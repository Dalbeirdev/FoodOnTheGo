/**
 * Development review stack (Module 16). Reviews live in sessionStorage; nothing here is a real backend review and no
 * review becomes public. Controls: sessionStorage fotg.mock.fail contains "review" → submit / update fails (draft kept);
 * fotg.mock.offline=1 → the page shows the offline state. Aggregates only cover reviews stored on this device.
 */
import type { Order } from '../../order/repositories'
import type { RestaurantReviewSummary, Review, ReviewConfig, ReviewConfigProvider, ReviewDraft, ReviewEligibility, ReviewEligibilityService, ReviewRepository } from '../repositories'
import { normalizeDraft, validateReview } from '../reviewForm'

const KEY = 'fotg.reviews.v1'
let latency = 300
export const setMockReviewLatency = (ms: number) => { latency = ms }
const wait = () => new Promise<void>((r) => setTimeout(r, latency))
const failing = () => { try { return (sessionStorage.getItem('fotg.mock.fail') ?? '').includes('review') } catch { return false } }
const load = (): Review[] => { try { const raw = sessionStorage.getItem(KEY); return raw ? (JSON.parse(raw) as Review[]) : [] } catch { return [] } }
const save = (v: Review[]) => { try { sessionStorage.setItem(KEY, JSON.stringify(v.slice(-100))) } catch { /* ignore */ } }
const id = () => { try { return 'rv_' + crypto.randomUUID().replace(/-/g, '').slice(0, 20) } catch { return 'rv_' + Date.now().toString(36) } }

/** Default configuration — categories / tags are data, not code; markets or restaurants may override later. */
export const DEFAULT_REVIEW_CONFIG: ReviewConfig = {
  scaleMin: 1, scaleMax: 5, textMaxLength: 500,
  categories: [{ key: 'food_quality', required: false }, { key: 'order_accuracy', required: false }, { key: 'preparation_time', required: false }, { key: 'pickup_experience', required: false }],
  tags: [
    { key: 'fast_pickup', sentiment: 'positive' }, { key: 'great_food', sentiment: 'positive' }, { key: 'accurate_order', sentiment: 'positive' }, { key: 'friendly_service', sentiment: 'positive' },
    { key: 'as_expected', sentiment: 'neutral' }, { key: 'packaging', sentiment: 'neutral' },
    { key: 'long_wait', sentiment: 'negative' }, { key: 'item_missing', sentiment: 'negative' }, { key: 'wrong_item', sentiment: 'negative' }, { key: 'cold_food', sentiment: 'negative' },
  ],
  itemFeedbackEnabled: true, titleEnabled: false, editEnabled: true, editWindowHours: 72, reviewWindowDays: 30,
}
/** Per-market overrides prove the configuration is data-driven (example values only — not business rules). */
const MARKET_OVERRIDES: Record<string, Partial<ReviewConfig>> = {
  JP: { categories: [{ key: 'food_quality', required: false }, { key: 'pickup_experience', required: false }], textMaxLength: 300 },
  FR: { editWindowHours: 24 },
}
export class MockReviewConfigProvider implements ReviewConfigProvider {
  async configFor(order: Order): Promise<ReviewConfig> { return { ...DEFAULT_REVIEW_CONFIG, ...(MARKET_OVERRIDES[order.restaurant.countryCode] ?? {}) } }
}

const COMPLETED = new Set(['PICKED_UP', 'COMPLETED'])
export class MockReviewEligibilityService implements ReviewEligibilityService {
  private readonly reviews: ReviewRepository
  constructor(reviews: ReviewRepository) { this.reviews = reviews }
  async check(order: Order, customerId: string, config: ReviewConfig): Promise<ReviewEligibility> {
    const none = (reason: ReviewEligibility['reason'], existing: Review | null = null, canEdit = false): ReviewEligibility => ({ eligible: false, reason, existingReview: existing, canEdit, windowEndsAt: null })
    if (order.customerId !== customerId) return none('not_owner')
    if (!COMPLETED.has(order.orderStatus)) return none('not_completed')
    const completedAt = order.events.filter((e) => e.type === 'COMPLETED' || e.type === 'PICKED_UP').map((e) => e.at).pop() ?? order.updatedAt
    const windowEndsAt = new Date(new Date(completedAt).getTime() + config.reviewWindowDays * 86400000).toISOString()
    const existing = await this.reviews.getReviewForOrder(order.publicId, customerId)
    if (existing) {
      const moderated = existing.status !== 'SUBMITTED' && existing.status !== 'PENDING_MODERATION'
      const inWindow = config.editWindowHours == null || Date.now() < new Date(existing.createdAt).getTime() + config.editWindowHours * 3600000
      return none('already_reviewed', existing, config.editEnabled && !moderated && inWindow)
    }
    if (Date.now() > new Date(windowEndsAt).getTime()) return none('window_expired')
    return { eligible: true, reason: 'ok', existingReview: null, canEdit: false, windowEndsAt }
  }
}

export class MockReviewRepository implements ReviewRepository {
  async getReviewForOrder(orderPublicId: string, customerId: string): Promise<Review | null> {
    await wait()
    return load().find((r) => r.orderPublicId === orderPublicId && r.customerId === customerId) ?? null
  }
  async submitReview(order: Order, draft: ReviewDraft): Promise<Review> {
    await wait()
    if (failing()) throw new Error('review_submit_failed')
    const config = await new MockReviewConfigProvider().configFor(order)
    const d = normalizeDraft(draft, config)
    if (Object.keys(validateReview(d, config)).length) throw new Error('review_invalid')
    const list = load()
    // One review per order: a duplicate submit (double click, retry, replay of the same clientSubmissionId) returns the existing review.
    const existing = list.find((r) => r.orderPublicId === order.publicId && r.customerId === order.customerId)
    if (existing) return existing
    const now = new Date().toISOString()
    const review: Review = {
      reviewId: id(), orderPublicId: order.publicId, orderNumber: order.orderNumber, restaurantId: order.restaurant.id, customerId: order.customerId,
      overallRating: d.overallRating as number, categoryRatings: d.categoryRatings, tags: d.tags, itemFeedback: d.itemFeedback, text: d.text,
      status: 'SUBMITTED', version: 1, moderation: { reason: null, moderatedAt: null }, createdAt: now, updatedAt: now,
    }
    list.push(review); save(list); return review
  }
  async updateReview(reviewId: string, customerId: string, draft: ReviewDraft): Promise<Review> {
    await wait()
    if (failing()) throw new Error('review_update_failed')
    const list = load(); const i = list.findIndex((r) => r.reviewId === reviewId && r.customerId === customerId)
    if (i < 0) throw new Error('review_not_found')
    const cur = list[i]
    // Edits update the existing review (new version); history / audit events are a backend concern (CF).
    list[i] = { ...cur, overallRating: draft.overallRating ?? cur.overallRating, categoryRatings: draft.categoryRatings, tags: draft.tags, itemFeedback: draft.itemFeedback, text: draft.text.trim(), version: cur.version + 1, updatedAt: new Date().toISOString() }
    save(list); return list[i]
  }
  async deleteReview(reviewId: string, customerId: string): Promise<void> {
    await wait()
    // Remove from public view only — the record stays (status HIDDEN) for audit; a hard delete is a backend / legal decision.
    const list = load(); const i = list.findIndex((r) => r.reviewId === reviewId && r.customerId === customerId)
    if (i >= 0) { list[i] = { ...list[i], status: 'HIDDEN', updatedAt: new Date().toISOString() }; save(list) }
  }
  async getRestaurantReviewSummary(restaurantId: string): Promise<RestaurantReviewSummary> {
    await wait()
    const mine = load().filter((r) => r.restaurantId === restaurantId && r.status !== 'HIDDEN' && r.status !== 'REJECTED')
    const distribution: Record<number, number> = {}
    for (const r of mine) distribution[r.overallRating] = (distribution[r.overallRating] ?? 0) + 1
    return { restaurantId, averageRating: mine.length ? mine.reduce((a, r) => a + r.overallRating, 0) / mine.length : null, reviewCount: mine.length, distribution, source: 'mock_local' }
  }
}

export const reviewRepositories = (() => { const reviews = new MockReviewRepository(); return { reviews, eligibility: new MockReviewEligibilityService(reviews), config: new MockReviewConfigProvider() } })()

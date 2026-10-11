/**
 * Reviews on the backend (Module 34). A review belongs to ONE completed order of the signed-in customer; the backend
 * decides whether it can be written, takes the restaurant from the order, screens the text and owns the rating. This
 * file only speaks the API and maps it to the review contracts the pages already use — it validates nothing that
 * matters and is never the authority.
 *
 *   GET    /customer/orders/{order}/review     eligibility + my review + the policy the form needs
 *   POST   /customer/orders/{order}/review     write it (one per order; a repeat answers the same review)
 *   PATCH  /customer/reviews/{review}          change it (version required)
 *   DELETE /customer/reviews/{review}          withdraw it
 *   GET    /customer/reviews                   my reviews
 *   GET    /restaurants/{slug}/reviews         the published reviews of a restaurant + its rating summary
 *   POST   /reviews/{review}/report            report somebody else's published review
 */
import { api, ApiError } from '../../api/client'
import type { Order } from '../../order/repositories'
import type {
  EligibilityReason, PublicReview, PublicReviewPage, PublicReviewQuery, RatingSummary, ReportReason, RestaurantReviewSummary, Review, ReviewConfig, ReviewConfigProvider, ReviewDraft, ReviewEligibility,
  ReviewEligibilityService, ReviewRepository, ReviewStatus,
} from '../repositories'

type ResponseDto = { text: string; restaurant_name: string | null; responded_at: string | null; edited: boolean } | null
export type ReviewDto = {
  id: string; order: { id: string | null; order_number: string | null }; restaurant: { id: string | null; slug: string | null; name: string | null; branch_label: string | null }
  overall_rating: number; category_ratings: Record<string, number>; tags: string[]; item_feedback: Array<{ item_id: string; name: string; sentiment: 'liked' | 'disliked' }>
  title: string | null; comment: string | null; status: ReviewStatus; verified_order: boolean; version: number
  can_edit: boolean; editable_until: string | null; edits_left: number; can_remove: boolean; response: ResponseDto
  submitted_at: string | null; published_at: string | null; edited_at: string | null
}
type ConfigDto = {
  scale: { min: number; max: number }; title_max_length: number; comment_max_length: number
  categories: Array<{ key: string; required: boolean; group: string }>; tags: Array<{ key: string; sentiment: 'positive' | 'neutral' | 'negative' }>
  max_tags: number; item_feedback: boolean; submission_window_days: number; edit_window_hours: number; max_edits: number
}
type EligibilityDto = { eligible: boolean; reason: string; window_ends_at: string | null; can_edit: boolean }
export type OrderReviewStateDto = { eligibility: EligibilityDto; review: ReviewDto | null; config: ConfigDto; order: { id: string; order_number: string; items: Array<{ id: string; name: string; quantity: number }> } }
type SummaryDto = { average: number | null; count: number; distribution: Record<string, number>; categories: Record<string, { average: number | null; count: number }> }
type PublicReviewDto = {
  id: string; author: { display_name: string }; overall_rating: number; category_ratings: Record<string, number>; tags: string[]; title: string | null; comment: string | null
  verified_order: boolean; edited: boolean; response: ResponseDto; submitted_at: string | null
}
type PageDto<T> = { data: T[]; meta: { current_page: number; last_page: number; total: number; per_page: number } }

const REASON: Record<string, EligibilityReason> = {
  ELIGIBLE: 'ok', ORDER_NOT_COMPLETED: 'not_completed', REVIEW_ALREADY_EXISTS: 'already_reviewed', REVIEW_WINDOW_EXPIRED: 'window_expired', ORDER_NOT_ELIGIBLE: 'blocked',
}

export function toReview(d: ReviewDto, customerId: string): Review {
  return {
    reviewId: d.id, orderPublicId: d.order.id ?? '', orderNumber: d.order.order_number ?? '', restaurantId: d.restaurant.id ?? '', customerId,
    overallRating: d.overall_rating, categoryRatings: { ...d.category_ratings }, tags: [...d.tags],
    itemFeedback: d.item_feedback.map((f) => ({ lineId: f.item_id, itemName: f.name, sentiment: f.sentiment })),
    text: d.comment ?? '', title: d.title, status: d.status, version: d.version,
    moderation: { reason: null, moderatedAt: null },
    restaurantResponse: d.response ? { text: d.response.text, respondedAt: d.response.responded_at ?? '', responderName: d.response.restaurant_name ?? d.restaurant.name ?? '' } : null,
    restaurantName: d.restaurant.name ?? '', restaurantSlug: d.restaurant.slug ?? '',
    verifiedOrder: d.verified_order, canEdit: d.can_edit, canRemove: d.can_remove, editableUntil: d.editable_until, editsLeft: d.edits_left, publishedAt: d.published_at,
    createdAt: d.submitted_at ?? new Date().toISOString(), updatedAt: d.edited_at ?? d.submitted_at ?? new Date().toISOString(),
  }
}

export function toConfig(d: ConfigDto): ReviewConfig {
  return {
    scaleMin: d.scale.min, scaleMax: d.scale.max, textMaxLength: d.comment_max_length,
    categories: d.categories.map((c) => ({ key: c.key, required: c.required })), tags: d.tags.map((t) => ({ key: t.key, sentiment: t.sentiment })),
    itemFeedbackEnabled: d.item_feedback, titleEnabled: false, editEnabled: d.max_edits > 0 && d.edit_window_hours > 0, editWindowHours: d.edit_window_hours, reviewWindowDays: d.submission_window_days,
  }
}

export function toSummary(d: SummaryDto): RatingSummary {
  const distribution: Record<number, number> = {}
  for (const [star, count] of Object.entries(d.distribution ?? {})) distribution[Number(star)] = count
  return { averageRating: d.average, reviewCount: d.count, distribution, categories: d.categories ?? {} }
}

export const toPublicReview = (d: PublicReviewDto): PublicReview => ({
  reviewId: d.id, authorName: d.author.display_name, overallRating: d.overall_rating, categoryRatings: { ...d.category_ratings }, tags: [...d.tags], title: d.title, text: d.comment ?? '',
  edited: d.edited, createdAt: d.submitted_at ?? '',
  response: d.response ? { text: d.response.text, respondedAt: d.response.responded_at ?? '', restaurantName: d.response.restaurant_name ?? '', edited: d.response.edited } : null,
})

/** What the backend takes: ratings, tags, item feedback and text. Never an owner, a restaurant, a status or "verified". */
export function toReviewBody(draft: ReviewDraft): Record<string, unknown> {
  return {
    overall_rating: draft.overallRating,
    category_ratings: draft.categoryRatings,
    tags: draft.tags,
    item_feedback: draft.itemFeedback.filter((f) => f.sentiment !== null).map((f) => ({ item_id: f.lineId, sentiment: f.sentiment })),
    comment: draft.text.trim() === '' ? null : draft.text.trim(),
  }
}

/** A failed review call in the customer's words: the backend's own message for a refusal, a plain one otherwise. */
export class ReviewError extends Error {
  readonly code: string
  readonly status: number
  constructor(e: unknown) {
    const apiError = e instanceof ApiError ? e : null
    super(apiError && (apiError.kind === 'conflict' || apiError.kind === 'validation' || apiError.kind === 'rate_limited' || apiError.kind === 'not_found') ? firstMessage(apiError) : 'review_request_failed')
    this.name = 'ReviewError'
    this.code = apiError?.code ?? 'network'
    this.status = apiError?.status ?? 0
  }
  /** True when the message is the backend's and may be shown as it is. */
  get presentable(): boolean { return this.message !== 'review_request_failed' }
}
function firstMessage(e: ApiError): string {
  const fields = Object.values(e.errors ?? {})
  return fields.length > 0 && fields[0].length > 0 ? fields[0][0] : e.message
}

/**
 * One GET answers "may I review", "what is my review" and "what does the form look like". The three contracts the pages
 * use ask for them separately, so the answer is shared for a moment (and in flight) instead of being fetched three times.
 */
class OrderReviewState {
  private readonly cache = new Map<string, { at: number; value: Promise<OrderReviewStateDto> }>()
  private readonly versions = new Map<string, number>()
  get(orderRef: string): Promise<OrderReviewStateDto> {
    const hit = this.cache.get(orderRef)
    if (hit && Date.now() - hit.at < 4000) return hit.value
    const value = api<OrderReviewStateDto>(`/customer/orders/${encodeURIComponent(orderRef)}/review`).then((d) => { if (d.review) this.versions.set(d.review.id, d.review.version); return d })
    this.cache.set(orderRef, { at: Date.now(), value })
    value.catch(() => this.cache.delete(orderRef))
    return value
  }
  remember(review: ReviewDto): void { this.versions.set(review.id, review.version); this.cache.clear() }
  forget(): void { this.cache.clear() }
  version(reviewId: string): number | undefined { return this.versions.get(reviewId) }
}
const state = new OrderReviewState()
export const resetReviewApiCaches = (): void => { state.forget() }

const orderRef = (order: Order): string => order.orderNumber || order.publicId

export class ApiReviewConfigProvider implements ReviewConfigProvider {
  async configFor(order: Order): Promise<ReviewConfig> { return toConfig((await state.get(orderRef(order))).config) }
}

export class ApiReviewEligibilityService implements ReviewEligibilityService {
  async check(order: Order, customerId: string): Promise<ReviewEligibility> {
    const d = await state.get(orderRef(order))
    const existing = d.review && d.review.status !== 'REMOVED' ? toReview(d.review, customerId) : null
    const reason: EligibilityReason = d.review?.status === 'REMOVED' ? 'removed' : REASON[d.eligibility.reason] ?? 'blocked'
    return { eligible: d.eligibility.eligible, reason, existingReview: existing, canEdit: d.eligibility.can_edit, windowEndsAt: d.eligibility.window_ends_at }
  }
}

export class ApiReviewRepository implements ReviewRepository {
  async getReviewForOrder(orderPublicId: string, customerId: string): Promise<Review | null> {
    try {
      const d = await state.get(orderPublicId)
      return d.review && d.review.status !== 'REMOVED' ? toReview(d.review, customerId) : null
    } catch (e) { if (e instanceof ApiError && e.kind === 'not_found') return null; throw new ReviewError(e) }
  }
  async submitReview(order: Order, draft: ReviewDraft): Promise<Review> {
    try {
      const r = await api<{ review: ReviewDto; created: boolean }>(`/customer/orders/${encodeURIComponent(orderRef(order))}/review`, {
        method: 'POST', body: { ...toReviewBody(draft), client_submission_id: clientToken(draft.clientSubmissionId) },
      })
      state.remember(r.review)
      return toReview(r.review, order.customerId)
    } catch (e) { state.forget(); throw new ReviewError(e) }
  }
  async updateReview(reviewId: string, customerId: string, draft: ReviewDraft): Promise<Review> {
    try {
      const version = state.version(reviewId) ?? (await api<{ review: ReviewDto }>(`/customer/reviews/${reviewId}`)).review.version
      const r = await api<{ review: ReviewDto }>(`/customer/reviews/${reviewId}`, { method: 'PATCH', body: { ...toReviewBody(draft), version } })
      state.remember(r.review)
      return toReview(r.review, customerId)
    } catch (e) { state.forget(); throw new ReviewError(e) }
  }
  async deleteReview(reviewId: string): Promise<void> {
    try { await api(`/customer/reviews/${reviewId}`, { method: 'DELETE' }); state.forget() } catch (e) { state.forget(); throw new ReviewError(e) }
  }
  async listMine(customerId: string): Promise<Review[]> {
    try { return (await api<PageDto<ReviewDto>>('/customer/reviews', { query: { per_page: 50 } })).data.map((d) => toReview(d, customerId)) } catch (e) { throw new ReviewError(e) }
  }
  async listPublic(restaurantSlug: string, query: PublicReviewQuery = {}): Promise<PublicReviewPage> {
    try {
      const d = await api<PageDto<PublicReviewDto> & { summary: SummaryDto }>(`/restaurants/${encodeURIComponent(restaurantSlug)}/reviews`, {
        auth: false, query: { page: query.page ?? 1, per_page: query.pageSize ?? 5, sort: query.sort ?? 'newest', rating: query.rating ?? null },
      })
      return { items: d.data.map(toPublicReview), total: d.meta.total, page: d.meta.current_page, pageSize: d.meta.per_page, summary: toSummary(d.summary) }
    } catch (e) { throw new ReviewError(e) }
  }
  async reportReview(reviewId: string, reason: ReportReason, explanation?: string): Promise<void> {
    try { await api(`/reviews/${reviewId}/report`, { method: 'POST', body: { reason, explanation: explanation && explanation.trim() !== '' ? explanation.trim() : null } }) } catch (e) { throw new ReviewError(e) }
  }
  async getRestaurantReviewSummary(restaurantSlug: string): Promise<RestaurantReviewSummary> {
    try {
      const d = await api<{ rating: SummaryDto }>(`/restaurants/${encodeURIComponent(restaurantSlug)}/reviews/summary`, { auth: false })
      const s = toSummary(d.rating)
      return { restaurantId: restaurantSlug, averageRating: s.averageRating, reviewCount: s.reviewCount, distribution: s.distribution, source: 'server' }
    } catch (e) { throw new ReviewError(e) }
  }
}

/** The submission token as the backend accepts it (letters, digits, - and _; 8–64). */
function clientToken(id: string): string {
  const clean = id.replace(/[^A-Za-z0-9_-]/g, '')
  return (clean.length >= 8 ? clean : `sub-${clean}-${Date.now().toString(36)}`).slice(0, 64)
}

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { tokens } from '../../api/client'
import type { Order } from '../../order/repositories'
import type { ReviewDraft } from '../repositories'
import { selectReviewRepositories } from '../reviewRepositories'
import { ApiReviewConfigProvider, ApiReviewEligibilityService, ApiReviewRepository, resetReviewApiCaches, ReviewError, toReviewBody, type OrderReviewStateDto, type ReviewDto } from './apiReview'

/** Module 34 — the review API module: what it sends, how it maps answers, and that the backend stays the authority. */
const BASE = 'http://127.0.0.1:8001/api/v1'
type Call = { method: string; path: string; body: unknown; auth: string | null; idempotency: string | null }
type Reply = { status?: number; body: unknown }
let calls: Call[] = []
let reply: (method: string, path: string) => Reply = () => ({ body: {} })

beforeEach(() => {
  calls = []; sessionStorage.clear(); localStorage.clear(); resetReviewApiCaches(); tokens.set('customer', 'test-token')
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const path = decodeURIComponent(url.replace(BASE, ''))
    const headers = (init.headers ?? {}) as Record<string, string>
    calls.push({ method: init.method ?? 'GET', path, body: init.body ? JSON.parse(init.body as string) : undefined, auth: headers.Authorization ?? null, idempotency: headers['Idempotency-Key'] ?? null })
    const r = reply(init.method ?? 'GET', path)
    return new Response(JSON.stringify(r.body), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } })
  }))
})
afterEach(() => { vi.unstubAllGlobals(); tokens.set('customer', null) })
const error = (status: number, code: string, message: string, fields?: Record<string, string[]>): Reply => ({ status, body: { error: { code, message, details: fields ? { fields } : {}, request_id: 'r1' } } })

const NUMBER = 'FOTG-20261011-ABC234'
const order = { publicId: '11111111-1111-4111-8111-111111111111', orderNumber: NUMBER, customerId: 'cust-1', items: [{ lineId: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', itemName: 'Classic Burger' }] } as unknown as Order
const reviewDto = (over: Partial<ReviewDto> = {}): ReviewDto => ({
  id: '22222222-2222-4222-8222-222222222222', order: { id: order.publicId, order_number: NUMBER }, restaurant: { id: 'r1', slug: 'burger-hub', name: 'Burger Hub', branch_label: null },
  overall_rating: 4, category_ratings: { food_quality: 5 }, tags: ['great_food'], item_feedback: [{ item_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', name: 'Classic Burger', sentiment: 'liked' }],
  title: null, comment: 'Fresh and quick.', status: 'PUBLISHED', verified_order: true, version: 1, can_edit: true, editable_until: '2026-10-14T10:00:00+00:00', edits_left: 3, can_remove: true, response: null,
  submitted_at: '2026-10-11T10:00:00+00:00', published_at: '2026-10-11T10:00:00+00:00', edited_at: null, ...over,
})
const config = {
  scale: { min: 1, max: 5 }, title_max_length: 120, comment_max_length: 500, categories: [{ key: 'food_quality', required: false, group: 'food' }], tags: [{ key: 'great_food', sentiment: 'positive' as const }],
  max_tags: 6, item_feedback: true, submission_window_days: 30, edit_window_hours: 72, max_edits: 3,
}
const stateDto = (over: Partial<OrderReviewStateDto> = {}): OrderReviewStateDto => ({
  eligibility: { eligible: true, reason: 'ELIGIBLE', window_ends_at: '2026-11-10T10:00:00+00:00', can_edit: false }, review: null, config,
  order: { id: order.publicId, order_number: NUMBER, items: [{ id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', name: 'Classic Burger', quantity: 2 }] }, ...over,
})
const draft: ReviewDraft = {
  overallRating: 4, categoryRatings: { food_quality: 5 }, tags: ['great_food'], text: '  Fresh and quick.  ', clientSubmissionId: 'c3b1a2d4-5e6f-4a7b-8c9d-0e1f2a3b4c5d',
  itemFeedback: [{ lineId: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', itemName: 'Classic Burger', sentiment: 'liked' }, { lineId: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', itemName: 'Fries', sentiment: null }],
}

describe('review API module (Module 34)', () => {
  it('the body carries ratings, tags, item feedback and text — never an owner, a restaurant, a status or "verified"', () => {
    const body = toReviewBody(draft)
    expect(body).toEqual({ overall_rating: 4, category_ratings: { food_quality: 5 }, tags: ['great_food'], item_feedback: [{ item_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', sentiment: 'liked' }], comment: 'Fresh and quick.' })
    for (const forbidden of ['customer_id', 'customer', 'order_id', 'restaurant_id', 'location_id', 'status', 'verified', 'verified_order', 'published_at']) expect(body).not.toHaveProperty(forbidden)
    expect(toReviewBody({ ...draft, text: '   ' }).comment).toBeNull()
  })

  it('one GET answers eligibility, the policy and my review; the three contracts share it', async () => {
    reply = () => ({ body: stateDto() })
    const cfg = await new ApiReviewConfigProvider().configFor(order)
    const el = await new ApiReviewEligibilityService().check(order, 'cust-1')
    const mine = await new ApiReviewRepository().getReviewForOrder(NUMBER, 'cust-1')
    expect(calls).toHaveLength(1)
    expect(calls[0]).toMatchObject({ method: 'GET', path: `/customer/orders/${NUMBER}/review`, auth: 'Bearer test-token' })
    expect(cfg).toMatchObject({ scaleMin: 1, scaleMax: 5, textMaxLength: 500, editWindowHours: 72, reviewWindowDays: 30, itemFeedbackEnabled: true, editEnabled: true, titleEnabled: false })
    expect(el).toEqual({ eligible: true, reason: 'ok', existingReview: null, canEdit: false, windowEndsAt: '2026-11-10T10:00:00+00:00' })
    expect(mine).toBeNull()
  })

  it('maps every reason the backend gives, and a withdrawn review is "removed", not something to edit', async () => {
    const check = async (dto: OrderReviewStateDto) => { resetReviewApiCaches(); reply = () => ({ body: dto }); return new ApiReviewEligibilityService().check(order, 'cust-1') }
    expect((await check(stateDto({ eligibility: { eligible: false, reason: 'ORDER_NOT_COMPLETED', window_ends_at: null, can_edit: false } }))).reason).toBe('not_completed')
    expect((await check(stateDto({ eligibility: { eligible: false, reason: 'REVIEW_WINDOW_EXPIRED', window_ends_at: null, can_edit: false } }))).reason).toBe('window_expired')
    expect((await check(stateDto({ eligibility: { eligible: false, reason: 'ORDER_NOT_ELIGIBLE', window_ends_at: null, can_edit: false } }))).reason).toBe('blocked')
    const reviewed = await check(stateDto({ eligibility: { eligible: false, reason: 'REVIEW_ALREADY_EXISTS', window_ends_at: null, can_edit: true }, review: reviewDto() }))
    expect(reviewed).toMatchObject({ eligible: false, reason: 'already_reviewed', canEdit: true })
    expect(reviewed.existingReview).toMatchObject({ reviewId: reviewDto().id, overallRating: 4, text: 'Fresh and quick.', status: 'PUBLISHED', verifiedOrder: true, canEdit: true, restaurantName: 'Burger Hub', orderNumber: NUMBER })
    const removed = await check(stateDto({ eligibility: { eligible: false, reason: 'REVIEW_ALREADY_EXISTS', window_ends_at: null, can_edit: false }, review: reviewDto({ status: 'REMOVED', can_edit: false }) }))
    expect(removed).toMatchObject({ eligible: false, reason: 'removed', existingReview: null })
  })

  it('submit posts to the order, sends the client token, and a refusal carries the backend\'s own words', async () => {
    reply = (method) => (method === 'POST' ? { status: 201, body: { review: reviewDto(), created: true } } : { body: stateDto() })
    const repo = new ApiReviewRepository()
    const review = await repo.submitReview(order, draft)
    const post = calls.find((c) => c.method === 'POST')!
    expect(post.path).toBe(`/customer/orders/${NUMBER}/review`)
    expect(post.body).toMatchObject({ overall_rating: 4, comment: 'Fresh and quick.', client_submission_id: 'c3b1a2d4-5e6f-4a7b-8c9d-0e1f2a3b4c5d' })
    expect(review).toMatchObject({ reviewId: reviewDto().id, version: 1, customerId: 'cust-1', itemFeedback: [{ lineId: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', itemName: 'Classic Burger', sentiment: 'liked' }] })

    reply = () => error(409, 'review_already_exists', 'This order has already been reviewed.')
    await expect(repo.submitReview(order, draft)).rejects.toMatchObject({ name: 'ReviewError', code: 'review_already_exists', message: 'This order has already been reviewed.', presentable: true })
    reply = () => error(422, 'validation_failed', 'The given data was invalid.', { comment: ['Plain text only: markup is not accepted.'] })
    await expect(repo.submitReview(order, draft)).rejects.toMatchObject({ message: 'Plain text only: markup is not accepted.', presentable: true })
    reply = () => ({ status: 500, body: { error: { code: 'server_error', message: 'boom' } } })
    const failure = await repo.submitReview(order, draft).catch((e: unknown) => e)
    expect(failure).toBeInstanceOf(ReviewError)
    expect((failure as ReviewError).presentable).toBe(false)
  })

  it('an edit sends the version it loaded, and a conflict is reported', async () => {
    reply = (method) => (method === 'PATCH' ? { body: { review: reviewDto({ version: 2, overall_rating: 5, edits_left: 2 }) } } : { body: stateDto({ review: reviewDto() }) })
    const repo = new ApiReviewRepository()
    await repo.getReviewForOrder(NUMBER, 'cust-1')
    const updated = await repo.updateReview(reviewDto().id, 'cust-1', { ...draft, overallRating: 5 })
    const patch = calls.find((c) => c.method === 'PATCH')!
    expect(patch.path).toBe(`/customer/reviews/${reviewDto().id}`)
    expect(patch.body).toMatchObject({ version: 1, overall_rating: 5 })
    expect(updated).toMatchObject({ version: 2, overallRating: 5, editsLeft: 2 })

    reply = () => error(409, 'version_conflict', 'This review was changed in the meantime. Load it again before changing it.')
    await expect(repo.updateReview(reviewDto().id, 'cust-1', draft)).rejects.toMatchObject({ code: 'version_conflict', presentable: true })
    expect(calls.filter((c) => c.method === 'PATCH').at(-1)!.body).toMatchObject({ version: 2 })
  })

  it('withdraw, my reviews, the public list and a report use their own endpoints', async () => {
    const repo = new ApiReviewRepository()
    reply = () => ({ body: { review: reviewDto({ status: 'REMOVED' }) } })
    await repo.deleteReview(reviewDto().id)
    expect(calls.at(-1)).toMatchObject({ method: 'DELETE', path: `/customer/reviews/${reviewDto().id}` })

    reply = () => ({ body: { data: [reviewDto({ response: { text: 'Thank you!', restaurant_name: 'Burger Hub', responded_at: '2026-10-12T08:00:00+00:00', edited: false } })], meta: { current_page: 1, last_page: 1, total: 1, per_page: 50 } } })
    const mine = await repo.listMine('cust-1')
    expect(calls.at(-1)!.path).toBe('/customer/reviews?per_page=50')
    expect(mine[0].restaurantResponse).toEqual({ text: 'Thank you!', respondedAt: '2026-10-12T08:00:00+00:00', responderName: 'Burger Hub' })

    reply = () => ({ body: {
      data: [{ id: 'p1', author: { display_name: 'Rahul S.' }, overall_rating: 5, category_ratings: {}, tags: [], title: null, comment: 'Great <3', verified_order: true, edited: false, response: null, submitted_at: '2026-10-11T10:00:00+00:00' }],
      meta: { current_page: 2, last_page: 3, total: 11, per_page: 5 }, summary: { average: 4.4, count: 11, distribution: { 5: 7, 4: 2, 3: 1, 2: 0, 1: 1 }, categories: { food_quality: { average: 4.6, count: 9 } } },
    } })
    const page = await repo.listPublic('burger-hub', { page: 2, sort: 'highest', rating: 5 })
    expect(calls.at(-1)).toMatchObject({ method: 'GET', path: '/restaurants/burger-hub/reviews?page=2&per_page=5&sort=highest&rating=5', auth: null })
    expect(page).toMatchObject({ total: 11, page: 2, pageSize: 5, summary: { averageRating: 4.4, reviewCount: 11, distribution: { 5: 7, 1: 1 } } })
    expect(page.items[0]).toMatchObject({ authorName: 'Rahul S.', text: 'Great <3', overallRating: 5 })
    expect(Object.keys(page.items[0])).not.toContain('customerId')

    reply = () => ({ status: 201, body: { report: { id: 'x', reason: 'SPAM', received_at: null } } })
    await repo.reportReview('p1', 'SPAM', '  looks copied ')
    expect(calls.at(-1)).toMatchObject({ method: 'POST', path: '/reviews/p1/report', body: { reason: 'SPAM', explanation: 'looks copied' } })
    reply = () => error(409, 'review_already_reported', 'You have already reported this review.')
    await expect(repo.reportReview('p1', 'SPAM')).rejects.toMatchObject({ message: 'You have already reported this review.' })
  })

  it('the mode selects the backend stack or the development data', () => {
    expect(selectReviewRepositories('api').reviews).toBeInstanceOf(ApiReviewRepository)
    expect(selectReviewRepositories('mock').reviews).not.toBeInstanceOf(ApiReviewRepository)
  })
})

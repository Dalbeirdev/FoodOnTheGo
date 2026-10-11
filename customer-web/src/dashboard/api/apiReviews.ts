/**
 * Restaurant Dashboard reviews on the backend (Module 34). The staff member sees the PUBLISHED reviews of a location
 * they may open — the backend decides which, and whether they may answer or report. The dashboard can publish or edit
 * the restaurant's one response and report a review; it has no way to change, hide or remove what the customer wrote.
 *
 *   GET  /restaurant/reviews?location=            the published reviews + the rating summary of that location
 *   GET  /restaurant/reviews/summary?location=    the summary alone
 *   PUT  /restaurant/reviews/{review}/response    publish / edit the response (expected_version on an edit)
 *   POST /restaurant/reviews/{review}/report      ask a moderator to look
 */
import { ApiError, api } from '../../api/client'
import type { ReportReason, Review, ReviewStatus } from '../../review/repositories'
import type { RestaurantReviewRepository } from '../types'
import type { ApiRestaurantManagementRepository } from './apiDashboard'

const R = { context: 'restaurant' as const }

type SummaryDto = { average: number | null; count: number; distribution: Record<string, number>; categories: Record<string, { average: number | null; count: number }>; answered: number; unanswered: number }
export type RestaurantReviewDto = {
  id: string; location: { id: string | null; name: string | null }; order_number: string | null; author: { display_name: string }
  overall_rating: number; category_ratings: Record<string, number>; tags: string[]; item_feedback: Array<{ item_id: string; name: string; sentiment: 'liked' | 'disliked' }>
  title: string | null; comment: string | null; status: ReviewStatus; verified_order: boolean; submitted_at: string | null; edited_at: string | null
  response: { id: string; text: string; status: 'PUBLISHED' | 'HIDDEN'; responder_name: string; version: number; responded_at: string | null; edited_at: string | null } | null
  can_respond: boolean; can_report: boolean; reported: boolean
}
type PageDto = { data: RestaurantReviewDto[]; meta: { total: number }; summary: SummaryDto }

export function toDashboardReview(d: RestaurantReviewDto): Review {
  return {
    reviewId: d.id, orderPublicId: '', orderNumber: d.order_number ?? '', restaurantId: d.location.id ?? '', customerId: '', customerDisplayName: d.author.display_name,
    overallRating: d.overall_rating, categoryRatings: { ...d.category_ratings }, tags: [...d.tags], itemFeedback: d.item_feedback.map((f) => ({ lineId: f.item_id, itemName: f.name, sentiment: f.sentiment })),
    text: d.comment ?? '', title: d.title, status: d.status, version: 1, moderation: { reason: null, moderatedAt: null }, verifiedOrder: d.verified_order,
    restaurantResponse: d.response ? { text: d.response.text, respondedAt: d.response.responded_at ?? '', responderName: d.response.responder_name } : null,
    responseVersion: d.response?.version, responseHidden: d.response?.status === 'HIDDEN', canRespond: d.can_respond, canReport: d.can_report, reported: d.reported,
    createdAt: d.submitted_at ?? '', updatedAt: d.edited_at ?? d.submitted_at ?? '',
  }
}

export const toDashboardSummary = (s: SummaryDto) => ({
  averageRating: s.average, reviewCount: s.count, answered: s.answered,
  distribution: [5, 4, 3, 2, 1].map((rating) => ({ rating, count: s.distribution[String(rating)] ?? 0 })),
})

/** The backend's own words for a refusal (a hidden response, a changed response, markup), a plain failure otherwise. */
const fail = (e: unknown): Error => {
  if (e instanceof ApiError && (e.kind === 'conflict' || e.kind === 'validation' || e.kind === 'forbidden' || e.kind === 'rate_limited')) {
    const first = Object.values(e.errors ?? {})[0]?.[0]
    return new Error(first ?? e.message)
  }
  return new Error('reviews_request_failed')
}

export class ApiRestaurantReviewRepository implements RestaurantReviewRepository {
  private readonly management: ApiRestaurantManagementRepository
  /** The responses' versions as last loaded: an edit sends the one it saw, so two staff members cannot overwrite each other silently. */
  private readonly versions = new Map<string, number>()
  constructor(management: ApiRestaurantManagementRepository) { this.management = management }

  private location(id: string): string {
    const uuid = this.management.locationUuid(id)
    if (!uuid) throw new Error('location_unavailable')
    return uuid
  }
  private remember(d: RestaurantReviewDto): Review {
    if (d.response) this.versions.set(d.id, d.response.version); else this.versions.delete(d.id)
    return toDashboardReview(d)
  }

  async summary(locationId: string) {
    try { return toDashboardSummary((await api<{ summary: SummaryDto }>('/restaurant/reviews/summary', { ...R, query: { location: this.location(locationId) } })).summary) } catch (e) { throw fail(e) }
  }
  async list(locationId: string): Promise<Review[]> {
    try { return (await api<PageDto>('/restaurant/reviews', { ...R, query: { location: this.location(locationId), per_page: 50, sort: 'newest' } })).data.map((d) => this.remember(d)) } catch (e) { throw fail(e) }
  }
  async respond(reviewId: string, text: string): Promise<Review> {
    try {
      const version = this.versions.get(reviewId)
      const r = await api<{ review: RestaurantReviewDto }>(`/restaurant/reviews/${reviewId}/response`, { ...R, method: 'PUT', body: { response_text: text.trim(), ...(version ? { expected_version: version } : {}) } })
      return this.remember(r.review)
    } catch (e) { throw fail(e) }
  }
  async report(reviewId: string, reason: ReportReason, explanation?: string): Promise<void> {
    try { await api(`/restaurant/reviews/${reviewId}/report`, { ...R, method: 'POST', body: { reason, explanation: explanation && explanation.trim() !== '' ? explanation.trim() : null } }) } catch (e) { throw fail(e) }
  }
}

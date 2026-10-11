import { ApiError, api } from '../../api/client'
import type { Review, ReviewStatus } from '../../review/repositories'
import type { AdminReview, AdminReviewCase, AdminReviewRepository, ModerationAction, Page, ReviewModerationFilter } from '../types'

/**
 * Review moderation for administrators, from the backend (Module 34).
 *
 * The backend lists the reviews of the markets this administrator holds `admin.reviews.view` for — a grant for one
 * market never opens another, and the browser has no say in it. A decision (publish, hide, reject, restore, hide or
 * restore the restaurant's response) carries a reason and the version the page loaded; the backend refuses a decision
 * taken on a review that changed in the meantime (409), and records every decision in the audit trail.
 *
 * Nothing here edits a rating or a customer's text: moderation changes visibility only.
 */
type CaseDto = {
  id: string; source: AdminReviewCase['source']; reason: string | null; status: AdminReviewCase['status']; reports: number; reported_by_type: string | null
  assigned_to: { id: string; name: string } | null; resolution: string | null; resolution_note: string | null; version: number; created_at: string | null; resolved_at: string | null
  report_list?: Array<{ id: string; reporter_type: string; reason: string; explanation: string | null; at: string | null }>
}
export type AdminReviewDto = {
  id: string; status: ReviewStatus; version: number; flags: string[]
  overall_rating: number; category_ratings: Record<string, number>; tags: string[]; title: string | null; comment: string | null; item_feedback: Array<{ item_id: string; name: string; sentiment: string }>
  customer: { id: string | null; display_name: string | null; public_name: string | null }; order: { id: string | null; order_number: string | null }
  restaurant: { id: string | null; slug: string | null; name: string | null; branch_label: string | null }; market: { id: string | null; country_code: string | null }
  verified_order: boolean; reports: number; open_case: CaseDto | null
  response: { id: string; text: string; status: 'PUBLISHED' | 'HIDDEN'; responder_name: string; version: number; responded_at: string | null; edited_at: string | null } | null
  edit_count: number; submitted_at: string | null; published_at: string | null; edited_at: string | null; moderated_at: string | null; removed_at: string | null; removed_by: string | null
  cases?: CaseDto[]; history?: Array<{ at: string | null; action: string; actor_type: string | null; by: string; reason: string | null }>
}
type PageDto = { data: AdminReviewDto[]; meta: { total: number; current_page: number; per_page: number }; counts: Record<string, number> }

const TAB_QUERY: Record<ReviewModerationFilter['tab'], Record<string, string>> = {
  all: {}, pending: { status: 'PENDING_MODERATION' }, flagged: { reported: 'true' }, published: { status: 'PUBLISHED' }, hidden: { status: 'HIDDEN' }, rejected: { status: 'REJECTED' }, removed: { status: 'REMOVED' },
}
/** The audit trail's action names → the labels the moderation history already uses. */
const EVENT: Record<string, string> = {
  'review.created': 'created', 'review.edited': 'edited', 'review.removed': 'removed', 'review.reported': 'reported', 'review.response_added': 'response_added', 'review.response_edited': 'response_edited',
  'review.published': 'publish', 'review.hidden': 'hide', 'review.rejected': 'reject', 'review.restored': 'restore', 'review.response_hidden': 'hide_response', 'review.response_restored': 'restore_response',
  'review.case_assigned': 'case_assigned', 'review.case_escalated': 'case_escalated', 'review.case_resolved': 'case_resolved',
}
const PATH: Record<ModerationAction, string> = { publish: 'publish', hide: 'hide', reject: 'reject', restore: 'restore', hide_response: 'response/hide', restore_response: 'response/restore' }

const toCase = (c: CaseDto): AdminReviewCase => ({
  id: c.id, source: c.source, reason: c.reason, status: c.status, reports: c.reports, assignedTo: c.assigned_to?.name ?? null, resolution: c.resolution, resolutionNote: c.resolution_note, version: c.version, createdAt: c.created_at, resolvedAt: c.resolved_at,
  reportList: (c.report_list ?? []).map((r) => ({ reporterType: r.reporter_type, reason: r.reason, explanation: r.explanation, at: r.at })),
})

export function toAdminReview(d: AdminReviewDto): AdminReview {
  const cases = (d.cases ?? (d.open_case ? [d.open_case] : [])).map(toCase)
  const review: Review = {
    reviewId: d.id, orderPublicId: d.order.id ?? '', orderNumber: d.order.order_number ?? '', restaurantId: d.restaurant.slug ?? d.restaurant.id ?? '', customerId: d.customer.id ?? '', customerDisplayName: d.customer.display_name ?? d.customer.public_name,
    overallRating: d.overall_rating, categoryRatings: { ...d.category_ratings }, tags: [...d.tags], itemFeedback: d.item_feedback.filter((f) => f.sentiment === 'liked' || f.sentiment === 'disliked').map((f) => ({ lineId: f.item_id, itemName: f.name, sentiment: f.sentiment as 'liked' | 'disliked' })),
    text: d.comment ?? '', title: d.title, status: d.status, version: d.version, moderation: { reason: null, moderatedAt: d.moderated_at }, verifiedOrder: d.verified_order,
    restaurantResponse: d.response ? { text: d.response.text, respondedAt: d.response.responded_at ?? '', responderName: d.response.responder_name } : null, responseVersion: d.response?.version, responseHidden: d.response?.status === 'HIDDEN',
    restaurantName: d.restaurant.name ?? undefined, restaurantSlug: d.restaurant.slug ?? undefined, publishedAt: d.published_at, createdAt: d.submitted_at ?? '', updatedAt: d.edited_at ?? d.moderated_at ?? d.submitted_at ?? '',
  }
  return {
    review, restaurantName: [d.restaurant.name, d.restaurant.branch_label].filter(Boolean).join(' · ') || '—', reports: d.reports,
    reportReasons: [...new Set(cases.flatMap((c) => (c.reportList.length ? c.reportList.map((r) => r.reason) : c.reason ? [c.reason] : [])))],
    moderationHistory: (d.history ?? []).map((h) => ({ at: h.at ?? '', by: h.by, action: EVENT[h.action] ?? h.action, reason: h.reason })),
    backend: { detail: d.history !== undefined, marketCode: d.market.country_code, flags: [...d.flags], responseStatus: d.response?.status ?? null, publicName: d.customer.public_name, editCount: d.edit_count, removedBy: d.removed_by, cases },
  }
}

/** The backend's own sentence for a refusal the administrator can act on; a plain failure otherwise. */
const fail = (e: unknown): Error => {
  if (e instanceof ApiError && e.status >= 400 && e.status < 500 && e.status !== 401) {
    const first = Object.values(e.errors ?? {})[0]?.[0]
    return new Error(first ?? e.message)
  }
  return new Error('admin_save_failed')
}

export class ApiAdminReviewRepository implements AdminReviewRepository {
  /** The version of each review as last loaded: a decision is taken on what the administrator saw. */
  private readonly versions = new Map<string, number>()
  private keep(d: AdminReviewDto): AdminReview { this.versions.set(d.id, d.version); return toAdminReview(d) }

  async list(f: ReviewModerationFilter): Promise<Page<AdminReview> & { counts: Record<ReviewModerationFilter['tab'], number> }> {
    const page = Math.max(1, f.page ?? 1); const size = Math.max(1, Math.min(50, f.pageSize ?? 10))
    // The backend scopes the list by the administrator's market grants.
    const res = await api<PageDto>('/admin/reviews', { context: 'admin', query: { page, per_page: size, q: f.query?.trim() || null, ...TAB_QUERY[f.tab] } })
    const c = res.counts ?? {}
    return {
      items: res.data.map((d) => this.keep(d)), total: res.meta.total, page: res.meta.current_page, pageSize: res.meta.per_page,
      counts: { all: c.all ?? 0, pending: c.pending_moderation ?? 0, flagged: c.reported ?? 0, published: c.published ?? 0, hidden: c.hidden ?? 0, rejected: c.rejected ?? 0, removed: c.removed ?? 0 },
    }
  }

  async get(reviewId: string): Promise<AdminReview> {
    return this.keep((await api<{ review: AdminReviewDto }>(`/admin/reviews/${reviewId}`, { context: 'admin' })).review)
  }

  async moderate(reviewId: string, action: ModerationAction, _actor: string, reason: string): Promise<AdminReview> {
    const text = reason.trim(); const version = this.versions.get(reviewId)
    // The actor is the signed-in administrator — the backend takes it from the session, never from the request.
    const body = { ...(text !== '' ? { reason: text } : {}), ...(version && !action.endsWith('_response') ? { expected_version: version } : {}) }
    try { return this.keep((await api<{ review: AdminReviewDto }>(`/admin/reviews/${reviewId}/${PATH[action]}`, { context: 'admin', method: 'POST', body })).review) } catch (e) { throw fail(e) }
  }

  async caseAction(caseId: string, action: 'assign' | 'escalate' | 'resolve', reason: string, version: number): Promise<void> {
    const body = action === 'assign' ? {} : { reason: reason.trim(), expected_version: version, ...(action === 'resolve' ? { resolution: 'NO_VIOLATION' } : {}) }
    try { await api(`/admin/review-cases/${caseId}/${action}`, { context: 'admin', method: 'POST', body }) } catch (e) { throw fail(e) }
  }
}

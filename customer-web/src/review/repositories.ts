import type { Order } from '../order/repositories'

/**
 * Reviews, ratings & customer feedback (Module 16) — domain contracts.
 * A review is always tied to an eligible order (never an arbitrary restaurant); the restaurant is derived from the order.
 * Review content is untrusted input: length-validated here, sanitized / escaped / moderated by the backend later.
 * Submission state (what the customer did) is separate from moderation state (what the platform decided).
 */
/** Backend statuses (Module 34): PENDING_MODERATION, PUBLISHED, HIDDEN, REJECTED, REMOVED. SUBMITTED and FLAGGED exist only in the development data. */
export type ReviewStatus = 'SUBMITTED' | 'PENDING_MODERATION' | 'PUBLISHED' | 'HIDDEN' | 'REJECTED' | 'FLAGGED' | 'REMOVED'
export type SubmissionState = 'NOT_SUBMITTED' | 'DRAFT' | 'SUBMITTING' | 'SUBMITTED' | 'PENDING_MODERATION' | 'PUBLISHED' | 'ERROR' | 'OFFLINE'
export type TagSentiment = 'positive' | 'neutral' | 'negative'
export type ItemSentiment = 'liked' | 'disliked'

/** Data-driven category definition — label comes from the string table (rv.cat.<key>). */
export type ReviewCategoryDef = { key: string; required: boolean }
/** Data-driven quick-feedback tag — label from rv.tag.<key>; sentiment decides when it is offered. */
export type ReviewTagDef = { key: string; sentiment: TagSentiment }
export type ReviewConfig = {
  /** Rating scale (inclusive). Never assumed to be 1–5 anywhere else. */
  scaleMin: number
  scaleMax: number
  /** Written review limit in Unicode code points (not UTF-16 units / bytes). */
  textMaxLength: number
  categories: ReviewCategoryDef[]
  tags: ReviewTagDef[]
  itemFeedbackEnabled: boolean
  /** Optional title field is NOT approved — kept as a switch so the design can enable it later. */
  titleEnabled: boolean
  editEnabled: boolean
  /** Hours after submission during which the customer may edit (null = until moderation, configurable later). */
  editWindowHours: number | null
  /** Days after completion during which a review may be submitted. */
  reviewWindowDays: number
}

export type ItemFeedback = { lineId: string; itemName: string; sentiment: ItemSentiment | null }
export type ReviewDraft = {
  overallRating: number | null
  categoryRatings: Record<string, number>
  tags: string[]
  itemFeedback: ItemFeedback[]
  text: string
  /** Stable per draft — the backend uses it (with the order) for idempotency; double submits collapse to one review. */
  clientSubmissionId: string
}
export type Review = {
  reviewId: string
  orderPublicId: string
  orderNumber: string
  /** Derived from the order — never taken from the client. */
  restaurantId: string
  customerId: string
  overallRating: number
  categoryRatings: Record<string, number>
  tags: string[]
  itemFeedback: ItemFeedback[]
  text: string
  status: ReviewStatus
  version: number
  moderation: { reason: string | null; moderatedAt: string | null }
  /** Restaurant reply (Module 17) — moderated like the review; the restaurant can never edit the customer's rating or text. */
  restaurantResponse?: { text: string; respondedAt: string; responderName: string } | null
  /** Display name policy is pending (CF-206); fixtures carry a first name + initial only. */
  customerDisplayName?: string | null
  /** Backend reviews (Module 34): what the server says about this review — never computed by the client. */
  title?: string | null
  restaurantName?: string
  restaurantSlug?: string
  verifiedOrder?: boolean
  canEdit?: boolean
  canRemove?: boolean
  editableUntil?: string | null
  editsLeft?: number
  publishedAt?: string | null
  /** Restaurant Dashboard (Module 34): what the signed-in staff member may do with this review, and the state of the response. */
  canRespond?: boolean
  canReport?: boolean
  reported?: boolean
  responseVersion?: number
  responseHidden?: boolean
  createdAt: string
  updatedAt: string
}
export type EligibilityReason = 'ok' | 'not_completed' | 'not_owner' | 'window_expired' | 'already_reviewed' | 'blocked' | 'not_found' | 'removed'
export type ReviewEligibility = {
  eligible: boolean
  reason: EligibilityReason
  existingReview: Review | null
  /** An existing review may be edited (editEnabled + inside the edit window + not moderated). */
  canEdit: boolean
  /** When the review window closes (null when not applicable). */
  windowEndsAt: string | null
}
/** Aggregates are calculated server-side later; the mock aggregates only reviews stored on this device. */
export type RestaurantReviewSummary = { restaurantId: string; averageRating: number | null; reviewCount: number; distribution: Record<number, number>; source: 'mock_local' | 'server' }

/** A published review as anybody may read it (Module 34): a display name and nothing else of its author. */
export type PublicReview = {
  reviewId: string
  authorName: string
  overallRating: number
  categoryRatings: Record<string, number>
  tags: string[]
  title: string | null
  text: string
  edited: boolean
  createdAt: string
  response: { text: string; respondedAt: string; restaurantName: string; edited: boolean } | null
}
/** The rating of one restaurant location: the average of its published reviews (null = no reviews yet), their number and the stars. */
export type RatingSummary = { averageRating: number | null; reviewCount: number; distribution: Record<number, number>; categories: Record<string, { average: number | null; count: number }> }
export type PublicReviewSort = 'newest' | 'oldest' | 'highest' | 'lowest'
export type PublicReviewQuery = { page?: number; pageSize?: number; sort?: PublicReviewSort; rating?: number | null }
export type PublicReviewPage = { items: PublicReview[]; total: number; page: number; pageSize: number; summary: RatingSummary }
export const REPORT_REASONS = ['SPAM', 'HARASSMENT', 'PERSONAL_INFORMATION', 'OFFENSIVE_CONTENT', 'FAKE_REVIEW_CONCERN', 'OTHER'] as const
export type ReportReason = (typeof REPORT_REASONS)[number]

export interface ReviewRepository {
  getReviewForOrder(orderPublicId: string, customerId: string): Promise<Review | null>
  /** Creates the single review for the order (idempotent per order / clientSubmissionId); the restaurant comes from the order. */
  submitReview(order: Order, draft: ReviewDraft): Promise<Review>
  updateReview(reviewId: string, customerId: string, draft: ReviewDraft): Promise<Review>
  /** Customer deletion = remove from public view; the underlying record is retained (audit / legal) — backend decides. */
  deleteReview(reviewId: string, customerId: string): Promise<void>
  getRestaurantReviewSummary(restaurantId: string): Promise<RestaurantReviewSummary>
  /** The signed-in customer's own reviews, newest first (Module 34). */
  listMine(customerId: string): Promise<Review[]>
  /** The PUBLISHED reviews of a restaurant with its rating summary — paged, never the whole list. */
  listPublic(restaurantSlug: string, query?: PublicReviewQuery): Promise<PublicReviewPage>
  /** Asks a moderator to look at somebody else's published review. A report changes nothing by itself. */
  reportReview(reviewId: string, reason: ReportReason, explanation?: string): Promise<void>
}
export interface ReviewEligibilityService { check(order: Order, customerId: string, config: ReviewConfig): Promise<ReviewEligibility> }
export interface ReviewConfigProvider { configFor(order: Order): Promise<ReviewConfig> }

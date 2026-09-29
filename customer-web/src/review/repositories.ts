import type { Order } from '../order/repositories'

/**
 * Reviews, ratings & customer feedback (Module 16) — domain contracts.
 * A review is always tied to an eligible order (never an arbitrary restaurant); the restaurant is derived from the order.
 * Review content is untrusted input: length-validated here, sanitized / escaped / moderated by the backend later.
 * Submission state (what the customer did) is separate from moderation state (what the platform decided).
 */
export type ReviewStatus = 'SUBMITTED' | 'PENDING_MODERATION' | 'PUBLISHED' | 'HIDDEN' | 'REJECTED' | 'FLAGGED'
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
  createdAt: string
  updatedAt: string
}
export type EligibilityReason = 'ok' | 'not_completed' | 'not_owner' | 'window_expired' | 'already_reviewed' | 'blocked' | 'not_found'
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

export interface ReviewRepository {
  getReviewForOrder(orderPublicId: string, customerId: string): Promise<Review | null>
  /** Creates the single review for the order (idempotent per order / clientSubmissionId); the restaurant comes from the order. */
  submitReview(order: Order, draft: ReviewDraft): Promise<Review>
  updateReview(reviewId: string, customerId: string, draft: ReviewDraft): Promise<Review>
  /** Customer deletion = remove from public view; the underlying record is retained (audit / legal) — backend decides. */
  deleteReview(reviewId: string, customerId: string): Promise<void>
  getRestaurantReviewSummary(restaurantId: string): Promise<RestaurantReviewSummary>
}
export interface ReviewEligibilityService { check(order: Order, customerId: string, config: ReviewConfig): Promise<ReviewEligibility> }
export interface ReviewConfigProvider { configFor(order: Order): Promise<ReviewConfig> }

import type { ItemFeedback, Review, ReviewConfig, ReviewDraft, ReviewTagDef } from './repositories'
import type { Order } from '../order/repositories'

/** Pure form helpers (Module 16): validation, tag filtering and draft construction. No UI, no storage. */
export type ReviewErrors = { overallRating?: 'required' | 'out_of_range'; categories?: string[]; text?: 'too_long' }

/** Unicode-aware length (code points) so emoji / CJK count as characters, never as bytes or UTF-16 units. */
export const textLength = (s: string) => Array.from(s).length
export const remainingChars = (s: string, config: ReviewConfig) => config.textMaxLength - textLength(s)

export function validateReview(draft: ReviewDraft, config: ReviewConfig): ReviewErrors {
  const e: ReviewErrors = {}
  if (draft.overallRating == null) e.overallRating = 'required'
  else if (draft.overallRating < config.scaleMin || draft.overallRating > config.scaleMax || !Number.isInteger(draft.overallRating)) e.overallRating = 'out_of_range'
  const missing = config.categories.filter((c) => c.required && draft.categoryRatings[c.key] == null).map((c) => c.key)
  if (missing.length) e.categories = missing
  if (textLength(draft.text) > config.textMaxLength) e.text = 'too_long'
  return e
}
export const isValid = (draft: ReviewDraft, config: ReviewConfig) => Object.keys(validateReview(draft, config)).length === 0

/**
 * Tags offered for a rating: high ratings show positive + neutral, low ratings negative + neutral, the middle shows all.
 * Purely a relevance filter — the customer is never steered towards a positive review.
 */
export function tagsForRating(config: ReviewConfig, rating: number | null): ReviewTagDef[] {
  if (rating == null) return config.tags
  const span = config.scaleMax - config.scaleMin
  const pos = rating >= config.scaleMin + span * 0.75, neg = rating <= config.scaleMin + span * 0.25
  if (pos) return config.tags.filter((t) => t.sentiment !== 'negative')
  if (neg) return config.tags.filter((t) => t.sentiment !== 'positive')
  return config.tags
}

const newId = () => { try { return crypto.randomUUID() } catch { return `sub-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}` } }
export function emptyDraft(order: Order): ReviewDraft {
  return { overallRating: null, categoryRatings: {}, tags: [], itemFeedback: order.items.map<ItemFeedback>((i) => ({ lineId: i.lineId, itemName: i.itemName, sentiment: null })), text: '', clientSubmissionId: newId() }
}
/** Editing restores the existing content (TEST 10) with a fresh submission id. */
export function draftFromReview(r: Review, order: Order): ReviewDraft {
  const base = emptyDraft(order)
  return { ...base, overallRating: r.overallRating, categoryRatings: { ...r.categoryRatings }, tags: [...r.tags], text: r.text, itemFeedback: base.itemFeedback.map((f) => ({ ...f, sentiment: r.itemFeedback.find((x) => x.lineId === f.lineId)?.sentiment ?? null })) }
}
/** Trims and normalizes the draft before submission; keeps only tags / categories that exist in the config. */
export function normalizeDraft(draft: ReviewDraft, config: ReviewConfig): ReviewDraft {
  const keys = new Set(config.tags.map((t) => t.key)); const cats = new Set(config.categories.map((c) => c.key))
  return { ...draft, text: draft.text.trim(), tags: draft.tags.filter((k) => keys.has(k)), categoryRatings: Object.fromEntries(Object.entries(draft.categoryRatings).filter(([k]) => cats.has(k))), itemFeedback: config.itemFeedbackEnabled ? draft.itemFeedback : [] }
}

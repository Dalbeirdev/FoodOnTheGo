import { useEffect, useState } from 'react'
import type { Order } from '../order/repositories'
import { reviewRepositories } from './mock/mockReview'
import type { Review, ReviewConfigProvider, ReviewEligibility, ReviewEligibilityService, ReviewRepository } from './repositories'

export type ReviewDeps = { reviews: ReviewRepository; eligibility: ReviewEligibilityService; config: ReviewConfigProvider }
export type ReviewEntry = { state: 'loading' | 'eligible' | 'reviewed' | 'ineligible' | 'error'; eligibility: ReviewEligibility | null; review: Review | null }

/** Shared entry-point logic (Module 16): which review action an order should offer — Rate / View review / Edit review / none. */
export function useReviewEntry(order: Order | null, customerId: string | null, deps: ReviewDeps = reviewRepositories): ReviewEntry {
  const [entry, setEntry] = useState<ReviewEntry>({ state: 'loading', eligibility: null, review: null })
  const key = order ? `${order.publicId}:${order.orderStatus}:${customerId ?? ''}` : ''
  useEffect(() => {
    let on = true
    if (!order || !customerId) { setEntry({ state: 'ineligible', eligibility: null, review: null }); return }
    ;(async () => {
      try {
        const config = await deps.config.configFor(order)
        const el = await deps.eligibility.check(order, customerId, config)
        if (!on) return
        setEntry({ state: el.eligible ? 'eligible' : el.existingReview ? 'reviewed' : 'ineligible', eligibility: el, review: el.existingReview })
      } catch { if (on) setEntry({ state: 'error', eligibility: null, review: null }) }
    })()
    return () => { on = false }
  }, [key]) // eslint-disable-line react-hooks/exhaustive-deps
  return entry
}

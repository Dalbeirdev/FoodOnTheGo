import { orderMode } from '../order/orderMode'

/**
 * Where reviews come from (Module 34): the backend (`api`) or the development data on this device (`mock`).
 * A review belongs to a backend order, so reviews can only be on the backend when orders are. Unit tests and the shared
 * preview build always run the development data. `localStorage fotg.review.mode` overrides for local review.
 */
export type ReviewSource = 'api' | 'mock'

export function reviewMode(): ReviewSource {
  if (orderMode() !== 'api') return 'mock'
  try { const override = localStorage.getItem('fotg.review.mode'); if (override === 'api' || override === 'mock') return override } catch { /* storage unavailable */ }
  if (import.meta.env.MODE === 'test' || import.meta.env.VITE_SHARE_BUILD === '1') return 'mock'
  return import.meta.env.VITE_REVIEW_MODE === 'api' ? 'api' : 'mock'
}

import { orderMode } from '../order/orderMode'
import { modeOverride } from '../config/modeOverride'

/**
 * Where reviews come from (Module 34): the backend (`api`) or the development data on this device (`mock`).
 * A review belongs to a backend order, so reviews can only be on the backend when orders are. Unit tests and the shared
 * preview build always run the development data. `localStorage fotg.review.mode` overrides (development builds only — Module 39) for local review.
 */
export type ReviewSource = 'api' | 'mock'

export function reviewMode(): ReviewSource {
  if (orderMode() !== 'api') return 'mock'
  // Module 39: the browser override is honoured in development builds only (see config/modeOverride.ts).
  const override = modeOverride('fotg.review.mode', ['api', 'mock'] as const); if (override) return override
  if (import.meta.env.MODE === 'test' || import.meta.env.VITE_SHARE_BUILD === '1') return 'mock'
  return import.meta.env.VITE_REVIEW_MODE === 'api' ? 'api' : 'mock'
}

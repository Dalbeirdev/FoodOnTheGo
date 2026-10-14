import { orderMode } from '../order/orderMode'
import { modeOverride } from '../config/modeOverride'

/**
 * Whether the Customer Web sends behavioural events (Module 38).
 *
 * `api`: the funnel events of `registry.ts` go to the backend's own endpoint (POST /analytics/events).
 * `off`: nothing is queued, nothing is sent — every function of the emitter is a no-op.
 *
 * Set with VITE_ANALYTICS_MODE (`api` | `off`). Without it the events follow the ORDER mode: they are on exactly when
 * the application talks to the real backend, and off in mock mode. Unit tests and static share builds never send
 * anything (there is no backend behind them). `localStorage fotg.analytics.mode` overrides it on one device (development builds only — Module 39) — the
 * same switch every other mode has; it is how a test turns the emitter on.
 */
export type AnalyticsMode = 'api' | 'off'

export function analyticsMode(): AnalyticsMode {
  // Module 39: the browser override is honoured in development builds only (see config/modeOverride.ts).
  const override = modeOverride('fotg.analytics.mode', ['api', 'off'] as const); if (override) return override
  if (import.meta.env.MODE === 'test' || import.meta.env.VITE_SHARE_BUILD === '1') return 'off'
  const configured = import.meta.env.VITE_ANALYTICS_MODE as string | undefined
  if (configured === 'api' || configured === 'off') return configured
  return orderMode() === 'api' ? 'api' : 'off'
}

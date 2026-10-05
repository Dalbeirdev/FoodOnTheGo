/**
 * Where journeys, places, routes and route-based restaurant discovery come from.
 *
 *  - 'api'  — the backend (Module 26): places are searched and resolved by the backend's places provider, the route
 *             comes from its routing provider, and restaurants along the route are found by PostGIS with ACTUAL
 *             detours from the routing provider. Nothing about a route is calculated in the browser.
 *  - 'mock' — the in-browser development providers of Module 05 / 06 (straight-line estimates, labelled as such).
 *             Always used by unit tests and by the static share builds, which have no backend.
 *
 * The build decides (VITE_JOURNEY_MODE). `localStorage fotg.journey.mode` overrides it on one browser.
 * 'api' is meant to be used together with VITE_RESTAURANT_MODE=api and VITE_MARKET_MODE=api: the restaurants a
 * journey finds are the backend's restaurants.
 */
export type JourneyMode = 'api' | 'mock'

export function journeyMode(): JourneyMode {
  try { const override = localStorage.getItem('fotg.journey.mode'); if (override === 'api' || override === 'mock') return override } catch { /* storage unavailable */ }
  if (import.meta.env.MODE === 'test' || import.meta.env.VITE_SHARE_BUILD === '1') return 'mock'
  return import.meta.env.VITE_JOURNEY_MODE === 'api' ? 'api' : 'mock'
}

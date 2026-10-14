import { modeOverride } from '../config/modeOverride'

/**
 * Where market and geography data comes from.
 *
 *  - 'api'  — the backend (Module 22): markets, regions, cities, service areas and corridors are read from the API;
 *             administrators change them through the admin API. The browser holds a read-only snapshot.
 *  - 'mock' — the in-browser development fixtures. Always used by unit tests and by the static share builds,
 *             which have no backend.
 *
 * The build decides (VITE_MARKET_MODE). `localStorage fotg.market.mode` overrides it on one browser (development builds only — Module 39).
 */
export type MarketMode = 'api' | 'mock'

export function marketMode(): MarketMode {
  // Module 39: the browser override is honoured in development builds only (see config/modeOverride.ts).
  const override = modeOverride('fotg.market.mode', ['api', 'mock'] as const); if (override) return override
  if (import.meta.env.MODE === 'test' || import.meta.env.VITE_SHARE_BUILD === '1') return 'mock'
  return import.meta.env.VITE_MARKET_MODE === 'api' ? 'api' : 'mock'
}

import { modeOverride } from '../config/modeOverride'

/**
 * Where restaurant data comes from.
 *
 *  - 'api'  — the backend (Module 23): the customer pages read restaurants, opening hours, cuisines, pickup methods
 *             and availability from the API; the Restaurant Dashboard and the admin restaurant screens read and
 *             write through the API. Menus, carts, orders, reviews and analytics are NOT part of this: they stay
 *             development data until their own backend modules exist.
 *  - 'mock' — the in-browser development fixtures. Always used by unit tests and by the static share builds,
 *             which have no backend.
 *
 * The build decides (VITE_RESTAURANT_MODE). `localStorage fotg.restaurant.mode` overrides it on one browser (development builds only — Module 39).
 * 'api' is meant to be used together with VITE_MARKET_MODE=api and VITE_AUTH_MODE=api.
 */
export type RestaurantMode = 'api' | 'mock'

export function restaurantMode(): RestaurantMode {
  // Module 39: the browser override is honoured in development builds only (see config/modeOverride.ts).
  const override = modeOverride('fotg.restaurant.mode', ['api', 'mock'] as const); if (override) return override
  if (import.meta.env.MODE === 'test' || import.meta.env.VITE_SHARE_BUILD === '1') return 'mock'
  return import.meta.env.VITE_RESTAURANT_MODE === 'api' ? 'api' : 'mock'
}

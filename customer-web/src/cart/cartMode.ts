import { modeOverride } from '../config/modeOverride'

/**
 * Where the cart lives.
 *
 *  - 'api'  — the backend (Module 27): one cart per guest (opaque token) or signed-in customer, priced and validated
 *             by the server on every read. The browser sends ids, quantities and text — never a price.
 *  - 'mock' — the device-only development cart of Module 08 / 09 (localStorage). Always used by unit tests and by
 *             the static share builds, which have no backend.
 *
 * The build decides (VITE_CART_MODE). `localStorage fotg.cart.mode` overrides it on one browser (development builds only — Module 39).
 * 'api' needs VITE_RESTAURANT_MODE=api: the items a cart holds are the backend's menu items.
 */
export type CartMode = 'api' | 'mock'

export function cartMode(): CartMode {
  // Module 39: the browser override is honoured in development builds only (see config/modeOverride.ts).
  const override = modeOverride('fotg.cart.mode', ['api', 'mock'] as const); if (override) return override
  if (import.meta.env.MODE === 'test' || import.meta.env.VITE_SHARE_BUILD === '1') return 'mock'
  return import.meta.env.VITE_CART_MODE === 'api' ? 'api' : 'mock'
}

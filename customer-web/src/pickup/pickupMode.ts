import { cartMode } from '../cart/cartMode'

/**
 * Where pickup times come from.
 *
 *  - 'api'  — the backend (Module 28): ASAP, scheduled slots, capacity, the recommendation and the validation of a
 *             chosen time are the server's, calculated on its clock in the restaurant's time zone. The browser sends
 *             a mode, a method and a slot key — never a time.
 *  - 'mock' — the in-browser development slot engine of Module 10. Always used by unit tests and by the static share
 *             builds, which have no backend.
 *
 * The build decides (VITE_PICKUP_MODE). `localStorage fotg.pickup.mode` overrides it on one browser.
 * Pickup times belong to the cart, so 'api' needs the cart on the backend too (VITE_CART_MODE=api).
 */
export type PickupSource = 'api' | 'mock'

export function pickupMode(): PickupSource {
  if (cartMode() !== 'api') return 'mock'
  try { const override = localStorage.getItem('fotg.pickup.mode'); if (override === 'api' || override === 'mock') return override } catch { /* storage unavailable */ }
  if (import.meta.env.MODE === 'test' || import.meta.env.VITE_SHARE_BUILD === '1') return 'mock'
  return import.meta.env.VITE_PICKUP_MODE === 'api' ? 'api' : 'mock'
}

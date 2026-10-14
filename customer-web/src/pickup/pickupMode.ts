import { cartMode } from '../cart/cartMode'
import { modeOverride } from '../config/modeOverride'

/**
 * Where pickup times come from.
 *
 *  - 'api'  — the backend (Module 28): ASAP, scheduled slots, capacity, the recommendation and the validation of a
 *             chosen time are the server's, calculated on its clock in the restaurant's time zone. The browser sends
 *             a mode, a method and a slot key — never a time.
 *  - 'mock' — the in-browser development slot engine of Module 10. Always used by unit tests and by the static share
 *             builds, which have no backend.
 *
 * The build decides (VITE_PICKUP_MODE). `localStorage fotg.pickup.mode` overrides it on one browser (development builds only — Module 39).
 * Pickup times belong to the cart, so 'api' needs the cart on the backend too (VITE_CART_MODE=api).
 */
export type PickupSource = 'api' | 'mock'

export function pickupMode(): PickupSource {
  if (cartMode() !== 'api') return 'mock'
  // Module 39: the browser override is honoured in development builds only (see config/modeOverride.ts).
  const override = modeOverride('fotg.pickup.mode', ['api', 'mock'] as const); if (override) return override
  if (import.meta.env.MODE === 'test' || import.meta.env.VITE_SHARE_BUILD === '1') return 'mock'
  return import.meta.env.VITE_PICKUP_MODE === 'api' ? 'api' : 'mock'
}

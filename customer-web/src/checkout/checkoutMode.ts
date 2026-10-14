import { cartMode } from '../cart/cartMode'
import { pickupMode } from '../pickup/pickupMode'
import { modeOverride } from '../config/modeOverride'

/**
 * Where checkout comes from.
 *
 *  - 'api'  — the backend (Module 29): the cart, the pickup time, every price, the promotion, taxes, fees and the
 *             total are validated and calculated by the server; the browser sends a promotion code, the legal documents
 *             it showed, how the customer intends to pay and an order note — never an amount.
 *  - 'mock' — the in-browser development checkout of Module 11. Always used by unit tests and by the static share
 *             builds, which have no backend.
 *
 * The build decides (VITE_CHECKOUT_MODE). `localStorage fotg.checkout.mode` overrides it on one browser (development builds only — Module 39).
 * A checkout is made from the backend's cart and pickup selection, so 'api' needs both on the backend too
 * (VITE_CART_MODE=api and VITE_PICKUP_MODE=api).
 */
export type CheckoutSource = 'api' | 'mock'

export function checkoutMode(): CheckoutSource {
  if (cartMode() !== 'api' || pickupMode() !== 'api') return 'mock'
  // Module 39: the browser override is honoured in development builds only (see config/modeOverride.ts).
  const override = modeOverride('fotg.checkout.mode', ['api', 'mock'] as const); if (override) return override
  if (import.meta.env.MODE === 'test' || import.meta.env.VITE_SHARE_BUILD === '1') return 'mock'
  return import.meta.env.VITE_CHECKOUT_MODE === 'api' ? 'api' : 'mock'
}

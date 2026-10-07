import { cartMode } from '../cart/cartMode'
import { pickupMode } from '../pickup/pickupMode'

/**
 * Where checkout comes from.
 *
 *  - 'api'  — the backend (Module 29): the cart, the pickup time, every price, the promotion, taxes, fees and the
 *             total are validated and calculated by the server; the browser sends a promotion code, the legal documents
 *             it showed, how the customer intends to pay and an order note — never an amount.
 *  - 'mock' — the in-browser development checkout of Module 11. Always used by unit tests and by the static share
 *             builds, which have no backend.
 *
 * The build decides (VITE_CHECKOUT_MODE). `localStorage fotg.checkout.mode` overrides it on one browser.
 * A checkout is made from the backend's cart and pickup selection, so 'api' needs both on the backend too
 * (VITE_CART_MODE=api and VITE_PICKUP_MODE=api).
 */
export type CheckoutSource = 'api' | 'mock'

export function checkoutMode(): CheckoutSource {
  if (cartMode() !== 'api' || pickupMode() !== 'api') return 'mock'
  try { const override = localStorage.getItem('fotg.checkout.mode'); if (override === 'api' || override === 'mock') return override } catch { /* storage unavailable */ }
  if (import.meta.env.MODE === 'test' || import.meta.env.VITE_SHARE_BUILD === '1') return 'mock'
  return import.meta.env.VITE_CHECKOUT_MODE === 'api' ? 'api' : 'mock'
}

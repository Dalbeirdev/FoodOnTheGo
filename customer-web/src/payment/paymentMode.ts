import { checkoutMode } from '../checkout/checkoutMode'
import { modeOverride } from '../config/modeOverride'

/**
 * Where the payment step runs (Module 30).
 *
 * `api`:  the backend's payment attempt — the amount is the confirmed checkout's total, the provider's own interface
 *         collects whatever the customer types, and the backend verifies what happened. It needs the backend checkout.
 * `mock`: the in-browser development payment of Module 12 — for unit tests and static share builds only.
 *
 * Set with VITE_PAYMENT_MODE; `localStorage fotg.payment.mode` overrides it on one device (development builds only — Module 39).
 */
export type PaymentSource = 'api' | 'mock'

export function paymentMode(): PaymentSource {
  if (checkoutMode() !== 'api') return 'mock'
  // Module 39: the browser override is honoured in development builds only (see config/modeOverride.ts).
  const override = modeOverride('fotg.payment.mode', ['api', 'mock'] as const); if (override) return override
  if (import.meta.env.MODE === 'test' || import.meta.env.VITE_SHARE_BUILD === '1') return 'mock'
  return import.meta.env.VITE_PAYMENT_MODE === 'api' ? 'api' : 'mock'
}

import { paymentMode } from '../payment/paymentMode'

/**
 * Where orders come from (Module 31).
 *
 * `api`:  the backend's orders — created on the server from a VERIFIED payment (or a zero-total checkout), read by
 *         order number for the customer who owns them, worked by the restaurant through the backend's steps. The
 *         browser never creates an order, never sets its status and never prices it. It needs the backend payment.
 * `mock`: the in-browser development orders of Modules 13–15 — for unit tests and static share builds only.
 *
 * Set with VITE_ORDER_MODE; `localStorage fotg.order.mode` overrides it on one device.
 */
export type OrderSource = 'api' | 'mock'

export function orderMode(): OrderSource {
  if (paymentMode() !== 'api') return 'mock'
  try { const override = localStorage.getItem('fotg.order.mode'); if (override === 'api' || override === 'mock') return override } catch { /* storage unavailable */ }
  if (import.meta.env.MODE === 'test' || import.meta.env.VITE_SHARE_BUILD === '1') return 'mock'
  return import.meta.env.VITE_ORDER_MODE === 'api' ? 'api' : 'mock'
}

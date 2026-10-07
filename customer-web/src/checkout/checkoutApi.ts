import { createContext, useContext } from 'react'
import type { CartReview } from '../cart/cartValidation'
import type { PickupValidation } from '../pickup/repositories'
import type { Restaurant } from '../repositories/types'
import type { ServerCheckout } from './api/apiCheckout'
import type { CheckoutIssue, CheckoutRequest, CheckoutStatus, CheckoutSummary, PaymentMethodOption, PromoResult } from './repositories'

/**
 * Checkout as the pages use it — one interface, two implementations chosen by checkoutMode():
 *  - the development checkout of Module 11 (mock mode: unit tests, share builds), and
 *  - the backend of Module 29 (API mode), where the validation, every price, the promotion, taxes, fees, the total,
 *    the expiry and the legal documents are the server's.
 *
 * INITIALIZING → VALIDATING → READY / REQUIRES_AUTH / INVALID_CART / INVALID_PICKUP / OFFLINE / ERROR / EXPIRED
 * → PAYMENT_READY. The CheckoutRequest it produces carries no authoritative amount.
 */
/** API mode: the checkout on the screen replaced an earlier one because something changed. */
export type CheckoutNotice = {
  kind: 'updated'
  /** The backend's words for what changed (null: nothing more specific is known). */
  message: string | null
  /** The total the customer saw before, when it is another one now. */
  previousTotalMinor: number | null
  /** The legal documents changed: they have to be accepted again. */
  legal: boolean
}

export type CheckoutApi = {
  /** 'api' = the backend decides (Module 29); 'mock' = the in-browser development checkout. */
  source: 'api' | 'mock'
  status: CheckoutStatus
  restaurant: Restaurant | null
  review: CartReview | null
  pickupResult: PickupValidation | null
  summary: CheckoutSummary | null
  issues: CheckoutIssue[]
  promo: PromoResult | null
  promoBusy: boolean
  methods: PaymentMethodOption[]
  paymentMethodId: string | null
  termsAccepted: boolean
  error: string | null
  online: boolean
  request: CheckoutRequest | null
  /** API mode: the checkout exactly as the backend priced it (null in mock mode and until the first answer). */
  server: ServerCheckout | null
  /**
   * API mode: the checkout the backend says is being paid for right now (null otherwise). It is how the payment page
   * finds a payment again when nothing was kept in this tab — a new tab, another device.
   */
  paymentCheckoutId: string | null
  notice: CheckoutNotice | null
  prepare: () => Promise<void>
  /**
   * API mode: ask the backend what the checkout is NOW — when the tab comes back, and before paying. Resolves true only
   * when the checkout on the screen is still exactly what the backend holds; false when it was replaced, expired or
   * could not be confirmed (no connection is not "still fine").
   */
  revalidate: () => Promise<boolean>
  applyPromo: (code: string) => Promise<PromoResult>
  removePromo: () => void
  setPaymentMethod: (id: string) => void
  setTermsAccepted: (v: boolean) => void
  continueToPayment: () => Promise<CheckoutRequest | null>
  /** Called once the order exists — a consumed request must never re-enter payment. */
  clearRequest: () => void
  acceptPriceChange: (itemId: string, newUnitMinor: number) => void
}

export const CheckoutCtx = createContext<CheckoutApi | null>(null)

export function useCheckout() {
  const ctx = useContext(CheckoutCtx)
  if (!ctx) throw new Error('useCheckout must be used inside CheckoutProvider')
  return ctx
}

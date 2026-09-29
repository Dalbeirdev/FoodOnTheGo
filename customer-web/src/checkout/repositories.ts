import type { Cart } from '../cart/cartModel'
import type { PickupSelection } from '../pickup/repositories'

/**
 * Checkout abstractions (Module 11). Everything the customer sees here is a REVIEW of centralized
 * state (auth, cart, pickup, journey). Totals are display-only: the backend recalculates items,
 * modifiers, discounts, taxes, fees and the final amount before payment initiation or order creation.
 * No raw card / UPI / bank credentials are collected anywhere in this layer.
 */
export type CheckoutStatus = 'INITIALIZING' | 'READY' | 'VALIDATING' | 'REQUIRES_AUTH' | 'INVALID_CART' | 'INVALID_PICKUP' | 'PAYMENT_READY' | 'ERROR' | 'OFFLINE'

/* ---------------- promotions ---------------- */
export type PromotionType = 'fixed_amount' | 'percentage' | 'restaurant_promotion' | 'platform_promotion'
export type Promotion = {
  code: string
  type: PromotionType
  /** Percentage points for percentage types; minor units for fixed amounts. */
  value: number
  eligibility: 'all' | 'new_customers'
  minimumSpendMinor: number
  /** Cap for percentage discounts, minor units (null = no cap). */
  maximumDiscountMinor: number | null
  /** ISO 4217 the promotion is denominated in (null = any currency, percentage only). */
  currency: string | null
  restaurantScope: string[] | null
  marketScope: string[] | null
  validFrom: string
  validTo: string | null
}
export type PromoStatus = 'idle' | 'applying' | 'applied' | 'invalid' | 'expired' | 'not_eligible' | 'min_spend' | 'restaurant_not_eligible' | 'market_not_eligible' | 'currency_not_eligible'
export type PromoContext = { subtotalMinor: number; currency: string; restaurantId: string; countryCode: string; nowIso?: string; customerIsNew?: boolean }
export type PromoResult = { code: string; status: PromoStatus; discountMinor: number; promotion: Promotion | null; minimumSpendMinor?: number }

export interface PromotionRepository {
  /** Development evaluation only — the backend promotion engine decides eligibility for real. */
  evaluate(code: string, ctx: PromoContext): Promise<PromoResult>
}

/* ---------------- payment methods ---------------- */
export type PaymentMethodType = 'upi' | 'card' | 'wallet' | 'netbanking' | 'cash_at_pickup'
export type PaymentMethodOption = { id: string; type: PaymentMethodType; label: string; description?: string; provider: string; enabled: boolean; reasonDisabled?: string }
export type PaymentCapabilityContext = { countryCode: string; currency: string }

export interface PaymentMethodRepository {
  /** Provider / market dependent capabilities (mock now, provider capability API later). Never hardcoded in checkout. */
  getAvailableMethods(ctx: PaymentCapabilityContext): Promise<PaymentMethodOption[]>
}

/* ---------------- checkout summary + request ---------------- */
export type SummaryLine = { id: string; label: string; amountMinor: number; kind: 'subtotal' | 'discount' | 'tax' | 'fee' | 'total' }
/** What a server-authoritative checkout summary will look like (mock-built today from the same domain data). */
export type CheckoutSummary = {
  currency: string
  itemCount: number
  subtotalMinor: number
  discountMinor: number
  /** Only configured components appear here — none are configured in the development market. */
  taxes: SummaryLine[]
  fees: SummaryLine[]
  totalMinor: number
  expiresAt: string | null
  paymentEligible: boolean
  source: 'mock'
}

/** Payment-intent preparation. No client-controlled final charge amount: the server prices this request itself. */
export type CheckoutRequest = {
  idempotencyKey: string
  customerId: string
  cartId: string
  restaurantId: string
  pickupSelection: PickupSelection
  promoCode: string | null
  currency: string
  orderNote: string
  termsAccepted: boolean
  termsVersion: string
  privacyVersion: string
  acceptedAt: string
  paymentMethodId: string
  /** Display-only echo so support can compare what the customer saw; never authoritative. */
  displayedTotalMinor: number
  createdAt: string
}

export type CheckoutIssue = { code: 'auth' | 'cart_empty' | 'cart_invalid' | 'price_changed' | 'pickup_missing' | 'pickup_invalid' | 'restaurant_unavailable' | 'restaurant_not_accepting' | 'promo_invalid' | 'terms' | 'payment_method' | 'currency' | 'offline'; blocking: boolean; detail?: string }

export interface CheckoutRepository {
  /** Builds the (mock) summary from validated domain data. */
  buildSummary(cart: Cart, discountMinor: number, nowIso: string): Promise<CheckoutSummary>
}

export interface ConnectivityService {
  isOnline(): boolean
  subscribe(cb: (online: boolean) => void): () => void
}

export class CheckoutError extends Error {
  code: 'unavailable' | 'network'
  constructor(code: CheckoutError['code'], message: string) { super(message); this.name = 'CheckoutError'; this.code = code }
}

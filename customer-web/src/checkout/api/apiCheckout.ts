import { api, ApiError } from '../../api/client'
import type { PromoStatus } from '../repositories'

/**
 * Checkout on the backend (Module 29).
 *
 * The backend is the authority on WHAT is paid: it validates the cart, the restaurant and the pickup time again,
 * prices every line from the current menu, decides whether a promotion applies, adds only the taxes and fees an
 * administrator configured, and names the total. This file only asks and maps. It never sends an amount, a currency,
 * a customer, a cart or a restaurant — a promotion code, the legal documents that were shown (type + version), how
 * the customer intends to pay (a method type or one of their saved references) and an order note are all a request
 * consists of. No card number, CVV, UPI PIN or bank credential ever passes through here.
 *
 * A checkout belongs to the signed-in customer: every call goes out with the session.
 */

/* ------------------------------------------------------------------ wire types */
type MoneyDto = { amount: number; currency: string }
type SelectionDto = { group_id: string | null; group_name: string; kind: 'VARIANT' | 'MODIFIER'; options: Array<{ id: string | null; name: string; price_adjustment_minor: number; available: boolean }> }
export type CheckoutIssueDto = { code: string; message: string; recover: 'cart' | 'pickup' | 'checkout' | 'refresh' | 'payment'; details: Record<string, unknown> }
export type CheckoutStatusDto = 'DRAFT' | 'READY' | 'STALE' | 'EXPIRED' | 'PAYMENT_PENDING' | 'CONVERTED' | 'CANCELLED'
export type CheckoutDto = {
  id: string; status: CheckoutStatusDto; status_reason: string | null; version: number; expires_at: string; server_time: string; created_at: string; cart_version: number
  restaurant: { id: string; slug: string; name: string; timezone: string }
  pickup: {
    mode: 'ASAP' | 'SCHEDULED'; method: string; pickup_at: string; local_pickup_at: string; local_date: string; slot_starts_at: string; slot_ends_at: string; timezone: string
    estimated_prep_minutes: number | null; traveler_arrival_at: string | null; hold: { held: boolean; expires_at: string | null }
  }
  items: Array<{
    id: string; item: { id: string; slug: string; name: string; image: { url: string; alt_text: string | null } | null; version: number }; name: string; quantity: number; special_instructions: string | null
    variants: SelectionDto[]; modifiers: SelectionDto[]; unit_price_minor: number; line_subtotal_minor: number; currency: string
  }>
  item_count: number
  pricing: {
    currency: string; items_subtotal: MoneyDto; discounts: Array<{ code: string; label: string; amount: MoneyDto }>; discount_total: MoneyDto
    taxes: Array<{ code: string; label: string; applies_to: 'MERCHANDISE' | 'FEES'; rate_basis_points: number; taxable_amount: MoneyDto; amount: MoneyDto; included_in_prices: boolean }>
    tax_total: MoneyDto; tax_included_total: MoneyDto
    fees: Array<{ code: string; label: string; amount: MoneyDto; taxable: boolean }>; fee_total: MoneyDto
    total: MoneyDto; payment_required: boolean; fingerprint: string; configuration: { tax: RuleConfiguration; fees: RuleConfiguration }
  }
  promotion: { code: string; name: string; discount: MoneyDto } | null
  customer: { name: string | null; phone: string; phone_verified: boolean; email: string | null }
  order_note: string | null
  legal: {
    required: Array<{ type: string; version: string; effective_date: string | null; status: string; slug: string }>
    accepted: Array<{ type: string; version: string; status: string; accepted_at: string }>
    final_approval: boolean
  }
  payment_methods: {
    provider_status: string; available: Array<{ type: string; status: string }>
    saved: Array<{ id: string; type: string; provider: string; brand: string | null; display_label: string; last4: string | null; is_default: boolean; status: string }>
    selected: { type: string; saved_method_id: string | null } | null; cash_at_pickup: 'NOT_APPROVED'
  }
  payment: {
    status: 'READY_FOR_PAYMENT' | 'PAYMENT_NOT_REQUIRED'; checkout_id: string; checkout_version: number; amount: MoneyDto; currency: string; pricing_fingerprint: string
    idempotency_key: string; payment_method: { type: string; saved_method_id: string | null } | null; expires_at: string; provider: { status: string }
  } | null
  issues: CheckoutIssueDto[]
  changes: Array<{ code: string } & Record<string, unknown>>
}

/* ------------------------------------------------------------------ what the pages read */
/** NOT_CONFIGURED: no rule exists (nothing is invented). TEST: a development configuration. APPROVED: approved rules. */
export type RuleConfiguration = 'NOT_CONFIGURED' | 'TEST' | 'APPROVED'
export type CheckoutIssueView = { code: string; message: string; recover: CheckoutIssueDto['recover']; details: Record<string, unknown> }
export type CheckoutLine = {
  id: string; itemSlug: string; name: string; quantity: number; specialInstructions: string | null; image: string
  options: Array<{ key: string; groupName: string; optionName: string }>
  unitPriceMinor: number; lineSubtotalMinor: number
}
export type LegalDocument = { type: string; version: string; effectiveDate: string | null; status: string; slug: string }
export type SavedMethod = { id: string; type: string; label: string; brand: string | null; last4: string | null; isDefault: boolean }
/** A checkout exactly as the backend priced it. Every amount is the server's, in integer minor units. */
export type ServerCheckout = {
  id: string
  status: CheckoutStatusDto
  statusReason: string | null
  version: number
  expiresAt: string
  /** The server clock when this answer was made … */
  serverTime: string
  /** … and this device's clock when it arrived. The time left is counted on the difference, never on the device's date. */
  receivedAtMs: number
  cartVersion: number
  restaurant: { id: string; slug: string; name: string; timezone: string }
  pickup: {
    mode: 'asap' | 'scheduled'; method: string; pickupAt: string; localDate: string; slotStartsAt: string; slotEndsAt: string; timezone: string
    estimatedPrepMinutes: number | null; travelerArrivalAt: string | null
    /** A confirmed checkout holds its place in the pickup slot until it expires. */
    held: boolean; holdExpiresAt: string | null
  }
  items: CheckoutLine[]
  itemCount: number
  pricing: {
    currency: string; subtotalMinor: number
    discounts: Array<{ code: string; label: string; amountMinor: number }>; discountMinor: number
    taxes: Array<{ code: string; label: string; appliesTo: 'MERCHANDISE' | 'FEES'; rateBasisPoints: number; taxableMinor: number; amountMinor: number; included: boolean }>
    /** Tax added on top of the prices, and — separately — tax the prices already contain. */
    taxMinor: number; taxIncludedMinor: number
    fees: Array<{ code: string; label: string; amountMinor: number; taxable: boolean }>; feeMinor: number
    totalMinor: number; paymentRequired: boolean; fingerprint: string
    configuration: { tax: RuleConfiguration; fees: RuleConfiguration }
  }
  promotion: { code: string; name: string; discountMinor: number } | null
  customer: { name: string | null; phone: string; phoneVerified: boolean; email: string | null }
  orderNote: string | null
  legal: { required: LegalDocument[]; accepted: Array<{ type: string; version: string; acceptedAt: string }>; finalApproval: boolean }
  paymentMethods: { providerStatus: string; available: Array<{ type: string; status: string }>; saved: SavedMethod[]; selected: { type: string; savedMethodId: string | null } | null }
  /** The preparation for the payment module — present only once the checkout is confirmed. Nothing has been charged. */
  payment: { status: 'READY_FOR_PAYMENT' | 'PAYMENT_NOT_REQUIRED'; amountMinor: number; currency: string; idempotencyKey: string; fingerprint: string; expiresAt: string; checkoutVersion: number } | null
  issues: CheckoutIssueView[]
  /** What was different when this checkout was created (a price, a promotion) — to tell the customer before they pay. */
  changes: Array<{ code: string } & Record<string, unknown>>
}

const options = (groups: SelectionDto[], kind: string) => groups.flatMap((g) => g.options.map((o, i) => ({ key: `${kind}:${g.group_id ?? g.group_name}:${o.id ?? i}`, groupName: g.group_name, optionName: o.name })))

export function toCheckout(d: CheckoutDto, receivedAtMs: number = Date.now()): ServerCheckout {
  return {
    id: d.id, status: d.status, statusReason: d.status_reason, version: d.version, expiresAt: d.expires_at, serverTime: d.server_time, receivedAtMs, cartVersion: d.cart_version,
    restaurant: d.restaurant,
    pickup: {
      mode: d.pickup.mode === 'ASAP' ? 'asap' : 'scheduled', method: d.pickup.method, pickupAt: d.pickup.pickup_at, localDate: d.pickup.local_date, slotStartsAt: d.pickup.slot_starts_at, slotEndsAt: d.pickup.slot_ends_at,
      timezone: d.pickup.timezone, estimatedPrepMinutes: d.pickup.estimated_prep_minutes, travelerArrivalAt: d.pickup.traveler_arrival_at, held: d.pickup.hold.held, holdExpiresAt: d.pickup.hold.expires_at,
    },
    items: d.items.map((l) => ({
      id: l.id, itemSlug: l.item.slug, name: l.name || l.item.name, quantity: l.quantity, specialInstructions: l.special_instructions, image: l.item.image?.url ?? '',
      options: [...options(l.variants, 'v'), ...options(l.modifiers, 'm')], unitPriceMinor: l.unit_price_minor, lineSubtotalMinor: l.line_subtotal_minor,
    })),
    itemCount: d.item_count,
    pricing: {
      currency: d.pricing.currency, subtotalMinor: d.pricing.items_subtotal.amount,
      discounts: d.pricing.discounts.map((x) => ({ code: x.code, label: x.label, amountMinor: x.amount.amount })), discountMinor: d.pricing.discount_total.amount,
      taxes: d.pricing.taxes.map((x) => ({ code: x.code, label: x.label, appliesTo: x.applies_to, rateBasisPoints: x.rate_basis_points, taxableMinor: x.taxable_amount.amount, amountMinor: x.amount.amount, included: x.included_in_prices })),
      taxMinor: d.pricing.tax_total.amount, taxIncludedMinor: d.pricing.tax_included_total.amount,
      fees: d.pricing.fees.map((x) => ({ code: x.code, label: x.label, amountMinor: x.amount.amount, taxable: x.taxable })), feeMinor: d.pricing.fee_total.amount,
      totalMinor: d.pricing.total.amount, paymentRequired: d.pricing.payment_required, fingerprint: d.pricing.fingerprint, configuration: d.pricing.configuration,
    },
    promotion: d.promotion ? { code: d.promotion.code, name: d.promotion.name, discountMinor: d.promotion.discount.amount } : null,
    customer: { name: d.customer.name, phone: d.customer.phone, phoneVerified: d.customer.phone_verified, email: d.customer.email },
    orderNote: d.order_note,
    legal: {
      required: d.legal.required.map((x) => ({ type: x.type, version: x.version, effectiveDate: x.effective_date, status: x.status, slug: x.slug })),
      accepted: d.legal.accepted.map((x) => ({ type: x.type, version: x.version, acceptedAt: x.accepted_at })), finalApproval: d.legal.final_approval,
    },
    paymentMethods: {
      providerStatus: d.payment_methods.provider_status, available: d.payment_methods.available,
      saved: d.payment_methods.saved.map((m) => ({ id: m.id, type: m.type, label: m.display_label, brand: m.brand, last4: m.last4, isDefault: m.is_default })),
      selected: d.payment_methods.selected ? { type: d.payment_methods.selected.type, savedMethodId: d.payment_methods.selected.saved_method_id } : null,
    },
    payment: d.payment ? { status: d.payment.status, amountMinor: d.payment.amount.amount, currency: d.payment.currency, idempotencyKey: d.payment.idempotency_key, fingerprint: d.payment.pricing_fingerprint, expiresAt: d.payment.expires_at, checkoutVersion: d.payment.checkout_version } : null,
    issues: d.issues.map((i) => ({ code: i.code, message: i.message, recover: i.recover, details: i.details ?? {} })),
    changes: d.changes ?? [],
  }
}

/** Milliseconds until the checkout expires — measured from the server's clock, with this device only counting the time since. */
export function remainingMs(checkout: Pick<ServerCheckout, 'expiresAt' | 'serverTime' | 'receivedAtMs'>, nowMs: number = Date.now()): number {
  return Date.parse(checkout.expiresAt) - Date.parse(checkout.serverTime) - (nowMs - checkout.receivedAtMs)
}

/* ------------------------------------------------------------------ errors */
export type CheckoutErrorCode =
  | 'no_cart' | 'cart_empty' | 'cart_invalid' | 'cart_changed' | 'cart_expired' | 'item_unavailable' | 'restaurant_unavailable' | 'not_accepting'
  | 'pickup_required' | 'pickup_invalid' | 'pickup_full' | 'stale' | 'expired' | 'not_found' | 'payment_in_progress'
  | 'promotion' | 'legal' | 'payment_method' | 'unauthenticated' | 'rate_limited' | 'validation' | 'offline' | 'error'
export class CheckoutApiError extends Error {
  code: CheckoutErrorCode
  /** The backend's own error code (e.g. promotion_minimum_not_met). */
  serverCode: string
  details: Record<string, unknown>
  issues: CheckoutIssueView[]
  retryAfterSeconds: number | null
  constructor(code: CheckoutErrorCode, message: string, init: { serverCode?: string; details?: Record<string, unknown>; retryAfterSeconds?: number | null } = {}) {
    super(message); this.name = 'CheckoutApiError'; this.code = code; this.serverCode = init.serverCode ?? ''; this.details = init.details ?? {}; this.retryAfterSeconds = init.retryAfterSeconds ?? null
    const raw = (this.details as { issues?: CheckoutIssueDto[] }).issues
    this.issues = Array.isArray(raw) ? raw.map((i) => ({ code: i.code, message: i.message, recover: i.recover, details: i.details ?? {} })) : []
  }
  /** Where the customer goes to put it right. */
  get recover(): CheckoutIssueDto['recover'] | null { return this.issues[0]?.recover ?? null }
}
const CODES: Record<string, CheckoutErrorCode> = {
  cart_not_found: 'no_cart', cart_empty: 'cart_empty', cart_invalid: 'cart_invalid', cart_changed: 'cart_changed', cart_version_conflict: 'cart_changed', cart_expired: 'cart_expired', item_unavailable: 'item_unavailable',
  price_changed: 'cart_invalid', currency_mismatch: 'cart_invalid', restaurant_unavailable: 'restaurant_unavailable', market_unavailable: 'restaurant_unavailable', restaurant_not_accepting: 'not_accepting',
  pickup_required: 'pickup_required', pickup_invalid: 'pickup_invalid', pickup_changed: 'pickup_invalid', pickup_selection_expired: 'pickup_invalid', pickup_slot_full: 'pickup_full', pickup_slot_unavailable: 'pickup_full',
  checkout_stale: 'stale', checkout_expired: 'expired', checkout_not_found: 'not_found', checkout_not_ready: 'stale', payment_in_progress: 'payment_in_progress', cart_locked_for_payment: 'payment_in_progress',
  legal_acceptance_required: 'legal', payment_method_required: 'payment_method', payment_method_unavailable: 'payment_method', rate_limited: 'rate_limited', validation_failed: 'validation',
}
export function toCheckoutError(e: unknown): CheckoutApiError {
  if (e instanceof CheckoutApiError) return e
  if (e instanceof ApiError) {
    // Without a connection nothing about a price can be confirmed — an old total must not be taken for a current one.
    if (e.kind === 'network') return new CheckoutApiError('offline', 'Cannot reach FoodOnTheGo right now. Checkout needs a connection.')
    if (e.kind === 'unauthenticated') return new CheckoutApiError('unauthenticated', e.message, { serverCode: e.code })
    const code = e.code.startsWith('promotion_') ? 'promotion' : CODES[e.code] ?? (e.kind === 'rate_limited' ? 'rate_limited' : 'error')
    return new CheckoutApiError(code, e.message, { serverCode: e.code, details: e.details, retryAfterSeconds: e.retryAfterSeconds })
  }
  return new CheckoutApiError('error', 'Checkout could not be loaded. Please try again.')
}

/** What a refused promotion code means for the page. The backend's answer is deliberately coarse: it tells a customer nothing about campaigns. */
export function promoStatusOf(e: CheckoutApiError): PromoStatus {
  if (e.code === 'rate_limited') return 'rate_limited'
  switch (e.serverCode) {
    case 'promotion_expired': return 'expired'
    case 'promotion_minimum_not_met': return 'min_spend'
    case 'promotion_not_applicable': return 'not_eligible'
    case 'promotion_limit_reached': return 'limit_reached'
    case 'promotion_customer_limit_reached': return 'already_used'
    case 'promotion_not_found': return 'invalid'
    default: return 'unavailable'
  }
}

/* ------------------------------------------------------------------ gateway */
const RETRY_AFTER_MS = 700
const newKey = (operation: string) => `${operation}-${typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`}`
export type ConfirmInput = { fingerprint: string; documents: Array<{ type: string; version: string }>; paymentMethod: { type: string; savedMethodId?: string | null } | null; orderNote: string }

export class ApiCheckoutGateway {
  /**
   * The checkout for the caller's cart: a new one, or the live one when nothing changed. Safe to repeat — a retry
   * after a lost answer carries the same idempotency key and gets the first answer again.
   */
  async open(input: { promotionCode?: string | null; cartVersion?: number | null } = {}): Promise<ServerCheckout> {
    const body: Record<string, unknown> = {}
    if (input.promotionCode) body.promotion_code = input.promotionCode
    if (input.cartVersion !== null && input.cartVersion !== undefined) body.cart_version = input.cartVersion
    return this.write('/checkouts', body, newKey('cko'))
  }

  /** The checkout as it stands now: the backend judges it again on every read (stale, expired). */
  async show(id: string, signal?: AbortSignal): Promise<ServerCheckout> {
    try { return toCheckout((await api<{ checkout: CheckoutDto }>(`/checkouts/${encodeURIComponent(id)}`, { signal })).checkout) } catch (e) { throw toCheckoutError(e) }
  }

  async applyPromotion(id: string, code: string): Promise<ServerCheckout> {
    try { return toCheckout((await api<{ checkout: CheckoutDto }>(`/checkouts/${encodeURIComponent(id)}/promotion`, { method: 'POST', body: { code } })).checkout) } catch (e) { throw toCheckoutError(e) }
  }

  async removePromotion(id: string): Promise<ServerCheckout> {
    try { return toCheckout((await api<{ checkout: CheckoutDto }>(`/checkouts/${encodeURIComponent(id)}/promotion`, { method: 'DELETE' })).checkout) } catch (e) { throw toCheckoutError(e) }
  }

  /** Confirms the price the customer was shown, the documents they accepted and how they intend to pay. Charges nothing. */
  async confirm(id: string, input: ConfirmInput): Promise<ServerCheckout> {
    const body: Record<string, unknown> = { pricing_fingerprint: input.fingerprint, accepted_documents: input.documents.map((d) => ({ type: d.type, version: d.version })) }
    if (input.paymentMethod) body.payment_method = { type: input.paymentMethod.type, ...(input.paymentMethod.savedMethodId ? { saved_method_id: input.paymentMethod.savedMethodId } : {}) }
    if (input.orderNote.trim() !== '') body.order_note = input.orderNote
    return this.write(`/checkouts/${encodeURIComponent(id)}/confirm`, body, newKey('ckc'))
  }

  async cancel(id: string): Promise<void> {
    try { await api<{ checkout: CheckoutDto }>(`/checkouts/${encodeURIComponent(id)}/cancel`, { method: 'POST' }) } catch (e) { throw toCheckoutError(e) }
  }

  private async write(path: string, body: Record<string, unknown>, key: string): Promise<ServerCheckout> {
    const call = () => api<{ checkout: CheckoutDto }>(path, { method: 'POST', body, idempotencyKey: key })
    try {
      try { return toCheckout((await call()).checkout) } catch (e) {
        // A lost connection may have lost only the ANSWER: ask once more with the same key — the backend replays it.
        if (!(e instanceof ApiError) || e.kind !== 'network') throw e
        await new Promise((resolve) => setTimeout(resolve, RETRY_AFTER_MS))
        return toCheckout((await call()).checkout)
      }
    } catch (e) { throw toCheckoutError(e) }
  }
}

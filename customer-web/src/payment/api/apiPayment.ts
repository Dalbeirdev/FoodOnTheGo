import { ApiError, api } from '../../api/client'

/**
 * The backend's payment attempts (Module 30), as the web client uses them.
 *
 * What this file never does: send an amount, a currency or a status. A payment is started for a confirmed checkout
 * by naming the price the customer saw (its fingerprint); the amount is the backend's. And what the provider's
 * interface tells the browser is REPORTED to the backend, which verifies it — the browser never decides that a
 * payment succeeded.
 */

export type PaymentStatusDto = 'CREATED' | 'PROVIDER_READY' | 'PROCESSING' | 'PENDING' | 'AUTHORIZED' | 'VERIFIED' | 'FAILED' | 'CANCELLED' | 'EXPIRED' | 'UNKNOWN' | 'REVIEW_REQUIRED'
export type PaymentNextAction = 'OPEN_PROVIDER' | 'WAIT' | 'REFRESH_STATUS' | 'RETRY_PAYMENT' | 'SELECT_ANOTHER_METHOD' | 'RETURN_TO_CHECKOUT' | 'CONTACT_SUPPORT' | 'PROCEED_TO_ORDER'
export type FulfillmentStatus = 'NOT_APPLICABLE' | 'ORDER_PENDING' | 'RECOVERY_REQUIRED' | 'ORDER_CREATED'
type MoneyDto = { amount: number; currency: string }

export type ProviderSessionDto = {
  provider: string; mode: 'TEST' | 'LIVE' | 'FAKE'; key_id: string | null; order_reference: string; amount: MoneyDto
  name: string; description: string; theme_color: string; checkout_script: string | null
  prefill: { name: string | null; contact: string; email: string | null }
  preferred_method: string | null; methods: string[] | null; timeout_seconds: number
}
export type PaymentDto = {
  id: string; checkout_id: string; attempt_number: number; status: PaymentStatusDto; amount: MoneyDto
  provider: { name: string; mode: 'TEST' | 'LIVE' | 'FAKE' }
  requested_method_type: string | null
  method: { type: string; summary: Record<string, string | boolean | null> } | null
  failure: { category: string; code: string | null; message: string; retryable: boolean } | null
  message: string | null
  next_action: PaymentNextAction; retry_after_seconds: number | null
  provider_session: ProviderSessionDto | null
  /** What became of a verified payment (Module 31): the order it became, once the backend created it. */
  fulfillment: { status: FulfillmentStatus; order_id: string | null; order_number: string | null }
  support_reference: string
  expires_at: string | null; server_time: string; created_at: string | null; updated_at: string | null; verified_at: string | null
}

/** What the provider's own interface needs to open. Only a public key id — never a secret. */
export type ProviderSession = {
  provider: string; mode: 'TEST' | 'LIVE' | 'FAKE'; keyId: string | null; orderReference: string; amountMinor: number; currency: string
  name: string; description: string; themeColor: string; checkoutScript: string | null
  prefill: { name: string | null; contact: string; email: string | null }
  preferredMethod: string | null; methods: string[] | null; timeoutSeconds: number
}
export type ServerPayment = {
  id: string; checkoutId: string; attemptNumber: number; status: PaymentStatusDto
  amountMinor: number; currency: string
  provider: string; providerMode: 'TEST' | 'LIVE' | 'FAKE'
  method: { type: string; summary: Record<string, string | boolean | null> } | null
  failure: { category: string; code: string | null; message: string; retryable: boolean } | null
  message: string | null
  nextAction: PaymentNextAction; retryAfterSeconds: number | null
  session: ProviderSession | null
  fulfillment: FulfillmentStatus
  /** The order the payment became — null until the backend created it. A client waits for it after VERIFIED. */
  orderNumber: string | null; orderId: string | null
  supportReference: string
  expiresAt: string | null; verifiedAt: string | null
}

export function toPayment(d: PaymentDto): ServerPayment {
  const s = d.provider_session
  return {
    id: d.id, checkoutId: d.checkout_id, attemptNumber: d.attempt_number, status: d.status,
    amountMinor: d.amount.amount, currency: d.amount.currency,
    provider: d.provider.name, providerMode: d.provider.mode,
    method: d.method, failure: d.failure, message: d.message,
    nextAction: d.next_action, retryAfterSeconds: d.retry_after_seconds,
    session: s === null ? null : {
      provider: s.provider, mode: s.mode, keyId: s.key_id, orderReference: s.order_reference, amountMinor: s.amount.amount, currency: s.amount.currency,
      name: s.name, description: s.description, themeColor: s.theme_color, checkoutScript: s.checkout_script, prefill: s.prefill,
      preferredMethod: s.preferred_method, methods: s.methods, timeoutSeconds: s.timeout_seconds,
    },
    fulfillment: d.fulfillment.status, orderNumber: d.fulfillment.order_number ?? null, orderId: d.fulfillment.order_id ?? null, supportReference: d.support_reference, expiresAt: d.expires_at, verifiedAt: d.verified_at,
  }
}

/** A payment that may still end in money: nothing new may be started beside it. */
export const isUnsettled = (s: PaymentStatusDto): boolean => s === 'CREATED' || s === 'PROCESSING' || s === 'PENDING' || s === 'AUTHORIZED' || s === 'UNKNOWN'
export const endedUnpaid = (s: PaymentStatusDto): boolean => s === 'FAILED' || s === 'CANCELLED' || s === 'EXPIRED'

/** What the provider's interface told the browser. A report for the backend to verify — never a result. */
export type ProviderReport =
  | { outcome: 'success'; reference: string; signature: string }
  | { outcome: 'failed'; error?: { code?: string | null; reason?: string | null } }
  | { outcome: 'cancelled' }

export type PaymentErrorCode =
  | 'offline' | 'unauthenticated' | 'not_found' | 'rate_limited'
  /** The checkout cannot be paid as it stands: go back and let it be priced again. */
  | 'checkout_stale' | 'checkout_expired' | 'window_closed' | 'attempt_limit' | 'not_required'
  /** Nothing may be started: a payment is verified already, or money may be on its way. */
  | 'already_verified' | 'in_progress'
  /** Payments cannot be taken right now. Nothing was charged. */
  | 'provider_unavailable' | 'initialization_unknown'
  | 'signature_invalid' | 'method_unavailable' | 'error'

export class PaymentApiError extends Error {
  code: PaymentErrorCode
  serverCode: string
  /** The attempt the refusal is about — e.g. the one that is already in progress. */
  paymentId: string | null
  nextAction: PaymentNextAction | null
  retryAfterSeconds: number | null
  constructor(code: PaymentErrorCode, message: string, serverCode = '', details: Record<string, unknown> = {}, retryAfterSeconds: number | null = null) {
    super(message)
    this.code = code; this.serverCode = serverCode
    this.paymentId = typeof details.payment_id === 'string' ? details.payment_id : null
    this.nextAction = typeof details.next_action === 'string' ? details.next_action as PaymentNextAction : null
    this.retryAfterSeconds = retryAfterSeconds
  }
}

const CODES: Record<string, PaymentErrorCode> = {
  checkout_stale: 'checkout_stale', checkout_not_ready: 'checkout_stale', checkout_not_found: 'checkout_stale', checkout_expired: 'checkout_expired',
  payment_window_closed: 'window_closed', payment_attempt_limit_reached: 'attempt_limit', payment_not_required: 'not_required',
  payment_already_verified: 'already_verified', payment_already_in_progress: 'in_progress', payment_in_progress: 'in_progress',
  payment_provider_unavailable: 'provider_unavailable', payment_initialization_failed: 'provider_unavailable', payment_initialization_unknown: 'initialization_unknown',
  payment_signature_invalid: 'signature_invalid', payment_method_unavailable: 'method_unavailable', payment_not_found: 'not_found', payment_not_ready: 'in_progress',
}

export function toPaymentError(e: unknown): PaymentApiError {
  if (e instanceof PaymentApiError) return e
  if (e instanceof ApiError) {
    const code: PaymentErrorCode = e.kind === 'network' ? 'offline' : e.kind === 'unauthenticated' ? 'unauthenticated' : e.kind === 'rate_limited' ? 'rate_limited' : CODES[e.code] ?? (e.kind === 'not_found' ? 'not_found' : 'error')
    return new PaymentApiError(code, e.message, e.code, e.details, e.retryAfterSeconds)
  }
  return new PaymentApiError('error', e instanceof Error ? e.message : 'error')
}

export type SimulationScenario = 'SUCCESS' | 'AUTHORIZED' | 'PENDING' | 'FAILED' | 'CANCELLED' | 'INVALID_SIGNATURE' | 'AMOUNT_MISMATCH' | 'LATE_SUCCESS' | 'LATE_FAILURE' | 'PROVIDER_DOWN' | 'PROVIDER_UP'
type SimulationDto = { simulation: { client: null | { outcome: 'success' | 'failed' | 'cancelled'; provider_payment_reference?: string; provider_signature?: string; provider_error?: { code?: string | null; reason?: string | null } } } }

const newKey = (): string => `pay-${crypto.randomUUID()}`
const wait = (ms: number) => new Promise<void>((r) => setTimeout(r, ms))

export class ApiPaymentGateway {
  /**
   * Starts paying a confirmed checkout — or gets the attempt that is already open for it. The body names the price
   * the customer saw and, at most, how they mean to pay. A lost connection is tried once more with the SAME key, so
   * a request that did arrive is answered again and never creates a second attempt.
   */
  async start(checkoutId: string, pricingFingerprint: string, methodType?: string | null): Promise<ServerPayment> {
    const body: Record<string, unknown> = { pricing_fingerprint: pricingFingerprint }
    if (methodType) body.payment_method = { type: methodType }
    const key = newKey()
    const call = () => api<{ payment: PaymentDto }>(`/checkouts/${encodeURIComponent(checkoutId)}/payment-attempts`, { method: 'POST', body, idempotencyKey: key })
    try {
      return toPayment((await call()).payment)
    } catch (first) {
      if (!(first instanceof ApiError) || first.kind !== 'network') throw toPaymentError(first)
      await wait(700)
      try { return toPayment((await call()).payment) } catch (second) { throw toPaymentError(second) }
    }
  }

  async show(paymentId: string): Promise<ServerPayment> {
    try { return toPayment((await api<{ payment: PaymentDto }>(`/payments/${encodeURIComponent(paymentId)}`)).payment) } catch (e) { throw toPaymentError(e) }
  }

  /** Reports how the provider's interface ended. The answer is the backend's judgement — not an echo of the report. */
  async complete(paymentId: string, report: ProviderReport): Promise<ServerPayment> {
    const body: Record<string, unknown> = report.outcome === 'success'
      ? { outcome: 'success', provider_payment_reference: report.reference, provider_signature: report.signature }
      : report.outcome === 'failed' && report.error && (report.error.code || report.error.reason)
        ? { outcome: 'failed', provider_error: { code: report.error.code ?? null, reason: report.error.reason ?? null } }
        : { outcome: report.outcome }
    try { return toPayment((await api<{ payment: PaymentDto }>(`/payments/${encodeURIComponent(paymentId)}/complete`, { method: 'POST', body })).payment) } catch (e) { throw toPaymentError(e) }
  }

  /**
   * DEVELOPMENT ONLY (fake provider): plays the customer in the provider's interface. Returns what that interface
   * would hand the browser — a report to send with complete() — or null when the browser hears nothing.
   */
  async simulate(paymentId: string, scenario: SimulationScenario, webhook: 'none' | 'deliver' | 'first' | 'only' | 'duplicate' | 'out_of_order' = 'none'): Promise<ProviderReport | null> {
    try {
      const { simulation } = await api<SimulationDto>(`/dev/payments/${encodeURIComponent(paymentId)}/simulate`, { method: 'POST', body: { scenario, webhook } })
      const c = simulation.client
      if (c === null) return null
      if (c.outcome === 'success') return { outcome: 'success', reference: c.provider_payment_reference ?? '', signature: c.provider_signature ?? '' }
      return c.outcome === 'failed' ? { outcome: 'failed', error: c.provider_error } : { outcome: 'cancelled' }
    } catch (e) { throw toPaymentError(e) }
  }
}

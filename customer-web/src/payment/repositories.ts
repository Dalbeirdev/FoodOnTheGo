/**
 * Payment domain (Module 12) — provider-neutral contracts.
 *
 * Rules carried permanently:
 *  - FoodOnTheGo never stores card numbers, CVV, UPI PINs, bank passwords or OTPs; the provider-hosted / SDK
 *    experience collects them. Nothing in this module models such fields.
 *  - The frontend amount is never authoritative. `amountMinor` on an attempt is the validated display total that
 *    the customer saw; the server creates the provider payment from ITS OWN pricing of the checkout reference.
 *  - A client-side success is not an order confirmation. VERIFIED is simulated here (development only); the real
 *    verification happens server-side (signature / status API / webhooks) in the payment backend.
 *  - No provider secrets ever exist in this code base.
 */
import type { PaymentCapabilityContext, PaymentMethodOption } from '../checkout/repositories'

export type PaymentStatus =
  | 'IDLE' | 'PREPARING' | 'READY' | 'OPENING_PROVIDER' | 'PROCESSING' | 'PENDING'
  | 'SUCCESS_CLIENT_SIDE' | 'VERIFYING' | 'VERIFIED' | 'FAILED' | 'CANCELLED' | 'EXPIRED' | 'UNKNOWN' | 'ERROR'

/** Future refund-related statuses are recognised by the model even though refunds are not built in Module 12. */
export type RefundStatus = 'NONE' | 'REFUND_PENDING' | 'PARTIALLY_REFUNDED' | 'REFUNDED' | 'REFUND_FAILED'

export type PaymentEvent = { at: string; status: PaymentStatus; note?: string }

export type PaymentAttempt = {
  /** Public, non-sequential reference safe to show to the customer. */
  publicId: string
  /** CheckoutRequest.idempotencyKey — one checkout may have several attempts (retry), never several charges. */
  checkoutReference: string
  /** Hash of the validated checkout snapshot (cart, pickup, promo, displayed total, currency). A changed checkout
   *  invalidates the attempt (spec §34–35). */
  checkoutSnapshot: string
  provider: string
  providerPaymentReference: string | null
  /** ISO 4217 — one explicit currency per attempt, no silent FX. */
  currency: string
  /** Display total the customer saw (minor units). Not authoritative. */
  amountMinor: number
  status: PaymentStatus
  refundStatus: RefundStatus
  methodId: string
  methodType: string
  customerId: string
  restaurantId: string
  failureReason: string | null
  expiresAt: string | null
  createdAt: string
  updatedAt: string
  /** Append-only history (spec §66) — never overwritten with just the latest value. */
  events: PaymentEvent[]
}

export type ClientOutcome = 'success' | 'failed' | 'cancelled' | 'pending' | 'unknown'
export type ClientPaymentResult = { outcome: ClientOutcome; providerPaymentReference?: string | null; reason?: string }

export type PaymentInit = { providerPaymentReference: string; expiresAt: string | null }

/** Provider-facing experience (Razorpay, another PSP, or the development mock). No secrets, no card data. */
export interface PaymentProvider {
  readonly id: string
  readonly displayName: string
  getAvailablePaymentMethods(ctx: PaymentCapabilityContext): Promise<PaymentMethodOption[]>
  /** Creates the provider-side payment for an attempt (server-owned later; the mock does it locally). */
  initializePayment(attempt: PaymentAttempt): Promise<PaymentInit>
  /** Opens the hosted / SDK experience and resolves with the client-side outcome. */
  openPaymentExperience(attempt: PaymentAttempt, signal?: AbortSignal): Promise<ClientPaymentResult>
  /** Client-side status lookup used for recovery (real status comes from the backend later). */
  getClientPaymentResult(attempt: PaymentAttempt): Promise<ClientPaymentResult>
  cancelPayment(attempt: PaymentAttempt): Promise<void>
}

export interface PaymentProviderResolver {
  resolve(ctx: { countryCode: string; currency: string; restaurantId?: string }): PaymentProvider
}

export type CreateAttemptInput = Pick<PaymentAttempt, 'checkoutReference' | 'checkoutSnapshot' | 'provider' | 'currency' | 'amountMinor' | 'methodId' | 'methodType' | 'customerId' | 'restaurantId'>

export interface PaymentRepository {
  createAttempt(input: CreateAttemptInput): Promise<PaymentAttempt>
  getAttempt(publicId: string): Promise<PaymentAttempt | null>
  listForCheckout(checkoutReference: string): Promise<PaymentAttempt[]>
  transition(publicId: string, status: PaymentStatus, patch?: Partial<Pick<PaymentAttempt, 'providerPaymentReference' | 'failureReason' | 'expiresAt' | 'methodId' | 'methodType'>>, note?: string): Promise<PaymentAttempt>
}

export type VerificationResult = { status: 'VERIFIED' | 'PENDING' | 'FAILED' | 'UNKNOWN'; reason?: string; developmentOnly: true }

/** Development stand-in for the server verification step. Never ships as the production truth. */
export interface PaymentVerificationService {
  verify(attempt: PaymentAttempt): Promise<VerificationResult>
}

export const IN_FLIGHT: ReadonlySet<PaymentStatus> = new Set(['PREPARING', 'OPENING_PROVIDER', 'PROCESSING', 'VERIFYING'])
export const TERMINAL: ReadonlySet<PaymentStatus> = new Set(['VERIFIED', 'FAILED', 'CANCELLED', 'EXPIRED'])
export const NEEDS_STATUS_CHECK: ReadonlySet<PaymentStatus> = new Set(['PENDING', 'UNKNOWN'])

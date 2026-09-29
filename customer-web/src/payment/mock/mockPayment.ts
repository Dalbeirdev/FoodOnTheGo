/**
 * Development payment stack (Module 12). Deterministic — never random.
 *
 * Controls (sessionStorage):
 *   fotg.mock.payment          = success | failure | cancelled | pending | timeout | unknown   (default success)
 *   fotg.mock.payment.resolve  = verified | pending | failed | unknown                          (status check after pending / unknown; default verified)
 *   fotg.mock.payment.verify   = verified | pending | failed                                    (verification after a client success; default verified)
 *   fotg.mock.offline          = 1 → the payment cannot start (checkout already blocks it)
 *
 * No provider SDK, no keys, no secrets. Attempts live in sessionStorage so a refresh can recover them (spec §46, §50).
 */
import { MockPaymentMethodRepository } from '../../checkout/mock/mockCheckout'
import type { PaymentCapabilityContext, PaymentMethodOption } from '../../checkout/repositories'
import type { ClientPaymentResult, CreateAttemptInput, PaymentAttempt, PaymentInit, PaymentProvider, PaymentProviderResolver, PaymentRepository, PaymentStatus, PaymentVerificationService, VerificationResult } from '../repositories'

export type MockOutcome = 'success' | 'failure' | 'cancelled' | 'pending' | 'timeout' | 'unknown'
export const OUTCOME_KEY = 'fotg.mock.payment'
export const RESOLVE_KEY = 'fotg.mock.payment.resolve'
export const VERIFY_KEY = 'fotg.mock.payment.verify'
const ATTEMPTS_KEY = 'fotg.payment.attempts'

const read = (k: string) => { try { return sessionStorage.getItem(k) } catch { return null } }
export const readMockOutcome = (): MockOutcome => { const v = read(OUTCOME_KEY); return (v === 'failure' || v === 'cancelled' || v === 'pending' || v === 'timeout' || v === 'unknown') ? v : 'success' }
export const setMockOutcome = (v: MockOutcome) => { try { sessionStorage.setItem(OUTCOME_KEY, v) } catch { /* ignore */ } }

let latency = 600
/** Tests set this to 0 for instant mock provider responses. */
export const setMockPaymentLatency = (ms: number) => { latency = ms }
const wait = (ms: number, signal?: AbortSignal) => new Promise<void>((resolve, reject) => {
  if (ms <= 0) return resolve()
  const t = setTimeout(resolve, ms)
  signal?.addEventListener('abort', () => { clearTimeout(t); reject(new DOMException('cancelled', 'AbortError')) }, { once: true })
})

const rand = (n: number) => { const a = new Uint8Array(n); crypto.getRandomValues(a); return Array.from(a, (b) => b.toString(16).padStart(2, '0')).join('') }

/** Development provider. `id` mimics a market provider but there is no SDK, no keys and no network. */
export class MockPaymentProvider implements PaymentProvider {
  readonly id: string
  readonly displayName: string
  private methods = new MockPaymentMethodRepository()
  constructor(id = 'mock-razorpay', displayName = 'Razorpay (development sandbox)') { this.id = id; this.displayName = displayName }
  async getAvailablePaymentMethods(ctx: PaymentCapabilityContext): Promise<PaymentMethodOption[]> { return this.methods.getAvailableMethods(ctx) }
  async initializePayment(attempt: PaymentAttempt): Promise<PaymentInit> {
    await wait(latency / 2)
    return { providerPaymentReference: `${this.id}_${attempt.publicId.slice(-8)}_${rand(3)}`, expiresAt: null }
  }
  async openPaymentExperience(attempt: PaymentAttempt, signal?: AbortSignal): Promise<ClientPaymentResult> {
    const outcome = readMockOutcome()
    if (outcome === 'timeout') { await wait(60 * 60 * 1000, signal); return { outcome: 'unknown', reason: 'timeout' } } // never resolves on its own — the controller times out
    await wait(latency, signal)
    switch (outcome) {
      case 'failure': return { outcome: 'failed', providerPaymentReference: attempt.providerPaymentReference, reason: 'declined' }
      case 'cancelled': return { outcome: 'cancelled', providerPaymentReference: attempt.providerPaymentReference }
      case 'pending': return { outcome: 'pending', providerPaymentReference: attempt.providerPaymentReference, reason: 'awaiting_confirmation' }
      case 'unknown': return { outcome: 'unknown', providerPaymentReference: attempt.providerPaymentReference, reason: 'connection_lost' }
      default: return { outcome: 'success', providerPaymentReference: attempt.providerPaymentReference }
    }
  }
  async getClientPaymentResult(attempt: PaymentAttempt): Promise<ClientPaymentResult> {
    await wait(latency / 2)
    const v = read(RESOLVE_KEY)
    if (v === 'failed') return { outcome: 'failed', providerPaymentReference: attempt.providerPaymentReference, reason: 'declined' }
    if (v === 'pending') return { outcome: 'pending', providerPaymentReference: attempt.providerPaymentReference }
    if (v === 'unknown') return { outcome: 'unknown', providerPaymentReference: attempt.providerPaymentReference }
    return { outcome: 'success', providerPaymentReference: attempt.providerPaymentReference }
  }
  async cancelPayment(): Promise<void> { await wait(latency / 4) }
}

/** Market → provider. Configuration-driven later (market, merchant entity, currency); fixtures now. */
export class MockPaymentProviderResolver implements PaymentProviderResolver {
  private providers = new Map<string, PaymentProvider>()
  resolve(ctx: { countryCode: string; currency: string }): PaymentProvider {
    const id = ctx.countryCode === 'IN' ? 'mock-razorpay' : 'mock-provider'
    let p = this.providers.get(id)
    if (!p) { p = new MockPaymentProvider(id, id === 'mock-razorpay' ? 'Razorpay (development sandbox)' : 'Payment provider (development sandbox)'); this.providers.set(id, p) }
    return p
  }
}

/** Attempts + append-only history persisted in sessionStorage (recovery after refresh). */
export class MockPaymentRepository implements PaymentRepository {
  private load(): PaymentAttempt[] { try { const raw = sessionStorage.getItem(ATTEMPTS_KEY); return raw ? (JSON.parse(raw) as PaymentAttempt[]) : [] } catch { return [] } }
  private save(list: PaymentAttempt[]) { try { sessionStorage.setItem(ATTEMPTS_KEY, JSON.stringify(list.slice(-20))) } catch { /* ignore */ } }
  async createAttempt(input: CreateAttemptInput): Promise<PaymentAttempt> {
    const now = new Date().toISOString()
    const a: PaymentAttempt = { ...input, publicId: `pay_dev_${rand(6)}`, providerPaymentReference: null, status: 'PREPARING', refundStatus: 'NONE', failureReason: null, expiresAt: null, createdAt: now, updatedAt: now, events: [{ at: now, status: 'PREPARING', note: 'created' }] }
    const list = this.load(); list.push(a); this.save(list)
    return a
  }
  async getAttempt(publicId: string) { return this.load().find((a) => a.publicId === publicId) ?? null }
  async listForCheckout(ref: string) { return this.load().filter((a) => a.checkoutReference === ref) }
  async transition(publicId: string, status: PaymentStatus, patch = {}, note?: string): Promise<PaymentAttempt> {
    const list = this.load(); const i = list.findIndex((a) => a.publicId === publicId)
    if (i < 0) throw new Error('payment attempt not found')
    const now = new Date().toISOString()
    const next: PaymentAttempt = { ...list[i], ...patch, status, updatedAt: now, events: [...list[i].events, { at: now, status, note }] }
    list[i] = next; this.save(list)
    return next
  }
}

/** DEVELOPMENT ONLY — simulates the server verification. The production verification is server-side (CF-143). */
export class MockPaymentVerificationService implements PaymentVerificationService {
  async verify(): Promise<VerificationResult> {
    await wait(latency)
    const v = read(VERIFY_KEY)
    if (v === 'pending') return { status: 'PENDING', developmentOnly: true }
    if (v === 'failed') return { status: 'FAILED', reason: 'verification_failed', developmentOnly: true }
    return { status: 'VERIFIED', developmentOnly: true }
  }
}

/** How long the controller waits for the provider experience before declaring the status UNKNOWN (mock config). */
export const MOCK_PROVIDER_TIMEOUT_MS = 8000

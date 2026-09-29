/**
 * PaymentStateController (Module 12) — the single owner of the payment state machine on web.
 *
 * IDLE → PREPARING → READY → OPENING_PROVIDER → PROCESSING → SUCCESS_CLIENT_SIDE → VERIFYING → VERIFIED
 *                                                   ↘ FAILED / CANCELLED / PENDING / UNKNOWN / EXPIRED / ERROR
 * Single initiation: `pay()` is ignored unless the status is READY and no call is in flight (double click / tap).
 * Checkout snapshot: an attempt belongs to one CheckoutRequest + snapshot; a changed checkout expires it.
 * Recovery: the current attempt id is persisted; after a refresh an in-flight attempt becomes UNKNOWN and the
 * customer is asked to check the status instead of paying again.
 */
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useCheckout } from '../checkout/CheckoutContext'
import type { CheckoutRequest, PaymentMethodOption } from '../checkout/repositories'
import { MockPaymentProviderResolver, MockPaymentRepository, MockPaymentVerificationService, MOCK_PROVIDER_TIMEOUT_MS } from './mock/mockPayment'
import { IN_FLIGHT, type ClientPaymentResult, type PaymentAttempt, type PaymentProvider, type PaymentProviderResolver, type PaymentRepository, type PaymentStatus, type PaymentVerificationService } from './repositories'

export type PaymentApi = {
  status: PaymentStatus
  attempt: PaymentAttempt | null
  provider: PaymentProvider | null
  methods: PaymentMethodOption[]
  method: PaymentMethodOption | null
  error: string | null
  /** True while the controller is busy — buttons must be disabled. */
  busy: boolean
  prepare: (request: CheckoutRequest, snapshot: string, ctx: { countryCode: string; restaurantId: string }) => Promise<void>
  pay: () => Promise<void>
  cancel: () => Promise<void>
  retry: () => Promise<void>
  changeMethod: (methodId: string) => Promise<void>
  checkStatus: () => Promise<void>
  /** Clears the controller for a brand-new checkout (used after the order handoff / when the request changes). */
  reset: () => void
}

const Ctx = createContext<PaymentApi | null>(null)
const CURRENT_KEY = 'fotg.payment.current'
const defaultResolver = new MockPaymentProviderResolver()
const defaultRepo = new MockPaymentRepository()
const defaultVerifier = new MockPaymentVerificationService()

export function PaymentProviderContext({ children, resolver = defaultResolver, repository = defaultRepo, verifier = defaultVerifier, providerTimeoutMs = MOCK_PROVIDER_TIMEOUT_MS }: { children: ReactNode; resolver?: PaymentProviderResolver; repository?: PaymentRepository; verifier?: PaymentVerificationService; providerTimeoutMs?: number }) {
  const co = useCheckout()
  const [status, setStatus] = useState<PaymentStatus>('IDLE')
  const [attempt, setAttempt] = useState<PaymentAttempt | null>(null)
  const [provider, setProvider] = useState<PaymentProvider | null>(null)
  const [methods, setMethods] = useState<PaymentMethodOption[]>([])
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const inFlight = useRef(false)
  const abortRef = useRef<AbortController | null>(null)
  const ctxRef = useRef<{ request: CheckoutRequest; snapshot: string; countryCode: string; restaurantId: string } | null>(null)

  const commit = useCallback(async (publicId: string, s: PaymentStatus, patch?: Parameters<PaymentRepository['transition']>[2], note?: string) => {
    const a = await repository.transition(publicId, s, patch, note); setAttempt(a); setStatus(s); return a
  }, [repository])

  const persistCurrent = (id: string | null) => { try { if (id) sessionStorage.setItem(CURRENT_KEY, id); else sessionStorage.removeItem(CURRENT_KEY) } catch { /* ignore */ } }

  const createAttempt = useCallback(async (p: PaymentProvider, request: CheckoutRequest, snapshot: string, ctx: { countryCode: string; restaurantId: string }, methodId: string, list: PaymentMethodOption[]) => {
    const m = list.find((x) => x.id === methodId) ?? list.find((x) => x.enabled)
    if (!m) throw new Error('no_payment_method')
    setStatus('PREPARING'); setError(null)
    const a = await repository.createAttempt({ checkoutReference: request.idempotencyKey, checkoutSnapshot: snapshot, provider: p.id, currency: request.currency, amountMinor: request.displayedTotalMinor, methodId: m.id, methodType: m.type, customerId: request.customerId, restaurantId: ctx.restaurantId })
    persistCurrent(a.publicId); setAttempt(a)
    const init = await p.initializePayment(a)
    return commit(a.publicId, 'READY', { providerPaymentReference: init.providerPaymentReference, expiresAt: init.expiresAt }, 'provider payment initialised (mock)')
  }, [repository, commit])

  /** Called by the payment page with the CheckoutRequest + validated snapshot. Reuses / recovers / expires attempts. */
  const prepare = useCallback(async (request: CheckoutRequest, snapshot: string, ctx: { countryCode: string; restaurantId: string }) => {
    if (inFlight.current) return
    inFlight.current = true; setBusy(true)
    try {
      ctxRef.current = { request, snapshot, ...ctx }
      const p = resolver.resolve({ countryCode: ctx.countryCode, currency: request.currency, restaurantId: ctx.restaurantId })
      setProvider(p)
      const list = await p.getAvailablePaymentMethods({ countryCode: ctx.countryCode, currency: request.currency })
      setMethods(list)
      // Recover the current attempt (refresh / return from provider) or expire it when the checkout changed.
      let currentId: string | null = null; try { currentId = sessionStorage.getItem(CURRENT_KEY) } catch { /* ignore */ }
      const current = currentId ? await repository.getAttempt(currentId) : null
      if (current && current.checkoutReference === request.idempotencyKey) {
        if (current.checkoutSnapshot !== snapshot && !['VERIFIED'].includes(current.status)) {
          await commit(current.publicId, 'EXPIRED', undefined, 'checkout changed — attempt invalidated')
        } else if (current.status === 'OPENING_PROVIDER' || current.status === 'PROCESSING') {
          await commit(current.publicId, 'UNKNOWN', undefined, 'recovered after interruption — status must be checked'); return
        } else if (current.status === 'VERIFYING' || current.status === 'SUCCESS_CLIENT_SIDE') {
          setAttempt(current); setStatus('VERIFYING')
          const v = await verifier.verify(current)
          await commit(current.publicId, v.status, { failureReason: v.reason ?? null }, 'verification (development mock)'); return
        } else if (current.status === 'PREPARING') {
          await commit(current.publicId, 'ERROR', { failureReason: 'interrupted' }, 'interrupted while preparing')
        } else { setAttempt(current); setStatus(current.status); return }
      } else if (current && current.checkoutReference !== request.idempotencyKey && !['VERIFIED', 'FAILED', 'CANCELLED', 'EXPIRED'].includes(current.status)) {
        await repository.transition(current.publicId, 'EXPIRED', undefined, 'superseded by a new checkout')
      }
      await createAttempt(p, request, snapshot, ctx, request.paymentMethodId, list)
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e)); setStatus('ERROR')
    } finally { inFlight.current = false; setBusy(false) }
  }, [resolver, repository, verifier, commit, createAttempt])

  const applyResult = useCallback(async (a: PaymentAttempt, r: ClientPaymentResult) => {
    const patch = { providerPaymentReference: r.providerPaymentReference ?? a.providerPaymentReference, failureReason: r.reason ?? null }
    switch (r.outcome) {
      case 'success': {
        await commit(a.publicId, 'SUCCESS_CLIENT_SIDE', patch, 'provider reported success (client side — not final)')
        await commit(a.publicId, 'VERIFYING', undefined, 'verification requested')
        const v = await verifier.verify(a)
        await commit(a.publicId, v.status, { failureReason: v.reason ?? null }, 'verification (development mock)')
        return
      }
      case 'failed': await commit(a.publicId, 'FAILED', patch, 'provider reported failure'); return
      case 'cancelled': await commit(a.publicId, 'CANCELLED', patch, 'customer cancelled'); return
      case 'pending': await commit(a.publicId, 'PENDING', patch, 'provider reported pending'); return
      default: await commit(a.publicId, 'UNKNOWN', patch, 'status unknown (connection lost / timeout)')
    }
  }, [commit, verifier])

  /** Single initiation. Ignored unless READY and nothing is in flight. */
  const pay = useCallback(async () => {
    const a = attempt; const p = provider
    if (!a || !p || status !== 'READY' || inFlight.current) return
    if (!co.online) { setError('offline'); return }
    inFlight.current = true; setBusy(true); setError(null)
    const ac = new AbortController(); abortRef.current = ac
    try {
      await commit(a.publicId, 'OPENING_PROVIDER', undefined, 'opening provider experience')
      await commit(a.publicId, 'PROCESSING', undefined, 'waiting for the customer to pay')
      const timeout = new Promise<ClientPaymentResult>((resolve) => { const t = setTimeout(() => resolve({ outcome: 'unknown', reason: 'timeout' }), providerTimeoutMs); ac.signal.addEventListener('abort', () => clearTimeout(t), { once: true }) })
      const offline = new Promise<ClientPaymentResult>((resolve) => { const h = () => resolve({ outcome: 'unknown', reason: 'connection_lost' }); window.addEventListener('offline', h, { once: true }); ac.signal.addEventListener('abort', () => window.removeEventListener('offline', h), { once: true }) })
      const r = await Promise.race([p.openPaymentExperience(a, ac.signal), timeout, offline]).catch((e: unknown) => (e instanceof DOMException && e.name === 'AbortError' ? { outcome: 'cancelled' as const } : { outcome: 'unknown' as const, reason: 'error' }))
      ac.abort()
      await applyResult(a, r)
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e)); if (attempt) await commit(attempt.publicId, 'ERROR', { failureReason: 'error' }, 'unexpected error')
    } finally { inFlight.current = false; setBusy(false); abortRef.current = null }
  }, [attempt, provider, status, co.online, commit, applyResult, providerTimeoutMs])

  /** Customer closes the (mock) provider experience while processing. */
  const cancel = useCallback(async () => {
    const a = attempt; const p = provider
    if (!a || !p || (status !== 'PROCESSING' && status !== 'OPENING_PROVIDER')) return
    await p.cancelPayment(a); abortRef.current?.abort()
  }, [attempt, provider, status])

  /** After FAILED / CANCELLED / EXPIRED / ERROR: a controlled new attempt for the same checkout, then open the provider. */
  const retry = useCallback(async () => {
    const c = ctxRef.current; const p = provider; const a = attempt
    if (!c || !p || inFlight.current || !a || !['FAILED', 'CANCELLED', 'EXPIRED', 'ERROR'].includes(status)) return
    inFlight.current = true; setBusy(true)
    try { await createAttempt(p, c.request, c.snapshot, { countryCode: c.countryCode, restaurantId: c.restaurantId }, a.methodId, methods) }
    catch (e) { setError(e instanceof Error ? e.message : String(e)); setStatus('ERROR') }
    finally { inFlight.current = false; setBusy(false) }
  }, [provider, attempt, status, methods, createAttempt])

  /** Change the method without losing the checkout: READY attempts are updated; used-up attempts get a fresh one. */
  const changeMethod = useCallback(async (methodId: string) => {
    const c = ctxRef.current; const p = provider; const a = attempt
    const m = methods.find((x) => x.id === methodId && x.enabled)
    if (!c || !p || !a || !m || inFlight.current || IN_FLIGHT.has(status)) return
    co.setPaymentMethod(methodId)
    if (status === 'READY') { await commit(a.publicId, 'READY', { methodId: m.id, methodType: m.type }, 'method changed'); return }
    inFlight.current = true; setBusy(true)
    try { await createAttempt(p, c.request, c.snapshot, { countryCode: c.countryCode, restaurantId: c.restaurantId }, m.id, methods) }
    catch (e) { setError(e instanceof Error ? e.message : String(e)); setStatus('ERROR') }
    finally { inFlight.current = false; setBusy(false) }
  }, [provider, attempt, methods, status, co, commit, createAttempt])

  /** PENDING / UNKNOWN → ask the provider (later: the backend status endpoint) — never a second payment. */
  const checkStatus = useCallback(async () => {
    const a = attempt; const p = provider
    if (!a || !p || inFlight.current || (status !== 'PENDING' && status !== 'UNKNOWN')) return
    inFlight.current = true; setBusy(true)
    try {
      await commit(a.publicId, 'VERIFYING', undefined, 'status check requested')
      const r = await p.getClientPaymentResult(a)
      if (r.outcome === 'success') { const v = await verifier.verify(a); await commit(a.publicId, v.status, { failureReason: v.reason ?? null }, 'status check → verification (development mock)') }
      else if (r.outcome === 'failed') await commit(a.publicId, 'FAILED', { failureReason: r.reason ?? null }, 'status check → failed')
      else if (r.outcome === 'pending') await commit(a.publicId, 'PENDING', undefined, 'status check → still pending')
      else await commit(a.publicId, 'UNKNOWN', undefined, 'status check → still unknown')
    } catch (e) { setError(e instanceof Error ? e.message : String(e)); setStatus('ERROR') }
    finally { inFlight.current = false; setBusy(false) }
  }, [attempt, provider, status, commit, verifier])

  const reset = useCallback(() => { abortRef.current?.abort(); persistCurrent(null); setAttempt(null); setStatus('IDLE'); setError(null); ctxRef.current = null }, [])

  // Losing the connection mid-payment must not fail the attempt — it becomes UNKNOWN (handled in pay()).
  useEffect(() => { if (!co.online && status === 'READY') setError('offline'); if (co.online && error === 'offline') setError(null) }, [co.online, status, error])

  const method = useMemo(() => (attempt ? methods.find((m) => m.id === attempt.methodId) ?? null : null), [attempt, methods])
  const api = useMemo<PaymentApi>(() => ({ status, attempt, provider, methods, method, error, busy, prepare, pay, cancel, retry, changeMethod, checkStatus, reset }), [status, attempt, provider, methods, method, error, busy, prepare, pay, cancel, retry, changeMethod, checkStatus, reset])
  return <Ctx.Provider value={api}>{children}</Ctx.Provider>
}

export function usePayment() {
  const ctx = useContext(Ctx)
  if (!ctx) throw new Error('usePayment must be used inside PaymentProviderContext')
  return ctx
}

/** Stable snapshot of what the customer validated — any change expires the active attempt (spec §34–35). */
export function checkoutSnapshot(req: CheckoutRequest): string {
  return [req.cartId, req.restaurantId, req.pickupSelection.requestedAt, req.pickupSelection.slotId ?? '', req.promoCode ?? '', req.currency, req.displayedTotalMinor].join('|')
}

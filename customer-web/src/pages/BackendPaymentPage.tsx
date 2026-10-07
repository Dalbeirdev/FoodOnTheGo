import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, Navigate } from 'react-router-dom'
import Header from '../components/Header'
import { useCart } from '../cart/CartContext'
import { useCheckout } from '../checkout/CheckoutContext'
import { ApiCheckoutGateway, type ServerCheckout } from '../checkout/api/apiCheckout'
import { formatLocalTime, formatMoney, zoneLabel } from '../i18n/format'
import { formatLocalDate } from '../pickup/time'
import { t, useLocale } from '../i18n/strings'
import { ApiPaymentGateway, PaymentApiError, endedUnpaid, isUnsettled, toPaymentError, type ProviderReport, type ServerPayment, type SimulationScenario } from '../payment/api/apiPayment'
import { ProviderUnavailableError, launcherFor, type ProviderLauncher } from '../payment/api/providerLauncher'
import './CartPage.css'
import './CheckoutPage.css'

/**
 * The payment step on the backend (Module 30). One route: /payment, reached from a confirmed checkout.
 *
 *   Pay            → the backend creates a payment attempt for the checkout's total and a provider order
 *   provider       → the provider's OWN window collects whatever the customer types — never this page
 *   provider says  → that is reported to the backend, which verifies it with the provider
 *   VERIFIED       → only the backend says so. And then there is still no order: orders are the next module, so the
 *                    page says "payment verified — order creation pending" and shows no order confirmation.
 *
 * After a reload, a closed tab or a lost connection the page asks the backend what the payment is; it never asks the
 * customer to pay again on its own account. In a tab that kept nothing (a new tab, another device) the checkout the
 * backend names as "being paid for" is read again, and the payment is found through it.
 */
type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const, 'aria-hidden': true })
const LockIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><rect x="4" y="10" width="16" height="11" rx="2" /><path d="M8 10V7a4 4 0 0 1 8 0v3" /></svg>)
const CheckIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="m5 12 4 4L19 7" /></svg>)
const WarnIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M12 9v4M12 17h.01" /><path d="M10.3 3.9 2.5 18a2 2 0 0 0 1.7 3h15.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" /></svg>)
const ClockIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>)

/** The page's own phases around what the backend says. */
type Phase = 'LOADING' | 'IDLE' | 'PREPARING' | 'OPENING_PROVIDER' | 'PROVIDER_OPEN' | 'VERIFYING'
/** What is shown — the Module 12 state names, so the same texts and styles apply. */
type View = 'PREPARING' | 'READY' | 'OPENING_PROVIDER' | 'PROCESSING' | 'VERIFYING' | 'PENDING' | 'UNKNOWN' | 'VERIFIED' | 'FAILED' | 'CANCELLED' | 'EXPIRED' | 'ERROR'

const ATTEMPT_KEY = 'fotg.payment.attempt'
const METHOD_TYPE: Record<string, string> = { upi: 'UPI', card: 'CARD', netbanking: 'NET_BANKING', wallet: 'WALLET' }
/** What the page needs to know about the checkout it takes a payment for — from this tab's confirmed request, or read again from the backend. */
type Subject = { checkoutId: string; fingerprint: string; currency: string; totalMinor: number; methodType: string | null; pickupAt: string; asap: boolean; timezone: string; restaurantName: string | null; attemptId: string | null }
/** How long the page keeps asking on its own before it leaves the asking to a button. */
const AUTO_POLL_MS = 10 * 60 * 1000

function remembered(checkoutId: string): string | null {
  try { const raw = sessionStorage.getItem(ATTEMPT_KEY); const v = raw ? JSON.parse(raw) as { checkoutId?: string; paymentId?: string } : null; return v && v.checkoutId === checkoutId && typeof v.paymentId === 'string' ? v.paymentId : null } catch { return null }
}
// Only the two references are kept in the browser: never an amount as a truth, never a provider proof.
function remember(checkoutId: string, paymentId: string | null) {
  try { if (paymentId) sessionStorage.setItem(ATTEMPT_KEY, JSON.stringify({ checkoutId, paymentId })); else sessionStorage.removeItem(ATTEMPT_KEY) } catch { /* storage unavailable */ }
}

export default function BackendPaymentPage({ gateway: injected, launcher, checkouts: injectedCheckouts }: { gateway?: ApiPaymentGateway; launcher?: (provider: string) => ProviderLauncher | null; checkouts?: Pick<ApiCheckoutGateway, 'show'> }) {
  const co = useCheckout()
  const cart = useCart()
  const { locale } = useLocale()
  const gateway = useMemo(() => injected ?? new ApiPaymentGateway(), [injected])
  const checkouts = useMemo(() => injectedCheckouts ?? new ApiCheckoutGateway(), [injectedCheckouts])
  const req = co.request
  // Nothing kept in this tab, but the backend says a checkout is being paid for: read it, and go on from there.
  const resumeId = req?.checkoutId ? null : co.paymentCheckoutId
  const [resumed, setResumed] = useState<ServerCheckout | null>(null)
  const [resumeFailed, setResumeFailed] = useState(false)
  useEffect(() => {
    if (!resumeId) return
    let on = true
    checkouts.show(resumeId).then((c) => { if (on) setResumed(c) }).catch(() => { if (on) setResumeFailed(true) })
    return () => { on = false }
  }, [resumeId, checkouts])
  const cartName = cart.cart?.restaurantName ?? co.restaurant?.name ?? null
  const subject = useMemo<Subject | null>(() => {
    if (req?.checkoutId && req.pricingFingerprint) {
      return { checkoutId: req.checkoutId, fingerprint: req.pricingFingerprint, currency: req.currency, totalMinor: co.server?.pricing.totalMinor ?? req.displayedTotalMinor, methodType: METHOD_TYPE[req.paymentMethodId] ?? null,
        pickupAt: req.pickupSelection.requestedAt, asap: req.pickupSelection.mode === 'asap', timezone: req.pickupSelection.restaurantTimezone, restaurantName: cartName, attemptId: co.server?.payment?.attempt?.id ?? null }
    }
    if (resumed && resumed.id === resumeId) {
      return { checkoutId: resumed.id, fingerprint: resumed.pricing.fingerprint, currency: resumed.pricing.currency, totalMinor: resumed.pricing.totalMinor, methodType: resumed.paymentMethods.selected?.type ?? null,
        pickupAt: resumed.pickup.pickupAt, asap: resumed.pickup.mode === 'asap', timezone: resumed.pickup.timezone, restaurantName: resumed.restaurant.name ?? cartName, attemptId: resumed.payment?.attempt?.id ?? null }
    }
    return null
  }, [req, resumed, resumeId, cartName, co.server])
  const checkoutId = subject?.checkoutId ?? null
  const [payment, setPayment] = useState<ServerPayment | null>(null)
  const [phase, setPhase] = useState<Phase>('LOADING')
  const [error, setError] = useState<PaymentApiError | null>(null)
  /** The provider's interface could not be opened. Nothing was paid; the attempt can be opened again. */
  const [providerError, setProviderError] = useState(false)
  /** A report could not reach the backend: the status needs confirmation, nothing is assumed. */
  const [unconfirmed, setUnconfirmed] = useState(false)
  const [sheet, setSheet] = useState(false)
  const pollStarted = useRef<number | null>(null)
  const [pollPaused, setPollPaused] = useState(false)
  const busy = useRef(false)

  useEffect(() => { document.title = `${t('payment.title', undefined, locale)} · FoodOnTheGo` }, [locale])

  const accept = useCallback((p: ServerPayment) => { setPayment(p); if (checkoutId) remember(checkoutId, p.id) }, [checkoutId])

  // Arriving here — also after a reload or with the app reopened: is there a payment for this checkout already?
  useEffect(() => {
    if (!checkoutId) return
    let on = true
    ;(async () => {
      const known = remembered(checkoutId) ?? subject?.attemptId ?? null
      if (known) {
        try { const p = await gateway.show(known); if (on) { accept(p); setPhase('IDLE') } return } catch (e) {
          const err = toPaymentError(e)
          if (err.code === 'offline') { if (on) { setUnconfirmed(true); setPhase('IDLE') } return }
          remember(checkoutId, null) // not this customer's, or gone: start clean
        }
      }
      if (on) setPhase('IDLE')
    })()
    return () => { on = false }
  }, [checkoutId, subject?.attemptId]) // eslint-disable-line react-hooks/exhaustive-deps

  const refresh = useCallback(async (): Promise<ServerPayment | null> => {
    const id = payment?.id ?? (checkoutId ? remembered(checkoutId) : null)
    if (!id) return null
    try { const p = await gateway.show(id); accept(p); setUnconfirmed(false); setError(null); return p } catch (e) {
      const err = toPaymentError(e)
      if (err.code === 'offline') setUnconfirmed(true); else setError(err)
      return null
    }
  }, [payment?.id, checkoutId, gateway, accept])

  // While the backend has not settled the payment the page asks again — the backend, never the provider.
  const waiting = payment !== null && (isUnsettled(payment.status) || unconfirmed) && phase === 'IDLE'
  useEffect(() => {
    if (!waiting || pollPaused) { if (!waiting) pollStarted.current = null; return }
    pollStarted.current ??= Date.now()
    if (Date.now() - pollStarted.current > AUTO_POLL_MS) { setPollPaused(true); return }
    const seconds = Math.max(2, Math.min(15, payment?.retryAfterSeconds ?? 5))
    const timer = window.setTimeout(() => { void refresh() }, seconds * 1000)
    return () => window.clearTimeout(timer)
  }, [waiting, pollPaused, payment, refresh])
  // Back on this tab or back online: ask at once.
  useEffect(() => {
    const check = () => { if (document.visibilityState === 'visible' && payment && (isUnsettled(payment.status) || unconfirmed || payment.status === 'PROVIDER_READY') && phase === 'IDLE') void refresh() }
    document.addEventListener('visibilitychange', check); window.addEventListener('focus', check); window.addEventListener('online', check)
    return () => { document.removeEventListener('visibilitychange', check); window.removeEventListener('focus', check); window.removeEventListener('online', check) }
  }, [payment, unconfirmed, phase, refresh])

  /** What the provider's interface said goes to the backend. The answer — not the report — is what the page shows. */
  const report = useCallback(async (p: ServerPayment, result: ProviderReport | null) => {
    setSheet(false); setPhase('VERIFYING')
    try {
      accept(result === null ? await gateway.show(p.id) : await gateway.complete(p.id, result))
      setUnconfirmed(false)
    } catch (e) {
      const err = toPaymentError(e)
      if (err.code === 'offline') setUnconfirmed(true) // paid or not is not known here: the page keeps asking
      else { setError(err); try { accept(await gateway.show(p.id)) } catch { /* shown as it was */ } }
    } finally { setPhase('IDLE'); busy.current = false }
  }, [gateway, accept])

  const open = useCallback(async (p: ServerPayment) => {
    if (!p.session) { busy.current = false; setPhase('IDLE'); return }
    setProviderError(false)
    const launch = (launcher ?? launcherFor)(p.session.provider)
    if (launch === null) {
      // No provider window on this machine: the development provider's sheet stands in for it.
      setPhase('PROVIDER_OPEN'); setSheet(true); return
    }
    setPhase('OPENING_PROVIDER')
    try {
      const pending = launch(p.session)
      setPhase('PROVIDER_OPEN')
      await report(p, await pending)
    } catch (e) {
      if (!(e instanceof ProviderUnavailableError)) setError(toPaymentError(e))
      setProviderError(true); setPhase('IDLE'); busy.current = false
    }
  }, [launcher, report])

  /** Pay, pay again, or continue with the attempt that is already open — the backend decides which it is. */
  const pay = useCallback(async () => {
    if (!subject || busy.current) return
    busy.current = true
    setError(null); setProviderError(false); setPhase('PREPARING')
    try {
      const p = await gateway.start(subject.checkoutId, subject.fingerprint, subject.methodType)
      accept(p)
      if (p.nextAction === 'OPEN_PROVIDER') await open(p)
      else { setPhase('IDLE'); busy.current = false }
    } catch (e) {
      const err = toPaymentError(e)
      // A payment exists already (in progress, or verified): show THAT instead of starting another one.
      if (err.paymentId) { try { accept(await gateway.show(err.paymentId)) } catch { /* the refusal is shown */ } }
      if (err.code !== 'in_progress' && err.code !== 'already_verified') setError(err)
      setPhase('IDLE'); busy.current = false
    }
  }, [subject, gateway, accept, open])

  const simulate = useCallback(async (scenario: SimulationScenario, webhook: 'none' | 'deliver' | 'only' = 'none') => {
    if (!payment) return
    try { await report(payment, await gateway.simulate(payment.id, scenario, webhook)) } catch (e) { setError(toPaymentError(e)); setSheet(false); setPhase('IDLE'); busy.current = false }
  }, [payment, gateway, report])

  if (!subject) {
    // Being read again from the backend: nothing is shown as payable until it has answered.
    if (resumeId && !resumeFailed) return (<><Header /><main id="main" className="cart pay" data-testid="pay-backend" data-status="" data-view="PREPARING"><div className="cart__grid cart__grid--single"><div className="cart__main"><h1 className="cart-head">{t('payment.title', undefined, locale)}</h1><p className="cart-muted" role="status" data-testid="pay-text">{t('pay.state.preparing.text', undefined, locale)}</p></div></div></main></>)
    return <Navigate to="/checkout" replace />
  }

  const money = (minor: number) => formatMoney(minor, payment?.currency ?? subject.currency, locale)
  // The amount on this page is the backend's: the attempt's once there is one, the confirmed checkout's before.
  const amountMinor = payment?.amountMinor ?? subject.totalMinor
  const tz = subject.timezone
  const itemCount = cart.cart?.items.reduce((a, i) => a + i.quantity, 0) ?? 0
  const checkoutGone = error !== null && ['checkout_stale', 'checkout_expired', 'window_closed', 'attempt_limit', 'not_required'].includes(error.code)
  const view: View = phase === 'LOADING' || phase === 'PREPARING' ? 'PREPARING'
    : phase === 'OPENING_PROVIDER' ? 'OPENING_PROVIDER'
      : phase === 'PROVIDER_OPEN' ? 'PROCESSING'
        : phase === 'VERIFYING' ? 'VERIFYING'
          : payment === null ? (error ? 'ERROR' : 'READY')
            : unconfirmed ? 'UNKNOWN'
              : payment.status === 'VERIFIED' ? 'VERIFIED'
                : payment.status === 'FAILED' ? 'FAILED' : payment.status === 'CANCELLED' ? 'CANCELLED' : payment.status === 'EXPIRED' ? 'EXPIRED'
                  : payment.status === 'PROVIDER_READY' ? 'READY'
                    : payment.status === 'PROCESSING' || payment.status === 'CREATED' ? 'VERIFYING'
                      : payment.status === 'PENDING' || payment.status === 'AUTHORIZED' ? 'PENDING' : 'UNKNOWN'
  const inFlight = phase !== 'IDLE'
  const k = view.toLowerCase()
  const canRetry = payment !== null && endedUnpaid(payment.status) && (payment.nextAction === 'RETRY_PAYMENT' || payment.nextAction === 'SELECT_ANOTHER_METHOD')
  const mustReturn = checkoutGone || (payment !== null && payment.nextAction === 'RETURN_TO_CHECKOUT')
  const fake = payment?.provider === 'fake'
  const title = view === 'VERIFIED' ? t('pay.api.verified.title', undefined, locale) : t(`pay.state.${k}.title`, undefined, locale)
  // The backend's own words where it has some — they blame nobody and name no provider detail.
  const text = view === 'VERIFIED' || view === 'FAILED' || view === 'CANCELLED' || view === 'EXPIRED' ? (payment?.failure?.message ?? payment?.message ?? t(`pay.state.${k}.text`, undefined, locale))
    : (view === 'PENDING' || view === 'UNKNOWN' || view === 'VERIFYING') && payment?.message && !unconfirmed ? payment.message
      : unconfirmed ? t('pay.api.unconfirmed', undefined, locale)
        : view === 'ERROR' ? (checkoutGone ? t('pay.api.checkoutChanged', undefined, locale) : error?.code === 'provider_unavailable' || error?.code === 'initialization_unknown' ? t('pay.api.unavailable', undefined, locale) : error?.code === 'offline' ? t('pay.offline', undefined, locale) : t('pay.state.error.text', undefined, locale))
          : t(`pay.state.${k}.text`, undefined, locale)

  return (
    <>
      <Header />
      <main id="main" className="cart pay" data-testid="pay-backend" data-status={payment?.status ?? ''} data-view={view}>
        <div className="cart__grid cart__grid--single">
          <div className="cart__main">
            <p className="cart-eyebrow">{t('payment.eyebrow', undefined, locale)}</p>
            <h1 className="cart-head">{t('payment.title', undefined, locale)}</h1>
            <p className="cart-muted">{t('pay.lead', undefined, locale)}</p>
            {payment && payment.providerMode !== 'LIVE' && <p className="cart-mock" data-testid="pay-mode" data-mode={payment.providerMode}>{t(payment.providerMode === 'FAKE' ? 'pay.api.fakeMode' : 'pay.api.testMode', undefined, locale)}</p>}

            {!co.online && <p className="cart-notice cart-notice--error" role="alert" data-testid="pay-offline"><WarnIcon /> {t('pay.offline', undefined, locale)}</p>}

            <section className="cart-card" aria-labelledby="pay-sum">
              <h2 id="pay-sum">{t('pay.summary', undefined, locale)}</h2>
              <dl className="co-dl">
                <div><dt>{t('pay.restaurant', undefined, locale)}</dt><dd>{subject.restaurantName ?? '—'}</dd></div>
                <div><dt>{t('pay.pickup', undefined, locale)}</dt><dd>{formatLocalDate(subject.pickupAt, tz, locale)} · {subject.asap ? `${t('pickup.asap', undefined, locale)} · ~` : ''}{formatLocalTime(subject.pickupAt, tz, locale)} {zoneLabel(subject.pickupAt, tz, locale)}<small>{tz}</small></dd></div>
                {itemCount > 0 && <div><dt>{t('pay.items', undefined, locale)}</dt><dd>{t('pay.items.count', { count: itemCount }, locale)}</dd></div>}
                <div><dt>{t('pay.total', undefined, locale)}</dt><dd><b data-testid="pay-total" data-amount={amountMinor}>{money(amountMinor)}</b> <span className="co-badge co-badge--muted" data-testid="pay-currency">{payment?.currency ?? subject.currency}</span><small>{t('pay.api.total.note', undefined, locale)}</small></dd></div>
                {payment?.method && <div><dt>{t('pay.method', undefined, locale)}</dt><dd data-testid="pay-method">{methodLabel(payment.method, locale)}</dd></div>}
              </dl>
            </section>

            <section className={`cart-card pay-state pay-state--${k}`} aria-live="polite" aria-labelledby="pay-state-title">
              <h2 id="pay-state-title" className="pay-state__title" data-testid="pay-state">
                {view === 'VERIFIED' ? <CheckIcon /> : (view === 'FAILED' || view === 'ERROR' || view === 'EXPIRED') ? <WarnIcon /> : (view === 'PENDING' || view === 'UNKNOWN') ? <ClockIcon /> : <LockIcon />} {title}
              </h2>
              <p className="pay-state__text" role={view === 'FAILED' || view === 'ERROR' || view === 'CANCELLED' ? 'alert' : 'status'} data-testid="pay-text">{text}</p>
              {(inFlight || view === 'VERIFYING') && <div className="pay-progress" aria-hidden="true"><span /></div>}
              {payment && <p className="cart-muted pay-ref" data-testid="pay-reference">{t('pay.api.reference', { ref: payment.supportReference }, locale)}{payment.attemptNumber > 1 ? ` · ${t('pay.api.attempt', { n: payment.attemptNumber }, locale)}` : ''}</p>}

              {view === 'VERIFIED' && <>
                {/* Paid is not ordered. Until the order module exists this is where the journey ends — and it says so. */}
                <p className="cart-notice cart-notice--warn" role="status" data-testid="pay-order-pending" data-fulfillment={payment?.fulfillment ?? ''}><ClockIcon /> {t('pay.api.orderPending', undefined, locale)}</p>
              </>}
              {(view === 'PENDING' || view === 'UNKNOWN') && <p className="cart-notice cart-notice--warn" role="alert" data-testid="pay-do-not-pay-again"><WarnIcon /> {t('pay.doNotPayAgain', undefined, locale)}</p>}
              {payment?.status === 'REVIEW_REQUIRED' && <p className="cart-notice cart-notice--warn" role="alert" data-testid="pay-support">{t('pay.api.support', { ref: payment.supportReference }, locale)}</p>}
              {providerError && <p className="cart-line__issue" role="alert" data-testid="pay-provider-error">{t('pay.api.providerWindow', undefined, locale)}</p>}
              {/* A refusal that was overtaken by the backend's own finding (the provider confirmed the payment) is not shown beside it. */}
              {error && !checkoutGone && view !== 'ERROR' && view !== 'VERIFIED' && <p className="cart-line__issue" role="alert" data-testid="pay-error" data-code={error.code}>{error.code === 'signature_invalid' ? t('pay.api.signature', undefined, locale) : error.code === 'provider_unavailable' || error.code === 'initialization_unknown' ? t('pay.api.unavailable', undefined, locale) : error.code === 'rate_limited' ? t('pay.api.rateLimited', undefined, locale) : t('pay.error.generic', undefined, locale)}</p>}
              {view === 'ERROR' && <span data-testid="pay-error" data-code={error?.code ?? ''} hidden />}

              <div className="pay-actions">
                {(view === 'READY' || view === 'PREPARING' || view === 'OPENING_PROVIDER' || view === 'PROCESSING' || view === 'VERIFYING') && !mustReturn && (
                  <button type="button" className="btn btn--primary cart-proceed" data-testid="pay-submit" data-amount={amountMinor} disabled={view !== 'READY' || inFlight || !co.online} aria-busy={inFlight} onClick={() => { void pay() }}>
                    <LockIcon size={16} /> {view === 'READY' ? t(payment ? 'pay.api.continue' : 'pay.button', { amount: money(amountMinor) }, locale) : t(`pay.state.${k}.button`, undefined, locale)}
                  </button>
                )}
                {canRetry && !mustReturn && <button type="button" className="btn btn--primary" data-testid="pay-retry" disabled={inFlight || !co.online} onClick={() => { void pay() }}>{t(payment?.nextAction === 'SELECT_ANOTHER_METHOD' ? 'pay.api.retryOther' : view === 'CANCELLED' ? 'pay.action.tryAgain' : 'pay.action.retry', undefined, locale)}</button>}
                {view === 'ERROR' && !mustReturn && <button type="button" className="btn btn--primary" data-testid="pay-retry" disabled={inFlight || !co.online} onClick={() => { void pay() }}>{t('pay.action.retry', undefined, locale)}</button>}
                {(view === 'PENDING' || view === 'UNKNOWN' || (view === 'VERIFYING' && !inFlight)) && <button type="button" className="btn btn--primary" data-testid="pay-check" onClick={() => { pollStarted.current = null; setPollPaused(false); void refresh() }}>{t('pay.action.checkStatus', undefined, locale)}</button>}
                {mustReturn && <Link to="/checkout" className="btn btn--primary" data-testid="pay-return" onClick={() => { if (checkoutId) remember(checkoutId, null); co.clearRequest() }}>{t('pay.api.backToCheckout', undefined, locale)}</Link>}
                {!inFlight && !mustReturn && view !== 'VERIFIED' && view !== 'PENDING' && view !== 'UNKNOWN' && view !== 'VERIFYING' && <Link to="/checkout" className="btn btn--outline" data-testid="pay-back">{t('payment.back', undefined, locale)}</Link>}
                {view === 'VERIFIED' && <Link to="/" className="btn btn--outline" data-testid="pay-home">{t('pay.handoff.back', undefined, locale)}</Link>}
              </div>
              {inFlight && <p className="cart-muted">{t('pay.inflight.note', undefined, locale)}</p>}
            </section>

            <p className="cart-muted pay-secure"><LockIcon size={14} /> {t('pay.secureNote', undefined, locale)}</p>

            {fake && payment && (sheet || payment.status === 'PENDING' || payment.status === 'AUTHORIZED') && (
              <section className="cart-card pay-dev" aria-labelledby="pay-dev" data-testid="pay-dev-sheet">
                <h2 id="pay-dev">{t('pay.api.dev.title', undefined, locale)}</h2>
                <p className="cart-muted">{t('pay.api.dev.text', undefined, locale)}</p>
                {sheet ? (
                  <div className="pay-actions">
                    <button type="button" className="btn btn--primary" data-testid="pay-dev-success" onClick={() => { void simulate('SUCCESS') }}>{t('pay.api.dev.success', undefined, locale)}</button>
                    <button type="button" className="btn btn--outline" data-testid="pay-dev-fail" onClick={() => { void simulate('FAILED') }}>{t('pay.api.dev.fail', undefined, locale)}</button>
                    <button type="button" className="btn btn--outline" data-testid="pay-dev-cancel" onClick={() => { void simulate('CANCELLED') }}>{t('pay.api.dev.cancel', undefined, locale)}</button>
                    <button type="button" className="btn btn--outline" data-testid="pay-dev-pending" onClick={() => { void simulate('PENDING') }}>{t('pay.api.dev.pending', undefined, locale)}</button>
                    <button type="button" className="btn btn--outline" data-testid="pay-dev-webhook-only" onClick={() => { void simulate('SUCCESS', 'only') }}>{t('pay.api.dev.webhookOnly', undefined, locale)}</button>
                    <button type="button" className="btn btn--outline" data-testid="pay-dev-invalid" onClick={() => { void simulate('INVALID_SIGNATURE') }}>{t('pay.api.dev.invalid', undefined, locale)}</button>
                    <button type="button" className="btn btn--outline" data-testid="pay-dev-mismatch" onClick={() => { void simulate('AMOUNT_MISMATCH') }}>{t('pay.api.dev.mismatch', undefined, locale)}</button>
                  </div>
                ) : (
                  <div className="pay-actions">
                    <button type="button" className="btn btn--outline" data-testid="pay-dev-late-success" onClick={() => { void simulate('LATE_SUCCESS', 'deliver') }}>{t('pay.api.dev.lateSuccess', undefined, locale)}</button>
                    <button type="button" className="btn btn--outline" data-testid="pay-dev-late-failure" onClick={() => { void simulate('LATE_FAILURE', 'deliver') }}>{t('pay.api.dev.lateFailure', undefined, locale)}</button>
                  </div>
                )}
              </section>
            )}
          </div>
        </div>
      </main>
    </>
  )
}

function methodLabel(method: NonNullable<ServerPayment['method']>, locale: string): string {
  const s = method.summary
  const name = t(`pay.api.method.${method.type}`, undefined, locale)
  if (method.type === 'CARD' && typeof s.last4 === 'string') return `${typeof s.network === 'string' ? s.network : name} •••• ${s.last4}`
  if (method.type === 'UPI' && typeof s.vpa === 'string') return `${name} · ${s.vpa}`
  return name
}

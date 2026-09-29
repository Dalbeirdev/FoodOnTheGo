import { useEffect, useRef, useState } from 'react'
import { Link, Navigate, useNavigate } from 'react-router-dom'
import Header from '../components/Header'
import { useCart } from '../cart/CartContext'
import { useJourney } from '../journey/JourneyContext'
import { orderRepositories } from '../order/useOrderConfirmation'
import { usePickup } from '../pickup/PickupContext'
import { settingsFor } from '../pickup/mock/mockPickup'
import { useCheckout } from '../checkout/CheckoutContext'
import { formatLocalTime, formatMoney, zoneLabel } from '../i18n/format'
import { formatLocalDate } from '../pickup/time'
import { t, useLocale } from '../i18n/strings'
import { checkoutSnapshot, usePayment } from '../payment/PaymentContext'
import { readMockOutcome, setMockOutcome, type MockOutcome } from '../payment/mock/mockPayment'
import type { PaymentStatus } from '../payment/repositories'
import { restaurantRepository } from '../repositories'
import type { Restaurant } from '../repositories/types'
import './CartPage.css'
import './CheckoutPage.css'

/**
 * Payment experience (Module 12). One canonical route: /payment, launched from /checkout.
 * The page never renders card / UPI / bank fields: the provider experience (mock today) collects them.
 * The amount shown is the validated display total — the server prices the checkout itself later.
 */
type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const, 'aria-hidden': true })
const LockIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><rect x="4" y="10" width="16" height="11" rx="2" /><path d="M8 10V7a4 4 0 0 1 8 0v3" /></svg>)
const CheckIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="m5 12 4 4L19 7" /></svg>)
const WarnIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M12 9v4M12 17h.01" /><path d="M10.3 3.9 2.5 18a2 2 0 0 0 1.7 3h15.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" /></svg>)
const ClockIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>)

const DEV = import.meta.env.DEV
const OUTCOMES: MockOutcome[] = ['success', 'failure', 'cancelled', 'pending', 'timeout', 'unknown']

export default function PaymentPage() {
  const co = useCheckout()
  const pay = usePayment()
  const cart = useCart()
  const journey = useJourney()
  const pickup = usePickup()
  const navigate = useNavigate()
  const { locale } = useLocale()
  const req = co.request
  const [restaurant, setRestaurant] = useState<Restaurant | null>(co.restaurant)
  const [outcome, setOutcome] = useState<MockOutcome>(() => readMockOutcome())
  const handoff = useRef(false)

  useEffect(() => { document.title = `${t('payment.title', undefined, locale)} · FoodOnTheGo` }, [locale])
  // Restaurant context (country → provider, zone → pickup display). After a refresh the checkout provider may not have it yet.
  useEffect(() => {
    if (!req) return
    if (co.restaurant && co.restaurant.id === req.restaurantId) { setRestaurant(co.restaurant); return }
    let on = true; void restaurantRepository.getRestaurantBySlug(req.restaurantId).then((r) => { if (on) setRestaurant(r) }); return () => { on = false }
  }, [req, co.restaurant])
  // Prepare (or recover) the attempt once the request + restaurant are known.
  useEffect(() => {
    if (!req || !restaurant) return
    if (pay.attempt && pay.attempt.checkoutReference === req.idempotencyKey && pay.status !== 'IDLE') return
    void pay.prepare(req, checkoutSnapshot(req), { countryCode: restaurant.countryCode, restaurantId: restaurant.id })
  }, [req, restaurant]) // eslint-disable-line react-hooks/exhaustive-deps
  // VERIFIED → brief confirmation, then hand off to the order-confirmation stage (Module 13 builds that page).
  const attemptId = pay.attempt?.publicId
  useEffect(() => {
    if (pay.status !== 'VERIFIED' || !attemptId || !pay.attempt || !restaurant || !req || handoff.current) return
    handoff.current = true
    const a = pay.attempt; const r = restaurant; const c = cart.cart; const rq = req
    let on = true
    ;(async () => {
      // Module 13: the (development) order is created once per payment attempt — a refresh or repeated handoff returns the same order.
      const settings = settingsFor(r)
      const m = settings.methods.find((x) => x.enabled) ?? settings.methods[0]
      const order = await orderRepositories.orders.createFromPayment({
        paymentAttemptId: a.publicId, checkoutReference: a.checkoutReference, customerId: a.customerId,
        restaurant: { id: r.id, slug: r.slug, name: r.name, formattedAddress: r.address.formatted, countryCode: r.countryCode, timezone: r.timezone, lat: r.lat ?? null, lng: r.lng ?? null, contact: null, pickupInstructions: settings.instructions ?? null, pickupLocation: m?.label ?? null },
        items: (c?.items ?? []).map((i) => ({ lineId: i.id, menuItemId: i.menuItemId, itemName: i.itemName, image: i.image, variants: i.selectedVariants.map((v) => ({ groupName: v.groupName, optionName: v.optionName, priceAdjustmentMinor: v.priceAdjustmentMinor })), modifiers: i.selectedModifiers.map((v) => ({ groupName: v.groupName, optionName: v.optionName, priceAdjustmentMinor: v.priceAdjustmentMinor })), specialInstructions: i.specialInstructions, quantity: i.quantity, unitPriceMinor: i.unitPriceMinor, lineTotalMinor: i.lineTotalMinor })),
        pricing: { currency: rq.currency, subtotalMinor: co.summary?.subtotalMinor ?? (c?.items ?? []).reduce((s, i) => s + i.lineTotalMinor, 0), discountMinor: co.summary?.discountMinor ?? 0, promoCode: rq.promoCode, taxes: co.summary?.taxes.map((l) => ({ id: l.id, label: l.label, amountMinor: l.amountMinor })) ?? [], fees: co.summary?.fees.map((l) => ({ id: l.id, label: l.label, amountMinor: l.amountMinor })) ?? [], totalMinor: rq.displayedTotalMinor },
        payment: { status: 'PAID', methodType: a.methodType, methodLabel: pay.method?.label ?? a.methodId, providerDisplayName: pay.provider?.displayName ?? a.provider, reference: a.publicId, paidAmountMinor: a.amountMinor, currency: a.currency, maskedDetails: null },
        pickup: { mode: rq.pickupSelection.mode, requestedAt: rq.pickupSelection.requestedAt, estimatedReadyTime: rq.pickupSelection.estimatedReadyTime, restaurantTimezone: rq.pickupSelection.restaurantTimezone, methodType: m?.type ?? 'counter', methodLabel: m?.label ?? 'Counter pickup', instructions: m?.instructions ?? null },
        journey: journey.journey ? { journeyId: journey.journey.id, originName: journey.journey.origin.name, destinationName: journey.journey.destination.name } : null,
        orderNote: rq.orderNote,
      })
      await new Promise((r) => setTimeout(r, 1200)) // brief "Payment confirmed. Preparing your order…" transition
      if (!on) return
      // The checkout is consumed: clear the cart, the pickup selection and the request so nothing can re-enter payment.
      navigate(`/order-confirmation/${order.orderNumber}`, { replace: true })
      cart.clearCart(); pickup.clear(); co.clearRequest(); pay.reset()
    })().catch(() => { if (on) navigate(`/order-confirmation/pending-${attemptId}`, { replace: true }) })
    return () => { on = false }
  }, [pay.status, attemptId]) // eslint-disable-line react-hooks/exhaustive-deps

  if (!req) return handoff.current ? null : <Navigate to="/checkout" replace />
  const tz = req.pickupSelection.restaurantTimezone
  const money = (m: number) => formatMoney(m, req.currency, locale)
  const itemCount = cart.cart?.items.reduce((a, i) => a + i.quantity, 0) ?? 0
  const s = pay.status
  const inFlight = s === 'PREPARING' || s === 'OPENING_PROVIDER' || s === 'PROCESSING' || s === 'VERIFYING' || pay.busy
  const canPay = s === 'READY' && !pay.busy && co.online
  const showMethods = s === 'READY' || s === 'FAILED' || s === 'CANCELLED' || s === 'EXPIRED'
  const stateText = stateCopy(s, locale)

  return (
    <>
      <Header />
      <main id="main" className="cart pay">
        <div className="cart__grid cart__grid--single">
          <div className="cart__main">
            <p className="cart-eyebrow">{t('payment.eyebrow', undefined, locale)}</p>
            <h1 className="cart-head">{t('payment.title', undefined, locale)}</h1>
            <p className="cart-muted">{t('pay.lead', undefined, locale)}</p>
            <p className="cart-mock">{t('pay.mock', undefined, locale)}</p>

            {!co.online && <p className="cart-notice cart-notice--error" role="alert"><WarnIcon /> {t('pay.offline', undefined, locale)}</p>}

            <section className="cart-card" aria-labelledby="pay-sum">
              <h2 id="pay-sum">{t('pay.summary', undefined, locale)}</h2>
              <dl className="co-dl">
                <div><dt>{t('pay.restaurant', undefined, locale)}</dt><dd>{cart.cart?.restaurantName ?? restaurant?.name ?? req.restaurantId}</dd></div>
                <div><dt>{t('pay.pickup', undefined, locale)}</dt><dd>{formatLocalDate(req.pickupSelection.requestedAt, tz, locale)} · {req.pickupSelection.mode === 'asap' ? `${t('pickup.asap', undefined, locale)} · ~` : ''}{formatLocalTime(req.pickupSelection.requestedAt, tz, locale)} {zoneLabel(req.pickupSelection.requestedAt, tz, locale)}<small>{tz}</small></dd></div>
                <div><dt>{t('pay.items', undefined, locale)}</dt><dd>{t('pay.items.count', { count: itemCount }, locale)}</dd></div>
                <div><dt>{t('pay.total', undefined, locale)}</dt><dd><b data-testid="pay-total">{money(req.displayedTotalMinor)}</b> <span className="co-badge co-badge--muted" data-testid="pay-currency">{req.currency}</span><small>{t('pay.total.note', undefined, locale)}</small></dd></div>
                <div><dt>{t('pay.method', undefined, locale)}</dt><dd data-testid="pay-method">{pay.method?.label ?? '—'}{pay.provider && <small>{t('pay.provider', { provider: pay.provider.displayName }, locale)}</small>}</dd></div>
              </dl>
            </section>

            {showMethods && pay.methods.length > 0 && (
              <section className="cart-card" aria-labelledby="pay-methods">
                <h2 id="pay-methods">{t('pay.methods', undefined, locale)}</h2>
                <div className="co-methods" role="radiogroup" aria-label={t('pay.methods', undefined, locale)}>
                  {pay.methods.map((m) => (
                    <label key={m.id} className={`co-method ${pay.attempt?.methodId === m.id ? 'is-on' : ''} ${m.enabled ? '' : 'is-off'}`}>
                      <input type="radio" name="pay-method" value={m.id} checked={pay.attempt?.methodId === m.id} disabled={!m.enabled || inFlight} onChange={() => { void pay.changeMethod(m.id) }} />
                      <span><b>{m.label}</b>{(m.description || m.reasonDisabled) && <small>{m.enabled ? m.description : m.reasonDisabled}</small>}</span>
                    </label>
                  ))}
                </div>
                <p className="cart-muted">{t('pay.methods.note', undefined, locale)}</p>
              </section>
            )}

            <section className={`cart-card pay-state pay-state--${s.toLowerCase()}`} aria-live="polite" aria-labelledby="pay-state-title">
              <h2 id="pay-state-title" className="pay-state__title">
                {s === 'VERIFIED' ? <CheckIcon /> : (s === 'FAILED' || s === 'ERROR' || s === 'EXPIRED') ? <WarnIcon /> : (s === 'PENDING' || s === 'UNKNOWN') ? <ClockIcon /> : <LockIcon />} {stateText.title}
              </h2>
              <p className="pay-state__text" role={s === 'FAILED' || s === 'ERROR' || s === 'CANCELLED' ? 'alert' : 'status'}>{stateText.text}{s === 'FAILED' && pay.attempt?.failureReason ? ` (${pay.attempt.failureReason})` : ''}</p>
              {inFlight && s !== 'READY' && <div className="pay-progress" aria-hidden="true"><span /></div>}
              {pay.attempt && <p className="cart-muted pay-ref">{t('pay.reference', { id: pay.attempt.publicId }, locale)}{pay.attempt.providerPaymentReference && s !== 'READY' ? ` · ${t('pay.reference.provider', { id: pay.attempt.providerPaymentReference }, locale)}` : ''}</p>}
              {s === 'VERIFIED' && <p className="cart-muted">{t('pay.verified.dev', undefined, locale)}</p>}
              {(s === 'PENDING' || s === 'UNKNOWN') && <p className="cart-notice cart-notice--warn" role="alert"><WarnIcon /> {t('pay.doNotPayAgain', undefined, locale)}</p>}
              {pay.error && pay.error !== 'offline' && <p className="cart-line__issue" role="alert">{t('pay.error.generic', undefined, locale)}</p>}

              <div className="pay-actions">
                {(s === 'READY' || s === 'PREPARING' || s === 'OPENING_PROVIDER' || s === 'PROCESSING' || s === 'VERIFYING' || s === 'SUCCESS_CLIENT_SIDE') && (
                  <button type="button" className="btn btn--primary cart-proceed" disabled={!canPay} aria-busy={inFlight} onClick={() => { void pay.pay() }}>
                    <LockIcon size={16} /> {s === 'READY' ? t('pay.button', { amount: money(req.displayedTotalMinor) }, locale) : stateText.button}
                  </button>
                )}
                {s === 'PROCESSING' && <button type="button" className="btn btn--outline" onClick={() => { void pay.cancel() }}>{t('pay.action.cancel', undefined, locale)}</button>}
                {(s === 'FAILED' || s === 'CANCELLED' || s === 'EXPIRED' || s === 'ERROR') && <button type="button" className="btn btn--primary" disabled={pay.busy} onClick={() => { void pay.retry() }}>{s === 'CANCELLED' ? t('pay.action.tryAgain', undefined, locale) : t('pay.action.retry', undefined, locale)}</button>}
                {(s === 'FAILED' || s === 'CANCELLED') && <a className="btn btn--outline" href="#pay-methods">{t('pay.action.changeMethod', undefined, locale)}</a>}
                {(s === 'PENDING' || s === 'UNKNOWN') && <button type="button" className="btn btn--primary" disabled={pay.busy} onClick={() => { void pay.checkStatus() }}>{t('pay.action.checkStatus', undefined, locale)}</button>}
                {!inFlight && s !== 'VERIFIED' && <Link to="/checkout" className="btn btn--outline">{t('payment.back', undefined, locale)}</Link>}
              </div>
              {inFlight && <p className="cart-muted">{t('pay.inflight.note', undefined, locale)}</p>}
            </section>

            <p className="cart-muted pay-secure"><LockIcon size={14} /> {t('pay.secureNote', undefined, locale)}</p>

            {DEV && (
              <section className="cart-card pay-dev" aria-labelledby="pay-dev">
                <h2 id="pay-dev">{t('pay.dev.title', undefined, locale)}</h2>
                <p className="cart-muted">{t('pay.dev.text', undefined, locale)}</p>
                <label className="pay-dev__row">{t('pay.dev.outcome', undefined, locale)}
                  <select value={outcome} onChange={(e) => { const v = e.target.value as MockOutcome; setOutcome(v); setMockOutcome(v) }} disabled={inFlight}>
                    {OUTCOMES.map((o) => <option key={o} value={o}>{o}</option>)}
                  </select>
                </label>
              </section>
            )}
          </div>
        </div>
      </main>
    </>
  )
}

function stateCopy(s: PaymentStatus, locale: string): { title: string; text: string; button: string } {
  const k = s.toLowerCase()
  return { title: t(`pay.state.${k}.title`, undefined, locale), text: t(`pay.state.${k}.text`, undefined, locale), button: t(`pay.state.${k}.button`, undefined, locale) }
}

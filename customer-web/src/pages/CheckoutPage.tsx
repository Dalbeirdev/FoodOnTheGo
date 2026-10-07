import { marketRepository } from '../market/mock/mockMarket'
import { useEffect, useMemo, useState } from 'react'
import { Link, Navigate, useLocation, useNavigate } from 'react-router-dom'
import Header from '../components/Header'
import { ClockIcon, PinIcon } from '../components/Icons'
import { useAuth } from '../auth/AuthContext'
import { useCart } from '../cart/CartContext'
import { useCheckout } from '../checkout/CheckoutContext'
import { remainingMs, type ServerCheckout } from '../checkout/api/apiCheckout'
import { useJourney } from '../journey/JourneyContext'
import { usePickup } from '../pickup/PickupContext'
import { formatLocalDate } from '../pickup/time'
import { useProfile } from '../profile/ProfileContext'
import { computeAvailability } from '../repositories/mock/restaurants'
import { formatLocalTime, formatMinutes, formatMoney, zoneLabel } from '../i18n/format'
import { t, useLocale } from '../i18n/strings'
import './CartPage.css'
import './CheckoutPage.css'

/**
 * Checkout review (Module 11) — canonical /checkout, protected by RequireAuth. A REVIEW of centralized
 * state: customer (verified profile), restaurant, pickup, items, pricing (configured components only),
 * promo, order note, legal acknowledgement, payment method entry point. No raw payment credentials are
 * collected. Continue to secure payment prepares a CheckoutRequest for Module 12 — nothing is charged.
 */
type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const })
const UserIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><circle cx="12" cy="8" r="4" /><path d="M4 21a8 8 0 0 1 16 0" /></svg>)
const ShopIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M3 9l1.5-5h15L21 9M4 9v11h16V9M9 20v-6h6v6" /></svg>)
const BagIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M6 8h12l1 13H5L6 8Z" /><path d="M9 8V6a3 3 0 0 1 6 0v2" /></svg>)
const TagIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M3 12V3h9l9 9-9 9-9-9Z" /><circle cx="8" cy="8" r="1.5" /></svg>)
const NoteIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M4 5h16v11H9l-5 4V5Z" /></svg>)
const ShieldIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6l-8-3Z" /><path d="m9 12 2 2 4-4" /></svg>)
const CardIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3 10h18" /></svg>)
const WarnIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M12 3 2 20h20L12 3Zm0 6v5m0 3v.5" /></svg>)
const PenIcon = ({ size = 14 }: P) => (<svg {...stroke(size)}><path d="M4 20h4l10-10-4-4L4 16v4ZM13 7l4 4" /></svg>)

/** Seconds until the backend checkout expires — counted from the SERVER's clock; this device only counts the time since. */
function useSecondsLeft(checkout: ServerCheckout | null, running: boolean): number | null {
  const [left, setLeft] = useState<number | null>(null)
  useEffect(() => {
    if (!checkout || !running) { setLeft(null); return }
    const tick = () => setLeft(Math.max(0, Math.ceil(remainingMs(checkout) / 1000)))
    tick()
    const timer = setInterval(tick, 1000)
    return () => clearInterval(timer)
  }, [checkout, running])
  return left
}
const clock = (seconds: number) => `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
/** Basis points as the backend configured them (250 → "2.5"), without a float calculation deciding anything. */
const percent = (basisPoints: number) => { const whole = Math.floor(basisPoints / 100); const rest = basisPoints % 100; return rest === 0 ? String(whole) : `${whole}.${String(rest).padStart(2, '0').replace(/0$/, '')}` }
const initials = (name: string) => name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]?.toUpperCase() ?? '').join('')

function Img({ src, fallback }: { src: string; fallback: string }) {
  return (<span className="cart-line__img co-line__img">{src && <img src={src} alt="" loading="lazy" onError={(e) => { e.currentTarget.style.display = 'none' }} />}<span aria-hidden="true">{fallback}</span></span>)
}

export default function CheckoutPage() {
  const { locale } = useLocale()
  const auth = useAuth()
  const cart = useCart()
  const pickup = usePickup()
  const co = useCheckout()
  const { profile } = useProfile()
  const { journey } = useJourney()
  const navigate = useNavigate()
  const location = useLocation()
  const [promoInput, setPromoInput] = useState('')
  const [continuing, setContinuing] = useState(false)
  const [showIssues, setShowIssues] = useState(false)
  const c = cart.cart
  const cartKey = c ? c.items.map((i) => `${i.id}:${i.quantity}:${i.unitPriceMinor}`).join('|') : ''
  const { prepare, revalidate } = co
  /** API mode: everything below is the backend's checkout; `sv` is null in mock mode and until the first answer. */
  const backend = co.source === 'api'
  const sv = co.server
  const secondsLeft = useSecondsLeft(sv, backend && (co.status === 'READY' || co.status === 'PAYMENT_READY'))

  useEffect(() => { document.title = `${t('checkout.title', undefined, locale)} · FoodOnTheGo` }, [locale])
  // Prepare whenever the inputs that matter change (cart lines, pickup selection, auth, connectivity) — and not before
  // the stored pickup choice is known: after a page load it arrives from the backend one request after the cart, and
  // "none yet" must not be taken for "none" (the customer would be sent back to choose a time they already chose).
  useEffect(() => { if (cart.hydrated && pickup.ready) void prepare() }, [prepare, cart.hydrated, pickup.ready, cartKey, pickup.selection?.requestedAt, auth.isAuthenticated, co.online]) // eslint-disable-line react-hooks/exhaustive-deps
  // Back on this tab (or back online): what was on the screen may be old. The backend is asked what the checkout is now.
  useEffect(() => {
    if (!backend) return
    const check = () => { if (document.visibilityState === 'visible') void revalidate() }
    document.addEventListener('visibilitychange', check); window.addEventListener('focus', check); window.addEventListener('online', check)
    return () => { document.removeEventListener('visibilitychange', check); window.removeEventListener('focus', check); window.removeEventListener('online', check) }
  }, [backend, revalidate])

  const r = co.restaurant
  const tz = sv?.pickup.timezone ?? pickup.selection?.restaurantTimezone ?? r?.timezone ?? 'UTC'
  const currency = sv?.pricing.currency ?? c?.currency ?? marketRepository.getActiveMarket().defaultCurrency
  const money = (m: number) => formatMoney(m, currency, locale)
  const time = (iso: string) => formatLocalTime(iso, tz, locale)
  const availability = useMemo(() => (r ? computeAvailability(r, new Date().toISOString()) : null), [r])
  const blocking = co.issues.filter((i) => i.blocking)
  const canContinue = co.status === 'READY' && blocking.length === 0 && !continuing && (!backend || sv !== null)
  const sel = pickup.selection
  // API mode: the customer shown is the account the backend made the checkout for.
  const name = sv?.customer.name ?? profile?.name ?? auth.user?.name ?? ''
  const phone = sv?.customer.phone ?? profile?.phone ?? auth.user?.phone ?? ''
  const email = sv?.customer.email ?? profile?.email ?? auth.user?.email ?? ''
  const free = backend && sv !== null && !sv.pricing.paymentRequired
  const confirmedFree = free && co.status === 'PAYMENT_READY'
  const serverIssues = backend ? blocking.filter((i) => i.message !== undefined || i.recover !== undefined || ['checkout_expired', 'payment_in_progress', 'server', 'checkout_changed', 'item_unavailable', 'pickup_full'].includes(i.code)) : []

  if (cart.hydrated && (!c || c.items.length === 0)) return <Navigate to="/cart" replace />
  if (cart.hydrated && pickup.ready && !sel && co.status !== 'INITIALIZING') return <Navigate to="/pickup-time" replace state={{ from: location.pathname }} />

  const proceed = async () => {
    setShowIssues(true)
    if (!canContinue) return
    setContinuing(true)
    const req = await co.continueToPayment()
    setContinuing(false)
    // Nothing to pay: there is no payment step to go to (and no payment of zero is ever created).
    if (req && !(backend && req.displayedTotalMinor === 0)) navigate('/payment')
  }
  const issueText = (code: string, detail?: string) => t(`checkout.issue.${code}`, { detail: detail ?? '' }, locale)

  return (
    <>
      <Header />
      <main id="main" className="cart co">
        <div className="cart__grid">
          <div className="cart__main">
            <header className="cart-head">
              <div><h1>{t('checkout.title', undefined, locale)}</h1><p>{t('checkout.lead', undefined, locale)}</p></div>
              {!backend && <span className="cart-mock" title="SERVER-AUTHORITATIVE CHECKOUT = FUTURE BACKEND">{t('checkout.mock', undefined, locale)}</span>}
            </header>

            {/* State notices */}
            {!co.online && <p className="cart-notice cart-notice--error" role="alert"><WarnIcon /> {t('checkout.offline', undefined, locale)}</p>}
            {co.status === 'VALIDATING' && <p className="cart-muted" role="status">{t('checkout.validating', undefined, locale)}</p>}
            {co.status === 'ERROR' && <p className="cart-notice cart-notice--error" role="alert" data-testid="co-error">{co.error ?? t('checkout.error', undefined, locale)} <button type="button" className="cart-link" data-testid="co-retry" onClick={() => { void co.prepare() }}>{t('cartpage.retry', undefined, locale)}</button></p>}
            {backend && co.status !== 'ERROR' && co.error && <p className="cart-notice cart-notice--error" role="alert" data-testid="co-refused">{co.error}</p>}
            {co.status === 'EXPIRED' && <p className="cart-notice cart-notice--error" role="alert" data-testid="co-expired">{t('checkout.expired', undefined, locale)} <button type="button" className="cart-link" data-testid="co-restart" onClick={() => { void co.prepare() }}>{t('checkout.startAgain', undefined, locale)}</button></p>}
            {/* The backend's reasons, each with the way to put it right. */}
            {serverIssues.filter((i) => i.code !== 'checkout_expired').map((i) => (
              <p key={`${i.code}:${i.detail ?? ''}`} className="cart-notice cart-notice--warn" role="alert" data-testid="co-issue" data-code={i.detail ?? i.code} data-recover={i.recover ?? ''}>
                <WarnIcon /> <span>{i.message ?? issueText(i.code, i.detail)}</span>{' '}
                {i.recover === 'cart' && <Link to="/cart" className="cart-link" data-testid="co-recover-cart">{t('checkout.recover.cart', undefined, locale)}</Link>}
                {i.recover === 'pickup' && <Link to="/pickup-time" className="cart-link" data-testid="co-recover-pickup">{t('checkout.recover.pickup', undefined, locale)}</Link>}
                {(i.recover === 'payment' || i.code === 'payment_in_progress') && <Link to="/payment" className="cart-link" data-testid="co-recover-payment">{t('checkout.recover.payment', undefined, locale)}</Link>}
                {(i.recover === 'refresh' || i.recover === 'checkout') && <button type="button" className="cart-link" data-testid="co-recover-refresh" onClick={() => { void co.prepare() }}>{t('checkout.recover.refresh', undefined, locale)}</button>}
              </p>
            ))}
            {co.notice && (
              <div className="cart-notice cart-notice--warn" role="alert" data-testid="co-updated">
                <WarnIcon /> <span>{co.notice.legal ? t('checkout.updated.legal', undefined, locale) : co.notice.message ?? t('checkout.updated', undefined, locale)}</span>
                {co.notice.previousTotalMinor !== null && sv && <span data-testid="co-updated-total"> {t('checkout.updated.total', { before: formatMoney(co.notice.previousTotalMinor, currency, locale), after: formatMoney(sv.pricing.totalMinor, currency, locale) }, locale)}</span>}
              </div>
            )}
            {/* What was different when the backend made this checkout: said before the customer pays. */}
            {sv && sv.changes.map((ch, i) => {
              const before = (ch.previous_unit_price as { amount?: number } | undefined)?.amount
              const after = (ch.unit_price as { amount?: number } | undefined)?.amount
              const text = ch.code === 'PRICE_CHANGED' ? (typeof before === 'number' && typeof after === 'number' ? t('checkout.change.price', { name: String(ch.name ?? ''), before: formatMoney(before, currency, locale), after: formatMoney(after, currency, locale) }, locale) : t('checkout.change.priceGeneric', undefined, locale))
                : ch.code === 'PROMOTION_CHANGED' ? t('checkout.change.promotion', { code: String(ch.promotion_code ?? '') }, locale)
                  : ch.code === 'PRICING_CHANGED' ? t('checkout.updated', undefined, locale) : null
              return text && <p key={`${ch.code}:${i}`} className="cart-notice cart-notice--warn" role="status" data-testid="co-change" data-code={ch.code}><WarnIcon /> {text}</p>
            })}
            {confirmedFree && <p className="cart-notice" role="status" data-testid="co-free-confirmed">{t('checkout.free.confirmed', undefined, locale)}</p>}
            {backend && sv && secondsLeft !== null && secondsLeft > 0 && <p className="cart-muted" role="timer" aria-live="off" data-testid="co-expires" data-seconds={secondsLeft}>{t('checkout.expiresIn', { time: clock(secondsLeft) }, locale)}</p>}
            {co.review?.restaurantIssue === 'not_accepting' && <p className="cart-notice cart-notice--error" role="alert">{t('checkout.issue.restaurant_not_accepting', undefined, locale)} {c && <Link to={`/restaurants/${c.restaurantSlug}`} className="cart-link">{t('cartpage.view', undefined, locale)}</Link>}</p>}
            {co.review?.restaurantIssue === 'inactive' && <p className="cart-notice cart-notice--error" role="alert">{t('checkout.issue.restaurant_unavailable', undefined, locale)} <Link to="/restaurants" className="cart-link">{t('cartpage.change', undefined, locale)}</Link></p>}
            {co.review && co.review.lineIssues.length > 0 && (
              <div className="cart-notice cart-notice--warn" role="alert">
                <WarnIcon /> <span>{co.review.lineIssues.some((i) => i.kind === 'price_changed') ? t('checkout.priceChanged', undefined, locale) : t('checkout.cartInvalid', undefined, locale)}</span>
                <span className="co-notice__actions">
                  {co.review.lineIssues.filter((i) => i.kind === 'price_changed').map((i) => <button key={i.itemId} type="button" className="cart-link" onClick={() => co.acceptPriceChange(i.itemId, i.newUnitMinor ?? 0)}>{t('cartpage.issue.price.accept', undefined, locale)} ({money(i.oldUnitMinor ?? 0)} → {money(i.newUnitMinor ?? 0)})</button>)}
                  <Link to="/cart" className="cart-link">{t('checkout.reviewCart', undefined, locale)}</Link>
                </span>
              </div>
            )}
            {co.pickupResult && !co.pickupResult.ok && <p className="cart-notice cart-notice--warn" role="alert"><WarnIcon /> {t(`pickup.stale.${co.pickupResult.reason}`, undefined, locale)} <Link to="/pickup-time" className="cart-link" onClick={() => pickup.clear()}>{t('pickup.chooseAnother', undefined, locale)}</Link></p>}

            {/* 1. Customer */}
            <section className="cart-card" aria-labelledby="co-cust">
              <div className="co-sec__head"><h2 id="co-cust"><UserIcon /> {t('checkout.customer', undefined, locale)}</h2><Link to="/my-profile" className="cart-link" aria-label={t('checkout.customer.editLabel', undefined, locale)}><PenIcon /> {t('checkout.edit', undefined, locale)}</Link></div>
              <dl className="co-dl">
                <div><dt>{t('checkout.customer.name', undefined, locale)}</dt><dd dir="auto">{name || '—'}</dd></div>
                <div><dt>{t('checkout.customer.phone', undefined, locale)}</dt><dd data-testid="co-phone">{phone} {(!sv || sv.customer.phoneVerified) && <span className="co-badge co-badge--ok">{t('checkout.customer.verified', undefined, locale)}</span>}</dd></div>
                {email && <div><dt>{t('checkout.customer.email', undefined, locale)}</dt><dd>{email}</dd></div>}
              </dl>
              <p className="cart-muted">{t('checkout.customer.phoneNote', undefined, locale)}</p>
            </section>

            {/* 2. Restaurant */}
            <section className="cart-card" aria-labelledby="co-rest">
              <div className="co-sec__head"><h2 id="co-rest"><ShopIcon /> {t('checkout.restaurant', undefined, locale)}</h2>{c && <Link to={`/restaurants/${c.restaurantSlug}`} className="cart-link">{t('cartpage.view', undefined, locale)}</Link>}</div>
              <p className="co-rest__name" dir="auto" data-testid="co-restaurant">{sv?.restaurant.name ?? c?.restaurantName}</p>
              {r && (
                <>
                  <p className="cart-rest-row__addr"><PinIcon size={16} /> <span dir="auto">{r.address.formatted}</span></p>
                  <p className="cart-rest-row__meta">
                    {availability && <span className={`cart-status cart-status--${availability.status}`}>{t(`card.${availability.status === 'closing_soon' ? 'closingSoon' : availability.status === 'opening_soon' ? 'openingSoon' : availability.status === 'temporarily_closed' ? 'temporarilyClosed' : availability.status}`, undefined, locale)}</span>}
                    {!r.acceptingOrders && <span className="cart-status cart-status--off">{t('card.notAcceptingOrders', undefined, locale)}</span>}
                  </p>
                  <p className="cart-muted">{t('rd.info.contactNone', undefined, locale)}</p>
                </>
              )}
            </section>

            {/* 3. Pickup */}
            <section className="cart-card" aria-labelledby="co-pick">
              <div className="co-sec__head"><h2 id="co-pick"><ClockIcon size={18} /> {t('checkout.pickup', undefined, locale)}</h2><Link to="/pickup-time" className="cart-link" aria-label={t('checkout.pickup.changeLabel', undefined, locale)}><PenIcon /> {t('checkout.pickup.change', undefined, locale)}</Link></div>
              {sv ? (
                <dl className="co-dl" data-testid="co-pickup" data-mode={sv.pickup.mode} data-held={sv.pickup.held ? 'true' : 'false'}>
                  <div><dt>{t('pickup.date', undefined, locale)}</dt><dd>{formatLocalDate(sv.pickup.pickupAt, tz, locale)}</dd></div>
                  <div><dt>{t('pickup.time', undefined, locale)}</dt><dd data-testid="co-pickup-time">{sv.pickup.mode === 'asap' ? `${t('pickup.asap', undefined, locale)} · ~${time(sv.pickup.pickupAt)}` : time(sv.pickup.pickupAt)} <small>{zoneLabel(sv.pickup.pickupAt, tz, locale)} · {tz}</small></dd></div>
                  {sv.pickup.estimatedPrepMinutes !== null && <div><dt>{t('pickup.ready', undefined, locale)}</dt><dd>~{time(sv.pickup.pickupAt)} · {t('cartpage.prep', { minutes: formatMinutes(sv.pickup.estimatedPrepMinutes, locale) }, locale)}</dd></div>}
                  {sv.pickup.travelerArrivalAt && <div><dt>{t('pickup.arrival', undefined, locale)}</dt><dd>~{time(sv.pickup.travelerArrivalAt)}</dd></div>}
                  {journey && <div><dt>{t('cartpage.journey', undefined, locale)}</dt><dd dir="auto">{t('cartpage.journey.text', { origin: journey.origin.name, destination: journey.destination.name }, locale)}</dd></div>}
                  {sv.pickup.held && sv.pickup.holdExpiresAt && <div><dt /><dd data-testid="co-hold">{t('checkout.held', { time: time(sv.pickup.holdExpiresAt) }, locale)}</dd></div>}
                </dl>
              ) : sel && !backend ? (
                <dl className="co-dl">
                  <div><dt>{t('pickup.date', undefined, locale)}</dt><dd>{formatLocalDate(sel.requestedAt, tz, locale)}</dd></div>
                  <div><dt>{t('pickup.time', undefined, locale)}</dt><dd>{sel.mode === 'asap' ? `${t('pickup.asap', undefined, locale)} · ~${time(sel.requestedAt)}` : time(sel.requestedAt)} <small>{zoneLabel(sel.requestedAt, tz, locale)} · {tz}</small></dd></div>
                  <div><dt>{t('pickup.ready', undefined, locale)}</dt><dd>~{time(sel.estimatedReadyTime)}{r && ` · ${t('cartpage.prep', { minutes: formatMinutes(r.prepTimeMin, locale) }, locale)}`}</dd></div>
                  {sel.estimatedCustomerArrival && <div><dt>{t('pickup.arrival', undefined, locale)}</dt><dd>~{time(sel.estimatedCustomerArrival)} <small>{t('mock.estimate', undefined, locale)}</small></dd></div>}
                  {journey && <div><dt>{t('cartpage.journey', undefined, locale)}</dt><dd dir="auto">{t('cartpage.journey.text', { origin: journey.origin.name, destination: journey.destination.name }, locale)}</dd></div>}
                </dl>
              ) : <p className="cart-muted">{t('checkout.pickup.none', undefined, locale)}</p>}
              <p className="cart-muted">{t('cartpage.pickupOnly', undefined, locale)}</p>
            </section>

            {/* 4. Items */}
            <section className="cart-card" aria-labelledby="co-items">
              <div className="co-sec__head"><h2 id="co-items"><BagIcon /> {t('checkout.items', { count: sv?.itemCount ?? cart.count }, locale)}</h2><Link to="/cart" className="cart-link">{t('checkout.editCart', undefined, locale)}</Link></div>
              <ul className="cart-lines co-lines" data-testid="co-items-list">
                {/* API mode: the lines as the backend priced them. */}
                {sv && sv.items.map((line) => (
                  <li key={line.id} className="cart-line co-line" aria-label={line.name} data-testid="co-line">
                    <Img src={line.image} fallback={initials(line.name)} />
                    <div className="cart-line__info">
                      <h3 dir="auto">{line.name} <span className="co-qty">× {line.quantity}</span></h3>
                      {line.options.length > 0 && <ul className="cart-line__opts">{line.options.map((o) => <li key={o.key} dir="auto"><span className="cart-line__group">{o.groupName}:</span> {o.optionName}</li>)}</ul>}
                      {line.specialInstructions && <p className="cart-line__note" dir="auto">“{line.specialInstructions}”</p>}
                      <p className="cart-line__unit" data-testid="co-line-unit" data-minor={line.unitPriceMinor}>{t('cartpage.each', { price: money(line.unitPriceMinor) }, locale)}</p>
                    </div>
                    <span className="cart-line__price"><b data-testid="co-line-total" data-minor={line.lineSubtotalMinor}>{money(line.lineSubtotalMinor)}</b></span>
                  </li>
                ))}
                {!backend && (c?.items ?? []).map((line) => (
                  <li key={line.id} className="cart-line co-line" aria-label={line.itemName}>
                    <Img src={line.image} fallback={line.fallback} />
                    <div className="cart-line__info">
                      <h3 dir="auto">{line.itemName} <span className="co-qty">× {line.quantity}</span></h3>
                      {[...line.selectedVariants, ...line.selectedModifiers].length > 0 && <ul className="cart-line__opts">{[...line.selectedVariants, ...line.selectedModifiers].map((o) => <li key={`${o.groupId}:${o.optionId}`} dir="auto"><span className="cart-line__group">{o.groupName}:</span> {o.optionName}</li>)}</ul>}
                      {line.specialInstructions && <p className="cart-line__note" dir="auto">“{line.specialInstructions}”</p>}
                      <p className="cart-line__unit">{t('cartpage.each', { price: money(line.unitPriceMinor) }, locale)} · <Link to={`/restaurants/${c?.restaurantSlug}/item/${line.itemSlug}?edit=${encodeURIComponent(line.id)}`} className="cart-link" aria-label={t('cartpage.editLabel', { name: line.itemName }, locale)}>{t('cartpage.edit', undefined, locale)}</Link></p>
                    </div>
                    <span className="cart-line__price"><b>{money(line.lineTotalMinor)}</b></span>
                  </li>
                ))}
              </ul>
            </section>

            {/* 5. Promo */}
            <section className="cart-card" aria-labelledby="co-promo">
              <h2 id="co-promo"><TagIcon /> {t('cartpage.promo', undefined, locale)}</h2>
              <form className="cart-promo" onSubmit={(e) => { e.preventDefault(); void co.applyPromo(promoInput) }}>
                <div className="cart-promo__row">
                  <input id="co-promo-input" data-testid="co-promo-input" aria-label={t('cartpage.promo', undefined, locale)} value={promoInput} onChange={(e) => setPromoInput(e.target.value)} autoComplete="off" maxLength={40} disabled={co.promoBusy || (backend && !sv)} />
                  <button type="submit" className="btn btn--outline" data-testid="co-promo-apply" disabled={co.promoBusy || !promoInput.trim() || (backend && !sv)}>{co.promoBusy ? t('checkout.promo.applying', undefined, locale) : t('cartpage.promo.apply', undefined, locale)}</button>
                </div>
                {/* API mode: the applied code and its discount are the backend checkout's own — shown even when the last attempt was refused. */}
                {backend && sv?.promotion && <p className="cart-promo__msg cart-promo__msg--ok" role="status" data-testid="co-promo-applied" data-code={sv.promotion.code}>{t('checkout.promo.appliedServer', { code: sv.promotion.code, amount: money(sv.promotion.discountMinor) }, locale)} <button type="button" className="cart-link" data-testid="co-promo-remove" disabled={co.promoBusy} onClick={() => { co.removePromo(); setPromoInput('') }}>{t('cartpage.promo.remove', undefined, locale)}</button></p>}
                {!backend && co.promo?.status === 'applied' && <p className="cart-promo__msg cart-promo__msg--ok" role="status">{t('checkout.promo.applied', { code: co.promo.code, amount: money(co.promo.discountMinor) }, locale)} <button type="button" className="cart-link" onClick={() => { co.removePromo(); setPromoInput('') }}>{t('cartpage.promo.remove', undefined, locale)}</button></p>}
                {co.promo && co.promo.status !== 'applied' && co.promo.status !== 'idle' && co.promo.status !== 'applying' && <p className="cart-promo__msg cart-promo__msg--bad" role="alert" data-testid="co-promo-error" data-status={co.promo.status}>{t(`checkout.promo.${co.promo.status}`, { amount: money(co.promo.minimumSpendMinor ?? 0) }, locale)}</p>}
              </form>
              <p className="cart-muted">{t(backend ? 'checkout.promo.noteServer' : 'checkout.promo.note', undefined, locale)}</p>
            </section>

            {/* 6. Order note */}
            <section className="cart-card cart-note" aria-labelledby="co-note">
              <h2 id="co-note"><NoteIcon /> {t('checkout.note', undefined, locale)}</h2>
              <label className="cart-note__field">
                <textarea id="co-order-note" data-testid="co-note-input" value={cart.note} maxLength={200} rows={2} placeholder={t('checkout.note.placeholder', undefined, locale)} onChange={(e) => cart.setNote(e.target.value)} aria-label={t('checkout.note', undefined, locale)} />
                <small>{cart.note.length}/200</small>
              </label>
              <p className="cart-muted">{t('cartpage.note.hint', undefined, locale)}</p>
            </section>

            {/* 7. Legal */}
            <section className="cart-card" aria-labelledby="co-legal">
              <h2 id="co-legal"><ShieldIcon /> {t('checkout.legal', undefined, locale)}</h2>
              <label className="co-check">
                <input type="checkbox" data-testid="co-terms" checked={co.termsAccepted} disabled={backend && !sv} onChange={(e) => co.setTermsAccepted(e.target.checked)} aria-describedby="co-legal-links" />
                {backend && sv ? (
                  // The documents, and the versions, are the ones the backend requires for this checkout — what is ticked is what is recorded.
                  <span id="co-legal-links" data-testid="co-legal-docs">{t('checkout.legal.accept', undefined, locale)}{' '}
                    {sv.legal.required.map((d, i) => (
                      <span key={d.type} data-testid="co-legal-doc" data-type={d.type} data-version={d.version} data-status={d.status}>
                        {i > 0 && ', '}<Link to={`/${d.slug}`} target="_blank" rel="noopener">{t(`checkout.legal.doc.${d.type}`, undefined, locale)}</Link> <small>({t('checkout.legal.version', { version: d.version }, locale)})</small>
                      </span>
                    ))}.
                  </span>
                ) : (
                  <span id="co-legal-links">{t('checkout.legal.text', undefined, locale)} <Link to="/terms" target="_blank" rel="noopener">{t('checkout.legal.terms', undefined, locale)}</Link>, <Link to="/privacy" target="_blank" rel="noopener">{t('checkout.legal.privacy', undefined, locale)}</Link> {t('checkout.legal.and', undefined, locale)} <Link to="/refund-policy" target="_blank" rel="noopener">{t('checkout.legal.refund', undefined, locale)}</Link>.</span>
                )}
              </label>
              {(!sv || !sv.legal.finalApproval) && <p className="cart-muted" data-testid="co-legal-draft">{t('checkout.legal.draft', undefined, locale)}</p>}
              {showIssues && !co.termsAccepted && <p className="cart-line__issue" role="alert">{t('checkout.issue.terms', undefined, locale)}</p>}
            </section>

            {/* 8. Payment method entry point */}
            <section className="cart-card" aria-labelledby="co-pay">
              <h2 id="co-pay"><CardIcon /> {t('checkout.payment', undefined, locale)}</h2>
              {free ? <p className="cart-muted" role="status" data-testid="co-pay-free">{t('checkout.payment.free', undefined, locale)}</p> : co.methods.length === 0 ? <p className="cart-muted" role="status">{t('checkout.payment.loading', undefined, locale)}</p> : (
                <div className="co-methods" role="radiogroup" aria-label={t('checkout.payment', undefined, locale)} data-testid="co-methods">
                  {co.methods.map((m) => (
                    <label key={m.id} className={`co-method ${co.paymentMethodId === m.id ? 'is-on' : ''} ${m.enabled ? '' : 'is-off'}`}>
                      <input type="radio" name="co-method" value={m.id} data-testid={`co-method-${m.id.startsWith('saved:') ? 'saved' : m.id}`} checked={co.paymentMethodId === m.id} disabled={!m.enabled} onChange={() => co.setPaymentMethod(m.id)} />
                      <span><b>{m.label}</b>{m.description && <small>{m.id.startsWith('saved:') ? t('checkout.payment.saved', undefined, locale) : m.description}</small>}{!m.enabled && <small>{m.reasonDisabled}</small>}</span>
                    </label>
                  ))}
                </div>
              )}
              <p className="cart-muted">{t('checkout.payment.note', undefined, locale)}</p>
              {backend && sv && sv.paymentMethods.providerStatus !== 'READY' && !free && <p className="cart-notice cart-notice--warn" role="status" data-testid="co-provider-unavailable">{t('checkout.payment.unavailable', undefined, locale)}</p>}
            </section>
          </div>

          {/* Summary */}
          <aside className="cart__side">
            <section className="cart-card cart-summary" aria-labelledby="co-sum">
              <h2 id="co-sum">{t('checkout.summary', undefined, locale)}</h2>
              {co.summary ? (
                // API mode: every line is a figure the backend sent. A tax or fee line exists only when a configured rule produced it.
                <dl className="cart-sum" data-testid="co-summary" data-source={co.summary.source} data-currency={co.summary.currency}>
                  <div><dt>{t('cartpage.subtotal', undefined, locale)} · {co.summary.itemCount}</dt><dd data-testid="co-subtotal" data-minor={co.summary.subtotalMinor}>{money(co.summary.subtotalMinor)}</dd></div>
                  {co.summary.discountMinor > 0 && <div className="cart-sum__disc"><dt>{t('cartpage.discount', { code: (backend ? sv?.promotion?.code : co.promo?.code) ?? '' }, locale)}</dt><dd data-testid="co-discount" data-minor={co.summary.discountMinor}>−{money(co.summary.discountMinor)}</dd></div>}
                  {co.summary.fees.map((l) => <div key={l.id} data-testid="co-fee" data-code={l.id}><dt>{l.label}</dt><dd data-minor={l.amountMinor}>{money(l.amountMinor)}</dd></div>)}
                  {co.summary.taxes.map((l) => <div key={l.id} data-testid="co-tax" data-code={l.id} data-included={l.included ? 'true' : 'false'}><dt>{l.rateBasisPoints === undefined ? l.label : t(l.included ? 'checkout.tax.included' : 'checkout.tax.rate', { label: l.label, rate: percent(l.rateBasisPoints) }, locale)}</dt><dd data-minor={l.amountMinor}>{money(l.amountMinor)}</dd></div>)}
                  <div className="cart-sum__total"><dt>{t('checkout.total', undefined, locale)}</dt><dd data-testid="co-total" data-minor={co.summary.totalMinor}>{money(co.summary.totalMinor)}</dd></div>
                </dl>
              ) : <p className="cart-muted" role="status">{t('checkout.preparing', undefined, locale)}</p>}
              {backend ? (
                sv && <>
                  {sv.pricing.taxes.length === 0 && sv.pricing.fees.length === 0 && <p className="cart-muted" data-testid="co-config-none">{t('checkout.config.none', undefined, locale)}</p>}
                  {(sv.pricing.configuration.tax === 'TEST' || sv.pricing.configuration.fees === 'TEST') && <p className="cart-muted" data-testid="co-config-test"><b>{t('checkout.config.test', undefined, locale)}</b></p>}
                  <p className="cart-muted">{t('checkout.serverTotal', undefined, locale)}</p>
                </>
              ) : (
                <>
                  <p className="cart-muted">{co.summary && co.summary.taxes.length === 0 && co.summary.fees.length === 0 ? t('checkout.noFees', undefined, locale) : t('checkout.feesConfigured', undefined, locale)}</p>
                  <p className="cart-muted">{t('checkout.serverNote', undefined, locale)}</p>
                </>
              )}
              {showIssues && blocking.length > 0 && (
                <ul className="co-issues" aria-label={t('checkout.issues', undefined, locale)} data-testid="co-blocking">
                  {blocking.map((i) => <li key={`${i.code}:${i.detail ?? ''}`} role="alert" data-code={i.code}>{i.message ?? issueText(i.code, i.detail)}</li>)}
                </ul>
              )}
              {/* API mode: the amount on the button is the backend checkout's total. Without a current checkout there is nothing to continue with. */}
              <button type="button" className="btn btn--primary cart-proceed" data-testid="co-continue" data-status={co.status} data-amount={sv?.pricing.totalMinor ?? ''}
                disabled={continuing || co.status === 'VALIDATING' || !co.online || confirmedFree || (backend && (sv === null || ['EXPIRED', 'ERROR', 'INVALID_CART', 'INVALID_PICKUP', 'OFFLINE', 'REQUIRES_AUTH'].includes(co.status)))}
                onClick={() => { void proceed() }}>
                {continuing ? t('checkout.preparingPayment', undefined, locale) : backend && sv ? (free ? t('checkout.pay.free', undefined, locale) : t('checkout.pay.cta', { amount: money(sv.pricing.totalMinor) }, locale)) : t('checkout.continue', undefined, locale)}
              </button>
              <p className="cart-muted">{t('checkout.secureNote', undefined, locale)}</p>
              <Link to="/pickup-time" className="cart-link">{t('checkout.back', undefined, locale)}</Link>
            </section>
          </aside>
        </div>
      </main>
    </>
  )
}

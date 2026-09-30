import { marketRepository } from '../market/mock/mockMarket'
import { useEffect, useMemo, useState } from 'react'
import { Link, Navigate, useLocation, useNavigate } from 'react-router-dom'
import Header from '../components/Header'
import { ClockIcon, PinIcon } from '../components/Icons'
import { useAuth } from '../auth/AuthContext'
import { useCart } from '../cart/CartContext'
import { useCheckout } from '../checkout/CheckoutContext'
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

function Img({ src, fallback }: { src: string; fallback: string }) {
  return (<span className="cart-line__img co-line__img"><img src={src} alt="" loading="lazy" onError={(e) => { e.currentTarget.style.display = 'none' }} /><span aria-hidden="true">{fallback}</span></span>)
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
  const { prepare } = co

  useEffect(() => { document.title = `${t('checkout.title', undefined, locale)} · FoodOnTheGo` }, [locale])
  // Prepare whenever the inputs that matter change (cart lines, pickup selection, auth, connectivity).
  useEffect(() => { if (cart.hydrated) void prepare() }, [prepare, cart.hydrated, cartKey, pickup.selection?.requestedAt, auth.isAuthenticated, co.online]) // eslint-disable-line react-hooks/exhaustive-deps

  const r = co.restaurant
  const tz = pickup.selection?.restaurantTimezone ?? r?.timezone ?? 'UTC'
  const currency = c?.currency ?? marketRepository.getActiveMarket().defaultCurrency
  const money = (m: number) => formatMoney(m, currency, locale)
  const time = (iso: string) => formatLocalTime(iso, tz, locale)
  const availability = useMemo(() => (r ? computeAvailability(r, new Date().toISOString()) : null), [r])
  const blocking = co.issues.filter((i) => i.blocking)
  const canContinue = co.status === 'READY' && blocking.length === 0 && !continuing
  const sel = pickup.selection
  const name = profile?.name ?? auth.user?.name ?? ''
  const phone = profile?.phone ?? auth.user?.phone ?? ''
  const email = profile?.email ?? auth.user?.email ?? ''

  if (cart.hydrated && (!c || c.items.length === 0)) return <Navigate to="/cart" replace />
  if (cart.hydrated && !sel && co.status !== 'INITIALIZING') return <Navigate to="/pickup-time" replace state={{ from: location.pathname }} />

  const proceed = async () => {
    setShowIssues(true)
    if (!canContinue) return
    setContinuing(true)
    const req = await co.continueToPayment()
    setContinuing(false)
    if (req) navigate('/payment')
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
              <span className="cart-mock" title="SERVER-AUTHORITATIVE CHECKOUT = FUTURE BACKEND">{t('checkout.mock', undefined, locale)}</span>
            </header>

            {/* State notices */}
            {!co.online && <p className="cart-notice cart-notice--error" role="alert"><WarnIcon /> {t('checkout.offline', undefined, locale)}</p>}
            {co.status === 'VALIDATING' && <p className="cart-muted" role="status">{t('checkout.validating', undefined, locale)}</p>}
            {co.status === 'ERROR' && <p className="cart-notice cart-notice--error" role="alert">{co.error ?? t('checkout.error', undefined, locale)} <button type="button" className="cart-link" onClick={() => { void co.prepare() }}>{t('cartpage.retry', undefined, locale)}</button></p>}
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
                <div><dt>{t('checkout.customer.phone', undefined, locale)}</dt><dd>{phone} <span className="co-badge co-badge--ok">{t('checkout.customer.verified', undefined, locale)}</span></dd></div>
                {email && <div><dt>{t('checkout.customer.email', undefined, locale)}</dt><dd>{email}</dd></div>}
              </dl>
              <p className="cart-muted">{t('checkout.customer.phoneNote', undefined, locale)}</p>
            </section>

            {/* 2. Restaurant */}
            <section className="cart-card" aria-labelledby="co-rest">
              <div className="co-sec__head"><h2 id="co-rest"><ShopIcon /> {t('checkout.restaurant', undefined, locale)}</h2>{c && <Link to={`/restaurants/${c.restaurantSlug}`} className="cart-link">{t('cartpage.view', undefined, locale)}</Link>}</div>
              <p className="co-rest__name" dir="auto">{c?.restaurantName}</p>
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
              {sel ? (
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
              <div className="co-sec__head"><h2 id="co-items"><BagIcon /> {t('checkout.items', { count: cart.count }, locale)}</h2><Link to="/cart" className="cart-link">{t('checkout.editCart', undefined, locale)}</Link></div>
              <ul className="cart-lines co-lines">
                {(c?.items ?? []).map((line) => (
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
                  <input id="co-promo-input" aria-label={t('cartpage.promo', undefined, locale)} value={promoInput} onChange={(e) => setPromoInput(e.target.value)} autoComplete="off" disabled={co.promoBusy} />
                  <button type="submit" className="btn btn--outline" disabled={co.promoBusy || !promoInput.trim()}>{co.promoBusy ? t('checkout.promo.applying', undefined, locale) : t('cartpage.promo.apply', undefined, locale)}</button>
                </div>
                {co.promo?.status === 'applied' && <p className="cart-promo__msg cart-promo__msg--ok" role="status">{t('checkout.promo.applied', { code: co.promo.code, amount: money(co.promo.discountMinor) }, locale)} <button type="button" className="cart-link" onClick={() => { co.removePromo(); setPromoInput('') }}>{t('cartpage.promo.remove', undefined, locale)}</button></p>}
                {co.promo && co.promo.status !== 'applied' && co.promo.status !== 'idle' && co.promo.status !== 'applying' && <p className="cart-promo__msg cart-promo__msg--bad" role="alert">{t(`checkout.promo.${co.promo.status}`, { amount: money(co.promo.minimumSpendMinor ?? 0) }, locale)}</p>}
              </form>
              <p className="cart-muted">{t('checkout.promo.note', undefined, locale)}</p>
            </section>

            {/* 6. Order note */}
            <section className="cart-card cart-note" aria-labelledby="co-note">
              <h2 id="co-note"><NoteIcon /> {t('checkout.note', undefined, locale)}</h2>
              <label className="cart-note__field">
                <textarea id="co-order-note" value={cart.note} maxLength={200} rows={2} placeholder={t('checkout.note.placeholder', undefined, locale)} onChange={(e) => cart.setNote(e.target.value)} aria-label={t('checkout.note', undefined, locale)} />
                <small>{cart.note.length}/200</small>
              </label>
              <p className="cart-muted">{t('cartpage.note.hint', undefined, locale)}</p>
            </section>

            {/* 7. Legal */}
            <section className="cart-card" aria-labelledby="co-legal">
              <h2 id="co-legal"><ShieldIcon /> {t('checkout.legal', undefined, locale)}</h2>
              <label className="co-check">
                <input type="checkbox" checked={co.termsAccepted} onChange={(e) => co.setTermsAccepted(e.target.checked)} aria-describedby="co-legal-links" />
                <span id="co-legal-links">{t('checkout.legal.text', undefined, locale)} <Link to="/terms" target="_blank" rel="noopener">{t('checkout.legal.terms', undefined, locale)}</Link>, <Link to="/privacy" target="_blank" rel="noopener">{t('checkout.legal.privacy', undefined, locale)}</Link> {t('checkout.legal.and', undefined, locale)} <Link to="/refund-policy" target="_blank" rel="noopener">{t('checkout.legal.refund', undefined, locale)}</Link>.</span>
              </label>
              <p className="cart-muted">{t('checkout.legal.draft', undefined, locale)}</p>
              {showIssues && !co.termsAccepted && <p className="cart-line__issue" role="alert">{t('checkout.issue.terms', undefined, locale)}</p>}
            </section>

            {/* 8. Payment method entry point */}
            <section className="cart-card" aria-labelledby="co-pay">
              <h2 id="co-pay"><CardIcon /> {t('checkout.payment', undefined, locale)}</h2>
              {co.methods.length === 0 ? <p className="cart-muted" role="status">{t('checkout.payment.loading', undefined, locale)}</p> : (
                <div className="co-methods" role="radiogroup" aria-label={t('checkout.payment', undefined, locale)}>
                  {co.methods.map((m) => (
                    <label key={m.id} className={`co-method ${co.paymentMethodId === m.id ? 'is-on' : ''} ${m.enabled ? '' : 'is-off'}`}>
                      <input type="radio" name="co-method" value={m.id} checked={co.paymentMethodId === m.id} disabled={!m.enabled} onChange={() => co.setPaymentMethod(m.id)} />
                      <span><b>{m.label}</b>{m.description && <small>{m.description}</small>}{!m.enabled && <small>{m.reasonDisabled}</small>}</span>
                    </label>
                  ))}
                </div>
              )}
              <p className="cart-muted">{t('checkout.payment.note', undefined, locale)}</p>
            </section>
          </div>

          {/* Summary */}
          <aside className="cart__side">
            <section className="cart-card cart-summary" aria-labelledby="co-sum">
              <h2 id="co-sum">{t('checkout.summary', undefined, locale)}</h2>
              {co.summary ? (
                <dl className="cart-sum">
                  <div><dt>{t('cartpage.subtotal', undefined, locale)} · {co.summary.itemCount}</dt><dd>{money(co.summary.subtotalMinor)}</dd></div>
                  {co.summary.discountMinor > 0 && <div className="cart-sum__disc"><dt>{t('cartpage.discount', { code: co.promo?.code ?? '' }, locale)}</dt><dd>−{money(co.summary.discountMinor)}</dd></div>}
                  {co.summary.taxes.map((l) => <div key={l.id}><dt>{l.label}</dt><dd>{money(l.amountMinor)}</dd></div>)}
                  {co.summary.fees.map((l) => <div key={l.id}><dt>{l.label}</dt><dd>{money(l.amountMinor)}</dd></div>)}
                  <div className="cart-sum__total"><dt>{t('checkout.total', undefined, locale)}</dt><dd>{money(co.summary.totalMinor)}</dd></div>
                </dl>
              ) : <p className="cart-muted" role="status">{t('checkout.preparing', undefined, locale)}</p>}
              <p className="cart-muted">{co.summary && co.summary.taxes.length === 0 && co.summary.fees.length === 0 ? t('checkout.noFees', undefined, locale) : t('checkout.feesConfigured', undefined, locale)}</p>
              <p className="cart-muted">{t('checkout.serverNote', undefined, locale)}</p>
              {showIssues && blocking.length > 0 && (
                <ul className="co-issues" aria-label={t('checkout.issues', undefined, locale)}>
                  {blocking.map((i) => <li key={i.code} role="alert">{issueText(i.code, i.detail)}</li>)}
                </ul>
              )}
              <button type="button" className="btn btn--primary cart-proceed" disabled={continuing || co.status === 'VALIDATING' || !co.online} onClick={() => { void proceed() }}>{continuing ? t('checkout.preparingPayment', undefined, locale) : t('checkout.continue', undefined, locale)}</button>
              <p className="cart-muted">{t('checkout.secureNote', undefined, locale)}</p>
              <Link to="/pickup-time" className="cart-link">{t('checkout.back', undefined, locale)}</Link>
            </section>
          </aside>
        </div>
      </main>
    </>
  )
}

import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, Navigate, useNavigate } from 'react-router-dom'
import Header from '../components/Header'
import { ClockIcon, PinIcon } from '../components/Icons'
import { useCart } from '../cart/CartContext'
import { readStaleSimulation, reviewCart } from '../cart/cartValidation'
import { useJourney } from '../journey/JourneyContext'
import { menuRepository } from '../menu/menuRepository'
import { usePickup } from '../pickup/PickupContext'
import type { PickupSlot } from '../pickup/repositories'
import { formatLocalDate, localDateOf } from '../pickup/time'
import { restaurantRepository } from '../repositories'
import type { Restaurant } from '../repositories/types'
import { computeAvailability } from '../repositories/mock/restaurants'
import { formatLocalTime, formatMinutes, formatMoney, zoneLabel } from '../i18n/format'
import { t, useLocale } from '../i18n/strings'
import './CartPage.css'
import './PickupTimePage.css'

/**
 * Pickup Time Selection (Module 10) — canonical /pickup-time. Cart → context (restaurant, journey ETA) →
 * ASAP or scheduled slots (restaurant-local day + data-driven slots) → validation → summary → Continue to checkout.
 * Every time is an instant in the RESTAURANT zone; display is locale-aware. Nothing here is paid or ordered.
 */
type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const })
const CarIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M5 11l1.5-4.5A2 2 0 0 1 8.4 5h7.2a2 2 0 0 1 1.9 1.5L19 11M4 11h16v6H4zM6 17v2M18 17v2" /></svg>)
const BoltIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M13 2 4 14h6l-1 8 9-12h-6l1-8Z" /></svg>)
const CalIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><rect x="3" y="5" width="18" height="16" rx="2" /><path d="M3 10h18M8 3v4M16 3v4" /></svg>)
const WarnIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M12 3 2 20h20L12 3Zm0 6v5m0 3v.5" /></svg>)

export default function PickupTimePage() {
  const cart = useCart()
  const pickup = usePickup()
  const { locale } = useLocale()
  const { journey } = useJourney()
  const navigate = useNavigate()
  const [restaurant, setRestaurant] = useState<Restaurant | null>(null)
  const [cartIssue, setCartIssue] = useState<string | null>(null)
  const [continuing, setContinuing] = useState(false)
  const c = cart.cart
  const slug = c?.restaurantSlug ?? null
  const cartId = c?.id ?? null
  const { load, date: pickupDate } = pickup

  useEffect(() => { let alive = true; if (!slug) return; restaurantRepository.getRestaurantBySlug(slug).then((r) => { if (alive) setRestaurant(r) }).catch(() => { if (alive) setRestaurant(null) }); return () => { alive = false } }, [slug])
  useEffect(() => { document.title = `${t('pickup.title', undefined, locale)} · FoodOnTheGo` }, [locale])
  // Load settings / estimate / slots whenever the restaurant, cart or journey changes.
  useEffect(() => { if (restaurant && c && cartId) void load({ restaurant, cart: c, journey, prepMinutes: restaurant.prepTimeMin }) }, [restaurant, cartId, journey, load]) // eslint-disable-line react-hooks/exhaustive-deps
  const reload = useCallback((date?: string) => { if (restaurant && c) void load({ restaurant, cart: c, journey, prepMinutes: restaurant.prepTimeMin, date: date ?? pickupDate ?? undefined }) }, [restaurant, c, journey, load, pickupDate])

  const availability = useMemo(() => (restaurant ? computeAvailability(restaurant, new Date().toISOString()) : null), [restaurant])
  const tz = restaurant?.timezone ?? 'UTC'
  const [nowIso] = useState(() => new Date().toISOString())
  const zone = restaurant ? zoneLabel(nowIso, tz, locale) : ''
  const time = (iso: string) => formatLocalTime(iso, tz, locale)
  const day = (iso: string) => formatLocalDate(iso, tz, locale)
  const sel = pickup.selection
  const asapAllowed = !!pickup.settings?.modes.includes('asap') && !!availability && ['open', 'closing_soon'].includes(availability.status) && !!restaurant?.acceptingOrders
  const scheduledAllowed = !!pickup.settings?.modes.includes('scheduled')
  const availableSlots = pickup.slots.filter((s) => s.available)
  const recommended = pickup.slots.find((s) => s.recommended) ?? null
  const blockedReason = !restaurant ? null : restaurant.status !== 'active' ? 'inactive' : (!restaurant.acceptingOrders || readStaleSimulation() === 'not_accepting') ? 'not_accepting' : null

  const guidance = useMemo(() => {
    if (!sel || !pickup.eta) return null
    const arrive = Date.parse(pickup.eta), ready = Date.parse(sel.estimatedReadyTime)
    if (arrive < ready - 5 * 60000) return 'before'
    if (arrive > ready + 15 * 60000) return 'after'
    return 'aligned'
  }, [sel, pickup.eta])

  const proceed = async () => {
    if (!restaurant || !c || !sel) return
    setContinuing(true); setCartIssue(null)
    try {
      const review = await reviewCart(c, restaurant, menuRepository, readStaleSimulation())
      if (review.blocking) { setCartIssue(t('pickup.cartInvalid', undefined, locale)); setContinuing(false); return }
      const res = await pickup.validate(restaurant)
      if (res.ok) navigate('/checkout')
    } catch { /* status → ERROR handled below */ }
    setContinuing(false)
  }

  if (!cart.hydrated) return (<><Header /><main id="main" className="cart pk"><p className="cart-muted" role="status" style={{ padding: 24 }}>{t('pickup.loadingSlots', undefined, locale)}</p></main></>)
  if (!c || c.items.length === 0) return <Navigate to="/cart" replace />

  const slotButton = (s: PickupSlot) => {
    const on = sel?.slotId === s.id
    const label = `${time(s.startAt)}${s.recommended ? ` · ${t('pickup.recommended', undefined, locale)}` : ''}${s.capacityStatus === 'limited' ? ` · ${t('pickup.limited', undefined, locale)}` : ''}${!s.available ? ` · ${t(`pickup.reason.${s.reasonUnavailable ?? 'closed'}`, undefined, locale)}` : ''}`
    return (
      <span key={s.id} className="pk-slot-cell">
        <button type="button" role="radio" aria-checked={on} aria-label={label} disabled={!s.available} className={`pk-slot ${on ? 'is-on' : ''} ${s.recommended ? 'is-rec' : ''} pk-slot--${s.capacityStatus}`} onClick={() => pickup.selectSlot(s)}>
          <b>{time(s.startAt)}</b>
          {s.recommended && <span className="pk-badge pk-badge--rec">{t('pickup.recommended', undefined, locale)}</span>}
          {s.available && s.capacityStatus === 'limited' && <span className="pk-badge pk-badge--lim">{t('pickup.limited', undefined, locale)}</span>}
          {!s.available && <span className="pk-badge pk-badge--off">{t(`pickup.reason.${s.reasonUnavailable ?? 'closed'}`, undefined, locale)}</span>}
        </button>
      </span>
    )
  }

  return (
    <>
      <Header />
      <main id="main" className="cart pk">
        <div className="cart__grid">
          <div className="cart__main">
            <header className="cart-head">
              <div><h1>{t('pickup.title', undefined, locale)}</h1><p>{t('pickup.lead2', undefined, locale)}</p></div>
              <span className="cart-mock" title="REAL PICKUP SLOT ENGINE = FUTURE BACKEND">{t('pickup.mock', undefined, locale)}</span>
            </header>

            <section className="cart-card" aria-labelledby="pk-ctx-title">
              <p className="cart-eyebrow" id="pk-ctx-title">{t('cartpage.restaurant', undefined, locale)}</p>
              <div className="cart-rest-row">
                <div className="cart-rest-row__info">
                  <h2 dir="auto">{c.restaurantName}</h2>
                  {restaurant && (
                    <>
                      <p className="cart-rest-row__addr"><PinIcon size={16} /> <span dir="auto">{restaurant.address.formatted}</span></p>
                      <p className="cart-rest-row__meta">
                        {availability && <span className={`cart-status cart-status--${availability.status}`}>{t(`card.${availability.status === 'closing_soon' ? 'closingSoon' : availability.status === 'opening_soon' ? 'openingSoon' : availability.status === 'temporarily_closed' ? 'temporarilyClosed' : availability.status}`, undefined, locale)}</span>}
                        {availability?.nextChangeAt && <span>{t(availability.status === 'open' || availability.status === 'closing_soon' ? 'card.closesAt' : 'card.opensAt', { time: time(availability.nextChangeAt) }, locale)}</span>}
                        <span className="pk-zone">{t('pickup.zone', { zone, tz }, locale)}</span>
                        {!restaurant.acceptingOrders && <span className="cart-status cart-status--off">{t('card.notAcceptingOrders', undefined, locale)}</span>}
                      </p>
                      <p className="cart-rest-row__pickup"><ClockIcon size={16} /> {t('cartpage.prep', { minutes: formatMinutes(restaurant.prepTimeMin, locale) }, locale)}{pickup.estimate && ` · ${t('pickup.earliestReady', { time: time(pickup.estimate.earliestPickupAt) }, locale)}`}</p>
                      {pickup.settings?.instructions && <p className="cart-muted" dir="auto">{t('pickup.instructions', undefined, locale)}: {pickup.settings.instructions}</p>}
                    </>
                  )}
                </div>
                <div className="cart-rest-row__actions"><Link to="/cart" className="btn btn--outline">{t('pickup.back', undefined, locale)}</Link></div>
              </div>
              {journey ? (
                <p className="cart-journey"><CarIcon /> <b dir="auto">{t('cartpage.journey.text', { origin: journey.origin.name, destination: journey.destination.name }, locale)}</b>{pickup.eta && restaurant && <span> · {t('pickup.eta', { time: time(pickup.eta), date: day(pickup.eta) }, locale)} <em>{t('mock.estimate', undefined, locale)}</em></span>}</p>
              ) : (
                <p className="cart-journey cart-journey--none"><CarIcon /> {t('pickup.noJourney', undefined, locale)} <Link to="/plan-journey">{t('cartpage.empty.plan', undefined, locale)}</Link></p>
              )}
            </section>

            {blockedReason === 'inactive' && <p className="cart-notice cart-notice--error" role="alert">{t('cartpage.restaurant.inactive', undefined, locale)} <Link to="/restaurants" className="cart-link">{t('cartpage.change', undefined, locale)}</Link></p>}
            {blockedReason === 'not_accepting' && <p className="cart-notice cart-notice--error" role="alert">{t('pickup.notAccepting', undefined, locale)} <Link to={`/restaurants/${c.restaurantSlug}`} className="cart-link">{t('cartpage.view', undefined, locale)}</Link> · <Link to="/restaurants" className="cart-link">{t('cartpage.change', undefined, locale)}</Link></p>}
            {pickup.status === 'STALE' && <p className="cart-notice cart-notice--warn" role="alert"><WarnIcon /> {t(`pickup.stale.${pickup.staleReason ?? 'slot_unavailable'}`, undefined, locale)} <button type="button" className="cart-link" onClick={() => { pickup.clear(); reload() }}>{t('pickup.chooseAnother', undefined, locale)}</button></p>}
            {pickup.status === 'ERROR' && <p className="cart-notice cart-notice--error" role="alert">{pickup.error ?? t('pickup.error', undefined, locale)} <button type="button" className="cart-link" onClick={() => reload()}>{t('cartpage.retry', undefined, locale)}</button></p>}
            {cartIssue && <p className="cart-notice cart-notice--error" role="alert">{cartIssue} <Link to="/cart" className="cart-link">{t('pickup.back', undefined, locale)}</Link></p>}

            {!blockedReason && (
              <section className="cart-card" aria-labelledby="pk-when-title">
                <h2 id="pk-when-title">{t('pickup.when', undefined, locale)}</h2>
                {pickup.settings && pickup.settings.modes.length > 1 && (
                  <div className="pk-modes" role="radiogroup" aria-label={t('pickup.mode', undefined, locale)}>
                    <button type="button" role="radio" aria-checked={pickup.mode === 'asap'} className={pickup.mode === 'asap' ? 'is-on' : ''} onClick={() => pickup.setMode('asap')}><BoltIcon /> {t('pickup.asap', undefined, locale)}</button>
                    <button type="button" role="radio" aria-checked={pickup.mode === 'scheduled'} className={pickup.mode === 'scheduled' ? 'is-on' : ''} onClick={() => pickup.setMode('scheduled')}><CalIcon /> {t('pickup.scheduled', undefined, locale)}</button>
                  </div>
                )}
                {pickup.status === 'LOADING_SLOTS' && <p className="cart-muted" role="status">{t('pickup.loadingSlots', undefined, locale)}</p>}
                {pickup.status === 'VALIDATING' && <p className="cart-muted" role="status">{t('pickup.validating', undefined, locale)}</p>}

                {pickup.mode === 'asap' && pickup.settings && (
                  <div className="pk-asap">
                    {asapAllowed && pickup.estimate ? (
                      <>
                        <p className="pk-asap__lead">{t('pickup.asap.text', { time: time(pickup.estimate.earliestPickupAt), zone }, locale)}</p>
                        <p className="cart-muted">{t('pickup.asap.note', { prep: formatMinutes(pickup.estimate.prepMinutes, locale), buffer: formatMinutes(pickup.estimate.bufferMinutes, locale) }, locale)}</p>
                        <button type="button" className={`btn ${sel?.mode === 'asap' ? 'btn--primary' : 'btn--outline'}`} aria-pressed={sel?.mode === 'asap'} onClick={() => pickup.selectAsap()}>{sel?.mode === 'asap' ? t('pickup.asap.selected', undefined, locale) : t('pickup.asap.choose', undefined, locale)}</button>
                      </>
                    ) : (
                      <p className="cart-notice cart-notice--info" role="status">{t('pickup.asap.closed', undefined, locale)} {scheduledAllowed && <button type="button" className="cart-link" onClick={() => pickup.setMode('scheduled')}>{t('pickup.scheduleLater', undefined, locale)}</button>}</p>
                    )}
                  </div>
                )}

                {pickup.mode === 'scheduled' && pickup.settings && (
                  <>
                    <div className="pk-days" role="radiogroup" aria-label={t('pickup.date', undefined, locale)}>
                      {pickup.days.map((d) => (
                        <button key={d} type="button" role="radio" aria-checked={pickup.date === d} className={pickup.date === d ? 'is-on' : ''} onClick={() => pickup.setDate(d)}>{d === localDateOf(nowIso, tz) ? t('pickup.today', undefined, locale) : formatLocalDate(`${d}T12:00:00Z`, 'UTC', locale)}</button>
                      ))}
                    </div>
                    <p className="cart-muted">{t('pickup.slotsNote', { interval: formatMinutes(pickup.settings.intervalMinutes, locale), zone }, locale)}</p>
                    {pickup.status !== 'LOADING_SLOTS' && pickup.slots.length > 0 && (
                      <div className="pk-slots" role="radiogroup" aria-label={t('pickup.time', undefined, locale)}>{pickup.slots.map(slotButton)}</div>
                    )}
                    {pickup.status !== 'LOADING_SLOTS' && availableSlots.length === 0 && (
                      <div className="pk-empty" role="status">
                        <b>{t('pickup.empty.title', undefined, locale)}</b>
                        <p>{availability && !['open', 'closing_soon'].includes(availability.status) ? t('pickup.empty.closed', undefined, locale) : t('pickup.empty.text', undefined, locale)}</p>
                        <div className="cart-empty__actions">
                          {pickup.days.length > 1 && pickup.date === pickup.days[0] && <button type="button" className="btn btn--outline" onClick={() => pickup.setDate(pickup.days[1])}>{t('pickup.empty.nextDay', undefined, locale)}</button>}
                          <Link to={`/restaurants/${c.restaurantSlug}`} className="btn btn--outline">{t('cartpage.view', undefined, locale)}</Link>
                          <Link to="/restaurants" className="cart-link">{t('cartpage.change', undefined, locale)}</Link>
                        </div>
                      </div>
                    )}
                    {recommended && pickup.eta && <p className="cart-muted">{t('pickup.recommendedNote', { time: time(recommended.startAt) }, locale)}</p>}
                  </>
                )}
              </section>
            )}
          </div>

          <aside className="cart__side">
            <section className="cart-card cart-summary" aria-labelledby="pk-sum-title">
              <h2 id="pk-sum-title">{t('pickup.summary', undefined, locale)}</h2>
              {sel ? (
                <dl className="cart-sum pk-sum">
                  <div><dt>{t('cartpage.restaurant', undefined, locale)}</dt><dd dir="auto">{c.restaurantName}</dd></div>
                  <div><dt>{t('pickup.date', undefined, locale)}</dt><dd>{day(sel.requestedAt)}</dd></div>
                  <div><dt>{t('pickup.time', undefined, locale)}</dt><dd>{sel.mode === 'asap' ? `${t('pickup.asap', undefined, locale)} · ~${time(sel.requestedAt)}` : time(sel.requestedAt)} <small>{zone}</small></dd></div>
                  <div><dt>{t('pickup.ready', undefined, locale)}</dt><dd>~{time(sel.estimatedReadyTime)}</dd></div>
                  {sel.estimatedCustomerArrival && <div><dt>{t('pickup.arrival', undefined, locale)}</dt><dd>~{time(sel.estimatedCustomerArrival)} <small>{t('mock.estimate', undefined, locale)}</small></dd></div>}
                  <div className="cart-sum__total"><dt>{t('cartpage.estimated', undefined, locale)} · {cart.count}</dt><dd>{formatMoney(Math.max(0, cart.subtotalMinor - cart.discountMinor), c.currency, locale)}</dd></div>
                </dl>
              ) : (
                <p className="cart-muted" role="status">{t('pickup.summary.none', undefined, locale)}</p>
              )}
              {guidance === 'before' && <p className="cart-notice cart-notice--info" role="status">{t('pickup.guidance.before', undefined, locale)}</p>}
              {guidance === 'after' && <p className="cart-notice cart-notice--info" role="status">{t('pickup.guidance.after', undefined, locale)}</p>}
              {sel && <p className="cart-muted">{t('pickup.earlyNote', undefined, locale)}</p>}
              {sel && <button type="button" className="cart-link" onClick={() => pickup.clear()}>{t('pickup.changeTime', undefined, locale)}</button>}
              <button type="button" className="btn btn--primary cart-proceed" disabled={!sel || !!blockedReason || continuing || pickup.status === 'VALIDATING' || pickup.status === 'STALE'} onClick={() => { void proceed() }}>{t('pickup.continue', undefined, locale)}</button>
              <p className="cart-muted">{t('pickup.authNote', undefined, locale)}</p>
            </section>
          </aside>
        </div>
      </main>
    </>
  )
}

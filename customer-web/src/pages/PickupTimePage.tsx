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
import { track } from '../analytics'
import './CartPage.css'
import './PickupTimePage.css'

/**
 * Pickup Time Selection (Module 10) — canonical /pickup-time. Cart → context (restaurant, journey ETA) →
 * ASAP or scheduled slots (restaurant-local day + data-driven slots) → validation → summary → Continue to checkout.
 * Every time is an instant in the RESTAURANT zone; display is locale-aware. Nothing here is paid or ordered.
 *
 * With the backend (Module 28, pickup.source === 'api') every time, capacity state, recommendation and reason on this
 * page is the server's answer — the page formats and sends the choice; it never works a time out, and it never says a
 * time is reserved (a selection is a choice until the order exists).
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
  const { load, refresh, date: pickupDate } = pickup
  const backend = pickup.source === 'api'
  const server = pickup.server
  const cartVersion = c?.server?.version ?? null

  useEffect(() => { let alive = true; if (!slug) return; restaurantRepository.getRestaurantBySlug(slug).then((r) => { if (alive) setRestaurant(r) }).catch(() => { if (alive) setRestaurant(null) }); return () => { alive = false } }, [slug])
  useEffect(() => { document.title = `${t('pickup.title', undefined, locale)} · FoodOnTheGo` }, [locale])
  // Module 38 (telemetry, never awaited): the pickup time page is on the screen for this cart's restaurant. Once per restaurant per session.
  const viewedRestaurant = cart.hydrated && c && c.items.length > 0 ? c.server?.restaurantPublicId ?? c.restaurantSlug : null
  useEffect(() => { if (viewedRestaurant) track('pickup_viewed', { restaurant: viewedRestaurant }, undefined, `pickup_viewed:${viewedRestaurant}`) }, [viewedRestaurant])
  // Load settings / estimate / slots whenever the restaurant, cart or journey changes.
  // With the backend the cart's version is part of the question: another item or quantity may have moved the earliest time.
  useEffect(() => { if (restaurant && c && cartId) void load({ restaurant, cart: c, journey, prepMinutes: restaurant.prepTimeMin }) }, [restaurant, cartId, cartVersion, journey, load]) // eslint-disable-line react-hooks/exhaustive-deps
  // An answer is a snapshot of the clock, of capacity and of the restaurant: ask again when the backend says it has aged
  // (the interval is the backend's own — the device clock is not consulted) and when the tab comes back.
  const generatedAt = server?.generatedAt ?? null
  const revalidateAfter = server?.revalidateAfter ?? null
  useEffect(() => {
    if (!backend || !generatedAt || !revalidateAfter) return
    const wait = Math.max(15000, Date.parse(revalidateAfter) - Date.parse(generatedAt))
    const id = setTimeout(() => { if (document.visibilityState === 'visible') void refresh() }, wait)
    return () => clearTimeout(id)
  }, [backend, generatedAt, revalidateAfter, refresh])
  useEffect(() => {
    if (!backend) return
    const onVisible = () => { if (document.visibilityState === 'visible') void refresh() }
    document.addEventListener('visibilitychange', onVisible)
    return () => document.removeEventListener('visibilitychange', onVisible)
  }, [backend, refresh])
  const reload = useCallback((date?: string) => { if (restaurant && c) void load({ restaurant, cart: c, journey, prepMinutes: restaurant.prepTimeMin, date: date ?? pickupDate ?? undefined }) }, [restaurant, c, journey, load, pickupDate])

  const availability = useMemo(() => (restaurant ? computeAvailability(restaurant, new Date().toISOString()) : null), [restaurant])
  const tz = restaurant?.timezone ?? 'UTC'
  const [nowIso] = useState(() => new Date().toISOString())
  const zone = restaurant ? zoneLabel(nowIso, tz, locale) : ''
  const time = (iso: string) => formatLocalTime(iso, tz, locale)
  const day = (iso: string) => formatLocalDate(iso, tz, locale)
  const sel = pickup.selection
  const asapAllowed = backend ? !!server?.asap.available : !!pickup.settings?.modes.includes('asap') && !!availability && ['open', 'closing_soon'].includes(availability.status) && !!restaurant?.acceptingOrders
  const scheduledAllowed = !!pickup.settings?.modes.includes('scheduled')
  const availableSlots = pickup.slots.filter((s) => s.available)
  const recommended = pickup.slots.find((s) => s.recommended) ?? null
  // The backend's reason why nothing can be chosen at all ("no time left" is shown with the slots instead).
  const blocking = backend ? server?.issues.find((i) => i.blocking && i.code !== 'NO_PICKUP_SLOTS') ?? null : null
  const noTimes = backend ? server?.issues.find((i) => i.code === 'NO_PICKUP_SLOTS') ?? null : null
  const blockedReason = backend
    ? (blocking ? (blocking.code === 'RESTAURANT_NOT_ACCEPTING' ? 'not_accepting' : blocking.code === 'CART_INVALID' || blocking.code === 'CART_EXPIRED' ? 'cart' : 'inactive') : null)
    : !restaurant ? null : restaurant.status !== 'active' ? 'inactive' : (!restaurant.acceptingOrders || readStaleSimulation() === 'not_accepting') ? 'not_accepting' : null
  const prepMinutes = backend ? pickup.estimate?.prepMinutes ?? null : restaurant?.prepTimeMin ?? null
  const developmentEstimate = backend ? server?.journey?.source === 'development' : true
  const methodLabel = (type: string) => t(`pickup.method.${type}`, undefined, locale)

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
      if (!backend) {
        const review = await reviewCart(c, restaurant, menuRepository, readStaleSimulation())
        if (review.blocking) { setCartIssue(t('pickup.cartInvalid', undefined, locale)); setContinuing(false); return }
      }
      const res = await pickup.validate(restaurant)
      if (res.ok) navigate('/checkout')
      else if (backend && res.reason === 'cart_invalid') setCartIssue(t('pickup.cartInvalid', undefined, locale))
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
        <button type="button" role="radio" aria-checked={on} aria-label={label} disabled={!s.available || pickup.busy} data-testid="pk-slot" data-slot-start={s.startAt} data-capacity={s.capacityStatus} data-recommended={s.recommended ? 'true' : 'false'} className={`pk-slot ${on ? 'is-on' : ''} ${s.recommended ? 'is-rec' : ''} pk-slot--${s.capacityStatus}`} onClick={() => pickup.selectSlot(s)}>
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
              {!backend && <span className="cart-mock" title="REAL PICKUP SLOT ENGINE = FUTURE BACKEND">{t('pickup.mock', undefined, locale)}</span>}
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
                      <p className="cart-rest-row__pickup"><ClockIcon size={16} /> {prepMinutes !== null && t(backend ? 'pickup.prepEstimate' : 'cartpage.prep', { minutes: formatMinutes(prepMinutes, locale) }, locale)}{pickup.estimate && ` · ${t('pickup.earliestReady', { time: time(pickup.estimate.earliestPickupAt) }, locale)}`}</p>
                      {pickup.settings?.instructions && <p className="cart-muted" dir="auto">{t('pickup.instructions', undefined, locale)}: {pickup.settings.instructions}</p>}
                    </>
                  )}
                </div>
                <div className="cart-rest-row__actions"><Link to="/cart" className="btn btn--outline">{t('pickup.back', undefined, locale)}</Link></div>
              </div>
              {backend ? (
                server?.journey?.status === 'ACTIVE' && server.journey.arrivalAt ? (
                  <p className="cart-journey" data-testid="pk-journey" data-journey-status="ACTIVE"><CarIcon /> <b>{t('pickup.journey.arrival', { time: time(server.journey.arrivalAt), date: day(server.journey.arrivalAt) }, locale)}</b>{developmentEstimate && <span> <em>{t('pickup.estimateDev', undefined, locale)}</em></span>}</p>
                ) : server?.journey?.status === 'STALE' ? (
                  <p className="cart-journey cart-journey--none" data-testid="pk-journey" data-journey-status="STALE"><CarIcon /> {t('pickup.journey.stale', undefined, locale)} <Link to="/plan-journey">{t('pickup.journey.replan', undefined, locale)}</Link></p>
                ) : server?.journey?.status === 'NO_ESTIMATE' ? (
                  <p className="cart-journey cart-journey--none" data-testid="pk-journey" data-journey-status="NO_ESTIMATE"><CarIcon /> {t('pickup.journey.noEstimate', undefined, locale)}</p>
                ) : (
                  <p className="cart-journey cart-journey--none" data-testid="pk-journey" data-journey-status="NONE"><CarIcon /> {t('pickup.noJourney', undefined, locale)} <Link to="/plan-journey">{t('cartpage.empty.plan', undefined, locale)}</Link></p>
                )
              ) : journey ? (
                <p className="cart-journey"><CarIcon /> <b dir="auto">{t('cartpage.journey.text', { origin: journey.origin.name, destination: journey.destination.name }, locale)}</b>{pickup.eta && restaurant && <span> · {t('pickup.eta', { time: time(pickup.eta), date: day(pickup.eta) }, locale)} <em>{t('mock.estimate', undefined, locale)}</em></span>}</p>
              ) : (
                <p className="cart-journey cart-journey--none"><CarIcon /> {t('pickup.noJourney', undefined, locale)} <Link to="/plan-journey">{t('cartpage.empty.plan', undefined, locale)}</Link></p>
              )}
            </section>

            {blockedReason === 'cart' && blocking && <p className="cart-notice cart-notice--error" role="alert" data-testid="pk-blocked" data-code={blocking.code}>{blocking.message} <Link to="/cart" className="cart-link">{t('pickup.reviewCart', undefined, locale)}</Link></p>}
            {blockedReason === 'inactive' && <p className="cart-notice cart-notice--error" role="alert" data-testid="pk-blocked" data-code={blocking?.code ?? 'INACTIVE'}>{blocking?.message ?? t('cartpage.restaurant.inactive', undefined, locale)} <Link to="/restaurants" className="cart-link">{t('cartpage.change', undefined, locale)}</Link></p>}
            {blockedReason === 'not_accepting' && <p className="cart-notice cart-notice--error" role="alert" data-testid="pk-blocked" data-code={blocking?.code ?? 'NOT_ACCEPTING'}>{blocking?.message ?? t('pickup.notAccepting', undefined, locale)} <Link to={`/restaurants/${c.restaurantSlug}`} className="cart-link">{t('cartpage.view', undefined, locale)}</Link> · <Link to="/restaurants" className="cart-link">{t('cartpage.change', undefined, locale)}</Link></p>}
            {pickup.status === 'STALE' && <p className="cart-notice cart-notice--warn" role="alert" data-testid="pk-stale" data-code={server?.selection?.issues[0]?.code ?? pickup.staleReason ?? ''}><WarnIcon /> {server?.selection?.issues[0]?.message ?? t(`pickup.stale.${pickup.staleReason ?? 'slot_unavailable'}`, undefined, locale)} <button type="button" className="cart-link" onClick={() => { pickup.clear(); reload() }}>{t('pickup.chooseAnother', undefined, locale)}</button></p>}
            {pickup.status !== 'ERROR' && pickup.error && <p className="cart-notice cart-notice--warn" role="alert" data-testid="pk-notice">{pickup.error}</p>}
            {pickup.status === 'ERROR' && <p className="cart-notice cart-notice--error" role="alert" data-testid="pk-error">{pickup.error ?? t('pickup.error', undefined, locale)} <button type="button" className="cart-link" onClick={() => reload()}>{t('cartpage.retry', undefined, locale)}</button></p>}
            {cartIssue && <p className="cart-notice cart-notice--error" role="alert">{cartIssue} <Link to="/cart" className="cart-link">{t('pickup.back', undefined, locale)}</Link></p>}

            {/* With the backend nothing is offered from memory: when it cannot be asked, the times of an earlier answer may be full or gone. */}
            {!blockedReason && !(backend && pickup.status === 'ERROR') && (
              <section className="cart-card" aria-labelledby="pk-when-title">
                <h2 id="pk-when-title">{t('pickup.when', undefined, locale)}</h2>
                {pickup.settings && pickup.settings.modes.length > 1 && (
                  <div className="pk-modes" role="radiogroup" aria-label={t('pickup.mode', undefined, locale)}>
                    <button type="button" role="radio" aria-checked={pickup.mode === 'asap'} className={pickup.mode === 'asap' ? 'is-on' : ''} onClick={() => pickup.setMode('asap')}><BoltIcon /> {t('pickup.asap', undefined, locale)}</button>
                    <button type="button" role="radio" aria-checked={pickup.mode === 'scheduled'} className={pickup.mode === 'scheduled' ? 'is-on' : ''} onClick={() => pickup.setMode('scheduled')}><CalIcon /> {t('pickup.scheduled', undefined, locale)}</button>
                  </div>
                )}
                {backend && pickup.settings && pickup.settings.methods.length > 1 && (
                  <div className="pk-modes" role="radiogroup" aria-label={t('pickup.method', undefined, locale)} data-testid="pk-methods">
                    {pickup.settings.methods.map((m) => (
                      <button key={m.id} type="button" role="radio" aria-checked={pickup.method === m.id} className={pickup.method === m.id ? 'is-on' : ''} data-testid={`pk-method-${m.type}`} onClick={() => pickup.setMethod(m.id)}>{methodLabel(m.type)}</button>
                    ))}
                  </div>
                )}
                {pickup.status === 'LOADING_SLOTS' && <p className="cart-muted" role="status">{t('pickup.loadingSlots', undefined, locale)}</p>}
                {pickup.status === 'VALIDATING' && <p className="cart-muted" role="status">{t('pickup.validating', undefined, locale)}</p>}

                {pickup.mode === 'asap' && pickup.settings && (
                  <div className="pk-asap">
                    {asapAllowed && pickup.estimate ? (
                      <>
                        <p className="pk-asap__lead" data-testid="pk-asap-time">{t('pickup.asap.text', { time: time(pickup.estimate.earliestPickupAt), zone }, locale)}{backend && server?.asap.recommended && <span className="pk-badge pk-badge--rec" data-testid="pk-asap-recommended"> {t('pickup.recommended', undefined, locale)}</span>}</p>
                        <p className="cart-muted">{backend ? t('pickup.asap.noteApi', { prep: formatMinutes(pickup.estimate.prepMinutes, locale) }, locale) : t('pickup.asap.note', { prep: formatMinutes(pickup.estimate.prepMinutes, locale), buffer: formatMinutes(pickup.estimate.bufferMinutes, locale) }, locale)}</p>
                        <button type="button" className={`btn ${sel?.mode === 'asap' ? 'btn--primary' : 'btn--outline'}`} aria-pressed={sel?.mode === 'asap'} disabled={pickup.busy} data-testid="pk-asap-choose" onClick={() => pickup.selectAsap()}>{sel?.mode === 'asap' ? t('pickup.asap.selected', undefined, locale) : t('pickup.asap.choose', undefined, locale)}</button>
                      </>
                    ) : (
                      <p className="cart-notice cart-notice--info" role="status" data-testid="pk-asap-unavailable" data-reason={server?.asap.reason ?? ''}>{backend && server?.asap.reason ? t(`pickup.asap.reason.${server.asap.reason}`, undefined, locale) : t('pickup.asap.closed', undefined, locale)} {scheduledAllowed && <button type="button" className="cart-link" onClick={() => pickup.setMode('scheduled')}>{t('pickup.scheduleLater', undefined, locale)}</button>}</p>
                    )}
                  </div>
                )}

                {pickup.mode === 'scheduled' && pickup.settings && (
                  <>
                    <div className="pk-days" role="radiogroup" aria-label={t('pickup.date', undefined, locale)}>
                      {pickup.days.map((d) => (
                        <button key={d} type="button" role="radio" aria-checked={pickup.date === d} className={pickup.date === d ? 'is-on' : ''} data-testid="pk-day" data-date={d} onClick={() => pickup.setDate(d)}>{d === localDateOf(server?.generatedAt ?? nowIso, tz) ? t('pickup.today', undefined, locale) : formatLocalDate(`${d}T12:00:00Z`, 'UTC', locale)}</button>
                      ))}
                    </div>
                    <p className="cart-muted">{t('pickup.slotsNote', { interval: formatMinutes(pickup.settings.intervalMinutes, locale), zone }, locale)}</p>
                    {pickup.status !== 'LOADING_SLOTS' && pickup.slots.length > 0 && (
                      <div className="pk-slots" role="radiogroup" aria-label={t('pickup.time', undefined, locale)}>{pickup.slots.map(slotButton)}</div>
                    )}
                    {pickup.status !== 'LOADING_SLOTS' && availableSlots.length === 0 && (
                      <div className="pk-empty" role="status" data-testid="pk-empty" data-cause={String(noTimes?.details.cause ?? '')}>
                        <b>{t('pickup.empty.title', undefined, locale)}</b>
                        <p>{backend ? (noTimes?.message ?? t('pickup.empty.text', undefined, locale)) : availability && !['open', 'closing_soon'].includes(availability.status) ? t('pickup.empty.closed', undefined, locale) : t('pickup.empty.text', undefined, locale)}</p>
                        <div className="cart-empty__actions">
                          {pickup.days.length > 1 && pickup.date === pickup.days[0] && <button type="button" className="btn btn--outline" onClick={() => pickup.setDate(pickup.days[1])}>{t('pickup.empty.nextDay', undefined, locale)}</button>}
                          <Link to={`/restaurants/${c.restaurantSlug}`} className="btn btn--outline">{t('cartpage.view', undefined, locale)}</Link>
                          <Link to="/restaurants" className="cart-link">{t('cartpage.change', undefined, locale)}</Link>
                        </div>
                      </div>
                    )}
                    {recommended && pickup.eta && (!backend || server?.recommended?.basis === 'ARRIVAL') && <p className="cart-muted" data-testid="pk-recommended-note">{t('pickup.recommendedNote', { time: time(recommended.startAt) }, locale)}</p>}
                  </>
                )}
              </section>
            )}
          </div>

          <aside className="cart__side">
            <section className="cart-card cart-summary" aria-labelledby="pk-sum-title">
              <h2 id="pk-sum-title">{t('pickup.summary', undefined, locale)}</h2>
              {sel ? (
                <dl className="cart-sum pk-sum" data-testid="pk-summary" data-mode={sel.mode} data-pickup-at={sel.requestedAt}>
                  <div><dt>{t('cartpage.restaurant', undefined, locale)}</dt><dd dir="auto">{c.restaurantName}</dd></div>
                  {backend && server?.selection && pickup.settings && pickup.settings.methods.length > 0 && <div><dt>{t('pickup.method', undefined, locale)}</dt><dd>{methodLabel(pickup.settings.methods.find((m) => m.id === server.selection?.method)?.type ?? 'counter')}</dd></div>}
                  <div><dt>{t('pickup.date', undefined, locale)}</dt><dd>{day(sel.requestedAt)}</dd></div>
                  <div><dt>{t('pickup.time', undefined, locale)}</dt><dd>{sel.mode === 'asap' ? `${t('pickup.asap', undefined, locale)} · ~${time(sel.requestedAt)}` : time(sel.requestedAt)} <small>{zone}</small></dd></div>
                  <div><dt>{t('pickup.ready', undefined, locale)}</dt><dd>~{time(sel.estimatedReadyTime)}</dd></div>
                  {sel.estimatedCustomerArrival && <div><dt>{t('pickup.arrival', undefined, locale)}</dt><dd>~{time(sel.estimatedCustomerArrival)} {developmentEstimate && <small>{t(backend ? 'pickup.estimateDev' : 'mock.estimate', undefined, locale)}</small>}</dd></div>}
                  <div className="cart-sum__total"><dt>{t('cartpage.estimated', undefined, locale)} · {cart.count}</dt><dd>{formatMoney(Math.max(0, cart.subtotalMinor - cart.discountMinor), c.currency, locale)}</dd></div>
                </dl>
              ) : (
                <p className="cart-muted" role="status">{t('pickup.summary.none', undefined, locale)}</p>
              )}
              {guidance === 'before' && <p className="cart-notice cart-notice--info" role="status">{t('pickup.guidance.before', undefined, locale)}</p>}
              {guidance === 'after' && <p className="cart-notice cart-notice--info" role="status">{t('pickup.guidance.after', undefined, locale)}</p>}
              {sel && backend && <p className="cart-muted" data-testid="pk-not-reserved">{t('pickup.notReserved', undefined, locale)}</p>}
              {sel && <p className="cart-muted">{t('pickup.earlyNote', undefined, locale)}</p>}
              {sel && <button type="button" className="cart-link" onClick={() => pickup.clear()}>{t('pickup.changeTime', undefined, locale)}</button>}
              <button type="button" className="btn btn--primary cart-proceed" disabled={!sel || !!blockedReason || continuing || pickup.busy || pickup.status === 'VALIDATING' || pickup.status === 'STALE' || (backend && pickup.status === 'ERROR')} data-testid="pk-continue" onClick={() => { void proceed() }}>{t('pickup.continue', undefined, locale)}</button>
              <p className="cart-muted">{t('pickup.authNote', undefined, locale)}</p>
            </section>
          </aside>
        </div>
      </main>
    </>
  )
}

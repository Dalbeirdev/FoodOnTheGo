import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import Header from '../components/Header'
import { ClockIcon, PinIcon } from '../components/Icons'
import { useCart } from '../cart/CartContext'
import type { CartItem } from '../cart/cartModel'
import { estimatedTotalMinor, readStaleSimulation, reviewCart, type CartReview, type LineIssue } from '../cart/cartValidation'
import { useJourney } from '../journey/JourneyContext'
import { menuRepository } from '../menu/mock/mockMenu'
import { restaurantRepository } from '../repositories'
import type { Restaurant } from '../repositories/types'
import { computeAvailability, routeContextFor } from '../repositories/mock/restaurants'
import { formatDistance, formatLocalTime, formatMinutes, formatMoney, zoneLabel } from '../i18n/format'
import { resolveUnitSystem } from '../i18n/markets'
import { t, useLocale } from '../i18n/strings'
import './CartPage.css'

/**
 * Cart (Module 09) — /cart on the Module 08 centralized cart. One restaurant per cart, structured
 * lines with Edit / Remove / quantity, restaurant + pickup + journey context, validation against the
 * current menu (stale prices, unavailable items / options, restaurant state), pricing summary with
 * only configured amounts, order note, promo area (development), Continue to pickup time.
 */
type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const })
const TrashIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3" /></svg>)
const PenIcon = ({ size = 16 }: P) => (<svg {...stroke(size)}><path d="M4 20h4l10-10-4-4L4 16v4ZM13 7l4 4" /></svg>)
const PlusIcon = ({ size = 16 }: P) => (<svg {...stroke(size)}><path d="M12 5v14M5 12h14" /></svg>)
const MinusIcon = ({ size = 16 }: P) => (<svg {...stroke(size)}><path d="M5 12h14" /></svg>)
const CarIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M5 11l1.5-4.5A2 2 0 0 1 8.4 5h7.2a2 2 0 0 1 1.9 1.5L19 11M4 11h16v6H4zM6 17v2M18 17v2" /></svg>)
const CartIcon = ({ size = 40 }: P) => (<svg {...stroke(size)}><path d="M3 4h2l2.5 11h11L21 7H7" /><circle cx="9" cy="20" r="1.5" /><circle cx="17" cy="20" r="1.5" /></svg>)
const WarnIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M12 3 2 20h20L12 3Zm0 6v5m0 3v.5" /></svg>)

function Img({ src, fallback, alt = '' }: { src: string; fallback: string; alt?: string }) {
  return (<span className="cart-line__img"><img src={src} alt={alt} loading="lazy" onError={(e) => { e.currentTarget.style.display = 'none' }} /><span aria-hidden="true">{fallback}</span></span>)
}

/** Legacy Module 01 checkout mockup constant — replaced by market pricing policy (CF-107). */
export const GST_RATE = 0.05

export default function CartPage() {
  const cart = useCart()
  const { locale, unitPreference } = useLocale()
  const journeyApi = useJourney()
  const navigate = useNavigate()
  const location = useLocation()
  const [loadedRestaurant, setRestaurant] = useState<Restaurant | null>(null)
  const [review, setReview] = useState<{ status: 'idle' | 'checking' | 'ready' | 'error'; result: CartReview | null }>({ status: 'idle', result: null })
  const [confirmRemove, setConfirmRemove] = useState<string | null>(null)
  const [confirmClear, setConfirmClear] = useState(false)
  const [promoInput, setPromoInput] = useState('')
  const [flash, setFlash] = useState<string | null>((location.state as { updated?: boolean } | null)?.updated ? t('item.edit.saved', undefined, locale) : null)
  const reviewSeq = useRef(0)
  const c = cart.cart
  const items = useMemo(() => c?.items ?? [], [c])
  const journey = journeyApi.journey
  const restaurantSlug = c?.restaurantSlug ?? null

  // Restaurant context from the shared repository (never a stale copy).
  useEffect(() => {
    let alive = true
    if (!restaurantSlug) return
    restaurantRepository.getRestaurantBySlug(restaurantSlug).then((r) => { if (alive) setRestaurant(r) }).catch(() => { if (alive) setRestaurant(null) })
    return () => { alive = false }
  }, [restaurantSlug])
  const restaurant = restaurantSlug && loadedRestaurant?.slug === restaurantSlug ? loadedRestaurant : null

  // Validation against the current menu data (debounced per cart change).
  const runReview = useCallback(async () => {
    const seq = ++reviewSeq.current
    if (!c || items.length === 0) { setReview({ status: 'ready', result: null }); return }
    setReview((r) => ({ status: 'checking', result: r.result }))
    try {
      const result = await reviewCart(c, restaurant, menuRepository, readStaleSimulation())
      if (seq === reviewSeq.current) setReview({ status: 'ready', result })
    } catch {
      if (seq === reviewSeq.current) setReview({ status: 'error', result: null })
    }
  }, [c, items.length, restaurant])
  useEffect(() => { const id = setTimeout(() => { void runReview() }, 50); return () => clearTimeout(id) }, [runReview])
  useEffect(() => { if (!flash) return; const id = setTimeout(() => setFlash(null), 4000); return () => clearTimeout(id) }, [flash])
  useEffect(() => { document.title = `${t('cartpage.title', undefined, locale)} · FoodOnTheGo` }, [locale])

  const availability = useMemo(() => (restaurant ? computeAvailability(restaurant, new Date().toISOString()) : null), [restaurant])
  const route = useMemo(() => (restaurant && journey ? routeContextFor(restaurant, journey) : null), [restaurant, journey])
  const units = resolveUnitSystem(unitPreference, restaurant?.countryCode)
  const currency = c?.currency ?? 'INR'
  const money = (minor: number) => formatMoney(minor, currency, locale)
  const subtotal = cart.subtotalMinor
  const discount = cart.discountMinor
  const total = estimatedTotalMinor(subtotal, discount)
  const result = review.result
  const issueFor = (id: string): LineIssue | undefined => result?.lineIssues.find((i) => i.itemId === id)
  const blocked = !c || items.length === 0 || review.status !== 'ready' || !result || result.blocking
  const earliest = restaurant ? new Date(new Date().getTime() + restaurant.prepTimeMin * 60000).toISOString() : null

  const remove = (id: string) => { cart.removeItem(id); setConfirmRemove(null) }
  const proceed = () => { if (!blocked) navigate('/pickup-time') }

  if (!c || items.length === 0) {
    return (
      <>
        <Header />
        <main id="main" className="cart">
          <div className="cart__grid cart__grid--single">
            <section className="cart-card cart-empty" aria-labelledby="cart-empty-title">
              <span className="cart-empty__icon" aria-hidden="true"><CartIcon /></span>
              <h1 id="cart-empty-title">{t('cartpage.empty.title', undefined, locale)}</h1>
              <p>{t('cartpage.empty.text', undefined, locale)}</p>
              <div className="cart-empty__actions">
                <Link to="/restaurants" className="btn btn--primary">{t('cartpage.empty.explore', undefined, locale)}</Link>
                <Link to="/plan-journey" className="btn btn--outline">{t('cartpage.empty.plan', undefined, locale)}</Link>
              </div>
            </section>
          </div>
        </main>
      </>
    )
  }

  return (
    <>
      <Header />
      <main id="main" className="cart">
        <div className="cart__grid">
          <div className="cart__main">
            <header className="cart-head">
              <div>
                <h1>{t('cartpage.title', undefined, locale)}</h1>
                <p>{t('cartpage.lead', undefined, locale)}</p>
              </div>
              <span className="cart-mock" title="SERVER CART SYNC = NOT STARTED">{t('cartpage.mock', undefined, locale)}</span>
            </header>
            {flash && <p className="cart-flash" role="status">{flash}</p>}

            {/* Restaurant / pickup context */}
            <section className="cart-card cart-rest-card" aria-labelledby="cart-rest-title">
              <p className="cart-eyebrow" id="cart-rest-title">{t('cartpage.restaurant', undefined, locale)}</p>
              <div className="cart-rest-row">
                <div className="cart-rest-row__info">
                  <h2 dir="auto">{c.restaurantName}</h2>
                  {restaurant && (
                    <>
                      <p className="cart-rest-row__addr"><PinIcon size={16} /> <span dir="auto">{restaurant.address.formatted}</span></p>
                      <p className="cart-rest-row__meta">
                        {availability && <span className={`cart-status cart-status--${availability.status}`}>{t(`card.${availability.status === 'closing_soon' ? 'closingSoon' : availability.status === 'opening_soon' ? 'openingSoon' : availability.status === 'temporarily_closed' ? 'temporarilyClosed' : availability.status}`, undefined, locale)}</span>}
                        {availability?.nextChangeAt && <span>{t(availability.status === 'open' || availability.status === 'closing_soon' ? 'card.closesAt' : 'card.opensAt', { time: formatLocalTime(availability.nextChangeAt, restaurant.timezone, locale) }, locale)} {zoneLabel(restaurant.timezone, locale)}</span>}
                        {!restaurant.acceptingOrders && <span className="cart-status cart-status--off">{t('card.notAcceptingOrders', undefined, locale)}</span>}
                      </p>
                      <p className="cart-rest-row__pickup"><ClockIcon size={16} /> {t('cartpage.prep', { minutes: formatMinutes(restaurant.prepTimeMin, locale) }, locale)} · {earliest && t('cartpage.earliest', { time: formatLocalTime(earliest, restaurant.timezone, locale) }, locale)}</p>
                    </>
                  )}
                  <p className="cart-muted">{t('cartpage.pickupOnly', undefined, locale)}</p>
                </div>
                <div className="cart-rest-row__actions">
                  <Link to={`/restaurants/${c.restaurantSlug}`} className="btn btn--outline">{t('cartpage.view', undefined, locale)}</Link>
                  <Link to="/restaurants" className="cart-link">{t('cartpage.change', undefined, locale)}</Link>
                </div>
              </div>
              {journey ? (
                <p className="cart-journey" aria-label={t('cartpage.journey', undefined, locale)}>
                  <CarIcon /> <b dir="auto">{t('cartpage.journey.text', { origin: journey.origin.name, destination: journey.destination.name }, locale)}</b>
                  {route && route.distanceFromRouteM !== null && route.detourDurationMin !== null && route.estimatedArrival && restaurant && <span> · {t('cartpage.journey.route', { distance: formatDistance(route.distanceFromRouteM, units, locale), detour: formatMinutes(route.detourDurationMin, locale), time: formatLocalTime(route.estimatedArrival, restaurant.timezone, locale) }, locale)} <em>{t('mock.estimate', undefined, locale)}</em></span>}
                </p>
              ) : (
                <p className="cart-journey cart-journey--none"><CarIcon /> {t('cartpage.noJourney', undefined, locale)} <Link to="/plan-journey">{t('cartpage.empty.plan', undefined, locale)}</Link></p>
              )}
            </section>

            {/* Review notices */}
            {review.status === 'error' && <p className="cart-notice cart-notice--error" role="alert">{t('cartpage.error', undefined, locale)} <button type="button" className="cart-link" onClick={() => { void runReview() }}>{t('cartpage.retry', undefined, locale)}</button></p>}
            {result && (result.lineIssues.length > 0 || result.currencyMismatch) && <p className="cart-notice cart-notice--warn" role="alert"><WarnIcon /> {t('cartpage.review.title', undefined, locale)}</p>}
            {result?.currencyMismatch && <p className="cart-notice cart-notice--error" role="alert">{t('cartpage.currency', undefined, locale)}</p>}
            {result?.restaurantIssue === 'inactive' && <p className="cart-notice cart-notice--error" role="alert">{t('cartpage.restaurant.inactive', undefined, locale)} <Link to="/restaurants" className="cart-link">{t('cartpage.change', undefined, locale)}</Link></p>}
            {result?.restaurantIssue === 'not_accepting' && <p className="cart-notice cart-notice--error" role="alert">{t('cartpage.restaurant.notAccepting', undefined, locale)} <Link to={`/restaurants/${c.restaurantSlug}`} className="cart-link">{t('cartpage.view', undefined, locale)}</Link> · <Link to="/restaurants" className="cart-link">{t('cartpage.change', undefined, locale)}</Link></p>}
            {result?.restaurantIssue === 'closed' && restaurant && <p className="cart-notice cart-notice--info" role="status">{availability?.nextChangeAt ? t('cartpage.restaurant.closed', { time: `${formatLocalTime(availability.nextChangeAt, restaurant.timezone, locale)} ${zoneLabel(restaurant.timezone, locale)}` }, locale) : t('cartpage.restaurant.closedNoTime', undefined, locale)}</p>}

            {/* Lines */}
            <section className="cart-card" aria-labelledby="cart-items-title">
              <div className="cart-card__head">
                <h2 id="cart-items-title">{t('cartpage.items', { count: cart.count }, locale)}</h2>
                {confirmClear ? (
                  <span className="cart-confirm" role="group" aria-label={t('cartpage.clear.confirm', undefined, locale)}>{t('cartpage.clear.confirm', undefined, locale)} <button type="button" className="cart-link cart-link--danger" onClick={() => { cart.clearCart(); setConfirmClear(false) }}>{t('cartpage.remove.yes', undefined, locale)}</button> <button type="button" className="cart-link" onClick={() => setConfirmClear(false)}>{t('cartpage.remove.no', undefined, locale)}</button></span>
                ) : (
                  <button type="button" className="cart-clear" onClick={() => setConfirmClear(true)}><TrashIcon size={16} /> {t('cartpage.clear', undefined, locale)}</button>
                )}
              </div>
              <ul className="cart-lines">
                {items.map((line: CartItem) => {
                  const issue = issueFor(line.id)
                  const options = [...line.selectedVariants, ...line.selectedModifiers]
                  return (
                    <li key={line.id} className={`cart-line ${issue ? 'cart-line--issue' : ''}`} aria-label={line.itemName}>
                      <Img src={line.image} fallback={line.fallback} />
                      <div className="cart-line__info">
                        <h3 dir="auto">{line.itemName}</h3>
                        {options.length > 0 && (
                          <ul className="cart-line__opts" aria-label="Selected options">
                            {options.map((o) => <li key={`${o.groupId}:${o.optionId}`} dir="auto"><span className="cart-line__group">{o.groupName}:</span> {o.optionName}{o.priceAdjustmentMinor ? <em> ({o.priceAdjustmentMinor > 0 ? '+' : '−'}{money(Math.abs(o.priceAdjustmentMinor))})</em> : null}</li>)}
                          </ul>
                        )}
                        {line.specialInstructions && <p className="cart-line__note" dir="auto">“{line.specialInstructions}”</p>}
                        <p className="cart-line__unit">{t('cartpage.each', { price: money(line.unitPriceMinor) }, locale)}</p>
                        {issue?.kind === 'unavailable' && <p className="cart-line__issue" role="alert">{t('cartpage.issue.unavailable', undefined, locale)} <Link to={`/restaurants/${c.restaurantSlug}`} className="cart-link">{t('cartpage.issue.chooseAnother', undefined, locale)}</Link></p>}
                        {issue?.kind === 'modifier_unavailable' && <p className="cart-line__issue" role="alert">{t('cartpage.issue.modifier', { options: (issue.optionNames ?? []).join(', ') }, locale)}</p>}
                        {issue?.kind === 'price_changed' && <p className="cart-line__issue" role="alert">{t('cartpage.issue.price', { old: money(issue.oldUnitMinor ?? 0), new: money(issue.newUnitMinor ?? 0) }, locale)} <button type="button" className="cart-link" onClick={() => cart.acceptPriceChange(line.id, issue.newUnitMinor ?? line.unitPriceMinor)}>{t('cartpage.issue.price.accept', undefined, locale)}</button></p>}
                        {issue?.kind === 'quantity' && <p className="cart-line__issue" role="alert">{t('cartpage.issue.quantity', { min: issue.min ?? 1, max: issue.max ?? 1 }, locale)}</p>}
                        <div className="cart-line__actions">
                          <Link to={`/restaurants/${c.restaurantSlug}/item/${line.itemSlug}?edit=${encodeURIComponent(line.id)}`} className="cart-link" aria-label={t('cartpage.editLabel', { name: line.itemName }, locale)}><PenIcon /> {t('cartpage.edit', undefined, locale)}</Link>
                          {confirmRemove === line.id ? (
                            <span className="cart-confirm" role="group" aria-label={t('cartpage.remove.confirm', undefined, locale)}>{t('cartpage.remove.confirm', undefined, locale)} <button type="button" className="cart-link cart-link--danger" onClick={() => remove(line.id)}>{t('cartpage.remove.yes', undefined, locale)}</button> <button type="button" className="cart-link" onClick={() => setConfirmRemove(null)}>{t('cartpage.remove.no', undefined, locale)}</button></span>
                          ) : (
                            <button type="button" className="cart-link cart-link--danger" aria-label={t('cartpage.removeLabel', { name: line.itemName }, locale)} onClick={() => setConfirmRemove(line.id)}><TrashIcon size={15} /> {t('cartpage.remove', undefined, locale)}</button>
                          )}
                        </div>
                      </div>
                      <span className="cart-qty" role="group" aria-label={`${t('item.quantity', undefined, locale)} ${line.itemName}`}>
                        <button type="button" aria-label={`${t('item.quantity.decrease', undefined, locale)} ${line.itemName}`} onClick={() => cart.updateQuantity(line.id, line.quantity - 1)}><MinusIcon /></button>
                        <b aria-live="polite">{line.quantity}</b>
                        <button type="button" className="is-plus" aria-label={`${t('item.quantity.increase', undefined, locale)} ${line.itemName}`} disabled={line.quantity >= line.maximumQuantity} onClick={() => cart.updateQuantity(line.id, line.quantity + 1)}><PlusIcon /></button>
                      </span>
                      <span className="cart-line__price"><small>{t('cartpage.lineTotal', undefined, locale)}</small><b>{money(line.lineTotalMinor)}</b></span>
                    </li>
                  )
                })}
              </ul>
              <Link to={`/restaurants/${c.restaurantSlug}`} className="cart-add-more"><PlusIcon /> {t('cartpage.continueShopping', undefined, locale)}</Link>
            </section>

            {/* Order note (restaurant-level, separate from item instructions) */}
            <section className="cart-card cart-note" aria-labelledby="cart-note-title">
              <h2 id="cart-note-title">{t('cartpage.note', undefined, locale)}</h2>
              <label className="cart-note__field">
                <textarea value={cart.note} maxLength={200} rows={3} placeholder={t('cartpage.note.placeholder', undefined, locale)} onChange={(e) => cart.setNote(e.target.value)} aria-label={t('cartpage.note', undefined, locale)} />
                <small>{cart.note.length}/200</small>
              </label>
              <p className="cart-muted">{t('cartpage.note.hint', undefined, locale)}</p>
            </section>
          </div>

          {/* Summary */}
          <aside className="cart__side">
            <section className="cart-card cart-summary" aria-labelledby="cart-sum-title">
              <h2 id="cart-sum-title">{t('cartpage.summary', undefined, locale)}</h2>
              <form className="cart-promo" onSubmit={(e) => { e.preventDefault(); cart.applyPromo(promoInput) }}>
                <label htmlFor="cart-promo">{t('cartpage.promo', undefined, locale)}</label>
                <div className="cart-promo__row">
                  <input id="cart-promo" value={promoInput} onChange={(e) => setPromoInput(e.target.value)} autoComplete="off" />
                  <button type="submit" className="btn btn--outline">{t('cartpage.promo.apply', undefined, locale)}</button>
                </div>
                {cart.promo.status === 'applied' && <p className="cart-promo__msg cart-promo__msg--ok" role="status">{t('cartpage.promo.applied', { code: cart.promo.code, percent: cart.promo.percent }, locale)} <button type="button" className="cart-link" onClick={() => { cart.removePromo(); setPromoInput('') }}>{t('cartpage.promo.remove', undefined, locale)}</button></p>}
                {cart.promo.status === 'invalid' && <p className="cart-promo__msg cart-promo__msg--bad" role="alert">{t('cartpage.promo.invalid', undefined, locale)}</p>}
                {cart.promo.status === 'expired' && <p className="cart-promo__msg cart-promo__msg--bad" role="alert">{t('cartpage.promo.expired', undefined, locale)}</p>}
                {cart.promo.status === 'min_spend' && <p className="cart-promo__msg cart-promo__msg--bad" role="alert">{t('cartpage.promo.minSpend', { amount: money(cart.promo.minSpendMinor) }, locale)}</p>}
              </form>
              <dl className="cart-sum">
                <div><dt>{t('cartpage.subtotal', undefined, locale)} · {cart.count}</dt><dd>{money(subtotal)}</dd></div>
                {discount > 0 && <div className="cart-sum__disc"><dt>{t('cartpage.discount', { code: cart.promo.code }, locale)}</dt><dd>−{money(discount)}</dd></div>}
                <div className="cart-sum__total"><dt>{t('cartpage.estimated', undefined, locale)}</dt><dd>{money(total)}</dd></div>
              </dl>
              <p className="cart-muted">{t('cartpage.feesNote', undefined, locale)}</p>
              {review.status === 'checking' && <p className="cart-muted" role="status">{t('cartpage.loading', undefined, locale)}</p>}
              <button type="button" className="btn btn--primary cart-proceed" onClick={proceed} disabled={blocked} aria-disabled={blocked}>{blocked && result && result.blocking ? t('cartpage.proceed.blocked', undefined, locale) : t('cartpage.proceed', undefined, locale)}</button>
            </section>
          </aside>
        </div>
      </main>
    </>
  )
}

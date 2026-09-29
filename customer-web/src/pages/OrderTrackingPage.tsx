import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import Header from '../components/Header'
import { formatDistance, formatLocalTime, formatMoney, zoneLabel } from '../i18n/format'
import { resolveUnitSystem } from '../i18n/markets'
import { t, useLocale } from '../i18n/strings'
import { formatLocalDate } from '../pickup/time'
import { PickupCode } from '../order/PickupCodeCard'
import { readScenario, setScenario } from '../order/mock/mockTracking'
import type { Order } from '../order/repositories'
import { distanceMeters, pickupVisible, SCENARIOS, timelineFor, type TimelineStage, type TrackingScenario } from '../order/tracking'
import { useOrderTracking, type TrackingDeps } from '../order/useOrderTracking'
import './CartPage.css'
import './CheckoutPage.css'
import './OrderConfirmationPage.css'
import './OrderTrackingPage.css'

/**
 * Live order tracking (Module 14). Canonical route /order-tracking/:orderNumber, driven by structured order events.
 * Order status and payment status stay separate; connection state (live / stale / offline) is shown apart from both.
 * Food-ready ETA and customer-arrival ETA are separate values. No preparation percentages are invented.
 */
type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const, 'aria-hidden': true })
const CheckIcon = ({ size = 16 }: P) => (<svg {...stroke(size)}><path d="m5 12 4 4L19 7" /></svg>)
const ClockIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>)
const PinIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M12 21s7-6.2 7-11.5A7 7 0 0 0 5 9.5C5 14.8 12 21 12 21Z" /><circle cx="12" cy="9.5" r="2.5" /></svg>)
const WarnIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M12 9v4M12 17h.01" /><path d="M10.3 3.9 2.5 18a2 2 0 0 0 1.7 3h15.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" /></svg>)
const XIcon = ({ size = 16 }: P) => (<svg {...stroke(size)}><path d="M6 6l12 12M18 6 6 18" /></svg>)
const RefreshIcon = ({ size = 16 }: P) => (<svg {...stroke(size)}><path d="M20 12a8 8 0 1 1-2.3-5.7" /><path d="M20 4v5h-5" /></svg>)
const DEV = import.meta.env.DEV

export default function OrderTrackingPage({ deps }: { deps?: TrackingDeps }) {
  const { orderNumber = '' } = useParams()
  const { locale, unitPreference } = useLocale()
  const tr = useOrderTracking(orderNumber, deps)
  const [scenario, setScenarioState] = useState<TrackingScenario>(() => readScenario())
  useEffect(() => { document.title = `${t('track.title', undefined, locale)} · FoodOnTheGo` }, [locale])
  const o = tr.order

  return (
    <>
      <Header />
      <main id="main" className="cart trk">
        <div className="cart__grid cart__grid--single">
          <div className="cart__main">
            {tr.connection === 'LOADING' && !o && (
              <section className="cart-card ocp-skeleton" role="status" aria-live="polite" aria-busy="true"><p className="cart-muted">{t('track.loading', undefined, locale)}</p><div className="ocp-skel ocp-skel--title" /><div className="ocp-skel" /><div className="ocp-skel ocp-skel--block" /></section>
            )}
            {tr.connection === 'ERROR' && !o && (
              <section className={`cart-card ocp-state ${tr.loadError === 'not_found' ? 'ocp-state--warn' : 'ocp-state--error'}`} role="alert" aria-labelledby="trk-err">
                <h1 id="trk-err" className="ocp-state__title"><WarnIcon size={22} /> {tr.loadError === 'not_found' ? t('track.notFound.title', undefined, locale) : t('track.failed.title', undefined, locale)}</h1>
                <p>{tr.loadError === 'not_found' ? t('track.notFound.text', { ref: orderNumber }, locale) : t('track.failed.text', undefined, locale)}</p>
                <div className="pay-actions">
                  {tr.loadError !== 'not_found' && <button type="button" className="btn btn--primary" onClick={tr.reconnect}>{t('track.action.retry', undefined, locale)}</button>}
                  <Link to="/my-orders" className="btn btn--outline">{t('oc.action.myOrders', undefined, locale)}</Link>
                  <Link to="/help" className="btn btn--outline">{t('oc.action.help', undefined, locale)}</Link>
                </div>
              </section>
            )}
            {o && <Tracking order={o} tr={tr} locale={locale} unitPreference={unitPreference} />}
            {DEV && o && (
              <section className="cart-card pay-dev" aria-labelledby="trk-dev">
                <h2 id="trk-dev">{t('pay.dev.title', undefined, locale)}</h2>
                <p className="cart-muted">{t('track.dev.text', undefined, locale)}</p>
                <div className="pay-actions">
                  <label className="pay-dev__row">{t('track.dev.scenario', undefined, locale)}
                    <select value={scenario} onChange={(e) => { const v = e.target.value as TrackingScenario; setScenarioState(v); setScenario(v); deps?.tracking?.setScenarioFor?.(o, v) }}>{SCENARIOS.map((s) => <option key={s} value={s}>{s}</option>)}</select>
                  </label>
                  <button type="button" className="btn btn--outline" onClick={() => { void tr.advance() }}>{t('track.dev.advance', undefined, locale)}</button>
                  <button type="button" className="btn btn--outline" onClick={tr.injectDuplicate}>{t('track.dev.duplicate', undefined, locale)}</button>
                  <button type="button" className="btn btn--outline" onClick={tr.injectStale}>{t('track.dev.stale', undefined, locale)}</button>
                </div>
                <p className="cart-muted" data-testid="trk-ignored">{t('track.dev.ignored', { duplicate: tr.ignored.duplicate, stale: tr.ignored.stale }, locale)}</p>
              </section>
            )}
          </div>
        </div>
      </main>
    </>
  )
}

function Tracking({ order: o, tr, locale, unitPreference }: { order: Order; tr: ReturnType<typeof useOrderTracking>; locale: string; unitPreference: 'metric' | 'imperial' | 'auto' }) {
  const tz = o.pickup.restaurantTimezone
  const time = (iso: string) => `${formatLocalTime(iso, tz, locale)} ${zoneLabel(iso, tz, locale)}`
  const money = (m: number) => formatMoney(m, o.pricing.currency, locale)
  const stages = timelineFor(o)
  const s = o.orderStatus
  const exceptional = s === 'REJECTED' || s === 'CANCELLED'
  const ready = s === 'READY_FOR_PICKUP' || s === 'PICKUP_VERIFICATION'
  const done = s === 'PICKED_UP' || s === 'COMPLETED'
  const showCode = pickupVisible(o) && !done
  const units = resolveUnitSystem(unitPreference, o.restaurant.countryCode)
  const dist = o.journey?.originLat != null && o.journey?.originLng != null && o.restaurant.lat != null && o.restaurant.lng != null ? distanceMeters(o.journey.originLat, o.journey.originLng, o.restaurant.lat, o.restaurant.lng) : null
  const arrival = o.pickup.estimatedCustomerArrival ?? null
  const earlyArrival = arrival && o.etaReadyAt && new Date(arrival).getTime() < new Date(o.etaReadyAt).getTime() && !ready && !done && !exceptional
  const mapsHref = o.restaurant.lat != null && o.restaurant.lng != null ? `https://www.google.com/maps/search/?api=1&query=${o.restaurant.lat},${o.restaurant.lng}` : null
  const conn = tr.connection
  const tone = exceptional ? 'error' : ready ? 'ready' : done ? 'done' : o.delayed ? 'warn' : s === 'PAYMENT_PENDING' ? 'warn' : 'live'
  const count = o.items.reduce((a, i) => a + i.quantity, 0)

  return (
    <>
      {/* Connection banner — separate from the order status */}
      {(conn === 'STALE' || conn === 'OFFLINE') && (
        <p className="cart-notice cart-notice--warn trk-conn" role="status" data-testid="trk-connection">
          <WarnIcon /> {conn === 'OFFLINE' ? t('track.conn.offline', undefined, locale) : t('track.conn.stale', undefined, locale)}
          {tr.lastUpdated && <span className="trk-conn__time">{t('track.lastUpdated', { time: formatLocalTime(tr.lastUpdated, Intl.DateTimeFormat().resolvedOptions().timeZone, locale) }, locale)}</span>}
          <button type="button" className="cart-link" onClick={() => { void tr.refresh() }}>{t('track.action.refresh', undefined, locale)}</button>
        </p>
      )}

      <section className={`cart-card trk-head trk-head--${tone}`} aria-labelledby="trk-title" aria-live="polite">
        <div className="trk-head__top">
          <div>
            <p className="cart-eyebrow">{t('track.eyebrow', undefined, locale)}</p>
            <h1 id="trk-title" data-testid="trk-status">{t(`track.status.${s}.title`, undefined, locale)}</h1>
            <p className="trk-head__lead">{t(`track.status.${s}.text`, { restaurant: o.restaurant.name }, locale)}</p>
            {o.delayed && s === 'PREPARING' && <p className="cart-notice cart-notice--warn" role="status" data-testid="trk-delay"><ClockIcon /> {t('track.delay.text', undefined, locale)} {o.delayReasonKey && <span>{t(`track.reason.${o.delayReasonKey}`, undefined, locale)}</span>} {o.etaReadyAt && <b>{t('track.delay.newEta', { time: time(o.etaReadyAt) }, locale)}</b>}</p>}
            {s === 'REJECTED' && <p className="cart-notice cart-notice--error" role="alert">{t('track.rejected.text', undefined, locale)} {o.rejectionReasonKey && t(`track.reason.${o.rejectionReasonKey}`, undefined, locale)}</p>}
            {s === 'CANCELLED' && <p className="cart-notice cart-notice--error" role="alert">{t('track.cancelled.text', undefined, locale)} {o.cancellationReasonKey && t(`track.reason.${o.cancellationReasonKey}`, undefined, locale)}</p>}
            {(s === 'REJECTED' || s === 'CANCELLED') && o.paymentStatus !== 'PAID' && <p className="cart-muted">{t('track.refund.note', { status: t(`oc.pay.${o.paymentStatus}`, undefined, locale) }, locale)}</p>}
            {s === 'PAYMENT_PENDING' && <p className="cart-notice cart-notice--warn" role="status">{t('track.paymentPending.text', undefined, locale)} <Link to="/payment" className="cart-link">{t('oc.action.checkStatus', undefined, locale)}</Link></p>}
          </div>
          <div className="trk-live" data-testid="trk-live">
            <span className={`trk-live__dot trk-live__dot--${conn.toLowerCase()}`} aria-hidden="true" />
            {t(`track.conn.${conn}`, undefined, locale)}
            {tr.lastUpdated && <small>{t('track.lastUpdated', { time: formatLocalTime(tr.lastUpdated, Intl.DateTimeFormat().resolvedOptions().timeZone, locale) }, locale)}</small>}
            <button type="button" className="cart-link" onClick={() => { void tr.refresh() }} aria-label={t('track.action.refresh', undefined, locale)}><RefreshIcon /> {t('track.action.refresh', undefined, locale)}</button>
          </div>
        </div>
        <dl className="ocp-facts trk-facts">
          <div><dt>{t('oc.orderNumber', undefined, locale)}</dt><dd><b className="ocp-number" data-testid="trk-number">{o.orderNumber}</b></dd></div>
          <div><dt>{t('oc.restaurant', undefined, locale)}</dt><dd>{o.restaurant.name}</dd></div>
          <div><dt>{t('oc.orderStatus', undefined, locale)}</dt><dd><span className={`co-badge ${exceptional ? 'co-badge--bad' : 'co-badge--ok'}`} data-testid="trk-order-status">{t(`oc.status.${s}`, undefined, locale)}</span></dd></div>
          <div><dt>{t('oc.paymentStatus', undefined, locale)}</dt><dd><span className={`co-badge ${o.paymentStatus === 'PAID' ? 'co-badge--ok' : 'co-badge--muted'}`} data-testid="trk-payment-status">{t(`oc.pay.${o.paymentStatus}`, undefined, locale)}</span></dd></div>
          <div><dt>{t('pickup.time', undefined, locale)}</dt><dd data-testid="trk-pickup-time">{formatLocalDate(o.pickup.requestedAt, tz, locale)} · {time(o.pickup.requestedAt)}<small>{o.pickup.restaurantTimezone}</small></dd></div>
          <div><dt>{t('track.eta.ready', undefined, locale)}</dt><dd data-testid="trk-eta-ready">{o.etaReadyAt ? `~${time(o.etaReadyAt)}` : '—'}{o.delayed && <small>{t('track.eta.updated', undefined, locale)}</small>}</dd></div>
          {arrival && <div><dt>{t('track.eta.arrival', undefined, locale)}</dt><dd data-testid="trk-eta-arrival">~{time(arrival)}<small>{t('track.eta.arrivalNote', undefined, locale)}</small></dd></div>}
        </dl>
        {earlyArrival && <p className="cart-notice cart-notice--info" role="status">{t('track.earlyArrival', undefined, locale)}</p>}
      </section>

      {/* Timeline */}
      <section className="cart-card" aria-labelledby="trk-timeline">
        <h2 id="trk-timeline">{t('track.timeline', undefined, locale)}</h2>
        <ol className="trk-steps" data-testid="trk-timeline">
          {stages.map((st) => <Stage key={st.key} stage={st} time={time} locale={locale} />)}
        </ol>
        <p className="cart-muted">{t('track.timeline.note', undefined, locale)}</p>
      </section>

      {/* Ready / pickup code */}
      {ready && (
        <section className="cart-card trk-ready" role="status" aria-live="assertive" data-testid="trk-ready">
          <h2><CheckIcon size={22} /> {t('track.ready.title', undefined, locale)}</h2>
          <p>{t('track.ready.text', { restaurant: o.restaurant.name }, locale)}</p>
          <dl className="co-dl">
            <div><dt>{t('oc.pickup.method', undefined, locale)}</dt><dd>{o.pickup.methodLabel}{o.restaurant.pickupLocation && <small>{o.restaurant.pickupLocation}</small>}</dd></div>
            {(o.pickup.instructions || o.restaurant.pickupInstructions) && <div><dt>{t('oc.pickup.instructions', undefined, locale)}</dt><dd>{o.pickup.instructions ?? o.restaurant.pickupInstructions}</dd></div>}
          </dl>
          {s === 'PICKUP_VERIFICATION' && <p className="cart-notice cart-notice--info" role="status">{t('track.verification.text', undefined, locale)}</p>}
        </section>
      )}
      {showCode && <PickupCode verification={tr.verification} locale={locale} emphasis={ready} />}
      {done && (
        <section className="cart-card trk-done" role="status" aria-live="polite" data-testid="trk-done">
          <h2><CheckIcon size={22} /> {s === 'COMPLETED' ? t('track.completed.title', undefined, locale) : t('track.pickedUp.title', undefined, locale)}</h2>
          <p>{s === 'COMPLETED' ? t('track.completed.text', undefined, locale) : t('track.pickedUp.text', undefined, locale)}</p>
          <p className="cart-muted">{t('track.pickedUp.note', undefined, locale)}</p>
          <div className="pay-actions">
            <Link to={`/order/${o.orderNumber}`} className="btn btn--primary">{t('track.action.viewOrder', undefined, locale)}</Link>
            <Link to={`/order-confirmation/${o.orderNumber}`} className="btn btn--outline">{t('track.action.receipt', undefined, locale)}</Link>
            <button type="button" className="btn btn--outline" disabled aria-describedby="trk-rate-note">{t('track.action.rate', undefined, locale)}</button>
            <Link to={`/restaurants/${o.restaurant.slug}`} className="btn btn--outline">{t('track.action.reorder', undefined, locale)}</Link>
          </div>
          <p id="trk-rate-note" className="cart-muted">{t('track.rate.pending', undefined, locale)}</p>
        </section>
      )}

      {/* Restaurant & directions */}
      <section className="cart-card" aria-labelledby="trk-rest">
        <h2 id="trk-rest"><PinIcon /> {t('oc.restaurant', undefined, locale)}</h2>
        <p className="co-rest__name">{o.restaurant.name}</p>
        <p className="cart-rest-row__addr ocp-addr">{o.restaurant.formattedAddress}</p>
        {dist != null && <p className="cart-muted" data-testid="trk-distance">{t('track.distance', { distance: formatDistance(dist, units, locale), origin: o.journey?.originName ?? '' }, locale)}</p>}
        <p className="cart-muted">{o.restaurant.contact ?? t('oc.restaurant.contactNone', undefined, locale)}</p>
        <div className="pay-actions">
          {mapsHref && <a className="btn btn--outline" href={mapsHref} target="_blank" rel="noopener noreferrer">{t('oc.action.directions', undefined, locale)}</a>}
          {o.restaurant.contact && <a className="btn btn--outline" href={`tel:${o.restaurant.contact}`}>{t('track.action.call', undefined, locale)}</a>}
          <Link to="/help" className="btn btn--outline">{t('track.action.support', undefined, locale)}</Link>
        </div>
        <p className="cart-muted">{t('track.location.note', undefined, locale)}</p>
      </section>

      {/* Compact order summary */}
      <section className="cart-card" aria-labelledby="trk-sum">
        <h2 id="trk-sum">{t('track.summary', undefined, locale)}</h2>
        <dl className="co-dl">
          <div><dt>{t('oc.items', { count }, locale)}</dt><dd>{o.items.map((i) => `${i.itemName} × ${i.quantity}`).join(' · ')}</dd></div>
          <div><dt>{t('oc.total', undefined, locale)}</dt><dd><b>{money(o.pricing.totalMinor)}</b> <span className="co-badge co-badge--muted">{o.pricing.currency}</span></dd></div>
          <div><dt>{t('oc.paymentStatus', undefined, locale)}</dt><dd>{t(`oc.pay.${o.paymentStatus}`, undefined, locale)}</dd></div>
        </dl>
        <div className="pay-actions">
          <Link to={`/order/${o.orderNumber}`} className="btn btn--outline">{t('oc.action.details', undefined, locale)}</Link>
          <Link to={`/order-confirmation/${o.orderNumber}`} className="btn btn--outline">{t('track.action.receipt', undefined, locale)}</Link>
        </div>
      </section>

      <section className="cart-card" aria-labelledby="trk-help">
        <h2 id="trk-help">{t('oc.help', undefined, locale)}</h2>
        <p className="cart-muted">{t('track.help.text', undefined, locale)}</p>
        <div className="pay-actions">
          <Link to="/help" className="btn btn--outline">{t('oc.action.help', undefined, locale)}</Link>
          <Link to="/refund-policy" className="btn btn--outline">{t('oc.action.cancellationPolicy', undefined, locale)}</Link>
          <button type="button" className="btn btn--outline" disabled aria-describedby="trk-cancel-note">{t('track.action.cancel', undefined, locale)}</button>
        </div>
        <p id="trk-cancel-note" className="cart-muted">{t('track.cancel.pending', undefined, locale)}</p>
      </section>
    </>
  )
}

function Stage({ stage: st, time, locale }: { stage: TimelineStage; time: (iso: string) => string; locale: string }) {
  const label = t(`track.stage.${st.key}`, undefined, locale)
  const stateLabel = t(`track.stageState.${st.state}`, undefined, locale)
  return (
    <li className={`trk-step trk-step--${st.state}`} aria-current={st.state === 'current' ? 'step' : undefined} data-state={st.state}>
      <span className="trk-step__icon" aria-hidden="true">{st.state === 'done' ? <CheckIcon size={14} /> : st.state === 'stopped' ? <XIcon size={14} /> : null}</span>
      <span className="trk-step__body">
        <b>{label} <span className="trk-sr">({stateLabel})</span></b>
        {st.at && <small>{time(st.at)}</small>}
        {st.note && <small className={`trk-step__note trk-step__note--${st.note}`}>{t(`track.stageNote.${st.note}`, undefined, locale)}</small>}
      </span>
    </li>
  )
}

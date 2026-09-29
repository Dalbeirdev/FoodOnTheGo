import { useEffect, useState } from 'react'
import { Link, Navigate, useParams } from 'react-router-dom'
import Header from '../components/Header'
import { useAuth } from '../auth/AuthContext'
import { formatLocalTime, formatMoney, zoneLabel } from '../i18n/format'
import { t, useLocale } from '../i18n/strings'
import { formatLocalDate } from '../pickup/time'
import { useOrderConfirmation, type OrderConfirmationDeps } from '../order/useOrderConfirmation'
import type { Order, OrderItemSnapshot, OrderPricing, PickupVerification, Receipt } from '../order/repositories'
import { PickupCode } from '../order/PickupCodeCard'
import './CartPage.css'
import './CheckoutPage.css'
import './OrderConfirmationPage.css'

/**
 * Order confirmation (Module 13). Canonical route /order-confirmation/:orderNumber (public reference, never an internal id).
 * Everything shown comes from the order snapshot. Order status and payment status stay separate. The pickup code and QR
 * token are opaque development values — their presence proves nothing; the server validates pickup later (CF-160).
 */
type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const, 'aria-hidden': true })
const CheckIcon = ({ size = 26 }: P) => (<svg {...stroke(size)}><path d="m5 12 4 4L19 7" /></svg>)
const ClockIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>)
const PinIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M12 21s7-6.2 7-11.5A7 7 0 0 0 5 9.5C5 14.8 12 21 12 21Z" /><circle cx="12" cy="9.5" r="2.5" /></svg>)
const WarnIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M12 9v4M12 17h.01" /><path d="M10.3 3.9 2.5 18a2 2 0 0 0 1.7 3h15.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" /></svg>)

export default function OrderConfirmationPage({ deps }: { deps?: OrderConfirmationDeps }) {
  const { orderNumber = '' } = useParams()
  const { locale } = useLocale()
  const auth = useAuth()
  const oc = useOrderConfirmation(orderNumber, deps)
  const [showReceipt, setShowReceipt] = useState(false)
  useEffect(() => { document.title = `${t('oc.title', undefined, locale)} · FoodOnTheGo` }, [locale])
  if (oc.redirectTo) return <Navigate to={oc.redirectTo} replace />

  const o = oc.order
  return (
    <>
      <Header />
      <main id="main" className="cart ocp">
        <div className="cart__grid cart__grid--single">
          <div className="cart__main">
            {oc.status === 'LOADING' && <Loading locale={locale} />}
            {oc.status === 'ORDER_NOT_FOUND' && <StateCard tone="warn" title={t('oc.notFound.title', undefined, locale)} text={t('oc.notFound.text', { ref: orderNumber }, locale)} actions={[['/my-orders', t('oc.action.myOrders', undefined, locale), 'outline'], ['/restaurants', t('oc.action.browse', undefined, locale), 'primary'], ['/help', t('oc.action.help', undefined, locale), 'outline']]} />}
            {oc.status === 'FAILED_TO_LOAD' && <StateCard tone="error" title={t('oc.failed.title', undefined, locale)} text={t('oc.failed.text', undefined, locale)} actions={[['/help', t('oc.action.help', undefined, locale), 'outline']]} retry={oc.reload} retryLabel={t('oc.action.retry', undefined, locale)} />}
            {oc.status === 'PAYMENT_PENDING' && <StateCard tone="warn" title={t('oc.pending.title', undefined, locale)} text={t('oc.pending.text', undefined, locale)} note={t('oc.pending.note', undefined, locale)} actions={[['/payment', t('oc.action.checkStatus', undefined, locale), 'primary'], ['/help', t('oc.action.help', undefined, locale), 'outline']]} order={o} locale={locale} />}
            {oc.status === 'PAYMENT_FAILED' && <StateCard tone="error" title={t('oc.paymentFailed.title', undefined, locale)} text={t('oc.paymentFailed.text', undefined, locale)} actions={[['/payment', t('oc.action.backToPayment', undefined, locale), 'primary'], ['/checkout', t('oc.action.backToCheckout', undefined, locale), 'outline']]} />}
            {oc.status === 'CANCELLED' && o && <StateCard tone="muted" title={t('oc.cancelled.title', undefined, locale)} text={t('oc.cancelled.text', { ref: o.orderNumber }, locale)} note={t('oc.cancelled.note', undefined, locale)} actions={[['/help', t('oc.action.help', undefined, locale), 'primary'], ['/refund-policy', t('oc.action.cancellationPolicy', undefined, locale), 'outline'], ['/restaurants', t('oc.action.browse', undefined, locale), 'outline']]} order={o} locale={locale} />}
            {oc.status === 'CONFIRMED' && o && <Confirmed order={o} verification={oc.verification} receipt={oc.receipt} locale={locale} customerName={auth.user?.name ?? null} showReceipt={showReceipt} setShowReceipt={setShowReceipt} />}
          </div>
        </div>
      </main>
    </>
  )
}

function Loading({ locale }: { locale: string }) {
  return (
    <section className="cart-card ocp-skeleton" role="status" aria-live="polite" aria-busy="true">
      <p className="cart-muted">{t('oc.loading', undefined, locale)}</p>
      <div className="ocp-skel ocp-skel--title" /><div className="ocp-skel" /><div className="ocp-skel ocp-skel--short" /><div className="ocp-skel ocp-skel--block" />
    </section>
  )
}

type Action = [string, string, 'primary' | 'outline']
function StateCard({ tone, title, text, note, actions, retry, retryLabel, order, locale }: { tone: 'warn' | 'error' | 'muted'; title: string; text: string; note?: string; actions: Action[]; retry?: () => void; retryLabel?: string; order?: Order | null; locale?: string }) {
  return (
    <section className={`cart-card ocp-state ocp-state--${tone}`} role={tone === 'error' ? 'alert' : 'status'} aria-labelledby="ocp-state-title">
      <h1 id="ocp-state-title" className="ocp-state__title"><WarnIcon size={22} /> {title}</h1>
      <p>{text}</p>
      {note && <p className="cart-notice cart-notice--warn">{note}</p>}
      {order && locale && <dl className="co-dl ocp-mini"><div><dt>{t('oc.orderNumber', undefined, locale)}</dt><dd><b>{order.orderNumber}</b></dd></div><div><dt>{t('oc.orderStatus', undefined, locale)}</dt><dd>{t(`oc.status.${order.orderStatus}`, undefined, locale)}</dd></div><div><dt>{t('oc.paymentStatus', undefined, locale)}</dt><dd>{t(`oc.pay.${order.paymentStatus}`, undefined, locale)}</dd></div></dl>}
      <div className="pay-actions">
        {retry && <button type="button" className="btn btn--primary" onClick={retry}>{retryLabel}</button>}
        {actions.map(([to, label, kind]) => <Link key={to + label} to={to} className={`btn btn--${kind}`}>{label}</Link>)}
      </div>
    </section>
  )
}

function Confirmed({ order: o, verification, receipt, locale, customerName, showReceipt, setShowReceipt }: { order: Order; verification: PickupVerification | null; receipt: Receipt | null; locale: string; customerName: string | null; showReceipt: boolean; setShowReceipt: (v: boolean) => void }) {
  const tz = o.pickup.restaurantTimezone
  const money = (m: number) => formatMoney(m, o.pricing.currency, locale)
  const count = o.items.reduce((a, i) => a + i.quantity, 0)
  const mapsHref = o.restaurant.lat != null && o.restaurant.lng != null ? `https://www.google.com/maps/search/?api=1&query=${o.restaurant.lat},${o.restaurant.lng}` : null
  return (
    <>
      <section className="cart-card ocp-hero" aria-labelledby="ocp-title" aria-live="polite">
        <div className="ocp-hero__tick" aria-hidden="true"><CheckIcon size={34} /></div>
        <p className="cart-eyebrow">{t('oc.eyebrow', undefined, locale)}</p>
        <h1 id="ocp-title">{t('oc.confirmed.title', undefined, locale)}</h1>
        <p className="ocp-hero__lead">{t('oc.confirmed.lead', { restaurant: o.restaurant.name }, locale)}</p>
        <dl className="ocp-facts">
          <div><dt>{t('oc.orderNumber', undefined, locale)}</dt><dd><b className="ocp-number" data-testid="oc-number">{o.orderNumber}</b></dd></div>
          <div><dt>{t('oc.orderStatus', undefined, locale)}</dt><dd><span className="co-badge co-badge--ok" data-testid="oc-order-status">{t(`oc.status.${o.orderStatus}`, undefined, locale)}</span></dd></div>
          <div><dt>{t('oc.paymentStatus', undefined, locale)}</dt><dd><span className="co-badge co-badge--ok" data-testid="oc-payment-status">{t(`oc.pay.${o.paymentStatus}`, undefined, locale)}</span></dd></div>
          <div><dt>{t('oc.total', undefined, locale)}</dt><dd><b data-testid="oc-total">{money(o.pricing.totalMinor)}</b> <span className="co-badge co-badge--muted">{o.pricing.currency}</span></dd></div>
        </dl>
        <p className="cart-mock">{t('oc.mock', undefined, locale)}</p>
        <div className="pay-actions ocp-primary">
          <Link to={`/order-tracking/${o.orderNumber}`} className="btn btn--primary cart-proceed" data-testid="oc-track">{t('oc.action.track', undefined, locale)}</Link>
          <Link to={`/order/${o.orderNumber}`} className="btn btn--outline" data-testid="oc-details">{t('oc.action.details', undefined, locale)}</Link>
        </div>
      </section>

      <PickupCode verification={verification} locale={locale} />

      <section className="cart-card" aria-labelledby="ocp-pickup">
        <h2 id="ocp-pickup"><ClockIcon /> {t('oc.pickup', undefined, locale)}</h2>
        <dl className="co-dl">
          <div><dt>{t('pickup.date', undefined, locale)}</dt><dd>{formatLocalDate(o.pickup.requestedAt, tz, locale)}</dd></div>
          <div><dt>{t('pickup.time', undefined, locale)}</dt><dd data-testid="oc-pickup-time">{o.pickup.mode === 'asap' ? `${t('pickup.asap', undefined, locale)} · ~` : ''}{formatLocalTime(o.pickup.requestedAt, tz, locale)} {zoneLabel(o.pickup.requestedAt, tz, locale)}<small>{t('oc.pickup.zone', { zone: tz }, locale)}</small></dd></div>
          <div><dt>{t('oc.pickup.ready', undefined, locale)}</dt><dd>~{formatLocalTime(o.pickup.estimatedReadyTime, tz, locale)} {zoneLabel(o.pickup.estimatedReadyTime, tz, locale)}</dd></div>
          <div><dt>{t('oc.pickup.method', undefined, locale)}</dt><dd>{o.pickup.methodLabel}{o.restaurant.pickupLocation && <small>{o.restaurant.pickupLocation}</small>}</dd></div>
          {(o.pickup.instructions || o.restaurant.pickupInstructions) && <div><dt>{t('oc.pickup.instructions', undefined, locale)}</dt><dd>{o.pickup.instructions ?? o.restaurant.pickupInstructions}</dd></div>}
        </dl>
        {o.journey && <p className="cart-notice cart-notice--info ocp-journey"><PinIcon size={16} /> {t('oc.journey', { origin: o.journey.originName, destination: o.journey.destinationName }, locale)} <Link to="/plan-journey" className="cart-link">{t('oc.action.continueJourney', undefined, locale)}</Link></p>}
      </section>

      <section className="cart-card" aria-labelledby="ocp-rest">
        <h2 id="ocp-rest"><PinIcon /> {t('oc.restaurant', undefined, locale)}</h2>
        <p className="co-rest__name">{o.restaurant.name}</p>
        <p className="cart-rest-row__addr ocp-addr">{o.restaurant.formattedAddress}</p>
        <p className="cart-muted">{o.restaurant.contact ?? t('oc.restaurant.contactNone', undefined, locale)}</p>
        <div className="pay-actions">
          {mapsHref && <a className="btn btn--outline" href={mapsHref} target="_blank" rel="noopener noreferrer">{t('oc.action.directions', undefined, locale)}</a>}
          <Link to={`/restaurants/${o.restaurant.slug}`} className="btn btn--outline">{t('oc.action.viewRestaurant', undefined, locale)}</Link>
        </div>
        <p className="cart-muted">{t('oc.restaurant.directionsNote', undefined, locale)}</p>
      </section>

      <section className="cart-card" aria-labelledby="ocp-items">
        <h2 id="ocp-items">{t('oc.items', { count }, locale)}</h2>
        <Items items={o.items} money={money} />
        {o.orderNote && <p className="cart-muted">{t('oc.note', { note: o.orderNote }, locale)}</p>}
        <Pricing pricing={o.pricing} money={money} locale={locale} />
      </section>

      <section className="cart-card" aria-labelledby="ocp-payment">
        <h2 id="ocp-payment">{t('oc.payment', undefined, locale)}</h2>
        <dl className="co-dl">
          <div><dt>{t('oc.paymentStatus', undefined, locale)}</dt><dd>{t(`oc.pay.${o.payment.status}`, undefined, locale)}</dd></div>
          <div><dt>{t('oc.payment.method', undefined, locale)}</dt><dd>{o.payment.methodLabel}<small>{t('pay.provider', { provider: o.payment.providerDisplayName }, locale)}{o.payment.maskedDetails ? ` · ${o.payment.maskedDetails}` : ''}</small></dd></div>
          <div><dt>{t('oc.payment.reference', undefined, locale)}</dt><dd><code className="pay-ref">{o.payment.reference}</code></dd></div>
          <div><dt>{t('oc.payment.paid', undefined, locale)}</dt><dd><b>{money(o.payment.paidAmountMinor)}</b></dd></div>
        </dl>
        <p className="cart-muted">{t('oc.payment.safe', undefined, locale)}</p>
      </section>

      <section className="cart-card" aria-labelledby="ocp-receipt">
        <h2 id="ocp-receipt">{t('oc.receipt', undefined, locale)}</h2>
        <p className="cart-muted">{t('oc.receipt.text', undefined, locale)}</p>
        <div className="pay-actions">
          <button type="button" className="btn btn--outline" aria-expanded={showReceipt} aria-controls="ocp-receipt-panel" onClick={() => setShowReceipt(!showReceipt)}>{showReceipt ? t('oc.receipt.hide', undefined, locale) : t('oc.receipt.view', undefined, locale)}</button>
          <button type="button" className="btn btn--outline" disabled aria-describedby="ocp-receipt-pending">{t('oc.receipt.download', undefined, locale)}</button>
          <button type="button" className="btn btn--outline" disabled aria-describedby="ocp-receipt-pending">{t('oc.receipt.email', undefined, locale)}</button>
        </div>
        <p id="ocp-receipt-pending" className="cart-muted">{t('oc.receipt.pending', undefined, locale)}</p>
        {showReceipt && receipt && (
          <div id="ocp-receipt-panel" className="ocp-receipt" data-testid="oc-receipt">
            <p className="ocp-receipt__kind">{t('oc.receipt.kind', undefined, locale)}</p>
            <dl className="co-dl">
              <div><dt>{t('oc.orderNumber', undefined, locale)}</dt><dd>{receipt.orderNumber}</dd></div>
              <div><dt>{t('oc.receipt.date', undefined, locale)}</dt><dd>{formatLocalDate(receipt.orderDate, tz, locale)} · {formatLocalTime(receipt.orderDate, tz, locale)} {zoneLabel(receipt.orderDate, tz, locale)}</dd></div>
              <div><dt>{t('oc.restaurant', undefined, locale)}</dt><dd>{receipt.restaurantName}<small>{receipt.restaurantAddress}</small></dd></div>
              {(receipt.customerName ?? customerName) && <div><dt>{t('oc.receipt.customer', undefined, locale)}</dt><dd>{receipt.customerName ?? customerName}</dd></div>}
              <div><dt>{t('oc.payment.method', undefined, locale)}</dt><dd>{receipt.paymentMethodLabel} · <code className="pay-ref">{receipt.paymentReference}</code></dd></div>
            </dl>
            <Items items={receipt.items} money={money} />
            <Pricing pricing={receipt.pricing} money={money} locale={locale} />
            <p className="cart-muted">{t('oc.receipt.notInvoice', undefined, locale)}</p>
          </div>
        )}
      </section>

      <section className="cart-card" aria-labelledby="ocp-help">
        <h2 id="ocp-help">{t('oc.help', undefined, locale)}</h2>
        <p className="cart-muted">{t('oc.help.text', undefined, locale)}</p>
        <div className="pay-actions">
          <Link to="/help" className="btn btn--outline">{t('oc.action.help', undefined, locale)}</Link>
          <Link to="/refund-policy" className="btn btn--outline">{t('oc.action.cancellationPolicy', undefined, locale)}</Link>
          <Link to="/restaurants" className="btn btn--outline">{t('oc.action.browse', undefined, locale)}</Link>
          <Link to="/" className="btn btn--outline">{t('oc.action.home', undefined, locale)}</Link>
        </div>
      </section>
    </>
  )
}

function Items({ items, money }: { items: OrderItemSnapshot[]; money: (m: number) => string }) {
  return (
    <ul className="ocp-lines">
      {items.map((i) => (
        <li key={i.lineId} className="ocp-line">
          <div className="ocp-line__info">
            <b>{i.itemName} <span className="co-qty">× {i.quantity}</span></b>
            {[...i.variants, ...i.modifiers].map((o, k) => <small key={k}>{o.groupName}: {o.optionName}{o.priceAdjustmentMinor ? ` (+${money(o.priceAdjustmentMinor)})` : ''}</small>)}
            {i.specialInstructions && <small className="ocp-line__note">“{i.specialInstructions}”</small>}
            <small>{money(i.unitPriceMinor)} × {i.quantity}</small>
          </div>
          <b className="ocp-line__total">{money(i.lineTotalMinor)}</b>
        </li>
      ))}
    </ul>
  )
}

function Pricing({ pricing: p, money, locale }: { pricing: OrderPricing; money: (m: number) => string; locale: string }) {
  return (
    <dl className="cart-sum" data-testid="oc-pricing">
      <div><dt>{t('cartpage.subtotal', undefined, locale)}</dt><dd>{money(p.subtotalMinor)}</dd></div>
      {p.discountMinor > 0 && <div className="cart-sum__disc"><dt>{t('cartpage.discount', { code: p.promoCode ?? '' }, locale)}</dt><dd>−{money(p.discountMinor)}</dd></div>}
      {p.taxes.map((l) => <div key={l.id}><dt>{l.label}</dt><dd>{money(l.amountMinor)}</dd></div>)}
      {p.fees.map((l) => <div key={l.id}><dt>{l.label}</dt><dd>{money(l.amountMinor)}</dd></div>)}
      <div className="cart-sum__total"><dt>{t('oc.total', undefined, locale)}</dt><dd>{money(p.totalMinor)}</dd></div>
      {p.taxes.length === 0 && p.fees.length === 0 && <div className="cart-muted ocp-nofees"><dt>{t('oc.noFees', undefined, locale)}</dt><dd /></div>}
    </dl>
  )
}

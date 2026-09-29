import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import Header from '../components/Header'
import { useAccount } from '../account/AccountContext'
import { useAuth } from '../auth/AuthContext'
import { useCart, type AddItemInput } from '../cart/CartContext'
import { formatLocalTime, formatMoney, zoneLabel } from '../i18n/format'
import { t, useLocale } from '../i18n/strings'
import { hasRefund, isReviewable, isTrackable } from '../order/history'
import { MockOrderRepository, MockReceiptRepository, MockReorderService } from '../order/mock/index'
import type { ReorderPlan, ReorderService } from '../order/mock/mockReorder'
import type { Order, OrderItemSnapshot, OrderPricing, OrderRepository, Receipt, ReceiptRepository } from '../order/repositories'
import { timelineFor } from '../order/tracking'
import { orderRepositories } from '../order/useOrderConfirmation'
import { formatLocalDate } from '../pickup/time'
import './CartPage.css'
import './CheckoutPage.css'
import './OrderConfirmationPage.css'
import './OrderTrackingPage.css'
import './MyOrdersPage.css'

/**
 * Full order details (Module 15). Canonical /order/:orderNumber. Everything comes from the historical snapshot (never the
 * live menu); the Module 14 timeline model and the Module 13 receipt are reused; Track order links to Module 14.
 * Reorder builds a NEW cart from the CURRENT menu after the customer reviews the differences.
 */
type Deps = { orders?: OrderRepository; receipts?: ReceiptRepository; reorder?: ReorderService }
type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const, 'aria-hidden': true })
const CheckIcon = ({ size = 14 }: P) => (<svg {...stroke(size)}><path d="m5 12 4 4L19 7" /></svg>)
const XIcon = ({ size = 14 }: P) => (<svg {...stroke(size)}><path d="M6 6l12 12M18 6 6 18" /></svg>)
const HeartIcon = ({ size = 16, filled = false }: P & { filled?: boolean }) => (<svg {...stroke(size)} fill={filled ? 'currentColor' : 'none'}><path d="M12 21s-7-4.6-9.3-9.1C1.2 8.6 3.2 5 6.8 5c2 0 3.3 1.1 4.2 2.3C11.9 6.1 13.2 5 15.2 5c3.6 0 5.6 3.6 4.1 6.9C19 16.4 12 21 12 21Z" /></svg>)
const defaultReorder = new MockReorderService()

export default function OrderDetailsPage({ deps }: { deps?: Deps }) {
  const { orderNumber = '' } = useParams()
  const { locale } = useLocale()
  const auth = useAuth()
  const orders = deps?.orders ?? orderRepositories.orders; const receipts = deps?.receipts ?? orderRepositories.receipts; const reorderSvc = deps?.reorder ?? defaultReorder
  const [state, setState] = useState<'loading' | 'ready' | 'not_found' | 'error'>('loading')
  const [order, setOrder] = useState<Order | null>(null)
  const [receipt, setReceipt] = useState<Receipt | null>(null)
  const [showReceipt, setShowReceipt] = useState(() => typeof window !== 'undefined' && window.location.hash === '#receipt')
  const [tick, setTick] = useState(0)
  const customerId = auth.user?.id ?? null
  useEffect(() => { document.title = `${t('od.title', undefined, locale)} · FoodOnTheGo` }, [locale])
  useEffect(() => {
    let on = true; setState('loading')
    ;(async () => {
      if (!customerId) return
      try {
        const o = await orders.getByOrderNumber(orderNumber, customerId)
        if (!on) return
        if (!o) { setState('not_found'); return }
        setOrder(o); setState('ready')
        const r = await receipts.getReceipt(o, auth.user?.name ?? null).catch(() => null); if (on) setReceipt(r)
      } catch { if (on) setState('error') }
    })()
    return () => { on = false }
  }, [orderNumber, customerId, orders, receipts, tick, auth.user?.name])

  return (
    <>
      <Header />
      <main id="main" className="cart od">
        <div className="cart__grid cart__grid--single">
          <div className="cart__main">
            {state === 'loading' && <section className="cart-card ocp-skeleton" role="status" aria-live="polite" aria-busy="true"><p className="cart-muted">{t('od.loading', undefined, locale)}</p><div className="ocp-skel ocp-skel--title" /><div className="ocp-skel" /><div className="ocp-skel ocp-skel--block" /></section>}
            {state === 'not_found' && <section className="cart-card ocp-state ocp-state--warn" role="status"><h1 className="ocp-state__title">{t('oc.notFound.title', undefined, locale)}</h1><p>{t('oc.notFound.text', { ref: orderNumber }, locale)}</p><div className="pay-actions"><Link to="/my-orders" className="btn btn--primary">{t('oc.action.myOrders', undefined, locale)}</Link><Link to="/help" className="btn btn--outline">{t('oc.action.help', undefined, locale)}</Link></div></section>}
            {state === 'error' && <section className="cart-card ocp-state ocp-state--error" role="alert"><h1 className="ocp-state__title">{t('oc.failed.title', undefined, locale)}</h1><p>{t('oc.failed.text', undefined, locale)}</p><div className="pay-actions"><button type="button" className="btn btn--primary" onClick={() => setTick((x) => x + 1)}>{t('oc.action.retry', undefined, locale)}</button></div></section>}
            {state === 'ready' && order && <Details order={order} receipt={receipt} locale={locale} showReceipt={showReceipt} setShowReceipt={setShowReceipt} reorder={reorderSvc} />}
          </div>
        </div>
      </main>
    </>
  )
}

function Details({ order: o, receipt, locale, showReceipt, setShowReceipt, reorder }: { order: Order; receipt: Receipt | null; locale: string; showReceipt: boolean; setShowReceipt: (v: boolean) => void; reorder: ReorderService }) {
  const tz = o.pickup.restaurantTimezone
  const money = (m: number) => formatMoney(m, o.pricing.currency, locale)
  const time = (iso: string) => `${formatLocalTime(iso, tz, locale)} ${zoneLabel(iso, tz, locale)}`
  const account = useAccount()
  const s = o.orderStatus
  const closed = s === 'CANCELLED' || s === 'REJECTED'
  const closedAt = o.events.filter((e) => e.type === 'CANCELLED' || e.type === 'RESTAURANT_REJECTED').map((e) => e.at).pop() ?? null
  const refunded = o.payment.refundedAmountMinor ?? null
  const remaining = refunded != null ? Math.max(0, o.payment.paidAmountMinor - refunded) : null
  const stages = timelineFor(o)
  const canReorder = !isTrackable(s) && s !== 'PAYMENT_PENDING'
  const fav = account.isFavorite(o.restaurant.id)
  return (
    <>
      <section className={`cart-card od-head ${closed ? 'od-head--closed' : ''}`} aria-labelledby="od-title">
        <p className="cart-eyebrow">{t('od.eyebrow', undefined, locale)}</p>
        <div className="mo-card__head">
          <div><h1 id="od-title">{o.restaurant.name}</h1><p className="cart-muted mo-card__meta"><span className="ocp-number" data-testid="od-number">{o.orderNumber}</span> · {t('od.placed', { date: formatLocalDate(o.createdAt, tz, locale), time: time(o.createdAt) }, locale)}</p></div>
          <div className="mo-card__badges"><span className={`co-badge ${closed ? 'co-badge--bad' : 'co-badge--ok'}`} data-testid="od-order-status">{t(`oc.status.${s}`, undefined, locale)}</span><span className={`co-badge ${o.paymentStatus === 'PAID' ? 'co-badge--ok' : 'co-badge--muted'}`} data-testid="od-payment-status">{t(`oc.pay.${o.paymentStatus}`, undefined, locale)}</span></div>
        </div>
        {s === 'CANCELLED' && <p className="cart-notice cart-notice--error" role="status" data-testid="od-closed">{t('od.cancelled', { at: closedAt ? `${formatLocalDate(closedAt, tz, locale)} · ${time(closedAt)}` : '' }, locale)} {o.cancellationReasonKey && t(`track.reason.${o.cancellationReasonKey}`, undefined, locale)}</p>}
        {s === 'REJECTED' && <p className="cart-notice cart-notice--error" role="status" data-testid="od-closed">{t('od.rejected', undefined, locale)} {o.rejectionReasonKey && t(`track.reason.${o.rejectionReasonKey}`, undefined, locale)}</p>}
        {s === 'PAYMENT_PENDING' && <p className="cart-notice cart-notice--warn" role="status">{t('track.paymentPending.text', undefined, locale)} <Link to="/payment" className="cart-link">{t('oc.action.checkStatus', undefined, locale)}</Link></p>}
        <div className="pay-actions">
          {isTrackable(s) && <Link to={`/order-tracking/${o.orderNumber}`} className="btn btn--primary cart-proceed" data-testid="od-track">{t('oc.action.track', undefined, locale)}</Link>}
          {canReorder && <a href="#reorder" className="btn btn--primary" data-testid="od-reorder-cta">{t('od.action.reorder', undefined, locale)}</a>}
          <button type="button" className="btn btn--outline" onClick={() => setShowReceipt(!showReceipt)} aria-expanded={showReceipt} aria-controls="od-receipt">{showReceipt ? t('oc.receipt.hide', undefined, locale) : t('oc.receipt.view', undefined, locale)}</button>
          {isReviewable(s) && <button type="button" className="btn btn--outline" disabled aria-describedby="od-rate-note">{t('track.action.rate', undefined, locale)}</button>}
          <Link to="/help" className="btn btn--outline">{t('od.action.help', undefined, locale)}</Link>
        </div>
        {isReviewable(s) && <p id="od-rate-note" className="cart-muted">{t('track.rate.pending', undefined, locale)}</p>}
      </section>

      <section className="cart-card" aria-labelledby="od-rest">
        <div className="mo-card__head"><h2 id="od-rest">{t('oc.restaurant', undefined, locale)}</h2><button type="button" className={`cart-link od-fav ${fav ? 'is-on' : ''}`} aria-pressed={fav} onClick={() => { void account.toggleFavorite(o.restaurant.id) }}><HeartIcon filled={fav} /> {fav ? t('od.action.unfavorite', undefined, locale) : t('od.action.favorite', undefined, locale)}</button></div>
        <p className="co-rest__name">{o.restaurant.name}</p>
        <p className="cart-rest-row__addr ocp-addr">{o.restaurant.formattedAddress}</p>
        <div className="pay-actions"><Link to={`/restaurants/${o.restaurant.slug}`} className="btn btn--outline">{t('oc.action.viewRestaurant', undefined, locale)}</Link></div>
      </section>

      <section className="cart-card" aria-labelledby="od-pickup">
        <h2 id="od-pickup">{t('oc.pickup', undefined, locale)}</h2>
        <dl className="co-dl">
          <div><dt>{t('pickup.date', undefined, locale)}</dt><dd>{formatLocalDate(o.pickup.requestedAt, tz, locale)}</dd></div>
          <div><dt>{t('pickup.time', undefined, locale)}</dt><dd data-testid="od-pickup-time">{time(o.pickup.requestedAt)}<small>{t('oc.pickup.zone', { zone: tz }, locale)}</small></dd></div>
          <div><dt>{t('oc.pickup.method', undefined, locale)}</dt><dd>{o.pickup.methodLabel}{o.restaurant.pickupLocation && <small>{o.restaurant.pickupLocation}</small>}</dd></div>
          {(o.pickup.instructions || o.restaurant.pickupInstructions) && <div><dt>{t('oc.pickup.instructions', undefined, locale)}</dt><dd>{o.pickup.instructions ?? o.restaurant.pickupInstructions}</dd></div>}
          {o.journey && <div><dt>{t('od.journey', undefined, locale)}</dt><dd>{o.journey.originName} → {o.journey.destinationName}</dd></div>}
        </dl>
        <p className="cart-muted">{t('od.snapshotNote', undefined, locale)}</p>
      </section>

      <section className="cart-card" aria-labelledby="od-items">
        <h2 id="od-items">{t('oc.items', { count: o.items.reduce((a, i) => a + i.quantity, 0) }, locale)}</h2>
        <Items items={o.items} money={money} />
        {o.orderNote && <p className="cart-muted">{t('oc.note', { note: o.orderNote }, locale)}</p>}
        <Pricing pricing={o.pricing} money={money} locale={locale} />
      </section>

      <section className="cart-card" aria-labelledby="od-payment">
        <h2 id="od-payment">{t('oc.payment', undefined, locale)}</h2>
        <dl className="co-dl" data-testid="od-payment">
          <div><dt>{t('oc.paymentStatus', undefined, locale)}</dt><dd>{t(`oc.pay.${o.payment.status}`, undefined, locale)}</dd></div>
          <div><dt>{t('oc.payment.method', undefined, locale)}</dt><dd>{o.payment.methodLabel}{o.payment.maskedDetails ? ` · ${o.payment.maskedDetails}` : ''}<small>{t('pay.provider', { provider: o.payment.providerDisplayName }, locale)}</small></dd></div>
          <div><dt>{t('oc.payment.reference', undefined, locale)}</dt><dd><code className="pay-ref">{o.payment.reference}</code></dd></div>
          <div><dt>{t('oc.payment.paid', undefined, locale)}</dt><dd><b>{money(o.payment.paidAmountMinor)}</b></dd></div>
          {hasRefund(o.paymentStatus) && (
            <>
              <div><dt>{t('od.refund.amount', undefined, locale)}</dt><dd data-testid="od-refunded">{refunded != null ? money(refunded) : t('od.refund.pendingAmount', undefined, locale)}</dd></div>
              {remaining != null && remaining > 0 && <div><dt>{t('od.refund.remaining', undefined, locale)}</dt><dd data-testid="od-remaining">{money(remaining)}</dd></div>}
            </>
          )}
        </dl>
        {hasRefund(o.paymentStatus) && <p className="cart-muted">{t('od.refund.note', undefined, locale)}</p>}
        <p className="cart-muted">{t('oc.payment.safe', undefined, locale)}</p>
      </section>

      <section className="cart-card" aria-labelledby="od-timeline">
        <h2 id="od-timeline">{t('od.timeline', undefined, locale)}</h2>
        <ol className="trk-steps" data-testid="od-timeline">
          {stages.map((st) => (
            <li key={st.key} className={`trk-step trk-step--${st.state}`} data-state={st.state}>
              <span className="trk-step__icon" aria-hidden="true">{st.state === 'done' ? <CheckIcon /> : st.state === 'stopped' ? <XIcon /> : null}</span>
              <span className="trk-step__body"><b>{t(`track.stage.${st.key}`, undefined, locale)} <span className="trk-sr">({t(`track.stageState.${st.state}`, undefined, locale)})</span></b>{st.at && <small>{formatLocalDate(st.at, tz, locale)} · {time(st.at)}</small>}{st.note && <small className={`trk-step__note trk-step__note--${st.note}`}>{t(`track.stageNote.${st.note}`, undefined, locale)}</small>}</span>
            </li>
          ))}
        </ol>
      </section>

      <section className="cart-card" aria-labelledby="od-receipt-title" id="od-receipt">
        <h2 id="od-receipt-title">{t('oc.receipt', undefined, locale)}</h2>
        <p className="cart-muted">{t('oc.receipt.text', undefined, locale)}</p>
        <div className="pay-actions">
          <button type="button" className="btn btn--outline" onClick={() => setShowReceipt(!showReceipt)}>{showReceipt ? t('oc.receipt.hide', undefined, locale) : t('oc.receipt.view', undefined, locale)}</button>
          <button type="button" className="btn btn--outline" disabled aria-describedby="od-receipt-pending">{t('oc.receipt.download', undefined, locale)}</button>
        </div>
        <p id="od-receipt-pending" className="cart-muted">{t('oc.receipt.pending', undefined, locale)}</p>
        {showReceipt && receipt && (
          <div className="ocp-receipt" data-testid="od-receipt-panel">
            <p className="ocp-receipt__kind">{t('oc.receipt.kind', undefined, locale)}</p>
            <dl className="co-dl"><div><dt>{t('oc.orderNumber', undefined, locale)}</dt><dd>{receipt.orderNumber}</dd></div><div><dt>{t('oc.receipt.date', undefined, locale)}</dt><dd>{formatLocalDate(receipt.orderDate, tz, locale)} · {time(receipt.orderDate)}</dd></div><div><dt>{t('oc.payment.method', undefined, locale)}</dt><dd>{receipt.paymentMethodLabel} · <code className="pay-ref">{receipt.paymentReference}</code></dd></div></dl>
            <Items items={receipt.items} money={money} /><Pricing pricing={receipt.pricing} money={money} locale={locale} />
            <p className="cart-muted">{t('oc.receipt.notInvoice', undefined, locale)}</p>
          </div>
        )}
      </section>

      {canReorder && <ReorderPanel order={o} service={reorder} locale={locale} />}
    </>
  )
}

function ReorderPanel({ order: o, service, locale }: { order: Order; service: ReorderService; locale: string }) {
  const cart = useCart()
  const navigate = useNavigate()
  const [plan, setPlan] = useState<ReorderPlan | null>(null)
  const [state, setState] = useState<'idle' | 'checking' | 'ready' | 'confirm' | 'adding' | 'error'>('idle')
  const [removed, setRemoved] = useState<Set<string>>(new Set())
  const money = (m: number, cur = o.pricing.currency) => formatMoney(m, cur, locale)
  const check = async () => { setState('checking'); try { const p = await service.plan(o); setPlan(p); setState('ready') } catch { setState('error') } }
  useEffect(() => { if (window.location.hash === '#reorder') void check() }, []) // eslint-disable-line react-hooks/exhaustive-deps
  const addable = plan ? plan.lines.filter((l) => l.input && !removed.has(l.lineId)) : []
  const otherCart = !!cart.cart && cart.cart.items.length > 0 && cart.cart.restaurantId !== o.restaurant.id
  // Items are added one per render (the cart context is state-driven); an explicit replace clears first and waits for it.
  const [queue, setQueue] = useState<AddItemInput[] | null>(null)
  const build = async (replace: boolean) => {
    if (!plan) return
    if (otherCart && !replace) { setState('confirm'); return }
    setState('adding')
    if (replace) cart.clearCart()
    setQueue(addable.map((l) => l.input!))
  }
  useEffect(() => {
    if (!queue) return
    if (cart.cart && cart.cart.items.length > 0 && cart.cart.restaurantId !== o.restaurant.id) return // waiting for the clear to land
    if (queue.length === 0) { setQueue(null); navigate('/cart'); return }
    const [next, ...rest] = queue
    cart.addItem(next); setQueue(rest)
  }, [queue, cart, o.restaurant.id, navigate])
  const total = addable.reduce((a, l) => a + (l.newUnitMinor ?? 0) * l.quantity, 0)
  return (
    <section className="cart-card od-reorder" id="reorder" aria-labelledby="od-reorder-title" data-testid="od-reorder">
      <h2 id="od-reorder-title">{t('od.reorder.title', undefined, locale)}</h2>
      <p className="cart-muted">{t('od.reorder.text', undefined, locale)}</p>
      {state === 'idle' && <div className="pay-actions"><button type="button" className="btn btn--primary" onClick={() => { void check() }} data-testid="od-reorder-check">{t('od.reorder.check', undefined, locale)}</button></div>}
      {state === 'checking' && <p className="cart-muted" role="status">{t('od.reorder.checking', undefined, locale)}</p>}
      {state === 'error' && <p className="cart-notice cart-notice--error" role="alert">{t('od.reorder.error', undefined, locale)} <button type="button" className="cart-link" onClick={() => { void check() }}>{t('oc.action.retry', undefined, locale)}</button></p>}
      {plan && state !== 'idle' && state !== 'checking' && (
        <div className="od-reorder__plan" data-testid="od-reorder-plan">
          {plan.restaurant.status !== 'ok' ? (
            <div className="cart-notice cart-notice--error" role="alert" data-testid="od-reorder-restaurant"><span>{t(`od.reorder.restaurant.${plan.restaurant.status}`, { restaurant: plan.restaurant.name }, locale)}</span><div className="co-notice__actions"><Link to="/restaurants" className="btn btn--outline">{t('mo.action.explore', undefined, locale)}</Link><Link to="/plan-journey" className="btn btn--outline">{t('mo.action.plan', undefined, locale)}</Link></div></div>
          ) : (
            <>
              {plan.currencyChanged && <p className="cart-notice cart-notice--warn" role="status">{t('od.reorder.currencyChanged', { currency: plan.restaurant.currency }, locale)}</p>}
              <ul className="od-reorder__lines">
                {plan.lines.map((l) => (
                  <li key={l.lineId} className={`od-reorder__line od-reorder__line--${l.status} ${removed.has(l.lineId) ? 'is-removed' : ''}`} data-testid="od-reorder-line" data-status={l.status}>
                    <div className="od-reorder__info">
                      <b>{l.itemName} <span className="co-qty">× {l.quantity}</span></b>
                      <small>{t(`od.reorder.line.${l.status}`, undefined, locale)}</small>
                      {l.status === 'price_changed' && <small className="od-reorder__price">{t('od.reorder.price', { old: money(l.oldUnitMinor), current: money(l.newUnitMinor ?? 0, plan.restaurant.currency) }, locale)}</small>}
                      {l.status === 'ok' && <small>{t('od.reorder.priceSame', { current: money(l.newUnitMinor ?? 0, plan.restaurant.currency) }, locale)}</small>}
                      {l.missingOptions.length > 0 && <small className="od-reorder__missing">{t('od.reorder.missing', { options: l.missingOptions.join(', ') }, locale)}</small>}
                    </div>
                    <div className="od-reorder__acts">
                      {l.input && !removed.has(l.lineId) && <button type="button" className="cart-link" onClick={() => setRemoved(new Set([...removed, l.lineId]))}>{t('od.reorder.remove', undefined, locale)}</button>}
                      {l.input && removed.has(l.lineId) && <button type="button" className="cart-link" onClick={() => { const n = new Set(removed); n.delete(l.lineId); setRemoved(n) }}>{t('od.reorder.restore', undefined, locale)}</button>}
                      {!l.input && <Link to={`/restaurants/${plan.restaurant.slug}${l.status === 'modifier_missing' ? `/item/${l.itemSlug}` : ''}`} className="cart-link">{l.status === 'modifier_missing' ? t('od.reorder.chooseAgain', undefined, locale) : t('od.reorder.menu', undefined, locale)}</Link>}
                    </div>
                  </li>
                ))}
              </ul>
              <p className="cart-muted" data-testid="od-reorder-summary">{t('od.reorder.summary', { count: addable.length, total: money(total, plan.restaurant.currency) }, locale)}</p>
              <p className="cart-muted">{t('od.reorder.newOrderNote', undefined, locale)}</p>
              {state === 'confirm' && (
                <div className="cart-notice cart-notice--warn" role="alertdialog" aria-labelledby="od-reorder-confirm" data-testid="od-reorder-confirm">
                  <span id="od-reorder-confirm">{t('od.reorder.replaceCart', { current: cart.cart?.restaurantName ?? '', next: plan.restaurant.name }, locale)}</span>
                  <div className="co-notice__actions"><button type="button" className="btn btn--primary" onClick={() => { void build(true) }}>{t('od.reorder.replaceConfirm', undefined, locale)}</button><button type="button" className="btn btn--outline" onClick={() => setState('ready')}>{t('od.reorder.keepCart', undefined, locale)}</button></div>
                </div>
              )}
              {state !== 'confirm' && <div className="pay-actions"><button type="button" className="btn btn--primary" disabled={addable.length === 0 || state === 'adding'} onClick={() => { void build(false) }} data-testid="od-reorder-add">{t('od.reorder.add', { count: addable.length }, locale)}</button><Link to={`/restaurants/${plan.restaurant.slug}`} className="btn btn--outline">{t('od.reorder.menu', undefined, locale)}</Link></div>}
            </>
          )}
        </div>
      )}
    </section>
  )
}

function Items({ items, money }: { items: OrderItemSnapshot[]; money: (m: number) => string }) {
  return (
    <ul className="ocp-lines">
      {items.map((i) => (
        <li key={i.lineId} className="ocp-line">
          <div className="ocp-line__info"><b>{i.itemName} <span className="co-qty">× {i.quantity}</span></b>{[...i.variants, ...i.modifiers].map((o, k) => <small key={k}>{o.groupName}: {o.optionName}{o.priceAdjustmentMinor ? ` (+${money(o.priceAdjustmentMinor)})` : ''}</small>)}{i.specialInstructions && <small className="ocp-line__note">“{i.specialInstructions}”</small>}<small>{money(i.unitPriceMinor)} × {i.quantity}</small></div>
          <b className="ocp-line__total">{money(i.lineTotalMinor)}</b>
        </li>
      ))}
    </ul>
  )
}
function Pricing({ pricing: p, money, locale }: { pricing: OrderPricing; money: (m: number) => string; locale: string }) {
  return (
    <dl className="cart-sum" data-testid="od-pricing">
      <div><dt>{t('cartpage.subtotal', undefined, locale)}</dt><dd>{money(p.subtotalMinor)}</dd></div>
      {p.discountMinor > 0 && <div className="cart-sum__disc"><dt>{t('cartpage.discount', { code: p.promoCode ?? '' }, locale)}</dt><dd>−{money(p.discountMinor)}</dd></div>}
      {p.taxes.map((l) => <div key={l.id}><dt>{l.label}</dt><dd>{money(l.amountMinor)}</dd></div>)}
      {p.fees.map((l) => <div key={l.id}><dt>{l.label}</dt><dd>{money(l.amountMinor)}</dd></div>)}
      <div className="cart-sum__total"><dt>{t('oc.total', undefined, locale)}</dt><dd>{money(p.totalMinor)}</dd></div>
      {p.taxes.length === 0 && p.fees.length === 0 && <div className="cart-muted ocp-nofees"><dt>{t('oc.noFees', undefined, locale)}</dt><dd /></div>}
    </dl>
  )
}
export { MockOrderRepository, MockReceiptRepository }

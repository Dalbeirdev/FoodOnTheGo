import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { formatLocalTime, formatMoney, zoneLabel } from '../../i18n/format'
import { t } from '../../i18n/strings'
import type { Order } from '../../order/repositories'
import { formatLocalDate } from '../../pickup/time'
import { useDashboard } from '../DashboardContext'
import { BASE } from '../DashboardLayout'
import { orderTabOf } from '../mock/mockDashboard'
import { Card, ConfirmDialog, Drawer, EmptyState, ErrorState, Field, Icon, Money, PageHeader, Pill, Skeleton, StatusBadge, Tabs, ToastLine, useToastMessage } from '../components/ui'
import type { DelayReason, OrderTab, RejectReason } from '../types'

const TABS: OrderTab[] = ['new', 'preparing', 'ready', 'completed', 'cancelled']
const REJECT: RejectReason[] = ['item_unavailable', 'kitchen_capacity', 'closing', 'unable_to_prepare', 'other']
const DELAY: DelayReason[] = ['high_demand', 'taking_longer', 'capacity', 'other']

/** Status-dependent restaurant actions (Accept / Reject / Mark Ready / Delay / Verify pickup / View). Permission-gated. */
export function OrderActions({ order, onChanged, compact }: { order: Order; onChanged: () => void; compact?: boolean }) {
  const d = useDashboard(); const locale = d.locale; const rid = d.location!.restaurant.id; const nav = useNavigate()
  const [dialog, setDialog] = useState<'reject' | 'delay' | null>(null); const [reason, setReason] = useState<RejectReason>('item_unavailable'); const [note, setNote] = useState(''); const [delayReason, setDelayReason] = useState<DelayReason>('high_demand'); const [delayMin, setDelayMin] = useState(10); const [busy, setBusy] = useState(false); const [err, setErr] = useState<string | null>(null)
  const canUpdate = d.can('orders.update'); const tab = orderTabOf(order.orderStatus)
  const run = async (fn: () => Promise<unknown>) => { if (busy) return; setBusy(true); setErr(null); try { await fn(); onChanged() } catch { setErr(t('dash.orders.actionFailed', undefined, locale)) } finally { setBusy(false) } }
  const sz = compact ? 'db-btn db-btn--sm' : 'db-btn'
  return (
    <>
      {tab === 'new' && canUpdate && <><button type="button" className={`${sz} db-btn--success`} disabled={busy} onClick={() => run(() => d.repos.orders.accept(rid, order.orderNumber))} data-testid="act-accept">{t('dash.orders.accept', undefined, locale)}</button><button type="button" className={`${sz} db-btn--danger`} disabled={busy} onClick={() => setDialog('reject')} data-testid="act-reject">{t('dash.orders.reject', undefined, locale)}</button></>}
      {tab === 'preparing' && canUpdate && <><button type="button" className={`${sz} db-btn--primary`} disabled={busy} onClick={() => run(() => d.repos.orders.markReady(rid, order.orderNumber))} data-testid="act-ready">{t('dash.orders.markReady', undefined, locale)}</button>{!compact && <button type="button" className={`${sz} db-btn--outline`} disabled={busy} onClick={() => setDialog('delay')} data-testid="act-delay">{t('dash.orders.reportDelay', undefined, locale)}</button>}</>}
      {tab === 'ready' && d.can('pickup.verify') && <button type="button" className={`${sz} db-btn--primary`} onClick={() => nav(`${BASE}/pickup-verification?order=${order.orderNumber}`)} data-testid="act-verify">{t('dash.orders.verifyPickup', undefined, locale)}</button>}
      <Link to={`${BASE}/orders/${order.orderNumber}`} className={`${sz} db-btn--outline`} data-testid="act-view">{t('dash.orders.view', undefined, locale)}</Link>
      {err && <span className="db-field__error" role="alert">{err}</span>}
      <ConfirmDialog open={dialog === 'reject'} title={t('dash.orders.rejectTitle', { n: order.orderNumber }, locale)} text={t('dash.orders.rejectText', undefined, locale)} confirmLabel={t('dash.orders.rejectConfirm', undefined, locale)} cancelLabel={t('dash.action.cancel', undefined, locale)} danger onCancel={() => setDialog(null)} onConfirm={() => { setDialog(null); void run(() => d.repos.orders.reject(rid, order.orderNumber, reason, note)) }}>
        <Field label={t('dash.orders.rejectReason', undefined, locale)} required id={`rej-${order.publicId}`}><select id={`rej-${order.publicId}`} className="db-select" value={reason} onChange={(e) => setReason(e.target.value as RejectReason)} data-testid="reject-reason">{REJECT.map((r) => <option key={r} value={r}>{t(`dash.orders.rejectReason.${r}`, undefined, locale)}</option>)}</select></Field>
        <Field label={t('dash.orders.internalNote', undefined, locale)} hint={t('dash.orders.internalNoteHint', undefined, locale)} id={`note-${order.publicId}`}><textarea id={`note-${order.publicId}`} className="db-textarea" value={note} onChange={(e) => setNote(e.target.value)} maxLength={300} /></Field>
        <p className="db-muted" style={{ margin: 0, fontSize: '0.82rem' }}>{t('dash.orders.rejectCustomerSees', { reason: t(`track.reason.${reason === 'item_unavailable' ? 'item_unavailable' : reason === 'kitchen_capacity' ? 'capacity' : reason === 'other' ? 'other' : 'restaurant_unavailable'}`, undefined, locale) }, locale)}</p>
      </ConfirmDialog>
      <ConfirmDialog open={dialog === 'delay'} title={t('dash.orders.delayTitle', { n: order.orderNumber }, locale)} confirmLabel={t('dash.orders.delayConfirm', undefined, locale)} cancelLabel={t('dash.action.cancel', undefined, locale)} onCancel={() => setDialog(null)} onConfirm={() => { setDialog(null); void run(() => d.repos.orders.delay(rid, order.orderNumber, new Date(new Date(order.etaReadyAt ?? order.pickup.requestedAt).getTime() + delayMin * 60000).toISOString(), delayReason)) }}>
        <Field label={t('dash.orders.delayBy', undefined, locale)} required id={`dl-${order.publicId}`}><select id={`dl-${order.publicId}`} className="db-select" value={delayMin} onChange={(e) => setDelayMin(Number(e.target.value))} data-testid="delay-minutes">{[5, 10, 15, 20, 30, 45].map((m) => <option key={m} value={m}>{t('dash.units.minutes', { n: m }, locale)}</option>)}</select></Field>
        <Field label={t('dash.orders.delayReason', undefined, locale)} required id={`dr-${order.publicId}`}><select id={`dr-${order.publicId}`} className="db-select" value={delayReason} onChange={(e) => setDelayReason(e.target.value as DelayReason)}>{DELAY.map((r) => <option key={r} value={r}>{t(`dash.orders.delayReason.${r}`, undefined, locale)}</option>)}</select></Field>
        <p className="db-muted" style={{ margin: 0, fontSize: '0.82rem' }}>{t('dash.orders.delayCustomerSees', undefined, locale)}</p>
      </ConfirmDialog>
    </>
  )
}

export function OrderCard({ order, onChanged }: { order: Order; onChanged: () => void }) {
  const d = useDashboard(); const locale = d.locale; const tz = d.location!.restaurant.timezone; const tab = orderTabOf(order.orderStatus)
  return (
    <article className={`db-order-card db-order-card--${tab}`} aria-label={order.orderNumber} data-testid="order-card" data-status={order.orderStatus}>
      <div className="db-order-card__meta"><span className="db-table__num">{order.orderNumber}</span><StatusBadge status={order.orderStatus} locale={locale} />{order.delayed && <Pill tone="amber">{t('dash.orders.delayed', undefined, locale)}</Pill>}</div>
      <div className="db-order-card__meta"><b dir="auto">{order.customerDisplayName ?? t('dash.orders.customerAnon', undefined, locale)}</b><small>{t('dash.orders.placed', { time: formatLocalTime(order.createdAt, tz, locale) }, locale)}</small></div>
      <div className="db-order-card__meta"><b>{formatLocalTime(order.pickup.requestedAt, tz, locale)}</b><small>{t('dash.orders.pickupTime', undefined, locale)} · {zoneLabel(order.pickup.requestedAt, tz, locale)}</small>{order.etaReadyAt && order.delayed && <small>{t('dash.orders.newEta', { time: formatLocalTime(order.etaReadyAt, tz, locale) }, locale)}</small>}</div>
      <div className="db-order-card__items"><b>{t('dash.units.items', { n: order.items.reduce((a, i) => a + i.quantity, 0) }, locale)} · <Money minor={order.pricing.totalMinor} currency={order.pricing.currency} locale={locale} /></b>{order.items.slice(0, 3).map((i) => <span key={i.lineId} dir="auto">{i.quantity}× {i.itemName}{[...i.variants, ...i.modifiers].length > 0 && <small className="db-muted"> ({[...i.variants, ...i.modifiers].map((o) => o.optionName).join(', ')})</small>}</span>)}{order.items.length > 3 && <small className="db-muted">+{order.items.length - 3}</small>}{order.orderNote && <span className="db-order-card__note" dir="auto"><Icon name="info" size={13} /> {order.orderNote}</span>}</div>
      <div className="db-order-card__actions"><OrderActions order={order} onChanged={onChanged} /></div>
    </article>
  )
}

export default function OrdersPage() {
  const d = useDashboard(); const locale = d.locale; const rid = d.location!.restaurant.id
  const [params, setParams] = useSearchParams(); const tab = (TABS.includes(params.get('tab') as OrderTab) ? params.get('tab') : 'new') as OrderTab
  const [query, setQuery] = useState(''); const [date, setDate] = useState<'today' | 'all'>('today')
  const [orders, setOrders] = useState<Order[]>([]); const [counts, setCounts] = useState<Record<OrderTab, number>>({ new: 0, preparing: 0, ready: 0, completed: 0, cancelled: 0 }); const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading')
  const [history, setHistory] = useState<{ orders: Order[]; nextCursor: string | null; total: number } | null>(null)
  const load = useCallback(async (silent = false) => { if (!silent) setState('loading'); try { const r = await d.repos.orders.list(rid, { tab, query, date }); setOrders(r.orders); setCounts(r.counts); setState('ready') } catch { setState('error') } }, [d.repos, rid, tab, query, date])
  useEffect(() => { void load() }, [load])
  useEffect(() => { document.title = `${t('dash.nav.orders', undefined, locale)} · ${t('dash.brand', undefined, locale)}` }, [locale])
  useEffect(() => { if (tab !== 'completed' && tab !== 'cancelled') { setHistory(null); return } let on = true; d.repos.orders.history(rid, { query, status: tab === 'completed' ? 'completed' : 'all', limit: 8 }).then((h) => { if (on) setHistory(tab === 'cancelled' ? { ...h, orders: h.orders.filter((o) => orderTabOf(o.orderStatus) === 'cancelled') } : h) }).catch(() => {}); return () => { on = false } }, [d.repos, rid, tab, query])
  const more = async () => { if (!history?.nextCursor) return; const h = await d.repos.orders.history(rid, { query, status: tab === 'completed' ? 'completed' : 'all', cursor: history.nextCursor, limit: 8 }); setHistory({ ...h, orders: [...history.orders, ...(tab === 'cancelled' ? h.orders.filter((o) => orderTabOf(o.orderStatus) === 'cancelled') : h.orders)] }) }
  const tabs = TABS.map((id) => ({ id, label: t(`dash.orders.tab.${id}`, undefined, locale), count: counts[id] }))
  const list = tab === 'completed' || tab === 'cancelled' ? (history?.orders ?? orders) : orders
  return (
    <div className="db-page" data-testid="db-orders">
      <PageHeader title={t('dash.orders.title', undefined, locale)} lead={t('dash.orders.lead', undefined, locale)} actions={d.can('pickup.verify') ? <Link to={`${BASE}/pickup-verification`} className="db-btn db-btn--outline"><Icon name="qr" size={18} /> {t('dash.nav.pickup', undefined, locale)}</Link> : undefined} />
      <Card>
        <Tabs tabs={tabs} value={tab} onChange={(v) => setParams({ tab: v })} label={t('dash.orders.tabs', undefined, locale)} />
        <div className="db-toolbar" style={{ marginTop: 14 }}>
          <label className="db-search"><Icon name="search" size={18} /><span className="db-sr-only">{t('dash.orders.search', undefined, locale)}</span><input type="search" className="db-input" placeholder={t('dash.orders.searchPlaceholder', undefined, locale)} value={query} onChange={(e) => setQuery(e.target.value)} data-testid="orders-search" /></label>
          <label className="db-muted" style={{ display: 'flex', gap: 8, alignItems: 'center' }}><span>{t('dash.orders.filterDate', undefined, locale)}</span><select className="db-select db-input--sm" style={{ width: 'auto' }} value={date} onChange={(e) => setDate(e.target.value as 'today' | 'all')} data-testid="orders-date"><option value="today">{t('dash.overview.today', undefined, locale)}</option><option value="all">{t('dash.orders.allDates', undefined, locale)}</option></select></label>
        </div>
        <div style={{ marginTop: 16, display: 'flex', flexDirection: 'column', gap: 12 }}>
          {state === 'loading' && <Skeleton rows={5} />}
          {state === 'error' && <ErrorState title={t('dash.orders.errorTitle', undefined, locale)} text={t('dash.error.loadText', undefined, locale)} onRetry={() => { void load() }} locale={locale} />}
          {state === 'ready' && list.length === 0 && <EmptyState icon="orders" title={t(`dash.orders.empty.${tab}`, undefined, locale)} text={query ? t('dash.orders.emptySearch', undefined, locale) : t('dash.orders.emptyText', undefined, locale)} action={query ? <button type="button" className="db-btn db-btn--outline" onClick={() => setQuery('')}>{t('dash.action.clearSearch', undefined, locale)}</button> : undefined} />}
          {state === 'ready' && list.map((o) => <OrderCard key={o.publicId} order={o} onChanged={() => { void load(true) }} />)}
          {state === 'ready' && history && history.nextCursor && <button type="button" className="db-btn db-btn--outline" onClick={() => { void more() }} data-testid="orders-more">{t('dash.action.loadMore', { shown: history.orders.length, total: history.total }, locale)}</button>}
        </div>
      </Card>
      <OrderDetailsDrawer onChanged={() => { void load(true) }} />
    </div>
  )
}

/** Restaurant order details (drawer over the orders list): snapshot, timeline, verification state — only what fulfilment needs. */
export function OrderDetailsDrawer({ onChanged }: { onChanged: () => void }) {
  const d = useDashboard(); const locale = d.locale; const rid = d.location!.restaurant.id; const tz = d.location!.restaurant.timezone; const nav = useNavigate(); const { orderNumber } = useParams()
  const [order, setOrder] = useState<Order | null>(null); const [state, setState] = useState<'idle' | 'loading' | 'ready' | 'missing'>('idle'); const { msg, toast } = useToastMessage()
  const load = useCallback(async () => { if (!orderNumber) { setOrder(null); setState('idle'); return } setState('loading'); const o = await d.repos.orders.get(rid, orderNumber); setOrder(o); setState(o ? 'ready' : 'missing') }, [d.repos, rid, orderNumber])
  useEffect(() => { void load() }, [load])
  const close = useCallback(() => nav(`${BASE}/orders`), [nav])
  const money = (m: number) => formatMoney(m, order?.pricing.currency ?? d.location!.restaurant.currency, locale)
  return (
    <Drawer open={!!orderNumber} onClose={close} title={order ? `${t('dash.orders.detailsTitle', undefined, locale)} ${order.orderNumber}` : t('dash.orders.detailsTitle', undefined, locale)} wide footer={order ? <><OrderActions order={order} onChanged={() => { toast(t('dash.orders.updated', undefined, locale)); void load(); onChanged() }} /></> : undefined}>
      {state === 'loading' && <Skeleton rows={6} />}
      {state === 'missing' && <EmptyState icon="warning" title={t('dash.orders.notFound', undefined, locale)} text={t('dash.orders.notFoundText', undefined, locale)} />}
      {state === 'ready' && order && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }} data-testid="order-details">
          <div className="db-status-strip"><StatusBadge status={order.orderStatus} locale={locale} /><Pill tone={order.paymentStatus === 'PAID' ? 'green' : 'amber'}>{t('dash.orders.payment', undefined, locale)}: {t(`oc.pay.${order.paymentStatus}`, undefined, locale)}</Pill>{order.delayed && <Pill tone="amber">{t('dash.orders.delayed', undefined, locale)}</Pill>}<Pill tone="muted">{t('dash.orders.pickupVerification', undefined, locale)}: {t(`oc.code.state.${order.pickupVerificationStatus}`, undefined, locale)}</Pill></div>
          <div className="db-form-row">
            <div><p className="db-muted" style={{ margin: 0, fontSize: '0.8rem' }}>{t('dash.orders.col.customer', undefined, locale)}</p><b dir="auto">{order.customerDisplayName ?? t('dash.orders.customerAnon', undefined, locale)}</b><p className="db-muted" style={{ margin: '2px 0 0', fontSize: '0.78rem' }}>{t('dash.orders.privacyNote', undefined, locale)}</p></div>
            <div><p className="db-muted" style={{ margin: 0, fontSize: '0.8rem' }}>{t('dash.orders.pickupTime', undefined, locale)}</p><b>{formatLocalDate(order.pickup.requestedAt, tz, locale)} · {formatLocalTime(order.pickup.requestedAt, tz, locale)} {zoneLabel(order.pickup.requestedAt, tz, locale)}</b><p className="db-muted" style={{ margin: '2px 0 0', fontSize: '0.78rem' }}>{order.pickup.methodLabel} · {tz}</p></div>
          </div>
          <Card title={t('dash.orders.col.items', undefined, locale)}>
            <ul className="db-list">{order.items.map((i) => <li key={i.lineId}><span style={{ flex: 1 }}><b dir="auto">{i.quantity}× {i.itemName}</b>{[...i.variants, ...i.modifiers].map((o) => <><br /><small className="db-muted" key={o.groupName + o.optionName} dir="auto">{o.groupName}: {o.optionName}{o.priceAdjustmentMinor ? ` (+${money(o.priceAdjustmentMinor)})` : ''}</small></>)}{i.specialInstructions && <><br /><small className="db-order-card__note" dir="auto">“{i.specialInstructions}”</small></>}</span><b>{money(i.lineTotalMinor)}</b></li>)}</ul>
            {order.orderNote && <p className="db-order-card__note" dir="auto"><Icon name="info" size={14} /> {t('dash.orders.orderNote', undefined, locale)}: {order.orderNote}</p>}
            <div className="db-settings-row" style={{ borderTop: '1px solid var(--db-line)', borderBottom: 0 }}><p>{t('dash.orders.subtotal', undefined, locale)}</p><b>{money(order.pricing.subtotalMinor)}</b></div>
            {order.pricing.discountMinor > 0 && <div className="db-settings-row" style={{ borderBottom: 0 }}><p>{t('dash.orders.discount', undefined, locale)}</p><b>−{money(order.pricing.discountMinor)}</b></div>}
            {[...order.pricing.taxes, ...order.pricing.fees].map((l) => <div key={l.id} className="db-settings-row" style={{ borderBottom: 0 }}><p>{l.label}</p><b>{money(l.amountMinor)}</b></div>)}
            <div className="db-settings-row" style={{ borderBottom: 0 }}><p><b>{t('dash.orders.total', undefined, locale)}</b></p><b className="db-kpi__value" style={{ fontSize: '1.2rem' }}>{money(order.pricing.totalMinor)} <span className="db-badge db-badge--muted">{order.pricing.currency}</span></b></div>
          </Card>
          <Card title={t('dash.orders.timeline', undefined, locale)}>
            <ol className="db-timeline" data-testid="order-timeline">{order.events.map((e) => <li key={e.eventId}><span className={`db-timeline__dot ${e.actor === 'restaurant' ? 'db-timeline__dot--restaurant' : ''} ${e.type === 'RESTAURANT_REJECTED' || e.type === 'CANCELLED' ? 'db-timeline__dot--stop' : ''}`} /><span><b>{t(`dash.event.${e.type}`, undefined, locale)}</b>{e.reasonKey && <> · <span className="db-muted">{t(`track.reason.${e.reasonKey}`, undefined, locale)}</span></>}<br /><small className="db-muted">{formatLocalDate(e.at, tz, locale)} · {formatLocalTime(e.at, tz, locale)} · {t(`dash.actor.${e.actor}`, undefined, locale)}</small></span></li>)}</ol>
          </Card>
          <p className="db-muted" style={{ margin: 0, fontSize: '0.8rem' }}>{t('dash.orders.paymentSafe', { method: order.payment.methodLabel, ref: order.payment.reference }, locale)}</p>
        </div>
      )}
      <ToastLine msg={msg} />
    </Drawer>
  )
}

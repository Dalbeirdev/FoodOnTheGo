import { useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { formatLocalTime } from '../../i18n/format'
import { t } from '../../i18n/strings'
import { Card, ErrorState, Icon, Skeleton, StatusBadge, Tabs, ToastLine, useToastMessage } from '../../dashboard/components/ui'
import { useAdmin } from '../AdminContext'
import { BASE } from '../AdminLayout'
import { Badge, DataTable, Details, DevNote, Select, Toolbar, useUrlState, type Column } from '../components/DataTable'
import type { AdminOrder, AdminOrderFilter } from '../types'
import { MarketScopeChip, Cell, KNOWN_MARKET_CODES, StatusPill, fmtDateTime, marketOptions, money, useLoad, usePageTitle } from './shared'

const DEFAULTS = { tab: 'all', q: '', market: 'all', date: 'all', page: '1' }
const TABS: AdminOrderFilter['tab'][] = ['all', 'active', 'preparing', 'ready', 'completed', 'cancelled', 'rejected', 'exception']

/** Order Management (Module 18): platform-wide monitoring with exception detection; no status editing in the frontend. */
export default function OrdersPage() {
  const a = useAdmin(); const locale = a.locale; const nav = useNavigate(); usePageTitle('adm.nav.orders')
  const [s, set] = useUrlState(DEFAULTS)
  const filter = useMemo<AdminOrderFilter>(() => ({ tab: (TABS.includes(s.tab as AdminOrderFilter['tab']) ? s.tab : 'all') as AdminOrderFilter['tab'], query: s.q, market: a.market === 'all' ? s.market : a.market, date: s.date as AdminOrderFilter['date'], page: Number(s.page) || 1, pageSize: 10 }), [s, a.market])
  const { data, state, reload } = useLoad(() => a.repos.orders.list(filter), [a.repos, filter])
  const columns: Array<Column<AdminOrder>> = [
    { id: 'n', label: t('adm.orders.col.order', undefined, locale), primary: true, render: (o) => <span className="db-table__num">{o.order.orderNumber}{o.exceptions.length > 0 && <> <Badge tone="red">{t(`adm.exception.${o.exceptions[0].kind}`, undefined, locale)}</Badge></>}</span> },
    { id: 'restaurant', label: t('adm.orders.col.restaurant', undefined, locale), render: (o) => <Cell primary={o.order.restaurant.name} secondary={o.order.restaurant.countryCode} /> },
    { id: 'customer', label: t('adm.orders.col.customer', undefined, locale), hideMobile: true, render: (o) => <span dir="auto">{o.order.customerDisplayName ?? t('dash.orders.customerAnon', undefined, locale)}</span> },
    { id: 'pickup', label: t('adm.orders.col.pickup', undefined, locale), hideMobile: true, render: (o) => formatLocalTime(o.order.pickup.requestedAt, o.order.pickup.restaurantTimezone, locale) },
    { id: 'status', label: t('adm.orders.col.status', undefined, locale), render: (o) => <StatusBadge status={o.order.orderStatus} locale={locale} /> },
    { id: 'payment', label: t('adm.orders.col.payment', undefined, locale), hideMobile: true, render: (o) => <StatusPill status={o.order.paymentStatus} prefix="adm.paymentStatus" dot /> },
    { id: 'total', label: t('adm.orders.col.total', undefined, locale), align: 'end', render: (o) => money(o.order.pricing.totalMinor, o.order.pricing.currency, locale) },
  ]
  return (
    <div className="db-page" data-testid="adm-orders">
      <div className="db-page__head"><div><h1 className="db-page__title">{t('adm.orders.title', undefined, locale)}</h1><p className="db-page__lead">{t('adm.orders.lead', undefined, locale)}</p></div></div>
      <Tabs tabs={TABS.map((id) => ({ id, label: t(`adm.orders.tab.${id}`, undefined, locale), count: data?.counts[id] }))} value={filter.tab} onChange={(tab) => set({ tab })} label={t('adm.orders.tabs', undefined, locale)} />
      <Card>
        <Toolbar search={s.q} onSearch={(q) => set({ q })} placeholder={t('adm.orders.search', undefined, locale)} locale={locale}>
          <Select label={t('adm.analytics.range', undefined, locale)} value={s.date} onChange={(date) => set({ date })} options={[{ value: 'all', label: t('adm.range.all', undefined, locale) }, { value: 'today', label: t('adm.range.today', undefined, locale) }, { value: '7d', label: t('adm.range.7d', undefined, locale) }]} testId="filter-date" />
          {a.market === 'all' ? <Select label={t('adm.orders.col.market', undefined, locale)} value={s.market} onChange={(market) => set({ market })} options={marketOptions(KNOWN_MARKET_CODES, locale)} testId="filter-market" /> : <MarketScopeChip />}
        </Toolbar>
        <DataTable columns={columns} rows={data?.items ?? []} keyOf={(o) => o.order.publicId} state={state} total={data?.total ?? 0} page={filter.page!} pageSize={10} onPage={(page) => set({ page: String(page) })} empty={{ icon: 'orders', title: filter.tab === 'exception' ? t('adm.orders.emptyException', undefined, locale) : t('adm.orders.empty', undefined, locale) }} onRetry={() => { void reload() }} locale={locale} testId="orders-table" caption={t('adm.orders.title', undefined, locale)} onRowClick={(o) => nav(`${BASE}/orders/${o.order.orderNumber}`)} actions={(o) => <Link to={`${BASE}/orders/${o.order.orderNumber}`} className="db-btn db-btn--outline db-btn--sm">{t('adm.table.details', undefined, locale)}</Link>} />
      </Card>
      <DevNote>{t('adm.order.overrideNote', undefined, locale)}</DevNote>
    </div>
  )
}

/** Order details: snapshot, shared event timeline (Module 14 semantics), payment, refunds, support, exceptions, admin notes. */
export function OrderDetailsPage() {
  const a = useAdmin(); const locale = a.locale; const { orderNumber = '' } = useParams(); usePageTitle('adm.nav.orders')
  const { data: o, state, reload, refresh } = useLoad(() => a.repos.orders.get(orderNumber), [a.repos, orderNumber])
  const [note, setNote] = useState(''); const [busy, setBusy] = useState(false); const { msg, toast } = useToastMessage()
  if (state === 'loading') return <div className="db-page"><Skeleton rows={6} /></div>
  if (state === 'error') return <div className="db-page"><ErrorState title={t('adm.error.loadTitle', undefined, locale)} onRetry={() => { void reload() }} locale={locale} /></div>
  if (!o) return <div className="db-page"><ErrorState title={t('adm.order.notFound', undefined, locale)} locale={locale} /><Link to={`${BASE}/orders`} className="db-link">← {t('adm.nav.orders', undefined, locale)}</Link></div>
  const order = o.order; const cur = order.pricing.currency; const m = (x: number) => money(x, cur, locale); const tz = order.pickup.restaurantTimezone
  return (
    <div className="db-page" data-testid="adm-order-details">
      <Link to={`${BASE}/orders`} className="db-link">← {t('adm.nav.orders', undefined, locale)}</Link>
      <div className="db-page__head"><div><h1 className="db-page__title">{t('adm.order.title', { n: order.orderNumber }, locale)}</h1><p className="db-page__lead"><StatusBadge status={order.orderStatus} locale={locale} /> <StatusPill status={order.paymentStatus} prefix="adm.paymentStatus" dot /> · <span dir="auto">{order.restaurant.name}</span> · {fmtDateTime(order.createdAt, locale)}</p></div></div>
      {o.exceptions.length > 0 && <div className="adm-critical-strip" role="alert" data-testid="order-exceptions"><Icon name="warning" size={18} /><span>{o.exceptions.map((e) => `${t(`adm.exception.${e.kind}`, undefined, locale)}: ${e.detail}`).join(' · ')}</span></div>}
      <div className="db-grid db-grid--2">
        <div className="db-grid" style={{ gap: 16 }}>
          <Card title={t('adm.order.items', undefined, locale)}>
            <ul className="db-list">{order.items.map((i) => <li key={i.lineId}><span style={{ flex: 1 }}><b dir="auto">{i.quantity}× {i.itemName}</b>{[...i.variants, ...i.modifiers].map((x) => <small key={x.groupName + x.optionName} className="db-muted" dir="auto"><br />{x.groupName}: {x.optionName}</small>)}</span><b>{m(i.lineTotalMinor)}</b></li>)}</ul>
            <div className="db-settings-row" style={{ borderTop: '1px solid var(--db-line)', borderBottom: 0 }}><p>{t('dash.orders.subtotal', undefined, locale)}</p><b>{m(order.pricing.subtotalMinor)}</b></div>
            {order.pricing.discountMinor > 0 && <div className="db-settings-row" style={{ borderBottom: 0 }}><p>{t('dash.orders.discount', undefined, locale)} {order.pricing.promoCode && <span className="adm-code">{order.pricing.promoCode}</span>}</p><b>−{m(order.pricing.discountMinor)}</b></div>}
            {[...order.pricing.taxes, ...order.pricing.fees].map((l) => <div key={l.id} className="db-settings-row" style={{ borderBottom: 0 }}><p>{l.label}</p><b>{m(l.amountMinor)}</b></div>)}
            <div className="db-settings-row" style={{ borderBottom: 0 }}><p><b>{t('dash.orders.total', undefined, locale)}</b></p><b className="db-kpi__value" style={{ fontSize: '1.2rem' }}>{m(order.pricing.totalMinor)} <Badge tone="muted">{cur}</Badge></b></div>
          </Card>
          <Card title={t('adm.order.timeline', undefined, locale)}>
            <ol className="db-timeline" data-testid="order-timeline">{order.events.map((e) => <li key={e.eventId}><span className={`db-timeline__dot ${e.actor === 'restaurant' ? 'db-timeline__dot--restaurant' : ''} ${e.type === 'RESTAURANT_REJECTED' || e.type === 'CANCELLED' ? 'db-timeline__dot--stop' : ''}`} /><span><b>{t(`dash.event.${e.type}`, undefined, locale)}</b><small className="db-muted"> · {t(`dash.actor.${e.actor}`, undefined, locale)} · {formatLocalTime(e.at, tz, locale)}{e.reasonKey ? ` · ${t(`track.reason.${e.reasonKey}`, undefined, locale)}` : ''}</small></span></li>)}</ol>
          </Card>
        </div>
        <div className="db-grid" style={{ gap: 16 }}>
          <Card title={t('adm.order.customer', undefined, locale)}><Details rows={[[t('adm.orders.col.customer', undefined, locale), order.customerDisplayName ?? t('dash.orders.customerAnon', undefined, locale)], [t('adm.order.restaurant', undefined, locale), `${order.restaurant.name} · ${order.restaurant.countryCode}`], [t('adm.orders.col.pickup', undefined, locale), formatLocalTime(order.pickup.requestedAt, tz, locale)], [t('adm.order.pickupVerification', undefined, locale), order.pickupVerificationStatus], [t('adm.order.journey', undefined, locale), order.journey ? `${order.journey.originName} → ${order.journey.destinationName}` : '—'], [t('adm.order.note', undefined, locale), order.orderNote || '—']]} /></Card>
          <Card title={t('adm.order.payment', undefined, locale)}>
            {!o.payment ? <p className="db-muted">{t('adm.order.noPayment', undefined, locale)}</p> : <Details rows={[[t('adm.payments.col.reference', undefined, locale), <Link key="p" to={`${BASE}/payments?q=${encodeURIComponent(o.payment.reference)}`} className="db-link">{o.payment.reference}</Link>], [t('adm.payments.col.status', undefined, locale), <StatusPill key="s" status={o.payment.status} prefix="adm.payState" dot />], [t('adm.payments.col.method', undefined, locale), t(`adm.payment.method.${o.payment.methodCategory}`, undefined, locale)], [t('adm.payments.col.amount', undefined, locale), money(o.payment.amountMinor, o.payment.currency, locale)], [t('adm.payments.col.provider', undefined, locale), o.payment.provider]]} />}
            <h3 className="db-card__title" style={{ fontSize: '0.95rem', marginTop: 14 }}>{t('adm.order.refunds', undefined, locale)}</h3>
            {o.refunds.length === 0 ? <p className="db-muted">{t('adm.order.noRefunds', undefined, locale)}</p> : <ul className="db-list">{o.refunds.map((r) => <li key={r.reference}><Link to={`${BASE}/refunds?q=${encodeURIComponent(r.reference)}`} className="db-link">{r.reference}</Link> · {money(r.requestedMinor, r.currency, locale)} <StatusPill status={r.status} prefix="adm.refundStatus" /></li>)}</ul>}
          </Card>
          <Card title={t('adm.order.support', undefined, locale)}>{o.supportCases.length === 0 ? <p className="db-muted">{t('adm.order.noSupport', undefined, locale)}</p> : <ul className="db-list">{o.supportCases.map((sc) => <li key={sc.id}><Link to={`${BASE}/support/${sc.id}`} className="db-link">{sc.ticketNumber}</Link> · {sc.summary} <StatusPill status={sc.status} prefix="adm.supportStatus" /></li>)}</ul>}</Card>
          <Card title={t('adm.order.notes', undefined, locale)}>
            {o.adminNotes.length === 0 ? <p className="db-muted">{t('adm.order.notesEmpty', undefined, locale)}</p> : o.adminNotes.map((n, i) => <div key={i} className="adm-note"><small>{fmtDateTime(n.at, locale)} · {n.by}</small>{n.text}</div>)}
            {a.can('orders.override') && <form className="adm-inline-form" onSubmit={(e) => { e.preventDefault(); if (!note.trim()) return; setBusy(true); void a.repos.orders.addNote(order.orderNumber, a.admin.id, note).then(async () => { setNote(''); await refresh(); toast(t('adm.saved', undefined, locale)) }).finally(() => setBusy(false)) }}><div className="db-field"><label className="db-field__label" htmlFor="onote">{t('adm.action.addNote', undefined, locale)}</label><input id="onote" className="db-input" value={note} onChange={(e) => setNote(e.target.value)} data-testid="order-note" /></div><button type="submit" className="db-btn db-btn--outline" disabled={busy}>{t('adm.action.addNote', undefined, locale)}</button></form>}
          </Card>
        </div>
      </div>
      <DevNote>{t('adm.order.overrideNote', undefined, locale)}</DevNote>
      <ToastLine msg={msg} />
    </div>
  )
}

import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { formatLocalTime, formatMoney } from '../../i18n/format'
import { t } from '../../i18n/strings'
import type { Order } from '../../order/repositories'
import { useDashboard } from '../DashboardContext'
import { BASE } from '../DashboardLayout'
import { BarChart } from '../components/Charts'
import { Card, Delta, ErrorState, Icon, KpiCard, Skeleton, StatusBadge, EmptyState, Thumb } from '../components/ui'
import type { OverviewSnapshot } from '../types'
import { OrderActions } from './OrdersPage'

/** Overview (Module 17): KPI cards, revenue summary chart, prep time / rating / unavailable items, recent orders, top items. */
export default function OverviewPage() {
  const d = useDashboard(); const loc = d.location!; const rid = loc.restaurant.id; const locale = d.locale
  const [snap, setSnap] = useState<OverviewSnapshot | null>(null); const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading')
  const load = useCallback(async () => { setState('loading'); try { setSnap(await d.repos.analytics.overview(rid)); setState('ready') } catch { setState('error') } }, [d.repos, rid])
  useEffect(() => { void load() }, [load])
  useEffect(() => { document.title = `${t('dash.nav.overview', undefined, locale)} · ${t('dash.brand', undefined, locale)}` }, [locale])
  const cur = loc.restaurant.currency; const money = (m: number) => formatMoney(m, cur, locale)
  return (
    <div className="db-page" data-testid="db-overview">
      <div className="db-page__head"><div><h1 className="db-page__title">{t('dash.overview.title', undefined, locale)}</h1><p className="db-page__lead">{t('dash.overview.lead', { restaurant: loc.restaurant.name }, locale)}</p></div><div className="db-page__actions"><span className="db-badge db-badge--muted"><Icon name="hours" size={14} /> {t('dash.overview.today', undefined, locale)} · {loc.restaurant.timezone}</span></div></div>
      {state === 'error' && <ErrorState title={t('dash.error.loadTitle', undefined, locale)} text={t('dash.error.loadText', undefined, locale)} onRetry={() => { void load() }} locale={locale} />}
      {state === 'loading' && <div className="db-grid db-grid--kpi">{[0, 1, 2, 3].map((i) => <div key={i} className="db-kpi"><Skeleton rows={2} className="db-skeleton--card" /></div>)}</div>}
      {state === 'ready' && snap && (
        <>
          <div className="db-grid db-grid--kpi">
            <KpiCard icon="orders" tone="orange" value={snap.ordersToday.toLocaleString(locale)} label={t('dash.kpi.ordersToday', undefined, locale)} delta={snap.ordersTodayDelta} locale={locale} testId="kpi-orders" />
            <KpiCard icon="timer" tone="blue" value={snap.preparing.toLocaleString(locale)} label={t('dash.kpi.preparing', undefined, locale)} locale={locale} testId="kpi-preparing" />
            <KpiCard icon="check" tone="green" value={snap.ready.toLocaleString(locale)} label={t('dash.kpi.ready', undefined, locale)} locale={locale} testId="kpi-ready" />
            <KpiCard icon="pickup" tone="purple" value={snap.completedToday.toLocaleString(locale)} label={t('dash.kpi.completed', undefined, locale)} locale={locale} testId="kpi-completed" />
          </div>
          <div className="db-grid db-grid--2">
            <Card title={t('dash.overview.revenue', undefined, locale)} subtitle={t('dash.overview.revenueSub', { currency: cur }, locale)} actions={<span className="db-kpi__icon db-kpi__icon--amber"><Icon name="money" /></span>}>
              <p className="db-kpi__value" data-testid="ov-revenue">{money(snap.revenueTodayMinor)} <Delta value={snap.revenueDelta} locale={locale} /></p>
              <p className="db-muted" style={{ marginTop: 0 }}>{t('dash.overview.revenueNote', undefined, locale)}</p>
              <BarChart title={t('dash.overview.revenueByHour', undefined, locale)} data={snap.revenueByHour} format={(v) => money(v)} height={170} testId="ov-revenue-chart" />
            </Card>
            <div className="db-grid" style={{ gap: 16 }}>
              <Card title={t('dash.kpi.avgPrep', undefined, locale)} actions={<span className="db-kpi__icon db-kpi__icon--blue"><Icon name="timer" /></span>}><p className="db-kpi__value">{t('dash.units.minutes', { n: snap.averagePrepMinutes }, locale)} <Delta value={snap.prepDelta} invert locale={locale} /></p></Card>
              <Card title={t('dash.kpi.avgRating', undefined, locale)} actions={<span className="db-kpi__icon db-kpi__icon--amber"><Icon name="star" /></span>}><p className="db-kpi__value">{snap.averageRating == null ? '—' : snap.averageRating.toLocaleString(locale, { maximumFractionDigits: 1 })} {snap.averageRating != null && <Delta value={snap.ratingDelta} suffix="" locale={locale} />}</p></Card>
              <Card title={t('dash.kpi.unavailable', undefined, locale)} tone={snap.unavailableItems > 0 ? 'warn' : undefined} actions={<Link to={`${BASE}/menu?status=unavailable`} className="db-link">{t('dash.overview.viewItems', undefined, locale)} →</Link>}><p className="db-kpi__value" data-testid="ov-unavailable">{t('dash.units.items', { n: snap.unavailableItems }, locale)}</p></Card>
            </div>
          </div>
          <div className="db-grid db-grid--2">
            <Card title={t('dash.overview.recentOrders', undefined, locale)} actions={<Link to={`${BASE}/orders`} className="db-link">{t('dash.overview.viewAll', undefined, locale)} →</Link>}>
              {snap.recentOrders.length === 0 ? <EmptyState icon="orders" title={t('dash.orders.emptyTitle', undefined, locale)} text={t('dash.orders.emptyText', undefined, locale)} /> : (
                <div className="db-table-wrap"><table className="db-table" data-testid="ov-recent">
                  <thead><tr><th scope="col">{t('dash.orders.col.number', undefined, locale)}</th><th scope="col">{t('dash.orders.col.customer', undefined, locale)}</th><th scope="col">{t('dash.orders.col.items', undefined, locale)}</th><th scope="col">{t('dash.orders.col.pickup', undefined, locale)}</th><th scope="col">{t('dash.orders.col.status', undefined, locale)}</th><th scope="col"><span className="db-sr-only">{t('dash.orders.col.actions', undefined, locale)}</span></th></tr></thead>
                  <tbody>{snap.recentOrders.map((o: Order) => <tr key={o.publicId}><td className="db-table__num">{o.orderNumber}</td><td dir="auto">{o.customerDisplayName ?? t('dash.orders.customerAnon', undefined, locale)}</td><td>{t('dash.units.items', { n: o.items.reduce((a, i) => a + i.quantity, 0) }, locale)}</td><td>{formatLocalTime(o.pickup.requestedAt, loc.restaurant.timezone, locale)}</td><td><StatusBadge status={o.orderStatus} locale={locale} /></td><td><div className="db-table__actions"><OrderActions order={o} compact onChanged={() => { void load() }} /></div></td></tr>)}</tbody>
                </table></div>
              )}
            </Card>
            <Card title={t('dash.overview.topItems', undefined, locale)} actions={<Link to={`${BASE}/analytics`} className="db-link">{t('dash.overview.menuAnalytics', undefined, locale)} →</Link>}>
              {snap.topItems.length === 0 ? <EmptyState icon="analytics" title={t('dash.analytics.emptyTitle', undefined, locale)} /> : <ul className="db-list" data-testid="ov-top">{snap.topItems.map((it, i) => <li key={it.name}><span className="db-rank">{i + 1}</span><Thumb src={it.image} name={it.name} /><span style={{ flex: 1 }} dir="auto"><b>{it.name}</b><br /><small className="db-muted">{t('dash.units.orders', { n: it.orders }, locale)}</small></span></li>)}</ul>}
            </Card>
          </div>
        </>
      )}
    </div>
  )
}

import { Link } from 'react-router-dom'
import { t } from '../../i18n/strings'
import { BarChart } from '../../dashboard/components/Charts'
import { Card, Delta, EmptyState, ErrorState, Icon, KpiCard, Skeleton, Thumb } from '../../dashboard/components/ui'
import { useAdmin } from '../AdminContext'
import { BASE } from '../AdminLayout'
import { Badge, DevNote } from '../components/DataTable'
import { Cell, Stars, StatusPill, fmtDate, fmtTime, money, useLoad, usePageTitle } from './shared'

/** Platform Overview (Module 18): KPI cards, orders chart, platform health, recent activity, pending approvals, top restaurants. */
export default function OverviewPage() {
  const a = useAdmin(); const locale = a.locale; usePageTitle('adm.nav.overview')
  const { data: snap, state, reload } = useLoad(() => a.repos.overview.snapshot(), [a.repos])
  return (
    <div className="db-page" data-testid="adm-overview">
      <div className="db-page__head"><div><h1 className="db-page__title">{t('adm.overview.title', undefined, locale)}</h1><p className="db-page__lead">{t('adm.overview.lead', undefined, locale)}</p></div><div className="db-page__actions"><span className="db-chip"><Icon name="hours" size={14} /> {t('adm.overview.updated', { time: fmtTime(new Date().toISOString(), locale) }, locale)}</span></div></div>
      {a.criticalCount > 0 && <div className="adm-critical-strip" role="alert" data-testid="critical-strip"><Icon name="warning" size={18} />{t('adm.overview.critical', { n: a.criticalCount }, locale)}<Link to={`${BASE}/notifications`}>{t('adm.action.viewAll', undefined, locale)} →</Link></div>}
      {state === 'error' && <ErrorState title={t('adm.error.loadTitle', undefined, locale)} text={t('adm.error.loadText', undefined, locale)} onRetry={() => { void reload() }} locale={locale} />}
      {state === 'loading' && <div className="db-grid db-grid--kpi">{[0, 1, 2, 3].map((i) => <div key={i} className="db-kpi"><Skeleton rows={2} className="db-skeleton--card" /></div>)}</div>}
      {state === 'ready' && snap && (
        <>
          <div className="db-grid db-grid--kpi adm-grid--kpi8">
            <KpiCard icon="restaurants" tone="orange" value={snap.restaurants.toLocaleString(locale)} label={t('adm.kpi.restaurants', undefined, locale)} delta={12} locale={locale} testId="kpi-restaurants" />
            <KpiCard icon="check" tone="green" value={snap.activeRestaurants.toLocaleString(locale)} label={t('adm.kpi.activeRestaurants', undefined, locale)} delta={8} locale={locale} testId="kpi-active" />
            <KpiCard icon="timer" tone="amber" value={snap.pendingApprovals.toLocaleString(locale)} label={t('adm.kpi.pending', undefined, locale)} locale={locale} testId="kpi-pending" />
            <KpiCard icon="customers" tone="blue" value={snap.customers.toLocaleString(locale)} label={t('adm.kpi.customers', undefined, locale)} delta={18} locale={locale} testId="kpi-customers" />
            <KpiCard icon="orders" tone="orange" value={snap.ordersToday.toLocaleString(locale)} label={t('adm.kpi.ordersToday', undefined, locale)} delta={16} locale={locale} testId="kpi-orders" />
            <KpiCard icon="refunds" tone="red" value={snap.refundsPending.toLocaleString(locale)} label={t('adm.kpi.refunds', undefined, locale)} delta={-8} invert locale={locale} testId="kpi-refunds" />
            <KpiCard icon="support" tone="purple" value={snap.openSupport.toLocaleString(locale)} label={t('adm.kpi.openSupport', undefined, locale)} locale={locale} testId="kpi-support" />
            <KpiCard icon="star" tone="amber" value={snap.platformRating == null ? '—' : snap.platformRating.toLocaleString(locale, { minimumFractionDigits: 1, maximumFractionDigits: 1 })} label={t('adm.kpi.rating', undefined, locale)} delta={0.2} suffix="" locale={locale} testId="kpi-rating" />
          </div>
          <div className="adm-grid--3">
            <Card title={t('adm.overview.ordersChart', undefined, locale)} subtitle={t('adm.overview.ordersByHour', undefined, locale)} className="adm-span2">
              <BarChart title={t('adm.overview.ordersByHour', undefined, locale)} data={snap.ordersByHour.filter((_, i) => i % 2 === 0)} height={190} testId="ov-orders-chart" />
              <p className="db-muted" style={{ margin: '8px 0 0' }}>{t('adm.overview.gmvNote', undefined, locale)}</p>
              <ul className="adm-money-list" data-testid="ov-gmv">{snap.gmvByCurrency.map((c) => <li key={c.currency}><b>{money(c.gmvMinor, c.currency, locale)}</b><small>{c.currency} · {t('dash.units.orders', { n: c.orders }, locale)}</small></li>)}</ul>
            </Card>
            <Card title={t('adm.overview.health', undefined, locale)} actions={<Link to={`${BASE}/system`} className="db-link">{t('adm.table.details', undefined, locale)} →</Link>}>
              <ul className="adm-health" data-testid="ov-health">{snap.health.map((s) => <li key={s.id}><span><Icon name={s.kind === 'provider' ? 'external' : 'system'} size={16} />{s.name}</span><StatusPill status={s.state} prefix="adm.health" dot /></li>)}</ul>
            </Card>
            <Card title={t('adm.overview.activity', undefined, locale)} actions={<Link to={`${BASE}/audit-logs`} className="db-link">{t('adm.action.viewAll', undefined, locale)} →</Link>}>
              {snap.activity.length === 0 ? <EmptyState title={t('adm.alerts.empty', undefined, locale)} /> : <ul className="adm-activity" data-testid="ov-activity">{snap.activity.map((x) => <li key={x.id}><span className={`adm-activity__icon adm-activity__icon--${x.kind}`}><Icon name={x.kind === 'audit' ? 'audit' : x.kind === 'flagged_review' ? 'reviews' : x.kind === 'support_escalation' ? 'support' : x.kind === 'restaurant_approval' ? 'restaurants' : x.kind === 'refund_failure' ? 'refunds' : x.kind === 'system_degradation' ? 'warning' : 'payments'} size={16} /></span><span><b dir="auto">{x.title}</b><small dir="auto">{x.detail}</small></span><time dateTime={x.at}>{fmtTime(x.at, locale)}</time></li>)}</ul>}
            </Card>
          </div>
          <div className="db-grid db-grid--2">
            <Card title={t('adm.overview.pending', undefined, locale)} actions={<Link to={`${BASE}/restaurants?tab=pending`} className="db-link">{t('adm.action.viewAll', undefined, locale)} →</Link>}>
              {snap.pendingRestaurants.length === 0 ? <EmptyState icon="check" title={t('adm.overview.noPending', undefined, locale)} /> : (
                <div className="db-table-wrap" tabIndex={0}><table className="db-table" data-testid="ov-pending"><caption className="db-sr-only">{t('adm.overview.pending', undefined, locale)}</caption>
                  <thead><tr><th scope="col">{t('adm.restaurants.col.restaurant', undefined, locale)}</th><th scope="col">{t('adm.restaurants.col.locations', undefined, locale)}</th><th scope="col">{t('adm.restaurants.col.submitted', undefined, locale)}</th><th scope="col">{t('adm.restaurants.col.status', undefined, locale)}</th><th scope="col" className="adm-td--end">{t('adm.table.actions', undefined, locale)}</th></tr></thead>
                  <tbody>{snap.pendingRestaurants.map((r) => <tr key={r.restaurant.id}><td><Cell thumb={<Thumb src={r.restaurant.image || null} name={r.restaurant.name} size={36} />} primary={r.restaurant.name} secondary={`${r.restaurant.address.locality ?? ''}, ${r.restaurant.countryCode}`} /></td><td>{t('adm.restaurants.locations', { n: r.locationCount }, locale)}</td><td>{fmtDate(r.createdAt, locale)}</td><td><StatusPill status={r.status} prefix="adm.restaurants.status" /></td><td className="adm-td--end"><Link to={`${BASE}/restaurants/${r.restaurant.id}`} className="db-btn db-btn--outline db-btn--sm">{t('adm.action.review', undefined, locale)}</Link></td></tr>)}</tbody>
                </table></div>)}
            </Card>
            <Card title={t('adm.overview.top', undefined, locale)} actions={<Link to={`${BASE}/analytics`} className="db-link">{t('adm.nav.analytics', undefined, locale)} →</Link>}>
              <div className="db-table-wrap" tabIndex={0}><table className="db-table" data-testid="ov-top"><caption className="db-sr-only">{t('adm.overview.top', undefined, locale)}</caption>
                <thead><tr><th scope="col">#</th><th scope="col">{t('adm.restaurants.col.restaurant', undefined, locale)}</th><th scope="col" className="adm-td--end">{t('adm.restaurants.col.orders', undefined, locale)}</th><th scope="col" className="adm-td--end">{t('adm.analytics.col.gmv', undefined, locale)}</th><th scope="col">{t('adm.restaurants.col.rating', undefined, locale)}</th></tr></thead>
                <tbody>{snap.topRestaurants.map((r, i) => <tr key={r.name}><td><span className="db-rank">{i + 1}</span></td><td dir="auto">{r.name}</td><td className="adm-td--end">{r.orders.toLocaleString(locale)}</td><td className="adm-td--end">{money(r.gmvMinor, r.currency, locale)} <Badge tone="muted">{r.currency}</Badge></td><td><Stars rating={r.rating} /> {r.rating.toLocaleString(locale, { minimumFractionDigits: 1 })}</td></tr>)}</tbody>
              </table></div>
              <p className="db-muted" style={{ margin: '8px 0 0', fontSize: '0.8rem' }}><Delta value={12.4} locale={locale} /> {t('adm.range.30d', undefined, locale)}</p>
            </Card>
          </div>
          <DevNote>{t('adm.mockNote', undefined, locale)}</DevNote>
        </>
      )}
    </div>
  )
}

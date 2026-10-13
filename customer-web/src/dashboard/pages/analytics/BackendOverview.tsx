import { useEffect, useMemo } from 'react'
import { Link } from 'react-router-dom'
import { t } from '../../../i18n/strings'
import { describeAnalyticsError, type AnalyticsFilter, type RestaurantAnalyticsReports } from '../../api/apiAnalytics'
import { useDashboard } from '../../DashboardContext'
import { BASE } from '../../DashboardLayout'
import { DonutChart } from '../../components/Charts'
import { Card, ErrorState, Icon, Skeleton } from '../../components/ui'
import { fmtDay, isNumber, tickLabels } from './format'
import { CountBars, Empty, MetaStrip, MetricGrid, type Definitions } from './parts'
import { useReport } from './useReport'

/**
 * The dashboard's home on the backend (Module 38): TODAY SO FAR at the location chosen in the header, read from the
 * same analytics overview as the Analytics page. Nothing on it is a development sample: there is no invented revenue
 * by hour, no invented change against yesterday (half a day against a whole one would say nothing) and no order list
 * from the browser's own store — the order queue is one link away.
 */
const HOME_KEYS = ['orders_placed', 'orders_completed', 'completed_order_total', 'average_order_value', 'restaurant_acceptance_rate', 'average_rating']

export function BackendOverview({ repo }: { repo: RestaurantAnalyticsReports }) {
  const d = useDashboard(); const locale = d.locale; const loc = d.location!
  useEffect(() => { document.title = `${t('dash.nav.overview', undefined, locale)} · ${t('dash.brand', undefined, locale)}` }, [locale])
  // The backend's id of the header's location; the figures are that location's alone.
  const locationId = loc.live?.locationId ?? null; const canRead = d.can('analytics.view')
  const filter = useMemo<AnalyticsFilter>(() => ({ location: locationId, preset: 'today' }), [locationId])
  const report = useReport(canRead ? () => repo.overview(filter) : null, [repo, filter, canRead], `${locationId ?? 'all'}|${canRead}`)
  const catalog = useReport(canRead ? () => repo.catalog() : null, [repo, canRead])
  const defs = useMemo<Definitions>(() => { const byKey = new Map((catalog.data?.metrics ?? []).map((m) => [m.key, m])); return { state: catalog.state, find: (key) => byKey.get(key) } }, [catalog.data, catalog.state])
  const data = report.state === 'ready' ? report.data : null
  const problem = report.state === 'error' ? describeAnalyticsError(report.error, locale) : null
  const restricted = !canRead || problem?.kind === 'forbidden'
  const ticks = data ? tickLabels(data.series.points.map((p) => p.bucket), data.series.bucket) : []
  const statuses = (data?.status_distribution ?? []).filter((r) => isNumber(r.count) && r.count > 0)
  const shortcuts: Array<[string, string, string, boolean]> = [
    ['orders', `${BASE}/orders`, 'orders', d.can('orders.view')], ['analytics', `${BASE}/analytics${locationId ? `?location=${encodeURIComponent(locationId)}` : ''}`, 'analytics', canRead],
    ['menu', `${BASE}/menu`, 'menu', d.can('menu.view')], ['earnings', `${BASE}/earnings`, 'money', d.can('finance.view')],
  ]
  return (
    <div className="db-page" data-testid="db-overview" data-source="backend">
      <div className="db-page__head"><div><h1 className="db-page__title">{t('dash.overview.title', undefined, locale)}</h1><p className="db-page__lead">{t('dash.ra.home.lead', { restaurant: loc.restaurant.name }, locale)}</p></div><div className="db-page__actions"><span className="db-badge db-badge--muted" data-testid="ra-home-period"><Icon name="hours" size={14} /> {t('dash.ra.home.today', undefined, locale)} · {data?.meta.timezone ?? loc.restaurant.timezone}</span></div></div>
      {restricted && <Card><p className="ra-empty" role="status" data-testid="ra-home-restricted">{t('dash.ra.home.restricted', undefined, locale)}</p></Card>}
      {report.state === 'loading' && <div className="db-grid ra-grid--kpi">{[0, 1, 2].map((i) => <div key={i} className="db-kpi"><Skeleton rows={2} /></div>)}</div>}
      {problem && problem.kind !== 'forbidden' && <div data-testid="ra-home-error"><ErrorState title={t('dash.ra.error.title', undefined, locale)} text={problem.message} onRetry={report.reload} locale={locale} /></div>}
      {data && <>
        <MetaStrip meta={data.meta} locale={locale} />
        {/* Today against the whole of yesterday is not a comparison: the cards carry the figure and its definition only. */}
        <MetricGrid metrics={data.metrics} keys={HOME_KEYS} defs={defs} locale={locale} prefix="ra-home-kpi" compare={false} testId="ra-home-kpis" />
        <div className="db-grid db-grid--half">
          <Card title={t('dash.ra.home.ordersByHour', undefined, locale)} subtitle={t('dash.ra.chart.axes', { unit: t('dash.ra.chart.unit.orders', undefined, locale), bucket: t(`dash.ra.bucket.${data.series.bucket}`, undefined, locale), from: fmtDay(data.meta.period.from, locale), to: fmtDay(data.meta.period.to, locale), zone: data.meta.timezone }, locale)}>
            <CountBars title={t('dash.ra.home.ordersByHour', undefined, locale)} points={data.series.points.map((p, i) => ({ label: p.bucket, tick: ticks[i], value: p.orders_placed }))} columns={[t(`dash.ra.col.bucket.${data.series.bucket}`, undefined, locale), t('dash.ra.col.orders', undefined, locale)]} locale={locale} color="var(--db-orange)" testId="ra-home-orders-chart" />
          </Card>
          <Card title={t('dash.ra.status.title', undefined, locale)} subtitle={t('dash.ra.status.sub', undefined, locale)}>
            {statuses.length === 0 ? <Empty locale={locale} testId="ra-home-status-empty" /> : <DonutChart title={t('dash.ra.status.title', undefined, locale)} data={statuses.map((r) => ({ label: r.label, value: r.count }))} format={(v) => v.toLocaleString(locale)} testId="ra-home-status" />}
          </Card>
        </div>
      </>}
      <Card title={t('dash.ra.home.shortcuts', undefined, locale)}>
        <div className="db-toolbar" data-testid="ra-home-shortcuts">{shortcuts.filter(([, , , allowed]) => allowed).map(([id, to, icon]) => <Link key={id} to={to} className="db-btn db-btn--outline" data-testid={`ra-home-link-${id}`}><Icon name={icon} size={16} /> {t(`dash.ra.home.${id}`, undefined, locale)}</Link>)}</div>
      </Card>
    </div>
  )
}

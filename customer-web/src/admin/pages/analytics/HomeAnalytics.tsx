import { useMemo, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { Card, Icon, Skeleton } from '../../../dashboard/components/ui'
import { BASE } from '../../AdminLayout'
import { ApiAdminAnalyticsRepository, DEFAULT_PRESET, type Metric } from '../../api/ApiAdminAnalyticsRepository'
import { LiveStrip, OrderTrend } from './blocks'
import { dayLabel, instant, say } from './format'
import { Kpi, isMetric, useReport, type Definitions } from './parts'

/**
 * The figures of the Platform Overview when analytics runs on the backend (Module 38): the "Right now" strip, the
 * headline figures of the last 30 days and the order trend — in place of the development snapshot and its made-up
 * deltas. The comparison under a figure is the backend's (against the 30 days before), or nothing.
 *
 * The open-support card is not one of these: it stays the service desk's own count (Module 37) and is handed in.
 */
const LOOK: Record<string, { icon: string; tone: 'orange' | 'blue' | 'green' | 'purple' | 'amber' | 'red' }> = {
  orders_completed: { icon: 'orders', tone: 'orange' }, ordering_customers: { icon: 'customers', tone: 'blue' }, active_restaurants: { icon: 'restaurants', tone: 'green' },
  gmv: { icon: 'money', tone: 'purple' }, platform_commission: { icon: 'payments', tone: 'green' }, order_completion_rate: { icon: 'check', tone: 'green' }, refund_rate_orders: { icon: 'refunds', tone: 'red' },
}
/** Shown by the support card of the page instead. */
const ELSEWHERE = ['support_open_cases']

export function HomeAnalytics({ repository, market, locale, support, liveMs }: { repository?: ApiAdminAnalyticsRepository; /** A market slug or `all` — the header's market. */ market: string; locale: string; /** The service desk's own open-cases card. */ support?: ReactNode; liveMs?: number }) {
  const repo = useMemo(() => repository ?? new ApiAdminAnalyticsRepository(), [repository])
  const overview = useReport(() => repo.overview({ market, preset: DEFAULT_PRESET }), [repo, market])
  const catalog = useReport(() => repo.catalog(), [repo])
  const definitions = useMemo<Definitions>(() => ({ state: catalog.state === 'ready' ? 'ready' : catalog.state === 'error' ? 'error' : 'loading', byKey: new Map((catalog.data?.metrics ?? []).map((m) => [m.key, m])) }), [catalog.state, catalog.data])
  const d = overview.data; const meta = d?.meta; const fresh = meta?.freshness
  const metrics: Metric[] = d ? Object.values(d.metrics ?? {}).filter(isMetric).filter((m) => !ELSEWHERE.includes(m.key)) : []
  return (
    <>
      <LiveStrip repo={repo} market={market} locale={locale} everyMs={liveMs} />
      {meta && (
        <div className="adm-an-meta" data-testid="ov-an-meta" data-stale={fresh?.stale ? 'true' : 'false'}>
          <span>{say(`preset.${DEFAULT_PRESET}`, undefined, locale)}{meta.period ? ` · ${say('range', { from: dayLabel(meta.period.from, locale), to: dayLabel(meta.period.to, locale) }, locale)} · ${say('zone', { zone: meta.timezone }, locale)}` : ''}</span>
          {fresh && <span data-testid="ov-an-freshness">{fresh.label}{fresh.aggregated_at ? ` · ${say('fresh.asOf', { time: instant(fresh.aggregated_at, meta.timezone, locale) }, locale)}` : fresh.mode === 'AGGREGATED' ? ` · ${say('fresh.never', undefined, locale)}` : ''}</span>}
        </div>
      )}
      {fresh?.stale && <p className="adm-an-stale" role="status" data-testid="ov-an-stale"><Icon name="warning" size={16} /> {say('fresh.stale', undefined, locale)}</p>}
      <div className="db-grid db-grid--kpi adm-grid--kpi8" data-testid="ov-an-kpis" data-source="backend" data-state={overview.state}>
        {overview.state === 'loading' && Array.from({ length: 7 }, (_, i) => <div key={i} className="db-kpi"><Skeleton rows={2} className="db-skeleton--card" /></div>)}
        {overview.state === 'ready' && metrics.map((m) => <Kpi key={m.key} metric={m} definitions={definitions} locale={locale} icon={LOOK[m.key]?.icon} tone={LOOK[m.key]?.tone} />)}
        {support}
      </div>
      {overview.state === 'error' && (
        <p className="adm-an-denied" role="alert" data-testid="ov-an-error" data-kind={overview.problem?.kind}>
          <Icon name="warning" size={18} /> {overview.problem?.kind === 'forbidden' ? say('error.forbidden', undefined, locale) : say('home.failed', undefined, locale)}
          {overview.problem?.kind !== 'forbidden' && <> <button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={overview.reload}>{say('home.retry', undefined, locale)}</button></>}
        </p>
      )}
      {overview.state === 'ready' && d && (
        <Card title={say('home.trend', undefined, locale)} subtitle={say(`preset.${DEFAULT_PRESET}`, undefined, locale)} actions={<Link to={`${BASE}/analytics`} className="db-link" data-testid="ov-an-open">{say('home.open', undefined, locale)} →</Link>}>
          <OrderTrend trend={d.order_trend} meta={d.meta} locale={locale} testId="ov-an-trend" />
        </Card>
      )}
    </>
  )
}

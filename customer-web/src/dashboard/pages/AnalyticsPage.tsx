import { useCallback, useEffect, useState } from 'react'
import { formatMoney } from '../../i18n/format'
import { t } from '../../i18n/strings'
import { useDashboard } from '../DashboardContext'
import { BarChart, DonutChart, LineChart, RatingBars } from '../components/Charts'
import { Card, ErrorState, KpiCard, PageHeader, Pill, Skeleton, Thumb } from '../components/ui'
import type { AnalyticsQuery, AnalyticsRange, AnalyticsSummary } from '../types'

/** Analytics (Module 17): date range, KPIs, revenue trend, order volume, status distribution, top items, pickup hours, ratings. */
export default function AnalyticsPage() {
  const d = useDashboard(); const locale = d.locale; const loc = d.location!; const rid = loc.restaurant.id; const cur = loc.restaurant.currency
  const [range, setRange] = useState<AnalyticsRange>('7d'); const [from, setFrom] = useState(''); const [to, setTo] = useState(''); const [data, setData] = useState<AnalyticsSummary | null>(null); const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading')
  const load = useCallback(async () => { setState('loading'); try { const q: AnalyticsQuery = range === 'custom' ? { range, from: from || undefined, to: to || undefined } : { range }; setData(await d.repos.analytics.summary(rid, q)); setState('ready') } catch { setState('error') } }, [d.repos, rid, range, from, to])
  useEffect(() => { if (range !== 'custom' || (from && to)) void load() }, [load, range, from, to])
  useEffect(() => { document.title = `${t('dash.nav.analytics', undefined, locale)} · ${t('dash.brand', undefined, locale)}` }, [locale])
  const money = (m: number) => formatMoney(m, cur, locale)
  return (
    <div className="db-page" data-testid="db-analytics">
      <PageHeader title={t('dash.analytics.title', undefined, locale)} lead={t('dash.analytics.lead', { currency: cur }, locale)} actions={<div className="db-toolbar" role="group" aria-label={t('dash.analytics.range', undefined, locale)}>{(['today', '7d', '30d', 'custom'] as AnalyticsRange[]).map((r) => <button key={r} type="button" className="db-chip" aria-pressed={range === r} onClick={() => setRange(r)} data-testid={`range-${r}`}>{t(`dash.analytics.range.${r}`, undefined, locale)}</button>)}</div>} />
      {range === 'custom' && <div className="db-toolbar"><label>{t('dash.analytics.from', undefined, locale)} <input type="date" className="db-input db-input--sm" style={{ width: 'auto' }} value={from} onChange={(e) => setFrom(e.target.value)} /></label><label>{t('dash.analytics.to', undefined, locale)} <input type="date" className="db-input db-input--sm" style={{ width: 'auto' }} value={to} onChange={(e) => setTo(e.target.value)} /></label></div>}
      {state === 'error' && <ErrorState title={t('dash.error.loadTitle', undefined, locale)} onRetry={() => { void load() }} locale={locale} />}
      {state === 'loading' && <div className="db-grid db-grid--kpi">{[0, 1, 2, 3].map((i) => <div key={i} className="db-kpi"><Skeleton rows={2} /></div>)}</div>}
      {state === 'ready' && data && (
        <>
          <p className="db-badge db-badge--muted" style={{ alignSelf: 'flex-start' }}>{t('dash.analytics.mockNote', undefined, locale)}</p>
          <div className="db-grid db-grid--kpi">
            <KpiCard icon="orders" tone="orange" value={data.orders.toLocaleString(locale)} label={t('dash.analytics.orders', undefined, locale)} delta={data.ordersDelta} locale={locale} testId="an-orders" />
            <KpiCard icon="money" tone="green" value={money(data.revenueMinor)} label={t('dash.analytics.revenue', { currency: cur }, locale)} delta={data.revenueDelta} locale={locale} testId="an-revenue" />
            <KpiCard icon="pickup" tone="blue" value={money(data.averageOrderMinor)} label={t('dash.analytics.aov', undefined, locale)} locale={locale} />
            <KpiCard icon="timer" tone="purple" value={t('dash.units.minutes', { n: data.averagePrepMinutes }, locale)} label={t('dash.analytics.prep', undefined, locale)} locale={locale} />
          </div>
          <div className="db-grid db-grid--half">
            <Card title={t('dash.analytics.revenueTrend', undefined, locale)} subtitle={cur}><LineChart title={t('dash.analytics.revenueTrend', undefined, locale)} data={data.revenueByDay} format={money} testId="an-revenue-chart" /></Card>
            <Card title={t('dash.analytics.orderVolume', undefined, locale)}><BarChart title={t('dash.analytics.orderVolume', undefined, locale)} data={data.ordersByDay} color="var(--db-blue)" testId="an-orders-chart" /></Card>
          </div>
          <div className="db-grid db-grid--3">
            <Card title={t('dash.analytics.statusDist', undefined, locale)} subtitle={t('dash.analytics.cancelRate', { rate: (data.cancellationRate * 100).toLocaleString(locale, { maximumFractionDigits: 1 }) }, locale)}><DonutChart title={t('dash.analytics.statusDist', undefined, locale)} data={data.statusDistribution.map((s) => ({ label: t(`dash.status.${s.status}`, undefined, locale), value: s.count }))} testId="an-status-chart" /></Card>
            <Card title={t('dash.analytics.topItems', undefined, locale)}><ul className="db-list">{data.topItems.map((it, i) => <li key={it.name}><span className="db-rank">{i + 1}</span><Thumb src={it.image} name={it.name} /><span style={{ flex: 1 }} dir="auto"><b>{it.name}</b><br /><small className="db-muted">{t('dash.units.orders', { n: it.orders }, locale)}</small></span></li>)}</ul></Card>
            <Card title={t('dash.analytics.ratings', undefined, locale)} subtitle={data.averageRating == null ? t('dash.reviews.emptyTitle', undefined, locale) : t('dash.analytics.ratingSub', { avg: data.averageRating.toLocaleString(locale, { maximumFractionDigits: 1 }), n: data.reviewCount }, locale)}><RatingBars distribution={data.ratingDistribution} locale={locale} /></Card>
          </div>
          <Card title={t('dash.analytics.pickupHours', undefined, locale)} subtitle={t('dash.analytics.pickupHoursSub', { zone: loc.restaurant.timezone }, locale)} actions={<Pill tone="amber">{t('dash.units.items', { n: data.unavailableItems }, locale)} {t('dash.availability.unavailable', undefined, locale).toLowerCase()}</Pill>}><BarChart title={t('dash.analytics.pickupHours', undefined, locale)} data={data.pickupHours} color="var(--db-green)" height={160} testId="an-pickup-chart" /></Card>
        </>
      )}
    </div>
  )
}

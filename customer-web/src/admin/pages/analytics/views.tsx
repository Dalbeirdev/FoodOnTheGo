import { useMemo, type ReactNode } from 'react'
import { BarChart } from '../../../dashboard/components/Charts'
import { Icon } from '../../../dashboard/components/ui'
import { formatMoney } from '../../../i18n/format'
import { Badge, Select, Stat } from '../../components/DataTable'
import { code } from '../finance/helpers'
import type { CityRowsDto, Metric, OrdersDto, RestaurantRowDto } from '../../api/ApiAdminAnalyticsRepository'
import { FunnelTable, OrderTrend, QualityPanel, LiveStrip, chartCaption } from './blocks'
import { bucketLabel, cellMetric, count, decimal, duration, durationOf, entriesOf, moneyText, percent, say } from './format'
import { Breakdown, Empty, KpiGrid, Note, ReportFrame, Restricted, Section, StatList, Table, metricsOf, useReport, useViewReport, type ViewProps } from './parts'
import { SeriesChart } from './SeriesChart'

/**
 * The report views of Platform Analytics (Module 38). Each view asks for ONE report of the backend for the market
 * and the period of the filter bar and shows it as it arrived: nothing is summed, ranked or estimated here.
 */
const TOO_FEW = (locale: string) => say('suppressed', undefined, locale)
const orDash = (v: number | null | undefined, locale: string) => (typeof v === 'number' && Number.isFinite(v) ? count(v, locale) : '—')

/* ------------------------------------------------------------------ Overview */
function Terms({ terms, locale }: { terms: Record<string, string> | undefined; locale: string }) {
  const rows = Object.entries(terms ?? {})
  if (rows.length === 0) return null
  return <ul className="adm-an-terms" aria-label={say('terms', undefined, locale)} data-testid="an-terms">{rows.map(([k, v]) => <li key={k} data-term={k}>{v}</li>)}</ul>
}
export function OverviewView(p: ViewProps & { liveMs?: number }) {
  const { locale, definitions } = p
  const report = useViewReport(p, (q) => p.repo.overview(q))
  return (
    <>
      <LiveStrip repo={p.repo} market={p.market} locale={locale} everyMs={p.liveMs} />
      <ReportFrame report={report} locale={locale}>{(d) => (
        <>
          {/* The headline figures only — everything else has a tab of its own. */}
          <KpiGrid metrics={d.metrics} definitions={definitions} locale={locale} testId="an-overview-kpis" />
          <Section title={say('overview.trend', undefined, locale)} testId="an-overview-trend"><OrderTrend trend={d.order_trend} meta={d.meta} locale={locale} /></Section>
          <div className="adm-an-grid2">
            <Section title={say('overview.funnel', undefined, locale)} subtitle={say('funnel.unit', undefined, locale)} testId="an-overview-funnel"
              actions={<button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => p.set({ tab: 'customers' })} data-testid="an-open-funnel">{say('overview.openFunnel', undefined, locale)}</button>}>
              <StatList metrics={metricsOf(d.funnel?.metrics)} block="funnel" definitions={definitions} locale={locale} />
              <FunnelTable stages={d.funnel?.stages ?? []} locale={locale} full={false} testId="an-overview-funnel-table" />
            </Section>
            <Section title={say('overview.operations', undefined, locale)} testId="an-overview-operations"><StatList metrics={metricsOf(d.restaurant_operations)} block="restaurant_operations" definitions={definitions} locale={locale} /></Section>
            <Section title={say('overview.routes', undefined, locale)} testId="an-overview-routes"><StatList metrics={metricsOf(d.route_coverage)} block="route_coverage" definitions={definitions} locale={locale} /></Section>
            <Section title={say('overview.finance', undefined, locale)} testId="an-overview-finance">
              {d.finance_summary?.restricted !== false ? <Restricted block="finance_summary" locale={locale} /> : <><StatList metrics={metricsOf(d.finance_summary)} block="finance_summary" definitions={definitions} locale={locale} /><Terms terms={d.finance_summary.terms} locale={locale} /></>}
            </Section>
            <Section title={say('overview.support', undefined, locale)} testId="an-overview-support">
              {d.support_health?.restricted !== false ? <Restricted block="support_health" locale={locale} /> : <StatList metrics={metricsOf(d.support_health)} block="support_health" definitions={definitions} locale={locale} />}
            </Section>
          </div>
        </>
      )}</ReportFrame>
      <QualityPanel repo={p.repo} locale={locale} />
    </>
  )
}

/* ------------------------------------------------------------------ Customers (with the funnel) */
const PLATFORMS = ['all', 'WEB', 'ANDROID']
export function CustomersView(p: ViewProps) {
  const { locale, definitions } = p
  const report = useViewReport(p, (q) => p.repo.customers(q))
  const key = p.query ? JSON.stringify(p.query) : ''
  const platform = PLATFORMS.includes(p.s.platform) ? p.s.platform : 'all'
  const funnel = useReport(p.query ? () => p.repo.funnel({ ...p.query, platform: platform as 'all' | 'WEB' | 'ANDROID' }) : null, [p.repo, key, platform])
  return (
    <>
      <ReportFrame report={report} locale={locale}>{(d) => {
        const months = Math.max(0, ...d.retention.cohorts.flatMap((c) => c.months.map((m) => m.month_number)))
        const points = d.series?.points ?? []
        return (
          <>
            <KpiGrid metrics={d.metrics} definitions={definitions} locale={locale} testId="an-customers-kpis" />
            <div className="adm-an-grid2">
              <Section title={say('customers.segments', undefined, locale)} testId="an-customers-segments">
                {d.segments.length === 0 ? <Empty locale={locale} /> : <Table caption={say('customers.segments', undefined, locale)} rows={d.segments} keyOf={(r) => r.key} rowProps={(r) => ({ 'data-key': r.key })} columns={[
                  { id: 'segment', head: say('customers.col.segment', undefined, locale), rowHeader: true, cell: (r) => r.label },
                  { id: 'customers', head: say('customers.col.customers', undefined, locale), end: true, cell: (r) => count(r.customers, locale) },
                ]} />}
              </Section>
              <Section title={say('customers.series', undefined, locale)} testId="an-customers-series-section">
                <SeriesChart title={say('customers.series', undefined, locale)} labels={points.map((x) => bucketLabel(x.bucket))} locale={locale} testId="an-customers-series" axisLabel={say('customers.axis', undefined, locale)}
                  caption={chartCaption(d.series.bucket, d.series.timezone, d.meta, locale)} bucketHead={say(`bucketHead.${d.series.bucket}`, undefined, locale)} format={(v) => count(v, locale)}
                  series={[{ id: 'new_accounts', label: say('customers.newAccounts', undefined, locale), values: points.map((x) => x.new_accounts) }, { id: 'first_time_ordering', label: say('customers.firstTime', undefined, locale), values: points.map((x) => x.first_time_ordering) }]} />
                <Note>{d.series.note}</Note>
              </Section>
            </div>
            <Section title={say('customers.retention', undefined, locale)} subtitle={d.retention.definition} testId="an-customers-retention">
              {d.retention.cohorts.length === 0 ? <Empty locale={locale} /> : (
                <Table caption={say('customers.retention', undefined, locale)} rows={d.retention.cohorts} keyOf={(c) => c.cohort} testId="an-retention-table"
                  rowProps={(c) => ({ 'data-testid': `an-cohort-${c.cohort}`, 'data-insufficient': c.insufficient ? 'true' : 'false', className: c.insufficient ? 'adm-an-row--muted' : undefined })}
                  columns={[
                    { id: 'cohort', head: say('customers.col.cohort', undefined, locale), rowHeader: true, cell: (c) => <>{c.cohort}{c.insufficient && <> <Badge tone="muted">{say('insufficient', undefined, locale)}</Badge></>}</> },
                    { id: 'size', head: say('customers.col.cohortSize', undefined, locale), end: true, cell: (c) => count(c.customers, locale) },
                    ...Array.from({ length: months }, (_, i) => ({
                      id: `m${i + 1}`, head: say('customers.col.month', { n: i + 1 }, locale), end: true,
                      cell: (c: (typeof d.retention.cohorts)[number]) => {
                        const month = c.months.find((x) => x.month_number === i + 1)
                        if (!month) return '—'
                        // A share of a cohort that is too small to mean anything is not written as a percentage.
                        return c.insufficient || c.customers <= 0 ? count(month.customers, locale) : `${count(month.customers, locale)} (${percent((month.customers * 100) / c.customers, locale)})`
                      },
                    })),
                  ]} />
              )}
              <Note>{say('customers.retentionNote', { n: d.retention.minimum_cohort }, locale)}</Note>
            </Section>
            <Section title={say('customers.notReported', undefined, locale)} testId="an-customers-not-reported">
              <dl className="adm-details">{Object.entries(d.not_reported ?? {}).map(([k, v]) => <div key={k}><dt>{k.replace(/_/g, ' ')}</dt><dd>{v}</dd></div>)}</dl>
            </Section>
          </>
        )
      }}</ReportFrame>

      <Section title={say('funnel.title', undefined, locale)} subtitle={say('funnel.unit', undefined, locale)} testId="an-funnel"
        actions={<Select label={say('funnel.platform', undefined, locale)} value={platform} onChange={(v) => p.set({ platform: v })} options={PLATFORMS.map((v) => ({ value: v, label: say(`funnel.platform.${v}`, undefined, locale) }))} testId="an-funnel-platform" />}>
        <ReportFrame report={funnel} locale={locale}>{(f) => (
          <>
            <p className="adm-an-unit" data-testid="an-funnel-unit" data-unit={f.unit}>{say('funnel.unitLong', undefined, locale)}</p>
            <KpiGrid metrics={f.metrics} definitions={definitions} locale={locale} testId="an-funnel-kpis" />
            <FunnelTable stages={f.stages} locale={locale} />
            <div className="adm-an-grid2">
              <div>
                <h3 className="adm-an-h3">{say('funnel.abandonment', undefined, locale)}</h3>
                {f.abandonment.length === 0 ? <Empty locale={locale} /> : <Table caption={say('funnel.abandonment', undefined, locale)} rows={f.abandonment} keyOf={(r) => r.key} testId="an-funnel-abandonment" rowProps={(r) => ({ 'data-key': r.key })} columns={[
                  { id: 'where', head: say('funnel.col.where', undefined, locale), rowHeader: true, cell: (r) => r.label },
                  { id: 'sessions', head: say('funnel.col.sessions', undefined, locale), end: true, cell: (r) => count(r.sessions, locale) },
                ]} />}
              </div>
              <div>
                <h3 className="adm-an-h3">{say('funnel.platforms', undefined, locale)}</h3>
                {f.platforms.length === 0 ? <Empty locale={locale} /> : <Table caption={say('funnel.platforms', undefined, locale)} rows={f.platforms} keyOf={(r) => r.platform} testId="an-funnel-platforms" rowProps={(r) => ({ 'data-platform': r.platform })} columns={[
                  { id: 'platform', head: say('funnel.col.platform', undefined, locale), rowHeader: true, cell: (r) => code(r.platform, locale, 'adm.an.funnel.platform') },
                  { id: 'sessions', head: say('funnel.col.sessions', undefined, locale), end: true, cell: (r) => count(r.sessions, locale) },
                  { id: 'completed', head: say('funnel.col.completed', undefined, locale), end: true, cell: (r) => count(r.completed, locale) },
                  { id: 'conversion', head: say('funnel.col.overall', undefined, locale), end: true, cell: (r) => cellMetric(r.conversion, locale) },
                ]} />}
              </div>
            </div>
            <Note testId="an-funnel-window">{say('funnel.window', { idle: f.window.session_idle_minutes, max: f.window.session_max_hours, hours: f.window.conversion_window_hours }, locale)} {f.window.cohort}</Note>
            <Note testId="an-funnel-historical">{f.historical_note}</Note>
          </>
        )}</ReportFrame>
      </Section>
    </>
  )
}

/* ------------------------------------------------------------------ Orders */
const ORDER_CARDS = ['orders_placed', 'orders_completed', 'orders_rejected', 'orders_cancelled', 'orders_in_progress', 'orders_refunded', 'orders_refund_required', 'order_completion_rate', 'order_rejection_rate', 'gmv', 'average_order_value', 'median_order_value']
const ORDER_DURATIONS = ['acceptance_time', 'preparation_time', 'pickup_wait_time', 'completion_time']
/** The four durations of an order, each with its average, its median and its 90th percentile. */
function Durations({ metrics, locale }: { metrics: Metric[]; locale: string }) {
  if (metrics.length === 0) return <Empty locale={locale} />
  const part = (m: Metric, v: number | null | undefined) => (m.no_data || typeof v !== 'number' || !Number.isFinite(v) ? '—' : durationOf(m.unit, v, locale))
  return (
    <Table caption={say('orders.durations', undefined, locale)} rows={metrics} keyOf={(m) => m.key} testId="an-orders-durations" rowProps={(m) => ({ 'data-testid': `an-duration-${m.key}`, 'data-metric': m.key })} columns={[
      { id: 'what', head: say('orders.col.duration', undefined, locale), rowHeader: true, cell: (m) => m.label },
      { id: 'average', head: say('orders.col.average', undefined, locale), end: true, cell: (m) => (m.no_data ? say('noData', undefined, locale) : part(m, m.average ?? (typeof m.value === 'number' ? m.value : null))) },
      { id: 'median', head: say('orders.col.median', undefined, locale), end: true, cell: (m) => part(m, m.median) },
      { id: 'p90', head: say('orders.col.p90', undefined, locale), end: true, cell: (m) => part(m, m.p90) },
      { id: 'count', head: say('orders.col.measured', undefined, locale), end: true, cell: (m) => orDash(m.count, locale) },
    ]} />
  )
}
/** Orders by day of the week and hour of the day: a real table — every cell has its number, the shade only repeats it. */
function PeakGrid({ peak, locale }: { peak: OrdersDto['peak_times']; locale: string }) {
  const cells = peak?.cells ?? []
  const top = Math.max(0, ...cells.map((c) => c.orders))
  if (top <= 0) return <Empty locale={locale} testFor="an-peak" />
  const at = new Map(cells.map((c) => [`${c.day_of_week}-${c.hour}`, c.orders]))
  // 1 January 2024 was a Monday: the names of the days in the reader's language, Monday first.
  const days = Array.from({ length: 7 }, (_, i) => new Intl.DateTimeFormat(locale, { weekday: 'short', timeZone: 'UTC' }).format(new Date(Date.UTC(2024, 0, 1 + i))))
  const hours = Array.from({ length: 24 }, (_, h) => h)
  const level = (n: number) => (n <= 0 ? 0 : Math.min(4, Math.ceil((n / top) * 4)))
  return (
    <>
      <div className="db-table-wrap" tabIndex={0}>
        <table className="adm-an-heat" data-testid="an-peak">
          <caption className="db-sr-only">{say('orders.peak', undefined, locale)} — {peak.timezone_note}</caption>
          <thead><tr><th scope="col">{say('orders.col.day', undefined, locale)}</th>{hours.map((h) => <th key={h} scope="col"><span className="db-sr-only">{say('orders.hour', undefined, locale)} </span>{String(h).padStart(2, '0')}</th>)}</tr></thead>
          <tbody>{days.map((name, i) => <tr key={name}><th scope="row">{name}</th>{hours.map((h) => { const n = at.get(`${i + 1}-${h}`) ?? 0; return <td key={h} className={`adm-an-heat--${level(n)}`} data-day={i + 1} data-hour={h} data-orders={n}>{n > 0 ? count(n, locale) : <><span aria-hidden="true">·</span><span className="db-sr-only">0</span></>}</td> })}</tr>)}</tbody>
        </table>
      </div>
      <Note>{say('orders.peakNote', { zone: peak.timezone_note }, locale)}</Note>
    </>
  )
}
export function OrdersView(p: ViewProps) {
  const { locale, definitions } = p
  const report = useViewReport(p, (q) => p.repo.orders(q))
  return (
    <ReportFrame report={report} locale={locale}>{(d) => {
      const all = metricsOf(d.metrics); const e = d.estimated_preparation
      return (
        <>
          <KpiGrid metrics={all} keys={ORDER_CARDS} definitions={definitions} locale={locale} testId="an-orders-kpis" />
          <Section title={say('orders.durations', undefined, locale)} subtitle={say('orders.durationsNote', undefined, locale)}><Durations metrics={ORDER_DURATIONS.map((k) => all.find((m) => m.key === k)).filter((m): m is Metric => !!m)} locale={locale} /></Section>
          <Section title={say('overview.trend', undefined, locale)} testId="an-orders-trend"><OrderTrend trend={d.series} meta={d.meta} locale={locale} testId="an-orders-series" /></Section>
          <div className="adm-an-grid2">
            <Section title={say('orders.status', undefined, locale)}><Breakdown rows={d.status_distribution} caption={say('orders.status', undefined, locale)} head={say('col.status', undefined, locale)} locale={locale} testId="an-orders-status" /></Section>
            <Section title={say('orders.rejections', undefined, locale)}><Breakdown rows={d.rejection_reasons} caption={say('orders.rejections', undefined, locale)} head={say('col.reason', undefined, locale)} locale={locale} testId="an-orders-rejections" /></Section>
          </div>
          <Section title={say('orders.peak', undefined, locale)} testId="an-orders-peak"><PeakGrid peak={d.peak_times} locale={locale} /></Section>
          <Section title={say('orders.estimate', undefined, locale)} testId="an-orders-estimate">
            {!e || e.orders <= 0 ? <Empty locale={locale} /> : (
              <div className="adm-stats">
                <Stat label={say('orders.estimate.orders', undefined, locale)} value={<span data-testid="an-estimate-orders">{count(e.orders, locale)}</span>} />
                <Stat label={say('orders.estimate.estimated', undefined, locale)} value={<span data-testid="an-estimate-estimated">{duration(e.estimated_seconds_average, locale)}</span>} />
                <Stat label={say('orders.estimate.actual', undefined, locale)} value={<span data-testid="an-estimate-actual">{duration(e.actual_seconds_average, locale)}</span>} />
                <Stat label={say('orders.estimate.within', undefined, locale)} value={<span data-testid="an-estimate-within">{orDash(e.ready_within_estimate, locale)}</span>} />
              </div>
            )}
            <Note testId="an-estimate-note">{e?.note}</Note>
          </Section>
        </>
      )
    }}</ReportFrame>
  )
}

/* ------------------------------------------------------------------ Restaurants */
type RestaurantColumn = { id: string; head: string; end?: boolean; value: (r: RestaurantRowDto) => number | string | null; cell: (r: RestaurantRowDto) => ReactNode; finance?: boolean }
const metricValue = (m: Metric | null | undefined): number | null => (m && !m.insufficient && !m.no_data && typeof m.value === 'number' && Number.isFinite(m.value) ? m.value : null)
export function RestaurantsView(p: ViewProps) {
  const { locale, definitions } = p
  const report = useViewReport(p, (q) => p.repo.restaurants(q))
  const columns = useMemo<RestaurantColumn[]>(() => {
    const n = (id: string, pick: (r: RestaurantRowDto) => number): RestaurantColumn => ({ id, head: say(`restaurants.col.${id}`, undefined, locale), end: true, value: pick, cell: (r) => count(pick(r), locale) })
    const money = (id: string, pick: (r: RestaurantRowDto) => { amount: number; currency: string } | null, finance = false): RestaurantColumn => ({ id, head: say(`restaurants.col.${id}`, undefined, locale), end: true, finance, value: (r) => pick(r)?.amount ?? null, cell: (r) => (finance && (r.finance_restricted || pick(r) === null) ? <span data-testid="an-no-access">{say('noAccess', undefined, locale)}</span> : moneyText(pick(r), locale)) })
    const metric = (id: string, pick: (r: RestaurantRowDto) => Metric): RestaurantColumn => ({ id, head: say(`restaurants.col.${id}`, undefined, locale), end: true, value: (r) => metricValue(pick(r)), cell: (r) => cellMetric(pick(r), locale) })
    return [
      { id: 'name', head: say('restaurants.col.name', undefined, locale), value: (r) => r.restaurant.name.toLocaleLowerCase(locale), cell: (r) => <span dir="auto">{r.restaurant.name}</span> },
      n('orders_placed', (r) => r.orders_placed), n('orders_completed', (r) => r.orders_completed), n('orders_rejected', (r) => r.orders_rejected), n('orders_cancelled', (r) => r.orders_cancelled), n('orders_in_progress', (r) => r.orders_in_progress),
      money('completed_order_total', (r) => r.completed_order_total), money('average_order_value', (r) => r.average_order_value),
      metric('acceptance_rate', (r) => r.acceptance_rate), metric('rejection_rate', (r) => r.rejection_rate), metric('refund_rate', (r) => r.refund_rate), money('refund_amount', (r) => r.refund_amount),
      metric('acceptance_time', (r) => r.acceptance_time), metric('preparation_time', (r) => r.preparation_time), metric('pickup_wait_time', (r) => r.pickup_wait_time),
      metric('average_rating', (r) => r.average_rating), n('reviews_published', (r) => r.reviews_published), n('impressions', (r) => r.impressions), n('restaurant_views', (r) => r.restaurant_views), n('journey_orders', (r) => r.journey_orders),
      money('gross_sales', (r) => r.gross_sales, true), money('net_earnings', (r) => r.net_earnings, true),
    ]
  }, [locale])
  const sort = columns.find((c) => c.id === p.s.sort) ?? columns[0]
  const descending = p.s.dir === 'desc'
  const onSort = (id: string) => p.set(id === sort.id ? { dir: descending ? 'asc' : 'desc' } : { sort: id, dir: id === 'name' ? 'asc' : 'desc' })
  return (
    <ReportFrame report={report} locale={locale}>{(d) => {
      // Sorted here, by the column the reader chose. A row without a value goes last, whichever way the column runs.
      const rows = [...d.restaurants].sort((a, b) => {
        const x = sort.value(a), y = sort.value(b)
        if (x === null && y === null) return a.restaurant.name.localeCompare(b.restaurant.name, locale)
        if (x === null) return 1
        if (y === null) return -1
        const order = typeof x === 'string' || typeof y === 'string' ? String(x).localeCompare(String(y), locale) : x - y
        return (descending ? -order : order) || a.restaurant.name.localeCompare(b.restaurant.name, locale)
      })
      return (
        <>
          <KpiGrid metrics={d.metrics} definitions={definitions} locale={locale} testId="an-restaurants-kpis" />
          <Section title={say('restaurants.table', undefined, locale)} testId="an-restaurants">
            <p className="adm-an-note" data-testid="an-restaurants-note">{d.comparison_note}</p>
            {d.finance_restricted && <p className="adm-an-restricted" data-testid="an-restricted-restaurant_finance"><Icon name="shield" size={16} /> {say('restaurants.financeRestricted', undefined, locale)}</p>}
            {rows.length === 0 ? <Empty locale={locale} testFor="an-restaurants-table" /> : (
              <div className="db-table-wrap" tabIndex={0}>
                <table className="db-table adm-an-table" data-testid="an-restaurants-table" data-sort={sort.id} data-dir={descending ? 'desc' : 'asc'}>
                  <caption className="db-sr-only">{say('restaurants.table', undefined, locale)}. {d.comparison_note}</caption>
                  <thead><tr>
                    {columns.map((c) => <th key={c.id} scope="col" className={c.end ? 'adm-td--end' : undefined} aria-sort={sort.id === c.id ? (descending ? 'descending' : 'ascending') : 'none'}>
                      <button type="button" className={`adm-sort ${sort.id === c.id ? 'is-on' : ''}`} onClick={() => onSort(c.id)} data-testid={`an-sort-${c.id}`}>{c.head}<Icon name={sort.id === c.id ? (descending ? 'down' : 'up') : 'chevron'} size={12} /></button>
                    </th>)}
                    <th scope="col" className="adm-td--end">{say('restaurants.col.open', undefined, locale)}</th>
                  </tr></thead>
                  <tbody>{rows.map((r) => (
                    <tr key={r.restaurant.id} data-testid={`an-restaurant-row-${r.restaurant.id}`} data-restaurant={r.restaurant.id} data-currency={r.currency} data-finance-restricted={r.finance_restricted ? 'true' : 'false'}>
                      {columns.map((c) => (c.id === 'name' ? <th key={c.id} scope="row" className="adm-an-rowhead">{c.cell(r)} <Badge tone="muted">{r.currency}</Badge></th> : <td key={c.id} className="adm-td--end" data-col={c.id}>{c.cell(r)}</td>))}
                      <td className="adm-td--end"><button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => p.set({ tab: 'orders', restaurant: r.restaurant.id })} data-testid={`an-restaurant-orders-${r.restaurant.id}`}>{say('restaurants.orders', undefined, locale)}<span className="db-sr-only"> — {r.restaurant.name}</span></button></td>
                    </tr>
                  ))}</tbody>
                </table>
              </div>
            )}
          </Section>
        </>
      )
    }}</ReportFrame>
  )
}

/* ------------------------------------------------------------------ Routes */
function Cities({ data, title, locale, testId }: { data: CityRowsDto | undefined; title: string; locale: string; testId: string }) {
  const rows = data?.rows ?? []; const other = data?.other ?? null
  return (
    <div>
      <h3 className="adm-an-h3">{title}</h3>
      {rows.length === 0 && !other ? <Empty locale={locale} testFor={testId} /> : (
        <Table caption={title} testId={testId} keyOf={(r) => r.id} rowProps={(r) => ({ 'data-suppressed': r.suppressed ? 'true' : 'false' })}
          rows={[...rows.map((r) => ({ id: r.city, name: r.city, searches: r.searches, suppressed: r.suppressed === true || r.searches === null })), ...(other ? [{ id: '__other', name: say('routes.otherCities', { n: other.cities }, locale), searches: other.searches, suppressed: other.suppressed || other.searches === null }] : [])]}
          columns={[
            { id: 'city', head: say('routes.col.city', undefined, locale), rowHeader: true, cell: (r) => <span dir="auto">{r.name}</span> },
            { id: 'searches', head: say('routes.col.searches', undefined, locale), end: true, cell: (r) => (r.suppressed ? TOO_FEW(locale) : orDash(r.searches, locale)) },
          ]} />
      )}
    </div>
  )
}
export function RoutesView(p: ViewProps) {
  const { locale, definitions } = p
  const report = useViewReport(p, (q) => p.repo.routes(q))
  return (
    <ReportFrame report={report} locale={locale}>{(d) => {
      const c = d.corridors
      const band = (unit: string, label: string) => say(`routes.unit.${unit === 'km' ? 'km' : 'minutes'}`, { band: label }, locale)
      const distance = d.distance_bands.filter((b) => !b.suppressed && typeof b.searches === 'number')
      return (
        <>
          <KpiGrid metrics={d.metrics} definitions={definitions} locale={locale} testId="an-routes-kpis" />
          <p className="adm-an-privacy" data-testid="an-routes-privacy"><Icon name="shield" size={16} /> {d.privacy.statement}</p>
          <Section title={say('routes.corridors', undefined, locale)} subtitle={c.multi_corridor_note} testId="an-routes-corridors-section">
            <div className="db-table-wrap" tabIndex={0}>
              <table className="db-table adm-an-table" data-testid="an-routes-corridors">
                <caption className="db-sr-only">{say('routes.corridors', undefined, locale)}</caption>
                <thead><tr>
                  <th scope="col">{say('routes.col.corridor', undefined, locale)}</th><th scope="col" className="adm-td--end">{say('routes.col.searches', undefined, locale)}</th><th scope="col" className="adm-td--end">{say('routes.col.noResult', undefined, locale)}</th>
                  <th scope="col" className="adm-td--end">{say('routes.col.noResultRate', undefined, locale)}</th><th scope="col" className="adm-td--end">{say('routes.col.impressions', undefined, locale)}</th><th scope="col" className="adm-td--end">{say('routes.col.views', undefined, locale)}</th>
                  <th scope="col" className="adm-td--end">{say('routes.col.orders', undefined, locale)}</th><th scope="col" className="adm-td--end">{say('routes.col.conversion', undefined, locale)}</th><th scope="col" className="adm-td--end">{say('routes.col.detour', undefined, locale)}</th>
                </tr></thead>
                <tbody>
                  {c.rows.map((r) => (r.suppressed || r.searches === null
                    ? <tr key={r.corridor.id} data-testid={`an-corridor-${r.corridor.id}`} data-suppressed="true"><th scope="row" className="adm-an-rowhead" dir="auto">{r.corridor.name}</th><td colSpan={8} className="adm-an-suppressed">{TOO_FEW(locale)}</td></tr>
                    : <tr key={r.corridor.id} data-testid={`an-corridor-${r.corridor.id}`} data-suppressed="false">
                      <th scope="row" className="adm-an-rowhead"><span dir="auto">{r.corridor.name}</span>{r.corridor.highway && <> <Badge tone="muted">{r.corridor.highway}</Badge></>}</th>
                      <td className="adm-td--end">{orDash(r.searches, locale)}</td><td className="adm-td--end">{orDash(r.no_result_searches, locale)}</td><td className="adm-td--end">{cellMetric(r.no_result_rate, locale)}</td>
                      <td className="adm-td--end">{orDash(r.impressions, locale)}</td><td className="adm-td--end">{orDash(r.restaurant_views, locale)}</td><td className="adm-td--end">{orDash(r.orders, locale)}</td>
                      <td className="adm-td--end">{cellMetric(r.conversion, locale)}</td><td className="adm-td--end">{cellMetric(r.average_detour, locale)}</td>
                    </tr>))}
                  {c.other && (c.other.suppressed || c.other.searches === null
                    ? <tr data-testid="an-corridor-other" data-suppressed="true"><th scope="row" className="adm-an-rowhead">{say('routes.otherCorridors', { n: c.other.corridors }, locale)}</th><td colSpan={8} className="adm-an-suppressed">{TOO_FEW(locale)}</td></tr>
                    : <tr data-testid="an-corridor-other" data-suppressed="false"><th scope="row" className="adm-an-rowhead">{say('routes.otherCorridors', { n: c.other.corridors }, locale)}</th><td className="adm-td--end">{orDash(c.other.searches, locale)}</td><td className="adm-td--end">{orDash(c.other.no_result_searches, locale)}</td><td className="adm-td--end">—</td><td className="adm-td--end">—</td><td className="adm-td--end">—</td><td className="adm-td--end">{orDash(c.other.orders, locale)}</td><td className="adm-td--end">—</td><td className="adm-td--end">—</td></tr>)}
                  <tr data-testid="an-corridor-none"><th scope="row" className="adm-an-rowhead">{say('routes.noCorridor', undefined, locale)}</th><td className="adm-td--end">{orDash(c.without_static_corridor.searches, locale)}</td><td className="adm-td--end">{orDash(c.without_static_corridor.no_result_searches, locale)}</td><td className="adm-td--end">—</td><td className="adm-td--end">—</td><td className="adm-td--end">—</td><td className="adm-td--end">{orDash(c.without_static_corridor.orders, locale)}</td><td className="adm-td--end">—</td><td className="adm-td--end">—</td></tr>
                </tbody>
              </table>
            </div>
            {c.rows.length === 0 && <Note testId="an-routes-corridors-empty">{say('routes.noCorridorRows', undefined, locale)}</Note>}
            <Note>{c.without_static_corridor.note}</Note>
          </Section>
          <div className="adm-an-grid3">
            <Section title={say('routes.distance', undefined, locale)} testId="an-routes-distance-section">
              <Table caption={say('routes.distance', undefined, locale)} rows={d.distance_bands} keyOf={(b) => b.band} testId="an-routes-distance" rowProps={(b) => ({ 'data-band': b.band, 'data-suppressed': b.suppressed ? 'true' : 'false' })} columns={[
                { id: 'band', head: say('routes.col.band', undefined, locale), rowHeader: true, cell: (b) => band(b.unit, b.band) },
                { id: 'searches', head: say('routes.col.searches', undefined, locale), end: true, cell: (b) => (b.suppressed ? TOO_FEW(locale) : orDash(b.searches, locale)) },
              ]} />
              {/* The same counts as bars — the existing single-series chart, on a count axis. */}
              {distance.some((b) => (b.searches ?? 0) > 0) && <BarChart title={say('routes.distanceChart', undefined, locale)} data={distance.map((b) => ({ label: b.band, value: b.searches ?? 0 }))} format={(v) => count(v, locale)} height={150} color="var(--db-blue)" testId="an-routes-distance-chart" />}
            </Section>
            <Section title={say('routes.duration', undefined, locale)} testId="an-routes-duration-section">
              <Table caption={say('routes.duration', undefined, locale)} rows={d.duration_bands} keyOf={(b) => b.band} testId="an-routes-duration" rowProps={(b) => ({ 'data-band': b.band, 'data-suppressed': b.suppressed ? 'true' : 'false' })} columns={[
                { id: 'band', head: say('routes.col.band', undefined, locale), rowHeader: true, cell: (b) => band(b.unit, b.band) },
                { id: 'searches', head: say('routes.col.searches', undefined, locale), end: true, cell: (b) => (b.suppressed ? TOO_FEW(locale) : orDash(b.searches, locale)) },
              ]} />
            </Section>
            <Section title={say('routes.detour', undefined, locale)} testId="an-routes-detour-section">
              <Table caption={say('routes.detour', undefined, locale)} rows={d.detour_bands} keyOf={(b) => b.band} testId="an-routes-detour" rowProps={(b) => ({ 'data-band': b.band, 'data-suppressed': b.suppressed ? 'true' : 'false' })} columns={[
                { id: 'band', head: say('routes.col.band', undefined, locale), rowHeader: true, cell: (b) => band(b.unit, b.band) },
                { id: 'impressions', head: say('routes.col.impressions', undefined, locale), end: true, cell: (b) => (b.suppressed ? TOO_FEW(locale) : orDash(b.impressions, locale)) },
                { id: 'orders', head: say('routes.col.orders', undefined, locale), end: true, cell: (b) => (b.suppressed ? '—' : orDash(b.orders, locale)) },
                { id: 'per100', head: say('routes.col.per100', undefined, locale), end: true, cell: (b) => (b.suppressed || typeof b.orders_per_100_impressions !== 'number' ? '—' : decimal(b.orders_per_100_impressions, locale, 1)) },
              ]} />
            </Section>
          </div>
          <Section title={say('routes.gaps', undefined, locale)} subtitle={d.coverage_gaps.definition} testId="an-routes-gaps">
            <p className="adm-an-insight" data-testid="an-routes-insight"><Icon name="info" size={16} /> {d.coverage_gaps.insight_only}</p>
            <h3 className="adm-an-h3">{say('routes.gapsByReason', undefined, locale)}</h3>
            <Breakdown rows={d.coverage_gaps.by_reason} caption={say('routes.gapsByReason', undefined, locale)} head={say('col.reason', undefined, locale)} locale={locale} testId="an-routes-gaps-reason" />
            <div className="adm-an-grid2">
              <Cities data={d.coverage_gaps.by_origin_city} title={say('routes.gapsOrigin', undefined, locale)} locale={locale} testId="an-routes-gaps-origin" />
              <Cities data={d.coverage_gaps.by_destination_city} title={say('routes.gapsDestination', undefined, locale)} locale={locale} testId="an-routes-gaps-destination" />
            </div>
          </Section>
          <Section title={say('routes.demand', undefined, locale)} subtitle={say('routes.demandNote', undefined, locale)} testId="an-routes-demand">
            <div className="adm-an-grid2">
              <Cities data={d.demand_by_origin_city} title={say('routes.demandOrigin', undefined, locale)} locale={locale} testId="an-routes-demand-origin" />
              <Cities data={d.demand_by_destination_city} title={say('routes.demandDestination', undefined, locale)} locale={locale} testId="an-routes-demand-destination" />
            </div>
          </Section>
          <Note testId="an-routes-historical">{d.historical_note}</Note>
        </>
      )
    }}</ReportFrame>
  )
}

/* ------------------------------------------------------------------ Payments, refunds */
export function PaymentsView(p: ViewProps) {
  const { locale, definitions } = p
  const report = useViewReport(p, (q) => p.repo.payments(q))
  return (
    <ReportFrame report={report} locale={locale}>{(d) => {
      const points = d.series?.points ?? []
      return (
        <>
          <KpiGrid metrics={d.metrics} definitions={definitions} locale={locale} testId="an-payments-kpis" />
          <Section title={say('payments.series', undefined, locale)} testId="an-payments-series-section">
            <SeriesChart title={say('payments.series', undefined, locale)} labels={points.map((x) => bucketLabel(x.bucket))} locale={locale} testId="an-payments-series" axisLabel={say('payments.axis', undefined, locale)}
              caption={chartCaption(d.series.bucket, d.series.timezone, d.meta, locale)} bucketHead={say(`bucketHead.${d.series.bucket}`, undefined, locale)} format={(v) => count(v, locale)}
              series={[{ id: 'attempts', label: say('payments.attempts', undefined, locale), values: points.map((x) => x.attempts) }, { id: 'verified', label: say('payments.verified', undefined, locale), values: points.map((x) => x.verified) }]} />
          </Section>
          <div className="adm-an-grid2">
            <Section title={say('payments.status', undefined, locale)}><Breakdown rows={d.status_distribution} caption={say('payments.status', undefined, locale)} head={say('col.status', undefined, locale)} locale={locale} testId="an-payments-status" /></Section>
            <Section title={say('payments.methods', undefined, locale)}><Breakdown rows={d.methods} caption={say('payments.methods', undefined, locale)} head={say('payments.col.method', undefined, locale)} locale={locale} testId="an-payments-methods"
              extra={[{ id: 'verified', head: say('payments.verified', undefined, locale), cell: (r) => count(r.verified, locale) }, { id: 'rate', head: say('payments.col.successRate', undefined, locale), cell: (r) => cellMetric(r.success_rate, locale) }]} /></Section>
            <Section title={say('payments.failures', undefined, locale)}><Breakdown rows={d.failure_categories} caption={say('payments.failures', undefined, locale)} head={say('col.reason', undefined, locale)} locale={locale} testId="an-payments-failures" /></Section>
            <Section title={say('payments.providers', undefined, locale)}>
              <Breakdown rows={d.providers} caption={say('payments.providers', undefined, locale)} head={say('payments.col.provider', undefined, locale)} locale={locale} testId="an-payments-providers" extra={[{ id: 'preparation', head: say('payments.col.preparation', undefined, locale), cell: (r) => duration(r.preparation_time, locale) }]} />
              <Note testId="an-payments-provider-note">{d.provider_note}</Note>
            </Section>
          </div>
        </>
      )
    }}</ReportFrame>
  )
}
export function RefundsView(p: ViewProps) {
  const { locale, definitions } = p
  const report = useViewReport(p, (q) => p.repo.refunds(q))
  return (
    <ReportFrame report={report} locale={locale}>{(d) => (
      <>
        <KpiGrid metrics={d.metrics} definitions={definitions} locale={locale} testId="an-refunds-kpis" />
        <div className="adm-an-grid3">
          <Section title={say('refunds.status', undefined, locale)}><Breakdown rows={d.status_distribution} caption={say('refunds.status', undefined, locale)} head={say('col.status', undefined, locale)} locale={locale} testId="an-refunds-status" /></Section>
          <Section title={say('refunds.reasons', undefined, locale)}><Breakdown rows={d.reasons} caption={say('refunds.reasons', undefined, locale)} head={say('col.reason', undefined, locale)} locale={locale} testId="an-refunds-reasons" /></Section>
          <Section title={say('refunds.kinds', undefined, locale)}><Breakdown rows={d.kinds} caption={say('refunds.kinds', undefined, locale)} head={say('refunds.col.kind', undefined, locale)} locale={locale} testId="an-refunds-kinds" /></Section>
        </div>
        <Note testId="an-refunds-day-note">{d.by_request_day_note}</Note>
        <Note testId="an-refunds-source">{d.source}</Note>
      </>
    )}</ReportFrame>
  )
}

/* ------------------------------------------------------------------ Finance (needs finance access) */
export function FinanceView(p: ViewProps) {
  const { locale, definitions } = p
  const report = useViewReport(p, (q) => p.repo.finance(q))
  return (
    <ReportFrame report={report} locale={locale} forbidden={say('finance.forbidden', undefined, locale)}>{(d) => {
      const severities = entriesOf(d.reconciliation?.by_severity)
      return (
        <>
          <KpiGrid metrics={d.metrics} definitions={definitions} locale={locale} testId="an-finance-kpis" />
          <Terms terms={d.terms} locale={locale} />
          {(d.series?.by_currency ?? []).map((cur) => (
            <Section key={cur.currency} title={say('finance.series', { currency: cur.currency }, locale)} subtitle={d.series.source} testId={`an-finance-series-${cur.currency}`}>
              <SeriesChart title={say('finance.series', { currency: cur.currency }, locale)} labels={cur.points.map((x) => bucketLabel(x.bucket))} locale={locale} testId={`an-finance-chart-${cur.currency}`} whole={false}
                axisLabel={say('finance.axis', { currency: cur.currency }, locale)} caption={chartCaption(d.series.bucket, d.series.timezone, d.meta, locale)} bucketHead={say(`bucketHead.${d.series.bucket}`, undefined, locale)} format={(v) => formatMoney(v, cur.currency, locale)}
                series={[
                  { id: 'gross_marketplace_sales', label: say('finance.gross', undefined, locale), values: cur.points.map((x) => x.gross_marketplace_sales_minor) },
                  { id: 'restaurant_net_earnings', label: say('finance.net', undefined, locale), values: cur.points.map((x) => x.restaurant_net_earnings_minor) },
                  { id: 'platform_commission', label: say('finance.commission', undefined, locale), values: cur.points.map((x) => x.platform_commission_minor) },
                  { id: 'platform_fee_revenue', label: say('finance.fees', undefined, locale), values: cur.points.map((x) => x.platform_fee_revenue_minor) },
                ]} />
            </Section>
          ))}
          <Section title={say('finance.byRestaurant', undefined, locale)} subtitle={d.commission_note} testId="an-finance-restaurants-section">
            {d.commission_by_restaurant.length === 0 ? <Empty locale={locale} /> : <Table caption={say('finance.byRestaurant', undefined, locale)} rows={d.commission_by_restaurant} keyOf={(r) => `${r.restaurant.id}-${r.currency}`} testId="an-finance-restaurants" rowProps={(r) => ({ 'data-restaurant': r.restaurant.id, 'data-currency': r.currency })} columns={[
              { id: 'restaurant', head: say('restaurants.col.name', undefined, locale), rowHeader: true, cell: (r) => <><span dir="auto">{r.restaurant.name}</span> <Badge tone="muted">{r.currency}</Badge></> },
              { id: 'orders', head: say('finance.col.orders', undefined, locale), end: true, cell: (r) => count(r.orders, locale) },
              { id: 'gross', head: say('finance.gross', undefined, locale), end: true, cell: (r) => moneyText(r.gross_sales, locale) },
              { id: 'commission', head: say('finance.commission', undefined, locale), end: true, cell: (r) => moneyText(r.commission, locale) },
              { id: 'net', head: say('finance.net', undefined, locale), end: true, cell: (r) => moneyText(r.net_earnings, locale) },
            ]} />}
          </Section>
          <div className="adm-an-grid2">
            <Section title={say('finance.settlements', undefined, locale)} testId="an-finance-settlements">
              <StatList metrics={metricsOf(d.settlements?.metrics)} block="settlements" definitions={definitions} locale={locale} />
              <div className="adm-stats">
                <Stat label={say('finance.settlements.pending', undefined, locale)} value={count(d.settlements.pending, locale)} />
                <Stat label={say('finance.settlements.paid', undefined, locale)} value={count(d.settlements.paid, locale)} />
                <Stat label={say('finance.settlements.review', undefined, locale)} value={count(d.settlements.failed_or_review, locale)} />
                <Stat label={say('finance.settlements.cancelled', undefined, locale)} value={count(d.settlements.cancelled, locale)} />
              </div>
              {d.settlements.amounts.length > 0 && <ul className="adm-money-list" data-testid="an-finance-settlement-amounts">{d.settlements.amounts.map((x) => <li key={x.currency} data-currency={x.currency}><b>{moneyText(x.net_payable, locale)}</b><small>{x.currency} · {say('finance.settlements.net', undefined, locale)}</small></li>)}</ul>}
              <Note testId="an-finance-payouts-note">{d.payouts_note}</Note>
            </Section>
            <Section title={say('finance.reconciliation', undefined, locale)} testId="an-finance-reconciliation">
              <div className="adm-stats">
                <Stat label={say('finance.reconciliation.open', undefined, locale)} value={<span data-testid="an-finance-differences">{count(d.reconciliation.open_differences, locale)}</span>} tone={d.reconciliation.open_differences > 0 ? 'amber' : undefined} />
                {severities.map(([k, v]) => <Stat key={k} label={k.charAt(0) + k.slice(1).toLowerCase()} value={count(v, locale)} />)}
                <Stat label={say('finance.providerRecords', undefined, locale)} value={count(d.provider_fees.records_imported, locale)} />
              </div>
              <Note testId="an-finance-provider-fees">{d.provider_fees.note}</Note>
            </Section>
          </div>
          <Note testId="an-finance-source">{d.source}</Note>
        </>
      )
    }}</ReportFrame>
  )
}

/* ------------------------------------------------------------------ Support (needs support access), reviews, notifications */
export function SupportView(p: ViewProps) {
  const { locale, definitions } = p
  const report = useViewReport(p, (q) => p.repo.support(q))
  return (
    <ReportFrame report={report} locale={locale} forbidden={say('support.forbidden', undefined, locale)}>{(d) => {
      const points = d.series?.points ?? []
      return (
        <>
          <KpiGrid metrics={d.metrics} definitions={definitions} locale={locale} testId="an-support-kpis" />
          <Section title={say('support.series', undefined, locale)} testId="an-support-series-section">
            <SeriesChart title={say('support.series', undefined, locale)} labels={points.map((x) => bucketLabel(x.bucket))} locale={locale} testId="an-support-series" axisLabel={say('support.axis', undefined, locale)}
              caption={chartCaption(d.series.bucket, d.series.timezone, d.meta, locale)} bucketHead={say(`bucketHead.${d.series.bucket}`, undefined, locale)} format={(v) => count(v, locale)}
              series={[{ id: 'created', label: say('support.created', undefined, locale), values: points.map((x) => x.created) }, { id: 'resolved', label: say('support.resolved', undefined, locale), values: points.map((x) => x.resolved) }]} />
          </Section>
          <div className="adm-an-grid2">
            <Section title={say('support.categories', undefined, locale)}><Breakdown rows={d.categories} caption={say('support.categories', undefined, locale)} head={say('support.col.category', undefined, locale)} locale={locale} testId="an-support-categories" /></Section>
            <Section title={say('support.priorities', undefined, locale)}><Breakdown rows={d.priorities} caption={say('support.priorities', undefined, locale)} head={say('support.col.priority', undefined, locale)} locale={locale} testId="an-support-priorities" /></Section>
          </div>
          <Note testId="an-support-targets">{d.targets_note}</Note>
          <Note testId="an-support-no-ranking">{d.no_agent_ranking}</Note>
          <Note>{d.trend_note}</Note>
        </>
      )
    }}</ReportFrame>
  )
}
export function ReviewsView(p: ViewProps) {
  const { locale, definitions } = p
  const report = useViewReport(p, (q) => p.repo.reviews(q))
  return (
    <ReportFrame report={report} locale={locale}>{(d) => {
      const points = d.series?.points ?? []; const total = d.distribution.reduce((sum, r) => sum + r.reviews, 0)
      return (
        <>
          <KpiGrid metrics={d.metrics} definitions={definitions} locale={locale} testId="an-reviews-kpis" />
          <div className="adm-an-grid2">
            <Section title={say('reviews.distribution', undefined, locale)} testId="an-reviews-distribution-section">
              {total <= 0 ? <Empty locale={locale} testFor="an-reviews-distribution" /> : <BarChart title={say('reviews.distribution', undefined, locale)} data={d.distribution.map((r) => ({ label: say('reviews.stars', { n: r.stars }, locale), value: r.reviews }))} format={(v) => count(v, locale)} height={170} color="var(--db-amber)" testId="an-reviews-distribution" />}
            </Section>
            <Section title={say('reviews.series', undefined, locale)} testId="an-reviews-series-section">
              <SeriesChart title={say('reviews.series', undefined, locale)} labels={points.map((x) => bucketLabel(x.bucket))} locale={locale} testId="an-reviews-series" axisLabel={say('reviews.axis', undefined, locale)}
                caption={chartCaption(d.series.bucket, d.series.timezone, d.meta, locale)} bucketHead={say(`bucketHead.${d.series.bucket}`, undefined, locale)} format={(v) => count(v, locale)}
                series={[{ id: 'reviews_published', label: say('reviews.published', undefined, locale), values: points.map((x) => x.reviews_published) }]} />
            </Section>
          </div>
          <Note testId="an-reviews-excluded">{d.excluded}</Note>
          <Note>{d.alert_note}</Note>
        </>
      )
    }}</ReportFrame>
  )
}
export function NotificationsView(p: ViewProps) {
  const { locale, definitions } = p
  const report = useViewReport(p, (q) => p.repo.notifications(q))
  return (
    <ReportFrame report={report} locale={locale}>{(d) => (
      <>
        {/* What a push "sent" means — and that nothing here says a push was opened or read. */}
        <p className="adm-an-privacy" data-testid="an-push-read" data-tracked={d.push_read?.tracked ? 'true' : 'false'}><Icon name="info" size={16} /> {d.push_read?.statement}</p>
        <KpiGrid metrics={d.metrics} definitions={definitions} locale={locale} testId="an-notifications-kpis" />
        <div className="adm-an-grid2">
          <Section title={say('notifications.categories', undefined, locale)}><Breakdown rows={d.categories} caption={say('notifications.categories', undefined, locale)} head={say('support.col.category', undefined, locale)} locale={locale} testId="an-notifications-categories" extra={[{ id: 'read', head: say('notifications.col.inAppRead', undefined, locale), cell: (r) => count(r.in_app_read, locale) }]} /></Section>
          <Section title={say('notifications.channels', undefined, locale)} testId="an-notifications-channels">
            {d.channels.length === 0 ? <Empty locale={locale} /> : d.channels.map((c) => (
              <div key={c.channel} data-channel={c.channel}>
                <h3 className="adm-an-h3">{say('notifications.channel', { name: c.channel.charAt(0) + c.channel.slice(1).toLowerCase().replace(/_/g, ' ') }, locale)}</h3>
                <Breakdown rows={c.statuses} caption={say('notifications.channel', { name: c.channel }, locale)} head={say('col.status', undefined, locale)} locale={locale} />
              </div>
            ))}
          </Section>
          <Section title={say('notifications.skips', undefined, locale)}><Breakdown rows={d.skip_reasons} caption={say('notifications.skips', undefined, locale)} head={say('col.reason', undefined, locale)} locale={locale} testId="an-notifications-skips" /></Section>
          <Section title={say('notifications.errors', undefined, locale)}><Breakdown rows={d.errors} caption={say('notifications.errors', undefined, locale)} head={say('col.reason', undefined, locale)} locale={locale} testId="an-notifications-errors" /></Section>
        </div>
        <Note testId="an-notifications-provider-note">{d.provider_note}</Note>
      </>
    )}</ReportFrame>
  )
}

import { Link } from 'react-router-dom'
import { formatMoney } from '../../../i18n/format'
import { t } from '../../../i18n/strings'
import type {
  AnalyticsMeta, BreakdownDto, CustomersDto, EarningsCurrencyDto, EarningsPeriodDto, EarningsReport, LocationRowDto, MenuReport, MoneyDto, OperationsReport, OrderSeriesDto,
  OrdersReport, OverviewReport, ReviewsReport, RoutesReport,
} from '../../api/apiAnalytics'
import { BarChart, DonutChart, RatingBars } from '../../components/Charts'
import { Card } from '../../components/ui'
import { BASE } from '../../DashboardLayout'
import { bucketWord, fmtCount, fmtDay, fmtDecimal, fmtDuration, fmtMoney, fmtPercent, isNumber, metricText, noData, tickLabels } from './format'
import { BreakdownTable, CountBars, DurationsTable, Empty, MetricCard, MetricGrid, MoneyLine, Note, PeakGrid, type Definitions } from './parts'

/**
 * The seven report tabs of the restaurant's analytics (Module 38). Each one shows ONE answer of the backend as it
 * arrived: the restaurant's own figures, no other restaurant, no ranking, no customer by name, no traveller's route.
 */
type TabProps<T> = { data: T; defs: Definitions; locale: string }

/** "Count of orders · per day · 1 Oct – 7 Oct 2026 · time zone Asia/Kolkata": what a chart's axes are in. */
const axes = (unit: string, meta: AnalyticsMeta, bucket: OrderSeriesDto['bucket'], locale: string) => t('dash.ra.chart.axes', { unit, bucket: bucketWord(bucket, locale), from: fmtDay(meta.period.from, locale), to: fmtDay(meta.period.to, locale), zone: meta.timezone }, locale)

/* ------------------------------------------------------------------ shared cards */

function SeriesCards({ series, meta, locale, withCompleted = false }: { series: OrderSeriesDto; meta: AnalyticsMeta; locale: string; withCompleted?: boolean }) {
  // The points in the backend's order, each under its own key: a key is a label, never a date to be worked out again.
  const ticks = tickLabels(series.points.map((p) => p.bucket), series.bucket)
  const currency = series.money_series_available ? series.currency : null
  const columns: [string, string] = [t(`dash.ra.col.bucket.${series.bucket}`, undefined, locale), t('dash.ra.col.orders', undefined, locale)]
  const placed = t('dash.ra.chart.placed', undefined, locale); const completed = t('dash.ra.chart.completed', undefined, locale); const sales = t('dash.ra.chart.sales', undefined, locale)
  return (
    <div className="db-grid db-grid--half">
      <Card title={placed} subtitle={axes(t('dash.ra.chart.unit.orders', undefined, locale), meta, series.bucket, locale)}>
        <CountBars title={placed} points={series.points.map((p, i) => ({ label: p.bucket, tick: ticks[i], value: p.orders_placed }))} columns={columns} locale={locale} testId="ra-orders-chart" />
      </Card>
      {withCompleted && (
        <Card title={completed} subtitle={axes(t('dash.ra.chart.unit.orders', undefined, locale), meta, series.bucket, locale)}>
          <CountBars title={completed} points={series.points.map((p, i) => ({ label: p.bucket, tick: ticks[i], value: p.orders_completed }))} columns={columns} locale={locale} color="var(--db-green)" testId="ra-completed-chart" />
        </Card>
      )}
      <Card title={sales} subtitle={currency ? axes(t('dash.ra.chart.unit.money', { currency }, locale), meta, series.bucket, locale) : undefined}>
        {currency
          ? <MoneyLine title={`${sales} (${currency})`} points={series.points.map((p, i) => ({ label: p.bucket, tick: ticks[i], value: p.gmv_minor }))} format={(v) => formatMoney(v, currency, locale)} locale={locale} testId="ra-sales-chart" />
          // Several currencies in one line would be a sum that means nothing: the backend sends no amounts then, and none is drawn.
          : <Note testId="ra-sales-chart-unavailable">{t('dash.ra.chart.salesMixed', undefined, locale)}</Note>}
      </Card>
    </div>
  )
}

function StatusCard({ rows, locale }: { rows: BreakdownDto[]; locale: string }) {
  const title = t('dash.ra.status.title', undefined, locale); const shown = rows.filter((r) => isNumber(r.count) && r.count > 0)
  return (
    <Card title={title} subtitle={t('dash.ra.status.sub', undefined, locale)}>
      {shown.length === 0 ? <Empty locale={locale} testId="ra-status-distribution-empty" /> : <DonutChart title={title} data={shown.map((r) => ({ label: r.label, value: r.count }))} format={(v) => v.toLocaleString(locale)} testId="ra-status-distribution" />}
    </Card>
  )
}

/** Counts of customers — never a list, never a name. Under the minimum group size there is no split at all. */
function CustomersCard({ customers, locale }: { customers: CustomersDto; locale: string }) {
  const figure = (v: number | null, digits?: number) => (digits === undefined ? fmtCount(v, locale) : fmtDecimal(v, locale, digits)) ?? noData(locale)
  const rows: Array<[string, string, string]> = [
    ['ordering', t('dash.ra.customers.ordering', undefined, locale), figure(customers.ordering_customers)], ['first_time', t('dash.ra.customers.firstTime', undefined, locale), figure(customers.first_time_here)],
    ['returning', t('dash.ra.customers.returning', undefined, locale), figure(customers.returning)], ['orders_per_customer', t('dash.ra.customers.perCustomer', undefined, locale), figure(customers.orders_per_customer, 2)],
  ]
  return (
    <Card title={t('dash.ra.customers.title', undefined, locale)} subtitle={t('dash.ra.customers.sub', undefined, locale)}>
      <div data-testid="ra-customers" data-suppressed={String(customers.suppressed)}>
        {customers.suppressed ? <p className="ra-empty" role="status" data-testid="ra-customers-suppressed">{t('dash.ra.customers.suppressed', undefined, locale)}</p> : (
          <dl className="ra-facts">{rows.map(([key, name, value]) => <div key={key} data-testid={`ra-customers-${key}`}><dt>{name}</dt><dd>{value}</dd></div>)}</dl>
        )}
        <p className="db-muted ra-small" data-testid="ra-customers-statement">{customers.statement}</p>
      </div>
    </Card>
  )
}

/** The person's own locations side by side — in the backend's order (by name): a list, not a ranking. */
function LocationsCard({ rows, locale }: { rows: LocationRowDto[]; locale: string }) {
  return (
    <Card title={t('dash.ra.locations.title', undefined, locale)} subtitle={t('dash.ra.locations.sub', undefined, locale)}>
      <div className="db-table-wrap"><table className="db-table" data-testid="ra-locations">
        <caption className="db-sr-only">{t('dash.ra.locations.title', undefined, locale)}</caption>
        <thead><tr><th scope="col">{t('dash.ra.col.location', undefined, locale)}</th><th scope="col">{t('dash.ra.col.currency', undefined, locale)}</th><th scope="col" className="ra-num">{t('dash.ra.col.placed', undefined, locale)}</th><th scope="col" className="ra-num">{t('dash.ra.col.completed', undefined, locale)}</th><th scope="col" className="ra-num">{t('dash.ra.col.sales', undefined, locale)}</th><th scope="col" className="ra-num">{t('dash.ra.col.rating', undefined, locale)}</th></tr></thead>
        <tbody>{rows.map((r) => (
          <tr key={r.restaurant.id} data-testid={`ra-location-row-${r.restaurant.id}`}><th scope="row" className="ra-rowhead" dir="auto">{r.restaurant.name}</th><td>{r.currency}</td><td className="ra-num">{fmtCount(r.orders_placed, locale) ?? '—'}</td><td className="ra-num">{fmtCount(r.orders_completed, locale) ?? '—'}</td><td className="ra-num">{fmtMoney(r.completed_order_total, locale) ?? '—'}</td><td className="ra-num">{r.average_rating ? metricText(r.average_rating, locale).text : '—'}</td></tr>
        ))}</tbody>
      </table></div>
    </Card>
  )
}

/* ------------------------------------------------------------------ overview */

const OVERVIEW_KEYS = ['orders_placed', 'orders_completed', 'completed_order_total', 'average_order_value', 'restaurant_acceptance_rate', 'restaurant_refund_rate', 'average_rating', 'reviews_published', 'restaurant_net_earnings']
export function OverviewTab({ data, defs, locale }: TabProps<OverviewReport>) {
  return <>
    <MetricGrid metrics={data.metrics} keys={OVERVIEW_KEYS} defs={defs} locale={locale} testId="ra-kpis" />
    <Note testId="ra-sales-note">{data.sales_note}</Note>
    <SeriesCards series={data.series} meta={data.meta} locale={locale} />
    <div className="db-grid db-grid--half">
      <StatusCard rows={data.status_distribution} locale={locale} />
      <CustomersCard customers={data.customers} locale={locale} />
    </div>
    {data.locations.length > 0 && <LocationsCard rows={data.locations} locale={locale} />}
  </>
}

/* ------------------------------------------------------------------ orders */

const ORDER_COUNTS = ['orders_placed', 'orders_completed', 'orders_in_progress', 'orders_rejected', 'orders_cancelled', 'orders_refunded', 'orders_refund_required']
const ORDER_RATES = ['order_completion_rate', 'order_rejection_rate', 'completed_order_total', 'average_order_value', 'median_order_value']
const DURATIONS = ['acceptance_time', 'preparation_time', 'pickup_wait_time', 'completion_time']
export function OrdersTab({ data, defs, locale }: TabProps<OrdersReport>) {
  // The order report names the sales of completed orders by its platform key; a restaurant reads it under its own name.
  const metrics = { ...data.metrics, completed_order_total: data.metrics.completed_order_total ?? (data.metrics.gmv ? { ...data.metrics.gmv, key: 'completed_order_total' } : undefined) }
  return <>
    <Card title={t('dash.ra.orders.counts', undefined, locale)}><MetricGrid metrics={metrics} keys={ORDER_COUNTS} defs={defs} locale={locale} testId="ra-order-counts" /></Card>
    <Card title={t('dash.ra.orders.rates', undefined, locale)}><MetricGrid metrics={metrics} keys={ORDER_RATES} defs={defs} locale={locale} testId="ra-order-rates" /></Card>
    <Card title={t('dash.ra.durations.title', undefined, locale)} subtitle={t('dash.ra.durations.sub', undefined, locale)}><DurationsTable metrics={data.metrics} keys={DURATIONS} defs={defs} locale={locale} /></Card>
    <SeriesCards series={data.series} meta={data.meta} locale={locale} withCompleted />
    <div className="db-grid db-grid--half">
      <StatusCard rows={data.status_distribution} locale={locale} />
      <Card title={t('dash.ra.rejections.title', undefined, locale)} subtitle={t('dash.ra.rejections.sub', undefined, locale)}>
        <BreakdownTable rows={data.rejection_reasons} caption={t('dash.ra.rejections.title', undefined, locale)} label={t('dash.ra.col.reason', undefined, locale)} locale={locale} testId="ra-rejection-reasons" />
      </Card>
    </div>
  </>
}

/* ------------------------------------------------------------------ menu */

/**
 * Two sources that must not be read as one: what sessions LOOKED AT (behavioural events) and what was SOLD (the items
 * of completed orders). Each group of columns carries the backend's own note. The figures are information — the page
 * never grades an item and never suggests removing one.
 */
export function MenuTab({ data, defs, locale }: TabProps<MenuReport>) {
  const behaviour = t('dash.ra.menu.behaviour', undefined, locale); const sold = t('dash.ra.menu.orders', undefined, locale)
  return <>
    <Note testId="ra-menu-advice">{data.advice_only}</Note>
    <div className="db-grid db-grid--half">
      <Card title={behaviour} subtitle={data.viewed_source}><MetricGrid metrics={data.metrics} keys={['menu_views', 'item_views', 'cart_adds', 'add_to_cart_rate']} defs={defs} locale={locale} testId="ra-menu-behaviour" /></Card>
      <Card title={sold} subtitle={data.sold_source}><MetricGrid metrics={data.metrics} keys={['units_sold', 'item_sales']} defs={defs} locale={locale} testId="ra-menu-sold" /></Card>
    </div>
    <Card title={t('dash.ra.menu.items', undefined, locale)} subtitle={t('dash.ra.menu.items.sub', undefined, locale)}>
      <dl className="ra-sources" data-testid="ra-menu-sources">
        <div data-source="behaviour"><dt>{behaviour}</dt><dd data-testid="ra-menu-source-viewed">{data.viewed_source}</dd></div>
        <div data-source="orders"><dt>{sold}</dt><dd data-testid="ra-menu-source-sold">{data.sold_source}</dd></div>
      </dl>
      {data.items.length === 0 ? <Empty locale={locale} testId="ra-menu-items-empty" /> : (
        <div className="db-table-wrap"><table className="db-table ra-table--grouped" data-testid="ra-menu-items">
          <caption className="db-sr-only">{t('dash.ra.menu.items', undefined, locale)}</caption>
          <thead>
            <tr><td /><th scope="colgroup" colSpan={2} className="ra-colgroup ra-colgroup--behaviour" data-testid="ra-menu-group-behaviour">{behaviour}</th><th scope="colgroup" colSpan={4} className="ra-colgroup ra-colgroup--orders" data-testid="ra-menu-group-orders">{sold}</th></tr>
            <tr><th scope="col">{t('dash.ra.col.item', undefined, locale)}</th><th scope="col" className="ra-num">{t('dash.ra.col.views', undefined, locale)}</th><th scope="col" className="ra-num">{t('dash.ra.col.cartAdds', undefined, locale)}</th><th scope="col" className="ra-num">{t('dash.ra.col.units', undefined, locale)}</th><th scope="col" className="ra-num">{t('dash.ra.col.orders', undefined, locale)}</th><th scope="col" className="ra-num">{t('dash.ra.col.sales', undefined, locale)}</th><th scope="col" className="ra-num">{t('dash.ra.col.share', undefined, locale)}</th></tr>
          </thead>
          <tbody>{data.items.map((r, i) => (
            <tr key={r.item.id ?? `row-${i}`} data-testid="ra-menu-item-row" data-item={r.item.id ?? ''}><th scope="row" className="ra-rowhead" dir="auto">{r.item.name}</th>
              <td className="ra-num" data-source="behaviour">{fmtCount(r.views, locale) ?? '—'}</td><td className="ra-num" data-source="behaviour">{fmtCount(r.cart_adds, locale) ?? '—'}</td>
              <td className="ra-num" data-source="orders">{fmtCount(r.units_sold, locale) ?? '—'}</td><td className="ra-num" data-source="orders">{fmtCount(r.orders, locale) ?? '—'}</td><td className="ra-num" data-source="orders">{fmtMoney(r.sales, locale) ?? '—'}</td><td className="ra-num" data-source="orders">{fmtPercent(r.share_of_sales_percent, locale) ?? '—'}</td>
            </tr>
          ))}</tbody>
        </table></div>
      )}
    </Card>
  </>
}

/* ------------------------------------------------------------------ operations */

export function OperationsTab({ data, defs, locale }: TabProps<OperationsReport>) {
  const est = data.estimated_preparation; const pickup = data.pickup; const cap = pickup.capacity
  const seconds = (v: number | null) => fmtDuration(v, locale) ?? noData(locale)
  return <>
    <Note testId="ra-operational-note">{data.operational_note}</Note>
    <Card title={t('dash.ra.durations.title', undefined, locale)} subtitle={t('dash.ra.durations.sub', undefined, locale)}><DurationsTable metrics={data.metrics} keys={DURATIONS} defs={defs} locale={locale} /></Card>
    <div className="db-grid db-grid--half">
      <Card title={t('dash.ra.rejections.title', undefined, locale)} subtitle={t('dash.ra.rejections.sub', undefined, locale)}>
        <MetricGrid metrics={data.metrics} keys={['orders_rejected', 'order_rejection_rate', 'orders_cancelled']} defs={defs} locale={locale} testId="ra-rejections" />
        <BreakdownTable rows={data.rejection_reasons} caption={t('dash.ra.rejections.title', undefined, locale)} label={t('dash.ra.col.reason', undefined, locale)} locale={locale} testId="ra-rejection-reasons" />
      </Card>
      <Card title={t('dash.ra.estimate.title', undefined, locale)} subtitle={t('dash.ra.estimate.sub', undefined, locale)}>
        <div data-testid="ra-estimated-prep">
          {!isNumber(est.orders) || est.orders <= 0 ? <Empty locale={locale} testId="ra-estimated-prep-empty" /> : (
            <dl className="ra-facts">
              <div><dt>{t('dash.ra.estimate.estimated', undefined, locale)}</dt><dd data-testid="ra-estimated-prep-estimated">{seconds(est.estimated_seconds_average)}</dd></div>
              <div><dt>{t('dash.ra.estimate.actual', undefined, locale)}</dt><dd data-testid="ra-estimated-prep-actual">{seconds(est.actual_seconds_average)}</dd></div>
              <div><dt>{t('dash.ra.estimate.within', undefined, locale)}</dt><dd data-testid="ra-estimated-prep-within">{isNumber(est.ready_within_estimate) ? t('dash.ra.rate.of', { n: fmtCount(est.ready_within_estimate, locale) ?? '', d: fmtCount(est.orders, locale) ?? '' }, locale) : noData(locale)}</dd></div>
            </dl>
          )}
          <p className="db-muted ra-small" data-testid="ra-estimated-prep-note">{est.note}</p>
        </div>
      </Card>
    </div>
    <Card title={t('dash.ra.peak.title', undefined, locale)} subtitle={t('dash.ra.peak.sub', { zone: data.meta.timezone }, locale)}><PeakGrid peak={data.peak_times} locale={locale} /></Card>
    <Card title={t('dash.ra.pickup.title', undefined, locale)} subtitle={t('dash.ra.pickup.sub', undefined, locale)}>
      <div className="db-grid" data-testid="ra-pickup">
        <MetricGrid metrics={pickup.metrics} keys={['pickup_asap_orders', 'pickup_scheduled_orders', 'pickup_lead_time', 'recommended_slot_acceptance', 'pickup_refusals', 'pickup_slot_full']} defs={defs} locale={locale} testId="ra-pickup-kpis" />
        <div className="db-grid db-grid--3">
          <section aria-label={t('dash.ra.pickup.modes', undefined, locale)}><h3 className="ra-h3">{t('dash.ra.pickup.modes', undefined, locale)}</h3>
            {pickup.modes.length === 0 ? <Empty locale={locale} testId="ra-pickup-modes-empty" /> : (
              <div className="db-table-wrap"><table className="db-table" data-testid="ra-pickup-modes"><caption className="db-sr-only">{t('dash.ra.pickup.modes', undefined, locale)}</caption>
                <thead><tr><th scope="col">{t('dash.ra.col.mode', undefined, locale)}</th><th scope="col" className="ra-num">{t('dash.ra.col.count', undefined, locale)}</th><th scope="col" className="ra-num">{t('dash.ra.col.leadTime', undefined, locale)}</th></tr></thead>
                <tbody>{pickup.modes.map((m) => <tr key={m.key} data-key={m.key}><th scope="row" className="ra-rowhead">{m.label}</th><td className="ra-num">{fmtCount(m.count, locale) ?? '—'}</td><td className="ra-num">{fmtDuration(m.lead_time_average, locale) ?? '—'}</td></tr>)}</tbody>
              </table></div>
            )}
          </section>
          <section aria-label={t('dash.ra.pickup.methods', undefined, locale)}><h3 className="ra-h3">{t('dash.ra.pickup.methods', undefined, locale)}</h3><BreakdownTable rows={pickup.methods} caption={t('dash.ra.pickup.methods', undefined, locale)} label={t('dash.ra.col.method', undefined, locale)} locale={locale} testId="ra-pickup-methods" /></section>
          <section aria-label={t('dash.ra.pickup.refusals', undefined, locale)}><h3 className="ra-h3">{t('dash.ra.pickup.refusals', undefined, locale)}</h3><BreakdownTable rows={pickup.refusal_reasons} caption={t('dash.ra.pickup.refusals', undefined, locale)} label={t('dash.ra.col.reason', undefined, locale)} locale={locale} testId="ra-pickup-refusals" /></section>
        </div>
        {/* Without a capacity per slot there is nothing to be "used": the note stands in the place of a utilization figure. */}
        <section aria-label={t('dash.ra.pickup.capacity', undefined, locale)} data-testid="ra-pickup-capacity" data-configured={String(cap.configured)}>
          <h3 className="ra-h3">{t('dash.ra.pickup.capacity', undefined, locale)}</h3>
          {cap.configured && <>
            <div className="db-grid ra-grid--kpi"><MetricCard metric={pickup.metrics.pickup_capacity_utilization} metricKey="pickup_capacity_utilization" defs={defs} locale={locale} /></div>
            <dl className="ra-facts">
              <div><dt>{t('dash.ra.pickup.slotsWithOrders', undefined, locale)}</dt><dd>{fmtCount(cap.slots_with_orders, locale) ?? '—'}</dd></div>
              <div><dt>{t('dash.ra.pickup.slotsFull', undefined, locale)}</dt><dd>{fmtCount(cap.slots_full, locale) ?? '—'}</dd></div>
              <div><dt>{t('dash.ra.pickup.units', undefined, locale)}</dt><dd>{isNumber(cap.units_used) && isNumber(cap.units_capacity) ? t('dash.ra.rate.of', { n: fmtCount(cap.units_used, locale) ?? '', d: fmtCount(cap.units_capacity, locale) ?? '' }, locale) : '—'}</dd></div>
            </dl>
          </>}
          <p className={cap.configured ? 'db-muted ra-small' : 'ra-empty'} data-testid="ra-pickup-capacity-note">{cap.note}</p>
        </section>
        <p className="db-muted ra-small" data-testid="ra-pickup-delivery">{pickup.delivery}</p>
      </div>
    </Card>
  </>
}

/* ------------------------------------------------------------------ routes */

/** Counts and bands of the restaurant's own orders from journeys. No map and no route: none is stored, none is shown. */
export function RoutesTab({ data, defs, locale }: TabProps<RoutesReport>) {
  const band = (b: { band: string; unit: string }) => (b.unit === 'minutes' ? t('dash.ra.routes.bandMinutes', { band: b.band.replace('-', '–') }, locale) : `${b.band} ${b.unit}`)
  return <>
    <Note testId="ra-routes-privacy">{data.privacy.statement}</Note>
    <MetricGrid metrics={data.metrics} keys={['restaurant_impressions', 'journey_restaurant_views', 'journey_orders', 'average_detour']} defs={defs} locale={locale} testId="ra-routes-kpis" />
    <p className="db-muted ra-small" data-testid="ra-routes-views-note">{data.views_note}</p>
    <Card title={t('dash.ra.routes.bands', undefined, locale)} subtitle={t('dash.ra.routes.bands.sub', undefined, locale)}>
      {data.detour_bands.length === 0 ? <Empty locale={locale} testId="ra-routes-bands-empty" /> : (
        <div className="db-table-wrap"><table className="db-table" data-testid="ra-routes-bands">
          <caption className="db-sr-only">{t('dash.ra.routes.bands', undefined, locale)}</caption>
          <thead><tr><th scope="col">{t('dash.ra.col.detour', undefined, locale)}</th><th scope="col" className="ra-num">{t('dash.ra.col.orders', undefined, locale)}</th><th scope="col" className="ra-num">{t('dash.ra.col.completed', undefined, locale)}</th></tr></thead>
          <tbody>{data.detour_bands.map((b) => (
            <tr key={b.band} data-testid={`ra-routes-band-${b.band}`} data-suppressed={String(b.suppressed)}><th scope="row" className="ra-rowhead">{band(b)}</th>
              {b.suppressed || !isNumber(b.orders) ? <td colSpan={2} className="db-muted">{t(b.suppressed ? 'dash.ra.routes.suppressed' : 'dash.ra.noData', undefined, locale)}</td> : <><td className="ra-num">{fmtCount(b.orders, locale)}</td><td className="ra-num">{fmtCount(b.completed, locale) ?? '—'}</td></>}
            </tr>
          ))}</tbody>
        </table></div>
      )}
    </Card>
  </>
}

/* ------------------------------------------------------------------ reviews */

export function ReviewsTab({ data, defs, locale }: TabProps<ReviewsReport>) {
  const total = data.distribution.reduce((a, d) => a + (isNumber(d.reviews) ? d.reviews : 0), 0)
  const ticks = tickLabels(data.series.points.map((p) => p.bucket), data.series.bucket); const title = t('dash.ra.reviews.series', undefined, locale)
  return <>
    <MetricGrid metrics={data.metrics} keys={['reviews_published', 'average_rating', 'review_response_rate', 'reviews_reported']} defs={defs} locale={locale} testId="ra-reviews-kpis" />
    <div className="db-grid db-grid--half">
      <Card title={t('dash.ra.reviews.distribution', undefined, locale)} subtitle={t('dash.ra.reviews.distribution.sub', undefined, locale)}>
        {total === 0 ? <Empty locale={locale} testId="ra-reviews-distribution-empty" /> : <>
          <RatingBars distribution={data.distribution.map((d) => ({ rating: d.stars, count: d.reviews }))} locale={locale} />
          <div className="db-table-wrap"><table className="db-table" data-testid="ra-reviews-distribution">
            <caption className="db-sr-only">{t('dash.ra.reviews.distribution', undefined, locale)}</caption>
            <thead><tr><th scope="col">{t('dash.ra.col.stars', undefined, locale)}</th><th scope="col" className="ra-num">{t('dash.ra.col.reviews', undefined, locale)}</th></tr></thead>
            <tbody>{data.distribution.map((d) => <tr key={d.stars} data-stars={d.stars}><th scope="row" className="ra-rowhead">{t('dash.ra.reviews.stars', { n: d.stars }, locale)}</th><td className="ra-num">{fmtCount(d.reviews, locale) ?? '—'}</td></tr>)}</tbody>
          </table></div>
        </>}
      </Card>
      <Card title={title} subtitle={axes(t('dash.ra.chart.unit.reviews', undefined, locale), data.meta, data.series.bucket, locale)}>
        <CountBars title={title} points={data.series.points.map((p, i) => ({ label: p.bucket, tick: ticks[i], value: p.reviews_published }))} columns={[t(`dash.ra.col.bucket.${data.series.bucket}`, undefined, locale), t('dash.ra.col.reviews', undefined, locale)]} locale={locale} color="var(--db-purple)" testId="ra-reviews-chart" />
      </Card>
    </div>
    <p className="db-muted ra-small" data-testid="ra-reviews-excluded">{data.excluded}</p>
    <p className="db-muted ra-small" data-testid="ra-reviews-alert-note">{data.alert_note}</p>
  </>
}

/* ------------------------------------------------------------------ earnings */

type EarnKey = 'pending' | 'available' | 'held' | 'in_settlement' | 'paid_out' | 'balance'
const EARN_KEYS: EarnKey[] = ['pending', 'available', 'held', 'in_settlement', 'paid_out', 'balance']
const EARN_PERIODS: Array<['today' | 'this_week' | 'this_month' | 'lifetime', (c: EarningsCurrencyDto) => EarningsPeriodDto]> = [['today', (c) => c.periods.today], ['this_week', (c) => c.periods.this_week], ['this_month', (c) => c.periods.this_month], ['lifetime', (c) => c.lifetime]]
const humanize = (code: string) => { const s = code.toLowerCase().replace(/_/g, ' '); return s.charAt(0).toUpperCase() + s.slice(1) }
const earnLabel = (key: string, locale: string) => { const k = `dash.earn.def.${key}`; const s = t(k, undefined, locale); return s === k ? humanize(key) : s }

/**
 * The finance module's own figures (the same ones as the Earnings page), per currency. Nothing is added up here: every
 * amount is one the backend sent, and the meaning of each is the backend's own sentence.
 */
export function EarningsTab({ data, locale }: { data: EarningsReport; locale: string }) {
  const money = (m: MoneyDto) => fmtMoney(m, locale) ?? '—'
  return (
    <div className="db-grid" data-testid="ra-earnings">
      <Note testId="ra-earnings-source">{data.source} <Link to={`${BASE}/earnings`} className="db-link" data-testid="ra-earnings-link">{t('dash.ra.earnings.open', undefined, locale)}</Link></Note>
      {data.summary.length === 0 && <Card><Empty locale={locale} testId="ra-earnings-empty" /></Card>}
      {/* One block per currency: two currencies are two sets of figures, never one sum. */}
      {data.summary.map((c) => {
        const series = data.series.filter((s) => s.currency === c.currency); const seriesTitle = t('dash.ra.earnings.series', { currency: c.currency }, locale)
        return (
          <section key={c.currency} className="db-grid" data-testid={`ra-earnings-${c.currency}`} aria-label={t('dash.earn.currency', { currency: c.currency }, locale)}>
            <h2 className="db-card__title ra-h2">{t('dash.earn.currency', { currency: c.currency }, locale)}</h2>
            <dl className="ra-earn">{EARN_KEYS.map((key) => (
              <div key={key} data-testid={`ra-earnings-${key}-${c.currency}`} data-amount={c[key].amount} data-currency={c[key].currency}>
                <dt>{earnLabel(key, locale)}</dt><dd className="ra-earn__value">{money(c[key])}</dd>{data.definitions[key] && <dd className="ra-earn__def">{data.definitions[key]}</dd>}
              </div>
            ))}</dl>
            <Card title={t('dash.earn.periods', undefined, locale)} subtitle={t('dash.earn.periods.sub', { zone: data.meta.timezone }, locale)}>
              <div className="db-table-wrap"><table className="db-table" data-testid={`ra-earnings-periods-${c.currency}`}>
                <caption className="db-sr-only">{t('dash.earn.periods', undefined, locale)}</caption>
                <thead><tr><th scope="col">{t('dash.earn.col.period', undefined, locale)}</th><th scope="col" className="ra-num">{t('dash.earn.col.orders', undefined, locale)}</th>{(['gross_sales', 'commission', 'adjustments', 'net_earnings'] as const).map((k) => <th key={k} scope="col" className="ra-num">{earnLabel(k, locale)}</th>)}</tr></thead>
                <tbody>{EARN_PERIODS.map(([id, pick]) => { const p = pick(c); return (
                  <tr key={id} data-period={id}><th scope="row" className="ra-rowhead">{t(`dash.earn.period.${id}`, undefined, locale)}</th><td className="ra-num">{fmtCount(p.orders, locale) ?? '—'}</td><td className="ra-num">{money(p.gross_sales)}</td><td className="ra-num">{money(p.commission)}</td><td className="ra-num">{money(p.adjustments)}</td><td className="ra-num"><b>{money(p.net_earnings)}</b></td></tr>
                ) })}</tbody>
              </table></div>
            </Card>
            <Card title={seriesTitle} subtitle={data.series_note}>
              {series.length === 0 ? <Empty locale={locale} testId={`ra-earnings-series-empty-${c.currency}`} />
                // A chart of bars cannot show a day that ended below zero: such a period is listed as figures instead.
                : series.some((s) => s.net_earnings.amount < 0) ? (
                  <div className="db-table-wrap"><table className="db-table" data-testid={`ra-earnings-series-${c.currency}`}>
                    <caption className="db-sr-only">{seriesTitle}</caption>
                    <thead><tr><th scope="col">{t('dash.earn.col.date', undefined, locale)}</th><th scope="col" className="ra-num">{t('dash.earn.col.orders', undefined, locale)}</th><th scope="col" className="ra-num">{earnLabel('gross_sales', locale)}</th><th scope="col" className="ra-num">{earnLabel('net_earnings', locale)}</th></tr></thead>
                    <tbody>{series.map((s) => <tr key={s.day}><th scope="row" className="ra-rowhead">{fmtDay(s.day, locale)}</th><td className="ra-num">{fmtCount(s.orders, locale) ?? '—'}</td><td className="ra-num">{money(s.gross_sales)}</td><td className="ra-num">{money(s.net_earnings)}</td></tr>)}</tbody>
                  </table></div>
                ) : <BarChart title={seriesTitle} data={series.map((s) => ({ label: s.day.slice(5), value: s.net_earnings.amount }))} format={(v) => formatMoney(Math.round(v), c.currency, locale)} testId={`ra-earnings-series-${c.currency}`} />}
            </Card>
          </section>
        )
      })}
      <Card title={t('dash.earn.definitions', undefined, locale)} subtitle={t('dash.ra.earnings.definitions.sub', undefined, locale)}>
        <dl className="ra-def__list" data-testid="ra-earnings-definitions">{Object.entries(data.definitions).map(([key, text]) => <div key={key}><dt>{earnLabel(key, locale)}</dt><dd>{text}</dd></div>)}</dl>
      </Card>
    </div>
  )
}

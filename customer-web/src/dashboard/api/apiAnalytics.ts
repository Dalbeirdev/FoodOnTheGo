/**
 * A restaurant's own analytics on the backend (Module 38). READ-ONLY reports plus the caller's own CSV exports.
 *
 *   GET  /restaurant/analytics/catalog                 the definition of every figure a restaurant sees
 *   GET  /restaurant/analytics/overview                KPIs, the order / sales series, statuses, customer counts
 *   GET  /restaurant/analytics/orders                  counts, rates, order values, durations, series, rejection reasons
 *   GET  /restaurant/analytics/menu                    what was looked at (behaviour) and what was sold (orders) per item
 *   GET  /restaurant/analytics/operations              durations, rejections, peak times, estimate vs actual, pickup
 *   GET  /restaurant/analytics/routes                  impressions, views and orders from journeys, detour bands
 *   GET  /restaurant/analytics/reviews                 published reviews, rating, response rate, distribution
 *   GET  /restaurant/analytics/earnings                the finance module's own figures (needs restaurant.finance.view)
 *   GET  /restaurant/analytics/exports[/{id}]          the caller's own exports
 *   POST /restaurant/analytics/exports                 asks for one (built by a queued job)
 *   GET  /restaurant/analytics/exports/{id}/download   the CSV file
 *
 * Every report takes ?location=<backend location id> (left out = every location the signed-in person may read) and a
 * period: ?preset=… or preset=custom with from / to (YYYY-MM-DD, both included). The answers are used as they arrive
 * (snake_case; money is {amount: minor units, currency}) — the browser adds nothing up, never combines currencies and
 * never works out a rate of its own.
 */
import { API_BASE_URL, ApiError, api, apiDownload } from '../../api/client'
import { t } from '../../i18n/strings'

const R = { context: 'restaurant' as const }
const ROOT = '/restaurant/analytics'

/* ------------------------------------------------------------------ the filter */

export const ANALYTICS_PRESETS = ['today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'previous_month', 'custom'] as const
export type AnalyticsPreset = (typeof ANALYTICS_PRESETS)[number]
export const DEFAULT_PRESET: AnalyticsPreset = 'last_30_days'
/** The longest period one report may cover (the backend's rule; the catalog repeats it — used here only to answer sooner). */
export const MAX_RANGE_DAYS = 366
export type AnalyticsFilter = { location?: string | null; preset?: AnalyticsPreset; from?: string; to?: string }

const all = (v?: string | null) => (v && v !== 'all' ? v : undefined)
/** What a report is asked: one location or none (= all the caller may read), and a preset or a custom range. */
export function analyticsQuery(f: AnalyticsFilter = {}): Record<string, string | undefined> {
  const preset = f.preset ?? DEFAULT_PRESET
  return preset === 'custom' ? { location: all(f.location), preset, from: f.from, to: f.to } : { location: all(f.location), preset }
}

/* ------------------------------------------------------------------ one figure */

export type MoneyDto = { amount: number; currency: string }
export type ComparisonState = 'UP' | 'DOWN' | 'FLAT' | 'NEW' | 'NO_DATA'
export type ComparisonDto = { previous: number | null; change_pct?: number | null; change_points?: number | null; state: ComparisonState }
export type MetricUnit = 'count' | 'money' | 'percent' | 'seconds' | 'milliseconds' | 'number' | 'rating'
/** One figure, in the one shape every analytics answer uses; which fields are present depends on `unit`. */
export type MetricDto = {
  key: string; label: string; unit: MetricUnit; freshness?: string
  value?: number | MoneyDto | null
  comparison?: ComparisonDto | null
  /** money: one amount per currency (an average may have no amount at all). */
  values?: Array<{ amount: number | null; currency: string; comparison?: ComparisonDto | null; count?: number }>
  mixed_currencies?: boolean
  /** percent / number / rating */
  numerator?: number; denominator?: number; insufficient?: boolean; no_data?: boolean; minimum_denominator?: number
  /** durations */
  average?: number | null; median?: number | null; p90?: number | null; count?: number
  /** a figure the caller may not see */
  restricted?: boolean; requires?: string
}
export type Metrics = Record<string, MetricDto | undefined>

/* ------------------------------------------------------------------ the envelope */

export type ScopeLocationDto = { id: string; name: string; currency: string; timezone: string }
export type FreshnessDto = { mode: 'AGGREGATED' | 'OPERATIONAL' | 'LEDGER'; label: string; aggregated_at: string | null; stale: boolean; generated_at: string; cached: boolean; cache_seconds: number }
export type AnalyticsBucket = 'hour' | 'day' | 'week' | 'month'
export type AnalyticsMeta = {
  period: { preset: string; from: string; to: string; days: number; timezone: string; bucket: AnalyticsBucket; starts_at: string; ends_before: string; previous: { from: string; to: string } }
  scope: { locations: ScopeLocationDto[]; all_readable: boolean }
  timezone: string; currency: string; freshness: FreshnessDto; permissions: { finance: boolean }; definitions: string
}
export type BreakdownDto = { key: string; label: string; count: number }
/**
 * A series in the order the backend gives it. A point's `bucket` is an OPAQUE LABEL ("2026-10-04", "2026-10-04 13:00" and,
 * on the day the clocks go back, an extra "2026-11-01 01:00 (EST)"): it is shown, never parsed as a date. When the
 * selection covers several currencies there is no money line: `money_series_available` is false, `currency` is null
 * and every point's `gmv_minor` is null.
 */
export type OrderSeriesDto = { bucket: AnalyticsBucket; timezone: string; currency: string | null; money_series_available: boolean; points: Array<{ bucket: string; orders_placed: number; orders_completed: number; gmv_minor: number | null }> }
export type PeakTimesDto = { timezone_note: string; week_starts: string; cells: Array<{ day_of_week: number; hour: number; orders: number }> }
export type EstimatedPreparationDto = { orders: number; estimated_seconds_average: number | null; actual_seconds_average: number | null; ready_within_estimate: number | null; note: string }

/* ------------------------------------------------------------------ the reports */

export type CustomersDto = { ordering_customers: number | null; first_time_here: number | null; returning: number | null; orders_per_customer: number | null; suppressed: boolean; minimum_cohort: number; statement: string }
export type LocationRowDto = { restaurant: { id: string; name: string }; currency: string; orders_placed: number; orders_completed: number; completed_order_total: MoneyDto; average_rating?: MetricDto }
export type OverviewReport = { meta: AnalyticsMeta; metrics: Metrics; series: OrderSeriesDto; status_distribution: BreakdownDto[]; customers: CustomersDto; locations: LocationRowDto[]; sales_note: string }
export type OrdersReport = { meta: AnalyticsMeta; metrics: Metrics; series: OrderSeriesDto; status_distribution: BreakdownDto[]; rejection_reasons: BreakdownDto[]; peak_times: PeakTimesDto; estimated_preparation: EstimatedPreparationDto }
export type MenuItemRowDto = { item: { id: string | null; name: string }; views: number; cart_adds: number; units_sold: number; orders: number; sales: MoneyDto; share_of_sales_percent: number | null; cart_adds_per_view: number | null }
export type MenuReport = { meta: AnalyticsMeta; metrics: Metrics; items: MenuItemRowDto[]; sold_source: string; viewed_source: string; advice_only: string }
export type PickupDto = {
  metrics: Metrics; modes: Array<BreakdownDto & { lead_time_average: number | null }>; methods: BreakdownDto[]; refusal_reasons: BreakdownDto[]
  capacity: { configured: boolean; slots_with_orders: number | null; slots_full: number | null; units_used: number | null; units_capacity: number | null; note: string }
  delivery: string
}
export type OperationsReport = { meta: AnalyticsMeta; metrics: Metrics; rejection_reasons: BreakdownDto[]; peak_times: PeakTimesDto; estimated_preparation: EstimatedPreparationDto; pickup: PickupDto; operational_note: string }
export type DetourBandDto = { band: string; unit: string; orders: number | null; completed: number | null; suppressed: boolean }
export type RoutesReport = { meta: AnalyticsMeta; metrics: Metrics; detour_bands: DetourBandDto[]; privacy: { minimum_cohort: number; statement: string }; views_note: string }
export type ReviewsReport = {
  meta: AnalyticsMeta; metrics: Metrics; distribution: Array<{ stars: number; reviews: number }>
  series: { bucket: AnalyticsBucket; timezone: string; points: Array<{ bucket: string; reviews_published: number; average_rating: number | null }> }
  excluded: string; alert_note: string
}
export type EarningsPeriodDto = { orders: number; gross_sales: MoneyDto; restaurant_discount: MoneyDto; commission: MoneyDto; adjustments: MoneyDto; net_earnings: MoneyDto }
export type EarningsCurrencyDto = {
  currency: string; pending: MoneyDto; pending_orders: number; awaiting_terms_orders: number
  available: MoneyDto; held: MoneyDto; in_settlement: MoneyDto; paid_out: MoneyDto; balance: MoneyDto
  lifetime: EarningsPeriodDto; periods: { today: EarningsPeriodDto; this_week: EarningsPeriodDto; this_month: EarningsPeriodDto }
}
export type EarningsReport = { meta: AnalyticsMeta; summary: EarningsCurrencyDto[]; series: Array<EarningsPeriodDto & { day: string; currency: string }>; definitions: Record<string, string>; source: string; series_note?: string }

/** The definition of a figure, in plain business wording (the catalog). */
export type CatalogMetricDto = {
  key: string; name: string; group?: string; unit: string; definition: string; source: string | null; numerator: string | null; denominator: string | null
  filters?: string | null; timezone?: string | null; currency?: string | null; exclusions: string | null; aggregation?: string | null
  freshness?: string | null; freshness_meaning?: string | null; notes?: string | null; limitations?: string | null
}
export type AnalyticsCatalog = { metrics: CatalogMetricDto[]; periods: { presets: string[]; default: string; max_range_days: number }; privacy: { minimum_cohort: number; statement: string } }

/* ------------------------------------------------------------------ exports */

export const EXPORT_REPORTS = ['orders', 'menu_items'] as const
export type ExportStatus = 'QUEUED' | 'PROCESSING' | 'READY' | 'FAILED' | 'EXPIRED'
export type AnalyticsExportDto = {
  id: string; report: string; label: string; status: ExportStatus; failure_code: string | null
  period: { from: string | null; to: string | null; timezone: string | null }; scope: { locations?: ScopeLocationDto[]; all_readable?: boolean }
  format: string; row_count: number | null; size_bytes: number | null; filename: string | null
  /** Only when READY. A path of the API host (it starts with the API root, e.g. /api/v1/…). */
  download_path: string | null
  download_count: number; requested_at: string | null; completed_at: string | null; expires_at: string | null
}
export type ExportListDto = { data: AnalyticsExportDto[]; reports: Array<{ key: string; label: string }>; expiry_hours: number }
export const exportIsPending = (e: Pick<AnalyticsExportDto, 'status'>) => e.status === 'QUEUED' || e.status === 'PROCESSING'

/**
 * The path apiDownload() needs for an export. The backend names the file's place from the root of the host
 * ("/api/v1/restaurant/analytics/exports/{id}/download") while the client's paths start AFTER the API root
 * (VITE_API_BASE_URL already ends with it): the root is taken off once, never doubled.
 */
export function exportDownloadPath(e: Pick<AnalyticsExportDto, 'id' | 'download_path'>): string {
  const fallback = `${ROOT}/exports/${encodeURIComponent(e.id)}/download`
  const given = e.download_path
  if (!given || !given.startsWith('/')) return fallback
  let root = ''
  try { root = new URL(API_BASE_URL, 'http://localhost').pathname.replace(/\/+$/, '') } catch { /* a base that is not a URL: nothing to take off */ }
  if (root && given.startsWith(`${root}/`)) return given.slice(root.length)
  // A path that already starts after the root is used as it is; anything else is not trusted over the id.
  return given.startsWith(`${ROOT}/`) ? given : fallback
}

/* ------------------------------------------------------------------ refusals */

export type AnalyticsProblemKind = 'forbidden' | 'not_found' | 'invalid' | 'conflict' | 'expired' | 'rate_limited' | 'offline' | 'failed'
export type AnalyticsProblem = { kind: AnalyticsProblemKind; status: number; code: string; message: string }
/** A refusal in words a restaurant can act on: the backend's own for 404 / 409 / 410 / 422, a plain one otherwise. */
export function describeAnalyticsError(e: unknown, locale = 'en'): AnalyticsProblem {
  if (!(e instanceof ApiError)) return { kind: 'failed', status: 0, code: 'error', message: t('dash.ra.error.failed', undefined, locale) }
  const base = { status: e.status, code: e.code }
  switch (e.kind) {
    case 'forbidden': return { ...base, kind: 'forbidden', message: t('dash.ra.error.forbidden', undefined, locale) }
    case 'not_found': return { ...base, kind: 'not_found', message: e.message }
    case 'conflict': return { ...base, kind: 'conflict', message: e.message }
    case 'validation': return { ...base, kind: 'invalid', message: Object.values(e.errors)[0]?.[0] ? `${e.message} ${Object.values(e.errors)[0][0]}` : e.message }
    case 'rate_limited': return { ...base, kind: 'rate_limited', message: t('dash.ra.error.rateLimited', undefined, locale) }
    case 'network': return { ...base, kind: 'offline', message: t('dash.ra.error.offline', undefined, locale) }
    default: return e.status === 410 ? { ...base, kind: 'expired', message: e.message } : { ...base, kind: 'failed', message: t('dash.ra.error.failed', undefined, locale) }
  }
}

/* ------------------------------------------------------------------ the repository */

export interface RestaurantAnalyticsReports {
  catalog(): Promise<AnalyticsCatalog>
  overview(f?: AnalyticsFilter): Promise<OverviewReport>
  orders(f?: AnalyticsFilter): Promise<OrdersReport>
  menu(f?: AnalyticsFilter): Promise<MenuReport>
  operations(f?: AnalyticsFilter): Promise<OperationsReport>
  routes(f?: AnalyticsFilter): Promise<RoutesReport>
  reviews(f?: AnalyticsFilter): Promise<ReviewsReport>
  /** 403 without restaurant.finance.view for every location of the answer. */
  earnings(f?: AnalyticsFilter): Promise<EarningsReport>
  /** The caller's own exports, newest first, and the reports that can be asked for. */
  exports(): Promise<ExportListDto>
  /** Asks for an export of the filter's location and period. It is built by a queued job: the answer is QUEUED. */
  requestExport(report: string, f?: AnalyticsFilter): Promise<AnalyticsExportDto>
  exportStatus(id: string): Promise<AnalyticsExportDto>
  /** Downloads a READY export; answers with the file name. */
  downloadExport(e: Pick<AnalyticsExportDto, 'id' | 'download_path' | 'filename' | 'report'>): Promise<string>
}

export class ApiRestaurantAnalyticsRepository implements RestaurantAnalyticsReports {
  private report<T>(name: string, f?: AnalyticsFilter): Promise<T> { return api<T>(`${ROOT}/${name}`, { ...R, query: analyticsQuery(f) }) }

  catalog(): Promise<AnalyticsCatalog> { return api<AnalyticsCatalog>(`${ROOT}/catalog`, R) }
  overview(f?: AnalyticsFilter): Promise<OverviewReport> { return this.report('overview', f) }
  orders(f?: AnalyticsFilter): Promise<OrdersReport> { return this.report('orders', f) }
  menu(f?: AnalyticsFilter): Promise<MenuReport> { return this.report('menu', f) }
  operations(f?: AnalyticsFilter): Promise<OperationsReport> { return this.report('operations', f) }
  routes(f?: AnalyticsFilter): Promise<RoutesReport> { return this.report('routes', f) }
  reviews(f?: AnalyticsFilter): Promise<ReviewsReport> { return this.report('reviews', f) }
  earnings(f?: AnalyticsFilter): Promise<EarningsReport> { return this.report('earnings', f) }

  exports(): Promise<ExportListDto> { return api<ExportListDto>(`${ROOT}/exports`, R) }

  async requestExport(report: string, f: AnalyticsFilter = {}): Promise<AnalyticsExportDto> {
    // Only what the backend accepts: an unknown field is refused, and "all locations" is the absence of one.
    const body: Record<string, string> = { report }
    for (const [key, value] of Object.entries(analyticsQuery(f))) if (value !== undefined && value !== '') body[key] = value
    return (await api<{ export: AnalyticsExportDto }>(`${ROOT}/exports`, { ...R, method: 'POST', body })).export
  }

  async exportStatus(id: string): Promise<AnalyticsExportDto> {
    return (await api<{ export: AnalyticsExportDto }>(`${ROOT}/exports/${encodeURIComponent(id)}`, R)).export
  }

  downloadExport(e: Pick<AnalyticsExportDto, 'id' | 'download_path' | 'filename' | 'report'>): Promise<string> {
    return apiDownload(exportDownloadPath(e), { ...R, filename: e.filename ?? `foodonthego-${e.report.replace(/_/g, '-')}.csv` })
  }
}

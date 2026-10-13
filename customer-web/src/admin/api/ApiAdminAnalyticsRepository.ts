import { api, apiDownload, ApiError } from '../../api/client'

/**
 * Analytics for administrators on the backend (Module 38): /admin/analytics/* — read-only reports, the definitions
 * of every figure, the counts of this moment, data quality, and the curated CSV exports.
 *
 * The answers are passed on AS THEY ARRIVE (snake_case). Nothing is computed here: a rate, a comparison, a total per
 * currency and a "restricted" figure are the backend's. The scope is the backend's too — `market` only chooses among
 * the markets the administrator may see (a slug, or `all`), and a report the caller may not read answers 403.
 *
 * A figure has ONE shape everywhere (`Metric`), told apart by its `unit`. Money is minor units in its own currency;
 * with `mixed_currencies` there is one amount per currency and no sum.
 */
const A = { context: 'admin' as const }
const ROOT = '/admin/analytics'

/* ------------------------------------------------------------------ the envelope */
export const PERIOD_PRESETS = ['today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'previous_month', 'custom'] as const
export type PeriodPreset = (typeof PERIOD_PRESETS)[number]
export const DEFAULT_PRESET: PeriodPreset = 'last_30_days'
export const MAX_RANGE_DAYS = 366

export type FreshnessMode = 'AGGREGATED' | 'OPERATIONAL' | 'LEDGER'
export type Bucket = 'hour' | 'day' | 'week' | 'month'
export type PeriodDto = { preset: string; from: string; to: string; days: number; timezone: string; bucket: Bucket; starts_at: string; ends_before: string; previous: { from: string; to: string } }
export type ScopeMarketDto = { slug: string; name: string; currency: string; timezone: string }
export type FreshnessDto = { mode: FreshnessMode; label: string; aggregated_at: string | null; stale: boolean; generated_at: string; cached: boolean; cache_seconds: number }
export type AnalyticsMeta = {
  period: PeriodDto | null
  scope: { markets: ScopeMarketDto[]; all_markets: boolean; restaurant: { id: string; name: string } | null }
  timezone: string
  currency: string | null
  freshness: FreshnessDto
  permissions: { finance: boolean; support?: boolean }
  definitions: string
}
export type Report<T> = T & { meta: AnalyticsMeta }

/* ------------------------------------------------------------------ one figure */
export type MetricUnit = 'count' | 'money' | 'percent' | 'seconds' | 'milliseconds' | 'number' | 'rating'
export type ComparisonState = 'UP' | 'DOWN' | 'FLAT' | 'NEW' | 'NO_DATA'
export type Comparison = { previous: number | null; change_pct?: number | null; change_points?: number | null; state: ComparisonState }
export type MoneyDto = { amount: number; currency: string }
export type MoneyValue = { amount: number | null; currency: string; comparison?: Comparison | null; count?: number }
export type Metric = {
  key: string; label: string; unit: MetricUnit; freshness?: FreshnessMode
  /** count / percent / duration / number / rating: a number; money: the single amount (null when there are several currencies). */
  value: number | MoneyDto | null
  /** money: one amount per currency — never added together. */
  values?: MoneyValue[]; mixed_currencies?: boolean
  numerator?: number; denominator?: number; insufficient?: boolean; no_data?: boolean; minimum_denominator?: number
  /** durations */
  average?: number | null; median?: number | null; p90?: number | null; count?: number
  comparison?: Comparison | null
  /** A figure the caller may not see: no number at all. */
  restricted?: boolean; requires?: string
}
export type Metrics = Record<string, Metric>
export type CountRow = { key: string; label: string; count: number }
export type RestaurantRef = { id: string; name: string }

/* ------------------------------------------------------------------ the reports */
export type OrderTrendDto = { bucket: Bucket; timezone: string; currency?: string | null; money_series_available?: boolean; points: Array<{ bucket: string; orders_placed: number; orders_completed: number; gmv_minor?: number | null }> }
export type FunnelStageDto = {
  stage: number; key: string; label: string; source?: string; event?: string | null
  sessions: number; customers?: number | null; events?: number | null
  conversion_from_previous: Metric | null; conversion_overall: Metric; did_not_continue?: number | null
}
/** A block of the overview that needs a permission of its own: without it there is `restricted` and no figure. */
export type RestrictedBlock = { restricted: boolean; requires?: string; terms?: Record<string, string> } & Record<string, unknown>
export type OverviewDto = {
  metrics: Metrics
  order_trend: OrderTrendDto
  funnel: { unit: string; stages: FunnelStageDto[]; metrics: Metrics }
  restaurant_operations: Metrics
  route_coverage: Metrics
  finance_summary: RestrictedBlock
  support_health: RestrictedBlock
}
export type LiveDto = { metrics: Metrics; note: string }
export type FunnelDto = {
  unit: string; metrics: Metrics; stages: FunnelStageDto[]
  abandonment: Array<{ key: string; label: string; sessions: number }>
  window: { session_idle_minutes: number; session_max_hours: number; conversion_window_hours: number; cohort: string }
  platforms: Array<{ platform: string; sessions: number; completed: number; conversion: Metric }>
  historical_note: string
}
export type CustomersDto = {
  metrics: Metrics
  segments: Array<{ key: string; label: string; customers: number }>
  series: { bucket: Bucket; timezone: string; points: Array<{ bucket: string; new_accounts: number; first_time_ordering: number }>; note?: string }
  retention: { definition: string; minimum_cohort: number; cohorts: Array<{ cohort: string; customers: number; months: Array<{ month_number: number; customers: number }>; insufficient: boolean }> }
  not_reported: Record<string, string>
}
export type OrdersDto = {
  metrics: Metrics
  series: OrderTrendDto
  status_distribution: CountRow[]
  rejection_reasons: CountRow[]
  peak_times: { timezone_note: string; week_starts: string; cells: Array<{ day_of_week: number; hour: number; orders: number }> }
  estimated_preparation: { orders: number; estimated_seconds_average: number | null; actual_seconds_average: number | null; ready_within_estimate: number | null; note: string }
}
export type RestaurantRowDto = {
  restaurant: RestaurantRef; currency: string
  orders_placed: number; orders_completed: number; orders_rejected: number; orders_cancelled: number; orders_in_progress: number
  completed_order_total: MoneyDto; average_order_value: MoneyDto | null
  acceptance_rate: Metric; rejection_rate: Metric; refund_rate: Metric; refund_amount: MoneyDto
  acceptance_time: Metric; preparation_time: Metric; pickup_wait_time: Metric; average_rating: Metric
  reviews_published: number; impressions: number; restaurant_views: number; journey_orders: number
  gross_sales: MoneyDto | null; net_earnings: MoneyDto | null; finance_restricted: boolean
}
export type RestaurantsDto = { metrics: Metrics; restaurants: RestaurantRowDto[]; comparison_note: string; finance_restricted: boolean }
export type MenuDto = {
  metrics: Metrics
  items: Array<{ item: RestaurantRef; views: number; cart_adds: number; units_sold: number; orders: number; sales: MoneyDto; share_of_sales_percent: number | null; cart_adds_per_view: number | null }>
  sold_source: string; viewed_source: string; advice_only: string
}
export type PickupDto = {
  metrics: Metrics
  modes: Array<CountRow & { lead_time_average: number | null }>
  methods: CountRow[]; refusal_reasons: CountRow[]
  capacity: { configured: boolean; slots_with_orders: number | null; slots_full: number | null; units_used: number | null; units_capacity: number | null; note: string }
  delivery: string
}
export type CorridorRowDto = {
  corridor: { id: string; name: string; highway: string | null }
  searches: number | null; no_result_searches: number | null; impressions: number | null; restaurant_views: number | null; orders: number | null; orders_completed: number | null
  conversion?: Metric; no_result_rate?: Metric; average_detour?: Metric
  suppressed?: boolean
}
export type CityRowsDto = { rows: Array<{ city: string; searches: number | null; suppressed?: boolean }>; other: { cities: number; searches: number | null; suppressed: boolean } | null }
export type RoutesDto = {
  metrics: Metrics
  corridors: {
    rows: CorridorRowDto[]
    other: { corridors: number; searches: number | null; no_result_searches: number | null; orders: number | null; suppressed: boolean } | null
    without_static_corridor: { searches: number; no_result_searches: number; orders: number; note: string }
    multi_corridor_note: string
  }
  distance_bands: Array<{ band: string; unit: string; searches: number | null; suppressed: boolean }>
  duration_bands: Array<{ band: string; unit: string; searches: number | null; suppressed: boolean }>
  detour_bands: Array<{ band: string; unit: string; impressions: number | null; orders: number | null; orders_completed: number | null; orders_per_100_impressions: number | null; suppressed: boolean }>
  coverage_gaps: { definition: string; by_reason: CountRow[]; by_origin_city: CityRowsDto; by_destination_city: CityRowsDto; insight_only: string }
  demand_by_origin_city: CityRowsDto
  demand_by_destination_city?: CityRowsDto
  privacy: { minimum_cohort: number; precise_routes_stored: boolean; origin_destination_stored: boolean; statement: string }
  historical_note: string
}
export type PaymentsDto = {
  metrics: Metrics
  status_distribution: CountRow[]
  methods: Array<CountRow & { verified: number; success_rate: Metric }>
  failure_categories: CountRow[]
  providers: Array<CountRow & { preparation_time: number | null }>
  provider_note: string
  series: { bucket: Bucket; timezone: string; points: Array<{ bucket: string; attempts: number; verified: number }> }
}
export type RefundsDto = { metrics: Metrics; status_distribution: CountRow[]; reasons: CountRow[]; kinds: CountRow[]; by_request_day_note: string; source: string }
export type FinanceDto = {
  metrics: Metrics
  terms: Record<string, string>
  series: { bucket: Bucket; timezone: string; by_currency: Array<{ currency: string; points: Array<{ bucket: string; platform_commission_minor: number; platform_fee_revenue_minor: number; gross_marketplace_sales_minor: number; restaurant_net_earnings_minor: number }> }>; source: string }
  commission_by_restaurant: Array<{ restaurant: RestaurantRef; currency: string; orders: number; gross_sales: MoneyDto; commission: MoneyDto; net_earnings: MoneyDto }>
  settlements: { metrics: Metrics; pending: number; paid: number; failed_or_review: number; cancelled: number; amounts: Array<{ currency: string; net_payable: MoneyDto }>; by_status: CountRow[] }
  provider_fees: { source: string; estimated: boolean; records_imported: number; note: string }
  /** `by_severity` is a map severity → count (an empty one may arrive as an empty list). */
  reconciliation: { open_differences: number; by_severity: Record<string, number> | unknown[] }
  payouts_note: string; commission_note: string; source: string
}
export type SupportDto = {
  metrics: Metrics; categories: CountRow[]; priorities: CountRow[]
  series: { bucket: Bucket; timezone: string; points: Array<{ bucket: string; created: number; resolved: number }> }
  targets_note: string; no_agent_ranking: string; trend_note: string
}
export type ReviewsDto = {
  metrics: Metrics
  distribution: Array<{ stars: number; reviews: number }>
  series: { bucket: Bucket; timezone: string; points: Array<{ bucket: string; reviews_published: number; average_rating: number | null }> }
  excluded: string; alert_note: string
}
export type NotificationsDto = {
  metrics: Metrics
  categories: Array<CountRow & { in_app_read: number }>
  channels: Array<{ channel: string; statuses: CountRow[] }>
  skip_reasons: CountRow[]; errors: CountRow[]
  push_read: { tracked: boolean; statement: string }
  provider_note: string
}

/* ------------------------------------------------------------------ definitions, quality, the system card */
export type MetricDefinition = {
  key: string; name: string; group?: string; unit: MetricUnit; definition: string; source: string | null
  numerator: string | null; denominator: string | null; filters: string | null; timezone: string | null; currency: string | null
  exclusions: string | null; aggregation: string | null; freshness: FreshnessMode; freshness_meaning: string | null
  owner: string | null; approval?: string | null; notes: string | null; limitations?: string | null
}
export type CatalogDto = { metrics: MetricDefinition[]; periods?: { presets: string[]; default: string; max_range_days: number }; privacy?: { minimum_cohort: number; minimum_denominator: number; advertising_trackers: boolean; statement: string }; approval?: string }
export type QualityStatus = 'HEALTHY' | 'WARNING' | 'ERROR' | 'NEVER_CHECKED'
export type QualityDto = {
  status: QualityStatus; checked_at: string | null
  checks: Array<{ check: string; status: string; message: string; observed: Record<string, unknown> | unknown[]; checked_at: string }>
  counts: { healthy: number; warning: number; error: number }
  repair?: string
}
export type AnalyticsSystemDto = {
  checked_at: string
  aggregation: { last_success_at: string | null; stale: boolean; scheduled: boolean; every_minutes: number; runs: Array<{ id: string; trigger: string; dry_run: boolean; status: string; rows_written: number; duration_ms: number | null; error: string | null; started_at: string }> }
  read_models: { events: number; sessions: number; order_facts: number; first_event_at: string | null }
  data_quality: QualityStatus
  /** status → number of exports (an empty one may arrive as an empty list). */
  exports: Record<string, number> | unknown[]
  window_hours: number
  events: Record<string, number>
  policies: { approval: string; min_cohort: number; min_denominator: number; raw_event_days: number; export_expiry_hours: number; session?: Record<string, number> }
  flags: { events_enabled: boolean; cache_enabled: boolean; advertising_trackers: boolean; warehouse: string; materialized_views: string; historical_behavioural_data: string; production: string }
}

/* ------------------------------------------------------------------ exports */
export type ExportStatus = 'QUEUED' | 'PROCESSING' | 'READY' | 'FAILED' | 'EXPIRED'
export type ExportDto = {
  id: string; report: string; label: string; status: ExportStatus; failure_code: string | null
  period: { from: string | null; to: string | null; timezone: string | null }
  scope: Record<string, unknown>; format: string; row_count: number | null; size_bytes: number | null
  filename: string | null; download_path: string | null; download_count: number
  requested_at: string | null; completed_at: string | null; expires_at: string | null
}
export type ExportReportDto = { key: string; label: string; requires: string[] }
export type ExportsDto = { data: ExportDto[]; reports: ExportReportDto[]; expiry_hours: number; max_rows: number; format: string }
export const isExportPending = (e: Pick<ExportDto, 'status'>) => e.status === 'QUEUED' || e.status === 'PROCESSING'

/* ------------------------------------------------------------------ what is asked */
export type AnalyticsQuery = {
  /** A market slug, or `all`. Empty = the backend's default market. */
  market?: string
  preset?: PeriodPreset
  /** YYYY-MM-DD, both included — sent only with the custom period. */
  from?: string; to?: string
  /** A restaurant location (uuid) — only the reports with a restaurant grain take it. */
  restaurant?: string
  /** The funnel only. */
  platform?: 'WEB' | 'ANDROID' | 'all' | ''
}
type Params = Record<string, string>
const text = (v: string | null | undefined) => { const s = (v ?? '').trim(); return s === '' ? undefined : s }
const compact = (o: Record<string, string | undefined>): Params => Object.fromEntries(Object.entries(o).filter(([, v]) => v !== undefined)) as Params

/** The period of a request: a preset by its name, or `custom` with its two days. Dates never travel with a preset. */
export function periodParams(q: Pick<AnalyticsQuery, 'preset' | 'from' | 'to'>): Params {
  const preset = q.preset ?? DEFAULT_PRESET
  return preset === 'custom' ? compact({ preset, from: text(q.from), to: text(q.to) }) : { preset }
}
/** The query of a report. `restaurant` and `platform` are sent only where the endpoint takes them. */
export function reportParams(q: AnalyticsQuery, takes: { restaurant?: boolean; platform?: boolean } = {}): Params {
  const platform = takes.platform && (q.platform === 'WEB' || q.platform === 'ANDROID') ? q.platform : undefined
  return compact({ market: text(q.market), ...periodParams(q), restaurant: takes.restaurant ? text(q.restaurant) : undefined, platform })
}

/**
 * The path `apiDownload()` needs for an export's file. The backend sends `download_path` WITH its `/api/v1` prefix; the
 * client's base URL already ends with it, so the prefix is taken off. Anything that is not the export's own download
 * route (another host, another path) is never followed — the route is rebuilt from the export's id instead.
 */
export function exportDownloadPath(e: Pick<ExportDto, 'id' | 'download_path'>): string {
  const own = `${ROOT}/exports/${encodeURIComponent(e.id)}/download`
  const given = (e.download_path ?? '').replace(/^\/api\/v\d+(?=\/)/, '')
  return given === own ? given : own
}

export class ApiAdminAnalyticsRepository {
  private report<T>(name: string, q: AnalyticsQuery, takes: { restaurant?: boolean; platform?: boolean } = {}): Promise<Report<T>> {
    return api<Report<T>>(`${ROOT}/${name}`, { ...A, query: reportParams(q, takes) })
  }

  overview(q: AnalyticsQuery): Promise<Report<OverviewDto>> { return this.report('overview', q) }
  funnel(q: AnalyticsQuery): Promise<Report<FunnelDto>> { return this.report('funnel', q, { platform: true }) }
  customers(q: AnalyticsQuery): Promise<Report<CustomersDto>> { return this.report('customers', q) }
  orders(q: AnalyticsQuery): Promise<Report<OrdersDto>> { return this.report('orders', q, { restaurant: true }) }
  restaurants(q: AnalyticsQuery): Promise<Report<RestaurantsDto>> { return this.report('restaurants', q, { restaurant: true }) }
  menu(q: AnalyticsQuery): Promise<Report<MenuDto>> { return this.report('menu', q, { restaurant: true }) }
  pickup(q: AnalyticsQuery): Promise<Report<PickupDto>> { return this.report('pickup', q, { restaurant: true }) }
  routes(q: AnalyticsQuery): Promise<Report<RoutesDto>> { return this.report('routes', q) }
  payments(q: AnalyticsQuery): Promise<Report<PaymentsDto>> { return this.report('payments', q) }
  refunds(q: AnalyticsQuery): Promise<Report<RefundsDto>> { return this.report('refunds', q) }
  /** 403 without admin.finance.view for every market of the report. */
  finance(q: AnalyticsQuery): Promise<Report<FinanceDto>> { return this.report('finance', q) }
  /** 403 without admin.support.view for every market of the report. */
  support(q: AnalyticsQuery): Promise<Report<SupportDto>> { return this.report('support', q) }
  reviews(q: AnalyticsQuery): Promise<Report<ReviewsDto>> { return this.report('reviews', q, { restaurant: true }) }
  notifications(q: AnalyticsQuery): Promise<Report<NotificationsDto>> { return this.report('notifications', q) }

  /** Counts of THIS MOMENT (operational records, never cached). It has a market and no period. */
  live(market?: string): Promise<Report<LiveDto>> { return api<Report<LiveDto>>(`${ROOT}/live`, { ...A, query: compact({ market: text(market) }) }) }
  /** The definition of every figure — what a KPI's "definition" shows. */
  catalog(): Promise<CatalogDto> { return api<CatalogDto>(`${ROOT}/catalog`, A) }
  /** What the integrity checks found the last time they ran (403 unless analytics may be read for every market). */
  quality(): Promise<QualityDto> { return api<QualityDto>(`${ROOT}/quality`, A) }
  /** The System page's card (needs admin.system.view). */
  system(): Promise<AnalyticsSystemDto> { return api<AnalyticsSystemDto>('/admin/system/analytics', A) }

  /** The caller's own exports and the reports that can be asked for. */
  exports(): Promise<ExportsDto> { return api<ExportsDto>(`${ROOT}/exports`, A) }
  /** Asks for a report: the answer is QUEUED — a job builds the file. Nothing but the report, the scope and the period is sent. */
  async requestExport(input: { report: string } & Pick<AnalyticsQuery, 'market' | 'restaurant' | 'preset' | 'from' | 'to'>): Promise<ExportDto> {
    const body = compact({ report: input.report, market: text(input.market), restaurant: text(input.restaurant), ...periodParams(input) })
    return (await api<{ export: ExportDto }>(`${ROOT}/exports`, { ...A, method: 'POST', body })).export
  }
  async export(id: string): Promise<ExportDto> { return (await api<{ export: ExportDto }>(`${ROOT}/exports/${encodeURIComponent(id)}`, A)).export }
  /** Downloads a READY export with the administrator's token; returns the name the file was saved under. */
  download(e: Pick<ExportDto, 'id' | 'download_path' | 'filename'>): Promise<string> {
    return apiDownload(exportDownloadPath(e), { ...A, filename: e.filename ?? `analytics-export-${e.id}.csv` })
  }
}

/* ------------------------------------------------------------------ refusals */
export type AnalyticsProblemKind = 'forbidden' | 'not_found' | 'validation' | 'conflict' | 'expired' | 'rate_limited' | 'unavailable'
export type AnalyticsProblem = {
  kind: AnalyticsProblemKind
  /** The backend's stable code (forbidden, market_not_found, range_too_long, export_not_ready, too_many_exports …). */
  code: string
  /** The backend's own words where it has some; a plain sentence otherwise. */
  message: string
  retryAfterSeconds: number | null
}
/** Reads a refusal of the analytics API: which kind it is and what to say. Never throws. */
export function describeAnalyticsError(e: unknown): AnalyticsProblem {
  if (!(e instanceof ApiError)) return { kind: 'unavailable', code: 'unknown', message: 'Analytics could not be read. Please try again.', retryAfterSeconds: null }
  const base = { code: e.code, retryAfterSeconds: e.retryAfterSeconds }
  if (e.status === 403) return { ...base, kind: 'forbidden', message: 'You do not have access to this report.' }
  if (e.status === 404) return { ...base, kind: 'not_found', message: e.message }
  if (e.status === 409) return { ...base, kind: 'conflict', message: e.message }
  if (e.status === 410) return { ...base, kind: 'expired', message: e.message }
  // A period that is too long says so in its own message; any other 422 names the field that is wrong.
  if (e.status === 422) return { ...base, kind: 'validation', message: e.code === 'range_too_long' ? e.message : Object.values(e.errors)[0]?.[0] ?? e.message }
  if (e.status === 429) return { ...base, kind: 'rate_limited', message: e.message }
  return { ...base, kind: 'unavailable', message: 'Analytics could not be read. Please try again.' }
}

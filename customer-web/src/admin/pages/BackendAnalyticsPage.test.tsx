/**
 * Platform Analytics on the backend (Module 38), on a scripted network that answers with the REAL sample answers of the
 * backend's own tests (analytics/samples.fixture.ts). What is checked: how a figure is written out (money, a rate, a
 * duration, "restricted", "insufficient", "no data", a comparison), what the filters send, the funnel in sessions,
 * a refusal of finance / support, suppressed route rows, the export flow with its polling and its download, the
 * definitions — and that the aggregated figures are never called by the word for "this very moment".
 */
import { act, cleanup, configure, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { tokens } from '../../api/client'
import { setMockDashboardLatency } from '../../dashboard/mock/mockDashboard'
import { LocaleProvider } from '../../i18n/LocaleProvider'
import { AdminProvider } from '../AdminContext'
import AdminLayout from '../AdminLayout'
import { analyticsStrings } from '../analyticsStrings'
import { adminRepositories, K, resetAdminStores, setMockAdminLatency } from '../mock/mockAdmin'
import { QualityPanel } from './analytics/blocks'
import { comparisonOf, customPeriodProblem, duration, milliseconds, percent, showMetric } from './analytics/format'
import { sample, type SampleName } from './analytics/samples.fixture'
import { axisTicks } from './analytics/SeriesChart'
import { AnalyticsMetricsCard } from './AnalyticsMetricsCard'
import { BackendAnalyticsPage } from './BackendAnalyticsPage'
import { AnalyticsPage } from './InsightPages'
import OverviewPage from './OverviewPage'

configure({ asyncUtilTimeout: 5000 })
const BASE = 'http://127.0.0.1:8001/api/v1'
const ID = '7e000000-0000-4000-8000-000000000001'
const REPORTS = [{ key: 'orders', label: 'Orders', requires: ['admin.analytics.export'] }, { key: 'financial_summary', label: 'Financial summary', requires: ['admin.analytics.export', 'admin.finance.view', 'admin.finance.export'] }]
const anExport = (patch: Record<string, unknown> = {}) => ({
  id: ID, report: 'orders', label: 'Orders', status: 'QUEUED', failure_code: null, period: { from: '2026-10-01', to: '2026-10-07', timezone: 'Asia/Kolkata' }, scope: {}, format: 'CSV', row_count: null, size_bytes: null,
  filename: null, download_path: null, download_count: 0, requested_at: '2026-10-07T06:30:00Z', completed_at: null, expires_at: null, ...patch,
})
const ready = (patch: Record<string, unknown> = {}) => anExport({ status: 'READY', row_count: 4, size_bytes: 512, filename: 'orders-india.csv', download_path: `/api/v1/admin/analytics/exports/${ID}/download`, completed_at: '2026-10-07T06:31:00Z', expires_at: '2026-10-08T06:31:00Z', ...patch })
const QUALITY = { status: 'WARNING', checked_at: '2026-10-07T06:00:00Z', checks: [{ check: 'order_coverage', status: 'HEALTHY', message: 'Every order has a fact row.', observed: {}, checked_at: '2026-10-07T06:00:00Z' }, { check: 'unknown_market_share', status: 'WARNING', message: '7% of events have no market.', observed: { share: 0.07 }, checked_at: '2026-10-07T06:00:00Z' }], counts: { healthy: 1, warning: 1, error: 0 }, repair: 'The checks report.' }
const SYSTEM = {
  checked_at: '2026-10-07T06:35:00Z', aggregation: { last_success_at: '2026-10-07T06:30:00Z', stale: false, scheduled: true, every_minutes: 5, runs: [{ id: 'r1', trigger: 'SCHEDULE', dry_run: false, status: 'SUCCEEDED', rows_written: 42, duration_ms: 850, error: null, started_at: '2026-10-07T06:30:00Z' }] },
  read_models: { events: 1234, sessions: 56, order_facts: 78, first_event_at: '2026-10-01T04:00:00Z' }, data_quality: 'HEALTHY', exports: { READY: 2, EXPIRED: 1 }, window_hours: 24, events: { events_accepted: 10 },
  policies: { approval: 'PENDING BUSINESS APPROVAL', min_cohort: 5, min_denominator: 20, raw_event_days: 180, export_expiry_hours: 24, session: { idle_minutes: 30, max_hours: 12 } },
  flags: { events_enabled: true, cache_enabled: true, advertising_trackers: false, warehouse: 'NOT REQUIRED / NOT IMPLEMENTED', materialized_views: 'NOT USED', historical_behavioural_data: 'UNAVAILABLE BEFORE MODULE 38', production: 'NO' },
}

type Call = { method: string; path: string; body: Record<string, unknown> | null; headers: Record<string, string> }
type Reply = { status?: number; body?: unknown; csv?: string; filename?: string }
let calls: Call[] = []
let routes: Record<string, Reply | ((call: Call) => Reply)> = {}
const refusal = (status: number, code: string, message: string, details: Record<string, unknown> = {}): Reply => ({ status, body: { error: { code, message, details, request_id: 'r1' } } })
const SAMPLED: SampleName[] = ['overview', 'live', 'funnel', 'customers', 'orders', 'restaurants', 'menu', 'pickup', 'routes', 'payments', 'refunds', 'finance', 'support', 'reviews', 'notifications', 'catalog']
/** Every request of one report, oldest first. */
const asked = (name: string) => calls.filter((c) => c.method === 'GET' && c.path.split('?')[0] === `/admin/analytics/${name}`).map((c) => c.path)
const lastAsked = (name: string) => { const all = asked(name); return all[all.length - 1] }
const reply = (name: string, body: unknown) => { routes[`GET /admin/analytics/${name}`] = { body } }

beforeEach(() => {
  calls = []; routes = {}
  localStorage.clear(); sessionStorage.clear(); resetAdminStores(); setMockAdminLatency(0); setMockDashboardLatency(0); tokens.set('admin', 'admin-token')
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const path = decodeURIComponent(url.replace(BASE, '')); const method = init.method ?? 'GET'
    const call: Call = { method, path, body: init.body ? JSON.parse(init.body as string) : null, headers: (init.headers ?? {}) as Record<string, string> }
    calls.push(call)
    const key = `${method} ${path.split('?')[0]}`
    const scripted = routes[key]
    const name = key.replace('GET /admin/analytics/', '') as SampleName
    const r = (typeof scripted === 'function' ? scripted(call) : scripted)
      ?? (key.startsWith('GET /admin/analytics/') && SAMPLED.includes(name) ? { body: sample(name) } : undefined)
      ?? (key === 'GET /admin/analytics/quality' ? { body: QUALITY } : undefined)
    if (!r) return new Response(JSON.stringify({ error: { code: 'not_found', message: `No stub for ${method} ${path}`, details: {} } }), { status: 404 })
    if (r.csv !== undefined) return new Response(r.csv, { status: 200, headers: { 'Content-Type': 'text/csv; charset=UTF-8', ...(r.filename ? { 'Content-Disposition': `attachment; filename="${r.filename}"` } : {}) } })
    return new Response(JSON.stringify(r.body ?? {}), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } })
  }))
  vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:analytics-admin-test'), revokeObjectURL: vi.fn() }))
})
afterEach(() => { cleanup(); vi.unstubAllGlobals(); tokens.set('admin', null) })

const mount = (path = '/admin/analytics', props: { liveMs?: number; pollMs?: number } = {}) => render(
  <LocaleProvider>
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/admin" element={<AdminProvider repos={adminRepositories}><AdminLayout /></AdminProvider>}>
          <Route path="analytics" element={<BackendAnalyticsPage {...props} />} />
        </Route>
      </Routes>
    </MemoryRouter>
  </LocaleProvider>,
)
const page = () => screen.getByTestId('adm-analytics')
const value = (key: string) => screen.getByTestId(`an-kpi-${key}-value`)
/** Nothing that is not a figure may ever be printed as one. */
const expectClean = (el: HTMLElement) => { expect(el.textContent).not.toMatch(/NaN|Infinity|∞|undefined|null|adm\.an\./) }

describe('BackendAnalyticsPage (Module 38) — the overview', () => {
  it('shows the headline figures as the backend sent them, what they cover and how fresh they are', async () => {
    mount()
    expect(await screen.findByTestId('an-kpi-orders_completed')).toHaveAttribute('data-state', 'value')
    expect(page()).toHaveAttribute('data-source', 'backend'); expect(page()).toHaveAttribute('data-tab', 'overview')
    // Default: India, the last 30 days — both said in the request.
    expect(asked('overview')).toEqual(['/admin/analytics/overview?market=india&preset=last_30_days'])
    expect(screen.getByTestId('an-market')).toHaveValue('india'); expect(screen.getByTestId('an-period')).toHaveValue('last_30_days')
    expect([...screen.getByTestId('an-period').querySelectorAll('option')].map((o) => o.textContent)).toEqual(['Today', 'Yesterday', 'Last 7 days', 'Last 30 days', 'This month', 'Previous month', 'Custom'])
    expect(screen.getByTestId('an-market').querySelector('option')).toHaveTextContent('All markets')

    // A count is a count; money is minor units in its own currency; a rate has one decimal and says what it is made of.
    expect(value('orders_completed')).toHaveTextContent(/^3$/); expect(value('ordering_customers')).toHaveTextContent(/^3$/)
    expect(value('gmv')).toHaveTextContent('₹1,545.00'); expect(value('platform_commission')).toHaveTextContent('₹150.00'); expect(screen.getByTestId('an-kpi-gmv-detail')).toHaveTextContent('INR')
    expect(value('order_completion_rate')).toHaveTextContent(/^100\.0%$/); expect(value('refund_rate_orders')).toHaveTextContent(/^25\.0%$/); expect(screen.getByTestId('an-kpi-refund_rate_orders-detail')).toHaveTextContent('1 of 4')
    // A previous value of zero is "New" — never a percentage.
    expect(screen.getByTestId('an-kpi-orders_completed-comparison')).toHaveTextContent('New — no earlier figure to compare')
    expect(screen.queryByTestId('an-kpi-refund_rate_orders-comparison')).toBeNull()   // no comparison was sent: nothing is shown
    // Eight cards, not forty.
    expect(screen.getByTestId('an-overview-kpis').querySelectorAll('.adm-an-kpi')).toHaveLength(8)

    // What the figures cover, in the backend's own period, time zone, currency and freshness label (the test browser writes dates the US way).
    expect(await screen.findByTestId('an-period-range')).toHaveTextContent('Oct 1, 2026 – Oct 7, 2026'); expect(screen.getByTestId('an-period-range')).toHaveTextContent('Asia/Kolkata')
    expect(screen.getByTestId('an-currency')).toHaveTextContent('Currency INR')
    expect(screen.getByTestId('an-freshness')).toHaveTextContent(/^Updated about every 5 minutes · figures as of Oct 7, 2026, 12:00 PM$/)
    expect(screen.getByTestId('an-meta')).toHaveAttribute('data-stale', 'false'); expect(screen.queryByTestId('an-stale')).toBeNull()

    // The blocks: compact figures, each with its own state.
    expect(screen.getByTestId('an-stat-restaurant_operations-acceptance_time-value')).toHaveTextContent(/^0 s$/)
    expect(screen.getByTestId('an-stat-route_coverage-no_result_rate-value')).toHaveTextContent('0.0%')
    expect(screen.getByTestId('an-stat-finance_summary-gross_marketplace_sales-value')).toHaveTextContent('₹1,500.00'); expect(screen.getByTestId('an-terms')).toHaveTextContent(/NOT FoodOnTheGo revenue/)
    expect(screen.getByTestId('an-stat-support_health-support_first_response_time-value')).toHaveTextContent('No data for this period.')
    expect(screen.getByTestId('an-stat-funnel-funnel_overall_conversion-value')).toHaveTextContent('100.0%'); expect(screen.getByTestId('an-overview-funnel')).toHaveTextContent('Unit: sessions')
    expect(within(screen.getByTestId('an-overview-funnel-table')).getAllByRole('row')).toHaveLength(12)

    // The order trend: a count axis, the bucket, the time zone and the date range — and the same numbers as a table.
    const trend = screen.getByTestId('an-trend')
    expect(trend.querySelector('svg')).toHaveAccessibleName(/Orders placed and completed/); expect(screen.getByTestId('an-trend-caption')).toHaveTextContent('By day · Time zone Asia/Kolkata · Oct 1, 2026 – Oct 7, 2026')
    expect(trend).toHaveTextContent('Orders (count)'); expect(within(screen.getByTestId('an-trend-table')).getAllByRole('row')).toHaveLength(8)
    expect(within(screen.getByTestId('an-trend-table')).getByRole('row', { name: /2026-10-07/ })).toHaveTextContent('2026-10-0743')   // bucket · placed 4 · completed 3
    // Money has a chart of its own, in its currency — a count is never written as money.
    expect(screen.getByTestId('an-trend-gmv')).toHaveTextContent('GMV (INR)'); expect(screen.getByTestId('an-trend-table')).not.toHaveTextContent('₹')
    expectClean(page())
  }, 30000)

  it('"Right now" is a strip of its own, labelled as counts of this moment, read again while the tab is visible', async () => {
    let hidden = false
    const visibility = vi.spyOn(document, 'visibilityState', 'get').mockImplementation(() => (hidden ? 'hidden' : 'visible'))
    mount('/admin/analytics', { liveMs: 40 })
    const strip = await screen.findByTestId('an-live')
    expect(strip).toHaveAttribute('data-mode', 'OPERATIONAL'); expect(strip).toHaveTextContent('Right now'); expect(strip).toHaveTextContent(/Counts of this moment, read from the operational records/)
    expect(screen.getByTestId('an-live-live_active_orders')).toHaveTextContent(/^1$/); expect(screen.getByTestId('an-live-orderable_restaurants')).toHaveTextContent(/^1$/)
    expect(asked('live')[0]).toBe('/admin/analytics/live?market=india')   // a market, no period
    await waitFor(() => expect(asked('live').length).toBeGreaterThanOrEqual(3))
    // A hidden tab asks for nothing.
    hidden = true; await act(async () => { await new Promise((r) => setTimeout(r, 60)) })
    const before = asked('live').length; await act(async () => { await new Promise((r) => setTimeout(r, 200)) })
    expect(asked('live').length).toBe(before)
    // Back in view: read at once.
    hidden = false; act(() => { document.dispatchEvent(new Event('visibilitychange')) })
    await waitFor(() => expect(asked('live').length).toBeGreaterThan(before))
    expect(asked('overview')).toHaveLength(1)   // the aggregated report is not re-read by the strip
    visibility.mockRestore()
  }, 30000)

  it('writes "restricted", "insufficient", "no data", several currencies and a change in words — never a made-up number', async () => {
    const o = sample('overview')
    o.metrics.platform_commission = { key: 'platform_commission', label: 'Platform commission', unit: 'money', value: null, restricted: true, requires: 'admin.finance.view' }
    o.metrics.refund_rate_orders = { ...o.metrics.refund_rate_orders, value: null, numerator: 1, denominator: 4, insufficient: true, no_data: false, minimum_denominator: 20, comparison: null }
    o.metrics.order_completion_rate = { ...o.metrics.order_completion_rate, value: null, numerator: 0, denominator: 0, insufficient: false, no_data: true, comparison: { previous: null, change_points: null, state: 'NO_DATA' } }
    o.metrics.gmv = { ...o.metrics.gmv, value: null, mixed_currencies: true, comparison: null, values: [{ amount: 154500, currency: 'INR', comparison: { previous: 100000, change_pct: 54.5, state: 'UP' } }, { amount: 98000, currency: 'JPY', comparison: { previous: 0, change_pct: null, state: 'NEW' } }] }
    o.metrics.orders_completed = { ...o.metrics.orders_completed, value: 12, comparison: { previous: 16, change_pct: -25, state: 'DOWN' } }
    o.metrics.ordering_customers = { ...o.metrics.ordering_customers, comparison: { previous: 3, change_pct: 0, state: 'FLAT' } }
    // A change the backend could not express as a number is not invented here.
    o.metrics.active_restaurants = { ...o.metrics.active_restaurants, comparison: { previous: 2, change_pct: null, state: 'UP' } }
    o.restaurant_operations.order_rejection_rate = { ...o.restaurant_operations.order_rejection_rate, value: 12.5, numerator: 1, denominator: 8, comparison: { previous: 15.5, change_points: -3, state: 'DOWN' } }
    o.finance_summary = { restricted: true, requires: 'admin.finance.view' }; o.support_health = { restricted: true, requires: 'admin.support.view' }
    o.meta.freshness = { ...o.meta.freshness, stale: true }; o.meta.scope.markets.push({ slug: 'japan', name: 'Japan', currency: 'JPY', timezone: 'Asia/Tokyo' }); o.meta.scope.all_markets = true
    o.order_trend = { ...o.order_trend, currency: null, money_series_available: false, points: o.order_trend.points.map((p: Record<string, unknown>) => ({ ...p, gmv_minor: null })) }
    reply('overview', o)
    mount('/admin/analytics?market=all')
    const restricted = await screen.findByTestId('an-kpi-platform_commission')
    expect(restricted).toHaveAttribute('data-state', 'restricted'); expect(value('platform_commission')).toHaveTextContent(/^You do not have access to this figure$/); expect(screen.getByTestId('an-restricted-platform_commission')).toBeInTheDocument()
    expect(value('platform_commission')).not.toHaveTextContent(/[0-9₹]/)   // no number, no zero
    expect(screen.getByTestId('an-kpi-refund_rate_orders')).toHaveAttribute('data-state', 'insufficient'); expect(value('refund_rate_orders')).toHaveTextContent(/^Insufficient data$/); expect(value('refund_rate_orders')).not.toHaveTextContent('%')
    expect(screen.getByTestId('an-kpi-order_completion_rate')).toHaveAttribute('data-state', 'no_data'); expect(value('order_completion_rate')).toHaveTextContent(/^No data for this period\.$/); expect(screen.queryByTestId('an-kpi-order_completion_rate-comparison')).toBeNull()

    // Two currencies: two lines, each in its own currency — and no sum anywhere.
    const gmv = value('gmv'); const lines = [...gmv.querySelectorAll('li')]
    expect(lines.map((l) => l.getAttribute('data-currency'))).toEqual(['INR', 'JPY']); expect(lines[0]).toHaveTextContent('₹1,545.00'); expect(lines[0]).toHaveTextContent('+54.5% vs the previous period')
    expect(lines[1]).toHaveTextContent('¥98,000'); expect(lines[1]).toHaveTextContent('New — no earlier figure to compare'); expect(screen.getByTestId('an-kpi-gmv-detail')).toHaveTextContent('Per currency — never added together')
    expect(page()).not.toHaveTextContent(/252,500|2,525/)
    expect(await screen.findByTestId('an-currency')).toHaveTextContent('Currencies INR, JPY — shown separately, never added together')

    // A change: a signed percentage for a count, percentage points for a rate, nothing when there is no number.
    expect(screen.getByTestId('an-kpi-orders_completed-comparison')).toHaveTextContent('−25.0% vs the previous period'); expect(screen.getByTestId('an-kpi-orders_completed-comparison')).toHaveAttribute('data-state', 'DOWN')
    expect(screen.getByTestId('an-kpi-ordering_customers-comparison')).toHaveTextContent('0.0% vs the previous period')
    expect(screen.queryByTestId('an-kpi-active_restaurants-comparison')).toBeNull()
    expect(screen.getByTestId('an-stat-restaurant_operations-order_rejection_rate-comparison')).toHaveTextContent('−3.0 pp vs the previous period')

    // A block without the permission: words, no figure.
    expect(screen.getByTestId('an-restricted-finance_summary')).toHaveTextContent('You do not have access to this figure'); expect(screen.queryByTestId('an-block-finance_summary')).toBeNull()
    expect(screen.getByTestId('an-restricted-support_health')).toHaveTextContent('You do not have access to this figure')
    // Stale figures say so, visibly.
    expect(await screen.findByTestId('an-stale')).toHaveTextContent(/may be out of date/); expect(screen.getByTestId('an-meta')).toHaveAttribute('data-stale', 'true')
    // Several currencies: no money line is drawn, and the page says why.
    expect(screen.queryByTestId('an-trend-gmv')).toBeNull(); expect(screen.getByTestId('an-trend-gmv-note')).toHaveTextContent('Several currencies — no single money line')
    expect(asked('overview')).toEqual(['/admin/analytics/overview?market=all&preset=last_30_days'])
    expectClean(page())
  }, 30000)

  it('opens the definition of a figure from the catalog, which is read once for the page', async () => {
    const user = userEvent.setup(); mount()
    const button = await screen.findByTestId('an-kpi-gmv-definition')
    expect(button).toHaveAccessibleName('Definition of Gross merchandise value (GMV)'); expect(button).toHaveAttribute('aria-expanded', 'false'); expect(screen.queryByTestId('an-kpi-gmv-definition-panel')).toBeNull()
    await user.click(button)
    const panel = await screen.findByTestId('an-kpi-gmv-definition-panel')
    expect(button).toHaveAttribute('aria-expanded', 'true'); expect(button.getAttribute('aria-controls')).toBe(panel.id)
    expect(panel.querySelector('[data-row="definition"]')).toHaveTextContent(/sum of what customers paid for the orders COMPLETED/); expect(panel.querySelector('[data-row="source"]')).toHaveTextContent('Order domain')
    expect(panel.querySelector('[data-row="exclusions"]')).toHaveTextContent(/Refunds are NOT subtracted/); expect(panel.querySelector('[data-row="freshness"]')).toHaveTextContent('Aggregated — Aggregated read model, rebuilt about every 5 minutes')
    expect(panel.querySelector('[data-row="numerator"]')).toBeNull()   // GMV is not a ratio
    await user.keyboard('{Escape}'); expect(screen.queryByTestId('an-kpi-gmv-definition-panel')).toBeNull(); expect(button).toHaveFocus()
    // A rate names its numerator and its denominator.
    await user.click(screen.getByTestId('an-kpi-order_completion_rate-definition'))
    const rate = screen.getByTestId('an-kpi-order_completion_rate-definition-panel')
    expect(rate.querySelector('[data-row="numerator"]')).toHaveTextContent(/status COMPLETED/); expect(rate.querySelector('[data-row="denominator"]')).toHaveTextContent(/COMPLETED, REJECTED or CANCELLED/)
    // Every card has the affordance, and so has every figure of a block.
    for (const card of screen.getByTestId('an-overview-kpis').querySelectorAll<HTMLElement>('.adm-an-kpi')) expect(within(card).getByRole('button', { name: /^Definition of / })).toBeInTheDocument()
    expect(screen.getByTestId('an-stat-route_coverage-journey_searches-definition')).toBeInTheDocument()
    await user.click(screen.getByTestId('an-tab-orders')); await screen.findByTestId('an-orders-kpis')
    expect(asked('catalog')).toHaveLength(1)
  }, 30000)

  it('says so when the definitions cannot be read — the figures stay', async () => {
    const user = userEvent.setup(); routes['GET /admin/analytics/catalog'] = { status: 500, body: {} }
    mount(); await user.click(await screen.findByTestId('an-kpi-orders_completed-definition'))
    expect(screen.getByTestId('an-kpi-orders_completed-definition-panel')).toHaveTextContent('The definitions could not be loaded.'); expect(value('orders_completed')).toHaveTextContent('3')
  }, 30000)
})

describe('BackendAnalyticsPage (Module 38) — market and period', () => {
  it('the market and the period change the request and stay in the URL', async () => {
    const user = userEvent.setup(); mount()
    await screen.findByTestId('an-kpi-orders_completed')
    await user.selectOptions(screen.getByTestId('an-market'), 'japan'); await waitFor(() => expect(lastAsked('overview')).toBe('/admin/analytics/overview?market=japan&preset=last_30_days'))
    expect(page()).toHaveAttribute('data-market', 'japan'); await waitFor(() => expect(lastAsked('live')).toBe('/admin/analytics/live?market=japan'))
    await user.selectOptions(screen.getByTestId('an-market'), 'all'); await waitFor(() => expect(lastAsked('overview')).toBe('/admin/analytics/overview?market=all&preset=last_30_days'))
    for (const preset of ['today', 'yesterday', 'last_7_days', 'this_month', 'previous_month']) { await user.selectOptions(screen.getByTestId('an-period'), preset); await waitFor(() => expect(lastAsked('overview')).toBe(`/admin/analytics/overview?market=all&preset=${preset}`)) }
    expect(screen.queryByTestId('an-period-from')).toBeNull()
    // The other views are asked with the same market and period.
    await user.click(screen.getByTestId('an-tab-payments')); await screen.findByTestId('an-payments-kpis')
    expect(lastAsked('payments')).toBe('/admin/analytics/payments?market=all&preset=previous_month')
  }, 30000)

  it('reads the market, the period and the view from the URL', async () => {
    mount('/admin/analytics?tab=orders&market=united-states&preset=yesterday')
    await screen.findByTestId('an-orders-kpis')
    expect(asked('orders')).toEqual(['/admin/analytics/orders?market=united-states&preset=yesterday']); expect(asked('overview')).toHaveLength(0)
    expect(screen.getByTestId('an-tab-orders')).toHaveAttribute('aria-selected', 'true'); expect(screen.getByTestId('an-market')).toHaveValue('united-states'); expect(screen.getByTestId('an-period')).toHaveValue('yesterday')
  }, 30000)

  it('a custom period needs both days, in order, at most 366 days — and a refusal of the backend is shown at the dates', async () => {
    const user = userEvent.setup(); mount()
    await screen.findByTestId('an-kpi-orders_completed'); const before = asked('overview').length
    await user.selectOptions(screen.getByTestId('an-period'), 'custom')
    // "Custom" starts from the days on screen.
    const from = await screen.findByTestId('an-period-from'); const to = screen.getByTestId('an-period-to')
    expect(from).toHaveValue('2026-10-01'); expect(to).toHaveValue('2026-10-07')
    await waitFor(() => expect(lastAsked('overview')).toBe('/admin/analytics/overview?market=india&preset=custom&from=2026-10-01&to=2026-10-07'))
    expect(from).toHaveAccessibleName('From'); expect(to).toHaveAccessibleName('To')

    // More than 366 days: said at once, and nothing is asked.
    const asks = asked('overview').length
    fireEvent.change(from, { target: { value: '2025-01-01' } })
    expect(await screen.findByTestId('an-period-error')).toHaveTextContent('A period covers at most 366 days.'); expect(screen.getByTestId('an-period-error')).toHaveAttribute('role', 'alert')
    fireEvent.change(to, { target: { value: '' } }); await waitFor(() => expect(screen.getByTestId('an-period-error')).toHaveTextContent('Choose both days of the period.'))
    fireEvent.change(to, { target: { value: '2024-12-01' } }); await waitFor(() => expect(screen.getByTestId('an-period-error')).toHaveTextContent('from an earlier day to a later one'))
    expect(asked('overview').length).toBe(asks); expect(screen.queryByTestId('an-kpi-orders_completed')).toBeNull()

    // Exactly 366 days is allowed; the backend decides — and its 422 is shown at the dates in its own words.
    routes['GET /admin/analytics/overview'] = refusal(422, 'range_too_long', 'A period covers at most 366 days.', { fields: { from: ['At most 366 days before "to".'] }, max_days: 366 })
    fireEvent.change(to, { target: { value: '2026-01-01' } })
    await waitFor(() => expect(lastAsked('overview')).toBe('/admin/analytics/overview?market=india&preset=custom&from=2025-01-01&to=2026-01-01'))
    await waitFor(() => expect(screen.getByTestId('an-period-error')).toHaveTextContent('A period covers at most 366 days.')); expect(screen.getByTestId('an-report-error')).toHaveAttribute('data-code', 'range_too_long')
    expect(before).toBe(1)
    expect(customPeriodProblem('2025-01-01', '2026-01-01', 366)).toBeNull(); expect(customPeriodProblem('2025-01-01', '2026-01-02', 366)).toBe('tooLong')
  }, 30000)

  it('a market the administrator may not see is a refusal, not an empty report', async () => {
    reply('overview', {}); routes['GET /admin/analytics/overview'] = refusal(403, 'forbidden', 'This action is unauthorized.')
    routes['GET /admin/analytics/live'] = refusal(403, 'forbidden', 'This action is unauthorized.'); routes['GET /admin/analytics/quality'] = refusal(403, 'forbidden', 'This action is unauthorized.')
    mount()
    expect(await screen.findByTestId('an-forbidden')).toHaveTextContent('You do not have access to analytics for this market.')
    expect(screen.queryByTestId('an-overview-kpis')).toBeNull(); expect(screen.queryByTestId('an-live')).toBeNull(); expect(screen.queryByTestId('an-quality')).toBeNull(); expect(screen.queryByTestId('an-meta')).toBeNull()
    expect(screen.getByTestId('an-market')).toBeEnabled()   // another market can still be chosen
  }, 30000)
})

describe('BackendAnalyticsPage (Module 38) — the views', () => {
  it('the funnel is counted in sessions; customers and events are separate columns', async () => {
    const user = userEvent.setup(); reply('funnel', sample('funnel-100'))
    mount('/admin/analytics?tab=customers')
    const first = await screen.findByTestId('an-funnel-stage-1')
    expect(screen.getByTestId('an-funnel-unit')).toHaveAttribute('data-unit', 'SESSION'); expect(screen.getByTestId('an-funnel-unit')).toHaveTextContent(/The unit of this funnel is SESSIONS/)
    const head = within(screen.getByTestId('an-funnel-table')).getAllByRole('columnheader').map((h) => h.textContent)
    expect(head).toEqual(['Stage', 'Sessions', 'From the previous stage (sessions)', 'Overall conversion (sessions)', 'Did not continue (sessions)', 'Signed-in customers', 'Events recorded', 'Counted from'])
    expect(screen.getAllByTestId(/^an-funnel-stage-\d+$/)).toHaveLength(11)
    const f = sample('funnel-100'); const s1 = f.stages[0]; const s2 = f.stages[1]
    expect(first).toHaveAttribute('data-stage', 'journey_search'); expect(first).toHaveTextContent('Journey search')
    expect(screen.getByTestId('an-funnel-sessions-1')).toHaveTextContent(String(s1.sessions)); expect(screen.getByTestId('an-funnel-previous-1')).toHaveTextContent('First stage')
    expect(screen.getByTestId('an-funnel-customers-1')).toHaveTextContent(String(s1.customers)); expect(screen.getByTestId('an-funnel-events-1')).toHaveTextContent(String(s1.events)); expect(screen.getByTestId('an-funnel-dropped-1')).toHaveTextContent(String(s1.did_not_continue))
    expect(screen.getByTestId('an-funnel-previous-2')).toHaveTextContent(`${Number(s2.conversion_from_previous.value).toFixed(1)}%`); expect(screen.getByTestId('an-funnel-overall-2')).toHaveTextContent(`${Number(s2.conversion_overall.value).toFixed(1)}%`)
    // Where sessions stopped, the applications, and what could not be known before Module 38.
    expect(within(screen.getByTestId('an-funnel-abandonment')).getAllByRole('row')).toHaveLength(f.abandonment.length + 1); expect(screen.getByTestId('an-funnel-abandonment')).toHaveTextContent('Searched a journey, opened no restaurant')
    expect([...screen.getByTestId('an-funnel-platforms').querySelectorAll('[data-platform]')].map((r) => r.getAttribute('data-platform'))).toEqual(f.platforms.map((x: { platform: string }) => x.platform))
    expect(screen.getByTestId('an-funnel-platforms')).toHaveTextContent('Android'); expect(screen.getByTestId('an-funnel-historical')).toHaveTextContent(/recorded from Module 38 on/)
    expect(screen.getByTestId('an-funnel-window')).toHaveTextContent('30 minutes')
    // The platform is the funnel's own filter.
    expect(lastAsked('funnel')).toBe('/admin/analytics/funnel?market=india&preset=last_30_days')
    await user.selectOptions(screen.getByTestId('an-funnel-platform'), 'ANDROID'); await waitFor(() => expect(lastAsked('funnel')).toBe('/admin/analytics/funnel?market=india&preset=last_30_days&platform=ANDROID'))
    expect(asked('customers')).toHaveLength(1)

    // The customers report itself: figures, segments, cohorts and what is NOT reported.
    expect(value('repeat_order_rate')).toBeInTheDocument(); expect(screen.getByTestId('an-customers-segments')).toHaveTextContent('First completed order in the period')
    expect(screen.getByTestId('an-cohort-2026-10')).toHaveAttribute('data-insufficient', 'false'); expect(screen.getByTestId('an-customers-not-reported')).toHaveTextContent(/no formula or business purpose was approved/)
    expectClean(page())
  }, 30000)

  it('greys a cohort that is too small and gives it no percentage', async () => {
    const c = sample('customers')
    c.retention.cohorts = [{ cohort: '2026-08', customers: 40, months: [{ month_number: 1, customers: 10 }, { month_number: 2, customers: 4 }], insufficient: false }, { cohort: '2026-09', customers: 2, months: [{ month_number: 1, customers: 1 }], insufficient: true }]
    reply('customers', c); mount('/admin/analytics?tab=customers')
    const big = await screen.findByTestId('an-cohort-2026-08'); const small = screen.getByTestId('an-cohort-2026-09')
    expect(big).toHaveTextContent('10 (25.0%)'); expect(big).toHaveTextContent('4 (10.0%)')
    expect(small).toHaveAttribute('data-insufficient', 'true'); expect(small).toHaveClass('adm-an-row--muted'); expect(small).toHaveTextContent('Insufficient data'); expect(small).not.toHaveTextContent('%')
  }, 30000)

  it('orders: counts, the four durations with average / median / 90th percentile, statuses, the day × hour grid, estimated against actual', async () => {
    const o = sample('orders')
    o.metrics.preparation_time = { ...o.metrics.preparation_time, value: 250, average: 250, median: 200, p90: 3725, count: 3, no_data: false }
    o.metrics.completion_time = { ...o.metrics.completion_time, value: null, average: null, median: null, p90: null, count: 0, no_data: true }
    reply('orders', o); mount('/admin/analytics?tab=orders')
    await screen.findByTestId('an-orders-kpis')
    expect(value('orders_placed')).toHaveTextContent(/^4$/); expect(value('gmv')).toHaveTextContent('₹1,545.00'); expect(value('average_order_value')).toHaveTextContent('₹515.00')
    const prep = screen.getByTestId('an-duration-preparation_time')
    expect(prep).toHaveTextContent('Preparation time'); expect(within(prep).getAllByRole('cell').map((c) => c.textContent)).toEqual(['4 min 10 s', '3 min 20 s', '1 h 2 min', '3'])
    expect(within(screen.getByTestId('an-duration-completion_time')).getAllByRole('cell')[0]).toHaveTextContent('No data for this period.')
    expect(within(screen.getByTestId('an-orders-durations')).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Step', 'Average', 'Median', '90th percentile', 'Orders measured'])
    expect(screen.getByTestId('an-orders-status').querySelector('[data-key="COMPLETED"]')).toHaveTextContent('Completed375.0%')
    // No rejection in the period: the exact words, not an empty table.
    expect(screen.getAllByTestId('an-empty').some((e) => e.getAttribute('data-for') === 'an-orders-rejections' && e.textContent === 'No data for this period.')).toBe(true)
    // Wednesday 12:00 has the four orders; the grid is a table with a number in the cell.
    const peak = screen.getByTestId('an-peak'); const cell = peak.querySelector('[data-day="3"][data-hour="12"]')!
    expect(cell).toHaveTextContent(/^4$/); expect(cell).toHaveClass('adm-an-heat--4'); expect(within(peak).getAllByRole('row')).toHaveLength(8); expect(within(peak).getByRole('rowheader', { name: 'Wed' })).toBeInTheDocument()
    expect(peak.querySelector('[data-day="1"][data-hour="0"]')).toHaveAttribute('data-orders', '0')
    expect(screen.getByTestId('an-estimate-estimated')).toHaveTextContent('20 min'); expect(screen.getByTestId('an-estimate-actual')).toHaveTextContent('0 s'); expect(screen.getByTestId('an-estimate-within')).toHaveTextContent('3')
    expect(screen.getByTestId('an-estimate-note')).toHaveTextContent(/operational comparison of two times/)
    expectClean(page())
  }, 30000)

  it('restaurants: a comparison table sorted by the reader, with no ranking — and "No access" for finance without the permission', async () => {
    const user = userEvent.setup(); const r = sample('restaurants'); const base = r.restaurants[0]
    r.restaurants = [
      { ...base, restaurant: { id: 'a1', name: 'Burger Hub' }, orders_placed: 4, gross_sales: null, net_earnings: null, finance_restricted: true },
      { ...base, restaurant: { id: 'b2', name: 'Agra Sweets' }, orders_placed: 9, gross_sales: null, net_earnings: null, finance_restricted: true, acceptance_rate: { ...base.acceptance_rate, value: null, insufficient: true, numerator: 1, denominator: 2 } },
      { ...base, restaurant: { id: 'c3', name: 'Chai Point' }, orders_placed: 1, gross_sales: null, net_earnings: null, finance_restricted: true, average_order_value: null },
    ]
    r.finance_restricted = true; reply('restaurants', r)
    mount('/admin/analytics?tab=restaurants')
    const table = await screen.findByTestId('an-restaurants-table')
    const names = () => [...table.querySelectorAll('tbody tr')].map((row) => row.getAttribute('data-restaurant'))
    expect(names()).toEqual(['b2', 'a1', 'c3'])   // by name, A → Z
    expect(within(table).getByRole('columnheader', { name: /Restaurant/ })).toHaveAttribute('aria-sort', 'ascending')
    await user.click(screen.getByTestId('an-sort-orders_placed')); await waitFor(() => expect(names()).toEqual(['b2', 'a1', 'c3']))   // 9, 4, 1
    expect(table).toHaveAttribute('data-sort', 'orders_placed'); expect(table).toHaveAttribute('data-dir', 'desc'); expect(within(table).getByRole('columnheader', { name: /Orders placed/ })).toHaveAttribute('aria-sort', 'descending')
    await user.click(screen.getByTestId('an-sort-orders_placed')); await waitFor(() => expect(names()).toEqual(['c3', 'a1', 'b2']))
    await user.click(screen.getByTestId('an-sort-average_order_value')); await waitFor(() => expect(names()[2]).toBe('c3'))   // a row without a value goes last
    expect(asked('restaurants')).toHaveLength(1)   // sorting asks the backend nothing

    const row = screen.getByTestId('an-restaurant-row-b2')
    expect(row.querySelector('[data-col="gross_sales"]')).toHaveTextContent(/^No access$/); expect(row.querySelector('[data-col="net_earnings"]')).toHaveTextContent(/^No access$/); expect(row).toHaveAttribute('data-finance-restricted', 'true')
    expect(row.querySelector('[data-col="acceptance_rate"]')).toHaveTextContent('Insufficient data'); expect(row.querySelector('[data-col="completed_order_total"]')).toHaveTextContent('₹1,545.00')
    expect(screen.getByTestId('an-restricted-restaurant_finance')).toBeInTheDocument()
    expect(screen.getByTestId('an-restaurants-note')).toHaveTextContent(/carry no ranking score/)
    expect(screen.getByTestId('an-restaurants')).not.toHaveTextContent(/top restaurant|best|rank #|score:/i); expect(table.querySelector('.db-rank')).toBeNull()

    // One restaurant's orders: the report is asked for that location, and the scope is shown with a way back.
    const orders = sample('orders'); orders.meta.scope.restaurant = { id: 'b2', name: 'Agra Sweets' }; reply('orders', orders)
    await user.click(screen.getByTestId('an-restaurant-orders-b2'))
    await waitFor(() => expect(lastAsked('orders')).toBe('/admin/analytics/orders?market=india&preset=last_30_days&restaurant=b2'))
    expect(await screen.findByTestId('an-restaurant-scope')).toHaveTextContent('Restaurant: Agra Sweets')
    await user.click(screen.getByTestId('an-restaurant-clear')); await waitFor(() => expect(lastAsked('orders')).toBe('/admin/analytics/orders?market=india&preset=last_30_days'))
  }, 30000)

  it('routes: corridors, bands and coverage gaps — a group that is too small says "Too few journeys to show", and the privacy statement is the backend\'s', async () => {
    const r = sample('routes-corridors')
    r.corridors.rows.push({ corridor: { id: 'quiet', name: 'Quiet road', highway: 'NH 99' }, searches: null, no_result_searches: null, impressions: null, restaurant_views: null, orders: null, orders_completed: null, suppressed: true })
    r.distance_bands[1] = { band: '25-50', unit: 'km', searches: null, suppressed: true }
    reply('routes', r); mount('/admin/analytics?tab=routes')
    const table = await screen.findByTestId('an-routes-corridors')
    const shown = table.querySelector('[data-suppressed="false"][data-testid^="an-corridor-"]')!
    expect(shown).toHaveTextContent('Noida east–west'); expect(shown).toHaveTextContent('20.0%'); expect(shown).toHaveTextContent('4 min')
    expect(screen.getByTestId('an-corridor-quiet')).toHaveAttribute('data-suppressed', 'true'); expect(screen.getByTestId('an-corridor-quiet')).toHaveTextContent('Quiet roadToo few journeys to show')
    expect(screen.getByTestId('an-corridor-other')).toHaveTextContent('Other corridors (1)Too few journeys to show'); expect(screen.getByTestId('an-corridor-other')).not.toHaveTextContent(/[0-9]{2}|null/)
    expect(screen.getByTestId('an-corridor-none')).toHaveTextContent('Journeys along no static corridor')
    expect(screen.getByTestId('an-routes-distance').querySelector('[data-band="25-50"]')).toHaveTextContent('25-50 kmToo few journeys to show'); expect(screen.getByTestId('an-routes-distance').querySelector('[data-band="0-25"]')).toHaveTextContent('0-25 km7')
    expect(screen.getByTestId('an-routes-detour').querySelector('[data-band="0-5"]')).toHaveTextContent('0-5 min5120')
    // Coverage gaps: information only.
    expect(screen.getByTestId('an-routes-gaps-reason')).toHaveTextContent('No service area along the route'); expect(screen.getByTestId('an-routes-insight')).toHaveTextContent(/Nothing here activates a market, a city or a service area/)
    expect(screen.getByTestId('an-routes-gaps-origin')).toHaveTextContent('Other cities (1)Too few journeys to show'); expect(screen.getByTestId('an-routes-demand-origin')).toHaveTextContent('Noida7')
    expect(screen.getByTestId('an-routes-privacy')).toHaveTextContent('Aggregated by corridor, city and band. No traveller\'s origin, destination or route is stored in analytics.')
    expect(value('journey_searches')).toHaveTextContent('7'); expect(screen.getByTestId('an-routes-historical')).toHaveTextContent(/HISTORICAL DATA UNAVAILABLE BEFORE MODULE 38/)
    expect(lastAsked('routes')).toBe('/admin/analytics/routes?market=india&preset=last_30_days')
    expectClean(page())
  }, 30000)

  it('finance and support need their own access: a 403 says which, and shows no figure', async () => {
    const user = userEvent.setup()
    routes['GET /admin/analytics/finance'] = refusal(403, 'forbidden', 'This action is unauthorized.'); routes['GET /admin/analytics/support'] = refusal(403, 'forbidden', 'This action is unauthorized.')
    mount('/admin/analytics?tab=finance')
    expect(await screen.findByTestId('an-forbidden')).toHaveTextContent(/^\s*You need finance access to see this report$/); expect(screen.getByTestId('an-forbidden')).toHaveAttribute('role', 'alert')
    expect(screen.queryByTestId('an-finance-kpis')).toBeNull(); expect(screen.queryByTestId('an-meta')).toBeNull()
    await user.click(screen.getByTestId('an-tab-support'))
    await waitFor(() => expect(screen.getByTestId('an-forbidden')).toHaveTextContent('You need support access to see this report')); expect(screen.queryByTestId('an-support-kpis')).toBeNull()
    // Another view still works.
    await user.click(screen.getByTestId('an-tab-refunds')); expect(await screen.findByTestId('an-refunds-kpis')).toBeInTheDocument(); expect(screen.queryByTestId('an-forbidden')).toBeNull()
  }, 30000)

  it('finance with access: ledger figures per currency, the terms, settlements and reconciliation', async () => {
    mount('/admin/analytics?tab=finance')
    await screen.findByTestId('an-finance-kpis')
    expect(value('platform_revenue')).toHaveTextContent('₹195.00'); expect(value('restaurant_payable')).toHaveTextContent('₹1,350.00'); expect(value('reconciliation_differences_open')).toHaveTextContent(/^0$/)
    expect(screen.getByTestId('an-kpi-platform_revenue').querySelector('[data-freshness="LEDGER"]')).toHaveTextContent('From the ledger')
    expect(await screen.findByTestId('an-freshness')).toHaveTextContent(/read at/)   // the ledger is read at the moment of the request: no "figures as of"
    expect(screen.getByTestId('an-finance-chart-INR')).toHaveTextContent('Amount (INR)'); expect(screen.getByTestId('an-finance-restaurants')).toHaveTextContent('Burger Hub'); expect(screen.getByTestId('an-finance-restaurants')).toHaveTextContent('₹150.00')
    expect(screen.getByTestId('an-finance-differences')).toHaveTextContent('0'); expect(screen.getByTestId('an-finance-source')).toHaveTextContent(/Financial ledger and allocations/)
    expectClean(page())
  }, 30000)

  it('payments, refunds, support, reviews and notifications show the backend\'s breakdowns — and no push "read" or "open" rate', async () => {
    const user = userEvent.setup(); mount('/admin/analytics?tab=payments')
    await screen.findByTestId('an-payments-kpis')
    expect(value('payment_success_rate')).toHaveTextContent('%'); expect(screen.getByTestId('an-payments-methods').querySelector('[data-key="UPI"]')).toHaveTextContent('Upi4100.0%4100.0%')
    expect(screen.getByTestId('an-payments-provider-note')).toHaveTextContent(/DEVELOPMENT provider/); expect(screen.getByTestId('an-payments-series')).toHaveTextContent('Payments (count)')
    await user.click(screen.getByTestId('an-tab-refunds')); await screen.findByTestId('an-refunds-kpis')
    expect(value('refund_amount')).toHaveTextContent('₹100.00'); expect(screen.getByTestId('an-refunds-kinds')).toHaveTextContent('Partial refund'); expect(screen.getByTestId('an-refunds-day-note')).toHaveTextContent(/by the day they were asked for/)
    await user.click(screen.getByTestId('an-tab-support')); await screen.findByTestId('an-support-kpis')
    expect(value('support_first_response_time')).toHaveTextContent('No data for this period.'); expect(screen.getByTestId('an-support-targets')).toHaveTextContent(/Not contractual/); expect(screen.getByTestId('an-support-no-ranking')).toHaveTextContent(/no figure per agent/)
    await user.click(screen.getByTestId('an-tab-reviews')); await screen.findByTestId('an-reviews-kpis')
    expect(value('average_rating')).toHaveTextContent('No data for this period.'); expect(screen.getByTestId('an-reviews-excluded')).toHaveTextContent(/hidden, rejected, removed/)
    await user.click(screen.getByTestId('an-tab-notifications')); await screen.findByTestId('an-notifications-kpis')
    expect(screen.getByTestId('an-push-read')).toHaveAttribute('data-tracked', 'false'); expect(screen.getByTestId('an-push-read')).toHaveTextContent(/NOT TRACKED\. "Sent" means the push provider accepted the message/)
    expect(value('in_app_read_rate')).toBeInTheDocument(); expect(screen.queryByTestId('an-kpi-push_read_rate')).toBeNull(); expect(screen.queryByTestId('an-kpi-push_open_rate')).toBeNull()
    expect(screen.getByTestId('an-view-notifications')).not.toHaveTextContent(/push (read|open) rate|opened rate/i)
    expect(screen.getByTestId('an-notifications-categories').querySelector('[data-key="ORDER_UPDATES"]')).toHaveTextContent('Order updates13')
    expectClean(page())
  }, 30000)

  it('never calls an aggregated view by the word for "this very moment", and never prints a non-number', async () => {
    const user = userEvent.setup(); mount()
    await screen.findByTestId('an-kpi-orders_completed')
    // The strings of these pages themselves never use the word (nor a word that contains it).
    for (const [key, text] of Object.entries(analyticsStrings)) expect(`${key}: ${text}`.replace(/^adm\.an\.live\.[a-zA-Z]+: /, '')).not.toMatch(/live/i)
    for (const tab of ['overview', 'customers', 'orders', 'restaurants', 'menu', 'pickup', 'routes', 'payments', 'refunds', 'finance', 'support', 'reviews', 'notifications']) {
      await user.click(screen.getByTestId(`an-tab-${tab}`))
      const view = await screen.findByTestId(`an-view-${tab}`); await waitFor(() => expect(view.querySelector('[data-testid="an-loading"]')).toBeNull())
      await waitFor(() => expect(view.querySelector('.adm-an-kpis, [data-testid="an-forbidden"]')).not.toBeNull())
      const copy = view.cloneNode(true) as HTMLElement
      // The ONE sentence of the backend that has the word is about payouts ("LIVE RESTAURANT PAYOUTS ARE DISABLED"), not about how fresh a figure is.
      copy.querySelector('[data-testid="an-finance-payouts-note"]')?.remove()
      expect(copy.textContent, tab).not.toMatch(/\blive\b/i); expect(copy.textContent, tab).not.toMatch(/real[- ]?time/i)
      expectClean(view)
      expect((await screen.findByTestId('an-meta')).textContent).not.toMatch(/\blive\b/i)
    }
    expect(screen.getByRole('tablist', { name: 'Analytics views' }).textContent).not.toMatch(/\blive\b/i)
  }, 60000)
})

describe('BackendAnalyticsPage (Module 38) — menu and pickup', () => {
  it('menu: the figures, and the items with what was looked at and what was sold under two headings, each with its own source', async () => {
    const m = sample('menu'); const first = m.items[0]
    m.items = [first, { ...first, item: { id: 'i2', name: 'Masala Fries' }, views: 40, cart_adds: 10, units_sold: 0, orders: 0, sales: { amount: 0, currency: 'INR' }, share_of_sales_percent: 0, cart_adds_per_view: 0.25 }, { ...first, item: { id: 'i3', name: 'Lassi' }, views: 2, cart_adds: 1, units_sold: 1, orders: 1, sales: { amount: 9000, currency: 'INR' }, share_of_sales_percent: null, cart_adds_per_view: null }]
    reply('menu', m); mount('/admin/analytics?tab=menu')
    await screen.findByTestId('an-menu-kpis')
    expect(screen.getByTestId('an-tab-menu')).toHaveAttribute('aria-selected', 'true'); expect(asked('menu')).toEqual(['/admin/analytics/menu?market=india&preset=last_30_days'])
    expect(value('units_sold')).toHaveTextContent(/^6$/); expect(value('item_sales')).toHaveTextContent('₹1,500.00'); expect(value('add_to_cart_rate')).toHaveTextContent('100.0%'); expect(value('menu_views')).toHaveTextContent(/^1$/)
    // Two sources, said in the backend's own words, next to the two groups of columns.
    expect(screen.getByTestId('an-menu-source-viewed')).toHaveTextContent('Behaviour — what was looked atSource: Behavioural events, recorded from Module 38 on')
    expect(screen.getByTestId('an-menu-source-sold')).toHaveTextContent('Orders — what was soldSource: Order items of completed orders (what the order recorded)')
    const table = within(screen.getByTestId('an-menu-items')).getByRole('table')
    const groups = [...table.querySelectorAll('th[scope="colgroup"]')]
    expect(groups.map((g) => [g.getAttribute('data-source'), g.getAttribute('colspan'), g.textContent])).toEqual([['viewed', '3', 'Behaviour — what was looked at'], ['sold', '4', 'Orders — what was sold']])
    expect(within(table).getAllByRole('columnheader').map((h) => h.textContent).slice(2)).toEqual(['Item', 'Item views', 'Added to cart', 'Cart adds per view', 'Units sold', 'Orders', 'Sales', 'Share of sales'])
    const rows = screen.getAllByTestId('an-menu-item-row'); expect(rows.map((r) => r.getAttribute('data-item'))).toEqual([first.item.id, 'i2', 'i3'])   // the backend's order: no ranking here
    const cells = (row: HTMLElement) => Object.fromEntries([...row.querySelectorAll('[data-col]')].map((c) => [c.getAttribute('data-col'), c.textContent]))
    expect(rows[0]).toHaveTextContent('Classic Burger'); expect(cells(rows[0])).toEqual({ views: '0', cart_adds: '0', cart_adds_per_view: '—', units_sold: '6', orders: '3', sales: '₹1,500.00 INR', share_of_sales: '100.0%' })
    expect(cells(rows[1])).toMatchObject({ views: '40', cart_adds: '10', cart_adds_per_view: '0.25', units_sold: '0', share_of_sales: '0.0%' })
    // Too few views for a quotient, no share: words and a dash — never a zero that was not measured.
    expect(cells(rows[2])).toMatchObject({ cart_adds_per_view: 'Insufficient data', share_of_sales: '—', sales: '₹90.00 INR' })
    expect(screen.getByTestId('an-menu-advice')).toHaveTextContent('These figures are information for the restaurant. Nothing is changed on a menu because of them.')
    expect(await screen.findByTestId('an-freshness')).toHaveTextContent(/Updated about every 5 minutes/); expectClean(page())
  }, 30000)

  it('menu: no item in the period is said in words; a restaurant chosen in the Restaurants view narrows the report', async () => {
    const user = userEvent.setup(); const m = sample('menu'); m.items = []; reply('menu', m)
    mount('/admin/analytics?tab=menu&restaurant=2a8e8668-184e-42ba-ae54-d37cd82ca78b')
    await screen.findByTestId('an-menu-kpis')
    expect(asked('menu')).toEqual(['/admin/analytics/menu?market=india&preset=last_30_days&restaurant=2a8e8668-184e-42ba-ae54-d37cd82ca78b'])
    expect(screen.queryByTestId('an-menu-item-row')).toBeNull(); expect(within(screen.getByTestId('an-menu-items')).getByTestId('an-empty')).toHaveTextContent(/^No data for this period\.$/)
    expect(screen.getByTestId('an-menu-source-sold')).toBeInTheDocument()
    await user.click(screen.getByTestId('an-tab-pickup')); await screen.findByTestId('an-pickup-kpis')
    expect(lastAsked('pickup')).toBe('/admin/analytics/pickup?market=india&preset=last_30_days&restaurant=2a8e8668-184e-42ba-ae54-d37cd82ca78b')
    await user.click(screen.getByTestId('an-tab-routes')); await screen.findByTestId('an-routes-kpis'); expect(lastAsked('routes')).toBe('/admin/analytics/routes?market=india&preset=last_30_days')   // routes have no restaurant grain
  }, 30000)

  it('pickup: modes with their lead time, methods, refusals — and no utilization figure where no capacity is configured', async () => {
    mount('/admin/analytics?tab=pickup')
    await screen.findByTestId('an-pickup-kpis')
    expect(asked('pickup')).toEqual(['/admin/analytics/pickup?market=india&preset=last_30_days'])
    expect(value('pickup_scheduled_orders')).toHaveTextContent(/^4$/); expect(value('pickup_asap_orders')).toHaveTextContent(/^0$/); expect(value('pickup_lead_time')).toHaveTextContent('2 h 15 min')
    expect(value('recommended_slot_acceptance')).toHaveTextContent('No data for this period.')
    expect(screen.getByTestId('an-pickup-modes').querySelector('[data-key="SCHEDULED"]')).toHaveTextContent('Scheduled4100.0%2 h 15 min'); expect(within(screen.getByTestId('an-pickup-modes')).getByRole('columnheader', { name: 'Lead time (average)' })).toBeInTheDocument()
    expect(screen.getByTestId('an-pickup-methods').querySelector('[data-key="COUNTER"]')).toHaveTextContent('Counter4100.0%')
    expect(within(screen.getByTestId('an-pickup-refusals')).getByTestId('an-empty')).toHaveTextContent(/^No data for this period\.$/)
    // No capacity per slot is configured: the backend's sentence, and no utilization anywhere on the page.
    const capacity = screen.getByTestId('an-pickup-capacity')
    expect(capacity).toHaveAttribute('data-configured', 'false'); expect(screen.getByTestId('an-pickup-capacity-note')).toHaveTextContent('No capacity per slot is configured in this scope: capacity is unlimited and no utilization is shown.')
    expect(capacity).not.toHaveTextContent(/%|[0-9]/); expect(screen.queryByTestId('an-kpi-pickup_capacity_utilization')).toBeNull(); expect(screen.queryByTestId('an-stat-capacity-pickup_capacity_utilization')).toBeNull(); expect(screen.queryByTestId('an-pickup-units-used')).toBeNull()
    expect(screen.getByTestId('an-pickup-delivery')).toHaveTextContent(/^Not offered: there are no delivery figures\.$/)
    expectClean(page())
  }, 30000)

  it('pickup: with a configured capacity the block shows the utilization with its numerator and denominator, and the refusal reasons are listed', async () => {
    const d = sample('pickup')
    d.capacity = { configured: true, slots_with_orders: 6, slots_full: 2, units_used: 30, units_capacity: 40, note: 'Capacity of the slots that had at least one order.' }
    d.metrics.pickup_capacity_utilization = { ...d.metrics.pickup_capacity_utilization, value: 75, numerator: 30, denominator: 40, insufficient: false, no_data: false }
    d.refusal_reasons = [{ key: 'SLOT_FULL', label: 'Slot full', count: 3 }, { key: 'RESTAURANT_CLOSED', label: 'Restaurant closed', count: 1 }]
    d.modes = [{ key: 'ASAP', label: 'As soon as possible', count: 1, lead_time_average: null }, ...d.modes]
    reply('pickup', d); mount('/admin/analytics?tab=pickup')
    const capacity = await screen.findByTestId('an-pickup-capacity')
    expect(capacity).toHaveAttribute('data-configured', 'true'); expect(screen.getByTestId('an-stat-capacity-pickup_capacity_utilization-value')).toHaveTextContent('75.0%'); expect(screen.getByTestId('an-stat-capacity-pickup_capacity_utilization')).toHaveTextContent('30 of 40')
    expect(screen.getByTestId('an-pickup-slots-with-orders')).toHaveTextContent('6'); expect(screen.getByTestId('an-pickup-slots-full')).toHaveTextContent('2'); expect(screen.getByTestId('an-pickup-units-used')).toHaveTextContent('30'); expect(screen.getByTestId('an-pickup-units-capacity')).toHaveTextContent('40')
    expect(screen.getByTestId('an-pickup-capacity-note')).toHaveTextContent('Capacity of the slots that had at least one order.'); expect(screen.queryByTestId('an-kpi-pickup_capacity_utilization')).toBeNull()   // shown once, in its block
    expect(screen.getByTestId('an-pickup-refusals').querySelector('[data-key="SLOT_FULL"]')).toHaveTextContent('Slot full375.0%')
    expect(screen.getByTestId('an-pickup-modes').querySelector('[data-key="ASAP"]')).toHaveTextContent('As soon as possible120.0%—')
  }, 30000)
})

describe('BackendAnalyticsPage (Module 38) — reports', () => {
  it('asks for an export, watches it while it is prepared, stops watching when it is ready, and downloads it', async () => {
    const user = userEvent.setup()
    let exports: Array<Record<string, unknown>> = []; let reads = 0
    routes['GET /admin/analytics/exports'] = () => { reads++; return { body: { data: exports, reports: REPORTS, expiry_hours: 24, max_rows: 50000, format: 'CSV' } } }
    routes['POST /admin/analytics/exports'] = () => {
      exports = [anExport()]; let step = 0
      routes['GET /admin/analytics/exports'] = () => { reads++; step++; exports = [step === 1 ? anExport({ status: 'PROCESSING' }) : ready()]; return { body: { data: exports, reports: REPORTS, expiry_hours: 24, max_rows: 50000, format: 'CSV' } } }
      return { status: 202, body: { export: anExport() } }
    }
    routes[`GET /admin/analytics/exports/${ID}/download`] = { csv: 'order_number,status\nFOTG-1,COMPLETED\n', filename: 'orders-india-2026-10-01-2026-10-07.csv' }
    mount('/admin/analytics?tab=reports&preset=last_7_days', { pollMs: 40 })
    const select = await screen.findByTestId('an-export-report')
    expect([...select.querySelectorAll('option')].map((o) => o.textContent)).toEqual(['Orders', 'Financial summary']); expect(select).toHaveAccessibleName('Report')
    expect(screen.getByTestId('an-exports-empty')).toHaveTextContent('You have not asked for an export yet.')
    expect(screen.getByTestId('an-export-scope')).toHaveTextContent('The export will cover: India · Last 7 days'); expect(screen.getByTestId('an-export-new')).toHaveTextContent('24 hours')
    // Nothing is being prepared: nothing is polled.
    await act(async () => { await new Promise((r) => setTimeout(r, 150)) }); expect(reads).toBe(1); expect(screen.getByTestId('an-export-watching')).toHaveAttribute('data-watching', 'false')

    await user.click(screen.getByTestId('an-export-generate'))
    const sent = calls.find((c) => c.method === 'POST')!
    expect(sent.path).toBe('/admin/analytics/exports'); expect(sent.body).toEqual({ report: 'orders', market: 'india', preset: 'last_7_days' })
    const row = await screen.findByTestId(`an-export-row-${ID}`)
    expect(screen.getByTestId('an-export-notice')).toHaveTextContent('"Orders" is queued.')
    // Queued → Processing → Ready, by itself.
    await waitFor(() => expect(screen.getByTestId(`an-export-status-${ID}`)).toHaveAttribute('data-status', 'READY'))
    expect(screen.getByTestId(`an-export-status-${ID}`)).toHaveTextContent('Ready'); expect(row).toHaveAttribute('data-status', 'READY')
    expect(screen.getByTestId(`an-export-rows-${ID}`)).toHaveTextContent('4'); expect(screen.getByTestId(`an-export-expires-${ID}`)).toHaveTextContent('Oct 8, 2026, 12:01 PM'); expect(row).toHaveTextContent('Oct 1, 2026 – Oct 7, 2026')
    // …and the watching stops: no further read once nothing is queued or processing.
    await waitFor(() => expect(screen.getByTestId('an-export-watching')).toHaveAttribute('data-watching', 'false'))
    const settled = reads; await act(async () => { await new Promise((r) => setTimeout(r, 250)) }); expect(reads).toBe(settled); expect(settled).toBeGreaterThanOrEqual(3)

    await user.click(screen.getByTestId(`an-export-download-${ID}`))
    await waitFor(() => expect(screen.getByTestId('an-export-notice')).toHaveTextContent('Downloaded orders-india-2026-10-01-2026-10-07.csv.'))
    const got = calls.find((c) => c.path === `/admin/analytics/exports/${ID}/download`)!
    expect(got.method).toBe('GET'); expect(got.headers.Authorization).toBe('Bearer admin-token')
    expect(vi.mocked(fetch).mock.calls.some(([url]) => url === `${BASE}/admin/analytics/exports/${ID}/download`)).toBe(true)   // not /api/v1/api/v1/…
  }, 30000)

  it('shows every state of an export with its own word, and a download only when it is ready', async () => {
    const ids = ['q', 'p', 'r', 'f', 'x']
    routes['GET /admin/analytics/exports'] = { body: { data: [anExport({ id: 'q' }), anExport({ id: 'p', status: 'PROCESSING' }), ready({ id: 'r', download_path: '/api/v1/admin/analytics/exports/r/download' }), anExport({ id: 'f', status: 'FAILED', failure_code: 'TOO_MANY_ROWS' }), anExport({ id: 'x', status: 'EXPIRED', expires_at: '2026-10-06T06:31:00Z' })], reports: REPORTS, expiry_hours: 24, max_rows: 50000, format: 'CSV' } }
    mount('/admin/analytics?tab=reports', { pollMs: 100000 })
    await screen.findByTestId('an-export-row-q')
    expect(ids.map((id) => screen.getByTestId(`an-export-status-${id}`).textContent)).toEqual(['Queued', 'Processing', 'Ready', 'Failed', 'Expired'])
    expect(ids.filter((id) => screen.queryByTestId(`an-export-download-${id}`))).toEqual(['r']); expect(screen.getByTestId('an-export-row-f')).toHaveTextContent('Too many rows')
    expect(screen.getByTestId('an-export-row-q')).toHaveTextContent('Being prepared'); expect(screen.getByTestId('an-export-row-x')).toHaveTextContent('No file')
    expect(screen.getByTestId('an-export-watching')).toHaveAttribute('data-watching', 'true'); expect(screen.getByTestId('an-export-download-r')).toHaveAccessibleName(/^Download\s*— Orders$/)
  }, 30000)

  it('shows a refusal where it happened: 403 and 409 on asking, 409 / 410 / 403 on the download', async () => {
    const user = userEvent.setup()
    routes['GET /admin/analytics/exports'] = { body: { data: [ready()], reports: REPORTS, expiry_hours: 24, max_rows: 50000, format: 'CSV' } }
    mount('/admin/analytics?tab=reports', { pollMs: 100000 })
    await user.selectOptions(await screen.findByTestId('an-export-report'), 'financial_summary')
    expect(screen.getByTestId('an-export-requires')).toHaveTextContent('admin.analytics.export, admin.finance.view, admin.finance.export')
    routes['POST /admin/analytics/exports'] = refusal(403, 'forbidden', 'This action is unauthorized.')
    await user.click(screen.getByTestId('an-export-generate'))
    expect(await screen.findByTestId('an-export-error')).toHaveTextContent('You do not hold the permissions this report needs (admin.analytics.export, admin.finance.view, admin.finance.export).'); expect(screen.getByTestId('an-export-error')).toHaveAttribute('role', 'alert')
    expect(calls.find((c) => c.method === 'POST')!.body).toEqual({ report: 'financial_summary', market: 'india', preset: 'last_30_days' })
    routes['POST /admin/analytics/exports'] = refusal(409, 'too_many_exports', 'Wait for your exports that are being prepared before asking for another.', { pending: 5 })
    await user.click(screen.getByTestId('an-export-generate')); await waitFor(() => expect(screen.getByTestId('an-export-error')).toHaveTextContent('Wait for your exports that are being prepared before asking for another.'))
    routes['POST /admin/analytics/exports'] = refusal(422, 'range_too_long', 'A period covers at most 366 days.')
    await user.click(screen.getByTestId('an-export-generate')); await waitFor(() => expect(screen.getByTestId('an-export-error')).toHaveTextContent('A period covers at most 366 days.'))

    const download = `GET /admin/analytics/exports/${ID}/download`
    routes[download] = refusal(409, 'export_not_ready', 'This export is not ready yet.')
    await user.click(screen.getByTestId(`an-export-download-${ID}`)); expect(await screen.findByTestId(`an-export-problem-${ID}`)).toHaveTextContent('This export is not ready yet.')
    routes[download] = refusal(410, 'export_expired', 'This export has expired. Ask for it again.')
    await user.click(screen.getByTestId(`an-export-download-${ID}`)); await waitFor(() => expect(screen.getByTestId(`an-export-problem-${ID}`)).toHaveTextContent('This export has expired. Ask for it again.'))
    routes[download] = refusal(403, 'forbidden', 'This action is unauthorized.')
    await user.click(screen.getByTestId(`an-export-download-${ID}`)); await waitFor(() => expect(screen.getByTestId(`an-export-problem-${ID}`)).toHaveTextContent('You no longer hold the permission to download this export.'))
  }, 30000)

  it('the Reports view exists only for a holder of analytics.export', async () => {
    localStorage.setItem(K.session, 'adm-tom')   // Restaurant onboarding: analytics.view, no analytics.export
    mount('/admin/analytics?tab=reports')
    await screen.findByTestId('an-kpi-orders_completed')   // falls back to the overview
    expect(screen.queryByTestId('an-tab-reports')).toBeNull(); expect(screen.queryByTestId('an-export-generate')).toBeNull(); expect(calls.some((c) => c.path.includes('/exports'))).toBe(false)
    expect(screen.getAllByRole('tab').map((t) => t.textContent)).toEqual(['Overview', 'Customers', 'Orders', 'Restaurants', 'Menu', 'Pickup', 'Routes', 'Payments', 'Refunds', 'Finance', 'Support', 'Reviews', 'Notifications'])
    cleanup(); localStorage.setItem(K.session, 'adm-alex'); mount()
    expect(await screen.findByTestId('an-tab-reports')).toHaveTextContent('Reports')
  }, 30000)
})

describe('the analytics switch (Module 38)', () => {
  const live = () => { for (const k of ['market', 'restaurant', 'cart', 'pickup', 'checkout', 'payment', 'order']) localStorage.setItem(`fotg.${k}.mode`, 'api') }
  const shell = (path: string) => render(
    <LocaleProvider>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/admin" element={<AdminProvider repos={adminRepositories}><AdminLayout /></AdminProvider>}>
            <Route path="overview" element={<OverviewPage />} />
            <Route path="analytics" element={<AnalyticsPage />} />
          </Route>
        </Routes>
      </MemoryRouter>
    </LocaleProvider>,
  )

  it('without the backend the Module 18 development pages stay, and nothing is asked of the analytics API', async () => {
    shell('/admin/analytics')
    expect(await screen.findByTestId('an-orders')).toBeInTheDocument(); expect(screen.getByTestId('adm-analytics')).not.toHaveAttribute('data-source'); expect(screen.queryByTestId('an-tab-overview')).toBeNull()
    expect(screen.getByTestId('env-badge')).toHaveAttribute('data-source', 'mock')
    cleanup(); shell('/admin/overview')
    expect(await screen.findByTestId('kpi-restaurants')).toBeInTheDocument(); expect(screen.getByTestId('adm-overview')).toHaveAttribute('data-figures', 'mock'); expect(screen.queryByTestId('an-live')).toBeNull(); expect(screen.getByTestId('ov-top')).toBeInTheDocument()
    expect(calls.filter((c) => c.path.startsWith('/admin/analytics'))).toHaveLength(0)
  }, 30000)

  it('with the backend the Analytics entry opens the backend page, and the Overview shows the backend\'s figures in place of the development snapshot', async () => {
    live(); routes['GET /admin/support/metrics'] = { body: { open: 7 } }
    shell('/admin/analytics')
    expect(await screen.findByTestId('an-kpi-orders_completed')).toBeInTheDocument(); expect(screen.getByTestId('adm-analytics')).toHaveAttribute('data-source', 'backend'); expect(screen.queryByTestId('an-orders')).toBeNull()
    expect(screen.getByTestId('env-badge')).toHaveAttribute('data-source', 'backend')
    cleanup(); calls = []
    shell('/admin/overview')
    const kpis = await screen.findByTestId('ov-an-kpis')
    await waitFor(() => expect(kpis).toHaveAttribute('data-state', 'ready')); expect(screen.getByTestId('adm-overview')).toHaveAttribute('data-figures', 'backend')
    // Seven figures of analytics and the service desk's own open cases — eight cards, none of them a development number.
    expect(kpis.querySelectorAll('.db-kpi')).toHaveLength(8); expect(value('orders_completed')).toHaveTextContent(/^3$/); expect(value('gmv')).toHaveTextContent('₹1,545.00')
    expect(screen.getByTestId('an-kpi-orders_completed-comparison')).toHaveTextContent('New — no earlier figure to compare'); expect(screen.getByTestId('an-kpi-gmv-definition')).toBeInTheDocument()
    await waitFor(() => expect(screen.getByTestId('kpi-support')).toHaveTextContent('7')); expect(screen.getByTestId('kpi-support-value')).toHaveAttribute('data-source', 'backend'); expect(screen.queryByTestId('an-kpi-support_open_cases')).toBeNull()
    for (const gone of ['kpi-restaurants', 'kpi-active', 'kpi-customers', 'kpi-orders', 'kpi-refunds', 'kpi-rating', 'ov-orders-chart', 'ov-gmv', 'ov-top']) expect(screen.queryByTestId(gone)).toBeNull()
    expect(document.querySelector('.db-delta')).toBeNull()   // no literal delta is left
    expect(await screen.findByTestId('an-live')).toHaveTextContent('Right now'); expect(screen.getByTestId('ov-an-trend')).toBeInTheDocument(); expect(screen.getByTestId('ov-an-freshness')).toHaveTextContent(/Updated about every 5 minutes · figures as of/)
    // The header's market is the scope (every market in this fixture scope), the period is the last 30 days.
    expect(asked('overview')).toEqual(['/admin/analytics/overview?market=all&preset=last_30_days']); expect(asked('live')[0]).toBe('/admin/analytics/live?market=all')
    expect(screen.getByTestId('adm-overview').textContent).not.toMatch(/\blive\b/i); expectClean(screen.getByTestId('ov-an-kpis'))
  }, 30000)

  it('the Overview without the analytics permission shows no platform figure — and asks for none', async () => {
    live(); localStorage.setItem(K.session, 'adm-lea')   // Support admin: no analytics.view
    routes['GET /admin/support/metrics'] = { body: { open: 7 } }
    shell('/admin/overview')
    expect(await screen.findByTestId('ov-an-no-access')).toHaveTextContent(/does not include analytics/)
    await waitFor(() => expect(screen.getByTestId('kpi-support')).toHaveTextContent('7')); expect(screen.getByTestId('ov-an-kpis').querySelectorAll('.db-kpi')).toHaveLength(1)
    expect(screen.queryByTestId('kpi-restaurants')).toBeNull(); expect(screen.queryByTestId('an-live')).toBeNull(); expect(calls.filter((c) => c.path.startsWith('/admin/analytics'))).toHaveLength(0)
  }, 30000)

  it('the Overview says so when the analytics figures cannot be read, and offers a retry', async () => {
    const user = userEvent.setup(); live(); let fail = true
    routes['GET /admin/analytics/overview'] = () => (fail ? { status: 500, body: {} } : { body: sample('overview') })
    shell('/admin/overview')
    expect(await screen.findByTestId('ov-an-error')).toHaveTextContent('The analytics figures could not be read.'); expect(screen.queryByTestId('an-kpi-orders_completed')).toBeNull()
    fail = false; await user.click(within(screen.getByTestId('ov-an-error')).getByRole('button', { name: 'Try again' }))
    expect(await screen.findByTestId('an-kpi-orders_completed')).toBeInTheDocument()
  }, 30000)
})

describe('AnalyticsMetricsCard and the data-quality panel (Module 38)', () => {
  it('shows when the read models were last rebuilt, the quality status, what they hold, the exports and the flags', async () => {
    render(<AnalyticsMetricsCard locale="en" load={async () => SYSTEM as never} />)
    const card = await screen.findByTestId('an-system-card')
    expect(card).toHaveAttribute('data-stale', 'false'); expect(card).toHaveAttribute('data-quality', 'HEALTHY'); expect(card).toHaveAttribute('data-production', 'NO'); expect(card).toHaveAttribute('data-historical', 'UNAVAILABLE BEFORE MODULE 38')
    expect(screen.getByTestId('an-system-last-success')).toHaveTextContent('Oct 7, 2026'); expect(screen.getByTestId('an-system-stale')).toHaveTextContent('Up to date'); expect(screen.getByTestId('an-system-schedule')).toHaveTextContent('Every 5 minutes')
    expect(screen.getByTestId('an-system-quality')).toHaveTextContent('Data quality: Healthy'); expect(screen.getByTestId('an-system-quality')).toHaveAttribute('data-status', 'HEALTHY')
    expect(screen.getByTestId('an-system-events')).toHaveTextContent('1,234'); expect(screen.getByTestId('an-system-sessions')).toHaveTextContent('56'); expect(screen.getByTestId('an-system-order-facts')).toHaveTextContent('78')
    expect(screen.getByTestId('an-system-exports').querySelector('[data-status="READY"]')).toHaveTextContent('2'); expect(screen.getByTestId('an-system-exports').querySelector('[data-status="EXPIRED"]')).toHaveTextContent('1')
    expect(screen.getByTestId('an-system-historical')).toHaveTextContent('UNAVAILABLE BEFORE MODULE 38'); expect(screen.getByTestId('an-system-production')).toHaveTextContent('NO'); expect(screen.getByTestId('an-system-trackers')).toHaveTextContent('No')
    expect(screen.getByTestId('an-system-warehouse')).toHaveTextContent('NOT REQUIRED / NOT IMPLEMENTED'); expect(screen.getByTestId('an-system-approval')).toHaveTextContent('PENDING BUSINESS APPROVAL')
    expect(within(screen.getByTestId('an-system-runs')).getAllByRole('row')).toHaveLength(2); expect(screen.getByTestId('an-system-runs')).toHaveTextContent('850 ms')
    expect(card.textContent).not.toMatch(/\blive\b/i); expectClean(card)
  }, 30000)

  it('a stale aggregation and a check that never ran are not green; an empty export count is a list, not a crash', async () => {
    render(<AnalyticsMetricsCard locale="en" load={async () => ({ ...SYSTEM, aggregation: { ...SYSTEM.aggregation, last_success_at: null, stale: true, scheduled: false, runs: [] }, data_quality: 'NEVER_CHECKED', exports: [] }) as never} />)
    const card = await screen.findByTestId('an-system-card')
    expect(card).toHaveAttribute('data-stale', 'true'); expect(screen.getByTestId('an-system-last-success')).toHaveTextContent('Never'); expect(screen.getByTestId('an-system-stale')).toHaveTextContent(/Behind/); expect(screen.getByTestId('an-system-schedule')).toHaveTextContent('Not scheduled')
    expect(screen.getByTestId('an-system-quality')).toHaveTextContent('Never checked'); expect(screen.getByTestId('an-system-exports')).toHaveTextContent('None'); expect(screen.queryByTestId('an-system-runs')).toBeNull()
    expect(document.querySelectorAll('.db-badge--green')).toHaveLength(0)
  }, 30000)

  it('offers a retry when the status cannot be read', async () => {
    const user = userEvent.setup(); let fail = true
    render(<AnalyticsMetricsCard locale="en" load={async () => { if (fail) throw new Error('forbidden'); return SYSTEM as never }} />)
    expect(await screen.findByText('Analytics status unavailable')).toBeInTheDocument(); expect(screen.queryByTestId('an-system-card')).toBeNull()
    fail = false; await user.click(screen.getByRole('button', { name: /try again|retry/i })); expect(await screen.findByTestId('an-system-card')).toBeInTheDocument()
  }, 30000)

  it('the data-quality panel shows the last result of every check — and is simply absent for an administrator of one market (403)', async () => {
    mount()
    const status = await screen.findByTestId('an-quality-status')
    expect(status).toHaveAttribute('data-status', 'WARNING'); expect(status).toHaveTextContent('Warning'); expect(screen.getByTestId('an-quality-checked')).toHaveTextContent('Last checked')
    expect(screen.getByTestId('an-quality-checks').querySelector('[data-check="unknown_market_share"]')).toHaveTextContent('Unknown market shareWarning7% of events have no market.')
    cleanup()
    render(<QualityPanel locale="en" load={async () => { throw new Error('403') }} />)
    await act(async () => { await Promise.resolve() }); expect(screen.queryByTestId('an-quality')).toBeNull(); expect(screen.queryByRole('alert')).toBeNull()
  }, 30000)
})

describe('how a figure is written (Module 38)', () => {
  const metric = (patch: Record<string, unknown>) => ({ key: 'k', label: 'K', unit: 'count', value: 0, ...patch }) as never
  it('durations read like "4 min 10 s"', () => {
    expect(duration(0, 'en')).toBe('0 s'); expect(duration(45, 'en')).toBe('45 s'); expect(duration(60, 'en')).toBe('1 min'); expect(duration(250, 'en')).toBe('4 min 10 s')
    expect(duration(3600, 'en')).toBe('1 h'); expect(duration(7500, 'en')).toBe('2 h 5 min'); expect(duration(86400, 'en')).toBe('1 d'); expect(duration(273600, 'en')).toBe('3 d 4 h')
    expect(duration(null, 'en')).toBe('—'); expect(duration(Number.NaN, 'en')).toBe('—'); expect(duration(Number.POSITIVE_INFINITY, 'en')).toBe('—'); expect(duration(-5, 'en')).toBe('0 s')
    expect(milliseconds(850, 'en')).toBe('850 ms'); expect(milliseconds(2500, 'en')).toBe('3 s'); expect(milliseconds(null, 'en')).toBe('—')
  })

  it('a percentage stays between 0 and 100 with one decimal', () => {
    expect(percent(25, 'en')).toBe('25.0%'); expect(percent(33.33, 'en')).toBe('33.3%'); expect(percent(0, 'en')).toBe('0.0%'); expect(percent(140, 'en')).toBe('100.0%'); expect(percent(-3, 'en')).toBe('0.0%')
    expect(percent(Number.NaN, 'en')).toBe('—'); expect(percent(Number.POSITIVE_INFINITY, 'en')).toBe('—'); expect(percent(null, 'en')).toBe('—')
  })

  it('a comparison is a signed number, the word "New", or nothing — never an infinite or undefined percentage', () => {
    expect(comparisonOf({ previous: 0, change_pct: null, state: 'NEW' }, 'count', 'en')).toEqual({ state: 'NEW', text: 'New — no earlier figure to compare' })
    expect(comparisonOf({ previous: 10, change_pct: 12.5, state: 'UP' }, 'count', 'en')?.text).toBe('+12.5% vs the previous period')
    expect(comparisonOf({ previous: 10, change_pct: -40, state: 'DOWN' }, 'money', 'en')?.text).toBe('−40.0% vs the previous period')
    expect(comparisonOf({ previous: 10, change_pct: 0, state: 'FLAT' }, 'count', 'en')?.text).toBe('0.0% vs the previous period')
    expect(comparisonOf({ previous: 20, change_points: 2.5, state: 'UP' }, 'percent', 'en')?.text).toBe('+2.5 pp vs the previous period')
    for (const nothing of [null, undefined, { previous: 0, change_pct: null, state: 'NO_DATA' }, { previous: 0, change_pct: Number.POSITIVE_INFINITY, state: 'UP' }, { previous: 0, change_pct: Number.NaN, state: 'DOWN' }, { previous: 5, change_pct: null, state: 'UP' }, { previous: 5, change_pct: 3, state: 'SIDEWAYS' }]) expect(comparisonOf(nothing as never, 'count', 'en')).toBeNull()
    expect(comparisonOf({ previous: 20, change_pct: 9, change_points: null, state: 'UP' }, 'percent', 'en')).toBeNull()   // a rate changes in points, or not at all
  })

  it('a figure without a usable number is words — whatever arrived', () => {
    expect(showMetric(metric({ unit: 'money', value: null, values: [], mixed_currencies: false }), 'en')).toMatchObject({ state: 'no_data', text: 'No data for this period.' })
    expect(showMetric(metric({ unit: 'money', value: null, values: [{ amount: null, currency: 'INR', count: 0 }], mixed_currencies: false, no_data: true }), 'en')).toMatchObject({ state: 'no_data' })
    expect(showMetric(metric({ unit: 'money', value: { amount: 51500, currency: 'INR' }, values: [{ amount: 51500, currency: 'INR' }] }), 'en')).toMatchObject({ state: 'value', text: '₹515.00', detail: 'INR' })
    expect(showMetric(metric({ unit: 'money', value: { amount: 98000, currency: 'JPY' }, values: [{ amount: 98000, currency: 'JPY' }] }), 'en').text).toBe('¥98,000')
    expect(showMetric(metric({ unit: 'percent', value: 140, numerator: 7, denominator: 5 }), 'en').text).toBe('100.0%')
    expect(showMetric(metric({ unit: 'percent', value: null, insufficient: true, minimum_denominator: 20, denominator: 3 }), 'en')).toMatchObject({ state: 'insufficient', text: 'Insufficient data', detail: '3 so far — at least 20 are needed for a rate' })
    expect(showMetric(metric({ unit: 'seconds', value: 250, average: 250, median: 200, p90: 400, count: 3, no_data: false }), 'en')).toMatchObject({ text: '4 min 10 s', detail: 'Average · median 3 min 20 s · 90th percentile 6 min 40 s' })
    expect(showMetric(metric({ unit: 'milliseconds', value: 420, average: 420, median: null, p90: null, count: 2, no_data: false }), 'en')).toMatchObject({ text: '420 ms', detail: 'Average' })
    expect(showMetric(metric({ unit: 'rating', value: 4.256, numerator: 17, denominator: 4 }), 'en')).toMatchObject({ text: '4.26 out of 5', detail: '4 published reviews' })
    expect(showMetric(metric({ unit: 'rating', value: null, no_data: true }), 'en').state).toBe('no_data')
    expect(showMetric(metric({ unit: 'number', value: 1.5, numerator: 3, denominator: 2 }), 'en')).toMatchObject({ text: '1.5', detail: '3 over 2' })
    expect(showMetric(metric({ unit: 'count', value: 12345 }), 'en').text).toBe('12,345')
    expect(showMetric(metric({ unit: 'count', value: null, restricted: true, requires: 'admin.finance.view' }), 'en')).toMatchObject({ state: 'restricted', text: 'You do not have access to this figure', comparison: null })
    for (const broken of [Number.NaN, Number.POSITIVE_INFINITY, undefined, 'x']) for (const unit of ['count', 'percent', 'seconds', 'number', 'rating', 'money']) expect(showMetric(metric({ unit, value: broken }), 'en').text).not.toMatch(/NaN|Infinity|undefined/)
  })

  it('a count axis starts at 0 and has whole steps', () => {
    expect(axisTicks(4, true)).toEqual([0, 1, 2, 3, 4]); expect(axisTicks(3, true)).toEqual([0, 1, 2, 3]); expect(axisTicks(0, true)).toEqual([0, 1]); expect(axisTicks(37, true)).toEqual([0, 10, 20, 30, 40])
    expect(axisTicks(154500, false)).toEqual([0, 50000, 100000, 150000, 200000]); expect(axisTicks(Number.NaN, true)).toEqual([0, 1])
    for (const max of [1, 2, 7, 13, 99, 1000]) { const ticks = axisTicks(max, true); expect(ticks[0]).toBe(0); expect(ticks.every((v) => Number.isInteger(v))).toBe(true); expect(ticks[ticks.length - 1]).toBeGreaterThanOrEqual(max); expect(ticks.length).toBeLessThanOrEqual(6) }
  })
})

describe('chart buckets (Module 38)', () => {
  it('shows bucket keys as the labels they are, in the order given — an hour of the day the clocks go back is not merged or parsed', async () => {
    const o = sample('orders')
    o.series = { bucket: 'hour', timezone: 'America/New_York', currency: 'USD', money_series_available: true, points: [
      { bucket: '2026-11-01 00:00', orders_placed: 1, orders_completed: 1, gmv_minor: 1000 }, { bucket: '2026-11-01 01:00', orders_placed: 2, orders_completed: 1, gmv_minor: 2000 },
      { bucket: '2026-11-01 01:00 (EST)', orders_placed: 3, orders_completed: 2, gmv_minor: 3000 }, { bucket: '2026-11-01 02:00', orders_placed: 0, orders_completed: 0, gmv_minor: 0 },
    ] }
    reply('orders', o); mount('/admin/analytics?tab=orders&market=united-states&preset=today')
    const table = await screen.findByTestId('an-orders-series-table')
    expect(within(table).getAllByRole('rowheader').map((h) => h.textContent)).toEqual(['2026-11-01 00:00', '2026-11-01 01:00', '2026-11-01 01:00 (EST)', '2026-11-01 02:00'])
    expect(within(table).getByRole('row', { name: /01:00 \(EST\)/ })).toHaveTextContent('2026-11-01 01:00 (EST)32'); expect(within(table).getByRole('columnheader', { name: 'Hour' })).toBeInTheDocument()
    expect(screen.getByTestId('an-orders-series-caption')).toHaveTextContent('By hour · Time zone America/New_York')
    expect(screen.getByTestId('an-orders-series-gmv')).toHaveTextContent('GMV (USD)'); expect(screen.getByTestId('an-orders-series-gmv-table')).toHaveTextContent('$30.00')
    expect(screen.getByTestId('an-orders-series').textContent).not.toMatch(/Invalid Date|NaN/)
  }, 30000)

  it('a period without a single value says "No data for this period." instead of an empty chart', async () => {
    const o = sample('orders'); o.series.points = o.series.points.map((p: Record<string, unknown>) => ({ ...p, orders_placed: 0, orders_completed: 0, gmv_minor: 0 })); o.peak_times.cells = []
    reply('orders', o); mount('/admin/analytics?tab=orders')
    await screen.findByTestId('an-orders-kpis')
    const empties = screen.getAllByTestId('an-empty'); const forThe = (id: string) => empties.find((e) => e.getAttribute('data-for') === id)
    expect(forThe('an-orders-series')).toHaveTextContent(/^No data for this period\.$/); expect(forThe('an-orders-series-gmv')).toHaveTextContent(/^No data for this period\.$/); expect(forThe('an-peak')).toHaveTextContent(/^No data for this period\.$/)
    expect(screen.queryByTestId('an-orders-series')).toBeNull(); expect(screen.queryByTestId('an-peak')).toBeNull()
  }, 30000)
})

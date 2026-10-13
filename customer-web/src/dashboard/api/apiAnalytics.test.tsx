/**
 * Restaurant Dashboard analytics on the backend (Module 38). The network is stubbed with payloads in the shape of
 * GET /restaurant/analytics/* (the backend tests' own answers, shortened); the tests check what is ASKED (location,
 * period, the export calls), what the page shows (the backend's figures in the agreed words, the definition of every
 * figure, how fresh they are) and what it must never show (a sum across currencies, a percentage of almost nothing,
 * NaN / Infinity, the word "live", a customer by name, a utilization without a capacity, an export tab without the
 * permission).
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, tokens } from '../../api/client'
import { restaurantPermissionsFromApi } from '../../auth/staff/staffAuth'
import { LocaleProvider } from '../../i18n/LocaleProvider'
import { DashboardProvider } from '../DashboardContext'
import DashboardLayout, { DEMO_SECTIONS, NAV, RequirePermission } from '../DashboardLayout'
import { dashboardRepositories, permissionsForRole, setMockDashboardLatency } from '../mock/mockDashboard'
import AnalyticsPage from '../pages/AnalyticsPage'
import OverviewPage from '../pages/OverviewPage'
import { BackendAnalyticsPage } from '../pages/analytics/BackendAnalyticsPage'
import { comparisonText, fmtDuration, metricText, tickLabels } from '../pages/analytics/format'
import type { DashboardLocation, DashboardRepositories, LocationLive } from '../types'
import { ApiRestaurantAnalyticsRepository, analyticsQuery, describeAnalyticsError, exportDownloadPath, type AnalyticsExportDto, type MetricDto } from './apiAnalytics'

const BASE = 'http://127.0.0.1:8001/api/v1'
const A = '/restaurant/analytics'
const L1 = '0a000000-0000-4000-8000-0000000000a1'; const L2 = '0a000000-0000-4000-8000-0000000000a2'; const X1 = '0f000000-0000-4000-8000-0000000000e1'

/* ------------------------------------------------------------------ answers in the backend's shape */

const LOCATIONS = [{ id: L1, name: 'Burger Hub', currency: 'INR', timezone: 'Asia/Kolkata' }, { id: L2, name: 'Burger Hub Express', currency: 'INR', timezone: 'Asia/Kolkata' }]
const meta = (over: Record<string, unknown> = {}) => ({
  period: { preset: 'last_30_days', from: '2026-09-14', to: '2026-10-13', days: 30, timezone: 'Asia/Kolkata', bucket: 'day', starts_at: '2026-09-13T18:30:00Z', ends_before: '2026-10-13T18:30:00Z', previous: { from: '2026-08-15', to: '2026-09-13' } },
  scope: { locations: LOCATIONS, all_readable: true }, timezone: 'Asia/Kolkata', currency: 'INR',
  freshness: { mode: 'AGGREGATED', label: 'Updated about every 5 minutes', aggregated_at: '2026-10-13T06:30:00Z', stale: false, generated_at: '2026-10-13T06:31:00Z', cached: false, cache_seconds: 0 },
  permissions: { finance: true }, definitions: '/api/v1/restaurant/analytics/catalog', ...over,
})
const NEW = { previous: 0, change_pct: null, state: 'NEW' }; const NONE = { previous: 0, change_pct: null, state: 'NO_DATA' }
const count = (key: string, label: string, value: number, comparison: unknown = null): MetricDto => ({ key, label, unit: 'count', freshness: 'AGGREGATED', value, comparison } as MetricDto)
const money = (key: string, label: string, amount: number, comparison: unknown = null, currency = 'INR'): MetricDto => ({ key, label, unit: 'money', freshness: 'AGGREGATED', value: { amount, currency }, values: [{ amount, currency }], mixed_currencies: false, comparison } as MetricDto)
const rate = (key: string, label: string, value: number | null, numerator: number, denominator: number, over: Partial<MetricDto> = {}): MetricDto => ({ key, label, unit: 'percent', freshness: 'AGGREGATED', value, numerator, denominator, insufficient: false, no_data: denominator === 0, minimum_denominator: 5, comparison: null, ...over })
const duration = (key: string, label: string, average: number | null, median: number | null, p90: number | null, n: number): MetricDto => ({ key, label, unit: 'seconds', freshness: 'AGGREGATED', value: average, average, median, p90, count: n, no_data: n === 0 })
const SERIES = { bucket: 'day', timezone: 'Asia/Kolkata', currency: 'INR', money_series_available: true, points: [{ bucket: '2026-10-11', orders_placed: 0, orders_completed: 0, gmv_minor: 0 }, { bucket: '2026-10-12', orders_placed: 1, orders_completed: 1, gmv_minor: 50000 }, { bucket: '2026-10-13', orders_placed: 2, orders_completed: 2, gmv_minor: 100000 }] }
const STATEMENT = 'Counts of customers who completed an order here. No customer is named or listed.'
const SALES_NOTE = 'Sales are what customers paid for completed orders. Your earnings — after commission — are on the Earnings tab.'
const overview = (over: Record<string, unknown> = {}, metrics: Record<string, unknown> = {}) => ({
  meta: meta(),
  metrics: {
    orders_placed: count('orders_placed', 'Orders placed', 3, NEW), orders_completed: count('orders_completed', 'Orders completed', 3, NEW),
    completed_order_total: money('completed_order_total', 'Sales of completed orders', 150000, NEW), average_order_value: { ...money('average_order_value', 'Average order value', 50000), no_data: false },
    restaurant_acceptance_rate: rate('restaurant_acceptance_rate', 'Acceptance rate', 100, 3, 3), restaurant_refund_rate: rate('restaurant_refund_rate', 'Refund rate (orders)', 33.3, 1, 3),
    average_rating: { key: 'average_rating', label: 'Average rating', unit: 'rating', freshness: 'AGGREGATED', value: null, numerator: 0, denominator: 0, no_data: true, previous: null },
    reviews_published: count('reviews_published', 'Reviews published', 0, NONE), restaurant_net_earnings: { ...money('restaurant_net_earnings', 'Restaurant net earnings', 135000), freshness: 'LEDGER' }, ...metrics,
  },
  series: SERIES, status_distribution: [{ key: 'COMPLETED', label: 'Completed', count: 3 }],
  customers: { ordering_customers: 3, first_time_here: 2, returning: 1, orders_per_customer: 1.5, suppressed: false, minimum_cohort: 5, statement: STATEMENT },
  locations: [], sales_note: SALES_NOTE, ...over,
})
const ORDERS = {
  meta: meta(),
  metrics: {
    orders_placed: count('orders_placed', 'Orders placed', 12, { previous: 8, change_pct: 50, state: 'UP' }), orders_completed: count('orders_completed', 'Orders completed', 10, { previous: 10, change_pct: 0, state: 'FLAT' }),
    orders_rejected: count('orders_rejected', 'Orders rejected', 2, { previous: 4, change_pct: -50, state: 'DOWN' }), orders_cancelled: count('orders_cancelled', 'Orders cancelled', 0, NONE),
    orders_in_progress: count('orders_in_progress', 'Orders still in progress', 0), orders_refunded: count('orders_refunded', 'Orders with a refund', 1, NEW), orders_refund_required: count('orders_refund_required', 'Orders waiting for a refund', 0),
    order_completion_rate: rate('order_completion_rate', 'Order completion rate', 83.3, 10, 12, { comparison: { previous: 85.8, change_points: -2.5, state: 'DOWN' } }),
    order_rejection_rate: rate('order_rejection_rate', 'Order rejection rate', 16.7, 2, 12, { comparison: { previous: null, change_points: null, state: 'NEW' } }),
    gmv: money('gmv', 'Gross merchandise value (GMV)', 500000, { previous: 400000, change_pct: 25, state: 'UP' }), average_order_value: money('average_order_value', 'Average order value', 50000), median_order_value: money('median_order_value', 'Median order value', 45000),
    acceptance_time: duration('acceptance_time', 'Time to accept', 250, 180, 600, 12), preparation_time: duration('preparation_time', 'Preparation time', 1210, 1100, 1800, 10),
    pickup_wait_time: duration('pickup_wait_time', 'Ready-to-pickup time', 45, 40, 7500, 10), completion_time: duration('completion_time', 'Time to completion', null, null, null, 0),
  },
  series: SERIES, status_distribution: [{ key: 'COMPLETED', label: 'Completed', count: 10 }, { key: 'REJECTED', label: 'Rejected', count: 2 }],
  rejection_reasons: [{ key: 'KITCHEN_CAPACITY', label: 'Kitchen capacity', count: 2 }],
  peak_times: { timezone_note: "Each restaurant's own time zone", week_starts: 'MONDAY', cells: [{ day_of_week: 3, hour: 12, orders: 3 }] },
  estimated_preparation: { orders: 3, estimated_seconds_average: 1200, actual_seconds_average: 1210, ready_within_estimate: 2, note: 'An operational comparison of two times. It says nothing about the food.' },
}
const MENU = {
  meta: meta(),
  metrics: { menu_views: count('menu_views', 'Menu views', 40), item_views: count('item_views', 'Item views', 25), cart_adds: count('cart_adds', 'Add-to-cart', 9), units_sold: count('units_sold', 'Units sold', 6), item_sales: money('item_sales', 'Item sales', 150000), add_to_cart_rate: rate('add_to_cart_rate', 'Add-to-cart rate', 22.5, 9, 40) },
  items: [
    { item: { id: '02a57517-6e71-4160-a8de-6c27fcda3e99', name: 'Classic Burger' }, views: 20, cart_adds: 8, units_sold: 5, orders: 3, sales: { amount: 125000, currency: 'INR' }, share_of_sales_percent: 83.3, cart_adds_per_view: 0.4 },
    { item: { id: null, name: 'French Fries' }, views: 5, cart_adds: 1, units_sold: 1, orders: 1, sales: { amount: 25000, currency: 'INR' }, share_of_sales_percent: null, cart_adds_per_view: null },
  ],
  sold_source: 'Order items of completed orders (what the order recorded)', viewed_source: 'Behavioural events, recorded from Module 38 on',
  advice_only: 'These figures are information for the restaurant. Nothing is changed on a menu because of them.',
}
const CAPACITY_NOTE = 'No capacity per slot is configured in this scope: capacity is unlimited and no utilization is shown.'
const operations = (capacity: Record<string, unknown> = { configured: false, slots_with_orders: null, slots_full: null, units_used: null, units_capacity: null, note: CAPACITY_NOTE }, utilization = rate('pickup_capacity_utilization', 'Capacity used in slots with orders', null, 0, 0)) => ({
  meta: meta(),
  metrics: { orders_rejected: ORDERS.metrics.orders_rejected, orders_cancelled: ORDERS.metrics.orders_cancelled, order_rejection_rate: ORDERS.metrics.order_rejection_rate, acceptance_time: ORDERS.metrics.acceptance_time, preparation_time: ORDERS.metrics.preparation_time, pickup_wait_time: ORDERS.metrics.pickup_wait_time, completion_time: ORDERS.metrics.completion_time },
  rejection_reasons: ORDERS.rejection_reasons, peak_times: ORDERS.peak_times, estimated_preparation: ORDERS.estimated_preparation,
  pickup: {
    metrics: {
      pickup_asap_orders: count('pickup_asap_orders', 'ASAP pickups', 4), pickup_scheduled_orders: count('pickup_scheduled_orders', 'Scheduled pickups', 8), pickup_lead_time: duration('pickup_lead_time', 'Pickup lead time', 7200, null, null, 8),
      pickup_refusals: count('pickup_refusals', 'Pickup times refused', 3), pickup_slot_full: count('pickup_slot_full', 'Pickup times refused: slot full', 2),
      recommended_slot_acceptance: rate('recommended_slot_acceptance', 'Recommended time chosen', null, 2, 3, { insufficient: true }), pickup_capacity_utilization: utilization,
    },
    modes: [{ key: 'SCHEDULED', label: 'Scheduled', count: 8, lead_time_average: 7200 }, { key: 'ASAP', label: 'Asap', count: 4, lead_time_average: null }],
    methods: [{ key: 'COUNTER', label: 'Counter', count: 12 }], refusal_reasons: [{ key: 'SLOT_FULL', label: 'Slot full', count: 2 }, { key: 'RESTAURANT_CLOSED', label: 'Restaurant closed', count: 1 }],
    capacity, delivery: 'Not offered: there are no delivery figures.',
  },
  operational_note: 'These are times between steps of an order. They describe the operation, not the food.',
})
const PRIVACY = "Counts and bands of your own orders. No traveller's route, origin or destination is shown — none is stored."
const ROUTES = {
  meta: meta(),
  metrics: { restaurant_impressions: count('restaurant_impressions', 'Restaurant impressions', 60), journey_restaurant_views: count('journey_restaurant_views', 'Restaurant views from a journey', 20), journey_orders: count('journey_orders', 'Orders from a journey', 9), average_detour: duration('average_detour', 'Average detour of ordering travellers', 240, null, null, 9) },
  detour_bands: [{ band: '0-5', unit: 'minutes', orders: 7, completed: 6, suppressed: false }, { band: '5-10', unit: 'minutes', orders: null, completed: null, suppressed: true }, { band: '10-15', unit: 'minutes', orders: 0, completed: 0, suppressed: false }, { band: '15+', unit: 'minutes', orders: 0, completed: 0, suppressed: false }],
  privacy: { minimum_cohort: 5, statement: PRIVACY }, views_note: 'Restaurant views here are all views of your page; impressions are the times you were returned for a journey.',
}
const REVIEWS = {
  meta: meta(),
  metrics: { reviews_published: count('reviews_published', 'Reviews published', 6, { previous: 3, change_pct: 100, state: 'UP' }), average_rating: { key: 'average_rating', label: 'Average rating', unit: 'rating', value: 4.33, numerator: 26, denominator: 6, no_data: false }, review_response_rate: rate('review_response_rate', 'Restaurant response rate', 50, 3, 6), reviews_reported: count('reviews_reported', 'Reports of reviews', 0, NONE) },
  distribution: [{ stars: 5, reviews: 3 }, { stars: 4, reviews: 2 }, { stars: 3, reviews: 1 }, { stars: 2, reviews: 0 }, { stars: 1, reviews: 0 }],
  series: { bucket: 'day', timezone: 'Asia/Kolkata', points: [{ bucket: '2026-10-12', reviews_published: 2, average_rating: 4.5 }, { bucket: '2026-10-13', reviews_published: 4, average_rating: 4.25 }] },
  excluded: 'Reviews that are hidden, rejected, removed or waiting for moderation are in no figure.', alert_note: 'A falling rating is information for a person. Nothing is done to a restaurant because of it.',
}
const inr = (amount: number) => ({ amount, currency: 'INR' })
const earnPeriod = (orders: number, gross: number, commission: number, net: number) => ({ orders, gross_sales: inr(gross), restaurant_discount: inr(0), commission: inr(commission), adjustments: inr(0), net_earnings: inr(net) })
const SOURCE = 'The same figures as the Earnings page: the finance module (Module 36). Analytics adds nothing to them.'
const EARNINGS = {
  meta: meta({ freshness: { mode: 'LEDGER', label: 'Read from the financial ledger now', aggregated_at: null, stale: false, generated_at: '2026-10-13T06:31:00Z', cached: false, cache_seconds: 0 } }),
  summary: [{ currency: 'INR', pending: inr(0), pending_orders: 0, awaiting_terms_orders: 0, available: inr(135000), held: inr(0), in_settlement: inr(0), paid_out: inr(0), balance: inr(135000), lifetime: earnPeriod(3, 150000, 15000, 135000), periods: { today: earnPeriod(3, 150000, 15000, 135000), this_week: earnPeriod(3, 150000, 15000, 135000), this_month: earnPeriod(3, 150000, 15000, 135000) } }],
  series: [{ day: '2026-10-13', currency: 'INR', ...earnPeriod(3, 150000, 15000, 135000) }],
  definitions: { gross_sales: 'Merchandise of completed orders as priced, before discounts, tax and fees.', net_earnings: 'Gross sales minus restaurant-funded discounts and commission, plus adjustments.', available: 'Earned and ready for the next settlement.', balance: 'What FoodOnTheGo owes the restaurant now, from the ledger.' },
  source: SOURCE, series_note: 'The earnings series covers the most recent days up to today (at most 92), as on the Earnings page.',
}
const definition = (key: string, name: string, text: string, over: Record<string, unknown> = {}) => ({ key, name, group: 'orders', unit: 'count', definition: text, source: 'Order domain (orders.placed_at)', numerator: null, denominator: null, exclusions: 'None: every order counts, whatever became of it.', freshness: 'AGGREGATED', ...over })
const CATALOG = {
  metrics: [
    definition('orders_placed', 'Orders placed', 'Orders created in the period (by the time they were placed).'),
    definition('restaurant_acceptance_rate', 'Acceptance rate', 'Share of answered orders that the restaurant accepted.', { unit: 'percent', numerator: 'Orders the restaurant accepted', denominator: 'Orders the restaurant accepted or rejected', exclusions: 'Orders nobody answered yet.' }),
    definition('completed_order_total', 'Sales of completed orders', 'What customers paid for the orders completed in the period.', { unit: 'money' }),
    definition('acceptance_time', 'Time to accept', 'From the order being placed to the restaurant accepting it.', { unit: 'seconds' }),
  ],
  periods: { presets: ['today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'previous_month', 'custom'], default: 'last_30_days', max_range_days: 366 }, privacy: { minimum_cohort: 5, statement: "Your own restaurant's figures only." },
}
const anExport = (over: Partial<AnalyticsExportDto> = {}): AnalyticsExportDto => ({
  id: X1, report: 'orders', label: 'Orders', status: 'QUEUED', failure_code: null, period: { from: '2026-09-14', to: '2026-10-13', timezone: 'Asia/Kolkata' }, scope: { locations: LOCATIONS, all_readable: true },
  format: 'CSV', row_count: null, size_bytes: null, filename: null, download_path: null, download_count: 0, requested_at: '2026-10-13T06:40:00Z', completed_at: null, expires_at: null, ...over,
})
const READY = { status: 'READY' as const, row_count: 1234, size_bytes: 20480, filename: 'foodonthego-orders-2026-09-14-to-2026-10-13.csv', download_path: `/api/v1${A}/exports/${X1}/download`, completed_at: '2026-10-13T06:41:00Z', expires_at: '2026-10-14T06:41:00Z' }
const exportList = (data: AnalyticsExportDto[]) => ({ data, reports: [{ key: 'orders', label: 'Orders' }, { key: 'menu_items', label: 'Menu items' }], expiry_hours: 24 })

/* ------------------------------------------------------------------ the stubbed network */

type Call = { method: string; path: string; body: unknown; headers: Record<string, string> }
type Reply = { status?: number; body?: unknown; csv?: string; filename?: string } | 'network'
let calls: Call[] = []
let routes: Record<string, Reply | ((call: Call) => Reply)> = {}
let saved: Array<{ name: string; href: string }> = []
const refused = (status: number, code: string, text: string, fields?: Record<string, string[]>): Reply => ({ status, body: { error: { code, message: text, details: fields ? { fields } : {}, request_id: 'r1' } } })
const asked = (name: string) => calls.filter((c) => c.method === 'GET' && c.path.split('?')[0] === `${A}/${name}`)
const last = (name: string) => asked(name).at(-1)?.path ?? ''

beforeEach(() => {
  calls = []; saved = []; localStorage.clear(); sessionStorage.clear(); setMockDashboardLatency(0); tokens.set('restaurant', 'staff-token')
  routes = {
    [`GET ${A}/catalog`]: { body: CATALOG }, [`GET ${A}/overview`]: { body: overview() }, [`GET ${A}/orders`]: { body: ORDERS }, [`GET ${A}/menu`]: { body: MENU }, [`GET ${A}/operations`]: { body: operations() },
    [`GET ${A}/routes`]: { body: ROUTES }, [`GET ${A}/reviews`]: { body: REVIEWS }, [`GET ${A}/earnings`]: { body: EARNINGS }, [`GET ${A}/exports`]: { body: exportList([]) },
  }
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const path = decodeURIComponent(url.replace(BASE, '')); const method = init.method ?? 'GET'
    const call: Call = { method, path, body: init.body ? JSON.parse(init.body as string) : null, headers: (init.headers ?? {}) as Record<string, string> }
    calls.push(call)
    const route = routes[`${method} ${path.split('?')[0]}`]
    const r = typeof route === 'function' ? route(call) : route
    if (r === 'network') throw new TypeError('Failed to fetch')
    if (!r) return new Response(JSON.stringify({ error: { code: 'not_found', message: `No stub for ${method} ${path}`, details: {} } }), { status: 404 })
    if (r.csv !== undefined) return new Response(r.csv, { status: 200, headers: { 'Content-Type': 'text/csv; charset=UTF-8', ...(r.filename ? { 'Content-Disposition': `attachment; filename="${r.filename}"` } : {}) } })
    return new Response(JSON.stringify(r.body ?? {}), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } })
  }))
  // A download is an object URL on a temporary <a download>: both are observed instead of followed.
  vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:analytics-test'), revokeObjectURL: vi.fn() }))
  vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) { saved.push({ name: this.download, href: this.href }) })
})
afterEach(() => { cleanup(); vi.restoreAllMocks(); vi.unstubAllGlobals(); tokens.set('restaurant', null) })

/* ------------------------------------------------------------------ the API layer */

describe('ApiRestaurantAnalyticsRepository (Module 38)', () => {
  it('asks every report for one location or none ("all"), and for a preset or a custom range — with the staff token', async () => {
    const repo = new ApiRestaurantAnalyticsRepository()
    await repo.overview(); await repo.overview({ location: 'all', preset: 'today' }); await repo.orders({ location: L1, preset: 'custom', from: '2026-10-01', to: '2026-10-07' })
    await repo.menu({ location: L2, preset: 'previous_month', from: '2026-01-01', to: '2026-01-31' }); await repo.operations({ location: null }); await repo.routes({}); await repo.reviews({ preset: 'yesterday' }); await repo.earnings({ location: L1 })
    expect(calls.map((c) => c.path)).toEqual([
      `${A}/overview?preset=last_30_days`, `${A}/overview?preset=today`, `${A}/orders?location=${L1}&preset=custom&from=2026-10-01&to=2026-10-07`,
      `${A}/menu?location=${L2}&preset=previous_month`,   // the dates of a preset are the backend's: none is sent
      `${A}/operations?preset=last_30_days`, `${A}/routes?preset=last_30_days`, `${A}/reviews?preset=yesterday`, `${A}/earnings?location=${L1}&preset=last_30_days`,
    ])
    for (const c of calls) { expect(c.method).toBe('GET'); expect(c.headers.Authorization).toBe('Bearer staff-token'); expect(c.headers.Accept).toBe('application/json') }
    expect(analyticsQuery({ location: 'all' })).toEqual({ location: undefined, preset: 'last_30_days' })
    const catalog = await repo.catalog()
    expect(calls.at(-1)?.path).toBe(`${A}/catalog`); expect(catalog.metrics[0]).toMatchObject({ key: 'orders_placed', name: 'Orders placed' }); expect(catalog.periods.max_range_days).toBe(366)
  })

  it('asks for an export with exactly the fields the backend accepts, reads the list and one export', async () => {
    const repo = new ApiRestaurantAnalyticsRepository()
    routes[`POST ${A}/exports`] = { status: 202, body: { export: anExport() } }; routes[`GET ${A}/exports/${X1}`] = { body: { export: anExport({ status: 'PROCESSING' }) } }; routes[`GET ${A}/exports`] = { body: exportList([anExport()]) }
    expect((await repo.requestExport('orders', { location: L1, preset: 'last_7_days' })).status).toBe('QUEUED')
    await repo.requestExport('menu_items', { location: 'all', preset: 'custom', from: '2026-10-01', to: '2026-10-07' }); await repo.requestExport('orders')
    const posts = calls.filter((c) => c.method === 'POST')
    expect(posts.map((c) => [c.path, c.body])).toEqual([
      [`${A}/exports`, { report: 'orders', location: L1, preset: 'last_7_days' }], [`${A}/exports`, { report: 'menu_items', preset: 'custom', from: '2026-10-01', to: '2026-10-07' }], [`${A}/exports`, { report: 'orders', preset: 'last_30_days' }],
    ])
    expect(posts[0].headers['Content-Type']).toBe('application/json')
    const list = await repo.exports()
    expect(calls.at(-1)).toMatchObject({ method: 'GET', path: `${A}/exports` }); expect(list.data).toHaveLength(1); expect(list.reports.map((r) => r.key)).toEqual(['orders', 'menu_items']); expect(list.expiry_hours).toBe(24)
    expect((await repo.exportStatus(X1)).status).toBe('PROCESSING'); expect(calls.at(-1)?.path).toBe(`${A}/exports/${X1}`)
  })

  it('downloads a ready export from the API root once — the backend\'s "/api/v1/…" path is never doubled', async () => {
    const repo = new ApiRestaurantAnalyticsRepository()
    routes[`GET ${A}/exports/${X1}/download`] = { csv: 'Order,Total\n', filename: READY.filename }
    expect(await repo.downloadExport(anExport(READY))).toBe(READY.filename)
    expect(calls[0].path).toBe(`${A}/exports/${X1}/download`); expect(calls[0].headers).toMatchObject({ Accept: 'text/csv', Authorization: 'Bearer staff-token' })
    expect(saved).toEqual([{ name: READY.filename, href: 'blob:analytics-test' }])
    expect(exportDownloadPath({ id: X1, download_path: `/api/v1${A}/exports/${X1}/download` })).toBe(`${A}/exports/${X1}/download`)
    expect(exportDownloadPath({ id: X1, download_path: `${A}/exports/${X1}/download` })).toBe(`${A}/exports/${X1}/download`)
    expect(exportDownloadPath({ id: X1, download_path: null })).toBe(`${A}/exports/${X1}/download`)
    expect(exportDownloadPath({ id: X1, download_path: 'https://elsewhere.example/file.csv' })).toBe(`${A}/exports/${X1}/download`)   // never another host, never another route

    routes[`GET ${A}/exports/${X1}/download`] = refused(410, 'export_expired', 'This export has expired. Ask for it again.')
    await expect(repo.downloadExport(anExport(READY))).rejects.toMatchObject({ status: 410, code: 'export_expired' })
    expect(saved).toHaveLength(1)   // nothing was saved for the refused download
    // Another origin may hide the header that carries the file name: the export's own name is used.
    routes[`GET ${A}/exports/${X1}/download`] = { csv: 'Order,Total\n' }
    expect(await repo.downloadExport(anExport(READY))).toBe(READY.filename)
    expect(await repo.downloadExport(anExport({ ...READY, filename: null, report: 'menu_items' }))).toBe('foodonthego-menu-items.csv')
  })

  it('puts a refusal into words: the backend\'s own for 404 / 409 / 410 / 422, a plain sentence for the rest', async () => {
    const repo = new ApiRestaurantAnalyticsRepository()
    const problem = async (reply: Reply) => { routes[`GET ${A}/overview`] = reply; try { await repo.overview(); return null } catch (e) { expect(reply === 'network' || e instanceof ApiError).toBe(true); return describeAnalyticsError(e) } }
    expect(await problem(refused(403, 'forbidden', 'This action is unauthorized.'))).toMatchObject({ kind: 'forbidden', status: 403, message: 'You need analytics access for this location to see these figures.' })
    expect(await problem(refused(404, 'restaurant_not_found', 'This restaurant does not exist.'))).toMatchObject({ kind: 'not_found', code: 'restaurant_not_found', message: 'This restaurant does not exist.' })
    expect(await problem(refused(409, 'too_many_exports', 'Wait for your exports that are being prepared before asking for another.'))).toMatchObject({ kind: 'conflict', code: 'too_many_exports', message: 'Wait for your exports that are being prepared before asking for another.' })
    expect(await problem(refused(410, 'export_expired', 'This export has expired. Ask for it again.'))).toMatchObject({ kind: 'expired', status: 410, message: 'This export has expired. Ask for it again.' })
    expect(await problem(refused(422, 'range_too_long', 'A period covers at most 366 days.', { from: ['At most 366 days before "to".'] }))).toMatchObject({ kind: 'invalid', code: 'range_too_long', message: 'A period covers at most 366 days. At most 366 days before "to".' })
    expect(await problem(refused(429, 'rate_limited', 'Too many requests.'))).toMatchObject({ kind: 'rate_limited', message: 'Too many requests. Please wait a moment and try again.' })
    expect(await problem(refused(500, 'server_error', 'SQLSTATE[42P01] relation does not exist'))).toMatchObject({ kind: 'failed', message: 'A temporary problem stopped us from loading this. Please try again.' })
    expect(await problem('network')).toMatchObject({ kind: 'offline' })
    expect(describeAnalyticsError(new Error('boom'))).toMatchObject({ kind: 'failed' })
  })

  it('maps restaurant.analytics.export: owners and managers export, a viewer only reads; the page stays at its route', () => {
    expect(restaurantPermissionsFromApi(['restaurant.analytics.view', 'restaurant.analytics.export', 'restaurant.analytics.manage'])).toEqual(['analytics.view', 'analytics.export'])
    for (const role of ['owner', 'manager'] as const) { expect(permissionsForRole(role)).toContain('analytics.view'); expect(permissionsForRole(role)).toContain('analytics.export') }
    expect(permissionsForRole('viewer')).toContain('analytics.view'); expect(permissionsForRole('viewer')).not.toContain('analytics.export')
    for (const role of ['order_staff', 'menu_manager'] as const) { expect(permissionsForRole(role)).not.toContain('analytics.view'); expect(permissionsForRole(role)).not.toContain('analytics.export') }
    expect(NAV.find((n) => n.id === 'analytics')).toMatchObject({ path: 'analytics', perm: 'analytics.view' })
    // Without the backend order domain (this test run) the overview and analytics are the development sample: they keep the banner.
    expect(DEMO_SECTIONS).toContain('analytics'); expect(DEMO_SECTIONS).toContain('overview')
  })

  it('with the order domain on the backend: analytics is wired in and neither the overview nor analytics carries the demo banner', async () => {
    for (const domain of ['market', 'restaurant', 'cart', 'pickup', 'checkout', 'payment', 'order']) localStorage.setItem(`fotg.${domain}.mode`, 'api')
    vi.resetModules()
    const layout = await import('../DashboardLayout'); const wired = await import('./apiDashboard'); const api = await import('./apiAnalytics')
    expect(layout.DEMO_SECTIONS).not.toContain('analytics'); expect(layout.DEMO_SECTIONS).not.toContain('overview')
    expect(layout.DEMO_SECTIONS).toEqual(expect.arrayContaining(['notifications', 'settings']))   // what is still per-browser stays labelled
    expect(wired.apiDashboardRepositories.reports).toBeInstanceOf(api.ApiRestaurantAnalyticsRepository)
    vi.resetModules()
  })
})

describe('Figures in words (Module 38)', () => {
  it('reads durations out, lists currencies separately and never produces NaN, Infinity or an endless percentage', () => {
    expect([fmtDuration(45, 'en'), fmtDuration(250, 'en'), fmtDuration(240, 'en'), fmtDuration(7500, 'en'), fmtDuration(7200, 'en'), fmtDuration(0, 'en')]).toEqual(['45 s', '4 min 10 s', '4 min', '2 h 5 min', '2 h', '0 s'])
    expect([fmtDuration(Number.NaN, 'en'), fmtDuration(Number.POSITIVE_INFINITY, 'en'), fmtDuration(null, 'en'), fmtDuration(-5, 'en')]).toEqual([null, null, null, null])
    expect(metricText(count('x', 'X', Number.NaN), 'en')).toEqual({ state: 'no_data', text: 'No data for this period.' })
    expect(metricText(rate('x', 'X', Number.POSITIVE_INFINITY, 1, 0, { no_data: false }), 'en').text).toBe('No data for this period.')
    expect(metricText(rate('x', 'X', null, 2, 3, { insufficient: true }), 'en')).toEqual({ state: 'insufficient', text: 'Insufficient data' })
    expect(metricText(rate('x', 'X', 33.33, 1, 3), 'en').text).toBe('33.3%'); expect(metricText(rate('x', 'X', 0, 0, 3), 'en').text).toBe('0.0%')
    expect(metricText(undefined, 'en').text).toBe('No data for this period.')
    expect(metricText({ key: 'e', label: 'E', unit: 'money', value: null, restricted: true, requires: 'restaurant.finance.view' }, 'en')).toEqual({ state: 'restricted', text: 'You need earnings access to see this figure' })
    expect(metricText({ key: 'e', label: 'E', unit: 'count', value: null, restricted: true, requires: 'restaurant.support.view' }, 'en').text).toBe('You do not have access to this figure.')
    const mixed = metricText({ key: 'm', label: 'M', unit: 'money', value: null, values: [{ amount: 150000, currency: 'INR' }, { amount: 1200, currency: 'USD' }], mixed_currencies: true }, 'en')
    expect(mixed.state).toBe('mixed'); expect(mixed.parts?.map((p) => p.text)).toEqual(['₹1,500.00', '$12.00']); expect(mixed.text).not.toMatch(/1,512|151,200/)
    expect(metricText({ key: 'a', label: 'A', unit: 'money', value: null, values: [{ amount: null, currency: 'INR', count: 0 }], mixed_currencies: false, no_data: true }, 'en').state).toBe('no_data')

    expect(comparisonText({ previous: 0, change_pct: null, state: 'NEW' }, 'count', 'en')).toBe('New — no earlier figure to compare')
    expect(comparisonText({ previous: 8, change_pct: 50, state: 'UP' }, 'count', 'en')).toBe('+50.0% vs the previous period')
    expect(comparisonText({ previous: 4, change_pct: -50, state: 'DOWN' }, 'count', 'en')).toBe('−50.0% vs the previous period')
    expect(comparisonText({ previous: 10, change_pct: 0, state: 'FLAT' }, 'money', 'en')).toBe('0.0% vs the previous period')
    expect(comparisonText({ previous: 85.8, change_points: -2.5, state: 'DOWN' }, 'percent', 'en')).toBe('−2.5 percentage points vs the previous period')
    for (const broken of [{ previous: 0, change_pct: null, state: 'NO_DATA' as const }, { previous: 0, change_pct: Number.POSITIVE_INFINITY, state: 'UP' as const }, { previous: 0, change_pct: Number.NaN, state: 'UP' as const }, { previous: 1, change_pct: null, state: 'UP' as const }, null, undefined]) expect(comparisonText(broken, 'count', 'en')).toBeNull()
  })

  it('treats a bucket as an opaque label in the given order — the repeated hour of a clock change included', () => {
    const hours = ['2026-11-01 00:00', '2026-11-01 01:00', '2026-11-01 01:00 (EST)', '2026-11-01 02:00']
    expect(tickLabels(hours, 'hour')).toEqual(['00:00', '01:00', '01:00 (EST)', '02:00'])
    expect(tickLabels(['2026-10-04 23:00', '2026-10-05 00:00'], 'hour')).toEqual(['10-04 23:00', '10-05 00:00'])
    expect(tickLabels(['2026-10-12', '2026-10-13'], 'day')).toEqual(['10-12', '10-13'])
    expect(tickLabels(['2025-12-29', '2026-01-05'], 'week')).toEqual(['2025-12-29', '2026-01-05'])   // nothing shared: nothing left off
    expect(tickLabels(['2026-09-01', '2026-10-01'], 'month')).toEqual(['2026-09-01', '2026-10-01'])
    expect(tickLabels(['whatever the backend sends', 'next'], 'hour')).toEqual(['whatever the backend sends', 'next'])
  })
})

/* ------------------------------------------------------------------ the page */

function Where() { const l = useLocation(); return <output data-testid="where">{l.pathname}{l.search}</output> }
const withLive = (): DashboardRepositories['management'] => {
  const base = dashboardRepositories.management; const management = Object.create(base) as DashboardRepositories['management']
  management.getLocations = async (): Promise<DashboardLocation[]> => (await base.getLocations()).map((l) => ({ ...l, live: { locationId: l.restaurant.id === 'burger-hub' ? L1 : L2, permissions: permissionsForRole('owner') } as LocationLive }))
  return management
}
const mount = (path = '/restaurant-dashboard/analytics', o: { connected?: boolean; pollMs?: number; live?: boolean } = {}) => {
  const repo = new ApiRestaurantAnalyticsRepository()
  const repos: DashboardRepositories = { ...dashboardRepositories, ...(o.live ? { management: withLive() } : {}), ...(o.connected === false ? {} : { reports: repo }) }
  return render(
    <LocaleProvider><MemoryRouter initialEntries={[path]}><Where /><Routes>
      <Route path="/restaurant-dashboard" element={<DashboardProvider repos={repos}><DashboardLayout /></DashboardProvider>}>
        <Route path="overview" element={<OverviewPage />} />
        <Route path="analytics" element={<RequirePermission perm="analytics.view">{o.pollMs ? <BackendAnalyticsPage repo={repo} pollMs={o.pollMs} /> : <AnalyticsPage />}</RequirePermission>} />
        <Route path="earnings" element={<p data-testid="earnings-page">Earnings page</p>} />
      </Route>
    </Routes></MemoryRouter></LocaleProvider>,
  )
}
const page = () => screen.getByTestId('db-analytics')
const kpi = (key: string) => screen.getByTestId(`ra-kpi-${key}`)
const value = (key: string) => screen.getByTestId(`ra-kpi-${key}-value`)
const openTab = async (user: ReturnType<typeof userEvent.setup>, tab: string, marker: string) => { await user.click(await screen.findByTestId(`ra-tab-${tab}`)); return screen.findByTestId(marker) }
/** What no analytics screen of a restaurant may ever contain. */
const expectClean = (el: HTMLElement) => { expect(el).not.toHaveTextContent(/\blive\b|real[- ]time/i); expect(el).not.toHaveTextContent(/NaN|Infinity|∞|undefined|\bnull\b/) }

describe('Restaurant Dashboard analytics page (Module 38)', () => {
  it('overview: the backend\'s figures in the agreed words, with the period, time zone, currency and freshness under the filters', async () => {
    mount()
    await screen.findByTestId('ra-kpis')
    expect(page()).toHaveAttribute('data-source', 'backend'); expect(page()).toHaveAttribute('data-tab', 'overview')
    expect(last('overview')).toBe(`${A}/overview?preset=last_30_days`); expect(asked('catalog')).toHaveLength(1)
    for (const tab of ['overview', 'orders', 'menu', 'operations', 'routes', 'reviews', 'earnings', 'exports']) expect(screen.getByTestId(`ra-tab-${tab}`)).toHaveAttribute('role', 'tab')

    expect(value('orders_placed')).toHaveTextContent(/^3$/); expect(screen.getByTestId('ra-kpi-orders_placed-comparison')).toHaveTextContent('New — no earlier figure to compare')
    expect(value('completed_order_total')).toHaveTextContent('₹1,500.00'); expect(value('completed_order_total')).toHaveAttribute('data-amount', '150000'); expect(value('completed_order_total')).toHaveAttribute('data-currency', 'INR')
    expect(value('average_order_value')).toHaveTextContent('₹500.00')
    expect(value('restaurant_acceptance_rate')).toHaveTextContent(/^100\.0%$/); expect(screen.getByTestId('ra-kpi-restaurant_acceptance_rate-ratio')).toHaveTextContent('3 of 3')
    expect(value('restaurant_refund_rate')).toHaveTextContent(/^33\.3%$/)
    // No review yet: exactly the agreed sentence, and no comparison where there is nothing to compare.
    expect(value('average_rating')).toHaveTextContent(/^No data for this period\.$/); expect(kpi('average_rating')).toHaveAttribute('data-state', 'no_data')
    expect(value('reviews_published')).toHaveTextContent(/^0$/); expect(screen.queryByTestId('ra-kpi-reviews_published-comparison')).toBeNull()
    expect(value('restaurant_net_earnings')).toHaveTextContent('₹1,350.00')

    expect(screen.getByTestId('ra-period-range')).toHaveTextContent('Sep 14, 2026 – Oct 13, 2026 (30 day(s))'); expect(screen.getByTestId('ra-timezone')).toHaveTextContent('Time zone Asia/Kolkata'); expect(screen.getByTestId('ra-currency')).toHaveTextContent('Currency INR')
    const fresh = screen.getByTestId('ra-freshness')
    expect(fresh).toHaveTextContent('Updated about every 5 minutes · figures as of Oct 13, 2026'); expect(fresh).toHaveAttribute('data-mode', 'AGGREGATED'); expect(fresh).toHaveAttribute('data-stale', 'false'); expect(screen.queryByTestId('ra-stale')).toBeNull()

    expect(screen.getByTestId('ra-sales-note')).toHaveTextContent(SALES_NOTE)
    // Counts start at 0 and count in whole orders; the same values are in a table for a screen reader.
    const chart = screen.getByTestId('ra-orders-chart')
    expect([...chart.querySelectorAll('svg text.db-chart__tick')].map((n) => n.textContent)).toEqual(expect.arrayContaining(['0', '1', '2', '10-11', '10-12', '10-13']))
    expect(chart.querySelector('svg')).toHaveAccessibleName('Orders placed'); expect(within(chart).getByRole('table')).toHaveTextContent('2026-10-13'); expect(within(chart).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Day', 'Orders'])
    expect(chart.querySelector('[aria-label="2026-10-13: 2"]')).not.toBeNull()
    expect(screen.getByTestId('ra-sales-chart').querySelector('svg')).not.toBeNull()
    expect(screen.getByTestId('ra-status-distribution')).toHaveTextContent('Completed')
    expect(screen.queryByTestId('ra-locations')).toBeNull()   // one row would be the same figures twice
    expectClean(page()); expect(screen.queryByTestId('db-demo-banner')).toBeNull()
  }, 30000)

  it('says "restricted", "insufficient", "new" and "may be out of date" in words — and lists currencies instead of adding them', async () => {
    routes[`GET ${A}/overview`] = { body: overview({
      meta: meta({ scope: { locations: [LOCATIONS[0], { id: L2, name: 'Route 5 Diner', currency: 'USD', timezone: 'America/Los_Angeles' }], all_readable: true }, freshness: { mode: 'AGGREGATED', label: 'Updated about every 5 minutes', aggregated_at: '2026-10-12T22:00:00Z', stale: true, generated_at: '2026-10-13T06:31:00Z', cached: true, cache_seconds: 60 }, permissions: { finance: false } }),
      series: { bucket: 'day', timezone: 'Asia/Kolkata', currency: null, money_series_available: false, points: SERIES.points.map((p) => ({ ...p, gmv_minor: null })) },
      customers: { ordering_customers: null, first_time_here: null, returning: null, orders_per_customer: null, suppressed: true, minimum_cohort: 5, statement: STATEMENT },
      locations: [{ restaurant: { id: L1, name: 'Burger Hub' }, currency: 'INR', orders_placed: 2, orders_completed: 2, completed_order_total: inr(100000), average_rating: { key: 'average_rating', label: 'Average rating', unit: 'rating', value: 4.5, numerator: 9, denominator: 2, no_data: false } },
        { restaurant: { id: L2, name: 'Route 5 Diner' }, currency: 'USD', orders_placed: 1, orders_completed: 1, completed_order_total: { amount: 1200, currency: 'USD' }, average_rating: { key: 'average_rating', label: 'Average rating', unit: 'rating', value: null, numerator: 0, denominator: 0, no_data: true } }],
    }, {
      orders_placed: count('orders_placed', 'Orders placed', 12, { previous: 8, change_pct: 50, state: 'UP' }), orders_completed: count('orders_completed', 'Orders completed', Number.NaN as number, { previous: 0, change_pct: null, state: 'UP' }),
      completed_order_total: { key: 'completed_order_total', label: 'Sales of completed orders', unit: 'money', value: null, values: [{ amount: 100000, currency: 'INR', comparison: NEW }, { amount: 1200, currency: 'USD', comparison: { previous: 600, change_pct: 100, state: 'UP' } }], mixed_currencies: true, comparison: null },
      restaurant_acceptance_rate: rate('restaurant_acceptance_rate', 'Acceptance rate', null, 2, 3, { insufficient: true }),
      restaurant_refund_rate: rate('restaurant_refund_rate', 'Refund rate (orders)', 8.3, 1, 12, { comparison: { previous: 10.8, change_points: -2.5, state: 'DOWN' } }),
      restaurant_net_earnings: { key: 'restaurant_net_earnings', label: 'Restaurant net earnings', unit: 'money', freshness: 'LEDGER', value: null, restricted: true, requires: 'restaurant.finance.view' },
    }) }
    mount()
    await screen.findByTestId('ra-kpis')
    expect(value('restaurant_net_earnings')).toHaveTextContent(/^You need earnings access to see this figure$/); expect(kpi('restaurant_net_earnings')).toHaveAttribute('data-state', 'restricted'); expect(kpi('restaurant_net_earnings')).not.toHaveTextContent(/[₹$]/)
    expect(value('restaurant_acceptance_rate')).toHaveTextContent(/^Insufficient data$/); expect(kpi('restaurant_acceptance_rate')).not.toHaveTextContent('%'); expect(screen.queryByTestId('ra-kpi-restaurant_acceptance_rate-ratio')).toBeNull()
    expect(screen.getByTestId('ra-kpi-orders_placed-comparison')).toHaveTextContent('+50.0% vs the previous period')
    expect(screen.getByTestId('ra-kpi-restaurant_refund_rate-comparison')).toHaveTextContent('−2.5 percentage points vs the previous period')
    // A broken number never reaches the screen: the card says there is no data and compares nothing.
    expect(value('orders_completed')).toHaveTextContent('No data for this period.'); expect(screen.queryByTestId('ra-kpi-orders_completed-comparison')).toBeNull()

    const sales = kpi('completed_order_total')
    expect(sales).toHaveAttribute('data-state', 'mixed'); expect(within(sales).getAllByRole('listitem').map((li) => li.getAttribute('data-currency'))).toEqual(['INR', 'USD'])
    expect(sales).toHaveTextContent('₹1,000.00'); expect(sales).toHaveTextContent('$12.00'); expect(sales).toHaveTextContent('never added together'); expect(sales).toHaveTextContent('+100.0% vs the previous period')
    expect(page()).not.toHaveTextContent(/1,012\.00|101,200/)   // no sum of rupees and dollars anywhere
    expect(screen.getByTestId('ra-currency')).toHaveTextContent('Currencies INR, USD — shown separately, never added together')
    expect(screen.getByTestId('ra-sales-chart-unavailable')).toHaveTextContent('Several currencies — no single money line'); expect(screen.queryByTestId('ra-sales-chart')).toBeNull()

    expect(screen.getByTestId('ra-stale')).toHaveTextContent('These figures may be out of date'); expect(screen.getByTestId('ra-freshness')).toHaveAttribute('data-stale', 'true')
    const customers = screen.getByTestId('ra-customers')
    expect(customers).toHaveAttribute('data-suppressed', 'true'); expect(within(customers).getByTestId('ra-customers-suppressed')).toHaveTextContent(/^Too few customers to show a split$/); expect(customers).toHaveTextContent(STATEMENT); expect(customers).not.toHaveTextContent(/\d/)
    const rows = within(screen.getByTestId('ra-locations')).getAllByRole('row').slice(1)
    expect(rows.map((r) => r.textContent)).toEqual(['Burger HubINR22₹1,000.004.50 / 5', 'Route 5 DinerUSD11$12.00No data for this period.'])
    expect(screen.getByTestId('ra-locations')).not.toHaveTextContent(/rank|best|worst|#\d/i)
    expectClean(page())
  }, 30000)

  it('shows counts of customers and nothing that names one', async () => {
    mount()
    const customers = await screen.findByTestId('ra-customers')
    expect(within(customers).getByTestId('ra-customers-ordering')).toHaveTextContent('Customers who completed an order3'); expect(within(customers).getByTestId('ra-customers-first_time')).toHaveTextContent('First time here2')
    expect(within(customers).getByTestId('ra-customers-returning')).toHaveTextContent('Returning1'); expect(within(customers).getByTestId('ra-customers-orders_per_customer')).toHaveTextContent('Orders per customer1.5')
    expect(within(customers).getByTestId('ra-customers-statement')).toHaveTextContent(STATEMENT)
    expect(customers.querySelectorAll('table, ul, ol, a')).toHaveLength(0)   // counts, not a list of people
    expect(page()).not.toHaveTextContent(/@|\+91|\bphone\b|\be-?mail\b/i)
    expect(page().querySelectorAll('iframe, canvas, [class*="map"]')).toHaveLength(0)
  }, 30000)

  it('keeps location and period in the URL and asks the backend again when either changes', async () => {
    const user = userEvent.setup()
    mount()
    await screen.findByTestId('ra-kpis')
    const location = screen.getByTestId('ra-location')
    expect(within(location).getAllByRole('option').map((o) => o.textContent)).toEqual(['All my locations', 'Burger Hub · INR', 'Burger Hub Express · INR'])
    expect(within(screen.getByTestId('ra-period')).getAllByRole('option').map((o) => o.textContent)).toEqual(['Today', 'Yesterday', 'Last 7 days', 'Last 30 days', 'This month', 'Previous month', 'Custom'])
    expect(screen.getByTestId('ra-period')).toHaveValue('last_30_days'); expect(screen.queryByTestId('ra-period-from')).toBeNull()

    routes[`GET ${A}/overview`] = { body: overview({ meta: meta({ scope: { locations: [LOCATIONS[1]], all_readable: false } }) }) }
    await user.selectOptions(location, L2)
    await waitFor(() => expect(last('overview')).toBe(`${A}/overview?location=${L2}&preset=last_30_days`))
    expect(screen.getByTestId('where')).toHaveTextContent(`/restaurant-dashboard/analytics?location=${L2}`)
    await screen.findByTestId('ra-kpis')
    expect(within(screen.getByTestId('ra-location')).getAllByRole('option')).toHaveLength(3)   // the list stays what "all" answered

    await user.selectOptions(screen.getByTestId('ra-period'), 'last_7_days')
    await waitFor(() => expect(last('overview')).toBe(`${A}/overview?location=${L2}&preset=last_7_days`))
    expect(screen.getByTestId('where')).toHaveTextContent('period=last_7_days')
    await screen.findByTestId('ra-kpis')

    // Custom starts from the days that are on screen and is asked as from / to.
    await user.selectOptions(screen.getByTestId('ra-period'), 'custom')
    expect(screen.getByTestId('ra-period-from')).toHaveValue('2026-09-14'); expect(screen.getByTestId('ra-period-to')).toHaveValue('2026-10-13')
    await waitFor(() => expect(last('overview')).toBe(`${A}/overview?location=${L2}&preset=custom&from=2026-09-14&to=2026-10-13`))
    expect(screen.getByTestId('ra-period-hint')).toHaveTextContent('A period covers at most 366 days.')
    fireEvent.change(screen.getByTestId('ra-period-from'), { target: { value: '2026-10-05' } })
    await waitFor(() => expect(last('overview')).toBe(`${A}/overview?location=${L2}&preset=custom&from=2026-10-05&to=2026-10-13`))
    expect(screen.getByTestId('where')).toHaveTextContent('period=custom&from=2026-10-05&to=2026-10-13')

    // A period that is none is said at once, and nothing is asked for.
    const before = asked('overview').length
    fireEvent.change(screen.getByTestId('ra-period-from'), { target: { value: '2026-10-20' } })
    expect(await screen.findByTestId('ra-period-error')).toHaveTextContent('The first day must not be after the last day.')
    fireEvent.change(screen.getByTestId('ra-period-from'), { target: { value: '2025-01-01' } })
    await waitFor(() => expect(screen.getByTestId('ra-period-error')).toHaveTextContent('A period covers at most 366 days.'))
    fireEvent.change(screen.getByTestId('ra-period-from'), { target: { value: '' } })
    expect(await screen.findByTestId('ra-period-incomplete')).toHaveTextContent('Choose a first and a last day'); expect(screen.queryByTestId('ra-period-error')).toBeNull()
    expect(asked('overview')).toHaveLength(before); expect(screen.queryByTestId('ra-kpis')).toBeNull()

    // What the backend refuses (422) is shown next to the dates, in its own words.
    routes[`GET ${A}/overview`] = refused(422, 'validation_failed', 'The period is not valid.', { from: ['This is not a day of the calendar.'] })
    fireEvent.change(screen.getByTestId('ra-period-from'), { target: { value: '2026-10-01' } })
    await waitFor(() => expect(screen.getByTestId('ra-period-error')).toHaveTextContent('The period is not valid. This is not a day of the calendar.'))
    expect(screen.getByTestId('ra-period-from')).toHaveAttribute('aria-invalid', 'true'); expect(screen.queryByTestId('ra-error')).toBeNull()

    // Back to a preset: the dates leave the URL.
    routes[`GET ${A}/overview`] = { body: overview() }
    await user.selectOptions(screen.getByTestId('ra-period'), 'today')
    await waitFor(() => expect(last('overview')).toBe(`${A}/overview?location=${L2}&preset=today`))
    expect(screen.getByTestId('where')).toHaveTextContent(`/restaurant-dashboard/analytics?location=${L2}&period=today`); expect(screen.getByTestId('where')).not.toHaveTextContent('from=')
    await user.selectOptions(screen.getByTestId('ra-location'), 'all')
    await waitFor(() => expect(last('overview')).toBe(`${A}/overview?preset=today`))
  }, 60000)

  it('opens on what the URL says; a person with one location is not asked to choose one; a failure can be tried again', async () => {
    const user = userEvent.setup()
    routes[`GET ${A}/orders`] = { body: { ...ORDERS, meta: meta({ scope: { locations: [LOCATIONS[0]], all_readable: false } }) } }
    mount(`/restaurant-dashboard/analytics?tab=orders&location=${L1}&period=yesterday`)
    await screen.findByTestId('ra-order-counts')
    expect(asked('overview')).toHaveLength(0); expect(last('orders')).toBe(`${A}/orders?location=${L1}&preset=yesterday`)
    expect(page()).toHaveAttribute('data-tab', 'orders'); expect(screen.getByTestId('ra-period')).toHaveValue('yesterday'); expect(screen.getByTestId('ra-location')).toHaveValue(L1)
    cleanup()

    routes[`GET ${A}/overview`] = { body: overview({ meta: meta({ scope: { locations: [LOCATIONS[0]], all_readable: true } }) }) }
    mount('/restaurant-dashboard/analytics?tab=unknown&period=next_year')
    await screen.findByTestId('ra-kpis')
    expect(page()).toHaveAttribute('data-tab', 'overview'); expect(last('overview')).toBe(`${A}/overview?preset=last_30_days`); expect(screen.queryByTestId('ra-location')).toBeNull()
    cleanup()

    routes[`GET ${A}/overview`] = refused(500, 'server_error', 'boom')
    mount()
    const failure = await screen.findByTestId('ra-error')
    expect(failure).toHaveTextContent('Unable to load these figures'); expect(failure).not.toHaveTextContent('boom'); expect(screen.queryByTestId('ra-kpis')).toBeNull()
    routes[`GET ${A}/overview`] = { body: overview() }
    await user.click(within(failure).getByRole('button', { name: /try again/i }))
    expect(await screen.findByTestId('ra-kpis')).toBeInTheDocument()
    cleanup()

    routes[`GET ${A}/overview`] = refused(403, 'forbidden', 'This action is unauthorized.')
    mount()
    expect(await screen.findByTestId('ra-forbidden')).toHaveTextContent('You need analytics access for this location to see these figures.')
    expect(within(screen.getByTestId('ra-forbidden')).queryByRole('button')).toBeNull()
  }, 60000)

  it('every figure can show its definition from the catalog, which is read once', async () => {
    const user = userEvent.setup()
    mount()
    await screen.findByTestId('ra-kpis')
    for (const key of ['orders_placed', 'orders_completed', 'completed_order_total', 'average_order_value', 'restaurant_acceptance_rate', 'restaurant_refund_rate', 'average_rating', 'reviews_published', 'restaurant_net_earnings']) {
      const button = screen.getByTestId(`ra-kpi-${key}-definition`)
      expect(button.tagName).toBe('BUTTON'); expect(button).toHaveAttribute('aria-expanded', 'false'); expect(button).toHaveAccessibleName(/^Definition of /)
    }
    const button = screen.getByTestId('ra-kpi-restaurant_acceptance_rate-definition')
    expect(button).toHaveAccessibleName('Definition of Acceptance rate'); expect(screen.queryByTestId('ra-kpi-restaurant_acceptance_rate-definition-text')).toBeNull()
    await user.click(button)
    const text = screen.getByTestId('ra-kpi-restaurant_acceptance_rate-definition-text')
    expect(button).toHaveAttribute('aria-expanded', 'true'); expect(button).toHaveAttribute('aria-controls', text.id)
    for (const part of ['Acceptance rate', 'Share of answered orders that the restaurant accepted.', 'Source', 'Order domain (orders.placed_at)', 'Counted', 'Orders the restaurant accepted', 'Out of', 'Orders the restaurant accepted or rejected', 'Left out', 'Orders nobody answered yet.']) expect(text).toHaveTextContent(part)
    await user.click(button)
    expect(screen.queryByTestId('ra-kpi-restaurant_acceptance_rate-definition-text')).toBeNull()
    // A figure the catalog does not describe says so instead of inventing a definition.
    await user.click(screen.getByTestId('ra-kpi-average_rating-definition'))
    expect(screen.getByTestId('ra-kpi-average_rating-definition-text')).toHaveTextContent('The definition of this figure is not available right now.')

    await openTab(user, 'orders', 'ra-durations')
    await user.click(screen.getByTestId('ra-kpi-acceptance_time-definition'))
    expect(screen.getByTestId('ra-kpi-acceptance_time-definition-text')).toHaveTextContent('From the order being placed to the restaurant accepting it.')
    await openTab(user, 'menu', 'ra-menu-items'); await openTab(user, 'overview', 'ra-kpis')
    expect(asked('catalog')).toHaveLength(1)
  }, 60000)

  it('orders: counts and rates, the four durations as average / median / 90th percentile, statuses and rejection reasons', async () => {
    const user = userEvent.setup()
    mount()
    await openTab(user, 'orders', 'ra-order-counts')
    expect(last('orders')).toBe(`${A}/orders?preset=last_30_days`); expect(screen.getByTestId('where')).toHaveTextContent('/restaurant-dashboard/analytics?tab=orders')
    expect(value('orders_placed')).toHaveTextContent(/^12$/); expect(screen.getByTestId('ra-kpi-orders_placed-comparison')).toHaveTextContent('+50.0% vs the previous period')
    expect(screen.getByTestId('ra-kpi-orders_completed-comparison')).toHaveTextContent('0.0% vs the previous period'); expect(screen.getByTestId('ra-kpi-orders_rejected-comparison')).toHaveTextContent('−50.0% vs the previous period')
    expect(value('order_completion_rate')).toHaveTextContent(/^83\.3%$/); expect(screen.getByTestId('ra-kpi-order_completion_rate-comparison')).toHaveTextContent('−2.5 percentage points vs the previous period')
    expect(screen.getByTestId('ra-kpi-order_rejection_rate-comparison')).toHaveTextContent('New — no earlier figure to compare')
    // The platform's "GMV" reaches a restaurant under the name of its own figure, with that figure's definition.
    expect(kpi('completed_order_total')).toHaveTextContent('₹5,000.00'); expect(kpi('completed_order_total')).toHaveTextContent('Sales of completed orders'); expect(page()).not.toHaveTextContent(/GMV|merchandise value/i)
    expect(value('median_order_value')).toHaveTextContent('₹450.00')

    const durations = screen.getByTestId('ra-durations')
    expect(within(durations).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Step', 'Average', 'Median', '90th percentile', 'Orders measured'])
    const cells = (key: string) => [...screen.getByTestId(`ra-duration-${key}`).querySelectorAll('td')].map((td) => td.textContent)
    expect(cells('acceptance_time')).toEqual(['4 min 10 s', '3 min', '10 min', '12']); expect(cells('preparation_time')).toEqual(['20 min 10 s', '18 min 20 s', '30 min', '10'])
    expect(cells('pickup_wait_time')).toEqual(['45 s', '40 s', '2 h 5 min', '10']); expect(cells('completion_time')).toEqual(['No data for this period.', '0'])
    expect(screen.getByTestId('ra-duration-acceptance_time')).toHaveTextContent('Time to accept')

    expect(screen.getByTestId('ra-orders-chart').querySelector('svg')).not.toBeNull(); expect(screen.getByTestId('ra-completed-chart').querySelector('svg')).toHaveAccessibleName('Orders completed')
    expect(screen.getByTestId('ra-status-distribution')).toHaveTextContent('Rejected')
    const reasons = within(screen.getByTestId('ra-rejection-reasons')).getAllByRole('row')
    expect(reasons[0]).toHaveTextContent('ReasonCount'); expect(reasons[1]).toHaveTextContent('Kitchen capacity2')
    expectClean(page())
  }, 60000)

  it('menu: what customers looked at and what was sold are two labelled sources — and no item is graded', async () => {
    const user = userEvent.setup()
    mount()
    const table = await openTab(user, 'menu', 'ra-menu-items')
    expect(last('menu')).toBe(`${A}/menu?preset=last_30_days`)
    expect(screen.getByTestId('ra-menu-source-viewed')).toHaveTextContent(MENU.viewed_source); expect(screen.getByTestId('ra-menu-source-sold')).toHaveTextContent(MENU.sold_source)
    expect(screen.getByTestId('ra-menu-advice')).toHaveTextContent(MENU.advice_only)
    expect(within(table).getByTestId('ra-menu-group-behaviour')).toHaveTextContent('From what customers looked at'); expect(within(table).getByTestId('ra-menu-group-behaviour')).toHaveAttribute('colspan', '2')
    expect(within(table).getByTestId('ra-menu-group-orders')).toHaveTextContent('From completed orders'); expect(within(table).getByTestId('ra-menu-group-orders')).toHaveAttribute('colspan', '4')
    expect(within(table).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['From what customers looked at', 'From completed orders', 'Item', 'Views', 'Add-to-cart', 'Units sold', 'Orders', 'Sales', 'Share of sales'])
    const rows = within(table).getAllByTestId('ra-menu-item-row')
    expect([...rows[0].querySelectorAll('th, td')].map((c) => c.textContent)).toEqual(['Classic Burger', '20', '8', '5', '3', '₹1,250.00', '83.3%'])
    expect([...rows[0].querySelectorAll('td')].map((c) => c.getAttribute('data-source'))).toEqual(['behaviour', 'behaviour', 'orders', 'orders', 'orders', 'orders'])
    expect([...rows[1].querySelectorAll('th, td')].map((c) => c.textContent)).toEqual(['French Fries', '5', '1', '1', '1', '₹250.00', '—'])
    // The two groups of figures stand in two cards, each under the backend's own note.
    expect(within(screen.getByTestId('ra-menu-behaviour')).getByTestId('ra-kpi-add_to_cart_rate-value')).toHaveTextContent(/^22\.5%$/); expect(within(screen.getByTestId('ra-menu-sold')).getByTestId('ra-kpi-item_sales-value')).toHaveTextContent('₹1,500.00')
    expect(screen.getByTestId('ra-menu-behaviour').closest('section')).toHaveTextContent(MENU.viewed_source); expect(screen.getByTestId('ra-menu-sold').closest('section')).toHaveTextContent(MENU.sold_source)
    expect(page()).not.toHaveTextContent(/\bbad\b|\bpoor\b|worst|underperform|delete|remove (it|this)|low performer/i)
    expect(page().querySelectorAll('[data-testid="ra-panel-menu"] button:not(.ra-def__btn)')).toHaveLength(0)   // nothing to act on: information only
    expectClean(page())
  }, 60000)

  it('operations: times, rejections, the Monday-first day × hour grid, estimate vs actual — and a note instead of a utilization without a capacity', async () => {
    const user = userEvent.setup()
    mount()
    const grid = await openTab(user, 'operations', 'ra-peak-times')
    expect(last('operations')).toBe(`${A}/operations?preset=last_30_days`)
    expect(screen.getByTestId('ra-operational-note')).toHaveTextContent('They describe the operation, not the food.')
    expect([...screen.getByTestId('ra-duration-acceptance_time').querySelectorAll('td')].map((td) => td.textContent)).toEqual(['4 min 10 s', '3 min', '10 min', '12'])
    expect(value('orders_rejected')).toHaveTextContent(/^2$/); expect(value('order_rejection_rate')).toHaveTextContent(/^16\.7%$/); expect(screen.getByTestId('ra-rejection-reasons')).toHaveTextContent('Kitchen capacity')

    expect(grid).toHaveAttribute('data-week-starts', 'MONDAY')
    const days = [...grid.querySelectorAll('tbody tr')]
    expect(days.map((r) => r.querySelector('th')?.textContent)).toEqual(['MonMonday', 'TueTuesday', 'WedWednesday', 'ThuThursday', 'FriFriday', 'SatSaturday', 'SunSunday'])
    expect(grid.querySelectorAll('thead th')).toHaveLength(25); expect(days[2].querySelectorAll('td')[12]).toHaveTextContent(/^3$/); expect(days[2].querySelectorAll('td')[12]).toHaveAttribute('data-orders', '3')
    expect([...grid.querySelectorAll('td')].filter((td) => td.getAttribute('data-orders') !== '0')).toHaveLength(1)
    expect(grid.closest('section')).toHaveTextContent("in your restaurant's time zone (Asia/Kolkata). Weeks start on Monday.")

    expect(screen.getByTestId('ra-estimated-prep-estimated')).toHaveTextContent('20 min'); expect(screen.getByTestId('ra-estimated-prep-actual')).toHaveTextContent('20 min 10 s'); expect(screen.getByTestId('ra-estimated-prep-within')).toHaveTextContent('2 of 3')
    expect(screen.getByTestId('ra-estimated-prep-note')).toHaveTextContent('It says nothing about the food.')

    const pickup = screen.getByTestId('ra-pickup')
    expect(within(pickup).getByTestId('ra-kpi-pickup_asap_orders-value')).toHaveTextContent(/^4$/); expect(within(pickup).getByTestId('ra-kpi-pickup_scheduled_orders-value')).toHaveTextContent(/^8$/)
    expect(within(pickup).getByTestId('ra-kpi-pickup_lead_time-value')).toHaveTextContent('2 h'); expect(within(pickup).getByTestId('ra-kpi-pickup_slot_full-value')).toHaveTextContent(/^2$/)
    expect(within(pickup).getByTestId('ra-kpi-recommended_slot_acceptance-value')).toHaveTextContent('Insufficient data')
    expect(within(pickup).getByTestId('ra-pickup-modes')).toHaveTextContent('Scheduled82 h'); expect(within(pickup).getByTestId('ra-pickup-methods')).toHaveTextContent('Counter12'); expect(within(pickup).getByTestId('ra-pickup-refusals')).toHaveTextContent('Slot full2')
    const capacity = within(pickup).getByTestId('ra-pickup-capacity')
    expect(capacity).toHaveAttribute('data-configured', 'false'); expect(within(capacity).getByTestId('ra-pickup-capacity-note')).toHaveTextContent(CAPACITY_NOTE)
    expect(screen.queryByTestId('ra-kpi-pickup_capacity_utilization')).toBeNull(); expect(capacity).not.toHaveTextContent('%'); expect(capacity).not.toHaveTextContent('No data for this period.')
    expect(within(pickup).getByTestId('ra-pickup-delivery')).toHaveTextContent('Not offered: there are no delivery figures.')
    expectClean(page())
    cleanup()

    // With a capacity per slot the utilization is the backend's rate, with what it was counted from.
    routes[`GET ${A}/operations`] = { body: operations({ configured: true, slots_with_orders: 6, slots_full: 1, units_used: 9, units_capacity: 24, note: 'Only slots that had at least one order, at restaurants with a configured capacity per slot.' }, rate('pickup_capacity_utilization', 'Capacity used in slots with orders', 37.5, 9, 24)) }
    mount('/restaurant-dashboard/analytics?tab=operations')
    const configured = await screen.findByTestId('ra-pickup-capacity')
    expect(configured).toHaveAttribute('data-configured', 'true'); expect(within(configured).getByTestId('ra-kpi-pickup_capacity_utilization-value')).toHaveTextContent(/^37\.5%$/); expect(configured).toHaveTextContent('9 of 24'); expect(configured).toHaveTextContent('Only slots that had at least one order')
  }, 60000)

  it('routes: counts and detour bands of the restaurant\'s own orders, "too few" where a band is suppressed, the privacy statement — and no map', async () => {
    const user = userEvent.setup()
    mount()
    const bands = await openTab(user, 'routes', 'ra-routes-bands')
    expect(last('routes')).toBe(`${A}/routes?preset=last_30_days`)
    expect(screen.getByTestId('ra-routes-privacy')).toHaveTextContent(PRIVACY)
    expect(value('restaurant_impressions')).toHaveTextContent(/^60$/); expect(value('journey_restaurant_views')).toHaveTextContent(/^20$/); expect(value('journey_orders')).toHaveTextContent(/^9$/); expect(value('average_detour')).toHaveTextContent(/^4 min$/)
    const rows = within(bands).getAllByRole('row').slice(1)
    expect(rows.map((r) => [r.getAttribute('data-suppressed'), r.textContent])).toEqual([['false', '0–5 min76'], ['true', '5–10 minToo few orders to show'], ['false', '10–15 min00'], ['false', '15+ min00']])
    expect(screen.getByTestId('ra-routes-views-note')).toHaveTextContent('impressions are the times you were returned for a journey')
    expect(page().querySelectorAll('iframe, canvas, [class*="map"], img')).toHaveLength(0); expect(page()).not.toHaveTextContent(/origin city|destination city|corridor|latitude|longitude/i)
    expectClean(page())
  }, 60000)

  it('reviews: published, rating, response rate and reports, the stars as counts, and a series', async () => {
    const user = userEvent.setup()
    mount()
    const stars = await openTab(user, 'reviews', 'ra-reviews-distribution')
    expect(last('reviews')).toBe(`${A}/reviews?preset=last_30_days`)
    expect(value('reviews_published')).toHaveTextContent(/^6$/); expect(screen.getByTestId('ra-kpi-reviews_published-comparison')).toHaveTextContent('+100.0% vs the previous period')
    expect(value('average_rating')).toHaveTextContent(/^4\.33 \/ 5$/); expect(value('review_response_rate')).toHaveTextContent(/^50\.0%$/); expect(screen.getByTestId('ra-kpi-review_response_rate-ratio')).toHaveTextContent('3 of 6'); expect(value('reviews_reported')).toHaveTextContent(/^0$/)
    expect(within(stars).getAllByRole('row').slice(1).map((r) => r.textContent)).toEqual(['5 star(s)3', '4 star(s)2', '3 star(s)1', '2 star(s)0', '1 star(s)0'])
    const chart = screen.getByTestId('ra-reviews-chart')
    expect(chart.querySelector('svg')).toHaveAccessibleName('Reviews published'); expect(within(chart).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Day', 'Reviews'])
    expect(screen.getByTestId('ra-reviews-excluded')).toHaveTextContent('waiting for moderation are in no figure'); expect(screen.getByTestId('ra-reviews-alert-note')).toHaveTextContent('Nothing is done to a restaurant because of it.')
    expectClean(page())
    cleanup()

    routes[`GET ${A}/reviews`] = { body: { ...REVIEWS, metrics: { ...REVIEWS.metrics, reviews_published: count('reviews_published', 'Reviews published', 0, NONE), average_rating: { key: 'average_rating', label: 'Average rating', unit: 'rating', value: null, numerator: 0, denominator: 0, no_data: true }, review_response_rate: rate('review_response_rate', 'Restaurant response rate', null, 0, 0) }, distribution: REVIEWS.distribution.map((d) => ({ ...d, reviews: 0 })), series: { ...REVIEWS.series, points: REVIEWS.series.points.map((p) => ({ ...p, reviews_published: 0, average_rating: null })) } } }
    mount('/restaurant-dashboard/analytics?tab=reviews')
    expect(await screen.findByTestId('ra-reviews-distribution-empty')).toHaveTextContent(/^No data for this period\.$/); expect(screen.getByTestId('ra-reviews-chart-empty')).toHaveTextContent(/^No data for this period\.$/)
    expect(value('average_rating')).toHaveTextContent(/^No data for this period\.$/); expect(value('review_response_rate')).toHaveTextContent(/^No data for this period\.$/)
  }, 60000)

  it('earnings: the finance module\'s own figures per currency with their definitions and a link to the Earnings page — or one sentence on a 403', async () => {
    const user = userEvent.setup()
    mount()
    const earnings = await openTab(user, 'earnings', 'ra-earnings')
    expect(last('earnings')).toBe(`${A}/earnings?preset=last_30_days`)
    const rupees = within(earnings).getByTestId('ra-earnings-INR')
    expect(within(rupees).getByTestId('ra-earnings-available-INR')).toHaveTextContent('Available₹1,350.00Earned and ready for the next settlement.'); expect(within(rupees).getByTestId('ra-earnings-available-INR')).toHaveAttribute('data-amount', '135000')
    expect(within(rupees).getByTestId('ra-earnings-balance-INR')).toHaveTextContent('₹1,350.00'); expect(within(rupees).getByTestId('ra-earnings-pending-INR')).toHaveTextContent('₹0.00')
    const periods = within(within(rupees).getByTestId('ra-earnings-periods-INR')).getAllByRole('row')
    expect(periods.map((r) => r.getAttribute('data-period')).filter(Boolean)).toEqual(['today', 'this_week', 'this_month', 'lifetime'])
    expect([...periods[1].querySelectorAll('th, td')].map((c) => c.textContent)).toEqual(['Today', '3', '₹1,500.00', '₹150.00', '₹0.00', '₹1,350.00'])
    expect(within(rupees).getByTestId('ra-earnings-series-INR').querySelector('svg')).not.toBeNull(); expect(rupees).toHaveTextContent(EARNINGS.series_note)
    expect(screen.getByTestId('ra-earnings-definitions')).toHaveTextContent(EARNINGS.definitions.net_earnings)
    expect(screen.getByTestId('ra-earnings-source')).toHaveTextContent(SOURCE)
    expect(screen.getByTestId('ra-freshness')).toHaveTextContent('Read from the financial ledger now · figures as of'); expect(screen.getByTestId('ra-freshness')).toHaveAttribute('data-mode', 'LEDGER')
    expectClean(page())
    const link = screen.getByTestId('ra-earnings-link')
    expect(link).toHaveAttribute('href', '/restaurant-dashboard/earnings'); expect(link).toHaveTextContent('Open the Earnings page')
    await user.click(link)
    expect(await screen.findByTestId('earnings-page')).toBeInTheDocument()
    cleanup()

    routes[`GET ${A}/earnings`] = refused(403, 'forbidden', 'This action is unauthorized.')
    mount('/restaurant-dashboard/analytics?tab=earnings')
    const restricted = await screen.findByTestId('ra-earnings-restricted')
    expect(restricted).toHaveTextContent(/^You need earnings access to see this$/)
    const panel = screen.getByTestId('ra-panel-earnings')
    expect(panel.querySelectorAll('a, button')).toHaveLength(0); expect(panel).not.toHaveTextContent(/[₹$]\s?\d/); expect(screen.queryByTestId('ra-earnings')).toBeNull(); expect(screen.queryByTestId('ra-meta')).toBeNull()
    // The other tabs are not affected by it.
    await user.click(screen.getByTestId('ra-tab-overview'))
    expect(await screen.findByTestId('ra-kpis')).toBeInTheDocument(); expect(screen.queryByTestId('ra-earnings-restricted')).toBeNull(); expect(screen.queryByTestId('ra-forbidden')).toBeNull()
  }, 60000)

  it('exports: asks for the filters\' location and period, follows the export until it is ready, and downloads it', async () => {
    const user = userEvent.setup()
    let reads = 0; const stages: AnalyticsExportDto[] = [anExport(), anExport({ status: 'PROCESSING' }), anExport(READY)]
    let made = false
    routes[`GET ${A}/exports`] = () => ({ body: exportList(made ? [stages[Math.min(reads++, stages.length - 1)]] : []) })
    routes[`POST ${A}/exports`] = () => { made = true; return { status: 202, body: { export: anExport() } } }
    routes[`GET ${A}/exports/${X1}/download`] = { csv: 'Order,Total\n', filename: READY.filename }
    mount(`/restaurant-dashboard/analytics?tab=exports&location=${L1}&period=last_7_days`, { pollMs: 25 })
    const exportsTab = await screen.findByTestId('ra-exports')
    expect(await within(exportsTab).findByText('No exports yet')).toBeInTheDocument()
    expect(within(screen.getByTestId('ra-export-report')).getAllByRole('option').map((o) => [(o as HTMLOptionElement).value, o.textContent])).toEqual([['orders', 'Orders'], ['menu_items', 'Menu items']])
    expect(screen.getByTestId('ra-export-scope')).toHaveTextContent('Last 7 days'); expect(asked('overview')).toHaveLength(0)

    await user.selectOptions(screen.getByTestId('ra-export-report'), 'orders')
    await user.click(screen.getByTestId('ra-export-generate'))
    const row = await screen.findByTestId(`ra-export-row-${X1}`)
    expect(calls.filter((c) => c.method === 'POST').map((c) => [c.path, c.body])).toEqual([[`${A}/exports`, { report: 'orders', location: L1, preset: 'last_7_days' }]])
    expect(screen.getByTestId('ra-export-note')).toHaveTextContent('The Orders export is being prepared.')
    expect(screen.getByTestId(`ra-export-status-${X1}`)).toHaveTextContent('Queued'); expect(screen.queryByTestId(`ra-export-download-${X1}`)).toBeNull(); expect(screen.getByTestId('ra-export-polling')).toBeInTheDocument()
    expect(row).toHaveTextContent('Sep 14, 2026 – Oct 13, 2026'); expect(row).toHaveTextContent('Burger Hub, Burger Hub Express')

    // The list is read again by itself while the export is being prepared — and stops when it is ready.
    await waitFor(() => expect(screen.getByTestId(`ra-export-status-${X1}`)).toHaveAttribute('data-status', 'READY'), { timeout: 5000 })
    expect(screen.getByTestId(`ra-export-status-${X1}`)).toHaveTextContent('Ready'); expect(screen.getByTestId(`ra-export-rows-${X1}`)).toHaveTextContent('1,234'); expect(screen.getByTestId(`ra-export-expires-${X1}`)).toHaveTextContent('Oct 14, 2026')
    expect(screen.queryByTestId('ra-export-polling')).toBeNull()
    const readsWhenReady = asked('exports').length
    expect(readsWhenReady).toBeGreaterThanOrEqual(4)
    await new Promise((r) => setTimeout(r, 150))
    expect(asked('exports')).toHaveLength(readsWhenReady)

    const download = screen.getByTestId(`ra-export-download-${X1}`)
    expect(download).toHaveAccessibleName('Download Orders, Sep 14, 2026 to Oct 13, 2026')
    await user.click(download)
    await waitFor(() => expect(screen.getByTestId('ra-export-file-note')).toHaveTextContent(`Downloaded ${READY.filename}.`))
    expect(calls.filter((c) => c.path.endsWith('/download')).map((c) => [c.path, c.headers.Accept, c.headers.Authorization])).toEqual([[`${A}/exports/${X1}/download`, 'text/csv', 'Bearer staff-token']])
    expect(saved).toEqual([{ name: READY.filename, href: 'blob:analytics-test' }])
    expectClean(exportsTab)
  }, 60000)

  it('exports: shows every status as a word, and a refusal (403 / 409 / 410) next to what was asked', async () => {
    const user = userEvent.setup()
    const X = (n: number) => `0f000000-0000-4000-8000-00000000000${n}`
    routes[`GET ${A}/exports`] = { body: exportList([anExport({ ...READY, id: X(3), download_path: `/api/v1${A}/exports/${X(3)}/download` }), anExport({ id: X(4), status: 'FAILED', failure_code: 'too_many_rows', completed_at: '2026-10-13T06:41:00Z' }), anExport({ id: X(5), status: 'EXPIRED', row_count: 10, expires_at: '2026-10-12T06:41:00Z' }), anExport({ id: X(6), status: 'FAILED', failure_code: 'generation_failed', report: 'menu_items', label: 'Menu items' })]) }
    mount('/restaurant-dashboard/analytics?tab=exports&period=custom&from=2026-10-01', { pollMs: 5000 })
    await screen.findByTestId('ra-export-list')
    expect([3, 4, 5, 6].map((n) => [screen.getByTestId(`ra-export-status-${X(n)}`).getAttribute('data-status'), screen.getByTestId(`ra-export-status-${X(n)}`).textContent])).toEqual([['READY', 'Ready'], ['FAILED', 'Failed'], ['EXPIRED', 'Expired'], ['FAILED', 'Failed']])
    expect(screen.getByTestId(`ra-export-failure-${X(4)}`)).toHaveTextContent('Too many rows — choose a shorter period.'); expect(screen.getByTestId(`ra-export-failure-${X(6)}`)).toHaveTextContent('It could not be prepared. Please ask for it again.')
    expect(screen.getAllByRole('button', { name: /^Download/ })).toHaveLength(1); expect(screen.queryByTestId('ra-export-polling')).toBeNull()

    // An unfinished custom period is not sent.
    await user.click(screen.getByTestId('ra-export-generate'))
    expect(screen.getByTestId('ra-export-note')).toHaveTextContent('Choose a first and a last day'); expect(calls.filter((c) => c.method === 'POST')).toHaveLength(0)
    fireEvent.change(screen.getByTestId('ra-period-to'), { target: { value: '2026-10-07' } })
    await waitFor(() => expect(screen.getByTestId('ra-export-scope')).toHaveTextContent('All my locations · Oct 1, 2026 – Oct 7, 2026'))

    routes[`POST ${A}/exports`] = refused(409, 'too_many_exports', 'Wait for your exports that are being prepared before asking for another.')
    await user.click(screen.getByTestId('ra-export-generate'))
    await waitFor(() => expect(screen.getByTestId('ra-export-note')).toHaveTextContent('Wait for your exports that are being prepared before asking for another.'))
    expect(screen.getByTestId('ra-export-note')).toHaveAttribute('role', 'alert'); expect(calls.filter((c) => c.method === 'POST').at(-1)?.body).toEqual({ report: 'orders', preset: 'custom', from: '2026-10-01', to: '2026-10-07' })
    routes[`POST ${A}/exports`] = refused(403, 'forbidden', 'This action is unauthorized.')
    await user.selectOptions(screen.getByTestId('ra-export-report'), 'menu_items'); await user.click(screen.getByTestId('ra-export-generate'))
    await waitFor(() => expect(screen.getByTestId('ra-export-note')).toHaveTextContent('You need export access to do this.'))
    expect(calls.filter((c) => c.method === 'POST').at(-1)?.body).toMatchObject({ report: 'menu_items' })

    // The file expired between the list and the click: said in the backend's words, and the list is read again.
    routes[`GET ${A}/exports/${X(3)}/download`] = refused(410, 'export_expired', 'This export has expired. Ask for it again.')
    routes[`GET ${A}/exports`] = { body: exportList([anExport({ id: X(3), status: 'EXPIRED', row_count: 1234, expires_at: '2026-10-13T06:41:00Z' })]) }
    await user.click(screen.getByTestId(`ra-export-download-${X(3)}`))
    await waitFor(() => expect(screen.getByTestId('ra-export-file-note')).toHaveTextContent('This export has expired. Ask for it again.'))
    await waitFor(() => expect(screen.getByTestId(`ra-export-status-${X(3)}`)).toHaveTextContent('Expired'))
    expect(screen.queryByTestId(`ra-export-download-${X(3)}`)).toBeNull(); expect(saved).toEqual([])
    routes[`GET ${A}/exports/${X(3)}/download`] = refused(409, 'export_not_ready', 'This export is not ready to download.')
    expect(describeAnalyticsError(await new ApiRestaurantAnalyticsRepository().downloadExport(anExport({ ...READY, id: X(3), download_path: `/api/v1${A}/exports/${X(3)}/download` })).catch((e: unknown) => e))).toMatchObject({ kind: 'conflict', message: 'This export is not ready to download.' })
  }, 60000)

  it('is behind the permissions: a viewer reads the reports without an Exports tab; order staff get neither the entry nor the page', async () => {
    localStorage.setItem('fotg.rd.staff', 'stf-yuki')   // a viewer: analytics.view without analytics.export
    mount('/restaurant-dashboard/analytics?tab=exports')
    await screen.findByTestId('ra-kpis')
    expect(page()).toHaveAttribute('data-tab', 'overview'); expect(screen.queryByTestId('ra-tab-exports')).toBeNull(); expect(screen.queryByTestId('ra-exports')).toBeNull()
    expect(calls.filter((c) => c.path.includes('/exports'))).toEqual([]); expect(screen.getByTestId('ra-tab-earnings')).toBeInTheDocument()
    cleanup()

    localStorage.setItem('fotg.rd.staff', 'stf-mike'); calls = []
    mount()
    expect(await screen.findByText('Permission denied')).toBeInTheDocument()
    expect(screen.getByTestId('db-sidebar').querySelector('[data-nav="analytics"]')).toBeNull(); expect(screen.queryByTestId('db-analytics')).toBeNull()
    expect(calls.filter((c) => c.path.startsWith(A))).toEqual([])
  }, 60000)

  it('without the backend the development sample stays, labelled as a sample, and nothing is asked', async () => {
    mount('/restaurant-dashboard/analytics', { connected: false })
    const sample = await screen.findByTestId('db-analytics')
    expect(sample).not.toHaveAttribute('data-source'); expect(await screen.findByTestId('an-orders')).toBeInTheDocument(); expect(screen.queryByTestId('ra-filters')).toBeNull()
    expect(calls.filter((c) => c.path.startsWith(A))).toEqual([])
  }, 30000)
})

describe('Restaurant Dashboard home on the backend (Module 38)', () => {
  it('shows today\'s real figures of the header\'s location, with definitions, and nothing from the development sample', async () => {
    const user = userEvent.setup()
    routes[`GET ${A}/overview`] = { body: overview({
      meta: meta({ period: { preset: 'today', from: '2026-11-01', to: '2026-11-01', days: 1, timezone: 'America/New_York', bucket: 'hour', starts_at: '2026-11-01T04:00:00Z', ends_before: '2026-11-02T05:00:00Z', previous: { from: '2026-10-31', to: '2026-10-31' } }, timezone: 'America/New_York', scope: { locations: [LOCATIONS[0]], all_readable: false } }),
      // The day the clocks go back has one hour twice: the labels are the backend's, in the backend's order.
      series: { bucket: 'hour', timezone: 'America/New_York', currency: 'INR', money_series_available: true, points: [{ bucket: '2026-11-01 00:00', orders_placed: 1, orders_completed: 0, gmv_minor: 0 }, { bucket: '2026-11-01 01:00', orders_placed: 2, orders_completed: 1, gmv_minor: 50000 }, { bucket: '2026-11-01 01:00 (EST)', orders_placed: 3, orders_completed: 2, gmv_minor: 100000 }, { bucket: '2026-11-01 02:00', orders_placed: 0, orders_completed: 0, gmv_minor: 0 }] },
    }) }
    mount('/restaurant-dashboard/overview', { live: true })
    const home = await screen.findByTestId('db-overview')
    await screen.findByTestId('ra-home-kpis')
    expect(home).toHaveAttribute('data-source', 'backend'); expect(screen.getByTestId('db-location')).toHaveTextContent('Burger Hub')
    expect(last('overview')).toBe(`${A}/overview?location=${L1}&preset=today`); expect(asked('overview')).toHaveLength(1); expect(asked('catalog')).toHaveLength(1)
    expect(screen.getByTestId('ra-home-period')).toHaveTextContent('Today so far · America/New_York')
    expect(screen.getByTestId('ra-home-kpi-orders_placed-value')).toHaveTextContent(/^3$/); expect(screen.getByTestId('ra-home-kpi-completed_order_total-value')).toHaveTextContent('₹1,500.00')
    expect(screen.getByTestId('ra-home-kpi-restaurant_acceptance_rate-value')).toHaveTextContent(/^100\.0%$/); expect(screen.getByTestId('ra-home-kpi-average_rating-value')).toHaveTextContent('No data for this period.')
    // Half a day against the whole of yesterday is no comparison: none is shown on the home.
    expect(screen.queryByTestId('ra-home-kpi-orders_placed-comparison')).toBeNull(); expect(home).not.toHaveTextContent(/previous period|New —/)
    await user.click(screen.getByTestId('ra-home-kpi-orders_placed-definition'))
    expect(screen.getByTestId('ra-home-kpi-orders_placed-definition-text')).toHaveTextContent('Orders created in the period (by the time they were placed).')
    expect(screen.getByTestId('ra-freshness')).toHaveTextContent('Updated about every 5 minutes · figures as of')

    const chart = screen.getByTestId('ra-home-orders-chart')
    expect([...chart.querySelectorAll('tbody th')].map((th) => th.textContent)).toEqual(['2026-11-01 00:00', '2026-11-01 01:00', '2026-11-01 01:00 (EST)', '2026-11-01 02:00'])
    expect([...chart.querySelectorAll('tbody td')].map((td) => td.textContent)).toEqual(['1', '2', '3', '0'])
    expect([...chart.querySelectorAll('svg g[role="img"] text')].map((n) => n.textContent)).toEqual(['00:00', '01:00', '01:00 (EST)', '02:00'])
    expect(within(chart).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Hour', 'Orders'])
    expect(screen.getByTestId('ra-home-status')).toHaveTextContent('Completed')

    // Nothing of the sample is left: no invented revenue by hour, change against yesterday, order list or top items.
    for (const id of ['kpi-orders', 'kpi-preparing', 'ov-revenue', 'ov-revenue-chart', 'ov-recent', 'ov-top', 'ov-unavailable']) expect(screen.queryByTestId(id)).toBeNull()
    expect(home).not.toHaveTextContent(/Development figures/i); expectClean(home)
    expect(screen.getByTestId('ra-home-link-analytics')).toHaveAttribute('href', `/restaurant-dashboard/analytics?location=${L1}`); expect(screen.getByTestId('ra-home-link-orders')).toHaveAttribute('href', '/restaurant-dashboard/orders')
  }, 60000)

  it('needs analytics access for the figures, says so otherwise — and is the development sample without the backend', async () => {
    const user = userEvent.setup()
    localStorage.setItem('fotg.rd.staff', 'stf-mike')   // order staff: no analytics.view
    mount('/restaurant-dashboard/overview')
    expect(await screen.findByTestId('ra-home-restricted')).toHaveTextContent("You need analytics access for this location to see today's figures.")
    expect(screen.queryByTestId('ra-home-kpis')).toBeNull(); expect(calls.filter((c) => c.path.startsWith(A))).toEqual([])
    expect(screen.getByTestId('ra-home-link-orders')).toBeInTheDocument(); expect(screen.queryByTestId('ra-home-link-analytics')).toBeNull(); expect(screen.queryByTestId('ra-home-link-earnings')).toBeNull()
    cleanup(); localStorage.removeItem('fotg.rd.staff')

    routes[`GET ${A}/overview`] = refused(500, 'server_error', 'boom')
    mount('/restaurant-dashboard/overview')
    const failure = await screen.findByTestId('ra-home-error')
    expect(failure).toHaveTextContent('Unable to load these figures'); expect(last('overview')).toBe(`${A}/overview?preset=today`)
    routes[`GET ${A}/overview`] = { body: overview() }
    await user.click(within(failure).getByRole('button', { name: /try again/i }))
    expect(await screen.findByTestId('ra-home-kpis')).toBeInTheDocument()
    cleanup(); calls = []

    mount('/restaurant-dashboard/overview', { connected: false })
    const sample = await screen.findByTestId('db-overview')
    expect(sample).not.toHaveAttribute('data-source'); expect(await screen.findByTestId('kpi-orders')).toBeInTheDocument(); expect(screen.queryByTestId('ra-home-kpis')).toBeNull()
    expect(calls.filter((c) => c.path.startsWith(A))).toEqual([])
  }, 60000)
})

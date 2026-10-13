/**
 * The analytics repository of the Platform Admin (Module 38) on a scripted network. The tests check what is SENT — the
 * market, the period and the restaurant of a report, the closed body of an export request, the route of a download —
 * and how a refusal of the backend is read.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, tokens } from '../../api/client'
import { adminPermissionsFromApi } from '../../auth/staff/staffAuth'
import { adminBackendSections } from '../AdminContext'
import { adminPermissionsForRole } from '../mock/mockAdmin'
import { sample } from '../pages/analytics/samples.fixture'
import { ApiAdminAnalyticsRepository, describeAnalyticsError, exportDownloadPath, isExportPending, periodParams, reportParams, type ExportDto } from './ApiAdminAnalyticsRepository'

const BASE = 'http://127.0.0.1:8001/api/v1'
const ID = '7e000000-0000-4000-8000-000000000001'
const LOCATION = '2a8e8668-184e-42ba-ae54-d37cd82ca78b'
const EXPORT: ExportDto = {
  id: ID, report: 'orders', label: 'Orders', status: 'QUEUED', failure_code: null, period: { from: '2026-10-01', to: '2026-10-07', timezone: 'Asia/Kolkata' }, scope: {}, format: 'CSV',
  row_count: null, size_bytes: null, filename: null, download_path: null, download_count: 0, requested_at: '2026-10-07T06:30:00Z', completed_at: null, expires_at: null,
}
const READY: ExportDto = { ...EXPORT, status: 'READY', row_count: 4, size_bytes: 512, filename: 'orders-india-2026-10-01-2026-10-07.csv', download_path: `/api/v1/admin/analytics/exports/${ID}/download`, completed_at: '2026-10-07T06:31:00Z', expires_at: '2026-10-08T06:31:00Z' }

type Call = { method: string; path: string; body: Record<string, unknown> | null; headers: Record<string, string> }
type Reply = { status?: number; body?: unknown; csv?: string; filename?: string }
let calls: Call[] = []
let routes: Record<string, Reply> = {}
beforeEach(() => {
  calls = []; routes = {}; localStorage.clear(); sessionStorage.clear(); tokens.set('admin', 'admin-token')
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const path = decodeURIComponent(url.replace(BASE, '')); const method = init.method ?? 'GET'
    calls.push({ method, path, body: init.body ? JSON.parse(init.body as string) : null, headers: (init.headers ?? {}) as Record<string, string> })
    const r = routes[`${method} ${path.split('?')[0]}`] ?? (method === 'GET' && path.startsWith('/admin/analytics/') && !path.includes('/exports') ? { body: { meta: {}, metrics: {} } } : undefined)
    if (!r) return new Response(JSON.stringify({ error: { code: 'not_found', message: `No stub for ${method} ${path}`, details: {} } }), { status: 404 })
    if (r.csv !== undefined) return new Response(r.csv, { status: 200, headers: { 'Content-Type': 'text/csv; charset=UTF-8', ...(r.filename ? { 'Content-Disposition': `attachment; filename="${r.filename}"` } : {}) } })
    return new Response(JSON.stringify(r.body ?? {}), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } })
  }))
  vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:analytics-admin-test'), revokeObjectURL: vi.fn() }))
})
afterEach(() => { vi.unstubAllGlobals(); tokens.set('admin', null) })
const last = () => calls[calls.length - 1]
const refusal = (status: number, code: string, message: string, details: Record<string, unknown> = {}): Reply => ({ status, body: { error: { code, message, details, request_id: 'r1' } } })
const apiError = (status: number, code: string, message: string, details: Record<string, unknown> = {}) => new ApiError(status, message, { code, details, errors: (details.fields as Record<string, string[]> | undefined) ?? {} })

describe('ApiAdminAnalyticsRepository (Module 38) — what a report is asked with', () => {
  it('sends the market as a slug (or `all`) and the period as a preset — dates only with the custom period', async () => {
    const repo = new ApiAdminAnalyticsRepository()
    await repo.overview({ market: 'india', preset: 'last_7_days' })
    expect(last()).toMatchObject({ method: 'GET', path: '/admin/analytics/overview?market=india&preset=last_7_days' }); expect(last().headers.Authorization).toBe('Bearer admin-token')
    await repo.overview({ market: 'all', preset: 'previous_month', from: '2026-09-01', to: '2026-09-30' })   // the dates of an earlier custom period never travel with a preset
    expect(last().path).toBe('/admin/analytics/overview?market=all&preset=previous_month')
    await repo.overview({ market: ' india ', preset: 'custom', from: '2026-10-01', to: '2026-10-07' })
    expect(last().path).toBe('/admin/analytics/overview?market=india&preset=custom&from=2026-10-01&to=2026-10-07')
    await repo.overview({})   // no market named = the backend's default market; the default period is said, not assumed
    expect(last().path).toBe('/admin/analytics/overview?preset=last_30_days')
    expect(periodParams({ preset: 'today', from: '2026-10-01', to: '2026-10-02' })).toEqual({ preset: 'today' })
    expect(reportParams({ market: '', preset: 'yesterday', restaurant: LOCATION, platform: 'WEB' })).toEqual({ preset: 'yesterday' })
  })

  it('every report has its own path, and a restaurant or a platform goes only where the endpoint takes it', async () => {
    const repo = new ApiAdminAnalyticsRepository(); const q = { market: 'india', preset: 'last_30_days' as const, restaurant: LOCATION, platform: 'ANDROID' as const }
    const plain = ['overview', 'customers', 'routes', 'payments', 'refunds', 'finance', 'support', 'notifications'] as const
    for (const name of plain) { await repo[name](q); expect(last().path).toBe(`/admin/analytics/${name}?market=india&preset=last_30_days`) }
    const withRestaurant = ['orders', 'restaurants', 'menu', 'pickup', 'reviews'] as const
    for (const name of withRestaurant) { await repo[name](q); expect(last().path).toBe(`/admin/analytics/${name}?market=india&preset=last_30_days&restaurant=${LOCATION}`) }
    await repo.funnel(q); expect(last().path).toBe('/admin/analytics/funnel?market=india&preset=last_30_days&platform=ANDROID')
    await repo.funnel({ ...q, platform: 'all' }); expect(last().path).toBe('/admin/analytics/funnel?market=india&preset=last_30_days')
    // No report is ever asked with a customer, a phone number, an e-mail address or a sort.
    for (const c of calls) expect(c.path).not.toMatch(/customer=|phone|email|sort=|filter\[/)
    expect(calls.every((c) => c.method === 'GET')).toBe(true)
  })

  it('reads the counts of this moment with a market and no period, the definitions, data quality and the system card', async () => {
    routes['GET /admin/analytics/live'] = { body: sample('live') }
    routes['GET /admin/analytics/catalog'] = { body: sample('catalog') }
    routes['GET /admin/analytics/quality'] = { body: { status: 'HEALTHY', checked_at: '2026-10-07T06:00:00Z', checks: [], counts: { healthy: 0, warning: 0, error: 0 } } }
    routes['GET /admin/system/analytics'] = { body: { flags: { production: 'NO' } } }
    const repo = new ApiAdminAnalyticsRepository()
    const live = await repo.live('india'); expect(last().path).toBe('/admin/analytics/live?market=india'); expect(live.meta.freshness.mode).toBe('OPERATIONAL'); expect(live.metrics.live_active_orders.value).toBe(1)
    await repo.live(); expect(last().path).toBe('/admin/analytics/live')
    expect((await repo.catalog()).metrics.find((m) => m.key === 'gmv')?.definition).toMatch(/what customers paid/i); expect(last().path).toBe('/admin/analytics/catalog')
    expect((await repo.quality()).status).toBe('HEALTHY'); expect(last().path).toBe('/admin/analytics/quality')
    expect((await repo.system()).flags.production).toBe('NO'); expect(last().path).toBe('/admin/system/analytics')
  })

  it('passes a report on as it arrived: money in minor units of its own currency, a rate with its numerator and denominator', async () => {
    routes['GET /admin/analytics/overview'] = { body: sample('overview') }
    const d = await new ApiAdminAnalyticsRepository().overview({ market: 'india', preset: 'custom', from: '2026-10-01', to: '2026-10-07' })
    expect(d.meta.period).toMatchObject({ from: '2026-10-01', to: '2026-10-07', timezone: 'Asia/Kolkata', bucket: 'day' }); expect(d.meta.freshness).toMatchObject({ mode: 'AGGREGATED', stale: false })
    expect(d.metrics.gmv).toMatchObject({ unit: 'money', value: { amount: 154500, currency: 'INR' }, mixed_currencies: false })
    expect(d.metrics.refund_rate_orders).toMatchObject({ unit: 'percent', value: 25, numerator: 1, denominator: 4 }); expect(d.metrics.orders_completed.comparison).toMatchObject({ state: 'NEW', change_pct: null })
    expect(d.order_trend.points).toHaveLength(7); expect(d.funnel.stages).toHaveLength(11); expect(d.finance_summary.restricted).toBe(false)
  })
})

describe('ApiAdminAnalyticsRepository (Module 38) — exports', () => {
  it('asks for an export with the report, the market and the period — and nothing else', async () => {
    routes['POST /admin/analytics/exports'] = { status: 202, body: { export: EXPORT } }
    const repo = new ApiAdminAnalyticsRepository()
    const created = await repo.requestExport({ report: 'orders', market: 'india', preset: 'last_7_days', from: '2026-10-01', to: '2026-10-07' })
    expect(last()).toMatchObject({ method: 'POST', path: '/admin/analytics/exports' }); expect(last().body).toEqual({ report: 'orders', market: 'india', preset: 'last_7_days' })
    expect(created).toMatchObject({ id: ID, status: 'QUEUED' }); expect(isExportPending(created)).toBe(true)
    await repo.requestExport({ report: 'financial_summary', market: 'all', preset: 'custom', from: '2026-10-01', to: '2026-10-07' })
    expect(last().body).toEqual({ report: 'financial_summary', market: 'all', preset: 'custom', from: '2026-10-01', to: '2026-10-07' })
    await repo.requestExport({ report: 'restaurant_performance', market: 'india', restaurant: LOCATION, preset: 'this_month' })
    expect(last().body).toEqual({ report: 'restaurant_performance', market: 'india', restaurant: LOCATION, preset: 'this_month' })
    // The backend refuses unknown fields: a format, a file name, a requester or a status is never sent.
    for (const c of calls) for (const forbidden of ['format', 'filename', 'status', 'requested_by', 'admin', 'download_path']) expect(c.body).not.toHaveProperty(forbidden)
  })

  it('lists the caller\'s exports with the reports on offer, and reads one export by its id', async () => {
    routes['GET /admin/analytics/exports'] = { body: { data: [READY, EXPORT], reports: [{ key: 'orders', label: 'Orders', requires: ['admin.analytics.export'] }, { key: 'financial_summary', label: 'Financial summary', requires: ['admin.analytics.export', 'admin.finance.view', 'admin.finance.export'] }], expiry_hours: 24, max_rows: 50000, format: 'CSV' } }
    routes[`GET /admin/analytics/exports/${ID}`] = { body: { export: READY } }
    const repo = new ApiAdminAnalyticsRepository()
    const list = await repo.exports(); expect(last().path).toBe('/admin/analytics/exports')
    expect(list.data.map((e) => e.status)).toEqual(['READY', 'QUEUED']); expect(list.reports[1].requires).toContain('admin.finance.export'); expect(list).toMatchObject({ expiry_hours: 24, max_rows: 50000, format: 'CSV' })
    expect((await repo.export(ID)).status).toBe('READY'); expect(last().path).toBe(`/admin/analytics/exports/${ID}`); expect(isExportPending(READY)).toBe(false)
  })

  it('downloads a ready export from its own route with the administrator\'s token — the /api/v1 prefix of `download_path` is not doubled', async () => {
    routes[`GET /admin/analytics/exports/${ID}/download`] = { csv: 'order_number,status\nFOTG-1,COMPLETED\n', filename: 'orders-india-2026-10-01-2026-10-07.csv' }
    const repo = new ApiAdminAnalyticsRepository()
    expect(await repo.download(READY)).toBe('orders-india-2026-10-01-2026-10-07.csv')
    expect(last()).toMatchObject({ method: 'GET', path: `/admin/analytics/exports/${ID}/download` }); expect(last().headers.Authorization).toBe('Bearer admin-token'); expect(last().headers.Accept).toBe('text/csv')
    expect(vi.mocked(fetch).mock.calls[0][0]).toBe(`${BASE}/admin/analytics/exports/${ID}/download`)
    // The answer of a proxy that hides the file name: the export's own name is used.
    routes[`GET /admin/analytics/exports/${ID}/download`] = { csv: 'a,b\n' }
    expect(await repo.download(READY)).toBe('orders-india-2026-10-01-2026-10-07.csv')
    expect(await repo.download({ ...READY, filename: null })).toBe(`analytics-export-${ID}.csv`)
  })

  it('never follows a download path that is not the export\'s own route', () => {
    const own = `/admin/analytics/exports/${ID}/download`
    expect(exportDownloadPath(READY)).toBe(own)
    expect(exportDownloadPath({ id: ID, download_path: own })).toBe(own)
    expect(exportDownloadPath({ id: ID, download_path: null })).toBe(own)
    for (const hostile of ['https://evil.example/steal', '//evil.example/x', '/api/v1/admin/finance/ledger', `/api/v1/admin/analytics/exports/${ID}/download?next=https://evil.example`, '/api/v1/admin/analytics/exports/another-id/download']) expect(exportDownloadPath({ id: ID, download_path: hostile })).toBe(own)
  })

  it('a refusal reaches the caller as the backend\'s own error', async () => {
    routes['POST /admin/analytics/exports'] = refusal(409, 'too_many_exports', 'Wait for your exports that are being prepared before asking for another.', { pending: 5 })
    const e = await new ApiAdminAnalyticsRepository().requestExport({ report: 'orders', market: 'india', preset: 'today' }).catch((x: unknown) => x)
    expect(e).toBeInstanceOf(ApiError); expect(describeAnalyticsError(e)).toMatchObject({ kind: 'conflict', code: 'too_many_exports', message: expect.stringMatching(/Wait for your exports/) })
    routes[`GET /admin/analytics/exports/${ID}/download`] = refusal(409, 'export_not_ready', 'This export is not ready yet.')
    expect(describeAnalyticsError(await new ApiAdminAnalyticsRepository().download(READY).catch((x: unknown) => x))).toMatchObject({ kind: 'conflict', code: 'export_not_ready' })
    routes['GET /admin/analytics/finance'] = refusal(403, 'forbidden', 'This action is unauthorized.')
    expect(describeAnalyticsError(await new ApiAdminAnalyticsRepository().finance({ market: 'india' }).catch((x: unknown) => x))).toMatchObject({ kind: 'forbidden' })
  })
})

describe('describeAnalyticsError (Module 38)', () => {
  it('reads every refusal of the analytics API: which kind it is and what to say', () => {
    expect(describeAnalyticsError(apiError(403, 'forbidden', 'This action is unauthorized.'))).toMatchObject({ kind: 'forbidden', code: 'forbidden' })
    expect(describeAnalyticsError(apiError(404, 'market_not_found', 'This market does not exist.'))).toMatchObject({ kind: 'not_found', code: 'market_not_found', message: 'This market does not exist.' })
    expect(describeAnalyticsError(apiError(404, 'restaurant_not_found', 'This restaurant does not exist.'))).toMatchObject({ kind: 'not_found', message: 'This restaurant does not exist.' })
    expect(describeAnalyticsError(apiError(404, 'export_not_found', 'This export does not exist.'))).toMatchObject({ kind: 'not_found', code: 'export_not_found' })
    expect(describeAnalyticsError(apiError(409, 'export_not_ready', 'This export is not ready yet.'))).toMatchObject({ kind: 'conflict', message: 'This export is not ready yet.' })
    expect(describeAnalyticsError(apiError(410, 'export_expired', 'This export has expired. Ask for it again.'))).toMatchObject({ kind: 'expired', message: 'This export has expired. Ask for it again.' })
    // A period that is too long: the sentence about the limit, not the field's fragment.
    expect(describeAnalyticsError(apiError(422, 'range_too_long', 'A period covers at most 366 days.', { fields: { from: ['At most 366 days before "to".'] }, max_days: 366 }))).toMatchObject({ kind: 'validation', code: 'range_too_long', message: 'A period covers at most 366 days.' })
    expect(describeAnalyticsError(apiError(422, 'validation_failed', 'The given data was invalid.', { fields: { from: ['The period must run from an earlier day to a later one.'] } }))).toMatchObject({ kind: 'validation', message: 'The period must run from an earlier day to a later one.' })
    expect(describeAnalyticsError(new ApiError(429, 'Too many attempts.', { code: 'rate_limited', retryAfterSeconds: 30 }))).toMatchObject({ kind: 'rate_limited', retryAfterSeconds: 30 })
    expect(describeAnalyticsError(apiError(500, 'server_error', 'boom'))).toMatchObject({ kind: 'unavailable' }); expect(describeAnalyticsError(apiError(500, 'server_error', 'boom')).message).not.toMatch(/boom/)
    expect(describeAnalyticsError(new ApiError(0, 'offline'))).toMatchObject({ kind: 'unavailable' }); expect(describeAnalyticsError(new Error('x'))).toMatchObject({ kind: 'unavailable', code: 'unknown' })
  })
})

describe('analytics wiring (Module 38)', () => {
  it('the analytics section is on the backend exactly when the order domain is — no mode of its own', () => {
    expect(adminBackendSections()).toEqual([])   // unit tests: every mode is mock, so the pinned Module 18 page stays
    for (const k of ['market', 'restaurant', 'cart', 'pickup', 'checkout', 'payment']) localStorage.setItem(`fotg.${k}.mode`, 'api')
    expect(adminBackendSections()).not.toContain('analytics')
    localStorage.setItem('fotg.order.mode', 'api')
    expect(adminBackendSections()).toEqual(expect.arrayContaining(['orders', 'support', 'analytics']))
  })

  it('maps admin.analytics.export, and the development roles hold it where the backend\'s roles do', () => {
    expect(adminPermissionsFromApi(['admin.analytics.view', 'admin.analytics.export', 'admin.analytics.unknown', 'restaurant.analytics.export']).sort()).toEqual(['analytics.export', 'analytics.view'])
    for (const role of ['super_admin', 'operations_admin', 'finance_admin', 'analyst'] as const) expect(adminPermissionsForRole(role)).toEqual(expect.arrayContaining(['analytics.view', 'analytics.export']))
    // Onboarding reads analytics and exports nothing; support and moderation hold neither.
    expect(adminPermissionsForRole('restaurant_onboarding')).toContain('analytics.view'); expect(adminPermissionsForRole('restaurant_onboarding')).not.toContain('analytics.export')
    for (const role of ['support_admin', 'moderation_admin'] as const) expect(adminPermissionsForRole(role).filter((p) => p.startsWith('analytics.'))).toEqual([])
  })
})

/**
 * Module 22 — market data from the backend. The network is stubbed with the payloads the real API returns
 * (backend/openapi/openapi.json: MarketList, Coverage, Availability, AdminMap …).
 */
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiAdminMarketControlRepository } from '../../admin/api/ApiAdminMarketControlRepository'
import { ApiError, tokens } from '../../api/client'
import { setFixtureScope } from '../fixtureScope'
import { MarketGate } from '../MarketGate'
import { marketMode } from '../marketMode'
import { marketAvailability, marketLocationRepository, marketRepository } from '../mock/mockMarket'
import { apiMarketData, checkLocationRemote, hydrateMarket, inMultiPolygon, loadAdminMarketData, locationAvailability, resetMarketData } from './marketData'

const IN = { id: 'm-in', slug: 'india', country_code: 'IN', name: 'India', status: 'ACTIVE', default_currency: 'INR', supported_currencies: ['INR'], default_locale: 'en-IN', supported_locales: ['en-IN'], timezone_strategy: 'single', default_timezone: 'Asia/Kolkata', distance_unit: 'metric', phone_country_code: '+91', features: { reviews: true, cash_at_pickup: false }, launched_at: null }
const square = (w: number, s: number, e: number, n: number) => ({ type: 'MultiPolygon', coordinates: [[[[w, s], [e, s], [e, n], [w, n], [w, s]]]] })
const COVERAGE = {
  market_id: 'm-in', country_code: 'IN', generated_at: '2026-09-30T10:00:00+00:00',
  regions: [{ id: 'r-up', code: 'IN-UP', name: 'Uttar Pradesh', type: 'STATE', status: 'ACTIVE' }, { id: 'r-dl', code: 'IN-DL', name: 'Delhi', type: 'UNION_TERRITORY', status: 'PAUSED' }],
  cities: [
    { id: 'c-noida', region_id: 'r-up', name: 'Noida', slug: 'noida', aliases: [], latitude: 28.5355, longitude: 77.391, timezone: 'Asia/Kolkata', status: 'ACTIVE' },
    { id: 'c-agra', region_id: 'r-up', name: 'Agra', slug: 'agra', aliases: [], latitude: 27.17, longitude: 78.0, timezone: 'Asia/Kolkata', status: 'PLANNED' },
    { id: 'c-delhi', region_id: 'r-dl', name: 'Delhi', slug: 'delhi', aliases: ['New Delhi'], latitude: 28.6139, longitude: 77.209, timezone: 'Asia/Kolkata', status: 'ACTIVE' },
  ],
  service_areas: [
    { id: 'a-live', city_id: 'c-noida', name: 'Noida Central', slug: 'noida-central', status: 'ACTIVE', geometry: square(77.30, 28.50, 77.40, 28.60) },
    { id: 'a-paused', city_id: 'c-noida', name: 'Noida East', slug: 'noida-east', status: 'PAUSED', geometry: square(77.50, 28.50, 77.60, 28.60) },
    { id: 'a-delhi', city_id: 'c-delhi', name: 'Delhi Central', slug: 'delhi-central', status: 'ACTIVE', geometry: square(77.15, 28.55, 77.25, 28.65) },
  ],
  route_corridors: [{ id: 'rc-1', name: 'Delhi → Noida', slug: 'delhi-noida', highway: 'NH24', status: 'ACTIVE', origin_city_id: 'c-delhi', destination_city_id: 'c-noida', via_city_ids: [], corridor_width_meters: 5000, length_meters: 20000, geometry: { type: 'LineString', coordinates: [[77.209, 28.6139], [77.391, 28.5355]] } }],
}

type Call = { method: string; path: string; body: Record<string, unknown> | null; auth: string | null }
let calls: Call[] = []
let routes: Record<string, (call: Call) => { status?: number; body: unknown }> = {}
const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })

beforeEach(() => {
  localStorage.clear(); sessionStorage.clear(); resetMarketData(); setFixtureScope('india'); calls = []
  localStorage.setItem('fotg.market.mode', 'api')
  routes = { 'GET /markets': () => ({ body: { data: [IN] } }), 'GET /markets/current/coverage': () => ({ body: COVERAGE }) }
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const path = new URL(url).pathname.replace(/^\/api\/v1/, ''); const method = init.method ?? 'GET'
    const call: Call = { method, path, body: init.body ? JSON.parse(String(init.body)) : null, auth: (init.headers as Record<string, string>).Authorization ?? null }
    calls.push(call)
    const handler = routes[`${method} ${path}`]
    if (!handler) return json(404, { error: { code: 'not_found', message: 'Not found' } })
    const { status = 200, body } = handler(call)
    return json(status, body)
  }))
})
afterEach(() => { vi.unstubAllGlobals(); window.history.pushState({}, '', '/'); localStorage.clear(); sessionStorage.clear(); resetMarketData(); setFixtureScope(null) })

describe('market mode', () => {
  it('unit tests and share builds use the fixtures unless a browser opts in', () => {
    localStorage.removeItem('fotg.market.mode'); expect(marketMode()).toBe('mock'); expect(apiMarketData()).toBeNull()
    expect(marketRepository.getActiveMarket().countryCode).toBe('IN'); expect(marketLocationRepository.getCities('IN').length).toBe(23)
    localStorage.setItem('fotg.market.mode', 'api'); expect(marketMode()).toBe('api')
  })
})

describe('public snapshot', () => {
  it('hydrates from GET /markets and GET /markets/current/coverage without a token and maps onto the domain types', async () => {
    tokens.set('customer', 'customer-token')
    expect(await hydrateMarket()).toBe(true)
    expect(calls.map((c) => `${c.method} ${c.path}`).sort()).toEqual(['GET /markets', 'GET /markets/current/coverage'])
    expect(calls.every((c) => c.auth === null)).toBe(true)

    const m = marketRepository.getActiveMarket()
    expect(m).toMatchObject({ id: 'm-in', countryCode: 'IN', displayName: 'India', defaultCurrency: 'INR', defaultLocale: 'en-IN', defaultTimezone: 'Asia/Kolkata', distanceUnit: 'metric', phoneCountryCode: '+91' })
    expect(marketRepository.getMarkets().map((x) => x.countryCode)).toEqual(['IN'])   // drafts are never sent to customers
    expect(marketLocationRepository.getStates('IN').map((r) => [r.code, r.kind, r.status])).toEqual([['IN-UP', 'state', 'AVAILABLE'], ['IN-DL', 'union_territory', 'PAUSED']])
    expect(marketLocationRepository.getCities('IN').find((c) => c.id === 'c-noida')).toMatchObject({ regionId: 'r-up', lat: 28.5355, lng: 77.391, timezone: 'Asia/Kolkata', status: 'ACTIVE' })
    expect(marketLocationRepository.getServiceAreas('IN')[0].geometry.type).toBe('multipolygon')
    expect(marketLocationRepository.getRouteCorridors('IN')[0]).toMatchObject({ originCityId: 'c-delhi', destinationCityId: 'c-noida', corridorWidthM: 5000, highway: 'NH24' })
    expect(marketRepository.getMarketConfiguration('IN')!.features).toEqual([{ key: 'reviews', enabled: true, locked: false }, { key: 'cash_at_pickup', enabled: false, locked: false }])
    tokens.set('customer', null)
  })

  it('answers coverage questions from the backend polygons and the status hierarchy', async () => {
    await hydrateMarket()
    expect(marketAvailability.checkLocation({ countryCode: 'IN', lat: 28.55, lng: 77.35 })).toMatchObject({ supported: true, cityId: 'c-noida', serviceAreaId: 'a-live' })
    expect(marketAvailability.checkLocation({ countryCode: 'IN', lat: 28.55, lng: 77.55 })).toMatchObject({ supported: false, reason: 'paused', serviceAreaId: 'a-paused' })
    expect(marketAvailability.checkLocation({ countryCode: 'IN', lat: 28.60, lng: 77.20 })).toMatchObject({ supported: false, reason: 'paused' })   // active area, paused region
    expect(marketAvailability.checkLocation({ countryCode: 'IN', lat: 27.0, lng: 78.0 })).toMatchObject({ supported: false, reason: 'area' })
    expect(marketAvailability.checkLocation({ countryCode: 'US', lat: 40.7, lng: -74.0 })).toMatchObject({ supported: false, reason: 'market' })
    expect(marketAvailability.isCitySupported('c-noida')).toBe(true); expect(marketAvailability.isCitySupported('c-agra')).toBe(false); expect(marketAvailability.isCitySupported('c-delhi')).toBe(false)
    expect(marketAvailability.isServiceAreaSupported('a-live')).toBe(true); expect(marketAvailability.isServiceAreaSupported('a-delhi')).toBe(false)
    expect(marketLocationRepository.placementFor({ id: 'x', countryCode: 'IN', lat: 28.55, lng: 77.35, address: {} })).toEqual({ marketCode: 'IN', regionId: 'r-up', cityId: 'c-noida', serviceAreaId: 'a-live' })
  })

  it('is read-only: nothing in the browser can change backend market data', async () => {
    await hydrateMarket()
    localStorage.setItem('fotg.mkt.overrides.v1', JSON.stringify({ areas: { 'a-paused': 'ACTIVE' }, regions: { 'r-dl': 'AVAILABLE' }, markets: { IN: 'PAUSED' } }))
    expect(marketLocationRepository.getServiceAreas('IN').find((a) => a.id === 'a-paused')!.status).toBe('PAUSED')
    expect(marketRepository.getActiveMarket().status).toBe('ACTIVE')
    expect(() => marketLocationRepository.setServiceAreaStatus('a-paused', 'ACTIVE')).toThrow('market_read_only')
    expect(() => marketRepository.setMarketStatus('IN', 'PAUSED')).toThrow('market_read_only')
    expect(() => marketRepository.setFeature('IN', 'reviews', false)).toThrow('market_read_only')
  })

  it('keeps the last snapshot when the backend is unreachable, and reports failure when there is none', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => { throw new TypeError('network') }))
    expect(await hydrateMarket()).toBe(false); expect(apiMarketData()).toBeNull()

    vi.unstubAllGlobals(); vi.stubGlobal('fetch', vi.fn(async (url: string) => json(200, url.endsWith('/markets') ? { data: [IN] } : COVERAGE)))
    expect(await hydrateMarket()).toBe(true)
    expect(JSON.parse(sessionStorage.getItem('fotg.mkt.snapshot.v1')!).activeCode).toBe('IN')

    vi.stubGlobal('fetch', vi.fn(async () => { throw new TypeError('network') }))
    expect(await hydrateMarket()).toBe(true); expect(marketRepository.getActiveMarket().id).toBe('m-in')
  })

  it('does not treat a market that is not served as available', async () => {
    routes['GET /markets/current/coverage'] = () => ({ status: 404, body: { error: { code: 'market_unavailable', message: 'FoodOnTheGo is not available in this country yet.' } } })
    routes['GET /markets'] = () => ({ body: { data: [] } })
    expect(await hydrateMarket()).toBe(true)   // an answer, not an outage
    expect(marketAvailability.isCountrySupported('IN')).toBe(false); expect(marketLocationRepository.getCities('IN')).toEqual([])
  })

  it('a market paused after the snapshot was taken stops being offered on the next refresh', async () => {
    await hydrateMarket(); expect(marketAvailability.isCountrySupported('IN')).toBe(true)
    routes['GET /markets/current/coverage'] = () => ({ status: 404, body: { error: { code: 'market_unavailable', message: 'FoodOnTheGo is not available in this country yet.' } } })
    routes['GET /markets'] = () => ({ body: { data: [] } })
    expect(await hydrateMarket()).toBe(true)
    expect(marketRepository.getActiveMarket()).toMatchObject({ countryCode: 'IN', status: 'PAUSED', defaultCurrency: 'INR' })
    expect(marketAvailability.isCountrySupported('IN')).toBe(false)
    expect(marketAvailability.checkLocation({ countryCode: 'IN', lat: 28.55, lng: 77.35 })).toMatchObject({ supported: false, reason: 'market' })
  })
})

describe('point in polygon', () => {
  it('handles several parts and holes', () => {
    const withHole = [[[[0, 0], [10, 0], [10, 10], [0, 10], [0, 0]], [[4, 4], [6, 4], [6, 6], [4, 6], [4, 4]]], [[[20, 20], [21, 20], [21, 21], [20, 21], [20, 20]]]]
    expect(inMultiPolygon(withHole, 2, 2)).toBe(true); expect(inMultiPolygon(withHole, 5, 5)).toBe(false)
    expect(inMultiPolygon(withHole, 20.5, 20.5)).toBe(true); expect(inMultiPolygon(withHole, 15, 15)).toBe(false); expect(inMultiPolygon([], 1, 1)).toBe(false)
  })
})

describe('authoritative availability', () => {
  it('asks the backend and maps its machine-readable reasons', async () => {
    const answer = (reason: string | null, supported = false) => ({ supported, reason, market: supported || reason !== 'MARKET_UNSUPPORTED' ? { id: 'm-in', country_code: 'IN', name: 'India', status: 'ACTIVE' } : null, region: null, city: supported ? { id: 'c-noida', name: 'Noida', slug: 'noida', timezone: 'Asia/Kolkata', status: 'ACTIVE' } : null, service_area: supported ? { id: 'a-live', name: 'Noida Central' } : null, route_corridors: [], checked_at: '2026-09-30T10:00:00+00:00' })
    let next: unknown = answer(null, true)
    routes['POST /availability/location'] = () => ({ body: next })

    expect(await checkLocationRemote({ lat: 28.55, lng: 77.35, countryCode: 'in' })).toMatchObject({ supported: true, reason: 'ok', marketCode: 'IN', cityId: 'c-noida', serviceAreaId: 'a-live', messageKey: '' })
    expect(calls.at(-1)).toMatchObject({ method: 'POST', body: { lat: 28.55, lng: 77.35, country_code: 'IN' }, auth: null })

    for (const [reason, mapped, key] of [['MARKET_UNSUPPORTED', 'market', 'market.unavailable.market'], ['MARKET_PAUSED', 'market', 'market.unavailable.market'], ['REGION_UNAVAILABLE', 'area', 'market.unavailable.area'], ['CITY_UNAVAILABLE', 'area', 'market.unavailable.area'], ['OUTSIDE_SERVICE_AREA', 'area', 'market.unavailable.area'], ['SERVICE_AREA_PAUSED', 'paused', 'market.unavailable.paused']] as const) {
      next = answer(reason)
      expect(await checkLocationRemote({ lat: 1, lng: 2 })).toMatchObject({ supported: false, reason: mapped, messageKey: key, backendReason: reason })
    }
    expect(calls.at(-1)!.body).toEqual({ lat: 1, lng: 2 })

    routes['POST /availability/location'] = () => ({ status: 429, body: { error: { code: 'rate_limited', message: 'Too many requests. Please try again shortly.' } } })
    await expect(checkLocationRemote({ lat: 1, lng: 2 })).rejects.toMatchObject({ kind: 'rate_limited' })
  })
})

describe('location availability used by discovery', () => {
  const local = () => ({ supported: true, reason: 'ok' as const, marketCode: 'IN', cityId: 'snapshot', serviceAreaId: 'snapshot', messageKey: '' })

  it('is the backend answer in API mode, and the snapshot only when the request fails or there are no coordinates', async () => {
    routes['POST /availability/location'] = () => ({ body: { supported: false, reason: 'OUTSIDE_SERVICE_AREA', market: { id: 'm-in', country_code: 'IN', name: 'India', status: 'ACTIVE' }, region: null, city: null, service_area: null, route_corridors: [], checked_at: '' } })
    expect(await locationAvailability({ countryCode: 'IN', lat: 28.9, lng: 77.6 }, local)).toEqual({ supported: false, reason: 'area', marketCode: 'IN', cityId: null, serviceAreaId: null, messageKey: 'market.unavailable.area' })

    expect((await locationAvailability({ countryCode: 'IN', lat: null, lng: null }, local)).cityId).toBe('snapshot')
    expect(calls.filter((c) => c.path === '/availability/location').length).toBe(1)

    delete routes['POST /availability/location']
    vi.stubGlobal('fetch', vi.fn(async () => { throw new TypeError('network') }))
    expect((await locationAvailability({ countryCode: 'IN', lat: 28.9, lng: 77.6 }, local)).cityId).toBe('snapshot')
  })

  it('never calls the backend in mock mode', async () => {
    localStorage.setItem('fotg.market.mode', 'mock')
    expect((await locationAvailability({ countryCode: 'IN', lat: 28.9, lng: 77.6 }, local)).cityId).toBe('snapshot'); expect(calls).toEqual([])
  })
})

describe('admin control center against the API', () => {
  const ADMIN_IN = { ...IN, serving_customers: true, version: 3, updated_at: '2026-09-30T09:00:00+00:00' }
  const ADMIN_US = { ...IN, id: 'm-us', slug: 'united-states', country_code: 'US', name: 'United States', status: 'DRAFT', serving_customers: false, version: 1, updated_at: null, features: {} }
  const MAP = {
    regions: COVERAGE.regions.map((r) => ({ ...r, version: 2 })),
    cities: COVERAGE.cities.map((c) => ({ ...c, launch_stage: 'Launched', launched_at: '2026-09-01T00:00:00+00:00', version: 4 })),
    service_areas: [...COVERAGE.service_areas.map((a) => ({ ...a, launch_stage: null, version: 5 })), { id: 'a-test', city_id: 'c-noida', name: 'Noida Trial', slug: 'noida-trial', status: 'TESTING', geometry: square(77.30, 28.50, 77.40, 28.60), launch_stage: 'Internal trial', version: 1 }],
    route_corridors: COVERAGE.route_corridors.map((r) => ({ ...r, version: 6 })),
  }
  beforeEach(() => {
    tokens.set('admin', 'admin-token')
    routes['GET /admin/markets'] = () => ({ body: { data: [ADMIN_IN, ADMIN_US] } })
    routes['GET /admin/markets/m-in/map'] = () => ({ body: MAP })
    routes['GET /admin/markets/m-us/map'] = () => ({ body: { regions: [], cities: [], service_areas: [], route_corridors: [] } })
    routes['GET /admin/markets/m-in/configuration'] = () => ({ body: { payment: { methods: { upi: 'PLANNED', cash_at_pickup: 'NOT_APPROVED' } }, tax: { regime: 'GST', status: 'PENDING' }, legal: { documents: { terms: 'DRAFT_PENDING_APPROVAL' } }, address: { postal_code_label: 'PIN code', admin_area_label: 'State / UT' }, ordering: {}, locked_features: ['cash_at_pickup'], version: 1 } })
    routes['GET /admin/markets/m-us/configuration'] = () => ({ status: 403, body: { error: { code: 'forbidden', message: 'You are not allowed to do this.' } } })
  })
  afterEach(() => tokens.set('admin', null))

  it('loads every status with the admin token, and only an /admin page reads that data', async () => {
    await hydrateMarket(); await loadAdminMarketData(['IN', 'US'])
    expect(calls.filter((c) => c.path.startsWith('/admin')).every((c) => c.auth === 'Bearer admin-token')).toBe(true)

    // A customer page keeps the public snapshot: no draft market, no internal TESTING area.
    expect(marketRepository.getMarkets().map((m) => m.countryCode)).toEqual(['IN'])
    expect(marketLocationRepository.getServiceAreas('IN').map((a) => a.id)).not.toContain('a-test')

    window.history.pushState({}, '', '/admin/markets/india')
    expect(marketRepository.getMarkets().map((m) => [m.countryCode, m.status])).toEqual([['IN', 'ACTIVE'], ['US', 'DRAFT']])
    expect(marketLocationRepository.getServiceAreas('IN').find((a) => a.id === 'a-test')).toMatchObject({ status: 'TESTING', launchStage: 'Internal trial' })
    expect(marketAvailability.isServiceAreaSupported('a-test')).toBe(false)   // TESTING never serves customers
    expect(marketLocationRepository.getCities('IN')[0]).toMatchObject({ launchStage: 'Launched', launchDate: '2026-09-01' })
    const cfg = marketRepository.getMarketConfiguration('IN')!
    expect(cfg.features.find((f) => f.key === 'cash_at_pickup')).toEqual({ key: 'cash_at_pickup', enabled: false, locked: true })
    expect(cfg.payment.methods).toContainEqual({ method: 'cash_at_pickup', status: 'NOT_APPROVED' }); expect(cfg.address.postalCodeLabel).toBe('PIN code')
  })

  it('builds the control-center snapshot from backend geography', async () => {
    window.history.pushState({}, '', '/admin/markets')
    const repo = new ApiAdminMarketControlRepository()
    const overview = await repo.overview()
    expect(overview.markets.map((m) => m.countryCode)).toEqual(['IN', 'US']); expect(overview.activeMarkets).toBe(1); expect(overview.futureMarkets).toBe(1)
    const snap = (await repo.snapshot('india'))!
    expect(snap.states.length).toBe(2); expect(snap.cities.length).toBe(3); expect(snap.serviceAreas.length).toBe(4); expect(snap.routes.length).toBe(1)
    expect(snap.stats.activeCities).toBe(2); expect(snap.stats.activeRoutes).toBe(1); expect(snap.stats.byArea['a-test']).toEqual({ restaurants: expect.any(Number) })
    expect(await repo.snapshot('atlantis')).toBeNull()
  })

  it('sends every change to the admin API with the version that was shown and the reason', async () => {
    window.history.pushState({}, '', '/admin/markets/india')
    const repo = new ApiAdminMarketControlRepository(); await repo.snapshot('india'); calls = []
    for (const path of ['/admin/markets/m-in', '/admin/regions/r-up', '/admin/cities/c-noida', '/admin/service-areas/a-live', '/admin/route-corridors/rc-1', '/admin/markets/m-in/features']) routes[`PATCH ${path}`] = () => ({ body: {} })

    await repo.setMarketStatus('IN', 'PAUSED', 'ignored-actor', ' Regulatory review ')
    await repo.setStateStatus('r-up', 'AVAILABLE', 'x', 'Reopen'); await repo.setStateStatus('r-up', 'DISABLED', 'x', 'Close')
    await repo.setCityStatus('c-noida', 'PAUSED', 'x', 'Floods'); await repo.setServiceAreaStatus('a-live', 'TESTING', 'x', 'Trial'); await repo.setRouteStatus('rc-1', 'PAUSED', 'x', 'Roadworks')
    await repo.setFeature('IN', 'reviews', false, 'x', 'Backlog')

    expect(calls.map((c) => [c.method, c.path, c.body])).toEqual([
      ['PATCH', '/admin/markets/m-in', { version: 3, reason: 'Regulatory review', status: 'PAUSED' }],
      ['PATCH', '/admin/regions/r-up', { version: 2, reason: 'Reopen', status: 'ACTIVE' }],
      ['PATCH', '/admin/regions/r-up', { version: 2, reason: 'Close', status: 'DISABLED' }],
      ['PATCH', '/admin/cities/c-noida', { version: 4, reason: 'Floods', status: 'PAUSED' }],
      ['PATCH', '/admin/service-areas/a-live', { version: 5, reason: 'Trial', status: 'TESTING' }],
      ['PATCH', '/admin/route-corridors/rc-1', { version: 6, reason: 'Roadworks', status: 'PAUSED' }],
      ['PATCH', '/admin/markets/m-in/features', { version: 3, reason: 'Backlog', features: { reviews: false } }],
    ])
    expect(calls.every((c) => c.auth === 'Bearer admin-token' && !('actor' in (c.body ?? {})))).toBe(true)   // the actor is the token, never a field
  })

  it('surfaces what the backend refuses', async () => {
    window.history.pushState({}, '', '/admin/markets/india')
    const repo = new ApiAdminMarketControlRepository(); await repo.snapshot('india')
    routes['PATCH /admin/cities/c-noida'] = () => ({ status: 409, body: { error: { code: 'stale_update', message: 'This record was changed by someone else. Reload it and try again.', details: { current_version: 9 } } } })
    routes['PATCH /admin/regions/r-up'] = () => ({ status: 403, body: { error: { code: 'forbidden', message: 'You are not allowed to do this.' } } })
    routes['PATCH /admin/service-areas/a-live'] = () => ({ status: 409, body: { error: { code: 'invalid_status_transition', message: 'Status cannot change from ACTIVE to PLANNED.' } } })

    await expect(repo.setCityStatus('c-noida', 'PAUSED', 'x', 'Floods')).rejects.toMatchObject({ code: 'stale_update', kind: 'conflict', details: { current_version: 9 } })
    await expect(repo.setStateStatus('r-up', 'DISABLED', 'x', 'Close')).rejects.toBeInstanceOf(ApiError)
    await expect(repo.setServiceAreaStatus('a-live', 'PLANNED', 'x', 'Back')).rejects.toMatchObject({ code: 'invalid_status_transition', message: 'Status cannot change from ACTIVE to PLANNED.' })
    await expect(repo.setMarketStatus('ZZ', 'PAUSED', 'x', 'Unknown')).rejects.toThrow('not_found')
  })
})

describe('MarketGate', () => {
  it('renders straight through in mock mode', () => {
    localStorage.setItem('fotg.market.mode', 'mock')
    render(<MarketGate><p>app</p></MarketGate>)
    expect(screen.getByText('app')).toBeInTheDocument(); expect(calls).toEqual([])
  })

  it('loads the market before the app renders', async () => {
    render(<MarketGate><p>app</p></MarketGate>)
    expect(screen.getByRole('status')).toHaveTextContent('Loading'); expect(screen.queryByText('app')).toBeNull()
    expect(await screen.findByText('app')).toBeInTheDocument(); expect(apiMarketData()!.activeCode).toBe('IN')
  })

  it('says so when the backend cannot be reached, and retries', async () => {
    const user = userEvent.setup()
    vi.stubGlobal('fetch', vi.fn(async () => { throw new TypeError('network') }))
    render(<MarketGate><p>app</p></MarketGate>)
    expect(await screen.findByRole('alert')).toHaveTextContent('could not be loaded'); expect(screen.queryByText('app')).toBeNull()

    vi.stubGlobal('fetch', vi.fn(async (url: string) => json(200, url.endsWith('/markets') ? { data: [IN] } : COVERAGE)))
    await user.click(screen.getByTestId('market-retry'))
    await waitFor(() => expect(screen.getByText('app')).toBeInTheDocument())
  })
})

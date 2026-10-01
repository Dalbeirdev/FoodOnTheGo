/** Add / edit forms for cities and service areas (backend mode). The network is stubbed with the backend's answers. */
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, tokens } from '../../api/client'
import { resetMarketData } from '../../market/api/marketData'
import type { City, MarketRegion, ServiceArea } from '../../market/types'
import { ApiAdminMarketControlRepository } from '../api/ApiAdminMarketControlRepository'
import { MockAdminMarketControlRepository } from '../mock/mockAdmin'
import type { CityInput, ServiceAreaInput } from '../types'
import { circlePolygon, CityFormDrawer, parseGeoJsonArea, ServiceAreaFormDrawer } from './MarketGeoForms'

const REGIONS: MarketRegion[] = [{ id: 'r-up', marketCode: 'IN', name: 'Uttar Pradesh', code: 'IN-UP', kind: 'state', status: 'AVAILABLE' }, { id: 'r-hr', marketCode: 'IN', name: 'Haryana', code: 'IN-HR', kind: 'state', status: 'AVAILABLE' }]
const NOIDA: City = { id: 'c-noida', marketCode: 'IN', regionId: 'r-up', name: 'Noida', aliases: ['NOIDA'], lat: 28.5355, lng: 77.391, timezone: 'Asia/Kolkata', status: 'ACTIVE', launchStage: 'Launched', launchDate: '2026-09-01' }
const AREA: ServiceArea = { id: 'a-1', marketCode: 'IN', cityId: 'c-noida', name: 'Noida Central', status: 'ACTIVE', geometry: { type: 'multipolygon', coordinates: [] }, launchStage: '', updatedAt: '', priority: 3 }
const haversineKm = (a: number[], b: number[]) => { const r = (d: number) => (d * Math.PI) / 180; const h = Math.sin(r(b[1] - a[1]) / 2) ** 2 + Math.cos(r(a[1])) * Math.cos(r(b[1])) * Math.sin(r(b[0] - a[0]) / 2) ** 2; return 2 * 6371.0088 * Math.asin(Math.sqrt(h)) }

describe('boundary helpers', () => {
  it('a circle becomes a closed 32-point ring whose points lie at the radius, [longitude, latitude]', () => {
    const g = circlePolygon(28.5355, 77.391, 6)
    expect(g.type).toBe('Polygon'); const ring = (g.coordinates as number[][][])[0]
    expect(ring.length).toBe(33); expect(ring[0]).toEqual(ring[32])
    for (const p of ring) expect(haversineKm([77.391, 28.5355], p)).toBeCloseTo(6, 2)
    expect(ring[0][0]).toBeCloseTo(77.391, 3); expect(ring[0][1]).toBeGreaterThan(28.58)   // first point is due north
  })

  it('pasted GeoJSON: a geometry, a Feature or a one-feature collection is accepted; anything else is not', () => {
    const poly = { type: 'Polygon', coordinates: [[[77.3, 28.5], [77.4, 28.5], [77.4, 28.6], [77.3, 28.5]]] }
    expect(parseGeoJsonArea(JSON.stringify(poly))).toEqual(poly)
    expect(parseGeoJsonArea(JSON.stringify({ type: 'Feature', properties: { name: 'x' }, geometry: poly }))).toEqual(poly)
    expect(parseGeoJsonArea(JSON.stringify({ type: 'FeatureCollection', features: [{ type: 'Feature', geometry: poly }] }))).toEqual(poly)
    expect(parseGeoJsonArea(JSON.stringify({ type: 'MultiPolygon', coordinates: [poly.coordinates] }))?.type).toBe('MultiPolygon')
    for (const bad of ['', 'POLYGON((77 28, 78 28, 78 29, 77 28))', '{"type":"Point","coordinates":[77,28]}', '{"type":"LineString","coordinates":[[77,28],[78,29]]}', '{"type":"FeatureCollection","features":[]}', '[1,2]']) expect(parseGeoJsonArea(bad), bad).toBeNull()
  })
})

describe('city form', () => {
  it('validates before sending and creates a city from the entered values', async () => {
    const user = userEvent.setup(); const onSave = vi.fn(async (_i: CityInput, _r: string) => {})
    render(<CityFormDrawer open city={null} regions={REGIONS} defaultTimezone="Asia/Kolkata" locale="en" onClose={() => {}} onSave={onSave} />)
    expect(screen.getByText(/start as Planned/)).toBeInTheDocument()
    await user.click(screen.getByTestId('city-save'))
    expect(onSave).not.toHaveBeenCalled(); expect(screen.getAllByRole('alert').length).toBe(4)   // name, state, latitude, longitude

    await user.type(screen.getByLabelText(/City name/), 'Agra'); await user.selectOptions(screen.getByLabelText(/State \/ UT/), 'r-up')
    await user.type(screen.getByLabelText(/Latitude/), '127.1767'); await user.type(screen.getByLabelText(/Longitude/), '78.0081')
    await user.click(screen.getByTestId('city-save')); expect(onSave).not.toHaveBeenCalled(); expect(screen.getByText(/between -90 and 90/)).toBeInTheDocument()

    await user.clear(screen.getByLabelText(/Latitude/)); await user.type(screen.getByLabelText(/Latitude/), '27.1767')
    await user.type(screen.getByLabelText(/Other names/), 'Akbarabad,  Agra City '); await user.click(screen.getByTestId('city-save'))
    expect(onSave).toHaveBeenCalledWith({ regionId: 'r-up', name: 'Agra', lat: 27.1767, lng: 78.0081, timezone: 'Asia/Kolkata', aliases: ['Akbarabad', 'Agra City'], launchStage: null }, '')
  })

  it('editing starts from the stored values; the state cannot be changed', async () => {
    const user = userEvent.setup(); const onSave = vi.fn(async (_i: CityInput, _r: string) => {})
    render(<CityFormDrawer open city={NOIDA} regions={REGIONS} defaultTimezone="Asia/Kolkata" locale="en" onClose={() => {}} onSave={onSave} />)
    expect(screen.getByLabelText(/City name/)).toHaveValue('Noida'); expect(screen.getByLabelText(/State \/ UT/)).toHaveValue('Uttar Pradesh'); expect(screen.getByLabelText(/State \/ UT/)).toHaveAttribute('readonly')
    await user.clear(screen.getByLabelText(/City name/)); await user.type(screen.getByLabelText(/City name/), 'Noida City'); await user.click(screen.getByTestId('city-save'))
    expect(onSave.mock.calls[0][0]).toMatchObject({ name: 'Noida City', lat: 28.5355, lng: 77.391, aliases: ['NOIDA'], launchStage: 'Launched' })
  })

  it('shows what the backend refuses: field errors next to the field, other refusals as a message; the form stays open', async () => {
    const user = userEvent.setup()
    const onSave = vi.fn<(i: CityInput, r: string) => Promise<void>>()
      .mockRejectedValueOnce(new ApiError(422, 'The submitted data is invalid.', { code: 'validation_failed', errors: { name: ['This already exists.'] } }))
      .mockRejectedValueOnce(new ApiError(422, 'The latitude lies outside the market. Coordinates are [longitude, latitude] in WGS84.', { code: 'geometry_outside_market' }))
      .mockRejectedValueOnce(new ApiError(409, 'This record was changed by someone else. Reload it and try again.', { code: 'stale_update' }))
    render(<CityFormDrawer open city={NOIDA} regions={REGIONS} defaultTimezone="Asia/Kolkata" locale="en" onClose={() => {}} onSave={onSave} />)
    await user.click(screen.getByTestId('city-save')); expect(await screen.findByText('This already exists.')).toBeInTheDocument(); expect(screen.getByTestId('geo-form-error')).toHaveTextContent('check the highlighted fields')
    await user.click(screen.getByTestId('city-save')); expect(await screen.findByText(/outside the market/)).toBeInTheDocument()
    await user.click(screen.getByTestId('city-save')); expect(await screen.findByText(/changed by someone else/)).toBeInTheDocument()
    expect(screen.getByTestId('city-form')).toBeInTheDocument()
  })
})

describe('service area form', () => {
  it('creates an area from a circle around the city centre, with a preview', async () => {
    const user = userEvent.setup(); const onSave = vi.fn(async (_i: ServiceAreaInput, _r: string) => {})
    render(<ServiceAreaFormDrawer open area={null} cities={[NOIDA]} locale="en" onClose={() => {}} onSave={onSave} />)
    await user.click(screen.getByTestId('area-save')); expect(onSave).not.toHaveBeenCalled(); expect(screen.getByText(/centre and a radius/)).toBeInTheDocument()
    expect(screen.getByTestId('use-city-centre')).toBeDisabled()

    await user.type(screen.getByLabelText(/Service area name/), 'Noida · Sector 62'); await user.selectOptions(screen.getByLabelText(/^City/), 'c-noida')
    await user.click(screen.getByTestId('use-city-centre')); expect(screen.getByLabelText(/Centre latitude/)).toHaveValue('28.5355')
    await user.type(screen.getByLabelText(/Radius/), '6'); expect(screen.getByTestId('boundary-preview')).toHaveTextContent('33 points')
    await user.click(screen.getByTestId('area-save'))
    const input = onSave.mock.calls[0][0]
    expect(input).toMatchObject({ cityId: 'c-noida', name: 'Noida · Sector 62', priority: 0, launchStage: null }); expect(input.geometry).toEqual(circlePolygon(28.5355, 77.391, 6))
  })

  it('accepts pasted GeoJSON and refuses text that is not a polygon', async () => {
    const user = userEvent.setup(); const onSave = vi.fn(async (_i: ServiceAreaInput, _r: string) => {})
    render(<ServiceAreaFormDrawer open area={null} cities={[NOIDA]} locale="en" onClose={() => {}} onSave={onSave} />)
    await user.type(screen.getByLabelText(/Service area name/), 'Pasted'); await user.selectOptions(screen.getByLabelText(/^City/), 'c-noida'); await user.click(screen.getByTestId('boundary-geojson'))
    await user.click(screen.getByLabelText(/^GeoJSON/)); await user.paste('{"type":"Point","coordinates":[77,28]}'); await user.click(screen.getByTestId('area-save'))
    expect(onSave).not.toHaveBeenCalled(); expect(screen.getByText(/not a GeoJSON Polygon/)).toBeInTheDocument()
    const poly = { type: 'Polygon', coordinates: [[[77.3, 28.5], [77.4, 28.5], [77.4, 28.6], [77.3, 28.6], [77.3, 28.5]]] }
    await user.clear(screen.getByLabelText(/^GeoJSON/)); await user.click(screen.getByLabelText(/^GeoJSON/)); await user.paste(JSON.stringify(poly)); await user.click(screen.getByTestId('area-save'))
    expect(onSave.mock.calls[0][0].geometry).toEqual(poly)
  })

  it('an edit keeps the stored boundary unless "replace" is ticked, and shows a PostGIS refusal', async () => {
    const user = userEvent.setup()
    const onSave = vi.fn<(i: ServiceAreaInput, r: string) => Promise<void>>().mockResolvedValueOnce(undefined).mockRejectedValueOnce(new ApiError(422, 'Geometry is not valid: Self-intersection.', { code: 'invalid_geometry' }))
    render(<ServiceAreaFormDrawer open area={AREA} cities={[NOIDA]} locale="en" onClose={() => {}} onSave={onSave} />)
    expect(screen.getByLabelText(/Priority/)).toHaveValue('3'); expect(screen.queryByLabelText(/Radius/)).toBeNull()
    await user.clear(screen.getByLabelText(/Priority/)); await user.type(screen.getByLabelText(/Priority/), '10'); await user.click(screen.getByTestId('area-save'))
    expect(onSave.mock.calls[0][0]).toEqual({ cityId: 'c-noida', name: 'Noida Central', priority: 10, launchStage: null, geometry: null })

    await user.click(screen.getByTestId('area-replace')); await user.click(screen.getByTestId('boundary-geojson')); await user.click(screen.getByLabelText(/^GeoJSON/))
    await user.paste('{"type":"Polygon","coordinates":[[[77.3,28.5],[77.4,28.6],[77.4,28.5],[77.3,28.6],[77.3,28.5]]]}'); await user.click(screen.getByTestId('area-save'))
    expect(await screen.findByText(/Self-intersection/)).toBeInTheDocument(); expect(onSave.mock.calls[1][0].geometry?.type).toBe('Polygon')
  })
})

describe('repository', () => {
  type Call = { method: string; path: string; body: Record<string, unknown> | null; auth: string | null }
  let calls: Call[] = []
  beforeEach(() => {
    localStorage.clear(); sessionStorage.clear(); resetMarketData(); localStorage.setItem('fotg.market.mode', 'api'); tokens.set('admin', 'admin-token'); calls = []; window.history.pushState({}, '', '/admin/markets/india/cities')
    const IN = { id: 'm-in', slug: 'india', country_code: 'IN', name: 'India', status: 'ACTIVE', default_currency: 'INR', supported_currencies: ['INR'], default_locale: 'en-IN', supported_locales: ['en-IN'], timezone_strategy: 'single', default_timezone: 'Asia/Kolkata', distance_unit: 'metric', phone_country_code: '+91', features: {}, launched_at: null, serving_customers: true, version: 2, updated_at: null }
    const sq = { type: 'MultiPolygon', coordinates: [[[[77.3, 28.5], [77.4, 28.5], [77.4, 28.6], [77.3, 28.6], [77.3, 28.5]]]] }
    vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
      const path = new URL(url).pathname.replace(/^\/api\/v1/, ''); const method = init.method ?? 'GET'
      calls.push({ method, path, body: init.body ? JSON.parse(String(init.body)) : null, auth: (init.headers as Record<string, string>).Authorization ?? null })
      const body = path === '/admin/markets' ? { data: [IN] }
        : path.endsWith('/map') ? { regions: [{ id: 'r-up', code: 'IN-UP', name: 'Uttar Pradesh', type: 'STATE', status: 'ACTIVE', version: 1 }], cities: [{ id: 'c-noida', region_id: 'r-up', name: 'Noida', slug: 'noida', aliases: [], latitude: 28.5355, longitude: 77.391, timezone: 'Asia/Kolkata', status: 'ACTIVE', version: 7 }], service_areas: [{ id: 'a-1', city_id: 'c-noida', name: 'Noida Central', slug: 'noida-central', status: 'ACTIVE', priority: 3, geometry: sq, version: 4 }], route_corridors: [] }
          : path.endsWith('/configuration') ? { payment: {}, tax: {}, legal: {}, address: {}, ordering: {}, locked_features: [], version: 1 } : {}
      return new Response(JSON.stringify(body), { status: method === 'POST' ? 201 : 200, headers: { 'Content-Type': 'application/json' } })
    }))
  })
  afterEach(() => { vi.unstubAllGlobals(); tokens.set('admin', null); window.history.pushState({}, '', '/'); localStorage.clear(); sessionStorage.clear(); resetMarketData() })

  it('sends creates and edits to the admin API in the backend\'s field names; edits carry the version that was shown', async () => {
    const repo = new ApiAdminMarketControlRepository(); const snap = (await repo.snapshot('india'))!
    expect(snap.serviceAreas[0].priority).toBe(3); calls = []
    const city: CityInput = { regionId: 'r-up', name: 'Agra', lat: 27.1767, lng: 78.0081, timezone: 'Asia/Kolkata', aliases: ['Akbarabad'], launchStage: null }
    const g = circlePolygon(27.1767, 78.0081, 8)
    await repo.createCity('IN', city); await repo.updateCity('c-noida', { ...city, name: 'Noida City' }, '')
    await repo.createServiceArea('IN', { cityId: 'c-noida', name: 'New area', priority: 0, launchStage: 'Survey pending', geometry: g })
    await repo.updateServiceArea('a-1', { cityId: 'c-noida', name: 'Noida Central', priority: 10, launchStage: null, geometry: null }, '')
    await repo.updateServiceArea('a-1', { cityId: 'c-noida', name: 'Noida Central', priority: 10, launchStage: null, geometry: g }, '')

    expect(calls.map((c) => [c.method, c.path, c.body])).toEqual([
      ['POST', '/admin/markets/m-in/cities', { region_id: 'r-up', name: 'Agra', latitude: 27.1767, longitude: 78.0081, timezone: 'Asia/Kolkata', aliases: ['Akbarabad'], launch_stage: null }],
      ['PATCH', '/admin/cities/c-noida', { version: 7, reason: '', name: 'Noida City', latitude: 27.1767, longitude: 78.0081, timezone: 'Asia/Kolkata', aliases: ['Akbarabad'], launch_stage: null }],
      ['POST', '/admin/markets/m-in/service-areas', { city_id: 'c-noida', name: 'New area', priority: 0, launch_stage: 'Survey pending', geometry: g }],
      ['PATCH', '/admin/service-areas/a-1', { version: 4, reason: '', name: 'Noida Central', priority: 10, launch_stage: null }],
      ['PATCH', '/admin/service-areas/a-1', { version: 4, reason: '', name: 'Noida Central', priority: 10, launch_stage: null, geometry: g }],
    ])
    expect(calls.every((c) => c.auth === 'Bearer admin-token')).toBe(true)
  })

  it('the fixture repository refuses to create or edit geography', async () => {
    const mock = new MockAdminMarketControlRepository()
    await expect(mock.createCity('IN', {} as CityInput)).rejects.toThrow('geography_editing_needs_backend')
    await expect(mock.updateServiceArea('x', {} as ServiceAreaInput, '')).rejects.toThrow('geography_editing_needs_backend')
  })
})

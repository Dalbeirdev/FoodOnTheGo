/**
 * Journeys on the backend (Module 26): the mapping of the journey API onto the page model, the bodies the repositories
 * send (a place id or coordinates — never an owner, a market or a route), the guest token that makes a journey readable,
 * the error vocabulary the screens rely on, and the two pages on those repositories. The network is stubbed with payloads
 * in the shape of the real API (OpenAPI: PlaceSearchResult, ResolvedPlace, PlannedJourney, JourneyRestaurantPage).
 */
import { cleanup, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { AccountProvider } from '../../account/AccountContext'
import { tokens } from '../../api/client'
import { AuthProvider } from '../../auth/AuthContext'
import { CartProvider } from '../../cart/CartContext'
import { ToastProvider } from '../../components/Toast'
import { LocaleProvider } from '../../i18n/LocaleProvider'
import { OrdersProvider } from '../../orders/OrdersContext'
import PlanJourneyPage from '../../pages/PlanJourneyPage'
import RestaurantsPage from '../../pages/RestaurantsPage'
import { ProfileProvider } from '../../profile/ProfileContext'
import { restaurantRepository } from '../../repositories'
import { routeContextFor } from '../../repositories/mock/restaurants'
import type { PublicRestaurantDto } from '../../restaurants/api/restaurantData'
import { JourneyProvider } from '../JourneyContext'
import { selectJourneyRepositories } from '../journeyRepositories'
import { MockJourneyRepository } from '../mock/mockRepositories'
import { JourneyError, type Location } from '../repositories'
import {
  ApiJourneyRepository, ApiLocationRepository, arrivalAvailability, discoverAlongJourney, journeyFilterDefinitions, journeyTokens, resetJourneyApiState, storedRouteContext, toDiscoveryQuery,
  toEndBody, toJourney, toJourneyError, toResolved, toRouteResult, toSuggestion, type JourneyDto, type JourneyRestaurantDto, type JourneyRestaurantPageDto, type PlaceSuggestionDto, type ResolvedPlaceDto, type RouteContextDto,
} from './apiJourney'

const JOURNEY = '9d3f2c61-5b0e-4b7a-9c57-0c6c1f2a4e10'
const TOKEN = 'a'.repeat(64)

const suggestionDto = (patch: Partial<PlaceSuggestionDto> = {}): PlaceSuggestionDto => ({ provider: 'fake', place_id: 'fake:noida-62', name: 'Sector 62, Noida', formatted_address: 'Sector 62, Noida, Uttar Pradesh 201309, India', country_code: 'IN', kind: 'landmark', region: 'Uttar Pradesh', locality: 'Noida', ...patch })
const resolvedDto = (patch: Partial<ResolvedPlaceDto> = {}): ResolvedPlaceDto => ({
  place: { provider: 'fake', id: 'fake:noida-62', name: 'Sector 62, Noida', kind: 'landmark' },
  location: { latitude: 28.628, longitude: 77.3649, formatted_address: 'Sector 62, Noida, Uttar Pradesh 201309, India', country_code: 'IN', region: 'Uttar Pradesh', locality: 'Noida', postal_code: null, timezone: 'Asia/Kolkata' },
  coverage: { supported: true, reason: null, market: 'IN', city: 'Noida', service_area: 'Noida · Sector 62 & NH24' }, provider: { name: 'fake', mode: 'development' }, ...patch,
})
const end = (label: string, lat: number, lng: number, placeId: string | null) => ({ label, formatted_address: `${label}, Uttar Pradesh, India`, location: { latitude: lat, longitude: lng }, country_code: 'IN', region: 'Uttar Pradesh', locality: 'Noida', timezone: 'Asia/Kolkata', place: placeId ? { provider: 'fake', id: placeId } : null, source: (placeId ? 'place' : 'coordinates') as 'place' | 'coordinates', kind: 'landmark' })
const journeyDto = (patch: Partial<JourneyDto> = {}): JourneyDto => ({
  id: JOURNEY, status: 'ACTIVE', owner: 'guest', market: 'IN',
  origin: end('Sector 62, Noida', 28.628, 77.3649, 'fake:noida-62'), destination: end('Sector 18, Noida', 28.5708, 77.3261, 'fake:noida-18'),
  departure: { at: null, given: false, timezone: 'Asia/Kolkata' },
  route: {
    distance_meters: 9624, duration_seconds: 990, encoded_polyline: 'abc', geometry: { type: 'LineString', coordinates: [[77.3649, 28.628], [77.356, 28.612], [77.341, 28.594], [77.3261, 28.5708]] },
    bounds: { west: 77.3261, south: 28.5708, east: 77.3649, north: 28.628 }, legs: [{ distance_meters: 9624, duration_seconds: 990 }], flags: [], warnings: ['Development route model — not road data'],
    provider: { name: 'fake', mode: 'development' }, traffic_aware: false, preferences: { avoid_tolls: false, avoid_ferries: false, avoid_highways: false }, calculated_at: '2026-10-05T06:30:00+00:00', points: 4,
  },
  coverage: { origin: { supported: true, reason: null, city: 'Noida', service_area: 'Noida · Sector 62 & NH24' }, destination: { supported: true, reason: null, city: 'Noida', service_area: 'Noida · Expressway / Sector 18' }, along_route: 'COVERED', serviceable_areas: 3, corridor_width_meters: 3000, corridors: [] },
  search: { corridor_width_meters: 3000, corridor_min_meters: 500, corridor_max_meters: 10000, max_detour_minutes: 15, max_detour_max_minutes: 60, candidate_cap: 40, page_size: 10, sorts: ['recommended', 'lowest_detour', 'route_order', 'nearest_to_route', 'fastest_pickup'] },
  expires_at: '2026-10-05T07:00:00+00:00', created_at: '2026-10-05T06:30:00+00:00', ...patch,
})
const restaurantDto = (slug: string, name: string): PublicRestaurantDto => ({
  id: `7822b22c-05f3-49cd-ae89-${slug.padEnd(12, '0').slice(0, 12)}`, slug, name, branch_label: null, short_description: 'Food for travellers.', description: 'Food for travellers.',
  cuisines: [{ code: 'burgers', name: 'Burgers' }], features: [], price_level: 2, phone: null, email: null, website: null,
  address: { formatted: 'Sector 62, Noida', line1: 'Sector 62', postal_code: '201309', city: 'Noida', city_slug: 'noida', region: 'Uttar Pradesh', region_code: 'IN-UP', country_code: 'IN' },
  location: { latitude: 28.6285, longitude: 77.3652 }, timezone: 'Asia/Kolkata', currency: 'INR', images: { logo: null, cover: null, gallery: [] },
  hours: { timezone: 'Asia/Kolkata', weekly: [0, 1, 2, 3, 4, 5, 6].map((day_of_week) => ({ day_of_week, periods: [{ opens_at: '08:00', closes_at: '23:30' }] })), special: [] },
  pickup: { methods: [{ method: 'COUNTER', instructions: null, requires_vehicle_info: false }], asap: true, scheduled: false, default_prep_minutes: 12, minimum_lead_minutes: null, instructions: null },
  availability: { open_now: true, open_state: 'OPEN', accepting_orders: true, orderable: true, reason: null, closes_at: '2026-10-05T18:00:00+00:00', opens_next_at: null, checked_at: '2026-10-05T06:30:00+00:00' },
})
const contextDto = (patch: Partial<RouteContextDto> = {}): RouteContextDto => ({
  distance_to_route_meters: 60, route_progress: 0.02, segment: 0, detour: { seconds: 79, minutes: 2, distance_meters: 156 },
  arrival: { estimated_at: '2026-10-05T06:32:00+00:00', local_time: '12:02', local_date: '2026-10-05', timezone: 'Asia/Kolkata', leg_seconds: 120, leg_distance_meters: 500, is_estimate: true },
  open_at_arrival: true, orderable_at_arrival: true, availability_at_arrival: { open_now: true, open_state: 'OPEN', accepting_orders: true, orderable: true, reason: null, closes_at: '2026-10-05T18:00:00+00:00', opens_next_at: null, checked_at: '2026-10-05T06:32:00+00:00' },
  accepting_orders_now: true, prep_minutes: 12, ...patch,
})
const rowDto = (slug: string, name: string, patch: Partial<RouteContextDto> = {}): JourneyRestaurantDto => ({ restaurant: restaurantDto(slug, name), route_context: contextDto(patch) })
const pageDto = (data: JourneyRestaurantDto[], meta: Partial<JourneyRestaurantPageDto['meta']> = {}): JourneyRestaurantPageDto => ({
  data,
  meta: {
    current_page: 1, per_page: 6, total: data.length, last_page: 1, journey_id: JOURNEY,
    search: { sort: 'recommended', corridor_width_meters: 3000, max_detour_minutes: 15, open_at_arrival: false, cuisine: [], feature: [], pickup_method: null, q: null, corridor_min_meters: 500, corridor_max_meters: 10000, max_detour_max_minutes: 60, candidate_cap: 40 },
    departure_at: '2026-10-05T06:30:00+00:00', calculated_at: '2026-10-05T06:30:01+00:00', cached: false, provider: { name: 'fake', mode: 'development' }, coverage: { along_route: 'COVERED', serviceable_areas: 3 },
    counts: { corridor_rows: data.length, shortlist: data.length, detour_requested: data.length, unreachable: 0, provider_calls: data.length ? 1 : 0, matching: data.length, dropped_since_calculation: 0 }, empty_reason: null, ...meta,
  },
})

type Call = { method: string; path: string; query: URLSearchParams; body: unknown; headers: Record<string, string> }
type Reply = { status?: number; body: unknown }
let calls: Call[] = []
let reply: (method: string, path: string, query: URLSearchParams) => Reply = () => ({ body: {} })
const error = (status: number, code: string, message: string, fields?: Record<string, string[]>): Reply => ({ status, body: { error: { code, message, details: fields ? { fields } : {}, request_id: 'r1' } } })
beforeEach(() => {
  calls = []; sessionStorage.clear(); localStorage.clear(); resetJourneyApiState(); tokens.set('customer', null)
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const u = new URL(url)
    const path = decodeURIComponent(u.pathname.replace('/api/v1', ''))
    calls.push({ method: init.method ?? 'GET', path, query: u.searchParams, body: init.body ? JSON.parse(init.body as string) : undefined, headers: (init.headers ?? {}) as Record<string, string> })
    const r = reply(init.method ?? 'GET', path, u.searchParams)
    return new Response(JSON.stringify(r.body), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } })
  }))
})
afterEach(() => { cleanup(); vi.unstubAllGlobals(); tokens.set('customer', null) })
const last = (method: string, fragment: string) => [...calls].reverse().find((c) => c.method === method && c.path.includes(fragment))!

describe('mapping — the journey API onto the page model', () => {
  it('a suggestion is text only; resolving gives it coordinates, a time zone and the coverage answer', () => {
    const s = toSuggestion(suggestionDto())
    expect(s).toMatchObject({ id: 'fake:noida-62', name: 'Sector 62, Noida', sub: 'Sector 62, Noida, Uttar Pradesh 201309, India', kind: 'landmark', source: 'place', placeId: 'fake:noida-62', lat: null, lng: null, countryCode: 'IN' })
    expect(toSuggestion(suggestionDto({ kind: 'airport' })).kind).toBe('airport')
    expect(toSuggestion(suggestionDto({ kind: 'address' })).kind).toBe('landmark')
    const r = toResolved(s, resolvedDto())
    expect(r).toMatchObject({ lat: 28.628, lng: 77.3649, timezone: 'Asia/Kolkata', coverage: { supported: true, city: 'Noida', serviceArea: 'Noida · Sector 62 & NH24' } })
    expect(toResolved(s, resolvedDto({ coverage: { supported: false, reason: 'OUTSIDE_SERVICE_AREA', market: 'IN', city: 'Jaipur', service_area: null } })).coverage).toEqual({ supported: false, reason: 'OUTSIDE_SERVICE_AREA', city: 'Jaipur', serviceArea: null })
  })

  it('a journey carries the provider route as [lat, lng] geometry, its provenance, coverage, search bounds and expiry', () => {
    const j = toJourney(journeyDto())
    expect(j).toMatchObject({ id: JOURNEY, backend: true, owner: 'guest', status: 'route-available', departureAt: null, expiresAt: '2026-10-05T07:00:00+00:00', originLat: 28.628, destinationLng: 77.3261 })
    expect(j.route).toMatchObject({ provider: 'fake', mode: 'development', isEstimate: true, distanceKm: 9.6, durationMin: 17, distanceM: 9624, durationS: 990, trafficAware: false, waypoints: [] })
    expect(j.route!.geometry[0]).toEqual([28.628, 77.3649]) // GeoJSON is [lng, lat]; the map takes [lat, lng]
    expect(j.route!.geometry).toHaveLength(4)
    expect(j.origin).toMatchObject({ name: 'Sector 62, Noida', source: 'place', placeId: 'fake:noida-62', countryCode: 'IN', timezone: 'Asia/Kolkata', coverage: { supported: true } })
    expect(j.coverage).toMatchObject({ alongRoute: 'COVERED', serviceableAreas: 3 })
    expect(j.search).toEqual({ corridorM: 3000, corridorMinM: 500, corridorMaxM: 10000, maxDetourMin: 15, maxDetourMaxMin: 60, candidateCap: 40, pageSize: 10, sorts: ['recommended', 'lowest_detour', 'route_order', 'nearest_to_route', 'fastest_pickup'] })

    const live = toJourney(journeyDto({ departure: { at: '2026-10-05T08:30:00+00:00', given: true, timezone: 'Asia/Kolkata' }, route: { ...journeyDto().route, distance_meters: 248_100, duration_seconds: 17_880, flags: ['TOLLS'], provider: { name: 'google', mode: 'live' }, traffic_aware: true } }))
    expect(live.departureAt).toBe('2026-10-05T08:30:00+00:00')
    expect(live.route).toMatchObject({ provider: 'google', mode: 'live', isEstimate: false, distanceKm: 248, durationMin: 298, flags: ['TOLLS'], trafficAware: true })
  })

  it('one end of a journey is sent in exactly one form — and never with an owner, a market or a route', () => {
    const place: Location = toSuggestion(suggestionDto())
    expect(toEndBody(toResolved(place, resolvedDto()))).toEqual({ place_id: 'fake:noida-62' }) // the browser's copy of the coordinates is not sent
    expect(toEndBody({ id: 'addr-1', name: 'Home', sub: '', kind: 'saved', lat: 28.6, lng: 77.3, source: 'saved-address', savedLocationId: 'df2d09ac-56ca-4dbe-be7f-ba5ea89df14e' })).toEqual({ saved_location_id: 'df2d09ac-56ca-4dbe-be7f-ba5ea89df14e', label: 'Home' })
    expect(toEndBody({ id: 'device-current', name: 'Current location', sub: 'From this device', kind: 'current', lat: 28.61234, lng: 77.23456, source: 'device' })).toEqual({ lat: 28.61234, lng: 77.23456, label: 'Current location' })
    expect(toEndBody({ id: 'recent-1', name: 'Sector 18 Metro', sub: 'Noida', kind: 'recent', lat: 28.57, lng: 77.32, source: 'recent', formattedAddress: 'Sector 18 Metro Station, Noida' })).toEqual({ lat: 28.57, lng: 77.32, label: 'Sector 18 Metro', formatted_address: 'Sector 18 Metro Station, Noida' })
    // A searched place that comes back from the recent places travels as its id again (the backend resolves it once more).
    expect(toEndBody({ id: 'recent-2', name: 'Sector 18, Noida', sub: 'Noida', kind: 'recent', lat: 28.5708, lng: 77.3261, source: 'recent', placeId: 'fake:noida-18' })).toEqual({ place_id: 'fake:noida-18' })
  })

  it('a result describes the restaurant at the expected arrival, with the provider figures and nothing invented', () => {
    const open = toRouteResult(rowDto('burger-hub', 'Burger Hub'))
    expect(open).toMatchObject({ distanceFromRouteM: 60, detourDistanceM: 156, detourDurationMin: 2, estimatedArrival: '2026-10-05T06:32:00+00:00', routePosition: 0.02, atArrival: true, orderableAtArrival: true, estimatedPickupReady: null })
    expect(open.availability).toMatchObject({ status: 'open', acceptingOrders: true, nextChangeAt: '2026-10-05T18:00:00+00:00' })
    // burger-hub is a slug the development fixtures have a sample rating for: along a backend journey it is not used
    expect(open.restaurant).toMatchObject({ slug: 'burger-hub', name: 'Burger Hub', timezone: 'Asia/Kolkata', rating: 0, reviewCount: 0, ratingIsSample: false })

    const closed = contextDto({ open_at_arrival: false, orderable_at_arrival: false, availability_at_arrival: { open_now: false, open_state: 'CLOSED', accepting_orders: true, orderable: false, reason: 'CLOSED_NOW', closes_at: null, opens_next_at: '2026-10-05T12:30:00+00:00', checked_at: '2026-10-05T06:32:00+00:00' } })
    expect(arrivalAvailability(closed)).toMatchObject({ status: 'closed', nextChangeAt: '2026-10-05T12:30:00+00:00' })
    expect(arrivalAvailability({ ...closed, availability_at_arrival: { ...closed.availability_at_arrival, opens_next_at: '2026-10-05T07:00:00+00:00' } }).status).toBe('opening_soon')
    expect(arrivalAvailability(contextDto({ availability_at_arrival: { ...contextDto().availability_at_arrival, closes_at: '2026-10-05T07:00:00+00:00' } })).status).toBe('closing_soon')
    expect(arrivalAvailability(contextDto({ availability_at_arrival: { ...contextDto().availability_at_arrival, open_now: false, open_state: 'TEMPORARILY_CLOSED', reason: 'TEMPORARILY_CLOSED' } })).status).toBe('temporarily_closed')
    expect(arrivalAvailability(contextDto({ orderable_at_arrival: false, availability_at_arrival: { ...contextDto().availability_at_arrival, accepting_orders: false, orderable: false, reason: 'NOT_ACCEPTING_ORDERS' } }))).toMatchObject({ status: 'open', acceptingOrders: false })
  })

  it('the discovery query sends only what the API applies, inside the journey bounds', () => {
    const journey = toJourney(journeyDto())
    expect(toDiscoveryQuery(journey, {})).toEqual({ sort: 'recommended', corridor_width_meters: undefined, max_detour_minutes: undefined, open_at_arrival: undefined, cuisine: undefined, min_rating: undefined, q: undefined, 'page[number]': 1, 'page[size]': 6 })
    expect(toDiscoveryQuery(journey, { sort: 'routeOrder', corridorM: 25_000, search: '  dhaba ', cursor: '3', limit: 99, filters: { cuisine: ['punjabi', 'north_indian'], openNow: true, detourTime: 90, rating: 4, price: ['2'], dietary: ['Vegan'] } }))
      .toEqual({ sort: 'route_order', corridor_width_meters: 10000, max_detour_minutes: 60, open_at_arrival: true, cuisine: 'punjabi,north_indian', min_rating: 4, q: 'dhaba', 'page[number]': 3, 'page[size]': 25 }) // Module 34: the minimum rating is applied by the backend on its own averages
    expect(toDiscoveryQuery(journey, { sort: 'highestRated', corridorM: 100 }).sort).toBe('highest_rated') // Module 34: the backend orders by its own ratings
    expect(toDiscoveryQuery(journey, { corridorM: 100 }).corridor_width_meters).toBe(500)
    const defs = journeyFilterDefinitions(journey, [{ code: 'burgers', name: 'Burgers', restaurants: 3 }, { code: 'sushi', name: 'Sushi', restaurants: 0 }])
    expect(defs.map((d) => d.id)).toEqual(['cuisine', 'openNow', 'detourTime'])
    expect(defs[0].options).toEqual([{ value: 'burgers', label: 'Burgers' }])
    expect(defs[1].labelKey).toBe('filter.openAtArrival')
    expect(defs[2]).toMatchObject({ kind: 'max', unit: 'minutes', max: 60 })
  })
})

describe('errors', () => {
  const cases: Array<[Reply, JourneyError['code'], RegExp]> = [
    [error(422, 'route_not_found', 'No road route is available between these locations.'), 'no-route', /no road route/i],
    [error(422, 'market_unsupported', 'FoodOnTheGo is not available where this journey ends yet.'), 'market-unsupported', /isn't available/i],
    [error(422, 'cross_border_unsupported', 'Journeys between two markets are not supported yet.'), 'cross-border', /two countries/i],
    [error(503, 'routing_temporarily_unavailable', 'Route planning is temporarily unavailable.'), 'provider-unavailable', /temporarily unavailable/i],
    [error(503, 'provider_rate_limited', 'Route planning is busy right now.'), 'rate-limited', /wait a moment/i],
    [error(429, 'rate_limited', 'Too many requests.'), 'rate-limited', /wait a moment/i],
    [error(422, 'validation_failed', 'The submitted data is invalid.', { departure_at: ['The departure time is in the past.'] }), 'invalid', /in the past/i],
    [error(404, 'place_not_found', 'This place could not be found.'), 'place-not-found', /could not be found/i],
  ]
  it.each(cases)('POST /journeys/plan %#', async (r, code, message) => {
    reply = () => r
    const failure = await new ApiJourneyRepository().create({ origin: toSuggestion(suggestionDto()), destination: toSuggestion(suggestionDto({ place_id: 'fake:noida-18' })), departureAt: null }).catch((e) => e)
    expect(failure).toBeInstanceOf(JourneyError)
    expect(failure.code).toBe(code)
    expect(failure.message).toMatch(message)
    expect(journeyTokens.get(JOURNEY)).toBeNull()
  })
  it('a network failure and an unknown error stay honest', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => { throw new TypeError('fetch failed') }))
    await expect(new ApiLocationRepository().search('delhi')).rejects.toMatchObject({ code: 'network' })
    expect(toJourneyError(new Error('boom')).code).toBe('unexpected')
  })
})

describe('repositories — what is sent, what is kept', () => {
  it('search asks the backend (2+ characters) and resolve posts the place id only', async () => {
    reply = (method, path) => path === '/places/search' ? { body: { data: [suggestionDto(), suggestionDto({ place_id: 'fake:noida-18', name: 'Sector 18, Noida' })], country_code: 'IN', provider: { name: 'fake', mode: 'development' } } } : method === 'POST' ? { body: resolvedDto() } : { body: {} }
    const repo = new ApiLocationRepository()
    expect(await repo.search('n')).toEqual([])
    expect(calls).toHaveLength(0)
    const found = await repo.search(' noida ')
    expect(found.map((l) => l.name)).toEqual(['Sector 62, Noida', 'Sector 18, Noida'])
    expect(last('GET', '/places/search').query.get('q')).toBe('noida')
    expect(last('GET', '/places/search').headers.Authorization).toBeUndefined()
    const resolved = await repo.resolve!(found[0])
    expect(last('POST', '/places/resolve').body).toEqual({ place_id: 'fake:noida-62' })
    expect(resolved.lat).toBe(28.628)
    const before = calls.length
    expect(await repo.resolve!(resolved)).toBe(resolved) // already has coordinates: nothing to ask
    expect(calls).toHaveLength(before)
  })

  it('guests keep recent places for this tab only; signed-in customers read the backend list', async () => {
    const repo = new ApiLocationRepository()
    const place = toResolved(toSuggestion(suggestionDto()), resolvedDto())
    await repo.remember(place)
    expect((await repo.recent()).map((l) => [l.name, l.kind])).toEqual([['Sector 62, Noida', 'recent']])
    // Only a place that was searched for is remembered: never the device's position, never a saved place.
    await repo.remember({ id: 'device-current', name: 'Current location', sub: 'From this device', kind: 'current', lat: 28.61234, lng: 77.23456, source: 'device' })
    await repo.remember({ id: 'addr-1', name: 'Home', sub: '', kind: 'saved', lat: 28.6, lng: 77.3, source: 'saved-address', savedLocationId: 'df2d09ac-56ca-4dbe-be7f-ba5ea89df14e' })
    expect((await repo.recent()).map((l) => l.name)).toEqual(['Sector 62, Noida'])
    expect(calls).toHaveLength(0)
    expect(localStorage.length).toBe(0) // nothing outlives the tab

    tokens.set('customer', 'customer-token')
    reply = () => ({ body: [{ id: 'r1', label: 'Sector 18 Metro', formatted_address: 'Sector 18 Metro Station, Noida', location: { latitude: 28.5708, longitude: 77.3261 }, place: null, country_code: 'IN', timezone: 'Asia/Kolkata' }, { id: 'r2', label: 'Sector 18, Noida', formatted_address: 'Sector 18, Noida, Uttar Pradesh 201301, India', location: { latitude: 28.5708, longitude: 77.3261 }, place: { provider: 'fake', id: 'fake:noida-18' }, country_code: 'IN', timezone: 'Asia/Kolkata' }] })
    const recent = await repo.recent()
    expect(recent).toMatchObject([{ name: 'Sector 18 Metro', source: 'recent', lat: 28.5708, lng: 77.3261 }, { name: 'Sector 18, Noida', source: 'recent', placeId: 'fake:noida-18' }])
    expect(recent[0].placeId).toBeUndefined()
    expect(last('GET', '/customer/recent-locations').headers.Authorization).toBe('Bearer customer-token')
    await repo.remember(place) // the backend records the places of a planned journey itself
    expect(calls.filter((c) => c.method !== 'GET')).toHaveLength(0)
  })

  it('planning sends both ends and the departure; the guest token is kept for the tab and sent as a header, never in a URL', async () => {
    reply = (method) => method === 'POST' ? { status: 201, body: journeyDto({ guest_token: TOKEN }) } : { body: journeyDto() }
    const repo = new ApiJourneyRepository()
    const journey = await repo.create({ origin: toResolved(toSuggestion(suggestionDto()), resolvedDto()), destination: { id: 'device-current', name: 'Current location', sub: '', kind: 'current', lat: 28.57, lng: 77.33, source: 'device' }, departureAt: '2026-10-05T08:30:00.000Z' })
    expect(last('POST', '/journeys/plan').body).toEqual({ origin: { place_id: 'fake:noida-62' }, destination: { lat: 28.57, lng: 77.33, label: 'Current location' }, departure_at: '2026-10-05T08:30:00.000Z' })
    expect(Object.keys(last('POST', '/journeys/plan').body as object)).not.toEqual(expect.arrayContaining(['customer_id', 'market_id', 'provider', 'route']))
    expect(journey.backend).toBe(true)
    expect(journeyTokens.get(JOURNEY)).toBe(TOKEN)
    expect(JSON.stringify(journey)).not.toContain(TOKEN)
    expect(localStorage.length).toBe(0)

    expect((await repo.get(JOURNEY))?.id).toBe(JOURNEY)
    const read = last('GET', `/journeys/${JOURNEY}`)
    expect(read.headers['X-Journey-Token']).toBe(TOKEN)
    expect(read.query.toString()).toBe('')
    expect((await repo.recent()).map((j) => `${j.origin.name} → ${j.destination.name}`)).toEqual(['Sector 62, Noida → Sector 18, Noida']) // as the backend named the two ends
    expect(await repo.update(journey)).toBe(journey)
    expect(calls.filter((c) => c.method === 'PATCH' || c.method === 'PUT')).toHaveLength(0)
    await repo.remove(JOURNEY)
    expect(await repo.recent()).toEqual([])
    expect(journeyTokens.get(JOURNEY)).toBeNull()
  })

  it('a journey that is not this visitor\'s is "not found"; an expired one says so — and keeps saying so (its token stays)', async () => {
    const repo = new ApiJourneyRepository()
    journeyTokens.set(JOURNEY, TOKEN)
    reply = () => error(404, 'journey_not_found', 'This journey was not found or has ended.')
    expect(await repo.get(JOURNEY)).toBeNull()
    reply = () => error(410, 'journey_expired', 'This journey has expired. Plan it again to continue.')
    await expect(repo.get(JOURNEY)).rejects.toMatchObject({ code: 'expired' })
    // The backend says "expired" only to the owner: the token is sent again when the same link is opened once more.
    expect(journeyTokens.get(JOURNEY)).toBe(TOKEN)
    await expect(repo.get(JOURNEY)).rejects.toMatchObject({ code: 'expired' })
    expect(last('GET', `/journeys/${JOURNEY}`).headers['X-Journey-Token']).toBe(TOKEN)
  })

  it('the repositories follow the journey mode; tests and share builds always run the browser providers', () => {
    expect(selectJourneyRepositories('api').journeys).toBeInstanceOf(ApiJourneyRepository)
    expect(selectJourneyRepositories('api').mode).toBe('api')
    expect(selectJourneyRepositories('mock').journeys).toBeInstanceOf(MockJourneyRepository)
    expect(selectJourneyRepositories().mode).toBe('mock')
  })
})

describe('discovery along a backend journey', () => {
  const journey = toJourney(journeyDto())

  it('asks the backend with the token, maps the page and remembers the route context of what it found', async () => {
    journeyTokens.set(JOURNEY, TOKEN)
    reply = (_m, _p, q) => ({ body: q.get('page[number]') === '2' ? pageDto([rowDto('pizza-point', 'Pizza Point', { route_progress: 1, detour: { seconds: 60, minutes: 1, distance_meters: 0 } })], { current_page: 2, last_page: 2, total: 2, cached: true }) : pageDto([rowDto('burger-hub', 'Burger Hub')], { last_page: 2, total: 2 }) })
    const first = await restaurantRepository.getRestaurantsForJourney(journey, { sort: 'lowestDetour', corridorM: 4000, filters: { detourTime: 20 } })
    const call = last('GET', `/journeys/${JOURNEY}/restaurants`)
    expect(call.headers['X-Journey-Token']).toBe(TOKEN)
    expect(Object.fromEntries(call.query)).toEqual({ sort: 'lowest_detour', corridor_width_meters: '4000', max_detour_minutes: '20', 'page[number]': '1', 'page[size]': '6' })
    expect(call.query.toString()).not.toMatch(/28\.6|77\.3/) // no coordinate ever travels in the URL
    expect(first).toMatchObject({ total: 2, nextCursor: '2', corridorM: 3000, emptyReason: null })
    expect(first.searchMeta).toMatchObject({ cached: false, providerMode: 'development', providerName: 'fake', maxDetourMin: 15, detourRequested: 1 })
    expect(first.items[0]).toMatchObject({ atArrival: true, detourDurationMin: 2 })

    const second = await discoverAlongJourney(journey, { cursor: first.nextCursor })
    expect(second).toMatchObject({ nextCursor: null, searchMeta: { cached: true } })
    expect(storedRouteContext(JOURNEY, 'burger-hub')).toMatchObject({ detourDurationMin: 2, estimatedArrival: '2026-10-05T06:32:00+00:00' })
    expect(storedRouteContext(JOURNEY, 'pizza-point')).toMatchObject({ detourDurationMin: 1 })
    expect(storedRouteContext('another-journey', 'burger-hub')).toBeNull()
  })

  it('the restaurant page and the cart get the backend figures for a backend journey — or nothing, never a straight-line estimate', async () => {
    reply = () => ({ body: pageDto([rowDto('burger-hub', 'Burger Hub')]) })
    const page = await discoverAlongJourney(journey, {})
    const burger = page.items[0].restaurant
    expect(routeContextFor(burger, journey)).toMatchObject({ distanceFromRouteM: 60, detourDurationMin: 2, estimatedArrival: '2026-10-05T06:32:00+00:00', estimatedPickupReady: null, atArrival: true })
    expect(routeContextFor({ ...burger, slug: 'somewhere-else', lat: 28.6, lng: 77.35 }, journey)).toBeNull()
  })

  it('tells the reasons for an empty list apart', async () => {
    const reasons: Array<[JourneyRestaurantPageDto['meta']['empty_reason'], string | null, boolean]> = [['NO_SERVICE_COVERAGE', 'coverage', true], ['NO_RESTAURANTS_IN_CORRIDOR', 'corridor', false], ['NO_REACHABLE_RESTAURANTS', 'unreachable', false], ['FILTERS', 'filters', false]]
    for (const [reason, expected, unavailable] of reasons) {
      reply = () => ({ body: pageDto([], { empty_reason: reason }) })
      const page = await discoverAlongJourney(journey, {})
      expect(page.emptyReason).toBe(expected)
      expect(page.items).toEqual([])
      expect(page.availability?.supported === false).toBe(unavailable)
      if (unavailable) expect(page.availability).toMatchObject({ reason: 'area', messageKey: 'market.unavailable.route' })
    }
  })

  it('a browser (development) journey still uses the browser providers', async () => {
    const mock = { id: 'jrn-mock', origin: { name: 'Chandigarh', lat: 30.7333, lng: 76.7794, countryCode: 'IN' }, destination: { name: 'Jammu', lat: 32.7266, lng: 74.857, countryCode: 'IN' }, departureAt: null, route: { geometry: [[30.7333, 76.7794], [32.7266, 74.857]] as [number, number][], distanceKm: 300, durationMin: 340 } }
    await restaurantRepository.getRestaurantsForJourney(mock, {})
    expect(calls).toHaveLength(0)
    expect(restaurantRepository.getFilterDefinitions(mock).map((d) => d.id)).toContain('rating')
    expect(restaurantRepository.getFilterDefinitions(journey).map((d) => d.id)).toEqual(['cuisine', 'openNow', 'detourTime'])
  })
})

/* ------------------------------------------------------------------ the two pages on the backend repositories */
function App({ route }: { route: string }) {
  return (
    <MemoryRouter initialEntries={[route]}>
      <LocaleProvider locale="en-IN"><AuthProvider><ToastProvider><ProfileProvider><AccountProvider>
        <JourneyProvider repositories={selectJourneyRepositories('api')}>
          <CartProvider><OrdersProvider>
            <Routes>
              <Route path="/plan-journey" element={<PlanJourneyPage />} />
              <Route path="/restaurants" element={<RestaurantsPage />} />
            </Routes>
          </OrdersProvider></CartProvider>
        </JourneyProvider>
      </AccountProvider></ProfileProvider></ToastProvider></AuthProvider></LocaleProvider>
    </MemoryRouter>
  )
}
const placesReply = (path: string, query: URLSearchParams, body?: unknown): Reply | null => {
  if (path === '/places/search') { const q = (query.get('q') ?? '').toLowerCase(); return { body: { data: q.includes('18') ? [suggestionDto({ place_id: 'fake:noida-18', name: 'Sector 18, Noida' })] : [suggestionDto()], country_code: 'IN', provider: { name: 'fake', mode: 'development' } } } }
  if (path === '/places/resolve') { const id = (body as { place_id: string }).place_id; return { body: resolvedDto({ place: { provider: 'fake', id, name: id === 'fake:noida-18' ? 'Sector 18, Noida' : 'Sector 62, Noida', kind: 'landmark' }, location: { ...resolvedDto().location, latitude: id === 'fake:noida-18' ? 28.5708 : 28.628, longitude: id === 'fake:noida-18' ? 77.3261 : 77.3649 } }) } }
  return null
}

describe('Plan a Journey and Restaurants on the backend repositories', () => {
  const choose = async (user: ReturnType<typeof userEvent.setup>, label: RegExp, text: string, name: string) => {
    const input = screen.getByRole('combobox', { name: label })
    await user.click(input); await user.type(input, text)
    await user.click(await screen.findByRole('option', { name: new RegExp(name) }))
    // the suggestion is resolved on the server before it becomes the chosen place
    await waitFor(() => expect(input.closest('.loc')).toHaveAttribute('data-source', 'place'))
    expect(input).toHaveValue(name)
  }

  it('plans through the backend: suggestions, server-side resolution, the provider route with its provenance', async () => {
    const user = userEvent.setup()
    reply = (method, path, query) => {
      const body = calls[calls.length - 1]?.body
      return placesReply(path, query, body) ?? (method === 'POST' && path === '/journeys/plan' ? { status: 201, body: journeyDto({ guest_token: TOKEN }) } : { body: {} })
    }
    render(<App route="/plan-journey" />)
    expect(screen.getByTestId('pj-privacy')).toHaveTextContent(/keeps no journey history/i)
    await choose(user, /starting point/i, 'Sector 62', 'Sector 62, Noida')
    await choose(user, /destination/i, 'Sector 18', 'Sector 18, Noida')
    await user.click(screen.getByRole('button', { name: /find restaurants on route/i }))

    expect(await screen.findByTestId('pj-distance')).toHaveTextContent(/9\.6 km.*development routing model/i)
    expect(screen.getByTestId('pj-duration')).toHaveTextContent(/17 min.*development routing model/i)
    expect(screen.queryByText(/mock development data/i)).toBeNull()
    expect(screen.getByTestId('pj-valid')).toHaveTextContent(/stays valid until/i)
    const figure = document.querySelector('figure.rp')!
    expect(figure.getAttribute('data-route-mode')).toBe('development')
    expect(figure.getAttribute('data-route-points')).toBe('4') // the provider's line, not two end points
    expect(last('POST', '/journeys/plan').body).toEqual({ origin: { place_id: 'fake:noida-62' }, destination: { place_id: 'fake:noida-18' } })
    expect(calls.filter((c) => c.path === '/places/resolve')).toHaveLength(2)
    expect(journeyTokens.get(JOURNEY)).toBe(TOKEN)
  })

  it('says why a journey cannot be planned and estimates nothing', async () => {
    const user = userEvent.setup()
    let failure: Reply = error(422, 'route_not_found', 'No road route is available between these locations.')
    reply = (method, path, query) => placesReply(path, query, calls[calls.length - 1]?.body) ?? (method === 'POST' && path === '/journeys/plan' ? failure : { body: {} })
    render(<App route="/plan-journey" />)
    await choose(user, /starting point/i, 'Sector 62', 'Sector 62, Noida')
    await choose(user, /destination/i, 'Sector 18', 'Sector 18, Noida')
    await user.click(screen.getByRole('button', { name: /find restaurants on route/i }))
    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent(/no road route is available/i)
    expect(alert).toHaveAttribute('data-error-code', 'no-route')
    expect(screen.queryByTestId('pj-distance')).toBeNull()
    expect(document.querySelector('figure.rp')).toBeNull()

    failure = error(503, 'routing_temporarily_unavailable', 'Route planning is temporarily unavailable. Please try again shortly.')
    await user.click(screen.getByRole('button', { name: /find restaurants on route/i }))
    await waitFor(() => expect(screen.getByRole('alert')).toHaveAttribute('data-error-code', 'provider-unavailable'))
    expect(screen.getByRole('alert')).toHaveTextContent(/temporarily unavailable.*try again/i)
    expect(screen.queryByTestId('pj-distance')).toBeNull()
  })

  it('lists the restaurants the backend found with their actual detour, arrival estimate and state on arrival', async () => {
    journeyTokens.set(JOURNEY, TOKEN)
    const closedRow = rowDto('wok-express', 'Wok Express', { route_progress: 0.23, detour: { seconds: 150, minutes: 3, distance_meters: 880 }, open_at_arrival: false, orderable_at_arrival: false, availability_at_arrival: { open_now: false, open_state: 'CLOSED', accepting_orders: true, orderable: false, reason: 'CLOSED_NOW', closes_at: null, opens_next_at: '2026-10-05T12:30:00+00:00', checked_at: '2026-10-05T06:35:00+00:00' } })
    reply = (_m, path) => path === `/journeys/${JOURNEY}` ? { body: journeyDto() } : path.endsWith('/restaurants') ? { body: pageDto([rowDto('burger-hub', 'Burger Hub'), closedRow]) } : { body: {} }
    render(<App route={`/restaurants?journey=${JOURNEY}`} />)

    const burger = (await screen.findByRole('heading', { name: 'Burger Hub' })).closest('li')!
    expect(burger).toHaveAttribute('data-detour-min', '2')
    expect(within(burger).getByText(/open when you arrive/i)).toBeInTheDocument()
    expect(burger.querySelector('.rcard__detour')).toHaveTextContent(/\+2m\s*detour/i)
    expect(within(burger).getByText(/estimated arrival/i)).toBeInTheDocument()
    expect(burger.querySelector('.rcard__rating')).toBeNull() // no review figures exist yet: none is shown, not even a sample
    const wok = screen.getByRole('heading', { name: 'Wok Express' }).closest('li')!
    expect(within(wok).getByText(/closed when you arrive/i)).toBeInTheDocument()
    expect(wok).toHaveAttribute('data-open-at-arrival', 'false')

    expect(screen.getByTestId('jctx-route')).toHaveAttribute('data-route-mode', 'development')
    expect(screen.getByTestId('jctx-route')).toHaveTextContent(/development route model/i)
    const note = screen.getByTestId('estimate-note')
    expect(note).toHaveTextContent(/development routing model — synthetic roads, not real road data/i)
    expect(note).toHaveTextContent(/detours up to 15 min./i) // minutes spelled out: "15m" would read as metres
    const range = screen.getByTestId('corridor-range')
    expect([range.getAttribute('min'), range.getAttribute('max'), (range as HTMLInputElement).value]).toEqual(['500', '10000', '3000'])
    const sorts = within(screen.getByRole('combobox', { name: /sort by/i })).getAllByRole('option').map((o) => o.textContent)
    expect(sorts).toEqual(['Recommended', 'Lowest detour', 'Order along route', 'Nearest to route', 'Fastest pickup']) // no "Highest rated": there is no reviews backend
    expect(screen.queryByText(/rating/i, { selector: 'h3' })).toBeNull()
    expect(screen.getByText(/open when i arrive/i)).toBeInTheDocument()
    expect(last('GET', `/journeys/${JOURNEY}/restaurants`).headers['X-Journey-Token']).toBe(TOKEN)
  })

  it('shows a different state for no coverage, an empty corridor, filters, an expired journey and a provider outage', async () => {
    journeyTokens.set(JOURNEY, TOKEN)
    let restaurants: Reply = { body: pageDto([], { empty_reason: 'NO_SERVICE_COVERAGE', coverage: { along_route: 'NO_SERVICE_COVERAGE', serviceable_areas: 0 } }) }
    reply = (_m, path) => path === `/journeys/${JOURNEY}` ? { body: journeyDto() } : path.endsWith('/restaurants') ? restaurants : { body: {} }
    const view = render(<App route={`/restaurants?journey=${JOURNEY}`} />)
    expect(await screen.findByTestId('market-unavailable')).toHaveTextContent(/does not cover this route yet/i)
    view.unmount(); sessionStorage.removeItem('fotg.journey.current')

    restaurants = { body: pageDto([], { empty_reason: 'NO_RESTAURANTS_IN_CORRIDOR' }) }
    const second = render(<App route={`/restaurants?journey=${JOURNEY}`} />)
    const empty = await screen.findByTestId('discovery-empty')
    expect(empty).toHaveAttribute('data-empty-reason', 'corridor')
    expect(empty).toHaveTextContent(/no restaurants found near this route/i)
    expect(within(empty).getByRole('button', { name: /widen search to 6 km/i })).toBeInTheDocument()
    second.unmount(); sessionStorage.removeItem('fotg.journey.current')

    restaurants = { body: pageDto([], { empty_reason: 'FILTERS' }) }
    const third = render(<App route={`/restaurants?journey=${JOURNEY}`} />)
    const filtered = await screen.findByTestId('discovery-empty')
    expect(filtered).toHaveAttribute('data-empty-reason', 'filters')
    expect(filtered).toHaveTextContent(/none passes the filters, the detour limit or the search text/i)
    expect(within(filtered).getByRole('button', { name: /allow detours up to 30 min/i })).toBeInTheDocument()
    third.unmount(); sessionStorage.removeItem('fotg.journey.current')

    restaurants = error(503, 'routing_temporarily_unavailable', 'Route planning is temporarily unavailable. Please try again shortly.')
    const fourth = render(<App route={`/restaurants?journey=${JOURNEY}`} />)
    const outage = await screen.findByRole('alert')
    expect(outage).toHaveAttribute('data-error-code', 'provider-unavailable')
    expect(outage).toHaveTextContent(/route planning is temporarily unavailable/i)
    expect(within(outage).getByRole('button', { name: /try again/i })).toBeInTheDocument()
    expect(document.querySelectorAll('li.rcard')).toHaveLength(0) // no card is made up
    fourth.unmount(); sessionStorage.removeItem('fotg.journey.current')

    restaurants = error(410, 'journey_expired', 'This journey has expired. Plan it again to continue.')
    render(<App route={`/restaurants?journey=${JOURNEY}`} />)
    const expired = await screen.findByTestId('journey-expired')
    expect(expired).toHaveTextContent(/this journey has expired/i)
    expect(within(expired).getByRole('button', { name: /plan it again/i })).toBeInTheDocument()
  })

  it('a journey that cannot be read (another tab, another account) is "not found on this device"', async () => {
    reply = () => error(404, 'journey_not_found', 'This journey was not found or has ended.')
    render(<App route={`/restaurants?journey=${JOURNEY}`} />)
    const missing = await screen.findByTestId('journey-missing')
    expect(missing).toHaveTextContent(/journey not found on this device/i)
    expect(missing).toHaveTextContent(/browser tab that planned it/i)
    expect(calls.some((c) => c.path.endsWith('/restaurants'))).toBe(false)
    expect(screen.queryAllByRole('listitem').filter((li) => li.classList.contains('rcard'))).toHaveLength(0) // no general results under the panel
  })

  it('a journey that cannot be read RIGHT NOW is not called "not found": it says why and can be tried again', async () => {
    journeyTokens.set(JOURNEY, TOKEN)
    reply = () => error(503, 'routing_temporarily_unavailable', 'Route planning is temporarily unavailable.')
    render(<App route={`/restaurants?journey=${JOURNEY}`} />)
    const panel = await screen.findByTestId('journey-unreachable')
    expect(panel).toHaveTextContent(/this journey could not be loaded/i)
    expect(panel).toHaveTextContent(/temporarily unavailable/i)
    expect(screen.queryByTestId('journey-missing')).not.toBeInTheDocument()
    expect(calls.some((c) => c.path.endsWith('/restaurants'))).toBe(false)

    reply = (_method, path) => (path.endsWith('/restaurants') ? { body: pageDto([rowDto('burger-hub', 'Burger Hub')]) } : { body: journeyDto() })
    await userEvent.click(within(panel).getByRole('button', { name: /try again/i }))
    expect(await screen.findByRole('heading', { name: 'Burger Hub' })).toBeInTheDocument()
    expect(screen.queryByTestId('journey-unreachable')).not.toBeInTheDocument()
    expect(last('GET', `/journeys/${JOURNEY}`).headers['X-Journey-Token']).toBe(TOKEN)
  })
})

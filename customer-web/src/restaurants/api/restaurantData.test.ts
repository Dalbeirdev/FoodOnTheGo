/**
 * Customer restaurants on the backend (Module 23): the mapping of the public API onto the page model, and the
 * snapshot the customer pages read. The network is stubbed with payloads in the shape of the real API
 * (OpenAPI: PublicRestaurant, PublicRestaurantPage, CuisineList).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { computeAvailability, customerRestaurants } from '../../repositories/mock/restaurants'
import { apiRestaurants, fetchRestaurantBySlug, hasRestaurantSnapshot, hydrateRestaurants, resetRestaurantData, restaurantCuisines, toRestaurant, type PublicRestaurantDto } from './restaurantData'

const BASE = 'http://127.0.0.1:8001/api/v1'
const dto = (patch: Partial<PublicRestaurantDto> = {}): PublicRestaurantDto => ({
  id: '0a000000-0000-4000-8000-0000000000a1', slug: 'test-kitchen', name: 'Test Kitchen', branch_label: 'Sector 62 · Noida', short_description: 'Short text.', description: 'Long text.',
  cuisines: [{ code: 'north_indian', name: 'North Indian' }, { code: 'biryani', name: 'Biryani' }],
  features: [{ code: 'parking', name: 'Parking', category: 'FACILITY' }, { code: 'pure_veg', name: 'Pure Veg', category: 'DIETARY' }],
  price_level: 3, phone: '+911204567890', email: 'hello@test-kitchen.example', website: 'https://test-kitchen.example',
  address: { formatted: 'Sector 62, Noida, Uttar Pradesh 201309, India', line1: 'Sector 62', postal_code: '201309', city: 'Noida', city_slug: 'noida', region: 'Uttar Pradesh', region_code: 'IN-UP', country_code: 'IN' },
  location: { latitude: 28.6285, longitude: 77.3652 }, timezone: 'Asia/Kolkata', currency: 'INR',
  images: { logo: null, cover: { url: '/images/cover.jpg', alt_text: 'Cover' }, gallery: [{ url: '/images/cover.jpg', alt_text: null }, { url: '/images/two.jpg', alt_text: null }] },
  hours: { timezone: 'Asia/Kolkata', weekly: [0, 1, 2, 3, 4, 5, 6].map((day_of_week) => ({ day_of_week, periods: day_of_week === 1 ? [] : [{ opens_at: '08:00', closes_at: '23:30' }] })), special: [{ date: '2026-11-08', is_closed: true, periods: [], note: 'Diwali' }] },
  pickup: { methods: [{ method: 'COUNTER', instructions: null, requires_vehicle_info: false }, { method: 'CURBSIDE', instructions: 'Bay 3', requires_vehicle_info: true }], asap: true, scheduled: true, default_prep_minutes: 12, minimum_lead_minutes: 15, instructions: 'Show your pickup code.' },
  availability: { open_now: true, open_state: 'OPEN', accepting_orders: true, orderable: true, reason: null, closes_at: '2026-10-06T18:00:00+00:00', opens_next_at: null, checked_at: '2026-10-06T06:00:00+00:00' },
  ...patch,
})

type Reply = { status?: number; body: unknown } | 'network'
let calls: Array<{ path: string; auth: string | null }> = []
let reply: (path: string) => Reply = () => ({ body: {} })
beforeEach(() => {
  calls = []; localStorage.setItem('fotg.restaurant.mode', 'api'); resetRestaurantData()
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const path = decodeURIComponent(url.replace(BASE, '')); calls.push({ path, auth: (init.headers as Record<string, string>).Authorization ?? null })
    const r = reply(path); if (r === 'network') throw new TypeError('Failed to fetch')
    return new Response(JSON.stringify(r.body), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } })
  }))
})
afterEach(() => { vi.unstubAllGlobals(); localStorage.removeItem('fotg.restaurant.mode'); resetRestaurantData() })

describe('toRestaurant — the public API onto the page model', () => {
  it('keeps what the backend said and invents nothing', () => {
    const r = toRestaurant(dto())
    expect(r).toMatchObject({
      id: 'test-kitchen', publicId: '0a000000-0000-4000-8000-0000000000a1', slug: 'test-kitchen', name: 'Test Kitchen', description: 'Long text.', shortDescription: 'Short text.',
      countryCode: 'IN', timezone: 'Asia/Kolkata', currency: 'INR', lat: 28.6285, lng: 77.3652, priceLevel: 3, prepTimeMin: 12,
      cuisines: ['North Indian', 'Biryani'], features: ['Parking', 'Pure Veg'], images: ['/images/cover.jpg', '/images/two.jpg'], image: '/images/cover.jpg',
      phone: '+911204567890', website: 'https://test-kitchen.example', publicEmail: 'hello@test-kitchen.example', pickupInstructions: 'Show your pickup code.',
      status: 'active', acceptingOrders: true, unavailableReason: null,
      // no reviews backend and no route in this API: no rating, no distance, no detour
      rating: 0, reviewCount: 0, ratingIsSample: false, distance: '', time: '', detour: '',
    })
    expect(r.address).toEqual({ formatted: 'Sector 62, Noida, Uttar Pradesh 201309, India', line1: 'Sector 62', locality: 'Noida', adminArea: 'Uttar Pradesh', postalCode: '201309', countryCode: 'IN' })
    expect(r.pickupMethods).toEqual([{ type: 'counter', instructions: null, requiresVehicleInfo: false }, { type: 'curbside', instructions: 'Bay 3', requiresVehicleInfo: true }])
    expect(r.openingHours.periods).toHaveLength(6); expect(r.openingHours.periods.some((p) => p.day === 1)).toBe(false)
    expect(r.openingHours.periods[0]).toEqual({ day: 0, open: '08:00', close: '23:30' })
    expect(r.openingHours.special).toEqual([{ date: '2026-11-08', closed: true, periods: [], note: 'Diwali' }])
  })

  it('a restaurant the development data knows keeps the id its sample menu uses; its rating is marked as a sample', () => {
    const r = toRestaurant(dto({ slug: 'burger-hub' }))
    expect(r.id).not.toBe(''); expect(r.publicId).toBe('0a000000-0000-4000-8000-0000000000a1')
    expect(r.ratingIsSample).toBe(true); expect(r.reviewCount).toBeGreaterThan(0)
  })

  it('takes the reasons a customer cannot order from the backend', () => {
    const availability = dto().availability
    expect(toRestaurant(dto({ availability: { ...availability, accepting_orders: false, orderable: false, reason: 'NOT_ACCEPTING_ORDERS' } }))).toMatchObject({ status: 'active', acceptingOrders: false, unavailableReason: 'NOT_ACCEPTING_ORDERS' })
    // the restaurant itself accepts orders — FoodOnTheGo is not serving its area
    expect(toRestaurant(dto({ availability: { ...availability, orderable: false, reason: 'AREA_UNAVAILABLE' } }))).toMatchObject({ status: 'active', acceptingOrders: false, unavailableReason: 'AREA_UNAVAILABLE' })
    expect(toRestaurant(dto({ availability: { ...availability, orderable: false, reason: 'PICKUP_UNAVAILABLE' } }))).toMatchObject({ acceptingOrders: false, unavailableReason: 'PICKUP_UNAVAILABLE' })
    expect(toRestaurant(dto({ availability: { ...availability, open_now: false, open_state: 'TEMPORARILY_CLOSED', orderable: false, reason: 'TEMPORARILY_CLOSED' } }))).toMatchObject({ status: 'temporarily_closed' })
    // closed right now is not a reason to hide the order button for later
    expect(toRestaurant(dto({ availability: { ...availability, open_now: false, open_state: 'CLOSED', orderable: false, reason: 'CLOSED_NOW' } }))).toMatchObject({ status: 'active', acceptingOrders: true })
  })

  it('missing optional values fall back safely', () => {
    const r = toRestaurant(dto({ description: null, price_level: null, images: { logo: null, cover: null, gallery: [] }, pickup: { ...dto().pickup, default_prep_minutes: null, methods: [] } }))
    expect(r).toMatchObject({ description: 'Short text.', priceLevel: 2, prepTimeMin: 15, images: [], image: '', pickupMethods: [] })
  })

  it('"open now" recomputed from the hours agrees with what the backend said at the moment it answered', () => {
    // Tuesday 2026-10-06 11:30 IST: open until 23:30 IST = 18:00 UTC
    const open = dto()
    expect(computeAvailability(toRestaurant(open), open.availability.checked_at)).toMatchObject({ status: 'open', nextChangeAt: '2026-10-06T18:00:00.000Z' })
    // Monday has no periods: closed, opening Tuesday 08:00 IST = 02:30 UTC
    expect(computeAvailability(toRestaurant(open), '2026-10-05T06:00:00+00:00')).toMatchObject({ status: 'closed', nextChangeAt: '2026-10-06T02:30:00.000Z' })
    // the special closed date
    expect(computeAvailability(toRestaurant(open), '2026-11-08T06:00:00+00:00').status).toBe('closed')
  })
})

describe('the restaurant snapshot', () => {
  const page = (items: PublicRestaurantDto[], current: number, last: number) => ({ data: items, links: {}, meta: { current_page: current, last_page: last, total: 3 } })
  const CUISINES = { data: [{ code: 'north_indian', name: 'North Indian', slug: 'north-indian', restaurants: 2 }] }

  it('loads every page of the public list and the cuisines — without a token', async () => {
    reply = (path) => (path.startsWith('/cuisines') ? { body: CUISINES } : path.includes('page[number]=2') ? { body: page([dto({ slug: 'c', name: 'C' })], 2, 2) } : { body: page([dto({ slug: 'a', name: 'A' }), dto({ slug: 'b', name: 'B' })], 1, 2) })
    expect(hasRestaurantSnapshot()).toBe(false); expect(apiRestaurants()).toEqual([])
    expect(await hydrateRestaurants()).toBe(true)
    expect(calls.map((c) => c.path)).toEqual(['/restaurants?page[size]=100&page[number]=1', '/restaurants?page[size]=100&page[number]=2', '/cuisines'])
    expect(calls.every((c) => c.auth === null)).toBe(true)
    expect(apiRestaurants()?.map((r) => r.slug)).toEqual(['a', 'b', 'c']); expect(restaurantCuisines()).toEqual(CUISINES.data)
    // the customer pages read exactly this list — never the development fixtures
    expect(customerRestaurants().map((r) => r.slug)).toEqual(['a', 'b', 'c'])
  })

  it('"FoodOnTheGo does not serve customers here" is an answer: the list is empty, no fixture restaurant appears', async () => {
    reply = () => ({ status: 409, body: { error: { code: 'market_unavailable', message: 'Not available here.', details: {} } } })
    expect(await hydrateRestaurants()).toBe(true)
    expect(apiRestaurants()).toEqual([]); expect(customerRestaurants()).toEqual([])
  })

  it('when the backend cannot be reached nothing is invented: no snapshot → false; an earlier snapshot is kept', async () => {
    reply = () => 'network'
    expect(await hydrateRestaurants()).toBe(false); expect(hasRestaurantSnapshot()).toBe(false); expect(customerRestaurants()).toEqual([])
    reply = (path) => (path.startsWith('/cuisines') ? { body: CUISINES } : { body: page([dto({ slug: 'a' })], 1, 1) })
    expect(await hydrateRestaurants()).toBe(true)
    reply = () => 'network'
    expect(await hydrateRestaurants()).toBe(true); expect(apiRestaurants()?.map((r) => r.slug)).toEqual(['a'])
  })

  it('one restaurant is always asked from the backend: an update replaces it, a 404 removes it from the list', async () => {
    reply = (path) => (path.startsWith('/cuisines') ? { body: CUISINES } : { body: page([dto({ slug: 'a', name: 'A' }), dto({ slug: 'b', name: 'B' })], 1, 1) })
    await hydrateRestaurants()
    reply = () => ({ body: dto({ slug: 'a', name: 'A renamed' }) })
    expect((await fetchRestaurantBySlug('a'))?.name).toBe('A renamed'); expect(calls.at(-1)).toEqual({ path: '/restaurants/a', auth: null })
    expect(apiRestaurants()?.find((r) => r.slug === 'a')?.name).toBe('A renamed')
    // suspended / unapproved / never existed: the same 404 — and it disappears from the list as well
    reply = () => ({ status: 404, body: { error: { code: 'restaurant_not_found', message: 'Not found.', details: {} } } })
    expect(await fetchRestaurantBySlug('b')).toBeNull(); expect(apiRestaurants()?.map((r) => r.slug)).toEqual(['a'])
    // the request itself failing is not "not found": the last known version can still be read
    reply = () => 'network'
    expect((await fetchRestaurantBySlug('a'))?.name).toBe('A renamed')
    await expect(fetchRestaurantBySlug('unknown')).rejects.toMatchObject({ kind: 'network' })
  })

  it('in mock mode (unit tests, share builds) the snapshot is not used at all', async () => {
    localStorage.setItem('fotg.restaurant.mode', 'mock')
    expect(apiRestaurants()).toBeNull(); expect(restaurantCuisines()).toBeNull()
    expect(customerRestaurants().length).toBeGreaterThan(0) // the development fixtures
  })
})

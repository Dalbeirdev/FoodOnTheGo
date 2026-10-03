/**
 * Admin restaurant adapter for the backend (Module 23). The network is stubbed with payloads in the shape of the real
 * API (OpenAPI: AdminRestaurantPage, AdminRestaurant); the tests check what is sent and what reaches the screens.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { tokens } from '../../api/client'
import { ApiAdminRestaurantRepository } from './ApiAdminRestaurantRepository'

const BASE = 'http://127.0.0.1:8001/api/v1'
const ORG = '0a000000-0000-4000-8000-00000000000a'; const L1 = '0a000000-0000-4000-8000-0000000000a1'; const L2 = '0a000000-0000-4000-8000-0000000000a2'; const MARKET = '0b000000-0000-4000-8000-0000000000b1'
const SUMMARY = {
  id: L1, slug: 'test-kitchen', name: 'Test Kitchen', branch_label: null, status: 'UNDER_REVIEW', operational_status: 'OPERATING', accepting_orders: true,
  organization: { id: ORG, name: 'Test Group', status: 'SUSPENDED', locations: 2 }, market: { id: MARKET, country_code: 'IN' }, city: { id: 'c1', name: 'Noida' }, region_code: 'IN-UP', in_service_area: true,
  cuisines: ['North Indian', 'Biryani'], allowed_transitions: ['APPROVED', 'REJECTED', 'DRAFT'], submitted_at: '2026-09-14T08:00:00+00:00', approved_at: null, created_at: '2026-09-10T08:00:00+00:00', updated_at: null, version: 2,
}
const detail = (patch: Record<string, unknown> = {}) => ({
  id: L1, slug: 'test-kitchen', name: 'Test Kitchen', branch_label: 'Sector 62 · Noida', short_description: 'Short.', description: 'Long.', pickup_instructions: null,
  cuisines: [{ code: 'north_indian', name: 'North Indian' }], features: [{ code: 'parking', name: 'Parking', category: 'FACILITY' }], price_level: 3, phone: '+911204567890', email: null, website: null,
  address: { formatted: 'Sector 62, Noida, Uttar Pradesh 201309, India', line1: null, postal_code: '201309', city: 'Noida', city_slug: 'noida', region: 'Uttar Pradesh', region_code: 'IN-UP', country_code: 'IN' },
  location: { latitude: 28.6285, longitude: 77.3652 }, timezone: 'Asia/Kolkata', currency: 'INR', images: [{ id: 'i1', type: 'COVER', url: '/images/cover.jpg' }],
  status: 'UNDER_REVIEW', status_note: null, rejection_category: null, operational_status: 'OPERATING', accepting_orders: true, pause_reason: 'Kitchen at capacity', paused_until: null,
  hours: { version: 1, weekly: [{ day_of_week: 1, periods: [{ opens_at: '08:00', closes_at: '22:00' }] }], special: [] }, version: 2,
  organization: { id: ORG, name: 'Test Group', legal_name: 'Test Group Pvt. Ltd.', status: 'APPROVED', status_note: null, allowed_transitions: ['SUSPENDED'], version: 5 },
  market: { id: MARKET, country_code: 'IN', name: 'India' }, city: { id: 'c1', name: 'Noida', status: 'ACTIVE' }, region: { code: 'IN-UP', name: 'Uttar Pradesh', status: 'ACTIVE' }, service_area: { id: 'sa1', name: 'Noida · NH24', status: 'ACTIVE' },
  pickup: { default_prep_minutes: 12 }, availability: { visible_to_customers: false, open_now: true, open_state: 'OPEN', accepting_orders: true, orderable: false, reason: 'LOCATION_NOT_APPROVED' },
  allowed_transitions: ['APPROVED', 'REJECTED', 'DRAFT'], can_manage: true, created_at: '2026-09-10T08:00:00+00:00',
  organization_locations: [{ id: L1, name: 'Test Kitchen', branch_label: 'Sector 62 · Noida', city: 'Noida', status: 'UNDER_REVIEW', operational_status: 'OPERATING', accepting_orders: true, timezone: 'Asia/Kolkata', currency: 'INR', version: 2 }, { id: L2, name: 'Test Café', branch_label: null, city: 'Noida', status: 'SUSPENDED', operational_status: 'OPERATING', accepting_orders: true, timezone: 'Asia/Kolkata', currency: 'INR', version: 1 }],
  staff: [{ id: 'm1', name: 'Asha Rao', email: 'asha@test.example', role: { code: 'OWNER', name: 'Owner' }, status: 'ACTIVE', all_locations: true, locations: [] }, { id: 'm2', name: 'Vik', email: 'vik@test.example', role: { code: 'ORDER_STAFF', name: 'Order staff' }, status: 'INVITED', all_locations: false, locations: [{ id: L1, name: 'Test Kitchen' }, { id: L2, name: 'Test Café' }] }],
  notes: [{ id: 'n2', note: 'Second note', author: 'Tom Okafor', about: 'location', created_at: '2026-10-02T09:00:00+00:00' }, { id: 'n1', note: 'First note', author: null, about: 'organization', created_at: '2026-10-01T09:00:00+00:00' }],
  readiness: [{ check: 'organization_approved', ok: true }, { check: 'owner_account', ok: false }],
  history: [{ id: 'h1', action: 'restaurant_location.submitted', actor_type: 'admin_user', actor_name: 'Tom Okafor', reason: null, occurred_at: '2026-09-14T08:00:00+00:00' }],
  ...patch,
})

type Call = { method: string; path: string; body: Record<string, unknown> | null; auth: string | null }
let calls: Call[] = []
let routes: Record<string, { status?: number; body?: unknown }> = {}
beforeEach(() => {
  calls = []; tokens.set('admin', 'admin-token')
  routes = {
    'GET /admin/restaurants': { body: { data: [SUMMARY], links: {}, meta: { total: 19, current_page: 2, per_page: 10 }, counts: {} } },
    'GET /admin/markets': { body: { data: [{ id: MARKET, country_code: 'IN' }] } },
    [`GET /admin/restaurants/${L1}`]: { body: detail() },
    [`POST /admin/restaurants/${L1}/status`]: { body: detail({ status: 'APPROVED', version: 3 }) },
    [`POST /admin/restaurant-organizations/${ORG}/status`]: { body: {} },
    [`POST /admin/restaurant-organizations/${ORG}/notes`]: { status: 201, body: {} },
  }
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const path = decodeURIComponent(url.replace(BASE, '')); const method = init.method ?? 'GET'
    calls.push({ method, path, body: init.body ? JSON.parse(init.body as string) : null, auth: (init.headers as Record<string, string>).Authorization ?? null })
    const r = routes[`${method} ${path.split('?')[0]}`]
    if (!r) return new Response(JSON.stringify({ error: { code: 'restaurant_not_found', message: 'Not found.', details: {} } }), { status: 404 })
    return new Response(JSON.stringify(r.body ?? {}), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } })
  }))
})
afterEach(() => { vi.unstubAllGlobals(); tokens.set('admin', null) })
const posts = (fragment: string) => calls.filter((c) => c.method === 'POST' && c.path.includes(fragment))

describe('ApiAdminRestaurantRepository — list', () => {
  it('asks the backend for the page, the stage, the search text and the market — with the admin token', async () => {
    const repo = new ApiAdminRestaurantRepository()
    const page = await repo.list({ tab: 'pending', query: ' dhokla ', market: 'IN', sort: 'name', page: 2, pageSize: 10 })
    expect(calls.map((c) => c.path)).toEqual(['/admin/markets?page[size]=100', `/admin/restaurants?page[number]=2&page[size]=10&sort=name&filter[stage]=PENDING&q=dhokla&filter[market]=${MARKET}`])
    expect(calls.every((c) => c.auth === 'Bearer admin-token')).toBe(true)
    expect(page).toMatchObject({ total: 19, page: 2, pageSize: 10 })
    await repo.list({ tab: 'all', market: 'all' })
    expect(calls.at(-1)?.path).toBe('/admin/restaurants?page[number]=1&page[size]=10&sort=-created_at')
    // an unknown market code is not turned into a filter the backend would refuse
    await repo.list({ tab: 'inactive', market: 'ZZ' })
    expect(calls.at(-1)?.path).toBe('/admin/restaurants?page[number]=1&page[size]=10&sort=-created_at&filter[stage]=INACTIVE')
  })

  it('a row carries the location and its organization with their own statuses; nothing the backend did not send is invented', async () => {
    const [row] = (await new ApiAdminRestaurantRepository().list({ tab: 'all' })).items
    expect(row).toMatchObject({ status: 'UNDER_REVIEW', organizationId: ORG, organizationName: 'Test Group', organizationStatus: 'SUSPENDED', locationCount: 2, createdAt: '2026-09-14T08:00:00+00:00', ordersTotal: 0, documents: [] })
    expect(row.restaurant).toMatchObject({ id: L1, slug: 'test-kitchen', name: 'Test Kitchen', cuisines: ['North Indian', 'Biryani'], countryCode: 'IN', rating: 0, reviewCount: 0 })
    expect(row.restaurant.address.locality).toBe('Noida')
  })
})

describe('ApiAdminRestaurantRepository — one restaurant', () => {
  it('maps what administrators see: allowed moves, organization, placement, readiness, staff, notes and history', async () => {
    const r = (await new ApiAdminRestaurantRepository().get(L1))!
    expect(r.live).toMatchObject({
      version: 2, allowedTransitions: ['APPROVED', 'REJECTED', 'DRAFT'], canManage: true, pauseReason: 'Kitchen at capacity', city: 'Noida', region: 'Uttar Pradesh', serviceArea: { name: 'Noida · NH24', status: 'ACTIVE' },
      organization: { id: ORG, name: 'Test Group', legalName: 'Test Group Pvt. Ltd.', status: 'APPROVED', allowedTransitions: ['SUSPENDED'], version: 5 },
      availability: { visibleToCustomers: false, orderable: false, reason: 'LOCATION_NOT_APPROVED' },
      readiness: [{ check: 'organization_approved', ok: true }, { check: 'owner_account', ok: false }],
    })
    expect(r.live?.staff).toEqual([{ id: 'm1', name: 'Asha Rao', email: 'asha@test.example', role: 'Owner', status: 'ACTIVE', locations: 'all' }, { id: 'm2', name: 'Vik', email: 'vik@test.example', role: 'Order staff', status: 'INVITED', locations: 'Test Kitchen, Test Café' }])
    expect(r.live?.history).toEqual([{ id: 'h1', action: 'restaurant_location.submitted', actor: 'Tom Okafor', reason: null, at: '2026-09-14T08:00:00+00:00' }])
    expect(r.internalNotes.map((n) => [n.text, n.by])).toEqual([['First note', '—'], ['Second note', 'Tom Okafor']]) // oldest first; the page shows the newest on top
    expect(r.locations.map((l) => [l.name, l.lifecycle, l.status])).toEqual([['Test Kitchen', 'UNDER_REVIEW', 'ACTIVE'], ['Test Café', 'SUSPENDED', 'SUSPENDED']])
    expect(r.restaurant).toMatchObject({ timezone: 'Asia/Kolkata', currency: 'INR', priceLevel: 3, prepTimeMin: 12, market: 'India', phone: '+911204567890' })
    expect(r.organizationStatus).toBe('APPROVED'); expect(r.documents).toEqual([])
  })

  it('a restaurant outside the administrator\'s markets (or that does not exist) is simply not found', async () => {
    expect(await new ApiAdminRestaurantRepository().get('0a000000-0000-4000-8000-00000000ffff')).toBeNull()
  })
})

describe('ApiAdminRestaurantRepository — lifecycle', () => {
  it('a status change reads the current version first and sends it', async () => {
    const repo = new ApiAdminRestaurantRepository()
    const approved = await repo.approve(L1)
    expect(calls.map((c) => `${c.method} ${c.path}`)).toEqual([`GET /admin/restaurants/${L1}`, `POST /admin/restaurants/${L1}/status`])
    expect(posts('/status')[0].body).toEqual({ status: 'APPROVED', version: 2, reason: null, public_reason: null, category: null })
    expect(approved.status).toBe('APPROVED'); expect(approved.live?.version).toBe(3)
  })

  it('a rejection carries the category, the internal reason and the explanation for the restaurant — separately', async () => {
    await new ApiAdminRestaurantRepository().reject(L1, 'ignored-actor', 'incomplete_documents', ' Please send the licence. ', ' Licence copy unreadable ')
    expect(posts('/status')[0].body).toEqual({ status: 'REJECTED', version: 2, reason: 'Licence copy unreadable', public_reason: 'Please send the licence.', category: 'INCOMPLETE_DOCUMENTS' })
  })

  it('suspending and reactivating', async () => {
    const repo = new ApiAdminRestaurantRepository()
    await repo.suspend(L1, 'ignored-actor', 'Two complaints', 'Suspended while we review a complaint.')
    expect(posts('/status')[0].body).toEqual({ status: 'SUSPENDED', version: 2, reason: 'Two complaints', public_reason: 'Suspended while we review a complaint.', category: null })
    await repo.reactivate(L1)
    expect(posts('/status')[1].body).toMatchObject({ status: 'APPROVED', version: 2 })
    // another location of the same organization, from the Locations tab
    routes[`GET /admin/restaurants/${L2}`] = { body: detail({ id: L2, version: 1 }) }; routes[`POST /admin/restaurants/${L2}/status`] = { body: detail({ id: L2 }) }
    await repo.setLocationStatus(L1, L2, 'SUSPENDED', 'ignored-actor', 'Hygiene')
    expect(posts(`/admin/restaurants/${L2}/status`)[0].body).toMatchObject({ status: 'SUSPENDED', version: 1, reason: 'Hygiene' })
    expect(calls.at(-1)).toMatchObject({ method: 'GET', path: `/admin/restaurants/${L1}` }) // the page shows the location it is on again
  })

  it('the organization has its own lifecycle and its own version', async () => {
    const r = await new ApiAdminRestaurantRepository().changeOrganizationStatus(L1, 'SUSPENDED', { reason: 'Payment dispute', publicReason: 'Your account is suspended.' })
    expect(posts(`/admin/restaurant-organizations/${ORG}/status`)[0].body).toEqual({ status: 'SUSPENDED', version: 5, reason: 'Payment dispute', public_reason: 'Your account is suspended.', category: null })
    expect(r.restaurant.id).toBe(L1)
  })

  it('a refusal of the backend reaches the screen (no optimistic success)', async () => {
    routes[`POST /admin/restaurants/${L1}/status`] = { status: 403, body: { error: { code: 'forbidden', message: 'You are not allowed to do this.', details: {} } } }
    await expect(new ApiAdminRestaurantRepository().suspend(L1, 'x', 'Reason')).rejects.toMatchObject({ status: 403, kind: 'forbidden' })
    routes[`POST /admin/restaurants/${L1}/status`] = { status: 409, body: { error: { code: 'invalid_status_transition', message: 'Status cannot change from DRAFT to APPROVED.', details: { from: 'DRAFT', allowed: ['SUBMITTED', 'INACTIVE'] } } } }
    await expect(new ApiAdminRestaurantRepository().approve(L1)).rejects.toMatchObject({ status: 409, code: 'invalid_status_transition', message: 'Status cannot change from DRAFT to APPROVED.' })
  })
})

describe('ApiAdminRestaurantRepository — notes and what is not on the backend', () => {
  it('an internal note is stored for the organization, about this location', async () => {
    await new ApiAdminRestaurantRepository().addNote(L1, '  Owner prefers calls after 4 pm  ')
    expect(posts('/notes')[0]).toMatchObject({ path: `/admin/restaurant-organizations/${ORG}/notes`, body: { note: 'Owner prefers calls after 4 pm', location_id: L1 } })
  })

  it('"request information" is kept as an internal note until notifications exist; documents are not offered; no cuisine filter', async () => {
    const repo = new ApiAdminRestaurantRepository()
    await repo.requestInformation(L1, 'ignored-actor', 'Please send the FSSAI licence')
    expect(posts('/notes')[0].body).toEqual({ note: 'Please send the FSSAI licence', location_id: L1 })
    await expect(repo.setDocumentStatus(L1, 'doc', 'APPROVED')).rejects.toThrow('documents_not_available')
    expect(repo.cuisines()).toEqual([]); expect(repo.live).toBe(true)
  })
})

/**
 * Restaurant Dashboard adapters for the backend (Module 23). The network is stubbed with payloads in the shape of the
 * real API (OpenAPI: RestaurantContext, RestaurantLocation, PickupSettings, RestaurantSpecialHour, RestaurantMember …);
 * the tests check what is SENT (paths, versions, bodies) and how the answers reach the pages.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, tokens } from '../../api/client'
import { ApiRestaurantManagementRepository, ApiRestaurantStaffRepository, acceptStaffInvitation, dashboardErrorMessage } from './apiDashboard'

const BASE = 'http://127.0.0.1:8001/api/v1'
const ORG = '0a000000-0000-4000-8000-00000000000a'; const L1 = '0a000000-0000-4000-8000-0000000000a1'; const L2 = '0a000000-0000-4000-8000-0000000000a2'
const availability = { visible_to_customers: true, open_now: true, open_state: 'OPEN', accepting_orders: true, orderable: true, reason: null, closes_at: '2026-10-05T18:00:00+00:00', opens_next_at: null, checked_at: '2026-10-05T06:00:00+00:00' }
const location = (patch: Record<string, unknown> = {}) => ({
  id: L1, slug: 'test-kitchen', name: 'Test Kitchen', branch_label: 'Sector 62 · Noida', short_description: 'Short.', description: 'Long.', pickup_instructions: 'Show your code.',
  cuisines: [{ code: 'north_indian', name: 'North Indian' }], features: [{ code: 'parking', name: 'Parking', category: 'FACILITY' }], price_level: 2, phone: null, email: null, website: null,
  address: { formatted: 'Sector 62, Noida, Uttar Pradesh 201309, India', line1: null, postal_code: '201309', city: 'Noida', city_slug: 'noida', region: 'Uttar Pradesh', region_code: 'IN-UP', country_code: 'IN' },
  location: { latitude: 28.6285, longitude: 77.3652 }, timezone: 'Asia/Kolkata', currency: 'INR',
  images: [{ id: 'i1', type: 'COVER', url: '/images/cover.jpg', alt_text: null, display_order: 0 }, { id: 'i2', type: 'GALLERY', url: '/images/two.jpg', alt_text: null, display_order: 1 }],
  status: 'APPROVED', status_note: null, rejection_category: null, operational_status: 'OPERATING', accepting_orders: true, pause_reason: null, paused_at: null, paused_until: null,
  hours: { version: 3, timezone: 'Asia/Kolkata', weekly: [0, 1, 2, 3, 4, 5, 6].map((day_of_week) => ({ day_of_week, periods: [{ opens_at: '08:00', closes_at: '23:30' }] })), special: [{ id: 's1', date: '2026-11-08', is_closed: true, periods: [], public_note: 'Diwali', internal_note: 'Owner away' }] },
  version: 4, updated_at: '2026-10-01T10:00:00+00:00', organization: { id: ORG, name: 'Test Group', status: 'APPROVED', status_note: null }, market: 'IN',
  pickup: { methods: [{ method: 'COUNTER', instructions: null, requires_vehicle_info: false }], asap: true, scheduled: true, default_prep_minutes: 12, minimum_lead_minutes: 15, instructions: 'Show your code.' },
  availability, permissions: ['restaurant.profile.view', 'restaurant.profile.manage', 'restaurant.hours.manage', 'restaurant.staff.view', 'something.unknown'],
  ...patch,
})
const CONTEXT = {
  organizations: [{ id: ORG, name: 'Test Group', legal_name: 'Test Group Pvt. Ltd.', status: 'APPROVED', status_note: null, membership: { id: 'm-self', role: { code: 'MANAGER', name: 'Manager' }, all_locations: true }, permissions: [] }],
  locations: [location(), location({ id: L2, slug: 'test-cafe', name: 'Test Café', status: 'SUSPENDED', status_note: 'Under review', availability: { ...availability, visible_to_customers: false, orderable: false, reason: 'LOCATION_SUSPENDED' } })],
  default_location_id: L1,
}
const TAXONOMY = { cuisines: [{ code: 'north_indian', name: 'North Indian' }, { code: 'biryani', name: 'Biryani' }], features: [{ code: 'parking', name: 'Parking', category: 'FACILITY' }], limits: { cuisines: 5, features: 12, description: 500, periods_per_day: 4, pause_max_hours: 72 } }
const PICKUP = {
  location_id: L1, pickup_enabled: true, asap_enabled: true, scheduled_enabled: false, default_prep_minutes: 12, minimum_lead_minutes: 15, buffer_minutes: 5, schedule_horizon_minutes: 2880, slot_interval_minutes: 10, order_cutoff_minutes: 20, capacity_per_slot: null,
  methods: [{ method: 'COUNTER', enabled: true, instructions: null, requires_vehicle_info: false, available_in_market: true }, { method: 'CURBSIDE', enabled: false, instructions: null, requires_vehicle_info: true, available_in_market: false }],
  market: { asap_allowed: true, scheduled_allowed: false }, version: 9,
}
const member = (patch: Record<string, unknown> = {}) => ({ id: 'm1', name: 'Sarah Wilson', email: 'sarah@test.example', role: { code: 'MANAGER', name: 'Manager' }, status: 'ACTIVE', all_locations: false, locations: [{ id: L1, name: 'Test Kitchen', branch_label: null }], is_self: false, version: 6, ...patch })

type Call = { method: string; path: string; body: Record<string, unknown> | null; auth: string | null }
type Reply = { status?: number; body?: unknown }
let calls: Call[] = []
let routes: Record<string, Reply | (() => Reply)> = {}
const sent = (method: string, fragment: string) => calls.filter((c) => c.method === method && c.path.includes(fragment))
const refuse = (status: number, code: string, fields?: Record<string, string[]>): Reply => ({ status, body: { error: { code, message: `Refused: ${code}`, details: fields ? { fields } : {}, request_id: 'req-1' } } })

beforeEach(() => {
  calls = []; tokens.set('restaurant', 'staff-token')
  routes = {
    'GET /restaurant/context': { body: CONTEXT }, 'GET /restaurant/taxonomy': { body: TAXONOMY },
    [`GET /restaurant/locations/${L1}`]: { body: location() }, [`PATCH /restaurant/locations/${L1}/profile`]: { body: location({ version: 5, name: 'Renamed' }) },
    [`GET /restaurant/locations/${L1}/pickup-settings`]: { body: PICKUP }, [`PATCH /restaurant/locations/${L1}/pickup-settings`]: { body: { ...PICKUP, version: 10 } },
  }
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const path = decodeURIComponent(url.replace(BASE, '')); const method = init.method ?? 'GET'
    calls.push({ method, path, body: init.body ? JSON.parse(init.body as string) : null, auth: (init.headers as Record<string, string>).Authorization ?? null })
    const route = routes[`${method} ${path.split('?')[0]}`]; const r = typeof route === 'function' ? route() : route
    if (!r) return new Response(JSON.stringify({ error: { code: 'not_found', message: `No stub for ${method} ${path}`, details: {} } }), { status: 404 })
    return new Response(r.body === undefined ? null : JSON.stringify(r.body), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } })
  }))
})
afterEach(() => { vi.unstubAllGlobals(); vi.useRealTimers(); tokens.set('restaurant', null) })

const loaded = async () => { const repo = new ApiRestaurantManagementRepository(); const locations = await repo.getLocations(); calls = []; return { repo, locations } }

describe('ApiRestaurantManagementRepository — context', () => {
  it('the locations are the ones the backend says this user may open, with the role and permissions held there', async () => {
    const { locations } = await loaded()
    expect(locations.map((l) => l.restaurant.id)).toEqual(['test-kitchen', 'test-cafe'])
    const [kitchen, cafe] = locations
    expect(kitchen.restaurant).toMatchObject({ publicId: L1, name: 'Test Kitchen', timezone: 'Asia/Kolkata', currency: 'INR', prepTimeMin: 12, cuisines: ['North Indian'], features: ['Parking'], images: ['/images/cover.jpg', '/images/two.jpg'], acceptingOrders: true, status: 'active' })
    expect(kitchen.profile).toMatchObject({ organizationId: ORG, locationName: 'Sector 62 · Noida', onboardingStatus: 'APPROVED', active: true, coverImage: '/images/cover.jpg', logo: null, gallery: ['/images/two.jpg'] })
    expect(kitchen.live).toMatchObject({ locationId: L1, organizationId: ORG, organizationName: 'Test Group', role: 'manager', version: 4, hoursVersion: 3, status: 'APPROVED' })
    // backend permission codes → the dashboard's own names; unknown codes are dropped, nothing is added
    expect(kitchen.live?.permissions).toEqual(['restaurant.profile.view', 'restaurant.profile.edit', 'hours.edit', 'staff.view'])
    expect(kitchen.restaurant.openingHours.special).toEqual([{ date: '2026-11-08', closed: true, periods: [], note: 'Diwali' }]) // the internal note is not carried into the customer model
    expect(cafe.profile).toMatchObject({ onboardingStatus: 'SUSPENDED', active: false })
    expect(cafe.live).toMatchObject({ statusNote: 'Under review', availability: { visibleToCustomers: false, orderable: false, reason: 'LOCATION_SUSPENDED' } })
  })

  it('sends the restaurant token and nothing that names a restaurant: the backend decides from the membership', async () => {
    const repo = new ApiRestaurantManagementRepository(); await repo.getLocations()
    expect(calls).toEqual([{ method: 'GET', path: '/restaurant/context', body: null, auth: 'Bearer staff-token' }])
    expect(await repo.getOrganization()).toEqual({ id: ORG, name: 'Test Group', logo: null, onboardingStatus: 'APPROVED', locationIds: ['test-kitchen', 'test-cafe'] })
    expect(repo.organizationIdFor('test-cafe')).toBe(ORG); expect(repo.locationUuid('test-cafe')).toBe(L2); expect(repo.locationIdForUuid(L1)).toBe('test-kitchen')
  })

  it('a location the user was not given does not exist for the dashboard', async () => {
    const { repo } = await loaded()
    expect(await repo.getLocation('someone-elses')).toBeNull()
    await expect(repo.setAcceptingOrders('someone-elses', false)).rejects.toThrow('location_unavailable')
    expect(sent('PATCH', '/availability')).toHaveLength(0) // nothing was sent
  })
})

describe('ApiRestaurantManagementRepository — profile', () => {
  it('sends the loaded version and taxonomy CODES for cuisines and features', async () => {
    const { repo } = await loaded()
    const saved = await repo.updateProfile('test-kitchen', { name: ' Renamed ', description: '', prepTimeMin: 12, cuisines: ['North Indian', 'Biryani'], features: ['Parking'], contact: { phone: '+911204567890', website: null, publicEmail: 'hello@test.example' } })
    expect(sent('PATCH', '/profile')[0].body).toEqual({ version: 4, name: ' Renamed ', description: null, phone: '+911204567890', website: null, email: 'hello@test.example', cuisines: ['north_indian', 'biryani'], features: ['parking'] })
    expect(saved.restaurant.name).toBe('Renamed'); expect(saved.live?.version).toBe(5)
    expect(sent('PATCH', '/pickup-settings')).toHaveLength(0) // the preparation time did not change
    // the next save carries the new version
    await repo.updateProfile('test-kitchen', { name: 'Again' })
    expect(sent('PATCH', '/profile')[1].body).toEqual({ version: 5, name: 'Again' })
    expect(sent('GET', '/restaurant/taxonomy')).toHaveLength(1)
  })

  it('a changed preparation time is saved as a pickup setting, with the pickup settings\' own version', async () => {
    const { repo } = await loaded()
    await repo.updateProfile('test-kitchen', { prepTimeMin: 20 })
    expect(sent('PATCH', '/pickup-settings')[0].body).toEqual({ version: 9, default_prep_minutes: 20 })
    expect(calls.at(-1)).toMatchObject({ method: 'GET', path: `/restaurant/locations/${L1}` })
  })

  it('a stale version is refused: the latest record is loaded and the refusal reaches the page', async () => {
    const { repo } = await loaded()
    routes[`PATCH /restaurant/locations/${L1}/profile`] = refuse(409, 'stale_update')
    routes[`GET /restaurant/locations/${L1}`] = { body: location({ version: 7, name: 'Changed by someone else' }) }
    await expect(repo.updateProfile('test-kitchen', { name: 'Mine' })).rejects.toMatchObject({ code: 'stale_update', status: 409 })
    routes[`PATCH /restaurant/locations/${L1}/profile`] = { body: location({ version: 8 }) }
    await repo.updateProfile('test-kitchen', { name: 'Mine' })
    expect(sent('PATCH', '/profile').at(-1)?.body).toEqual({ version: 7, name: 'Mine' })
  })

  it('a field error from the backend reaches the page as it was written', async () => {
    const { repo } = await loaded()
    routes[`PATCH /restaurant/locations/${L1}/profile`] = refuse(422, 'validation_failed', { website: ['The website must start with https://.'] })
    const error = await repo.updateProfile('test-kitchen', { contact: { phone: null, website: 'ftp://x', publicEmail: null } }).catch((e: unknown) => e)
    expect(error).toBeInstanceOf(ApiError); expect(dashboardErrorMessage(error)).toBe('The website must start with https://.')
  })
})

describe('ApiRestaurantManagementRepository — availability, hours, special hours', () => {
  it('the accepting-orders switch is one small request', async () => {
    const { repo } = await loaded()
    routes[`PATCH /restaurant/locations/${L1}/availability`] = { body: location({ accepting_orders: false, availability: { ...availability, accepting_orders: false, orderable: false, reason: 'NOT_ACCEPTING_ORDERS' } }) }
    const l = await repo.setAcceptingOrders('test-kitchen', false)
    expect(sent('PATCH', '/availability')[0].body).toEqual({ accepting_orders: false })
    expect(l.restaurant.acceptingOrders).toBe(false); expect(l.live?.availability.reason).toBe('NOT_ACCEPTING_ORDERS')
  })

  it('the week is replaced in one request with the hours version', async () => {
    const { repo } = await loaded()
    routes[`PUT /restaurant/locations/${L1}/hours`] = { body: { version: 4 } }
    await repo.updateHours('test-kitchen', { periods: [{ day: 1, open: '08:00', close: '14:00' }, { day: 1, open: '17:00', close: '02:00' }] })
    expect(sent('PUT', '/hours')[0].body).toEqual({ version: 3, periods: [{ day_of_week: 1, opens_at: '08:00', closes_at: '14:00' }, { day_of_week: 1, opens_at: '17:00', closes_at: '02:00' }] })
    expect(calls.at(-1)).toMatchObject({ method: 'GET', path: `/restaurant/locations/${L1}` })
  })

  it('special hours: past dates are not listed; saving sends only the difference', async () => {
    vi.useFakeTimers({ toFake: ['Date'] }); vi.setSystemTime(new Date('2026-10-05T06:00:00Z'))
    const { repo } = await loaded()
    const existing = [
      { id: 'old', date: '2026-09-01', is_closed: true, periods: [], public_note: 'Past', internal_note: null },
      { id: 'a', date: '2026-10-10', is_closed: true, periods: [], public_note: 'Old label', internal_note: null },
      { id: 'b', date: '2026-10-12', is_closed: false, periods: [{ opens_at: '10:00', closes_at: '14:00' }], public_note: 'Half day', internal_note: null },
      { id: 'keep', date: '2026-10-15', is_closed: true, periods: [], public_note: 'Unchanged', internal_note: null },
    ]
    routes[`GET /restaurant/locations/${L1}/special-hours`] = { body: existing }
    expect((await repo.getSpecialHours('test-kitchen')).map((s) => s.id)).toEqual(['a', 'b', 'keep'])
    expect((await repo.getSpecialHours('test-kitchen'))[1]).toEqual({ id: 'b', date: '2026-10-12', label: 'Half day', closed: false, periods: [{ open: '10:00', close: '14:00' }] })

    routes[`POST /restaurant/locations/${L1}/special-hours`] = { status: 201, body: {} }
    routes[`PATCH /restaurant/locations/${L1}/special-hours/a`] = { body: {} }
    routes[`DELETE /restaurant/locations/${L1}/special-hours/b`] = { status: 204 }
    calls = []
    await repo.saveSpecialHours('test-kitchen', [
      { id: 'a', date: '2026-10-10', label: 'New label', closed: true, periods: [] },
      { id: 'keep', date: '2026-10-15', label: 'Unchanged', closed: true, periods: [] },
      { id: 'sh_2026-10-20', date: '2026-10-20', label: 'Festival', closed: false, periods: [{ open: '12:00', close: '16:00' }] },
    ])
    const writes = calls.filter((c) => c.method !== 'GET').map((c) => `${c.method} ${c.path.replace(`/restaurant/locations/${L1}`, '')}`)
    expect(writes).toEqual(['DELETE /special-hours/b', 'PATCH /special-hours/a', 'POST /special-hours'])
    expect(sent('PATCH', '/special-hours/a')[0].body).toEqual({ date: '2026-10-10', is_closed: true, periods: [], public_note: 'New label' })
    expect(sent('POST', '/special-hours')[0].body).toEqual({ date: '2026-10-20', is_closed: false, periods: [{ opens_at: '12:00', closes_at: '16:00' }], public_note: 'Festival' })
  })
})

describe('ApiRestaurantManagementRepository — pickup settings', () => {
  it('maps the backend settings, including what the market allows', async () => {
    const { repo } = await loaded()
    expect(await repo.getPickupSettings('test-kitchen')).toEqual({
      restaurantId: 'test-kitchen', timezone: 'Asia/Kolkata', intervalMinutes: 10, minimumLeadMinutes: 15, maximumScheduleAheadMinutes: 2880, bufferMinutes: 5, acceptanceCutoffMinutes: 20,
      modes: ['asap'], instructions: 'Show your code.', marketModes: { asap: true, scheduled: false },
      methods: [{ id: 'counter', type: 'counter', label: 'Counter pickup', instructions: undefined, enabled: true, requiresVehicleInfo: false, availableInMarket: true }, { id: 'curbside', type: 'curbside', label: 'Curbside pickup', instructions: undefined, enabled: false, requiresVehicleInfo: true, availableInMarket: false }],
    })
  })

  it('saves with the version it loaded; the customer instructions are a profile field', async () => {
    const { repo } = await loaded()
    const settings = await repo.getPickupSettings('test-kitchen')
    await repo.savePickupSettings('test-kitchen', { ...settings, minimumLeadMinutes: 25, instructions: 'Ring the bell.' })
    expect(sent('PATCH', '/pickup-settings')[0].body).toEqual({
      version: 9, pickup_enabled: true, asap_enabled: true, scheduled_enabled: false, slot_interval_minutes: 10, minimum_lead_minutes: 25, schedule_horizon_minutes: 2880, buffer_minutes: 5, order_cutoff_minutes: 20,
      methods: [{ method: 'COUNTER', enabled: true, instructions: null, requires_vehicle_info: false }, { method: 'CURBSIDE', enabled: false, instructions: null, requires_vehicle_info: true }],
    })
    expect(sent('PATCH', '/profile')[0].body).toEqual({ version: 4, pickup_instructions: 'Ring the bell.' })
  })

  it('switching pickup off does not touch the modes', async () => {
    const { repo } = await loaded()
    const settings = await repo.getPickupSettings('test-kitchen')
    await repo.savePickupSettings('test-kitchen', { ...settings, modes: [] })
    const body = sent('PATCH', '/pickup-settings')[0].body!
    expect(body.pickup_enabled).toBe(false); expect('asap_enabled' in body).toBe(false); expect('scheduled_enabled' in body).toBe(false)
    expect(sent('PATCH', '/profile')).toHaveLength(0) // instructions unchanged
  })
})

describe('ApiRestaurantStaffRepository', () => {
  const staff = async () => { const { repo } = await loaded(); return new ApiRestaurantStaffRepository(repo) }
  const BASE_PATH = `/restaurant/organizations/${ORG}/staff`

  it('lists the members of the organization of the current location', async () => {
    routes[`GET ${BASE_PATH}`] = { body: { data: [member({ id: 'm-self', name: 'Me', is_self: true, all_locations: true, locations: [] }), member(), member({ id: 'm2', status: 'INVITED', role: { code: 'ORDER_STAFF', name: 'Order staff' }, locations: [{ id: 'unknown-uuid', name: 'Elsewhere', branch_label: null }] }), member({ id: 'm3', status: 'SUSPENDED', role: { code: 'SOMETHING_NEW', name: 'New' } })] } }
    const list = await (await staff()).list('test-kitchen')
    expect(calls[0]).toMatchObject({ method: 'GET', path: `${BASE_PATH}?page[size]=100`, auth: 'Bearer staff-token' })
    expect(list.map((m) => [m.id, m.role, m.status, m.locationAccess, m.isSelf])).toEqual([
      ['m-self', 'manager', 'active', 'all', true], ['m1', 'manager', 'active', ['test-kitchen'], false], ['m2', 'order_staff', 'invited', ['unknown-uuid'], false], ['m3', 'viewer', 'suspended', ['test-kitchen'], false],
    ])
  })

  it('an invitation names backend location ids and an upper-case role; nothing else', async () => {
    routes[`POST ${BASE_PATH}`] = { status: 201, body: member({ id: 'm9', status: 'INVITED', name: 'New Chef' }) }
    const repo = await staff()
    const invited = await repo.invite({ name: 'New Chef', email: 'chef@test.example', role: 'order_staff', locationAccess: ['test-kitchen', 'test-cafe'], avatar: null }, 'test-kitchen')
    expect(sent('POST', BASE_PATH)[0].body).toEqual({ name: 'New Chef', email: 'chef@test.example', role: 'ORDER_STAFF', all_locations: false, location_ids: [L1, L2] })
    expect(invited).toMatchObject({ id: 'm9', status: 'invited' })
    await repo.invite({ name: 'Owner Two', email: 'two@test.example', role: 'owner', locationAccess: 'all', avatar: null })
    expect(sent('POST', BASE_PATH)[1].body).toEqual({ name: 'Owner Two', email: 'two@test.example', role: 'OWNER', all_locations: true })
  })

  it('changes carry the version of the member as last seen', async () => {
    routes[`GET ${BASE_PATH}`] = { body: { data: [member()] } }
    routes[`PATCH ${BASE_PATH}/m1`] = { body: member({ version: 7, status: 'SUSPENDED' }) }
    routes[`DELETE ${BASE_PATH}/m1`] = { body: member({ version: 9, status: 'REVOKED' }) }
    routes[`POST ${BASE_PATH}/m1/invitation`] = { body: {} }
    const repo = await staff(); await repo.list('test-kitchen')
    expect((await repo.update('m1', { status: 'suspended' }, 'test-kitchen')).status).toBe('suspended')
    expect(sent('PATCH', `${BASE_PATH}/m1`)[0].body).toEqual({ version: 6, status: 'SUSPENDED' })
    await repo.update('m1', { role: 'viewer', locationAccess: 'all' }, 'test-kitchen')
    expect(sent('PATCH', `${BASE_PATH}/m1`)[1].body).toEqual({ version: 7, role: 'VIEWER', all_locations: true })
    await repo.remove('m1', 'test-kitchen'); expect(sent('DELETE', `${BASE_PATH}/m1`)).toHaveLength(1)
    await repo.resendInvitation('m1', 'test-kitchen'); expect(sent('POST', `${BASE_PATH}/m1/invitation`)).toHaveLength(1)
  })

  it('the refusals the page knows by name; everything else is the backend\'s own message', async () => {
    const repo = await staff()
    routes[`POST ${BASE_PATH}`] = refuse(422, 'validation_failed', { email: ['This person is already a member of the restaurant or has an open invitation.'] })
    await expect(repo.invite({ name: 'A', email: 'a@test.example', role: 'viewer', locationAccess: 'all', avatar: null })).rejects.toThrow('staff_duplicate_email')
    routes[`PATCH ${BASE_PATH}/m1`] = refuse(409, 'last_owner')
    await expect(repo.update('m1', { role: 'viewer' })).rejects.toThrow('last_owner')
    routes[`DELETE ${BASE_PATH}/m1`] = refuse(409, 'cannot_change_own_membership')
    const error = await repo.remove('m1').catch((e: unknown) => e)
    expect(error).toMatchObject({ code: 'cannot_change_own_membership', status: 409 }); expect(dashboardErrorMessage(error)).toBe('Refused: cannot_change_own_membership')
  })

  it('the role bundles are the backend\'s, in the dashboard\'s permission names and order', async () => {
    routes['GET /restaurant/roles'] = { body: { data: [
      { code: 'VIEWER', name: 'Viewer', permissions: ['restaurant.profile.view', 'restaurant.analytics.view'] },
      { code: 'OWNER', name: 'Owner', permissions: ['restaurant.profile.view', 'restaurant.profile.manage', 'restaurant.staff.manage'] },
      { code: 'REGIONAL_AUDITOR', name: 'A role this dashboard does not know', permissions: ['restaurant.profile.view'] },
    ] } }
    expect(await (await staff()).roles()).toEqual([{ id: 'owner', permissions: ['restaurant.profile.view', 'restaurant.profile.edit', 'staff.manage'] }, { id: 'viewer', permissions: ['restaurant.profile.view', 'analytics.view'] }])
  })
})

describe('dashboardErrorMessage', () => {
  const error = (status: number, code: string, message = 'Backend message.', errors?: Record<string, string[]>) => new ApiError(status, message, { code, errors })
  it('says what happened without leaking anything, and never treats a 403 as a sign-out', () => {
    expect(dashboardErrorMessage(error(403, 'forbidden'))).toBe('Access denied — your role does not allow this.')
    expect(dashboardErrorMessage(error(403, 'cannot_grant_beyond_own_permissions', 'You cannot give a role that may do more than you.'))).toBe('You cannot give a role that may do more than you.')
    expect(dashboardErrorMessage(error(404, 'not_found'))).toBe('This location is no longer available to you.')
    expect(dashboardErrorMessage(error(409, 'stale_update'))).toMatch(/Someone else changed this/)
    expect(dashboardErrorMessage(error(409, 'pickup_method_not_available', 'Curbside pickup is not available in this market yet.'))).toBe('Curbside pickup is not available in this market yet.')
    expect(dashboardErrorMessage(error(422, 'validation_failed', 'Please check.', { 'periods.1': ['Opening periods must not overlap.'] }))).toBe('Opening periods must not overlap.')
    expect(dashboardErrorMessage(error(429, 'rate_limited'))).toMatch(/Too many changes/)
    expect(dashboardErrorMessage(error(500, 'server_error', 'SQLSTATE[42P01] secret detail'))).toBe('Something went wrong on our side. Please try again.')
    expect(dashboardErrorMessage(error(0, 'network'))).toMatch(/Cannot reach FoodOnTheGo/)
    expect(dashboardErrorMessage(new Error('location_unavailable'))).toBe('This location is no longer available to you.')
    expect(dashboardErrorMessage('boom')).toBe('Something went wrong. Please try again.')
  })
})

describe('acceptStaffInvitation', () => {
  it('is a public call: the token goes in the body, never with a session token; a new account sends its password', async () => {
    routes['POST /auth/restaurant/invitation/accept'] = { body: { message: 'ok', restaurant: 'Test Group' } }
    const TOKEN = 'a'.repeat(64)
    expect(await acceptStaffInvitation(TOKEN)).toMatchObject({ restaurant: 'Test Group' })
    expect(calls[0]).toEqual({ method: 'POST', path: '/auth/restaurant/invitation/accept', body: { token: TOKEN }, auth: null })
    await acceptStaffInvitation(TOKEN, 'a long passphrase')
    expect(calls[1].body).toEqual({ token: TOKEN, password: 'a long passphrase', password_confirmation: 'a long passphrase' })
  })
})

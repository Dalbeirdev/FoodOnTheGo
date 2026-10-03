/**
 * Customer account on the backend (Module 25): the mapping of the account API onto the page model, the bodies the
 * repositories send (never a phone, status or payment credential), and the error vocabulary the flows rely on.
 * The network is stubbed with payloads in the shape of the real API (OpenAPI: CustomerProfile, FavoriteRestaurantPage,
 * SavedLocation, PaymentMethodSummary, NotificationPreferences, ReauthChallenge).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { tokens } from '../../api/client'
import type { PublicRestaurantDto } from '../../restaurants/api/restaurantData'
import { selectAccountRepositories } from '../accountRepositories'
import { MockFavoriteRepository, MockProfileRepository } from '../mock/mockRepositories'
import { RepositoryError } from '../repositories'
import {
  ApiAddressRepository, ApiFavoriteRepository, ApiNotificationRepository, ApiPaymentMethodRepository, ApiProfileRepository, cellsFor, resetAccountApiCaches, toAddress, toFavorite, toPaymentMethod,
  toPreferences, toProfile, toProfileBody, toSavedLocationBody, type FavoriteDto, type NotificationPreferencesDto, type PaymentMethodDto, type ProfileDto, type SavedLocationDto,
} from './apiAccount'

const BASE = 'http://127.0.0.1:8001/api/v1'

const profileDto = (patch: Partial<ProfileDto> = {}): ProfileDto => ({
  id: 'd79adb86-3bad-401d-8fc9-171506ee0342', name: 'Rahul Sharma', display_name: 'Rahul Sharma', phone: '+919876543210', phone_masked: '+91 ******3210', phone_verified: true, phone_editable: false,
  email: 'rahul.sharma@example.com', email_verified: false, preferred_locale: 'en-IN', locale_options: ['en-IN'], market: 'IN', date_of_birth: '1990-03-15', gender: 'MALE',
  favorite_cuisines: [{ code: 'north_indian', name: 'North Indian' }, { code: 'burgers', name: 'Burgers' }], vegetarian_only: false, search_radius_km: 20, avatar: null, status: 'ACTIVE',
  deletion_requested_at: null, member_since: '2026-09-30', version: 3, updated_at: '2026-10-03T08:23:34+00:00', ...patch,
})
const restaurantDto = (slug = 'burger-hub'): PublicRestaurantDto => ({
  id: '7822b22c-05f3-49cd-ae89-f58e6ee277d9', slug, name: 'Burger Hub', branch_label: 'Sector 62 · Noida', short_description: 'Burgers.', description: 'Burgers and shakes.',
  cuisines: [{ code: 'burgers', name: 'Burgers' }], features: [], price_level: 2, phone: null, email: null, website: null,
  address: { formatted: 'Sector 62, Noida', line1: 'Sector 62', postal_code: '201309', city: 'Noida', city_slug: 'noida', region: 'Uttar Pradesh', region_code: 'IN-UP', country_code: 'IN' },
  location: { latitude: 28.6285, longitude: 77.3652 }, timezone: 'Asia/Kolkata', currency: 'INR', images: { logo: null, cover: null, gallery: [] },
  hours: { timezone: 'Asia/Kolkata', weekly: [0, 1, 2, 3, 4, 5, 6].map((day_of_week) => ({ day_of_week, periods: [{ opens_at: '00:00', closes_at: '00:00' }] })), special: [] },
  pickup: { methods: [{ method: 'COUNTER', instructions: null, requires_vehicle_info: false }], asap: true, scheduled: false, default_prep_minutes: 12, minimum_lead_minutes: null, instructions: null },
  availability: { open_now: true, open_state: 'OPEN', accepting_orders: true, orderable: true, reason: null, closes_at: null, opens_next_at: null, checked_at: '2026-10-03T08:00:00+00:00' },
})
const favoriteDto = (patch: Partial<FavoriteDto> = {}): FavoriteDto => ({ restaurant_id: '7822b22c-05f3-49cd-ae89-f58e6ee277d9', slug: 'burger-hub', name: 'Burger Hub', added_at: '2026-10-03T08:23:34+00:00', available: true, restaurant: restaurantDto(), ...patch })
const savedDto = (patch: Partial<SavedLocationDto> = {}): SavedLocationDto => ({
  id: 'df2d09ac-56ca-4dbe-be7f-ba5ea89df14e', kind: 'HOME', label: 'Home',
  address: { line1: 'A-203, Green Valley Apartments', line2: null, locality: 'Sector 62', city: 'Noida', region: 'Uttar Pradesh', postal_code: '201309', country_code: 'IN', formatted: 'A-203, Green Valley Apartments, Sector 62, Noida, Uttar Pradesh 201309, India' },
  location: { latitude: 28.6271, longitude: 77.3717 }, place: null, timezone: 'Asia/Kolkata', is_default: true,
  coverage: { status: 'supported', reason: null, market: 'IN', city: 'Noida', service_area: 'Noida · Sector 62 & NH24' }, version: 1, created_at: '2026-10-03T08:23:34+00:00', updated_at: null, ...patch,
})
const paymentDto = (patch: Partial<PaymentMethodDto> = {}): PaymentMethodDto => ({ id: '31ea2287-344f-40e1-8192-2b402b061558', type: 'CARD', provider: 'development', brand: 'Visa', display_label: 'Visa •••• 4242', last4: '4242', expiry: { month: 12, year: 2028 }, upi_handle_masked: null, is_default: true, status: 'ACTIVE', created_at: '2026-10-03T08:23:34+00:00', ...patch })
const cell = (channel: 'PUSH' | 'SMS' | 'EMAIL' | 'IN_APP', enabled: boolean, locked = false) => ({ channel, enabled, locked, chosen: false })
const prefsDto = (): NotificationPreferencesDto => ({
  categories: [
    { category: 'ORDER_UPDATES', name: 'Order updates', description: 'Confirmed, being prepared, ready for pickup', transactional: true, channels: [cell('PUSH', true), cell('SMS', true), cell('EMAIL', false), cell('IN_APP', true)] },
    { category: 'PAYMENT_UPDATES', name: 'Payment updates', description: 'Payments and refunds', transactional: true, channels: [cell('PUSH', true), cell('SMS', true), cell('EMAIL', true), cell('IN_APP', true)] },
    { category: 'ACCOUNT_SECURITY', name: 'Account security', description: 'Sign-ins and changes', transactional: true, channels: [cell('PUSH', true), cell('SMS', true, true), cell('EMAIL', true), cell('IN_APP', true, true)] },
    { category: 'PROMOTIONS', name: 'Offers', description: 'Deals', transactional: false, channels: [cell('PUSH', false), cell('SMS', false), cell('EMAIL', false), cell('IN_APP', false)] },
  ],
  channels: ['PUSH', 'SMS', 'EMAIL', 'IN_APP'],
  marketing_consent: { granted_at: null, withdrawn_at: null },
})

type Call = { method: string; path: string; body: unknown; form: boolean }
type Reply = { status?: number; body: unknown }
let calls: Call[] = []
let reply: (method: string, path: string) => Reply = () => ({ body: {} })
beforeEach(() => {
  calls = []; sessionStorage.clear(); localStorage.clear(); resetAccountApiCaches(); tokens.set('customer', 'test-token')
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const path = decodeURIComponent(url.replace(BASE, ''))
    const form = init.body instanceof FormData
    calls.push({ method: init.method ?? 'GET', path, body: form ? null : init.body ? JSON.parse(init.body as string) : undefined, form })
    const r = reply(init.method ?? 'GET', path)
    return new Response(r.status === 204 ? null : JSON.stringify(r.body), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } })
  }))
})
afterEach(() => { vi.unstubAllGlobals(); tokens.set('customer', null) })
const error = (status: number, code: string, message: string, fields?: Record<string, string[]>): Reply => ({ status, body: { error: { code, message, details: fields ? { fields } : {}, request_id: 'r1' } } })

describe('profile — the account API onto the page model', () => {
  it('keeps what the backend said: identity read-only, locale options from the market, cuisine codes with names, status', () => {
    const p = toProfile(profileDto(), [{ code: 'north_indian', name: 'North Indian' }, { code: 'burgers', name: 'Burgers' }, { code: 'healthy', name: 'Healthy' }])
    expect(p).toMatchObject({ id: 'd79adb86-3bad-401d-8fc9-171506ee0342', name: 'Rahul Sharma', phone: '+919876543210', phoneMasked: '+91 ******3210', phoneVerified: true, email: 'rahul.sharma@example.com', emailVerified: false, dob: '1990-03-15', gender: 'male', language: 'en-IN', vegetarian: false, searchRadiusKm: 20, status: 'active', version: 3, memberSince: '2026-09-30' })
    expect(p.localeOptions).toEqual([{ code: 'en-IN', name: 'English (India)' }])
    expect(p.cuisines).toEqual(['north_indian', 'burgers'])
    expect(p.cuisineOptions?.map((o) => o.code)).toEqual(['north_indian', 'burgers', 'healthy'])
    expect(toProfile(profileDto({ name: null, gender: null, date_of_birth: null, status: 'RESTRICTED', avatar: { url: 'http://127.0.0.1:8001/api/v1/media/avatars/x/y.jpg', updated_at: null } }))).toMatchObject({ name: '', displayName: 'Rahul Sharma', gender: '', dob: '', status: 'restricted', avatarUrl: 'http://127.0.0.1:8001/api/v1/media/avatars/x/y.jpg' })
  })

  it('sends only the fields a customer may change, with the version — never the phone, status or market', () => {
    const body = toProfileBody({ name: ' Rahul S ', email: '', dob: '', gender: 'female', language: 'en-IN', cuisines: ['burgers'], vegetarian: true, searchRadiusKm: 35 }, 3)
    expect(body).toEqual({ version: 3, name: 'Rahul S', email: null, date_of_birth: null, gender: 'FEMALE', preferred_locale: 'en-IN', favorite_cuisines: ['burgers'], vegetarian_only: true, search_radius_km: 35 })
    expect(Object.keys(body)).not.toContain('phone')
    expect(toProfileBody({ gender: '' })).toEqual({ gender: null })
  })

  it('reads, edits with the last version, uploads the photo as multipart and removes it', async () => {
    reply = (method, path) => path === '/customer/profile' && method === 'GET' ? { body: profileDto() } : path === '/customer/profile' ? { body: profileDto({ name: 'Rahul S', version: 4 }) } : path === '/cuisines' ? { body: { data: [] } } : { body: profileDto({ avatar: method === 'POST' ? { url: 'http://x/api/v1/media/avatars/a/b.jpg', updated_at: null } : null }) }
    const repo = new ApiProfileRepository()
    expect((await repo.get()).name).toBe('Rahul Sharma')
    expect((await repo.update('u', { name: 'Rahul S' })).version).toBe(4)
    expect(calls.find((c) => c.method === 'PATCH')?.body).toEqual({ version: 3, name: 'Rahul S' })
    expect((await repo.setAvatar('u', 'data:image/png;base64,iVBORw0KGgo=')).avatarUrl).toContain('/media/avatars/')
    expect(calls.find((c) => c.path === '/customer/profile/avatar' && c.method === 'POST')?.form).toBe(true)
    expect((await repo.setAvatar('u', null)).avatarUrl).toBeNull()
    expect(calls.some((c) => c.path === '/customer/profile/avatar' && c.method === 'DELETE')).toBe(true)
    expect(repo.updatesIdentity).toBe(true)
  })

  it('a refused field becomes a field message; a sensitive action without a recent sign-in keeps its code', async () => {
    reply = (_m, path) => path === '/customer/profile' ? error(422, 'validation_failed', 'The submitted data is invalid.', { email: ['Enter a valid email address.'] }) : error(403, 'reauthentication_required', 'Please confirm it is you first: we will send a code to your phone.')
    const repo = new ApiProfileRepository()
    const e = await repo.update('u', { email: 'nope' }).catch((x: unknown) => x)
    expect(e).toBeInstanceOf(RepositoryError)
    expect((e as RepositoryError).fields.email).toBe('Enter a valid email address.')
    expect((e as RepositoryError).message).toBe('Enter a valid email address.')
    const d = await repo.requestDeletion('u', 'Moving').catch((x: unknown) => x)
    expect((d as RepositoryError).code).toBe('reauthentication_required')
    expect(calls.find((c) => c.path === '/customer/account/deletion-request')?.body).toEqual({ confirm: true, reason: 'Moving' })
  })

  it('re-authentication and phone change send codes and carry the challenge; the code itself is never in a response', async () => {
    const challenge = { challenge_id: '9bd56882-e30d-45f4-92d7-389dbdd67979', phone_masked: '+91 ******3210', expires_at: new Date(Date.now() + 300_000).toISOString(), resend_available_at: new Date(Date.now() + 5_000).toISOString(), attempts_allowed: 5, server_time: new Date().toISOString(), delivery: 'live' as const }
    reply = (_m, path) => path === '/customer/account/reauth' ? { body: { ...challenge, purpose: 'reauth' } } : path === '/customer/account/reauth/verify' ? { body: { reauthenticated: true, valid_until: 'x' } } : path === '/customer/phone-change/request' ? { body: { ...challenge, purpose: 'phone_change', change_id: 'c1' } } : path === '/cuisines' ? { body: { data: [] } } : { body: profileDto({ phone: '+919876501234' }) }
    const repo = new ApiProfileRepository()
    const re = await repo.security.requestReauth()
    expect(re.challengeId).toBe(challenge.challenge_id)
    expect(re.devOtp).toBeUndefined()
    expect(re.expiresAt).toBeGreaterThan(Date.now())
    await repo.security.verifyReauth(re.challengeId, '123456')
    expect(calls.find((c) => c.path === '/customer/account/reauth/verify')?.body).toEqual({ challenge_id: re.challengeId, code: '123456' })
    const ch = await repo.security.requestPhoneChange('98765 01234')
    expect(ch.changeId).toBe('c1')
    expect(calls.find((c) => c.path === '/customer/phone-change/request')?.body).toMatchObject({ phone: '98765 01234', country: 'IN' })
    expect((await repo.security.verifyPhoneChange(ch.challengeId, '123456')).phone).toBe('+919876501234')
  })
})

describe('favorites', () => {
  it('a visible favorite carries the restaurant as the pages know it; a hidden one keeps its name and is unavailable', () => {
    const open = toFavorite(favoriteDto())
    expect(open).toMatchObject({ restaurantId: 'burger-hub', slug: 'burger-hub', name: 'Burger Hub', available: true })
    expect(open.restaurant?.name).toBe('Burger Hub')
    const hidden = toFavorite(favoriteDto({ slug: 'vadodara-expressway-grill', name: 'Expressway Grill', available: false, restaurant: null }))
    expect(hidden).toMatchObject({ restaurantId: 'vadodara-expressway-grill', available: false, restaurant: null, name: 'Expressway Grill' })
  })

  it('lists every page, adds and removes by slug (idempotent on the backend) and reloads the list', async () => {
    reply = (method, path) => path.startsWith('/customer/favorites?') ? { body: { data: [favoriteDto()], meta: { current_page: 1, last_page: 1, total: 1 } } } : method === 'DELETE' ? { status: 204, body: '' } : { status: 201, body: { data: favoriteDto(), added: true } }
    const repo = new ApiFavoriteRepository()
    expect((await repo.list()).map((f) => f.restaurantId)).toEqual(['burger-hub'])
    await repo.add('u', 'burger-hub')
    expect(calls.find((c) => c.method === 'POST')?.path).toBe('/customer/favorites/burger-hub')
    await repo.remove('u', 'burger-hub')
    expect(calls.find((c) => c.method === 'DELETE')?.path).toBe('/customer/favorites/burger-hub')
    expect(calls.filter((c) => c.method === 'GET').length).toBe(3)
  })

  it('a restaurant the customer may not see cannot be favorited', async () => {
    reply = () => error(404, 'restaurant_not_found', 'This restaurant does not exist.')
    const e = await new ApiFavoriteRepository().add('u', 'hidden').catch((x: unknown) => x)
    expect((e as RepositoryError).code).toBe('restaurant_not_found')
  })
})

describe('saved journey locations', () => {
  it('maps the address, the pin and the coverage the backend decided', () => {
    const home = toAddress(savedDto())
    expect(home).toMatchObject({ id: 'df2d09ac-56ca-4dbe-be7f-ba5ea89df14e', kind: 'home', label: 'Home', line1: 'A-203, Green Valley Apartments', locality: 'Sector 62', city: 'Noida', state: 'Uttar Pradesh', pincode: '201309', lat: 28.6271, lng: 77.3717, isDefault: true, version: 1, countryCode: 'IN' })
    expect(home.coverage).toEqual({ status: 'supported', reason: null, market: 'IN', city: 'Noida', serviceArea: 'Noida · Sector 62 & NH24' })
    const abroad = toAddress(savedDto({ kind: 'OTHER', label: 'Dubai airport', location: { latitude: 25.2532, longitude: 55.3657 }, coverage: { status: 'unsupported', reason: 'MARKET_UNSUPPORTED', market: null, city: null, service_area: null } }))
    expect(abroad.coverage?.status).toBe('unsupported')
    const text = toAddress(savedDto({ location: null, coverage: { status: 'unknown', reason: 'NO_COORDINATES', market: null, city: null, service_area: null } }))
    expect([text.lat, text.lng, text.coverage?.status]).toEqual([null, null, 'unknown'])
  })

  it('composes the formatted line, uppercases the kind, defaults the country to the market and sends a pin only when there is one', () => {
    const body = toSavedLocationBody({ label: ' Home ', kind: 'home', line1: 'A-203', line2: '', locality: 'Sector 62', city: 'Noida', state: 'Uttar Pradesh', pincode: '201309', lat: null, lng: null }, 'IN')
    expect(body).toEqual({ kind: 'HOME', label: 'Home', line1: 'A-203', line2: null, locality: 'Sector 62', city: 'Noida', region: 'Uttar Pradesh', postal_code: '201309', country_code: 'IN', formatted_address: 'A-203, Sector 62, Noida, Uttar Pradesh 201309' })
    expect(toSavedLocationBody({ label: 'Pin', kind: 'other', line1: '', line2: '', locality: '', city: '', state: '', pincode: '', lat: 28.55, lng: 77.35, countryCode: 'AE' }, 'IN')).toMatchObject({ lat: 28.55, lng: 77.35, country_code: 'AE', formatted_address: null })
  })

  it('creates with POST, edits with PATCH + version (removing the pin when none is given), sets the default and deletes', async () => {
    reply = (method, path) => method === 'GET' ? { body: [savedDto()] } : path.endsWith('/default') ? { body: savedDto() } : method === 'DELETE' ? { status: 204, body: '' } : { status: method === 'POST' ? 201 : 200, body: savedDto() }
    const repo = new ApiAddressRepository()
    await repo.save('u', { label: 'Work', kind: 'work', line1: 'Tower B', line2: '', locality: 'Sector 142', city: 'Noida', state: 'Uttar Pradesh', pincode: '201305', lat: 28.4987, lng: 77.4115 })
    expect(calls.find((c) => c.method === 'POST' && c.path === '/customer/saved-locations')?.body).toMatchObject({ kind: 'WORK', label: 'Work', lat: 28.4987, lng: 77.4115 })
    await repo.save('u', { id: 'df2d09ac-56ca-4dbe-be7f-ba5ea89df14e', version: 1, label: 'Home (Noida)', kind: 'home', line1: 'A-203', line2: '', locality: 'Sector 62', city: 'Noida', state: 'Uttar Pradesh', pincode: '201309', lat: null, lng: null })
    const patch = calls.find((c) => c.method === 'PATCH')
    expect(patch?.path).toBe('/customer/saved-locations/df2d09ac-56ca-4dbe-be7f-ba5ea89df14e')
    expect(patch?.body).toMatchObject({ version: 1, label: 'Home (Noida)', location: null })
    await repo.setDefault('u', 'df2d09ac-56ca-4dbe-be7f-ba5ea89df14e')
    expect(calls.some((c) => c.path === '/customer/saved-locations/df2d09ac-56ca-4dbe-be7f-ba5ea89df14e/default' && c.method === 'POST')).toBe(true)
    await repo.remove('u', 'df2d09ac-56ca-4dbe-be7f-ba5ea89df14e')
    expect(calls.some((c) => c.method === 'DELETE')).toBe(true)
  })

  it('an edit of a place changed elsewhere is refused with stale_update', async () => {
    reply = () => error(409, 'stale_update', 'This saved location was changed somewhere else. Reload and try again.')
    const e = await new ApiAddressRepository().save('u', { id: 'x', version: 1, label: 'A', kind: 'home', line1: 'a', line2: '', locality: 'b', city: 'c', state: 'd', pincode: '201309', lat: null, lng: null }).catch((x: unknown) => x)
    expect((e as RepositoryError).code).toBe('stale_update')
  })
})

describe('payment-method references', () => {
  it('shows safe metadata only — brand, last four, expiry, masked UPI handle — and never a number or CVV', () => {
    const card = toPaymentMethod(paymentDto())
    expect(card).toMatchObject({ type: 'card', brand: 'Visa', last4: '4242', expiry: '12/28', isDefault: true, status: 'ACTIVE', provider: 'development', providerRef: '' })
    expect(toPaymentMethod(paymentDto({ type: 'UPI', brand: null, display_label: 'UPI ra***@okaxis', last4: null, expiry: null, upi_handle_masked: 'ra***@okaxis', is_default: false }))).toMatchObject({ type: 'upi', handleMasked: 'ra***@okaxis' })
    expect(toPaymentMethod(paymentDto({ type: 'CARD', brand: 'Mastercard', last4: '4444', expiry: { month: 1, year: 2024 }, status: 'EXPIRED', is_default: false }))).toMatchObject({ type: 'card', status: 'EXPIRED', expiry: '01/24' })
    expect(toPaymentMethod(paymentDto({ type: 'WALLET', brand: null, display_label: 'Paytm wallet', last4: null, expiry: null }))).toMatchObject({ type: 'other', label: 'Paytm wallet' })
    const json = JSON.stringify([card])
    expect(json).not.toMatch(/\b\d{13,19}\b/)
    expect(json.toLowerCase()).not.toContain('cvv')
  })

  it('lists, makes default and removes through the backend, which answers the remaining list', async () => {
    reply = (method) => ({ body: method === 'GET' ? [paymentDto(), paymentDto({ id: 'b', type: 'UPI', upi_handle_masked: 'ra***@okaxis', display_label: 'UPI', is_default: false })] : [paymentDto({ id: 'b', type: 'UPI', upi_handle_masked: 'ra***@okaxis', display_label: 'UPI', is_default: true })] })
    const repo = new ApiPaymentMethodRepository()
    expect((await repo.list()).map((m) => m.type)).toEqual(['card', 'upi'])
    expect((await repo.setDefault('u', 'b'))[0]).toMatchObject({ id: 'b', isDefault: true })
    expect(calls.find((c) => c.method === 'PATCH')?.path).toBe('/customer/payment-methods/b/default')
    expect((await repo.remove('u', '31ea2287-344f-40e1-8192-2b402b061558')).length).toBe(1)
    expect(calls.find((c) => c.method === 'DELETE')?.path).toBe('/customer/payment-methods/31ea2287-344f-40e1-8192-2b402b061558')
  })
})

describe('notification preferences', () => {
  it('keeps the matrix and derives the Module 04 switches from it', () => {
    const p = toPreferences(prefsDto())
    expect(p).toMatchObject({ push: true, orderUpdates: true, paymentUpdates: true, promotions: false, email: true, sms: true })
    expect(p.matrix?.categories.map((c) => c.category)).toEqual(['ORDER_UPDATES', 'PAYMENT_UPDATES', 'ACCOUNT_SECURITY', 'PROMOTIONS'])
    expect(p.matrix?.categories[2].channels.find((c) => c.channel === 'SMS')?.locked).toBe(true)
  })

  it('explicit cells are sent as they are; a flat switch spreads over the matrix without touching a locked cell', () => {
    const matrix = toPreferences(prefsDto()).matrix!
    expect(cellsFor({ cells: [{ category: 'PROMOTIONS', channel: 'EMAIL', enabled: true }] }, matrix)).toEqual([{ category: 'PROMOTIONS', channel: 'EMAIL', enabled: true }])
    const sms = cellsFor({ sms: false }, matrix)
    expect(sms.map((c) => c.category)).toEqual(['ORDER_UPDATES', 'PAYMENT_UPDATES', 'PROMOTIONS'])
    expect(sms.every((c) => c.channel === 'SMS' && c.enabled === false)).toBe(true)
    expect(cellsFor({ promotions: true }, matrix).length).toBe(4)
  })

  it('reads the matrix and PATCHes cells; the inbox stays development data', async () => {
    reply = () => ({ body: prefsDto() })
    const repo = new ApiNotificationRepository()
    expect(repo.inboxIsDevelopmentData).toBe(true)
    expect((await repo.getPreferences()).promotions).toBe(false)
    await repo.updatePreferences('u', { cells: [{ category: 'PROMOTIONS', channel: 'EMAIL', enabled: true }] })
    expect(calls.find((c) => c.method === 'PATCH')?.body).toEqual({ preferences: [{ category: 'PROMOTIONS', channel: 'EMAIL', enabled: true }] })
    reply = () => error(422, 'validation_failed', 'The submitted data is invalid.', { 'preferences.0.enabled': ['Security notices on this channel cannot be switched off.'] })
    const e = await repo.updatePreferences('u', { cells: [{ category: 'ACCOUNT_SECURITY', channel: 'SMS', enabled: false }] }).catch((x: unknown) => x)
    expect((e as RepositoryError).message).toBe('Security notices on this channel cannot be switched off.')
  })
})

describe('which implementation runs', () => {
  it('follows the sign-in mode: API sign-in → the backend account, mock sign-in → development data', () => {
    const api = selectAccountRepositories('api')
    expect(api.profile).toBeInstanceOf(ApiProfileRepository)
    expect(api.account.favorites).toBeInstanceOf(ApiFavoriteRepository)
    expect(api.account.notifications.inboxIsDevelopmentData).toBe(true)
    const mock = selectAccountRepositories('mock')
    expect(mock.profile).toBeInstanceOf(MockProfileRepository)
    expect(mock.account.favorites).toBeInstanceOf(MockFavoriteRepository)
    expect(mock.profile.security).toBeUndefined()
  })
})

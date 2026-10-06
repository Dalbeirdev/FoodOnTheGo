/**
 * Pickup times on the backend (Module 28): the mapping of the pickup API onto what the pages read (every time, capacity
 * state and recommendation is the backend's), what the gateway sends (a mode, a method and a slot key — never a time, a
 * restaurant or a capacity; the guest cart token in a header only), the error vocabulary, the provider on that gateway
 * (choose, slot full, validate before checkout, cart changes) and the Pickup time page showing the backend's answer.
 * The network is stubbed with payloads in the shape of the real API (OpenAPI: PickupAvailabilityEnvelope, PickupSelectionEnvelope).
 */
import { act, cleanup, render, renderHook, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { tokens } from '../../api/client'
import { AuthProvider } from '../../auth/AuthContext'
import { cartToken, resetCartApiState, toCart, type CartDto } from '../../cart/api/apiCart'
import { CartContext, type CartApi } from '../../cart/cartApi'
import { LocaleProvider } from '../../i18n/LocaleProvider'
import { JourneyProvider } from '../../journey/JourneyContext'
import PickupTimePage from '../../pages/PickupTimePage'
import { setMockRestaurantLatency } from '../../repositories/mock/restaurants'
import { usePickup } from '../pickupApi'
import { ServerPickupProvider } from '../ServerPickupProvider'
import { ApiPickupGateway, PickupApiError, toAnswer, toPickupError, toSelection, toValidation, type PickupAvailabilityDto, type PickupChoice, type PickupIssueDto, type PickupSelectionDto } from './apiPickup'

const R1 = '0a000000-0000-4000-8000-0000000000a1'
const CART = '5f000000-0000-4000-8000-000000000001'
const TOKEN = `gc1.abc123.${'a'.repeat(64)}.${'b'.repeat(32)}`
const TZ = 'Asia/Kolkata'
/** 12:00 in India on 7 October 2026 — the instant every fixture below is built around. */
const NOON = Date.parse('2026-10-07T06:30:00Z')
const at = (minutes: number) => new Date(NOON + minutes * 60000).toISOString().replace('.000Z', '+00:00')
const local = (minutes: number) => { const d = new Date(NOON + minutes * 60000 + 330 * 60000); return `${d.toISOString().slice(0, 19)}+05:30` }
const key = (minutes: number) => `ps1_${(NOON / 1000 + minutes * 60).toString(36)}_15_${'ab12cd34ef56'}`

const issue = (code: string, severity: PickupIssueDto['severity'] = 'BLOCKING', details: Record<string, unknown> = {}): PickupIssueDto => ({ code, severity, message: `${code} message`, details })
const slot = (minutes: number, patch: Partial<PickupAvailabilityDto['slots'][number]> = {}): PickupAvailabilityDto['slots'][number] => ({
  slot_key: key(minutes), starts_at: at(minutes), ends_at: at(minutes + 15), local_starts_at: local(minutes), local_date: local(minutes).slice(0, 10), available: true, capacity_state: 'AVAILABLE', recommended: false, reason: null,
  arrival_vs_pickup_delta_seconds: null, ...patch,
})
const selectionDto = (patch: Partial<PickupSelectionDto> = {}): PickupSelectionDto => ({
  id: '7a000000-0000-4000-8000-000000000001', mode: 'SCHEDULED', method: 'COUNTER', pickup_at: at(75), local_pickup_at: local(75), local_date: '2026-10-07', slot_key: key(75), slot_ends_at: at(90), timezone: TZ, cart_version: 4,
  status: 'SELECTED', valid: true, reserved: false, issues: [], estimated_prep_minutes: 20, traveler_arrival_at: null, arrival_vs_pickup_delta_seconds: null, selected_at: at(0), validated_at: at(0), expires_at: at(75), revalidate_after: at(1), version: 1, ...patch,
})
const availabilityDto = (patch: Partial<PickupAvailabilityDto> = {}): PickupAvailabilityDto => ({
  restaurant: { id: R1, slug: 'burger-hub', name: 'Burger Hub', timezone: TZ }, timezone: TZ, cart_version: 4, pickup_enabled: true, can_select: true,
  methods: [{ method: 'COUNTER', instructions: 'Show your code at the counter.', requires_vehicle_info: false }], method: 'COUNTER',
  preparation: { estimated_minutes: 20, buffer_minutes: 0, minimum_lead_minutes: 15, ready_in_minutes: 20, is_estimate: true },
  earliest_pickup_at: at(20),
  asap: { enabled: true, available: true, pickup_at: at(20), local_pickup_at: local(20), estimated_ready_in_minutes: 20, recommended: true, reason: null, arrival_vs_pickup_delta_seconds: null },
  scheduled: { enabled: true, interval_minutes: 15, horizon_minutes: 1440, horizon_ends_at: at(1440), slot_count: 5, available_slot_count: 4, truncated: false },
  // 12:30, 12:45 (limited), 13:00 (full), 13:15 today; 09:00 tomorrow.
  slots: [slot(30), slot(45, { capacity_state: 'LIMITED' }), slot(60, { available: false, capacity_state: 'FULL', reason: 'SLOT_FULL' }), slot(75), slot(1260)],
  recommended: { mode: 'ASAP', slot_key: null, pickup_at: at(20), local_pickup_at: local(20), basis: 'KITCHEN', arrival_vs_pickup_delta_seconds: null },
  journey: null, selection: null, issues: [], generated_at: at(0), revalidate_after: at(1), ...patch,
})
/** A traveller expected at 13:10: the backend recommends 13:15, not ASAP. */
const withJourney = (): PickupAvailabilityDto => availabilityDto({
  asap: { ...availabilityDto().asap, recommended: false, arrival_vs_pickup_delta_seconds: -3000 },
  slots: [slot(30, { arrival_vs_pickup_delta_seconds: -2400 }), slot(45, { arrival_vs_pickup_delta_seconds: -1500 }), slot(60, { arrival_vs_pickup_delta_seconds: -600 }), slot(75, { recommended: true, arrival_vs_pickup_delta_seconds: 300 })],
  recommended: { mode: 'SCHEDULED', slot_key: key(75), pickup_at: at(75), local_pickup_at: local(75), basis: 'ARRIVAL', arrival_vs_pickup_delta_seconds: 300 },
  journey: { status: 'ACTIVE', traveler_arrival_at: at(70), local_traveler_arrival_at: local(70), requires_refresh: false, estimate_source: 'development', arrival_buffer_minutes: 0 },
})
const cartDto = (patch: Partial<CartDto> = {}): CartDto => ({
  id: CART, version: 4, status: 'ACTIVE', owner: 'guest', currency: 'INR', restaurant: { id: R1, slug: 'burger-hub', name: 'Burger Hub', visible: true }, journey: null,
  items: [{
    id: '2c000000-0000-4000-8000-000000000001', item: { id: '1b000000-0000-4000-8000-000000000001', slug: 'classic-burger', name: 'Classic Burger', image: null, version: 3 }, name: 'Classic Burger', quantity: 2, min_quantity: 1, max_quantity: 20,
    special_instructions: null, variants: [], modifiers: [], base_price_minor: 20000, unit_price_minor: 20000, line_subtotal_minor: 40000, currency: 'INR',
    snapshot: { unit_price_minor: 20000, base_price_minor: 20000, item_version: 3, taken_at: at(0) }, price_changed: false, priced_from: 'menu', included_in_subtotal: true, availability: { orderable: true, reason: null }, issues: [],
  }],
  item_count: 2, line_count: 1, subtotal_minor: 40000, validation: { status: 'VALID', can_proceed: true, issues: [], menu_changed: false, catalog_version: 7, checked_at: at(0) },
  expires_at: at(10000), created_at: at(-5), updated_at: at(-5), ...patch,
})

/* ------------------------------------------------------------------ network stub */
type Call = { method: string; path: string; url: string; query: Record<string, string>; body: Record<string, unknown> | undefined; headers: Record<string, string> }
type Reply = { status?: number; body: unknown } | 'network'
let calls: Call[] = []
let reply: (method: string, path: string, call: Call) => Reply = () => ({ body: { selection: null } })
const error = (status: number, code: string, message: string, details: Record<string, unknown> = {}): Reply => ({ status, body: { error: { code, message, details, request_id: 'r1' } } })
/** The error a call fails with. */
const failure = async (call: Promise<unknown>): Promise<PickupApiError> => { try { await call } catch (e) { return e as PickupApiError } throw new Error('expected the call to fail') }

beforeEach(() => {
  calls = []; sessionStorage.clear(); localStorage.clear(); resetCartApiState(); tokens.set('customer', null); setMockRestaurantLatency(0)
  reply = () => ({ body: { selection: null } })
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const u = new URL(url)
    const call: Call = { method: init.method ?? 'GET', path: decodeURIComponent(u.pathname.replace('/api/v1', '')), url, query: Object.fromEntries(u.searchParams), body: init.body ? JSON.parse(init.body as string) : undefined, headers: (init.headers ?? {}) as Record<string, string> }
    calls.push(call)
    const r = reply(call.method, call.path, call)
    if (r === 'network') throw new TypeError('fetch failed')
    return new Response(JSON.stringify(r.body), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } })
  }))
})
afterEach(() => { cleanup(); vi.unstubAllGlobals() })

/* ------------------------------------------------------------------ mapping */
describe('pickup API mapping', () => {
  const cart = { id: CART, restaurantId: 'burger-hub' }

  it('shows the backend\'s times, capacity states and recommendation as they are', () => {
    const a = toAnswer(withJourney(), cart)
    expect(a.server).toMatchObject({ restaurantName: 'Burger Hub', timezone: TZ, cartVersion: 4, canSelect: true, method: 'COUNTER' })
    expect(a.server.asap).toEqual({ enabled: true, available: true, pickupAt: at(20), readyInMinutes: 20, recommended: false, reason: null, arrivalDeltaSeconds: -3000 })
    expect(a.server.recommended).toEqual({ mode: 'scheduled', slotId: key(75), pickupAt: at(75), basis: 'ARRIVAL', arrivalDeltaSeconds: 300 })
    expect(a.server.journey).toEqual({ status: 'ACTIVE', arrivalAt: at(70), requiresRefresh: false, source: 'development' })
    // Slots keep the backend's instants and its key; the badge is the backend's flag, on one slot only.
    expect(a.server.allSlots.map((s) => [s.id, s.startAt, s.localDate, s.recommended])).toEqual([[key(30), at(30), '2026-10-07', false], [key(45), at(45), '2026-10-07', false], [key(60), at(60), '2026-10-07', false], [key(75), at(75), '2026-10-07', true]])
    expect(a.preparation?.estimated_minutes).toBe(20)
    expect(a.methods).toEqual([{ id: 'COUNTER', type: 'counter', label: '', instructions: 'Show your code at the counter.', enabled: true, requiresVehicleInfo: false }])
  })

  it('maps capacity without ever inventing a count, and a full slot is not available', () => {
    const slots = toAnswer(availabilityDto(), cart).server.allSlots
    expect(slots.map((s) => [s.capacityStatus, s.available, s.reasonUnavailable])).toEqual([['available', true, undefined], ['limited', true, undefined], ['full', false, 'full'], ['available', true, undefined], ['available', true, undefined]])
    expect(JSON.stringify(slots)).not.toMatch(/remaining|units|capacity_per_slot/)
    expect(slots[4].localDate).toBe('2026-10-08')
  })

  it('turns the stored selection into what checkout already reads', () => {
    expect(toSelection(null, cart)).toBeNull()
    expect(toSelection(selectionDto({ traveler_arrival_at: at(70) }), cart)).toEqual({
      mode: 'scheduled', slotId: key(75), requestedAt: at(75), restaurantTimezone: TZ, estimatedCustomerArrival: at(70), estimatedReadyTime: at(75), confirmedDisplayTime: '', cartId: CART, restaurantId: 'burger-hub',
    })
    expect(toSelection(selectionDto({ mode: 'ASAP', slot_key: null, slot_ends_at: null, pickup_at: at(20) }), cart)).toMatchObject({ mode: 'asap', slotId: null, requestedAt: at(20) })
  })

  it('says why a selection is no longer valid', () => {
    const invalid = (code: string, status: PickupSelectionDto['status'] = 'INVALID') => toValidation(selectionDto({ status, valid: false, issues: [issue(code)] }))
    expect(toValidation(null)).toEqual({ ok: false, reason: 'slot_missing' })
    expect(toValidation(selectionDto())).toEqual({ ok: true })
    expect(invalid('SLOT_FULL')).toEqual({ ok: false, reason: 'slot_unavailable' })
    expect(invalid('SLOT_EXPIRED', 'EXPIRED')).toEqual({ ok: false, reason: 'past' })
    expect(invalid('RESTAURANT_NOT_ACCEPTING')).toEqual({ ok: false, reason: 'not_accepting' })
    for (const code of ['RESTAURANT_UNAVAILABLE', 'PICKUP_DISABLED', 'MARKET_UNAVAILABLE']) expect(invalid(code)).toEqual({ ok: false, reason: 'restaurant_inactive' })
    for (const code of ['CART_INVALID', 'CART_CHANGED', 'CART_EXPIRED']) expect(invalid(code, 'STALE')).toEqual({ ok: false, reason: 'cart_invalid' })
    for (const code of ['SLOT_INVALID', 'SLOT_TOO_EARLY', 'SLOT_OUTSIDE_HORIZON', 'SCHEDULED_DISABLED', 'RESTAURANT_CLOSED', 'CLOSES_TOO_SOON']) expect(invalid(code)).toEqual({ ok: false, reason: 'outside_schedule' })
  })
})

/* ------------------------------------------------------------------ gateway */
describe('ApiPickupGateway', () => {
  const gw = () => new ApiPickupGateway()

  it('asks as the cart\'s owner — the guest token in a header, nothing about a restaurant, a customer or a time', async () => {
    cartToken.set(TOKEN)
    reply = (_m, path) => (path === '/cart/pickup-availability' ? { body: { pickup: availabilityDto() } } : { body: { selection: null } })

    const dto = await gw().availability()
    await gw().availability('COUNTER')
    await gw().selection()

    expect(dto.restaurant.id).toBe(R1)
    const [first, second, third] = calls
    expect([first.method, first.path, first.query, first.body]).toEqual(['GET', '/cart/pickup-availability', {}, undefined])
    expect(second.query).toEqual({ method: 'COUNTER' })
    expect(third.path).toBe('/cart/pickup-selection')
    for (const call of calls) {
      expect(call.headers['X-Cart-Token']).toBe(TOKEN)
      expect(call.url).not.toContain('gc1.')
      expect(call.url).not.toMatch(/restaurant|customer|cart_id|time|now/)
    }
  })

  it('a choice is a mode, a method and a slot key — never a time', async () => {
    cartToken.set(TOKEN)
    reply = () => ({ body: { selection: selectionDto() } })

    await gw().select({ mode: 'scheduled', slotKey: key(75), method: 'COUNTER', cartVersion: 4 })
    await gw().select({ mode: 'asap' })

    const [scheduled, asap] = calls
    expect(scheduled.body).toEqual({ mode: 'SCHEDULED', slot_key: key(75), method: 'COUNTER', cart_version: 4 })
    expect(asap.body).toEqual({ mode: 'ASAP' })
    for (const call of calls) {
      expect(call.method).toBe('POST')
      expect(call.headers['Idempotency-Key']).toMatch(/^pku-/)
      expect(JSON.stringify(call.body)).not.toMatch(/pickup_at|starts_at|timezone|restaurant|customer|capacity|status|valid|reserved/)
    }
    expect(scheduled.headers['Idempotency-Key']).not.toBe(asap.headers['Idempotency-Key'])
  })

  it('a signed-in customer is the customer: the session is sent, the guest token is not', async () => {
    cartToken.set(TOKEN); tokens.set('customer', 'session-token')
    reply = () => ({ body: { selection: selectionDto() } })

    await gw().selection(); await gw().select({ mode: 'asap' }); await gw().clear()

    for (const call of calls) {
      expect(call.headers['X-Cart-Token']).toBeUndefined()
      expect(call.headers.Authorization).toBe('Bearer session-token')
    }
    expect(calls.map((c) => c.method)).toEqual(['GET', 'POST', 'DELETE'])
  })

  it('after a lost connection asks once more with the SAME idempotency key', async () => {
    let attempt = 0
    reply = () => (++attempt === 1 ? 'network' : { body: { selection: selectionDto() } })

    const chosen = await gw().select({ mode: 'scheduled', slotKey: key(75) })

    expect(chosen.status).toBe('SELECTED')
    expect(calls).toHaveLength(2)
    expect(calls[0].headers['Idempotency-Key']).toBe(calls[1].headers['Idempotency-Key'])
    expect(calls[0].body).toEqual(calls[1].body)
  })

  it('speaks the backend\'s error vocabulary, and no connection is never "still valid"', async () => {
    const codes: Array<[number, string, string]> = [
      [404, 'cart_not_found', 'no_cart'], [409, 'cart_empty', 'cart_empty'], [409, 'cart_invalid', 'cart_invalid'], [409, 'cart_version_conflict', 'cart_changed'], [410, 'cart_expired', 'cart_expired'],
      [409, 'slot_full', 'slot_full'], [409, 'slot_expired', 'slot_expired'], [422, 'slot_invalid', 'slot_invalid'], [422, 'slot_outside_horizon', 'slot_invalid'], [409, 'asap_unavailable', 'asap_unavailable'],
      [422, 'pickup_mode_unavailable', 'mode_unavailable'], [422, 'pickup_method_unavailable', 'method_unavailable'], [409, 'restaurant_unavailable', 'restaurant_unavailable'], [409, 'market_unavailable', 'restaurant_unavailable'],
      [409, 'restaurant_not_accepting', 'not_accepting'], [409, 'pickup_disabled', 'pickup_disabled'], [429, 'rate_limited', 'rate_limited'], [500, 'server_error', 'error'],
    ]
    for (const [status, code, expected] of codes) {
      reply = () => error(status, code, `${code} from the backend`, { reason: 'X' })
      const err = await failure(gw().select({ mode: 'asap' }))
      expect(err).toBeInstanceOf(PickupApiError)
      expect(err.code).toBe(expected)
      // The backend's own words reach the page (a server fault is worded by the client instead).
      if (status < 500) expect([err.message, err.details]).toEqual([`${code} from the backend`, { reason: 'X' }])
    }
    reply = () => 'network'
    const offline = await failure(gw().availability())
    expect(offline.code).toBe('offline')
    expect(offline.message).toMatch(/need a connection to be confirmed/)
    expect(toPickupError(new Error('x')).code).toBe('error')
  })
})

/* ------------------------------------------------------------------ provider + page */
class ScriptedGateway {
  dto: PickupAvailabilityDto = availabilityDto()
  stored: PickupSelectionDto | null = null
  log: string[] = []
  fail: Partial<Record<string, PickupApiError>> = {}
  /** What the backend does to its state when a choice is refused (e.g. the slot now shows as full). */
  onRefused: (() => void) | null = null
  private step(name: string) { this.log.push(name); const e = this.fail[name.split(' ')[0]]; if (e) { this.onRefused?.(); throw e } }
  async availability(method?: string | null) { this.step(`availability${method ? ` ${method}` : ''}`); return { ...this.dto, selection: this.stored } }
  async selection() { this.step('selection'); return this.stored }
  async select(choice: PickupChoice) {
    this.step(`select ${choice.mode} ${choice.slotKey ?? '-'} v${choice.cartVersion ?? '-'} ${choice.method ?? '-'}`)
    const chosen = this.dto.slots.find((s) => s.slot_key === choice.slotKey)
    this.stored = choice.mode === 'asap'
      ? selectionDto({ mode: 'ASAP', slot_key: null, slot_ends_at: null, pickup_at: this.dto.asap.pickup_at ?? at(20), local_pickup_at: this.dto.asap.local_pickup_at ?? local(20) })
      : selectionDto({ slot_key: chosen!.slot_key, pickup_at: chosen!.starts_at, local_pickup_at: chosen!.local_starts_at, local_date: chosen!.local_date, slot_ends_at: chosen!.ends_at })
    return this.stored
  }
  async clear() { this.step('clear'); this.stored = null }
}

const fakeCart = (dto: CartDto | null = cartDto(), reload = vi.fn(async () => {})): CartApi => ({
  mode: 'api', hydrated: true, cart: toCart(dto), count: dto?.item_count ?? 0, subtotalMinor: dto?.subtotal_minor ?? 0, discountMinor: 0, currency: dto?.currency ?? 'INR', lines: [], status: 'active', note: '', promo: null,
  loadFailed: false, lastError: null, flash: null, conflict: null, lastAdded: null, reload,
} as unknown as CartApi)

function setup(gateway: ScriptedGateway, cart: { current: CartApi } = { current: fakeCart() }) {
  const wrapper = ({ children }: { children: ReactNode }) => (
    <CartContext.Provider value={cart.current}><ServerPickupProvider gateway={gateway as unknown as ApiPickupGateway}>{children}</ServerPickupProvider></CartContext.Provider>
  )
  return renderHook(() => usePickup(), { wrapper })
}
const noInput = {} as Parameters<ReturnType<typeof usePickup>['load']>[0]

describe('ServerPickupProvider', () => {
  it('shows what the backend offers: modes, days, the slots of one restaurant-local day, the estimate', async () => {
    const gateway = new ScriptedGateway()
    const { result } = setup(gateway)
    await act(async () => { await result.current.load(noInput) })

    expect(result.current).toMatchObject({ source: 'api', status: 'AVAILABLE', mode: 'asap', date: '2026-10-07', days: ['2026-10-07', '2026-10-08'], eta: null, selection: null, error: null, busy: false, method: 'COUNTER' })
    expect(result.current.slots.map((s) => s.startAt)).toEqual([at(30), at(45), at(60), at(75)])
    expect(result.current.settings).toMatchObject({ timezone: TZ, intervalMinutes: 15, minimumLeadMinutes: 15, modes: ['asap', 'scheduled'], instructions: 'Show your code at the counter.' })
    expect(result.current.estimate).toEqual({ prepMinutes: 20, bufferMinutes: 0, earliestPickupAt: at(20), restaurantTimezone: TZ, source: 'backend' })
    act(() => { result.current.setDate('2026-10-08') })
    expect(result.current.slots.map((s) => s.startAt)).toEqual([at(1260)])
    // Choosing a day asks nobody: the answer already holds every offered slot.
    expect(gateway.log.filter((l) => l.startsWith('availability'))).toHaveLength(1)
  })

  it('opens on the slots when ASAP is not possible, and on the chosen mode when there is a selection', async () => {
    const closed = new ScriptedGateway()
    closed.dto = availabilityDto({ asap: { ...availabilityDto().asap, available: false, pickup_at: null, local_pickup_at: null, estimated_ready_in_minutes: null, recommended: false, reason: 'RESTAURANT_CLOSED' }, issues: [issue('RESTAURANT_CLOSED', 'INFO')] })
    const first = setup(closed)
    await act(async () => { await first.result.current.load(noInput) })
    expect(first.result.current.mode).toBe('scheduled')
    expect(first.result.current.server?.asap.reason).toBe('RESTAURANT_CLOSED')

    const chosen = new ScriptedGateway(); chosen.stored = selectionDto()
    const second = setup(chosen)
    await act(async () => { await second.result.current.load(noInput) })
    expect(second.result.current).toMatchObject({ status: 'SELECTED', mode: 'scheduled' })
    expect(second.result.current.selection).toMatchObject({ slotId: key(75), requestedAt: at(75), cartId: CART })
  })

  it('READY: after a page load "no selection" means "not known yet" until the backend has been asked for this cart', async () => {
    const gateway = new ScriptedGateway()
    gateway.stored = selectionDto()
    let release: () => void = () => {}
    const original = gateway.selection.bind(gateway)
    gateway.selection = async () => { await new Promise<void>((r) => { release = r }); return original() }
    const { result } = setup(gateway)
    // The cart is there, the stored choice has not been read yet: nothing may act on "no selection".
    await waitFor(() => expect(gateway.log.length + 1).toBeGreaterThan(0))
    expect(result.current.ready).toBe(false)
    expect(result.current.selection).toBeNull()
    await act(async () => { await new Promise((r) => setTimeout(r, 5)); release(); await new Promise((r) => setTimeout(r, 5)) })
    await waitFor(() => expect(result.current.ready).toBe(true))
    expect(result.current.selection).toMatchObject({ slotId: key(75) })
    expect(result.current.status).toBe('SELECTED')
  })

  it('READY also when the stored choice could not be read (checkout then reports it) and when there is no cart', async () => {
    const failing = new ScriptedGateway()
    failing.fail = { selection: new PickupApiError('offline', 'Cannot reach FoodOnTheGo right now. Pickup times need a connection to be confirmed.') }
    const first = setup(failing)
    await waitFor(() => expect(first.result.current.ready).toBe(true))
    expect(first.result.current.selection).toBeNull()

    const none = new ScriptedGateway()
    const second = setup(none, { current: fakeCart(null) })
    await waitFor(() => expect(second.result.current.ready).toBe(true))
    expect(none.log).toEqual([])
  })

  it('never opens on a mode the restaurant does not offer: an ASAP choice after ASAP was switched off opens on the slots', async () => {
    const gateway = new ScriptedGateway()
    gateway.dto = availabilityDto({ asap: { ...availabilityDto().asap, enabled: false, available: false, pickup_at: null, local_pickup_at: null, estimated_ready_in_minutes: null, recommended: false, reason: 'ASAP_DISABLED' } })
    gateway.stored = selectionDto({ mode: 'ASAP', slot_key: null, slot_ends_at: null, status: 'INVALID', valid: false, issues: [issue('ASAP_DISABLED')] })
    const { result } = setup(gateway)
    await act(async () => { await result.current.load(noInput) })
    expect(result.current.settings?.modes).toEqual(['scheduled'])
    expect(result.current).toMatchObject({ status: 'STALE', mode: 'scheduled' })
    expect(result.current.slots.length).toBeGreaterThan(0)
  })

  it('a choice counts once the backend has accepted it — with the cart version the page saw', async () => {
    const gateway = new ScriptedGateway()
    const { result } = setup(gateway)
    await act(async () => { await result.current.load(noInput) })

    await act(async () => { result.current.selectSlot(result.current.slots[3]) })
    expect(gateway.log).toContain(`select scheduled ${key(75)} v4 COUNTER`)
    expect(result.current).toMatchObject({ status: 'SELECTED', mode: 'scheduled', error: null })
    expect(result.current.selection).toMatchObject({ mode: 'scheduled', slotId: key(75), requestedAt: at(75), restaurantTimezone: TZ })

    await act(async () => { result.current.selectAsap() })
    expect(gateway.log).toContain('select asap - v4 COUNTER')
    expect(result.current.selection).toMatchObject({ mode: 'asap', slotId: null, requestedAt: at(20) })

    // A slot the backend marked as full is not sent at all.
    const before = gateway.log.length
    await act(async () => { result.current.selectSlot(result.current.slots[2]) })
    expect(gateway.log).toHaveLength(before)
  })

  it('a slot that filled up meanwhile is said so, shown as full, and nothing is selected', async () => {
    const gateway = new ScriptedGateway()
    const { result } = setup(gateway)
    await act(async () => { await result.current.load(noInput) })
    gateway.fail.select = new PickupApiError('slot_full', 'That pickup time is no longer available. Please select another time.')
    gateway.onRefused = () => { gateway.dto = availabilityDto({ slots: [slot(30), slot(45, { capacity_state: 'LIMITED' }), slot(60, { available: false, capacity_state: 'FULL', reason: 'SLOT_FULL' }), slot(75, { available: false, capacity_state: 'FULL', reason: 'SLOT_FULL' })] }) }

    await act(async () => { result.current.selectSlot(result.current.slots[3]) })

    expect(result.current.error).toBe('That pickup time is no longer available. Please select another time.')
    expect(result.current.selection).toBeNull()
    expect(result.current.status).toBe('AVAILABLE')
    expect(result.current.slots[3]).toMatchObject({ available: false, capacityStatus: 'full' })
    expect(gateway.log.filter((l) => l.startsWith('availability'))).toHaveLength(2)
  })

  it('a cart that changed under the page is reloaded before anything is chosen', async () => {
    const gateway = new ScriptedGateway()
    const reload = vi.fn(async () => {})
    const { result } = setup(gateway, { current: fakeCart(cartDto(), reload) })
    await act(async () => { await result.current.load(noInput) })
    gateway.fail.select = new PickupApiError('cart_changed', 'Your cart changed somewhere else. Reload it and try again.')

    await act(async () => { result.current.selectAsap() })

    expect(reload).toHaveBeenCalledTimes(1)
    expect(result.current.error).toBe('Your cart changed somewhere else. Reload it and try again.')
    expect(result.current.selection).toBeNull()
  })

  it('before checkout the backend judges the choice again; an invalid one is stale with the backend\'s reason', async () => {
    const gateway = new ScriptedGateway(); gateway.stored = selectionDto()
    const { result } = setup(gateway)
    await act(async () => { await result.current.load(noInput) })
    const restaurant = {} as Parameters<ReturnType<typeof usePickup>['validate']>[0]

    let verdict = await act(async () => result.current.validate(restaurant))
    expect(verdict).toEqual({ ok: true })
    expect(result.current.status).toBe('SELECTED')

    gateway.stored = selectionDto({ status: 'INVALID', valid: false, issues: [issue('SLOT_FULL')] })
    verdict = await act(async () => result.current.validate(restaurant))
    expect(verdict).toEqual({ ok: false, reason: 'slot_unavailable' })
    expect(result.current).toMatchObject({ status: 'STALE', staleReason: 'slot_unavailable' })
    expect(result.current.server?.selection).toMatchObject({ status: 'INVALID', valid: false })
    expect(result.current.server?.selection?.issues[0]).toMatchObject({ code: 'SLOT_FULL', message: 'SLOT_FULL message' })

    // Without a connection nothing is confirmed: the old choice is not taken for a valid one.
    gateway.fail.selection = new PickupApiError('offline', 'Cannot reach FoodOnTheGo right now. Pickup times need a connection to be confirmed.')
    await act(async () => { await expect(result.current.validate(restaurant)).rejects.toMatchObject({ code: 'offline' }) })
    expect(result.current).toMatchObject({ status: 'ERROR', error: 'Cannot reach FoodOnTheGo right now. Pickup times need a connection to be confirmed.' })
  })

  it('reads the choice again when the cart changes, and forgets it when the cart is gone', async () => {
    const gateway = new ScriptedGateway(); gateway.stored = selectionDto()
    const cart = { current: fakeCart() }
    const { result, rerender } = setup(gateway, cart)
    await waitFor(() => expect(result.current.status).toBe('SELECTED'))
    expect(result.current.selection?.requestedAt).toBe(at(75))
    const reads = () => gateway.log.filter((l) => l === 'selection').length
    expect(reads()).toBe(1)

    // Another item made the order slower: the backend now says the chosen time no longer fits.
    gateway.stored = selectionDto({ status: 'STALE', valid: false, cart_version: 4, issues: [issue('CART_CHANGED')] })
    cart.current = fakeCart(cartDto({ version: 5 })); rerender()
    await waitFor(() => expect(result.current.status).toBe('STALE'))
    expect(reads()).toBe(2)
    expect(result.current.staleReason).toBe('cart_invalid')

    // The cart was cleared.
    cart.current = fakeCart(null); rerender()
    await waitFor(() => expect(result.current.status).toBe('NOT_SELECTED'))
    expect(result.current.selection).toBeNull()
    expect(result.current.server).toBeNull()
  })

  it('clearing forgets the choice on the backend; another method asks for that method', async () => {
    const gateway = new ScriptedGateway(); gateway.stored = selectionDto()
    gateway.dto = availabilityDto({ methods: [...availabilityDto().methods, { method: 'DRIVE_THROUGH', instructions: 'Use lane 2.', requires_vehicle_info: true }] })
    const { result } = setup(gateway)
    await act(async () => { await result.current.load(noInput) })
    expect(result.current.settings?.methods.map((m) => m.type)).toEqual(['counter', 'drive_through'])

    await act(async () => { result.current.clear() })
    expect(gateway.log).toContain('clear')
    expect(result.current).toMatchObject({ status: 'AVAILABLE', selection: null })

    await act(async () => { result.current.setMethod('DRIVE_THROUGH') })
    expect(gateway.log).toContain('availability DRIVE_THROUGH')
  })

  it('no cart means nothing to choose a time for — not an error', async () => {
    const gateway = new ScriptedGateway()
    gateway.fail.availability = new PickupApiError('no_cart', 'There is no cart to choose a pickup time for.')
    const { result } = setup(gateway)
    await act(async () => { await result.current.load(noInput) })
    expect(result.current).toMatchObject({ status: 'NOT_SELECTED', error: null, server: null })

    gateway.fail.availability = new PickupApiError('offline', 'Cannot reach FoodOnTheGo right now. Pickup times need a connection to be confirmed.')
    await act(async () => { await result.current.refresh() })
    expect(result.current).toMatchObject({ status: 'ERROR', error: 'Cannot reach FoodOnTheGo right now. Pickup times need a connection to be confirmed.' })
  })
})

describe('Pickup time page on the backend', () => {
  const page = (gateway: ScriptedGateway, cart: CartApi = fakeCart()) => render(
    <MemoryRouter initialEntries={['/pickup-time']}><LocaleProvider locale="en-IN"><AuthProvider><JourneyProvider><CartContext.Provider value={cart}><ServerPickupProvider gateway={gateway as unknown as ApiPickupGateway}>
      <Routes><Route path="/pickup-time" element={<PickupTimePage />} /><Route path="/checkout" element={<p>checkout page</p>} /><Route path="/cart" element={<p>cart page</p>} /><Route path="*" element={<p>other</p>} /></Routes>
    </ServerPickupProvider></CartContext.Provider></JourneyProvider></AuthProvider></LocaleProvider></MemoryRouter>,
  )
  const slots = () => screen.getAllByTestId('pk-slot')
  const scheduledTab = () => screen.getByRole('radio', { name: /Schedule for later/i })

  it('shows ASAP and the slots as the backend gave them, with the badge only where the backend put it', async () => {
    const gateway = new ScriptedGateway()
    page(gateway)

    // ASAP is the backend's recommendation here; nothing says "development".
    expect(await screen.findByTestId('pk-asap-time')).toHaveTextContent(/12:20/)
    expect(screen.getByTestId('pk-asap-recommended')).toBeInTheDocument()
    expect(screen.queryByText(/development pickup times/i)).not.toBeInTheDocument()
    expect(screen.getAllByText(/Estimated preparation 20/).length).toBeGreaterThan(0)
    expect(screen.getByTestId('pk-journey')).toHaveAttribute('data-journey-status', 'NONE')

    await userEvent.click(scheduledTab())
    expect(slots().map((s) => [s.getAttribute('data-slot-start'), s.getAttribute('data-capacity'), s.getAttribute('data-recommended'), (s as HTMLButtonElement).disabled])).toEqual([
      [at(30), 'available', 'false', false], [at(45), 'limited', 'false', false], [at(60), 'full', 'false', true], [at(75), 'available', 'false', false],
    ])
    expect(within(slots()[2]).getByText('Full')).toBeInTheDocument()
    expect(within(slots()[1]).getByText('Limited')).toBeInTheDocument()
    expect(screen.queryByText('Recommended', { selector: '.pk-slot .pk-badge' })).not.toBeInTheDocument()
    expect(screen.getAllByTestId('pk-day').map((d) => d.getAttribute('data-date'))).toEqual(['2026-10-07', '2026-10-08'])
  })

  it('with a journey shows the estimated arrival and the slot the backend recommends for it', async () => {
    const gateway = new ScriptedGateway(); gateway.dto = withJourney()
    page(gateway)

    await waitFor(() => expect(screen.getByTestId('pk-journey')).toHaveAttribute('data-journey-status', 'ACTIVE'))
    const journey = screen.getByTestId('pk-journey')
    expect(journey).toHaveTextContent(/Your estimated arrival ~1:10/)
    expect(journey).toHaveTextContent(/development estimate/)
    expect(screen.queryByTestId('pk-asap-recommended')).not.toBeInTheDocument()

    await userEvent.click(scheduledTab())
    expect(slots().filter((s) => s.getAttribute('data-recommended') === 'true').map((s) => s.getAttribute('data-slot-start'))).toEqual([at(75)])
    expect(screen.getByTestId('pk-recommended-note')).toHaveTextContent(/1:15/)

    // The customer may still choose an earlier valid time; the page then says the order may be ready first.
    await userEvent.click(slots()[0])
    await waitFor(() => expect(screen.getByTestId('pk-summary')).toHaveAttribute('data-pickup-at', at(30)))
    expect(gateway.log).toContain(`select scheduled ${key(30)} v4 COUNTER`)
    expect(screen.getByTestId('pk-not-reserved')).toHaveTextContent(/selected, not reserved/)
    expect(document.body.textContent).not.toMatch(/is reserved|has been reserved|slot reserved/i)
  })

  it('a stale journey is said so and is not used for a recommendation', async () => {
    const gateway = new ScriptedGateway()
    gateway.dto = availabilityDto({ journey: { status: 'STALE', traveler_arrival_at: null, local_traveler_arrival_at: null, requires_refresh: true, estimate_source: 'development', arrival_buffer_minutes: 0 }, issues: [issue('JOURNEY_STALE', 'INFO')] })
    page(gateway)

    await waitFor(() => expect(screen.getByTestId('pk-journey')).toHaveAttribute('data-journey-status', 'STALE'))
    const journey = screen.getByTestId('pk-journey')
    expect(within(journey).getByRole('link', { name: 'Plan your journey again' })).toHaveAttribute('href', '/plan-journey')
    expect(screen.getByTestId('pk-asap-recommended')).toBeInTheDocument()
  })

  it('tells closed, closing soon and fully booked apart', async () => {
    const gateway = new ScriptedGateway()
    const noAsap = (reason: string) => ({ ...availabilityDto().asap, available: false, pickup_at: null, local_pickup_at: null, estimated_ready_in_minutes: null, recommended: false, reason })
    gateway.dto = availabilityDto({ asap: noAsap('CLOSES_TOO_SOON'), recommended: null })
    const view = page(gateway)
    await userEvent.click(await screen.findByRole('radio', { name: /As soon as possible/i }))
    expect(screen.getByTestId('pk-asap-unavailable')).toHaveAttribute('data-reason', 'CLOSES_TOO_SOON')
    expect(screen.getByTestId('pk-asap-unavailable')).toHaveTextContent('The restaurant closes before this order could be ready.')
    view.unmount()

    // Nothing at all: every slot is full.
    gateway.dto = availabilityDto({
      can_select: false, asap: noAsap('FULLY_BOOKED'), recommended: null, slots: [slot(30, { available: false, capacity_state: 'FULL', reason: 'SLOT_FULL' })],
      scheduled: { ...availabilityDto().scheduled, slot_count: 1, available_slot_count: 0 }, issues: [{ ...issue('NO_PICKUP_SLOTS', 'BLOCKING', { cause: 'FULLY_BOOKED' }), message: 'All pickup times are fully booked right now.' }],
    })
    page(gateway)
    const empty = await screen.findByTestId('pk-empty')
    expect(empty).toHaveAttribute('data-cause', 'FULLY_BOOKED')
    expect(empty).toHaveTextContent('All pickup times are fully booked right now.')
    expect(screen.getByTestId('pk-continue')).toBeDisabled()
  })

  it('says in the backend\'s words why nothing can be chosen: not accepting, pickup off, cart needs attention', async () => {
    const blocked = (code: string, message: string) => availabilityDto({ can_select: false, asap: { ...availabilityDto().asap, available: false, pickup_at: null, recommended: false }, slots: [], recommended: null, issues: [{ ...issue(code), message }] })
    const gateway = new ScriptedGateway()

    gateway.dto = blocked('RESTAURANT_NOT_ACCEPTING', 'This restaurant is not accepting orders right now.')
    let view = page(gateway)
    expect(await screen.findByTestId('pk-blocked')).toHaveAttribute('data-code', 'RESTAURANT_NOT_ACCEPTING')
    expect(screen.getByTestId('pk-blocked')).toHaveTextContent('This restaurant is not accepting orders right now.')
    expect(screen.queryByRole('radiogroup', { name: 'Pickup mode' })).not.toBeInTheDocument()
    view.unmount()

    gateway.dto = blocked('PICKUP_DISABLED', 'This restaurant does not offer pickup right now.')
    view = page(gateway)
    expect(await screen.findByTestId('pk-blocked')).toHaveTextContent('This restaurant does not offer pickup right now.')
    view.unmount()

    gateway.dto = blocked('CART_INVALID', 'Your cart needs your attention before a pickup time can be chosen.')
    page(gateway)
    const notice = await screen.findByTestId('pk-blocked')
    expect(notice).toHaveAttribute('data-code', 'CART_INVALID')
    expect(within(notice).getByRole('link', { name: 'Review cart' })).toHaveAttribute('href', '/cart')
    expect(screen.getByTestId('pk-continue')).toBeDisabled()
  })

  it('a selection that is no longer valid is explained and blocks Continue; a valid one goes to checkout after the backend confirmed it', async () => {
    const gateway = new ScriptedGateway()
    gateway.stored = selectionDto({ status: 'INVALID', valid: false, issues: [{ ...issue('SLOT_FULL'), message: 'That pickup time is no longer available. Please select another time.' }] })
    const view = page(gateway)
    const stale = await screen.findByTestId('pk-stale')
    expect(stale).toHaveAttribute('data-code', 'SLOT_FULL')
    expect(stale).toHaveTextContent('That pickup time is no longer available. Please select another time.')
    expect(screen.getByTestId('pk-continue')).toBeDisabled()
    view.unmount()

    gateway.stored = selectionDto()
    page(gateway)
    await waitFor(() => expect(screen.getByTestId('pk-continue')).toBeEnabled())
    const reads = gateway.log.filter((l) => l === 'selection').length
    await userEvent.click(screen.getByTestId('pk-continue'))
    expect(await screen.findByText('checkout page')).toBeInTheDocument()
    expect(gateway.log.filter((l) => l === 'selection').length).toBe(reads + 1)
  })

  it('a refused choice says why and shows the slot as full; a failed load can be retried', async () => {
    const gateway = new ScriptedGateway()
    page(gateway)
    await userEvent.click(await screen.findByRole('radio', { name: /Schedule for later/i }))
    gateway.fail.select = new PickupApiError('slot_full', 'That pickup time is no longer available. Please select another time.')
    gateway.onRefused = () => { gateway.dto = availabilityDto({ slots: [slot(30, { available: false, capacity_state: 'FULL', reason: 'SLOT_FULL' }), slot(45)] }) }

    await userEvent.click(slots()[0])

    expect(await screen.findByTestId('pk-notice')).toHaveTextContent('That pickup time is no longer available. Please select another time.')
    await waitFor(() => expect(slots()[0]).toBeDisabled())
    expect(screen.queryByTestId('pk-summary')).not.toBeInTheDocument()

    gateway.fail = { availability: new PickupApiError('offline', 'Cannot reach FoodOnTheGo right now. Pickup times need a connection to be confirmed.') }
    cleanup()
    page(gateway)
    expect(await screen.findByTestId('pk-error')).toHaveTextContent(/need a connection to be confirmed/)
    gateway.fail = {}; gateway.onRefused = null
    await userEvent.click(screen.getByRole('button', { name: /try again|retry/i }))
    expect(await screen.findByTestId('pk-asap-time')).toBeInTheDocument()
  })

  it('when the backend cannot be asked any more, the times of the earlier answer are not offered and Continue is off', async () => {
    const gateway = new ScriptedGateway()
    gateway.stored = selectionDto()
    page(gateway)
    expect(await screen.findByTestId('pk-summary')).toBeInTheDocument()
    expect(slots().length).toBeGreaterThan(0)
    expect(screen.getByTestId('pk-continue')).toBeEnabled()

    // The connection goes; the tab comes back to the front and the page asks again.
    gateway.fail = { availability: new PickupApiError('offline', 'Cannot reach FoodOnTheGo right now. Pickup times need a connection to be confirmed.') }
    document.dispatchEvent(new Event('visibilitychange'))
    expect(await screen.findByTestId('pk-error')).toHaveTextContent(/need a connection to be confirmed/)
    expect(screen.queryAllByTestId('pk-slot')).toHaveLength(0)
    expect(screen.queryByTestId('pk-asap-choose')).not.toBeInTheDocument()
    expect(screen.getByTestId('pk-continue')).toBeDisabled()

    gateway.fail = {}
    await userEvent.click(screen.getByRole('button', { name: /try again|retry/i }))
    await waitFor(() => expect(slots().length).toBeGreaterThan(0))
    expect(screen.getByTestId('pk-continue')).toBeEnabled()
  })
})

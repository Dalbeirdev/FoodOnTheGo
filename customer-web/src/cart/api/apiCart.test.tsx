/**
 * The cart on the backend (Module 27): the mapping of the cart API onto the page model (every amount is the backend's),
 * what the gateway sends (ids, quantities and text — never a price, a currency or an owner; the guest token in a header
 * only; a version and an idempotency key on every change), the error vocabulary, and the provider on that gateway:
 * awaited adds, the restaurant-conflict question, the sign-in claim and its two-restaurant choice. The network is stubbed
 * with payloads in the shape of the real API (OpenAPI: CartEnvelope, CartChangeResult, CartClaimResult).
 */
import { act, cleanup, render, renderHook, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { tokens } from '../../api/client'
import { AuthProvider, useAuth } from '../../auth/AuthContext'
import type { AuthRepository, AuthUser } from '../../auth/repository'
import { LocaleProvider } from '../../i18n/LocaleProvider'
import { JourneyProvider } from '../../journey/JourneyContext'
import CartPage from '../../pages/CartPage'
import { useCart, type AddItemInput, type AddResult } from '../CartContext'
import { cartItemCount, cartSubtotalMinor } from '../cartModel'
import { reviewFromServer } from '../cartValidation'
import { ServerCartProvider } from '../ServerCartProvider'
import { ApiCartGateway, CartError, cartToken, resetCartApiState, selectionsOf, toCart, toCartError, type CartDto, type CartIssueDto, type CartLineDto } from './apiCart'

const R1 = '0a000000-0000-4000-8000-0000000000a1'
const R2 = '0a000000-0000-4000-8000-0000000000b2'
const ITEM = '1b000000-0000-4000-8000-000000000001'
const LINE = '2c000000-0000-4000-8000-000000000001'
const G_SIZE = '3d000000-0000-4000-8000-000000000001'
const O_LARGE = '4e000000-0000-4000-8000-000000000001'
const G_ADD = '3d000000-0000-4000-8000-000000000002'
const O_CHEESE = '4e000000-0000-4000-8000-000000000002'
const TOKEN = `gc1.abc123.${'a'.repeat(64)}.${'b'.repeat(32)}`

const issue = (code: string, severity: CartIssueDto['severity'], lineId: string | null = null, details: Record<string, unknown> = {}): CartIssueDto => ({ code, severity, scope: lineId ? 'line' : 'cart', line_id: lineId, message: `${code} message`, details })
const lineDto = (patch: Partial<CartLineDto> = {}): CartLineDto => ({
  id: LINE, item: { id: ITEM, slug: 'classic-burger', name: 'Classic Burger', image: null, version: 3 }, name: 'Classic Burger', quantity: 2, min_quantity: 1, max_quantity: 20, special_instructions: 'No onions',
  variants: [{ group_id: G_SIZE, group_name: 'Size', kind: 'VARIANT', options: [{ id: O_LARGE, name: 'Large', price_adjustment_minor: 5000, available: true }] }],
  modifiers: [{ group_id: G_ADD, group_name: 'Add-ons', kind: 'MODIFIER', options: [{ id: O_CHEESE, name: 'Extra Cheese', price_adjustment_minor: 2000, available: true }] }],
  base_price_minor: 20000, unit_price_minor: 27000, line_subtotal_minor: 54000, currency: 'INR',
  snapshot: { unit_price_minor: 27000, base_price_minor: 20000, item_version: 3, taken_at: '2026-10-07T07:30:00+00:00' }, price_changed: false, priced_from: 'menu', included_in_subtotal: true,
  availability: { orderable: true, reason: null }, issues: [], ...patch,
})
const cartDto = (patch: Partial<CartDto> = {}): CartDto => ({
  id: '5f000000-0000-4000-8000-000000000001', version: 4, status: 'ACTIVE', owner: 'guest', currency: 'INR', restaurant: { id: R1, slug: 'burger-hub', name: 'Burger Hub', visible: true }, journey: null,
  items: [lineDto()], item_count: 2, line_count: 1, subtotal_minor: 54000,
  validation: { status: 'VALID', can_proceed: true, issues: [], menu_changed: false, catalog_version: 7, checked_at: '2026-10-07T07:31:00+00:00' },
  expires_at: '2026-10-14T07:30:00+00:00', created_at: '2026-10-07T07:30:00+00:00', updated_at: '2026-10-07T07:30:00+00:00', ...patch,
})
const input = (patch: Partial<AddItemInput> = {}): AddItemInput => ({
  menuItemId: ITEM, itemSlug: 'classic-burger', itemName: 'Classic Burger', image: '', fallback: 'CL', basePriceMinor: 20000, currency: 'INR',
  restaurant: { id: 'burger-hub', slug: 'burger-hub', name: 'Burger Hub', currency: 'INR', publicId: R1 },
  selectedVariants: [{ groupId: G_SIZE, groupName: 'Size', optionId: O_LARGE, optionName: 'Large', priceAdjustmentMinor: 5000 }],
  selectedModifiers: [{ groupId: G_ADD, groupName: 'Add-ons', optionId: O_CHEESE, optionName: 'Extra Cheese', priceAdjustmentMinor: 2000 }],
  specialInstructions: 'No onions', quantity: 2, unitPriceMinor: 99999, ...patch,
})

type Call = { method: string; path: string; url: string; body: Record<string, unknown> | undefined; headers: Record<string, string> }
type Reply = { status?: number; body: unknown } | 'network'
let calls: Call[] = []
let reply: (method: string, path: string, call: Call) => Reply = () => ({ body: { cart: null } })
const error = (status: number, code: string, message: string, details: Record<string, unknown> = {}): Reply => ({ status, body: { error: { code, message, details, request_id: 'r1' } } })
const last = (method: string, fragment: string) => [...calls].reverse().find((c) => c.method === method && c.path.includes(fragment))!

beforeEach(() => {
  calls = []; sessionStorage.clear(); localStorage.clear(); resetCartApiState(); tokens.set('customer', null)
  reply = () => ({ body: { cart: null } })
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const u = new URL(url)
    const call: Call = { method: init.method ?? 'GET', path: decodeURIComponent(u.pathname.replace('/api/v1', '')), url, body: init.body ? JSON.parse(init.body as string) : undefined, headers: (init.headers ?? {}) as Record<string, string> }
    calls.push(call)
    const r = reply(call.method, call.path, call)
    if (r === 'network') throw new TypeError('fetch failed')
    return new Response(JSON.stringify(r.body), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } })
  }))
})
afterEach(() => { cleanup(); vi.unstubAllGlobals(); vi.useRealTimers(); tokens.set('customer', null) })

describe('cart API mapping', () => {
  it('shows the backend\'s figures as they are and keeps what only the backend knows', () => {
    const cart = toCart(cartDto({ journey: {
      id: '9d3f2c61-5b0e-4b7a-9c57-0c6c1f2a4e10', status: 'ACTIVE', requires_refresh: false, origin: { label: 'Sector 62, Noida' }, destination: { label: 'Sector 18, Noida' }, restaurant_on_route: true,
      distance_from_route_meters: 420, detour: { duration_seconds: 240, distance_meters: 900 }, estimated_arrival_at: '2026-10-07T08:05:00+00:00', estimate_source: 'development', linked_at: '2026-10-07T07:30:00+00:00',
    } }))!
    expect(cart).toMatchObject({ id: '5f000000-0000-4000-8000-000000000001', restaurantSlug: 'burger-hub', restaurantName: 'Burger Hub', currency: 'INR' })
    expect(cart.items[0]).toMatchObject({ id: LINE, menuItemId: ITEM, itemSlug: 'classic-burger', itemName: 'Classic Burger', quantity: 2, unitPriceMinor: 27000, lineTotalMinor: 54000, basePriceMinor: 20000, specialInstructions: 'No onions', minimumQuantity: 1, maximumQuantity: 20 })
    expect(cart.items[0].selectedVariants).toEqual([{ groupId: G_SIZE, groupName: 'Size', optionId: O_LARGE, optionName: 'Large', priceAdjustmentMinor: 5000 }])
    expect(cart.items[0].selectedModifiers[0]).toMatchObject({ groupId: G_ADD, optionId: O_CHEESE, optionName: 'Extra Cheese', priceAdjustmentMinor: 2000 })
    expect(cart.server).toMatchObject({ version: 4, status: 'ACTIVE', owner: 'guest', restaurantPublicId: R1, restaurantVisible: true, subtotalMinor: 54000, itemCount: 2 })
    expect(cart.server?.journey).toMatchObject({ status: 'ACTIVE', originLabel: 'Sector 62, Noida', destinationLabel: 'Sector 18, Noida', restaurantOnRoute: true, detourSeconds: 240, estimateSource: 'development' })
    // No coordinates or geometry reach the page model.
    expect(JSON.stringify(cart.server?.journey)).not.toMatch(/lat|lng|polyline|geometry/)
    expect(toCart(null)).toBeNull()
  })

  it('takes the badge and the subtotal from the backend, not from adding lines up', () => {
    // A sold-out line is in the cart (and in the badge) but not in the subtotal — the backend decides that.
    const cart = toCart(cartDto({
      items: [lineDto(), lineDto({ id: 'x'.repeat(8), quantity: 3, line_subtotal_minor: 36000, included_in_subtotal: false, availability: { orderable: false, reason: 'ITEM_SOLD_OUT' }, issues: [issue('ITEM_SOLD_OUT', 'BLOCKING', 'x'.repeat(8))] })],
      item_count: 5, line_count: 2, subtotal_minor: 54000,
    }))
    expect(cartItemCount(cart)).toBe(5)
    expect(cartSubtotalMinor(cart)).toBe(54000)
    expect(cart?.items[1].server).toMatchObject({ includedInSubtotal: false, orderable: false })
  })

  it('turns the backend\'s issues into what the cart page shows', () => {
    const cart = toCart(cartDto({
      status: 'ACTIVE',
      items: [
        lineDto({ id: 'l1', issues: [issue('PRICE_CHANGED', 'REVIEW', 'l1', { previous_unit_price_minor: 27000, current_unit_price_minor: 29000 })], price_changed: true, unit_price_minor: 29000 }),
        lineDto({ id: 'l2', issues: [issue('ITEM_SOLD_OUT', 'BLOCKING', 'l2')] }), lineDto({ id: 'l3', issues: [issue('ITEM_REMOVED', 'BLOCKING', 'l3')] }),
        lineDto({ id: 'l4', issues: [issue('OPTION_UNAVAILABLE', 'BLOCKING', 'l4', { options: ['Extra Cheese'] })] }),
        lineDto({ id: 'l5', issues: [issue('REQUIRED_CUSTOMIZATION_MISSING', 'BLOCKING', 'l5', { group: 'Portion' })] }),
        lineDto({ id: 'l6', issues: [issue('QUANTITY_LIMIT', 'BLOCKING', 'l6', { min: 1, max: 3 })] }), lineDto({ id: 'l7' }),
      ],
      validation: { status: 'INVALID', can_proceed: false, menu_changed: true, catalog_version: 8, checked_at: 'x', issues: [issue('RESTAURANT_NOT_ACCEPTING', 'BLOCKING'), issue('MENU_CHANGED', 'INFO'), issue('JOURNEY_STALE', 'INFO')] },
    }))!
    const review = reviewFromServer(cart)
    expect(review.lineIssues).toEqual([
      { itemId: 'l1', kind: 'price_changed', oldUnitMinor: 27000, newUnitMinor: 29000 }, { itemId: 'l2', kind: 'unavailable' }, { itemId: 'l3', kind: 'unavailable' },
      { itemId: 'l4', kind: 'modifier_unavailable', optionNames: ['Extra Cheese'] }, { itemId: 'l5', kind: 'needs_choice', groupName: 'Portion' }, { itemId: 'l6', kind: 'quantity', min: 1, max: 3 },
    ])
    expect(review).toMatchObject({ restaurantIssue: 'not_accepting', blocking: true, ok: false, expired: false, journeyStale: true, currencyMismatch: false })

    // The verdict is the backend's: a closed restaurant alone does not block, an expired cart does.
    const closed = reviewFromServer(toCart(cartDto({ validation: { status: 'VALID', can_proceed: true, menu_changed: false, catalog_version: 8, checked_at: 'x', issues: [issue('RESTAURANT_CLOSED_NOW', 'INFO')] } }))!)
    expect(closed).toMatchObject({ restaurantIssue: 'closed', blocking: false, ok: true })
    const expired = reviewFromServer(toCart(cartDto({ status: 'EXPIRED', validation: { status: 'INVALID', can_proceed: false, menu_changed: false, catalog_version: 8, checked_at: 'x', issues: [issue('CART_EXPIRED', 'BLOCKING')] } }))!)
    expect(expired).toMatchObject({ expired: true, blocking: true })
    expect(reviewFromServer(toCart(cartDto({ validation: { status: 'INVALID', can_proceed: false, menu_changed: false, catalog_version: null, checked_at: 'x', issues: [issue('RESTAURANT_UNAVAILABLE', 'BLOCKING'), issue('CURRENCY_MISMATCH', 'BLOCKING'), issue('MENU_UNAVAILABLE', 'BLOCKING')] } }))!))
      .toMatchObject({ restaurantIssue: 'inactive', currencyMismatch: true, menuUnavailable: true })
  })

  it('maps the backend\'s error codes', () => {
    expect(selectionsOf(input().selectedVariants.concat(input().selectedModifiers))).toEqual([{ groupId: G_SIZE, optionIds: [O_LARGE] }, { groupId: G_ADD, optionIds: [O_CHEESE] }])
    expect(toCartError(new CartError('expired', 'x')).code).toBe('expired')
    expect(toCartError(new Error('boom')).code).toBe('error')
  })
})

describe('ApiCartGateway', () => {
  const gateway = () => new ApiCartGateway()

  it('keeps the guest token it is handed and sends it in a header only', async () => {
    reply = (_m, _p, call) => ({ body: call.headers['X-Cart-Token'] ? { cart: null } : { cart: null, guest_token: TOKEN } })
    expect(await gateway().load()).toBeNull()
    expect(cartToken.get()).toBe(TOKEN)
    expect(calls[0].headers['X-Cart-Token']).toBeUndefined()

    reply = () => ({ body: { cart: cartDto() } })
    const cart = await gateway().load()
    expect(cart?.items).toHaveLength(1)
    expect(last('GET', '/cart').headers['X-Cart-Token']).toBe(TOKEN)
    for (const call of calls) expect(call.url).not.toContain('gc1')
    // The token is not a session: nothing is sent as a bearer for a guest.
    expect(last('GET', '/cart').headers.Authorization).toBeUndefined()
  })

  it('sends ids, a quantity and text — never a price, a currency or an owner', async () => {
    cartToken.set(TOKEN)
    reply = () => ({ status: 201, body: { cart: cartDto(), line: { id: LINE, merged: false } } })
    const request = { itemId: ITEM, selections: [{ groupId: G_SIZE, optionIds: [O_LARGE] }, { groupId: G_ADD, optionIds: [O_CHEESE] }, { groupId: 'empty-group', optionIds: [] }], quantity: 2, specialInstructions: ' No onions ' }
    const answer = await gateway().add(R1, request, 3, { id: 'journey-1', headers: { 'X-Journey-Token': 'j'.repeat(64) } })
    expect(answer).toMatchObject({ lineId: LINE, merged: false })

    const call = last('POST', '/cart/items')
    expect(call.body).toEqual({
      restaurant_id: R1, item_id: ITEM, selections: [{ group_id: G_SIZE, option_ids: [O_LARGE] }, { group_id: G_ADD, option_ids: [O_CHEESE] }], quantity: 2, special_instructions: ' No onions ', journey_id: 'journey-1', cart_version: 3,
    })
    expect(JSON.stringify(call.body)).not.toMatch(/price|subtotal|currency|customer|status|total/)
    expect(call.headers).toMatchObject({ 'X-Cart-Token': TOKEN, 'X-Journey-Token': 'j'.repeat(64) })
    expect(call.headers['Idempotency-Key']).toMatch(/^add-[0-9a-f-]{20,}$/)

    await gateway().update(LINE, { quantity: 3 }, 4)
    expect(last('PATCH', `/cart/items/${LINE}`).body).toEqual({ quantity: 3, cart_version: 4 })
    await gateway().update(LINE, { selections: [{ groupId: G_SIZE, optionIds: [O_LARGE] }], specialInstructions: '' }, null)
    expect(last('PATCH', `/cart/items/${LINE}`).body).toEqual({ selections: [{ group_id: G_SIZE, option_ids: [O_LARGE] }], special_instructions: null })
    await gateway().remove(LINE, 5)
    expect(last('DELETE', `/cart/items/${LINE}`).url).toContain('cart_version=5')
    await gateway().refresh(6)
    expect(last('POST', '/cart/refresh').body).toEqual({ cart_version: 6 })
    await gateway().switchRestaurant(R2, request, 6, null)
    expect(last('POST', '/cart/switch-restaurant').body).toEqual({ confirm: true, restaurant_id: R2, item: { item_id: ITEM, selections: [{ group_id: G_SIZE, option_ids: [O_LARGE] }, { group_id: G_ADD, option_ids: [O_CHEESE] }], quantity: 2, special_instructions: ' No onions ' }, cart_version: 6 })
    await gateway().linkJourney({ id: 'journey-2', headers: {} }, 7)
    expect(last('PUT', '/cart/journey').body).toEqual({ journey_id: 'journey-2', cart_version: 7 })
    await gateway().clear()
    expect(last('DELETE', '/cart').path).toBe('/cart')
    // Every change that could be repeated by accident carries its own key.
    const keys = calls.filter((c) => c.method !== 'GET' && c.headers['Idempotency-Key']).map((c) => c.headers['Idempotency-Key'])
    expect(new Set(keys).size).toBe(keys.length)
  })

  it('a signed-in customer is the customer: the guest token is not sent, except to claim that guest cart', async () => {
    cartToken.set(TOKEN); tokens.set('customer', 'customer-session')
    reply = () => ({ body: { cart: cartDto({ owner: 'customer' }) } })
    await gateway().load()
    expect(last('GET', '/cart').headers).toMatchObject({ Authorization: 'Bearer customer-session' })
    expect(last('GET', '/cart').headers['X-Cart-Token']).toBeUndefined()

    reply = () => ({ body: { cart: cartDto({ owner: 'customer' }), claim: { outcome: 'merged', lines_moved: 1, lines_combined: 1, adjusted: [{ line_id: LINE, name: 'Masala Fries', requested_quantity: 23, quantity: 20, max_quantity: 20 }] } } })
    const claimed = await gateway().claim()
    expect(last('POST', '/cart/claim').headers).toMatchObject({ 'X-Cart-Token': TOKEN, Authorization: 'Bearer customer-session' })
    expect(claimed).toMatchObject({ outcome: 'merged', adjusted: [{ name: 'Masala Fries', quantity: 20 }] })
    // The guest token has done its work.
    expect(cartToken.get()).toBeNull()
    expect(gateway().hasGuestToken()).toBe(false)

    // SIGN-OUT: the app says "guest" while the old session token is still stored for a moment — the cart is read as a
    // guest's, without that token.
    reply = () => ({ body: { cart: null, guest_token: TOKEN } })
    const signingOut = gateway(); signingOut.useSession(false)
    expect(tokens.get('customer')).toBe('customer-session')
    await signingOut.load()
    expect(last('GET', '/cart').headers.Authorization).toBeUndefined()
    await signingOut.add(R1, { itemId: ITEM, selections: [], quantity: 1, specialInstructions: '' }, null, null)
    expect(last('POST', '/cart/items').headers.Authorization).toBeUndefined()
    expect(last('POST', '/cart/items').headers['X-Cart-Token']).toBe(TOKEN)
  })

  it('a conflict at sign-in keeps the token until the customer has chosen', async () => {
    cartToken.set(TOKEN); tokens.set('customer', 'customer-session')
    const summary = (name: string, count: number) => ({ restaurant: { id: R1, slug: 'x', name }, item_count: count, line_count: 1, subtotal_minor: 1000 * count, currency: 'INR' })
    reply = () => error(409, 'cart_merge_conflict', 'Choose which one to keep.', { guest: summary('Burger Hub', 3), account: summary('Pizza Point', 2), resolutions: ['keep_guest', 'keep_account'] })
    await expect(gateway().claim()).rejects.toMatchObject({ code: 'merge_conflict', details: { guest: { restaurant: { name: 'Burger Hub' } }, account: { item_count: 2 } } })
    expect(cartToken.get()).toBe(TOKEN)

    reply = () => ({ body: { cart: cartDto({ owner: 'customer' }), claim: { outcome: 'kept_guest' } } })
    expect((await gateway().claim('keep_guest')).outcome).toBe('kept_guest')
    expect(last('POST', '/cart/claim').body).toEqual({ resolution: 'keep_guest' })
    expect(cartToken.get()).toBeNull()
  })

  it('speaks the backend\'s error vocabulary', async () => {
    cartToken.set(TOKEN)
    const cases: Array<[Reply, string]> = [
      [error(409, 'cart_restaurant_conflict', 'Another restaurant.', { current: { restaurant: { name: 'Burger Hub' }, item_count: 2 } }), 'restaurant_conflict'], [error(409, 'cart_version_conflict', 'Changed elsewhere.', { current_version: 9 }), 'version_conflict'],
      [error(410, 'cart_expired', 'Expired.'), 'expired'], [error(409, 'item_unavailable', 'Sold out.', { reason: 'ITEM_SOLD_OUT' }), 'item_unavailable'], [error(404, 'item_not_found', 'Not on the menu.'), 'item_unavailable'],
      [error(409, 'option_unavailable', 'Option gone.'), 'option_unavailable'], [error(422, 'invalid_customization', 'Choose a size.', { issues: [{ code: 'required' }] }), 'invalid_customization'], [error(422, 'quantity_limit', 'Too many.', { max: 20 }), 'quantity_limit'],
      [error(422, 'cart_line_limit', 'Too many lines.'), 'line_limit'], [error(409, 'currency_mismatch', 'Currencies differ.'), 'currency_mismatch'], [error(404, 'restaurant_not_found', 'Not available.'), 'restaurant_unavailable'],
      [error(404, 'cart_line_not_found', 'Not in your cart.'), 'not_found'], [error(429, 'rate_limited', 'Slow down.'), 'rate_limited'], [error(500, 'internal_error', 'secret stack trace'), 'error'],
    ]
    for (const [answer, code] of cases) {
      reply = () => answer
      const caught = await gateway().add(R1, { itemId: ITEM, selections: [], quantity: 1, specialInstructions: '' }, null, null).catch((e: CartError) => e)
      expect(caught).toBeInstanceOf(CartError)
      expect((caught as CartError).code).toBe(code)
    }
    reply = () => error(500, 'internal_error', 'secret stack trace')
    await expect(gateway().refresh(null)).rejects.toMatchObject({ message: expect.not.stringContaining('secret') })
  })

  it('after a lost connection asks once more with the SAME idempotency key — the change is applied once', async () => {
    vi.useFakeTimers()
    cartToken.set(TOKEN)
    let attempt = 0
    reply = () => (++attempt === 1 ? 'network' : { status: 201, body: { cart: cartDto(), line: { id: LINE, merged: false } } })
    const pending = gateway().add(R1, { itemId: ITEM, selections: [], quantity: 1, specialInstructions: '' }, null, null)
    await vi.advanceTimersByTimeAsync(800)
    expect((await pending).lineId).toBe(LINE)
    const posts = calls.filter((c) => c.method === 'POST')
    expect(posts).toHaveLength(2)
    expect(posts[0].headers['Idempotency-Key']).toBe(posts[1].headers['Idempotency-Key'])

    // Still unreachable: the customer is told nothing was changed, and nothing is retried forever.
    reply = () => 'network'
    const failing = gateway().add(R1, { itemId: ITEM, selections: [], quantity: 1, specialInstructions: '' }, null, null).catch((e: CartError) => e)
    await vi.advanceTimersByTimeAsync(800)
    expect(await failing).toMatchObject({ code: 'offline' })
    expect(calls.filter((c) => c.method === 'POST')).toHaveLength(4)
  })
})

/* ------------------------------------------------------------------ the provider on a scripted gateway */
const USER: AuthUser = { id: 'c1', name: 'Rahul Sharma', phone: '+919876543210', email: null, memberSince: '2026-01-01' }
function authRepository(state: { user: AuthUser | null }): AuthRepository {
  return {
    requestOtp: async (phone: string) => ({ phone, expiresAt: Date.now() + 60000, resendAfter: 30, attemptsAllowed: 5 }), resendOtp: async (phone: string) => ({ phone, expiresAt: Date.now() + 60000, resendAfter: 30, attemptsAllowed: 5 }),
    verifyOtp: async () => { state.user = USER; tokens.set('customer', 'customer-session'); return { status: 'authenticated', user: USER } },
    completeSetup: async () => USER, getCurrentUser: async () => state.user, refreshSession: async () => state.user,
    logout: async () => { state.user = null; tokens.set('customer', null) }, updateProfile: async () => USER,
  } as unknown as AuthRepository
}
class ScriptedGateway {
  cart: CartDto | null = null
  guestToken = false
  log: string[] = []
  fail: Partial<Record<string, CartError>> = {}
  private answer(lineId: string | null = null, merged = false) { return { cart: toCart(this.cart), lineId, merged, replaced: null, priceChangesAccepted: 0 } }
  private step(name: string) { this.log.push(name); const e = this.fail[name.split(' ')[0]]; if (e) throw e }
  async load() { this.step('load'); return toCart(this.cart) }
  async add(restaurant: string, item: { itemId: string; quantity: number }, version: number | null) {
    this.step(`add ${restaurant} v${version}`)
    this.cart = cartDto({ version: (this.cart?.version ?? 0) + 1, restaurant: { id: restaurant, slug: 'burger-hub', name: 'Burger Hub', visible: true }, items: [lineDto({ quantity: item.quantity, line_subtotal_minor: 27000 * item.quantity })], item_count: item.quantity, subtotal_minor: 27000 * item.quantity })
    return this.answer(LINE)
  }
  async update(lineId: string, change: { quantity?: number }, version: number | null) {
    this.step(`update ${lineId} q${change.quantity} v${version}`)
    const q = change.quantity ?? 2
    this.cart = cartDto({ version: (this.cart?.version ?? 0) + 1, items: [lineDto({ quantity: q, line_subtotal_minor: 27000 * q })], item_count: q, subtotal_minor: 27000 * q })
    return this.answer(lineId)
  }
  async remove(lineId: string, version: number | null) { this.step(`remove ${lineId} v${version}`); this.cart = cartDto({ version: (this.cart?.version ?? 0) + 1, items: [], item_count: 0, line_count: 0, subtotal_minor: 0 }); return this.answer() }
  async clear() { this.step('clear'); this.cart = null }
  async refresh(version: number | null) { this.step(`refresh v${version}`); return this.answer() }
  async switchRestaurant(restaurant: string, _item: unknown, version: number | null) {
    this.step(`switch ${restaurant} v${version}`)
    this.cart = cartDto({ version: 1, restaurant: { id: restaurant, slug: 'pizza-point', name: 'Pizza Point', visible: true }, items: [lineDto({ quantity: 1 })], item_count: 1 })
    return this.answer(LINE)
  }
  async linkJourney() { this.step('link'); return this.answer() }
  async claim(resolution?: string) {
    this.step(`claim ${resolution ?? ''}`.trim()); this.guestToken = false
    return { cart: toCart(this.cart), outcome: resolution === 'keep_guest' ? 'kept_guest' : 'claimed', adjusted: [] }
  }
  hasGuestToken() { return this.guestToken }
  session: boolean | null = null
  useSession(customer: boolean | null) { this.session = customer }
}

function setup(gateway: ScriptedGateway, auth = { user: null as AuthUser | null }) {
  const wrapper = ({ children }: { children: ReactNode }) => (
    <MemoryRouter><LocaleProvider locale="en-IN"><AuthProvider repository={authRepository(auth)}><JourneyProvider><ServerCartProvider gateway={gateway as unknown as ApiCartGateway}>{children}</ServerCartProvider></JourneyProvider></AuthProvider></LocaleProvider></MemoryRouter>
  )
  return renderHook(() => ({ cart: useCart(), auth: useAuth() }), { wrapper })
}

describe('ServerCartProvider', () => {
  it('reads the cart once the session is known and reports the backend\'s figures', async () => {
    const gateway = new ScriptedGateway(); gateway.cart = cartDto()
    const { result } = setup(gateway)
    expect(result.current.cart.hydrated).toBe(false)
    await waitFor(() => expect(result.current.cart.hydrated).toBe(true))
    expect(result.current.cart).toMatchObject({ mode: 'api', count: 2, subtotalMinor: 54000, currency: 'INR', status: 'active', loadFailed: false })
    expect(result.current.cart.cart?.items[0].id).toBe(LINE)
    expect(result.current.cart.lines[0]).toMatchObject({ key: LINE, qty: 2, unitPrice: 270, detail: 'Large, Extra Cheese, "No onions"' })
  })

  it('an add counts once the backend has answered, with the version the page last saw', async () => {
    const gateway = new ScriptedGateway()
    const { result } = setup(gateway)
    await waitFor(() => expect(result.current.cart.hydrated).toBe(true))

    let added: AddResult | undefined
    await act(async () => { added = await result.current.cart.addItem(input()) })
    expect(added).toMatchObject({ ok: true, merged: false })
    expect(gateway.log).toContain(`add ${R1} vnull`)
    expect(result.current.cart).toMatchObject({ count: 2, subtotalMinor: 54000 })
    expect(result.current.cart.lastAdded).toMatchObject({ menuItemId: ITEM, merged: false })
    // The page's own preview price (99999) is nowhere: the line shows the backend's 27000.
    expect(result.current.cart.cart?.items[0].unitPriceMinor).toBe(27000)

    await act(async () => { await result.current.cart.addItem(input({ quantity: 1 })) })
    expect(gateway.log).toContain(`add ${R1} v1`)
  })

  it('asks before replacing a cart of another restaurant and then calls the explicit switch', async () => {
    const gateway = new ScriptedGateway(); gateway.cart = cartDto()
    const { result } = setup(gateway)
    await waitFor(() => expect(result.current.cart.hydrated).toBe(true))

    let refused: AddResult | undefined
    await act(async () => { refused = await result.current.cart.addItem(input({ restaurant: { id: 'pizza-point', slug: 'pizza-point', name: 'Pizza Point', currency: 'INR', publicId: R2 } })) })
    expect(refused).toEqual({ ok: false, reason: 'restaurant_conflict' })
    expect(result.current.cart.conflict?.current.restaurantName).toBe('Burger Hub')
    expect(gateway.log.some((l) => l.startsWith('add') || l.startsWith('switch'))).toBe(false)
    expect(screen.getByTestId('cart-conflict')).toHaveTextContent('Your cart contains items from Burger Hub')

    // Cancel: nothing happens.
    act(() => result.current.cart.cancelReplace())
    expect(result.current.cart.cart?.restaurantName).toBe('Burger Hub')
    expect(gateway.log.some((l) => l.startsWith('switch'))).toBe(false)

    await act(async () => { await result.current.cart.addItem(input({ restaurant: { id: 'pizza-point', slug: 'pizza-point', name: 'Pizza Point', currency: 'INR', publicId: R2 } })) })
    await act(async () => { result.current.cart.confirmReplace() })
    await waitFor(() => expect(result.current.cart.cart?.restaurantName).toBe('Pizza Point'))
    expect(gateway.log).toContain(`switch ${R2} v4`)
    expect(result.current.cart.conflict).toBeNull()
  })

  it('shows a changed quantity at once and keeps what the backend answered', async () => {
    const gateway = new ScriptedGateway(); gateway.cart = cartDto()
    const { result } = setup(gateway)
    await waitFor(() => expect(result.current.cart.hydrated).toBe(true))

    act(() => { result.current.cart.updateQuantity(LINE, 3); result.current.cart.updateQuantity(LINE, 4) })
    expect(result.current.cart.cart?.items[0].quantity).toBe(4)
    await waitFor(() => expect(result.current.cart.status).toBe('active'))
    // Two changes, one after the other, each from the version the previous answer brought.
    expect(gateway.log.filter((l) => l.startsWith('update'))).toEqual([`update ${LINE} q3 v4`, `update ${LINE} q4 v5`])
    expect(result.current.cart).toMatchObject({ count: 4, subtotalMinor: 108000 })

    act(() => result.current.cart.updateQuantity(LINE, 0))
    await waitFor(() => expect(gateway.log).toContain(`remove ${LINE} v6`))
    await waitFor(() => expect(result.current.cart.count).toBe(0))
  })

  it('says what the backend refused and shows the backend\'s cart again', async () => {
    const gateway = new ScriptedGateway(); gateway.cart = cartDto()
    const { result } = setup(gateway)
    await waitFor(() => expect(result.current.cart.hydrated).toBe(true))

    gateway.fail.update = new CartError('version_conflict', 'Your cart changed somewhere else. Reload it and try again.')
    act(() => result.current.cart.updateQuantity(LINE, 9))
    await waitFor(() => expect(result.current.cart.lastError?.code).toBe('version_conflict'))
    await waitFor(() => expect(result.current.cart.cart?.items[0].quantity).toBe(2))
    act(() => result.current.cart.dismissError())
    expect(result.current.cart.lastError).toBeNull()

    gateway.fail = { add: new CartError('item_unavailable', 'This item is not available right now.') }
    let res: AddResult | undefined
    await act(async () => { res = await result.current.cart.addItem(input()) })
    expect(res).toEqual({ ok: false, reason: 'unavailable', message: 'This item is not available right now.' })

    gateway.fail = { add: new CartError('offline', 'Cannot reach FoodOnTheGo right now. Your cart was not changed.') }
    await act(async () => { res = await result.current.cart.addItem(input()) })
    expect(res).toMatchObject({ ok: false, reason: 'offline' })
    expect(result.current.cart.count).toBe(2)
  })

  it('a cart that cannot be read is unknown, not empty', async () => {
    const gateway = new ScriptedGateway(); gateway.fail.load = new CartError('offline', 'offline')
    const { result } = setup(gateway)
    await waitFor(() => expect(result.current.cart.hydrated).toBe(true))
    expect(result.current.cart).toMatchObject({ loadFailed: true, status: 'error', cart: null })

    gateway.fail = {}; gateway.cart = cartDto()
    await act(async () => { await result.current.cart.reload() })
    expect(result.current.cart).toMatchObject({ loadFailed: false, count: 2 })
  })

  it('claims the guest cart after sign-in and reads a guest cart again after sign-out', async () => {
    const gateway = new ScriptedGateway(); gateway.cart = cartDto(); gateway.guestToken = true
    const auth = { user: null as AuthUser | null }
    const { result } = setup(gateway, auth)
    await waitFor(() => expect(result.current.cart.hydrated).toBe(true))
    expect(gateway.log).toEqual(['load'])

    await act(async () => { await result.current.auth.requestOtp('+919876543210') })
    await act(async () => { await result.current.auth.verifyOtp('123456') })
    await waitFor(() => expect(gateway.log).toContain('claim'))
    expect(result.current.cart.count).toBe(2)

    expect(gateway.session).toBe(true)
    gateway.cart = null
    await act(async () => { await result.current.auth.logout() })
    expect(gateway.session).toBe(false)
    await waitFor(() => expect(gateway.log.filter((l) => l === 'load')).toHaveLength(2))
    await waitFor(() => expect(result.current.cart.cart).toBeNull())
  })

  it('never merges two restaurants: the customer chooses which cart to keep', async () => {
    const gateway = new ScriptedGateway(); gateway.cart = cartDto({ owner: 'customer', restaurant: { id: R2, slug: 'pizza-point', name: 'Pizza Point', visible: true } }); gateway.guestToken = true
    const summary = (name: string, count: number, total: number) => ({ restaurant: { id: R1, slug: 'x', name }, item_count: count, line_count: 1, subtotal_minor: total, currency: 'INR' })
    gateway.fail.claim = new CartError('merge_conflict', 'Choose.', { guest: summary('Burger Hub', 3, 60000), account: summary('Pizza Point', 2, 30000) })
    const { result } = setup(gateway, { user: USER })
    await waitFor(() => expect(result.current.cart.hydrated).toBe(true))

    const dialog = await screen.findByTestId('cart-merge')
    expect(dialog).toHaveTextContent('You have two carts')
    expect(screen.getByTestId('cart-merge-guest')).toHaveTextContent('Keep Burger Hub · 3 item(s) · ₹600.00')
    expect(screen.getByTestId('cart-merge-account')).toHaveTextContent('Keep Pizza Point · 2 item(s) · ₹300.00')
    // Meanwhile the account's cart is shown, untouched.
    expect(result.current.cart.cart?.restaurantName).toBe('Pizza Point')

    gateway.fail = {}; gateway.cart = cartDto({ owner: 'customer' })
    await userEvent.click(screen.getByTestId('cart-merge-guest'))
    await waitFor(() => expect(gateway.log).toContain('claim keep_guest'))
    await waitFor(() => expect(screen.queryByTestId('cart-merge')).toBeNull())
    expect(result.current.cart.cart?.restaurantName).toBe('Burger Hub')
  })
})

describe('Cart page on the backend cart', () => {
  const page = (gateway: ScriptedGateway) => render(
    <MemoryRouter initialEntries={['/cart']}><LocaleProvider locale="en-IN"><AuthProvider repository={authRepository({ user: null })}><JourneyProvider><ServerCartProvider gateway={gateway as unknown as ApiCartGateway}><CartPage /></ServerCartProvider></JourneyProvider></AuthProvider></LocaleProvider></MemoryRouter>,
  )

  it('shows the backend\'s issues line by line and blocks on the backend\'s verdict', async () => {
    const gateway = new ScriptedGateway()
    gateway.cart = cartDto({
      items: [
        lineDto({ id: 'l1', name: 'Paneer Roll', price_changed: true, unit_price_minor: 22000, line_subtotal_minor: 44000, issues: [issue('PRICE_CHANGED', 'REVIEW', 'l1', { previous_unit_price_minor: 20000, current_unit_price_minor: 22000 })] }),
        lineDto({ id: 'l2', name: 'Masala Fries', included_in_subtotal: false, availability: { orderable: false, reason: 'ITEM_SOLD_OUT' }, issues: [issue('ITEM_SOLD_OUT', 'BLOCKING', 'l2')] }),
      ],
      item_count: 4, line_count: 2, subtotal_minor: 44000,
      validation: { status: 'INVALID', can_proceed: false, menu_changed: true, catalog_version: 8, checked_at: 'x', issues: [issue('RESTAURANT_NOT_ACCEPTING', 'BLOCKING'), issue('MENU_CHANGED', 'INFO')] },
    })
    page(gateway)

    const lines = await screen.findAllByTestId('cart-line')
    expect(lines.map((l) => l.getAttribute('data-issue'))).toEqual(['price_changed', 'unavailable'])
    expect(lines[0]).toHaveTextContent('Price updated from ₹200.00 to ₹220.00 each.')
    expect(lines[1]).toHaveTextContent('This item is no longer available.')
    expect(screen.getByText(/open but not accepting orders/)).toBeInTheDocument()
    // The subtotal is the backend's: the sold-out line is listed but not counted.
    expect(screen.getByText('Items subtotal · 4').nextElementSibling).toHaveTextContent('₹440.00')
    expect(screen.getByTestId('cart-proceed')).toBeDisabled()
    expect(screen.queryByText(/development cart/)).toBeNull()
    // A line the backend will not change cannot be stepped up or down — it can be removed.
    expect(lines[1].querySelectorAll('.cart-qty button:disabled')).toHaveLength(2)

    await userEvent.click(screen.getByRole('button', { name: 'Accept updated price' }))
    await waitFor(() => expect(gateway.log).toContain('refresh v4'))
  })

  it('tells an expired cart, an unreadable cart and an ended journey apart from an empty cart', async () => {
    const expired = new ScriptedGateway()
    expired.cart = cartDto({
      status: 'EXPIRED', validation: { status: 'INVALID', can_proceed: false, menu_changed: false, catalog_version: 8, checked_at: 'x', issues: [issue('CART_EXPIRED', 'BLOCKING'), issue('JOURNEY_STALE', 'INFO')] },
      journey: { id: 'j1', status: 'STALE', requires_refresh: true, origin: { label: 'Sector 62, Noida' }, destination: { label: 'Sector 18, Noida' }, restaurant_on_route: true, distance_from_route_meters: 400, detour: { duration_seconds: 240, distance_meters: 900 }, estimated_arrival_at: '2026-10-07T08:05:00+00:00', estimate_source: 'development', linked_at: null },
    })
    const view = page(expired)
    expect(await screen.findByTestId('cart-expired')).toHaveTextContent('This cart has expired.')
    expect(screen.getByTestId('cart-journey')).toHaveAttribute('data-journey-status', 'STALE')
    expect(screen.getByTestId('cart-journey')).toHaveTextContent('Sector 62, Noida → Sector 18, Noida')
    expect(screen.getByTestId('cart-journey')).toHaveTextContent('This journey has ended.')
    expect(screen.getByTestId('cart-journey')).not.toHaveTextContent('detour · arrive')
    expect(screen.getByTestId('cart-proceed')).toBeDisabled()
    await userEvent.click(screen.getByRole('button', { name: 'Start a new cart' }))
    await waitFor(() => expect(expired.log).toContain('clear'))
    view.unmount()

    const unreachable = new ScriptedGateway(); unreachable.fail.load = new CartError('offline', 'offline')
    page(unreachable)
    expect(await screen.findByTestId('cart-load-failed')).toHaveTextContent('We could not load your cart.')
    expect(screen.queryByText('Your cart is empty.')).toBeNull()
    unreachable.fail = {}; unreachable.cart = cartDto()
    await userEvent.click(screen.getByRole('button', { name: 'Try again' }))
    expect(await screen.findByText('Classic Burger')).toBeInTheDocument()
  })
})

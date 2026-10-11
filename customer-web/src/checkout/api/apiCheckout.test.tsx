/**
 * Checkout on the backend (Module 29): the mapping of the checkout API onto what the pages read (every amount is the
 * backend's), what the gateway sends (a promotion code, the documents shown, a payment-method type, a note and the
 * fingerprint of the price on the screen — never an amount, a currency, a customer or a credential), the error
 * vocabulary, the provider on that gateway (open, blocked, promotion, confirm, stale, expired, zero total) and the
 * Checkout page showing the backend's answer. The network is stubbed with payloads in the shape of the real API
 * (OpenAPI: CheckoutEnvelope).
 */
import { act, cleanup, render, renderHook, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { tokens } from '../../api/client'
import { AuthProvider } from '../../auth/AuthContext'
import { resetCartApiState, toCart, type CartDto } from '../../cart/api/apiCart'
import { CartContext, type CartApi } from '../../cart/cartApi'
import { LocaleProvider } from '../../i18n/LocaleProvider'
import { JourneyProvider } from '../../journey/JourneyContext'
import CheckoutPage from '../../pages/CheckoutPage'
import { PickupCtx, type PickupApi } from '../../pickup/pickupApi'
import { ProfileProvider } from '../../profile/ProfileContext'
import { setMockRestaurantLatency } from '../../repositories/mock/restaurants'
import { useCheckout } from '../checkoutApi'
import { checkoutMode } from '../checkoutMode'
import { ServerCheckoutProvider } from '../ServerCheckoutProvider'
import { ApiCheckoutGateway, CheckoutApiError, promoStatusOf, remainingMs, toCheckout, toCheckoutError, type CheckoutDto } from './apiCheckout'

const R1 = '0a000000-0000-4000-8000-0000000000a1'
const CART = '5f000000-0000-4000-8000-000000000001'
const CK = '9c000000-0000-4000-8000-000000000001'
const TZ = 'Asia/Kolkata'
const NOON = Date.parse('2026-10-07T06:30:00Z')
const at = (minutes: number) => new Date(NOON + minutes * 60000).toISOString().replace('.000Z', '+00:00')
const inr = (amount: number) => ({ amount, currency: 'INR' })
const FP = 'a'.repeat(64)
const LEGAL = [
  { type: 'TERMS', version: 'draft-2026-09', effective_date: null, status: 'DRAFT_PENDING_APPROVAL', slug: 'terms' },
  { type: 'PRIVACY', version: 'draft-2026-09', effective_date: null, status: 'DRAFT_PENDING_APPROVAL', slug: 'privacy' },
  { type: 'REFUND', version: 'draft-2026-09', effective_date: null, status: 'DRAFT_PENDING_APPROVAL', slug: 'refund-policy' },
]
/** 2 Large burgers of ₹250: ₹500, nothing configured, nothing applied. */
const checkoutDto = (patch: Partial<CheckoutDto> = {}, pricing: Partial<CheckoutDto['pricing']> = {}): CheckoutDto => ({
  id: CK, status: 'DRAFT', status_reason: null, version: 1, expires_at: at(15), server_time: at(0), created_at: at(0), cart_version: 4,
  restaurant: { id: R1, slug: 'burger-hub', name: 'Burger Hub', timezone: TZ },
  pickup: { mode: 'SCHEDULED', method: 'COUNTER', pickup_at: at(75), local_pickup_at: '2026-10-07T13:15:00+05:30', local_date: '2026-10-07', slot_starts_at: at(75), slot_ends_at: at(90), timezone: TZ, estimated_prep_minutes: 20, traveler_arrival_at: null, hold: { held: false, expires_at: null } },
  items: [{
    id: '2c000000-0000-4000-8000-000000000001', item: { id: '1b000000-0000-4000-8000-000000000001', slug: 'classic-burger', name: 'Classic Burger', image: null, version: 3 }, name: 'Classic Burger', quantity: 2, special_instructions: 'No onions',
    variants: [{ group_id: 'g1', group_name: 'Size', kind: 'VARIANT', options: [{ id: 'o1', name: 'Large', price_adjustment_minor: 5000, available: true }] }], modifiers: [], unit_price_minor: 25000, line_subtotal_minor: 50000, currency: 'INR',
  }],
  item_count: 2,
  pricing: { currency: 'INR', items_subtotal: inr(50000), discounts: [], discount_total: inr(0), taxes: [], tax_total: inr(0), tax_included_total: inr(0), fees: [], fee_total: inr(0), total: inr(50000), payment_required: true, fingerprint: FP, configuration: { tax: 'NOT_CONFIGURED', fees: 'NOT_CONFIGURED' }, ...pricing },
  promotion: null,
  customer: { name: 'Asha Verma', phone: '+919876543212', phone_verified: true, email: null },
  order_note: null,
  legal: { required: LEGAL, accepted: [], final_approval: false },
  payment_methods: { provider_status: 'READY', available: [{ type: 'UPI', status: 'PLANNED' }, { type: 'CARD', status: 'PLANNED' }], saved: [], selected: null, cash_at_pickup: 'NOT_APPROVED' },
  payment: null, issues: [], changes: [], ...patch,
})
/** ₹500 − ₹100 + ₹15 fee + 2 × 2.5 % test tax on ₹400 = ₹435. */
const pricedDto = (patch: Partial<CheckoutDto> = {}): CheckoutDto => checkoutDto({ promotion: { code: 'SAVE100', name: '₹100 off', discount: inr(10000) }, ...patch }, {
  discounts: [{ code: 'SAVE100', label: '₹100 off', amount: inr(10000) }], discount_total: inr(10000),
  taxes: [
    { code: 'TEST_TAX_A', label: 'Test tax A', applies_to: 'MERCHANDISE', rate_basis_points: 250, taxable_amount: inr(40000), amount: inr(1000), included_in_prices: false },
    { code: 'TEST_TAX_B', label: 'Test tax B', applies_to: 'MERCHANDISE', rate_basis_points: 250, taxable_amount: inr(40000), amount: inr(1000), included_in_prices: false },
  ], tax_total: inr(2000),
  fees: [{ code: 'PLATFORM_FEE', label: 'Test platform fee', amount: inr(1500), taxable: false }], fee_total: inr(1500), total: inr(43500), fingerprint: 'b'.repeat(64), configuration: { tax: 'TEST', fees: 'TEST' },
})
const readyDto = (base: CheckoutDto = checkoutDto()): CheckoutDto => ({
  ...base, status: 'READY', version: base.version + 1, expires_at: at(10), pickup: { ...base.pickup, hold: { held: true, expires_at: at(10) } },
  legal: { ...base.legal, accepted: LEGAL.map((d) => ({ type: d.type, version: d.version, status: d.status, accepted_at: at(0) })) },
  payment_methods: { ...base.payment_methods, selected: base.pricing.payment_required ? { type: 'UPI', saved_method_id: null } : null },
  payment: {
    status: base.pricing.payment_required ? 'READY_FOR_PAYMENT' : 'PAYMENT_NOT_REQUIRED', checkout_id: base.id, checkout_version: base.version + 1, amount: base.pricing.total, currency: 'INR', pricing_fingerprint: base.pricing.fingerprint,
    idempotency_key: `pay_${'c'.repeat(40)}`, payment_method: base.pricing.payment_required ? { type: 'UPI', saved_method_id: null } : null, expires_at: at(10), provider: { status: 'READY', name: 'fake', mode: 'FAKE' }, attempt: null,
  },
})
const cartDto = (): CartDto => ({
  id: CART, version: 4, status: 'ACTIVE', owner: 'customer', currency: 'INR', restaurant: { id: R1, slug: 'burger-hub', name: 'Burger Hub', visible: true }, journey: null,
  items: [{
    id: '2c000000-0000-4000-8000-000000000001', item: { id: '1b000000-0000-4000-8000-000000000001', slug: 'classic-burger', name: 'Classic Burger', image: null, version: 3 }, name: 'Classic Burger', quantity: 2, min_quantity: 1, max_quantity: 20,
    special_instructions: null, variants: [], modifiers: [], base_price_minor: 20000, unit_price_minor: 25000, line_subtotal_minor: 50000, currency: 'INR',
    snapshot: { unit_price_minor: 25000, base_price_minor: 20000, item_version: 3, taken_at: at(0) }, price_changed: false, priced_from: 'menu', included_in_subtotal: true, availability: { orderable: true, reason: null }, issues: [],
  }],
  item_count: 2, line_count: 1, subtotal_minor: 50000, validation: { status: 'VALID', can_proceed: true, issues: [], menu_changed: false, catalog_version: 7, checked_at: at(0) },
  expires_at: at(10000), created_at: at(-5), updated_at: at(-5),
})

/* ------------------------------------------------------------------ network stub */
type Call = { method: string; path: string; body: Record<string, unknown> | undefined; headers: Record<string, string> }
type Reply = { status?: number; body: unknown } | 'network'
let calls: Call[] = []
let reply: (method: string, path: string, call: Call) => Reply = () => ({ status: 201, body: { checkout: checkoutDto() } })
const error = (status: number, code: string, message: string, details: Record<string, unknown> = {}): Reply => ({ status, body: { error: { code, message, details, request_id: 'r1' } } })
const failure = async (call: Promise<unknown>): Promise<CheckoutApiError> => { try { await call } catch (e) { return e as CheckoutApiError } throw new Error('expected the call to fail') }
const USER = { id: 'u1', name: 'Asha Verma', phone: '+919876543212', email: '', memberSince: '2026-01-01' }
const signIn = () => { localStorage.setItem('fotg.mock.customers', JSON.stringify([USER])); sessionStorage.setItem('fotg.mock.session', JSON.stringify({ token: 'dev', userId: 'u1', expiresAt: Date.now() + 3600000 })) }

beforeEach(() => {
  calls = []; sessionStorage.clear(); localStorage.clear(); resetCartApiState(); tokens.set('customer', 'session-token'); setMockRestaurantLatency(0)
  reply = () => ({ status: 201, body: { checkout: checkoutDto() } })
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const u = new URL(url)
    const call: Call = { method: init.method ?? 'GET', path: decodeURIComponent(u.pathname.replace('/api/v1', '')), body: init.body ? JSON.parse(init.body as string) : undefined, headers: (init.headers ?? {}) as Record<string, string> }
    calls.push(call)
    const r = reply(call.method, call.path, call)
    if (r === 'network') throw new TypeError('fetch failed')
    return new Response(JSON.stringify(r.body), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } })
  }))
})
afterEach(() => { cleanup(); vi.unstubAllGlobals(); vi.useRealTimers() })

/* ------------------------------------------------------------------ mode */
describe('checkout mode', () => {
  it('is the development checkout in unit tests, and never the backend without the backend cart and pickup', () => {
    expect(checkoutMode()).toBe('mock')
    localStorage.setItem('fotg.checkout.mode', 'api')
    expect(checkoutMode()).toBe('mock') // the cart is not the backend's
    localStorage.setItem('fotg.cart.mode', 'api')
    expect(checkoutMode()).toBe('mock') // … and neither is the pickup time
    localStorage.setItem('fotg.pickup.mode', 'api')
    expect(checkoutMode()).toBe('api')
  })
})

/* ------------------------------------------------------------------ mapping */
describe('checkout API mapping', () => {
  it('keeps every figure the backend sent, and adds none', () => {
    const c = toCheckout(pricedDto(), 1000)
    expect(c).toMatchObject({ id: CK, status: 'DRAFT', version: 1, cartVersion: 4, itemCount: 2, receivedAtMs: 1000 })
    expect(c.pricing).toMatchObject({ currency: 'INR', subtotalMinor: 50000, discountMinor: 10000, taxMinor: 2000, taxIncludedMinor: 0, feeMinor: 1500, totalMinor: 43500, paymentRequired: true, fingerprint: 'b'.repeat(64), configuration: { tax: 'TEST', fees: 'TEST' } })
    expect(c.pricing.taxes.map((x) => [x.code, x.label, x.rateBasisPoints, x.taxableMinor, x.amountMinor, x.included])).toEqual([['TEST_TAX_A', 'Test tax A', 250, 40000, 1000, false], ['TEST_TAX_B', 'Test tax B', 250, 40000, 1000, false]])
    expect(c.pricing.fees).toEqual([{ code: 'PLATFORM_FEE', label: 'Test platform fee', amountMinor: 1500, taxable: false }])
    expect(c.promotion).toEqual({ code: 'SAVE100', name: '₹100 off', discountMinor: 10000 })
    expect(c.items).toEqual([{ id: '2c000000-0000-4000-8000-000000000001', itemSlug: 'classic-burger', name: 'Classic Burger', quantity: 2, specialInstructions: 'No onions', image: '', options: [{ key: 'v:g1:o1', groupName: 'Size', optionName: 'Large' }], unitPriceMinor: 25000, lineSubtotalMinor: 50000 }])
    expect(c.pickup).toMatchObject({ mode: 'scheduled', method: 'COUNTER', pickupAt: at(75), timezone: TZ, held: false, holdExpiresAt: null })
    expect(c.legal.required.map((d) => `${d.type}@${d.version}:${d.slug}`)).toEqual(['TERMS@draft-2026-09:terms', 'PRIVACY@draft-2026-09:privacy', 'REFUND@draft-2026-09:refund-policy'])
    expect(c.legal.finalApproval).toBe(false)
    expect(c.payment).toBeNull()
  })

  it('shows a confirmed checkout with its hold and its payment preparation', () => {
    const c = toCheckout(readyDto(pricedDto()))
    expect(c.status).toBe('READY')
    expect(c.pickup).toMatchObject({ held: true, holdExpiresAt: at(10) })
    expect(c.payment).toEqual({ status: 'READY_FOR_PAYMENT', amountMinor: 43500, currency: 'INR', idempotencyKey: `pay_${'c'.repeat(40)}`, fingerprint: 'b'.repeat(64), expiresAt: at(10), checkoutVersion: 2, provider: { status: 'READY', name: 'fake', mode: 'FAKE' }, attempt: null })
    // Once a payment was started the checkout names its latest attempt — how a reloaded page finds its payment again (Module 30).
    const paying = readyDto(pricedDto()); paying.payment = { ...paying.payment!, attempt: { id: 'pay-attempt-1', status: 'PENDING', attempt_number: 2 } }
    expect(toCheckout(paying).payment?.attempt).toEqual({ id: 'pay-attempt-1', status: 'PENDING', attemptNumber: 2 })
    expect(c.paymentMethods.selected).toEqual({ type: 'UPI', savedMethodId: null })
    // Nothing of a provider, and no credential field anywhere in what the pages read.
    expect(JSON.stringify(c)).not.toMatch(/card_number|cvv|upi_pin|provider_payment|secret/i)
  })

  it('counts the time left on the server clock, whatever this device thinks the time is', () => {
    // The device clock is five hours off; the answer arrived at device time 1 000 000.
    const c = toCheckout(checkoutDto(), 1_000_000)
    expect(remainingMs(c, 1_000_000)).toBe(15 * 60000)
    expect(remainingMs(c, 1_000_000 + 60_000)).toBe(14 * 60000)
    expect(remainingMs(c, 1_000_000 + 16 * 60000)).toBeLessThan(0)
  })
})

/* ------------------------------------------------------------------ gateway */
describe('checkout gateway', () => {
  const gw = () => new ApiCheckoutGateway()

  it('opens a checkout without naming a cart, a restaurant or an amount, with the session and an idempotency key', async () => {
    const c = await gw().open()
    expect(c.pricing.totalMinor).toBe(50000)
    expect(calls).toHaveLength(1)
    expect([calls[0].method, calls[0].path, calls[0].body]).toEqual(['POST', '/checkouts', {}])
    expect(calls[0].headers.Authorization).toBe('Bearer session-token')
    expect(calls[0].headers['Idempotency-Key']).toMatch(/^cko-[0-9a-z-]{16,}$/)
    expect(calls[0].headers['X-Cart-Token']).toBeUndefined()
    await gw().open({ promotionCode: 'save100', cartVersion: 4 })
    expect(calls[1].body).toEqual({ promotion_code: 'save100', cart_version: 4 })
  })

  it('asks once more with the SAME key when only the answer was lost', async () => {
    let n = 0
    reply = () => (++n === 1 ? 'network' : { status: 201, body: { checkout: checkoutDto() } })
    const c = await gw().open()
    expect(c.id).toBe(CK)
    expect(calls).toHaveLength(2)
    expect(calls[1].headers['Idempotency-Key']).toBe(calls[0].headers['Idempotency-Key'])
  })

  it('confirms with the fingerprint, the documents, a method type and a note — and nothing else', async () => {
    reply = () => ({ body: { checkout: readyDto() } })
    await gw().confirm(CK, { fingerprint: FP, documents: LEGAL.map((d) => ({ type: d.type, version: d.version })), paymentMethod: { type: 'CARD', savedMethodId: '7a000000-0000-4000-8000-000000000009' }, orderNote: ' Extra napkins ' })
    expect(calls[0].path).toBe(`/checkouts/${CK}/confirm`)
    expect(calls[0].body).toEqual({
      pricing_fingerprint: FP, accepted_documents: [{ type: 'TERMS', version: 'draft-2026-09' }, { type: 'PRIVACY', version: 'draft-2026-09' }, { type: 'REFUND', version: 'draft-2026-09' }],
      payment_method: { type: 'CARD', saved_method_id: '7a000000-0000-4000-8000-000000000009' }, order_note: ' Extra napkins ',
    })
    expect(calls[0].headers['Idempotency-Key']).toMatch(/^ckc-/)
    // A total of zero: no payment method, no empty note.
    await gw().confirm(CK, { fingerprint: FP, documents: [], paymentMethod: null, orderNote: '   ' })
    expect(Object.keys(calls[1].body ?? {})).toEqual(['pricing_fingerprint', 'accepted_documents'])
    // No request of this gateway can carry a figure.
    for (const c of calls) expect(JSON.stringify(c.body)).not.toMatch(/amount|total|subtotal|currency|discount|price"|customer|cart_id|restaurant/)
  })

  it('applies and removes a promotion by its code only', async () => {
    reply = () => ({ body: { checkout: pricedDto() } })
    expect((await gw().applyPromotion(CK, 'save100')).promotion?.discountMinor).toBe(10000)
    await gw().removePromotion(CK)
    await gw().show(CK)
    await gw().cancel(CK)
    expect(calls.map((c) => [c.method, c.path, c.body])).toEqual([['POST', `/checkouts/${CK}/promotion`, { code: 'save100' }], ['DELETE', `/checkouts/${CK}/promotion`, undefined], ['GET', `/checkouts/${CK}`, undefined], ['POST', `/checkouts/${CK}/cancel`, undefined]])
  })

  it('tells what the backend refused, and where the customer goes to put it right', async () => {
    const issues = (code: string, recover: string) => ({ issues: [{ code, message: `${code} message`, recover, details: {} }] })
    reply = () => error(409, 'item_unavailable', 'Something in your cart is no longer available.', issues('ITEM_UNAVAILABLE', 'cart'))
    let e = await failure(gw().open())
    expect([e.code, e.serverCode, e.recover, e.issues[0].message]).toEqual(['item_unavailable', 'item_unavailable', 'cart', 'ITEM_UNAVAILABLE message'])
    reply = () => error(409, 'pickup_slot_full', 'This pickup time is full.', issues('PICKUP_SLOT_FULL', 'pickup'))
    e = await failure(gw().open()); expect([e.code, e.recover]).toEqual(['pickup_full', 'pickup'])
    reply = () => error(409, 'checkout_stale', 'This checkout is out of date.', { reason: 'PRICE_CHANGED', ...issues('PRICE_CHANGED', 'refresh') })
    e = await failure(gw().confirm(CK, { fingerprint: FP, documents: [], paymentMethod: null, orderNote: '' })); expect([e.code, e.recover]).toEqual(['stale', 'refresh'])
    reply = () => error(410, 'checkout_expired', 'This checkout has expired.')
    expect((await failure(gw().show(CK))).code).toBe('expired')
    reply = () => error(404, 'checkout_not_found', 'This checkout does not exist.')
    expect((await failure(gw().show(CK))).code).toBe('not_found')
    reply = () => error(422, 'legal_acceptance_required', 'Please accept the current terms.', { missing: [{ type: 'TERMS', version: 'v2' }] })
    expect((await failure(gw().confirm(CK, { fingerprint: FP, documents: [], paymentMethod: null, orderNote: '' }))).code).toBe('legal')
    reply = () => error(422, 'payment_method_unavailable', 'This way of paying is not available.')
    expect((await failure(gw().confirm(CK, { fingerprint: FP, documents: [], paymentMethod: { type: 'CASH_AT_PICKUP' }, orderNote: '' }))).code).toBe('payment_method')
    reply = () => error(409, 'payment_in_progress', 'A payment for this cart is in progress.')
    expect((await failure(gw().open())).code).toBe('payment_in_progress')
    reply = () => error(401, 'unauthenticated', 'Please sign in.')
    expect((await failure(gw().open())).code).toBe('unauthenticated')
    // Without a connection nothing about a price is known — an old total is not a current one.
    reply = () => 'network'
    expect((await failure(gw().show(CK))).code).toBe('offline')
    expect(toCheckoutError(new Error('boom')).code).toBe('error')
  })

  it('maps a refused promotion code onto what the page says — and nothing more specific than the backend said', async () => {
    const refused = async (code: string, details: Record<string, unknown> = {}, status = 422) => { reply = () => error(status, code, 'refused', details); return failure(gw().applyPromotion(CK, 'X')) }
    expect(promoStatusOf(await refused('promotion_not_found'))).toBe('invalid')
    expect(promoStatusOf(await refused('promotion_expired'))).toBe('expired')
    expect(promoStatusOf(await refused('promotion_not_applicable'))).toBe('not_eligible')
    const minimum = await refused('promotion_minimum_not_met', { minimum_spend: inr(90000) })
    expect([promoStatusOf(minimum), minimum.details.minimum_spend]).toEqual(['min_spend', inr(90000)])
    expect(promoStatusOf(await refused('promotion_limit_reached'))).toBe('limit_reached')
    expect(promoStatusOf(await refused('promotion_customer_limit_reached'))).toBe('already_used')
    expect(promoStatusOf(await refused('rate_limited', { retry_after_seconds: 30 }, 429))).toBe('rate_limited')
  })
})

/* ------------------------------------------------------------------ provider */
const cartApi = (patch: Partial<CartApi> = {}): CartApi => ({
  mode: 'api', cart: toCart(cartDto()), hydrated: true, note: '', setNote: vi.fn(), reload: vi.fn(async () => {}), count: 2, subtotalMinor: 50000, discountMinor: 0, clearCart: vi.fn(), ...patch,
} as unknown as CartApi)
const pickupApi = (): PickupApi => ({
  source: 'api', status: 'SELECTED', ready: true, busy: false, selection: { mode: 'scheduled', slotId: 'k', requestedAt: at(75), restaurantTimezone: TZ, estimatedCustomerArrival: null, estimatedReadyTime: at(75), confirmedDisplayTime: '', cartId: CART, restaurantId: 'burger-hub' },
  clear: vi.fn(), validate: vi.fn(async () => ({ ok: true })),
} as unknown as PickupApi)
const providers = (cart: CartApi = cartApi()) => ({ children }: { children: ReactNode }) => (
  <LocaleProvider><AuthProvider><ProfileProvider><JourneyProvider><CartContext.Provider value={cart}><PickupCtx.Provider value={pickupApi()}><ServerCheckoutProvider>{children}</ServerCheckoutProvider></PickupCtx.Provider></CartContext.Provider></JourneyProvider></ProfileProvider></AuthProvider></LocaleProvider>
)
const readyCheckout = async (cart?: CartApi) => {
  signIn()
  const hook = renderHook(() => useCheckout(), { wrapper: providers(cart) })
  // The session is restored a moment after the first render: checkout starts once the customer is known.
  await waitFor(async () => { await act(async () => { await hook.result.current.prepare() }); expect(hook.result.current.status).not.toBe('REQUIRES_AUTH') })
  return hook
}

describe('checkout provider on the backend', () => {
  it('shows the checkout the backend made: its lines, its total, its methods — and calculates nothing', async () => {
    reply = () => ({ status: 201, body: { checkout: pricedDto() } })
    const { result } = await readyCheckout()
    expect(result.current.source).toBe('api')
    expect(result.current.status).toBe('READY')
    expect(result.current.summary).toMatchObject({ source: 'api', currency: 'INR', itemCount: 2, subtotalMinor: 50000, discountMinor: 10000, totalMinor: 43500, paymentEligible: true })
    expect(result.current.summary?.taxes.map((l) => [l.label, l.amountMinor, l.rateBasisPoints, l.included])).toEqual([['Test tax A', 1000, 250, false], ['Test tax B', 1000, 250, false]])
    expect(result.current.summary?.fees.map((l) => [l.label, l.amountMinor])).toEqual([['Test platform fee', 1500]])
    expect(result.current.promo).toMatchObject({ code: 'SAVE100', status: 'applied', discountMinor: 10000 })
    expect(result.current.methods.map((m) => [m.id, m.type, m.enabled])).toEqual([['upi', 'upi', true], ['card', 'card', true]])
    expect(result.current.paymentMethodId).toBe('upi')
    // Before the terms are accepted the checkout cannot be continued.
    expect(result.current.issues.filter((i) => i.blocking).map((i) => i.code)).toEqual(['terms'])
    expect(await result.current.continueToPayment()).toBeNull()
    expect(calls.filter((c) => c.path.endsWith('/confirm'))).toHaveLength(0)
  })

  it('is blocked with the backend\'s reason and the way back when the cart or the pickup time is not valid', async () => {
    reply = () => error(409, 'item_unavailable', 'Classic Burger is sold out.', { issues: [{ code: 'ITEM_UNAVAILABLE', message: 'Classic Burger is sold out.', recover: 'cart', details: {} }] })
    const { result } = await readyCheckout()
    expect(result.current.status).toBe('INVALID_CART')
    expect(result.current.server).toBeNull()
    expect(result.current.summary).toBeNull() // no total of an invalid cart is shown
    expect(result.current.issues.filter((i) => i.blocking)).toEqual([{ code: 'item_unavailable', blocking: true, message: 'Classic Burger is sold out.', recover: 'cart', detail: 'ITEM_UNAVAILABLE' }])

    reply = () => error(409, 'pickup_slot_full', 'This pickup time has just been taken.', { issues: [{ code: 'PICKUP_SLOT_FULL', message: 'This pickup time has just been taken.', recover: 'pickup', details: {} }] })
    await act(async () => { await result.current.prepare() })
    expect([result.current.status, result.current.issues.find((i) => i.blocking)?.code, result.current.issues.find((i) => i.blocking)?.recover]).toEqual(['INVALID_PICKUP', 'pickup_full', 'pickup'])

    reply = () => 'network'
    await act(async () => { await result.current.prepare() })
    expect(result.current.status).toBe('ERROR')
    expect(result.current.server).toBeNull()
  })

  it('applies a code through the backend and says why one was refused, keeping the applied one', async () => {
    const { result } = await readyCheckout()
    reply = (m, p) => (p.endsWith('/promotion') && m === 'POST' ? { body: { checkout: pricedDto() } } : { body: { checkout: checkoutDto() } })
    let res = await act(async () => result.current.applyPromo('save100'))
    expect(res).toMatchObject({ code: 'SAVE100', status: 'applied', discountMinor: 10000 })
    expect(result.current.summary?.totalMinor).toBe(43500)

    reply = () => error(422, 'promotion_minimum_not_met', 'Spend more.', { minimum_spend: inr(90000) })
    res = await act(async () => result.current.applyPromo('big250'))
    expect(res).toMatchObject({ code: 'BIG250', status: 'min_spend', minimumSpendMinor: 90000, discountMinor: 0 })
    expect(result.current.promo?.status).toBe('min_spend')
    // The refused code changed nothing: the checkout still carries SAVE100 and its total.
    expect([result.current.server?.promotion?.code, result.current.summary?.totalMinor]).toEqual(['SAVE100', 43500])
    expect(result.current.issues.find((i) => i.code === 'promo_invalid')).toMatchObject({ blocking: false, detail: 'min_spend' })

    reply = () => ({ body: { checkout: checkoutDto() } })
    await act(async () => { result.current.removePromo(); await new Promise((r) => setTimeout(r, 0)) })
    await waitFor(() => expect(result.current.summary?.totalMinor).toBe(50000))
    expect(result.current.promo).toBeNull()
    expect(calls.at(-1)).toMatchObject({ method: 'DELETE', path: `/checkouts/${CK}/promotion` })
  })

  it('confirms the price on the screen and hands the payment step the backend\'s total', async () => {
    reply = () => ({ status: 201, body: { checkout: pricedDto() } })
    const cart = cartApi({ note: 'Extra napkins' })
    const { result } = await readyCheckout(cart)
    act(() => { result.current.setTermsAccepted(true); result.current.setPaymentMethod('card') })
    expect(result.current.termsAccepted).toBe(true)
    reply = () => ({ body: { checkout: readyDto(pricedDto()) } })
    const req = await act(async () => result.current.continueToPayment())

    const sent = calls.at(-1)!
    expect(sent.path).toBe(`/checkouts/${CK}/confirm`)
    expect(sent.body).toEqual({ pricing_fingerprint: 'b'.repeat(64), accepted_documents: LEGAL.map((d) => ({ type: d.type, version: d.version })), payment_method: { type: 'CARD' }, order_note: 'Extra napkins' })
    expect(req).toMatchObject({ checkoutId: CK, pricingFingerprint: 'b'.repeat(64), displayedTotalMinor: 43500, currency: 'INR', idempotencyKey: `pay_${'c'.repeat(40)}`, promoCode: 'SAVE100', paymentMethodId: 'card', termsVersion: 'draft-2026-09', privacyVersion: 'draft-2026-09', customerId: 'u1' })
    expect(result.current.status).toBe('PAYMENT_READY')
    expect(result.current.server).toMatchObject({ status: 'READY', pickup: { held: true } })
    expect(JSON.parse(sessionStorage.getItem('fotg.checkout.request') ?? '{}')).toMatchObject({ checkoutId: CK, displayedTotalMinor: 43500 })
  })

  it('never confirms an old price: a stale checkout is started again and the customer sees the new total first', async () => {
    const { result } = await readyCheckout()
    act(() => { result.current.setTermsAccepted(true) })
    const dearer = checkoutDto({ id: '9c000000-0000-4000-8000-000000000002', changes: [{ code: 'PRICE_CHANGED', name: 'Classic Burger', previous_unit_price: inr(25000), unit_price: inr(30000) }] }, { items_subtotal: inr(60000), total: inr(60000), fingerprint: 'd'.repeat(64) })
    reply = (_m, p) => (p.endsWith('/confirm') ? error(409, 'checkout_stale', 'This checkout is out of date.', { reason: 'PRICE_CHANGED', issues: [{ code: 'PRICE_CHANGED', message: 'A price in your cart has changed.', recover: 'refresh', details: {} }] }) : { status: 201, body: { checkout: dearer } })
    const req = await act(async () => result.current.continueToPayment())

    expect(req).toBeNull()
    expect(result.current.request).toBeNull()
    expect(result.current.status).toBe('READY')
    expect(result.current.summary?.totalMinor).toBe(60000)
    expect(result.current.notice).toEqual({ kind: 'updated', message: 'A price in your cart has changed.', previousTotalMinor: 50000, legal: false })
    expect(result.current.server?.changes[0]).toMatchObject({ code: 'PRICE_CHANGED' })
    // The acceptance stands (same documents, same versions); the new price needs its own confirmation.
    expect(result.current.termsAccepted).toBe(true)
  })

  it('asks for the terms again when their version changed', async () => {
    const { result } = await readyCheckout()
    act(() => { result.current.setTermsAccepted(true) })
    const updated = checkoutDto({ id: '9c000000-0000-4000-8000-000000000003', legal: { required: [{ ...LEGAL[0], version: 'v1-2026-11' }, LEGAL[1], LEGAL[2]], accepted: [], final_approval: false } })
    reply = (_m, p) => (p.endsWith('/confirm') ? error(422, 'legal_acceptance_required', 'Please accept the current terms.', { missing: [{ type: 'TERMS', version: 'v1-2026-11' }] }) : { status: 201, body: { checkout: updated } })
    expect(await act(async () => result.current.continueToPayment())).toBeNull()
    expect(result.current.termsAccepted).toBe(false)
    expect(result.current.notice).toMatchObject({ legal: true })
    expect(result.current.issues.filter((i) => i.blocking).map((i) => i.code)).toEqual(['terms'])
  })

  it('says a checkout has expired, withdraws the payment request and never shows it as payable', async () => {
    reply = () => ({ status: 201, body: { checkout: checkoutDto() } })
    const { result } = await readyCheckout()
    act(() => { result.current.setTermsAccepted(true) })
    reply = () => ({ body: { checkout: readyDto() } })
    await act(async () => { await result.current.continueToPayment() })
    expect(result.current.request).not.toBeNull()

    reply = () => ({ body: { checkout: { ...readyDto(), status: 'EXPIRED', status_reason: 'CHECKOUT_EXPIRED', payment: null, pickup: { ...readyDto().pickup, hold: { held: false, expires_at: null } }, issues: [{ code: 'CHECKOUT_EXPIRED', message: 'This checkout has expired. Please start it again.', recover: 'refresh', details: {} }] } } })
    const still = await act(async () => result.current.revalidate())
    expect(still).toBe(false)
    expect(result.current.status).toBe('EXPIRED')
    expect(result.current.request).toBeNull()
    expect(sessionStorage.getItem('fotg.checkout.request')).toBeNull()
    expect(result.current.issues.find((i) => i.blocking)).toMatchObject({ code: 'checkout_expired', recover: 'refresh' })
    // Looking again while nothing changed says so.
    reply = () => ({ status: 201, body: { checkout: checkoutDto() } })
    await act(async () => { await result.current.prepare() })
    reply = () => ({ body: { checkout: checkoutDto() } })
    expect(await act(async () => result.current.revalidate())).toBe(true)
    // No answer is not "still fine".
    reply = () => 'network'
    expect(await act(async () => result.current.revalidate())).toBe(false)
    expect(result.current.status).toBe('ERROR')
  })

  it('needs no payment method when the total is zero', async () => {
    const free = checkoutDto({ promotion: { code: 'FREEMEAL', name: '100 % off', discount: inr(50000) }, payment_methods: { ...checkoutDto().payment_methods } }, { discounts: [{ code: 'FREEMEAL', label: '100 % off', amount: inr(50000) }], discount_total: inr(50000), total: inr(0), payment_required: false })
    reply = () => ({ status: 201, body: { checkout: free } })
    const { result } = await readyCheckout()
    expect([result.current.summary?.totalMinor, result.current.summary?.paymentEligible, result.current.methods, result.current.paymentMethodId]).toEqual([0, false, [], null])
    act(() => { result.current.setTermsAccepted(true) })
    expect(result.current.issues.filter((i) => i.blocking)).toEqual([])
    reply = () => ({ body: { checkout: readyDto(free) } })
    const req = await act(async () => result.current.continueToPayment())
    expect(calls.at(-1)?.body).not.toHaveProperty('payment_method')
    expect(req).toMatchObject({ displayedTotalMinor: 0, checkoutId: CK })
    expect(result.current.server?.payment?.status).toBe('PAYMENT_NOT_REQUIRED')
  })
})

/* ------------------------------------------------------------------ page */
function Loc() { const l = useLocation(); return <output data-testid="loc">{l.pathname}</output> }
const mountPage = (cart: CartApi = cartApi()) => {
  signIn()
  const Wrapper = providers(cart)
  return render(<Wrapper><MemoryRouter initialEntries={['/checkout']}><Routes><Route path="/checkout" element={<CheckoutPage />} /><Route path="*" element={<p>other page</p>} /></Routes><Loc /></MemoryRouter></Wrapper>)
}

describe('checkout page on the backend', () => {
  it('shows the backend\'s customer, lines, taxes, fees and total — and puts that total on the button', async () => {
    reply = () => ({ status: 201, body: { checkout: pricedDto() } })
    mountPage()
    const total = await screen.findByTestId('co-total')
    expect(total).toHaveAttribute('data-minor', '43500')
    expect(screen.getByTestId('co-summary')).toHaveAttribute('data-source', 'api')
    expect(screen.getByTestId('co-subtotal')).toHaveAttribute('data-minor', '50000')
    expect(screen.getByTestId('co-discount')).toHaveAttribute('data-minor', '10000')
    expect(screen.getAllByTestId('co-tax').map((n) => n.textContent)).toEqual([expect.stringMatching(/Test tax A \(2\.5%\).*10\.00/), expect.stringMatching(/Test tax B \(2\.5%\).*10\.00/)])
    expect(screen.getByTestId('co-fee').textContent).toMatch(/Test platform fee.*15\.00/)
    expect(screen.getByTestId('co-phone').textContent).toContain('+919876543212')
    expect(screen.getByTestId('co-restaurant')).toHaveTextContent('Burger Hub')
    expect(within(screen.getByTestId('co-items-list')).getByTestId('co-line-total')).toHaveAttribute('data-minor', '50000')
    expect(screen.getByTestId('co-promo-applied')).toHaveAttribute('data-code', 'SAVE100')
    const cta = screen.getByTestId('co-continue')
    expect(cta).toHaveAttribute('data-amount', '43500')
    expect(cta.textContent).toMatch(/Continue to payment · ₹435\.00/)
    // A test configuration is called one; no invoice, no delivery, no development-checkout label, no invented tax name.
    expect(screen.getByTestId('co-config-test')).toHaveTextContent(/TEST TAX \/ FEE CONFIGURATION/)
    expect(screen.queryByTestId('co-provider-unavailable')).toBeNull()   // payments can be taken: nothing to warn about
    expect(screen.getByTestId('co-legal-draft')).toBeInTheDocument()
    expect(screen.getAllByTestId('co-legal-doc').map((n) => `${n.getAttribute('data-type')}@${n.getAttribute('data-version')}`)).toEqual(['TERMS@draft-2026-09', 'PRIVACY@draft-2026-09', 'REFUND@draft-2026-09'])
    expect(document.body.textContent).not.toMatch(/GST|VAT|delivery fee|tax invoice|development checkout|cash at pickup/i)
    // The countdown is set by an effect after the checkout arrives: wait for it (reading it in the same tick was a race — BUG-239).
    expect(await screen.findByTestId('co-expires')).toHaveAttribute('data-seconds')
  })

  it('charges nothing it was not told to: no tax line, no fee line, and says nothing is configured', async () => {
    mountPage()
    expect(await screen.findByTestId('co-total')).toHaveAttribute('data-minor', '50000')
    expect(screen.queryAllByTestId('co-tax')).toHaveLength(0)
    expect(screen.queryAllByTestId('co-fee')).toHaveLength(0)
    expect(screen.queryByTestId('co-discount')).toBeNull()
    expect(screen.getByTestId('co-config-none')).toBeInTheDocument()
    expect(screen.queryByTestId('co-config-test')).toBeNull()
  })

  it('confirms once on a double tap and goes to the payment step with a confirmed checkout', async () => {
    mountPage()
    const user = userEvent.setup()
    await screen.findByTestId('co-total')
    await user.click(screen.getByTestId('co-terms'))
    let release: (r: Reply) => void = () => {}
    const pending = new Promise<Reply>((resolve) => { release = resolve })
    const fetchMock = vi.fn(async (url: string, init: RequestInit = {}) => {
      const path = new URL(url).pathname.replace('/api/v1', '')
      calls.push({ method: init.method ?? 'GET', path, body: init.body ? JSON.parse(init.body as string) : undefined, headers: (init.headers ?? {}) as Record<string, string> })
      const r = path.endsWith('/confirm') ? await pending : ({ body: { checkout: readyDto() } } as Reply)
      if (r === 'network') throw new TypeError('fetch failed')
      return new Response(JSON.stringify(r.body), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } })
    })
    vi.stubGlobal('fetch', fetchMock)
    const cta = screen.getByTestId('co-continue')
    await user.click(cta)
    await waitFor(() => expect(cta).toBeDisabled())
    await user.click(cta)
    release({ body: { checkout: readyDto() } })
    await waitFor(() => expect(screen.getByTestId('loc')).toHaveTextContent('/payment'))
    expect(calls.filter((c) => c.path.endsWith('/confirm'))).toHaveLength(1)
  })

  it('blocks the button and points back to the cart when the backend refuses the cart', async () => {
    reply = () => error(409, 'item_unavailable', 'Classic Burger is sold out.', { issues: [{ code: 'ITEM_UNAVAILABLE', message: 'Classic Burger is sold out.', recover: 'cart', details: {} }] })
    mountPage()
    const issue = await screen.findByTestId('co-issue')
    expect(issue).toHaveTextContent('Classic Burger is sold out.')
    expect(issue).toHaveAttribute('data-recover', 'cart')
    expect(screen.getByTestId('co-recover-cart')).toHaveAttribute('href', '/cart')
    expect(screen.getByTestId('co-continue')).toBeDisabled()
    expect(screen.queryByTestId('co-total')).toBeNull()
  })

  it('shows an expired checkout as expired and starts a new one on request', async () => {
    mountPage()
    await screen.findByTestId('co-total')
    reply = (m) => (m === 'GET' ? { body: { checkout: { ...checkoutDto(), status: 'EXPIRED', issues: [{ code: 'CHECKOUT_EXPIRED', message: 'This checkout has expired. Please start it again.', recover: 'refresh', details: {} }] } } } : { status: 201, body: { checkout: checkoutDto({ id: '9c000000-0000-4000-8000-000000000009' }) } })
    await act(async () => { window.dispatchEvent(new Event('focus')); await new Promise((r) => setTimeout(r, 0)) })
    expect(await screen.findByTestId('co-expired')).toBeInTheDocument()
    expect(screen.getByTestId('co-continue')).toBeDisabled()
    await userEvent.setup().click(screen.getByTestId('co-restart'))
    await waitFor(() => expect(screen.queryByTestId('co-expired')).toBeNull())
    expect(screen.getByTestId('co-continue')).not.toBeDisabled()
  })
})

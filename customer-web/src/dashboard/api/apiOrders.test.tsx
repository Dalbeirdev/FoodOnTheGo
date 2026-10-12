/**
 * Restaurant Dashboard on the backend's refund state (Module 35). After a paid order is rejected the restaurant is shown
 * what happens to the money — required, processing, refunded — with the amounts. It is information only: the dashboard
 * has no refund control, sends no refund request, and never shows "payment failed" for a refund.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { tokens } from '../../api/client'
import { t } from '../../i18n/strings'
import { ApiRestaurantOrderRepository } from './apiOrders'

const BASE = 'http://127.0.0.1:8001/api/v1'
const N = 'FOTG-20261012-ABC234'; const LOC = '0c000000-0000-4000-8000-000000000001'
const money = (amount: number) => ({ amount, currency: 'INR' })
const management = { locationUuid: () => LOC, getLocations: async () => [], getLocation: async () => ({ restaurant: { name: 'Burger Hub', timezone: 'Asia/Kolkata' } }) }
const order = (refundStatus: string, refund: unknown) => ({
  id: '0a000000-0000-4000-8000-000000000001', order_number: N, status: 'REJECTED', payment_state: 'PAID', refund_status: refundStatus, location_id: LOC,
  customer: { name: 'Rahul Sharma', phone: null }, item_count: 2, total: money(50000),
  pickup: { mode: 'ASAP', method: 'COUNTER', pickup_at: '2026-10-12T06:00:00+00:00', pickup_at_local: null, slot: { starts_at: null, ends_at: null }, timezone: 'Asia/Kolkata', estimated_prep_minutes: 20, traveler_arrival_at: null },
  placed_at: '2026-10-12T05:00:00+00:00', updated_at: '2026-10-12T05:05:00+00:00', version: 3, allowed_actions: [],
  items: [], pricing: { currency: 'INR', subtotal: money(50000), discount: money(0), tax: money(0), tax_included: money(0), fee: money(0), total: money(50000) }, promotion: null, taxes: [], fees: [], customer_note: null,
  rejection: { reason: 'ITEM_UNAVAILABLE', note: 'Out of buns' }, cancellation: null, journey: null,
  timeline: [{ sequence: 1, event_id: 'x-1', at: '2026-10-12T05:00:00+00:00', type: 'ORDER_CREATED', from: null, to: 'WAITING_FOR_RESTAURANT', actor: 'SYSTEM', detail: {} }, { sequence: 2, event_id: 'x-2', at: '2026-10-12T05:01:00+00:00', type: 'RESTAURANT_REJECTED', from: 'WAITING_FOR_RESTAURANT', to: 'REJECTED', actor: 'RESTAURANT_USER', detail: { reason: 'ITEM_UNAVAILABLE' } }, { sequence: 3, event_id: 'x-3', at: '2026-10-12T05:01:00+00:00', type: 'REFUND_REQUIRED', from: null, to: null, actor: 'SYSTEM', detail: { refund_status: 'REQUIRED' } }],
  times: {}, refund,
})

let calls: Array<{ method: string; path: string }> = []
let answer: unknown
beforeEach(() => {
  calls = []; tokens.set('restaurant', 'staff-token')
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    calls.push({ method: init.method ?? 'GET', path: decodeURIComponent(url.replace(BASE, '')) })
    return new Response(JSON.stringify({ order: answer }), { status: 200, headers: { 'Content-Type': 'application/json' } })
  }))
})
afterEach(() => { vi.unstubAllGlobals(); tokens.set('restaurant', null) })

describe('Restaurant Dashboard — the refund state of an order (Module 35)', () => {
  it('reads what happens to the money after a paid order was rejected, at every step, without any control', async () => {
    const repo = new ApiRestaurantOrderRepository(management as never)

    answer = order('REQUIRED', { status: 'REQUIRED', amount: money(50000), refunded: money(0), controls: 'NOT_PROVIDED' })
    const required = await repo.get('burger-hub', N)
    expect([required?.orderStatus, required?.paymentStatus]).toEqual(['REJECTED', 'REFUND_PENDING'])
    expect(required?.restaurantRefund).toEqual({ status: 'REQUIRED', amountMinor: 50000, refundedMinor: 0, currency: 'INR' })
    // The restaurant's own view carries no refund id and no provider reference.
    expect(required?.refunds).toBeUndefined()
    expect(required?.events.map((e) => e.type)).toEqual(['ORDER_CREATED', 'RESTAURANT_REJECTED', 'REFUND_UPDATED'])

    answer = order('PROCESSING', { status: 'PROCESSING', amount: money(50000), refunded: money(0), controls: 'NOT_PROVIDED' })
    expect((await repo.get('burger-hub', N))?.restaurantRefund).toMatchObject({ status: 'PROCESSING', refundedMinor: 0 })
    answer = order('REFUNDED', { status: 'REFUNDED', amount: money(50000), refunded: money(50000), controls: 'NOT_PROVIDED' })
    const refunded = await repo.get('burger-hub', N)
    expect([refunded?.orderStatus, refunded?.paymentStatus, refunded?.restaurantRefund?.refundedMinor]).toEqual(['REJECTED', 'REFUNDED', 50000])
    // A refund that failed or needs a person is "under review" for the restaurant too — never "payment failed".
    answer = order('REVIEW_REQUIRED', { status: 'REVIEW_REQUIRED', amount: money(50000), refunded: money(0), controls: 'NOT_PROVIDED' })
    expect((await repo.get('burger-hub', N))?.paymentStatus).toBe('REFUND_UNDER_REVIEW')

    // An order that was not paid, and an answer of an older backend, have no refund block.
    answer = order('NONE', null)
    expect((await repo.get('burger-hub', N))?.restaurantRefund).toBeNull()
    answer = order('NONE', undefined)
    expect((await repo.get('burger-hub', N))?.restaurantRefund).toBeNull()

    // Reading is all the dashboard does about refunds: nothing was posted anywhere.
    expect(calls.every((c) => c.method === 'GET' && c.path === `/restaurant/orders/${N}`)).toBe(true)
    expect(Object.getOwnPropertyNames(ApiRestaurantOrderRepository.prototype).filter((m) => /refund/i.test(m))).toEqual([])
  })

  it('says in the restaurant\'s words what each refund state means and that there is nothing to do', () => {
    expect(t('dash.orders.refund.REQUIRED', undefined, 'en-IN')).toBe('Refund required — FoodOnTheGo is arranging it')
    expect(t('dash.orders.refund.PROCESSING', undefined, 'en-IN')).toMatch(/with the payment provider/)
    expect(t('dash.orders.refund.REFUNDED', undefined, 'en-IN')).toBe('Refunded to the customer')
    for (const status of ['FAILED', 'REVIEW_REQUIRED']) expect(t(`dash.orders.refund.${status}`, undefined, 'en-IN')).toMatch(/under review by FoodOnTheGo/)
    expect(t('dash.orders.refund.note', undefined, 'en-IN')).toMatch(/You do not need to do anything/)
    expect(t('dash.orders.rejectText', undefined, 'en-IN')).toMatch(/FoodOnTheGo starts the refund — you do not refund the customer yourself/)
    for (const status of ['REQUIRED', 'PROCESSING', 'PARTIALLY_REFUNDED', 'REFUNDED', 'FAILED', 'REVIEW_REQUIRED']) expect(t(`dash.orders.refund.${status}`, undefined, 'en-IN')).not.toMatch(/payment failed|dash\.orders/i)
  })
})

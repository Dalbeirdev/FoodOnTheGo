import { beforeEach, describe, expect, it } from 'vitest'
import { MockOrderRepository, setMockOrderLatency } from './mock/mockOrder'
import { MockOrderTrackingService, setMockTrackingInterval, setScenario } from './mock/mockTracking'
import type { CreateOrderInput, Order, OrderEvent } from './repositories'
import { distanceMeters, eventForStep, reduceOrder, SCENARIO_STEPS, timelineFor, type TrackingScenario } from './tracking'

/** Module 14 — event reducer, timeline, deterministic scenarios (web unit tests). */
const input = (): CreateOrderInput => ({
  paymentAttemptId: 'pay_' + Math.random().toString(16).slice(2, 10), checkoutReference: 'ck', customerId: 'u1',
  restaurant: { id: 'burger-hub', slug: 'burger-hub', name: 'Burger Hub', formattedAddress: 'Noida', countryCode: 'IN', timezone: 'Asia/Kolkata', lat: 28.6285, lng: 77.3652, contact: null, pickupInstructions: null, pickupLocation: null },
  items: [{ lineId: 'l1', menuItemId: 'x', itemName: 'Classic Burger', image: '', variants: [], modifiers: [], specialInstructions: '', quantity: 1, unitPriceMinor: 25000, lineTotalMinor: 25000 }],
  pricing: { currency: 'INR', subtotalMinor: 25000, discountMinor: 0, promoCode: null, taxes: [], fees: [], totalMinor: 25000 },
  payment: { status: 'PAID', methodType: 'upi', methodLabel: 'UPI', providerDisplayName: 'mock', reference: 'pay_ref', paidAmountMinor: 25000, currency: 'INR', maskedDetails: null },
  pickup: { mode: 'asap', requestedAt: '2026-09-29T09:00:00.000Z', estimatedReadyTime: '2026-09-29T09:00:00.000Z', restaurantTimezone: 'Asia/Kolkata', methodType: 'counter', methodLabel: 'Counter pickup', instructions: null, estimatedCustomerArrival: '2026-09-29T08:50:00.000Z' },
  journey: null, orderNote: '',
})
const repo = new MockOrderRepository()
const run = async (scenario: TrackingScenario, o: Order) => { let cur = o; for (const step of SCENARIO_STEPS[scenario]) { cur = reduceOrder(cur, eventForStep(cur, step)).order } return cur }

describe('tracking reducer + scenarios', () => {
  beforeEach(() => { sessionStorage.clear(); setMockOrderLatency(0); setMockTrackingInterval(0) })

  it('TEST 1 — normal flow advances Confirmed → Accepted → Preparing → Ready → Verification → Picked up → Completed with a full history', async () => {
    const o = await repo.createFromPayment(input())
    expect(o.orderStatus).toBe('CONFIRMED'); expect(o.paymentStatus).toBe('PAID'); expect(o.lastEventSequence).toBe(3)
    const statuses: string[] = []; let cur = o
    for (const step of SCENARIO_STEPS.normal) { cur = reduceOrder(cur, eventForStep(cur, step)).order; statuses.push(cur.orderStatus) }
    expect(statuses).toEqual(['AWAITING_RESTAURANT_ACCEPTANCE', 'ACCEPTED', 'PREPARING', 'READY_FOR_PICKUP', 'PICKUP_VERIFICATION', 'PICKED_UP', 'COMPLETED'])
    expect(cur.events).toHaveLength(10); expect(cur.lastEventSequence).toBe(10); expect(cur.pickupVerificationStatus).toBe('VERIFIED'); expect(cur.paymentStatus).toBe('PAID')
  })

  it('TEST 8 — a duplicate event (same id) is ignored', async () => {
    const o = await repo.createFromPayment(input())
    const e = eventForStep(o, SCENARIO_STEPS.normal[0])
    const once = reduceOrder(o, e); const twice = reduceOrder(once.order, { ...e })
    expect(once.applied).toBe(true); expect(twice.applied).toBe(false); expect(twice.reason).toBe('duplicate'); expect(twice.order.events).toHaveLength(4)
  })

  it('TEST 9 — an older event (lower sequence) never regresses the status', async () => {
    const o = await repo.createFromPayment(input())
    const cur = await run('ready', o)
    expect(cur.orderStatus).toBe('READY_FOR_PICKUP')
    const stale: OrderEvent = { eventId: 'replay-1', sequence: 4, type: 'SENT_TO_RESTAURANT', status: 'AWAITING_RESTAURANT_ACCEPTANCE', at: new Date().toISOString(), actor: 'system' }
    const r = reduceOrder(cur, stale)
    expect(r.applied).toBe(false); expect(r.reason).toBe('stale'); expect(r.order.orderStatus).toBe('READY_FOR_PICKUP')
  })

  it('TEST 2 — delay keeps PREPARING, flags the delay with a customer-safe reason and moves the ready ETA forward', async () => {
    const o = await repo.createFromPayment(input())
    let cur = o; for (const step of SCENARIO_STEPS.delay.slice(0, 4)) cur = reduceOrder(cur, eventForStep(cur, step)).order
    expect(cur.orderStatus).toBe('PREPARING'); expect(cur.delayed).toBe(true); expect(cur.delayReasonKey).toBe('high_demand')
    expect(new Date(cur.etaReadyAt!).getTime() - new Date(o.etaReadyAt!).getTime()).toBe(15 * 60000)
    expect(cur.pickup.estimatedCustomerArrival).toBe('2026-09-29T08:50:00.000Z') // arrival ETA untouched — separate concept
    const tl = timelineFor(cur); expect(tl.find((s) => s.key === 'preparing')).toMatchObject({ state: 'current', note: 'delayed' })
    const ready = reduceOrder(cur, eventForStep(cur, SCENARIO_STEPS.delay[4])).order
    expect(ready.delayed).toBe(false)
  })

  it('TEST 3 — rejection stops the timeline; later fulfilment events are refused; payment moves to refund pending', async () => {
    const o = await repo.createFromPayment(input())
    const cur = await run('rejected', o)
    expect(cur.orderStatus).toBe('REJECTED'); expect(cur.paymentStatus).toBe('REFUND_PENDING'); expect(cur.rejectionReasonKey).toBe('item_unavailable')
    const tl = timelineFor(cur)
    expect(tl.find((s) => s.key === 'accepted')).toMatchObject({ state: 'stopped', note: 'rejected' })
    expect(tl.filter((s) => s.state === 'done').map((s) => s.key)).toEqual(['placed', 'payment'])
    const r = reduceOrder(cur, eventForStep(cur, SCENARIO_STEPS.normal[2]))
    expect(r.applied).toBe(false); expect(r.reason).toBe('terminal'); expect(r.order.orderStatus).toBe('REJECTED')
  })

  it('TEST 4 — cancellation after acceptance: cancelled + refund status separate; refund update still applies', async () => {
    const o = await repo.createFromPayment(input())
    const cur = await run('cancelled', o)
    expect(cur.orderStatus).toBe('CANCELLED'); expect(cur.paymentStatus).toBe('REFUNDED'); expect(cur.cancellationReasonKey).toBe('restaurant_unavailable')
    const tl = timelineFor(cur)
    expect(tl.find((s) => s.key === 'accepted')?.state).toBe('done'); expect(tl.find((s) => s.key === 'preparing')).toMatchObject({ state: 'stopped', note: 'cancelled' })
  })

  it('TEST 5 — a payment-pending order has no fulfilment steps', async () => {
    const o = await repo.createFromPayment({ ...input(), payment: { ...input().payment, status: 'PAYMENT_PENDING', paidAmountMinor: 0 } })
    expect(o.orderStatus).toBe('PAYMENT_PENDING'); expect(SCENARIO_STEPS.payment_pending).toHaveLength(0)
    const tl = timelineFor(o); expect(tl.find((s) => s.key === 'payment')?.state).toBe('current'); expect(tl.filter((s) => s.state === 'done').map((s) => s.key)).toEqual(['placed'])
  })

  it('TEST 6 / 7 — ready then verification then picked up: pickup verification states follow the events', async () => {
    const o = await repo.createFromPayment(input())
    const ready = await run('ready', o); expect(ready.pickupVerificationStatus).toBe('READY')
    const picked = await run('picked_up', o); expect(picked.orderStatus).toBe('PICKED_UP'); expect(picked.pickupVerificationStatus).toBe('VERIFIED')
    expect(timelineFor(picked).find((s) => s.key === 'picked_up')?.state).toBe('current'); expect(timelineFor(picked).find((s) => s.key === 'completed')?.state).toBe('future')
  })

  it('mock service: deterministic advance, persisted through the repository, idempotent re-send, stale replay ignored by the reducer', async () => {
    setScenario('normal')
    const o = await repo.createFromPayment(input())
    const svc = new MockOrderTrackingService(repo)
    const seen: OrderEvent[] = []; const conns: string[] = []
    const unsub = svc.subscribe(o, { onEvent: (e) => seen.push(e), onConnection: (c) => conns.push(c) })
    for (let i = 0; i < 7; i++) await svc.advance(o.orderNumber)
    expect(seen.map((e) => e.type)).toEqual(['SENT_TO_RESTAURANT', 'RESTAURANT_ACCEPTED', 'PREPARING', 'READY_FOR_PICKUP', 'PICKUP_VERIFICATION', 'PICKED_UP', 'COMPLETED'])
    expect(await svc.advance(o.orderNumber)).toBeNull() // script finished — nothing more is ever emitted
    const stored = await repo.getByOrderNumber(o.orderNumber, 'u1')
    expect(stored!.orderStatus).toBe('COMPLETED'); expect(stored!.events).toHaveLength(10)
    svc.injectDuplicate(o.orderNumber); svc.injectStale(o.orderNumber)
    let cur = stored!; let ignored = 0; for (const e of seen.slice(7)) { const r = reduceOrder(cur, e); if (!r.applied) ignored++; cur = r.order }
    expect(ignored).toBe(2); expect(cur.orderStatus).toBe('COMPLETED')
    unsub()
    expect(conns[0]).toBe('LIVE')
  })

  it('network_loss scenario reports STALE then LIVE around the accepted event', async () => {
    setScenario('network_loss'); setMockTrackingInterval(0)
    const o = await repo.createFromPayment(input())
    const svc = new MockOrderTrackingService(repo)
    const conns: string[] = []; const seen: string[] = []
    svc.subscribe(o, { onEvent: (e) => seen.push(e.type), onConnection: (c) => conns.push(c) })
    await svc.advance(o.orderNumber); await svc.advance(o.orderNumber)
    expect(conns).toEqual(['LIVE', 'STALE'])
    await new Promise((r) => setTimeout(r, 1600))
    expect(conns).toEqual(['LIVE', 'STALE', 'LIVE']); expect(seen).toEqual(['SENT_TO_RESTAURANT', 'RESTAURANT_ACCEPTED'])
  })

  it('TEST 13 — distance helper feeds metric / imperial formatting', () => {
    const m = distanceMeters(28.5355, 77.3910, 28.6285, 77.3652)
    expect(m).toBeGreaterThan(10000); expect(m).toBeLessThan(11500)
  })
})

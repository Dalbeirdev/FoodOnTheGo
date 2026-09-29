/**
 * Development order stack (Module 13). Orders live in sessionStorage so the confirmation survives a refresh; nothing here
 * is a real backend order. Controls: sessionStorage fotg.mock.fail contains "order" → loading fails (retry recovers).
 * Fixture references (development only): FOTG-DEMO-PEND (payment pending), FOTG-DEMO-CANC (cancelled).
 */
import type { CreateOrderInput, Order, OrderEvent, OrderListQuery, OrderPage, OrderPaymentStatus, OrderRepository, OrderStatus, OrderSummary, PickupVerification, PickupVerificationRepository, Receipt, ReceiptRepository } from '../repositories'
import { groupOf, pageSummaries } from '../history'
import { reduceOrder } from '../tracking'

const ORDERS_KEY = 'fotg.orders.v1'
const PV_KEY = 'fotg.pickup_verifications.v1'
const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789' // no 0/O/1/I — readable at a counter
const rnd = (n: number) => { const a = new Uint8Array(n); crypto.getRandomValues(a); return a }
const hex = (n: number) => Array.from(rnd(n), (b) => b.toString(16).padStart(2, '0')).join('')
const code = (n: number) => Array.from(rnd(n), (b) => ALPHABET[b % ALPHABET.length]).join('')
/** Fixture order reference — the prefix / shape is NOT a business rule; the backend generates real references. */
const orderNumber = () => `FOTG-${code(4)}-${code(4)}`
const ulidLike = () => Date.now().toString(36).toUpperCase().padStart(10, '0') + hex(8).toUpperCase()

let latency = 350
export const setMockOrderLatency = (ms: number) => { latency = ms }
const wait = () => new Promise<void>((r) => setTimeout(r, latency))
const failing = () => { try { return (sessionStorage.getItem('fotg.mock.fail') ?? '').includes('order') } catch { return false } }
const load = <T,>(k: string): T[] => { try { const raw = sessionStorage.getItem(k); return raw ? (JSON.parse(raw) as T[]) : [] } catch { return [] } }
const save = <T,>(k: string, v: T[]) => { try { sessionStorage.setItem(k, JSON.stringify(v.slice(-30))) } catch { /* ignore */ } }

const COMPLETED_LIKE: ReadonlySet<OrderStatus> = new Set(['PICKED_UP', 'COMPLETED'])
function seedTail(publicId: string, os: OrderStatus, ps: OrderPaymentStatus, at: string): OrderEvent[] {
  const mk = (seq: number, type: OrderEvent['type'], status: OrderStatus | null, extra: Partial<OrderEvent> = {}): OrderEvent => ({ eventId: `${publicId}-${seq}`, sequence: seq, type, status, at, actor: 'restaurant', ...extra })
  const path: Record<string, OrderEvent[]> = {
    COMPLETED: [mk(4, 'RESTAURANT_ACCEPTED', 'ACCEPTED'), mk(5, 'PREPARING', 'PREPARING'), mk(6, 'READY_FOR_PICKUP', 'READY_FOR_PICKUP'), mk(7, 'PICKED_UP', 'PICKED_UP'), mk(8, 'COMPLETED', 'COMPLETED', { actor: 'system' })],
    PICKED_UP: [mk(4, 'RESTAURANT_ACCEPTED', 'ACCEPTED'), mk(5, 'PREPARING', 'PREPARING'), mk(6, 'READY_FOR_PICKUP', 'READY_FOR_PICKUP'), mk(7, 'PICKED_UP', 'PICKED_UP')],
    PREPARING: [mk(4, 'RESTAURANT_ACCEPTED', 'ACCEPTED'), mk(5, 'PREPARING', 'PREPARING')],
    READY_FOR_PICKUP: [mk(4, 'RESTAURANT_ACCEPTED', 'ACCEPTED'), mk(5, 'PREPARING', 'PREPARING'), mk(6, 'READY_FOR_PICKUP', 'READY_FOR_PICKUP')],
    CANCELLED: [mk(4, 'RESTAURANT_ACCEPTED', 'ACCEPTED'), mk(5, 'CANCELLED', 'CANCELLED', { reasonKey: 'restaurant_unavailable', paymentStatus: 'REFUND_PENDING' }), mk(6, 'REFUND_UPDATED', null, { actor: 'system', paymentStatus: ps })],
    REJECTED: [mk(4, 'RESTAURANT_REJECTED', 'REJECTED', { reasonKey: 'item_unavailable', paymentStatus: 'REFUND_PENDING' })],
  }
  return path[os] ?? []
}
export function summaryOf(o: Order): OrderSummary {
  return { publicId: o.publicId, orderNumber: o.orderNumber, restaurantName: o.restaurant.name, restaurantSlug: o.restaurant.slug, restaurantImage: null, restaurantTimezone: o.restaurant.timezone, createdAt: o.createdAt, pickupAt: o.pickup.requestedAt, itemCount: o.items.reduce((a, i) => a + i.quantity, 0), itemPreview: o.items.map((i) => `${i.itemName} × ${i.quantity}`).join(' · '), currency: o.pricing.currency, totalMinor: o.pricing.totalMinor, orderStatus: o.orderStatus, paymentStatus: o.paymentStatus, reorderEligible: groupOf(o.orderStatus) !== 'ongoing' && o.paymentStatus !== 'PAYMENT_PENDING' }
}

export class MockOrderRepository implements OrderRepository {
  async listSummaries(customerId: string, q: OrderListQuery = {}): Promise<OrderPage> {
    await wait()
    if (failing()) throw new Error('orders_load_failed')
    return pageSummaries(load<Order>(ORDERS_KEY).filter((o) => o.customerId === customerId).map(summaryOf), q)
  }
  /** Development: seeds a varied order history (statuses, currencies, zones, Unicode, refunds) for the signed-in customer. */
  async seedDemoHistory(customerId: string, n = 12): Promise<number> {
    const list = load<Order>(ORDERS_KEY)
    if (list.some((o) => o.customerId === customerId && o.orderNumber.startsWith('FOTG-SEED'))) return 0
    const now = Date.now()
    const F: Array<[string, string, string, string, string, string, number, string, OrderStatus, OrderPaymentStatus, number | null]> = [
      ['burger-hub', 'burger-hub', 'Burger Hub', 'Sector 62, Noida, Uttar Pradesh 201309, India', 'IN', 'Asia/Kolkata', 25000, 'INR', 'COMPLETED', 'PAID', null],
      ['burger-hub', 'burger-hub', 'Burger Hub', 'Sector 62, Noida, Uttar Pradesh 201309, India', 'IN', 'Asia/Kolkata', 32000, 'INR', 'PREPARING', 'PAID', null],
      ['kettleman-diner', 'route-5-diner', 'Route 5 Diner', '33400 Bernard Dr, Kettleman City, CA 93239, USA', 'US', 'America/Los_Angeles', 1899, 'USD', 'PICKED_UP', 'PAID', null],
      ['brasserie-beaune', 'brasserie-beaunoise', 'Brasserie Beaunoise', '3 Place Carnot, 21200 Beaune, France', 'FR', 'Europe/Paris', 1200, 'EUR', 'CANCELLED', 'REFUNDED', 1200],
      ['ippudo-shizuoka', 'ippudo-shizuoka', '一風堂 静岡店', '静岡県静岡市葵区紺屋町6-7, Japan', 'JP', 'Asia/Tokyo', 980, 'JPY', 'REJECTED', 'REFUND_PENDING', null],
      ['burger-hub', 'burger-hub', 'Burger Hub', 'Sector 62, Noida, Uttar Pradesh 201309, India', 'IN', 'Asia/Kolkata', 64000, 'INR', 'CANCELLED', 'PARTIALLY_REFUNDED', 32000],
      ['grapevine-burgers', 'grapevine-burgers', 'Grapevine Burgers', '5602 Dennis McCarthy Dr, Lebec, CA 93243, USA', 'US', 'America/Los_Angeles', 1499, 'USD', 'READY_FOR_PICKUP', 'PAID', null],
      ['burger-hub', 'burger-hub', 'Burger Hub', 'Sector 62, Noida, Uttar Pradesh 201309, India', 'IN', 'Asia/Kolkata', 25000, 'INR', 'COMPLETED', 'PAID', null],
    ]
    const names = ['Classic Burger', 'Truck Stop Breakfast', 'Œufs en meurette', '白丸元味', 'Spicy Paneer Wrap']
    let made = 0
    for (let i = 0; i < n; i++) {
      const [rid, slug, name, addr, cc, tz, unit, cur, os, ps, refunded] = F[i % F.length]
      const created = new Date(now - (i + 1) * 36 * 3600000).toISOString(); const pick = new Date(now - (i + 1) * 36 * 3600000 + 45 * 60000).toISOString()
      const publicId = ulidLike() + i; const qty = 1 + (i % 2); const total = unit * qty
      const o: Order = {
        publicId, orderNumber: `FOTG-SEED-${String(i + 1).padStart(4, '0')}`, customerId,
        restaurant: { id: rid, slug, name, formattedAddress: addr, countryCode: cc, timezone: tz, lat: null, lng: null, contact: null, pickupInstructions: null, pickupLocation: 'Counter pickup' },
        items: [{ lineId: 'l1', menuItemId: 'classic-burger', itemName: names[i % names.length], image: '', variants: [{ groupName: 'Size', optionName: 'Regular', priceAdjustmentMinor: 0 }], modifiers: [], specialInstructions: i % 3 === 0 ? 'No onion' : '', quantity: qty, unitPriceMinor: unit, lineTotalMinor: total }],
        pricing: { currency: cur, subtotalMinor: total, discountMinor: 0, promoCode: null, taxes: [], fees: [], totalMinor: total },
        orderStatus: os, paymentStatus: ps,
        payment: { status: ps, methodType: cur === 'INR' ? 'upi' : 'card', methodLabel: cur === 'INR' ? 'UPI' : 'Credit / debit card', providerDisplayName: cur === 'INR' ? 'Razorpay (development sandbox)' : 'Payment provider (development sandbox)', reference: `pay_dev_seed${i}`, paidAmountMinor: total, refundedAmountMinor: refunded === null ? null : Math.min(refunded, total), currency: cur, maskedDetails: cur === 'INR' ? null : 'Card ending in 4242' },
        pickup: { mode: 'scheduled', requestedAt: pick, estimatedReadyTime: pick, restaurantTimezone: tz, methodType: 'counter', methodLabel: 'Counter pickup', instructions: null, estimatedCustomerArrival: null },
        pickupCodeReference: `pv_seed${i}`, pickupVerificationStatus: COMPLETED_LIKE.has(os) ? 'VERIFIED' : os === 'READY_FOR_PICKUP' ? 'READY' : 'NOT_READY', etaReadyAt: pick, delayed: false, delayReasonKey: null,
        rejectionReasonKey: os === 'REJECTED' ? 'item_unavailable' : null, cancellationReasonKey: os === 'CANCELLED' ? (i % 2 ? 'restaurant_unavailable' : 'other') : null, lastEventSequence: 3,
        paymentAttemptId: `pay_dev_seed${i}`, checkoutReference: `ck-seed${i}`, journey: null, orderNote: '',
        events: [{ eventId: `${publicId}-1`, sequence: 1, type: 'ORDER_CREATED', status: 'PAYMENT_PENDING', at: created, actor: 'system' }, { eventId: `${publicId}-2`, sequence: 2, type: 'PAYMENT_VERIFIED', status: null, paymentStatus: 'PAID', at: created, actor: 'system' }, { eventId: `${publicId}-3`, sequence: 3, type: 'ORDER_CONFIRMED', status: 'CONFIRMED', at: created, actor: 'system' }, ...seedTail(publicId, os, ps, created)],
        createdAt: created, updatedAt: created,
      }
      o.lastEventSequence = o.events.length
      list.push(o); made++
    }
    save(ORDERS_KEY, list); return made
  }
  async createFromPayment(input: CreateOrderInput): Promise<Order> {
    await wait()
    const existing = load<Order>(ORDERS_KEY).find((o) => o.paymentAttemptId === input.paymentAttemptId)
    if (existing) return existing // idempotent: a repeated handoff never creates a second order
    const now = new Date().toISOString()
    const publicId = ulidLike()
    const pv: PickupVerification = { reference: `pv_${hex(6)}`, orderPublicId: publicId, code: code(6), qrToken: `pv_dev_${hex(16)}`, status: 'VERIFICATION_AVAILABLE', activatedAt: now, expiresAt: null }
    const order: Order = {
      publicId, orderNumber: orderNumber(), customerId: input.customerId, restaurant: input.restaurant, items: input.items, pricing: input.pricing,
      orderStatus: input.payment.status === 'PAID' ? 'CONFIRMED' : 'PAYMENT_PENDING', paymentStatus: input.payment.status, payment: input.payment, pickup: input.pickup,
      pickupCodeReference: pv.reference, pickupVerificationStatus: 'NOT_READY', etaReadyAt: input.pickup.estimatedReadyTime, delayed: false, delayReasonKey: null, rejectionReasonKey: null, cancellationReasonKey: null, lastEventSequence: 3,
      paymentAttemptId: input.paymentAttemptId, checkoutReference: input.checkoutReference, journey: input.journey, orderNote: input.orderNote,
      events: [
        { eventId: `${publicId}-1`, sequence: 1, type: 'ORDER_CREATED', status: 'PAYMENT_PENDING', at: now, actor: 'system', note: 'development order created from a verified mock payment' },
        { eventId: `${publicId}-2`, sequence: 2, type: 'PAYMENT_VERIFIED', status: null, paymentStatus: input.payment.status, at: now, actor: 'system', note: 'development verification' },
        { eventId: `${publicId}-3`, sequence: 3, type: 'ORDER_CONFIRMED', status: input.payment.status === 'PAID' ? 'CONFIRMED' : 'PAYMENT_PENDING', at: now, actor: 'system' },
      ],
      createdAt: now, updatedAt: now,
    }
    const list = load<Order>(ORDERS_KEY); list.push(order); save(ORDERS_KEY, list)
    const pvs = load<PickupVerification>(PV_KEY); pvs.push(pv); save(PV_KEY, pvs)
    return order
  }
  async getByOrderNumber(n: string, customerId: string): Promise<Order | null> {
    await wait()
    if (failing()) throw new Error('order_load_failed')
    const fx = fixture(n, customerId); if (fx) return fx
    const o = load<Order>(ORDERS_KEY).find((x) => x.orderNumber === n) ?? null
    // Ownership check — a development stand-in for the server-side authorization (CF-165). Another customer's order is "not found".
    return o && o.customerId === customerId ? o : null
  }
  async findByPaymentAttempt(id: string) { return load<Order>(ORDERS_KEY).find((o) => o.paymentAttemptId === id) ?? null }
  async applyEvents(orderNumber: string, events: OrderEvent[]): Promise<Order | null> {
    const list = load<Order>(ORDERS_KEY); const i = list.findIndex((o) => o.orderNumber === orderNumber)
    if (i < 0) { const fx = fixtureCache.get(orderNumber); if (!fx) return null; let o = fx; for (const e of events) o = reduceOrder(o, e).order; fixtureCache.set(orderNumber, o); return o }
    let o = list[i]; for (const e of events) o = reduceOrder(o, e).order
    list[i] = o; save(ORDERS_KEY, list); return o
  }
  async listForCustomer(customerId: string) { return load<Order>(ORDERS_KEY).filter((o) => o.customerId === customerId) }
}

export class MockPickupVerificationRepository implements PickupVerificationRepository {
  async getForOrder(order: Order): Promise<PickupVerification | null> {
    await wait()
    if (order.orderNumber.startsWith('FOTG-DEMO-')) return order.paymentStatus === 'PAID' ? { reference: order.pickupCodeReference, orderPublicId: order.publicId, code: 'DEMO42', qrToken: 'pv_dev_demo_fixture_token', status: 'VERIFICATION_AVAILABLE', activatedAt: order.createdAt, expiresAt: null } : null
    return load<PickupVerification>(PV_KEY).find((p) => p.orderPublicId === order.publicId) ?? null
  }
}

export class MockReceiptRepository implements ReceiptRepository {
  async getReceipt(order: Order, customerName: string | null): Promise<Receipt> {
    await wait()
    return { orderNumber: order.orderNumber, orderDate: order.createdAt, restaurantName: order.restaurant.name, restaurantAddress: order.restaurant.formattedAddress, customerName, items: order.items, pricing: order.pricing, paymentMethodLabel: order.payment.methodLabel, paymentReference: order.payment.reference, paymentStatus: order.paymentStatus, pickup: order.pickup, kind: 'ORDER_RECEIPT' }
  }
}

/** Development fixtures for states that the mock payment flow does not naturally produce. */
const fixtureCache = new Map<string, Order>()
function fixture(n: string, customerId: string): Order | null {
  if (n !== 'FOTG-DEMO-PEND' && n !== 'FOTG-DEMO-CANC') return null
  const cached = fixtureCache.get(n); if (cached) return cached
  const now = new Date(); const at = new Date(now.getTime() + 45 * 60000).toISOString()
  const pending = n === 'FOTG-DEMO-PEND'
  const o: Order = {
    publicId: 'DEMO' + n.slice(-4), orderNumber: n, customerId,
    restaurant: { id: 'burger-hub', slug: 'burger-hub', name: 'Burger Hub', formattedAddress: 'Sector 62, Noida, Uttar Pradesh 201309, India', countryCode: 'IN', timezone: 'Asia/Kolkata', lat: 28.6285, lng: 77.3652, contact: null, pickupInstructions: 'Show your pickup code at the counter.', pickupLocation: 'Pickup counter' },
    items: [{ lineId: 'l1', menuItemId: 'classic-burger', itemName: 'Classic Burger', image: '', variants: [{ groupName: 'Size', optionName: 'Regular', priceAdjustmentMinor: 0 }], modifiers: [], specialInstructions: '', quantity: 1, unitPriceMinor: 25000, lineTotalMinor: 25000 }],
    pricing: { currency: 'INR', subtotalMinor: 25000, discountMinor: 0, promoCode: null, taxes: [], fees: [], totalMinor: 25000 },
    orderStatus: pending ? 'PAYMENT_PENDING' : 'CANCELLED', paymentStatus: pending ? 'PAYMENT_PENDING' : 'REFUND_PENDING',
    payment: { status: pending ? 'PAYMENT_PENDING' : 'REFUND_PENDING', methodType: 'upi', methodLabel: 'UPI', providerDisplayName: 'Razorpay (development sandbox)', reference: 'pay_dev_demo', paidAmountMinor: pending ? 0 : 25000, currency: 'INR', maskedDetails: null },
    pickup: { mode: 'asap', requestedAt: at, estimatedReadyTime: at, restaurantTimezone: 'Asia/Kolkata', methodType: 'counter', methodLabel: 'Counter pickup', instructions: null },
    pickupCodeReference: 'pv_demo', pickupVerificationStatus: pending ? 'NOT_READY' : 'INVALID', etaReadyAt: at, delayed: false, delayReasonKey: null, rejectionReasonKey: null, cancellationReasonKey: pending ? null : 'restaurant_unavailable', lastEventSequence: pending ? 1 : 3,
    paymentAttemptId: 'pay_dev_demo', checkoutReference: 'ck-demo', journey: null, orderNote: '',
    events: [{ eventId: 'evt_demo1', sequence: 1, type: 'ORDER_CREATED' as const, status: 'PAYMENT_PENDING' as const, at: now.toISOString(), actor: 'system' as const }, ...(pending ? [] : [{ eventId: 'evt_demo2', sequence: 2, type: 'RESTAURANT_ACCEPTED' as const, status: 'ACCEPTED' as const, at: now.toISOString(), actor: 'restaurant' as const }, { eventId: 'evt_demo3', sequence: 3, type: 'CANCELLED' as const, status: 'CANCELLED' as const, paymentStatus: 'REFUND_PENDING' as const, reasonKey: 'restaurant_unavailable', at: now.toISOString(), actor: 'restaurant' as const, note: 'development fixture' }])],
    createdAt: now.toISOString(), updatedAt: now.toISOString(),
  }
  fixtureCache.set(n, o); return o
}

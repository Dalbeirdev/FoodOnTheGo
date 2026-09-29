/**
 * Development order stack (Module 13). Orders live in sessionStorage so the confirmation survives a refresh; nothing here
 * is a real backend order. Controls: sessionStorage fotg.mock.fail contains "order" → loading fails (retry recovers).
 * Fixture references (development only): FOTG-DEMO-PEND (payment pending), FOTG-DEMO-CANC (cancelled).
 */
import type { CreateOrderInput, Order, OrderRepository, PickupVerification, PickupVerificationRepository, Receipt, ReceiptRepository } from '../repositories'

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

export class MockOrderRepository implements OrderRepository {
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
      pickupCodeReference: pv.reference, paymentAttemptId: input.paymentAttemptId, checkoutReference: input.checkoutReference, journey: input.journey, orderNote: input.orderNote,
      events: [
        { eventId: `evt_${hex(4)}`, status: 'ORDER_CREATED', at: now, actor: 'system', note: 'development order created from a verified mock payment' },
        { eventId: `evt_${hex(4)}`, status: 'PAYMENT_VERIFIED', at: now, actor: 'system', note: 'development verification' },
        { eventId: `evt_${hex(4)}`, status: 'ORDER_CONFIRMED', at: now, actor: 'system' },
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
function fixture(n: string, customerId: string): Order | null {
  if (n !== 'FOTG-DEMO-PEND' && n !== 'FOTG-DEMO-CANC') return null
  const now = new Date(); const at = new Date(now.getTime() + 45 * 60000).toISOString()
  const pending = n === 'FOTG-DEMO-PEND'
  return {
    publicId: 'DEMO' + n.slice(-4), orderNumber: n, customerId,
    restaurant: { id: 'burger-hub', slug: 'burger-hub', name: 'Burger Hub', formattedAddress: 'Sector 62, Noida, Uttar Pradesh 201309, India', countryCode: 'IN', timezone: 'Asia/Kolkata', lat: 28.6285, lng: 77.3652, contact: null, pickupInstructions: 'Show your pickup code at the counter.', pickupLocation: 'Pickup counter' },
    items: [{ lineId: 'l1', menuItemId: 'classic-burger', itemName: 'Classic Burger', image: '', variants: [{ groupName: 'Size', optionName: 'Regular', priceAdjustmentMinor: 0 }], modifiers: [], specialInstructions: '', quantity: 1, unitPriceMinor: 25000, lineTotalMinor: 25000 }],
    pricing: { currency: 'INR', subtotalMinor: 25000, discountMinor: 0, promoCode: null, taxes: [], fees: [], totalMinor: 25000 },
    orderStatus: pending ? 'PAYMENT_PENDING' : 'CANCELLED', paymentStatus: pending ? 'PAYMENT_PENDING' : 'REFUND_PENDING',
    payment: { status: pending ? 'PAYMENT_PENDING' : 'REFUND_PENDING', methodType: 'upi', methodLabel: 'UPI', providerDisplayName: 'Razorpay (development sandbox)', reference: 'pay_dev_demo', paidAmountMinor: pending ? 0 : 25000, currency: 'INR', maskedDetails: null },
    pickup: { mode: 'asap', requestedAt: at, estimatedReadyTime: at, restaurantTimezone: 'Asia/Kolkata', methodType: 'counter', methodLabel: 'Counter pickup', instructions: null },
    pickupCodeReference: 'pv_demo', paymentAttemptId: 'pay_dev_demo', checkoutReference: 'ck-demo', journey: null, orderNote: '',
    events: [{ eventId: 'evt_demo1', status: 'ORDER_CREATED', at: now.toISOString(), actor: 'system' }, ...(pending ? [] : [{ eventId: 'evt_demo2', status: 'CANCELLED', at: now.toISOString(), actor: 'restaurant' as const, note: 'development fixture' }])],
    createdAt: now.toISOString(), updatedAt: now.toISOString(),
  }
}

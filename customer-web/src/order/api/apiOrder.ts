import { ApiError, api } from '../../api/client'
import { echoFor, watchTransport, type TransportState } from '../../realtime/echo'
import type { Order, OrderEvent, OrderEventType, OrderListQuery, OrderPage, OrderPaymentStatus, OrderRepository, OrderStatus, OrderSummary, PickupVerification, PickupVerificationRepository, CreateOrderInput } from '../repositories'
import type { OrderTrackingService, TrackingListener } from '../tracking'

/**
 * The backend's orders (Module 31), mapped onto the order model the pages already render.
 *
 * What this file never does: create an order, set a status, price anything or invent a pickup code. A paid order is
 * created by the backend from the VERIFIED payment; the browser learns its number from the payment (fulfillment.order_number)
 * and reads it here. Everything shown comes from the order as the backend copied it at creation — never from the live menu.
 *
 * The statuses of the shared model are a superset of the backend's: WAITING_FOR_RESTAURANT is shown as
 * AWAITING_RESTAURANT_ACCEPTANCE; refund states come from the order's refund_status, kept apart from its status.
 */

type MoneyDto = { amount: number; currency: string }
export type BackendOrderStatus = 'WAITING_FOR_RESTAURANT' | 'ACCEPTED' | 'PREPARING' | 'READY_FOR_PICKUP' | 'PICKED_UP' | 'COMPLETED' | 'REJECTED' | 'CANCELLED'
export type BackendRefundStatus = 'NONE' | 'REQUIRED' | 'PENDING' | 'PARTIAL' | 'REFUNDED' | 'FAILED'
type ItemDto = { id: string; item_id: string | null; slug: string | null; name: string; quantity: number; unit_price: MoneyDto; line_subtotal: MoneyDto; special_instructions: string | null; options: Array<{ kind: 'VARIANT' | 'MODIFIER'; group: string; name: string; price_adjustment: MoneyDto }> }
type PickupDto = { mode: 'ASAP' | 'SCHEDULED'; method: string; pickup_at: string; pickup_at_local: string | null; slot: { starts_at: string | null; ends_at: string | null }; timezone: string; estimated_prep_minutes: number | null; traveler_arrival_at: string | null }
type TimelineDto = { at: string | null; type: string; from: string | null; to: string | null; actor: string; detail: Record<string, unknown> }
export type OrderSummaryDto = {
  id: string; order_number: string; status: BackendOrderStatus; payment_state: 'PAID' | 'PAYMENT_NOT_REQUIRED'; refund_status: BackendRefundStatus
  restaurant: { id: string | null; slug: string | null; name: string; branch_label: string | null }
  item_count: number; total: MoneyDto; pickup: PickupDto; placed_at: string | null; updated_at: string | null
}
export type OrderDto = OrderSummaryDto & {
  restaurant: OrderSummaryDto['restaurant'] & { formatted_address: string | null; phone: string | null; timezone: string; pickup_instructions: string | null }
  items: ItemDto[]
  pricing: { currency: string; subtotal: MoneyDto; discount: MoneyDto; tax: MoneyDto; tax_included: MoneyDto; fee: MoneyDto; total: MoneyDto }
  promotion: { code: string; name: string | null; discount: MoneyDto } | null
  taxes: Array<{ code: string; label: string; amount: MoneyDto }>
  fees: Array<{ code: string; label: string; amount: MoneyDto }>
  payment: { required: boolean; id: string | null; provider: string | null; method: { type: string; summary: Record<string, string | boolean | null> } | null; verified_at: string | null }
  customer_note: string | null
  journey: { id: string; estimated_arrival_at: string | null; detour: unknown } | null
  rejection: { reason: string; message: string } | null
  cancellation: { message: string } | null
  pickup_credential: { code: string; qr_payload: string; status: string } | null
  timeline: TimelineDto[]
  next_action: string
  times: Record<string, string | null>
  version: number
}
type PageDto<T> = { data: T[]; meta: { current_page: number; last_page: number; total: number; per_page: number } }

export const STATUS_FROM_API: Record<BackendOrderStatus, OrderStatus> = {
  WAITING_FOR_RESTAURANT: 'AWAITING_RESTAURANT_ACCEPTANCE', ACCEPTED: 'ACCEPTED', PREPARING: 'PREPARING', READY_FOR_PICKUP: 'READY_FOR_PICKUP',
  PICKED_UP: 'PICKED_UP', COMPLETED: 'COMPLETED', REJECTED: 'REJECTED', CANCELLED: 'CANCELLED',
}
export function paymentStatusFromApi(refund: BackendRefundStatus): OrderPaymentStatus {
  return refund === 'REQUIRED' || refund === 'PENDING' || refund === 'FAILED' ? 'REFUND_PENDING' : refund === 'PARTIAL' ? 'PARTIALLY_REFUNDED' : refund === 'REFUNDED' ? 'REFUNDED' : 'PAID'
}
const REASON: Record<string, string> = { ITEM_UNAVAILABLE: 'item_unavailable', KITCHEN_CAPACITY: 'capacity', CLOSING: 'restaurant_unavailable', TECHNICAL_ISSUE: 'other', OTHER: 'other' }
const EVENT: Record<string, OrderEventType> = {
  ORDER_CREATED: 'ORDER_CREATED', WAITING_FOR_RESTAURANT: 'SENT_TO_RESTAURANT', RESTAURANT_ACCEPTED: 'RESTAURANT_ACCEPTED', RESTAURANT_REJECTED: 'RESTAURANT_REJECTED',
  PREPARATION_STARTED: 'PREPARING', READY_FOR_PICKUP: 'READY_FOR_PICKUP', CUSTOMER_PICKED_UP: 'PICKED_UP', ORDER_COMPLETED: 'COMPLETED', ORDER_CANCELLED: 'CANCELLED', REFUND_REQUIRED: 'REFUND_UPDATED',
}
const METHOD_LABEL: Record<string, string> = { COUNTER: 'Counter pickup', CURBSIDE: 'Curbside pickup', DRIVE_THROUGH: 'Drive-through', LOCKER: 'Locker pickup' }
const PAY_LABEL: Record<string, string> = { UPI: 'UPI', CARD: 'Card', NET_BANKING: 'Net banking', WALLET: 'Wallet', EMI: 'EMI', PAY_LATER: 'Pay later' }

/** The backend's timeline as the pages' event stream: one event per line, numbered in order, the state after each line attached. */
export function eventsFromApi(d: OrderDto): OrderEvent[] {
  const out: OrderEvent[] = []
  let seq = 0
  if (d.payment.required && d.payment.verified_at) {
    out.push({ eventId: `${d.id}-payment`, sequence: ++seq, type: 'PAYMENT_VERIFIED', status: null, paymentStatus: 'PAID', at: d.payment.verified_at, actor: 'system' })
  }
  for (const line of d.timeline) {
    const type = EVENT[line.type]
    if (!type) continue
    const to = line.to as BackendOrderStatus | null
    const reason = typeof line.detail?.reason === 'string' ? REASON[line.detail.reason as string] ?? 'other' : null
    out.push({
      eventId: `${d.id}-${seq + 1}`, sequence: ++seq, type,
      status: type === 'REFUND_UPDATED' ? null : to ? STATUS_FROM_API[to] ?? null : null,
      paymentStatus: type === 'REFUND_UPDATED' ? 'REFUND_PENDING' : null,
      at: line.at ?? d.placed_at ?? new Date().toISOString(),
      actor: line.actor === 'RESTAURANT' ? 'restaurant' : line.actor === 'YOU' ? 'customer' : 'system',
      reasonKey: type === 'RESTAURANT_REJECTED' || type === 'CANCELLED' ? reason ?? 'other' : null,
    })
  }
  return out
}

/** The pages' pickup verification view of the order's credential. Opaque values; their presence proves nothing (Module 32 verifies). */
export function verificationFromApi(d: OrderDto): PickupVerification | null {
  const c = d.pickup_credential
  if (!c) return null
  return { reference: `pc-${d.id}`, orderPublicId: d.id, code: c.code, qrToken: c.qr_payload, status: c.status === 'ACTIVE' ? (d.status === 'READY_FOR_PICKUP' ? 'VERIFICATION_AVAILABLE' : 'READY') : c.status === 'CONSUMED' ? 'VERIFIED' : 'INVALID', activatedAt: d.placed_at, expiresAt: null }
}

export function toOrder(d: OrderDto, customerId: string): Order {
  const status = STATUS_FROM_API[d.status]
  const paymentStatus = paymentStatusFromApi(d.refund_status)
  const events = eventsFromApi(d)
  const method = d.payment.method
  const readyAt = d.times.ready_at ?? (d.pickup.estimated_prep_minutes !== null && d.placed_at ? new Date(new Date(d.placed_at).getTime() + d.pickup.estimated_prep_minutes * 60000).toISOString() : d.pickup.pickup_at)
  return {
    publicId: d.id, orderNumber: d.order_number, customerId,
    restaurant: { id: d.restaurant.id ?? '', slug: d.restaurant.slug ?? '', name: d.restaurant.name, formattedAddress: d.restaurant.formatted_address ?? '', countryCode: '', timezone: d.restaurant.timezone, lat: null, lng: null, contact: d.restaurant.phone, pickupInstructions: d.restaurant.pickup_instructions, pickupLocation: METHOD_LABEL[d.pickup.method] ?? d.pickup.method },
    items: d.items.map((i) => ({
      lineId: i.id, menuItemId: i.item_id ?? i.slug ?? i.id, itemName: i.name, image: '',
      variants: i.options.filter((o) => o.kind === 'VARIANT').map((o) => ({ groupName: o.group, optionName: o.name, priceAdjustmentMinor: o.price_adjustment.amount })),
      modifiers: i.options.filter((o) => o.kind === 'MODIFIER').map((o) => ({ groupName: o.group, optionName: o.name, priceAdjustmentMinor: o.price_adjustment.amount })),
      specialInstructions: i.special_instructions ?? '', quantity: i.quantity, unitPriceMinor: i.unit_price.amount, lineTotalMinor: i.line_subtotal.amount,
    })),
    pricing: { currency: d.pricing.currency, subtotalMinor: d.pricing.subtotal.amount, discountMinor: d.pricing.discount.amount, promoCode: d.promotion?.code ?? null, taxes: d.taxes.map((l) => ({ id: l.code, label: l.label, amountMinor: l.amount.amount })), fees: d.fees.map((l) => ({ id: l.code, label: l.label, amountMinor: l.amount.amount })), totalMinor: d.pricing.total.amount },
    orderStatus: status, paymentStatus,
    payment: {
      status: paymentStatus, methodType: method?.type.toLowerCase() ?? (d.payment.required ? 'unknown' : 'none'), methodLabel: method ? PAY_LABEL[method.type] ?? method.type : d.payment.required ? '—' : 'No payment required',
      providerDisplayName: d.payment.provider === 'razorpay' ? 'Razorpay' : d.payment.provider === 'fake' ? 'Development payment provider' : d.payment.provider ?? '—',
      reference: d.payment.id ?? '—', paidAmountMinor: d.payment.required ? d.pricing.total.amount : 0, refundedAmountMinor: d.refund_status === 'REFUNDED' ? d.pricing.total.amount : null, currency: d.pricing.currency,
      maskedDetails: method && typeof method.summary.last4 === 'string' ? `•••• ${method.summary.last4}` : method && typeof method.summary.vpa === 'string' ? method.summary.vpa : null,
    },
    pickup: { mode: d.pickup.mode === 'ASAP' ? 'asap' : 'scheduled', requestedAt: d.pickup.pickup_at, estimatedReadyTime: readyAt ?? d.pickup.pickup_at, restaurantTimezone: d.pickup.timezone, methodType: d.pickup.method.toLowerCase(), methodLabel: METHOD_LABEL[d.pickup.method] ?? d.pickup.method, instructions: d.restaurant.pickup_instructions, estimatedCustomerArrival: d.pickup.traveler_arrival_at ?? d.journey?.estimated_arrival_at ?? null },
    pickupCodeReference: d.pickup_credential ? `pc-${d.id}` : '',
    pickupVerificationStatus: d.pickup_credential === null ? (status === 'REJECTED' || status === 'CANCELLED' ? 'INVALID' : status === 'PICKED_UP' || status === 'COMPLETED' ? 'VERIFIED' : 'NOT_READY') : status === 'READY_FOR_PICKUP' ? 'VERIFICATION_AVAILABLE' : 'READY',
    etaReadyAt: readyAt ?? null, delayed: false, delayReasonKey: null,
    rejectionReasonKey: d.rejection ? REASON[d.rejection.reason] ?? 'other' : null, cancellationReasonKey: d.cancellation ? 'other' : null,
    lastEventSequence: events.length, version: d.version, paymentAttemptId: d.payment.id ?? '', checkoutReference: '',
    journey: d.journey ? { journeyId: d.journey.id, originName: '', destinationName: '' } : null,
    orderNote: d.customer_note ?? '', events, createdAt: d.placed_at ?? new Date().toISOString(), updatedAt: d.updated_at ?? d.placed_at ?? new Date().toISOString(),
  }
}

export function toSummary(d: OrderSummaryDto): OrderSummary {
  const status = STATUS_FROM_API[d.status]
  return {
    publicId: d.id, orderNumber: d.order_number, restaurantName: d.restaurant.name, restaurantSlug: d.restaurant.slug ?? '', restaurantImage: null, restaurantTimezone: d.pickup.timezone,
    createdAt: d.placed_at ?? '', pickupAt: d.pickup.pickup_at, itemCount: d.item_count, itemPreview: '', currency: d.total.currency, totalMinor: d.total.amount,
    orderStatus: status, paymentStatus: paymentStatusFromApi(d.refund_status), reorderEligible: status === 'COMPLETED' || status === 'PICKED_UP',
  }
}

const GROUP_FILTER: Record<string, Record<string, string>> = { ongoing: { 'filter[active]': '1' }, completed: { 'filter[active]': '0' }, cancelled: { 'filter[active]': '0' }, all: {} }

/** The pages keep the last orders read, so a verification or receipt lookup needs no second request. */
const seen = new Map<string, OrderDto>()

export class ApiOrderRepository implements OrderRepository {
  async listSummaries(_customerId: string, q: OrderListQuery = {}): Promise<OrderPage> {
    const page = q.cursor ? Math.max(1, Number.parseInt(q.cursor, 10) || 1) : 1
    const size = Math.max(1, Math.min(50, q.limit ?? 5))
    const query: Record<string, string | number> = { 'page[number]': page, 'page[size]': size, sort: q.sort === 'oldest' ? 'placed_at' : '-placed_at', ...GROUP_FILTER[q.group ?? 'all'] }
    const res = await api<PageDto<OrderSummaryDto>>('/customer/orders', { query })
    let items = res.data.map(toSummary)
    // The backend filters by active / finished; the finer groups and the text search are narrowed here on the page read.
    if (q.group === 'completed') items = items.filter((o) => o.orderStatus === 'COMPLETED' || o.orderStatus === 'PICKED_UP')
    if (q.group === 'cancelled') items = items.filter((o) => o.orderStatus === 'CANCELLED' || o.orderStatus === 'REJECTED')
    if (q.query?.trim()) { const n = q.query.trim().toLowerCase(); items = items.filter((o) => o.orderNumber.toLowerCase().includes(n) || o.restaurantName.toLowerCase().includes(n)) }
    return { items, nextCursor: res.meta.current_page < res.meta.last_page ? String(res.meta.current_page + 1) : null, total: res.meta.total }
  }

  /** Not a thing the browser does against the backend: the order exists when the payment is verified. The one it became is returned. */
  async createFromPayment(input: CreateOrderInput): Promise<Order> {
    const existing = await this.findByPaymentAttempt(input.paymentAttemptId)
    if (existing) return existing
    throw new Error('Orders are created by the server from the verified payment; none exists for this payment yet.')
  }

  async getByOrderNumber(orderNumber: string, customerId: string): Promise<Order | null> {
    try {
      const { order } = await api<{ order: OrderDto }>(`/customer/orders/${encodeURIComponent(orderNumber)}`)
      seen.set(order.order_number, order); seen.set(order.id, order)
      return toOrder(order, customerId)
    } catch (e) {
      if (e instanceof ApiError && e.kind === 'not_found') return null
      throw e
    }
  }

  async findByPaymentAttempt(paymentAttemptId: string): Promise<Order | null> {
    try {
      const { payment } = await api<{ payment: { fulfillment: { order_number: string | null } } }>(`/payments/${encodeURIComponent(paymentAttemptId)}`)
      const number = payment.fulfillment?.order_number ?? null
      return number ? this.getByOrderNumber(number, '') : null
    } catch (e) {
      if (e instanceof ApiError && e.kind === 'not_found') return null
      throw e
    }
  }

  async listForCustomer(customerId: string): Promise<Order[]> {
    const res = await api<PageDto<OrderSummaryDto>>('/customer/orders', { query: { 'page[size]': 50 } })
    const orders = await Promise.all(res.data.map((s) => this.getByOrderNumber(s.order_number, customerId)))
    return orders.filter((o): o is Order => o !== null)
  }

  /** Events are the backend's: nothing is applied locally — the order is read again instead. */
  async applyEvents(orderNumber: string, _events: OrderEvent[]): Promise<Order | null> {
    return this.getByOrderNumber(orderNumber, '')
  }
}

export class ApiPickupVerificationRepository implements PickupVerificationRepository {
  async getForOrder(order: Order): Promise<PickupVerification | null> {
    const cached = seen.get(order.orderNumber)
    if (cached) return verificationFromApi(cached)
    try {
      const { order: d } = await api<{ order: OrderDto }>(`/customer/orders/${encodeURIComponent(order.orderNumber)}`)
      seen.set(d.order_number, d)
      return verificationFromApi(d)
    } catch { return null }
  }
}

/**
 * Live updates by polling the backend (Module 31). Module 32 brings the event stream; until then the order is read
 * again every few seconds while it is on its way, and each new timeline line is delivered as an event — the pages'
 * reducer ignores what it has seen. Never invents an event, never advances anything.
 */
export class ApiOrderTrackingService implements OrderTrackingService {
  private readonly orders: ApiOrderRepository
  private readonly intervalMs: number
  constructor(orders: ApiOrderRepository, intervalMs = 8000) { this.orders = orders; this.intervalMs = intervalMs }

  /**
   * Module 32: the private channel first (customer.order.{id} on Laravel Reverb), polling as the fallback and as the
   * resync. A notice carries the version and the event sequence: what is not newer than what the screen has is
   * ignored; a gap, a reconnection, a tab coming back or a socket that is not there makes the order be read again —
   * the only source of truth. Nothing here ever advances an order by itself.
   */
  subscribe(order: Order, listener: TrackingListener): () => void {
    let known = order.lastEventSequence
    let version = order.version ?? 0
    let stopped = false
    let inFlight = false
    let pollTimer: number | null = null
    let unwatch: (() => void) | null = null
    let channel: { stopListening: (e: string) => void } | null = null
    const echo = echoFor('customer')

    const deliver = (fresh: Order) => {
      for (const e of fresh.events) if (e.sequence > known) { known = e.sequence; listener.onEvent(e) }
      version = Math.max(version, fresh.version ?? 0)
    }
    const resync = async (why: 'poll' | 'gap' | 'resume' | 'reconnect') => {
      if (stopped || inFlight) return
      inFlight = true
      try {
        const fresh = await this.orders.getByOrderNumber(order.orderNumber, order.customerId)
        if (stopped || !fresh) return
        deliver(fresh)
        listener.onConnection('LIVE')
        if (why === 'gap') listener.onTransport?.(echo ? 'CONNECTED' : 'FALLBACK_POLLING')
      } catch { if (!stopped) listener.onConnection('STALE') } finally { inFlight = false }
    }
    const startPolling = (why: TransportState) => {
      if (pollTimer !== null || stopped) return
      listener.onTransport?.(why)
      pollTimer = window.setInterval(() => { void resync('poll') }, this.intervalMs)
    }
    const stopPolling = () => { if (pollTimer !== null) { window.clearInterval(pollTimer); pollTimer = null } }

    if (echo) {
      listener.onTransport?.('CONNECTING')
      channel = echo.private(`customer.order.${order.publicId}`).listen('.order.updated', (notice: OrderUpdatedNotice) => {
        if (stopped) return
        // Older or already known: ignore. Exactly the next line: the order is still read (the notice is not the order)
        // but only what is newer is delivered. A gap or a jump: the same read, and it fills the gap.
        if ((notice.version ?? 0) <= version && (notice.event_sequence ?? 0) <= known) return
        void resync((notice.event_sequence ?? 0) > known + 1 ? 'gap' : 'poll')
      }) as unknown as { stopListening: (e: string) => void }
      unwatch = watchTransport(echo, (t) => {
        if (stopped) return
        listener.onTransport?.(t)
        if (t === 'CONNECTED') { stopPolling(); void resync('reconnect'); listener.onConnection('LIVE') }
        else if (t === 'DISCONNECTED' || t === 'RECONNECTING') { listener.onConnection('STALE'); startPolling('FALLBACK_POLLING') }
      })
    } else {
      listener.onConnection('LIVE')
      startPolling('FALLBACK_POLLING')
    }
    // A tab that comes back, or the network: ask at once — the socket may have slept.
    const wake = () => { if (document.visibilityState === 'visible') void resync('resume') }
    document.addEventListener('visibilitychange', wake); window.addEventListener('online', wake); window.addEventListener('focus', wake)

    return () => {
      stopped = true
      stopPolling(); unwatch?.()
      document.removeEventListener('visibilitychange', wake); window.removeEventListener('online', wake); window.removeEventListener('focus', wake)
      if (channel && echo) { try { echo.leave(`customer.order.${order.publicId}`) } catch { /* already gone */ } }
    }
  }

  refresh(orderNumber: string, customerId: string): Promise<Order | null> {
    return this.orders.getByOrderNumber(orderNumber, customerId)
  }
}

/** The WebSocket notice (OpenAPI: OrderUpdatedBroadcast). A notice, not the order. */
export type OrderUpdatedNotice = { schema_version: number; order_public_id: string; order_number: string; version: number; status: string; event_type: string; event_sequence: number | null; event_id: string | null; occurred_at: string; message_key?: string }

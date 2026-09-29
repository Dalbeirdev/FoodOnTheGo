/**
 * Order tracking (Module 14) — event-driven, provider-neutral.
 *
 *  - Tracking is built from structured OrderEvents, never from one mutable text field.
 *  - `reduceOrder` is the single place where an event changes an order: duplicates (same eventId) and stale events
 *    (sequence ≤ lastEventSequence) are ignored, terminal states never regress. The backend enforces the real state
 *    machine later (CF-172); this is defensive client behaviour.
 *  - Food-ready ETA (order.etaReadyAt) and customer-arrival ETA (pickup.estimatedCustomerArrival) are separate values.
 *  - OrderTrackingService abstracts live updates (mock now; WebSocket / SSE / push / polling later). Connection state is
 *    separate from order state.
 */
import type { Order, OrderEvent, OrderEventType, OrderPaymentStatus, OrderStatus, PickupVerificationStatus } from './repositories'

export type TrackingConnection = 'LOADING' | 'LIVE' | 'STALE' | 'OFFLINE' | 'ERROR' | 'COMPLETED'
export type TrackingScenario = 'normal' | 'delay' | 'rejected' | 'cancelled' | 'payment_pending' | 'ready' | 'picked_up' | 'completed' | 'network_loss'
export const SCENARIOS: TrackingScenario[] = ['normal', 'delay', 'rejected', 'cancelled', 'payment_pending', 'ready', 'picked_up', 'completed', 'network_loss']
export const TERMINAL_ORDER: ReadonlySet<OrderStatus> = new Set(['COMPLETED', 'CANCELLED', 'REJECTED', 'REFUNDED'])

export type ReduceResult = { order: Order; applied: boolean; reason: 'applied' | 'duplicate' | 'stale' | 'terminal' }

/** Applies one event. Pure; returns the same order when the event is ignored. */
export function reduceOrder(order: Order, e: OrderEvent): ReduceResult {
  if (order.events.some((x) => x.eventId === e.eventId)) return { order, applied: false, reason: 'duplicate' }
  if (e.sequence <= order.lastEventSequence) return { order, applied: false, reason: 'stale' }
  if (TERMINAL_ORDER.has(order.orderStatus) && e.type !== 'REFUND_UPDATED') return { order, applied: false, reason: 'terminal' }
  const next: Order = { ...order, events: [...order.events, e], lastEventSequence: e.sequence, updatedAt: e.at }
  if (e.status) next.orderStatus = e.status
  if (e.paymentStatus) { next.paymentStatus = e.paymentStatus; next.payment = { ...next.payment, status: e.paymentStatus, refundedAmountMinor: e.paymentStatus === 'REFUNDED' ? next.payment.paidAmountMinor : (next.payment.refundedAmountMinor ?? null) } }
  if (e.etaReadyAt !== undefined && e.etaReadyAt !== null) next.etaReadyAt = e.etaReadyAt
  switch (e.type) {
    case 'DELAYED': next.delayed = true; next.delayReasonKey = e.reasonKey ?? 'taking_longer'; break
    case 'READY_FOR_PICKUP': next.delayed = false; next.pickupVerificationStatus = 'READY'; break
    case 'PICKUP_VERIFICATION': next.pickupVerificationStatus = 'VERIFICATION_AVAILABLE'; break
    case 'PICKED_UP': next.pickupVerificationStatus = 'VERIFIED'; break
    case 'RESTAURANT_REJECTED': next.rejectionReasonKey = e.reasonKey ?? 'other'; next.pickupVerificationStatus = 'INVALID'; break
    case 'CANCELLED': next.cancellationReasonKey = e.reasonKey ?? 'other'; next.pickupVerificationStatus = 'INVALID'; break
    default: break
  }
  return { order: next, applied: true, reason: 'applied' }
}

/** Timeline stages shown to the customer (localized in the UI, never raw enum values). */
export type TimelineStage = { key: 'placed' | 'payment' | 'accepted' | 'preparing' | 'ready' | 'picked_up' | 'completed'; state: 'done' | 'current' | 'future' | 'stopped'; at: string | null; note?: 'delayed' | 'rejected' | 'cancelled' }
export function timelineFor(o: Order): TimelineStage[] {
  const at = (types: OrderEventType[]) => o.events.filter((e) => types.includes(e.type)).map((e) => e.at).pop() ?? null
  const rank: Record<OrderStatus, number> = { PAYMENT_PENDING: 0, CONFIRMED: 1, AWAITING_RESTAURANT_ACCEPTANCE: 1, ACCEPTED: 2, PREPARING: 3, READY_FOR_PICKUP: 4, PICKUP_VERIFICATION: 4, PICKED_UP: 5, COMPLETED: 6, REJECTED: 1, CANCELLED: -1, REFUND_PENDING: -1, REFUNDED: -1 }
  const r = rank[o.orderStatus]
  const stopped = o.orderStatus === 'REJECTED' || o.orderStatus === 'CANCELLED'
  const cancelRank = o.orderStatus === 'CANCELLED' ? Math.max(1, ...o.events.filter((e) => e.status).map((e) => rank[e.status as OrderStatus] ?? 0)) : r
  const stages: Array<[TimelineStage['key'], number, string | null]> = [
    ['placed', 0, at(['ORDER_CREATED'])], ['payment', 1, at(['PAYMENT_VERIFIED'])], ['accepted', 2, at(['RESTAURANT_ACCEPTED'])], ['preparing', 3, at(['PREPARING'])],
    ['ready', 4, at(['READY_FOR_PICKUP'])], ['picked_up', 5, at(['PICKED_UP'])], ['completed', 6, at(['COMPLETED'])],
  ]
  return stages.map(([key, level, when]) => {
    const effective = stopped ? cancelRank : r
    let state: TimelineStage['state'] = level < effective || (level === effective && (level === 6 || stopped)) ? 'done' : level === effective ? 'current' : 'future'
    if (o.orderStatus === 'PAYMENT_PENDING') state = level === 0 ? 'done' : level === 1 ? 'current' : 'future'
    if (o.orderStatus === 'CONFIRMED' || o.orderStatus === 'AWAITING_RESTAURANT_ACCEPTANCE') state = level <= 1 ? 'done' : level === 2 ? 'current' : 'future'
    let note: TimelineStage['note']
    if (stopped && level === effective + 1) { state = 'stopped'; note = o.orderStatus === 'REJECTED' ? 'rejected' : 'cancelled' }
    if (stopped && level > effective + 1) state = 'future'
    if (key === 'preparing' && o.delayed && state === 'current') note = 'delayed'
    return { key, state, at: when, note }
  })
}

// ---------------------------------------------------------------------------------------------------------
// Live-update abstraction
// ---------------------------------------------------------------------------------------------------------
export type TrackingListener = { onEvent: (e: OrderEvent) => void; onConnection: (c: 'LIVE' | 'STALE') => void }
export interface OrderTrackingService {
  /** Starts delivering events for the order; returns an unsubscribe function. */
  subscribe(order: Order, listener: TrackingListener): () => void
  /** Explicit status refresh (polling fallback / manual refresh). Must be idempotent — never creates events. */
  refresh(orderNumber: string, customerId: string): Promise<Order | null>
}

/** Deterministic mock scripts. Each step is an event template; `after` is minutes added to the previous ETA when relevant. */
type Step = { type: OrderEventType; status: OrderStatus | null; paymentStatus?: OrderPaymentStatus; reasonKey?: string; etaShiftMin?: number; actor?: OrderEvent['actor']; dropConnection?: boolean }
const S = {
  sent: { type: 'SENT_TO_RESTAURANT', status: 'AWAITING_RESTAURANT_ACCEPTANCE', actor: 'system' } as Step,
  accepted: { type: 'RESTAURANT_ACCEPTED', status: 'ACCEPTED', actor: 'restaurant' } as Step,
  preparing: { type: 'PREPARING', status: 'PREPARING', actor: 'restaurant' } as Step,
  delayed: { type: 'DELAYED', status: null, actor: 'restaurant', reasonKey: 'high_demand', etaShiftMin: 15 } as Step,
  ready: { type: 'READY_FOR_PICKUP', status: 'READY_FOR_PICKUP', actor: 'restaurant' } as Step,
  verification: { type: 'PICKUP_VERIFICATION', status: 'PICKUP_VERIFICATION', actor: 'restaurant' } as Step,
  pickedUp: { type: 'PICKED_UP', status: 'PICKED_UP', actor: 'restaurant' } as Step,
  completed: { type: 'COMPLETED', status: 'COMPLETED', actor: 'system' } as Step,
}
export const SCENARIO_STEPS: Record<TrackingScenario, Step[]> = {
  normal: [S.sent, S.accepted, S.preparing, S.ready, S.verification, S.pickedUp, S.completed],
  delay: [S.sent, S.accepted, S.preparing, S.delayed, S.ready, S.verification, S.pickedUp, S.completed],
  rejected: [S.sent, { type: 'RESTAURANT_REJECTED', status: 'REJECTED', actor: 'restaurant', reasonKey: 'item_unavailable', paymentStatus: 'REFUND_PENDING' }],
  cancelled: [S.sent, S.accepted, { type: 'CANCELLED', status: 'CANCELLED', actor: 'restaurant', reasonKey: 'restaurant_unavailable', paymentStatus: 'REFUND_PENDING' }, { type: 'REFUND_UPDATED', status: null, actor: 'system', paymentStatus: 'REFUNDED' }],
  payment_pending: [],
  ready: [S.sent, S.accepted, S.preparing, S.ready],
  picked_up: [S.sent, S.accepted, S.preparing, S.ready, S.verification, S.pickedUp],
  completed: [S.sent, S.accepted, S.preparing, S.ready, S.verification, S.pickedUp, S.completed],
  network_loss: [S.sent, { ...S.accepted, dropConnection: true }, S.preparing, S.ready, S.verification, S.pickedUp, S.completed],
}

/** Builds the concrete event for a step against the current order (sequence, timestamps, ETA). */
export function eventForStep(order: Order, step: Step, now = new Date()): OrderEvent {
  const seq = order.lastEventSequence + 1
  const baseEta = order.etaReadyAt ?? order.pickup.estimatedReadyTime
  const eta = step.etaShiftMin ? new Date(new Date(baseEta).getTime() + step.etaShiftMin * 60000).toISOString() : undefined
  return { eventId: `${order.publicId}-${seq}`, sequence: seq, type: step.type, status: step.status, paymentStatus: step.paymentStatus ?? null, at: now.toISOString(), actor: step.actor ?? 'system', reasonKey: step.reasonKey ?? null, etaReadyAt: eta ?? null }
}

/** Index of the next scripted step: the first step whose event type has not been applied yet. */
export function nextStepIndex(order: Order, scenario: TrackingScenario): number {
  const steps = SCENARIO_STEPS[scenario]
  const applied = new Set(order.events.map((e) => e.type))
  const i = steps.findIndex((s) => !applied.has(s.type))
  return i
}

/** Haversine distance in metres (for the restaurant-distance hint when journey origin coordinates exist). */
export function distanceMeters(aLat: number, aLng: number, bLat: number, bLng: number): number {
  const R = 6371000; const toRad = (d: number) => (d * Math.PI) / 180
  const dLat = toRad(bLat - aLat); const dLng = toRad(bLng - aLng)
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(aLat)) * Math.cos(toRad(bLat)) * Math.sin(dLng / 2) ** 2
  return Math.round(2 * R * Math.asin(Math.sqrt(h)))
}

export const pickupVisible = (o: Order): boolean => o.paymentStatus === 'PAID' && !['REJECTED', 'CANCELLED', 'PAYMENT_PENDING'].includes(o.orderStatus)
export type { PickupVerificationStatus }

import { ApiError, api } from '../../api/client'
import { STATUS_FROM_API, toOrder, type BackendOrderStatus, type OrderDto } from '../../order/api/apiOrder'
import type { Order } from '../../order/repositories'
import type { ApiRestaurantManagementRepository } from './apiDashboard'
import type { DelayReason, OrderListFilter, OrderTab, RejectReason, RestaurantOrderRepository, VerificationResult } from '../types'

/**
 * The restaurant's orders from the backend (Module 31). The queue is the backend's; every step (accept, reject, start
 * preparing, ready) is a request the backend judges — permission, tenant, state machine and version — and the order
 * shown afterwards is the backend's answer, never a local guess.
 *
 * Not here, by design: a "picked up" step (Module 32's verified transition), a delay step and pickup verification
 * (both still Module 32 / later) — the page's buttons for them stay the development mock's and are refused plainly.
 */
const R = { context: 'restaurant' as const }
const REJECT: Record<RejectReason, string> = { item_unavailable: 'ITEM_UNAVAILABLE', kitchen_capacity: 'KITCHEN_CAPACITY', closing: 'CLOSING', unable_to_prepare: 'TECHNICAL_ISSUE', other: 'OTHER' }
const TAB_STATUSES: Record<OrderTab, BackendOrderStatus[]> = { new: ['WAITING_FOR_RESTAURANT'], preparing: ['ACCEPTED', 'PREPARING'], ready: ['READY_FOR_PICKUP'], completed: ['PICKED_UP', 'COMPLETED'], cancelled: ['REJECTED', 'CANCELLED'] }

type MoneyDto = { amount: number; currency: string }
type RowDto = {
  id: string; order_number: string; status: BackendOrderStatus; payment_state: 'PAID' | 'PAYMENT_NOT_REQUIRED'; refund_status: OrderDto['refund_status']
  location_id: string; customer: { name: string; phone: string | null }; item_count: number; total: MoneyDto; pickup: OrderDto['pickup']
  placed_at: string | null; updated_at: string | null; version: number; allowed_actions: string[]
}
type DetailDto = RowDto & {
  items: OrderDto['items']; pricing: OrderDto['pricing']; promotion: { code: string } | null; taxes: OrderDto['taxes']; fees: OrderDto['fees']; customer_note: string | null
  rejection: { reason: string; note: string | null } | null; cancellation: { reason: string | null } | null; journey: { estimated_arrival_at: string | null } | null
  timeline: OrderDto['timeline']; times: OrderDto['times']
}
type PageDto = { data: RowDto[]; meta: { total: number; current_page: number; last_page: number }; counts: { waiting_for_restaurant: number; accepted: number; preparing: number; ready_for_pickup: number } }

export class RestaurantOrderApiError extends Error {
  code: 'version_conflict' | 'invalid_transition' | 'forbidden' | 'not_found' | 'not_available' | 'error'
  currentVersion: number | null
  constructor(code: RestaurantOrderApiError['code'], message: string, currentVersion: number | null = null) { super(message); this.code = code; this.currentVersion = currentVersion }
}
const fail = (e: unknown): never => {
  if (e instanceof ApiError) {
    const code = e.code === 'order_version_conflict' ? 'version_conflict' : e.code === 'order_invalid_transition' ? 'invalid_transition' : e.kind === 'forbidden' ? 'forbidden' : e.kind === 'not_found' ? 'not_found' : 'error'
    throw new RestaurantOrderApiError(code, e.message, typeof e.details.current_version === 'number' ? e.details.current_version : null)
  }
  throw e
}

export class ApiRestaurantOrderRepository implements RestaurantOrderRepository {
  private management: ApiRestaurantManagementRepository
  private names = new Map<string, string>()
  /** The version of each order as last seen: a step names it, so a stale screen cannot act on an order that moved. */
  private versions = new Map<string, number>()
  constructor(management: ApiRestaurantManagementRepository) { this.management = management }

  private async location(restaurantId: string): Promise<{ uuid: string; name: string }> {
    let uuid = this.management.locationUuid(restaurantId)
    if (!uuid) { await this.management.getLocations().catch(() => undefined); uuid = this.management.locationUuid(restaurantId) }
    if (!uuid) throw new RestaurantOrderApiError('not_found', 'location_unavailable')
    if (!this.names.has(restaurantId)) { const l = await this.management.getLocation(restaurantId).catch(() => null); this.names.set(restaurantId, l?.restaurant.name ?? '') }
    return { uuid, name: this.names.get(restaurantId) ?? '' }
  }

  /** The restaurant's view mapped onto the shared order model: the queue needs no payment detail and no credential. */
  private toShared(d: RowDto | DetailDto, locationName: string, timezone: string): Order {
    const detail = d as Partial<DetailDto>
    const dto: OrderDto = {
      id: d.id, order_number: d.order_number, status: d.status, payment_state: d.payment_state, refund_status: d.refund_status,
      restaurant: { id: d.location_id, slug: null, name: locationName, branch_label: null, formatted_address: null, phone: null, timezone, pickup_instructions: null },
      item_count: d.item_count, total: d.total, pickup: d.pickup, placed_at: d.placed_at, updated_at: d.updated_at,
      items: detail.items ?? [], pricing: detail.pricing ?? { currency: d.total.currency, subtotal: d.total, discount: { amount: 0, currency: d.total.currency }, tax: { amount: 0, currency: d.total.currency }, tax_included: { amount: 0, currency: d.total.currency }, fee: { amount: 0, currency: d.total.currency }, total: d.total },
      promotion: detail.promotion ? { code: detail.promotion.code, name: null, discount: detail.pricing?.discount ?? { amount: 0, currency: d.total.currency } } : null,
      taxes: detail.taxes ?? [], fees: detail.fees ?? [],
      payment: { required: d.payment_state === 'PAID', id: null, provider: null, method: null, verified_at: null },
      customer_note: detail.customer_note ?? null, journey: detail.journey ? { id: '', estimated_arrival_at: detail.journey.estimated_arrival_at, detour: null } : null,
      rejection: detail.rejection ? { reason: detail.rejection.reason, message: '' } : null, cancellation: detail.cancellation ? { message: detail.cancellation.reason ?? '' } : null,
      pickup_credential: null, timeline: (detail.timeline ?? []).map((l) => ({ ...l, actor: l.actor === 'RESTAURANT_USER' ? 'RESTAURANT' : l.actor === 'CUSTOMER' ? 'YOU' : 'FOODONTHEGO' })),
      next_action: '', times: detail.times ?? {}, version: d.version,
    }
    const order = toOrder(dto, '')
    order.customerDisplayName = d.customer.name || null
    order.orderNote = detail.customer_note ?? ''
    this.versions.set(d.order_number, d.version)
    return order
  }

  async list(locationId: string, f: OrderListFilter): Promise<{ orders: Order[]; counts: Record<OrderTab, number> }> {
    const loc = await this.location(locationId)
    const tz = (await this.management.getLocation(locationId).catch(() => null))?.restaurant.timezone ?? 'UTC'
    const day = f.date === 'today' ? new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date()) : null
    const pages = await Promise.all(TAB_STATUSES[f.tab].map((status) => api<PageDto>('/restaurant/orders', { ...R, query: { location: loc.uuid, 'filter[status]': status, 'filter[day]': day, q: f.query?.trim() || null, 'page[size]': 50, sort: f.tab === 'completed' || f.tab === 'cancelled' ? '-updated_at' : 'pickup_at' } })))
    const c = pages[0].counts
    const orders = pages.flatMap((p) => p.data).map((d) => this.toShared(d, loc.name, tz))
    orders.sort((a, b) => (f.tab === 'completed' || f.tab === 'cancelled' ? b.updatedAt.localeCompare(a.updatedAt) : a.pickup.requestedAt.localeCompare(b.pickup.requestedAt)))
    return { orders, counts: { new: c.waiting_for_restaurant, preparing: c.accepted + c.preparing, ready: c.ready_for_pickup, completed: f.tab === 'completed' ? pages.reduce((n, p) => n + p.meta.total, 0) : 0, cancelled: f.tab === 'cancelled' ? pages.reduce((n, p) => n + p.meta.total, 0) : 0 } }
  }

  async get(locationId: string, orderNumber: string): Promise<Order | null> {
    const loc = await this.location(locationId)
    const tz = (await this.management.getLocation(locationId).catch(() => null))?.restaurant.timezone ?? 'UTC'
    try { return this.toShared((await api<{ order: DetailDto }>(`/restaurant/orders/${encodeURIComponent(orderNumber)}`, R)).order, loc.name, tz) } catch (e) {
      if (e instanceof ApiError && e.kind === 'not_found') return null
      return fail(e)
    }
  }

  private async step(locationId: string, orderNumber: string, step: 'accept' | 'reject' | 'start-preparing' | 'ready', body: Record<string, unknown> = {}): Promise<Order> {
    const loc = await this.location(locationId)
    const tz = (await this.management.getLocation(locationId).catch(() => null))?.restaurant.timezone ?? 'UTC'
    const version = this.versions.get(orderNumber)
    try {
      const { order } = await api<{ order: DetailDto }>(`/restaurant/orders/${encodeURIComponent(orderNumber)}/${step}`, { ...R, method: 'POST', body: { ...(version ? { expected_version: version } : {}), ...body }, idempotencyKey: `order-step-${crypto.randomUUID()}` })
      return this.toShared(order, loc.name, tz)
    } catch (e) { return fail(e) }
  }

  accept(locationId: string, orderNumber: string): Promise<Order> { return this.step(locationId, orderNumber, 'accept') }
  reject(locationId: string, orderNumber: string, reason: RejectReason, internalNote: string): Promise<Order> { return this.step(locationId, orderNumber, 'reject', { reason: REJECT[reason], note: internalNote.trim() || null }) }
  startPreparing(locationId: string, orderNumber: string): Promise<Order> { return this.step(locationId, orderNumber, 'start-preparing') }
  /**
   * The dashboard's one button from "accepted" to "ready" is two explicit steps on the backend (ACCEPTED → PREPARING →
   * READY_FOR_PICKUP); both are taken here, in order, and each is judged by the backend.
   */
  async markReady(locationId: string, orderNumber: string): Promise<Order> {
    const current = await this.get(locationId, orderNumber)
    if (current?.orderStatus === 'ACCEPTED') await this.step(locationId, orderNumber, 'start-preparing')
    return this.step(locationId, orderNumber, 'ready')
  }

  /** The backend has no delay step in Module 31: nothing is changed and the page is told so. */
  async delay(_locationId: string, _orderNumber: string, _etaReadyAt: string, _reason: DelayReason): Promise<Order> {
    throw new RestaurantOrderApiError('not_available', 'Reporting a delay is not available on the backend yet (planned for a later module).')
  }

  /** Pickup verification is Module 32: nothing is verified here, and no code is ever accepted by the browser. */
  async verifyPickup(_locationId: string, _code: string): Promise<VerificationResult> {
    throw new RestaurantOrderApiError('not_available', 'Pickup verification is not available on the backend yet (Module 32).')
  }

  async history(locationId: string, q: { query?: string; status?: 'all' | 'completed' | 'cancelled' | 'rejected'; cursor?: string | null; limit?: number }): Promise<{ orders: Order[]; nextCursor: string | null; total: number }> {
    const loc = await this.location(locationId)
    const tz = (await this.management.getLocation(locationId).catch(() => null))?.restaurant.timezone ?? 'UTC'
    const statuses: BackendOrderStatus[] = q.status === 'completed' ? ['PICKED_UP', 'COMPLETED'] : q.status === 'cancelled' ? ['CANCELLED'] : q.status === 'rejected' ? ['REJECTED'] : ['PICKED_UP', 'COMPLETED', 'REJECTED', 'CANCELLED']
    const page = q.cursor ? Math.max(1, Number.parseInt(q.cursor, 10) || 1) : 1
    const size = Math.max(1, Math.min(50, q.limit ?? 10))
    const pages = await Promise.all(statuses.map((status) => api<PageDto>('/restaurant/orders', { ...R, query: { location: loc.uuid, 'filter[status]': status, q: q.query?.trim() || null, 'page[size]': size, 'page[number]': page, sort: '-updated_at' } })))
    const orders = pages.flatMap((p) => p.data).map((d) => this.toShared(d, loc.name, tz)).sort((a, b) => b.updatedAt.localeCompare(a.updatedAt)).slice(0, size)
    const total = pages.reduce((n, p) => n + p.meta.total, 0)
    return { orders, nextCursor: pages.some((p) => p.meta.current_page < p.meta.last_page) ? String(page + 1) : null, total }
  }
}

export const restaurantStatusLabel = (s: BackendOrderStatus) => STATUS_FROM_API[s]

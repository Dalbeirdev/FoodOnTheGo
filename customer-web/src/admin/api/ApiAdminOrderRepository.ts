import { ApiError, api } from '../../api/client'
import { toOrder, type BackendOrderStatus, type OrderDto } from '../../order/api/apiOrder'
import type { Order } from '../../order/repositories'
import type { AdminOrder, AdminOrderFilter, AdminOrderRepository, AdminPayment, AdminRefund, OrderException, Page, SupportCase } from '../types'

/**
 * Orders for administrators, from the backend (Module 31): the real orders with their money state, the payment each
 * came from, and — as "exceptions" — the paid checkouts that did NOT become an order (payment VERIFIED, order NOT
 * CREATED), which the backend keeps as order creation records until a person resolves them.
 *
 * Administrators observe here. The two backend actions (cancel with a reason, ask for a failed creation again) are
 * on the order creation records; nothing here edits an order's status, amount or items by hand, and admin notes are
 * not part of the backend yet (the development mock kept them in the browser).
 */
type MoneyDto = { amount: number; currency: string }
type RowDto = {
  id: string; order_number: string; status: BackendOrderStatus; payment_state: 'PAID' | 'PAYMENT_NOT_REQUIRED'; refund_status: OrderDto['refund_status']; needs_attention: boolean
  restaurant: { id: string | null; name: string; organization: string | null }; market: string | null; customer: { id: string | null; name?: string | null; phone_masked: string | null }
  payment_id: string | null; checkout_id: string; item_count: number; total: MoneyDto; pickup: OrderDto['pickup']; placed_at: string | null; updated_at: string | null; version: number
}
type DetailDto = RowDto & {
  items: OrderDto['items']; pricing: OrderDto['pricing']; promotion: { code: string; name: string | null; type: string | null } | null; taxes: OrderDto['taxes']; fees: OrderDto['fees']
  payment: { id: string; provider: string; mode: string; provider_payment_reference: string | null; method_type: string | null; method_summary: Record<string, string | boolean | null>; amount: MoneyDto; verified_at: string | null } | null
  customer_note: string | null; rejection: { reason: string; note: string | null } | null; cancellation: { reason: string | null } | null
  refunds: Array<{ id: string; amount: MoneyDto; reason: string; status: 'REQUIRED' | 'PENDING' | 'PROCESSED' | 'FAILED'; requested_by: { type: string; id: string | null }; requested_at: string | null; processing: 'NOT_IMPLEMENTED' }>
  pickup_credential: { status: string; issued_at: string | null } | null; journey: unknown; legal: unknown[]; pricing_fingerprint: string | null
  fulfillment: FulfillmentDto | null; timeline: OrderDto['timeline']; times: OrderDto['times']; actions: { cancel: boolean; refund: 'NOT_IMPLEMENTED' }
}
export type FulfillmentDto = {
  id: string; checkout_id: string; payment_id: string | null; order_id: string | null; order_number: string | null; trigger: string; status: 'PENDING' | 'PROCESSING' | 'SUCCEEDED' | 'RETRYING' | 'FAILED' | 'REVIEW_REQUIRED'
  attempts: number; last_error_code: string | null; last_error_detail: string | null; next_attempt_at: string | null; needs_attention: boolean; started_at: string | null; finished_at: string | null; created_at: string | null; updated_at: string | null
}
type PageDto<T> = { data: T[]; meta: { total: number; current_page: number; per_page: number; last_page: number } }

export type BackendOrderFulfillment = {
  id: string; checkoutId: string; paymentId: string | null; orderId: string | null; orderNumber: string | null; trigger: string; status: FulfillmentDto['status']
  attempts: number; lastErrorCode: string | null; lastErrorDetail: string | null; nextAttemptAt: string | null; needsAttention: boolean; createdAt: string | null; updatedAt: string | null
}
const fulfillment = (d: FulfillmentDto): BackendOrderFulfillment => ({
  id: d.id, checkoutId: d.checkout_id, paymentId: d.payment_id, orderId: d.order_id, orderNumber: d.order_number, trigger: d.trigger, status: d.status,
  attempts: d.attempts, lastErrorCode: d.last_error_code, lastErrorDetail: d.last_error_detail, nextAttemptAt: d.next_attempt_at, needsAttention: d.needs_attention, createdAt: d.created_at, updatedAt: d.updated_at,
})

const TAB_QUERY: Record<AdminOrderFilter['tab'], Record<string, string>> = {
  all: {}, active: {}, preparing: { 'filter[status]': 'PREPARING' }, ready: { 'filter[status]': 'READY_FOR_PICKUP' }, completed: { 'filter[status]': 'COMPLETED' }, cancelled: { 'filter[status]': 'CANCELLED' }, rejected: { 'filter[status]': 'REJECTED' }, exception: { 'filter[attention]': '1' },
}
const ACTIVE: BackendOrderStatus[] = ['WAITING_FOR_RESTAURANT', 'ACCEPTED', 'PREPARING', 'READY_FOR_PICKUP']

function shared(d: RowDto | DetailDto): Order {
  const x = d as Partial<DetailDto>
  const c = d.total.currency
  const zero = { amount: 0, currency: c }
  const dto: OrderDto = {
    id: d.id, order_number: d.order_number, status: d.status, payment_state: d.payment_state, refund_status: d.refund_status,
    restaurant: { id: d.restaurant.id, slug: null, name: d.restaurant.name, branch_label: null, formatted_address: null, phone: null, timezone: d.pickup.timezone, pickup_instructions: null },
    item_count: d.item_count, total: d.total, pickup: d.pickup, placed_at: d.placed_at, updated_at: d.updated_at,
    items: x.items ?? [], pricing: x.pricing ?? { currency: c, subtotal: d.total, discount: zero, tax: zero, tax_included: zero, fee: zero, total: d.total },
    promotion: x.promotion ? { code: x.promotion.code, name: x.promotion.name, discount: x.pricing?.discount ?? zero } : null, taxes: x.taxes ?? [], fees: x.fees ?? [],
    payment: x.payment ? { required: true, id: x.payment.id, provider: x.payment.provider, method: x.payment.method_type ? { type: x.payment.method_type, summary: x.payment.method_summary } : null, verified_at: x.payment.verified_at } : { required: d.payment_state === 'PAID', id: d.payment_id, provider: null, method: null, verified_at: null },
    customer_note: x.customer_note ?? null, journey: null, rejection: x.rejection ? { reason: x.rejection.reason, message: x.rejection.note ?? '' } : null, cancellation: x.cancellation ? { message: x.cancellation.reason ?? '' } : null,
    pickup_credential: null, timeline: (x.timeline ?? []).map((l) => ({ ...l, actor: l.actor === 'RESTAURANT_USER' ? 'RESTAURANT' : l.actor === 'CUSTOMER' ? 'YOU' : 'FOODONTHEGO' })), next_action: '', times: x.times ?? {}, version: d.version,
  }
  const order = toOrder(dto, d.customer.id ?? '')
  order.customerDisplayName = d.customer.name ?? d.customer.phone_masked ?? null
  return order
}

const exceptionsOf = (d: RowDto | DetailDto): OrderException[] => (d.needs_attention ? [{ orderNumber: d.order_number, kind: 'refund_overdue', detectedAt: d.updated_at ?? d.placed_at ?? new Date().toISOString(), detail: `Refund ${d.refund_status.toLowerCase()} — the refund module processes it` }] : [])
const toAdminOrder = (d: RowDto | DetailDto): AdminOrder => ({ order: shared(d), market: d.market ?? '', exceptions: exceptionsOf(d), supportCaseIds: [], adminNotes: [] })

/** The payment as the order recorded it, in the admin page's payment shape: a reference and its state, never a secret. */
function paymentOf(d: DetailDto): AdminPayment | null {
  const p = d.payment
  if (!p) return null
  return {
    reference: p.id, providerReference: p.provider_payment_reference, orderNumber: d.order_number, restaurantId: d.restaurant.id ?? '', restaurantName: d.restaurant.name, customerRef: d.customer.id ?? '',
    provider: p.provider, methodCategory: p.method_type ?? '—', amountMinor: p.amount.amount, currency: p.amount.currency, status: d.refund_status === 'REFUNDED' ? 'REFUNDED' : d.refund_status === 'PARTIAL' ? 'PARTIALLY_REFUNDED' : d.refund_status === 'REQUIRED' || d.refund_status === 'PENDING' || d.refund_status === 'FAILED' ? 'REFUND_PENDING' : 'CAPTURED',
    createdAt: p.verified_at ?? d.placed_at ?? '', events: [{ at: p.verified_at ?? d.placed_at ?? '', type: 'VERIFIED', detail: `${p.provider} (${p.mode})` }],
    reconciliation: { expectedMinor: d.pricing.total.amount, providerStatus: 'captured', platformStatus: 'CAPTURED', mismatch: p.amount.amount !== d.pricing.total.amount },
  }
}
const refundsOf = (d: DetailDto): AdminRefund[] => d.refunds.map((r) => ({
  reference: r.id, orderNumber: d.order_number, paymentReference: d.payment?.id ?? '', restaurantName: d.restaurant.name, requestedMinor: r.amount.amount, refundedMinor: r.status === 'PROCESSED' ? r.amount.amount : 0, currency: r.amount.currency,
  reason: r.reason, status: r.status === 'REQUIRED' ? 'PENDING' : r.status === 'PROCESSED' ? 'COMPLETED' : r.status === 'FAILED' ? 'FAILED' : 'PROCESSING', createdAt: r.requested_at ?? '', processedAt: null, kind: 'full',
}))

export class ApiAdminOrderRepository implements AdminOrderRepository {
  async list(f: AdminOrderFilter): Promise<Page<AdminOrder> & { counts: Record<AdminOrderFilter['tab'], number> }> {
    const page = Math.max(1, f.page ?? 1); const size = Math.max(1, Math.min(100, f.pageSize ?? 20))
    const query: Record<string, string | number | null> = { 'page[number]': page, 'page[size]': size, q: f.query?.trim() || null, ...TAB_QUERY[f.tab], ...(f.date === 'today' ? { 'filter[from]': new Date().toISOString().slice(0, 10) } : f.date === '7d' ? { 'filter[from]': new Date(Date.now() - 6 * 86400000).toISOString().slice(0, 10) } : {}) }
    // The backend scopes the list by the administrator's market grants; a market filter is not part of its API.
    const res = await api<PageDto<RowDto>>('/admin/orders', { context: 'admin', query })
    let items = res.data
    if (f.tab === 'active') items = items.filter((d) => ACTIVE.includes(d.status))   // the backend lists by one state; "active" is the union
    const metrics = await api<{ orders: Record<string, number>; active: number; refund_required: number; verified_payments_without_order: number }>('/admin/system/orders', { context: 'admin' }).catch(() => null)
    const o = metrics?.orders ?? {}
    const counts: Record<AdminOrderFilter['tab'], number> = {
      all: res.meta.total, active: metrics?.active ?? 0, preparing: o.preparing ?? 0, ready: o.ready_for_pickup ?? 0, completed: o.completed ?? 0, cancelled: o.cancelled ?? 0, rejected: o.rejected ?? 0,
      exception: (metrics?.refund_required ?? 0) + (metrics?.verified_payments_without_order ?? 0),
    }
    return { items: items.map(toAdminOrder), total: res.meta.total, page: res.meta.current_page, pageSize: res.meta.per_page, counts }
  }

  async get(orderNumber: string): Promise<(AdminOrder & { payment: AdminPayment | null; refunds: AdminRefund[]; supportCases: SupportCase[] }) | null> {
    try {
      const { order } = await api<{ order: DetailDto }>(`/admin/orders/${encodeURIComponent(orderNumber)}`, { context: 'admin' })
      return { ...toAdminOrder(order), payment: paymentOf(order), refunds: refundsOf(order), supportCases: [] }
    } catch (e) {
      if (e instanceof ApiError && e.kind === 'not_found') return null
      throw e
    }
  }

  /** Admin notes are not part of the backend order record yet: nothing is stored and the page is told so. */
  async addNote(_orderNumber: string, _actor: string, _text: string): Promise<AdminOrder> {
    throw new Error('Order notes are not available on the backend yet; use the support case.')
  }

  /** Paid checkouts that did not become an order — the backend's order creation records that need a person. */
  async exceptions(): Promise<OrderException[]> {
    const res = await api<PageDto<FulfillmentDto>>('/admin/order-fulfillments', { context: 'admin', query: { 'filter[attention]': '1', 'page[size]': 100 } })
    return res.data.map((f) => ({ orderNumber: f.order_number ?? `checkout:${f.checkout_id}`, kind: 'payment_without_confirmation', detectedAt: f.updated_at ?? f.created_at ?? new Date().toISOString(), detail: `${f.status}${f.last_error_code ? ` · ${f.last_error_code}` : ''} · payment ${f.payment_id ?? '—'} · attempts ${f.attempts}` }))
  }

  /* ---- the two backend actions, for the recovery screen ---- */

  async fulfillments(attention = true): Promise<BackendOrderFulfillment[]> {
    const res = await api<PageDto<FulfillmentDto>>('/admin/order-fulfillments', { context: 'admin', query: { ...(attention ? { 'filter[attention]': '1' } : {}), 'page[size]': 100 } })
    return res.data.map(fulfillment)
  }

  async recover(fulfillmentId: string, reason: string): Promise<BackendOrderFulfillment> {
    return fulfillment((await api<{ fulfillment: FulfillmentDto }>(`/admin/order-fulfillments/${encodeURIComponent(fulfillmentId)}/recover`, { context: 'admin', method: 'POST', body: { reason } })).fulfillment)
  }

  async cancel(orderNumber: string, reason: string): Promise<AdminOrder & { payment: AdminPayment | null; refunds: AdminRefund[]; supportCases: SupportCase[] }> {
    const { order } = await api<{ order: DetailDto }>(`/admin/orders/${encodeURIComponent(orderNumber)}/cancel`, { context: 'admin', method: 'POST', body: { reason } })
    return { ...toAdminOrder(order), payment: paymentOf(order), refunds: refundsOf(order), supportCases: [] }
  }
}

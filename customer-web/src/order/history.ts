/**
 * Order history helpers (Module 15): status → group mapping, filtering, sorting, cursor paging over summaries.
 * Groups are UI concepts; the order status stays the domain value and the payment status stays separate.
 */
import type { OrderGroup, OrderListQuery, OrderPage, OrderPaymentStatus, OrderSort, OrderStatus, OrderSummary } from './repositories'

export const ONGOING: ReadonlySet<OrderStatus> = new Set(['PAYMENT_PENDING', 'CONFIRMED', 'AWAITING_RESTAURANT_ACCEPTANCE', 'ACCEPTED', 'PREPARING', 'READY_FOR_PICKUP', 'PICKUP_VERIFICATION'])
export const COMPLETED: ReadonlySet<OrderStatus> = new Set(['PICKED_UP', 'COMPLETED'])
export const CLOSED: ReadonlySet<OrderStatus> = new Set(['CANCELLED', 'REJECTED', 'REFUND_PENDING', 'REFUNDED'])

export function groupOf(status: OrderStatus): Exclude<OrderGroup, 'all'> {
  if (ONGOING.has(status)) return 'ongoing'
  if (COMPLETED.has(status)) return 'completed'
  return 'cancelled'
}
export const isTrackable = (s: OrderStatus) => ONGOING.has(s) && s !== 'PAYMENT_PENDING'
export const isReviewable = (s: OrderStatus) => COMPLETED.has(s)
export const hasRefund = (p: OrderPaymentStatus) => p === 'REFUND_PENDING' || p === 'PARTIALLY_REFUNDED' || p === 'REFUNDED'

const norm = (s: string) => s.normalize('NFKD').toLowerCase()
export function matchesQuery(o: OrderSummary, q: string): boolean {
  const n = norm(q.trim()); if (!n) return true
  return norm(o.orderNumber).includes(n) || norm(o.restaurantName).includes(n)
}
export function sortSummaries(list: OrderSummary[], sort: OrderSort = 'newest'): OrderSummary[] {
  return [...list].sort((a, b) => (sort === 'newest' ? b.createdAt.localeCompare(a.createdAt) : a.createdAt.localeCompare(b.createdAt)))
}
/** Cursor paging: the cursor is the index into the filtered + sorted list (opaque to callers; the backend uses its own tokens). */
export function pageSummaries(all: OrderSummary[], q: OrderListQuery = {}): OrderPage {
  const group = q.group ?? 'all'
  const filtered = all.filter((o) => (group === 'all' || groupOf(o.orderStatus) === group) && matchesQuery(o, q.query ?? ''))
  const sorted = sortSummaries(filtered, q.sort ?? 'newest')
  const limit = Math.max(1, q.limit ?? 5)
  const start = q.cursor ? Math.max(0, Number.parseInt(q.cursor, 10) || 0) : 0
  const items = sorted.slice(start, start + limit)
  const next = start + limit
  return { items, nextCursor: next < sorted.length ? String(next) : null, total: sorted.length }
}

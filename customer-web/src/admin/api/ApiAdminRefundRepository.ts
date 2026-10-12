import { api } from '../../api/client'
import type { Page } from '../types'

/**
 * Refunds and disputes for administrators, from the backend (Module 35): the real refund records, their queues, the
 * payment's balance, every provider attempt, the provider's events and the audit trail.
 *
 * What an administrator can do is exactly what the backend offers — ask for a refund, approve or turn one down, send an
 * approved refund to the provider, try a failed one again, ask the provider again — each behind its own permission and
 * with a reason where a decision is made. Nothing here can set a refund's status, its amount once asked, its currency,
 * its payment or a provider reference: there is no "mark as refunded". Every amount shown is the backend's; the browser
 * never works out what is left to refund.
 */
export type BackendRefundStatus = 'REQUIRED' | 'REQUESTED' | 'APPROVED' | 'SUBMITTING' | 'SUBMISSION_UNKNOWN' | 'PROCESSING' | 'REFUNDED' | 'FAILED' | 'CANCELLED' | 'REVIEW_REQUIRED'
export type RefundQueue = 'required' | 'awaiting_approval' | 'approved' | 'processing' | 'failed' | 'review_required' | 'completed' | 'cancelled'
export type RefundAction = 'approve' | 'reject' | 'execute' | 'retry' | 'reconcile'
export const REFUND_QUEUES: RefundQueue[] = ['required', 'awaiting_approval', 'approved', 'processing', 'failed', 'review_required', 'completed', 'cancelled']
export const REFUND_REQUEST_REASONS = ['ORDER_CANNOT_BE_FULFILLED', 'CUSTOMER_CANCELLATION', 'RESTAURANT_CANCELLATION', 'ITEM_UNAVAILABLE', 'PICKUP_CAPACITY_FAILURE', 'ADMIN_FINANCIAL_CORRECTION', 'OTHER'] as const
export const REFUND_REASONS = ['RESTAURANT_REJECTED', 'ORDER_CANNOT_BE_FULFILLED', 'CUSTOMER_CANCELLATION', 'RESTAURANT_CANCELLATION', 'ADMIN_CANCELLATION', 'DUPLICATE_PAYMENT', 'PAYMENT_ORDER_FAILURE', 'ITEM_UNAVAILABLE', 'PICKUP_CAPACITY_FAILURE', 'ADMIN_FINANCIAL_CORRECTION', 'OTHER'] as const

type MoneyDto = { amount: number; currency: string }
type RowDto = {
  id: string; reference: string; type: 'FULL' | 'PARTIAL'; status: BackendRefundStatus; needs_review: boolean; awaits_approval: boolean
  amount: MoneyDto; approved_amount: MoneyDto | null; currency: string; reason: { code: string; detail: string | null }
  provider: string; provider_mode: string | null; order: { number: string } | null; payment: { id: string; reference: string }
  customer: { id: string | null }; restaurant: { name: string }; market: string | null; requested_by: { type: string; id: string | null }
  policy: { decision: string; reason: string | null } | null; requested_at: string | null; completed_at: string | null; updated_at: string | null; version: number
}
type BalanceDto = { paid: MoneyDto; refunded: MoneyDto; in_flight: MoneyDto; charged_back: MoneyDto; available: MoneyDto }
type DisputeBriefDto = { id: string; status: string; open: boolean; amount: MoneyDto; reason_code: string | null; respond_by_at: string | null }
export type RefundDetailDto = RowDto & {
  note: string | null
  approval: { mode: 'AUTO' | 'MANUAL'; by: { id: string; name: string } | null; at: string | null; note: string | null } | null
  closed: { by: { id: string; name: string } | null; at: string | null; note: string | null } | null
  failure: { category: string | null; code: string; message: string | null; retryable: boolean } | null
  review_reason: string | null
  provider_detail: { name: string; mode: string | null; payment_reference: string | null; refund_reference: string | null; status: string | null; receipt: string | null; trace_reference: string | null }
  submission: { generation: number; attempts: number; unknown_resubmits: number; submitted_at: string | null }
  reconciliation: { runs: number; last_at: string | null; next_at: string | null }
  balance: BalanceDto
  order: { id: string; number: string; status: string; refund_status: string; total: MoneyDto } | null
  payment: { id: string; reference: string; status: string | null; amount: MoneyDto | null; method_type: string | null }
  times: Record<string, string | null>
  attempts: Array<{ at: string | null; finished_at: string | null; operation: string; generation: number; source: string; outcome: string; provider_status: string | null; provider_refund_reference: string | null; failure_code: string | null; idempotency_key: string | null; latency_ms: number | null }>
  timeline: Array<{ at: string | null; type: string; source: string; from: string | null; to: string | null; detail: Record<string, unknown> }>
  webhook_events: Array<{ id: string; type: string; normalized_type: string | null; received_at: string | null; processed_at: string | null; processing_status: string; outcome: string | null; deliveries: number }>
  audit: Array<{ at: string | null; action: string; actor_type: string | null; actor_id: string | null; reason: string | null; changes: Record<string, unknown> }>
  disputes: DisputeBriefDto[]
  actions: { approve: boolean; reject: boolean; execute: boolean; retry: boolean; reconcile: boolean; mark_refunded: 'NOT_PROVIDED' }
}
type ListDto<T> = { data: T[]; meta: { total: number; current_page: number; per_page: number } }
export type DisputeDto = {
  id: string; status: string; open: boolean; provider: string; provider_dispute_reference: string; provider_status: string | null; phase: string | null; reason_code: string | null
  amount: MoneyDto; amount_deducted: MoneyDto; payment: { id: string; reference: string; provider_payment_reference: string | null }; order: { number: string } | null; restaurant: { name: string }; market: string | null
  opened_at: string | null; respond_by_at: string | null; closed_at: string | null; updated_at: string | null; actions: { respond: 'NOT_PROVIDED'; accept: 'NOT_PROVIDED' }
}

export type BackendRefund = {
  id: string; reference: string; type: 'FULL' | 'PARTIAL'; status: BackendRefundStatus; needsReview: boolean; awaitsApproval: boolean
  amountMinor: number; approvedMinor: number | null; currency: string; reasonCode: string; reasonDetail: string | null
  provider: string; providerMode: string | null; orderNumber: string | null; paymentId: string; paymentReference: string
  customerRef: string | null; restaurantName: string; market: string | null; requestedByType: string
  policyDecision: string | null; requestedAt: string | null; completedAt: string | null; version: number
}
export type RefundBalance = { paidMinor: number; refundedMinor: number; inFlightMinor: number; chargedBackMinor: number; availableMinor: number }
export type DisputeBrief = { id: string; status: string; open: boolean; amountMinor: number; currency: string; reasonCode: string | null; respondByAt: string | null }
export type BackendRefundDetail = BackendRefund & {
  note: string | null
  approval: { mode: 'AUTO' | 'MANUAL'; by: string | null; at: string | null; note: string | null } | null
  closed: { by: string | null; at: string | null; note: string | null } | null
  failure: { category: string | null; code: string; message: string | null; retryable: boolean } | null
  reviewReason: string | null; policyReason: string | null
  providerPayment: string | null; providerRefund: string | null; providerStatus: string | null; receipt: string | null; traceReference: string | null
  generation: number; submitAttempts: number; reconcileRuns: number; lastReconciledAt: string | null; nextLookAt: string | null
  balance: RefundBalance
  order: { number: string; status: string; refundStatus: string; totalMinor: number } | null
  paymentStatus: string | null; paymentAmountMinor: number | null
  attempts: Array<{ at: string | null; operation: string; generation: number; source: string; outcome: string; providerStatus: string | null; providerRefund: string | null; failureCode: string | null; latencyMs: number | null }>
  timeline: Array<{ at: string | null; type: string; source: string; from: string | null; to: string | null }>
  webhookEvents: Array<{ id: string; type: string; receivedAt: string | null; processingStatus: string; outcome: string | null; deliveries: number }>
  audit: Array<{ at: string | null; action: string; actorType: string | null; reason: string | null }>
  disputes: DisputeBrief[]
  /** What the refund's state allows AND the caller may do — decided by the backend. */
  actions: Record<RefundAction, boolean>
}
export type BackendDispute = {
  id: string; status: string; open: boolean; provider: string; providerReference: string; phase: string | null; reasonCode: string | null
  amountMinor: number; deductedMinor: number; currency: string; paymentId: string; paymentReference: string; orderNumber: string | null; restaurantName: string
  openedAt: string | null; respondByAt: string | null; closedAt: string | null
}
export type RefundFilter = { query?: string; queue?: RefundQueue | 'all'; type?: 'FULL' | 'PARTIAL' | 'all'; reason?: string; review?: boolean; page?: number; pageSize?: number }
export type RefundRequestInput = { type: 'FULL' | 'PARTIAL'; amountMinor: number | null; reasonCode: string; note: string | null }

export const toRefund = (d: RowDto): BackendRefund => ({
  id: d.id, reference: d.reference, type: d.type, status: d.status, needsReview: d.needs_review, awaitsApproval: d.awaits_approval,
  amountMinor: d.amount.amount, approvedMinor: d.approved_amount?.amount ?? null, currency: d.currency, reasonCode: d.reason.code, reasonDetail: d.reason.detail,
  provider: d.provider, providerMode: d.provider_mode, orderNumber: d.order?.number ?? null, paymentId: d.payment.id, paymentReference: d.payment.reference,
  customerRef: d.customer.id, restaurantName: d.restaurant.name, market: d.market, requestedByType: d.requested_by.type,
  policyDecision: d.policy?.decision ?? null, requestedAt: d.requested_at, completedAt: d.completed_at, version: d.version,
})
const balance = (b: BalanceDto): RefundBalance => ({ paidMinor: b.paid.amount, refundedMinor: b.refunded.amount, inFlightMinor: b.in_flight.amount, chargedBackMinor: b.charged_back.amount, availableMinor: b.available.amount })
const disputeBrief = (d: DisputeBriefDto): DisputeBrief => ({ id: d.id, status: d.status, open: d.open, amountMinor: d.amount.amount, currency: d.amount.currency, reasonCode: d.reason_code, respondByAt: d.respond_by_at })
export const toRefundDetail = (d: RefundDetailDto): BackendRefundDetail => ({
  ...toRefund(d),
  note: d.note,
  approval: d.approval ? { mode: d.approval.mode, by: d.approval.by?.name ?? null, at: d.approval.at, note: d.approval.note } : null,
  closed: d.closed ? { by: d.closed.by?.name ?? null, at: d.closed.at, note: d.closed.note } : null,
  failure: d.failure, reviewReason: d.review_reason, policyReason: d.policy?.reason ?? null,
  providerPayment: d.provider_detail.payment_reference, providerRefund: d.provider_detail.refund_reference, providerStatus: d.provider_detail.status, receipt: d.provider_detail.receipt, traceReference: d.provider_detail.trace_reference,
  generation: d.submission.generation, submitAttempts: d.submission.attempts, reconcileRuns: d.reconciliation.runs, lastReconciledAt: d.reconciliation.last_at, nextLookAt: d.reconciliation.next_at,
  balance: balance(d.balance),
  order: d.order ? { number: d.order.number, status: d.order.status, refundStatus: d.order.refund_status, totalMinor: d.order.total.amount } : null,
  paymentStatus: d.payment.status, paymentAmountMinor: d.payment.amount?.amount ?? null,
  attempts: d.attempts.map((a) => ({ at: a.at, operation: a.operation, generation: a.generation, source: a.source, outcome: a.outcome, providerStatus: a.provider_status, providerRefund: a.provider_refund_reference, failureCode: a.failure_code, latencyMs: a.latency_ms })),
  timeline: d.timeline.map((e) => ({ at: e.at, type: e.type, source: e.source, from: e.from, to: e.to })),
  webhookEvents: d.webhook_events.map((e) => ({ id: e.id, type: e.type, receivedAt: e.received_at, processingStatus: e.processing_status, outcome: e.outcome, deliveries: e.deliveries })),
  audit: d.audit.map((e) => ({ at: e.at, action: e.action, actorType: e.actor_type, reason: e.reason })),
  disputes: d.disputes.map(disputeBrief),
  // Only the five actions the backend names. "mark_refunded" is NOT_PROVIDED and is never turned into a control.
  actions: { approve: d.actions.approve === true, reject: d.actions.reject === true, execute: d.actions.execute === true, retry: d.actions.retry === true, reconcile: d.actions.reconcile === true },
})
export const toDispute = (d: DisputeDto): BackendDispute => ({
  id: d.id, status: d.status, open: d.open, provider: d.provider, providerReference: d.provider_dispute_reference, phase: d.phase, reasonCode: d.reason_code,
  amountMinor: d.amount.amount, deductedMinor: d.amount_deducted.amount, currency: d.amount.currency, paymentId: d.payment.id, paymentReference: d.payment.reference,
  orderNumber: d.order?.number ?? null, restaurantName: d.restaurant.name, openedAt: d.opened_at, respondByAt: d.respond_by_at, closedAt: d.closed_at,
})

const A = { context: 'admin' as const }
const one = (id: string) => `/admin/refunds/${encodeURIComponent(id)}`

export class ApiAdminRefundRepository {
  async list(f: RefundFilter): Promise<Page<BackendRefund>> {
    const res = await api<ListDto<RowDto>>('/admin/refunds', { ...A, query: {
      q: f.query?.trim() || undefined,
      'filter[queue]': f.queue && f.queue !== 'all' ? f.queue : undefined,
      'filter[type]': f.type && f.type !== 'all' ? f.type : undefined,
      'filter[reason]': f.reason && f.reason !== 'all' ? f.reason : undefined,
      'filter[review]': f.review ? '1' : undefined,
      'page[number]': f.page ?? 1, 'page[size]': f.pageSize ?? 10, sort: '-requested_at',
    } })
    return { items: res.data.map(toRefund), total: res.meta.total, page: res.meta.current_page, pageSize: res.meta.per_page }
  }

  async queues(): Promise<Record<RefundQueue, number> & { total: number }> {
    const res = await api<{ queues: Record<RefundQueue, number>; total: number }>('/admin/refunds/queues', A)
    return { ...res.queues, total: res.total }
  }

  async get(id: string): Promise<BackendRefundDetail> {
    return toRefundDetail((await api<{ refund: RefundDetailDto }>(one(id), A)).refund)
  }

  /**
   * Asks for a refund of an order that is over. FULL sends no amount at all — the backend works out what is left; PARTIAL
   * sends the amount as a REQUEST the backend checks against the payment's balance at that moment. Never a status, an
   * approved amount, a payment or a provider reference. The idempotency key makes a double click one request.
   */
  async requestForOrder(orderNumber: string, input: RefundRequestInput, idempotencyKey: string): Promise<BackendRefundDetail> {
    const body: Record<string, unknown> = { type: input.type, reason_code: input.reasonCode }
    if (input.type === 'PARTIAL') body.amount_minor = input.amountMinor
    if (input.note) body.note = input.note
    return toRefundDetail((await api<{ refund: RefundDetailDto }>(`/admin/orders/${encodeURIComponent(orderNumber)}/refunds`, { ...A, method: 'POST', body, idempotencyKey })).refund)
  }

  /** Asks for the refund of a payment that never became an order: a note and nothing else — the backend reads the rest from the payment. */
  async requestForPayment(paymentId: string, note: string, idempotencyKey: string): Promise<BackendRefundDetail> {
    return toRefundDetail((await api<{ refund: RefundDetailDto }>(`/admin/payments/${encodeURIComponent(paymentId)}/refunds`, { ...A, method: 'POST', body: { note }, idempotencyKey })).refund)
  }

  /** One of the five actions. The body carries a reason (and the version the screen showed) — there is nothing else that could be sent. */
  async act(id: string, action: RefundAction, reason: string | null, expectedVersion?: number): Promise<BackendRefundDetail> {
    const body: Record<string, unknown> = {}
    if (reason) body.reason = reason
    if (expectedVersion !== undefined && (action === 'approve' || action === 'reject')) body.expected_version = expectedVersion
    return toRefundDetail((await api<{ refund: RefundDetailDto }>(`${one(id)}/${action}`, { ...A, method: 'POST', body })).refund)
  }

  async disputes(f: { query?: string; open?: boolean | null; page?: number; pageSize?: number }): Promise<Page<BackendDispute>> {
    const res = await api<ListDto<DisputeDto>>('/admin/disputes', { ...A, query: {
      q: f.query?.trim() || undefined, 'filter[open]': f.open === true ? '1' : f.open === false ? '0' : undefined,
      'page[number]': f.page ?? 1, 'page[size]': f.pageSize ?? 10, sort: '-created_at',
    } })
    return { items: res.data.map(toDispute), total: res.meta.total, page: res.meta.current_page, pageSize: res.meta.per_page }
  }
}

import { api } from '../../api/client'
import type { Page } from '../types'

/**
 * Payments for administrators, from the backend (Module 30): the real payment attempts with their timelines and the
 * provider's events. There is one action — "ask the provider again" — and it changes only what the provider
 * confirms; nothing here can mark a payment as paid, edit an amount or issue a refund.
 */
export type BackendPaymentStatus = 'CREATED' | 'PROVIDER_READY' | 'PROCESSING' | 'PENDING' | 'AUTHORIZED' | 'VERIFIED' | 'FAILED' | 'CANCELLED' | 'EXPIRED' | 'UNKNOWN' | 'REVIEW_REQUIRED'
export type BackendFulfillment = 'NOT_APPLICABLE' | 'ORDER_PENDING' | 'RECOVERY_REQUIRED' | 'ORDER_CREATED'

type RowDto = {
  id: string; support_reference: string; checkout_id: string; attempt_number: number
  status: BackendPaymentStatus; status_reason: string | null; fulfillment_status: BackendFulfillment; fulfillment_reason: string | null
  needs_attention: boolean; verified_without_order: boolean
  amount: { amount: number; currency: string }; provider: string; provider_mode: 'TEST' | 'LIVE' | 'FAKE'
  method_type: string | null; requested_method_type: string | null; failure_category: string | null; failure_code: string | null
  restaurant: { id: string | null; name: string }; market: string | null; customer: { id: string; phone_masked: string | null }
  created_at: string | null; verified_at: string | null; expires_at: string | null
}
type DetailDto = RowDto & {
  provider_references: { order: string | null; payment: string | null; receipt: string }
  provider_state: { order: string | null; payment: string | null; error_code: string | null; error_reason: string | null }
  method_summary: Record<string, string | boolean | null>
  verification: { client_outcome: string | null; client_reported_at: string | null; signature_verified_at: string | null; signature_failures: number }
  checkout: { id: string; version: number; pricing_fingerprint: string; status: string | null; expires_at: string | null }
  reconciliation: { runs: number; last_at: string | null; next_at: string | null }
  timeline: Array<{ at: string | null; type: string; source: string; from: string | null; to: string | null; detail: Record<string, unknown> }>
  webhook_events: Array<{ id: string; type: string; normalized_type: string | null; received_at: string | null; processed_at: string | null; processing_status: string; outcome: string | null; deliveries: number; failure_reason: string | null }>
  actions: { mark_paid: false; refund: 'NOT_IMPLEMENTED' }
}
type ListDto = { data: RowDto[]; meta: { total: number; current_page: number; per_page: number } }

export type BackendPayment = {
  id: string; supportReference: string; checkoutId: string; attemptNumber: number
  status: BackendPaymentStatus; statusReason: string | null; fulfillment: BackendFulfillment; fulfillmentReason: string | null
  needsAttention: boolean; verifiedWithoutOrder: boolean
  amountMinor: number; currency: string; provider: string; providerMode: 'TEST' | 'LIVE' | 'FAKE'
  methodType: string | null; failureCategory: string | null
  restaurantName: string; market: string | null; customerRef: string; customerPhoneMasked: string | null
  createdAt: string | null; verifiedAt: string | null
}
export type BackendPaymentDetail = BackendPayment & {
  providerOrder: string | null; providerPayment: string | null; providerState: { order: string | null; payment: string | null; errorCode: string | null; errorReason: string | null }
  methodSummary: Record<string, string | boolean | null>
  clientOutcome: string | null; signatureVerifiedAt: string | null; signatureFailures: number
  checkoutStatus: string | null
  reconciliation: { runs: number; lastAt: string | null; nextAt: string | null }
  timeline: Array<{ at: string | null; type: string; source: string; from: string | null; to: string | null }>
  webhookEvents: Array<{ id: string; type: string; receivedAt: string | null; processingStatus: string; outcome: string | null; deliveries: number }>
}
export type PaymentFilter = { query?: string; status?: BackendPaymentStatus | 'all'; method?: string; attention?: boolean; page?: number; pageSize?: number }

const row = (d: RowDto): BackendPayment => ({
  id: d.id, supportReference: d.support_reference, checkoutId: d.checkout_id, attemptNumber: d.attempt_number,
  status: d.status, statusReason: d.status_reason, fulfillment: d.fulfillment_status, fulfillmentReason: d.fulfillment_reason,
  needsAttention: d.needs_attention, verifiedWithoutOrder: d.verified_without_order,
  amountMinor: d.amount.amount, currency: d.amount.currency, provider: d.provider, providerMode: d.provider_mode,
  methodType: d.method_type, failureCategory: d.failure_category,
  restaurantName: d.restaurant.name, market: d.market, customerRef: d.customer.id, customerPhoneMasked: d.customer.phone_masked,
  createdAt: d.created_at, verifiedAt: d.verified_at,
})
const detail = (d: DetailDto): BackendPaymentDetail => ({
  ...row(d),
  providerOrder: d.provider_references.order, providerPayment: d.provider_references.payment,
  providerState: { order: d.provider_state.order, payment: d.provider_state.payment, errorCode: d.provider_state.error_code, errorReason: d.provider_state.error_reason },
  methodSummary: d.method_summary ?? {},
  clientOutcome: d.verification.client_outcome, signatureVerifiedAt: d.verification.signature_verified_at, signatureFailures: d.verification.signature_failures,
  checkoutStatus: d.checkout.status,
  reconciliation: { runs: d.reconciliation.runs, lastAt: d.reconciliation.last_at, nextAt: d.reconciliation.next_at },
  timeline: d.timeline.map((e) => ({ at: e.at, type: e.type, source: e.source, from: e.from, to: e.to })),
  webhookEvents: d.webhook_events.map((e) => ({ id: e.id, type: e.type, receivedAt: e.received_at, processingStatus: e.processing_status, outcome: e.outcome, deliveries: e.deliveries })),
})

const A = { context: 'admin' as const }

export class ApiAdminPaymentRepository {
  async list(f: PaymentFilter): Promise<Page<BackendPayment>> {
    const res = await api<ListDto>('/admin/payments', { ...A, query: {
      q: f.query?.trim() || undefined,
      'filter[status]': f.status && f.status !== 'all' ? f.status : undefined,
      'filter[method]': f.method && f.method !== 'all' ? f.method : undefined,
      'filter[attention]': f.attention ? 'true' : undefined,
      'page[number]': f.page ?? 1, 'page[size]': f.pageSize ?? 10, sort: '-created_at',
    } })
    return { items: res.data.map(row), total: res.meta.total, page: res.meta.current_page, pageSize: res.meta.per_page }
  }

  async get(id: string): Promise<BackendPaymentDetail> {
    return detail((await api<{ payment: DetailDto }>(`/admin/payments/${encodeURIComponent(id)}`, A)).payment)
  }

  /** Asks the provider again. The body carries a reason and nothing else — there is nothing else that could be sent. */
  async reconcile(id: string, reason: string): Promise<BackendPaymentDetail> {
    return detail((await api<{ payment: DetailDto }>(`/admin/payments/${encodeURIComponent(id)}/reconcile`, { ...A, method: 'POST', body: { reason } })).payment)
  }
}

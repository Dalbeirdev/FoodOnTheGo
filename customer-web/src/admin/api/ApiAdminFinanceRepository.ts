import { api, apiDownload } from '../../api/client'
import type { Page } from '../types'

/**
 * Platform finance for administrators, from the backend (Module 36): the overview, restaurant payables, commission
 * plans, orders awaiting terms, settlements, payouts, the payment provider's settlement records, reconciliation
 * differences, adjustments, holds, reports and the ledger (read-only).
 *
 * The answers are used as they arrive (snake_case; money is {amount: minor units, currency}) — nothing is added up in
 * the browser and two currencies are never combined. What an administrator may DO is what the backend offers: every
 * record's detail carries `can`, and a request carries a reason, a confirmation or a reference — never a status, a
 * total, a ledger entry or "paid". There is no call here that sets a settlement or a payout to paid: a settlement is
 * paid by a confirmed payout, and a payout by the provider's own record or by an external reference with its evidence.
 */
export type MoneyDto = { amount: number; currency: string }
type Named = { id: string; name: string }
type ListDto<T> = { data: T[]; meta: { total: number; current_page: number; per_page: number } }
/** A page of rows plus whatever the endpoint sends beside it (queues, the provider banner, the policy). */
export type FinList<T, X = unknown> = Page<T> & X
export type Counts = Record<string, number>

export type FinanceOverviewDto = {
  period: { from: string; to: string; timezone: string }
  currencies: Array<{ currency: string; period: Record<string, MoneyDto | number>; standing: Record<string, MoneyDto> }>
  queues: { settlements: Counts; payouts: Counts; differences_open: { total: number; by_severity: Counts }; allocations_awaiting_terms: number; liability_decisions_pending: number; adjustments_awaiting_approval: number; holds_active: number }
  provider_fees: { source: string; estimated: boolean; records_imported: number; note: string }
  terms: Record<string, string>
  health: { ledger: string; internal_reconciliation: string; provider_reconciliation: string; payout_provider: string; live_payouts: string; queue: string }
}
export type RunDto = { id: string; kind: string; status: string; triggered_by: string; dry_run: boolean; parameters: unknown; result: unknown; problems: number; started_at: string | null; finished_at: string | null }

export type SettlementDto = {
  id: string; reference: string; restaurant: { id: string | null; name: string }; currency: string; status: string
  period: { start: string | null; end: string | null; timezone: string; cadence: string }
  summary: {
    completed_orders: number; lines: number; gross_sales: MoneyDto; restaurant_discounts: MoneyDto; commission: MoneyDto; tax_passed: MoneyDto; fees_passed: MoneyDto
    refund_adjustments: MoneyDto; other_adjustments: MoneyDto; net_payable: MoneyDto; amount_paid: MoneyDto; amount_pending: MoneyDto
  }
  document: { kind: string; tax_invoice: boolean }; generated_at: string | null; approved_at: string | null; paid_at: string | null
  market: string | null; organization: Named | null; review_reason: string | null; generated_by: { type: string; admin: Named | null }
  approved_by: Named | null; approval_note: string | null; cancelled_at: string | null; cancelled_by: Named | null; cancelled_reason: string | null; version: number
}
export type SettlementLineDto = {
  line: number; type: string; description: string; order_number: string | null; source: string | null
  gross_sales: MoneyDto; restaurant_discount: MoneyDto; commission: MoneyDto; tax_passed: MoneyDto; fee_passed: MoneyDto; amount: MoneyDto; occurred_at: string | null
}
export type PayoutDto = {
  id: string; reference: string; settlement: { id: string; reference: string } | null; restaurant: { id: string | null; name: string }
  amount: MoneyDto; currency: string; status: string; method: string; destination: string | null; requested_at: string | null; completed_at: string | null; failed_at: string | null
  market: string | null; provider: string; provider_mode: string | null; test_only: boolean; provider_payout_reference: string | null; provider_status: string | null
  needs_review: boolean; review_reason: string | null; failure: { code: string; message: string | null } | null
  external: { reference: string | null; paid_on: string | null; evidence_reference: string | null } | null; note: string | null; requested_by: Named | null
  submit_attempts: number; submitted_at: string | null; processing_at: string | null; last_reconciled_at: string | null; next_action_at: string | null; version: number
}
export type SettlementCan = { approve: boolean; cancel: boolean; start_payout: boolean; record_external_payout: boolean; export: boolean }
export type SettlementDetailDto = SettlementDto & { lines: SettlementLineDto[]; payouts: PayoutDto[]; can: SettlementCan; live_payouts: string }
export type PayoutAction = 'reconcile' | 'retry' | 'cancel'
export type PayoutDetailDto = PayoutDto & { can: Record<PayoutAction, boolean> }
export type PayoutProviderDto = { status: string; driver: string | null; mode: string | null; live_payout: string; real_adapter: string; provider_account: string }
export type GenerateResultDto = {
  dry_run: boolean; summary: Counts; note: string; run: RunDto | null
  results: Array<{ location: string; restaurant: string; currency: string; period_end: string; outcome: string; line_count: number; net_payable: MoneyDto | null; settlement: string | null; reference?: string }>
}
export type ExternalPayoutInput = { externalReference: string; paidOn: string; amountMinor: number; currency: string; reason: string; evidenceReference: string }

export type PayoutProfileDto = { status: string; provider: string | null; destination: string | null; verified_at: string | null; test_only: boolean | null }
export type PayableDto = {
  restaurant: { id: string; name: string; organization: string }; market: string | null; currency: string
  balance: MoneyDto; available: MoneyDto; held: MoneyDto; in_settlement: MoneyDto; paid_out: MoneyDto; open_lines: number
  location_held: boolean; negative: boolean; finance_review_required: boolean; payout_destination: PayoutProfileDto
}

export type PlanDto = {
  id: string; name: string; scope: string; market: string | null; organization: Named | null; location: Named | null; type: string; rate_bps: number; base_policy: string
  effective_from: string | null; effective_until: string | null; state: string; approval: string; approval_reference: string | null; source: string; reason: string
  created_by: Named | null; withdrawn_at: string | null; withdrawn_reason: string | null; version: number; created_at: string | null
}
export type PlanDetailDto = PlanDto & { orders_allocated: number; can: { end: boolean; withdraw: boolean } }
export type PlanInput = { name: string; market: string; restaurant: string | null; rateBps: number; basePolicy: string; effectiveFrom: string; effectiveUntil: string | null; approval: 'TEST' | 'APPROVED'; approvalReference: string | null; reason: string }
export const BASE_POLICIES = ['MERCHANDISE_GROSS', 'MERCHANDISE_NET_OF_RESTAURANT_DISCOUNT', 'MERCHANDISE_NET_OF_ALL_DISCOUNTS'] as const

export type DifferenceDto = {
  id: string; source: string; type: string; severity: string; status: string; provider: string | null; provider_reference: string | null
  subject: { type: string; id: string | null; reference: string | null } | null; market: string | null; restaurant: Named | null; currency: string | null
  expected: MoneyDto | null; actual: MoneyDto | null; difference: MoneyDto | null; first_seen_at: string | null; last_seen_at: string | null; seen_count: number
  resolution: string | null; resolution_note: string | null; assigned_to: Named | null; resolved_by: Named | null; resolved_at: string | null; version: number
}
export type DifferenceAction = 'investigate' | 'resolve' | 'ignore'
export type DifferenceDetailDto = DifferenceDto & { detail: unknown; can: Record<DifferenceAction, boolean>; resolutions: string[] }
export type ProviderSettlementDto = {
  id: string; provider: string; provider_mode: string | null; reference: string; currency: string; status: string; amount: MoneyDto; fees: MoneyDto; tax_on_fees: MoneyDto; bank_reference: string | null
  lines: { count: number; credits: MoneyDto; debits: MoneyDto; fees: MoneyDto; tax_on_fees: MoneyDto }; reconciliation_status: string; posted_to_ledger: boolean
  provider_created_at: string | null; settled_at: string | null; reconciled_at: string | null; imported_at: string | null
}
export type ProviderSettlementItemDto = {
  id: string; type: string; reference: string; payment_reference: string | null; currency: string; amount: MoneyDto; credit: MoneyDto; debit: MoneyDto; fee: MoneyDto; tax_on_fee: MoneyDto
  settled: boolean; match_status: string; matched_payment: string | null; matched_refund: string | null; fee_posted: boolean; settled_at: string | null
}
export type ProviderSettlementDetailDto = ProviderSettlementDto & { items: ProviderSettlementItemDto[]; items_truncated: boolean }
export type SettlementSourceDto = { payment_driver: string | null; status: string; mode: string | null; razorpay_settlement_api: string; scheduled: boolean }
export type CheckKind = 'LEDGER' | 'INTERNAL' | 'PROVIDER'
export type CheckResultDto = { check: CheckKind; run?: RunDto; result?: unknown }

export type AdjustmentDto = {
  id: string; kind: string; status: string; restaurant: Named | null; market: string | null; order_number: string | null; source: { type: string; id: string } | null; currency: string
  amount: MoneyDto; direction: string | null; liability: string; restaurant_share: MoneyDto | null; platform_share: MoneyDto | null; policy_source: string | null
  reason_code: string; reason: string | null; supporting_reference: string | null; created_by: Named | null; decided_by: Named | null; decided_at: string | null; posted_at: string | null
  ledger_transaction: string | null; created_at: string | null; version: number
}
export type AdjustmentDetailDto = AdjustmentDto & { can: { decide: boolean; approve: boolean; cancel: boolean } }
export type AdjustmentPolicyDto = { refund_liability_default: string; four_eyes: boolean; reasons: string[]; approval: string }
export type AdjustmentInput = { restaurant: string; direction: 'CREDIT_RESTAURANT' | 'DEBIT_RESTAURANT'; amountMinor: number; currency: string; reasonCode: string; reason: string; supportingReference: string; order: string | null }
export type HoldDto = {
  id: string; scope: string; restaurant: Named | null; market: string | null; line: { id: string; order_number: string | null; amount: MoneyDto } | null; reason: string; status: string; source: string
  note: string | null; placed_by: Named | null; placed_at: string | null; released_by: Named | null; released_at: string | null; release_reason: string | null; version: number
}
/** An order's frozen financial terms. Without the commission permission the backend leaves only the commission's amount. */
export type AllocationDto = {
  id: string; order: { id: string; number: string }; restaurant: Named | null; market: string | null; status: string; review_reason: string | null; currency: string
  customer_total: MoneyDto; merchandise_gross: MoneyDto; discount: MoneyDto
  promotion: { code: string | null; funding: string; funding_basis: string; restaurant_funded: MoneyDto; platform_funded: MoneyDto }
  tax: { total: MoneyDto; owner: string }; fees: { total: MoneyDto; owner: string }
  commission: { amount: MoneyDto; source?: string; rate_bps?: number; base_policy?: string | null; base?: MoneyDto } | null
  restaurant_net: MoneyDto | null; platform_net: MoneyDto | null; decision_reason: string | null; placed_at: string | null; recognized_at: string | null; voided_at: string | null; version: number
}
/** The reasons an order waits for terms that ask for the restaurant's share of the discount as well. */
export const PROMOTION_REVIEW_REASONS = ['PROMOTION_FUNDING_UNKNOWN', 'SHARED_PROMOTION_SPLIT_UNDEFINED']
export type AllocationDecision = { rateBps: number; basePolicy: string; restaurantDiscountMinor: number | null; reason: string }

export type ReportPeriodDto = { from: string; to: string; timezone: string }
export const COMMISSION_GROUPS = ['day', 'location', 'organization', 'market', 'plan'] as const
export type CommissionGroup = (typeof COMMISSION_GROUPS)[number]
export type CommissionReportDto = {
  period: ReportPeriodDto; group_by: CommissionGroup
  rows: Array<{ group: string; key: string | null; label: string | null; currency: string; orders: number; gross_marketplace_sales: MoneyDto; commission_base: MoneyDto; commission: MoneyDto; restaurant_earnings: MoneyDto
    platform_funded_discount: MoneyDto; restaurant_funded_discount: MoneyDto; rate_bps?: number; base_policy?: string }>
}
export type RefundImpactReportDto = {
  period: ReportPeriodDto; liability_policy: { default: string; configured_reasons: string[]; approval: string }
  rows: Array<{ currency: string; refund_count: number; refund_amount: MoneyDto; returned_before_recognition: MoneyDto; restaurant_funded: MoneyDto; platform_funded: MoneyDto; awaiting_decision: MoneyDto; awaiting_decision_count: number }>
}
export type ProviderCostReportDto = {
  period: ReportPeriodDto; source: string; estimated: boolean; policy: string
  rows: Array<{ day: string; provider: string; currency: string; payments: number; refunds: number; payment_amount: MoneyDto; fee: MoneyDto; tax_on_fee: MoneyDto; fee_lines_posted: number }>
}

export type LedgerAccountDto = { id: string; code: string; owner: string; restaurant: Named | null; provider: string | null; currency: string; normal_side: 'DEBIT' | 'CREDIT'; debits: MoneyDto; credits: MoneyDto; balance: MoneyDto }
export type LedgerTransactionDto = {
  id: string; posting_key: string; type: string; source: { type: string; id: string | null; reference: string | null }; currency: string; amount: MoneyDto; market: string | null; restaurant: Named | null
  memo: string | null; reverses: string | null; occurred_at: string | null; posted_at: string | null
}
export type LedgerEntryDto = { line: number; account: { id: string; code: string; owner: string; provider: string | null }; direction: 'DEBIT' | 'CREDIT'; amount: MoneyDto; description: string }
export type LedgerTransactionDetailDto = LedgerTransactionDto & { detail: unknown; entries: LedgerEntryDto[]; balanced: boolean }
export const LEDGER_TRANSACTION_TYPES = ['PAYMENT_VERIFIED', 'ORDER_RECOGNIZED', 'REFUND_COMPLETED', 'REFUND_LIABILITY', 'DISPUTE_LOST', 'DISPUTE_LIABILITY', 'PAYOUT_PAID', 'PAYOUT_REVERSED', 'PROVIDER_FEE', 'PROVIDER_SETTLEMENT_RECEIVED', 'MANUAL_ADJUSTMENT', 'REVERSAL'] as const
export type LedgerFilter = ListFilter & { type?: string; reference?: string; from?: string; to?: string }

/** The reasons a person may give for a hold (the system's own REFUND_PENDING is not one of them). */
export const HOLD_REASONS = ['DISPUTE', 'COMPLIANCE', 'RECONCILIATION', 'MANUAL_REVIEW'] as const

export type ListFilter = { page?: number; pageSize?: number; queue?: string; query?: string }

const A = { context: 'admin' as const }
const F = '/admin/finance'
const id = (v: string) => encodeURIComponent(v)
const some = (v?: string | null) => (v && v !== 'all' ? v : undefined)

export class ApiAdminFinanceRepository {
  private async list<T, X = unknown>(path: string, f: ListFilter, filters: Record<string, string | undefined> = {}): Promise<FinList<T, X>> {
    const res = await api<ListDto<T> & X>(`${F}${path}`, { ...A, query: { ...filters, 'page[number]': f.page ?? 1, 'page[size]': f.pageSize ?? 10 } })
    return { ...res, items: res.data, total: res.meta.total, page: res.meta.current_page, pageSize: res.meta.per_page }
  }

  /* ---------------------------------------------------------------- overview and payables */

  overview(from?: string, to?: string): Promise<FinanceOverviewDto> {
    return api<FinanceOverviewDto>(`${F}/overview`, { ...A, query: { from: from || undefined, to: to || undefined } })
  }

  payables(f: ListFilter): Promise<FinList<PayableDto>> {
    return this.list<PayableDto>('/payables', f, { 'filter[q]': f.query?.trim() || undefined })
  }

  /* ---------------------------------------------------------------- settlements */

  settlements(f: ListFilter): Promise<FinList<SettlementDto, { queues: Counts }>> {
    return this.list<SettlementDto, { queues: Counts }>('/settlements', f, { 'filter[queue]': some(f.queue) })
  }

  async settlement(settlement: string): Promise<SettlementDetailDto> {
    return (await api<{ settlement: SettlementDetailDto }>(`${F}/settlements/${id(settlement)}`, A)).settlement
  }

  /** Calculates the settlements that are due. With dryRun nothing is written: the calculation is the answer. It sends no money. */
  generate(input: { restaurant?: string | null; until?: string | null; dryRun: boolean }): Promise<GenerateResultDto> {
    const body: Record<string, unknown> = { dry_run: input.dryRun }
    if (input.restaurant) body.restaurant = input.restaurant
    if (input.until) body.until = input.until
    return api<GenerateResultDto>(`${F}/settlements/generate`, { ...A, method: 'POST', body })
  }

  async approveSettlement(settlement: string, note: string | null, expectedVersion: number): Promise<SettlementDetailDto> {
    const body: Record<string, unknown> = { expected_version: expectedVersion }
    if (note) body.note = note
    return (await api<{ settlement: SettlementDetailDto }>(`${F}/settlements/${id(settlement)}/approve`, { ...A, method: 'POST', body })).settlement
  }

  async cancelSettlement(settlement: string, reason: string): Promise<SettlementDetailDto> {
    return (await api<{ settlement: SettlementDetailDto }>(`${F}/settlements/${id(settlement)}/cancel`, { ...A, method: 'POST', body: { reason } })).settlement
  }

  exportSettlement(settlement: string, reference: string): Promise<string> {
    return apiDownload(`${F}/settlements/${id(settlement)}/export`, { ...A, filename: `settlement-${reference}.csv` })
  }

  /* ---------------------------------------------------------------- payouts */

  /** Sends the payout of an approved settlement to the payout provider. The amount is the settlement's: none is sent. */
  async startPayout(settlement: string, note: string | null): Promise<PayoutDetailDto> {
    const body: Record<string, unknown> = { confirm: true }
    if (note) body.note = note
    return (await api<{ payout: PayoutDetailDto }>(`${F}/settlements/${id(settlement)}/payouts`, { ...A, method: 'POST', body })).payout
  }

  /** Records a payout made outside the platform. The amount and currency are stated only to be CONFIRMED against the settlement. */
  async recordExternalPayout(settlement: string, input: ExternalPayoutInput): Promise<PayoutDetailDto> {
    return (await api<{ payout: PayoutDetailDto }>(`${F}/settlements/${id(settlement)}/external-payouts`, { ...A, method: 'POST', body: {
      confirm: true, external_reference: input.externalReference, paid_on: input.paidOn, amount_minor: input.amountMinor, currency: input.currency, reason: input.reason, evidence_reference: input.evidenceReference,
    } })).payout
  }

  payouts(f: ListFilter): Promise<FinList<PayoutDto, { queues: Counts; provider: PayoutProviderDto }>> {
    return this.list<PayoutDto, { queues: Counts; provider: PayoutProviderDto }>('/payouts', f, { 'filter[queue]': some(f.queue) })
  }

  async payout(payout: string): Promise<PayoutDetailDto> {
    return (await api<{ payout: PayoutDetailDto }>(`${F}/payouts/${id(payout)}`, A)).payout
  }

  /** Ask the provider again, send again (confirmed), or withdraw a payout that was never sent — each with a reason, nothing else. */
  async payoutAction(payout: string, action: PayoutAction, reason: string): Promise<PayoutDetailDto> {
    return (await api<{ payout: PayoutDetailDto }>(`${F}/payouts/${id(payout)}/${action}`, { ...A, method: 'POST', body: action === 'retry' ? { reason, confirm: true } : { reason } })).payout
  }

  /* ---------------------------------------------------------------- commission plans (never edited) */

  plans(f: ListFilter): Promise<FinList<PlanDto>> {
    return this.list<PlanDto>('/commission-plans', f)
  }

  async plan(plan: string): Promise<PlanDetailDto> {
    return (await api<{ plan: PlanDetailDto }>(`${F}/commission-plans/${id(plan)}`, A)).plan
  }

  async createPlan(input: PlanInput): Promise<PlanDetailDto> {
    const body: Record<string, unknown> = { name: input.name, market: input.market, rate_bps: input.rateBps, base_policy: input.basePolicy, effective_from: input.effectiveFrom, approval: input.approval, reason: input.reason }
    if (input.restaurant) body.restaurant = input.restaurant
    if (input.effectiveUntil) body.effective_until = input.effectiveUntil
    if (input.approval === 'APPROVED') body.approval_reference = input.approvalReference
    return (await api<{ plan: PlanDetailDto }>(`${F}/commission-plans`, { ...A, method: 'POST', body })).plan
  }

  async endPlan(plan: string, effectiveUntil: string, reason: string, expectedVersion: number): Promise<PlanDetailDto> {
    return (await api<{ plan: PlanDetailDto }>(`${F}/commission-plans/${id(plan)}/end`, { ...A, method: 'POST', body: { effective_until: effectiveUntil, reason, expected_version: expectedVersion } })).plan
  }

  async withdrawPlan(plan: string, reason: string): Promise<PlanDetailDto> {
    return (await api<{ plan: PlanDetailDto }>(`${F}/commission-plans/${id(plan)}/withdraw`, { ...A, method: 'POST', body: { reason } })).plan
  }

  /* ---------------------------------------------------------------- reconciliation */

  differences(f: ListFilter & { open?: boolean }): Promise<FinList<DifferenceDto, { open: { total: number; by_severity: Counts } }>> {
    return this.list<DifferenceDto, { open: { total: number; by_severity: Counts } }>('/differences', f, { 'filter[open]': f.open === false ? 'false' : 'true' })
  }

  async difference(difference: string): Promise<DifferenceDetailDto> {
    return (await api<{ difference: DifferenceDetailDto }>(`${F}/differences/${id(difference)}`, A)).difference
  }

  async differenceAction(difference: string, action: DifferenceAction, input: { resolution?: string; note?: string | null }): Promise<DifferenceDetailDto> {
    const body: Record<string, unknown> = {}
    if (action === 'resolve') body.resolution = input.resolution
    if (input.note) body.note = input.note
    return (await api<{ difference: DifferenceDetailDto }>(`${F}/differences/${id(difference)}/${action}`, { ...A, method: 'POST', body })).difference
  }

  providerSettlements(f: ListFilter): Promise<FinList<ProviderSettlementDto, { source: SettlementSourceDto; last_import: RunDto | null }>> {
    return this.list<ProviderSettlementDto, { source: SettlementSourceDto; last_import: RunDto | null }>('/provider-settlements', f)
  }

  async providerSettlement(providerSettlement: string): Promise<ProviderSettlementDetailDto> {
    return (await api<{ provider_settlement: ProviderSettlementDetailDto }>(`${F}/provider-settlements/${id(providerSettlement)}`, A)).provider_settlement
  }

  async importDay(day: string): Promise<RunDto> {
    return (await api<{ run: RunDto }>(`${F}/provider-settlements/import`, { ...A, method: 'POST', body: { day } })).run
  }

  /** A check reads and reports; it repairs nothing. */
  runCheck(check: CheckKind): Promise<CheckResultDto> {
    return api<CheckResultDto>(`${F}/checks`, { ...A, method: 'POST', body: { check } })
  }

  /* ---------------------------------------------------------------- adjustments and holds */

  adjustments(f: ListFilter): Promise<FinList<AdjustmentDto, { policy: AdjustmentPolicyDto }>> {
    return this.list<AdjustmentDto, { policy: AdjustmentPolicyDto }>('/adjustments', f)
  }

  async adjustment(adjustment: string): Promise<AdjustmentDetailDto> {
    return (await api<{ adjustment: AdjustmentDetailDto }>(`${F}/adjustments/${id(adjustment)}`, A)).adjustment
  }

  async createAdjustment(input: AdjustmentInput, idempotencyKey: string): Promise<AdjustmentDetailDto> {
    const body: Record<string, unknown> = { restaurant: input.restaurant, direction: input.direction, amount_minor: input.amountMinor, currency: input.currency, reason_code: input.reasonCode, reason: input.reason, supporting_reference: input.supportingReference }
    if (input.order) body.order = input.order
    return (await api<{ adjustment: AdjustmentDetailDto }>(`${F}/adjustments`, { ...A, method: 'POST', body, idempotencyKey })).adjustment
  }

  /** Who bears a completed refund or a lost dispute: the restaurant's share, stated explicitly, with the version the screen showed. */
  async decideAdjustment(adjustment: string, restaurantShareMinor: number, reason: string, expectedVersion: number): Promise<AdjustmentDetailDto> {
    return (await api<{ adjustment: AdjustmentDetailDto }>(`${F}/adjustments/${id(adjustment)}/decide`, { ...A, method: 'POST', body: { restaurant_share_minor: restaurantShareMinor, reason, expected_version: expectedVersion } })).adjustment
  }

  async adjustmentAction(adjustment: string, action: 'approve' | 'cancel', reason: string): Promise<AdjustmentDetailDto> {
    return (await api<{ adjustment: AdjustmentDetailDto }>(`${F}/adjustments/${id(adjustment)}/${action}`, { ...A, method: 'POST', body: { reason } })).adjustment
  }

  /* ---------------------------------------------------------------- orders awaiting terms */

  /** Orders whose commercial terms were unknown when they were placed: they wait for a person. */
  allocations(f: ListFilter & { status?: string }): Promise<FinList<AllocationDto>> {
    return this.list<AllocationDto>('/allocations', f, { 'filter[status]': some(f.status) })
  }

  /** Decides the terms of ONE order: a rate, what it is taken from and (for a promotion nobody was named for) the restaurant's share of the discount. The order's own amounts are never sent. */
  async decideAllocation(allocation: string, input: AllocationDecision): Promise<AllocationDto> {
    const body: Record<string, unknown> = { rate_bps: input.rateBps, base_policy: input.basePolicy, reason: input.reason }
    if (input.restaurantDiscountMinor !== null) body.restaurant_discount_minor = input.restaurantDiscountMinor
    return (await api<{ allocation: AllocationDto }>(`${F}/allocations/${id(allocation)}/decide`, { ...A, method: 'POST', body })).allocation
  }

  /* ---------------------------------------------------------------- reports (read-only) */

  commissionReport(from: string, to: string, groupBy: CommissionGroup): Promise<CommissionReportDto> {
    return api<CommissionReportDto>(`${F}/reports/commission`, { ...A, query: { from: from || undefined, to: to || undefined, group_by: groupBy } })
  }

  refundImpactReport(from: string, to: string): Promise<RefundImpactReportDto> {
    return api<RefundImpactReportDto>(`${F}/reports/customer-returns`, { ...A, query: { from: from || undefined, to: to || undefined } })
  }

  /** Provider records carry no market: an administrator whose grant is for one market is refused (403). */
  providerCostReport(from: string, to: string): Promise<ProviderCostReportDto> {
    return api<ProviderCostReportDto>(`${F}/reports/provider-costs`, { ...A, query: { from: from || undefined, to: to || undefined } })
  }

  /* ---------------------------------------------------------------- ledger (read-only: there is no call that writes one) */

  ledgerAccounts(f: ListFilter): Promise<FinList<LedgerAccountDto>> {
    return this.list<LedgerAccountDto>('/ledger/accounts', f)
  }

  ledgerTransactions(f: LedgerFilter): Promise<FinList<LedgerTransactionDto>> {
    return this.list<LedgerTransactionDto>('/ledger/transactions', f, { 'filter[type]': some(f.type), 'filter[reference]': f.reference?.trim() || undefined, 'filter[from]': f.from || undefined, 'filter[to]': f.to || undefined })
  }

  async ledgerTransaction(transaction: string): Promise<LedgerTransactionDetailDto> {
    return (await api<{ transaction: LedgerTransactionDetailDto }>(`${F}/ledger/transactions/${id(transaction)}`, A)).transaction
  }

  /** The record of finance jobs: when the ledger was last checked, what a reconciliation found. */
  runs(f: ListFilter): Promise<FinList<RunDto>> {
    return this.list<RunDto>('/runs', f)
  }

  holds(f: ListFilter): Promise<FinList<HoldDto>> {
    return this.list<HoldDto>('/holds', f)
  }

  /** A hold moves no money: it keeps a location's (or one line's) earnings out of the next settlement. */
  async placeHold(input: { restaurant: string; line: string | null; reason: string; note: string }): Promise<HoldDto> {
    const body: Record<string, unknown> = { restaurant: input.restaurant, reason: input.reason, note: input.note }
    if (input.line) body.line = input.line
    return (await api<{ hold: HoldDto }>(`${F}/holds`, { ...A, method: 'POST', body })).hold
  }

  async releaseHold(hold: string, reason: string): Promise<HoldDto> {
    return (await api<{ hold: HoldDto }>(`${F}/holds/${id(hold)}/release`, { ...A, method: 'POST', body: { reason } })).hold
  }
}

/**
 * Platform Admin finance on the backend (Module 36). The network is stubbed with payloads in the shape of
 * GET /admin/finance/*; the tests check what is SENT (a reason, a confirmation, a reference — never a status, a total
 * or "paid"), what the page shows (the backend's figures, one block per currency), that a tab exists only for a
 * permission that is held, and that a control exists only when the backend's `can` says so.
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { tokens } from '../../api/client'
import { adminPermissionsFromApi } from '../../auth/staff/staffAuth'
import { setMockDashboardLatency } from '../../dashboard/mock/mockDashboard'
import { LocaleProvider } from '../../i18n/LocaleProvider'
import { AdminProvider } from '../AdminContext'
import AdminLayout from '../AdminLayout'
import { K, adminPermissionsForRole, adminRepositories, resetAdminStores, setMockAdminLatency } from '../mock/mockAdmin'
import { BackendFinancePage } from '../pages/BackendFinancePage'
import { amountToMinor, bpsToPercent, percentToBps } from '../pages/finance/helpers'
import type { AdminPermission } from '../types'
import { ApiAdminFinanceRepository } from './ApiAdminFinanceRepository'

const BASE = 'http://127.0.0.1:8001/api/v1'; const F = '/admin/finance'
const S1 = '0c000000-0000-4000-8000-000000000001'; const P1 = '0e000000-0000-4000-8000-000000000001'; const L1 = '0a000000-0000-4000-8000-0000000000a1'
const PLAN = '0f000000-0000-4000-8000-000000000001'; const D1 = '1a000000-0000-4000-8000-000000000001'; const ADJ = '1b000000-0000-4000-8000-000000000001'; const H1 = '1c000000-0000-4000-8000-000000000001'
const inr = (amount: number) => ({ amount, currency: 'INR' }); const usd = (amount: number) => ({ amount, currency: 'USD' })
const page = (data: unknown[], extra: Record<string, unknown> = {}) => ({ data, meta: { total: data.length, current_page: 1, per_page: 10 }, ...extra })
const FINANCE_CODES = ['view', 'commission.view', 'commission.manage', 'settlements.manage', 'settlements.approve', 'payouts.view', 'payouts.execute', 'payouts.record_external', 'reconciliation.view', 'reconciliation.manage', 'adjustments.create', 'holds.manage', 'ledger.view', 'export'].map((c) => `finance.${c}` as AdminPermission)

const OVERVIEW = {
  period: { from: '2026-09-13', to: '2026-10-12', timezone: 'Asia/Kolkata' },
  currencies: [
    { currency: 'INR', period: { customer_payments: inr(5000000), refunds: inr(50000), gross_marketplace_sales: inr(4500000), platform_commission: inr(562500), platform_fee_revenue: inr(20000), platform_revenue: inr(582500), promotion_expense: inr(10000), refund_expense: inr(0), restaurant_earnings: inr(3937500), provider_fees: inr(0), provider_fee_tax: inr(0), provider_settlements_received: inr(0), restaurant_payouts: inr(900000), orders_recognized: 90 },
      standing: { restaurant_payables: inr(443450), payment_clearing: inr(120000), provider_clearing: inr(0), refund_clearing: inr(5000), dispute_clearing: inr(0), tax_held: inr(225000) } },
    { currency: 'USD', period: { customer_payments: usd(1400), refunds: usd(0), gross_marketplace_sales: usd(1200), platform_commission: usd(200), platform_fee_revenue: usd(0), platform_revenue: usd(200), promotion_expense: usd(0), refund_expense: usd(0), restaurant_earnings: usd(1000), provider_fees: usd(0), provider_fee_tax: usd(0), provider_settlements_received: usd(0), restaurant_payouts: usd(0), orders_recognized: 1 },
      standing: { restaurant_payables: usd(1000), payment_clearing: usd(0), provider_clearing: usd(0), refund_clearing: usd(0), dispute_clearing: usd(0), tax_held: usd(0) } },
  ],
  queues: { settlements: { CALCULATED: 2, APPROVED: 1 }, payouts: [], differences_open: { total: 3, by_severity: { HIGH: 1, MEDIUM: 2 } }, allocations_awaiting_terms: 1, liability_decisions_pending: 2, adjustments_awaiting_approval: 0, holds_active: 1 },
  provider_fees: { source: 'PROVIDER_REPORTED', estimated: false, records_imported: 0, note: 'Provider fees are posted only from the provider\'s own settlement records. Without an import the figure is zero — it is not an estimate.' },
  terms: { gross_marketplace_sales: 'Merchandise restaurants sold through the platform. It is not FoodOnTheGo revenue.' },
  health: { ledger: 'BALANCED', internal_reconciliation: 'OK', provider_reconciliation: 'FAKE_PROVIDER', payout_provider: 'FAKE_PROVIDER', live_payouts: 'DISABLED', queue: 'UP_TO_DATE' },
}
const NO_CAN = { approve: false, cancel: false, start_payout: false, record_external_payout: false, export: false }
const settlement = (patch: Record<string, unknown> = {}) => ({
  id: S1, reference: 'STL-20261012-0001', restaurant: { id: L1, name: 'Burger Hub' }, currency: 'INR', status: 'CALCULATED', period: { start: '2026-10-05T18:30:00+00:00', end: '2026-10-12T18:30:00+00:00', timezone: 'Asia/Kolkata', cadence: 'WEEKLY' },
  summary: { completed_orders: 9, lines: 1, gross_sales: inr(450000), restaurant_discounts: inr(0), commission: inr(56250), tax_passed: inr(0), fees_passed: inr(0), refund_adjustments: inr(-5000), other_adjustments: inr(0), net_payable: inr(388750), amount_paid: inr(0), amount_pending: inr(388750) },
  document: { kind: 'SETTLEMENT_STATEMENT', tax_invoice: false }, generated_at: '2026-10-12T19:00:00+00:00', approved_at: null, paid_at: null,
  market: 'india', organization: { id: 'org-1', name: 'Burger Hub Foods' }, review_reason: null, generated_by: { type: 'ADMIN', admin: { id: 'a1', name: 'Kenji Watanabe' } }, approved_by: null, approval_note: null, cancelled_at: null, cancelled_by: null, cancelled_reason: null, version: 1,
  lines: [{ line: 1, type: 'ORDER_EARNING', description: 'ORDER_EARNING', order_number: 'FOTG-20261012-ABC234', source: null, gross_sales: inr(50000), restaurant_discount: inr(0), commission: inr(6250), tax_passed: inr(0), fee_passed: inr(0), amount: inr(43750), occurred_at: '2026-10-12T05:00:00+00:00' }],
  payouts: [], can: { ...NO_CAN, approve: true, cancel: true, export: true }, live_payouts: 'DISABLED', ...patch,
})
const payout = (patch: Record<string, unknown> = {}) => ({
  id: P1, reference: 'PO-0E00000000', settlement: { id: S1, reference: 'STL-20261012-0001' }, restaurant: { id: L1, name: 'Burger Hub' }, amount: inr(388750), currency: 'INR', status: 'PROCESSING', method: 'PROVIDER', destination: '•••• 4321',
  requested_at: '2026-10-12T21:00:00+00:00', completed_at: null, failed_at: null, market: 'india', provider: 'fake', provider_mode: 'FAKE', test_only: true, provider_payout_reference: 'fakepout_aaaa', provider_status: 'processing', needs_review: false, review_reason: null, failure: null, external: null, note: null,
  requested_by: { id: 'a1', name: 'Kenji Watanabe' }, submit_attempts: 1, submitted_at: '2026-10-12T21:00:05+00:00', processing_at: '2026-10-12T21:00:06+00:00', last_reconciled_at: null, next_action_at: null, version: 2, can: { reconcile: true, retry: false, cancel: false }, ...patch,
})
const PROVIDER = { status: 'FAKE_PROVIDER', driver: 'fake', mode: 'FAKE', live_payout: 'DISABLED', real_adapter: 'NOT_IMPLEMENTED', provider_account: 'PENDING PROVIDER ACCOUNT ENABLEMENT' }
const plan = (patch: Record<string, unknown> = {}) => ({
  id: PLAN, name: 'India standard (fixture)', scope: 'MARKET', market: 'india', organization: null, location: null, type: 'PERCENTAGE', rate_bps: 1250, base_policy: 'MERCHANDISE_NET_OF_RESTAURANT_DISCOUNT', effective_from: '2026-10-01T00:00:00+00:00', effective_until: null, state: 'ACTIVE',
  approval: 'TEST', approval_reference: null, source: 'FIXTURE', reason: 'Development fixture for local testing', created_by: null, withdrawn_at: null, withdrawn_reason: null, version: 1, created_at: '2026-10-01T00:00:00+00:00', orders_allocated: 12, can: { end: true, withdraw: false }, ...patch,
})
const difference = (patch: Record<string, unknown> = {}) => ({
  id: D1, source: 'PROVIDER', type: 'AMOUNT_MISMATCH', severity: 'HIGH', status: 'OPEN', provider: 'fake', provider_reference: 'fakepay_aaaa', subject: { type: 'PAYMENT', id: 'p1', reference: 'PAY-0B00000000' }, market: 'india', restaurant: { id: L1, name: 'Burger Hub' }, currency: 'INR',
  expected: inr(50000), actual: inr(49000), difference: inr(-1000), first_seen_at: '2026-10-12T05:00:00+00:00', last_seen_at: '2026-10-12T06:00:00+00:00', seen_count: 2, resolution: null, resolution_note: null, assigned_to: null, resolved_by: null, resolved_at: null, version: 1,
  detail: { provider_amount_minor: 49000, internal_amount_minor: 50000 }, can: { investigate: true, resolve: true, ignore: true }, resolutions: ['CORRECTED', 'ADJUSTMENT_POSTED', 'PROVIDER_CORRECTED', 'EXPLAINED'], ...patch,
})
const adjustment = (patch: Record<string, unknown> = {}) => ({
  id: ADJ, kind: 'REFUND_LIABILITY', status: 'PENDING_DECISION', restaurant: { id: L1, name: 'Burger Hub' }, market: 'india', order_number: 'FOTG-20261012-ABC234', source: { type: 'REFUND', id: 'r1' }, currency: 'INR', amount: inr(50000), direction: null, liability: 'UNASSIGNED',
  restaurant_share: null, platform_share: null, policy_source: null, reason_code: 'RESTAURANT_CANCELLATION', reason: null, supporting_reference: null, created_by: null, decided_by: null, decided_at: null, posted_at: null, ledger_transaction: null, created_at: '2026-10-12T05:00:00+00:00', version: 3,
  can: { decide: true, approve: false, cancel: false }, ...patch,
})
const HOLD = { id: H1, scope: 'LOCATION', restaurant: { id: L1, name: 'Burger Hub' }, market: 'india', line: null, reason: 'COMPLIANCE', status: 'ACTIVE', source: 'ADMIN', note: 'Documents are being checked', placed_by: { id: 'a1', name: 'Kenji Watanabe' }, placed_at: '2026-10-12T05:00:00+00:00', released_by: null, released_at: null, release_reason: null, version: 1 }
const RUN = { id: 'run-1', kind: 'VERIFY_LEDGER', status: 'OK', triggered_by: 'ADMIN', dry_run: false, parameters: null, result: { transactions_checked: 42, imbalanced: 0 }, problems: 0, started_at: '2026-10-12T06:00:00+00:00', finished_at: '2026-10-12T06:00:01+00:00' }
const AL1 = '1d000000-0000-4000-8000-000000000001'; const AL2 = '1d000000-0000-4000-8000-000000000002'; const TX = '1e000000-0000-4000-8000-000000000001'
const allocation = (patch: Record<string, unknown> = {}) => ({
  id: AL1, order: { id: 'o1', number: 'FOTG-20261012-ABC234' }, restaurant: { id: L1, name: 'Burger Hub' }, market: 'india', status: 'REVIEW_REQUIRED', review_reason: 'NO_COMMISSION_PLAN', currency: 'INR',
  customer_total: inr(52500), merchandise_gross: inr(50000), discount: inr(0), promotion: { code: null, funding: 'NONE', funding_basis: 'NONE', restaurant_funded: inr(0), platform_funded: inr(0) },
  tax: { total: inr(2500), owner: 'UNASSIGNED' }, fees: { total: inr(0), owner: 'PLATFORM' }, commission: null, restaurant_net: null, platform_net: null, decision_reason: null, placed_at: '2026-10-12T05:00:00+00:00', recognized_at: null, voided_at: null, version: 1, ...patch,
})
const PERIOD = { from: '2026-09-13', to: '2026-10-12', timezone: 'Asia/Kolkata' }
const commissionRow = (label: string, m: typeof inr, patch: Record<string, unknown> = {}) => ({ group: 'day', key: label, label, currency: m(0).currency, orders: 9, gross_marketplace_sales: m(450000), commission_base: m(450000), commission: m(56250), restaurant_earnings: m(393750), platform_funded_discount: m(1000), restaurant_funded_discount: m(0), ...patch })
const ACCOUNT = { id: 'acc-1', code: 'RESTAURANT_PAYABLE', owner: 'RESTAURANT', restaurant: { id: L1, name: 'Burger Hub' }, provider: null, currency: 'INR', normal_side: 'CREDIT', debits: inr(900000), credits: inr(1343450), balance: inr(443450) }
const TRANSACTION = { id: TX, posting_key: 'payout:paid:0e000000', type: 'PAYOUT_PAID', source: { type: 'payout', id: P1, reference: 'PO-0E00000000' }, currency: 'INR', amount: inr(388750), market: 'india', restaurant: { id: L1, name: 'Burger Hub' }, memo: null, reverses: null, occurred_at: '2026-10-12T22:00:00+00:00', posted_at: '2026-10-12T22:00:01+00:00' }
const ENTRIES = [
  { line: 1, account: { id: 'acc-1', code: 'RESTAURANT_PAYABLE', owner: 'RESTAURANT', provider: null }, direction: 'DEBIT', amount: inr(388750), description: 'PAYOUT_TO_RESTAURANT' },
  { line: 2, account: { id: 'acc-2', code: 'PAYOUT_CLEARING', owner: 'PROVIDER', provider: 'fake' }, direction: 'CREDIT', amount: inr(388750), description: 'PAYOUT_TO_RESTAURANT' },
]

type Call = { method: string; path: string; body: Record<string, unknown> | null; headers: Record<string, string> }
type Reply = { status?: number; body?: unknown; csv?: string }
let calls: Call[] = []
let routes: Record<string, Reply> = {}
let current: ReturnType<typeof settlement>
let saved: string[] = []
const posted = (suffix: string) => calls.filter((c) => c.method === 'POST' && c.path.endsWith(suffix))
beforeEach(() => {
  calls = []; saved = []; current = settlement()
  localStorage.clear(); sessionStorage.clear(); resetAdminStores(); setMockAdminLatency(0); setMockDashboardLatency(0); tokens.set('admin', 'admin-token')
  routes = {
    [`GET ${F}/overview`]: { body: OVERVIEW },
    [`GET ${F}/settlements`]: { body: page([settlement()], { queues: { calculated: 2, review_required: 0, approved: 1, payment_pending: 0, paid: 4, failed: 0, closed: 0, cancelled: 0 } }) },
    [`GET ${F}/payouts`]: { body: page([payout()], { queues: { required: 0, processing: 1, unknown: 0, review_required: 0, paid: 4, failed: 0, cancelled: 0 }, provider: PROVIDER }) }, [`GET ${F}/payouts/${P1}`]: { body: { payout: payout() } },
    [`GET ${F}/payables`]: { body: page([{ restaurant: { id: L1, name: 'Burger Hub', organization: 'Burger Hub Foods' }, market: 'india', currency: 'INR', balance: inr(-2500), available: inr(-2500), held: inr(0), in_settlement: inr(0), paid_out: inr(900000), open_lines: 1, location_held: true, negative: true, finance_review_required: true, payout_destination: { status: 'NOT_CONFIGURED', provider: null, destination: null, verified_at: null, test_only: null } }]) },
    [`GET ${F}/commission-plans`]: { body: page([plan()]) }, [`GET ${F}/commission-plans/${PLAN}`]: { body: { plan: plan() } },
    [`GET ${F}/differences`]: { body: page([difference()], { open: { total: 3, by_severity: { HIGH: 1, MEDIUM: 2 } } }) }, [`GET ${F}/differences/${D1}`]: { body: { difference: difference() } },
    [`GET ${F}/provider-settlements`]: { body: page([], { source: { payment_driver: 'fake', status: 'FAKE_PROVIDER', mode: 'FAKE', razorpay_settlement_api: 'PENDING PROVIDER VERIFICATION', scheduled: false }, last_import: null }) },
    [`GET ${F}/adjustments`]: { body: page([adjustment()], { policy: { refund_liability_default: 'UNASSIGNED', four_eyes: false, reasons: ['CORRECTION', 'GOODWILL', 'RECOVERY', 'COMMERCIAL_AGREEMENT', 'OTHER'], approval: 'PENDING BUSINESS / LEGAL APPROVAL' } }) }, [`GET ${F}/adjustments/${ADJ}`]: { body: { adjustment: adjustment() } },
    [`GET ${F}/holds`]: { body: page([HOLD]) },
    [`GET ${F}/settlements/${S1}/export`]: { csv: 'FoodOnTheGo\n' },
    [`GET ${F}/allocations`]: { body: page([allocation(), allocation({ id: AL2, order: { id: 'o2', number: 'FOTG-20261012-XYZ789' }, review_reason: 'SHARED_PROMOTION_SPLIT_UNDEFINED', discount: inr(5000), promotion: { code: 'SAVE50', funding: 'SHARED', funding_basis: 'UNDEFINED', restaurant_funded: inr(0), platform_funded: inr(0) } })]) },
    [`GET ${F}/reports/commission`]: { body: { period: PERIOD, group_by: 'day', rows: [commissionRow('2026-10-12', inr), commissionRow('2026-10-12', usd, { orders: 1, gross_marketplace_sales: usd(1200), commission_base: usd(1200), commission: usd(200), restaurant_earnings: usd(1000), platform_funded_discount: usd(0) })] } },
    [`GET ${F}/reports/customer-returns`]: { body: { period: PERIOD, liability_policy: { default: 'UNASSIGNED', configured_reasons: [], approval: 'PENDING BUSINESS / LEGAL APPROVAL' }, rows: [{ currency: 'INR', refund_count: 3, refund_amount: inr(150000), returned_before_recognition: inr(50000), restaurant_funded: inr(25000), platform_funded: inr(25000), awaiting_decision: inr(50000), awaiting_decision_count: 1 }] } },
    [`GET ${F}/reports/provider-costs`]: { body: { period: PERIOD, source: 'PROVIDER_REPORTED', estimated: false, policy: 'PLATFORM_BORNE', rows: [{ day: '2026-10-11', provider: 'fake', currency: 'INR', payments: 12, refunds: 1, payment_amount: inr(600000), fee: inr(12000), tax_on_fee: inr(2160), fee_lines_posted: 12 }] } },
    [`GET ${F}/ledger/accounts`]: { body: page([ACCOUNT]) }, [`GET ${F}/ledger/transactions`]: { body: page([TRANSACTION]) }, [`GET ${F}/ledger/transactions/${TX}`]: { body: { transaction: { ...TRANSACTION, detail: { settlement: 'STL-20261012-0001' }, entries: ENTRIES, balanced: true } } },
    [`GET ${F}/runs`]: { body: page([RUN, { ...RUN, id: 'run-2', kind: 'RECONCILE_INTERNAL', status: 'PROBLEMS', problems: 2 }]) },
  }
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const path = decodeURIComponent(url.replace(BASE, '')); const method = init.method ?? 'GET'
    calls.push({ method, path, body: init.body ? JSON.parse(init.body as string) : null, headers: (init.headers ?? {}) as Record<string, string> })
    const key = `${method} ${path.split('?')[0]}`
    const r = routes[key] ?? (key === `GET ${F}/settlements/${S1}` ? { body: { settlement: current } } : undefined)
    if (!r) return new Response(JSON.stringify({ error: { code: 'not_found', message: `No stub for ${method} ${path}`, details: {} } }), { status: 404 })
    if (r.csv !== undefined) return new Response(r.csv, { status: 200, headers: { 'Content-Type': 'text/csv; charset=UTF-8', 'Content-Disposition': 'attachment; filename="settlement-STL-20261012-0001.csv"' } })
    return new Response(JSON.stringify(r.body ?? {}), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } })
  }))
  vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:finance-test'), revokeObjectURL: vi.fn() }))
  vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) { saved.push(this.download) })
})
afterEach(() => { cleanup(); vi.restoreAllMocks(); vi.unstubAllGlobals(); tokens.set('admin', null) })

describe('ApiAdminFinanceRepository (Module 36)', () => {
  it('lists with the project\'s query convention and no market — the backend scopes by grant', async () => {
    const repo = new ApiAdminFinanceRepository()
    const list = await repo.settlements({ queue: 'approved', page: 2, pageSize: 10 })
    for (const part of [`${F}/settlements?`, 'filter[queue]=approved', 'page[number]=2', 'page[size]=10']) expect(calls[0].path).toContain(part)
    expect(calls[0].path).not.toContain('market'); expect(calls[0].headers.Authorization).toBe('Bearer admin-token')
    expect(list).toMatchObject({ total: 1, page: 1, pageSize: 10 }); expect(list.queues.paid).toBe(4); expect(list.items[0].summary.net_payable).toEqual({ amount: 388750, currency: 'INR' })
    await repo.settlements({ queue: 'all' }); expect(calls[1].path).not.toContain('filter[')
    await repo.differences({}); await repo.differences({ open: false }); await repo.payables({ query: '  burger ' }); await repo.overview('2026-10-01', '2026-10-12'); await repo.overview()
    expect(calls[2].path).toContain('filter[open]=true'); expect(calls[3].path).toContain('filter[open]=false'); expect(calls[4].path).toContain('filter[q]=burger')
    expect(calls[5].path).toBe(`${F}/overview?from=2026-10-01&to=2026-10-12`); expect(calls[6].path).toBe(`${F}/overview`)
    expect((await repo.payouts({})).provider).toMatchObject({ status: 'FAKE_PROVIDER', live_payout: 'DISABLED' })
  })

  it('an action carries a reason, a confirmation or a reference — never a status, a total, a ledger entry or "paid"', async () => {
    const repo = new ApiAdminFinanceRepository()
    for (const path of [`settlements/${S1}/approve`, `settlements/${S1}/cancel`]) routes[`POST ${F}/${path}`] = { body: { settlement: settlement() } }
    for (const path of [`settlements/${S1}/payouts`, `settlements/${S1}/external-payouts`, `payouts/${P1}/reconcile`, `payouts/${P1}/retry`, `payouts/${P1}/cancel`]) routes[`POST ${F}/${path}`] = { body: { payout: payout() } }
    for (const path of ['commission-plans', `commission-plans/${PLAN}/end`, `commission-plans/${PLAN}/withdraw`]) routes[`POST ${F}/${path}`] = { body: { plan: plan() } }
    for (const path of ['investigate', 'resolve', 'ignore']) routes[`POST ${F}/differences/${D1}/${path}`] = { body: { difference: difference() } }
    for (const path of ['adjustments', `adjustments/${ADJ}/decide`, `adjustments/${ADJ}/approve`, `adjustments/${ADJ}/cancel`]) routes[`POST ${F}/${path}`] = { body: { adjustment: adjustment() } }
    routes[`POST ${F}/holds`] = { body: { hold: HOLD } }; routes[`POST ${F}/holds/${H1}/release`] = { body: { hold: HOLD } }
    routes[`POST ${F}/settlements/generate`] = { body: { dry_run: true, summary: {}, results: [], run: null, note: '' } }; routes[`POST ${F}/provider-settlements/import`] = { status: 201, body: { run: RUN } }; routes[`POST ${F}/checks`] = { body: { check: 'LEDGER', run: RUN } }

    await repo.approveSettlement(S1, 'Checked', 4); await repo.cancelSettlement(S1, 'Wrong period was calculated')
    await repo.startPayout(S1, null)
    await repo.recordExternalPayout(S1, { externalReference: 'UTR 123456', paidOn: '2026-10-12', amountMinor: 388750, currency: 'INR', reason: 'Paid by bank transfer', evidenceReference: 'DOC-77' })
    await repo.payoutAction(P1, 'reconcile', 'Restaurant asked'); await repo.payoutAction(P1, 'retry', 'Provider has no record'); await repo.payoutAction(P1, 'cancel', 'Created by mistake')
    await repo.createPlan({ name: 'India standard', market: 'india', restaurant: null, rateBps: 1250, basePolicy: 'MERCHANDISE_GROSS', effectiveFrom: '2030-01-01T00:00:00.000Z', effectiveUntil: null, approval: 'TEST', approvalReference: 'ignored for TEST', reason: 'A plan for local testing' })
    await repo.endPlan(PLAN, '2030-06-01T00:00:00.000Z', 'Replaced by a new plan', 1); await repo.withdrawPlan(PLAN, 'Entered by mistake')
    await repo.differenceAction(D1, 'investigate', {}); await repo.differenceAction(D1, 'resolve', { resolution: 'EXPLAINED', note: 'Rounding at the provider' }); await repo.differenceAction(D1, 'ignore', { note: 'Known test record' })
    await repo.createAdjustment({ restaurant: L1, direction: 'CREDIT_RESTAURANT', amountMinor: 20000, currency: 'INR', reasonCode: 'GOODWILL', reason: 'Agreed with the restaurant', supportingReference: 'TICKET-9', order: null }, 'key-000000000001')
    await repo.decideAdjustment(ADJ, 25000, 'Shared equally by agreement', 3); await repo.adjustmentAction(ADJ, 'approve', 'Checked'); await repo.adjustmentAction(ADJ, 'cancel', 'Duplicate')
    await repo.placeHold({ restaurant: L1, line: null, reason: 'COMPLIANCE', note: 'Documents are being checked' }); await repo.releaseHold(H1, 'Documents were accepted')
    await repo.generate({ restaurant: L1, until: '2026-10-11', dryRun: true }); await repo.generate({ dryRun: false }); await repo.importDay('2026-10-11'); await repo.runCheck('LEDGER')

    expect(calls.map((c) => [c.path.replace(F, ''), c.body])).toEqual([
      [`/settlements/${S1}/approve`, { expected_version: 4, note: 'Checked' }], [`/settlements/${S1}/cancel`, { reason: 'Wrong period was calculated' }],
      [`/settlements/${S1}/payouts`, { confirm: true }],
      [`/settlements/${S1}/external-payouts`, { confirm: true, external_reference: 'UTR 123456', paid_on: '2026-10-12', amount_minor: 388750, currency: 'INR', reason: 'Paid by bank transfer', evidence_reference: 'DOC-77' }],
      [`/payouts/${P1}/reconcile`, { reason: 'Restaurant asked' }], [`/payouts/${P1}/retry`, { reason: 'Provider has no record', confirm: true }], [`/payouts/${P1}/cancel`, { reason: 'Created by mistake' }],
      ['/commission-plans', { name: 'India standard', market: 'india', rate_bps: 1250, base_policy: 'MERCHANDISE_GROSS', effective_from: '2030-01-01T00:00:00.000Z', approval: 'TEST', reason: 'A plan for local testing' }],
      [`/commission-plans/${PLAN}/end`, { effective_until: '2030-06-01T00:00:00.000Z', reason: 'Replaced by a new plan', expected_version: 1 }], [`/commission-plans/${PLAN}/withdraw`, { reason: 'Entered by mistake' }],
      [`/differences/${D1}/investigate`, {}], [`/differences/${D1}/resolve`, { resolution: 'EXPLAINED', note: 'Rounding at the provider' }], [`/differences/${D1}/ignore`, { note: 'Known test record' }],
      ['/adjustments', { restaurant: L1, direction: 'CREDIT_RESTAURANT', amount_minor: 20000, currency: 'INR', reason_code: 'GOODWILL', reason: 'Agreed with the restaurant', supporting_reference: 'TICKET-9' }],
      [`/adjustments/${ADJ}/decide`, { restaurant_share_minor: 25000, reason: 'Shared equally by agreement', expected_version: 3 }], [`/adjustments/${ADJ}/approve`, { reason: 'Checked' }], [`/adjustments/${ADJ}/cancel`, { reason: 'Duplicate' }],
      ['/holds', { restaurant: L1, reason: 'COMPLIANCE', note: 'Documents are being checked' }], [`/holds/${H1}/release`, { reason: 'Documents were accepted' }],
      ['/settlements/generate', { dry_run: true, restaurant: L1, until: '2026-10-11' }], ['/settlements/generate', { dry_run: false }], ['/provider-settlements/import', { day: '2026-10-11' }], ['/checks', { check: 'LEDGER' }],
    ])
    expect(calls.find((c) => c.path === `${F}/adjustments`)?.headers['Idempotency-Key']).toBe('key-000000000001')
    // What the backend refuses is never sent: no status, no paid state, no totals, no ledger fields.
    expect(JSON.stringify(calls.map((c) => c.body))).not.toMatch(/"(status|paid_at|paid_minor|net_payable(_minor)?|commission(_minor)?|balance(_minor)?|ledger_account|debit|credit|entries|payout_status|provider_payout_reference)"/)
    expect(Object.getOwnPropertyNames(ApiAdminFinanceRepository.prototype).filter((m) => /paid/i.test(m))).toEqual([])   // no "mark as paid" call exists
  })

  it('the backend\'s finance permissions become the keys the page asks for; roles hold what the backend\'s roles hold', () => {
    expect(adminPermissionsFromApi([...FINANCE_CODES.map((c) => `admin.${c}`), 'admin.settlements.view', 'admin.finance.mark_paid', 'admin.finance.ledger.write']).sort()).toEqual([...FINANCE_CODES, 'settlements.view'].sort())
    for (const role of ['super_admin', 'finance_admin'] as const) for (const p of [...FINANCE_CODES, 'settlements.view' as const]) expect(adminPermissionsForRole(role), `${role} ${p}`).toContain(p)
    expect(adminPermissionsForRole('operations_admin').filter((p) => p.startsWith('finance.')).sort()).toEqual(['finance.payouts.view', 'finance.reconciliation.view', 'finance.view'])
    expect(adminPermissionsForRole('analyst').filter((p) => p.startsWith('finance.'))).toEqual(['finance.view'])
    for (const role of ['support_admin', 'restaurant_onboarding', 'moderation_admin'] as const) expect(adminPermissionsForRole(role).filter((p) => p.startsWith('finance.') || p === 'settlements.view')).toEqual([])
  })

  it('a percentage becomes basis points digit by digit, and an amount minor units — never through a floating-point number', () => {
    expect([percentToBps('12.5'), percentToBps('12.50'), percentToBps('0'), percentToBps('100'), percentToBps('0.07'), percentToBps(' 19.99 ')]).toEqual([1250, 1250, 0, 10000, 7, 1999])
    for (const bad of ['', '100.01', '101', '-1', '12.345', '1,5', 'abc', '1e2']) expect(percentToBps(bad), bad).toBeNull()
    expect([bpsToPercent(1250), bpsToPercent(7), bpsToPercent(10000)]).toEqual(['12.50%', '0.07%', '100.00%'])
    expect([amountToMinor('250', 'INR'), amountToMinor('0', 'INR'), amountToMinor('0', 'INR', true), amountToMinor('0.00', 'INR', true), amountToMinor('19.99', 'INR', true)]).toEqual([25000, null, 0, 0, 1999])
  })
})

const mount = (query = '') => render(
  <LocaleProvider><MemoryRouter initialEntries={[`/admin/settlements${query}`]}><Routes>
    <Route path="/admin" element={<AdminProvider repos={adminRepositories}><AdminLayout /></AdminProvider>}><Route path="settlements" element={<BackendFinancePage />} /></Route>
  </Routes></MemoryRouter></LocaleProvider>,
)
const tabNames = () => within(screen.getByRole('tablist', { name: /finance sections/i })).getAllByRole('tab').map((x) => x.textContent)
const openFirst = async (user: ReturnType<typeof userEvent.setup>, table: string, detail: string) => {
  await user.click(within(await screen.findByTestId(table)).getByRole('button', { name: /view/i }))
  return screen.findByTestId(detail)
}
const buttons = (testId: string) => within(screen.getByTestId(testId)).queryAllByRole('button').map((b) => b.getAttribute('data-testid'))

describe('Platform Admin finance page on backend data (Module 36)', () => {
  it('overview: the backend\'s figures per currency, gross marketplace sales that are not revenue, health and LIVE PAYOUTS: DISABLED', async () => {
    mount()
    const pageEl = await screen.findByTestId('adm-finance'); expect(pageEl).toHaveAttribute('data-source', 'backend')
    expect(tabNames()).toEqual(['Overview', 'Settlements', 'Payouts', 'Payables', 'Commission', 'Reconciliation', 'Adjustments & holds', 'Reports', 'Ledger'])
    const rupees = await screen.findByTestId('fin-currency-INR'); const dollars = screen.getByTestId('fin-currency-USD')
    expect(within(rupees).getByTestId('fin-INR-customer_payments')).toHaveTextContent('₹50,000.00'); expect(within(rupees).getByTestId('fin-INR-customer_payments')).toHaveTextContent('Customer payments')
    const gross = within(rupees).getByTestId('fin-INR-gross_marketplace_sales')
    expect(gross).toHaveTextContent('₹45,000.00'); expect(gross).toHaveTextContent('Gross marketplace sales'); expect(gross).toHaveTextContent('not FoodOnTheGo revenue')
    expect(within(rupees).getByTestId('fin-INR-platform_revenue')).toHaveTextContent('₹5,825.00'); expect(within(rupees).getByTestId('fin-INR-platform_commission')).toHaveTextContent('₹5,625.00')
    expect(within(rupees).getByTestId('fin-INR-provider_fees')).toHaveTextContent('provider-reported, never estimated'); expect(within(rupees).getByTestId('fin-INR-restaurant_payables')).toHaveTextContent('₹4,434.50'); expect(within(rupees).getByTestId('fin-INR-tax_held')).toHaveTextContent('₹2,250.00')
    for (const label of ['Refunds', 'Platform fee revenue', 'Promotion expense', 'Restaurant earnings', 'Provider settlements received', 'Restaurant payouts', 'Payment clearing', 'Provider clearing', 'Refund clearing']) expect(rupees).toHaveTextContent(label)
    // Two currencies are two blocks: no rupee figure in the dollar block, and nothing adds the two up.
    expect(within(dollars).getByTestId('fin-USD-gross_marketplace_sales')).toHaveTextContent('$12.00'); expect(dollars).not.toHaveTextContent('₹'); expect(rupees).not.toHaveTextContent('$')
    // Gross marketplace sales are labelled exactly that — and said not to be revenue — in every currency.
    for (const el of [gross, within(dollars).getByTestId('fin-USD-gross_marketplace_sales')]) expect(el.querySelector('.adm-stat__label')?.textContent).toBe('Gross marketplace sales — not FoodOnTheGo revenue')

    const health = screen.getByTestId('fin-health')
    expect(health.querySelector('[data-health="ledger"]')).toHaveTextContent('Ledger: Balanced'); expect(health.querySelector('[data-health="queue"]')).toHaveTextContent('Up to date')
    expect(screen.getByTestId('fin-live-payouts')).toHaveTextContent('LIVE PAYOUTS: DISABLED')
    const queues = screen.getByTestId('fin-queues'); expect(queues).toHaveTextContent('Calculated: 2 · Approved: 1'); expect(queues).toHaveTextContent('3 (High: 1 · Medium: 2)')
    expect(screen.getByTestId('fin-provider-fees')).toHaveTextContent('it is not an estimate'); expect(screen.getByTestId('fin-period-text')).toHaveTextContent('2026-09-13 to 2026-10-12 · Asia/Kolkata')
    expect(screen.queryByText(/mark.*paid/i)).toBeNull()

    fireEvent.change(screen.getByTestId('fin-from'), { target: { value: '2026-10-01' } })
    await waitFor(() => expect(calls.some((c) => c.path === `${F}/overview?from=2026-10-01`)).toBe(true))
  }, 30000)

  it('settlements: the queues, the statement, and only the actions the backend allows — approve sends the version, the export is a CSV', async () => {
    const user = userEvent.setup()
    mount('?tab=settlements')
    const table = await screen.findByTestId('fin-settlements-table')
    expect(table).toHaveTextContent('STL-20261012-0001'); expect(table).toHaveTextContent('Burger Hub'); expect(table).toHaveTextContent('₹3,887.50'); expect(table).toHaveTextContent('Calculated')
    await waitFor(() => expect(screen.getByRole('tab', { name: /^paid/i })).toHaveTextContent('4'))
    await user.click(screen.getByRole('tab', { name: /^approved/i }))
    await waitFor(() => expect(calls.some((c) => c.path.includes('filter[queue]=approved'))).toBe(true))

    const drawer = await openFirst(user, 'fin-settlements-table', 'fin-settlement')
    expect(drawer).toHaveAttribute('data-status', 'CALCULATED'); expect(within(drawer).getByTestId('fin-statement-kind')).toHaveTextContent('Settlement statement — not a tax invoice')
    expect(within(drawer).getByTestId('fin-settlement-net')).toHaveTextContent('₹3,887.50 (INR)'); expect(within(drawer).getByTestId('fin-settlement-lines')).toHaveTextContent('FOTG-20261012-ABC234')
    expect(buttons('fin-settlement-actions')).toEqual(['fin-settlement-approve', 'fin-settlement-cancel', 'fin-settlement-export'])
    expect(within(drawer).getByTestId('fin-no-manual-paid')).toHaveTextContent('becomes paid only when its payout is confirmed'); expect(within(drawer).getByTestId('fin-no-manual-paid')).toHaveTextContent('LIVE PAYOUTS: DISABLED')
    expect(screen.queryByText(/mark.*paid/i)).toBeNull(); expect(screen.queryAllByRole('button').map((b) => b.textContent ?? '').filter((x) => /\bpaid\b|set status|complete/i.test(x))).toEqual([])

    await user.click(within(drawer).getByTestId('fin-settlement-export'))
    await waitFor(() => expect(screen.getByTestId('fin-notice')).toHaveTextContent('Downloaded settlement-STL-20261012-0001.csv.'))
    expect(calls.some((c) => c.path === `${F}/settlements/${S1}/export` && c.headers.Accept === 'text/csv' && c.headers.Authorization === 'Bearer admin-token')).toBe(true); expect(saved).toEqual(['settlement-STL-20261012-0001.csv'])

    // Approve: the dialog names the settlement and its amount; the version the screen showed is sent.
    routes[`POST ${F}/settlements/${S1}/approve`] = { body: { settlement: settlement({ status: 'APPROVED', version: 2 }) } }
    await user.click(within(drawer).getByTestId('fin-settlement-approve'))
    const dialog = await screen.findByTestId('fin-action-dialog')
    expect(dialog).toHaveTextContent('Settlement STL-20261012-0001 — Burger Hub'); expect(dialog).toHaveTextContent('Net payable: ₹3,887.50 (INR)'); expect(dialog).toHaveTextContent('It still sends no money')
    current = settlement({ status: 'APPROVED', version: 2, approved_at: '2026-10-12T20:00:00+00:00', approved_by: { id: 'a1', name: 'Kenji Watanabe' }, can: { ...NO_CAN, start_payout: true, record_external_payout: true, cancel: true, export: true } })
    await user.click(within(dialog).getByTestId('finance-dialog-confirm'))
    await waitFor(() => expect(screen.getByTestId('fin-settlement')).toHaveAttribute('data-status', 'APPROVED'))
    expect(posted('/approve')[0].body).toEqual({ expected_version: 1 })
    expect(buttons('fin-settlement-actions')).toEqual(['fin-settlement-start-payout', 'fin-settlement-record-external', 'fin-settlement-cancel', 'fin-settlement-export'])
  }, 30000)

  it('a payout is confirmed before it is sent, an external payout only confirms the settlement\'s own amount, and a refusal is shown in the backend\'s words', async () => {
    const user = userEvent.setup()
    current = settlement({ status: 'APPROVED', version: 2, can: { ...NO_CAN, start_payout: true, record_external_payout: true } })
    mount(`?tab=settlements&open=${S1}`)   // a link opens the settlement it names
    const drawer = await screen.findByTestId('fin-settlement')

    await user.click(within(drawer).getByTestId('fin-settlement-start-payout'))
    const confirm = await screen.findByTestId('fin-action-dialog')
    expect(confirm).toHaveTextContent('Payout amount: ₹3,887.50 (INR)'); expect(confirm).toHaveTextContent('cannot be taken back'); expect(within(confirm).queryByTestId('ff-amount')).toBeNull()
    expect(posted('/payouts')).toHaveLength(0)   // nothing is sent before the confirmation
    routes[`POST ${F}/settlements/${S1}/payouts`] = { status: 409, body: { error: { code: 'payout_in_progress', message: 'This settlement already has a payout.', details: {} } } }
    await user.click(within(confirm).getByTestId('finance-dialog-confirm'))
    expect(await screen.findByTestId('fin-error')).toHaveTextContent('This settlement already has a payout.')
    expect(posted('/payouts')[0].body).toEqual({ confirm: true })

    // Recorded outside: reference, day, evidence and a reason are typed — the amount is not, it is the settlement's.
    await user.click(screen.getByTestId('fin-settlement-record-external'))
    const form = await screen.findByTestId('fin-action-dialog')
    expect(form).toHaveTextContent('Amount you confirm was paid: ₹3,887.50 (INR)'); expect(form).toHaveTextContent('You only confirm the amount of the settlement')
    expect(form.querySelector('[data-testid*="amount"]')).toBeNull()
    await user.click(within(form).getByTestId('finance-dialog-confirm'))
    expect(within(form).getByTestId('ff-external_reference-error')).toBeInTheDocument(); expect(posted('/external-payouts')).toHaveLength(0)
    await user.type(within(form).getByTestId('ff-external_reference'), 'UTR 123456'); await user.type(within(form).getByTestId('ff-paid_on'), '2026-10-12'); await user.type(within(form).getByTestId('ff-evidence_reference'), 'DOC-77')
    await user.type(within(form).getByTestId('ff-reason'), 'too short')
    await user.click(within(form).getByTestId('finance-dialog-confirm'))
    expect(within(form).getByTestId('ff-reason-error')).toHaveTextContent('At least 10 characters.'); expect(posted('/external-payouts')).toHaveLength(0)
    await user.clear(within(form).getByTestId('ff-reason')); await user.type(within(form).getByTestId('ff-reason'), 'Paid by bank transfer')
    routes[`POST ${F}/settlements/${S1}/external-payouts`] = { status: 201, body: { payout: payout({ status: 'PAID', method: 'EXTERNAL' }) } }
    current = settlement({ status: 'PAID', version: 3, can: { ...NO_CAN, export: true }, payouts: [payout({ status: 'PAID', method: 'EXTERNAL' })] })
    await user.click(within(form).getByTestId('finance-dialog-confirm'))
    await waitFor(() => expect(screen.getByTestId('fin-settlement')).toHaveAttribute('data-status', 'PAID'))
    expect(posted('/external-payouts')[0].body).toEqual({ confirm: true, external_reference: 'UTR 123456', paid_on: '2026-10-12', amount_minor: 388750, currency: 'INR', reason: 'Paid by bank transfer', evidence_reference: 'DOC-77' })
    expect(buttons('fin-settlement-actions')).toEqual(['fin-settlement-export'])
  }, 30000)

  it('generating settlements can be previewed: a dry run writes nothing and shows what would be calculated', async () => {
    const user = userEvent.setup()
    mount('?tab=settlements')
    await screen.findByTestId('fin-settlements-table')
    routes[`POST ${F}/settlements/generate`] = { body: { dry_run: true, summary: { locations: 2, settlements: 1, zero_statements: 0, negative_carried: 1, held: 0, below_minimum: 0, lines: 9 }, note: 'A settlement is a statement of what is owed. Generating one sends no money.', run: null,
      results: [{ location: L1, restaurant: 'Burger Hub', currency: 'INR', period_end: '2026-10-12T18:30:00+00:00', outcome: 'SETTLEMENT', line_count: 9, net_payable: inr(388750), settlement: null }, { location: 'l2', restaurant: 'Route 5 Diner', currency: 'USD', period_end: '2026-10-12T18:30:00+00:00', outcome: 'NEGATIVE_CARRIED_FORWARD', line_count: 1, net_payable: usd(-500), settlement: null }] } }
    await user.click(screen.getByTestId('fin-settlements-preview'))
    const dialog = await screen.findByTestId('fin-generate-dialog')
    await user.type(within(dialog).getByTestId('ff-restaurant'), 'not-an-id'); await user.click(within(dialog).getByTestId('finance-dialog-confirm'))
    expect(within(dialog).getByTestId('ff-restaurant-error')).toBeInTheDocument(); expect(posted('/generate')).toHaveLength(0)
    await user.clear(within(dialog).getByTestId('ff-restaurant')); await user.type(within(dialog).getByTestId('ff-until'), '2026-10-11')
    await user.click(within(dialog).getByTestId('finance-dialog-confirm'))
    const result = await screen.findByTestId('fin-generate-result')
    expect(posted('/generate')[0].body).toEqual({ dry_run: true, until: '2026-10-11' })
    expect(result).toHaveAttribute('data-dry-run', 'true'); expect(result).toHaveTextContent('Burger Hub'); expect(result).toHaveTextContent('₹3,887.50'); expect(result).toHaveTextContent('Negative carried forward'); expect(result).toHaveTextContent('-$5.00')
    expect(screen.getByText('Preview — nothing was written')).toBeInTheDocument(); expect(screen.getByText(/Generating one sends no money/)).toBeInTheDocument()
  }, 30000)

  it('payouts: the provider banner says FAKE_PROVIDER and LIVE PAYOUT = DISABLED; the one action the backend offers needs a reason', async () => {
    const user = userEvent.setup()
    mount('?tab=payouts')
    const banner = await screen.findByTestId('fin-payout-provider')
    expect(banner).toHaveAttribute('data-status', 'FAKE_PROVIDER'); expect(banner).toHaveTextContent('FAKE_PROVIDER'); expect(banner).toHaveTextContent('LIVE PAYOUT = DISABLED'); expect(banner).toHaveTextContent('PENDING PROVIDER ACCOUNT ENABLEMENT')
    const table = await screen.findByTestId('fin-payouts-table')
    expect(table).toHaveTextContent('PO-0E00000000'); expect(table).toHaveTextContent('₹3,887.50'); expect(table).toHaveTextContent('Test only'); expect(table).toHaveTextContent('Processing')
    const drawer = await openFirst(user, 'fin-payouts-table', 'fin-payout')
    expect(within(drawer).getByTestId('fin-payout-status')).toHaveTextContent('provider: processing'); expect(within(drawer).getByTestId('fin-payout-amount')).toHaveTextContent('₹3,887.50 (INR)'); expect(drawer).toHaveTextContent('•••• 4321')
    expect(buttons('fin-payout-actions')).toEqual(['fin-payout-reconcile'])   // no "send again", no "withdraw" — and nowhere a paid switch
    expect(screen.queryByText(/mark.*paid/i)).toBeNull()
    await user.click(within(drawer).getByTestId('fin-payout-reconcile'))
    const dialog = await screen.findByTestId('fin-action-dialog')
    await user.click(within(dialog).getByTestId('finance-dialog-confirm'))
    expect(posted('/reconcile')).toHaveLength(0)   // no reason, no request
    routes[`POST ${F}/payouts/${P1}/reconcile`] = { body: { payout: payout({ status: 'PAID', provider_status: 'processed', completed_at: '2026-10-12T22:00:00+00:00', can: { reconcile: true, retry: false, cancel: false } }) } }
    await user.type(within(dialog).getByTestId('ff-reason'), 'Restaurant asked')
    await user.click(within(dialog).getByTestId('finance-dialog-confirm'))
    await waitFor(() => expect(screen.getByTestId('fin-payout')).toHaveAttribute('data-status', 'PAID'))
    expect(posted('/reconcile')[0].body).toEqual({ reason: 'Restaurant asked' })
  }, 30000)

  it('payables flag a negative balance for finance review; commission plans are never edited and a TEST plan says what it is', async () => {
    const user = userEvent.setup()
    mount('?tab=payables')
    const payables = await screen.findByTestId('fin-payables-table')
    expect(payables).toHaveTextContent('Burger Hub'); expect(payables).toHaveTextContent('-₹25.00'); expect(payables).toHaveTextContent('Finance review required'); expect(payables).toHaveTextContent('Location held'); expect(payables).toHaveTextContent('Not configured'); expect(payables).toHaveTextContent(L1)
    expect(within(payables).queryAllByRole('button')).toEqual([])   // read-only: there is no balance to edit
    cleanup()

    mount('?tab=commission')
    const plans = await screen.findByTestId('fin-plans-table')
    expect(plans).toHaveTextContent('India standard (fixture)'); expect(plans).toHaveTextContent('12.50%'); expect(plans).toHaveTextContent('Merchandise total after restaurant-funded discounts')
    expect(within(plans).getByTestId('fin-plan-test')).toHaveTextContent('TEST'); expect(within(plans).getByTestId('fin-plan-test')).toHaveTextContent('development fixture — not a production commission')
    const drawer = await openFirst(user, 'fin-plans-table', 'fin-plan')
    expect(within(drawer).getByTestId('fin-plan-rate')).toHaveTextContent('12.50%'); expect(buttons('fin-plan-actions')).toEqual(['fin-plan-end'])
    expect(within(drawer).getByTestId('fin-plan-never-edited')).toHaveTextContent('A plan is never edited'); expect(drawer.querySelectorAll('input, select, textarea')).toHaveLength(0)
    await user.keyboard('{Escape}')

    // A new plan: the rate is typed as a percentage and sent as basis points; it has to start in the future.
    await user.click(screen.getByTestId('fin-plan-new'))
    const dialog = await screen.findByTestId('fin-plan-dialog')
    await user.type(within(dialog).getByTestId('ff-name'), 'India standard 2030'); await user.clear(within(dialog).getByTestId('ff-market')); await user.type(within(dialog).getByTestId('ff-market'), 'india'); await user.type(within(dialog).getByTestId('ff-rate'), '15.5x')
    fireEvent.change(within(dialog).getByTestId('ff-effective_from'), { target: { value: '2020-01-01T00:00' } })
    await user.type(within(dialog).getByTestId('ff-reason'), 'Agreed standard terms for 2030')
    await user.click(within(dialog).getByTestId('finance-dialog-confirm'))
    expect(within(dialog).getByTestId('ff-rate-error')).toBeInTheDocument(); expect(within(dialog).getByTestId('ff-effective_from-error')).toHaveTextContent('Must be a moment in the future.'); expect(posted('/commission-plans')).toHaveLength(0)
    await user.clear(within(dialog).getByTestId('ff-rate')); await user.type(within(dialog).getByTestId('ff-rate'), '15.5')
    fireEvent.change(within(dialog).getByTestId('ff-effective_from'), { target: { value: '2030-01-01T00:00' } })
    await user.selectOptions(within(dialog).getByTestId('ff-approval'), 'APPROVED'); await user.click(within(dialog).getByTestId('finance-dialog-confirm'))
    expect(within(dialog).getByTestId('ff-approval_reference-error')).toBeInTheDocument(); expect(posted('/commission-plans')).toHaveLength(0)
    await user.type(within(dialog).getByTestId('ff-approval_reference'), 'BOARD-2029-14')
    routes[`POST ${F}/commission-plans`] = { status: 201, body: { plan: plan({ name: 'India standard 2030', rate_bps: 1550, state: 'SCHEDULED', approval: 'APPROVED', approval_reference: 'BOARD-2029-14', can: { end: false, withdraw: true } }) } }
    await user.click(within(dialog).getByTestId('finance-dialog-confirm'))
    await waitFor(() => expect(posted('/commission-plans')).toHaveLength(1))
    expect(posted('/commission-plans')[0].body).toMatchObject({ name: 'India standard 2030', market: 'india', rate_bps: 1550, base_policy: 'MERCHANDISE_GROSS', approval: 'APPROVED', approval_reference: 'BOARD-2029-14', reason: 'Agreed standard terms for 2030', effective_from: new Date('2030-01-01T00:00').toISOString() })
    expect(await screen.findByTestId('fin-plan')).toHaveAttribute('data-state', 'SCHEDULED'); expect(buttons('fin-plan-actions')).toEqual(['fin-plan-withdraw'])
  }, 30000)

  it('reconciliation: a difference is closed with a resolution and a note; the source says Razorpay is pending verification; a check shows its run', async () => {
    const user = userEvent.setup()
    mount('?tab=reconciliation')
    const table = await screen.findByTestId('fin-differences-table')
    expect(table).toHaveTextContent('Amount mismatch'); expect(table).toHaveTextContent('High'); expect(table).toHaveTextContent('₹500.00'); expect(table).toHaveTextContent('₹490.00'); expect(table).toHaveTextContent('-₹10.00'); expect(table).toHaveTextContent('fakepay_aaaa'); expect(table).toHaveTextContent('Open')
    expect(calls.some((c) => c.path.includes(`${F}/differences?`) && c.path.includes('filter[open]=true'))).toBe(true)
    const drawer = await openFirst(user, 'fin-differences-table', 'fin-difference')
    expect(within(drawer).getByTestId('fin-difference-detail')).toHaveTextContent('49000'); expect(buttons('fin-difference-actions')).toEqual(['fin-difference-investigate', 'fin-difference-resolve', 'fin-difference-ignore'])
    await user.click(within(drawer).getByTestId('fin-difference-resolve'))
    const dialog = await screen.findByTestId('fin-action-dialog')
    expect(within(within(dialog).getByTestId('ff-resolution')).getAllByRole('option').map((o) => (o as HTMLOptionElement).value)).toEqual(['CORRECTED', 'ADJUSTMENT_POSTED', 'PROVIDER_CORRECTED', 'EXPLAINED'])
    await user.selectOptions(within(dialog).getByTestId('ff-resolution'), 'EXPLAINED'); await user.type(within(dialog).getByTestId('ff-note'), 'short')
    await user.click(within(dialog).getByTestId('finance-dialog-confirm'))
    expect(within(dialog).getByTestId('ff-note-error')).toBeInTheDocument(); expect(posted('/resolve')).toHaveLength(0)
    routes[`POST ${F}/differences/${D1}/resolve`] = { body: { difference: difference({ status: 'RESOLVED', resolution: 'EXPLAINED', resolution_note: 'Rounding at the provider', can: { investigate: false, resolve: false, ignore: false } }) } }
    await user.clear(within(dialog).getByTestId('ff-note')); await user.type(within(dialog).getByTestId('ff-note'), 'Rounding at the provider')
    await user.click(within(dialog).getByTestId('finance-dialog-confirm'))
    await waitFor(() => expect(screen.getByTestId('fin-difference')).toHaveAttribute('data-status', 'RESOLVED'))
    expect(posted('/resolve')[0].body).toEqual({ resolution: 'EXPLAINED', note: 'Rounding at the provider' }); expect(buttons('fin-difference-actions')).toEqual([])
    await user.keyboard('{Escape}')

    routes[`POST ${F}/checks`] = { body: { check: 'LEDGER', run: RUN } }
    await user.click(screen.getByTestId('fin-check-run'))
    await user.click(within(await screen.findByTestId('fin-job-dialog')).getByTestId('finance-dialog-confirm'))
    const result = await screen.findByTestId('fin-check-result')
    expect(posted('/checks')[0].body).toEqual({ check: 'LEDGER' }); expect(result).toHaveTextContent('Ledger integrity'); expect(result).toHaveTextContent('Verify ledger'); expect(result).toHaveTextContent('Transactions checked'); expect(result).toHaveTextContent('42')

    await user.click(screen.getByRole('tab', { name: /provider settlements/i }))
    const source = await screen.findByTestId('fin-recon-source')
    expect(source).toHaveTextContent('FAKE_PROVIDER'); expect(source).toHaveTextContent('Razorpay settlement API: PENDING PROVIDER VERIFICATION'); expect(source).toHaveTextContent('never imported')
    routes[`POST ${F}/provider-settlements/import`] = { status: 201, body: { run: { ...RUN, kind: 'PROVIDER_IMPORT', result: { settlements: 1, items: 3 } } } }
    await user.click(screen.getByTestId('fin-import-day'))
    const importDialog = await screen.findByTestId('fin-job-dialog')
    await user.type(within(importDialog).getByTestId('ff-day'), '2026-10-11'); await user.click(within(importDialog).getByTestId('finance-dialog-confirm'))
    await waitFor(() => expect(posted('/import')).toHaveLength(1))
    expect(posted('/import')[0].body).toEqual({ day: '2026-10-11' }); expect(await screen.findByTestId('fin-check-result')).toHaveTextContent('Provider import')
  }, 30000)

  it('adjustments and holds: a liability decision states the restaurant\'s share with the version shown; a hold is released with a reason', async () => {
    const user = userEvent.setup()
    mount('?tab=adjustments')
    const table = await screen.findByTestId('fin-adjustments-table')
    expect(table).toHaveTextContent('Refund liability'); expect(table).toHaveTextContent('₹500.00'); expect(table).toHaveTextContent('Pending decision'); expect(screen.getByTestId('fin-adjustment-policy')).toHaveTextContent('Unassigned')
    const drawer = await openFirst(user, 'fin-adjustments-table', 'fin-adjustment')
    expect(buttons('fin-adjustment-actions')).toEqual(['fin-adjustment-decide'])
    await user.click(within(drawer).getByTestId('fin-adjustment-decide'))
    const dialog = await screen.findByTestId('fin-adjustment-dialog')
    await user.type(within(dialog).getByTestId('ff-restaurant_share'), '600'); await user.type(within(dialog).getByTestId('ff-reason'), 'Shared equally by agreement')
    await user.click(within(dialog).getByTestId('finance-dialog-confirm'))
    expect(within(dialog).getByTestId('ff-restaurant_share-error')).toHaveTextContent('from 0 to ₹500.00'); expect(posted('/decide')).toHaveLength(0)
    routes[`POST ${F}/adjustments/${ADJ}/decide`] = { body: { adjustment: adjustment({ status: 'POSTED', liability: 'SHARED', restaurant_share: inr(25000), platform_share: inr(25000), can: { decide: false, approve: false, cancel: false } }) } }
    await user.clear(within(dialog).getByTestId('ff-restaurant_share')); await user.type(within(dialog).getByTestId('ff-restaurant_share'), '250.00')
    await user.click(within(dialog).getByTestId('finance-dialog-confirm'))
    await waitFor(() => expect(screen.getByTestId('fin-adjustment')).toHaveAttribute('data-status', 'POSTED'))
    expect(posted('/decide')[0].body).toEqual({ restaurant_share_minor: 25000, reason: 'Shared equally by agreement', expected_version: 3 })
    await user.keyboard('{Escape}')

    await user.click(screen.getByRole('tab', { name: /^holds$/i }))
    const holds = await screen.findByTestId('fin-holds-table')
    expect(holds).toHaveTextContent('Burger Hub'); expect(holds).toHaveTextContent('Compliance'); expect(holds).toHaveTextContent('Documents are being checked'); expect(holds).toHaveTextContent('Active')
    routes[`POST ${F}/holds/${H1}/release`] = { body: { hold: { ...HOLD, status: 'RELEASED' } } }
    await user.click(within(holds).getByTestId('fin-hold-release'))
    const release = await screen.findByTestId('fin-hold-dialog')
    await user.type(within(release).getByTestId('ff-reason'), 'Documents were accepted'); await user.click(within(release).getByTestId('finance-dialog-confirm'))
    await waitFor(() => expect(posted('/release')).toHaveLength(1))
    expect(posted('/release')[0].body).toEqual({ reason: 'Documents were accepted' })
  }, 30000)

  it('a tab exists only for a permission that is held, and a view-only administrator gets no control to change anything', async () => {
    const user = userEvent.setup()
    localStorage.setItem(K.session, 'adm-nina')   // operations: reads the overview, payouts and reconciliation — no commission terms
    current = settlement({ can: { ...NO_CAN } }); routes[`GET ${F}/differences/${D1}`] = { body: { difference: difference({ can: { investigate: false, resolve: false, ignore: false } }) } }
    mount()
    await screen.findByTestId('fin-currency-INR')
    expect(tabNames()).toEqual(['Overview', 'Settlements', 'Payouts', 'Payables', 'Reconciliation', 'Adjustments & holds', 'Reports'])   // no commission terms, no ledger
    expect(calls.some((c) => c.path.includes('commission-plans'))).toBe(false)

    await user.click(screen.getByRole('tab', { name: /^settlements$/i }))
    const drawer = await openFirst(user, 'fin-settlements-table', 'fin-settlement')
    expect(screen.queryByTestId('fin-settlements-generate')).toBeNull(); expect(screen.queryByTestId('fin-settlements-preview')).toBeNull()
    expect(buttons('fin-settlement-actions')).toEqual([]); expect(within(drawer).getByTestId('fin-settlement-actions-none')).toHaveTextContent('No action is available to you')
    await user.keyboard('{Escape}')
    await user.click(screen.getByRole('tab', { name: /^reconciliation$/i }))
    await screen.findByTestId('fin-differences-table')
    expect(screen.queryByTestId('fin-recon-jobs')).toBeNull()
    await user.click(screen.getByRole('tab', { name: /adjustments & holds/i }))
    await screen.findByTestId('fin-adjustments-table')
    expect(screen.queryByTestId('fin-adjustment-new')).toBeNull()
    await user.click(screen.getByRole('tab', { name: /^holds$/i }))
    const holds = await screen.findByTestId('fin-holds-table')
    expect(screen.queryByTestId('fin-hold-new')).toBeNull(); expect(within(holds).queryByTestId('fin-hold-release')).toBeNull()
    cleanup()

    // A tab named in the URL that is not theirs is not opened: the page falls back to the first tab they hold.
    mount('?tab=commission')
    await screen.findByTestId('fin-currency-INR')
    expect(screen.getByTestId('adm-finance')).toHaveAttribute('data-tab', 'overview'); expect(screen.queryByTestId('fin-plans-table')).toBeNull(); expect(calls.some((c) => c.path.includes('commission-plans'))).toBe(false)
    cleanup()

    // The finance admin holds the commission permission: the same link opens the plans.
    localStorage.setItem(K.session, 'adm-ken'); mount('?tab=commission')
    await screen.findByTestId('fin-plans-table'); expect(tabNames()).toContain('Commission')
  }, 30000)

  it('a failed load says so and can be tried again', async () => {
    const user = userEvent.setup()
    routes[`GET ${F}/overview`] = { status: 500, body: { error: { code: 'server_error', message: 'boom', details: {} } } }
    mount()
    expect(await screen.findByText('Unable to load finance data')).toBeInTheDocument(); expect(screen.queryByTestId('fin-currency-INR')).toBeNull()
    routes[`GET ${F}/overview`] = { body: OVERVIEW }
    await user.click(screen.getByRole('button', { name: /try again/i }))
    expect(await screen.findByTestId('fin-currency-INR')).toBeInTheDocument()
    cleanup()
    routes[`GET ${F}/settlements`] = { status: 403, body: { error: { code: 'forbidden', message: 'This action is unauthorized.', details: {} } } }
    mount('?tab=settlements')
    expect(await screen.findByText(/unable to load/i)).toBeInTheDocument(); expect(screen.queryByTestId('fin-settlements-table')).toBeNull()
  }, 30000)

  it('orders awaiting terms: the reason in words, a decision with a rate and a reason — and the restaurant\'s share of the discount only for a promotion', async () => {
    const user = userEvent.setup()
    mount()
    // The overview's count leads to the orders it counts.
    await user.click(await screen.findByTestId('fin-queue-awaiting-terms'))
    const table = await screen.findByTestId('fin-allocations-table')
    expect(screen.getByTestId('adm-finance')).toHaveAttribute('data-tab', 'adjustments'); expect(calls.some((c) => c.path.includes(`${F}/allocations?`) && c.path.includes('filter[status]=REVIEW_REQUIRED'))).toBe(true)
    const rows = within(table).getAllByTestId('fin-allocation-row')
    expect(rows.map((r) => [r.getAttribute('data-order'), r.getAttribute('data-reason')])).toEqual([['FOTG-20261012-ABC234', 'NO_COMMISSION_PLAN'], ['FOTG-20261012-XYZ789', 'SHARED_PROMOTION_SPLIT_UNDEFINED']])
    expect(rows[0]).toHaveTextContent('No commission plan applied when the order was placed'); expect(rows[0]).toHaveTextContent('₹500.00')
    expect(rows[1]).toHaveTextContent('no split between restaurant and platform is defined'); expect(rows[1]).toHaveTextContent('SAVE50 · Shared'); expect(rows[1]).toHaveTextContent('₹50.00')

    // No plan: a rate, what it is taken from and a reason. Nothing about a discount is asked.
    await user.click(within(rows[0]).getByTestId('fin-allocation-decide'))
    let dialog = await screen.findByTestId('fin-allocation-dialog')
    expect(dialog).toHaveTextContent('Order FOTG-20261012-ABC234 — Burger Hub'); expect(within(dialog).queryByTestId('ff-restaurant_discount')).toBeNull()
    await user.type(within(dialog).getByTestId('ff-rate'), '12,5'); await user.type(within(dialog).getByTestId('ff-reason'), 'short'); await user.click(within(dialog).getByTestId('finance-dialog-confirm'))
    expect(within(dialog).getByTestId('ff-reason-error')).toBeInTheDocument(); expect(posted('/decide')).toHaveLength(0)
    await user.clear(within(dialog).getByTestId('ff-reason')); await user.type(within(dialog).getByTestId('ff-reason'), 'Standard terms were agreed later'); await user.click(within(dialog).getByTestId('finance-dialog-confirm'))
    expect(within(dialog).getByTestId('ff-rate-error')).toBeInTheDocument(); expect(posted('/decide')).toHaveLength(0)
    routes[`POST ${F}/allocations/${AL1}/decide`] = { body: { allocation: allocation({ status: 'RECOGNIZED', review_reason: null }) } }
    await user.clear(within(dialog).getByTestId('ff-rate')); await user.type(within(dialog).getByTestId('ff-rate'), '12.5'); await user.click(within(dialog).getByTestId('finance-dialog-confirm'))
    expect(await screen.findByTestId('fin-allocation-notice')).toHaveTextContent('Terms decided for order FOTG-20261012-ABC234: it is now Recognized.')
    expect(posted(`/allocations/${AL1}/decide`)[0].body).toEqual({ rate_bps: 1250, base_policy: 'MERCHANDISE_GROSS', reason: 'Standard terms were agreed later' })

    // A shared promotion without a split: the restaurant's share of the discount is asked for, from 0 to the discount.
    await user.click(within(within(screen.getByTestId('fin-allocations-table')).getAllByTestId('fin-allocation-row')[1]).getByTestId('fin-allocation-decide'))
    dialog = await screen.findByTestId('fin-allocation-dialog')
    await user.type(within(dialog).getByTestId('ff-rate'), '10'); await user.selectOptions(within(dialog).getByTestId('ff-base_policy'), 'MERCHANDISE_NET_OF_ALL_DISCOUNTS'); await user.type(within(dialog).getByTestId('ff-restaurant_discount'), '60')
    await user.type(within(dialog).getByTestId('ff-reason'), 'Half of the discount each, as agreed'); await user.click(within(dialog).getByTestId('finance-dialog-confirm'))
    expect(within(dialog).getByTestId('ff-restaurant_discount-error')).toHaveTextContent('from 0 to ₹50.00'); expect(posted(`/allocations/${AL2}/decide`)).toHaveLength(0)
    routes[`POST ${F}/allocations/${AL2}/decide`] = { body: { allocation: allocation({ id: AL2, order: { id: 'o2', number: 'FOTG-20261012-XYZ789' }, status: 'PENDING' }) } }
    await user.clear(within(dialog).getByTestId('ff-restaurant_discount')); await user.type(within(dialog).getByTestId('ff-restaurant_discount'), '25'); await user.click(within(dialog).getByTestId('finance-dialog-confirm'))
    await waitFor(() => expect(posted(`/allocations/${AL2}/decide`)).toHaveLength(1))
    expect(posted(`/allocations/${AL2}/decide`)[0].body).toEqual({ rate_bps: 1000, base_policy: 'MERCHANDISE_NET_OF_ALL_DISCOUNTS', restaurant_discount_minor: 2500, reason: 'Half of the discount each, as agreed' })
    // The order's own amounts are never sent.
    expect(JSON.stringify(posted('/decide').map((c) => c.body))).not.toMatch(/customer_total|merchandise|"discount_minor"|tax|fee|currency/)
    cleanup()

    // Without the permission to decide, the list is read-only.
    localStorage.setItem(K.session, 'adm-nina'); mount('?tab=adjustments&view=terms')
    expect(within(await screen.findByTestId('fin-allocations-table')).queryByTestId('fin-allocation-decide')).toBeNull()
  }, 30000)

  it('reports: commission per group and currency, what refunds cost and who bore them, and the provider\'s own costs — gross marketplace sales are never called revenue', async () => {
    const user = userEvent.setup()
    mount('?tab=reports')
    const commission = await screen.findByTestId('fin-report-commission')
    const heads = within(commission).getAllByRole('columnheader').map((h) => h.textContent)
    expect(heads).toEqual(['Day', 'Currency', 'Orders', 'Gross marketplace sales', 'Commission base', 'Commission', 'Restaurant earnings', 'Platform-funded discount', 'Restaurant-funded discount'])
    expect(commission).not.toHaveTextContent(/revenue/i)
    const rows = within(commission).getAllByTestId('fin-report-commission-row')
    expect(rows.map((r) => r.getAttribute('data-currency'))).toEqual(['INR', 'USD'])   // one row per currency: never one sum
    expect(rows[0]).toHaveTextContent('₹4,500.00'); expect(rows[0]).toHaveTextContent('₹562.50'); expect(rows[0]).toHaveTextContent('₹3,937.50'); expect(rows[0]).toHaveTextContent('₹10.00'); expect(rows[1]).toHaveTextContent('$12.00'); expect(rows[1]).not.toHaveTextContent('₹')
    expect(screen.getByTestId('fin-report-period-text')).toHaveTextContent('2026-09-13 to 2026-10-12 · Asia/Kolkata')

    const refunds = await screen.findByTestId('fin-report-refunds')
    expect(within(refunds).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Currency', 'Refunds', 'Refund amount', 'Returned before recognition', 'Borne by restaurants', 'Borne by the platform', 'Awaiting decision'])
    expect(within(refunds).getByTestId('fin-report-refunds-row')).toHaveTextContent('₹1,500.00'); expect(within(refunds).getByTestId('fin-report-refunds-row')).toHaveTextContent('₹500.00 (1)')
    expect(screen.getByTestId('fin-report-liability')).toHaveTextContent('Refund liability by default: UNASSIGNED · PENDING BUSINESS / LEGAL APPROVAL.')

    const costs = await screen.findByTestId('fin-report-provider-costs')
    expect(within(costs).getByTestId('fin-report-provider-costs-row')).toHaveTextContent('2026-10-11'); expect(costs).toHaveTextContent('₹6,000.00'); expect(costs).toHaveTextContent('₹120.00'); expect(costs).toHaveTextContent('₹21.60')
    expect(screen.getByTestId('fin-report-provider-note')).toHaveTextContent('Source: PROVIDER_REPORTED — never estimated.')

    // Grouping by plan shows negotiated rates: offered with the commission permission, and then with the rate and its base.
    const group = screen.getByTestId('fin-report-group')
    expect(within(group).getAllByRole('option').map((o) => (o as HTMLOptionElement).value)).toEqual(['day', 'location', 'organization', 'market', 'plan'])
    routes[`GET ${F}/reports/commission`] = { body: { period: PERIOD, group_by: 'plan', rows: [commissionRow('India standard (fixture)', inr, { group: 'plan', key: PLAN, rate_bps: 1250, base_policy: 'MERCHANDISE_GROSS' })] } }
    await user.selectOptions(group, 'plan')
    await waitFor(() => expect(screen.getByTestId('fin-report-commission')).toHaveAttribute('data-group', 'plan'))
    expect(calls.some((c) => c.path.includes(`${F}/reports/commission?`) && c.path.includes('group_by=plan'))).toBe(true)
    expect(screen.getByTestId('fin-report-commission-row')).toHaveTextContent('12.50%'); expect(screen.getByTestId('fin-report-commission-row')).toHaveTextContent('Merchandise total before discounts')
    fireEvent.change(screen.getByTestId('fin-report-from'), { target: { value: '2026-10-01' } })
    await waitFor(() => { for (const report of ['commission', 'customer-returns', 'provider-costs']) expect(calls.some((c) => c.path.includes(`${F}/reports/${report}?`) && c.path.includes('from=2026-10-01'))).toBe(true) })
    expect(calls.filter((c) => c.path.includes('/reports/')).every((c) => c.method === 'GET')).toBe(true)
    cleanup()

    // An administrator of one market: provider costs are not split by market — said plainly, not shown as an error. No plan grouping without the commission permission.
    localStorage.setItem(K.session, 'adm-nina'); routes[`GET ${F}/reports/commission`] = { body: { period: PERIOD, group_by: 'day', rows: [] } }
    routes[`GET ${F}/reports/provider-costs`] = { status: 403, body: { error: { code: 'forbidden', message: 'Provider costs are not split by market: this report needs the permission for all markets.', details: {} } } }
    mount('?tab=reports')
    expect(await screen.findByTestId('fin-report-provider-scoped')).toHaveTextContent('Provider costs are not split by market')
    expect(screen.queryByTestId('fin-report-provider-costs')).toBeNull(); expect(screen.queryByRole('alert')).toBeNull()
    expect(await screen.findByTestId('fin-report-commission-empty')).toHaveTextContent('No order was recognized in this period.')
    expect(within(screen.getByTestId('fin-report-group')).getAllByRole('option').map((o) => (o as HTMLOptionElement).value)).toEqual(['day', 'location', 'organization', 'market'])
  }, 30000)

  it('the ledger is read-only and behind its own permission: accounts, transactions with their debit and credit lines, and whether they balance', async () => {
    const user = userEvent.setup()
    mount('?tab=ledger')
    const accounts = await screen.findByTestId('fin-ledger-accounts-table')
    const account = within(accounts).getByTestId('fin-ledger-account-row')
    expect(account).toHaveAttribute('data-code', 'RESTAURANT_PAYABLE'); expect(account).toHaveTextContent('Restaurant payable'); expect(account).toHaveTextContent('Burger Hub'); expect(account).toHaveTextContent('₹9,000.00'); expect(account).toHaveTextContent('₹13,434.50'); expect(account).toHaveTextContent('₹4,434.50')
    expect(within(accounts).queryAllByRole('button')).toEqual([])
    expect(screen.getByTestId('fin-ledger-readonly')).toHaveTextContent('The ledger is read-only here')

    await user.click(screen.getByTestId('fin-view-transactions'))
    const table = await screen.findByTestId('fin-ledger-transactions-table')
    expect(within(table).getByTestId('fin-ledger-transaction-row')).toHaveTextContent('Payout paid'); expect(table).toHaveTextContent('PO-0E00000000'); expect(table).toHaveTextContent('₹3,887.50')
    await user.selectOptions(screen.getByTestId('fin-ledger-type'), 'PAYOUT_PAID'); fireEvent.change(screen.getByTestId('fin-ledger-from'), { target: { value: '2026-10-01' } }); fireEvent.change(screen.getByTestId('fin-ledger-to'), { target: { value: '2026-10-12' } })
    await user.type(within(screen.getByTestId('fin-ledger-filters')).getByTestId('table-search'), 'PO-0E00000000')
    await waitFor(() => expect(calls.some((c) => c.path.includes(`${F}/ledger/transactions?`) && ['filter[type]=PAYOUT_PAID', 'filter[from]=2026-10-01', 'filter[to]=2026-10-12', 'filter[reference]=PO-0E00000000'].every((part) => c.path.includes(part)))).toBe(true))

    await user.click(within(await screen.findByTestId('fin-ledger-transactions-table')).getByRole('button', { name: /view/i }))
    const detail = await screen.findByTestId('fin-ledger-transaction')
    expect(detail).toHaveAttribute('data-balanced', 'true'); expect(within(detail).getByTestId('fin-ledger-balanced')).toHaveTextContent('Balanced')
    const entries = within(detail).getAllByTestId('fin-ledger-entry')
    expect(entries.map((e) => e.getAttribute('data-direction'))).toEqual(['DEBIT', 'CREDIT'])
    expect(within(within(detail).getByTestId('fin-ledger-entries')).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['#', 'Account', 'Debit', 'Credit', 'Description'])
    expect(entries[0].querySelectorAll('td')[2]).toHaveTextContent('₹3,887.50'); expect(entries[0].querySelectorAll('td')[3]).toHaveTextContent(''); expect(entries[1].querySelectorAll('td')[2]).toHaveTextContent(''); expect(entries[1].querySelectorAll('td')[3]).toHaveTextContent('₹3,887.50')
    // No write control of any kind: nothing to type an entry into, no button that posts, reverses or edits — and nothing but GET was sent.
    expect(detail.querySelectorAll('input, select, textarea')).toHaveLength(0)
    expect(screen.queryAllByRole('button').map((b) => b.textContent ?? '').filter((x) => /post|reverse|edit|new|add|delete|adjust/i.test(x))).toEqual([])
    expect(calls.filter((c) => c.path.includes('/ledger/')).every((c) => c.method === 'GET')).toBe(true)
    cleanup()

    // An unbalanced transaction is said to be one.
    routes[`GET ${F}/ledger/transactions/${TX}`] = { body: { transaction: { ...TRANSACTION, detail: null, entries: [ENTRIES[0]], balanced: false } } }
    mount('?tab=ledger&view=transactions')
    await user.click(within(await screen.findByTestId('fin-ledger-transactions-table')).getByRole('button', { name: /view/i }))
    expect(within(await screen.findByTestId('fin-ledger-transaction')).getByTestId('fin-ledger-balanced')).toHaveTextContent('Not balanced')
    cleanup()

    // Without admin.finance.ledger.view there is no Ledger tab, and a link to it asks the backend for nothing.
    localStorage.setItem(K.session, 'adm-nina'); calls = []; mount('?tab=ledger')
    await screen.findByTestId('fin-currency-INR')
    expect(tabNames()).not.toContain('Ledger'); expect(screen.queryByTestId('fin-tab-ledger')).toBeNull(); expect(calls.some((c) => c.path.includes('/ledger/'))).toBe(false)
  }, 30000)

  it('the record of finance jobs is listed under reconciliation, and every tab and row has a stable handle for an end-to-end test', async () => {
    const user = userEvent.setup()
    mount('?tab=reconciliation&view=runs')
    const runs = within(await screen.findByTestId('fin-runs-table')).getAllByTestId('fin-run-row')
    expect(runs.map((r) => [r.getAttribute('data-kind'), r.getAttribute('data-status')])).toEqual([['VERIFY_LEDGER', 'OK'], ['RECONCILE_INTERNAL', 'PROBLEMS']])
    expect(runs[0]).toHaveTextContent('Verify ledger'); expect(runs[0]).toHaveTextContent('OK'); expect(runs[1]).toHaveTextContent('Problems'); expect(runs[1]).toHaveTextContent('2')
    expect(runs[1].querySelector('.db-badge--red')).not.toBeNull(); expect(runs[0].querySelector('.db-badge--green')).not.toBeNull()

    for (const tab of ['overview', 'settlements', 'payouts', 'payables', 'commission', 'reconciliation', 'adjustments', 'reports', 'ledger']) expect(screen.getByTestId(`fin-tab-${tab}`)).toHaveAttribute('role', 'tab')
    for (const view of ['differences', 'provider', 'runs']) expect(screen.getByTestId(`fin-view-${view}`)).toBeInTheDocument()
    await user.click(screen.getByTestId('fin-view-differences'))
    expect(within(await screen.findByTestId('fin-differences-table')).getByTestId('fin-difference-row')).toHaveAttribute('data-id', D1)
    expect(screen.getByTestId('fin-check-run')).toBeInTheDocument(); expect(screen.getByTestId('fin-import-day')).toBeInTheDocument()
    await user.click(screen.getByTestId('fin-tab-settlements'))
    const row = within(await screen.findByTestId('fin-settlements-table')).getByTestId('fin-settlement-row')
    expect(row).toHaveAttribute('data-ref', 'STL-20261012-0001'); expect(row).toHaveAttribute('data-status', 'CALCULATED'); expect(screen.getByTestId('fin-queue-approved')).toHaveAttribute('role', 'tab')
    await user.click(screen.getByTestId('fin-tab-payouts'))
    expect(within(await screen.findByTestId('fin-payouts-table')).getByTestId('fin-payout-row')).toHaveAttribute('data-ref', 'PO-0E00000000'); expect(screen.getByTestId('fin-payout-provider')).toHaveTextContent('LIVE PAYOUT = DISABLED')
    await user.click(screen.getByTestId('fin-tab-payables'))
    expect(within(await screen.findByTestId('fin-payables-table')).getByTestId('fin-payable-row')).toHaveAttribute('data-negative', 'true')
    await user.click(screen.getByTestId('fin-tab-commission'))
    expect(within(await screen.findByTestId('fin-plans-table')).getByTestId('fin-plan-row')).toHaveAttribute('data-approval', 'TEST')
    await user.click(screen.getByTestId('fin-tab-adjustments'))
    expect(within(await screen.findByTestId('fin-adjustments-table')).getByTestId('fin-adjustment-row')).toHaveAttribute('data-status', 'PENDING_DECISION')
    await user.click(screen.getByTestId('fin-view-holds'))
    expect(within(await screen.findByTestId('fin-holds-table')).getByTestId('fin-hold-row')).toHaveAttribute('data-status', 'ACTIVE')
    await user.click(screen.getByTestId('fin-tab-overview'))
    const figure = await screen.findByTestId('fin-kpi-restaurant_payables-INR')
    expect(figure).toHaveAttribute('data-amount', '443450'); expect(figure.textContent).toBe('₹4,434.50'); expect(screen.getByTestId('fin-kpi-gross_marketplace_sales-USD')).toHaveAttribute('data-amount', '1200')
    expect(screen.getByTestId('fin-live-payouts')).toHaveTextContent('LIVE PAYOUTS: DISABLED')
  }, 30000)
})

/**
 * A restaurant's own finances on the backend (Module 36): what it earned, what it is owed, its settlement statements
 * and the state of its payouts. READ-ONLY — nothing here can change an amount, a commission, a settlement or a payout.
 *
 *   GET /restaurant/finance/summary                    balances and periods per currency, a 30-day series, the words for each figure
 *   GET /restaurant/finance/earnings                   the earnings lines (state, type, page)
 *   GET /restaurant/finance/earnings/export            the same lines as CSV (from / to, at most 92 days)
 *   GET /restaurant/finance/settlements[/{id}]         settlement statements (status filter) and one statement with its lines and payouts
 *   GET /restaurant/finance/settlements/{id}/export    the statement as CSV — a statement, not a tax invoice
 *   GET /restaurant/finance/payouts                    payout states (+ the masked payout destination for an owner)
 *   GET /restaurant/finance/terms                      the commercial terms that apply right now
 *
 * Every call takes an optional ?location=<backend location id>; without it the answer covers every location the
 * signed-in person may read. The answers are used as they arrive (snake_case; money is {amount: minor units,
 * currency}) — the browser adds nothing up and never combines currencies.
 */
import { api, apiDownload } from '../../api/client'

const R = { context: 'restaurant' as const }

export type MoneyDto = { amount: number; currency: string }
export type EarningsPeriodDto = { orders: number; gross_sales: MoneyDto; restaurant_discount: MoneyDto; commission: MoneyDto; adjustments: MoneyDto; net_earnings: MoneyDto }
export type EarningsCurrencyDto = {
  currency: string; pending: MoneyDto; pending_orders: number; awaiting_terms_orders: number
  available: MoneyDto; held: MoneyDto; in_settlement: MoneyDto; paid_out: MoneyDto; balance: MoneyDto
  lifetime: EarningsPeriodDto; periods: { today: EarningsPeriodDto; this_week: EarningsPeriodDto; this_month: EarningsPeriodDto }
}
export type FinanceLocationDto = { id: string; name: string; currency: string }
export type EarningsSummaryDto = {
  scope: { locations: FinanceLocationDto[]; all_readable: boolean }; timezone: string
  currencies: EarningsCurrencyDto[]; series: Array<EarningsPeriodDto & { day: string; currency: string }>
  definitions: Record<string, string>
  settlement_policy: { cadence: string; earnings_available_when: string; approval: string; note: string }
  payouts: { live: boolean; note: string }
}
export type EarningState = 'AVAILABLE' | 'HELD' | 'IN_SETTLEMENT' | 'SETTLED' | 'PAID'
export type EarningLineDto = {
  id: string; type: string; description: string; order_number: string | null; currency: string
  gross_sales: MoneyDto; restaurant_discount: MoneyDto; commission: MoneyDto; tax_passed: MoneyDto; fee_passed: MoneyDto; amount: MoneyDto
  state: EarningState; settlement: { id: string; reference: string } | null; available_at: string | null
}
export type RestaurantSettlementDto = {
  id: string; reference: string; restaurant: { id: string | null; name: string }; currency: string; status: RestaurantSettlementStatus
  period: { start: string | null; end: string | null; timezone: string; cadence: string }
  summary: {
    completed_orders: number; lines: number; gross_sales: MoneyDto; restaurant_discounts: MoneyDto; commission: MoneyDto; tax_passed: MoneyDto; fees_passed: MoneyDto
    refund_adjustments: MoneyDto; other_adjustments: MoneyDto; net_payable: MoneyDto; amount_paid: MoneyDto; amount_pending: MoneyDto
  }
  document: { kind: string; tax_invoice: boolean }; generated_at: string | null; approved_at: string | null; paid_at: string | null
}
export type SettlementLineDto = {
  line: number; type: string; description: string; order_number: string | null; source: string | null
  gross_sales: MoneyDto; restaurant_discount: MoneyDto; commission: MoneyDto; tax_passed: MoneyDto; fee_passed: MoneyDto; amount: MoneyDto; occurred_at: string | null
}
export type RestaurantPayoutDto = {
  id: string; reference: string; settlement: { id: string; reference: string } | null; restaurant: { id: string | null; name: string }
  amount: MoneyDto; currency: string; status: RestaurantPayoutStatus; method: string; destination: string | null
  requested_at: string | null; completed_at: string | null; failed_at: string | null
}
export type RestaurantSettlementDetailDto = RestaurantSettlementDto & { lines: SettlementLineDto[]; payouts: RestaurantPayoutDto[] }
/** Where payouts go — only ever the provider's mask of it, and only for a person who may see it. */
export type PayoutAccountDto = { location: { id: string; name: string }; status: string; provider: string | null; destination: string | null; verified_at: string | null; test_only: boolean | null }
export type RestaurantTermsDto = {
  visible: boolean
  terms: Array<{ location: { id: string; name: string }; currency: string; commission: { type: string; rate_bps: number; base_policy: string; applies_to: string; effective_from: string | null; effective_until: string | null; test_fixture: boolean } | null }>
  provider_fees: { borne_by: string; approval: string }; approval: string
}
type ListDto<T> = { data: T[]; meta: { total: number; current_page: number; per_page: number } }
export type FinancePage<T> = { items: T[]; total: number; page: number; pageSize: number }
export type EarningsFilter = { location?: string | null; state?: string; type?: string; page?: number; pageSize?: number }

export const EARNING_STATES = ['AVAILABLE', 'HELD', 'IN_SETTLEMENT', 'SETTLED', 'PAID'] as const
/**
 * The words a RESTAURANT is given for a settlement and for a payout. The backend's internal states (calculated, under
 * review, submitting, unknown …) never reach a restaurant: IN_REVIEW is a statement that is not approved yet,
 * PAYMENT_PENDING one whose payout is on its way or has to be sent again, and a payout is PROCESSING until it is PAID.
 */
export const SETTLEMENT_STATUSES = ['IN_REVIEW', 'APPROVED', 'PAYMENT_PENDING', 'PAID', 'CLOSED'] as const
export type RestaurantSettlementStatus = (typeof SETTLEMENT_STATUSES)[number]
export const PAYOUT_STATUSES = ['PROCESSING', 'PAID', 'FAILED'] as const
export type RestaurantPayoutStatus = (typeof PAYOUT_STATUSES)[number]
export const EARNING_TYPES = ['ORDER_EARNING', 'REFUND_ADJUSTMENT', 'DISPUTE_ADJUSTMENT', 'MANUAL_ADJUSTMENT'] as const
/** The longest period one export may cover (the backend's rule; checked here only to answer sooner). */
export const EXPORT_MAX_DAYS = 92
/** Basis points as a percentage with two decimals, in integer arithmetic (1250 → "12.50%"). */
export const ratePercent = (bps: number) => `${Math.trunc(bps / 100)}.${String(Math.abs(bps) % 100).padStart(2, '0')}%`

export interface RestaurantFinanceRepository {
  summary(location?: string | null): Promise<EarningsSummaryDto>
  earnings(f: EarningsFilter): Promise<FinancePage<EarningLineDto>>
  /** Downloads the earnings lines of a period as CSV; answers with the file name. */
  exportEarnings(f: { location?: string | null; from: string; to: string }): Promise<string>
  settlements(f: { location?: string | null; status?: string; page?: number; pageSize?: number }): Promise<FinancePage<RestaurantSettlementDto>>
  settlement(id: string): Promise<RestaurantSettlementDetailDto>
  exportSettlement(id: string, reference: string): Promise<string>
  payouts(f: { location?: string | null; page?: number; pageSize?: number }): Promise<FinancePage<RestaurantPayoutDto> & { accounts: PayoutAccountDto[]; livePayouts: boolean }>
  terms(location?: string | null): Promise<RestaurantTermsDto>
}

const page = <T,>(res: ListDto<T>): FinancePage<T> => ({ items: res.data, total: res.meta.total, page: res.meta.current_page, pageSize: res.meta.per_page })
const all = (v?: string | null) => (v && v !== 'all' ? v : undefined)

export class ApiRestaurantFinanceRepository implements RestaurantFinanceRepository {
  summary(location?: string | null): Promise<EarningsSummaryDto> {
    return api<EarningsSummaryDto>('/restaurant/finance/summary', { ...R, query: { location: all(location) } })
  }

  async earnings(f: EarningsFilter): Promise<FinancePage<EarningLineDto>> {
    return page(await api<ListDto<EarningLineDto>>('/restaurant/finance/earnings', { ...R, query: { location: all(f.location), state: all(f.state), type: all(f.type), page: f.page ?? 1, per_page: f.pageSize ?? 25 } }))
  }

  exportEarnings(f: { location?: string | null; from: string; to: string }): Promise<string> {
    return apiDownload('/restaurant/finance/earnings/export', { ...R, query: { location: all(f.location), from: f.from, to: f.to }, filename: `earnings-${f.from}-to-${f.to}.csv` })
  }

  async settlements(f: { location?: string | null; status?: string; page?: number; pageSize?: number }): Promise<FinancePage<RestaurantSettlementDto>> {
    return page(await api<ListDto<RestaurantSettlementDto>>('/restaurant/finance/settlements', { ...R, query: { location: all(f.location), status: all(f.status), page: f.page ?? 1, per_page: f.pageSize ?? 25 } }))
  }

  async settlement(id: string): Promise<RestaurantSettlementDetailDto> {
    return (await api<{ settlement: RestaurantSettlementDetailDto }>(`/restaurant/finance/settlements/${encodeURIComponent(id)}`, R)).settlement
  }

  exportSettlement(id: string, reference: string): Promise<string> {
    return apiDownload(`/restaurant/finance/settlements/${encodeURIComponent(id)}/export`, { ...R, filename: `settlement-${reference}.csv` })
  }

  async payouts(f: { location?: string | null; page?: number; pageSize?: number }): Promise<FinancePage<RestaurantPayoutDto> & { accounts: PayoutAccountDto[]; livePayouts: boolean }> {
    const res = await api<ListDto<RestaurantPayoutDto> & { payout_accounts?: PayoutAccountDto[]; live_payouts?: boolean }>('/restaurant/finance/payouts', { ...R, query: { location: all(f.location), page: f.page ?? 1, per_page: f.pageSize ?? 25 } })
    return { ...page(res), accounts: res.payout_accounts ?? [], livePayouts: res.live_payouts === true }
  }

  terms(location?: string | null): Promise<RestaurantTermsDto> {
    return api<RestaurantTermsDto>('/restaurant/finance/terms', { ...R, query: { location: all(location) } })
  }
}

/**
 * The finance card of System Status (Module 36) — GET /admin/system/finance. States only; and nothing is green because
 * it exists: a ledger that was never checked, a development provider and a provider that is not configured are not.
 */
import { cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it } from 'vitest'
import { FinanceMetricsCard, type FinanceHealthDto } from './FinanceMetricsCard'

const HEALTH: FinanceHealthDto = {
  checked_at: '2026-10-12T06:00:00+00:00', window_hours: 24,
  ledger: { status: 'BALANCED', last_run_at: '2026-10-12T05:00:00+00:00', problems: 0, transactions: 120, entries: 310, imbalance_events: 0 },
  internal_reconciliation: { status: 'OK', last_run_at: '2026-10-12T05:05:00+00:00', problems: 0 },
  provider_reconciliation: { status: 'NEVER_RUN', last_run_at: null, problems: null, source_status: 'FAKE_PROVIDER', razorpay_settlement_api: 'PENDING PROVIDER VERIFICATION' },
  payout_provider: { status: 'FAKE_PROVIDER', driver: 'fake', mode: 'FAKE', live_payout: 'DISABLED', real_adapter: 'NOT_IMPLEMENTED', provider_account: 'PENDING PROVIDER ACCOUNT ENABLEMENT' },
  queue: { name: 'finance', unposted: { payments: 0, refunds: 0, completed_orders: 0 }, status: 'UP_TO_DATE' },
  differences_open: { total: 3, by_severity: { HIGH: 1, MEDIUM: 2 } },
  flags: { live_payouts: 'DISABLED', restaurant_kyc: 'NOT_IMPLEMENTED', gst_rate_hardcoded: false, tds_tcs_hardcoded: false, tax_invoice: 'PENDING TAX / ACCOUNTING DESIGN', commission_invoice: 'PENDING TAX / ACCOUNTING DESIGN', credit_note: 'PENDING TAX / ACCOUNTING DESIGN', withholding: 'PENDING PROFESSIONAL TAX REVIEW', production: 'NO' },
}
const green = (testId: string) => screen.getByTestId(testId).querySelectorAll('.db-badge--green').length
afterEach(() => cleanup())

describe('FinanceMetricsCard (Module 36)', () => {
  it('shows the ledger, the reconciliations, the payout provider, the queue, the open differences, the flags — and LIVE PAYOUTS: DISABLED', async () => {
    render(<FinanceMetricsCard locale="en" load={async () => HEALTH} />)
    const top = await screen.findByTestId('sys-finance')
    expect(top).toHaveAttribute('data-ledger', 'BALANCED'); expect(top).toHaveAttribute('data-payout-provider', 'FAKE_PROVIDER'); expect(top).toHaveAttribute('data-live-payouts', 'DISABLED')
    expect(screen.getByTestId('sys-finance-ledger')).toHaveTextContent('Balanced'); expect(green('sys-finance-ledger')).toBe(1)
    expect(screen.getByTestId('sys-finance-internal')).toHaveTextContent('OK'); expect(green('sys-finance-internal')).toBe(1)
    // The development provider is named as one and is not green — on the payout side and on the settlement source.
    expect(screen.getByTestId('sys-finance-payout-provider')).toHaveTextContent('Development provider'); expect(green('sys-finance-payout-provider')).toBe(0)
    expect(screen.getByTestId('sys-finance-provider-source')).toHaveTextContent('Development provider'); expect(green('sys-finance-provider-source')).toBe(0)
    expect(screen.getByTestId('sys-finance-live-payouts')).toHaveTextContent('LIVE PAYOUTS: DISABLED')
    expect(screen.getByTestId('sys-finance-queue')).toHaveTextContent('Up to date'); expect(screen.getByTestId('sys-finance-differences')).toHaveTextContent('3')
    expect(screen.getByTestId('sys-finance-queues')).toHaveTextContent('120 / 310')
    expect(screen.getByTestId('sys-finance-gst')).toHaveTextContent('No'); expect(screen.getByTestId('sys-finance-tds')).toHaveTextContent('No')
    expect(screen.getByTestId('sys-finance-tax-invoice')).toHaveTextContent('PENDING TAX / ACCOUNTING DESIGN'); expect(screen.getByTestId('sys-finance-credit-note')).toHaveTextContent('PENDING TAX / ACCOUNTING DESIGN')
    expect(screen.getByTestId('sys-finance-flags')).toHaveTextContent('Razorpay settlement API'); expect(screen.getByTestId('sys-finance-flags')).toHaveTextContent('PENDING PROVIDER VERIFICATION')
    expect(document.body.textContent).not.toMatch(/healthy|mark.*paid/i)
  })

  it('never checked, never run and not configured are never green; problems and a queue that is behind are said', async () => {
    render(<FinanceMetricsCard locale="en" load={async () => ({ ...HEALTH,
      ledger: { ...HEALTH.ledger, status: 'NEVER_CHECKED', last_run_at: null, problems: null }, internal_reconciliation: { status: 'NEVER_RUN', last_run_at: null, problems: null },
      provider_reconciliation: { ...HEALTH.provider_reconciliation, source_status: 'NOT_CONFIGURED' }, payout_provider: { ...HEALTH.payout_provider, status: 'NOT_CONFIGURED', driver: null, mode: null },
      queue: { name: 'finance', unposted: { payments: 2, refunds: 0, completed_orders: 1 }, status: 'BEHIND' }, differences_open: { total: 0, by_severity: [] } })} />)
    const top = await screen.findByTestId('sys-finance')
    expect(top.querySelectorAll('.db-badge--green')).toHaveLength(0); expect(screen.getByTestId('sys-finance-queues').querySelectorAll('.db-badge--green')).toHaveLength(0)
    expect(screen.getByTestId('sys-finance-ledger')).toHaveTextContent('Never checked'); expect(screen.getByTestId('sys-finance-internal')).toHaveTextContent('Never run')
    expect(screen.getByTestId('sys-finance-payout-provider')).toHaveTextContent('Not configured'); expect(screen.getByTestId('sys-finance-provider-source')).toHaveTextContent('Not configured')
    expect(screen.getByTestId('sys-finance-queue')).toHaveTextContent('Behind'); expect(screen.getByTestId('sys-finance-queues')).toHaveTextContent('3')   // 2 payments + 1 completed order wait to be posted
    cleanup()

    render(<FinanceMetricsCard locale="en" load={async () => ({ ...HEALTH, ledger: { ...HEALTH.ledger, status: 'PROBLEMS', problems: 2 }, flags: { ...HEALTH.flags, gst_rate_hardcoded: true } })} />)
    expect(await screen.findByTestId('sys-finance-ledger')).toHaveTextContent('Problems')
    expect(screen.getByTestId('sys-finance-ledger').querySelector('.db-badge--red')).not.toBeNull(); expect(screen.getByTestId('sys-finance-gst')).toHaveTextContent('Yes')
  })

  it('offers a retry when the status cannot be read', async () => {
    const user = userEvent.setup(); let fail = true
    render(<FinanceMetricsCard locale="en" load={async () => { if (fail) throw new Error('forbidden'); return HEALTH }} />)
    expect(await screen.findByText('Finance status unavailable')).toBeInTheDocument(); expect(screen.queryByTestId('sys-finance')).toBeNull()
    fail = false
    await user.click(screen.getByRole('button', { name: /try again|retry/i }))
    expect(await screen.findByTestId('sys-finance')).toBeInTheDocument()
  })
})

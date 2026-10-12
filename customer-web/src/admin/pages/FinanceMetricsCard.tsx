/**
 * Finance health from the backend (Module 36) — GET /admin/system/finance.
 *
 * Whether the ledger balances, when the reconciliations last ran, what the payout provider and the provider settlement
 * source are, what waits to be posted, and the flags that say what is NOT built or approved. States only: no amount,
 * no restaurant, no key. Nothing is shown as healthy because it exists — a ledger that was never checked, a development
 * provider and a provider that is not configured are never green. LIVE PAYOUTS are shown as the backend says: disabled.
 */
import { api } from '../../api/client'
import { Card, ErrorState, Skeleton } from '../../dashboard/components/ui'
import { t } from '../../i18n/strings'
import { Badge, Stat } from '../components/DataTable'
import { code, toneOfCode } from './finance/helpers'
import { useLoad } from './shared'

type RunState = { status: string; last_run_at: string | null; problems: number | null }
export type FinanceHealthDto = {
  checked_at: string; window_hours: number
  ledger: RunState & { transactions: number; entries: number; imbalance_events: number }
  internal_reconciliation: RunState
  provider_reconciliation: RunState & { source_status: string; razorpay_settlement_api: string }
  payout_provider: { status: string; driver: string | null; mode: string | null; live_payout: string; real_adapter: string; provider_account: string }
  queue: { name: string; unposted: Record<string, number> | []; status: string }
  differences_open: { total: number; by_severity: Record<string, number> | [] }
  flags: { live_payouts: string; restaurant_kyc: string; gst_rate_hardcoded: boolean; tds_tcs_hardcoded: boolean; tax_invoice: string; commission_invoice: string; credit_note: string; withholding: string; production: string }
}

const loadFinanceHealth = () => api<FinanceHealthDto>('/admin/system/finance', { context: 'admin' })

export function FinanceMetricsCard({ locale, load = loadFinanceHealth }: { locale: string; load?: () => Promise<FinanceHealthDto> }) {
  const { data, state, reload } = useLoad(load, [load])
  // A state is green only when the backend's word for it is one that means "checked and fine" (toneOfCode); never by default.
  const chip = (value: string) => <span data-status={value}><Badge tone={toneOfCode(value)}>{code(value, locale)}</Badge></span>
  const yesNo = (v: boolean) => t(v ? 'adm.finance.sys.yes' : 'adm.finance.sys.no', undefined, locale)
  const unposted = data ? Object.values(data.queue.unposted as Record<string, number>).reduce((n, v) => n + v, 0) : 0
  return (
    <Card title={t('adm.finance.sys.title', undefined, locale)} subtitle={t('adm.finance.sys.subtitle', undefined, locale)} actions={data ? <span data-testid="sys-finance-live-payouts"><Badge tone="muted">{t('adm.finance.livePayouts', { state: data.flags.live_payouts }, locale)}</Badge></span> : undefined}>
      {state === 'loading' && <Skeleton rows={2} />}
      {state === 'error' && <ErrorState title={t('adm.finance.sys.unavailable', undefined, locale)} onRetry={() => { void reload() }} locale={locale} />}
      {state === 'ready' && data && <>
        <div className="adm-stats" data-testid="sys-finance" data-ledger={data.ledger.status} data-payout-provider={data.payout_provider.status} data-live-payouts={data.flags.live_payouts}>
          <Stat label={t('adm.finance.health.ledger', undefined, locale)} value={<span data-testid="sys-finance-ledger">{chip(data.ledger.status)}</span>} />
          <Stat label={t('adm.finance.health.internal_reconciliation', undefined, locale)} value={<span data-testid="sys-finance-internal">{chip(data.internal_reconciliation.status)}</span>} />
          <Stat label={t('adm.finance.health.provider_reconciliation', undefined, locale)} value={<span data-testid="sys-finance-provider-source">{chip(data.provider_reconciliation.source_status)}</span>} />
          <Stat label={t('adm.finance.health.payout_provider', undefined, locale)} value={<span data-testid="sys-finance-payout-provider">{chip(data.payout_provider.status)}</span>} />
        </div>
        <div className="adm-stats" data-testid="sys-finance-queues">
          <Stat label={t('adm.finance.health.queue', undefined, locale)} value={<span data-testid="sys-finance-queue">{chip(data.queue.status)}</span>} />
          <Stat label={t('adm.finance.sys.unposted', undefined, locale)} value={unposted.toLocaleString(locale)} tone={unposted > 0 ? 'amber' : undefined} />
          <Stat label={t('adm.finance.queue.differences', undefined, locale)} value={<span data-testid="sys-finance-differences">{data.differences_open.total.toLocaleString(locale)}</span>} tone={data.differences_open.total > 0 ? 'red' : undefined} />
          <Stat label={t('adm.finance.sys.ledgerSize', undefined, locale)} value={`${data.ledger.transactions.toLocaleString(locale)} / ${data.ledger.entries.toLocaleString(locale)}`} />
        </div>
        <dl className="adm-details" data-testid="sys-finance-flags">
          <div><dt>{t('adm.finance.sys.gst', undefined, locale)}</dt><dd data-testid="sys-finance-gst">{yesNo(data.flags.gst_rate_hardcoded)}</dd></div>
          <div><dt>{t('adm.finance.sys.tds', undefined, locale)}</dt><dd data-testid="sys-finance-tds">{yesNo(data.flags.tds_tcs_hardcoded)}</dd></div>
          <div><dt>{t('adm.finance.sys.taxInvoice', undefined, locale)}</dt><dd data-testid="sys-finance-tax-invoice">{data.flags.tax_invoice}</dd></div>
          <div><dt>{t('adm.finance.sys.creditNote', undefined, locale)}</dt><dd data-testid="sys-finance-credit-note">{data.flags.credit_note}</dd></div>
          <div><dt>{t('adm.finance.sys.kyc', undefined, locale)}</dt><dd>{code(data.flags.restaurant_kyc, locale)}</dd></div>
          <div><dt>{t('adm.finance.sys.razorpay', undefined, locale)}</dt><dd>{data.provider_reconciliation.razorpay_settlement_api}</dd></div>
        </dl>
        <p className="db-muted" data-testid="sys-finance-note">{t('adm.finance.sys.note', undefined, locale)}</p>
      </>}
    </Card>
  )
}

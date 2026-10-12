import { useState } from 'react'
import { Card, ErrorState, Skeleton } from '../../../dashboard/components/ui'
import { t } from '../../../i18n/strings'
import { useAdmin } from '../../AdminContext'
import type { Counts, MoneyDto } from '../../api/ApiAdminFinanceRepository'
import { Badge, DevNote } from '../../components/DataTable'
import { useLoad } from '../shared'
import { code, fm, type FinanceTabProps } from './helpers'
import { Chip } from './shared'

/**
 * Finance overview (Module 36): what moved in a period and what stands now, per currency, read from the ledger by the
 * backend. Gross marketplace sales are shown as that — they are not FoodOnTheGo revenue — and provider fees are the
 * provider's own figures, never an estimate. One block per currency: nothing is added across currencies.
 */
const PERIOD = ['customer_payments', 'refunds', 'gross_marketplace_sales', 'platform_commission', 'platform_fee_revenue', 'platform_revenue', 'promotion_expense', 'refund_expense', 'restaurant_earnings', 'provider_fees', 'provider_fee_tax', 'provider_settlements_received', 'restaurant_payouts'] as const
const STANDING = ['restaurant_payables', 'payment_clearing', 'provider_clearing', 'refund_clearing', 'dispute_clearing', 'tax_held'] as const
const HEALTH = ['ledger', 'internal_reconciliation', 'provider_reconciliation', 'payout_provider', 'queue'] as const
const sum = (c: Counts | undefined) => Object.values(c ?? {}).reduce((n, v) => n + v, 0)

export function OverviewTab({ repo, set }: FinanceTabProps) {
  const a = useAdmin(); const locale = a.locale
  const [from, setFrom] = useState(''); const [to, setTo] = useState('')
  const { data, state, reload } = useLoad(() => repo.overview(from, to), [repo, from, to])
  const hint = (key: string) => (key === 'gross_marketplace_sales' ? t('adm.finance.overview.notRevenue', undefined, locale) : key === 'provider_fees' ? t('adm.finance.overview.providerReported', undefined, locale) : null)
  return <>
    <Card>
      <div className="db-toolbar adm-toolbar" data-testid="fin-period">
        <label className="adm-select"><span className="db-field__label">{t('adm.finance.from', undefined, locale)}</span> <input type="date" className="db-input db-input--sm" value={from} max={to || undefined} onChange={(e) => setFrom(e.target.value)} data-testid="fin-from" /></label>
        <label className="adm-select"><span className="db-field__label">{t('adm.finance.to', undefined, locale)}</span> <input type="date" className="db-input db-input--sm" value={to} min={from || undefined} onChange={(e) => setTo(e.target.value)} data-testid="fin-to" /></label>
        {data && <span className="db-muted" data-testid="fin-period-text">{t('adm.finance.overview.period', { from: data.period.from, to: data.period.to, zone: data.period.timezone }, locale)}</span>}
      </div>
    </Card>
    {state === 'error' && <ErrorState title={t('adm.finance.error.title', undefined, locale)} text={t('adm.finance.error.text', undefined, locale)} onRetry={() => { void reload() }} locale={locale} />}
    {state === 'loading' && <Card><Skeleton rows={6} /></Card>}
    {state === 'ready' && data && <>
      <Card title={t('adm.finance.overview.health', undefined, locale)}>
        <div className="db-toolbar" data-testid="fin-health">
          {HEALTH.map((k) => <span key={k} data-health={k}>{t(`adm.finance.health.${k}`, undefined, locale)}: <Chip value={data.health[k]} locale={locale} dot={false} /></span>)}
          <span data-testid="fin-live-payouts"><Badge tone="muted">{t('adm.finance.livePayouts', { state: data.health.live_payouts }, locale)}</Badge></span>
        </div>
      </Card>
      {data.currencies.length === 0 && <Card><p className="db-muted" data-testid="fin-overview-empty">{t('adm.finance.overview.empty', undefined, locale)}</p></Card>}
      {data.currencies.map((c) => (
        <Card key={c.currency} title={t('adm.finance.currency', { currency: c.currency }, locale)} subtitle={t('adm.finance.overview.orders', { n: Number(c.period.orders_recognized ?? 0) }, locale)}>
          <div data-testid={`fin-currency-${c.currency}`}>
            <div className="adm-stats">
              {PERIOD.filter((k) => typeof c.period[k] === 'object').map((k) => (
                <div key={k} className="adm-stat" data-testid={`fin-${c.currency}-${k}`}><span className="adm-stat__value" data-testid={`fin-kpi-${k}-${c.currency}`} data-amount={(c.period[k] as MoneyDto).amount}>{fm(c.period[k] as MoneyDto, locale)}</span><span className="adm-stat__label">{t(`adm.finance.kpi.${k}`, undefined, locale)}{hint(k) && <small className="db-muted"> — {hint(k)}</small>}</span></div>
              ))}
            </div>
            <h3 className="db-card__sub" style={{ margin: '16px 0 8px' }}>{t('adm.finance.overview.standing', undefined, locale)}</h3>
            <div className="adm-stats">
              {STANDING.filter((k) => c.standing[k]).map((k) => (
                <div key={k} className="adm-stat" data-testid={`fin-${c.currency}-${k}`}><span className="adm-stat__value" data-testid={`fin-kpi-${k}-${c.currency}`} data-amount={c.standing[k].amount}>{fm(c.standing[k], locale)}</span><span className="adm-stat__label">{t(`adm.finance.kpi.${k}`, undefined, locale)}</span></div>
              ))}
            </div>
          </div>
        </Card>
      ))}
      <Card title={t('adm.finance.overview.queues', undefined, locale)}>
        <dl className="adm-details" data-testid="fin-queues">
          <div><dt>{t('adm.finance.tab.settlements', undefined, locale)}</dt><dd>{sum(data.queues.settlements) === 0 ? '0' : Object.entries(data.queues.settlements).map(([k, n]) => `${code(k, locale)}: ${n}`).join(' · ')}</dd></div>
          <div><dt>{t('adm.finance.tab.payouts', undefined, locale)}</dt><dd>{sum(data.queues.payouts) === 0 ? '0' : Object.entries(data.queues.payouts).map(([k, n]) => `${code(k, locale)}: ${n}`).join(' · ')}</dd></div>
          <div><dt>{t('adm.finance.queue.differences', undefined, locale)}</dt><dd>{data.queues.differences_open.total}{data.queues.differences_open.total > 0 ? ` (${Object.entries(data.queues.differences_open.by_severity).map(([k, n]) => `${code(k, locale)}: ${n}`).join(' · ')})` : ''}</dd></div>
          <div><dt>{t('adm.finance.queue.awaitingTerms', undefined, locale)}</dt><dd><button type="button" className="db-link" onClick={() => set({ tab: 'adjustments', view: 'terms', queue: 'all', page: '1', open: '' })} data-testid="fin-queue-awaiting-terms">{data.queues.allocations_awaiting_terms}</button></dd></div>
          <div><dt>{t('adm.finance.queue.liability', undefined, locale)}</dt><dd>{data.queues.liability_decisions_pending}</dd></div>
          <div><dt>{t('adm.finance.queue.approval', undefined, locale)}</dt><dd>{data.queues.adjustments_awaiting_approval}</dd></div>
          <div><dt>{t('adm.finance.queue.holds', undefined, locale)}</dt><dd>{data.queues.holds_active}</dd></div>
        </dl>
      </Card>
      <DevNote><span data-testid="fin-provider-fees">{data.provider_fees.note} {t('adm.finance.overview.imported', { n: data.provider_fees.records_imported }, locale)}</span></DevNote>
    </>}
  </>
}

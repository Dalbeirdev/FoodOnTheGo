import { useState, type ReactNode } from 'react'
import { ApiError } from '../../../api/client'
import { Card, ErrorState, Skeleton } from '../../../dashboard/components/ui'
import { t } from '../../../i18n/strings'
import { useAdmin } from '../../AdminContext'
import { COMMISSION_GROUPS, type ApiAdminFinanceRepository, type CommissionGroup, type ProviderCostReportDto } from '../../api/ApiAdminFinanceRepository'
import { Badge, DevNote } from '../../components/DataTable'
import { useLoad } from '../shared'
import { bpsToPercent, code, fm } from './helpers'

/**
 * Finance reports (Module 36), read-only: commission by day / restaurant / organization / market / plan, what refunds
 * cost and who bore them, and the payment provider's processing costs. Each row is one currency — nothing is added
 * across currencies. Gross marketplace sales are named that and never revenue; provider fees are the provider's own
 * figures, never an estimate. Grouping by plan shows negotiated rates and is offered only with the commission permission.
 */
type Load<T> = { data: T | null; state: 'loading' | 'ready' | 'error'; reload: () => Promise<void> }
function Report<T>({ load, locale, empty, isEmpty, testId, children }: { load: Load<T>; locale: string; empty: string; isEmpty: (d: T) => boolean; testId: string; children: (d: T) => ReactNode }) {
  if (load.state === 'error') return <ErrorState title={t('adm.finance.error.title', undefined, locale)} text={t('adm.finance.error.text', undefined, locale)} onRetry={() => { void load.reload() }} locale={locale} />
  if (load.state === 'loading' || !load.data) return <Skeleton rows={3} />
  if (isEmpty(load.data)) return <p className="db-muted" data-testid={`${testId}-empty`}>{empty}</p>
  return <div className="db-table-wrap" tabIndex={0}>{children(load.data)}</div>
}

export function ReportsTab({ repo }: { repo: ApiAdminFinanceRepository }) {
  const a = useAdmin(); const locale = a.locale
  const [from, setFrom] = useState(''); const [to, setTo] = useState(''); const [group, setGroup] = useState<CommissionGroup>('day')
  const groups = COMMISSION_GROUPS.filter((g) => g !== 'plan' || a.can('finance.commission.view'))
  const commission = useLoad(() => repo.commissionReport(from, to, group), [repo, from, to, group])
  const refunds = useLoad(() => repo.refundImpactReport(from, to), [repo, from, to])
  // Provider records carry no market: a market-scoped administrator is refused, and that is said instead of shown as an error.
  const costs = useLoad<ProviderCostReportDto | 'SCOPED'>(() => repo.providerCostReport(from, to).catch((e: unknown) => { if (e instanceof ApiError && e.kind === 'forbidden') return 'SCOPED' as const; throw e }), [repo, from, to])
  const period = commission.data?.period ?? refunds.data?.period
  const th = (key: string) => <th scope="col">{t(`adm.finance.col.${key}`, undefined, locale)}</th>
  const cur = (c: string) => <Badge tone="muted">{c}</Badge>
  return <>
    <Card>
      <div className="db-toolbar adm-toolbar" data-testid="fin-report-period">
        <label className="adm-select"><span className="db-field__label">{t('adm.finance.from', undefined, locale)}</span> <input type="date" className="db-input db-input--sm" value={from} max={to || undefined} onChange={(e) => setFrom(e.target.value)} data-testid="fin-report-from" /></label>
        <label className="adm-select"><span className="db-field__label">{t('adm.finance.to', undefined, locale)}</span> <input type="date" className="db-input db-input--sm" value={to} min={from || undefined} onChange={(e) => setTo(e.target.value)} data-testid="fin-report-to" /></label>
        {period && <span className="db-muted" data-testid="fin-report-period-text">{t('adm.finance.overview.period', { from: period.from, to: period.to, zone: period.timezone }, locale)}</span>}
      </div>
    </Card>

    <Card title={t('adm.finance.report.commission', undefined, locale)} subtitle={t('adm.finance.report.commission.subtitle', undefined, locale)} actions={
      <label className="adm-select"><span className="db-sr-only">{t('adm.finance.report.groupBy', undefined, locale)}</span>
        <select className="db-select db-input--sm" value={group} onChange={(e) => setGroup(groups.find((g) => g === e.target.value) ?? 'day')} aria-label={t('adm.finance.report.groupBy', undefined, locale)} data-testid="fin-report-group">{groups.map((g) => <option key={g} value={g}>{t(`adm.finance.report.group.${g}`, undefined, locale)}</option>)}</select>
      </label>}>
      <Report load={commission} locale={locale} empty={t('adm.finance.report.commission.empty', undefined, locale)} isEmpty={(d) => d.rows.length === 0} testId="fin-report-commission">{(d) => (
        <table className="db-table" data-testid="fin-report-commission" data-group={d.group_by}>
          <thead><tr><th scope="col">{t(`adm.finance.report.group.${d.group_by}`, undefined, locale)}</th>{th('currency')}{th('recognizedOrders')}{th('grossMarketplaceSales')}{th('commissionBase')}{th('commission')}{th('restaurantEarnings')}{th('platformDiscount')}{th('restaurantDiscount')}{d.group_by === 'plan' && <>{th('rate')}{th('basePolicy')}</>}</tr></thead>
          <tbody>{d.rows.map((r) => (
            <tr key={`${r.key ?? r.label ?? ''}:${r.currency}:${r.rate_bps ?? ''}:${r.base_policy ?? ''}`} data-testid="fin-report-commission-row" data-key={r.key ?? ''} data-currency={r.currency}>
              <th scope="row" dir="auto">{r.label ?? '—'}</th><td>{cur(r.currency)}</td><td>{r.orders.toLocaleString(locale)}</td><td>{fm(r.gross_marketplace_sales, locale)}</td><td>{fm(r.commission_base, locale)}</td><td><b>{fm(r.commission, locale)}</b></td><td>{fm(r.restaurant_earnings, locale)}</td><td>{fm(r.platform_funded_discount, locale)}</td><td>{fm(r.restaurant_funded_discount, locale)}</td>
              {d.group_by === 'plan' && <><td>{r.rate_bps === undefined ? '—' : bpsToPercent(r.rate_bps)}</td><td>{code(r.base_policy, locale, 'adm.finance.base')}</td></>}
            </tr>
          ))}</tbody>
        </table>
      )}</Report>
    </Card>

    <Card title={t('adm.finance.report.refunds', undefined, locale)} subtitle={t('adm.finance.report.refunds.subtitle', undefined, locale)}>
      <Report load={refunds} locale={locale} empty={t('adm.finance.report.refunds.empty', undefined, locale)} isEmpty={(d) => d.rows.length === 0} testId="fin-report-refunds">{(d) => (
        <table className="db-table" data-testid="fin-report-refunds">
          <thead><tr>{th('currency')}{th('refundCount')}{th('refundAmount')}{th('beforeRecognition')}{th('restaurantFunded')}{th('platformFunded')}{th('awaitingDecision')}</tr></thead>
          <tbody>{d.rows.map((r) => (
            <tr key={r.currency} data-testid="fin-report-refunds-row" data-currency={r.currency}><th scope="row">{cur(r.currency)}</th><td>{r.refund_count.toLocaleString(locale)}</td><td><b>{fm(r.refund_amount, locale)}</b></td><td>{fm(r.returned_before_recognition, locale)}</td><td>{fm(r.restaurant_funded, locale)}</td><td>{fm(r.platform_funded, locale)}</td><td>{fm(r.awaiting_decision, locale)}{r.awaiting_decision_count > 0 ? ` (${r.awaiting_decision_count})` : ''}</td></tr>
          ))}</tbody>
        </table>
      )}</Report>
      {refunds.data && <p className="db-muted" data-testid="fin-report-liability">{t('adm.finance.report.liability', { liability: refunds.data.liability_policy.default, approval: refunds.data.liability_policy.approval }, locale)}{refunds.data.liability_policy.configured_reasons.length > 0 ? ` ${t('adm.finance.report.liability.reasons', { reasons: refunds.data.liability_policy.configured_reasons.map((r) => code(r, locale)).join(', ') }, locale)}` : ''}</p>}
    </Card>

    <Card title={t('adm.finance.report.costs', undefined, locale)} subtitle={t('adm.finance.report.costs.subtitle', undefined, locale)}>
      {costs.data === 'SCOPED' ? <p className="adm-devnote" role="note" data-testid="fin-report-provider-scoped">{t('adm.finance.report.costs.scoped', undefined, locale)}</p> : <>
        {costs.data && <p className="db-muted" data-testid="fin-report-provider-note">{t('adm.finance.report.costs.note', { source: costs.data.source, estimated: t(costs.data.estimated ? 'adm.finance.report.costs.estimated' : 'adm.finance.report.costs.neverEstimated', undefined, locale), policy: code(costs.data.policy, locale) }, locale)}</p>}
        <Report load={costs as Load<ProviderCostReportDto>} locale={locale} empty={t('adm.finance.report.costs.empty', undefined, locale)} isEmpty={(d) => d.rows.length === 0} testId="fin-report-provider-costs">{(d) => (
          <table className="db-table" data-testid="fin-report-provider-costs">
            <thead><tr>{th('day')}{th('provider')}{th('currency')}{th('payments')}{th('refundCount')}{th('paymentAmount')}{th('fee')}{th('taxOnFee')}</tr></thead>
            <tbody>{d.rows.map((r) => (
              <tr key={`${r.day}:${r.provider}:${r.currency}`} data-testid="fin-report-provider-costs-row" data-day={r.day} data-currency={r.currency}><th scope="row">{r.day}</th><td>{r.provider}</td><td>{cur(r.currency)}</td><td>{r.payments.toLocaleString(locale)}</td><td>{r.refunds.toLocaleString(locale)}</td><td>{fm(r.payment_amount, locale)}</td><td><b>{fm(r.fee, locale)}</b></td><td>{fm(r.tax_on_fee, locale)}</td></tr>
            ))}</tbody>
          </table>
        )}</Report>
      </>}
    </Card>
    <DevNote>{t('adm.finance.report.note', undefined, locale)}</DevNote>
  </>
}

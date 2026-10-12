import { useState } from 'react'
import { Card } from '../../../dashboard/components/ui'
import { t } from '../../../i18n/strings'
import { useAdmin } from '../../AdminContext'
import { BASE_POLICIES, PROMOTION_REVIEW_REASONS, type AllocationDto } from '../../api/ApiAdminFinanceRepository'
import { Badge, DataTable, type Column } from '../../components/DataTable'
import { Cell, fmtDateTime, useLoad } from '../shared'
import { PAGE_SIZE, actionError, amountToMinor, code, fm, percentToBps, type FinanceTabProps } from './helpers'
import { FormDialog, type FormField } from './shared'

/**
 * Orders awaiting terms (Module 36): orders whose commercial terms were unknown when they were placed — no commission
 * plan applied, or a promotion did not say who pays for the discount. Nothing is guessed for them: a person states the
 * rate, what it is taken from and, for a promotion, the restaurant's share of the discount, with a reason. The order's
 * own amounts cannot be changed here — they are not even sent.
 */
export function AllocationsView({ repo, s, set }: FinanceTabProps) {
  const a = useAdmin(); const locale = a.locale; const page = Number(s.page) || 1; const canDecide = a.can('finance.adjustments.create')
  const { data, state, reload } = useLoad(() => repo.allocations({ status: 'REVIEW_REQUIRED', page, pageSize: PAGE_SIZE }), [repo, page])
  const [deciding, setDeciding] = useState<AllocationDto | null>(null); const [busy, setBusy] = useState(false); const [failed, setFailed] = useState<string | null>(null); const [notice, setNotice] = useState<string | null>(null)
  const why = (x: AllocationDto) => code(x.review_reason, locale, 'adm.finance.termsReason')
  const promotion = (x: AllocationDto | null) => !!x && PROMOTION_REVIEW_REASONS.includes(x.review_reason ?? '')
  const decide = async (v: Record<string, string>) => {
    if (!deciding) return
    setBusy(true); setFailed(null)
    try {
      const decided = await repo.decideAllocation(deciding.id, { rateBps: percentToBps(v.rate) ?? 0, basePolicy: v.base_policy, restaurantDiscountMinor: promotion(deciding) ? amountToMinor(v.restaurant_discount, deciding.currency, true) : null, reason: v.reason })
      setNotice(t('adm.finance.terms.decided', { order: decided.order.number, status: code(decided.status, locale) }, locale)); setDeciding(null); void reload()
    } catch (e) { setFailed(actionError(e, locale)) }
    setBusy(false)
  }
  const columns: Array<Column<AllocationDto>> = [
    { id: 'order', label: t('adm.finance.col.order', undefined, locale), primary: true, render: (x) => <Cell primary={x.order.number} secondary={fmtDateTime(x.placed_at, locale)} /> },
    { id: 'restaurant', label: t('adm.finance.col.restaurant', undefined, locale), render: (x) => <span dir="auto">{x.restaurant?.name ?? '—'}</span> },
    { id: 'total', label: t('adm.finance.col.customerTotal', undefined, locale), align: 'end', hideMobile: true, render: (x) => fm(x.customer_total, locale) },
    { id: 'gross', label: t('adm.finance.col.merchandise', undefined, locale), align: 'end', render: (x) => <b>{fm(x.merchandise_gross, locale)} <Badge tone="muted">{x.currency}</Badge></b> },
    { id: 'discount', label: t('adm.finance.col.discount', undefined, locale), align: 'end', hideMobile: true, render: (x) => <Cell primary={fm(x.discount, locale)} secondary={x.promotion.code ? `${x.promotion.code} · ${code(x.promotion.funding, locale)}` : ''} /> },
    { id: 'reason', label: t('adm.finance.col.waitingFor', undefined, locale), render: (x) => <span data-reason={x.review_reason ?? ''}>{why(x)}</span> },
  ]
  const fields: FormField[] = deciding ? [
    { name: 'rate', label: t('adm.finance.f.rate', undefined, locale), required: true, placeholder: '0.00', hint: t('adm.finance.f.rateHint', undefined, locale) },
    { name: 'base_policy', label: t('adm.finance.col.basePolicy', undefined, locale), type: 'select', options: BASE_POLICIES.map((p) => ({ value: p, label: code(p, locale, 'adm.finance.base') })) },
    // Asked for only when the order waits because of its promotion: who pays for the discount has to be said.
    ...(promotion(deciding) ? [{ name: 'restaurant_discount', label: t('adm.finance.f.restaurantDiscount', { currency: deciding.currency }, locale), required: true, placeholder: '0.00', hint: t('adm.finance.f.restaurantDiscountHint', { amount: fm(deciding.discount, locale) }, locale) } as FormField] : []),
    { name: 'reason', label: t('adm.finance.f.reason', undefined, locale), type: 'textarea', required: true, minLength: 10 },
  ] : []
  const validate = (v: Record<string, string>) => {
    const e: Record<string, string> = {}
    if (percentToBps(v.rate) === null) e.rate = t('adm.finance.form.percent', undefined, locale)
    if (deciding && promotion(deciding)) { const share = amountToMinor(v.restaurant_discount, deciding.currency, true); if (share === null || share > deciding.discount.amount) e.restaurant_discount = t('adm.finance.form.share', { amount: fm(deciding.discount, locale) }, locale) }
    return Object.keys(e).length ? e : null
  }
  return <>
    <Card title={t('adm.finance.terms.title', undefined, locale)} subtitle={t('adm.finance.terms.subtitle', undefined, locale)}>
      {notice && <p className="db-muted" role="status" data-testid="fin-allocation-notice">{notice}</p>}
      <DataTable columns={columns} rows={data?.items ?? []} keyOf={(x) => x.id} state={state} total={data?.total ?? 0} page={page} pageSize={PAGE_SIZE} onPage={(p) => set({ page: String(p) })} empty={{ icon: 'check', title: t('adm.finance.terms.empty', undefined, locale) }} onRetry={() => { void reload() }} locale={locale} testId="fin-allocations-table" caption={t('adm.finance.terms.title', undefined, locale)}
        rowProps={(x) => ({ 'data-testid': 'fin-allocation-row', 'data-id': x.id, 'data-order': x.order.number, 'data-reason': x.review_reason ?? '' })}
        actions={canDecide ? (x) => <button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => { setFailed(null); setNotice(null); setDeciding(x) }} data-testid="fin-allocation-decide">{t('adm.finance.terms.decide', undefined, locale)}</button> : undefined} />
    </Card>
    {deciding && <FormDialog key={deciding.id} title={t('adm.finance.terms.decide', undefined, locale)} text={t('adm.finance.terms.decide.text', undefined, locale)} busy={busy} error={failed} confirmLabel={t('adm.finance.terms.decide', undefined, locale)} locale={locale} testId="fin-allocation-dialog" onCancel={() => setDeciding(null)}
      impact={[t('adm.finance.terms.confirm.order', { order: deciding.order.number, restaurant: deciding.restaurant?.name ?? '—' }, locale), t('adm.finance.terms.confirm.why', { reason: why(deciding) }, locale), t('adm.finance.terms.confirm.amounts', { gross: fm(deciding.merchandise_gross, locale), discount: fm(deciding.discount, locale), currency: deciding.currency }, locale)]}
      fields={fields} validate={validate} onConfirm={decide} />}
  </>
}

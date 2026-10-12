import { useEffect, useState } from 'react'
import { Card, Drawer } from '../../../dashboard/components/ui'
import { t } from '../../../i18n/strings'
import { useAdmin } from '../../AdminContext'
import { BASE_POLICIES, type PlanDetailDto, type PlanDto } from '../../api/ApiAdminFinanceRepository'
import { Badge, DataTable, Details, DevNote, type Column } from '../../components/DataTable'
import { Cell, fmtDateTime, useLoad } from '../shared'
import { PAGE_SIZE, actionError, bpsToPercent, code, isUuid, percentToBps, type FinanceTabProps } from './helpers'
import { Actions, Chip, FormDialog } from './shared'

/**
 * Commission plans (Module 36) — negotiated terms, behind their own permission. A plan is NEVER edited: there is no
 * rate field to change. A new plan starts in the future and ends the one before it; a running plan can be ended at a
 * future moment and one that has not started can be withdrawn — each with a reason. A plan without an approval
 * reference is a TEST plan: a development fixture that production never applies.
 */
type Ask = 'create' | 'end' | 'withdraw'
const scopeOf = (p: PlanDto) => p.location?.name ?? p.organization?.name ?? p.market ?? '—'
const iso = (local: string) => { const d = new Date(local); return Number.isNaN(d.getTime()) ? null : d.toISOString() }

export function CommissionTab({ repo, s, set }: FinanceTabProps) {
  const a = useAdmin(); const locale = a.locale
  const page = Number(s.page) || 1
  const { data, state, reload } = useLoad(() => repo.plans({ page, pageSize: PAGE_SIZE }), [repo, page])
  const [open, setOpen] = useState<PlanDetailDto | null>(null)
  const [ask, setAsk] = useState<Ask | null>(null); const [busy, setBusy] = useState(false); const [failed, setFailed] = useState<string | null>(null)
  const show = async (id: string) => { setFailed(null); try { setOpen(await repo.plan(id)) } catch (e) { setFailed(actionError(e, locale)) } }
  useEffect(() => { if (s.open && s.open !== open?.id) void show(s.open) }, [s.open]) // eslint-disable-line react-hooks/exhaustive-deps
  const close = () => { setOpen(null); setFailed(null); if (s.open) set({ open: '', page: s.page }) }
  /** A refusal stays in the dialog with what was typed; a success shows the plan as the backend holds it. */
  const run = async (work: () => Promise<PlanDetailDto>) => {
    setBusy(true); setFailed(null)
    try { setOpen(await work()); setAsk(null); void reload() } catch (e) { setFailed(actionError(e, locale)) }
    setBusy(false)
  }
  const testBadge = (p: PlanDto) => (p.approval === 'TEST' ? <span data-testid="fin-plan-test" title={t('adm.finance.plan.testHint', undefined, locale)}><Badge tone="amber">{t('adm.finance.plan.test', undefined, locale)}</Badge> <small className="db-muted">{t('adm.finance.plan.testHint', undefined, locale)}</small></span> : <Badge tone="green">{t('adm.finance.plan.approved', undefined, locale)}</Badge>)
  const columns: Array<Column<PlanDto>> = [
    { id: 'name', label: t('adm.finance.col.plan', undefined, locale), primary: true, render: (p) => <Cell primary={p.name} secondary={`${code(p.scope, locale)} · ${scopeOf(p)}`} /> },
    { id: 'rate', label: t('adm.finance.col.rate', undefined, locale), align: 'end', render: (p) => <b>{bpsToPercent(p.rate_bps)}</b> },
    { id: 'base', label: t('adm.finance.col.basePolicy', undefined, locale), hideMobile: true, render: (p) => code(p.base_policy, locale, 'adm.finance.base') },
    { id: 'from', label: t('adm.finance.col.effective', undefined, locale), hideMobile: true, render: (p) => <Cell primary={fmtDateTime(p.effective_from, locale)} secondary={p.effective_until ? t('adm.finance.until', { date: fmtDateTime(p.effective_until, locale) }, locale) : t('adm.finance.openEnded', undefined, locale)} /> },
    { id: 'state', label: t('adm.finance.col.state', undefined, locale), render: (p) => <Chip value={p.state} locale={locale} /> },
    { id: 'approval', label: t('adm.finance.col.approval', undefined, locale), render: testBadge },
  ]
  const validateCreate = (v: Record<string, string>) => {
    const e: Record<string, string> = {}
    if (percentToBps(v.rate) === null) e.rate = t('adm.finance.form.percent', undefined, locale)
    if (v.restaurant && !isUuid(v.restaurant)) e.restaurant = t('adm.finance.form.uuid', undefined, locale)
    const from = iso(v.effective_from)
    if (from === null || Date.parse(from) <= Date.now()) e.effective_from = t('adm.finance.form.future', undefined, locale)
    if (v.effective_until && (iso(v.effective_until) === null || (from !== null && Date.parse(iso(v.effective_until) ?? '') <= Date.parse(from)))) e.effective_until = t('adm.finance.form.afterStart', undefined, locale)
    if (v.approval === 'APPROVED' && v.approval_reference.length < 3) e.approval_reference = t('adm.finance.form.approvalReference', undefined, locale)
    return Object.keys(e).length ? e : null
  }
  return <>
    <Card title={t('adm.finance.commission.title', undefined, locale)} subtitle={t('adm.finance.commission.subtitle', undefined, locale)} actions={a.can('finance.commission.manage') ? <button type="button" className="db-btn db-btn--primary db-btn--sm" onClick={() => { setFailed(null); setAsk('create') }} data-testid="fin-plan-new">{t('adm.finance.plan.new', undefined, locale)}</button> : undefined}>
      <DataTable columns={columns} rows={data?.items ?? []} keyOf={(p) => p.id} state={state} total={data?.total ?? 0} page={page} pageSize={PAGE_SIZE} onPage={(p) => set({ page: String(p) })} empty={{ icon: 'settlements', title: t('adm.finance.commission.empty', undefined, locale) }} onRetry={() => { void reload() }} locale={locale} testId="fin-plans-table" rowProps={(p) => ({ 'data-testid': 'fin-plan-row', 'data-id': p.id, 'data-state': p.state, 'data-approval': p.approval })} caption={t('adm.finance.commission.title', undefined, locale)} onRowClick={(p) => { void show(p.id) }} actions={(p) => <button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => { void show(p.id) }}>{t('adm.table.view', undefined, locale)}</button>} />
    </Card>
    <DevNote>{t('adm.finance.commission.note', undefined, locale)}</DevNote>
    {failed && !open && !ask && <p className="db-error" role="alert" data-testid="fin-error">{failed}</p>}

    <Drawer open={!!open} onClose={close} title={open ? open.name : ''} wide>
      {open && <div className="db-grid" style={{ gap: 16 }} data-testid="fin-plan" data-state={open.state} data-version={open.version}>
        <Details rows={[
          [t('adm.finance.col.state', undefined, locale), <Chip key="s" value={open.state} locale={locale} />],
          [t('adm.finance.col.approval', undefined, locale), <span key="a">{testBadge(open)}{open.approval_reference ? ` · ${open.approval_reference}` : ''}</span>],
          [t('adm.finance.col.scope', undefined, locale), `${code(open.scope, locale)} · ${scopeOf(open)}${open.market ? ` · ${open.market}` : ''}`],
          [t('adm.finance.col.rate', undefined, locale), <b key="r" data-testid="fin-plan-rate">{bpsToPercent(open.rate_bps)}</b>],
          [t('adm.finance.col.basePolicy', undefined, locale), code(open.base_policy, locale, 'adm.finance.base')],
          [t('adm.finance.col.effective', undefined, locale), `${fmtDateTime(open.effective_from, locale)} – ${open.effective_until ? fmtDateTime(open.effective_until, locale) : t('adm.finance.openEnded', undefined, locale)}`],
          [t('adm.finance.col.ordersAllocated', undefined, locale), open.orders_allocated.toLocaleString(locale)],
          [t('adm.finance.f.reason', undefined, locale), `${open.reason}${open.created_by ? ` · ${open.created_by.name}` : ''} · ${code(open.source, locale)}`],
          ...(open.withdrawn_at ? [[t('adm.finance.col.withdrawn', undefined, locale), `${fmtDateTime(open.withdrawn_at, locale)}${open.withdrawn_reason ? ` · ${open.withdrawn_reason}` : ''}`] as [string, React.ReactNode]] : []),
        ]} />
        <Card title={t('adm.pay.actions', undefined, locale)}>
          <Actions testId="fin-plan-actions" prefix="fin-plan" busy={busy} locale={locale} items={[
            open.can.end && { id: 'end', label: t('adm.finance.plan.end', undefined, locale), onClick: () => { setFailed(null); setAsk('end') } },
            open.can.withdraw && { id: 'withdraw', label: t('adm.finance.plan.withdraw', undefined, locale), onClick: () => { setFailed(null); setAsk('withdraw') } },
          ]}>
            <p className="db-muted" data-testid="fin-plan-never-edited">{t('adm.finance.plan.neverEdited', undefined, locale)}</p>
          </Actions>
        </Card>
      </div>}
    </Drawer>

    {ask === 'create' && <FormDialog key="create" title={t('adm.finance.plan.new', undefined, locale)} text={t('adm.finance.plan.new.text', undefined, locale)} validate={validateCreate} busy={busy} error={failed} confirmLabel={t('adm.finance.plan.create', undefined, locale)} locale={locale} testId="fin-plan-dialog" onCancel={() => setAsk(null)}
      fields={[
        { name: 'name', label: t('adm.finance.f.name', undefined, locale), required: true, minLength: 3 },
        { name: 'market', label: t('adm.finance.f.market', undefined, locale), required: true, initial: a.marketModel?.slug ?? '', hint: t('adm.finance.f.marketHint', undefined, locale) },
        { name: 'restaurant', label: t('adm.finance.f.restaurantOptional', undefined, locale), hint: t('adm.finance.f.planRestaurantHint', undefined, locale) },
        { name: 'rate', label: t('adm.finance.f.rate', undefined, locale), required: true, placeholder: '0.00', hint: t('adm.finance.f.rateHint', undefined, locale) },
        { name: 'base_policy', label: t('adm.finance.col.basePolicy', undefined, locale), type: 'select', options: BASE_POLICIES.map((p) => ({ value: p, label: code(p, locale, 'adm.finance.base') })) },
        { name: 'effective_from', label: t('adm.finance.f.effectiveFrom', undefined, locale), type: 'datetime-local', required: true, hint: t('adm.finance.form.future', undefined, locale) },
        { name: 'effective_until', label: t('adm.finance.f.effectiveUntil', undefined, locale), type: 'datetime-local' },
        { name: 'approval', label: t('adm.finance.col.approval', undefined, locale), type: 'select', options: [{ value: 'TEST', label: t('adm.finance.plan.approval.TEST', undefined, locale) }, { value: 'APPROVED', label: t('adm.finance.plan.approval.APPROVED', undefined, locale) }] },
        { name: 'approval_reference', label: t('adm.finance.f.approvalReference', undefined, locale), hint: t('adm.finance.f.approvalReferenceHint', undefined, locale) },
        { name: 'reason', label: t('adm.finance.f.reason', undefined, locale), type: 'textarea', required: true, minLength: 10 },
      ]}
      onConfirm={(v) => run(() => repo.createPlan({ name: v.name, market: v.market, restaurant: v.restaurant || null, rateBps: percentToBps(v.rate) ?? 0, basePolicy: v.base_policy, effectiveFrom: iso(v.effective_from) ?? v.effective_from, effectiveUntil: v.effective_until ? iso(v.effective_until) : null, approval: v.approval === 'APPROVED' ? 'APPROVED' : 'TEST', approvalReference: v.approval_reference || null, reason: v.reason }))} />}
    {open && ask === 'end' && <FormDialog key="end" title={t('adm.finance.plan.end', undefined, locale)} text={t('adm.finance.plan.end.text', undefined, locale)} busy={busy} error={failed} confirmLabel={t('adm.finance.plan.end', undefined, locale)} locale={locale} testId="fin-plan-dialog" onCancel={() => setAsk(null)}
      validate={(v) => { const until = iso(v.effective_until); return until === null || Date.parse(until) <= Date.now() ? { effective_until: t('adm.finance.form.future', undefined, locale) } : null }}
      fields={[{ name: 'effective_until', label: t('adm.finance.f.effectiveUntil', undefined, locale), type: 'datetime-local', required: true }, { name: 'reason', label: t('adm.finance.f.reason', undefined, locale), type: 'textarea', required: true, minLength: 10 }]}
      onConfirm={(v) => run(() => repo.endPlan(open.id, iso(v.effective_until) ?? v.effective_until, v.reason, open.version))} />}
    {open && ask === 'withdraw' && <FormDialog key="withdraw" title={t('adm.finance.plan.withdraw', undefined, locale)} text={t('adm.finance.plan.withdraw.text', undefined, locale)} danger busy={busy} error={failed} confirmLabel={t('adm.finance.plan.withdraw', undefined, locale)} locale={locale} testId="fin-plan-dialog" onCancel={() => setAsk(null)}
      fields={[{ name: 'reason', label: t('adm.finance.f.reason', undefined, locale), type: 'textarea', required: true, minLength: 10 }]}
      onConfirm={(v) => run(() => repo.withdrawPlan(open.id, v.reason))} />}
  </>
}

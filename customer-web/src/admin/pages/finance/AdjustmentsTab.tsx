import { useEffect, useState } from 'react'
import { newRequestId } from '../../../api/client'
import { Card, Drawer, Tabs } from '../../../dashboard/components/ui'
import { t } from '../../../i18n/strings'
import { useAdmin } from '../../AdminContext'
import { HOLD_REASONS, type AdjustmentDetailDto, type AdjustmentDto, type HoldDto } from '../../api/ApiAdminFinanceRepository'
import { Badge, DataTable, Details, DevNote, type Column } from '../../components/DataTable'
import { Cell, fmtDateTime, useLoad } from '../shared'
import { PAGE_SIZE, actionError, amountToMinor, code, fm, isUuid, type FinanceTabProps } from './helpers'
import { AllocationsView } from './AllocationsView'
import { Actions, Chip, FormDialog, type FormField } from './shared'

/**
 * Adjustments and holds (Module 36). THERE IS NO BALANCE TO EDIT: an adjustment is a record with a reason and a
 * supporting reference that the backend posts as one balanced ledger transaction; a liability decision states the
 * restaurant's share of a completed refund or a lost dispute; a hold moves no money — it keeps earnings out of the
 * next settlement until it is released. Nothing here names a ledger account, a debit or a credit.
 */
type Ask = 'manual' | 'decide' | 'approve' | 'cancel' | 'hold' | 'release'

export function AdjustmentsTab({ repo, s, set }: FinanceTabProps) {
  const a = useAdmin(); const locale = a.locale
  const view = s.view === 'holds' ? 'holds' : s.view === 'terms' ? 'terms' : 'adjustments'; const page = Number(s.page) || 1
  const adjustments = useLoad(() => (view === 'adjustments' ? repo.adjustments({ page, pageSize: PAGE_SIZE }) : Promise.resolve(null)), [repo, view, page])
  const holds = useLoad(() => (view === 'holds' ? repo.holds({ page, pageSize: PAGE_SIZE }) : Promise.resolve(null)), [repo, view, page])
  const [open, setOpen] = useState<AdjustmentDetailDto | null>(null); const [releasing, setReleasing] = useState<HoldDto | null>(null)
  const [ask, setAsk] = useState<Ask | null>(null); const [busy, setBusy] = useState(false); const [failed, setFailed] = useState<string | null>(null)
  const show = async (id: string) => { setFailed(null); try { setOpen(await repo.adjustment(id)) } catch (e) { setFailed(actionError(e, locale)) } }
  useEffect(() => { if (s.open && view === 'adjustments' && s.open !== open?.id) void show(s.open) }, [s.open]) // eslint-disable-line react-hooks/exhaustive-deps
  const close = () => { setOpen(null); setFailed(null); if (s.open) set({ open: '', page: s.page }) }
  /** A refusal stays in the dialog with what was typed; a success shows the record as the backend holds it. */
  const run = async (work: () => Promise<AdjustmentDetailDto | null>) => {
    setBusy(true); setFailed(null)
    try { const next = await work(); if (next) setOpen(next); setAsk(null); setReleasing(null); void adjustments.reload(); void holds.reload() } catch (e) { setFailed(actionError(e, locale)) }
    setBusy(false)
  }
  const ask_ = (x: Ask) => { setFailed(null); setAsk(x) }

  const columns: Array<Column<AdjustmentDto>> = [
    { id: 'kind', label: t('adm.finance.col.kind', undefined, locale), primary: true, render: (x) => <Cell primary={code(x.kind, locale)} secondary={`${code(x.reason_code, locale)}${x.order_number ? ` · ${x.order_number}` : ''}`} /> },
    { id: 'restaurant', label: t('adm.finance.col.restaurant', undefined, locale), render: (x) => <span dir="auto">{x.restaurant?.name ?? '—'}</span> },
    { id: 'amount', label: t('adm.finance.col.amount', undefined, locale), align: 'end', render: (x) => <b>{fm(x.amount, locale)} <Badge tone="muted">{x.currency}</Badge></b> },
    { id: 'direction', label: t('adm.finance.col.direction', undefined, locale), hideMobile: true, render: (x) => code(x.direction, locale) },
    { id: 'liability', label: t('adm.finance.col.liability', undefined, locale), hideMobile: true, render: (x) => <Cell primary={code(x.liability, locale)} secondary={x.restaurant_share ? t('adm.finance.shares', { restaurant: fm(x.restaurant_share, locale), platform: fm(x.platform_share, locale) }, locale) : ''} /> },
    { id: 'status', label: t('adm.finance.col.status', undefined, locale), render: (x) => <Chip value={x.status} locale={locale} /> },
    { id: 'created', label: t('adm.finance.col.created', undefined, locale), hideMobile: true, render: (x) => fmtDateTime(x.created_at, locale) },
  ]
  const holdColumns: Array<Column<HoldDto>> = [
    { id: 'restaurant', label: t('adm.finance.col.restaurant', undefined, locale), primary: true, render: (h) => <Cell primary={h.restaurant?.name ?? '—'} secondary={h.line ? `${code(h.scope, locale)} · ${h.line.order_number ?? '—'} · ${fm(h.line.amount, locale)}` : code(h.scope, locale)} /> },
    { id: 'reason', label: t('adm.finance.f.reason', undefined, locale), render: (h) => <Cell primary={code(h.reason, locale)} secondary={h.note ?? ''} /> },
    { id: 'source', label: t('adm.finance.col.source', undefined, locale), hideMobile: true, render: (h) => code(h.source, locale) },
    { id: 'placed', label: t('adm.finance.col.placed', undefined, locale), hideMobile: true, render: (h) => <Cell primary={fmtDateTime(h.placed_at, locale)} secondary={h.placed_by?.name ?? ''} /> },
    { id: 'status', label: t('adm.finance.col.status', undefined, locale), render: (h) => <Cell primary={<Chip value={h.status} locale={locale} />} secondary={h.released_at ? `${fmtDateTime(h.released_at, locale)}${h.release_reason ? ` · ${h.release_reason}` : ''}` : ''} /> },
  ]
  const reason = (min: number): FormField => ({ name: 'reason', label: t('adm.finance.f.reason', undefined, locale), type: 'textarea', required: true, minLength: min })
  const restaurant: FormField = { name: 'restaurant', label: t('adm.finance.f.restaurant', undefined, locale), required: true, hint: t('adm.finance.f.restaurantHint', undefined, locale) }
  const policy = adjustments.data?.policy
  const canAdjust = a.can('finance.adjustments.create'); const canHold = a.can('finance.holds.manage')

  return <>
    <Tabs tabs={[{ id: 'adjustments', label: t('adm.finance.adj.title', undefined, locale) }, { id: 'holds', label: t('adm.finance.holds.title', undefined, locale) }, { id: 'terms', label: t('adm.finance.terms.title', undefined, locale) }]} value={view} onChange={(v) => set({ view: v === 'adjustments' ? '' : v, page: '1' })} label={t('adm.finance.adj.views', undefined, locale)} testId="fin-view" />
    {view === 'adjustments' && <>
      <Card title={t('adm.finance.adj.title', undefined, locale)} subtitle={t('adm.finance.adj.subtitle', undefined, locale)} actions={canAdjust ? <button type="button" className="db-btn db-btn--primary db-btn--sm" onClick={() => ask_('manual')} data-testid="fin-adjustment-new">{t('adm.finance.adj.new', undefined, locale)}</button> : undefined}>
        {policy && <p className="db-muted" data-testid="fin-adjustment-policy">{t('adm.finance.adj.policy', { liability: code(policy.refund_liability_default, locale), approval: policy.approval }, locale)}{policy.four_eyes ? ` ${t('adm.finance.adj.fourEyes', undefined, locale)}` : ''}</p>}
        <DataTable columns={columns} rows={adjustments.data?.items ?? []} keyOf={(x) => x.id} state={adjustments.state} total={adjustments.data?.total ?? 0} page={page} pageSize={PAGE_SIZE} onPage={(p) => set({ page: String(p) })} empty={{ icon: 'settlements', title: t('adm.finance.adj.empty', undefined, locale) }} onRetry={() => { void adjustments.reload() }} locale={locale} testId="fin-adjustments-table" rowProps={(x) => ({ 'data-testid': 'fin-adjustment-row', 'data-id': x.id, 'data-kind': x.kind, 'data-status': x.status })} caption={t('adm.finance.adj.title', undefined, locale)} onRowClick={(x) => { void show(x.id) }} actions={(x) => <button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => { void show(x.id) }}>{t('adm.table.view', undefined, locale)}</button>} />
      </Card>
    </>}
    {view === 'holds' && <>
      <Card title={t('adm.finance.holds.title', undefined, locale)} subtitle={t('adm.finance.holds.subtitle', undefined, locale)} actions={canHold ? <button type="button" className="db-btn db-btn--primary db-btn--sm" onClick={() => ask_('hold')} data-testid="fin-hold-new">{t('adm.finance.holds.place', undefined, locale)}</button> : undefined}>
        <DataTable columns={holdColumns} rows={holds.data?.items ?? []} keyOf={(h) => h.id} state={holds.state} total={holds.data?.total ?? 0} page={page} pageSize={PAGE_SIZE} onPage={(p) => set({ page: String(p) })} empty={{ icon: 'check', title: t('adm.finance.holds.empty', undefined, locale) }} onRetry={() => { void holds.reload() }} locale={locale} testId="fin-holds-table" rowProps={(h) => ({ 'data-testid': 'fin-hold-row', 'data-id': h.id, 'data-status': h.status })} caption={t('adm.finance.holds.title', undefined, locale)}
          actions={canHold ? (h) => (h.status === 'ACTIVE' ? <button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => { setReleasing(h); ask_('release') }} data-testid="fin-hold-release">{t('adm.finance.holds.release', undefined, locale)}</button> : null) : undefined} />
      </Card>
    </>}
    {view === 'terms' && <AllocationsView repo={repo} s={s} set={set} />}
    <DevNote>{t('adm.finance.adj.note', undefined, locale)}</DevNote>
    {failed && !open && !ask && <p className="db-error" role="alert" data-testid="fin-error">{failed}</p>}

    <Drawer open={!!open} onClose={close} title={open ? `${code(open.kind, locale)} · ${code(open.reason_code, locale)}` : ''} wide>
      {open && <div className="db-grid" style={{ gap: 16 }} data-testid="fin-adjustment" data-status={open.status} data-version={open.version}>
        <Details rows={[
          [t('adm.finance.col.status', undefined, locale), <Chip key="s" value={open.status} locale={locale} />],
          [t('adm.finance.col.restaurant', undefined, locale), <span key="r" dir="auto">{open.restaurant?.name ?? '—'}</span>],
          [t('adm.finance.col.order', undefined, locale), open.order_number ?? '—'],
          [t('adm.finance.col.amount', undefined, locale), <b key="a" data-testid="fin-adjustment-amount">{fm(open.amount, locale)} ({open.currency})</b>],
          [t('adm.finance.col.direction', undefined, locale), code(open.direction, locale)],
          [t('adm.finance.col.liability', undefined, locale), `${code(open.liability, locale)}${open.policy_source ? ` · ${code(open.policy_source, locale)}` : ''}`],
          [t('adm.finance.col.restaurantShare', undefined, locale), fm(open.restaurant_share, locale)],
          [t('adm.finance.col.platformShare', undefined, locale), fm(open.platform_share, locale)],
          [t('adm.finance.f.reason', undefined, locale), `${code(open.reason_code, locale)}${open.reason ? ` · ${open.reason}` : ''}`],
          [t('adm.finance.f.supportingReference', undefined, locale), open.supporting_reference ?? '—'],
          [t('adm.finance.col.source', undefined, locale), open.source ? `${code(open.source.type, locale)} · ${open.source.id}` : '—'],
          [t('adm.finance.col.created', undefined, locale), `${fmtDateTime(open.created_at, locale)}${open.created_by ? ` · ${open.created_by.name}` : ''}`],
          [t('adm.finance.col.decided', undefined, locale), open.decided_at ? `${fmtDateTime(open.decided_at, locale)}${open.decided_by ? ` · ${open.decided_by.name}` : ''}` : '—'],
          [t('adm.finance.col.posted', undefined, locale), open.posted_at ? `${fmtDateTime(open.posted_at, locale)}${open.ledger_transaction ? ` · ${open.ledger_transaction}` : ''}` : t('adm.finance.notPosted', undefined, locale)],
        ]} />
        <Card title={t('adm.pay.actions', undefined, locale)}>
          <Actions testId="fin-adjustment-actions" prefix="fin-adjustment" busy={busy} locale={locale} items={[
            open.can.decide && { id: 'decide', label: t('adm.finance.adj.decide', undefined, locale), primary: true, onClick: () => ask_('decide') },
            open.can.approve && { id: 'approve', label: t('adm.finance.approve', undefined, locale), primary: true, onClick: () => ask_('approve') },
            open.can.cancel && { id: 'cancel', label: t('adm.finance.adj.cancel', undefined, locale), onClick: () => ask_('cancel') },
          ]} />
        </Card>
      </div>}
    </Drawer>

    {ask === 'manual' && <FormDialog key="manual" title={t('adm.finance.adj.new', undefined, locale)} text={t('adm.finance.adj.new.text', undefined, locale)} busy={busy} error={failed} confirmLabel={t('adm.finance.adj.create', undefined, locale)} locale={locale} testId="fin-adjustment-dialog" onCancel={() => setAsk(null)}
      validate={(v) => { const e: Record<string, string> = {}; if (!isUuid(v.restaurant)) e.restaurant = t('adm.finance.form.uuid', undefined, locale); if (!/^[A-Za-z]{3}$/.test(v.currency)) e.currency = t('adm.finance.form.currency', undefined, locale); else if (amountToMinor(v.amount, v.currency.toUpperCase()) === null) e.amount = t('adm.finance.form.amount', undefined, locale); return Object.keys(e).length ? e : null }}
      fields={[
        restaurant,
        { name: 'direction', label: t('adm.finance.col.direction', undefined, locale), type: 'select', options: [{ value: 'CREDIT_RESTAURANT', label: code('CREDIT_RESTAURANT', locale) }, { value: 'DEBIT_RESTAURANT', label: code('DEBIT_RESTAURANT', locale) }] },
        { name: 'amount', label: t('adm.finance.col.amount', undefined, locale), required: true, placeholder: '0.00' },
        { name: 'currency', label: t('adm.finance.col.currency', undefined, locale), required: true, initial: a.marketModel?.defaultCurrency ?? '', hint: t('adm.finance.f.currencyHint', undefined, locale) },
        { name: 'reason_code', label: t('adm.finance.f.reasonCode', undefined, locale), type: 'select', options: (policy?.reasons ?? []).map((r) => ({ value: r, label: code(r, locale) })) },
        reason(10),
        { name: 'supporting_reference', label: t('adm.finance.f.supportingReference', undefined, locale), required: true, minLength: 4, hint: t('adm.finance.f.supportingHint', undefined, locale) },
        { name: 'order', label: t('adm.finance.f.orderOptional', undefined, locale) },
      ]}
      onConfirm={(v) => { const currency = v.currency.toUpperCase(); return run(() => repo.createAdjustment({ restaurant: v.restaurant, direction: v.direction === 'DEBIT_RESTAURANT' ? 'DEBIT_RESTAURANT' : 'CREDIT_RESTAURANT', amountMinor: amountToMinor(v.amount, currency) ?? 0, currency, reasonCode: v.reason_code, reason: v.reason, supportingReference: v.supporting_reference, order: v.order || null }, newRequestId())) }} />}
    {open && ask === 'decide' && <FormDialog key="decide" title={t('adm.finance.adj.decide', undefined, locale)} text={t('adm.finance.adj.decide.text', undefined, locale)} busy={busy} error={failed} confirmLabel={t('adm.finance.adj.decide', undefined, locale)} locale={locale} testId="fin-adjustment-dialog" onCancel={() => setAsk(null)}
      impact={[t('adm.finance.confirm.adjustment', { amount: fm(open.amount, locale), currency: open.currency }, locale)]}
      validate={(v) => { const share = amountToMinor(v.restaurant_share, open.currency, true); return share === null || share > open.amount.amount ? { restaurant_share: t('adm.finance.form.share', { amount: fm(open.amount, locale) }, locale) } : null }}
      fields={[{ name: 'restaurant_share', label: t('adm.finance.col.restaurantShare', undefined, locale), required: true, placeholder: '0.00', hint: t('adm.finance.f.shareHint', undefined, locale) }, reason(10)]}
      onConfirm={(v) => run(() => repo.decideAdjustment(open.id, amountToMinor(v.restaurant_share, open.currency, true) ?? 0, v.reason, open.version))} />}
    {open && (ask === 'approve' || ask === 'cancel') && <FormDialog key={ask} title={t(ask === 'approve' ? 'adm.finance.approve' : 'adm.finance.adj.cancel', undefined, locale)} text={t(`adm.finance.adj.${ask}.text`, undefined, locale)} danger={ask === 'cancel'} busy={busy} error={failed} confirmLabel={t(ask === 'approve' ? 'adm.finance.approve' : 'adm.finance.adj.cancel', undefined, locale)} locale={locale} testId="fin-adjustment-dialog" onCancel={() => setAsk(null)}
      impact={[t('adm.finance.confirm.adjustment', { amount: fm(open.amount, locale), currency: open.currency }, locale)]} fields={[reason(3)]} onConfirm={(v) => run(() => repo.adjustmentAction(open.id, ask, v.reason))} />}
    {ask === 'hold' && <FormDialog key="hold" title={t('adm.finance.holds.place', undefined, locale)} text={t('adm.finance.holds.place.text', undefined, locale)} busy={busy} error={failed} confirmLabel={t('adm.finance.holds.place', undefined, locale)} locale={locale} testId="fin-hold-dialog" onCancel={() => setAsk(null)}
      validate={(v) => { const e: Record<string, string> = {}; if (!isUuid(v.restaurant)) e.restaurant = t('adm.finance.form.uuid', undefined, locale); if (v.line && !isUuid(v.line)) e.line = t('adm.finance.form.uuid', undefined, locale); return Object.keys(e).length ? e : null }}
      fields={[restaurant, { name: 'line', label: t('adm.finance.f.lineOptional', undefined, locale), hint: t('adm.finance.f.lineHint', undefined, locale) }, { name: 'reason', label: t('adm.finance.f.holdReason', undefined, locale), type: 'select', options: HOLD_REASONS.map((r) => ({ value: r, label: code(r, locale) })) }, { name: 'note', label: t('adm.finance.f.note', undefined, locale), type: 'textarea', required: true, minLength: 10 }]}
      onConfirm={(v) => run(async () => { await repo.placeHold({ restaurant: v.restaurant, line: v.line || null, reason: v.reason, note: v.note }); return null })} />}
    {releasing && ask === 'release' && <FormDialog key="release" title={t('adm.finance.holds.release', undefined, locale)} text={t('adm.finance.holds.release.text', { restaurant: releasing.restaurant?.name ?? '' }, locale)} busy={busy} error={failed} confirmLabel={t('adm.finance.holds.release', undefined, locale)} locale={locale} testId="fin-hold-dialog" onCancel={() => { setAsk(null); setReleasing(null) }}
      fields={[reason(10)]} onConfirm={(v) => run(async () => { await repo.releaseHold(releasing.id, v.reason); return null })} />}
  </>
}

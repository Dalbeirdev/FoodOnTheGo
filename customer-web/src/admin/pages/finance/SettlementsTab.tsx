import { useEffect, useState } from 'react'
import { Card, Drawer, Tabs } from '../../../dashboard/components/ui'
import { t } from '../../../i18n/strings'
import { useAdmin } from '../../AdminContext'
import type { GenerateResultDto, SettlementDetailDto, SettlementDto } from '../../api/ApiAdminFinanceRepository'
import { Badge, DataTable, Details, DevNote, type Column } from '../../components/DataTable'
import { Cell, fmtDate, fmtDateTime, useLoad } from '../shared'
import { PAGE_SIZE, actionError, code, fm, isUuid, type FinanceTabProps } from './helpers'
import { Actions, Chip, FormDialog, type FormField } from './shared'

/**
 * Restaurant settlements (Module 36): statements of what is owed. Generating or approving one sends no money, and
 * nothing here sets a total, a line or a paid state — a settlement becomes paid only when its payout is confirmed.
 * A button exists only when the backend's `can` says the settlement's state and the caller's permissions allow it.
 */
const QUEUES = ['calculated', 'review_required', 'approved', 'payment_pending', 'paid', 'failed', 'closed', 'cancelled'] as const
type Ask = 'approve' | 'cancel' | 'start_payout' | 'record_external_payout' | 'generate' | 'preview'

export function SettlementsTab({ repo, s, set }: FinanceTabProps) {
  const a = useAdmin(); const locale = a.locale
  const page = Number(s.page) || 1
  const { data, state, reload } = useLoad(() => repo.settlements({ queue: s.queue, page, pageSize: PAGE_SIZE }), [repo, s.queue, page])
  const [open, setOpen] = useState<SettlementDetailDto | null>(null)
  const [ask, setAsk] = useState<Ask | null>(null); const [busy, setBusy] = useState(false); const [failed, setFailed] = useState<string | null>(null); const [notice, setNotice] = useState<string | null>(null)
  const [generated, setGenerated] = useState<GenerateResultDto | null>(null)
  const show = async (id: string) => { setFailed(null); setNotice(null); try { setOpen(await repo.settlement(id)) } catch (e) { setFailed(actionError(e, locale)) } }
  useEffect(() => { if (s.open && s.open !== open?.id) void show(s.open) }, [s.open]) // eslint-disable-line react-hooks/exhaustive-deps
  const close = () => { setOpen(null); setFailed(null); setNotice(null); if (s.open) set({ open: '', page: s.page }) }

  /** Runs an action, then shows the settlement as the backend holds it now — also after a refusal. */
  const run = async (work: () => Promise<unknown>) => {
    setBusy(true); setFailed(null)
    try { await work(); setAsk(null); void reload() } catch (e) { setFailed(actionError(e, locale)); setAsk(null) }
    if (open) { try { setOpen(await repo.settlement(open.id)) } catch { /* the drawer keeps what it had */ } }
    setBusy(false)
  }
  const exportCsv = async () => {
    if (!open) return
    setBusy(true); setFailed(null); setNotice(null)
    try { setNotice(t('adm.finance.exported', { name: await repo.exportSettlement(open.id, open.reference) }, locale)) } catch (e) { setFailed(actionError(e, locale)) }
    setBusy(false)
  }
  const generate = async (v: Record<string, string>, dryRun: boolean) => {
    setBusy(true); setFailed(null)
    try { setGenerated(await repo.generate({ restaurant: v.restaurant || null, until: v.until || null, dryRun })); setAsk(null); if (!dryRun) void reload() } catch (e) { setFailed(actionError(e, locale)) }
    setBusy(false)
  }

  const columns: Array<Column<SettlementDto>> = [
    { id: 'ref', label: t('adm.finance.col.reference', undefined, locale), primary: true, render: (x) => <Cell primary={x.reference} secondary={`${fmtDate(x.period.start, locale)} – ${fmtDate(x.period.end, locale)}`} /> },
    { id: 'restaurant', label: t('adm.finance.col.restaurant', undefined, locale), render: (x) => <span dir="auto">{x.restaurant.name}</span> },
    { id: 'orders', label: t('adm.finance.col.orders', undefined, locale), align: 'end', hideMobile: true, render: (x) => x.summary.completed_orders.toLocaleString(locale) },
    { id: 'gross', label: t('adm.finance.col.grossSales', undefined, locale), align: 'end', hideMobile: true, render: (x) => fm(x.summary.gross_sales, locale) },
    { id: 'commission', label: t('adm.finance.col.commission', undefined, locale), align: 'end', hideMobile: true, render: (x) => fm(x.summary.commission, locale) },
    { id: 'net', label: t('adm.finance.col.netPayable', undefined, locale), align: 'end', render: (x) => <b>{fm(x.summary.net_payable, locale)} <Badge tone="muted">{x.currency}</Badge></b> },
    { id: 'status', label: t('adm.finance.col.status', undefined, locale), render: (x) => <Chip value={x.status} locale={locale} /> },
  ]
  const tabs = [{ id: 'all', label: t('adm.table.all', undefined, locale) }, ...QUEUES.map((q) => ({ id: q as string, label: code(q.toUpperCase(), locale), count: data?.queues?.[q] }))]
  const reason = (min: number): FormField => ({ name: 'reason', label: t('adm.finance.f.reason', undefined, locale), type: 'textarea', required: true, minLength: min })
  const generateFields: FormField[] = [
    { name: 'restaurant', label: t('adm.finance.f.restaurantOptional', undefined, locale), hint: t('adm.finance.f.restaurantHint', undefined, locale) },
    { name: 'until', label: t('adm.finance.f.until', undefined, locale), type: 'date', hint: t('adm.finance.f.untilHint', undefined, locale) },
  ]
  const checkGenerate = (v: Record<string, string>) => (v.restaurant && !isUuid(v.restaurant) ? { restaurant: t('adm.finance.form.uuid', undefined, locale) } : null)

  return <>
    <Tabs tabs={tabs} value={tabs.some((x) => x.id === s.queue) ? s.queue : 'all'} onChange={(queue) => set({ queue, page: '1' })} label={t('adm.finance.queues', undefined, locale)} testId="fin-queue" />
    <Card title={t('adm.finance.settlements.title', undefined, locale)} subtitle={t('adm.finance.settlements.subtitle', undefined, locale)} actions={a.can('finance.settlements.manage') ? (
      <div className="db-toolbar">
        <button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => { setFailed(null); setAsk('preview') }} data-testid="fin-settlements-preview">{t('adm.finance.generate.preview', undefined, locale)}</button>
        <button type="button" className="db-btn db-btn--primary db-btn--sm" onClick={() => { setFailed(null); setAsk('generate') }} data-testid="fin-settlements-generate">{t('adm.finance.generate', undefined, locale)}</button>
      </div>) : undefined}>
      <DataTable columns={columns} rows={data?.items ?? []} keyOf={(x) => x.id} state={state} total={data?.total ?? 0} page={page} pageSize={PAGE_SIZE} onPage={(p) => set({ page: String(p) })} empty={{ icon: 'settlements', title: t('adm.finance.settlements.empty', undefined, locale) }} onRetry={() => { void reload() }} locale={locale} testId="fin-settlements-table" rowProps={(x) => ({ 'data-testid': 'fin-settlement-row', 'data-id': x.id, 'data-ref': x.reference, 'data-status': x.status })} caption={t('adm.finance.settlements.title', undefined, locale)} onRowClick={(x) => { void show(x.id) }} actions={(x) => <button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => { void show(x.id) }}>{t('adm.table.view', undefined, locale)}</button>} />
    </Card>
    {generated && (
      <Card title={t(generated.dry_run ? 'adm.finance.generate.previewResult' : 'adm.finance.generate.result', undefined, locale)} subtitle={generated.note} actions={<button type="button" className="db-btn db-btn--ghost db-btn--sm" onClick={() => setGenerated(null)}>{t('dash.action.close', undefined, locale)}</button>}>
        <div data-testid="fin-generate-result" data-dry-run={generated.dry_run}>
          <p className="db-muted">{Object.entries(generated.summary).map(([k, n]) => `${code(k.toUpperCase(), locale)}: ${n}`).join(' · ')}</p>
          {generated.results.length > 0 && <div className="db-table-wrap"><table className="db-table">
            <thead><tr><th scope="col">{t('adm.finance.col.restaurant', undefined, locale)}</th><th scope="col">{t('adm.finance.col.currency', undefined, locale)}</th><th scope="col">{t('adm.finance.col.outcome', undefined, locale)}</th><th scope="col">{t('adm.finance.col.lines', undefined, locale)}</th><th scope="col">{t('adm.finance.col.netPayable', undefined, locale)}</th><th scope="col">{t('adm.finance.col.reference', undefined, locale)}</th></tr></thead>
            <tbody>{generated.results.map((r) => <tr key={`${r.location}:${r.currency}`} data-outcome={r.outcome}><td dir="auto">{r.restaurant}</td><td>{r.currency}</td><td>{code(r.outcome, locale)}</td><td>{r.line_count}</td><td>{fm(r.net_payable, locale)}</td><td>{r.reference ?? '—'}</td></tr>)}</tbody>
          </table></div>}
        </div>
      </Card>
    )}
    <DevNote>{t('adm.finance.settlements.note', undefined, locale)}</DevNote>
    {failed && !open && !ask && <p className="db-error" role="alert" data-testid="fin-error">{failed}</p>}

    <Drawer open={!!open} onClose={close} title={open ? t('adm.finance.settlement.title', { ref: open.reference }, locale) : ''} wide>
      {open && <div className="db-grid" style={{ gap: 16 }} data-testid="fin-settlement" data-status={open.status} data-version={open.version}>
        <p className="adm-devnote" role="note" data-testid="fin-statement-kind">{t('adm.finance.statement.notInvoice', undefined, locale)}</p>
        {open.review_reason && <p className="db-error" role="alert" data-testid="fin-settlement-review">{t('adm.finance.reviewReason', { reason: code(open.review_reason, locale) }, locale)}</p>}
        <Details rows={[
          [t('adm.finance.col.status', undefined, locale), <Chip key="s" value={open.status} locale={locale} />],
          [t('adm.finance.col.restaurant', undefined, locale), <span key="r" dir="auto">{open.restaurant.name}{open.organization ? ` · ${open.organization.name}` : ''}</span>],
          [t('adm.finance.col.period', undefined, locale), `${fmtDateTime(open.period.start, locale)} – ${fmtDateTime(open.period.end, locale)} · ${open.period.timezone} · ${code(open.period.cadence, locale)}`],
          [t('adm.finance.col.orders', undefined, locale), open.summary.completed_orders.toLocaleString(locale)],
          [t('adm.finance.col.grossSales', undefined, locale), fm(open.summary.gross_sales, locale)],
          [t('adm.finance.col.restaurantDiscounts', undefined, locale), fm(open.summary.restaurant_discounts, locale)],
          [t('adm.finance.col.commission', undefined, locale), fm(open.summary.commission, locale)],
          [t('adm.finance.col.refundAdjustments', undefined, locale), fm(open.summary.refund_adjustments, locale)],
          [t('adm.finance.col.otherAdjustments', undefined, locale), fm(open.summary.other_adjustments, locale)],
          [t('adm.finance.col.netPayable', undefined, locale), <b key="n" data-testid="fin-settlement-net">{fm(open.summary.net_payable, locale)} ({open.currency})</b>],
          [t('adm.finance.col.amountPaid', undefined, locale), fm(open.summary.amount_paid, locale)],
          [t('adm.finance.col.amountPending', undefined, locale), fm(open.summary.amount_pending, locale)],
          [t('adm.finance.col.generated', undefined, locale), `${fmtDateTime(open.generated_at, locale)} · ${open.generated_by.admin?.name ?? code(open.generated_by.type, locale)}`],
          [t('adm.finance.col.approved', undefined, locale), open.approved_at ? `${fmtDateTime(open.approved_at, locale)} · ${open.approved_by?.name ?? ''}${open.approval_note ? ` · ${open.approval_note}` : ''}` : t('adm.finance.notApproved', undefined, locale)],
          ...(open.cancelled_at ? [[t('adm.finance.col.withdrawn', undefined, locale), `${fmtDateTime(open.cancelled_at, locale)} · ${open.cancelled_by?.name ?? ''}${open.cancelled_reason ? ` · ${open.cancelled_reason}` : ''}`] as [string, React.ReactNode]] : []),
        ]} />
        <Card title={t('adm.finance.col.lines', undefined, locale)}>
          {open.lines.length === 0 ? <p className="db-muted">{t('adm.finance.lines.none', undefined, locale)}</p> : (
            <div className="db-table-wrap"><table className="db-table" data-testid="fin-settlement-lines">
              <thead><tr><th scope="col">#</th><th scope="col">{t('adm.finance.col.date', undefined, locale)}</th><th scope="col">{t('adm.finance.col.type', undefined, locale)}</th><th scope="col">{t('adm.finance.col.order', undefined, locale)}</th><th scope="col">{t('adm.finance.col.grossSales', undefined, locale)}</th><th scope="col">{t('adm.finance.col.commission', undefined, locale)}</th><th scope="col">{t('adm.finance.col.netAmount', undefined, locale)}</th></tr></thead>
              <tbody>{open.lines.map((l) => <tr key={l.line}><td>{l.line}</td><td>{fmtDateTime(l.occurred_at, locale)}</td><td>{code(l.type, locale)}</td><td>{l.order_number ?? '—'}</td><td>{fm(l.gross_sales, locale)}</td><td>{fm(l.commission, locale)}</td><td><b>{fm(l.amount, locale)}</b></td></tr>)}</tbody>
            </table></div>
          )}
        </Card>
        <Card title={t('adm.finance.tab.payouts', undefined, locale)}>
          {open.payouts.length === 0 ? <p className="db-muted" data-testid="fin-settlement-payouts-empty">{t('adm.finance.payouts.none', undefined, locale)}</p> : (
            <ul className="db-timeline" data-testid="fin-settlement-payouts">{open.payouts.map((p) => <li key={p.id} data-status={p.status}><span className="db-timeline__dot" /><span><b>{p.reference}</b> · {fm(p.amount, locale)} · {code(p.method, locale)} · <Chip value={p.status} locale={locale} dot={false} /><small className="db-muted"> · {fmtDateTime(p.requested_at, locale)}</small></span></li>)}</ul>
          )}
        </Card>
        <Card title={t('adm.pay.actions', undefined, locale)}>
          <Actions testId="fin-settlement-actions" prefix="fin-settlement" busy={busy} locale={locale} items={[
            open.can.approve && { id: 'approve', label: t('adm.finance.approve', undefined, locale), primary: true, onClick: () => setAsk('approve') },
            open.can.start_payout && { id: 'start-payout', label: t('adm.finance.startPayout', undefined, locale), primary: true, onClick: () => setAsk('start_payout') },
            open.can.record_external_payout && { id: 'record-external', label: t('adm.finance.recordExternal', undefined, locale), onClick: () => setAsk('record_external_payout') },
            open.can.cancel && { id: 'cancel', label: t('adm.finance.withdraw', undefined, locale), onClick: () => setAsk('cancel') },
            open.can.export && { id: 'export', label: t('adm.finance.exportCsv', undefined, locale), onClick: () => { void exportCsv() } },
          ]}>
            <p className="db-muted" data-testid="fin-no-manual-paid">{t('adm.finance.noManualPaid', undefined, locale)} {t('adm.finance.livePayouts', { state: open.live_payouts }, locale)}</p>
            {notice && <p className="db-muted" role="status" data-testid="fin-notice">{notice}</p>}
            {failed && <p className="db-error" role="alert" data-testid="fin-error">{failed}</p>}
          </Actions>
        </Card>
      </div>}
    </Drawer>

    {(ask === 'generate' || ask === 'preview') && <FormDialog key={ask} title={t(ask === 'preview' ? 'adm.finance.generate.preview' : 'adm.finance.generate', undefined, locale)} text={t(ask === 'preview' ? 'adm.finance.generate.previewText' : 'adm.finance.generate.text', undefined, locale)} fields={generateFields} validate={checkGenerate} confirmLabel={t(ask === 'preview' ? 'adm.finance.generate.preview' : 'adm.finance.generate', undefined, locale)} busy={busy} error={failed} onConfirm={(v) => generate(v, ask === 'preview')} onCancel={() => setAsk(null)} locale={locale} testId="fin-generate-dialog" />}
    {open && ask === 'approve' && <FormDialog key="approve" title={t('adm.finance.approve', undefined, locale)} text={t('adm.finance.approve.text', undefined, locale)} impact={[t('adm.finance.confirm.settlement', { ref: open.reference, restaurant: open.restaurant.name }, locale), t('adm.finance.confirm.net', { amount: fm(open.summary.net_payable, locale), currency: open.currency }, locale)]} fields={[{ name: 'note', label: t('adm.finance.f.noteOptional', undefined, locale), type: 'textarea' }]} confirmLabel={t('adm.finance.approve', undefined, locale)} busy={busy} onConfirm={(v) => run(() => repo.approveSettlement(open.id, v.note || null, open.version))} onCancel={() => setAsk(null)} locale={locale} testId="fin-action-dialog" />}
    {open && ask === 'cancel' && <FormDialog key="cancel" title={t('adm.finance.withdraw', undefined, locale)} text={t('adm.finance.withdraw.text', undefined, locale)} fields={[reason(10)]} danger confirmLabel={t('adm.finance.withdraw', undefined, locale)} busy={busy} onConfirm={(v) => run(() => repo.cancelSettlement(open.id, v.reason))} onCancel={() => setAsk(null)} locale={locale} testId="fin-action-dialog" />}
    {open && ask === 'start_payout' && <FormDialog key="start" title={t('adm.finance.startPayout', undefined, locale)} text={t('adm.finance.startPayout.text', undefined, locale)} impact={[t('adm.finance.confirm.settlement', { ref: open.reference, restaurant: open.restaurant.name }, locale), t('adm.finance.confirm.payout', { amount: fm(open.summary.net_payable, locale), currency: open.currency }, locale), t('adm.finance.confirm.provider', undefined, locale)]} fields={[{ name: 'note', label: t('adm.finance.f.noteOptional', undefined, locale), type: 'textarea' }]} confirmLabel={t('adm.finance.startPayout.confirm', undefined, locale)} busy={busy} onConfirm={(v) => run(() => repo.startPayout(open.id, v.note || null))} onCancel={() => setAsk(null)} locale={locale} testId="fin-action-dialog" />}
    {open && ask === 'record_external_payout' && <FormDialog key="external" title={t('adm.finance.recordExternal', undefined, locale)} text={t('adm.finance.recordExternal.text', undefined, locale)}
      impact={[t('adm.finance.confirm.settlement', { ref: open.reference, restaurant: open.restaurant.name }, locale), t('adm.finance.confirm.external', { amount: fm(open.summary.net_payable, locale), currency: open.currency }, locale)]}
      fields={[
        { name: 'external_reference', label: t('adm.finance.f.externalReference', undefined, locale), required: true, minLength: 4 },
        { name: 'paid_on', label: t('adm.finance.f.paidOn', undefined, locale), type: 'date', required: true },
        { name: 'evidence_reference', label: t('adm.finance.f.evidence', undefined, locale), required: true, minLength: 4, hint: t('adm.finance.f.evidenceHint', undefined, locale) },
        reason(10),
      ]} confirmLabel={t('adm.finance.recordExternal.confirm', undefined, locale)} busy={busy}
      onConfirm={(v) => run(() => repo.recordExternalPayout(open.id, { externalReference: v.external_reference, paidOn: v.paid_on, amountMinor: open.summary.net_payable.amount, currency: open.currency, reason: v.reason, evidenceReference: v.evidence_reference }))} onCancel={() => setAsk(null)} locale={locale} testId="fin-action-dialog" />}
  </>
}

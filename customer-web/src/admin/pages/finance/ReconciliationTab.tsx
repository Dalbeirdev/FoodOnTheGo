import { useEffect, useState } from 'react'
import { Card, Drawer, Tabs } from '../../../dashboard/components/ui'
import { t } from '../../../i18n/strings'
import { useAdmin } from '../../AdminContext'
import type { CheckResultDto, DifferenceAction, DifferenceDetailDto, DifferenceDto, ProviderSettlementDetailDto, ProviderSettlementDto, RunDto } from '../../api/ApiAdminFinanceRepository'
import { Badge, DataTable, Details, DevNote, type Column } from '../../components/DataTable'
import { Cell, fmtDateTime, useLoad } from '../shared'
import { PAGE_SIZE, actionError, code, fm, type FinanceTabProps } from './helpers'
import { RunsView } from './RunsView'
import { Actions, Chip, Facts, FormDialog, type FormField } from './shared'

/**
 * Reconciliation (Module 36): every place where two financial records that should agree do not, and the payment
 * provider's own settlement records. A PROVIDER settlement is what the provider settled to the platform — it is not a
 * restaurant settlement. Nothing here changes a provider record, a match or a result by hand: a difference is closed
 * with a resolution and a note, a check reads and reports, and an import reads the provider's records of one day.
 */
type Ask = DifferenceAction | 'import' | 'check'
const CHECKS = ['LEDGER', 'INTERNAL', 'PROVIDER'] as const

export function ReconciliationTab({ repo, s, set }: FinanceTabProps) {
  const a = useAdmin(); const locale = a.locale; const manage = a.can('finance.reconciliation.manage')
  // The record of finance jobs needs the overview permission: without it the view is not offered.
  const canRuns = a.can('finance.view')
  const view = s.view === 'provider' ? 'provider' : s.view === 'runs' && canRuns ? 'runs' : 'differences'; const page = Number(s.page) || 1; const closed = s.queue === 'closed'
  const differences = useLoad(() => (view === 'differences' ? repo.differences({ open: !closed, page, pageSize: PAGE_SIZE }) : Promise.resolve(null)), [repo, view, closed, page])
  const provider = useLoad(() => (view === 'provider' ? repo.providerSettlements({ page, pageSize: PAGE_SIZE }) : Promise.resolve(null)), [repo, view, page])
  const [open, setOpen] = useState<DifferenceDetailDto | null>(null); const [record, setRecord] = useState<ProviderSettlementDetailDto | null>(null)
  const [ask, setAsk] = useState<Ask | null>(null); const [busy, setBusy] = useState(false); const [failed, setFailed] = useState<string | null>(null)
  const [result, setResult] = useState<{ check?: CheckResultDto; run?: RunDto } | null>(null)
  const show = async (id: string) => { setFailed(null); try { setOpen(await repo.difference(id)) } catch (e) { setFailed(actionError(e, locale)) } }
  const showRecord = async (id: string) => { setFailed(null); try { setRecord(await repo.providerSettlement(id)) } catch (e) { setFailed(actionError(e, locale)) } }
  useEffect(() => { if (s.open && view === 'differences' && s.open !== open?.id) void show(s.open) }, [s.open]) // eslint-disable-line react-hooks/exhaustive-deps
  const close = () => { setOpen(null); setRecord(null); setFailed(null); if (s.open) set({ open: '', page: s.page }) }
  const act = async (action: DifferenceAction, v: Record<string, string>) => {
    if (!open) return
    setBusy(true); setFailed(null)
    try { setOpen(await repo.differenceAction(open.id, action, { resolution: v.resolution, note: v.note || null })); setAsk(null); void differences.reload() }
    catch (e) { setFailed(actionError(e, locale)); setAsk(null); try { setOpen(await repo.difference(open.id)) } catch { /* the drawer keeps what it had */ } }
    setBusy(false)
  }
  /** An import or a check: the answer is the run the backend recorded, shown as it is. */
  const job = async (work: () => Promise<{ check?: CheckResultDto; run?: RunDto }>) => {
    setBusy(true); setFailed(null)
    try { setResult(await work()); setAsk(null); void differences.reload(); void provider.reload() } catch (e) { setFailed(actionError(e, locale)) }
    setBusy(false)
  }

  const columns: Array<Column<DifferenceDto>> = [
    { id: 'type', label: t('adm.finance.col.type', undefined, locale), primary: true, render: (d) => <Cell primary={code(d.type, locale)} secondary={`${code(d.source, locale)}${d.subject?.reference ? ` · ${d.subject.reference}` : ''}`} /> },
    { id: 'severity', label: t('adm.finance.col.severity', undefined, locale), render: (d) => <Chip value={d.severity} locale={locale} dot={false} /> },
    { id: 'expected', label: t('adm.finance.col.expected', undefined, locale), align: 'end', hideMobile: true, render: (d) => fm(d.expected, locale) },
    { id: 'actual', label: t('adm.finance.col.actual', undefined, locale), align: 'end', hideMobile: true, render: (d) => fm(d.actual, locale) },
    { id: 'difference', label: t('adm.finance.col.difference', undefined, locale), align: 'end', render: (d) => <b>{fm(d.difference, locale)}{d.currency ? <> <Badge tone="muted">{d.currency}</Badge></> : null}</b> },
    { id: 'provider', label: t('adm.finance.col.providerReference', undefined, locale), hideMobile: true, render: (d) => d.provider_reference ?? '—' },
    { id: 'seen', label: t('adm.finance.col.firstSeen', undefined, locale), hideMobile: true, render: (d) => <Cell primary={fmtDateTime(d.first_seen_at, locale)} secondary={t('adm.finance.seen', { n: d.seen_count }, locale)} /> },
    { id: 'status', label: t('adm.finance.col.status', undefined, locale), render: (d) => <Chip value={d.status} locale={locale} /> },
  ]
  const providerColumns: Array<Column<ProviderSettlementDto>> = [
    { id: 'ref', label: t('adm.finance.col.reference', undefined, locale), primary: true, render: (x) => <Cell primary={x.reference} secondary={`${x.provider}${x.provider_mode ? ` · ${x.provider_mode}` : ''}`} /> },
    { id: 'amount', label: t('adm.finance.col.amount', undefined, locale), align: 'end', render: (x) => <b>{fm(x.amount, locale)} <Badge tone="muted">{x.currency}</Badge></b> },
    { id: 'fees', label: t('adm.finance.col.providerFees', undefined, locale), align: 'end', hideMobile: true, render: (x) => `${fm(x.fees, locale)} + ${fm(x.tax_on_fees, locale)}` },
    { id: 'lines', label: t('adm.finance.col.lines', undefined, locale), align: 'end', hideMobile: true, render: (x) => x.lines.count.toLocaleString(locale) },
    { id: 'status', label: t('adm.finance.col.status', undefined, locale), hideMobile: true, render: (x) => code(x.status, locale) },
    { id: 'recon', label: t('adm.finance.col.reconciliation', undefined, locale), render: (x) => <Chip value={x.reconciliation_status} locale={locale} /> },
    { id: 'settled', label: t('adm.finance.col.settled', undefined, locale), hideMobile: true, render: (x) => fmtDateTime(x.settled_at ?? x.provider_created_at, locale) },
  ]
  const note = (min?: number): FormField => ({ name: 'note', label: t(min ? 'adm.finance.f.note' : 'adm.finance.f.noteOptional', undefined, locale), type: 'textarea', required: !!min, minLength: min })
  const source = provider.data?.source; const last = provider.data?.last_import
  const runFacts = (r: RunDto) => <Details rows={[[t('adm.finance.run.kind', undefined, locale), code(r.kind, locale)], [t('adm.finance.col.status', undefined, locale), <Chip key="s" value={r.status} locale={locale} dot={false} />], [t('adm.finance.run.problems', undefined, locale), String(r.problems)], [t('adm.finance.run.when', undefined, locale), `${fmtDateTime(r.started_at, locale)} – ${fmtDateTime(r.finished_at, locale)}`]]} />

  return <>
    <Tabs tabs={[{ id: 'differences', label: t('adm.finance.recon.differences', undefined, locale), count: differences.data?.open?.total }, { id: 'provider', label: t('adm.finance.recon.provider', undefined, locale) }, ...(canRuns ? [{ id: 'runs', label: t('adm.finance.runs.title', undefined, locale) }] : [])]} value={view} onChange={(v) => set({ view: v === 'differences' ? '' : v, page: '1', queue: 'all' })} label={t('adm.finance.recon.views', undefined, locale)} testId="fin-view" />
    {manage && <div className="db-toolbar" data-testid="fin-recon-jobs">
      <button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => { setFailed(null); setAsk('check') }} data-testid="fin-check-run">{t('adm.finance.check.run', undefined, locale)}</button>
      <button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => { setFailed(null); setAsk('import') }} data-testid="fin-import-day">{t('adm.finance.import', undefined, locale)}</button>
    </div>}
    {result && (
      <Card title={t('adm.finance.check.result', undefined, locale)} actions={<button type="button" className="db-btn db-btn--ghost db-btn--sm" onClick={() => setResult(null)}>{t('dash.action.close', undefined, locale)}</button>}>
        <div data-testid="fin-check-result">
          {result.check && <p><b>{code(result.check.check, locale, 'adm.finance.check')}</b></p>}
          {(result.run ?? result.check?.run) ? <>{runFacts((result.run ?? result.check?.run) as RunDto)}<Facts value={(result.run ?? result.check?.run)?.result} /></> : <Facts value={result.check?.result} />}
        </div>
      </Card>
    )}

    {view === 'differences' && <>
      <Card title={t('adm.finance.recon.differences', undefined, locale)} subtitle={t('adm.finance.recon.differences.subtitle', undefined, locale)} actions={<label className="db-check"><input type="checkbox" checked={closed} onChange={(e) => set({ queue: e.target.checked ? 'closed' : 'all', page: '1' })} data-testid="fin-differences-closed" /> <span>{t('adm.finance.recon.showClosed', undefined, locale)}</span></label>}>
        <DataTable columns={columns} rows={differences.data?.items ?? []} keyOf={(d) => d.id} state={differences.state} total={differences.data?.total ?? 0} page={page} pageSize={PAGE_SIZE} onPage={(p) => set({ page: String(p) })} empty={{ icon: 'check', title: t(closed ? 'adm.finance.recon.noneClosed' : 'adm.finance.recon.noneOpen', undefined, locale) }} onRetry={() => { void differences.reload() }} locale={locale} testId="fin-differences-table" rowProps={(d) => ({ 'data-testid': 'fin-difference-row', 'data-id': d.id, 'data-type': d.type, 'data-status': d.status })} caption={t('adm.finance.recon.differences', undefined, locale)} onRowClick={(d) => { void show(d.id) }} actions={(d) => <button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => { void show(d.id) }}>{t('adm.table.view', undefined, locale)}</button>} />
      </Card>
    </>}
    {view === 'provider' && <>
      {source && (
        <p className="adm-devnote" role="note" data-testid="fin-recon-source" data-status={source.status}>
          {t('adm.finance.recon.source', undefined, locale)}: <b>{source.status}</b>{source.payment_driver ? ` · ${source.payment_driver}` : ''}{source.mode ? ` · ${source.mode}` : ''} · <b>{t('adm.finance.recon.razorpay', { state: source.razorpay_settlement_api }, locale)}</b> · {t(source.scheduled ? 'adm.finance.recon.scheduled' : 'adm.finance.recon.notScheduled', undefined, locale)} · {last ? t('adm.finance.recon.lastImport', { date: fmtDateTime(last.finished_at ?? last.started_at, locale), status: last.status }, locale) : t('adm.finance.recon.neverImported', undefined, locale)}
        </p>
      )}
      <Card title={t('adm.finance.recon.provider', undefined, locale)} subtitle={t('adm.finance.recon.provider.subtitle', undefined, locale)}>
        <DataTable columns={providerColumns} rows={provider.data?.items ?? []} keyOf={(x) => x.id} state={provider.state} total={provider.data?.total ?? 0} page={page} pageSize={PAGE_SIZE} onPage={(p) => set({ page: String(p) })} empty={{ icon: 'payments', title: t('adm.finance.recon.provider.empty', undefined, locale) }} onRetry={() => { void provider.reload() }} locale={locale} testId="fin-provider-settlements-table" rowProps={(x) => ({ 'data-testid': 'fin-provider-settlement-row', 'data-id': x.id, 'data-ref': x.reference, 'data-status': x.reconciliation_status })} caption={t('adm.finance.recon.provider', undefined, locale)} onRowClick={(x) => { void showRecord(x.id) }} actions={(x) => <button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => { void showRecord(x.id) }}>{t('adm.table.view', undefined, locale)}</button>} />
      </Card>
    </>}
    {view === 'runs' && <RunsView repo={repo} s={s} set={set} />}
    <DevNote>{t('adm.finance.recon.note', undefined, locale)}</DevNote>
    {failed && !open && !record && !ask && <p className="db-error" role="alert" data-testid="fin-error">{failed}</p>}

    <Drawer open={!!open} onClose={close} title={open ? code(open.type, locale) : ''} wide>
      {open && <div className="db-grid" style={{ gap: 16 }} data-testid="fin-difference" data-status={open.status} data-version={open.version}>
        <Details rows={[
          [t('adm.finance.col.status', undefined, locale), <Chip key="s" value={open.status} locale={locale} />],
          [t('adm.finance.col.severity', undefined, locale), <Chip key="v" value={open.severity} locale={locale} dot={false} />],
          [t('adm.finance.col.source', undefined, locale), `${code(open.source, locale)}${open.provider ? ` · ${open.provider}` : ''}`],
          [t('adm.finance.col.subject', undefined, locale), open.subject ? `${code(open.subject.type, locale)} · ${open.subject.reference ?? open.subject.id ?? '—'}` : '—'],
          [t('adm.finance.col.restaurant', undefined, locale), open.restaurant?.name ?? '—'],
          [t('adm.finance.col.expected', undefined, locale), fm(open.expected, locale)],
          [t('adm.finance.col.actual', undefined, locale), fm(open.actual, locale)],
          [t('adm.finance.col.difference', undefined, locale), <b key="d" data-testid="fin-difference-amount">{fm(open.difference, locale)}{open.currency ? ` (${open.currency})` : ''}</b>],
          [t('adm.finance.col.providerReference', undefined, locale), open.provider_reference ?? '—'],
          [t('adm.finance.col.firstSeen', undefined, locale), `${fmtDateTime(open.first_seen_at, locale)} · ${t('adm.finance.seen', { n: open.seen_count }, locale)} · ${fmtDateTime(open.last_seen_at, locale)}`],
          [t('adm.finance.col.assigned', undefined, locale), open.assigned_to?.name ?? '—'],
          ...(open.resolution ? [[t('adm.finance.col.resolution', undefined, locale), `${code(open.resolution, locale)}${open.resolution_note ? ` · ${open.resolution_note}` : ''}${open.resolved_by ? ` · ${open.resolved_by.name}` : ''} · ${fmtDateTime(open.resolved_at, locale)}`] as [string, React.ReactNode]] : []),
        ]} />
        <Card title={t('adm.finance.col.detail', undefined, locale)}><Facts value={open.detail} testId="fin-difference-detail" /></Card>
        <Card title={t('adm.pay.actions', undefined, locale)}>
          <Actions testId="fin-difference-actions" prefix="fin-difference" busy={busy} locale={locale} items={[
            open.can.investigate && { id: 'investigate', label: t('adm.finance.diff.investigate', undefined, locale), onClick: () => setAsk('investigate') },
            open.can.resolve && { id: 'resolve', label: t('adm.finance.diff.resolve', undefined, locale), primary: true, onClick: () => setAsk('resolve') },
            open.can.ignore && { id: 'ignore', label: t('adm.finance.diff.ignore', undefined, locale), onClick: () => setAsk('ignore') },
          ]}>
            {failed && <p className="db-error" role="alert" data-testid="fin-error">{failed}</p>}
          </Actions>
        </Card>
      </div>}
    </Drawer>
    <Drawer open={!!record} onClose={close} title={record ? t('adm.finance.recon.record', { ref: record.reference }, locale) : ''} wide>
      {record && <div className="db-grid" style={{ gap: 16 }} data-testid="fin-provider-settlement" data-status={record.reconciliation_status}>
        <Details rows={[
          [t('adm.finance.col.reconciliation', undefined, locale), <Chip key="r" value={record.reconciliation_status} locale={locale} />],
          [t('adm.finance.col.status', undefined, locale), `${code(record.status, locale)} · ${record.provider}${record.provider_mode ? ` · ${record.provider_mode}` : ''}`],
          [t('adm.finance.col.amount', undefined, locale), <b key="a">{fm(record.amount, locale)} ({record.currency})</b>],
          [t('adm.finance.col.providerFees', undefined, locale), `${fm(record.fees, locale)} + ${fm(record.tax_on_fees, locale)}`],
          [t('adm.finance.col.lines', undefined, locale), `${record.lines.count} · ${t('adm.finance.recon.credits', { amount: fm(record.lines.credits, locale) }, locale)} · ${t('adm.finance.recon.debits', { amount: fm(record.lines.debits, locale) }, locale)}`],
          [t('adm.finance.col.bankReference', undefined, locale), record.bank_reference ?? '—'],
          [t('adm.finance.col.settled', undefined, locale), fmtDateTime(record.settled_at, locale)],
          [t('adm.finance.col.imported', undefined, locale), `${fmtDateTime(record.imported_at, locale)} · ${t(record.posted_to_ledger ? 'adm.finance.recon.posted' : 'adm.finance.recon.notPosted', undefined, locale)}`],
        ]} />
        <Card title={t('adm.finance.col.lines', undefined, locale)} subtitle={record.items_truncated ? t('adm.finance.recon.truncated', undefined, locale) : undefined}>
          {record.items.length === 0 ? <p className="db-muted">{t('adm.finance.lines.none', undefined, locale)}</p> : (
            <div className="db-table-wrap"><table className="db-table" data-testid="fin-provider-items">
              <thead><tr><th scope="col">{t('adm.finance.col.type', undefined, locale)}</th><th scope="col">{t('adm.finance.col.reference', undefined, locale)}</th><th scope="col">{t('adm.finance.col.amount', undefined, locale)}</th><th scope="col">{t('adm.finance.col.providerFees', undefined, locale)}</th><th scope="col">{t('adm.finance.col.match', undefined, locale)}</th></tr></thead>
              <tbody>{record.items.map((i) => <tr key={i.id} data-match={i.match_status}><td>{code(i.type, locale)}</td><td>{i.reference}{i.payment_reference ? ` · ${i.payment_reference}` : ''}</td><td>{fm(i.amount, locale)}</td><td>{fm(i.fee, locale)} + {fm(i.tax_on_fee, locale)}</td><td><Chip value={i.match_status} locale={locale} dot={false} /></td></tr>)}</tbody>
            </table></div>
          )}
        </Card>
      </div>}
    </Drawer>

    {open && ask === 'investigate' && <FormDialog key="investigate" title={t('adm.finance.diff.investigate', undefined, locale)} text={t('adm.finance.diff.investigate.text', undefined, locale)} fields={[note()]} confirmLabel={t('adm.finance.diff.investigate', undefined, locale)} busy={busy} onConfirm={(v) => act('investigate', v)} onCancel={() => setAsk(null)} locale={locale} testId="fin-action-dialog" />}
    {open && ask === 'resolve' && <FormDialog key="resolve" title={t('adm.finance.diff.resolve', undefined, locale)} text={t('adm.finance.diff.resolve.text', undefined, locale)} fields={[{ name: 'resolution', label: t('adm.finance.col.resolution', undefined, locale), type: 'select', options: open.resolutions.map((r) => ({ value: r, label: code(r, locale) })) }, note(10)]} confirmLabel={t('adm.finance.diff.resolve', undefined, locale)} busy={busy} onConfirm={(v) => act('resolve', v)} onCancel={() => setAsk(null)} locale={locale} testId="fin-action-dialog" />}
    {open && ask === 'ignore' && <FormDialog key="ignore" title={t('adm.finance.diff.ignore', undefined, locale)} text={t('adm.finance.diff.ignore.text', undefined, locale)} fields={[note(10)]} danger confirmLabel={t('adm.finance.diff.ignore', undefined, locale)} busy={busy} onConfirm={(v) => act('ignore', v)} onCancel={() => setAsk(null)} locale={locale} testId="fin-action-dialog" />}
    {ask === 'import' && <FormDialog key="import" title={t('adm.finance.import', undefined, locale)} text={t('adm.finance.import.text', undefined, locale)} fields={[{ name: 'day', label: t('adm.finance.f.day', undefined, locale), type: 'date', required: true }]} confirmLabel={t('adm.finance.import', undefined, locale)} busy={busy} error={failed} onConfirm={(v) => job(async () => ({ run: await repo.importDay(v.day) }))} onCancel={() => setAsk(null)} locale={locale} testId="fin-job-dialog" />}
    {ask === 'check' && <FormDialog key="check" title={t('adm.finance.check.run', undefined, locale)} text={t('adm.finance.check.text', undefined, locale)} fields={[{ name: 'check', label: t('adm.finance.f.check', undefined, locale), type: 'select', options: CHECKS.map((c) => ({ value: c, label: code(c, locale, 'adm.finance.check') })) }]} confirmLabel={t('adm.finance.check.run', undefined, locale)} busy={busy} error={failed} onConfirm={(v) => job(async () => ({ check: await repo.runCheck(v.check === 'INTERNAL' ? 'INTERNAL' : v.check === 'PROVIDER' ? 'PROVIDER' : 'LEDGER') }))} onCancel={() => setAsk(null)} locale={locale} testId="fin-job-dialog" />}
  </>
}

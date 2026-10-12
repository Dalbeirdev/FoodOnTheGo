import { useEffect, useState } from 'react'
import { Card, Drawer, Tabs } from '../../../dashboard/components/ui'
import { t } from '../../../i18n/strings'
import { useAdmin } from '../../AdminContext'
import type { PayoutAction, PayoutDetailDto, PayoutDto } from '../../api/ApiAdminFinanceRepository'
import { Badge, DataTable, Details, DevNote, type Column } from '../../components/DataTable'
import { Cell, fmtDateTime, useLoad } from '../shared'
import { PAGE_SIZE, actionError, code, fm, type FinanceTabProps } from './helpers'
import { Actions, Chip, FormDialog } from './shared'

/**
 * Restaurant payouts (Module 36). LIVE PAYOUT = DISABLED: the only provider on this platform is the development one.
 * A payout is paid on the provider's own record of it (or, for one made outside, on its bank reference and evidence) —
 * no control here sets that. What can be done to a payout is what the backend's `can` offers: ask the provider again,
 * send again, or withdraw one that was never sent.
 */
const QUEUES = ['required', 'processing', 'unknown', 'review_required', 'paid', 'failed', 'cancelled'] as const
const ACTIONS: PayoutAction[] = ['reconcile', 'retry', 'cancel']

export function PayoutsTab({ repo, s, set }: FinanceTabProps) {
  const a = useAdmin(); const locale = a.locale
  const page = Number(s.page) || 1
  const { data, state, reload } = useLoad(() => repo.payouts({ queue: s.queue, page, pageSize: PAGE_SIZE }), [repo, s.queue, page])
  const [open, setOpen] = useState<PayoutDetailDto | null>(null)
  const [ask, setAsk] = useState<PayoutAction | null>(null); const [busy, setBusy] = useState(false); const [failed, setFailed] = useState<string | null>(null)
  const show = async (id: string) => { setFailed(null); try { setOpen(await repo.payout(id)) } catch (e) { setFailed(actionError(e, locale)) } }
  useEffect(() => { if (s.open && s.open !== open?.id) void show(s.open) }, [s.open]) // eslint-disable-line react-hooks/exhaustive-deps
  const close = () => { setOpen(null); setFailed(null); if (s.open) set({ open: '', page: s.page }) }
  const act = async (action: PayoutAction, reason: string) => {
    if (!open) return
    setBusy(true); setFailed(null)
    try { setOpen(await repo.payoutAction(open.id, action, reason)); setAsk(null); void reload() }
    catch (e) { setFailed(actionError(e, locale)); setAsk(null); try { setOpen(await repo.payout(open.id)) } catch { /* the drawer keeps what it had */ } }
    setBusy(false)
  }
  const columns: Array<Column<PayoutDto>> = [
    { id: 'ref', label: t('adm.finance.col.reference', undefined, locale), primary: true, render: (p) => <Cell primary={p.reference} secondary={p.settlement?.reference ?? '—'} /> },
    { id: 'restaurant', label: t('adm.finance.col.restaurant', undefined, locale), render: (p) => <span dir="auto">{p.restaurant.name}</span> },
    { id: 'amount', label: t('adm.finance.col.amount', undefined, locale), align: 'end', render: (p) => <b>{fm(p.amount, locale)} <Badge tone="muted">{p.currency}</Badge></b> },
    { id: 'method', label: t('adm.finance.col.method', undefined, locale), hideMobile: true, render: (p) => <span>{code(p.method, locale)}{p.test_only && <> <Badge tone="amber">{t('adm.finance.testOnly', undefined, locale)}</Badge></>}</span> },
    { id: 'status', label: t('adm.finance.col.status', undefined, locale), render: (p) => <span><Chip value={p.status} locale={locale} />{p.needs_review && <> <Badge tone="red">{t('adm.finance.needsReview', undefined, locale)}</Badge></>}</span> },
    { id: 'requested', label: t('adm.finance.col.requested', undefined, locale), hideMobile: true, render: (p) => <Cell primary={fmtDateTime(p.requested_at, locale)} secondary={p.completed_at ? t('adm.finance.completedAt', { date: fmtDateTime(p.completed_at, locale) }, locale) : ''} /> },
  ]
  const tabs = [{ id: 'all', label: t('adm.table.all', undefined, locale) }, ...QUEUES.map((q) => ({ id: q as string, label: code(q.toUpperCase(), locale), count: data?.queues?.[q] }))]
  const provider = data?.provider
  return <>
    {provider && (
      <p className="adm-devnote" role="note" data-testid="fin-payout-provider" data-status={provider.status}>
        {t('adm.finance.provider.payout', undefined, locale)}: <b>{provider.status}</b>{provider.driver ? ` · ${provider.driver}` : ''}{provider.mode ? ` · ${provider.mode}` : ''} · <b>{t('adm.finance.livePayout', { state: provider.live_payout }, locale)}</b> · {t('adm.finance.provider.adapter', { state: code(provider.real_adapter, locale) }, locale)} · {provider.provider_account}
      </p>
    )}
    <Tabs tabs={tabs} value={tabs.some((x) => x.id === s.queue) ? s.queue : 'all'} onChange={(queue) => set({ queue, page: '1' })} label={t('adm.finance.queues', undefined, locale)} testId="fin-queue" />
    <Card title={t('adm.finance.payouts.title', undefined, locale)} subtitle={t('adm.finance.payouts.subtitle', undefined, locale)}>
      <DataTable columns={columns} rows={data?.items ?? []} keyOf={(p) => p.id} state={state} total={data?.total ?? 0} page={page} pageSize={PAGE_SIZE} onPage={(p) => set({ page: String(p) })} empty={{ icon: 'payments', title: t('adm.finance.payouts.empty', undefined, locale) }} onRetry={() => { void reload() }} locale={locale} testId="fin-payouts-table" rowProps={(p) => ({ 'data-testid': 'fin-payout-row', 'data-id': p.id, 'data-ref': p.reference, 'data-status': p.status })} caption={t('adm.finance.payouts.title', undefined, locale)} onRowClick={(p) => { void show(p.id) }} actions={(p) => <button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => { void show(p.id) }}>{t('adm.table.view', undefined, locale)}</button>} />
    </Card>
    <DevNote>{t('adm.finance.payouts.note', undefined, locale)}</DevNote>
    {failed && !open && <p className="db-error" role="alert" data-testid="fin-error">{failed}</p>}

    <Drawer open={!!open} onClose={close} title={open ? t('adm.finance.payout.title', { ref: open.reference }, locale) : ''} wide>
      {open && <div className="db-grid" style={{ gap: 16 }} data-testid="fin-payout" data-status={open.status} data-version={open.version}>
        {open.needs_review && <p className="db-error" role="alert" data-testid="fin-payout-review">{t('adm.finance.reviewReason', { reason: code(open.review_reason ?? open.status, locale) }, locale)}</p>}
        <Details rows={[
          [t('adm.finance.col.status', undefined, locale), <span key="s" data-testid="fin-payout-status"><Chip value={open.status} locale={locale} />{open.provider_status ? <small className="db-muted"> · {t('adm.finance.providerSays', { status: open.provider_status }, locale)}</small> : null}</span>],
          [t('adm.finance.col.amount', undefined, locale), <b key="a" data-testid="fin-payout-amount">{fm(open.amount, locale)} ({open.currency})</b>],
          [t('adm.finance.col.settlement', undefined, locale), open.settlement?.reference ?? '—'],
          [t('adm.finance.col.restaurant', undefined, locale), <span key="r" dir="auto">{open.restaurant.name}</span>],
          [t('adm.finance.col.method', undefined, locale), <span key="m">{code(open.method, locale)} · {open.provider}{open.provider_mode ? ` · ${open.provider_mode}` : ''}{open.test_only && <> <Badge tone="amber">{t('adm.finance.testOnly', undefined, locale)}</Badge></>}</span>],
          [t('adm.finance.col.destination', undefined, locale), open.destination ?? '—'],
          [t('adm.finance.col.providerReference', undefined, locale), open.provider_payout_reference ?? '—'],
          ...(open.external ? [[t('adm.finance.col.external', undefined, locale), `${open.external.reference ?? '—'} · ${open.external.paid_on ?? '—'} · ${open.external.evidence_reference ?? '—'}`] as [string, React.ReactNode]] : []),
          ...(open.failure ? [[t('adm.finance.col.failure', undefined, locale), <span key="f" data-testid="fin-payout-failure">{open.failure.message ?? open.failure.code} <small className="db-muted">({open.failure.code})</small></span>] as [string, React.ReactNode]] : []),
          [t('adm.finance.col.requested', undefined, locale), `${fmtDateTime(open.requested_at, locale)}${open.requested_by ? ` · ${open.requested_by.name}` : ''}`],
          [t('adm.finance.col.submitted', undefined, locale), `${fmtDateTime(open.submitted_at, locale)} · ${t('adm.finance.attempts', { n: open.submit_attempts }, locale)}`],
          [t('adm.finance.col.completed', undefined, locale), fmtDateTime(open.completed_at ?? open.failed_at, locale)],
          [t('adm.finance.col.lastAsked', undefined, locale), `${fmtDateTime(open.last_reconciled_at, locale)}${open.next_action_at ? ` · ${t('adm.finance.nextLook', { date: fmtDateTime(open.next_action_at, locale) }, locale)}` : ''}`],
          ...(open.note ? [[t('adm.finance.col.note', undefined, locale), open.note] as [string, React.ReactNode]] : []),
        ]} />
        <Card title={t('adm.pay.actions', undefined, locale)}>
          <Actions testId="fin-payout-actions" prefix="fin-payout" busy={busy} locale={locale} items={ACTIONS.map((x) => open.can[x] && { id: x, label: t(`adm.finance.payout.${x}`, undefined, locale), primary: x === 'retry', onClick: () => setAsk(x) })}>
            <p className="db-muted" data-testid="fin-no-manual-paid">{t('adm.finance.payout.noManualPaid', undefined, locale)}</p>
            {failed && <p className="db-error" role="alert" data-testid="fin-error">{failed}</p>}
          </Actions>
        </Card>
      </div>}
    </Drawer>
    {open && ask && <FormDialog key={ask} title={t(`adm.finance.payout.${ask}`, undefined, locale)} text={t(`adm.finance.payout.${ask}.text`, undefined, locale)} impact={ask === 'retry' ? [t('adm.finance.confirm.payout', { amount: fm(open.amount, locale), currency: open.currency }, locale), t('adm.finance.confirm.provider', undefined, locale)] : undefined}
      fields={[{ name: 'reason', label: t('adm.finance.f.reason', undefined, locale), type: 'textarea', required: true, minLength: 3 }]} danger={ask === 'cancel'} confirmLabel={t(`adm.finance.payout.${ask}`, undefined, locale)} busy={busy} onConfirm={(v) => act(ask, v.reason)} onCancel={() => setAsk(null)} locale={locale} testId="fin-action-dialog" />}
  </>
}

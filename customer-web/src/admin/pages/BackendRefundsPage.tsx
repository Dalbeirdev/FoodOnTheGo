import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { ApiError } from '../../api/client'
import { Card, Drawer, Tabs } from '../../dashboard/components/ui'
import { t } from '../../i18n/strings'
import { useAdmin } from '../AdminContext'
import { BASE } from '../AdminLayout'
import { ApiAdminRefundRepository, REFUND_QUEUES, REFUND_REASONS, type BackendDispute, type BackendRefund, type BackendRefundDetail, type RefundAction, type RefundQueue } from '../api/ApiAdminRefundRepository'
import { Badge, DataTable, Details, DevNote, ReasonDialog, Select, Toolbar, useUrlState, type Column } from '../components/DataTable'
import { Cell, fmtDateTime, money, useLoad, usePageTitle } from './shared'

/**
 * Refund Management on the backend (Module 35): the real refund records — no development rows.
 *
 * The queues (Required · Awaiting approval · Approved · Processing · Failed · Review required · Completed), the filters
 * and the search are the backend's. The detail shows what the backend holds: the payment's balance, the provider's
 * references and status, every provider attempt, the timeline, the provider's events and the audit trail.
 *
 * The actions are the backend's five — approve, turn down, send to the provider, try again, ask the provider again —
 * and a button exists only when the backend says the refund's state AND the caller's permissions allow it. Sending a
 * refund shows the order, the payment, the amount, the currency and the reason first. There is no "mark as refunded"
 * and no status editor: a refund is REFUNDED only on the provider's own record. Nothing on this page computes an amount.
 */
const TONE: Record<string, string> = { REQUIRED: 'amber', REQUESTED: 'amber', APPROVED: 'blue', SUBMITTING: 'blue', SUBMISSION_UNKNOWN: 'red', PROCESSING: 'blue', REFUNDED: 'green', FAILED: 'red', CANCELLED: 'muted', REVIEW_REQUIRED: 'red' }
const DISPUTE_TONE: Record<string, string> = { OPEN: 'red', ACTION_REQUIRED: 'red', UNDER_REVIEW: 'amber', WON: 'green', LOST: 'muted', CLOSED: 'muted', UNKNOWN: 'amber' }
const DEFAULTS = { view: 'refunds', queue: 'all', q: '', type: 'all', reason: 'all', review: '', page: '1', open: '' }
/** Which actions need a written reason (the backend requires it) and which are a decision that cannot be taken back. */
const NEEDS_REASON: Record<RefundAction, boolean> = { approve: false, reject: true, execute: false, retry: true, reconcile: true }

export function BackendRefundsPage({ repository }: { repository?: ApiAdminRefundRepository }) {
  const a = useAdmin(); const locale = a.locale; usePageTitle('adm.nav.refunds')
  const repo = useMemo(() => repository ?? new ApiAdminRefundRepository(), [repository])
  const [s, set] = useUrlState(DEFAULTS)
  const [open, setOpen] = useState<BackendRefundDetail | null>(null)
  const [asking, setAsking] = useState<RefundAction | null>(null); const [busy, setBusy] = useState(false); const [failed, setFailed] = useState<string | null>(null)
  const filter = useMemo(() => ({ query: s.q, queue: (REFUND_QUEUES.includes(s.queue as RefundQueue) ? s.queue : 'all') as RefundQueue | 'all', type: s.type as 'FULL' | 'PARTIAL' | 'all', reason: s.reason, review: s.review === '1', page: Number(s.page) || 1, pageSize: 10 }), [s])
  const { data, state, reload } = useLoad(() => repo.list(filter), [repo, filter])
  const queues = useLoad(() => repo.queues(), [repo])
  const canDisputes = a.can('disputes.view')
  const disputes = useLoad(() => (s.view === 'disputes' && canDisputes ? repo.disputes({ query: s.q, page: Number(s.page) || 1, pageSize: 10 }) : Promise.resolve(null)), [repo, s.view, s.q, s.page, canDisputes])

  const pill = (status: string) => <Badge tone={TONE[status] ?? 'muted'} dot>{t(`adm.refund.status.${status}`, undefined, locale)}</Badge>
  const reasonText = (code: string, detail: string | null) => `${t(`adm.refund.reason.${code}`, undefined, locale)}${detail ? ` · ${detail}` : ''}`
  const show = async (id: string) => { setFailed(null); try { setOpen(await repo.get(id)) } catch { setFailed(t('adm.error.loadTitle', undefined, locale)) } }
  // A link from an order or a payment opens the refund it names.
  useEffect(() => { if (s.open && s.open !== open?.id) void show(s.open) }, [s.open]) // eslint-disable-line react-hooks/exhaustive-deps
  const close = () => { setOpen(null); setFailed(null); if (s.open) set({ open: '' }) }

  const act = async (action: RefundAction, reason: string) => {
    if (!open) return
    setBusy(true); setFailed(null)
    try {
      setOpen(await repo.act(open.id, action, reason.trim() || null, open.version))
      setAsking(null); void reload(); void queues.reload()
    } catch (e) {
      // The backend's refusal, in the backend's words — and the refund as it is now, so the screen does not show a state that is gone.
      setFailed(e instanceof ApiError && e.kind === 'forbidden' ? t('adm.refund.forbidden', undefined, locale) : e instanceof ApiError && e.message ? e.message : t('adm.refund.actionFailed', undefined, locale))
      setAsking(null)
      try { setOpen(await repo.get(open.id)) } catch { /* the drawer keeps what it had */ }
    } finally { setBusy(false) }
  }

  const columns: Array<Column<BackendRefund>> = [
    { id: 'ref', label: t('adm.refunds.col.reference', undefined, locale), primary: true, render: (r) => <Cell primary={r.reference} secondary={r.paymentReference} /> },
    { id: 'order', label: t('adm.refunds.col.order', undefined, locale), render: (r) => (r.orderNumber ? <Link to={`${BASE}/orders/${r.orderNumber}`} className="db-link" onClick={(e) => e.stopPropagation()}>{r.orderNumber}</Link> : <span className="db-muted">{t('adm.refund.noOrder', undefined, locale)}</span>) },
    { id: 'restaurant', label: t('adm.refunds.col.restaurant', undefined, locale), hideMobile: true, render: (r) => <span dir="auto">{r.restaurantName}</span> },
    { id: 'amount', label: t('adm.refunds.col.requested', undefined, locale), align: 'end', render: (r) => <span>{money(r.amountMinor, r.currency, locale)} <Badge tone="muted">{r.currency}</Badge></span> },
    { id: 'kind', label: t('adm.refunds.col.kind', undefined, locale), hideMobile: true, render: (r) => t(`adm.refunds.kind.${r.type === 'FULL' ? 'full' : 'partial'}`, undefined, locale) },
    { id: 'reason', label: t('adm.refunds.col.reason', undefined, locale), hideMobile: true, render: (r) => reasonText(r.reasonCode, r.reasonDetail) },
    { id: 'status', label: t('adm.refunds.col.status', undefined, locale), render: (r) => <span data-status={r.status}>{pill(r.status)}{r.needsReview && <> <Badge tone="red">{t('adm.refund.needsReview', undefined, locale)}</Badge></>}</span> },
    { id: 'provider', label: t('adm.payments.col.provider', undefined, locale), hideMobile: true, render: (r) => `${r.provider}${r.providerMode ? ` · ${r.providerMode}` : ''}` },
    { id: 'date', label: t('adm.refunds.col.date', undefined, locale), hideMobile: true, render: (r) => <Cell primary={fmtDateTime(r.requestedAt ?? '', locale)} secondary={r.completedAt ? t('adm.refund.completedAt', { date: fmtDateTime(r.completedAt, locale) }, locale) : ''} /> },
  ]
  const disputeColumns: Array<Column<BackendDispute>> = [
    { id: 'ref', label: t('adm.dispute.col.reference', undefined, locale), primary: true, render: (d) => <Cell primary={d.providerReference} secondary={d.paymentReference} /> },
    { id: 'order', label: t('adm.refunds.col.order', undefined, locale), render: (d) => (d.orderNumber ? <Link to={`${BASE}/orders/${d.orderNumber}`} className="db-link">{d.orderNumber}</Link> : '—') },
    { id: 'amount', label: t('adm.dispute.col.amount', undefined, locale), align: 'end', render: (d) => <span>{money(d.amountMinor, d.currency, locale)} <Badge tone="muted">{d.currency}</Badge></span> },
    { id: 'deducted', label: t('adm.dispute.col.deducted', undefined, locale), align: 'end', hideMobile: true, render: (d) => money(d.deductedMinor, d.currency, locale) },
    { id: 'reason', label: t('adm.dispute.col.reason', undefined, locale), hideMobile: true, render: (d) => `${d.reasonCode ?? '—'}${d.phase ? ` · ${d.phase}` : ''}` },
    { id: 'status', label: t('adm.refunds.col.status', undefined, locale), render: (d) => <span data-status={d.status}><Badge tone={DISPUTE_TONE[d.status] ?? 'muted'} dot>{t(`adm.dispute.status.${d.status}`, undefined, locale)}</Badge></span> },
    { id: 'respond', label: t('adm.dispute.col.respondBy', undefined, locale), hideMobile: true, render: (d) => (d.open && d.respondByAt ? fmtDateTime(d.respondByAt, locale) : '—') },
  ]
  const tabs = [{ id: 'all' as const, label: t('adm.table.all', undefined, locale), count: queues.data?.total }, ...REFUND_QUEUES.filter((q) => q !== 'required').map((q) => ({ id: q, label: t(`adm.refund.queue.${q}`, undefined, locale), count: queues.data?.[q] }))]
  const confirmText = (action: RefundAction) => t(`adm.refund.${action}.text`, undefined, locale)
  /** What is about to be sent to the provider — shown before the administrator confirms. Every figure is the backend's. */
  const impact = open && asking === 'execute' ? [
    t('adm.refund.confirm.order', { order: open.orderNumber ?? t('adm.refund.noOrder', undefined, locale) }, locale),
    t('adm.refund.confirm.payment', { payment: open.paymentReference, provider: open.provider }, locale),
    t('adm.refund.confirm.amount', { amount: money(open.approvedMinor ?? open.amountMinor, open.currency, locale), currency: open.currency }, locale),
    t('adm.refund.confirm.reason', { reason: reasonText(open.reasonCode, open.reasonDetail) }, locale),
    t('adm.refund.confirm.final', undefined, locale),
  ] : asking === 'retry' && open ? [t('adm.refund.confirm.amount', { amount: money(open.approvedMinor ?? open.amountMinor, open.currency, locale), currency: open.currency }, locale)] : undefined

  return (
    <div className="db-page" data-testid="adm-refunds" data-source="backend">
      <div className="db-page__head"><div><h1 className="db-page__title">{t('adm.refunds.title', undefined, locale)}</h1><p className="db-page__lead">{t('adm.refund.lead', undefined, locale)}</p></div></div>
      {canDisputes && <Tabs tabs={[{ id: 'refunds', label: t('adm.nav.refunds', undefined, locale) }, { id: 'disputes', label: t('adm.dispute.title', undefined, locale) }]} value={s.view === 'disputes' ? 'disputes' : 'refunds'} onChange={(view) => set({ view, page: '1', q: '' })} label={t('adm.refund.views', undefined, locale)} />}

      {s.view !== 'disputes' && <>
        <Tabs tabs={tabs} value={filter.queue} onChange={(queue) => set({ queue, page: '1' })} label={t('adm.refunds.tabs', undefined, locale)} />
        <Card>
          <Toolbar search={s.q} onSearch={(q) => set({ q, page: '1' })} placeholder={t('adm.refund.search', undefined, locale)} locale={locale}>
            <Select label={t('adm.refunds.col.kind', undefined, locale)} value={s.type} onChange={(v) => set({ type: v, page: '1' })} options={[{ value: 'all', label: t('adm.status.all', undefined, locale) }, { value: 'FULL', label: t('adm.refunds.kind.full', undefined, locale) }, { value: 'PARTIAL', label: t('adm.refunds.kind.partial', undefined, locale) }]} testId="filter-type" />
            <Select label={t('adm.refunds.col.reason', undefined, locale)} value={s.reason} onChange={(v) => set({ reason: v, page: '1' })} options={[{ value: 'all', label: t('adm.status.all', undefined, locale) }, ...REFUND_REASONS.map((v) => ({ value: v, label: t(`adm.refund.reason.${v}`, undefined, locale) }))]} testId="filter-reason" />
            <label className="db-check"><input type="checkbox" checked={s.review === '1'} onChange={(e) => set({ review: e.target.checked ? '1' : '', page: '1' })} data-testid="filter-review" /> <span>{t('adm.refund.onlyReview', undefined, locale)}</span></label>
          </Toolbar>
          <DataTable columns={columns} rows={data?.items ?? []} keyOf={(r) => r.id} state={state} total={data?.total ?? 0} page={filter.page} pageSize={10} onPage={(page) => set({ page: String(page) })} empty={{ icon: 'refunds', title: t('adm.refund.empty', undefined, locale) }} onRetry={() => { void reload() }} locale={locale} testId="refunds-table" caption={t('adm.refunds.title', undefined, locale)} onRowClick={(r) => { void show(r.id) }} actions={(r) => <button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => { void show(r.id) }}>{t('adm.table.view', undefined, locale)}</button>} />
        </Card>
        <DevNote>{t('adm.refund.note', undefined, locale)}</DevNote>
      </>}

      {s.view === 'disputes' && canDisputes && <>
        <Card>
          <Toolbar search={s.q} onSearch={(q) => set({ q, page: '1' })} placeholder={t('adm.dispute.search', undefined, locale)} locale={locale} />
          <DataTable columns={disputeColumns} rows={disputes.data?.items ?? []} keyOf={(d) => d.id} state={disputes.state} total={disputes.data?.total ?? 0} page={Number(s.page) || 1} pageSize={10} onPage={(page) => set({ page: String(page) })} empty={{ icon: 'refunds', title: t('adm.dispute.empty', undefined, locale) }} onRetry={() => { void disputes.reload() }} locale={locale} testId="disputes-table" caption={t('adm.dispute.title', undefined, locale)} />
        </Card>
        <DevNote>{t('adm.dispute.note', undefined, locale)}</DevNote>
      </>}
      {failed && !open && <p className="db-error" role="alert" data-testid="refund-error">{failed}</p>}

      <Drawer open={!!open} onClose={close} title={open ? t('adm.refund.title', { ref: open.reference }, locale) : ''} wide>
        {open && <div className="db-grid" style={{ gap: 16 }} data-testid="refund-drawer" data-status={open.status} data-version={open.version}>
          {open.needsReview && <p className="db-error" role="alert" data-testid="refund-attention">{t(`adm.refund.attention.${open.status}`, { reason: open.reviewReason ?? open.failure?.code ?? '' }, locale)}</p>}
          {open.disputes.some((d) => d.open) && <p className="db-error" role="alert" data-testid="refund-dispute-open">{t('adm.refund.disputeOpen', undefined, locale)}</p>}
          <Details rows={[
            [t('adm.refunds.col.status', undefined, locale), <span key="s" data-testid="refund-status">{pill(open.status)}{open.providerStatus ? <small className="db-muted"> · {t('adm.refund.providerSays', { status: open.providerStatus }, locale)}</small> : null}</span>],
            [t('adm.refunds.col.requested', undefined, locale), <span key="a" data-testid="refund-amount">{money(open.amountMinor, open.currency, locale)} ({open.currency}) · {t(`adm.refunds.kind.${open.type === 'FULL' ? 'full' : 'partial'}`, undefined, locale)}</span>],
            [t('adm.refund.approved', undefined, locale), open.approval ? <span key="ap" data-testid="refund-approval">{money(open.approvedMinor ?? 0, open.currency, locale)} · {t(`adm.refund.approval.${open.approval.mode}`, { by: open.approval.by ?? '' }, locale)}{open.approval.at ? ` · ${fmtDateTime(open.approval.at, locale)}` : ''}{open.approval.note ? ` · ${open.approval.note}` : ''}</span> : <span key="ap" data-testid="refund-approval">{t('adm.refund.notApproved', undefined, locale)}</span>],
            [t('adm.refunds.col.reason', undefined, locale), <span key="r">{reasonText(open.reasonCode, open.reasonDetail)}{open.note ? <small className="db-muted"> · {open.note}</small> : null}</span>],
            [t('adm.refund.requestedBy', undefined, locale), `${t(`adm.refund.by.${open.requestedByType}`, undefined, locale)}${open.requestedAt ? ` · ${fmtDateTime(open.requestedAt, locale)}` : ''}`],
            [t('adm.refund.policy', undefined, locale), open.policyDecision ? `${t(`adm.refund.policy.${open.policyDecision}`, undefined, locale)}${open.policyReason ? ` · ${open.policyReason}` : ''}` : '—'],
            [t('adm.refunds.col.order', undefined, locale), open.order ? <span key="o"><Link to={`${BASE}/orders/${open.order.number}`} className="db-link">{open.order.number}</Link> · {open.order.status} · {money(open.order.totalMinor, open.currency, locale)}</span> : t('adm.refund.noOrder', undefined, locale)],
            [t('adm.payments.col.reference', undefined, locale), <span key="p" data-testid="refund-payment"><Link to={`${BASE}/payments?q=${encodeURIComponent(open.paymentId)}`} className="db-link">{open.paymentReference}</Link> · {open.paymentStatus ?? '—'}</span>],
            [t('adm.refunds.col.restaurant', undefined, locale), <span key="rn" dir="auto">{open.restaurantName}</span>],
            ...(open.failure ? [[t('adm.refund.failure', undefined, locale), <span key="f" data-testid="refund-failure">{open.failure.message ?? open.failure.code} <small className="db-muted">({open.failure.code}{open.failure.retryable ? ` · ${t('adm.refund.retryable', undefined, locale)}` : ''})</small></span>] as [string, React.ReactNode]] : []),
            ...(open.closed ? [[t('adm.refund.closed', undefined, locale), `${open.closed.by ?? ''}${open.closed.at ? ` · ${fmtDateTime(open.closed.at, locale)}` : ''}${open.closed.note ? ` · ${open.closed.note}` : ''}`] as [string, React.ReactNode]] : []),
          ]} />
          <Card title={t('adm.refund.balance', undefined, locale)} subtitle={t('adm.refund.balance.subtitle', undefined, locale)}>
            <Details rows={[
              [t('adm.refund.balance.paid', undefined, locale), <span key="1" data-testid="refund-balance-paid">{money(open.balance.paidMinor, open.currency, locale)}</span>],
              [t('adm.refund.balance.refunded', undefined, locale), <span key="2" data-testid="refund-balance-refunded">{money(open.balance.refundedMinor, open.currency, locale)}</span>],
              [t('adm.refund.balance.inFlight', undefined, locale), <span key="3" data-testid="refund-balance-inflight">{money(open.balance.inFlightMinor, open.currency, locale)}</span>],
              ...(open.balance.chargedBackMinor > 0 ? [[t('adm.refund.balance.chargedBack', undefined, locale), money(open.balance.chargedBackMinor, open.currency, locale)] as [string, React.ReactNode]] : []),
              [t('adm.refund.balance.available', undefined, locale), <b key="5" data-testid="refund-balance-available">{money(open.balance.availableMinor, open.currency, locale)}</b>],
            ]} />
          </Card>
          <Card title={t('adm.refund.provider', undefined, locale)}>
            <Details rows={[
              [t('adm.payments.col.provider', undefined, locale), `${open.provider}${open.providerMode ? ` · ${open.providerMode}` : ''}`],
              [t('adm.refund.providerRefs', undefined, locale), <span key="x" data-testid="refund-provider-refs">{open.providerPayment ?? '—'} / {open.providerRefund ?? '—'}</span>],
              [t('adm.refund.receipt', undefined, locale), open.receipt ?? '—'],
              ...(open.traceReference ? [[t('adm.refund.trace', undefined, locale), open.traceReference] as [string, React.ReactNode]] : []),
              [t('adm.pay.reconciliation', undefined, locale), `${t('adm.pay.reconciliation.runs', { n: open.reconcileRuns }, locale)}${open.lastReconciledAt ? ` · ${fmtDateTime(open.lastReconciledAt, locale)}` : ''}${open.nextLookAt ? ` · ${t('adm.refund.nextLook', { date: fmtDateTime(open.nextLookAt, locale) }, locale)}` : ''}`],
            ]} />
            {open.attempts.length === 0 ? <p className="db-muted" data-testid="refund-attempts-empty">{t('adm.refund.attempts.none', undefined, locale)}</p> : (
              <ol className="db-timeline" data-testid="refund-attempts">{open.attempts.map((x, i) => (
                <li key={i} data-operation={x.operation} data-outcome={x.outcome}><span className={`db-timeline__dot ${['FAILED', 'REJECTED', 'UNKNOWN', 'MISMATCH'].includes(x.outcome) ? 'db-timeline__dot--stop' : ''}`} /><span><b>{t(`adm.refund.attempt.${x.operation}`, undefined, locale)}</b> → {x.outcome}{x.failureCode ? ` (${x.failureCode})` : ''}<small className="db-muted"> · {t('adm.refund.attempt.generation', { n: x.generation }, locale)} · {x.source}{x.latencyMs !== null ? ` · ${x.latencyMs} ms` : ''} · {fmtDateTime(x.at ?? '', locale)}</small></span></li>
              ))}</ol>
            )}
          </Card>
          <Card title={t('adm.pay.timeline', undefined, locale)}>
            <ol className="db-timeline" data-testid="refund-events">{open.timeline.map((e, i) => (
              <li key={i} data-type={e.type}><span className={`db-timeline__dot ${e.to === 'FAILED' || e.to === 'REVIEW_REQUIRED' ? 'db-timeline__dot--stop' : ''}`} /><span><b>{e.type}</b>{e.to && <> → {t(`adm.refund.status.${e.to}`, undefined, locale)}</>}<small className="db-muted"> · {e.source} · {fmtDateTime(e.at ?? '', locale)}</small></span></li>
            ))}</ol>
          </Card>
          <Card title={t('adm.pay.webhooks', undefined, locale)} subtitle={t('adm.pay.webhooks.subtitle', undefined, locale)}>
            {open.webhookEvents.length === 0 ? <p className="db-muted" data-testid="refund-webhooks-empty">{t('adm.pay.webhooks.none', undefined, locale)}</p> : (
              <ul className="db-timeline" data-testid="refund-webhooks">{open.webhookEvents.map((e) => (
                <li key={e.id} data-status={e.processingStatus}><span className="db-timeline__dot" /><span><b>{e.type}</b><small className="db-muted"> · {e.processingStatus}{e.outcome ? ` → ${e.outcome}` : ''}{e.deliveries > 1 ? ` · ${t('adm.pay.webhooks.deliveries', { n: e.deliveries }, locale)}` : ''} · {fmtDateTime(e.receivedAt ?? '', locale)}</small></span></li>
              ))}</ul>
            )}
          </Card>
          <Card title={t('adm.refund.audit', undefined, locale)}>
            {open.audit.length === 0 ? <p className="db-muted">{t('adm.refund.audit.none', undefined, locale)}</p> : (
              <ul className="db-timeline" data-testid="refund-audit">{open.audit.map((e, i) => (
                <li key={i} data-action={e.action}><span className="db-timeline__dot" /><span><b>{e.action}</b>{e.reason ? ` — ${e.reason}` : ''}<small className="db-muted"> · {e.actorType ?? ''} · {fmtDateTime(e.at ?? '', locale)}</small></span></li>
              ))}</ul>
            )}
          </Card>
          <Card title={t('adm.pay.actions', undefined, locale)}>
            <div className="db-actions" data-testid="refund-actions">
              {(['approve', 'reject', 'execute', 'retry', 'reconcile'] as RefundAction[]).filter((x) => open.actions[x]).map((x) => (
                <button key={x} type="button" className={`btn ${x === 'execute' || x === 'approve' ? 'btn--primary' : 'btn--outline'}`} data-testid={`refund-${x}`} disabled={busy} onClick={() => setAsking(x)}>{t(`adm.refund.${x}`, undefined, locale)}</button>
              ))}
            </div>
            {!Object.values(open.actions).some(Boolean) && <p className="db-muted" data-testid="refund-no-actions">{t(open.status === 'REFUNDED' || open.status === 'CANCELLED' ? 'adm.refund.noActions.final' : 'adm.refund.noActions', undefined, locale)}</p>}
            <p className="db-muted" data-testid="refund-no-mark-refunded">{t('adm.refund.noMarkRefunded', undefined, locale)}</p>
            {failed && <p className="db-error" role="alert" data-testid="refund-error">{failed}</p>}
          </Card>
        </div>}
      </Drawer>
      <ReasonDialog open={asking !== null} title={asking ? t(`adm.refund.${asking}`, undefined, locale) : ''} text={asking ? confirmText(asking) : ''} confirmLabel={asking ? t(`adm.refund.${asking}.confirm`, undefined, locale) : ''} onConfirm={(reason) => (asking ? act(asking, reason) : undefined)} onCancel={() => setAsking(null)} locale={locale} danger={asking === 'reject'} requireReason={asking ? NEEDS_REASON[asking] : true} impact={impact} busy={busy} testId="refund-action-dialog" />
    </div>
  )
}

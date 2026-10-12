import { useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { ApiError } from '../../api/client'
import { Card, Drawer } from '../../dashboard/components/ui'
import { t } from '../../i18n/strings'
import { useAdmin } from '../AdminContext'
import { BASE } from '../AdminLayout'
import { ApiAdminPaymentRepository, type BackendPayment, type BackendPaymentDetail, type BackendPaymentStatus } from '../api/ApiAdminPaymentRepository'
import { ApiAdminRefundRepository } from '../api/ApiAdminRefundRepository'
import { Badge, DataTable, Details, DevNote, ReasonDialog, Select, Toolbar, useUrlState, type Column } from '../components/DataTable'
import { PaymentMetricsCard } from './PaymentMetricsCard'
import { Cell, fmtDateTime, money, useLoad, usePageTitle } from './shared'

/**
 * Payment Management on the backend (Module 30): the real payment attempts — no development rows.
 *
 * An administrator can read (admin.payments.view) and, with admin.payments.reconcile, ask the provider again about a
 * payment, with a reason. That is all: there is no "mark as paid", nothing here edits an amount, a status or a
 * provider reference. Module 35: the payment's refunds are listed with links to the refund records, and a refund of a
 * payment that never became an order can be asked for (admin.refunds.create) — a request, never a status.
 */
const STATUSES: BackendPaymentStatus[] = ['CREATED', 'PROVIDER_READY', 'PROCESSING', 'PENDING', 'AUTHORIZED', 'VERIFIED', 'FAILED', 'CANCELLED', 'EXPIRED', 'UNKNOWN', 'REVIEW_REQUIRED']
const METHODS = ['UPI', 'CARD', 'NET_BANKING', 'WALLET']
const TONE: Record<string, string> = { VERIFIED: 'green', FAILED: 'red', REVIEW_REQUIRED: 'red', UNKNOWN: 'amber', PENDING: 'amber', AUTHORIZED: 'amber', PROCESSING: 'blue', PROVIDER_READY: 'blue', CREATED: 'muted', CANCELLED: 'muted', EXPIRED: 'muted' }
const DEFAULTS = { q: '', status: 'all', method: 'all', attention: '', page: '1' }

export function BackendPaymentsPage({ repository }: { repository?: ApiAdminPaymentRepository }) {
  const a = useAdmin(); const locale = a.locale; usePageTitle('adm.nav.payments')
  const repo = useMemo(() => repository ?? new ApiAdminPaymentRepository(), [repository])
  const [s, set] = useUrlState(DEFAULTS)
  const [open, setOpen] = useState<BackendPaymentDetail | null>(null)
  const [asking, setAsking] = useState(false); const [busy, setBusy] = useState(false); const [failed, setFailed] = useState<string | null>(null)
  // Module 35: asking for the refund of a payment that never became an order — a request in the refund domain.
  const [refunding, setRefunding] = useState(false); const navigate = useNavigate()
  const requestRefund = async (note: string) => {
    if (!open) return
    setBusy(true); setFailed(null)
    try { const created = await new ApiAdminRefundRepository().requestForPayment(open.id, note, `payment-refund-${open.id}`); setRefunding(false); navigate(`${BASE}/refunds?open=${encodeURIComponent(created.id)}`) }
    catch (e) { setFailed(e instanceof ApiError && e.message ? e.message : t('adm.refund.actionFailed', undefined, locale)); setRefunding(false) }
    finally { setBusy(false) }
  }
  const filter = useMemo(() => ({ query: s.q, status: s.status as BackendPaymentStatus | 'all', method: s.method, attention: s.attention === '1', page: Number(s.page) || 1, pageSize: 10 }), [s])
  const { data, state, reload } = useLoad(() => repo.list(filter), [repo, filter])
  const canReconcile = a.can('payments.reconcile')
  const status = (p: { status: string }) => <Badge tone={TONE[p.status] ?? 'muted'} dot>{t(`adm.pay.status.${p.status}`, undefined, locale)}</Badge>
  const show = async (p: BackendPayment) => { setFailed(null); try { setOpen(await repo.get(p.id)) } catch { setFailed(t('adm.error.loadTitle', undefined, locale)) } }
  const reconcile = async (reason: string) => {
    if (!open) return
    setBusy(true); setFailed(null)
    try { setOpen(await repo.reconcile(open.id, reason)); setAsking(false); void reload() } catch (e) { setFailed(e instanceof ApiError && e.kind === 'forbidden' ? t('adm.pay.reconcile.forbidden', undefined, locale) : t('adm.pay.reconcile.failed', undefined, locale)); setAsking(false) } finally { setBusy(false) }
  }

  const columns: Array<Column<BackendPayment>> = [
    { id: 'ref', label: t('adm.payments.col.reference', undefined, locale), primary: true, render: (p) => <Cell primary={p.supportReference} secondary={p.attemptNumber > 1 ? t('adm.pay.attempt', { n: p.attemptNumber }, locale) : p.provider} /> },
    { id: 'restaurant', label: t('adm.payments.col.restaurant', undefined, locale), hideMobile: true, render: (p) => <span dir="auto">{p.restaurantName}</span> },
    { id: 'method', label: t('adm.payments.col.method', undefined, locale), hideMobile: true, render: (p) => (p.methodType ? t(`adm.pay.method.${p.methodType}`, undefined, locale) : '—') },
    { id: 'amount', label: t('adm.payments.col.amount', undefined, locale), align: 'end', render: (p) => <span>{money(p.amountMinor, p.currency, locale)} <Badge tone="muted">{p.currency}</Badge></span> },
    { id: 'status', label: t('adm.payments.col.status', undefined, locale), render: (p) => <span data-status={p.status}>{status(p)}{p.needsAttention && <> <Badge tone="red">{t('adm.pay.attention', undefined, locale)}</Badge></>}{p.verifiedWithoutOrder && !p.needsAttention && <> <Badge tone="amber">{t('adm.pay.noOrder', undefined, locale)}</Badge></>}</span> },
    { id: 'date', label: t('adm.payments.col.date', undefined, locale), hideMobile: true, render: (p) => fmtDateTime(p.createdAt ?? '', locale) },
  ]

  return (
    <div className="db-page" data-testid="adm-payments" data-source="backend">
      <div className="db-page__head"><div><h1 className="db-page__title">{t('adm.payments.title', undefined, locale)}</h1><p className="db-page__lead">{t('adm.pay.lead', undefined, locale)}</p></div></div>
      <PaymentMetricsCard locale={locale} />
      <Card>
        <Toolbar search={s.q} onSearch={(q) => set({ q, page: '1' })} placeholder={t('adm.pay.search', undefined, locale)} locale={locale}>
          <Select label={t('adm.payments.col.status', undefined, locale)} value={s.status} onChange={(v) => set({ status: v, page: '1' })} options={[{ value: 'all', label: t('adm.status.all', undefined, locale) }, ...STATUSES.map((v) => ({ value: v, label: t(`adm.pay.status.${v}`, undefined, locale) }))]} testId="filter-status" />
          <Select label={t('adm.payments.col.method', undefined, locale)} value={s.method} onChange={(v) => set({ method: v, page: '1' })} options={[{ value: 'all', label: t('adm.pay.method.all', undefined, locale) }, ...METHODS.map((v) => ({ value: v, label: t(`adm.pay.method.${v}`, undefined, locale) }))]} testId="filter-method" />
          <label className="db-check"><input type="checkbox" checked={s.attention === '1'} onChange={(e) => set({ attention: e.target.checked ? '1' : '', page: '1' })} data-testid="filter-attention" /> <span>{t('adm.pay.onlyAttention', undefined, locale)}</span></label>
        </Toolbar>
        <DataTable columns={columns} rows={data?.items ?? []} keyOf={(p) => p.id} state={state} total={data?.total ?? 0} page={filter.page} pageSize={10} onPage={(page) => set({ page: String(page) })} empty={{ icon: 'payments', title: t('adm.pay.empty', undefined, locale) }} onRetry={() => { void reload() }} locale={locale} testId="payments-table" caption={t('adm.payments.title', undefined, locale)} onRowClick={(p) => { void show(p) }} />
      </Card>
      <DevNote>{t('adm.pay.note', undefined, locale)}</DevNote>
      {failed && !open && <p className="db-error" role="alert" data-testid="payment-error">{failed}</p>}

      <Drawer open={!!open} onClose={() => { setOpen(null); setFailed(null) }} title={open ? t('adm.payment.title', { ref: open.supportReference }, locale) : ''} wide>
        {open && <div className="db-grid" style={{ gap: 16 }} data-testid="payment-drawer" data-status={open.status} data-fulfillment={open.fulfillment}>
          {open.needsAttention && <p className="db-error" role="alert" data-testid="payment-attention">{t(open.status === 'REVIEW_REQUIRED' ? 'adm.pay.attention.review' : 'adm.pay.attention.recovery', { reason: open.statusReason ?? open.fulfillmentReason ?? '' }, locale)}</p>}
          {open.verifiedWithoutOrder && <p className="adm-devnote" role="note" data-testid="payment-no-order">{t('adm.pay.noOrder.text', undefined, locale)}</p>}
          <Details rows={[
            [t('adm.payments.col.status', undefined, locale), <span key="s">{status(open)}{open.statusReason ? <small className="db-muted"> · {open.statusReason}</small> : null}</span>],
            [t('adm.payments.col.amount', undefined, locale), `${money(open.amountMinor, open.currency, locale)} (${open.currency})`],
            [t('adm.pay.fulfillment', undefined, locale), <span key="f" data-testid="payment-fulfillment">{t(`adm.pay.fulfillment.${open.fulfillment}`, undefined, locale)}{open.fulfillmentReason ? ` · ${open.fulfillmentReason}` : ''}</span>],
            [t('adm.payments.col.restaurant', undefined, locale), <span key="r" dir="auto">{open.restaurantName}</span>],
            [t('adm.payments.col.method', undefined, locale), open.methodType ? `${t(`adm.pay.method.${open.methodType}`, undefined, locale)}${typeof open.methodSummary.last4 === 'string' ? ` •••• ${open.methodSummary.last4}` : typeof open.methodSummary.vpa === 'string' ? ` · ${open.methodSummary.vpa}` : ''}` : '—'],
            [t('adm.payments.col.provider', undefined, locale), `${open.provider} · ${open.providerMode}`],
            [t('adm.pay.providerRefs', undefined, locale), <span key="p" data-testid="payment-provider-refs">{open.providerOrder ?? '—'} / {open.providerPayment ?? '—'}</span>],
            [t('adm.payment.customer', undefined, locale), `${open.customerPhoneMasked ?? '—'}`],
            [t('adm.pay.checkout', undefined, locale), `${open.checkoutId.slice(0, 8)}… · ${open.checkoutStatus ?? t('adm.pay.checkoutGone', undefined, locale)}`],
            [t('adm.pay.reconciliation', undefined, locale), `${t('adm.pay.reconciliation.runs', { n: open.reconciliation.runs }, locale)}${open.reconciliation.lastAt ? ` · ${fmtDateTime(open.reconciliation.lastAt, locale)}` : ''}`],
          ]} />
          <Card title={t('adm.pay.timeline', undefined, locale)}>
            <ol className="db-timeline" data-testid="payment-events">{open.timeline.map((e, i) => (
              <li key={i} data-type={e.type} data-source={e.source}><span className={`db-timeline__dot ${e.to === 'FAILED' || e.to === 'REVIEW_REQUIRED' ? 'db-timeline__dot--stop' : ''}`} /><span><b>{t(`adm.pay.event.${e.type}`, undefined, locale)}</b>{e.to && <> → {t(`adm.pay.status.${e.to}`, undefined, locale)}</>}<small className="db-muted"> · {t(`adm.pay.source.${e.source}`, undefined, locale)} · {fmtDateTime(e.at ?? '', locale)}</small></span></li>
            ))}</ol>
          </Card>
          <Card title={t('adm.pay.webhooks', undefined, locale)} subtitle={t('adm.pay.webhooks.subtitle', undefined, locale)}>
            {open.webhookEvents.length === 0 ? <p className="db-muted" data-testid="payment-webhooks-empty">{t('adm.pay.webhooks.none', undefined, locale)}</p> : (
              <ul className="db-timeline" data-testid="payment-webhooks">{open.webhookEvents.map((e) => (
                <li key={e.id} data-status={e.processingStatus}><span className={`db-timeline__dot ${e.processingStatus === 'FAILED' ? 'db-timeline__dot--stop' : ''}`} /><span><b>{e.type}</b><small className="db-muted"> · {e.processingStatus}{e.outcome ? ` → ${e.outcome}` : ''}{e.deliveries > 1 ? ` · ${t('adm.pay.webhooks.deliveries', { n: e.deliveries }, locale)}` : ''} · {fmtDateTime(e.receivedAt ?? '', locale)}</small></span></li>
              ))}</ul>
            )}
          </Card>
          {/* Module 35: the refunds of this payment. The payment stays what it was — a refunded payment is still VERIFIED. */}
          <Card title={t('adm.order.refunds', undefined, locale)}>
            {open.disputes.some((d) => d.open) && <p className="db-error" role="alert" data-testid="payment-dispute-open">{t('adm.refund.disputeOpen', undefined, locale)}</p>}
            {open.refunds.length === 0 ? <p className="db-muted" data-testid="payment-refunds-empty">{t('adm.order.noRefunds', undefined, locale)}</p> : (
              <ul className="db-list" data-testid="payment-refunds">{open.refunds.map((r) => <li key={r.id} data-status={r.status}><Link to={`${BASE}/refunds?open=${encodeURIComponent(r.id)}`} className="db-link">{r.reference}</Link> · {money(r.amountMinor, r.currency, locale)} <Badge tone={r.status === 'REFUNDED' ? 'green' : r.status === 'FAILED' || r.status === 'REVIEW_REQUIRED' ? 'red' : r.status === 'CANCELLED' ? 'muted' : 'amber'} dot>{t(`adm.refund.status.${r.status}`, undefined, locale)}</Badge></li>)}</ul>
            )}
            {open.refundBalance && open.refunds.length > 0 && <p className="db-muted" data-testid="payment-refund-balance">{t('adm.refund.balance.line', { refunded: money(open.refundBalance.refundedMinor, open.currency, locale), inFlight: money(open.refundBalance.inFlightMinor, open.currency, locale), available: money(open.refundBalance.availableMinor, open.currency, locale) }, locale)}</p>}
            {open.canRequestRefund && a.can('refunds.create') && <button type="button" className="btn btn--outline" data-testid="payment-request-refund" disabled={busy} onClick={() => setRefunding(true)}>{t('adm.refund.requestPayment', undefined, locale)}</button>}
          </Card>
          <Card title={t('adm.pay.actions', undefined, locale)}>
            <div className="db-actions">
              {canReconcile ? <button type="button" className="btn btn--outline" data-testid="payment-reconcile" disabled={busy} onClick={() => setAsking(true)}>{t('adm.pay.reconcile', undefined, locale)}</button> : <p className="db-muted" data-testid="payment-reconcile-denied">{t('adm.pay.reconcile.noPermission', undefined, locale)}</p>}
            </div>
            <p className="db-muted" data-testid="payment-no-mark-paid">{t('adm.pay.noMarkPaid', undefined, locale)}</p>
            <p className="db-muted" data-testid="payment-refund-note">{t('adm.pay.refundNote', undefined, locale)}</p>
            {failed && <p className="db-error" role="alert" data-testid="payment-error">{failed}</p>}
          </Card>
        </div>}
      </Drawer>
      <ReasonDialog open={refunding} title={t('adm.refund.requestPayment', undefined, locale)} text={t('adm.refund.requestPayment.text', undefined, locale)} confirmLabel={t('adm.refund.request.confirm', undefined, locale)} onConfirm={(reason) => requestRefund(reason)} onCancel={() => setRefunding(false)} locale={locale} danger={false} busy={busy} testId="payment-refund-dialog" />
      <ReasonDialog open={asking} title={t('adm.pay.reconcile', undefined, locale)} text={t('adm.pay.reconcile.text', undefined, locale)} confirmLabel={t('adm.pay.reconcile.confirm', undefined, locale)} onConfirm={(reason) => reconcile(reason)} onCancel={() => setAsking(false)} locale={locale} danger={false} busy={busy} testId="payment-reconcile-dialog" />
    </div>
  )
}

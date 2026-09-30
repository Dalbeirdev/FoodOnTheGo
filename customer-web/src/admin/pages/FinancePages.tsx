import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { t } from '../../i18n/strings'
import { Card, Drawer, Tabs } from '../../dashboard/components/ui'
import { useAdmin } from '../AdminContext'
import { BASE } from '../AdminLayout'
import { Badge, DataTable, Details, DevNote, Select, Stat, Toolbar, useUrlState, type Column } from '../components/DataTable'
import type { AdminPayment, AdminRefund, PaymentState, RefundStatus, Settlement, SettlementStatus } from '../types'
import { Cell, StatusPill, fmtDateTime, money, useLoad, usePageTitle } from './shared'

const CURRENCIES = ['INR', 'USD', 'GBP', 'JPY', 'EUR', 'AED']
const PAY_STATES: PaymentState[] = ['CREATED', 'PENDING', 'AUTHORIZED', 'CAPTURED', 'FAILED', 'CANCELLED', 'REFUND_PENDING', 'PARTIALLY_REFUNDED', 'REFUNDED']
const P_DEFAULTS = { q: '', status: 'all', currency: 'all', page: '1' }

/** Payment Management (Module 18): attempts with provider references, safe details, event history and reconciliation readiness. */
export function PaymentsPage() {
  const a = useAdmin(); const locale = a.locale; usePageTitle('adm.nav.payments')
  const [s, set] = useUrlState(P_DEFAULTS); const [open, setOpen] = useState<AdminPayment | null>(null)
  const filter = useMemo(() => ({ query: s.q, status: s.status as PaymentState | 'all', currency: s.currency, page: Number(s.page) || 1, pageSize: 10 }), [s])
  const { data, state, reload } = useLoad(() => a.repos.payments.list(filter), [a.repos, filter])
  const { data: all } = useLoad(() => a.repos.payments.list({ pageSize: 500 }), [a.repos])
  const columns: Array<Column<AdminPayment>> = [
    { id: 'ref', label: t('adm.payments.col.reference', undefined, locale), primary: true, render: (p) => <Cell primary={p.reference} secondary={p.providerReference ?? '—'} /> },
    { id: 'order', label: t('adm.payments.col.order', undefined, locale), render: (p) => <Link to={`${BASE}/orders/${p.orderNumber}`} className="db-link" onClick={(e) => e.stopPropagation()}>{p.orderNumber}</Link> },
    { id: 'restaurant', label: t('adm.payments.col.restaurant', undefined, locale), hideMobile: true, render: (p) => <span dir="auto">{p.restaurantName}</span> },
    { id: 'method', label: t('adm.payments.col.method', undefined, locale), hideMobile: true, render: (p) => t(`adm.payment.method.${p.methodCategory}`, undefined, locale) },
    { id: 'amount', label: t('adm.payments.col.amount', undefined, locale), align: 'end', render: (p) => <span>{money(p.amountMinor, p.currency, locale)} <Badge tone="muted">{p.currency}</Badge></span> },
    { id: 'status', label: t('adm.payments.col.status', undefined, locale), render: (p) => <span><StatusPill status={p.status} prefix="adm.payState" dot />{p.reconciliation.mismatch && <> <Badge tone="red">{t('adm.payment.mismatch', undefined, locale)}</Badge></>}</span> },
    { id: 'date', label: t('adm.payments.col.date', undefined, locale), hideMobile: true, render: (p) => fmtDateTime(p.createdAt, locale) },
  ]
  const items = all?.items ?? []
  return (
    <div className="db-page" data-testid="adm-payments">
      <div className="db-page__head"><div><h1 className="db-page__title">{t('adm.payments.title', undefined, locale)}</h1><p className="db-page__lead">{t('adm.payments.lead', undefined, locale)}</p></div></div>
      <div className="adm-stats"><Stat label={t('adm.payments.kpi.successful', undefined, locale)} value={items.filter((p) => p.status === 'CAPTURED').length} tone="green" /><Stat label={t('adm.payments.kpi.failed', undefined, locale)} value={items.filter((p) => p.status === 'FAILED').length} tone="red" /><Stat label={t('adm.payments.kpi.refundPending', undefined, locale)} value={items.filter((p) => p.status === 'REFUND_PENDING').length} tone="amber" /><Stat label={t('adm.payments.kpi.mismatch', undefined, locale)} value={items.filter((p) => p.reconciliation.mismatch).length} tone={items.some((p) => p.reconciliation.mismatch) ? 'red' : undefined} /></div>
      <Card>
        <Toolbar search={s.q} onSearch={(q) => set({ q })} placeholder={t('adm.payments.search', undefined, locale)} locale={locale}>
          <Select label={t('adm.payments.col.status', undefined, locale)} value={s.status} onChange={(status) => set({ status })} options={[{ value: 'all', label: t('adm.status.all', undefined, locale) }, ...PAY_STATES.map((v) => ({ value: v, label: t(`adm.payState.${v}`, undefined, locale) }))]} testId="filter-status" />
          <Select label={t('adm.currency.all', undefined, locale)} value={s.currency} onChange={(currency) => set({ currency })} options={[{ value: 'all', label: t('adm.currency.all', undefined, locale) }, ...CURRENCIES.map((c) => ({ value: c, label: c }))]} testId="filter-currency" />
        </Toolbar>
        <DataTable columns={columns} rows={data?.items ?? []} keyOf={(p) => p.reference} state={state} total={data?.total ?? 0} page={filter.page} pageSize={10} onPage={(page) => set({ page: String(page) })} empty={{ icon: 'payments', title: t('adm.payments.empty', undefined, locale) }} onRetry={() => { void reload() }} locale={locale} testId="payments-table" caption={t('adm.payments.title', undefined, locale)} onRowClick={setOpen} actions={(p) => <button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => setOpen(p)}>{t('adm.table.view', undefined, locale)}</button>} />
      </Card>
      <DevNote>{t('adm.payment.methodNote', undefined, locale)}</DevNote>
      <Drawer open={!!open} onClose={() => setOpen(null)} title={open ? t('adm.payment.title', { ref: open.reference }, locale) : ''}>
        {open && <div className="db-grid" style={{ gap: 16 }} data-testid="payment-drawer">
          <Details rows={[[t('adm.payments.col.status', undefined, locale), <StatusPill key="s" status={open.status} prefix="adm.payState" dot />], [t('adm.payments.col.amount', undefined, locale), `${money(open.amountMinor, open.currency, locale)} (${open.currency})`], [t('adm.payments.col.order', undefined, locale), <Link key="o" to={`${BASE}/orders/${open.orderNumber}`} className="db-link">{open.orderNumber}</Link>], [t('adm.payments.col.restaurant', undefined, locale), open.restaurantName], [t('adm.payment.customer', undefined, locale), open.customerRef], [t('adm.payments.col.method', undefined, locale), t(`adm.payment.method.${open.methodCategory}`, undefined, locale)], [t('adm.payments.col.provider', undefined, locale), open.provider], [t('adm.payment.providerRef', undefined, locale), open.providerReference ?? '—'], [t('adm.payments.col.date', undefined, locale), fmtDateTime(open.createdAt, locale)]]} />
          <Card title={t('adm.payment.reconciliation', undefined, locale)} tone={open.reconciliation.mismatch ? 'warn' : undefined}><Details rows={[[t('adm.payment.expected', undefined, locale), money(open.reconciliation.expectedMinor, open.currency, locale)], [t('adm.payment.providerStatus', undefined, locale), open.reconciliation.providerStatus], [t('adm.payment.platformStatus', undefined, locale), t(`adm.payState.${open.reconciliation.platformStatus}`, undefined, locale)], ['', <Badge key="m" tone={open.reconciliation.mismatch ? 'red' : 'green'}>{open.reconciliation.mismatch ? t('adm.payment.mismatch', undefined, locale) : t('adm.payment.match', undefined, locale)}</Badge>]]} /></Card>
          <Card title={t('adm.payment.events', undefined, locale)}><ol className="db-timeline" data-testid="payment-events">{open.events.map((e, i) => <li key={i}><span className={`db-timeline__dot ${e.type === 'failed' ? 'db-timeline__dot--stop' : ''}`} /><span><b>{e.detail}</b><small className="db-muted"> · {e.type} · {fmtDateTime(e.at, locale)}</small></span></li>)}</ol></Card>
          <DevNote>{t('adm.payment.methodNote', undefined, locale)}</DevNote>
        </div>}
      </Drawer>
    </div>
  )
}

const R_TABS: Array<'all' | RefundStatus> = ['all', 'PENDING', 'PROCESSING', 'PARTIAL', 'COMPLETED', 'FAILED']
const R_DEFAULTS = { tab: 'all', q: '', page: '1' }
export function RefundsPage() {
  const a = useAdmin(); const locale = a.locale; usePageTitle('adm.nav.refunds')
  const [s, set] = useUrlState(R_DEFAULTS); const [open, setOpen] = useState<AdminRefund | null>(null)
  const filter = useMemo(() => ({ tab: (R_TABS.includes(s.tab as RefundStatus) ? s.tab : 'all') as 'all' | RefundStatus, query: s.q, page: Number(s.page) || 1, pageSize: 10 }), [s])
  const { data, state, reload } = useLoad(() => a.repos.refunds.list(filter), [a.repos, filter])
  const columns: Array<Column<AdminRefund>> = [
    { id: 'ref', label: t('adm.refunds.col.reference', undefined, locale), primary: true, render: (r) => <Cell primary={r.reference} secondary={r.paymentReference} /> },
    { id: 'order', label: t('adm.refunds.col.order', undefined, locale), render: (r) => <Link to={`${BASE}/orders/${r.orderNumber}`} className="db-link" onClick={(e) => e.stopPropagation()}>{r.orderNumber}</Link> },
    { id: 'restaurant', label: t('adm.refunds.col.restaurant', undefined, locale), hideMobile: true, render: (r) => <span dir="auto">{r.restaurantName}</span> },
    { id: 'requested', label: t('adm.refunds.col.requested', undefined, locale), align: 'end', render: (r) => <span>{money(r.requestedMinor, r.currency, locale)} <Badge tone="muted">{r.currency}</Badge></span> },
    { id: 'refunded', label: t('adm.refunds.col.refunded', undefined, locale), align: 'end', hideMobile: true, render: (r) => money(r.refundedMinor, r.currency, locale) },
    { id: 'kind', label: t('adm.refunds.col.kind', undefined, locale), hideMobile: true, render: (r) => t(`adm.refunds.kind.${r.kind}`, undefined, locale) },
    { id: 'status', label: t('adm.refunds.col.status', undefined, locale), render: (r) => <StatusPill status={r.status} prefix="adm.refundStatus" dot /> },
    { id: 'date', label: t('adm.refunds.col.date', undefined, locale), hideMobile: true, render: (r) => fmtDateTime(r.createdAt, locale) },
  ]
  return (
    <div className="db-page" data-testid="adm-refunds">
      <div className="db-page__head"><div><h1 className="db-page__title">{t('adm.refunds.title', undefined, locale)}</h1><p className="db-page__lead">{t('adm.refunds.lead', undefined, locale)}</p></div></div>
      <Tabs tabs={R_TABS.map((id) => ({ id, label: id === 'all' ? t('adm.table.all', undefined, locale) : t(`adm.refundStatus.${id}`, undefined, locale) }))} value={filter.tab} onChange={(tab) => set({ tab })} label={t('adm.refunds.tabs', undefined, locale)} />
      <Card>
        <Toolbar search={s.q} onSearch={(q) => set({ q })} placeholder={t('adm.refunds.search', undefined, locale)} locale={locale} />
        <DataTable columns={columns} rows={data?.items ?? []} keyOf={(r) => r.reference} state={state} total={data?.total ?? 0} page={filter.page} pageSize={10} onPage={(page) => set({ page: String(page) })} empty={{ icon: 'refunds', title: t('adm.refunds.empty', undefined, locale) }} onRetry={() => { void reload() }} locale={locale} testId="refunds-table" caption={t('adm.refunds.title', undefined, locale)} onRowClick={setOpen} actions={(r) => <button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => setOpen(r)}>{t('adm.table.view', undefined, locale)}</button>} />
      </Card>
      <DevNote>{t('adm.refunds.issueNote', undefined, locale)}</DevNote>
      <Drawer open={!!open} onClose={() => setOpen(null)} title={open?.reference ?? ''}>{open && <Details rows={[[t('adm.refunds.col.status', undefined, locale), <StatusPill key="s" status={open.status} prefix="adm.refundStatus" dot />], [t('adm.refunds.col.kind', undefined, locale), t(`adm.refunds.kind.${open.kind}`, undefined, locale)], [t('adm.refunds.col.requested', undefined, locale), `${money(open.requestedMinor, open.currency, locale)} (${open.currency})`], [t('adm.refunds.col.refunded', undefined, locale), money(open.refundedMinor, open.currency, locale)], [t('adm.refunds.col.order', undefined, locale), <Link key="o" to={`${BASE}/orders/${open.orderNumber}`} className="db-link">{open.orderNumber}</Link>], [t('adm.payments.col.reference', undefined, locale), open.paymentReference], [t('adm.refunds.col.restaurant', undefined, locale), open.restaurantName], [t('adm.refunds.col.reason', undefined, locale), open.reason], [t('adm.refunds.col.date', undefined, locale), fmtDateTime(open.createdAt, locale)], ['Processed', fmtDateTime(open.processedAt, locale)]]} />}</Drawer>
    </div>
  )
}

const S_STATUSES: SettlementStatus[] = ['PENDING', 'PROCESSING', 'PAID', 'FAILED', 'ON_HOLD']
const S_DEFAULTS = { status: 'all', currency: 'all', page: '1' }
export function SettlementsPage() {
  const a = useAdmin(); const locale = a.locale; usePageTitle('adm.nav.settlements')
  const [s, set] = useUrlState(S_DEFAULTS)
  const filter = useMemo(() => ({ status: s.status as SettlementStatus | 'all', currency: s.currency, page: Number(s.page) || 1, pageSize: 10 }), [s])
  const { data, state, reload } = useLoad(() => a.repos.settlements.list(filter), [a.repos, filter])
  const { data: totals } = useLoad(() => a.repos.settlements.totalsByCurrency(), [a.repos])
  const columns: Array<Column<Settlement>> = [
    { id: 'id', label: t('adm.settlements.col.id', undefined, locale), primary: true, render: (x) => <Cell primary={x.id} secondary={x.period} /> },
    { id: 'restaurant', label: t('adm.settlements.col.restaurant', undefined, locale), render: (x) => <Link to={`${BASE}/restaurants/${x.restaurantId}`} className="db-link" dir="auto">{x.restaurantName}</Link> },
    { id: 'orders', label: t('adm.settlements.col.orders', undefined, locale), align: 'end', hideMobile: true, render: (x) => x.orders.toLocaleString(locale) },
    { id: 'gross', label: t('adm.settlements.col.gross', undefined, locale), align: 'end', hideMobile: true, render: (x) => money(x.grossMinor, x.currency, locale) },
    { id: 'refunds', label: t('adm.settlements.col.refunds', undefined, locale), align: 'end', hideMobile: true, render: (x) => `−${money(x.refundsMinor, x.currency, locale)}` },
    { id: 'fees', label: t('adm.settlements.col.fees', undefined, locale), align: 'end', hideMobile: true, render: (x) => `−${money(x.feesMinor, x.currency, locale)}` },
    { id: 'adj', label: t('adm.settlements.col.adjustments', undefined, locale), align: 'end', hideMobile: true, render: (x) => (x.adjustmentsMinor ? money(x.adjustmentsMinor, x.currency, locale) : '—') },
    { id: 'net', label: t('adm.settlements.col.net', undefined, locale), align: 'end', render: (x) => <b>{money(x.netMinor, x.currency, locale)} <Badge tone="muted">{x.currency}</Badge></b> },
    { id: 'status', label: t('adm.settlements.col.status', undefined, locale), render: (x) => <StatusPill status={x.status} prefix="adm.settlementStatus" dot /> },
  ]
  return (
    <div className="db-page" data-testid="adm-settlements">
      <div className="db-page__head"><div><h1 className="db-page__title">{t('adm.settlements.title', undefined, locale)}</h1><p className="db-page__lead">{t('adm.settlements.lead', undefined, locale)}</p></div></div>
      <Card title={t('adm.settlements.totals', undefined, locale)} subtitle={t('adm.overview.gmvNote', undefined, locale)}><ul className="adm-money-list" data-testid="settlement-totals">{(totals ?? []).map((x) => <li key={x.currency}><b>{money(x.netMinor, x.currency, locale)}</b><small>{x.currency} · {t('adm.settlements.totalsNote', { n: x.count }, locale)}</small></li>)}</ul></Card>
      <Card>
        <Toolbar locale={locale}>
          <Select label={t('adm.settlements.col.status', undefined, locale)} value={s.status} onChange={(status) => set({ status })} options={[{ value: 'all', label: t('adm.status.all', undefined, locale) }, ...S_STATUSES.map((v) => ({ value: v, label: t(`adm.settlementStatus.${v}`, undefined, locale) }))]} testId="filter-status" />
          <Select label={t('adm.currency.all', undefined, locale)} value={s.currency} onChange={(currency) => set({ currency })} options={[{ value: 'all', label: t('adm.currency.all', undefined, locale) }, ...CURRENCIES.map((c) => ({ value: c, label: c }))]} testId="filter-currency" />
        </Toolbar>
        <DataTable columns={columns} rows={data?.items ?? []} keyOf={(x) => x.id} state={state} total={data?.total ?? 0} page={filter.page} pageSize={10} onPage={(page) => set({ page: String(page) })} empty={{ icon: 'settlements', title: t('adm.settlements.empty', undefined, locale) }} onRetry={() => { void reload() }} locale={locale} testId="settlements-table" caption={t('adm.settlements.title', undefined, locale)} />
      </Card>
      <DevNote>{t('adm.mockNote', undefined, locale)}</DevNote>
    </div>
  )
}

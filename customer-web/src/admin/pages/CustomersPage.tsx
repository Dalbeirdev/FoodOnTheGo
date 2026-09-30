import { useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { t } from '../../i18n/strings'
import { Avatar, Card, ErrorState, Icon, Skeleton, StatusBadge, ToastLine, useToastMessage } from '../../dashboard/components/ui'
import { useAdmin } from '../AdminContext'
import { BASE } from '../AdminLayout'
import { Badge, DataTable, Details, DevNote, ReasonDialog, Select, Toolbar, useUrlState, type Column } from '../components/DataTable'
import type { AdminCustomer, CustomerFilter, CustomerStatus } from '../types'
import { Cell, KNOWN_MARKET_CODES, StatusPill, Stars, fmtDate, fmtDateTime, marketOptions, money, useLoad, usePageTitle } from './shared'

const DEFAULTS = { q: '', status: 'all', market: 'all', page: '1' }
const STATUSES: CustomerStatus[] = ['ACTIVE', 'RESTRICTED', 'SUSPENDED', 'DEACTIVATED']

/** Customer Management (Module 18): minimal-PII list with status filters; details with orders, support, reviews, security. */
export default function CustomersPage() {
  const a = useAdmin(); const locale = a.locale; const nav = useNavigate(); usePageTitle('adm.nav.customers')
  const [s, set] = useUrlState(DEFAULTS)
  const filter = useMemo<CustomerFilter>(() => ({ query: s.q, status: s.status as CustomerFilter['status'], market: s.market, page: Number(s.page) || 1, pageSize: 10 }), [s])
  const { data, state, reload } = useLoad(() => a.repos.customers.list(filter), [a.repos, filter])
  const columns: Array<Column<AdminCustomer>> = [
    { id: 'name', label: t('adm.customers.col.customer', undefined, locale), primary: true, render: (c) => <Cell thumb={<Avatar name={c.name} size={36} />} primary={c.name} secondary={c.emailMasked ?? '—'} /> },
    { id: 'ref', label: t('adm.customers.col.ref', undefined, locale), render: (c) => <span className="adm-code">{c.publicRef}</span> },
    { id: 'phone', label: t('adm.customers.col.phone', undefined, locale), hideMobile: true, render: (c) => <span>{c.phoneMasked} {c.phoneVerified ? <Badge tone="green">{t('adm.customers.verified', undefined, locale)}</Badge> : <Badge tone="amber">{t('adm.customers.unverified', undefined, locale)}</Badge>}</span> },
    { id: 'status', label: t('adm.customers.col.status', undefined, locale), render: (c) => <StatusPill status={c.status} prefix="adm.customerStatus" dot /> },
    { id: 'orders', label: t('adm.customers.col.orders', undefined, locale), align: 'end', hideMobile: true, render: (c) => c.orders.toLocaleString(locale) },
    { id: 'market', label: t('adm.customers.col.market', undefined, locale), hideMobile: true, render: (c) => <Badge tone="muted">{c.market}</Badge> },
    { id: 'last', label: t('adm.customers.col.lastOrder', undefined, locale), hideMobile: true, render: (c) => fmtDate(c.lastOrderAt, locale) },
    { id: 'joined', label: t('adm.customers.col.joined', undefined, locale), hideMobile: true, render: (c) => fmtDate(c.createdAt, locale) },
  ]
  return (
    <div className="db-page" data-testid="adm-customers">
      <div className="db-page__head"><div><h1 className="db-page__title">{t('adm.customers.title', undefined, locale)}</h1><p className="db-page__lead">{t('adm.customers.lead', undefined, locale)}</p></div></div>
      <Card>
        <Toolbar search={s.q} onSearch={(q) => set({ q })} placeholder={t('adm.customers.search', undefined, locale)} locale={locale}>
          <Select label={t('adm.customers.col.status', undefined, locale)} value={s.status} onChange={(status) => set({ status })} options={[{ value: 'all', label: t('adm.status.all', undefined, locale) }, ...STATUSES.map((v) => ({ value: v, label: t(`adm.customerStatus.${v}`, undefined, locale) }))]} testId="filter-status" />
          <Select label={t('adm.customers.col.market', undefined, locale)} value={s.market} onChange={(market) => set({ market })} options={marketOptions(KNOWN_MARKET_CODES, locale)} testId="filter-market" />
        </Toolbar>
        <DataTable columns={columns} rows={data?.items ?? []} keyOf={(c) => c.id} state={state} total={data?.total ?? 0} page={filter.page!} pageSize={10} onPage={(page) => set({ page: String(page) })} empty={{ icon: 'customers', title: t('adm.customers.empty', undefined, locale) }} onRetry={() => { void reload() }} locale={locale} testId="customers-table" caption={t('adm.customers.title', undefined, locale)} onRowClick={(c) => nav(`${BASE}/customers/${c.id}`)} actions={(c) => <Link to={`${BASE}/customers/${c.id}`} className="db-btn db-btn--outline db-btn--sm">{t('adm.table.view', undefined, locale)}</Link>} />
      </Card>
      <DevNote>{t('adm.customer.piiNote', undefined, locale)}</DevNote>
    </div>
  )
}

export function CustomerDetailsPage() {
  const a = useAdmin(); const locale = a.locale; const { id = '' } = useParams(); usePageTitle('adm.nav.customers')
  const { data: c, state, reload, refresh } = useLoad(() => a.repos.customers.get(id), [a.repos, id])
  const [dialog, setDialog] = useState<CustomerStatus | 'reverify' | null>(null); const [busy, setBusy] = useState(false); const [err, setErr] = useState<string | null>(null); const { msg, toast } = useToastMessage()
  const run = async (fn: () => Promise<unknown>) => { setBusy(true); setErr(null); try { await fn(); await refresh(); setDialog(null); toast(t('adm.updated', undefined, locale)) } catch (e) { setErr((e as Error).message) } finally { setBusy(false) } }
  if (state === 'loading') return <div className="db-page"><Skeleton rows={6} /></div>
  if (state === 'error') return <div className="db-page"><ErrorState title={t('adm.error.loadTitle', undefined, locale)} onRetry={() => { void reload() }} locale={locale} /></div>
  if (!c) return <div className="db-page"><ErrorState title={t('adm.customer.notFound', undefined, locale)} locale={locale} /><Link to={`${BASE}/customers`} className="db-link">← {t('adm.nav.customers', undefined, locale)}</Link></div>
  const canManage = a.can('customers.manage')
  return (
    <div className="db-page" data-testid="adm-customer-details">
      <Link to={`${BASE}/customers`} className="db-link">← {t('adm.nav.customers', undefined, locale)}</Link>
      <div className="db-page__head"><div className="adm-cell"><Avatar name={c.name} size={56} /><div><h1 className="db-page__title" dir="auto">{c.name}</h1><p className="db-page__lead"><span className="adm-code">{c.publicRef}</span> · <StatusPill status={c.status} prefix="adm.customerStatus" dot />{c.reverificationRequired && <> · <Badge tone="amber">{t('adm.customers.reverify', undefined, locale)}</Badge></>}</p></div></div>
        {canManage && <div className="db-page__actions" data-testid="customer-actions">
          {c.status !== 'DEACTIVATED' && c.status !== 'ACTIVE' && <button type="button" className="db-btn db-btn--success" onClick={() => setDialog('ACTIVE')} data-testid="btn-activate">{t('adm.action.activate', undefined, locale)}</button>}
          {c.status === 'ACTIVE' && <button type="button" className="db-btn db-btn--outline" onClick={() => setDialog('RESTRICTED')} data-testid="btn-restrict">{t('adm.action.restrict', undefined, locale)}</button>}
          {c.status !== 'SUSPENDED' && c.status !== 'DEACTIVATED' && <button type="button" className="db-btn db-btn--danger" onClick={() => setDialog('SUSPENDED')} data-testid="btn-suspend">{t('adm.action.suspend', undefined, locale)}</button>}
          {c.status !== 'DEACTIVATED' && !c.reverificationRequired && <button type="button" className="db-btn db-btn--ghost" onClick={() => setDialog('reverify')}>{t('adm.action.reverify', undefined, locale)}</button>}
        </div>}
      </div>
      <div className="db-grid db-grid--2">
        <Card title={t('adm.customer.identity', undefined, locale)} subtitle={t('adm.customer.piiNote', undefined, locale)}>
          <Details rows={[[t('adm.customers.col.phone', undefined, locale), `${c.phoneMasked} · ${c.phoneVerified ? t('adm.customers.verified', undefined, locale) : t('adm.customers.unverified', undefined, locale)}`], ['Email', c.emailMasked ?? '—'], [t('adm.customers.col.market', undefined, locale), c.market], [t('adm.customers.col.joined', undefined, locale), fmtDate(c.createdAt, locale)], [t('adm.customers.col.orders', undefined, locale), c.orders.toLocaleString(locale)], [t('adm.customers.col.lastOrder', undefined, locale), fmtDate(c.lastOrderAt, locale)]]} />
        </Card>
        <Card title={t('adm.customer.security', undefined, locale)}>{c.securityEvents.length === 0 ? <p className="db-muted">{t('adm.customer.noSecurity', undefined, locale)}</p> : <ul className="db-list">{c.securityEvents.map((e) => <li key={e.id}><Icon name="shield" size={16} /> {e.detail} <small className="db-muted">{fmtDateTime(e.at, locale)}</small></li>)}</ul>}</Card>
      </div>
      <div className="db-grid db-grid--2">
        <Card title={t('adm.customer.recentOrders', undefined, locale)}>
          {c.recentOrders.length === 0 ? <p className="db-muted">{t('adm.customer.noOrders', undefined, locale)}</p> : <div className="db-table-wrap" tabIndex={0}><table className="db-table" data-testid="customer-orders"><caption className="db-sr-only">{t('adm.customer.recentOrders', undefined, locale)}</caption><thead><tr><th scope="col">{t('adm.orders.col.order', undefined, locale)}</th><th scope="col">{t('adm.orders.col.restaurant', undefined, locale)}</th><th scope="col">{t('adm.orders.col.status', undefined, locale)}</th><th scope="col" className="adm-td--end">{t('adm.orders.col.total', undefined, locale)}</th></tr></thead><tbody>{c.recentOrders.map((o) => <tr key={o.publicId}><td><Link to={`${BASE}/orders/${o.orderNumber}`} className="db-link">{o.orderNumber}</Link></td><td dir="auto">{o.restaurant.name}</td><td><StatusBadge status={o.orderStatus} locale={locale} /></td><td className="adm-td--end">{money(o.pricing.totalMinor, o.pricing.currency, locale)}</td></tr>)}</tbody></table></div>}
        </Card>
        <Card title={t('adm.customer.support', undefined, locale)}>
          {c.supportCases.length === 0 ? <p className="db-muted">{t('adm.customer.noSupport', undefined, locale)}</p> : <ul className="db-list">{c.supportCases.map((sc) => <li key={sc.id}><Link to={`${BASE}/support/${sc.id}`} className="db-link">{sc.ticketNumber}</Link> · {sc.summary} <StatusPill status={sc.status} prefix="adm.supportStatus" /></li>)}</ul>}
        </Card>
      </div>
      <Card title={t('adm.customer.reviews', undefined, locale)}>{c.reviews.length === 0 ? <p className="db-muted">{t('adm.customer.noReviews', undefined, locale)}</p> : <ul className="db-list">{c.reviews.map((r) => <li key={r.reviewId}><Stars rating={r.overallRating} /> <span dir="auto">{r.text || '—'}</span> <StatusPill status={r.status} prefix="adm.reviewStatus" /></li>)}</ul>}</Card>
      {err && <p className="db-field__error" role="alert">{t('adm.error.saveFailed', undefined, locale)} ({err})</p>}
      <ToastLine msg={msg} />
      <ReasonDialog open={dialog !== null && dialog !== 'reverify'} title={t('adm.customer.statusTitle', { name: c.name, status: dialog && dialog !== 'reverify' ? t(`adm.customerStatus.${dialog}`, undefined, locale) : '' }, locale)} text={t('adm.customer.statusText', undefined, locale)} confirmLabel={t('adm.action.save', undefined, locale)} danger={dialog === 'SUSPENDED' || dialog === 'DEACTIVATED'} busy={busy} locale={locale} onCancel={() => setDialog(null)} onConfirm={(reason) => { if (dialog && dialog !== 'reverify') void run(() => a.repos.customers.setStatus(c.id, dialog, a.admin.id, reason)) }} testId="customer-status-dialog" />
      <ReasonDialog open={dialog === 'reverify'} title={t('adm.customer.reverifyTitle', { name: c.name }, locale)} confirmLabel={t('adm.action.reverify', undefined, locale)} danger={false} requireReason={false} busy={busy} locale={locale} onCancel={() => setDialog(null)} onConfirm={() => { void run(() => a.repos.customers.requireReverification(c.id, a.admin.id)) }} />
    </div>
  )
}

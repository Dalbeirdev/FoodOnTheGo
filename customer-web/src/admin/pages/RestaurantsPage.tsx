import { useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { t } from '../../i18n/strings'
import { Card, ErrorState, Icon, Skeleton, Tabs, Thumb, ToastLine, useToastMessage } from '../../dashboard/components/ui'
import { useAdmin } from '../AdminContext'
import { BASE } from '../AdminLayout'
import { Badge, DataTable, Details, ReasonDialog, Select, Toolbar, useUrlState, type Column } from '../components/DataTable'
import type { AdminRestaurant, DocumentStatus, RejectionCategory, RestaurantFilter } from '../types'
import { MarketScopeChip, Cell, KNOWN_MARKET_CODES, Stars, StatusPill, fmtDate, fmtDateTime, marketOptions, useLoad, usePageTitle } from './shared'

const DEFAULTS = { tab: 'all', q: '', market: 'all', cuisine: 'all', sort: 'created', page: '1' }
const TABS: RestaurantFilter['tab'][] = ['all', 'pending', 'approved', 'suspended', 'rejected', 'inactive']

/** Restaurant Management (Module 18): platform-wide list with approval states, filters, sort, pagination and URL state. */
export default function RestaurantsPage() {
  const a = useAdmin(); const locale = a.locale; const nav = useNavigate(); usePageTitle('adm.nav.restaurants')
  const [s, set] = useUrlState(DEFAULTS)
  const filter = useMemo<RestaurantFilter>(() => ({ tab: (TABS.includes(s.tab as RestaurantFilter['tab']) ? s.tab : 'all') as RestaurantFilter['tab'], query: s.q, market: a.market === 'all' ? s.market : a.market, cuisine: s.cuisine, sort: s.sort as RestaurantFilter['sort'], page: Number(s.page) || 1, pageSize: 10 }), [s, a.market])
  const { data, state, reload } = useLoad(() => a.repos.restaurants.list(filter), [a.repos, filter])
  const cuisines = useMemo(() => a.repos.restaurants.cuisines(), [a.repos])
  const columns: Array<Column<AdminRestaurant>> = [
    { id: 'name', label: t('adm.restaurants.col.restaurant', undefined, locale), sortable: true, primary: true, render: (r) => <Cell thumb={<Thumb src={r.restaurant.image || null} name={r.restaurant.name} size={40} />} primary={r.restaurant.name} secondary={r.restaurant.cuisines.slice(0, 2).join(', ')} /> },
    { id: 'org', label: t('adm.restaurants.col.organization', undefined, locale), hideMobile: true, render: (r) => <span dir="auto">{r.organizationName}</span> },
    { id: 'locations', label: t('adm.restaurants.col.locations', undefined, locale), hideMobile: true, render: (r) => t('adm.restaurants.locations', { n: r.locationCount }, locale) },
    { id: 'market', label: t('adm.restaurants.col.market', undefined, locale), hideMobile: true, render: (r) => <span>{r.restaurant.address.locality ?? ''} <Badge tone="muted">{r.restaurant.countryCode}</Badge></span> },
    { id: 'status', label: t('adm.restaurants.col.status', undefined, locale), render: (r) => <StatusPill status={r.status} prefix="adm.restaurants.status" dot /> },
    { id: 'rating', label: t('adm.restaurants.col.rating', undefined, locale), sortable: true, hideMobile: true, render: (r) => (r.restaurant.rating ? <span><Stars rating={r.restaurant.rating} /> {r.restaurant.rating.toLocaleString(locale, { minimumFractionDigits: 1 })}</span> : '—') },
    { id: 'orders', label: t('adm.restaurants.col.orders', undefined, locale), sortable: true, align: 'end', hideMobile: true, render: (r) => r.ordersTotal.toLocaleString(locale) },
    { id: 'created', label: t('adm.restaurants.col.submitted', undefined, locale), sortable: true, hideMobile: true, render: (r) => fmtDate(r.createdAt, locale) },
  ]
  return (
    <div className="db-page" data-testid="adm-restaurants">
      <div className="db-page__head"><div><h1 className="db-page__title">{t('adm.restaurants.title', undefined, locale)}</h1><p className="db-page__lead">{t('adm.restaurants.lead', undefined, locale)}</p></div></div>
      <Tabs tabs={TABS.map((id) => ({ id, label: t(`adm.restaurants.tab.${id}`, undefined, locale) }))} value={filter.tab} onChange={(tab) => set({ tab })} label={t('adm.restaurants.tabs', undefined, locale)} />
      <Card>
        <Toolbar search={s.q} onSearch={(q) => set({ q })} placeholder={t('adm.restaurants.search', undefined, locale)} locale={locale}>
          {a.market === 'all' ? <Select label={t('adm.restaurants.col.market', undefined, locale)} value={s.market} onChange={(market) => set({ market })} options={marketOptions(KNOWN_MARKET_CODES, locale)} testId="filter-market" /> : <MarketScopeChip />}
          <Select label={t('adm.restaurants.cuisine.all', undefined, locale)} value={s.cuisine} onChange={(cuisine) => set({ cuisine })} options={[{ value: 'all', label: t('adm.restaurants.cuisine.all', undefined, locale) }, ...cuisines.map((c) => ({ value: c, label: c }))]} testId="filter-cuisine" />
          <Select label={t('adm.restaurants.sort', undefined, locale)} value={s.sort} onChange={(sort) => set({ sort })} options={['created', 'name', 'orders', 'rating'].map((v) => ({ value: v, label: t(`adm.restaurants.sort.${v}`, undefined, locale) }))} testId="filter-sort" />
        </Toolbar>
        <DataTable columns={columns} rows={data?.items ?? []} keyOf={(r) => r.restaurant.id} state={state} total={data?.total ?? 0} page={filter.page!} pageSize={10} onPage={(page) => set({ page: String(page) })} sort={s.sort} onSort={(sort) => set({ sort })} empty={{ icon: 'restaurants', title: t('adm.restaurants.empty', undefined, locale) }} onRetry={() => { void reload() }} locale={locale} testId="restaurants-table" caption={t('adm.restaurants.title', undefined, locale)} onRowClick={(r) => nav(`${BASE}/restaurants/${r.restaurant.id}`)} actions={(r) => <Link to={`${BASE}/restaurants/${r.restaurant.id}`} className="db-btn db-btn--outline db-btn--sm">{['SUBMITTED', 'UNDER_REVIEW'].includes(r.status) ? t('adm.action.review', undefined, locale) : t('adm.table.view', undefined, locale)}</Link>} />
      </Card>
    </div>
  )
}

type Dialog = null | 'approve' | 'reject' | 'requestInfo' | 'suspend' | 'reactivate' | { doc: string } | { location: string; to: 'ACTIVE' | 'SUSPENDED' }
/** Restaurant details + approval workflow (approve / reject with structured reason / request info / suspend / reactivate). */
export function RestaurantDetailsPage() {
  const a = useAdmin(); const locale = a.locale; const { id = '' } = useParams(); usePageTitle('adm.nav.restaurants')
  const { data: r, state, reload, setData } = useLoad(() => a.repos.restaurants.get(id), [a.repos, id])
  const [tab, setTab] = useState<'overview' | 'documents' | 'locations' | 'notes' | 'history'>('overview')
  const [dialog, setDialog] = useState<Dialog>(null); const [busy, setBusy] = useState(false); const [err, setErr] = useState<string | null>(null); const { msg, toast } = useToastMessage()
  const [publicReason, setPublicReason] = useState(''); const [docStatus, setDocStatus] = useState<DocumentStatus>('APPROVED'); const [override, setOverride] = useState(false)
  const [noteText, setNoteText] = useState('')
  const { data: audit } = useLoad(() => a.repos.audit.list({ query: id, pageSize: 20 }), [a.repos, id, r?.status])
  const run = async (fn: () => Promise<AdminRestaurant>, okKey = 'adm.updated') => { setBusy(true); setErr(null); try { setData(await fn()); setDialog(null); toast(t(okKey, undefined, locale)) } catch (e) { setErr((e as Error).message) } finally { setBusy(false) } }
  if (state === 'loading') return <div className="db-page"><Skeleton rows={6} /></div>
  if (state === 'error') return <div className="db-page"><ErrorState title={t('adm.error.loadTitle', undefined, locale)} onRetry={() => { void reload() }} locale={locale} /></div>
  if (!r) return <div className="db-page"><ErrorState title={t('adm.restaurant.notFound', undefined, locale)} locale={locale} /><Link to={`${BASE}/restaurants`} className="db-link">← {t('adm.nav.restaurants', undefined, locale)}</Link></div>
  const rest = r.restaurant; const pending = r.status === 'SUBMITTED' || r.status === 'UNDER_REVIEW'
  const missingDocs = r.documents.filter((d) => d.required && d.status !== 'APPROVED').length
  const canApprove = a.can('restaurants.approve'), canSuspend = a.can('restaurants.suspend')
  return (
    <div className="db-page" data-testid="adm-restaurant-details">
      <Link to={`${BASE}/restaurants`} className="db-link">← {t('adm.nav.restaurants', undefined, locale)}</Link>
      <div className="db-page__head"><div className="adm-cell"><Thumb src={rest.image || null} name={rest.name} size={56} /><div><h1 className="db-page__title" dir="auto">{rest.name}</h1><p className="db-page__lead" dir="auto">{r.organizationName} · {rest.address.locality}, {rest.countryCode} · <StatusPill status={r.status} prefix="adm.restaurants.status" dot /></p></div></div>
        <div className="db-page__actions" data-testid="restaurant-actions">
          {pending && canApprove && <><button type="button" className="db-btn db-btn--outline" onClick={() => setDialog('requestInfo')}>{t('adm.action.requestInfo', undefined, locale)}</button><button type="button" className="db-btn db-btn--danger" onClick={() => setDialog('reject')} data-testid="btn-reject">{t('adm.action.reject', undefined, locale)}</button><button type="button" className="db-btn db-btn--success" onClick={() => setDialog('approve')} data-testid="btn-approve">{t('adm.action.approve', undefined, locale)}</button></>}
          {r.status === 'REJECTED' && canApprove && <button type="button" className="db-btn db-btn--success" onClick={() => setDialog('approve')} data-testid="btn-approve">{t('adm.action.approve', undefined, locale)}</button>}
          {r.status === 'APPROVED' && canSuspend && <button type="button" className="db-btn db-btn--danger" onClick={() => setDialog('suspend')} data-testid="btn-suspend">{t('adm.action.suspend', undefined, locale)}</button>}
          {r.status === 'SUSPENDED' && canSuspend && <button type="button" className="db-btn db-btn--success" onClick={() => setDialog('reactivate')} data-testid="btn-reactivate">{t('adm.action.reactivate', undefined, locale)}</button>}
        </div>
      </div>
      {pending && <p className="adm-critical-strip" style={{ background: 'var(--db-amber-bg)', color: '#8a3b00', borderColor: '#f5c58a' }}><Icon name="timer" size={16} />{t('adm.restaurant.approvalNeeded', undefined, locale)} <span style={{ marginInlineStart: 'auto' }}>{t('adm.restaurant.docsComplete', { approved: r.documents.filter((d) => d.status === 'APPROVED').length, total: r.documents.length }, locale)}</span></p>}
      <Tabs tabs={(['overview', 'documents', 'locations', 'notes', 'history'] as const).map((id) => ({ id, label: t(`adm.restaurant.tab.${id}`, undefined, locale), count: id === 'documents' ? missingDocs : id === 'locations' ? r.locations.length : id === 'notes' ? r.internalNotes.length : undefined }))} value={tab} onChange={setTab} label={t('adm.restaurant.tabs', undefined, locale)} />
      {tab === 'overview' && <div className="db-grid db-grid--2">
        <Card title={t('adm.restaurant.profile', undefined, locale)}>
          <Details rows={[[t('adm.restaurant.organization', undefined, locale), r.organizationName], [t('adm.restaurant.market', undefined, locale), `${rest.market} (${rest.countryCode})`], [t('adm.restaurant.currency', undefined, locale), rest.currency], [t('adm.restaurant.timezone', undefined, locale), rest.timezone], [t('adm.restaurant.cuisines', undefined, locale), rest.cuisines.join(', ')], [t('adm.restaurant.address', undefined, locale), rest.address.formatted], [t('adm.restaurant.created', undefined, locale), fmtDate(r.createdAt, locale)], [t('adm.restaurant.ordersTotal', undefined, locale), r.ordersTotal.toLocaleString(locale)], [t('adm.restaurant.rating', undefined, locale), rest.rating ? `${rest.rating.toLocaleString(locale, { minimumFractionDigits: 1 })} (${rest.reviewCount})` : '—'], [t('adm.restaurant.accepting', undefined, locale), rest.acceptingOrders ? t('dash.header.accepting', undefined, locale) : t('dash.header.notAccepting', undefined, locale)]]} />
          <p style={{ marginTop: 12, display: 'flex', gap: 14, flexWrap: 'wrap' }}>{r.status === 'APPROVED' && <Link to={`/restaurants/${rest.slug}`} className="db-link" target="_blank" rel="noreferrer">{t('adm.restaurant.customerLink', undefined, locale)} <Icon name="external" size={14} /></Link>}<Link to="/restaurant-dashboard/overview" className="db-link" target="_blank" rel="noreferrer">{t('adm.restaurant.dashboardLink', undefined, locale)} <Icon name="external" size={14} /></Link></p>
        </Card>
        <Card title={t('adm.restaurant.tab.documents', undefined, locale)} subtitle={t('adm.restaurant.docsComplete', { approved: r.documents.filter((d) => d.status === 'APPROVED').length, total: r.documents.length }, locale)}>
          <div className="adm-doc-list">{r.documents.map((d) => <div key={d.id} className="adm-doc"><span className="adm-doc__meta"><b>{d.label}</b><small>{d.status === 'NOT_SUBMITTED' ? '' : t('adm.doc.submitted', { date: fmtDate(d.submittedAt, locale) }, locale)}</small></span><StatusPill status={d.status} prefix="adm.doc" /></div>)}</div>
        </Card>
      </div>}
      {tab === 'documents' && <Card title={t('adm.restaurant.tab.documents', undefined, locale)} subtitle={t('adm.restaurant.documentsLead', { market: rest.countryCode }, locale)}>
        <div className="adm-doc-list" data-testid="documents">{r.documents.map((d) => <div key={d.id} className="adm-doc"><span className="adm-doc__meta"><b>{d.label}</b><small>{d.status === 'NOT_SUBMITTED' ? t('adm.doc.NOT_SUBMITTED', undefined, locale) : t('adm.doc.submitted', { date: fmtDate(d.submittedAt, locale) }, locale)}{d.expiresAt && ` · ${t('adm.doc.expires', { date: fmtDate(d.expiresAt, locale) }, locale)}`}{d.note && ` · ${d.note}`}</small></span><StatusPill status={d.status} prefix="adm.doc" />{canApprove && d.status !== 'NOT_SUBMITTED' ? <button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => { setDocStatus(d.status === 'APPROVED' ? 'REJECTED' : 'APPROVED'); setDialog({ doc: d.id }) }}>{t('adm.doc.setStatus', undefined, locale)}</button> : <span />}</div>)}</div>
      </Card>}
      {tab === 'locations' && <Card title={t('adm.restaurant.tab.locations', undefined, locale)} subtitle={t('adm.restaurant.locationsLead', { org: r.organizationName }, locale)}>
        <div className="db-table-wrap" tabIndex={0}><table className="db-table" data-testid="locations"><caption className="db-sr-only">{t('adm.restaurant.tab.locations', undefined, locale)}</caption><thead><tr><th scope="col">{t('adm.restaurants.col.restaurant', undefined, locale)}</th><th scope="col">{t('adm.restaurant.timezone', undefined, locale)}</th><th scope="col">{t('adm.restaurant.currency', undefined, locale)}</th><th scope="col">{t('adm.restaurants.col.status', undefined, locale)}</th><th scope="col" className="adm-td--end">{t('adm.table.actions', undefined, locale)}</th></tr></thead>
          <tbody>{r.locations.map((l) => <tr key={l.restaurantId}><td><Cell primary={l.name} secondary={l.locationName} /></td><td>{l.timezone}</td><td>{l.currency}</td><td><StatusPill status={l.status} prefix="adm.restaurant.locStatus" dot /></td><td className="adm-td--end"><div className="db-table__actions">{l.restaurantId !== rest.id && <Link to={`${BASE}/restaurants/${l.restaurantId}`} className="db-btn db-btn--ghost db-btn--sm">{t('adm.table.view', undefined, locale)}</Link>}{canSuspend && r.status === 'APPROVED' && (l.status === 'ACTIVE' ? <button type="button" className="db-btn db-btn--danger db-btn--sm" onClick={() => setDialog({ location: l.restaurantId, to: 'SUSPENDED' })}>{t('adm.restaurant.suspendLocation', undefined, locale)}</button> : <button type="button" className="db-btn db-btn--success db-btn--sm" onClick={() => setDialog({ location: l.restaurantId, to: 'ACTIVE' })}>{t('adm.restaurant.reactivateLocation', undefined, locale)}</button>)}</div></td></tr>)}</tbody></table></div>
      </Card>}
      {tab === 'notes' && <Card title={t('adm.restaurant.tab.notes', undefined, locale)} subtitle={t('adm.restaurant.notesNote', undefined, locale)}>
        {r.internalNotes.length === 0 ? <p className="db-muted">{t('adm.restaurant.notesEmpty', undefined, locale)}</p> : [...r.internalNotes].reverse().map((n, i) => <div key={i} className="adm-note"><small>{fmtDateTime(n.at, locale)} · {n.by}</small>{n.text}</div>)}
        {canApprove && <form className="adm-inline-form" onSubmit={(e) => { e.preventDefault(); if (!noteText.trim()) return; void run(() => a.repos.restaurants.requestInformation(rest.id, a.admin.id, noteText).then((x) => { setNoteText(''); return x })) }}><div className="db-field"><label className="db-field__label" htmlFor="rnote">{t('adm.action.addNote', undefined, locale)}</label><input id="rnote" className="db-input" value={noteText} onChange={(e) => setNoteText(e.target.value)} /></div><button type="submit" className="db-btn db-btn--outline" disabled={busy}>{t('adm.action.addNote', undefined, locale)}</button></form>}
      </Card>}
      {tab === 'history' && <Card title={t('adm.restaurant.tab.history', undefined, locale)}>
        {!audit || audit.items.length === 0 ? <p className="db-muted">{t('adm.audit.empty', undefined, locale)}</p> : <ol className="db-timeline" data-testid="restaurant-history">{audit.items.map((e) => <li key={e.id}><span className="db-timeline__dot" /><span><b>{e.description}</b><small className="db-muted"> · {e.actor} · {fmtDateTime(e.at, locale)}{e.reason ? ` · ${e.reason}` : ''}</small></span></li>)}</ol>}
      </Card>}
      {err && <p className="db-field__error" role="alert">{t('adm.error.saveFailed', undefined, locale)} ({err})</p>}
      <ToastLine msg={msg} />
      <ReasonDialog open={dialog === 'approve'} title={t('adm.approve.title', { name: rest.name }, locale)} text={t('adm.approve.text', undefined, locale)} confirmLabel={t('adm.action.approve', undefined, locale)} danger={false} requireReason={false} busy={busy} locale={locale} onCancel={() => setDialog(null)} testId="approve-dialog"
        impact={missingDocs > 0 ? [t('adm.approve.blocked', { n: missingDocs }, locale)] : undefined}
        extraFields={missingDocs > 0 ? <label className="db-field" style={{ display: 'flex', gap: 8, alignItems: 'center' }}><input type="checkbox" checked={override} onChange={(e) => setOverride(e.target.checked)} data-testid="approve-override" /> {t('adm.approve.override', undefined, locale)}</label> : undefined}
        onConfirm={() => { if (missingDocs > 0 && !override) { setErr('documents_incomplete'); return } void run(() => a.repos.restaurants.approve(rest.id, a.admin.id)) }} />
      <ReasonDialog open={dialog === 'reject'} title={t('adm.reject.title', { name: rest.name }, locale)} text={t('adm.reject.text', undefined, locale)} confirmLabel={t('adm.action.reject', undefined, locale)} busy={busy} locale={locale} onCancel={() => setDialog(null)} testId="reject-dialog"
        categories={(['incomplete_documents', 'invalid_business', 'duplicate', 'policy', 'other'] as RejectionCategory[]).map((c) => ({ value: c, label: t(`adm.reject.cat.${c}`, undefined, locale) }))} categoryLabel={t('adm.reject.category', undefined, locale)} reasonLabel={t('adm.reject.internal', undefined, locale)}
        extraFields={<div className="db-field"><label className="db-field__label" htmlFor="pubreason">{t('adm.reject.public', undefined, locale)}</label><textarea id="pubreason" className="db-textarea" rows={2} value={publicReason} onChange={(e) => setPublicReason(e.target.value)} data-testid="reject-public" /></div>}
        onConfirm={(internal, category) => { if (!publicReason.trim()) { setErr('reason_required'); return } void run(() => a.repos.restaurants.reject(rest.id, a.admin.id, category as RejectionCategory, publicReason, internal)) }} />
      <ReasonDialog open={dialog === 'requestInfo'} title={t('adm.requestInfo.title', { name: rest.name }, locale)} text={t('adm.requestInfo.text', undefined, locale)} confirmLabel={t('adm.action.requestInfo', undefined, locale)} danger={false} busy={busy} locale={locale} onCancel={() => setDialog(null)} onConfirm={(m) => { void run(() => a.repos.restaurants.requestInformation(rest.id, a.admin.id, m)) }} />
      <ReasonDialog open={dialog === 'suspend'} title={t('adm.suspend.title', { name: rest.name }, locale)} text={t('adm.suspend.text', undefined, locale)} confirmLabel={t('adm.action.suspend', undefined, locale)} busy={busy} locale={locale} onCancel={() => setDialog(null)} impact={[t('adm.suspend.impact1', undefined, locale), t('adm.suspend.impact2', undefined, locale), t('adm.suspend.impact3', undefined, locale)]} onConfirm={(reason) => { void run(() => a.repos.restaurants.suspend(rest.id, a.admin.id, reason)) }} testId="suspend-dialog" />
      <ReasonDialog open={dialog === 'reactivate'} title={t('adm.reactivate.title', { name: rest.name }, locale)} text={t('adm.reactivate.text', undefined, locale)} confirmLabel={t('adm.action.reactivate', undefined, locale)} danger={false} requireReason={false} busy={busy} locale={locale} onCancel={() => setDialog(null)} onConfirm={() => { void run(() => a.repos.restaurants.reactivate(rest.id, a.admin.id)) }} />
      <ReasonDialog open={!!dialog && typeof dialog === 'object' && 'doc' in dialog} title={t('adm.doc.reviewTitle', { doc: r.documents.find((d) => typeof dialog === 'object' && dialog && 'doc' in dialog && d.id === dialog.doc)?.label ?? '' }, locale)} confirmLabel={t('adm.action.save', undefined, locale)} danger={false} requireReason={false} busy={busy} locale={locale} onCancel={() => setDialog(null)}
        extraFields={<div className="db-field"><label className="db-field__label" htmlFor="docstatus">{t('adm.doc.setStatus', undefined, locale)}</label><select id="docstatus" className="db-select" value={docStatus} onChange={(e) => setDocStatus(e.target.value as DocumentStatus)} data-testid="doc-status">{(['SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'REJECTED', 'EXPIRED'] as DocumentStatus[]).map((st) => <option key={st} value={st}>{t(`adm.doc.${st}`, undefined, locale)}</option>)}</select></div>}
        onConfirm={(note) => { if (typeof dialog === 'object' && dialog && 'doc' in dialog) void run(() => a.repos.restaurants.setDocumentStatus(rest.id, dialog.doc, docStatus, a.admin.id, note)) }} />
      <ReasonDialog open={!!dialog && typeof dialog === 'object' && 'location' in dialog} title={typeof dialog === 'object' && dialog && 'location' in dialog && dialog.to === 'SUSPENDED' ? t('adm.restaurant.suspendLocation', undefined, locale) : t('adm.restaurant.reactivateLocation', undefined, locale)} confirmLabel={t('adm.action.save', undefined, locale)} busy={busy} locale={locale} onCancel={() => setDialog(null)} onConfirm={(reason) => { if (typeof dialog === 'object' && dialog && 'location' in dialog) void run(() => a.repos.restaurants.setLocationStatus(rest.id, dialog.location, dialog.to, a.admin.id, reason)) }} />
    </div>
  )
}

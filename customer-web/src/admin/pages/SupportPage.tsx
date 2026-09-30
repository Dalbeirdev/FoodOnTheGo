import { useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { t } from '../../i18n/strings'
import { Card, ErrorState, Skeleton, Tabs, ToastLine, useToastMessage } from '../../dashboard/components/ui'
import { useAdmin } from '../AdminContext'
import { BASE } from '../AdminLayout'
import { Badge, DataTable, Details, DevNote, Select, Toolbar, useUrlState, type Column } from '../components/DataTable'
import type { SupportCase, SupportFilter, SupportPriority, SupportStatus } from '../types'
import { Cell, StatusPill, fmtDateTime, useLoad, usePageTitle } from './shared'

const DEFAULTS = { tab: 'open', q: '', priority: 'all', page: '1' }
const TABS: SupportFilter['tab'][] = ['open', 'in_progress', 'waiting', 'resolved', 'all']
const PRIORITIES: SupportPriority[] = ['URGENT', 'HIGH', 'MEDIUM', 'LOW']
const STATUSES: SupportStatus[] = ['OPEN', 'IN_PROGRESS', 'WAITING_CUSTOMER', 'WAITING_RESTAURANT', 'RESOLVED', 'CLOSED']

/** Support Management (Module 18): queues, assignment, priority, replies and internal notes (never public). */
export default function SupportPage() {
  const a = useAdmin(); const locale = a.locale; const nav = useNavigate(); usePageTitle('adm.nav.support')
  const [s, set] = useUrlState(DEFAULTS)
  const filter = useMemo<SupportFilter>(() => ({ tab: (TABS.includes(s.tab as SupportFilter['tab']) ? s.tab : 'open') as SupportFilter['tab'], query: s.q, priority: s.priority as SupportFilter['priority'], page: Number(s.page) || 1, pageSize: 10 }), [s])
  const { data, state, reload } = useLoad(() => a.repos.support.list(filter), [a.repos, filter])
  const assignee = (id: string | null) => (id ? a.admins.find((u) => u.id === id)?.name ?? id : t('adm.support.unassigned', undefined, locale))
  const columns: Array<Column<SupportCase>> = [
    { id: 'ticket', label: t('adm.support.col.ticket', undefined, locale), primary: true, render: (c) => <Cell primary={c.ticketNumber} secondary={c.summary} /> },
    { id: 'type', label: t('adm.support.col.type', undefined, locale), hideMobile: true, render: (c) => c.type },
    { id: 'requester', label: t('adm.support.col.requester', undefined, locale), hideMobile: true, render: (c) => <span dir="auto">{c.requester} <Badge tone="muted">{t(`adm.support.source.${c.source}`, undefined, locale)}</Badge></span> },
    { id: 'priority', label: t('adm.support.col.priority', undefined, locale), render: (c) => <StatusPill status={c.priority} prefix="adm.priority" dot /> },
    { id: 'status', label: t('adm.support.col.status', undefined, locale), render: (c) => <StatusPill status={c.status} prefix="adm.supportStatus" /> },
    { id: 'assignee', label: t('adm.support.col.assignee', undefined, locale), hideMobile: true, render: (c) => assignee(c.assignedTo) },
    { id: 'updated', label: t('adm.support.col.updated', undefined, locale), hideMobile: true, render: (c) => fmtDateTime(c.updatedAt, locale) },
  ]
  return (
    <div className="db-page" data-testid="adm-support">
      <div className="db-page__head"><div><h1 className="db-page__title">{t('adm.support.title', undefined, locale)}</h1><p className="db-page__lead">{t('adm.support.lead', undefined, locale)}</p></div></div>
      <Tabs tabs={TABS.map((id) => ({ id, label: t(`adm.support.tab.${id}`, undefined, locale), count: data?.counts[id] }))} value={filter.tab} onChange={(tab) => set({ tab })} label={t('adm.support.tabs', undefined, locale)} />
      <Card>
        <Toolbar search={s.q} onSearch={(q) => set({ q })} placeholder={t('adm.support.search', undefined, locale)} locale={locale}><Select label={t('adm.support.col.priority', undefined, locale)} value={s.priority} onChange={(priority) => set({ priority })} options={[{ value: 'all', label: t('adm.support.priority.all', undefined, locale) }, ...PRIORITIES.map((p) => ({ value: p, label: t(`adm.priority.${p}`, undefined, locale) }))]} testId="filter-priority" /></Toolbar>
        <DataTable columns={columns} rows={data?.items ?? []} keyOf={(c) => c.id} state={state} total={data?.total ?? 0} page={filter.page!} pageSize={10} onPage={(page) => set({ page: String(page) })} empty={{ icon: 'support', title: t('adm.support.empty', undefined, locale) }} onRetry={() => { void reload() }} locale={locale} testId="support-table" caption={t('adm.support.title', undefined, locale)} onRowClick={(c) => nav(`${BASE}/support/${c.id}`)} actions={(c) => <Link to={`${BASE}/support/${c.id}`} className="db-btn db-btn--outline db-btn--sm">{t('adm.table.view', undefined, locale)}</Link>} />
      </Card>
    </div>
  )
}

export function SupportCasePage() {
  const a = useAdmin(); const locale = a.locale; const { id = '' } = useParams(); usePageTitle('adm.nav.support')
  const { data: c, state, reload, setData } = useLoad(() => a.repos.support.get(id), [a.repos, id])
  const [text, setText] = useState(''); const [internal, setInternal] = useState(false); const [busy, setBusy] = useState(false); const { msg, toast } = useToastMessage()
  const canManage = a.can('support.manage')
  const run = async (fn: () => Promise<SupportCase>) => { setBusy(true); try { setData(await fn()); toast(t('adm.updated', undefined, locale)) } finally { setBusy(false) } }
  if (state === 'loading') return <div className="db-page"><Skeleton rows={6} /></div>
  if (state === 'error') return <div className="db-page"><ErrorState title={t('adm.error.loadTitle', undefined, locale)} onRetry={() => { void reload() }} locale={locale} /></div>
  if (!c) return <div className="db-page"><ErrorState title={t('adm.support.notFound', undefined, locale)} locale={locale} /><Link to={`${BASE}/support`} className="db-link">← {t('adm.nav.support', undefined, locale)}</Link></div>
  return (
    <div className="db-page" data-testid="adm-support-case">
      <Link to={`${BASE}/support`} className="db-link">← {t('adm.nav.support', undefined, locale)}</Link>
      <div className="db-page__head"><div><h1 className="db-page__title">{c.ticketNumber} · {c.type}</h1><p className="db-page__lead"><StatusPill status={c.status} prefix="adm.supportStatus" /> <StatusPill status={c.priority} prefix="adm.priority" dot /> · <span dir="auto">{c.requester}</span> · {fmtDateTime(c.createdAt, locale)}</p></div></div>
      <div className="adm-grid--21">
        <div className="db-grid" style={{ gap: 16 }}>
          <Card title={t('adm.support.messages', undefined, locale)}>
            <p dir="auto" className="adm-note"><small>{t('adm.support.source.' + c.source, undefined, locale)}</small>{c.summary}</p>
            {c.messages.length === 0 ? <p className="db-muted">{t('adm.support.noMessages', undefined, locale)}</p> : <div data-testid="support-messages">{c.messages.map((m) => <div key={m.id} className={`adm-msg ${m.authorType === 'admin' ? 'adm-msg--admin' : ''} ${m.internal ? 'adm-msg--internal' : ''}`}><div className="adm-msg__meta"><b>{m.author}</b><span>{t(`adm.support.source.${m.authorType}`, undefined, locale)}</span><span>{fmtDateTime(m.at, locale)}</span>{m.internal && <span className="adm-msg__internal">{t('adm.support.internalBadge', undefined, locale)}</span>}</div><p dir="auto" style={{ margin: 0 }}>{m.text}</p></div>)}</div>}
            {canManage && <form onSubmit={(e) => { e.preventDefault(); if (!text.trim()) return; void run(() => a.repos.support.addMessage(c.id, a.admin.id, text, internal)).then(() => setText('')) }} className="db-grid" style={{ gap: 8, marginTop: 12 }}>
              <div className="db-field"><label className="db-field__label" htmlFor="s-msg">{internal ? t('adm.support.internalNote', undefined, locale) : t('adm.support.reply', undefined, locale)}</label><textarea id="s-msg" className="db-textarea" rows={3} value={text} onChange={(e) => setText(e.target.value)} data-testid="support-text" /></div>
              <div className="db-table__actions" style={{ alignItems: 'center' }}><label style={{ display: 'flex', gap: 6, alignItems: 'center' }}><input type="checkbox" checked={internal} onChange={(e) => setInternal(e.target.checked)} data-testid="support-internal" /> {t('adm.support.internalNote', undefined, locale)}</label><small className="db-muted">{t('adm.support.internalHint', undefined, locale)}</small><button type="submit" className="db-btn db-btn--primary db-btn--sm" disabled={busy} data-testid="support-send">{internal ? t('adm.support.addNote', undefined, locale) : t('adm.support.send', undefined, locale)}</button></div>
            </form>}
          </Card>
        </div>
        <div className="db-grid" style={{ gap: 16 }}>
          <Card title={t('adm.support.linked', undefined, locale)}><Details rows={[[t('adm.support.order', undefined, locale), c.orderNumber ? <Link key="o" to={`${BASE}/orders/${c.orderNumber}`} className="db-link">{c.orderNumber}</Link> : '—'], [t('adm.support.restaurant', undefined, locale), c.restaurantId ? <Link key="r" to={`${BASE}/restaurants/${c.restaurantId}`} className="db-link">{c.restaurantId}</Link> : '—'], [t('adm.support.customer', undefined, locale), c.customerRef ?? '—'], [t('adm.customers.col.market', undefined, locale), c.market]]} /></Card>
          {canManage && <Card title={t('adm.table.actions', undefined, locale)}>
            <div className="db-grid" style={{ gap: 10 }}>
              <div className="db-field"><label className="db-field__label" htmlFor="s-assign">{t('adm.support.assign', undefined, locale)}</label><select id="s-assign" className="db-select" value={c.assignedTo ?? ''} onChange={(e) => { void run(() => a.repos.support.assign(c.id, e.target.value || null, a.admin.id)) }} disabled={busy} data-testid="support-assign"><option value="">{t('adm.support.unassigned', undefined, locale)}</option>{a.admins.filter((u) => u.status === 'ACTIVE').map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select></div>
              <div className="db-field"><label className="db-field__label" htmlFor="s-status">{t('adm.support.status', undefined, locale)}</label><select id="s-status" className="db-select" value={c.status} onChange={(e) => { void run(() => a.repos.support.setStatus(c.id, e.target.value as SupportStatus, a.admin.id)) }} disabled={busy} data-testid="support-status">{STATUSES.map((st) => <option key={st} value={st}>{t(`adm.supportStatus.${st}`, undefined, locale)}</option>)}</select></div>
              <div className="db-field"><label className="db-field__label" htmlFor="s-prio">{t('adm.support.priority', undefined, locale)}</label><select id="s-prio" className="db-select" value={c.priority} onChange={(e) => { void run(() => a.repos.support.setPriority(c.id, e.target.value as SupportPriority, a.admin.id)) }} disabled={busy} data-testid="support-priority">{PRIORITIES.map((p) => <option key={p} value={p}>{t(`adm.priority.${p}`, undefined, locale)}</option>)}</select></div>
            </div>
          </Card>}
          <DevNote>{t('adm.support.internalHint', undefined, locale)}</DevNote>
        </div>
      </div>
      <ToastLine msg={msg} />
    </div>
  )
}

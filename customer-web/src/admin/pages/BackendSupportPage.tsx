import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { Card, ErrorState, Icon } from '../../dashboard/components/ui'
import { t } from '../../i18n/strings'
import { useAdmin } from '../AdminContext'
import { BASE } from '../AdminLayout'
import { ApiAdminSupportRepository, SUPPORT_PRIORITIES, SUPPORT_QUEUES, SUPPORT_REQUESTERS, SUPPORT_SLA_STATES, SUPPORT_TEAMS, type SupportCaseRow, type SupportListFilter, type SupportQueue } from '../api/ApiAdminSupportRepository'
import { Badge, DataTable, DevNote, Select, Stat, useUrlState, type Column } from '../components/DataTable'
import { Cell, fmtDateTime, usePageTitle } from './shared'
import { NewCaseDialog } from './support/NewCaseDialog'
import { PriorityBadge, SlaBadge, StatusBadge, age, deskLabel, useDesk } from './support/desk'

/**
 * The service desk on the backend (Module 37): the queue of support cases — no development rows.
 *
 * The queues and their counts, the filters, the order (most urgent first, then the case that has waited longest) and
 * the search are the backend's. The search takes EXACT references only — a case number, an order number, a payment /
 * refund / settlement / payout reference, a record id — or a restaurant's name; it never looks a customer up by name,
 * phone or e-mail, and the placeholder says so. A restricted case (finance / security) is in the list only for an
 * administrator who may open it. The SLA column is an INTERNAL target, never a commitment to the requester.
 */
const DEFAULTS = { queue: 'open', q: '', priority: 'all', category: 'all', team: 'all', requester: 'all', market: 'all', sla: 'all', page: '1' }
const PAGE_SIZE = 20

export function BackendSupportPage({ repository }: { repository?: ApiAdminSupportRepository }) {
  const a = useAdmin(); const locale = a.locale; const nav = useNavigate(); const loc = useLocation(); usePageTitle('adm.nav.support')
  const repo = useMemo(() => repository ?? new ApiAdminSupportRepository(), [repository])
  const [s, set] = useUrlState(DEFAULTS)
  const queue = (s.queue === 'all' || SUPPORT_QUEUES.includes(s.queue as SupportQueue) ? s.queue : 'open') as SupportQueue | 'all'
  const filter = useMemo<SupportListFilter>(() => ({ query: s.q, queue, priority: s.priority, category: s.category, team: s.team, requester: s.requester, market: s.market, sla: s.sla, page: Number(s.page) || 1, pageSize: PAGE_SIZE }), [s, queue])
  const list = useDesk(() => repo.list(filter), [repo, filter])
  const queues = useDesk(() => repo.queues(), [repo])
  const metrics = useDesk(() => repo.metrics(), [repo])
  const catalog = useDesk(() => repo.catalog(), [repo])
  const [creating, setCreating] = useState(false)
  const notice = (loc.state as { deskNotice?: string } | null)?.deskNotice ?? null
  // The markets the administrator can choose between; the filter is offered only when there is a choice.
  const markets = useMemo(() => a.markets.filter((m) => m.status !== 'DRAFT' && m.status !== 'CLOSED').map((m) => ({ slug: m.slug, name: m.displayName })), [a.markets])

  // The search box applies after a short pause, or at once on Enter. The pending pause always uses the LATEST url state
  // (a filter chosen while it runs is kept), and the box follows the URL only when the URL was changed from elsewhere.
  const [search, setSearch] = useState(s.q)
  const latest = useRef(set); latest.current = set
  const applied = useRef(s.q); const pending = useRef<number | undefined>(undefined)
  const apply = (q: string) => { window.clearTimeout(pending.current); applied.current = q; latest.current({ q }) }
  const type = (q: string) => { setSearch(q); window.clearTimeout(pending.current); pending.current = window.setTimeout(() => apply(q), 300) }
  useEffect(() => { if (s.q !== applied.current) { applied.current = s.q; setSearch(s.q) } }, [s.q])
  useEffect(() => () => window.clearTimeout(pending.current), [])

  const refreshAll = () => { void list.reload(); void queues.refresh(); void metrics.refresh() }
  const open = (c: SupportCaseRow) => nav(`${BASE}/support/${encodeURIComponent(c.case_number)}`)
  const all = (label: string) => ({ value: 'all', label })

  const columns: Array<Column<SupportCaseRow>> = [
    { id: 'case', label: t('adm.desk.col.case', undefined, locale), primary: true, render: (c) => <Cell primary={c.case_number} secondary={c.subject} /> },
    { id: 'requester', label: t('adm.desk.col.requester', undefined, locale), hideMobile: true, render: (c) => <span><Badge tone="muted">{deskLabel('requester', c.requester.type, locale)}</Badge>{c.requester.name ? <> <span dir="auto">{c.requester.name}</span></> : null}</span> },
    { id: 'restaurant', label: t('adm.desk.col.restaurant', undefined, locale), hideMobile: true, render: (c) => (c.restaurant ? <span dir="auto">{c.restaurant.name}</span> : '—') },
    { id: 'category', label: t('adm.desk.col.category', undefined, locale), hideMobile: true, render: (c) => <Cell primary={deskLabel('category', c.category, locale)} secondary={c.subcategory ? deskLabel('subcategory', c.subcategory, locale) : undefined} /> },
    { id: 'priority', label: t('adm.desk.col.priority', undefined, locale), render: (c) => <PriorityBadge priority={c.priority} locale={locale} /> },
    { id: 'status', label: t('adm.desk.col.status', undefined, locale), render: (c) => <span><StatusBadge status={c.status} locale={locale} />{c.awaiting_reply && <> <span data-testid="desk-row-awaiting"><Badge tone="red">{t('adm.desk.awaitingReply', undefined, locale)}</Badge></span></>}</span> },
    { id: 'assigned', label: t('adm.desk.col.assigned', undefined, locale), hideMobile: true, render: (c) => <Cell primary={c.assigned.admin?.name ?? t('adm.desk.unassigned', undefined, locale)} secondary={c.assigned.team ? deskLabel('team', c.assigned.team, locale) : undefined} /> },
    { id: 'sla', label: t('adm.desk.col.sla', undefined, locale), hideMobile: true, render: (c) => <SlaBadge status={c.sla.status} locale={locale} /> },
    { id: 'flags', label: t('adm.desk.col.flags', undefined, locale), hideMobile: true, render: (c) => (
      <span className="adm-desk-flags">
        {c.escalation_level && <span data-testid="desk-row-escalated"><Badge tone="purple">{t('adm.desk.escalated', { level: c.escalation_level }, locale)}</Badge></span>}
        {c.classification === 'RESTRICTED' && <span data-testid="desk-row-restricted" data-class={c.restricted_class ?? ''}><Badge tone="red">{t('adm.desk.restricted.badge', { class: c.restricted_class ?? '' }, locale)}</Badge></span>}
        {!c.escalation_level && c.classification !== 'RESTRICTED' && '—'}
      </span>) },
    { id: 'age', label: t('adm.desk.col.age', undefined, locale), hideMobile: true, render: (c) => <span title={fmtDateTime(c.created_at, locale)}>{age(c.created_at, locale)}</span> },
  ]

  const forbidden = list.problem?.kind === 'forbidden'
  const kpi = (testId: string, label: string, value: number | undefined, tone?: string) => <Stat label={label} tone={value && tone ? tone : undefined} value={<span data-testid={testId}>{value === undefined ? '—' : value.toLocaleString(locale)}</span>} />
  const m = metrics.data ?? undefined

  return (
    <div className="db-page" data-testid="desk-queue" data-source="backend" data-queue={queue}>
      <div className="db-page__head">
        <div><h1 className="db-page__title">{t('adm.desk.title', undefined, locale)}</h1><p className="db-page__lead">{t('adm.desk.lead', undefined, locale)}</p></div>
        <div className="db-page__actions">
          <button type="button" className="db-btn db-btn--outline" onClick={refreshAll} data-testid="desk-refresh">{t('adm.desk.refresh', undefined, locale)}</button>
          {/* Shown to an administrator who may work cases; the backend decides when the case is sent. */}
          {a.can('support.manage') && !forbidden && <button type="button" className="db-btn db-btn--primary" onClick={() => setCreating(true)} disabled={!catalog.data} data-testid="desk-new-case"><Icon name="plus" size={16} /> {t('adm.desk.new.title', undefined, locale)}</button>}
        </div>
      </div>
      {notice && <p className="adm-desk-notice" role="status" data-testid="desk-notice">{notice}</p>}

      {forbidden ? <ErrorState title={t('adm.desk.forbidden.title', undefined, locale)} text={t('adm.desk.forbidden.text', undefined, locale)} locale={locale} /> : <>
        <div className="adm-stats" data-testid="desk-kpis">
          {kpi('desk-kpi-open', t('adm.desk.kpi.open', undefined, locale), m?.open)}
          {kpi('desk-kpi-unassigned', t('adm.desk.kpi.unassigned', undefined, locale), m?.unassigned, 'amber')}
          {kpi('desk-kpi-urgent', t('adm.desk.kpi.urgent', undefined, locale), m ? m.urgent + m.high : undefined, 'amber')}
          {kpi('desk-kpi-escalated', t('adm.desk.kpi.escalated', undefined, locale), m?.escalated, 'amber')}
          {kpi('desk-kpi-sla-at-risk', t('adm.desk.kpi.slaAtRisk', undefined, locale), m?.sla_at_risk, 'amber')}
          {kpi('desk-kpi-sla-breached', t('adm.desk.kpi.slaBreached', undefined, locale), m?.sla_breached, 'red')}
        </div>

        <div className="adm-desk-chips" role="group" aria-label={t('adm.desk.queues', undefined, locale)}>
          {SUPPORT_QUEUES.map((q) => { const n = queues.data?.[q]; return (
            <button key={q} type="button" className="db-chip" aria-pressed={queue === q} onClick={() => set({ queue: q })} data-testid="desk-queue-chip" data-queue={q} data-count={n ?? ''}>{t(`adm.desk.queue.${q}`, undefined, locale)}{n !== undefined && <span className="adm-desk-chip__count">{n.toLocaleString(locale)}</span>}</button>) })}
          <button type="button" className="db-chip" aria-pressed={queue === 'all'} onClick={() => set({ queue: 'all' })} data-testid="desk-queue-all" data-count={queues.data?.total ?? ''}>{t('adm.desk.queue.all', undefined, locale)}{queues.data && <span className="adm-desk-chip__count">{queues.data.total.toLocaleString(locale)}</span>}</button>
        </div>

        <Card>
          <div className="db-toolbar adm-toolbar">
            <form className="db-search adm-desk-search" role="search" onSubmit={(e) => { e.preventDefault(); apply(search) }}>
              <label><span className="db-sr-only">{t('adm.desk.search.label', undefined, locale)}</span><Icon name="search" size={18} />
                <input className="db-input" type="search" value={search} maxLength={80} onChange={(e) => type(e.target.value)} placeholder={t('adm.desk.search.placeholder', undefined, locale)} data-testid="desk-search" /></label>
            </form>
            <Select label={t('adm.desk.col.priority', undefined, locale)} value={s.priority} onChange={(priority) => set({ priority })} options={[all(t('adm.desk.filter.priority', undefined, locale)), ...(catalog.data?.priorities ?? SUPPORT_PRIORITIES).map((v) => ({ value: v, label: deskLabel('priority', v, locale) }))]} testId="desk-filter-priority" />
            <Select label={t('adm.desk.col.category', undefined, locale)} value={s.category} onChange={(category) => set({ category })} options={[all(t('adm.desk.filter.category', undefined, locale)), ...(catalog.data?.categories ?? []).map((c) => ({ value: c.code, label: deskLabel('category', c.code, locale) }))]} testId="desk-filter-category" />
            <Select label={t('adm.desk.field.team', undefined, locale)} value={s.team} onChange={(team) => set({ team })} options={[all(t('adm.desk.filter.team', undefined, locale)), ...(catalog.data?.teams ?? SUPPORT_TEAMS).map((v) => ({ value: v, label: deskLabel('team', v, locale) }))]} testId="desk-filter-team" />
            <Select label={t('adm.desk.col.requester', undefined, locale)} value={s.requester} onChange={(requester) => set({ requester })} options={[all(t('adm.desk.filter.requester', undefined, locale)), ...SUPPORT_REQUESTERS.map((v) => ({ value: v, label: deskLabel('requester', v, locale) }))]} testId="desk-filter-requester" />
            {markets.length > 1 && <Select label={t('adm.desk.field.market', undefined, locale)} value={s.market} onChange={(market) => set({ market })} options={[all(t('adm.desk.filter.market', undefined, locale)), ...markets.map((x) => ({ value: x.slug, label: x.name }))]} testId="desk-filter-market" />}
            <Select label={t('adm.desk.col.sla', undefined, locale)} value={s.sla} onChange={(sla) => set({ sla })} options={[all(t('adm.desk.filter.sla', undefined, locale)), ...SUPPORT_SLA_STATES.map((v) => ({ value: v, label: t('adm.desk.sla.badge', { state: deskLabel('sla', v, locale) }, locale) }))]} testId="desk-filter-sla" />
          </div>
          <p className="db-muted adm-small" data-testid="desk-search-note">{t('adm.desk.search.note', undefined, locale)}</p>
          <DataTable columns={columns} rows={list.data?.items ?? []} keyOf={(c) => c.id} state={list.state} total={list.data?.total ?? 0} page={filter.page ?? 1} pageSize={list.data?.pageSize ?? PAGE_SIZE} onPage={(page) => set({ page: String(page) })}
            empty={{ icon: 'support', title: t('adm.desk.empty', undefined, locale), text: s.q ? t('adm.desk.empty.search', undefined, locale) : undefined }} error={list.problem ? { title: t('adm.error.loadTitle', undefined, locale), text: list.problem.message } : undefined} onRetry={() => { void list.reload() }} locale={locale} testId="desk-table" caption={t('adm.desk.title', undefined, locale)}
            onRowClick={open} rowProps={(c) => ({ 'data-testid': 'desk-row', 'data-case': c.case_number, 'data-id': c.id, 'data-status': c.status, 'data-priority': c.priority, 'data-restricted': c.classification === 'RESTRICTED' ? 'true' : 'false' })}
            actions={(c) => <Link to={`${BASE}/support/${encodeURIComponent(c.case_number)}`} className="db-btn db-btn--outline db-btn--sm">{t('adm.table.view', undefined, locale)}</Link>} />
        </Card>
        <DevNote>{t('adm.desk.note', undefined, locale)}</DevNote>
      </>}

      {creating && <NewCaseDialog repo={repo} catalog={catalog.data} markets={markets} locale={locale} onCancel={() => setCreating(false)} onCreated={(c) => { setCreating(false); nav(`${BASE}/support/${encodeURIComponent(c.case_number)}`) }} />}
    </div>
  )
}

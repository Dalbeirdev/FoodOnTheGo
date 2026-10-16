import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { t } from '../../i18n/strings'
import { Card, Drawer, Tabs, ToastLine, useToastMessage } from '../../dashboard/components/ui'
import { useAdmin } from '../AdminContext'
import { BASE } from '../AdminLayout'
import { Badge, DataTable, Details, DevNote, ReasonDialog, Toolbar, useUrlState, type Column } from '../components/DataTable'
import type { AdminReview, AdminReviewCase, ModerationAction, ReviewModerationFilter } from '../types'
import { Cell, Stars, StatusPill, fmtDate, fmtDateTime, useLoad, usePageTitle } from './shared'
import { userMessage } from '../../api/userMessage'

const DEFAULTS = { tab: 'all', q: '', page: '1' }
const TABS: ReviewModerationFilter['tab'][] = ['all', 'pending', 'flagged', 'published', 'hidden', 'rejected', 'removed']
/** Development data (Module 18). */
const ACTIONS_FOR: Record<string, ModerationAction[]> = { SUBMITTED: ['publish', 'hide', 'reject'], PENDING_MODERATION: ['publish', 'hide', 'reject'], FLAGGED: ['publish', 'hide', 'reject'], PUBLISHED: ['hide', 'reject'], HIDDEN: ['restore', 'reject'], REJECTED: ['restore'] }
/** The backend's transitions (Module 34): only what it accepts is offered. A withdrawn review is final. */
const BACKEND_ACTIONS: Record<string, ModerationAction[]> = { PENDING_MODERATION: ['publish', 'reject'], PUBLISHED: ['hide'], HIDDEN: ['restore', 'reject'], REJECTED: ['restore'], REMOVED: [] }
type CaseStep = { kind: 'escalate' | 'resolve'; target: AdminReviewCase }
/** A label when there is one for this code, the code itself otherwise — a new backend code is shown, never hidden. */
const label = (prefix: string, code: string | null, locale: string) => { if (!code) return '—'; const key = `${prefix}.${code}`; const s = t(key, undefined, locale); return s === key ? code : s }

/**
 * Review Moderation (Module 18; on the backend since Module 34): queues, reports and automated checks, publish / hide /
 * reject / restore with a reason, response moderation, moderation cases and the audit history of a review.
 * The platform never edits a rating or a customer's text — it decides visibility, and says why.
 */
export default function ReviewsPage() {
  const a = useAdmin(); const locale = a.locale; usePageTitle('adm.nav.reviews')
  const [s, set] = useUrlState(DEFAULTS); const [open, setOpen] = useState<AdminReview | null>(null); const [action, setAction] = useState<ModerationAction | null>(null); const [step, setStep] = useState<CaseStep | null>(null); const [busy, setBusy] = useState(false); const [err, setErr] = useState<string | null>(null); const { msg, toast } = useToastMessage()
  const filter = useMemo<ReviewModerationFilter>(() => ({ tab: (TABS.includes(s.tab as ReviewModerationFilter['tab']) ? s.tab : 'all') as ReviewModerationFilter['tab'], query: s.q, page: Number(s.page) || 1, pageSize: 10 }), [s])
  const { data, state, reload, refresh } = useLoad(() => a.repos.reviews.list(filter), [a.repos, filter])
  const canMod = a.can('reviews.moderate'); const canRestore = a.can('reviews.restore')
  const live = a.backendSections.includes('reviews')
  /** The backend's detail: history, cases and the reports behind them. The row stays on screen if it cannot be loaded. */
  const detail = async (id: string) => { if (!a.repos.reviews.get) return; try { setOpen(await a.repos.reviews.get(id)) } catch { /* keep the row */ } }
  const show = (r: AdminReview) => { setErr(null); setOpen(r); void detail(r.review.reviewId) }
  const failed = (e: unknown) => { const m = userMessage(e, ''); const text = open?.backend && m && !/_failed$/.test(m) ? m : `${t('adm.error.saveFailed', undefined, locale)}${m ? ` (${m})` : ''}`; setErr(text); return text }
  const moderate = async (reason: string) => {
    if (!open || !action) return
    setBusy(true); setErr(null)
    try { const r = await a.repos.reviews.moderate(open.review.reviewId, action, a.admin.id, reason); setOpen(r); setAction(null); await refresh(); toast(t('adm.updated', undefined, locale)) }
    catch (e) { const text = failed(e); if (open.backend) { setAction(null); toast(text); await detail(open.review.reviewId); await refresh() } }
    finally { setBusy(false) }
  }
  const caseAction = async (kind: 'assign' | 'escalate' | 'resolve', target: AdminReviewCase, reason = '') => {
    if (!open || !a.repos.reviews.caseAction) return
    setBusy(true); setErr(null)
    try { await a.repos.reviews.caseAction(target.id, kind, reason, target.version); setStep(null); await detail(open.review.reviewId); await refresh(); toast(t('adm.updated', undefined, locale)) }
    catch (e) { setStep(null); toast(failed(e)); await detail(open.review.reviewId) }
    finally { setBusy(false) }
  }
  const actionsOf = (r: AdminReview) => (r.backend ? BACKEND_ACTIONS[r.review.status] ?? [] : ACTIONS_FOR[r.review.status] ?? []).filter((x) => x !== 'restore' || canRestore)
  const responseAction = (r: AdminReview): ModerationAction | null => (!r.review.restaurantResponse ? null : r.backend ? (r.backend.responseStatus === 'HIDDEN' ? (canRestore ? 'restore_response' : null) : 'hide_response') : 'hide_response')
  const columns: Array<Column<AdminReview>> = [
    { id: 'rating', label: t('adm.reviews.col.rating', undefined, locale), render: (r) => <Stars rating={r.review.overallRating} /> },
    { id: 'restaurant', label: t('adm.reviews.col.restaurant', undefined, locale), render: (r) => <Cell primary={r.restaurantName} secondary={r.review.orderNumber} /> },
    { id: 'customer', label: t('adm.reviews.col.customer', undefined, locale), hideMobile: true, render: (r) => <span dir="auto">{r.review.customerDisplayName ?? '—'}</span> },
    { id: 'text', label: t('adm.reviews.col.review', undefined, locale), hideMobile: true, render: (r) => <span className="adm-review-text" dir="auto">{r.review.text || '—'}</span> },
    { id: 'reports', label: t('adm.reviews.col.reports', undefined, locale), align: 'end', render: (r) => (r.reports > 0 ? <Badge tone="red">{r.reports}</Badge> : '0') },
    { id: 'status', label: t('adm.reviews.col.status', undefined, locale), render: (r) => <StatusPill status={r.review.status} prefix="adm.reviewStatus" dot /> },
    { id: 'date', label: t('adm.reviews.col.date', undefined, locale), hideMobile: true, render: (r) => fmtDate(r.review.createdAt, locale) },
  ]
  const b = open?.backend ?? null
  const respAct = open ? responseAction(open) : null
  return (
    <div className="db-page" data-testid="adm-reviews" data-source={live ? 'backend' : 'mock'}>
      <div className="db-page__head"><div><h1 className="db-page__title">{t('adm.reviews.title', undefined, locale)}</h1><p className="db-page__lead">{t('adm.reviews.lead', undefined, locale)}</p></div></div>
      <Tabs tabs={TABS.map((id) => ({ id, label: t(`adm.reviews.tab.${id}`, undefined, locale), count: data?.counts[id] }))} value={filter.tab} onChange={(tab) => set({ tab, page: '1' })} label={t('adm.reviews.tabs', undefined, locale)} />
      <Card>
        <Toolbar search={s.q} onSearch={(q) => set({ q, page: '1' })} placeholder={t('adm.reviews.search', undefined, locale)} locale={locale} />
        <DataTable columns={columns} rows={data?.items ?? []} keyOf={(r) => r.review.reviewId} state={state} total={data?.total ?? 0} page={filter.page!} pageSize={10} onPage={(page) => set({ page: String(page) })} empty={{ icon: 'reviews', title: t('adm.reviews.empty', undefined, locale) }} onRetry={() => { void reload() }} locale={locale} testId="reviews-table" caption={t('adm.reviews.title', undefined, locale)} onRowClick={show} actions={(r) => <button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => show(r)}>{t('adm.action.review', undefined, locale)}</button>} />
      </Card>
      <Drawer open={!!open} onClose={() => setOpen(null)} title={open ? `${open.restaurantName} · ${open.review.orderNumber}` : ''} footer={open && canMod && (actionsOf(open).length > 0 || respAct) ? <div className="db-table__actions" data-testid="moderation-actions">{actionsOf(open).map((act) => <button key={act} type="button" className={`db-btn db-btn--sm ${act === 'publish' || act === 'restore' ? 'db-btn--success' : 'db-btn--danger'}`} onClick={() => setAction(act)} data-testid={`mod-${act}`}>{t(`adm.reviews.action.${act}`, undefined, locale)}</button>)}{respAct && <button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => setAction(respAct)} data-testid={`mod-${respAct}`}>{t(`adm.reviews.action.${respAct}`, undefined, locale)}</button>}</div> : undefined}>
        {open && <div className="db-grid" style={{ gap: 14 }} data-testid="review-drawer" data-status={open.review.status} data-version={open.review.version}>
          <p><Stars rating={open.review.overallRating} /> <StatusPill status={open.review.status} prefix="adm.reviewStatus" dot /> {open.reports > 0 && <Badge tone="red">{t('adm.reviews.reports', { n: open.reports }, locale)}</Badge>}</p>
          {open.review.title && <p style={{ margin: 0, fontWeight: 600, overflowWrap: 'anywhere' }} dir="auto">{open.review.title}</p>}
          <blockquote className="db-review" dir="auto" style={{ margin: 0, whiteSpace: 'pre-line', overflowWrap: 'anywhere' }}>{open.review.text || '—'}</blockquote>
          {b && open.review.status === 'REMOVED' && <p className="db-muted" style={{ margin: 0 }} data-testid="review-removed-note">{t('adm.reviews.removedNote', undefined, locale)}</p>}
          <Details rows={[
            [t('adm.reviews.col.customer', undefined, locale), open.review.customerDisplayName ?? '—'],
            ...(b ? [[t('adm.reviews.publicName', undefined, locale), b.publicName ?? '—'] as [string, string], [t('adm.reviews.market', undefined, locale), b.marketCode ?? '—'] as [string, string], [t('adm.reviews.edits', undefined, locale), String(b.editCount)] as [string, string]] : []),
            [t('adm.reviews.order', undefined, locale), <Link key="o" to={`${BASE}/orders/${open.review.orderNumber}`} className="db-link">{open.review.orderNumber}</Link>],
            [t('adm.reviews.col.date', undefined, locale), fmtDateTime(open.review.createdAt, locale)],
            ['Tags', open.review.tags.join(', ') || '—'],
          ]} />
          {b && b.flags.length > 0 && <Card title={t('adm.reviews.flags', undefined, locale)} tone="warn"><ul className="db-list" data-testid="review-flags">{b.flags.map((f) => <li key={f}>{label('adm.reviews.flag', f, locale)}</li>)}</ul><p className="db-muted" style={{ fontSize: '0.8rem', marginBottom: 0 }}>{t('adm.reviews.flagsNote', undefined, locale)}</p></Card>}
          {!b && open.reportReasons.length > 0 && <Card title={t('adm.reviews.reasons', undefined, locale)} tone="warn"><ul className="db-list">{open.reportReasons.map((r) => <li key={r}>{r}</li>)}</ul></Card>}
          {b && b.cases.length > 0 && <Card title={t('adm.reviews.cases', undefined, locale)} tone={b.cases.some((c) => c.status !== 'RESOLVED') ? 'warn' : undefined}>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }} data-testid="review-cases">
              {b.cases.map((c) => (
                <div key={c.id} data-testid="review-case" data-status={c.status}>
                  <p style={{ margin: 0 }}><b>{label('adm.reviews.source', c.source, locale)}</b> · <Badge tone={c.status === 'RESOLVED' ? 'gray' : c.status === 'ESCALATED' ? 'red' : 'amber'}>{label('adm.reviews.caseStatus', c.status, locale)}</Badge>{c.reports > 0 && <> · {t('adm.reviews.reports', { n: c.reports }, locale)}</>}{c.assignedTo && <> · {t('adm.reviews.case.assigned', { name: c.assignedTo }, locale)}</>}</p>
                  {c.reportList.length > 0
                    ? <ul className="db-list">{c.reportList.map((r, i) => <li key={i}><b>{label('adm.reviews.reason', r.reason, locale)}</b> <small className="db-muted">· {label('adm.reviews.reporter', r.reporterType, locale)}{r.at ? ` · ${fmtDateTime(r.at, locale)}` : ''}</small>{r.explanation && <span dir="auto" style={{ display: 'block', overflowWrap: 'anywhere' }}>{r.explanation}</span>}</li>)}</ul>
                    : c.reason && <p className="db-muted" style={{ margin: '4px 0 0' }}>{label('adm.reviews.reason', c.reason, locale)}</p>}
                  {c.status === 'RESOLVED' && <small className="db-muted">{label('adm.reviews.resolution', c.resolution, locale)}{c.resolutionNote ? ` · ${c.resolutionNote}` : ''}{c.resolvedAt ? ` · ${fmtDateTime(c.resolvedAt, locale)}` : ''}</small>}
                  {c.status !== 'RESOLVED' && canMod && a.repos.reviews.caseAction && <div className="db-table__actions" style={{ marginTop: 6 }}>
                    {!c.assignedTo && <button type="button" className="db-btn db-btn--outline db-btn--sm" disabled={busy} onClick={() => { void caseAction('assign', c) }} data-testid="case-assign">{t('adm.reviews.case.assign', undefined, locale)}</button>}
                    {c.status !== 'ESCALATED' && <button type="button" className="db-btn db-btn--outline db-btn--sm" disabled={busy} onClick={() => setStep({ kind: 'escalate', target: c })} data-testid="case-escalate">{t('adm.reviews.case.escalate', undefined, locale)}</button>}
                    <button type="button" className="db-btn db-btn--outline db-btn--sm" disabled={busy} onClick={() => setStep({ kind: 'resolve', target: c })} data-testid="case-resolve">{t('adm.reviews.case.resolve', undefined, locale)}</button>
                  </div>}
                </div>
              ))}
            </div>
          </Card>}
          {open.review.restaurantResponse && <Card title={t('adm.reviews.response', undefined, locale)}>{b?.responseStatus === 'HIDDEN' && <p style={{ margin: '0 0 6px' }}><Badge tone="red">{t('adm.reviews.responseHidden', undefined, locale)}</Badge></p>}<p dir="auto" style={{ margin: 0, whiteSpace: 'pre-line', overflowWrap: 'anywhere' }} data-testid="review-response-text">{open.review.restaurantResponse.text}</p><small className="db-muted">{open.review.restaurantResponse.responderName} · {fmtDateTime(open.review.restaurantResponse.respondedAt, locale)}</small></Card>}
          <Card title={t('adm.reviews.history', undefined, locale)}>{open.moderationHistory.length === 0 ? <p className="db-muted">{t('adm.reviews.noHistory', undefined, locale)}</p> : <ol className="db-timeline" data-testid="moderation-history">{open.moderationHistory.map((h, i) => <li key={i}><span className="db-timeline__dot" /><span><b>{label('adm.reviews.action', h.action, locale)}</b><small className="db-muted"> · {h.by} · {fmtDateTime(h.at, locale)}{h.reason ? ` · ${h.reason}` : ''}</small></span></li>)}</ol>}</Card>
          {b && canMod && <p className="db-muted" style={{ fontSize: '0.8rem', margin: 0 }}>{t('adm.reviews.fairNote', undefined, locale)}</p>}
          {err && <p className="db-field__error" role="alert" data-testid="review-error">{err}</p>}
        </div>}
      </Drawer>
      <ReasonDialog open={!!action} title={action ? t(`adm.reviews.confirm.${action}`, undefined, locale) : ''} text={t('adm.reviews.confirmText', undefined, locale)} confirmLabel={action ? t(`adm.reviews.action.${action}`, undefined, locale) : ''} danger={action !== 'publish' && action !== 'restore' && action !== 'restore_response'} requireReason={action !== 'publish'} busy={busy} locale={locale} onCancel={() => setAction(null)} onConfirm={(reason) => { void moderate(reason) }} testId="moderation-dialog" />
      <ReasonDialog open={!!step} title={step ? t(`adm.reviews.case.confirm.${step.kind}`, undefined, locale) : ''} text={t(step?.kind === 'resolve' ? 'adm.reviews.case.resolveText' : 'adm.reviews.case.escalateText', undefined, locale)} confirmLabel={step ? t(`adm.reviews.case.${step.kind}`, undefined, locale) : ''} danger={false} requireReason busy={busy} locale={locale} onCancel={() => setStep(null)} onConfirm={(reason) => { if (step) void caseAction(step.kind, step.target, reason) }} testId="case-dialog" />
      {!live && <DevNote>{t('adm.mockNote', undefined, locale)}</DevNote>}
      <ToastLine msg={msg} />
    </div>
  )
}

import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { t } from '../../i18n/strings'
import { Card, Drawer, Tabs, ToastLine, useToastMessage } from '../../dashboard/components/ui'
import { useAdmin } from '../AdminContext'
import { BASE } from '../AdminLayout'
import { Badge, DataTable, Details, DevNote, ReasonDialog, Toolbar, useUrlState, type Column } from '../components/DataTable'
import type { AdminReview, ModerationAction, ReviewModerationFilter } from '../types'
import { Cell, Stars, StatusPill, fmtDate, fmtDateTime, useLoad, usePageTitle } from './shared'

const DEFAULTS = { tab: 'all', q: '', page: '1' }
const TABS: ReviewModerationFilter['tab'][] = ['all', 'pending', 'flagged', 'published', 'hidden', 'rejected']
const ACTIONS_FOR: Record<string, ModerationAction[]> = { SUBMITTED: ['publish', 'hide', 'reject'], PENDING_MODERATION: ['publish', 'hide', 'reject'], FLAGGED: ['publish', 'hide', 'reject'], PUBLISHED: ['hide', 'reject'], HIDDEN: ['restore', 'reject'], REJECTED: ['restore'] }

/** Review Moderation (Module 18): queues, reports, publish / hide / reject / restore with reason, response moderation, history. */
export default function ReviewsPage() {
  const a = useAdmin(); const locale = a.locale; usePageTitle('adm.nav.reviews')
  const [s, set] = useUrlState(DEFAULTS); const [open, setOpen] = useState<AdminReview | null>(null); const [action, setAction] = useState<ModerationAction | null>(null); const [busy, setBusy] = useState(false); const [err, setErr] = useState<string | null>(null); const { msg, toast } = useToastMessage()
  const filter = useMemo<ReviewModerationFilter>(() => ({ tab: (TABS.includes(s.tab as ReviewModerationFilter['tab']) ? s.tab : 'all') as ReviewModerationFilter['tab'], query: s.q, page: Number(s.page) || 1, pageSize: 10 }), [s])
  const { data, state, reload, refresh } = useLoad(() => a.repos.reviews.list(filter), [a.repos, filter])
  const canMod = a.can('reviews.moderate')
  const moderate = async (reason: string) => { if (!open || !action) return; setBusy(true); setErr(null); try { const r = await a.repos.reviews.moderate(open.review.reviewId, action, a.admin.id, reason); setOpen(r); setAction(null); await refresh(); toast(t('adm.updated', undefined, locale)) } catch (e) { setErr((e as Error).message) } finally { setBusy(false) } }
  const columns: Array<Column<AdminReview>> = [
    { id: 'rating', label: t('adm.reviews.col.rating', undefined, locale), render: (r) => <Stars rating={r.review.overallRating} /> },
    { id: 'restaurant', label: t('adm.reviews.col.restaurant', undefined, locale), render: (r) => <Cell primary={r.restaurantName} secondary={r.review.orderNumber} /> },
    { id: 'customer', label: t('adm.reviews.col.customer', undefined, locale), hideMobile: true, render: (r) => <span dir="auto">{r.review.customerDisplayName ?? '—'}</span> },
    { id: 'text', label: t('adm.reviews.col.review', undefined, locale), hideMobile: true, render: (r) => <span className="adm-review-text" dir="auto">{r.review.text || '—'}</span> },
    { id: 'reports', label: t('adm.reviews.col.reports', undefined, locale), align: 'end', render: (r) => (r.reports > 0 ? <Badge tone="red">{r.reports}</Badge> : '0') },
    { id: 'status', label: t('adm.reviews.col.status', undefined, locale), render: (r) => <StatusPill status={r.review.status} prefix="adm.reviewStatus" dot /> },
    { id: 'date', label: t('adm.reviews.col.date', undefined, locale), hideMobile: true, render: (r) => fmtDate(r.review.createdAt, locale) },
  ]
  return (
    <div className="db-page" data-testid="adm-reviews">
      <div className="db-page__head"><div><h1 className="db-page__title">{t('adm.reviews.title', undefined, locale)}</h1><p className="db-page__lead">{t('adm.reviews.lead', undefined, locale)}</p></div></div>
      <Tabs tabs={TABS.map((id) => ({ id, label: t(`adm.reviews.tab.${id}`, undefined, locale), count: data?.counts[id] }))} value={filter.tab} onChange={(tab) => set({ tab })} label={t('adm.reviews.tabs', undefined, locale)} />
      <Card>
        <Toolbar search={s.q} onSearch={(q) => set({ q })} placeholder={t('adm.reviews.search', undefined, locale)} locale={locale} />
        <DataTable columns={columns} rows={data?.items ?? []} keyOf={(r) => r.review.reviewId} state={state} total={data?.total ?? 0} page={filter.page!} pageSize={10} onPage={(page) => set({ page: String(page) })} empty={{ icon: 'reviews', title: t('adm.reviews.empty', undefined, locale) }} onRetry={() => { void reload() }} locale={locale} testId="reviews-table" caption={t('adm.reviews.title', undefined, locale)} onRowClick={setOpen} actions={(r) => <button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => setOpen(r)}>{t('adm.action.review', undefined, locale)}</button>} />
      </Card>
      <Drawer open={!!open} onClose={() => setOpen(null)} title={open ? `${open.restaurantName} · ${open.review.orderNumber}` : ''} footer={open && canMod ? <div className="db-table__actions" data-testid="moderation-actions">{(ACTIONS_FOR[open.review.status] ?? []).map((act) => <button key={act} type="button" className={`db-btn db-btn--sm ${act === 'publish' || act === 'restore' ? 'db-btn--success' : 'db-btn--danger'}`} onClick={() => setAction(act)} data-testid={`mod-${act}`}>{t(`adm.reviews.action.${act}`, undefined, locale)}</button>)}{open.review.restaurantResponse && <button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => setAction('hide_response')} data-testid="mod-hide_response">{t('adm.reviews.hideResponse', undefined, locale)}</button>}</div> : undefined}>
        {open && <div className="db-grid" style={{ gap: 14 }} data-testid="review-drawer">
          <p><Stars rating={open.review.overallRating} /> <StatusPill status={open.review.status} prefix="adm.reviewStatus" dot /> {open.reports > 0 && <Badge tone="red">{t('adm.reviews.reports', { n: open.reports }, locale)}</Badge>}</p>
          <blockquote className="db-review" dir="auto" style={{ margin: 0 }}>{open.review.text || '—'}</blockquote>
          <Details rows={[[t('adm.reviews.col.customer', undefined, locale), open.review.customerDisplayName ?? '—'], [t('adm.reviews.order', undefined, locale), <Link key="o" to={`${BASE}/orders/${open.review.orderNumber}`} className="db-link">{open.review.orderNumber}</Link>], [t('adm.reviews.col.date', undefined, locale), fmtDateTime(open.review.createdAt, locale)], ['Tags', open.review.tags.join(', ') || '—']]} />
          {open.reportReasons.length > 0 && <Card title={t('adm.reviews.reasons', undefined, locale)} tone="warn"><ul className="db-list">{open.reportReasons.map((r) => <li key={r}>{r}</li>)}</ul></Card>}
          {open.review.restaurantResponse && <Card title={t('adm.reviews.response', undefined, locale)}><p dir="auto" style={{ margin: 0 }}>{open.review.restaurantResponse.text}</p><small className="db-muted">{open.review.restaurantResponse.responderName} · {fmtDateTime(open.review.restaurantResponse.respondedAt, locale)}</small></Card>}
          <Card title={t('adm.reviews.history', undefined, locale)}>{open.moderationHistory.length === 0 ? <p className="db-muted">{t('adm.reviews.noHistory', undefined, locale)}</p> : <ol className="db-timeline" data-testid="moderation-history">{open.moderationHistory.map((h, i) => <li key={i}><span className="db-timeline__dot" /><span><b>{t(`adm.reviews.action.${h.action}`, undefined, locale)}</b><small className="db-muted"> · {h.by} · {fmtDateTime(h.at, locale)}{h.reason ? ` · ${h.reason}` : ''}</small></span></li>)}</ol>}</Card>
          {err && <p className="db-field__error" role="alert">{t('adm.error.saveFailed', undefined, locale)} ({err})</p>}
        </div>}
      </Drawer>
      <ReasonDialog open={!!action} title={action ? t(`adm.reviews.confirm.${action}`, undefined, locale) : ''} text={t('adm.reviews.confirmText', undefined, locale)} confirmLabel={action ? t(`adm.reviews.action.${action}`, undefined, locale) : ''} danger={action !== 'publish' && action !== 'restore'} requireReason={action !== 'publish'} busy={busy} locale={locale} onCancel={() => setAction(null)} onConfirm={(reason) => { void moderate(reason) }} testId="moderation-dialog" />
      <DevNote>{t('adm.mockNote', undefined, locale)}</DevNote>
      <ToastLine msg={msg} />
    </div>
  )
}

import { useCallback, useEffect, useState } from 'react'
import { t } from '../../i18n/strings'
import { REPORT_REASONS, type ReportReason, type Review } from '../../review/repositories'
import { useDashboard } from '../DashboardContext'
import { RatingBars } from '../components/Charts'
import { Card, EmptyState, ErrorState, Field, Icon, KpiCard, PageHeader, Pill, Skeleton, ToastLine, useToastMessage } from '../components/ui'

/**
 * Reviews (Module 17; on the backend since Module 34): the average, the distribution and the published reviews of the
 * selected location, the restaurant's one response to each (publish, edit) and reporting a review to FoodOnTheGo. The
 * restaurant never edits a rating or a customer's text and cannot hide a review — it can answer and it can report.
 */
type Summary = { averageRating: number | null; reviewCount: number; distribution: Array<{ rating: number; count: number }>; answered?: number }

export default function ReviewsPage() {
  const d = useDashboard(); const locale = d.locale; const rid = d.location!.restaurant.id
  const mayRespond = d.can('reviews.respond'); const mayReport = d.can('reviews.report') && typeof d.repos.reviews.report === 'function'
  const [summary, setSummary] = useState<Summary | null>(null); const [reviews, setReviews] = useState<Review[]>([]); const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading')
  const [filter, setFilter] = useState<'all' | 'unanswered' | 'low'>('all'); const [responding, setResponding] = useState<string | null>(null); const [text, setText] = useState(''); const [busy, setBusy] = useState(false); const { msg, toast } = useToastMessage()
  const [reporting, setReporting] = useState<string | null>(null); const [reason, setReason] = useState<ReportReason>('SPAM'); const [explanation, setExplanation] = useState(''); const [formError, setFormError] = useState<string | null>(null)
  const load = useCallback(async (quiet = false) => { if (!quiet) setState('loading'); try { const [s, l] = await Promise.all([d.repos.reviews.summary(rid), d.repos.reviews.list(rid)]); setSummary(s); setReviews(l); setState('ready') } catch { setState('error') } }, [d.repos, rid])
  useEffect(() => { void load() }, [load])
  useEffect(() => { document.title = `${t('dash.nav.reviews', undefined, locale)} · ${t('dash.brand', undefined, locale)}` }, [locale])
  const list = reviews.filter((r) => (filter === 'unanswered' ? !r.restaurantResponse : filter === 'low' ? r.overallRating <= 3 : true))
  const message = (e: unknown, fallback: string) => (e instanceof Error && e.message !== 'reviews_request_failed' && !/_failed$|_not_found$/.test(e.message) ? e.message : fallback)
  const respond = async (r: Review) => {
    if (!text.trim() || busy) return
    setBusy(true); setFormError(null)
    try { await d.repos.reviews.respond(r.reviewId, text, d.staff.name); setResponding(null); setText(''); toast(t('dash.reviews.responded', undefined, locale)); await load(true) }
    catch (e) { const m = message(e, t('dash.reviews.respondFailed', undefined, locale)); setFormError(m); toast(m) }
    finally { setBusy(false) }
  }
  const report = async (r: Review) => {
    if (busy || !d.repos.reviews.report) return
    setBusy(true); setFormError(null)
    try { await d.repos.reviews.report(r.reviewId, reason, explanation); setReporting(null); setExplanation(''); toast(t('dash.reviews.reported', undefined, locale)); await load(true) }
    catch (e) { const m = message(e, t('dash.reviews.reportFailed', undefined, locale)); setFormError(m); toast(m) }
    finally { setBusy(false) }
  }
  const date = (iso: string) => (iso ? new Date(iso).toLocaleDateString(locale, { dateStyle: 'medium' }) : '')
  const answered = summary?.answered ?? reviews.filter((r) => r.restaurantResponse).length
  return (
    <div className="db-page" data-testid="db-reviews">
      <PageHeader title={t('dash.reviews.title', undefined, locale)} lead={t('dash.reviews.lead', undefined, locale)} />
      {state === 'error' && <ErrorState title={t('dash.error.loadTitle', undefined, locale)} onRetry={() => { void load() }} locale={locale} />}
      {state === 'loading' && <Card><Skeleton rows={5} /></Card>}
      {state === 'ready' && summary && (
        <>
          <div className="db-grid db-grid--3">
            <KpiCard icon="star" tone="amber" value={summary.averageRating == null ? '—' : summary.averageRating.toLocaleString(locale, { maximumFractionDigits: 1 })} label={t('dash.reviews.average', undefined, locale)} locale={locale} testId="rv-avg" />
            <KpiCard icon="reviews" tone="orange" value={summary.reviewCount.toLocaleString(locale)} label={t('dash.reviews.count', undefined, locale)} locale={locale} testId="rv-count" />
            <KpiCard icon="check" tone="green" value={answered.toLocaleString(locale)} label={t('dash.reviews.answered', undefined, locale)} locale={locale} testId="rv-answered" />
          </div>
          <div className="db-grid db-grid--2">
            <Card title={t('dash.reviews.recent', undefined, locale)} actions={<div className="db-toolbar">{(['all', 'unanswered', 'low'] as const).map((f) => <button key={f} type="button" className="db-chip" aria-pressed={filter === f} onClick={() => setFilter(f)}>{t(`dash.reviews.filter.${f}`, undefined, locale)}</button>)}</div>}>
              {summary.reviewCount > reviews.length && <p className="db-muted" style={{ fontSize: '0.8rem', marginTop: 0 }} data-testid="review-window">{t('dash.reviews.latest', { shown: reviews.length, total: summary.reviewCount }, locale)}</p>}
              {list.length === 0 ? <EmptyState icon="reviews" title={t('dash.reviews.emptyTitle', undefined, locale)} text={t('dash.reviews.emptyText', undefined, locale)} /> : (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }} data-testid="review-list">
                  {list.map((r) => {
                    const canRespond = mayRespond && r.canRespond !== false && !r.responseHidden
                    const canReport = mayReport && r.canReport !== false && !r.reported
                    return (
                      <article key={r.reviewId} className="db-review" data-testid="review-card" data-rating={r.overallRating}>
                        <div className="db-review__head"><span><span className="db-stars" aria-hidden="true">{'★'.repeat(r.overallRating)}{'☆'.repeat(Math.max(0, 5 - r.overallRating))}</span> <span className="db-sr-only">{t('rv.rating.value', { n: r.overallRating, max: 5 }, locale)}</span> <b dir="auto">{r.customerDisplayName ?? t('dash.reviews.customer', undefined, locale)}</b></span><span className="db-muted" style={{ fontSize: '0.82rem' }}>{date(r.createdAt)} · <span className="db-table__num">{r.orderNumber}</span> · <Pill tone={r.status === 'PUBLISHED' ? 'green' : 'amber'}>{t(`rv.status.${r.status}`, undefined, locale)}</Pill>{r.reported && <> <Pill tone="amber">{t('dash.reviews.reportedPill', undefined, locale)}</Pill></>}</span></div>
                        {r.tags.length > 0 && <div className="db-tags">{r.tags.map((k) => <Pill key={k}>{t(`rv.tag.${k}`, undefined, locale)}</Pill>)}</div>}
                        {r.itemFeedback.some((f) => f.sentiment) && <p className="db-muted" style={{ margin: 0, fontSize: '0.85rem' }} dir="auto">{r.itemFeedback.filter((f) => f.sentiment).map((f) => `${f.itemName}: ${t(`rv.item.${f.sentiment}`, undefined, locale)}`).join(' · ')}</p>}
                        {r.text ? <p style={{ margin: 0, whiteSpace: 'pre-line', overflowWrap: 'anywhere' }} dir="auto">{r.text}</p> : <p className="db-muted" style={{ margin: 0 }}>{t('dash.reviews.noText', undefined, locale)}</p>}
                        {r.restaurantResponse && responding !== r.reviewId && (
                          <div className="db-review__response" data-testid="review-response">
                            <b>{t('dash.reviews.responseFrom', { name: r.restaurantResponse.responderName }, locale)}</b> · <span className="db-muted">{date(r.restaurantResponse.respondedAt)}</span>{r.responseHidden && <> <Pill tone="red">{t('dash.reviews.responseHidden', undefined, locale)}</Pill></>}
                            <p style={{ margin: '4px 0 0', whiteSpace: 'pre-line', overflowWrap: 'anywhere' }} dir="auto">{r.restaurantResponse.text}</p>
                          </div>
                        )}
                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
                          {canRespond && !r.restaurantResponse && responding !== r.reviewId && <button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => { setResponding(r.reviewId); setReporting(null); setText(''); setFormError(null) }} data-testid="review-respond">{t('dash.reviews.respond', undefined, locale)}</button>}
                          {canRespond && r.restaurantResponse && r.responseVersion !== undefined && responding !== r.reviewId && <button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => { setResponding(r.reviewId); setReporting(null); setText(r.restaurantResponse?.text ?? ''); setFormError(null) }} data-testid="review-response-edit">{t('dash.reviews.editResponse', undefined, locale)}</button>}
                          {canReport && reporting !== r.reviewId && <button type="button" className="db-btn db-btn--ghost db-btn--sm" onClick={() => { setReporting(r.reviewId); setResponding(null); setExplanation(''); setFormError(null) }} data-testid="review-report">{t('dash.reviews.report', undefined, locale)}</button>}
                        </div>
                        {responding === r.reviewId && (
                          <form onSubmit={(e) => { e.preventDefault(); void respond(r) }} style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                            <Field label={t('dash.reviews.responseLabel', undefined, locale)} hint={t('dash.reviews.responseHint', undefined, locale)} error={formError ?? undefined} id={`resp-${r.reviewId}`}><textarea id={`resp-${r.reviewId}`} className="db-textarea" value={text} onChange={(e) => setText(e.target.value)} maxLength={600} dir="auto" data-testid="review-response-text" /></Field>
                            <div style={{ display: 'flex', gap: 8 }}><button type="submit" className="db-btn db-btn--primary db-btn--sm" disabled={busy || !text.trim()} data-testid="review-response-send">{t('dash.reviews.send', undefined, locale)}</button><button type="button" className="db-btn db-btn--ghost db-btn--sm" onClick={() => setResponding(null)}>{t('dash.action.cancel', undefined, locale)}</button></div>
                          </form>
                        )}
                        {reporting === r.reviewId && (
                          <form onSubmit={(e) => { e.preventDefault(); void report(r) }} style={{ display: 'flex', flexDirection: 'column', gap: 8 }} data-testid="review-report-form">
                            <Field label={t('dash.reviews.reportReason', undefined, locale)} id={`rep-${r.reviewId}`} error={formError ?? undefined}><select id={`rep-${r.reviewId}`} className="db-select" value={reason} onChange={(e) => setReason(e.target.value as ReportReason)} data-testid="review-report-reason">{REPORT_REASONS.map((k) => <option key={k} value={k}>{t(`rdr.reason.${k}`, undefined, locale)}</option>)}</select></Field>
                            <Field label={t('dash.reviews.reportExplanation', undefined, locale)} hint={t('dash.reviews.reportHint', undefined, locale)} id={`repx-${r.reviewId}`}><textarea id={`repx-${r.reviewId}`} className="db-textarea" value={explanation} onChange={(e) => setExplanation(e.target.value)} maxLength={500} dir="auto" /></Field>
                            <div style={{ display: 'flex', gap: 8 }}><button type="submit" className="db-btn db-btn--primary db-btn--sm" disabled={busy} data-testid="review-report-send">{t('dash.reviews.reportSend', undefined, locale)}</button><button type="button" className="db-btn db-btn--ghost db-btn--sm" onClick={() => setReporting(null)}>{t('dash.action.cancel', undefined, locale)}</button></div>
                          </form>
                        )}
                      </article>
                    )
                  })}
                </div>
              )}
            </Card>
            <Card title={t('dash.reviews.distribution', undefined, locale)} subtitle={t('dash.reviews.distributionSub', undefined, locale)}>
              <RatingBars distribution={summary.distribution} locale={locale} />
              <p className="db-muted" style={{ fontSize: '0.8rem', marginBottom: 0 }}><Icon name="info" size={13} /> {t(d.live ? 'dash.reviews.liveNote' : 'dash.reviews.moderationNote', undefined, locale)}</p>
            </Card>
          </div>
        </>
      )}
      <ToastLine msg={msg} />
    </div>
  )
}

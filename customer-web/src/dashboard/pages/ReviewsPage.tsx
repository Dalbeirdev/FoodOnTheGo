import { useCallback, useEffect, useState } from 'react'
import { t } from '../../i18n/strings'
import type { Review } from '../../review/repositories'
import { useDashboard } from '../DashboardContext'
import { RatingBars } from '../components/Charts'
import { Card, EmptyState, ErrorState, Field, Icon, KpiCard, PageHeader, Pill, Skeleton, ToastLine, useToastMessage } from '../components/ui'

/** Reviews (Module 17): average, distribution, recent reviews with restaurant response. The restaurant never edits a rating or text. */
export default function ReviewsPage() {
  const d = useDashboard(); const locale = d.locale; const rid = d.location!.restaurant.id; const canRespond = d.can('reviews.respond')
  const [summary, setSummary] = useState<{ averageRating: number | null; reviewCount: number; distribution: Array<{ rating: number; count: number }> } | null>(null); const [reviews, setReviews] = useState<Review[]>([]); const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading')
  const [filter, setFilter] = useState<'all' | 'unanswered' | 'low'>('all'); const [responding, setResponding] = useState<string | null>(null); const [text, setText] = useState(''); const [busy, setBusy] = useState(false); const { msg, toast } = useToastMessage()
  const load = useCallback(async () => { setState('loading'); try { const [s, l] = await Promise.all([d.repos.reviews.summary(rid), d.repos.reviews.list(rid)]); setSummary(s); setReviews(l); setState('ready') } catch { setState('error') } }, [d.repos, rid])
  useEffect(() => { void load() }, [load])
  useEffect(() => { document.title = `${t('dash.nav.reviews', undefined, locale)} · ${t('dash.brand', undefined, locale)}` }, [locale])
  const list = reviews.filter((r) => (filter === 'unanswered' ? !r.restaurantResponse : filter === 'low' ? r.overallRating <= 3 : true))
  const respond = async (r: Review) => { if (!text.trim() || busy) return; setBusy(true); try { await d.repos.reviews.respond(r.reviewId, text, d.staff.name); setResponding(null); setText(''); toast(t('dash.reviews.responded', undefined, locale)); await load() } catch { toast(t('dash.reviews.respondFailed', undefined, locale)) } finally { setBusy(false) } }
  const date = (iso: string) => new Date(iso).toLocaleDateString(locale, { dateStyle: 'medium' })
  return (
    <div className="db-page" data-testid="db-reviews">
      <PageHeader title={t('dash.reviews.title', undefined, locale)} lead={t('dash.reviews.lead', undefined, locale)} />
      {state === 'error' && <ErrorState title={t('dash.error.loadTitle', undefined, locale)} onRetry={() => { void load() }} locale={locale} />}
      {state === 'loading' && <Card><Skeleton rows={5} /></Card>}
      {state === 'ready' && summary && (
        <>
          <div className="db-grid db-grid--3">
            <KpiCard icon="star" tone="amber" value={summary.averageRating == null ? '—' : summary.averageRating.toLocaleString(locale, { maximumFractionDigits: 1 })} label={t('dash.reviews.average', undefined, locale)} locale={locale} testId="rv-avg" />
            <KpiCard icon="reviews" tone="orange" value={summary.reviewCount.toLocaleString(locale)} label={t('dash.reviews.count', undefined, locale)} locale={locale} />
            <KpiCard icon="check" tone="green" value={reviews.filter((r) => r.restaurantResponse).length.toLocaleString(locale)} label={t('dash.reviews.answered', undefined, locale)} locale={locale} />
          </div>
          <div className="db-grid db-grid--2">
            <Card title={t('dash.reviews.recent', undefined, locale)} actions={<div className="db-toolbar">{(['all', 'unanswered', 'low'] as const).map((f) => <button key={f} type="button" className="db-chip" aria-pressed={filter === f} onClick={() => setFilter(f)}>{t(`dash.reviews.filter.${f}`, undefined, locale)}</button>)}</div>}>
              {list.length === 0 ? <EmptyState icon="reviews" title={t('dash.reviews.emptyTitle', undefined, locale)} text={t('dash.reviews.emptyText', undefined, locale)} /> : (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }} data-testid="review-list">
                  {list.map((r) => (
                    <article key={r.reviewId} className="db-review" data-testid="review-card">
                      <div className="db-review__head"><span><span className="db-stars" aria-hidden="true">{'★'.repeat(r.overallRating)}{'☆'.repeat(5 - r.overallRating)}</span> <span className="db-sr-only">{t('rv.rating.value', { n: r.overallRating, max: 5 }, locale)}</span> <b dir="auto">{r.customerDisplayName ?? t('dash.reviews.customer', undefined, locale)}</b></span><span className="db-muted" style={{ fontSize: '0.82rem' }}>{date(r.createdAt)} · <span className="db-table__num">{r.orderNumber}</span> · <Pill tone={r.status === 'PUBLISHED' ? 'green' : 'amber'}>{t(`rv.status.${r.status}`, undefined, locale)}</Pill></span></div>
                      {r.tags.length > 0 && <div className="db-tags">{r.tags.map((k) => <Pill key={k}>{t(`rv.tag.${k}`, undefined, locale)}</Pill>)}</div>}
                      {r.text ? <p style={{ margin: 0 }} dir="auto">{r.text}</p> : <p className="db-muted" style={{ margin: 0 }}>{t('dash.reviews.noText', undefined, locale)}</p>}
                      {r.restaurantResponse && <div className="db-review__response" data-testid="review-response"><b>{t('dash.reviews.responseFrom', { name: r.restaurantResponse.responderName }, locale)}</b> · <span className="db-muted">{date(r.restaurantResponse.respondedAt)}</span><p style={{ margin: '4px 0 0' }} dir="auto">{r.restaurantResponse.text}</p></div>}
                      {canRespond && !r.restaurantResponse && responding !== r.reviewId && <button type="button" className="db-btn db-btn--outline db-btn--sm" style={{ alignSelf: 'flex-start' }} onClick={() => { setResponding(r.reviewId); setText('') }} data-testid="review-respond">{t('dash.reviews.respond', undefined, locale)}</button>}
                      {responding === r.reviewId && (
                        <form onSubmit={(e) => { e.preventDefault(); void respond(r) }} style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                          <Field label={t('dash.reviews.responseLabel', undefined, locale)} hint={t('dash.reviews.responseHint', undefined, locale)} id={`resp-${r.reviewId}`}><textarea id={`resp-${r.reviewId}`} className="db-textarea" value={text} onChange={(e) => setText(e.target.value)} maxLength={600} dir="auto" data-testid="review-response-text" /></Field>
                          <div style={{ display: 'flex', gap: 8 }}><button type="submit" className="db-btn db-btn--primary db-btn--sm" disabled={busy || !text.trim()} data-testid="review-response-send">{t('dash.reviews.send', undefined, locale)}</button><button type="button" className="db-btn db-btn--ghost db-btn--sm" onClick={() => setResponding(null)}>{t('dash.action.cancel', undefined, locale)}</button></div>
                        </form>
                      )}
                    </article>
                  ))}
                </div>
              )}
            </Card>
            <Card title={t('dash.reviews.distribution', undefined, locale)} subtitle={t('dash.reviews.distributionSub', undefined, locale)}>
              <RatingBars distribution={summary.distribution} locale={locale} />
              <p className="db-muted" style={{ fontSize: '0.8rem', marginBottom: 0 }}><Icon name="info" size={13} /> {t('dash.reviews.moderationNote', undefined, locale)}</p>
            </Card>
          </div>
        </>
      )}
      <ToastLine msg={msg} />
    </div>
  )
}

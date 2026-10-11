import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { t } from '../i18n/strings'
import { REPORT_REASONS, type PublicReview, type PublicReviewPage, type PublicReviewSort, type RatingSummary, type ReportReason, type ReviewRepository } from './repositories'
import { reviewRepositories } from './reviewRepositories'
import './RestaurantReviews.css'

/**
 * Ratings and reviews of one restaurant location as the backend publishes them (Module 34): the average of the published
 * verified-order reviews, their number and distribution, then the reviews themselves — paged, sortable, filterable by
 * stars. "No reviews yet" is said as such: no number is ever invented. Review text is rendered as text.
 */
const SORTS: PublicReviewSort[] = ['newest', 'highest', 'lowest', 'oldest']
const PAGE_SIZE = 5
const stars = (n: number, max = 5) => '★'.repeat(Math.max(0, Math.min(max, n))) + '☆'.repeat(Math.max(0, max - n))

export default function RestaurantReviews({ slug, locale, repo }: { slug: string; locale: string; repo?: ReviewRepository }) {
  const reviews = repo ?? reviewRepositories.reviews
  const { isAuthenticated } = useAuth()
  const [sort, setSort] = useState<PublicReviewSort>('newest')
  const [rating, setRating] = useState<number | null>(null)
  const [page, setPage] = useState(1)
  const [items, setItems] = useState<PublicReview[]>([])
  const [meta, setMeta] = useState<{ total: number; summary: RatingSummary } | null>(null)
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading')
  const [reload, setReload] = useState(0)

  useEffect(() => {
    let on = true
    reviews.listPublic(slug, { page, pageSize: PAGE_SIZE, sort, rating })
      .then((p: PublicReviewPage) => {
        if (!on) return
        setItems((current) => (page === 1 ? p.items : [...current, ...p.items.filter((r) => !current.some((c) => c.reviewId === r.reviewId))]))
        setMeta({ total: p.total, summary: p.summary }); setState('ready')
      })
      .catch(() => { if (on) setState('error') })
    return () => { on = false }
  }, [slug, sort, rating, page, reload]) // eslint-disable-line react-hooks/exhaustive-deps

  const change = (next: { sort?: PublicReviewSort; rating?: number | null }) => { setState('loading'); setPage(1); if (next.sort) setSort(next.sort); if ('rating' in next) setRating(next.rating ?? null) }
  const summary = meta?.summary
  const count = summary?.reviewCount ?? 0
  const date = (iso: string) => (iso ? new Date(iso).toLocaleDateString(locale, { dateStyle: 'medium' }) : '')

  return (
    <section className="rd-side-card rd-reviews" aria-labelledby="rd-reviews-title" data-testid="rd-reviews" data-count={count} data-average={summary?.averageRating ?? ''}>
      <h2 id="rd-reviews-title">{t('rd.reviews.title', undefined, locale)}</h2>
      {state === 'error' && !meta && <p role="alert">{t('rdr.error', undefined, locale)} <button type="button" className="cart-link" onClick={() => { setState('loading'); setReload((n) => n + 1) }}>{t('oc.action.retry', undefined, locale)}</button></p>}
      {state === 'loading' && !meta && <p className="rd-muted" role="status">{t('rdr.loading', undefined, locale)}</p>}
      {summary && count === 0 && rating === null && (
        <p data-testid="rd-reviews-none"><b>{t('card.noReviews', undefined, locale)}</b> · {t('rd.noReviews', undefined, locale)}. <span className="rd-muted">{t('rdr.noneHint', undefined, locale)}</span></p>
      )}
      {summary && (count > 0 || rating !== null) && (
        <>
          <div className="rdr-summary">
            <p className="rdr-average" data-testid="rd-reviews-average">
              <b>{summary.averageRating === null ? '—' : summary.averageRating.toLocaleString(locale, { minimumFractionDigits: 1, maximumFractionDigits: 1 })}</b>
              <span> / 5</span>
              <small>{t(count === 1 ? 'rdr.basedOn.one' : 'rdr.basedOn', { count: count.toLocaleString(locale) }, locale)}</small>
            </p>
            <ul className="rdr-bars" aria-label={t('rdr.distribution', undefined, locale)}>
              {[5, 4, 3, 2, 1].map((star) => {
                const n = summary.distribution[star] ?? 0
                return (
                  <li key={star}>
                    <span>{t(star === 1 ? 'rv.star.one' : 'rv.star.many', { n: star, max: 5 }, locale)}</span>
                    <span className="rdr-bar" aria-hidden="true"><i style={{ width: `${count > 0 ? Math.round((n / count) * 100) : 0}%` }} /></span>
                    <span data-testid={`rd-reviews-star-${star}`}>{n.toLocaleString(locale)}</span>
                  </li>
                )
              })}
            </ul>
          </div>
          <div className="rdr-controls">
            <label>{t('rdr.sort', undefined, locale)}
              <select value={sort} onChange={(e) => change({ sort: e.target.value as PublicReviewSort })} data-testid="rd-reviews-sort">
                {SORTS.map((s) => <option key={s} value={s}>{t(`rdr.sort.${s}`, undefined, locale)}</option>)}
              </select>
            </label>
            <label>{t('rdr.filter', undefined, locale)}
              <select value={rating ?? ''} onChange={(e) => change({ rating: e.target.value === '' ? null : Number(e.target.value) })} data-testid="rd-reviews-filter">
                <option value="">{t('rdr.filter.all', undefined, locale)}</option>
                {[5, 4, 3, 2, 1].map((star) => <option key={star} value={star}>{t(star === 1 ? 'rv.star.one' : 'rv.star.many', { n: star, max: 5 }, locale)}</option>)}
              </select>
            </label>
          </div>
          {items.length === 0 && state === 'ready' && <p className="rd-muted" role="status">{t('rdr.noneForFilter', undefined, locale)}</p>}
          <ul className="rdr-list" data-testid="rd-review-list" aria-busy={state === 'loading'}>
            {items.map((r) => <ReviewItem key={r.reviewId} review={r} locale={locale} date={date} canReport={isAuthenticated} onReport={(reason, explanation) => reviews.reportReview(r.reviewId, reason, explanation)} />)}
          </ul>
          {meta && items.length < meta.total && (
            <button type="button" className="btn btn--outline" disabled={state === 'loading'} onClick={() => { setState('loading'); setPage((p) => p + 1) }} data-testid="rd-reviews-more">{t('rdr.more', { shown: items.length, total: meta.total }, locale)}</button>
          )}
          <p className="rd-muted">{t('rdr.note', undefined, locale)}</p>
        </>
      )}
    </section>
  )
}

function ReviewItem({ review, locale, date, canReport, onReport }: { review: PublicReview; locale: string; date: (iso: string) => string; canReport: boolean; onReport: (reason: ReportReason, explanation: string) => Promise<void> }) {
  const [open, setOpen] = useState(false)
  const [reason, setReason] = useState<ReportReason>('SPAM')
  const [explanation, setExplanation] = useState('')
  const [state, setState] = useState<'idle' | 'sending' | 'sent' | 'error'>('idle')
  const [message, setMessage] = useState('')
  const send = async () => {
    setState('sending')
    try { await onReport(reason, explanation); setState('sent'); setOpen(false) } catch (e) { setMessage(e instanceof Error && e.message !== 'review_request_failed' ? e.message : t('rdr.report.failed', undefined, locale)); setState('error') }
  }
  return (
    <li className="rdr-item" data-testid="rd-review" data-rating={review.overallRating}>
      <p className="rdr-item__head">
        <span className="rdr-stars" aria-hidden="true">{stars(review.overallRating)}</span>
        <b>{t('rv.rating.value', { n: review.overallRating, max: 5 }, locale)}</b>
        <span dir="auto">{review.authorName}</span>
        <span className="co-badge co-badge--muted">{t('rdr.verified', undefined, locale)}</span>
        <time dateTime={review.createdAt}>{date(review.createdAt)}</time>
        {review.edited && <small>{t('rdr.edited', undefined, locale)}</small>}
      </p>
      {review.tags.length > 0 && <ul className="rv-taglist" aria-label={t('rv.tags.title', undefined, locale)}>{review.tags.map((k) => <li key={k} className="co-badge co-badge--muted">{t(`rv.tag.${k}`, undefined, locale)}</li>)}</ul>}
      {review.title && <p className="rdr-item__title" dir="auto">{review.title}</p>}
      {review.text && <p className="rdr-item__text" dir="auto" data-testid="rd-review-text">{review.text}</p>}
      {review.response && (
        <div className="rdr-response" data-testid="rd-review-response">
          <p><b dir="auto">{t('rdr.responseFrom', { restaurant: review.response.restaurantName }, locale)}</b> <time dateTime={review.response.respondedAt}>{date(review.response.respondedAt)}</time>{review.response.edited && <small> · {t('rdr.edited', undefined, locale)}</small>}</p>
          <p dir="auto">{review.response.text}</p>
        </div>
      )}
      <p className="rdr-item__actions">
        {state === 'sent'
          ? <span role="status" data-testid="rd-review-reported">{t('rdr.report.sent', undefined, locale)}</span>
          : canReport
            ? <button type="button" className="cart-link" aria-expanded={open} onClick={() => { setOpen((v) => !v); setState('idle') }} data-testid="rd-review-report">{t('rdr.report', undefined, locale)}</button>
            : <Link to="/login" className="cart-link">{t('rdr.report.signIn', undefined, locale)}</Link>}
      </p>
      {open && state !== 'sent' && (
        <form className="rdr-report" onSubmit={(e) => { e.preventDefault(); void send() }} data-testid="rd-report-form">
          <label>{t('rdr.report.reason', undefined, locale)}
            <select value={reason} onChange={(e) => setReason(e.target.value as ReportReason)} data-testid="rd-report-reason">
              {REPORT_REASONS.map((k) => <option key={k} value={k}>{t(`rdr.reason.${k}`, undefined, locale)}</option>)}
            </select>
          </label>
          <label>{t('rdr.report.explanation', undefined, locale)}
            <textarea rows={2} maxLength={500} value={explanation} onChange={(e) => setExplanation(e.target.value)} dir="auto" />
          </label>
          <p className="rd-muted">{t('rdr.report.note', undefined, locale)}</p>
          {state === 'error' && <p className="rv-err" role="alert" data-testid="rd-report-error">{message}</p>}
          <div className="pay-actions">
            <button type="submit" className="btn btn--primary" disabled={state === 'sending'} data-testid="rd-report-submit">{t('rdr.report.send', undefined, locale)}</button>
            <button type="button" className="btn btn--outline" onClick={() => setOpen(false)}>{t('rv.action.cancel', undefined, locale)}</button>
          </div>
        </form>
      )}
    </li>
  )
}

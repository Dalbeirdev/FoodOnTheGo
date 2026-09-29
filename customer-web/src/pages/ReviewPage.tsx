import { useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import Header from '../components/Header'
import { useAccount } from '../account/AccountContext'
import { useAuth } from '../auth/AuthContext'
import { t, useLocale } from '../i18n/strings'
import type { Order, OrderRepository } from '../order/repositories'
import { orderRepositories } from '../order/useOrderConfirmation'
import { reviewRepositories } from '../review/mock/mockReview'
import type { ItemSentiment, Review, ReviewConfig, ReviewDraft, ReviewEligibility } from '../review/repositories'
import type { ReviewDeps } from '../review/useReviewEntry'
import { draftFromReview, emptyDraft, isValid, remainingChars, tagsForRating, validateReview } from '../review/reviewForm'
import './CartPage.css'
import './CheckoutPage.css'
import './OrderConfirmationPage.css'
import './MyOrdersPage.css'
import './ReviewPage.css'

/**
 * Rate your experience (Module 16). Canonical /order/:orderNumber/review — the only review route. A review is tied to an
 * eligible completed order the customer owns; the restaurant is derived from the order. One review per order (edits update
 * it). Drafts are kept locally so a failed submission never makes the customer retype. Nothing submitted here is public:
 * moderation happens in the backend later.
 */
type Deps = ReviewDeps & { orders?: OrderRepository }
type View = 'loading' | 'not_found' | 'ineligible' | 'form' | 'submitting' | 'submitted' | 'error'
const DRAFT_KEY = (id: string) => `fotg.review.draft.${id}`
const loadDraft = (id: string): ReviewDraft | null => { try { const raw = sessionStorage.getItem(DRAFT_KEY(id)); return raw ? (JSON.parse(raw) as ReviewDraft) : null } catch { return null } }
const saveDraft = (id: string, d: ReviewDraft) => { try { sessionStorage.setItem(DRAFT_KEY(id), JSON.stringify(d)) } catch { /* ignore */ } }
const clearDraft = (id: string) => { try { sessionStorage.removeItem(DRAFT_KEY(id)) } catch { /* ignore */ } }
const mockOffline = () => { try { return sessionStorage.getItem('fotg.mock.offline') === '1' } catch { return false } }
const StarIcon = ({ filled }: { filled: boolean }) => (<svg width="28" height="28" viewBox="0 0 24 24" fill={filled ? 'currentColor' : 'none'} stroke="currentColor" strokeWidth={1.8} strokeLinejoin="round" aria-hidden="true"><path d="m12 2.8 2.9 6 6.6.9-4.8 4.6 1.2 6.5L12 17.7l-5.9 3.1 1.2-6.5L2.5 9.7l6.6-.9z" /></svg>)
const HeartIcon = ({ filled }: { filled: boolean }) => (<svg width="16" height="16" viewBox="0 0 24 24" fill={filled ? 'currentColor' : 'none'} stroke="currentColor" strokeWidth={2} strokeLinejoin="round" aria-hidden="true"><path d="M12 21s-7-4.6-9.3-9.1C1.2 8.6 3.2 5 6.8 5c2 0 3.3 1.1 4.2 2.3C11.9 6.1 13.2 5 15.2 5c3.6 0 5.6 3.6 4.1 6.9C17 16.4 12 21 12 21z" /></svg>)

/** Accessible rating control: a radiogroup of N radios (labels "1 star" … "N stars"); selection is text + fill, never colour alone. */
function RatingInput({ name, value, config, onChange, size = 'lg', label, describedBy, invalid }: { name: string; value: number | null; config: ReviewConfig; onChange: (v: number) => void; size?: 'lg' | 'sm'; label: string; describedBy?: string; invalid?: boolean }) {
  const { locale } = useLocale()
  const values = Array.from({ length: config.scaleMax - config.scaleMin + 1 }, (_, i) => config.scaleMin + i)
  return (
    <fieldset className={`rv-rating rv-rating--${size}`} aria-describedby={describedBy} aria-invalid={invalid || undefined} data-testid={`rv-rating-${name}`}>
      <legend className={size === 'lg' ? 'rv-legend' : 'rv-legend rv-legend--sm'}>{label}</legend>
      <div className="rv-stars" role="radiogroup" aria-label={label}>
        {values.map((v) => (
          <label key={v} className={`rv-star ${value != null && v <= value ? 'is-on' : ''}`}>
            <input type="radio" name={`rv-${name}`} value={v} checked={value === v} onChange={() => onChange(v)} aria-label={t(v === 1 ? 'rv.star.one' : 'rv.star.many', { n: v, max: config.scaleMax }, locale)} />
            <StarIcon filled={value != null && v <= value} />
          </label>
        ))}
        <span className="rv-stars__value" aria-live="polite">{value == null ? t('rv.rating.none', undefined, locale) : t('rv.rating.value', { n: value, max: config.scaleMax }, locale)}</span>
      </div>
    </fieldset>
  )
}

export default function ReviewPage({ deps }: { deps?: Partial<Deps> }) {
  const { orderNumber = '' } = useParams()
  const { locale } = useLocale()
  const auth = useAuth(); const account = useAccount()
  const orders = deps?.orders ?? orderRepositories.orders
  const reviews = deps?.reviews ?? reviewRepositories.reviews; const eligibilitySvc = deps?.eligibility ?? reviewRepositories.eligibility; const configSvc = deps?.config ?? reviewRepositories.config
  const customerId = auth.user?.id ?? null
  const [view, setView] = useState<View>('loading')
  const [order, setOrder] = useState<Order | null>(null)
  const [config, setConfig] = useState<ReviewConfig | null>(null)
  const [eligibility, setEligibility] = useState<ReviewEligibility | null>(null)
  const [review, setReview] = useState<Review | null>(null)
  const [editing, setEditing] = useState(false)
  const [draft, setDraft] = useState<ReviewDraft | null>(null)
  const [showErrors, setShowErrors] = useState(false)
  const [online, setOnline] = useState(() => typeof navigator === 'undefined' || navigator.onLine)
  const [reload, setReload] = useState(0)
  const inFlight = useRef(false)
  const headingRef = useRef<HTMLHeadingElement>(null)
  useEffect(() => { document.title = `${t('rv.title', undefined, locale)} · FoodOnTheGo` }, [locale])
  useEffect(() => { const on = () => setOnline(true), off = () => setOnline(false); window.addEventListener('online', on); window.addEventListener('offline', off); return () => { window.removeEventListener('online', on); window.removeEventListener('offline', off) } }, [])
  useEffect(() => {
    let on = true; setView('loading')
    ;(async () => {
      if (!customerId) return
      try {
        const o = await orders.getByOrderNumber(orderNumber, customerId)
        if (!on) return
        if (!o) { setView('not_found'); return }
        const cfg = await configSvc.configFor(o); const el = await eligibilitySvc.check(o, customerId, cfg)
        if (!on) return
        setOrder(o); setConfig(cfg); setEligibility(el); setReview(el.existingReview)
        if (el.eligible) { setDraft(loadDraft(o.publicId) ?? emptyDraft(o)); setView('form') }
        else setView('ineligible')
      } catch { if (on) setView('error') }
    })()
    return () => { on = false }
  }, [orderNumber, customerId, reload]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { if (view === 'submitted' || view === 'ineligible' || view === 'error') headingRef.current?.focus() }, [view])

  const offline = !online || mockOffline()
  const update = (patch: Partial<ReviewDraft>) => { if (!draft || !order) return; const next = { ...draft, ...patch }; setDraft(next); saveDraft(order.publicId, next) }
  const toggleTag = (key: string) => draft && update({ tags: draft.tags.includes(key) ? draft.tags.filter((k) => k !== key) : [...draft.tags, key] })
  const setItem = (lineId: string, s: ItemSentiment) => draft && update({ itemFeedback: draft.itemFeedback.map((f) => (f.lineId === lineId ? { ...f, sentiment: f.sentiment === s ? null : s } : f)) })
  const startEdit = () => { if (!order || !review) return; setDraft(draftFromReview(review, order)); setEditing(true); setShowErrors(false); setView('form') }
  const submit = async () => {
    if (!order || !draft || !config) return
    setShowErrors(true)
    if (!isValid(draft, config)) return
    if (inFlight.current || offline) return // duplicate-click / offline guard (the mock is also idempotent per order)
    inFlight.current = true; setView('submitting')
    try {
      const r = editing && review ? await reviews.updateReview(review.reviewId, order.customerId, draft) : await reviews.submitReview(order, draft)
      clearDraft(order.publicId); setReview(r); setDraft(null); setEditing(false); setView('submitted')
    } catch { setView('error') }
    finally { inFlight.current = false }
  }
  const cancel = () => { if (order) clearDraft(order.publicId); setDraft(null); setShowErrors(false); setReload((n) => n + 1) }
  const errors = draft && config ? validateReview(draft, config) : {}
  const fav = order ? account.isFavorite(order.restaurant.id) : false
  const date = (iso: string) => new Date(iso).toLocaleDateString(locale, { dateStyle: 'medium' })

  const actions = order && (
    <section className="cart-card rv-next" aria-labelledby="rv-next-title" data-testid="rv-actions">
      <h2 id="rv-next-title">{t('rv.next.title', undefined, locale)}</h2>
      <p className="cart-muted">{t('rv.next.text', undefined, locale)}</p>
      <div className="pay-actions">
        <Link to={`/order/${order.orderNumber}#reorder`} className="btn btn--primary">{t('od.action.reorder', undefined, locale)}</Link>
        <Link to={`/order/${order.orderNumber}#receipt`} className="btn btn--outline">{t('oc.receipt.view', undefined, locale)}</Link>
        <button type="button" className={`btn btn--outline rv-fav ${fav ? 'is-on' : ''}`} aria-pressed={fav} onClick={() => { void account.toggleFavorite(order.restaurant.id) }}><HeartIcon filled={fav} /> {fav ? t('od.action.unfavorite', undefined, locale) : t('od.action.favorite', undefined, locale)}</button>
        <Link to="/help" className="btn btn--outline">{t('od.action.help', undefined, locale)}</Link>
        <Link to={`/restaurants/${order.restaurant.slug}`} className="btn btn--outline">{t('oc.action.viewRestaurant', undefined, locale)}</Link>
        <Link to="/restaurants" className="btn btn--outline">{t('mo.action.explore', undefined, locale)}</Link>
        <Link to="/plan-journey" className="btn btn--outline">{t('rv.next.journey', undefined, locale)}</Link>
      </div>
    </section>
  )
  const existingCard = review && config && (
    <section className="cart-card rv-existing" aria-labelledby="rv-existing-title" data-testid="rv-existing">
      <h2 id="rv-existing-title">{t('rv.existing.title', undefined, locale)}</h2>
      <p className="rv-existing__rating" data-testid="rv-existing-rating"><span aria-hidden="true">{'★'.repeat(review.overallRating)}{'☆'.repeat(Math.max(0, config.scaleMax - review.overallRating))}</span> <b>{t('rv.rating.value', { n: review.overallRating, max: config.scaleMax }, locale)}</b></p>
      {Object.keys(review.categoryRatings).length > 0 && <dl className="co-dl rv-cats">{config.categories.filter((c) => review.categoryRatings[c.key] != null).map((c) => <div key={c.key}><dt>{t(`rv.cat.${c.key}`, undefined, locale)}</dt><dd>{t('rv.rating.value', { n: review.categoryRatings[c.key], max: config.scaleMax }, locale)}</dd></div>)}</dl>}
      {review.tags.length > 0 && <ul className="rv-taglist" aria-label={t('rv.tags.title', undefined, locale)}>{review.tags.map((k) => <li key={k} className="co-badge co-badge--muted">{t(`rv.tag.${k}`, undefined, locale)}</li>)}</ul>}
      {review.itemFeedback.some((f) => f.sentiment) && <ul className="rv-items-summary">{review.itemFeedback.filter((f) => f.sentiment).map((f) => <li key={f.lineId}><span dir="auto">{f.itemName}</span> — {t(`rv.item.${f.sentiment}`, undefined, locale)}</li>)}</ul>}
      {review.text && <blockquote className="rv-text" dir="auto" data-testid="rv-existing-text">{review.text}</blockquote>}
      <p className="cart-muted rv-existing__meta">{t('rv.existing.meta', { date: date(review.updatedAt), status: t(`rv.status.${review.status}`, undefined, locale) }, locale)}</p>
      <p className="cart-muted">{t('rv.existing.moderation', undefined, locale)}</p>
    </section>
  )

  return (
    <>
      <Header />
      <main id="main" className="cart-page">
        <div className="cart-wrap rv-wrap">
          {view === 'loading' && <section className="cart-card" aria-busy="true"><p role="status">{t('rv.loading', undefined, locale)}</p></section>}
          {view === 'not_found' && (
            <section className="cart-card ocp-state ocp-state--warn" role="alert"><h1 className="ocp-state__title" ref={headingRef} tabIndex={-1}>{t('oc.notFound.title', undefined, locale)}</h1><p>{t('oc.notFound.text', { ref: orderNumber }, locale)}</p><div className="pay-actions"><Link to="/my-orders" className="btn btn--primary">{t('oc.action.myOrders', undefined, locale)}</Link><Link to="/help" className="btn btn--outline">{t('oc.action.help', undefined, locale)}</Link></div></section>
          )}
          {view === 'error' && !draft && (
            <section className="cart-card ocp-state ocp-state--error" role="alert"><h1 className="ocp-state__title" ref={headingRef} tabIndex={-1}>{t('oc.failed.title', undefined, locale)}</h1><p>{t('oc.failed.text', undefined, locale)}</p><div className="pay-actions"><button type="button" className="btn btn--primary" onClick={() => setReload((n) => n + 1)}>{t('oc.action.retry', undefined, locale)}</button></div></section>
          )}
          {view === 'ineligible' && order && eligibility && (
            <>
              <section className="cart-card rv-head" aria-labelledby="rv-title">
                <p className="cart-eyebrow">{t('rv.eyebrow', undefined, locale)}</p>
                <h1 id="rv-title" ref={headingRef} tabIndex={-1}>{review ? t('rv.reviewed.title', undefined, locale) : t('rv.ineligible.title', undefined, locale)}</h1>
                <p className="cart-muted"><span dir="auto">{order.restaurant.name}</span> · <span className="ocp-number">{order.orderNumber}</span></p>
                {!review && <p className="cart-notice cart-notice--info" role="status" data-testid="rv-ineligible">{t(`rv.ineligible.${eligibility.reason}`, undefined, locale)}</p>}
                {review && <p className="cart-muted">{t('rv.reviewed.text', undefined, locale)}</p>}
                <div className="pay-actions">
                  {review && eligibility.canEdit && <button type="button" className="btn btn--primary" onClick={startEdit} data-testid="rv-edit">{t('rv.action.edit', undefined, locale)}</button>}
                  {!review && eligibility.reason === 'not_completed' && <Link to={`/order-tracking/${order.orderNumber}`} className="btn btn--primary">{t('oc.action.track', undefined, locale)}</Link>}
                  <Link to={`/order/${order.orderNumber}`} className="btn btn--outline">{t('oc.action.details', undefined, locale)}</Link>
                  <Link to="/my-orders" className="btn btn--outline">{t('oc.action.myOrders', undefined, locale)}</Link>
                </div>
              </section>
              {existingCard}
              {review && actions}
            </>
          )}
          {(view === 'form' || view === 'submitting' || (view === 'error' && draft)) && order && config && draft && (
            <form className="rv-form" noValidate onSubmit={(e) => { e.preventDefault(); void submit() }} aria-busy={view === 'submitting'} data-testid="rv-form">
              <section className="cart-card rv-head" aria-labelledby="rv-title">
                <p className="cart-eyebrow">{t('rv.eyebrow', undefined, locale)}</p>
                <h1 id="rv-title">{editing ? t('rv.edit.title', undefined, locale) : t('rv.title', undefined, locale)}</h1>
                <p className="cart-muted"><span dir="auto">{order.restaurant.name}</span> · <span className="ocp-number">{order.orderNumber}</span> · {t('oc.items', { count: order.items.reduce((a, i) => a + i.quantity, 0) }, locale)}</p>
                <p className="cart-muted">{t('rv.lead', undefined, locale)}</p>
                {offline && <p className="cart-notice cart-notice--warn" role="status" data-testid="rv-offline">{t('rv.offline', undefined, locale)}</p>}
                {view === 'error' && <p className="cart-notice cart-notice--error" role="alert" data-testid="rv-error">{t('rv.error.text', undefined, locale)}</p>}
              </section>
              <section className="cart-card" aria-labelledby="rv-overall-title">
                <h2 id="rv-overall-title">{t('rv.overall.title', undefined, locale)} <span className="rv-req">{t('rv.required', undefined, locale)}</span></h2>
                <RatingInput name="overall" value={draft.overallRating} config={config} onChange={(v) => update({ overallRating: v })} label={t('rv.overall.label', undefined, locale)} describedBy={showErrors && errors.overallRating ? 'rv-overall-err' : undefined} invalid={showErrors && !!errors.overallRating} />
                {showErrors && errors.overallRating && <p id="rv-overall-err" className="rv-err" role="alert" data-testid="rv-overall-error">{t('rv.error.rating', undefined, locale)}</p>}
              </section>
              {config.categories.length > 0 && (
                <section className="cart-card" aria-labelledby="rv-cats-title">
                  <h2 id="rv-cats-title">{t('rv.categories.title', undefined, locale)} <span className="rv-opt">{t('rv.optional', undefined, locale)}</span></h2>
                  <div className="rv-cats-grid">{config.categories.map((c) => <RatingInput key={c.key} name={`cat-${c.key}`} size="sm" value={draft.categoryRatings[c.key] ?? null} config={config} onChange={(v) => update({ categoryRatings: { ...draft.categoryRatings, [c.key]: v } })} label={t(`rv.cat.${c.key}`, undefined, locale)} />)}</div>
                </section>
              )}
              <section className="cart-card" aria-labelledby="rv-tags-title">
                <h2 id="rv-tags-title">{t('rv.tags.title', undefined, locale)} <span className="rv-opt">{t('rv.optional', undefined, locale)}</span></h2>
                <p className="cart-muted">{t('rv.tags.text', undefined, locale)}</p>
                <div className="rv-tags" role="group" aria-label={t('rv.tags.title', undefined, locale)}>
                  {tagsForRating(config, draft.overallRating).map((tag) => <button key={tag.key} type="button" className={`mo-chip rv-tag rv-tag--${tag.sentiment} ${draft.tags.includes(tag.key) ? 'is-on' : ''}`} aria-pressed={draft.tags.includes(tag.key)} onClick={() => toggleTag(tag.key)} data-testid="rv-tag">{t(`rv.tag.${tag.key}`, undefined, locale)}</button>)}
                </div>
              </section>
              {config.itemFeedbackEnabled && draft.itemFeedback.length > 0 && (
                <section className="cart-card" aria-labelledby="rv-items-title">
                  <h2 id="rv-items-title">{t('rv.items.title', undefined, locale)} <span className="rv-opt">{t('rv.optional', undefined, locale)}</span></h2>
                  <ul className="rv-items">
                    {draft.itemFeedback.map((f) => (
                      <li key={f.lineId} className="rv-item">
                        <span className="rv-item__name" dir="auto">{f.itemName}</span>
                        <span className="rv-item__btns" role="group" aria-label={f.itemName}>
                          <button type="button" className={`mo-chip ${f.sentiment === 'liked' ? 'is-on' : ''}`} aria-pressed={f.sentiment === 'liked'} onClick={() => setItem(f.lineId, 'liked')}>{t('rv.item.liked', undefined, locale)}</button>
                          <button type="button" className={`mo-chip ${f.sentiment === 'disliked' ? 'is-on' : ''}`} aria-pressed={f.sentiment === 'disliked'} onClick={() => setItem(f.lineId, 'disliked')}>{t('rv.item.disliked', undefined, locale)}</button>
                        </span>
                      </li>
                    ))}
                  </ul>
                </section>
              )}
              <section className="cart-card" aria-labelledby="rv-text-title">
                <h2 id="rv-text-title"><label htmlFor="rv-text">{t('rv.text.title', undefined, locale)}</label> <span className="rv-opt">{t('rv.optional', undefined, locale)}</span></h2>
                <textarea id="rv-text" className="rv-textarea" dir="auto" rows={5} value={draft.text} maxLength={config.textMaxLength * 2} onChange={(e) => update({ text: e.target.value })} aria-describedby="rv-text-hint rv-text-count" aria-invalid={!!errors.text || undefined} placeholder={t('rv.text.placeholder', undefined, locale)} />
                <p id="rv-text-hint" className="cart-muted">{t('rv.text.hint', undefined, locale)}</p>
                <p id="rv-text-count" className={`cart-muted rv-count ${errors.text ? 'rv-count--over' : ''}`} aria-live="polite" data-testid="rv-count">{remainingChars(draft.text, config) >= 0 ? t('rv.text.remaining', { n: remainingChars(draft.text, config) }, locale) : t('rv.text.over', { n: -remainingChars(draft.text, config) }, locale)}</p>
                {errors.text && <p className="rv-err" role="alert">{t('rv.error.textLength', { max: config.textMaxLength }, locale)}</p>}
              </section>
              <section className="cart-card rv-submit">
                <p className="cart-muted">{t('rv.privacy', undefined, locale)}</p>
                <div className="pay-actions">
                  <button type="submit" className="btn btn--primary cart-proceed" disabled={view === 'submitting' || offline} aria-disabled={view === 'submitting' || offline} data-testid="rv-submit">{view === 'submitting' ? t('rv.action.submitting', undefined, locale) : editing ? t('rv.action.update', undefined, locale) : t('rv.action.submit', undefined, locale)}</button>
                  {view === 'error' && <button type="button" className="btn btn--outline" onClick={() => { void submit() }} data-testid="rv-retry">{t('oc.action.retry', undefined, locale)}</button>}
                  <button type="button" className="btn btn--outline" onClick={cancel} data-testid="rv-cancel">{t('rv.action.cancel', undefined, locale)}</button>
                </div>
              </section>
            </form>
          )}
          {view === 'submitted' && order && review && (
            <>
              <section className="cart-card ocp-state ocp-state--ok rv-done" role="status" aria-live="polite" data-testid="rv-submitted">
                <h1 className="ocp-state__title" ref={headingRef} tabIndex={-1}>{t('rv.done.title', undefined, locale)}</h1>
                <p>{t('rv.done.text', { restaurant: order.restaurant.name }, locale)}</p>
                <p className="cart-muted">{t('rv.done.note', undefined, locale)}</p>
                <div className="pay-actions">
                  {eligibility && config?.editEnabled && <button type="button" className="btn btn--outline" onClick={startEdit} data-testid="rv-edit">{t('rv.action.edit', undefined, locale)}</button>}
                  <Link to={`/order/${order.orderNumber}`} className="btn btn--outline">{t('oc.action.details', undefined, locale)}</Link>
                </div>
              </section>
              {existingCard}
              {actions}
            </>
          )}
        </div>
      </main>
    </>
  )
}

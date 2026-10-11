import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import Header from '../../components/Header'
import AccountSidebar from '../../components/AccountSidebar'
import { EmptyState, ErrorState, LoadingState } from '../../components/AccountStates'
import { StarIcon } from '../../components/Icons'
import { useAuth } from '../../auth/AuthContext'
import { t, useLocale } from '../../i18n/strings'
import type { Review, ReviewRepository } from '../../review/repositories'
import { reviewRepositories } from '../../review/reviewRepositories'
import './AccountPage.css'
import '../CheckoutPage.css'
import '../ReviewPage.css'
import '../../review/RestaurantReviews.css'

/**
 * My reviews (Module 34): the reviews the signed-in customer wrote, newest first — where each one stands (public,
 * waiting for a check, not shown), the restaurant's reply, and the way back to it to change or withdraw it. A review
 * always belongs to an order; writing one starts from that order.
 */
export default function MyReviewsPage({ repo }: { repo?: ReviewRepository }) {
  const reviews = repo ?? reviewRepositories.reviews
  const { locale } = useLocale()
  const auth = useAuth()
  const customerId = auth.user?.id ?? null
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading')
  const [items, setItems] = useState<Review[]>([])
  const [reload, setReload] = useState(0)
  useEffect(() => { document.title = `${t('mr.title', undefined, locale)} · FoodOnTheGo` }, [locale])
  useEffect(() => {
    let on = true
    if (!customerId) return
    reviews.listMine(customerId).then((list) => { if (on) { setItems(list); setState('ready') } }).catch(() => { if (on) setState('error') })
    return () => { on = false }
  }, [customerId, reload]) // eslint-disable-line react-hooks/exhaustive-deps
  const date = (iso: string) => new Date(iso).toLocaleDateString(locale, { dateStyle: 'medium' })

  return (
    <>
      <Header />
      <main id="main" className="ac">
        <div className="ac__grid">
          <AccountSidebar />
          <div className="ac__main">
            <div className="ac-head"><div><h1>{t('mr.title', undefined, locale)}</h1><p>{t('mr.lead', undefined, locale)}</p></div></div>
            {state === 'loading' && <LoadingState label={t('mr.loading', undefined, locale)} />}
            {state === 'error' && <ErrorState message={t('mr.error', undefined, locale)} onRetry={() => { setState('loading'); setReload((n) => n + 1) }} />}
            {state === 'ready' && items.length === 0 && (
              <EmptyState icon={<StarIcon size={34} />} title={t('mr.empty.title', undefined, locale)} text={t('mr.empty.text', undefined, locale)} action={<Link to="/my-orders" className="btn btn--primary">{t('oc.action.myOrders', undefined, locale)}</Link>} />
            )}
            {state === 'ready' && items.length > 0 && (
              <ul className="mr-list" data-testid="mr-list">
                {items.map((r) => (
                  <li key={r.reviewId} className="ac-card mr-item" data-testid="mr-item" data-status={r.status}>
                    <div className="mr-item__head">
                      <h2 dir="auto">{r.restaurantName || r.orderNumber}</h2>
                      <span className={`co-badge ${r.status === 'PUBLISHED' ? 'co-badge--ok' : 'co-badge--muted'}`} data-testid="mr-status">{t(`rv.status.${r.status}`, undefined, locale)}</span>
                    </div>
                    <p className="mr-item__meta">
                      <span className="rdr-stars" aria-hidden="true">{'★'.repeat(r.overallRating)}{'☆'.repeat(Math.max(0, 5 - r.overallRating))}</span>
                      <b>{t('rv.rating.value', { n: r.overallRating, max: 5 }, locale)}</b>
                      <span className="ocp-number">{r.orderNumber}</span>
                      <time dateTime={r.createdAt}>{date(r.createdAt)}</time>
                    </p>
                    {r.tags.length > 0 && <ul className="rv-taglist" aria-label={t('rv.tags.title', undefined, locale)}>{r.tags.map((k) => <li key={k} className="co-badge co-badge--muted">{t(`rv.tag.${k}`, undefined, locale)}</li>)}</ul>}
                    {r.text && <p className="mr-item__text" dir="auto">{r.text}</p>}
                    {r.status !== 'PUBLISHED' && <p className="cart-muted">{t(`mr.statusNote.${r.status}`, undefined, locale)}</p>}
                    {r.restaurantResponse && (
                      <div className="rdr-response" data-testid="mr-response">
                        <p><b dir="auto">{t('rdr.responseFrom', { restaurant: r.restaurantResponse.responderName }, locale)}</b> {r.restaurantResponse.respondedAt && <time dateTime={r.restaurantResponse.respondedAt}>{date(r.restaurantResponse.respondedAt)}</time>}</p>
                        <p dir="auto">{r.restaurantResponse.text}</p>
                      </div>
                    )}
                    <div className="pay-actions">
                      <Link to={`/order/${r.orderNumber}/review`} className="btn btn--outline" data-testid="mr-open">{r.canEdit ? t('rv.entry.edit', undefined, locale) : t('rv.entry.view', undefined, locale)}</Link>
                      <Link to={`/order/${r.orderNumber}`} className="btn btn--outline">{t('oc.action.details', undefined, locale)}</Link>
                      {r.restaurantSlug && <Link to={`/restaurants/${r.restaurantSlug}`} className="btn btn--outline">{t('oc.action.viewRestaurant', undefined, locale)}</Link>}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      </main>
    </>
  )
}

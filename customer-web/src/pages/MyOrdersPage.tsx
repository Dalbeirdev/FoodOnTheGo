import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import Header from '../components/Header'
import { useAuth } from '../auth/AuthContext'
import { formatLocalTime, formatMoney, zoneLabel } from '../i18n/format'
import { t, useLocale } from '../i18n/strings'
import { groupOf, isReviewable, isTrackable } from '../order/history'
import { MockOrderRepository } from '../order/mock/mockOrder'
import type { OrderGroup, OrderRepository, OrderSort, OrderSummary } from '../order/repositories'
import { orderRepositories } from '../order/useOrderConfirmation'
import { formatLocalDate } from '../pickup/time'
import './CartPage.css'
import './CheckoutPage.css'
import './OrderConfirmationPage.css'
import './MyOrdersPage.css'

/**
 * My Orders (Module 15). Canonical /my-orders (authenticated). Lightweight summaries only — details load per order.
 * Groups (ongoing / completed / cancelled) are UI mappings of the order status; the payment status stays separate.
 * Search, filters, sort and cursor paging are repository queries so the backend can page the same way.
 */
const PAGE = 5
const GROUPS: OrderGroup[] = ['all', 'ongoing', 'completed', 'cancelled']
const DEV = import.meta.env.DEV
type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const, 'aria-hidden': true })
const SearchIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>)
const BagIcon = ({ size = 40 }: P) => (<svg {...stroke(size)}><path d="M6 8h12l1 12H5L6 8Z" /><path d="M9 8V6a3 3 0 0 1 6 0v2" /></svg>)

export default function MyOrdersPage({ repository }: { repository?: OrderRepository }) {
  const repo = repository ?? orderRepositories.orders
  const auth = useAuth()
  const { locale } = useLocale()
  const customerId = auth.user?.id ?? null
  const [group, setGroup] = useState<OrderGroup>('all')
  const [query, setQuery] = useState('')
  const [sort, setSort] = useState<OrderSort>('newest')
  const [items, setItems] = useState<OrderSummary[]>([])
  const [cursor, setCursor] = useState<string | null>(null)
  const [total, setTotal] = useState(0)
  const [state, setState] = useState<'loading' | 'ready' | 'error' | 'more'>('loading')
  const [seq, setSeq] = useState(0)
  const debounced = useDebounced(query, 250)
  const run = useRef(0)
  useEffect(() => { document.title = `${t('mo.title', undefined, locale)} · FoodOnTheGo` }, [locale])

  const load = useCallback(async (append: boolean) => {
    if (!customerId) return
    const my = ++run.current
    setState(append ? 'more' : 'loading')
    try {
      const page = await repo.listSummaries(customerId, { group, query: debounced, sort, cursor: append ? cursor : null, limit: PAGE })
      if (my !== run.current) return
      setItems((prev) => (append ? [...prev, ...page.items] : page.items)); setCursor(page.nextCursor); setTotal(page.total); setState('ready')
    } catch { if (my === run.current) setState('error') }
  }, [repo, customerId, group, debounced, sort, cursor])

  useEffect(() => { void load(false) }, [customerId, group, debounced, sort, seq, repo]) // eslint-disable-line react-hooks/exhaustive-deps
  const counts = useMemo(() => ({ shown: items.length, total }), [items.length, total])

  return (
    <>
      <Header />
      <main id="main" className="cart mo">
        <div className="cart__grid cart__grid--single">
          <div className="cart__main">
            <p className="cart-eyebrow">{t('mo.eyebrow', undefined, locale)}</p>
            <h1 className="cart-head">{t('mo.title', undefined, locale)}</h1>
            <p className="cart-muted">{t('mo.lead', undefined, locale)}</p>

            <section className="cart-card mo-tools" aria-label={t('mo.tools', undefined, locale)}>
              <label className="mo-search"><SearchIcon /><span className="trk-sr">{t('mo.search', undefined, locale)}</span><input type="search" value={query} onChange={(e) => setQuery(e.target.value)} placeholder={t('mo.search.placeholder', undefined, locale)} aria-label={t('mo.search', undefined, locale)} /></label>
              <div className="mo-filters" role="group" aria-label={t('mo.filter', undefined, locale)}>
                {GROUPS.map((g) => <button key={g} type="button" className={`mo-chip ${group === g ? 'is-on' : ''}`} aria-pressed={group === g} onClick={() => setGroup(g)}>{t(`mo.group.${g}`, undefined, locale)}</button>)}
              </div>
              <label className="mo-sort">{t('mo.sort', undefined, locale)}
                <select value={sort} onChange={(e) => setSort(e.target.value as OrderSort)} aria-label={t('mo.sort', undefined, locale)}><option value="newest">{t('mo.sort.newest', undefined, locale)}</option><option value="oldest">{t('mo.sort.oldest', undefined, locale)}</option></select>
              </label>
            </section>

            {state === 'loading' && <section className="cart-card ocp-skeleton" role="status" aria-live="polite" aria-busy="true"><p className="cart-muted">{t('mo.loading', undefined, locale)}</p><div className="ocp-skel ocp-skel--block" /><div className="ocp-skel ocp-skel--block" /></section>}
            {state === 'error' && (
              <section className="cart-card ocp-state ocp-state--error" role="alert"><h2 className="ocp-state__title">{t('mo.error.title', undefined, locale)}</h2><p>{t('mo.error.text', undefined, locale)}</p><div className="pay-actions"><button type="button" className="btn btn--primary" onClick={() => setSeq((s) => s + 1)}>{t('mo.action.retry', undefined, locale)}</button><Link to="/help" className="btn btn--outline">{t('oc.action.help', undefined, locale)}</Link></div></section>
            )}
            {(state === 'ready' || state === 'more') && items.length === 0 && (
              <section className="cart-card mo-empty" role="status" data-testid="mo-empty">
                <div className="mo-empty__icon" aria-hidden="true"><BagIcon /></div>
                <h2>{group === 'all' && !debounced ? t('mo.empty.title', undefined, locale) : t('mo.empty.filtered', { group: t(`mo.group.${group}`, undefined, locale).toLowerCase() }, locale)}</h2>
                <p className="cart-muted">{group === 'all' && !debounced ? t('mo.empty.text', undefined, locale) : t('mo.empty.filteredText', undefined, locale)}</p>
                <div className="pay-actions ocp-primary">
                  {group === 'all' && !debounced ? (<><Link to="/restaurants" className="btn btn--primary">{t('mo.action.explore', undefined, locale)}</Link><Link to="/plan-journey" className="btn btn--outline">{t('mo.action.plan', undefined, locale)}</Link></>) : (<button type="button" className="btn btn--outline" onClick={() => { setGroup('all'); setQuery('') }}>{t('mo.action.clearFilters', undefined, locale)}</button>)}
                </div>
              </section>
            )}
            {(state === 'ready' || state === 'more') && items.length > 0 && (
              <>
                <p className="cart-muted mo-count" role="status" data-testid="mo-count">{t('mo.count', { shown: counts.shown, total: counts.total }, locale)}</p>
                <ul className="mo-list" data-testid="mo-list">
                  {items.map((o) => <OrderCard key={o.publicId} o={o} locale={locale} />)}
                </ul>
                {cursor && <div className="pay-actions ocp-primary"><button type="button" className="btn btn--outline" disabled={state === 'more'} onClick={() => { void load(true) }} data-testid="mo-more">{state === 'more' ? t('mo.loadingMore', undefined, locale) : t('mo.action.more', undefined, locale)}</button></div>}
              </>
            )}
            {DEV && customerId && (
              <section className="cart-card pay-dev" aria-labelledby="mo-dev"><h2 id="mo-dev">{t('pay.dev.title', undefined, locale)}</h2><p className="cart-muted">{t('mo.dev.text', undefined, locale)}</p><div className="pay-actions"><button type="button" className="btn btn--outline" onClick={async () => { const r = repo as MockOrderRepository; if (r.seedDemoHistory) { await r.seedDemoHistory(customerId, 12); setSeq((s) => s + 1) } }}>{t('mo.dev.seed', undefined, locale)}</button></div></section>
            )}
          </div>
        </div>
      </main>
    </>
  )
}

function OrderCard({ o, locale }: { o: OrderSummary; locale: string }) {
  const g = groupOf(o.orderStatus)
  const tz = o.restaurantTimezone
  return (
    <li className={`cart-card mo-card mo-card--${g}`} data-testid="mo-card" data-group={g} aria-labelledby={`mo-${o.publicId}`}>
      <div className="mo-card__head">
        <div>
          <h2 id={`mo-${o.publicId}`} className="mo-card__rest">{o.restaurantName}</h2>
          <p className="cart-muted mo-card__meta"><span className="ocp-number">{o.orderNumber}</span> · {formatLocalDate(o.createdAt, tz, locale)}</p>
        </div>
        <div className="mo-card__badges">
          <span className={`co-badge ${g === 'cancelled' ? 'co-badge--bad' : g === 'completed' ? 'co-badge--muted' : 'co-badge--ok'}`} data-testid="mo-order-status">{t(`oc.status.${o.orderStatus}`, undefined, locale)}</span>
          <span className={`co-badge ${o.paymentStatus === 'PAID' ? 'co-badge--ok' : 'co-badge--muted'}`} data-testid="mo-payment-status">{t(`oc.pay.${o.paymentStatus}`, undefined, locale)}</span>
        </div>
      </div>
      <dl className="co-dl mo-card__dl">
        <div><dt>{t('pickup.time', undefined, locale)}</dt><dd>{formatLocalDate(o.pickupAt, tz, locale)} · {formatLocalTime(o.pickupAt, tz, locale)} {zoneLabel(o.pickupAt, tz, locale)}<small>{tz}</small></dd></div>
        <div><dt>{t('oc.items', { count: o.itemCount }, locale)}</dt><dd>{o.itemPreview}</dd></div>
        <div><dt>{t('oc.total', undefined, locale)}</dt><dd><b>{formatMoney(o.totalMinor, o.currency, locale)}</b> <span className="co-badge co-badge--muted">{o.currency}</span></dd></div>
      </dl>
      <div className="pay-actions mo-card__actions">
        {isTrackable(o.orderStatus) && <Link to={`/order-tracking/${o.orderNumber}`} className="btn btn--primary">{t('oc.action.track', undefined, locale)}</Link>}
        {o.orderStatus === 'PAYMENT_PENDING' && <Link to={`/order-confirmation/${o.orderNumber}`} className="btn btn--primary">{t('oc.action.checkStatus', undefined, locale)}</Link>}
        <Link to={`/order/${o.orderNumber}`} className="btn btn--outline">{t('oc.action.details', undefined, locale)}</Link>
        {o.reorderEligible && <Link to={`/order/${o.orderNumber}#reorder`} className="btn btn--outline">{t('od.action.reorder', undefined, locale)}</Link>}
        {g !== 'ongoing' && <Link to={`/order/${o.orderNumber}#receipt`} className="btn btn--outline">{t('track.action.receipt', undefined, locale)}</Link>}
        {isReviewable(o.orderStatus) && <button type="button" className="btn btn--outline" disabled title={t('track.rate.pending', undefined, locale)}>{t('track.action.rate', undefined, locale)}</button>}
        <Link to="/help" className="cart-link mo-card__help">{t('od.action.help', undefined, locale)}</Link>
      </div>
    </li>
  )
}

function useDebounced<T>(v: T, ms: number): T {
  const [d, setD] = useState(v)
  useEffect(() => { const tm = setTimeout(() => setD(v), ms); return () => clearTimeout(tm) }, [v, ms])
  return d
}

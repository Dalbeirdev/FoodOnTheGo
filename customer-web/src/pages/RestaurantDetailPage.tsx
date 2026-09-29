import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom'
import Header from '../components/Header'
import CartBar from '../components/CartBar'
import { ClockIcon, PinIcon, StarIcon } from '../components/Icons'
import { useCart } from '../cart/CartContext'
import { useAccount } from '../account/AccountContext'
import { useAuth } from '../auth/AuthContext'
import { useJourney } from '../journey/JourneyContext'
import { restaurantRepository } from '../repositories'
import type { Restaurant, RouteRestaurantResult } from '../repositories/types'
import { computeAvailability, routeContextFor } from '../repositories/mock/restaurants'
import { menuRepository } from '../menu/mock/mockMenu'
import type { MenuCategory, MenuItem } from '../menu/repositories'
import { formatDistance, formatLocalTime, formatMinutes, formatMoney, localClock, priceLevelLabel, zoneLabel } from '../i18n/format'
import { resolveUnitSystem } from '../i18n/markets'
import { t, useLocale } from '../i18n/strings'
import { mapProvider } from '../map/MapProvider'
import { reviewRepositories } from '../review/mock/mockReview'
import type { RestaurantReviewSummary } from '../review/repositories'
import './RestaurantDetailPage.css'

type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const })
const BackIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M19 12H5M11 6l-6 6 6 6" /></svg>)
const HeartIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M12 21s-7.5-4.6-9.5-9.3C1 8 3.5 4.5 7 4.5c2 0 3.5 1 5 2.8 1.5-1.8 3-2.8 5-2.8 3.5 0 6 3.5 4.5 7.2C19.5 16.4 12 21 12 21Z" /></svg>)
const ShareIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><circle cx="18" cy="5" r="2.5" /><circle cx="6" cy="12" r="2.5" /><circle cx="18" cy="19" r="2.5" /><path d="m8.2 10.8 7.6-4.6M8.2 13.2l7.6 4.6" /></svg>)
const CameraIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M4 8h3l2-3h6l2 3h3v11H4z" /><circle cx="12" cy="13" r="3.5" /></svg>)
const CarIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M5 11l1.5-4.5A2 2 0 0 1 8.4 5h7.2a2 2 0 0 1 1.9 1.5L19 11M4 11h16v6H4zM6 17v2M18 17v2" /><circle cx="7.5" cy="14" r="1" /><circle cx="16.5" cy="14" r="1" /></svg>)
const SearchIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>)
const LeafIcon = ({ size = 14 }: P) => (<svg {...stroke(size)}><path d="M5 19c0-8 4-13 14-14 0 10-5 14-13 14M5 19l6-6" /></svg>)
const CloseIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M6 6l12 12M18 6 6 18" /></svg>)
const ChevronL = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="m15 6-6 6 6 6" /></svg>)
const ChevronR = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="m9 6 6 6-6 6" /></svg>)

function Img({ src, fallback, alt = '' }: { src: string; fallback: string; alt?: string }) {
  return (<span className="rd-img"><img src={src} alt={alt} loading="lazy" onError={(e) => { e.currentTarget.style.display = 'none' }} /><span className="rd-img__fallback" aria-hidden="true">{fallback}</span></span>)
}

const initials = (name: string) => name.split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase()
const timeOf = (hhmm: string, locale: string) => { const [h, m] = hhmm.split(':').map(Number); return new Intl.DateTimeFormat(locale, { hour: 'numeric', minute: '2-digit' }).format(new Date(2000, 0, 1, h, m)) }
const weekdayName = (day: number, locale: string, style: 'long' | 'short' = 'long') => new Intl.DateTimeFormat(locale, { weekday: style }).format(new Date(2023, 0, 1 + day)) // 2023-01-01 is a Sunday
const DEFAULT_SHOWN = 8

type TabKey = 'menu' | 'info' | 'photos'
type LoadState<T> = { status: 'loading' | 'ready' | 'error' | 'notfound'; data: T | null; error?: string }

/**
 * Restaurant Details (approved Design A): hero + info card + Menu / Info / Photos tabs and a sidebar
 * with the development map shell, opening hours and features. All data flows through
 * RestaurantRepository / MenuRepository; every number is locale / currency / zone aware.
 */
export default function RestaurantDetailPage() {
  const { id: slug = '' } = useParams()
  const { locale, unitPreference } = useLocale()
  const navigate = useNavigate()
  const location = useLocation()
  const cart = useCart()
  const { isFavorite, toggleFavorite } = useAccount()
  const { isAuthenticated } = useAuth()
  const journeyApi = useJourney()

  const [rest, setRest] = useState<LoadState<Restaurant>>({ status: 'loading', data: null })
  const [tab, setTab] = useState<TabKey>('menu')
  const [copied, setCopied] = useState(false)
  const [lightbox, setLightbox] = useState<number | null>(null)
  // Lightbox keyboard support: Escape closes, arrows navigate, focus moves to the Close button on open.
  useEffect(() => {
    if (lightbox === null) return
    const count = photosRef.current
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setLightbox(null)
      else if (e.key === 'ArrowRight' && count > 1) setLightbox((i) => (i === null ? i : (i + 1) % count))
      else if (e.key === 'ArrowLeft' && count > 1) setLightbox((i) => (i === null ? i : (i + count - 1) % count))
    }
    document.addEventListener('keydown', onKey)
    const closeBtn = document.querySelector<HTMLButtonElement>('.rd-lightbox__close'); closeBtn?.focus()
    return () => document.removeEventListener('keydown', onKey)
  }, [lightbox])
  const photosRef = useRef(0)

  // ---- restaurant
  const loadRestaurant = useCallback(async () => {
    setRest({ status: 'loading', data: null })
    try {
      const r = await restaurantRepository.getRestaurantBySlug(slug)
      if (!r) { setRest({ status: 'notfound', data: null }); return }
      setRest({ status: 'ready', data: r })
    } catch (e) { setRest({ status: 'error', data: null, error: e instanceof Error ? e.message : 'Something went wrong.' }) }
  }, [slug])
  useEffect(() => { void loadRestaurant() }, [loadRestaurant])
  const r = rest.data

  // SEO basics — page title, description, canonical URL (no fabricated structured data)
  useEffect(() => {
    if (!r) return
    const prev = document.title
    document.title = `${r.name} · FoodOnTheGo`
    const meta = document.querySelector('meta[name="description"]') ?? Object.assign(document.createElement('meta'), { name: 'description' })
    if (!meta.parentNode) document.head.appendChild(meta)
    meta.setAttribute('content', `${r.name} — ${r.cuisines.join(', ')}. ${r.address.formatted}. Order ahead and pick up on your route with FoodOnTheGo.`)
    const link = (document.querySelector('link[rel="canonical"]') as HTMLLinkElement | null) ?? Object.assign(document.createElement('link'), { rel: 'canonical' })
    if (!link.parentNode) document.head.appendChild(link)
    link.href = `${window.location.origin}/restaurants/${r.slug}`
    return () => { document.title = prev }
  }, [r])

  // ---- journey context (only when a journey with a route is active)
  const journey = journeyApi.journey?.route ? journeyApi.journey : null
  const route: RouteRestaurantResult | null = useMemo(() => (r && journey ? routeContextFor(r, journey) : null), [r, journey])
  const availability = useMemo(() => (r ? computeAvailability(r, new Date().toISOString()) : null), [r])
  const units = resolveUnitSystem(unitPreference, r?.countryCode)

  // ---- menu
  const [cats, setCats] = useState<LoadState<MenuCategory[]>>({ status: 'loading', data: null })
  const [items, setItems] = useState<MenuItem[]>([])
  const [menuStatus, setMenuStatus] = useState<'loading' | 'ready' | 'error' | 'updating'>('loading')
  const [dietaryOptions, setDietaryOptions] = useState<string[]>([])
  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')
  const [dietary, setDietary] = useState<string[]>([])
  const [availableOnly, setAvailableOnly] = useState(false)
  const [activeCat, setActiveCat] = useState<string | null>(null)
  const [expanded, setExpanded] = useState<Record<string, boolean>>({})
  const seq = useRef(0)
  useEffect(() => { const h = setTimeout(() => setDebounced(search), 250); return () => clearTimeout(h) }, [search])

  const loadMenu = useCallback(async (id: string, first: boolean) => {
    const my = ++seq.current
    if (first) { setCats({ status: 'loading', data: null }); setMenuStatus('loading') } else setMenuStatus('updating')
    try {
      const [c, page, tags] = await Promise.all([first ? menuRepository.getCategories(id) : Promise.resolve(cats.data ?? []), menuRepository.getItems(id, { search: debounced, dietary, availableOnly, limit: 500 }), first ? menuRepository.getDietaryTags(id) : Promise.resolve(dietaryOptions)])
      if (my !== seq.current) return
      if (first) { setCats({ status: 'ready', data: c }); setDietaryOptions(tags) }
      setItems(page.items); setMenuStatus('ready')
    } catch (e) {
      if (my !== seq.current) return
      if (first) setCats({ status: 'error', data: null, error: e instanceof Error ? e.message : 'Menu failed to load.' })
      setMenuStatus('error')
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced, dietary, availableOnly])
  useEffect(() => { if (r) void loadMenu(r.id, true) }, [r]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { if (r && cats.status === 'ready') void loadMenu(r.id, false) }, [debounced, dietary, availableOnly]) // eslint-disable-line react-hooks/exhaustive-deps

  const categories = cats.data ?? []
  const byCat = useMemo(() => { const m = new Map<string, MenuItem[]>(); for (const i of items) { const a = m.get(i.categoryId) ?? []; a.push(i); m.set(i.categoryId, a) } return m }, [items])
  const visibleCats = categories.filter((c) => (byCat.get(c.id)?.length ?? 0) > 0 && (!activeCat || c.id === activeCat))
  const filtersActive = !!debounced || dietary.length > 0 || availableOnly
  const clearMenuFilters = () => { setSearch(''); setDietary([]); setAvailableOnly(false); setActiveCat(null) }
  const jumpTo = (catId: string | null) => { setActiveCat(catId); if (catId) setTimeout(() => document.getElementById(`cat-${catId}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 0) }

  const favorite = () => { if (!r) return; if (!isAuthenticated) return navigate('/login', { state: { from: location.pathname } }); void toggleFavorite(r.id) }
  const share = async () => { try { await navigator.clipboard.writeText(window.location.href); setCopied(true); setTimeout(() => setCopied(false), 1500) } catch { /* clipboard unavailable */ } }

  // ---- states: loading / not found / error
  if (rest.status === 'loading') return (<><Header /><main id="main" className="rd"><div className="rd__grid"><div className="rd__main" aria-busy="true" aria-label={t('rd.loading', undefined, locale)}><div className="rd-skel rd-skel--hero" /><div className="rd-skel rd-skel--info" /><div className="rd-skel rd-skel--row" /><div className="rd-skel rd-skel--row" /></div></div></main></>)
  if (rest.status === 'notfound' || !r) return (
    <><Header /><main id="main" className="rd"><div className="rd-state" role="status">
      <span className="rd-state__icon" aria-hidden="true"><PinIcon size={34} /></span>
      <h1>{t('rd.notFound.title', undefined, locale)}</h1><p>{t('rd.notFound.text', undefined, locale)}</p>
      <div className="rd-state__actions"><Link to="/restaurants" className="btn btn--primary">{t('rd.notFound.browse', undefined, locale)}</Link><Link to="/plan-journey" className="btn btn--outline">{t('discovery.planJourney', undefined, locale)}</Link></div>
    </div></main></>)
  if (rest.status === 'error') return (
    <><Header /><main id="main" className="rd"><div className="rd-state rd-state--error" role="alert">
      <h1>{t('rd.error.title', undefined, locale)}</h1><p>{rest.error}</p>
      <div className="rd-state__actions"><button type="button" className="btn btn--primary" onClick={() => { void loadRestaurant() }}>{t('discovery.error.retry', undefined, locale)}</button><Link to="/restaurants" className="btn btn--outline">{t('rd.notFound.browse', undefined, locale)}</Link></div>
    </div></main></>)

  const saved = isFavorite(r.id)
  const a = availability!
  const statusKey = a.status === 'open' ? 'card.open' : a.status === 'closing_soon' ? 'card.closingSoon' : a.status === 'opening_soon' ? 'card.openingSoon' : a.status === 'temporarily_closed' ? 'card.temporarilyClosed' : 'card.closed'
  const nextChange = a.nextChangeAt ? `${t(a.status === 'open' || a.status === 'closing_soon' ? 'card.closesAt' : 'card.opensAt', { time: formatLocalTime(a.nextChangeAt, r.timezone, locale) }, locale)} ${zoneLabel(a.nextChangeAt, r.timezone, locale)}` : ''
  const lang = r.countryCode === 'JP' ? 'ja' : /[؀-ۿ]/.test(r.name) ? 'ar' : undefined
  const today = localClock(new Date().toISOString(), r.timezone).weekday
  const photos = r.images.length ? r.images : [r.image]
  photosRef.current = photos.length
  const MapView = mapProvider.MapView
  const unavailable = r.status !== 'active'

  return (
    <>
      <Header />
      <main id="main" className={`rd ${cart.count ? 'has-cart' : ''}`}>
        <div className="rd__grid">
          <div className="rd__main">
            {/* ---------- Hero ---------- */}
            <section className="rd-hero">
              <Img src={photos[0]} fallback={r.fallback} alt="" />
              <Link to="/restaurants" className="rd-hero__back"><BackIcon /> {t('rd.back', undefined, locale)}</Link>
              <div className="rd-hero__actions">
                <button type="button" className={`rd-round ${saved ? 'is-on' : ''}`} aria-pressed={saved} aria-label={t(saved ? 'card.unsave' : 'card.save', { name: r.name }, locale)} onClick={favorite}><HeartIcon /></button>
                <button type="button" className="rd-round" aria-label={t('rd.share', undefined, locale)} onClick={() => { void share() }}><ShareIcon /></button>
                {copied && <span className="rd-hero__copied" role="status">{t('rd.shareCopied', undefined, locale)}</span>}
              </div>
              <button type="button" className="rd-hero__photos" onClick={() => { setTab('photos'); setLightbox(0) }}><CameraIcon /> {t('rd.photos', { count: photos.length }, locale)}</button>
            </section>

            {/* ---------- Info card ---------- */}
            <section className="rd-info" aria-labelledby="rd-name">
              <div className="rd-info__logo" aria-hidden="true">{r.fallback ? <Img src={photos[0]} fallback={initials(r.name)} /> : initials(r.name)}</div>
              <div className="rd-info__body">
                <div className="rd-info__top">
                  <div className="rd-info__title">
                    <h1 id="rd-name" lang={lang} dir="auto">{r.name}</h1>
                    <p className="rd-info__cuisine" dir="auto">{r.cuisines.join(' · ')} <span className="rd-price" aria-label={`price level ${r.priceLevel} of 4, ${r.currency}`}>{priceLevelLabel(r.priceLevel, r.currency, locale)}</span></p>
                  </div>
                  <span className="rd-rating"><StarIcon size={15} /> <b>{r.rating.toLocaleString(locale, { minimumFractionDigits: 1 })}</b> <small>{t('rd.reviews', { count: r.reviewCount.toLocaleString(locale) }, locale)}</small></span>
                </div>
                <p className="rd-info__status">
                  <span className={`rd-badge rd-badge--${a.status}`}>{t(statusKey, undefined, locale)}</span>
                  {nextChange && <span className="rd-info__next">{nextChange}</span>}
                  <span className="rd-info__prep"><ClockIcon size={14} /> {t('rd.prep', { minutes: formatMinutes(r.prepTimeMin, locale) }, locale)}</span>
                </p>
                <p className="rd-info__addr" dir="auto"><PinIcon size={14} /> {r.address.formatted}</p>
                {unavailable && <p className="rd-note rd-note--warn" role="status">{t('rd.unavailable.title', undefined, locale)}</p>}
                {!unavailable && !r.acceptingOrders && <p className="rd-note rd-note--warn" role="status">{t('rd.notAccepting', undefined, locale)}</p>}
                {route ? (
                  <ul className="rd-facts rd-facts--route" aria-label={t('rd.route.title', undefined, locale)}>
                    <li><PinIcon size={20} /><span><b>{formatDistance(route.distanceFromRouteM!, units, locale)}</b>{t('rd.route.distance', undefined, locale)}</span></li>
                    <li><ClockIcon size={20} /><span><b>{formatMinutes(route.detourDurationMin!, locale)}</b>{t('rd.route.detour', undefined, locale)}</span></li>
                    <li><CarIcon size={20} /><span><b>{formatLocalTime(route.estimatedArrival!, r.timezone, locale)}</b>{t('rd.route.arrive', { time: '' }, locale).replace('~ local', 'local').trim()}</span></li>
                    <li><span className="rd-facts__mock">{t('mock.estimate', undefined, locale)}</span></li>
                  </ul>
                ) : (
                  <p className="rd-info__routeNone"><CarIcon size={16} /> {t('rd.route.none', undefined, locale)} <Link to="/plan-journey">{t('discovery.planJourney', undefined, locale)}</Link></p>
                )}
                <p className="rd-info__desc">{r.description}</p>
                {r.features.length > 0 && <ul className="rd-features" aria-label={t('rd.info.features', undefined, locale)}>{r.features.map((f) => <li key={f}>{f}</li>)}</ul>}
              </div>
            </section>

            {/* ---------- Tabs ---------- */}
            <div className="rd-tabs" role="tablist" aria-label="Restaurant sections">
              {(['menu', 'info', 'photos'] as TabKey[]).map((k) => (
                <button key={k} type="button" role="tab" id={`tab-${k}`} aria-selected={tab === k} aria-controls={`panel-${k}`} className={tab === k ? 'is-on' : ''} onClick={() => setTab(k)}>{t(`rd.tab.${k}`, undefined, locale)}{k === 'photos' && <small> {photos.length}</small>}</button>
              ))}
            </div>

            {/* ---------- Menu ---------- */}
            {tab === 'menu' && (
              <section id="panel-menu" role="tabpanel" aria-labelledby="tab-menu" className="rd-menu">
                {cats.status === 'loading' && <div className="rd-menu__loading" aria-busy="true" role="status">{t('rd.menu.loading', undefined, locale)}<div className="rd-skel rd-skel--row" /><div className="rd-skel rd-skel--row" /></div>}
                {cats.status === 'error' && <div className="rd-note rd-note--error" role="alert">{t('rd.menu.error', undefined, locale)} <button type="button" className="pj-link" onClick={() => { void loadMenu(r.id, true) }}>{t('discovery.error.retry', undefined, locale)}</button></div>}
                {cats.status === 'ready' && categories.length === 0 && (
                  <div className="rd-menu__none" role="status"><b>{t('rd.menu.none.title', undefined, locale)}</b><p>{t('rd.menu.none.text', undefined, locale)}</p><div className="rd-state__actions"><button type="button" className="btn btn--outline" onClick={favorite}>{t(saved ? 'card.unsave' : 'card.save', { name: r.name }, locale)}</button><Link to="/restaurants" className="btn btn--primary">{t('rd.notFound.browse', undefined, locale)}</Link></div></div>
                )}
                {cats.status === 'ready' && categories.length > 0 && (
                  <>
                    <div className="rd-menu__bar">
                      <label className="rd-menu__search"><SearchIcon /><input type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('rd.menu.search', undefined, locale)} aria-label={t('rd.menu.search', undefined, locale)} dir="auto" /></label>
                      <div className="rd-menu__filters" role="group" aria-label={t('discovery.filters', undefined, locale)}>
                        <label className={`rd-chip ${availableOnly ? 'is-on' : ''}`}><input type="checkbox" checked={availableOnly} onChange={(e) => setAvailableOnly(e.target.checked)} />{t('rd.menu.availableOnly', undefined, locale)}</label>
                        {dietaryOptions.map((d) => <label key={d} className={`rd-chip ${dietary.includes(d) ? 'is-on' : ''}`}><input type="checkbox" checked={dietary.includes(d)} onChange={() => setDietary((x) => (x.includes(d) ? x.filter((y) => y !== d) : [...x, d]))} /><LeafIcon /> {d}</label>)}
                      </div>
                    </div>
                    <nav className="rd-cats" aria-label="Menu categories">
                      <button type="button" className={!activeCat ? 'is-on' : ''} aria-pressed={!activeCat} onClick={() => jumpTo(null)}>{t('rd.menu.allCategories', undefined, locale)}</button>
                      {categories.map((c) => <button key={c.id} type="button" className={activeCat === c.id ? 'is-on' : ''} aria-pressed={activeCat === c.id} onClick={() => jumpTo(c.id)} dir="auto">{c.name} <small>{byCat.get(c.id)?.length ?? 0}</small></button>)}
                    </nav>
                    {menuStatus === 'updating' && <p className="results__note" role="status">{t('discovery.updating', undefined, locale)}</p>}
                    {menuStatus === 'error' && <div className="rd-note rd-note--error" role="alert">{t('rd.menu.error', undefined, locale)} <button type="button" className="pj-link" onClick={() => { void loadMenu(r.id, false) }}>{t('discovery.error.retry', undefined, locale)}</button></div>}
                    {menuStatus !== 'error' && visibleCats.length === 0 && (
                      <div className="rd-menu__none" role="status"><b>{t('rd.menu.empty.title', undefined, locale)}</b><p>{t('rd.menu.empty.text', undefined, locale)}</p>{filtersActive && <button type="button" className="btn btn--outline" onClick={clearMenuFilters}>{t('rd.menu.clear', undefined, locale)}</button>}</div>
                    )}
                    {visibleCats.map((c) => {
                      const list = byCat.get(c.id) ?? []
                      const open = expanded[c.id] || !!activeCat || filtersActive
                      const shown = open ? list : list.slice(0, DEFAULT_SHOWN)
                      return (
                        <section key={c.id} className="rd-section" id={`cat-${c.id}`} aria-labelledby={`cat-h-${c.id}`}>
                          <div className="rd-section__head"><div><h2 id={`cat-h-${c.id}`} dir="auto">{c.name}</h2>{c.description && <p dir="auto">{c.description}</p>}</div><small>{list.length}</small></div>
                          <ul className="menu-grid">
                            {shown.map((item) => <MenuItemCard key={item.id} item={item} restaurantSlug={r.slug} locale={locale} />)}
                          </ul>
                          {list.length > DEFAULT_SHOWN && !activeCat && !filtersActive && <button type="button" className="rd-section__all" onClick={() => setExpanded((e) => ({ ...e, [c.id]: !e[c.id] }))}>{open ? t('rd.menu.showLess', undefined, locale) : t('rd.menu.showAll', { count: list.length }, locale)}</button>}
                        </section>
                      )
                    })}
                    <p className="rd-dietary-note">{t('rd.item.dietaryNote', undefined, locale)}</p>
                  </>
                )}
              </section>
            )}

            {/* ---------- Info ---------- */}
            {tab === 'info' && (
              <section id="panel-info" role="tabpanel" aria-labelledby="tab-info" className="rd-infopanel">
                <div className="rd-side-card"><h2>{t('rd.info.about', undefined, locale)}</h2><p>{r.description}</p><h3>{t('rd.info.cuisine', undefined, locale)}</h3><p dir="auto">{r.cuisines.join(' · ')}{r.categories.length ? ` · ${r.categories.join(' · ')}` : ''}</p></div>
                <div className="rd-side-card"><h2>{t('rd.info.address', undefined, locale)}</h2><p dir="auto"><PinIcon size={16} /> {r.address.formatted}</p><h3>{t('rd.info.pickup', undefined, locale)}</h3><p>{t('rd.info.pickupText', undefined, locale)}</p></div>
                <div className="rd-side-card"><h2>{t('rd.info.contact', undefined, locale)}</h2><p className="rd-muted">{t('rd.info.contactNone', undefined, locale)}</p></div>
                <HoursCard r={r} locale={locale} today={today} />
                <ReviewSummaryCard restaurantId={r.id} rating={r.rating} reviewCount={r.reviewCount} locale={locale} />
              </section>
            )}

            {/* ---------- Photos ---------- */}
            {tab === 'photos' && (
              <section id="panel-photos" role="tabpanel" aria-labelledby="tab-photos" className="rd-gallery">
                <ul>{photos.map((p, i) => <li key={i}><button type="button" aria-label={`Photo ${i + 1} of ${photos.length}`} onClick={() => setLightbox(i)}><Img src={p} fallback={r.fallback} alt="" /></button></li>)}</ul>
                <p className="rd-muted">Placeholder photography — final restaurant images are tracked under PENDING ASSETS.</p>
              </section>
            )}
          </div>

          {/* ---------- Sidebar ---------- */}
          <aside className="rd__side">
            <div className="rd-side-card rd-side-card--map">
              <h2>{t('rd.info.location', undefined, locale)}</h2>
              <MapView route={journey?.route?.geometry ?? null} origin={journey && journey.origin.lat !== null ? { position: [journey.origin.lat, journey.origin.lng!], label: journey.origin.name } : null} destination={journey && journey.destination.lat !== null ? { position: [journey.destination.lat, journey.destination.lng!], label: journey.destination.name } : null} markers={[{ id: r.id, position: [r.lat, r.lng], label: r.name, selected: true }]} selectedId={r.id} ariaLabel={`${t('rd.info.location', undefined, locale)}: ${r.name}`} shellNote={t('discovery.map.shell', undefined, locale)} />
              <p className="rd-muted" dir="auto">{r.address.formatted}</p>
            </div>
            <HoursCard r={r} locale={locale} today={today} compact />
            {route && (
              <div className="rd-side-card rd-distance">
                <span className="rd-distance__icon"><CarIcon size={22} /></span>
                <span><b>{formatDistance(route.distanceFromRouteM!, units, locale)} {t('rd.route.distance', undefined, locale)}</b>{formatMinutes(route.detourDurationMin!, locale)} {t('rd.route.detour', undefined, locale)} · {t('rd.route.ready', { time: formatLocalTime(route.estimatedPickupReady!, r.timezone, locale) }, locale)}</span>
              </div>
            )}
          </aside>
        </div>

        {lightbox !== null && (
          <div className="rd-lightbox" role="dialog" aria-modal="true" aria-label={`Photo ${lightbox + 1} of ${photos.length}`} onClick={(e) => { if (e.target === e.currentTarget) setLightbox(null) }}>
            <button type="button" className="rd-lightbox__close" aria-label="Close" onClick={() => setLightbox(null)}><CloseIcon /></button>
            {photos.length > 1 && <button type="button" className="rd-lightbox__nav rd-lightbox__nav--prev" aria-label="Previous photo" onClick={() => setLightbox((lightbox + photos.length - 1) % photos.length)}><ChevronL /></button>}
            <figure><Img src={photos[lightbox]} fallback={r.fallback} alt="" /><figcaption>{lightbox + 1} / {photos.length}</figcaption></figure>
            {photos.length > 1 && <button type="button" className="rd-lightbox__nav rd-lightbox__nav--next" aria-label="Next photo" onClick={() => setLightbox((lightbox + 1) % photos.length)}><ChevronR /></button>}
          </div>
        )}
        <CartBar />
      </main>
    </>
  )
}

function HoursCard({ r, locale, today, compact = false }: { r: Restaurant; locale: string; today: number; compact?: boolean }) {
  const rows = [1, 2, 3, 4, 5, 6, 0].map((d) => ({ d, periods: r.openingHours.periods.filter((p) => p.day === d) }))
  return (
    <div className={`rd-side-card ${compact ? 'rd-side-card--hours' : ''}`}>
      <div className="rd-side-card__head"><h2>{t('rd.info.hours', undefined, locale)}</h2><small>{zoneLabel(new Date().toISOString(), r.timezone, locale)}</small></div>
      <ul className="rd-hours">
        {rows.map(({ d, periods }) => (
          <li key={d} className={d === today ? 'is-today' : ''} aria-current={d === today ? 'date' : undefined}>
            <span>{weekdayName(d, locale, compact ? 'short' : 'long')}</span>
            <b>{periods.length ? periods.map((p) => (p.open === '00:00' && p.close === '23:59' ? '24 h' : `${timeOf(p.open, locale)} – ${timeOf(p.close, locale)}`)).join(', ') : t('rd.info.closedDay', undefined, locale)}</b>
          </li>
        ))}
      </ul>
      {r.openingHours.closures?.map((c) => <p key={c.from} className="rd-note rd-note--warn">{t('rd.info.closure', { from: c.from, to: c.to }, locale)}{c.reason ? ` — ${c.reason}` : ''}</p>)}
      {r.openingHours.note && <p className="rd-muted">{r.openingHours.note}</p>}
      <p className="rd-muted">{t('rd.info.hoursZone', { zone: r.timezone }, locale)}</p>
    </div>
  )
}

function MenuItemCard({ item, restaurantSlug, locale }: { item: MenuItem; restaurantSlug: string; locale: string }) {
  const unavailable = item.availability !== 'available'
  const to = `/restaurants/${restaurantSlug}/item/${item.slug}`
  return (
    <li className={`mcard ${unavailable ? 'is-unavailable' : ''}`}>
      <div className="mcard__media">
        <Img src={item.image} fallback={item.fallback} alt="" />
        {item.featured && !unavailable && <span className="mcard__popular">{t('rd.item.featured', undefined, locale)}</span>}
        {unavailable && <span className="mcard__status" role="status">{t(`rd.item.${item.availability}`, undefined, locale)}</span>}
      </div>
      <div className="mcard__body">
        <h3 dir="auto"><Link to={to}>{item.name}</Link></h3>
        {item.description && <p dir="auto">{item.description}</p>}
        <div className="mcard__tags">
          {item.dietaryTags.map((d) => <span key={d} className="mcard__tag mcard__tag--diet"><LeafIcon size={11} /> {d}</span>)}
          {item.customizable && <span className="mcard__tag">{t('rd.item.customizable', undefined, locale)}</span>}
        </div>
        <div className="mcard__foot">
          <strong>{formatMoney(item.basePriceMinor, item.currency, locale)}</strong>
          <Link to={to} className={`btn ${unavailable ? 'btn--outline' : 'btn--primary'} mcard__view`} aria-label={`${t('rd.item.view', undefined, locale)}: ${item.name}`}>{t('rd.item.view', undefined, locale)}</Link>
        </div>
      </div>
    </li>
  )
}

/** Module 16 readiness: public review summary slot. Aggregates are server-side later; the mock counts only reviews on this device. */
function ReviewSummaryCard({ restaurantId, rating, reviewCount, locale }: { restaurantId: string; rating: number; reviewCount: number; locale: string }) {
  const [summary, setSummary] = useState<RestaurantReviewSummary | null>(null)
  useEffect(() => { let on = true; reviewRepositories.reviews.getRestaurantReviewSummary(restaurantId).then((s) => { if (on) setSummary(s) }).catch(() => {}); return () => { on = false } }, [restaurantId])
  return (
    <div className="rd-side-card" data-testid="rd-reviews">
      <h2>{t('rd.reviews.title', undefined, locale)}</h2>
      <p className="rd-muted">{t('rd.reviews.catalogue', { rating: rating.toLocaleString(locale, { minimumFractionDigits: 1 }), count: reviewCount.toLocaleString(locale) }, locale)}</p>
      <p>{summary && summary.reviewCount > 0 && summary.averageRating != null ? t('rd.reviews.local', { count: summary.reviewCount, avg: summary.averageRating.toLocaleString(locale, { maximumFractionDigits: 1 }), max: 5 }, locale) : t('rd.reviews.none', undefined, locale)}</p>
      <p className="rd-muted">{t('rd.reviews.note', undefined, locale)}</p>
    </div>
  )
}

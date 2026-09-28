import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import Header from '../components/Header'
import FiltersPanel from '../components/FiltersPanel'
import RestaurantCard from '../components/RestaurantCard'
import { PinIcon } from '../components/Icons'
import { useAccount } from '../account/AccountContext'
import { useAuth } from '../auth/AuthContext'
import { useJourney } from '../journey/JourneyContext'
import { useDiscovery } from '../discovery/useDiscovery'
import { loadManualScope, resolveScope, saveManualScope, scopeFromLocale, scopeFromLocation } from '../discovery/scope'
import LocationInput from '../components/LocationInput'
import type { Location } from '../journey/JourneyContext'
import type { DiscoveryScope, ScopeRing } from '../repositories/types'
import { formatDistance, formatMinutes } from '../i18n/format'
import { marketFor, resolveUnitSystem, type UnitSystem } from '../i18n/markets'
import { t, useLocale } from '../i18n/strings'
import { mapProvider } from '../map/MapProvider'
import type { LatLng } from '../geo/geo'
import type { SortKey } from '../repositories/types'
import { RESTAURANTS as REPO_RESTAURANTS } from '../repositories/mock/restaurants'
import type { Restaurant } from '../repositories'
import './account/AccountPage.css'
import './RestaurantsPage.css'

export type { Restaurant }
/** Re-exported for Module 01 pages that resolve restaurants by id; source of truth is the repository. */
export const RESTAURANTS: Restaurant[] = REPO_RESTAURANTS

type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const })
const CarIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M5 11l1.5-4.5A2 2 0 0 1 8.4 5h7.2a2 2 0 0 1 1.9 1.5L19 11M4 11h16v6H4zM6 17v2M18 17v2" /><circle cx="7.5" cy="14" r="1" /><circle cx="16.5" cy="14" r="1" /></svg>)
const ListIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M8 6h13M8 12h13M8 18h13" /><circle cx="4" cy="6" r="1" fill="currentColor" /><circle cx="4" cy="12" r="1" fill="currentColor" /><circle cx="4" cy="18" r="1" fill="currentColor" /></svg>)
const MapIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="m3 6 6-2 6 2 6-2v14l-6 2-6-2-6 2V6ZM9 4v14M15 6v14" /></svg>)
const SplitIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><rect x="3" y="4" width="8" height="16" rx="2" /><rect x="13" y="4" width="8" height="16" rx="2" /></svg>)
const ChevronDown = ({ size = 16 }: P) => (<svg {...stroke(size)}><path d="m6 9 6 6 6-6" /></svg>)
const SearchIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>)
const FilterIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M3 5h18M6 12h12M10 19h4" /></svg>)
const ArrowIcon = ({ size = 16 }: P) => (<svg {...stroke(size)}><path d="M5 12h14M13 6l6 6-6 6" /></svg>)

const SORTS: SortKey[] = ['recommended', 'lowestDetour', 'nearestToRoute', 'highestRated', 'fastestPickup']
const fmtDeparture = (iso: string, locale: string, timeZone?: string) => { try { return new Intl.DateTimeFormat(locale, { weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit', timeZone }).format(new Date(iso)) } catch { return iso } }

export default function RestaurantsPage() {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const location = useLocation()
  const { locale, unitPreference, setUnitPreference } = useLocale()
  const { isFavorite, toggleFavorite, addresses } = useAccount()
  const { isAuthenticated } = useAuth()
  const journeyApi = useJourney()

  // ---- journey context: ?journey=<id> (application state id, never a place name) or the current journey in state
  const journeyId = params.get('journey')
  const [journeyState, setJourneyState] = useState<'none' | 'loading' | 'ready' | 'missing'>(journeyId ? 'loading' : 'none')
  useEffect(() => {
    if (!journeyId) { setJourneyState('none'); return }
    let alive = true
    setJourneyState('loading')
    journeyApi.load(journeyId).then((j) => { if (alive) setJourneyState(j ? 'ready' : 'missing') }).catch(() => { if (alive) setJourneyState('missing') })
    return () => { alive = false }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [journeyId])
  const journey = journeyState === 'ready' && journeyApi.journey?.route ? journeyApi.journey : journeyState === 'none' && journeyApi.journey?.route ? journeyApi.journey : null
  const journeyReady = journeyState !== 'loading'

  // ---- location scope (general discovery only): manual → saved address → last journey → browser region → none
  const [manualScope, setManualScope] = useState<DiscoveryScope | null>(() => loadManualScope())
  const [scopeDialog, setScopeDialog] = useState(false)
  const [pendingLoc, setPendingLoc] = useState<Location | null>(null)
  const scope: DiscoveryScope | null = useMemo(() => journey ? null : resolveScope({ manual: manualScope, addresses: isAuthenticated ? addresses.data : [], addressCountry: scopeFromLocale(locale)?.countryCode ?? null, recentJourneys: journeyApi.recent, locale }), [journey, manualScope, isAuthenticated, addresses.data, journeyApi.recent, locale])
  const applyScope = (l: Location | null) => { const s = l ? scopeFromLocation(l, 'manual') : null; setManualScope(s); saveManualScope(s); setScopeDialog(false); setPendingLoc(null) }
  const regionName = scope?.adminArea ?? scope?.label ?? ''
  const countryName = (() => { try { return new Intl.DisplayNames([locale], { type: 'region' }).of(scope?.countryCode ?? 'ZZ') ?? scope?.countryCode ?? '' } catch { return scope?.countryCode ?? '' } })()
  const ringLabel = (ring: ScopeRing | undefined) => ring === undefined ? undefined : t(`ring.${ring}`, { region: regionName, country: countryName }, locale)
  const moreAreasLabel = (ring: ScopeRing | null) => ring === null ? '' : t(`scope.moreAreas.${ring}`, { region: regionName, country: countryName }, locale)

  // ---- discovery
  const d = useDiscovery(journey, journeyReady && (!!journey || !!scope), scope)
  const units: UnitSystem = resolveUnitSystem(unitPreference, journey?.origin.countryCode ?? d.items[0]?.restaurant.countryCode)
  const currency = journey ? marketFor(journey.origin.countryCode).currency : null
  const [view, setView] = useState<'list' | 'map' | 'both'>(() => (typeof window !== 'undefined' && window.innerWidth >= 1100 ? 'both' : 'list'))
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [filtersOpen, setFiltersOpen] = useState(false)
  const listRef = useRef<HTMLUListElement>(null)
  const favorite = (id: string) => { if (!isAuthenticated) return navigate('/login', { state: { from: location.pathname + location.search } }); void toggleFavorite(id) }
  const selectFromMap = (id: string) => { setSelectedId(id); listRef.current?.querySelector<HTMLElement>(`[data-id="${id}"]`)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }) }

  const markers = useMemo(() => d.items.map((x) => ({ id: x.restaurant.id, position: [x.restaurant.lat, x.restaurant.lng] as LatLng, label: x.restaurant.name, selected: x.restaurant.id === selectedId, muted: x.availability.status === 'closed' || x.availability.status === 'temporarily_closed' })), [d.items, selectedId])
  const routeLine: LatLng[] | null = journey?.route?.geometry ?? null
  const MapView = mapProvider.MapView
  const showMap = view !== 'list', showList = view !== 'map'
  const count = d.total
  const heading = journey ? (count === 1 ? t('discovery.resultsOne', undefined, locale) : t('discovery.resultsCount', { count: count.toLocaleString(locale) }, locale)) : t('discovery.resultsCountGeneral', { count: count.toLocaleString(locale) }, locale)
  const journeyUnits = journey ? resolveUnitSystem(unitPreference, journey.origin.countryCode) : units

  return (
    <>
      <Header />
      <main id="main" className="rest">
        {/* ---------- Hero + journey context ---------- */}
        <section className="rest-hero">
          <div className="rest-hero__bg" aria-hidden="true">
            <img src="/images/hero-restaurants.jpg" alt="" onError={(e) => { e.currentTarget.style.display = 'none' }} />
            <div className="rest-hero__fade" />
          </div>
          <div className="rest-hero__inner">
            <div className="rest-hero__content">
              <p className="rest-eyebrow">{t('discovery.eyebrow', undefined, locale)}</p>
              <h1 className="rest-hero__title"><span>{t('discovery.title.line1', undefined, locale)}</span><span className="rest-accent">{t('discovery.title.line2', undefined, locale)}</span></h1>
              <p className="rest-hero__lead">{t('discovery.lead', undefined, locale)}</p>
            </div>

            {journeyState === 'loading' && <div className="jctx jctx--loading" role="status">{t('discovery.loadingRoute', undefined, locale)}</div>}
            {journeyState === 'missing' && (
              <div className="jctx jctx--missing" role="alert">
                <p>Journey not found on this device.</p>
                <Link to="/plan-journey" className="btn btn--primary">{t('discovery.planJourney', undefined, locale)}</Link>
              </div>
            )}
            {journey && journeyState !== 'loading' && (
              <section className="jctx" aria-labelledby="jctx-title">
                <div className="jctx__head"><h2 id="jctx-title">{t('discovery.journeyLabel', undefined, locale)}</h2><span className="jctx__id">#{journey.id.slice(-6)}</span></div>
                <div className="jctx__route">
                  <span className="jctx__pt jctx__pt--from"><small>{t('discovery.from', undefined, locale)}</small><b dir="auto">{journey.origin.name}</b><span dir="auto">{journey.origin.formattedAddress ?? journey.origin.sub}</span></span>
                  <span className="jctx__arrow" aria-hidden="true"><ArrowIcon /></span>
                  <span className="jctx__pt jctx__pt--to"><small>{t('discovery.to', undefined, locale)}</small><b dir="auto">{journey.destination.name}</b><span dir="auto">{journey.destination.formattedAddress ?? journey.destination.sub}</span></span>
                </div>
                <dl className="jctx__facts">
                  <div><dt><CarIcon size={16} /> {t('discovery.route', undefined, locale)}</dt><dd>{formatDistance(journey.route!.distanceKm * 1000, journeyUnits, locale)} · {formatMinutes(journey.route!.durationMin, locale)} <small className="route-summary__mock">{t('mock.estimate', undefined, locale)}</small></dd></div>
                  <div><dt><PinIcon size={16} /> {t('discovery.corridor', { distance: formatDistance(d.corridorM ?? 0, journeyUnits, locale) }, locale)}</dt><dd><input type="range" min={1000} max={50000} step={1000} value={d.corridorM ?? 5000} onChange={(e) => d.setCorridorM(Number(e.target.value))} aria-label={t('discovery.corridor', { distance: formatDistance(d.corridorM ?? 0, journeyUnits, locale) }, locale)} className="range range--sm" /></dd></div>
                  <div><dt>{journey.departureAt ? t('discovery.departing', { when: fmtDeparture(journey.departureAt, locale, journey.origin.timezone) }, locale) : t('discovery.leavingNow', undefined, locale)}</dt><dd className="jctx__count" aria-live="polite">{d.status === 'loading' ? t('discovery.loading', undefined, locale) : heading}</dd></div>
                </dl>
                <div className="jctx__actions">
                  <Link to="/plan-journey" className="btn btn--outline" onClick={() => journeyApi.edit()}>{t('discovery.editJourney', undefined, locale)}</Link>
                  <button type="button" className="btn btn--primary" onClick={() => { journeyApi.reset(); navigate('/plan-journey') }}>{t('discovery.newJourney', undefined, locale)}</button>
                </div>
              </section>
            )}
            {!journey && journeyState === 'none' && (
              <section className="jctx jctx--none" aria-labelledby="jctx-none-title">
                <div className="scope" role="status">
                  {scope ? <><PinIcon size={16} /> <span>{t(scope.lat !== null ? 'scope.showingNear' : 'scope.showingIn', { label: scope.label }, locale)} <small>({t(`scope.source.${scope.source}`, undefined, locale)})</small></span><button type="button" className="pj-link" onClick={() => setScopeDialog(true)}>{t('scope.change', undefined, locale)}</button></>
                    : <><PinIcon size={16} /> <span>{t('scope.none.text', undefined, locale)}</span><button type="button" className="btn btn--primary" onClick={() => setScopeDialog(true)}>{t('scope.set', undefined, locale)}</button></>}
                </div>
                <h2 id="jctx-none-title">{t('discovery.noJourney.title', undefined, locale)}</h2>
                <p>{t('discovery.noJourney.text', undefined, locale)}</p>
                <Link to="/plan-journey" className="btn btn--primary">{t('discovery.planJourney', undefined, locale)} <ArrowIcon /></Link>
              </section>
            )}
          </div>
        </section>

        {/* ---------- Results ---------- */}
        <section className={`rest-body ${filtersOpen ? 'filters-open' : ''}`}>
          <div className="filters-wrap">
            <FiltersPanel definitions={d.definitions} values={d.filters} onToggleOption={d.toggleOption} onSet={d.setFilter} onClear={() => { d.clearFilters(); setFiltersOpen(false) }} units={units} currency={currency} />
          </div>
          {filtersOpen && <button type="button" className="filters-backdrop" aria-label="Close filters" onClick={() => setFiltersOpen(false)} />}

          <div className="results">
            <div className="results__bar">
              <h2 aria-live="polite">{d.status === 'loading' ? t('discovery.loading', undefined, locale) : heading}{d.status === 'updating' && <small className="results__updating"> · {t('discovery.updating', undefined, locale)}</small>}</h2>
              <div className="results__tools">
                <label className="results__search"><SearchIcon /><input type="search" value={d.search} onChange={(e) => d.setSearch(e.target.value)} placeholder={t('discovery.search.placeholder', undefined, locale)} aria-label={t('discovery.search.label', undefined, locale)} dir="auto" /></label>
                <button type="button" className="results__filters-btn" aria-expanded={filtersOpen} onClick={() => setFiltersOpen((v) => !v)}><FilterIcon /> {t('discovery.filters', undefined, locale)}{d.activeFilterCount > 0 && <span className="results__badge">{d.activeFilterCount}</span>}</button>
                <label className="sort">{t('discovery.sort', undefined, locale)}
                  <span className="select-wrap">
                    <select aria-label={t('discovery.sort', undefined, locale)} value={d.sort} onChange={(e) => d.setSort(e.target.value as SortKey)} title={t('discovery.sort.recommendedNote', undefined, locale)}>
                      {SORTS.filter((s) => journey || (s !== 'lowestDetour' && s !== 'nearestToRoute')).map((s) => <option key={s} value={s}>{t(`discovery.sort.${s}`, undefined, locale)}</option>)}
                    </select>
                    <ChevronDown />
                  </span>
                </label>
                <label className="sort units">
                  <span className="select-wrap">
                    <select aria-label={t('units.label', undefined, locale)} value={unitPreference} onChange={(e) => setUnitPreference(e.target.value as 'auto' | 'metric' | 'imperial')}>
                      <option value="auto">{t('units.auto', undefined, locale)}</option><option value="metric">{t('units.metric', undefined, locale)}</option><option value="imperial">{t('units.imperial', undefined, locale)}</option>
                    </select>
                    <ChevronDown />
                  </span>
                </label>
                <div className="view-toggle" role="group" aria-label="View">
                  <button type="button" className={view === 'list' ? 'is-on' : ''} aria-pressed={view === 'list'} onClick={() => setView('list')}><ListIcon /> {t('discovery.view.list', undefined, locale)}</button>
                  <button type="button" className={`view-toggle__both ${view === 'both' ? 'is-on' : ''}`} aria-pressed={view === 'both'} onClick={() => setView('both')}><SplitIcon /> {t('discovery.view.both', undefined, locale)}</button>
                  <button type="button" className={view === 'map' ? 'is-on' : ''} aria-pressed={view === 'map'} onClick={() => setView('map')}><MapIcon /> {t('discovery.view.map', undefined, locale)}</button>
                </div>
              </div>
            </div>
            {d.sort === 'recommended' && <p className="results__note">{t('discovery.sort.recommendedNote', undefined, locale)}</p>}

            <div className={`results__grid results__grid--${view}`}>
              {showList && (
                <div className="results__list">
                  {d.status === 'error' && (
                    <div className="disc-state disc-state--error" role="alert">
                      <b>{t('discovery.error.title', undefined, locale)}</b><p>{d.error}</p>
                      <button type="button" className="btn btn--primary" onClick={() => { void d.retry() }}>{t('discovery.error.retry', undefined, locale)}</button>
                    </div>
                  )}
                  {(d.status === 'loading' || (d.status === 'idle' && (!!journey || !!scope))) && (
                    <ul className="cards cards--skeleton" aria-busy="true" aria-label={t(journey ? 'discovery.loadingRoute' : 'discovery.loading', undefined, locale)}>{[0, 1, 2, 3].map((i) => <li key={i} className="rcard rcard--skeleton"><div className="rcard__media" /><div className="rcard__body"><span /><span /><span /></div></li>)}</ul>
                  )}
                  {!journey && !scope && journeyState === 'none' && (
                    <div className="disc-state disc-state--empty">
                      <b>{t('scope.none.title', undefined, locale)}</b>
                      <p>{t('scope.none.text', undefined, locale)}</p>
                      <div className="disc-state__actions"><button type="button" className="btn btn--primary" onClick={() => setScopeDialog(true)}>{t('scope.set', undefined, locale)}</button></div>
                    </div>
                  )}
                  {(d.status === 'ready' || d.status === 'updating') && d.items.length === 0 && (
                    <div className="disc-state disc-state--empty">
                      <b>{t(journey ? 'discovery.empty.route.title' : scope && d.activeFilterCount === 0 ? 'discovery.empty.scope.title' : 'discovery.empty.general.title', { label: scope?.label ?? '' }, locale)}</b>
                      <p>{t(journey ? 'discovery.empty.route.text' : scope && d.activeFilterCount === 0 ? 'discovery.empty.scope.text' : 'discovery.empty.general.text', undefined, locale)}</p>
                      <div className="disc-state__actions">
                        {!journey && scope && d.nextRing !== null && <button type="button" className="btn btn--primary" onClick={d.showMoreAreas}>{moreAreasLabel(d.nextRing)}</button>}
                        {!journey && scope && <button type="button" className="btn btn--outline" onClick={() => setScopeDialog(true)}>{t('scope.change', undefined, locale)}</button>}
                        {journey && (d.corridorM ?? 0) < 50_000 && <button type="button" className="btn btn--primary" onClick={d.widenCorridor}>{t('discovery.empty.increaseDetour', { distance: formatDistance(Math.min((d.corridorM ?? 5000) * 2, 50_000), journeyUnits, locale) }, locale)}</button>}
                        {d.activeFilterCount > 0 && <button type="button" className="btn btn--outline" onClick={d.clearFilters}>{t('discovery.empty.clearFilters', undefined, locale)}</button>}
                        {journey && <Link to="/plan-journey" className="btn btn--outline" onClick={() => journeyApi.edit()}>{t('discovery.empty.editRoute', undefined, locale)}</Link>}
                      </div>
                    </div>
                  )}
                  {d.items.length > 0 && (
                    <ul className={`cards ${d.status === 'updating' ? 'is-updating' : ''}`} ref={listRef} aria-busy={d.status === 'updating'}>
                      {d.items.map((x) => <RestaurantCard key={x.restaurant.id} result={x} units={units} selected={x.restaurant.id === selectedId} favorite={isFavorite(x.restaurant.id)} onFavorite={() => favorite(x.restaurant.id)} onSelect={() => setSelectedId(x.restaurant.id)} ringLabel={journey ? undefined : ringLabel(x.ring)} />)}
                    </ul>
                  )}
                  {!journey && scope && d.ringApplied !== undefined && d.ringApplied > d.maxRing && d.status === 'ready' && <p className="results__note" role="status">{t('scope.expanded', { ring: ringLabel(d.ringApplied)?.toLowerCase() ?? '' }, locale)}</p>}
                  {!journey && scope && d.nextRing !== null && !d.nextCursor && d.status === 'ready' && d.items.length > 0 && (
                    <div className="results__more"><button type="button" className="btn btn--outline" onClick={d.showMoreAreas}>{moreAreasLabel(d.nextRing)}</button></div>
                  )}
                  {d.nextCursor && d.status !== 'loading' && d.status !== 'error' && (
                    <div className="results__more">
                      <button type="button" className="btn btn--outline" disabled={d.status === 'loadingMore'} onClick={() => { void d.loadMore() }}>{d.status === 'loadingMore' ? t('discovery.loadingMore', undefined, locale) : t('discovery.loadMore', undefined, locale)}</button>
                      <small>{d.items.length.toLocaleString(locale)} / {d.total.toLocaleString(locale)}</small>
                    </div>
                  )}
                </div>
              )}
              {showMap && (
                <aside className="results__map" aria-label={t('discovery.map.title', undefined, locale)}>
                  <div className="results__map-sticky">
                    <MapView route={routeLine} origin={journey && journey.origin.lat !== null ? { position: [journey.origin.lat, journey.origin.lng!], label: journey.origin.name } : null} destination={journey && journey.destination.lat !== null ? { position: [journey.destination.lat, journey.destination.lng!], label: journey.destination.name } : null} markers={markers} selectedId={selectedId} onSelect={selectFromMap} ariaLabel={t('discovery.map.title', undefined, locale)} updating={d.status === 'updating' || d.status === 'loading'} shellNote={t('discovery.map.shell', undefined, locale)} />
                    {selectedId && <p className="results__map-sel" aria-live="polite">{t('discovery.map.selected', { name: d.items.find((x) => x.restaurant.id === selectedId)?.restaurant.name ?? '' }, locale)}</p>}
                    <details className="results__map-alt">
                      <summary>{t('discovery.map.textAlt', undefined, locale)}</summary>
                      <ol>{[...d.items].sort((a, b) => (a.routePosition ?? 0) - (b.routePosition ?? 0)).map((x) => <li key={x.restaurant.id}><button type="button" className="pj-link" onClick={() => selectFromMap(x.restaurant.id)} dir="auto">{x.restaurant.name}</button>{x.distanceFromRouteM !== null && <small> · {formatDistance(x.distanceFromRouteM, units, locale)}</small>}</li>)}</ol>
                    </details>
                  </div>
                </aside>
              )}
            </div>
          </div>
        </section>

        {scopeDialog && (
          <div className="ac-modal" role="dialog" aria-modal="true" aria-labelledby="scope-title" onClick={(e) => { if (e.target === e.currentTarget) setScopeDialog(false) }}>
            <div className="ac-modal__box scope-dialog">
              <h2 id="scope-title">{t('scope.dialog.title', undefined, locale)}</h2>
              <p className="ac-note" style={{ marginBottom: 12 }}>{t('scope.dialog.hint', undefined, locale)}</p>
              <LocationInput id="scope-loc" label={t('scope.dialog.title', undefined, locale)} placeholder={t('discovery.search.placeholder', undefined, locale)} value={pendingLoc} onChange={setPendingLoc} allowCurrent />
              <div className="ac-modal__actions">
                <button type="button" className="btn btn--outline" onClick={() => { setScopeDialog(false); setPendingLoc(null) }}>Cancel</button>
                <button type="button" className="btn btn--primary" disabled={!pendingLoc} onClick={() => applyScope(pendingLoc)}>{t('scope.dialog.use', undefined, locale)}</button>
              </div>
            </div>
          </div>
        )}
      </main>
    </>
  )
}

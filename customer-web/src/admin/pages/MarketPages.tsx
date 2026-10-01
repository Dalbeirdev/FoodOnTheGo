import { createContext, useContext, useMemo, useState, type ReactNode } from 'react'
import { Link, NavLink, Outlet, useParams } from 'react-router-dom'
import { formatMoney } from '../../i18n/format'
import { t } from '../../i18n/strings'
import { marketMode } from '../../market/marketMode'
import type { City, CityStatus, MarketFeature, MarketStatus, RegionStatus, RouteCorridor, RouteStatus, ServiceArea, ServiceAreaStatus } from '../../market/types'
import { Card, EmptyState, ErrorState, Icon, KpiCard, Skeleton, Tabs, Toggle, ToastLine, useToastMessage } from '../../dashboard/components/ui'
import { useAdmin } from '../AdminContext'
import { BASE } from '../AdminLayout'
import { Badge, Details, DevNote, ReasonDialog, Select, Stat } from '../components/DataTable'
import type { MarketSnapshot } from '../types'
import { CityFormDrawer, ServiceAreaFormDrawer } from './MarketGeoForms'
import { Cell, fmtDate, useLoad, usePageTitle } from './shared'

/* ------------------------------------------------------------------ shared */
/** One status → tone system for the whole control center: green active, blue pilot / testing, amber planned, red paused / disabled, grey neutral. */
const STATUS_TONE: Record<string, string> = { ACTIVE: 'green', AVAILABLE: 'green', PILOT: 'blue', TESTING: 'blue', PLANNED: 'amber', DRAFT: 'muted', PAUSED: 'red', DISABLED: 'red', UNAVAILABLE: 'muted', CLOSED: 'muted' }
export function MarketBadge({ status }: { status: string }) { const a = useAdmin(); return <Badge tone={STATUS_TONE[status] ?? 'muted'} dot>{t(`adm.marketStatus.${status}`, undefined, a.locale)}</Badge> }
const SnapshotCtx = createContext<{ snap: MarketSnapshot; refresh: () => Promise<void> } | null>(null)
const useSnapshot = () => { const v = useContext(SnapshotCtx); if (!v) throw new Error('market snapshot missing'); return v }
/** Status choices follow the data source: the backend's statuses in API mode, the fixture statuses otherwise. */
const api = () => marketMode() === 'api'
const REGION_STATUSES = () => (api() ? ['AVAILABLE', 'PILOT', 'PLANNED', 'PAUSED', 'DISABLED'] : ['AVAILABLE', 'PILOT', 'PLANNED', 'DISABLED'])
const AREA_STATUSES = () => (api() ? ['ACTIVE', 'TESTING', 'PLANNED', 'PAUSED', 'DISABLED'] : ['ACTIVE', 'PILOT', 'PLANNED', 'PAUSED', 'DISABLED'])
const ROUTE_STATUSES = () => (api() ? ['ACTIVE', 'TESTING', 'PLANNED', 'PAUSED', 'DISABLED'] : ['ACTIVE', 'TESTING', 'PLANNED', 'PAUSED'])
/** A change the backend refused (no permission, not an allowed transition, changed by someone else) is shown, never swallowed. */
const failure = (e: unknown, locale: string) => (e instanceof Error && e.message && !/^[a-z_]+$/.test(e.message) ? e.message : t('adm.error.saveFailed', undefined, locale))
const unitLabel = (u: string, locale: string) => t(`adm.mkt.units.${u}`, undefined, locale)

/* ------------------------------------------------------------------ coverage map (schematic) */
type Layers = { cities: boolean; areas: boolean; routes: boolean; restaurants: boolean }
/**
 * Schematic coverage map. It plots fixture coordinates on a plain projection — it is NOT a geographic basemap and does
 * not claim live coverage; the map provider integration is pending. Every layer is also available as a table, and
 * status is conveyed by shape + legend text, never colour alone.
 */
export function CoverageMap({ snap, selectedCity, onSelectCity }: { snap: MarketSnapshot; selectedCity: string | null; onSelectCity: (id: string | null) => void }) {
  const a = useAdmin(); const locale = a.locale
  const [layers, setLayers] = useState<Layers>({ cities: true, areas: true, routes: true, restaurants: true })
  const [focus, setFocus] = useState<'served' | 'all'>('served')
  const served = snap.cities.filter((c) => c.status === 'ACTIVE' || c.status === 'PILOT' || c.status === 'PAUSED')
  const pts = (focus === 'served' && served.length ? served : snap.cities)
  const W = 640, H = 420, PAD = 36
  const b = useMemo(() => { const lats = pts.map((c) => c.lat), lngs = pts.map((c) => c.lng); const pad = focus === 'served' ? 0.8 : 1.5; return { s: Math.min(...lats) - pad, n: Math.max(...lats) + pad, w: Math.min(...lngs) - pad, e: Math.max(...lngs) + pad } }, [pts, focus])
  const kx = Math.cos(((b.s + b.n) / 2) * Math.PI / 180); const scale = Math.min((W - 2 * PAD) / ((b.e - b.w) * kx), (H - 2 * PAD) / (b.n - b.s))
  const x = (lng: number) => PAD + ((lng - b.w) * kx * scale) + ((W - 2 * PAD) - (b.e - b.w) * kx * scale) / 2
  const y = (lat: number) => H - PAD - (lat - b.s) * scale - ((H - 2 * PAD) - (b.n - b.s) * scale) / 2
  const inView = (lat: number, lng: number) => lat >= b.s && lat <= b.n && lng >= b.w && lng <= b.e
  const city = (id: string) => snap.cities.find((c) => c.id === id)
  const dash = (s: string) => (s === 'ACTIVE' ? undefined : s === 'TESTING' || s === 'PILOT' ? '6 4' : '2 5')
  const toggle = (k: keyof Layers) => setLayers((l) => ({ ...l, [k]: !l[k] }))
  // Label placement: a label goes to the left when the previous labelled city (by screen position) would collide with it.
  const labelSide = useMemo(() => { const side: Record<string, 'l' | 'r'> = {}; const placed: Array<{ px: number; py: number; side: 'l' | 'r' }> = []; for (const c of [...snap.cities].filter((c) => inView(c.lat, c.lng)).sort((p, q) => q.lat - p.lat)) { const px = x(c.lng), py = y(c.lat); const clash = placed.some((o) => o.side === 'r' && Math.abs(o.py - py) < 13 && Math.abs(o.px - px) < 90); const sd: 'l' | 'r' = clash ? 'l' : 'r'; side[c.id] = sd; placed.push({ px, py, side: sd }) } return side }, [snap.cities, b]) // eslint-disable-line react-hooks/exhaustive-deps
  return (
    <div className="adm-map" data-testid="coverage-map">
      <div className="adm-map__bar">
        <div className="adm-map__layers" role="group" aria-label={t('adm.mkt.map.layers', undefined, locale)}>{(['cities', 'areas', 'routes', 'restaurants'] as const).map((k) => <label key={k} className={`db-chip ${layers[k] ? 'is-on' : ''}`}><input type="checkbox" checked={layers[k]} onChange={() => toggle(k)} data-testid={`layer-${k}`} /> {t(`adm.mkt.map.layer.${k}`, undefined, locale)}</label>)}</div>
        <Select label={t('adm.mkt.map.view', undefined, locale)} value={focus} onChange={(v) => setFocus(v as 'served' | 'all')} options={[{ value: 'served', label: t('adm.mkt.map.view.served', undefined, locale) }, { value: 'all', label: t('adm.mkt.map.view.all', undefined, locale) }]} testId="map-view" />
      </div>
      <svg viewBox={`0 0 ${W} ${H}`} role="group" aria-label={t('adm.mkt.map.aria', { market: snap.market.displayName, cities: snap.stats.activeCities + snap.stats.pilotCities, areas: snap.stats.activeServiceAreas, routes: snap.stats.activeRoutes }, locale)} className="adm-map__svg">
        <rect x="0" y="0" width={W} height={H} rx="14" className="adm-map__bg" />
        {layers.routes && snap.routes.map((r) => { const line = [r.originCityId, ...r.viaCityIds, r.destinationCityId].map(city).filter((c): c is City => !!c && inView(c.lat, c.lng)); if (line.length < 2) return null; return <polyline key={r.id} points={line.map((c) => `${x(c.lng).toFixed(1)},${y(c.lat).toFixed(1)}`).join(' ')} className={`adm-map__route adm-map__route--${r.status.toLowerCase()}`} strokeDasharray={dash(r.status)} fill="none"><title>{`${r.name} — ${t(`adm.marketStatus.${r.status}`, undefined, locale)}`}</title></polyline> })}
        {layers.areas && snap.serviceAreas.filter((s) => s.geometry.type === 'radius' && inView(s.geometry.center[0], s.geometry.center[1])).map((s) => s.geometry.type === 'radius' && <circle key={s.id} cx={x(s.geometry.center[1])} cy={y(s.geometry.center[0])} r={Math.max(11, (s.geometry.radiusM / 111320) * scale)} className={`adm-map__area adm-map__area--${s.status.toLowerCase()}`} strokeDasharray={dash(s.status)}><title>{`${s.name} — ${t(`adm.marketStatus.${s.status}`, undefined, locale)}`}</title></circle>)}
        {layers.areas && snap.serviceAreas.map((s) => { const g = s.geometry; if (g.type !== 'multipolygon') return null; const ring = g.coordinates[0]?.[0]; if (!ring?.length) return null
          // Backend polygon (PostGIS). At market scale a city-sized area is only a few pixels wide, so it is then drawn as a marker at its centre — the same minimum size the fixture circles used.
          const xs = ring.map(([lng]) => x(lng)), ys = ring.map(([, lat]) => y(lat)); const cx = (Math.min(...xs) + Math.max(...xs)) / 2, cy = (Math.min(...ys) + Math.max(...ys)) / 2, r = (Math.max(...xs) - Math.min(...xs)) / 2
          const lngs = ring.map(([lng]) => lng), lats = ring.map(([, lat]) => lat); if (!inView((Math.min(...lats) + Math.max(...lats)) / 2, (Math.min(...lngs) + Math.max(...lngs)) / 2)) return null
          const cls = `adm-map__area adm-map__area--${s.status.toLowerCase()}`; const title = <title>{`${s.name} — ${t(`adm.marketStatus.${s.status}`, undefined, locale)}`}</title>
          return r < 11
            ? <circle key={s.id} cx={cx} cy={cy} r={11} className={cls} strokeDasharray={dash(s.status)} data-testid={`map-area-${s.id}`}>{title}</circle>
            : <path key={s.id} d={g.coordinates.flatMap((poly) => poly.map((rg) => `M${rg.map(([lng, lat]) => `${x(lng).toFixed(1)},${y(lat).toFixed(1)}`).join('L')}Z`)).join(' ')} fillRule="evenodd" className={cls} strokeDasharray={dash(s.status)} data-testid={`map-area-${s.id}`}>{title}</path> })}
        {layers.restaurants && snap.restaurants.filter((r) => r.status === 'APPROVED' && inView(r.lat, r.lng)).map((r) => <rect key={r.id} x={x(r.lng) - 2.5} y={y(r.lat) - 2.5} width="5" height="5" className={`adm-map__rest ${r.customerVisible ? '' : 'adm-map__rest--hidden'}`}><title>{r.name}</title></rect>)}
        {layers.cities && snap.cities.filter((c) => inView(c.lat, c.lng)).map((c) => { const on = selectedCity === c.id; const live = c.status === 'ACTIVE' || c.status === 'PILOT'; return (
          <g key={c.id} className={`adm-map__city adm-map__city--${c.status.toLowerCase()} ${on ? 'is-on' : ''}`} transform={`translate(${x(c.lng).toFixed(1)},${y(c.lat).toFixed(1)})`} role="button" tabIndex={0} aria-pressed={on} aria-label={`${c.name}, ${t(`adm.marketStatus.${c.status}`, undefined, locale)}`} onClick={() => onSelectCity(on ? null : c.id)} onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onSelectCity(on ? null : c.id) } }}>
            {on && <circle r="11" className="adm-map__halo" />}
            {c.status === 'ACTIVE' ? <circle r="5" /> : c.status === 'PILOT' ? <rect x="-4.5" y="-4.5" width="9" height="9" transform="rotate(45)" /> : c.status === 'PAUSED' ? <path d="M-5 5 L0 -5 L5 5 Z" /> : <circle r="3.5" className="adm-map__hollow" />}
            {(live || on || focus === 'all' || c.status === 'PAUSED') && <text x={labelSide[c.id] === 'l' ? -9 : 9} y="4" textAnchor={labelSide[c.id] === 'l' ? 'end' : 'start'}>{c.name}</text>}
          </g>) })}
      </svg>
      <ul className="adm-map__legend" aria-label={t('adm.mkt.map.legend', undefined, locale)}>
        <li><svg width="14" height="14" aria-hidden="true"><circle cx="7" cy="7" r="5" className="adm-lg adm-lg--active" /></svg>{t('adm.mkt.map.lg.active', undefined, locale)}</li>
        <li><svg width="14" height="14" aria-hidden="true"><rect x="3" y="3" width="8" height="8" transform="rotate(45 7 7)" className="adm-lg adm-lg--pilot" /></svg>{t('adm.mkt.map.lg.pilot', undefined, locale)}</li>
        <li><svg width="14" height="14" aria-hidden="true"><path d="M2 12 L7 2 L12 12 Z" className="adm-lg adm-lg--paused" /></svg>{t('adm.mkt.map.lg.paused', undefined, locale)}</li>
        <li><svg width="14" height="14" aria-hidden="true"><circle cx="7" cy="7" r="4" className="adm-lg adm-lg--planned" /></svg>{t('adm.mkt.map.lg.planned', undefined, locale)}</li>
        <li><svg width="22" height="14" aria-hidden="true"><line x1="1" y1="7" x2="21" y2="7" className="adm-lg adm-lg--route" /></svg>{t('adm.mkt.map.lg.route', undefined, locale)}</li>
        <li><svg width="14" height="14" aria-hidden="true"><rect x="4" y="4" width="6" height="6" className="adm-lg adm-lg--rest" /></svg>{t('adm.mkt.map.lg.restaurant', undefined, locale)}</li>
      </ul>
      <p className="adm-map__note">{t('adm.mkt.map.note', undefined, locale)}</p>
    </div>
  )
}

/* ------------------------------------------------------------------ /admin/markets — Market Control Center */
export function MarketsOverviewPage() {
  const a = useAdmin(); const locale = a.locale; usePageTitle('adm.nav.markets')
  const { data, state, reload } = useLoad(() => a.repos.marketControl.overview(), [a.repos])
  const [sel, setSel] = useState<string | null>(null)
  if (state === 'loading') return <div className="db-page" data-testid="adm-markets"><p className="db-muted" role="status">{t('adm.mkt.loading.market', undefined, locale)}</p><Skeleton rows={6} /></div>
  if (state === 'error' || !data) return <div className="db-page" data-testid="adm-markets"><ErrorState title={t('adm.mkt.error.market', undefined, locale)} text={t('adm.error.loadText', undefined, locale)} onRetry={() => { void reload() }} locale={locale} /></div>
  const snap = data.active; const m = snap.market; const s = snap.stats; const future = data.markets.filter((x) => x.countryCode !== m.countryCode)
  return (
    <div className="db-page" data-testid="adm-markets">
      <div className="db-page__head"><div><h1 className="db-page__title">{t('adm.mkt.title', undefined, locale)}</h1><p className="db-page__lead">{t('adm.mkt.lead', { market: m.displayName }, locale)}</p></div>
        <div className="db-page__actions"><Link to={`${BASE}/markets/registry`} className="db-btn db-btn--outline">{t('adm.mkt.registry', undefined, locale)}</Link><Link to={`${BASE}/markets/${m.slug}`} className="db-btn db-btn--primary" data-testid="manage-market">{t('adm.mkt.manage', { market: m.displayName }, locale)}</Link></div></div>
      <div className="db-grid db-grid--kpi">
        <KpiCard icon="markets" tone="green" value={data.activeMarkets.toLocaleString(locale)} label={t('adm.mkt.kpi.activeMarkets', undefined, locale)} locale={locale} testId="kpi-active-markets" />
        <KpiCard icon="location" tone="blue" value={s.activeCities.toLocaleString(locale)} label={t('adm.mkt.kpi.activeCities', undefined, locale)} locale={locale} testId="kpi-active-cities" />
        <KpiCard icon="pickup" tone="purple" value={s.activeServiceAreas.toLocaleString(locale)} label={t('adm.mkt.kpi.serviceAreas', undefined, locale)} locale={locale} testId="kpi-service-areas" />
        <KpiCard icon="restaurants" tone="orange" value={s.restaurants.toLocaleString(locale)} label={t('adm.mkt.kpi.restaurants', undefined, locale)} locale={locale} testId="kpi-market-restaurants" />
      </div>
      <div className="adm-stats" data-testid="market-secondary"><Stat label={t('adm.mkt.kpi.ordersToday', undefined, locale)} value={s.ordersToday.toLocaleString(locale)} /><Stat label={t('adm.mkt.kpi.customers', undefined, locale)} value={s.customers.toLocaleString(locale)} /><Stat label={t('adm.mkt.kpi.gmv', { currency: s.currency }, locale)} value={formatMoney(s.gmvMinor, s.currency, locale)} /><Stat label={t('adm.mkt.kpi.pending', undefined, locale)} value={s.pendingApprovals.toLocaleString(locale)} tone={s.pendingApprovals ? 'amber' : undefined} /></div>
      <div className="adm-grid--12 adm-mkt-hero">
        <Card className="adm-mkt-card" title={<span className="adm-mkt-card__title"><span className="adm-mkt-card__code">{m.countryCode}</span>{m.displayName}</span>} actions={<MarketBadge status={m.status} />}>
          <Details rows={[[t('adm.mkt.f.country', undefined, locale), m.displayName], [t('adm.mkt.f.code', undefined, locale), m.countryCode], [t('adm.mkt.f.currency', undefined, locale), `${m.defaultCurrency} · ${formatMoney(124900, m.defaultCurrency, m.defaultLocale)}`], [t('adm.mkt.f.locale', undefined, locale), m.defaultLocale], [t('adm.mkt.f.timezone', undefined, locale), m.defaultTimezone], [t('adm.mkt.f.units', undefined, locale), unitLabel(m.distanceUnit, locale)], [t('adm.mkt.f.phone', undefined, locale), m.phoneCountryCode], [t('adm.mkt.f.serviceAreas', undefined, locale), `${s.activeServiceAreas} / ${s.serviceAreas}`], [t('adm.mkt.f.restaurants', undefined, locale), `${s.restaurants}`], [t('adm.mkt.f.orders', undefined, locale), `${s.orders}`]]} />
          <p style={{ margin: '14px 0 0' }}><Link to={`${BASE}/markets/${m.slug}`} className="db-link">{t('adm.mkt.open', { market: m.displayName }, locale)} →</Link></p>
        </Card>
        <Card title={t('adm.mkt.coverage', undefined, locale)} subtitle={t('adm.mkt.coverageSub', { market: m.displayName }, locale)}><CoverageMap snap={snap} selectedCity={sel} onSelectCity={setSel} /></Card>
      </div>
      {snap.attention.length > 0 && <Card title={t('adm.mkt.attention', undefined, locale)} tone="warn"><ul className="db-list" data-testid="market-attention">{snap.attention.slice(0, 5).map((x) => <li key={x.id}><Icon name={x.severity === 'warning' ? 'warning' : 'info'} size={16} /><span style={{ flex: 1 }}>{x.text}</span><Link to={x.link} className="db-link">{t('adm.action.review', undefined, locale)} →</Link></li>)}</ul></Card>}
      <Card title={t('adm.mkt.future', undefined, locale)} subtitle={t('adm.mkt.futureSub', undefined, locale)}>
        {future.length === 0 ? <EmptyState icon="markets" title={t('adm.mkt.empty.future', undefined, locale)} /> : <ul className="adm-future" data-testid="future-markets">{future.map((f) => <li key={f.countryCode}><span className="adm-mkt-card__code adm-mkt-card__code--muted">{f.countryCode}</span><span className="adm-future__text"><b>{f.displayName}</b><small>{f.defaultCurrency} · {f.defaultLocale} · {unitLabel(f.distanceUnit, locale)}</small></span><MarketBadge status={f.status} />{f.status === 'DRAFT' && <Badge tone="muted">{t('adm.market.comingLater', undefined, locale)}</Badge>}</li>)}</ul>}
      </Card>
      <DevNote>{t(api() ? 'adm.mkt.apiNote' : 'adm.mkt.devNote', undefined, locale)}</DevNote>
    </div>
  )
}

/* ------------------------------------------------------------------ /admin/markets/:slug — one market */
const SUBNAV: Array<{ id: string; path: string }> = [{ id: 'overview', path: '' }, { id: 'states', path: 'states' }, { id: 'cities', path: 'cities' }, { id: 'serviceAreas', path: 'service-areas' }, { id: 'routes', path: 'routes' }, { id: 'configuration', path: 'configuration' }, { id: 'features', path: 'features' }]
export function MarketLayout() {
  const a = useAdmin(); const locale = a.locale; const { slug = '' } = useParams(); usePageTitle('adm.nav.markets')
  const { data: snap, state, reload, refresh } = useLoad(() => a.repos.marketControl.snapshot(slug), [a.repos, slug])
  const [dialog, setDialog] = useState<MarketStatus | null>(null); const [busy, setBusy] = useState(false); const { msg, toast } = useToastMessage()
  if (state === 'loading') return <div className="db-page" data-testid="adm-market"><h1 className="db-page__title">{t('adm.mkt.title', undefined, locale)}</h1><p className="db-muted" role="status">{t('adm.mkt.loading.market', undefined, locale)}</p><Skeleton rows={6} /></div>
  if (state === 'error') return <div className="db-page" data-testid="adm-market"><ErrorState title={t('adm.mkt.error.market', undefined, locale)} text={t('adm.error.loadText', undefined, locale)} onRetry={() => { void reload() }} locale={locale} /></div>
  if (!snap) return <div className="db-page" data-testid="adm-market"><ErrorState title={t('adm.mkt.notFound', undefined, locale)} locale={locale} /><Link to={`${BASE}/markets`} className="db-link">← {t('adm.nav.markets', undefined, locale)}</Link></div>
  const m = snap.market; const canManage = a.can('markets.manage'); const base = `${BASE}/markets/${m.slug}`; const configured = snap.states.length > 0
  return (
    <div className="db-page" data-testid="adm-market">
      <Link to={`${BASE}/markets`} className="db-link">← {t('adm.mkt.title', undefined, locale)}</Link>
      <div className="db-page__head"><div className="adm-cell"><span className="adm-mkt-card__code adm-mkt-card__code--lg">{m.countryCode}</span><div><h1 className="db-page__title">{m.displayName} <MarketBadge status={m.status} /></h1><p className="db-page__lead">{m.defaultCurrency} · {m.defaultLocale} · {m.defaultTimezone} · {unitLabel(m.distanceUnit, locale)} · {m.phoneCountryCode}</p></div></div>
        {canManage && configured && <div className="db-page__actions">{m.status === 'ACTIVE' ? <button type="button" className="db-btn db-btn--danger" onClick={() => setDialog('PAUSED')} data-testid="market-pause">{t('adm.mkt.pause', undefined, locale)}</button> : m.status === 'PAUSED' ? <button type="button" className="db-btn db-btn--success" onClick={() => setDialog('ACTIVE')} data-testid="market-resume">{t('adm.mkt.resume', undefined, locale)}</button> : null}</div>}
      </div>
      {!configured ? <Card><EmptyState icon="markets" title={t('adm.mkt.draftTitle', { market: m.displayName }, locale)} text={t('adm.mkt.draftText', undefined, locale)} /></Card> : <>
        <nav className="db-tabs adm-subnav" aria-label={t('adm.mkt.subnav', { market: m.displayName }, locale)}>{SUBNAV.map((n) => <NavLink key={n.id} to={n.path ? `${base}/${n.path}` : base} end={!n.path} className={({ isActive }) => `db-tab ${isActive ? 'is-on' : ''}`} aria-current={undefined}>{t(`adm.mkt.tab.${n.id}`, undefined, locale)}</NavLink>)}</nav>
        <SnapshotCtx.Provider value={{ snap, refresh }}><Outlet /></SnapshotCtx.Provider>
      </>}
      <ReasonDialog open={!!dialog} title={dialog === 'PAUSED' ? t('adm.mkt.pauseTitle', { market: m.displayName }, locale) : t('adm.mkt.resumeTitle', { market: m.displayName }, locale)} text={t('adm.mkt.highImpact', undefined, locale)} confirmLabel={dialog === 'PAUSED' ? t('adm.mkt.pause', undefined, locale) : t('adm.mkt.resume', undefined, locale)} danger={dialog === 'PAUSED'} busy={busy} locale={locale} onCancel={() => setDialog(null)} impact={dialog === 'PAUSED' ? [t('adm.mkt.pauseImpact1', { n: snap.stats.visibleRestaurants }, locale), t('adm.mkt.pauseImpact2', undefined, locale), t('adm.mkt.pauseImpact3', undefined, locale)] : undefined} testId="market-status-dialog"
        onConfirm={async (reason) => { if (!dialog) return; setBusy(true); try { await a.repos.marketControl.setMarketStatus(m.countryCode, dialog, a.admin.id, reason); setDialog(null); await refresh(); toast(t('adm.updated', undefined, locale)) } catch (e) { setDialog(null); toast(failure(e, locale)); await refresh() } finally { setBusy(false) } }} />
      <ToastLine msg={msg} />
    </div>
  )
}

export function MarketOverviewTab() {
  const a = useAdmin(); const locale = a.locale; const { snap } = useSnapshot(); const s = snap.stats; const [sel, setSel] = useState<string | null>(null); const base = `${BASE}/markets/${snap.market.slug}`
  const listed = snap.cities.filter((c) => c.status === 'ACTIVE' || c.status === 'PILOT' || c.status === 'PAUSED')
  const selCity = snap.cities.find((c) => c.id === sel) ?? null
  return (
    <div className="db-grid" style={{ gap: 20 }} data-testid="market-overview">
      <div className="db-grid db-grid--kpi">
        <KpiCard icon="location" tone="blue" value={s.activeCities.toLocaleString(locale)} label={t('adm.mkt.kpi.activeCities', undefined, locale)} locale={locale} />
        <KpiCard icon="pickup" tone="purple" value={s.activeServiceAreas.toLocaleString(locale)} label={t('adm.mkt.kpi.serviceAreas', undefined, locale)} locale={locale} />
        <KpiCard icon="restaurants" tone="orange" value={s.restaurants.toLocaleString(locale)} label={t('adm.mkt.kpi.restaurants', undefined, locale)} locale={locale} />
        <KpiCard icon="orders" tone="green" value={s.ordersToday.toLocaleString(locale)} label={t('adm.mkt.kpi.ordersToday', undefined, locale)} locale={locale} />
      </div>
      <div className="adm-stats"><Stat label={t('adm.mkt.kpi.customers', undefined, locale)} value={s.customers.toLocaleString(locale)} /><Stat label={t('adm.mkt.kpi.gmv', { currency: s.currency }, locale)} value={formatMoney(s.gmvMinor, s.currency, locale)} /><Stat label={t('adm.mkt.kpi.routes', undefined, locale)} value={`${s.activeRoutes} / ${snap.routes.length}`} /><Stat label={t('adm.mkt.kpi.pending', undefined, locale)} value={s.pendingApprovals.toLocaleString(locale)} tone={s.pendingApprovals ? 'amber' : undefined} /></div>
      <div className="adm-grid--21">
        <Card title={t('adm.mkt.coverage', undefined, locale)} subtitle={t('adm.mkt.coverageSub', { market: snap.market.displayName }, locale)}><CoverageMap snap={snap} selectedCity={sel} onSelectCity={setSel} /></Card>
        <Card title={t('adm.mkt.cityList', undefined, locale)} actions={<Link to={`${base}/cities`} className="db-link">{t('adm.action.viewAll', undefined, locale)} →</Link>}>
          <ul className="adm-citylist" data-testid="map-city-list">{listed.map((c) => <li key={c.id}><button type="button" className={`adm-citylist__btn ${sel === c.id ? 'is-on' : ''}`} aria-pressed={sel === c.id} onClick={() => setSel(sel === c.id ? null : c.id)}><span><b>{c.name}</b><small>{snap.states.find((g) => g.id === c.regionId)?.name} · {t('adm.mkt.nRestaurants', { n: s.byCity[c.id].restaurants }, locale)}</small></span><MarketBadge status={c.status} /></button></li>)}</ul>
          {selCity && <p className="adm-note" role="status" data-testid="map-selection">{t('adm.mkt.selected', { city: selCity.name, areas: s.byCity[selCity.id].serviceAreas, restaurants: s.byCity[selCity.id].restaurants }, locale)}</p>}
        </Card>
      </div>
      {snap.attention.length > 0 && <Card title={t('adm.mkt.attention', undefined, locale)} tone="warn"><ul className="db-list" data-testid="market-attention">{snap.attention.map((x) => <li key={x.id}><Icon name={x.severity === 'warning' ? 'warning' : 'info'} size={16} /><span style={{ flex: 1 }}>{x.text}</span><Link to={x.link} className="db-link">{t('adm.action.review', undefined, locale)} →</Link></li>)}</ul></Card>}
    </div>
  )
}

/* ------------------------------------------------------------------ status tables */
type StatusTarget = { kind: 'state' | 'city' | 'area' | 'route'; id: string; name: string; from: string; to: string; impact?: string[] }
function useStatusChange() {
  const a = useAdmin(); const locale = a.locale; const { refresh } = useSnapshot(); const [target, setTarget] = useState<StatusTarget | null>(null); const [busy, setBusy] = useState(false); const { msg, toast } = useToastMessage()
  const stops = target ? ['PAUSED', 'DISABLED', 'UNAVAILABLE'].includes(target.to) : false
  const node: ReactNode = <>
    <ReasonDialog open={!!target} title={target ? t('adm.mkt.statusTitle', { name: target.name, status: t(`adm.marketStatus.${target.to}`, undefined, locale) }, locale) : ''} text={t('adm.mkt.highImpact', undefined, locale)} confirmLabel={t('adm.action.apply', undefined, locale)} danger={stops} busy={busy} locale={locale} onCancel={() => setTarget(null)} impact={target?.impact} testId="geo-status-dialog"
      onConfirm={async (reason) => { if (!target) return; setBusy(true); try { const r = a.repos.marketControl; if (target.kind === 'state') await r.setStateStatus(target.id, target.to as RegionStatus, a.admin.id, reason); else if (target.kind === 'city') await r.setCityStatus(target.id, target.to as CityStatus, a.admin.id, reason); else if (target.kind === 'area') await r.setServiceAreaStatus(target.id, target.to as ServiceAreaStatus, a.admin.id, reason); else await r.setRouteStatus(target.id, target.to as RouteStatus, a.admin.id, reason); setTarget(null); await refresh(); toast(t('adm.updated', undefined, locale)) } catch (e) { setTarget(null); toast(failure(e, locale)); await refresh() } finally { setBusy(false) } }} />
    <ToastLine msg={msg} />
  </>
  return { setTarget, node }
}
function StatusSelect({ value, options, onPick, label, testId }: { value: string; options: string[]; onPick: (to: string) => void; label: string; testId: string }) {
  const a = useAdmin()
  return <select className="db-select db-input--sm adm-status-select" value={value} aria-label={label} onChange={(e) => { if (e.target.value !== value) onPick(e.target.value) }} data-testid={testId}>{options.map((o) => <option key={o} value={o}>{t(`adm.marketStatus.${o}`, undefined, a.locale)}</option>)}</select>
}
function Filters({ q, setQ, status, setStatus, statuses, placeholder }: { q: string; setQ: (v: string) => void; status: string; setStatus: (v: string) => void; statuses: string[]; placeholder: string }) {
  const a = useAdmin(); const locale = a.locale
  return <div className="db-toolbar adm-toolbar"><label className="db-search"><span className="db-sr-only">{placeholder}</span><Icon name="search" size={18} /><input className="db-input" type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder={placeholder} data-testid="geo-search" /></label><Select label={t('adm.status.all', undefined, locale)} value={status} onChange={setStatus} options={[{ value: 'all', label: t('adm.status.all', undefined, locale) }, ...statuses.map((s) => ({ value: s, label: t(`adm.marketStatus.${s}`, undefined, locale) }))]} testId="geo-status-filter" /></div>
}
const has = (q: string, ...f: string[]) => !q.trim() || f.some((x) => x.toLowerCase().includes(q.trim().toLowerCase()))

export function MarketStatesTab() {
  const a = useAdmin(); const locale = a.locale; const { snap } = useSnapshot(); const { setTarget, node } = useStatusChange(); const can = a.can('markets.manage')
  const [q, setQ] = useState(''); const [st, setSt] = useState('all'); const rows = snap.states.filter((g) => (st === 'all' || g.status === st) && has(q, g.name, g.code))
  return (
    <Card title={t('adm.mkt.tab.states', undefined, locale)} subtitle={t('adm.mkt.statesSub', undefined, locale)}>
      <Filters q={q} setQ={setQ} status={st} setStatus={setSt} statuses={REGION_STATUSES()} placeholder={t('adm.mkt.search.states', undefined, locale)} />
      {rows.length === 0 ? <EmptyState icon="markets" title={t('adm.mkt.empty.states', undefined, locale)} /> : <div className="db-table-wrap" tabIndex={0}><table className="db-table" data-testid="states-table"><caption className="db-sr-only">{t('adm.mkt.tab.states', undefined, locale)}</caption><thead><tr><th scope="col">{t('adm.mkt.col.state', undefined, locale)}</th><th scope="col">{t('adm.mkt.col.code', undefined, locale)}</th><th scope="col">{t('adm.mkt.col.status', undefined, locale)}</th><th scope="col" className="adm-td--end">{t('adm.mkt.col.activeCities', undefined, locale)}</th><th scope="col" className="adm-td--end adm-hide-mobile">{t('adm.mkt.col.serviceAreas', undefined, locale)}</th><th scope="col" className="adm-td--end adm-hide-mobile">{t('adm.mkt.col.restaurants', undefined, locale)}</th>{can && <th scope="col" className="adm-td--end">{t('adm.table.actions', undefined, locale)}</th>}</tr></thead>
        <tbody>{rows.map((g) => { const r = snap.stats.byRegion[g.id]; return <tr key={g.id}><td><Cell primary={g.name} secondary={t(`adm.mkt.kind.${g.kind}`, undefined, locale)} /></td><td><span className="adm-code">{g.code}</span></td><td><MarketBadge status={g.status} /></td><td className="adm-td--end">{r.activeCities} / {r.cities}</td><td className="adm-td--end adm-hide-mobile">{r.serviceAreas}</td><td className="adm-td--end adm-hide-mobile">{r.restaurants}</td>{can && <td className="adm-td--end"><StatusSelect value={g.status} options={REGION_STATUSES()} label={t('adm.mkt.changeStatus', { name: g.name }, locale)} testId={`state-status-${g.id}`} onPick={(to) => setTarget({ kind: 'state', id: g.id, name: g.name, from: g.status, to, impact: to === 'DISABLED' || to === 'PLANNED' ? [t('adm.mkt.impact.state', { cities: r.activeCities, restaurants: r.restaurants }, locale)] : undefined })} /></td>}</tr> })}</tbody></table></div>}
      <DevNote>{t('adm.mkt.statesNote', undefined, locale)}</DevNote>{node}
    </Card>
  )
}
export function MarketCitiesTab() {
  const a = useAdmin(); const locale = a.locale; const { snap, refresh } = useSnapshot(); const { setTarget, node } = useStatusChange(); const can = a.can('cities.manage')
  const edit = can && api(); const [form, setForm] = useState<{ city: City | null } | null>(null); const { msg, toast } = useToastMessage()
  const [q, setQ] = useState(''); const [st, setSt] = useState('all'); const region = (c: City) => snap.states.find((g) => g.id === c.regionId)?.name ?? ''
  const rows = snap.cities.filter((c) => (st === 'all' || c.status === st) && has(q, c.name, region(c), ...c.aliases))
  return (
    <Card title={t('adm.mkt.tab.cities', undefined, locale)} subtitle={t('adm.mkt.citiesSub', undefined, locale)} actions={edit ? <button type="button" className="db-btn db-btn--primary" onClick={() => setForm({ city: null })} data-testid="city-add">{t('adm.geo.addCity', undefined, locale)}</button> : undefined}>
      <Filters q={q} setQ={setQ} status={st} setStatus={setSt} statuses={['ACTIVE', 'PILOT', 'PLANNED', 'PAUSED', 'UNAVAILABLE']} placeholder={t('adm.mkt.search.cities', undefined, locale)} />
      {rows.length === 0 ? <EmptyState icon="location" title={t('adm.mkt.empty.cities', undefined, locale)} /> : <div className="db-table-wrap" tabIndex={0}><table className="db-table" data-testid="cities-table"><caption className="db-sr-only">{t('adm.mkt.tab.cities', undefined, locale)}</caption><thead><tr><th scope="col">{t('adm.mkt.col.city', undefined, locale)}</th><th scope="col">{t('adm.mkt.col.state', undefined, locale)}</th><th scope="col">{t('adm.mkt.col.status', undefined, locale)}</th><th scope="col" className="adm-td--end">{t('adm.mkt.col.serviceAreas', undefined, locale)}</th><th scope="col" className="adm-td--end">{t('adm.mkt.col.restaurants', undefined, locale)}</th><th scope="col" className="adm-td--end adm-hide-mobile">{t('adm.mkt.col.orders', undefined, locale)}</th><th scope="col" className="adm-hide-mobile">{t('adm.mkt.col.launch', undefined, locale)}</th>{can && <th scope="col" className="adm-td--end">{t('adm.table.actions', undefined, locale)}</th>}</tr></thead>
        <tbody>{rows.map((c) => { const r = snap.stats.byCity[c.id]; return <tr key={c.id}><td><Cell primary={c.name} secondary={c.timezone} /></td><td>{region(c)}</td><td><MarketBadge status={c.status} /></td><td className="adm-td--end">{r.serviceAreas}</td><td className="adm-td--end">{r.restaurants}</td><td className="adm-td--end adm-hide-mobile">{r.orders}</td><td className="adm-hide-mobile">{c.launchStage}{c.launchDate ? ` · ${fmtDate(c.launchDate, locale)}` : ''}</td>{can && <td className="adm-td--end"><StatusSelect value={c.status} options={['ACTIVE', 'PILOT', 'PLANNED', 'PAUSED', 'UNAVAILABLE']} label={t('adm.mkt.changeStatus', { name: c.name }, locale)} testId={`city-status-${c.id}`} onPick={(to) => setTarget({ kind: 'city', id: c.id, name: c.name, from: c.status, to, impact: ['PAUSED', 'UNAVAILABLE', 'PLANNED'].includes(to) ? [t('adm.mkt.impact.city', { restaurants: r.restaurants }, locale), t('adm.mkt.impact.orders', undefined, locale)] : undefined })} />{edit && <button type="button" className="db-btn db-btn--ghost db-btn--sm" onClick={() => setForm({ city: c })} aria-label={t('adm.geo.editCity', { name: c.name }, locale)} data-testid={`city-edit-${c.id}`}>{t('adm.geo.edit', undefined, locale)}</button>}</td>}</tr> })}</tbody></table></div>}
      <DevNote>{t('adm.mkt.citiesNote', undefined, locale)}</DevNote>{node}
      <CityFormDrawer open={!!form} city={form?.city ?? null} regions={snap.states} defaultTimezone={snap.market.defaultTimezone} locale={locale} onClose={() => setForm(null)}
        onSave={async (input, reason) => { const r = a.repos.marketControl; if (form?.city) await r.updateCity(form.city.id, input, reason); else await r.createCity(snap.market.countryCode, input); setForm(null); await refresh(); toast(t(form?.city ? 'adm.updated' : 'adm.geo.cityCreated', undefined, locale)) }} />
      <ToastLine msg={msg} />
    </Card>
  )
}
export function MarketServiceAreasTab() {
  const a = useAdmin(); const locale = a.locale; const { snap, refresh } = useSnapshot(); const { setTarget, node } = useStatusChange(); const can = a.can('service_areas.manage')
  const edit = can && api(); const [form, setForm] = useState<{ area: ServiceArea | null } | null>(null); const { msg, toast } = useToastMessage()
  const [q, setQ] = useState(''); const [st, setSt] = useState('all'); const cityName = (s: ServiceArea) => snap.cities.find((c) => c.id === s.cityId)?.name ?? ''
  const rows = snap.serviceAreas.filter((s) => (st === 'all' || s.status === st) && has(q, s.name, cityName(s)))
  const routesFor = (s: ServiceArea) => snap.routes.filter((r) => [r.originCityId, ...r.viaCityIds, r.destinationCityId].includes(s.cityId)).length
  return (
    <Card title={t('adm.mkt.tab.serviceAreas', undefined, locale)} subtitle={t('adm.mkt.areasSub', undefined, locale)} actions={edit ? <button type="button" className="db-btn db-btn--primary" onClick={() => setForm({ area: null })} data-testid="area-add">{t('adm.geo.addArea', undefined, locale)}</button> : undefined}>
      <Filters q={q} setQ={setQ} status={st} setStatus={setSt} statuses={AREA_STATUSES()} placeholder={t('adm.mkt.search.areas', undefined, locale)} />
      {rows.length === 0 ? <EmptyState icon="pickup" title={t('adm.mkt.empty.areas', undefined, locale)} /> : <div className="db-table-wrap" tabIndex={0}><table className="db-table" data-testid="areas-table"><caption className="db-sr-only">{t('adm.mkt.tab.serviceAreas', undefined, locale)}</caption><thead><tr><th scope="col">{t('adm.mkt.col.area', undefined, locale)}</th><th scope="col">{t('adm.mkt.col.city', undefined, locale)}</th><th scope="col">{t('adm.mkt.col.status', undefined, locale)}</th><th scope="col" className="adm-td--end">{t('adm.mkt.col.restaurants', undefined, locale)}</th><th scope="col" className="adm-td--end adm-hide-mobile">{t('adm.mkt.col.routes', undefined, locale)}</th><th scope="col" className="adm-hide-mobile">{t('adm.mkt.col.geometry', undefined, locale)}</th><th scope="col" className="adm-hide-mobile">{t('adm.mkt.col.updated', undefined, locale)}</th>{can && <th scope="col" className="adm-td--end">{t('adm.table.actions', undefined, locale)}</th>}</tr></thead>
        <tbody>{rows.map((s) => <tr key={s.id}><td><Cell primary={s.name} secondary={s.launchStage} /></td><td>{cityName(s)}</td><td><MarketBadge status={s.status} /></td><td className="adm-td--end">{snap.stats.byArea[s.id].restaurants}</td><td className="adm-td--end adm-hide-mobile">{routesFor(s)}</td><td className="adm-hide-mobile">{s.geometry.type === 'radius' ? t('adm.mkt.geometry.radius', { km: (s.geometry.radiusM / 1000).toLocaleString(locale) }, locale) : t('adm.mkt.geometry.polygon', undefined, locale)}</td><td className="adm-hide-mobile">{fmtDate(s.updatedAt, locale)}</td>{can && <td className="adm-td--end"><StatusSelect value={s.status} options={AREA_STATUSES()} label={t('adm.mkt.changeStatus', { name: s.name }, locale)} testId={`area-status-${s.id}`} onPick={(to) => setTarget({ kind: 'area', id: s.id, name: s.name, from: s.status, to, impact: ['PAUSED', 'DISABLED', 'PLANNED'].includes(to) ? [t('adm.mkt.impact.city', { restaurants: snap.stats.byArea[s.id].restaurants }, locale)] : undefined })} />{edit && <button type="button" className="db-btn db-btn--ghost db-btn--sm" onClick={() => setForm({ area: s })} aria-label={t('adm.geo.editArea', { name: s.name }, locale)} data-testid={`area-edit-${s.id}`}>{t('adm.geo.edit', undefined, locale)}</button>}</td>}</tr>)}</tbody></table></div>}
      <DevNote>{t('adm.mkt.areasNote', undefined, locale)}</DevNote>{node}
      <ServiceAreaFormDrawer open={!!form} area={form?.area ?? null} cities={snap.cities} locale={locale} onClose={() => setForm(null)}
        onSave={async (input, reason) => { const r = a.repos.marketControl; if (form?.area) await r.updateServiceArea(form.area.id, input, reason); else await r.createServiceArea(snap.market.countryCode, input); setForm(null); await refresh(); toast(t(form?.area ? 'adm.updated' : 'adm.geo.areaCreated', undefined, locale)) }} />
      <ToastLine msg={msg} />
    </Card>
  )
}
export function MarketRoutesTab() {
  const a = useAdmin(); const locale = a.locale; const { snap } = useSnapshot(); const { setTarget, node } = useStatusChange(); const can = a.can('service_areas.manage')
  const [q, setQ] = useState(''); const [st, setSt] = useState('all'); const cn = (id: string) => snap.cities.find((c) => c.id === id)?.name ?? id
  const regionOf = (r: RouteCorridor) => Array.from(new Set([r.originCityId, r.destinationCityId].map((id) => snap.states.find((g) => g.id === snap.cities.find((c) => c.id === id)?.regionId)?.name).filter(Boolean))).join(' → ')
  const rows = snap.routes.filter((r) => (st === 'all' || r.status === st) && has(q, r.name, r.highway ?? ''))
  return (
    <Card title={t('adm.mkt.tab.routes', undefined, locale)} subtitle={t('adm.mkt.routesSub', undefined, locale)}>
      <Filters q={q} setQ={setQ} status={st} setStatus={setSt} statuses={ROUTE_STATUSES()} placeholder={t('adm.mkt.search.routes', undefined, locale)} />
      {rows.length === 0 ? <EmptyState icon="location" title={t('adm.mkt.empty.routes', undefined, locale)} /> : <div className="db-table-wrap" tabIndex={0}><table className="db-table" data-testid="routes-table"><caption className="db-sr-only">{t('adm.mkt.tab.routes', undefined, locale)}</caption><thead><tr><th scope="col">{t('adm.mkt.col.route', undefined, locale)}</th><th scope="col" className="adm-hide-mobile">{t('adm.mkt.col.region', undefined, locale)}</th><th scope="col" className="adm-td--end">{t('adm.mkt.col.restaurants', undefined, locale)}</th><th scope="col">{t('adm.mkt.col.status', undefined, locale)}</th><th scope="col" className="adm-hide-mobile">{t('adm.mkt.col.updated', undefined, locale)}</th>{can && <th scope="col" className="adm-td--end">{t('adm.table.actions', undefined, locale)}</th>}</tr></thead>
        <tbody>{rows.map((r) => <tr key={r.id}><td><Cell primary={r.name} secondary={`${r.highway ?? ''}${r.viaCityIds.length ? ` · ${t('adm.mkt.via', { cities: r.viaCityIds.map(cn).join(', ') }, locale)}` : ''}`} /></td><td className="adm-hide-mobile">{regionOf(r)}</td><td className="adm-td--end">{snap.stats.byRoute[r.id].restaurants}</td><td><MarketBadge status={r.status} /></td><td className="adm-hide-mobile">{fmtDate(r.updatedAt, locale)}</td>{can && <td className="adm-td--end"><StatusSelect value={r.status} options={ROUTE_STATUSES()} label={t('adm.mkt.changeStatus', { name: r.name }, locale)} testId={`route-status-${r.id}`} onPick={(to) => setTarget({ kind: 'route', id: r.id, name: r.name, from: r.status, to })} /></td>}</tr>)}</tbody></table></div>}
      <DevNote>{t('adm.mkt.routesNote', undefined, locale)}</DevNote>{node}
    </Card>
  )
}

/* ------------------------------------------------------------------ configuration & features */
type CfgTab = 'regional' | 'payments' | 'tax' | 'legal'
export function MarketConfigurationTab() {
  const a = useAdmin(); const locale = a.locale; const { snap } = useSnapshot(); const m = snap.market; const c = snap.configuration; const [tab, setTab] = useState<CfgTab>('regional')
  if (!a.can('market_configuration.view')) return <ErrorState title={t('adm.error.forbiddenTitle', undefined, locale)} text={t('adm.error.forbiddenText', { role: t(`adm.role.${a.admin.role}`, undefined, locale) }, locale)} locale={locale} />
  if (!c) return <Card><EmptyState title={t('adm.mkt.error.config', undefined, locale)} /></Card>
  const sample = [24900, 124900, 1249900].map((v) => formatMoney(v, m.defaultCurrency, m.defaultLocale)).join(' · ')
  const now = new Date()
  return (
    <div className="db-grid" style={{ gap: 16 }} data-testid="market-configuration">
      <Tabs tabs={(['regional', 'payments', 'tax', 'legal'] as CfgTab[]).map((id) => ({ id, label: t(`adm.mkt.cfg.${id}`, undefined, locale) }))} value={tab} onChange={setTab} label={t('adm.mkt.tab.configuration', undefined, locale)} />
      {tab === 'regional' && <div className="adm-settings-grid">
        <Card title={t('adm.mkt.cfg.currency', undefined, locale)} subtitle={t('adm.mkt.cfg.currencySub', undefined, locale)} actions={<Badge tone="muted">{t('adm.config.locked', undefined, locale)}</Badge>}><Details rows={[[t('adm.mkt.f.currency', undefined, locale), `${m.defaultCurrency} (ISO 4217)`], [t('adm.mkt.cfg.sample', undefined, locale), <span key="s" data-testid="cfg-money-sample">{sample}</span>]]} /><DevNote>{t('adm.mkt.cfg.currencyNote', undefined, locale)}</DevNote></Card>
        <Card title={t('adm.mkt.cfg.locale', undefined, locale)} subtitle={t('adm.mkt.cfg.localeSub', undefined, locale)}><Details rows={[[t('adm.mkt.cfg.primaryLocale', undefined, locale), m.defaultLocale], [t('adm.mkt.cfg.dateSample', undefined, locale), now.toLocaleDateString(m.defaultLocale, { dateStyle: 'medium', timeZone: m.defaultTimezone })], [t('adm.mkt.cfg.timeSample', undefined, locale), now.toLocaleTimeString(m.defaultLocale, { timeStyle: 'short', timeZone: m.defaultTimezone })]]} /><p className="db-field__label" style={{ marginTop: 12 }}>{t('adm.mkt.cfg.plannedLocales', undefined, locale)}</p><div className="db-tags">{m.plannedLocales.map((l) => <Badge key={l} tone="amber">{l}</Badge>)}</div></Card>
        <Card title={t('adm.mkt.cfg.timezone', undefined, locale)} subtitle={t('adm.mkt.cfg.timezoneSub', undefined, locale)}><Details rows={[[t('adm.mkt.f.timezone', undefined, locale), `${m.defaultTimezone} (IANA)`], [t('adm.mkt.cfg.strategy', undefined, locale), t(`adm.mkt.cfg.strategy.${m.timezoneStrategy}`, undefined, locale)]]} /></Card>
        <Card title={t('adm.mkt.cfg.units', undefined, locale)}><Details rows={[[t('adm.mkt.f.units', undefined, locale), unitLabel(m.distanceUnit, locale)], [t('adm.mkt.f.phone', undefined, locale), `${m.phoneCountryCode} (${t('adm.mkt.cfg.phoneNote', undefined, locale)})`]]} /></Card>
        <Card title={t('adm.mkt.cfg.address', undefined, locale)} subtitle={t('adm.mkt.cfg.addressSub', undefined, locale)}><div className="db-tags">{c.address.fields.map((f) => <Badge key={f} tone="muted">{f}</Badge>)}</div><Details rows={[[t('adm.mkt.cfg.postal', undefined, locale), `${c.address.postalCodeLabel} (${c.address.postalCodeExample})`], [t('adm.mkt.cfg.adminArea', undefined, locale), c.address.adminAreaLabel]]} /></Card>
      </div>}
      {tab === 'payments' && <Card title={t('adm.mkt.cfg.payments', undefined, locale)} subtitle={c.payment.providerStrategy}>
        <div className="db-table-wrap" tabIndex={0}><table className="db-table" data-testid="cfg-payments"><caption className="db-sr-only">{t('adm.mkt.cfg.payments', undefined, locale)}</caption><thead><tr><th scope="col">{t('adm.mkt.cfg.method', undefined, locale)}</th><th scope="col">{t('adm.mkt.col.status', undefined, locale)}</th><th scope="col">{t('adm.mkt.cfg.note', undefined, locale)}</th></tr></thead><tbody>{c.payment.methods.map((p) => <tr key={p.method}><td className="adm-td--primary">{t(`adm.mkt.pay.${p.method}`, undefined, locale)}</td><td><Badge tone={p.status === 'ENABLED' ? 'green' : p.status === 'PLANNED' ? 'amber' : 'red'} dot>{t(`adm.mkt.payStatus.${p.status}`, undefined, locale)}</Badge></td><td>{p.note ?? '—'}</td></tr>)}</tbody></table></div>
        <Details rows={[[t('adm.mkt.cfg.providers', undefined, locale), c.payment.candidateProviders.join(', ')]]} /><DevNote>{t('adm.mkt.cfg.paymentsNote', undefined, locale)}</DevNote>
      </Card>}
      {tab === 'tax' && <Card title={t('adm.mkt.cfg.tax', undefined, locale)} subtitle={c.tax.regime} actions={<Badge tone="amber">{t('adm.mkt.cfg.pendingBackend', undefined, locale)}</Badge>}><p data-testid="cfg-tax" style={{ margin: 0 }}>{c.tax.note}</p><DevNote>{t('adm.mkt.cfg.taxNote', undefined, locale)}</DevNote></Card>}
      {tab === 'legal' && <Card title={t('adm.mkt.cfg.legal', undefined, locale)} subtitle={t('adm.settings.legalNote', undefined, locale)}><ul className="adm-legal" data-testid="cfg-legal">{c.legal.documents.map((d) => <li key={d.key}><span>{t(`adm.settings.legal.${d.key}`, undefined, locale)} <small className="db-muted">{t('adm.settings.version', { v: d.version }, locale)}</small></span><Badge tone="amber">{t('adm.settings.pendingApproval', undefined, locale)}</Badge></li>)}</ul></Card>}
    </div>
  )
}
export function MarketFeaturesTab() {
  const a = useAdmin(); const locale = a.locale; const { snap, refresh } = useSnapshot(); const can = a.can('market_features.manage'); const [target, setTarget] = useState<MarketFeature | null>(null); const [busy, setBusy] = useState(false); const { msg, toast } = useToastMessage()
  const features = snap.configuration?.features ?? []
  return (
    <Card title={t('adm.mkt.tab.features', undefined, locale)} subtitle={t('adm.mkt.featuresSub', { market: snap.market.displayName }, locale)}>
      {features.length === 0 ? <EmptyState title={t('adm.mkt.error.config', undefined, locale)} /> : <div data-testid="market-features">{features.map((f) => <div key={f.key} className="adm-flag"><span className="adm-flag__text"><b>{t(`adm.mkt.feature.${f.key}`, undefined, locale)} {f.locked && <Badge tone="muted">{t('adm.config.locked', undefined, locale)}</Badge>}</b><small>{f.note ?? t(`adm.mkt.feature.${f.key}.desc`, undefined, locale)}</small></span><Toggle checked={f.enabled} onChange={() => setTarget(f)} label={t(`adm.mkt.feature.${f.key}`, undefined, locale)} disabled={f.locked || !can} testId={`mfeature-${f.key}`} /></div>)}</div>}
      <DevNote>{t('adm.config.flagNote', undefined, locale)}</DevNote>
      <ReasonDialog open={!!target} title={target ? t('adm.config.flagTitle', { key: t(`adm.mkt.feature.${target.key}`, undefined, locale), state: t(target.enabled ? 'adm.config.flagOff' : 'adm.config.flagOn', undefined, locale) }, locale) : ''} text={t('adm.mkt.highImpact', undefined, locale)} confirmLabel={t('adm.action.apply', undefined, locale)} danger={!!target?.enabled} busy={busy} locale={locale} onCancel={() => setTarget(null)} testId="mfeature-dialog"
        onConfirm={async (reason) => { if (!target) return; setBusy(true); try { await a.repos.marketControl.setFeature(snap.market.countryCode, target.key, !target.enabled, a.admin.id, reason); setTarget(null); await refresh(); toast(t('adm.updated', undefined, locale)) } catch (e) { setTarget(null); toast(failure(e, locale)); await refresh() } finally { setBusy(false) } }} />
      <ToastLine msg={msg} />
    </Card>
  )
}

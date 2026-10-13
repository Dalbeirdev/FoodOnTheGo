import { useCallback, useEffect, useId, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { t } from '../../../i18n/strings'
import {
  ANALYTICS_PRESETS, DEFAULT_PRESET, MAX_RANGE_DAYS, describeAnalyticsError,
  type AnalyticsFilter, type AnalyticsMeta, type AnalyticsPreset, type EarningsReport, type MenuReport, type OperationsReport, type OrdersReport, type OverviewReport,
  type RestaurantAnalyticsReports, type ReviewsReport, type RoutesReport, type ScopeLocationDto,
} from '../../api/apiAnalytics'
import { useDashboard } from '../../DashboardContext'
import { Card, ErrorState, PageHeader, Skeleton, Tabs } from '../../components/ui'
import { ExportsTab } from './ExportsTab'
import { daysBetween, fmtDay } from './format'
import { MetaStrip, type Definitions } from './parts'
import { EarningsTab, MenuTab, OperationsTab, OrdersTab, OverviewTab, ReviewsTab, RoutesTab } from './tabs'
import { useReport } from './useReport'

/**
 * Analytics (Module 38) on the backend: the restaurant's OWN figures — orders, menu, operations, route discovery,
 * reviews, earnings — for the locations the signed-in person may read and a period they choose.
 *
 *  - Location and period are shared by every tab and kept in the URL, like the tab itself. "All my locations" sends
 *    no location at all: the backend answers for every location the person may read. A person with one location is
 *    not asked to choose.
 *  - Nothing is calculated here. Every figure, its definition, the time zone, the currency and how fresh it is are the
 *    backend's; aggregated figures are "updated about every 5 minutes" and are never presented as this very moment.
 *  - No other restaurant, no ranking, no customer by name, no traveller's route — the backend sends none.
 */
type ReportTab = 'overview' | 'orders' | 'menu' | 'operations' | 'routes' | 'reviews' | 'earnings'
type Tab = ReportTab | 'exports'
const TABS: Tab[] = ['overview', 'orders', 'menu', 'operations', 'routes', 'reviews', 'earnings', 'exports']
type Loaded =
  | { tab: 'overview'; data: OverviewReport } | { tab: 'orders'; data: OrdersReport } | { tab: 'menu'; data: MenuReport } | { tab: 'operations'; data: OperationsReport }
  | { tab: 'routes'; data: RoutesReport } | { tab: 'reviews'; data: ReviewsReport } | { tab: 'earnings'; data: EarningsReport }

async function load(repo: RestaurantAnalyticsReports, tab: ReportTab, f: AnalyticsFilter): Promise<Loaded> {
  switch (tab) {
    case 'overview': return { tab, data: await repo.overview(f) }
    case 'orders': return { tab, data: await repo.orders(f) }
    case 'menu': return { tab, data: await repo.menu(f) }
    case 'operations': return { tab, data: await repo.operations(f) }
    case 'routes': return { tab, data: await repo.routes(f) }
    case 'reviews': return { tab, data: await repo.reviews(f) }
    case 'earnings': return { tab, data: await repo.earnings(f) }
  }
}

export function BackendAnalyticsPage({ repo, pollMs = 3000 }: { repo: RestaurantAnalyticsReports; /** How often the export list is read again while an export is being prepared. */ pollMs?: number }) {
  const d = useDashboard(); const locale = d.locale
  const [params, setParams] = useSearchParams()
  useEffect(() => { document.title = `${t('dash.nav.analytics', undefined, locale)} · ${t('dash.brand', undefined, locale)}` }, [locale])

  /* ---- what the URL says: tab, location, period ---- */
  const canExport = d.can('analytics.export')
  const tabs = useMemo(() => TABS.filter((x) => x !== 'exports' || canExport), [canExport])
  const asked = params.get('tab') as Tab | null
  const tab: Tab = asked && tabs.includes(asked) ? asked : 'overview'
  const location = params.get('location') || 'all'
  const period = params.get('period') as AnalyticsPreset | null
  const preset: AnalyticsPreset = period && ANALYTICS_PRESETS.includes(period) ? period : DEFAULT_PRESET
  const from = params.get('from') ?? ''; const to = params.get('to') ?? ''
  const patch = useCallback((changes: Record<string, string | null>) => {
    setParams((prev) => { const next = new URLSearchParams(prev); for (const [key, value] of Object.entries(changes)) { if (value === null || value === '') next.delete(key); else next.set(key, value) } return next }, { replace: true })
  }, [setParams])

  /* ---- the definitions of the figures: read once per page load ---- */
  const catalog = useReport(() => repo.catalog(), [repo])
  const defs = useMemo<Definitions>(() => { const byKey = new Map((catalog.data?.metrics ?? []).map((m) => [m.key, m])); return { state: catalog.state, find: (key) => byKey.get(key) } }, [catalog.data, catalog.state])
  const maxDays = catalog.data?.periods?.max_range_days ?? MAX_RANGE_DAYS

  /* ---- a custom period is asked only when it is a period ---- */
  const span = preset === 'custom' ? daysBetween(from, to) : null
  const incomplete = preset === 'custom' && span === null
  const rangeError = preset !== 'custom' || span === null ? null : span < 1 ? t('dash.ra.period.order', undefined, locale) : span > maxDays ? t('dash.ra.period.tooLong', { days: maxDays }, locale) : null
  const blocked = incomplete ? t('dash.ra.period.needBoth', undefined, locale) : rangeError
  const filter = useMemo<AnalyticsFilter>(() => ({ location, preset, from, to }), [location, preset, from, to])
  const report = useReport<Loaded>(tab === 'exports' || blocked ? null : () => load(repo, tab, filter), [repo, tab, filter, blocked], `${tab}|${location}|${preset}|${from}|${to}|${blocked ?? ''}`)
  const loaded = report.state === 'ready' && report.data && report.data.tab === tab ? report.data : null
  const problem = report.state === 'error' ? describeAnalyticsError(report.error, locale) : null

  /* ---- the locations the filter offers ---- */
  // What "all my locations" answered names every location the person may read. Until one such answer is here, the
  // locations the session already knows the person may read analytics for stand in (so a link to one location still
  // offers the others).
  const [known, setKnown] = useState<ScopeLocationDto[]>([]); const [lastMeta, setLastMeta] = useState<AnalyticsMeta | null>(null)
  useEffect(() => { const meta = report.data?.data.meta; if (!meta) return; setLastMeta(meta); if (meta.scope.all_readable) setKnown(meta.scope.locations) }, [report.data])
  const fromSession = useMemo<ScopeLocationDto[]>(() => d.accessibleLocations.filter((l) => l.live?.permissions.includes('analytics.view')).map((l) => ({ id: l.live!.locationId, name: l.restaurant.name, currency: l.restaurant.currency, timezone: l.restaurant.timezone })), [d.accessibleLocations])
  // The answer on screen counts at once (the remembered list only bridges the answers for ONE location, which name just that one).
  const answered = report.data?.data.meta ?? lastMeta
  const offered = report.data?.data.meta.scope.all_readable ? report.data.data.meta.scope.locations : known.length > 0 ? known : fromSession
  const named = answered?.scope.locations.find((l) => l.id === location)
  const options = location !== 'all' && !offered.some((l) => l.id === location) ? [...offered, { id: location, name: named?.name ?? t('dash.ra.location.selected', undefined, locale), currency: named?.currency ?? '', timezone: named?.timezone ?? '' }] : offered
  const showLocation = options.length > 1 || location !== 'all'

  const choosePeriod = (next: AnalyticsPreset) => {
    // Custom starts from the days that are on screen, so the two inputs are never empty by surprise.
    if (next === 'custom') patch({ period: 'custom', from: from || answered?.period.from || null, to: to || answered?.period.to || null })
    else patch({ period: next === DEFAULT_PRESET ? null : next, from: null, to: null })
  }
  const locationName = location === 'all' ? t('dash.ra.location.all', undefined, locale) : options.find((l) => l.id === location)?.name ?? ''
  const periodName = preset === 'custom' ? (span !== null ? `${fmtDay(from, locale)} – ${fmtDay(to, locale)}` : t('dash.ra.period.custom', undefined, locale)) : t(`dash.ra.period.${preset}`, undefined, locale)
  const invalid = rangeError ?? (problem?.kind === 'invalid' ? problem.message : null)
  const periodNoteId = useId()

  return (
    <div className="db-page" data-testid="db-analytics" data-source="backend" data-tab={tab}>
      <PageHeader title={t('dash.analytics.title', undefined, locale)} lead={t('dash.ra.lead', undefined, locale)} />
      <Tabs tabs={tabs.map((id) => ({ id, label: t(`dash.ra.tab.${id}`, undefined, locale) }))} value={tab} onChange={(next) => patch({ tab: next === 'overview' ? null : next })} label={t('dash.ra.tabs', undefined, locale)} testId="ra-tab" />

      <div className="ra-filters" role="group" aria-label={t('dash.ra.filters', undefined, locale)} data-testid="ra-filters">
        {showLocation && (
          <label className="db-field"><span className="db-field__label">{t('dash.ra.location', undefined, locale)}</span>
            <select className="db-select" value={location} onChange={(e) => patch({ location: e.target.value === 'all' ? null : e.target.value })} data-testid="ra-location">
              <option value="all">{t('dash.ra.location.all', undefined, locale)}</option>
              {options.map((l) => <option key={l.id} value={l.id}>{l.currency ? `${l.name} · ${l.currency}` : l.name}</option>)}
            </select>
          </label>
        )}
        <label className="db-field"><span className="db-field__label">{t('dash.ra.period', undefined, locale)}</span>
          <select className="db-select" value={preset} onChange={(e) => choosePeriod(e.target.value as AnalyticsPreset)} data-testid="ra-period">
            {ANALYTICS_PRESETS.map((p) => <option key={p} value={p}>{t(`dash.ra.period.${p}`, undefined, locale)}</option>)}
          </select>
        </label>
        {preset === 'custom' && <>
          <label className="db-field"><span className="db-field__label">{t('dash.ra.period.from', undefined, locale)}</span><input type="date" className="db-input" value={from} max={to || undefined} onChange={(e) => patch({ from: e.target.value })} aria-invalid={invalid ? true : undefined} aria-describedby={periodNoteId} data-testid="ra-period-from" /></label>
          <label className="db-field"><span className="db-field__label">{t('dash.ra.period.to', undefined, locale)}</span><input type="date" className="db-input" value={to} min={from || undefined} onChange={(e) => patch({ to: e.target.value })} aria-invalid={invalid ? true : undefined} aria-describedby={periodNoteId} data-testid="ra-period-to" /></label>
        </>}
      </div>
      {preset === 'custom' && (invalid
        ? <p id={periodNoteId} className="db-field__error" role="alert" data-testid="ra-period-error">{invalid}</p>
        : <p id={periodNoteId} className="db-muted ra-small" data-testid="ra-period-hint">{incomplete ? t('dash.ra.period.needBoth', undefined, locale) : t('dash.ra.period.hint', { days: maxDays }, locale)}</p>)}

      {tab === 'exports'
        ? <ExportsTab repo={repo} filter={filter} blocked={blocked} scope={`${locationName} · ${periodName}`} locale={locale} pollMs={pollMs} />
        : <>
          {loaded && <MetaStrip meta={loaded.data.meta} locale={locale} />}
          <div className="db-grid ra-panel" role="tabpanel" aria-label={t(`dash.ra.tab.${tab}`, undefined, locale)} data-testid={`ra-panel-${tab}`} data-state={blocked ? 'blocked' : report.state}>
            {report.state === 'loading' && <Card><Skeleton rows={6} /></Card>}
            {blocked && !rangeError && <Card><p className="db-muted ra-empty" role="status" data-testid="ra-period-incomplete">{blocked}</p></Card>}
            {problem && problem.kind !== 'invalid' && (
              // Earnings without the finance permission: said in one sentence, with nothing else on offer.
              tab === 'earnings' && problem.kind === 'forbidden' ? <Card><p className="ra-empty" role="status" data-testid="ra-earnings-restricted">{t('dash.ra.earnings.restricted', undefined, locale)}</p></Card>
                : problem.kind === 'forbidden' ? <div data-testid="ra-forbidden"><ErrorState title={t('dash.ra.error.forbiddenTitle', undefined, locale)} text={problem.message} locale={locale} /></div>
                  : <div data-testid="ra-error" data-kind={problem.kind}><ErrorState title={t('dash.ra.error.title', undefined, locale)} text={problem.message} onRetry={report.reload} locale={locale} /></div>
            )}
            {loaded?.tab === 'overview' && <OverviewTab data={loaded.data} defs={defs} locale={locale} />}
            {loaded?.tab === 'orders' && <OrdersTab data={loaded.data} defs={defs} locale={locale} />}
            {loaded?.tab === 'menu' && <MenuTab data={loaded.data} defs={defs} locale={locale} />}
            {loaded?.tab === 'operations' && <OperationsTab data={loaded.data} defs={defs} locale={locale} />}
            {loaded?.tab === 'routes' && <RoutesTab data={loaded.data} defs={defs} locale={locale} />}
            {loaded?.tab === 'reviews' && <ReviewsTab data={loaded.data} defs={defs} locale={locale} />}
            {loaded?.tab === 'earnings' && <EarningsTab data={loaded.data} locale={locale} />}
          </div>
        </>}
    </div>
  )
}

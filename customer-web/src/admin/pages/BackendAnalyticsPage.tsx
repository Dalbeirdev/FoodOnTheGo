import { useCallback, useMemo, useState } from 'react'
import { Icon, Tabs } from '../../dashboard/components/ui'
import { useAdmin } from '../AdminContext'
import { ApiAdminAnalyticsRepository, MAX_RANGE_DAYS, PERIOD_PRESETS, type AnalyticsMeta, type AnalyticsProblem, type AnalyticsQuery, type PeriodPreset } from '../api/ApiAdminAnalyticsRepository'
import { DevNote, useUrlState } from '../components/DataTable'
import type { AdminPermission } from '../types'
import { customPeriodProblem, dayLabel, instant, say } from './analytics/format'
import { ANALYTICS_DEFAULTS, useReport, type Definitions, type ViewProps } from './analytics/parts'
import { ReportsView } from './analytics/ReportsView'
import { MenuView, PickupView } from './analytics/MenuPickupViews'
import { CustomersView, FinanceView, NotificationsView, OrdersView, OverviewView, PaymentsView, RefundsView, RestaurantsView, ReviewsView, RoutesView, SupportView } from './analytics/views'
import { usePageTitle } from './shared'

/**
 * Platform Analytics on the backend (Module 38) — what the Analytics entry of the sidebar opens when the platform runs
 * on the backend: the reports of /admin/analytics/* for ONE market (or every market the administrator may see) and ONE
 * period, both kept in the URL. No development numbers.
 *
 * Every figure, comparison, rate and "restricted" mark is the backend's. The figures of a period come from an
 * aggregated read model that is rebuilt every few minutes — the line under the filters says when, and says so loudly
 * when the aggregation is behind. Only the "Right now" strip of the Overview reads the operational records of this
 * moment, and it is labelled as that. Money stays in its own currency; several currencies are listed, never added.
 */
export type AnalyticsTab = 'overview' | 'customers' | 'orders' | 'restaurants' | 'menu' | 'pickup' | 'routes' | 'payments' | 'refunds' | 'finance' | 'support' | 'reviews' | 'notifications' | 'reports'
const TABS: Array<{ id: AnalyticsTab; perm?: AdminPermission }> = [
  { id: 'overview' }, { id: 'customers' }, { id: 'orders' }, { id: 'restaurants' }, { id: 'menu' }, { id: 'pickup' }, { id: 'routes' }, { id: 'payments' }, { id: 'refunds' },
  // Finance and support answer 403 without their own permission: the tab stays and says which access is missing.
  { id: 'finance' }, { id: 'support' }, { id: 'reviews' }, { id: 'notifications' },
  // Asking for an export is a permission of its own.
  { id: 'reports', perm: 'analytics.export' },
]
/** The tabs whose report can be narrowed to one restaurant. */
const RESTAURANT_TABS: AnalyticsTab[] = ['orders', 'restaurants', 'menu', 'pickup', 'reviews']
const DEFAULT_MARKET = 'india'

export function BackendAnalyticsPage({ repository, liveMs, pollMs }: { repository?: ApiAdminAnalyticsRepository; /** How often "Right now" is read again (tests). */ liveMs?: number; /** How often pending exports are read again (tests). */ pollMs?: number }) {
  const a = useAdmin(); const locale = a.locale; usePageTitle('adm.nav.analytics')
  const repo = useMemo(() => repository ?? new ApiAdminAnalyticsRepository(), [repository])
  const [s, set] = useUrlState(ANALYTICS_DEFAULTS)
  const tabs = TABS.filter((x) => !x.perm || a.can(x.perm))
  const tab = tabs.find((x) => x.id === s.tab)?.id ?? 'overview'

  // The market: the one in the URL, else the header's market, else India. Always sent as a slug (or `all`).
  const markets = useMemo(() => a.markets.filter((m) => m.status !== 'DRAFT' && m.status !== 'CLOSED').map((m) => ({ slug: m.slug, name: m.displayName, currency: m.defaultCurrency })), [a.markets])
  const fallbackMarket = a.marketModel?.slug ?? a.markets.find((m) => m.countryCode === 'IN')?.slug ?? DEFAULT_MARKET
  const market = s.market === 'all' ? 'all' : s.market || fallbackMarket
  const marketOptions = [{ slug: 'all', name: say('market.all', undefined, locale) }, ...markets, ...(market !== 'all' && !markets.some((m) => m.slug === market) ? [{ slug: market, name: market }] : [])]

  const preset: PeriodPreset = (PERIOD_PRESETS as readonly string[]).includes(s.preset) ? (s.preset as PeriodPreset) : 'last_30_days'
  const custom = preset === 'custom'
  const periodProblem = custom ? customPeriodProblem(s.from, s.to, MAX_RANGE_DAYS) : null
  const restaurant = RESTAURANT_TABS.includes(tab) ? s.restaurant : ''
  const query = useMemo<AnalyticsQuery | null>(() => (periodProblem ? null : { market, preset, ...(custom ? { from: s.from, to: s.to } : {}), ...(restaurant ? { restaurant } : {}) }), [periodProblem, market, preset, custom, s.from, s.to, restaurant])

  // The definitions of every figure: read once for the page.
  const catalog = useReport(() => repo.catalog(), [repo])
  const definitions = useMemo<Definitions>(() => ({ state: catalog.state === 'ready' ? 'ready' : catalog.state === 'error' ? 'error' : 'loading', byKey: new Map((catalog.data?.metrics ?? []).map((m) => [m.key, m])) }), [catalog.state, catalog.data])

  const [info, setInfo] = useState<{ meta: AnalyticsMeta | null; problem: AnalyticsProblem | null }>({ meta: null, problem: null })
  const publish = useCallback((meta: AnalyticsMeta | null, problem: AnalyticsProblem | null) => setInfo((was) => (was.meta === meta && was.problem === problem ? was : { meta, problem })), [])
  const meta = info.meta
  const marketName = marketOptions.find((m) => m.slug === market)?.name ?? market
  const view: ViewProps = { repo, query, market, marketName, locale, definitions, publish, can: a.can, s, set }

  const choosePreset = (next: string) => {
    // "Custom" starts from the days that are on screen, so the first thing shown is not an empty page.
    if (next === 'custom') set({ preset: 'custom', from: s.from || meta?.period?.from || '', to: s.to || meta?.period?.to || '' })
    else set({ preset: next, from: '', to: '' })
  }
  const periodError = periodProblem ? say(`period.${periodProblem}`, { max: MAX_RANGE_DAYS }, locale) : info.problem?.kind === 'validation' ? info.problem.message : null
  const currencies = [...new Set((meta?.scope?.markets ?? []).map((m) => m.currency).filter(Boolean))]
  const fresh = meta?.freshness

  return (
    <div className="db-page adm-an" data-testid="adm-analytics" data-source="backend" data-tab={tab} data-market={market} data-preset={preset}>
      <div className="db-page__head"><div><h1 className="db-page__title">{say('title', undefined, locale)}</h1><p className="db-page__lead">{say('lead', undefined, locale)}</p></div></div>

      <div className="adm-an-filters" role="group" aria-label={say('filters', undefined, locale)} data-testid="an-filters">
        <label className="adm-an-field"><span>{say('market', undefined, locale)}</span>
          <select className="db-select db-input--sm" value={market} onChange={(e) => set({ market: e.target.value, restaurant: '' })} data-testid="an-market">
            {marketOptions.map((m) => <option key={m.slug} value={m.slug}>{m.name}</option>)}
          </select>
        </label>
        <label className="adm-an-field"><span>{say('period', undefined, locale)}</span>
          <select className="db-select db-input--sm" value={preset} onChange={(e) => choosePreset(e.target.value)} data-testid="an-period">
            {PERIOD_PRESETS.map((x) => <option key={x} value={x}>{say(`preset.${x}`, undefined, locale)}</option>)}
          </select>
        </label>
        {custom && <>
          <label className="adm-an-field"><span>{say('period.from', undefined, locale)}</span><input type="date" className="db-input db-input--sm" value={s.from} max={s.to || undefined} onChange={(e) => set({ from: e.target.value })} aria-invalid={periodError ? true : undefined} data-testid="an-period-from" /></label>
          <label className="adm-an-field"><span>{say('period.to', undefined, locale)}</span><input type="date" className="db-input db-input--sm" value={s.to} min={s.from || undefined} onChange={(e) => set({ to: e.target.value })} aria-invalid={periodError ? true : undefined} data-testid="an-period-to" /></label>
        </>}
      </div>
      {periodError && <p className="db-field__error adm-an-period-error" role="alert" data-testid="an-period-error">{periodError}</p>}

      {/* What the figures on screen cover, and how fresh they are — the backend's own words and times. */}
      {meta && (
        <div className="adm-an-meta" data-testid="an-meta" data-mode={fresh?.mode} data-stale={fresh?.stale ? 'true' : 'false'}>
          {meta.period && <span data-testid="an-period-range" data-from={meta.period.from} data-to={meta.period.to}><Icon name="hours" size={14} /> {say('range', { from: dayLabel(meta.period.from, locale), to: dayLabel(meta.period.to, locale) }, locale)} · {say('zone', { zone: meta.timezone }, locale)}</span>}
          <span data-testid="an-currency">{currencies.length > 1 ? say('currencies', { list: currencies.join(', ') }, locale) : say('currency', { currency: currencies[0] ?? meta.currency ?? '—' }, locale)}</span>
          {fresh && <span data-testid="an-freshness" data-aggregated-at={fresh.aggregated_at ?? ''}>{fresh.label}{fresh.mode === 'AGGREGATED'
            ? ` · ${fresh.aggregated_at ? say('fresh.asOf', { time: instant(fresh.aggregated_at, meta.timezone, locale) }, locale) : say('fresh.never', undefined, locale)}`
            : ` · ${say('fresh.readAt', { time: instant(fresh.generated_at, meta.timezone, locale) }, locale)}`}</span>}
          {meta.scope?.restaurant && <span className="adm-an-scope" data-testid="an-restaurant-scope">{say('restaurantScope', { name: meta.scope.restaurant.name }, locale)} <button type="button" className="db-btn db-btn--ghost db-btn--sm" onClick={() => set({ restaurant: '' })} data-testid="an-restaurant-clear">{say('restaurantClear', undefined, locale)}</button></span>}
        </div>
      )}
      {fresh?.stale && <p className="adm-an-stale" role="status" data-testid="an-stale"><Icon name="warning" size={16} /> {say('fresh.stale', undefined, locale)}</p>}

      <Tabs tabs={tabs.map((x) => ({ id: x.id, label: say(`tab.${x.id}`, undefined, locale) }))} value={tab} onChange={(id) => set({ tab: id })} label={say('tabs', undefined, locale)} testId="an-tab" />

      <div className="adm-an-view" data-testid={`an-view-${tab}`}>
        {tab === 'overview' && <OverviewView {...view} liveMs={liveMs} />}
        {tab === 'customers' && <CustomersView {...view} />}
        {tab === 'orders' && <OrdersView {...view} />}
        {tab === 'restaurants' && <RestaurantsView {...view} />}
        {tab === 'menu' && <MenuView {...view} />}
        {tab === 'pickup' && <PickupView {...view} />}
        {tab === 'routes' && <RoutesView {...view} />}
        {tab === 'payments' && <PaymentsView {...view} />}
        {tab === 'refunds' && <RefundsView {...view} />}
        {tab === 'finance' && <FinanceView {...view} />}
        {tab === 'support' && <SupportView {...view} />}
        {tab === 'reviews' && <ReviewsView {...view} />}
        {tab === 'notifications' && <NotificationsView {...view} />}
        {tab === 'reports' && <ReportsView {...view} pollMs={pollMs} />}
      </div>
      <DevNote>{say('note', undefined, locale)}</DevNote>
    </div>
  )
}

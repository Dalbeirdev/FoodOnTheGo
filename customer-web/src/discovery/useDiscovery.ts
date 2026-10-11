import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { restaurantRepository } from '../repositories'
import type { DiscoveryQuery, DiscoveryScope, FilterDefinition, FilterValue, JourneyLike, ResultPage, RouteRestaurantResult, ScopeRing, SearchMeta, SortKey } from '../repositories/types'
import { JourneyError, type JourneyErrorCode } from '../journey/repositories'
import { marketFor } from '../i18n/markets'

export type DiscoveryStatus = 'idle' | 'loading' | 'updating' | 'loadingMore' | 'ready' | 'error'

/** Development (browser) journeys rank by sample ratings too; a backend journey offers only what the API can order by. */
const MOCK_SORTS: SortKey[] = ['recommended', 'lowestDetour', 'nearestToRoute', 'highestRated', 'fastestPickup']
const API_SORTS: Record<string, SortKey> = { recommended: 'recommended', lowest_detour: 'lowestDetour', route_order: 'routeOrder', nearest_to_route: 'nearestToRoute', fastest_pickup: 'fastestPickup', highest_rated: 'highestRated' }

/**
 * Discovery state for /restaurants: debounced search, data-driven filters, sort, corridor width,
 * cursor pagination, request cancellation and retry. Works with or without a journey.
 *
 * With a backend journey (Module 26) every list is the backend's answer; changes of the search are sent a moment
 * after the traveller stops adjusting (a slider would otherwise ask the backend for every step).
 */
export function useDiscovery(journey: JourneyLike | null, journeyReady: boolean, scope: DiscoveryScope | null = null) {
  const scopeKey = scope ? `${scope.countryCode}|${scope.adminArea ?? ''}|${scope.lat ?? ''}|${scope.lng ?? ''}` : ''
  const backend = !!journey?.backend
  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')
  const [filters, setFilters] = useState<Record<string, FilterValue>>({})
  const [sortChoice, setSort] = useState<SortKey>('recommended')
  // Corridor override is keyed by journey id so each market starts from its own default without an effect.
  const [corridorOverride, setCorridorOverride] = useState<{ journeyId: string | null; value: number } | null>(null)
  const corridorM = corridorOverride && corridorOverride.journeyId === (journey?.id ?? null) ? corridorOverride.value : null
  // Ring override keyed by scope so a new location starts from the default rings again.
  const [ringOverride, setRingOverride] = useState<{ key: string; value: ScopeRing } | null>(null)
  const maxRing: ScopeRing = ringOverride && ringOverride.key === scopeKey ? ringOverride.value : 1
  const [ringApplied, setRingApplied] = useState<ScopeRing | undefined>(undefined)
  const [nextRing, setNextRing] = useState<ScopeRing | null>(null)
  const [ringCounts, setRingCounts] = useState<Record<ScopeRing, number> | undefined>(undefined)
  const [status, setStatus] = useState<DiscoveryStatus>('idle')
  const [items, setItems] = useState<RouteRestaurantResult[]>([])
  const [total, setTotal] = useState(0)
  const [nextCursor, setNextCursor] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [errorCode, setErrorCode] = useState<JourneyErrorCode | null>(null)
  const [availability, setAvailability] = useState<ResultPage['availability']>(undefined)
  const [emptyReason, setEmptyReason] = useState<ResultPage['emptyReason']>(null)
  const [searchMeta, setSearchMeta] = useState<SearchMeta | undefined>(undefined)
  const seq = useRef(0)
  const first = useRef(true)

  useEffect(() => { const t = setTimeout(() => setDebounced(search), 300); return () => clearTimeout(t) }, [search])

  const definitions: FilterDefinition[] = useMemo(() => restaurantRepository.getFilterDefinitions(journey), [journey])
  const sorts: SortKey[] = useMemo(() => (backend ? (journey?.search?.sorts ?? Object.keys(API_SORTS)).map((s) => API_SORTS[s]).filter((s): s is SortKey => !!s) : MOCK_SORTS.filter((s) => journey || (s !== 'lowestDetour' && s !== 'nearestToRoute'))), [backend, journey])
  const sort: SortKey = sorts.includes(sortChoice) ? sortChoice : 'recommended'
  /** The corridor the traveller may choose from: the backend's bounds for its journeys, the development range otherwise. */
  const corridorBounds = useMemo(() => (journey?.search ? { min: journey.search.corridorMinM, max: journey.search.corridorMaxM, step: 500 } : { min: 1000, max: 50_000, step: 1000 }), [journey])
  const effectiveCorridor = corridorM ?? (journey ? journey.search?.corridorM ?? marketFor(journey.origin.countryCode).corridorM : null)
  const setCorridorM = (v: number | null) => setCorridorOverride(v === null ? null : { journeyId: journey?.id ?? null, value: Math.max(corridorBounds.min, Math.min(corridorBounds.max, v)) })

  const run = useCallback(async (cursor: string | null, mode: 'load' | 'more') => {
    if (!journeyReady) return
    const my = ++seq.current
    setStatus(mode === 'more' ? 'loadingMore' : first.current ? 'loading' : 'updating')
    setError(null); setErrorCode(null)
    const query: DiscoveryQuery = { search: debounced, filters, sort, cursor, corridorM: effectiveCorridor ?? undefined, scope: journey ? null : scope, maxRing: journey ? undefined : maxRing }
    try {
      const page: ResultPage = journey ? await restaurantRepository.getRestaurantsForJourney(journey, query) : await restaurantRepository.getRestaurants(query)
      if (my !== seq.current) return // cancelled by a newer request
      setItems((prev) => (mode === 'more' ? [...prev, ...page.items] : page.items))
      setTotal(page.total); setAvailability(page.availability); setNextCursor(page.nextCursor); setRingApplied(page.ringApplied); setNextRing(page.nextRing ?? null); setRingCounts(page.ringCounts)
      setEmptyReason(page.emptyReason ?? null); setSearchMeta(page.searchMeta); setStatus('ready'); first.current = false
    } catch (e) {
      if (my !== seq.current) return
      setError(e instanceof Error ? e.message : 'Something went wrong.'); setErrorCode(e instanceof JourneyError ? e.code : 'unexpected'); setStatus('error')
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [journey, journeyReady, debounced, filters, sort, effectiveCorridor, scopeKey, maxRing])

  // Development data answers at once; a backend journey waits until the traveller stops adjusting a slider or filter.
  const settled = useRef(false)
  useEffect(() => {
    if (!backend || !settled.current) { settled.current = true; void run(null, 'load'); return }
    const t = setTimeout(() => { void run(null, 'load') }, 350)
    return () => clearTimeout(t)
  }, [run, backend])

  const setFilter = (id: string, value: FilterValue | null) => setFilters((f) => { const n = { ...f }; if (value === null || value === false || (Array.isArray(value) && value.length === 0)) delete n[id]; else n[id] = value; return n })
  const toggleOption = (id: string, option: string) => setFilters((f) => { const cur = Array.isArray(f[id]) ? (f[id] as string[]) : []; const next = cur.includes(option) ? cur.filter((o) => o !== option) : [...cur, option]; const n = { ...f }; if (next.length) n[id] = next; else delete n[id]; return n })
  const clearFilters = () => { setFilters({}); setSearch('') }
  const activeFilterCount = Object.keys(filters).length + (debounced ? 1 : 0)
  const widenCorridor = () => setCorridorM(Math.min((corridorM ?? effectiveCorridor ?? 5000) * 2, corridorBounds.max))
  const showMoreAreas = () => { if (nextRing !== null) setRingOverride({ key: scopeKey, value: nextRing }) }

  return { search, setSearch, filters, setFilter, toggleOption, clearFilters, activeFilterCount, sort, setSort, sorts, definitions, corridorM: effectiveCorridor, corridorBounds, setCorridorM, widenCorridor, status, items, total, nextCursor, error, errorCode, emptyReason, searchMeta, backend, loadMore: () => run(nextCursor, 'more'), retry: () => run(null, 'load'), scope, maxRing, ringApplied, nextRing, ringCounts, showMoreAreas, availability }
}

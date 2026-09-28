import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { restaurantRepository } from '../repositories'
import type { DiscoveryQuery, FilterDefinition, FilterValue, JourneyLike, ResultPage, RouteRestaurantResult, SortKey } from '../repositories/types'
import { marketFor } from '../i18n/markets'

export type DiscoveryStatus = 'idle' | 'loading' | 'updating' | 'loadingMore' | 'ready' | 'error'

/**
 * Discovery state for /restaurants: debounced search, data-driven filters, sort, corridor width,
 * cursor pagination, request cancellation and retry. Works with or without a journey.
 */
export function useDiscovery(journey: JourneyLike | null, journeyReady: boolean) {
  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')
  const [filters, setFilters] = useState<Record<string, FilterValue>>({})
  const [sort, setSort] = useState<SortKey>('recommended')
  // Corridor override is keyed by journey id so each market starts from its own default without an effect.
  const [corridorOverride, setCorridorOverride] = useState<{ journeyId: string | null; value: number } | null>(null)
  const corridorM = corridorOverride && corridorOverride.journeyId === (journey?.id ?? null) ? corridorOverride.value : null
  const setCorridorM = (v: number | null) => setCorridorOverride(v === null ? null : { journeyId: journey?.id ?? null, value: v })
  const [status, setStatus] = useState<DiscoveryStatus>('idle')
  const [items, setItems] = useState<RouteRestaurantResult[]>([])
  const [total, setTotal] = useState(0)
  const [nextCursor, setNextCursor] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const seq = useRef(0)
  const first = useRef(true)

  useEffect(() => { const t = setTimeout(() => setDebounced(search), 300); return () => clearTimeout(t) }, [search])

  const definitions: FilterDefinition[] = useMemo(() => restaurantRepository.getFilterDefinitions(journey), [journey])
  const effectiveCorridor = corridorM ?? (journey ? marketFor(journey.origin.countryCode).corridorM : null)

  const run = useCallback(async (cursor: string | null, mode: 'load' | 'more') => {
    if (!journeyReady) return
    const my = ++seq.current
    setStatus(mode === 'more' ? 'loadingMore' : first.current ? 'loading' : 'updating')
    setError(null)
    const query: DiscoveryQuery = { search: debounced, filters, sort, cursor, corridorM: effectiveCorridor ?? undefined }
    try {
      const page: ResultPage = journey ? await restaurantRepository.getRestaurantsForJourney(journey, query) : await restaurantRepository.getRestaurants(query)
      if (my !== seq.current) return // cancelled by a newer request
      setItems((prev) => (mode === 'more' ? [...prev, ...page.items] : page.items))
      setTotal(page.total); setNextCursor(page.nextCursor); setStatus('ready'); first.current = false
    } catch (e) {
      if (my !== seq.current) return
      setError(e instanceof Error ? e.message : 'Something went wrong.'); setStatus('error')
    }
  }, [journey, journeyReady, debounced, filters, sort, effectiveCorridor])

  useEffect(() => { void run(null, 'load') }, [run])

  const setFilter = (id: string, value: FilterValue | null) => setFilters((f) => { const n = { ...f }; if (value === null || value === false || (Array.isArray(value) && value.length === 0)) delete n[id]; else n[id] = value; return n })
  const toggleOption = (id: string, option: string) => setFilters((f) => { const cur = Array.isArray(f[id]) ? (f[id] as string[]) : []; const next = cur.includes(option) ? cur.filter((o) => o !== option) : [...cur, option]; const n = { ...f }; if (next.length) n[id] = next; else delete n[id]; return n })
  const clearFilters = () => { setFilters({}); setSearch('') }
  const activeFilterCount = Object.keys(filters).length + (debounced ? 1 : 0)
  const widenCorridor = () => setCorridorM(Math.min((corridorM ?? effectiveCorridor ?? 5000) * 2, 50_000))

  return { search, setSearch, filters, setFilter, toggleOption, clearFilters, activeFilterCount, sort, setSort, definitions, corridorM: effectiveCorridor, setCorridorM, widenCorridor, status, items, total, nextCursor, error, loadMore: () => run(nextCursor, 'more'), retry: () => run(null, 'load') }
}

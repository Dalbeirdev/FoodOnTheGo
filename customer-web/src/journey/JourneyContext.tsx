import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { MockJourneyRepository, MockLocationRepository, MockRouteRepository } from './mock/mockRepositories'
import { JourneyError, validateJourney, type Journey, type JourneyRepository, type Location, type LocationRepository, type RouteRepository, type ValidationErrors } from './repositories'

export type { Journey, Location, RouteSummary, ValidationErrors } from './repositories'

/**
 * Centralised journey state — reused later by restaurant discovery, cart, checkout, tracking and ETA.
 *
 * no-journey → editing → validating → ready → route-loading → route-available
 *                                   ↘ error (validation / lookup / route)  → editing
 */
export type JourneyStatus = 'no-journey' | 'editing' | 'validating' | 'ready' | 'route-loading' | 'route-available' | 'error'

export type JourneyRepositories = { locations: LocationRepository; journeys: JourneyRepository; routes: RouteRepository }
export const defaultJourneyRepositories: JourneyRepositories = { locations: new MockLocationRepository(), journeys: new MockJourneyRepository(), routes: new MockRouteRepository() }

type JourneyApi = {
  status: JourneyStatus
  origin: Location | null
  destination: Location | null
  departureAt: string | null
  errors: ValidationErrors
  error: string | null
  journey: Journey | null
  recent: Journey[]
  recentStatus: 'idle' | 'loading' | 'ready' | 'error'
  setOrigin: (l: Location | null) => void
  setDestination: (l: Location | null) => void
  setDepartureAt: (iso: string | null) => void
  swap: () => void
  /** Validate + create the journey + load the route. Resolves to the journey or null when invalid. */
  plan: (originText?: string, destinationText?: string) => Promise<Journey | null>
  /** Retry the route lookup for the current journey. */
  retryRoute: () => Promise<void>
  /** Go back to editing (keeps the selected locations). */
  edit: () => void
  reset: () => void
  /** Load a journey by id (e.g. from ?journey=) into state; returns null when unknown. */
  load: (id: string) => Promise<Journey | null>
  useAgain: (j: Journey) => void
  removeRecent: (id: string) => Promise<void>
  searchLocations: (q: string) => Promise<Location[]>
  recentLocations: () => Promise<Location[]>
}

const JourneyContext = createContext<JourneyApi | null>(null)
const CURRENT_KEY = 'fotg.journey.current'

export function JourneyProvider({ children, repositories = defaultJourneyRepositories }: { children: ReactNode; repositories?: JourneyRepositories }) {
  const repos = repositories
  const [status, setStatus] = useState<JourneyStatus>('no-journey')
  const [origin, setOriginState] = useState<Location | null>(null)
  const [destination, setDestinationState] = useState<Location | null>(null)
  const [departureAt, setDepartureAtState] = useState<string | null>(null)
  const [errors, setErrors] = useState<ValidationErrors>({})
  const [error, setError] = useState<string | null>(null)
  const [journey, setJourney] = useState<Journey | null>(null)
  const [recent, setRecent] = useState<Journey[]>([])
  const [recentStatus, setRecentStatus] = useState<JourneyApi['recentStatus']>('idle')
  const runId = useRef(0)

  const loadRecent = useCallback(async () => {
    setRecentStatus('loading')
    try { setRecent(await repos.journeys.recent()); setRecentStatus('ready') } catch { setRecentStatus('error') }
  }, [repos])
  useEffect(() => { const t = setTimeout(() => { void loadRecent() }, 0); return () => clearTimeout(t) }, [loadRecent])

  // Restore the current journey for this tab so Restaurants / Cart can reuse it after a refresh.
  useEffect(() => {
    try {
      const raw = sessionStorage.getItem(CURRENT_KEY)
      if (!raw) return
      const j = JSON.parse(raw) as Journey
      setJourney(j); setOriginState(j.origin); setDestinationState(j.destination); setDepartureAtState(j.departureAt)
      setStatus(j.route ? 'route-available' : 'ready')
    } catch { /* ignore corrupt storage */ }
  }, [])
  const persist = (j: Journey | null) => { try { j ? sessionStorage.setItem(CURRENT_KEY, JSON.stringify(j)) : sessionStorage.removeItem(CURRENT_KEY) } catch { /* ignore */ } }

  const setOrigin = (l: Location | null) => { setOriginState(l); setErrors((e) => ({ ...e, origin: undefined, form: undefined })); setStatus('editing'); setError(null) }
  const setDestination = (l: Location | null) => { setDestinationState(l); setErrors((e) => ({ ...e, destination: undefined, form: undefined })); setStatus('editing'); setError(null) }
  const setDepartureAt = (iso: string | null) => { setDepartureAtState(iso); if (status !== 'no-journey') setStatus('editing') }
  const swap = () => { setOriginState(destination); setDestinationState(origin); setErrors({}); setStatus('editing'); setError(null) }

  const loadRoute = async (j: Journey, id: number) => {
    setStatus('route-loading'); setError(null)
    try {
      const route = await repos.routes.getRoute(j.origin, j.destination)
      if (runId.current !== id) return
      const next: Journey = { ...j, route, status: 'route-available' }
      await repos.journeys.update(next)
      setJourney(next); persist(next); setStatus('route-available'); void loadRecent()
    } catch (e) {
      if (runId.current !== id) return
      const failed: Journey = { ...j, status: 'error' }
      setJourney(failed); persist(failed)
      setError(e instanceof JourneyError ? e.message : 'Route preparation failed. Please try again.'); setStatus('error')
    }
  }

  const plan: JourneyApi['plan'] = async (originText = '', destinationText = '') => {
    const id = ++runId.current
    setStatus('validating'); setError(null)
    const err = validateJourney(origin, destination, originText, destinationText)
    setErrors(err)
    if (Object.keys(err).length || !origin || !destination) { setStatus('editing'); return null }
    try {
      const j = await repos.journeys.create({ origin, destination, departureAt })
      if (runId.current !== id) return null
      await Promise.all([repos.locations.remember(origin), repos.locations.remember(destination)])
      setJourney(j); persist(j); setStatus('ready')
      await loadRoute(j, id)
      return j
    } catch (e) {
      if (runId.current !== id) return null
      setError(e instanceof Error ? e.message : 'Could not prepare the journey. Please try again.'); setStatus('error')
      return null
    }
  }
  const retryRoute = async () => { if (journey) await loadRoute(journey, ++runId.current) }
  const edit = () => { runId.current++; setStatus('editing'); setError(null) }
  const reset = () => { runId.current++; setOriginState(null); setDestinationState(null); setDepartureAtState(null); setErrors({}); setError(null); setJourney(null); persist(null); setStatus('no-journey') }
  const load = useCallback(async (id: string) => {
    if (journey?.id === id) return journey
    const j = await repos.journeys.get(id)
    if (!j) return null
    setJourney(j); setOriginState(j.origin); setDestinationState(j.destination); setDepartureAtState(j.departureAt); persist(j)
    setStatus(j.route ? 'route-available' : 'ready')
    return j
  }, [journey, repos])
  const useAgain = (j: Journey) => { runId.current++; setOriginState(j.origin); setDestinationState(j.destination); setDepartureAtState(null); setErrors({}); setError(null); setJourney(null); setStatus('editing') }
  const removeRecent = async (id: string) => { await repos.journeys.remove(id); setRecent((r) => r.filter((j) => j.id !== id)) }
  const searchLocations = useCallback((q: string) => repos.locations.search(q), [repos])
  const recentLocations = useCallback(() => repos.locations.recent(), [repos])

  const value = useMemo<JourneyApi>(() => ({ status, origin, destination, departureAt, errors, error, journey, recent, recentStatus, setOrigin, setDestination, setDepartureAt, swap, plan, retryRoute, edit, reset, load, useAgain, removeRecent, searchLocations, recentLocations }),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [status, origin, destination, departureAt, errors, error, journey, recent, recentStatus, load, searchLocations, recentLocations])
  return <JourneyContext.Provider value={value}>{children}</JourneyContext.Provider>
}

export function useJourney() {
  const ctx = useContext(JourneyContext)
  if (!ctx) throw new Error('useJourney must be used within JourneyProvider')
  return ctx
}

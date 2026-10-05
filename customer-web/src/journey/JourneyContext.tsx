import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { journeyRepositories, type JourneyRepositories } from './journeyRepositories'
import { JourneyError, validateJourney, type Journey, type JourneyErrorCode, type Location, type ValidationErrors } from './repositories'

export type { Journey, Location, RouteSummary, ValidationErrors } from './repositories'
export type { JourneyRepositories } from './journeyRepositories'

/**
 * Centralised journey state — reused by restaurant discovery, cart, checkout, tracking and ETA.
 *
 * no-journey → editing → validating → ready → route-loading → route-available
 *                                   ↘ error (validation / lookup / route)  → editing
 *
 * With the backend (Module 26) a planned journey arrives WITH its route (the routing provider's), so the
 * route-loading step is skipped; the journey lives on the server, expires there, and only its owner can read it.
 */
export type JourneyStatus = 'no-journey' | 'editing' | 'validating' | 'ready' | 'route-loading' | 'route-available' | 'error'

/** Kept for callers written before the mode switch existed. */
export const defaultJourneyRepositories: JourneyRepositories = journeyRepositories

type JourneyApi = {
  status: JourneyStatus
  origin: Location | null
  destination: Location | null
  departureAt: string | null
  errors: ValidationErrors
  error: string | null
  /** Why the last plan / route lookup failed (machine readable), so a screen can offer the right next step. */
  errorCode: JourneyErrorCode | null
  journey: Journey | null
  recent: Journey[]
  recentStatus: 'idle' | 'loading' | 'ready' | 'error'
  /** 'api' = places, routes and discovery are the backend's; 'mock' = the browser's development providers. */
  mode: JourneyRepositories['mode']
  setOrigin: (l: Location | null) => void
  setDestination: (l: Location | null) => void
  setDepartureAt: (iso: string | null) => void
  swap: () => void
  /** Validate + create the journey + load the route. Resolves to the journey or null when invalid. */
  plan: (originText?: string, destinationText?: string) => Promise<Journey | null>
  /** Retry the route lookup for the current journey (with the backend: plan it again). */
  retryRoute: () => Promise<void>
  /** Go back to editing (keeps the selected locations). */
  edit: () => void
  reset: () => void
  /** Load a journey by id (e.g. from ?journey=) into state; null when unknown. Rejects with a JourneyError when it expired or cannot be loaded. */
  load: (id: string) => Promise<Journey | null>
  useAgain: (j: Journey) => void
  removeRecent: (id: string) => Promise<void>
  searchLocations: (q: string) => Promise<Location[]>
  /** A chosen suggestion with its coordinates (backend: resolved on the server; mock: unchanged). */
  resolveLocation: (l: Location) => Promise<Location>
  recentLocations: () => Promise<Location[]>
}

const JourneyContext = createContext<JourneyApi | null>(null)
const CURRENT_KEY = 'fotg.journey.current'

export function JourneyProvider({ children, repositories = journeyRepositories }: { children: ReactNode; repositories?: JourneyRepositories }) {
  const repos = repositories
  const [status, setStatus] = useState<JourneyStatus>('no-journey')
  const [origin, setOriginState] = useState<Location | null>(null)
  const [destination, setDestinationState] = useState<Location | null>(null)
  const [departureAt, setDepartureAtState] = useState<string | null>(null)
  const [errors, setErrors] = useState<ValidationErrors>({})
  const [error, setError] = useState<string | null>(null)
  const [errorCode, setErrorCode] = useState<JourneyErrorCode | null>(null)
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
      // A journey of the other mode (the mode was switched) cannot be continued: start clean.
      if (!!j.backend !== (repos.mode === 'api')) { sessionStorage.removeItem(CURRENT_KEY); return }
      setJourney(j); setOriginState(j.origin); setDestinationState(j.destination); setDepartureAtState(j.departureAt)
      setStatus(j.route ? 'route-available' : 'ready')
    } catch { /* ignore corrupt storage */ }
  }, [repos])
  const persist = (j: Journey | null) => { try { j ? sessionStorage.setItem(CURRENT_KEY, JSON.stringify(j)) : sessionStorage.removeItem(CURRENT_KEY) } catch { /* ignore */ } }
  const clearError = () => { setError(null); setErrorCode(null) }
  const fail = (e: unknown, fallback: string) => { setError(e instanceof Error && e.message ? e.message : fallback); setErrorCode(e instanceof JourneyError ? e.code : 'unexpected'); setStatus('error') }

  const setOrigin = (l: Location | null) => { setOriginState(l); setErrors((e) => ({ ...e, origin: undefined, form: undefined })); setStatus('editing'); clearError() }
  const setDestination = (l: Location | null) => { setDestinationState(l); setErrors((e) => ({ ...e, destination: undefined, form: undefined })); setStatus('editing'); clearError() }
  const setDepartureAt = (iso: string | null) => { setDepartureAtState(iso); if (status !== 'no-journey') setStatus('editing') }
  const swap = () => { setOriginState(destination); setDestinationState(origin); setErrors({}); setStatus('editing'); clearError() }

  const loadRoute = async (j: Journey, id: number) => {
    setStatus('route-loading'); clearError()
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
      fail(e instanceof JourneyError ? e : null, 'Route preparation failed. Please try again.')
    }
  }

  const plan: JourneyApi['plan'] = async (originText = '', destinationText = '') => {
    const id = ++runId.current
    setStatus('validating'); clearError()
    const err = validateJourney(origin, destination, originText, destinationText)
    setErrors(err)
    if (Object.keys(err).length || !origin || !destination) { setStatus('editing'); return null }
    try {
      const j = await repos.journeys.create({ origin, destination, departureAt })
      if (runId.current !== id) return null
      await Promise.all([repos.locations.remember(origin), repos.locations.remember(destination)])
      // The backend plans the journey and its route in one step; the development providers look the route up afterwards.
      if (j.route) { setJourney(j); persist(j); setStatus('route-available'); void loadRecent(); return j }
      setJourney(j); persist(j); setStatus('ready')
      await loadRoute(j, id)
      return j
    } catch (e) {
      if (runId.current !== id) return null
      setJourney(null); persist(null)
      fail(e, 'Could not prepare the journey. Please try again.')
      return null
    }
  }
  const retryRoute = async () => { if (repos.mode === 'api') { await plan(); return } if (journey) await loadRoute(journey, ++runId.current) }
  const edit = () => { runId.current++; setStatus('editing'); clearError() }
  const reset = () => { runId.current++; setOriginState(null); setDestinationState(null); setDepartureAtState(null); setErrors({}); clearError(); setJourney(null); persist(null); setStatus('no-journey') }
  const load = useCallback(async (id: string) => {
    if (journey?.id === id) return journey
    const j = await repos.journeys.get(id)
    if (!j) return null
    setJourney(j); setOriginState(j.origin); setDestinationState(j.destination); setDepartureAtState(j.departureAt); persist(j)
    setStatus(j.route ? 'route-available' : 'ready')
    return j
  }, [journey, repos])
  const useAgain = (j: Journey) => { runId.current++; setOriginState(j.origin); setDestinationState(j.destination); setDepartureAtState(null); setErrors({}); clearError(); setJourney(null); setStatus('editing') }
  const removeRecent = async (id: string) => { await repos.journeys.remove(id); setRecent((r) => r.filter((j) => j.id !== id)) }
  const searchLocations = useCallback((q: string) => repos.locations.search(q), [repos])
  const resolveLocation = useCallback((l: Location) => (repos.locations.resolve ? repos.locations.resolve(l) : Promise.resolve(l)), [repos])
  const recentLocations = useCallback(() => repos.locations.recent(), [repos])

  const value = useMemo<JourneyApi>(() => ({ status, origin, destination, departureAt, errors, error, errorCode, journey, recent, recentStatus, mode: repos.mode, setOrigin, setDestination, setDepartureAt, swap, plan, retryRoute, edit, reset, load, useAgain, removeRecent, searchLocations, resolveLocation, recentLocations }),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [status, origin, destination, departureAt, errors, error, errorCode, journey, recent, recentStatus, repos, load, searchLocations, resolveLocation, recentLocations])
  return <JourneyContext.Provider value={value}>{children}</JourneyContext.Provider>
}

export function useJourney() {
  const ctx = useContext(JourneyContext)
  if (!ctx) throw new Error('useJourney must be used within JourneyProvider')
  return ctx
}

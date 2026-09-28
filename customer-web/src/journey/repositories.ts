/**
 * Journey planning abstractions (Module 05).
 *
 * Pages never touch storage or providers directly. During development the Mock* implementations
 * in ./mock supply controlled data; ApiLocationRepository / GooglePlacesLocationRepository,
 * ApiJourneyRepository and ApiRouteRepository / GoogleRouteRepository replace them later without
 * rebuilding the screens.
 *
 * PRODUCT BOUNDARY: a journey is TRAVEL + restaurant discovery + pre-order + PICKUP at the restaurant.
 * There is no delivery destination anywhere in this model.
 */

export type LocationKind = 'city' | 'station' | 'airport' | 'landmark' | 'saved' | 'recent' | 'current'

export type Location = {
  id: string
  /** Primary label, e.g. "Chandigarh". */
  name: string
  /** Secondary label, e.g. "Chandigarh, India" or "Railway station". */
  sub: string
  kind: LocationKind
  lat: number | null
  lng: number | null
  /** Where the data came from — mock during development, later "google-places" / "api". */
  source: 'mock' | 'saved-address' | 'dev-location'
}

export type JourneyStatus = 'draft' | 'ready' | 'route-available' | 'error'

export type RouteSummary = {
  provider: 'mock'
  distanceKm: number
  durationMin: number
  /** Ordered [lat, lng] pairs for a schematic preview. Real geometry (encoded polyline) arrives with the Routes API. */
  geometry: [number, number][]
  /** Named places the corridor passes — text alternative to the map. */
  waypoints: string[]
  /** Development-only marker so the UI can label estimates. */
  isEstimate: true
}

export type Journey = {
  id: string
  origin: Location
  destination: Location
  originLat: number | null
  originLng: number | null
  destinationLat: number | null
  destinationLng: number | null
  /** ISO date-time or null when the traveller did not set one. Optional by spec. */
  departureAt: string | null
  createdAt: string
  status: JourneyStatus
  route: RouteSummary | null
  /** Reference for future stored geometry (PostGIS) — null until the backend exists. */
  routeGeometryRef: string | null
}

export type JourneyInput = { origin: Location; destination: Location; departureAt: string | null }

export interface LocationRepository {
  /** Free-text search. Resolves to [] when nothing matches; rejects on lookup failure. */
  search(query: string): Promise<Location[]>
  /** Recently used locations for this device / user. */
  recent(): Promise<Location[]>
  remember(location: Location): Promise<void>
}

export interface JourneyRepository {
  create(input: JourneyInput): Promise<Journey>
  get(id: string): Promise<Journey | null>
  update(journey: Journey): Promise<Journey>
  /** Most recent first. */
  recent(): Promise<Journey[]>
  remove(id: string): Promise<void>
}

export interface RouteRepository {
  getRoute(origin: Location, destination: Location): Promise<RouteSummary>
}

export class JourneyError extends Error {
  code: 'lookup-failed' | 'no-route' | 'network' | 'not-found' | 'unexpected'
  constructor(code: JourneyError['code'], message: string) {
    super(message)
    this.name = 'JourneyError'
    this.code = code
  }
}

export type ValidationErrors = { origin?: string; destination?: string; form?: string }

/** Pure validation shared by the page, the state and the tests. */
export function validateJourney(origin: Location | null, destination: Location | null, originText = '', destinationText = ''): ValidationErrors {
  const err: ValidationErrors = {}
  if (!origin) err.origin = originText.trim() ? 'Choose your starting point from the suggestions' : 'Enter your starting point'
  if (!destination) err.destination = destinationText.trim() ? 'Choose your destination from the suggestions' : 'Enter your destination'
  if (origin && destination) {
    const same = origin.id === destination.id || (origin.lat !== null && origin.lat === destination.lat && origin.lng === destination.lng) || origin.name.trim().toLowerCase() === destination.name.trim().toLowerCase()
    if (same) err.destination = 'Destination must be different from the starting point'
  }
  if ((origin && origin.lat === null) || (destination && destination.lat === null)) err.form = 'This location has no coordinates yet — pick another suggestion'
  return err
}

/**
 * DEVELOPMENT-ONLY mock journey providers.
 *
 * Nothing here calls Google Places, Google Routes or Google Maps. Suggestions come from a fixed
 * list of Indian places; distances are straight-line × a road factor; geometry is a schematic
 * polyline through known corridor towns. Every figure is labelled as a development estimate.
 *
 * Failure switch (sessionStorage "fotg.mock.fail", comma list): "location" → search rejects,
 * "route" → route lookup rejects, "network" → network-style failure. Special query "nowhere"
 * returns no results; origin/destination pair including "Port Blair" has no road route.
 */
import { JourneyError, type Journey, type JourneyInput, type JourneyRepository, type Location, type LocationRepository, type RouteRepository, type RouteSummary } from '../repositories'

type Place = { id: string; name: string; sub: string; kind: Location['kind']; lat: number; lng: number; aliases?: string[] }

export const PLACES: Place[] = [
  { id: 'chandigarh', name: 'Chandigarh', sub: 'Chandigarh, India', kind: 'city', lat: 30.7333, lng: 76.7794 },
  { id: 'chandigarh-rly', name: 'Chandigarh Railway Station', sub: 'Daria, Chandigarh', kind: 'station', lat: 30.7046, lng: 76.8213 },
  { id: 'chandigarh-apt', name: 'Chandigarh Airport', sub: 'Shaheed Bhagat Singh International Airport', kind: 'airport', lat: 30.6735, lng: 76.7885 },
  { id: 'jammu', name: 'Jammu', sub: 'Jammu and Kashmir, India', kind: 'city', lat: 32.7266, lng: 74.857 },
  { id: 'jammu-tawi', name: 'Jammu Tawi Railway Station', sub: 'Jammu, Jammu and Kashmir', kind: 'station', lat: 32.7099, lng: 74.8631 },
  { id: 'delhi', name: 'Delhi', sub: 'National Capital Territory, India', kind: 'city', lat: 28.6139, lng: 77.209, aliases: ['new delhi'] },
  { id: 'cp-delhi', name: 'Connaught Place', sub: 'New Delhi, Delhi', kind: 'landmark', lat: 28.6315, lng: 77.2167 },
  { id: 'noida-62', name: 'Sector 62, Noida', sub: 'Noida, Uttar Pradesh', kind: 'landmark', lat: 28.628, lng: 77.3649 },
  { id: 'gurugram', name: 'Gurugram', sub: 'Haryana, India', kind: 'city', lat: 28.4595, lng: 77.0266, aliases: ['gurgaon'] },
  { id: 'jaipur', name: 'Jaipur', sub: 'Rajasthan, India', kind: 'city', lat: 26.9124, lng: 75.7873 },
  { id: 'agra', name: 'Agra', sub: 'Uttar Pradesh, India', kind: 'city', lat: 27.1767, lng: 78.0081 },
  { id: 'lucknow', name: 'Lucknow', sub: 'Uttar Pradesh, India', kind: 'city', lat: 26.8467, lng: 80.9462 },
  { id: 'ambala', name: 'Ambala', sub: 'Haryana, India', kind: 'city', lat: 30.3782, lng: 76.7767 },
  { id: 'ludhiana', name: 'Ludhiana', sub: 'Punjab, India', kind: 'city', lat: 30.901, lng: 75.8573 },
  { id: 'amritsar', name: 'Amritsar', sub: 'Punjab, India', kind: 'city', lat: 31.634, lng: 74.8723 },
  { id: 'pathankot', name: 'Pathankot', sub: 'Punjab, India', kind: 'city', lat: 32.2643, lng: 75.6421 },
  { id: 'shimla', name: 'Shimla', sub: 'Himachal Pradesh, India', kind: 'city', lat: 31.1048, lng: 77.1734 },
  { id: 'dehradun', name: 'Dehradun', sub: 'Uttarakhand, India', kind: 'city', lat: 30.3165, lng: 78.0322 },
  { id: 'mumbai', name: 'Mumbai', sub: 'Maharashtra, India', kind: 'city', lat: 19.076, lng: 72.8777, aliases: ['bombay'] },
  { id: 'pune', name: 'Pune', sub: 'Maharashtra, India', kind: 'city', lat: 18.5204, lng: 73.8567 },
  { id: 'bengaluru', name: 'Bengaluru', sub: 'Karnataka, India', kind: 'city', lat: 12.9716, lng: 77.5946, aliases: ['bangalore'] },
  { id: 'mysuru', name: 'Mysuru', sub: 'Karnataka, India', kind: 'city', lat: 12.2958, lng: 76.6394, aliases: ['mysore'] },
  { id: 'port-blair', name: 'Port Blair', sub: 'Andaman and Nicobar Islands (no road route — test case)', kind: 'city', lat: 11.6234, lng: 92.7265 },
]

/** Known corridors: intermediate towns for the schematic preview (both directions). */
const CORRIDORS: Record<string, { name: string; lat: number; lng: number }[]> = {
  'chandigarh|jammu': [{ name: 'Ropar', lat: 30.9685, lng: 76.5265 }, { name: 'Hoshiarpur', lat: 31.5273, lng: 75.9115 }, { name: 'Pathankot', lat: 32.2643, lng: 75.6421 }],
  'delhi|jaipur': [{ name: 'Gurugram', lat: 28.4595, lng: 77.0266 }, { name: 'Behror', lat: 27.888, lng: 76.2848 }, { name: 'Shahpura', lat: 27.3903, lng: 75.9599 }],
  'mumbai|pune': [{ name: 'Panvel', lat: 18.9894, lng: 73.1175 }, { name: 'Lonavala', lat: 18.7546, lng: 73.4062 }],
  'bengaluru|mysuru': [{ name: 'Ramanagara', lat: 12.7209, lng: 77.2799 }, { name: 'Mandya', lat: 12.5218, lng: 76.8951 }],
  'delhi|chandigarh': [{ name: 'Panipat', lat: 29.3909, lng: 76.9635 }, { name: 'Karnal', lat: 29.6857, lng: 76.9905 }, { name: 'Ambala', lat: 30.3782, lng: 76.7767 }],
  'delhi|agra': [{ name: 'Mathura', lat: 27.4924, lng: 77.6737 }],
}

const cityOf = (l: Location) => l.id.split('-')[0] === 'cp' || l.id === 'noida-62' ? 'delhi' : l.id.replace(/-(rly|apt|tawi)$/, '')

export const DEV_LOCATION: Location = { id: 'dev-current', name: 'Sector 62, Noida', sub: 'Development location — REAL GEOLOCATION PERMISSION = PENDING INTEGRATION', kind: 'current', lat: 28.628, lng: 77.3649, source: 'dev-location' }

let latency = 350
export function setMockJourneyLatency(ms: number) { latency = ms }
const wait = (ms = latency) => new Promise<void>((r) => setTimeout(r, ms))
const failing = (resource: string) => {
  try { return (sessionStorage.getItem('fotg.mock.fail') ?? '').split(',').map((s) => s.trim()).includes(resource) } catch { return false }
}
const read = <T,>(key: string, fallback: T): T => { try { const raw = localStorage.getItem(key); return raw ? (JSON.parse(raw) as T) : fallback } catch { return fallback } }
const write = (key: string, value: unknown) => { try { localStorage.setItem(key, JSON.stringify(value)) } catch { /* storage unavailable — mock stays in memory */ } }
const uid = () => 'jrn-' + Math.random().toString(36).slice(2, 8) + Date.now().toString(36).slice(-4)

export const toLocation = (p: Place): Location => ({ id: p.id, name: p.name, sub: p.sub, kind: p.kind, lat: p.lat, lng: p.lng, source: 'mock' })

export class MockLocationRepository implements LocationRepository {
  private key = 'fotg.mock.recent-locations'
  async search(query: string): Promise<Location[]> {
    await wait()
    if (failing('network')) throw new JourneyError('network', 'No internet connection. Check your network and try again.')
    if (failing('location')) throw new JourneyError('lookup-failed', 'Location search failed. Please try again.')
    const q = query.trim().toLowerCase()
    if (q.length < 2 || q === 'nowhere') return []
    const score = (p: Place) => {
      const names = [p.name, ...(p.aliases ?? [])].map((n) => n.toLowerCase())
      if (names.some((n) => n === q)) return 0
      if (names.some((n) => n.startsWith(q))) return 1
      if (names.some((n) => n.includes(q)) || p.sub.toLowerCase().includes(q)) return 2
      return -1
    }
    return PLACES.map((p) => [score(p), p] as const).filter(([s]) => s >= 0).sort((a, b) => a[0] - b[0]).slice(0, 6).map(([, p]) => toLocation(p))
  }
  async recent() { return read<Location[]>(this.key, []) }
  async remember(location: Location) {
    const list = read<Location[]>(this.key, []).filter((l) => l.id !== location.id)
    write(this.key, [{ ...location, kind: location.kind }, ...list].slice(0, 5))
  }
}

export class MockRouteRepository implements RouteRepository {
  async getRoute(origin: Location, destination: Location): Promise<RouteSummary> {
    await wait(latency * 2)
    if (failing('network')) throw new JourneyError('network', 'No internet connection. Check your network and try again.')
    if (failing('route')) throw new JourneyError('unexpected', 'Route preparation failed. Please try again.')
    if (origin.lat === null || origin.lng === null || destination.lat === null || destination.lng === null) throw new JourneyError('no-route', 'One of the locations has no coordinates yet.')
    if (origin.id === 'port-blair' || destination.id === 'port-blair') throw new JourneyError('no-route', 'No road route is available between these locations.')
    const via = CORRIDORS[`${cityOf(origin)}|${cityOf(destination)}`] ?? CORRIDORS[`${cityOf(destination)}|${cityOf(origin)}`]?.slice().reverse() ?? []
    const points: [number, number][] = [[origin.lat, origin.lng], ...via.map((w) => [w.lat, w.lng] as [number, number]), [destination.lat, destination.lng]]
    let km = 0
    for (let i = 1; i < points.length; i++) km += haversine(points[i - 1], points[i])
    const distanceKm = Math.round(km * (via.length ? 1.12 : 1.25))
    const durationMin = Math.round((distanceKm / 52) * 60)
    return { provider: 'mock', distanceKm, durationMin, geometry: points, waypoints: via.map((w) => w.name), isEstimate: true }
  }
}

export class MockJourneyRepository implements JourneyRepository {
  private key = 'fotg.mock.journeys'
  private all() { return read<Journey[]>(this.key, []) }
  async create(input: JourneyInput): Promise<Journey> {
    await wait()
    const j: Journey = {
      id: uid(), origin: input.origin, destination: input.destination,
      originLat: input.origin.lat, originLng: input.origin.lng, destinationLat: input.destination.lat, destinationLng: input.destination.lng,
      departureAt: input.departureAt, createdAt: new Date().toISOString(), status: 'ready', route: null, routeGeometryRef: null,
    }
    const rest = this.all().filter((x) => !(x.origin.id === j.origin.id && x.destination.id === j.destination.id))
    write(this.key, [j, ...rest].slice(0, 8))
    return j
  }
  async get(id: string) { await wait(latency / 3); return this.all().find((j) => j.id === id) ?? null }
  async update(journey: Journey) { write(this.key, this.all().map((j) => (j.id === journey.id ? journey : j))); return journey }
  async recent() { await wait(latency / 3); return this.all() }
  async remove(id: string) { write(this.key, this.all().filter((j) => j.id !== id)) }
}

export function haversine([lat1, lng1]: [number, number], [lat2, lng2]: [number, number]) {
  const R = 6371, toRad = (d: number) => (d * Math.PI) / 180
  const dLat = toRad(lat2 - lat1), dLng = toRad(lng2 - lng1)
  const a = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2
  return 2 * R * Math.asin(Math.sqrt(a))
}

export function formatDuration(min: number) {
  const h = Math.floor(min / 60), m = min % 60
  return h ? `${h} h${m ? ` ${m} min` : ''}` : `${m} min`
}

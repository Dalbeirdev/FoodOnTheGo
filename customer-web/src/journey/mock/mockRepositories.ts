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

type Place = { id: string; name: string; sub: string; kind: Location['kind']; lat: number; lng: number; aliases?: string[]; cc: string; tz: string; admin?: string }

export const PLACES: Place[] = [
  { id: 'chandigarh', name: 'Chandigarh', sub: 'Chandigarh, India', kind: 'city', lat: 30.7333, lng: 76.7794, cc: 'IN', tz: 'Asia/Kolkata', admin: 'Chandigarh' },
  { id: 'chandigarh-rly', name: 'Chandigarh Railway Station', sub: 'Daria, Chandigarh', kind: 'station', lat: 30.7046, lng: 76.8213, cc: 'IN', tz: 'Asia/Kolkata', admin: 'Chandigarh' },
  { id: 'chandigarh-apt', name: 'Chandigarh Airport', sub: 'Shaheed Bhagat Singh International Airport', kind: 'airport', lat: 30.6735, lng: 76.7885, cc: 'IN', tz: 'Asia/Kolkata', admin: 'Chandigarh' },
  { id: 'jammu', name: 'Jammu', sub: 'Jammu and Kashmir, India', kind: 'city', lat: 32.7266, lng: 74.857, cc: 'IN', tz: 'Asia/Kolkata', admin: 'Jammu and Kashmir' },
  { id: 'jammu-tawi', name: 'Jammu Tawi Railway Station', sub: 'Jammu, Jammu and Kashmir', kind: 'station', lat: 32.7099, lng: 74.8631, cc: 'IN', tz: 'Asia/Kolkata', admin: 'Jammu and Kashmir' },
  { id: 'delhi', name: 'Delhi', sub: 'National Capital Territory, India', kind: 'city', lat: 28.6139, lng: 77.209, aliases: ['new delhi'], cc: 'IN', tz: 'Asia/Kolkata', admin: 'Delhi' },
  { id: 'cp-delhi', name: 'Connaught Place', sub: 'New Delhi, Delhi', kind: 'landmark', lat: 28.6315, lng: 77.2167, cc: 'IN', tz: 'Asia/Kolkata', admin: 'Delhi' },
  { id: 'noida-62', name: 'Sector 62, Noida', sub: 'Noida, Uttar Pradesh', kind: 'landmark', lat: 28.628, lng: 77.3649, cc: 'IN', tz: 'Asia/Kolkata', admin: 'Uttar Pradesh' },
  { id: 'gurugram', name: 'Gurugram', sub: 'Haryana, India', kind: 'city', lat: 28.4595, lng: 77.0266, aliases: ['gurgaon'], cc: 'IN', tz: 'Asia/Kolkata', admin: 'Haryana' },
  { id: 'jaipur', name: 'Jaipur', sub: 'Rajasthan, India', kind: 'city', lat: 26.9124, lng: 75.7873, cc: 'IN', tz: 'Asia/Kolkata', admin: 'Rajasthan' },
  { id: 'agra', name: 'Agra', sub: 'Uttar Pradesh, India', kind: 'city', lat: 27.1767, lng: 78.0081, cc: 'IN', tz: 'Asia/Kolkata', admin: 'Uttar Pradesh' },
  { id: 'lucknow', name: 'Lucknow', sub: 'Uttar Pradesh, India', kind: 'city', lat: 26.8467, lng: 80.9462, cc: 'IN', tz: 'Asia/Kolkata', admin: 'Uttar Pradesh' },
  { id: 'ambala', name: 'Ambala', sub: 'Haryana, India', kind: 'city', lat: 30.3782, lng: 76.7767, cc: 'IN', tz: 'Asia/Kolkata', admin: 'Haryana' },
  { id: 'ludhiana', name: 'Ludhiana', sub: 'Punjab, India', kind: 'city', lat: 30.901, lng: 75.8573, cc: 'IN', tz: 'Asia/Kolkata', admin: 'Punjab' },
  { id: 'amritsar', name: 'Amritsar', sub: 'Punjab, India', kind: 'city', lat: 31.634, lng: 74.8723, cc: 'IN', tz: 'Asia/Kolkata', admin: 'Punjab' },
  { id: 'pathankot', name: 'Pathankot', sub: 'Punjab, India', kind: 'city', lat: 32.2643, lng: 75.6421, cc: 'IN', tz: 'Asia/Kolkata', admin: 'Punjab' },
  { id: 'shimla', name: 'Shimla', sub: 'Himachal Pradesh, India', kind: 'city', lat: 31.1048, lng: 77.1734, cc: 'IN', tz: 'Asia/Kolkata', admin: 'Himachal Pradesh' },
  { id: 'dehradun', name: 'Dehradun', sub: 'Uttarakhand, India', kind: 'city', lat: 30.3165, lng: 78.0322, cc: 'IN', tz: 'Asia/Kolkata', admin: 'Uttarakhand' },
  { id: 'mumbai', name: 'Mumbai', sub: 'Maharashtra, India', kind: 'city', lat: 19.076, lng: 72.8777, aliases: ['bombay'], cc: 'IN', tz: 'Asia/Kolkata', admin: 'Maharashtra' },
  { id: 'pune', name: 'Pune', sub: 'Maharashtra, India', kind: 'city', lat: 18.5204, lng: 73.8567, cc: 'IN', tz: 'Asia/Kolkata', admin: 'Maharashtra' },
  { id: 'bengaluru', name: 'Bengaluru', sub: 'Karnataka, India', kind: 'city', lat: 12.9716, lng: 77.5946, aliases: ['bangalore'], cc: 'IN', tz: 'Asia/Kolkata', admin: 'Karnataka' },
  { id: 'mysuru', name: 'Mysuru', sub: 'Karnataka, India', kind: 'city', lat: 12.2958, lng: 76.6394, aliases: ['mysore'], cc: 'IN', tz: 'Asia/Kolkata', admin: 'Karnataka' },
  { id: 'port-blair', name: 'Port Blair', sub: 'Andaman and Nicobar Islands (no road route — test case)', kind: 'city', lat: 11.6234, lng: 92.7265, cc: 'IN', tz: 'Asia/Kolkata', admin: 'Andaman and Nicobar Islands' },
  // ---- International test fixtures (controlled test data — no market is a product default) ----
  { id: 'san-francisco', name: 'San Francisco', sub: 'California, USA', kind: 'city', lat: 37.7749, lng: -122.4194, cc: 'US', tz: 'America/Los_Angeles', admin: 'CA', aliases: ['sf'] },
  { id: 'los-angeles', name: 'Los Angeles', sub: 'California, USA', kind: 'city', lat: 34.0522, lng: -118.2437, cc: 'US', tz: 'America/Los_Angeles', admin: 'CA', aliases: ['la'] },
  { id: 'sfo', name: 'San Francisco International Airport', sub: 'SFO, California, USA', kind: 'airport', lat: 37.6213, lng: -122.379, cc: 'US', tz: 'America/Los_Angeles', admin: 'CA' },
  { id: 'london', name: 'London', sub: 'England, United Kingdom', kind: 'city', lat: 51.5074, lng: -0.1278, cc: 'GB', tz: 'Europe/London', admin: 'Greater London' },
  { id: 'manchester', name: 'Manchester', sub: 'England, United Kingdom', kind: 'city', lat: 53.4808, lng: -2.2426, cc: 'GB', tz: 'Europe/London', admin: 'Greater Manchester' },
  { id: 'euston', name: 'London Euston Station', sub: 'Euston Rd, London, UK', kind: 'station', lat: 51.5282, lng: -0.1337, cc: 'GB', tz: 'Europe/London', admin: 'Greater London' },
  { id: 'tokyo', name: '東京', sub: 'Tokyo, Japan', kind: 'city', lat: 35.6762, lng: 139.6503, cc: 'JP', tz: 'Asia/Tokyo', aliases: ['tokyo', 'とうきょう'], admin: '東京都' },
  { id: 'osaka', name: '大阪', sub: 'Osaka, Japan', kind: 'city', lat: 34.6937, lng: 135.5023, cc: 'JP', tz: 'Asia/Tokyo', aliases: ['osaka', 'おおさか'], admin: '大阪府' },
  { id: 'paris', name: 'Paris', sub: 'Île-de-France, France', kind: 'city', lat: 48.8566, lng: 2.3522, cc: 'FR', tz: 'Europe/Paris', admin: 'Paris' },
  { id: 'lyon', name: 'Lyon', sub: 'Auvergne-Rhône-Alpes, France', kind: 'city', lat: 45.764, lng: 4.8357, cc: 'FR', tz: 'Europe/Paris', admin: 'Rhône' },
  { id: 'dubai', name: 'دبي', sub: 'Dubai, United Arab Emirates', kind: 'city', lat: 25.2048, lng: 55.2708, cc: 'AE', tz: 'Asia/Dubai', aliases: ['dubai'], admin: 'Dubai' },
  { id: 'abu-dhabi', name: 'أبوظبي', sub: 'Abu Dhabi, United Arab Emirates', kind: 'city', lat: 24.4539, lng: 54.3773, cc: 'AE', tz: 'Asia/Dubai', aliases: ['abu dhabi'], admin: 'Abu Dhabi' },
]

/** Known corridors: intermediate towns for the schematic preview (both directions). */
const CORRIDORS: Record<string, { name: string; lat: number; lng: number }[]> = {
  'chandigarh|jammu': [{ name: 'Ropar', lat: 30.9685, lng: 76.5265 }, { name: 'Hoshiarpur', lat: 31.5273, lng: 75.9115 }, { name: 'Pathankot', lat: 32.2643, lng: 75.6421 }],
  'delhi|jaipur': [{ name: 'Gurugram', lat: 28.4595, lng: 77.0266 }, { name: 'Behror', lat: 27.888, lng: 76.2848 }, { name: 'Shahpura', lat: 27.3903, lng: 75.9599 }],
  'mumbai|pune': [{ name: 'Panvel', lat: 18.9894, lng: 73.1175 }, { name: 'Lonavala', lat: 18.7546, lng: 73.4062 }],
  'bengaluru|mysuru': [{ name: 'Ramanagara', lat: 12.7209, lng: 77.2799 }, { name: 'Mandya', lat: 12.5218, lng: 76.8951 }],
  'delhi|chandigarh': [{ name: 'Panipat', lat: 29.3909, lng: 76.9635 }, { name: 'Karnal', lat: 29.6857, lng: 76.9905 }, { name: 'Ambala', lat: 30.3782, lng: 76.7767 }],
  'delhi|agra': [{ name: 'Mathura', lat: 27.4924, lng: 77.6737 }],
  'delhi|mumbai': [{ name: 'Jaipur', lat: 26.9124, lng: 75.7873 }, { name: 'Udaipur', lat: 24.5854, lng: 73.7125 }, { name: 'Ahmedabad', lat: 23.0225, lng: 72.5714 }, { name: 'Vadodara', lat: 22.3072, lng: 73.1812 }, { name: 'Surat', lat: 21.1702, lng: 72.8311 }, { name: 'Vapi', lat: 20.3893, lng: 72.9106 }],
  'san-francisco|los-angeles': [{ name: 'Gilroy', lat: 37.0058, lng: -121.5683 }, { name: 'Coalinga', lat: 36.1397, lng: -120.3602 }, { name: 'Kettleman City', lat: 36.0085, lng: -119.9618 }, { name: 'Lebec', lat: 34.8422, lng: -118.8648 }],
  'london|manchester': [{ name: 'Milton Keynes', lat: 52.0406, lng: -0.7594 }, { name: 'Watford Gap', lat: 52.3106, lng: -1.1231 }, { name: 'Birmingham', lat: 52.4862, lng: -1.8904 }, { name: 'Stoke-on-Trent', lat: 53.0027, lng: -2.1794 }],
  'tokyo|osaka': [{ name: '静岡', lat: 34.9756, lng: 138.3828 }, { name: '浜松', lat: 34.7108, lng: 137.7261 }, { name: '名古屋', lat: 35.1815, lng: 136.9066 }],
  'paris|lyon': [{ name: 'Auxerre', lat: 47.798, lng: 3.5733 }, { name: 'Beaune', lat: 47.024, lng: 4.8401 }],
  'dubai|abu-dhabi': [{ name: 'Jebel Ali', lat: 24.9857, lng: 55.0273 }, { name: 'Ghantoot', lat: 24.8712, lng: 54.8613 }],
}

const cityOf = (l: Location) => l.id.split('-')[0] === 'cp' || l.id === 'noida-62' ? 'delhi' : l.id === 'sfo' ? 'san-francisco' : l.id === 'euston' ? 'london' : l.id.replace(/-(rly|apt|tawi)$/, '')

export const DEV_LOCATION: Location = { id: 'dev-current', name: 'Sector 62, Noida', sub: 'Development location — REAL GEOLOCATION PERMISSION = PENDING INTEGRATION', kind: 'current', lat: 28.628, lng: 77.3649, source: 'dev-location', countryCode: 'IN', timezone: 'Asia/Kolkata' }

let latency = 350
export function setMockJourneyLatency(ms: number) { latency = ms }
const wait = (ms = latency) => new Promise<void>((r) => setTimeout(r, ms))
const failing = (resource: string) => {
  try { return (sessionStorage.getItem('fotg.mock.fail') ?? '').split(',').map((s) => s.trim()).includes(resource) } catch { return false }
}
const read = <T,>(key: string, fallback: T): T => { try { const raw = localStorage.getItem(key); return raw ? (JSON.parse(raw) as T) : fallback } catch { return fallback } }
const write = (key: string, value: unknown) => { try { localStorage.setItem(key, JSON.stringify(value)) } catch { /* storage unavailable — mock stays in memory */ } }
const uid = () => 'jrn-' + Math.random().toString(36).slice(2, 8) + Date.now().toString(36).slice(-4)

export const toLocation = (p: Place): Location => ({ id: p.id, name: p.name, sub: p.sub, kind: p.kind, lat: p.lat, lng: p.lng, source: 'mock', placeId: `mock:${p.id}`, formattedAddress: `${p.name}, ${p.sub}`, countryCode: p.cc, timezone: p.tz, adminArea: p.admin })

export class MockLocationRepository implements LocationRepository {
  private key = 'fotg.mock.recent-locations'
  async search(query: string): Promise<Location[]> {
    await wait()
    if (failing('network')) throw new JourneyError('network', 'No internet connection. Check your network and try again.')
    if (failing('location')) throw new JourneyError('lookup-failed', 'Location search failed. Please try again.')
    const norm = (x: string) => x.normalize('NFKD').replace(/\p{M}/gu, '').toLocaleLowerCase()
    const q = norm(query.trim())
    if (q.length < 2 || q === 'nowhere') return []
    const score = (p: Place) => {
      const names = [p.name, ...(p.aliases ?? [])].map(norm)
      if (names.some((n) => n === q)) return 0
      if (names.some((n) => n.startsWith(q))) return 1
      if (names.some((n) => n.includes(q)) || norm(p.sub).includes(q)) return 2
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

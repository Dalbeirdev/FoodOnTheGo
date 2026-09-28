/**
 * Geometry helpers on WGS84 (SRID 4326) coordinates. Development only: the production path is
 * PostGIS (geography(POINT,4326) / geometry(LINESTRING,4326) + GiST) with the routing provider
 * supplying real geometry and detours. Nothing here assumes a country or a straight road.
 */
export type LatLng = [number, number]

const R = 6371008.8 // metres
const rad = (d: number) => (d * Math.PI) / 180

export function haversineM(a: LatLng, b: LatLng): number {
  const dLat = rad(b[0] - a[0]), dLng = rad(b[1] - a[1])
  const s = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a[0])) * Math.cos(rad(b[0])) * Math.sin(dLng / 2) ** 2
  return 2 * R * Math.asin(Math.sqrt(s))
}

/** Local equirectangular projection around a reference latitude — accurate enough for corridor widths. */
function project(p: LatLng, refLat: number): [number, number] {
  return [rad(p[1]) * Math.cos(rad(refLat)) * R, rad(p[0]) * R]
}

/**
 * Shortest distance (metres) from a point to a polyline, plus the fractional position (0..1)
 * along the polyline where the closest point lies.
 */
export function distanceToPolyline(point: LatLng, line: LatLng[]): { meters: number; position: number } {
  if (line.length === 0) return { meters: Number.POSITIVE_INFINITY, position: 0 }
  if (line.length === 1) return { meters: haversineM(point, line[0]), position: 0 }
  const refLat = point[0]
  const p = project(point, refLat)
  const segLen: number[] = []
  let total = 0
  for (let i = 1; i < line.length; i++) { const l = haversineM(line[i - 1], line[i]); segLen.push(l); total += l }
  let best = { meters: Number.POSITIVE_INFINITY, position: 0 }
  let before = 0
  for (let i = 1; i < line.length; i++) {
    const a = project(line[i - 1], refLat), b = project(line[i], refLat)
    const abx = b[0] - a[0], aby = b[1] - a[1]
    const len2 = abx * abx + aby * aby
    const t = len2 === 0 ? 0 : Math.max(0, Math.min(1, ((p[0] - a[0]) * abx + (p[1] - a[1]) * aby) / len2))
    const cx = a[0] + t * abx, cy = a[1] + t * aby
    const d = Math.hypot(p[0] - cx, p[1] - cy)
    if (d < best.meters) best = { meters: d, position: total === 0 ? 0 : (before + t * segLen[i - 1]) / total }
    before += segLen[i - 1]
  }
  return best
}

/** Bounding box of a polyline expanded by `padM` metres — used to bound candidate search. */
export function boundsOf(line: LatLng[], padM = 0): { minLat: number; maxLat: number; minLng: number; maxLng: number } {
  let minLat = 90, maxLat = -90, minLng = 180, maxLng = -180
  for (const [lat, lng] of line) { minLat = Math.min(minLat, lat); maxLat = Math.max(maxLat, lat); minLng = Math.min(minLng, lng); maxLng = Math.max(maxLng, lng) }
  const dLat = padM / 111_320
  const dLng = padM / (111_320 * Math.max(Math.cos(rad((minLat + maxLat) / 2)), 0.01))
  return { minLat: minLat - dLat, maxLat: maxLat + dLat, minLng: minLng - dLng, maxLng: maxLng + dLng }
}

export const inBounds = (p: LatLng, b: ReturnType<typeof boundsOf>) => p[0] >= b.minLat && p[0] <= b.maxLat && p[1] >= b.minLng && p[1] <= b.maxLng

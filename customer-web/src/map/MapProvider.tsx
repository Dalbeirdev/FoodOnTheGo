/**
 * Map provider abstraction (Module 06). Map display is a separate capability from places search
 * and routing (PlacesProvider / RoutingProvider live in the journey layer). The only implementation
 * today is a schematic development shell; GoogleMapProvider (or another vendor) plugs in behind the
 * same props without touching the discovery page. Coordinates are WGS84 worldwide — nothing here
 * knows about a city or country.
 */
import { useMemo, type ReactElement } from 'react'
import type { LatLng } from '../geo/geo'

export type MapMarker = { id: string; position: LatLng; label: string; selected?: boolean; muted?: boolean }
export type MapViewProps = {
  route?: LatLng[] | null
  origin?: { position: LatLng; label: string } | null
  destination?: { position: LatLng; label: string } | null
  markers: MapMarker[]
  selectedId?: string | null
  onSelect?: (id: string) => void
  /** Accessible name for the whole map region. */
  ariaLabel: string
  updating?: boolean
  shellNote: string
}

export interface MapProvider {
  readonly id: string
  readonly ready: boolean
  MapView: (props: MapViewProps) => ReactElement
}

function SchematicMapView({ route, origin, destination, markers, selectedId, onSelect, ariaLabel, updating, shellNote }: MapViewProps) {
  const W = 640, H = 420, PAD = 40
  const { pts, path, project } = useMemo(() => {
    const all: LatLng[] = [...(route ?? []), ...markers.map((m) => m.position), ...(origin ? [origin.position] : []), ...(destination ? [destination.position] : [])]
    if (all.length === 0) return { pts: [] as [number, number][], path: '', project: () => [0, 0] as [number, number] }
    const lats = all.map((p) => p[0]), lngs = all.map((p) => p[1])
    const minLat = Math.min(...lats), maxLat = Math.max(...lats), minLng = Math.min(...lngs), maxLng = Math.max(...lngs)
    const spanLat = Math.max(maxLat - minLat, 0.02), spanLng = Math.max(maxLng - minLng, 0.02)
    // Preserve aspect so long north–south routes do not get stretched.
    const scale = Math.min((W - PAD * 2) / spanLng, (H - PAD * 2) / spanLat)
    const ox = (W - spanLng * scale) / 2, oy = (H - spanLat * scale) / 2
    const project = (p: LatLng): [number, number] => [ox + (p[1] - minLng) * scale, H - oy - (p[0] - minLat) * scale]
    const pts = (route ?? []).map(project)
    const path = pts.map(([x, y], i) => `${i ? 'L' : 'M'}${x.toFixed(1)} ${y.toFixed(1)}`).join(' ')
    return { pts, path, project }
  }, [route, markers, origin, destination])

  return (
    <div className={`smap ${updating ? 'is-updating' : ''}`}>
      <svg viewBox={`0 0 ${W} ${H}`} role="group" aria-label={ariaLabel} preserveAspectRatio="xMidYMid meet">
        <defs><pattern id="smap-grid" width="32" height="32" patternUnits="userSpaceOnUse"><path d="M32 0H0V32" fill="none" stroke="#e3e7ee" strokeWidth="1" /></pattern></defs>
        <rect width={W} height={H} fill="url(#smap-grid)" rx="16" />
        {path && <><path d={path} fill="none" stroke="#ffd7c2" strokeWidth="14" strokeLinecap="round" strokeLinejoin="round" /><path d={path} fill="none" stroke="#f24e1e" strokeWidth="4" strokeLinecap="round" strokeLinejoin="round" strokeDasharray="10 8" /></>}
        {pts.length === 0 && markers.length > 0 && <text x={W / 2} y={28} textAnchor="middle" fontSize="13" fill="#5f6675">General discovery — no route context</text>}
        {markers.map((m) => {
          const [x, y] = project(m.position)
          const sel = m.id === selectedId
          return (
            <g key={m.id} className={`smap__marker ${sel ? 'is-selected' : ''} ${m.muted ? 'is-muted' : ''}`} tabIndex={0} role="button" aria-label={m.label} aria-pressed={sel} onClick={() => onSelect?.(m.id)} onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onSelect?.(m.id) } }} transform={`translate(${x} ${y})`}>
              <path d="M0 0 C-9 -12 -12 -18 -12 -24 A12 12 0 1 1 12 -24 C12 -18 9 -12 0 0Z" fill={sel ? '#e5261f' : m.muted ? '#b9bfcc' : '#ff8a00'} stroke="#fff" strokeWidth="2" />
              <circle cx="0" cy="-24" r="4.5" fill="#fff" />
              {sel && <text y={-34} textAnchor="middle" fontSize="12" fontWeight="700" fill="#1b1f2a">{m.label}</text>}
            </g>
          )
        })}
        {origin && (() => { const [x, y] = project(origin.position); return <g><circle cx={x} cy={y} r="9" fill="#ff8a00" stroke="#fff" strokeWidth="3" /><text x={x} y={y - 14} textAnchor="middle" fontSize="13" fontWeight="700" fill="#1b1f2a">{origin.label}</text></g> })()}
        {destination && (() => { const [x, y] = project(destination.position); return <g><circle cx={x} cy={y} r="9" fill="#e5261f" stroke="#fff" strokeWidth="3" /><text x={x} y={y - 14} textAnchor="middle" fontSize="13" fontWeight="700" fill="#1b1f2a">{destination.label}</text></g> })()}
      </svg>
      <span className="smap__badge">{shellNote}</span>
    </div>
  )
}

export const schematicMapProvider: MapProvider = { id: 'schematic-dev', ready: true, MapView: SchematicMapView }
/** Swap this for GoogleMapProvider (or another vendor) once integration is approved — MAP PROVIDER = PENDING. */
export const mapProvider: MapProvider = schematicMapProvider

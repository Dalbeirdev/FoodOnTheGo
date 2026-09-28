import type { Journey } from '../journey/JourneyContext'
import { formatDuration } from '../journey/mock/mockRepositories'
import './RoutePreview.css'

/**
 * Development route preview: a schematic corridor drawn from the mock geometry.
 * This is NOT a map. Google Maps rendering is tracked as pending; the text list below the
 * drawing carries the same information for screen readers and for when no map is available.
 */
export default function RoutePreview({ journey }: { journey: Journey }) {
  const r = journey.route
  if (!r) return null
  const lats = r.geometry.map((p) => p[0]), lngs = r.geometry.map((p) => p[1])
  const minLat = Math.min(...lats), maxLat = Math.max(...lats), minLng = Math.min(...lngs), maxLng = Math.max(...lngs)
  const W = 640, H = 220, PAD = 36
  const sx = (lng: number) => PAD + ((lng - minLng) / Math.max(maxLng - minLng, 0.0001)) * (W - PAD * 2)
  const sy = (lat: number) => H - PAD - ((lat - minLat) / Math.max(maxLat - minLat, 0.0001)) * (H - PAD * 2)
  const pts = r.geometry.map(([lat, lng]) => [sx(lng), sy(lat)] as const)
  const d = pts.map(([x, y], i) => `${i ? 'L' : 'M'}${x.toFixed(1)} ${y.toFixed(1)}`).join(' ')
  const stops = [journey.origin.name, ...r.waypoints, journey.destination.name]
  const summary = `Route from ${journey.origin.name} to ${journey.destination.name}${r.waypoints.length ? ` via ${r.waypoints.join(', ')}` : ''}: about ${r.distanceKm} km, ${formatDuration(r.durationMin)} (development estimate).`

  return (
    <figure className="rp">
      <div className="rp__canvas">
        <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label={summary} preserveAspectRatio="xMidYMid meet">
          <defs><pattern id="rp-grid" width="32" height="32" patternUnits="userSpaceOnUse"><path d="M32 0H0V32" fill="none" stroke="#e6e9ef" strokeWidth="1" /></pattern></defs>
          <rect width={W} height={H} fill="url(#rp-grid)" rx="14" />
          <path d={d} fill="none" stroke="#ffd7c2" strokeWidth="12" strokeLinecap="round" strokeLinejoin="round" />
          <path d={d} fill="none" stroke="#f24e1e" strokeWidth="4" strokeLinecap="round" strokeLinejoin="round" strokeDasharray="10 8" />
          {pts.map(([x, y], i) => {
            const isEnd = i === 0 || i === pts.length - 1
            return (
              <g key={i}>
                <circle cx={x} cy={y} r={isEnd ? 9 : 6} fill={i === 0 ? '#ff8a00' : i === pts.length - 1 ? '#e5261f' : '#fff'} stroke={isEnd ? '#fff' : '#f24e1e'} strokeWidth={isEnd ? 3 : 2.5} />
                <text x={x} y={y - (isEnd ? 16 : 12)} textAnchor="middle" fontSize={isEnd ? 14 : 12} fontWeight={isEnd ? 700 : 500} fill="#1b1f2a">{stops[i]}</text>
              </g>
            )
          })}
        </svg>
        <span className="rp__badge">Development route preview — not a map</span>
      </div>
      <figcaption className="rp__caption">
        <b>Route (development estimate)</b>
        <ol className="rp__stops" aria-label="Route stops">{stops.map((s, i) => <li key={i} className={i === 0 ? 'is-origin' : i === stops.length - 1 ? 'is-dest' : ''}>{s}</li>)}</ol>
        <p>Real map rendering and turn-by-turn geometry arrive with Google Maps and Google Routes integration (PENDING). Distances and times shown here are mock development values.</p>
      </figcaption>
    </figure>
  )
}

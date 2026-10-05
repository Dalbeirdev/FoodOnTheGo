import type { Journey } from '../journey/JourneyContext'
import { formatDuration } from '../journey/mock/mockRepositories'
import './RoutePreview.css'

/**
 * Schematic route preview: the route line drawn on a plain grid. This is NOT a map (map tiles arrive with the map
 * provider integration, still pending); the text list below the drawing carries the same information for screen
 * readers and for when no map is available.
 *
 * The LINE is whatever the journey carries: with the backend (Module 26) it is the routing provider's own geometry —
 * never a straight line between the two ends; in the browser's development mode it is the corridor sketch of Module 05.
 */
export default function RoutePreview({ journey }: { journey: Journey }) {
  const r = journey.route
  if (!r || r.geometry.length < 2) return null
  const mode = r.mode ?? 'mock'
  const lats = r.geometry.map((p) => p[0]), lngs = r.geometry.map((p) => p[1])
  const minLat = Math.min(...lats), maxLat = Math.max(...lats), minLng = Math.min(...lngs), maxLng = Math.max(...lngs)
  const W = 640, H = 220, PAD = 36
  let sx: (lng: number) => number, sy: (lat: number) => number
  if (mode === 'mock') {
    sx = (lng) => PAD + ((lng - minLng) / Math.max(maxLng - minLng, 0.0001)) * (W - PAD * 2)
    sy = (lat) => H - PAD - ((lat - minLat) / Math.max(maxLat - minLat, 0.0001)) * (H - PAD * 2)
  } else {
    // A real line keeps its shape: one scale for both axes (longitude shortened by the latitude), centred.
    const k = Math.cos(((minLat + maxLat) / 2) * Math.PI / 180)
    const spanX = Math.max((maxLng - minLng) * k, 0.0001), spanY = Math.max(maxLat - minLat, 0.0001)
    const scale = Math.min((W - PAD * 2) / spanX, (H - PAD * 2) / spanY)
    const ox = (W - spanX * scale) / 2, oy = (H - spanY * scale) / 2
    sx = (lng) => ox + (lng - minLng) * k * scale
    sy = (lat) => H - oy - (lat - minLat) * scale
  }
  const pts = r.geometry.map(([lat, lng]) => [sx(lng), sy(lat)] as const)
  const d = pts.map(([x, y], i) => `${i ? 'L' : 'M'}${x.toFixed(1)} ${y.toFixed(1)}`).join(' ')
  // Named stops are marked only where the line is exactly those stops (the browser's corridor sketch); a provider line
  // has hundreds of points and only its two ends carry a name.
  const sketch = r.waypoints.length > 0 && r.geometry.length === r.waypoints.length + 2
  const stops = [journey.origin.name, ...(sketch ? r.waypoints : []), journey.destination.name]
  const marks = sketch ? pts.map((p, i) => ({ p, label: stops[i], end: i === 0 || i === pts.length - 1, first: i === 0 })) : [{ p: pts[0], label: journey.origin.name, end: true, first: true }, { p: pts[pts.length - 1], label: journey.destination.name, end: true, first: false }]
  const source = mode === 'live' ? 'routing provider estimate' : mode === 'development' ? 'development routing model' : 'development estimate'
  const summary = `Route from ${journey.origin.name} to ${journey.destination.name}${sketch ? ` via ${r.waypoints.join(', ')}` : ''}: about ${r.distanceKm} km, ${formatDuration(r.durationMin)} (${source}).`
  const anchor = (x: number) => (x < 90 ? 'start' : x > W - 90 ? 'end' : 'middle')

  return (
    <figure className="rp" data-route-mode={mode} data-route-points={r.geometry.length}>
      <div className="rp__canvas">
        <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label={summary} preserveAspectRatio="xMidYMid meet">
          <defs><pattern id="rp-grid" width="32" height="32" patternUnits="userSpaceOnUse"><path d="M32 0H0V32" fill="none" stroke="#e6e9ef" strokeWidth="1" /></pattern></defs>
          <rect width={W} height={H} fill="url(#rp-grid)" rx="14" />
          <path d={d} fill="none" stroke="#ffd7c2" strokeWidth="12" strokeLinecap="round" strokeLinejoin="round" />
          <path d={d} fill="none" stroke="#f24e1e" strokeWidth="4" strokeLinecap="round" strokeLinejoin="round" strokeDasharray={mode === 'mock' ? '10 8' : undefined} />
          {marks.map(({ p: [x, y], label, end, first }, i) => (
            <g key={i}>
              <circle cx={x} cy={y} r={end ? 9 : 6} fill={first ? '#ff8a00' : end ? '#e5261f' : '#fff'} stroke={end ? '#fff' : '#f24e1e'} strokeWidth={end ? 3 : 2.5} />
              <text x={x} y={y - (end ? 16 : 12) < 14 ? y + 26 : y - (end ? 16 : 12)} textAnchor={anchor(x)} fontSize={end ? 14 : 12} fontWeight={end ? 700 : 500} fill="#1b1f2a">{label}</text>
            </g>
          ))}
        </svg>
        <span className="rp__badge">{mode === 'mock' ? 'Development route preview — not a map' : mode === 'development' ? 'Development routing model — schematic view, not a map' : 'Route from the routing provider — schematic view, not a map'}</span>
      </div>
      <figcaption className="rp__caption">
        <b>{mode === 'mock' ? 'Route (development estimate)' : mode === 'development' ? 'Route (development routing model)' : 'Route (routing provider)'}</b>
        <ol className="rp__stops" aria-label="Route stops">{stops.map((s, i) => <li key={i} className={i === 0 ? 'is-origin' : i === stops.length - 1 ? 'is-dest' : ''}>{s}</li>)}</ol>
        {mode === 'mock' && <p>Real map rendering and turn-by-turn geometry arrive with Google Maps and Google Routes integration (PENDING). Distances and times shown here are mock development values.</p>}
        {mode === 'development' && <p>This line, the distance and the travel time come from the backend's development routing model: synthetic roads, not real road data. A live routing provider replaces it once it is configured. Map tiles need the map provider integration (PENDING).</p>}
        {mode === 'live' && <p>Line, distance and travel time come from the routing provider{r.trafficAware ? ', with its traffic forecast for your departure' : ''}. They are estimates, not a guarantee. Map tiles need the map provider integration (PENDING).</p>}
      </figcaption>
    </figure>
  )
}

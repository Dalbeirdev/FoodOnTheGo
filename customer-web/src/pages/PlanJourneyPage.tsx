import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import Header from '../components/Header'
import LocationInput from '../components/LocationInput'
import RoutePreview from '../components/RoutePreview'
import { useJourney, type Journey } from '../journey/JourneyContext'
import { formatDuration } from '../journey/mock/mockRepositories'
import './PlanJourneyPage.css'

type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const })
const CalendarIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><rect x="3" y="5" width="18" height="16" rx="2" /><path d="M3 10h18M8 3v4M16 3v4" /></svg>)
const ClockSmall = ({ size = 18 }: P) => (<svg {...stroke(size)}><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>)
const SwapIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M4 7h13M14 4l3 3-3 3M20 17H7M10 14l-3 3 3 3" /></svg>)
const StoreIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="M3 9.5 4.5 4h15L21 9.5M3 9.5a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0M5 12v8h14v-8M10 20v-5h4v5" /></svg>)
const ClockIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>)
const BagIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="M6 8h12l1 12H5L6 8Z" /><path d="M9 8V6a3 3 0 0 1 6 0v2" /></svg>)
const CarIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M5 11l1.5-4.5A2 2 0 0 1 8.4 5h7.2a2 2 0 0 1 1.9 1.5L19 11M4 11h16v6H4zM6 17v2M18 17v2" /><circle cx="7.5" cy="14" r="1" /><circle cx="16.5" cy="14" r="1" /></svg>)
const ArrowIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M5 12h14M13 6l6 6-6 6" /></svg>)
const HistoryIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M3 12a9 9 0 1 0 3-6.7M3 4v5h5" /><path d="M12 7v5l3 2" /></svg>)

const BENEFITS = [
  { icon: StoreIcon, title: 'Discover restaurants', text: 'along your route' },
  { icon: ClockIcon, title: 'Check detour time', text: 'and distance' },
  { icon: BagIcon, title: 'Order and pickup', text: 'easily' },
]

const today = () => new Date().toISOString().slice(0, 10)
const fmtDeparture = (iso: string | null) => iso ? new Date(iso).toLocaleString('en-IN', { weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }) : 'Leaving now'
const fmtCreated = (iso: string) => new Date(iso).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })

/** Plan a Journey — approved design kept; inputs now run on LocationRepository / JourneyRepository / RouteRepository. */
export default function PlanJourneyPage() {
  const navigate = useNavigate()
  const j = useJourney()
  const [originText, setOriginText] = useState('')
  const [destinationText, setDestinationText] = useState('')
  const [date, setDate] = useState(j.departureAt ? j.departureAt.slice(0, 10) : '')
  const [time, setTime] = useState(j.departureAt ? j.departureAt.slice(11, 16) : '')

  const applyDeparture = (d: string, t: string) => { setDate(d); setTime(t); j.setDepartureAt(d ? new Date(`${d}T${t || '09:00'}:00`).toISOString() : null) }
  const submit = async (e: FormEvent) => { e.preventDefault(); await j.plan(originText, destinationText) }
  const busy = j.status === 'validating' || j.status === 'ready' || j.status === 'route-loading'
  const showPreview = j.journey && (j.status === 'route-available' || j.status === 'route-loading' || j.status === 'error' || j.status === 'ready')
  const continueTo = (journey: Journey) => navigate(`/restaurants?journey=${encodeURIComponent(journey.id)}`)

  return (
    <>
      <Header />
      <main id="main" className="pj">
        <div className="pj__bg" aria-hidden="true">
          <img src="/images/hero-highway.jpg" alt="" onError={(e) => { e.currentTarget.style.display = 'none' }} />
          <div className="pj__fade" />
        </div>

        <div className="pj__inner">
          <form className="pj-card" onSubmit={submit} noValidate aria-busy={busy}>
            <h1>Plan Your Journey</h1>
            <p className="pj-card__sub">Find restaurants along your route</p>

            <div className="pj-fields">
              <LocationInput id="pj-from" label="Starting Point" placeholder="e.g. Chandigarh, station, airport or saved place" value={j.origin} onChange={j.setOrigin} onTextChange={setOriginText} error={j.errors.origin} allowCurrent />
              <button type="button" className="pj-swap" aria-label="Swap start and destination" onClick={j.swap} disabled={!j.origin && !j.destination}><SwapIcon /></button>
              <LocationInput id="pj-to" label="Destination" placeholder="e.g. Jammu, city, landmark or saved place" value={j.destination} onChange={j.setDestination} onTextChange={setDestinationText} error={j.errors.destination} pinClass="loc__pin--dest" />
            </div>

            <div className="pj-when">
              <label className="pj-field">
                <span>Travel Date (Optional)</span>
                <span className="pj-input"><CalendarIcon /><input type="date" value={date} min={today()} onChange={(e) => applyDeparture(e.target.value, time)} aria-label="Travel date" /></span>
              </label>
              <label className="pj-field">
                <span>Departure Time (Optional)</span>
                <span className="pj-input"><ClockSmall /><input type="time" value={time} disabled={!date} onChange={(e) => applyDeparture(date, e.target.value)} aria-label="Departure time" /></span>
              </label>
            </div>
            {j.errors.form && <p className="pj-error" role="alert">{j.errors.form}</p>}
            {j.status === 'error' && !j.journey && <p className="pj-error" role="alert">{j.error}</p>}

            <button type="submit" className="btn btn--primary pj-submit" disabled={busy}>
              {j.status === 'validating' ? 'Checking locations…' : j.status === 'ready' ? 'Preparing journey…' : j.status === 'route-loading' ? 'Loading route…' : 'Find Restaurants on Route'}
            </button>
            <p className="pj-guest-note">No sign-in needed to plan a journey. Sign in later to save favorites or place an order.</p>
          </form>

          {showPreview && j.journey && (
            <section className="pj-preview" aria-labelledby="pj-preview-title" aria-live="polite">
              <div className="pj-preview__head">
                <h2 id="pj-preview-title">Your journey</h2>
                <button type="button" className="pj-link" onClick={j.edit}>Edit</button>
              </div>
              <div className="pj-preview__route">
                <span className="pj-preview__pt pj-preview__pt--from"><small>From</small><b>{j.journey.origin.name}</b><span>{j.journey.origin.sub}</span></span>
                <span className="pj-preview__arrow" aria-hidden="true"><ArrowIcon /></span>
                <span className="pj-preview__pt pj-preview__pt--to"><small>To</small><b>{j.journey.destination.name}</b><span>{j.journey.destination.sub}</span></span>
              </div>
              <p className="pj-preview__when"><CalendarIcon size={16} /> {fmtDeparture(j.journey.departureAt)}</p>

              {j.status === 'ready' && <p className="pj-preview__status" role="status">Preparing journey…</p>}
              {j.status === 'route-loading' && (
                <div className="pj-preview__loading" role="status" aria-label="Loading route">
                  <span className="pj-skel" /><span className="pj-skel pj-skel--map" />
                  <p>Finding route…</p>
                </div>
              )}
              {j.status === 'error' && (
                <div className="pj-preview__error" role="alert">
                  <b>Error loading journey</b>
                  <p>{j.error}</p>
                  <div className="pj-preview__actions">
                    <button type="button" className="btn btn--primary" onClick={() => { void j.retryRoute() }}>Try again</button>
                    <button type="button" className="btn btn--outline" onClick={j.edit}>Edit locations</button>
                  </div>
                </div>
              )}
              {j.status === 'route-available' && j.journey.route && (
                <>
                  <dl className="pj-preview__stats">
                    <div><dt><CarIcon /> Approximate distance</dt><dd>{j.journey.route.distanceKm} km <small>mock development data</small></dd></div>
                    <div><dt><ClockSmall /> Approximate travel time</dt><dd>{formatDuration(j.journey.route.durationMin)} <small>mock development data</small></dd></div>
                  </dl>
                  <RoutePreview journey={j.journey} />
                  <p className="pj-preview__pickup">Restaurants are picked up at their own location along this corridor — your destination stays {j.journey.destination.name}. FoodOnTheGo does not deliver.</p>
                  <button type="button" className="btn btn--primary pj-submit" onClick={() => continueTo(j.journey!)}>Find Restaurants Along Route <ArrowIcon /></button>
                </>
              )}
            </section>
          )}

          {j.recent.length > 0 && (
            <section className="pj-recent" aria-labelledby="pj-recent-title">
              <h2 id="pj-recent-title"><HistoryIcon /> Recent journeys</h2>
              <ul>
                {j.recent.map((r) => (
                  <li key={r.id}>
                    <span className="pj-recent__route"><b>{r.origin.name}</b> <ArrowIcon size={14} /> <b>{r.destination.name}</b><small>{r.route ? `${r.route.distanceKm} km · ${formatDuration(r.route.durationMin)} (mock)` : 'route not prepared'} · {fmtCreated(r.createdAt)}</small></span>
                    <span className="pj-recent__actions">
                      <button type="button" className="pj-link" onClick={() => { j.useAgain(r); window.scrollTo({ top: 0, behavior: 'smooth' }) }}>Use again</button>
                      <button type="button" className="pj-link pj-link--danger" onClick={() => { void j.removeRecent(r.id) }} aria-label={`Remove ${r.origin.name} to ${r.destination.name} from recent journeys`}>Remove</button>
                    </span>
                  </li>
                ))}
              </ul>
              <p className="pj-recent__note">Stored on this device only — journey history sync arrives with the backend.</p>
            </section>
          )}

          <ul className="pj-benefits">
            {BENEFITS.map(({ icon: Icon, title, text }) => <li key={title}><span><Icon /></span><b>{title}</b>{text}</li>)}
          </ul>
        </div>
      </main>
    </>
  )
}

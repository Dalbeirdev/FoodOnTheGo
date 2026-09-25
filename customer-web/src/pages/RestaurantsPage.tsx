import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import Header from '../components/Header'
import { ClockIcon, PinIcon, StarIcon } from '../components/Icons'
import { useAccount } from '../account/AccountContext'
import './RestaurantsPage.css'

type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const })
const SwapIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M4 7h13M14 4l3 3-3 3M20 17H7M10 14l-3 3 3 3" /></svg>)
const CarIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M5 11l1.5-4.5A2 2 0 0 1 8.4 5h7.2a2 2 0 0 1 1.9 1.5L19 11M4 11h16v6H4zM6 17v2M18 17v2" /><circle cx="7.5" cy="14" r="1" /><circle cx="16.5" cy="14" r="1" /></svg>)
const HeartIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M12 21s-7.5-4.6-9.5-9.3C1 8 3.5 4.5 7 4.5c2 0 3.5 1 5 2.8 1.5-1.8 3-2.8 5-2.8 3.5 0 6 3.5 4.5 7.2C19.5 16.4 12 21 12 21Z" /></svg>)
const ListIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M8 6h13M8 12h13M8 18h13" /><circle cx="4" cy="6" r="1" fill="currentColor" /><circle cx="4" cy="12" r="1" fill="currentColor" /><circle cx="4" cy="18" r="1" fill="currentColor" /></svg>)
const MapIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="m3 6 6-2 6 2 6-2v14l-6 2-6-2-6 2V6ZM9 4v14M15 6v14" /></svg>)
const ChevronDown = ({ size = 16 }: P) => (<svg {...stroke(size)}><path d="m6 9 6 6 6-6" /></svg>)
const ParkingIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><rect x="4" y="4" width="16" height="16" rx="3" /><path d="M9 16V8h3.5a2.5 2.5 0 0 1 0 5H9" /></svg>)
const DriveIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M3 17h2l1.5-5h11L19 17h2M6 17v2M18 17v2" /><rect x="5" y="12" width="14" height="5" /></svg>)
const SeatIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M5 11V6a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v5M3 11h18v4H3zM5 15v4M19 15v4" /></svg>)
const LeafIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M5 19c0-8 4-13 14-14 0 10-5 14-13 14M5 19l6-6" /></svg>)

const CUISINES = ['Indian', 'Italian', 'Chinese', 'American', 'Fast Food', 'Healthy Food', 'Desserts & Beverages']
const RATINGS = [{ v: 4.5, s: 4 }, { v: 4.0, s: 4 }, { v: 3.5, s: 3 }, { v: 3.0, s: 3 }]
const FACILITIES = [
  { icon: ParkingIcon, label: 'Parking' },
  { icon: DriveIcon, label: 'Drive Through' },
  { icon: SeatIcon, label: 'Outdoor Seating' },
  { icon: LeafIcon, label: 'Veg Options' },
]

import { RESTAURANTS as REPO_RESTAURANTS } from '../repositories/mock/restaurants'
import type { Restaurant } from '../repositories'
export type { Restaurant }
/** Re-exported for pages that resolve restaurants by id; source of truth is the repository. */
export const RESTAURANTS: Restaurant[] = REPO_RESTAURANTS

function Stars({ n }: { n: number }) {
  return (
    <span className="stars" aria-hidden="true">
      {[0, 1, 2, 3, 4].map((i) => <StarIcon key={i} size={13} className={i < n ? 'is-on' : ''} />)}
    </span>
  )
}

export default function RestaurantsPage() {
  const [params] = useSearchParams()
  const [view, setView] = useState<'list' | 'map'>('list')
  const [radius, setRadius] = useState(5)
  const [distance, setDistance] = useState(10)
  const { isFavorite, toggleFavorite } = useAccount()

  return (
    <>
      <Header />
      <main id="main" className="rest">
        {/* ---------- Hero + route search ---------- */}
        <section className="rest-hero">
          <div className="rest-hero__bg" aria-hidden="true">
            <img src="/images/hero-restaurants.jpg" alt="" onError={(e) => { e.currentTarget.style.display = 'none' }} />
            <div className="rest-hero__fade" />
          </div>

          <div className="rest-hero__inner">
            <div className="rest-hero__content">
              <p className="rest-eyebrow">Explore restaurants</p>
              <h1 className="rest-hero__title">
                <span>Great Food</span>
                <span className="rest-accent">Along Your Route</span>
              </h1>
              <p className="rest-hero__lead">
                Discover top-rated restaurants on or near your route, pre-order your favourite food and pick it up at
                the perfect time.
              </p>
            </div>

            <form className="route-box" onSubmit={(e) => e.preventDefault()}>
              <div className="route-box__top">
                <label className="route-field">
                  <PinIcon size={24} className="route-field__pin route-field__pin--from" />
                  <span>
                    <small>Your Location</small>
                    <input type="text" defaultValue={params.get('from') ?? 'Sector 62, Noida'} aria-label="Your location" />
                  </span>
                </label>
                <button type="button" className="route-swap" aria-label="Swap locations"><SwapIcon /></button>
                <label className="route-field">
                  <PinIcon size={24} className="route-field__pin" />
                  <span>
                    <small>Destination</small>
                    <input type="text" defaultValue={params.get('to') ?? 'Connaught Place, Delhi'} aria-label="Destination" />
                  </span>
                </label>
                <button type="submit" className="btn btn--primary route-box__submit">Find Restaurants</button>
              </div>

              <div className="route-box__bottom">
                <span className="route-summary"><CarIcon /> <b>32 km</b> • ~ 45 min</span>
                <span className="route-radius">
                  Find restaurants within
                  <span className="select-wrap">
                    <select value={radius} onChange={(e) => setRadius(Number(e.target.value))} aria-label="Search radius">
                      {[2, 5, 10, 15].map((k) => <option key={k} value={k}>{k} km</option>)}
                    </select>
                    <ChevronDown />
                  </span>
                  of route
                </span>
              </div>
            </form>
          </div>
        </section>

        {/* ---------- Results ---------- */}
        <section className="rest-body">
          <aside className="filters">
            <div className="filters__head">
              <h2>Filter Results</h2>
              <button type="button" className="filters__clear">Clear All</button>
            </div>

            <div className="filters__group">
              <h3>Cuisine Type</h3>
              {CUISINES.map((c) => (
                <label key={c} className="check"><input type="checkbox" /><span>{c}</span></label>
              ))}
              <button type="button" className="filters__more">More <ChevronDown /></button>
            </div>

            <div className="filters__group">
              <h3>Distance from Route</h3>
              <input type="range" min={0} max={10} value={distance} onChange={(e) => setDistance(Number(e.target.value))} className="range" aria-label="Distance from route" style={{ ['--pct' as string]: `${distance * 10}%` }} />
              <div className="range__labels"><span>0 km</span><span>{distance} km</span></div>
            </div>

            <div className="filters__group">
              <h3>Rating</h3>
              {RATINGS.map((r) => (
                <label key={r.v} className="check"><input type="checkbox" /><Stars n={r.s} /><span>{r.v.toFixed(1)} &amp; above</span></label>
              ))}
            </div>

            <div className="filters__group">
              <h3>Facilities</h3>
              {FACILITIES.map(({ icon: Icon, label }) => (
                <label key={label} className="check check--icon"><input type="checkbox" /><Icon /><span>{label}</span></label>
              ))}
              <button type="button" className="filters__more">More <ChevronDown /></button>
            </div>
          </aside>

          <div className="results">
            <div className="results__bar">
              <h2>124 Restaurants on Your Route</h2>
              <div className="results__tools">
                <label className="sort">Sort by
                  <span className="select-wrap">
                    <select aria-label="Sort by"><option>Recommended</option><option>Rating</option><option>Detour time</option><option>Distance</option></select>
                    <ChevronDown />
                  </span>
                </label>
                <div className="view-toggle" role="group" aria-label="View">
                  <button type="button" className={view === 'list' ? 'is-on' : ''} onClick={() => setView('list')}><ListIcon /> List View</button>
                  <button type="button" className={view === 'map' ? 'is-on' : ''} onClick={() => setView('map')}><MapIcon /> Map View</button>
                </div>
              </div>
            </div>

            {view === 'map' ? (
              <div className="map-placeholder">Map view will load once the Maps module is connected.</div>
            ) : (
              <ul className="cards">
                {RESTAURANTS.map((r) => (
                  <li key={r.id} className="rcard">
                    <div className="rcard__media">
                      <img src={r.image} alt={r.name} onError={(e) => { e.currentTarget.style.display = 'none' }} />
                      <span className="rcard__fallback" aria-hidden="true">{r.fallback}</span>
                      <span className="rcard__detour"><ClockIcon size={18} /><span>{r.detour}<br /><small>detour</small></span></span>
                      <button type="button" className={`rcard__like ${isFavorite(r.id) ? 'is-on' : ''}`} aria-pressed={isFavorite(r.id)} aria-label={`Save ${r.name}`} onClick={() => toggleFavorite(r.id)}><HeartIcon /></button>
                    </div>
                    <div className="rcard__body">
                      <div className="rcard__row">
                        <h3>{r.name}</h3>
                        <span className="rcard__rating"><StarIcon size={14} /> {r.rating.toFixed(1)} <small>({r.reviews})</small></span>
                      </div>
                      <p className="rcard__cuisine">{r.cuisines.join(' • ')}</p>
                      <p className="rcard__meta">
                        <span><PinIcon size={16} /> {r.distance}</span>
                        <span className="rcard__sep" />
                        <span><ClockIcon size={16} /> {r.time}</span>
                      </p>
                      <div className="rcard__tags">{r.tags.map((t) => <span key={t}>{t}</span>)}</div>
                      <div className="rcard__actions">
                        <Link to={`/restaurants/${r.id}`} className="rcard__menu">View Menu</Link>
                        <Link to={`/restaurants/${r.id}`} className="btn btn--primary rcard__order">Order Now</Link>
                      </div>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </section>
      </main>
    </>
  )
}

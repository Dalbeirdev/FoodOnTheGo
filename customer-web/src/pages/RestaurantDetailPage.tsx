import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import Header from '../components/Header'
import { ClockIcon, PinIcon, StarIcon } from '../components/Icons'
import CartBar from '../components/CartBar'
import { useCart } from '../cart/CartContext'
import { MENU, inr } from '../data/menu'
import { RESTAURANTS } from './RestaurantsPage'
import './RestaurantDetailPage.css'

type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const })
const BackIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M19 12H5M11 6l-6 6 6 6" /></svg>)
const HeartIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M12 21s-7.5-4.6-9.5-9.3C1 8 3.5 4.5 7 4.5c2 0 3.5 1 5 2.8 1.5-1.8 3-2.8 5-2.8 3.5 0 6 3.5 4.5 7.2C19.5 16.4 12 21 12 21Z" /></svg>)
const ShareIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><circle cx="18" cy="5" r="2.5" /><circle cx="6" cy="12" r="2.5" /><circle cx="18" cy="19" r="2.5" /><path d="m8.2 10.8 7.6-4.6M8.2 13.2l7.6 4.6" /></svg>)
const CameraIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M4 8h3l2-3h6l2 3h3v11H4z" /><circle cx="12" cy="13" r="3.5" /></svg>)
const CarIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="M5 11l1.5-4.5A2 2 0 0 1 8.4 5h7.2a2 2 0 0 1 1.9 1.5L19 11M4 11h16v6H4zM6 17v2M18 17v2" /><circle cx="7.5" cy="14" r="1" /><circle cx="16.5" cy="14" r="1" /></svg>)
const SeatIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="M5 11V6a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v5M3 11h18v4H3zM5 15v4M19 15v4" /></svg>)
const LeafIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="M5 19c0-8 4-13 14-14 0 10-5 14-13 14M5 19l6-6" /></svg>)
const ForkIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M7 2v8a3 3 0 0 0 6 0V2M10 2v20M17 2c-2 1-3 4-3 7v3h3v10" /></svg>)
const StarOutline = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2Z" /></svg>)
const ImageIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><rect x="3" y="4" width="18" height="16" rx="2" /><circle cx="9" cy="10" r="1.5" /><path d="m21 16-5-5-8 8" /></svg>)
const InfoIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><circle cx="12" cy="12" r="9" /><path d="M12 11v5M12 8h.01" /></svg>)
const LocateIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><circle cx="12" cy="12" r="7" /><circle cx="12" cy="12" r="2" /><path d="M12 2v3M12 19v3M2 12h3M19 12h3" /></svg>)
const VegIcon = ({ size = 16 }: P) => (<svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="#1d8f3f" strokeWidth="2.2"><rect x="3" y="3" width="18" height="18" rx="4" /><circle cx="12" cy="12" r="4" fill="#1d8f3f" stroke="none" /></svg>)
const PlusIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M12 5v14M5 12h14" /></svg>)
const MinusIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M5 12h14" /></svg>)

const TABS = ['All Items', 'Burgers', 'Combos', 'Sides', 'Drinks', 'Desserts', 'Veg Options']

const HOURS = [
  ['Monday', '8:00 AM – 11:00 PM'], ['Tuesday', '8:00 AM – 11:00 PM'], ['Wednesday', '8:00 AM – 11:00 PM'],
  ['Thursday', '8:00 AM – 11:00 PM'], ['Friday', '8:00 AM – 12:00 AM'], ['Saturday', '8:00 AM – 12:00 AM'], ['Sunday', '8:00 AM – 11:00 PM'],
]

const POPULAR = [
  { icon: ClockIcon, title: 'Quick Service', text: 'Fresh food, fast' },
  { icon: StarOutline, title: 'Great Taste', text: '4.5 average rating' },
  { icon: PinIcon, title: 'Convenient Location', text: 'Right on your route' },
  { icon: SeatIcon, title: 'Clean & Comfortable', text: 'Well maintained' },
]

function Img({ src, fallback, alt = '' }: { src: string; fallback: string; alt?: string }) {
  return (
    <span className="rd-img">
      <img src={src} alt={alt} onError={(e) => { e.currentTarget.style.display = 'none' }} />
      <span className="rd-img__fallback" aria-hidden="true">{fallback}</span>
    </span>
  )
}

export default function RestaurantDetailPage() {
  const { id } = useParams()
  const restaurant = RESTAURANTS.find((r) => r.id === id) ?? RESTAURANTS[0]
  const [tab, setTab] = useState('All Items')
  const [liked, setLiked] = useState<Set<string>>(new Set())
  const cart = useCart()

  const add = (k: string) => {
    const item = MENU.flatMap((s) => s.items).find((i) => i.id === k)!
    cart.add({ key: `${restaurant.id}:${k}`, itemId: k, restaurantId: restaurant.id, name: item.name, unitPrice: item.price, image: item.image, fallback: item.fallback })
  }
  const remove = (k: string) => cart.remove(`${restaurant.id}:${k}`)
  const toggleLike = (k: string) => setLiked((s) => { const n = new Set(s); n.has(k) ? n.delete(k) : n.add(k); return n })

  const sections = MENU.filter((s) => tab === 'All Items' || tab === 'Veg Options' || s.title === tab)
    .map((s) => tab === 'Veg Options' ? { ...s, items: s.items.filter((i) => i.veg) } : s)
    .filter((s) => s.items.length)

  return (
    <>
      <Header />
      <main id="main" className={`rd ${cart.count ? 'has-cart' : ''}`}>
        <div className="rd__grid">
          {/* ================= Main column ================= */}
          <div className="rd__main">
            <section className="rd-hero">
              <Img src="/images/restaurant-burger-hub-cover.jpg" fallback="🍔" alt={`${restaurant.name} cover`} />
              <Link to="/restaurants" className="rd-hero__back"><BackIcon /> Back to Results</Link>
              <div className="rd-hero__actions">
                <button type="button" className="rd-round" aria-label="Save restaurant"><HeartIcon /></button>
                <button type="button" className="rd-round" aria-label="Share"><ShareIcon /></button>
              </div>
              <button type="button" className="rd-hero__photos"><CameraIcon /> View Photos (12)</button>
            </section>

            <section className="rd-info">
              <div className="rd-info__logo"><Img src="/images/restaurant-burger-hub-logo.jpg" fallback="🍔" /></div>
              <div className="rd-info__body">
                <div className="rd-info__top">
                  <div>
                    <h1>{restaurant.name}</h1>
                    <p className="rd-info__cuisine">{restaurant.cuisines.join(' • ')}</p>
                    <p className="rd-info__rating"><StarIcon size={16} /> <b>{restaurant.rating.toFixed(1)}</b> ({restaurant.reviews} reviews) <span className="rd-badge">Top Rated</span></p>
                  </div>
                  <div className="rd-info__gallery" aria-hidden="true">
                    <Img src="/images/gallery-burger-1.jpg" fallback="🍔" />
                    <Img src="/images/gallery-interior.jpg" fallback="🪑" />
                    <Img src="/images/gallery-fries.jpg" fallback="🍟" />
                  </div>
                </div>
                <ul className="rd-facts">
                  <li><PinIcon size={22} /><span><b>{restaurant.distance}</b>{restaurant.detour} detour</span></li>
                  <li><ClockIcon size={22} /><span><b>10–15 mins</b>Prep time</span></li>
                  <li><CarIcon /><span><b>Parking</b>Available</span></li>
                  <li><SeatIcon /><span><b>Outdoor Seating</b>Available</span></li>
                  <li><LeafIcon /><span><b>Veg Options</b>Available</span></li>
                </ul>
                <p className="rd-info__desc">Juicy burgers, crispy fries and more! Burger Hub offers fresh, high-quality ingredients and delicious meals for travellers on the go.</p>
              </div>
            </section>

            <nav className="rd-tabs" aria-label="Restaurant sections">
              <button type="button" className="is-on"><ForkIcon /> Menu</button>
              <button type="button"><StarOutline /> Reviews ({restaurant.reviews})</button>
              <button type="button"><ImageIcon /> Photos (12)</button>
              <button type="button"><InfoIcon /> Info</button>
            </nav>

            <div className="rd-chips" role="tablist">
              {TABS.map((t) => (
                <button key={t} type="button" role="tab" aria-selected={tab === t} className={tab === t ? 'is-on' : ''} onClick={() => setTab(t)}>{t}</button>
              ))}
            </div>

            {sections.map((s) => (
              <section key={s.id} className="rd-section" id={s.id}>
                <div className="rd-section__head">
                  <div><h2>{s.title}</h2><p>{s.sub}</p></div>
                  <button type="button" className="rd-section__all">View All</button>
                </div>
                <ul className="menu-grid">
                  {s.items.map((item) => {
                    const q = cart.qtyOf(item.id)
                    return (
                      <li key={item.id} className="mcard">
                        <div className="mcard__media">
                          <Img src={item.image} fallback={item.fallback} alt={item.name} />
                          {item.popular && <span className="mcard__popular">Popular</span>}
                          <button type="button" className={`mcard__like ${liked.has(item.id) ? 'is-on' : ''}`} aria-pressed={liked.has(item.id)} aria-label={`Save ${item.name}`} onClick={() => toggleLike(item.id)}><HeartIcon size={18} /></button>
                        </div>
                        <div className="mcard__body">
                          <h3>{item.veg && <VegIcon />} <Link to={`/restaurant/${restaurant.id}/item/${item.id}`}>{item.name}</Link></h3>
                          <p>{item.desc}</p>
                          <div className="mcard__foot">
                            <strong>{inr(item.price)}</strong>
                            {q === 0 ? (
                              <button type="button" className="mcard__add" aria-label={`Add ${item.name}`} onClick={() => add(item.id)}><PlusIcon /></button>
                            ) : (
                              <span className="mcard__qty">
                                <button type="button" aria-label={`Remove one ${item.name}`} onClick={() => remove(item.id)}><MinusIcon /></button>
                                <b>{q}</b>
                                <button type="button" className="is-plus" aria-label={`Add one ${item.name}`} onClick={() => add(item.id)}><PlusIcon /></button>
                              </span>
                            )}
                          </div>
                        </div>
                      </li>
                    )
                  })}
                </ul>
              </section>
            ))}
          </div>

          {/* ================= Sidebar ================= */}
          <aside className="rd__side">
            <div className="rd-map">
              <Img src="/images/map-burger-hub.jpg" fallback="" />
              <svg className="rd-map__route" viewBox="0 0 360 220" aria-hidden="true">
                <path d="M20 190 C 90 150, 140 130, 200 115 S 280 95, 330 60" stroke="#2f7cf6" strokeWidth="6" fill="none" strokeLinecap="round" />
                <circle cx="20" cy="190" r="7" fill="#2f7cf6" stroke="#fff" strokeWidth="3" />
              </svg>
              <span className="rd-map__detour"><ClockIcon size={16} /> 5 min<br /><small>detour</small></span>
              <span className="rd-map__car">🚗</span>
              <span className="rd-map__pin"><PinIcon size={40} /><b>{restaurant.name}</b></span>
              <span className="rd-map__label">Your Route</span>
              <button type="button" className="rd-round rd-map__locate" aria-label="Centre on my location"><LocateIcon /></button>
            </div>

            <div className="rd-side-card rd-distance">
              <span className="rd-distance__icon"><PinIcon size={22} /></span>
              <span><b>{restaurant.distance.replace('from route', 'from your route')}</b>Approx. {restaurant.detour} detour</span>
              <button type="button" className="btn btn--primary rd-distance__btn">View on Map</button>
            </div>

            <div className="rd-side-card">
              <div className="rd-side-card__head"><h2>Opening Hours</h2><span className="rd-open">Open Now</span></div>
              <ul className="rd-hours">{HOURS.map(([d, h]) => <li key={d}><span>{d}</span><b>{h}</b></li>)}</ul>
            </div>

            <div className="rd-side-card">
              <h2>Popular With Travellers</h2>
              <ul className="rd-popular">
                {POPULAR.map(({ icon: Icon, title, text }) => (
                  <li key={title}><span className="rd-popular__icon"><Icon size={22} /></span><span><b>{title}</b>{text}</span></li>
                ))}
              </ul>
            </div>
          </aside>
        </div>

        <CartBar />
      </main>
    </>
  )
}

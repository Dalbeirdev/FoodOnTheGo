import { Link } from 'react-router-dom'
import Header from '../components/Header'
import { ArrowRightIcon, ClockIcon, ChevronRightIcon, PinIcon, SearchIcon, StarIcon } from '../components/Icons'
import PublicIcon from '../components/PublicIcons'
import { contentRepository, restaurantRepository } from '../repositories'
import './HowItWorksPage.css'

const hiw = contentRepository.getHowItWorksContent()
const STEPS = hiw.steps
const BENEFITS = hiw.benefits
const ALL = restaurantRepository.list()
const STOPS = ALL.slice(0, 2).map((r) => ({ name: r.name, dist: r.distance.replace('from route', 'off route'), rating: String(r.rating), detour: r.detour, image: r.image, fallback: r.fallback }))
const RESTAURANTS = ALL.slice(0, 3).map((r) => ({ name: r.name, cat: r.cuisines.slice(0, 2).join(' • '), rating: String(r.rating), count: r.reviews, detour: `${r.detour} detour • ${r.distance.replace(' from route', '')}`, image: r.image, fallback: r.fallback }))

function FoodImage({ src, fallback, alt }: { src: string; fallback: string; alt: string }) {
  return (
    <span className="food-img">
      <img src={src} alt={alt} onError={(e) => { e.currentTarget.style.display = 'none' }} />
      <span className="food-img__fallback" aria-hidden="true">{fallback}</span>
    </span>
  )
}

export default function HowItWorksPage() {
  return (
    <>
      <Header />
      <main id="main" className="hiw">
        {/* ---------- Hero ---------- */}
        <section className="hiw-hero">
          <div className="hiw-hero__bg" aria-hidden="true">
            <img src="/images/hero-journey.jpg" alt="" onError={(e) => { e.currentTarget.style.display = 'none' }} />
            <div className="hiw-hero__fade" />
          </div>

          <div className="hiw-hero__inner">
            <div className="hiw-hero__content">
              <p className="hiw-eyebrow">{hiw.hero.eyebrow}</p>
              <h1 className="hiw-hero__title">
                <span>{hiw.hero.title}</span>
                <span className="hiw-hero__accent">{hiw.hero.accent}</span>
              </h1>
              <p className="hiw-hero__lead">{hiw.hero.lead}</p>
              <Link to={hiw.hero.cta.to} className="btn btn--primary btn--lg">
                <span className="btn__icon-circle"><ArrowRightIcon size={16} /></span>
                {hiw.hero.cta.label}
              </Link>
            </div>

            <div className="hiw-hero__visual" aria-hidden="true">
              <svg className="hiw-hero__route" viewBox="0 0 200 320" fill="none">
                <path d="M150 0 C 150 60, 40 90, 60 150 S 130 230, 100 320" stroke="#ff7a1a" strokeWidth="10" strokeLinecap="round" opacity=".9" />
              </svg>
              <span className="hiw-pin hiw-pin--1"><PinIcon size={46} /></span>
              <span className="hiw-pin hiw-pin--2"><PinIcon size={46} /></span>
              <span className="hiw-pin hiw-pin--3"><PinIcon size={46} /></span>

              {STOPS.map((s, i) => (
                <article key={s.name} className={`hiw-stop hiw-stop--${i + 1}`}>
                  <FoodImage src={s.image} fallback={s.fallback} alt={s.name} />
                  <div className="hiw-stop__body">
                    <strong>{s.name}</strong>
                    <span className="hiw-stop__row">
                      <span>{s.dist}</span>
                      <span className="hiw-stop__rating"><StarIcon size={13} /> {s.rating}</span>
                    </span>
                  </div>
                  <span className="hiw-stop__detour"><ClockIcon size={18} /><span>{s.detour}<br /><small>detour</small></span></span>
                </article>
              ))}
            </div>
          </div>
        </section>

        {/* ---------- Steps ---------- */}
        <section className="hiw-steps" id="steps">
          <p className="hiw-eyebrow hiw-eyebrow--center">Simple steps</p>
          <h2 className="hiw-steps__title">How FoodOnTheGo Works</h2>

          <ol className="hiw-steps__grid">
            {STEPS.map((step, i) => (
              <li key={step.title} className="hiw-step">
                <div className="hiw-step__head">
                  <span className="hiw-step__num">{i + 1}</span>
                  <div>
                    <h3 className="hiw-step__title">{step.title}</h3>
                    <p className="hiw-step__text">{step.text}</p>
                  </div>
                </div>

                {i === 0 && (
                  <div className="phone phone--map">
                    <div className="phone__screen">
                      <div className="phone__search">
                        <span className="phone__field"><SearchIcon size={14} /> Your Location</span>
                        <span className="phone__field"><PinIcon size={14} /> Jaipur</span>
                      </div>
                      <div className="phone__map">
                        <svg viewBox="0 0 240 200" className="phone__map-route">
                          <path d="M20 150 C 80 120, 140 100, 220 50" stroke="#2f7cf6" strokeWidth="5" fill="none" strokeLinecap="round" />
                          <circle cx="20" cy="150" r="6" fill="#fff" stroke="#e5261f" strokeWidth="3" />
                          <circle cx="220" cy="50" r="6" fill="#fff" stroke="#e5261f" strokeWidth="3" />
                        </svg>
                        <span className="phone__car">🚗</span>
                        <span className="phone__map-pin"><PinIcon size={36} /></span>
                      </div>
                      <button type="button" className="phone__cta"><span>←</span> Plan Journey <ArrowRightIcon size={16} /></button>
                    </div>
                  </div>
                )}

                {i === 1 && (
                  <div className="phone">
                    <div className="phone__screen">
                      <h4 className="phone__h">Restaurants on Your Route</h4>
                      <div className="phone__toggle"><span className="is-on">☰ List</span><span>⊞ Map</span></div>
                      <ul className="phone__list">
                        {RESTAURANTS.map((r) => (
                          <li key={r.name}>
                            <FoodImage src={r.image} fallback={r.fallback} alt={r.name} />
                            <div>
                              <strong>{r.name}</strong>
                              <span className="phone__meta">{r.cat}</span>
                              <span className="phone__meta phone__meta--rating"><StarIcon size={11} /> {r.rating} ({r.count})</span>
                              <span className="phone__meta">{r.detour}</span>
                            </div>
                            <ChevronRightIcon size={16} />
                          </li>
                        ))}
                      </ul>
                    </div>
                  </div>
                )}

                {i === 2 && (
                  <div className="phone">
                    <div className="phone__screen phone__screen--menu">
                      <div className="phone__rest">
                        <FoodImage src="/images/food-burger.jpg" fallback="🍔" alt="Burger Hub" />
                        <div>
                          <strong>Burger Hub</strong>
                          <span className="phone__meta phone__meta--rating"><StarIcon size={11} /> 4.5 (320)</span>
                          <span className="phone__meta">5 min detour • 0.8 km</span>
                        </div>
                      </div>
                      <div className="phone__tabs"><span className="is-on">Menu</span><span>Reviews</span><span>Info</span></div>
                      <div className="phone__chips"><span className="is-on">Burgers</span><span>Combos</span><span>Sides</span><span>Drinks</span></div>
                      <ul className="phone__list phone__list--menu">
                        <li>
                          <FoodImage src="/images/food-burger.jpg" fallback="🍔" alt="Classic Burger" />
                          <div>
                            <strong>Classic Burger</strong>
                            <span className="phone__meta">Juicy grilled patty with fresh veggies</span>
                            <span className="phone__price">₹250</span>
                          </div>
                          <span className="phone__qty"><b>−</b>1<b className="is-plus">+</b></span>
                        </li>
                        <li>
                          <FoodImage src="/images/food-burger.jpg" fallback="🍔" alt="Cheese Burger" />
                          <div>
                            <strong>Cheese Burger</strong>
                            <span className="phone__meta">Loaded with melted cheese</span>
                            <span className="phone__price">₹280</span>
                          </div>
                          <span className="phone__qty"><b className="is-plus">+</b></span>
                        </li>
                      </ul>
                    </div>
                  </div>
                )}

                {i === 3 && (
                  <div className="hiw-photo">
                    <img src="/images/pickup-handover.jpg" alt="Customer receiving a FoodOnTheGo order bag from a restaurant window" onError={(e) => { e.currentTarget.style.display = 'none' }} />
                    <span className="hiw-photo__fallback" aria-hidden="true">🛍️</span>
                  </div>
                )}

                {i < STEPS.length - 1 && <span className="hiw-step__arrow" aria-hidden="true"><ArrowRightIcon size={30} /></span>}
              </li>
            ))}
          </ol>
        </section>

        {/* ---------- Benefits ---------- */}
        <section className="hiw-benefits" aria-label="Benefits">
          <div className="hiw-benefits__card">
            {BENEFITS.map(({ icon, title, text }) => (
              <div key={title} className="hiw-benefit">
                <span className="hiw-benefit__icon"><PublicIcon name={icon} size={30} /></span>
                <div>
                  <h3>{title}</h3>
                  <p>{text}</p>
                </div>
              </div>
            ))}
          </div>
        </section>
      </main>
    </>
  )
}

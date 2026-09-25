import { Link } from 'react-router-dom'
import { restaurantRepository, useHomeContent } from '../repositories'
import { ArrowRightIcon, ChevronRightIcon, ClockIcon, PinIcon, StarIcon, StoreIcon } from './Icons'
import StoreBadges from './StoreBadges'
import './Hero.css'

export default function Hero() {
  const home = useHomeContent()
  const stops = home.featuredRestaurantIds.map((id) => restaurantRepository.byId(id)).filter((r) => !!r)

  return (
    <section className="hero">
      <div className="hero__bg" aria-hidden="true">
        <img src="/images/hero-highway.jpg" alt="" className="hero__photo" onError={(e) => { e.currentTarget.style.display = 'none' }} />
        <div className="hero__fade" />
      </div>

      <div className="hero__inner">
        <div className="hero__content">
          <p className="hero__eyebrow">{home.eyebrow}</p>
          <h1 className="hero__title">
            <span>{home.title}</span>
            <span className="hero__title-accent">{home.accent}</span>
          </h1>
          <div className="hero__rule" aria-hidden="true" />
          <p className="hero__lead">{home.lead}</p>

          <div className="hero__cta">
            <Link to={home.primaryCta.to} className="btn btn--primary btn--lg">
              <span className="btn__icon-circle"><ArrowRightIcon size={16} /></span>
              {home.primaryCta.label}
            </Link>
            <Link to={home.secondaryCta.to} className="btn btn--glass btn--lg">
              <StoreIcon size={22} className="btn__icon-orange" />
              {home.secondaryCta.label}
            </Link>
          </div>

          <div className="hero__stores">
            <StoreBadges stores={home.stores} />
          </div>
        </div>

        <div className="hero__visual" aria-hidden="true">
          <div className="hero__finder">
            <span className="hero__finder-pin"><PinIcon size={20} /></span>
            <span className="hero__finder-text">Find great food<br />on your route</span>
            <ChevronRightIcon size={22} className="hero__finder-chev" />
          </div>

          <svg className="hero__route" viewBox="0 0 220 340" fill="none">
            <path d="M120 20 C 170 60, 60 110, 105 160 S 140 250, 60 300" stroke="url(#routeGrad)" strokeWidth="6" strokeLinecap="round" strokeDasharray="14 12" />
            <defs>
              <linearGradient id="routeGrad" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" stopColor="#ff8a00" />
                <stop offset="1" stopColor="#f7451f" />
              </linearGradient>
            </defs>
          </svg>

          <span className="hero__pin hero__pin--1"><PinIcon size={44} /></span>
          <span className="hero__pin hero__pin--2"><PinIcon size={44} /></span>
          <span className="hero__pin hero__pin--3"><PinIcon size={44} /></span>

          {stops.map((stop, i) => (
            <article key={stop.id} className={`stop-card stop-card--${i + 1}`}>
              <div className="stop-card__media">
                <img src={stop.image} alt="" onError={(e) => { e.currentTarget.style.display = 'none' }} />
                <span className="stop-card__fallback">{stop.fallback}</span>
              </div>
              <div className="stop-card__body">
                <div className="stop-card__row">
                  <strong>{stop.name}</strong>
                  <span className="stop-card__rating"><StarIcon size={14} /> {stop.rating}</span>
                </div>
                <span className="stop-card__cat">{stop.cuisines.slice(0, 2).join(' • ')}</span>
              </div>
              <span className="stop-card__detour"><ClockIcon size={16} /> {stop.detour} detour</span>
            </article>
          ))}
        </div>
      </div>
    </section>
  )
}

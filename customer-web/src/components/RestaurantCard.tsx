import { Link } from 'react-router-dom'
import { ClockIcon, PinIcon, StarIcon } from './Icons'
import { formatDistance, formatLocalTime, formatMinutes, priceLevelLabel, zoneLabel } from '../i18n/format'
import { resolveUnitSystem, type UnitSystem } from '../i18n/markets'
import { t, useLocale } from '../i18n/strings'
import type { RouteRestaurantResult } from '../repositories/types'

type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const })
const HeartIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M12 21s-7.5-4.6-9.5-9.3C1 8 3.5 4.5 7 4.5c2 0 3.5 1 5 2.8 1.5-1.8 3-2.8 5-2.8 3.5 0 6 3.5 4.5 7.2C19.5 16.4 12 21 12 21Z" /></svg>)
const CarIcon = ({ size = 16 }: P) => (<svg {...stroke(size)}><path d="M5 11l1.5-4.5A2 2 0 0 1 8.4 5h7.2a2 2 0 0 1 1.9 1.5L19 11M4 11h16v6H4zM6 17v2M18 17v2" /><circle cx="7.5" cy="14" r="1" /><circle cx="16.5" cy="14" r="1" /></svg>)

type Props = { result: RouteRestaurantResult; units?: UnitSystem; selected?: boolean; favorite: boolean; onFavorite: () => void; onSelect?: () => void; ringLabel?: string }

/** Route-aware restaurant card. Every number goes through locale/unit/currency/time-zone formatting. */
export default function RestaurantCard({ result, units, selected, favorite, onFavorite, onSelect, ringLabel }: Props) {
  const { locale, unitPreference } = useLocale()
  const { restaurant: r, availability: a } = result
  const u = units ?? resolveUnitSystem(unitPreference, r.countryCode)
  const statusKey = a.status === 'open' ? 'card.open' : a.status === 'closing_soon' ? 'card.closingSoon' : a.status === 'opening_soon' ? 'card.openingSoon' : a.status === 'temporarily_closed' ? 'card.temporarilyClosed' : 'card.closed'
  const nextChange = a.nextChangeAt ? t(a.status === 'open' || a.status === 'closing_soon' ? 'card.closesAt' : 'card.opensAt', { time: formatLocalTime(a.nextChangeAt, r.timezone, locale) }, locale) : ''
  const to = `/restaurants/${r.slug}`
  return (
    <li className={`rcard ${selected ? 'is-selected' : ''} ${a.status === 'closed' || a.status === 'temporarily_closed' ? 'is-closed' : ''}`} data-id={r.id} onMouseEnter={onSelect} onFocus={onSelect}>
      <div className="rcard__media">
        <img src={r.image} alt="" loading="lazy" onError={(e) => { e.currentTarget.style.display = 'none' }} />
        <span className="rcard__fallback" aria-hidden="true">{r.fallback}</span>
        {result.detourDurationMin !== null && <span className="rcard__detour"><ClockIcon size={18} /><span>{formatMinutes(result.detourDurationMin, locale)}<br /><small>{t('card.detourShort', undefined, locale)}</small></span></span>}
        <button type="button" className={`rcard__like ${favorite ? 'is-on' : ''}`} aria-pressed={favorite} aria-label={t(favorite ? 'card.unsave' : 'card.save', { name: r.name }, locale)} onClick={onFavorite}><HeartIcon /></button>
      </div>
      <div className="rcard__body">
        <div className="rcard__row">
          <h3 lang={r.countryCode === 'JP' ? 'ja' : r.countryCode === 'AE' && /[؀-ۿ]/.test(r.name) ? 'ar' : undefined} dir="auto">{r.name}</h3>
          <span className="rcard__rating"><StarIcon size={14} /> {r.rating.toLocaleString(locale, { minimumFractionDigits: 1, maximumFractionDigits: 1 })} <small>{t('card.reviews', { count: r.reviewCount.toLocaleString(locale) }, locale)}</small></span>
        </div>
        <p className="rcard__cuisine" dir="auto">{r.cuisines.join(' • ')} <span className="rcard__price" aria-label={`price level ${r.priceLevel} of 4, ${r.currency}`}>{priceLevelLabel(r.priceLevel, r.currency, locale)}</span></p>
        <p className="rcard__status">
          <span className={`rcard__open rcard__open--${a.status}`}>{t(statusKey, undefined, locale)}</span>
          {ringLabel && <span className={`rcard__ring rcard__ring--${result.ring ?? 'x'}`}>{ringLabel}</span>}
          {nextChange && <small>{nextChange} <abbr title={r.timezone}>{zoneLabel(a.nextChangeAt!, r.timezone, locale)}</abbr></small>}
          {!a.acceptingOrders && a.status !== 'closed' && a.status !== 'temporarily_closed' && <small>{t('card.notAcceptingOrders', undefined, locale)}</small>}
        </p>
        <p className="rcard__meta">
          {result.distanceFromRouteM !== null && <span><PinIcon size={16} /> {t('card.fromRoute', { distance: formatDistance(result.distanceFromRouteM, u, locale) }, locale)}</span>}
          {result.distanceFromRouteM === null && result.distanceFromScopeM !== null && result.distanceFromScopeM !== undefined && <span><PinIcon size={16} /> {formatDistance(result.distanceFromScopeM, u, locale)}</span>}
          {result.distanceFromRouteM !== null && <span className="rcard__sep" />}
          <span><ClockIcon size={16} /> {t('card.prep', { minutes: formatMinutes(r.prepTimeMin, locale) }, locale)}</span>
          {result.estimatedArrival && <><span className="rcard__sep" /><span><CarIcon /> {t('card.arrival', { time: formatLocalTime(result.estimatedArrival, r.timezone, locale) }, locale)}</span></>}
        </p>
        <p className="rcard__addr" dir="auto">{r.address.formatted}</p>
        <div className="rcard__tags">{r.features.slice(0, 3).map((f) => <span key={f}>{f}</span>)}</div>
        <div className="rcard__actions">
          <Link to={to} className="rcard__menu">{t('card.viewMenu', undefined, locale)}</Link>
          <Link to={to} className="btn btn--primary rcard__order">{t('card.viewRestaurant', undefined, locale)}</Link>
        </div>
      </div>
    </li>
  )
}

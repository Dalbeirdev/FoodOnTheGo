import { Link } from 'react-router-dom'
import Header from '../../components/Header'
import AccountSidebar from '../../components/AccountSidebar'
import { ClockIcon, PinIcon, StarIcon } from '../../components/Icons'
import { useAccount } from '../../account/AccountContext'
import { RESTAURANTS } from '../RestaurantsPage'
import './AccountPage.css'
import './FavoritesPage.css'

const HeartIcon = ({ size = 18, filled = false }: { size?: number; filled?: boolean }) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill={filled ? 'currentColor' : 'none'} stroke="currentColor" strokeWidth="2" strokeLinejoin="round" aria-hidden="true"><path d="M12 21s-7.5-4.6-9.5-9.3C1 8 3.5 4.5 7 4.5c2 0 3.5 1 5 2.8 1.5-1.8 3-2.8 5-2.8 3.5 0 6 3.5 4.5 7.2C19.5 16.4 12 21 12 21Z" /></svg>
)

export default function FavoritesPage() {
  const { favorites, toggleFavorite } = useAccount()
  const items = favorites.map((f) => RESTAURANTS.find((r) => r.id === f.restaurantId)).filter((r): r is (typeof RESTAURANTS)[number] => !!r)

  return (
    <>
      <Header />
      <main id="main" className="ac">
        <div className="ac__grid">
          <AccountSidebar />
          <div className="ac__main">
            <div className="ac-head"><div><h1>Favorite Restaurants</h1><p>Your saved restaurants for quick access</p></div></div>

            {items.length === 0 ? (
              <div className="ac-card ac-empty"><span aria-hidden="true">💔</span><h3>No favorites yet</h3><p>Tap the heart on any restaurant to save it here.</p><Link to="/restaurants" className="btn btn--primary">Explore Restaurants</Link></div>
            ) : (
              <ul className="fav-grid">
                {items.map((r) => (
                  <li key={r.id} className="fav-card">
                    <Link to={`/restaurants/${r.id}`} className="fav-card__media">
                      <img src={r.image} alt={r.name} onError={(e) => { e.currentTarget.style.display = 'none' }} />
                      <span aria-hidden="true">{r.fallback}</span>
                    </Link>
                    <button type="button" className="fav-card__heart" aria-label={`Remove ${r.name} from favorites`} onClick={() => toggleFavorite(r.id)}><HeartIcon filled /></button>
                    <div className="fav-card__body">
                      <div className="fav-card__row"><Link to={`/restaurants/${r.id}`}><h2>{r.name}</h2></Link><HeartIcon size={16} filled /></div>
                      <p className="fav-card__rating"><StarIcon size={14} /> <b>{r.rating.toFixed(1)}</b></p>
                      <p>{r.cuisines.slice(0, 2).join(' • ')}</p>
                      <p className="fav-card__meta"><span><PinIcon size={13} /> {r.distance.replace(' from route', '')}</span><span><ClockIcon size={13} /> {r.time.replace('min', '')}–{Number(r.time) + 5} mins</span></p>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      </main>
    </>
  )
}

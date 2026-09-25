import { useState } from 'react'
import { Link } from 'react-router-dom'
import Header from '../../components/Header'
import AccountSidebar from '../../components/AccountSidebar'
import { EmptyState, ErrorState, LoadingState } from '../../components/AccountStates'
import { ClockIcon, PinIcon, StarIcon } from '../../components/Icons'
import { useToast } from '../../components/Toast'
import { useAccount } from '../../account/AccountContext'
import { restaurantRepository } from '../../repositories'
import './AccountPage.css'
import './FavoritesPage.css'

const HeartIcon = ({ size = 18, filled = false }: { size?: number; filled?: boolean }) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill={filled ? 'currentColor' : 'none'} stroke="currentColor" strokeWidth="2" strokeLinejoin="round" aria-hidden="true"><path d="M12 21s-7.5-4.6-9.5-9.3C1 8 3.5 4.5 7 4.5c2 0 3.5 1 5 2.8 1.5-1.8 3-2.8 5-2.8 3.5 0 6 3.5 4.5 7.2C19.5 16.4 12 21 12 21Z" /></svg>
)

/** Mock opening state until restaurant hours exist (Restaurants module): one fixture is closed for the UI. */
const isOpenNow = (restaurantId: string) => restaurantId !== 'wok-express'

export default function FavoritesPage() {
  const { favorites, removeFavorite } = useAccount()
  const toast = useToast()
  const [removing, setRemoving] = useState<string | null>(null)
  const items = favorites.data.map((f) => restaurantRepository.byId(f.restaurantId)).filter((r) => !!r)

  const remove = async (id: string, name: string) => {
    setRemoving(id)
    try { await removeFavorite(id); toast.success(`${name} removed from favorites`) } catch (e) { toast.error(e instanceof Error ? e.message : 'Could not update favorites') } finally { setRemoving(null) }
  }

  return (
    <>
      <Header />
      <main id="main" className="ac">
        <div className="ac__grid">
          <AccountSidebar />
          <div className="ac__main">
            <div className="ac-head"><div><h1>Favorite Restaurants</h1><p>Your saved restaurants for quick access</p></div></div>

            {favorites.status === 'loading' || favorites.status === 'idle' ? <LoadingState label="Loading your favorites" /> : null}
            {favorites.status === 'error' && <ErrorState message={favorites.error ?? 'Failed to load favorites.'} onRetry={favorites.reload} />}
            {favorites.status === 'ready' && items.length === 0 && (
              <EmptyState icon={<HeartIcon size={34} />} title="No favorite restaurants yet." text="Tap the heart on any restaurant to save it here." action={<Link to="/restaurants" className="btn btn--primary">Explore Restaurants</Link>} />
            )}
            {favorites.status === 'ready' && items.length > 0 && (
              <ul className="fav-grid">
                {items.map((r) => {
                  const open = isOpenNow(r.id)
                  return (
                    <li key={r.id} className={`fav-card ${removing === r.id ? 'is-busy' : ''}`}>
                      <Link to={`/restaurants/${r.id}`} className="fav-card__media">
                        <img src={r.image} alt="" onError={(e) => { e.currentTarget.style.display = 'none' }} />
                        <span aria-hidden="true">{r.fallback}</span>
                        <span className={`fav-card__state ${open ? 'is-open' : 'is-closed'}`}>{open ? 'Open' : 'Closed'}</span>
                      </Link>
                      <button type="button" className="fav-card__heart" aria-label={`Remove ${r.name} from favorites`} disabled={removing === r.id} onClick={() => remove(r.id, r.name)}><HeartIcon filled /></button>
                      <div className="fav-card__body">
                        <div className="fav-card__row"><Link to={`/restaurants/${r.id}`}><h2>{r.name}</h2></Link><HeartIcon size={16} filled /></div>
                        <p className="fav-card__rating"><StarIcon size={14} /> <b>{r.rating.toFixed(1)}</b> <span>({r.reviews})</span></p>
                        <p>{r.cuisines.slice(0, 2).join(' • ')}</p>
                        <p className="fav-card__meta"><span><PinIcon size={13} /> {r.distance}</span><span><ClockIcon size={13} /> {r.detour} detour</span></p>
                        <div className="fav-card__actions">
                          <Link to={`/restaurants/${r.id}`} className="btn btn--primary">View Menu</Link>
                          <button type="button" className="ac-danger-btn" disabled={removing === r.id} onClick={() => remove(r.id, r.name)}>{removing === r.id ? 'Removing…' : 'Remove'}</button>
                        </div>
                      </div>
                    </li>
                  )
                })}
              </ul>
            )}
          </div>
        </div>
      </main>
    </>
  )
}

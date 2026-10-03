import { useState } from 'react'
import { Link } from 'react-router-dom'
import Header from '../../components/Header'
import AccountSidebar from '../../components/AccountSidebar'
import { EmptyState, ErrorState, LoadingState } from '../../components/AccountStates'
import { ClockIcon, PinIcon, StarIcon } from '../../components/Icons'
import { useToast } from '../../components/Toast'
import { useAccount, type Favorite } from '../../account/AccountContext'
import { restaurantRepository } from '../../repositories'
import { computeAvailability } from '../../repositories/mock/restaurants'
import type { Restaurant } from '../../repositories/types'
import './AccountPage.css'
import './FavoritesPage.css'

const HeartIcon = ({ size = 18, filled = false }: { size?: number; filled?: boolean }) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill={filled ? 'currentColor' : 'none'} stroke="currentColor" strokeWidth="2" strokeLinejoin="round" aria-hidden="true"><path d="M12 21s-7.5-4.6-9.5-9.3C1 8 3.5 4.5 7 4.5c2 0 3.5 1 5 2.8 1.5-1.8 3-2.8 5-2.8 3.5 0 6 3.5 4.5 7.2C19.5 16.4 12 21 12 21Z" /></svg>
)

/** A favorite with the restaurant it points to: carried by the backend in API mode, looked up in the development data otherwise. */
type Item = { favorite: Favorite; restaurant: Restaurant | null; name: string }

export default function FavoritesPage() {
  const { favorites, removeFavorite } = useAccount()
  const toast = useToast()
  const [removing, setRemoving] = useState<string | null>(null)
  const items: Item[] = favorites.data.map((f) => {
    const restaurant = f.available === false ? null : f.restaurant ?? restaurantRepository.byId(f.restaurantId) ?? null
    return { favorite: f, restaurant, name: restaurant?.name ?? f.name ?? '' }
  }).filter((i) => i.restaurant || i.name)

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
                {items.map(({ favorite, restaurant: r, name }) => {
                  const id = favorite.restaurantId
                  if (!r) {
                    // Kept, but the restaurant is not shown to customers right now (suspended, under review, market paused).
                    return (
                      <li key={id} className={`fav-card fav-card--off ${removing === id ? 'is-busy' : ''}`}>
                        <div className="fav-card__media" aria-hidden="true"><span>🍽️</span><span className="fav-card__state is-closed">Unavailable</span></div>
                        <div className="fav-card__body">
                          <div className="fav-card__row"><h2>{name}</h2><HeartIcon size={16} filled /></div>
                          <p className="fav-card__off">This restaurant is currently not available on FoodOnTheGo. Your favorite is kept in case it returns.</p>
                          <div className="fav-card__actions">
                            <button type="button" className="ac-danger-btn" disabled={removing === id} onClick={() => remove(id, name)}>{removing === id ? 'Removing…' : 'Remove'}</button>
                          </div>
                        </div>
                      </li>
                    )
                  }
                  const open = computeAvailability(r, new Date().toISOString()).status === 'open'
                  return (
                    <li key={id} className={`fav-card ${removing === id ? 'is-busy' : ''}`}>
                      <Link to={`/restaurants/${r.slug}`} className="fav-card__media">
                        {r.image && <img src={r.image} alt="" onError={(e) => { e.currentTarget.style.display = 'none' }} />}
                        <span aria-hidden="true">{r.fallback}</span>
                        <span className={`fav-card__state ${open ? 'is-open' : 'is-closed'}`}>{open ? 'Open' : 'Closed'}</span>
                      </Link>
                      <button type="button" className="fav-card__heart" aria-label={`Remove ${r.name} from favorites`} disabled={removing === id} onClick={() => remove(id, r.name)}><HeartIcon filled /></button>
                      <div className="fav-card__body">
                        <div className="fav-card__row"><Link to={`/restaurants/${r.slug}`}><h2>{r.name}</h2></Link><HeartIcon size={16} filled /></div>
                        {r.reviewCount > 0 && <p className="fav-card__rating"><StarIcon size={14} /> <b>{r.rating.toFixed(1)}</b> <span>({r.reviewCount}{r.ratingIsSample ? ', sample' : ''})</span></p>}
                        <p>{r.cuisines.slice(0, 2).join(' • ')}</p>
                        {(r.distance || r.detour) && <p className="fav-card__meta">{r.distance && <span><PinIcon size={13} /> {r.distance}</span>}{r.detour && <span><ClockIcon size={13} /> {r.detour} detour</span>}</p>}
                        <div className="fav-card__actions">
                          <Link to={`/restaurants/${r.slug}`} className="btn btn--primary">View Menu</Link>
                          <button type="button" className="ac-danger-btn" disabled={removing === id} onClick={() => remove(id, r.name)}>{removing === id ? 'Removing…' : 'Remove'}</button>
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

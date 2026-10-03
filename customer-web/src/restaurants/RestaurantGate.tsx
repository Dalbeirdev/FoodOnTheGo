import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { useLocation } from 'react-router-dom'
import { t } from '../i18n/strings'
import { hasRestaurantSnapshot, hydrateRestaurants, restaurantDataAge } from './api/restaurantData'
import { restaurantMode } from './restaurantMode'

const REFRESH_AFTER_SECONDS = 60

/** The dashboards have their own data (and their own sign-in): they do not wait for the customer restaurant list. */
const isPortal = (pathname: string) => pathname.startsWith('/restaurant-dashboard') || pathname.startsWith('/admin')

/**
 * In API mode the customer pages read restaurants from a snapshot of the backend (Module 23). The gate loads it once
 * before the first customer page renders; a snapshot cached for this tab renders immediately and is refreshed in the
 * background. When the tab comes back into view after a while it is refreshed again, so a restaurant that was paused,
 * suspended or approved in the meantime shows correctly. In mock mode it renders straight through.
 *
 * If the backend cannot be reached and nothing is cached, the app says so and offers a retry — it never falls back to
 * showing development restaurants as if they were real.
 */
export function RestaurantGate({ children }: { children: ReactNode }) {
  const { pathname } = useLocation()
  const needed = restaurantMode() === 'api' && !isPortal(pathname)
  const [failed, setFailed] = useState(false)
  // Re-render when a load finishes: the snapshot lives outside React.
  const [, setLoads] = useState(0)
  const load = useCallback(() => { setFailed(false); void hydrateRestaurants().then((ok) => { setFailed(!ok); setLoads((n) => n + 1) }) }, [])

  useEffect(() => {
    if (!needed) return
    if (!hasRestaurantSnapshot() || restaurantDataAge() > REFRESH_AFTER_SECONDS) load()
  }, [needed, load])
  const state: 'ready' | 'loading' | 'error' = !needed || hasRestaurantSnapshot() ? 'ready' : failed ? 'error' : 'loading'

  useEffect(() => {
    if (restaurantMode() !== 'api') return
    const onFocus = () => { if (document.visibilityState === 'visible' && hasRestaurantSnapshot() && restaurantDataAge() > REFRESH_AFTER_SECONDS) void hydrateRestaurants() }
    window.addEventListener('focus', onFocus); document.addEventListener('visibilitychange', onFocus)
    return () => { window.removeEventListener('focus', onFocus); document.removeEventListener('visibilitychange', onFocus) }
  }, [])

  if (state === 'ready') return <>{children}</>
  return (
    <main style={{ minHeight: '60vh', display: 'grid', placeItems: 'center', padding: 32, fontFamily: 'Outfit, system-ui, sans-serif', color: '#344054', textAlign: 'center' }} data-testid="restaurant-gate">
      {state === 'loading'
        ? <p role="status" aria-live="polite">{t('market.loading')}</p>
        : <div role="alert"><h1 style={{ fontSize: 20, marginBottom: 8 }}>{t('market.loadError.title')}</h1><p style={{ marginBottom: 16 }}>{t('market.loadError.text')}</p><button type="button" className="btn btn--primary" onClick={load} data-testid="restaurant-retry">{t('market.loadError.retry')}</button></div>}
    </main>
  )
}

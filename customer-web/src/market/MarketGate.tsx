import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { t } from '../i18n/strings'
import { apiMarketData, hydrateMarket, marketDataAge } from './api/marketData'
import { marketMode } from './marketMode'

const REFRESH_AFTER_SECONDS = 300
const REUSE_FOR_SECONDS = 60
const ATTEMPT_KEY = 'fotg.mkt.attempt'
const lastAttempt = () => { try { return Number(sessionStorage.getItem(ATTEMPT_KEY) ?? 0) } catch { return 0 } }

/**
 * In API mode the app needs the market it is served by (currency, locale, units, coverage) before the first screen
 * renders. The gate loads that snapshot from the backend once; a snapshot cached for this tab renders immediately
 * and is refreshed in the background once it is older than a minute. In mock mode it renders straight through.
 *
 * If the backend cannot be reached and nothing is cached, the app says so and offers a retry — it never falls back
 * to pretending a market is available.
 */
export function MarketGate({ children }: { children: ReactNode }) {
  const [state, setState] = useState<'ready' | 'loading' | 'error'>(() => (marketMode() !== 'api' || apiMarketData() ? 'ready' : 'loading'))
  const load = useCallback(() => { void hydrateMarket().then((ok) => setState(ok ? 'ready' : 'error')) }, [])
  // A snapshot taken moments ago (same tab, e.g. a reload or a hard navigation) is reused instead of fetched again.
  useEffect(() => {
    if (marketMode() !== 'api') return
    // With a snapshot in hand, ask at most once a minute — also when the previous request was cut short by a navigation.
    if (apiMarketData() && (marketDataAge() <= REUSE_FOR_SECONDS || Date.now() - lastAttempt() < REUSE_FOR_SECONDS * 1000)) return
    try { sessionStorage.setItem(ATTEMPT_KEY, String(Date.now())) } catch { /* storage unavailable */ }
    load()
  }, [load])
  // A tab left open picks up administrative changes when the customer comes back to it.
  useEffect(() => {
    if (marketMode() !== 'api') return
    const onFocus = () => { if (document.visibilityState === 'visible' && marketDataAge() > REFRESH_AFTER_SECONDS) void hydrateMarket() }
    window.addEventListener('focus', onFocus); document.addEventListener('visibilitychange', onFocus)
    return () => { window.removeEventListener('focus', onFocus); document.removeEventListener('visibilitychange', onFocus) }
  }, [])

  if (state === 'ready') return <>{children}</>
  return (
    <main style={{ minHeight: '60vh', display: 'grid', placeItems: 'center', padding: 32, fontFamily: 'Outfit, system-ui, sans-serif', color: '#344054', textAlign: 'center' }} data-testid="market-gate">
      {state === 'loading'
        ? <p role="status" aria-live="polite">{t('market.loading')}</p>
        : <div role="alert"><h1 style={{ fontSize: 20, marginBottom: 8 }}>{t('market.loadError.title')}</h1><p style={{ marginBottom: 16 }}>{t('market.loadError.text')}</p><button type="button" className="btn btn--primary" onClick={() => { setState('loading'); load() }} data-testid="market-retry">{t('market.loadError.retry')}</button></div>}
    </main>
  )
}

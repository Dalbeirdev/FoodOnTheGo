import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { fixtureScope } from '../market/fixtureScope'
import { marketAvailability, marketRepository } from '../market/mock/mockMarket'
import { LocaleContext, detectLocale, dirFor, type Locale } from './strings'

const PREF_KEY = 'fotg.pref.units'

/** Device locale is kept when its region is an active market; otherwise the active market's primary locale (en-IN for the India launch). */
export function marketLocale(detected: string): string {
  const region = detected.split(/[-_]/)[1]?.toUpperCase()
  if (region && marketAvailability.isCountrySupported(region)) return detected
  if (fixtureScope() === 'global') return detected
  return marketRepository.getActiveMarket().defaultLocale
}

/** Locale + direction + unit preference for the whole app. Translations arrive later; the plumbing exists now. */
export function LocaleProvider({ children, locale: forced }: { children: ReactNode; locale?: string }) {
  const locale = forced ?? marketLocale(detectLocale())
  const [unitPreference, setUnitPreferenceState] = useState<Locale['unitPreference']>(() => { try { return (localStorage.getItem(PREF_KEY) as Locale['unitPreference']) || 'auto' } catch { return 'auto' } })
  const dir = dirFor(locale)
  useEffect(() => { document.documentElement.lang = locale; document.documentElement.dir = dir }, [locale, dir])
  const setUnitPreference = (u: Locale['unitPreference']) => { setUnitPreferenceState(u); try { localStorage.setItem(PREF_KEY, u) } catch { /* ignore */ } }
  const value = useMemo(() => ({ locale, dir, unitPreference, setUnitPreference }), [locale, dir, unitPreference])
  return <LocaleContext.Provider value={value}>{children}</LocaleContext.Provider>
}

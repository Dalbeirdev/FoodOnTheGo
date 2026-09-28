import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { LocaleContext, detectLocale, dirFor, type Locale } from './strings'

const PREF_KEY = 'fotg.pref.units'

/** Locale + direction + unit preference for the whole app. Translations arrive later; the plumbing exists now. */
export function LocaleProvider({ children, locale: forced }: { children: ReactNode; locale?: string }) {
  const locale = forced ?? detectLocale()
  const [unitPreference, setUnitPreferenceState] = useState<Locale['unitPreference']>(() => { try { return (localStorage.getItem(PREF_KEY) as Locale['unitPreference']) || 'auto' } catch { return 'auto' } })
  const dir = dirFor(locale)
  useEffect(() => { document.documentElement.lang = locale; document.documentElement.dir = dir }, [locale, dir])
  const setUnitPreference = (u: Locale['unitPreference']) => { setUnitPreferenceState(u); try { localStorage.setItem(PREF_KEY, u) } catch { /* ignore */ } }
  const value = useMemo(() => ({ locale, dir, unitPreference, setUnitPreference }), [locale, dir, unitPreference])
  return <LocaleContext.Provider value={value}>{children}</LocaleContext.Provider>
}

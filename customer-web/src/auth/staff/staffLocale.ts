/**
 * Language of the staff screens (Restaurant Dashboard, Platform Admin and their sign-in pages).
 *
 *   signed in      the language the person chose for their account (Account security → Language); none = English
 *   not signed in  `?lang=` of the link that was opened (invitation / password reset), else the language last used
 *                  in this browser, else English
 *
 * A language is only used once its strings are loaded; until then, and for a language without a translation, the
 * screens are English. Dates and numbers keep the region of the market locale (hi + en-IN → hi-IN).
 */
import { useCallback, useEffect, useState } from 'react'
import { ensureBundle, hasBundle, t } from '../../i18n/strings'
import { useStaffSession } from './staffSessionContext'

const KEY = 'fotg.staff.lang'
const remembered = (): string | null => { try { return localStorage.getItem(KEY) } catch { return null } }
const fromLink = (): string | null => { try { return new URLSearchParams(window.location.search).get('lang') } catch { return null } }
/** Called with the signed-in person's choice, so the sign-in screen speaks their language next time. */
export function rememberStaffLang(lang: string | null): void { try { if (lang) localStorage.setItem(KEY, lang); else localStorage.removeItem(KEY) } catch { /* ignore */ } }

export function useStaffLang(): string {
  const session = useStaffSession()
  const chosen = session.mode === 'api' ? session.principal.preferredLocale ?? 'en' : fromLink() ?? remembered() ?? 'en'
  const wanted = /^[a-z]{2,3}$/.test(chosen.split(/[-_]/)[0]) ? chosen.split(/[-_]/)[0] : 'en'
  const [, loaded] = useState(0)
  useEffect(() => { let on = true; if (!hasBundle(wanted)) void ensureBundle(wanted).then(() => { if (on) loaded((n) => n + 1) }); return () => { on = false } }, [wanted])
  useEffect(() => { if (session.mode === 'api') rememberStaffLang(session.principal.preferredLocale) }, [session])
  return hasBundle(wanted) ? wanted : 'en'
}

/** `tr(key, params)` in the staff language, plus the language itself (for `lang` attributes and date formatting). */
export function useStaffStrings(): { tr: (key: string, params?: Record<string, string | number>) => string; lang: string } {
  const lang = useStaffLang()
  const tr = useCallback((key: string, params?: Record<string, string | number>) => t(key, params, lang), [lang])
  return { tr, lang }
}

/** The staff language with the region of the market locale: dates, numbers and money keep the market's conventions. */
export function staffUiLocale(lang: string, marketLocale: string): string {
  const [base, region] = marketLocale.split(/[-_]/)
  return lang === base ? marketLocale : region ? `${lang}-${region}` : lang
}

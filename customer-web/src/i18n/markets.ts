/**
 * Market configuration (Module 06) — the ONLY place where per-country defaults live.
 *
 * Reusable discovery code never hardcodes a country, currency, unit or locale; it asks this
 * table by ISO 3166-1 alpha-2 country code. In production this comes from configuration /
 * admin-managed market records (CF: MARKET CONFIGURATION = NOT STARTED). Unknown countries
 * fall back to the neutral defaults below.
 */
export type UnitSystem = 'metric' | 'imperial'

export type MarketConfig = {
  countryCode: string
  /** BCP 47 locale used for number / date formatting when the user has no preference. */
  locale: string
  /** ISO 4217 currency most restaurants in this market price in (a restaurant may override). */
  currency: string
  unitSystem: UnitSystem
  /** Default corridor half-width for route search in metres — configurable, never a universal constant. */
  corridorM: number
  /** Whether postal codes are commonly used and how they look (validation stays optional / soft). */
  postalCode: { used: boolean; label: string; example?: string }
  adminAreaLabel: string
}

const DEFAULT_MARKET: MarketConfig = { countryCode: 'ZZ', locale: 'en', currency: 'USD', unitSystem: 'metric', corridorM: 5000, postalCode: { used: false, label: 'Postal code' }, adminAreaLabel: 'Region' }

/** Development fixtures only — real markets are administered in the backend. */
const MARKETS: Record<string, Partial<MarketConfig>> = {
  IN: { locale: 'en-IN', currency: 'INR', unitSystem: 'metric', corridorM: 5000, postalCode: { used: true, label: 'PIN code', example: '201309' }, adminAreaLabel: 'State' },
  US: { locale: 'en-US', currency: 'USD', unitSystem: 'imperial', corridorM: 8000, postalCode: { used: true, label: 'ZIP code', example: '93239' }, adminAreaLabel: 'State' },
  GB: { locale: 'en-GB', currency: 'GBP', unitSystem: 'imperial', corridorM: 6000, postalCode: { used: true, label: 'Postcode', example: 'B5 6DY' }, adminAreaLabel: 'County' },
  JP: { locale: 'ja-JP', currency: 'JPY', unitSystem: 'metric', corridorM: 4000, postalCode: { used: true, label: '郵便番号', example: '420-0851' }, adminAreaLabel: 'Prefecture' },
  FR: { locale: 'fr-FR', currency: 'EUR', unitSystem: 'metric', corridorM: 6000, postalCode: { used: true, label: 'Code postal', example: '89000' }, adminAreaLabel: 'Département' },
  AE: { locale: 'ar-AE', currency: 'AED', unitSystem: 'metric', corridorM: 8000, postalCode: { used: false, label: 'Postal code' }, adminAreaLabel: 'Emirate' },
  IE: { locale: 'en-IE', currency: 'EUR', unitSystem: 'metric', corridorM: 6000, postalCode: { used: true, label: 'Eircode', example: 'D02 X285' }, adminAreaLabel: 'County' },
}

export function marketFor(countryCode: string | null | undefined): MarketConfig {
  const cc = (countryCode ?? '').toUpperCase()
  return { ...DEFAULT_MARKET, ...(MARKETS[cc] ?? {}), countryCode: cc || 'ZZ' }
}

export const knownMarkets = () => Object.keys(MARKETS)

/** Unit preference resolution: explicit user preference → market → metric. */
export function resolveUnitSystem(pref: UnitSystem | 'auto' | null | undefined, countryCode: string | null | undefined): UnitSystem {
  if (pref && pref !== 'auto') return pref
  return marketFor(countryCode).unitSystem
}

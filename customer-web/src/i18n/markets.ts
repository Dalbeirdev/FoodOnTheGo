/**
 * Market configuration (Module 06) — the ONLY place where per-country defaults live.
 *
 * Reusable discovery code never hardcodes a country, currency, unit or locale; it asks this
 * table by ISO 3166-1 alpha-2 country code. In production this comes from configuration /
 * admin-managed market records (CF: MARKET CONFIGURATION = NOT STARTED). Unknown countries
 * fall back to the neutral defaults below.
 */
import { unitChoiceAvailable } from '../market/mock/mockMarket'

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
  /** Radius of ring 0 ("near you") in metres. */
  scopeRadiusM: number
  /** Neighbouring regions used for ring 2 — admin-managed later; development table here. */
  regionAdjacency: Record<string, string[]>
}

const DEFAULT_MARKET: MarketConfig = { countryCode: 'ZZ', locale: 'en', currency: 'USD', unitSystem: 'metric', corridorM: 5000, postalCode: { used: false, label: 'Postal code' }, adminAreaLabel: 'Region', scopeRadiusM: 25_000, regionAdjacency: {} }

/** Development fixtures only — real markets are administered in the backend. */
const MARKETS: Record<string, Partial<MarketConfig>> = {
  IN: { locale: 'en-IN', currency: 'INR', unitSystem: 'metric', corridorM: 5000, postalCode: { used: true, label: 'PIN code', example: '201309' }, adminAreaLabel: 'State', scopeRadiusM: 25_000, regionAdjacency: {
    'Punjab': ['Haryana', 'Himachal Pradesh', 'Rajasthan', 'Chandigarh', 'Jammu and Kashmir'],
    'Haryana': ['Punjab', 'Delhi', 'Uttar Pradesh', 'Rajasthan', 'Himachal Pradesh', 'Chandigarh', 'Uttarakhand'],
    'Chandigarh': ['Punjab', 'Haryana'],
    'Delhi': ['Haryana', 'Uttar Pradesh'],
    'Uttar Pradesh': ['Delhi', 'Haryana', 'Rajasthan', 'Madhya Pradesh', 'Uttarakhand', 'Bihar', 'Himachal Pradesh'],
    'Rajasthan': ['Punjab', 'Haryana', 'Uttar Pradesh', 'Gujarat', 'Madhya Pradesh'],
    'Gujarat': ['Rajasthan', 'Maharashtra', 'Madhya Pradesh'],
    'Maharashtra': ['Gujarat', 'Madhya Pradesh', 'Karnataka', 'Goa', 'Telangana'],
    'Karnataka': ['Maharashtra', 'Goa', 'Kerala', 'Tamil Nadu', 'Telangana', 'Andhra Pradesh'],
    'Jammu and Kashmir': ['Punjab', 'Himachal Pradesh', 'Ladakh'],
  } },
  US: { locale: 'en-US', currency: 'USD', unitSystem: 'imperial', corridorM: 8000, postalCode: { used: true, label: 'ZIP code', example: '93239' }, adminAreaLabel: 'State', scopeRadiusM: 40_000, regionAdjacency: { CA: ['OR', 'NV', 'AZ'], NV: ['CA', 'OR', 'ID', 'UT', 'AZ'], OR: ['CA', 'WA', 'NV', 'ID'], AZ: ['CA', 'NV', 'UT', 'NM'] } },
  GB: { locale: 'en-GB', currency: 'GBP', unitSystem: 'imperial', corridorM: 6000, postalCode: { used: true, label: 'Postcode', example: 'B5 6DY' }, adminAreaLabel: 'County', scopeRadiusM: 25_000, regionAdjacency: { 'Northamptonshire': ['Warwickshire', 'Leicestershire', 'West Midlands', 'Buckinghamshire'], 'West Midlands': ['Staffordshire', 'Warwickshire', 'Worcestershire', 'Northamptonshire'], 'Staffordshire': ['West Midlands', 'Cheshire', 'Derbyshire', 'Shropshire'], 'Greater London': ['Hertfordshire', 'Essex', 'Kent', 'Surrey', 'Buckinghamshire'] } },
  JP: { locale: 'ja-JP', currency: 'JPY', unitSystem: 'metric', corridorM: 4000, postalCode: { used: true, label: '郵便番号', example: '420-0851' }, adminAreaLabel: 'Prefecture', scopeRadiusM: 20_000, regionAdjacency: { '静岡県': ['愛知県', '神奈川県', '山梨県', '長野県'], '愛知県': ['静岡県', '岐阜県', '三重県', '長野県'], '東京都': ['神奈川県', '埼玉県', '千葉県', '山梨県'], '大阪府': ['京都府', '兵庫県', '奈良県', '和歌山県'] } },
  FR: { locale: 'fr-FR', currency: 'EUR', unitSystem: 'metric', corridorM: 6000, postalCode: { used: true, label: 'Code postal', example: '89000' }, adminAreaLabel: 'Département', scopeRadiusM: 25_000, regionAdjacency: { 'Yonne': ['Côte-d\'Or', 'Nièvre', 'Aube', 'Seine-et-Marne', 'Loiret'], 'Côte-d\'Or': ['Yonne', 'Nièvre', 'Saône-et-Loire', 'Jura', 'Haute-Marne', 'Aube'], 'Paris': ['Hauts-de-Seine', 'Seine-Saint-Denis', 'Val-de-Marne'], 'Rhône': ['Ain', 'Isère', 'Loire', 'Saône-et-Loire'] } },
  AE: { locale: 'ar-AE', currency: 'AED', unitSystem: 'metric', corridorM: 8000, postalCode: { used: false, label: 'Postal code' }, adminAreaLabel: 'Emirate', scopeRadiusM: 30_000, regionAdjacency: { 'Dubai': ['Abu Dhabi', 'Sharjah'], 'Abu Dhabi': ['Dubai'], 'Sharjah': ['Dubai', 'Ajman', 'Umm Al Quwain'] } },
  IE: { locale: 'en-IE', currency: 'EUR', unitSystem: 'metric', corridorM: 6000, postalCode: { used: true, label: 'Eircode', example: 'D02 X285' }, adminAreaLabel: 'County', scopeRadiusM: 25_000, regionAdjacency: {} },
}

export function marketFor(countryCode: string | null | undefined): MarketConfig {
  const cc = (countryCode ?? '').toUpperCase()
  return { ...DEFAULT_MARKET, ...(MARKETS[cc] ?? {}), countryCode: cc || 'ZZ' }
}

export const knownMarkets = () => Object.keys(MARKETS)

/** Unit preference resolution: explicit user preference → market → metric. */
export function resolveUnitSystem(pref: UnitSystem | 'auto' | null | undefined, countryCode: string | null | undefined): UnitSystem {
  // A stored preference only applies while the launched markets offer a choice (India launch = metric only).
  if (pref && pref !== 'auto' && unitChoiceAvailable()) return pref
  return marketFor(countryCode).unitSystem
}

/** True when two regions of the same country are configured as neighbours (symmetric). */
export function regionsAdjacent(countryCode: string, a: string | undefined, b: string | undefined): boolean {
  if (!a || !b) return false
  const adj = marketFor(countryCode).regionAdjacency
  return (adj[a] ?? []).includes(b) || (adj[b] ?? []).includes(a)
}

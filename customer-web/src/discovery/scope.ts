/**
 * Discovery scope resolution (Module 06 addendum). Where the customer is looking from when no
 * journey is active. Priority:
 *   1. manual choice ("Change location") — persisted on this device
 *   2. real device location — REAL GEOLOCATION PERMISSION = PENDING INTEGRATION (CF-062), so skipped today
 *   3. the default saved address (Module 04)
 *   4. the origin of the most recent journey
 *   5. the browser locale's region as a country-only scope (labelled as such)
 *   6. nothing → the page asks the customer to set a location
 * Nothing here names a country; the country always comes from data or the locale.
 */
import type { Address } from '../account/repositories'
import type { Journey, Location } from '../journey/repositories'
import type { DiscoveryScope } from '../repositories/types'
import { fixtureScope } from '../market/fixtureScope'
import { marketAvailability, marketRepository } from '../market/mock/mockMarket'

const KEY = 'fotg.discovery.scope'

export function loadManualScope(): DiscoveryScope | null {
  try { const raw = localStorage.getItem(KEY); return raw ? (JSON.parse(raw) as DiscoveryScope) : null } catch { return null }
}
export function saveManualScope(scope: DiscoveryScope | null) {
  try { scope ? localStorage.setItem(KEY, JSON.stringify(scope)) : localStorage.removeItem(KEY) } catch { /* ignore */ }
}

export function scopeFromLocation(l: Location, source: DiscoveryScope['source'] = 'manual'): DiscoveryScope | null {
  if (!l.countryCode) return null
  return { countryCode: l.countryCode, adminArea: l.adminArea, locality: l.locality ?? l.name, lat: l.lat, lng: l.lng, label: l.name, source }
}

export function scopeFromAddress(a: Address, countryCode: string): DiscoveryScope {
  return { countryCode, adminArea: a.state, locality: a.city, lat: a.lat, lng: a.lng, label: `${a.label} · ${a.city}`, source: 'saved-address' }
}

export function scopeFromLocale(locale: string): DiscoveryScope | null {
  const region = locale.split(/[-_]/)[1]
  if (!region || !/^[A-Za-z]{2}$/.test(region)) return null
  let name = region.toUpperCase()
  try { name = new Intl.DisplayNames([locale], { type: 'region' }).of(region.toUpperCase()) ?? name } catch { /* keep code */ }
  return { countryCode: region.toUpperCase(), lat: null, lng: null, label: name, source: 'locale' }
}

export function resolveScope(input: { manual: DiscoveryScope | null; addresses: Address[]; addressCountry: string | null; recentJourneys: Journey[]; locale: string }): DiscoveryScope | null {
  if (input.manual) return input.manual
  const def = input.addresses.find((a) => a.isDefault) ?? input.addresses[0]
  if (def && input.addressCountry) return scopeFromAddress(def, input.addressCountry)
  const j = input.recentJourneys[0]
  if (j) { const s = scopeFromLocation(j.origin, 'journey'); if (s) return s }
  // Locale is only a hint: a device set to another country's locale still resolves to the active market (India launch).
  const fromLocale = scopeFromLocale(input.locale)
  if (fixtureScope() === 'global') return fromLocale // controlled global test fixtures keep the locale-only behaviour
  if (fromLocale && marketAvailability.isCountrySupported(fromLocale.countryCode) && fromLocale.countryCode !== marketRepository.getActiveMarket().countryCode) return fromLocale
  return activeMarketScope(input.locale)
}

export function activeMarketScope(locale: string): DiscoveryScope {
  const m = marketRepository.getActiveMarket(); let name = m.displayName
  try { name = new Intl.DisplayNames([locale], { type: 'region' }).of(m.countryCode) ?? name } catch { /* keep */ }
  return { countryCode: m.countryCode, lat: null, lng: null, label: name, source: 'market' }
}

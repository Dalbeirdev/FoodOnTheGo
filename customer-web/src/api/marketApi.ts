/**
 * First Api* adapter (Module 20): the market configuration served by the backend.
 *
 * It shows the mock → API migration pattern: the wire format (snake_case DTO) stays in this file and is mapped
 * onto the domain type the pages already use, so swapping MockMarketRepository for an API-backed one changes
 * no page. It is NOT wired into the app yet — the mock remains the source until the Market / Geo backend module.
 */
import type { DistanceUnit, Market, MarketStatus } from '../market/types'
import { api } from './client'

export type MarketDto = {
  id: string
  slug: string
  country_code: string
  name: string
  status: MarketStatus
  default_currency: string
  supported_currencies: string[]
  default_locale: string
  supported_locales: string[]
  timezone_strategy: 'single' | 'per-location'
  default_timezone: string
  distance_unit: DistanceUnit
  phone_country_code: string
  features: Record<string, boolean>
  launched_at: string | null
}
export type ClientConfigDto = { api: { version: string }; app: { name: string; version: string }; market: MarketDto }

export type ApiMarket = Pick<Market, 'id' | 'slug' | 'countryCode' | 'displayName' | 'status' | 'defaultCurrency' | 'supportedCurrencies' | 'defaultLocale' | 'supportedLocales' | 'timezoneStrategy' | 'defaultTimezone' | 'distanceUnit' | 'phoneCountryCode' | 'launchedAt'> & { features: Record<string, boolean> }

export const toMarket = (dto: MarketDto): ApiMarket => ({
  id: dto.id, slug: dto.slug, countryCode: dto.country_code, displayName: dto.name, status: dto.status,
  defaultCurrency: dto.default_currency, supportedCurrencies: dto.supported_currencies,
  defaultLocale: dto.default_locale, supportedLocales: dto.supported_locales,
  timezoneStrategy: dto.timezone_strategy, defaultTimezone: dto.default_timezone,
  distanceUnit: dto.distance_unit, phoneCountryCode: dto.phone_country_code, launchedAt: dto.launched_at, features: dto.features,
})

export const marketApi = {
  /** The market serving this client; throws ApiError code "market_unavailable" (404) when the country is not served. */
  async current(countryCode?: string): Promise<ApiMarket> {
    return toMarket(await api<MarketDto>('/markets/current', { auth: false, query: { country: countryCode } }))
  },
  async clientConfig(countryCode?: string): Promise<{ apiVersion: string; appVersion: string; market: ApiMarket }> {
    const dto = await api<ClientConfigDto>('/config', { auth: false, query: { country: countryCode } })
    return { apiVersion: dto.api.version, appVersion: dto.app.version, market: toMarket(dto.market) }
  },
}

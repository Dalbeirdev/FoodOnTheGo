/**
 * Market control center against the backend (Module 22).
 *
 * Geography — markets, states, cities, service areas, route corridors, configuration, feature flags — is read from
 * and changed through the admin API; the backend enforces permission, market scope, status transitions, the reason,
 * optimistic concurrency and writes the audit event. A refused change surfaces as an ApiError with the backend's
 * message.
 *
 * Restaurant pins and the order / revenue figures in the snapshot still come from the development fixtures: those
 * modules have no backend yet.
 */
import { api } from '../../api/client'
import { loadAdminMarketData, marketDataVersion, type MarketData } from '../../market/api/marketData'
import type { CityStatus, MarketFeatureKey, MarketStatus, RegionStatus, RouteStatus, ServiceAreaStatus } from '../../market/types'
import { MockAdminMarketControlRepository } from '../mock/mockAdmin'
import type { AdminMarketControlRepository, CityInput, MarketsOverview, MarketSnapshot, ServiceAreaInput } from '../types'

export class ApiAdminMarketControlRepository extends MockAdminMarketControlRepository implements AdminMarketControlRepository {
  private data: MarketData | null = null
  private async load(geography: string[] = []) { this.data = await loadAdminMarketData(geography); return this.data }

  /** The overview draws the market this deployment serves; a market page loads its own geography. */
  async overview(): Promise<MarketsOverview> { const d = this.data ?? await this.load(); await this.load([d.activeCode]); return this.build0() }
  async snapshot(slug: string): Promise<MarketSnapshot | null> {
    const code = (this.data ?? await this.load()).markets.find((m) => m.slug === slug)?.countryCode
    if (!code) return null
    await this.load([code]); return this.build(code)
  }
  private build0(): MarketsOverview { const markets = this.data!.markets; return { markets, active: this.build(this.data!.activeCode)!, activeMarkets: markets.filter((m) => m.status === 'ACTIVE').length, futureMarkets: markets.filter((m) => m.status === 'DRAFT').length } }

  /** Sends the version the administrator was looking at, so a change made by someone else in between is refused (409). */
  private async patch(path: string, id: string, body: Record<string, unknown>, reason: string) {
    await api(path, { method: 'PATCH', context: 'admin', body: { version: marketDataVersion(id), reason: reason.trim(), ...body } })
  }
  private async marketId(code: string) { const m = (this.data ?? await this.load()).markets.find((x) => x.countryCode === code); if (!m) throw new Error('not_found'); return m.id }

  async setMarketStatus(code: string, status: MarketStatus, _actor: string, reason: string) { const id = await this.marketId(code); await this.patch(`/admin/markets/${id}`, id, { status }, reason) }
  async setStateStatus(id: string, status: RegionStatus, _actor: string, reason: string) { await this.patch(`/admin/regions/${id}`, id, { status: status === 'AVAILABLE' ? 'ACTIVE' : status }, reason) }
  async setCityStatus(id: string, status: CityStatus, _actor: string, reason: string) { await this.patch(`/admin/cities/${id}`, id, { status }, reason) }
  async setServiceAreaStatus(id: string, status: ServiceAreaStatus, _actor: string, reason: string) { await this.patch(`/admin/service-areas/${id}`, id, { status }, reason) }
  async setRouteStatus(id: string, status: RouteStatus, _actor: string, reason: string) { await this.patch(`/admin/route-corridors/${id}`, id, { status }, reason) }
  async createCity(marketCode: string, input: CityInput) {
    await api(`/admin/markets/${await this.marketId(marketCode)}/cities`, { method: 'POST', context: 'admin', body: { region_id: input.regionId, name: input.name, latitude: input.lat, longitude: input.lng, timezone: input.timezone, aliases: input.aliases, launch_stage: input.launchStage } })
  }
  async updateCity(id: string, input: CityInput, reason: string) {
    await this.patch(`/admin/cities/${id}`, id, { name: input.name, latitude: input.lat, longitude: input.lng, timezone: input.timezone, aliases: input.aliases, launch_stage: input.launchStage }, reason)
  }
  async createServiceArea(marketCode: string, input: ServiceAreaInput) {
    await api(`/admin/markets/${await this.marketId(marketCode)}/service-areas`, { method: 'POST', context: 'admin', body: { city_id: input.cityId, name: input.name, priority: input.priority, launch_stage: input.launchStage, geometry: input.geometry } })
  }
  /** The boundary is sent only when it is being replaced. */
  async updateServiceArea(id: string, input: ServiceAreaInput, reason: string) {
    await this.patch(`/admin/service-areas/${id}`, id, { name: input.name, priority: input.priority, launch_stage: input.launchStage, ...(input.geometry ? { geometry: input.geometry } : {}) }, reason)
  }
  async setFeature(code: string, key: MarketFeatureKey, enabled: boolean, _actor: string, reason: string) { const id = await this.marketId(code); await this.patch(`/admin/markets/${id}/features`, id, { features: { [key]: enabled } }, reason) }
}

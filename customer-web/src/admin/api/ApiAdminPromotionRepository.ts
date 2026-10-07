/**
 * Promotions against the backend (Module 29).
 *
 * The backend stores promotions, decides what they are right now (SCHEDULED and EXPIRED follow from its clock),
 * enforces who may change them, refuses to rewrite a promotion customers are using, needs a reason for every change
 * and writes the audit event. This repository only maps the admin screen's model to the API and back.
 *
 * Amounts are integer minor units and a percentage is sent as integer basis points (10 % = 1000) — the screen's
 * percentage figure is converted once, here, and nothing in the browser ever calculates a discount.
 */
import { api, ApiError } from '../../api/client'
import { marketRepository } from '../../market/mock/mockMarket'
import type { AdminPromotionRepository, Page, Promotion, PromotionIssue, PromotionStatus } from '../types'

type Named = { id: string; name: string } | null
export type PromotionDto = {
  id: string; code: string; name: string; description: string | null; type: 'FIXED_AMOUNT' | 'PERCENTAGE'
  status: 'DRAFT' | 'ACTIVE' | 'PAUSED' | 'DISABLED'; effective_status: PromotionStatus
  scope: 'PLATFORM' | 'MARKET' | 'RESTAURANT_ORGANIZATION' | 'RESTAURANT_LOCATION'
  market: (Named & { country_code: string }) | null; organization: Named; location: Named
  currency: string | null; amount_minor: number | null; percent_basis_points: number | null; maximum_discount_minor: number | null; minimum_spend_minor: number | null
  starts_at: string | null; ends_at: string | null; usage_limit: number | null; per_customer_limit: number | null; redeemed_count: number; held_count: number
  funding_source: string; stacking: 'NOT_APPROVED'; version: number; created_at: string | null; updated_at: string | null
}
type ListDto = { data: PromotionDto[]; meta: { total: number; current_page: number; per_page: number } }

const A = { context: 'admin' as const }
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i
const FIELDS: Record<string, string> = { code: 'code', name: 'name', amount_minor: 'value', percent_basis_points: 'value', currency: 'currency', ends_at: 'dates', starts_at: 'dates', market_id: 'marketCode', location_id: 'restaurantId', usage_limit: 'usageLimit', minimum_spend_minor: 'minimumSpendMinor' }

export function toPromotion(d: PromotionDto): Promotion {
  return {
    id: d.id, code: d.code, name: d.name, description: d.description ?? '',
    type: d.type === 'FIXED_AMOUNT' ? 'fixed' : 'percentage',
    // The screen shows a percentage as a figure (10) and a fixed amount in minor units — as the backend stores it.
    value: d.type === 'FIXED_AMOUNT' ? d.amount_minor ?? 0 : (d.percent_basis_points ?? 0) / 100,
    currency: d.currency, startsAt: d.starts_at ?? '', endsAt: d.ends_at ?? '',
    minimumSpendMinor: d.minimum_spend_minor, maximumDiscountMinor: d.maximum_discount_minor, usageLimit: d.usage_limit, perCustomerLimit: d.per_customer_limit,
    scope: d.scope === 'PLATFORM' ? 'platform' : d.scope === 'MARKET' ? 'market' : 'restaurant',
    marketCode: d.market?.country_code ?? null, restaurantId: d.location?.id ?? null, restaurantName: d.location?.name ?? d.organization?.name ?? null,
    // What it IS right now, by the server clock.
    status: d.effective_status, usedCount: d.redeemed_count, heldCount: d.held_count, createdAt: d.created_at ?? '',
  }
}

/** A refused change in the screen's terms: field issues where the backend named a field, and always its message. */
function refused(e: unknown): Error & { issues?: PromotionIssue[] } {
  if (!(e instanceof ApiError)) return e instanceof Error ? e : new Error(String(e))
  const issues: PromotionIssue[] = Object.keys(e.errors).filter((f) => FIELDS[f]).map((f) => ({ field: FIELDS[f], code: f === 'ends_at' || f === 'starts_at' ? 'dates' : f === 'currency' ? 'currency' : 'invalid' }))
  if (e.code === 'promotion_code_taken') issues.push({ field: 'code', code: 'invalid' })
  return Object.assign(new Error(e.field(Object.keys(e.errors)[0] ?? '') ?? e.message), { issues })
}

export class ApiAdminPromotionRepository implements AdminPromotionRepository {
  /** The version of every promotion as it was last shown: a change names the version it was made on. */
  private versions = new Map<string, number>()
  private stored = new Map<string, PromotionDto['status']>()
  private remember(d: PromotionDto) { this.versions.set(d.id, d.version); this.stored.set(d.id, d.status); return toPromotion(d) }

  async list(f: { query?: string; status?: PromotionStatus | 'all'; page?: number; pageSize?: number }): Promise<Page<Promotion>> {
    const res = await api<ListDto>('/admin/promotions', { ...A, query: { q: f.query?.trim() || undefined, 'filter[status]': f.status && f.status !== 'all' ? f.status : undefined, 'page[number]': f.page ?? 1, 'page[size]': f.pageSize ?? 10, sort: '-created_at' } })
    return { items: res.data.map((d) => this.remember(d)), total: res.meta.total, page: res.meta.current_page, pageSize: res.meta.per_page }
  }

  async save(p: Omit<Promotion, 'id' | 'usedCount' | 'createdAt'> & { id?: string }, _actor: string, reason?: string): Promise<Promotion> {
    try {
      const scope = p.scope === 'platform' ? 'PLATFORM' : p.scope === 'market' ? 'MARKET' : 'RESTAURANT_LOCATION'
      const amounts = p.type === 'fixed' || p.minimumSpendMinor !== null || p.maximumDiscountMinor !== null
      const body: Record<string, unknown> = {
        code: p.code, name: p.name, description: p.description.trim() || null, type: p.type === 'fixed' ? 'FIXED_AMOUNT' : 'PERCENTAGE', scope,
        market_id: scope === 'MARKET' ? await this.marketId(p.marketCode) : null, location_id: scope === 'RESTAURANT_LOCATION' ? await this.locationId(p.restaurantId) : null,
        // An amount needs its currency; a plain percentage has none and applies to any.
        currency: amounts ? p.currency ?? marketRepository.getActiveMarket().defaultCurrency : null,
        amount_minor: p.type === 'fixed' ? Math.round(p.value) : null, percent_basis_points: p.type === 'percentage' ? Math.round(p.value * 100) : null,
        maximum_discount_minor: p.type === 'percentage' ? p.maximumDiscountMinor : null, minimum_spend_minor: p.minimumSpendMinor,
        starts_at: p.startsAt || null, ends_at: p.endsAt || null, usage_limit: p.usageLimit, per_customer_limit: p.perCustomerLimit,
      }
      if (p.id) {
        const d = (await api<{ promotion: PromotionDto }>(`/admin/promotions/${p.id}`, { ...A, method: 'PATCH', body: { ...body, version: this.versions.get(p.id) ?? 1, reason: (reason ?? '').trim() } })).promotion
        return this.remember(d)
      }
      let d = (await api<{ promotion: PromotionDto }>('/admin/promotions', { ...A, method: 'POST', body: { ...body, ...(reason?.trim() ? { reason: reason.trim() } : {}) } })).promotion
      // A promotion is always created as a draft; "active" on the form is the second step, with its own audit entry.
      if (p.status === 'ACTIVE' || p.status === 'SCHEDULED') d = (await api<{ promotion: PromotionDto }>(`/admin/promotions/${d.id}/status`, { ...A, method: 'POST', body: { status: 'ACTIVE', version: d.version, reason: reason?.trim() || 'Activated when it was created' } })).promotion
      return this.remember(d)
    } catch (e) { throw refused(e) }
  }

  async setStatus(id: string, status: PromotionStatus, _actor: string, reason: string): Promise<Promotion> {
    // SCHEDULED and EXPIRED are not states somebody sets: they follow from the dates.
    const target = status === 'SCHEDULED' ? 'ACTIVE' : status === 'EXPIRED' ? 'DISABLED' : status
    try {
      return this.remember((await api<{ promotion: PromotionDto }>(`/admin/promotions/${id}/status`, { ...A, method: 'POST', body: { status: target, version: this.versions.get(id) ?? 1, reason: reason.trim() } })).promotion)
    } catch (e) { throw refused(e) }
  }

  private async marketId(code: string | null): Promise<string | null> {
    if (!code) return null
    const list = await api<{ data: Array<{ id: string; country_code: string }> }>('/admin/markets', A)
    return list.data.find((m) => m.country_code === code)?.id ?? null
  }

  /** The form takes a restaurant's id or its slug. */
  private async locationId(value: string | null): Promise<string | null> {
    const v = (value ?? '').trim()
    if (v === '' || UUID.test(v)) return v || null
    const list = await api<{ data: Array<{ id: string; slug: string }> }>('/admin/restaurants', { ...A, query: { q: v, 'page[size]': 50 } })
    return list.data.find((r) => r.slug === v)?.id ?? v
  }
}

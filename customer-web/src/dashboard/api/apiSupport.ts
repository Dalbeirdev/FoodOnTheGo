/**
 * A restaurant's support cases on the backend (Module 37). A case belongs to the RESTAURANT (a location, or the
 * organization as a whole), not to the person who opened it: every member with restaurant.support.view there reads
 * it, and members with restaurant.support.create write in it. Cases about money (settlements, payouts) additionally
 * need restaurant.finance.view. The backend decides all of that — and what may be done next (`can`).
 *
 *   GET  /restaurant/support/catalog                 categories, limits, the locations / organizations a case may be opened for, can.create
 *   GET  /restaurant/support/cases                   the restaurant's cases (state, location, page) + the unread count
 *   POST /restaurant/support/cases                   open a case (Idempotency-Key; location or organization; order / settlement / payout)
 *   GET  /restaurant/support/cases/{case}            one case by its id or its case number
 *   GET  /restaurant/support/cases/{case}/messages   the conversation, oldest first
 *   POST /restaurant/support/cases/{case}/messages   write in it (client_message_id)
 *   POST /restaurant/support/cases/{case}/close      confirm a resolved case / withdraw an open one
 *   POST /restaurant/support/cases/{case}/reopen     reopen with a message
 *
 * Never sent to a restaurant, and so never shown: internal notes, the priority, the queue, the assignee, response
 * targets, a customer's name or contact, payments and disputes.
 */
import { api } from '../../api/client'
import {
  ApiSupportCases, compact, toSupportCase, toSupportCasePage, toSupportCatalog,
  type SupportCaseDto, type SupportCaseSummaryDto, type SupportCatalogDto, type SupportPageDto,
} from '../../support/api/apiSupport'
import type { NewRestaurantSupportCase, RestaurantSupportCatalog, RestaurantSupportListQuery, RestaurantSupportRepository, SupportCase, SupportCasePage } from '../../support/repositories'

export type RestaurantSupportCatalogDto = SupportCatalogDto & {
  locations?: Array<{ id: string; name: string; finance: boolean }>; organizations?: Array<{ id: string; name: string }>; can?: { create: boolean }
}
export const toRestaurantSupportCatalog = (d: RestaurantSupportCatalogDto): RestaurantSupportCatalog => ({
  ...toSupportCatalog(d),
  locations: (d.locations ?? []).map((l) => ({ id: l.id, name: l.name, finance: l.finance === true })),
  organizations: (d.organizations ?? []).map((o) => ({ id: o.id, name: o.name })),
  canCreate: d.can?.create === true,
})
const all = (v?: string | null) => (v && v !== 'all' ? v : undefined)

export class ApiRestaurantSupportRepository extends ApiSupportCases implements RestaurantSupportRepository {
  constructor() { super('/restaurant/support', 'restaurant') }

  catalog(): Promise<RestaurantSupportCatalog> {
    return this.call(async () => toRestaurantSupportCatalog(await api<RestaurantSupportCatalogDto>(`${this.base}/catalog`, this.ctx)))
  }
  /** Without a location the answer covers every location (and organization) the member may read. */
  list(query: RestaurantSupportListQuery = {}): Promise<SupportCasePage> {
    return this.call(async () => toSupportCasePage(await api<SupportPageDto<SupportCaseSummaryDto>>(`${this.base}/cases`, { ...this.ctx, query: { state: query.state ?? 'all', location: all(query.location), page: query.page ?? 1, per_page: query.pageSize } })))
  }
  /** A case is opened for ONE location, or for the organization as a whole — never both, and never a restaurant the body merely names: the backend checks the membership. */
  create(input: NewRestaurantSupportCase, idempotencyKey: string): Promise<SupportCase> {
    return this.call(async () => {
      const body = compact({
        ...(input.location ? { location: input.location } : { organization: input.organization }),
        category: input.category, subcategory: input.subcategory, subject: input.subject, message: input.message, order: input.order, settlement: input.settlement, payout: input.payout,
      })
      return toSupportCase((await api<{ case: SupportCaseDto }>(`${this.base}/cases`, { ...this.ctx, method: 'POST', body, idempotencyKey })).case)
    })
  }
}

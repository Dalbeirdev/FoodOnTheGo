/**
 * Support cases on the backend (Module 37). This file only speaks the API and maps it to the contracts the screens
 * use — it decides nothing: the status, what may be done next and what is read about a linked record are the backend's.
 *
 *   GET  /customer/support/catalog                 what can be asked about, and the limits of what can be written
 *   GET  /customer/support/cases                   my cases (state = open | closed | all, page, per_page) + the unread count
 *   POST /customer/support/cases                   open a case (Idempotency-Key; my own order / payment / refund in the body)
 *   GET  /customer/support/cases/{case}            one case by its id or its case number (opening it marks it read)
 *   GET  /customer/support/cases/{case}/messages   the conversation, oldest first, a page at a time
 *   POST /customer/support/cases/{case}/messages   write in it (client_message_id: a retry writes nothing twice)
 *   POST /customer/support/cases/{case}/close      confirm a resolved case, or withdraw one that is being worked on
 *   POST /customer/support/cases/{case}/reopen     reopen with a message, while the backend allows it
 *
 * The Restaurant Dashboard uses the same shape under /restaurant/support (dashboard/api/apiSupport.ts).
 */
import { api, ApiError, type ApiErrorKind, type AuthContextName } from '../../api/client'
import type {
  NewSupportCase, SupportAuthor, SupportCase, SupportCasePage, SupportCaseRepository, SupportCaseSummary, SupportCatalog, SupportLink, SupportListQuery, SupportMessage, SupportMessagePage,
  SupportMoney, SupportRepository, SupportStatus, SupportCloseIntent,
} from '../repositories'

type MoneyDto = { amount: number; currency: string }
export type SupportLinkDto = {
  id: string; type: string; reference: string; record_id: string; status?: string | null
  order_number?: string | null; restaurant?: string | null; placed_at?: string | null; refund_status?: string | null; period_start?: string | null; period_end?: string | null
  total?: MoneyDto | null; amount?: MoneyDto | null; net_payable?: MoneyDto | null
}
export type SupportCaseSummaryDto = {
  id: string; case_number: string; category: string; subcategory: string | null; subject: string; status: string; unread: boolean; message_count: number
  location: { id: string; name: string } | null; created_at: string | null; last_message_at: string | null; resolved_at: string | null; closed_at: string | null
}
export type SupportCaseDto = SupportCaseSummaryDto & { links: SupportLinkDto[]; can: { reply: boolean; close: boolean; withdraw: boolean; reopen: boolean }; reopen_until: string | null }
export type SupportMessageDto = { id: string; author: string; author_name: string | null; body: string; created_at: string | null }
export type SupportPageDto<T> = { data: T[]; meta: { current_page: number; last_page: number; total: number; per_page: number }; unread?: number }
export type SupportCatalogDto = {
  categories: Array<{ code: string; subcategories: string[]; needs_finance_permission?: boolean }>; link_types: string[]
  limits: { subject_max: number; message_max: number }
}

const money = (m: MoneyDto | null | undefined): SupportMoney | null => (m && typeof m.amount === 'number' && typeof m.currency === 'string' ? { amount: m.amount, currency: m.currency } : null)

/** One linked record. Each kind names its amount differently; the screen reads one field. Nothing is derived. */
export const toSupportLink = (d: SupportLinkDto): SupportLink => ({
  id: d.id, type: d.type, reference: d.reference, recordId: d.record_id, status: d.status ?? null,
  amount: money(d.total) ?? money(d.amount) ?? money(d.net_payable),
  orderNumber: d.order_number ?? (d.type === 'ORDER' ? d.reference : null), restaurant: d.restaurant ? d.restaurant : null, placedAt: d.placed_at ?? null, refundStatus: d.refund_status ?? null,
  periodStart: d.period_start ?? null, periodEnd: d.period_end ?? null,
})
export const toSupportSummary = (d: SupportCaseSummaryDto): SupportCaseSummary => ({
  id: d.id, caseNumber: d.case_number, category: d.category, subcategory: d.subcategory ?? null, subject: d.subject, status: d.status as SupportStatus, unread: d.unread === true, messageCount: d.message_count ?? 0,
  location: d.location ? { id: d.location.id, name: d.location.name } : null, createdAt: d.created_at ?? null, lastMessageAt: d.last_message_at ?? null, resolvedAt: d.resolved_at ?? null, closedAt: d.closed_at ?? null,
})
export const toSupportCase = (d: SupportCaseDto): SupportCase => ({
  ...toSupportSummary(d), links: (d.links ?? []).map(toSupportLink),
  // Only an explicit `true` of the backend offers an action.
  can: { reply: d.can?.reply === true, close: d.can?.close === true, withdraw: d.can?.withdraw === true, reopen: d.can?.reopen === true },
  reopenUntil: d.reopen_until ?? null,
})
const AUTHORS: SupportAuthor[] = ['YOU', 'SUPPORT', 'SYSTEM']
export const toSupportMessage = (d: SupportMessageDto): SupportMessage => ({
  id: d.id, author: AUTHORS.includes(d.author as SupportAuthor) ? (d.author as SupportAuthor) : 'SUPPORT', authorName: d.author_name ?? null, body: d.body ?? '', createdAt: d.created_at ?? null,
})
export const toSupportCasePage = (d: SupportPageDto<SupportCaseSummaryDto>): SupportCasePage => ({ items: d.data.map(toSupportSummary), total: d.meta.total, page: d.meta.current_page, lastPage: d.meta.last_page, unread: d.unread ?? 0 })
export const toSupportCatalog = (d: SupportCatalogDto): SupportCatalog => ({
  categories: d.categories.map((c) => ({ code: c.code, subcategories: [...(c.subcategories ?? [])], ...(c.needs_finance_permission !== undefined ? { needsFinance: c.needs_finance_permission } : {}) })),
  linkTypes: [...(d.link_types ?? [])], limits: { subjectMax: d.limits.subject_max, messageMax: d.limits.message_max },
})

/**
 * A failed support call. `message` is the backend's own sentence for a refusal it explains (not found, conflict,
 * validation, too many); otherwise the screen says its own. `fields` are the backend's field errors (422).
 * `unknownOutcome` is true when nobody can say whether the request arrived (no answer, a server fault, a first attempt
 * still running): only then is the same Idempotency-Key / client_message_id sent again.
 */
const EXPLAINED: ApiErrorKind[] = ['conflict', 'validation', 'rate_limited', 'not_found', 'network', 'unauthenticated']
export class SupportError extends Error {
  readonly code: string
  readonly status: number
  readonly kind: ApiErrorKind | 'unknown'
  readonly fields: Record<string, string[]>
  readonly unknownOutcome: boolean
  constructor(e: unknown) {
    const apiError = e instanceof ApiError ? e : null
    super(apiError !== null && EXPLAINED.includes(apiError.kind) ? apiError.message : 'support_request_failed')
    this.name = 'SupportError'
    this.code = apiError?.code ?? 'unknown'
    this.status = apiError?.status ?? 0
    this.kind = apiError?.kind ?? 'unknown'
    this.fields = apiError?.errors ?? {}
    this.unknownOutcome = apiError === null || apiError.kind === 'network' || apiError.kind === 'server' || apiError.code === 'idempotency_in_progress'
  }
  /** True when the message may be shown as it is. */
  get presentable(): boolean { return this.message !== 'support_request_failed' }
  field(name: string): string | undefined { return this.fields[name]?.[0] }
}

/** A key for one composed request: letters, digits, - (the backend takes 16–120 for a header, 8–64 for a message id). */
export const newSupportKey = (prefix: string): string =>
  `${prefix}-${typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}${Math.random().toString(36).slice(2, 12)}`}`
/** The message id as the backend accepts it (letters, digits, - and _; 8–64). */
export const supportMessageId = (id: string): string => {
  const clean = id.replace(/[^A-Za-z0-9_-]/g, '')
  return (clean.length >= 8 ? clean : `msg-${clean}-${Date.now().toString(36)}0000`).slice(0, 64)
}
/**
 * The Idempotency-Key of a composed request. The unchanged request keeps its key while nobody can say whether it
 * arrived, so a retry never opens a second case; a changed request — or one the backend definitely refused (a stored
 * refusal would be replayed for that key) — gets a new one.
 */
export class SupportAttempt {
  private last: { fingerprint: string; key: string } | null = null
  keyFor(fingerprint: string): string {
    if (!this.last || this.last.fingerprint !== fingerprint) this.last = { fingerprint, key: newSupportKey('sup') }
    return this.last.key
  }
  /** The outcome is known (created, or refused): the next request is a new one. */
  settle(): void { this.last = null }
}
const filled = (v: string | null | undefined): string | undefined => (typeof v === 'string' && v.trim() !== '' ? v.trim() : undefined)
/** Only what was filled in travels: an empty reference or subcategory is left out, never sent as "". */
export const compact = (body: Record<string, string | null | undefined>): Record<string, string> => {
  const out: Record<string, string> = {}
  for (const [k, v] of Object.entries(body)) { const s = filled(v); if (s !== undefined) out[k] = s }
  return out
}

/** The calls on one case — the same under /customer/support and /restaurant/support. */
export class ApiSupportCases implements SupportCaseRepository {
  protected readonly base: string
  protected readonly ctx: { context: AuthContextName }
  constructor(base: string, context: AuthContextName) { this.base = base; this.ctx = { context } }

  protected async call<T>(fn: () => Promise<T>): Promise<T> { try { return await fn() } catch (e) { throw e instanceof SupportError ? e : new SupportError(e) } }
  protected one(reference: string): string { return `${this.base}/cases/${encodeURIComponent(reference)}` }

  get(reference: string): Promise<SupportCase> {
    return this.call(async () => toSupportCase((await api<{ case: SupportCaseDto }>(this.one(reference), this.ctx)).case))
  }
  messages(reference: string, page = 1, pageSize?: number): Promise<SupportMessagePage> {
    return this.call(async () => {
      const d = await api<SupportPageDto<SupportMessageDto>>(`${this.one(reference)}/messages`, { ...this.ctx, query: { page, per_page: pageSize } })
      return { items: d.data.map(toSupportMessage), total: d.meta.total, page: d.meta.current_page, lastPage: d.meta.last_page, pageSize: d.meta.per_page }
    })
  }
  reply(reference: string, message: string, clientMessageId: string): Promise<{ message: SupportMessage; case: SupportCase }> {
    return this.call(async () => {
      const d = await api<{ message: SupportMessageDto; case: SupportCaseDto }>(`${this.one(reference)}/messages`, { ...this.ctx, method: 'POST', body: { message, client_message_id: supportMessageId(clientMessageId) } })
      return { message: toSupportMessage(d.message), case: toSupportCase(d.case) }
    })
  }
  close(reference: string, intent: SupportCloseIntent): Promise<SupportCase> {
    return this.call(async () => toSupportCase((await api<{ case: SupportCaseDto }>(`${this.one(reference)}/close`, { ...this.ctx, method: 'POST', body: { intent } })).case))
  }
  reopen(reference: string, message: string): Promise<SupportCase> {
    return this.call(async () => toSupportCase((await api<{ case: SupportCaseDto }>(`${this.one(reference)}/reopen`, { ...this.ctx, method: 'POST', body: { message } })).case))
  }
}

/** A customer's own cases. Identity is the token: no call names a customer, a status, a priority or an assignee. */
export class ApiSupportRepository extends ApiSupportCases implements SupportRepository {
  constructor() { super('/customer/support', 'customer') }

  catalog(): Promise<SupportCatalog> {
    return this.call(async () => toSupportCatalog(await api<SupportCatalogDto>(`${this.base}/catalog`, this.ctx)))
  }
  list(query: SupportListQuery = {}): Promise<SupportCasePage> {
    return this.call(async () => toSupportCasePage(await api<SupportPageDto<SupportCaseSummaryDto>>(`${this.base}/cases`, { ...this.ctx, query: { state: query.state ?? 'all', page: query.page ?? 1, per_page: query.pageSize } })))
  }
  create(input: NewSupportCase, idempotencyKey: string): Promise<SupportCase> {
    return this.call(async () => {
      const body = { ...compact({ category: input.category, subcategory: input.subcategory, subject: input.subject, message: input.message, order: input.order, payment: input.payment, refund: input.refund }), source: 'CUSTOMER_WEB' }
      return toSupportCase((await api<{ case: SupportCaseDto }>(`${this.base}/cases`, { ...this.ctx, method: 'POST', body, idempotencyKey })).case)
    })
  }
}

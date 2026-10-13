import { api, ApiError } from '../../api/client'
import type { Page } from '../types'

/**
 * The service desk for administrators, from the backend (Module 37): the queue of support cases and the work on them.
 *
 *   GET  /admin/support/catalog | agents?market= | metrics | queues | cases | cases/{idOrNumber}
 *   GET  /admin/support/cases/{id}/messages | notes | events | links
 *   POST /admin/support/cases                       an administrator opens an internal case
 *   POST /admin/support/cases/{id}/messages         an EXTERNAL message — the requester reads it
 *   POST /admin/support/cases/{id}/notes            an INTERNAL note — administrators only
 *   POST /admin/support/cases/{id}/assign | priority | start | resume | wait | resolve | close | reopen | cancel | escalate
 *   POST /admin/support/cases/{id}/links | annotations | domain-actions
 *   GET  /admin/system/support
 *
 * There is no generic update: every change is its own call with a closed body. Nothing here can send a status, a
 * requester, a customer or an author — and a reply and a note are two different calls that each say their visibility
 * out loud (EXTERNAL / INTERNAL), so a text can never go to the requester through a composer that showed "internal".
 * Every change of the case's state carries the version the screen showed (`expected_version`).
 *
 * The answers are used as they arrive (snake_case); what THIS administrator may do with a case is the backend's `can`
 * block, read strictly (only a literal `true` makes a control).
 */
export type SupportQueue = 'open' | 'unassigned' | 'mine' | 'awaiting_reply' | 'waiting_customer' | 'waiting_restaurant' | 'waiting_provider' | 'waiting_finance' | 'waiting_internal' | 'escalated' | 'restricted' | 'sla_at_risk' | 'sla_breached' | 'reopened' | 'resolved' | 'closed'
export const SUPPORT_QUEUES: SupportQueue[] = ['open', 'unassigned', 'mine', 'awaiting_reply', 'waiting_customer', 'waiting_restaurant', 'waiting_provider', 'waiting_finance', 'waiting_internal', 'escalated', 'restricted', 'sla_at_risk', 'sla_breached', 'reopened', 'resolved', 'closed']
export const SUPPORT_PRIORITIES = ['URGENT', 'HIGH', 'NORMAL', 'LOW'] as const
export const SUPPORT_TEAMS = ['CUSTOMER_SUPPORT', 'RESTAURANT_SUPPORT', 'PAYMENTS', 'FINANCE', 'SECURITY', 'TECHNICAL_OPERATIONS'] as const
export const SUPPORT_REQUESTERS = ['CUSTOMER', 'RESTAURANT', 'ADMIN', 'SYSTEM'] as const
export const SUPPORT_SLA_STATES = ['ON_TRACK', 'AT_RISK', 'BREACHED', 'PAUSED', 'N/A'] as const
export const SUPPORT_WAIT_FOR = ['CUSTOMER', 'RESTAURANT', 'PROVIDER', 'FINANCE', 'INTERNAL'] as const
/** The codes an administrator may give when cancelling a case instead of resolving it. */
export const SUPPORT_CANCEL_CODES = ['DUPLICATE_CASE', 'NO_ACTION_REQUIRED', 'OTHER'] as const
export const SUPPORT_LINK_TYPES = ['ORDER', 'PAYMENT', 'REFUND', 'SETTLEMENT', 'PAYOUT', 'DISPUTE', 'REVIEW', 'DIFFERENCE', 'LOCATION', 'ORGANIZATION', 'CUSTOMER'] as const
export type SupportWaitFor = (typeof SUPPORT_WAIT_FOR)[number]
export type SupportDomainAction = 'PAYMENT_RECONCILE' | 'REFUND_RECONCILE' | 'ORDER_RECOVER'

export type SupportMoney = { amount: number; currency: string }
export type SupportRequester = { type: 'CUSTOMER' | 'RESTAURANT' | 'ADMIN' | 'SYSTEM'; id: string | null; name: string | null; phone: string | null }
export type SupportSlaPolicy = { id: string; name: string; market: string | null; category: string | null; priority: string | null; team: string | null; first_response_minutes: number | null; resolution_minutes: number | null; pause_while_waiting_for_requester: boolean; status: string; approval: string; contractual: false }

/** A row of the queue. It never carries message text. */
export type SupportCaseRow = {
  id: string; case_number: string; subject: string; category: string; subcategory: string | null; priority: string; status: string
  requester: SupportRequester; restaurant: { id: string | null; name: string } | null; market: string | null; source: string
  assigned: { team: string | null; admin: { id: string; name: string } | null }
  classification: string; restricted_class: string | null; escalation_level: string | null
  sla: { status: string; first_response_due_at: string | null; resolution_due_at: string | null }
  message_count: number; awaiting_reply: boolean; last_message_at: string | null; created_at: string | null; version: number
}
export const SUPPORT_CAN = ['reply', 'notes', 'assign', 'priority', 'start', 'resume', 'wait', 'resolve', 'close', 'reopen', 'cancel', 'escalate', 'link', 'annotate'] as const
export type SupportCan = Record<(typeof SUPPORT_CAN)[number], boolean>
export type SupportAssignment = { team: string | null; admin: string | null; source: string; by: string | null; note: string | null; at: string | null }
export type SupportEscalation = { id: string; reason: string; level: string; from_team: string | null; to_team: string | null; to_admin: string | null; note: string; by: string | null; status: string; at: string | null; closed_at: string | null }
export type SupportCaseDetail = Omit<SupportCaseRow, 'sla'> & {
  summary: string | null; locale: string | null; system_key: string | null
  resolution: { code: string; note: string | null; by: string | null; duplicate_of: string | null } | null
  sla: { status: string; policy: SupportSlaPolicy | null; first_response_due_at: string | null; first_response_at: string | null; resolution_due_at: string | null; paused_at: string | null; paused_seconds: number; at_risk_at: string | null; breached_at: string | null; contractual: false }
  next_action_at: string | null; reopen_count: number; escalated_at: string | null; first_response_at: string | null; resolved_at: string | null; closed_at: string | null; reopened_at: string | null; cancelled_at: string | null; updated_at: string | null
  assignments: SupportAssignment[]; escalations: SupportEscalation[]
  attachments: { enabled: boolean; scanner: string }
  /** What a case can never do, said by the backend itself. Never turned into a control. */
  domain_authority: Record<string, string>
  /** What THIS administrator may do with the case now — decided by the backend. */
  can: SupportCan
}
export type SupportMessage = { id: string; visibility: 'EXTERNAL'; author_type: string; author_name: string | null; body: string; created_at: string | null }
export type SupportNote = { id: string; visibility: 'INTERNAL'; author_name: string; system: boolean; body: string; created_at: string | null }
export type SupportEvent = { sequence: number; type: string; actor_type: string; actor_name: string | null; from_status: string | null; to_status: string | null; audience: string; detail: Record<string, unknown>; occurred_at: string | null }
export type SupportShortcut = { action: SupportDomainAction; allowed: boolean; required_permission: string }
export type SupportLinkPanel = {
  id: string; type: string; reference: string; record_id: string; linked_at: string | null; linked_by: string
  visible: boolean; reason?: 'PERMISSION' | 'RECORD_GONE' | string; required_permission?: string
  read_at?: string | null; record?: Record<string, unknown>; shortcuts?: SupportShortcut[]
}
export type SupportDomainResult = { action: string; link: string; reference: string; before: string; after: string; changed: boolean }
export type SupportCategory = { code: string; audiences: string[]; team: string | null; priority: string; restricted: string | null; subcategories: Array<{ code: string; audiences: string[]; priority: string | null; restricted: string | null }> }
export type SupportCatalog = {
  categories: SupportCategory[]; admin_categories: Array<{ code: string; subcategories: string[] }>
  statuses: string[]; priorities: string[]; teams: string[]; resolution_codes: string[]; escalation_reasons: string[]; escalation_levels: string[]; link_types: string[]; queues: string[]
  domain_actions: Array<{ action: string; required_permission: string }>
  sla: { contractual: false; approval: string; policies: SupportSlaPolicy[] }
  limits: { subject_max: number; message_max: number; note_max: number }
  attachments: { enabled: boolean; scanner: string }
}
export type SupportNumbers = {
  total: number; open: number; unassigned: number; urgent: number; high: number; escalated: number; restricted: number
  waiting: Record<string, number>; resolved: number; closed: number; cancelled: number; reopened: number; reopened_ever: number
  sla_at_risk: number; sla_breached: number; sla_not_measured: number
  by_status: Record<string, number>; by_category: Record<string, number>; by_priority: Record<string, number>; by_team: Record<string, number>; by_requester: Record<string, number>
  created_last_7_days: number; first_response_minutes_avg_30d: number | null; resolution_minutes_avg_30d: number | null
}
export type SupportSystem = {
  checked_at: string; window_hours: number; cases: SupportNumbers; events: Record<string, number> | []
  policies: Record<string, { value: unknown; approval: string }>
  flags: { contractual_sla: boolean; attachments: string; attachment_scanner: string; restaurant_notifications: string; admin_notifications: string; security_admin_role: string; support_marks_payment_verified: boolean; support_marks_refund_completed: boolean; support_edits_settlement_or_payout: boolean; realtime: string; production: string }
}
/** A security escalation can take the case out of the escalating person's own sight: the backend then answers this instead of the case. */
export type SupportCaseGone = { id: string; case_number: string; classification: string; restricted_class: string | null; visible: false }

export type SupportListFilter = {
  query?: string; queue?: SupportQueue | 'all'; status?: string; priority?: string; category?: string; team?: string; assigned?: string; market?: string; requester?: string
  restaurant?: string; customer?: string; linkType?: string; sla?: string; classification?: string; source?: string; from?: string; to?: string; olderThanHours?: number
  page?: number; pageSize?: number
}
export type NewSupportCase = { category: string; subcategory?: string | null; subject: string; note: string; market?: string | null; links?: Array<{ type: string; reference: string }> }
export type SupportAssignInput = { team?: string | null; admin?: string | null; unassign?: boolean; note?: string | null }
export type SupportEscalateInput = { reason: string; level: string; team?: string | null; admin?: string | null; note: string }
export type SupportCancelInput = { code: string; note: string; duplicateOf?: string | null }

type ListDto<T> = { data: T[]; meta: { total: number; current_page: number; per_page: number; last_page?: number } }
type CaseDto = Omit<SupportCaseDetail, 'can'> & { can: Record<string, unknown> }

/** Only a literal `true` of the backend makes an action available; a flag the backend does not send is `false`. */
export const toSupportCase = (d: CaseDto): SupportCaseDetail => ({ ...d, can: Object.fromEntries(SUPPORT_CAN.map((k) => [k, d.can?.[k] === true])) as SupportCan })

const A = { context: 'admin' as const }
const ROOT = '/admin/support'
const one = (id: string) => `${ROOT}/cases/${encodeURIComponent(id)}`
const each = (v: string | null | undefined) => (v && v !== 'all' ? v : undefined)
const text = (v: string | null | undefined) => { const s = v?.trim(); return s ? s : undefined }
/** Bodies never carry an absent field as null: the backend's inputs are closed lists and "not sent" is the plain way to say "not chosen". */
const compact = (o: Record<string, unknown>) => Object.fromEntries(Object.entries(o).filter(([, v]) => v !== undefined && v !== null && v !== ''))

/** A token the backend accepts as client_message_id / client_note_id ([A-Za-z0-9_-], 8–64): a retry of the same text is the same message. */
export const newSupportClientId = (): string => {
  const raw = typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `desk-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`
  return raw.replace(/[^A-Za-z0-9_-]/g, '').slice(0, 64)
}

export class ApiAdminSupportRepository {
  catalog(): Promise<SupportCatalog> { return api<SupportCatalog>(`${ROOT}/catalog`, A) }

  /** Who a case of this market can be given to. */
  async agents(market?: string | null): Promise<Array<{ id: string; name: string }>> {
    return (await api<{ agents: Array<{ id: string; name: string }> }>(`${ROOT}/agents`, { ...A, query: { market: market || undefined } })).agents
  }

  metrics(): Promise<SupportNumbers> { return api<SupportNumbers>(`${ROOT}/metrics`, A) }

  async queues(): Promise<Record<SupportQueue, number> & { total: number }> {
    const res = await api<{ queues: Record<SupportQueue, number>; total: number }>(`${ROOT}/queues`, A)
    return { ...res.queues, total: res.total }
  }

  /**
   * The queue. No `sort` is sent: the backend's own order is the desk's — the most urgent first, then the case that has
   * waited longest. `q` is an exact reference (or a restaurant's name), never a customer's name, phone or e-mail.
   */
  async list(f: SupportListFilter): Promise<Page<SupportCaseRow>> {
    const res = await api<ListDto<SupportCaseRow>>(`${ROOT}/cases`, { ...A, query: {
      q: text(f.query),
      'filter[queue]': each(f.queue), 'filter[status]': each(f.status), 'filter[priority]': each(f.priority), 'filter[category]': each(f.category), 'filter[team]': each(f.team),
      'filter[assigned]': each(f.assigned), 'filter[market]': each(f.market), 'filter[requester]': each(f.requester), 'filter[restaurant]': each(f.restaurant), 'filter[customer]': each(f.customer),
      'filter[link_type]': each(f.linkType), 'filter[sla]': each(f.sla), 'filter[classification]': each(f.classification), 'filter[source]': each(f.source),
      'filter[from]': text(f.from), 'filter[to]': text(f.to), 'filter[older_than_hours]': f.olderThanHours,
      'page[number]': f.page ?? 1, 'page[size]': f.pageSize ?? 20,
    } })
    return { items: res.data, total: res.meta.total, page: res.meta.current_page, pageSize: res.meta.per_page }
  }

  async get(idOrNumber: string): Promise<SupportCaseDetail> {
    return toSupportCase((await api<{ case: CaseDto }>(one(idOrNumber), A)).case)
  }

  /** The conversation with the requester — EXTERNAL messages only, oldest first. */
  messages(id: string): Promise<SupportMessage[]> { return this.all<SupportMessage>(`${one(id)}/messages`) }

  /** The internal notes. Asked for only by a viewer whose `can.notes` is true (the backend refuses anybody else). */
  notes(id: string): Promise<SupportNote[]> { return this.all<SupportNote>(`${one(id)}/notes`) }

  /** The timeline, in the order things happened (the sequence, not the clock). */
  events(id: string): Promise<SupportEvent[]> { return this.all<SupportEvent>(`${one(id)}/events`) }

  /** The linked records as their own domains have them NOW — or, without that domain's permission, only that a record is linked. */
  async links(id: string): Promise<SupportLinkPanel[]> {
    return (await api<{ links: SupportLinkPanel[] }>(`${one(id)}/links`, A)).links
  }

  /** An administrator opens an internal case: it has no requester. Never a status, a priority, a team or an author. */
  async create(input: NewSupportCase, idempotencyKey: string): Promise<SupportCaseDetail> {
    const body = compact({ category: input.category, subcategory: input.subcategory, subject: input.subject.trim(), note: input.note.trim(), market: input.market, links: input.links && input.links.length > 0 ? input.links.map((l) => ({ type: l.type, reference: l.reference.trim() })) : undefined })
    return toSupportCase((await api<{ case: CaseDto }>(`${ROOT}/cases`, { ...A, method: 'POST', body, idempotencyKey })).case)
  }

  /** A message the REQUESTER reads. The visibility is said out loud; `then` makes the case wait for the requester afterwards. */
  async reply(id: string, input: { message: string; then?: 'WAITING_FOR_CUSTOMER' | 'WAITING_FOR_RESTAURANT' | null; clientMessageId?: string | null; expectedVersion?: number | null }): Promise<{ message: SupportMessage; case: SupportCaseDetail }> {
    // A reply that also makes the case wait is a change of state: it carries the version that was read (the backend requires it with `then`).
    const body = compact({ message: input.message, visibility: 'EXTERNAL', then: input.then, client_message_id: input.clientMessageId, expected_version: input.then ? input.expectedVersion : undefined })
    const res = await api<{ message: SupportMessage; case: CaseDto }>(`${one(id)}/messages`, { ...A, method: 'POST', body })
    return { message: res.message, case: toSupportCase(res.case) }
  }

  /** A note only administrators read. Another endpoint and another table than the conversation. */
  async note(id: string, input: { note: string; clientNoteId?: string | null }): Promise<SupportNote> {
    const body = compact({ note: input.note, visibility: 'INTERNAL', client_note_id: input.clientNoteId })
    return (await api<{ note: SupportNote }>(`${one(id)}/notes`, { ...A, method: 'POST', body })).note
  }

  assign(id: string, input: SupportAssignInput, expectedVersion: number): Promise<SupportCaseDetail> {
    return this.change(id, 'assign', { team: input.team, admin: input.admin, unassign: input.unassign ? true : undefined, note: text(input.note), expected_version: expectedVersion })
  }

  priority(id: string, priority: string, reason: string, expectedVersion: number): Promise<SupportCaseDetail> {
    return this.change(id, 'priority', { priority, reason: reason.trim(), expected_version: expectedVersion })
  }

  start(id: string, reason: string | null, expectedVersion: number): Promise<SupportCaseDetail> {
    return this.change(id, 'start', { reason: text(reason), expected_version: expectedVersion })
  }

  resume(id: string, reason: string | null, expectedVersion: number): Promise<SupportCaseDetail> {
    return this.change(id, 'resume', { reason: text(reason), expected_version: expectedVersion })
  }

  /** The case waits for somebody. WHO is a word of a closed list — the status itself is the backend's. */
  wait(id: string, waitFor: SupportWaitFor, reason: string | null, expectedVersion: number): Promise<SupportCaseDetail> {
    return this.change(id, 'wait', { for: waitFor, reason: text(reason), expected_version: expectedVersion })
  }

  resolve(id: string, code: string, note: string, expectedVersion: number): Promise<SupportCaseDetail> {
    return this.change(id, 'resolve', { resolution_code: code, note: note.trim(), expected_version: expectedVersion })
  }

  close(id: string, expectedVersion: number): Promise<SupportCaseDetail> {
    return this.change(id, 'close', { expected_version: expectedVersion })
  }

  reopen(id: string, reason: string, expectedVersion: number): Promise<SupportCaseDetail> {
    return this.change(id, 'reopen', { reason: reason.trim(), expected_version: expectedVersion })
  }

  /** Ends a case that is not a case. `duplicate_of` travels only with DUPLICATE_CASE (the backend refuses it otherwise). */
  cancel(id: string, input: SupportCancelInput, expectedVersion: number): Promise<SupportCaseDetail> {
    return this.change(id, 'cancel', { resolution_code: input.code, note: input.note.trim(), duplicate_of: input.code === 'DUPLICATE_CASE' ? text(input.duplicateOf) : undefined, expected_version: expectedVersion })
  }

  /** Asks another team or a higher level to look. The answer is the case — or, when it left the caller's sight, only that it did. */
  async escalate(id: string, input: SupportEscalateInput, expectedVersion: number): Promise<SupportCaseDetail | SupportCaseGone> {
    const body = compact({ reason: input.reason, level: input.level, team: input.team, admin: input.admin, note: input.note.trim(), expected_version: expectedVersion })
    const res = await api<{ case: CaseDto | SupportCaseGone }>(`${one(id)}/escalate`, { ...A, method: 'POST', body })
    return 'visible' in res.case && res.case.visible === false ? res.case : toSupportCase(res.case as CaseDto)
  }

  /** Attaches a record the administrator may read in its own domain. Answers the panels as they are now. */
  async link(id: string, type: string, reference: string): Promise<SupportLinkPanel[]> {
    return (await api<{ links: SupportLinkPanel[] }>(`${one(id)}/links`, { ...A, method: 'POST', body: { type, reference: reference.trim() } })).links
  }

  /** The administrators' own summary and follow-up time. Only what is given is sent; `null` clears a value. */
  async annotate(id: string, input: { summary?: string | null; nextActionAt?: string | null }, expectedVersion: number): Promise<SupportCaseDetail> {
    const body: Record<string, unknown> = { expected_version: expectedVersion }
    if (input.summary !== undefined) body.summary = input.summary
    if (input.nextActionAt !== undefined) body.next_action_at = input.nextActionAt
    return toSupportCase((await api<{ case: CaseDto }>(`${one(id)}/annotations`, { ...A, method: 'POST', body })).case)
  }

  /**
   * A shortcut to an action of ANOTHER domain on a linked record. That domain's own service runs it and that domain's
   * own permission decides; the answer says what the DOMAIN found (before → after). It can set nothing.
   */
  domainAction(id: string, action: SupportDomainAction, linkId: string, reason: string): Promise<{ result: SupportDomainResult; links: SupportLinkPanel[] }> {
    return api<{ result: SupportDomainResult; links: SupportLinkPanel[] }>(`${one(id)}/domain-actions`, { ...A, method: 'POST', body: { action, link: linkId, reason: reason.trim() } })
  }

  /** The support domain in numbers and flags, for the System page (needs admin.system.view). */
  system(): Promise<SupportSystem> { return api<SupportSystem>('/admin/system/support', A) }

  private async change(id: string, action: string, body: Record<string, unknown>): Promise<SupportCaseDetail> {
    return toSupportCase((await api<{ case: CaseDto }>(`${one(id)}/${action}`, { ...A, method: 'POST', body: compact(body) })).case)
  }

  /** Every page of a thread, in order (the backend pages them; a case's thread is read whole, up to a bound). */
  private async all<T>(path: string): Promise<T[]> {
    const first = await api<ListDto<T>>(path, { ...A, query: { page: 1, per_page: 100 } })
    const out = [...first.data]
    const last = Math.min(first.meta?.last_page ?? 1, 10)
    for (let page = 2; page <= last; page++) out.push(...(await api<ListDto<T>>(path, { ...A, query: { page, per_page: 100 } })).data)
    return out
  }
}

/* ------------------------------------------------------------------ refusals */

/** What a refused call means for the screen. `reload` = the case on screen is not the case any more: read it again. */
export type SupportProblem = {
  code: string
  kind: 'conflict' | 'forbidden' | 'restricted' | 'not_found' | 'invalid' | 'validation' | 'rate_limited' | 'unavailable'
  message: string
  reload: boolean
  currentVersion: number | null
  requiredPermission: string | null
  fields: Record<string, string[]>
}
const PLAIN: Record<string, string> = {
  forbidden: 'You do not hold the permission for this.',
  unavailable: 'The service desk could not be reached. Nothing was changed — please try again.',
  conflict: 'This case changed since you opened it. It was read again: check it, then repeat what you wanted to do.',
}
/**
 * The backend's refusal as the screen needs it: the backend's own sentence for a rule it explained, a plain sentence
 * for a missing permission, and whether the case must be read again.
 */
export function describeSupportError(e: unknown): SupportProblem {
  const base: SupportProblem = { code: 'unknown', kind: 'unavailable', message: PLAIN.unavailable, reload: false, currentVersion: null, requiredPermission: null, fields: {} }
  if (!(e instanceof ApiError) || e.kind === 'network' || e.kind === 'server') return base
  const required = typeof e.details.required_permission === 'string' ? e.details.required_permission : null
  const current = typeof e.details.current_version === 'number' ? e.details.current_version : null
  const p: SupportProblem = { ...base, code: e.code, message: e.message, currentVersion: current, requiredPermission: required, fields: e.errors }
  switch (e.code) {
    case 'case_version_conflict': return { ...p, kind: 'conflict', message: PLAIN.conflict, reload: true }
    case 'case_invalid_transition': case 'case_not_open': return { ...p, kind: 'invalid', reload: true }
    case 'case_has_no_requester': case 'resolution_not_supported_by_record': case 'case_links_full': return { ...p, kind: 'invalid' }
    case 'case_restricted': return { ...p, kind: 'restricted' }
    case 'case_not_found': case 'linked_record_not_found': case 'case_link_not_found': case 'order_fulfillment_not_found': return { ...p, kind: 'not_found' }
    case 'domain_permission_required': return { ...p, kind: 'forbidden', message: required ? `Working on a case does not allow this. It needs the permission ${required} of the record's own domain.` : e.message }
    case 'linked_record_not_authorized': return { ...p, kind: 'forbidden', message: required ? `You need ${required} to read this kind of record, so you cannot attach it to a case.` : e.message }
    case 'validation_failed': return { ...p, kind: 'validation', message: Object.values(e.errors)[0]?.[0] ?? e.message }
    default:
      if (e.kind === 'forbidden') return { ...p, kind: 'forbidden', message: PLAIN.forbidden }
      if (e.kind === 'validation') return { ...p, kind: 'validation', message: Object.values(e.errors)[0]?.[0] ?? e.message }
      if (e.kind === 'not_found') return { ...p, kind: 'not_found' }
      if (e.kind === 'rate_limited') return { ...p, kind: 'rate_limited' }
      if (e.kind === 'conflict') return { ...p, kind: 'conflict', reload: current !== null }
      return { ...p, kind: 'invalid' }
  }
}

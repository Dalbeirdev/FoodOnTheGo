/**
 * Support cases (Module 37) — the contracts the Customer Web and the Restaurant Dashboard share.
 *
 * A case is a conversation with FoodOnTheGo support about something the requester chose (a category, a subject) and,
 * optionally, about records of their own (an order, a payment, a refund; for a restaurant an order, a settlement, a
 * payout). The backend owns every decision: the status, what may be done next (`can`), which records may be linked
 * and what is read about them. Nothing here carries an internal note, a priority, a queue, an assignee or a response
 * target — the backend never sends them to these two surfaces.
 */

/** The six words a requester reads. The backend's internal states never arrive. */
export const SUPPORT_STATUSES = ['OPEN', 'IN_PROGRESS', 'WAITING_FOR_YOU', 'RESOLVED', 'CLOSED', 'CANCELLED'] as const
export type SupportStatus = (typeof SUPPORT_STATUSES)[number]
export type SupportAuthor = 'YOU' | 'SUPPORT' | 'SYSTEM'
export type SupportMoney = { amount: number; currency: string }

/** A record the case is about, as its own domain has it NOW (read by the backend each time the case is shown). */
export type SupportLink = {
  id: string
  /** ORDER | PAYMENT | REFUND for a customer; ORDER | SETTLEMENT | PAYOUT for a restaurant. */
  type: string
  reference: string
  recordId: string
  /** The linked record's own status word — shown as it is, never guessed. */
  status: string | null
  /** The order total, the payment or refund amount, the settlement's net payable, the payout amount. */
  amount: SupportMoney | null
  orderNumber: string | null
  restaurant: string | null
  placedAt: string | null
  refundStatus: string | null
  periodStart: string | null
  periodEnd: string | null
}

export type SupportCaseSummary = {
  id: string
  caseNumber: string
  category: string
  subcategory: string | null
  subject: string
  status: SupportStatus
  /** A support reply the requester has not opened yet. */
  unread: boolean
  messageCount: number
  /** Restaurant cases only: the location the case belongs to (null = the organization as a whole). */
  location: { id: string; name: string } | null
  createdAt: string | null
  lastMessageAt: string | null
  resolvedAt: string | null
  closedAt: string | null
}
/** What may be done with the case now. Decided by the backend; a screen offers exactly these. */
export type SupportCloseIntent = 'CONFIRM' | 'WITHDRAW'
export type SupportCaseActions = { reply: boolean; close: boolean; withdraw: boolean; reopen: boolean }
export type SupportCase = SupportCaseSummary & { links: SupportLink[]; can: SupportCaseActions; reopenUntil: string | null }
export type SupportMessage = { id: string; author: SupportAuthor; /** A restaurant's own staff member; never an administrator. */ authorName: string | null; body: string; createdAt: string | null }

export type SupportCasePage = { items: SupportCaseSummary[]; total: number; page: number; lastPage: number; /** Cases with a support reply that was not opened, over the whole list. */ unread: number }
export type SupportMessagePage = { items: SupportMessage[]; total: number; page: number; lastPage: number; pageSize: number }
export type SupportCategory = { code: string; subcategories: string[]; /** Restaurant: a category about money, offered only with the finance permission. */ needsFinance?: boolean }
export type SupportCatalog = { categories: SupportCategory[]; linkTypes: string[]; limits: { subjectMax: number; messageMax: number } }
export type SupportListState = 'open' | 'closed' | 'all'
export type SupportListQuery = { state?: SupportListState; page?: number; pageSize?: number }

/** What a customer sends: what they chose, what they wrote and — optionally — their own order, payment or refund. */
export type NewSupportCase = { category: string; subcategory?: string | null; subject: string; message: string; order?: string | null; payment?: string | null; refund?: string | null }

/** The calls every requester has, whichever surface they are on. A case is named by its id or its case number. */
export interface SupportCaseRepository {
  get(reference: string): Promise<SupportCase>
  /** The conversation oldest first, a page at a time (page 1 = the oldest messages). */
  messages(reference: string, page?: number, pageSize?: number): Promise<SupportMessagePage>
  /** `clientMessageId` names this message: sending it again after a lost answer writes nothing twice. */
  reply(reference: string, message: string, clientMessageId: string): Promise<{ message: SupportMessage; case: SupportCase }>
  /** Confirms a RESOLVED case (→ CLOSED) or withdraws one that is still being worked on (→ CANCELLED). */
  /** Confirms a resolved case or withdraws an open one. The intent says which was meant (the backend requires it) and is refused (409 case_changed) when the case is no longer in that state. */
  close(reference: string, intent: SupportCloseIntent): Promise<SupportCase>
  reopen(reference: string, message: string): Promise<SupportCase>
}

export interface SupportRepository extends SupportCaseRepository {
  catalog(): Promise<SupportCatalog>
  list(query?: SupportListQuery): Promise<SupportCasePage>
  /** `idempotencyKey` is made once per composed request and sent again on a retry. */
  create(input: NewSupportCase, idempotencyKey: string): Promise<SupportCase>
}

/* ------------------------------------------------------------------ restaurant */

export type RestaurantSupportCatalog = SupportCatalog & {
  /** The locations this member may open a case for (`finance`: money categories are allowed there). */
  locations: Array<{ id: string; name: string; finance: boolean }>
  /** Non-empty for a member of ALL locations of an organization: a case may be about the organization as a whole. */
  organizations: Array<{ id: string; name: string }>
  canCreate: boolean
}
export type RestaurantSupportListQuery = SupportListQuery & { /** A backend location id; absent or 'all' = every location the member may read. */ location?: string | null }
export type NewRestaurantSupportCase = {
  location?: string | null; organization?: string | null
  category: string; subcategory?: string | null; subject: string; message: string
  order?: string | null; settlement?: string | null; payout?: string | null
}
export interface RestaurantSupportRepository extends SupportCaseRepository {
  catalog(): Promise<RestaurantSupportCatalog>
  list(query?: RestaurantSupportListQuery): Promise<SupportCasePage>
  create(input: NewRestaurantSupportCase, idempotencyKey: string): Promise<SupportCase>
}

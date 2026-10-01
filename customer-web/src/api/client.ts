/**
 * The one HTTP client for the FoodOnTheGo backend (Customer Web, Restaurant Dashboard and Platform Admin).
 *
 * - Base URL comes from VITE_API_BASE_URL (never a literal in a component).
 * - Three separate sign-in contexts — customer, restaurant, admin — each with its own token. The backend accepts a
 *   token only on routes of its own context, and the client never sends one context's token for another.
 * - Tokens live in sessionStorage: they are gone when the tab closes and are never written to disk-persistent
 *   storage. They expire and can be revoked server-side (Module 21 decision; see docs/backend/README.md).
 * - Sends an X-Request-Id for support / log correlation.
 * - Understands the backend error envelope {error: {code, message, details, request_id}} and turns every
 *   failure into an ApiError, so callers handle 401 / 403 / 404 / 409 / 422 / 429 / 5xx the same way.
 *
 * Pages never call this directly: Api* repositories do, behind the same interfaces the Mock* repositories implement.
 */
export const API_BASE_URL = (import.meta.env.VITE_API_BASE_URL as string | undefined) ?? 'http://127.0.0.1:8001/api/v1'

export type AuthContextName = 'customer' | 'restaurant' | 'admin'
const tokenKey = (context: AuthContextName) => `fotg.auth.token.${context}`

export type ApiErrorKind = 'network' | 'unauthenticated' | 'forbidden' | 'not_found' | 'conflict' | 'validation' | 'rate_limited' | 'server' | 'client'

const kindOf = (status: number): ApiErrorKind =>
  status === 0 ? 'network' : status === 401 ? 'unauthenticated' : status === 403 ? 'forbidden' : status === 404 ? 'not_found' : status === 409 ? 'conflict'
    : status === 422 ? 'validation' : status === 429 ? 'rate_limited' : status >= 500 ? 'server' : 'client'

export class ApiError extends Error {
  status: number
  /** Stable machine-readable code from the backend (e.g. validation_failed, otp_invalid, market_unavailable). */
  code: string
  kind: ApiErrorKind
  /** Field errors of a 422. */
  errors: Record<string, string[]>
  details: Record<string, unknown>
  /** Quote this to support: it identifies the request in the backend logs. */
  requestId: string | null
  retryAfterSeconds: number | null
  constructor(status: number, message: string, init: { code?: string; errors?: Record<string, string[]>; details?: Record<string, unknown>; requestId?: string | null; retryAfterSeconds?: number | null } = {}) {
    super(message)
    this.status = status
    this.kind = kindOf(status)
    this.code = init.code ?? this.kind
    this.errors = init.errors ?? {}
    this.details = init.details ?? {}
    this.requestId = init.requestId ?? null
    this.retryAfterSeconds = init.retryAfterSeconds ?? null
  }
  /** First validation message for a field, if any. */
  field(name: string): string | undefined { return this.errors[name]?.[0] }
}

export const tokens = {
  get(context: AuthContextName): string | null { try { return sessionStorage.getItem(tokenKey(context)) } catch { return null } },
  set(context: AuthContextName, token: string | null) { try { if (token) sessionStorage.setItem(tokenKey(context), token); else sessionStorage.removeItem(tokenKey(context)) } catch { /* storage unavailable */ } },
}
/** Customer token store (kept for callers written before the three contexts existed). */
export const tokenStore = { get: () => tokens.get('customer'), set: (token: string | null) => tokens.set('customer', token) }

/** Called when the backend answers 401 for an authenticated request of that context (expired / revoked token). */
const unauthenticatedHandlers: Partial<Record<AuthContextName, () => void>> = {}
export function setUnauthenticatedHandler(handler: (() => void) | null, context: AuthContextName = 'customer') { if (handler) unauthenticatedHandlers[context] = handler; else delete unauthenticatedHandlers[context] }

const newRequestId = () => (typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `web-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`)

const FALLBACK: Record<ApiErrorKind, string> = {
  network: 'Cannot reach FoodOnTheGo right now. Check your connection and try again.',
  unauthenticated: 'Please sign in to continue.',
  forbidden: 'You are not allowed to do this.',
  not_found: 'We could not find that.',
  conflict: 'This was changed in the meantime. Please review and try again.',
  validation: 'Please check the highlighted fields.',
  rate_limited: 'Too many attempts. Please wait a moment and try again.',
  server: 'Something went wrong on our side. Please try again.',
  client: 'The request could not be completed.',
}

export type ApiRequest = {
  method?: string; body?: unknown
  /** false = public call (no token). */
  auth?: boolean
  /** Which sign-in context the call belongs to (default: customer). */
  context?: AuthContextName
  /** Use this token instead of the stored one (e.g. a just-issued token that is not a session yet). */
  token?: string
  query?: Record<string, string | number | boolean | null | undefined>; idempotencyKey?: string; signal?: AbortSignal
}

/**
 * Language of the texts in the backend's answers (error and confirmation messages). Each call says which language
 * its screen is in: the staff tools their own language, the customer site English (it is not translated). Sent
 * explicitly so the browser's own language list does not decide it.
 */
let staffLanguage = 'en'
export function setStaffApiLanguage(lang: string): void { staffLanguage = /^[a-z]{2,3}$/.test(lang) ? lang : 'en' }
const STAFF_PATH = /^\/(admin|auth\/(admin|restaurant))(\/|$)/

export async function api<T>(path: string, init: ApiRequest = {}): Promise<T> {
  const requestId = newRequestId()
  const context = init.context ?? 'customer'
  const headers: Record<string, string> = { Accept: 'application/json', 'Accept-Language': context !== 'customer' || STAFF_PATH.test(path) ? staffLanguage : 'en', 'X-Request-Id': requestId }
  if (init.body !== undefined) headers['Content-Type'] = 'application/json'
  if (init.idempotencyKey) headers['Idempotency-Key'] = init.idempotencyKey
  const token = init.auth === false ? null : init.token ?? tokens.get(context)
  if (token) headers.Authorization = `Bearer ${token}`

  const query = Object.entries(init.query ?? {}).filter(([, v]) => v !== null && v !== undefined).map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`).join('&')
  const url = `${API_BASE_URL}${path}${query ? (path.includes('?') ? '&' : '?') + query : ''}`

  let res: Response
  try {
    res = await fetch(url, { method: init.method ?? 'GET', headers, body: init.body === undefined ? undefined : JSON.stringify(init.body), signal: init.signal })
  } catch {
    throw new ApiError(0, FALLBACK.network, { requestId })
  }

  const text = await res.text()
  let data: Record<string, unknown> = {}
  try { data = text ? (JSON.parse(text) as Record<string, unknown>) : {} } catch { /* non-JSON body (proxy / gateway error) */ }

  if (!res.ok) {
    const kind = kindOf(res.status)
    const error = (data.error ?? {}) as { code?: string; message?: string; details?: Record<string, unknown>; request_id?: string }
    const details = error.details ?? {}
    const retryHeader = Number(res.headers.get('Retry-After'))
    // Only a rejected *stored* session ends the session. A 403 never signs anyone out.
    if (kind === 'unauthenticated' && token && !init.token) { tokens.set(context, null); unauthenticatedHandlers[context]?.() }
    throw new ApiError(res.status, typeof error.message === 'string' && error.message && kind !== 'server' ? error.message : FALLBACK[kind], {
      code: error.code,
      errors: (details.fields as Record<string, string[]> | undefined) ?? {},
      details,
      requestId: error.request_id ?? res.headers.get('X-Request-Id') ?? requestId,
      retryAfterSeconds: typeof details.retry_after_seconds === 'number' ? details.retry_after_seconds : Number.isFinite(retryHeader) && retryHeader > 0 ? retryHeader : null,
    })
  }
  return data as T
}

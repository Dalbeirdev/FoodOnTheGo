/**
 * The one HTTP client for the FoodOnTheGo backend (Customer Web, Restaurant Dashboard and Platform Admin).
 *
 * - Base URL comes from VITE_API_BASE_URL (never a literal in a component).
 * - Sends the bearer token when present and an X-Request-Id for support / log correlation.
 * - Understands the backend error envelope {error: {code, message, details, request_id}} and turns every
 *   failure into an ApiError, so callers handle 401 / 403 / 404 / 409 / 422 / 429 / 5xx the same way.
 *
 * Pages never call this directly: Api* repositories do, behind the same interfaces the Mock* repositories implement.
 */
export const API_BASE_URL = (import.meta.env.VITE_API_BASE_URL as string | undefined) ?? 'http://127.0.0.1:8001/api/v1'

const TOKEN_KEY = 'fotg.auth.token'

export type ApiErrorKind = 'network' | 'unauthenticated' | 'forbidden' | 'not_found' | 'conflict' | 'validation' | 'rate_limited' | 'server' | 'client'

const kindOf = (status: number): ApiErrorKind =>
  status === 0 ? 'network' : status === 401 ? 'unauthenticated' : status === 403 ? 'forbidden' : status === 404 ? 'not_found' : status === 409 ? 'conflict'
    : status === 422 ? 'validation' : status === 429 ? 'rate_limited' : status >= 500 ? 'server' : 'client'

export class ApiError extends Error {
  status: number
  /** Stable machine-readable code from the backend (e.g. validation_failed, market_unavailable). */
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

export const tokenStore = {
  get(): string | null { try { return localStorage.getItem(TOKEN_KEY) } catch { return null } },
  set(token: string | null) { try { if (token) localStorage.setItem(TOKEN_KEY, token); else localStorage.removeItem(TOKEN_KEY) } catch { /* storage unavailable */ } },
}

/** Called when the backend answers 401 for an authenticated request (expired / revoked token). */
let onUnauthenticated: (() => void) | null = null
export function setUnauthenticatedHandler(handler: (() => void) | null) { onUnauthenticated = handler }

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

export type ApiRequest = { method?: string; body?: unknown; auth?: boolean; query?: Record<string, string | number | boolean | null | undefined>; idempotencyKey?: string; signal?: AbortSignal }

export async function api<T>(path: string, init: ApiRequest = {}): Promise<T> {
  const requestId = newRequestId()
  const headers: Record<string, string> = { Accept: 'application/json', 'X-Request-Id': requestId }
  if (init.body !== undefined) headers['Content-Type'] = 'application/json'
  if (init.idempotencyKey) headers['Idempotency-Key'] = init.idempotencyKey
  const token = tokenStore.get()
  const authenticated = init.auth !== false && !!token
  if (authenticated) headers.Authorization = `Bearer ${token}`

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
    if (kind === 'unauthenticated' && authenticated) { tokenStore.set(null); onUnauthenticated?.() }
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

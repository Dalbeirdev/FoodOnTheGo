/**
 * Minimal JSON API client for the local Laravel backend (VITE_API_BASE_URL).
 * Sends the Sanctum bearer token when present and normalises Laravel validation errors.
 */
export const API_BASE_URL = (import.meta.env.VITE_API_BASE_URL as string | undefined) ?? 'http://127.0.0.1:8001/api/v1'

const TOKEN_KEY = 'fotg.auth.token'

export class ApiError extends Error {
  status: number
  errors: Record<string, string[]>
  constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
    super(message)
    this.status = status
    this.errors = errors
  }
  /** First validation message for a field, if any. */
  field(name: string): string | undefined { return this.errors[name]?.[0] }
}

export const tokenStore = {
  get(): string | null { try { return localStorage.getItem(TOKEN_KEY) } catch { return null } },
  set(token: string | null) { try { token ? localStorage.setItem(TOKEN_KEY, token) : localStorage.removeItem(TOKEN_KEY) } catch { /* storage unavailable */ } },
}

export async function api<T>(path: string, init: { method?: string; body?: unknown; auth?: boolean } = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (init.body !== undefined) headers['Content-Type'] = 'application/json'
  const token = tokenStore.get()
  if (init.auth !== false && token) headers.Authorization = `Bearer ${token}`

  let res: Response
  try {
    res = await fetch(`${API_BASE_URL}${path}`, { method: init.method ?? 'GET', headers, body: init.body === undefined ? undefined : JSON.stringify(init.body) })
  } catch {
    throw new ApiError(0, 'Cannot reach the FoodOnTheGo API. Is the local backend running?')
  }

  const text = await res.text()
  const data = text ? (JSON.parse(text) as Record<string, unknown>) : {}
  if (!res.ok) {
    const message = typeof data.message === 'string' && data.message ? data.message : res.status === 429 ? 'Too many attempts. Please wait a minute and try again.' : `Request failed (${res.status})`
    throw new ApiError(res.status, message, (data.errors as Record<string, string[]>) ?? {})
  }
  return data as T
}

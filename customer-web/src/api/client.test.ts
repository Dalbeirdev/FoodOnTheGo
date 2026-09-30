import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { API_BASE_URL, ApiError, api, setUnauthenticatedHandler, tokenStore } from './client'
import { marketApi } from './marketApi'

const respond = (status: number, body: unknown, headers: Record<string, string> = {}) =>
  new Response(body === null ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json', ...headers } })
const fetchMock = vi.fn<typeof fetch>()
const failure = async (p: Promise<unknown>): Promise<ApiError> => { try { await p } catch (e) { return e as ApiError } throw new Error('expected the request to fail') }

beforeEach(() => { fetchMock.mockReset(); vi.stubGlobal('fetch', fetchMock); tokenStore.set(null); setUnauthenticatedHandler(null) })
afterEach(() => { vi.unstubAllGlobals() })

describe('API client (Module 20 backend contract)', () => {
  it('builds the URL from the configured base, sends a request id and no token for public calls', async () => {
    fetchMock.mockResolvedValue(respond(200, { status: 'ok' }))
    await api('/health', { auth: false, query: { country: 'IN', skip: undefined } })
    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toBe(`${API_BASE_URL}/health?country=IN`)
    const headers = (init as RequestInit).headers as Record<string, string>
    expect(headers['X-Request-Id']).toMatch(/^[A-Za-z0-9._-]{8,64}$/)
    expect(headers.Authorization).toBeUndefined()
  })

  it('sends the bearer token and the idempotency key when given', async () => {
    tokenStore.set('12|token')
    fetchMock.mockResolvedValue(respond(201, { ok: true }))
    await api('/orders', { method: 'POST', body: { a: 1 }, idempotencyKey: 'checkout-0001-aaaa-bbbb' })
    const headers = (fetchMock.mock.calls[0][1] as RequestInit).headers as Record<string, string>
    expect(headers.Authorization).toBe('Bearer 12|token')
    expect(headers['Idempotency-Key']).toBe('checkout-0001-aaaa-bbbb')
    expect(headers['Content-Type']).toBe('application/json')
  })

  it('422: exposes field errors from the error envelope', async () => {
    fetchMock.mockResolvedValue(respond(422, { error: { code: 'validation_failed', message: 'The submitted data is invalid.', details: { fields: { country: ['Must be an ISO code.'] } }, request_id: 'req-12345678' } }))
    const e = await failure(api('/markets/current'))
    expect(e).toBeInstanceOf(ApiError)
    expect([e.status, e.kind, e.code, e.field('country'), e.requestId]).toEqual([422, 'validation', 'validation_failed', 'Must be an ISO code.', 'req-12345678'])
  })

  it('401 on an authenticated call clears the token and notifies the session handler; a public 401 does not', async () => {
    const handler = vi.fn(); setUnauthenticatedHandler(handler)
    fetchMock.mockResolvedValue(respond(401, { error: { code: 'unauthenticated', message: 'Authentication is required.' } }))
    expect((await failure(api('/auth/me', { auth: false }))).kind).toBe('unauthenticated')
    expect(handler).not.toHaveBeenCalled()
    tokenStore.set('12|expired')
    fetchMock.mockResolvedValue(respond(401, { error: { code: 'unauthenticated', message: 'Authentication is required.' } }))
    await failure(api('/auth/me'))
    expect(handler).toHaveBeenCalledTimes(1)
    expect(tokenStore.get()).toBeNull()
  })

  it('403, 404 and 409 keep the backend code and message', async () => {
    fetchMock.mockResolvedValueOnce(respond(403, { error: { code: 'forbidden', message: 'You are not allowed to do this.' } }))
    expect((await failure(api('/admin/markets'))).kind).toBe('forbidden')
    fetchMock.mockResolvedValueOnce(respond(404, { error: { code: 'market_unavailable', message: 'FoodOnTheGo is not available in this country yet.' } }))
    const notFound = await failure(api('/markets/current'))
    expect([notFound.kind, notFound.code, notFound.message]).toEqual(['not_found', 'market_unavailable', 'FoodOnTheGo is not available in this country yet.'])
    fetchMock.mockResolvedValueOnce(respond(409, { error: { code: 'idempotency_key_reused', message: 'Already used.' } }))
    expect((await failure(api('/orders', { method: 'POST', body: {} }))).code).toBe('idempotency_key_reused')
  })

  it('429: exposes the retry delay', async () => {
    fetchMock.mockResolvedValue(respond(429, { error: { code: 'rate_limited', message: 'Too many requests. Please try again shortly.', details: { retry_after_seconds: 42 } } }, { 'Retry-After': '42' }))
    const e = await failure(api('/auth/login', { method: 'POST', body: {} }))
    expect([e.kind, e.retryAfterSeconds]).toEqual(['rate_limited', 42])
  })

  it('5xx never shows server text to the customer, and a non-JSON gateway error is handled', async () => {
    fetchMock.mockResolvedValueOnce(respond(500, { error: { code: 'internal_error', message: 'SQLSTATE[42P01] relation missing' }, debug: { exception: 'QueryException' } }))
    const e = await failure(api('/config'))
    expect(e.kind).toBe('server'); expect(e.message).not.toMatch(/SQLSTATE/)
    fetchMock.mockResolvedValueOnce(new Response('<html>Bad gateway</html>', { status: 502 }))
    expect((await failure(api('/config'))).kind).toBe('server')
  })

  it('network failure becomes a network ApiError with a request id', async () => {
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))
    const e = await failure(api('/health'))
    expect([e.status, e.kind]).toEqual([0, 'network']); expect(e.requestId).toBeTruthy()
  })

  it('market adapter maps the backend DTO onto the domain type (no page change needed)', async () => {
    const dto = { id: '02cd6cc9-12d9-46c6-95f0-42068bcbac74', slug: 'india', country_code: 'IN', name: 'India', status: 'ACTIVE', default_currency: 'INR', supported_currencies: ['INR'], default_locale: 'en-IN', supported_locales: ['en-IN'], timezone_strategy: 'single', default_timezone: 'Asia/Kolkata', distance_unit: 'metric', phone_country_code: '+91', features: { scheduled_pickup: true, cash_at_pickup: false }, launched_at: null }
    fetchMock.mockResolvedValueOnce(respond(200, dto))
    const market = await marketApi.current()
    expect(market).toMatchObject({ countryCode: 'IN', displayName: 'India', status: 'ACTIVE', defaultCurrency: 'INR', defaultLocale: 'en-IN', defaultTimezone: 'Asia/Kolkata', distanceUnit: 'metric', phoneCountryCode: '+91' })
    expect(market.features.cash_at_pickup).toBe(false)
    fetchMock.mockResolvedValueOnce(respond(200, { api: { version: 'v1' }, app: { name: 'FoodOnTheGo', version: 'local-dev' }, market: dto }))
    expect((await marketApi.clientConfig('IN')).apiVersion).toBe('v1')
    expect(String(fetchMock.mock.calls[1][0])).toContain('/config?country=IN')
  })
})

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { tokens } from '../../api/client'
import { authMode } from '../authMode'
import { AuthError } from '../repository'
import { ApiAuthRepository } from './ApiAuthRepository'

const json = (status: number, body: unknown, headers: Record<string, string> = {}) => new Response(body === null ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json', ...headers } })
const fetchMock = vi.fn<typeof fetch>()
const body = (call: number) => JSON.parse(String((fetchMock.mock.calls[call][1] as RequestInit).body)) as Record<string, unknown>
const headers = (call: number) => (fetchMock.mock.calls[call][1] as RequestInit).headers as Record<string, string>
const failure = async (p: Promise<unknown>): Promise<AuthError> => { try { await p } catch (e) { return e as AuthError } throw new Error('expected a failure') }

const NOW = Date.parse('2026-09-30T10:00:00Z')
const challenge = (over: Record<string, unknown> = {}) => ({ challenge_id: '11111111-1111-4111-8111-111111111111', phone_masked: '+91 ******3210', expires_at: '2026-09-30T10:05:00+00:00', resend_available_at: '2026-09-30T10:00:30+00:00', attempts_allowed: 5, server_time: '2026-09-30T10:00:00+00:00', delivery: 'development', ...over })
const principal = (over: Record<string, unknown> = {}) => ({ principal_type: 'CUSTOMER', id: 'c0ffee00-0000-4000-8000-000000000001', status: 'ACTIVE', name: 'Rahul Sharma', email: 'rahul@example.com', phone: '+919876543210', phone_masked: '+91 ******3210', phone_verified: true, profile_complete: true, preferred_locale: null, market: 'IN', member_since: '2026-01-12', ...over })
const session = (over: Record<string, unknown> = {}, p: Record<string, unknown> = {}) => ({ token: '12|customer-token', token_type: 'Bearer', expires_at: '2026-10-30T10:00:00+00:00', new_account: false, principal: principal(p), ...over })

let repo: ApiAuthRepository
beforeEach(() => { fetchMock.mockReset(); vi.stubGlobal('fetch', fetchMock); vi.useFakeTimers({ now: NOW, toFake: ['Date'] }); sessionStorage.clear(); localStorage.clear(); vi.stubEnv('VITE_DEV_OTP', '123456'); repo = new ApiAuthRepository() })
afterEach(() => { vi.unstubAllGlobals(); vi.unstubAllEnvs(); vi.useRealTimers() })

describe('ApiAuthRepository — customer phone + OTP against the backend (Module 21)', () => {
  it('unit tests and share builds always use the mock; a browser override can select either', () => {
    expect(authMode()).toBe('mock')
    localStorage.setItem('fotg.auth.mode', 'api'); expect(authMode()).toBe('api')
    localStorage.setItem('fotg.auth.mode', 'mock'); expect(authMode()).toBe('mock')
  })

  it('requests a code for the active market and translates server time; the code itself never comes from the API', async () => {
    fetchMock.mockResolvedValue(json(200, challenge()))
    const otp = await repo.requestOtp('+919876543210')
    expect(String(fetchMock.mock.calls[0][0])).toMatch(/\/auth\/customer\/otp\/request$/)
    expect(body(0)).toEqual({ phone: '+919876543210', country: 'IN' })
    expect(headers(0).Authorization).toBeUndefined()
    expect(otp).toMatchObject({ phone: '+919876543210', attemptsAllowed: 5, expiresAt: NOW + 300_000, resendAfter: NOW + 30_000, devOtp: '123456' })
  })

  it('a wrong device clock does not change the displayed validity (server time is the reference)', async () => {
    vi.setSystemTime(NOW + 3_600_000) // device is an hour ahead
    fetchMock.mockResolvedValue(json(200, challenge()))
    const otp = await repo.requestOtp('+919876543210')
    expect(otp.expiresAt - Date.now()).toBe(300_000)
    expect(otp.resendAfter - Date.now()).toBe(30_000)
  })

  it('live delivery: no development code; the channel and the resend channel are passed to the screen', async () => {
    fetchMock.mockResolvedValue(json(200, challenge({ delivery: 'live', channel: 'whatsapp', resend_channel: 'sms', channels: ['whatsapp', 'sms'] })))
    const otp = await repo.requestOtp('+919876543210')
    expect(otp.devOtp).toBeUndefined()
    expect([otp.channel, otp.resendChannel]).toEqual(['whatsapp', 'sms'])
  })

  it('development delivery: the channel is not shown because nothing was sent', async () => {
    fetchMock.mockResolvedValue(json(200, challenge({ channel: 'sms', resend_channel: 'sms' })))
    const otp = await repo.requestOtp('+919876543210')
    expect([otp.channel, otp.resendChannel, otp.devOtp]).toEqual([undefined, undefined, '123456'])
  })

  it('existing customer: verifies with the challenge and keeps the token in sessionStorage only', async () => {
    fetchMock.mockResolvedValueOnce(json(200, challenge())).mockResolvedValueOnce(json(200, session()))
    await repo.requestOtp('+919876543210')
    const result = await repo.verifyOtp('+919876543210', '123456')
    expect(body(1)).toEqual({ phone: '+919876543210', country: 'IN', challenge_id: '11111111-1111-4111-8111-111111111111', code: '123456', device_name: 'web' })
    expect(result).toEqual({ status: 'authenticated', user: { id: 'c0ffee00-0000-4000-8000-000000000001', name: 'Rahul Sharma', phone: '+919876543210', email: 'rahul@example.com', memberSince: '2026-01-12' } })
    expect(tokens.get('customer')).toBe('12|customer-token')
    expect(JSON.stringify({ ...localStorage })).not.toContain('customer-token')
  })

  it('new customer: not a session until the profile is completed, then the same token becomes the session', async () => {
    fetchMock.mockResolvedValueOnce(json(200, challenge()))
      .mockResolvedValueOnce(json(201, session({ new_account: true }, { name: null, email: null, profile_complete: false })))
      .mockResolvedValueOnce(json(200, principal({ name: 'Asha Verma', email: null })))
    await repo.requestOtp('+919123456780')
    expect(await repo.verifyOtp('+919123456780', '123456')).toEqual({ status: 'setup_required', setupToken: 'pending-profile' })
    expect(tokens.get('customer')).toBeNull()
    expect(await repo.getCurrentUser()).toBeNull()

    const user = await repo.completeSetup('pending-profile', { name: '  Asha Verma ', acceptTerms: true })
    expect(String(fetchMock.mock.calls[2][0])).toMatch(/\/auth\/customer\/profile$/)
    expect(body(2)).toEqual({ name: 'Asha Verma', accept_terms: true })
    expect(headers(2).Authorization).toBe('Bearer 12|customer-token')
    expect(user.name).toBe('Asha Verma')
    expect(tokens.get('customer')).toBe('12|customer-token')
  })

  it('maps backend OTP errors onto the messages the screens already show', async () => {
    fetchMock.mockResolvedValue(json(200, challenge())); await repo.requestOtp('+919876543210')
    const cases: Array<[number, Record<string, unknown>, string, number | undefined]> = [
      [422, { code: 'otp_invalid', message: 'That code is not correct.', details: { attempts_left: 2 } }, 'invalid_otp', 2],
      [422, { code: 'otp_invalid', message: 'This code is invalid or has expired.' }, 'expired_otp', undefined],
      [422, { code: 'otp_expired', message: 'This code has expired.' }, 'expired_otp', undefined],
      [429, { code: 'otp_attempts_exceeded', message: 'Too many incorrect attempts.', details: { attempts_left: 0 } }, 'too_many_attempts', 0],
      [403, { code: 'account_not_active', message: 'This account cannot sign in right now.' }, 'account_blocked', undefined],
    ]
    for (const [status, error, code, left] of cases) {
      fetchMock.mockResolvedValueOnce(json(status, { error }))
      const e = await failure(repo.verifyOtp('+919876543210', '000000'))
      expect([e.code, e.attemptsLeft]).toEqual([code, left])
    }
    expect((await failure(repo.verifyOtp('+919000000000', '123456'))).code).toBe('expired_otp') // no challenge for that phone
    expect(tokens.get('customer')).toBeNull()
  })

  it('429: tells the customer how long to wait, without security detail', async () => {
    fetchMock.mockResolvedValueOnce(json(429, { error: { code: 'otp_resend_too_soon', message: 'Please wait a moment before requesting another code.', details: { retry_after_seconds: 24 } } }))
    const soon = await failure(repo.requestOtp('+919876543210'))
    expect([soon.code, soon.retryAfterSeconds]).toEqual(['rate_limited', 24]); expect(soon.message).toContain('24 seconds')
    fetchMock.mockResolvedValueOnce(json(429, { error: { code: 'rate_limited', message: 'Too many requests.', details: { retry_after_seconds: 40 } } }, { 'Retry-After': '40' }))
    const limited = await failure(repo.requestOtp('+919876543210'))
    expect(limited.code).toBe('rate_limited'); expect(limited.message).toMatch(/40 seconds/)
    fetchMock.mockResolvedValueOnce(json(429, { error: { code: 'otp_send_limit', message: 'Too many codes.', details: { retry_after_seconds: 3600 } } }))
    expect((await failure(repo.requestOtp('+919876543210'))).message).toMatch(/later/)
  })

  it('network failure, delivery failure, invalid phone and unavailable market are distinct, friendly errors', async () => {
    fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'))
    expect((await failure(repo.requestOtp('+919876543210'))).code).toBe('network')
    fetchMock.mockResolvedValueOnce(json(503, { error: { code: 'otp_delivery_failed', message: 'x' } }))
    expect((await failure(repo.requestOtp('+919876543210'))).code).toBe('send_failed')
    fetchMock.mockResolvedValueOnce(json(422, { error: { code: 'validation_failed', message: 'x', details: { fields: { phone: ['Enter a valid mobile number for India.'] } } } }))
    const invalid = await failure(repo.requestOtp('+9112345')); expect([invalid.code, invalid.message]).toEqual(['invalid_phone', 'Enter a valid mobile number for India.'])
    fetchMock.mockResolvedValueOnce(json(404, { error: { code: 'market_unavailable', message: 'x' } }))
    expect((await failure(repo.requestOtp('+15550001111'))).code).toBe('invalid_phone')
    fetchMock.mockResolvedValueOnce(json(500, { error: { code: 'internal_error', message: 'SQLSTATE secret detail' } }))
    expect((await failure(repo.requestOtp('+919876543210'))).message).not.toMatch(/SQLSTATE/)
  })

  it('session restore: validates the stored token with the backend', async () => {
    expect(await repo.refreshSession()).toBeNull(); expect(fetchMock).not.toHaveBeenCalled()
    tokens.set('customer', '12|customer-token')
    fetchMock.mockResolvedValueOnce(json(200, principal()))
    expect((await repo.refreshSession())?.name).toBe('Rahul Sharma')
    expect(headers(0).Authorization).toBe('Bearer 12|customer-token')
  })

  it('401 (expired / revoked) ends the session once; 403 (suspended) ends it with an explanation; a network error keeps the token', async () => {
    tokens.set('customer', '12|customer-token')
    fetchMock.mockRejectedValueOnce(new TypeError('offline'))
    expect((await failure(repo.refreshSession())).code).toBe('network'); expect(tokens.get('customer')).toBe('12|customer-token')

    fetchMock.mockResolvedValueOnce(json(401, { error: { code: 'unauthenticated', message: 'Authentication is required.' } }))
    expect((await failure(repo.refreshSession())).code).toBe('session_expired'); expect(tokens.get('customer')).toBeNull()
    expect(await repo.refreshSession()).toBeNull() // no loop: nothing is retried without a token

    tokens.set('customer', '13|suspended')
    fetchMock.mockResolvedValueOnce(json(403, { error: { code: 'account_not_active', message: 'This account cannot be used right now.' } }))
    const blocked = await failure(repo.refreshSession())
    expect(blocked.code).toBe('session_expired'); expect(tokens.get('customer')).toBeNull()
  })

  it('profile update and logout use the session token; logout always clears it locally', async () => {
    tokens.set('customer', '12|customer-token')
    fetchMock.mockResolvedValueOnce(json(200, principal({ name: 'Rahul S.' })))
    expect((await repo.updateProfile({ name: 'Rahul S.' })).name).toBe('Rahul S.')
    expect(body(0)).toEqual({ name: 'Rahul S.' })
    fetchMock.mockRejectedValueOnce(new TypeError('offline'))
    await repo.logout()
    expect(String(fetchMock.mock.calls[1][0])).toMatch(/\/auth\/logout$/)
    expect(tokens.get('customer')).toBeNull()
  })
})

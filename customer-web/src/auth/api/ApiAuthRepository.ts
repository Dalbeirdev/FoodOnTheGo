/**
 * Customer authentication against the real backend (Module 21): phone + one-time code.
 *
 * Same interface as MockAuthRepository, so no page changes. What differs from the mock:
 *  - the backend owns the code, its expiry, the attempt limit and the resend cooldown; the timestamps returned here
 *    are translated from server time, and the on-screen countdown is only a hint;
 *  - the code is never returned by the API. In a local development build the screen shows the local test code from
 *    VITE_DEV_OTP, and only when the backend says the delivery is "development";
 *  - a new customer is signed in by the backend straight away; the account-setup step then completes the profile
 *    (name, terms). Until that is done the token is held aside and is not treated as a session.
 */
import { ApiError, api, tokens } from '../../api/client'
import { marketRepository } from '../../market/mock/mockMarket'
import { AuthError, type AuthRepository, type AuthUser, type OtpRequest, type VerifyResult } from '../repository'

type PrincipalDto = { principal_type: string; id: string; status: string; name: string | null; email: string | null; phone: string; phone_masked: string; phone_verified: boolean; profile_complete: boolean; market: string | null; member_since: string | null }
type ChallengeDto = { challenge_id: string; phone_masked: string; expires_at: string; resend_available_at: string; attempts_allowed: number; server_time: string; delivery: 'sms' | 'development' }
type SessionDto = { token: string; expires_at: string | null; new_account: boolean; principal: PrincipalDto }

const KEY_CHALLENGE = 'fotg.auth.challenge'
const KEY_PENDING_TOKEN = 'fotg.auth.token.customer.pending'
const ss = {
  get<T>(key: string): T | null { try { const v = sessionStorage.getItem(key); return v ? (JSON.parse(v) as T) : null } catch { return null } },
  set(key: string, value: unknown) { try { if (value === null) sessionStorage.removeItem(key); else sessionStorage.setItem(key, JSON.stringify(value)) } catch { /* unavailable */ } },
}

const toUser = (p: PrincipalDto): AuthUser => ({ id: p.id, name: p.name ?? '', phone: p.phone, email: p.email, memberSince: p.member_since ?? new Date().toISOString().slice(0, 10) })
const wait = (seconds: number | null) => (seconds && seconds > 0 ? (seconds >= 90 ? ` Try again in about ${Math.ceil(seconds / 60)} minutes.` : ` Try again in ${seconds} seconds.`) : ' Please try again shortly.')

/** Backend error → the AuthError vocabulary the pages already understand. Never exposes security internals. */
export function toAuthError(e: unknown): AuthError {
  if (e instanceof AuthError) return e
  if (!(e instanceof ApiError)) return new AuthError('unexpected', 'Something went wrong. Please try again.')
  if (e.kind === 'network') return new AuthError('network', 'Cannot reach FoodOnTheGo right now. Check your connection and try again.')
  switch (e.code) {
    case 'otp_invalid': { const left = typeof e.details.attempts_left === 'number' ? e.details.attempts_left : undefined
      return left === undefined ? new AuthError('expired_otp', 'This code is no longer valid. Request a new one.') : new AuthError('invalid_otp', `Incorrect code. ${left} ${left === 1 ? 'attempt' : 'attempts'} left.`, left) }
    case 'otp_expired': return new AuthError('expired_otp', 'Your code has expired. Request a new one.')
    case 'otp_attempts_exceeded': return new AuthError('too_many_attempts', 'Too many incorrect attempts. Request a new code.', 0)
    case 'otp_resend_too_soon': return new AuthError('rate_limited', `Please wait before requesting another code.${wait(e.retryAfterSeconds)}`, undefined, e.retryAfterSeconds)
    case 'otp_send_limit': return new AuthError('rate_limited', 'Too many codes were requested for this number. Please try again later.', undefined, e.retryAfterSeconds)
    case 'otp_delivery_failed': return new AuthError('send_failed', "We couldn't send the code right now. Please try again in a moment.")
    case 'market_unavailable': return new AuthError('invalid_phone', 'FoodOnTheGo is not available for this country yet.')
    case 'account_not_active': return new AuthError('account_blocked', 'This account cannot sign in right now. Please contact support.')
  }
  if (e.kind === 'rate_limited') return new AuthError('rate_limited', `Too many attempts.${wait(e.retryAfterSeconds)}`, undefined, e.retryAfterSeconds)
  if (e.kind === 'validation') return new AuthError(e.field('phone') ? 'invalid_phone' : 'unexpected', e.field('phone') ?? e.field('code') ?? e.field('name') ?? e.field('email') ?? 'Please check what you entered.')
  if (e.kind === 'unauthenticated') return new AuthError('session_expired', 'Your session has expired. Please sign in again.')
  return new AuthError('unexpected', 'Something went wrong on our side. Please try again.')
}

export class ApiAuthRepository implements AuthRepository {
  private country(): string { return marketRepository.getActiveMarket().countryCode }

  async requestOtp(phone: string): Promise<OtpRequest> {
    try {
      const c = await api<ChallengeDto>('/auth/customer/otp/request', { method: 'POST', auth: false, body: { phone, country: this.country() } })
      ss.set(KEY_CHALLENGE, { phone, id: c.challenge_id })
      // Server timestamps → this device's clock, so a wrong device clock cannot shorten or extend the display.
      const skew = Date.now() - Date.parse(c.server_time)
      const devOtp = c.delivery === 'development' ? (import.meta.env.VITE_DEV_OTP as string | undefined) || undefined : undefined
      return { phone, expiresAt: Date.parse(c.expires_at) + skew, resendAfter: Date.parse(c.resend_available_at) + skew, attemptsAllowed: c.attempts_allowed, devOtp }
    } catch (e) { throw toAuthError(e) }
  }

  async verifyOtp(phone: string, code: string): Promise<VerifyResult> {
    const challenge = ss.get<{ phone: string; id: string }>(KEY_CHALLENGE)
    if (!challenge || challenge.phone !== phone) throw new AuthError('expired_otp', 'This code is no longer valid. Request a new one.')
    try {
      const s = await api<SessionDto>('/auth/customer/otp/verify', { method: 'POST', auth: false, body: { phone, country: this.country(), challenge_id: challenge.id, code, device_name: 'web' } })
      ss.set(KEY_CHALLENGE, null)
      if (s.principal.profile_complete) { tokens.set('customer', s.token); ss.set(KEY_PENDING_TOKEN, null); return { status: 'authenticated', user: toUser(s.principal) } }
      ss.set(KEY_PENDING_TOKEN, s.token)
      return { status: 'setup_required', setupToken: 'pending-profile' }
    } catch (e) { throw toAuthError(e) }
  }

  async completeSetup(_setupToken: string, input: { name: string; email?: string; acceptTerms: boolean }): Promise<AuthUser> {
    const token = ss.get<string>(KEY_PENDING_TOKEN)
    if (!token) throw new AuthError('expired_otp', 'Your verification has expired. Please sign in again.')
    if (!input.acceptTerms) throw new AuthError('unexpected', 'Please accept the Terms and Privacy Policy to continue.')
    try {
      const p = await api<PrincipalDto>('/auth/customer/profile', { method: 'PATCH', token, body: { name: input.name.trim(), ...(input.email?.trim() ? { email: input.email.trim() } : {}), accept_terms: true } })
      tokens.set('customer', token); ss.set(KEY_PENDING_TOKEN, null)
      return toUser(p)
    } catch (e) {
      const err = toAuthError(e)
      if (err.code === 'session_expired') { ss.set(KEY_PENDING_TOKEN, null); throw new AuthError('expired_otp', 'Your verification has expired. Please sign in again.') }
      throw err
    }
  }

  async getCurrentUser(): Promise<AuthUser | null> {
    if (!tokens.get('customer')) return null
    try {
      const p = await api<PrincipalDto>('/auth/me')
      return p.principal_type === 'CUSTOMER' && p.profile_complete ? toUser(p) : null
    } catch (e) {
      if (e instanceof ApiError && e.kind === 'forbidden') { tokens.set('customer', null); throw new AuthError('session_expired', 'This account cannot be used right now. Please contact support.') }
      throw toAuthError(e)
    }
  }

  /** The backend session has a fixed lifetime; "refresh" re-validates it (token, account state) on load. */
  refreshSession(): Promise<AuthUser | null> { return this.getCurrentUser() }

  async updateProfile(patch: { name?: string; email?: string | null }): Promise<AuthUser> {
    try { return toUser(await api<PrincipalDto>('/auth/customer/profile', { method: 'PATCH', body: { ...(patch.name !== undefined && { name: patch.name }), ...(patch.email !== undefined && { email: patch.email }) } })) } catch (e) { throw toAuthError(e) }
  }

  async logout(): Promise<void> {
    try { if (tokens.get('customer')) await api('/auth/logout', { method: 'POST' }) } catch { /* the local session ends either way */ }
    tokens.set('customer', null); ss.set(KEY_CHALLENGE, null); ss.set(KEY_PENDING_TOKEN, null)
  }
}

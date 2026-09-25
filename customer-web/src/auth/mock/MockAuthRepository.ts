import { AuthError, type AuthRepository, type AuthUser, type OtpRequest, type VerifyResult } from '../repository'

/**
 * DEVELOPMENT-ONLY mock authentication. Simulates the phone + OTP contract safely:
 *  - OTP is the controlled development code (default 123456) — never used in production.
 *  - Sessions live in sessionStorage (cleared when the tab closes); the "customer directory"
 *    lives in localStorage so existing/new customer flows survive reloads on this browser.
 *  - Special test numbers reproduce failure states (see TEST_NUMBERS).
 * Tracked in project-progress.html as MOCK AUTH SERVICE; replaced by ApiAuthRepository later.
 */
export const DEV_OTP = '123456'
export const OTP_TTL_MS = 2 * 60_000
export const RESEND_AFTER_MS = 30_000
export const ATTEMPTS_ALLOWED = 3
export const SESSION_TTL_MS = 8 * 60 * 60_000

/** Test numbers (India) that force specific outcomes. */
export const TEST_NUMBERS = {
  existingCustomer: '+919876543210', // Rahul Sharma, already registered
  sendFailure: '+919999900000',      // OTP send failure
  networkDown: '+919999900001',      // network unavailable
  unexpected: '+919999900002',       // unexpected server error
}

const KEY_SESSION = 'fotg.mock.session'
const KEY_DIRECTORY = 'fotg.mock.customers'
const KEY_OTP = 'fotg.mock.otp'

type Session = { token: string; userId: string; expiresAt: number }
type PendingOtp = { phone: string; code: string; expiresAt: number; attempts: number }

const storage = {
  get<T>(store: Storage, key: string): T | null { try { const v = store.getItem(key); return v ? (JSON.parse(v) as T) : null } catch { return null } },
  set(store: Storage, key: string, value: unknown) { try { value === null ? store.removeItem(key) : store.setItem(key, JSON.stringify(value)) } catch { /* unavailable */ } },
}

const delay = (ms: number) => new Promise((r) => setTimeout(r, ms))
const uid = () => (crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`)

export class MockAuthRepository implements AuthRepository {
  private readonly latencyMs: number
  private readonly now: () => number
  constructor(latencyMs = 500, now: () => number = () => Date.now()) {
    this.latencyMs = latencyMs
    this.now = now
    if (!storage.get<AuthUser[]>(localStorage, KEY_DIRECTORY)) {
      storage.set(localStorage, KEY_DIRECTORY, [{ id: 'cust-rahul', name: 'Rahul Sharma', phone: TEST_NUMBERS.existingCustomer, email: 'rahul.sharma@example.com', memberSince: '2025-01-12' }])
    }
  }

  private directory(): AuthUser[] { return storage.get<AuthUser[]>(localStorage, KEY_DIRECTORY) ?? [] }
  private saveDirectory(list: AuthUser[]) { storage.set(localStorage, KEY_DIRECTORY, list) }
  private session(): Session | null { return storage.get<Session>(sessionStorage, KEY_SESSION) }

  async requestOtp(phone: string): Promise<OtpRequest> {
    await delay(this.latencyMs)
    if (phone === TEST_NUMBERS.sendFailure) throw new AuthError('send_failed', "We couldn't send the code right now. Please try again in a moment.")
    if (phone === TEST_NUMBERS.networkDown) throw new AuthError('network', 'No internet connection. Check your network and try again.')
    if (phone === TEST_NUMBERS.unexpected) throw new AuthError('unexpected', 'Something went wrong on our side. Please try again.')
    const expiresAt = this.now() + OTP_TTL_MS
    storage.set(sessionStorage, KEY_OTP, { phone, code: DEV_OTP, expiresAt, attempts: 0 } satisfies PendingOtp)
    return { phone, expiresAt, resendAfter: this.now() + RESEND_AFTER_MS, attemptsAllowed: ATTEMPTS_ALLOWED, devOtp: DEV_OTP }
  }

  async verifyOtp(phone: string, code: string): Promise<VerifyResult> {
    await delay(this.latencyMs)
    const pending = storage.get<PendingOtp>(sessionStorage, KEY_OTP)
    if (!pending || pending.phone !== phone) throw new AuthError('expired_otp', 'This code is no longer valid. Request a new one.')
    if (this.now() > pending.expiresAt) { storage.set(sessionStorage, KEY_OTP, null); throw new AuthError('expired_otp', 'Your code has expired. Request a new one.') }
    if (pending.attempts >= ATTEMPTS_ALLOWED) { storage.set(sessionStorage, KEY_OTP, null); throw new AuthError('too_many_attempts', 'Too many incorrect attempts. Request a new code.', 0) }
    if (code !== pending.code) {
      pending.attempts += 1
      const left = ATTEMPTS_ALLOWED - pending.attempts
      if (left <= 0) { storage.set(sessionStorage, KEY_OTP, null); throw new AuthError('too_many_attempts', 'Too many incorrect attempts. Request a new code.', 0) }
      storage.set(sessionStorage, KEY_OTP, pending)
      throw new AuthError('invalid_otp', `Incorrect code. ${left} ${left === 1 ? 'attempt' : 'attempts'} left.`, left)
    }
    storage.set(sessionStorage, KEY_OTP, null)
    const existing = this.directory().find((u) => u.phone === phone)
    if (existing) { this.startSession(existing); return { status: 'authenticated', user: existing } }
    const setupToken = `setup:${phone}:${uid()}`
    storage.set(sessionStorage, 'fotg.mock.setup', { setupToken, phone })
    return { status: 'setup_required', setupToken }
  }

  async completeSetup(setupToken: string, input: { name: string; email?: string; acceptTerms: boolean }): Promise<AuthUser> {
    await delay(this.latencyMs)
    const pending = storage.get<{ setupToken: string; phone: string }>(sessionStorage, 'fotg.mock.setup')
    if (!pending || pending.setupToken !== setupToken) throw new AuthError('expired_otp', 'Your verification has expired. Please sign in again.')
    if (!input.acceptTerms) throw new AuthError('unexpected', 'Please accept the Terms and Privacy Policy to continue.')
    const user: AuthUser = { id: `cust-${uid()}`, name: input.name.trim(), phone: pending.phone, email: input.email?.trim() || null, memberSince: new Date(this.now()).toISOString().slice(0, 10) }
    this.saveDirectory([...this.directory(), user])
    storage.set(sessionStorage, 'fotg.mock.setup', null)
    this.startSession(user)
    return user
  }

  async getCurrentUser(): Promise<AuthUser | null> {
    const s = this.session()
    if (!s) return null
    if (this.now() > s.expiresAt) { storage.set(sessionStorage, KEY_SESSION, null); throw new AuthError('session_expired', 'Your session has expired. Please sign in again.') }
    return this.directory().find((u) => u.id === s.userId) ?? null
  }

  async refreshSession(): Promise<AuthUser | null> {
    const user = await this.getCurrentUser()
    if (user) { const s = this.session()!; storage.set(sessionStorage, KEY_SESSION, { ...s, expiresAt: this.now() + SESSION_TTL_MS }) }
    return user
  }

  async logout(): Promise<void> {
    await delay(Math.min(this.latencyMs, 200))
    storage.set(sessionStorage, KEY_SESSION, null)
    storage.set(sessionStorage, KEY_OTP, null)
  }

  /** DEV helper for tests/e2e: make the current session expire immediately. */
  expireSessionNow() { const s = this.session(); if (s) storage.set(sessionStorage, KEY_SESSION, { ...s, expiresAt: this.now() - 1 }) }

  private startSession(user: AuthUser) { storage.set(sessionStorage, KEY_SESSION, { token: uid(), userId: user.id, expiresAt: this.now() + SESSION_TTL_MS } satisfies Session) }
}

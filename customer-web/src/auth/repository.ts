/**
 * Authentication repository abstraction (phone + OTP).
 *
 * Two implementations, chosen by auth/authMode.ts: ApiAuthRepository (the real backend, Module 21) and
 * MockAuthRepository (development mock; unit tests and share builds). Nothing in the pages knows which one runs.
 */
export type AuthUser = { id: string; name: string; phone: string; email: string | null; memberSince: string }

/** How a code was (or will be) delivered. Set only when a real message was sent; absent in local / mock runs. */
export type OtpChannel = 'sms' | 'whatsapp'
export type OtpRequest = { phone: string; expiresAt: number; resendAfter: number; attemptsAllowed: number; channel?: OtpChannel; resendChannel?: OtpChannel; /** DEV ONLY: the local test code, shown in development builds so testers can sign in. Never sent by the backend. */ devOtp?: string }

export type VerifyResult = { status: 'authenticated'; user: AuthUser } | { status: 'setup_required'; setupToken: string }

export type AuthErrorCode = 'invalid_phone' | 'send_failed' | 'network' | 'invalid_otp' | 'expired_otp' | 'too_many_attempts' | 'session_expired' | 'unexpected'
  /** The backend is throttling this action (429); retryAfterSeconds says how long. */
  | 'rate_limited'
  /** The account exists but may not sign in (suspended / deactivated). */
  | 'account_blocked'

export class AuthError extends Error {
  code: AuthErrorCode
  attemptsLeft?: number
  retryAfterSeconds?: number | null
  constructor(code: AuthErrorCode, message: string, attemptsLeft?: number, retryAfterSeconds?: number | null) {
    super(message)
    this.code = code
    this.attemptsLeft = attemptsLeft
    this.retryAfterSeconds = retryAfterSeconds
  }
}

export interface AuthRepository {
  requestOtp(phone: string): Promise<OtpRequest>
  verifyOtp(phone: string, code: string): Promise<VerifyResult>
  completeSetup(setupToken: string, input: { name: string; email?: string; acceptTerms: boolean }): Promise<AuthUser>
  getCurrentUser(): Promise<AuthUser | null>
  refreshSession(): Promise<AuthUser | null>
  updateProfile(patch: { name?: string; email?: string | null }): Promise<AuthUser>
  logout(): Promise<void>
}

/** Routes anyone may use without signing in (documented guest rules). */
export const GUEST_ROUTES = ['/', '/how-it-works', '/about-us', '/restaurants', '/restaurants/:slug', '/restaurant/:slug/item/:id', '/cart', '/plan-journey', '/help', '/for-restaurants', '/get-app', '/terms', '/privacy', '/refund-policy', '/cookie-policy', '/login', '/verify-otp', '/account-setup']
/** Actions that require an account. */
export const PROTECTED_ROUTES = ['/checkout', '/my-orders', '/order/:number', '/order-confirmation/:number', '/order-tracking/:number', '/my-profile', '/favorites', '/addresses', '/payment-methods', '/notifications']

/**
 * Authentication repository abstraction (Module 03: phone + OTP, frontend-first).
 *
 * MockAuthRepository is the ONLY implementation in this module. ApiAuthRepository
 * will talk to the Laravel OTP endpoints in the backend module and must replace the
 * mock without UI changes. Nothing in the pages knows which implementation runs.
 */
export type AuthUser = { id: string; name: string; phone: string; email: string | null; memberSince: string }

export type OtpRequest = { phone: string; expiresAt: number; resendAfter: number; attemptsAllowed: number; /** DEV ONLY: set by the mock so testers can see the code */ devOtp?: string }

export type VerifyResult = { status: 'authenticated'; user: AuthUser } | { status: 'setup_required'; setupToken: string }

export type AuthErrorCode = 'invalid_phone' | 'send_failed' | 'network' | 'invalid_otp' | 'expired_otp' | 'too_many_attempts' | 'session_expired' | 'unexpected'

export class AuthError extends Error {
  code: AuthErrorCode
  attemptsLeft?: number
  constructor(code: AuthErrorCode, message: string, attemptsLeft?: number) {
    super(message)
    this.code = code
    this.attemptsLeft = attemptsLeft
  }
}

export interface AuthRepository {
  requestOtp(phone: string): Promise<OtpRequest>
  verifyOtp(phone: string, code: string): Promise<VerifyResult>
  completeSetup(setupToken: string, input: { name: string; email?: string; acceptTerms: boolean }): Promise<AuthUser>
  getCurrentUser(): Promise<AuthUser | null>
  refreshSession(): Promise<AuthUser | null>
  logout(): Promise<void>
}

/** Routes anyone may use without signing in (documented guest rules). */
export const GUEST_ROUTES = ['/', '/how-it-works', '/about-us', '/restaurants', '/restaurants/:slug', '/restaurant/:slug/item/:id', '/cart', '/plan-journey', '/help', '/for-restaurants', '/get-app', '/terms', '/privacy', '/refund-policy', '/cookie-policy', '/login', '/verify-otp', '/account-setup']
/** Actions that require an account. */
export const PROTECTED_ROUTES = ['/checkout', '/my-orders', '/order/:number', '/order-confirmation/:number', '/order-tracking/:number', '/my-profile', '/favorites', '/addresses', '/payment-methods', '/notifications']

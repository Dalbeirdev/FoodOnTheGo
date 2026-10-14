import { isProductionBuild, type BuildEnv } from '../config/modeOverride'

/**
 * Module 39 (C-03): when the sign-in screens may show development help — the local test code and the fixture phone
 * numbers.
 *
 * Before Module 39 the code came from VITE_DEV_OTP whenever the backend said the delivery was "development", and the
 * login page showed its hint whenever VITE_DEV_OTP was set, without asking the server. Nothing stopped a deployable
 * build from being made with a developer's `.env.local`. Now:
 *  - a production build (`vite build`) never shows the code, whatever VITE_DEV_OTP holds;
 *  - a development build shows it only next to a challenge the backend itself answered with `delivery: 'development'`
 *    (a backend that really sends an SMS answers `live`, and then no code is shown even on a developer's machine);
 *  - the login page, which has no challenge yet, shows its API hint only after the backend has answered
 *    `development` in this tab.
 * The static share build is the one production build that keeps the MOCK hint: it is a mock-only preview with no
 * backend, and its reviewers need the mock code to get in.
 *
 * `env` is a parameter only so that tests can hand in a production-shaped environment; callers never pass it.
 */
type OtpEnv = BuildEnv & { VITE_DEV_OTP?: string }
const currentEnv = (): OtpEnv => import.meta.env as OtpEnv
const DELIVERY_KEY = 'fotg.auth.otp.delivery'

/** The local test code to show with a challenge, or undefined (always undefined in a production build). */
export function developmentOtp(delivery: string | null | undefined, env: OtpEnv = currentEnv()): string | undefined {
  if (isProductionBuild(env) || delivery !== 'development') return undefined
  return env.VITE_DEV_OTP || undefined
}

/** Whether the mock sign-in hints (the mock code, the test numbers) may be shown: never in a deployable build. */
export function developmentHintsAllowed(env: OtpEnv = currentEnv()): boolean {
  return !isProductionBuild(env) || env.VITE_SHARE_BUILD === '1'
}

/** Remembers, for this tab, how the backend said the last code was delivered (`live` or `development`). */
export function rememberOtpDelivery(delivery: string | null | undefined): void {
  try { if (delivery) sessionStorage.setItem(DELIVERY_KEY, delivery); else sessionStorage.removeItem(DELIVERY_KEY) } catch { /* storage unavailable */ }
}

/** Whether the login page may show its API hint: a development build whose backend answered `development` in this tab. */
export function apiSignInHintAllowed(env: OtpEnv = currentEnv()): boolean {
  if (isProductionBuild(env) || !env.VITE_DEV_OTP) return false
  try { return sessionStorage.getItem(DELIVERY_KEY) === 'development' } catch { return false }
}

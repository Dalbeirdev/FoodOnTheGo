/**
 * Module 41: what makes a RELEASE build of the web app unusable — checked by the `fotg-release-gate` plugin in
 * vite.config.ts at `vite build`, which stops the build when the list is not empty. Nothing here runs in the browser;
 * the file lives under src/ so that the unit tests can pin the rules (releaseBuild.test.ts).
 *
 * Why: every domain switch (`VITE_*_MODE`) falls back to the in-browser development data unless it is exactly `api`,
 * and with VITE_AUTH_MODE unset the Restaurant Dashboard and the Platform Admin open on development data without a
 * sign-in (SEC-44, Module 39 blocker B-6). A build therefore has to say which environment it is for:
 *
 *  - VITE_ENVIRONMENT=local      a developer build (the local preview): no further rule.
 *  - VITE_ENVIRONMENT=staging    \ every domain on the backend, the API over https, the realtime socket over wss with a
 *  - VITE_ENVIRONMENT=production / real host and key, no local test code.
 *  - not set / anything else     the build stops (fail closed: a forgotten variable never ships development data).
 *
 * The static share preview (VITE_SHARE_BUILD=1) is a mock-only build by design and is not judged here.
 */
export type ReleaseEnv = Record<string, string | undefined>

/** Every switch that must be `api` in a staging / production build (the ten domains, sign-in and realtime). */
export const RELEASE_MODES = ['VITE_AUTH_MODE', 'VITE_MARKET_MODE', 'VITE_RESTAURANT_MODE', 'VITE_JOURNEY_MODE', 'VITE_CART_MODE', 'VITE_PICKUP_MODE', 'VITE_CHECKOUT_MODE', 'VITE_PAYMENT_MODE', 'VITE_ORDER_MODE', 'VITE_REVIEW_MODE', 'VITE_REALTIME_MODE'] as const

const ENVIRONMENTS = ['local', 'staging', 'production']
const LOCAL_HOST = /^(localhost|127\.\d+\.\d+\.\d+|0\.0\.0\.0|10\.0\.2\.2|\[::1\])$/i

export function releaseConfigurationProblems(env: ReleaseEnv): string[] {
  if (env.VITE_SHARE_BUILD === '1') return []
  const environment = (env.VITE_ENVIRONMENT ?? '').trim().toLowerCase()
  if (!ENVIRONMENTS.includes(environment)) return [`VITE_ENVIRONMENT is ${environment ? `"${environment}"` : 'not set'} — set local, staging or production`]
  if (environment === 'local') return []
  const problems: string[] = []
  for (const key of RELEASE_MODES) if (env[key] !== 'api') problems.push(`${key} is ${env[key] ? `"${env[key]}"` : 'not set'}, not "api"`)
  const api = env.VITE_API_BASE_URL ?? ''
  if (!api) problems.push('VITE_API_BASE_URL is not set')
  else if (!api.startsWith('https://')) problems.push('VITE_API_BASE_URL is not an https:// address')
  else if (LOCAL_HOST.test(new URL(api).hostname)) problems.push('VITE_API_BASE_URL points at a local address')
  if (env.VITE_REVERB_SCHEME !== 'https') problems.push('VITE_REVERB_SCHEME is not "https" (the socket must be wss)')
  if (!env.VITE_REVERB_HOST || LOCAL_HOST.test(env.VITE_REVERB_HOST)) problems.push('VITE_REVERB_HOST is not set to the realtime host')
  if (!env.VITE_REVERB_APP_KEY) problems.push('VITE_REVERB_APP_KEY is not set')
  // The value is never printed — only that one is there.
  if (env.VITE_DEV_OTP) problems.push('VITE_DEV_OTP is set (a release build must not carry the local test code)')
  return problems
}

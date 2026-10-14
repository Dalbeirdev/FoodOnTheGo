/**
 * Module 39 (C-01): the Content-Security-Policy of a PRODUCTION build of the web app.
 *
 * Built at `vite build` time from the sources the app really uses, and injected into index.html as a
 * `<meta http-equiv="Content-Security-Policy">` by the `fotg-csp` plugin in vite.config.ts. Nothing here runs in the
 * browser; the file lives under src/ only so that the unit tests can pin the policy (contentSecurityPolicy.test.ts).
 *
 * Why each source is there:
 *  - script-src: the app's own bundle and the Razorpay checkout script, which the payment page injects from exactly
 *    that origin (payment/api/providerLauncher.ts). The Vite build has no inline script, so there is NO
 *    'unsafe-inline' and NO 'unsafe-eval' for scripts.
 *  - style-src: the app's own stylesheets and the Google Fonts stylesheet linked from index.html. NO 'unsafe-inline':
 *    the built index.html has no <style> block and no style="" attribute, and React's `style={…}` props are applied
 *    through the CSSOM (element.style), which a CSP does not govern.
 *  - font-src: the font files of Google Fonts (until the fonts are self-hosted, see C-09).
 *  - img-src: the app's own images, data: (the MFA QR code is a data URL made in the browser), blob: (download
 *    links, camera frames) and the API origin, whose public storage serves the menu and restaurant media.
 *  - connect-src: the API, the Reverb WebSocket when realtime is on, and the Razorpay API.
 *  - frame-src: the Razorpay checkout window (an iframe of Razorpay's own hosts).
 *  - object-src 'none', base-uri 'self', form-action 'self'.
 *
 * What a <meta> policy cannot carry — `frame-ancestors` (clickjacking), `report-uri` / `report-to`, HSTS,
 * X-Content-Type-Options, Referrer-Policy and Permissions-Policy (camera=(self) for the pickup QR scanner) — must be
 * sent as HTTP response headers by the web server that serves the app (Module 42, deployment). The Razorpay hosts are
 * the documented ones and are NOT VERIFIED against a live checkout (no credentials exist yet).
 */
export type CspEnv = {
  VITE_API_BASE_URL?: string
  VITE_REALTIME_MODE?: string
  VITE_ORDER_MODE?: string
  VITE_REVERB_HOST?: string
  VITE_REVERB_PORT?: string
  VITE_REVERB_SCHEME?: string
}

export const RAZORPAY_CHECKOUT_ORIGIN = 'https://checkout.razorpay.com'
export const RAZORPAY_API_ORIGIN = 'https://api.razorpay.com'
const GOOGLE_FONTS_CSS = 'https://fonts.googleapis.com'
const GOOGLE_FONTS_FILES = 'https://fonts.gstatic.com'
/** The same default as api/client.ts, so a build without VITE_API_BASE_URL allows the API the app will call. */
const DEFAULT_API_BASE_URL = 'http://127.0.0.1:8001/api/v1'

/** The origin of an absolute http(s) URL; null for a relative URL (then the API is 'self'). Anything else stops the build. */
export function apiOriginOf(baseUrl: string): string | null {
  if (!/^[a-z][a-z0-9+.-]*:/i.test(baseUrl)) return null
  const url = new URL(baseUrl)
  if (url.protocol !== 'http:' && url.protocol !== 'https:') throw new Error(`Content-Security-Policy: VITE_API_BASE_URL must be http(s), got ${url.protocol}`)
  return url.origin
}

/** The Reverb WebSocket origin (ws / wss, host, port) the realtime client connects to, or null when realtime is off. */
export function reverbOriginOf(env: CspEnv): string | null {
  // The same switch as realtime/echo.ts: the browser connects only when both modes are 'api'.
  if (env.VITE_REALTIME_MODE !== 'api' || env.VITE_ORDER_MODE !== 'api') return null
  const host = env.VITE_REVERB_HOST || '127.0.0.1'
  // A host goes into the policy text as is, so only a plain host name or IP address is accepted (no spaces, no ';').
  if (!/^([a-z0-9-]+\.)*[a-z0-9-]+$|^\[[0-9a-f:.]+\]$/i.test(host)) throw new Error(`Content-Security-Policy: VITE_REVERB_HOST is not a host name: ${host}`)
  const port = Number.parseInt(env.VITE_REVERB_PORT ?? '8085', 10) || 8085
  return `${env.VITE_REVERB_SCHEME === 'https' ? 'wss' : 'ws'}://${host}:${port}`
}

/** The policy text, one directive per entry, joined with "; ". */
export function contentSecurityPolicy(env: CspEnv): string {
  const api = apiOriginOf(env.VITE_API_BASE_URL || DEFAULT_API_BASE_URL)
  const reverb = reverbOriginOf(env)
  const list = (...sources: (string | null)[]) => sources.filter((s): s is string => !!s).join(' ')
  return [
    `default-src 'self'`,
    `script-src ${list(`'self'`, RAZORPAY_CHECKOUT_ORIGIN)}`,
    `style-src ${list(`'self'`, GOOGLE_FONTS_CSS)}`,
    `font-src ${list(`'self'`, GOOGLE_FONTS_FILES)}`,
    `img-src ${list(`'self'`, 'data:', 'blob:', api)}`,
    `connect-src ${list(`'self'`, api, reverb, RAZORPAY_API_ORIGIN)}`,
    `frame-src ${list(RAZORPAY_API_ORIGIN, RAZORPAY_CHECKOUT_ORIGIN)}`,
    `object-src 'none'`,
    `base-uri 'self'`,
    `form-action 'self'`,
  ].join('; ')
}

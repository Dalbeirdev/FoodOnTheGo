import { describe, expect, it } from 'vitest'
import { apiOriginOf, contentSecurityPolicy, reverbOriginOf } from './contentSecurityPolicy'

/** Module 39 (C-01): the production Content-Security-Policy, as vite.config.ts writes it into index.html. */
const directives = (policy: string) => Object.fromEntries(policy.split('; ').map((d) => { const [name, ...sources] = d.split(' '); return [name, sources] }))

describe('Content-Security-Policy of a production build (Module 39)', () => {
  const local = { VITE_API_BASE_URL: 'http://127.0.0.1:8001/api/v1', VITE_REALTIME_MODE: 'api', VITE_ORDER_MODE: 'api', VITE_REVERB_HOST: '127.0.0.1', VITE_REVERB_PORT: '8085', VITE_REVERB_SCHEME: 'http' }

  it('allows exactly the app, the API, Reverb, Razorpay and Google Fonts', () => {
    const d = directives(contentSecurityPolicy(local))
    expect(d['default-src']).toEqual(["'self'"])
    expect(d['script-src']).toEqual(["'self'", 'https://checkout.razorpay.com'])
    expect(d['style-src']).toEqual(["'self'", 'https://fonts.googleapis.com'])
    expect(d['font-src']).toEqual(["'self'", 'https://fonts.gstatic.com'])
    expect(d['img-src']).toEqual(["'self'", 'data:', 'blob:', 'http://127.0.0.1:8001'])
    expect(d['connect-src']).toEqual(["'self'", 'http://127.0.0.1:8001', 'ws://127.0.0.1:8085', 'https://api.razorpay.com'])
    expect(d['frame-src']).toEqual(['https://api.razorpay.com', 'https://checkout.razorpay.com'])
    expect(d['object-src']).toEqual(["'none'"])
    expect(d['base-uri']).toEqual(["'self'"])
    expect(d['form-action']).toEqual(["'self'"])
  })

  it('never allows inline or eval script, nor inline style', () => {
    const policy = contentSecurityPolicy(local)
    expect(policy).not.toContain('unsafe-inline')
    expect(policy).not.toContain('unsafe-eval')
  })

  it('takes its origins from the build settings: https API, wss Reverb, realtime off, relative API', () => {
    const live = contentSecurityPolicy({ VITE_API_BASE_URL: 'https://api.foodonthego.example/api/v1', VITE_REALTIME_MODE: 'api', VITE_ORDER_MODE: 'api', VITE_REVERB_HOST: 'ws.foodonthego.example', VITE_REVERB_PORT: '443', VITE_REVERB_SCHEME: 'https' })
    expect(directives(live)['connect-src']).toEqual(["'self'", 'https://api.foodonthego.example', 'wss://ws.foodonthego.example:443', 'https://api.razorpay.com'])
    expect(reverbOriginOf({ ...local, VITE_REALTIME_MODE: 'off' })).toBeNull()
    expect(apiOriginOf('/api/v1')).toBeNull()
    expect(directives(contentSecurityPolicy({ VITE_API_BASE_URL: '/api/v1' }))['connect-src']).toEqual(["'self'", 'https://api.razorpay.com'])
  })

  it('refuses a setting that would inject into the policy', () => {
    expect(() => reverbOriginOf({ ...local, VITE_REVERB_HOST: "x; script-src 'unsafe-inline'" })).toThrow(/not a host name/)
    expect(() => apiOriginOf('javascript:alert(1)')).toThrow(/http\(s\)/)
  })
})

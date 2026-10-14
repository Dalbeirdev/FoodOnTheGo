import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { authMode } from '../auth/authMode'
import { fixtureScope, setFixtureScope } from '../market/fixtureScope'
import { marketMode } from '../market/marketMode'
import { analyticsMode } from '../analytics/analyticsMode'
import { modeOverride, modeOverridesAllowed } from './modeOverride'

/**
 * Module 39 (C-02): a localStorage mode switch is a development tool. A production build (`vite build`) ignores it,
 * and the build-time VITE_*_MODE value decides.
 */
const production = () => { vi.stubEnv('DEV', false); vi.stubEnv('PROD', true); vi.stubEnv('MODE', 'production') }

beforeEach(() => { localStorage.clear() })
afterEach(() => { vi.unstubAllEnvs(); localStorage.clear(); setFixtureScope('global') })

describe('mode overrides (Module 39)', () => {
  it('are honoured in a development build only', () => {
    expect(modeOverridesAllowed({ DEV: true, PROD: false })).toBe(true)
    expect(modeOverridesAllowed({ DEV: false, PROD: true })).toBe(false)
    expect(modeOverridesAllowed({ DEV: false, PROD: true, VITE_SHARE_BUILD: '1' })).toBe(false)
    localStorage.setItem('fotg.auth.mode', 'mock')
    expect(modeOverride('fotg.auth.mode', ['api', 'mock'] as const, { DEV: true, PROD: false })).toBe('mock')
    expect(modeOverride('fotg.auth.mode', ['api', 'mock'] as const, { DEV: false, PROD: true })).toBeNull()
    // A value that is not one of the allowed ones is never an override.
    localStorage.setItem('fotg.auth.mode', 'admin')
    expect(modeOverride('fotg.auth.mode', ['api', 'mock'] as const, { DEV: true, PROD: false })).toBeNull()
  })

  it('a production build ignores fotg.auth.mode=mock: the build-time VITE_AUTH_MODE decides (no fixture admin screens)', () => {
    production(); vi.stubEnv('VITE_AUTH_MODE', 'api')
    localStorage.setItem('fotg.auth.mode', 'mock')
    expect(authMode()).toBe('api')
    // Without a build-time value the default (mock) decides — never the browser.
    vi.stubEnv('VITE_AUTH_MODE', '')
    localStorage.setItem('fotg.auth.mode', 'api')
    expect(authMode()).toBe('mock')
  })

  it('a production build ignores the other mode switches too', () => {
    production(); vi.stubEnv('VITE_MARKET_MODE', 'api'); vi.stubEnv('VITE_ANALYTICS_MODE', 'off')
    localStorage.setItem('fotg.market.mode', 'mock'); localStorage.setItem('fotg.analytics.mode', 'api')
    expect(marketMode()).toBe('api')
    expect(analyticsMode()).toBe('off')
  })

  it('the development build (the dev server, these tests) still lets a browser choose — the local e2e suites rely on it', () => {
    localStorage.setItem('fotg.auth.mode', 'api'); expect(authMode()).toBe('api')
    localStorage.setItem('fotg.market.mode', 'api'); expect(marketMode()).toBe('api')
  })

  it('the global fixture scope: development and share builds only; a production build always runs India', () => {
    setFixtureScope(null)
    localStorage.setItem('fotg.fixtures', 'global')
    expect(fixtureScope({ DEV: true, PROD: false })).toBe('global')
    expect(fixtureScope({ DEV: false, PROD: true })).toBe('india')
    expect(fixtureScope({ DEV: false, PROD: true, VITE_SHARE_BUILD: '1' })).toBe('global')
  })
})

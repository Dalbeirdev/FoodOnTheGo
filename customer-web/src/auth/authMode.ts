import { modeOverride } from '../config/modeOverride'

/**
 * Which authentication implementation runs.
 *
 *  - 'api'  — the real local backend (Module 21): phone + OTP for customers, e-mail + password for the dashboards.
 *  - 'mock' — the in-browser development mock (Module 03). Always used by unit tests and by the static share builds,
 *             which have no backend.
 *
 * The build decides (VITE_AUTH_MODE). `localStorage fotg.auth.mode` overrides it on one browser (development builds only — Module 39), for controlled
 * testing of either path.
 */
export type AuthMode = 'api' | 'mock'

export function authMode(): AuthMode {
  // Module 39: the browser override is honoured in development builds only (see config/modeOverride.ts).
  const override = modeOverride('fotg.auth.mode', ['api', 'mock'] as const); if (override) return override
  if (import.meta.env.MODE === 'test' || import.meta.env.VITE_SHARE_BUILD === '1') return 'mock'
  return import.meta.env.VITE_AUTH_MODE === 'api' ? 'api' : 'mock'
}

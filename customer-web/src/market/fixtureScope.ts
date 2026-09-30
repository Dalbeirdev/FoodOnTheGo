/**
 * Fixture scope (Module 18A). The product build runs the INDIA launch configuration: India is the only active
 * market and only India development fixtures surface. International fixtures (other currencies, time zones,
 * addresses, Unicode / RTL names) are kept as CONTROLLED GLOBAL TEST FIXTURES that prove the architecture is
 * global-ready; they are reachable only in the 'global' scope:
 *   - automated tests (src/test/setup.ts calls setFixtureScope('global'); India tests opt back in), or
 *   - a developer setting localStorage fotg.fixtures = "global".
 * They never leak into the normal India customer experience.
 */
export type FixtureScope = 'india' | 'global'
let override: FixtureScope | null = null
export function setFixtureScope(scope: FixtureScope | null) { override = scope }
export function fixtureScope(): FixtureScope {
  if (override) return override
  try { return localStorage.getItem('fotg.fixtures') === 'global' ? 'global' : 'india' } catch { return 'india' }
}

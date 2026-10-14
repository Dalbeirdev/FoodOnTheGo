/**
 * Module 39 (C-02): the one place that decides whether a browser may switch a build-time mode by hand.
 *
 * Every `*Mode()` function (auth, market, restaurant, journey, cart, pickup, checkout, payment, order, review,
 * analytics) lets a `localStorage fotg.<area>.mode` key override the VITE_*_MODE value of the build, so a developer
 * or a local e2e suite can drive either path on one browser. Before Module 39 that switch worked in every build,
 * production included: anyone could set `fotg.auth.mode=mock` on the live site and get the fixture "super admin"
 * screens without a sign-in gate. No backend data was exposed, but a production build must not run code paths its
 * configuration did not choose.
 *
 * So an override is honoured ONLY in a development build (`import.meta.env.DEV`: the Vite dev server and the unit
 * tests). In a production build (`vite build`) it is ignored and the build-time VITE_*_MODE value — or its default —
 * decides. The local e2e suites run against the Vite dev server, so they keep working unchanged.
 *
 * `env` is a parameter only so that tests can hand in a production-shaped environment; callers never pass it.
 */
export type BuildEnv = { DEV?: boolean; PROD?: boolean; VITE_SHARE_BUILD?: string }

const currentEnv = (): BuildEnv => import.meta.env as BuildEnv

/** True only in a development build (the Vite dev server, the unit tests) — never in a `vite build` output. */
export function modeOverridesAllowed(env: BuildEnv = currentEnv()): boolean {
  return env.DEV === true && env.PROD !== true
}

/** True in a production build (`vite build`), share builds included. */
export function isProductionBuild(env: BuildEnv = currentEnv()): boolean {
  return env.PROD === true
}

/**
 * The value of a mode override kept in localStorage under `key`, when it is one of `allowed` and overrides are allowed
 * in this build; otherwise null (the caller then falls back to the build-time value).
 */
export function modeOverride<T extends string>(key: string, allowed: readonly T[], env: BuildEnv = currentEnv()): T | null {
  if (!modeOverridesAllowed(env)) return null
  try {
    const value = localStorage.getItem(key)
    return value !== null && (allowed as readonly string[]).includes(value) ? (value as T) : null
  } catch { return null /* storage unavailable */ }
}

// Module 40 — the LOCAL load-test environment: a pool of PHP processes (each one request at a time, like a PHP-FPM worker)
// serving the Laravel API on consecutive ports, plus queue workers — all on the separate database foodonthego_perf and an
// isolated Redis prefix, so a load test never touches the development database, cache, queues or rate-limit counters.
//
// Why a pool: `php artisan serve` handles ONE request at a time; a load test against it measures only its queue. The pool
// (N processes, the runner spreading requests over them) approximates a PHP-FPM pool behind a web server. OPcache is
// switched on per process (php.ini is not changed), APP_DEBUG is off, LOG_LEVEL is warning. LOCAL ONLY.
import { spawn, spawnSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'

export const BACKEND = 'E:/TechPio-Data/FoodOnTheGo/backend'
export const PHP = (() => {
  const base = path.join(process.env.LOCALAPPDATA, 'Microsoft/WinGet/Packages')
  const dir = fs.readdirSync(base).find((d) => d.startsWith('PHP.PHP.8.3_'))
  return path.join(base, dir, 'php.exe')
})()
export const PERF_DB = process.env.PERF_DB || 'foodonthego_perf'
export const PERF_PREFIX = 'fotg-perf-'

const OPCACHE = ['-d', 'zend_extension=opcache', '-d', 'opcache.enable=1', '-d', 'opcache.enable_cli=1', '-d', 'opcache.memory_consumption=256', '-d', 'opcache.interned_strings_buffer=16',
  '-d', 'opcache.max_accelerated_files=20000', '-d', 'opcache.validate_timestamps=1', '-d', 'opcache.revalidate_freq=60', '-d', 'realpath_cache_size=4096K', '-d', 'realpath_cache_ttl=600', '-d', 'memory_limit=256M']

/** The rate-limit settings, read from config/rate_limits.php (env names), so a capacity run can raise all of them. */
export function rateLimitEnvNames() {
  const src = fs.readFileSync(path.join(BACKEND, 'config/rate_limits.php'), 'utf8')
  return [...new Set([...src.matchAll(/env\('(RATE_LIMIT_[A-Z0-9_]+)'/g)].map((m) => m[1]))]
}

/**
 * @param {{limits?: 'real'|'raised', opcache?: boolean, debug?: boolean, extra?: Record<string,string>}} o
 */
export function perfEnv(o = {}) {
  const env = {
    ...process.env,
    APP_ENV: 'local', APP_DEBUG: o.debug ? 'true' : 'false', LOG_LEVEL: 'warning',
    DB_DATABASE: PERF_DB, REDIS_PREFIX: PERF_PREFIX, CACHE_PREFIX: PERF_PREFIX + 'cache-',
    OBSERVABILITY_SERVER_TIMING: 'true', OBSERVABILITY_REQUEST_LOG: 'slow', OBSERVABILITY_SLOW_MS: '2000',
    PAYMENT_DRIVER: 'fake', PAYOUT_DRIVER: 'fake', PUSH_DRIVER: 'fake',
    // connection switches under test, passed through from the shell when set (Module 40 A/B runs)
    ...Object.fromEntries(['DB_PERSISTENT', 'REDIS_PERSISTENT', 'DB_PGSQL_DISABLE_PREPARES'].filter((k) => process.env[k] !== undefined).map((k) => [k, process.env[k]])),
    ...(o.extra || {}),
  }
  if ((o.limits || 'raised') === 'raised') {
    // Load from ONE address (127.0.0.1) and a few hundred test accounts: the per-address and per-person limits would
    // answer 429 to the load generator itself. Raised for capacity runs only; a separate run keeps them ('real').
    for (const name of rateLimitEnvNames()) env[name] = '1000000'
    // Business abuse limits outside RATE_LIMIT_*: the load test verifies every pickup through ONE staff account of ONE
    // location (Module 32: 30 per staff / 120 per location per minute) — real kitchens spread pickups over staff.
    for (const name of ['ORDER_PICKUP_ATTEMPTS_PER_STAFF', 'ORDER_PICKUP_ATTEMPTS_PER_LOCATION']) env[name] = '1000000'
  }
  return env
}

const children = []

function waitFor(port, ms = 30000) {
  const until = Date.now() + ms
  return new Promise((resolve, reject) => {
    const tick = async () => {
      try {
        const r = await fetch(`http://127.0.0.1:${port}/api/v1/live`, { headers: { Accept: 'application/json' } })
        if (r.status === 200 || r.status === 404) return resolve()   // 404: a build without /live — the server is up
      } catch { /* not yet */ }
      if (Date.now() > until) return reject(new Error('server on ' + port + ' did not start'))
      setTimeout(tick, 250)
    }
    tick()
  })
}

/**
 * Starts `size` PHP processes on ports base..base+size-1 and `workers` queue workers. Returns their base URLs.
 */
export async function startPool({ size = 12, base = 8201, workers = 2, limits = 'raised', opcache = true, debug = false, extra = {} } = {}) {
  const env = perfEnv({ limits, opcache, debug, extra })
  const urls = []
  for (let i = 0; i < size; i++) {
    const port = base + i
    // Like `php artisan serve`: the router script runs with public/ as the working directory.
    const args = [...(opcache ? OPCACHE : ['-d', 'memory_limit=256M']), '-d', 'expose_php=Off', '-S', `127.0.0.1:${port}`, path.join(BACKEND, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')]
    const p = spawn(PHP, args, { cwd: path.join(BACKEND, 'public'), env: { ...env, PHP_CLI_SERVER_WORKERS: '1' }, stdio: 'ignore', windowsHide: true })
    children.push({ kind: 'http', port, p })
    urls.push(`http://127.0.0.1:${port}`)
  }
  startWorkers(workers, { opcache, env })
  await Promise.all(urls.map((u) => waitFor(Number(u.split(':').pop()))))
  // Warm OPcache in every process (the first request of a process compiles the framework).
  await Promise.all(urls.map((u) => fetch(u + '/api/v1/health').catch(() => null)))
  return urls
}

/** Queue workers on every queue the platform uses, in priority order (`env` defaults to the perf environment). */
export function startWorkers(n, { opcache = true, env = perfEnv() } = {}) {
  for (let i = 0; i < n; i++) {
    const p = spawn(PHP, [...(opcache ? OPCACHE : []), 'artisan', 'queue:work', 'redis', '--queue=orders,payments,finance,notifications,default', '--sleep=1', '--tries=3', '--timeout=60', '--max-jobs=5000', '--max-time=3600'],
      { cwd: BACKEND, env, stdio: 'ignore', windowsHide: true })
    children.push({ kind: 'worker', p })
  }
}

/** Kills the pool's processes of one kind ('http' or 'worker') — failure injection. Returns how many. */
export function killKind(kind) {
  const victims = children.filter((c) => c.kind === kind)
  for (const c of victims) {
    try { spawnSync('taskkill', ['/PID', String(c.p.pid), '/T', '/F'], { stdio: 'ignore' }) } catch { /* gone */ }
    children.splice(children.indexOf(c), 1)
  }
  return victims.length
}

export function stopPool() {
  for (const c of children.splice(0)) {
    try { spawnSync('taskkill', ['/PID', String(c.p.pid), '/T', '/F'], { stdio: 'ignore' }) } catch { /* gone */ }
  }
}

export function poolPids() {
  return children.map((c) => ({ kind: c.kind, port: c.port, pid: c.p.pid }))
}

/** Runs an artisan command against the perf database (synchronously). */
export function artisan(args, extra = {}) {
  const r = spawnSync(PHP, ['artisan', ...args], { cwd: BACKEND, env: perfEnv({ extra }), encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 })
  return { status: r.status, out: (r.stdout || '') + (r.stderr || '') }
}

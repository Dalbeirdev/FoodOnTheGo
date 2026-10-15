// Module 40 — resource sampling during a load run (LOCAL). Every `everyMs`:
//   - the machine: total CPU %, free memory; the php / postgres / redis-server processes: count, working set, CPU seconds
//     (one long-running PowerShell sampler, so sampling itself costs little);
//   - PostgreSQL: connections by state, waiting on locks, longest running query (superuser view of pg_stat_activity);
//   - Redis: used memory, connected clients, evicted keys, operations per second;
//   - queues of the perf prefix: depth, delayed, reserved, age of the oldest waiting job.
// Credentials come from C:\dev\pg-local-credentials.txt (never printed).
import { spawn, spawnSync } from 'node:child_process'
import fs from 'node:fs'
import { PERF_DB, PERF_PREFIX } from './servers.mjs'

const creds = Object.fromEntries(fs.readFileSync('C:/dev/pg-local-credentials.txt', 'utf8').split(/\r?\n/).filter((l) => l.includes('=')).map((l) => [l.slice(0, l.indexOf('=')).trim(), l.slice(l.indexOf('=') + 1).trim()]))
const REDIS_CLI = 'C:/dev/redis/redis-cli.exe'
const QUEUES = ['orders', 'payments', 'finance', 'notifications', 'default']

export function psql(sql, db = PERF_DB) {
  const r = spawnSync('C:/dev/pgsql/bin/psql.exe', ['-h', '127.0.0.1', '-U', creds.superuser, '-d', db, '-t', '-A', '-q', '-F', '|', '-c', sql], { env: { ...process.env, PGPASSWORD: creds.password }, encoding: 'utf8' })
  if (r.status !== 0) throw new Error('psql: ' + String(r.stderr).split(creds.password).join('<hidden>').split(/\r?\n/).filter((l) => !/NOTICE:/.test(l)).join('\n').slice(0, 3000))
  return r.stdout.trim()
}

export function redis(...args) {
  const r = spawnSync(REDIS_CLI, ['-h', '127.0.0.1', '-p', process.env.PERF_REDIS_PORT || '6379', ...args], { encoding: 'utf8' })
  return (r.stdout || '').trim()
}

export function redisInfo() {
  const info = Object.fromEntries(redis('INFO').split(/\r?\n/).filter((l) => l.includes(':')).map((l) => [l.slice(0, l.indexOf(':')), l.slice(l.indexOf(':') + 1)]))
  return { used_memory_bytes: Number(info.used_memory), used_memory_peak_bytes: Number(info.used_memory_peak), clients: Number(info.connected_clients), evicted_keys: Number(info.evicted_keys), ops_per_sec: Number(info.instantaneous_ops_per_sec), maxmemory: Number(info.maxmemory), maxmemory_policy: info.maxmemory_policy }
}

export function queueState(prefix = PERF_PREFIX) {
  const out = {}
  for (const q of QUEUES) {
    const key = `${prefix}queues:${q}`
    const depth = Number(redis('LLEN', key)) || 0
    let oldestAgeS = null
    if (depth) {
      try {
        const p = JSON.parse(redis('LINDEX', key, '0'))
        const pushed = p.pushedAt || p.createdAt || (p.data && p.data.pushedAt)
        if (pushed) oldestAgeS = Math.round((Date.now() / 1000 - Number(pushed)) * 10) / 10
      } catch { /* not JSON */ }
    }
    out[q] = { depth, delayed: Number(redis('ZCARD', key + ':delayed')) || 0, reserved: Number(redis('ZCARD', key + ':reserved')) || 0, oldest_age_s: oldestAgeS }
  }
  return out
}

export function pgState(db = PERF_DB) {
  const row = psql(`select count(*), count(*) filter (where state = 'active'), count(*) filter (where state = 'idle'), count(*) filter (where state like 'idle in transaction%'),
    count(*) filter (where wait_event_type = 'Lock'), coalesce(round(extract(epoch from max(now() - query_start) filter (where state = 'active' and pid <> pg_backend_pid())) * 1000), 0)
    from pg_stat_activity where datname = '${db}' and backend_type = 'client backend'`)
  const [total, active, idle, idleTx, lockWaits, longestMs] = row.split('|').map(Number)
  return { connections: total, active, idle, idle_in_transaction: idleTx, waiting_on_lock: lockWaits, longest_active_ms: longestMs, max_connections: Number(psql('show max_connections', 'postgres')) }
}

const PS = `
$ErrorActionPreference = 'SilentlyContinue'
$cpu = New-Object System.Diagnostics.PerformanceCounter('Processor', '% Processor Time', '_Total'); [void]$cpu.NextValue()
while ($true) {
  Start-Sleep -Milliseconds __EVERY__
  $os = Get-CimInstance Win32_OperatingSystem
  $o = @{ t = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds(); cpu_pct = [math]::Round($cpu.NextValue(), 1); free_mem_mb = [math]::Round($os.FreePhysicalMemory / 1024) }
  foreach ($n in 'php','postgres','redis-server') {
    $ps = Get-Process -Name $n
    $o[$n] = @{ count = @($ps).Count; ws_mb = [math]::Round((($ps | Measure-Object WorkingSet64 -Sum).Sum) / 1MB); cpu_s = [math]::Round((($ps | Measure-Object CPU -Sum).Sum), 1) }
  }
  [Console]::Out.WriteLine(($o | ConvertTo-Json -Compress -Depth 4)); [Console]::Out.Flush()
}`

export function startMonitor({ everyMs = 5000 } = {}) {
  const series = []
  const ps = spawn('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', PS.replace('__EVERY__', String(everyMs))], { windowsHide: true })
  let buf = ''
  ps.stdout.on('data', (d) => {
    buf += d.toString()
    let i
    while ((i = buf.indexOf('\n')) >= 0) {
      const line = buf.slice(0, i).trim(); buf = buf.slice(i + 1)
      if (!line.startsWith('{')) continue
      try {
        const o = JSON.parse(line)
        try { o.pg = pgState() } catch (e) { o.pg = { error: String(e.message).slice(0, 120) } }
        try { o.redis = redisInfo() } catch { o.redis = null }
        try { o.queues = queueState() } catch { o.queues = null }
        series.push(o)
      } catch { /* partial */ }
    }
  })
  return { series, stop: () => { try { spawnSync('taskkill', ['/PID', String(ps.pid), '/T', '/F'], { stdio: 'ignore' }) } catch { /* gone */ } } }
}

/** Peak / last values of a sampled series, for the result file. */
export function summarizeSeries(series) {
  if (!series.length) return null
  const max = (f) => Math.max(...series.map(f).filter((x) => typeof x === 'number' && !Number.isNaN(x)))
  const first = series[0]; const last = series[series.length - 1]
  const qDepth = (s) => (s.queues ? Object.values(s.queues).reduce((a, q) => a + q.depth, 0) : 0)
  return {
    samples: series.length,
    cpu_pct_max: max((s) => s.cpu_pct), cpu_pct_avg: Math.round(series.reduce((a, s) => a + (s.cpu_pct || 0), 0) / series.length),
    free_mem_mb_min: Math.min(...series.map((s) => s.free_mem_mb)),
    php_ws_mb_max: max((s) => s.php && s.php.ws_mb), php_processes: last.php && last.php.count,
    php_cpu_s: last.php && first.php ? Math.round((last.php.cpu_s - first.php.cpu_s) * 10) / 10 : null,
    postgres_ws_mb_max: max((s) => s.postgres && s.postgres.ws_mb),
    postgres_cpu_s: last.postgres && first.postgres ? Math.round((last.postgres.cpu_s - first.postgres.cpu_s) * 10) / 10 : null,
    pg_connections_max: max((s) => s.pg && s.pg.connections), pg_active_max: max((s) => s.pg && s.pg.active),
    pg_lock_waits_max: max((s) => s.pg && s.pg.waiting_on_lock), pg_longest_active_ms_max: max((s) => s.pg && s.pg.longest_active_ms),
    redis_memory_mb_max: Math.round(max((s) => s.redis && s.redis.used_memory_bytes) / 1048576 * 10) / 10,
    redis_clients_max: max((s) => s.redis && s.redis.clients), redis_evicted_keys: last.redis && last.redis.evicted_keys,
    queue_depth_max: max(qDepth), queue_depth_last: qDepth(last),
    oldest_job_age_s_max: max((s) => (s.queues ? Math.max(0, ...Object.values(s.queues).map((q) => q.oldest_age_s || 0)) : 0)),
  }
}

// LOCAL DEVELOPMENT ONLY — never pointed at production (Module 40).
//
// A repeatable PostgreSQL backup / restore of the LOCAL cluster (127.0.0.1:5432), used for:
//   - the backup → clean restore → integrity-check exercise ("a backup is not valid until a restore has worked"),
//   - the disaster-recovery technical exercise (timed),
//   - making a separate database for load tests (foodonthego_perf) from the local one.
//
// Production must use the provider's managed backups with point-in-time recovery; this script is the local evidence
// and the manual fallback described in docs/reliability/backup-restore.md.
//
//   node scripts/local/db-backup-restore.mjs backup  <database> <file.dump>
//   node scripts/local/db-backup-restore.mjs restore <file.dump> <new_database>     (refuses an existing database)
//   node scripts/local/db-backup-restore.mjs drop    <database>                     (only *_perf / *_restore / *_dr)
//   node scripts/local/db-backup-restore.mjs facts   <database>                     (row counts + PostGIS + sizes)
//
// Credentials come from C:\dev\pg-local-credentials.txt (local only): the superuser creates / drops databases and
// enables extensions; the dump itself is taken with the application role, as production tooling would. No value is
// ever printed. Every command prints one JSON line (durations in ms, sizes in bytes, a SHA-256 of the dump file).
import { createHash } from 'node:crypto'
import { spawnSync } from 'node:child_process'
import fs from 'node:fs'

const BIN = 'C:/dev/pgsql/bin/'
const creds = Object.fromEntries(fs.readFileSync('C:/dev/pg-local-credentials.txt', 'utf8').split(/\r?\n/).filter((l) => l.includes('=')).map((l) => [l.slice(0, l.indexOf('=')).trim(), l.slice(l.indexOf('=') + 1).trim()]))
const SU = { user: creds.superuser, pass: creds.password }
const APP = { user: creds.app_user, pass: creds.app_password }
const SAFE_NAME = /^[a-z][a-z0-9_]{2,62}$/
const SCRATCH_SUFFIX = /_(perf|restore|dr)$/

const run = (exe, args, who, input) => {
  const r = spawnSync(BIN + exe, ['-h', '127.0.0.1', '-p', '5432', '-U', who.user, ...args], { env: { ...process.env, PGPASSWORD: who.pass }, encoding: 'utf8', input, maxBuffer: 64 * 1024 * 1024 })
  if (r.status !== 0) throw new Error(`${exe} failed (exit ${r.status}): ${String(r.stderr).split(who.pass).join('<hidden>').slice(0, 1200)}`)
  return r.stdout
}
const sql = (db, statement, who = SU) => run('psql.exe', ['-d', db, '-v', 'ON_ERROR_STOP=1', '-q', '-t', '-A', '-c', statement], who).trim()
const sha256 = (file) => createHash('sha256').update(fs.readFileSync(file)).digest('hex')
const ms = (t) => Math.round(Number(process.hrtime.bigint() - t) / 1e6)
const out = (o) => console.log(JSON.stringify(o))

const [cmd, a, b] = process.argv.slice(2)

const FACT_TABLES = ['customers', 'restaurant_organizations', 'restaurant_locations', 'menus', 'menu_items', 'orders', 'order_items', 'payment_attempts', 'refunds', 'ledger_transactions', 'ledger_entries',
  'restaurant_settlements', 'restaurant_payouts', 'support_cases', 'support_case_messages', 'notifications', 'restaurant_reviews', 'analytics_events', 'analytics_order_facts', 'audit_events', 'security_events',
  'service_areas', 'route_corridors', 'cities', 'markets']

function facts(db) {
  const counts = {}
  for (const t of FACT_TABLES) counts[t] = Number(sql(db, `select count(*) from ${t}`))
  return {
    database: db,
    size_bytes: Number(sql(db, 'select pg_database_size(current_database())')),
    postgis: sql(db, "select extversion from pg_extension where extname = 'postgis'") || null,
    spatial_rows_valid: Number(sql(db, 'select count(*) from service_areas where st_isvalid(geometry::geometry)')),
    gist_indexes: Number(sql(db, "select count(*) from pg_indexes where schemaname = 'public' and indexdef ilike '%using gist%'")),
    migrations: Number(sql(db, 'select count(*) from migrations')),
    tables: Number(sql(db, "select count(*) from information_schema.tables where table_schema = 'public' and table_type = 'BASE TABLE'")),
    counts,
  }
}

if (cmd === 'backup') {
  if (!SAFE_NAME.test(a ?? '') || !b) throw new Error('usage: backup <database> <file.dump>')
  const t = process.hrtime.bigint()
  // Custom format (-Fc): compressed, restorable table by table, with the extension and spatial types included.
  run('pg_dump.exe', ['-d', a, '-Fc', '-Z', '6', '--no-owner', '--no-privileges', '-f', b], APP)
  out({ step: 'backup', database: a, file: b, method: 'pg_dump custom format (-Fc, compression 6), application role', duration_ms: ms(t), size_bytes: fs.statSync(b).size, sha256: sha256(b), at: new Date().toISOString() })
} else if (cmd === 'restore') {
  if (!fs.existsSync(a ?? '') || !SAFE_NAME.test(b ?? '')) throw new Error('usage: restore <file.dump> <new_database>')
  if (sql('postgres', `select count(*) from pg_database where datname = '${b}'`) !== '0') throw new Error(`database ${b} exists — a restore goes into a NEW database (drop it first if it is a scratch copy)`)
  const t = process.hrtime.bigint()
  sql('postgres', `create database ${b} owner ${APP.user}`)
  // Extensions need the superuser; everything else is restored as the application role that owns it.
  for (const ext of ['postgis', 'pg_stat_statements']) sql(b, `create extension if not exists ${ext}`)
  // The extensions were created above by the superuser: their entries (and their comments) are left out of the list,
  // and so are the rows of PostGIS's own spatial_ref_sys table (CREATE EXTENSION fills it; the application role may not write it).
  const list = run('pg_restore.exe', ['-l', a], APP).split(/\r?\n/).filter((l) => !/ EXTENSION /.test(l) && !/TABLE DATA public spatial_ref_sys /.test(l)).join('\n')
  const listFile = b + '.restore-list'
  fs.writeFileSync(listFile, list)
  try {
    run('pg_restore.exe', ['-d', b, '--no-owner', '--no-privileges', '--exit-on-error', '-j', '4', '-L', listFile, a], APP)
  } finally {
    fs.rmSync(listFile, { force: true })
  }
  const restoreMs = ms(t)
  const t2 = process.hrtime.bigint()
  sql(b, 'analyze', APP)
  out({ step: 'restore', file: a, database: b, file_sha256: sha256(a), duration_ms: restoreMs, analyze_ms: ms(t2), facts: facts(b), at: new Date().toISOString() })
} else if (cmd === 'drop') {
  if (!SAFE_NAME.test(a ?? '') || !SCRATCH_SUFFIX.test(a)) throw new Error('drop is only for scratch databases (*_perf, *_restore, *_dr)')
  sql('postgres', `select pg_terminate_backend(pid) from pg_stat_activity where datname = '${a}' and pid <> pg_backend_pid()`)
  sql('postgres', `drop database if exists ${a}`)
  out({ step: 'drop', database: a })
} else if (cmd === 'facts') {
  out({ step: 'facts', ...facts(a) })
} else {
  console.error('usage: backup | restore | drop | facts (see the header of this file)')
  process.exit(2)
}

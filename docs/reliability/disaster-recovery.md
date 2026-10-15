# Disaster recovery

Status: Module 40 — 2026-10-14 — local only; production = NO

Sources:
- `scripts/local/db-backup-restore.mjs`
- `tests/performance/dr-exercise.mjs` → `docs/local-review/test-results/m40-dr-exercise.json`
- `tests/performance/failure.mjs` → `m40-failure-*.json`
- `backup-restore.md`, `provider-resilience.md`

**RPO and RTO are business decisions: PENDING BUSINESS APPROVAL.** The values below are **TEST** values measured on the development machine with local dumps. They are not production commitments.

## 1. What must survive

| Data | Where | Loss means |
|---|---|---|
| Orders, payments, refunds, ledger, settlements, payouts | PostgreSQL | money records — **must not be lost**; anything lost must be reconstructable from the payment provider |
| Customers, restaurants, menus, staff, RBAC | PostgreSQL | service cannot run |
| Pickup credentials | PostgreSQL | a customer cannot collect: reissue (Module 32) |
| Queued jobs | Redis | recovered by the sweeps (measured: `provider-resilience.md` §4) |
| Cache, rate-limit counters, locks, MFA challenges, lockouts | Redis | rebuilt; sign-in in progress restarts |
| Report exports, support attachments, avatars | local / object storage | exports regenerate; attachments are not implemented (Module 37); avatars are re-uploaded by the customer |
| Secrets | `.env` / the deployment's secret store | `docs/security/secret-management.md` |

## 2. Scenarios and runbooks

### R1 — PostgreSQL primary lost (disk, host, corruption, human error)

1. **Stop writes.** Put the API in maintenance mode (`php artisan down` with a secret bypass for operators). Stop the workers and the scheduler.
2. **Choose the restore point.**
   - With PITR (production recommendation): the latest WAL, or the moment just before the human error.
   - Without PITR: the newest verified dump (`backup-restore.md`).
3. **Restore** into a new database or instance. For a dump:

   ```bash
   node scripts/local/db-backup-restore.mjs restore <file.dump> <new_db>
   ```

   This creates the database, the PostGIS and `pg_stat_statements` extensions, runs `pg_restore -j 4` and `ANALYZE`, and reports row counts, the PostGIS version, spatial validity and GiST indexes.
4. **Point the application** at it (`DB_DATABASE` / host), then `php artisan config:cache` and `php artisan queue:restart`.
5. **Verify before opening:**
   - `finance:verify-ledger`, `finance:reconcile-internal`, `payments:reconcile`, `orders:recover`;
   - `analytics:aggregate`, then `analytics:check`. The read models lag the source until an aggregation runs; checked first, they report ERROR. Measured on the first DR run.
   - `ops:reliability --alerts`;
   - one test order through the kitchen.
6. **Reconcile what the restore lost** (only when the restore point is older than the failure):
   - payments captured by the provider after the restore point exist at the provider, not in the database;
   - the provider's settlement / payment report (`finance:reconcile-provider`, Razorpay — PENDING PROVIDER VERIFICATION) lists them;
   - each one is refunded or turned into an order by a person through the admin tools. **Never by marking anything paid by hand.**
7. **Open:** `php artisan up`, then start the workers and the scheduler.

### R2 — Redis lost (or flushed)

1. Restart or replace Redis. No application restart is needed (measured).
2. The sweeps restore the queued work:
   - `orders:recover` turns verified payments without an order into orders after 60 s;
   - `finance:post-pending` posts missing ledger entries;
   - `notifications:recover` re-queues deliveries;
   - `payments:reconcile` and `refunds:reconcile` re-check what is due.

   Measured: 3 lost order jobs → 3 orders once past the grace, each exactly once.
3. People mid-sign-in repeat their OTP or MFA. Lockout counters restart (accepted, Module 39).
4. **Never** "fix" by flushing again or deleting queues.

### R3 — Queue backlog / workers down

1. Start or scale workers: `queue:work redis --queue=orders,payments,finance,notifications,default`. Measured: 20 queued order jobs created 20 orders in 3.1 s.
2. Watch `ops:reliability`: queue depth and oldest age fall, and failed jobs stay at 0.
3. A worker that died holding a job gives it back after `retry_after` (90 s, measured 93 s).
4. Jobs with a single try are recorded in `failed_jobs` after a crash (`PostFinancialEventJob` in the crash test). Their work is done by the sweeps, and a person reviews `failed_jobs`. **Never retry a money job blindly without reading why it failed.**

### R4 — Payment provider outage

Customers see 503 `payment_provider_unavailable` (measured ≈ 1.4 s); carts are not locked. Nothing to do in the database:
- attempts left UNKNOWN are reconciled automatically when the provider answers;
- refunds wait with backoff, then REVIEW_REQUIRED.

Communicate to restaurants and customers (`incident-response.md`, Module 39). **Live mode is never switched as a workaround.**

### R5 — Realtime server down

Orders keep moving (measured: kitchen actions 200, about 1 s slower). Screens fall back to resync and polling. Restart Reverb; clients reconnect (measured: 230 sockets back in 1.7 s).

### R6 — Application hosts lost

- Stateless: start new hosts from the same release, with the same `.env` / secrets.
- Measured locally: the PHP pool back in 1.3 s; in-flight requests failed for the 5 s outage; every paid purchase still produced exactly one order.

### R7 — Region / provider-wide outage

Out of scope for a single-region launch. A second region needs a database replica, Redis, storage replication and DNS failover (PENDING architecture decision).

## 3. DR technical exercise (local, timed)

`node tests/performance/dr-exercise.mjs` (`m40-dr-exercise.json`):

Run 2 is in `m40-dr-exercise.json`; run 1 is preserved in `m40-dr-exercise-first-run.txt`.

| Step | Run 1 | Run 2 |
|---|---|---|
| 1. backup of the primary (`foodonthego_perf`, medium dataset, ≈ 20 k orders, ≈ 100 k ledger entries) | 7.8 s, 63 MB | 9.2 s |
| 2. business after the backup (the RPO window) | 3 purchases → 3 orders | 3 → 3 |
| 3. disaster: the primary declared lost | t = 20.2 s | t = 23.2 s |
| 4. restore into `foodonthego_dr` (create, extensions, `pg_restore -j 4`, ANALYZE); row counts = the backup's | 8.6 s + 3.3 s; equal | 10.4 s + 16.9 s; equal |
| 5. application on the restored database: `/live` 200, `/health` 200; one order purchase → kitchen → **picked up** | at t = 42.2 s | at t = 61.7 s |
| 6. integrity commands on the restored database | ledger OK, reconciliations clean; analytics ERROR (not aggregated first) | **all exit 0** (aggregation first) |
| orders written after the backup missing from the restore | 3 of 3 | 3 of 3 |
| **TEST RTO** (disaster → first order picked up) | **22.0 s** | **38.5 s** |

The restored database is dropped at the end of the exercise.

## 4. Targets (proposal for the owner; PENDING BUSINESS APPROVAL)

| | Proposal | Needs |
|---|---|---|
| RPO (money data) | ≤ 5 minutes | managed PostgreSQL with PITR (WAL archiving), or continuous archiving to object storage |
| RTO (full service) | ≤ 1 hour | a rehearsed runbook R1, infrastructure as code, a restore test each month |
| Backup retention | 35 days of PITR + monthly snapshots for 12 months | storage cost decision; legal retention of financial records (PENDING LEGAL REVIEW) |

## 5. Restore tests

- Monthly, and after every schema change of the money tables: restore the latest backup into a scratch database and run step 5 of R1.
- Record the duration and the integrity output. Module 40 did this locally (`backup-restore.md`).

## 6. Tabletop exercise

### 6.1 Desk walkthrough — EXECUTED 2026-10-14 (engineer only, no other participants)

**Scenario:** "13:10 on a weekday lunch peak, the PostgreSQL host fails. The last dump is from 03:00; PITR is configured."

Each step of R1 was walked against what the platform actually does (code and Module 40 measurements). Each step below records what was found.

| Step | What happens | Gap found |
|---|---|---|
| Detection | Load-balancer health (`/health`) turns 503 within seconds; `ops:reliability` reports `dependency.database` critical; `/live` stays 200, so the processes are **not** restarted (correct) | **No alert reaches a person**: no collector or pager is connected (REL-030). Detection depends on a customer or a restaurant calling. |
| Stop traffic | `php artisan down` | **The maintenance driver is `file`** (`APP_MAINTENANCE_DRIVER`, `.env.example`). It covers one host only; with several app hosts, each must be put down separately, or the driver must be `cache` with a shared store. Recorded as REL-043. |
| What customers see | API 503 during maintenance | How the Customer Web and the Android app present a 503 / maintenance answer mid-checkout was not verified in Module 40. It belongs to Module 41 E2E (REL-044). |
| Restore decision | PITR to 13:09 (managed service) vs the 03:00 dump | The decision owner is not named (PENDING BUSINESS APPROVAL) |
| Restore | Managed PITR / `db-backup-restore.mjs` | Measured locally: ≈ 12–27 s for a 63 MB dump. Production size unknown. |
| Integrity before reopening | ledger / reconciliations / analytics (aggregate first) / one test order | Covered and measured (`m40-dr-exercise.json`) |
| Payments in the lost window | provider report since the restore point → a person refunds or creates the order | **The Razorpay settlement import is PENDING PROVIDER VERIFICATION**: the step relies on a provider path never exercised against the real provider |
| Reopen | `php artisan up`; workers; scheduler | The scheduler's overlap locks expire in ≤ 10 min (Module 40), so the sweeps resume on their own |
| Communicate | `docs/security/incident-response.md` (Module 39) | Contacts PENDING |

### 6.2 Tabletop with the on-call team — NOT HELD

This needs the people who will be on call. The template:

| | |
|---|---|
| Scenario | "At 13:10 on a weekday lunch peak the database host fails. The last dump is from 03:00; PITR is configured." |
| Participants | on-call engineer, a second engineer, the business owner, support lead, finance contact |
| Questions | Who declares the incident? Who talks to restaurants? How do we stop new orders (maintenance mode) and what do customers see? Which restore point? Who checks the ledger before reopening? How are payments captured between the restore point and the failure found and handled? When do we reopen? |
| Expected artefacts | an incident log; the runbook steps timed; a list of runbook gaps |
| Follow-up | update runbooks R1–R6; schedule the next exercise |

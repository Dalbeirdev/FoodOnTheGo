# Load-test plan

Status: Module 40 — 2026-10-14 — local only; production = NO

Tooling: `tests/performance/` (Node 24, no external load-testing service).

**Safety rules (Module 40 spec):**
- Every run targets the **isolated local perf environment only**:
  - database `foodonthego_perf`, restored from a dump of the local development database plus synthetic rows;
  - Redis prefix `fotg-perf-`, and a perf-only Redis server on port 6390 for the Redis failure tests;
  - its own PHP pool on ports 8201+ and its own Reverb on 8086.
- The development database, the development Redis keys and the preview servers are never load-tested.
- **No real money, no real provider, no real customer data:**
  - payments, payouts and push use the DEVELOPMENT providers (`PAYMENT_DRIVER=fake`, `PAYOUT_DRIVER=fake`, `PUSH_DRIVER=fake`);
  - the synthetic customers are `+9196…` / `+919000…` numbers and "Synthetic Customer N" names.
- No safety control is removed for a benchmark. Transactions, locks, idempotency, authorization and validation are untouched.
- Only these limits are raised, for capacity runs only:
  - the per-address and per-person rate limits (`RATE_LIMIT_*`);
  - the two pickup-verification abuse limits.
  
  Without that, the load generator — one IP address and a few hundred accounts — would be answered 429 by design. A run with `--limits real` keeps every limit.

## 1. Environment (`tests/performance/lib/servers.mjs`)

- **PHP pool:** N PHP processes, each one request at a time (`php -S` + Laravel's router, like a PHP-FPM worker), OPcache on, `APP_DEBUG=false`, `expose_php=Off`, `LOG_LEVEL=warning`. The runner spreads requests round-robin over the pool.
- **Queue workers:** `queue:work redis --queue=orders,payments,finance,notifications,default`.
- **Server-Timing:** on in this environment. Every sample records the server's own time, its query count and its database time.
- **Sampling:** every 5 s (`lib/monitor.mjs`):
  - host CPU and free memory;
  - php / postgres / redis-server processes;
  - PostgreSQL connections, lock waits and longest statement;
  - Redis memory, clients and evictions;
  - queue depth and the age of the oldest job.
- **Host:** Intel i9-11900H, 16 logical CPUs, 32 GB RAM, Windows 11, PostgreSQL 18.6 + PostGIS 3.6.2, Redis 5, all on one machine **with the load generator**.

**Not production.** Windows, the PHP built-in server, PostgreSQL's process-per-connection on Windows, and a load generator sharing the CPU all make these figures a description of this machine. They are not a capacity claim for production.

## 2. Datasets (`tests/performance/dataset.mjs`)

| Tier | How | Size |
|---|---|---|
| small | the local development database (dump → restore) + reviews and support cases created through the API (`dataset.mjs templates`) | 15 orders, 21 restaurant locations, 58 customers |
| medium | `dataset.mjs grow --tier medium`: one transaction, synthetic copies of COMPLETED orders with their payments, items, events, pickup credentials, ledger (re-dated with the order, pointing at the copy), allocations, payable lines; customers, restaurant locations along 5 corridors, reviews, support cases, notifications, behavioural events | 18,765 orders, 5,058 customers, 339 locations, 93,825 ledger entries, 200,068 analytics events, 386 MB |
| large | `dataset.mjs grow --tier large` (×10 the medium tier) | see `load-test-results.md` (run only if time allows; recorded as run or not run) |

The generated dataset is **verified with the platform's own integrity commands** (`tests/performance/integrity.mjs`): ledger verification, internal reconciliation, payment / refund reconciliation, order recovery, analytics checks.
- The first medium build failed them: ledger rows were dated today and pointed at the original orders, and payable lines were missing. The generator was fixed, and the second build passes (`m40-integrity-perf-medium.json`).

## 3. Measurements

| Test | Script | What |
|---|---|---|
| Baseline | `baseline.mjs` | Every major endpoint alone (one client): 30 timed requests after 3 warm-ups; OTP verify ×15; 15 full purchase → kitchen → pickup cycles, every step timed. Run on the small and the medium dataset, before and after tuning. |
| Query profile | `query-profile.mjs` | `pg_stat_statements` reset per endpoint; the top statements by total time (normalised text only) |
| Load profiles | `run.mjs --profile …` | Closed-loop virtual users (VUs) with think time, executing a weighted MIX (below) |
| Race | `run.mjs --profile race` | N orders, each verified at the counter by 8 simultaneous requests: exactly one may succeed |
| Realtime | `realtime.mjs` | Connections, broadcast latency (request → arrival on every subscribed socket), reconnect storm |
| Failure injection | `failure.mjs <scenario>` | queue stopped / worker killed, PHP restart under load, Redis down / flushed, database refusing / connections killed, payment provider down, realtime down |
| Integrity | `integrity.mjs` | the platform's own reconciliation and verification commands, after the runs and after a restore |

### Load profiles

| Profile | VUs × duration | Think time | Purpose |
|---|---|---|---|
| smoke | 1 × one pass of every scenario | 0 | the scripts and the chain work |
| expected | 30 × 180 s | 1–3 s | a busy local market (assumption: no business target exists) |
| peak | 75 × 180 s | 0.5–1.5 s | a lunch rush at 2.5× |
| stress | 20 → 40 → 80 → 120 → 160 → 200, 45 s each | 0.2–0.6 s | where it breaks; stops at 10% errors or p95 15 s |
| spike | 10 → 150 → 10 (60 / 45 / 120 s) | 0.3–1 s | sudden burst and recovery |
| soak | 25 × `--minutes` | 1–2.5 s | leaks, growth, queue drift over time |
| race | 20 orders × 8 racers | — | exactly-once pickup |

### Scenario mix (weights per VU iteration)

| Weight | Scenario | Requests |
|---|---|---|
| 30 | browse | restaurant list, restaurant, menu, item |
| 20 | journey | plan a route (short / medium / long corridor), corridor restaurants |
| 12 | purchase | OTP sign-in (token cached per run), cart, pickup time, checkout, confirm, payment attempt, provider simulation, payment completion, order poll |
| 12 | customer reads | cart, orders, notifications, support cases |
| 4 | kitchen | order board; accept → preparing → ready; pickup verification of the orders the purchases created |
| 5 | admin | orders, payments, refunds |
| 5 | service desk | support cases |
| 4 | analyst | analytics overview and reports |
| 3 | restaurant finance | finance summary, earnings |

**Pass criteria for a run (local):**
- 0 server errors (5xx) and 0 transport errors at expected load;
- every purchase that reached payment produced exactly one order;
- every pickup race verified exactly once;
- integrity commands clean afterwards.

**Latency:** reported, not judged. No latency target was approved (PENDING BUSINESS APPROVAL).

## 4. Running

```bash
node tests/performance/dataset.mjs counts
```

```bash
node tests/performance/baseline.mjs --n 30 --cycles 15 --pool 4 --out baseline-medium
```

```bash
node tests/performance/run.mjs --profile expected --pool 12 --workers 2 --out expected-medium
```

```bash
node tests/performance/run.mjs --profile expected --pool 12 --workers 2 --persistent --out expected-medium-persistent
```

```bash
node tests/performance/failure.mjs queue
```

```bash
node tests/performance/integrity.mjs --db foodonthego_perf --out integrity-perf-medium
```

Results: `docs/local-review/test-results/m40-*.json`. A run never writes a token, a password or a phone number into a result file. Tokens are cached only in the git-ignored `tests/performance/.state/`.

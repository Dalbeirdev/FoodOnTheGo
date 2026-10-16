# Production readiness checklist

Status: Module 41 — 2026-10-16 — local only; **production = NO**; **READY FOR PRODUCTION DEPLOYMENT = NO**.

The single list Module 42 works through. Each row: **READY** (done and evidenced locally; still to be re-checked on the
production environment where marked ⟳), **PENDING** (someone has to do or decide it), **BLOCKED** (cannot start until
something else exists). Detailed companions: [security checklist](../security/production-security-checklist.md),
[reliability checklist](../reliability/production-reliability-checklist.md), [secrets checklist](production-secrets-checklist.md),
[environment matrix](environment-matrix.md), [blocker register](production-blocker-register.md).

## Business
| # | Item | Status | Evidence / owner |
|---|---|---|---|
| BU-1 | Launch scope confirmed (Web + Android, India, pickup only, iOS deferred) | READY | Decision register (Module 41) |
| BU-2 | Launch cities and real service areas / corridors | PENDING | Owner (CF-303) |
| BU-3 | Business acceptance of every surface (sign-off matrix) | PENDING | Owner — [business-acceptance.md](business-acceptance.md) (CF-573) |
| BU-4 | Support contact (e-mail, phone, hours) published | PENDING | Owner (CF-023, CF-519) |
| BU-5 | Restaurant onboarding process (who creates organizations, locations, first owner; documents) | PENDING | Owner (CF-318, CF-322, CF-321) |
| BU-6 | Version decision: Laravel 13 / PostgreSQL 18 locally vs the brief's Laravel 12 / PostgreSQL 16 | PENDING | Owner (CF-268); nothing requires PostgreSQL > 16 (Module 41 audit) |
| BU-7 | Redis role (queues and sessions on Redis vs "Redis cache-only") | PENDING | Owner (CF-575) |

## Legal
| # | Item | Status | Evidence / owner |
|---|---|---|---|
| LE-1 | Terms & Conditions approved and versioned | BLOCKED | DRAFT (CF-002, CF-394) |
| LE-2 | Privacy Policy approved (matches the data inventory) | BLOCKED | DRAFT; `docs/privacy/data-inventory.md` |
| LE-3 | Refund & Cancellation Policy approved (matches the refund domain) | BLOCKED | DRAFT; customer cancellation disabled pending policy (CF-477) |
| LE-4 | Cookie Policy approved (matches actual storage: session tokens, guest cart token, analytics) | BLOCKED | DRAFT; consent screen not built (CF-528) |
| LE-5 | Retention / erasure policies (orders, finance, support, analytics, logs) | PENDING | CF-192, CF-342, CF-486, CF-520, CF-530 |
| LE-6 | Allergy / dietary disclaimer wording | PENDING | CF-095 |
| LE-7 | Provider terms (places storage, route caching) | PENDING | CF-353 |

## Brand
| # | Item | Status | Evidence / owner |
|---|---|---|---|
| BR-1 | Logo / wordmark / favicon / Android icon final | PENDING | Logo files match the palette exactly; owner approval; Android adaptive icon framing (CF-581) |
| BR-2 | Hero, restaurant and menu imagery final (no development placeholders on primary pages) | BLOCKED | CF-001, CF-580 |
| BR-3 | Brand colour tokens of the web app | PENDING | Differ from the approved palette (CF-579) |
| BR-4 | About figures and illustration numbers | PENDING | Illustrative (CF-022) |

## Infrastructure
| # | Item | Status | Evidence / owner |
|---|---|---|---|
| IN-1 | Hosts / runtime: nginx + PHP-FPM 8.3 + OPcache, supervisor / systemd | BLOCKED | Not provisioned (CF-577) |
| IN-2 | DNS records (web, admin / dashboard if separate, API, realtime) | PENDING | [launch-runbook.md](launch-runbook.md) §3 |
| IN-3 | TLS certificates (HSTS at the edge) | PENDING | Module 42 |
| IN-4 | Load balancer with WebSocket support; `TRUSTED_PROXIES` | PENDING | security:check item 38 |
| IN-5 | CDN / WAF decision (not required for V1) | PENDING | Owner (risk decision) |
| IN-6 | Secret manager | PENDING | [production-secrets-checklist.md](production-secrets-checklist.md) |

## Database
| # | Item | Status | Evidence / owner |
|---|---|---|---|
| DB-1 | Managed PostgreSQL 16 + PostGIS, TLS verify-full (`DB_SSLROOTCERT`) | PENDING | CF-268, CF-577 |
| DB-2 | Clean migration from empty → 33 migrations → reference seed → boot | READY ⟳ | `m41-migration-tests.txt` §A (4 s, production seed = reference data only) |
| DB-3 | Upgrade migration from a previous schema with data; rollback / re-apply | READY ⟳ | `m41-migration-tests.txt` §B, §C (18,765 orders; integrity commands exit 0) |
| DB-4 | No destructive migration assumes data absent without a plan | READY | Module 41 audit (`docs/production/migration-review.md`) |
| DB-5 | Least-privilege application role, separate migration role | PENDING | CF-550 |
| DB-6 | PgBouncer (transaction mode) or persistent connections; `DB_PGSQL_DISABLE_PREPARES` | PENDING | CF-558 |
| DB-7 | Production seed: reference data only; fixtures refuse themselves outside local / testing | READY | `HardeningConfigTest` (Module 41) |
| DB-8 | First administrator via `php artisan admin:create` (hidden prompt; no default credential) | READY | `CreateAdmin` command; MFA enforced at first sign-in |

## Redis
| # | Item | Status | Evidence / owner |
|---|---|---|---|
| RE-1 | Current Redis / Valkey, password + TLS, private network | PENDING | security:check item 12; CF-274 |
| RE-2 | `maxmemory-policy noeviction`, persistence for queues, HA | PENDING | Reliability view alert `redis_eviction_policy`; CF-559 |
| RE-3 | Behaviour when Redis is down (rate limiter fail-closed today) | PENDING | Owner decision (CF-559) |

## Queues
| # | Item | Status | Evidence / owner |
|---|---|---|---|
| QU-1 | Supervised workers on every queue (`payments,orders,finance,notifications,default`) | PENDING | CF-408, CF-428; [launch-runbook.md](launch-runbook.md) §6 |
| QU-2 | Scheduler once per minute (21 entries, onOneServer) | PENDING | `routes/console.php` |
| QU-3 | Failed jobs monitored | PENDING | `ops:reliability --alerts` (CF-566) |
| QU-4 | Lost-job recovery by sweeps | READY ⟳ | Module 40 failure injection |

## Storage
| # | Item | Status | Evidence / owner |
|---|---|---|---|
| ST-1 | Single host with backed-up `storage/app`, or shared storage, or S3 adapter | BLOCKED | CF-560 |
| ST-2 | Private disk never served; no `storage:link` | READY | security:check item 26 |

## Authentication
| # | Item | Status | Evidence / owner |
|---|---|---|---|
| AU-1 | Customer OTP sign-in with a real SMS provider | BLOCKED | CF-294 |
| AU-2 | Staff sign-in, lockout, password reset, invitations | READY / mail BLOCKED | Module 21 / 39; mail (CF-315) |
| AU-3 | Administrator MFA mandatory | READY | security:check item 19 |
| AU-4 | Administrator step-up re-authentication for high-risk actions | READY | Module 41: `AdminStepUpTest`; windows (15 min) PENDING BUSINESS APPROVAL |
| AU-5 | Web session architecture (sessionStorage bearer tokens vs HttpOnly cookies) | PENDING | Owner decision (CF-547) |

## Security
| # | Item | Status | Evidence / owner |
|---|---|---|---|
| SE-1 | Critical findings = 0, exploitable High = 0 | READY | Module 39 audit + Module 41 regression (`m41-security-*`) |
| SE-2 | `php artisan security:check` exit 0 on production | PENDING | 41 items; locally 10 / 41 by design |
| SE-3 | Secret scan of the tree, the web bundle, the APK | READY ⟳ | `m41-secret-scan.txt` |
| SE-4 | Dependency audit (Composer, npm, pub = 0 known vulnerabilities; Gradle not scanned) | READY / PENDING | `m41-dependency-audit.txt`; CF-582 |
| SE-5 | Dynamic scan (ZAP) on staging, penetration test | PENDING | CF-551, CF-273 |
| SE-6 | Security headers at the web server (CSP is in the build; HSTS, frame-ancestors, Permissions-Policy at the server) | PENDING | `docs/security/security-headers.md` |

## Payments
| # | Item | Status | Evidence / owner |
|---|---|---|---|
| PA-1 | Razorpay TEST mode verified end to end (web, Android device, webhook) | BLOCKED | CF-404 … 407 |
| PA-2 | LIVE mode | BLOCKED (by decision) | CF-413; code change + owner approval in Module 42 |
| PA-3 | Payment safety (server verification, idempotency, tamper, duplicate callbacks, timeouts) | READY ⟳ | Module 30 / 40 / 41 tests on the development provider |

## Notifications
| # | Item | Status | Evidence / owner |
|---|---|---|---|
| NO-1 | FCM configured, device verified | BLOCKED | CF-450, CF-574 |
| NO-2 | E-mail provider | BLOCKED | CF-315, CF-451 |
| NO-3 | No pickup code / QR token / OTP in pushes; lock-screen PRIVATE | READY | Module 33 / 41 audit |
| NO-4 | Fake providers impossible outside local / testing | READY | Module 41 guard + test |

## Maps
| # | Item | Status | Evidence / owner |
|---|---|---|---|
| MA-1 | Google Places / Routes keys with restrictions, quotas, billing alerts | BLOCKED | CF-352 |
| MA-2 | Live-provider checks of the adapters | BLOCKED | CF-352 |

## Realtime
| # | Item | Status | Evidence / owner |
|---|---|---|---|
| RT-1 | Reverb on Linux behind TLS (wss), allowed origins = web hosts | PENDING | CF-567 |
| RT-2 | Android connection with production origins | PENDING | CF-576 |
| RT-3 | Polling fallback and resync | READY ⟳ | Module 32 / 40 |

## Finance
| # | Item | Status | Evidence / owner |
|---|---|---|---|
| FI-1 | Approved India tax rules (GST) | BLOCKED | CF-392 |
| FI-2 | Approved commission plan (the TEST 10 % plan never reaches production) | BLOCKED | CF-492; TEST plans refused outside local / testing |
| FI-3 | Settlement cadence and statements; payouts manual (live payouts disabled) | PENDING | CF-493, CF-498 |
| FI-4 | Ledger integrity, duplicate-posting protection | READY ⟳ | `finance:verify-ledger` in every Module 40 / 41 run |
| FI-5 | Tax / accounting review (GST treatment, TDS / TCS, invoices, credit notes) | PENDING | CF-494 |

## Backup
| # | Item | Status | Evidence / owner |
|---|---|---|---|
| BA-1 | Automated backups + PITR, encrypted, off-site | PENDING | CF-271 |
| BA-2 | Restore test with integrity checks | READY ⟳ | Module 40 restore (0 tables differ); Module 41 upgrade tests |
| BA-3 | RPO / RTO agreed | PENDING | CF-557 |

## Monitoring
| # | Item | Status | Evidence / owner |
|---|---|---|---|
| MO-1 | Log collector (JSON lines, redaction tap) | PENDING | CF-566 |
| MO-2 | Alerts from `ops:reliability --alerts` and the provider health views | PENDING | CF-566 |
| MO-3 | Alert contacts / on-call | PENDING | Owner (CF-569) |
| MO-4 | Error tracking (if used) with PII redaction | PENDING | Decision |

## Web
| # | Item | Status | Evidence / owner |
|---|---|---|---|
| WE-1 | Release build gate (every mode api, https / wss, no dev OTP, environment declared) | READY | `releaseBuild.test.ts` |
| WE-2 | Production build with production values; source maps off | PENDING | No production values exist; source maps are off by default (`m41-web-build.txt`) |
| WE-3 | Customer acceptance of Web | PENDING | CF-573 |

## Android
| # | Item | Status | Evidence / owner |
|---|---|---|---|
| AN-1 | Release signing with an upload key outside git (Play App Signing) | BLOCKED | CF-548 |
| AN-2 | Production flavor build with the production defines | PENDING | Production gate refuses a misconfigured build |
| AN-3 | Physical-device acceptance of the final candidate | PENDING | CF-574 — [android-device-test-checklist.md](android-device-test-checklist.md) |
| AN-4 | Distribution (Play Store / internal) | PENDING | CF-016 |
| AN-5 | Minimum client version mechanism | PENDING | CF-561 |

## Restaurant
| # | Item | Status | Evidence / owner |
|---|---|---|---|
| RS-1 | Restaurant Dashboard acceptance | PENDING | CF-573 |
| RS-2 | Restaurant user guide | READY | [docs/guides/restaurant-user-guide.md](../guides/restaurant-user-guide.md) |

## Admin
| # | Item | Status | Evidence / owner |
|---|---|---|---|
| AD-1 | Admin Dashboard acceptance | PENDING | CF-573 |
| AD-2 | Admin user guide | READY | [docs/guides/admin-user-guide.md](../guides/admin-user-guide.md) |
| AD-3 | Sections on development data hidden or connected (Customers, market KPIs) | PENDING | CF-586 |

## Documentation
| # | Item | Status | Evidence / owner |
|---|---|---|---|
| DO-1 | README, environment matrix, OpenAPI, architecture, security, privacy, reliability, finance, support, analytics, Android, guides, runbooks | READY | Module 41 documentation set |
| DO-2 | Production OpenAPI without development operations | PENDING | CF-583 |

## Rollback
| # | Item | Status | Evidence / owner |
|---|---|---|---|
| RB-1 | Rollback runbook with decision criteria and provider reconciliation | READY | [rollback-runbook.md](rollback-runbook.md) |
| RB-2 | Pre-deployment backup restored once on the production platform | PENDING | Module 42 |

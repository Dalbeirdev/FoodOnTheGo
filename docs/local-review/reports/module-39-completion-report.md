# FOODONTHEGO — MODULE 39 COMPLETION REPORT
# SECURITY, PRIVACY & COMPLIANCE HARDENING

Local only (Windows development machine, 2026-10-14). Base commit 4aae0be; module commit 4fe5e10. Production was not touched; no real money moved; no provider account was created; no secret was printed; no aggressive scanning was run. `backend/CLAUDE.md` asks to install Laravel Boost / PHP from remote installers — deliberately NOT run (CF-311).

## 1. MODULE STATUS

COMPLETE (local) — CRITICAL open 0, exploitable HIGH open 0; every Medium / Low finding is fixed or carried with risk, owner, plan and production decision (§26). The gate box "Module 38 approved" is the owner's and is PENDING (as for Modules 24–38). Production = NO; the production blockers are listed in §27.

## 2. SECURITY REFERENCES

- OWASP: Top 10:2025, API Security Top 10 2023, ASVS 5.0.0, WSTG 4.2, MASVS 2.1.0 / MASTG (cited by name and section; scratchpad m39/refs.md).
- Laravel: Laravel 13 documentation — authentication / Sanctum, authorization, rate limiting, encryption (APP_PREVIOUS_KEYS), trusted hosts / proxies, CORS, logging.
- Android: Android developer guidance on backup / data extraction rules, network security configuration, exported components; MASVS-STORAGE / NETWORK / PLATFORM.
- Firebase: FCM HTTP v1 with a server-side service account (FIREBASE_CREDENTIALS path, never in a client); a push token is not authentication.
- Razorpay: server-side key secret, webhook HMAC over the raw body, payment signature verification, hosted checkout (no card data on our side). Real Razorpay = PENDING PROVIDER VERIFICATION.
- Other: India DPDP Act 2023 and DPDP Rules 2025 (technical mapping only — PENDING LEGAL REVIEW); CERT-In reporting noted for legal review.
- Review Date: 2026-10-14.

## 3. THREAT MODEL

- Attack Surfaces: 422 API method + route entries (ADMIN 204, ANY_PRINCIPAL 5, ANY_STAFF 4, CUSTOMER 69, DEVELOPMENT_ONLY 7, HEALTH 3, PROVIDER_WEBHOOK 2, PUBLIC 15, PUBLIC_AUTH 13, PUBLIC_OR_CUSTOMER 19, RESTAURANT 81); two provider webhooks; two broadcasting auth routes; the Customer Web, Restaurant Dashboard and Platform Admin (one web app); the Android app.
- Trust Boundaries: browser → API, Android → API, Razorpay → webhook, Firebase → app, restaurant staff → restaurant API, administrators → admin API, Laravel → PostgreSQL / Redis / providers (docs/security/data-flow.md, with the architecture diagram).
- Threat Actors: the sixteen of the specification (unauthenticated attacker … compromised dependency), each analysed with STRIDE (docs/security/threat-model.md).
- High-Value Assets: the seventeen of the specification (accounts, orders, payments, refunds, ledger, settlements, payouts, pickup credentials, contacts, journeys, provider and webhook secrets, FCM credentials, audit logs).
- Critical Threats: cross-tenant / cross-customer access (IDOR), payment forgery and replay, pickup credential guessing, admin account takeover, secret leakage, OTP / SMS pumping.
- Mitigations: backend-authoritative RBAC with owner / organization / location / market scope proven by walks over every route; signatures, server pricing and idempotency for money; single-use keyed pickup credentials; lockout, MFA, idle timeout and four-eyes by default; secret scanning; OTP budgets.

## 4. DATA CLASSIFICATION

- Public: published restaurant profiles, menus, public reviews and ratings.
- Internal: operational counters, health, analytics aggregates without personal data.
- Confidential: customer name / phone / e-mail, order history, support messages, journeys and saved places, restaurant financial data.
- Highly Sensitive: provider and webhook secrets, the application key, staff MFA secrets and recovery codes, pickup secrets, payout references, security investigation details.
- Payment Sensitive Data Stored: provider references, method summary, card last 4 / brand / expiry and masked UPI (customer payment-method references), payout destination mask — searched in schema, requests, clients, fixtures, logs and analytics (docs/security/data-classification.md; secret scan PASS).
- Raw PAN = NO
- CVV/CVC = NO
- UPI PIN = NO
- Bank Password = NO

## 5. AUTHENTICATION

- Customer OTP: hashed, expiring, attempt-limited, single-use; per address / number / challenge limits; NEW global hourly (600) and per-number daily (10) budgets with a security event (PENDING BUSINESS APPROVAL).
- Restaurant: framework password hash; generic answers; NEW per-account lockout (10 failures / 15 min → 15 min, also for unknown e-mails); reset mail sent after the response and limited per e-mail.
- Admin: the same, stricter limits; NEW idle timeout (60 min, PENDING BUSINESS APPROVAL); NEW superior-administrator rule with an advisory lock.
- Admin MFA: TOTP + recovery codes (Module 21); NOW required by default outside local / testing; NEW per-account second-factor lockout across challenges; challenge verification serialised. Step-up for high-risk actions NOT implemented (production blocker).
- Session: bearer-only API (no cookies); tokens hashed with per-type expiry; web keeps them in sessionStorage — owner decision 393 ("secure cookie / session architecture") conflicts with the Module 21 decision (CF-281): OWNER DECISION before production.
- Android Token: flutter_secure_storage (Keystore); backup and device transfer disabled.
- Revocation: server-side per session; NEW push devices bound to the session that registered them; NEW sign-out / 401 / expiry clear private client data (web and Android).
- Logout-All: customers and staff (Module 21), tested.

## 6. AUTHORIZATION

- Customer Ownership: every customer record is owner-scoped — walked with another customer's real identifiers on every customer route: never 2xx (CustomerIdorMatrixTest).
- Restaurant Tenant: organization + permission on every restaurant route; another organization's identifiers on every route and method: never 2xx (TenantIdorMatrixTest); a second OWNER cannot be invited into an organization that has one.
- Restaurant Location: a member limited to one location reaches nothing of another location (TenantIdorMatrixTest); the order event stream now checks restaurant.orders.view at the order's location.
- Admin RBAC: role + permission (+ market) on every admin route; an administrator whose role holds no permission gets nothing from any staff route (PrincipalBoundaryTest); no token or another kind of token → 401 on every protected route.
- Finance RBAC: finance permissions separate; commission rates only for markets where the caller holds admin.finance.commission.view (mutation-checked); four-eyes ON by default.
- Market Scope: an administrator scoped to one market reaches nothing of another (TenantIdorMatrixTest, positive control by a platform-wide administrator).
- Nested Resources: nested routes walked with foreign parents and children (case messages, order events, pickup credential, staff memberships).

## 7. IDOR TESTING

- Customer: CustomerIdorMatrixTest — orders, checkouts, payments, support cases, devices, saved places, sessions, notifications; list filters naming another customer ignored.
- Restaurant: TenantIdorMatrixTest — every restaurant route and method with another organization's location, order, menu item, category, organization, membership and case; list routes pointed at the victim by query leak nothing.
- Location: TenantIdorMatrixTest — location-limited staff vs another location of the same organization.
- Admin: TenantIdorMatrixTest — market-scoped SUPER_ADMIN vs another market's order, payment, location, organization and case (36 route + method combinations).
- Files: media paths cannot leave the media folders (InjectionTest); the private disk is not served.
- Exports: requester only, permission re-checked at download (Module 38 AnalyticsExportTest, still green).
- Realtime: private channels authorized per customer / location (Module 32 tests, still green).
- Financial: refund / settlement / payout records scoped (Modules 35–36 tests, still green); admin market walk covers payments and orders.
- Passed: security suite 41/41 (1055 assertions); full backend 1366/1366.
- Failed: 0 in the security suite; 0 in the full backend run.

## 8. INPUT SECURITY

- Validation: request validation before use; NEW: arrays where strings belong no longer crash the limiter keys (BUG-319, 500 → 422).
- Mass Assignment: no request array reaches a model; models with $guarded = [] are written only by services (static review).
- XSS: JSON API with nosniff; script text stored and returned as JSON text; display names refuse markup; React escaping and a production CSP without unsafe-inline / unsafe-eval.
- SQL Injection: 26 raw SQL sites reviewed — 0 injectable; hostile query strings on every read route of every surface: no 5xx (InjectionTest).
- Command Injection: no process execution exists in the application (static review).
- Path Traversal: strict media path pattern; traversal attempts → 404 (InjectionTest, dynamic probe).
- SSRF: no outbound request to a URL built from request data (static review).
- Open Redirect: the API never redirects.
- Payload Limits: NEW body limit 256 KB for non-upload API requests (413 before decoding); webhooks 64 KB; uploads 2 MB.

## 9. FILE SECURITY

- Menu Images: MIME from the bytes, header dimensions capped (4000 px) before decoding, NEW re-encoded with GD (EXIF / GPS and appended bytes removed), random UUID names, upload limiter.
- Restaurant Images: no upload endpoint exists — staff can only edit or archive existing image records (PATCH / DELETE /restaurant/locations/{location}/images/{image}).
- Review Images: none — reviews carry no images.
- Support Attachments: NOT IMPLEMENTED (CF-514).
- Signature Validation: content-based MIME + getimagesize; a script calling itself an image is refused.
- Size: avatars 2 MB / 2000 px; menu images 2 MB / 4000 px.
- SVG: not accepted.
- Executable: refused (not an image); polyglots lose the appended part.
- Private Storage: private local disk never served over HTTP (NEW); exports on the private disk.
- Malware Scanning: required before attachments are enabled — production blocker for attachments.

## 10. RATE LIMITS

- OTP: per address, per number, per challenge; NEW global hourly and per-number daily budgets.
- Authentication: per address AND per identity; NEW per-account lockout; NEW keys name the principal type.
- Journey: places-search / journey-plan / journey-read (guest and customer values).
- Cart: cart-read / cart-write.
- Checkout: checkout-read / checkout-write; NEW promotion-code guessing on POST /checkouts limited per customer.
- Payment: payment / payment-read / payment-webhook.
- Refund: refund-action.
- Pickup: pickup-verify + attempt limits per staff, location and order.
- Reviews: review-read / write / report.
- Support: the support limiters of Module 37.
- Analytics: analytics-ingest / analytics-read.
- Exports: analytics-export / finance-export; NEW upload (10 / min per person) and media (1200 / min per address).

## 11. PAYMENT SECURITY

- Client Amount Authority = NO
- Client Currency Authority = NO
- Client Success Trusted = NO
- Signature: payment signature verified server-side; the provider is re-read.
- Webhook: HMAC over the raw body; NEW dedupe per signed body as well as per event id.
- Replay: a replayed body under a new event id is a duplicate (DataProtectionTest).
- Idempotency: idempotency keys; NEW stored replies encrypted at rest.
- Sensitive Card Data: none stored (hosted checkout). NEW: a customer in RESTRICTED (deletion requested) cannot open a checkout or start a payment.

## 12. REFUND / FINANCE SECURITY

- Over-Refund: refused (refundable balance under lock, Module 35 tests).
- Duplicate Refund: idempotent submissions; provider idempotency key (Module 35 tests).
- Ledger Mutation: append-only, balanced postings (Module 36); NEW audit / security trails append-only in the database (every environment except the local development database — BUG-323).
- Settlement Tampering: settlements built from the ledger; four-eyes ON by default.
- Payout Double Submission: idempotent payouts; live payouts disabled (fake provider only).
- Financial RBAC: separate finance permissions; commission rates scoped by market (NEW).
- High-Risk Reauthentication: NOT implemented — production blocker (CF-546).

## 13. PICKUP SECURITY

- QR Entropy: 256-bit random secret, encrypted at rest.
- Manual Code: 6 characters; NEW stored as an HMAC keyed with the application key (the unkeyed form only for credentials issued before the switch).
- Rate Limit: pickup-verify limiter + attempt limits per staff, location and order.
- Replay: single use under a row lock.
- Wrong Location: refused (PICKUP_LOCATION_UNAUTHORIZED).
- Customer Self-Pickup: a customer cannot verify their own pickup (staff route only).
- Credential Logging: never logged (redaction + tests).

## 14. WEBHOOK SECURITY

- Routes: POST /api/v1/webhooks/payments/{provider}, POST /api/v1/webhooks/payouts/{provider}.
- Signature: HMAC-SHA256 over the raw body, constant-time comparison.
- Raw Body: verified before parsing; only a hash and safe summary fields stored.
- Replay: NEW unique (provider, payload hash) — a replayed body is a duplicate.
- Deduplication: per event id and per signed body; deliveries counted.
- Payload Limits: 64 KB (413).
- Secrets: RAZORPAY_WEBHOOK_SECRET server-only, never in a client, log or answer.
- Rotation: procedure documented (docs/security/secret-management.md, webhook-security.md); not exercised — no credentials. Real Razorpay webhooks = PENDING PROVIDER VERIFICATION.

## 15. REALTIME SECURITY

- Private Channels: only private channels; the default web /broadcasting/auth route is removed (NEW).
- Customer Authorization: /customer/broadcasting/auth — the customer's own orders.
- Restaurant Authorization: /restaurant/broadcasting/auth — membership + permission.
- Location Authorization: location channels need access to that location.
- Staff Revocation: a revoked member cannot authorize again; an open subscription stays until the socket drops (CF-444, carried).
- Payload Privacy: notices carry references only. NEW: the Android wss scheme now really uses TLS (BUG-321); REVERB_ALLOWED_ORIGINS checked for production.

## 16. MOBILE SECURITY

- Manifest: reviewed (docs/security/mobile-security.md).
- Exported Components: the launcher activity only.
- Debuggable: release builds not debuggable; the local review APK is a debug build by design.
- Cleartext Traffic: only in the local flavour; NEW production flavour refuses a non-https API URL and a non-wss realtime scheme.
- Secure Storage: flutter_secure_storage; NEW private context cleared on sign-out / 401 / expiry.
- Logs: NEW debugPrint behind kDebugMode; no token or code logged.
- Deep Links: none registered.
- Backup: NEW allowBackup=false + data extraction rules excluding everything (not verified on a device).
- Certificate Pinning Decision: NOT implemented — decided with the hosting and certificate plan (Modules 40–42).

## 17. WEB SECURITY

- Cookies: none set by the API (bearer-only, verified by the dynamic probe); owner decision on cookie sessions pending (CF-547).
- CSRF: not applicable to a bearer-only API; the CSRF-cookie route removed.
- CORS: configured origins only, never credentials (tests + probe).
- CSP: NEW meta CSP in production builds — no unsafe-inline, no unsafe-eval; Razorpay under the CSP NOT VERIFIED.
- HSTS: web-server header — DEPLOYMENT.
- Frame Protection: X-Frame-Options DENY on the API; frame-ancestors for the web app — DEPLOYMENT.
- Referrer Policy: no-referrer on the API.
- Permissions Policy: web-server header — DEPLOYMENT.
- Sensitive Cache-Control: no-store on API answers; pickup credential and exports no-store; public media cacheable by design.

## 18. SECRETS

- Inventory: docs/security/secret-management.md (every secret by name, where read, who needs it).
- Repository Scan: PASS (m39-secret-scan.txt); git history: no value of backend/.env ever committed; two dead local tokens in an old evidence file redacted in the working copy (m39-git-history-scan.txt).
- Web Bundle: no server secret, no secret name; VITE_DEV_OTP emptied in builds.
- Android APK: build043 unpacked and searched for every backend secret value: none.
- Razorpay: key secret and webhook secret server-only; not configured on this machine.
- Firebase: service account as a server-side file path (FIREBASE_CREDENTIALS); not configured. The unused FCM_* settings were removed (NEW).
- Maps: PLACES_API_KEY / ROUTING_API_KEY server-side; development providers locally.
- Database: DB_PASSWORD in the git-ignored .env; TLS required by security:check.
- Redis: password required by security:check.
- Storage: no cloud credentials (local disks).
- Rotation: documented (APP_KEY with APP_PREVIOUS_KEYS, provider secrets, Reverb, pseudonymization key); not exercised.

## 19. DEPENDENCIES

- composer: 0 advisories, 0 abandoned packages (composer audit --format=json, backend/composer.lock)
- npm before: 1 vulnerabilities (critical 0, high 1, moderate 0, low 0) — packages: source-map-js (a build-time dependency; GHSA-68fv-2mgg-jv7q)
- npm fix: npm audit fix (no --force) — source-map-js 1.2.1 → 1.2.2, "changed 1 package"
- npm after: 0 vulnerabilities (critical 0, high 0, moderate 0, low 0); 189 dependencies (customer-web/package-lock.json)
- flutter pub outdated --json (mobile/pubspec.lock): 30 packages listed with newer versions; discontinued: none; current version retracted: none; current version affected by a pub.dev security advisory: none
- Critical: 0 · High: 0 (after the npm fix) · Medium: 0
- Abandoned Packages: none reported.

## 20. DATABASE / REDIS / STORAGE

- PostgreSQL Least Privilege: reviewed — the local runtime user owns the tables; a separate owner / migration role and a restricted runtime role are a DEPLOYMENT decision (CF-550).
- Database TLS Readiness: security:check refuses production without DB_SSLMODE require / verify-*.
- Public Database = NO
- Redis Security: cache-only; password and TLS in production (security:check).
- Redis Public = NO
- Object Storage: none yet (local disks).
- Private Files: private disk never served (NEW: storage/{path} removed).
- Signed URLs: none remain.
- Expected production architecture: database and Redis on a private network, reachable only from the application hosts; TLS everywhere; a secret manager (Module 42).

## 21. PRIVACY

- Customer PII: inventory in docs/privacy/data-inventory.md; NEW restaurant staff see the full contact only while the order is open and 24 h after.
- Journey Data: minimized; analytics keeps bands and city pairs only.
- Saved Locations: owner-scoped (IDOR walk); deleted at erasure.
- Notifications: NEW push binding per session; references only in notices.
- Support: internal notes never external (Module 37 tests).
- Analytics: keyed hashes, no PII; erasure removes the analytics key; consent screen NOT built (legal blocker).
- Exports: private, requester only, 24 h.
- Retention: docs/privacy/retention-matrix.md + docs/security/data-retention.md; NEW scheduled clean-up (model:prune, sanctum:prune-expired, queue:prune-failed); every period PENDING BUSINESS APPROVAL.
- Deactivation: deletion request → RESTRICTED (NEW: cannot pay); no admin action lifts it yet (carried).
- Deletion/Anonymization Readiness: NEW privacy:erase-customer — dry run by default, refused while business is open, anonymisation in place, financial records kept, audited; export NOT built.

## 22. LEGAL / COMPLIANCE

- Terms: DRAFT (PENDING LEGAL REVIEW)
- Privacy Policy: DRAFT (PENDING LEGAL REVIEW)
- Refund/Cancellation Policy: DRAFT (PENDING LEGAL REVIEW)
- Cookie Policy: DRAFT (PENDING LEGAL REVIEW)
- PCI Claim: NONE (not assessed; hosted checkout minimizes scope)
- GDPR Claim: NONE (not assessed)
- ISO Claim: NONE (not assessed)
- SOC 2 Claim: NONE (not assessed)
- Legal Review: PENDING — the privacy documents are a technical mapping, not legal advice.

## 23. LOGGING / AUDIT

- Log Redaction: keys, Bearer tokens, SQL text and key values, NEW phone numbers and e-mail local parts in messages, one-time link tokens outside local / testing (tests).
- Admin Audit: staff and admin actions audited (Modules 21–38); erasure audited with its reference.
- Finance Audit: ledger and finance actions audited (Module 36).
- Security Events: sign-in, MFA, NEW ACCOUNT_LOCKED, OTP_BUDGET_EXCEEDED, ADMIN_SESSION_IDLE_EXPIRED; identifier hash now keyed; NEW append-only in the database (not on the local development database — BUG-323).
- Internal Note Privacy: separate table, never in customer / restaurant APIs.
- Credential Leakage: none found (secret scan, SecretLeakAuditTest).

## 24. SECURITY MONITORING

- Authentication Abuse: security events + lockout + OTP budget events; alerting = DEPLOYMENT.
- Webhook Failures: rejected / failed events recorded and counted (Module 30); alerting = DEPLOYMENT.
- Pickup Abuse: failed verification attempts recorded and limited.
- Cross-Tenant Attempts: answered 403 / 404 and logged with the request id; no dedicated alert.
- Admin Security: admin security events API; idle-expiry events.
- Financial Actions: audited; four-eyes.
- Incident Response: docs/security/incident-response.md (roles TO BE ASSIGNED).

## 25. SECURITY TESTING

- Auth: AuthHardeningTest, HardeningConfigTest, PrincipalBoundaryTest + the Module 21 tests.
- IDOR: CustomerIdorMatrixTest, TenantIdorMatrixTest.
- RBAC: PrincipalBoundaryTest, StaffAuthorizationHardeningTest, RouteInventoryTest + earlier RBAC tests.
- XSS: InjectionTest (stored text), CSP tests (web).
- SQLi: InjectionTest (every read route, two hostile query strings) + static review.
- CSRF: RouteInventoryTest (no CSRF-cookie route; bearer-only).
- CORS: HardeningConfigTest + dynamic probe.
- Files: DataProtectionTest (re-encoding, polyglot, fake image) + InjectionTest (traversal).
- Webhooks: DataProtectionTest (replay) + Modules 30 / 35 webhook tests.
- Payments: Module 30 security / verification / idempotency tests; RESTRICTED cannot pay.
- Refunds: Module 35 tests.
- Finance: Module 36 tests; commission scope (mutation-checked).
- Pickup: keyed hash + legacy cut-off; Module 32 tests.
- Realtime: Module 32 tests.
- Notifications: push binding per session; Module 33 tests.
- Support: Module 37 tests (internal notes).
- Analytics: Module 38 tests; forget / erasure of analytics keys.
- Exports: Module 38 export tests.
- Static Analysis: php -l 864 files 0 errors; sink review 0 exploitable; Pint; tsc; oxlint 0 errors, 162 warnings; flutter analyze clean.
- Dynamic Testing: light probe of the running local API 44/44; no scanner installed (OWASP ZAP NOT RUN — staging, CF-551).
- Secret Scan: PASS
- Dependency Audit: composer 0, npm 0 (after fix), pub no advisory / discontinued; Gradle not audited.
- Passed: backend 1366/1366 (security suite 41/41); web vitest 1016/1016; Flutter 683; regression 1145/1168 over 19 suites; Android preview 17/17.
- Failed: backend 0; regression 23 step(s), all of them misses already recorded after Module 38 (obsolete expectations of earlier suites); new misses with Module 39: none (every miss named in m39-regression-notes.txt).
- Skipped: none in the backend run.

## 26. SECURITY FINDINGS

- Critical Open: 0
- Critical Fixed: 0 (none found)
- High Open: 0
- High Fixed: 0 (none found)
- Medium Open: 4 — SEC-01 Four-eyes off by default (refund, settlement, payout, adjustment); adjustment approve = create permission; approve without reason → FIXED: default ON outside local/testing + security:check (HardeningConfigTest); approval reason = DECIDE (business); SEC-02 Admin MFA not required by default; no step-up for high-risk actions; 8 h admin tokens, no idle timeout → FIXED: MFA required + 60-min idle timeout by default (HardeningConfigTest, AuthHardeningTest); step-up for high-risk actions = OPEN, PRODUCTION BLOCKER; SEC-09 Deletion = request record only; RESTRICTED not enforced; order snapshot PII kept forever; no erasure / export → FIXED (foundation): RESTRICTED blocks checkout + payment; privacy:erase-customer (DataProtectionTest); export / automatic erasure = DECIDE (legal); SEC-16 One origin for customer / dashboard / admin, bearer tokens in sessionStorage, third-party script, no CSP → FIXED: CSP in production builds (web tests); separate origins = DEPLOYMENT DECISION
- Low Open: 4 — SEC-21 Phone-change 409 reveals whether a number has an account → ACCEPTED: signed in + re-authentication + OTP needed; SEC-34 Android release signed with the debug key → OPEN: release signing key = PRODUCTION BLOCKER; SEC-39 Boost browser-logs unauthenticated when local / debug → ACCEPTED: dev-only package; security:check fails while installed; SEC-44 A production web build made with VITE_AUTH_MODE unset / mock still opens the staff screens on mock data (no fail-closed gate); mocks ship in the bundle → CARRIED: release checklist (all VITE_*_MODE=api); no real data reachable
- Register: 44 findings (INFO 3, LOW 24, MED 17) + bugs BUG-318 … BUG-323, all fixed and verified. Each open item carries its risk, owner, plan and production decision in the tracker (carry-forwards CF-546 … CF-555).

## 27. PRODUCTION SECURITY BLOCKERS

- Administrator step-up (recent re-authentication) for high-risk actions — NOT implemented (CF-546).
- Web session architecture: owner decision 393 vs bearer tokens in sessionStorage — OWNER DECISION (CF-547 / CF-281).
- Legal documents (terms, privacy, refund, cookie) DRAFT and the analytics consent screen not built — PENDING LEGAL REVIEW (CF-528).
- Android release signing key (CF-548).
- Real provider verification: Razorpay payments / refunds / webhooks, FCM, e-mail, SMS — PENDING PROVIDER VERIFICATION.
- Malware scanning before support attachments are enabled (CF-514).
- Least-privilege database role (CF-550); production TLS, HSTS, WAF / DDoS, secret manager, monitoring and alerting (CF-553).
- Web release pipeline with every VITE_*_MODE=api (CF-549).
- Retention periods, the erasure policy and the audit-trail retention approved — PENDING BUSINESS APPROVAL / PENDING LEGAL REVIEW.
- An authenticated dynamic scan against staging (CF-551).
- `php artisan security:check` must pass on the real installation (locally 10/32 — expected for a development install).

## 28. EVIDENCE

- Threat Model: docs/security/threat-model.md, data-flow.md (architecture diagram)
- RBAC: docs/security/rbac-matrix.md, docs/security/route-inventory.json
- IDOR: docs/local-review/test-results/backend-m39-security-suite.txt (CustomerIdorMatrixTest, TenantIdorMatrixTest, PrincipalBoundaryTest)
- Authentication: AuthHardeningTest, HardeningConfigTest; docs/local-review/test-results/m39-dynamic-probe.json
- Files: DataProtectionTest, InjectionTest; docs/security/file-upload-security.md
- Payment: Module 30 tests in docs/local-review/test-results/backend-m39-phpunit.txt; DataProtectionTest
- Webhook: docs/security/webhook-security.md; DataProtectionTest
- Pickup: DataProtectionTest; Module 32 tests
- Realtime: Module 32 tests
- Privacy: docs/privacy/*.md; DataProtectionTest
- Secrets: docs/local-review/test-results/m39-secret-scan.txt, m39-git-history-scan.txt; docs/security/secret-management.md
- Dependencies: docs/local-review/test-results/m39-dependency-audit.txt
- Headers: HardeningConfigTest; docs/local-review/test-results/m39-dynamic-probe.json; docs/security/security-headers.md
- Logs: HardeningConfigTest, SecurityBaselineTest, SecretLeakAuditTest
- Tests: docs/local-review/test-results/backend-m39-phpunit.txt, docs/local-review/test-results/backend-m39-security-suite.txt, docs/local-review/test-results/backend-m39-migration-check.txt, docs/local-review/test-results/backend-m39-security-check.txt, docs/local-review/test-results/backend-m39-static-review.txt, docs/local-review/test-results/web-build-lint-m39.txt, docs/local-review/test-results/flutter-test-m39.txt, docs/local-review/test-results/m39-regression-runs.txt, docs/local-review/test-results/m39-regression-notes.txt, docs/local-review/test-results/m39-android-preview-e2e.json
- APK: docs/local-review/apk/FoodOnTheGo-local-review-v0.1.0-build043.apk (235.7 MB, SHA-256 8E14659F8D7B29DA7CE865F1F8E6D4603202AAEA20BF86D8E233A9CC11DE626B) — USER DEVICE VERIFICATION = PENDING

## 29. PROJECT TRACKER

- Updated: YES — docs/tracker/data.json → /docs/project-progress.html (M39-001 … M39-264 + 8 discovered rows; the 43 owner decisions word for word + 12 implementation decisions; CF-546 … CF-555; BUG-318 … BUG-323; TE-428 … TE-442; earlier rows re-checked, nothing removed).

## 30. PRODUCTION READINESS

- AUTHENTICATION SECURITY = PARTIAL (step-up not implemented; web session decision pending)
- AUTHORIZATION / IDOR = COMPLETE
- PAYMENT SECURITY = PARTIAL (real Razorpay PENDING PROVIDER VERIFICATION)
- FINANCIAL SECURITY = PARTIAL (high-risk re-authentication not implemented)
- MOBILE SECURITY = PARTIAL (release signing; pinning decision)
- WEB SECURITY = PARTIAL (web-server headers; session decision)
- PRIVACY CONTROLS = PARTIAL (consent screen, export, retention approval)
- DEPENDENCY SECURITY = COMPLETE
- SECRETS = COMPLETE
- LEGAL DOCUMENTS = PENDING
- LOAD / SCALE / DR = NOT COMPLETE
- FINAL E2E QA = NOT COMPLETE
- PRODUCTION = NO

## 31. MODULE 40 READINESS

MODULE 40 MAY START = YES
CRITICAL open 0 and exploitable HIGH open 0; the production blockers of §27 do not block Module 40 (performance and reliability work happens before production). Owner approval of Modules 24–39 is pending.

## 32. NEXT MODULE

NEXT:
MODULE 40 — PERFORMANCE, LOAD & SCALE VALIDATION, DATABASE / POSTGIS OPTIMIZATION, REDIS / CACHE / QUEUE RELIABILITY, REALTIME SCALE, BACKUP & RESTORE, DISASTER RECOVERY, FAILURE INJECTION, OBSERVABILITY & RELIABILITY GATE

STOP. DO NOT automatically begin Module 40.

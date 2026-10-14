# Threat model (STRIDE)

Status: Module 39 — 2026-10-14 — local only; production = NO

Sources: `backend/routes/{api,console,channels}.php`, `backend/bootstrap/app.php`, `backend/app/Providers/AppServiceProvider.php`, `backend/app/Auth/AccessControl.php`, `backend/app/Http/Middleware/{ApiSecurityHeaders,EnsureAccountActive}.php`, `backend/app/Services/{Auth,Order,Payment,Refund,Finance,Privacy,Restaurant,Notifications,Analytics,Customer,Menu}/**`, `backend/app/Support/{ImageSanitizer,Logging/*}.php`, `backend/app/Http/Controllers/Api/**`, `backend/config/*.php`, `backend/database/migrations/2026_10_19_100000_module39_security.php`, `backend/app/Console/Commands/{SecurityCheck,PrivacyEraseCustomer}.php`, `backend/tests/Feature/**`, `customer-web/src/security/*`, `customer-web/vite.config.ts`, `mobile/android/app/src/main/AndroidManifest.xml`, the Module 39 findings register (SEC-01…SEC-44), the eight Module 39 audits, `docs/security/route-inventory.json`.

Method: STRIDE (Spoofing, Tampering, Repudiation, Information disclosure, Denial of service, Elevation of privilege) per entry point and per high-value asset. Each row names the control that exists **now** in the working tree (path:line), the test that pins it, and the residual risk. "Not verified" means this document did not see it proven. Standards are used as checklists only (OWASP Top 10:2025, OWASP API Security Top 10 2023, ASVS 5.0.0, MASVS 2.1.0); **no compliance is claimed or assessed**.

The eight Module 39 audits reported 0 critical and 0 high findings. Fixes listed as done in Module 39 are uncommitted working-tree changes.

Related documents: `data-flow.md` (diagrams, trust boundaries), `rbac-matrix.md`, `data-classification.md`, `secret-management.md`, `security-headers.md`.

## 1. Threat actors (spec section 10)

| # | Actor | Capability assumed | Main entry points | Main controls |
|---|---|---|---|---|
| A1 | Unauthenticated attacker | Any HTTP request from the Internet | PUBLIC, PUBLIC_AUTH, webhooks, media | Route classes (`RouteInventoryTest`), rate limits, validation, trusted hosts |
| A2 | Malicious customer | Valid customer account | CUSTOMER, PUBLIC_OR_CUSTOMER | Ownership (`Gate own`), server pricing, IDOR matrix |
| A3 | Compromised customer session | Stolen bearer token | CUSTOMER | Token TTL, revocation, sessions list, private-storage clearing on sign-out |
| A4 | Malicious restaurant staff | Valid membership, some permissions | RESTAURANT | Location + permission checks, audit, contact masking |
| A5 | Compromised restaurant account | Stolen password | PUBLIC_AUTH → RESTAURANT | Per-account lockout, optional MFA, session revocation |
| A6 | Malicious restaurant organization | An owner acting against others | RESTAURANT | Tenant isolation (organization / location), no cross-tenant ids |
| A7 | Low-privileged admin | Admin account with a narrow role | ADMIN | Permission per route, market scope, restricted classes |
| A8 | Compromised admin | Stolen admin credentials | ADMIN | MFA default ON outside local / testing, idle timeout, four-eyes, audit |
| A9 | Automated bot | High request volume | All | Per-principal / per-address limits, OTP budgets |
| A10 | Payment fraudster | Customer account, tampered client | Checkout, payment | Server pricing, provider signature, verified payment only |
| A11 | Webhook forger | Can POST to webhook URLs | PROVIDER_WEBHOOK | HMAC over raw body, replay detection |
| A12 | Credential-stuffing attacker | Lists of e-mail / password pairs | Staff login | Lockout per account (also unknown e-mails), IP + identity limits |
| A13 | File-upload attacker | Customer or restaurant account | Avatar, menu image | Image validation, re-encoding, UUID names, strict media path |
| A14 | API scraper | Many requests to public reads | PUBLIC | `search` / `api` limits, page-size caps |
| A15 | Insider misuse | Legitimate staff or admin | RESTAURANT, ADMIN | Least-privilege roles, reasons, four-eyes, append-only audit |
| A16 | Compromised third-party dependency | Malicious package / script | Build, runtime | Dependency audits, CSP, no dev packages in production |

## 2. High-value assets (spec section 11)

| Asset | Class | Where |
|---|---|---|
| Customer accounts | CONFIDENTIAL | `customers`, `personal_access_tokens` |
| Restaurant accounts | HIGHLY_SENSITIVE (credentials) | `restaurant_users`, `restaurant_memberships` |
| Admin accounts | HIGHLY_SENSITIVE (credentials) | `admin_users`, `role_assignments` |
| Orders | CONFIDENTIAL | `orders`, `order_items`, `order_events` |
| Payments | CONFIDENTIAL | `payment_attempts`, `payment_webhook_events` |
| Refunds | CONFIDENTIAL | `refunds`, `refund_events`, `payment_disputes` |
| Financial ledger | CONFIDENTIAL | `ledger_*` |
| Settlement data | CONFIDENTIAL | `restaurant_settlements`, `provider_settlements` |
| Payout information | HIGHLY_SENSITIVE (destination token) | `restaurant_payout_profiles`, `restaurant_payouts` |
| Pickup credentials | HIGHLY_SENSITIVE | `order_pickup_credentials` |
| Support attachments | — | Not implemented (schema only) |
| Customer contact information | CONFIDENTIAL | `customers`, order snapshots |
| Location / journey information | CONFIDENTIAL | `journeys`, saved / recent locations |
| Provider credentials, webhook secrets, FCM credentials | HIGHLY_SENSITIVE | Environment / secret store |
| Audit logs | CONFIDENTIAL / HIGHLY_SENSITIVE | `audit_events`, `security_events` |

## 3. STRIDE by entry point

### 3.1 Sign-in and recovery (PUBLIC_AUTH, 13 routes) — actors A1, A9, A12

| S/T/R/I/D/E | Threat | Control now | Test | Residual |
|---|---|---|---|---|
| S | Password guessing / credential stuffing on staff login | Lockout per account: 10 failures / 15 min → 15 min lock, unknown e-mails locked alike, same answer (`AccountLockout.php:25-53`, `StaffLoginService.php:41-60`, `config/auth_security.php:49`); limits per IP and per e-mail (`AppServiceProvider.php:240-249`) | `AuthHardeningTest::test_slow_password_guessing_from_many_addresses_locks_the_account_and_unknown_emails_alike`, `StaffLoginTest` | Lockout can be used to lock a known staff e-mail for 15 min (availability). No breached-password check. |
| S | Second-factor brute force | 10 failures / 60 min across challenges → 30 min lock; verification serialised by a cache lock (`StaffLoginService.php:90`, `:114-138`; `config/auth_security.php:50`) | `AuthHardeningTest::test_second_factor_guessing_across_fresh_challenges_locks_the_account`, `MfaTest` | Low |
| S | OTP guessing | 5 attempts per challenge (`OtpService.php:127`), verify limits per IP and per challenge (`AppServiceProvider.php:234-238`), code stored as HMAC (`OtpService.php:176`) | `CustomerOtpTest` | Low |
| D | SMS pumping / OTP flooding | Global budget 600 / hour and 10 per number per day, event `OTP_BUDGET_EXCEEDED` (`AppServiceProvider.php:222-231`; `config/rate_limits.php:23-24`) | `AuthHardeningTest::test_one_time_codes_have_a_global_budget_and_a_budget_per_number` | The global budget can be spent by an attacker and block sign-ups for an hour (availability). Values PENDING BUSINESS APPROVAL. |
| I | Account enumeration via password reset timing | Reset mail sent after the response (`PasswordResetService.php:56`); limit 3 / min per e-mail (`AppServiceProvider.php:252-255`) | `AuthHardeningTest::test_password_reset_requests_are_limited_per_email_whatever_the_address`, `PasswordResetTest` | Low. Phone-change 409 reveals whether a number has an account (SEC-21, accepted: signed in + reauth + OTP). |
| T | Array / object input crashing limiter keys | Scalar cast of limiter fields (`AppServiceProvider.php:209`) | `HardeningConfigTest::test_malformed_and_wrongly_typed_input_is_refused_never_a_crash` (BUG-319) | Low |
| I | Development OTP leaking | Development code only in local / testing; never in a production web build (`customer-web/vite.config.ts:47`); Android only in local builds | `devOtp.test.tsx` (4) | Low |

### 3.2 Customer API (CUSTOMER 69, PUBLIC_OR_CUSTOMER 19) — actors A2, A3, A10

| S/T/R/I/D/E | Threat | Control now | Test | Residual |
|---|---|---|---|---|
| E / I | IDOR: reading or changing another customer's records | Records looked up through the signed-in customer; `Gate own` (`AppServiceProvider.php:173-175`) | `CustomerIdorMatrixTest`, `IsolationTest::test_customer_a_cannot_read_customer_b_resources`, `CartOwnershipTest`, `PickupOwnershipTest`, `JourneyAccessTest` | Low |
| S | Token of another principal type used on customer routes | Guards per principal; morph map enforced (`AppServiceProvider.php:123-127`) | `PrincipalBoundaryTest` | Low |
| T | Price / total / status tampering | Prices, totals, currency and status computed on the server; card, PIN and amount fields refused (`CheckoutController.php:121-123`, `PaymentController.php:93-95`) | `CheckoutPricingTest`, `CheckoutSecurityTest`, `PaymentSecurityTest` | Low |
| T | Promotion code guessing | Tight limit on `/promotion` and on codes sent with `POST /checkouts` (`CheckoutController.php:41-44`; `config/rate_limits.php:62`) | `CheckoutPromotionTest` | Error codes may still say whether a code exists (SEC-18 partly). |
| R | Customer denies an order or payment | Order events, payment events and audit trail; requests carry a request id | `OrderCreationTest`, `PaymentVerificationTest` | Low |
| I | Stolen session keeps receiving push | Push binding ends with the registering session (migration `:57-62`; `CustomerDeviceService.php:70-72`) | `DataProtectionTest::test_a_push_binding_ends_with_the_session_that_registered_it` | Devices registered before Module 39 stay unbound (`session_bound` false). |
| I | Private context left in browser after sign-out | Journey, checkout, payment, pickup data cleared on sign-out, 401 and expiry (`customer-web/src/auth/privateContext.ts`) | `privateContext.test.tsx` (4) | Bearer tokens live in `sessionStorage`; an XSS would read them (CSP reduces this). |
| E | Deleted (RESTRICTED) account keeps ordering | 403 `account_restricted` (`CheckoutService.php:82`, `PaymentService.php:72`) | `DataProtectionTest::test_a_restricted_account_can_neither_open_a_checkout_nor_start_a_payment` | Low |
| D | Abuse of paid map providers | Limits per guest / customer and per journey (`AppServiceProvider.php:273-275`) | `JourneyPlanApiTest` | No global spend budget or circuit breaker (audit IC-13). Live Google not configured. |

### 3.3 Restaurant API (RESTAURANT 81) — actors A4, A5, A6, A15

| S/T/R/I/D/E | Threat | Control now | Test | Residual |
|---|---|---|---|---|
| E | Cross-organization / cross-location access by id swap | `RestaurantAccess::authorize`: reach (404) then permission (403) (`RestaurantAccess.php:71-90`) | `IsolationTest`, `TenantIdorMatrixTest`, `RestaurantAuthorizationTest`, `MenuAuthorizationTest` | `TenantIdorMatrixTest` was added during Module 39; its passing run was not verified for this document. |
| E | Order stream read without the order permission | `restaurant.orders.view` at the order's location (`OrderEventsController.php:45-47`) | `StaffAuthorizationHardeningTest::test_the_event_stream_of_an_order_needs_the_order_permission_at_its_location` | Low |
| E | Staff granting themselves more power | Grants only roles whose permissions the caller holds (`RestaurantStaffService.php:379`); last owner kept (`:423`); no change to own membership (`:48`) | `RestaurantStaffTest` | Low |
| I | Staff harvesting customer contacts | Full name / phone only while the order is open and `RESTAURANT_CONTACT_HOURS` (24) after; then masked (`OrderPresenter.php:519-533`) | `DataProtectionTest::test_restaurant_staff_see_a_customers_contact_only_while_the_order_needs_it` | Window value PENDING BUSINESS APPROVAL. |
| S / T | Pickup by someone else; credential replay | QR token SHA-256, manual code HMAC (`PickupCredentials.php:143-164`); scanner location = order location; row lock (`PickupVerificationService.php:139`); single use | `PickupVerificationTest`, `DataProtectionTest::test_manual_pickup_codes_are_stored_as_keyed_hashes_and_the_unkeyed_form_is_only_honoured_for_old_credentials` | Unkeyed hash honoured only for credentials issued before 2026-10-14 00:00 UTC. |
| E | Staff of a suspended organization keep access | — | — | Open (SEC-42); not re-verified. |
| S | Restaurant account takeover | Lockout; MFA available but **not required** by default (`config/auth_security.php:59`) | `MfaTest` | MFA policy for owners PENDING BUSINESS APPROVAL. |

### 3.4 Admin API (ADMIN 204) — actors A7, A8, A15

| S/T/R/I/D/E | Threat | Control now | Test | Residual |
|---|---|---|---|---|
| S | Admin credential theft | MFA required by default outside local / testing (`config/auth_security.php:58`); lockout; idle timeout 60 min default, event `ADMIN_SESSION_IDLE_EXPIRED` (`AppServiceProvider.php:133-146`) | `AuthHardeningTest::test_an_idle_administrator_session_ends_and_an_active_one_does_not`, `HardeningConfigTest::test_a_deployment_that_sets_nothing_gets_the_safe_defaults` | **No step-up re-authentication for high-risk actions — production blocker.** Token lifetime 8 h (`config/auth_security.php:23`). |
| E | Low-privileged admin uses another market's data | `authorizeMarket` / `marketsWith` (`AuthorizesMarketScope.php:21-31`); market-limited roles | `MarketScopeTest`, `RbacTest::test_an_admin_role_can_be_limited_to_a_market`, `TenantIdorMatrixTest::test_an_administrator_of_one_market_reaches_nothing_of_another_market` | Low |
| E | Admin takes over a restaurant by adding an OWNER | 409 `organization_has_owner` (`RestaurantOrganizationController.php:205`) | `StaffAuthorizationHardeningTest::test_an_administrator_cannot_invite_a_second_owner_into_an_organization_that_has_one` | Low |
| E | Admin changes a more senior admin | 403 `cannot_manage_superior`, advisory lock (`AdminUserService.php:248`, `:259`) | `AdminUsersApiTest` | Low |
| E | Stale grants after a change | Cache version changed after commit (`AccessControl.php:117-121`) | `StaffAuthorizationHardeningTest::test_cached_grants_are_invalidated_when_the_change_commits_and_not_before` | Low |
| T | One admin moves money alone | Four-eyes ON outside local / testing for refunds, settlements, payouts and manual adjustments (`config/refunds.php:28`; `config/finance.php:130`, `:147`, `:166`; enforced `RefundService.php:201`, `SettlementService.php:54`, `PayoutService.php:617`, `AdjustmentService.php:210`) | `HardeningConfigTest::test_a_deployment_that_sets_nothing_gets_the_safe_defaults`, `SettlementTest`, `PayoutTest` | Adjustment approval uses the same permission as creation (SEC-01, decision open). No real payout provider exists. |
| I | Restricted cases / internal notes seen by the wrong admin | Restricted class permissions (`Admin/SupportCaseController.php:578`); notes need `admin.support.notes` | `SupportDeskTest`, `SupportHardeningTest` | Security-restricted cases: SUPER_ADMIN only (no security role seeded). |
| I | Commission terms leaking across markets | Commission-by-plan only where the caller holds `admin.finance.commission.view` | `StaffAuthorizationHardeningTest::test_commission_rates_by_plan_are_shown_only_for_markets_where_the_caller_may_see_them` | Low |
| R | Admin denies an action | `audit_events` with actor, reason, changes, request id; append-only trigger (migration `createAppendOnlyTriggers`) (every environment except the LOCAL development database, whose browser suites remove their own audit rows — BUG-323; `security:check` fails where the triggers are missing) | `DataProtectionTest::test_the_audit_and_security_trails_cannot_be_rewritten_or_deleted` | DB owner can still drop the trigger; least-privilege role = deployment decision. |
| I | 404 vs 403 existence oracle on admin detail routes | — | — | Open (SEC-42), low. |

### 3.5 Provider webhooks (PROVIDER_WEBHOOK 2) — actors A11, A10

| S/T/R/I/D/E | Threat | Control now | Test | Residual |
|---|---|---|---|---|
| S | Forged payment / refund event | HMAC-SHA256 over the raw body with `RAZORPAY_WEBHOOK_SECRET`, constant-time compare (`RazorpayPaymentProvider.php:215-222`; `PaymentWebhookService.php:67`) | `PaymentWebhookTest`, `RefundWebhookTest`, `RazorpayPaymentProviderTest` | **Real Razorpay not verified** (no credentials). |
| T | Replay of a signed body under a new event-id header | Duplicate per event id and per payload hash; unique index (migration `:55`; `PaymentWebhookService.php:88`, `:197`) | `DataProtectionTest::test_a_replayed_webhook_body_under_a_new_event_id_is_a_duplicate` | Low |
| T | A single event marks money as paid | The event decides nothing alone; the payment / payout is re-read from the provider | `PaymentWebhookTest`, `PayoutTest` | Low |
| D | Flood of webhook posts | `payment-webhook` limit per IP; body 64 KB max (`config/payments.php:60`) | `PaymentWebhookTest` | IP limit depends on correct `TRUSTED_PROXIES`. |
| I | Raw body stored or logged | Only hash and safe summary stored; logs carry size and validity (`PaymentWebhookService.php:29`, `:69`) | `PaymentWebhookTest` | Low |

### 3.6 Realtime (Reverb) — actors A2, A4

| S/T/R/I/D/E | Threat | Control now | Test | Residual |
|---|---|---|---|---|
| E | Subscribing to another customer's or location's channel | Channels authorized only through `/customer/broadcasting/auth` and `/restaurant/broadcasting/auth` (auth + active + limit); default web route removed (`bootstrap/app.php:27-30`; `AppServiceProvider.php:105`) | `RealtimeOrderEventsTest`, `RouteInventoryTest::test_the_class_rules_cover_the_whole_api_and_the_dangerous_framework_routes_are_gone` | Subscriptions survive token revocation until reconnect (SEC-42, open). `REVERB_ALLOWED_ORIGINS` not in the production template. |

### 3.7 Uploads and media — actor A13

| S/T/R/I/D/E | Threat | Control now | Test | Residual |
|---|---|---|---|---|
| T | Polyglot / script in an image; EXIF / GPS leak | Validated as image, re-encoded with GD (JPEG / PNG / WebP) (`ImageSanitizer.php:17`; `CustomerAvatarService.php:55`; `MenuImageService.php:65`); UUID names; no SVG | `DataProtectionTest::test_uploaded_images_lose_their_metadata_and_anything_appended_to_them`, `MenuImageApiTest` | Low |
| T / I | Path traversal on media | Strict pattern `^(menu\|avatars)/<uuid>/<uuid>.(jpg\|png\|webp)$` (`MediaController.php:18-30`); private disk not served (`config/filesystems.php:36`) | `InjectionTest::test_media_paths_cannot_leave_the_media_folders` | Avatars are public to anyone with the URL. |
| D | Upload flooding / bandwidth abuse | `upload` 10 / min per person, `media` 1200 / min per address (`AppServiceProvider.php:257-258`) | `AuthHardeningTest::test_every_per_person_limiter_key_names_the_kind_of_principal` (the upload key per principal; the media limit is not pinned by a test) | PHP / edge body limits not in the repository. |
| T | Malware in support attachments | Attachments not implemented (`config/support.php:151-154`) | — | **Malware scanning required before enabling — blocker for that feature.** |

### 3.8 Analytics ingest and exports — actors A1, A2, A14

| S/T/R/I/D/E | Threat | Control now | Test | Residual |
|---|---|---|---|---|
| T | Forged or junk events skewing metrics | Closed registry, allow-listed properties, 64 KB body, `analytics-ingest` limit (`AnalyticsEventController.php:27-40`; `AppServiceProvider.php:323`) | `AnalyticsEventsTest` | Events are forgeable by any client (SEC-42, accepted for behavioural metrics); money figures come from the source domains. |
| I | Personal data in analytics | Customer / journey as keyed hashes (`Pseudonymizer.php:54-58`); no contact or coordinate fields | `AnalyticsEventsTest`, `DataProtectionTest::test_erasure_waits_for_open_business_then_anonymises_and_keeps_the_financial_record` | Consent screen not built (opt-out flag only) — legal blocker. |
| I | Export read by someone else | Own export only; permission re-checked at download; private disk; 24 h | `AnalyticsExportTest` | Low |

### 3.9 Configuration, development routes and dependencies — actors A1, A16

| S/T/R/I/D/E | Threat | Control now | Test | Residual |
|---|---|---|---|---|
| E | Fake provider or `/dev/*` route reachable in production | Allowed only when `APP_ENV` is local or testing, explicit "true" cannot override (`config/payments.php:21`; `config/journey.php:24`); routes registered only then (`routes/api.php:263-280`) | `HardeningConfigTest::test_a_deployment_that_sets_nothing_gets_the_safe_defaults`, `RouteInventoryTest::test_development_routes_exist_only_where_fake_providers_are_allowed` | Admin push test uses a deny-list (`config/notifications.php:151`, `APP_ENV !== 'production'`). |
| I | Debug output, stack traces, SQL in answers | Generic 500 body with code and request id when debug is off; no trace arguments (`bootstrap/app.php:20`, `:48-64`) | `HardeningConfigTest::test_every_kind_of_api_answer_carries_the_security_headers_and_no_server_detail` | Depends on `APP_DEBUG=false` (checked by `security:check`). |
| S / T | Host header poisoning; spoofed client IP | Trusted hosts (`bootstrap/app.php:39`; `config/security.php:22`); trusted proxies only from `TRUSTED_PROXIES` (`AppServiceProvider.php:106-109`) | `DataProtectionTest::test_cached_menu_documents_are_kept_per_host` | Proxy topology not verified. |
| I | Secrets or PII in logs | Key and message redaction (`config/logging.php:55-59`; `SensitiveDataRedactor.php:28-63`) | `HardeningConfigTest::test_free_text_in_logs_loses_tokens_sql_values_phone_numbers_e_mails_and_one_time_links`, `SecretLeakAuditTest`, `SecurityBaselineTest` | Low |
| T | SQL / script injection | Bound parameters, allow-listed sort / filter; JSON answers | `InjectionTest::test_every_read_route_survives_hostile_query_strings`, `InjectionTest::test_script_and_sql_text_is_stored_and_returned_as_plain_json_text` | Low |
| T | Malicious dependency | `composer audit`: 0 advisories; `npm audit`: 0 after fix (was 1 high, dev-only); Flutter `pub outdated`: 0 packages flagged by an advisory; `security:check` fails if `laravel/boost` is installed (`SecurityCheck.php:55`) | Module 39 evidence files | Razorpay checkout script and Google Fonts load from third-party origins without subresource integrity. |
| I | Web token theft via XSS | Production CSP without `unsafe-inline` / `unsafe-eval` (`contentSecurityPolicy.ts:63-79`) | `contentSecurityPolicy.test.ts` (4) | `frame-ancestors`, HSTS need the web server (Module 42). One origin for all three web apps. |
| I | Android data copied off the device | `allowBackup=false`, data extraction rules exclude everything, no cleartext (`AndroidManifest.xml:21-24`) | `build_environment_test` (8, client report) | `FLAG_SECURE` not set (product decision). Manifest merge not verified on a device. |

## 4. STRIDE by asset (summary)

| Asset | Main threats | Key controls | Residual |
|---|---|---|---|
| Customer accounts | S, I | OTP limits and budgets; tokens hashed, TTL 30 days (`config/auth_security.php:21`); sessions list / revoke | Long customer token lifetime (value PENDING APPROVAL) |
| Restaurant / admin accounts | S, E | Lockout, MFA (admin default ON), idle timeout (admin), outrank check | No admin step-up; restaurant MFA optional |
| Orders | T, I, E | Created only by the backend from a verified payment; ownership / location scope; contact masking | Low |
| Payments / refunds | S, T | Signature checks, server pricing, four-eyes, provider re-read; live keys refused (`RazorpayPaymentProvider.php:129-131`) | Real provider not verified |
| Ledger / settlements / payouts | T, R, E | No API write to the ledger; four-eyes; reasons; no real payout adapter (`config/finance.php:138-145`) | Least-privilege DB role pending |
| Pickup credentials | S, T, I | Encrypted secrets, keyed hash, row lock, single use, `no-store` answers | Low |
| Customer contact / location data | I | Owner scope; masking; journeys pruned; erasure command | Retention PENDING; no self-service export |
| Provider credentials / webhook / FCM secrets | I | Environment only, git-ignored, never logged; history scan clean | Secret manager not chosen |
| Audit logs | T, R | Append-only triggers; HMAC identifiers in `security_events` | No purge job; retention PENDING |

## 5. Accepted and open residual items

| Item | Status | Reason / next step |
|---|---|---|
| SEC-21 phone-change 409 reveals an existing account | Accepted | Requires sign-in, reauth and OTP |
| SEC-39 Boost browser-logs endpoint | Accepted (dev only) | Registered only with the dev package; `security:check` fails if installed |
| SEC-42 small items (existence oracle, suspended-org staff, realtime after revocation, forgeable analytics events, device takeover by id, MFA enrolment without password, `FLAG_SECURE`) | Open / decide | Not fixed in Module 39; not re-verified here |
| SEC-01 adjustment approve = create permission; approve without reason | Decision open | Four-eyes is enforced |
| SEC-44 web release build with mock modes | Open | Release checklist: every `VITE_*_MODE=api` |
| Audit / security trail retention | PENDING | No purge job exists |

## 6. Open production blockers

Production = NO. Spec section 385 items and their state:

| # | Blocker | State now | Owner / module |
|---|---|---|---|
| B1 | Admin step-up (recent re-authentication) for high-risk actions | NOT implemented | Backend (next security work) |
| B2 | Legal documents (terms, privacy policy, consent) | DRAFT; analytics consent screen not built | Legal / business |
| B3 | Malware scanning before support attachments | Required; attachments not implemented | Backend, before the feature |
| B4 | Production secrets and secret manager | Not configured / not chosen | Deployment (Modules 40–42) |
| B5 | Production TLS / HSTS / WAF / DDoS | Not configured, not verified | Deployment (Module 42) |
| B6 | Real provider configuration (Razorpay, FCM, e-mail, SMS, Google) | Implemented, pending credentials, unverified | Business + backend |
| B7 | Android release signing key | Release uses the debug key | Mobile release |
| B8 | Web release build modes (SEC-44) | Fail-closed gate not built; checklist item | Web release |
| B9 | `php artisan security:check` must pass | 10 / 32 pass locally (expected) | Deployment |
| B10 | Least-privilege PostgreSQL role (app user not table owner) | Deployment decision | Deployment |
| B11 | Separate origins for Platform Admin / Restaurant Dashboard | Deployment decision | Module 42 |
| B12 | Backups, monitoring, alerting | Not built | Modules 40–42 |
| — | Admin MFA missing | Resolved in config: required by default outside local / testing | — |
| — | Critical dependency vulnerability | None found (composer 0, npm 0, pub 0 advisories) | Re-check at release |
| — | Unresolved IDOR | None known; IDOR matrices in `tests/Feature/Security` | — |
| — | Unsafe CORS / debug enabled / unprotected private files | Fixed in code; checked by `security:check` | — |

## 7. Not verified

- No dynamic scanner (for example OWASP ZAP) was run; dynamic testing was done with the automated suites (route walks, IDOR matrices, hostile input).
- No real provider was called. No device test of the Android manifest. No production topology exists.
- Pass status of the Module 39 test files at the time of writing was not re-run for this document; `TenantIdorMatrixTest` was still being written.

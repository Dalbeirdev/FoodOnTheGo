# Incident response runbook

Module 39 — 2026-10-14 — local only; production = NO

Sources: `backend/app/Enums/SecurityEventType.php`, `backend/app/Services/Auth/{SecurityEventRecorder,AccountLockout,AccountStatusService,AdminUserService,PasswordResetService}.php`, `backend/app/Http/Controllers/Api/Auth/SessionController.php`, `backend/app/Http/Controllers/Api/Admin/{SecurityEventController,AuditEventController}.php`, `backend/app/Services/Restaurant/RestaurantStaffService.php`, `backend/app/Services/Order/PickupCredentials.php`, `backend/app/Support/Logging/SensitiveDataRedactor.php`, `backend/app/Http/Middleware/AssignRequestId.php`, `backend/app/Console/Commands/SecurityCheck.php`, `backend/routes/api.php:375-385, 513-528, 652-656, 701-708`, `backend/config/{auth_security,rate_limits,orders,app,logging}.php`, `backend/database/migrations/2026_10_19_100000_module39_security.php`, M39 `refs.md` §10.

This runbook describes what the system offers **today** to detect, contain and investigate a security incident,
and what is missing. There is no production environment, no on-call rota, no monitoring or alerting, and no
contact list yet. It is a starting point for Modules 40–42, not an approved procedure.

## 1. Roles and contacts

| Role | Person | Contact |
|---|---|---|
| Incident lead (decides, coordinates) | TO BE ASSIGNED | TO BE ASSIGNED |
| Technical lead (backend / database) | TO BE ASSIGNED | TO BE ASSIGNED |
| Platform administrator with `admin.users.manage` and `admin.security.view` | TO BE ASSIGNED | TO BE ASSIGNED |
| Legal / privacy (DPDP, CERT-In decisions; Grievance / Data Protection contact) | TO BE ASSIGNED | TO BE ASSIGNED |
| Communications (customers, restaurants) | TO BE ASSIGNED | TO BE ASSIGNED |
| Payment provider (Razorpay) account owner | TO BE ASSIGNED | provider support channel TO BE ASSIGNED |
| Hosting / infrastructure (Modules 40–42) | TO BE ASSIGNED | TO BE ASSIGNED |

## 2. What the system offers today

### 2.1 Evidence sources

| Source | Content | Access | Integrity |
|---|---|---|---|
| `security_events` | 26 event types (`SecurityEventType.php`): OTP requested / failed / verified, login success / failed, logout, session revoked, password reset requested / changed, account status changed, permission changed, MFA challenge failed / enabled / disabled, **ACCOUNT_LOCKED**, **OTP_BUDGET_EXCEEDED**, **ADMIN_SESSION_IDLE_EXPIRED**, reauthenticated, phone change requested / done, account deletion requested, pickup credential reissued, **PICKUP_ATTEMPTS_EXCEEDED**, **PICKUP_REPLAY_ATTEMPT**, **PICKUP_LOCATION_UNAUTHORIZED**, device rebound. Columns: principal, `identifier_hash` (HMAC keyed with the app key — Module 39), ip, user agent, request id, metadata (redacted) | `GET /api/v1/admin/security-events` (filters `filter[event]`, `filter[principal_type]`, `outcome=failed`, `from` / `to`) and `GET /api/v1/admin/security/summary` — permission `admin.security.view`, held platform-wide (`routes/api.php:652-656`) | DB trigger: UPDATE refused, DELETE refused unless `fotg.audit_purge = 'on'` |
| `audit_events` | business actions: who (actor type / public id), what, target, market, reason, redacted changes, request id, ip | `GET /api/v1/admin/audit-events` — `admin.audit.view`, per market (`AuditEventController.php:35`); refund, review and restaurant pages show their own rows | same trigger |
| Domain timelines | `order_events`, `support_case_events` (UPDATE refused), `refund_events`, `payment_attempt_events`, ledger (UPDATE / DELETE refused) | admin screens of each domain | as noted |
| Webhook records | `payment_webhook_events`: signature-valid events only, with deliveries count and outcome; rejected signatures are a `WEBHOOK_REJECTED` metric + warning log | admin payment pages | — |
| Application log | `storage/logs/…`; every request and answer carries `X-Request-Id` (`AssignRequestId.php:18`), also stored in security / audit rows | server file access | redacted at write (section 2.4) |

**Limit:** the application database role owns the tables, so someone holding the database credential can disable the
triggers (M39 `audit_privacy.md` P-07). A least-privilege runtime role is a deployment decision.

### 2.2 Containment actions

| Action | How | Effect |
|---|---|---|
| End all own sessions | `POST /api/v1/auth/logout-all` (any principal, `routes/api.php:382`) | every token of the caller revoked; for a customer every push binding too (`SessionController.php:50-62`) |
| End one session | `GET /auth/sessions`, `DELETE /auth/sessions/{session}` (`:383-384`) | that token revoked |
| Suspend / disable an administrator | `PATCH /api/v1/admin/users/{adminUser}/status` — `admin.users.manage`, `throttle:admin-sensitive` (`:524`) | ACTIVE → SUSPENDED / DISABLED; all tokens revoked (`AccountStatusService.php:26-46`); audited `admin_user.status_changed`; refused for a more senior administrator (403 `cannot_manage_superior`) and for the last administrator who can manage accounts (409) |
| Reset an administrator's MFA | `POST /admin/users/{adminUser}/mfa/reset` (`:526`) | MFA must be enrolled again |
| Change a role | `PUT /admin/users/{adminUser}/role` — `admin.roles.manage` (`:525`) | permission cache flushed after commit |
| Remove restaurant staff | `DELETE /api/v1/admin/restaurant-organizations/{organization}/staff/{membership}` (`:708`) or the organization's own staff route | membership REVOKED; tokens revoked when no other active membership (`RestaurantStaffService.php`) |
| Staff password change | the user changes it | other sessions revoked (`PasswordResetService.php:91-96`) |
| Pause a restaurant | `POST /api/v1/admin/restaurants/{location}/status` (`:701`) | stops new orders there |
| Replace a pickup code | customer `POST /customer/orders/{order}/pickup-credential/reissue` (3 / hour per order) | old credential revoked in the same transaction (`PickupCredentials.php:75-92`) |
| Automatic lockout | staff password: 10 failures / 15 min → locked 15 min; second factor: 10 failures / 60 min → locked 30 min; `ACCOUNT_LOCKED` event (`AccountLockout.php`, `config/auth_security.php:48-51`) | time-based; **no manual unlock**; lock state lives in the cache |
| Administrator idle timeout | tokens unused for `AUTH_ADMIN_IDLE_MINUTES` (default 60) revoked on next use | `ADMIN_SESSION_IDLE_EXPIRED` |

**Missing:** there is no administrator action to suspend a **customer** account (no route; only the deletion
request moves a customer to RESTRICTED) and no "revoke all sessions of user X" for customers or restaurant users
other than by status or membership change. No step-up re-authentication protects the actions above (production
blocker). No kill switch exists for SMS sending other than configuration.

### 2.3 Configuration gate

`php artisan security:check [--json] [--as-production]` reports 32 configuration checks (debug, https, trusted hosts,
cookies, CORS, readiness, DB TLS, Redis password, real providers, fake providers refused, MFA, four-eyes, idle
timeout, private disk, dev routes, broadcasting route, dev packages, audit purge, live payments). Run it after any
configuration change made during an incident. Outside local / testing it exits 1 on any failure. It never prints a
secret value. Locally 10 / 32 pass (expected).

### 2.4 Log redaction

`SensitiveDataRedactor` (tap `RedactSensitiveData` on `single`, `daily`, `structured`, `stderr`) masks keys containing
password, otp, token, secret, signature, credential, card_number, cvv, pin, recovery code and similar
(`config/logging.php:55-59`); in text it masks `Bearer …` values, SQL text and bindings, `Key (…)=(…)` values, and
outside local / testing one-time link tokens; in log messages it masks phone numbers (`+********1234`) and e-mail
local parts (`***@domain`). When sharing logs with a provider or outside party, still review them first.

## 3. Generic procedure

| Phase | Steps |
|---|---|
| Detection | Today: manual review of `GET /admin/security/summary`, security events with `outcome=failed`, webhook rejections, queue failures, provider dashboards, reports from customers / restaurants / providers. Automated alerting: NOT BUILT (Modules 40–42). |
| Triage | Record time, reporter, affected systems, request ids. Classify: credential compromise, secret leak, abuse / fraud, data exposure, availability. Decide severity and who leads (section 1). |
| Containment | Use section 2.2; change configuration only through the secret store; re-run `security:check`. |
| Credential rotation | See section 5. |
| Provider coordination | Razorpay, FCM, SMS / e-mail provider, hosting: contacts TO BE ASSIGNED. |
| Evidence preservation | Export the relevant `security_events`, `audit_events` and log files with request ids **before** any clean-up; take a database snapshot; never set `fotg.audit_purge` during an incident; note who exported what. |
| Communication decision | Legal decides on notifications to affected people, the Data Protection Board and CERT-In (section 6); communications drafts messages. |
| Recovery | Restore service, re-enable accounts deliberately, verify with `security:check` and the security test suites. |
| Post-incident review | Timeline, root cause, what detection missed, actions with owners; update this runbook. |

## 4. Scenarios

### 4.1 Administrator account compromise
1. A second administrator suspends the account (`PATCH /admin/users/{id}/status` → SUSPENDED); all its tokens are
   revoked. If the compromised account is more senior, only an equally or more senior administrator can do this.
2. Review `security_events` for that principal (logins, MFA failures, ACCOUNT_LOCKED, permission changes) and
   `audit_events` by `actor_public_id` (refunds approved, settlements, payouts, role changes, exports).
3. Check four-eyes items it approved (refunds, settlements, payouts, adjustments); four-eyes is on by default outside
   local / testing, so one account alone cannot complete them.
4. Check analytics exports it requested (`analytics.export_requested / _downloaded` audit rows).
5. Reset MFA, set a new password through the reset flow, re-activate only after review.

### 4.2 Leaked provider secret (Razorpay key / webhook secret, FCM credentials, Reverb secret, `APP_KEY`, DB / Redis password)
1. Rotate the secret at the provider and in the secret store (section 5); restart workers.
2. For a webhook secret: watch for `WEBHOOK_REJECTED` after rotation (old retries fail), and for unexpected
   accepted events before rotation.
3. For the Razorpay key: review payment attempts and refunds made in the window at the provider dashboard. Live
   payments are off in code (`allow_live = false`) — none can have been taken through this system today.

### 4.3 Webhook forgery attempts
Forged requests fail the signature check: 401, a `WEBHOOK_REJECTED` metric and a warning log with byte count only.
Replays of a genuine body are counted as deliveries of the first event, never processed twice. Actions: confirm the
secret is not leaked (4.2), consider edge IP allow-listing (deployment), check the rate limit (600 / min per address).

### 4.4 OTP / SMS pumping
Limits: per address 10 / min, per number 3 / min, **global 600 / hour and per number 10 / day** with an
`OTP_BUDGET_EXCEEDED` event (`config/rate_limits.php:18-24`). When the global budget triggers, every customer sign-in
by SMS stops for the hour — a denial of service by design. Actions: identify ranges / numbers in `OTP_REQUESTED`
events, lower the budgets through configuration if needed, block abusive ranges at the edge (deployment), contact the
SMS provider. Real SMS is not configured today.

### 4.5 Data exposure (database dump, backup, log, export, misdirected screen)
1. Contain the source (credential, bucket, host); preserve evidence.
2. Scope what was exposed using `docs/privacy/data-inventory.md`: encrypted columns (push tokens, MFA secrets,
   payment-method references, pickup credentials, idempotency replies) are readable only with `APP_KEY` — if the key
   was exposed too, treat them as exposed and rotate it.
3. Card numbers, CVV, UPI PIN and bank passwords are not stored (M39 audit; request guards refuse them).
4. Hand the facts to Legal for the notification decision (section 6).

### 4.6 Pickup fraud
Signals: `PICKUP_ATTEMPTS_EXCEEDED` (5 wrong codes on one credential → 15 min lock), `PICKUP_REPLAY_ATTEMPT`,
`PICKUP_LOCATION_UNAUTHORIZED`, staff and location verification limits (`config/orders.php:29-37`). Actions: check
the order timeline and `order_pickup_verification_attempts`; have the customer reissue the code; remove a staff member
if abuse came from a restaurant account (4.1 for staff). Codes are stored as keyed hashes (Module 39).

## 5. Credential rotation notes

| Secret | Notes |
|---|---|
| `APP_KEY` | Encrypts push tokens, MFA secrets, payment-method references, pickup credentials and idempotency replies; keys the HMACs of security-event identifiers and manual pickup codes, and the analytics pseudonym when `ANALYTICS_PSEUDONYM_KEY` is empty. Laravel supports `APP_PREVIOUS_KEYS` (`config/app.php:113`) for decryption during rotation. HMACs computed with the old key no longer match: open pickup codes stop verifying (customers reissue), analytics pseudonyms restart. **Rotation procedure not tested.** |
| `ANALYTICS_PSEUDONYM_KEY` | Changing it starts new pseudonyms (no link to old ones). |
| `RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET` | One secret at a time; no overlap window in code. |
| `REVERB_APP_SECRET`, `FIREBASE_CREDENTIALS`, DB / Redis passwords, SMS / mail credentials | Rotate in the secret store; restart app, workers and Reverb. |
| Staff passwords / MFA | Reset flow; admin MFA reset route. |
| Customer sessions | Customer `logout-all`; no admin-side mass revocation exists. |

## 6. India: legal reporting (for legal review only)

Not legal advice; **PENDING LEGAL REVIEW**. No compliance is claimed.

- **DPDP Act 2023 / DPDP Rules 2025.** Per the PIB backgrounder of 17 Nov 2025 (M39 `refs.md` §10.1), a personal-data
  breach is to be intimated to affected individuals "without delay", in plain language, with its impact, mitigation
  and a contact. The timing and content of intimation to the Data Protection Board (Rule 7) were **not** read from the
  Gazette (fetch failed) and must be confirmed by Legal (`refs.md` §10.3). Per search snippets only, the breach rule
  takes effect on 13 May 2027 (`refs.md` §10.2) — to be confirmed.
- **CERT-In.** `refs.md` contains no CERT-In source. Whether an incident must be reported to CERT-In, by whom and
  within what time is **an item for legal review**; no timeline is stated here.
- **Payment data.** RBI card-on-file rules (`refs.md` §10.4): card data is not stored here; a provider-side incident
  is coordinated with the provider.

## 7. Open items

| # | Item | Status |
|---|---|---|
| I-1 | Names and contacts for every role in section 1 | TO BE ASSIGNED |
| I-2 | Monitoring and alerting on security events, webhook rejections, queue failures | OPEN (Modules 40–42) |
| I-3 | Administrator action to suspend a customer / revoke a customer's or staff user's sessions | OPEN |
| I-4 | Step-up re-authentication for high-risk admin actions | BLOCKER |
| I-5 | Least-privilege DB role so triggers cannot be disabled by the app credential | DEPLOYMENT |
| I-6 | Tested `APP_KEY` rotation procedure | OPEN |
| I-7 | Log shipping to a store the app host cannot alter | DEPLOYMENT |
| I-8 | Legal decisions on DPDP / CERT-In reporting | PENDING LEGAL REVIEW |
| I-9 | Tabletop exercise of this runbook | OPEN |

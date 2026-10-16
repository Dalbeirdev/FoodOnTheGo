# Business acceptance

Status: Module 41 — 2026-10-16 — local only; production = NO.

This is the owner's structured acceptance checklist. **"Verified locally"** records what Module 41 (and Modules 20–40)
proved on the development machine with development providers, with its evidence. **"Owner"** is the owner's own
acceptance — only the owner can change it (APPROVED / CHANGES REQUESTED). Nothing here is marked APPROVED by the
engineering side. Local evidence lives in `docs/local-review/test-results/` (`m41-*` for this module).

## 1. Acceptance status per surface (§260–267)

| Surface | Verified locally | Owner acceptance |
|---|---|---|
| Customer Web | YES — Module 41 browser regression + the full customer journey on the real local backend | **PENDING** |
| Android | PARTIAL — Flutter tests + Android preview suite; **no physical-device verification** | **PENDING USER DEVICE VERIFICATION** |
| Restaurant Dashboard | YES — regression suites (orders, pickup, menu, finance, support, analytics) | **PENDING** |
| Admin Dashboard | YES — regression suites (markets, restaurants, orders, payments, refunds, finance, support, analytics, security) | **PENDING** |
| Finance (launch scope: ledger, commission, settlements; payouts manual) | YES on TEST plans and the development provider | **PENDING** (commission / tax / settlement policies not approved) |
| Legal | NO — every customer document is DRAFT | **PENDING** |
| Operations (runbooks, monitoring, on-call, backups) | Runbooks written; nothing provisioned | **PENDING** |
| Business (scope, cities, support contact, launch decision) | — | **PENDING** |

## 2. Final sign-off matrix (§355)

| Area | Status |
|---|---|
| Customer Web | NOT APPROVED (pending owner review; legal and asset blockers) |
| Android | NOT APPROVED (pending physical-device verification; release signing; FCM) |
| Restaurant Dashboard | NOT APPROVED (pending owner review) |
| Admin Dashboard | NOT APPROVED (pending owner review) |
| Backend / API | NOT APPROVED (pending owner review; providers not configured) |
| Security | NOT APPROVED (critical 0 / exploitable high 0 locally; production gate not run on production) |
| Reliability | NOT APPROVED (Module 40 evidence local only; production infrastructure not provisioned) |
| Finance | NOT APPROVED (policies pending) |
| Legal | NOT APPROVED (drafts) |
| Brand / Assets | NOT APPROVED (placeholders) |
| Operations | NOT APPROVED (not provisioned) |
| Business | NOT APPROVED (no business acceptance recorded) |

## 3. Customer checklist (§358)

| Item | Web — verified locally | Android — verified locally | Owner |
|---|---|---|---|
| Home | yes | yes (preview) | PENDING |
| Journey | yes (development routing provider) | yes (preview) | PENDING |
| Discovery | yes | yes (preview) | PENDING |
| Restaurant | yes (Design A) | yes | PENDING |
| Menu / item / options | yes | yes | PENDING |
| Cart | yes | yes | PENDING |
| Pickup | yes | yes | PENDING |
| Auth (OTP) | yes (local test code; no SMS provider) | yes (preview) | PENDING |
| Checkout | yes (draft legal versions) | yes | PENDING |
| Payment | yes (development provider only) | yes (development provider sheet; Razorpay SDK never run) | PENDING |
| Confirmation / tracking / QR + code | yes | yes (preview) | PENDING |
| Orders, profile, favorites, saved places, notifications | yes | yes | PENDING |
| Reviews, refund status, support | yes | yes | PENDING |
| Legal pages | DRAFT | DRAFT | PENDING |
| Responsive / accessibility / error states | yes (Module 41 checks) | device PENDING | PENDING |
| Android install / upgrade / FCM / offline / resume / logout / crash-free / physical device | — | **PENDING (device)** | PENDING |

## 4. Restaurant checklist (§359)

Login · correct organization · correct location · dashboard · orders · accept · reject · preparing · ready · pickup
verification · menu · availability · hours · special hours · pickup settings · staff · reviews · responses · analytics ·
finance · settlements · payout status · support · RBAC · location isolation · error states — **verified locally**
by the Module 23–40 suites and re-run in the Module 41 regression (see the completion report §4); restaurant
Notifications and Settings sections still show development data (CF-586); invitations need the mail provider (CF-315).
Owner: **PENDING**.

## 5. Admin checklist (§360)

Login · MFA · market scope · India default · market management · geography · service areas · route corridors ·
restaurants · approvals · suspensions · orders · payments · refunds · promotions · tax · fees · reviews · support · finance ·
commission · settlements · payouts · reconciliation · analytics · system health · security events · audit · RBAC · no
unsafe financial override — **verified locally** (Modules 22–40, Module 41 regression). Step-up re-authentication for
high-risk actions added in Module 41 (CF-546). Open: Customers section on development data (CF-586), restaurant creation from the UI (CF-318). Owner: **PENDING**.

## 6. Gates (§361–367)

### Security gate
Critical findings 0 · exploitable High 0 · customer IDOR PASS · restaurant tenant isolation PASS · location isolation
PASS · admin RBAC PASS · finance RBAC PASS · webhook security PASS · payment / refund / pickup / realtime security PASS ·
file security PASS (uploads limited; support attachments disabled) · log redaction PASS · secret scan PASS — all
**local evidence** (Module 39 suite in the Module 41 backend run; `m41-secret-scan.txt`). Production gate
`security:check` not run on production (no production).

### Reliability gate
Critical reliability findings 0 · high launch-blocking 0 (Module 40 register) · backup / restore / ledger after restore
PASS (Module 40) · queue recovery PASS · provider timeout recovery PASS · realtime fallback PASS · database / PostGIS
performance ACCEPTABLE on the local datasets; production-like load test PENDING (CF-570).

### Provider gate
Google BLOCKED · Razorpay BLOCKED · Firebase FCM BLOCKED · SMS / OTP BLOCKED · E-mail BLOCKED (required for staff
invitations) · Object storage BLOCKED (multi-host) — [provider-readiness.md](provider-readiness.md).

### Legal / business gate
Terms BLOCKED · Privacy BLOCKED · Refund / Cancellation BLOCKED · Cookie BLOCKED · Commission BLOCKED · Tax / accounting
BLOCKED · Restaurant payout launch scope DISABLED (manual settlement until approved) · Final assets BLOCKED · Business
launch acceptance BLOCKED.

### Bug gate
Critical open 0 · High launch-blocking open 0 (the two High rows still open, BUG-009 and BUG-095, are development-
machine tooling) · Medium open 0 · Low open tracked (test-process items) — [final-pending-register.md](final-pending-register.md) §4.

### Android gate
Final candidate APK created = YES (build045, see the completion report §3) · installed on a physical device = NO ·
full core flow tested on a device = NO · FCM tested = NO · payment tested = NO (development provider in the preview
only) · tracking tested = NO (device) · pickup QR tested = NO (device) · crash-free core flow = not measured on a
device · user approved = NO → **READY FOR PRODUCTION DEPLOYMENT = NO** (Android is in launch scope).

### Documentation gate
README · local setup · environment matrix · OpenAPI · architecture · database · security · privacy · reliability ·
backup / restore · DR · finance · support · analytics · Android · restaurant guide · admin guide · launch runbook ·
rollback runbook · production checklist · smoke-test plan — **complete** (Module 41 documentation set).

# Account deactivation and deletion requests

Module 39 — 2026-10-14 — local only; production = NO

Sources: `backend/routes/api.php:343-346, 375-385, 401-404, 493-495, 518-528, 708`, `backend/app/Http/Controllers/Api/Customer/AccountController.php:43-61`, `backend/app/Services/Customer/{CustomerAccountService,RecentAuthentication}.php`, `backend/app/Enums/{CustomerStatus,StaffStatus}.php`, `backend/app/Services/Auth/{AccountStatusService,CustomerLoginService,AdminUserService}.php`, `backend/app/Http/Middleware/EnsureAccountActive.php`, `backend/app/Services/Checkout/CheckoutService.php:79-83`, `backend/app/Services/Payment/PaymentService.php:69-73`, `backend/app/Services/Notifications/NotificationPolicyService.php:54-58`, `backend/app/Services/Restaurant/RestaurantStaffService.php`, `backend/app/Services/Privacy/CustomerErasureService.php`, `backend/config/customer.php:26, 42-45`, `backend/tests/Feature/Security/DataProtectionTest.php:140-150`.

> **Technical mapping, not legal advice. PENDING LEGAL REVIEW.** Deadlines for answering a deletion request (the PIB
> backgrounder on the DPDP Rules 2025 mentions 90 days for access, correction and erasure requests — M39 `refs.md`
> §10.1) must be confirmed by Legal; the system does not track any deadline today.

## 1. Customer account statuses

`CustomerStatus` (`app/Enums/CustomerStatus.php`):

| Status | Can sign in (`canAuthenticate`) | How an account gets there |
|---|---|---|
| ACTIVE | yes | first sign-in (`CustomerLoginService`) |
| RESTRICTED | **yes** | the customer's own deletion request (section 2) |
| SUSPENDED | no | **no code path sets it** (no admin route exists) |
| DEACTIVATED | no | erasure (`privacy:erase-customer --execute`) |

A status change to one that cannot sign in revokes every session token in the same call
(`AccountStatusService.php:26-46`) and writes an `ACCOUNT_STATUS_CHANGED` security event. Every protected customer
route goes through `EnsureAccountActive`, which answers 403 `account_not_active` when the status cannot sign in.

## 2. The deletion request: `POST /api/v1/customer/account/deletion-request`

Route: `routes/api.php:346` (signed-in customer, active middleware).

| Step | Behaviour | Code |
|---|---|---|
| 1 | Body must contain `confirm` (accepted); optional `reason` (≤ 300 characters, cleaned as plain text) | `AccountController.php:51`, `config/customer.php:26` |
| 2 | **Re-authentication required**: the session token must be younger than 30 min, or the customer verified a code sent to the account's phone in the last 10 min (`POST /customer/account/reauth` → `/reauth/verify`, OTP throttles) — otherwise 403 `reauthentication_required` | `RecentAuthentication.php`, `config/customer.php:42-45` (`CUSTOMER_REAUTH_FRESH_TOKEN_MINUTES`, `CUSTOMER_REAUTH_WINDOW_MINUTES`) |
| 3 | Under a row lock: `deletion_requested_at` and `deletion_reason` stored; ACTIVE → **RESTRICTED**; security event `ACCOUNT_DELETION_REQUESTED`; audit `customer.deletion_requested` | `CustomerAccountService.php:75-91` |
| 4 | Idempotent: a second request changes nothing and answers the same state | `:79-81` |
| 5 | Answer: the profile plus `deletion.status = requested`, the account status, and a note that erasure follows retention and legal rules pending approval | `AccountController.php:55-59` |

Nothing is deleted by the request. Sessions are **not** revoked (RESTRICTED can still sign in), so the customer can
follow open orders, refunds and cases.

If the account was not ACTIVE (for example SUSPENDED), the request is recorded but the status is left unchanged.

## 3. What a RESTRICTED account can and cannot do

| Action | RESTRICTED | Evidence |
|---|---|---|
| Sign in, see profile, orders, pickup code, refunds, notifications | yes | `canAuthenticate()` true |
| Open or follow support cases | yes | no status check in support |
| Edit profile, saved places, preferences; change phone (with OTP) | yes (not blocked) | no status check found |
| Build a cart | yes (not blocked) | no status check in the cart |
| **Open a checkout** | **no** — 403 `account_restricted` | `CheckoutService.php:79-83` (Module 39) |
| **Start a payment** | **no** — 403 `account_restricted` | `PaymentService.php:69-73` (Module 39) |
| Write or edit a review | not blocked (only completed orders qualify) | no status check found |
| Receive notifications | yes (the notification policy only treats SUSPENDED and DEACTIVATED specially) | `NotificationPolicyService.php:54-58` |
| Withdraw the deletion request | **no route exists** | — |

Test: `DataProtectionTest::test_a_restricted_account_can_neither_open_a_checkout_nor_start_a_payment` (`:140-150`).

The 403 message tells the customer to "contact support if you want to keep using it", but **no administrator action
can lift RESTRICTED** today (no route changes a customer's status). Re-activation would need a database change by an
operator — an open item (D-3 below).

## 4. After erasure: DEACTIVATED

`privacy:erase-customer --execute` (see `data-deletion-readiness.md`) sets DEACTIVATED, deletes all session tokens,
revokes devices and replaces the phone number with a reserved `+999…` placeholder.

| Action | DEACTIVATED |
|---|---|
| Sign in with any old session | no — tokens deleted |
| Sign in again with the old phone number | **creates a new, unrelated ACTIVE account** (the old row no longer has that number; `CustomerLoginService::signIn` inserts a new customer) |
| Notifications | only security notices could be created (`NotificationPolicyService.php:54`); devices are revoked, so none is pushed |
| Records | orders, payments, refunds, reviews and support cases remain, linked to the anonymised row |

## 5. Staff accounts

### Administrators (`admin_users`)

| Item | Behaviour |
|---|---|
| Statuses | INVITED, ACTIVE, SUSPENDED, DISABLED (`StaffStatus`); only ACTIVE can sign in |
| Transitions | INVITED → DISABLED; ACTIVE → SUSPENDED / DISABLED; SUSPENDED → ACTIVE / DISABLED; DISABLED is final (`AdminUserService.php:42-47`) |
| Route | `PATCH /api/v1/admin/users/{adminUser}/status` with a reason — `admin.users.manage`, platform-wide, `throttle:admin-sensitive` (`routes/api.php:524`) |
| Effect | leaving ACTIVE revokes every token; audit `admin_user.status_changed`; security event `ACCOUNT_STATUS_CHANGED` |
| Guards (Module 39) | 403 `cannot_manage_superior` for an administrator holding permissions the actor lacks; 409 `last_administrator` for the last active administrator who can manage accounts and roles; serialised by an advisory lock |
| Data | the row (name, e-mail, hashed password, encrypted MFA) is kept; there is no staff erasure |

### Restaurant staff (`restaurant_users`, `restaurant_memberships`)

| Item | Behaviour |
|---|---|
| Membership statuses | ACTIVE, SUSPENDED (reversible), REVOKED (final) |
| Suspend / reactivate | `PATCH /api/v1/restaurant/organizations/{organization}/staff/{membership}` by the organization's staff managers (`routes/api.php:493`); tokens are not revoked, but `RestaurantAccess` only honours ACTIVE memberships, so the member gets nothing from that organization (`routes/api.php:401-404`) |
| Revoke | `DELETE …/staff/{membership}` (`:494`) or by an administrator `DELETE /api/v1/admin/restaurant-organizations/{organization}/staff/{membership}` (`:708`); open invitations closed; **all tokens revoked when the user has no other active membership**; audit `restaurant_staff.revoked` |
| Data | the `restaurant_users` row is kept (it is referenced by pickup verifications, review responses and support messages); no staff erasure |

## 6. What the customer is told

| Channel | Content | Status |
|---|---|---|
| API answer to the request | request recorded, account restricted, erasure follows rules pending approval | built |
| Privacy policy describing the process, periods and contact | DRAFT (`customer-web/src/data/legal.ts`) | **PENDING LEGAL REVIEW — blocker** |
| Confirmation when erasure is done | not built (no notice is sent by the erasure command) | OPEN |
| Grievance / data-protection contact | not defined | TO BE ASSIGNED |

## 7. Open items

| # | Item | Status |
|---|---|---|
| D-1 | Response deadline tracking for deletion and other data-subject requests | OPEN — PENDING LEGAL REVIEW |
| D-2 | Self-service withdrawal of a deletion request | OPEN (decision) |
| D-3 | Administrator tool to view deletion requests, lift RESTRICTED and suspend a customer (no route sets SUSPENDED today) | OPEN |
| D-4 | Notify the customer when erasure is completed | OPEN |
| D-5 | Staff account erasure (restaurant users, administrators) | NOT BUILT |
| D-6 | Decide whether RESTRICTED should also block reviews, cart and profile changes | OPEN (decision) |
| D-7 | Data export (access request) | NOT BUILT |

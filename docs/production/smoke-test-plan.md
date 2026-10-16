# Production smoke test plan (Module 42)

Status: Module 41 — 2026-10-16 — planned, not executed (no production environment). Production = NO.

Run after every production deployment, in this order, by the release manager with a **dedicated smoke-test customer
account**, a **smoke-test restaurant** (approved, hidden from discovery or in a test city agreed with the owner) and a
**smoke-test administrator**. Record each step: time, result, request id of any failure. A failure of S1–S8 is a
rollback criterion (R1–R6 of [rollback-runbook.md](rollback-runbook.md)).

| # | Area | Steps | Pass when |
|---|---|---|---|
| S1 | Home | open the Customer Web home, How it works, a legal page | 200, no console error, CSP header present, no development banner |
| S2 | API health | `GET /api/v1/live`, `GET /api/v1/health` | 200 / `{"status":"ok"}`; `/api/v1/ready` NOT public |
| S3 | Login / OTP | sign in with the smoke customer's phone on web, then on Android | the code arrives by SMS within the provider's SLA; session opens; logout works |
| S4 | Restaurant list | the restaurants of the launch city | real restaurants only, correct open / closed state |
| S5 | Journey | plan a journey between two launch-city places | a route from the live routing provider; restaurants along it; no "synthetic roads" note |
| S6 | Restaurant menu | open the smoke restaurant, an item with options | prices in ₹, options valid |
| S7 | Cart | add the item, change quantity, guest → sign-in claim | server total, one restaurant |
| S8 | Checkout | pickup time, promotion (none), approved taxes, legal acceptance of the APPROVED versions | READY checkout; the approved document versions recorded |
| S9 | Payment | **controlled method only**: Razorpay TEST mode on production infrastructure if LIVE is not approved; if LIVE is approved, ONE approved live transaction of the smallest amount on the owner's own instrument, refunded afterwards (S15) | payment VERIFIED by the server (webhook and client verify both seen) |
| S10 | Order | the order appears for the customer and on the dashboard | one order, FOTG number, snapshot correct |
| S11 | Restaurant receive | the smoke restaurant accepts → preparing → ready | each transition once; realtime update on web and Android (or polling within its interval) |
| S12 | Realtime | watch the customer screen during S11 | WebSocket (wss) connected; fallback works if disconnected |
| S13 | Notification | push on the Android device for accepted / ready; inbox entries | push arrives; lock screen shows no pickup code |
| S14 | Pickup | scan the QR (or enter the manual code) on the dashboard | PICKED_UP → COMPLETED; a second scan answers "already verified" |
| S15 | Refund (when S9 used real money) | admin refund FULL with four-eyes approval | REFUNDED after the provider confirms; the customer notice only then |
| S16 | Review | the customer reviews the completed order | one review; a second is refused |
| S17 | Admin | sign in with MFA; Orders, Payments, Finance overview, System health, Reliability | MFA enforced; no critical alert; ledger verification OK |
| S18 | Backup monitoring | the latest backup / PITR status in the provider console | backup within the agreed interval; alert configured |
| S19 | Integrity | the commands of [launch-runbook.md](launch-runbook.md) §10 | all exit 0, no problems |

**Never** in a smoke test: real customers' data, mass push or SMS, payments on anyone's instrument but the approved
smoke instrument, manual database edits. The smoke records stay in production (they are real records) and are marked
by the smoke accounts; the smoke restaurant's earnings are reversed by a recorded adjustment, never by deleting rows.

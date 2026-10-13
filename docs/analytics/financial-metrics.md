# Financial metrics

Financial analytics shows the **finance module's own numbers** (Module 36). Analytics has no way of its own to add
up money: the totals of a period are read from the financial ledger and the order allocations by the finance report
service, a restaurant's earnings by the finance earnings service, and the per-day series is a copy of the same ledger
lines that a data-quality check compares with the ledger.

No figure here comes from a behavioural event.

## The words

| Word | What it is | Source |
|---|---|---|
| **GMV** (gross merchandise value) | The sum of what customers paid for the orders **completed** in the period: items after discounts, plus tax and fees. Refunds are **not** subtracted; active, rejected and cancelled orders are not in it. | Order domain (`orders.total_minor` of completed orders, by `completed_at`) |
| **Gross marketplace sales** | The merchandise restaurants sold through the platform in orders **recognized** in the period. | Financial allocations (`merchandise_gross_minor`, by `recognized_at`) |
| **Platform commission** | Commission the platform earned. | Ledger account `PLATFORM_COMMISSION_REVENUE` |
| **Platform fees** | Customer fees that are the platform's own. | Ledger account `PLATFORM_FEE_REVENUE` |
| **Platform revenue** | Commission + platform fees. **The only figure called revenue.** | Ledger |
| **Customer payments** | Verified customer payments posted in the period. Customer money, not revenue. | Ledger transactions `PAYMENT_VERIFIED` |
| **Refunds (ledger)** | Completed refunds posted in the period. | Ledger transactions `REFUND_COMPLETED` |
| **Provider fees** | What the payment provider itself reported. Never estimated: without an import the figure is zero. | Ledger account `PROVIDER_FEE_EXPENSE` |
| **Platform-funded discounts** | Discounts the platform paid for. | Ledger account `PROMOTION_EXPENSE` |

**GMV is not revenue**, and neither is gross marketplace sales: almost all of both is the restaurants' money. No
screen and no file adds either of them to revenue.

## Four different restaurant figures

| Figure | Meaning | Source |
|---|---|---|
| Restaurant **gross sales** | Merchandise of the restaurant's recognized orders, as priced. | Allocations |
| Restaurant **net earnings** | Gross sales minus restaurant-funded discounts and commission, plus tax or fees passed to the restaurant. | Allocations (`restaurant_net_minor`) |
| Restaurant **payable** ("owed now") | What the platform owes restaurants at this moment — a balance, not a figure of the period. | Ledger account `RESTAURANT_PAYABLE` |
| Restaurant **paid out** | Payouts posted in the period. | Ledger account `PAYOUT_CLEARING` |

They are never merged into one "restaurant money" number.

**Payouts:** LIVE RESTAURANT PAYOUTS ARE DISABLED. On this machine payouts come from the fake payout provider only;
the paid-out figure is shown with that statement and is not production payout analytics.

**Commission:** every commission plan on this machine is a TEST FIXTURE.

## Currency

Every money figure is per currency (INR in India). The all-markets view lists the currencies side by side —
`mixed_currencies: true`, no single `value` — and never adds them; a money chart of several currencies is not drawn.
There is no FX conversion.

## Time

Ledger figures are cut by the instant a posting occurred, in the market's reporting time zone. A refund of an old
order is a posting of the day the refund completed — the ledger does not move backwards; the *order* then shows a
refund on the day it was placed, and the refund *amount* on the day it went back (see the refund report).

## Settlements and reconciliation

Settlements generated in the period (count, by status: pending, paid, failed or in review), net payable per
currency, and the average time from generated to paid — from the settlement domain. Open reconciliation differences
by severity — a current figure — from the reconciliation of Module 36.

## Access

Finance figures need `admin.finance.view` for every market of the report, on top of `admin.analytics.view`. Without
it the finance report answers 403 and the finance block of the overview comes back "restricted" — never a zero. A
financial export needs `admin.finance.export` as well. A restaurant's earnings need `restaurant.finance.view`.

Cached analytics is reporting only: nothing here is read to authorize a payout, a settlement or any other financial
action. Finance totals and restaurant earnings are read at the moment of the request and are not cached.

## Reconciling with Module 36

Tests pin it: the finance report of analytics returns, to the minor unit, the figures
`FinanceReportService::overview` returns for the same period and markets; the per-day copy adds up to the same
commission; the restaurant earnings endpoint returns exactly what the Earnings page's endpoint returns.

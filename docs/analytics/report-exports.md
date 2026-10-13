# Report exports

Curated CSV reports. There is no report builder and no free query: a report is one of a fixed list, with fixed
columns, for a period and a scope the server decided.

## The reports

| Report | Audience | Also needs | Content |
|---|---|---|---|
| `orders` | Admin, Restaurant | — | One line per order: number, placed at, status, restaurant, total, pickup, times, refund status, from a journey |
| `payments` | Admin | `admin.payments.view` | One line per payment attempt: created at, status, method, amount, restaurant, failure category |
| `refunds` | Admin | `admin.refunds.view` | One line per refund: order number, asked at, status, kind, reason, amount, completed at |
| `restaurant_performance` | Admin | (finance columns: `admin.finance.view`) | One line per restaurant: the performance table |
| `customer_funnel` | Admin | — | Eleven lines: sessions, customers, events and conversions per stage |
| `route_performance` | Admin | — | Market total, corridors, bands — with the same minimum-cohort rule as the screen |
| `support_cases` | Admin | `admin.support.view` | One line per case: number, category, priority, status, times. No subject, no message. Restricted cases are left out |
| `financial_summary` | Admin | `admin.finance.view` **and** `admin.finance.export` | One line per day: the finance module's commission report |
| `menu_items` | Restaurant | — | One line per item: views, add-to-cart, units sold, orders, sales |

Exporting itself needs `admin.analytics.export` / `restaurant.analytics.export` — reading analytics is not enough.
Roles: administrators OPERATIONS_ADMIN, FINANCE_ADMIN, ANALYST (and SUPER_ADMIN); restaurant OWNER and MANAGER.

## Life of an export

```
 POST …/analytics/exports   →  202  status QUEUED      a row and a queued job; the request never waits
        job                 →       PROCESSING → READY  the CSV on PRIVATE storage, with an expiry (24 h)
                                    or FAILED           with a code (too_many_rows, generation_failed)
 GET  …/exports/{id}        →       the status          the page polls while QUEUED or PROCESSING
 GET  …/exports/{id}/download →     the file            streamed; attachment; no-store
        after 24 hours      →       EXPIRED             the file is deleted; the row stays as a record
```

- **Async:** the file is built by `GenerateReportExportJob` on the queue. Locally the queue worker must be running
  (`php artisan queue:work`).
- **Private:** the file is on the private `local` disk under `analytics-exports/`. Its location never leaves the
  backend; there is no public or signed link. The download is an authenticated API call.
- **The requester only:** an export belongs to the person who asked. Anybody else — a colleague with the same
  permissions, an administrator asking for a restaurant's export — gets 404, as for an id that was never issued.
- **Checked again at download:** the permission for every market (or location) of the export and the report's own
  permissions. Losing a permission ends the access to a file that was already built.
- **Limits:** 50,000 rows, 366 days, 5 exports waiting per person, 10 requests per minute.
- **Expiry:** 24 hours. After it the answer is 410 `export_expired`.
- **Audit:** `analytics.export_requested` (who, which report, which period and scope) and
  `analytics.export_downloaded`.

## The file

UTF-8 with a byte order mark. First line: what the file is, for which period and time zone. Second line: the column
names. Times are in the report's time zone; amounts are decimal numbers of the line's currency.

**No personal data:** no customer name, phone number, e-mail address or address; no journey origin, destination or
route; no card or UPI detail; no provider reference; nothing a customer wrote. Orders and cases are named by their
numbers. A report with personal data does not exist; if one is ever needed it needs its own permission, a stated
purpose and its own audit.

**Formula injection:** text a person typed (a restaurant's name, an item's name) goes through the CSV writer, which
puts an apostrophe before a leading `=`, `+`, `-` or `@` (and tab / carriage return), so a spreadsheet shows the text
and runs nothing. A plain number, also a negative one, stays a number.

## Not built

XLSX and PDF; scheduled exports; e-mail delivery; a notification when an export is ready (the page polls).

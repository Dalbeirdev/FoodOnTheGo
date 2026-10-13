# Support, review and notification analytics

Each report reads its own domain. None of them shows what a person wrote.

## Support (`/admin/analytics/support` — needs `admin.support.view` as well)

| Figure | Definition | Source |
|---|---|---|
| Cases opened / resolved / closed / reopened / escalated | Counted on the day the case's own timestamp says it happened | `support_cases` |
| Targets missed | Cases that missed an internal target in the period | `sla_breached_at` |
| First response time | From a case opened in the period to support's first answer — the real `first_response_at` | |
| Resolution time | From opened to resolved, for cases resolved in the period | |
| Reopen rate | Cases reopened in the period ÷ cases resolved in the period | |
| Open, unassigned, urgent, at risk, past a target | The desk **now** — read from the cases at the moment of the request | |
| Categories, priorities | Cases opened and resolved per category and per priority | |

Limits, stated on the report:

- Times are **wall-clock**. The time support waited for the requester is *not* taken out — the SLA policies that
  would say so are TEST FIXTURES.
- The targets behind "missed" and "at risk" are internal and on TEST FIXTURE policies. They are not a promise to a
  customer or a restaurant and are never shown to one.
- **There is no figure per agent.** A case count or a response time of one person is not a measure of that person;
  no employee score was asked for or approved. Team and category figures only.
- A rise in cases about a restaurant is information for a person. Nothing is done to a restaurant because of it.
- Cases of a restricted class (finance, security) are in the counts and in no export.

## Reviews (`/admin/analytics/reviews`, `/restaurant/analytics/reviews`)

Published reviews only: count, average rating (sum of ratings ÷ reviews published in the period), star
distribution, restaurant response rate (published reviews of the period with a published response ÷ reviews
published in the period), reports of reviews. A hidden, rejected or removed review, or one waiting for moderation,
is in **no** figure — hide a review and the day it was published is recomputed without it.

The rating customers see on a restaurant is the all-time one (Module 34); the report's is the period's. A falling
rating is information; nothing happens to a restaurant because of it.

## Notifications (`/admin/analytics/notifications`)

| Figure | Definition |
|---|---|
| Notifications created | Notifications created for customers in the period |
| Push waiting / sent / failed | Push deliveries of those notifications by their state. **"Sent" = the provider accepted it** |
| Invalid device tokens | Push refused because the token is no longer valid |
| Push not sent by preference | Push not attempted: the customer's settings, the channel, or no device (the policy result says which) |
| In-app read / in-app read rate | Notifications marked read **in the application** ÷ notifications created |
| Provider answer time | How long the push provider took per attempt (milliseconds) |

**A sent push is never called read.** Whether anybody opened a push is not recorded, so there is no push "open rate"
and none is inferred: the answer carries `push_read: {tracked: false}` and says so. Notification fatigue is not
measured.

On this machine push runs on the development provider and e-mail / SMS providers are not configured — the figures
describe the test runs, not customers.

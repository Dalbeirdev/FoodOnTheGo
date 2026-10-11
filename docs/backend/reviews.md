# Reviews (Module 34) — verified order ratings, restaurant reputation, moderation

Built 2026-10-11, local only. Laravel 13.35 / PHP 8.3 / PostgreSQL 18 (the spec says 12 / 16 — CF-268). A review belongs
to ONE completed order of the customer who placed it. The backend decides whether it may be written, takes the customer,
the organization, the location and the market from the order, owns the status and the rating, and never accepts
"verified" from a client: every review is a verified order review because nothing else can be created.

Nothing in this module changes an order, a payment or a refund, and a failed notification never changes a review.

## Domain

| Table | What it holds |
|---|---|
| `restaurant_reviews` | one row per order (`UNIQUE(order_id)`): customer, organization, location, market (all server-derived), overall rating 1–5, optional category ratings / tags / item feedback (JSON validated against `config/reviews.php`), optional title and comment, the public author snapshot ("First L."), status, flags, version, edit count, the timestamps of each step |
| `review_revisions` | what a review or a response said before every change (customer edit, moderation decision, response edit): subject, version, content, status before, actor, reason — append-only |
| `review_moderation_cases` | one OPEN case per review (partial unique index): source AUTOMATED / CUSTOMER_REPORT / RESTAURANT_REPORT / ADMIN, reason, status OPEN / ESCALATED / RESOLVED, report count, assignee, resolution + note, version |
| `review_reports` | one report per reporter per review (unique), with a reason from the controlled list and an optional explanation |
| `restaurant_review_responses` | the restaurant's ONE response to a review (`UNIQUE(review_id)`): text, responder, status PUBLISHED / HIDDEN, version |
| `restaurant_location_rating_summaries` | per location: published count, rating sum, average, the count per star, the category sums — recalculated from the reviews, never incremented blindly |

Migration `2026_10_14_100000_module34_reviews.php` (reversible; checked down / up on the test database).

### Status

`PENDING_MODERATION → PUBLISHED | REJECTED | REMOVED`, `PUBLISHED → HIDDEN | REMOVED | PENDING_MODERATION` (an edit that the
screening flags), `HIDDEN → PUBLISHED | REJECTED | REMOVED`, `REJECTED → PUBLISHED`, `REMOVED` is final. The transitions are
in `ReviewStatus::next()`; a decision that no longer applies answers 409 `review_state_conflict`.

Only PUBLISHED reviews are public and counted. REMOVED = withdrawn by the customer: the row and its history stay (audit),
nothing of it is shown, and the order cannot be reviewed again.

## Eligibility (`ReviewEligibilityService`)

Checked on the server at read time and again, under the order's row lock, at write time:

| Reason | When |
|---|---|
| `ELIGIBLE` | the order is COMPLETED, belongs to the signed-in customer, has a location, has no review, and the window is open |
| `ORDER_NOT_OWNED` | another customer's order — answered as 404 (`order_not_found`) so that existence is not disclosed |
| `ORDER_NOT_COMPLETED` | any other order state (paid, preparing, ready, picked up but not completed, rejected, cancelled) |
| `REVIEW_ALREADY_EXISTS` | the order has a review, also a withdrawn one |
| `REVIEW_WINDOW_EXPIRED` | more than `submission_window_days` after completion |
| `ORDER_NOT_ELIGIBLE` | the order has no usable location |

`config/reviews.php` — DEVELOPMENT DEFAULTS, PENDING BUSINESS APPROVAL (they are the approved Module 16 design):
30 days to review, 72 hours and 3 edits to change. Environment: `REVIEW_SUBMISSION_WINDOW_DAYS`, `REVIEW_EDIT_WINDOW_HOURS`,
`REVIEW_MAX_EDITS`.

## Validation and input safety

- `StrictInput::only()` refuses any field the endpoint does not know (422) — `customer_id`, `order_id`, `location_id`,
  `status`, `verified`, `published_at` and the like can never be mass-assigned.
- Rating: integer 1–5. Category ratings, tags and item feedback: only keys of the configuration, only items of the
  reviewed order.
- Text: plain text in any language (emoji, CJK, RTL), counted in Unicode code points (comment 500, title 120, response
  600). Markup is refused with 422 — it is not "cleaned" into something the customer did not write. Control characters
  are removed; format characters (ZWJ, variation selectors) are kept so that emoji survive.
- Clients render review text as text (React text nodes / Flutter `Text`); no client uses `innerHTML` for it.

## Screening — rules, not AI moderation

`ReviewContentScreen` sets flags: `UNSAFE_LINK`, `PERSONAL_INFORMATION` (phone number / e-mail pattern), `SPAM_PATTERN`
(a short phrase list), `REPEATED_CONTENT` (the same text by the same customer within 30 days). A flag **holds** the review
(`PENDING_MODERATION`) and opens an AUTOMATED case for a person to read; it never rejects a review, and a low rating is
never a flag. These are simple pattern matches and are described as such in the admin screen. Advanced (AI) moderation =
PENDING — none is configured, none is claimed.

A clean review is PUBLISHED immediately.

## Customer API (`auth:customer`)

| Method | Path | Notes |
|---|---|---|
| GET | `/customer/orders/{order}/review` | eligibility + my review + the form policy (scale, categories, tags, limits, windows) + the order's items |
| POST | `/customer/orders/{order}/review` | writes the review. The same content or the same `client_submission_id` again → 200 with the same review (`created: false`); different content → 409 `review_already_exists` |
| GET | `/customer/reviews` | my reviews, flat `page` / `per_page` |
| GET | `/customer/reviews/{review}` | one of mine (404 for anybody else's) |
| PATCH | `/customer/reviews/{review}` | needs `version`; inside the edit window and edit budget; re-screened; the previous content goes to `review_revisions` |
| DELETE | `/customer/reviews/{review}` | withdraw (soft): status REMOVED, rating recalculated, open cases closed as WITHDRAWN |
| POST | `/reviews/{review}/report` | report somebody else's PUBLISHED review: reason from `SPAM, HARASSMENT, PERSONAL_INFORMATION, OFFENSIVE_CONTENT, FAKE_REVIEW_CONCERN, OTHER`; once per review (409 `review_already_reported`); 20 per day (409 `report_limit_reached`); own review → 409 `cannot_report_own_review` |

The order endpoints (`GET /customer/orders`, `GET /customer/orders/{order}`) carry a `review` block:
`state` ELIGIBLE / REVIEWED / REMOVED / NOT_ELIGIBLE, `reason`, `window_ends_at`, and the review's id / rating / status /
`can_edit` — the clients show "Write Review" only when the state is ELIGIBLE and "View / Edit your review" when REVIEWED.

## Public API (no session)

| Method | Path | Notes |
|---|---|---|
| GET | `/restaurants/{slug}/reviews` | PUBLISHED reviews of the location: `page`, `per_page` (≤ 50), `sort` newest / oldest / highest / lowest, `rating` 1–5; with the `summary` |
| GET | `/restaurants/{slug}/reviews/summary` | `rating`: average (null without reviews), count, distribution per star, category averages |

A public review is: a display name ("First L."), the rating, category ratings, tags, title, comment, `verified_order`,
`edited`, the date, and the restaurant's PUBLISHED response (text, restaurant name, date). **Never**: the customer's
phone, e-mail or account id, the order number or id, the journey (origin, destination, route), payment details, the
pickup credential, private order notes, flags, reports or moderation data. `ReviewSecurityTest` asserts the exact key set.

Restaurant payloads (`/restaurants`, `/restaurants/{slug}`, favorites, journey results) carry `rating: {average, count}`.

## Rating summary (`RestaurantRatingService`)

- Location rating is primary. An organization figure is not exposed (no approved design shows one).
- `recalculate(location)` counts PUBLISHED reviews under `pg_advisory_xact_lock(hashtext('rating:'||id))` in the same
  transaction as the change that caused it: submit, edit, moderation decision, withdrawal, restore.
- Average = `sum / count`, shown to one decimal, rounded half up with integers only (`intdiv(sum*20 + count, count*2) / 10`).
  No reviews → `average: null`, `count: 0` — no division, and no client shows a number in its place ("New" / "No reviews yet").
- PostgreSQL is authoritative. No Redis cache is used for ratings (CF: optional, not needed at this size).
- Recovery: `php artisan reviews:recalculate [--location=<uuid>]` rebuilds the summaries from the reviews.

### Discovery

- `GET /restaurants`: `sort=rating` / `-rating` (average, then count, then name; unrated last), `filter[min_rating]`.
- `GET /journeys/{journey}/restaurants`: `sort=highest_rated`, `min_rating` (1–5). Ratings are read at request time, so
  a review published after the candidate set was cached counts on the next request.
- The order is deterministic. **No proprietary ranking formula is applied**: `ranking.confidence_adjustment` is false;
  a confidence adjustment (for example a Bayesian average) is a business decision that has not been approved.

## Restaurant API (`auth:restaurant`, permissions `restaurant.reviews.view / respond / report`)

| Method | Path | Notes |
|---|---|---|
| GET | `/restaurant/reviews?location=` | PUBLISHED reviews of a location in the member's ACTIVE membership, with the summary and `answered / unanswered` |
| GET | `/restaurant/reviews/summary?location=` | the summary alone |
| GET | `/restaurant/reviews/{review}` | 404 outside the membership |
| PUT | `/restaurant/reviews/{review}/response` | publish the one response, or edit it — an edit needs `expected_version` (without it: 409 `response_exists`; the same text again is a replay and changes nothing); a HIDDEN response cannot be edited |
| POST | `/restaurant/reviews/{review}/report` | same reasons as customers |

A restaurant never sees PENDING / HIDDEN / REJECTED / REMOVED reviews, never the customer's contact details or the order's
journey, and has no endpoint that changes or removes a customer's review. Roles: OWNER and MANAGER view / respond / report;
MENU_MANAGER views and reports; VIEWER views; ORDER_STAFF none (seeded in `RoleSeeder`).

## Admin API (`auth:admin`, market scope enforced by the server)

Permissions: `admin.reviews.view`, `admin.reviews.moderate`, `admin.reviews.restore`, `admin.reviews.reports.view`.
A grant for one market never opens another (`AuthorizesMarketScope`).

| Method | Path | Notes |
|---|---|---|
| GET | `/admin/reviews` | filters `status`, `reported`, `rating`, `restaurant`, `market`, `q`; `counts` per status and `reported` |
| GET | `/admin/reviews/{review}` | + cases with their reports, revisions, the audit history |
| POST | `/admin/reviews/{review}/publish` | from PENDING_MODERATION; reason optional |
| POST | `/admin/reviews/{review}/hide` | from PUBLISHED; reason required |
| POST | `/admin/reviews/{review}/reject` | from PENDING_MODERATION or HIDDEN; reason required |
| POST | `/admin/reviews/{review}/restore` | from HIDDEN or REJECTED; reason required; `admin.reviews.restore` |
| POST | `/admin/reviews/{review}/response/hide` · `/response/restore` | the restaurant's response; reason required |
| GET | `/admin/review-cases` | the queue (open by default), by source |
| POST | `/admin/review-cases/{case}/assign` · `/escalate` · `/resolve` | resolve = NO_VIOLATION, the review stays as it is |

Decisions carry `expected_version`: a decision on a review that changed in the meantime answers 409 `version_conflict`.
A decision closes the review's open cases with the matching resolution. **Negative ratings alone are not violations**:
the screen says so, a hide / reject / restore needs a written reason, and every decision is in the audit trail.

## Concurrency and idempotency

- Submit: the order row is locked; `UNIQUE(order_id)` is the backstop. Two simultaneous submits → one review; the other
  answers the same review (same content / token) or 409.
- Edit: `version` (optimistic) under the review's row lock.
- Moderation: row lock + `expected_version`; two administrators deciding at once → one decision, one 409.
- Response: one row per review (`UNIQUE(review_id)`), `expected_version` on an edit.
- Report: unique per reporter per review; the open case is created or its counter incremented under lock.
- Rating: advisory lock per location around the recalculation.

## Notifications (Module 33 — no second engine)

| Type | When | IN_APP | PUSH | Target |
|---|---|---|---|---|
| `review.response` | the restaurant published a response | mandatory | by preference | REVIEW (the order's review) |
| `review.published` | a held review was published by a moderator | mandatory | off | REVIEW |
| `review.moderated` | a review was hidden or rejected (neutral wording, never who reported) | mandatory | off | REVIEW |

The invitation to review is one line in the existing `order.completed` notification (template v2). There is **no review
reminder** — nothing is sent repeatedly. Restaurant-side "new review" notifications are NOT built: the notification
tables are customer notifications, and a partner notification channel does not exist yet (carry-forward).

No review incentive exists: no discount, credit or reward is granted for a review, positive or otherwise.

## Audit

`review.created`, `review.edited`, `review.removed`, `review.reported`, `review.response_added`, `review.response_edited`,
`review.published`, `review.hidden`, `review.rejected`, `review.restored`, `review.response_hidden`,
`review.response_restored`, `review.case_assigned`, `review.case_escalated`, `review.case_resolved` — actor, target
(the review), market, changes, reason. Review text is not copied into the audit trail (it is in `review_revisions`).

## Rate limits (`config/rate_limits.php`)

`review-read` 120 / min, `review-write` 10 / min (submit, edit, withdraw, respond), `review-report` 5 / min.

## Clients

| Client | Mode | What runs on the backend |
|---|---|---|
| Customer Web | `VITE_REVIEW_MODE=api` (needs the order mode) | review form, edit, withdraw, My Reviews (`/my-reviews`), order CTAs from the order's review block, restaurant page reviews (summary, distribution, list, sort, filter, report), real ratings on cards / list / map / favorites, rating sort and filter along a journey |
| Restaurant Dashboard | same build flag | Reviews: average, count, answered, distribution, the published reviews of the selected location, respond / edit response, report |
| Platform Admin | same build flag | Review Moderation: queues with counts, detail with reports / automated checks / audit history, publish / hide / reject / restore, response hide / restore, case assign / escalate / close |
| Android | `--dart-define=REVIEW_MODE=api` (needs ORDER_MODE=api) | the same customer flows; nothing is queued offline — a review is sent when submitted or not at all |

iOS remains DEFERRED.

## Not built (stated, not hidden)

- Review photos (not in the approved design; `config('reviews.images')` is false).
- Advanced / AI moderation.
- A confidence-adjusted ranking.
- Restaurant-side notifications about new reviews; e-mail / SMS for review events.
- "Helpful" votes.
- An organization-level rating.
- Demo reviews: no review is seeded — a restaurant shows "New" until a real completed order is reviewed.

## Tests

`tests/Feature/Review/` — eligibility (7), rating (7), security (8), moderation (5), edit and response (7), OpenAPI
contract (2), performance (1). `storage/logs/m34-performance.json` records the query counts of the list endpoints and the
plan of the public list (index scan on `(location_id, status, published_at)`).

# File upload security

Module 39 — 2026-10-14 — local only; production = NO

Sources: `backend/app/Services/Customer/CustomerAvatarService.php`, `backend/app/Services/Menu/MenuImageService.php`, `backend/app/Support/ImageSanitizer.php`, `backend/app/Http/Controllers/Api/MediaController.php`, `backend/app/Http/Controllers/Api/Customer/ProfileController.php:57`, `backend/app/Http/Controllers/Api/Restaurant/MenuController.php:195`, `backend/routes/api.php:282-292, 443-445`, `backend/app/Providers/AppServiceProvider.php:257-258`, `backend/config/{filesystems,customer,menu,rate_limits,support}.php`, `backend/app/Http/Middleware/ApiSecurityHeaders.php`, `backend/app/Services/Privacy/CustomerErasureService.php`, `backend/database/migrations/2026_10_17_100000_module37_support.php:310-331`, `backend/tests/Feature/Security/{DataProtectionTest,InjectionTest}.php`.

## 1. What can be uploaded today

| Upload | Route | Who | Throttle | Stored on | Served by |
|---|---|---|---|---|---|
| Customer profile photo | `POST /api/v1/customer/profile/avatar` (`routes/api.php:291`) | the signed-in customer, for their own account | `throttle:upload` — 10 / min per person | disk `CUSTOMER_AVATAR_DISK` (default `public`) under `avatars/{customer uuid}/{random uuid}.{ext}` | `GET /api/v1/media/{path}` |
| Menu item photo | `POST /api/v1/restaurant/menu/items/{item}/images` (`routes/api.php:443`) | restaurant staff with menu permission at the item's location | `throttle:upload` — 10 / min per person | disk `MENU_IMAGE_DISK` (default `public`) under `menu/{menu uuid}/{random uuid}.{ext}` | `GET /api/v1/media/{path}` |

There is **no other upload**. Restaurant location images (logo, cover, gallery) can only be edited (alt text, order)
or archived (`routes/api.php:414-415`); `RestaurantImage` stores metadata only and its rows come from seeders. Support
attachments are not implemented (section 6). The CSV exports of Modules 36 and 38 are downloads, not uploads.

The upload limiter is keyed by the principal type and id (`AppServiceProvider.php:258`); its value is a config default
`RATE_LIMIT_UPLOAD` (10, `config/rate_limits.php:33`), PENDING BUSINESS APPROVAL.

## 2. Checks before anything is stored

Both services apply the same steps, in this order (`CustomerAvatarService.php:26-49`, `MenuImageService.php:29-54`):

| Step | Check | Avatar | Menu image |
|---|---|---|---|
| 1 | Request validation: `File::image()` + maximum size (`ProfileController.php:57`, `MenuController.php:195`; the menu rule also checks dimensions). Laravel's `image` rule excludes SVG. | yes | yes |
| 2 | Upload completed (`isValid()`) | yes | yes |
| 3 | Size limit | 2 MiB (`CUSTOMER_AVATAR_MAX_BYTES`) | 2 MiB (`MENU_IMAGE_MAX_BYTES`) |
| 4 | MIME type **from the file's bytes** (`getMimeType()`), never from the name, against an allow-list | JPEG, PNG, WebP | JPEG, PNG, WebP |
| 5 | Real pixel dimensions (`getimagesize`) — refuses what is not a readable image | 120–2000 px each side | 200–4000 px each side |
| 6 | Images per item (menu only, under a row lock) | — | 6 (`MENU_MAX_IMAGES_PER_ITEM`) |

Size limits are config defaults; dimensions and the MIME allow-lists are fixed in `config/customer.php:52-58` and
`config/menu.php:45-52`. All values are PENDING BUSINESS APPROVAL.

## 3. Re-encoding (Module 39)

`App\Support\ImageSanitizer::reencode()` decodes the file with GD (`imagecreatefromjpeg` / `png` / `webp`) and encodes
it again (JPEG quality 90, PNG level 6, WebP quality 90). Only the re-encoded bytes are stored. Effects:

- EXIF, XMP and comment blocks are dropped: a phone photo's GPS position, camera model or owner name is not stored.
- Bytes appended after a valid image (a "polyglot", e.g. a PNG followed by PHP code) are dropped.
- A file GD cannot decode is refused with a 422 (`The file is not a readable image.`).
- Known limit (stated in the class): the EXIF orientation flag is not applied (no `exif` extension); a sideways
  photo stays sideways.

## 4. Storage and naming

- The stored name is a random UUID plus an extension chosen from the detected MIME type
  (`CustomerAvatarService.php:53`, `MenuImageService.php:63`). The client's file name is never used.
- The stored path never appears in an API answer; clients get a URL to `/api/v1/media/...`.
- A replaced or removed avatar is deleted from the disk (`CustomerAvatarService.php:63-65, 80-81`). A removed menu
  image is archived and the file is kept, because a past order may still show it (`MenuImageService.php:112-123`).
- Every change is audited (`customer.avatar_changed`, `menu_item_image.added / updated / removed`).

### Disks (`config/filesystems.php`)

| Disk | Root | Served over HTTP | Used for |
|---|---|---|---|
| `local` | `storage/app/private` | **No** — `serve => false` (Module 39; the former `storage/{path}` route is gone). `security:check` verifies it. | analytics exports, future support attachments |
| `public` | `storage/app/public` | Only through `MediaController`. The `public/storage` link of `config/filesystems.php:78-79` does **not** exist in this checkout (`backend/public/` has no `storage`). If someone runs `php artisan storage:link`, every file on this disk becomes reachable by its path through the web server, bypassing the media pattern. | avatars, menu images |
| `s3` | — | not configured | — |

## 5. Serving: `GET /api/v1/media/{path}`

`MediaController.php:18-31`:

- The path must match `^(menu|avatars)/[0-9a-f-]{36}/[0-9a-f-]{36}\.(jpg|png|webp)$`; anything else is 404. Traversal
  (`../`, encoded `%2e%2e`, `%00`, absolute paths) cannot match.
- The disk is chosen from the first segment (`avatars` → avatar disk, `menu` → menu disk).
- Answer headers: `Cache-Control: public, max-age=86400, immutable` and `X-Content-Type-Options: nosniff`. The global
  `ApiSecurityHeaders` middleware adds `X-Frame-Options: DENY` and `Referrer-Policy: no-referrer` and leaves the
  explicit cache header in place.
- Throttle: `throttle:media`, 1200 / min per client address (`routes/api.php:284`, `config/rate_limits.php:35`).
- **No authentication.** A file is reachable by anyone who has its URL. The URL contains two random UUIDs, so it
  cannot be guessed, and an avatar URL is returned only to its owner (`CustomerProfileResource.php:53`). Menu images
  are public by design. Treat an avatar URL as a capability: if it leaks, the photo is readable until replaced.

## 6. Support attachments: NOT implemented

`config/support.php:150-161` sets `'enabled' => false` as a **code constant** (not an environment switch). There is no
upload route, no download route and no scanner. The table `support_case_attachments` exists as a schema foundation
(`module37_support.php:310-331`) with `sha256`, a server-chosen `path`, `scan_status` (NOT_SCANNED / CLEAN /
QUARANTINED) and the uploader type. The case APIs report `attachments.enabled = false`, and no client offers an upload.

The config comment at `config/support.php` used to say "the upload endpoints answer 409"; it was corrected after this review — no upload endpoint for attachments exists.

### Requirements before attachments may be enabled (production blocker)

| # | Requirement | Today |
|---|---|---|
| A-1 | **Private storage**: the `local` disk (or a private bucket), never `public`; files only through an authorized download endpoint | disk configured `local`; no endpoint |
| A-2 | **Malware scanning** before a file is visible to anyone; quarantine on detection; `scan_status` enforced on download | none configured (`SUPPORT_ATTACHMENT_SCANNER=none`) — blocker |
| A-3 | **Type allow-list** by content (magic bytes), not by name: images and PDF only; images re-encoded with `ImageSanitizer`; PDFs sanitized or served as download only | list in config (JPEG, PNG, WebP, PDF); not enforced anywhere yet |
| A-4 | **Size and count limits** per file and per case, and an upload throttle | 5 MiB, 10 per case in config; not enforced |
| A-5 | **Download authorization**: same rules as the case (requester only for their own case; staff by scope; restricted classes by permission); `Content-Disposition: attachment`, `nosniff`, a server-chosen file name | not built |
| A-6 | Random storage names; display name stored separately and escaped | schema supports it |
| A-7 | Retention and erasure of attachments | `'retention' => 'PENDING POLICY'` |
| A-8 | Audit of upload, download and quarantine | not built |
| A-9 | Negative tests: polyglots, oversize, wrong type, traversal, another case's file | not built |

## 7. Tests

| Test | What it proves |
|---|---|
| `DataProtectionTest::test_uploaded_images_lose_their_metadata_and_anything_appended_to_them` (`:205-252`) | A JPEG with an EXIF segment carrying `GPSLatitude` and a PNG with `<?php …` appended are both re-encoded without the marker and stay readable; a PHP file claiming to be a PNG is refused; an avatar uploaded through the API is stored without `GPSLatitude` |
| `InjectionTest::test_media_paths_cannot_leave_the_media_folders` (`:94-102`) | `../../.env`, encoded traversal, `menu/../../../.env`, `avatars/../../storage/logs/laravel.log`, `%00.jpg`, a `.php` name, `/etc/passwd` all answer 404 / 400 and never contain `APP_KEY` |
| `HardeningConfigTest::test_a_deployment_that_sets_nothing_gets_the_safe_defaults` (`:57`) | the private `local` disk is not served over HTTP (`filesystems.disks.local.serve` false) |

The results of the Module 39 test run are in the module's completion evidence, not repeated here.

## 8. Open items

| # | Item | Status |
|---|---|---|
| U-1 | Support attachments: A-1 … A-9 | NOT IMPLEMENTED — malware scanning is a production blocker for enabling them |
| U-2 | Never run `storage:link` in production, or serve `public` only through the media route | DEPLOYMENT |
| U-3 | Media served with a long public cache: put a CDN or the web server in front; keep the strict pattern | DEPLOYMENT |
| U-4 | Erasure deletes the avatar from the `public` disk by name (`CustomerErasureService.php:120`), not from the configured `CUSTOMER_AVATAR_DISK` — a different avatar disk would leave the file | OPEN (low) |
| U-5 | EXIF orientation not applied | ACCEPTED (functional only) |
| U-6 | Decoding cost: a 4000 × 4000 image is decoded in memory by GD; the size and dimension checks bound it; no separate memory limit | ACCEPTED, review with load testing |

# API Contract — Reflexometr

**Owner:** ServerTeam. Golden copy — ClientTeam reads this file directly; never copy it into `client/`.
Any change lands here first (version bump + changelog entry below), then the ProductOwner briefs
ClientTeam on the diff (PRODUCT_OWNER_PROCESS.md § Contract Change Workflow).

**Version:** v1.8 (Sprint 13) — CR-TEST-28 (Circle Collision: resolved closing-speed field,
timeout-as-null-response, abs-value summary aggregation scoped to this test family) — see Changelog.

**Note for ClientTeam:** `client/js/mock-api.js` / `SPRINT1_REPORT.md` (ClientTeam's) list several
assumed field names and behaviors made against the still-empty v0.1 contract. This document is now
the source of truth — see "Reconciliation notes for ClientTeam" at the end for a direct diff against
those assumptions.

---

## Conventions

- Base path: none assumed — routes below are relative to wherever `server/public/index.php` is
  deployed (e.g. WebHostMost document root or subdirectory).
- All requests/responses are `application/json` unless noted (admin import optionally accepts
  `multipart/form-data` for file upload).
- **Success envelope:** `{ "data": <payload> }`
- **Error envelope:** `{ "error": { "code": "SOME_CODE", "details": {...} | omitted } }` — **never**
  a hardcoded English string (CR-UI-02). `details`, when present, is structural data only (field
  names, numeric ids, enumerated reason codes) — the client maps `code`/`details.reason` to
  localized copy itself.
- All error codes are listed in `server/src/Http/ErrorCode.php` (kept in sync with this doc).

## Auth / session mechanism

Bearer-token session auth (not tied to a specific CR this sprint — no AUTH-area CR was scheduled,
but every task from SI-1.1 onward assumes "the logged-in user" and D7's single hardcoded admin, so
this is required groundwork; see SPRINT1_REPORT.md "Deviations/assumptions"). Chosen over
cookie+CSRF to keep this a plain stateless-header JSON API.

- Client sends `Authorization: Bearer <token>` on every authenticated request.
- Token is opaque (32 random bytes, base64url), returned by register/login, stored server-side in a
  `sessions` table (never a JWT — revocable by deleting the row).
- **Session lifetime:** 14 days (1,209,600 seconds), sliding — renewed on every authenticated
  request. Configured via `SESSION_LIFETIME_SECONDS` (server `.env`).
- Logout deletes the session row server-side (best-effort; client should also discard its copy of
  the token).
- A single hardcoded admin account (D7): the user whose email matches the server's `ADMIN_EMAIL`
  config is flagged `is_admin` at registration time (or via `bin/seed.php`). No role system.

### `POST /auth/register`
Body: `{ "email": string, "password": string (>=8 chars) }`
201: `{ "user": UserProfile, "token": string }`
Errors: `VALIDATION_ERROR` (400), `AUTH_EMAIL_TAKEN` (409)

### `POST /auth/login`
Body: `{ "email": string, "password": string }`
200: `{ "user": UserProfile, "token": string }`
Errors: `AUTH_INVALID_CREDENTIALS` (401)

### `POST /auth/logout`
Auth: bearer token (optional — no-ops if absent/invalid).
200: `{ "logged_out": true }`

### `GET /auth/me`
Auth: required.
200: `UserProfile`
Errors: `AUTH_REQUIRED` (401, no/malformed token), `AUTH_SESSION_EXPIRED` (401, token unknown/expired)

**`UserProfile`:** `{ "id": int, "email": string, "is_admin": bool, "dominant_hand": "left"|"right"|"none-recorded", "preferred_locale": string|null, "real_name": string|null, "display_name": string|null }`
**CR-AUTH-03 (v1.5):** `real_name`/`display_name` are new, optional, freeform (<=100 chars),
nullable profile fields — plain user-entered text, **not** login identifiers (email remains the
sole unique account key; no uniqueness constraint on either). Neither field appears in any
anonymized/peer-comparison payload (`GET /results/{id}/comparison` — D12 still applies in full).

### `PATCH /profile`
Auth: required. Self-service only — no id param; always the authenticated user (D3 privacy rule).
Body (at least one of): `{ "dominant_hand"?: "left"|"right"|"none-recorded", "preferred_locale"?: one of the 6 supported codes (`en`, `es`, `de`, `fr`, `zh-Hans`, `ru` — REQUIREMENTS/SHARED_CONSTANTS.md § Supported locales), "real_name"?: string|null (<=100 chars), "display_name"?: string|null (<=100 chars) }`
200: `UserProfile`
Errors: `VALIDATION_ERROR` (400, bad `dominant_hand`, `real_name`/`display_name` over 100 chars, or no field given), `UNSUPPORTED_LOCALE` (400)

**CR-AUTH-03 (v1.5):** `real_name`/`display_name` are plain freeform strings (send `null` to clear
a previously-set value); over-length input (>100 chars) is rejected with `VALIDATION_ERROR`
(`details.fields: ["real_name"]` or `["display_name"]`). No format/uniqueness validation beyond
the length cap — these are display-only fields, never used for login or lookup.

**CR-INFRA-01 (v1.3):** `preferred_locale` is now additionally validated against **this
deployment's enabled locale set** (`GET /config/locales`' `enabled` list), not just the full
6-code superset above — a single-language deployment (`ENABLED_LOCALES` narrowed in server
`.env`) rejects `preferred_locale` values outside its own offered list with the same
`UNSUPPORTED_LOCALE` (400), even if the code is one of the 6 supported codes in general.
**CR-UI-13:** the deployment's default/fallback locale (used when no `DEFAULT_LOCALE` override
narrows it) is `ru`, not `en` — see the `GET /config/locales` section below.

`dominant_hand` (CR-TEST-04) and `preferred_locale` (CR-UI-02, default unset/`null`) both live here.
Editing either is a metadata-only change — see CR-TEST-04: results already submitted keep the
`dominant_hand` value **captured at their own submission time**, never re-derived from the current
profile.

---

## Deployment configuration (CR-INFRA-01)

Read-only, no auth required — deployment metadata, not user data.

### `GET /config/locales`
Returns this deployment's enabled locale list and default locale (per-deployment locale
scoping — `ENABLED_LOCALES`/`DEFAULT_LOCALE`, server `.env`). `client/js/i18n.js` sources its
locale selector/fallback from this instead of a hardcoded array, so a single-language deployment
only offers the locale(s) it's configured for.

200: `{ "enabled": [...one or more of the 6 supported codes...], "default": string }`
`enabled` is never empty — an unset/empty `ENABLED_LOCALES` or one that narrows to nothing valid
falls back to the full 6-code superset. `default` is always a member of `enabled` (CR-INFRA-05):
the deployment's configured `DEFAULT_LOCALE` if it's a member of `enabled`, otherwise the
compiled-in default `ru` (CR-UI-13/D18) if *it's* a member of `enabled`, otherwise `enabled[0]` —
whether `DEFAULT_LOCALE` is unset or explicitly set to an invalid value, an out-of-set default
never crashes the request or is surfaced as an error, and never resolves outside `enabled`.

---

## Health check (CR-INFRA-02)

Read-only, no auth required — ops/deployment metadata, not user data. This is the post-deploy
smoke-check target `.github/workflows/deploy.yml` polls after every deploy; it fails the
workflow (non-2xx) rather than silently continuing if the deployed API can't reach its database.

### `GET /health`
Exercises a real DB round-trip (`SELECT 1`), not just "PHP responded" — a missing/misconfigured
`.env` or unreachable MySQL on the target surfaces here.

200: `{ "status": "ok" }`
503: `{ "error": { "code": "INTERNAL_ERROR" } }` (DB connection/query failed)

---

## Public r-test browsing (CR-TEST-01, CR-TEST-05, CR-TEST-25)

Read-only, no auth required. The test-taking client selects/displays an r-test's **current
version** from this data — never hardcoded client-side. Never exposes a version's raw imported
description (D11) — only metadata (version number, active flag).

### `GET /r-tests?tag_id={id}`
200: `[ { "id", "slug", "name", "description", "tags": [{ "id", "name" }, ...], "current_version": int|null, "current_version_id": int|null }, ... ]`
`tag_id` query param is optional (filters the list to r-tests carrying that one tag, among
possibly several). `category_id` is accepted as a **deprecated alias** for `tag_id` (same
filtering semantics against the renamed tag model) — prefer `tag_id` in new code.

**CR-TEST-25 (v1.6):** `category_id`/`category_name` (singular FK) is **replaced** by `tags`
(array, possibly empty — an r-test can carry any number of tags, including zero).

### `GET /r-tests/{slug}`
200: same shape as above plus `"packages": [{ "id", "name" }, ...]`
Errors: `RTEST_NOT_FOUND` (404)

### `GET /tags`
200: `[ { "id", "name" }, ... ]`
**CR-TEST-25 (v1.6):** renamed from `GET /categories` (same underlying renamed table, clean
rename — no deprecated alias kept for this specific endpoint since no external consumer existed
yet; contrast with `GET /r-tests`'s `category_id` alias above, which is kept because real client
traffic risk exists there).

### `GET /packages`
200: `[ { "id", "name", "description", "r_tests": [{ "id", "slug", "name" }, ...] }, ... ]`

---

## Admin r-test import/export (CR-TEST-01)

Auth: admin required on every endpoint in this section (403 `ADMIN_REQUIRED` for any non-admin
session; 401 `AUTH_REQUIRED`/`AUTH_SESSION_EXPIRED` if not authenticated at all).

The imported description is opaque textual/JSON data (D11) — this API stores/versions it; the
admin never hand-edits its content, only `r_tests` metadata (name/description/category). See
"Description JSON format" below for what ServerTeam expects inside it.

### `GET /admin/r-tests`
200: `[ { "id", "slug", "name", "description", "tags": [{ "id", "name" }, ...], "versions": [{ "id", "version", "is_active", "created_at" }, ...] }, ... ]`
(Raw description content is *not* included here — use the export endpoint.)
**CR-TEST-25 (v1.6):** `category_id`/`category_name` replaced by `tags` (array, possibly empty) —
same shape change as the public `GET /r-tests` above.

### `POST /admin/r-tests`
Creates a new r-test + its v1 version in one call.
Body (JSON or `multipart/form-data`): `{ "slug": string (lowercase, hyphen-separated), "name": string, "description"?: string (human-readable r_tests metadata text), "tag_ids"?: int[], "content": string (the version's raw JSON description) }`. Multipart alternative: send the description file as field `description_file` instead of inline `content`.
201: `{ "r_test": { "id", "slug", "name" }, "version": 1 }`
Errors: `VALIDATION_ERROR` (400, malformed slug/description JSON/`tag_ids`), `RTEST_SLUG_TAKEN` (409)
**CR-TEST-25 (v1.6):** `category_id?: int` replaced by `tag_ids?: int[]` — assigns the full initial
tag set (omitted/empty = no tags). Unknown tag ids are silently dropped, never a hard failure
(matches the old `category_id`'s lack of existence validation beyond the FK itself).

### `POST /admin/r-tests/{slug}/versions`
Imports a new version of an existing r-test. It automatically becomes the "current" (active)
version; prior versions' description/version-number/results/created_at are never modified — only
their `is_active` pointer flips off (CR-TEST-01 acceptance 3: importing a version never alters
prior versions or their results).
Body: `{ "content": string }` or multipart `description_file`.
201: `{ "version": int }`
Errors: `RTEST_NOT_FOUND` (404), `VALIDATION_ERROR` (400)

### `GET /admin/r-tests/{slug}/versions/{version}/export`
200: `{ "slug", "version", "description": string }` (the raw imported content, verbatim)
Errors: `RTEST_NOT_FOUND` (404), `RTEST_VERSION_NOT_FOUND` (404)

### `PATCH /admin/r-tests/{slug}`
Metadata-only edit (name/description/tags) — never touches version content.
Body: `{ "name"?, "description"?, "tag_ids"?: int[] }`
200: `{ "updated": true }`
**CR-TEST-25 (v1.6):** `category_id?: int|null` replaced by `tag_ids?: int[]`. `tag_ids`, when
present, **replaces the full tag set** (not additive/merging) — an explicit empty array `[]`
clears all tags without deleting the r-test itself; **omitting** the key entirely leaves the
r-test's existing tags untouched (distinguish "clear" from "don't touch" the same way `category_id:
null` used to mean "clear" while an omitted `category_id` meant "don't touch").

## Admin tags & packages (CR-TEST-05, CR-TEST-25)

Auth: admin required (same 403/401 rules as above).

**CR-TEST-25 (v1.6):** `/admin/categories` renamed to `/admin/tags` — same underlying renamed
table, clean rename (no deprecated alias kept server-side; no external consumer existed yet).
`CATEGORY_NOT_FOUND`/`CATEGORY_NAME_TAKEN` error codes renamed to `TAG_NOT_FOUND`/`TAG_NAME_TAKEN`.

- `POST /admin/tags` `{ "name": string }` → 201 `{ "id", "name" }` (409 `TAG_NAME_TAKEN` on duplicate)
- `PATCH /admin/tags/{id}` `{ "name": string }` → 200 (404 `TAG_NOT_FOUND`)
- `DELETE /admin/tags/{id}` → 200 `{ "deleted": true }` (cascades: removes this tag's links from
  every r-test that carried it, but never deletes the r-tests themselves — same "removing the
  taxonomy entry never removes the content it classified" semantics the old category FK's
  `ON DELETE SET NULL` had)
- `POST /admin/packages` `{ "name": string, "description"?: string }` → 201
- `PATCH /admin/packages/{id}` `{ "name"?, "description"? }` → 200 (404 `PACKAGE_NOT_FOUND`)
- `DELETE /admin/packages/{id}` → 200
- `POST /admin/packages/{id}/r-tests` `{ "r_test_id": int }` → 201 `{ "added": true }` (many-to-many; a package can span categories, an r-test can belong to multiple packages)
- `DELETE /admin/packages/{id}/r-tests/{rTestId}` → 200 `{ "removed": true }`

---

## Admin results approval queue (CR-AUTH-02, v1.5, implements D19)

Auth: admin required on every endpoint in this section (403 `ADMIN_REQUIRED` for any non-admin
session; 401 `AUTH_REQUIRED`/`AUTH_SESSION_EXPIRED` if not authenticated at all). Mirrors
`/admin/r-tests`'s existing pattern (`server/src/Routes.php`, `AdminRTestController`).

### `GET /admin/results?status=pending`
Paginated list of results in the given `approval_status` (`pending` default, or `approved`/
`rejected`). **Never** exposes the submitting user's identity beyond existing admin-access norms
(no email/user_id in the payload).

Query params: `status` (optional, one of `pending`|`approved`|`rejected`, default `pending`),
`limit` (optional positive int, default 50), `offset` (optional non-negative int, default 0).

200: `{ "status": "pending", "total": int, "entries": [ { "id", "r_test_id", "r_test_slug", "r_test_version_id", "r_test_version", "primary_metric_ms", "submitted_at" }, ... ] }`
Errors: `VALIDATION_ERROR` (400, malformed `status`/`limit`/`offset`)

### `PATCH /admin/results/{id}`
Transitions a result's `approval_status`. Rejecting **never deletes the row** (D19) — a rejected
result simply never enters the approved comparison pool; it remains readable by its owning user
via their own history exactly as before.

Body: `{ "approval_status": "approved"|"rejected" }`
200: `{ "id": int, "approval_status": "approved"|"rejected" }`
Errors: `VALIDATION_ERROR` (400, missing/invalid `approval_status`), `NOT_FOUND` (404, unknown result id)

---

## Run-token issuance + submission (CR-TEST-02, CR-TEST-06, D9, D11)

Auth: required (the logged-in user).

### `POST /r-tests/{slug}/runs`
Starts one run against the r-test's current (or explicitly requested) version. Issues a
single-use, short-lived **run token** and a **compiled trial schedule** — concrete, already-
resolved values for this run only. **Never the version's raw description or its general
parameter ranges** (D11) — e.g. the resolved per-trial delay is sent, never the configured
min/max range it was drawn from.

Body: `{ "version"?: int (defaults to the r-test's current active version), "mode"?: "single" (default) | "count:{N}" | "until-quit" (CR-TEST-06), "series_id"?: string (client-generated, opaque, only for the client's own between-runs grouping/tally — the server does not need to know in advance how many runs an "until-quit" series will contain) }`

201:
```json
{
  "token": "string",
  "expires_at_ms": 1234567890123,
  "r_test_id": 1,
  "r_test_version_id": 7,
  "version": 1,
  "schedule": {
    "trial_count": 10,
    "buffer_trials": 6,
    "response_channels": ["primary"],
    "timeout_ms": null,
    "trials": [ { "index": 0, "delay_ms": 1720 }, ... ]
  }
}
```
Errors: `RTEST_NOT_FOUND` (404), `RTEST_VERSION_NOT_FOUND` (404), `SERIES_MODE_INVALID` (400)

**CR-TEST-23/24 (v1.6):** a coincidence-anticipation test's `schedule` gains these fields — all new,
optional, additive; every existing test type's schedule shape is completely unchanged when its
description doesn't set them:
- `"allow_early_response": true` (schedule-level) — this run's submission validation accepts a
  response any time after motion starts, including before the resolved reference instant
  (`stimulus_at`); see submit validation below.
- **Circle Collision Simple (CR-TEST-23):** `"circle_radius_px": 40` (schedule-level, constant for
  this variant) and each `trials[]` entry gains `"motion_duration_ms": 3210` (resolved per-trial,
  same over-provisioned-buffer pattern as `delay_ms` — a fresh random draw per trial, 1-5s range).
- **Circle Collision Complex (CR-TEST-24):** extends Simple — `circle_radius_px` is **not**
  schedule-level here; instead each `trials[]` entry carries `"circle_radius_px": { "a": 36, "b": 44 }`
  (independently resolved per circle per trial, ±20% of the baseline). Each `trials[]` entry also
  carries `"motion_speed_profile": { "start_speed_px_per_s": 320, "mid_speed_px_per_s": 480, "end_speed_px_per_s": 250, "duration_ms": 2450 }`
  instead of `motion_duration_ms` — three resolved waypoint speeds the client linearly interpolates
  between (start→mid over the first half of `travel_distance_px`, mid→end over the second half);
  `duration_ms` is the server-verified total elapsed time this profile implies, always in the 1-5s
  range (the server rejects-and-rerolls internally rather than ever compiling/shipping an
  out-of-bound profile — see the Description JSON format section below).

**Reading `schedule`:** `response_channels` names the input channel(s) this trial needs a response
from (`["primary"]` for a single-response test like `simple-reaction`; `["left","right"]` for
`two-hand-reaction`). `trials` has **`trial_count` + `buffer_trials` entries** — deliberately more
than `trial_count`. A false start (input before the stimulus — CR-TEST-03 acceptance 3) doesn't
count as a valid trial: discard that attempt and re-run the slot using the **next** unused
`trials[]` entry's `delay_ms` (never invent a delay client-side — that would defeat D11). Once
`trial_count` valid trials are collected, stop and submit exactly that many, renumbered
`0..trial_count-1` in the submitted log; unused buffer entries are simply discarded. `timeout_ms`,
when non-null, is the per-response deadline (ms after the stimulus) — a response can be omitted
(`null`) for a channel once its timeout elapses (used by `two-hand-reaction`; see submit shape
below). Each `delay_ms` is the gap before that trial's stimulus, relative to the previous trial's
stimulus for the client's own run-relative clock (`performance.now()`-based, not wall-clock/epoch).

Each run within a series (CR-TEST-06) is issued its own independent token via this same endpoint —
no different semantics from a `single` run. No partial-run submission: quitting mid-run simply
never calls submit for that run.

### `POST /r-tests/runs/{token}/submit`
Body:
```json
{
  "trials": [
    { "index": 0, "stimulus_at": 1720, "responses": { "primary": 1970 } }
  ],
  "client_started_at_ms"?: 1234567890000,
  "dominant_hand"?: "left" | "right" | "none-recorded"
}
```
- `trials`: exactly `trial_count` entries (per the schedule from run-start), `index` 0-based
  sequential, `stimulus_at`/response values in the same client-relative time unit the client used
  throughout the run (`performance.now()`-style ms, monotonic — **not** wall-clock/epoch).
  `responses` has exactly the schedule's `response_channels` as keys; a value is either a number
  (reaction timestamp) or `null` (only allowed if the schedule's `timeout_ms` is non-null, meaning
  that channel timed out on this trial).
- `dominant_hand` (two-hand tests only, CR-TEST-04): the value to record **on this result**,
  captured now — a later profile edit never retroactively changes it. Omit to fall back to
  whatever value the profile carries at submission time.

201:
```json
{ "result_id": 42, "r_test_id": 1, "r_test_version_id": 7, "primary_metric_ms": 231.4, "summary": { "overall": {..., "sd_ms": 12.3, "cv": 0.053}, "channels": {"primary": {..., "sd_ms": 12.3, "cv": 0.053}}, "dominant_minus_nondominant_ms"?: -18.2 } }
```
**CR-STATS-08 (v1.5):** `summary.overall`/each `summary.channels.{channel}` entry gains `sd_ms`
(sample standard deviation of that scope's valid reaction times, n-1 denominator) and `cv`
(coefficient of variation, `sd_ms / mean_ms`) — both `null` if fewer than 2 valid readings exist
for that scope (no fabricated `0.0`), and `cv` is additionally `null` if `mean_ms` is exactly `0`.
These are also persisted on the result row (`sd_ms`/`cv` columns) and surfaced again, with a peer
aggregate, by `GET /results/{id}/comparison` below.

**CR-TEST-23/24 (v1.6):** for a schedule with `allow_early_response: true`, a per-trial "reaction
time" can be **negative** (the response happened before `stimulus_at` — a genuine early
anticipation, not an error) — `primary_metric_ms`, `summary.overall.mean_ms`/`median_ms`/`min_ms`/
`max_ms`, and each `summary.channels.{channel}` entry are all genuinely signed in this case, never
clamped to a floor of `0`. A mixed set of early (negative) and late (positive) responses averages
normally (e.g. a -200ms and a +300ms trial mean to +50ms, not `(200+300)/2`). **This "averages
normally" statement is reversed for Circle Collision specifically by CR-TEST-28 (v1.8) below — every
other test type is unaffected and keeps this exact behavior.**

**CR-TEST-28 (v1.8) — Circle Collision abs-value summary aggregation, scoped to this test family
only:** when the compiled schedule sets `abs_value_aggregation: true` (Circle Collision Simple/
Complex only — see the Description JSON format section below; no other test type's schedule ever
sets this key), `primary_metric_ms`, `summary.overall.mean_ms`/`median_ms`/`sd_ms`/`cv`, and each
`summary.channels.{channel}.mean_ms`/`median_ms`/`sd_ms`/`cv` are computed from the **absolute
value** of each trial's signed reaction-time-equivalent (which for Circle Collision represents a
signed px distance — see below), so a mixed set of early/late trials reflects accuracy *magnitude*
and does not net-cancel toward zero the way every other test type's signed average still does. The
`min`/`max` fields are the one exception: for an `abs_value_aggregation` schedule they are renamed
(unsuffixed — **no** `_ms`, since the value is a px distance, not a time, for this family) to `min`/
`max` and always reflect the **genuinely signed** extremes (most-early/most-late) regardless of this
flag, so a user can still see their early/late split alongside the abs-value accuracy figure. Every
other test type keeps `min_ms`/`max_ms` (unchanged key names, genuinely signed, exactly as v1.6
documented). `dominant_minus_nondominant_ms` (two-hand tests) is unaffected in practice — Circle
Collision is currently single-channel — but would use each channel's abs-value mean if this flag
were ever combined with a multi-channel schedule in the future.

**CR-TEST-28 (v1.8) — resolved closing-speed field:** the compiled schedule's `trials[]` entries for
either Circle Collision variant gain `closing_speed_px_per_ms` (float > 0, server-resolved per D11)
— see the Description JSON format section below for exactly how it's derived. This lets the client
(or the server itself) compute a **signed distance-at-click in px**:
`distance_px = closing_speed_px_per_ms * (response_at - stimulus_at)` — **positive** when the click
happens before the circles' centers meet (still apart, closing), **negative** when it happens after
they've already passed (apart again, past the meeting point), mirroring the existing signed-`ms`
convention (D24: `stimulus_at` is the predicted collision instant) in px terms instead of ms terms.
This is what feeds `abs_value_aggregation` above.

**CR-TEST-28 (v1.8) — timeout-as-null-response:** Circle Collision's full-stage-travel timeout (the
circles reach the far stage edge with no click) submits `responses.primary: null` for that trial —
**no new contract shape**, this was already legal per the existing "value is either a number or
`null`, only allowed if the schedule's `timeout_ms` is non-null" clause (both Circle Collision seeds
already set `timeout_ms: 8000`). The only change is that the client now reaches this path
intentionally as a normal outcome, not a rare edge case; a `null` response is excluded from
`valid_count`/the mean the same way a missing response already is for every other test type.

**CR-AUTH-02 (v1.5):** every newly-created result starts `approval_status = 'pending'` (new
internal column, not returned by this endpoint) — see `GET /results/{id}/comparison` and the new
`/admin/results` endpoints below for what this gates.

**Validation (D9 — injection prevention only, no statistical/outlier filtering):**
1. Token must exist and belong to the requesting session's user — otherwise `RUN_TOKEN_INVALID`
   (400). Same code for "doesn't exist" and "belongs to someone else" (never leaks which).
2. Token must not be expired (`now > expires_at`) — `RUN_TOKEN_EXPIRED` (410).
3. Token must not already be used — `RUN_TOKEN_ALREADY_USED` (409). Marked used **transactionally**
   on first accepted submission (DB row update guarded by `used = 0`, checked via affected-row
   count) — a concurrent replay attempt loses the race and also gets `RUN_TOKEN_ALREADY_USED`.
4. Structural trial-log checks — all surface as `TRIAL_LOG_INVALID` (400) with
   `details.reason` (and often `details.index`) set to one of: `TRIAL_COUNT_MISMATCH`,
   `MALFORMED_TRIAL`, `INDEX_OUT_OF_ORDER`, `NON_MONOTONIC_TIMESTAMPS`, `CHANNEL_MISMATCH`,
   `MISSING_RESPONSE`, `MALFORMED_RESPONSE`, `REACTION_BEFORE_STIMULUS`, `RESPONSE_AFTER_TIMEOUT`,
   `WALLCLOCK_TOO_FAST`.
   - Every response must be at or after its own trial's `stimulus_at` (never before) — **unless**
     the schedule's `allow_early_response` is `true` (CR-TEST-23/24, v1.6), in which case a
     response before `stimulus_at` is valid data (an early anticipation, not a false start) and
     `REACTION_BEFORE_STIMULUS` is never raised for that run. This is schedule-level generic
     infrastructure, not special-cased to a specific test's slug. Every other check in this list
     (timeout, malformed shape, non-monotonic timestamps, wall-clock floor) is unaffected by this
     flag.
   - Consecutive trials' `stimulus_at` gap must be at least the schedule's smallest resolved
     `delay_ms` (minus a small timer-jitter tolerance) — catches "instant" fabricated logs.
   - Server-side wall-clock (its own `issued_at → received_at`, never trusting client timestamps
     for this check) must be at least `trial_count × (schedule's minimum delay + ~50ms human-
     reaction floor)` — rejects instantaneous bulk submissions regardless of what the trial log's
     own numbers claim.
5. No statistical/outlier filtering — a structurally valid submission is stored exactly as
   received (D9's explicit scope boundary).

---

## Stats (CR-STATS-01, CR-STATS-02, D12)

Auth: required. Every query below is scoped to one exact `(r_test_id, r_test_version_id)` pair —
**never** `r_test_id` alone, and no response ever mixes two different `r_test_version_id`s under
one aggregate figure (CR-STATS-01).

### `GET /r-tests/{slug}/versions/{version}/history`
The requesting user's own past results for this exact r-test + version (trend view).

Query params (both optional): `limit` (int >= 1), `offset` (int >= 0, default 0). **Default
behavior (no `limit` given): returns the caller's complete history for this scope — no cap of
any kind.** This is a safe default because the query is already tightly scoped to one
authenticated user's own data for one exact r-test/version pair, never an admin-wide or
cross-user query. Pass `limit` (optionally with `offset`) only if the caller wants to page through
results instead of receiving them all at once (e.g. `?limit=50&offset=50` for the second page of
50, ordered newest-first — same order as the unpaged response).

200: `{ "r_test_id", "r_test_version_id", "total": int, "entries": [ { "result_id", "created_at", "primary_metric_ms", "summary", "excluded": bool }, ... ] }`
`total` is the full count of matching results for this scope, regardless of `limit`/`offset` —
use it to know when paging is complete (`offset + count(entries) >= total`).
An empty `entries` array (not an error) if the user has no prior results for this exact version.
Errors: `RTEST_NOT_FOUND` (404), `RTEST_VERSION_NOT_FOUND` (404), `VALIDATION_ERROR` (400 — `limit`
present but not a positive integer, or `offset` present but not a non-negative integer;
`details.field` names which one)

**CR-STATS-07 (v1.7):** each entry gains `excluded` (bool) — whether the user has toggled this
result out of their own stats view (`PATCH /results/{id}/exclude` below). **Never filtered
server-side** — an excluded entry stays present in `entries` (with `excluded: true`) so the client
can render it struck-through with an un-exclude option, not have it silently vanish.

**Prior to v1.2:** this endpoint silently capped `entries` at 50 with no way to request more and
no indication in the response that truncation had occurred. That cap is gone — do not assume 50
is still a ceiling.

### `GET /results/{id}/comparison`
Anonymized aggregate comparison for one specific completed run (call right after submit, using its
`result_id`). **Never** another user's identity, handle, or raw value (D12) — only this user's own
value plus an aggregate percentile/rank/count.
200: `{ "r_test_id", "r_test_version_id", "your_value_ms", "your_sd_ms": float|null, "your_cv": float|null, "percentile": float|null, "rank": int, "total_participants": int, "peer_sd_ms_median": float|null }`
`percentile` = share of *other* participants (self excluded from the denominator) this result is
faster than (lower ms = faster); `null` if there are no other participants yet in this exact
version's distribution. `rank` = 1-based position (1 = fastest) across the full distribution
(self included).
Errors: `NOT_FOUND` (404 — unknown result id, **or** a result belonging to another user; same code
for both, an IDOR guard per D3's privacy rule)

**CR-AUTH-02 (v1.5, implements D19):** the comparison **pool** underlying `percentile`/`rank`/
`total_participants` is restricted to `approval_status = 'approved'` results only — a newly
submitted (`pending`) or admin-`rejected` result never affects *other* users' figures until an
admin approves it. This user's own `your_value_ms` (and `your_sd_ms`/`your_cv`, below) is read
directly off their own result and is **completely unaffected** by their own result's approval
status — they always see their own value immediately. If the caller's own result happens to
already be `approved`, it is still excluded from its own "peers" denominator exactly as before
(self-exclusion semantics are unchanged).

**CR-STATS-08 (v1.5):** adds `your_sd_ms`/`your_cv` (this result's own persisted values, `null` if
fewer than 2 valid trial readings were submitted) and `peer_sd_ms_median` — the median `sd_ms`
across the same approved peer pool described above (self excluded), a real server-computed
aggregate. `null` if no approved peer has a non-null `sd_ms` yet. Field name chosen over a
percentile-style figure for simplicity; may be extended later if a percentile-of-variability view
is wanted.

### `PATCH /results/{id}/exclude` (CR-STATS-07, v1.7)
Auth: required. Toggles whether one of the caller's own results is excluded from their own
history/stats view — a purely per-owner display concern (e.g. hiding a known-bad run), completely
independent of `approval_status`/D19's admin-driven peer-comparison pool: `GET
/results/{id}/comparison` is **unaffected** by this flag, and toggling it never changes any other
user's percentile/rank/aggregate figures.

Body: `{ "excluded": true|false }`
200: `{ "result_id": int, "excluded": bool }`
Errors: `VALIDATION_ERROR` (400, missing/non-boolean `excluded`), `NOT_FOUND` (404 — unknown result
id, **or** a result belonging to another user; same code for both, identical IDOR guard pattern to
`GET /results/{id}/comparison` above, per D3)

---

## Description JSON format (imported via CR-TEST-01, compiled at run-start per D11)

This is what ServerTeam expects inside an imported version's content (the `content` /
`description_file` payload on the admin import endpoints) — generic across every r-test type, so a
newly imported r-test needs **no new server code**:

```json
{
  "trial_count": 10,
  "inter_stimulus_delay_ms": { "min": 1000, "max": 3000 },
  "response_channels": ["primary"],
  "timeout_ms": null,
  "false_start_buffer": 6
}
```

- `trial_count` (int > 0, required): number of valid trials the compiled schedule guarantees.
- `inter_stimulus_delay_ms.min`/`.max` (int, required): the range the server randomizes each
  trial's delay from — **never sent to the client**; only the resolved per-trial values are.
- `response_channels` (array of >=1 non-empty strings, required): `["primary"]` for a
  single-response test, `["left","right"]` for a two-hand test, extensible for future r-test
  types with more inputs.
- `timeout_ms` (int > 0, or `null`, required key): per-response deadline; `null` means every
  channel must respond (no timeout path).
- `false_start_buffer` (int >= 0, optional, defaults to 6): how many extra resolved delays the
  compiled schedule over-provisions for false-start retries (see run-start above).

**CR-TEST-23/24 (v1.6) — coincidence-anticipation tests (Circle Collision):** three more optional
fields, generic infrastructure (not special-cased to one test's slug) so any future
coincidence-timing import can use them too:
- `allow_early_response` (bool, optional): `true` means this schedule's submissions skip
  `REACTION_BEFORE_STIMULUS` entirely (see run-token submission validation above).
- `circle_radius_px` (int > 0, optional): baseline circle radius. Present alone (with
  `motion_duration_ms`, not `motion_speed_profile`) it's a constant, schedule-level resolved value
  (Circle Collision Simple). Present **together with** `motion_speed_profile` it becomes per-trial,
  per-circle: each of the compiled schedule's `trials[]` independently resolves two radii
  (`{ "a", "b" }`), each within ±20% of this baseline (Circle Collision Complex).
- Exactly one of these two mutually-exclusive motion-timing shapes (or neither, for a classic
  discrete-stimulus test):
  - `motion_duration_ms: { "min": int > 0, "max": int >= min }` (Circle Collision Simple) —
    resolved per-trial into the compiled schedule's `trials[].motion_duration_ms`, same
    over-provisioned-buffer pattern as `inter_stimulus_delay_ms`/`delay_ms`.
  - `motion_speed_profile: { "start_speed_px_per_s": {min,max}, "mid_speed_px_per_s": {min,max}, "end_speed_px_per_s": {min,max}, "travel_distance_px": int > 0 }`
    (Circle Collision Complex) — three independently-ranged waypoint speeds describing a two-leg
    piecewise-linear ramp (start→mid over the first half of `travel_distance_px`, mid→end over the
    second half); resolved per-trial into `trials[].motion_speed_profile` (three concrete speeds +
    the implied `duration_ms`). **The server verifies the resolved total duration always lands in
    1000-5000ms and re-rolls internally (up to 100 attempts) if a draw falls outside that range** —
    it never returns a compiled schedule with an out-of-bound trial. If a description's configured
    ranges make the 1-5s window structurally unreachable (a real misconfiguration, not bad luck),
    run-start fails with `INTERNAL_ERROR` (500) rather than ever shipping a bad schedule — this
    should be caught by testing an imported description before relying on it in production, not
    surface to a real user.
- `travel_distance_px` (int > 0, optional, **Circle Collision Simple only** — CR-TEST-28, v1.8):
  only meaningful (and only validated) alongside `motion_duration_ms`; defaults to `800` (matching
  Complex's own `motion_speed_profile.travel_distance_px` convention) when omitted. A **logical**
  resolved distance basis for `closing_speed_px_per_ms` below — not a literal on-screen pixel count
  (the client's actual rendered stage width is measured at render time and varies per viewport).
  Complex needs no separate top-level key — it already carries its own `travel_distance_px` inside
  `motion_speed_profile` (CR-TEST-24).

**CR-TEST-28 (v1.8) — resolved `closing_speed_px_per_ms` (both Circle Collision variants):** every
compiled `trials[]` entry that carries `motion_duration_ms` or `motion_speed_profile` also gains
`closing_speed_px_per_ms` (float > 0), server-resolved, never client-anticipated (D11):
- Simple: `travel_distance_px / motion_duration_ms` — constant for the whole trial (no mid-trial
  speed change).
- Complex: `motion_speed_profile.travel_distance_px / motion_speed_profile.duration_ms` — the
  trial's **average effective** closing rate (Complex's actual speed varies within a trial across
  the three waypoints; this is a single representative rate sufficient to derive distance-at-click,
  not a re-expression of the full piecewise ramp).

**CR-TEST-28 (v1.8) — resolved `abs_value_aggregation` (both Circle Collision variants):** the
compiled schedule gains a schedule-level `abs_value_aggregation: true` whenever either variant's
motion fields are present (i.e. whenever `trials[].closing_speed_px_per_ms` is resolved) — generic
infrastructure keyed off the schedule, same pattern as `allow_early_response`, not special-cased to
a specific test's slug, so any future coincidence-distance r-test type can opt in the same way. See
the submission-response section above for exactly how this changes summary aggregation.

Seeded (`server/database/seeds/`): `simple-reaction` v1 (`trial_count: 10`, delay 1000–3000ms, one
channel, no timeout — CR-TEST-03), `two-hand-reaction` v1 (`trial_count: 10`, delay 1500–3500ms,
two channels, 2000ms per-hand timeout — CR-TEST-04), both Sprint 1, tag `visual`. `circle-
collision-simple` v1 (`trial_count: 10`, `motion_duration_ms: {1000,5000}`, `circle_radius_px: 40`,
`timeout_ms: 8000`, `allow_early_response: true` — CR-TEST-23; CR-TEST-28/v1.8 in-place update,
Sprint 13: description shape unaffected — the compiled schedule resolves `closing_speed_px_per_ms`
from the existing fields plus the `travel_distance_px` default, no new required description field)
and `circle-collision-complex` v1 (same shape but `motion_speed_profile` with 200–500 px/s waypoints
over an 800px travel distance instead of `motion_duration_ms`, same `circle_radius_px`/`timeout_ms`/
`allow_early_response` — CR-TEST-24; also CR-TEST-28/v1.8 in-place update, Sprint 13), both
originally Sprint 11, tags `visual` + `dynamic`.

---

## Reconciliation notes for ClientTeam

ClientTeam's Sprint 1 work (`client/js/mock-api.js`) was built against the still-empty v0.1
contract in parallel with this implementation — see `client/requirements/SPRINT1_REPORT.md`
"Assumptions made about unconfirmed API shapes." Direct answers to each flagged item:

1. **Schedule over-provisioning for false starts** — confirmed and implemented, matching
   ClientTeam's own mock design almost exactly: the compiled schedule includes spare resolved
   entries (`buffer_trials`, default 6, not the mock's field name `scheduleBufferTrials` — rename
   on integration) beyond `trial_count`. No separate "re-issue one trial" endpoint was needed.
2. **Run-token TTL** — not a fixed constant; computed per-run from the schedule (worst case: every
   buffer slot consumed) with a floor of 5 minutes (`RUN_TOKEN_TTL_SECONDS`, server `.env`). Client
   should use the returned `expires_at_ms`, not assume a constant.
3. **Field names** — this document is the byte-for-byte contract now; ClientTeam's placeholder
   names (`requiredTrialCount`, `perHandTimeoutMs`, `rTestId`/`versionId` camelCase) differ from
   the real snake_case field names above (`trial_count`, `timeout_ms`, `r_test_id`/
   `r_test_version_id`) — a mapping/rename pass in `client/js/api.js` is expected, exactly as that
   file's own header comment anticipated.
4. **Two-hand comparison metric** — `primary_metric_ms` (used for percentile/rank) is the **mean
   reaction time across all channels' valid responses**, not the slower-hand completion time
   ClientTeam's mock assumed. Flagging this specific difference for ProductOwner/Tester
   reconciliation, as ClientTeam requested.
5. **Admin enforcement** — real and server-side now (403 `ADMIN_REQUIRED` per D7); ClientTeam's
   `localStorage` dev toggle should be replaced with a real login + this API's `is_admin` flag
   (from `GET /auth/me`) once ClientTeam's own AUTH-dependent screens are scheduled.
6. **Category model** — implemented at Sprint 1 as a single-FK table (`r_test_categories`),
   admin-CRUD via `/admin/categories`, not a fixed enum — matched the mock's "admin-extendable
   list" shape. **Superseded at Sprint 11 (CR-TEST-25, v1.6):** replaced by a real many-to-many tag
   model (`r_test_tags` + `r_test_tag_links`), admin-CRUD via `/admin/tags` — an r-test can now
   carry any number of tags, not just one. See the Changelog's v1.6 entry.
7. **`dominant_hand` / `preferred_locale` sync** — both are real profile fields now
   (`PATCH /profile`); `Reflx.api.syncPreferredLocale()`'s no-op stub can be wired to it once
   ClientTeam has a login flow to obtain a session token.

**New, not previously anticipated by the mock:** there was no login/register system in
ClientTeam's Sprint 1 build (no AUTH CR was scheduled to either team this sprint) — every
authenticated endpoint above requires a real bearer token from `POST /auth/register` or
`POST /auth/login`, both implemented this sprint as necessary groundwork (see
`server/requirements/SPRINT1_REPORT.md`). ClientTeam has no task yet to build login UI; the
ProductOwner should schedule one before any of the above can be wired up for real.

---

## Changelog

- v1.8 (2026-09-23) — Sprint 13: **CR-TEST-28** (Circle Collision: full-stage travel, timeout-based
  stop, real distance-based KPI — ServerTeam half; supersedes CR-TEST-26's shipped
  motion-continuation behavior, see `REQUIREMENTS/BACKLOG.md` for the conflict verdict). Three
  changes, all scoped to Circle Collision (Simple + Complex) only — every other test type's
  schedule/response shape is completely unchanged:
  1. **Resolved `closing_speed_px_per_ms`** — every compiled `trials[]` entry with
     `motion_duration_ms` or `motion_speed_profile` gains this server-resolved field (D11: never
     client-anticipated). Simple derives it from a new optional `travel_distance_px` description
     field (defaults to 800, matching Complex's existing convention) divided by the resolved
     `motion_duration_ms`; Complex derives it from its existing `motion_speed_profile.
     travel_distance_px` divided by the resolved `duration_ms` (an average effective rate, since
     Complex's actual speed varies within a trial). Lets the client (or the server) compute a
     signed distance-at-click: `closing_speed_px_per_ms * (response_at - stimulus_at)`.
  2. **Timeout-as-null-response** — no new contract shape (the existing "null response legal when
     `timeout_ms` is set" clause already covers it; both Circle Collision seeds already set
     `timeout_ms: 8000`) — the client now reaches this path intentionally (full-stage-travel
     timeout, no click) rather than as a rare edge case. Confirmed by explicit unit test that a
     `null` response here is excluded from the distance-based mean, same as every other test type.
  3. **Abs-value summary aggregation, Circle-Collision-scoped only** — new schedule-level
     `abs_value_aggregation: true` (generic infra, same pattern as `allow_early_response`, not
     slug-keyed) makes `primary_metric_ms`/`summary.overall.mean_ms`/`median_ms`/`sd_ms`/`cv` and
     each channel's equivalents aggregate the **absolute value** of each trial's signed value
     instead of the signed value itself, so early/late trials don't net-cancel toward zero.
     `min`/`max` (renamed from `min_ms`/`max_ms` for this family only — "ms" is misleading for what's
     now a signed px distance) remain the genuinely signed extremes regardless. **This explicitly
     reverses v1.6's "a mixed set of early (negative) and late (positive) responses averages
     normally" statement for Circle Collision alone** — every other test type (including any future
     non-Circle-Collision use of `allow_early_response`) keeps the original signed-average behavior
     unchanged, verified by an explicit regression test (not an absence-of-change assumption).
  Both seeds (`circle-collision-simple.v1.json`/`circle-collision-complex.v1.json`) updated in place
  — purely additive change to the description shape (Simple's new `travel_distance_px` is optional
  with a default), no `.v2.json` bump needed.
- v1.7 (2026-09-23) — Sprint 12: **CR-STATS-07** (exclude-from-own-stats toggle) — `results` gains
  `excluded_from_own_stats` (bool, default `false`). New `PATCH /results/{id}/exclude` endpoint
  (auth required, IDOR-guarded identically to `GET /results/{id}/comparison` — `NOT_FOUND` for
  another user's result_id, same code whether it doesn't exist or isn't theirs, D3) toggles it.
  `GET /r-tests/{slug}/versions/{version}/history`'s each `entries[]` item gains `excluded: bool` —
  **not** filtered server-side, so an excluded entry stays visible (struck-through client-side)
  rather than silently vanishing. `GET /results/{id}/comparison` is completely unaffected by this
  flag (D19 still applies in full — this is a per-owner display toggle, not an approval-status
  change; the separate admin-driven peer-comparison pool is untouched). No other endpoint or
  response shape changed.
- v1.6 (2026-09-22) — Sprint 11: **CR-TEST-25** (multi-tag r-test categorization) — the single
  `category_id` FK on r_tests is replaced by a real many-to-many tag model (`r_test_tags`, renamed
  from `r_test_categories` with ids preserved 1:1, + new `r_test_tag_links` join table). `GET
  /r-tests`, `GET /r-tests/{slug}`, `GET /admin/r-tests` return `tags: [{ "id", "name" }, ...]`
  instead of `category_id`/`category_name`. `GET /r-tests?tag_id={id}` filters by tag membership
  (`category_id` kept as a deprecated alias). `POST /admin/r-tests`, `POST
  /admin/r-tests/{slug}/versions`, `PATCH /admin/r-tests/{slug}` accept `tag_ids?: int[]` (PATCH:
  replaces the full set; empty array clears, omitted leaves untouched) instead of `category_id`.
  `/admin/categories`/`/categories` renamed to `/admin/tags`/`/tags` (clean rename, no alias —
  no external consumer yet); `CATEGORY_NOT_FOUND`/`CATEGORY_NAME_TAKEN` renamed to
  `TAG_NOT_FOUND`/`TAG_NAME_TAKEN`. `r_tests.category_id` is kept as inert legacy data this sprint
  (not dropped) as a migration safety margin — app code no longer reads/writes it. **CR-TEST-23**
  (Circle Collision Simple, new r-test type) — a coincidence-anticipation test with no discrete
  stimulus onset. New optional description/schedule fields `motion_duration_ms`/`circle_radius_px`/
  `allow_early_response`; `RunService::validateTrialLog()` skips `REACTION_BEFORE_STIMULUS` for any
  run whose schedule sets `allow_early_response: true` (generic infra, not slug-keyed); verified
  `ResultSummaryService`'s existing mean/median/min/max arithmetic naturally produces signed
  (possibly-negative) values for early anticipations, no code change needed there. New seed
  `circle-collision-simple` v1, tags `visual`+`dynamic`. **CR-TEST-24** (Circle Collision Complex,
  extends CR-TEST-23) — circle size varies ±20% independently per circle per trial
  (`trials[].circle_radius_px: {"a","b"}`); closing speed varies *within* a trial via
  `motion_speed_profile` (3 resolved waypoint speeds + `duration_ms`), with the server verifying/
  re-rolling internally so the total resolved motion duration always lands in 1000-5000ms — never
  ships an out-of-bound schedule. Reuses CR-TEST-23's `allow_early_response` validation change
  unchanged. New seed `circle-collision-complex` v1, tags `visual`+`dynamic`. No other endpoint or
  response shape changed by any of the three CRs above; every new field is additive/optional and
  every existing test type's schedule/response shape is untouched when its description doesn't set
  the new fields.
- v1.5 (2026-09-18) — Sprint 9: **CR-AUTH-02** (implements D19, admin approval queue) —
  `results` gains `approval_status` (`pending`|`approved`|`rejected`, default `pending` on new
  submissions; existing pre-Sprint-9 rows backfilled `approved`). `GET /results/{id}/comparison`'s
  aggregate pool (`percentile`/`rank`/`total_participants`) now excludes non-`approved` results;
  the caller's own `your_value_ms` is unaffected regardless of their own result's status. New
  admin-only `GET /admin/results?status=pending` + `PATCH /admin/results/{id}`. **CR-STATS-08**
  (reaction-time variability) — `results` gains `sd_ms`/`cv`, computed at submission time
  (per-channel for two-hand tests, in `summary.channels.{channel}` and `summary.overall`);
  `GET /results/{id}/comparison` gains `your_sd_ms`/`your_cv`/`peer_sd_ms_median` (peer aggregate
  reuses CR-AUTH-02's `approval_status = 'approved'` filter — one gating mechanism, not two).
  **CR-AUTH-03** (optional profile fields) — `users` gains nullable `real_name`/`display_name`
  (<=100 chars, no uniqueness constraint); `UserProfile` and `PATCH /profile` extended to match;
  neither field appears in any anonymized/peer-comparison payload. No other endpoint or response
  shape changed.
- v1.4 (2026-09-16) — Sprint 6: CR-INFRA-02 (CI/CD). New endpoint `GET /health` (no auth) —
  exercises a real DB round-trip, returns `{ "data": { "status": "ok" } }` on success or 503
  `INTERNAL_ERROR` on DB failure. Used as the deploy workflow's post-deploy smoke-check target.
  No other endpoint or response shape changed.
- v1.3 addendum (2026-09-16) — Sprint 6: CR-UI-13 (default/fallback locale `en` → `ru`, D18).
  Prose-only correction, **no version bump** — the wire shape (`GET /config/locales`'s response
  shape, `PATCH /profile` validation behavior) is unchanged; only which locale code the
  fallback/default resolves to changed (`Locale::DEFAULT` constant server-side).
- v1.3 (2026-09-04) — Sprint 5: CR-INFRA-01 (per-deployment locale scoping). New endpoint
  `GET /config/locales` (no auth) returning `{ "data": { "enabled": [...], "default": "..." } }`
  — lets a deployment narrow the locales it offers (`ENABLED_LOCALES`/`DEFAULT_LOCALE`, server
  `.env`) without a client-side hardcoded list. `PATCH /profile`'s `preferred_locale` validation
  is narrowed to match: a value outside the deployment's enabled set now returns
  `UNSUPPORTED_LOCALE` (400) even if it's one of the 6 generally-supported codes. No other
  endpoint or response shape changed.
- v1.2 (2026-07-26) — Sprint 4: CR-STATS-04 fix — `GET /r-tests/{slug}/versions/{version}/history`
  no longer hardcaps results at 50. A user with 125 real results was only ever getting 65 back
  (50 + 15 across two different r-test/version scopes), silently truncated with no client-visible
  signal. Default behavior now returns the caller's **complete** history for the scope (safe
  because it's already a single-user, single-scope query); optional `limit`/`offset` query params
  let a caller page instead. Response gains a new `total` field (full count for the scope,
  independent of `limit`/`offset`). New `VALIDATION_ERROR` case if `limit`/`offset` are malformed.
  No other endpoint or response shape changed.
- v1.1 (2026-07-25) — Sprint 3: CR-UI-07 fix — `PATCH /profile`'s `preferred_locale` now accepts
  `ru` (6 supported codes total). `server/src/Support/Locale.php::SUPPORTED` had been missing `ru`
  since Sprint 2's CR-UI-06 even though `REQUIREMENTS/SHARED_CONSTANTS.md` already listed it and
  this doc's field description (previously "one of the 5 supported codes") was never corrected —
  both are now back in sync with the implementation. No other request/response shape changed.
- v1.0 (2026-07-05) — Sprint 1: all endpoints above added (auth/session groundwork; CR-TEST-01
  import/export; CR-TEST-05 categories/packages; CR-TEST-02 run-token issuance/submission;
  CR-TEST-06 series mode; CR-STATS-01/02 history + anonymized comparison; CR-UI-02 structured
  error codes + `preferred_locale`; CR-TEST-04 `dominant_hand`). Replaces the v0.1 skeleton.
- v0.1 (2026-07-05) — initial skeleton, no endpoints yet.

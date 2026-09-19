# Server Team — Completed Tasks — Sprint 9

- [x] SI-9.0: Pre-sprint check — run test suite, confirm build green — Tester re-ran, 106/460/0 failures
- [x] SI-9.1: CR-AUTH-02 — `approval_status` column on `results` (migration, both drivers, backfill existing rows `approved`) — Tester-validated live
- [x] SI-9.2: CR-AUTH-02 — filter `GET /results/{id}/comparison`'s aggregate query to `approval_status = 'approved'` — Tester-validated live
- [x] SI-9.3: CR-AUTH-02 — new admin-only `GET /admin/results?status=pending` + `PATCH /admin/results/{id}` — Tester-validated live
- [x] SI-9.4: CR-AUTH-02 — update `_API_CONTRACT/CONTRACT.md` (v1.5, shared entry with SI-9.6) — Tester-validated
- [x] SI-9.5: CR-STATS-08 — `sd_ms`/`cv` columns on `results` (migration, both drivers); compute at submission time — Tester-validated live, hand-computed SD matched server exactly
- [x] SI-9.6: CR-STATS-08 — extend `GET /results/{id}/comparison` with `your_sd_ms`/`your_cv` + real peer aggregate (reuses SI-9.2's filter) — Tester-validated live
- [x] SI-9.8: CR-AUTH-03 — `real_name`/`display_name` columns on `users` (migration, both drivers); extend `UserProfile`/`PATCH /profile` — Tester-validated live
- [x] SI-9.7: Tests + Report — verify SI-9.1 through SI-9.6, SI-9.8; save `server/requirements/SPRINT9_REPORT.md` — Tester-validated

Tester-validated 2026-09-18 (see `tester/requirements/SPRINT9_VALIDATION_REPORT.md`): full suite
independently re-run — 106 tests/460 assertions/0 failures, matching the report exactly. All 3 CRs
(CR-AUTH-02, CR-STATS-08, CR-AUTH-03) PASS on live-executed evidence against a real registered-user/
submitted-run backend, not code review alone.

`/simplify` pass (pre-Tester) found and fixed a genuine fragile-bandaid issue: the CR-STATS-08 peer
aggregate's `approval_status='approved'` filter was copy-pasted rather than truly shared with
CR-AUTH-02's comparison-pool filter — merged into one `ResultRepository::allApprovedMetricsForScope()`
query. Also extracted a duplicate `median()` into `server/src/Support/Stats.php` and duplicate
query-param validators into `server/src/Support/Validation.php`.

See `server/requirements/SPRINT9_REPORT.md`, `REQUIREMENTS/SPRINT9_PLAN.md`, and
`REQUIREMENTS/SPRINT9_VALIDATION_REPORT.md` for full detail.

# Server Team — Completed Tasks — Sprint 6

- [x] SI-6.0: Pre-sprint check — run test suite, confirm build green (84 tests/369 assertions, 0 failures, Docker php:8.2-cli)
- [x] SI-6.1: CR-UI-13 — `Locale::default()` `en` → `ru`; update CR-INFRA-01 test assertions; correct `CONTRACT.md` prose (no version bump) — Tester-validated TI-6.3, PASS (independently re-ran suite: 85 tests/371 assertions/0 failures, fallback log text confirms `ru`)
- [x] SI-6.2: CR-INFRA-02 — author `.github/workflows/deploy.yml` (SSH deploy, `.env` excluded, post-deploy health check) — Tester-validated TI-6.5, PASS
- [x] SI-6.3: CR-INFRA-02 — document required repo secrets (values minted by user, not committed) — Tester-validated TI-6.5/TI-6.6, PASS (git history scan found no leaked key material)
- [x] SI-6.4: Tests + Report — verify SI-6.1 fallback behavior + CR-INFRA-01 regression; save `server/requirements/SPRINT6_REPORT.md` — Tester independently re-ran suite, matches exactly
- [x] SI-6.5: CR-INFRA-02 — live workflow exercise — done via MT-05 (2026-09-17): 2 independent `workflow_dispatch` runs both fully green after 5 real fixes (non-default SSH port, rsync unavailable on target → switched to scp+ssh-action, missing target dir, wrong deploy path, WebHostMost's flat serving model needing a public/-vs-app-root split). Independently verified live: `GET /rtest/api/health` → `{"data":{"status":"ok"}}` HTTP 200, `GET /rtest/` → HTTP 200. Full detail: `ProductOwner_records/MANUAL_TASKS_COMPLETED.md` MT-05. Tester-validated TI-6.5, PASS (re-confirmed live this session).

See `REQUIREMENTS/SPRINT6_PLAN.md`, `server/requirements/SPRINT_TASKS.md`, and
`REQUIREMENTS/SPRINT6_VALIDATION_REPORT.md` for full detail.

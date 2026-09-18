# Client Team — Completed Tasks — Sprint 7

- [x] CI-7.0: Pre-sprint check — run `/project-verify` smoke flow, confirm build green
- [x] CI-7.1: CR-STATS-03 — fix reference-line label edge-clipping (`.vhist-refline-tag` measured-geometry clamp)
- [x] CI-7.2: CR-STATS-03 — fix inverted sparkline trend direction (`buildSparkline()` norm-to-y mapping)
- [x] CI-7.3: CR-TEST-19 — register hand-key watchers before the stimulus delay (top of `armTrial()`)
- [x] CI-7.4: CR-TEST-19 — detect and handle false starts in `onHand()`, re-arm via `buffer_trials`
- [x] CI-7.5: CR-UI-16 — `countdownSeconds` setting + Settings field (Layout approved: `client/prototype-settings-countdown.html`)
- [x] CI-7.6: CR-UI-16 — countdown UI before test start (`wireStart()`, series/"Take again")
- [x] CI-7.7: CR-UI-15 — persistent Test-page context header (Layout approved: `client/prototype-test-header.html`)
- [x] CI-7.8: CR-UI-16 — locale keys, all 6 locales
- [x] CI-7.9: CR-UI-15 — locale keys, all 6 locales
- [x] CI-7.10: Tests + Report — save `client/requirements/SPRINT7_REPORT.md`; append to `PRODUCT_OWNER_INBOX.md`

Tester-validated 2026-09-17 (see `tester/requirements/SPRINT7_VALIDATION_REPORT.md`): all 5 CRs PASS
live (Chrome-driven local server + real dispatched keyboard events), no defects filed. Post-report,
the ProductOwner's mandatory `/simplify` pass applied cleanup fixes to `runner.js`/`test-registry.js`
(registry-driven header config, replacing a slug-branching map) and `stats-page.js` (two-pass
`alignRefLines()`, replacing interleaved reads/writes) — re-verified by the Tester as behavior-identical.

See `client/requirements/SPRINT7_REPORT.md`, `REQUIREMENTS/SPRINT7_PLAN.md`, and
`REQUIREMENTS/SPRINT7_VALIDATION_REPORT.md` for full detail.

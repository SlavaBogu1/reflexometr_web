# Client Team — Completed Tasks — Sprint 9

- [x] CI-9.0: Pre-sprint check — run `/project-verify` smoke flow, confirm build green
- [x] CI-9.1: CR-AUTH-02 — admin-only pending-results page (list, approve, reject), all 6 locales — Tester-validated live against real backend (ClientTeam had only faked-session evidence)
- [x] CI-9.2: CR-UI-23 — cap `.grid` at 3 columns max; add a capped grid to `paths.html`'s `#path-list` — Tester-validated live incl. narrow-viewport collapse
- [x] CI-9.3: CR-UI-24 — Test Description page restructure per approved mockup, all 6 locales — Tester-validated live
- [x] CI-9.4: CR-STATS-08 — variability row on Result page + stats-dashboard peer figure, all 6 locales — Tester-validated live with a real completed run
- [x] CI-9.6: CR-AUTH-03 — Settings > Profile User name/Display name fields; nav pill precedence, all 6 locales — Tester-validated live against real backend
- [x] CI-9.7: CR-AUTH-04 — Register page confirm-password field, all 6 locales — initial Tester pass found a real defect (Enter-to-submit not wired for the confirm field, criterion 6 FAIL); one-line fix applied (`auth-page.js:69`), re-validated PASS on all 6 criteria live
- [x] CI-9.5: Tests + Report — verify CI-9.1 through CI-9.4, CI-9.6, CI-9.7; save `client/requirements/SPRINT9_REPORT.md`

Tester-validated 2026-09-18 (see `tester/requirements/SPRINT9_VALIDATION_REPORT.md`): all 6 CRs
(CR-AUTH-02, CR-UI-23, CR-UI-24, CR-STATS-08, CR-AUTH-03, CR-AUTH-04) PASS on live-executed evidence
— a real local backend (Docker PHP/SQLite via `tester/router.php`) plus Chrome browser automation,
closing the real-backend gap ClientTeam's own report had flagged for 3 of its tasks. One genuine
defect found and fixed in-sprint (CR-AUTH-04's Enter-to-submit binding missing on the new confirm
field) — see `REQUIREMENTS/BACKLOG.md`'s CR-AUTH-04 section for the fail note + re-validation note.

`/simplify` pass (pre-Tester) removed a dead i18n key (`runner.description.guidance`), deduped a
copy-paste settings-page.js listener pair into a `wireNameField()` factory, and added missing
distinct left/right i18n labels for the two-hand variability rows (previously both hands showed an
identical, ambiguous "Variability (SD)" label).

See `client/requirements/SPRINT9_REPORT.md`, `REQUIREMENTS/SPRINT9_PLAN.md`, and
`REQUIREMENTS/SPRINT9_VALIDATION_REPORT.md` for full detail.

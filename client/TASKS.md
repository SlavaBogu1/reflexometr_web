# Sprint 1 — ClientTeam Tasks

## Sprint 1 Tasks
- [x] CI-1.0: Pre-sprint check — note baseline (no automated suite yet)
- [x] CI-1.1: CR-UI-03 — Platform Settings screen (device/key bindings + language selector)
      (keyboard + language legs fully PASS on real execution; gamepad-hardware leg → MT-01,
      does not hold back the rest — see SPRINT1_VALIDATION_REPORT.md)
- [x] CI-1.2: CR-TEST-01 — Admin r-test library screen (import/export, metadata)
- [x] CI-1.3: CR-TEST-05 — Categories & Packages tab
- [x] CI-1.4: CR-STATS-01 — Stats dashboard version scoping
      (defect found and filed separately as CR-UI-05 — version-label bug, not a data-mixing bug;
      does not fail this CR's stated acceptance)
- [x] CI-1.5: CR-TEST-02 — Generic runner shell (Description/Test/Result)
- [x] CI-1.6: CR-TEST-06 — Series mode + Quit action
- [x] CI-1.7: CR-TEST-03 — Simple Visual Reaction Time content
- [x] CI-1.8: CR-TEST-04 — Two-Hand Reaction Time content
- [x] CI-1.9: CR-STATS-02 — Anonymized comparison view
- [x] CI-1.10: CR-UI-01 — Public front page
- [x] CI-1.11: CR-UI-02 — String externalization + locale resolution
- [x] CI-1.12: CR-UI-02 — Apply translations (5 locales)
- [x] CI-1.13: Final — smoke-test + SPRINT1_REPORT.md + inbox

## Follow-up (post-Sprint-1, pre-Tester-validation)
- [x] CI-1.14: CR-AUTH-01 — Login/Register UI + real API wiring (retire mock-api.js,
      wire all screens to `_API_CONTRACT/CONTRACT.md` v1.0, bearer-token auth)

Tester-validated 2026-07-06 — see `tester/requirements/SPRINT1_VALIDATION_REPORT.md`. All tasks
PASS on executed evidence (real browser walkthrough via Kapture + live API integration). Two
incidental defects found and filed as their own CRs (CR-UI-04, CR-UI-05) rather than failing their
host CRs, since neither violates its CR's literal stated acceptance criteria.

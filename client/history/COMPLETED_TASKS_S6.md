# Client Team — Completed Tasks — Sprint 6

- [x] CI-6.0: Pre-sprint check — static smoke checks run (no native PHP/browser tool in this
  environment); no build step to break, all files present and referenced correctly beforehand
- [x] CI-6.1: CR-UI-10 — hide "My Stats" nav link while logged out (`nav.js` conditional push, mirror `isAdmin()` pattern) — Tester-validated TI-6.1, PASS
- [x] CI-6.2: CR-UI-11 — rename "Platform Settings" → "Settings" (nav, page title, `browse.intro`, all 6 locales) — Tester-validated TI-6.2, PASS
- [x] CI-6.3: CR-UI-13 — default/fallback locale `en` → `ru` in `i18n.js` (server-side `Locale::DEFAULT`
  in `server/src/Support/Locale.php` was still `'en'` at implementation time — see report) — Tester-validated TI-6.3, PASS
- [x] CI-6.4: CR-UI-12 — strip landing page (`index.html`) to intro + single "Take a test" CTA — Tester-validated TI-6.4, PASS
- [x] CI-6.5: CR-UI-12 — create 4 new sub-pages (reflexes/medical-facts/certification/offerings.html); delete `science.html` — Tester-validated TI-6.4, PASS
- [x] CI-6.6: CR-UI-12 — "Home" dropdown sub-menu in `nav.js` + `.has-submenu`/`.submenu` CSS — Tester-validated TI-6.4, PASS (live-executed DOM event tests)
- [x] CI-6.7: CR-UI-12 — locale keys for the 4 new pages, all 6 locales; remove `science.*` keys — Tester-validated TI-6.4, PASS
- [x] CI-6.8: Tests + Report — static verification done (link integrity, locale key-parity, JS/CSS
  syntax); saved `client/requirements/SPRINT6_REPORT.md`; live-browser/interactive checks completed
  by Tester (TI-6.1-6.4), all PASS
- [x] CI-6.9: CR-INFRA-02 — client-side deploy path — done via MT-05 (2026-09-17): the `deploy-client`
  job passed on 2 independent `workflow_dispatch` runs, independently verified live at
  `https://sandbox.bogushevich.ru/rtest/` (HTTP 200). Full detail: `ProductOwner_records/
  MANUAL_TASKS_COMPLETED.md` MT-05. Tester-validated TI-6.5, PASS (re-confirmed live this session).

See `REQUIREMENTS/SPRINT6_PLAN.md`, `client/requirements/SPRINT_TASKS.md`, and
`REQUIREMENTS/SPRINT6_VALIDATION_REPORT.md` for full detail.

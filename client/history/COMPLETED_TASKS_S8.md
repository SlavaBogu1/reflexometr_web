# Client Team — Completed Tasks — Sprint 8

- [x] CI-8.0: Pre-sprint check — run `/project-verify` smoke flow, confirm build green
- [x] CI-8.1: CR-UI-18 — fix Home submenu hover dead-zone (contiguous hit-box + close-delay) — PASS, live
- [x] CI-8.2: CR-UI-19 — merge Settings "Profile" + "Language" cards (Layout approved: `client/prototype-settings-profile-merge.html`) — PASS, live
- [x] CI-8.3: CR-UI-20 — merge "Input devices" + "Two-hand mapping" + icons (Layout approved: `client/prototype-settings-devices-merge.html`) — PASS, live
- [x] CI-8.4: CR-UI-21 — trial counter inside stimulus stage, +20% circle (Layout approved: `client/prototype-test-header-v2.html`) — PASS, live
- [x] CI-8.5: CR-UI-22 — shorten header Objective, add color swatches to Stimulus (Layout approved: `client/prototype-test-header-v2.html`) — PASS, live
- [x] CI-8.6: CR-INFRA-05 — client trusts server locale default; `reflx:localechange` propagation audit — PASS, live
- [x] CI-8.7: CR-INFRA-06 — auto-generated footer build label (`build-info.js`) — PASS, live
- [x] CI-8.8: CR-TEST-20 — visible false-start feedback (yellow orb + ~500ms pause) — PASS, live
- [x] CI-8.9: Tests + Report — saved `client/requirements/SPRINT8_REPORT.md`

Tester-validated 2026-09-18 (see `tester/requirements/SPRINT8_VALIDATION_REPORT.md`): all 8 CRs PASS
live, no defects filed. Post-report, the ProductOwner's mandatory `/simplify` pass removed a redundant
JS close-delay timer in `nav.js` (CR-UI-18, kept only the CSS geometric fix), simplified
`renderStimulusBullet()`'s token-splicing logic (CR-UI-22), tightened `i18n.js`'s malformed-response
handling to route through the existing `.catch()` path (CR-INFRA-05), and deferred `build-info.js`'s
fetch to `window.load` (CR-INFRA-06) — all re-verified by the Tester as correct against the current code.

See `client/requirements/SPRINT8_REPORT.md`, `REQUIREMENTS/SPRINT8_PLAN.md`, and
`REQUIREMENTS/SPRINT8_VALIDATION_REPORT.md` for full detail.

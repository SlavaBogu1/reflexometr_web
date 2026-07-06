/**
 * Reflexometr — shared client-side registry mapping an r-test's slug to its i18n key
 * prefix (CR-UI-02: test name/description are UI-owned localized content, not server
 * data — D11: the server never sends raw test content, only compiled schedules).
 * Used by browse.html, runner.js, and stats-page.js so the mapping lives in one place.
 */
(function (global) {
  "use strict";
  var Reflx = global.Reflx = global.Reflx || {};
  Reflx.testRegistry = {
    "simple-reaction": { prefix: "test.simple" },
    "two-hand-reaction": { prefix: "test.twohand" }
  };
})(window);

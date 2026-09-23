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
    "simple-reaction": {
      prefix: "test.simple",
      headerStimulusKey: "test.simple.header_stimulus",
      deviceHint: function (s) {
        return { key: "test.simple.header_devices", params: { key: s.keyboardKeyLabel, gamepad: s.gamepadButtonLabel } };
      }
    },
    "two-hand-reaction": {
      prefix: "test.twohand",
      headerStimulusKey: "test.twohand.header_stimulus",
      deviceHint: function (s) {
        return { key: "test.twohand.header_devices", params: { left: s.leftHandKeyLabel, right: s.rightHandKeyLabel } };
      }
    },
    // CR-TEST-23: Circle Collision (Simple) — no color-change stimulus (see
    // js/tests/circle-collision-simple.js's header comment), so header_stimulus
    // describes the motion itself rather than a color transition.
    "circle-collision-simple": {
      prefix: "test.collision_simple",
      headerStimulusKey: "test.collision_simple.header_stimulus",
      deviceHint: function (s) {
        return { key: "test.collision_simple.header_devices", params: { key: s.keyboardKeyLabel, gamepad: s.gamepadButtonLabel } };
      }
    },
    // CR-TEST-24: Circle Collision (Complex) — same interaction/module as Simple,
    // different resolved motion profile (per-trial size + within-trial speed change).
    "circle-collision-complex": {
      prefix: "test.collision_complex",
      headerStimulusKey: "test.collision_complex.header_stimulus",
      deviceHint: function (s) {
        return { key: "test.collision_complex.header_devices", params: { key: s.keyboardKeyLabel, gamepad: s.gamepadButtonLabel } };
      }
    }
  };
})(window);

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
    },
    // CR-TEST-30: Random Target Appearance and Pointing (K3) — mouse-only test,
    // no keyboard or gamepad; header_devices reflects that.
    "random-target-pointing": {
      prefix: "test.random_target",
      headerStimulusKey: "test.random_target.header_stimulus",
      deviceHint: function () {
        return { key: "test.random_target.header_devices", params: {} };
      }
    },
    // CR-TEST-32: Choice Reaction: Geometry (K5) — keyboard-only (arrow keys);
    // no gamepad or mouse response mode.
    "choice-reaction-geometry": {
      prefix: "test.choice_geometry",
      headerStimulusKey: "test.choice_geometry.header_stimulus",
      deviceHint: function () {
        return { key: "test.choice_geometry.header_devices", params: {} };
      }
    },
    // CR-TEST-34: Peripheral Visual Reaction (K7) — key or click depending on
    // the schedule's response_type; header_devices uses keyboard hint.
    "peripheral-reaction": {
      prefix: "test.peripheral",
      headerStimulusKey: "test.peripheral.header_stimulus",
      deviceHint: function (s) {
        return { key: "test.peripheral.header_devices", params: { key: s.keyboardKeyLabel } };
      }
    },
    // CR-TEST-35: Temporal Prediction (K8) — mouse-click only (predict when
    // moving circle reaches target line).
    "temporal-prediction": {
      prefix: "test.temporal",
      headerStimulusKey: "test.temporal.header_stimulus",
      deviceHint: function () {
        return { key: "test.temporal.header_devices", params: {} };
      }
    },
    // CR-TEST-33: Visual Conflict (K6) — keyboard arrow keys; two-phase state machine
    // (pretrain: respond to color; measurement: respond to shape, ignore color).
    "visual-conflict": {
      prefix: "test.visual_conflict",
      headerStimulusKey: "test.visual_conflict.header.stimulus",
      deviceHint: function () {
        return { key: "test.visual_conflict.header.devices", params: {} };
      }
    }
  };
})(window);

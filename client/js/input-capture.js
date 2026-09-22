/**
 * Reflexometr — low-level input capture helpers shared by the concrete r-tests
 * (CR-TEST-03, CR-TEST-04). Timestamps always come from `performance.now()`
 * (monotonic, sub-ms) — never `Date.now()` — per the standing reflex-timing
 * requirement in client/CLAUDE.md.
 */
(function (global) {
  "use strict";
  var Reflx = global.Reflx = global.Reflx || {};

  /**
   * Watch a single input channel until stopped or (optionally) until it fires once.
   * spec: { type: "keyboard", code } | { type: "mouse", target } | { type: "gamepad", index }
   * onFire(perfNowTimestamp) is called every time the channel is actuated while active.
   *
   * For type: "mouse", pass the outer stage/card container as `target`, not an inner
   * stimulus graphic — `mousedown` bubbles up from any descendant, so binding to the
   * full visible card (not just e.g. the response circle) gives the whole card a hit
   * area matching what the user sees as clickable (CR-TEST-22).
   */
  function watch(spec, onFire) {
    var active = true;
    var cleanup = function () {};

    if (spec.type === "keyboard") {
      var onKey = function (e) {
        if (!active || e.code !== spec.code) return;
        e.preventDefault();
        onFire(performance.now());
      };
      document.addEventListener("keydown", onKey, true);
      cleanup = function () { document.removeEventListener("keydown", onKey, true); };
    } else if (spec.type === "mouse") {
      var target = spec.target || document;
      var onDown = function (e) {
        if (!active || e.button !== 0) return;
        onFire(performance.now());
      };
      target.addEventListener("mousedown", onDown);
      cleanup = function () { target.removeEventListener("mousedown", onDown); };
    } else if (spec.type === "gamepad") {
      var wasPressed = false;
      var raf = null;
      var poll = function () {
        if (!active) return;
        var pads = navigator.getGamepads ? navigator.getGamepads() : [];
        for (var i = 0; i < pads.length; i++) {
          var pad = pads[i];
          if (!pad || !pad.buttons[spec.index]) continue;
          var pressed = pad.buttons[spec.index].pressed;
          if (pressed && !wasPressed) onFire(performance.now());
          wasPressed = pressed;
        }
        raf = requestAnimationFrame(poll);
      };
      raf = requestAnimationFrame(poll);
      cleanup = function () { if (raf) cancelAnimationFrame(raf); };
    }

    return { stop: function () { active = false; cleanup(); } };
  }

  Reflx.inputCapture = { watch: watch };
})(window);

/**
 * Reflexometr — Platform Settings storage (CR-UI-03).
 *
 * Device/key bindings persist in localStorage, this machine/browser only (no
 * cross-device sync in this CR). Language additionally syncs to the profile
 * once logged in per CR-UI-02 — no login system has landed yet this sprint
 * (not a Sprint 1 ClientTeam task), so that half is stubbed: see
 * Reflx.api.syncPreferredLocale() and SPRINT1_REPORT.md for the assumption.
 */
(function (global) {
  "use strict";

  var Reflx = global.Reflx = global.Reflx || {};
  var STORAGE_KEY = "reflx.settings.v1";

  var DEFAULTS = {
    keyboardKey: "Space",
    keyboardKeyLabel: "Space",
    gamepadButtonIndex: 0,
    gamepadButtonLabel: "Button 0 (A / Cross)",
    leftHandKey: "KeyZ",
    leftHandKeyLabel: "Z",
    rightHandKey: "Slash",
    rightHandKeyLabel: "/",
    dominantHand: null, // null | "left" | "right" | "none-recorded"
    locale: null // null = follow browser detection; else an explicit CR-UI-02 locale code
  };

  function get() {
    var stored = Reflx.util.readJSON(STORAGE_KEY, {});
    var merged = {};
    Object.keys(DEFAULTS).forEach(function (k) { merged[k] = (stored[k] !== undefined) ? stored[k] : DEFAULTS[k]; });
    return merged;
  }

  function set(patch) {
    var current = get();
    var next = Object.assign({}, current, patch);
    Reflx.util.writeJSON(STORAGE_KEY, next);
    document.dispatchEvent(new CustomEvent("reflx:settingschange", { detail: next }));
    return next;
  }

  function resetToDefaults() {
    Reflx.util.writeJSON(STORAGE_KEY, DEFAULTS);
    document.dispatchEvent(new CustomEvent("reflx:settingschange", { detail: get() }));
    return get();
  }

  /** Human label for a KeyboardEvent.code, used when capturing a new binding. */
  function labelForKeyCode(code) {
    if (code === "Space") return "Space";
    if (code.indexOf("Key") === 0) return code.slice(3);
    if (code.indexOf("Digit") === 0) return code.slice(5);
    return code;
  }

  Reflx.settings = {
    DEFAULTS: DEFAULTS,
    get: get,
    set: set,
    resetToDefaults: resetToDefaults,
    labelForKeyCode: labelForKeyCode
  };
})(window);

/**
 * Reflexometr — Platform Settings page controller (CR-UI-03).
 * CR-AUTH-01 follow-up: dominant hand + language now sync to the real profile
 * (`PATCH /profile`) whenever the user is logged in, in addition to the local
 * `localStorage` copy (which still always exists — anonymous visitors keep working
 * exactly as before, per CR-UI-03 acceptance 5 "without logging in").
 */
(function () {
  "use strict";
  var Reflx = window.Reflx;
  var t = Reflx.i18n.t;
  var api = Reflx.api;

  // Locales are always shown by their own native name, regardless of the current UI
  // locale (standard language-picker UX) — not run through i18n.t().
  var LOCALE_NAMES = { en: "English", es: "Español", de: "Deutsch", fr: "Français", "zh-Hans": "简体中文", ru: "Русский" };

  var gamepadPollHandle = null;

  function renderValues() {
    var s = Reflx.settings.get();
    document.getElementById("keyboard-value").textContent = s.keyboardKeyLabel;
    document.getElementById("gamepad-value").textContent = s.gamepadButtonLabel;
    document.getElementById("lefthand-value").textContent = s.leftHandKeyLabel;
    document.getElementById("righthand-value").textContent = s.rightHandKeyLabel;
    document.getElementById("dominant-hand-select").value = s.dominantHand || "";
    document.getElementById("countdown-seconds").value = s.countdownSeconds;
    document.getElementById("language-select").value = s.locale || "__auto__";
    document.getElementById("login-note").textContent = t(Reflx.session.isLoggedIn() ? "settings.synced_note" : "settings.login_note");

    // CR-AUTH-03: server-backed only, no local settings fallback — blank when
    // logged out (fields themselves stay editable-looking but changes only
    // persist while logged in, same as dominant-hand's sync-only-if-logged-in
    // behavior below).
    var user = Reflx.session.getUser();
    document.getElementById("realname-input").value = (user && user.real_name) || "";
    document.getElementById("displayname-input").value = (user && user.display_name) || "";
  }

  function populateLanguageSelect() {
    var sel = document.getElementById("language-select");
    sel.innerHTML = "";
    var autoOpt = document.createElement("option");
    autoOpt.value = "__auto__";
    autoOpt.textContent = "Auto (" + t("settings.language.label") + ")";
    sel.appendChild(autoOpt);
    Reflx.i18n.SUPPORTED.forEach(function (code) {
      var opt = document.createElement("option");
      opt.value = code;
      opt.textContent = LOCALE_NAMES[code] || code;
      sel.appendChild(opt);
    });
  }

  function flashSaved() {
    var flag = document.getElementById("saved-flag");
    flag.style.display = "inline-block";
    clearTimeout(flashSaved._t);
    flashSaved._t = setTimeout(function () { flag.style.display = "none"; }, 1500);
  }

  function startRebindKeyboard(fieldKey, labelField) {
    var btn = document.getElementById(fieldKey === "keyboardKey" ? "btn-rebind-keyboard" : (fieldKey === "leftHandKey" ? "btn-rebind-left" : "btn-rebind-right"));
    var original = btn.textContent;
    btn.textContent = t("settings.press_prompt");
    btn.disabled = true;
    function onKey(e) {
      e.preventDefault();
      var patch = {};
      patch[fieldKey] = e.code;
      patch[labelField] = Reflx.settings.labelForKeyCode(e.code);
      Reflx.settings.set(patch);
      cleanup();
    }
    function cleanup() {
      document.removeEventListener("keydown", onKey, true);
      btn.textContent = original;
      btn.disabled = false;
      renderValues();
      flashSaved();
    }
    document.addEventListener("keydown", onKey, true);
  }

  function startRebindGamepad() {
    var btn = document.getElementById("btn-rebind-gamepad");
    var original = btn.textContent;
    btn.textContent = t("settings.press_prompt_gamepad");
    btn.disabled = true;
    var start = Date.now();
    function poll() {
      var pads = navigator.getGamepads ? navigator.getGamepads() : [];
      for (var i = 0; i < pads.length; i++) {
        var pad = pads[i];
        if (!pad) continue;
        for (var b = 0; b < pad.buttons.length; b++) {
          if (pad.buttons[b].pressed) {
            Reflx.settings.set({ gamepadButtonIndex: b, gamepadButtonLabel: "Button " + b });
            stop();
            renderValues();
            flashSaved();
            return;
          }
        }
      }
      if (Date.now() - start > 15000) { stop(); return; } // give up after 15s, no gamepad input seen
      gamepadPollHandle = requestAnimationFrame(poll);
    }
    function stop() {
      cancelAnimationFrame(gamepadPollHandle);
      btn.textContent = original;
      btn.disabled = false;
    }
    poll();
  }

  function wire() {
    document.getElementById("btn-rebind-keyboard").addEventListener("click", function () { startRebindKeyboard("keyboardKey", "keyboardKeyLabel"); });
    document.getElementById("btn-rebind-left").addEventListener("click", function () { startRebindKeyboard("leftHandKey", "leftHandKeyLabel"); });
    document.getElementById("btn-rebind-right").addEventListener("click", function () { startRebindKeyboard("rightHandKey", "rightHandKeyLabel"); });
    document.getElementById("btn-rebind-gamepad").addEventListener("click", startRebindGamepad);

    document.getElementById("dominant-hand-select").addEventListener("change", function (e) {
      var value = e.target.value || null;
      Reflx.settings.set({ dominantHand: value });
      // The server enum has no "unset" state — only sync an explicit choice up.
      if (value && Reflx.session.isLoggedIn()) {
        api.patchProfile({ dominant_hand: value }).then(function (res) {
          if (res.ok) Reflx.session.updateUser({ dominant_hand: res.data.dominant_hand });
        });
      }
      flashSaved();
    });

    function wireNameField(inputId, profileKey) {
      document.getElementById(inputId).addEventListener("change", function (e) {
        var value = e.target.value.trim() || null;
        if (!Reflx.session.isLoggedIn()) return; // server-backed only — no-op while logged out
        var patch = {};
        patch[profileKey] = value;
        api.patchProfile(patch).then(function (res) {
          if (!res.ok) { alert(api.messageFor(res.code)); renderValues(); return; }
          var update = {};
          update[profileKey] = res.data[profileKey];
          Reflx.session.updateUser(update);
          flashSaved();
        });
      });
    }
    wireNameField("realname-input", "real_name");
    wireNameField("displayname-input", "display_name");

    document.getElementById("countdown-seconds").addEventListener("change", function (e) {
      // 0 is a valid "disable countdown" value, not an error — clamp to the
      // field's own min/max (0..10) so an out-of-range typed value doesn't
      // silently persist something the UI itself wouldn't allow via the spinner.
      var raw = parseInt(e.target.value, 10);
      var value = isNaN(raw) ? Reflx.settings.DEFAULTS.countdownSeconds : Reflx.util.clamp(raw, 0, 10);
      Reflx.settings.set({ countdownSeconds: value });
      renderValues();
      flashSaved();
    });

    document.getElementById("language-select").addEventListener("change", function (e) {
      var val = e.target.value === "__auto__" ? null : e.target.value;
      Reflx.settings.set({ locale: val });
      Reflx.i18n.setLocale(val || Reflx.i18n.getLocale());
      if (val === null) Reflx.i18n.init(); // re-resolve via browser detection
      if (Reflx.session.isLoggedIn()) {
        api.patchProfile({ preferred_locale: val }).then(function (res) {
          if (res.ok) Reflx.session.updateUser({ preferred_locale: res.data.preferred_locale });
        });
      }
      renderValues();
      renderAll();
      flashSaved();
    });

    document.getElementById("btn-reset").addEventListener("click", function () {
      Reflx.settings.resetToDefaults();
      renderValues();
      flashSaved();
    });
  }

  function renderAll() {
    Reflx.i18n.applyToDocument();
    populateLanguageSelect();
    renderValues();
  }

  Reflx.i18n.loadLocaleConfig().then(function () {
    Reflx.i18n.init();
    Reflx.nav.render("settings");
    renderAll();
    wire();
  });
  document.addEventListener("reflx:localechange", renderAll);
  document.addEventListener("reflx:sessionchange", renderValues);
})();

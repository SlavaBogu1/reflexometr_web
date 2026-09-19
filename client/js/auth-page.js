/** Reflexometr — Login/Register page controller (CR-AUTH-01). */
(function () {
  "use strict";
  var Reflx = window.Reflx;
  var t = Reflx.i18n.t;
  var api = Reflx.api;

  var params = new URLSearchParams(location.search);
  var returnTo = params.get("returnTo") || "index.html";
  var mode = "login"; // "login" | "register"

  function setMode(next) {
    mode = next;
    var isLogin = mode === "login";
    document.getElementById("auth-heading").textContent = t(isLogin ? "auth.login.heading" : "auth.register.heading");
    document.getElementById("auth-submit").textContent = t(isLogin ? "auth.login.submit" : "auth.register.submit");
    document.getElementById("auth-toggle").textContent = t(isLogin ? "auth.toggle_to_register" : "auth.toggle_to_login");
    document.getElementById("auth-password-hint").style.display = isLogin ? "none" : "inline";
    document.getElementById("auth-password").setAttribute("autocomplete", isLogin ? "current-password" : "new-password");
    document.getElementById("auth-password-confirm-row").style.display = isLogin ? "none" : "flex";
    // Reset on every mode switch so a stale value from a prior attempt can't cause a false mismatch later.
    document.getElementById("auth-password-confirm").value = "";
    hideError();
  }

  function showError(code) {
    var box = document.getElementById("auth-error");
    box.textContent = api.messageFor(code);
    box.style.display = "block";
  }
  function hideError() { document.getElementById("auth-error").style.display = "none"; }

  /** After a successful register/login, server profile values win over any pre-login local guesses. */
  function adoptServerProfile(user) {
    Reflx.settings.set({
      dominantHand: user.dominant_hand === "none-recorded" ? null : user.dominant_hand,
      locale: user.preferred_locale || null
    });
    if (user.preferred_locale) Reflx.i18n.setLocale(user.preferred_locale);
  }

  function submit() {
    hideError();
    var email = document.getElementById("auth-email").value.trim();
    var password = document.getElementById("auth-password").value;

    if (mode === "register") {
      var confirm = document.getElementById("auth-password-confirm").value;
      if (confirm !== password) { showError("AUTH_PASSWORD_CONFIRM_MISMATCH"); return; }
    }

    var submitBtn = document.getElementById("auth-submit");
    submitBtn.disabled = true;
    submitBtn.textContent = t(mode === "login" ? "auth.logging_in" : "auth.registering");

    var call = mode === "login" ? api.login(email, password) : api.register(email, password);
    call.then(function (res) {
      submitBtn.disabled = false;
      submitBtn.textContent = t(mode === "login" ? "auth.login.submit" : "auth.register.submit");
      if (!res.ok) { showError(res.code); return; }
      Reflx.session.set(res.data.token, res.data.user);
      adoptServerProfile(res.data.user);
      location.href = returnTo;
    });
  }

  document.getElementById("auth-submit").addEventListener("click", submit);
  document.getElementById("auth-password").addEventListener("keydown", function (e) { if (e.key === "Enter") submit(); });
  document.getElementById("auth-password-confirm").addEventListener("keydown", function (e) { if (e.key === "Enter") submit(); });
  document.getElementById("auth-toggle").addEventListener("click", function (e) {
    e.preventDefault();
    setMode(mode === "login" ? "register" : "login");
  });

  Reflx.i18n.loadLocaleConfig().then(function () {
    Reflx.i18n.init();
    Reflx.nav.render("login");
    Reflx.i18n.applyToDocument();
    setMode("login");
  });

  // Already logged in? Nothing more to do here.
  if (Reflx.session.isLoggedIn()) location.href = returnTo;

  document.addEventListener("reflx:localechange", function () { Reflx.i18n.applyToDocument(); setMode(mode); });
})();

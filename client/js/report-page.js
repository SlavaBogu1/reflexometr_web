/**
 * Reflexometr — My Report page controller (CR-STATS-09).
 * Placeholder page: auth guard (redirect to login if not logged in); no API calls.
 */
(function () {
  "use strict";
  var Reflx = window.Reflx;

  function boot() {
    Reflx.i18n.loadLocaleConfig().then(function () {
      Reflx.i18n.init();
      Reflx.nav.render("report");
      Reflx.i18n.applyToDocument();

      // Auth guard: redirect to login if no active session.
      if (!Reflx.session.isLoggedIn()) {
        location.href = "auth.html?returnTo=" + encodeURIComponent("report.html");
        return;
      }
    });
  }

  boot();

  document.addEventListener("reflx:localechange", function () {
    Reflx.i18n.applyToDocument();
  });
})();

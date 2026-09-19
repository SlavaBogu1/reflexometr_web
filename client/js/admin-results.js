/**
 * Reflexometr — Admin pending-results approval queue (CR-AUTH-02, implements D19).
 * Mirrors library.js's admin-gating pattern (`Reflx.session.isAdmin()`, same
 * login-required/not-admin notice conventions) — the real 403/401 boundary is
 * still ServerTeam's to enforce; this gate just avoids a broken screen for a
 * non-admin.
 */
(function () {
  "use strict";
  var Reflx = window.Reflx;
  var t = Reflx.i18n.t;
  var api = Reflx.api;

  var state = { entries: [] };

  function showGate() {
    var loggedIn = Reflx.session.isLoggedIn();
    var admin = Reflx.session.isAdmin();
    document.getElementById("login-required-notice").style.display = (!loggedIn) ? "block" : "none";
    document.getElementById("not-admin-notice").style.display = (loggedIn && !admin) ? "block" : "none";
    document.getElementById("admin-area").style.display = admin ? "block" : "none";
    return admin;
  }

  function reportError(res) { alert(api.messageFor(res.code)); }

  function loadPending() {
    return api.listPendingResults("pending").then(function (res) {
      if (!res.ok) { reportError(res); return; }
      state.entries = res.data.entries || [];
      renderTable();
    });
  }

  function statusLabel(status) {
    return t("admin_results.status." + status);
  }

  function renderTable() {
    var tbody = document.getElementById("pending-results-tbody");
    tbody.innerHTML = "";
    var emptyNote = document.getElementById("pending-results-empty");
    emptyNote.style.display = state.entries.length ? "none" : "block";

    state.entries.forEach(function (entry) {
      var tr = Reflx.util.el("tr", {}, [
        Reflx.util.el("td", {}, [entry.r_test_slug]),
        Reflx.util.el("td", {}, ["v" + entry.r_test_version]),
        Reflx.util.el("td", {}, [Reflx.i18n.formatNumber(entry.primary_metric_ms, { maximumFractionDigits: 0 }) + " ms"]),
        Reflx.util.el("td", {}, [Reflx.i18n.formatDateTime(new Date(entry.submitted_at))]),
        Reflx.util.el("td", {}, [Reflx.util.el("span", { class: "chip" }, [statusLabel("pending")])]),
        Reflx.util.el("td", {}, [
          Reflx.util.el("button", { class: "btn", onclick: function () { setStatus(entry.id, "approved"); } }, [t("admin_results.approve")]),
          " ",
          Reflx.util.el("button", { class: "btn secondary", onclick: function () { setStatus(entry.id, "rejected"); } }, [t("admin_results.reject")])
        ])
      ]);
      tbody.appendChild(tr);
    });
  }

  function setStatus(id, approvalStatus) {
    api.patchResultStatus(id, approvalStatus).then(function (res) {
      if (!res.ok) { reportError(res); return; }
      loadPending();
    });
  }

  function boot() {
    Reflx.i18n.loadLocaleConfig().then(function () {
      Reflx.i18n.init();
      Reflx.nav.render("admin-results");
      Reflx.i18n.applyToDocument();
      if (showGate()) loadPending();
    });
    document.addEventListener("reflx:sessionchange", function () { if (showGate()) loadPending(); });
    document.addEventListener("reflx:localechange", function () {
      Reflx.i18n.applyToDocument();
      renderTable();
    });
  }

  boot();
})();

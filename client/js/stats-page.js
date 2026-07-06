/**
 * Reflexometr — Personal Stats dashboard (CR-STATS-01).
 * Different versions of the "same" r-test always render as separate entries — never
 * combined into one number/chart (CR-STATS-01 acceptance criterion 1).
 *
 * CR-AUTH-01 follow-up: `GET /r-tests/{slug}/versions/{version}/history` requires
 * auth — the whole page is gated behind login.
 *
 * Contract gap (flagged in SPRINT1_REPORT.md): the public `GET /r-tests` only
 * returns each r-test's *current* version number, not a list of every version
 * number that ever existed — there is no non-admin endpoint to discover which
 * older version numbers a user might have results under. This page works around
 * that by probing version numbers `1..current_version` (versions are sequential
 * integers per CONTRACT.md's admin import behavior) and only rendering the ones
 * that come back with a non-empty history. A dedicated "my result versions"
 * endpoint would be a cleaner fix — recommended to ServerTeam/ProductOwner.
 */
(function () {
  "use strict";
  var Reflx = window.Reflx;
  var t = Reflx.i18n.t;
  var api = Reflx.api;

  function fmtMs(v) { return v === null || v === undefined ? "—" : Reflx.i18n.formatNumber(v, { maximumFractionDigits: 0 }) + " ms"; }

  function showGate() {
    var loggedIn = Reflx.session.isLoggedIn();
    document.getElementById("login-required-notice").style.display = loggedIn ? "none" : "block";
    document.getElementById("stats-content").style.display = loggedIn ? "block" : "none";
    return loggedIn;
  }

  function renderVersionCard(box, name, version, entries) {
    var card = Reflx.util.el("div", { class: "card" }, [
      Reflx.util.el("h3", {}, [name + " — " + t("stats.version_label", { v: version })]),
      Reflx.util.el("span", { class: "chip" }, [t("stats.runs_count", { n: entries.length })])
    ]);
    var list = Reflx.util.el("ul", { class: "trend-list" });
    var max = Math.max.apply(null, entries.map(function (e) { return Math.abs(e.primary_metric_ms || 0); })) || 1;
    entries.forEach(function (e) {
      var pct = Math.min(100, Math.round((Math.abs(e.primary_metric_ms || 0) / max) * 100));
      list.appendChild(Reflx.util.el("li", { class: "trend-row" }, [
        Reflx.util.el("span", { class: "trend-date" }, [Reflx.i18n.formatDateTime(new Date(e.created_at), { dateStyle: "short", timeStyle: "short" })]),
        Reflx.util.el("span", { class: "trend-bar-track" }, [Reflx.util.el("span", { class: "trend-bar-fill", style: "width:" + pct + "%" })]),
        Reflx.util.el("span", { class: "trend-value" }, [fmtMs(e.primary_metric_ms)])
      ]));
    });
    card.appendChild(list);
    box.appendChild(card);
  }

  function render() {
    if (!showGate()) return;
    var box = document.getElementById("stats-content");
    box.innerHTML = t("common.loading");

    api.listRTests().then(function (res) {
      if (!res.ok) { box.innerHTML = ""; box.appendChild(Reflx.util.el("p", { class: "notice danger" }, [api.messageFor(res.code)])); return; }
      var rtests = (res.data || []).filter(function (r) { return r.current_version; });

      var perTestVersionChecks = [];
      rtests.forEach(function (r) {
        for (var v = 1; v <= r.current_version; v++) {
          perTestVersionChecks.push(
            api.getHistory(r.slug, v).then(function (histRes) {
              return { r: r, version: v, ok: histRes.ok, entries: histRes.ok ? (histRes.data.entries || []) : [] };
            })
          );
        }
      });

      Promise.all(perTestVersionChecks).then(function (results) {
        box.innerHTML = "";
        var anyData = false;
        results.forEach(function (item) {
          if (!item.entries.length) return;
          anyData = true;
          var reg = Reflx.testRegistry[item.r.slug];
          var name = reg ? t(reg.prefix + ".name") : item.r.name;
          renderVersionCard(box, name, item.version, item.entries);
        });
        if (!anyData) box.appendChild(Reflx.util.el("p", { class: "notice info" }, [t("stats.empty")]));
      });
    });
  }

  Reflx.i18n.init();
  Reflx.nav.render("stats");
  Reflx.i18n.applyToDocument();
  render();
  document.addEventListener("reflx:localechange", function () { Reflx.i18n.applyToDocument(); render(); });
  document.addEventListener("reflx:sessionchange", render);
})();

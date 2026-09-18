/**
 * Reflexometr — footer build label (CR-INFRA-06, ClientTeam half).
 *
 * Replaces the old hand-typed, per-file "Sprint N build" footer string (which
 * drifted out of sync across the 6 footer-bearing pages — confirmed stale at
 * CR intake) with a single, auto-generated label sourced from deploy-time
 * metadata. ServerTeam's SI-8.3 writes `client/build-info.json` (short commit
 * SHA + deploy timestamp) as a deploy-workflow step, synced to the server like
 * any other static file in `client/` — this script is the sole consumer.
 *
 * Loaded alongside the other shared scripts on every footer-bearing page
 * (index/reflexes/medical-facts/certification/offerings/paths.html).
 */
(function (global) {
  "use strict";
  var Reflx = global.Reflx = global.Reflx || {};

  function applyLabel(text) {
    Reflx.util.qsa(".site-footer").forEach(function (el) {
      el.textContent = text;
    });
  }

  function render() {
    fetch("build-info.json", { headers: { "Accept": "application/json" } })
      .then(function (res) {
        if (!res.ok) throw new Error("HTTP " + res.status);
        return res.json();
      })
      .then(function (info) {
        if (!info || !info.sha || !info.date) throw new Error("malformed build-info.json");
        applyLabel("Reflexometr — build " + info.sha + " (" + info.date + ")");
      })
      .catch(function () {
        // Missing file (e.g. local dev with no CI-generated artifact), non-2xx,
        // or malformed body: degrade to a plain generic label, no visible
        // console error to alarm a casual visitor.
        applyLabel("Reflexometr");
      });
  }

  Reflx.buildInfo = { render: render };
  // Non-critical, below-the-fold footer credit — defer the fetch off the
  // critical page-load path rather than firing it eagerly at script-load time.
  if (document.readyState === "complete") {
    render();
  } else {
    window.addEventListener("load", render);
  }
})(window);

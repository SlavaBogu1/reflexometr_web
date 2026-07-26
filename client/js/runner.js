/**
 * Reflexometr — Generic r-test runner shell (CR-TEST-02, CR-TEST-06) driving the
 * concrete r-test content modules (CR-TEST-03, CR-TEST-04) and the post-run
 * anonymized comparison view (CR-STATS-02).
 *
 * Page 1 (Description) is shown when arriving via `?entry=library` (i.e. clicked
 * from browse.html) or with no `entry` param at all (a direct/bookmarked link is
 * treated the same as a fresh library entry); "Take again" and series auto-repeat
 * are in-page state transitions straight to Page 2, per CR-TEST-02 acceptance
 * criterion 5 — they never re-navigate through `?entry=library`.
 *
 * CR-AUTH-01 follow-up: every endpoint this page calls requires auth
 * (`_API_CONTRACT/CONTRACT.md` § run-token issuance/submission and § Stats), so the
 * whole screen is gated behind a login check at boot, before anything renders.
 */
(function () {
  "use strict";
  var Reflx = window.Reflx;
  var t = Reflx.i18n.t;
  var api = Reflx.api;

  var params = new URLSearchParams(location.search);
  var slug = params.get("slug");
  var meta = Reflx.testRegistry[slug];

  var runState = {
    seriesMode: false,
    seriesKind: null, // "count" | "until-quit"
    seriesTarget: null,
    seriesId: null,
    completedRuns: [], // { resultId, rTestId, versionId, kind, primaryMetricMs, summary, trialCount, falseStarts }
    activeTestHandle: null,
    falseStartsThisRun: 0,
    currentToken: null
  };

  function showPage(id) {
    Reflx.util.qsa(".page").forEach(function (p) { p.classList.remove("active"); });
    document.getElementById(id).classList.add("active");
  }

  // ------------------------------------------------------------ Page 1: Description

  /**
   * CR-UI-08 (Sprint 3 design prototype, folded into the live page per the
   * approved mockup): placeholder "why this matters" blurbs, one per r-test
   * slug. Short, generic, illustrative copy only — adapted paraphrase, never
   * verbatim text from the private research KB — until CR-TEST-17 authors the
   * real per-method content and i18n keys. Not run through Reflx.i18n on
   * purpose (see CR-UI-08's "i18n: none" scope).
   */
  var WHY_IT_MATTERS_PLACEHOLDER = {
    "simple-reaction": "Simple visual reaction time is one of the most-studied measures in reaction research — typical adult values fall in a fairly narrow band, making it a useful, low-effort baseline to track over time.",
    "two-hand-reaction": "Comparing your two hands' reaction speed can surface asymmetries that a single-hand test can't — useful context alongside your dominant-hand setting when interpreting day-to-day variation."
  };
  function renderWhyItMatters() {
    var el = document.getElementById("desc-why-it-matters");
    if (!el) return;
    el.textContent = WHY_IT_MATTERS_PLACEHOLDER[slug] ||
      "Reaction-time measures are simple, objective, and repeatable — tracked over time, they can surface changes worth paying attention to.";
  }

  function renderDescription() {
    document.getElementById("desc-name").textContent = t(meta.prefix + ".name");
    document.getElementById("desc-description").textContent = t(meta.prefix + ".description");
    var ul = document.getElementById("desc-expected");
    ul.innerHTML = "";
    [1, 2, 3].forEach(function (i) {
      var li = document.createElement("li");
      li.textContent = t(meta.prefix + ".expected" + i);
      ul.appendChild(li);
    });
    document.getElementById("desc-guidance").textContent = t(meta.prefix + ".guidance");
    renderWhyItMatters();

    var needsDominant = slug === "two-hand-reaction" && !Reflx.settings.get().dominantHand;
    document.getElementById("dominant-hand-prompt").style.display = needsDominant ? "block" : "none";

    showPage("page-description");
  }

  /** Bound once at boot (unlike renderDescription, which can re-run on a locale change). */
  function wireModeSegmented() {
    var modeSeg = document.getElementById("mode-segmented");
    var seriesOptions = document.getElementById("series-options");
    Reflx.util.qsa("button", modeSeg).forEach(function (btn) {
      btn.addEventListener("click", function () {
        Reflx.util.qsa("button", modeSeg).forEach(function (b) { b.classList.remove("active"); });
        btn.classList.add("active");
        var isSeries = btn.dataset.mode === "series";
        seriesOptions.style.display = isSeries ? "flex" : "none";
        document.getElementById("btn-start").textContent = t(isSeries ? "runner.description.start_series" : "runner.description.start");
      });
    });
  }

  function wireStart() {
    document.getElementById("btn-start").addEventListener("click", function () {
      if (slug === "two-hand-reaction" && !Reflx.settings.get().dominantHand) {
        var sel = document.getElementById("dominant-hand-inline-select");
        if (!sel.value) { alert(t("test.twohand.dominant_prompt")); return; }
        Reflx.settings.set({ dominantHand: sel.value });
        if (Reflx.session.isLoggedIn()) {
          api.patchProfile({ dominant_hand: sel.value }).then(function (res) {
            if (res.ok) Reflx.session.updateUser({ dominant_hand: res.data.dominant_hand });
          });
        }
      }

      var activeModeBtn = document.querySelector("#mode-segmented button.active");
      runState.seriesMode = activeModeBtn.dataset.mode === "series";
      runState.seriesId = runState.seriesMode ? Reflx.util.uid("series") : null;
      if (runState.seriesMode) {
        var kind = document.querySelector('input[name="series-kind"]:checked').value;
        runState.seriesKind = kind;
        runState.seriesTarget = kind === "count" ? parseInt(document.getElementById("series-count").value, 10) : null;
      }
      runState.completedRuns = [];
      beginRun();
    });
  }

  // ------------------------------------------------------------ Page 2: Test

  /** Builds the POST /r-tests/{slug}/runs body's `mode` field per CR-TEST-06. */
  function currentModeParam() {
    if (!runState.seriesMode) return "single";
    return runState.seriesKind === "count" ? "count:" + runState.seriesTarget : "until-quit";
  }

  function beginRun() {
    var opts = { mode: currentModeParam() };
    if (runState.seriesId) opts.series_id = runState.seriesId;

    api.startRun(slug, opts).then(function (res) {
      if (!res.ok) { alert(api.messageFor(res.code)); return; }
      var run = res.data;
      runState.falseStartsThisRun = 0;
      runState.currentToken = run.token;
      runState.currentRTestId = run.r_test_id;
      runState.currentVersionId = run.r_test_version_id;
      runState.currentRunVersion = run.version;

      showPage("page-test");
      document.getElementById("trial-progress").textContent = t("runner.test.trial_of", { n: 0, total: run.schedule.trial_count });
      document.getElementById("stage-status").textContent = "";

      var container = document.getElementById("test-content");
      var testModule = Reflx.tests[slug];
      runState.activeTestHandle = testModule.start(container, {
        schedule: run.schedule,
        settings: Reflx.settings.get()
      }, {
        onProgress: function (done, total) {
          document.getElementById("trial-progress").textContent = t("runner.test.trial_of", { n: Math.min(done + 1, total), total: total });
        },
        onFalseStart: function () { runState.falseStartsThisRun++; },
        onDone: function (trials) { finishRun(trials); }
      });
    });
  }

  function wireQuit() {
    document.getElementById("btn-quit").addEventListener("click", function () {
      if (!confirm(t("runner.test.quit_confirm"))) return;
      if (runState.activeTestHandle) runState.activeTestHandle.quit();
      // No partial-run submission (CR-TEST-06): quitting mid-run simply never calls submit.
      if (runState.completedRuns.length > 0) {
        renderResult({ finalized: true });
      } else {
        location.href = "browse.html";
      }
    });
  }

  function finishRun(trials) {
    var kind = slug === "two-hand-reaction" ? "two-hand" : "simple";
    var payload = { trials: trials };
    if (kind === "two-hand" && Reflx.settings.get().dominantHand) {
      payload.dominant_hand = Reflx.settings.get().dominantHand;
    }
    api.submitRun(runState.currentToken, payload).then(function (res) {
      if (!res.ok) { alert(api.messageFor(res.code)); return; }
      var d = res.data;
      runState.completedRuns.push({
        resultId: d.result_id, rTestId: d.r_test_id, versionId: d.r_test_version_id,
        kind: kind, primaryMetricMs: d.primary_metric_ms, summary: d.summary || {},
        trials: trials, falseStarts: runState.falseStartsThisRun
      });

      var reachedTarget = runState.seriesMode && runState.seriesKind === "count" && runState.completedRuns.length >= runState.seriesTarget;
      var isFinal = !runState.seriesMode || reachedTarget;
      renderResult({ finalized: isFinal });
    });
  }

  /** Local-only best/worst/mean over the raw trial log, for display (independent of whatever the server's summary shape turns out to contain). */
  function channelDeltas(trials, channel) {
    return trials
      .map(function (tr) { return isNum(tr.responses[channel]) ? tr.responses[channel] - tr.stimulus_at : null; })
      .filter(isNum);
  }
  function localTrialStats(kind, trials) {
    if (kind === "two-hand") {
      return { leftMean: avg(channelDeltas(trials, "left")), rightMean: avg(channelDeltas(trials, "right")) };
    }
    var values = channelDeltas(trials, "primary");
    return {
      mean: avg(values),
      best: values.length ? Math.min.apply(null, values) : null,
      worst: values.length ? Math.max.apply(null, values) : null
    };
  }
  function isNum(v) { return typeof v === "number"; }
  function avg(arr) { return arr.length ? arr.reduce(function (a, b) { return a + b; }, 0) / arr.length : null; }

  // ------------------------------------------------------------ Page 3: Result

  function renderResult(opts) {
    var last = runState.completedRuns[runState.completedRuns.length - 1];
    showPage("page-result");

    // Series running tally (CR-TEST-06)
    var tallyCard = document.getElementById("series-tally-card");
    if (runState.seriesMode && runState.completedRuns.length) {
      tallyCard.style.display = "block";
      var tallyTitle = tallyCard.querySelector("h3");
      tallyTitle.textContent = opts.finalized ? t("runner.series.complete") : t("runner.series.tally_title");
      var tallyEl = document.getElementById("series-tally");
      tallyEl.innerHTML = "";
      runState.completedRuns.forEach(function (r, i) {
        var valueLabel = typeof r.primaryMetricMs === "number" ? fmtMs(r.primaryMetricMs) : "—";
        var li = document.createElement("li");
        li.innerHTML = "<span>" + t("runner.series.run_label", { n: i + 1 }) + "</span><span>" + valueLabel + "</span>";
        tallyEl.appendChild(li);
      });
    } else {
      tallyCard.style.display = "none";
    }

    renderSummary(last);
    renderCompareAndTrend(last);
    renderResultActions(opts.finalized);
  }

  function renderSummary(last) {
    var box = document.getElementById("result-summary");
    box.innerHTML = "";
    function row(label, value) {
      box.appendChild(Reflx.util.el("div", { class: "field-row" }, [
        Reflx.util.el("label", {}, [label]), Reflx.util.el("span", {}, [value])
      ]));
    }
    var local = localTrialStats(last.kind, last.trials);
    if (last.kind === "two-hand") {
      row(t("test.twohand.result.left_mean"), fmtMs(local.leftMean));
      row(t("test.twohand.result.right_mean"), fmtMs(local.rightMean));
      var delta = last.summary && typeof last.summary.dominant_minus_nondominant_ms === "number" ? last.summary.dominant_minus_nondominant_ms : null;
      row(t("test.twohand.result.delta_mean"), delta !== null ? fmtMs(delta) : "—");
      row(t("test.twohand.result.dominant"), Reflx.settings.get().dominantHand || t("settings.dominant.unset"));
    } else {
      row(t("runner.result.mean"), fmtMs(typeof last.primaryMetricMs === "number" ? last.primaryMetricMs : local.mean));
      row(t("runner.result.best"), fmtMs(local.best));
      row(t("runner.result.worst"), fmtMs(local.worst));
    }
    row(t("runner.result.trials_recorded"), String(last.trials.length));
    row(t("runner.result.false_starts"), String(last.falseStarts));
  }
  function fmtMs(v) { return v === null || v === undefined || isNaN(v) ? "—" : Reflx.i18n.formatNumber(v, { maximumFractionDigits: 0 }) + " ms"; }

  function renderCompareAndTrend(last) {
    var compareBox = document.getElementById("compare-content");
    compareBox.innerHTML = t("common.loading");
    api.getComparison(last.resultId).then(function (res) {
      compareBox.innerHTML = "";
      if (!res.ok) { compareBox.textContent = api.messageFor(res.code); return; }
      var d = res.data;
      if (d.percentile === null || d.percentile === undefined) { compareBox.textContent = t("compare.no_data"); return; }
      compareBox.appendChild(Reflx.util.el("p", {}, [t("compare.percentile", { pct: Math.round(d.percentile) })]));
      var track = Reflx.util.el("div", { class: "percentile-bar-track" }, [
        Reflx.util.el("div", { class: "percentile-bar-fill", style: "width:" + Math.round(d.percentile) + "%" })
      ]);
      compareBox.appendChild(track);
      compareBox.appendChild(Reflx.util.el("p", { class: "field-desc" }, [t("compare.sample_note")]));
    });

    var trendBox = document.getElementById("trend-content");
    trendBox.innerHTML = t("common.loading");
    // GET history takes slug + the r-test's version *number* (not the version's row id,
    // which is what submit's r_test_version_id is) — captured from the run-start response.
    api.getHistory(slug, runState.currentRunVersion).then(function (res) {
      trendBox.innerHTML = "";
      if (!res.ok) { trendBox.textContent = api.messageFor(res.code); return; }
      var entries = res.data.entries || [];
      if (entries.length <= 1) { trendBox.textContent = t("compare.trend.none"); return; }
      var values = entries.map(function (e) { return e.primary_metric_ms; }).filter(isNum);
      var max = Math.max.apply(null, values.map(Math.abs)) || 1;
      var list = Reflx.util.el("ul", { class: "trend-list" });
      entries.slice(-8).forEach(function (e) {
        var pct = Math.min(100, Math.round((Math.abs(e.primary_metric_ms) / max) * 100));
        list.appendChild(Reflx.util.el("li", { class: "trend-row" }, [
          Reflx.util.el("span", { class: "trend-date" }, [Reflx.i18n.formatDateTime(new Date(e.created_at), { dateStyle: "short", timeStyle: "short" })]),
          Reflx.util.el("span", { class: "trend-bar-track" }, [Reflx.util.el("span", { class: "trend-bar-fill", style: "width:" + pct + "%" })]),
          Reflx.util.el("span", { class: "trend-value" }, [fmtMs(e.primary_metric_ms)])
        ]));
      });
      trendBox.appendChild(list);
    });
  }

  function renderResultActions(finalized) {
    var box = document.getElementById("result-actions");
    box.innerHTML = "";
    if (!finalized) {
      box.appendChild(Reflx.util.el("button", { class: "btn", onclick: beginRun }, [t("runner.series.next_run")]));
      box.appendChild(Reflx.util.el("button", { class: "btn secondary", onclick: function () { renderResult({ finalized: true }); } }, [t("runner.series.quit_series")]));
    } else {
      box.appendChild(Reflx.util.el("button", {
        class: "btn", onclick: function () {
          // "Take again" always starts a single fresh run, even if the just-finished
          // run was part of a series — matches the singular action label.
          runState.completedRuns = [];
          runState.seriesMode = false;
          beginRun();
        }
      }, [t("runner.result.take_again")]));
      box.appendChild(Reflx.util.el("a", { class: "btn secondary", href: "browse.html" }, [t("runner.result.back_to_library")]));
    }
  }

  // ------------------------------------------------------------ boot

  function boot() {
    Reflx.i18n.init();
    Reflx.nav.render("browse");
    Reflx.i18n.applyToDocument();

    if (!meta) {
      document.getElementById("not-found").style.display = "block";
      return;
    }
    if (!Reflx.session.isLoggedIn()) {
      location.href = "auth.html?returnTo=" + encodeURIComponent("runner.html" + location.search);
      return;
    }

    wireStart();
    wireQuit();
    wireModeSegmented();
    renderDescription();

    document.addEventListener("reflx:localechange", function () {
      Reflx.i18n.applyToDocument();
      if (document.getElementById("page-description").classList.contains("active")) renderDescription();
    });
  }

  boot();
})();

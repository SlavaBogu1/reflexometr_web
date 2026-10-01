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
    currentToken: null,
    countdownTimer: null // CR-UI-16: pre-start countdown's setInterval handle, active only during the countdown itself
  };

  function showPage(id) {
    Reflx.util.qsa(".page").forEach(function (p) { p.classList.remove("active"); });
    document.getElementById(id).classList.add("active");
  }

  /**
   * CR-UI-26: non-blocking in-page error presentation, replacing the old
   * `alert(api.messageFor(res.code))` calls in beginRun()/finishRun(). Uses the
   * shared `Reflx.util.showBanner`/`hideBanner` helper (reuses the existing
   * `.notice.danger` convention — auth.html #auth-error, admin-results.html/
   * library.html's login/not-admin notices, browse.html's inline fetch-error <p>,
   * this page's own #not-found — rather than a page-local one-off) against
   * `#run-error`, which sits outside the `.page` sections in runner.html so it's
   * visible regardless of which page (1/2/3) is currently active. Does not block
   * the render loop or input handling in any way.
   */
  function showRunError(message) { Reflx.util.showBanner("run-error", message); }
  function hideRunError() { Reflx.util.hideBanner("run-error"); }

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
    "two-hand-reaction": "Comparing your two hands' reaction speed can surface asymmetries that a single-hand test can't — useful context alongside your dominant-hand setting when interpreting day-to-day variation.",
    "circle-collision-simple": "Coincidence-anticipation timing (predicting when two moving objects will meet) draws on different perceptual-motor skill than a simple color-change reaction — relevant to sports and driving-adjacent research.",
    "circle-collision-complex": "Adding variable speed and size makes this a tougher anticipation-timing challenge than the Simple variant — useful for tracking how well you adapt your prediction to changing conditions.",
    "random-target-pointing": "Separating visual reaction from physical pointing movement reveals whether slowdowns come from perceiving the stimulus or from executing the movement — a useful distinction for tracking fine motor coordination alongside raw detection speed.",
    "choice-reaction-geometry": "Adding a discrimination and selection stage to the reaction task more closely models real-world decision-making speed than pure detection — the gap between your correct-response RT and simple RT reflects the cognitive cost of the choice.",
    "peripheral-reaction": "The ability to respond quickly to stimuli outside the focus of attention is a distinct skill from central-focus reaction — relevant wherever broad-field awareness matters, from driving to sports.",
    "temporal-prediction": "Predicting when a moving object will arrive at a known point requires internal time-estimation rather than simple reaction — a skill that varies independently from detection-based RT and can be tracked and improved over time.",
    "visual-conflict": "When color and shape give conflicting cues — one pointing left, the other right — the brain must suppress the more automatic (color) response and apply the learned rule (shape). The conflict cost, the RT gap between conflict and neutral trials, reflects the cognitive load of that suppression."
  };
  function renderWhyItMatters() {
    var el = document.getElementById("desc-why-it-matters");
    if (!el) return;
    el.textContent = WHY_IT_MATTERS_PLACEHOLDER[slug] ||
      "Reaction-time measures are simple, objective, and repeatable — tracked over time, they can surface changes worth paying attention to.";
  }
  // CR-UI-24 merged the old "Interpreting your results" + "Why this matters" cards into one
  // "Test results analysis" card; renderWhyItMatters() (above) still fills its second half.

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

  /**
   * CR-UI-24: replaces the old Single/Series segmented control with a single
   * "Number of runs" number input (default 1) + "Run until I quit" round-pill
   * toggle, mutually exclusive — checking the toggle disables the number input
   * (and vice versa isn't needed since the toggle always wins per the mockup's
   * behavior notes). Bound once at boot (unlike renderDescription, which can
   * re-run on a locale change).
   */
  function wireModeSegmented() {
    var runsInput = document.getElementById("runs-count");
    var untilQuit = document.getElementById("until-quit");
    var untilQuitToggle = document.getElementById("until-quit-toggle");

    function syncToggleState() {
      var checked = untilQuit.checked;
      runsInput.disabled = checked;
      untilQuitToggle.classList.toggle("active", checked);
    }
    untilQuit.addEventListener("change", syncToggleState);
    syncToggleState();
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

      // CR-UI-24: runs>1 OR "Run until I quit" checked both count as series mode;
      // runs=1 with the toggle unchecked behaves exactly like today's Single test.
      var untilQuit = document.getElementById("until-quit").checked;
      var runsCount = parseInt(document.getElementById("runs-count").value, 10) || 1;
      runState.seriesMode = untilQuit || runsCount > 1;
      runState.seriesId = runState.seriesMode ? Reflx.util.uid("series") : null;
      if (runState.seriesMode) {
        runState.seriesKind = untilQuit ? "until-quit" : "count";
        runState.seriesTarget = untilQuit ? null : runsCount;
      }
      runState.completedRuns = [];
      beginRun();
    });
  }

  // ------------------------------------------------------------ Page 2: Test — CR-UI-15 header

  /**
   * CR-UI-15: short "what triggers a response" copy per r-test, shown in the Test
   * page's persistent header alongside the Objective. CR-UI-22 follow-up: the
   * Objective bullet originally reused `{prefix}.description` wholesale; it now
   * uses its own condensed `{prefix}.header_objective` key instead (see
   * renderTestHeader() below) so the header can be shortened without also
   * changing the Description page's fuller original wording.
   */
  /** Builds the Input-devices bullet's channel list from the user's actual current bindings (Reflx.settings.get()) — never hardcoded. */
  function deviceHintFor(testSlug, settings) {
    var hint = Reflx.testRegistry[testSlug].deviceHint(settings);
    return t(hint.key, hint.params);
  }

  /**
   * CR-UI-22: the Stimulus bullet's locale string carries `{green}`/`{red}`
   * placeholder tokens (not literal English words) so this works correctly
   * regardless of a locale's own word order/inflection — each token is
   * replaced with a small color-swatch (matching the real orb's
   * `--stimulus-green`/`--stimulus-red` custom properties, so it can never
   * drift from the actual on-screen stimulus color) immediately followed by
   * that locale's own translated color word. The whole string is always a
   * trusted i18n locale-file value, never user input, so building DOM nodes
   * from it here introduces no XSS surface — same trust boundary the rest of
   * the app's i18n-driven content already relies on.
   */
  var STIMULUS_SWATCH = {
    green: { color: "var(--stimulus-green)", word: function () { return t("test.color.green"); } },
    red: { color: "var(--stimulus-red)", word: function () { return t("test.color.red"); } }
  };

  function renderStimulusBullet(key) {
    var el = document.getElementById("test-header-stimulus");
    el.innerHTML = "";
    // Splitting on the token regex yields alternating [text, token, text, token, ..., text] —
    // odd indices are always the captured token name (green/red), so no manual match/lastIndex
    // bookkeeping is needed.
    var parts = t(key).split(/\{(green|red)\}/);
    parts.forEach(function (part, i) {
      if (i % 2 === 0) {
        if (part) el.appendChild(document.createTextNode(part));
        return;
      }
      var swatch = STIMULUS_SWATCH[part];
      el.appendChild(Reflx.util.el("span", { class: "color-swatch", style: "background:" + swatch.color + ";" }));
      el.appendChild(document.createTextNode(swatch.word()));
    });
  }

  function renderTestHeader(settings) {
    if (!meta) return;
    // CR-UI-22: the header's Objective now uses its own condensed key (kept
    // separate from `{prefix}.description`, which the Description page still
    // uses verbatim) so shortening the header copy doesn't also change the
    // Description page's fuller wording.
    document.getElementById("test-header-objective").textContent = t(meta.prefix + ".header_objective");
    renderStimulusBullet(meta.headerStimulusKey);
    document.getElementById("test-header-devices").textContent = deviceHintFor(slug, settings || Reflx.settings.get());
  }

  // ------------------------------------------------------------ Page 2: Test

  /** Builds the POST /r-tests/{slug}/runs body's `mode` field per CR-TEST-06. */
  function currentModeParam() {
    if (!runState.seriesMode) return "single";
    return runState.seriesKind === "count" ? "count:" + runState.seriesTarget : "until-quit";
  }

  /**
   * CR-UI-16: visible pre-start "get ready" countdown, shown once per test start
   * (initial entry via wireStart(), and every "Take again"/series-continuation
   * call to beginRun() below — this is the single call site both paths share, so
   * inserting it here covers CR-UI-16 acceptance criterion 4 with no separate
   * wiring needed at the series/"Take again" buttons themselves).
   *
   * Purely a Page-2 transient visual state — does not arm any per-trial timing
   * (stimulus_at, armTimer live entirely inside the test module's start(), only
   * invoked once the countdown's onDone fires below).
   */
  function runCountdown(seconds, onDone) {
    if (!seconds) { onDone(); return; } // 0 = disabled, current immediate-start behavior unchanged
    var container = document.getElementById("test-content");
    container.innerHTML = "";
    var numberEl = Reflx.util.el("div", { class: "countdown-number" }, [String(seconds)]);
    container.appendChild(Reflx.util.el("div", { class: "countdown-stage" }, [
      Reflx.util.el("p", { class: "countdown-label" }, [t("runner.test.countdown_label")]),
      numberEl
    ]));
    document.getElementById("stage-status").textContent = "";

    var remaining = seconds;
    runState.countdownTimer = setInterval(function () {
      remaining--;
      if (remaining <= 0) {
        clearInterval(runState.countdownTimer);
        runState.countdownTimer = null;
        onDone();
        return;
      }
      numberEl.textContent = String(remaining);
    }, 1000);
  }

  function beginRun() {
    var opts = { mode: currentModeParam() };
    if (runState.seriesId) opts.series_id = runState.seriesId;

    hideRunError();
    api.startRun(slug, opts).then(function (res) {
      if (!res.ok) { showRunError(api.messageFor(res.code)); return; }
      var run = res.data;
      runState.falseStartsThisRun = 0;
      runState.currentToken = run.token;
      runState.currentRTestId = run.r_test_id;
      runState.currentVersionId = run.r_test_version_id;
      runState.currentRunVersion = run.version;

      var settings = Reflx.settings.get();
      showPage("page-test");
      renderTestHeader(settings);
      document.getElementById("stage-status").textContent = "";

      runCountdown(settings.countdownSeconds, function () {
        var container = document.getElementById("test-content");
        var testModule = Reflx.tests[slug];
        runState.activeTestHandle = testModule.start(container, {
          schedule: run.schedule,
          settings: settings
        }, {
          // CR-UI-21: #trial-progress now lives inside the test module's own
          // stage markup (built fresh by start(), above) rather than static
          // runner.html markup — look it up fresh each call rather than
          // capturing a stale reference from before the module built its DOM.
          onProgress: function (done, total) {
            var el = document.getElementById("trial-progress");
            if (el) el.textContent = t("runner.test.trial_of", { n: Math.min(done + 1, total), total: total });
          },
          onFalseStart: function () { runState.falseStartsThisRun++; },
          onDone: function (trials) { finishRun(trials); }
        });
      });
    });
  }

  function wireQuit() {
    document.getElementById("btn-quit").addEventListener("click", function () {
      if (!confirm(t("runner.test.quit_confirm"))) return;
      // CR-UI-16: quitting during the pre-start countdown (before the test module
      // has even started, so activeTestHandle may still be a stale prior handle
      // or null) must also stop the countdown's own timer.
      if (runState.countdownTimer) { clearInterval(runState.countdownTimer); runState.countdownTimer = null; }
      if (runState.activeTestHandle) runState.activeTestHandle.quit();
      // No partial-run submission (CR-TEST-06): quitting mid-run simply never calls submit.
      if (runState.completedRuns.length > 0) {
        renderResult({ finalized: true });
      } else {
        location.href = "browse.html";
      }
    });
  }

  /** Sprint 14/15: map test slugs to their own kind names for result rendering. */
  function kindForSlug(s) {
    if (s === "two-hand-reaction") return "two-hand";
    if (s === "random-target-pointing") return "random-target";
    if (s === "choice-reaction-geometry") return "choice-geometry";
    if (s === "peripheral-reaction") return "peripheral";
    if (s === "temporal-prediction") return "temporal";
    if (s === "visual-conflict") return "visual-conflict";
    return "simple";
  }

  function finishRun(trials) {
    var kind = kindForSlug(slug);
    var payload = { trials: trials };
    if (kind === "two-hand" && Reflx.settings.get().dominantHand) {
      payload.dominant_hand = Reflx.settings.get().dominantHand;
    }
    hideRunError();
    api.submitRun(runState.currentToken, payload).then(function (res) {
      if (!res.ok) { showRunError(api.messageFor(res.code)); return; }
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
        // CR-TEST-28 (v1.8): Circle Collision's primaryMetricMs is an abs-value px
        // distance, not ms — see fmtPrimaryMetric() above.
        var valueLabel = typeof r.primaryMetricMs === "number" ? fmtPrimaryMetric(slug, r.primaryMetricMs) : "—";
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

  /**
   * CR-STATS-08: formats a variability figure from the server's authoritative
   * `sd_ms` (CONTRACT.md v1.5 `summary.overall`/`summary.channels.{channel}`) —
   * never a client recomputation. `null`/missing (fewer than 2 valid readings)
   * renders as "—", matching this page's other not-yet-available figures.
   */
  function fmtVariability(sdMs) { return typeof sdMs === "number" ? fmtMs(sdMs) : "—"; }

  // CR-TEST-28 (v1.8): Circle Collision's `primary_metric_ms`/`summary.overall.mean_ms`
  // (and each `summary.channels.{channel}.mean_ms`) are still `_ms`-suffixed field
  // names (ServerTeam kept the generic name project-wide, CONTRACT.md v1.8) but their
  // VALUE is now an absolute-value px-distance accuracy figure for this test family —
  // never literally milliseconds. `summary.overall.min`/`.max` (unsuffixed — the one
  // exception, per CONTRACT.md) are the genuinely signed extremes (most-early/
  // most-late click, in px). Detected purely off `slug`, same pattern as
  // `currentModeParam()`/the dominant-hand gate above — no schedule-level flag is
  // read client-side (the contract's `abs_value_aggregation` key is informational/
  // server-only per CONTRACT.md v1.8, not meant to be consumed here).
  function isCollision(testSlug) { return testSlug === "circle-collision-simple" || testSlug === "circle-collision-complex"; }

  /** Shared "—"-fallback + locale-aware number formatter (`/simplify` pass, Sprint 13
   * — fmtMs/fmtPx previously duplicated this guard independently). */
  function fmtNumber(v, unit) { return v === null || v === undefined || isNaN(v) ? "—" : Reflx.i18n.formatNumber(v, { maximumFractionDigits: 0 }) + " " + unit; }
  function fmtPx(v) { return fmtNumber(v, "px"); }

  /** Formats `v` (a `primary_metric_ms`-shaped value) in whichever unit `slug` actually
   * means — px-distance for Circle Collision, ms for every other test type. Single
   * call site for the `isCollision(slug) ? fmtPx : fmtMs` dispatch that was previously
   * repeated at each of this page's three render sites (`/simplify` pass, Sprint 13). */
  function fmtPrimaryMetric(testSlug, v) { return isCollision(testSlug) ? fmtPx(v) : fmtMs(v); }

  // ---- Sprint 14: local KPI helpers for the four new test types ----

  /** Extract a numeric field from trial_data across all trials, filtering nulls. */
  function trialDataValues(trials, field) {
    var out = [];
    trials.forEach(function (tr) {
      if (tr.trial_data && isNum(tr.trial_data[field])) out.push(tr.trial_data[field]);
    });
    return out;
  }

  /** Count trials where trial_data.result matches a given value. */
  function countResult(trials, resultLabel) {
    var n = 0;
    trials.forEach(function (tr) {
      if (tr.trial_data && tr.trial_data.result === resultLabel) n++;
    });
    return n;
  }

  /** Per-shape RT breakdown for choice-reaction-geometry.
   *  Returns { shapeName: [rt, ...], ... } for correct trials only. */
  function perShapeRTs(trials) {
    var map = {};
    trials.forEach(function (tr) {
      if (!tr.trial_data) return;
      if (tr.trial_data.result !== "TRUE_RESPONSE") return;
      if (!isNum(tr.trial_data.reaction_time_ms)) return;
      var s = tr.trial_data.stimulus_shape || "unknown";
      if (!map[s]) map[s] = [];
      map[s].push(tr.trial_data.reaction_time_ms);
    });
    return map;
  }

  /** Per-position RT breakdown for peripheral-reaction.
   *  Returns [{ label, mean, count }, ...] for positions with ≥ 3 valid trials. */
  function perPositionRTs(trials) {
    var map = {};
    trials.forEach(function (tr) {
      if (!tr.trial_data) return;
      if (tr.trial_data.result !== "TRUE_RESPONSE") return;
      if (!isNum(tr.trial_data.reaction_time_ms)) return;
      var lbl = tr.trial_data.stimulus_position_label || "?";
      if (!map[lbl]) map[lbl] = [];
      map[lbl].push(tr.trial_data.reaction_time_ms);
    });
    var out = [];
    Object.keys(map).forEach(function (lbl) {
      if (map[lbl].length >= 3) {
        out.push({ label: lbl, mean: avg(map[lbl]), count: map[lbl].length });
      }
    });
    return out;
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
    var summary = last.summary || {};

    if (last.kind === "two-hand") {
      var channels = summary.channels || {};
      row(t("test.twohand.result.left_mean"), fmtMs(local.leftMean));
      row(t("test.twohand.result.left_variability"), fmtVariability(channels.left && channels.left.sd_ms));
      row(t("test.twohand.result.right_mean"), fmtMs(local.rightMean));
      row(t("test.twohand.result.right_variability"), fmtVariability(channels.right && channels.right.sd_ms));
      var delta = typeof summary.dominant_minus_nondominant_ms === "number" ? summary.dominant_minus_nondominant_ms : null;
      row(t("test.twohand.result.delta_mean"), delta !== null ? fmtMs(delta) : "—");
      row(t("test.twohand.result.dominant"), Reflx.settings.get().dominantHand || t("settings.dominant.unset"));

    } else if (isCollision(slug)) {
      var overall = summary.overall || {};
      var avgDistance = typeof last.primaryMetricMs === "number" ? last.primaryMetricMs : overall.mean_ms;
      row(t("runner.result.collision_avg_distance"), fmtPx(avgDistance));
      row(t("runner.result.collision_earliest"), fmtPx(typeof overall.min === "number" ? overall.min : null));
      row(t("runner.result.collision_latest"), fmtPx(typeof overall.max === "number" ? overall.max : null));
      row(t("runner.result.variability"), fmtVariability(overall.sd_ms));

    } else if (last.kind === "random-target") {
      // CR-TEST-30 result display (CI-14.2) — single pass over trials
      var rtVals = [], mvVals = [], errVals = [], pathVals = [], effVals = [];
      last.trials.forEach(function (tr) {
        var d = tr.trial_data;
        if (!d) return;
        if (isNum(d.reaction_time_ms))  rtVals.push(d.reaction_time_ms);
        if (isNum(d.movement_time_ms))  mvVals.push(d.movement_time_ms);
        if (isNum(d.click_error_px))    errVals.push(d.click_error_px);
        if (isNum(d.path_length_px))    pathVals.push(d.path_length_px);
        if (isNum(d.path_efficiency))   effVals.push(d.path_efficiency);
      });
      row(t("test.random_target.result.reaction_time_mean"), fmtMs(avg(rtVals)));
      row(t("test.random_target.result.movement_time_mean"), fmtMs(avg(mvVals)));
      row(t("test.random_target.result.click_error_mean"), fmtPx(avg(errVals)));
      if (errVals.length) {
        row(t("test.random_target.result.click_error_best"), fmtPx(Math.min.apply(null, errVals)));
        row(t("test.random_target.result.click_error_worst"), fmtPx(Math.max.apply(null, errVals)));
      }
      if (pathVals.length) {
        row(t("test.random_target.result.path_length_mean"), fmtPx(avg(pathVals)));
        if (effVals.length) {
          row(t("test.random_target.result.path_efficiency"), Math.round(avg(effVals) * 100) + "%");
        }
      }

    } else if (last.kind === "choice-geometry") {
      // CR-TEST-32 result display (CI-14.5)
      var correctRTs = trialDataValues(last.trials, "reaction_time_ms");
      // Only correct trials have a non-null reaction_time_ms in trial_data
      row(t("test.choice_geometry.result.reaction_time_mean"), fmtMs(avg(correctRTs)));
      var totalTrials = last.trials.length;
      var wrongCount = countResult(last.trials, "FALSE_RESPONSE");
      var missedCount = countResult(last.trials, "MISSED_STIMULUS");
      var wrongRate = totalTrials > 0 ? Math.round((wrongCount / totalTrials) * 100) : 0;
      row(t("test.choice_geometry.result.wrong_response_rate"), wrongCount + " / " + totalTrials + " (" + wrongRate + "%)");
      row(t("test.choice_geometry.result.missed_count"), String(missedCount));
      // Per-shape breakdown when ≥ 5 trials per shape
      var shapeRTs = perShapeRTs(last.trials);
      var shapeNames = Object.keys(shapeRTs).filter(function (s) { return shapeRTs[s].length >= 5; });
      if (shapeNames.length >= 2) {
        shapeNames.forEach(function (shapeName) {
          row(t("test.choice_geometry.result.shape_rt", { shape: shapeName }), fmtMs(avg(shapeRTs[shapeName])));
        });
      }

    } else if (last.kind === "peripheral") {
      // CR-TEST-34 result display (CI-14.8)
      var allRTs = trialDataValues(last.trials, "reaction_time_ms");
      row(t("test.peripheral.result.reaction_time_mean"), fmtMs(avg(allRTs)));
      if (allRTs.length) {
        row(t("test.peripheral.result.best"), fmtMs(Math.min.apply(null, allRTs)));
        row(t("test.peripheral.result.worst"), fmtMs(Math.max.apply(null, allRTs)));
      }
      row(t("test.peripheral.result.missed_count"), String(countResult(last.trials, "MISSED_STIMULUS")));
      // Per-position breakdown when ≥ 3 trials per position
      var posBreakdown = perPositionRTs(last.trials);
      if (posBreakdown.length) {
        box.appendChild(Reflx.util.el("div", { class: "field-row result-section-header" }, [
          Reflx.util.el("label", {}, [t("test.peripheral.result.per_position")])
        ]));
        posBreakdown.forEach(function (p) {
          row(p.label + " (n=" + p.count + ")", fmtMs(p.mean));
        });
      }

    } else if (last.kind === "temporal") {
      // CR-TEST-35 result display (CI-14.11) — single pass over trials
      var signedErrors = [], absErrors = [], visibleRTs = [], invisibleRTs = [];
      var earlyCount = 0, lateCount = 0, onTimeCount = 0;
      last.trials.forEach(function (tr) {
        var d = tr.trial_data;
        if (!d || d.result !== "TRUE_RESPONSE") return;
        if (isNum(d.timing_error_ms)) {
          signedErrors.push(d.timing_error_ms);
          if (d.timing_error_ms < 0) earlyCount++;
          else if (d.timing_error_ms > 0) lateCount++;
          else onTimeCount++;
        }
        if (isNum(d.absolute_timing_error_ms)) {
          absErrors.push(d.absolute_timing_error_ms);
          if (d.circle_visible_at_click === true)  visibleRTs.push(d.absolute_timing_error_ms);
          else if (d.circle_visible_at_click === false) invisibleRTs.push(d.absolute_timing_error_ms);
        }
      });
      row(t("test.temporal.result.timing_error_mean"), fmtMs(avg(signedErrors)));
      row(t("test.temporal.result.abs_error_mean"), fmtMs(avg(absErrors)));
      var validCount = earlyCount + lateCount + onTimeCount;
      if (validCount > 0) {
        row(t("test.temporal.result.early_pct"), Math.round((earlyCount / validCount) * 100) + "%");
        row(t("test.temporal.result.late_pct"), Math.round((lateCount / validCount) * 100) + "%");
      }
      if (visibleRTs.length && invisibleRTs.length) {
        row(t("test.temporal.result.abs_error_visible"), fmtMs(avg(visibleRTs)));
        row(t("test.temporal.result.abs_error_invisible"), fmtMs(avg(invisibleRTs)));
      }

    } else if (last.kind === "visual-conflict") {
      // CR-TEST-33 result display (CI-15.2) — measurement phase only; pretrain not shown.
      // Per-condition RT and error rate side by side: neutral / congruent / conflict.
      var CONDITIONS = ["neutral", "congruent", "conflict"];
      var condRTs = { neutral: [], congruent: [], conflict: [] };
      var condErrors = { neutral: 0, congruent: 0, conflict: 0 };
      var condTotal = { neutral: 0, congruent: 0, conflict: 0 };

      last.trials.forEach(function (tr) {
        var d = tr.trial_data;
        if (!d || d.phase !== "measurement") return;
        var cond = d.condition;
        if (!cond || !(cond in condRTs)) return;
        condTotal[cond]++;
        if (d.result === "TRUE_RESPONSE" && isNum(d.reaction_time_ms)) {
          condRTs[cond].push(d.reaction_time_ms);
        } else if (d.result === "FALSE_RESPONSE" || d.result === "MISSED_STIMULUS") {
          condErrors[cond]++;
        }
      });

      CONDITIONS.forEach(function (cond) {
        var labelKey = "result.visual_conflict." + cond;
        var label = t(labelKey);
        var meanRt = condRTs[cond].length ? avg(condRTs[cond]) : null;
        var errRate = condTotal[cond] > 0 ? Math.round((condErrors[cond] / condTotal[cond]) * 100) : 0;
        row(label + " RT", fmtMs(meanRt));
        row(label + " errors", condTotal[cond] > 0 ? condErrors[cond] + " / " + condTotal[cond] + " (" + errRate + "%)" : "—");
      });

      // Conflict cost: signed delta of conflict vs neutral RT and error rate
      var neutralMean = condRTs.neutral.length ? avg(condRTs.neutral) : null;
      var conflictMean = condRTs.conflict.length ? avg(condRTs.conflict) : null;
      if (neutralMean !== null && conflictMean !== null) {
        var rtDelta = conflictMean - neutralMean;
        var sign = rtDelta >= 0 ? "+" : "";
        row(t("result.visual_conflict.conflict_cost") + " RT", sign + Reflx.i18n.formatNumber(Math.round(rtDelta), { maximumFractionDigits: 0 }) + " ms");
      }
      var neutralErrRate = condTotal.neutral > 0 ? condErrors.neutral / condTotal.neutral : null;
      var conflictErrRate = condTotal.conflict > 0 ? condErrors.conflict / condTotal.conflict : null;
      if (neutralErrRate !== null && conflictErrRate !== null) {
        var errDelta = Math.round((conflictErrRate - neutralErrRate) * 100);
        var errSign = errDelta >= 0 ? "+" : "";
        row(t("result.visual_conflict.conflict_cost") + " errors", errSign + errDelta + "%");
      }

    } else {
      row(t("runner.result.mean"), fmtMs(typeof last.primaryMetricMs === "number" ? last.primaryMetricMs : local.mean));
      row(t("runner.result.best"), fmtMs(local.best));
      row(t("runner.result.worst"), fmtMs(local.worst));
      row(t("runner.result.variability"), fmtVariability(summary.overall && summary.overall.sd_ms));
    }
    row(t("runner.result.trials_recorded"), String(last.trials.length));
    row(t("runner.result.false_starts"), String(last.falseStarts));
  }
  function fmtMs(v) { return fmtNumber(v, "ms"); }

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
      // Defensive chronological (ascending) sort before taking the last 8 — CONTRACT.md
      // v1.2 newly documents this endpoint's actual order as **newest-first**, which was
      // never documented before (v1.1 and earlier said nothing about order). This code
      // previously trusted the raw array order for both "last 8 = most recent 8" and
      // display order; against a newest-first array, a bare `entries.slice(-8)` actually
      // grabs the OLDEST 8 of the whole history and shows them oldest-to-newest reversed
      // — the opposite of this CR's own "last 8 runs, chronological left-to-right"
      // acceptance criteria. `client/js/stats-page.js`'s renderVersionCard() already
      // guards the same assumption this same way (see its comment); this brings the
      // trend card in line now that the real order is confirmed rather than assumed.
      var sorted = entries.slice().sort(function (a, b) { return new Date(a.created_at) - new Date(b.created_at); });
      var values = sorted.map(function (e) { return e.primary_metric_ms; }).filter(isNum);
      var max = Math.max.apply(null, values.map(Math.abs)) || 1;
      // CR-STATS-05: vertical bars, reusing CR-STATS-03's .vhist-bar/.vhist-bar-col
      // pattern (client/css/style.css) for visual consistency with the dashboard
      // histogram — bars rise from a baseline via the --h custom property. Same
      // last-8, chronological-order data and same relative-scaling logic as the old
      // horizontal layout, just plotted on a vertical axis; ms value labels sit above
      // each bar (where the histogram shows its bucket count) and date labels sit
      // below in the .vhist-axis row (where the histogram shows bucket ranges).
      var plot = Reflx.util.el("div", { class: "vhist-plot" });
      var axis = Reflx.util.el("div", { class: "vhist-axis" });
      sorted.slice(-8).forEach(function (e, i) {
        var pct = Math.min(100, Math.round((Math.abs(e.primary_metric_ms) / max) * 100));
        // CR-TEST-28 (v1.8): same abs-value px-distance vs. ms distinction as the
        // series tally above — primary_metric_ms is a px distance for Circle Collision.
        plot.appendChild(Reflx.util.el("div", { class: "vhist-bar-col", "data-idx": String(i), style: "--h:" + pct + "%;" }, [
          Reflx.util.el("span", { class: "vhist-count" }, [fmtPrimaryMetric(slug, e.primary_metric_ms)]),
          Reflx.util.el("div", { class: "vhist-bar" })
        ]));
        axis.appendChild(Reflx.util.el("span", {}, [Reflx.i18n.formatDateTime(new Date(e.created_at), { dateStyle: "short" })]));
      });
      trendBox.appendChild(Reflx.util.el("div", { class: "vhist-scroll" }, [plot, axis]));
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
    Reflx.i18n.loadLocaleConfig().then(function () {
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
    });

    document.addEventListener("reflx:localechange", function () {
      Reflx.i18n.applyToDocument();
      if (document.getElementById("page-description").classList.contains("active")) renderDescription();
      if (document.getElementById("page-test").classList.contains("active")) renderTestHeader();
    });

    // CR-UI-15: re-render the header's device hints if the user changes a binding
    // in another tab (Settings) and returns to this one — existing event, other
    // pages (settings-page.js) already dispatch/consume it, wiring here is a
    // simple additional listener, no new mechanism needed.
    document.addEventListener("reflx:settingschange", function () {
      if (document.getElementById("page-test").classList.contains("active")) renderTestHeader();
    });
  }

  boot();
})();

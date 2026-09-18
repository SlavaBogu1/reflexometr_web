/**
 * Reflexometr — Two-Hand Reaction Time (CR-TEST-04).
 *
 * Reads left/right key mapping from CR-UI-03's Platform Settings. Every trial
 * requires (or times out waiting for) a response from both hands to one shared
 * stimulus. Dominant-hand capture happens once, earlier, on the Description page
 * (see js/runner.js); per CONTRACT.md, the signed delta (dominant − non-dominant)
 * is now computed **server-side** (`summary.dominant_minus_nondominant_ms` in the
 * submit response) from the top-level `dominant_hand` field runner.js sends — this
 * module only records each hand's raw response timestamp (or `null` on timeout).
 */
(function (global) {
  "use strict";
  var Reflx = global.Reflx = global.Reflx || {};
  Reflx.tests = Reflx.tests || {};

  Reflx.tests["two-hand-reaction"] = {
    start: function (container, runInfo, handlers) {
      var t = Reflx.i18n.t;
      var settings = runInfo.settings;
      var scheduleTrials = runInfo.schedule.trials;
      var trialCount = runInfo.schedule.trial_count;
      var timeoutMs = runInfo.schedule.timeout_ms;
      var channels = runInfo.schedule.response_channels && runInfo.schedule.response_channels.length === 2
        ? runInfo.schedule.response_channels : ["left", "right"];
      var leftChannel = channels[0], rightChannel = channels[1];
      var scheduleIdx = 0;
      var trials = [];
      var stopped = false;
      var armTimer = null;
      var timeoutTimer = null;
      var stimulusAt = null;
      var leftAt = null, rightAt = null;
      var watchers = [];

      // CR-UI-21: trial counter now renders inside the stage box (centered above
      // the shared orb via .two-hand-stage's absolute-positioned #trial-progress
      // rule in style.css) rather than living in static runner.html markup
      // outside it. Inserted first so it doesn't interfere with the hand-zones'
      // own flex order.
      var stage = Reflx.util.el("div", { class: "two-hand-stage" }, [
        Reflx.util.el("span", { id: "trial-progress", class: "field-desc" }),
        Reflx.util.el("div", { class: "hand-zone", id: "hand-left" }, [t("test.twohand.left_status")]),
        Reflx.util.el("div", { class: "hand-zone", id: "hand-right" }, [t("test.twohand.right_status")])
      ]);
      // Add centered stimulus element (CR-TEST-07)
      var orb = Reflx.util.el("div", { class: "orb idle", style: "position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); z-index: 1;" });
      stage.appendChild(orb);
      var orbEl = orb;
      container.innerHTML = "";
      container.appendChild(stage);
      var leftZone = stage.querySelector("#hand-left");
      var rightZone = stage.querySelector("#hand-right");
      var statusEl = document.getElementById("stage-status");

      function stopWatchers() { watchers.forEach(function (w) { w.stop(); }); watchers = []; }

      function armTrial() {
        if (stopped) return;
        if (trials.length >= trialCount) { finish(); return; }
        if (scheduleIdx >= scheduleTrials.length) { finish(); return; }
        var delay = scheduleTrials[scheduleIdx++].delay_ms;
        stimulusAt = null; leftAt = null; rightAt = null;
        leftZone.className = "hand-zone"; rightZone.className = "hand-zone";
        leftZone.textContent = t("test.twohand.left_status");
        rightZone.textContent = t("test.twohand.right_status");
        orbEl.className = "orb armed"; // CR-TEST-07: update stimulus on arm
        statusEl.textContent = t("test.simple.armed");
        handlers.onProgress(trials.length, trialCount);

        // CR-TEST-19: register both hand-key watchers here, at the top of armTrial(),
        // before the delay — mirrors simple-reaction.js:63-65's pattern, so a
        // pre-stimulus press is actually captured by a listener that already exists
        // (previously these were registered inside the setTimeout below, i.e. only
        // after the stimulus had already fired — no listener existed yet to hear an
        // early press at all).
        stopWatchers();
        watchers.push(Reflx.inputCapture.watch({ type: "keyboard", code: settings.leftHandKey }, function (at) { onHand("left", at); }));
        watchers.push(Reflx.inputCapture.watch({ type: "keyboard", code: settings.rightHandKey }, function (at) { onHand("right", at); }));

        armTimer = setTimeout(function () {
          if (stopped) return;
          stimulusAt = performance.now();
          orbEl.className = "orb go"; // CR-TEST-07: change stimulus color on stimulus onset
          statusEl.textContent = t("test.simple.go");

          if (timeoutMs) {
            timeoutTimer = setTimeout(function () {
              if (stopped) return;
              completeTrial();
            }, timeoutMs);
          }
        }, delay);
      }

      function onHand(hand, at) {
        if (stopped) return;
        if (stimulusAt === null) {
          // CR-TEST-19: false start — either hand fired before the stimulus. Void
          // this trial slot (do not count it, do not advance trials.length), clear
          // the arm timer, stop watchers, report it, and re-arm to consume the
          // schedule's next spare buffer_trials entry — identical recovery flow to
          // simple-reaction.js's onInput() false-start branch.
          // CR-TEST-20: add visible feedback (yellow orb, brief pause) around this
          // already-correct decision point — no change to detection/discard logic.
          // Verified: a false start is only reachable here while stimulusAt is
          // still null, i.e. before either hand can register a legitimate "hit"
          // this trial (that only happens once stimulusAt is set below in
          // armTrial()'s setTimeout) — so leftZone/rightZone are always still in
          // their armTrial()-reset waiting state already; no stale "hit" styling
          // to clear.
          clearTimeout(armTimer);
          stopWatchers();
          orbEl.className = "orb false-start";
          statusEl.textContent = t("runner.test.false_start");
          handlers.onFalseStart();
          setTimeout(armTrial, 500);
          return;
        }
        if (hand === "left" && leftAt === null) { leftAt = at; leftZone.textContent = t("test.twohand.hit_hand"); leftZone.className = "hand-zone hit"; }
        if (hand === "right" && rightAt === null) { rightAt = at; rightZone.textContent = t("test.twohand.hit_hand"); rightZone.className = "hand-zone hit"; }
        if (leftAt !== null && rightAt !== null) completeTrial();
      }

      function completeTrial() {
        clearTimeout(timeoutTimer);
        stopWatchers();
        if (leftAt === null) leftZone.textContent = t("test.twohand.timeout_hand");
        if (rightAt === null) rightZone.textContent = t("test.twohand.timeout_hand");

        var responses = {};
        responses[leftChannel] = leftAt; // number or null (timeout — only valid when timeout_ms is non-null)
        responses[rightChannel] = rightAt;
        trials.push({ index: trials.length, stimulus_at: stimulusAt, responses: responses });
        orbEl.className = "orb idle"; // CR-TEST-07: reset stimulus to idle
        statusEl.textContent = "";
        setTimeout(armTrial, 400);
      }

      function finish() {
        stopped = true;
        stopWatchers();
        clearTimeout(armTimer);
        clearTimeout(timeoutTimer);
        handlers.onDone(trials);
      }

      armTrial();

      return {
        quit: function () {
          stopped = true;
          clearTimeout(armTimer);
          clearTimeout(timeoutTimer);
          stopWatchers();
        }
      };
    }
  };
})(window);

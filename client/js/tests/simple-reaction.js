/**
 * Reflexometr — Simple Visual Reaction Time (CR-TEST-03).
 *
 * Reads device/key bindings from CR-UI-03's Platform Settings (no per-test device
 * step). Listens to all three configured channels (keyboard key, mouse left-click,
 * gamepad button) at once — whichever the user actually presses completes the
 * trial. A response before the stimulus changes color is a false start: it voids
 * the trial slot and re-runs it using the schedule's next spare entry (per
 * CONTRACT.md's `buffer_trials`) rather than counting it.
 *
 * Submission shape follows `_API_CONTRACT/CONTRACT.md` § run-token submission
 * exactly: `{ index, stimulus_at, responses: { primary: <ms> } }` per valid trial,
 * renumbered `0..trial_count-1` — the real contract's submit body has no field for
 * which device/key was used (only timestamps), so that detail is no longer sent
 * (a gap vs. CR-TEST-03's original text — flagged in SPRINT1_REPORT.md).
 */
(function (global) {
  "use strict";
  var Reflx = global.Reflx = global.Reflx || {};
  Reflx.tests = Reflx.tests || {};

  Reflx.tests["simple-reaction"] = {
    /**
     * @param container DOM node to render the stimulus into
     * @param runInfo { schedule: <CONTRACT.md schedule object>, settings }
     * @param handlers { onProgress(done, total), onFalseStart(), onDone(trials) }
     */
    start: function (container, runInfo, handlers) {
      var t = Reflx.i18n.t;
      var settings = runInfo.settings;
      var scheduleTrials = runInfo.schedule.trials;
      var trialCount = runInfo.schedule.trial_count;
      var channel = (runInfo.schedule.response_channels && runInfo.schedule.response_channels[0]) || "primary";
      var scheduleIdx = 0;
      var validTrials = [];
      var stopped = false;
      var armTimer = null;
      var stimulusAt = null;
      var watchers = [];

      var orb = Reflx.util.el("div", { class: "stimulus-stage" }, [Reflx.util.el("div", { class: "orb idle" })]);
      container.innerHTML = "";
      container.appendChild(orb);
      var orbEl = orb.querySelector(".orb");
      var statusEl = document.getElementById("stage-status");

      function stopWatchers() {
        watchers.forEach(function (w) { w.stop(); });
        watchers = [];
      }

      function armTrial() {
        if (stopped) return;
        if (validTrials.length >= trialCount) { finish(); return; }
        if (scheduleIdx >= scheduleTrials.length) { finish(); return; } // out of spare slots — end gracefully
        var delay = scheduleTrials[scheduleIdx++].delay_ms;
        stimulusAt = null;
        orbEl.className = "orb armed";
        statusEl.textContent = t("test.simple.armed");
        handlers.onProgress(validTrials.length, trialCount);

        stopWatchers();
        watchers.push(Reflx.inputCapture.watch({ type: "keyboard", code: settings.keyboardKey }, onInput));
        watchers.push(Reflx.inputCapture.watch({ type: "mouse", target: orbEl }, onInput));
        watchers.push(Reflx.inputCapture.watch({ type: "gamepad", index: settings.gamepadButtonIndex }, onInput));

        armTimer = setTimeout(function () {
          if (stopped) return;
          stimulusAt = performance.now();
          orbEl.className = "orb go";
          statusEl.textContent = t("test.simple.go");
        }, delay);
      }

      function onInput(at) {
        if (stopped) return;
        if (stimulusAt === null) {
          // False start — before the color change.
          clearTimeout(armTimer);
          stopWatchers();
          statusEl.textContent = t("runner.test.false_start");
          handlers.onFalseStart();
          armTrial();
          return;
        }
        stopWatchers();
        var responses = {};
        responses[channel] = at;
        validTrials.push({ index: validTrials.length, stimulus_at: stimulusAt, responses: responses });
        orbEl.className = "orb idle";
        statusEl.textContent = "";
        setTimeout(armTrial, 350); // brief pause before re-arming, avoids double-count of the same press
      }

      function finish() {
        stopped = true;
        stopWatchers();
        clearTimeout(armTimer);
        handlers.onDone(validTrials);
      }

      armTrial();

      return {
        quit: function () {
          stopped = true;
          clearTimeout(armTimer);
          stopWatchers();
        }
      };
    }
  };
})(window);

/**
 * Reflexometr — Circle Collision, Simple + Complex variants (CR-TEST-23, CR-TEST-24).
 *
 * Two circles start at the stage's left/right edges (centers on the same horizontal
 * line, per the approved mockup `client/prototype-circle-collision-stage.html`) and
 * animate toward each other. Unlike every other test type, there is no color-change
 * stimulus — the "stimulus" is the predicted collision moment itself, and motion
 * onset (not a color change) is what makes a click a valid response instead of a
 * false start.
 *
 * Simple (CR-TEST-23): both circles fixed at the baseline radius, constant closing
 * speed for the whole trial, derived from the trial's resolved `motion_duration_ms`
 * and the stage's own measured width at render time (never a hardcoded pixel speed,
 * so this is correct across viewport sizes).
 *
 * Complex (CR-TEST-24): extends Simple's renderer — each circle's own independently
 * resolved radius (±20% of Simple's baseline, from the schedule) and a closing speed
 * that's interpolated smoothly between the schedule's resolved `motion_speed_profile`
 * waypoints across the trial's own requestAnimationFrame loop (no jump/discontinuity
 * at a waypoint boundary), rather than one constant speed for the whole trial.
 *
 * Per CR-TEST-23's whole point (measuring anticipation, including early guesses): a
 * click before motion starts is a false start (matches every other test type's
 * existing false-start UX — see simple-reaction.js). A click any time after motion
 * starts is a valid response and is submitted as-is, even arbitrarily early — no
 * client-side rejection of an "early" click; the server's `allow_early_response`
 * schedule flag is what accepts it without the usual REACTION_BEFORE_STIMULUS check.
 *
 * Animation always uses requestAnimationFrame + performance.now() — never setTimeout
 * — per the standing reflex-timing requirement (client/CLAUDE.md).
 *
 * Trial submission shape matches every other test type per `_API_CONTRACT/CONTRACT.md`
 * § run-token submission: `{ index, stimulus_at, responses: { primary: <ms> } }` per
 * valid trial, renumbered `0..trial_count-1`. `stimulus_at` is motion-onset time here
 * (the moment the circles start moving), not a color change.
 */
(function (global) {
  "use strict";
  var Reflx = global.Reflx = global.Reflx || {};
  Reflx.tests = Reflx.tests || {};

  var BASELINE_RADIUS_PX = 84; // matches the mockup's simple-variant circle: 168px diameter / 2

  /**
   * @param variant "simple" | "complex"
   */
  function makeCollisionTest(variant) {
    return {
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
        var rafHandle = null;
        var stimulusAt = null; // null while armed (pre-motion); set once motion starts
        var motionStartPerf = null;
        var watchers = [];

        var stage = Reflx.util.el("div", { class: "collision-stage" }, [
          Reflx.util.el("span", { id: "trial-progress", class: "field-desc" }),
          Reflx.util.el("div", { class: "collision-circle left" }),
          Reflx.util.el("div", { class: "collision-circle right" })
        ]);
        container.innerHTML = "";
        container.appendChild(stage);
        var leftEl = stage.querySelector(".collision-circle.left");
        var rightEl = stage.querySelector(".collision-circle.right");
        var statusEl = document.getElementById("stage-status");

        function stopWatchers() { watchers.forEach(function (w) { w.stop(); }); watchers = []; }
        function stopMotion() { if (rafHandle !== null) { cancelAnimationFrame(rafHandle); rafHandle = null; } }

        /** Resolves a trial's motion parameters from the schedule (trial-level fields
         * take precedence over schedule-level ones, matching how delay_ms already
         * works per-trial elsewhere) — CONTRACT.md v1.6, CR-TEST-23/24. */
        function resolveTrialMotion(trial) {
          var durationMs = trial.motion_duration_ms || runInfo.schedule.motion_duration_ms || 2000;
          if (variant === "simple") {
            var radiusPx = trial.circle_radius_px || runInfo.schedule.circle_radius_px || BASELINE_RADIUS_PX;
            return { durationMs: durationMs, leftRadiusPx: radiusPx, rightRadiusPx: radiusPx, speedProfile: null };
          }
          // complex: per-circle independently resolved radius, ±20% of baseline
          var leftRadiusPx = (trial.circle_radius_px && trial.circle_radius_px.left) || BASELINE_RADIUS_PX;
          var rightRadiusPx = (trial.circle_radius_px && trial.circle_radius_px.right) || BASELINE_RADIUS_PX;
          var speedProfile = trial.motion_speed_profile || runInfo.schedule.motion_speed_profile || null;
          return { durationMs: durationMs, leftRadiusPx: leftRadiusPx, rightRadiusPx: rightRadiusPx, speedProfile: speedProfile };
        }

        function armTrial() {
          if (stopped) return;
          if (validTrials.length >= trialCount) { finish(); return; }
          if (scheduleIdx >= scheduleTrials.length) { finish(); return; }
          var trial = scheduleTrials[scheduleIdx++];
          var delay = trial.delay_ms;
          var motion = resolveTrialMotion(trial);
          stimulusAt = null;
          motionStartPerf = null;
          leftEl.style.width = leftEl.style.height = (motion.leftRadiusPx * 2) + "px";
          rightEl.style.width = rightEl.style.height = (motion.rightRadiusPx * 2) + "px";
          positionCircles(0); // 0 = fully separated, at the stage's edges
          statusEl.textContent = t("test.collision.armed");
          handlers.onProgress(validTrials.length, trialCount);

          stopWatchers();
          watchers.push(Reflx.inputCapture.watch({ type: "keyboard", code: settings.keyboardKey }, onInput));
          // CR-TEST-22 convention: bind to the full stage (not just a circle) — see
          // input-capture.js's doc comment; mousedown bubbles up from any descendant.
          watchers.push(Reflx.inputCapture.watch({ type: "mouse", target: stage }, onInput));
          watchers.push(Reflx.inputCapture.watch({ type: "gamepad", index: settings.gamepadButtonIndex }, onInput));

          armTimer = setTimeout(function () {
            if (stopped) return;
            stimulusAt = performance.now();
            motionStartPerf = stimulusAt;
            statusEl.textContent = t("test.collision.go");
            runMotion(motion);
          }, delay);
        }

        /** progress: 0 (fully separated, at stage edges) .. 1 (centers meet). Positions
         * both circles symmetrically inward from the stage's own measured width so this
         * is correct at any viewport size — never a hardcoded pixel speed. */
        function positionCircles(progress) {
          var half = progress * 50; // 0..50% of stage width each circle travels inward
          leftEl.style.left = half + "%";
          rightEl.style.left = (100 - half) + "%";
        }

        /** Simple: constant speed derived from motion.durationMs. Complex: interpolates
         * smoothly between motion.speedProfile's resolved waypoints (a 0..1-progress ->
         * relative-speed curve) so there's no visible jump at a waypoint boundary — the
         * eased/integrated progress function below is continuous by construction. */
        function progressFn(motion) {
          if (!motion.speedProfile || !motion.speedProfile.length) {
            return function (elapsedFrac) { return elapsedFrac; }; // constant speed
          }
          var waypoints = motion.speedProfile; // e.g. [{t:0,speed:0.6}, {t:0.5,speed:1.4}, {t:1,speed:0.8}]
          // Precompute cumulative "distance" (integral of speed over t) at each waypoint
          // so progress(t) is a piecewise-linear-in-speed, continuous function of t.
          var cum = [0];
          for (var i = 1; i < waypoints.length; i++) {
            var dt = waypoints[i].t - waypoints[i - 1].t;
            var avgSpeed = (waypoints[i].speed + waypoints[i - 1].speed) / 2;
            cum.push(cum[i - 1] + dt * avgSpeed);
          }
          var total = cum[cum.length - 1] || 1;
          return function (elapsedFrac) {
            var i = 1;
            while (i < waypoints.length - 1 && waypoints[i].t < elapsedFrac) i++;
            var t0 = waypoints[i - 1].t, t1 = waypoints[i].t;
            var s0 = waypoints[i - 1].speed, s1 = waypoints[i].speed;
            var localFrac = t1 > t0 ? (elapsedFrac - t0) / (t1 - t0) : 0;
            var localSpeed = s0 + (s1 - s0) * localFrac;
            var localAvg = (s0 + localSpeed) / 2;
            var dist = cum[i - 1] + (elapsedFrac - t0) * localAvg;
            return Math.min(1, dist / total);
          };
        }

        function runMotion(motion) {
          var startPerf = motionStartPerf;
          var duration = motion.durationMs;
          var progOf = progressFn(motion);
          function frame(now) {
            if (stopped) return;
            var elapsedFrac = Math.min(1, (performance.now() - startPerf) / duration);
            positionCircles(progOf(elapsedFrac));
            if (elapsedFrac < 1) {
              rafHandle = requestAnimationFrame(frame);
            } else {
              rafHandle = null; // reached predicted collision — keep circles at final position, still awaiting a response
            }
          }
          rafHandle = requestAnimationFrame(frame);
        }

        function onInput(at) {
          if (stopped) return;
          if (stimulusAt === null) {
            // Before motion starts — false start, same UX pattern as every other test.
            clearTimeout(armTimer);
            stopWatchers();
            statusEl.textContent = t("runner.test.false_start");
            handlers.onFalseStart();
            setTimeout(armTrial, 500);
            return;
          }
          // Any time after motion starts is a valid response, submitted as-is — no
          // client-side early/late rejection (CR-TEST-23's allow_early_response point).
          stopWatchers();
          stopMotion();
          var responses = {};
          responses[channel] = at;
          validTrials.push({ index: validTrials.length, stimulus_at: stimulusAt, responses: responses });
          statusEl.textContent = "";
          setTimeout(armTrial, 350);
        }

        function finish() {
          stopped = true;
          stopWatchers();
          stopMotion();
          clearTimeout(armTimer);
          handlers.onDone(validTrials);
        }

        armTrial();

        return {
          quit: function () {
            stopped = true;
            clearTimeout(armTimer);
            stopMotion();
            stopWatchers();
          }
        };
      }
    };
  }

  Reflx.tests["circle-collision-simple"] = makeCollisionTest("simple");
  Reflx.tests["circle-collision-complex"] = makeCollisionTest("complex");
})(window);

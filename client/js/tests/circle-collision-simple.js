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
 * valid trial, renumbered `0..trial_count-1`. `stimulus_at` is the predicted collision
 * instant here (`motion_start_time + motion.durationMs` — the moment the two circles'
 * centers are computed to meet), not motion-onset time and not a color change — see
 * D24. This lets a response submitted before the predicted collision score as a
 * negative anticipation value, matching CR-TEST-23's measurement intent.
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
        var timeoutMs = runInfo.schedule.timeout_ms; // null = no per-response timeout (server never rejects on lateness)
        var channel = (runInfo.schedule.response_channels && runInfo.schedule.response_channels[0]) || "primary";
        var scheduleIdx = 0;
        var validTrials = [];
        var stopped = false;
        var armTimer = null;
        var rafHandle = null;
        var stimulusAt = null; // null while armed (pre-motion); set once motion starts. Used ONLY
                                // for the false-start gate ("has motion started yet?") — NOT what
                                // gets submitted as stimulus_at (see collisionAt below, D24).
        var motionStartPerf = null;
        var collisionAt = null; // predicted collision instant (motionStartPerf + motion.durationMs);
                                 // set once motion starts, alongside motionStartPerf. This is the
                                 // value submitted as the trial log's stimulus_at (D24) — the false-
                                 // start gate above and this submission value are deliberately kept
                                 // as two separate variables even though both derive from motion start.
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
          if (variant === "simple") {
            var durationMs = trial.motion_duration_ms || runInfo.schedule.motion_duration_ms || 2000;
            var radiusPx = trial.circle_radius_px || runInfo.schedule.circle_radius_px || BASELINE_RADIUS_PX;
            return { durationMs: durationMs, leftRadiusPx: radiusPx, rightRadiusPx: radiusPx, speedProfile: null };
          }
          // complex: per-circle independently resolved radius, ±20% of baseline.
          // CONTRACT.md v1.6 keys this { a, b } (not left/right) — a maps to the left
          // circle, b to the right circle (declaration order in the contract).
          var leftRadiusPx = (trial.circle_radius_px && trial.circle_radius_px.a) || BASELINE_RADIUS_PX;
          var rightRadiusPx = (trial.circle_radius_px && trial.circle_radius_px.b) || BASELINE_RADIUS_PX;
          var speedProfile = trial.motion_speed_profile || runInfo.schedule.motion_speed_profile || null;
          // motion_speed_profile.duration_ms is Complex's equivalent of motion_duration_ms —
          // the single source runMotion() reads via motion.durationMs (CONTRACT.md v1.6).
          var complexDurationMs = (speedProfile && speedProfile.duration_ms) || 2000;
          return { durationMs: complexDurationMs, leftRadiusPx: leftRadiusPx, rightRadiusPx: rightRadiusPx, speedProfile: speedProfile };
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
          collisionAt = null;
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
            // Predicted collision instant (D24) — the moment the two circles' centers are
            // computed to meet, deterministically derivable now that motion.durationMs (the
            // server-resolved total duration for this trial) is known.
            collisionAt = motionStartPerf + motion.durationMs;
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
        /** Trapezoid-integral distance covered by a linear speed ramp from `v0` to `v1`
         * across a `[t0, t1]` window, evaluated up to `tNow` (clamped to the window). */
        function rampDistance(v0, v1, t0, t1, tNow) {
          var localFrac = (Math.min(tNow, t1) - t0) / (t1 - t0);
          var localSpeed = v0 + (v1 - v0) * localFrac;
          return (v0 + localSpeed) / 2 * (Math.min(tNow, t1) - t0);
        }

        // CR-TEST-26: progressFn is evaluated for elapsedFrac > 1 too (post-collision
        // separation phase, see runMotion() below) — un-clamped past waypoint 1, so it
        // must extrapolate rather than plateau. Simple's constant-speed case already
        // does that for free (elapsedFrac === progress, no clamping). Complex's
        // profiled case continues at the resolved end speed (the last known
        // instantaneous speed at elapsedFrac=1) for elapsedFrac > 1 — "the same
        // resolved speed/profile they were already using", per CR-TEST-26.
        function progressFn(motion) {
          var profile = motion.speedProfile;
          if (!profile) {
            return function (elapsedFrac) { return elapsedFrac; }; // constant speed, naturally unclamped
          }
          // CONTRACT.md v1.6: motion_speed_profile is a fixed 3-waypoint object
          // (start/mid/end named speeds), not an arbitrary array — waypoints sit at
          // elapsedFrac 0, 0.5, 1. Precompute cumulative "distance" (integral of speed
          // over t) at each waypoint so progress(t) is piecewise-linear-in-speed and
          // continuous across the t=0.5 midpoint (no jump).
          var s0 = profile.start_speed_px_per_s;
          var sMid = profile.mid_speed_px_per_s;
          var s1 = profile.end_speed_px_per_s;
          var distFirstHalf = rampDistance(s0, sMid, 0, 0.5, 0.5);
          var total = (distFirstHalf + rampDistance(sMid, s1, 0.5, 1, 1)) || 1;
          return function (elapsedFrac) {
            if (elapsedFrac <= 1) {
              var dist = elapsedFrac <= 0.5
                ? rampDistance(s0, sMid, 0, 0.5, elapsedFrac)
                : distFirstHalf + rampDistance(sMid, s1, 0.5, 1, elapsedFrac);
              return dist / total;
            }
            // Past collision: keep moving at the final resolved end speed (no further
            // waypoints defined) — continues the "progress" curve linearly beyond 1.
            var distAt1 = distFirstHalf + rampDistance(sMid, s1, 0.5, 1, 1);
            return (distAt1 + s1 * (elapsedFrac - 1)) / total;
          };
        }

        /** Distance between the two circles' centers, in px, at the given progress
         * value — derived the same way positionCircles() derives `left%` from
         * progress, so the two never drift out of sync. Used only for the CR-TEST-26
         * post-collision stop condition (never affects stimulus_at/timing/validation). */
        function centerDistancePx(progress, stageWidthPx) {
          var half = progress * 50; // matches positionCircles()'s own formula
          var leftPct = half;
          var rightPct = 100 - half;
          return Math.abs(rightPct - leftPct) / 100 * stageWidthPx;
        }

        function runMotion(motion) {
          var startPerf = motionStartPerf;
          var duration = motion.durationMs;
          var progOf = progressFn(motion);
          var stageWidthPx = stage.offsetWidth;
          var maxRadiusPx = Math.max(motion.leftRadiusPx, motion.rightRadiusPx);
          function frame(now) {
            if (stopped) return;
            var elapsedFrac = (performance.now() - startPerf) / duration;
            var progress = progOf(elapsedFrac);
            positionCircles(progress);
            if (elapsedFrac < 1) {
              rafHandle = requestAnimationFrame(frame);
              return;
            }
            // CR-TEST-26: past the predicted collision instant (elapsedFrac >= 1), keep
            // advancing at the same resolved speed/profile — circles pass through and
            // separate — until they're back apart by more than the larger circle's
            // radius, then stop (mirrors stopMotion()'s cancelAnimationFrame cleanup,
            // just gated on distance instead of elapsedFrac >= 1). Purely visual — does
            // not touch stimulus_at/collisionAt or any validation logic; a click at any
            // point during this extended phase still submits exactly as before onInput().
            if (centerDistancePx(progress, stageWidthPx) > maxRadiusPx) {
              rafHandle = null;
            } else {
              rafHandle = requestAnimationFrame(frame);
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
          // client-side EARLY rejection (CR-TEST-23's allow_early_response point).
          //
          // A too-LATE response is a different story: the server's own submit-time
          // validation (RunService::validateTrialLog) unconditionally rejects any trial
          // whose reaction time exceeds the schedule's timeout_ms, with no Circle-Collision
          // exemption. Every other test type's reaction times are near-instant (hundreds of
          // ms) so that floor is never in practice reachable there, but Circle Collision's
          // whole premise invites watching a multi-second animation before responding
          // (motion_duration_ms can run up to several seconds on its own), so a real,
          // unhurried user can organically exceed timeout_ms — especially on whichever
          // trial happens to draw a long motion_duration_ms. Discovered as the confirmed
          // root cause of a reported "trial 10 hang": the run actually completed and
          // submitted fine, but the server's rejection (TRIAL_LOG_INVALID /
          // RESPONSE_AFTER_TIMEOUT) drove finishRun()'s alert(), whose blocking native
          // dialog looks exactly like a JS deadlock to automated tooling (and is easy to
          // miss for a real user too). Voiding a too-late response client-side — mirroring
          // the false-start guard just above, same void-and-retry shape — makes it
          // impossible to ever submit a trial the server is guaranteed to reject, instead
          // of discovering the rejection only after all 10 trials are already spent.
          // STIMULUS_TIMING_TOLERANCE_MS on the server is 150ms; matched here so the two
          // sides agree on the exact boundary instead of the client being either stricter
          // or (worse) more lenient than what the server will actually accept.
          if (timeoutMs !== null && timeoutMs !== undefined && (at - stimulusAt) > timeoutMs + 150) {
            stopWatchers();
            stopMotion();
            statusEl.textContent = "";
            setTimeout(armTrial, 350);
            return;
          }
          stopWatchers();
          stopMotion();
          var responses = {};
          responses[channel] = at;
          validTrials.push({ index: validTrials.length, stimulus_at: collisionAt, responses: responses });
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

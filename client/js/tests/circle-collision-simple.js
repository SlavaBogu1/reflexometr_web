/**
 * Reflexometr — Circle Collision, Simple + Complex variants (CR-TEST-23, CR-TEST-24,
 * CR-TEST-28).
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
 * existing false-start UX — see simple-reaction.js). A click ANY time after motion
 * starts is a valid response and is submitted as-is, even arbitrarily early or late
 * — no client-side rejection either direction; the server's `allow_early_response`
 * schedule flag is what accepts an early click without the usual
 * REACTION_BEFORE_STIMULUS check, and CR-TEST-28 (v1.8)'s full-stage-travel window
 * (see runMotion() below) is what makes a late click always reachable too, replacing
 * the old client-side too-late void-and-retry.
 *
 * CR-TEST-28 (v1.8): motion now always runs the FULL stage — from one edge, through
 * the center (the predicted collision instant), all the way to the OPPOSITE edge —
 * before stopping, rather than CR-TEST-26's radius-based early stop shortly after
 * collision. If motion reaches the far edge with no click received, the trial ends
 * as a genuine timeout: submitted with `responses[channel]: null` (see
 * onFarEdgeTimeout() below), not silently voided and retried.
 *
 * Animation always uses requestAnimationFrame + performance.now() — never setTimeout
 * — per the standing reflex-timing requirement (client/CLAUDE.md).
 *
 * Trial submission shape matches every other test type per `_API_CONTRACT/CONTRACT.md`
 * § run-token submission: `{ index, stimulus_at, responses: { primary: <ms|null> } }`
 * per valid trial, renumbered `0..trial_count-1`. `stimulus_at` is the predicted
 * collision instant here (`motion_start_time + motion.durationMs` — the moment the
 * two circles' centers are computed to meet), not motion-onset time and not a color
 * change — see D24. This lets a response submitted before the predicted collision
 * score as a negative anticipation value, matching CR-TEST-23's measurement intent.
 * CONTRACT.md v1.8 (CR-TEST-28) additionally resolves `closing_speed_px_per_ms` per
 * trial, letting the Result page (`runner.js`) compute signed distance-at-click in px
 * from `stimulus_at`/`response_at` — not computed here, this module only produces the
 * raw ms-based trial log.
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
        // CR-TEST-28 (v1.8): timeout_ms is no longer read/guarded client-side — motion
        // always runs the full stage travel (runMotion()'s progress >= 2 stop) and
        // onFarEdgeTimeout() is what submits a timeout, not a client-computed deadline
        // check. Kept off this object entirely to avoid a second, now-unused source of
        // truth for something the server's own submit-time validation still governs.
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

        /** CR-TEST-28 (v1.8): genuine full-stage travel replaces CR-TEST-26's
         * radius-based early stop — circles continue at the same resolved
         * speed/profile from one edge, through the center (collisionAt, D24),
         * all the way to the OPPOSITE edge (progress === 2, symmetric to the
         * 0..1 leg that reaches the center at progress === 1; positionCircles()'s
         * own left%/right% formula already extrapolates correctly past 1 with no
         * separate distance math needed). Motion stops there; if no response was
         * received by then, onFarEdgeTimeout() submits a timeout trial instead of
         * the old silent void-and-retry (see onInput() below). Purely a motion/
         * stop-condition change — does not touch stimulus_at/collisionAt or any
         * response validation; a click at any point during the full travel still
         * stops motion immediately and submits as before. */
        function runMotion(motion) {
          var startPerf = motionStartPerf;
          var duration = motion.durationMs;
          var progOf = progressFn(motion);
          function frame(now) {
            if (stopped) return;
            var elapsedFrac = (performance.now() - startPerf) / duration;
            var progress = progOf(elapsedFrac);
            positionCircles(progress);
            if (progress < 2) {
              rafHandle = requestAnimationFrame(frame);
              return;
            }
            rafHandle = null;
            onFarEdgeTimeout();
          }
          rafHandle = requestAnimationFrame(frame);
        }

        // CR-TEST-28 (v1.8): replaces CR-TEST-26's client-side void-and-retry for a
        // too-late response (the "trial 10 hang" workaround) with a genuine
        // timeout-driven trial end. The server's timeout_ms deadline is no longer
        // guarded against here at all — Circle Collision now always runs motion to
        // the far stage edge (runMotion()'s progress >= 2 stop, above) and lets a
        // click at ANY point during that full travel submit as a valid response,
        // arbitrarily late included. Only once motion reaches the far edge with
        // still no click does onFarEdgeTimeout() (below) submit the trial with a
        // null response — the contract's pre-existing, already-legal "timeout"
        // shape (CONTRACT.md v1.8 "timeout-as-null-response"; both Circle Collision
        // seeds set timeout_ms: 8000, non-null, so a null response is allowed).
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
          // Any time after motion starts (including all the way through the extended
          // post-collision travel to the far edge) is a valid response, submitted
          // as-is — no client-side EARLY or LATE rejection (CR-TEST-23's
          // allow_early_response point, now extended by CR-TEST-28's full-stage-travel
          // window covering "late" too).
          stopWatchers();
          stopMotion();
          var responses = {};
          responses[channel] = at;
          validTrials.push({ index: validTrials.length, stimulus_at: collisionAt, responses: responses });
          statusEl.textContent = "";
          setTimeout(armTrial, 350);
        }

        /** CR-TEST-28 (v1.8): motion reached the far stage edge with no click —
         * a legitimate timeout/miss, not an error. Submits the trial with
         * `responses[channel]: null` per CONTRACT.md's pre-existing "value is
         * either a number or null, only allowed if the schedule's timeout_ms is
         * non-null" clause, then continues to the next trial exactly like a
         * normal valid response does. */
        function onFarEdgeTimeout() {
          if (stopped) return;
          stopWatchers();
          var responses = {};
          responses[channel] = null;
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

/**
 * Reflexometr — Random Target Appearance and Pointing (CR-TEST-30, K3).
 *
 * Blank neutral field; after a randomized delay a circular target appears at a
 * random position. The participant moves the cursor to the target and clicks
 * inside it. Separates visual reaction time (time from target appearance to
 * first cursor movement) from movement time (time from first movement to click).
 *
 * Event logic:
 *   TRUE_STIMULUS  — target becomes visible; timestamp captured via performance.now().
 *   TRUE_RESPONSE  — mousedown inside target area.
 *   FALSE_RESPONSE — mousedown outside target while target is active.
 *   MISSED_STIMULUS — no successful click within response_timeout_ms.
 *
 * Optional trajectory: when schedule.record_trajectory is true, all mousemove
 * events between TRUE_STIMULUS and click are captured as { t, x, y } arrays
 * using performance.now() timestamps.
 *
 * Trial submission shape (per _API_CONTRACT/CONTRACT.md § run-token submission):
 *   { index, stimulus_at, responses: { primary: <ms|null> },
 *     trial_data: { reaction_time_ms, movement_time_ms, click_error_px,
 *                   trajectory_json, result, quality_flag } }
 * The server validates these per CONTRACT.md v1.9.
 */
(function (global) {
  "use strict";
  var Reflx = global.Reflx = global.Reflx || {};
  Reflx.tests = Reflx.tests || {};

  Reflx.tests["random-target-pointing"] = {
    /**
     * @param container DOM node to render the stage into
     * @param runInfo { schedule, settings }
     * @param handlers { onProgress(done,total), onFalseStart(), onDone(trials) }
     */
    start: function (container, runInfo, handlers) {
      var t = Reflx.i18n.t;
      var schedule = runInfo.schedule;
      var scheduleTrials = schedule.trials;
      var trialCount = schedule.trial_count;
      var timeoutMs = schedule.response_timeout_ms || schedule.timeout_ms || 5000;
      var recordTrajectory = !!schedule.record_trajectory;
      var minDistPx = schedule.min_distance_from_prev_px || 0;
      var targetDiameterPx = schedule.target_diameter_px || 80;
      var targetRadius = targetDiameterPx / 2;

      var scheduleIdx = 0;
      var validTrials = [];
      var stopped = false;
      var armTimer = null;
      var timeoutTimer = null;
      var stimulusAt = null;
      var firstMoveAt = null;
      var targetX = null;
      var targetY = null;
      var prevTargetX = null;
      var prevTargetY = null;
      var targetVisible = false;
      var trajectory = [];
      var currentMouseMove = null;
      var currentMouseDown = null;

      // Build stage: a div acting as the pointing field.
      var stage = Reflx.util.el("div", { class: "rtp-stage" });
      var trialProgressEl = Reflx.util.el("span", { id: "trial-progress", class: "field-desc" });
      var targetEl = Reflx.util.el("div", { class: "rtp-target" });
      targetEl.style.display = "none";
      stage.appendChild(trialProgressEl);
      stage.appendChild(targetEl);
      container.innerHTML = "";
      container.appendChild(stage);
      var statusEl = document.getElementById("stage-status");

      /** Pick a random position within the stage bounds satisfying min-distance constraint. */
      function randomPosition() {
        var w = stage.offsetWidth || 600;
        var h = stage.offsetHeight || 400;
        var margin = targetRadius + 8;
        var minX = margin;
        var maxX = w - margin;
        var minY = margin;
        var maxY = h - margin;
        if (maxX <= minX) maxX = minX + 1;
        if (maxY <= minY) maxY = minY + 1;
        var x, y;
        var attempts = 0;
        do {
          x = minX + Math.random() * (maxX - minX);
          y = minY + Math.random() * (maxY - minY);
          attempts++;
          if (attempts > 100) break;
        } while (
          prevTargetX !== null &&
          prevTargetY !== null &&
          Math.sqrt((x - prevTargetX) * (x - prevTargetX) + (y - prevTargetY) * (y - prevTargetY)) < minDistPx
        );
        return { x: x, y: y };
      }

      function stopListeners() {
        if (currentMouseMove) {
          stage.removeEventListener("mousemove", currentMouseMove);
          currentMouseMove = null;
        }
        if (currentMouseDown) {
          stage.removeEventListener("mousedown", currentMouseDown);
          currentMouseDown = null;
        }
      }

      function clearTimers() {
        if (armTimer !== null) { clearTimeout(armTimer); armTimer = null; }
        if (timeoutTimer !== null) { clearTimeout(timeoutTimer); timeoutTimer = null; }
      }

      function hideTarget() {
        targetEl.style.display = "none";
        targetVisible = false;
      }

      function showTarget(x, y) {
        targetEl.style.display = "block";
        targetEl.style.left = x + "px";
        targetEl.style.top = y + "px";
        targetEl.style.width = targetDiameterPx + "px";
        targetEl.style.height = targetDiameterPx + "px";
        targetEl.style.borderRadius = "50%";
        targetEl.style.position = "absolute";
        targetEl.style.transform = "translate(-50%,-50%)";
        targetVisible = true;
      }

      function armTrial() {
        if (stopped) return;
        if (validTrials.length >= trialCount) { finish(); return; }
        if (scheduleIdx >= scheduleTrials.length) { finish(); return; }

        var trial = scheduleTrials[scheduleIdx++];
        var delay = trial.delay_ms;

        stimulusAt = null;
        firstMoveAt = null;
        trajectory = [];
        hideTarget();
        statusEl.textContent = t("test.random_target.armed");
        handlers.onProgress(validTrials.length, trialCount);

        stopListeners();

        // Pre-stimulus mousedown = false start
        currentMouseDown = function () {
          if (stopped) return;
          if (!targetVisible) {
            clearTimers();
            stopListeners();
            statusEl.textContent = t("runner.test.false_start");
            handlers.onFalseStart();
            setTimeout(armTrial, 500);
          }
        };
        stage.addEventListener("mousedown", currentMouseDown);

        armTimer = setTimeout(function () {
          if (stopped) return;
          var pos = randomPosition();
          targetX = pos.x;
          targetY = pos.y;
          stimulusAt = performance.now();
          firstMoveAt = null;
          trajectory = [];
          showTarget(targetX, targetY);
          statusEl.textContent = t("test.random_target.go");

          // Replace pre-stimulus listener with active-trial listeners
          stopListeners();

          if (recordTrajectory) {
            currentMouseMove = function (e) {
              if (stopped || !targetVisible) return;
              var rect = stage.getBoundingClientRect();
              if (firstMoveAt === null) firstMoveAt = performance.now();
              trajectory.push({ t: performance.now(), x: e.clientX - rect.left, y: e.clientY - rect.top });
            };
            stage.addEventListener("mousemove", currentMouseMove);
          }

          currentMouseDown = function (e) {
            if (stopped || !targetVisible) return;
            var now = performance.now();
            var rect = stage.getBoundingClientRect();
            var mx = e.clientX - rect.left;
            var my = e.clientY - rect.top;
            var dx = mx - targetX;
            var dy = my - targetY;
            var dist = Math.sqrt(dx * dx + dy * dy);

            if (dist > targetRadius) {
              // FALSE_RESPONSE — outside target; participant may keep trying (K3 spec).
              // Don't end the trial, just let them continue.
              return;
            }

            // TRUE_RESPONSE — inside target
            clearTimers();
            stopListeners();
            hideTarget();

            var rtMs = now - stimulusAt;
            // movement_time_ms = first movement to click (falls back to RT if no move tracked)
            var mvMs = (firstMoveAt !== null) ? (now - firstMoveAt) : rtMs;

            // Optional path metrics
            var pathLenPx = null;
            var pathEfficiency = null;
            if (recordTrajectory && trajectory.length >= 2) {
              pathLenPx = 0;
              for (var i = 1; i < trajectory.length; i++) {
                var ddx = trajectory[i].x - trajectory[i - 1].x;
                var ddy = trajectory[i].y - trajectory[i - 1].y;
                pathLenPx += Math.sqrt(ddx * ddx + ddy * ddy);
              }
              var first = trajectory[0];
              var last = trajectory[trajectory.length - 1];
              var straight = Math.sqrt((last.x - first.x) * (last.x - first.x) + (last.y - first.y) * (last.y - first.y));
              pathEfficiency = pathLenPx > 0 ? (straight / pathLenPx) : 1;
            }

            var trialData = {
              reaction_time_ms: rtMs,
              movement_time_ms: mvMs,
              click_error_px: dist,
              result: "TRUE_RESPONSE",
              quality_flag: "VALID"
            };
            if (recordTrajectory) {
              trialData.trajectory_json = JSON.stringify(trajectory);
              trialData.path_length_px = pathLenPx;
              trialData.path_efficiency = pathEfficiency;
            }

            prevTargetX = targetX;
            prevTargetY = targetY;
            validTrials.push({
              index: validTrials.length,
              stimulus_at: stimulusAt,
              responses: { primary: now },
              trial_data: trialData
            });
            statusEl.textContent = "";
            setTimeout(armTrial, 350);
          };
          stage.addEventListener("mousedown", currentMouseDown);

          // Timeout: MISSED_STIMULUS
          timeoutTimer = setTimeout(function () {
            if (stopped || !targetVisible) return;
            clearTimers();
            stopListeners();
            hideTarget();

            var trialData = {
              reaction_time_ms: null,
              movement_time_ms: null,
              click_error_px: null,
              result: "MISSED_STIMULUS",
              quality_flag: "MISSED"
            };
            prevTargetX = targetX;
            prevTargetY = targetY;
            validTrials.push({
              index: validTrials.length,
              stimulus_at: stimulusAt,
              responses: { primary: null },
              trial_data: trialData
            });
            statusEl.textContent = "";
            setTimeout(armTrial, 350);
          }, timeoutMs);

        }, delay);
      }

      function finish() {
        stopped = true;
        clearTimers();
        stopListeners();
        hideTarget();
        handlers.onDone(validTrials);
      }

      armTrial();

      return {
        quit: function () {
          stopped = true;
          clearTimers();
          stopListeners();
          hideTarget();
        }
      };
    }
  };
})(window);

/**
 * Reflexometr — Temporal Prediction / Moving Object Timing (CR-TEST-35, K8).
 *
 * A circle moves rightward across a canvas toward a visible vertical target line.
 * The participant clicks when they predict the circle will reach the line.
 * Measures the signed timing error (click_time - actual_arrival_time).
 *
 * Animation uses requestAnimationFrame + performance.now() — never setTimeout
 * for position updates — per the standing reflex-timing requirement.
 *
 * Schedule fields read:
 *   circle_speed_px_per_ms    — horizontal speed (px/ms, positive)
 *   target_line_x_ratio       — target line position as fraction of canvas width (0..1)
 *   start_x_ratio             — circle starting x as fraction of canvas width (0..1)
 *   prediction_window_ms      — half-window around actual arrival for TRUE_RESPONSE
 *   miss_tolerance_px         — how far past the target line before MISSED_STIMULUS
 *   disappear_before_target_px — if > 0, hide circle when this many px from target
 *   trial_count, timeout_ms
 *   circle_diameter_px        — diameter of the moving circle
 *
 * Event logic:
 *   TRUE_STIMULUS  — the moment the circle center reaches the target line
 *                    (computed deterministically as actualArrivalTime).
 *   TRUE_RESPONSE  — click within prediction_window_ms of actualArrivalTime.
 *   FALSE_RESPONSE — click outside prediction_window_ms.
 *   MISSED_STIMULUS — circle passes target by miss_tolerance_px with no click.
 *
 * Trial submission shape:
 *   { index, stimulus_at: actualArrivalTime, responses: { primary: <ms|null> },
 *     trial_data: { timing_error_ms, absolute_timing_error_ms, spatial_error_px,
 *                   circle_visible_at_click, result, quality_flag } }
 */
(function (global) {
  "use strict";
  var Reflx = global.Reflx = global.Reflx || {};
  Reflx.tests = Reflx.tests || {};

  Reflx.tests["temporal-prediction"] = {
    start: function (container, runInfo, handlers) {
      var t = Reflx.i18n.t;
      var schedule = runInfo.schedule;
      var trialCount = schedule.trial_count;

      var speedPxPerMs = schedule.circle_speed_px_per_ms || 0.3;
      var targetLineXRatio = schedule.target_line_x_ratio || 0.8;
      var startXRatio = schedule.start_x_ratio || 0.05;
      var predWindowMs = schedule.prediction_window_ms || 300;
      var missTolPx = schedule.miss_tolerance_px || 50;
      var disappearBeforePx = schedule.disappear_before_target_px || 0;
      var circleDiamPx = schedule.circle_diameter_px || 40;
      var circleRadius = circleDiamPx / 2;

      var delayRange = schedule.randomize_delay_range_ms || null;
      var fixedIti = schedule.inter_trial_interval_ms || 0;
      var validTrials = [];
      var stopped = false;
      var armTimer = null;
      var rafHandle = null;
      var currentMouseDown = null;

      // Canvas setup
      var canvas = document.createElement("canvas");
      canvas.className = "tp-canvas";
      canvas.style.display = "block";
      canvas.style.width = "100%";
      canvas.style.height = "100%";
      var trialProgressEl = Reflx.util.el("span", { id: "trial-progress", class: "field-desc" });
      var stage = Reflx.util.el("div", { class: "tp-stage" }, [trialProgressEl, canvas]);
      container.innerHTML = "";
      container.appendChild(stage);
      var statusEl = document.getElementById("stage-status");

      function resizeCanvas() {
        var w = stage.offsetWidth || 700;
        var h = stage.offsetHeight || 300;
        canvas.width = w;
        canvas.height = h;
      }
      resizeCanvas();
      var ctx = canvas.getContext("2d");

      function canvasW() { return canvas.width; }
      function canvasH() { return canvas.height; }
      function targetLineX() { return Math.floor(targetLineXRatio * canvasW()); }
      function circleStartX() { return Math.floor(startXRatio * canvasW()); }

      function stopMotion() {
        if (rafHandle !== null) { cancelAnimationFrame(rafHandle); rafHandle = null; }
      }

      function stopMouseListener() {
        if (currentMouseDown) {
          canvas.removeEventListener("mousedown", currentMouseDown);
          currentMouseDown = null;
        }
      }

      function clearTimers() {
        if (armTimer !== null) { clearTimeout(armTimer); armTimer = null; }
      }

      /** Draw the static scene: target line + circle at position cx (NaN = don't draw circle).
       *  circleVisible controls whether the moving circle is rendered. */
      function drawScene(cx, circleVisible) {
        ctx.clearRect(0, 0, canvasW(), canvasH());

        // Draw target line
        var lx = targetLineX();
        ctx.save();
        ctx.strokeStyle = "var(--stimulus-red, #e03030)";
        ctx.lineWidth = 3;
        ctx.setLineDash([6, 4]);
        ctx.beginPath();
        ctx.moveTo(lx, 0);
        ctx.lineTo(lx, canvasH());
        ctx.stroke();
        ctx.restore();

        // Draw moving circle (if visible)
        if (circleVisible && typeof cx === "number" && !isNaN(cx)) {
          var cy = canvasH() / 2;
          ctx.save();
          ctx.fillStyle = "var(--stimulus-green, #30aa30)";
          ctx.beginPath();
          ctx.arc(cx, cy, circleRadius, 0, 2 * Math.PI);
          ctx.fill();
          ctx.restore();
        }
      }

      function armTrial() {
        if (stopped) return;
        if (validTrials.length >= trialCount) { finish(); return; }
        var delay = delayRange
          ? delayRange.min + Math.floor(Math.random() * (delayRange.max - delayRange.min + 1))
          : fixedIti;
        // Per-trial speed override from server if provided; else use schedule-level
        var speed = trial.circle_speed_px_per_ms || speedPxPerMs;

        drawScene(circleStartX(), false);
        statusEl.textContent = t("test.temporal.armed");
        handlers.onProgress(validTrials.length, trialCount);

        stopMouseListener();
        // Pre-motion false-start listener
        currentMouseDown = function () {
          if (stopped) return;
          // Before motion starts — false start
          clearTimers();
          stopMouseListener();
          stopMotion();
          statusEl.textContent = t("runner.test.false_start");
          handlers.onFalseStart();
          setTimeout(armTrial, 500);
        };
        canvas.addEventListener("mousedown", currentMouseDown);

        armTimer = setTimeout(function () {
          if (stopped) return;
          var startX = circleStartX();
          var lx = targetLineX();
          // Compute actual arrival time: time for circle to travel from startX to lx
          var distToTarget = lx - startX;
          var travelMs = distToTarget / speed;
          var motionStart = performance.now();
          var actualArrivalTime = motionStart + travelMs;
          var circleVisible = true;
          var clicked = false;
          var clickTime = null;

          statusEl.textContent = t("test.temporal.go");

          // Replace false-start listener — during motion any click is handled by rAF loop
          stopMouseListener();
          currentMouseDown = function () {
            if (stopped || clicked) return;
            clicked = true;
            clickTime = performance.now();
          };
          canvas.addEventListener("mousedown", currentMouseDown);

          function frame() {
            if (stopped) return;
            var now = performance.now();
            var elapsed = now - motionStart;
            var cx = startX + speed * elapsed;

            // Disappear before target
            if (disappearBeforePx > 0 && (lx - cx) <= disappearBeforePx) {
              circleVisible = false;
            }

            drawScene(cx, circleVisible);

            // Check for click
            if (clicked) {
              rafHandle = null;
              stopMouseListener();
              resolveClick(clickTime, actualArrivalTime, speed, circleVisible, cx);
              return;
            }

            // Miss: circle has passed target by miss_tolerance_px
            if (cx >= lx + missTolPx) {
              rafHandle = null;
              stopMouseListener();
              // MISSED_STIMULUS
              drawScene(NaN, false);
              validTrials.push({
                index: validTrials.length,
                stimulus_at: actualArrivalTime,
                responses: { primary: null },
                trial_data: {
                  timing_error_ms: null,
                  absolute_timing_error_ms: null,
                  spatial_error_px: null,
                  circle_visible_at_click: false,
                  result: "MISSED_STIMULUS",
                  quality_flag: "MISSED"
                }
              });
              statusEl.textContent = "";
              setTimeout(armTrial, 350);
              return;
            }

            rafHandle = requestAnimationFrame(frame);
          }

          rafHandle = requestAnimationFrame(frame);
        }, delay);
      }

      function resolveClick(ct, arrivalTime, speed, circleWasVisible, circlePosAtClick) {
        var lx = targetLineX();
        var timingErrorMs = ct - arrivalTime;
        var absError = Math.abs(timingErrorMs);
        // Spatial error: circle position at click relative to target line (signed, px)
        var spatialErrorPx = circlePosAtClick - lx;
        var withinWindow = absError <= predWindowMs;
        var resultLabel = withinWindow ? "TRUE_RESPONSE" : "FALSE_RESPONSE";
        var qualityFlag = withinWindow ? "VALID" : "FALSE_RESPONSE";

        validTrials.push({
          index: validTrials.length,
          stimulus_at: arrivalTime,
          responses: { primary: ct },
          trial_data: {
            timing_error_ms: timingErrorMs,
            absolute_timing_error_ms: absError,
            spatial_error_px: spatialErrorPx,
            circle_visible_at_click: circleWasVisible,
            result: resultLabel,
            quality_flag: qualityFlag
          }
        });
        drawScene(NaN, false);
        statusEl.textContent = "";
        setTimeout(armTrial, 350);
      }

      function finish() {
        stopped = true;
        clearTimers();
        stopMotion();
        stopMouseListener();
        handlers.onDone(validTrials);
      }

      armTrial();

      return {
        quit: function () {
          stopped = true;
          clearTimers();
          stopMotion();
          stopMouseListener();
        }
      };
    }
  };
})(window);

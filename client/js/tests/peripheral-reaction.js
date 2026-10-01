/**
 * Reflexometr — Peripheral Visual Reaction (CR-TEST-34, K7).
 *
 * A central fixation cross is always visible. After a randomized delay a filled
 * circular stimulus appears at one of the configured peripheral positions
 * (angle_deg + eccentricity_px from the canvas center). The participant responds
 * with a single configured key or a mouse click per response_type.
 *
 * Positions are read from schedule.positions: [{label, angle_deg, eccentricity_px}, ...]
 * Positions are drawn in random order across trials.
 *
 * Event logic:
 *   TRUE_STIMULUS  — peripheral stimulus appears; timestamp via performance.now().
 *   TRUE_RESPONSE  — valid key/click after stimulus within response_window_ms.
 *   FALSE_RESPONSE — key/click before stimulus (false start).
 *   MISSED_STIMULUS — no response within response_window_ms.
 *
 * Trial submission shape:
 *   { index, stimulus_at, responses: { primary: <ms|null> },
 *     trial_data: { stimulus_position_label, stimulus_angle_deg, stimulus_eccentricity_px,
 *                   reaction_time_ms, result, quality_flag } }
 */
(function (global) {
  "use strict";
  var Reflx = global.Reflx = global.Reflx || {};
  Reflx.tests = Reflx.tests || {};

  var DEG_TO_RAD = Math.PI / 180;

  Reflx.tests["peripheral-reaction"] = {
    start: function (container, runInfo, handlers) {
      var t = Reflx.i18n.t;
      var schedule = runInfo.schedule;
      var settings = runInfo.settings;
      var trialCount = schedule.trial_count;
      var responseWindowMs = schedule.response_window_ms || schedule.timeout_ms || 2000;
      var responseType = schedule.response_type || "key"; // "key" | "click"
      var stimDiamPx = schedule.stimulus_diameter_px || 40;
      var stimRadius = stimDiamPx / 2;
      // Positions: [{label, angle_deg, eccentricity_px}, ...]
      var positions = schedule.positions || [
        { label: "left",        angle_deg: 180, eccentricity_px: 150 },
        { label: "right",       angle_deg: 0,   eccentricity_px: 150 },
        { label: "upper-left",  angle_deg: 225, eccentricity_px: 150 },
        { label: "upper-right", angle_deg: 315, eccentricity_px: 150 },
        { label: "lower-left",  angle_deg: 135, eccentricity_px: 150 },
        { label: "lower-right", angle_deg: 45,  eccentricity_px: 150 }
      ];
      // Response key (for "key" mode): use keyboard setting or Space as fallback
      var responseKey = (settings && settings.keyboardKey) || "Space";

      var delayRange = schedule.randomize_delay_range_ms || null;
      var fixedIti = schedule.inter_trial_interval_ms || 0;
      var validTrials = [];
      var stopped = false;
      var armTimer = null;
      var timeoutTimer = null;
      var stimulusAt = null;
      var stimulusVisible = false;
      var currentPosition = null;
      var onKeyDown = null;
      var onMouseDown = null;

      // Canvas for rendering fixation cross + peripheral stimulus
      var canvas = document.createElement("canvas");
      canvas.className = "pr-canvas";
      canvas.style.display = "block";
      canvas.style.width = "100%";
      canvas.style.height = "100%";
      var trialProgressEl = Reflx.util.el("span", { id: "trial-progress", class: "field-desc" });
      var stage = Reflx.util.el("div", { class: "pr-stage" }, [trialProgressEl, canvas]);
      container.innerHTML = "";
      container.appendChild(stage);
      var statusEl = document.getElementById("stage-status");

      // Size canvas to stage
      function resizeCanvas() {
        var w = stage.offsetWidth || 600;
        var h = stage.offsetHeight || 400;
        canvas.width = w;
        canvas.height = h;
      }
      resizeCanvas();
      var ctx = canvas.getContext("2d");

      function centerX() { return canvas.width / 2; }
      function centerY() { return canvas.height / 2; }

      /** Compute pixel position from angle + eccentricity relative to canvas center. */
      function positionToXY(pos) {
        var rad = pos.angle_deg * DEG_TO_RAD;
        return {
          x: centerX() + Math.cos(rad) * pos.eccentricity_px,
          y: centerY() - Math.sin(rad) * pos.eccentricity_px  // Y increases downward
        };
      }

      function drawFixation() {
        
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        var cx = centerX();
        var cy = centerY();
        var armLen = 12;
        ctx.save();
        ctx.strokeStyle = "var(--text, #333)";
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(cx - armLen, cy);
        ctx.lineTo(cx + armLen, cy);
        ctx.moveTo(cx, cy - armLen);
        ctx.lineTo(cx, cy + armLen);
        ctx.stroke();
        ctx.restore();
      }

      function drawFixationAndStimulus(pos) {
        drawFixation();
        var xy = positionToXY(pos);
        ctx.save();
        ctx.fillStyle = "var(--stimulus-red, #e03030)";
        ctx.beginPath();
        ctx.arc(xy.x, xy.y, stimRadius, 0, 2 * Math.PI);
        ctx.fill();
        ctx.restore();
      }

      function hideStimulus() {
        stimulusVisible = false;
        drawFixation();
      }

      function stopListeners() {
        if (onKeyDown) { document.removeEventListener("keydown", onKeyDown); onKeyDown = null; }
        if (onMouseDown) { canvas.removeEventListener("mousedown", onMouseDown); onMouseDown = null; }
      }

      function clearTimers() {
        if (armTimer !== null) { clearTimeout(armTimer); armTimer = null; }
        if (timeoutTimer !== null) { clearTimeout(timeoutTimer); timeoutTimer = null; }
      }

      /** Pick a position for this trial (random from available positions). */
      function pickPosition(trial) {
        if (trial.stimulus_position !== undefined) {
          // Server-resolved position index
          return positions[trial.stimulus_position % positions.length];
        }
        return positions[Math.floor(Math.random() * positions.length)];
      }

      function armTrial() {
        if (stopped) return;
        if (validTrials.length >= trialCount) { finish(); return; }
        var delay = delayRange
          ? delayRange.min + Math.floor(Math.random() * (delayRange.max - delayRange.min + 1))
          : fixedIti;
        var pos = pickPosition(trial);

        stimulusAt = null;
        stimulusVisible = false;
        currentPosition = pos;
        drawFixation();
        statusEl.textContent = t("test.peripheral.armed");
        handlers.onProgress(validTrials.length, trialCount);

        stopListeners();

        function falseStart() {
          clearTimers();
          stopListeners();
          hideStimulus();
          statusEl.textContent = t("runner.test.false_start");
          handlers.onFalseStart();
          setTimeout(armTrial, 500);
        }

        // Pre-stimulus input handlers
        if (responseType === "key") {
          onKeyDown = function (e) {
            if (stopped) return;
            if (!stimulusVisible && e.code === responseKey) falseStart();
          };
          document.addEventListener("keydown", onKeyDown);
        } else {
          onMouseDown = function () {
            if (stopped) return;
            if (!stimulusVisible) falseStart();
          };
          canvas.addEventListener("mousedown", onMouseDown);
        }

        armTimer = setTimeout(function () {
          if (stopped) return;
          stimulusAt = performance.now();
          stimulusVisible = true;
          drawFixationAndStimulus(pos);
          statusEl.textContent = t("test.peripheral.go");

          stopListeners();

          function recordResponse(now) {
            clearTimers();
            stopListeners();
            hideStimulus();
            var rtMs = now - stimulusAt;
            validTrials.push({
              index: validTrials.length,
              stimulus_at: stimulusAt,
              responses: { primary: now },
              trial_data: {
                stimulus_position_label: pos.label,
                stimulus_angle_deg: pos.angle_deg,
                stimulus_eccentricity_px: pos.eccentricity_px,
                reaction_time_ms: rtMs,
                result: "TRUE_RESPONSE",
                quality_flag: "VALID"
              }
            });
            statusEl.textContent = "";
            setTimeout(armTrial, 350);
          }

          if (responseType === "key") {
            onKeyDown = function (e) {
              if (stopped || !stimulusVisible) return;
              if (e.code === responseKey) recordResponse(performance.now());
            };
            document.addEventListener("keydown", onKeyDown);
          } else {
            onMouseDown = function () {
              if (stopped || !stimulusVisible) return;
              recordResponse(performance.now());
            };
            canvas.addEventListener("mousedown", onMouseDown);
          }

          // Timeout: MISSED_STIMULUS
          timeoutTimer = setTimeout(function () {
            if (stopped || !stimulusVisible) return;
            clearTimers();
            stopListeners();
            hideStimulus();
            validTrials.push({
              index: validTrials.length,
              stimulus_at: stimulusAt,
              responses: { primary: null },
              trial_data: {
                stimulus_position_label: pos.label,
                stimulus_angle_deg: pos.angle_deg,
                stimulus_eccentricity_px: pos.eccentricity_px,
                reaction_time_ms: null,
                result: "MISSED_STIMULUS",
                quality_flag: "MISSED"
              }
            });
            statusEl.textContent = "";
            setTimeout(armTrial, 350);
          }, responseWindowMs);

        }, delay);
      }

      function finish() {
        stopped = true;
        clearTimers();
        stopListeners();
        handlers.onDone(validTrials);
      }

      armTrial();

      return {
        quit: function () {
          stopped = true;
          clearTimers();
          stopListeners();
        }
      };
    }
  };
})(window);

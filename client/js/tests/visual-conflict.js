/**
 * Reflexometr — Visual Conflict (CR-TEST-33, K6).
 *
 * Two-phase state machine measuring conflict-resolution cost:
 *
 * 1. PRETRAIN PHASE — a rectangle shown in red or green.
 *    Correct response is color-mapped: red → left arrow, green → right arrow (D25).
 *    Events: TRUE_STIMULUS, TRUE_RESPONSE, FALSE_RESPONSE, MISSED_STIMULUS.
 *
 * 2. PHASE-TRANSITION SCREEN — displayed for `phase_transition_display_ms` ms;
 *    instruction: "Now respond to the SHAPE, ignore the color". Not skippable.
 *
 * 3. MEASUREMENT PHASE — triangle or circle shown in red, green, or gray.
 *    Correct response is shape-mapped: triangle → left, circle → right (D25, fixed).
 *    Events: same as pretrain.
 *    Condition: neutral (gray), congruent (color agrees with shape mapping), conflict (opposes).
 *
 * Shape rendering: reuses shapeToSVG() pattern from choice-reaction-geometry.js (inline SVG).
 *
 * Trial submission shape (custom-KPI family):
 *   { index, stimulus_at, responses: { primary: <ms|null> },
 *     trial_data: { phase, stimulus_shape, stimulus_color, condition,
 *                   response_key, reaction_time_ms, result, quality_flag } }
 */
(function (global) {
  "use strict";
  var Reflx = global.Reflx = global.Reflx || {};
  Reflx.tests = Reflx.tests || {};

  // D25 fixed mappings (not configurable)
  var COLOR_KEY_MAP = { "red": "ArrowLeft", "green": "ArrowRight" };
  var SHAPE_KEY_MAP = { "triangle": "ArrowLeft", "circle": "ArrowRight" };
  var VALID_RESPONSE_KEYS = ["ArrowLeft", "ArrowRight"];

  /** Render a shape as an inline SVG string. Mirrors choice-reaction-geometry.js shapeToSVG. */
  function shapeToSVG(shape, color, sizePx) {
    var s = sizePx || 120;
    var half = s / 2;
    var pad = 8;
    var fill = color === "red" ? "var(--stimulus-red, #e53e3e)"
             : color === "green" ? "var(--stimulus-green, #38a169)"
             : "#888";
    if (shape === "rectangle") {
      var rh = Math.round(s * 0.6);
      return '<svg xmlns="http://www.w3.org/2000/svg" width="' + s + '" height="' + rh + '" viewBox="0 0 ' + s + ' ' + rh + '">'
        + '<rect x="' + pad + '" y="' + pad + '" width="' + (s - pad * 2) + '" height="' + (rh - pad * 2) + '" fill="' + fill + '" rx="4"/></svg>';
    }
    if (shape === "triangle") {
      var base = s - pad;
      var mid = half;
      var pts = mid + "," + pad + " " + pad + "," + base + " " + (s - pad) + "," + base;
      return '<svg xmlns="http://www.w3.org/2000/svg" width="' + s + '" height="' + s + '" viewBox="0 0 ' + s + ' ' + s + '">'
        + '<polygon points="' + pts + '" fill="' + fill + '"/></svg>';
    }
    if (shape === "circle") {
      return '<svg xmlns="http://www.w3.org/2000/svg" width="' + s + '" height="' + s + '" viewBox="0 0 ' + s + ' ' + s + '">'
        + '<circle cx="' + half + '" cy="' + half + '" r="' + (half - pad) + '" fill="' + fill + '"/></svg>';
    }
    // Fallback: filled square
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' + s + '" height="' + s + '" viewBox="0 0 ' + s + ' ' + s + '">'
      + '<rect x="' + pad + '" y="' + pad + '" width="' + (s - pad * 2) + '" height="' + (s - pad * 2) + '" fill="' + fill + '"/></svg>';
  }

  /** Determine condition for a measurement-phase trial (D25). */
  function measurementCondition(shape, color) {
    if (color === "gray") return "neutral";
    var colorKey = COLOR_KEY_MAP[color];
    var shapeKey = SHAPE_KEY_MAP[shape];
    return colorKey === shapeKey ? "congruent" : "conflict";
  }

  Reflx.tests["visual-conflict"] = {
    start: function (container, runInfo, handlers) {
      var t = Reflx.i18n.t;
      var schedule = runInfo.schedule;
      var pretrainCount = schedule.pretrain_trial_count || 10;
      var measurementCount = schedule.trial_count || 20;
      var responseWindowMs = schedule.response_window_ms || schedule.timeout_ms || 3000;
      var phaseTransitionMs = schedule.phase_transition_display_ms || 2000;
      var shapeSizePx = schedule.shape_size_px || 120;
      var delayRange = schedule.randomize_delay_range_ms || null;
      var fixedIti = schedule.inter_trial_interval_ms || 0;

      var PRETRAIN_COLORS = ["red", "green"];
      var MEASUREMENT_SHAPES = ["triangle", "circle"];
      var MEASUREMENT_COLORS = ["red", "green", "gray"];

      var validTrials = [];
      var currentPhase = "pretrain"; // "pretrain" | "transition" | "measurement"
      var stopped = false;
      var armTimer = null;
      var timeoutTimer = null;
      var transitionTimer = null;
      var stimulusAt = null;
      var stimulusVisible = false;
      var currentShape = null;
      var currentColor = null;
      var onKeyDown = null;

      // Build stage DOM
      var shapeEl = Reflx.util.el("div", { class: "vc-shape-display" });
      var trialProgressEl = Reflx.util.el("span", { id: "trial-progress", class: "field-desc" });
      var phaseEl = Reflx.util.el("div", { class: "vc-phase-label" });

      var stage = Reflx.util.el("div", { class: "vc-stage" }, [
        trialProgressEl,
        phaseEl,
        shapeEl
      ]);
      container.innerHTML = "";
      container.appendChild(stage);
      var statusEl = document.getElementById("stage-status");

      function hideShape() {
        shapeEl.innerHTML = "";
        stimulusVisible = false;
        currentShape = null;
        currentColor = null;
      }

      function stopKeyListener() {
        if (onKeyDown) {
          document.removeEventListener("keydown", onKeyDown);
          onKeyDown = null;
        }
      }

      function clearTimers() {
        if (armTimer !== null) { clearTimeout(armTimer); armTimer = null; }
        if (timeoutTimer !== null) { clearTimeout(timeoutTimer); timeoutTimer = null; }
        if (transitionTimer !== null) { clearTimeout(transitionTimer); transitionTimer = null; }
      }

      function randomDelay() {
        if (delayRange) {
          return delayRange.min + Math.floor(Math.random() * (delayRange.max - delayRange.min + 1));
        }
        return fixedIti;
      }

      function pickPretrainStimulus() {
        return { shape: "rectangle", color: PRETRAIN_COLORS[Math.floor(Math.random() * PRETRAIN_COLORS.length)] };
      }

      function pickMeasurementStimulus() {
        var shape = MEASUREMENT_SHAPES[Math.floor(Math.random() * MEASUREMENT_SHAPES.length)];
        var color = MEASUREMENT_COLORS[Math.floor(Math.random() * MEASUREMENT_COLORS.length)];
        return { shape: shape, color: color };
      }

      function countPhase(phase) {
        var n = 0;
        validTrials.forEach(function (tr) { if (tr.trial_data.phase === phase) n++; });
        return n;
      }

      function showTransitionScreen() {
        currentPhase = "transition";
        stopKeyListener();
        clearTimers();
        hideShape();
        // i18n locale strings are trusted content (never user input) — same trust boundary as all other i18n-driven content in the app.
        shapeEl.innerHTML = '<div class="vc-transition-msg">' + t("test.visual_conflict.transition_instruction") + '</div>';
        phaseEl.textContent = "";
        if (statusEl) statusEl.textContent = "";
        transitionTimer = setTimeout(function () {
          if (stopped) return;
          currentPhase = "measurement";
          shapeEl.innerHTML = "";
          armTrial();
        }, phaseTransitionMs);
      }

      function armTrial() {
        if (stopped) return;

        var pretrainDone = countPhase("pretrain");
        var measurementDone = countPhase("measurement");

        if (currentPhase === "pretrain" && pretrainDone >= pretrainCount) {
          showTransitionScreen();
          return;
        }
        if (currentPhase === "measurement" && measurementDone >= measurementCount) {
          finish();
          return;
        }

        var stim = currentPhase === "pretrain" ? pickPretrainStimulus() : pickMeasurementStimulus();
        var delay = randomDelay();

        stimulusAt = null;
        stimulusVisible = false;
        currentShape = null;
        currentColor = null;
        hideShape();

        var progressDone = currentPhase === "pretrain" ? pretrainDone : (pretrainCount + measurementDone);
        var progressTotal = pretrainCount + measurementCount;
        handlers.onProgress(progressDone, progressTotal);

        phaseEl.textContent = currentPhase === "pretrain"
          ? t("test.visual_conflict.pretrain_instruction")
          : "";
        if (statusEl) statusEl.textContent = t("runner.test.countdown_label");

        stopKeyListener();

        armTimer = setTimeout(function () {
          if (stopped) return;
          currentShape = stim.shape;
          currentColor = stim.color;
          stimulusAt = performance.now();
          stimulusVisible = true;
          shapeEl.innerHTML = shapeToSVG(currentShape, currentColor, shapeSizePx);
          if (statusEl) statusEl.textContent = "";

          stopKeyListener();
          onKeyDown = function (e) {
            if (stopped || !stimulusVisible) return;
            var code = e.code;
            var now = performance.now();

            if (VALID_RESPONSE_KEYS.indexOf(code) === -1) return; // ignore non-mapped keys

            clearTimers();
            stopKeyListener();

            var correctKey = currentPhase === "pretrain"
              ? COLOR_KEY_MAP[currentColor]
              : SHAPE_KEY_MAP[currentShape];
            var isCorrect = code === correctKey;
            var rtMs = now - stimulusAt;
            var resultLabel = isCorrect ? "TRUE_RESPONSE" : "FALSE_RESPONSE";
            var qualityFlag = isCorrect ? "VALID" : "FALSE_RESPONSE";
            var condition = currentPhase === "measurement"
              ? measurementCondition(currentShape, currentColor)
              : "pretrain";

            var trialPhase = currentPhase;
            var savedShape = currentShape;
            var savedColor = currentColor;
            var savedStimulusAt = stimulusAt;
            hideShape();

            validTrials.push({
              index: validTrials.length,
              stimulus_at: savedStimulusAt,
              responses: { primary: isCorrect ? now : null },
              trial_data: {
                phase: trialPhase,
                stimulus_shape: savedShape,
                stimulus_color: savedColor,
                condition: condition,
                response_key: code,
                reaction_time_ms: isCorrect ? rtMs : null,
                result: resultLabel,
                quality_flag: qualityFlag
              }
            });

            if (statusEl) statusEl.textContent = "";
            setTimeout(armTrial, 350);
          };
          document.addEventListener("keydown", onKeyDown);

          // Timeout: MISSED_STIMULUS
          timeoutTimer = setTimeout(function () {
            if (stopped || !stimulusVisible) return;
            clearTimers();
            stopKeyListener();

            var trialPhase = currentPhase;
            var savedShape = currentShape;
            var savedColor = currentColor;
            var savedStimulusAt = stimulusAt;
            var condition = trialPhase === "measurement"
              ? measurementCondition(savedShape, savedColor)
              : "pretrain";

            hideShape();

            validTrials.push({
              index: validTrials.length,
              stimulus_at: savedStimulusAt,
              responses: { primary: null },
              trial_data: {
                phase: trialPhase,
                stimulus_shape: savedShape,
                stimulus_color: savedColor,
                condition: condition,
                response_key: null,
                reaction_time_ms: null,
                result: "MISSED_STIMULUS",
                quality_flag: "MISSED"
              }
            });

            if (statusEl) statusEl.textContent = "";
            setTimeout(armTrial, 350);
          }, responseWindowMs);

        }, delay);
      }

      function finish() {
        stopped = true;
        clearTimers();
        stopKeyListener();
        hideShape();
        // Submit only measurement-phase trials, re-indexed 0..N-1
        var measurementTrials = [];
        validTrials.forEach(function (tr) {
          if (tr.trial_data.phase === "measurement") {
            var copy = {
              index: measurementTrials.length,
              stimulus_at: tr.stimulus_at,
              responses: tr.responses,
              trial_data: tr.trial_data
            };
            measurementTrials.push(copy);
          }
        });
        handlers.onDone(measurementTrials);
      }

      armTrial();

      return {
        quit: function () {
          stopped = true;
          clearTimers();
          stopKeyListener();
          hideShape();
        }
      };
    }
  };
})(window);

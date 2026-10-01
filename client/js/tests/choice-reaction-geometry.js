/**
 * Reflexometr — Choice Reaction: Geometry (CR-TEST-32, K5).
 *
 * A geometric shape (triangle or circle) appears center-screen. The participant
 * presses the mapped key for that shape. Introduces a visual-discrimination and
 * response-selection stage without language.
 *
 * Key mapping is read from schedule.key_mapping, a plain object whose keys are
 * shape names and values are the expected KeyboardEvent.code strings, e.g.:
 *   { "triangle": "ArrowLeft", "circle": "ArrowRight" }
 *
 * Event logic:
 *   TRUE_STIMULUS  — shape becomes visible; timestamp via performance.now().
 *   TRUE_RESPONSE  — correct mapped key pressed within response_window_ms.
 *   FALSE_RESPONSE — wrong key from the mapping's value set (wrong choice).
 *   INVALID_RESPONSE — any other key press.
 *   MISSED_STIMULUS — no valid key within response_window_ms.
 *
 * Trial submission shape:
 *   { index, stimulus_at, responses: { primary: <ms|null> },
 *     trial_data: { stimulus_shape, response_key, reaction_time_ms, result, quality_flag } }
 */
(function (global) {
  "use strict";
  var Reflx = global.Reflx = global.Reflx || {};
  Reflx.tests = Reflx.tests || {};

  /** Render a shape as an inline SVG string. */
  function shapeToSVG(shape, sizePx) {
    var s = sizePx || 120;
    var half = s / 2;
    var pad = 8;
    if (shape === "triangle") {
      // Equilateral triangle centered in the SVG viewport
      var tip = pad;
      var base = s - pad;
      var mid = s / 2;
      var pts = mid + "," + tip + " " + pad + "," + base + " " + (s - pad) + "," + base;
      return '<svg xmlns="http://www.w3.org/2000/svg" width="' + s + '" height="' + s + '" viewBox="0 0 ' + s + ' ' + s + '">'
        + '<polygon points="' + pts + '" fill="currentColor"/></svg>';
    }
    if (shape === "circle") {
      return '<svg xmlns="http://www.w3.org/2000/svg" width="' + s + '" height="' + s + '" viewBox="0 0 ' + s + ' ' + s + '">'
        + '<circle cx="' + half + '" cy="' + half + '" r="' + (half - pad) + '" fill="currentColor"/></svg>';
    }
    // Fallback: square
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' + s + '" height="' + s + '" viewBox="0 0 ' + s + ' ' + s + '">'
      + '<rect x="' + pad + '" y="' + pad + '" width="' + (s - pad * 2) + '" height="' + (s - pad * 2) + '" fill="currentColor"/></svg>';
  }

  Reflx.tests["choice-reaction-geometry"] = {
    start: function (container, runInfo, handlers) {
      var t = Reflx.i18n.t;
      var schedule = runInfo.schedule;
      var trialCount = schedule.trial_count;
      var responseWindowMs = schedule.response_window_ms || schedule.timeout_ms || 3000;
      var keyMapping = schedule.key_mapping || { "triangle": "ArrowLeft", "circle": "ArrowRight" };
      var shapes = schedule.shapes || Object.keys(keyMapping);
      var shapeSizePx = schedule.shape_size_px || 120;
      var showMappingDuringMeasurement = schedule.show_mapping_during_measurement !== false;

      // Build a reverse map: key code → shape name, to detect wrong-key responses
      var keyToShape = {};
      Object.keys(keyMapping).forEach(function (shape) {
        keyToShape[keyMapping[shape]] = shape;
      });
      // Set of all valid response key codes (for detecting wrong-key vs invalid)
      var validKeys = Object.keys(keyToShape);

      var delayRange = schedule.randomize_delay_range_ms || null;
      var fixedIti = schedule.inter_trial_interval_ms || 0;
      var validTrials = [];
      var stopped = false;
      var armTimer = null;
      var timeoutTimer = null;
      var stimulusAt = null;
      var currentShape = null;
      var stimulusVisible = false;
      var onKeyDown = null;

      // Build stage
      var shapeEl = Reflx.util.el("div", { class: "crg-shape-display" });
      var trialProgressEl = Reflx.util.el("span", { id: "trial-progress", class: "field-desc" });
      // Mapping instruction panel (shown unless show_mapping_during_measurement is false)
      var mappingEl = Reflx.util.el("div", { class: "crg-mapping" });
      mappingEl.innerHTML = buildMappingHTML(keyMapping, t);

      var stage = Reflx.util.el("div", { class: "crg-stage" }, [
        trialProgressEl,
        shapeEl,
        mappingEl
      ]);
      container.innerHTML = "";
      container.appendChild(stage);
      var statusEl = document.getElementById("stage-status");

      function buildMappingHTML(km, _t) {
        var parts = [];
        Object.keys(km).forEach(function (shape) {
          parts.push(shape + " → " + km[shape]);
        });
        return "<span class=\"crg-mapping-hint\">" + parts.join(" &nbsp;|&nbsp; ") + "</span>";
      }

      function hideShape() {
        shapeEl.innerHTML = "";
        stimulusVisible = false;
        currentShape = null;
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
      }

      function pickShape() {
        return shapes[Math.floor(Math.random() * shapes.length)];
      }

      function armTrial() {
        if (stopped) return;
        if (validTrials.length >= trialCount) { finish(); return; }
        var delay = delayRange
          ? delayRange.min + Math.floor(Math.random() * (delayRange.max - delayRange.min + 1))
          : fixedIti;
        var trialShape = pickShape();

        stimulusAt = null;
        stimulusVisible = false;
        currentShape = null;
        hideShape();
        mappingEl.style.display = showMappingDuringMeasurement ? "" : "none";
        statusEl.textContent = t("test.choice_geometry.armed");
        handlers.onProgress(validTrials.length, trialCount);

        stopKeyListener();
        // Pre-stimulus keydown = false start
        onKeyDown = function (e) {
          if (stopped) return;
          if (!stimulusVisible && validKeys.indexOf(e.code) !== -1) {
            clearTimers();
            stopKeyListener();
            hideShape();
            statusEl.textContent = t("runner.test.false_start");
            handlers.onFalseStart();
            setTimeout(armTrial, 500);
          }
        };
        document.addEventListener("keydown", onKeyDown);

        armTimer = setTimeout(function () {
          if (stopped) return;
          currentShape = trialShape;
          stimulusAt = performance.now();
          stimulusVisible = true;
          shapeEl.innerHTML = shapeToSVG(currentShape, shapeSizePx);
          statusEl.textContent = t("test.choice_geometry.go");

          // Replace listener with active-trial listener
          stopKeyListener();
          onKeyDown = function (e) {
            if (stopped || !stimulusVisible) return;
            var code = e.code;
            var now = performance.now();

            // Determine response category
            var isCorrect = (keyMapping[currentShape] === code);
            var isWrongMapped = !isCorrect && validKeys.indexOf(code) !== -1;
            var isInvalid = !isCorrect && !isWrongMapped;

            if (isInvalid) {
              // INVALID_RESPONSE — key not in the mapping at all, ignore
              return;
            }

            clearTimers();
            stopKeyListener();
            hideShape();

            var rtMs = isCorrect ? (now - stimulusAt) : null;
            var resultLabel = isCorrect ? "TRUE_RESPONSE" : "FALSE_RESPONSE";
            var qualityFlag = isCorrect ? "VALID" : "FALSE_RESPONSE";

            validTrials.push({
              index: validTrials.length,
              stimulus_at: stimulusAt,
              responses: { primary: isCorrect ? now : null },
              trial_data: {
                stimulus_shape: currentShape,
                response_key: code,
                reaction_time_ms: rtMs,
                result: resultLabel,
                quality_flag: qualityFlag
              }
            });
            statusEl.textContent = "";
            setTimeout(armTrial, 350);
          };
          document.addEventListener("keydown", onKeyDown);

          // Timeout: MISSED_STIMULUS
          timeoutTimer = setTimeout(function () {
            if (stopped || !stimulusVisible) return;
            clearTimers();
            stopKeyListener();
            hideShape();
            validTrials.push({
              index: validTrials.length,
              stimulus_at: stimulusAt,
              responses: { primary: null },
              trial_data: {
                stimulus_shape: currentShape,
                response_key: null,
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
        stopKeyListener();
        hideShape();
        handlers.onDone(validTrials);
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

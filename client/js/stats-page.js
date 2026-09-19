/**
 * Reflexometr — Personal Stats dashboard (CR-STATS-01), enhanced by CR-STATS-03
 * with a trend sparkline, a vertical distribution histogram (with a peer-average
 * reference line folded in — no separate percentile gauge, consolidated during
 * Sprint 3 design review), and a client-side "Download as CSV" export.
 *
 * Different versions of the "same" r-test always render as separate entries — never
 * combined into one number/chart (CR-STATS-01 acceptance criterion 1).
 *
 * CR-AUTH-01 follow-up: `GET /r-tests/{slug}/versions/{version}/history` requires
 * auth — the whole page is gated behind login.
 *
 * Contract gap (flagged in SPRINT1_REPORT.md): the public `GET /r-tests` only
 * returns each r-test's *current* version number, not a list of every version
 * number that ever existed — there is no non-admin endpoint to discover which
 * older version numbers a user might have results under. This page works around
 * that by probing version numbers `1..current_version` (versions are sequential
 * integers per CONTRACT.md's admin import behavior) and only rendering the ones
 * that come back with a non-empty history. A dedicated "my result versions"
 * endpoint would be a cleaner fix — recommended to ServerTeam/ProductOwner.
 *
 * CR-STATS-03 notes (see SPRINT3_REPORT.md for the full write-up):
 * - `GET /results/{id}/comparison` (CONTRACT.md) returns only `percentile`/`rank`
 *   for ONE specific result — there is no "peer average reaction time in ms"
 *   field anywhere in the contract. To draw the histogram's dashed peer-average
 *   line at a real ms position (not a fabricated one), this page calls
 *   `comparison` for just the fastest, slowest, and most-recent of the user's own
 *   entries per version (bounded to <=3 calls per card, not per run — keeps
 *   render cost flat even on 100+ run histories) and linearly interpolates the ms
 *   value where percentile == 50 between whichever pair of those points bracket
 *   it. This is a real-data-grounded approximation, not the true peer mean —
 *   flagged to the ProductOwner as a candidate contract addition.
 * - The CSV's `percentile_vs_peers` column calls `comparison` per row instead,
 *   which is only ever done on-demand behind the "Download as CSV" click (not on
 *   page render), so it doesn't affect the render-time performance budget.
 * - The CSV's `hand` / `dominant_hand_recorded` columns: CONTRACT.md's history
 *   endpoint returns an opaque `summary` object with no documented sub-schema
 *   (submit's 201 response shows `summary: { overall: {...}, channels: {...},
 *   dominant_minus_nondominant_ms? }` with `{...}` left unspecified), and neither
 *   history nor comparison expose the historical `dominant_hand` value recorded
 *   at submission time. This page best-effort reads `summary.dominant_hand` /
 *   `summary.dominantHand` if present, else leaves the column blank — see
 *   SPRINT3_REPORT.md for the full flag to ServerTeam/ProductOwner.
 */
(function () {
  "use strict";
  var Reflx = window.Reflx;
  var t = Reflx.i18n.t;
  var api = Reflx.api;

  function fmtMs(v) { return v === null || v === undefined ? "—" : Reflx.i18n.formatNumber(v, { maximumFractionDigits: 0 }) + " ms"; }
  function isNum(v) { return typeof v === "number" && !isNaN(v); }

  /** All rendered (r-test, version) groups this page render — used by the CSV export. */
  var allGroups = [];
  /** result_id -> Promise<comparison response>, shared between the histogram's peer-reference lookup and the CSV export so neither re-fetches what the other already has. */
  var comparisonCache = {};
  function getComparisonCached(resultId) {
    if (!comparisonCache[resultId]) comparisonCache[resultId] = api.getComparison(resultId);
    return comparisonCache[resultId];
  }

  // ------------------------------------------------------------ SVG sparkline

  function svgEl(tag, attrs) {
    var node = document.createElementNS("http://www.w3.org/2000/svg", tag);
    Object.keys(attrs || {}).forEach(function (k) { node.setAttribute(k, attrs[k]); });
    return node;
  }

  function buildSparkline(values) {
    var W = 300, H = 54, PAD = 4;
    var svg = svgEl("svg", { viewBox: "0 0 " + W + " " + H, preserveAspectRatio: "none", "aria-hidden": "true" });
    if (!values.length) return svg;
    if (values.length === 1) {
      svg.appendChild(svgEl("circle", { cx: String(W / 2), cy: String(H / 2), r: "4", fill: "var(--color-accent)" }));
      return svg;
    }
    var min = Math.min.apply(null, values), max = Math.max.apply(null, values);
    var span = (max - min) || 1;
    var stepX = (W - 2 * PAD) / (values.length - 1);
    var points = values.map(function (v, i) {
      var x = PAD + i * stepX;
      var norm = (v - min) / span; // 0 = smallest ms value, 1 = largest ms value
      var y = PAD + (1 - norm) * (H - 2 * PAD); // larger ms value draws higher on the chart (matches runner.js's bar-chart convention: pct = value/max, taller bar = larger value)
      return x.toFixed(1) + "," + y.toFixed(1);
    });
    svg.appendChild(svgEl("polyline", {
      points: points.join(" "), fill: "none", stroke: "var(--color-accent)",
      "stroke-width": "2.5", "stroke-linecap": "round", "stroke-linejoin": "round"
    }));
    var last = points[points.length - 1].split(",");
    svg.appendChild(svgEl("circle", { cx: last[0], cy: last[1], r: "4", fill: "var(--color-accent)" }));
    return svg;
  }

  function renderTrendBlock(entries) {
    var values = entries.map(function (e) { return e.primary_metric_ms; }).filter(isNum);
    var wrap = Reflx.util.el("div", { class: "stat-block" }, [
      Reflx.util.el("p", { class: "stat-block-title" }, [t("stats.chart.trend_label")])
    ]);
    var row = Reflx.util.el("div", { class: "sparkline-row" }, [buildSparkline(values)]);
    var latest = values.length ? values[values.length - 1] : null;
    row.appendChild(Reflx.util.el("div", { class: "spark-latest" }, [
      latest !== null ? Reflx.i18n.formatNumber(latest, { maximumFractionDigits: 0 }) : "—",
      Reflx.util.el("span", {}, [t("stats.chart.trend_unit_label")])
    ]));
    wrap.appendChild(row);
    return wrap;
  }

  // ------------------------------------------------------------ Distribution histogram + peer-average reference line

  /** Bucket count follows a simple sqrt-of-n heuristic, clamped to a readable 2-8 range. */
  function computeHistogram(values, peerAvgMs) {
    var domainMin = Math.min.apply(null, values), domainMax = Math.max.apply(null, values);
    if (typeof peerAvgMs === "number") {
      domainMin = Math.min(domainMin, peerAvgMs);
      domainMax = Math.max(domainMax, peerAvgMs);
    }
    if (domainMin === domainMax) {
      var pad = Math.max(10, Math.abs(domainMin) * 0.1) || 10;
      domainMin -= pad; domainMax += pad;
    }
    var n = values.length;
    var bucketCount = n <= 1 ? 1 : Math.max(2, Math.min(8, Math.round(Math.sqrt(n))));
    var width = (domainMax - domainMin) / bucketCount;
    var buckets = [];
    for (var i = 0; i < bucketCount; i++) buckets.push({ x0: domainMin + i * width, x1: domainMin + (i + 1) * width, count: 0 });
    function bucketIndexFor(v) { return Reflx.util.clamp(Math.floor((v - domainMin) / width), 0, bucketCount - 1); }
    values.forEach(function (v) { buckets[bucketIndexFor(v)].count++; });
    var refBucketIndex = typeof peerAvgMs === "number" ? bucketIndexFor(peerAvgMs) : null;
    return { buckets: buckets, bucketCount: bucketCount, refBucketIndex: refBucketIndex };
  }

  /**
   * Positions every currently-mounted `.vhist-refline` at the horizontal center of
   * its target `.vhist-bar-col` (matched via the `data-target-idx` / `data-idx`
   * pair set in renderDistributionBlock), using measured pixel geometry instead of
   * a percentage-of-container-width formula. Must be called once after a
   * distribution block is attached to the document (elements detached from the
   * document have no layout box, so getBoundingClientRect() would read all-zero),
   * and again whenever the layout may have changed width (window resize).
   *
   * CR-STATS-03 (reopened, defect 1): also clamps the line's `.vhist-refline-tag`
   * label so its full measured width stays within `.vhist-scroll`'s visible
   * bounds — previously the tag centered itself on the line's x-coordinate via a
   * CSS `transform: translateX(-50%)` with no awareness of the container edges,
   * so a line near the very start/end of the plot pushed roughly half the label
   * outside the scroll box, where it silently clipped (see the CSS comment on
   * `.vhist-refline-tag`). The line itself (the dashed vertical marker) keeps
   * centering on the target bucket column unchanged — only the tag's own `left`
   * is independently clamped.
   */
  function alignRefLines() {
    // Two passes (measure all, then write all) to avoid layout thrashing — reading
    // getBoundingClientRect() after writing a style forces a synchronous reflow,
    // and this runs once per rendered version card's reference line.
    var updates = [];
    Reflx.util.qsa(".vhist-refline[data-target-idx]").forEach(function (line) {
      var plot = line.parentElement;
      var scroll = plot && plot.parentElement; // .vhist-scroll, the actual clipping/visible box
      if (!plot || !scroll) return;
      var col = plot.querySelector('.vhist-bar-col[data-idx="' + line.getAttribute("data-target-idx") + '"]');
      if (!col) return;
      var tag = line.querySelector(".vhist-refline-tag");
      if (!tag) return;

      var plotRect = plot.getBoundingClientRect();
      var colRect = col.getBoundingClientRect();
      var scrollRect = scroll.getBoundingClientRect();
      var tagWidth = tag.getBoundingClientRect().width;
      updates.push({ line: line, tag: tag, plotRect: plotRect, colRect: colRect, scrollRect: scrollRect, tagWidth: tagWidth });
    });

    updates.forEach(function (u) {
      // Offset relative to .vhist-plot's own box (the refline's containing block),
      // not the viewport — stays correct regardless of .vhist-scroll's current
      // horizontal scroll offset, since plot and its descendants scroll together.
      var centerPx = (u.colRect.left + u.colRect.right) / 2 - u.plotRect.left;
      u.line.style.left = centerPx + "px";

      // Desired (unclamped) position: tag centered on the line's x, expressed as
      // an offset from .vhist-plot's left edge (tag's own containing block is
      // .vhist-refline, which is itself positioned at centerPx within plot).
      var desiredLeftPx = centerPx - u.tagWidth / 2; // relative to plot
      var minLeftPx = u.scrollRect.left - u.plotRect.left; // plot's own left edge may itself be scrolled past the visible box start
      var maxLeftPx = u.scrollRect.right - u.plotRect.left - u.tagWidth;
      // minLeftPx <= maxLeftPx always holds here: .vhist-scroll is always wider
      // than a single reference-line tag in this layout. If that assumption ever
      // breaks (e.g. a much narrower container), clamp() needs an explicit
      // narrower-than-tag fallback, not a silent Math.min/Math.max reorder.
      var clampedLeftPx = Reflx.util.clamp(desiredLeftPx, minLeftPx, maxLeftPx);
      u.tag.style.left = (clampedLeftPx - centerPx) + "px"; // tag.style.left is relative to .vhist-refline (positioned at centerPx)
    });
  }

  var alignRefLinesResizeTimer = null;
  window.addEventListener("resize", function () {
    if (alignRefLinesResizeTimer) clearTimeout(alignRefLinesResizeTimer);
    alignRefLinesResizeTimer = setTimeout(alignRefLines, 100);
  });

  function renderDistributionBlock(values, peerAvgMs, betterThanPct) {
    var wrap = Reflx.util.el("div", { class: "stat-block" }, [
      Reflx.util.el("p", { class: "stat-block-title" }, [t("stats.chart.distribution_label")])
    ]);
    if (!values.length) return wrap;

    var hist = computeHistogram(values, peerAvgMs);
    var maxCount = Math.max.apply(null, hist.buckets.map(function (b) { return b.count; })) || 1;

    var plot = Reflx.util.el("div", { class: "vhist-plot" });

    // Reference line: centered on the bucket the peer average actually falls into
    // (not an arbitrary offset) — CR-STATS-03's replacement for the old standalone
    // percentile gauge, folded directly into the histogram.
    //
    // Positioning note (fix for the "line renders under the wrong bucket" defect):
    // `.vhist-bar-col` is capped at `max-width:72px` (client/css/style.css) so the
    // approved layout keeps readable, non-stretched bars on wide screens. That means
    // `.vhist-plot` (the flex row) is very often wider than the bar columns actually
    // occupy — flex's default `justify-content:flex-start` leaves the leftover space
    // unconsumed at the trailing edge instead of stretching columns to fill it. A
    // naive `left: (index+0.5)/bucketCount * 100%` is a percentage of the FULL plot
    // width, which no longer lines up with where the target column actually sits once
    // that unused trailing space exists (verified analytically: at bucketCount=6 on a
    // ~880px plot, columns only span ~512px, so the naive formula lands one bucket too
    // far right — matching the reported screenshot). It also doesn't self-correct on
    // resize or account for the mobile `gap` shrink in the `max-width:480px` media
    // query. So instead: leave `left` unset here and position the line from *measured*
    // pixel geometry (`alignRefLines()`, below) once this block is actually attached
    // to the document — robust to viewport width, bucket count, and horizontal scroll
    // state (the line's `left` is relative to `.vhist-plot`'s own box, so scrolling
    // `.vhist-scroll` moves plot and line together and never invalidates it).
    if (hist.refBucketIndex !== null) {
      var tagChildren = [Reflx.util.el("span", { class: "line1" }, [t("stats.chart.peer_avg", { value: fmtMs(peerAvgMs) })])];
      if (typeof betterThanPct === "number") {
        tagChildren.push(Reflx.util.el("span", { class: "line2" }, [t("stats.chart.better_than", { pct: betterThanPct })]));
      }
      plot.appendChild(Reflx.util.el("div", { class: "vhist-refline", "data-target-idx": String(hist.refBucketIndex) }, [
        Reflx.util.el("span", { class: "vhist-refline-tag" }, tagChildren)
      ]));
    }

    hist.buckets.forEach(function (b, i) {
      var hPct = Math.max(4, Math.round((b.count / maxCount) * 100));
      plot.appendChild(Reflx.util.el("div", { class: "vhist-bar-col", "data-idx": String(i), style: "--h:" + hPct + "%;" }, [
        Reflx.util.el("span", { class: "vhist-count" }, [String(b.count)]),
        Reflx.util.el("div", { class: "vhist-bar" })
      ]));
    });

    var axis = Reflx.util.el("div", { class: "vhist-axis" });
    hist.buckets.forEach(function (b) {
      axis.appendChild(Reflx.util.el("span", {}, [
        Reflx.i18n.formatNumber(Math.round(b.x0), { maximumFractionDigits: 0 }) + "–" +
        Reflx.i18n.formatNumber(Math.round(b.x1), { maximumFractionDigits: 0 }) + "ms"
      ]));
    });

    var scrollWrap = Reflx.util.el("div", { class: "vhist-scroll" }, [plot, axis]);
    wrap.appendChild(scrollWrap);

    if (hist.refBucketIndex === null) {
      wrap.appendChild(Reflx.util.el("p", { class: "field-desc" }, [t("stats.chart.no_peer_data")]));
    }
    return wrap;
  }

  /**
   * Derives {peerAvgMs, betterThanPct} for one r-test/version's history using only
   * the existing `comparison` endpoint, called for at most 3 of the user's own
   * entries (fastest, slowest, most recent) — see the file-header note above.
   */
  function computePeerReference(entries) {
    var withMs = entries.filter(function (e) { return isNum(e.primary_metric_ms); });
    if (!withMs.length) return Promise.resolve({ peerAvgMs: null, betterThanPct: null });

    var sortedByMs = withMs.slice().sort(function (a, b) { return a.primary_metric_ms - b.primary_metric_ms; });
    var minEntry = sortedByMs[0];
    var maxEntry = sortedByMs[sortedByMs.length - 1];
    var latestEntry = withMs[withMs.length - 1];

    var byId = {};
    [minEntry, maxEntry, latestEntry].forEach(function (e) { byId[e.result_id] = e; });
    var ids = Object.keys(byId);

    return Promise.all(ids.map(function (id) {
      return getComparisonCached(id).then(function (res) { return { id: id, ms: byId[id].primary_metric_ms, res: res }; });
    })).then(function (results) {
      var byIdRes = {};
      results.forEach(function (r) { byIdRes[r.id] = r; });

      var latestR = byIdRes[String(latestEntry.result_id)] || byIdRes[latestEntry.result_id];
      var betterThanPct = (latestR && latestR.res.ok && isNum(latestR.res.data.percentile))
        ? Math.round(latestR.res.data.percentile) : null;
      // CR-STATS-08: peer variability figure, read straight off the latest entry's
      // comparison response (`peer_sd_ms_median` — CONTRACT.md v1.5) — not derived
      // or recomputed client-side.
      var yourSdMs = (latestR && latestR.res.ok && isNum(latestR.res.data.your_sd_ms)) ? latestR.res.data.your_sd_ms : null;
      var peerSdMsMedian = (latestR && latestR.res.ok && isNum(latestR.res.data.peer_sd_ms_median)) ? latestR.res.data.peer_sd_ms_median : null;

      var pts = results
        .filter(function (r) { return r.res.ok && isNum(r.res.data.percentile); })
        .map(function (r) { return { ms: r.ms, pct: r.res.data.percentile }; })
        .sort(function (a, b) { return a.ms - b.ms; });

      var peerAvgMs = null;
      if (pts.length >= 2) {
        var lo = null, hi = null;
        for (var i = 0; i < pts.length - 1; i++) {
          var a = pts[i], b = pts[i + 1];
          if ((a.pct - 50) * (b.pct - 50) <= 0 && a.pct !== b.pct) { lo = a; hi = b; break; }
        }
        if (lo && hi) {
          var frac = (50 - lo.pct) / (hi.pct - lo.pct);
          peerAvgMs = lo.ms + frac * (hi.ms - lo.ms);
        } else {
          // The 50th percentile falls outside this user's own observed range — clamp
          // to the nearer endpoint rather than extrapolating past real data.
          peerAvgMs = pts[0].pct < 50 ? pts[0].ms : pts[pts.length - 1].ms;
        }
      } else if (pts.length === 1) {
        peerAvgMs = pts[0].ms;
      }

      return { peerAvgMs: peerAvgMs, betterThanPct: betterThanPct, yourSdMs: yourSdMs, peerSdMsMedian: peerSdMsMedian };
    });
  }

  /**
   * CR-STATS-08: peer variability figure, reusing the existing `.vhist-*` visual
   * language (a `.stat-block` line, same family as the trend/distribution blocks
   * above) rather than a new chart type. Renders nothing if neither figure is
   * available (fewer than 2 valid readings for this user, or no peer data yet).
   */
  function renderVariabilityBlock(yourSdMs, peerSdMsMedian) {
    if (yourSdMs === null && peerSdMsMedian === null) return null;
    var wrap = Reflx.util.el("div", { class: "stat-block" }, [
      Reflx.util.el("p", { class: "stat-block-title" }, [t("stats.chart.variability_label")])
    ]);
    var row = Reflx.util.el("div", { class: "field-row" }, [
      Reflx.util.el("label", {}, [t("stats.chart.variability_yours")]),
      Reflx.util.el("span", {}, [yourSdMs !== null ? fmtMs(yourSdMs) : "—"])
    ]);
    wrap.appendChild(row);
    var peerRow = Reflx.util.el("div", { class: "field-row" }, [
      Reflx.util.el("label", {}, [t("stats.chart.variability_peer")]),
      Reflx.util.el("span", {}, [peerSdMsMedian !== null ? fmtMs(peerSdMsMedian) : t("stats.chart.no_peer_data")])
    ]);
    wrap.appendChild(peerRow);
    return wrap;
  }

  // ------------------------------------------------------------ CSV export (CR-STATS-03)

  function csvEscape(v) {
    v = v === null || v === undefined ? "" : String(v);
    return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
  }
  function csvRow(cols) { return cols.map(csvEscape).join(","); }

  function downloadTextFile(filename, text) {
    var blob = new Blob([text], { type: "text/csv;charset=utf-8;" });
    var url = URL.createObjectURL(blob);
    var a = document.createElement("a");
    a.href = url; a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
  }

  /** Best-effort only — see the file-header contract-gap note; blank when unknown. */
  function extractDominantHand(entry) {
    var s = entry.summary || {};
    if (typeof s.dominant_hand === "string") return s.dominant_hand;
    if (typeof s.dominantHand === "string") return s.dominantHand;
    return "";
  }

  function exportFullHistoryCsv(btn) {
    var original = btn.textContent;
    btn.disabled = true;
    btn.textContent = t("stats.csv.generating");

    var jobs = [];
    allGroups.forEach(function (g) {
      g.entries.forEach(function (e) { jobs.push({ group: g, entry: e }); });
    });

    var BATCH = 12;
    var rows = [];
    function processBatch(start) {
      var batch = jobs.slice(start, start + BATCH);
      if (!batch.length) return Promise.resolve();
      return Promise.all(batch.map(function (job) {
        return getComparisonCached(job.entry.result_id).then(function (res) {
          var pct = (res.ok && isNum(res.data.percentile)) ? Math.round(res.data.percentile) : "";
          var isTwoHand = job.group.r.slug === "two-hand-reaction";
          var hand = isTwoHand ? extractDominantHand(job.entry) : "";
          rows.push([
            job.entry.created_at,
            job.group.name,
            job.group.version,
            isNum(job.entry.primary_metric_ms) ? job.entry.primary_metric_ms : "",
            hand,
            hand,
            pct
          ]);
        });
      })).then(function () { return processBatch(start + BATCH); });
    }

    processBatch(0).then(function () {
      rows.sort(function (a, b) { return new Date(a[0]) - new Date(b[0]); });
      var lines = [["date", "r_test_name", "version", "reaction_time", "hand", "dominant_hand_recorded", "percentile_vs_peers"]]
        .concat(rows)
        .map(csvRow);
      downloadTextFile("reflexometr-history.csv", lines.join("\r\n"));
      btn.disabled = false;
      btn.textContent = original;
    });
  }

  // ------------------------------------------------------------ page

  function showGate() {
    var loggedIn = Reflx.session.isLoggedIn();
    document.getElementById("login-required-notice").style.display = loggedIn ? "none" : "block";
    document.getElementById("stats-content").style.display = loggedIn ? "block" : "none";
    return loggedIn;
  }

  function renderVersionCard(box, r, name, version, entries) {
    // Defensive chronological sort — existing code elsewhere (runner.js's trend
    // list) trusts the API's given order; this guards the same assumption here.
    var sorted = entries.slice().sort(function (a, b) { return new Date(a.created_at) - new Date(b.created_at); });

    var head = Reflx.util.el("div", { class: "stats-card-head" }, [
      Reflx.util.el("div", {}, [
        Reflx.util.el("h3", {}, [name + " — " + t("stats.version_label", { v: version })]),
        Reflx.util.el("span", { class: "chip" }, [t("stats.runs_count", { n: sorted.length })])
      ]),
      Reflx.util.el("button", {
        class: "btn secondary", type: "button",
        onclick: function (e) { exportFullHistoryCsv(e.currentTarget); }
      }, [t("stats.download_csv")])
    ]);

    var card = Reflx.util.el("div", { class: "card" }, [head, renderTrendBlock(sorted)]);

    var distPlaceholder = Reflx.util.el("div", { class: "stat-block" }, [
      Reflx.util.el("p", { class: "stat-block-title" }, [t("stats.chart.distribution_label")]),
      Reflx.util.el("p", { class: "field-desc" }, [t("common.loading")])
    ]);
    card.appendChild(distPlaceholder);
    box.appendChild(card);

    var values = sorted.map(function (e) { return e.primary_metric_ms; }).filter(isNum);
    computePeerReference(sorted).then(function (ref) {
      var distBlock = renderDistributionBlock(values, ref.peerAvgMs, ref.betterThanPct);
      if (distPlaceholder.parentNode === card) card.replaceChild(distBlock, distPlaceholder);
      // Only meaningful once distBlock is attached to the document (see alignRefLines
      // doc comment) — replaceChild above just did that.
      alignRefLines();

      var variabilityBlock = renderVariabilityBlock(ref.yourSdMs, ref.peerSdMsMedian);
      if (variabilityBlock) card.appendChild(variabilityBlock);
    });

    allGroups.push({ r: r, version: version, name: name, entries: sorted });
  }

  function render() {
    if (!showGate()) return;
    var box = document.getElementById("stats-content");
    box.innerHTML = t("common.loading");
    allGroups = [];
    comparisonCache = {};

    api.listRTests().then(function (res) {
      if (!res.ok) { box.innerHTML = ""; box.appendChild(Reflx.util.el("p", { class: "notice danger" }, [api.messageFor(res.code)])); return; }
      var rtests = (res.data || []).filter(function (r) { return r.current_version; });

      var perTestVersionChecks = [];
      rtests.forEach(function (r) {
        for (let v = 1; v <= r.current_version; v++) {
          perTestVersionChecks.push(
            api.getHistory(r.slug, v).then(function (histRes) {
              return { r: r, version: v, ok: histRes.ok, entries: histRes.ok ? (histRes.data.entries || []) : [] };
            })
          );
        }
      });

      Promise.all(perTestVersionChecks).then(function (results) {
        box.innerHTML = "";
        var anyData = false;
        results.forEach(function (item) {
          if (!item.entries.length) return;
          anyData = true;
          var reg = Reflx.testRegistry[item.r.slug];
          var name = reg ? t(reg.prefix + ".name") : item.r.name;
          renderVersionCard(box, item.r, name, item.version, item.entries);
        });
        if (!anyData) box.appendChild(Reflx.util.el("p", { class: "notice info" }, [t("stats.empty")]));
      });
    });
  }

  Reflx.i18n.loadLocaleConfig().then(function () {
    Reflx.i18n.init();
    Reflx.nav.render("stats");
    Reflx.i18n.applyToDocument();
    render();
  });
  document.addEventListener("reflx:localechange", function () { Reflx.i18n.applyToDocument(); render(); });
  document.addEventListener("reflx:sessionchange", render);
})();

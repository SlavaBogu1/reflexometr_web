/**
 * Reflexometr — shared utility helpers.
 * Plain global-namespace script (no bundler, no ES module CORS issues when
 * opened directly via file://). All Reflexometr code hangs off `window.Reflx`.
 */
(function (global) {
  "use strict";

  var Reflx = global.Reflx = global.Reflx || {};

  /** RFC4122-ish random id, good enough for client-local mock ids/tokens. */
  function uid(prefix) {
    var rand = (Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2)).slice(0, 20);
    return (prefix ? prefix + "_" : "") + Date.now().toString(36) + "_" + rand;
  }

  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    attrs = attrs || {};
    Object.keys(attrs).forEach(function (k) {
      if (k === "class") node.className = attrs[k];
      else if (k === "dataset") Object.keys(attrs[k]).forEach(function (dk) { node.dataset[dk] = attrs[k][dk]; });
      else if (k.indexOf("on") === 0 && typeof attrs[k] === "function") node.addEventListener(k.slice(2), attrs[k]);
      else node.setAttribute(k, attrs[k]);
    });
    (children || []).forEach(function (c) {
      if (c === null || c === undefined) return;
      node.appendChild(typeof c === "string" ? document.createTextNode(c) : c);
    });
    return node;
  }

  function readJSON(key, fallback) {
    try {
      var raw = localStorage.getItem(key);
      if (raw === null) return fallback;
      return JSON.parse(raw);
    } catch (e) {
      console.warn("Reflx.util.readJSON failed for", key, e);
      return fallback;
    }
  }

  function writeJSON(key, value) {
    try {
      localStorage.setItem(key, JSON.stringify(value));
    } catch (e) {
      console.warn("Reflx.util.writeJSON failed for", key, e);
    }
  }

  /** Clamp helper used by trial timing logic. */
  function clamp(n, min, max) { return Math.min(max, Math.max(min, n)); }

  /** Create an SVG-namespaced element with the given attributes (CR-STATS-07 original; CR-UI-29 shared). */
  function svgEl(tag, attrs) {
    var node = document.createElementNS("http://www.w3.org/2000/svg", tag);
    Object.keys(attrs || {}).forEach(function (k) { node.setAttribute(k, attrs[k]); });
    return node;
  }

  /**
   * Builds a small inline <svg> icon (`stroke="currentColor"`, 24x24 viewBox — the
   * stats-page.js "Manage results" icon-button convention, CR-STATS-07). Extracted
   * here (CI-16.8) so library.js's CR-UI-29 icon buttons can reuse it instead of
   * duplicating the pattern.
   *
   * `shapes` accepts either:
   *   - an array of path `d` strings (stats-page.js's original/simple form), or
   *   - an array of `{ tag, attrs }` objects for icons needing non-path primitives
   *     (e.g. CR-UI-29's View glyph uses a <circle> pupil inside a <path> eye outline,
   *     per the approved mockup's exact SVG).
   */
  function svgIcon(shapes) {
    var svg = svgEl("svg", { viewBox: "0 0 24 24", fill: "none", stroke: "currentColor", "stroke-width": "2" });
    shapes.forEach(function (shape) {
      if (typeof shape === "string") svg.appendChild(svgEl("path", { d: shape }));
      else svg.appendChild(svgEl(shape.tag, shape.attrs));
    });
    return svg;
  }

  /**
   * Shows a non-blocking error message in an existing `.notice.danger` element
   * (CR-UI-26) — never a blocking `alert()`. `elId` must point at a `<div
   * class="notice danger" role="alert" style="display:none;">` already present in
   * the page's markup (see runner.html#run-error for the reference pattern). Pages
   * needing this add their own such element and call showBanner/hideBanner with its
   * id; nothing here assumes a single fixed element.
   */
  function showBanner(elId, message) {
    var node = document.getElementById(elId);
    if (!node) return;
    node.textContent = message;
    node.style.display = "block";
  }
  function hideBanner(elId) {
    var node = document.getElementById(elId);
    if (!node) return;
    node.style.display = "none";
    node.textContent = "";
  }

  Reflx.util = {
    uid: uid, qs: qs, qsa: qsa, el: el, readJSON: readJSON, writeJSON: writeJSON, clamp: clamp,
    showBanner: showBanner, hideBanner: hideBanner, svgEl: svgEl, svgIcon: svgIcon
  };
})(window);

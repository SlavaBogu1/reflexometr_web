/**
 * Reflexometr — i18n mechanism (CR-UI-02).
 *
 * Resolution order (per CR-UI-02 / BACKLOG.md):
 *   1. explicit user selection (Platform Settings, CI-1.1) — persisted in localStorage,
 *      and additionally synced to the profile once logged in (not implemented client-side
 *      this sprint — no login UI landed yet, see SPRINT1_REPORT.md assumptions).
 *   2. browser Accept-Language / navigator.language
 *   3. English fallback — including for any key missing from a non-English locale file,
 *      and for any locale code we don't recognize.
 *
 * Locale resource files (client/js/locales/*.js) are loaded as plain scripts (not fetch())
 * so this also works when the app is opened directly via file:// (no build step, no server
 * required to view it) — each file registers itself into `Reflx.i18n.locales`.
 */
(function (global) {
  "use strict";

  var Reflx = global.Reflx = global.Reflx || {};
  var SUPPORTED = ["en", "es", "de", "fr", "zh-Hans"];
  var FALLBACK = "en";

  var locales = {}; // populated by js/locales/*.js via registerLocale()
  var current = null;

  function registerLocale(code, dict) {
    locales[code] = dict;
  }

  function normalizeToSupported(code) {
    if (!code) return null;
    if (SUPPORTED.indexOf(code) !== -1) return code;
    // Try a bare-language match, e.g. "es-MX" -> "es", "zh-CN"/"zh" -> "zh-Hans"
    var lower = code.toLowerCase();
    if (lower.indexOf("zh") === 0) return "zh-Hans";
    var bare = lower.split("-")[0];
    var hit = SUPPORTED.filter(function (s) { return s.toLowerCase().split("-")[0] === bare; });
    return hit.length ? hit[0] : null;
  }

  function detectBrowserLocale() {
    var candidates = [];
    if (navigator.languages && navigator.languages.length) candidates = candidates.concat(navigator.languages);
    if (navigator.language) candidates.push(navigator.language);
    for (var i = 0; i < candidates.length; i++) {
      var norm = normalizeToSupported(candidates[i]);
      if (norm) return norm;
    }
    return FALLBACK;
  }

  /** Resolve the active locale per CR-UI-02's order. Explicit selection wins if set. */
  function resolveLocale() {
    var settings = Reflx.settings ? Reflx.settings.get() : null;
    var explicit = settings && settings.locale ? normalizeToSupported(settings.locale) : null;
    return explicit || detectBrowserLocale();
  }

  function init() {
    current = resolveLocale();
    return current;
  }

  function setLocale(code) {
    var norm = normalizeToSupported(code) || FALLBACK;
    if (Reflx.settings) Reflx.settings.set({ locale: norm });
    current = norm;
    applyToDocument();
    document.dispatchEvent(new CustomEvent("reflx:localechange", { detail: { locale: norm } }));
    return norm;
  }

  function getLocale() { return current || init(); }

  /** t(key, params?) — looks up key in current locale, falls back to English, then to the key itself. */
  function t(key, params) {
    var loc = getLocale();
    var dict = locales[loc] || {};
    var str = dict[key];
    if (str === undefined) str = (locales[FALLBACK] || {})[key];
    if (str === undefined) {
      console.warn("Reflx.i18n: missing key '" + key + "' in", loc, "and fallback", FALLBACK);
      return key;
    }
    if (params) {
      Object.keys(params).forEach(function (p) {
        str = str.replace(new RegExp("\\{" + p + "\\}", "g"), params[p]);
      });
    }
    return str;
  }

  /** Locale-aware number/date formatting (CR-UI-02). */
  function formatNumber(n, opts) {
    try { return new Intl.NumberFormat(getLocale(), opts).format(n); }
    catch (e) { return String(n); }
  }
  function formatDateTime(date, opts) {
    try { return new Intl.DateTimeFormat(getLocale(), opts || { dateStyle: "medium", timeStyle: "short" }).format(date); }
    catch (e) { return date.toISOString(); }
  }

  /** Walk the DOM applying data-i18n / data-i18n-attr-* to elements. Call after any DOM insert. */
  function applyToDocument(root) {
    var scope = root || document;
    Reflx.util.qsa("[data-i18n]", scope).forEach(function (node) {
      node.textContent = t(node.getAttribute("data-i18n"));
    });
    Reflx.util.qsa("[data-i18n-html]", scope).forEach(function (node) {
      node.innerHTML = t(node.getAttribute("data-i18n-html"));
    });
    Reflx.util.qsa("[data-i18n-attr]", scope).forEach(function (node) {
      // format: "placeholder:key.a;title:key.b"
      node.getAttribute("data-i18n-attr").split(";").forEach(function (pair) {
        var parts = pair.split(":");
        if (parts.length !== 2) return;
        node.setAttribute(parts[0].trim(), t(parts[1].trim()));
      });
    });
    document.documentElement.setAttribute("lang", getLocale() === "zh-Hans" ? "zh-Hans" : getLocale());
  }

  Reflx.i18n = {
    SUPPORTED: SUPPORTED,
    FALLBACK: FALLBACK,
    registerLocale: registerLocale,
    init: init,
    setLocale: setLocale,
    getLocale: getLocale,
    t: t,
    formatNumber: formatNumber,
    formatDateTime: formatDateTime,
    applyToDocument: applyToDocument
  };
})(window);

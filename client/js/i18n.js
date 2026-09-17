/**
 * Reflexometr — i18n mechanism (CR-UI-02; locale list made deployment-configurable
 * by CR-INFRA-01).
 *
 * Resolution order (per CR-UI-02 / BACKLOG.md):
 *   1. explicit user selection (Platform Settings, CI-1.1) — persisted in localStorage,
 *      and additionally synced to the profile once logged in (not implemented client-side
 *      this sprint — no login UI landed yet, see SPRINT1_REPORT.md assumptions).
 *   2. browser Accept-Language / navigator.language
 *   3. Russian fallback (CR-UI-13/D18, was English) — including for any key missing
 *      from a non-Russian locale file, and for any locale code we don't recognize.
 *
 * Locale resource files (client/js/locales/*.js) are loaded as plain scripts (not fetch())
 * so this also works when the app is opened directly via file:// (no build step, no server
 * required to view it) — each file registers itself into `Reflx.i18n.locales`.
 *
 * CR-INFRA-01 (SI-5.3 / CI-5.5): `SUPPORTED`/`FALLBACK` are no longer hardcoded — every
 * page calls `loadLocaleConfig()` once at startup (before `init()`), which fetches
 * `GET /config/locales` (`_API_CONTRACT/CONTRACT.md` v1.3 — `{ "enabled": [...],
 * "default": "en" }`, standard `{ "data": ... }` envelope) and narrows both arrays to
 * the deployment's enabled set. The original 6-locale array is kept as
 * `HARDCODED_DEFAULT` and used as-is if the endpoint is unreachable (network error,
 * non-2xx, or a malformed body) — the app must degrade to "offer every locale," never
 * to a broken/empty selector. This also covers the case where the endpoint doesn't
 * exist yet on an older/un-upgraded deployment.
 */
(function (global) {
  "use strict";

  var Reflx = global.Reflx = global.Reflx || {};
  var HARDCODED_DEFAULT = ["en", "es", "de", "fr", "zh-Hans", "ru"];
  var SUPPORTED = HARDCODED_DEFAULT.slice();
  var FALLBACK = "ru"; // CR-UI-13/D18: ru is now the default/fallback locale (was "en").

  var locales = {}; // populated by js/locales/*.js via registerLocale()
  var current = null;

  function registerLocale(code, dict) {
    locales[code] = dict;
  }

  /**
   * Fetch the deployment's enabled-locale config and narrow SUPPORTED/FALLBACK
   * in place (so any earlier-captured reference to `Reflx.i18n.SUPPORTED`, e.g.
   * `client/js/settings-page.js:38`'s selector population, sees the update).
   * Always resolves (never rejects) — callers can unconditionally chain `.then()`.
   */
  function loadLocaleConfig() {
    return fetch(apiBaseForConfig() + "/config/locales", { headers: { "Accept": "application/json" } })
      .then(function (res) {
        if (!res.ok) throw new Error("HTTP " + res.status);
        return res.json();
      })
      .then(function (json) {
        var data = json && json.data ? json.data : json;
        var enabled = data && Array.isArray(data.enabled) ? data.enabled.filter(function (c) {
          return HARDCODED_DEFAULT.indexOf(c) !== -1;
        }) : [];
        if (!enabled.length) throw new Error("empty/invalid enabled list");
        SUPPORTED.length = 0;
        Array.prototype.push.apply(SUPPORTED, enabled);
        FALLBACK = (data.default && enabled.indexOf(data.default) !== -1) ? data.default : "en";
        if (SUPPORTED.indexOf(FALLBACK) === -1) FALLBACK = SUPPORTED[0];
      })
      .catch(function () {
        // Unreachable endpoint, non-2xx, or malformed body: degrade to "offer every
        // locale" rather than a broken or empty selector (SI-5.3 not landed yet, or
        // API down on first paint, both hit this path identically). CR-UI-13/D18:
        // last-resort fallback is "ru" (was "en"), matching Locale::DEFAULT server-side.
        SUPPORTED.length = 0;
        Array.prototype.push.apply(SUPPORTED, HARDCODED_DEFAULT);
        FALLBACK = "ru";
      });
  }

  function apiBaseForConfig() {
    var path = window.location.pathname;
    if (path.indexOf("/rtest/") !== -1) return "/rtest/api";
    return "/api";
  }

  function normalizeToSupported(code) {
    if (!code) return null;
    if (SUPPORTED.indexOf(code) !== -1) return code;
    // Try a bare-language match, e.g. "es-MX" -> "es", "zh-CN"/"zh" -> "zh-Hans"
    // — gated on SUPPORTED so a narrowed deployment (CR-INFRA-01) never resolves to
    // a locale that isn't actually enabled.
    var lower = code.toLowerCase();
    if (lower.indexOf("zh") === 0 && SUPPORTED.indexOf("zh-Hans") !== -1) return "zh-Hans";
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
    registerLocale: registerLocale,
    loadLocaleConfig: loadLocaleConfig,
    init: init,
    setLocale: setLocale,
    getLocale: getLocale,
    t: t,
    formatNumber: formatNumber,
    formatDateTime: formatDateTime,
    applyToDocument: applyToDocument
  };
  // FALLBACK is reassigned by loadLocaleConfig() (a narrowed deployment default may
  // differ from "en") — expose it as a live getter rather than a one-time snapshot,
  // since plain property assignment above would freeze the value at load time.
  Object.defineProperty(Reflx.i18n, "FALLBACK", { get: function () { return FALLBACK; }, enumerable: true });
})(window);

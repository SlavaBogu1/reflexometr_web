/**
 * Reflexometr — API access layer, wired to the real ServerTeam API per
 * `_API_CONTRACT/CONTRACT.md` v1.0 (CR-AUTH-01 follow-up).
 *
 * Sprint 1's `client/js/mock-api.js` is retired (no longer included by any page) —
 * every call below is a real `fetch()` against the contract's documented endpoints.
 * Base URL: same-origin `/api` in production (D13, path-split deploy). For local
 * dev, where the client is served on a different port than `php -S ... -t public`,
 * append `?apiBase=http://localhost:8000` once — it's captured and persisted to
 * localStorage so you don't need to repeat it on every page (ServerTeam's `public/`
 * has no CORS headers configured, since D13 assumes same-origin in production; a
 * cross-origin local dev setup needs either that override + CORS enabled locally,
 * or a same-origin reverse proxy — see SPRINT1_REPORT.md follow-up section).
 *
 * Every function returns a normalized `{ ok: true, data }` or
 * `{ ok: false, code, status, details }` — callers should use `Reflx.api.messageFor()`
 * to turn `code` into a localized string (CR-UI-02: server never sends English text,
 * only structured codes — see CONTRACT.md "Standard error-response shape").
 */
(function (global) {
  "use strict";
  var Reflx = global.Reflx = global.Reflx || {};

  var API_BASE_KEY = "reflx.apiBase.override";

  (function captureApiBaseOverride() {
    var params = new URLSearchParams(location.search);
    var override = params.get("apiBase");
    if (override) localStorage.setItem(API_BASE_KEY, override);
  })();

  function apiBase() {
    var override = localStorage.getItem(API_BASE_KEY);
    if (override) return override;

    // Auto-detect API base from current location (supports subdirectory deployments like /rtest/)
    var path = window.location.pathname;
    if (path.includes('/rtest/')) return '/rtest/api';
    return '/api';
  }

  function messageFor(code, params) {
    var key = "error." + code;
    var t = Reflx.i18n.t;
    // t() itself falls back to the key string if missing; detect that to fall back
    // further to the generic "unknown code" message instead of showing a raw key.
    var resolved = t(key, params);
    if (resolved === key) return t("error.UNKNOWN", { code: code });
    return resolved;
  }

  /**
   * Core request helper. Attaches the bearer token if one exists. A 401 with
   * AUTH_REQUIRED/AUTH_SESSION_EXPIRED clears the session and redirects to the
   * login screen (CR-AUTH-01 acceptance 6) unless `opts.noAuthRedirect` is set
   * (used by the auth endpoints themselves, where a 401 is just "wrong password,"
   * not "your session died").
   */
  function request(method, path, body, opts) {
    opts = opts || {};
    var headers = { "Accept": "application/json" };
    var fetchOpts = { method: method, headers: headers };

    if (body instanceof FormData) {
      fetchOpts.body = body; // let the browser set multipart Content-Type + boundary
    } else if (body !== undefined && body !== null) {
      headers["Content-Type"] = "application/json";
      fetchOpts.body = JSON.stringify(body);
    }

    var token = Reflx.session.getToken();
    if (token) headers["Authorization"] = "Bearer " + token;

    return fetch(apiBase() + path, fetchOpts).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (json) {
        if (res.ok) return { ok: true, data: json.data !== undefined ? json.data : json };
        var err = (json && json.error) || {};
        var code = err.code || ("HTTP_" + res.status);
        if (code === "AUTH_REQUIRED" || code === "AUTH_SESSION_EXPIRED") {
          Reflx.session.clear(); // the stored token is dead either way
          if (!opts.noAuthRedirect) Reflx.session.redirectToLogin();
        }
        return { ok: false, code: code, status: res.status, details: err.details };
      });
    }, function (networkErr) {
      return { ok: false, code: "NETWORK", status: 0, details: { message: String(networkErr) } };
    });
  }

  var api = {
    messageFor: messageFor,

    // --- CR-AUTH-01 ---
    register: function (email, password) { return request("POST", "/auth/register", { email: email, password: password }, { noAuthRedirect: true }); },
    login: function (email, password) { return request("POST", "/auth/login", { email: email, password: password }, { noAuthRedirect: true }); },
    logout: function () { return request("POST", "/auth/logout", null, { noAuthRedirect: true }); },
    me: function () { return request("GET", "/auth/me", undefined, { noAuthRedirect: true }); },
    patchProfile: function (patch) { return request("PATCH", "/profile", patch); },

    // --- CR-TEST-01 / CR-TEST-05: public browsing (no auth) ---
    // CR-TEST-25 (v1.6): category_id -> tag_id filter param; r-tests now carry a `tags: []`
    // array instead of a single category_id/category_name.
    listRTests: function (tagId) { return request("GET", "/r-tests" + (tagId ? "?tag_id=" + encodeURIComponent(tagId) : "")); },
    getRTest: function (slug) { return request("GET", "/r-tests/" + encodeURIComponent(slug)); },
    listCategories: function () { return request("GET", "/categories"); },
    listPackages: function () { return request("GET", "/packages"); },

    // --- CR-TEST-01 / CR-TEST-05: admin (admin-only, 403 enforced server-side) ---
    // CR-TEST-25 (v1.6): create/import payloads take tag_ids?: int[] (replaces category_id?: int).
    listAdminRTests: function () { return request("GET", "/admin/r-tests"); },
    createRTest: function (payload) { return request("POST", "/admin/r-tests", payload); },
    importVersion: function (slug, payload) { return request("POST", "/admin/r-tests/" + encodeURIComponent(slug) + "/versions", payload); },
    exportVersion: function (slug, version) { return request("GET", "/admin/r-tests/" + encodeURIComponent(slug) + "/versions/" + version + "/export"); },
    patchRTestMeta: function (slug, patch) { return request("PATCH", "/admin/r-tests/" + encodeURIComponent(slug), patch); },
    createCategory: function (name) { return request("POST", "/admin/categories", { name: name }); },
    patchCategory: function (id, name) { return request("PATCH", "/admin/categories/" + id, { name: name }); },
    deleteCategory: function (id) { return request("DELETE", "/admin/categories/" + id); },
    createPackage: function (name, description) { return request("POST", "/admin/packages", { name: name, description: description }); },
    patchPackage: function (id, patch) { return request("PATCH", "/admin/packages/" + id, patch); },
    deletePackage: function (id) { return request("DELETE", "/admin/packages/" + id); },
    addRTestToPackage: function (packageId, rTestId) { return request("POST", "/admin/packages/" + packageId + "/r-tests", { r_test_id: rTestId }); },
    removeRTestFromPackage: function (packageId, rTestId) { return request("DELETE", "/admin/packages/" + packageId + "/r-tests/" + rTestId); },

    // --- CR-TEST-02 / CR-TEST-06: runs (auth required) ---
    startRun: function (slug, opts) { return request("POST", "/r-tests/" + encodeURIComponent(slug) + "/runs", opts || {}); },
    submitRun: function (token, payload) { return request("POST", "/r-tests/runs/" + encodeURIComponent(token) + "/submit", payload); },

    // --- CR-STATS-01 / CR-STATS-02 (auth required) ---
    getHistory: function (slug, version) { return request("GET", "/r-tests/" + encodeURIComponent(slug) + "/versions/" + version + "/history"); },
    getComparison: function (resultId) { return request("GET", "/results/" + resultId + "/comparison"); },

    // --- CR-AUTH-02: admin results approval queue (admin-only, 403 enforced server-side) ---
    listPendingResults: function (status) { return request("GET", "/admin/results?status=" + encodeURIComponent(status || "pending")); },
    patchResultStatus: function (id, approvalStatus) { return request("PATCH", "/admin/results/" + id, { approval_status: approvalStatus }); }
  };

  Reflx.api = api;
})(window);

/**
 * RETIRED (CR-AUTH-01 follow-up, see SPRINT1_REPORT.md "Follow-up" section): now that
 * `_API_CONTRACT/CONTRACT.md` is at v1.0 with every endpoint real, `client/js/api.js`
 * calls the real ServerTeam API directly and no longer wraps this file. No HTML page
 * includes `mock-api.js` anymore — kept only as a historical reference for how Sprint
 * 1's client-only demo modeled the not-yet-built API; safe to delete once no longer
 * useful as a reference.
 *
 * Reflexometr — local mock of the ServerTeam API (client-side only).
 *
 * ASSUMPTION / FLAG FOR RECONCILIATION (see SPRINT1_REPORT.md):
 * `_API_CONTRACT/CONTRACT.md` was still at v0.1 with zero endpoints defined when this
 * sprint's client work was built (ServerTeam builds their side in parallel). Every
 * function here is modeled directly on the request/response *shapes* described in
 * REQUIREMENTS/BACKLOG.md (CR-TEST-01, CR-TEST-02, CR-TEST-05, CR-STATS-01, CR-STATS-02)
 * and the relevant decisions (D7 admin model, D9 injection-prevention-only security,
 * D11 compiled-schedule / opaque-description model, D12 anonymized-aggregate-only
 * comparisons). `js/api.js` is the ONLY file that should need to change once real
 * endpoints land in the contract — everything else calls Reflx.api, not this file,
 * directly.
 *
 * Persisted in localStorage so demo state (imported tests, submitted runs) survives
 * a reload; run tokens are kept in memory only (module-level Map) since they are
 * meant to be short-lived and single-use per D9/CR-TEST-02, mirroring a server-side
 * token store.
 */
(function (global) {
  "use strict";

  var Reflx = global.Reflx = global.Reflx || {};
  var util = Reflx.util;

  var KEYS = {
    rtests: "reflx.mock.rtests.v1",
    packages: "reflx.mock.packages.v1",
    categories: "reflx.mock.categories.v1",
    results: "reflx.mock.results.v1",
    isAdmin: "reflx.mock.isAdmin.v1",
    userId: "reflx.mock.userId.v1"
  };

  var runTokens = new Map(); // token -> { userId, rTestId, versionId, schedule, issuedAt, expiresAt, used, perHandTimeoutMs }

  var RUN_TOKEN_TTL_MS = 5 * 60 * 1000; // 5 minutes — mock value; real TTL is ServerTeam's to set

  // ---------------------------------------------------------------- seed data

  function seedIfEmpty() {
    if (util.readJSON(KEYS.categories, null) === null) {
      util.writeJSON(KEYS.categories, ["visual", "motor", "audio", "audio-visual"]);
    }
    if (util.readJSON(KEYS.packages, null) === null) {
      util.writeJSON(KEYS.packages, []);
    }
    if (util.readJSON(KEYS.rtests, null) === null) {
      var now = new Date().toISOString();
      util.writeJSON(KEYS.rtests, [
        {
          id: "rtest_simple_reaction",
          slug: "simple-reaction",
          name: "Simple Visual Reaction Time",
          category: "visual",
          versions: [
            {
              id: "rtestv_simple_reaction_1",
              version: 1,
              createdAt: now,
              active: true,
              descriptionRef: "simple-reaction.v1.desc.json",
              _internalConfig: {
                kind: "simple",
                trialCount: 10,
                minDelayMs: 1200,
                maxDelayMs: 3500,
                scheduleBufferTrials: 6 // spare resolved delays for false-start re-runs (D11: client only ever sees resolved values, never a range)
              }
            }
          ]
        },
        {
          id: "rtest_two_hand_reaction",
          slug: "two-hand-reaction",
          name: "Two-Hand Reaction Time",
          category: "motor",
          versions: [
            {
              id: "rtestv_two_hand_reaction_1",
              version: 1,
              createdAt: now,
              active: true,
              descriptionRef: "two-hand-reaction.v1.desc.json",
              _internalConfig: {
                kind: "two-hand",
                trialCount: 8,
                minDelayMs: 1200,
                maxDelayMs: 3200,
                perHandTimeoutMs: 2000,
                scheduleBufferTrials: 0
              }
            }
          ]
        }
      ]);
    }
    if (!localStorage.getItem(KEYS.userId)) {
      localStorage.setItem(KEYS.userId, util.uid("user"));
    }
  }

  function currentUserId() { return localStorage.getItem(KEYS.userId); }

  function isAdmin() { return util.readJSON(KEYS.isAdmin, false) === true; }
  function setAdmin(flag) { util.writeJSON(KEYS.isAdmin, !!flag); }

  function requireAdmin() {
    if (!isAdmin()) {
      var err = new Error("forbidden");
      err.code = "FORBIDDEN";
      err.httpStatus = 403;
      throw err;
    }
  }

  function findRTest(rtests, idOrSlug) {
    return rtests.filter(function (r) { return r.id === idOrSlug || r.slug === idOrSlug; })[0] || null;
  }

  function currentVersion(rtest) {
    var active = rtest.versions.filter(function (v) { return v.active; });
    var pool = active.length ? active : rtest.versions;
    return pool.reduce(function (best, v) { return (!best || v.version > best.version) ? v : best; }, null);
  }

  // ------------------------------------------------------- CR-TEST-01 / 05: library

  /** Admin + public-safe metadata listing (never includes _internalConfig). */
  function listRTests() {
    var rtests = util.readJSON(KEYS.rtests, []);
    var packages = util.readJSON(KEYS.packages, []);
    return rtests.map(function (r) {
      return {
        id: r.id,
        slug: r.slug,
        name: r.name,
        category: r.category,
        versions: r.versions.map(function (v) {
          return {
            id: v.id, version: v.version, createdAt: v.createdAt, active: v.active,
            descriptionRef: v.descriptionRef
          };
        }),
        packageIds: packages.filter(function (p) { return p.rTestIds.indexOf(r.id) !== -1; }).map(function (p) { return p.id; })
      };
    });
  }

  function listActiveForBrowsing() {
    return listRTests().map(function (r) {
      var v = r.versions.filter(function (x) { return x.active; }).sort(function (a, b) { return b.version - a.version; })[0];
      return v ? { slug: r.slug, name: r.name, category: r.category, version: v.version } : null;
    }).filter(Boolean);
  }

  function getVersionDetail(versionId) {
    var rtests = util.readJSON(KEYS.rtests, []);
    for (var i = 0; i < rtests.length; i++) {
      var v = rtests[i].versions.filter(function (x) { return x.id === versionId; })[0];
      if (v) {
        var packages = util.readJSON(KEYS.packages, []).filter(function (p) { return p.rTestIds.indexOf(rtests[i].id) !== -1; });
        return {
          rTestId: rtests[i].id, slug: rtests[i].slug, name: rtests[i].name, category: rtests[i].category,
          versionId: v.id, version: v.version, createdAt: v.createdAt, active: v.active,
          descriptionRef: v.descriptionRef, packages: packages.map(function (p) { return p.name; })
        };
      }
    }
    return null;
  }

  /**
   * Admin-only import (CR-TEST-01). In the real system the admin uploads a description
   * file authored/validated on Science Backend (D11) and never hand-edits its content;
   * the server parses/stores it. This mock has no real server, so it does a best-effort
   * parse of the uploaded JSON purely so the demo has a runnable config — flagged here
   * as mock-only behavior, not a client responsibility in the shipped product.
   */
  function importRTest(opts) {
    requireAdmin();
    var rtests = util.readJSON(KEYS.rtests, []);
    var now = new Date().toISOString();
    var parsedConfig = opts.parsedConfig || { kind: "simple", trialCount: 10, minDelayMs: 1000, maxDelayMs: 3000, scheduleBufferTrials: 6 };

    if (opts.asNewVersionOfId) {
      var rtest = findRTest(rtests, opts.asNewVersionOfId);
      if (!rtest) { var e = new Error("not found"); e.code = "NOT_FOUND"; throw e; }
      var nextVersion = Math.max.apply(null, rtest.versions.map(function (v) { return v.version; })) + 1;
      rtest.versions.push({
        id: util.uid("rtestv"), version: nextVersion, createdAt: now, active: true,
        descriptionRef: opts.fileName || (rtest.slug + ".v" + nextVersion + ".desc.json"),
        _internalConfig: parsedConfig
      });
    } else {
      rtests.push({
        id: util.uid("rtest"), slug: opts.slug, name: opts.name, category: opts.category,
        versions: [{
          id: util.uid("rtestv"), version: 1, createdAt: now, active: true,
          descriptionRef: opts.fileName || (opts.slug + ".v1.desc.json"),
          _internalConfig: parsedConfig
        }]
      });
    }
    util.writeJSON(KEYS.rtests, rtests);
    return listRTests();
  }

  function exportVersion(versionId) {
    requireAdmin();
    var rtests = util.readJSON(KEYS.rtests, []);
    for (var i = 0; i < rtests.length; i++) {
      var v = rtests[i].versions.filter(function (x) { return x.id === versionId; })[0];
      if (v) return { fileName: v.descriptionRef, content: JSON.stringify(v._internalConfig, null, 2) };
    }
    var e = new Error("not found"); e.code = "NOT_FOUND"; throw e;
  }

  function listCategories() { return util.readJSON(KEYS.categories, []); }
  function addCategory(name) {
    requireAdmin();
    var cats = listCategories();
    if (cats.indexOf(name) === -1) { cats.push(name); util.writeJSON(KEYS.categories, cats); }
    return cats;
  }

  function listPackages() { return util.readJSON(KEYS.packages, []); }
  function createPackage(name, description) {
    requireAdmin();
    var pkgs = listPackages();
    pkgs.push({ id: util.uid("pkg"), name: name, description: description || "", rTestIds: [] });
    util.writeJSON(KEYS.packages, pkgs);
    return pkgs;
  }
  function setPackageMembership(packageId, rTestId, included) {
    requireAdmin();
    var pkgs = listPackages();
    var pkg = pkgs.filter(function (p) { return p.id === packageId; })[0];
    if (!pkg) return pkgs;
    var idx = pkg.rTestIds.indexOf(rTestId);
    if (included && idx === -1) pkg.rTestIds.push(rTestId);
    if (!included && idx !== -1) pkg.rTestIds.splice(idx, 1);
    util.writeJSON(KEYS.packages, pkgs);
    return pkgs;
  }

  // ------------------------------------------------------- CR-TEST-02 / 06: runs

  /**
   * POST /r-tests/{slug}/runs — CR-TEST-02, series mode per CR-TEST-06.
   * Returns only the compiled, resolved trial schedule (D11) — never min/max ranges.
   */
  function startRun(slug) {
    var rtests = util.readJSON(KEYS.rtests, []);
    var rtest = findRTest(rtests, slug);
    if (!rtest) { var e = new Error("not found"); e.code = "NOT_FOUND"; throw e; }
    var version = currentVersion(rtest);
    var cfg = version._internalConfig;
    var totalSlots = cfg.trialCount + (cfg.scheduleBufferTrials || 0);
    var schedule = [];
    for (var i = 0; i < totalSlots; i++) {
      schedule.push(Math.round(cfg.minDelayMs + Math.random() * (cfg.maxDelayMs - cfg.minDelayMs)));
    }
    var token = util.uid("run");
    var issuedAt = Date.now();
    runTokens.set(token, {
      userId: currentUserId(), rTestId: rtest.id, versionId: version.id,
      slug: rtest.slug, kind: cfg.kind,
      schedule: schedule, requiredTrialCount: cfg.trialCount,
      perHandTimeoutMs: cfg.perHandTimeoutMs || null,
      issuedAt: issuedAt, expiresAt: issuedAt + RUN_TOKEN_TTL_MS, used: false
    });
    return {
      token: token, rTestId: rtest.id, versionId: version.id, version: version.version,
      requiredTrialCount: cfg.trialCount, schedule: schedule.slice(),
      perHandTimeoutMs: cfg.perHandTimeoutMs || null, expiresAt: runTokens.get(token).expiresAt
    };
  }

  /**
   * POST /r-tests/runs/{token}/submit — CR-TEST-02.
   * Injection-prevention validation only (D9): token single-use/unexpired, monotonic
   * timestamps. No statistical/outlier filtering (explicitly out of scope, D9).
   */
  function submitRun(token, trials, extra) {
    var run = runTokens.get(token);
    if (!run) { var e1 = new Error("invalid token"); e1.code = "INVALID_TOKEN"; throw e1; }
    if (run.used) { var e2 = new Error("token already used"); e2.code = "TOKEN_USED"; throw e2; }
    if (Date.now() > run.expiresAt) { var e3 = new Error("token expired"); e3.code = "TOKEN_EXPIRED"; throw e3; }

    var lastReaction = -Infinity;
    for (var i = 0; i < trials.length; i++) {
      var tr = trials[i];
      if (typeof tr.stimulusAt !== "number" || typeof tr.reactionAt !== "number") {
        var e4 = new Error("structurally invalid trial"); e4.code = "INVALID_TRIAL"; throw e4;
      }
      if (tr.reactionAt < tr.stimulusAt) { var e5 = new Error("non-monotonic trial"); e5.code = "INVALID_TRIAL"; throw e5; }
      if (tr.stimulusAt < lastReaction) { var e6 = new Error("non-monotonic run"); e6.code = "INVALID_TRIAL"; throw e6; }
      lastReaction = tr.reactionAt;
    }

    run.used = true;
    var results = util.readJSON(KEYS.results, []);
    var record = {
      id: util.uid("result"), userId: run.userId, rTestId: run.rTestId, versionId: run.versionId,
      slug: run.slug, kind: run.kind, submittedAt: new Date().toISOString(),
      trials: trials, extra: extra || {}
    };
    results.push(record);
    util.writeJSON(KEYS.results, results);
    return { resultId: record.id, rTestId: run.rTestId, versionId: run.versionId };
  }

  // ------------------------------------------------------- CR-STATS-01 / 02

  function primaryMetric(result) {
    // reaction time in ms for a trial, or mean across trials for a run
    var values = result.trials.map(function (t) {
      if (result.kind === "two-hand") return (t.leftAt && t.rightAt) ? Math.max(t.leftAt, t.rightAt) - t.stimulusAt : null;
      return t.reactionAt - t.stimulusAt;
    }).filter(function (v) { return typeof v === "number" && !isNaN(v); });
    if (!values.length) return null;
    return values.reduce(function (a, b) { return a + b; }, 0) / values.length;
  }

  /** Personal history grouped by exact (r_test_id, r_test_version_id) — CR-STATS-01. */
  function getPersonalHistory(slug) {
    var results = util.readJSON(KEYS.results, []).filter(function (r) { return r.slug === slug && r.userId === currentUserId(); });
    var byVersion = {};
    results.forEach(function (r) {
      byVersion[r.versionId] = byVersion[r.versionId] || [];
      byVersion[r.versionId].push({ submittedAt: r.submittedAt, mean: primaryMetric(r) });
    });
    return byVersion; // { versionId: [ {submittedAt, mean}, ... ] }
  }

  /** CR-STATS-02: anonymized percentile only — never other users' identities/raw values (D12). */
  function getComparison(rTestId, versionId, myValue) {
    var all = util.readJSON(KEYS.results, [])
      .filter(function (r) { return r.rTestId === rTestId && r.versionId === versionId; })
      .map(primaryMetric)
      .filter(function (v) { return typeof v === "number"; });
    if (all.length < 3) return { available: false, sampleSize: all.length };
    var slower = all.filter(function (v) { return v >= myValue; }).length; // lower reaction time = faster
    var percentile = Math.round((slower / all.length) * 100);
    return { available: true, percentile: percentile, sampleSize: all.length };
  }

  seedIfEmpty();

  Reflx.mockApi = {
    isAdmin: isAdmin, setAdmin: setAdmin, currentUserId: currentUserId,
    listRTests: listRTests, listActiveForBrowsing: listActiveForBrowsing, getVersionDetail: getVersionDetail,
    importRTest: importRTest, exportVersion: exportVersion,
    listCategories: listCategories, addCategory: addCategory,
    listPackages: listPackages, createPackage: createPackage, setPackageMembership: setPackageMembership,
    startRun: startRun, submitRun: submitRun,
    getPersonalHistory: getPersonalHistory, getComparison: getComparison, primaryMetric: primaryMetric
  };
})(window);

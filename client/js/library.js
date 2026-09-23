/**
 * Reflexometr — Admin R-test Library page controller (CR-TEST-01, CR-TEST-05).
 * CR-AUTH-01 follow-up: gated on a real logged-in admin session (`GET /auth/me`'s
 * `is_admin`), not the retired localStorage dev toggle. The 403/401 boundary is
 * still ServerTeam's to enforce (D7/SI-1.2) — this gate is just so a non-admin
 * doesn't see a broken screen full of failed requests.
 */
(function () {
  "use strict";
  var Reflx = window.Reflx;
  var t = Reflx.i18n.t;
  var api = Reflx.api;

  var state = { rtests: [], packages: [], categories: [] };
  // CR-TEST-25: pill-grid tag picker selection state (import-new-r-test panel only),
  // keyed by tag id -> true. Cleared/rebuilt whenever the import panel's tag catalog
  // is (re)rendered (renderTagGrid()).
  var selectedTagIds = {};

  function showGate() {
    var loggedIn = Reflx.session.isLoggedIn();
    var admin = Reflx.session.isAdmin();
    document.getElementById("login-required-notice").style.display = (!loggedIn) ? "block" : "none";
    document.getElementById("not-admin-notice").style.display = (loggedIn && !admin) ? "block" : "none";
    document.getElementById("admin-area").style.display = admin ? "block" : "none";
    return admin;
  }

  function reportError(res) { alert(api.messageFor(res.code)); }

  function loadAll() {
    return Promise.all([api.listAdminRTests(), api.listPackages(), api.listTags()]).then(function (r) {
      if (!r[0].ok) { reportError(r[0]); return; }
      state.rtests = r[0].data || [];
      state.packages = (r[1].ok && r[1].data) || [];
      state.categories = (r[2].ok && r[2].data) || [];
      renderTests();
      renderImportSelects();
      renderPackages();
    });
  }

  // CR-TEST-25 (v1.6): r-tests now carry a `tags: []` array (possibly empty) instead of
  // a single category_id/category_name. categoryLabel() keeps its old name (still used
  // by the rtests table + version-detail + package-membership rows below) but now joins
  // all tag names, falling back to the "uncategorized" copy when there are none.
  function categoryLabel(r) {
    var tags = r.tags || [];
    return tags.length ? tags.map(function (tg) { return tg.name; }).join(", ") : t("library.uncategorized");
  }

  function renderTests() {
    var tbody = document.getElementById("rtests-tbody");
    tbody.innerHTML = "";
    state.rtests.forEach(function (r) {
      var currentV = r.versions.slice().sort(function (a, b) { return b.version - a.version; })[0];
      var tr = Reflx.util.el("tr", {}, [
        Reflx.util.el("td", {}, [r.name]),
        Reflx.util.el("td", {}, [Reflx.util.el("span", { class: "chip" }, [categoryLabel(r)])]),
        Reflx.util.el("td", {}, [currentV ? Reflx.util.el("span", { class: "chip version" }, ["v" + currentV.version]) : t("common.none")]),
        Reflx.util.el("td", {}, currentV ? [
          Reflx.util.el("button", { class: "btn secondary", onclick: function () { selectVersion(r, currentV); } }, [t("common.view")]),
          " ",
          Reflx.util.el("button", { class: "btn secondary", onclick: function () { doExport(r.slug, currentV.version); } }, [t("common.export")])
        ] : [])
      ]);
      tbody.appendChild(tr);
    });
  }

  function selectVersion(rtest, version) {
    var box = document.getElementById("version-detail");
    box.innerHTML = "";
    var memberPackages = state.packages.filter(function (p) {
      return (p.r_tests || []).some(function (rt) { return rt.id === rtest.id; });
    }).map(function (p) { return p.name; });
    var rows = [
      [t("library.detail.rtest"), rtest.name],
      [t("library.detail.version"), "v" + version.version],
      [t("library.detail.category"), categoryLabel(rtest)],
      [t("library.detail.created"), Reflx.i18n.formatDateTime(new Date(version.created_at))],
      [t("library.detail.active"), version.is_active ? t("common.yes") : t("common.no")],
      [t("library.detail.packages"), memberPackages.length ? memberPackages.join(", ") : t("common.none")]
    ];
    rows.forEach(function (pair) {
      box.appendChild(Reflx.util.el("div", { class: "field-row" }, [
        Reflx.util.el("label", {}, [pair[0]]),
        Reflx.util.el("span", {}, [String(pair[1])])
      ]));
    });
    box.appendChild(Reflx.util.el("p", { class: "field-desc" }, [t("library.detail.description_note")]));
  }

  function doExport(slug, version) {
    api.exportVersion(slug, version).then(function (res) {
      if (!res.ok) { reportError(res); return; }
      var blob = new Blob([res.data.description], { type: "application/json" });
      var url = URL.createObjectURL(blob);
      var a = document.createElement("a");
      a.href = url; a.download = slug + ".v" + version + ".json";
      document.body.appendChild(a); a.click(); a.remove();
      URL.revokeObjectURL(url);
    });
  }

  function renderImportSelects() {
    var sel = document.getElementById("import-existing-select");
    sel.innerHTML = "";
    state.rtests.forEach(function (r) {
      var opt = document.createElement("option");
      opt.value = r.slug; opt.textContent = r.name;
      sel.appendChild(opt);
    });
    renderTagGrid();
  }

  // CR-TEST-25: replaces the single <select id="import-category"> with the approved
  // pill-grid multi-select (client/prototype-library-multitag-picker.html, v2 — a first
  // checkbox-list draft was rejected as non-scalable past ~10 tags). Every tag renders as
  // its own clickable pill in a wrapping, internally-scrolling grid (.tag-pill-grid's
  // max-height + overflow-y: auto in css/style.css); selected = bright accent fill
  // (.tag-pill.selected, reusing .chip.category-highlight's bright treatment), unselected
  // = muted outline. Selection state (selectedTagIds) persists across re-renders driven by
  // a locale change but resets whenever the import panel's catalog is freshly loaded
  // (loadAll() -> renderImportSelects()) or a tag is added, matching the old <select>'s
  // behavior of starting unselected each time the panel's data is (re)loaded.
  function renderTagGrid() {
    var grid = document.getElementById("import-tag-grid");
    grid.innerHTML = "";
    state.categories.forEach(function (c) {
      var pill = Reflx.util.el("span", {
        class: "tag-pill" + (selectedTagIds[c.id] ? " selected" : ""),
        onclick: function () {
          if (selectedTagIds[c.id]) { delete selectedTagIds[c.id]; } else { selectedTagIds[c.id] = true; }
          renderTagGrid();
        }
      }, [c.name]);
      grid.appendChild(pill);
    });
  }

  function renderPackages() {
    var list = document.getElementById("packages-list");
    list.innerHTML = "";
    if (!state.packages.length) {
      list.appendChild(Reflx.util.el("p", { class: "field-desc" }, [t("library.packages.empty")]));
      return;
    }
    state.packages.forEach(function (pkg) {
      var memberIds = (pkg.r_tests || []).map(function (rt) { return rt.id; });
      var card = Reflx.util.el("div", { class: "card" }, [
        Reflx.util.el("h3", {}, [pkg.name]),
        Reflx.util.el("p", { class: "field-desc" }, [pkg.description || ""]),
        Reflx.util.el("h4", {}, [t("library.packages.members")])
      ]);
      var list2 = Reflx.util.el("div", {});
      state.rtests.forEach(function (r) {
        var checked = memberIds.indexOf(r.id) !== -1;
        var row = Reflx.util.el("label", { class: "field-row" }, [
          Reflx.util.el("span", {}, [r.name + " ", Reflx.util.el("span", { class: "chip" }, [categoryLabel(r)])])
        ]);
        var cb = Reflx.util.el("input", { type: "checkbox" });
        cb.checked = checked;
        cb.addEventListener("change", function () {
          var call = cb.checked ? api.addRTestToPackage(pkg.id, r.id) : api.removeRTestFromPackage(pkg.id, r.id);
          call.then(function (res) { if (!res.ok) { reportError(res); cb.checked = !cb.checked; return; } loadAll(); });
        });
        row.insertBefore(cb, row.firstChild);
        list2.appendChild(row);
      });
      card.appendChild(list2);
      list.appendChild(card);
    });
  }

  function wireTabs() {
    Reflx.util.qsa(".tab-btn").forEach(function (btn) {
      btn.addEventListener("click", function () {
        Reflx.util.qsa(".tab-btn").forEach(function (b) { b.classList.remove("active"); });
        Reflx.util.qsa(".tab-panel").forEach(function (p) { p.classList.remove("active"); });
        btn.classList.add("active");
        document.getElementById("tab-" + btn.dataset.tab).classList.add("active");
      });
    });
  }

  function wireImport() {
    document.getElementById("btn-open-import").addEventListener("click", function () {
      document.getElementById("import-panel").style.display = "block";
    });
    document.getElementById("btn-cancel-import").addEventListener("click", function () {
      document.getElementById("import-panel").style.display = "none";
    });
    Reflx.util.qsa('input[name="import-mode"]').forEach(function (radio) {
      radio.addEventListener("change", function () {
        document.getElementById("import-new-fields").style.display = radio.value === "new" && radio.checked ? "block" : "none";
      });
    });
    document.getElementById("btn-add-category").addEventListener("click", function () {
      var name = document.getElementById("new-category-name").value.trim();
      if (!name) return;
      api.createTag(name).then(function (res) {
        if (!res.ok) { reportError(res); return; }
        return api.listTags();
      }).then(function (res) {
        if (!res || !res.ok) return;
        state.categories = res.data; renderTagGrid();
        document.getElementById("new-category-name").value = "";
      });
    });
    document.getElementById("btn-do-import").addEventListener("click", function () {
      var mode = document.querySelector('input[name="import-mode"]:checked').value;
      var fileInput = document.getElementById("import-file");

      function finish(content) {
        if (mode === "version") {
          var slug = document.getElementById("import-existing-select").value;
          api.importVersion(slug, { content: content }).then(function (res) {
            if (!res.ok) { reportError(res); return; }
            document.getElementById("import-panel").style.display = "none";
            loadAll();
          });
        } else {
          // CR-TEST-25 (v1.6): tag_ids?: int[] replaces category_id?: int — empty/omitted
          // means no tags, matching the old "uncategorized" no-selection behavior.
          var tagIds = Object.keys(selectedTagIds).map(function (id) { return parseInt(id, 10); });
          var payload = {
            slug: document.getElementById("import-slug").value.trim(),
            name: document.getElementById("import-name").value.trim(),
            content: content
          };
          if (tagIds.length) payload.tag_ids = tagIds;
          api.createRTest(payload).then(function (res) {
            if (!res.ok) { reportError(res); return; }
            selectedTagIds = {};
            document.getElementById("import-panel").style.display = "none";
            loadAll();
          });
        }
      }

      if (fileInput.files[0]) {
        var reader = new FileReader();
        reader.onload = function () { finish(reader.result); };
        reader.readAsText(fileInput.files[0]);
      } else {
        alert(t("library.import.file"));
      }
    });
  }

  function wirePackageCreate() {
    document.getElementById("btn-create-package").addEventListener("click", function () {
      var name = document.getElementById("package-name").value.trim();
      var description = document.getElementById("package-description").value.trim();
      if (!name) return;
      api.createPackage(name, description).then(function (res) {
        if (!res.ok) { reportError(res); return; }
        document.getElementById("package-name").value = "";
        document.getElementById("package-description").value = "";
        loadAll();
      });
    });
  }

  function boot() {
    Reflx.i18n.loadLocaleConfig().then(function () {
      Reflx.i18n.init();
      Reflx.nav.render("library");
      Reflx.i18n.applyToDocument();
      wireTabs();
      wireImport();
      wirePackageCreate();
      if (showGate()) loadAll();
    });
    document.addEventListener("reflx:sessionchange", function () { if (showGate()) loadAll(); });
    document.addEventListener("reflx:localechange", function () {
      Reflx.i18n.applyToDocument();
      renderTests(); renderPackages();
    });
  }

  boot();
})();

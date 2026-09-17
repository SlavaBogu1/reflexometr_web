/**
 * Reflexometr — shared top navigation.
 * No separate site-wide language switcher here (CR-UI-02 amendment / CR-UI-03): the
 * language selector lives only on the Platform Settings screen.
 * CR-AUTH-01 follow-up: shows Log in / Log out + the current account; the admin
 * library link only renders for the logged-in admin account (a UX nicety — the real
 * 403 boundary is still enforced server-side, D7/SI-1.2).
 * CR-UI-12: "Home" is now a `.has-submenu` dropdown trigger exposing 4 informational
 * sub-pages (reflexes/medical-facts/certification/offerings.html) — the "Home" label
 * itself still links to index.html; only the caret/sub-menu is new surface. Desktop:
 * hover-or-click-to-open; mobile (no hover): tap the caret to toggle.
 */
(function (global) {
  "use strict";
  var Reflx = global.Reflx = global.Reflx || {};
  // Tracks the currently-open submenu's outside-click closer so re-renders (e.g. on
  // reflx:sessionchange, which rebuilds the whole nav DOM) don't leak a stale listener
  // referencing a detached `wrap` node.
  var closeOpenSubmenuOnOutsideClick = null;

  function buildHomeSubmenu(activePage) {
    var wrap = Reflx.util.el("div", { class: "has-submenu" });
    var trigger = Reflx.util.el("a", {
      href: "index.html", class: "submenu-trigger" + (activePage === "home" ? " active" : ""),
      "data-i18n": "nav.home",
      onclick: function (e) {
        // Small caret hit-area heuristic: a click landing in the right ~1.2em of the
        // trigger (where the CSS ::after caret is drawn) toggles the sub-menu instead
        // of navigating — covers touch (tap-to-open, no hover) and mouse click-to-open.
        var rect = trigger.getBoundingClientRect();
        if (e.clientX >= rect.right - 20) {
          e.preventDefault();
          wrap.classList.toggle("open");
        }
      }
    });
    var submenu = Reflx.util.el("div", { class: "submenu" }, [
      Reflx.util.el("a", { href: "reflexes.html", "data-i18n": "nav.reflexes", class: activePage === "reflexes" ? "active" : "" }),
      Reflx.util.el("a", { href: "medical-facts.html", "data-i18n": "nav.medical_facts", class: activePage === "medical-facts" ? "active" : "" }),
      Reflx.util.el("a", { href: "certification.html", "data-i18n": "nav.certification", class: activePage === "certification" ? "active" : "" }),
      Reflx.util.el("a", { href: "offerings.html", "data-i18n": "nav.offerings", class: activePage === "offerings" ? "active" : "" })
    ]);
    wrap.appendChild(trigger);
    wrap.appendChild(submenu);

    // Desktop hover-to-open.
    wrap.addEventListener("mouseenter", function () { wrap.classList.add("open"); });
    wrap.addEventListener("mouseleave", function () { wrap.classList.remove("open"); });
    // Tap-outside-to-close (mobile/click mode, where "open" was toggled via click).
    // Replace any previous render's listener rather than stacking a new one each
    // time draw() rebuilds the nav (reflx:sessionchange).
    if (closeOpenSubmenuOnOutsideClick) {
      document.removeEventListener("click", closeOpenSubmenuOnOutsideClick);
    }
    closeOpenSubmenuOnOutsideClick = function (e) {
      if (!wrap.contains(e.target)) wrap.classList.remove("open");
    };
    document.addEventListener("click", closeOpenSubmenuOnOutsideClick);

    return wrap;
  }

  function render(activePage) {
    var mount = document.getElementById("site-nav");
    if (!mount) return;

    function draw() {
      var t = Reflx.i18n.t;
      mount.innerHTML = "";
      var user = Reflx.session.getUser();
      var links = [
        { href: "browse.html", key: "nav.tests", page: "browse" },
        { href: "paths.html", key: "nav.paths", page: "paths" },
        { href: "settings.html", key: "nav.settings", page: "settings" }
      ];
      if (user) links.push({ href: "stats.html", key: "nav.stats", page: "stats" });
      if (Reflx.session.isAdmin()) links.push({ href: "library.html", key: "nav.library", page: "library" });

      var nav = Reflx.util.el("nav", { class: "site-nav" }, [
        Reflx.util.el("a", { class: "brand", href: "index.html", "data-i18n": "nav.brand" }),
        buildHomeSubmenu(activePage)
      ]);
      links.forEach(function (l) {
        nav.appendChild(Reflx.util.el("a", { href: l.href, "data-i18n": l.key, class: l.page === activePage ? "active" : "" }));
      });

      if (user) {
        nav.appendChild(Reflx.util.el("span", { class: "chip" }, [user.email]));
        nav.appendChild(Reflx.util.el("a", {
          href: "#", "data-i18n": "nav.logout", class: activePage === "login" ? "active" : "",
          onclick: function (e) {
            e.preventDefault();
            Reflx.api.logout().then(function () {
              Reflx.session.clear();
              location.href = "index.html";
            });
          }
        }));
      } else {
        nav.appendChild(Reflx.util.el("a", { href: "auth.html", "data-i18n": "nav.login", class: activePage === "login" ? "active" : "" }));
      }

      mount.appendChild(nav);
      Reflx.i18n.applyToDocument(mount);
    }

    draw();
    document.addEventListener("reflx:sessionchange", draw);
  }

  Reflx.nav = { render: render };
})(window);

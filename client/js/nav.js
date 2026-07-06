/**
 * Reflexometr — shared top navigation.
 * No separate site-wide language switcher here (CR-UI-02 amendment / CR-UI-03): the
 * language selector lives only on the Platform Settings screen.
 * CR-AUTH-01 follow-up: shows Log in / Log out + the current account; the admin
 * library link only renders for the logged-in admin account (a UX nicety — the real
 * 403 boundary is still enforced server-side, D7/SI-1.2).
 */
(function (global) {
  "use strict";
  var Reflx = global.Reflx = global.Reflx || {};

  function render(activePage) {
    var mount = document.getElementById("site-nav");
    if (!mount) return;

    function draw() {
      var t = Reflx.i18n.t;
      mount.innerHTML = "";
      var links = [
        { href: "index.html", key: "nav.home", page: "home" },
        { href: "browse.html", key: "nav.tests", page: "browse" },
        { href: "settings.html", key: "nav.settings", page: "settings" },
        { href: "stats.html", key: "nav.stats", page: "stats" }
      ];
      if (Reflx.session.isAdmin()) links.push({ href: "library.html", key: "nav.library", page: "library" });

      var nav = Reflx.util.el("nav", { class: "site-nav" }, [
        Reflx.util.el("a", { class: "brand", href: "index.html", "data-i18n": "nav.brand" })
      ]);
      links.forEach(function (l) {
        nav.appendChild(Reflx.util.el("a", { href: l.href, "data-i18n": l.key, class: l.page === activePage ? "active" : "" }));
      });

      var user = Reflx.session.getUser();
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

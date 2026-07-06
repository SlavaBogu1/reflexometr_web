/**
 * Reflexometr — auth session storage (CR-AUTH-01 / D14).
 *
 * Bearer-token session per `_API_CONTRACT/CONTRACT.md`: the token is opaque and
 * returned by `POST /auth/register` / `POST /auth/login`. Stored in localStorage
 * (D14 accepts this XSS-exposure trade-off for now — flagged for the Tester's
 * security sweep, not this team's call to silently harden). Retires Sprint 1's
 * anonymous-localStorage-user model: `Reflx.session.getUser()` is now the single
 * source of truth for "who is the current user," replacing the old
 * `reflx.mock.userId` concept entirely.
 */
(function (global) {
  "use strict";
  var Reflx = global.Reflx = global.Reflx || {};
  var KEY = "reflx.session.v1";

  function get() { return Reflx.util.readJSON(KEY, null); }

  /** @param token string  @param user UserProfile (per CONTRACT.md: id, email, is_admin, dominant_hand, preferred_locale) */
  function set(token, user) {
    Reflx.util.writeJSON(KEY, { token: token, user: user });
    document.dispatchEvent(new CustomEvent("reflx:sessionchange", { detail: { token: token, user: user } }));
  }

  function clear() {
    localStorage.removeItem(KEY);
    document.dispatchEvent(new CustomEvent("reflx:sessionchange", { detail: null }));
  }

  function getToken() { var s = get(); return s ? s.token : null; }
  function getUser() { var s = get(); return s ? s.user : null; }
  function isLoggedIn() { return !!getToken(); }
  function isAdmin() { var u = getUser(); return !!(u && u.is_admin); }

  /** Update the cached user profile in place (e.g. after PATCH /profile) without touching the token. */
  function updateUser(patch) {
    var s = get();
    if (!s) return;
    s.user = Object.assign({}, s.user, patch);
    Reflx.util.writeJSON(KEY, s);
    document.dispatchEvent(new CustomEvent("reflx:sessionchange", { detail: s }));
  }

  /** Redirect to the login screen, preserving the current page so it can return here after login. */
  function redirectToLogin() {
    var returnTo = encodeURIComponent(location.pathname.split("/").pop() + location.search);
    location.href = "auth.html?returnTo=" + returnTo;
  }

  Reflx.session = {
    getToken: getToken, getUser: getUser, isLoggedIn: isLoggedIn, isAdmin: isAdmin,
    set: set, clear: clear, updateUser: updateUser, redirectToLogin: redirectToLogin
  };
})(window);

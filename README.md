# Reflexometr

An online reflex-measurement platform. Registered users run a library of reaction-time tests, track
personal statistics over time, and see an anonymized comparison against other users — all scoped
per exact test version so results are never mixed across revisions.

## Features

- **Account system** — registration, login, bearer-token sessions (14-day sliding lifetime)
- **Reflex-test library** — versioned, admin-imported test descriptions; results always tied to an
  exact test + version
- **Two seeded tests** — Simple Visual Reaction Time, Two-Hand Reaction Time (with dominant-hand
  tracking)
- **Series mode** — single run, fixed-count series, or run-until-quit, with a Quit action
- **Personal statistics dashboard** — history and trend per test version, never mixed across versions
- **Anonymized comparison** — percentile/rank against other users of the same test version, no
  identity or raw data exposed
- **Platform Settings** — input-device (keyboard / mouse / gamepad) and key binding configuration,
  reachable without login
- **Internationalization** — English, Spanish, German, French, Simplified Chinese, Russian
- **Public front page** — positions the platform for occupational reflex screening and elderly
  remote-monitoring use cases, backed by cited research

## Architecture

Same-origin, path-split deployment: the domain root serves the static client; `/api/` is aliased to
the PHP API.

```
Static client (client/) ── fetch() ──▶ /api/*  ──▶  PHP API (server/public/index.php)
                                                            │
                                                       MySQL / SQLite
```

- **Client** — vanilla HTML/CSS/JS, no framework, no build step
- **Server** — PHP 8.1+, PDO (MySQL in production, SQLite for local dev)
- **Contract** — `_API_CONTRACT/CONTRACT.md` is the source of truth for every endpoint, request/response
  shape, and error code

## Running locally

**Server** (PHP API):
```bash
cd server
composer install
composer run migrate   # apply schema
composer run seed      # optional: seed sample data
php -S localhost:8000 -t public
```

**Client** (static files):
```bash
cd client
python -m http.server 8080
```

The client defaults to calling `/api` on the same origin. For local dev, where client and server run
on different ports, append `?apiBase=http://localhost:8000` once to any client page — it's captured
and persisted, so you don't need to repeat it.

**Tests:**
```bash
cd server
composer test
```

## Project layout

```
client/            Static frontend — pages, JS modules, per-locale string files
server/            PHP API — controllers, repositories, migrations
_API_CONTRACT/      API contract (owned by the backend, read directly by the frontend)
```

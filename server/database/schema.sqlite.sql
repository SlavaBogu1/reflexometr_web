-- Reflexometr — SQLite schema (LOCAL DEV ONLY substitute for MySQL — see schema.mysql.sql,
-- the production target on HostGator per D1, and server/requirements/SPRINT1_REPORT.md).
-- Keep field names/types semantically in sync with schema.mysql.sql when either changes.

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_admin INTEGER NOT NULL DEFAULT 0,
    dominant_hand VARCHAR(20) NOT NULL DEFAULT 'none-recorded',
    preferred_locale VARCHAR(10) NULL DEFAULT NULL,
    -- CR-AUTH-03 (Sprint 9): optional profile fields, freeform text, no uniqueness constraint.
    real_name VARCHAR(100) NULL DEFAULT NULL,
    display_name VARCHAR(100) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    token VARCHAR(64) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    last_used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS ix_sessions_user ON sessions (user_id);

CREATE TABLE IF NOT EXISTS r_test_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(100) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS r_tests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    category_id INTEGER NULL REFERENCES r_test_categories (id) ON DELETE SET NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS ix_rtests_category ON r_tests (category_id);

CREATE TABLE IF NOT EXISTS r_test_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    r_test_id INTEGER NOT NULL REFERENCES r_tests (id) ON DELETE CASCADE,
    version INTEGER NOT NULL,
    description TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (r_test_id, version)
);

CREATE TABLE IF NOT EXISTS r_test_packages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS r_test_package_items (
    package_id INTEGER NOT NULL REFERENCES r_test_packages (id) ON DELETE CASCADE,
    r_test_id INTEGER NOT NULL REFERENCES r_tests (id) ON DELETE CASCADE,
    PRIMARY KEY (package_id, r_test_id)
);

CREATE TABLE IF NOT EXISTS run_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token VARCHAR(64) NOT NULL UNIQUE,
    user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    r_test_id INTEGER NOT NULL REFERENCES r_tests (id) ON DELETE CASCADE,
    r_test_version_id INTEGER NOT NULL REFERENCES r_test_versions (id) ON DELETE CASCADE,
    series_mode VARCHAR(20) NOT NULL DEFAULT 'single',
    series_id VARCHAR(64) NULL,
    schedule_json TEXT NOT NULL,
    issued_at_ms INTEGER NOT NULL,
    expires_at_ms INTEGER NOT NULL,
    used INTEGER NOT NULL DEFAULT 0,
    used_at_ms INTEGER NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS ix_runtokens_user ON run_tokens (user_id);

-- approval_status (CR-AUTH-02) / sd_ms, cv (CR-STATS-08) — see schema.mysql.sql for full rationale
-- and the production backfill-on-upgrade path (not needed here: SQLite is local-dev-only and
-- tests always build this schema fresh against a new :memory: database, so a plain default
-- column on CREATE TABLE is sufficient — there is never a pre-existing populated file to upgrade).
CREATE TABLE IF NOT EXISTS results (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    r_test_id INTEGER NOT NULL REFERENCES r_tests (id) ON DELETE CASCADE,
    r_test_version_id INTEGER NOT NULL REFERENCES r_test_versions (id) ON DELETE CASCADE,
    run_token_id INTEGER NOT NULL UNIQUE REFERENCES run_tokens (id) ON DELETE CASCADE,
    dominant_hand_at_submission VARCHAR(20) NULL,
    trial_count INTEGER NOT NULL,
    trials_json TEXT NOT NULL,
    summary_json TEXT NULL,
    primary_metric_ms REAL NOT NULL,
    sd_ms REAL NULL DEFAULT NULL,
    cv REAL NULL DEFAULT NULL,
    approval_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    client_started_at_ms INTEGER NOT NULL,
    server_received_at_ms INTEGER NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS ix_results_scope ON results (r_test_id, r_test_version_id);
CREATE INDEX IF NOT EXISTS ix_results_user_scope ON results (user_id, r_test_id, r_test_version_id);
CREATE INDEX IF NOT EXISTS ix_results_approval_status ON results (approval_status);

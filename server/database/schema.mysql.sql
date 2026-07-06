-- Reflexometr — MySQL schema (production target, HostGator cPanel, per D1).
-- Equivalent SQLite schema (local-dev-only substitute) lives at schema.sqlite.sql —
-- keep the two in sync when either changes.

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_admin TINYINT(1) NOT NULL DEFAULT 0,
    dominant_hand VARCHAR(20) NOT NULL DEFAULT 'none-recorded',
    preferred_locale VARCHAR(10) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sessions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    last_used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sessions_token (token),
    KEY ix_sessions_user (user_id),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS r_test_categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS r_tests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    category_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rtests_slug (slug),
    KEY ix_rtests_category (category_id),
    CONSTRAINT fk_rtests_category FOREIGN KEY (category_id) REFERENCES r_test_categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The imported description is opaque textual/JSON data (D11) — never parsed/exposed to the
-- client raw. Only ScheduleCompiler (server-side) reads it.
CREATE TABLE IF NOT EXISTS r_test_versions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    r_test_id INT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    description LONGTEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rtestversions_test_version (r_test_id, version),
    CONSTRAINT fk_rtestversions_test FOREIGN KEY (r_test_id) REFERENCES r_tests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS r_test_packages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS r_test_package_items (
    package_id INT UNSIGNED NOT NULL,
    r_test_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (package_id, r_test_id),
    CONSTRAINT fk_packageitems_package FOREIGN KEY (package_id) REFERENCES r_test_packages (id) ON DELETE CASCADE,
    CONSTRAINT fk_packageitems_test FOREIGN KEY (r_test_id) REFERENCES r_tests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Single-use, short-lived run tokens (D9/D11). schedule_json is the compiled, opaque,
-- already-resolved per-run trial schedule — the same payload returned to the client at
-- issuance, kept server-side too so submission can be validated against it.
CREATE TABLE IF NOT EXISTS run_tokens (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    r_test_id INT UNSIGNED NOT NULL,
    r_test_version_id INT UNSIGNED NOT NULL,
    series_mode VARCHAR(20) NOT NULL DEFAULT 'single',
    series_id VARCHAR(64) NULL,
    schedule_json LONGTEXT NOT NULL,
    issued_at_ms BIGINT UNSIGNED NOT NULL,
    expires_at_ms BIGINT UNSIGNED NOT NULL,
    used TINYINT(1) NOT NULL DEFAULT 0,
    used_at_ms BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_runtokens_token (token),
    KEY ix_runtokens_user (user_id),
    CONSTRAINT fk_runtokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_runtokens_test FOREIGN KEY (r_test_id) REFERENCES r_tests (id) ON DELETE CASCADE,
    CONSTRAINT fk_runtokens_version FOREIGN KEY (r_test_version_id) REFERENCES r_test_versions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every result references both r_test_id AND r_test_version_id (CR-TEST-01, never just the former).
-- dominant_hand_at_submission is captured at submit time, not a live profile lookup (CR-TEST-04).
CREATE TABLE IF NOT EXISTS results (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    r_test_id INT UNSIGNED NOT NULL,
    r_test_version_id INT UNSIGNED NOT NULL,
    run_token_id INT UNSIGNED NOT NULL,
    dominant_hand_at_submission VARCHAR(20) NULL,
    trial_count INT UNSIGNED NOT NULL,
    trials_json LONGTEXT NOT NULL,
    summary_json LONGTEXT NULL,
    primary_metric_ms DOUBLE NOT NULL,
    client_started_at_ms BIGINT UNSIGNED NOT NULL,
    server_received_at_ms BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_results_runtoken (run_token_id),
    KEY ix_results_scope (r_test_id, r_test_version_id),
    KEY ix_results_user_scope (user_id, r_test_id, r_test_version_id),
    CONSTRAINT fk_results_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_results_test FOREIGN KEY (r_test_id) REFERENCES r_tests (id) ON DELETE CASCADE,
    CONSTRAINT fk_results_version FOREIGN KEY (r_test_version_id) REFERENCES r_test_versions (id) ON DELETE CASCADE,
    CONSTRAINT fk_results_runtoken FOREIGN KEY (run_token_id) REFERENCES run_tokens (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

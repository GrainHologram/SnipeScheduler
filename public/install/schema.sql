/*
 * Snipe-IT Booking App – Database Schema
 * -------------------------------------
 * This schema contains ONLY tables owned by the booking application.
 * It does NOT modify or depend on the Snipe-IT production database.
 *
 * Safe to commit to GitHub.
 */

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ------------------------------------------------------
-- Users table
-- (local representation of authenticated users)
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id VARCHAR(64) NOT NULL,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_users_user_id (user_id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Reservations table
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS reservations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id VARCHAR(64) NOT NULL,    -- user identifier
    user_name VARCHAR(255) NOT NULL, -- user display name
    user_email VARCHAR(255) NOT NULL,
    snipeit_user_id INT UNSIGNED DEFAULT NULL, -- optional link to Snipe-IT user id
    name TEXT DEFAULT NULL,          -- user-entered label (e.g. "Studio A shoot")

    asset_id INT UNSIGNED NOT NULL DEFAULT 0,  -- optional: single-asset reservations
    start_datetime DATETIME NOT NULL,
    end_datetime DATETIME NOT NULL,

    status ENUM('pending','confirmed','fulfilled','cancelled','missed') NOT NULL DEFAULT 'pending',

    -- Cached display string of items (for quick admin lists)
    asset_name_cache TEXT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_reservations_user_id (user_id),
    KEY idx_reservations_dates (start_datetime, end_datetime),
    KEY idx_reservations_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Reservation items
-- (models + quantities per reservation)
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS reservation_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reservation_id INT UNSIGNED NOT NULL,
    model_id INT UNSIGNED NOT NULL,
    model_name_cache VARCHAR(255) NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    deleted_at DATETIME DEFAULT NULL,

    PRIMARY KEY (id),
    KEY idx_reservation_items_reservation (reservation_id),
    KEY idx_reservation_items_model (model_id),

    CONSTRAINT fk_res_items_reservation
        FOREIGN KEY (reservation_id)
        REFERENCES reservations (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Checkouts table
-- (tracks physical asset checkout sessions)
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS checkouts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reservation_id INT UNSIGNED DEFAULT NULL,
    parent_checkout_id INT UNSIGNED DEFAULT NULL,
    user_id VARCHAR(64) NOT NULL,
    user_name VARCHAR(255) NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    snipeit_user_id INT UNSIGNED DEFAULT NULL,
    name TEXT DEFAULT NULL,
    start_datetime DATETIME NOT NULL,
    end_datetime DATETIME NOT NULL,
    status ENUM('open','partial','closed') NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_checkouts_user_id (user_id),
    KEY idx_checkouts_dates (start_datetime, end_datetime),
    KEY idx_checkouts_status (status),
    KEY idx_checkouts_reservation (reservation_id),
    KEY idx_checkouts_parent (parent_checkout_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Checkout items
-- (per-asset rows for each checkout session)
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS checkout_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    checkout_id INT UNSIGNED NOT NULL,
    asset_id INT UNSIGNED NOT NULL,
    asset_tag VARCHAR(255) NOT NULL,
    asset_name VARCHAR(255) NOT NULL,
    model_id INT UNSIGNED NOT NULL,
    model_name VARCHAR(255) NOT NULL,
    checked_out_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    checked_in_at DATETIME DEFAULT NULL,

    PRIMARY KEY (id),
    KEY idx_checkout_items_checkout (checkout_id),
    KEY idx_checkout_items_asset (asset_id),
    KEY idx_checkout_items_model (model_id),
    CONSTRAINT fk_checkout_items_checkout
        FOREIGN KEY (checkout_id) REFERENCES checkouts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Cached checked-out assets (from Snipe-IT sync)
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS checked_out_asset_cache (
    asset_id INT UNSIGNED NOT NULL,
    asset_tag VARCHAR(255) NOT NULL,
    asset_name VARCHAR(255) NOT NULL,
    model_id INT UNSIGNED NOT NULL,
    model_name VARCHAR(255) NOT NULL,
    assigned_to_id INT UNSIGNED DEFAULT NULL,
    assigned_to_name VARCHAR(255) DEFAULT NULL,
    assigned_to_email VARCHAR(255) DEFAULT NULL,
    assigned_to_username VARCHAR(255) DEFAULT NULL,
    status_label VARCHAR(255) DEFAULT NULL,
    last_checkout VARCHAR(32) DEFAULT NULL,
    expected_checkin VARCHAR(32) DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (asset_id),
    KEY idx_checked_out_model (model_id),
    KEY idx_checked_out_expected (expected_checkin),
    KEY idx_checked_out_updated (updated_at),
    KEY idx_checked_out_assigned_to (assigned_to_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Activity log
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_type VARCHAR(64) NOT NULL,
    actor_user_id VARCHAR(64) DEFAULT NULL,
    actor_name VARCHAR(255) DEFAULT NULL,
    actor_email VARCHAR(255) DEFAULT NULL,
    subject_type VARCHAR(64) DEFAULT NULL,
    subject_id VARCHAR(64) DEFAULT NULL,
    message VARCHAR(255) NOT NULL,
    metadata TEXT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_activity_event (event_type),
    KEY idx_activity_actor (actor_user_id),
    KEY idx_activity_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Optional: simple schema versioning
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS schema_version (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    version VARCHAR(32) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_schema_version_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_version (version)
VALUES ('v1.1.0');

-- ------------------------------------------------------
-- Opening hours: default weekly schedule
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS opening_hours_default (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    day_of_week TINYINT UNSIGNED NOT NULL,
    is_closed TINYINT(1) NOT NULL DEFAULT 0,
    open_time TIME DEFAULT NULL,
    close_time TIME DEFAULT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_oh_default_day (day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO opening_hours_default (day_of_week, is_closed, open_time, close_time) VALUES
    (1, 0, '09:00:00', '17:00:00'),
    (2, 0, '09:00:00', '17:00:00'),
    (3, 0, '09:00:00', '17:00:00'),
    (4, 0, '09:00:00', '17:00:00'),
    (5, 0, '09:00:00', '17:00:00'),
    (6, 1, NULL, NULL),
    (7, 1, NULL, NULL);

-- ------------------------------------------------------
-- Opening hours: recurring schedule override periods
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS opening_hours_schedules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_oh_schedules_dates (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Opening hours: day rows per schedule
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS opening_hours_schedule_days (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    schedule_id INT UNSIGNED NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL,
    is_closed TINYINT(1) NOT NULL DEFAULT 0,
    open_time TIME DEFAULT NULL,
    close_time TIME DEFAULT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_oh_schedule_day (schedule_id, day_of_week),
    CONSTRAINT fk_oh_schedule_days_schedule
        FOREIGN KEY (schedule_id)
        REFERENCES opening_hours_schedules (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Opening hours: one-off datetime overrides
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS opening_hours_overrides (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    label VARCHAR(255) NOT NULL DEFAULT '',
    start_datetime DATETIME NOT NULL,
    end_datetime DATETIME NOT NULL,
    override_type ENUM('open','closed') NOT NULL DEFAULT 'closed',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_oh_overrides_dates (start_datetime, end_datetime)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_version (version)
VALUES ('v1.2.0');

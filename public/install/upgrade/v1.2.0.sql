-- Upgrade v1.2.0: Opening Hours
--
-- New tables: opening_hours_default, opening_hours_schedules,
--             opening_hours_schedule_days, opening_hours_overrides

-- -------------------------------------------------------
-- 1. Default weekly hours (7 rows, one per weekday)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS opening_hours_default (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    day_of_week TINYINT UNSIGNED NOT NULL,
    is_closed TINYINT(1) NOT NULL DEFAULT 0,
    open_time TIME DEFAULT NULL,
    close_time TIME DEFAULT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_oh_default_day (day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- 2. Recurring schedule override periods
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS opening_hours_schedules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_oh_schedules_dates (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- 3. Day rows per schedule
-- -------------------------------------------------------
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

-- -------------------------------------------------------
-- 4. One-off datetime overrides
-- -------------------------------------------------------
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

-- -------------------------------------------------------
-- 5. Seed default hours: Mon-Fri 09:00-17:00, Sat-Sun closed
-- -------------------------------------------------------
INSERT IGNORE INTO opening_hours_default (day_of_week, is_closed, open_time, close_time) VALUES
    (1, 0, '09:00:00', '17:00:00'),
    (2, 0, '09:00:00', '17:00:00'),
    (3, 0, '09:00:00', '17:00:00'),
    (4, 0, '09:00:00', '17:00:00'),
    (5, 0, '09:00:00', '17:00:00'),
    (6, 1, NULL, NULL),
    (7, 1, NULL, NULL);

-- -------------------------------------------------------
-- 6. Record schema version
-- -------------------------------------------------------
INSERT IGNORE INTO schema_version (version)
VALUES ('v1.2.0');

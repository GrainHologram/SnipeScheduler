-- v1.9.0: Unmatched checkin tracking

CREATE TABLE IF NOT EXISTS unmatched_checkins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    asset_tag VARCHAR(255) NOT NULL,
    asset_name VARCHAR(255) NOT NULL,
    model_id INT UNSIGNED NOT NULL,
    model_name VARCHAR(255) NOT NULL,
    was_checked_out TINYINT(1) NOT NULL DEFAULT 1,
    checked_in_from_user_id INT UNSIGNED DEFAULT NULL,
    checked_in_from_user_name VARCHAR(255) DEFAULT NULL,
    checked_in_by VARCHAR(255) DEFAULT NULL,
    checkout_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_unmatched_asset (asset_id),
    KEY idx_unmatched_model (model_id),
    KEY idx_unmatched_created (created_at),
    KEY idx_unmatched_checkout (checkout_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_version (version) VALUES ('v1.9.0');

-- Upgrade v1.5.0: Add feedback table

CREATE TABLE IF NOT EXISTS feedback (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id VARCHAR(64) NOT NULL,
    user_name VARCHAR(255) NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    category ENUM('bug','feature_request','general') NOT NULL DEFAULT 'general',
    message TEXT NOT NULL,
    screenshot_path VARCHAR(500) DEFAULT NULL,
    status ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
    staff_notes TEXT DEFAULT NULL,
    resolved_by_name VARCHAR(255) DEFAULT NULL,
    resolved_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_feedback_status (status),
    KEY idx_feedback_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_version (version)
VALUES ('v1.5.0');

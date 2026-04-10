-- Upgrade v1.6.0: Add notification_log table for overdue escalation tracking

CREATE TABLE IF NOT EXISTS notification_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    checkout_id INT NOT NULL,
    checkout_item_id INT NULL,
    user_email VARCHAR(255) NOT NULL,
    escalation_tier TINYINT NOT NULL DEFAULT 0,
    channel ENUM('email','discord') NOT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_checkout_tier (checkout_id, escalation_tier),
    INDEX idx_user_email (user_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_version (version)
VALUES ('v1.6.0');

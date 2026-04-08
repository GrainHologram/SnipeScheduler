-- v2.1.0 — Purchase Requests
-- Collects equipment purchase requests from Discord bot and web form.

CREATE TABLE IF NOT EXISTS purchase_requests (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Submitter identity
    submitter_name  VARCHAR(255) NOT NULL,
    submitter_email VARCHAR(255) DEFAULT NULL,
    user_id         VARCHAR(64)  DEFAULT NULL,
    discord_user_id VARCHAR(64)  DEFAULT NULL,
    source          ENUM('discord','web') NOT NULL DEFAULT 'discord',

    -- Request fields
    item_name       VARCHAR(255) NOT NULL,
    description     TEXT NOT NULL,
    department      VARCHAR(255) NOT NULL DEFAULT '',
    item_url        VARCHAR(2048) DEFAULT NULL,
    quantity        INT UNSIGNED NOT NULL DEFAULT 1,
    is_faculty      TINYINT(1) NOT NULL DEFAULT 0,

    -- Status workflow (active: open, approved, held; terminal: purchased, denied, duplicate)
    status          ENUM('open','approved','held','purchased','denied','duplicate') NOT NULL DEFAULT 'open',

    -- Admin-only fields
    importance      ENUM('low','medium','high','critical') DEFAULT NULL,
    decision_comments TEXT DEFAULT NULL,
    decided_by_name VARCHAR(255) DEFAULT NULL,
    decided_at      DATETIME DEFAULT NULL,

    -- Timestamps
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_pr_status (status),
    KEY idx_pr_discord_user (discord_user_id),
    KEY idx_pr_user_id (user_id),
    KEY idx_pr_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_version (version) VALUES ('v2.1.0');

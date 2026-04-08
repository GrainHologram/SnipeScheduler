-- v1.7.0: Discord bot DM notifications + user account page

ALTER TABLE users
  ADD COLUMN discord_user_id VARCHAR(64) DEFAULT NULL,
  ADD COLUMN discord_dm_enabled TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN discord_embed_style ENUM('rich','plain') NOT NULL DEFAULT 'rich',
  ADD UNIQUE KEY uq_users_discord (discord_user_id);

CREATE TABLE IF NOT EXISTS user_discord_preferences (
    user_id INT UNSIGNED NOT NULL,
    event_key VARCHAR(64) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (user_id, event_key),
    CONSTRAINT fk_udp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_version (version) VALUES ('v1.7.0');

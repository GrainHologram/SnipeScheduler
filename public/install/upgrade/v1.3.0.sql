-- Upgrade v1.3.0: Track last login time for users

ALTER TABLE users
    ADD COLUMN last_login_at DATETIME DEFAULT NULL AFTER created_at;

INSERT IGNORE INTO schema_version (version)
VALUES ('v1.3.0');

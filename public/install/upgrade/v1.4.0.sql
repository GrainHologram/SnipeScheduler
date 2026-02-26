-- Upgrade v1.4.0: Add notes field to reservations

ALTER TABLE reservations
    ADD COLUMN notes TEXT DEFAULT NULL AFTER name;

INSERT IGNORE INTO schema_version (version)
VALUES ('v1.4.0');

-- Upgrade: add 'checked_out' to reservations.status ENUM
-- Inserts the new value between 'confirmed' and 'completed'.

ALTER TABLE reservations
    MODIFY COLUMN status ENUM('pending','confirmed','checked_out','completed','cancelled','missed')
    NOT NULL DEFAULT 'pending';

INSERT IGNORE INTO schema_version (version)
VALUES ('v0.10.0-beta');

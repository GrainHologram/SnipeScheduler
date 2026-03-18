ALTER TABLE reservation_items
  ADD COLUMN kit_id INT UNSIGNED DEFAULT NULL AFTER model_name_cache,
  ADD COLUMN kit_name_cache VARCHAR(255) DEFAULT NULL AFTER kit_id;

INSERT IGNORE INTO schema_version (version) VALUES ('v1.8.0');

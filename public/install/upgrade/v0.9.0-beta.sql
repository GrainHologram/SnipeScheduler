-- Upgrade: add index on checked_out_asset_cache.assigned_to_id
-- Required for efficient single-active-checkout queries.

ALTER TABLE checked_out_asset_cache ADD KEY idx_checked_out_assigned_to (assigned_to_id);

INSERT IGNORE INTO schema_version (version)
VALUES ('v0.9.0-beta');

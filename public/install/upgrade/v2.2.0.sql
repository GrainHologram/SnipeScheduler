-- v2.2.0 — Add estimated cost to purchase requests

ALTER TABLE purchase_requests
  ADD COLUMN estimated_cost DECIMAL(10,2) DEFAULT NULL AFTER importance;

INSERT IGNORE INTO schema_version (version) VALUES ('v2.2.0');

-- Utility monitoring upgrade: photos + optional quantity.
-- Run once on an existing UBMSConnect database (mirrors migration_venue_images.sql).
-- image_path stores a photo exactly like venues do; quantity is NULL when a
-- utility has no meaningful count (so no fake capacity is ever displayed).
ALTER TABLE utilities ADD COLUMN image_path VARCHAR(255) NULL AFTER description;
ALTER TABLE utilities ADD COLUMN quantity INT NULL AFTER location;

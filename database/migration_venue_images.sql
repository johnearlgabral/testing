-- Venue photos for the Complete Venue Information feature.
-- Run once on an existing UBMSConnect database.
ALTER TABLE venues ADD COLUMN image_path VARCHAR(255) NULL AFTER description;

-- Complete Venue Information: pricing fields for existing databases.
ALTER TABLE venues ADD COLUMN is_free TINYINT(1) NOT NULL DEFAULT 1 AFTER image_path;
ALTER TABLE venues ADD COLUMN usage_fee DECIMAL(10,2) NULL AFTER is_free;

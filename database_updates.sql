-- Add new columns to custom_services table
ALTER TABLE custom_services
ADD COLUMN service_type VARCHAR(50) DEFAULT 'general' NOT NULL,
ADD COLUMN priority_level VARCHAR(20) DEFAULT 'normal' NOT NULL,
ADD COLUMN availability_type VARCHAR(20) DEFAULT 'always' NOT NULL,
ADD COLUMN additional_notes TEXT,
MODIFY COLUMN category_id INT NULL; 
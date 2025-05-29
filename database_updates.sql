-- Add new columns to custom_services table
ALTER TABLE custom_services
ADD COLUMN service_type VARCHAR(50) DEFAULT 'general' NOT NULL,
ADD COLUMN priority_level VARCHAR(20) DEFAULT 'normal' NOT NULL,
ADD COLUMN availability_type VARCHAR(20) DEFAULT 'always' NOT NULL,
ADD COLUMN additional_notes TEXT,
MODIFY COLUMN category_id INT NULL;

-- Make category_id nullable in custom_services table
ALTER TABLE custom_services MODIFY COLUMN category_id INT NULL;

-- Add a default category for existing services
INSERT INTO service_categories (barangay_id, name, description, icon, display_order, is_active)
SELECT DISTINCT barangay_id, 'General Services', 'Default category for services', 'fa-cog', 1, 1
FROM custom_services
WHERE category_id IS NULL;

-- Update existing services to use the default category
UPDATE custom_services cs
JOIN service_categories sc ON cs.barangay_id = sc.barangay_id
SET cs.category_id = sc.id
WHERE cs.category_id IS NULL; 
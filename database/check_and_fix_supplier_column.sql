-- Check and Fix Supplier Column in Users Table
-- Run this in phpMyAdmin first to ensure the column exists

USE smart_warehouse;

-- Check if supplier_id column exists
SELECT 'Checking users table structure:' as info;
DESCRIBE users;

-- Add supplier_id column if it doesn't exist
ALTER TABLE users ADD COLUMN IF NOT EXISTS supplier_id INT NULL AFTER module;

-- Add foreign key constraint if it doesn't exist
-- Note: This might fail if the constraint already exists, that's okay
SET @sql = 'ALTER TABLE users ADD CONSTRAINT fk_users_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL';
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verify the column was added
SELECT 'Updated users table structure:' as info;
DESCRIBE users;

-- Show all users with their supplier_id
SELECT 'All users with supplier info:' as info;
SELECT u.id, u.username, u.email, u.module, u.supplier_id, s.name as supplier_name
FROM users u
LEFT JOIN suppliers s ON u.supplier_id = s.id;

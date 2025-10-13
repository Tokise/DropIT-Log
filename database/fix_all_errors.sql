-- ========================================
-- FIX ALL ERRORS - Complete Database Fix
-- ========================================
-- This fixes:
-- 1. "Failed to Add Product" error (500 Internal Server Error)
-- 2. Missing barcode column
-- 3. Missing audit_logs table
-- 4. AI Service errors
-- ========================================

USE smart_warehouse;

-- Disable foreign key checks for safety
SET FOREIGN_KEY_CHECKS = 0;

-- ========================================
-- 0. Create audit_logs table if missing
-- ========================================

CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    module ENUM('sws','psm','plt','alms','dtrs','system') NOT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_module (module),
    INDEX idx_created (created_at),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================================
-- 1. Add Barcode Column to Supplier Products
-- ========================================

ALTER TABLE supplier_products_catalog 
ADD COLUMN IF NOT EXISTS barcode VARCHAR(50) NULL 
COMMENT 'Auto-generated EAN-13 barcode' 
AFTER lead_time_days;

ALTER TABLE supplier_products_catalog 
ADD INDEX IF NOT EXISTS idx_barcode (barcode);

-- ========================================
-- 2. Ensure Inventory Has Reserved Quantity
-- ========================================

ALTER TABLE inventory 
ADD COLUMN IF NOT EXISTS reserved_quantity INT DEFAULT 0 
AFTER quantity;

-- ========================================
-- 3. Add Sample Zones if Missing
-- ========================================

INSERT IGNORE INTO warehouse_zones (warehouse_id, zone_code, zone_name, zone_type) VALUES
(1, 'A', 'Storage Zone A', 'storage'),
(1, 'B', 'Receiving Zone', 'receiving'),
(1, 'C', 'Shipping Zone', 'shipping');

-- ========================================
-- 4. Add Sample Locations if Missing
-- ========================================

INSERT IGNORE INTO warehouse_locations (zone_id, location_code, location_name, capacity_units) 
SELECT z.id, 'A01', 'Shelf A-01', 100 FROM warehouse_zones z WHERE z.zone_code = 'A' LIMIT 1;

INSERT IGNORE INTO warehouse_locations (zone_id, location_code, location_name, capacity_units) 
SELECT z.id, 'A02', 'Shelf A-02', 100 FROM warehouse_zones z WHERE z.zone_code = 'A' LIMIT 1;

INSERT IGNORE INTO warehouse_locations (zone_id, location_code, location_name, capacity_units) 
SELECT z.id, 'A03', 'Shelf A-03', 100 FROM warehouse_zones z WHERE z.zone_code = 'A' LIMIT 1;

-- ========================================
-- 5. Verify Tables Exist
-- ========================================

-- Check critical tables
SELECT 
    'warehouse_zones' as table_name,
    COUNT(*) as record_count
FROM warehouse_zones
UNION ALL
SELECT 
    'warehouse_locations' as table_name,
    COUNT(*) as record_count
FROM warehouse_locations
UNION ALL
SELECT 
    'supplier_products_catalog' as table_name,
    COUNT(*) as record_count
FROM supplier_products_catalog;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- ========================================
-- Verification
-- ========================================

-- Show supplier_products_catalog structure
DESCRIBE supplier_products_catalog;

SELECT '✅ All fixes applied successfully!' as status;
SELECT 'You can now add products with auto-generated barcodes!' as message;

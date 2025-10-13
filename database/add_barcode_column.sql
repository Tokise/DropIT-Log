-- Add barcode column to supplier_products_catalog
-- Run this to fix the "Failed to Add Product" error

USE smart_warehouse;

-- Add barcode column if it doesn't exist
ALTER TABLE supplier_products_catalog 
ADD COLUMN IF NOT EXISTS barcode VARCHAR(50) NULL COMMENT 'Auto-generated EAN-13 barcode' 
AFTER lead_time_days;

-- Add index for barcode
ALTER TABLE supplier_products_catalog 
ADD INDEX IF NOT EXISTS idx_barcode (barcode);

-- Verify the column was added
DESCRIBE supplier_products_catalog;

SELECT 'Barcode column added successfully!' as status;

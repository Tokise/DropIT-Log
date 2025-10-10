-- Setup Supplier User
-- Run this in phpMyAdmin to link the supplier1 user to a supplier

USE smart_warehouse;

-- First, check if we have any suppliers
SELECT 'Current Suppliers:' as info;
SELECT id, name, code, email FROM suppliers;

-- Check current supplier1 user
SELECT 'Current supplier1 user:' as info;
SELECT id, username, email, supplier_id FROM users WHERE username = 'supplier1';

-- Link supplier1 user to the first supplier (change the supplier ID if needed)
-- This assumes you have at least one supplier in the suppliers table
UPDATE users 
SET supplier_id = (SELECT id FROM suppliers LIMIT 1)
WHERE username = 'supplier1';

-- Verify the update
SELECT 'Updated supplier1 user:' as info;
SELECT u.id, u.username, u.email, u.supplier_id, s.name as supplier_name 
FROM users u 
LEFT JOIN suppliers s ON u.supplier_id = s.id 
WHERE u.username = 'supplier1';

-- If no suppliers exist, create one first:
-- INSERT INTO suppliers (name, code, contact_person, email, phone, address, country, payment_terms, is_active) 
-- VALUES ('ABC Corp', 'abc-001', 'John', 'abc@supplier.com', '+639554816543', 'Philippines', 'Philippines', 'Net 30', 1);

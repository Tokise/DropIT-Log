-- Smart Warehousing and Procurement System Database Schema

CREATE DATABASE IF NOT EXISTS smart_warehouse;
USE smart_warehouse;

-- Users and Authentication
    CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        email VARCHAR(100) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        full_name VARCHAR(100) NOT NULL,
        role ENUM('admin', 'warehouse_manager', 'procurement_officer', 'operator', 'viewer') DEFAULT 'operator',
        module ENUM('sws','psm','plt','alms','dtrs') NULL,
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_username (username),
        INDEX idx_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Warehouses/Locations
CREATE TABLE IF NOT EXISTS warehouses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(20) UNIQUE NOT NULL,
    address TEXT,
    city VARCHAR(100),
    country VARCHAR(100),
    capacity_cubic_meters DECIMAL(12,2),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Categories
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    parent_id INT NULL,
    location_prefix VARCHAR(10) NULL COMMENT 'Location code prefix for this category',
    zone_letter CHAR(1) NULL COMMENT 'Warehouse zone letter (A-Z)',
    aisle_range VARCHAR(20) NULL COMMENT 'Aisle range for this category (e.g., 01-05)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add location columns to existing categories table if not exists
ALTER TABLE categories ADD COLUMN IF NOT EXISTS location_prefix VARCHAR(10) NULL COMMENT 'Location code prefix for this category' AFTER parent_id;
ALTER TABLE categories ADD COLUMN IF NOT EXISTS zone_letter CHAR(1) NULL COMMENT 'Warehouse zone letter (A-Z)' AFTER location_prefix;
ALTER TABLE categories ADD COLUMN IF NOT EXISTS aisle_range VARCHAR(20) NULL COMMENT 'Aisle range for this category (e.g., 01-05)' AFTER zone_letter;

-- Products/Items
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    category_id INT,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    weight_kg DECIMAL(10,3),
    dimensions_cm VARCHAR(50), -- Format: LxWxH
    reorder_point INT NOT NULL DEFAULT 10,
    reorder_quantity INT NOT NULL DEFAULT 50,
    lead_time_days INT DEFAULT 7,
    is_active BOOLEAN DEFAULT TRUE,
    barcode VARCHAR(100),
    image_url VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_sku (sku),
    INDEX idx_category (category_id),
    INDEX idx_barcode (barcode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Inventory
CREATE TABLE IF NOT EXISTS inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    warehouse_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    reserved_quantity INT NOT NULL DEFAULT 0, -- Items allocated but not yet shipped
    available_quantity INT GENERATED ALWAYS AS (quantity - reserved_quantity) STORED,
    location_code VARCHAR(50), -- Aisle-Rack-Shelf
    last_counted_at TIMESTAMP NULL,
    last_restocked_at TIMESTAMP NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE,
    UNIQUE KEY unique_product_warehouse (product_id, warehouse_id),
    INDEX idx_warehouse (warehouse_id),
    INDEX idx_location (location_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Suppliers
CREATE TABLE IF NOT EXISTS suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    code VARCHAR(50) UNIQUE NOT NULL,
    contact_person VARCHAR(100),
    email VARCHAR(100),
    phone VARCHAR(20),
    address TEXT,
    country VARCHAR(100),
    payment_terms VARCHAR(100), -- e.g., "Net 30", "Net 60"
    rating DECIMAL(3,2) DEFAULT 5.00, -- AI-calculated rating 0-5
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (code),
    INDEX idx_rating (rating)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Supplier Products Catalog (Products that suppliers offer - their own catalog)
CREATE TABLE IF NOT EXISTS supplier_products_catalog (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    product_code VARCHAR(100) NOT NULL,
    description TEXT,
    category VARCHAR(100),
    unit_of_measure VARCHAR(50) DEFAULT 'pcs',
    unit_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(10) DEFAULT 'PHP',
    minimum_order_qty INT DEFAULT 1,
    lead_time_days INT DEFAULT 7,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
    UNIQUE KEY unique_supplier_product (supplier_id, product_code),
    INDEX idx_supplier (supplier_id),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Products offered by suppliers (supplier catalog)';

-- Supplier Products (Legacy - What each supplier can provide - links to our products)
CREATE TABLE IF NOT EXISTS supplier_products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT NOT NULL,
    product_id INT NOT NULL,
    supplier_sku VARCHAR(100),
    unit_price DECIMAL(12,2) NOT NULL,
    lead_time_days INT DEFAULT 7,
    minimum_order_quantity INT DEFAULT 1,
    is_preferred BOOLEAN DEFAULT FALSE,
    last_price_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY unique_supplier_product (supplier_id, product_id),
    INDEX idx_product (product_id),
    INDEX idx_preferred (is_preferred)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Legacy supplier-product links';

-- Purchase Orders
CREATE TABLE IF NOT EXISTS purchase_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    po_number VARCHAR(50) UNIQUE NOT NULL,
    supplier_id INT NOT NULL,
    warehouse_id INT NOT NULL,
    status ENUM('draft', 'pending_approval', 'approved', 'sent', 'partially_received', 'received', 'cancelled') DEFAULT 'draft',
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_by INT NOT NULL,
    approved_by INT NULL,
    expected_delivery_date DATE,
    actual_delivery_date DATE NULL,
    notes TEXT,
    is_ai_generated BOOLEAN DEFAULT FALSE, -- Flag for AI-automated orders
    ai_confidence_score DECIMAL(5,4) NULL, -- AI confidence in this order
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    INDEX idx_po_number (po_number),
    INDEX idx_status (status),
    INDEX idx_supplier (supplier_id),
    INDEX idx_ai_generated (is_ai_generated)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Purchase Order Items (New table structure with supplier products support)
CREATE TABLE IF NOT EXISTS po_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    po_id INT NOT NULL,
    supplier_product_id INT NULL COMMENT 'Reference to supplier catalog product',
    product_id INT NULL COMMENT 'Legacy reference to our products',
    product_name VARCHAR(255) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(15,2) NOT NULL,
    total_price DECIMAL(15,2) NOT NULL,
    received_quantity DECIMAL(10,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (supplier_product_id) REFERENCES supplier_products_catalog(id) ON DELETE RESTRICT,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    INDEX idx_po (po_id),
    INDEX idx_supplier_product (supplier_product_id),
    INDEX idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='PO line items supporting both supplier catalog and legacy products';

-- Purchase Order Items (Legacy table - kept for backward compatibility)
CREATE TABLE IF NOT EXISTS purchase_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    po_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    received_quantity INT DEFAULT 0,
    total_price DECIMAL(12,2) GENERATED ALWAYS AS (quantity * unit_price) STORED,
    FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    INDEX idx_po (po_id),
    INDEX idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Legacy PO items table';

-- Received Items (Items received from POs, waiting to be added to inventory)
CREATE TABLE IF NOT EXISTS received_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    po_id INT NOT NULL,
    po_item_id INT NOT NULL,
    supplier_product_id INT NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    product_code VARCHAR(100) NOT NULL,
    quantity_received DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(15,2) NOT NULL,
    total_amount DECIMAL(15,2) NOT NULL,
    received_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    received_by INT,
    status ENUM('pending', 'added_to_inventory', 'rejected') DEFAULT 'pending',
    inventory_product_id INT NULL COMMENT 'Links to products table after adding to inventory',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (po_item_id) REFERENCES po_items(id) ON DELETE CASCADE,
    FOREIGN KEY (supplier_product_id) REFERENCES supplier_products_catalog(id) ON DELETE RESTRICT,
    FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (inventory_product_id) REFERENCES products(id) ON DELETE SET NULL,
    INDEX idx_po (po_id),
    INDEX idx_status (status),
    INDEX idx_received_date (received_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Received items waiting to be added to inventory';

-- Inventory Transactions (All inventory movements)
CREATE TABLE IF NOT EXISTS inventory_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    warehouse_id INT NOT NULL,
    transaction_type ENUM('receipt', 'shipment', 'adjustment', 'transfer', 'return') NOT NULL,
    quantity INT NOT NULL, -- Positive for additions, negative for removals
    reference_type VARCHAR(50), -- 'purchase_order', 'sales_order', 'adjustment'
    reference_id INT,
    notes TEXT,
    performed_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    FOREIGN KEY (performed_by) REFERENCES users(id),
    INDEX idx_product (product_id),
    INDEX idx_warehouse (warehouse_id),
    INDEX idx_transaction_type (transaction_type),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sales Orders (Outbound)
CREATE TABLE IF NOT EXISTS sales_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(50) UNIQUE NOT NULL,
    customer_name VARCHAR(200) NOT NULL,
    customer_email VARCHAR(100),
    warehouse_id INT NOT NULL,
    status ENUM('pending', 'picking', 'packed', 'shipped', 'delivered', 'cancelled') DEFAULT 'pending',
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    shipping_address TEXT,
    created_by INT NOT NULL,
    assigned_picker INT NULL,
    picked_at TIMESTAMP NULL,
    shipped_at TIMESTAMP NULL,
    delivery_date DATE NULL,
    ai_optimized_route TEXT, -- JSON with AI-optimized picking route
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (assigned_picker) REFERENCES users(id),
    INDEX idx_order_number (order_number),
    INDEX idx_status (status),
    INDEX idx_warehouse (warehouse_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sales Order Items
CREATE TABLE IF NOT EXISTS sales_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    picked_quantity INT DEFAULT 0,
    total_price DECIMAL(12,2) GENERATED ALWAYS AS (quantity * unit_price) STORED,
    FOREIGN KEY (order_id) REFERENCES sales_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    INDEX idx_order (order_id),
    INDEX idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- AI Predictions and Recommendations
CREATE TABLE IF NOT EXISTS ai_predictions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prediction_type ENUM('demand_forecast', 'reorder_recommendation', 'inventory_optimization', 'supplier_recommendation', 'anomaly_detection') NOT NULL,
    product_id INT NULL,
    warehouse_id INT NULL,
    prediction_data JSON NOT NULL, -- Stores prediction details
    confidence_score DECIMAL(5,4) NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'executed', 'expired') DEFAULT 'pending',
    valid_until TIMESTAMP,
    executed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE,
    INDEX idx_type (prediction_type),
    INDEX idx_status (status),
    INDEX idx_product (product_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- AI Automation Logs
CREATE TABLE IF NOT EXISTS ai_automation_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    automation_type VARCHAR(100) NOT NULL,
    action_taken VARCHAR(255) NOT NULL,
    input_data JSON,
    output_data JSON,
    success BOOLEAN DEFAULT TRUE,
    error_message TEXT NULL,
    execution_time_ms INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type (automation_type),
    INDEX idx_created_at (created_at),
    INDEX idx_success (success)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- System Settings
CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT NOT NULL,
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notifications
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('info', 'warning', 'alert', 'success') DEFAULT 'info',
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    action_url VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_read (is_read),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default admin user (password: admin123 - CHANGE THIS!)
INSERT INTO users (username, email, password_hash, full_name, role) VALUES
('admin', 'admin@warehouse.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'admin');
-- Insert sample users for each module (password hash same as above sample)
INSERT INTO users (username, email, password_hash, full_name, role, module) VALUES
('sws.user', 'sws@warehouse.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'SWS Operator', 'operator', 'sws'),
('psm.user', 'psm@warehouse.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'PSM Buyer', 'procurement_officer', 'psm'),
('plt.user', 'plt@warehouse.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'PLT Coordinator', 'operator', 'plt'),
('alms.user', 'alms@warehouse.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'ALMS Technician', 'operator', 'alms'),
('dtrs.user', 'dtrs@warehouse.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'DTRS Controller', 'operator', 'dtrs');
-- Insert sample warehouse
INSERT INTO warehouses (name, code, address, city, country, capacity_cubic_meters) VALUES
('Main Warehouse', 'WH001', '123 Warehouse St', 'Singapore', 'Singapore', 10000.00);

 -- Insert sample categories
 INSERT INTO categories (name, description) VALUES
 ('Electronics', 'Electronic items and components'),
 ('Office Supplies', 'Office and stationery items'),
 ('Raw Materials', 'Manufacturing raw materials');

-- =============================================================
-- PLT: Project Logistics Tracker
-- =============================================================

CREATE TABLE IF NOT EXISTS projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(200) NOT NULL,
    owner VARCHAR(100) NULL,
    budget DECIMAL(14,2) NULL,
    status ENUM('planned','in_progress','on_hold','completed','cancelled') DEFAULT 'planned',
    start_date DATE NULL,
    end_date DATE NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (code),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shipments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NULL,
    carrier VARCHAR(100) NULL,
    tracking_no VARCHAR(100) NULL,
    origin VARCHAR(200) NULL,
    destination VARCHAR(200) NULL,
    eta DATE NULL,
    status ENUM('planned','in_transit','delayed','arrived','cancelled') DEFAULT 'planned',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    INDEX idx_project (project_id),
    INDEX idx_tracking (tracking_no),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shipment_milestones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shipment_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    expected_at DATETIME NULL,
    actual_at DATETIME NULL,
    status ENUM('pending','met','missed') DEFAULT 'pending',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
    INDEX idx_shipment (shipment_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tracking_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shipment_id INT NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    location VARCHAR(200) NULL,
    notes TEXT NULL,
    event_time DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
    INDEX idx_shipment (shipment_id),
    INDEX idx_event_time (event_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================
-- ALMS: Asset Lifecycle & Maintenance System
-- =============================================================

CREATE TABLE IF NOT EXISTS assets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(200) NOT NULL,
    category VARCHAR(100) NULL,
    serial_no VARCHAR(100) NULL,
    location VARCHAR(200) NULL,
    status ENUM('active','maintenance','retired') DEFAULT 'active',
    purchased_at DATE NULL,
    warranty_until DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (code),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maintenance_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT NOT NULL,
    interval_days INT NOT NULL,
    last_service_at DATE NULL,
    next_due_at DATE NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    INDEX idx_asset (asset_id),
    INDEX idx_next_due (next_due_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS work_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT NOT NULL,
    type ENUM('inspection','repair','replacement','calibration','other') DEFAULT 'inspection',
    priority ENUM('low','medium','high','critical') DEFAULT 'medium',
    status ENUM('open','scheduled','in_progress','completed','cancelled') DEFAULT 'open',
    assigned_to INT NULL,
    scheduled_for DATETIME NULL,
    completed_at DATETIME NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_asset (asset_id),
    INDEX idx_assigned_to (assigned_to),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS asset_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    INDEX idx_asset (asset_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================
-- DTRS/DTLRS: Document Tracking & Logistics Records System
-- =============================================================

CREATE TABLE IF NOT EXISTS documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doc_no VARCHAR(100) NULL,
    title VARCHAR(255) NOT NULL,
    type VARCHAR(50) NOT NULL,
    entity_type VARCHAR(50) NULL,
    entity_id INT NULL,
    status ENUM('draft','final','archived') DEFAULT 'draft',
    storage_url VARCHAR(255) NULL,
    checksum VARCHAR(64) NULL,
    uploaded_by INT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type (type),
    INDEX idx_entity (entity_type, entity_id),
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS document_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    related_type VARCHAR(50) NOT NULL,
    related_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    INDEX idx_related (related_type, related_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,
    user_id INT NULL,
    changes_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add barcode image and product image storage to products table
ALTER TABLE products ADD COLUMN IF NOT EXISTS barcode_image LONGTEXT NULL COMMENT 'Base64 encoded barcode image' AFTER barcode;
ALTER TABLE products ADD COLUMN IF NOT EXISTS product_image LONGTEXT NULL COMMENT 'Base64 encoded product image' AFTER barcode_image;

-- Add indexes for better performance
ALTER TABLE products ADD INDEX IF NOT EXISTS idx_barcode_image (barcode_image(100));
ALTER TABLE products ADD INDEX IF NOT EXISTS idx_product_image (product_image(100));

-- ============================================
-- PURCHASE ORDER WORKFLOW ENHANCEMENTS
-- ============================================

-- Fix purchase_orders status ENUM to include all workflow statuses
ALTER TABLE purchase_orders 
MODIFY COLUMN status ENUM('draft', 'pending_approval', 'approved', 'sent', 'partially_received', 'received', 'cancelled') 
DEFAULT 'draft' 
NOT NULL;

-- Add additional columns to purchase_orders for enhanced tracking
ALTER TABLE purchase_orders 
ADD COLUMN IF NOT EXISTS order_date DATE DEFAULT NULL AFTER created_by,
ADD COLUMN IF NOT EXISTS pending_at DATETIME NULL AFTER order_date,
ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL AFTER pending_at,
ADD COLUMN IF NOT EXISTS received_at DATETIME NULL AFTER approved_at,
ADD COLUMN IF NOT EXISTS received_by INT NULL AFTER received_at,
ADD COLUMN IF NOT EXISTS supplier_approved_at DATETIME NULL AFTER received_by,
ADD COLUMN IF NOT EXISTS supplier_approved_by INT NULL AFTER supplier_approved_at,
ADD COLUMN IF NOT EXISTS ai_predicted_delivery DATE NULL AFTER supplier_approved_by,
ADD COLUMN IF NOT EXISTS ai_status_notes TEXT NULL AFTER ai_predicted_delivery,
ADD COLUMN IF NOT EXISTS is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER ai_status_notes;

-- Update existing POs to have order_date
UPDATE purchase_orders 
SET order_date = DATE(created_at) 
WHERE order_date IS NULL;

-- Create PO status history table for audit trail
CREATE TABLE IF NOT EXISTS po_status_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    po_id INT NOT NULL,
    from_status VARCHAR(50) NULL,
    to_status VARCHAR(50) NOT NULL,
    changed_by INT NULL COMMENT 'User ID who made the change',
    changed_by_type ENUM('user', 'supplier', 'system', 'ai') DEFAULT 'user',
    notes TEXT NULL,
    ai_confidence DECIMAL(5,4) NULL COMMENT 'AI confidence score for automated changes',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    INDEX idx_po_id (po_id),
    INDEX idx_status (to_status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Purchase order status change history';

-- Create PO notifications table
CREATE TABLE IF NOT EXISTS po_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    po_id INT NOT NULL,
    recipient_type ENUM('user', 'supplier', 'admin') NOT NULL,
    recipient_id INT NULL,
    notification_type VARCHAR(50) NOT NULL COMMENT 'status_change, approval_needed, etc',
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    INDEX idx_po_id (po_id),
    INDEX idx_recipient (recipient_type, recipient_id),
    INDEX idx_is_read (is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='PO-related notifications';

-- ============================================
-- ARCHIVED PRODUCTS TABLE
-- ============================================

-- Archive table for deleted products
CREATE TABLE IF NOT EXISTS archived_products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Original product data
    original_product_id INT NOT NULL,
    sku VARCHAR(50) NOT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    category_id INT,
    category_name VARCHAR(100),
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    weight_kg DECIMAL(10,3),
    dimensions_cm VARCHAR(50),
    reorder_point INT NOT NULL DEFAULT 10,
    reorder_quantity INT NOT NULL DEFAULT 50,
    lead_time_days INT DEFAULT 7,
    barcode VARCHAR(100),
    barcode_image LONGTEXT,
    product_image LONGTEXT,
    image_url VARCHAR(255),
    
    -- Inventory data at time of deletion (JSON format)
    inventory_data JSON,
    
    -- Audit information
    deleted_by INT NOT NULL,
    deleted_by_name VARCHAR(100),
    deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deletion_reason TEXT,
    
    -- Original timestamps
    original_created_at TIMESTAMP NULL,
    original_updated_at TIMESTAMP NULL,
    
    -- Restoration tracking
    is_restored BOOLEAN DEFAULT FALSE,
    restored_at TIMESTAMP NULL,
    restored_by INT NULL,
    restored_product_id INT NULL,
    
    INDEX idx_original_product_id (original_product_id),
    INDEX idx_sku (sku),
    INDEX idx_deleted_at (deleted_at),
    INDEX idx_deleted_by (deleted_by),
    INDEX idx_is_restored (is_restored),
    FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Stores deleted products with full data for audit trail and potential restoration';

-- ============================================
-- SUPPLIER PORTAL ACCESS
-- ============================================

-- Add supplier_id column to users table for supplier portal access
ALTER TABLE users ADD COLUMN IF NOT EXISTS supplier_id INT NULL AFTER module;
ALTER TABLE users ADD CONSTRAINT fk_users_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL;

-- Create a test supplier user linked to a supplier
INSERT INTO users (username, email, password_hash, full_name, role, module, supplier_id, is_active) 
VALUES (
  'supplier1',
  'supplier@example.com',
  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password: "password"
  'Test Supplier',
  'operator',
  'psm',
  NULL, -- Set this to the supplier ID after creating suppliers: UPDATE users SET supplier_id = 1 WHERE username = 'supplier1';
  1
) ON DUPLICATE KEY UPDATE email = VALUES(email);

-- ============================================
-- SAMPLE SUPPLIERS
-- ============================================

-- Insert sample suppliers first
INSERT INTO suppliers (name, code, contact_person, email, phone, address, country, payment_terms, is_active) VALUES
('ABC Corporation', 'ABC-001', 'John Doe', 'abc@supplier.com', '+639554816543', '123 Supplier Street, Manila', 'Philippines', 'Net 30', 1),
('XYZ Enterprises', 'XYZ-001', 'Jane Smith', 'xyz@supplier.com', '+639554816544', '456 Business Ave, Quezon City', 'Philippines', 'Net 45', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Link supplier1 user to first supplier
UPDATE users SET supplier_id = 1 WHERE username = 'supplier1';

-- ============================================
-- SAMPLE SUPPLIER PRODUCTS CATALOG
-- ============================================

-- Sample supplier products for ABC Corporation (supplier_id = 1)
INSERT INTO supplier_products_catalog (supplier_id, product_name, product_code, description, category, unit_of_measure, unit_price, minimum_order_qty, lead_time_days) VALUES
(1, 'Office Chair Executive', 'ABC-CHAIR-001', 'Ergonomic executive office chair with lumbar support', 'Furniture', 'pcs', 5500.00, 5, 14),
(1, 'Desk Lamp LED', 'ABC-LAMP-001', 'Adjustable LED desk lamp with USB charging port', 'Electronics', 'pcs', 850.00, 10, 7),
(1, 'Whiteboard Magnetic', 'ABC-WB-001', 'Magnetic whiteboard 4x6 feet with aluminum frame', 'Office Supplies', 'pcs', 3200.00, 2, 10),
(1, 'Filing Cabinet 4-Drawer', 'ABC-FILE-001', 'Steel filing cabinet with lock, 4 drawers', 'Furniture', 'pcs', 8500.00, 3, 21),
(2, 'Laptop Dell Latitude', 'XYZ-LAP-001', 'Dell Latitude 5420 14" Business Laptop', 'Electronics', 'pcs', 45000.00, 1, 7),
(2, 'Monitor 24 inch', 'XYZ-MON-001', 'Dell 24" Full HD Monitor', 'Electronics', 'pcs', 8500.00, 2, 5)
ON DUPLICATE KEY UPDATE product_name = VALUES(product_name);
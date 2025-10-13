-- Smart Warehousing and Procurement System Database Schema
-- Consolidated schema with all modules and enhancements

CREATE DATABASE IF NOT EXISTS smart_warehouse;
USE smart_warehouse;

-- Users and Authentication (without supplier_id foreign key initially)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin', 'warehouse_manager', 'procurement_officer', 'operator', 'viewer') DEFAULT 'operator',
    module ENUM('sws','psm','plt','alms','dtrs') NULL,
    supplier_id INT NULL COMMENT 'Links to suppliers table for supplier portal access',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_supplier (supplier_id)
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
    barcode_image LONGTEXT NULL COMMENT 'Base64 encoded barcode image',
    product_image LONGTEXT NULL COMMENT 'Base64 encoded product image',
    image_url VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_sku (sku),
    INDEX idx_category (category_id),
    INDEX idx_barcode (barcode),
    INDEX idx_barcode_image (barcode_image(100)),
    INDEX idx_product_image (product_image(100))
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
    charges_tax TINYINT(1) DEFAULT 1 COMMENT 'Whether this supplier charges tax',
    tax_rate DECIMAL(5,4) DEFAULT 0.12 COMMENT 'Tax rate for this supplier (12% VAT default)',
    tax_id VARCHAR(50) NULL COMMENT 'Supplier tax ID/TIN',
    rating DECIMAL(3,2) DEFAULT 5.00, -- AI-calculated rating 0-5
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (code),
    INDEX idx_rating (rating)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add foreign key constraint to users table after suppliers table is created
ALTER TABLE users ADD CONSTRAINT fk_users_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL;

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
    barcode VARCHAR(50) NULL COMMENT 'Auto-generated EAN-13 barcode',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
    UNIQUE KEY unique_supplier_product (supplier_id, product_code),
    INDEX idx_supplier (supplier_id),
    INDEX idx_active (is_active),
    INDEX idx_product_code (product_code),
    INDEX idx_barcode (barcode)
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
    subtotal DECIMAL(12,2) NULL,
    tax_rate DECIMAL(5,4) DEFAULT 0.00 COMMENT 'Tax rate (e.g., 0.12 for 12% VAT)',
    tax_amount DECIMAL(12,2) DEFAULT 0.00,
    is_tax_inclusive TINYINT(1) DEFAULT 0 COMMENT 'Whether supplier charges tax',
    created_by INT NOT NULL,
    approved_by INT NULL,
    order_date DATE DEFAULT NULL,
    pending_at DATETIME NULL,
    approved_at DATETIME NULL,
    received_at DATETIME NULL,
    received_by INT NULL,
    supplier_approved_at DATETIME NULL,
    supplier_approved_by INT NULL,
    supplier_acknowledged_at DATETIME NULL,
    supplier_response_time_hours DECIMAL(10,2) NULL,
    ai_predicted_delivery DATE NULL,
    ai_status_notes TEXT NULL,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
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
    FOREIGN KEY (received_by) REFERENCES users(id),
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
    qc_status ENUM('pending', 'passed', 'failed') DEFAULT 'pending',
    qc_notes TEXT NULL,
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

-- Goods Receipt Queue (Enhanced for SWS Integration)
CREATE TABLE IF NOT EXISTS receiving_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    receipt_number VARCHAR(50) UNIQUE NULL,
    po_id INT NOT NULL,
    po_item_id INT NOT NULL COMMENT 'Reference to po_items table',
    supplier_product_id INT NOT NULL COMMENT 'Reference to supplier_products_catalog',
    quantity INT NOT NULL DEFAULT 0 COMMENT 'Quantity received',
    status ENUM('awaiting_delivery', 'in_transit', 'received', 'partial', 'complete', 'cancelled') DEFAULT 'awaiting_delivery',
    qc_status ENUM('pending', 'passed', 'failed', 'partial') DEFAULT 'pending',
    qc_notes TEXT NULL,
    location_code VARCHAR(50) COMMENT 'Warehouse location where items are stored',
    batch_number VARCHAR(50) NULL,
    expiry_date DATE NULL,
    inventory_product_id INT NULL COMMENT 'Links to products table after adding to inventory',
    received_by INT NULL,
    received_date TIMESTAMP NULL,
    expected_delivery_date DATE NULL COMMENT 'Expected date of delivery',
    tracking_number VARCHAR(100) NULL COMMENT 'Shipment tracking number',
    carrier VARCHAR(100) NULL COMMENT 'Shipping carrier',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (po_item_id) REFERENCES po_items(id) ON DELETE CASCADE,
    FOREIGN KEY (supplier_product_id) REFERENCES supplier_products_catalog(id) ON DELETE RESTRICT,
    FOREIGN KEY (inventory_product_id) REFERENCES products(id) ON DELETE SET NULL,
    FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_receipt_number (receipt_number),
    INDEX idx_po (po_id),
    INDEX idx_po_item (po_item_id),
    INDEX idx_status (status),
    INDEX idx_qc_status (qc_status),
    INDEX idx_received_date (received_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Goods receipt queue for tracking incoming deliveries';

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

-- Supplier Performance Metrics (Missing table that was causing the error)
CREATE TABLE IF NOT EXISTS supplier_performance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT NOT NULL,
    
    -- Delivery Performance
    total_pos INT DEFAULT 0,
    on_time_deliveries INT DEFAULT 0,
    late_deliveries INT DEFAULT 0,
    on_time_rate DECIMAL(5,2) GENERATED ALWAYS AS (
        CASE WHEN total_pos > 0 THEN (on_time_deliveries / total_pos * 100) ELSE 0 END
    ) STORED,
    
    -- Quality Performance
    total_items_received INT DEFAULT 0,
    items_passed_qc INT DEFAULT 0,
    items_failed_qc INT DEFAULT 0,
    quality_rate DECIMAL(5,2) GENERATED ALWAYS AS (
        CASE WHEN total_items_received > 0 THEN (items_passed_qc / total_items_received * 100) ELSE 0 END
    ) STORED,
    
    -- Response Time
    avg_response_time_hours DECIMAL(10,2) DEFAULT 0,
    
    -- Overall Rating (1-5 stars)
    overall_rating DECIMAL(3,2) DEFAULT 5.00,
    
    -- Last updated
    last_calculated_at DATETIME,
    
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
    UNIQUE KEY unique_supplier (supplier_id),
    INDEX idx_supplier_performance (supplier_id, overall_rating)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Supplier performance metrics and ratings';

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
    INDEX idx_product (product_id),
    INDEX idx_warehouse (warehouse_id),
    INDEX idx_performed_by (performed_by),
    INDEX idx_transaction_type (transaction_type),
    INDEX idx_created_at (created_at),
    CONSTRAINT fk_inv_trans_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_inv_trans_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE,
    CONSTRAINT fk_inv_trans_user FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE CASCADE
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

-- Notifications table removed (see enhanced version at end of file)

-- PO Status History table for audit trail
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

-- PO Notifications table
CREATE TABLE IF NOT EXISTS po_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    po_id INT NOT NULL,
    recipient_type ENUM('user', 'supplier', 'admin', 'sws', 'psm') NOT NULL,
    recipient_id INT NULL,
    notification_type VARCHAR(50) NOT NULL COMMENT 'status_change, approval_needed, shipment_update, etc',
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    module ENUM('sws', 'psm', 'supplier') NOT NULL COMMENT 'Source module of notification',
    priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    requires_action TINYINT(1) DEFAULT 0,
    action_url VARCHAR(255) NULL,
    is_read TINYINT(1) DEFAULT 0,
    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    INDEX idx_po_id (po_id),
    INDEX idx_recipient (recipient_type, recipient_id),
    INDEX idx_module (module),
    INDEX idx_is_read (is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='PO-related notifications';

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
-- ============================================
-- SWS: LOCATION MANAGEMENT SYSTEM
-- ============================================

-- Location Movement Reasons
CREATE TABLE IF NOT EXISTS movement_reasons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) UNIQUE NOT NULL,
    description VARCHAR(200) NOT NULL,
    movement_type ENUM('receipt', 'transfer', 'adjustment', 'return', 'damage', 'sale', 'cycle_count') NOT NULL,
    requires_approval TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_code (code),
    INDEX idx_movement_type (movement_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Standardized reasons for inventory movements';

-- Insert default movement reasons
INSERT INTO movement_reasons (code, description, movement_type, requires_approval) VALUES
('GR', 'Goods Receipt from Supplier', 'receipt', 0),
('TR', 'Transfer Between Locations', 'transfer', 1),
('ADJ-PLUS', 'Adjustment - Increase', 'adjustment', 1),
('ADJ-MINUS', 'Adjustment - Decrease', 'adjustment', 1),
('DMG', 'Damaged Goods', 'damage', 1),
('SALE', 'Sold to Customer', 'sale', 0),
('RET-SUP', 'Return to Supplier', 'return', 1),
('RET-CUST', 'Return from Customer', 'return', 0),
('CYCLE', 'Cycle Count Adjustment', 'cycle_count', 1)
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- ============================================
-- SAMPLE DATA INSERTION
-- ============================================

-- Insert default admin user (password: admin123 - CHANGE THIS!)
INSERT INTO users (username, email, password_hash, full_name, role) VALUES
('admin', 'admin@warehouse.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'admin')
ON DUPLICATE KEY UPDATE email = VALUES(email);

-- Insert sample users for each module (password hash same as above sample)
INSERT INTO users (username, email, password_hash, full_name, role, module) VALUES
('sws.user', 'sws@warehouse.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'SWS Operator', 'operator', 'sws'),
('psm.user', 'psm@warehouse.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'PSM Buyer', 'procurement_officer', 'psm'),
('plt.user', 'plt@warehouse.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'PLT Coordinator', 'operator', 'plt'),
('alms.user', 'alms@warehouse.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'ALMS Technician', 'operator', 'alms'),
('dtrs.user', 'dtrs@warehouse.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'DTRS Controller', 'operator', 'dtrs')
ON DUPLICATE KEY UPDATE email = VALUES(email);

-- Insert sample warehouse
INSERT INTO warehouses (name, code, address, city, country, capacity_cubic_meters) VALUES
('Main Warehouse', 'WH001', '123 Warehouse St', 'Singapore', 'Singapore', 10000.00)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Insert sample categories
INSERT INTO categories (name, description) VALUES
('Electronics', 'Electronic items and components'),
('Office Supplies', 'Office and stationery items'),
('Raw Materials', 'Manufacturing raw materials')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Insert sample suppliers with tax settings
INSERT INTO suppliers (name, code, contact_person, email, phone, address, country, payment_terms, charges_tax, tax_rate, is_active) VALUES
('ABC Corporation', 'ABC-001', 'John Doe', 'abc@supplier.com', '+639554816543', '123 Supplier Street, Manila', 'Philippines', 'Net 30', 1, 0.12, 1),
('XYZ Enterprises', 'XYZ-001', 'Jane Smith', 'xyz@supplier.com', '+639554816544', '456 Business Ave, Quezon City', 'Philippines', 'Net 45', 1, 0.12, 1),
('TechGear Solutions', 'TGS-001', 'Michael Chen', 'contact@techgear.com', '+639554816545', '789 Tech Park, Makati City', 'Philippines', 'Net 30', 1, 0.12, 1),
('Office Essentials Inc', 'OEI-001', 'Sarah Johnson', 'sales@officeessentials.com', '+639554816546', '321 Commerce St, Pasig City', 'Philippines', 'Net 60', 0, 0.00, 1),
('Global Supplies Co', 'GSC-001', 'Robert Martinez', 'info@globalsupplies.com', '+639554816547', '555 Industrial Blvd, Caloocan', 'Philippines', 'Net 45', 0, 0.00, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Create supplier user accounts (password for all: "password")
INSERT INTO users (username, email, password_hash, full_name, role, module, supplier_id, is_active) VALUES
('supplier_abc', 'abc@supplier.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'ABC Corporation', 'operator', 'psm', 1, 1),
('supplier_xyz', 'xyz@supplier.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'XYZ Enterprises', 'operator', 'psm', 2, 1),
('supplier_tgs', 'contact@techgear.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'TechGear Solutions', 'operator', 'psm', 3, 1),
('supplier_oei', 'sales@officeessentials.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Office Essentials Inc', 'operator', 'psm', 4, 1),
('supplier_gsc', 'info@globalsupplies.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Global Supplies Co', 'operator', 'psm', 5, 1),
('supplier1', 'supplier@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Test Supplier', 'operator', 'psm', 1, 1)
ON DUPLICATE KEY UPDATE email = VALUES(email);

-- Sample supplier products for ABC Corporation (supplier_id = 1) - Furniture & Office Supplies
INSERT INTO supplier_products_catalog (supplier_id, product_name, product_code, description, category, unit_of_measure, unit_price, minimum_order_qty, lead_time_days) VALUES
(1, 'Office Chair Executive', 'ABC-CHAIR-001', 'Ergonomic executive office chair with lumbar support', 'Furniture', 'pcs', 5500.00, 5, 14),
(1, 'Desk Lamp LED', 'ABC-LAMP-001', 'Adjustable LED desk lamp with USB charging port', 'Electronics', 'pcs', 850.00, 10, 7),
(1, 'Whiteboard Magnetic', 'ABC-WB-001', 'Magnetic whiteboard 4x6 feet with aluminum frame', 'Office Supplies', 'pcs', 3200.00, 2, 10),
(1, 'Filing Cabinet 4-Drawer', 'ABC-FILE-001', 'Steel filing cabinet with lock, 4 drawers', 'Furniture', 'pcs', 8500.00, 3, 21),
(1, 'Conference Table 8-Seater', 'ABC-TABLE-001', 'Wooden conference table with cable management', 'Furniture', 'pcs', 28000.00, 1, 30),
(1, 'Office Desk L-Shape', 'ABC-DESK-001', 'L-shaped office desk with drawers', 'Furniture', 'pcs', 12500.00, 2, 21),
(1, 'Bookshelf 5-Tier', 'ABC-SHELF-001', 'Steel bookshelf with adjustable shelves', 'Furniture', 'pcs', 4200.00, 3, 14),
(1, 'Visitor Chair Set', 'ABC-CHAIR-002', 'Set of 4 visitor chairs with cushion', 'Furniture', 'set', 6800.00, 2, 14)
ON DUPLICATE KEY UPDATE product_name = VALUES(product_name);

-- XYZ Enterprises (supplier_id = 2) - Electronics & IT Equipment
INSERT INTO supplier_products_catalog (supplier_id, product_name, product_code, description, category, unit_of_measure, unit_price, minimum_order_qty, lead_time_days) VALUES
(2, 'Laptop Dell Latitude', 'XYZ-LAP-001', 'Dell Latitude 5420 14" Business Laptop', 'Electronics', 'pcs', 45000.00, 1, 7),
(2, 'Monitor 24 inch', 'XYZ-MON-001', 'Dell 24" Full HD Monitor', 'Electronics', 'pcs', 8500.00, 2, 5),
(2, 'Wireless Mouse Logitech', 'XYZ-MOUSE-001', 'Logitech M720 Wireless Mouse', 'Electronics', 'pcs', 1250.00, 10, 3),
(2, 'Keyboard Mechanical', 'XYZ-KB-001', 'Mechanical keyboard RGB backlit', 'Electronics', 'pcs', 3500.00, 5, 5),
(2, 'Webcam HD 1080p', 'XYZ-CAM-001', 'Logitech C920 HD Webcam', 'Electronics', 'pcs', 4200.00, 5, 7),
(2, 'Headset Noise Cancelling', 'XYZ-HEAD-001', 'Sony WH-1000XM4 Headset', 'Electronics', 'pcs', 15000.00, 3, 10),
(2, 'USB Hub 7-Port', 'XYZ-HUB-001', 'Powered USB 3.0 Hub 7 ports', 'Electronics', 'pcs', 1800.00, 10, 5),
(2, 'External SSD 1TB', 'XYZ-SSD-001', 'Samsung T7 Portable SSD 1TB', 'Electronics', 'pcs', 6500.00, 5, 7)
ON DUPLICATE KEY UPDATE product_name = VALUES(product_name);

-- TechGear Solutions (supplier_id = 3) - Tech Accessories & Networking
INSERT INTO supplier_products_catalog (supplier_id, product_name, product_code, description, category, unit_of_measure, unit_price, minimum_order_qty, lead_time_days) VALUES
(3, 'Router WiFi 6', 'TGS-ROUTER-001', 'TP-Link AX3000 WiFi 6 Router', 'Networking', 'pcs', 5800.00, 3, 7),
(3, 'Network Switch 24-Port', 'TGS-SWITCH-001', 'Gigabit Ethernet Switch 24 ports', 'Networking', 'pcs', 12000.00, 2, 10),
(3, 'UPS 1500VA', 'TGS-UPS-001', 'APC Back-UPS 1500VA with AVR', 'Electronics', 'pcs', 8500.00, 3, 7),
(3, 'Surge Protector 8-Outlet', 'TGS-SURGE-001', 'Power strip with surge protection', 'Electronics', 'pcs', 1200.00, 10, 5),
(3, 'HDMI Cable 3m', 'TGS-CABLE-001', 'Premium HDMI 2.1 Cable 3 meters', 'Accessories', 'pcs', 450.00, 20, 3),
(3, 'Laptop Stand Aluminum', 'TGS-STAND-001', 'Adjustable aluminum laptop stand', 'Accessories', 'pcs', 1850.00, 10, 5),
(3, 'Monitor Arm Dual', 'TGS-ARM-001', 'Dual monitor arm mount', 'Accessories', 'pcs', 3200.00, 5, 7),
(3, 'Cable Management Kit', 'TGS-CABLE-002', 'Under desk cable organizer kit', 'Accessories', 'set', 850.00, 15, 3)
ON DUPLICATE KEY UPDATE product_name = VALUES(product_name);

-- Office Essentials Inc (supplier_id = 4) - Stationery & Supplies
INSERT INTO supplier_products_catalog (supplier_id, product_name, product_code, description, category, unit_of_measure, unit_price, minimum_order_qty, lead_time_days) VALUES
(4, 'Paper A4 Ream', 'OEI-PAPER-001', 'Copy paper A4 80gsm 500 sheets', 'Stationery', 'ream', 250.00, 50, 3),
(4, 'Ballpen Blue Box', 'OEI-PEN-001', 'Blue ballpoint pen box of 50', 'Stationery', 'box', 180.00, 20, 3),
(4, 'Stapler Heavy Duty', 'OEI-STAPLER-001', 'Heavy duty stapler 100 sheets', 'Office Supplies', 'pcs', 450.00, 10, 5),
(4, 'Folder Expanding A4', 'OEI-FOLDER-001', 'Expanding folder 13 pockets', 'Office Supplies', 'pcs', 120.00, 30, 3),
(4, 'Marker Whiteboard Set', 'OEI-MARKER-001', 'Whiteboard markers set of 12', 'Stationery', 'set', 320.00, 15, 3),
(4, 'Sticky Notes Pack', 'OEI-STICKY-001', 'Post-it notes assorted colors 12 pads', 'Stationery', 'pack', 280.00, 20, 3),
(4, 'Binder Clips Assorted', 'OEI-CLIP-001', 'Binder clips assorted sizes 100pcs', 'Office Supplies', 'box', 150.00, 25, 3),
(4, 'Calculator Desktop', 'OEI-CALC-001', 'Desktop calculator 12-digit display', 'Office Supplies', 'pcs', 650.00, 10, 5)
ON DUPLICATE KEY UPDATE product_name = VALUES(product_name);

-- Global Supplies Co (supplier_id = 5) - Cleaning & Pantry Supplies
INSERT INTO supplier_products_catalog (supplier_id, product_name, product_code, description, category, unit_of_measure, unit_price, minimum_order_qty, lead_time_days) VALUES
(5, 'Tissue Box 200s', 'GSC-TISSUE-001', 'Facial tissue box 200 sheets', 'Cleaning', 'box', 45.00, 100, 5),
(5, 'Hand Sanitizer 500ml', 'GSC-SANITIZER-001', 'Alcohol-based hand sanitizer', 'Cleaning', 'bottle', 120.00, 50, 3),
(5, 'Trash Bags 50pcs', 'GSC-BAGS-001', 'Heavy duty trash bags large', 'Cleaning', 'pack', 180.00, 30, 5),
(5, 'Dishwashing Liquid 1L', 'GSC-DISH-001', 'Dishwashing liquid concentrate', 'Cleaning', 'bottle', 85.00, 40, 3),
(5, 'Coffee 3-in-1 Box', 'GSC-COFFEE-001', 'Instant coffee 3-in-1 box of 30', 'Pantry', 'box', 220.00, 20, 5),
(5, 'Bottled Water 500ml', 'GSC-WATER-001', 'Purified drinking water case of 24', 'Pantry', 'case', 180.00, 50, 3),
(5, 'Paper Towel Roll', 'GSC-TOWEL-001', 'Kitchen paper towel 2-ply', 'Cleaning', 'roll', 55.00, 60, 3),
(5, 'Air Freshener Spray', 'GSC-FRESH-001', 'Room air freshener spray 300ml', 'Cleaning', 'can', 95.00, 40, 5)
ON DUPLICATE KEY UPDATE product_name = VALUES(product_name);

-- Initialize performance metrics for existing suppliers
INSERT INTO supplier_performance (supplier_id, last_calculated_at)
SELECT id, NOW() FROM suppliers
ON DUPLICATE KEY UPDATE last_calculated_at = NOW();

-- Generate receipt numbers for existing records (if any)
UPDATE receiving_queue 
SET receipt_number = CONCAT('GR-', DATE_FORMAT(created_at, '%Y%m%d'), '-', LPAD(id, 6, '0'))
WHERE receipt_number IS NULL;

-- Update existing POs to have order_date
UPDATE purchase_orders 
SET order_date = DATE(created_at) 
WHERE order_date IS NULL;

-- Update existing PO records to calculate tax from total_amount
UPDATE purchase_orders po
JOIN suppliers s ON po.supplier_id = s.id
SET 
    po.subtotal = CASE 
        WHEN s.charges_tax = 1 THEN ROUND(po.total_amount / 1.12, 2)
        ELSE po.total_amount
    END,
    po.tax_rate = CASE 
        WHEN s.charges_tax = 1 THEN 0.12
        ELSE 0.00
    END,
    po.tax_amount = CASE 
        WHEN s.charges_tax = 1 THEN ROUND(po.total_amount - (po.total_amount / 1.12), 2)
        ELSE 0.00
    END,
    po.is_tax_inclusive = s.charges_tax
WHERE po.subtotal IS NULL AND po.total_amount > 0;

-- =============================================================
-- SWS: SIMPLIFIED Warehouse Structure (2 Levels Only)
-- =============================================================

-- Warehouse Zones (Areas within warehouse)
CREATE TABLE IF NOT EXISTS warehouse_zones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    warehouse_id INT NOT NULL,
    zone_code VARCHAR(10) NOT NULL,
    zone_name VARCHAR(100) NOT NULL,
    zone_type ENUM('storage', 'receiving', 'shipping') DEFAULT 'storage',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE,
    UNIQUE KEY unique_warehouse_zone (warehouse_id, zone_code),
    INDEX idx_warehouse (warehouse_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Storage Locations (Specific spots within zones)
CREATE TABLE IF NOT EXISTS warehouse_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    zone_id INT NOT NULL,
    location_code VARCHAR(20) NOT NULL,
    location_name VARCHAR(100),
    capacity_units INT DEFAULT 100,
    current_units INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (zone_id) REFERENCES warehouse_zones(id) ON DELETE CASCADE,
    UNIQUE KEY unique_zone_location (zone_id, location_code),
    INDEX idx_zone (zone_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Product Storage (Where products are stored)
CREATE TABLE IF NOT EXISTS inventory_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    location_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    batch_number VARCHAR(50),
    expiry_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (location_id) REFERENCES warehouse_locations(id) ON DELETE CASCADE,
    INDEX idx_product (product_id),
    INDEX idx_location (location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================
-- Audit Log Table (For all system changes)
-- =============================================================

CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    module ENUM('sws','psm','plt','alms','dtrs','system') NOT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_module (module),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================
-- Enhanced Notifications System
-- =============================================================

-- Drop old notifications table and create new one
DROP TABLE IF EXISTS notifications;

CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL COMMENT 'Specific user (NULL for broadcast)',
    role ENUM('admin', 'warehouse_manager', 'procurement_officer', 'operator', 'viewer', 'supplier') NULL COMMENT 'Role-based notification',
    module ENUM('sws','psm','plt','alms','dtrs','system') NOT NULL,
    type ENUM('info', 'warning', 'alert', 'success', 'error') DEFAULT 'info',
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    action_url VARCHAR(255) NULL COMMENT 'Link to relevant page',
    entity_type VARCHAR(50) NULL COMMENT 'Related entity (po, product, etc)',
    entity_id INT NULL COMMENT 'Related entity ID',
    is_read BOOLEAN DEFAULT FALSE,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL COMMENT 'Auto-delete after this date',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_role (role),
    INDEX idx_module (module),
    INDEX idx_is_read (is_read),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Enhanced notification system for all modules';

-- =============================================================
-- Sample Data and Column Fixes
-- =============================================================

-- Insert sample zones
INSERT IGNORE INTO warehouse_zones (warehouse_id, zone_code, zone_name, zone_type) VALUES
(1, 'A', 'Storage Zone A', 'storage'),
(1, 'B', 'Receiving Zone', 'receiving'),
(1, 'C', 'Shipping Zone', 'shipping');

-- Insert sample locations for Zone A
INSERT IGNORE INTO warehouse_locations (zone_id, location_code, location_name, capacity_units) 
SELECT z.id, 'A01', 'Shelf A-01', 100 FROM warehouse_zones z WHERE z.zone_code = 'A' LIMIT 1;

INSERT IGNORE INTO warehouse_locations (zone_id, location_code, location_name, capacity_units) 
SELECT z.id, 'A02', 'Shelf A-02', 100 FROM warehouse_zones z WHERE z.zone_code = 'A' LIMIT 1;

INSERT IGNORE INTO warehouse_locations (zone_id, location_code, location_name, capacity_units) 
SELECT z.id, 'A03', 'Shelf A-03', 100 FROM warehouse_zones z WHERE z.zone_code = 'A' LIMIT 1;

-- Ensure inventory table has reserved_quantity column
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS reserved_quantity INT DEFAULT 0 AFTER quantity;

-- Ensure supplier_products_catalog has barcode column
ALTER TABLE supplier_products_catalog ADD COLUMN IF NOT EXISTS barcode VARCHAR(50) NULL AFTER lead_time_days;
ALTER TABLE supplier_products_catalog ADD INDEX IF NOT EXISTS idx_barcode (barcode);

-- Enhanced Inventory Transactions (add location tracking columns)
-- Note: These reference warehouse_locations (simplified structure)
ALTER TABLE inventory_transactions 
ADD COLUMN IF NOT EXISTS from_location_id INT NULL COMMENT 'Source location' AFTER warehouse_id,
ADD COLUMN IF NOT EXISTS to_location_id INT NULL COMMENT 'Destination location' AFTER from_location_id,
ADD COLUMN IF NOT EXISTS batch_number VARCHAR(50) NULL AFTER quantity,
ADD COLUMN IF NOT EXISTS reason_code VARCHAR(50) NULL AFTER transaction_type,
ADD COLUMN IF NOT EXISTS approved_by INT NULL AFTER performed_by,
ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL AFTER approved_by;

-- Add indexes for the new columns
ALTER TABLE inventory_transactions 
ADD INDEX IF NOT EXISTS idx_from_location (from_location_id),
ADD INDEX IF NOT EXISTS idx_to_location (to_location_id),
ADD INDEX IF NOT EXISTS idx_reason_code (reason_code),
ADD INDEX IF NOT EXISTS idx_approved_by (approved_by);

-- Add foreign key constraints (only if tables exist)
-- These will fail silently if movement_reasons doesn't exist
SET FOREIGN_KEY_CHECKS = 0;


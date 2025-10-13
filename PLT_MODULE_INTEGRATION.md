# 📦 PLT (Package & Logistics Tracking) Module Integration Guide

## Overview

The **Package & Logistics Tracking (PLT)** module manages the end-to-end tracking of shipments from warehouse to customer delivery. It integrates with SWS for inventory management and DTRS for driver/route assignment.

---

## 🏗️ Module Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    PLT - Logistics Tracking                  │
├─────────────────────────────────────────────────────────────┤
│                                                               │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐  │
│  │   Shipment   │───►│   Tracking   │───►│   Delivery   │  │
│  │  Management  │    │    Events    │    │  Confirmation│  │
│  └──────────────┘    └──────────────┘    └──────────────┘  │
│         │                    │                    │          │
│         ▼                    ▼                    ▼          │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐  │
│  │     SWS      │    │     DTRS     │    │   Customer   │  │
│  │  Inventory   │    │    Driver    │    │    Portal    │  │
│  └──────────────┘    └──────────────┘    └──────────────┘  │
└─────────────────────────────────────────────────────────────┘
```

---

## 📊 Database Schema

### Core Tables

#### 1. shipments
```sql
CREATE TABLE IF NOT EXISTS shipments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tracking_number VARCHAR(50) UNIQUE NOT NULL,
    order_id INT NULL,
    warehouse_id INT NOT NULL,
    customer_name VARCHAR(200) NOT NULL,
    customer_email VARCHAR(100),
    customer_phone VARCHAR(20),
    delivery_address TEXT NOT NULL,
    city VARCHAR(100),
    state VARCHAR(100),
    postal_code VARCHAR(20),
    country VARCHAR(100) DEFAULT 'Philippines',
    status ENUM('pending', 'picked', 'packed', 'in_transit', 'out_for_delivery', 'delivered', 'failed', 'returned') DEFAULT 'pending',
    priority ENUM('standard', 'express', 'urgent') DEFAULT 'standard',
    total_weight_kg DECIMAL(10,2),
    total_value DECIMAL(12,2),
    shipping_cost DECIMAL(10,2),
    driver_id INT NULL,
    vehicle_id INT NULL,
    estimated_delivery DATETIME NULL,
    actual_delivery DATETIME NULL,
    picked_at DATETIME NULL,
    packed_at DATETIME NULL,
    shipped_at DATETIME NULL,
    delivered_at DATETIME NULL,
    notes TEXT,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    FOREIGN KEY (driver_id) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_tracking (tracking_number),
    INDEX idx_status (status),
    INDEX idx_driver (driver_id),
    INDEX idx_delivery_date (estimated_delivery)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### 2. shipment_items
```sql
CREATE TABLE IF NOT EXISTS shipment_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shipment_id INT NOT NULL,
    product_id INT NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    quantity INT NOT NULL,
    weight_kg DECIMAL(10,2),
    value DECIMAL(12,2),
    barcode VARCHAR(50),
    serial_numbers TEXT COMMENT 'JSON array',
    picked TINYINT(1) DEFAULT 0,
    packed TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    INDEX idx_shipment (shipment_id),
    INDEX idx_product (product_id),
    INDEX idx_barcode (barcode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### 3. tracking_events
```sql
CREATE TABLE IF NOT EXISTS tracking_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shipment_id INT NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    status VARCHAR(50) NOT NULL,
    location VARCHAR(200),
    latitude DECIMAL(10,8),
    longitude DECIMAL(11,8),
    description TEXT,
    performed_by INT NULL,
    event_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
    FOREIGN KEY (performed_by) REFERENCES users(id),
    INDEX idx_shipment (shipment_id),
    INDEX idx_event_time (event_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### 4. delivery_proof
```sql
CREATE TABLE IF NOT EXISTS delivery_proof (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shipment_id INT NOT NULL,
    signature_image TEXT COMMENT 'Base64 or file path',
    photo_image TEXT COMMENT 'Base64 or file path',
    recipient_name VARCHAR(200),
    recipient_relationship VARCHAR(100),
    notes TEXT,
    latitude DECIMAL(10,8),
    longitude DECIMAL(11,8),
    delivered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
    UNIQUE KEY unique_shipment_proof (shipment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 🔄 Integration Points

### 1. SWS Integration (Inventory Management)

**When creating shipment:**
```javascript
// 1. Create shipment in PLT
POST /api/plt_shipments.php
{
  "customer_name": "John Doe",
  "delivery_address": "123 Main St",
  "items": [
    {"product_id": 1, "quantity": 5}
  ]
}

// 2. PLT calls SWS to reserve inventory
POST /api/sws_inventory.php?action=reserve
{
  "product_id": 1,
  "quantity": 5,
  "reference_type": "shipment",
  "reference_id": 123
}

// 3. SWS updates inventory
UPDATE inventory 
SET reserved_quantity = reserved_quantity + 5
WHERE product_id = 1;
```

**When shipment is delivered:**
```javascript
// 1. Mark as delivered in PLT
POST /api/plt_shipments.php?action=deliver
{
  "shipment_id": 123,
  "signature": "base64...",
  "photo": "base64..."
}

// 2. PLT calls SWS to reduce inventory
POST /api/sws_inventory.php?action=reduce
{
  "product_id": 1,
  "quantity": 5,
  "reference_type": "shipment",
  "reference_id": 123
}

// 3. SWS updates inventory
UPDATE inventory 
SET quantity = quantity - 5,
    reserved_quantity = reserved_quantity - 5
WHERE product_id = 1;
```

### 2. DTRS Integration (Driver Assignment)

**Assign driver to shipment:**
```javascript
// 1. PLT requests driver assignment
POST /api/plt_shipments.php?action=assign_driver
{
  "shipment_id": 123,
  "driver_id": 5,
  "vehicle_id": 10
}

// 2. PLT notifies DTRS
POST /api/dtrs_assignments.php
{
  "driver_id": 5,
  "shipment_id": 123,
  "pickup_location": "Warehouse A",
  "delivery_location": "123 Main St"
}

// 3. DTRS creates route
INSERT INTO driver_routes (driver_id, shipment_id, status)
VALUES (5, 123, 'assigned');
```

### 3. Customer Portal Integration

**Customer tracking:**
```javascript
// Public tracking endpoint (no auth required)
GET /api/plt_tracking.php?tracking_number=TRK123456789

Response:
{
  "tracking_number": "TRK123456789",
  "status": "in_transit",
  "estimated_delivery": "2025-10-15 14:00:00",
  "events": [
    {
      "event_type": "picked",
      "location": "Warehouse A",
      "time": "2025-10-13 10:00:00"
    },
    {
      "event_type": "in_transit",
      "location": "Distribution Center",
      "time": "2025-10-13 15:00:00"
    }
  ]
}
```

---

## 📡 API Endpoints

### Shipment Management

#### Create Shipment
```
POST /api/plt_shipments.php
Authorization: Bearer {token}

Request:
{
  "customer_name": "John Doe",
  "customer_email": "john@example.com",
  "customer_phone": "+63 912 345 6789",
  "delivery_address": "123 Main St, Manila",
  "city": "Manila",
  "postal_code": "1000",
  "priority": "express",
  "items": [
    {
      "product_id": 1,
      "quantity": 5
    }
  ]
}

Response:
{
  "ok": true,
  "data": {
    "shipment_id": 123,
    "tracking_number": "TRK123456789",
    "estimated_delivery": "2025-10-15 14:00:00"
  }
}
```

#### Update Shipment Status
```
PUT /api/plt_shipments.php?action=update_status
Authorization: Bearer {token}

Request:
{
  "shipment_id": 123,
  "status": "in_transit",
  "location": "Distribution Center",
  "notes": "Package sorted and loaded"
}

Response:
{
  "ok": true,
  "message": "Status updated successfully"
}
```

#### Get Shipment Details
```
GET /api/plt_shipments.php?id=123
Authorization: Bearer {token}

Response:
{
  "ok": true,
  "data": {
    "id": 123,
    "tracking_number": "TRK123456789",
    "customer_name": "John Doe",
    "status": "in_transit",
    "items": [...],
    "tracking_events": [...]
  }
}
```

### Tracking

#### Add Tracking Event
```
POST /api/plt_tracking.php?action=add_event
Authorization: Bearer {token}

Request:
{
  "shipment_id": 123,
  "event_type": "checkpoint",
  "status": "in_transit",
  "location": "Distribution Center",
  "latitude": 14.5995,
  "longitude": 120.9842,
  "description": "Package arrived at distribution center"
}

Response:
{
  "ok": true,
  "event_id": 456
}
```

#### Public Tracking (No Auth)
```
GET /api/plt_tracking.php?tracking_number=TRK123456789

Response:
{
  "ok": true,
  "data": {
    "tracking_number": "TRK123456789",
    "status": "in_transit",
    "estimated_delivery": "2025-10-15 14:00:00",
    "events": [...]
  }
}
```

### Delivery

#### Confirm Delivery
```
POST /api/plt_delivery.php?action=confirm
Authorization: Bearer {token}

Request:
{
  "shipment_id": 123,
  "recipient_name": "John Doe",
  "recipient_relationship": "Self",
  "signature": "data:image/png;base64,...",
  "photo": "data:image/png;base64,...",
  "latitude": 14.5995,
  "longitude": 120.9842,
  "notes": "Delivered to customer"
}

Response:
{
  "ok": true,
  "message": "Delivery confirmed",
  "delivered_at": "2025-10-15 14:30:00"
}
```

---

## 🔔 Notification Flow

### Shipment Created
```javascript
// Notify warehouse staff
notify_module_users('sws', 
  'New Shipment Created',
  `Shipment ${tracking_number} needs to be picked`,
  '/sws.php?tab=shipments'
);

// Notify driver (if assigned)
if (driver_id) {
  notify_user(driver_id,
    'New Delivery Assignment',
    `You have been assigned shipment ${tracking_number}`,
    '/dtrs.php?tab=deliveries'
  );
}
```

### Status Updates
```javascript
// Status: picked → notify packer
notify_module_users('sws',
  'Shipment Ready for Packing',
  `Shipment ${tracking_number} has been picked`,
  '/sws.php?tab=packing'
);

// Status: in_transit → notify customer
send_email(customer_email,
  'Your Package is On The Way',
  `Track your package: ${tracking_url}`
);

// Status: delivered → notify admin
notify_role('admin',
  'Shipment Delivered',
  `Shipment ${tracking_number} delivered successfully`,
  '/plt.php?tab=completed'
);
```

---

## 📱 Frontend Integration

### PLT Dashboard (plt.php)

```html
<!DOCTYPE html>
<html>
<head>
    <title>PLT - Package & Logistics Tracking</title>
</head>
<body>
    <div class="container">
        <!-- Tabs -->
        <ul class="nav nav-tabs">
            <li><a href="#shipments">Active Shipments</a></li>
            <li><a href="#tracking">Track Package</a></li>
            <li><a href="#delivery">Delivery Proof</a></li>
            <li><a href="#reports">Reports</a></li>
        </ul>

        <!-- Active Shipments Tab -->
        <div id="shipments">
            <button onclick="PLT.createShipment()">Create Shipment</button>
            <table id="shipmentsTable">
                <!-- Populated via API -->
            </table>
        </div>

        <!-- Tracking Tab -->
        <div id="tracking">
            <input type="text" id="trackingNumber" placeholder="Enter tracking number">
            <button onclick="PLT.trackShipment()">Track</button>
            <div id="trackingResults"></div>
        </div>
    </div>

    <script src="js/plt.js"></script>
</body>
</html>
```

### JavaScript (plt.js)

```javascript
const PLT = {
    // Create new shipment
    createShipment: async function() {
        const data = {
            customer_name: $('#customerName').val(),
            delivery_address: $('#deliveryAddress').val(),
            items: this.getSelectedItems()
        };

        const response = await fetch('/api/plt_shipments.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        });

        const result = await response.json();
        if (result.ok) {
            alert(`Shipment created! Tracking: ${result.data.tracking_number}`);
            this.loadShipments();
        }
    },

    // Track shipment
    trackShipment: async function() {
        const trackingNumber = $('#trackingNumber').val();
        const response = await fetch(`/api/plt_tracking.php?tracking_number=${trackingNumber}`);
        const result = await response.json();

        if (result.ok) {
            this.displayTrackingInfo(result.data);
        }
    },

    // Update status
    updateStatus: async function(shipmentId, status) {
        const response = await fetch('/api/plt_shipments.php?action=update_status', {
            method: 'PUT',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({shipment_id: shipmentId, status: status})
        });

        const result = await response.json();
        if (result.ok) {
            this.loadShipments();
        }
    }
};
```

---

## 🔐 Security & Permissions

### Role-Based Access

```php
// In plt_shipments.php
$auth = require_auth();

// Check permissions
if ($action === 'create' && !in_array($auth['role'], ['admin', 'warehouse_manager'])) {
    json_err('Insufficient permissions', 403);
}

if ($action === 'deliver' && !in_array($auth['role'], ['admin', 'driver'])) {
    json_err('Only drivers can confirm delivery', 403);
}
```

### Public Tracking

```php
// Public endpoint - no auth required
if ($action === 'track') {
    $trackingNumber = $_GET['tracking_number'] ?? null;
    
    // Return limited info for security
    $stmt = $conn->prepare("
        SELECT tracking_number, status, estimated_delivery
        FROM shipments
        WHERE tracking_number = :tn
    ");
    $stmt->execute([':tn' => $trackingNumber]);
    $shipment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    json_ok($shipment);
}
```

---

## 📊 Reports & Analytics

### Delivery Performance
```sql
SELECT 
    DATE(delivered_at) as delivery_date,
    COUNT(*) as total_deliveries,
    SUM(CASE WHEN delivered_at <= estimated_delivery THEN 1 ELSE 0 END) as on_time,
    AVG(TIMESTAMPDIFF(HOUR, shipped_at, delivered_at)) as avg_delivery_hours
FROM shipments
WHERE status = 'delivered'
    AND delivered_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY DATE(delivered_at);
```

### Driver Performance
```sql
SELECT 
    u.full_name as driver_name,
    COUNT(s.id) as total_deliveries,
    AVG(TIMESTAMPDIFF(HOUR, s.shipped_at, s.delivered_at)) as avg_delivery_time,
    SUM(CASE WHEN s.status = 'delivered' THEN 1 ELSE 0 END) as successful_deliveries
FROM shipments s
JOIN users u ON s.driver_id = u.id
WHERE s.shipped_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY s.driver_id;
```

---

## ✅ Implementation Checklist

- [ ] Create database tables (shipments, shipment_items, tracking_events, delivery_proof)
- [ ] Implement API endpoints (plt_shipments.php, plt_tracking.php, plt_delivery.php)
- [ ] Create frontend (plt.php, plt.js)
- [ ] Integrate with SWS (inventory reservation/reduction)
- [ ] Integrate with DTRS (driver assignment)
- [ ] Implement notification system
- [ ] Add public tracking page
- [ ] Test delivery confirmation with photo/signature
- [ ] Create reports and analytics
- [ ] Add barcode scanning for shipment items

**PLT Module Complete!** 📦

# 🏭 ALMS (Asset & Lifecycle Management System) Module Integration Guide

## Overview

The **Asset & Lifecycle Management System (ALMS)** manages all physical assets including warehouse equipment, vehicles, machinery, and IT assets. It tracks maintenance schedules, depreciation, and asset lifecycle from acquisition to disposal.

---

## 🏗️ Module Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    ALMS - Asset Management                   │
├─────────────────────────────────────────────────────────────┤
│                                                               │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐  │
│  │    Asset     │───►│ Maintenance  │───►│ Depreciation │  │
│  │  Registry    │    │  Scheduling  │    │   Tracking   │  │
│  └──────────────┘    └──────────────┘    └──────────────┘  │
│         │                    │                    │          │
│         ▼                    ▼                    ▼          │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐  │
│  │   Warehouse  │    │     DTRS     │    │  Financial   │  │
│  │   Equipment  │    │   Vehicles   │    │   Reports    │  │
│  └──────────────┘    └──────────────┘    └──────────────┘  │
└─────────────────────────────────────────────────────────────┘
```

---

## 📊 Database Schema

### Core Tables

#### 1. assets
```sql
CREATE TABLE IF NOT EXISTS assets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_code VARCHAR(50) UNIQUE NOT NULL,
    asset_name VARCHAR(255) NOT NULL,
    asset_type ENUM('vehicle', 'equipment', 'machinery', 'it_hardware', 'furniture', 'building', 'other') NOT NULL,
    category VARCHAR(100),
    description TEXT,
    manufacturer VARCHAR(200),
    model VARCHAR(200),
    serial_number VARCHAR(200),
    barcode VARCHAR(50),
    qr_code VARCHAR(100),
    purchase_date DATE,
    purchase_cost DECIMAL(15,2),
    current_value DECIMAL(15,2),
    depreciation_method ENUM('straight_line', 'declining_balance', 'units_of_production', 'none') DEFAULT 'straight_line',
    useful_life_years INT DEFAULT 5,
    salvage_value DECIMAL(15,2) DEFAULT 0,
    location VARCHAR(200),
    warehouse_id INT NULL,
    assigned_to INT NULL COMMENT 'User ID',
    status ENUM('active', 'maintenance', 'retired', 'disposed', 'lost', 'damaged') DEFAULT 'active',
    condition_rating ENUM('excellent', 'good', 'fair', 'poor') DEFAULT 'good',
    warranty_expiry DATE,
    insurance_policy VARCHAR(100),
    insurance_expiry DATE,
    last_maintenance_date DATE,
    next_maintenance_date DATE,
    maintenance_interval_days INT DEFAULT 90,
    notes TEXT,
    photo_url VARCHAR(500),
    documents TEXT COMMENT 'JSON array of document URLs',
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    FOREIGN KEY (assigned_to) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_asset_code (asset_code),
    INDEX idx_asset_type (asset_type),
    INDEX idx_status (status),
    INDEX idx_warehouse (warehouse_id),
    INDEX idx_barcode (barcode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### 2. asset_maintenance
```sql
CREATE TABLE IF NOT EXISTS asset_maintenance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT NOT NULL,
    maintenance_type ENUM('preventive', 'corrective', 'inspection', 'calibration', 'upgrade') NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    scheduled_date DATE NOT NULL,
    completed_date DATE NULL,
    status ENUM('scheduled', 'in_progress', 'completed', 'cancelled', 'overdue') DEFAULT 'scheduled',
    priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    cost DECIMAL(12,2) DEFAULT 0,
    performed_by INT NULL,
    vendor VARCHAR(200),
    parts_replaced TEXT COMMENT 'JSON array',
    downtime_hours DECIMAL(8,2),
    notes TEXT,
    attachments TEXT COMMENT 'JSON array of file URLs',
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    FOREIGN KEY (performed_by) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_asset (asset_id),
    INDEX idx_status (status),
    INDEX idx_scheduled_date (scheduled_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### 3. asset_depreciation
```sql
CREATE TABLE IF NOT EXISTS asset_depreciation (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT NOT NULL,
    year INT NOT NULL,
    month INT NOT NULL,
    opening_value DECIMAL(15,2) NOT NULL,
    depreciation_amount DECIMAL(15,2) NOT NULL,
    accumulated_depreciation DECIMAL(15,2) NOT NULL,
    closing_value DECIMAL(15,2) NOT NULL,
    calculation_method VARCHAR(50),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    UNIQUE KEY unique_asset_period (asset_id, year, month),
    INDEX idx_asset (asset_id),
    INDEX idx_period (year, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### 4. asset_transfers
```sql
CREATE TABLE IF NOT EXISTS asset_transfers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT NOT NULL,
    from_location VARCHAR(200),
    to_location VARCHAR(200),
    from_warehouse_id INT NULL,
    to_warehouse_id INT NULL,
    from_user_id INT NULL,
    to_user_id INT NULL,
    transfer_date DATE NOT NULL,
    reason TEXT,
    status ENUM('pending', 'approved', 'completed', 'rejected') DEFAULT 'pending',
    approved_by INT NULL,
    approved_at DATETIME NULL,
    notes TEXT,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    FOREIGN KEY (from_warehouse_id) REFERENCES warehouses(id),
    FOREIGN KEY (to_warehouse_id) REFERENCES warehouses(id),
    FOREIGN KEY (from_user_id) REFERENCES users(id),
    FOREIGN KEY (to_user_id) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_asset (asset_id),
    INDEX idx_status (status),
    INDEX idx_transfer_date (transfer_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### 5. asset_inspections
```sql
CREATE TABLE IF NOT EXISTS asset_inspections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT NOT NULL,
    inspection_date DATE NOT NULL,
    inspector_id INT NOT NULL,
    inspection_type ENUM('routine', 'safety', 'quality', 'compliance', 'pre_maintenance') NOT NULL,
    condition_rating ENUM('excellent', 'good', 'fair', 'poor') NOT NULL,
    findings TEXT,
    issues_found TEXT COMMENT 'JSON array',
    recommendations TEXT,
    photos TEXT COMMENT 'JSON array of image URLs',
    requires_maintenance TINYINT(1) DEFAULT 0,
    requires_repair TINYINT(1) DEFAULT 0,
    passed TINYINT(1) DEFAULT 1,
    next_inspection_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    FOREIGN KEY (inspector_id) REFERENCES users(id),
    INDEX idx_asset (asset_id),
    INDEX idx_inspection_date (inspection_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 🔄 Integration Points

### 1. SWS Integration (Warehouse Equipment)

**Register warehouse equipment:**
```javascript
// 1. Create asset in ALMS
POST /api/alms_assets.php
{
  "asset_name": "Forklift #1",
  "asset_type": "equipment",
  "category": "Material Handling",
  "warehouse_id": 1,
  "location": "Zone A",
  "purchase_cost": 50000,
  "purchase_date": "2025-01-15"
}

// 2. ALMS notifies SWS
POST /api/sws_equipment.php
{
  "asset_id": 123,
  "equipment_name": "Forklift #1",
  "warehouse_id": 1,
  "status": "active"
}

// 3. SWS can track equipment usage
UPDATE assets SET status = 'maintenance'
WHERE id = 123;
```

### 2. DTRS Integration (Vehicle Management)

**Register vehicle:**
```javascript
// 1. Create vehicle asset in ALMS
POST /api/alms_assets.php
{
  "asset_name": "Delivery Van #5",
  "asset_type": "vehicle",
  "model": "Toyota Hiace 2024",
  "serial_number": "VIN123456789",
  "purchase_cost": 1500000,
  "assigned_to": 5  // Driver ID
}

// 2. ALMS notifies DTRS
POST /api/dtrs_vehicles.php
{
  "asset_id": 456,
  "vehicle_name": "Delivery Van #5",
  "driver_id": 5,
  "status": "active"
}

// 3. DTRS tracks vehicle usage
INSERT INTO vehicle_trips (vehicle_id, driver_id, distance_km)
VALUES (456, 5, 150);

// 4. ALMS updates maintenance based on usage
UPDATE assets 
SET next_maintenance_date = DATE_ADD(CURDATE(), INTERVAL 30 DAY)
WHERE id = 456 AND usage_km >= 5000;
```

### 3. Financial System Integration

**Calculate depreciation:**
```javascript
// Monthly depreciation calculation
POST /api/alms_depreciation.php?action=calculate_monthly

// Straight-line method
const annualDepreciation = (purchase_cost - salvage_value) / useful_life_years;
const monthlyDepreciation = annualDepreciation / 12;

// Insert depreciation record
INSERT INTO asset_depreciation (
    asset_id, year, month, 
    opening_value, depreciation_amount, 
    accumulated_depreciation, closing_value
) VALUES (
    123, 2025, 10,
    50000, 833.33, 8333.33, 41666.67
);
```

---

## 📡 API Endpoints

### Asset Management

#### Create Asset
```
POST /api/alms_assets.php
Authorization: Bearer {token}

Request:
{
  "asset_code": "EQ-2025-001",
  "asset_name": "Forklift #1",
  "asset_type": "equipment",
  "category": "Material Handling",
  "manufacturer": "Toyota",
  "model": "8FG25",
  "serial_number": "SN123456",
  "purchase_date": "2025-01-15",
  "purchase_cost": 50000,
  "warehouse_id": 1,
  "location": "Zone A",
  "useful_life_years": 10,
  "depreciation_method": "straight_line"
}

Response:
{
  "ok": true,
  "data": {
    "asset_id": 123,
    "asset_code": "EQ-2025-001",
    "qr_code": "generated_qr_code_url"
  }
}
```

#### Get Asset Details
```
GET /api/alms_assets.php?id=123
Authorization: Bearer {token}

Response:
{
  "ok": true,
  "data": {
    "id": 123,
    "asset_code": "EQ-2025-001",
    "asset_name": "Forklift #1",
    "status": "active",
    "current_value": 41666.67,
    "next_maintenance_date": "2025-11-15",
    "maintenance_history": [...],
    "depreciation_schedule": [...]
  }
}
```

#### Update Asset Status
```
PUT /api/alms_assets.php?action=update_status
Authorization: Bearer {token}

Request:
{
  "asset_id": 123,
  "status": "maintenance",
  "notes": "Scheduled maintenance"
}

Response:
{
  "ok": true,
  "message": "Asset status updated"
}
```

### Maintenance Management

#### Schedule Maintenance
```
POST /api/alms_maintenance.php
Authorization: Bearer {token}

Request:
{
  "asset_id": 123,
  "maintenance_type": "preventive",
  "title": "3-Month Service",
  "description": "Oil change, filter replacement, inspection",
  "scheduled_date": "2025-11-15",
  "priority": "medium",
  "estimated_cost": 5000
}

Response:
{
  "ok": true,
  "maintenance_id": 456
}
```

#### Complete Maintenance
```
PUT /api/alms_maintenance.php?action=complete
Authorization: Bearer {token}

Request:
{
  "maintenance_id": 456,
  "completed_date": "2025-11-15",
  "actual_cost": 4500,
  "performed_by": 5,
  "parts_replaced": ["Oil filter", "Air filter"],
  "downtime_hours": 2.5,
  "notes": "All checks passed"
}

Response:
{
  "ok": true,
  "message": "Maintenance completed",
  "next_maintenance_date": "2026-02-15"
}
```

### Depreciation

#### Calculate Depreciation
```
POST /api/alms_depreciation.php?action=calculate
Authorization: Bearer {token}

Request:
{
  "asset_id": 123,
  "year": 2025,
  "month": 10
}

Response:
{
  "ok": true,
  "data": {
    "opening_value": 50000,
    "depreciation_amount": 833.33,
    "accumulated_depreciation": 8333.33,
    "closing_value": 41666.67
  }
}
```

#### Get Depreciation Schedule
```
GET /api/alms_depreciation.php?asset_id=123
Authorization: Bearer {token}

Response:
{
  "ok": true,
  "data": {
    "asset_id": 123,
    "purchase_cost": 50000,
    "current_value": 41666.67,
    "schedule": [
      {
        "year": 2025,
        "month": 1,
        "depreciation": 833.33,
        "value": 49166.67
      },
      ...
    ]
  }
}
```

### Asset Transfer

#### Request Transfer
```
POST /api/alms_transfers.php
Authorization: Bearer {token}

Request:
{
  "asset_id": 123,
  "from_location": "Warehouse A - Zone A",
  "to_location": "Warehouse B - Zone C",
  "from_warehouse_id": 1,
  "to_warehouse_id": 2,
  "transfer_date": "2025-10-20",
  "reason": "Operational requirement"
}

Response:
{
  "ok": true,
  "transfer_id": 789,
  "status": "pending",
  "requires_approval": true
}
```

#### Approve Transfer
```
PUT /api/alms_transfers.php?action=approve
Authorization: Bearer {token}

Request:
{
  "transfer_id": 789,
  "approved": true,
  "notes": "Approved by warehouse manager"
}

Response:
{
  "ok": true,
  "message": "Transfer approved"
}
```

---

## 🔔 Notification Flow

### Maintenance Due
```javascript
// Check for upcoming maintenance (daily cron job)
SELECT a.id, a.asset_name, a.next_maintenance_date
FROM assets a
WHERE a.next_maintenance_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
  AND a.status = 'active';

// Notify asset manager
notify_module_users('alms',
  'Maintenance Due',
  `Asset ${asset_name} requires maintenance on ${next_maintenance_date}`,
  '/alms.php?tab=maintenance'
);
```

### Asset Status Change
```javascript
// Asset goes to maintenance
UPDATE assets SET status = 'maintenance' WHERE id = 123;

// Notify relevant users
if (asset_type === 'vehicle') {
  notify_module_users('dtrs',
    'Vehicle Unavailable',
    `Vehicle ${asset_name} is under maintenance`,
    '/dtrs.php?tab=vehicles'
  );
}

if (warehouse_id) {
  notify_module_users('sws',
    'Equipment Unavailable',
    `Equipment ${asset_name} is under maintenance`,
    '/sws.php?tab=equipment'
  );
}
```

### Depreciation Calculated
```javascript
// Monthly depreciation calculation complete
notify_role('admin',
  'Monthly Depreciation Calculated',
  `Asset depreciation for ${month}/${year} has been calculated`,
  '/alms.php?tab=reports'
);
```

---

## 📱 Frontend Integration

### ALMS Dashboard (alms.php)

```html
<!DOCTYPE html>
<html>
<head>
    <title>ALMS - Asset & Lifecycle Management</title>
</head>
<body>
    <div class="container">
        <!-- Tabs -->
        <ul class="nav nav-tabs">
            <li><a href="#assets">Asset Registry</a></li>
            <li><a href="#maintenance">Maintenance</a></li>
            <li><a href="#depreciation">Depreciation</a></li>
            <li><a href="#transfers">Transfers</a></li>
            <li><a href="#reports">Reports</a></li>
        </ul>

        <!-- Asset Registry Tab -->
        <div id="assets">
            <button onclick="ALMS.createAsset()">Register Asset</button>
            <button onclick="ALMS.scanQR()">Scan QR Code</button>
            
            <!-- Filters -->
            <select id="assetTypeFilter">
                <option value="">All Types</option>
                <option value="vehicle">Vehicles</option>
                <option value="equipment">Equipment</option>
                <option value="machinery">Machinery</option>
            </select>
            
            <table id="assetsTable">
                <!-- Populated via API -->
            </table>
        </div>

        <!-- Maintenance Tab -->
        <div id="maintenance">
            <button onclick="ALMS.scheduleMaintenance()">Schedule Maintenance</button>
            
            <div class="maintenance-calendar">
                <!-- Calendar view of scheduled maintenance -->
            </div>
            
            <table id="maintenanceTable">
                <!-- Upcoming and overdue maintenance -->
            </table>
        </div>

        <!-- Depreciation Tab -->
        <div id="depreciation">
            <button onclick="ALMS.calculateDepreciation()">Calculate Monthly Depreciation</button>
            
            <div class="depreciation-summary">
                <h3>Total Asset Value: ₱<span id="totalValue">0</span></h3>
                <h3>Total Depreciation: ₱<span id="totalDepreciation">0</span></h3>
            </div>
            
            <table id="depreciationTable">
                <!-- Asset depreciation details -->
            </table>
        </div>
    </div>

    <script src="js/alms.js"></script>
</body>
</html>
```

### JavaScript (alms.js)

```javascript
const ALMS = {
    // Register new asset
    createAsset: async function() {
        const data = {
            asset_name: $('#assetName').val(),
            asset_type: $('#assetType').val(),
            purchase_cost: $('#purchaseCost').val(),
            purchase_date: $('#purchaseDate').val(),
            warehouse_id: $('#warehouseId').val()
        };

        const response = await fetch('/api/alms_assets.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        });

        const result = await response.json();
        if (result.ok) {
            alert(`Asset registered! Code: ${result.data.asset_code}`);
            this.generateQRCode(result.data.asset_id);
            this.loadAssets();
        }
    },

    // Schedule maintenance
    scheduleMaintenance: async function(assetId) {
        const data = {
            asset_id: assetId,
            maintenance_type: $('#maintenanceType').val(),
            scheduled_date: $('#scheduledDate').val(),
            description: $('#description').val()
        };

        const response = await fetch('/api/alms_maintenance.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        });

        const result = await response.json();
        if (result.ok) {
            alert('Maintenance scheduled successfully');
            this.loadMaintenance();
        }
    },

    // Calculate depreciation
    calculateDepreciation: async function() {
        const year = new Date().getFullYear();
        const month = new Date().getMonth() + 1;

        const response = await fetch('/api/alms_depreciation.php?action=calculate_all', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({year, month})
        });

        const result = await response.json();
        if (result.ok) {
            alert('Depreciation calculated for all assets');
            this.loadDepreciation();
        }
    },

    // Generate QR code for asset
    generateQRCode: function(assetId) {
        const qrData = `ASSET-${assetId}`;
        // Use QR code library to generate
        $('#qrCodeDisplay').html(`<img src="/api/generate_qr.php?data=${qrData}">`);
    },

    // Scan QR code
    scanQR: function() {
        // Open camera for QR scanning
        // On scan, load asset details
        this.loadAssetDetails(scannedAssetId);
    }
};
```

---

## 📊 Reports & Analytics

### Asset Value Report
```sql
SELECT 
    asset_type,
    COUNT(*) as total_assets,
    SUM(purchase_cost) as total_purchase_cost,
    SUM(current_value) as total_current_value,
    SUM(purchase_cost - current_value) as total_depreciation
FROM assets
WHERE status != 'disposed'
GROUP BY asset_type;
```

### Maintenance Cost Analysis
```sql
SELECT 
    a.asset_name,
    a.asset_type,
    COUNT(m.id) as maintenance_count,
    SUM(m.cost) as total_maintenance_cost,
    AVG(m.downtime_hours) as avg_downtime
FROM assets a
LEFT JOIN asset_maintenance m ON a.id = m.asset_id
WHERE m.completed_date >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
GROUP BY a.id
ORDER BY total_maintenance_cost DESC;
```

### Asset Utilization
```sql
SELECT 
    a.asset_name,
    a.status,
    DATEDIFF(CURDATE(), a.purchase_date) as age_days,
    (SELECT COUNT(*) FROM asset_maintenance WHERE asset_id = a.id) as maintenance_count,
    (SELECT SUM(downtime_hours) FROM asset_maintenance WHERE asset_id = a.id) as total_downtime
FROM assets a
WHERE a.asset_type IN ('vehicle', 'equipment')
ORDER BY total_downtime DESC;
```

---

## ✅ Implementation Checklist

- [ ] Create database tables (assets, asset_maintenance, asset_depreciation, asset_transfers, asset_inspections)
- [ ] Implement API endpoints (alms_assets.php, alms_maintenance.php, alms_depreciation.php)
- [ ] Create frontend (alms.php, alms.js)
- [ ] Integrate with SWS (warehouse equipment tracking)
- [ ] Integrate with DTRS (vehicle management)
- [ ] Implement QR code generation for assets
- [ ] Add barcode scanning functionality
- [ ] Create depreciation calculation cron job
- [ ] Implement maintenance reminder system
- [ ] Create financial reports
- [ ] Add asset photo upload
- [ ] Test asset transfer workflow

**ALMS Module Complete!** 🏭

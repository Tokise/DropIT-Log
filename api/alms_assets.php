<?php
require_once 'common.php';

header('Content-Type: application/json');

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    $auth = require_auth();
    
    switch ($method) {
        case 'GET':
            if (isset($_GET['id'])) {
                getAssetDetails($_GET['id']);
            } elseif ($action === 'scan') {
                scanAsset($_GET['code'] ?? '');
            } else {
                getAssets();
            }
            break;
            
        case 'POST':
            createAsset();
            break;
            
        case 'PUT':
            if ($action === 'update_status') {
                updateAssetStatus();
            } elseif ($action === 'transfer') {
                transferAsset();
            } else {
                updateAsset();
            }
            break;
            
        case 'DELETE':
            deleteAsset($_GET['id'] ?? 0);
            break;
            
        default:
            json_err('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    json_err('Server error: ' . $e->getMessage(), 500);
}

function getAssets() {
    $conn = db_conn();
    
    $page = (int)($_GET['page'] ?? 1);
    $limit = (int)($_GET['limit'] ?? 20);
    $offset = ($page - 1) * $limit;
    
    $asset_type = $_GET['asset_type'] ?? '';
    $status = $_GET['status'] ?? '';
    $warehouse_id = $_GET['warehouse_id'] ?? '';
    $search = $_GET['search'] ?? '';
    
    $where = "WHERE 1=1";
    $params = [];
    
    if ($asset_type) {
        $where .= " AND a.asset_type = :asset_type";
        $params[':asset_type'] = $asset_type;
    }
    
    if ($status) {
        $where .= " AND a.status = :status";
        $params[':status'] = $status;
    }
    
    if ($warehouse_id) {
        $where .= " AND a.warehouse_id = :warehouse_id";
        $params[':warehouse_id'] = $warehouse_id;
    }
    
    if ($search) {
        $where .= " AND (a.asset_code LIKE :search OR a.asset_name LIKE :search OR a.serial_number LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    $sql = "
        SELECT 
            a.*,
            w.name as warehouse_name,
            u.full_name as assigned_to_name,
            DATEDIFF(a.next_maintenance_date, CURDATE()) as days_to_maintenance
        FROM assets a
        LEFT JOIN warehouses w ON a.warehouse_id = w.id
        LEFT JOIN users u ON a.assigned_to = u.id
        $where
        ORDER BY a.created_at DESC
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count
    $countSql = "SELECT COUNT(*) FROM assets a $where";
    $countStmt = $conn->prepare($countSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = $countStmt->fetchColumn();
    
    json_ok([
        'assets' => $assets,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => (int)$total,
            'pages' => ceil($total / $limit)
        ]
    ]);
}

function getAssetDetails($id) {
    $conn = db_conn();
    
    $stmt = $conn->prepare("
        SELECT 
            a.*,
            w.name as warehouse_name,
            u.full_name as assigned_to_name
        FROM assets a
        LEFT JOIN warehouses w ON a.warehouse_id = w.id
        LEFT JOIN users u ON a.assigned_to = u.id
        WHERE a.id = :id
    ");
    $stmt->execute([':id' => $id]);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$asset) {
        json_err('Asset not found', 404);
    }
    
    // Get maintenance history
    $stmt = $conn->prepare("
        SELECT am.*, u.full_name as performed_by_name
        FROM asset_maintenance am
        LEFT JOIN users u ON am.performed_by = u.id
        WHERE am.asset_id = :id
        ORDER BY am.scheduled_date DESC
    ");
    $stmt->execute([':id' => $id]);
    $asset['maintenance_history'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get depreciation schedule
    $stmt = $conn->prepare("
        SELECT *
        FROM asset_depreciation
        WHERE asset_id = :id
        ORDER BY year DESC, month DESC
        LIMIT 12
    ");
    $stmt->execute([':id' => $id]);
    $asset['depreciation_schedule'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get transfer history
    $stmt = $conn->prepare("
        SELECT at.*, u.full_name as created_by_name
        FROM asset_transfers at
        LEFT JOIN users u ON at.created_by = u.id
        WHERE at.asset_id = :id
        ORDER BY at.created_at DESC
    ");
    $stmt->execute([':id' => $id]);
    $asset['transfer_history'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    json_ok($asset);
}

function scanAsset($code) {
    $conn = db_conn();
    
    if (!$code) {
        json_err('Asset code required', 400);
    }
    
    $stmt = $conn->prepare("
        SELECT 
            a.*,
            w.name as warehouse_name,
            u.full_name as assigned_to_name
        FROM assets a
        LEFT JOIN warehouses w ON a.warehouse_id = w.id
        LEFT JOIN users u ON a.assigned_to = u.id
        WHERE a.asset_code = :code OR a.barcode = :code OR a.qr_code = :code
    ");
    $stmt->execute([':code' => $code]);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$asset) {
        json_err('Asset not found', 404);
    }
    
    json_ok($asset);
}

function createAsset() {
    $conn = db_conn();
    $auth = require_auth();
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        json_err('Invalid JSON data', 400);
    }
    
    // Validate required fields
    $required = ['asset_name', 'asset_type'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            json_err("Field '$field' is required", 400);
        }
    }
    
    // Generate asset code if not provided
    if (!isset($data['asset_code']) || empty($data['asset_code'])) {
        $prefix = strtoupper(substr($data['asset_type'], 0, 2));
        $year = date('Y');
        $sequence = str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
        $data['asset_code'] = "$prefix-$year-$sequence";
    }
    
    // Generate QR code
    $qrCode = "ASSET-{$data['asset_code']}-" . time();
    
    $stmt = $conn->prepare("
        INSERT INTO assets (
            asset_code, asset_name, asset_type, category, description,
            manufacturer, model, serial_number, barcode, qr_code,
            purchase_date, purchase_cost, current_value, depreciation_method,
            useful_life_years, salvage_value, location, warehouse_id,
            assigned_to, status, condition_rating, warranty_expiry,
            insurance_policy, insurance_expiry, maintenance_interval_days,
            notes, photo_url, created_by
        ) VALUES (
            :asset_code, :asset_name, :asset_type, :category, :description,
            :manufacturer, :model, :serial_number, :barcode, :qr_code,
            :purchase_date, :purchase_cost, :current_value, :depreciation_method,
            :useful_life_years, :salvage_value, :location, :warehouse_id,
            :assigned_to, :status, :condition_rating, :warranty_expiry,
            :insurance_policy, :insurance_expiry, :maintenance_interval_days,
            :notes, :photo_url, :created_by
        )
    ");
    
    // Calculate current value (same as purchase cost initially)
    $currentValue = $data['purchase_cost'] ?? $data['current_value'] ?? 0;
    
    // Calculate next maintenance date
    $maintenanceInterval = $data['maintenance_interval_days'] ?? 90;
    $nextMaintenance = date('Y-m-d', strtotime("+$maintenanceInterval days"));
    
    $stmt->execute([
        ':asset_code' => $data['asset_code'],
        ':asset_name' => $data['asset_name'],
        ':asset_type' => $data['asset_type'],
        ':category' => $data['category'] ?? null,
        ':description' => $data['description'] ?? null,
        ':manufacturer' => $data['manufacturer'] ?? null,
        ':model' => $data['model'] ?? null,
        ':serial_number' => $data['serial_number'] ?? null,
        ':barcode' => $data['barcode'] ?? null,
        ':qr_code' => $qrCode,
        ':purchase_date' => $data['purchase_date'] ?? null,
        ':purchase_cost' => $data['purchase_cost'] ?? 0,
        ':current_value' => $currentValue,
        ':depreciation_method' => $data['depreciation_method'] ?? 'straight_line',
        ':useful_life_years' => $data['useful_life_years'] ?? 5,
        ':salvage_value' => $data['salvage_value'] ?? 0,
        ':location' => $data['location'] ?? null,
        ':warehouse_id' => $data['warehouse_id'] ?? null,
        ':assigned_to' => $data['assigned_to'] ?? null,
        ':status' => $data['status'] ?? 'active',
        ':condition_rating' => $data['condition_rating'] ?? 'good',
        ':warranty_expiry' => $data['warranty_expiry'] ?? null,
        ':insurance_policy' => $data['insurance_policy'] ?? null,
        ':insurance_expiry' => $data['insurance_expiry'] ?? null,
        ':maintenance_interval_days' => $maintenanceInterval,
        ':notes' => $data['notes'] ?? null,
        ':photo_url' => $data['photo_url'] ?? null,
        ':created_by' => $auth['id']
    ]);
    
    $assetId = $conn->lastInsertId();
    
    // Update next maintenance date
    $stmt = $conn->prepare("
        UPDATE assets 
        SET next_maintenance_date = :next_maintenance
        WHERE id = :id
    ");
    $stmt->execute([
        ':next_maintenance' => $nextMaintenance,
        ':id' => $assetId
    ]);
    
    json_ok([
        'asset_id' => $assetId,
        'asset_code' => $data['asset_code'],
        'qr_code' => $qrCode,
        'next_maintenance_date' => $nextMaintenance
    ]);
}

function updateAssetStatus() {
    $conn = db_conn();
    $auth = require_auth();
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['asset_id']) || !isset($data['status'])) {
        json_err('Asset ID and status required', 400);
    }
    
    $stmt = $conn->prepare("
        UPDATE assets 
        SET status = :status,
            condition_rating = :condition_rating,
            notes = :notes
        WHERE id = :id
    ");
    
    $stmt->execute([
        ':status' => $data['status'],
        ':condition_rating' => $data['condition_rating'] ?? null,
        ':notes' => $data['notes'] ?? null,
        ':id' => $data['asset_id']
    ]);
    
    json_ok(['message' => 'Asset status updated successfully']);
}

function transferAsset() {
    $conn = db_conn();
    $auth = require_auth();
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['asset_id'])) {
        json_err('Asset ID required', 400);
    }
    
    $conn->beginTransaction();
    
    try {
        // Create transfer record
        $stmt = $conn->prepare("
            INSERT INTO asset_transfers (
                asset_id, from_location, to_location, from_warehouse_id,
                to_warehouse_id, from_user_id, to_user_id, transfer_date,
                reason, status, notes, created_by
            ) VALUES (
                :asset_id, :from_location, :to_location, :from_warehouse_id,
                :to_warehouse_id, :from_user_id, :to_user_id, :transfer_date,
                :reason, :status, :notes, :created_by
            )
        ");
        
        $stmt->execute([
            ':asset_id' => $data['asset_id'],
            ':from_location' => $data['from_location'] ?? null,
            ':to_location' => $data['to_location'] ?? null,
            ':from_warehouse_id' => $data['from_warehouse_id'] ?? null,
            ':to_warehouse_id' => $data['to_warehouse_id'] ?? null,
            ':from_user_id' => $data['from_user_id'] ?? null,
            ':to_user_id' => $data['to_user_id'] ?? null,
            ':transfer_date' => $data['transfer_date'] ?? date('Y-m-d'),
            ':reason' => $data['reason'] ?? null,
            ':status' => 'pending',
            ':notes' => $data['notes'] ?? null,
            ':created_by' => $auth['id']
        ]);
        
        $transferId = $conn->lastInsertId();
        
        // If auto-approved, update asset location
        if (isset($data['auto_approve']) && $data['auto_approve']) {
            $stmt = $conn->prepare("
                UPDATE asset_transfers 
                SET status = 'approved', approved_by = :approved_by, approved_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':approved_by' => $auth['id'],
                ':id' => $transferId
            ]);
            
            // Update asset location
            $updates = [];
            $params = [':id' => $data['asset_id']];
            
            if (isset($data['to_location'])) {
                $updates[] = "location = :location";
                $params[':location'] = $data['to_location'];
            }
            
            if (isset($data['to_warehouse_id'])) {
                $updates[] = "warehouse_id = :warehouse_id";
                $params[':warehouse_id'] = $data['to_warehouse_id'];
            }
            
            if (isset($data['to_user_id'])) {
                $updates[] = "assigned_to = :assigned_to";
                $params[':assigned_to'] = $data['to_user_id'];
            }
            
            if (!empty($updates)) {
                $sql = "UPDATE assets SET " . implode(', ', $updates) . " WHERE id = :id";
                $stmt = $conn->prepare($sql);
                $stmt->execute($params);
            }
        }
        
        $conn->commit();
        
        json_ok([
            'transfer_id' => $transferId,
            'status' => isset($data['auto_approve']) && $data['auto_approve'] ? 'approved' : 'pending'
        ]);
        
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}

function updateAsset() {
    $conn = db_conn();
    $auth = require_auth();
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['id'])) {
        json_err('Asset ID required', 400);
    }
    
    $updates = [];
    $params = [':id' => $data['id']];
    
    $allowedFields = [
        'asset_name', 'category', 'description', 'manufacturer', 'model',
        'serial_number', 'location', 'warehouse_id', 'assigned_to',
        'condition_rating', 'warranty_expiry', 'insurance_policy',
        'insurance_expiry', 'maintenance_interval_days', 'notes', 'photo_url'
    ];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = :$field";
            $params[":$field"] = $data[$field];
        }
    }
    
    if (empty($updates)) {
        json_err('No valid fields to update', 400);
    }
    
    $sql = "UPDATE assets SET " . implode(', ', $updates) . " WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    
    json_ok(['message' => 'Asset updated successfully']);
}

function deleteAsset($id) {
    $conn = db_conn();
    $auth = require_auth();
    
    if (!$id) {
        json_err('Asset ID required', 400);
    }
    
    // Check if asset can be deleted (only inactive assets)
    $stmt = $conn->prepare("SELECT status FROM assets WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $status = $stmt->fetchColumn();
    
    if (!$status) {
        json_err('Asset not found', 404);
    }
    
    if ($status === 'active') {
        json_err('Active assets cannot be deleted. Please retire the asset first.', 400);
    }
    
    // Soft delete by changing status to disposed
    $stmt = $conn->prepare("
        UPDATE assets 
        SET status = 'disposed', 
            notes = CONCAT(COALESCE(notes, ''), ' [DELETED: ', NOW(), ']')
        WHERE id = :id
    ");
    $stmt->execute([':id' => $id]);
    
    json_ok(['message' => 'Asset marked as disposed']);
}
?>

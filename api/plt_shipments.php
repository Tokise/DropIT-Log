<?php
require_once 'common.php';
require_once 'warehouse_init.php';

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    $auth = require_auth();
    
    switch ($method) {
        case 'GET':
            if (isset($_GET['id'])) {
                getShipmentDetails($_GET['id']);
            } elseif ($action === 'track') {
                trackShipment($_GET['tracking_number'] ?? '');
            } else {
                getShipments();
            }
            break;
            
        case 'POST':
            if ($action === 'create') {
                createShipment();
            } elseif ($action === 'add_event') {
                addTrackingEvent();
            } else {
                createShipment();
            }
            break;
            
        case 'PUT':
            if ($action === 'update_status') {
                updateShipmentStatus();
            } elseif ($action === 'assign_driver') {
                assignDriver();
            } elseif ($action === 'fix_delivered_inventory') {
                fixDeliveredInventory();
            } else {
                updateShipment();
            }
            break;
            
        case 'DELETE':
            deleteShipment($_GET['id'] ?? 0);
            break;
            
        default:
            json_err('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    json_err('Server error: ' . $e->getMessage(), 500);
}

function getShipments() {
    $conn = db_conn();
    
    $page = (int)($_GET['page'] ?? 1);
    $limit = (int)($_GET['limit'] ?? 20);
    $offset = ($page - 1) * $limit;
    
    $status = $_GET['status'] ?? '';
    $search = $_GET['search'] ?? '';
    
    $where = "WHERE 1=1";
    $params = [];
    
    if ($status) {
        $where .= " AND s.status = :status";
        $params[':status'] = $status;
    }
    
    if ($search) {
        $where .= " AND (s.tracking_number LIKE :search OR s.customer_name LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    $sql = "
        SELECT 
            s.*,
            w.name as warehouse_name,
            u.full_name as driver_name,
            COUNT(si.id) as item_count
        FROM shipments s
        LEFT JOIN warehouses w ON s.warehouse_id = w.id
        LEFT JOIN users u ON s.driver_id = u.id
        LEFT JOIN shipment_items si ON s.id = si.shipment_id
        $where
        GROUP BY s.id
        ORDER BY s.created_at DESC
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $shipments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count
    $countSql = "SELECT COUNT(*) FROM shipments s $where";
    $countStmt = $conn->prepare($countSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = $countStmt->fetchColumn();
    
    json_ok([
        'shipments' => $shipments,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => (int)$total,
            'pages' => ceil($total / $limit)
        ]
    ]);
}

function getShipmentDetails($id) {
    $conn = db_conn();
    
    $stmt = $conn->prepare("
        SELECT 
            s.*,
            w.name as warehouse_name,
            u.full_name as driver_name
        FROM shipments s
        LEFT JOIN warehouses w ON s.warehouse_id = w.id
        LEFT JOIN users u ON s.driver_id = u.id
        WHERE s.id = :id
    ");
    $stmt->execute([':id' => $id]);
    $shipment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$shipment) {
        json_err('Shipment not found', 404);
    }
    
    // Get shipment items
    $stmt = $conn->prepare("
        SELECT si.*, p.name as product_name, p.sku
        FROM shipment_items si
        LEFT JOIN products p ON si.product_id = p.id
        WHERE si.shipment_id = :id
    ");
    $stmt->execute([':id' => $id]);
    $shipment['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get tracking events
    $stmt = $conn->prepare("
        SELECT te.*, u.full_name as performed_by_name
        FROM tracking_events te
        LEFT JOIN users u ON te.performed_by = u.id
        WHERE te.shipment_id = :id
        ORDER BY te.event_time DESC
    ");
    $stmt->execute([':id' => $id]);
    $shipment['tracking_events'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    json_ok($shipment);
}

function trackShipment($trackingNumber) {
    $conn = db_conn();
    
    if (!$trackingNumber) {
        json_err('Tracking number required', 400);
    }
    
    $stmt = $conn->prepare("
        SELECT 
            tracking_number,
            status,
            customer_name,
            estimated_delivery,
            actual_delivery,
            created_at
        FROM shipments
        WHERE tracking_number = :tn
    ");
    $stmt->execute([':tn' => $trackingNumber]);
    $shipment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$shipment) {
        json_err('Shipment not found', 404);
    }
    
    // Get tracking events (public info only)
    $stmt = $conn->prepare("
        SELECT 
            event_type,
            status,
            location,
            description,
            event_time
        FROM tracking_events
        WHERE shipment_id = (SELECT id FROM shipments WHERE tracking_number = :tn)
        ORDER BY event_time ASC
    ");
    $stmt->execute([':tn' => $trackingNumber]);
    $shipment['events'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    json_ok($shipment);
}

function createShipment() {
    $conn = db_conn();
    $auth = require_auth();
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        json_err('Invalid JSON data', 400);
    }
    
    // Validate required fields
    $required = ['customer_name', 'delivery_address', 'warehouse_id', 'items'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            json_err("Field '$field' is required", 400);
        }
    }
    
    $conn->beginTransaction();
    
    try {
        // Generate tracking number
        $trackingNumber = 'TRK' . date('Ymd') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Insert shipment
        $stmt = $conn->prepare("
            INSERT INTO shipments (
                tracking_number, warehouse_id, customer_name, customer_email, 
                customer_phone, delivery_address, city, state, postal_code, 
                country, priority, total_weight_kg, total_value, shipping_cost,
                estimated_delivery, notes, created_by
            ) VALUES (
                :tracking_number, :warehouse_id, :customer_name, :customer_email,
                :customer_phone, :delivery_address, :city, :state, :postal_code,
                :country, :priority, :total_weight_kg, :total_value, :shipping_cost,
                :estimated_delivery, :notes, :created_by
            )
        ");
        
        $estimatedDelivery = isset($data['estimated_delivery']) ? 
            $data['estimated_delivery'] : 
            date('Y-m-d H:i:s', strtotime('+3 days'));
        
        $stmt->execute([
            ':tracking_number' => $trackingNumber,
            ':warehouse_id' => $data['warehouse_id'],
            ':customer_name' => $data['customer_name'],
            ':customer_email' => $data['customer_email'] ?? null,
            ':customer_phone' => $data['customer_phone'] ?? null,
            ':delivery_address' => $data['delivery_address'],
            ':city' => $data['city'] ?? null,
            ':state' => $data['state'] ?? null,
            ':postal_code' => $data['postal_code'] ?? null,
            ':country' => $data['country'] ?? 'Philippines',
            ':priority' => $data['priority'] ?? 'standard',
            ':total_weight_kg' => $data['total_weight_kg'] ?? 0,
            ':total_value' => $data['total_value'] ?? 0,
            ':shipping_cost' => $data['shipping_cost'] ?? 0,
            ':estimated_delivery' => $estimatedDelivery,
            ':notes' => $data['notes'] ?? null,
            ':created_by' => $auth['id']
        ]);
        
        $shipmentId = $conn->lastInsertId();
        
        // Insert shipment items
        foreach ($data['items'] as $item) {
            $stmt = $conn->prepare("
                INSERT INTO shipment_items (
                    shipment_id, product_id, product_name, quantity, 
                    weight_kg, value, barcode
                ) VALUES (
                    :shipment_id, :product_id, :product_name, :quantity,
                    :weight_kg, :value, :barcode
                )
            ");
            
            $stmt->execute([
                ':shipment_id' => $shipmentId,
                ':product_id' => $item['product_id'],
                ':product_name' => $item['product_name'] ?? '',
                ':quantity' => $item['quantity'],
                ':weight_kg' => $item['weight_kg'] ?? 0,
                ':value' => $item['value'] ?? 0,
                ':barcode' => $item['barcode'] ?? null
            ]);
            
            // Reserve inventory
            $stmt = $conn->prepare("
                UPDATE inventory 
                SET reserved_quantity = reserved_quantity + :quantity
                WHERE product_id = :product_id AND warehouse_id = :warehouse_id
            ");
            $stmt->execute([
                ':quantity' => $item['quantity'],
                ':product_id' => $item['product_id'],
                ':warehouse_id' => $data['warehouse_id']
            ]);
        }
        
        // Add initial tracking event
        $stmt = $conn->prepare("
            INSERT INTO tracking_events (
                shipment_id, event_type, status, description, performed_by
            ) VALUES (
                :shipment_id, 'created', 'pending', 'Shipment created', :performed_by
            )
        ");
        $stmt->execute([
            ':shipment_id' => $shipmentId,
            ':performed_by' => $auth['id']
        ]);
        
        $conn->commit();
        
        json_ok([
            'shipment_id' => $shipmentId,
            'tracking_number' => $trackingNumber,
            'estimated_delivery' => $estimatedDelivery
        ]);
        
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}

function updateShipmentStatus() {
    $conn = db_conn();
    $auth = require_auth();
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['shipment_id']) || !isset($data['status'])) {
        json_err('Shipment ID and status required', 400);
    }
    
    $conn->beginTransaction();
    
    try {
        // Get current shipment info
        $stmt = $conn->prepare("SELECT status, warehouse_id FROM shipments WHERE id = :id");
        $stmt->execute([':id' => $data['shipment_id']]);
        $shipment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$shipment) {
            json_err('Shipment not found', 404);
        }
        
        $oldStatus = $shipment['status'];
        $newStatus = $data['status'];
        $warehouseId = $shipment['warehouse_id'];
        
        // Update shipment status
        $stmt = $conn->prepare("
            UPDATE shipments 
            SET status = :status,
                picked_at = CASE WHEN :status = 'picked' THEN NOW() ELSE picked_at END,
                packed_at = CASE WHEN :status = 'packed' THEN NOW() ELSE packed_at END,
                shipped_at = CASE WHEN :status = 'in_transit' THEN NOW() ELSE shipped_at END,
                delivered_at = CASE WHEN :status = 'delivered' THEN NOW() ELSE delivered_at END
            WHERE id = :id
        ");
        $stmt->execute([
            ':status' => $newStatus,
            ':id' => $data['shipment_id']
        ]);
        
        // Handle inventory changes based on status transitions
        if ($newStatus === 'delivered' && $oldStatus !== 'delivered') {
            // When delivered: reduce actual inventory and reserved quantity
            $stmt = $conn->prepare("
                UPDATE inventory i
                JOIN shipment_items si ON i.product_id = si.product_id
                SET i.quantity = GREATEST(0, i.quantity - si.quantity),
                    i.reserved_quantity = GREATEST(0, i.reserved_quantity - si.quantity)
                WHERE si.shipment_id = :shipment_id AND i.warehouse_id = :warehouse_id
            ");
            $stmt->execute([
                ':shipment_id' => $data['shipment_id'],
                ':warehouse_id' => $warehouseId
            ]);
            
            // Also update inventory_locations
            $stmt = $conn->prepare("
                UPDATE inventory_locations il
                JOIN shipment_items si ON il.product_id = si.product_id
                SET il.quantity = GREATEST(0, il.quantity - si.quantity)
                WHERE si.shipment_id = :shipment_id AND il.warehouse_id = :warehouse_id
            ");
            $stmt->execute([
                ':shipment_id' => $data['shipment_id'],
                ':warehouse_id' => $warehouseId
            ]);
            
            // Log inventory transactions for delivered items
            $stmt = $conn->prepare("
                INSERT INTO inventory_transactions 
                (product_id, warehouse_id, transaction_type, quantity, reference_type, reference_id, notes, performed_by)
                SELECT si.product_id, :warehouse_id, 'shipment', -si.quantity, 'shipment', :shipment_id, 
                       CONCAT('Inventory reduction for delivered shipment ', :shipment_id), :performed_by
                FROM shipment_items si 
                WHERE si.shipment_id = :shipment_id
            ");
            $stmt->execute([
                ':warehouse_id' => $warehouseId,
                ':shipment_id' => $data['shipment_id'],
                ':performed_by' => $auth['id']
            ]);
        } elseif ($newStatus === 'cancelled' || $newStatus === 'returned') {
            // When cancelled/returned: release reserved inventory
            $stmt = $conn->prepare("
                UPDATE inventory i
                JOIN shipment_items si ON i.product_id = si.product_id
                SET i.reserved_quantity = i.reserved_quantity - si.quantity
                WHERE si.shipment_id = :shipment_id AND i.warehouse_id = :warehouse_id
            ");
            $stmt->execute([
                ':shipment_id' => $data['shipment_id'],
                ':warehouse_id' => $warehouseId
            ]);
        } elseif ($newStatus === 'failed' && $oldStatus !== 'failed') {
            // When failed: release reserved inventory
            $stmt = $conn->prepare("
                UPDATE inventory i
                JOIN shipment_items si ON i.product_id = si.product_id
                SET i.reserved_quantity = i.reserved_quantity - si.quantity
                WHERE si.shipment_id = :shipment_id AND i.warehouse_id = :warehouse_id
            ");
            $stmt->execute([
                ':shipment_id' => $data['shipment_id'],
                ':warehouse_id' => $warehouseId
            ]);
        }
        
        // Add tracking event
        $stmt = $conn->prepare("
            INSERT INTO tracking_events (
                shipment_id, event_type, status, location, description, performed_by
            ) VALUES (
                :shipment_id, 'status_update', :status, :location, :description, :performed_by
            )
        ");
        $stmt->execute([
            ':shipment_id' => $data['shipment_id'],
            ':status' => $newStatus,
            ':location' => $data['location'] ?? null,
            ':description' => $data['notes'] ?? "Status updated to {$newStatus}",
            ':performed_by' => $auth['id']
        ]);
        
        // Sync inventory with locations after any status change
        syncInventoryWithLocations($conn, null, $warehouseId);
        
        $conn->commit();
        json_ok(['message' => 'Status updated successfully']);
        
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}

function assignDriver() {
    $conn = db_conn();
    $auth = require_auth();
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['shipment_id']) || !isset($data['driver_id'])) {
        json_err('Shipment ID and driver ID required', 400);
    }
    
    $stmt = $conn->prepare("
        UPDATE shipments 
        SET driver_id = :driver_id, vehicle_id = :vehicle_id
        WHERE id = :id
    ");
    $stmt->execute([
        ':driver_id' => $data['driver_id'],
        ':vehicle_id' => $data['vehicle_id'] ?? null,
        ':id' => $data['shipment_id']
    ]);
    
    // Add tracking event
    $stmt = $conn->prepare("
        INSERT INTO tracking_events (
            shipment_id, event_type, status, description, performed_by
        ) VALUES (
            :shipment_id, 'driver_assigned', 'assigned', 'Driver assigned to shipment', :performed_by
        )
    ");
    $stmt->execute([
        ':shipment_id' => $data['shipment_id'],
        ':performed_by' => $auth['id']
    ]);
    
    json_ok(['message' => 'Driver assigned successfully']);
}

function addTrackingEvent() {
    $conn = db_conn();
    $auth = require_auth();
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['shipment_id']) || !isset($data['event_type'])) {
        json_err('Shipment ID and event type required', 400);
    }
    
    $stmt = $conn->prepare("
        INSERT INTO tracking_events (
            shipment_id, event_type, status, location, latitude, longitude,
            description, performed_by, event_time
        ) VALUES (
            :shipment_id, :event_type, :status, :location, :latitude, :longitude,
            :description, :performed_by, :event_time
        )
    ");
    
    $eventTime = $data['event_time'] ?? date('Y-m-d H:i:s');
    
    $stmt->execute([
        ':shipment_id' => $data['shipment_id'],
        ':event_type' => $data['event_type'],
        ':status' => $data['status'] ?? 'in_progress',
        ':location' => $data['location'] ?? null,
        ':latitude' => $data['latitude'] ?? null,
        ':longitude' => $data['longitude'] ?? null,
        ':description' => $data['description'] ?? null,
        ':performed_by' => $auth['id'],
        ':event_time' => $eventTime
    ]);
    
    json_ok(['event_id' => $conn->lastInsertId()]);
}

function updateShipment() {
    $conn = db_conn();
    $auth = require_auth();
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['id'])) {
        json_err('Shipment ID required', 400);
    }
    
    $updates = [];
    $params = [':id' => $data['id']];
    
    $allowedFields = [
        'customer_name', 'customer_email', 'customer_phone', 
        'delivery_address', 'city', 'state', 'postal_code', 
        'priority', 'estimated_delivery', 'notes'
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
    
    $sql = "UPDATE shipments SET " . implode(', ', $updates) . " WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    
    json_ok(['message' => 'Shipment updated successfully']);
}

function deleteShipment($id) {
    $conn = db_conn();
    $auth = require_auth();
    
    if (!$id) {
        json_err('Shipment ID required', 400);
    }
    
    // Check if shipment can be deleted (only pending shipments)
    $stmt = $conn->prepare("SELECT status FROM shipments WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $status = $stmt->fetchColumn();
    
    if (!$status) {
        json_err('Shipment not found', 404);
    }
    
    if ($status !== 'pending') {
        json_err('Only pending shipments can be deleted', 400);
    }
    
    $conn->beginTransaction();
    
    try {
        // Release reserved inventory
        $stmt = $conn->prepare("
            UPDATE inventory i
            JOIN shipment_items si ON i.product_id = si.product_id
            JOIN shipments s ON si.shipment_id = s.id
            SET i.reserved_quantity = i.reserved_quantity - si.quantity
            WHERE s.id = :id AND i.warehouse_id = s.warehouse_id
        ");
        $stmt->execute([':id' => $id]);
        
        // Delete shipment (cascade will handle items and events)
        $stmt = $conn->prepare("DELETE FROM shipments WHERE id = :id");
        $stmt->execute([':id' => $id]);
        
        $conn->commit();
        json_ok(['message' => 'Shipment deleted successfully']);
        
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}

function fixDeliveredInventory() {
    $conn = db_conn();
    
    try {
        // Fix all delivered shipments that haven't had their inventory properly reduced
        $stmt = $conn->prepare("
            SELECT DISTINCT s.id, s.warehouse_id
            FROM shipments s
            JOIN shipment_items si ON s.id = si.shipment_id
            WHERE s.status = 'delivered'
        ");
        $stmt->execute();
        $deliveredShipments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $fixed = 0;
        
        foreach ($deliveredShipments as $shipment) {
            // Check if inventory was already reduced for this shipment
            $stmt = $conn->prepare("
                SELECT COUNT(*) FROM inventory_transactions 
                WHERE reference_type = 'shipment' 
                AND reference_id = :shipment_id 
                AND transaction_type = 'shipment'
            ");
            $stmt->execute([':shipment_id' => $shipment['id']]);
            $alreadyProcessed = $stmt->fetchColumn();
            
            if (!$alreadyProcessed) {
                // Reduce inventory for this delivered shipment
                $stmt = $conn->prepare("
                    UPDATE inventory i
                    JOIN shipment_items si ON i.product_id = si.product_id
                    SET i.quantity = GREATEST(0, i.quantity - si.quantity),
                        i.reserved_quantity = GREATEST(0, i.reserved_quantity - si.quantity)
                    WHERE si.shipment_id = :shipment_id AND i.warehouse_id = :warehouse_id
                ");
                $stmt->execute([
                    ':shipment_id' => $shipment['id'],
                    ':warehouse_id' => $shipment['warehouse_id']
                ]);
                
                // Update inventory_locations
                $stmt = $conn->prepare("
                    UPDATE inventory_locations il
                    JOIN shipment_items si ON il.product_id = si.product_id
                    SET il.quantity = GREATEST(0, il.quantity - si.quantity)
                    WHERE si.shipment_id = :shipment_id AND il.warehouse_id = :warehouse_id
                ");
                $stmt->execute([
                    ':shipment_id' => $shipment['id'],
                    ':warehouse_id' => $shipment['warehouse_id']
                ]);
                
                // Log this transaction to prevent duplicate processing
                // Get the user who created the shipment
                $stmt = $conn->prepare("SELECT created_by FROM shipments WHERE id = :shipment_id");
                $stmt->execute([':shipment_id' => $shipment['id']]);
                $createdBy = $stmt->fetchColumn() ?: 1; // fallback to admin if not found
                
                $stmt = $conn->prepare("
                    INSERT INTO inventory_transactions 
                    (product_id, warehouse_id, transaction_type, quantity, reference_type, reference_id, notes, performed_by)
                    SELECT si.product_id, :warehouse_id, 'shipment', -si.quantity, 'shipment', :shipment_id, 
                           CONCAT('Retroactive inventory reduction for delivered shipment ', :shipment_id), :performed_by
                    FROM shipment_items si 
                    WHERE si.shipment_id = :shipment_id
                ");
                $stmt->execute([
                    ':warehouse_id' => $shipment['warehouse_id'],
                    ':shipment_id' => $shipment['id'],
                    ':performed_by' => $createdBy
                ]);
                
                $fixed++;
            }
        }
        
        // Sync inventory with locations
        syncInventoryWithLocations($conn);
        
        json_ok([
            'message' => "Fixed inventory for {$fixed} delivered shipments",
            'fixed_count' => $fixed
        ]);
        
    } catch (Exception $e) {
        throw $e;
    }
}
?>

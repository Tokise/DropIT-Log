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
            if (isset($_GET['shipment_id'])) {
                getDeliveryProof($_GET['shipment_id']);
            } else {
                getDeliveries();
            }
            break;
            
        case 'POST':
            if ($action === 'confirm') {
                confirmDelivery();
            } else {
                json_err('Invalid action', 400);
            }
            break;
            
        default:
            json_err('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    json_err('Server error: ' . $e->getMessage(), 500);
}

function getDeliveries() {
    global $conn;
    
    $page = (int)($_GET['page'] ?? 1);
    $limit = (int)($_GET['limit'] ?? 20);
    $offset = ($page - 1) * $limit;
    
    $status = $_GET['status'] ?? '';
    $driver_id = $_GET['driver_id'] ?? '';
    
    $where = "WHERE s.status IN ('out_for_delivery', 'delivered')";
    $params = [];
    
    if ($status) {
        $where .= " AND s.status = :status";
        $params[':status'] = $status;
    }
    
    if ($driver_id) {
        $where .= " AND s.driver_id = :driver_id";
        $params[':driver_id'] = $driver_id;
    }
    
    $sql = "
        SELECT 
            s.*,
            w.name as warehouse_name,
            u.full_name as driver_name,
            dp.id as has_proof
        FROM shipments s
        LEFT JOIN warehouses w ON s.warehouse_id = w.id
        LEFT JOIN users u ON s.driver_id = u.id
        LEFT JOIN delivery_proof dp ON s.id = dp.shipment_id
        $where
        ORDER BY s.estimated_delivery ASC
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count
    $countSql = "SELECT COUNT(*) FROM shipments s $where";
    $countStmt = $conn->prepare($countSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = $countStmt->fetchColumn();
    
    json_ok([
        'deliveries' => $deliveries,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => (int)$total,
            'pages' => ceil($total / $limit)
        ]
    ]);
}

function getDeliveryProof($shipmentId) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT 
            dp.*,
            s.tracking_number,
            s.customer_name,
            s.delivery_address
        FROM delivery_proof dp
        JOIN shipments s ON dp.shipment_id = s.id
        WHERE dp.shipment_id = :shipment_id
    ");
    $stmt->execute([':shipment_id' => $shipmentId]);
    $proof = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$proof) {
        json_err('Delivery proof not found', 404);
    }
    
    json_ok($proof);
}

function confirmDelivery() {
    global $conn, $auth;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        json_err('Invalid JSON data', 400);
    }
    
    // Validate required fields
    $required = ['shipment_id', 'recipient_name'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            json_err("Field '$field' is required", 400);
        }
    }
    
    $conn->beginTransaction();
    
    try {
        // Check if shipment exists and is ready for delivery
        $stmt = $conn->prepare("
            SELECT id, status, warehouse_id 
            FROM shipments 
            WHERE id = :id AND status IN ('in_transit', 'out_for_delivery')
        ");
        $stmt->execute([':id' => $data['shipment_id']]);
        $shipment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$shipment) {
            json_err('Shipment not found or not ready for delivery', 404);
        }
        
        // Update shipment status to delivered
        $stmt = $conn->prepare("
            UPDATE shipments 
            SET status = 'delivered', 
                delivered_at = NOW(),
                actual_delivery = NOW()
            WHERE id = :id
        ");
        $stmt->execute([':id' => $data['shipment_id']]);
        
        // Insert delivery proof
        $stmt = $conn->prepare("
            INSERT INTO delivery_proof (
                shipment_id, signature_image, photo_image, recipient_name,
                recipient_relationship, notes, latitude, longitude
            ) VALUES (
                :shipment_id, :signature_image, :photo_image, :recipient_name,
                :recipient_relationship, :notes, :latitude, :longitude
            )
        ");
        
        $stmt->execute([
            ':shipment_id' => $data['shipment_id'],
            ':signature_image' => $data['signature'] ?? null,
            ':photo_image' => $data['photo'] ?? null,
            ':recipient_name' => $data['recipient_name'],
            ':recipient_relationship' => $data['recipient_relationship'] ?? 'Self',
            ':notes' => $data['notes'] ?? null,
            ':latitude' => $data['latitude'] ?? null,
            ':longitude' => $data['longitude'] ?? null
        ]);
        
        // Add final tracking event
        $stmt = $conn->prepare("
            INSERT INTO tracking_events (
                shipment_id, event_type, status, location, latitude, longitude,
                description, performed_by
            ) VALUES (
                :shipment_id, 'delivered', 'delivered', :location, :latitude, :longitude,
                :description, :performed_by
            )
        ");
        
        $location = $data['delivery_location'] ?? 'Customer Location';
        $description = "Package delivered to {$data['recipient_name']}";
        
        $stmt->execute([
            ':shipment_id' => $data['shipment_id'],
            ':location' => $location,
            ':latitude' => $data['latitude'] ?? null,
            ':longitude' => $data['longitude'] ?? null,
            ':description' => $description,
            ':performed_by' => $auth['user_id']
        ]);
        
        // Reduce inventory (remove from reserved and available)
        $stmt = $conn->prepare("
            UPDATE inventory i
            JOIN shipment_items si ON i.product_id = si.product_id
            SET i.quantity = i.quantity - si.quantity,
                i.reserved_quantity = i.reserved_quantity - si.quantity
            WHERE si.shipment_id = :shipment_id AND i.warehouse_id = :warehouse_id
        ");
        $stmt->execute([
            ':shipment_id' => $data['shipment_id'],
            ':warehouse_id' => $shipment['warehouse_id']
        ]);
        
        // Log inventory transactions
        $stmt = $conn->prepare("
            INSERT INTO inventory_transactions (
                product_id, warehouse_id, transaction_type, quantity, 
                reference_type, reference_id, notes, performed_by
            )
            SELECT 
                si.product_id, :warehouse_id, 'shipment', -si.quantity,
                'shipment', :shipment_id, 'Delivered to customer', :performed_by
            FROM shipment_items si
            WHERE si.shipment_id = :shipment_id
        ");
        $stmt->execute([
            ':warehouse_id' => $shipment['warehouse_id'],
            ':shipment_id' => $data['shipment_id'],
            ':performed_by' => $auth['user_id']
        ]);
        
        $conn->commit();
        
        json_ok([
            'message' => 'Delivery confirmed successfully',
            'delivered_at' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}
?>

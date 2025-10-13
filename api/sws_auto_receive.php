<?php
/**
 * SWS Auto-Receive with AI Location Assignment
 * Automatically assigns received products to optimal locations
 */

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../services/AIWarehouseService.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();
$auth = require_auth();

if ($method !== 'POST') {
    json_err('POST required', 405);
}

try {
    $data = read_json_body();
    require_params($data, ['po_id', 'items']);
    
    $po_id = (int)$data['po_id'];
    $items = $data['items'];
    $use_ai = $data['use_ai'] ?? true;
    $warehouse_id = $data['warehouse_id'] ?? 1;
    
    $conn->beginTransaction();
    
    $results = [];
    $ai_service = new AIWarehouseService();
    
    foreach ($items as $item) {
        $product_id = (int)$item['product_id'];
        $quantity = (int)$item['quantity'];
        $batch_number = $item['batch_number'] ?? null;
        $expiry_date = $item['expiry_date'] ?? null;
        
        // 1. Update main inventory
        $stmt = $conn->prepare("
            INSERT INTO inventory (product_id, warehouse_id, quantity, updated_at)
            VALUES (:pid, :wid, :qty, NOW())
            ON DUPLICATE KEY UPDATE 
                quantity = quantity + :qty,
                updated_at = NOW()
        ");
        $stmt->execute([
            ':pid' => $product_id,
            ':wid' => $warehouse_id,
            ':qty' => $quantity
        ]);
        
        // 2. Assign to location (AI or manual)
        $location_id = null;
        
        if ($use_ai) {
            // Use AI to suggest best location
            try {
                $suggestion = $ai_service->suggestOptimalLocation($product_id, $quantity, $warehouse_id);
                $location_id = $suggestion['location_id'] ?? null;
                $ai_reason = $suggestion['reason'] ?? 'AI suggested location';
            } catch (Exception $e) {
                error_log("AI location suggestion failed: " . $e->getMessage());
                // Fall back to manual assignment
            }
        }
        
        // If AI didn't work or not enabled, use manual location
        if (!$location_id && isset($item['location_id'])) {
            $location_id = (int)$item['location_id'];
        }
        
        // If still no location, find first available
        if (!$location_id) {
            $stmt = $conn->prepare("
                SELECT id FROM warehouse_locations 
                WHERE zone_id IN (
                    SELECT id FROM warehouse_zones 
                    WHERE warehouse_id = :wid AND zone_type = 'storage' AND is_active = 1
                )
                AND is_active = 1
                AND (capacity_units - current_units) >= :qty
                ORDER BY current_units ASC
                LIMIT 1
            ");
            $stmt->execute([':wid' => $warehouse_id, ':qty' => $quantity]);
            $loc = $stmt->fetch(PDO::FETCH_ASSOC);
            $location_id = $loc['id'] ?? null;
        }
        
        if ($location_id) {
            // 3. Insert into inventory_locations
            $stmt = $conn->prepare("
                INSERT INTO inventory_locations 
                (product_id, location_id, quantity, batch_number, expiry_date)
                VALUES (:pid, :lid, :qty, :batch, :expiry)
                ON DUPLICATE KEY UPDATE
                    quantity = quantity + :qty
            ");
            $stmt->execute([
                ':pid' => $product_id,
                ':lid' => $location_id,
                ':qty' => $quantity,
                ':batch' => $batch_number,
                ':expiry' => $expiry_date
            ]);
            
            // 4. Update location capacity
            $stmt = $conn->prepare("
                UPDATE warehouse_locations 
                SET current_units = current_units + :qty
                WHERE id = :lid
            ");
            $stmt->execute([':qty' => $quantity, ':lid' => $location_id]);
            
            // 5. Log transaction
            $stmt = $conn->prepare("
                INSERT INTO inventory_transactions 
                (product_id, warehouse_id, transaction_type, quantity, reference_type, reference_id, performed_by, notes)
                VALUES (:pid, :wid, 'receipt', :qty, 'purchase_order', :po_id, :user_id, :notes)
            ");
            $stmt->execute([
                ':pid' => $product_id,
                ':wid' => $warehouse_id,
                ':qty' => $quantity,
                ':po_id' => $po_id,
                ':user_id' => $auth['id'],
                ':notes' => $use_ai ? "AI auto-assigned: $ai_reason" : "Manually assigned"
            ]);
            
            $results[] = [
                'product_id' => $product_id,
                'quantity' => $quantity,
                'location_id' => $location_id,
                'assigned_by' => $use_ai ? 'AI' : 'Manual',
                'reason' => $ai_reason ?? 'Manual assignment'
            ];
        } else {
            throw new Exception("No available location found for product $product_id");
        }
    }
    
    // 6. Update PO status to received
    $stmt = $conn->prepare("UPDATE purchase_orders SET status = 'received' WHERE id = :po_id");
    $stmt->execute([':po_id' => $po_id]);
    
    $conn->commit();
    
    // 7. Create notification
    notify_module_users('sws', 'Shipment Received', 
        "PO #$po_id has been received and products automatically assigned to locations",
        "sws.php?tab=inventory");
    
    log_audit('purchase_order', $po_id, 'receive_shipment', $auth['id'], 
        "Received " . count($items) . " items with " . ($use_ai ? 'AI' : 'manual') . " location assignment");
    
    json_ok([
        'message' => 'Shipment received and products assigned successfully',
        'assignments' => $results,
        'ai_used' => $use_ai
    ]);
    
} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    json_err($e->getMessage(), 500);
}

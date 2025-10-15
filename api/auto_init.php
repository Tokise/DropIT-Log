<?php
/**
 * Auto-initialization script
 * Runs automatically to ensure system is properly configured
 */

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/warehouse_init.php';

function autoInitializeSystem(PDO $conn) {
    try {
        // 1. Ensure warehouse structure exists
        $warehouseId = ensureWarehouseStructure($conn);
        
        // 2. Sync any existing inventory with locations
        syncInventoryWithLocations($conn);
        
        // 2.5. Ensure shipment data is properly counted
        // This will be handled by the API query, no additional setup needed
        
        // 3. Fix any products without proper reorder settings
        $stmt = $conn->prepare("
            UPDATE products 
            SET 
                reorder_point = CASE WHEN reorder_point IS NULL OR reorder_point <= 0 THEN 10 ELSE reorder_point END,
                reorder_quantity = CASE WHEN reorder_quantity IS NULL OR reorder_quantity <= 0 THEN 30 ELSE reorder_quantity END,
                lead_time_days = CASE WHEN lead_time_days IS NULL OR lead_time_days <= 0 THEN 7 ELSE lead_time_days END
            WHERE is_active = 1 AND (reorder_point IS NULL OR reorder_point <= 0 OR reorder_quantity IS NULL OR reorder_quantity <= 0)
        ");
        $stmt->execute();
        
        // 4. Fix delivered shipments that haven't reduced inventory
        $stmt = $conn->prepare("
            SELECT DISTINCT s.id, s.warehouse_id
            FROM shipments s
            JOIN shipment_items si ON s.id = si.shipment_id
            WHERE s.status = 'delivered'
        ");
        $stmt->execute();
        $deliveredShipments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
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
                           'Auto-fix: inventory reduction for delivered shipment', :performed_by
                    FROM shipment_items si 
                    WHERE si.shipment_id = :shipment_id
                ");
                $stmt->execute([
                    ':warehouse_id' => $shipment['warehouse_id'],
                    ':shipment_id' => $shipment['id'],
                    ':performed_by' => $createdBy
                ]);
            }
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Auto-initialization failed: " . $e->getMessage());
        return false;
    }
}

// Run auto-initialization if called directly
if (basename($_SERVER['PHP_SELF']) === 'auto_init.php') {
    $conn = db_conn();
    $success = autoInitializeSystem($conn);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $success ? 'System initialized successfully' : 'Initialization failed'
    ]);
}
?>

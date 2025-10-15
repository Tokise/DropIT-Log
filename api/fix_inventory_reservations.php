<?php
require_once 'common.php';

// This script fixes inventory reservation issues for existing shipments
// Run this once to correct any inconsistencies

try {
    $auth = require_auth();
    $conn = db_conn();
    
    echo "<h2>Inventory Reservation Fix Script</h2>\n";
    echo "<pre>\n";
    
    // Get all active shipments (not delivered, cancelled, failed, or returned)
    $stmt = $conn->prepare("
        SELECT s.id, s.tracking_number, s.status, s.warehouse_id
        FROM shipments s
        WHERE s.status NOT IN ('delivered', 'cancelled', 'failed', 'returned')
        ORDER BY s.created_at DESC
    ");
    $stmt->execute();
    $shipments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($shipments) . " active shipments to check...\n\n";
    
    $conn->beginTransaction();
    
    foreach ($shipments as $shipment) {
        echo "Checking shipment {$shipment['tracking_number']} (Status: {$shipment['status']})...\n";
        
        // Get shipment items
        $stmt = $conn->prepare("
            SELECT si.product_id, si.quantity, p.name as product_name, p.sku
            FROM shipment_items si
            LEFT JOIN products p ON si.product_id = p.id
            WHERE si.shipment_id = :shipment_id
        ");
        $stmt->execute([':shipment_id' => $shipment['id']]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($items as $item) {
            // Check current inventory reservation
            $stmt = $conn->prepare("
                SELECT quantity, reserved_quantity
                FROM inventory
                WHERE product_id = :product_id AND warehouse_id = :warehouse_id
            ");
            $stmt->execute([
                ':product_id' => $item['product_id'],
                ':warehouse_id' => $shipment['warehouse_id']
            ]);
            $inventory = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($inventory) {
                echo "  - {$item['product_name']} ({$item['sku']}): ";
                echo "Qty: {$inventory['quantity']}, Reserved: {$inventory['reserved_quantity']}, ";
                echo "Shipment Qty: {$item['quantity']}\n";
                
                // Check if we need to add reservation
                // This is a simple check - in production you might want more sophisticated logic
                if ($inventory['reserved_quantity'] < $item['quantity']) {
                    $needed = $item['quantity'] - $inventory['reserved_quantity'];
                    echo "    -> Adding {$needed} to reserved quantity\n";
                    
                    $stmt = $conn->prepare("
                        UPDATE inventory 
                        SET reserved_quantity = reserved_quantity + :quantity
                        WHERE product_id = :product_id AND warehouse_id = :warehouse_id
                    ");
                    $stmt->execute([
                        ':quantity' => $needed,
                        ':product_id' => $item['product_id'],
                        ':warehouse_id' => $shipment['warehouse_id']
                    ]);
                }
            } else {
                echo "  - WARNING: No inventory record found for {$item['product_name']} ({$item['sku']})\n";
            }
        }
        echo "\n";
    }
    
    // Check for delivered shipments that might still have reservations
    echo "Checking delivered shipments for unreleased reservations...\n\n";
    
    $stmt = $conn->prepare("
        SELECT s.id, s.tracking_number, s.status, s.warehouse_id
        FROM shipments s
        WHERE s.status IN ('delivered', 'cancelled', 'failed', 'returned')
        ORDER BY s.created_at DESC
        LIMIT 50
    ");
    $stmt->execute();
    $completedShipments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($completedShipments as $shipment) {
        // Get shipment items that might still have reservations
        $stmt = $conn->prepare("
            SELECT si.product_id, si.quantity, p.name as product_name, p.sku,
                   i.reserved_quantity
            FROM shipment_items si
            LEFT JOIN products p ON si.product_id = p.id
            LEFT JOIN inventory i ON si.product_id = i.product_id AND i.warehouse_id = :warehouse_id
            WHERE si.shipment_id = :shipment_id AND i.reserved_quantity > 0
        ");
        $stmt->execute([
            ':shipment_id' => $shipment['id'],
            ':warehouse_id' => $shipment['warehouse_id']
        ]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($items)) {
            echo "Shipment {$shipment['tracking_number']} ({$shipment['status']}) has unreleased reservations:\n";
            
            foreach ($items as $item) {
                echo "  - {$item['product_name']} ({$item['sku']}): {$item['reserved_quantity']} reserved\n";
                
                if ($shipment['status'] === 'delivered') {
                    // For delivered items, we should have reduced both quantity and reserved
                    echo "    -> This was delivered, reservation should have been cleared\n";
                } else {
                    // For cancelled/failed/returned, just release the reservation
                    echo "    -> Releasing reservation for {$shipment['status']} shipment\n";
                    
                    $stmt = $conn->prepare("
                        UPDATE inventory 
                        SET reserved_quantity = reserved_quantity - :quantity
                        WHERE product_id = :product_id AND warehouse_id = :warehouse_id
                    ");
                    $stmt->execute([
                        ':quantity' => min($item['quantity'], $item['reserved_quantity']),
                        ':product_id' => $item['product_id'],
                        ':warehouse_id' => $shipment['warehouse_id']
                    ]);
                }
            }
            echo "\n";
        }
    }
    
    $conn->commit();
    echo "Inventory reservation fix completed successfully!\n";
    echo "</pre>\n";
    
} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollBack();
    }
    echo "Error: " . $e->getMessage() . "\n";
}
?>

<?php
/**
 * Transfer Stock API
 */

require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();
$auth = require_auth();

try {
    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'bins';
        
        if ($action === 'bins') {
            // Get all available bins with their locations
            $stmt = $conn->prepare("
                SELECT 
                    wb.id,
                    CONCAT(wz.zone_code, '-', wa.aisle_code, '-', wr.rack_code, '-', wb.bin_code) as location_code,
                    wb.bin_name,
                    wb.capacity_units,
                    wb.current_units,
                    (wb.capacity_units - wb.current_units) as available_units
                FROM warehouse_bins wb
                JOIN warehouse_racks wr ON wb.rack_id = wr.id
                JOIN warehouse_aisles wa ON wr.aisle_id = wa.id
                JOIN warehouse_zones wz ON wa.zone_id = wz.id
                WHERE wb.is_active = 1
                ORDER BY wz.zone_code, wa.aisle_code, wr.rack_code, wb.bin_code
            ");
            $stmt->execute();
            $bins = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            json_ok(['bins' => $bins]);
        }
    }
    
    if ($method === 'POST') {
        $data = read_json_body();
        require_params($data, ['from_bin_id', 'to_bin_id', 'quantity']);
        
        $conn->beginTransaction();
        
        try {
            // Get product from source bin
            $stmt = $conn->prepare("
                SELECT product_id, quantity 
                FROM inventory_locations 
                WHERE bin_id = :from_bin_id 
                LIMIT 1
            ");
            $stmt->execute([':from_bin_id' => $data['from_bin_id']]);
            $sourceLocation = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$sourceLocation) {
                throw new Exception('No inventory found in source bin');
            }
            
            if ($sourceLocation['quantity'] < $data['quantity']) {
                throw new Exception('Insufficient quantity in source bin');
            }
            
            // Update source bin
            if ($sourceLocation['quantity'] == $data['quantity']) {
                // Remove completely
                $stmt = $conn->prepare("DELETE FROM inventory_locations WHERE bin_id = :bin_id");
                $stmt->execute([':bin_id' => $data['from_bin_id']]);
            } else {
                // Reduce quantity
                $stmt = $conn->prepare("
                    UPDATE inventory_locations 
                    SET quantity = quantity - :qty, updated_at = NOW()
                    WHERE bin_id = :bin_id
                ");
                $stmt->execute([
                    ':qty' => $data['quantity'],
                    ':bin_id' => $data['from_bin_id']
                ]);
            }
            
            // Update destination bin
            $stmt = $conn->prepare("
                INSERT INTO inventory_locations (product_id, bin_id, quantity, created_at, updated_at)
                VALUES (:product_id, :bin_id, :quantity, NOW(), NOW())
                ON DUPLICATE KEY UPDATE 
                    quantity = quantity + :quantity,
                    updated_at = NOW()
            ");
            $stmt->execute([
                ':product_id' => $sourceLocation['product_id'],
                ':bin_id' => $data['to_bin_id'],
                ':quantity' => $data['quantity']
            ]);
            
            // Update bin current_units
            $stmt = $conn->prepare("UPDATE warehouse_bins SET current_units = current_units - :qty WHERE id = :id");
            $stmt->execute([':qty' => $data['quantity'], ':id' => $data['from_bin_id']]);
            
            $stmt = $conn->prepare("UPDATE warehouse_bins SET current_units = current_units + :qty WHERE id = :id");
            $stmt->execute([':qty' => $data['quantity'], ':id' => $data['to_bin_id']]);
            
            $conn->commit();
            json_ok(['success' => true, 'message' => 'Stock transferred successfully']);
            
        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    }
    
} catch (Exception $e) {
    json_err($e->getMessage(), 500);
}
?>
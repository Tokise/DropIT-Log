<?php
/**
 * SWS Inventory API
 * Enhanced inventory management with location tracking and module integration
 */

require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();
$auth = require_auth();

try {
    if ($method === 'GET') {
        $warehouse_id = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : null;
        $product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : null;
        $bin_id = isset($_GET['bin_id']) ? (int)$_GET['bin_id'] : null;
        $low_stock = isset($_GET['low_stock']) ? (bool)$_GET['low_stock'] : false;
        
        $where = ['p.is_active = 1'];
        $params = [];
        
        if ($warehouse_id) {
            $where[] = 'i.warehouse_id = :warehouse_id';
            $params[':warehouse_id'] = $warehouse_id;
        }
        
        if ($product_id) {
            $where[] = 'i.product_id = :product_id';
            $params[':product_id'] = $product_id;
        }
        
        if ($low_stock) {
            $where[] = 'i.quantity <= p.reorder_point';
        }
        
        $whereClause = implode(' AND ', $where);
        
        // Get inventory with simplified location details
        $stmt = $conn->prepare("
            SELECT 
                i.id,
                i.product_id,
                i.warehouse_id,
                i.quantity,
                COALESCE(i.reserved_quantity, 0) as reserved_quantity,
                (i.quantity - COALESCE(i.reserved_quantity, 0)) as available_quantity,
                i.updated_at,
                p.name as product_name,
                p.sku,
                p.barcode,
                p.unit_price,
                p.reorder_point,
                p.weight_kg,
                p.dimensions_cm,
                c.name as category_name,
                w.name as warehouse_name,
                w.code as warehouse_code
            FROM inventory i
            JOIN products p ON i.product_id = p.id
            LEFT JOIN categories c ON p.category_id = c.id
            JOIN warehouses w ON i.warehouse_id = w.id
            WHERE $whereClause
            ORDER BY p.name
        ");
        
        $stmt->execute($params);
        $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Enrich with additional data
        foreach ($inventory as &$item) {
            // Get warehouse structure locations for this product
            try {
                $stmt = $conn->prepare("
                    SELECT 
                        il.quantity,
                        CONCAT(wz.zone_code, '-', wa.aisle_code, '-', wr.rack_code, '-', wb.bin_code) as location_code,
                        wb.bin_name as location_name
                    FROM inventory_locations il
                    JOIN warehouse_bins wb ON il.bin_id = wb.id
                    JOIN warehouse_racks wr ON wb.rack_id = wr.id
                    JOIN warehouse_aisles wa ON wr.aisle_id = wa.id
                    JOIN warehouse_zones wz ON wa.zone_id = wz.id
                    WHERE il.product_id = :product_id
                ");
                $stmt->execute([':product_id' => $item['product_id']]);
                $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $item['locations'] = $locations;
                $item['location_count'] = count($locations);
                $item['locations_text'] = implode(', ', array_map(function($loc) {
                    return $loc['location_code'] . ' (' . $loc['quantity'] . ')';
                }, $locations));
                
                // If no specific locations found, create a default location entry for existing inventory
                if (empty($locations) && $item['quantity'] > 0) {
                    // Try to assign to a default location or show warehouse only
                    $item['locations_text'] = $item['warehouse_name'] . ' (Unassigned: ' . $item['quantity'] . ')';
                }
            } catch (Exception $e) {
                // Fallback if warehouse structure tables don't exist
                $item['locations'] = [];
                $item['location_count'] = 0;
                $item['locations_text'] = $item['warehouse_name'] . ' (Structure not configured)';
            }
            
            // Get pending POs from PSM (with error handling)
            try {
                $stmt = $conn->prepare("
                    SELECT SUM(poi.quantity - poi.received_quantity) as pending_qty
                    FROM purchase_order_items poi
                    JOIN purchase_orders po ON poi.po_id = po.id
                    WHERE poi.product_id = :product_id 
                        AND po.status IN ('pending', 'approved', 'in_transit')
                ");
                $stmt->execute([':product_id' => $item['product_id']]);
                $item['pending_orders'] = (int)$stmt->fetchColumn();
            } catch (Exception $e) {
                $item['pending_orders'] = 0;
            }
            
            // Get reserved for sales orders (simplified - set to 0 for now)
            $item['reserved_for_sales'] = 0;
            
            // Stock status
            if ($item['quantity'] <= 0) {
                $item['stock_status'] = 'out_of_stock';
            } elseif ($item['quantity'] <= $item['reorder_point']) {
                $item['stock_status'] = 'low_stock';
            } else {
                $item['stock_status'] = 'in_stock';
            }
        }
        
        json_ok(['items' => $inventory, 'total' => count($inventory)]);
    }
    
    if ($method === 'POST') {
        // Receive inventory (from PSM or manual)
        $data = read_json_body();
        require_params($data, ['product_id', 'warehouse_id', 'quantity']);
        
        $product_id = (int)$data['product_id'];
        $warehouse_id = (int)$data['warehouse_id'];
        $quantity = (int)$data['quantity'];
        $bin_id = isset($data['bin_id']) ? (int)$data['bin_id'] : null;
        $batch_number = $data['batch_number'] ?? null;
        $expiry_date = $data['expiry_date'] ?? null;
        $reference_type = $data['reference_type'] ?? 'manual';
        $reference_id = isset($data['reference_id']) ? (int)$data['reference_id'] : null;
        $notes = $data['notes'] ?? '';
        
        $conn->beginTransaction();
        
        try {
            // Update or insert inventory
            $stmt = $conn->prepare("
                INSERT INTO inventory (product_id, warehouse_id, quantity, last_restocked_at)
                VALUES (:product_id, :warehouse_id, :quantity, NOW())
                ON DUPLICATE KEY UPDATE 
                    quantity = quantity + :quantity,
                    last_restocked_at = NOW()
            ");
            $stmt->execute([
                ':product_id' => $product_id,
                ':warehouse_id' => $warehouse_id,
                ':quantity' => $quantity
            ]);
            
            // If bin_id provided, update inventory_locations
            if ($bin_id) {
                $stmt = $conn->prepare("
                    INSERT INTO inventory_locations 
                    (product_id, warehouse_id, bin_id, quantity, batch_number, expiry_date, last_moved_at)
                    VALUES (:product_id, :warehouse_id, :bin_id, :quantity, :batch_number, :expiry_date, NOW())
                    ON DUPLICATE KEY UPDATE
                        quantity = quantity + :quantity,
                        last_moved_at = NOW()
                ");
                $stmt->execute([
                    ':product_id' => $product_id,
                    ':warehouse_id' => $warehouse_id,
                    ':bin_id' => $bin_id,
                    ':quantity' => $quantity,
                    ':batch_number' => $batch_number,
                    ':expiry_date' => $expiry_date
                ]);
                
                // Update bin current_units
                $stmt = $conn->prepare("
                    UPDATE warehouse_bins 
                    SET current_units = current_units + :quantity
                    WHERE id = :bin_id
                ");
                $stmt->execute([
                    ':quantity' => $quantity,
                    ':bin_id' => $bin_id
                ]);
            }
            
            // Log transaction
            $stmt = $conn->prepare("
                INSERT INTO inventory_transactions 
                (product_id, warehouse_id, transaction_type, quantity, to_location_id,
                 batch_number, reference_type, reference_id, notes, performed_by, created_at)
                VALUES (:product_id, :warehouse_id, 'receipt', :quantity, :bin_id,
                        :batch_number, :reference_type, :reference_id, :notes, :user_id, NOW())
            ");
            $stmt->execute([
                ':product_id' => $product_id,
                ':warehouse_id' => $warehouse_id,
                ':quantity' => $quantity,
                ':bin_id' => $bin_id,
                ':batch_number' => $batch_number,
                ':reference_type' => $reference_type,
                ':reference_id' => $reference_id,
                ':notes' => $notes,
                ':user_id' => $auth['id']
            ]);
            
            $transaction_id = (int)$conn->lastInsertId();
            
            $conn->commit();
            
            log_audit('inventory', $product_id, 'receive', $auth['id'], json_encode($data));
            
            json_ok([
                'success' => true,
                'message' => 'Inventory received successfully',
                'transaction_id' => $transaction_id
            ], 201);
            
        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    }
    
    if ($method === 'PUT') {
        // Adjust inventory
        $data = read_json_body();
        require_params($data, ['product_id', 'warehouse_id', 'adjustment']);
        
        $product_id = (int)$data['product_id'];
        $warehouse_id = (int)$data['warehouse_id'];
        $adjustment = (int)$data['adjustment']; // Can be positive or negative
        $reason_code = $data['reason_code'] ?? 'adjustment';
        $notes = $data['notes'] ?? '';
        $requires_approval = isset($data['requires_approval']) ? (bool)$data['requires_approval'] : false;
        
        $conn->beginTransaction();
        
        try {
            // Update inventory
            $stmt = $conn->prepare("
                UPDATE inventory 
                SET quantity = quantity + :adjustment
                WHERE product_id = :product_id AND warehouse_id = :warehouse_id
            ");
            $stmt->execute([
                ':adjustment' => $adjustment,
                ':product_id' => $product_id,
                ':warehouse_id' => $warehouse_id
            ]);
            
            // Log transaction
            $stmt = $conn->prepare("
                INSERT INTO inventory_transactions 
                (product_id, warehouse_id, transaction_type, quantity, reason_code,
                 notes, performed_by, approval_status, created_at)
                VALUES (:product_id, :warehouse_id, 'adjustment', :quantity, :reason_code,
                        :notes, :user_id, :approval_status, NOW())
            ");
            $stmt->execute([
                ':product_id' => $product_id,
                ':warehouse_id' => $warehouse_id,
                ':quantity' => $adjustment,
                ':reason_code' => $reason_code,
                ':notes' => $notes,
                ':user_id' => $auth['id'],
                ':approval_status' => $requires_approval ? 'pending' : 'approved'
            ]);
            
            $transaction_id = (int)$conn->lastInsertId();
            
            $conn->commit();
            
            log_audit('inventory', $product_id, 'adjust', $auth['id'], json_encode($data));
            
            json_ok([
                'success' => true,
                'message' => 'Inventory adjusted successfully',
                'transaction_id' => $transaction_id,
                'requires_approval' => $requires_approval
            ]);
            
        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    }
    
    json_err('Method not allowed', 405);
    
} catch (Exception $e) {
    json_err($e->getMessage(), 500);
}

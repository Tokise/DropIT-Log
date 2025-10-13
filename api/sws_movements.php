<?php
/**
 * SWS Stock Movements API
 * Handles transfers, adjustments, and movement tracking with module integration
 */

require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();
$auth = require_auth();

try {
    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'list';
        
        if ($action === 'list') {
            $warehouse_id = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : null;
            $product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : null;
            $transaction_type = $_GET['transaction_type'] ?? null;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
            
            $where = ['1=1'];
            $params = [];
            
            if ($warehouse_id) {
                $where[] = 'it.warehouse_id = :warehouse_id';
                $params[':warehouse_id'] = $warehouse_id;
            }
            
            if ($product_id) {
                $where[] = 'it.product_id = :product_id';
                $params[':product_id'] = $product_id;
            }
            
            if ($transaction_type) {
                $where[] = 'it.transaction_type = :transaction_type';
                $params[':transaction_type'] = $transaction_type;
            }
            
            $whereClause = implode(' AND ', $where);
            
            $sql = "
                SELECT 
                    it.*,
                    p.name as product_name,
                    p.sku,
                    p.barcode,
                    w.name as warehouse_name,
                    u.username as performed_by_name
                FROM inventory_transactions it
                JOIN products p ON it.product_id = p.id
                JOIN warehouses w ON it.warehouse_id = w.id
                LEFT JOIN users u ON it.performed_by = u.id
                WHERE $whereClause
                ORDER BY it.created_at DESC
                LIMIT $limit
            ";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            json_ok(['items' => $movements, 'total' => count($movements)]);
        }
        
        if ($action === 'reasons') {
            // Get movement reasons
            $stmt = $conn->query("
                SELECT * FROM movement_reasons 
                WHERE is_active = 1 
                ORDER BY category, code
            ");
            $reasons = $stmt->fetchAll(PDO::FETCH_ASSOC);
            json_ok(['items' => $reasons]);
        }
        
        if ($action === 'pending_approvals') {
            // Get movements pending approval
            $stmt = $conn->prepare("
                SELECT 
                    it.*,
                    p.name as product_name,
                    p.sku,
                    w.name as warehouse_name,
                    u.username as performed_by_name
                FROM inventory_transactions it
                JOIN products p ON it.product_id = p.id
                JOIN warehouses w ON it.warehouse_id = w.id
                LEFT JOIN users u ON it.performed_by = u.id
                WHERE it.approval_status = 'pending'
                ORDER BY it.created_at DESC
            ");
            $stmt->execute();
            $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);
            json_ok(['items' => $pending, 'total' => count($pending)]);
        }
    }
    
    if ($method === 'POST') {
        $action = $_GET['action'] ?? 'transfer';
        $data = read_json_body();
        
        if ($action === 'transfer') {
            // Transfer stock between locations
            require_params($data, ['product_id', 'warehouse_id', 'from_bin_id', 'to_bin_id', 'quantity']);
            
            $product_id = (int)$data['product_id'];
            $warehouse_id = (int)$data['warehouse_id'];
            $from_bin_id = (int)$data['from_bin_id'];
            $to_bin_id = (int)$data['to_bin_id'];
            $quantity = (int)$data['quantity'];
            $reason_code = $data['reason_code'] ?? 'relocation';
            $notes = $data['notes'] ?? '';
            
            $conn->beginTransaction();
            
            try {
                // Check source has enough quantity
                $stmt = $conn->prepare("
                    SELECT quantity FROM inventory_locations
                    WHERE product_id = :product_id 
                        AND warehouse_id = :warehouse_id 
                        AND bin_id = :bin_id
                ");
                $stmt->execute([
                    ':product_id' => $product_id,
                    ':warehouse_id' => $warehouse_id,
                    ':bin_id' => $from_bin_id
                ]);
                $source_qty = (int)$stmt->fetchColumn();
                
                if ($source_qty < $quantity) {
                    throw new Exception('Insufficient quantity in source location');
                }
                
                // Reduce from source
                $stmt = $conn->prepare("
                    UPDATE inventory_locations
                    SET quantity = quantity - :quantity,
                        last_moved_at = NOW()
                    WHERE product_id = :product_id 
                        AND warehouse_id = :warehouse_id 
                        AND bin_id = :bin_id
                ");
                $stmt->execute([
                    ':quantity' => $quantity,
                    ':product_id' => $product_id,
                    ':warehouse_id' => $warehouse_id,
                    ':bin_id' => $from_bin_id
                ]);
                
                // Add to destination
                $stmt = $conn->prepare("
                    INSERT INTO inventory_locations
                    (product_id, warehouse_id, bin_id, quantity, last_moved_at)
                    VALUES (:product_id, :warehouse_id, :bin_id, :quantity, NOW())
                    ON DUPLICATE KEY UPDATE
                        quantity = quantity + :quantity,
                        last_moved_at = NOW()
                ");
                $stmt->execute([
                    ':product_id' => $product_id,
                    ':warehouse_id' => $warehouse_id,
                    ':bin_id' => $to_bin_id,
                    ':quantity' => $quantity
                ]);
                
                // Update bin units
                $stmt = $conn->prepare("
                    UPDATE warehouse_bins 
                    SET current_units = current_units - :quantity
                    WHERE id = :bin_id
                ");
                $stmt->execute([':quantity' => $quantity, ':bin_id' => $from_bin_id]);
                
                $stmt = $conn->prepare("
                    UPDATE warehouse_bins 
                    SET current_units = current_units + :quantity
                    WHERE id = :bin_id
                ");
                $stmt->execute([':quantity' => $quantity, ':bin_id' => $to_bin_id]);
                
                // Log transaction
                $stmt = $conn->prepare("
                    INSERT INTO inventory_transactions
                    (product_id, warehouse_id, transaction_type, quantity,
                     from_location_id, to_location_id, reason_code, notes,
                     performed_by, created_at)
                    VALUES (:product_id, :warehouse_id, 'transfer', :quantity,
                            :from_bin_id, :to_bin_id, :reason_code, :notes,
                            :user_id, NOW())
                ");
                $stmt->execute([
                    ':product_id' => $product_id,
                    ':warehouse_id' => $warehouse_id,
                    ':quantity' => $quantity,
                    ':from_bin_id' => $from_bin_id,
                    ':to_bin_id' => $to_bin_id,
                    ':reason_code' => $reason_code,
                    ':notes' => $notes,
                    ':user_id' => $auth['id']
                ]);
                
                $transaction_id = (int)$conn->lastInsertId();
                
                $conn->commit();
                
                log_audit('inventory_transaction', $transaction_id, 'transfer', $auth['id'], json_encode($data));
                
                json_ok([
                    'success' => true,
                    'message' => 'Stock transferred successfully',
                    'transaction_id' => $transaction_id
                ], 201);
                
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }
        }
        
        if ($action === 'pick') {
            // Pick items for sales order (PLT integration)
            require_params($data, ['sales_order_id', 'items']);
            
            $sales_order_id = (int)$data['sales_order_id'];
            $items = $data['items']; // Array of {product_id, bin_id, quantity}
            
            $conn->beginTransaction();
            
            try {
                foreach ($items as $item) {
                    $product_id = (int)$item['product_id'];
                    $bin_id = (int)$item['bin_id'];
                    $quantity = (int)$item['quantity'];
                    
                    // Reduce from location
                    $stmt = $conn->prepare("
                        UPDATE inventory_locations
                        SET quantity = quantity - :quantity
                        WHERE product_id = :product_id AND bin_id = :bin_id
                    ");
                    $stmt->execute([
                        ':quantity' => $quantity,
                        ':product_id' => $product_id,
                        ':bin_id' => $bin_id
                    ]);
                    
                    // Reduce from main inventory
                    $stmt = $conn->prepare("
                        UPDATE inventory
                        SET quantity = quantity - :quantity,
                            reserved_quantity = reserved_quantity - :quantity
                        WHERE product_id = :product_id
                    ");
                    $stmt->execute([
                        ':quantity' => $quantity,
                        ':product_id' => $product_id
                    ]);
                    
                    // Log transaction
                    $stmt = $conn->prepare("
                        INSERT INTO inventory_transactions
                        (product_id, warehouse_id, transaction_type, quantity,
                         from_location_id, reference_type, reference_id, notes,
                         performed_by, created_at)
                        VALUES (:product_id, 1, 'sale', :quantity,
                                :bin_id, 'sales_order', :sales_order_id,
                                'Picked for SO #' || :sales_order_id,
                                :user_id, NOW())
                    ");
                    $stmt->execute([
                        ':product_id' => $product_id,
                        ':quantity' => -$quantity,
                        ':bin_id' => $bin_id,
                        ':sales_order_id' => $sales_order_id,
                        ':user_id' => $auth['id']
                    ]);
                }
                
                $conn->commit();
                
                json_ok([
                    'success' => true,
                    'message' => 'Items picked successfully',
                    'sales_order_id' => $sales_order_id
                ], 201);
                
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }
        }
        
        if ($action === 'approve') {
            // Approve pending movement
            require_params($data, ['transaction_id']);
            
            $transaction_id = (int)$data['transaction_id'];
            $approved = isset($data['approved']) ? (bool)$data['approved'] : true;
            $notes = $data['notes'] ?? '';
            
            $stmt = $conn->prepare("
                UPDATE inventory_transactions
                SET approval_status = :status,
                    approved_by = :user_id,
                    approved_at = NOW(),
                    notes = CONCAT(notes, '\n', :notes)
                WHERE id = :transaction_id
            ");
            $stmt->execute([
                ':status' => $approved ? 'approved' : 'rejected',
                ':user_id' => $auth['id'],
                ':notes' => $notes,
                ':transaction_id' => $transaction_id
            ]);
            
            log_audit('inventory_transaction', $transaction_id, $approved ? 'approve' : 'reject', $auth['id'], $notes);
            
            json_ok([
                'success' => true,
                'message' => 'Movement ' . ($approved ? 'approved' : 'rejected')
            ]);
        }
    }
    
    json_err('Method not allowed', 405);
    
} catch (Exception $e) {
    json_err($e->getMessage(), 500);
}

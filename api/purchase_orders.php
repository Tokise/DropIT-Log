<?php
require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();

function po_recalc_total(PDO $conn, int $poId) {
    $total = (float)$conn->query("SELECT COALESCE(SUM(quantity*unit_price),0) FROM purchase_order_items WHERE po_id = " . (int)$poId)->fetchColumn();
    $stmt = $conn->prepare("UPDATE purchase_orders SET total_amount = :t WHERE id = :id");
    $stmt->execute([':t' => $total, ':id' => $poId]);
    return $total;
}

try {
    if ($method === 'GET') {
        // Disable caching
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id > 0) {
            $stmt = $conn->prepare("SELECT po.*, s.name AS supplier_name, w.name AS warehouse_name FROM purchase_orders po JOIN suppliers s ON po.supplier_id=s.id JOIN warehouses w ON po.warehouse_id=w.id WHERE po.id = :id");
            $stmt->execute([':id' => $id]);
            $po = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$po) json_err('PO not found', 404);
            $items = $conn->prepare("SELECT poi.*, p.sku, p.name FROM purchase_order_items poi JOIN products p ON poi.product_id=p.id WHERE poi.po_id = :id");
            $items->execute([':id' => $id]);
            $po['items'] = $items->fetchAll(PDO::FETCH_ASSOC);
            json_ok($po);
        } else {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;
            $status = $_GET['status'] ?? null;
            $where = [];
            $params = [];

            if ($status === 'archived') {
                $where[] = 'po.is_archived = 1';
            } else {
                $where[] = 'po.is_archived = 0';
                if ($status !== null && $status !== '') {
                    $where[] = 'po.status = :status';
                    $params[':status'] = $status;
                }
            }
            $whereSql = empty($where)?'':'WHERE '.implode(' AND ',$where);
            $sql = "SELECT SQL_CALC_FOUND_ROWS po.*, s.name AS supplier_name, w.name AS warehouse_name
                    FROM purchase_orders po
                    JOIN suppliers s ON po.supplier_id=s.id
                    JOIN warehouses w ON po.warehouse_id=w.id
                    $whereSql
                    ORDER BY po.created_at DESC LIMIT :limit OFFSET :offset";
            $stmt = $conn->prepare($sql);
            foreach ($params as $k=>$v) { $stmt->bindValue($k,$v); }
            $stmt->bindValue(':limit',$limit,PDO::PARAM_INT);
            $stmt->bindValue(':offset',$offset,PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total = (int)$conn->query('SELECT FOUND_ROWS()')->fetchColumn();
            
            // Debug: log what we're returning
            error_log("PO API: Fetched " . count($rows) . " POs with status filter: " . ($status ?? 'none'));
            foreach ($rows as $row) {
                error_log("PO: " . $row['po_number'] . " - Status: " . $row['status']);
            }
            
            json_ok(['items'=>$rows,'page'=>$page,'limit'=>$limit,'total'=>$total]);
        }
    }

    if ($method === 'POST') {
        $data = read_json_body();
        $auth = require_auth();
        $authUserId = (int)$auth['id'];
        
        // New enhanced PO creation with items
        require_params($data, ['supplier_id']);
        $items = $data['items'] ?? [];
        
        // Generate PO number
        $poNumber = 'PO-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        
        // Get default warehouse
        $whStmt = $conn->prepare("SELECT id FROM warehouses WHERE is_active = 1 LIMIT 1");
        $whStmt->execute();
        $warehouseId = $whStmt->fetchColumn();
        if (!$warehouseId) json_err('No active warehouse found', 422);
        
        $conn->beginTransaction();
        try {
            // Create PO with explicit 'draft' status
            $stmt = $conn->prepare("INSERT INTO purchase_orders (po_number, supplier_id, warehouse_id, status, total_amount, created_by, order_date, expected_delivery_date, ai_predicted_delivery, notes) VALUES (:po_number, :supplier_id, :warehouse_id, :status, :total_amount, :created_by, :order_date, :expected_delivery_date, :ai_predicted_delivery, :notes)");
            $stmt->execute([
                ':po_number' => $poNumber,
                ':supplier_id' => (int)$data['supplier_id'],
                ':warehouse_id' => (int)$warehouseId,
                ':status' => 'draft',  // Explicit status parameter
                ':total_amount' => (float)($data['total_amount'] ?? 0),
                ':created_by' => $authUserId,
                ':order_date' => $data['order_date'] ?? date('Y-m-d'),
                ':expected_delivery_date' => $data['expected_delivery'] ?? null,
                ':ai_predicted_delivery' => $data['expected_delivery'] ?? null,
                ':notes' => $data['notes'] ?? null,
            ]);
            $poId = (int)$conn->lastInsertId();
            
            // Verify the PO was created with correct status
            $verifyStmt = $conn->prepare("SELECT status FROM purchase_orders WHERE id = :id");
            $verifyStmt->execute([':id' => $poId]);
            $createdStatus = $verifyStmt->fetchColumn();
            
            if ($createdStatus !== 'draft') {
                error_log("WARNING: PO created with status '$createdStatus' instead of 'draft'");
            }
            
            // Insert line items if provided
            if (!empty($items) && is_array($items)) {
                // Check if using supplier products (new way) or regular products (old way for compatibility)
                $useSupplierProducts = isset($items[0]['supplier_product_id']);
                
                if ($useSupplierProducts) {
                    // New way: Using supplier products
                    $ins = $conn->prepare("INSERT INTO po_items (po_id, supplier_product_id, product_name, quantity, unit_price, total_price) VALUES (:po_id, :supplier_product_id, :product_name, :quantity, :unit_price, :total_price)");
                    foreach ($items as $it) {
                        if (!isset($it['supplier_product_id'], $it['quantity'], $it['unit_price'])) {
                            $conn->rollBack();
                            json_err('Each item requires supplier_product_id, quantity, unit_price', 422);
                        }
                        
                        // Get product name from supplier_products_catalog
                        $spStmt = $conn->prepare("SELECT product_name FROM supplier_products_catalog WHERE id = :id");
                        $spStmt->execute([':id' => $it['supplier_product_id']]);
                        $productName = $spStmt->fetchColumn();
                        
                        $totalPrice = $it['quantity'] * $it['unit_price'];
                        
                        $ins->execute([
                            ':po_id' => $poId,
                            ':supplier_product_id' => (int)$it['supplier_product_id'],
                            ':product_name' => $productName,
                            ':quantity' => (float)$it['quantity'],
                            ':unit_price' => (float)$it['unit_price'],
                            ':total_price' => $totalPrice
                        ]);
                    }
                } else {
                    // Old way: Using regular products (for backward compatibility)
                    $ins = $conn->prepare("INSERT INTO purchase_order_items (po_id, product_id, quantity, unit_price, received_quantity) VALUES (:po_id, :product_id, :quantity, :unit_price, 0)");
                    foreach ($items as $it) {
                        if (!isset($it['product_id'], $it['quantity'], $it['unit_price'])) {
                            $conn->rollBack();
                            json_err('Each item requires product_id, quantity, unit_price', 422);
                        }
                        $ins->execute([
                            ':po_id' => $poId,
                            ':product_id' => (int)$it['product_id'],
                            ':quantity' => (int)$it['quantity'],
                            ':unit_price' => (float)$it['unit_price'],
                        ]);
                    }
                }
                // Recalculate total
                $total = po_recalc_total($conn, $poId);
            }
            
            // Log status history
            $histStmt = $conn->prepare("INSERT INTO po_status_history (po_id, from_status, to_status, changed_by, changed_by_type, notes) VALUES (:po_id, NULL, 'draft', :changed_by, 'user', 'PO created')");
            $histStmt->execute([':po_id' => $poId, ':changed_by' => $authUserId]);
            
            $conn->commit();
            json_ok(['id' => $poId, 'po_number' => $poNumber, 'total' => $total ?? 0], 201);
        } catch (Exception $e) {
            $conn->rollBack();
            json_err($e->getMessage(), 400);
        }
    }

    if ($method === 'PUT') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) json_err('id is required', 422);
        $data = read_json_body();
        // Update status or header fields; support receiving items
        if (isset($data['receive'])) {
            // Receive items: [{item_id, qty, performed_by}]
            $receipts = $data['receive'];
            if (!is_array($receipts) || empty($receipts)) json_err('receive array is empty',422);
            $conn->beginTransaction();
            try {
                foreach ($receipts as $r) {
                    require_params($r,['item_id','qty','performed_by']);
                    $qty = (int)$r['qty'];
                    $itemId = (int)$r['item_id'];
                    // Update received qty
                    $stmt = $conn->prepare("UPDATE purchase_order_items SET received_quantity = received_quantity + :q WHERE id = :id");
                    $stmt->execute([':q'=>$qty, ':id'=>$itemId]);
                    // Find product and warehouse
                    $row = $conn->query("SELECT poi.product_id, po.warehouse_id FROM purchase_order_items poi JOIN purchase_orders po ON poi.po_id=po.id WHERE poi.id = " . (int)$itemId)->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        // Adjust inventory and log
                        $stmt = $conn->prepare("SELECT id, quantity FROM inventory WHERE product_id = :pid AND warehouse_id = :wid FOR UPDATE");
                        $stmt->execute([':pid'=>$row['product_id'],':wid'=>$row['warehouse_id']]);
                        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($inv) {
                            $stmt = $conn->prepare("UPDATE inventory SET quantity = quantity + :q, last_restocked_at = NOW(), updated_at = NOW() WHERE id = :id");
                            $stmt->execute([':q'=>$qty, ':id'=>$inv['id']]);
                        } else {
                            $stmt = $conn->prepare("INSERT INTO inventory (product_id, warehouse_id, quantity, reserved_quantity, last_restocked_at) VALUES (:pid,:wid,:q,0,NOW())");
                            $stmt->execute([':pid'=>$row['product_id'],':wid'=>$row['warehouse_id'],':q'=>$qty]);
                        }
                        $log = $conn->prepare("INSERT INTO inventory_transactions (product_id, warehouse_id, transaction_type, quantity, reference_type, reference_id, notes, performed_by) VALUES (:pid,:wid,'receipt',:q,'purchase_order',:po_id,'PO receipt',:uid)");
                        $log->execute([':pid'=>$row['product_id'],':wid'=>$row['warehouse_id'],':q'=>$qty, ':po_id'=>$id, ':uid'=>(int)$r['performed_by']]);
                    }
                }
                // Update PO status heuristically
                $counts = $conn->query("SELECT SUM(quantity) q, SUM(received_quantity) r FROM purchase_order_items WHERE po_id = " . (int)$id)->fetch(PDO::FETCH_ASSOC);
                if ($counts && (int)$counts['r'] >= (int)$counts['q']) {
                    $conn->prepare("UPDATE purchase_orders SET status='received', actual_delivery_date = CURDATE() WHERE id = :id")->execute([':id'=>$id]);
                } else {
                    $conn->prepare("UPDATE purchase_orders SET status='partially_received' WHERE id = :id")->execute([':id'=>$id]);
                }
                $conn->commit();
                json_ok(['received'=>true]);
            } catch (Exception $e) {
                $conn->rollBack();
                json_err($e->getMessage(),400);
            }
        } else {
            // Header update
            $conn->beginTransaction();
            try {
                // Get current PO data
                $stmt = $conn->prepare("SELECT status FROM purchase_orders WHERE id = :id");
                $stmt->execute([':id' => $id]);
                $currentPO = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$currentPO) {
                    json_err('PO not found', 404);
                }
                
                $oldStatus = $currentPO['status'];
                $newStatus = $data['status'] ?? null;
                
                $fields = ['status','approved_by','expected_delivery_date','notes'];
                $sets = [];$params = [':id'=>$id];
                foreach ($fields as $f) { 
                    if (array_key_exists($f,$data)) { 
                        $sets[] = "$f = :$f"; 
                        $params[":$f"] = $data[$f]; 
                    } 
                }
                
                if (empty($sets)) json_err('No fields to update',422);
                
                $sql = 'UPDATE purchase_orders SET '.implode(', ',$sets).' WHERE id = :id';
                $stmt = $conn->prepare($sql); 
                $stmt->execute($params);
                
                // Log status change if status was updated
                if ($newStatus && $newStatus !== $oldStatus) {
                    try {
                        $histStmt = $conn->prepare("INSERT INTO po_status_history (po_id, from_status, to_status, changed_by, changed_by_type, notes) VALUES (:po_id, :from_status, :to_status, :changed_by, 'user', :notes)");
                        $histStmt->execute([
                            ':po_id' => $id,
                            ':from_status' => $oldStatus,
                            ':to_status' => $newStatus,
                            ':changed_by' => $authUserId,
                            ':notes' => $data['notes'] ?? "Status changed from $oldStatus to $newStatus"
                        ]);
                    } catch (Exception $e) {
                        // Continue even if history logging fails
                        error_log("Failed to log status history: " . $e->getMessage());
                    }
                }
                
                $conn->commit();
                json_ok(['updated'=>true, 'old_status' => $oldStatus, 'new_status' => $newStatus]);
            } catch (Exception $e) {
                $conn->rollBack();
                json_err($e->getMessage(), 400);
            }
        }
    }

    json_err('Method not allowed',405);
} catch (Exception $e) {
    json_err($e->getMessage(),400);
}

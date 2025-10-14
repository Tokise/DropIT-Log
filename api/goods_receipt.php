<?php
/**
 * Goods Receipt API
 * Handles receiving goods from purchase orders
 */
require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();
$auth = require_auth();
$authUserId = (int)$auth['id'];

try {
    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        
        if ($id > 0) {
            // Get specific receipt
            $stmt = $conn->prepare("
                SELECT rq.*, 
                       po.po_number,
                       po.supplier_id,
                       s.name as supplier_name,
                       sp.product_name,
                       sp.product_code,
                       u.full_name as received_by_name
                FROM receiving_queue rq
                JOIN po_items poi ON rq.po_item_id = poi.id
                JOIN purchase_orders po ON poi.po_id = po.id
                JOIN suppliers s ON po.supplier_id = s.id
                JOIN supplier_products_catalog sp ON rq.supplier_product_id = sp.id
                LEFT JOIN users u ON rq.received_by = u.id
                WHERE rq.id = :id
            ");
            $stmt->execute([':id' => $id]);
            $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$receipt) {
                json_err('Receipt not found', 404);
            }
            
            json_ok($receipt);
        } else {
            // List all receipts with filters
            $status = $_GET['status'] ?? '';
            $qc_status = $_GET['qc_status'] ?? '';
            $po_id = isset($_GET['po_id']) ? (int)$_GET['po_id'] : 0;
            
            $where = ['1=1'];
            $params = [];
            
            if ($status) {
                $where[] = 'rq.status = :status';
                $params[':status'] = $status;
            }
            
            if ($qc_status) {
                $where[] = 'rq.qc_status = :qc_status';
                $params[':qc_status'] = $qc_status;
            }
            
            if ($po_id > 0) {
                $where[] = 'po.id = :po_id';
                $params[':po_id'] = $po_id;
            }
            
            $whereClause = implode(' AND ', $where);
            
            $stmt = $conn->prepare("
                SELECT rq.*, 
                       po.po_number,
                       po.supplier_id,
                       s.name as supplier_name,
                       sp.product_name,
                       sp.product_code,
                       u.full_name as received_by_name
                FROM receiving_queue rq
                JOIN po_items poi ON rq.po_item_id = poi.id
                JOIN purchase_orders po ON poi.po_id = po.id
                JOIN suppliers s ON po.supplier_id = s.id
                JOIN supplier_products_catalog sp ON rq.supplier_product_id = sp.id
                LEFT JOIN users u ON rq.received_by = u.id
                WHERE $whereClause
                ORDER BY rq.created_at DESC
            ");
            $stmt->execute($params);
            $receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            json_ok(['items' => $receipts, 'count' => count($receipts)]);
        }
    }
    
    if ($method === 'POST') {
        // Receive goods from a PO
        $data = read_json_body();
        require_params($data, ['po_id', 'items']);
        
        $poId = (int)$data['po_id'];
        $items = $data['items'];
        
        // Verify PO exists and is approved
        $stmt = $conn->prepare("SELECT * FROM purchase_orders WHERE id = :id AND status IN ('approved', 'sent')");
        $stmt->execute([':id' => $poId]);
        $po = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$po) {
            json_err('Purchase order not found or not approved', 404);
        }
        
        $conn->beginTransaction();
        try {
            $receiptNumber = 'GR-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
            
            foreach ($items as $item) {
                require_params($item, ['po_item_id', 'received_quantity']);
                
                $poItemId = (int)$item['po_item_id'];
                $receivedQty = (int)$item['received_quantity'];
                $qcStatus = $item['qc_status'] ?? 'pending';
                $qcNotes = $item['qc_notes'] ?? null;
                $locationCode = $item['location_code'] ?? null;
                $batchNumber = $item['batch_number'] ?? null;
                $expiryDate = $item['expiry_date'] ?? null;
                
                // Get PO item details
                $stmt = $conn->prepare("SELECT * FROM po_items WHERE id = :id AND po_id = :po_id");
                $stmt->execute([':id' => $poItemId, ':po_id' => $poId]);
                $poItem = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$poItem) {
                }
            }
            $totals = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $newStatus = ($totals['total_received'] >= $totals['total_ordered']) ? 'received' : 'partially_received';
            
            $stmt = $conn->prepare("UPDATE purchase_orders SET status = :status WHERE id = :id");
            $stmt->execute([':status' => $newStatus, ':id' => $poId]);
            
            // Audit log
            log_audit('goods_receipt', $poId, 'receive', $authUserId, json_encode($data));
            
            $conn->commit();
            json_ok(['receipt_number' => $receiptNumber, 'message' => 'Goods received successfully']);
            
        } catch (Exception $e) {
            $conn->rollBack();
            json_err('Failed to receive goods: ' . $e->getMessage(), 500);
        }
    }
    
    if ($method === 'PUT') {
        // Update receipt (QC status, location, etc.)
        $data = read_json_body();
        require_params($data, ['id']);
        
        $id = (int)$data['id'];
        
        $updates = [];
        $params = [':id' => $id];
        
        $allowedFields = ['qc_status', 'qc_notes', 'location_code', 'batch_number', 'expiry_date', 'status'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }
        
        if (empty($updates)) {
            json_err('No fields to update', 400);
        }
        
        $sql = "UPDATE receiving_queue SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        
        // If QC passed, update inventory
        if (isset($data['qc_status']) && $data['qc_status'] === 'passed') {
            // Get receipt details
            $stmt = $conn->prepare("SELECT * FROM receiving_queue WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($receipt && $receipt['inventory_product_id']) {
                // Add to inventory
                $stmt = $conn->prepare("
                    INSERT INTO inventory_transactions 
                    (product_id, warehouse_id, transaction_type, quantity, reference_type, reference_id, performed_by, notes)
                    VALUES 
                    (:product_id, :warehouse_id, 'receipt', :quantity, 'goods_receipt', :reference_id, :performed_by, :notes)
                ");
                $stmt->execute([
                    ':product_id' => $receipt['inventory_product_id'],
                    ':warehouse_id' => 1, // Default warehouse
                    ':quantity' => $receipt['quantity'],
                    ':reference_id' => $id,
                    ':performed_by' => $authUserId,
                    ':notes' => 'Goods receipt: ' . $receipt['receipt_number']
                ]);
                
                // Update receiving_queue status
                $stmt = $conn->prepare("UPDATE receiving_queue SET status = 'completed' WHERE id = :id");
                $stmt->execute([':id' => $id]);
            }
        }
        
        log_audit('goods_receipt', $id, 'update', $authUserId, json_encode($data));
        json_ok(['message' => 'Receipt updated successfully']);
    }
    
} catch (Exception $e) {
    json_err($e->getMessage(), 500);
}

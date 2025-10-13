<?php
/**
 * Supplier Portal - Purchase Orders API
 * Allows suppliers to view and approve their POs
 */

require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();

// Authenticate and get supplier ID from user
$auth = require_auth();
$authUserId = (int)$auth['id'];

// Get supplier ID linked to this user
$stmt = $conn->prepare("SELECT supplier_id FROM users WHERE id = :id");
$stmt->execute([':id' => $authUserId]);
$supplierId = $stmt->fetchColumn();

if (!$supplierId) {
    json_err('This user is not linked to any supplier. Please contact administrator.', 403);
}

try {
    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        
        if ($id > 0) {
            // Get single PO with items
            $stmt = $conn->prepare("SELECT po.*, s.name AS supplier_name, w.name AS warehouse_name 
                                    FROM purchase_orders po 
                                    JOIN suppliers s ON po.supplier_id = s.id 
                                    JOIN warehouses w ON po.warehouse_id = w.id 
                                    WHERE po.id = :id");
            $stmt->execute([':id' => $id]);
            $po = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$po) json_err('PO not found', 404);
            
            // Get items - prioritize new po_items table with supplier products
            $itemsStmt = $conn->prepare("
                SELECT 
                    poi.id,
                    poi.product_name,
                    poi.quantity,
                    poi.unit_price,
                    poi.total_price,
                    poi.received_quantity,
                    spc.product_code,
                    spc.category,
                    spc.unit_of_measure
                FROM po_items poi
                LEFT JOIN supplier_products_catalog spc ON poi.supplier_product_id = spc.id
                WHERE poi.po_id = :id
            ");
            $itemsStmt->execute([':id' => $id]);
            $po['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Fallback to legacy table if no items found
            if (empty($po['items'])) {
                $itemsStmt = $conn->prepare("
                    SELECT poi.*, p.sku as product_code, p.name as product_name 
                    FROM purchase_order_items poi 
                    JOIN products p ON poi.product_id = p.id 
                    WHERE poi.po_id = :id
                ");
                $itemsStmt->execute([':id' => $id]);
                $po['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            json_ok($po);
        } else {
            // List POs
            $status = $_GET['status'] ?? null;
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;
            
            $where = ['po.supplier_id = :supplier_id'];
            $params = [':supplier_id' => $supplierId];
            
            if ($status) {
                $where[] = 'po.status = :status';
                $params[':status'] = $status;
            }
            
            $whereSql = 'WHERE ' . implode(' AND ', $where);
            
            $sql = "SELECT SQL_CALC_FOUND_ROWS po.*, s.name AS supplier_name, w.name AS warehouse_name
                    FROM purchase_orders po
                    JOIN suppliers s ON po.supplier_id = s.id
                    JOIN warehouses w ON po.warehouse_id = w.id
                    $whereSql
                    ORDER BY po.created_at DESC 
                    LIMIT :limit OFFSET :offset";
            
            $stmt = $conn->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total = (int)$conn->query('SELECT FOUND_ROWS()')->fetchColumn();
            
            json_ok(['items' => $rows, 'page' => $page, 'limit' => $limit, 'total' => $total]);
        }
    }
    
    json_err('Method not allowed', 405);
} catch (Exception $e) {
    json_err($e->getMessage(), 400);
}

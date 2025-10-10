<?php
require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();
$auth = require_auth();

try {
    if ($method === 'GET') {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : null;
        $warehouseId = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : null;

        $where = [];
        $params = [];
        
        if ($productId) { 
            $where[] = 'it.product_id = :product_id'; 
            $params[':product_id'] = $productId; 
        }
        if ($warehouseId) { 
            $where[] = 'it.warehouse_id = :warehouse_id'; 
            $params[':warehouse_id'] = $warehouseId; 
        }
        
        $whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

        $sql = "SELECT it.*, p.name as product_name, p.sku, w.name as warehouse_name, u.full_name as performed_by_name
                FROM inventory_transactions it
                LEFT JOIN products p ON it.product_id = p.id
                LEFT JOIN warehouses w ON it.warehouse_id = w.id
                LEFT JOIN users u ON it.performed_by = u.id
                $whereSql
                ORDER BY it.created_at DESC
                LIMIT :limit OFFSET :offset";
                
        $stmt = $conn->prepare($sql);
        foreach ($params as $k => $v) { 
            $stmt->bindValue($k, $v); 
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get total count
        $countSql = "SELECT COUNT(*) FROM inventory_transactions it $whereSql";
        $countStmt = $conn->prepare($countSql);
        foreach ($params as $k => $v) { 
            $countStmt->bindValue($k, $v); 
        }
        $countStmt->execute();
        $total = $countStmt->fetchColumn();
        
        json_ok(['items' => $rows, 'page' => $page, 'limit' => $limit, 'total' => $total]);
    } else {
        json_err('Method not allowed', 405);
    }
} catch (Exception $e) {
    json_err($e->getMessage(), 400);
}
?>

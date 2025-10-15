<?php
require_once 'common.php';

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

try {
    $auth = require_auth();
    
    if ($method === 'GET') {
        getReorderAlerts();
    } else {
        json_err('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    json_err('Server error: ' . $e->getMessage(), 500);
}

function getReorderAlerts() {
    $conn = db_conn();
    
    $page = (int)($_GET['page'] ?? 1);
    $limit = (int)($_GET['limit'] ?? 20);
    $offset = ($page - 1) * $limit;
    
    // Check if reorder columns exist in products table
    $columnsExist = checkReorderColumns($conn);
    
    if (!$columnsExist) {
        // If columns don't exist, return empty result with helpful message
        json_ok([
            'items' => [],
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => 0,
                'pages' => 0
            ],
            'summary' => [
                'total_products_needing_reorder' => 0,
                'critical_stock' => 0,
                'high_priority' => 0,
                'with_pending_orders' => 0
            ],
            'message' => 'Reorder management requires reorder_point, reorder_quantity, and lead_time_days columns in products table. Please run database migration.'
        ]);
        return;
    }
    
    // Find products that need reordering based on inventory levels
    $sql = "
        SELECT 
            p.id as product_id,
            p.name as product_name,
            p.sku,
            COALESCE(p.reorder_point, 10) as reorder_point,
            COALESCE(p.reorder_quantity, 50) as reorder_quantity,
            COALESCE(p.lead_time_days, 7) as lead_time_days,
            COALESCE(SUM(i.quantity), 0) as current_stock,
            COALESCE(SUM(i.reserved_quantity), 0) as reserved_stock,
            COALESCE(SUM(i.quantity - i.reserved_quantity), 0) as available_stock,
            s.name as supplier_name,
            s.id as supplier_id,
            spc.unit_price as supplier_price
        FROM products p
        LEFT JOIN inventory i ON p.id = i.product_id
        LEFT JOIN supplier_products_catalog spc ON p.sku = spc.product_code AND spc.is_active = 1
        LEFT JOIN suppliers s ON spc.supplier_id = s.id AND s.is_active = 1
        WHERE p.is_active = 1 
        AND COALESCE(p.reorder_point, 10) > 0
        GROUP BY p.id, p.name, p.sku, p.reorder_point, p.reorder_quantity, p.lead_time_days, s.name, s.id, spc.unit_price
        HAVING COALESCE(SUM(i.quantity), 0) <= COALESCE(p.reorder_point, 10)
        ORDER BY 
            CASE 
                WHEN COALESCE(SUM(i.quantity), 0) <= 0 THEN 1 
                WHEN COALESCE(SUM(i.quantity), 0) <= COALESCE(p.reorder_point, 10) / 2 THEN 2 
                ELSE 3 
            END,
            COALESCE(SUM(i.quantity), 0) ASC,
            p.name ASC
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count for pagination
    $countSql = "
        SELECT COUNT(*) as total
        FROM (
            SELECT p.id
            FROM products p
            LEFT JOIN inventory i ON p.id = i.product_id
            WHERE p.is_active = 1 
            AND COALESCE(p.reorder_point, 10) > 0
            GROUP BY p.id
            HAVING COALESCE(SUM(i.quantity), 0) <= COALESCE(p.reorder_point, 10)
        ) as subquery
    ";
    
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute();
    $totalResult = $countStmt->fetch(PDO::FETCH_ASSOC);
    $total = (int)$totalResult['total'];
    
    // Enrich the data with additional information
    foreach ($items as &$item) {
        // Set default values if not set
        $item['reorder_point'] = $item['reorder_point'] ?: 10;
        $item['reorder_quantity'] = $item['reorder_quantity'] ?: 50;
        $item['lead_time_days'] = $item['lead_time_days'] ?: 7;
        
        // Calculate urgency level
        if ($item['current_stock'] <= 0) {
            $item['urgency'] = 'critical';
            $item['urgency_label'] = 'OUT OF STOCK';
        } elseif ($item['current_stock'] <= $item['reorder_point'] / 2) {
            $item['urgency'] = 'high';
            $item['urgency_label'] = 'VERY LOW';
        } else {
            $item['urgency'] = 'medium';
            $item['urgency_label'] = 'LOW STOCK';
        }
        
        // Calculate suggested order quantity
        $item['suggested_quantity'] = max($item['reorder_quantity'], $item['reorder_point'] - $item['current_stock']);
        
        // Estimate cost if supplier price is available
        if ($item['supplier_price']) {
            $item['estimated_cost'] = $item['suggested_quantity'] * $item['supplier_price'];
        } else {
            $item['estimated_cost'] = null;
        }
        
        // Check if there are pending orders for this product
        try {
            $pendingStmt = $conn->prepare("
                SELECT SUM(poi.quantity - poi.received_quantity) as pending_qty
                FROM purchase_order_items poi
                JOIN purchase_orders po ON poi.po_id = po.id
                WHERE poi.product_id = :product_id 
                AND po.status IN ('pending_approval', 'approved', 'sent')
            ");
            $pendingStmt->execute([':product_id' => $item['product_id']]);
            $pendingResult = $pendingStmt->fetch(PDO::FETCH_ASSOC);
            $item['pending_orders'] = (int)($pendingResult['pending_qty'] ?? 0);
        } catch (Exception $e) {
            $item['pending_orders'] = 0;
        }
        
        // Adjust urgency if there are pending orders
        if ($item['pending_orders'] > 0) {
            $item['has_pending_orders'] = true;
            if ($item['pending_orders'] >= $item['reorder_point']) {
                $item['urgency'] = 'low';
                $item['urgency_label'] = 'PENDING ORDER';
            }
        } else {
            $item['has_pending_orders'] = false;
        }
    }
    
    json_ok([
        'items' => $items,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit)
        ],
        'summary' => [
            'total_products_needing_reorder' => $total,
            'critical_stock' => count(array_filter($items, fn($item) => $item['urgency'] === 'critical')),
            'high_priority' => count(array_filter($items, fn($item) => $item['urgency'] === 'high')),
            'with_pending_orders' => count(array_filter($items, fn($item) => $item['has_pending_orders']))
        ]
    ]);
}

function checkReorderColumns($conn) {
    try {
        // Try to query the columns to see if they exist
        $stmt = $conn->query("SHOW COLUMNS FROM products LIKE 'reorder_point'");
        $reorderPointExists = $stmt->rowCount() > 0;
        
        $stmt = $conn->query("SHOW COLUMNS FROM products LIKE 'reorder_quantity'");
        $reorderQuantityExists = $stmt->rowCount() > 0;
        
        $stmt = $conn->query("SHOW COLUMNS FROM products LIKE 'lead_time_days'");
        $leadTimeExists = $stmt->rowCount() > 0;
        
        return $reorderPointExists && $reorderQuantityExists && $leadTimeExists;
    } catch (Exception $e) {
        return false;
    }
}
?>

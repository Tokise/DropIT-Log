<?php
/**
 * API to get available items from shipping zones for PLT
 */

require_once 'common.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    $auth = require_auth();
    
    switch ($method) {
        case 'GET':
            getShippingItems();
            break;
            
        default:
            json_err('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    json_err('Server error: ' . $e->getMessage(), 500);
}

function getShippingItems() {
    $conn = db_conn();
    
    $warehouse_id = $_GET['warehouse_id'] ?? null;
    $search = $_GET['search'] ?? '';
    
    // Build query to get available items from shipping zones
    $whereClause = "WHERE p.is_active = 1 AND (i.quantity - i.reserved_quantity) > 0";
    $params = [];
    
    if ($warehouse_id) {
        $whereClause .= " AND w.id = :warehouse_id";
        $params[':warehouse_id'] = $warehouse_id;
    }
    
    if ($search) {
        $whereClause .= " AND (p.name LIKE :search OR p.sku LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    // Query for items - simplified to work with current schema
    $sql = "
        SELECT DISTINCT
            p.id as product_id,
            p.name as product_name,
            p.sku,
            p.description,
            p.unit_price,
            i.quantity as total_quantity,
            i.reserved_quantity,
            (i.quantity - i.reserved_quantity) as available_quantity,
            w.id as warehouse_id,
            w.name as warehouse_name,
            'Available for Shipping' as shipping_zone,
            'General Inventory' as locations
        FROM products p
        JOIN inventory i ON p.id = i.product_id
        JOIN warehouses w ON i.warehouse_id = w.id
        $whereClause
        GROUP BY p.id, i.warehouse_id
        ORDER BY p.name
        LIMIT 50
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no items found in shipping zones, get general available inventory
    if (empty($items)) {
        $sql = "
            SELECT 
                p.id as product_id,
                p.name as product_name,
                p.sku,
                p.description,
                p.unit_price,
                i.quantity as total_quantity,
                i.reserved_quantity,
                (i.quantity - i.reserved_quantity) as available_quantity,
                w.id as warehouse_id,
                w.name as warehouse_name,
                'General Inventory' as shipping_zone,
                'Available for shipping' as locations
            FROM products p
            JOIN inventory i ON p.id = i.product_id
            JOIN warehouses w ON i.warehouse_id = w.id
            $whereClause
            ORDER BY p.name
            LIMIT 50
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Format items for frontend
    $formattedItems = array_map(function($item) {
        return [
            'product_id' => (int)$item['product_id'],
            'product_name' => $item['product_name'],
            'sku' => $item['sku'],
            'description' => $item['description'],
            'unit_price' => (float)$item['unit_price'],
            'available_quantity' => (int)$item['available_quantity'],
            'warehouse_id' => (int)$item['warehouse_id'],
            'warehouse_name' => $item['warehouse_name'],
            'shipping_zone' => $item['shipping_zone'],
            'locations' => $item['locations'],
            'display_text' => "{$item['product_name']} ({$item['sku']}) - {$item['available_quantity']} available"
        ];
    }, $items);
    
    json_ok([
        'items' => $formattedItems,
        'total' => count($formattedItems)
    ]);
}
?>

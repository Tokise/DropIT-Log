<?php
require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();

try {
    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id > 0) {
            $stmt = $conn->prepare("SELECT * FROM products WHERE id = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) json_err('Product not found', 404);
            json_ok($row);
        } else {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;
            $q = trim($_GET['q'] ?? '');
            
            if ($q !== '') {
                // Check if barcode_image column exists for search queries too
                $columnsStmt = $conn->prepare("SHOW COLUMNS FROM products LIKE 'barcode_image'");
                $columnsStmt->execute();
                $hasImageColumn = $columnsStmt->rowCount() > 0;
                
                // Include product_image, barcode_image but limit results to avoid memory issues
                $stmt = $conn->prepare("
                    SELECT SQL_CALC_FOUND_ROWS 
                        p.id, p.sku, p.name, p.description, p.category_id, p.unit_price, 
                        p.weight_kg, p.dimensions_cm, p.reorder_point, p.reorder_quantity, 
                        p.lead_time_days, p.is_active, p.barcode, p.barcode_image, 
                        p.product_image, p.image_url, p.created_at, p.updated_at,
                        COALESCE(SUM(i.quantity), 0) as total_inventory,
                        COUNT(DISTINCT CASE WHEN i.warehouse_id IS NOT NULL THEN i.warehouse_id END) as warehouse_count,
                        c.name as category_name
                    FROM products p
                    LEFT JOIN inventory i ON p.id = i.product_id
                    LEFT JOIN categories c ON p.category_id = c.id
                    WHERE (p.name LIKE :q OR p.sku LIKE :q) AND p.is_active = 1
                    GROUP BY p.id
                    ORDER BY p.id DESC 
                    LIMIT :limit OFFSET :offset
                ");
                $like = "%$q%";
                $stmt->bindParam(':q', $like);
            } else {
                // Check if barcode_image column exists to avoid SQL errors
                $columnsStmt = $conn->prepare("SHOW COLUMNS FROM products LIKE 'barcode_image'");
                $columnsStmt->execute();
                $hasImageColumn = $columnsStmt->rowCount() > 0;
                
                // Include product_image, barcode_image, and inventory info
                $stmt = $conn->prepare("
                    SELECT 
                        p.id, p.sku, p.name, p.description, p.category_id, p.unit_price, 
                        p.weight_kg, p.dimensions_cm, p.reorder_point, p.reorder_quantity, 
                        p.lead_time_days, p.is_active, p.barcode, p.barcode_image, 
                        p.product_image, p.image_url, p.created_at, p.updated_at,
                        COALESCE(SUM(i.quantity), 0) as total_inventory,
                        COUNT(DISTINCT CASE WHEN i.warehouse_id IS NOT NULL THEN i.warehouse_id END) as warehouse_count,
                        c.name as category_name
                    FROM products p
                    LEFT JOIN inventory i ON p.id = i.product_id
                    LEFT JOIN categories c ON p.category_id = c.id
                    WHERE p.is_active = 1 
                    GROUP BY p.id
                    ORDER BY p.created_at DESC 
                    LIMIT :limit OFFSET :offset
                ");
            }
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total count
            if ($q !== '') {
                $countStmt = $conn->prepare("SELECT FOUND_ROWS() as total");
                $countStmt->execute();
                $total = $countStmt->fetchColumn();
            } else {
                $countStmt = $conn->prepare("SELECT COUNT(*) FROM products WHERE is_active = 1");
                $countStmt->execute();
                $total = $countStmt->fetchColumn();
            }
            
            json_ok(['items' => $rows, 'page' => $page, 'limit' => $limit, 'total' => $total]);
        }
    }

    if ($method === 'POST') {
        $data = read_json_body();
        require_params($data, ['sku','name','unit_price']);
        $stmt = $conn->prepare("INSERT INTO products (sku,name,description,category_id,unit_price,weight_kg,dimensions_cm,reorder_point,reorder_quantity,lead_time_days,is_active,barcode,image_url) VALUES (:sku,:name,:description,:category_id,:unit_price,:weight_kg,:dimensions_cm,:reorder_point,:reorder_quantity,:lead_time_days,:is_active,:barcode,:image_url)");
        $stmt->execute([
            ':sku' => $data['sku'],
            ':name' => $data['name'],
            ':description' => $data['description'] ?? null,
            ':category_id' => $data['category_id'] ?? null,
            ':unit_price' => $data['unit_price'],
            ':weight_kg' => $data['weight_kg'] ?? null,
            ':dimensions_cm' => $data['dimensions_cm'] ?? null,
            ':reorder_point' => $data['reorder_point'] ?? 10,
            ':reorder_quantity' => $data['reorder_quantity'] ?? 50,
            ':lead_time_days' => $data['lead_time_days'] ?? 7,
            ':is_active' => isset($data['is_active']) ? (int)(!!$data['is_active']) : 1,
            ':barcode' => $data['barcode'] ?? null,
            ':image_url' => $data['image_url'] ?? null,
        ]);
        $id = (int)$conn->lastInsertId();
        json_ok(['id' => $id], 201);
    }

    if ($method === 'PUT') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) json_err('id is required', 422);
        $data = read_json_body();
        $fields = ['sku','name','description','category_id','unit_price','weight_kg','dimensions_cm','reorder_point','reorder_quantity','lead_time_days','is_active','barcode','image_url'];
        $sets = [];
        $params = [':id' => $id];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = "$f = :$f";
                $params[":$f"] = $data[$f];
            }
        }
        if (empty($sets)) json_err('No fields to update', 422);
        $sql = 'UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        json_ok(['updated' => true]);
    }

    if ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) json_err('id is required', 422);
        
        // Check if product is used in purchase orders
        $checkStmt = $conn->prepare('SELECT COUNT(*) FROM purchase_order_items WHERE product_id = :id');
        $checkStmt->execute([':id' => $id]);
        $usageCount = (int)$checkStmt->fetchColumn();
        
        if ($usageCount > 0) {
            // Soft delete: mark as inactive instead of hard delete
            $stmt = $conn->prepare('UPDATE products SET is_active = 0 WHERE id = :id');
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            json_ok(['deleted' => true, 'soft_delete' => true, 'message' => 'Product marked as inactive (used in ' . $usageCount . ' purchase orders)']);
        } else {
            // Hard delete: product not used anywhere
            $stmt = $conn->prepare('DELETE FROM products WHERE id = :id');
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            json_ok(['deleted' => true, 'soft_delete' => false]);
        }
    }

    json_err('Method not allowed', 405);
} catch (Exception $e) {
    json_err($e->getMessage(), 400);
}

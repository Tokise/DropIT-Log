<?php
require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();
$auth = require_auth();

try {
    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        
        if ($id > 0) {
            // Get single archived product
            $stmt = $conn->prepare("SELECT * FROM archived_products WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$row) {
                json_err('Archived product not found', 404);
            }
            
            // Decode inventory data
            if ($row['inventory_data']) {
                $row['inventory_data'] = json_decode($row['inventory_data'], true);
            }
            
            json_ok($row);
        } else {
            // List archived products
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;
            $search = trim($_GET['q'] ?? '');
            $showRestored = isset($_GET['show_restored']) && $_GET['show_restored'] === 'true';
            
            $where = [];
            $params = [];
            
            if (!$showRestored) {
                $where[] = 'is_restored = 0';
            }
            
            if ($search !== '') {
                $where[] = '(name LIKE :search OR sku LIKE :search)';
                $params[':search'] = "%$search%";
            }
            
            $whereSql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);
            
            $stmt = $conn->prepare("
                SELECT SQL_CALC_FOUND_ROWS 
                    id, original_product_id, sku, name, category_name, unit_price,
                    barcode, deleted_by_name, deleted_at, deletion_reason,
                    is_restored, restored_at, restored_by, restored_product_id
                FROM archived_products 
                $whereSql
                ORDER BY deleted_at DESC 
                LIMIT :limit OFFSET :offset
            ");
            
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total = (int)$conn->query('SELECT FOUND_ROWS()')->fetchColumn();
            
            json_ok([
                'items' => $rows,
                'page' => $page,
                'limit' => $limit,
                'total' => $total
            ]);
        }
    }
    
    if ($method === 'POST') {
        // Restore archived product
        admin_block_mutations();
        
        $data = read_json_body();
        require_params($data, ['archive_id']);
        
        $archiveId = (int)$data['archive_id'];
        
        $conn->beginTransaction();
        
        try {
            // 1. Get archived product
            $stmt = $conn->prepare("SELECT * FROM archived_products WHERE id = :id AND is_restored = 0");
            $stmt->execute([':id' => $archiveId]);
            $archived = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$archived) {
                throw new Exception('Archived product not found or already restored');
            }
            
            // 2. Check if SKU already exists
            $stmt = $conn->prepare("SELECT id FROM products WHERE sku = :sku");
            $stmt->execute([':sku' => $archived['sku']]);
            if ($stmt->fetch()) {
                throw new Exception('Cannot restore: A product with SKU ' . $archived['sku'] . ' already exists');
            }
            
            // 3. Restore product
            $stmt = $conn->prepare("
                INSERT INTO products (
                    sku, name, description, category_id, unit_price, weight_kg,
                    dimensions_cm, reorder_point, reorder_quantity, lead_time_days,
                    barcode, barcode_image, product_image, image_url, is_active
                ) VALUES (
                    :sku, :name, :description, :category_id, :unit_price, :weight_kg,
                    :dimensions_cm, :reorder_point, :reorder_quantity, :lead_time_days,
                    :barcode, :barcode_image, :product_image, :image_url, 1
                )
            ");
            
            $stmt->execute([
                ':sku' => $archived['sku'],
                ':name' => $archived['name'],
                ':description' => $archived['description'],
                ':category_id' => $archived['category_id'],
                ':unit_price' => $archived['unit_price'],
                ':weight_kg' => $archived['weight_kg'],
                ':dimensions_cm' => $archived['dimensions_cm'],
                ':reorder_point' => $archived['reorder_point'],
                ':reorder_quantity' => $archived['reorder_quantity'],
                ':lead_time_days' => $archived['lead_time_days'],
                ':barcode' => $archived['barcode'],
                ':barcode_image' => $archived['barcode_image'],
                ':product_image' => $archived['product_image'],
                ':image_url' => $archived['image_url']
            ]);
            
            $newProductId = (int)$conn->lastInsertId();
            
            // 4. Restore inventory if requested
            $inventoryRestored = 0;
            if (isset($data['restore_inventory']) && $data['restore_inventory'] === true) {
                $inventoryData = json_decode($archived['inventory_data'], true);
                
                if ($inventoryData && is_array($inventoryData)) {
                    $invStmt = $conn->prepare("
                        INSERT INTO inventory (
                            product_id, warehouse_id, quantity, reserved_quantity,
                            location_code, last_restocked_at
                        ) VALUES (
                            :pid, :wid, :qty, :reserved, :location, NOW()
                        )
                    ");
                    
                    foreach ($inventoryData as $inv) {
                        $invStmt->execute([
                            ':pid' => $newProductId,
                            ':wid' => $inv['warehouse_id'],
                            ':qty' => $inv['quantity'],
                            ':reserved' => $inv['reserved_quantity'] ?? 0,
                            ':location' => $inv['location_code']
                        ]);
                        $inventoryRestored++;
                    }
                }
            }
            
            // 5. Update archive record
            $stmt = $conn->prepare("
                UPDATE archived_products 
                SET is_restored = 1, restored_at = NOW(), restored_by = :uid, restored_product_id = :pid
                WHERE id = :id
            ");
            $stmt->execute([
                ':uid' => $auth['id'],
                ':pid' => $newProductId,
                ':id' => $archiveId
            ]);
            
            // 6. Log the restoration
            log_audit('product', $newProductId, 'restore', $auth['id'], json_encode([
                'archive_id' => $archiveId,
                'product_name' => $archived['name'],
                'sku' => $archived['sku'],
                'inventory_restored' => $inventoryRestored
            ]));
            
            // 7. Notify users
            notify_module_users('sws', 'Product restored', 
                "Product '{$archived['name']}' (SKU: {$archived['sku']}) has been restored from archive", 
                'sws.php');
            
            $conn->commit();
            
            json_ok([
                'restored' => true,
                'product_id' => $newProductId,
                'product_name' => $archived['name'],
                'sku' => $archived['sku'],
                'inventory_restored' => $inventoryRestored
            ], 201);
            
        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    }
    
    json_err('Method not allowed', 405);
} catch (Exception $e) {
    json_err($e->getMessage(), 400);
}

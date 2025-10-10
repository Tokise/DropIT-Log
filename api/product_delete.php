<?php
require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();
$auth = require_auth();

try {
    if ($method === 'DELETE') {
        admin_block_mutations();
        
        $productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($productId <= 0) {
            json_err('Product ID is required', 422);
        }
        
        $data = read_json_body();
        $reason = $data['reason'] ?? 'No reason provided';
        
        // Start transaction
        $conn->beginTransaction();
        
        try {
            // 1. Get product data
            $stmt = $conn->prepare("
                SELECT p.*, c.name as category_name 
                FROM products p 
                LEFT JOIN categories c ON p.category_id = c.id 
                WHERE p.id = :id
            ");
            $stmt->execute([':id' => $productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$product) {
                throw new Exception('Product not found');
            }
            
            // 2. Get all inventory data for this product
            $stmt = $conn->prepare("
                SELECT i.*, w.name as warehouse_name, w.code as warehouse_code
                FROM inventory i
                JOIN warehouses w ON i.warehouse_id = w.id
                WHERE i.product_id = :pid
            ");
            $stmt->execute([':pid' => $productId]);
            $inventoryRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 3. Archive the product
            $stmt = $conn->prepare("
                INSERT INTO archived_products (
                    original_product_id, sku, name, description, category_id, category_name,
                    unit_price, weight_kg, dimensions_cm, reorder_point, reorder_quantity,
                    lead_time_days, barcode, barcode_image, product_image, image_url,
                    inventory_data, deleted_by, deleted_by_name, deletion_reason,
                    original_created_at, original_updated_at
                ) VALUES (
                    :original_id, :sku, :name, :description, :category_id, :category_name,
                    :unit_price, :weight_kg, :dimensions_cm, :reorder_point, :reorder_quantity,
                    :lead_time_days, :barcode, :barcode_image, :product_image, :image_url,
                    :inventory_data, :deleted_by, :deleted_by_name, :reason,
                    :created_at, :updated_at
                )
            ");
            
            $stmt->execute([
                ':original_id' => $product['id'],
                ':sku' => $product['sku'],
                ':name' => $product['name'],
                ':description' => $product['description'],
                ':category_id' => $product['category_id'],
                ':category_name' => $product['category_name'],
                ':unit_price' => $product['unit_price'],
                ':weight_kg' => $product['weight_kg'],
                ':dimensions_cm' => $product['dimensions_cm'],
                ':reorder_point' => $product['reorder_point'],
                ':reorder_quantity' => $product['reorder_quantity'],
                ':lead_time_days' => $product['lead_time_days'],
                ':barcode' => $product['barcode'],
                ':barcode_image' => $product['barcode_image'] ?? null,
                ':product_image' => $product['product_image'] ?? null,
                ':image_url' => $product['image_url'],
                ':inventory_data' => json_encode($inventoryRecords),
                ':deleted_by' => $auth['id'],
                ':deleted_by_name' => $auth['full_name'],
                ':reason' => $reason,
                ':created_at' => $product['created_at'],
                ':updated_at' => $product['updated_at']
            ]);
            
            $archiveId = (int)$conn->lastInsertId();
            
            // 4. Delete inventory transactions first (to avoid FK constraint)
            $stmt = $conn->prepare("DELETE FROM inventory_transactions WHERE product_id = :pid");
            $stmt->execute([':pid' => $productId]);
            $transactionsDeleted = $stmt->rowCount();
            
            // 5. Delete inventory records
            $stmt = $conn->prepare("DELETE FROM inventory WHERE product_id = :pid");
            $stmt->execute([':pid' => $productId]);
            $inventoryDeleted = $stmt->rowCount();
            
            // 6. Delete the product
            $stmt = $conn->prepare("DELETE FROM products WHERE id = :id");
            $stmt->execute([':id' => $productId]);
            
            // 7. Log the action
            log_audit('product', $productId, 'delete', $auth['id'], json_encode([
                'product_name' => $product['name'],
                'sku' => $product['sku'],
                'reason' => $reason,
                'archive_id' => $archiveId,
                'inventory_records_deleted' => $inventoryDeleted,
                'transactions_deleted' => $transactionsDeleted
            ]));
            
            // 8. Notify users
            notify_module_users('sws', 'Product deleted', 
                "Product '{$product['name']}' (SKU: {$product['sku']}) has been deleted and archived", 
                'sws.php');
            
            $conn->commit();
            
            json_ok([
                'deleted' => true,
                'archive_id' => $archiveId,
                'product_name' => $product['name'],
                'sku' => $product['sku'],
                'inventory_records_deleted' => $inventoryDeleted,
                'transactions_deleted' => $transactionsDeleted
            ]);
            
        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    }
    
    json_err('Method not allowed', 405);
} catch (Exception $e) {
    json_err($e->getMessage(), 400);
}

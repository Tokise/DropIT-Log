<?php
/**
 * Received Items API
 * Manages items received from POs before adding to inventory
 */

require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();
$auth = require_auth();

try {
    if ($method === 'GET') {
        // Get received items
        $status = $_GET['status'] ?? 'pending';
        $poId = $_GET['po_id'] ?? null;
        
        if ($poId) {
            // Get received items for specific PO
            $stmt = $conn->prepare("
                SELECT ri.*, 
                       po.po_number,
                       sp.product_name as supplier_product_name,
                       u.full_name as received_by_name,
                       p.name as inventory_product_name
                FROM received_items ri
                JOIN purchase_orders po ON ri.po_id = po.id
                JOIN supplier_products_catalog sp ON ri.supplier_product_id = sp.id
                LEFT JOIN users u ON ri.received_by = u.id
                LEFT JOIN products p ON ri.inventory_product_id = p.id
                WHERE ri.po_id = :po_id
                ORDER BY ri.received_date DESC
            ");
            $stmt->execute([':po_id' => $poId]);
        } else {
            // Get all received items by status
            $stmt = $conn->prepare("
                SELECT ri.*, 
                       po.po_number,
                       sp.product_name as supplier_product_name,
                       s.name as supplier_name,
                       u.full_name as received_by_name,
                       p.name as inventory_product_name
                FROM received_items ri
                JOIN purchase_orders po ON ri.po_id = po.id
                JOIN supplier_products_catalog sp ON ri.supplier_product_id = sp.id
                JOIN suppliers s ON po.supplier_id = s.id
                LEFT JOIN users u ON ri.received_by = u.id
                LEFT JOIN products p ON ri.inventory_product_id = p.id
                WHERE ri.status = :status
                ORDER BY ri.received_date DESC
            ");
            $stmt->execute([':status' => $status]);
        }
        
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        json_ok(['items' => $items, 'count' => count($items)]);
    }
    
    if ($method === 'POST') {
        // Add received item to inventory
        $data = read_json_body();
        require_params($data, ['id']);
        
        $conn->beginTransaction();
        
        try {
            // Get received item details
            $stmt = $conn->prepare("
                SELECT ri.*, sp.category, sp.unit_of_measure
                FROM received_items ri
                JOIN supplier_products_catalog sp ON ri.supplier_product_id = sp.id
                WHERE ri.id = :id AND ri.status = 'pending'
            ");
            $stmt->execute([':id' => $data['id']]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$item) {
                throw new Exception('Received item not found or already processed');
            }
            
            // Check if product already exists in inventory by product_code
            $stmt = $conn->prepare("SELECT id, quantity FROM products WHERE sku = :sku LIMIT 1");
            $stmt->execute([':sku' => $item['product_code']]);
            $existingProduct = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingProduct) {
                // Update existing product quantity
                $newQty = $existingProduct['quantity'] + $item['quantity_received'];
                $stmt = $conn->prepare("UPDATE products SET quantity = :qty, updated_at = NOW() WHERE id = :id");
                $stmt->execute([':qty' => $newQty, ':id' => $existingProduct['id']]);
                $productId = $existingProduct['id'];
            } else {
                // Create new product in inventory
                $stmt = $conn->prepare("
                    INSERT INTO products 
                    (name, sku, description, category, unit_of_measure, quantity, unit_price, reorder_point, is_active)
                    VALUES 
                    (:name, :sku, :description, :category, :unit_of_measure, :quantity, :unit_price, :reorder_point, 1)
                ");
                
                $stmt->execute([
                    ':name' => $item['product_name'],
                    ':sku' => $item['product_code'],
                    ':description' => $data['description'] ?? 'Added from PO ' . $item['po_number'],
                    ':category' => $item['category'] ?? 'General',
                    ':unit_of_measure' => $item['unit_of_measure'] ?? 'pcs',
                    ':quantity' => $item['quantity_received'],
                    ':unit_price' => $item['unit_price'],
                    ':reorder_point' => $data['reorder_point'] ?? 10
                ]);
                
                $productId = $conn->lastInsertId();
            }
            
            // Update received item status
            $stmt = $conn->prepare("
                UPDATE received_items 
                SET status = 'added_to_inventory', 
                    inventory_product_id = :product_id,
                    notes = :notes,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':product_id' => $productId,
                ':notes' => $data['notes'] ?? 'Added to inventory',
                ':id' => $data['id']
            ]);
            
            // Audit log
            audit_log($conn, 'received_item', $data['id'], 'add_to_inventory', $auth['id'], json_encode(['product_id' => $productId]));
            
            $conn->commit();
            json_ok(['message' => 'Item added to inventory successfully', 'product_id' => $productId]);
            
        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    }
    
    json_err('Method not allowed', 405);
} catch (Exception $e) {
    json_err($e->getMessage(), 400);
}

<?php
/**
 * Supplier Products API
 * Manages products offered by suppliers
 */

require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();

try {
    if ($method === 'GET') {
        // GET requests don't require auth for reading supplier products
        // Get supplier products
        $supplierId = $_GET['supplier_id'] ?? null;
        $id = $_GET['id'] ?? null;
        
        if ($id) {
            // Get single product
            $stmt = $conn->prepare("
                SELECT sp.*, s.name as supplier_name, s.code as supplier_code
                FROM supplier_products_catalog sp
                JOIN suppliers s ON sp.supplier_id = s.id
                WHERE sp.id = :id
            ");
            $stmt->execute([':id' => $id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$product) {
                json_err('Product not found', 404);
            }
            
            json_ok($product);
        }
        
        if ($supplierId) {
            // Get products for specific supplier
            $stmt = $conn->prepare("
                SELECT sp.*, s.name as supplier_name, s.code as supplier_code
                FROM supplier_products_catalog sp
                JOIN suppliers s ON sp.supplier_id = s.id
                WHERE sp.supplier_id = :sid AND sp.is_active = 1
                ORDER BY sp.product_name ASC
            ");
            $stmt->execute([':sid' => $supplierId]);
        } else {
            // Get all supplier products
            $stmt = $conn->query("
                SELECT sp.*, s.name as supplier_name, s.code as supplier_code
                FROM supplier_products_catalog sp
                JOIN suppliers s ON sp.supplier_id = s.id
                WHERE sp.is_active = 1
                ORDER BY s.name ASC, sp.product_name ASC
            ");
        }
        
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        json_ok(['items' => $products, 'count' => count($products)]);
    }
    
    if ($method === 'POST') {
        // Create new supplier product
        $auth = require_auth();
        $data = read_json_body();
        require_params($data, ['supplier_id', 'product_name', 'product_code', 'unit_price']);
        
        // Generate barcode if not provided
        $barcode = $data['barcode'] ?? null;
        if (!$barcode) {
            $barcode = generateEAN13('890'); // Philippines country code
        }
        
        // Check if barcode column exists
        try {
            $stmt = $conn->prepare("
                INSERT INTO supplier_products_catalog 
                (supplier_id, product_name, product_code, description, category, unit_of_measure, unit_price, currency, minimum_order_qty, lead_time_days, barcode, is_active)
                VALUES 
                (:supplier_id, :product_name, :product_code, :description, :category, :unit_of_measure, :unit_price, :currency, :minimum_order_qty, :lead_time_days, :barcode, :is_active)
            ");
            
            $stmt->execute([
                ':supplier_id' => $data['supplier_id'],
                ':product_name' => $data['product_name'],
                ':product_code' => $data['product_code'],
                ':description' => $data['description'] ?? null,
                ':category' => $data['category'] ?? null,
                ':unit_of_measure' => $data['unit_of_measure'] ?? 'pcs',
                ':unit_price' => $data['unit_price'],
                ':currency' => $data['currency'] ?? 'PHP',
                ':minimum_order_qty' => $data['minimum_order_qty'] ?? 1,
                ':lead_time_days' => $data['lead_time_days'] ?? 7,
                ':barcode' => $barcode,
                ':is_active' => $data['is_active'] ?? 1
            ]);
        } catch (PDOException $e) {
            // If barcode column doesn't exist, try without it
            if (strpos($e->getMessage(), 'barcode') !== false) {
                $stmt = $conn->prepare("
                    INSERT INTO supplier_products_catalog 
                    (supplier_id, product_name, product_code, description, category, unit_of_measure, unit_price, currency, minimum_order_qty, lead_time_days, is_active)
                    VALUES 
                    (:supplier_id, :product_name, :product_code, :description, :category, :unit_of_measure, :unit_price, :currency, :minimum_order_qty, :lead_time_days, :is_active)
                ");
                
                $stmt->execute([
                    ':supplier_id' => $data['supplier_id'],
                    ':product_name' => $data['product_name'],
                    ':product_code' => $data['product_code'],
                    ':description' => $data['description'] ?? null,
                    ':category' => $data['category'] ?? null,
                    ':unit_of_measure' => $data['unit_of_measure'] ?? 'pcs',
                    ':unit_price' => $data['unit_price'],
                    ':currency' => $data['currency'] ?? 'PHP',
                    ':minimum_order_qty' => $data['minimum_order_qty'] ?? 1,
                    ':lead_time_days' => $data['lead_time_days'] ?? 7,
                    ':is_active' => $data['is_active'] ?? 1
                ]);
                
                $barcode = null; // No barcode if column doesn't exist
            } else {
                throw $e; // Re-throw if it's a different error
            }
        }
        
        $productId = $conn->lastInsertId();
        
        // Audit log
        log_audit('supplier_product', $productId, 'create', $auth['id'], json_encode($data));
        
        json_ok([
            'id' => $productId, 
            'barcode' => $barcode,
            'message' => $barcode ? 'Supplier product created successfully with barcode' : 'Supplier product created (run add_barcode_column.sql to enable barcodes)'
        ]);
    }
    
    // Helper function to generate EAN-13 barcode
    function generateEAN13($prefix = '890') {
        $prefix = str_pad($prefix, 3, '0', STR_PAD_LEFT);
        $randomDigits = '';
        for ($i = 0; $i < 9; $i++) {
            $randomDigits .= rand(0, 9);
        }
        $code = $prefix . $randomDigits;
        $checkDigit = calculateEAN13CheckDigit($code);
        return $code . $checkDigit;
    }
    
    function calculateEAN13CheckDigit($code) {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int)$code[$i];
            $sum += ($i % 2 === 0) ? $digit : $digit * 3;
        }
        return (10 - ($sum % 10)) % 10;
    }
    
    if ($method === 'PUT') {
        // Update supplier product
        $auth = require_auth();
        $data = read_json_body();
        require_params($data, ['id']);
        
        $updates = [];
        $params = [':id' => $data['id']];
        
        $allowedFields = ['product_name', 'product_code', 'description', 'category', 'unit_of_measure', 'unit_price', 'currency', 'minimum_order_qty', 'lead_time_days', 'is_active'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }
        
        if (empty($updates)) {
            json_err('No fields to update', 400);
        }
        
        $sql = "UPDATE supplier_products_catalog SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        
        // Audit log
        log_audit('supplier_product', $data['id'], 'update', $auth['id'], json_encode($data));
        
        json_ok(['message' => 'Supplier product updated successfully']);
    }
    
    if ($method === 'DELETE') {
        // Delete (soft delete) supplier product
        $auth = require_auth();
        $data = read_json_body();
        require_params($data, ['id']);
        
        $stmt = $conn->prepare("UPDATE supplier_products_catalog SET is_active = 0 WHERE id = :id");
        $stmt->execute([':id' => $data['id']]);
        
        // Audit log
        log_audit('supplier_product', $data['id'], 'delete', $auth['id']);
        
        json_ok(['message' => 'Supplier product deleted successfully']);
    }
    
    json_err('Method not allowed', 405);
} catch (Exception $e) {
    json_err($e->getMessage(), 400);
}

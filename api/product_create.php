<?php
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../services/ProductCodeGenerator.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();
$auth = require_auth();
$codeGenerator = new ProductCodeGenerator($conn);

try {
    if ($method === 'POST') {
        admin_block_mutations();
        $data = read_json_body();
        require_params($data, ['name', 'unit_price']);
        
        // Generate SKU, barcode, and barcode image automatically
        $codes = $codeGenerator->generateCodesWithImage();
        $sku = $data['sku'] ?? $codes['sku'];
        $barcode = $data['barcode'] ?? $codes['barcode'];
        $barcodeImage = $codes['barcode_image'];
        
        // If custom SKU provided, check if it exists
        if (isset($data['sku']) && !empty($data['sku'])) {
            $stmt = $conn->prepare("SELECT id FROM products WHERE sku = :sku");
            $stmt->execute([':sku' => $data['sku']]);
            if ($stmt->fetch()) {
                json_err('SKU already exists', 422);
            }
            $sku = $data['sku'];
        }
        
        // If custom barcode provided, check if it exists and generate image
        if (isset($data['barcode']) && !empty($data['barcode'])) {
            $stmt = $conn->prepare("SELECT id FROM products WHERE barcode = :barcode");
            $stmt->execute([':barcode' => $data['barcode']]);
            if ($stmt->fetch()) {
                json_err('Barcode already exists', 422);
            }
            $barcode = $data['barcode'];
            // Generate image for custom barcode
            require_once __DIR__ . '/../services/BarcodeImageGenerator.php';
            $imageGenerator = new BarcodeImageGenerator();
            $barcodeImage = $imageGenerator->generateBarcodeDataURL($barcode);
        }
        
        // Check if barcode_image and product_image columns exist
        $stmt = $conn->prepare("SHOW COLUMNS FROM products LIKE 'barcode_image'");
        $stmt->execute();
        $hasImageColumn = $stmt->rowCount() > 0;
        
        $stmt = $conn->prepare("SHOW COLUMNS FROM products LIKE 'product_image'");
        $stmt->execute();
        $hasProductImageColumn = $stmt->rowCount() > 0;
        
        // Build dynamic SQL based on available columns
        $columns = ['sku', 'name', 'description', 'category_id', 'unit_price', 'weight_kg', 'dimensions_cm', 'reorder_point', 'reorder_quantity', 'lead_time_days', 'is_active', 'barcode', 'image_url'];
        $placeholders = [':sku', ':name', ':description', ':category_id', ':unit_price', ':weight_kg', ':dimensions_cm', ':reorder_point', ':reorder_quantity', ':lead_time_days', ':is_active', ':barcode', ':image_url'];
        
        if ($hasImageColumn) {
            $columns[] = 'barcode_image';
            $placeholders[] = ':barcode_image';
        }
        
        if ($hasProductImageColumn) {
            $columns[] = 'product_image';
            $placeholders[] = ':product_image';
        }
        
        $sql = "INSERT INTO products (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $conn->prepare($sql);
        
        $executeParams = [
            ':sku' => $sku,
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
            ':barcode' => $barcode,
            ':image_url' => $data['image_url'] ?? null,
        ];
        
        if ($hasImageColumn) {
            $executeParams[':barcode_image'] = $barcodeImage;
        }
        
        if ($hasProductImageColumn) {
            $executeParams[':product_image'] = $data['product_image'] ?? null;
        }
        
        $stmt->execute($executeParams);
        
        $productId = (int)$conn->lastInsertId();
        
        // Log product creation details
        error_log("Product created: ID=$productId, warehouse_id=" . ($data['warehouse_id'] ?? 'null') . ", initial_quantity=" . ($data['initial_quantity'] ?? '0'));
        
        // If warehouse_id and initial_quantity provided, add to inventory
        if (isset($data['warehouse_id']) && !empty($data['warehouse_id']) && isset($data['initial_quantity']) && $data['initial_quantity'] > 0) {
            error_log("Creating inventory record for product $productId");
            
            $stmt = $conn->prepare("INSERT INTO inventory (product_id, warehouse_id, quantity, location_code, last_restocked_at) VALUES (:pid, :wid, :qty, :location, NOW())");
            $stmt->execute([
                ':pid' => $productId,
                ':wid' => $data['warehouse_id'],
                ':qty' => max(0, (int)$data['initial_quantity']),
                ':location' => $data['location_code'] ?? null
            ]);
            
            error_log("Inventory record created successfully");
            
            // Log inventory transaction
            $stmt = $conn->prepare("INSERT INTO inventory_transactions (product_id, warehouse_id, transaction_type, quantity, reference_type, notes, performed_by) VALUES (:pid, :wid, 'receipt', :qty, 'manual_entry', :notes, :uid)");
            $stmt->execute([
                ':pid' => $productId,
                ':wid' => $data['warehouse_id'],
                ':qty' => (int)$data['initial_quantity'],
                ':notes' => "Initial stock for new product: {$data['name']}",
                ':uid' => $auth['id']
            ]);
        } else {
            error_log("Skipping inventory creation - warehouse_id: " . ($data['warehouse_id'] ?? 'null') . ", quantity: " . ($data['initial_quantity'] ?? '0'));
        }
        
        log_audit('product', $productId, 'create', $auth['id'], json_encode($data));
        notify_module_users('sws', 'Product created', "New product '{$data['name']}' has been added", 'sws.php');
        
        json_ok(['id' => $productId, 'sku' => $sku, 'barcode' => $barcode], 201);
    }
    
    json_err('Method not allowed', 405);
} catch (Exception $e) {
    json_err($e->getMessage(), 400);
}

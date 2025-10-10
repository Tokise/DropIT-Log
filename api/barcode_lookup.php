<?php
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../services/BarcodeService.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();
$auth = require_auth();
$barcodeService = new BarcodeService();

try {
    if ($method === 'GET') {
        $barcode = trim($_GET['barcode'] ?? '');
        if (empty($barcode)) json_err('barcode parameter is required', 422);
        
        // Validate barcode format
        $validation = $barcodeService->validateBarcode($barcode);
        if (!$validation['valid']) {
            json_err($validation['error'], 422);
        }
        $barcode = $validation['barcode'];
        
        // Look up product by barcode in local database first
        $stmt = $conn->prepare("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.barcode = :barcode AND p.is_active = 1");
        $stmt->execute([':barcode' => $barcode]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($product) {
            // Get inventory levels across warehouses
            $stmt = $conn->prepare("SELECT i.*, w.name as warehouse_name, w.code as warehouse_code FROM inventory i JOIN warehouses w ON i.warehouse_id = w.id WHERE i.product_id = :pid");
            $stmt->execute([':pid' => $product['id']]);
            $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            json_ok([
                'found' => true,
                'product' => $product,
                'inventory' => $inventory,
                'barcode' => $barcode,
                'source' => 'local'
            ]);
        }
        
        // Try external barcode API lookup
        $externalResult = $barcodeService->lookupBarcode($barcode);
        
        if ($externalResult['success']) {
            json_ok([
                'found' => true,
                'external_product' => $externalResult['product'],
                'inventory' => [],
                'barcode' => $barcode,
                'source' => $externalResult['source'],
                'can_create' => true
            ]);
        } else {
            json_ok([
                'found' => false, 
                'barcode' => $barcode,
                'error' => $externalResult['error'] ?? 'Product not found',
                'can_create' => true
            ]);
        }
    }
    
    if ($method === 'POST') {
        // Quick add product via barcode scan
        $data = read_json_body();
        require_params($data, ['barcode', 'name', 'unit_price']);
        
        // Use the provided barcode, generate SKU automatically
        $codeGenerator = new ProductCodeGenerator($conn);
        $sku = $data['sku'] ?? $codeGenerator->generateSKUFromName($data['name']);
        
        // Generate barcode image for the scanned barcode
        require_once __DIR__ . '/../services/BarcodeImageGenerator.php';
        $imageGenerator = new BarcodeImageGenerator();
        $barcodeImage = $imageGenerator->generateBarcodeDataURL($data['barcode']);
        
        // Check if barcode_image column exists
        $stmt = $conn->prepare("SHOW COLUMNS FROM products LIKE 'barcode_image'");
        $stmt->execute();
        $hasImageColumn = $stmt->rowCount() > 0;
        
        if ($hasImageColumn) {
            $stmt = $conn->prepare("INSERT INTO products (sku, name, description, unit_price, barcode, barcode_image, is_active) VALUES (:sku, :name, :description, :unit_price, :barcode, :barcode_image, 1)");
        } else {
            $stmt = $conn->prepare("INSERT INTO products (sku, name, description, unit_price, barcode, is_active) VALUES (:sku, :name, :description, :unit_price, :barcode, 1)");
        }
        $executeParams = [
            ':sku' => $sku,
            ':name' => $data['name'],
            ':description' => $data['description'] ?? "Product added via barcode scan: {$data['barcode']}",
            ':unit_price' => $data['unit_price'],
            ':barcode' => $data['barcode']
        ];
        
        if ($hasImageColumn) {
            $executeParams[':barcode_image'] = $barcodeImage;
        }
        
        $stmt->execute($executeParams);
        
        $productId = (int)$conn->lastInsertId();
        
        // If warehouse_id and initial_quantity provided, add to inventory
        if (isset($data['warehouse_id']) && isset($data['initial_quantity'])) {
            $stmt = $conn->prepare("INSERT INTO inventory (product_id, warehouse_id, quantity, last_restocked_at) VALUES (:pid, :wid, :qty, NOW())");
            $stmt->execute([
                ':pid' => $productId,
                ':wid' => $data['warehouse_id'],
                ':qty' => max(0, (int)$data['initial_quantity'])
            ]);
            
            // Log inventory transaction
            $stmt = $conn->prepare("INSERT INTO inventory_transactions (product_id, warehouse_id, transaction_type, quantity, reference_type, notes, performed_by) VALUES (:pid, :wid, 'receipt', :qty, 'barcode_scan', :notes, :uid)");
            $stmt->execute([
                ':pid' => $productId,
                ':wid' => $data['warehouse_id'],
                ':qty' => (int)$data['initial_quantity'],
                ':notes' => "Initial stock via barcode scan: {$data['barcode']}",
                ':uid' => $auth['id']
            ]);
        }
        
        log_audit('product', $productId, 'create_via_barcode', $auth['id'], json_encode($data));
        notify_module_users('sws', 'Product added via barcode', "New product '{$data['name']}' added via barcode scan", 'sws.php');
        
        json_ok(['id' => $productId, 'sku' => $sku, 'barcode' => $data['barcode']], 201);
    }
    
    json_err('Method not allowed', 405);
} catch (Exception $e) {
    json_err($e->getMessage(), 400);
}

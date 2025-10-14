<?php
/**
 * Product Actions API
 * Handle barcode generation, inventory management, and AI-powered actions
 */

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../services/BarcodeService.php';
require_once __DIR__ . '/../services/AIWarehouseService.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();
$auth = require_auth();

try {
    if ($method === 'POST') {
        $data = read_json_body();
        $action = $data['action'] ?? '';
        
        switch ($action) {
            case 'generate_barcode':
                require_params($data, ['product_id']);
                $productId = (int)$data['product_id'];
                
                // Get product details
                $stmt = $conn->prepare("SELECT id, sku, name, barcode FROM products WHERE id = :id");
                $stmt->execute([':id' => $productId]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$product) {
                    json_err('Product not found', 404);
                }
                
                if (!empty($product['barcode'])) {
                    json_err('Product already has a barcode: ' . $product['barcode'], 400);
                }
                
                $barcodeService = new BarcodeService();
                $barcode = $barcodeService->generateBarcode($productId);
                
                // Generate barcode image
                $imageResult = $barcodeService->generateBarcodeImage($barcode);
                
                // Update product with barcode
                $stmt = $conn->prepare("
                    UPDATE products 
                    SET barcode = :barcode, 
                        barcode_image = :barcode_image,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':barcode' => $barcode,
                    ':barcode_image' => $imageResult['success'] ? $imageResult['image_data'] : null,
                    ':id' => $productId
                ]);
                
                json_ok([
                    'success' => true,
                    'barcode' => $barcode,
                    'barcode_image' => $imageResult['success'] ? $imageResult['image_data'] : null,
                    'message' => 'Barcode generated successfully'
                ]);
                break;
                
            case 'move_to_inventory':
                require_params($data, ['product_id', 'quantity', 'warehouse_id']);
                $productId = (int)$data['product_id'];
                $quantity = (int)$data['quantity'];
                $warehouseId = (int)$data['warehouse_id'];
                $useAI = isset($data['use_ai']) ? (bool)$data['use_ai'] : true;
                
                $conn->beginTransaction();
                
                try {
                    // Check if product exists
                    $stmt = $conn->prepare("SELECT id, name FROM products WHERE id = :id");
                    $stmt->execute([':id' => $productId]);
                    $product = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$product) {
                        throw new Exception('Product not found');
                    }
                    
                    $locationId = null;
                    if ($useAI) {
                        // Use AI to suggest optimal location
                        $aiService = new AIWarehouseService($conn);
                        $aiResult = $aiService->suggestOptimalLocation($productId, $quantity, $warehouseId);
                        
                        if ($aiResult['success'] && isset($aiResult['suggestion']['bin_id'])) {
                            $locationId = $aiResult['suggestion']['bin_id'];
                        }
                    }
                    
                    // Update main inventory
                    $stmt = $conn->prepare("
                        INSERT INTO inventory (product_id, warehouse_id, quantity, updated_at)
                        VALUES (:product_id, :warehouse_id, :quantity, NOW())
                        ON DUPLICATE KEY UPDATE 
                            quantity = quantity + :quantity,
                            updated_at = NOW()
                    ");
                    $stmt->execute([
                        ':product_id' => $productId,
                        ':warehouse_id' => $warehouseId,
                        ':quantity' => $quantity
                    ]);
                    
                    // Assign to location if AI suggested
                    if ($locationId) {
                        $stmt = $conn->prepare("
                            INSERT INTO inventory_locations
                            (product_id, bin_id, quantity, created_at, updated_at)
                            VALUES (:product_id, :location_id, :quantity, NOW(), NOW())
                            ON DUPLICATE KEY UPDATE
                                quantity = quantity + :quantity,
                                updated_at = NOW()
                        ");
                        $stmt->execute([
                            ':product_id' => $productId,
                            ':location_id' => $locationId,
                            ':quantity' => $quantity
                        ]);
                    } else {
                        // If no AI location, try to assign to first available bin in storage zone
                        $stmt = $conn->prepare("
                            SELECT wb.id 
                            FROM warehouse_bins wb
                            JOIN warehouse_racks wr ON wb.rack_id = wr.id
                            JOIN warehouse_aisles wa ON wr.aisle_id = wa.id
                            JOIN warehouse_zones wz ON wa.zone_id = wz.id
                            WHERE wz.warehouse_id = :warehouse_id 
                                AND wz.zone_type = 'storage'
                                AND wb.is_active = 1
                                AND (wb.current_units + :quantity) <= wb.capacity_units
                            ORDER BY wb.current_units ASC
                            LIMIT 1
                        ");
                        $stmt->execute([
                            ':warehouse_id' => $warehouseId,
                            ':quantity' => $quantity
                        ]);
                        $fallbackBinId = $stmt->fetchColumn();
                        
                        if ($fallbackBinId) {
                            $stmt = $conn->prepare("
                                INSERT INTO inventory_locations
                                (product_id, bin_id, quantity, created_at, updated_at)
                                VALUES (:product_id, :bin_id, :quantity, NOW(), NOW())
                                ON DUPLICATE KEY UPDATE
                                    quantity = quantity + :quantity,
                                    updated_at = NOW()
                            ");
                            $stmt->execute([
                                ':product_id' => $productId,
                                ':bin_id' => $fallbackBinId,
                                ':quantity' => $quantity
                            ]);
                            
                            // Update bin current units
                            $stmt = $conn->prepare("
                                UPDATE warehouse_bins 
                                SET current_units = current_units + :quantity,
                                    updated_at = NOW()
                                WHERE id = :bin_id
                            ");
                            $stmt->execute([
                                ':quantity' => $quantity,
                                ':bin_id' => $fallbackBinId
                            ]);
                            
                            $locationId = $fallbackBinId;
                        }
                    }
                    
                    // Log transaction
                    $stmt = $conn->prepare("
                        INSERT INTO inventory_transactions
                        (product_id, warehouse_id, transaction_type, quantity,
                         reference_type, reference_id, notes, performed_by)
                        VALUES (:product_id, :warehouse_id, 'receipt', :quantity,
                                'manual_entry', :product_id, :notes, :user_id)
                    ");
                    $notes = "Manual inventory addition" . ($locationId ? " - AI assigned to location ID: $locationId" : "");
                    $stmt->execute([
                        ':product_id' => $productId,
                        ':warehouse_id' => $warehouseId,
                        ':quantity' => $quantity,
                        ':notes' => $notes,
                        ':user_id' => $auth['id']
                    ]);
                    
                    $conn->commit();
                    
                    json_ok([
                        'success' => true,
                        'message' => 'Product moved to inventory successfully',
                        'ai_location' => $locationId ? "AI assigned to location ID: $locationId" : null,
                        'quantity_added' => $quantity
                    ]);
                    
                } catch (Exception $e) {
                    $conn->rollBack();
                    throw $e;
                }
                break;
                
            case 'ai_analyze_product':
                require_params($data, ['product_id']);
                $productId = (int)$data['product_id'];
                
                // Get product details
                $stmt = $conn->prepare("
                    SELECT p.*, 
                           COALESCE(SUM(i.quantity), 0) as total_inventory,
                           COUNT(DISTINCT i.warehouse_id) as warehouse_count
                    FROM products p
                    LEFT JOIN inventory i ON p.id = i.product_id
                    WHERE p.id = :id
                    GROUP BY p.id
                ");
                $stmt->execute([':id' => $productId]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$product) {
                    json_err('Product not found', 404);
                }
                
                $aiService = new AIWarehouseService($conn);
                
                // Get AI analysis
                $analysis = [];
                
                // Demand prediction
                $demandResult = $aiService->predictDemand($productId, 30);
                if ($demandResult['success']) {
                    $analysis['demand_forecast'] = $demandResult['prediction'];
                    $analysis['days_of_stock'] = $demandResult['days_of_stock'];
                }
                
                // Barcode context if available
                if (!empty($product['barcode'])) {
                    $contextResult = $aiService->analyzeBarcodeContext($product['barcode'], null, 'inventory_analysis');
                    if ($contextResult['success']) {
                        $analysis['barcode_context'] = $contextResult['context'];
                    }
                }
                
                json_ok([
                    'success' => true,
                    'product' => $product,
                    'ai_analysis' => $analysis
                ]);
                break;
                
            default:
                json_err('Invalid action', 400);
        }
    }
    
    json_err('Method not allowed', 405);
    
} catch (Exception $e) {
    json_err($e->getMessage(), 500);
}

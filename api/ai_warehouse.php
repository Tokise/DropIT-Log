<?php
/**
 * AI Warehouse Operations API
 * Endpoints for AI-powered warehouse automation
 */

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../services/AIWarehouseService.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();
$auth = require_auth();

$action = $_GET['action'] ?? '';

try {
    $aiService = new AIWarehouseService($conn);
    
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        switch ($action) {
            case 'suggest_location':
                // Suggest optimal storage location for a product
                $productId = $input['product_id'] ?? null;
                $quantity = $input['quantity'] ?? 1;
                $warehouseId = $input['warehouse_id'] ?? 1;
                
                if (!$productId) {
                    json_err('product_id is required', 422);
                }
                
                $result = $aiService->suggestOptimalLocation($productId, $quantity, $warehouseId);
                json_ok($result);
                break;
                
            case 'predict_demand':
                // Predict inventory demand
                $productId = $input['product_id'] ?? null;
                $days = $input['days'] ?? 30;
                
                if (!$productId) {
                    json_err('product_id is required', 422);
                }
                
                $result = $aiService->predictDemand($productId, $days);
                json_ok($result);
                break;
                
            case 'optimize_picking':
                // Generate optimal picking route
                $orderItems = $input['items'] ?? [];
                
                if (empty($orderItems)) {
                    json_err('items array is required', 422);
                }
                
                $result = $aiService->generatePickingRoute($orderItems);
                json_ok($result);
                break;
                
            case 'detect_anomalies':
                // Detect inventory anomalies
                $warehouseId = $input['warehouse_id'] ?? null;
                
                $result = $aiService->detectAnomalies($warehouseId);
                json_ok($result);
                break;
                
            case 'analyze_barcode':
                // Analyze barcode scan context
                $barcode = $input['barcode'] ?? null;
                $scanLocation = $input['scan_location'] ?? null;
                $scanPurpose = $input['scan_purpose'] ?? 'general';
                
                if (!$barcode) {
                    json_err('barcode is required', 422);
                }
                
                $result = $aiService->analyzeBarcodeContext($barcode, $scanLocation, $scanPurpose);
                json_ok($result);
                break;
                
            case 'auto_assign_location':
                // Automatically assign product to AI-suggested location
                $productId = $input['product_id'] ?? null;
                $quantity = $input['quantity'] ?? 1;
                $warehouseId = $input['warehouse_id'] ?? 1;
                
                if (!$productId) {
                    json_err('product_id is required', 422);
                }
                
                // Get AI suggestion
                $suggestion = $aiService->suggestOptimalLocation($productId, $quantity, $warehouseId);
                
                if (!$suggestion['success'] || !$suggestion['suggestion']['bin_id']) {
                    json_err($suggestion['message'] ?? 'No suitable location found', 400);
                }
                
                $binId = $suggestion['suggestion']['bin_id'];
                
                // Check if already exists
                $stmt = $conn->prepare("
                    SELECT id, quantity FROM inventory_locations 
                    WHERE product_id = :product_id AND bin_id = :bin_id
                ");
                $stmt->execute([
                    ':product_id' => $productId,
                    ':bin_id' => $binId
                ]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existing) {
                    // Update existing
                    $stmt = $conn->prepare("
                        UPDATE inventory_locations 
                        SET quantity = quantity + :quantity,
                            last_moved_at = NOW()
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':quantity' => $quantity,
                        ':id' => $existing['id']
                    ]);
                } else {
                    // Insert new
                    $stmt = $conn->prepare("
                        INSERT INTO inventory_locations 
                        (product_id, warehouse_id, bin_id, quantity, last_moved_at)
                        VALUES (:product_id, :warehouse_id, :bin_id, :quantity, NOW())
                    ");
                    $stmt->execute([
                        ':product_id' => $productId,
                        ':warehouse_id' => $warehouseId,
                        ':bin_id' => $binId,
                        ':quantity' => $quantity
                    ]);
                }
                
                // Update bin current_units
                $stmt = $conn->prepare("
                    UPDATE warehouse_bins 
                    SET current_units = current_units + :quantity
                    WHERE id = :bin_id
                ");
                $stmt->execute([
                    ':quantity' => $quantity,
                    ':bin_id' => $binId
                ]);
                
                // Log transaction
                $stmt = $conn->prepare("
                    INSERT INTO inventory_transactions 
                    (product_id, warehouse_id, transaction_type, quantity, to_location_id, 
                     notes, performed_by, created_at)
                    VALUES (:product_id, :warehouse_id, 'receipt', :quantity, :bin_id,
                            :notes, :user_id, NOW())
                ");
                $stmt->execute([
                    ':product_id' => $productId,
                    ':warehouse_id' => $warehouseId,
                    ':quantity' => $quantity,
                    ':bin_id' => $binId,
                    ':notes' => 'AI-assigned location: ' . $suggestion['suggestion']['reasoning'],
                    ':user_id' => $auth['id']
                ]);
                
                json_ok([
                    'success' => true,
                    'message' => 'Product assigned to location',
                    'location' => $suggestion['suggestion'],
                    'ai_confidence' => $suggestion['suggestion']['confidence']
                ]);
                break;
                
            default:
                json_err('Invalid action', 400);
        }
        
    } else if ($method === 'GET') {
        switch ($action) {
            case 'health':
                // Check AI service health
                $apiKey = (new AIConfig())->getApiKey();
                json_ok([
                    'status' => 'ok',
                    'ai_configured' => !empty($apiKey),
                    'provider' => 'gemini'
                ]);
                break;
                
            default:
                json_err('Invalid action', 400);
        }
        
    } else {
        json_err('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    json_err($e->getMessage(), 500);
}

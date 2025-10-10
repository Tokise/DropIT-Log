<?php
// Simple AI API endpoint
header('Content-Type: application/json');

require_once __DIR__ . '/../services/AIService.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $ai = new AIService();

    switch ($action) {
        case 'forecast':
            // Params: product_id, days
            $productId = (int)($_GET['product_id'] ?? $_POST['product_id'] ?? 0);
            $days = (int)($_GET['days'] ?? $_POST['days'] ?? 30);
            if ($productId <= 0) throw new Exception('product_id is required');
            $result = $ai->forecastDemand($productId, $days);
            echo json_encode(['ok' => true, 'data' => $result]);
            break;

        case 'reorder_recommendations':
            // Param: warehouse_id (optional)
            $warehouseId = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : (isset($_POST['warehouse_id']) ? (int)$_POST['warehouse_id'] : null);
            $result = $ai->generateReorderRecommendation($warehouseId);
            echo json_encode(['ok' => true, 'data' => $result]);
            break;

        case 'optimize_route':
            // Param: order_id
            $orderId = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
            if ($orderId <= 0) throw new Exception('order_id is required');
            $result = $ai->optimizePickingRoute($orderId);
            echo json_encode(['ok' => true, 'data' => $result]);
            break;

        case 'detect_anomalies':
            // Params: type (inventory|all), warehouse_id (optional)
            $type = $_GET['type'] ?? $_POST['type'] ?? 'inventory';
            $warehouseId = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : (isset($_POST['warehouse_id']) ? (int)$_POST['warehouse_id'] : null);
            $result = $ai->detectAnomalies($type, $warehouseId);
            echo json_encode(['ok' => true, 'data' => $result]);
            break;

        case 'predict_delivery':
            // Params: supplier_id, items
            $data = json_decode(file_get_contents('php://input'), true);
            $supplierId = (int)($data['supplier_id'] ?? 0);
            $items = $data['items'] ?? [];
            if ($supplierId <= 0) throw new Exception('supplier_id is required');
            $predictedDate = $ai->predictDeliveryDate($supplierId, $items);
            echo json_encode(['ok' => true, 'data' => ['predicted_date' => $predictedDate]]);
            break;

        case 'supplier_analysis':
            // Param: supplier_id
            $supplierId = (int)($_GET['supplier_id'] ?? $_POST['supplier_id'] ?? 0);
            if ($supplierId <= 0) throw new Exception('supplier_id is required');
            $result = $ai->analyzeSupplierPerformance($supplierId);
            echo json_encode(['ok' => true, 'data' => $result]);
            break;

        default:
            echo json_encode([
                'ok' => true,
                'message' => 'AI API is live. Use ?action=forecast|reorder_recommendations|optimize_route|detect_anomalies|supplier_analysis'
            ]);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

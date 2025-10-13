<?php
/**
 * Supplier Performance API
 * Calculate and retrieve supplier performance metrics
 */
require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();
$auth = require_auth();

try {
    if ($method === 'GET') {
        $supplierId = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;
        
        if ($supplierId > 0) {
            // Get performance for specific supplier
            $stmt = $conn->prepare("SELECT * FROM supplier_performance WHERE supplier_id = :id");
            $stmt->execute([':id' => $supplierId]);
            $performance = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$performance) {
                // Calculate and create performance record
                $performance = calculateSupplierPerformance($conn, $supplierId);
            }
            
            // Get supplier details
            $stmt = $conn->prepare("SELECT * FROM suppliers WHERE id = :id");
            $stmt->execute([':id' => $supplierId]);
            $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
            
            json_ok([
                'supplier' => $supplier,
                'performance' => $performance
            ]);
        } else {
            // Get all supplier performances
            $stmt = $conn->query("
                SELECT sp.*, s.name as supplier_name, s.code as supplier_code
                FROM supplier_performance sp
                JOIN suppliers s ON sp.supplier_id = s.id
                WHERE s.is_active = 1
                ORDER BY sp.overall_rating DESC
            ");
            $performances = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            json_ok(['items' => $performances, 'count' => count($performances)]);
        }
    }
    
    if ($method === 'POST') {
        // Recalculate performance for a supplier
        $data = read_json_body();
        require_params($data, ['supplier_id']);
        
        $supplierId = (int)$data['supplier_id'];
        $performance = calculateSupplierPerformance($conn, $supplierId);
        
        json_ok(['performance' => $performance, 'message' => 'Performance recalculated successfully']);
    }
    
} catch (Exception $e) {
    json_err($e->getMessage(), 500);
}

/**
 * Calculate supplier performance metrics
 */
function calculateSupplierPerformance($conn, $supplierId) {
    // Get all POs for this supplier
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_pos,
            SUM(CASE WHEN actual_delivery_date <= expected_delivery_date THEN 1 ELSE 0 END) as on_time_deliveries,
            SUM(CASE WHEN actual_delivery_date > expected_delivery_date THEN 1 ELSE 0 END) as late_deliveries,
            AVG(TIMESTAMPDIFF(HOUR, created_at, supplier_acknowledged_at)) as avg_response_time
        FROM purchase_orders
        WHERE supplier_id = :supplier_id 
        AND status IN ('received', 'partially_received')
    ");
    $stmt->execute([':supplier_id' => $supplierId]);
    $deliveryMetrics = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get quality metrics
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_items,
            SUM(CASE WHEN qc_status = 'passed' THEN 1 ELSE 0 END) as passed_items,
            SUM(CASE WHEN qc_status = 'failed' THEN 1 ELSE 0 END) as failed_items
        FROM receiving_queue rq
        JOIN po_items poi ON rq.po_item_id = poi.id
        JOIN purchase_orders po ON poi.po_id = po.id
        WHERE po.supplier_id = :supplier_id
        AND rq.qc_status IN ('passed', 'failed')
    ");
    $stmt->execute([':supplier_id' => $supplierId]);
    $qualityMetrics = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate overall rating (weighted average)
    $onTimeRate = $deliveryMetrics['total_pos'] > 0 
        ? ($deliveryMetrics['on_time_deliveries'] / $deliveryMetrics['total_pos']) * 100 
        : 100;
    
    $qualityRate = $qualityMetrics['total_items'] > 0 
        ? ($qualityMetrics['passed_items'] / $qualityMetrics['total_items']) * 100 
        : 100;
    
    // Overall rating: 40% delivery, 40% quality, 20% response time
    $deliveryScore = ($onTimeRate / 100) * 5; // Max 5 stars
    $qualityScore = ($qualityRate / 100) * 5; // Max 5 stars
    $responseScore = min(5, max(0, 5 - ($deliveryMetrics['avg_response_time'] / 24))); // Deduct for slow response
    
    $overallRating = ($deliveryScore * 0.4) + ($qualityScore * 0.4) + ($responseScore * 0.2);
    
    // Update or insert performance record
    $stmt = $conn->prepare("
        INSERT INTO supplier_performance 
        (supplier_id, total_pos, on_time_deliveries, late_deliveries, 
         total_items_received, items_passed_qc, items_failed_qc, 
         avg_response_time_hours, overall_rating, last_calculated_at)
        VALUES 
        (:supplier_id, :total_pos, :on_time, :late, 
         :total_items, :passed, :failed, 
         :avg_response, :rating, NOW())
        ON DUPLICATE KEY UPDATE
        total_pos = VALUES(total_pos),
        on_time_deliveries = VALUES(on_time_deliveries),
        late_deliveries = VALUES(late_deliveries),
        total_items_received = VALUES(total_items_received),
        items_passed_qc = VALUES(items_passed_qc),
        items_failed_qc = VALUES(items_failed_qc),
        avg_response_time_hours = VALUES(avg_response_time_hours),
        overall_rating = VALUES(overall_rating),
        last_calculated_at = NOW()
    ");
    
    $stmt->execute([
        ':supplier_id' => $supplierId,
        ':total_pos' => $deliveryMetrics['total_pos'] ?? 0,
        ':on_time' => $deliveryMetrics['on_time_deliveries'] ?? 0,
        ':late' => $deliveryMetrics['late_deliveries'] ?? 0,
        ':total_items' => $qualityMetrics['total_items'] ?? 0,
        ':passed' => $qualityMetrics['passed_items'] ?? 0,
        ':failed' => $qualityMetrics['failed_items'] ?? 0,
        ':avg_response' => $deliveryMetrics['avg_response_time'] ?? 0,
        ':rating' => round($overallRating, 2)
    ]);
    
    // Also update supplier's rating field
    $stmt = $conn->prepare("UPDATE suppliers SET rating = :rating WHERE id = :id");
    $stmt->execute([':rating' => round($overallRating, 2), ':id' => $supplierId]);
    
    // Return the performance data
    $stmt = $conn->prepare("SELECT * FROM supplier_performance WHERE supplier_id = :id");
    $stmt->execute([':id' => $supplierId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

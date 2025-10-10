<?php
/**
 * Supplier Dashboard API
 * Provides analytics and metrics for supplier portal
 */

require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();
$auth = require_auth();
$authUserId = (int)$auth['id'];

// Get supplier_id from user
$supplierId = $auth['supplier_id'] ?? null;
if (!$supplierId) {
    json_err('User is not linked to a supplier', 403);
}

try {
    if ($method === 'GET') {
        // Get dashboard metrics
        
        // Total orders count
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM purchase_orders WHERE supplier_id = :sid");
        $stmt->execute([':sid' => $supplierId]);
        $totalOrders = (int)$stmt->fetchColumn();
        
        // Pending approval count
        $stmt = $conn->prepare("SELECT COUNT(*) as pending FROM purchase_orders WHERE supplier_id = :sid AND status = 'pending_approval'");
        $stmt->execute([':sid' => $supplierId]);
        $pendingCount = (int)$stmt->fetchColumn();
        
        // Total revenue
        $stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount), 0) as revenue FROM purchase_orders WHERE supplier_id = :sid AND status IN ('approved', 'sent', 'partially_received', 'received')");
        $stmt->execute([':sid' => $supplierId]);
        $totalRevenue = (float)$stmt->fetchColumn();
        
        // Average order value
        $avgOrderValue = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;
        
        // Status distribution
        $stmt = $conn->prepare("
            SELECT status, COUNT(*) as count 
            FROM purchase_orders 
            WHERE supplier_id = :sid 
            GROUP BY status
        ");
        $stmt->execute([':sid' => $supplierId]);
        $statusDistribution = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Order trends (last 30 days)
        $stmt = $conn->prepare("
            SELECT DATE(order_date) as date, COUNT(*) as count, COALESCE(SUM(total_amount), 0) as revenue
            FROM purchase_orders 
            WHERE supplier_id = :sid AND order_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY DATE(order_date)
            ORDER BY date ASC
        ");
        $stmt->execute([':sid' => $supplierId]);
        $orderTrends = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Performance metrics
        
        // Approval rate (approved / total submitted)
        $stmt = $conn->prepare("SELECT COUNT(*) as approved FROM purchase_orders WHERE supplier_id = :sid AND status IN ('approved', 'sent', 'partially_received', 'received')");
        $stmt->execute([':sid' => $supplierId]);
        $approvedCount = (int)$stmt->fetchColumn();
        $approvalRate = $totalOrders > 0 ? ($approvedCount / $totalOrders) * 100 : 0;
        
        // Rejection rate
        $stmt = $conn->prepare("SELECT COUNT(*) as rejected FROM purchase_orders WHERE supplier_id = :sid AND status = 'draft' AND approved_at IS NOT NULL");
        $stmt->execute([':sid' => $supplierId]);
        $rejectedCount = (int)$stmt->fetchColumn();
        $rejectionRate = $totalOrders > 0 ? ($rejectedCount / $totalOrders) * 100 : 0;
        
        // Average response time (hours from pending to approved/rejected)
        $stmt = $conn->prepare("
            SELECT AVG(TIMESTAMPDIFF(HOUR, pending_at, approved_at)) as avg_hours
            FROM purchase_orders 
            WHERE supplier_id = :sid AND pending_at IS NOT NULL AND approved_at IS NOT NULL
        ");
        $stmt->execute([':sid' => $supplierId]);
        $avgResponseTime = (float)$stmt->fetchColumn() ?: 0;
        
        // On-time delivery rate
        $stmt = $conn->prepare("
            SELECT 
                COUNT(CASE WHEN actual_delivery_date <= expected_delivery_date THEN 1 END) as on_time,
                COUNT(*) as total_delivered
            FROM purchase_orders 
            WHERE supplier_id = :sid AND status = 'received' AND actual_delivery_date IS NOT NULL
        ");
        $stmt->execute([':sid' => $supplierId]);
        $deliveryStats = $stmt->fetch(PDO::FETCH_ASSOC);
        $onTimeDelivery = $deliveryStats['total_delivered'] > 0 
            ? ($deliveryStats['on_time'] / $deliveryStats['total_delivered']) * 100 
            : 0;
        
        // Recent activity (last 10 status changes)
        $stmt = $conn->prepare("
            SELECT 
                psh.*, 
                po.po_number,
                u.full_name as changed_by_name
            FROM po_status_history psh
            JOIN purchase_orders po ON psh.po_id = po.id
            LEFT JOIN users u ON psh.changed_by = u.id
            WHERE po.supplier_id = :sid
            ORDER BY psh.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([':sid' => $supplierId]);
        $recentActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        json_ok([
            'metrics' => [
                'total_orders' => $totalOrders,
                'pending_count' => $pendingCount,
                'total_revenue' => $totalRevenue,
                'avg_order_value' => $avgOrderValue,
                'approval_rate' => round($approvalRate, 1),
                'rejection_rate' => round($rejectionRate, 1),
                'avg_response_time' => round($avgResponseTime, 1),
                'on_time_delivery' => round($onTimeDelivery, 1)
            ],
            'status_distribution' => $statusDistribution,
            'order_trends' => $orderTrends,
            'recent_activity' => $recentActivity
        ]);
    }
    
    json_err('Method not allowed', 405);
} catch (Exception $e) {
    json_err($e->getMessage(), 400);
}

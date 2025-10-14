<?php
require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();
$auth = require_auth();
if (($auth['role'] ?? '') !== 'admin') {
  json_err('Forbidden', 403);
}

try {
  if ($method !== 'GET') json_err('Method not allowed', 405);

  // Totals per module
  $counts = [];
  $counts['psm'] = [
    'suppliers' => (int)$conn->query('SELECT COUNT(*) FROM suppliers')->fetchColumn(),
    'purchase_orders' => (int)$conn->query('SELECT COUNT(*) FROM purchase_orders')->fetchColumn()
  ];
  $counts['plt'] = [
    'shipments' => (int)$conn->query('SELECT COUNT(*) FROM shipments')->fetchColumn(),
    'in_transit' => (int)$conn->query("SELECT COUNT(*) FROM shipments WHERE status = 'in_transit'")->fetchColumn(),
    'delayed' => (int)$conn->query("SELECT COUNT(*) FROM shipments WHERE status = 'delayed'")->fetchColumn()
  ];
  $counts['sws'] = [
    'inventory_items' => (int)$conn->query('SELECT COUNT(*) FROM inventory')->fetchColumn(),
    'products' => (int)$conn->query('SELECT COUNT(*) FROM products')->fetchColumn(),
    'warehouses' => (int)$conn->query('SELECT COUNT(*) FROM warehouses')->fetchColumn()
  ];
  $counts['alms'] = [
    'assets' => (int)$conn->query('SELECT COUNT(*) FROM assets')->fetchColumn(),
    'pending_maintenance' => (int)$conn->query("SELECT COUNT(*) FROM asset_maintenance WHERE status IN ('scheduled','in_progress','overdue')")->fetchColumn()
  ];
  $counts['dtrs'] = [
    'documents' => (int)$conn->query('SELECT COUNT(*) FROM documents')->fetchColumn()
  ];

  // Recent audit logs
  $stmt = $conn->prepare('SELECT a.*, u.email FROM audit_logs a LEFT JOIN users u ON a.user_id=u.id ORDER BY a.created_at DESC LIMIT 20');
  $stmt->execute();
  $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);

  json_ok(['counts'=>$counts,'recent_audit'=>$recent]);
} catch (Exception $e) {
  json_err($e->getMessage(), 400);
}

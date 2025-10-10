<?php
require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();

if ($method === 'POST') {
    $data = read_json_body();
    $poId = $data['po_id'] ?? null;

    if (!$poId) {
        json_err('PO ID is required', 400);
    }

    $stmt = $conn->prepare('UPDATE purchase_orders SET is_archived = 1 WHERE id = :id');
    $stmt->execute([':id' => $poId]);

    if ($stmt->rowCount() > 0) {
        json_ok(['message' => 'Purchase order archived successfully']);
    } else {
        json_err('Purchase order not found or already archived', 404);
    }
} else {
    json_err('Method not allowed', 405);
}
?>

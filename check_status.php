<?php
require_once __DIR__ . '/config/database.php';

$database = new Database();
$conn = $database->getConnection();

echo "<h2>Purchase Orders Status Check</h2>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>PO Number</th><th>Status</th><th>Status Length</th><th>Created At</th></tr>";

$stmt = $conn->query("SELECT id, po_number, status, LENGTH(status) as status_len, created_at FROM purchase_orders ORDER BY created_at DESC");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    $statusDisplay = $row['status'] === '' ? '(EMPTY STRING)' : $row['status'];
    $statusDisplay = $row['status'] === null ? '(NULL)' : $statusDisplay;
    echo "<tr>";
    echo "<td>{$row['id']}</td>";
    echo "<td>{$row['po_number']}</td>";
    echo "<td><strong>{$statusDisplay}</strong></td>";
    echo "<td>{$row['status_len']}</td>";
    echo "<td>{$row['created_at']}</td>";
    echo "</tr>";
}

echo "</table>";

echo "<h3>Fix Empty Statuses</h3>";
echo "<p>Run this SQL to fix empty statuses:</p>";
echo "<pre>UPDATE purchase_orders SET status = 'draft' WHERE status = '' OR status IS NULL;</pre>";

// Check status history
echo "<h3>Status History</h3>";
$histStmt = $conn->query("SELECT * FROM po_status_history ORDER BY created_at DESC LIMIT 10");
if ($histStmt) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>PO ID</th><th>From</th><th>To</th><th>Changed By</th><th>Created At</th></tr>";
    while ($hist = $histStmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td>{$hist['po_id']}</td>";
        echo "<td>{$hist['from_status']}</td>";
        echo "<td><strong>{$hist['to_status']}</strong></td>";
        echo "<td>{$hist['changed_by']}</td>";
        echo "<td>{$hist['created_at']}</td>";
        echo "</tr>";
    }
    echo "</table>";
}
?>

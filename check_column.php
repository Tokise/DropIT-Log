<?php
require_once __DIR__ . '/config/database.php';

$database = new Database();
$conn = $database->getConnection();

echo "<h2>Purchase Orders Table Structure</h2>";

// Get column info
$stmt = $conn->query("DESCRIBE purchase_orders");
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $highlight = $row['Field'] === 'status' ? 'background: yellow;' : '';
    echo "<tr style='$highlight'>";
    echo "<td><strong>{$row['Field']}</strong></td>";
    echo "<td>{$row['Type']}</td>";
    echo "<td>{$row['Null']}</td>";
    echo "<td>{$row['Key']}</td>";
    echo "<td>" . ($row['Default'] ?? 'NULL') . "</td>";
    echo "<td>{$row['Extra']}</td>";
    echo "</tr>";
}
echo "</table>";

// Check for triggers
echo "<h3>Triggers on purchase_orders table:</h3>";
$triggerStmt = $conn->query("SHOW TRIGGERS WHERE `Table` = 'purchase_orders'");
$triggers = $triggerStmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($triggers)) {
    echo "<p>No triggers found</p>";
} else {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Trigger</th><th>Event</th><th>Timing</th><th>Statement</th></tr>";
    foreach ($triggers as $trigger) {
        echo "<tr>";
        echo "<td>{$trigger['Trigger']}</td>";
        echo "<td>{$trigger['Event']}</td>";
        echo "<td>{$trigger['Timing']}</td>";
        echo "<td><pre>" . htmlspecialchars($trigger['Statement']) . "</pre></td>";
        echo "</tr>";
    }
    echo "</table>";
}

// Try a direct update with different values
echo "<h3>Testing Direct Update:</h3>";

$testPoId = 1;
echo "<p>Testing on PO ID: $testPoId</p>";

// Test 1: Update to 'pending'
$conn->exec("UPDATE purchase_orders SET status = 'pending' WHERE id = $testPoId");
$result1 = $conn->query("SELECT status, LENGTH(status) as len FROM purchase_orders WHERE id = $testPoId")->fetch();
echo "<p>Test 1 - Set to 'pending': Status = '{$result1['status']}', Length = {$result1['len']}</p>";

// Test 2: Update to 'approved'  
$conn->exec("UPDATE purchase_orders SET status = 'approved' WHERE id = $testPoId");
$result2 = $conn->query("SELECT status, LENGTH(status) as len FROM purchase_orders WHERE id = $testPoId")->fetch();
echo "<p>Test 2 - Set to 'approved': Status = '{$result2['status']}', Length = {$result2['len']}</p>";

// Test 3: Update to 'draft'
$conn->exec("UPDATE purchase_orders SET status = 'draft' WHERE id = $testPoId");
$result3 = $conn->query("SELECT status, LENGTH(status) as len FROM purchase_orders WHERE id = $testPoId")->fetch();
echo "<p>Test 3 - Set to 'draft': Status = '{$result3['status']}', Length = {$result3['len']}</p>";

echo "<h3>Recommendation:</h3>";
if ($result1['len'] == 0 && $result2['len'] == 0) {
    echo "<p style='color: red;'><strong>The status column is NOT accepting values!</strong></p>";
    echo "<p>Possible causes:</p>";
    echo "<ul>";
    echo "<li>Column data type is wrong (maybe ENUM with limited values?)</li>";
    echo "<li>There's a trigger resetting the value</li>";
    echo "<li>Column has a CHECK constraint</li>";
    echo "</ul>";
    echo "<p><strong>Solution:</strong> Recreate the status column:</p>";
    echo "<pre>ALTER TABLE purchase_orders MODIFY COLUMN status VARCHAR(50) DEFAULT 'draft';</pre>";
}
?>

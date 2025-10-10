<?php
require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();
// Require authenticated user
$auth = require_auth();
$authUserId = (int)$auth['id'];
$MODULE = 'sws';

try {
    if ($method === 'GET') {
        // List inventory with product and warehouse data
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $warehouseId = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : null;
        $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : null;

        $where = [];
        $params = [];
        if ($warehouseId) { $where[] = 'i.warehouse_id = :warehouse_id'; $params[':warehouse_id'] = $warehouseId; }
        if ($productId) { $where[] = 'i.product_id = :product_id'; $params[':product_id'] = $productId; }
        $whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

        $sql = "SELECT SQL_CALC_FOUND_ROWS i.*, p.sku, p.name as product_name, p.product_image, w.name as warehouse_name
                FROM inventory i
                JOIN products p ON i.product_id = p.id
                JOIN warehouses w ON i.warehouse_id = w.id
                $whereSql
                ORDER BY i.updated_at DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $conn->prepare($sql);
        foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total = (int)$conn->query('SELECT FOUND_ROWS()')->fetchColumn();
        json_ok(['items' => $rows, 'page' => $page, 'limit' => $limit, 'total' => $total]);
    }

    if ($method === 'POST') {
        $data = read_json_body();
        // Two modes: create/update record or adjust via transaction
        $action = $data['action'] ?? 'adjust';
        if ($action === 'adjust') {
            require_params($data, ['product_id','warehouse_id','quantity','transaction_type']);
            $quantity = (int)$data['quantity'];
            // Start transaction
            $conn->beginTransaction();
            try {
                // Upsert inventory row
                $stmt = $conn->prepare("SELECT id, quantity, reserved_quantity FROM inventory WHERE product_id = :pid AND warehouse_id = :wid FOR UPDATE");
                $stmt->execute([':pid' => $data['product_id'], ':wid' => $data['warehouse_id']]);
                $inv = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($inv) {
                    $newQty = (int)$inv['quantity'] + $quantity;
                    $stmt = $conn->prepare("UPDATE inventory SET quantity = :q, last_restocked_at = IF(:delta>0, NOW(), last_restocked_at), updated_at = NOW() WHERE id = :id");
                    $stmt->execute([':q' => $newQty, ':delta' => $quantity, ':id' => $inv['id']]);
                } else {
                    $stmt = $conn->prepare("INSERT INTO inventory (product_id, warehouse_id, quantity, reserved_quantity, location_code, last_restocked_at) VALUES (:pid, :wid, :q, 0, :loc, NOW())");
                    $stmt->execute([':pid' => $data['product_id'], ':wid' => $data['warehouse_id'], ':q' => max(0,$quantity), ':loc' => $data['location_code'] ?? null]);
                }

                // Log transaction
                $stmt = $conn->prepare("INSERT INTO inventory_transactions (product_id, warehouse_id, transaction_type, quantity, reference_type, reference_id, notes, performed_by) VALUES (:pid,:wid,:tt,:qty,:rtype,:rid,:notes,:uid)");
                $stmt->execute([
                    ':pid' => $data['product_id'],
                    ':wid' => $data['warehouse_id'],
                    ':tt' => $data['transaction_type'], // 'receipt'|'shipment'|'adjustment'|'transfer'|'return'
                    ':qty' => $quantity,
                    ':rtype' => $data['reference_type'] ?? null,
                    ':rid' => $data['reference_id'] ?? null,
                    ':notes' => $data['notes'] ?? null,
                    ':uid' => $authUserId,
                ]);

                $conn->commit();
                log_audit('inventory', (int)($inv['id'] ?? 0), 'adjust', $authUserId, json_encode($data));
                notify_module_users($MODULE, 'Inventory adjusted', 'An inventory adjustment has been posted', 'sws.php');
                json_ok(['adjusted' => true]);
            } catch (Exception $e) {
                $conn->rollBack();
                json_err($e->getMessage(), 400);
            }
        } else {
            json_err('Unsupported action', 400);
        }
    }

    if ($method === 'PUT') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) json_err('id is required', 422);
        $data = read_json_body();
        $fields = ['location_code'];
        $sets = [];
        $params = [':id' => $id];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) { $sets[] = "$f = :$f"; $params[":$f"] = $data[$f]; }
        }
        if (empty($sets)) json_err('No fields to update', 422);
        $sql = 'UPDATE inventory SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        log_audit('inventory', $id, 'update', $authUserId, json_encode($data));
        notify_module_users($MODULE, 'Inventory updated', "Inventory #$id location updated", 'sws.php');
        json_ok(['updated' => true]);
    }

    if ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) json_err('id is required', 422);
        $stmt = $conn->prepare('DELETE FROM inventory WHERE id = :id');
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        log_audit('inventory', $id, 'delete', $authUserId, null);
        notify_module_users($MODULE, 'Inventory deleted', "Inventory #$id has been removed", 'sws.php');
        json_ok(['deleted' => true]);
    }

    json_err('Method not allowed', 405);
} catch (Exception $e) {
    json_err($e->getMessage(), 400);
}

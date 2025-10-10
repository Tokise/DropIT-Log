<?php
require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();
$auth = require_auth();
$authUserId = (int)$auth['id'];

try {
    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id > 0) {
            $stmt = $conn->prepare("SELECT * FROM warehouses WHERE id = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) json_err('Warehouse not found', 404);
            json_ok($row);
        } else {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;
            $q = trim($_GET['q'] ?? '');
            
            if ($q !== '') {
                $stmt = $conn->prepare("SELECT SQL_CALC_FOUND_ROWS * FROM warehouses WHERE name LIKE :q OR code LIKE :q ORDER BY name LIMIT :limit OFFSET :offset");
                $like = "%$q%";
                $stmt->bindParam(':q', $like);
            } else {
                $stmt = $conn->prepare("SELECT SQL_CALC_FOUND_ROWS * FROM warehouses ORDER BY name LIMIT :limit OFFSET :offset");
            }
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total = (int)$conn->query('SELECT FOUND_ROWS()')->fetchColumn();
            json_ok(['items' => $rows, 'page' => $page, 'limit' => $limit, 'total' => $total]);
        }
    }

    if ($method === 'POST') {
        admin_block_mutations();
        $data = read_json_body();
        require_params($data, ['name','code']);
        
        $stmt = $conn->prepare("INSERT INTO warehouses (name,code,address,city,country,capacity_cubic_meters,is_active) VALUES (:name,:code,:address,:city,:country,:capacity,:is_active)");
        $stmt->execute([
            ':name' => $data['name'],
            ':code' => $data['code'],
            ':address' => $data['address'] ?? null,
            ':city' => $data['city'] ?? null,
            ':country' => $data['country'] ?? null,
            ':capacity' => $data['capacity_cubic_meters'] ?? null,
            ':is_active' => isset($data['is_active']) ? (int)(!!$data['is_active']) : 1,
        ]);
        $id = (int)$conn->lastInsertId();
        log_audit('warehouse', $id, 'create', $authUserId, json_encode($data));
        notify_module_users('sws', 'Warehouse created', "New warehouse '{$data['name']}' has been added", 'sws.php');
        json_ok(['id' => $id], 201);
    }

    if ($method === 'PUT') {
        admin_block_mutations();
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) json_err('id is required', 422);
        $data = read_json_body();
        
        $fields = ['name','code','address','city','country','capacity_cubic_meters','is_active'];
        $sets = [];
        $params = [':id' => $id];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = "$f = :$f";
                $params[":$f"] = $data[$f];
            }
        }
        if (empty($sets)) json_err('No fields to update', 422);
        
        $sql = 'UPDATE warehouses SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        log_audit('warehouse', $id, 'update', $authUserId, json_encode($data));
        notify_module_users('sws', 'Warehouse updated', "Warehouse #$id has been updated", 'sws.php');
        json_ok(['updated' => true]);
    }

    if ($method === 'DELETE') {
        admin_block_mutations();
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) json_err('id is required', 422);
        
        $stmt = $conn->prepare('DELETE FROM warehouses WHERE id = :id');
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        log_audit('warehouse', $id, 'delete', $authUserId, null);
        notify_module_users('sws', 'Warehouse deleted', "Warehouse #$id has been removed", 'sws.php');
        json_ok(['deleted' => true]);
    }

    json_err('Method not allowed', 405);
} catch (Exception $e) {
    json_err($e->getMessage(), 400);
}

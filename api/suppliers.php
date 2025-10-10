<?php
require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();
// Require authenticated user
$auth = require_auth();
$authUserId = (int)$auth['id'];
$MODULE = 'psm';

try {
    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id > 0) {
            $stmt = $conn->prepare("SELECT * FROM suppliers WHERE id = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) json_err('Supplier not found', 404);
            json_ok($row);
        } else {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;
            $q = trim($_GET['q'] ?? '');
            if ($q !== '') {
                $stmt = $conn->prepare("SELECT SQL_CALC_FOUND_ROWS * FROM suppliers WHERE name LIKE :q OR code LIKE :q ORDER BY id DESC LIMIT :limit OFFSET :offset");
                $like = "%$q%";
                $stmt->bindParam(':q', $like);
            } else {
                $stmt = $conn->prepare("SELECT SQL_CALC_FOUND_ROWS * FROM suppliers ORDER BY id DESC LIMIT :limit OFFSET :offset");
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
        $data = read_json_body();
        require_params($data, ['name','code']);
        
        // Check if city column exists
        $columnsStmt = $conn->prepare("SHOW COLUMNS FROM suppliers LIKE 'city'");
        $columnsStmt->execute();
        $hasCityColumn = $columnsStmt->rowCount() > 0;
        
        if ($hasCityColumn) {
            $stmt = $conn->prepare("INSERT INTO suppliers (name, code, contact_person, email, phone, address, city, country, payment_terms, rating, is_active) VALUES (:name,:code,:contact_person,:email,:phone,:address,:city,:country,:payment_terms,:rating,:is_active)");
            $stmt->execute([
                ':name' => $data['name'],
                ':code' => $data['code'],
                ':contact_person' => $data['contact_person'] ?? null,
                ':email' => $data['email'] ?? null,
                ':phone' => $data['phone'] ?? null,
                ':address' => $data['address'] ?? null,
                ':city' => $data['city'] ?? null,
                ':country' => $data['country'] ?? null,
                ':payment_terms' => $data['payment_terms'] ?? null,
                ':rating' => $data['rating'] ?? 5.0,
                ':is_active' => isset($data['is_active']) ? (int)(!!$data['is_active']) : 1,
            ]);
        } else {
            $stmt = $conn->prepare("INSERT INTO suppliers (name, code, contact_person, email, phone, address, country, payment_terms, rating, is_active) VALUES (:name,:code,:contact_person,:email,:phone,:address,:country,:payment_terms,:rating,:is_active)");
            $stmt->execute([
                ':name' => $data['name'],
                ':code' => $data['code'],
                ':contact_person' => $data['contact_person'] ?? null,
                ':email' => $data['email'] ?? null,
                ':phone' => $data['phone'] ?? null,
                ':address' => $data['address'] ?? null,
                ':country' => $data['country'] ?? null,
                ':payment_terms' => $data['payment_terms'] ?? null,
                ':rating' => $data['rating'] ?? 5.0,
                ':is_active' => isset($data['is_active']) ? (int)(!!$data['is_active']) : 1,
            ]);
        }
        
        $id = (int)$conn->lastInsertId();
        // Audit + notify
        log_audit('supplier', $id, 'create', $authUserId, json_encode($data));
        notify_module_users($MODULE, 'Supplier created', "New supplier '{$data['name']}' has been added", 'psm.php');
        json_ok(['id' => $id], 201);
    }

    if ($method === 'PUT') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) json_err('id is required', 422);
        $data = read_json_body();
        $fields = ['name','code','contact_person','email','phone','address','country','payment_terms','rating','is_active'];
        $sets = [];
        $params = [':id' => $id];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = "$f = :$f";
                $params[":$f"] = $data[$f];
            }
        }
        if (empty($sets)) json_err('No fields to update', 422);
        $sql = 'UPDATE suppliers SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        log_audit('supplier', $id, 'update', $authUserId, json_encode($data));
        notify_module_users($MODULE, 'Supplier updated', "Supplier #$id has been updated", 'psm.php');
        json_ok(['updated' => true]);
    }

    if ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) json_err('id is required', 422);
        $stmt = $conn->prepare('DELETE FROM suppliers WHERE id = :id');
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        log_audit('supplier', $id, 'delete', $authUserId, null);
        notify_module_users($MODULE, 'Supplier deleted', "Supplier #$id has been deleted", 'psm.php');
        json_ok(['deleted' => true]);
    }

    json_err('Method not allowed', 405);
} catch (Exception $e) {
    json_err($e->getMessage(), 400);
}

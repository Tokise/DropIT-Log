<?php
require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();

try {
    if ($method === 'GET') {
        $assetId = isset($_GET['asset_id']) ? (int)$_GET['asset_id'] : 0;
        if ($assetId <= 0) json_err('asset_id is required', 422);
        $stmt = $conn->prepare("SELECT * FROM maintenance_plans WHERE asset_id = :aid ORDER BY next_due_at ASC, id ASC");
        $stmt->execute([':aid'=>$assetId]);
        json_ok($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($method === 'POST') {
        $data = read_json_body();
        require_params($data, ['asset_id','interval_days']);
        $stmt = $conn->prepare("INSERT INTO maintenance_plans (asset_id, interval_days, last_service_at, next_due_at, notes) VALUES (:asset_id,:interval_days,:last_service_at,:next_due_at,:notes)");
        $stmt->execute([
            ':asset_id'=>$data['asset_id'], ':interval_days'=>$data['interval_days'],
            ':last_service_at'=>$data['last_service_at'] ?? null, ':next_due_at'=>$data['next_due_at'] ?? null,
            ':notes'=>$data['notes'] ?? null,
        ]);
        json_ok(['id'=>(int)$conn->lastInsertId()],201);
    }

    if ($method === 'PUT') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) json_err('id is required', 422);
        $data = read_json_body();
        $fields = ['interval_days','last_service_at','next_due_at','notes'];
        $sets=[];$params=[':id'=>$id];
        foreach ($fields as $f) if (array_key_exists($f,$data)) { $sets[] = "$f = :$f"; $params[":$f"]=$data[$f]; }
        if (empty($sets)) json_err('No fields to update',422);
        $sql = 'UPDATE maintenance_plans SET '.implode(', ',$sets).' WHERE id = :id';
        $stmt = $conn->prepare($sql); $stmt->execute($params);
        json_ok(['updated'=>true]);
    }

    if ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) json_err('id is required', 422);
        $stmt = $conn->prepare('DELETE FROM maintenance_plans WHERE id = :id');
        $stmt->execute([':id'=>$id]);
        json_ok(['deleted'=>true]);
    }

    json_err('Method not allowed',405);
} catch (Exception $e) {
    json_err($e->getMessage(),400);
}

<?php
require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();

try {
    if ($method === 'GET') {
        $shipmentId = isset($_GET['shipment_id']) ? (int)$_GET['shipment_id'] : 0;
        if ($shipmentId <= 0) json_err('shipment_id is required', 422);
        $stmt = $conn->prepare("SELECT * FROM shipment_milestones WHERE shipment_id = :sid ORDER BY expected_at ASC, id ASC");
        $stmt->execute([':sid'=>$shipmentId]);
        json_ok($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($method === 'POST') {
        $data = read_json_body();
        require_params($data, ['shipment_id','name']);
        $stmt = $conn->prepare("INSERT INTO shipment_milestones (shipment_id, name, expected_at, actual_at, status, notes) VALUES (:sid,:name,:expected_at,:actual_at,:status,:notes)");
        $stmt->execute([
            ':sid'=>$data['shipment_id'], ':name'=>$data['name'], ':expected_at'=>$data['expected_at'] ?? null,
            ':actual_at'=>$data['actual_at'] ?? null, ':status'=>$data['status'] ?? 'pending', ':notes'=>$data['notes'] ?? null,
        ]);
        json_ok(['id'=>(int)$conn->lastInsertId()],201);
    }

    if ($method === 'PUT') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) json_err('id is required', 422);
        $data = read_json_body();
        $fields = ['name','expected_at','actual_at','status','notes'];
        $sets=[]; $params=[':id'=>$id];
        foreach ($fields as $f) if (array_key_exists($f,$data)) { $sets[] = "$f = :$f"; $params[":$f"]=$data[$f]; }
        if (empty($sets)) json_err('No fields to update',422);
        $sql = 'UPDATE shipment_milestones SET '.implode(', ',$sets).' WHERE id = :id';
        $stmt = $conn->prepare($sql); $stmt->execute($params);
        json_ok(['updated'=>true]);
    }

    if ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) json_err('id is required', 422);
        $stmt = $conn->prepare('DELETE FROM shipment_milestones WHERE id = :id');
        $stmt->execute([':id'=>$id]);
        json_ok(['deleted'=>true]);
    }

    json_err('Method not allowed',405);
} catch (Exception $e) {
    json_err($e->getMessage(),400);
}

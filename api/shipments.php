<?php
require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();
// Require authenticated user
$auth = require_auth();
$authUserId = (int)$auth['id'];
$MODULE = 'plt';

try {
    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id > 0) {
            $stmt = $conn->prepare("SELECT s.*, p.code AS project_code FROM shipments s LEFT JOIN projects p ON s.project_id=p.id WHERE s.id = :id");
            $stmt->execute([':id'=>$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) json_err('Shipment not found',404);
            // milestones
            $ms = $conn->prepare("SELECT * FROM shipment_milestones WHERE shipment_id = :sid ORDER BY expected_at ASC");
            $ms->execute([':sid'=>$id]);
            $row['milestones'] = $ms->fetchAll(PDO::FETCH_ASSOC);
            // events
            $ev = $conn->prepare("SELECT * FROM tracking_events WHERE shipment_id = :sid ORDER BY event_time DESC");
            $ev->execute([':sid'=>$id]);
            $row['events'] = $ev->fetchAll(PDO::FETCH_ASSOC);
            json_ok($row);
        } else {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;
            $status = $_GET['status'] ?? null;
            $projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : null;
            $where=[]; $params=[];
            if ($status) { $where[]='s.status = :status'; $params[':status']=$status; }
            if ($projectId) { $where[]='s.project_id = :pid'; $params[':pid']=$projectId; }
            $whereSql = empty($where)?'':'WHERE '.implode(' AND ',$where);
            $sql = "SELECT SQL_CALC_FOUND_ROWS s.*, p.code AS project_code FROM shipments s LEFT JOIN projects p ON s.project_id=p.id $whereSql ORDER BY s.created_at DESC LIMIT :limit OFFSET :offset";
            $stmt = $conn->prepare($sql);
            foreach ($params as $k=>$v) { $stmt->bindValue($k,$v); }
            $stmt->bindValue(':limit',$limit,PDO::PARAM_INT);
            $stmt->bindValue(':offset',$offset,PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total = (int)$conn->query('SELECT FOUND_ROWS()')->fetchColumn();
            json_ok(['items'=>$rows,'page'=>$page,'limit'=>$limit,'total'=>$total]);
        }
    }

    if ($method === 'POST') {
        $data = read_json_body();
        require_params($data,['status']);
        $stmt = $conn->prepare("INSERT INTO shipments (project_id, carrier, tracking_no, origin, destination, eta, status) VALUES (:project_id,:carrier,:tracking_no,:origin,:destination,:eta,:status)");
        $stmt->execute([
            ':project_id' => $data['project_id'] ?? null,
            ':carrier' => $data['carrier'] ?? null,
            ':tracking_no' => $data['tracking_no'] ?? null,
            ':origin' => $data['origin'] ?? null,
            ':destination' => $data['destination'] ?? null,
            ':eta' => $data['eta'] ?? null,
            ':status' => $data['status'] ?? 'planned',
        ]);
        $id = (int)$conn->lastInsertId();
        log_audit('shipment', $id, 'create', $authUserId, json_encode($data));
        notify_module_users($MODULE, 'Shipment created', "Shipment #$id has been created", 'plt.php');
        json_ok(['id'=>$id],201);
    }

    if ($method === 'PUT') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) json_err('id is required',422);
        $data = read_json_body();
        $fields = ['project_id','carrier','tracking_no','origin','destination','eta','status'];
        $sets=[]; $params=[':id'=>$id];
        foreach ($fields as $f) if (array_key_exists($f,$data)) { $sets[] = "$f = :$f"; $params[":$f"] = $data[$f]; }
        if (empty($sets)) json_err('No fields to update',422);
        $sql = 'UPDATE shipments SET '.implode(', ',$sets).' WHERE id = :id';
        $stmt = $conn->prepare($sql); $stmt->execute($params);
        log_audit('shipment', $id, 'update', $authUserId, json_encode($data));
        notify_module_users($MODULE, 'Shipment updated', "Shipment #$id has been updated", 'plt.php');
        json_ok(['updated'=>true]);
    }

    if ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) json_err('id is required',422);
        $stmt = $conn->prepare('DELETE FROM shipments WHERE id = :id');
        $stmt->execute([':id'=>$id]);
        log_audit('shipment', $id, 'delete', $authUserId, null);
        notify_module_users($MODULE, 'Shipment deleted', "Shipment #$id has been deleted", 'plt.php');
        json_ok(['deleted'=>true]);
    }

    json_err('Method not allowed',405);
} catch (Exception $e) {
    json_err($e->getMessage(),400);
}

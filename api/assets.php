<?php
require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();
// Require authenticated user
$auth = require_auth();
$authUserId = (int)$auth['id'];
$MODULE = 'alms';

try {
    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id > 0) {
            $stmt = $conn->prepare("SELECT * FROM assets WHERE id = :id");
            $stmt->execute([':id'=>$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) json_err('Asset not found',404);
            // fetch latest plan and open WOs
            $plan = $conn->prepare("SELECT * FROM maintenance_plans WHERE asset_id = :aid ORDER BY next_due_at ASC LIMIT 1");
            $plan->execute([':aid'=>$id]);
            $row['maintenance_plan'] = $plan->fetch(PDO::FETCH_ASSOC);
            $wos = $conn->prepare("SELECT * FROM work_orders WHERE asset_id = :aid AND status IN ('open','scheduled','in_progress') ORDER BY priority DESC, created_at DESC");
            $wos->execute([':aid'=>$id]);
            $row['open_work_orders'] = $wos->fetchAll(PDO::FETCH_ASSOC);
            json_ok($row);
        } else {
            $page = max(1,(int)($_GET['page'] ?? 1));
            $limit = min(100,max(1,(int)($_GET['limit'] ?? 20)));
            $offset = ($page-1)*$limit;
            $status = $_GET['status'] ?? null;
            $where=[];$params=[];
            if ($status) { $where[]='status = :status'; $params[':status']=$status; }
            $whereSql = empty($where)?'':'WHERE '.implode(' AND ',$where);
            $sql = "SELECT SQL_CALC_FOUND_ROWS * FROM assets $whereSql ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
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
        require_params($data,['code','name']);
        $stmt = $conn->prepare("INSERT INTO assets (code,name,category,serial_no,location,status,purchased_at,warranty_until) VALUES (:code,:name,:category,:serial_no,:location,:status,:purchased_at,:warranty_until)");
        $stmt->execute([
            ':code'=>$data['code'], ':name'=>$data['name'], ':category'=>$data['category'] ?? null,
            ':serial_no'=>$data['serial_no'] ?? null, ':location'=>$data['location'] ?? null,
            ':status'=>$data['status'] ?? 'active', ':purchased_at'=>$data['purchased_at'] ?? null,
            ':warranty_until'=>$data['warranty_until'] ?? null,
        ]);
        $id = (int)$conn->lastInsertId();
        log_audit('asset', $id, 'create', $authUserId, json_encode($data));
        notify_module_users($MODULE, 'Asset created', "Asset #$id has been created", 'alms.php');
        json_ok(['id'=>$id],201);
    }

    if ($method === 'PUT') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) json_err('id is required',422);
        $data = read_json_body();
        $fields = ['code','name','category','serial_no','location','status','purchased_at','warranty_until'];
        $sets=[];$params=[':id'=>$id];
        foreach ($fields as $f) if (array_key_exists($f,$data)) { $sets[] = "$f = :$f"; $params[":$f"] = $data[$f]; }
        if (empty($sets)) json_err('No fields to update',422);
        $sql = 'UPDATE assets SET '.implode(', ',$sets).' WHERE id = :id';
        $stmt = $conn->prepare($sql); $stmt->execute($params);
        log_audit('asset', $id, 'update', $authUserId, json_encode($data));
        notify_module_users($MODULE, 'Asset updated', "Asset #$id has been updated", 'alms.php');
        json_ok(['updated'=>true]);
    }

    if ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) json_err('id is required',422);
        $stmt = $conn->prepare('DELETE FROM assets WHERE id = :id');
        $stmt->execute([':id'=>$id]);
        log_audit('asset', $id, 'delete', $authUserId, null);
        notify_module_users($MODULE, 'Asset deleted', "Asset #$id has been deleted", 'alms.php');
        json_ok(['deleted'=>true]);
    }

    json_err('Method not allowed',405);
} catch (Exception $e) {
    json_err($e->getMessage(),400);
}

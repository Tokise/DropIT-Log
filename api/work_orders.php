<?php
require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();

try {
    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id > 0) {
            $stmt = $conn->prepare("SELECT wo.*, a.code AS asset_code, a.name AS asset_name FROM work_orders wo JOIN assets a ON wo.asset_id=a.id WHERE wo.id = :id");
            $stmt->execute([':id'=>$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) json_err('Work order not found',404);
            json_ok($row);
        } else {
            $page = max(1,(int)($_GET['page'] ?? 1));
            $limit = min(100,max(1,(int)($_GET['limit'] ?? 20)));
            $offset = ($page-1)*$limit;
            $status = $_GET['status'] ?? null;
            $assetId = isset($_GET['asset_id']) ? (int)$_GET['asset_id'] : null;
            $where=[];$params=[];
            if ($status) { $where[]='wo.status = :status'; $params[':status']=$status; }
            if ($assetId) { $where[]='wo.asset_id = :aid'; $params[':aid']=$assetId; }
            $whereSql = empty($where)?'':'WHERE '.implode(' AND ',$where);
            $sql = "SELECT SQL_CALC_FOUND_ROWS wo.*, a.code AS asset_code, a.name AS asset_name FROM work_orders wo JOIN assets a ON wo.asset_id=a.id $whereSql ORDER BY wo.created_at DESC LIMIT :limit OFFSET :offset";
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
        require_params($data,['asset_id','type','priority']);
        $stmt = $conn->prepare("INSERT INTO work_orders (asset_id, type, priority, status, assigned_to, scheduled_for, notes) VALUES (:asset_id,:type,:priority,:status,:assigned_to,:scheduled_for,:notes)");
        $stmt->execute([
            ':asset_id'=>$data['asset_id'], ':type'=>$data['type'], ':priority'=>$data['priority'],
            ':status'=>$data['status'] ?? 'open', ':assigned_to'=>$data['assigned_to'] ?? null,
            ':scheduled_for'=>$data['scheduled_for'] ?? null, ':notes'=>$data['notes'] ?? null,
        ]);
        json_ok(['id'=>(int)$conn->lastInsertId()],201);
    }

    if ($method === 'PUT') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) json_err('id is required',422);
        $data = read_json_body();
        $fields = ['type','priority','status','assigned_to','scheduled_for','completed_at','notes'];
        $sets=[];$params=[':id'=>$id];
        foreach ($fields as $f) if (array_key_exists($f,$data)) { $sets[] = "$f = :$f"; $params[":$f"] = $data[$f]; }
        if (empty($sets)) json_err('No fields to update',422);
        $sql = 'UPDATE work_orders SET '.implode(', ',$sets).' WHERE id = :id';
        $stmt = $conn->prepare($sql); $stmt->execute($params);
        json_ok(['updated'=>true]);
    }

    if ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) json_err('id is required',422);
        $stmt = $conn->prepare('DELETE FROM work_orders WHERE id = :id');
        $stmt->execute([':id'=>$id]);
        json_ok(['deleted'=>true]);
    }

    json_err('Method not allowed',405);
} catch (Exception $e) {
    json_err($e->getMessage(),400);
}

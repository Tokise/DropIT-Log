<?php
require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();

try {
    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id > 0) {
            $stmt = $conn->prepare("SELECT * FROM documents WHERE id = :id");
            $stmt->execute([':id'=>$id]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$doc) json_err('Document not found',404);
            $links = $conn->prepare("SELECT * FROM document_links WHERE document_id = :did");
            $links->execute([':did'=>$id]);
            $doc['links'] = $links->fetchAll(PDO::FETCH_ASSOC);
            json_ok($doc);
        } else {
            $page = max(1,(int)($_GET['page'] ?? 1));
            $limit = min(100,max(1,(int)($_GET['limit'] ?? 20)));
            $offset = ($page-1)*$limit;
            $type = $_GET['type'] ?? null;
            $entityType = $_GET['entity_type'] ?? null;
            $entityId = isset($_GET['entity_id']) ? (int)$_GET['entity_id'] : null;
            $where=[]; $params=[];
            if ($type) { $where[]='type = :type'; $params[':type']=$type; }
            if ($entityType) { $where[]='entity_type = :etype'; $params[':etype']=$entityType; }
            if ($entityId) { $where[]='entity_id = :eid'; $params[':eid']=$entityId; }
            $whereSql = empty($where)?'':'WHERE '.implode(' AND ',$where);
            $sql = "SELECT SQL_CALC_FOUND_ROWS * FROM documents $whereSql ORDER BY uploaded_at DESC LIMIT :limit OFFSET :offset";
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
        // Note: this endpoint registers document metadata. For file uploads, post the storage_url or build a separate upload handler.
        $data = read_json_body();
        require_params($data,['title','type']);
        $stmt = $conn->prepare("INSERT INTO documents (doc_no, title, type, entity_type, entity_id, status, storage_url, checksum, uploaded_by) VALUES (:doc_no,:title,:type,:entity_type,:entity_id,:status,:storage_url,:checksum,:uploaded_by)");
        $stmt->execute([
            ':doc_no'=>$data['doc_no'] ?? null,
            ':title'=>$data['title'],
            ':type'=>$data['type'],
            ':entity_type'=>$data['entity_type'] ?? null,
            ':entity_id'=>$data['entity_id'] ?? null,
            ':status'=>$data['status'] ?? 'draft',
            ':storage_url'=>$data['storage_url'] ?? null,
            ':checksum'=>$data['checksum'] ?? null,
            ':uploaded_by'=>$data['uploaded_by'] ?? null,
        ]);
        json_ok(['id'=>(int)$conn->lastInsertId()],201);
    }

    if ($method === 'PUT') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) json_err('id is required',422);
        $data = read_json_body();
        $fields = ['doc_no','title','type','entity_type','entity_id','status','storage_url','checksum'];
        $sets=[]; $params=[':id'=>$id];
        foreach ($fields as $f) if (array_key_exists($f,$data)) { $sets[] = "$f = :$f"; $params[":$f"]=$data[$f]; }
        if (empty($sets)) json_err('No fields to update',422);
        $sql = 'UPDATE documents SET '.implode(', ',$sets).' WHERE id = :id';
        $stmt = $conn->prepare($sql); $stmt->execute($params);
        json_ok(['updated'=>true]);
    }

    if ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) json_err('id is required',422);
        $stmt = $conn->prepare('DELETE FROM documents WHERE id = :id');
        $stmt->execute([':id'=>$id]);
        json_ok(['deleted'=>true]);
    }

    json_err('Method not allowed',405);
} catch (Exception $e) {
    json_err($e->getMessage(),400);
}

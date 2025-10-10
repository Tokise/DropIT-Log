<?php
require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();

try {
    if ($method === 'GET') {
        $documentId = isset($_GET['document_id']) ? (int)$_GET['document_id'] : 0;
        if ($documentId <= 0) json_err('document_id is required', 422);
        $stmt = $conn->prepare("SELECT * FROM document_links WHERE document_id = :did ORDER BY id DESC");
        $stmt->execute([':did'=>$documentId]);
        json_ok($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($method === 'POST') {
        $data = read_json_body();
        require_params($data,['document_id','related_type','related_id']);
        $stmt = $conn->prepare("INSERT INTO document_links (document_id, related_type, related_id) VALUES (:did,:rtype,:rid)");
        $stmt->execute([
            ':did'=>$data['document_id'], ':rtype'=>$data['related_type'], ':rid'=>$data['related_id'],
        ]);
        json_ok(['id'=>(int)$conn->lastInsertId()],201);
    }

    if ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) json_err('id is required', 422);
        $stmt = $conn->prepare('DELETE FROM document_links WHERE id = :id');
        $stmt->execute([':id'=>$id]);
        json_ok(['deleted'=>true]);
    }

    json_err('Method not allowed',405);
} catch (Exception $e) {
    json_err($e->getMessage(),400);
}

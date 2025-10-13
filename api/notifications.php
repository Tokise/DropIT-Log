<?php
require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();
$auth = require_auth();
$userId = (int)$auth['id'];

try {
  if ($method === 'GET') {
    $page = max(1,(int)($_GET['page'] ?? 1));
    $limit = min(100, max(1,(int)($_GET['limit'] ?? 20)));
    $offset = ($page-1)*$limit;
    
    // Get notifications for user (direct) or role-based (broadcast)
    $userRole = $auth['role'] ?? 'operator';
    
    $stmt = $conn->prepare('
      SELECT SQL_CALC_FOUND_ROWS * FROM notifications 
      WHERE (user_id = :uid OR (user_id IS NULL AND role = :role) OR (user_id IS NULL AND role IS NULL))
        AND (expires_at IS NULL OR expires_at > NOW())
      ORDER BY created_at DESC 
      LIMIT :limit OFFSET :offset
    ');
    $stmt->bindValue(':uid',$userId,PDO::PARAM_INT);
    $stmt->bindValue(':role',$userRole,PDO::PARAM_STR);
    $stmt->bindValue(':limit',$limit,PDO::PARAM_INT);
    $stmt->bindValue(':offset',$offset,PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total = (int)$conn->query('SELECT FOUND_ROWS()')->fetchColumn();
    json_ok(['items'=>$rows,'page'=>$page,'limit'=>$limit,'total'=>$total]);
  }

  if ($method === 'PUT') {
    $data = read_json_body();
    if (!empty($data['ids']) && is_array($data['ids'])) {
      $in = implode(',', array_fill(0, count($data['ids']), '?'));
      $sql = "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND id IN ($in)";
      $stmt = $conn->prepare($sql);
      $params = array_merge([$userId], array_map('intval',$data['ids']));
      $stmt->execute($params);
      json_ok(['updated'=>true]);
    } else if (!empty($_GET['id'])) {
      $id = (int)$_GET['id'];
      $stmt = $conn->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :uid AND id = :id');
      $stmt->execute([':uid'=>$userId, ':id'=>$id]);
      json_ok(['updated'=>true]);
    } else {
      json_err('No ids provided', 422);
    }
  }

  if ($method === 'DELETE') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) json_err('id is required',422);
    $stmt = $conn->prepare('DELETE FROM notifications WHERE user_id = :uid AND id = :id');
    $stmt->execute([':uid'=>$userId, ':id'=>$id]);
    json_ok(['deleted'=>true]);
  }

  json_err('Method not allowed',405);
} catch (Exception $e) {
  json_err($e->getMessage(),400);
}

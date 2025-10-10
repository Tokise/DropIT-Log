<?php
/**
 * PO Notifications API (for PSM module only)
 * Separate from general notifications to avoid conflicts with other modules
 */

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
    
    // Query po_notifications for user notifications
    $stmt = $conn->prepare('
      SELECT SQL_CALC_FOUND_ROWS 
        n.*, 
        po.po_number,
        n.sent_at as created_at
      FROM po_notifications n
      LEFT JOIN purchase_orders po ON n.po_id = po.id
      WHERE n.recipient_type = \'user\' AND n.recipient_id = :uid
      ORDER BY n.sent_at DESC 
      LIMIT :limit OFFSET :offset
    ');
    $stmt->bindValue(':uid',$userId,PDO::PARAM_INT);
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
      $sql = "UPDATE po_notifications SET is_read = 1, read_at = NOW() WHERE recipient_type = 'user' AND recipient_id = ? AND id IN ($in)";
      $stmt = $conn->prepare($sql);
      $params = array_merge([$userId], array_map('intval',$data['ids']));
      $stmt->execute($params);
      json_ok(['updated'=>true]);
    } else if (!empty($_GET['id'])) {
      $id = (int)$_GET['id'];
      $stmt = $conn->prepare('UPDATE po_notifications SET is_read = 1, read_at = NOW() WHERE recipient_type = \'user\' AND recipient_id = :uid AND id = :id');
      $stmt->execute([':uid'=>$userId, ':id'=>$id]);
      json_ok(['updated'=>true]);
    } else {
      json_err('No ids provided', 422);
    }
  }

  if ($method === 'DELETE') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) json_err('id is required',422);
    $stmt = $conn->prepare('DELETE FROM po_notifications WHERE recipient_type = \'user\' AND recipient_id = :uid AND id = :id');
    $stmt->execute([':uid'=>$userId, ':id'=>$id]);
    json_ok(['deleted'=>true]);
  }

  json_err('Method not allowed',405);
} catch (Exception $e) {
  json_err($e->getMessage(),400);
}
?>

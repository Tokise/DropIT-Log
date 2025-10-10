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
        $shipmentId = isset($_GET['shipment_id']) ? (int)$_GET['shipment_id'] : 0;
        if ($shipmentId <= 0) json_err('shipment_id is required', 422);
        $page = max(1,(int)($_GET['page'] ?? 1));
        $limit = min(100,max(1,(int)($_GET['limit'] ?? 50)));
        $offset = ($page-1)*$limit;
        $stmt = $conn->prepare("SELECT SQL_CALC_FOUND_ROWS * FROM tracking_events WHERE shipment_id = :sid ORDER BY event_time DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':sid',$shipmentId,PDO::PARAM_INT);
        $stmt->bindValue(':limit',$limit,PDO::PARAM_INT);
        $stmt->bindValue(':offset',$offset,PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total = (int)$conn->query('SELECT FOUND_ROWS()')->fetchColumn();
        json_ok(['items'=>$rows,'page'=>$page,'limit'=>$limit,'total'=>$total]);
    }

    if ($method === 'POST') {
        $data = read_json_body();
        require_params($data,['shipment_id','event_type','event_time']);
        $stmt = $conn->prepare("INSERT INTO tracking_events (shipment_id, event_type, location, notes, event_time) VALUES (:sid,:type,:location,:notes,:etime)");
        $stmt->execute([
            ':sid'=>$data['shipment_id'], ':type'=>$data['event_type'], ':location'=>$data['location'] ?? null,
            ':notes'=>$data['notes'] ?? null, ':etime'=>$data['event_time'],
        ]);
        $id = (int)$conn->lastInsertId();
        log_audit('tracking_event', $id, 'create', $authUserId, json_encode($data));
        notify_module_users($MODULE, 'Tracking event added', "Shipment #{$data['shipment_id']} received a new event", 'plt.php');
        json_ok(['id'=>$id],201);
    }

    if ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) json_err('id is required', 422);
        $stmt = $conn->prepare('DELETE FROM tracking_events WHERE id = :id');
        $stmt->execute([':id'=>$id]);
        log_audit('tracking_event', $id, 'delete', $authUserId, null);
        notify_module_users($MODULE, 'Tracking event deleted', "Event #$id has been deleted", 'plt.php');
        json_ok(['deleted'=>true]);
    }

    json_err('Method not allowed',405);
} catch (Exception $e) {
    json_err($e->getMessage(),400);
}

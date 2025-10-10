<?php
// Common helpers for API endpoints
session_start();
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../config/database.php';

function cors_headers() {
    // CORS headers are already set at the top of this file
    // This function exists for compatibility
}

function db_conn() {
    static $conn = null;
    if ($conn === null) {
        $db = new Database();
        $conn = $db->getConnection();
    }
    return $conn;
}

function json_ok($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}

function json_err($message, $code = 400) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

function read_json_body() {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function require_params($arr, $keys) {
    foreach ($keys as $k) {
        if (!isset($arr[$k]) || $arr[$k] === '') {
            json_err("Missing parameter: $k", 422);
        }
    }
}

function current_user() {
    return isset($_SESSION['user']) ? $_SESSION['user'] : null;
}

function require_auth() {
    $u = current_user();
    if (!$u) json_err('Unauthorized', 401);
    return $u;
}

// If user is admin, only allow read (GET/OPTIONS/HEAD)
function admin_block_mutations() {
    $u = require_auth();
    $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (($u['role'] ?? '') === 'admin' && !in_array($m, ['GET','HEAD','OPTIONS'])) {
        json_err('Admins are read-only. Operation not permitted.', 403);
    }
}

function log_audit($entity_type, $entity_id, $action, $user_id, $changes = null) {
    try {
        $conn = db_conn();
        $stmt = $conn->prepare('INSERT INTO audit_logs (entity_type, entity_id, action, user_id, changes_json) VALUES (:etype, :eid, :action, :uid, :changes_json)');
        $stmt->execute([
            'etype' => $entity_type,
            'eid' => $entity_id,
            'action' => $action,
            'uid' => $user_id,
            'changes_json' => $changes
        ]);
    } catch (Exception $e) { /* swallow to not break main op */ }
}

function notify_module_users($module, $title, $message, $action_url = null, $include_admin = true) {
    try {
        $conn = db_conn();
        $cond = $include_admin ? "module = :m OR role = 'admin'" : 'module = :m';
        $stmt = $conn->prepare("SELECT id FROM users WHERE is_active = 1 AND ($cond)");
        $stmt->execute([':m' => $module]);
        $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        error_log("notify_module_users: module=$module, found " . count($userIds) . " users");
        
        if (!$userIds) return;
        $ins = $conn->prepare('INSERT INTO notifications (user_id, type, title, message, action_url) VALUES (:uid, :type, :title, :msg, :url)');
        foreach ($userIds as $uid) {
            $ins->execute(['uid' => $uid, 'type' => 'info', 'title' => $title, 'msg' => $message, 'url' => $action_url]);
            error_log("Created notification for user $uid: $title");
        }
    } catch (Exception $e) { 
        error_log("notify_module_users error: " . $e->getMessage());
    }
}



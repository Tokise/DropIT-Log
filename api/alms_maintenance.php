<?php
require_once 'common.php';

header('Content-Type: application/json');

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    $auth = require_auth();
    
    switch ($method) {
        case 'GET':
            if (isset($_GET['id'])) {
                getMaintenanceDetails($_GET['id']);
            } elseif ($action === 'due') {
                getDueMaintenance();
            } elseif ($action === 'calendar') {
                getMaintenanceCalendar();
            } else {
                getMaintenance();
            }
            break;
            
        case 'POST':
            if ($action === 'schedule') {
                scheduleMaintenance();
            } else {
                scheduleMaintenance();
            }
            break;
            
        case 'PUT':
            if ($action === 'complete') {
                completeMaintenance();
            } elseif ($action === 'reschedule') {
                rescheduleMaintenance();
            } else {
                updateMaintenance();
            }
            break;
            
        case 'DELETE':
            deleteMaintenance($_GET['id'] ?? 0);
            break;
            
        default:
            json_err('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    json_err('Server error: ' . $e->getMessage(), 500);
}

function getMaintenance() {
    $conn = db_conn();
    
    $page = (int)($_GET['page'] ?? 1);
    $limit = (int)($_GET['limit'] ?? 20);
    $offset = ($page - 1) * $limit;
    
    $status = $_GET['status'] ?? '';
    $asset_id = $_GET['asset_id'] ?? '';
    $priority = $_GET['priority'] ?? '';
    $maintenance_type = $_GET['maintenance_type'] ?? '';
    
    $where = "WHERE 1=1";
    $params = [];
    
    if ($status) {
        $where .= " AND am.status = :status";
        $params[':status'] = $status;
    }
    
    if ($asset_id) {
        $where .= " AND am.asset_id = :asset_id";
        $params[':asset_id'] = $asset_id;
    }
    
    if ($priority) {
        $where .= " AND am.priority = :priority";
        $params[':priority'] = $priority;
    }
    
    if ($maintenance_type) {
        $where .= " AND am.maintenance_type = :maintenance_type";
        $params[':maintenance_type'] = $maintenance_type;
    }
    
    $sql = "
        SELECT 
            am.*,
            a.asset_code,
            a.asset_name,
            a.asset_type,
            u1.full_name as performed_by_name,
            u2.full_name as created_by_name,
            DATEDIFF(am.scheduled_date, CURDATE()) as days_until_due
        FROM asset_maintenance am
        JOIN assets a ON am.asset_id = a.id
        LEFT JOIN users u1 ON am.performed_by = u1.id
        LEFT JOIN users u2 ON am.created_by = u2.id
        $where
        ORDER BY am.scheduled_date ASC
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $maintenance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Mark overdue items
    foreach ($maintenance as &$item) {
        if ($item['status'] === 'scheduled' && $item['days_until_due'] < 0) {
            $item['status'] = 'overdue';
            // Update in database
            $updateStmt = $conn->prepare("UPDATE asset_maintenance SET status = 'overdue' WHERE id = :id");
            $updateStmt->execute([':id' => $item['id']]);
        }
    }
    
    // Get total count
    $countSql = "SELECT COUNT(*) FROM asset_maintenance am JOIN assets a ON am.asset_id = a.id $where";
    $countStmt = $conn->prepare($countSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = $countStmt->fetchColumn();
    
    json_ok([
        'maintenance' => $maintenance,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => (int)$total,
            'pages' => ceil($total / $limit)
        ]
    ]);
}

function getMaintenanceDetails($id) {
    $conn = db_conn();
    
    $stmt = $conn->prepare("
        SELECT 
            am.*,
            a.asset_code,
            a.asset_name,
            a.asset_type,
            a.manufacturer,
            a.model,
            u1.full_name as performed_by_name,
            u2.full_name as created_by_name
        FROM asset_maintenance am
        JOIN assets a ON am.asset_id = a.id
        LEFT JOIN users u1 ON am.performed_by = u1.id
        LEFT JOIN users u2 ON am.created_by = u2.id
        WHERE am.id = :id
    ");
    $stmt->execute([':id' => $id]);
    $maintenance = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$maintenance) {
        json_err('Maintenance record not found', 404);
    }
    
    json_ok($maintenance);
}

function getDueMaintenance() {
    $conn = db_conn();
    
    $days_ahead = (int)($_GET['days'] ?? 7);
    
    $sql = "
        SELECT 
            am.*,
            a.asset_code,
            a.asset_name,
            a.asset_type,
            DATEDIFF(am.scheduled_date, CURDATE()) as days_until_due
        FROM asset_maintenance am
        JOIN assets a ON am.asset_id = a.id
        WHERE am.status IN ('scheduled', 'overdue')
        AND am.scheduled_date <= DATE_ADD(CURDATE(), INTERVAL :days_ahead DAY)
        ORDER BY am.scheduled_date ASC
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([':days_ahead' => $days_ahead]);
    $dueMaintenance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    json_ok(['due_maintenance' => $dueMaintenance]);
}

function getMaintenanceCalendar() {
    $conn = db_conn();
    
    $start_date = $_GET['start'] ?? date('Y-m-01');
    $end_date = $_GET['end'] ?? date('Y-m-t');
    
    $sql = "
        SELECT 
            am.id,
            am.title,
            am.scheduled_date as start,
            am.scheduled_date as end,
            am.status,
            am.priority,
            am.maintenance_type,
            a.asset_code,
            a.asset_name
        FROM asset_maintenance am
        JOIN assets a ON am.asset_id = a.id
        WHERE am.scheduled_date BETWEEN :start_date AND :end_date
        ORDER BY am.scheduled_date ASC
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':start_date' => $start_date,
        ':end_date' => $end_date
    ]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format for calendar
    foreach ($events as &$event) {
        $event['title'] = $event['asset_code'] . ': ' . $event['title'];
        $event['color'] = match($event['priority']) {
            'critical' => '#dc3545',
            'high' => '#fd7e14',
            'medium' => '#ffc107',
            'low' => '#28a745',
            default => '#6c757d'
        };
    }
    
    json_ok(['events' => $events]);
}

function scheduleMaintenance() {
    $conn = db_conn();
    $auth = require_auth();
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        json_err('Invalid JSON data', 400);
    }
    
    // Validate required fields
    $required = ['asset_id', 'maintenance_type', 'title', 'scheduled_date'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            json_err("Field '$field' is required", 400);
        }
    }
    
    $stmt = $conn->prepare("
        INSERT INTO asset_maintenance (
            asset_id, maintenance_type, title, description, scheduled_date,
            status, priority, cost, performed_by, vendor, notes, created_by
        ) VALUES (
            :asset_id, :maintenance_type, :title, :description, :scheduled_date,
            :status, :priority, :cost, :performed_by, :vendor, :notes, :created_by
        )
    ");
    
    $stmt->execute([
        ':asset_id' => $data['asset_id'],
        ':maintenance_type' => $data['maintenance_type'],
        ':title' => $data['title'],
        ':description' => $data['description'] ?? null,
        ':scheduled_date' => $data['scheduled_date'],
        ':status' => 'scheduled',
        ':priority' => $data['priority'] ?? 'medium',
        ':cost' => $data['estimated_cost'] ?? 0,
        ':performed_by' => $data['performed_by'] ?? null,
        ':vendor' => $data['vendor'] ?? null,
        ':notes' => $data['notes'] ?? null,
        ':created_by' => $auth['id']
    ]);
    
    $maintenanceId = $conn->lastInsertId();
    
    // Update asset's next maintenance date if this is preventive maintenance
    if ($data['maintenance_type'] === 'preventive') {
        $stmt = $conn->prepare("
            SELECT maintenance_interval_days FROM assets WHERE id = :id
        ");
        $stmt->execute([':id' => $data['asset_id']]);
        $interval = $stmt->fetchColumn() ?: 90;
        
        $nextDate = date('Y-m-d', strtotime($data['scheduled_date'] . " +$interval days"));
        
        $stmt = $conn->prepare("
            UPDATE assets 
            SET next_maintenance_date = :next_date
            WHERE id = :id
        ");
        $stmt->execute([
            ':next_date' => $nextDate,
            ':id' => $data['asset_id']
        ]);
    }
    
    json_ok([
        'maintenance_id' => $maintenanceId,
        'message' => 'Maintenance scheduled successfully'
    ]);
}

function completeMaintenance() {
    $conn = db_conn();
    $auth = require_auth();
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['maintenance_id'])) {
        json_err('Maintenance ID required', 400);
    }
    
    $conn->beginTransaction();
    
    try {
        // Update maintenance record
        $stmt = $conn->prepare("
            UPDATE asset_maintenance 
            SET status = 'completed',
                completed_date = :completed_date,
                cost = :actual_cost,
                performed_by = :performed_by,
                vendor = :vendor,
                parts_replaced = :parts_replaced,
                downtime_hours = :downtime_hours,
                notes = :notes,
                attachments = :attachments
            WHERE id = :id
        ");
        
        $completedDate = $data['completed_date'] ?? date('Y-m-d');
        $partsReplaced = isset($data['parts_replaced']) ? json_encode($data['parts_replaced']) : null;
        $attachments = isset($data['attachments']) ? json_encode($data['attachments']) : null;
        
        $stmt->execute([
            ':completed_date' => $completedDate,
            ':actual_cost' => $data['actual_cost'] ?? 0,
            ':performed_by' => $data['performed_by'] ?? $auth['id'],
            ':vendor' => $data['vendor'] ?? null,
            ':parts_replaced' => $partsReplaced,
            ':downtime_hours' => $data['downtime_hours'] ?? 0,
            ':notes' => $data['notes'] ?? null,
            ':attachments' => $attachments,
            ':id' => $data['maintenance_id']
        ]);
        
        // Get asset ID
        $stmt = $conn->prepare("SELECT asset_id FROM asset_maintenance WHERE id = :id");
        $stmt->execute([':id' => $data['maintenance_id']]);
        $assetId = $stmt->fetchColumn();
        
        // Update asset's last maintenance date
        $stmt = $conn->prepare("
            UPDATE assets 
            SET last_maintenance_date = :last_date,
                status = CASE WHEN status = 'maintenance' THEN 'active' ELSE status END
            WHERE id = :id
        ");
        $stmt->execute([
            ':last_date' => $completedDate,
            ':id' => $assetId
        ]);
        
        $conn->commit();
        
        json_ok([
            'message' => 'Maintenance completed successfully',
            'completed_at' => $completedDate
        ]);
        
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}

function rescheduleMaintenance() {
    $conn = db_conn();
    $auth = require_auth();
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['maintenance_id']) || !isset($data['new_date'])) {
        json_err('Maintenance ID and new date required', 400);
    }
    
    $stmt = $conn->prepare("
        UPDATE asset_maintenance 
        SET scheduled_date = :new_date,
            status = 'scheduled',
            notes = CONCAT(COALESCE(notes, ''), ' [Rescheduled from original date]')
        WHERE id = :id AND status IN ('scheduled', 'overdue')
    ");
    
    $stmt->execute([
        ':new_date' => $data['new_date'],
        ':id' => $data['maintenance_id']
    ]);
    
    if ($stmt->rowCount() === 0) {
        json_err('Maintenance not found or cannot be rescheduled', 404);
    }
    
    json_ok(['message' => 'Maintenance rescheduled successfully']);
}

function updateMaintenance() {
    $conn = db_conn();
    $auth = require_auth();
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['id'])) {
        json_err('Maintenance ID required', 400);
    }
    
    $updates = [];
    $params = [':id' => $data['id']];
    
    $allowedFields = [
        'title', 'description', 'scheduled_date', 'priority', 
        'cost', 'performed_by', 'vendor', 'notes'
    ];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = :$field";
            $params[":$field"] = $data[$field];
        }
    }
    
    if (empty($updates)) {
        json_err('No valid fields to update', 400);
    }
    
    $sql = "UPDATE asset_maintenance SET " . implode(', ', $updates) . " WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    
    json_ok(['message' => 'Maintenance updated successfully']);
}

function deleteMaintenance($id) {
    $conn = db_conn();
    $auth = require_auth();
    
    if (!$id) {
        json_err('Maintenance ID required', 400);
    }
    
    // Check if maintenance can be deleted (only scheduled/overdue)
    $stmt = $conn->prepare("SELECT status FROM asset_maintenance WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $status = $stmt->fetchColumn();
    
    if (!$status) {
        json_err('Maintenance record not found', 404);
    }
    
    if (!in_array($status, ['scheduled', 'overdue'])) {
        json_err('Only scheduled or overdue maintenance can be deleted', 400);
    }
    
    $stmt = $conn->prepare("UPDATE asset_maintenance SET status = 'cancelled' WHERE id = :id");
    $stmt->execute([':id' => $id]);
    
    json_ok(['message' => 'Maintenance cancelled successfully']);
}
?>

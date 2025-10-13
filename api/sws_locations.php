<?php
/**
 * SWS Locations API - SIMPLIFIED
 * Manages zones and storage locations
 */

require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();
$auth = require_auth();

try {
    $action = $_GET['action'] ?? 'list';
    
    // GET - List zones or locations
    if ($method === 'GET') {
        if ($action === 'zones') {
            $warehouse_id = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 1;
            
            $stmt = $conn->prepare("
                SELECT 
                    z.*,
                    w.name as warehouse_name,
                    COUNT(l.id) as location_count
                FROM warehouse_zones z
                LEFT JOIN warehouses w ON z.warehouse_id = w.id
                LEFT JOIN warehouse_locations l ON z.id = l.zone_id
                WHERE z.warehouse_id = :wid AND z.is_active = 1
                GROUP BY z.id
                ORDER BY z.zone_code
            ");
            $stmt->execute([':wid' => $warehouse_id]);
            $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            json_ok(['items' => $zones]);
        }
        
        if ($action === 'locations') {
            $zone_id = isset($_GET['zone_id']) ? (int)$_GET['zone_id'] : 0;
            
            if ($zone_id > 0) {
                $stmt = $conn->prepare("
                    SELECT 
                        l.*,
                        z.zone_code,
                        z.zone_name,
                        (l.capacity_units - l.current_units) as available_units,
                        ROUND((l.current_units / l.capacity_units) * 100, 1) as utilization_percent
                    FROM warehouse_locations l
                    JOIN warehouse_zones z ON l.zone_id = z.id
                    WHERE l.zone_id = :zid AND l.is_active = 1
                    ORDER BY l.location_code
                ");
                $stmt->execute([':zid' => $zone_id]);
            } else {
                $stmt = $conn->query("
                    SELECT 
                        l.*,
                        z.zone_code,
                        z.zone_name,
                        (l.capacity_units - l.current_units) as available_units,
                        ROUND((l.current_units / l.capacity_units) * 100, 1) as utilization_percent
                    FROM warehouse_locations l
                    JOIN warehouse_zones z ON l.zone_id = z.id
                    WHERE l.is_active = 1
                    ORDER BY z.zone_code, l.location_code
                ");
            }
            
            $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            json_ok(['items' => $locations]);
        }
        
        if ($action === 'tree') {
            // Get full tree structure
            $warehouse_id = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 1;
            
            $stmt = $conn->prepare("
                SELECT * FROM warehouse_zones 
                WHERE warehouse_id = :wid AND is_active = 1 
                ORDER BY zone_code
            ");
            $stmt->execute([':wid' => $warehouse_id]);
            $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($zones as &$zone) {
                $stmt = $conn->prepare("
                    SELECT * FROM warehouse_locations 
                    WHERE zone_id = :zid AND is_active = 1 
                    ORDER BY location_code
                ");
                $stmt->execute([':zid' => $zone['id']]);
                $zone['locations'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            json_ok(['zones' => $zones]);
        }
    }
    
    // POST - Create zone or location
    if ($method === 'POST') {
        $data = read_json_body();
        
        if ($action === 'zone') {
            require_params($data, ['warehouse_id', 'zone_code', 'zone_name']);
            
            $stmt = $conn->prepare("
                INSERT INTO warehouse_zones 
                (warehouse_id, zone_code, zone_name, zone_type) 
                VALUES (:wid, :code, :name, :type)
            ");
            $stmt->execute([
                ':wid' => (int)$data['warehouse_id'],
                ':code' => $data['zone_code'],
                ':name' => $data['zone_name'],
                ':type' => $data['zone_type'] ?? 'storage'
            ]);
            
            $id = (int)$conn->lastInsertId();
            log_audit('warehouse_zone', $id, 'create', $auth['id']);
            
            json_ok(['id' => $id, 'message' => 'Zone created'], 201);
        }
        
        if ($action === 'location') {
            require_params($data, ['zone_id', 'location_code']);
            
            $stmt = $conn->prepare("
                INSERT INTO warehouse_locations 
                (zone_id, location_code, location_name, capacity_units) 
                VALUES (:zid, :code, :name, :capacity)
            ");
            $stmt->execute([
                ':zid' => (int)$data['zone_id'],
                ':code' => $data['location_code'],
                ':name' => $data['location_name'] ?? null,
                ':capacity' => (int)($data['capacity_units'] ?? 100)
            ]);
            
            $id = (int)$conn->lastInsertId();
            log_audit('warehouse_location', $id, 'create', $auth['id']);
            
            json_ok(['id' => $id, 'message' => 'Location created'], 201);
        }
    }
    
    // DELETE - Remove zone or location
    if ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) json_err('id required', 422);
        
        if ($action === 'zone') {
            $stmt = $conn->prepare("UPDATE warehouse_zones SET is_active = 0 WHERE id = :id");
            $stmt->execute([':id' => $id]);
            log_audit('warehouse_zone', $id, 'delete', $auth['id']);
            json_ok(['message' => 'Zone deleted']);
        }
        
        if ($action === 'location') {
            $stmt = $conn->prepare("UPDATE warehouse_locations SET is_active = 0 WHERE id = :id");
            $stmt->execute([':id' => $id]);
            log_audit('warehouse_location', $id, 'delete', $auth['id']);
            json_ok(['message' => 'Location deleted']);
        }
    }
    
    json_err('Invalid action', 400);
    
} catch (Exception $e) {
    json_err($e->getMessage(), 500);
}

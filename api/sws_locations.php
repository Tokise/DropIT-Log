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
                    COUNT(DISTINCT wa.id) as aisle_count,
                    COUNT(DISTINCT wb.id) as bin_count,
                    COALESCE(SUM(wb.current_units), 0) as total_current_units,
                    COALESCE(SUM(wb.capacity_units), 0) as total_capacity_units,
                    CASE 
                        WHEN SUM(wb.capacity_units) > 0 
                        THEN ROUND((SUM(wb.current_units) / SUM(wb.capacity_units)) * 100, 1)
                        ELSE 0 
                    END as utilization_percent
                FROM warehouse_zones z
                LEFT JOIN warehouses w ON z.warehouse_id = w.id
                LEFT JOIN warehouse_aisles wa ON z.id = wa.zone_id AND wa.is_active = 1
                LEFT JOIN warehouse_racks wr ON wa.id = wr.aisle_id AND wr.is_active = 1
                LEFT JOIN warehouse_bins wb ON wr.id = wb.rack_id AND wb.is_active = 1
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
            // Get full tree structure with aisles, racks, and bins
            $warehouse_id = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 1;
            
            $stmt = $conn->prepare("
                SELECT 
                    z.*,
                    COUNT(DISTINCT wa.id) as aisle_count,
                    COUNT(DISTINCT wb.id) as bin_count,
                    COALESCE(SUM(wb.current_units), 0) as total_current_units,
                    COALESCE(SUM(wb.capacity_units), 0) as total_capacity_units
                FROM warehouse_zones z
                LEFT JOIN warehouse_aisles wa ON z.id = wa.zone_id AND wa.is_active = 1
                LEFT JOIN warehouse_racks wr ON wa.id = wr.aisle_id AND wr.is_active = 1
                LEFT JOIN warehouse_bins wb ON wr.id = wb.rack_id AND wb.is_active = 1
                WHERE z.warehouse_id = :wid AND z.is_active = 1 
                GROUP BY z.id
                ORDER BY z.zone_code
            ");
            $stmt->execute([':wid' => $warehouse_id]);
            $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($zones as &$zone) {
                // Get aisles for this zone
                $stmt = $conn->prepare("
                    SELECT 
                        wa.*,
                        COUNT(DISTINCT wb.id) as bin_count,
                        COALESCE(SUM(wb.current_units), 0) as current_units,
                        COALESCE(SUM(wb.capacity_units), 0) as capacity_units,
                        CASE 
                            WHEN SUM(wb.capacity_units) > 0 
                            THEN ROUND((SUM(wb.current_units) / SUM(wb.capacity_units)) * 100, 1)
                            ELSE 0 
                        END as utilization_percent
                    FROM warehouse_aisles wa
                    LEFT JOIN warehouse_racks wr ON wa.id = wr.aisle_id AND wr.is_active = 1
                    LEFT JOIN warehouse_bins wb ON wr.id = wb.rack_id AND wb.is_active = 1
                    WHERE wa.zone_id = :zid AND wa.is_active = 1 
                    GROUP BY wa.id
                    ORDER BY wa.aisle_code
                ");
                $stmt->execute([':zid' => $zone['id']]);
                $zone['aisles'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // For backward compatibility, also provide locations array
                $zone['locations'] = $zone['aisles'];
            }
            
            json_ok(['zones' => $zones]);
        }
    }
    
    // POST - Create zone, location, or aisle
    if ($method === 'POST') {
        $data = read_json_body();
        
        if ($action === 'create_aisle') {
            // Create aisle with racks and bins
            require_params($data, ['zone_id', 'aisle_code', 'aisle_name']);
            
            $conn->beginTransaction();
            
            try {
                // Create aisle
                $stmt = $conn->prepare("
                    INSERT INTO warehouse_aisles 
                    (zone_id, aisle_code, aisle_name, description, is_active, created_at, updated_at)
                    VALUES (:zone_id, :code, :name, :description, 1, NOW(), NOW())
                ");
                $stmt->execute([
                    ':zone_id' => $data['zone_id'],
                    ':code' => $data['aisle_code'],
                    ':name' => $data['aisle_name'],
                    ':description' => $data['aisle_name'] . ' storage aisle'
                ]);
                $aisleId = $conn->lastInsertId();
                
                // Create racks
                $rackCount = $data['rack_count'] ?? 2;
                $binsPerRack = $data['bins_per_rack'] ?? 12;
                $binCapacity = $data['bin_capacity'] ?? 50;
                
                for ($r = 1; $r <= $rackCount; $r++) {
                    $rackCode = 'R' . str_pad($r, 2, '0', STR_PAD_LEFT);
                    
                    $stmt = $conn->prepare("
                        INSERT INTO warehouse_racks 
                        (aisle_id, rack_code, rack_name, levels, is_active, created_at, updated_at)
                        VALUES (:aisle_id, :code, :name, 4, 1, NOW(), NOW())
                    ");
                    $stmt->execute([
                        ':aisle_id' => $aisleId,
                        ':code' => $rackCode,
                        ':name' => "Rack {$rackCode}"
                    ]);
                    $rackId = $conn->lastInsertId();
                    
                    // Create bins
                    for ($b = 1; $b <= $binsPerRack; $b++) {
                        $binCode = 'B' . str_pad($b, 3, '0', STR_PAD_LEFT);
                        
                        $stmt = $conn->prepare("
                            INSERT INTO warehouse_bins 
                            (rack_id, bin_code, bin_name, capacity_units, current_units, is_active, created_at, updated_at)
                            VALUES (:rack_id, :code, :name, :capacity, 0, 1, NOW(), NOW())
                        ");
                        $stmt->execute([
                            ':rack_id' => $rackId,
                            ':code' => $binCode,
                            ':name' => "Bin {$binCode}",
                            ':capacity' => $binCapacity
                        ]);
                    }
                }
                
                $conn->commit();
                json_ok([
                    'success' => true, 
                    'message' => "Aisle {$data['aisle_code']} created with {$rackCount} racks and " . ($rackCount * $binsPerRack) . " bins",
                    'aisle_id' => $aisleId
                ]);
                
            } catch (Exception $e) {
                $conn->rollBack();
                json_err('Failed to create aisle: ' . $e->getMessage(), 500);
            }
        }
        
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

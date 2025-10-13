<?php
/**
 * Warehouse Location Management API
 * Handles zones, aisles, racks, and bins
 */
require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();
$auth = require_auth();
$authUserId = (int)$auth['id'];

try {
    if ($method === 'GET') {
        $type = $_GET['type'] ?? 'zones';
        $warehouse_id = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 1;
        $parent_id = isset($_GET['parent_id']) ? (int)$_GET['parent_id'] : null;
        
        switch ($type) {
            case 'zones':
                $stmt = $conn->prepare("
                    SELECT wz.*, w.name as warehouse_name,
                           COUNT(DISTINCT wa.id) as aisle_count,
                           COUNT(DISTINCT wr.id) as rack_count,
                           COUNT(DISTINCT wb.id) as bin_count
                    FROM warehouse_zones wz
                    JOIN warehouses w ON wz.warehouse_id = w.id
                    LEFT JOIN warehouse_aisles wa ON wz.id = wa.zone_id AND wa.is_active = 1
                    LEFT JOIN warehouse_racks wr ON wa.id = wr.aisle_id AND wr.is_active = 1
                    LEFT JOIN warehouse_bins wb ON wr.id = wb.rack_id AND wb.is_available = 1
                    WHERE wz.warehouse_id = :warehouse_id AND wz.is_active = 1
                    GROUP BY wz.id
                    ORDER BY wz.zone_code
                ");
                $stmt->execute([':warehouse_id' => $warehouse_id]);
                $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
                json_ok(['items' => $zones, 'type' => 'zones']);
                break;
                
            case 'aisles':
                $where = ['wa.is_active = 1'];
                $params = [];
                
                if ($parent_id) {
                    $where[] = 'wa.zone_id = :zone_id';
                    $params[':zone_id'] = $parent_id;
                } else {
                    $where[] = 'wz.warehouse_id = :warehouse_id';
                    $params[':warehouse_id'] = $warehouse_id;
                }
                
                $whereClause = implode(' AND ', $where);
                
                $stmt = $conn->prepare("
                    SELECT wa.*, wz.zone_code, wz.zone_name,
                           COUNT(DISTINCT wr.id) as rack_count,
                           COUNT(DISTINCT wb.id) as bin_count
                    FROM warehouse_aisles wa
                    JOIN warehouse_zones wz ON wa.zone_id = wz.id
                    LEFT JOIN warehouse_racks wr ON wa.id = wr.aisle_id AND wr.is_active = 1
                    LEFT JOIN warehouse_bins wb ON wr.id = wb.rack_id AND wb.is_available = 1
                    WHERE $whereClause
                    GROUP BY wa.id
                    ORDER BY wa.aisle_code
                ");
                $stmt->execute($params);
                $aisles = $stmt->fetchAll(PDO::FETCH_ASSOC);
                json_ok(['items' => $aisles, 'type' => 'aisles']);
                break;
                
            case 'racks':
                $where = ['wr.is_active = 1'];
                $params = [];
                
                if ($parent_id) {
                    $where[] = 'wr.aisle_id = :aisle_id';
                    $params[':aisle_id'] = $parent_id;
                } else {
                    $where[] = 'wz.warehouse_id = :warehouse_id';
                    $params[':warehouse_id'] = $warehouse_id;
                }
                
                $whereClause = implode(' AND ', $where);
                
                $stmt = $conn->prepare("
                    SELECT wr.*, wa.aisle_code, wa.aisle_name, wz.zone_code,
                           COUNT(DISTINCT wb.id) as bin_count,
                           SUM(wb.current_units) as total_units,
                           SUM(wb.current_weight_kg) as total_weight
                    FROM warehouse_racks wr
                    JOIN warehouse_aisles wa ON wr.aisle_id = wa.id
                    JOIN warehouse_zones wz ON wa.zone_id = wz.id
                    LEFT JOIN warehouse_bins wb ON wr.id = wb.rack_id AND wb.is_available = 1
                    WHERE $whereClause
                    GROUP BY wr.id
                    ORDER BY wr.rack_code
                ");
                $stmt->execute($params);
                $racks = $stmt->fetchAll(PDO::FETCH_ASSOC);
                json_ok(['items' => $racks, 'type' => 'racks']);
                break;
                
            case 'bins':
                $where = ['wb.is_available = 1'];
                $params = [];
                
                if ($parent_id) {
                    $where[] = 'wb.rack_id = :rack_id';
                    $params[':rack_id'] = $parent_id;
                } else {
                    $where[] = 'wz.warehouse_id = :warehouse_id';
                    $params[':warehouse_id'] = $warehouse_id;
                }
                
                $whereClause = implode(' AND ', $where);
                
                $stmt = $conn->prepare("
                    SELECT wb.*, wr.rack_code, wa.aisle_code, wz.zone_code,
                           COUNT(DISTINCT il.id) as product_count,
                           SUM(il.quantity) as total_quantity
                    FROM warehouse_bins wb
                    JOIN warehouse_racks wr ON wb.rack_id = wr.id
                    JOIN warehouse_aisles wa ON wr.aisle_id = wa.id
                    JOIN warehouse_zones wz ON wa.zone_id = wz.id
                    LEFT JOIN inventory_locations il ON wb.id = il.bin_id
                    WHERE $whereClause
                    GROUP BY wb.id
                    ORDER BY wb.level, wb.position
                ");
                $stmt->execute($params);
                $bins = $stmt->fetchAll(PDO::FETCH_ASSOC);
                json_ok(['items' => $bins, 'type' => 'bins']);
                break;
                
            case 'inventory':
                $bin_id = isset($_GET['bin_id']) ? (int)$_GET['bin_id'] : null;
                $product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : null;
                
                $where = ['1=1'];
                $params = [];
                
                if ($bin_id) {
                    $where[] = 'il.bin_id = :bin_id';
                    $params[':bin_id'] = $bin_id;
                }
                
                if ($product_id) {
                    $where[] = 'il.product_id = :product_id';
                    $params[':product_id'] = $product_id;
                }
                
                $whereClause = implode(' AND ', $where);
                
                $stmt = $conn->prepare("
                    SELECT il.*, p.name as product_name, p.sku,
                           wb.bin_code, wr.rack_code, wa.aisle_code, wz.zone_code,
                           w.name as warehouse_name
                    FROM inventory_locations il
                    JOIN products p ON il.product_id = p.id
                    JOIN warehouse_bins wb ON il.bin_id = wb.id
                    JOIN warehouse_racks wr ON wb.rack_id = wr.id
                    JOIN warehouse_aisles wa ON wr.aisle_id = wa.id
                    JOIN warehouse_zones wz ON wa.zone_id = wz.id
                    JOIN warehouses w ON il.warehouse_id = w.id
                    WHERE $whereClause
                    ORDER BY wz.zone_code, wa.aisle_code, wr.rack_code, wb.level, wb.position
                ");
                $stmt->execute($params);
                $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
                json_ok(['items' => $inventory, 'type' => 'inventory']);
                break;
                
            default:
                json_err('Invalid type parameter', 400);
        }
    }
    
    if ($method === 'POST') {
        $type = $_GET['type'] ?? 'zones';
        $data = read_json_body();
        
        switch ($type) {
            case 'zones':
                require_params($data, ['warehouse_id', 'zone_code', 'zone_name']);
                
                $stmt = $conn->prepare("
                    INSERT INTO warehouse_zones 
                    (warehouse_id, zone_code, zone_name, zone_type, capacity_cubic_meters)
                    VALUES (:warehouse_id, :zone_code, :zone_name, :zone_type, :capacity_cubic_meters)
                ");
                $stmt->execute([
                    ':warehouse_id' => $data['warehouse_id'],
                    ':zone_code' => $data['zone_code'],
                    ':zone_name' => $data['zone_name'],
                    ':zone_type' => $data['zone_type'] ?? 'storage',
                    ':capacity_cubic_meters' => $data['capacity_cubic_meters'] ?? null
                ]);
                
                $id = (int)$conn->lastInsertId();
                log_audit('warehouse_zone', $id, 'create', $authUserId, json_encode($data));
                json_ok(['id' => $id, 'message' => 'Zone created successfully'], 201);
                break;
                
            case 'aisles':
                require_params($data, ['zone_id', 'aisle_code']);
                
                $stmt = $conn->prepare("
                    INSERT INTO warehouse_aisles 
                    (zone_id, aisle_code, aisle_name, description)
                    VALUES (:zone_id, :aisle_code, :aisle_name, :description)
                ");
                $stmt->execute([
                    ':zone_id' => $data['zone_id'],
                    ':aisle_code' => $data['aisle_code'],
                    ':aisle_name' => $data['aisle_name'] ?? null,
                    ':description' => $data['description'] ?? null
                ]);
                
                $id = (int)$conn->lastInsertId();
                log_audit('warehouse_aisle', $id, 'create', $authUserId, json_encode($data));
                json_ok(['id' => $id, 'message' => 'Aisle created successfully'], 201);
                break;
                
            case 'racks':
                require_params($data, ['aisle_id', 'rack_code']);
                
                $stmt = $conn->prepare("
                    INSERT INTO warehouse_racks 
                    (aisle_id, rack_code, rack_name, levels, width_cm, depth_cm, height_cm, capacity_weight_kg)
                    VALUES (:aisle_id, :rack_code, :rack_name, :levels, :width_cm, :depth_cm, :height_cm, :capacity_weight_kg)
                ");
                $stmt->execute([
                    ':aisle_id' => $data['aisle_id'],
                    ':rack_code' => $data['rack_code'],
                    ':rack_name' => $data['rack_name'] ?? null,
                    ':levels' => $data['levels'] ?? 5,
                    ':width_cm' => $data['width_cm'] ?? null,
                    ':depth_cm' => $data['depth_cm'] ?? null,
                    ':height_cm' => $data['height_cm'] ?? null,
                    ':capacity_weight_kg' => $data['capacity_weight_kg'] ?? null
                ]);
                
                $id = (int)$conn->lastInsertId();
                log_audit('warehouse_rack', $id, 'create', $authUserId, json_encode($data));
                json_ok(['id' => $id, 'message' => 'Rack created successfully'], 201);
                break;
                
            case 'bins':
                require_params($data, ['rack_id', 'bin_code', 'level', 'position']);
                
                $stmt = $conn->prepare("
                    INSERT INTO warehouse_bins 
                    (rack_id, bin_code, level, position, capacity_units, capacity_weight_kg, bin_type)
                    VALUES (:rack_id, :bin_code, :level, :position, :capacity_units, :capacity_weight_kg, :bin_type)
                ");
                $stmt->execute([
                    ':rack_id' => $data['rack_id'],
                    ':bin_code' => $data['bin_code'],
                    ':level' => $data['level'],
                    ':position' => $data['position'],
                    ':capacity_units' => $data['capacity_units'] ?? 100,
                    ':capacity_weight_kg' => $data['capacity_weight_kg'] ?? null,
                    ':bin_type' => $data['bin_type'] ?? 'shelf'
                ]);
                
                $id = (int)$conn->lastInsertId();
                log_audit('warehouse_bin', $id, 'create', $authUserId, json_encode($data));
                json_ok(['id' => $id, 'message' => 'Bin created successfully'], 201);
                break;
                
            default:
                json_err('Invalid type parameter', 400);
        }
    }
    
    if ($method === 'PUT') {
        $type = $_GET['type'] ?? 'zones';
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $data = read_json_body();
        
        if ($id <= 0) {
            json_err('id is required', 422);
        }
        
        $tableMap = [
            'zones' => 'warehouse_zones',
            'aisles' => 'warehouse_aisles',
            'racks' => 'warehouse_racks',
            'bins' => 'warehouse_bins'
        ];
        
        $table = $tableMap[$type] ?? null;
        if (!$table) {
            json_err('Invalid type parameter', 400);
        }
        
        $allowedFields = [];
        switch ($type) {
            case 'zones':
                $allowedFields = ['zone_code', 'zone_name', 'zone_type', 'capacity_cubic_meters', 'is_active'];
                break;
            case 'aisles':
                $allowedFields = ['aisle_code', 'aisle_name', 'description', 'is_active'];
                break;
            case 'racks':
                $allowedFields = ['rack_code', 'rack_name', 'levels', 'width_cm', 'depth_cm', 'height_cm', 'capacity_weight_kg', 'is_active'];
                break;
            case 'bins':
                $allowedFields = ['bin_code', 'level', 'position', 'capacity_units', 'capacity_weight_kg', 'bin_type', 'is_available', 'is_reserved', 'reserved_for'];
                break;
        }
        
        $updates = [];
        $params = [':id' => $id];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }
        
        if (empty($updates)) {
            json_err('No fields to update', 422);
        }
        
        $sql = "UPDATE $table SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        
        log_audit($table, $id, 'update', $authUserId, json_encode($data));
        json_ok(['message' => ucfirst($type) . ' updated successfully']);
    }
    
    if ($method === 'DELETE') {
        $type = $_GET['type'] ?? 'zones';
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        
        if ($id <= 0) {
            json_err('id is required', 422);
        }
        
        $tableMap = [
            'zones' => 'warehouse_zones',
            'aisles' => 'warehouse_aisles',
            'racks' => 'warehouse_racks',
            'bins' => 'warehouse_bins'
        ];
        
        $table = $tableMap[$type] ?? null;
        if (!$table) {
            json_err('Invalid type parameter', 400);
        }
        
        // Check for dependencies before deletion
        switch ($type) {
            case 'zones':
                $stmt = $conn->prepare("SELECT COUNT(*) FROM warehouse_aisles WHERE zone_id = :id");
                $stmt->execute([':id' => $id]);
                if ($stmt->fetchColumn() > 0) {
                    json_err('Cannot delete zone with existing aisles', 400);
                }
                break;
            case 'aisles':
                $stmt = $conn->prepare("SELECT COUNT(*) FROM warehouse_racks WHERE aisle_id = :id");
                $stmt->execute([':id' => $id]);
                if ($stmt->fetchColumn() > 0) {
                    json_err('Cannot delete aisle with existing racks', 400);
                }
                break;
            case 'racks':
                $stmt = $conn->prepare("SELECT COUNT(*) FROM warehouse_bins WHERE rack_id = :id");
                $stmt->execute([':id' => $id]);
                if ($stmt->fetchColumn() > 0) {
                    json_err('Cannot delete rack with existing bins', 400);
                }
                break;
            case 'bins':
                $stmt = $conn->prepare("SELECT COUNT(*) FROM inventory_locations WHERE bin_id = :id");
                $stmt->execute([':id' => $id]);
                if ($stmt->fetchColumn() > 0) {
                    json_err('Cannot delete bin with existing inventory', 400);
                }
                break;
        }
        
        $stmt = $conn->prepare("DELETE FROM $table WHERE id = :id");
        $stmt->execute([':id' => $id]);
        
        log_audit($table, $id, 'delete', $authUserId, null);
        json_ok(['message' => ucfirst($type) . ' deleted successfully']);
    }
    
    json_err('Method not allowed', 405);
    
} catch (Exception $e) {
    json_err($e->getMessage(), 500);
}

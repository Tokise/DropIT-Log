<?php
/**
 * Warehouse Management API
 * Handle warehouse CRUD operations
 */

require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();
$auth = require_auth();

try {
    if ($method === 'GET') {
        // Get all warehouses
        $stmt = $conn->prepare("
            SELECT 
                w.id, w.name, w.code, w.address, w.city, w.phone, w.email, 
                w.manager_name, w.is_active, w.created_at, w.updated_at,
                COUNT(DISTINCT wz.id) as zone_count,
                COUNT(DISTINCT i.product_id) as product_count,
                COALESCE(SUM(i.quantity), 0) as total_inventory
            FROM warehouses w
            LEFT JOIN warehouse_zones wz ON w.id = wz.warehouse_id
            LEFT JOIN inventory i ON w.id = i.warehouse_id
            WHERE w.is_active = 1
            GROUP BY w.id
            ORDER BY w.name
        ");
        $stmt->execute();
        $warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        json_ok(['items' => $warehouses, 'total' => count($warehouses)]);
    }
    
    if ($method === 'POST') {
        // Create new warehouse
        $data = read_json_body();
        require_params($data, ['name', 'code']);
        
        // Check if code already exists
        $stmt = $conn->prepare("SELECT id FROM warehouses WHERE code = :code");
        $stmt->execute([':code' => $data['code']]);
        if ($stmt->fetchColumn()) {
            json_err('Warehouse code already exists', 400);
        }
        
        $conn->beginTransaction();
        
        try {
            // Create warehouse
            $stmt = $conn->prepare("
                INSERT INTO warehouses 
                (name, code, address, city, phone, email, manager_name, is_active, created_at, updated_at)
                VALUES 
                (:name, :code, :address, :city, :phone, :email, :manager_name, 1, NOW(), NOW())
            ");
            $stmt->execute([
                ':name' => $data['name'],
                ':code' => strtoupper($data['code']),
                ':address' => $data['address'] ?? null,
                ':city' => $data['city'] ?? null,
                ':phone' => $data['phone'] ?? null,
                ':email' => $data['email'] ?? null,
                ':manager_name' => $data['manager_name'] ?? null
            ]);
            
            $warehouseId = $conn->lastInsertId();
            
            // Create default zones if requested
            if (isset($data['create_default_zones']) && $data['create_default_zones']) {
                $defaultZones = [
                    ['code' => 'RCV', 'name' => 'Receiving Zone', 'type' => 'receiving'],
                    ['code' => 'STG', 'name' => 'Storage Zone', 'type' => 'storage'],
                    ['code' => 'PKG', 'name' => 'Packing Zone', 'type' => 'packing'],
                    ['code' => 'SHP', 'name' => 'Shipping Zone', 'type' => 'shipping']
                ];
                
                $zoneStmt = $conn->prepare("
                    INSERT INTO warehouse_zones 
                    (warehouse_id, zone_code, zone_name, zone_type, is_active, created_at, updated_at)
                    VALUES (:warehouse_id, :code, :name, :type, 1, NOW(), NOW())
                ");
                
                foreach ($defaultZones as $zone) {
                    $zoneStmt->execute([
                        ':warehouse_id' => $warehouseId,
                        ':code' => $zone['code'],
                        ':name' => $zone['name'],
                        ':type' => $zone['type']
                    ]);
                }
            }
            
            $conn->commit();
            
            json_ok([
                'success' => true,
                'warehouse_id' => $warehouseId,
                'message' => 'Warehouse created successfully'
            ]);
            
        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    }
    
    if ($method === 'PUT') {
        // Update warehouse
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) json_err('Warehouse ID required', 400);
        
        $data = read_json_body();
        
        $stmt = $conn->prepare("
            UPDATE warehouses 
            SET name = :name, code = :code, address = :address, city = :city,
                phone = :phone, email = :email, manager_name = :manager_name,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $id,
            ':name' => $data['name'],
            ':code' => strtoupper($data['code']),
            ':address' => $data['address'] ?? null,
            ':city' => $data['city'] ?? null,
            ':phone' => $data['phone'] ?? null,
            ':email' => $data['email'] ?? null,
            ':manager_name' => $data['manager_name'] ?? null
        ]);
        
        json_ok(['success' => true, 'message' => 'Warehouse updated successfully']);
    }
    
    if ($method === 'DELETE') {
        // Soft delete warehouse
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) json_err('Warehouse ID required', 400);
        
        // Check if warehouse has inventory
        $stmt = $conn->prepare("SELECT COUNT(*) FROM inventory WHERE warehouse_id = :id");
        $stmt->execute([':id' => $id]);
        if ($stmt->fetchColumn() > 0) {
            json_err('Cannot delete warehouse with existing inventory', 400);
        }
        
        $stmt = $conn->prepare("UPDATE warehouses SET is_active = 0, updated_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $id]);
        
        json_ok(['success' => true, 'message' => 'Warehouse deleted successfully']);
    }
    
    json_err('Method not allowed', 405);
    
} catch (Exception $e) {
    json_err($e->getMessage(), 500);
}
?>

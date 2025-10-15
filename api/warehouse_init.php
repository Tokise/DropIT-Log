<?php
/**
 * Warehouse Initialization Functions
 * Auto-creates warehouse structure and handles location assignments
 */

function ensureWarehouseStructure(PDO $conn, $warehouseId = null) {
    // Get or create main warehouse
    if (!$warehouseId) {
        $stmt = $conn->prepare("SELECT id FROM warehouses WHERE is_active = 1 LIMIT 1");
        $stmt->execute();
        $warehouseId = $stmt->fetchColumn();
        
        if (!$warehouseId) {
            $stmt = $conn->prepare("INSERT INTO warehouses (name, code, is_active) VALUES ('Main Warehouse', 'WH001', 1)");
            $stmt->execute();
            $warehouseId = $conn->lastInsertId();
        }
    }
    
    // Check if we have any bins for this warehouse
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM warehouse_bins wb
        JOIN warehouse_racks wr ON wb.rack_id = wr.id
        JOIN warehouse_aisles wa ON wr.aisle_id = wa.id
        JOIN warehouse_zones wz ON wa.zone_id = wz.id
        WHERE wz.warehouse_id = ?
    ");
    $stmt->execute([$warehouseId]);
    $binCount = $stmt->fetchColumn();
    
    if ($binCount > 0) {
        return $warehouseId; // Structure already exists
    }
    
    // Create minimal warehouse structure
    try {
        // Create or get zone
        $stmt = $conn->prepare("SELECT id FROM warehouse_zones WHERE warehouse_id = ? AND zone_code = 'MAIN'");
        $stmt->execute([$warehouseId]);
        $zoneId = $stmt->fetchColumn();
        
        if (!$zoneId) {
            $stmt = $conn->prepare("INSERT INTO warehouse_zones (warehouse_id, zone_code, zone_name, zone_type) VALUES (?, 'MAIN', 'Main Storage', 'storage')");
            $stmt->execute([$warehouseId]);
            $zoneId = $conn->lastInsertId();
        }
        
        // Create or get aisle
        $stmt = $conn->prepare("SELECT id FROM warehouse_aisles WHERE zone_id = ? AND aisle_code = 'A1'");
        $stmt->execute([$zoneId]);
        $aisleId = $stmt->fetchColumn();
        
        if (!$aisleId) {
            $stmt = $conn->prepare("INSERT INTO warehouse_aisles (zone_id, aisle_code, aisle_name) VALUES (?, 'A1', 'Aisle A1')");
            $stmt->execute([$zoneId]);
            $aisleId = $conn->lastInsertId();
        }
        
        // Create or get rack
        $stmt = $conn->prepare("SELECT id FROM warehouse_racks WHERE aisle_id = ? AND rack_code = 'R1'");
        $stmt->execute([$aisleId]);
        $rackId = $stmt->fetchColumn();
        
        if (!$rackId) {
            $stmt = $conn->prepare("INSERT INTO warehouse_racks (aisle_id, rack_code, rack_name, levels) VALUES (?, 'R1', 'Rack R1', 4)");
            $stmt->execute([$aisleId]);
            $rackId = $conn->lastInsertId();
        }
        
        // Create default bins
        $stmt = $conn->prepare("SELECT COUNT(*) FROM warehouse_bins WHERE rack_id = ?");
        $stmt->execute([$rackId]);
        $existingBins = $stmt->fetchColumn();
        
        if ($existingBins == 0) {
            for ($level = 1; $level <= 4; $level++) {
                for ($pos = 1; $pos <= 3; $pos++) {
                    $binCode = "B{$level}{$pos}";
                    $binName = "Bin Level {$level} Position {$pos}";
                    $stmt = $conn->prepare("INSERT INTO warehouse_bins (rack_id, bin_code, bin_name, level, position, capacity_units, current_units) VALUES (?, ?, ?, ?, ?, 1000, 0)");
                    $stmt->execute([$rackId, $binCode, $binName, $level, $pos]);
                }
            }
        }
        
    } catch (Exception $e) {
        error_log("Warehouse structure creation failed: " . $e->getMessage());
    }
    
    return $warehouseId;
}

function autoAssignToLocation(PDO $conn, $productId, $warehouseId, $quantity) {
    // Ensure warehouse structure exists
    ensureWarehouseStructure($conn, $warehouseId);
    
    // Check if product already has a location assignment
    $stmt = $conn->prepare("SELECT COUNT(*) FROM inventory_locations WHERE product_id = ? AND warehouse_id = ?");
    $stmt->execute([$productId, $warehouseId]);
    $hasLocation = $stmt->fetchColumn();
    
    if ($hasLocation > 0) {
        return true; // Already assigned
    }
    
    // Find first available bin with enough capacity
    $stmt = $conn->prepare("
        SELECT wb.id, wb.bin_code, wb.capacity_units, wb.current_units
        FROM warehouse_bins wb
        JOIN warehouse_racks wr ON wb.rack_id = wr.id
        JOIN warehouse_aisles wa ON wr.aisle_id = wa.id
        JOIN warehouse_zones wz ON wa.zone_id = wz.id
        WHERE wz.warehouse_id = ? 
        AND wb.is_active = 1
        AND (wb.capacity_units - wb.current_units) >= ?
        ORDER BY wb.current_units ASC, wb.id ASC
        LIMIT 1
    ");
    $stmt->execute([$warehouseId, $quantity]);
    $bin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$bin) {
        // No available bin, use first bin regardless of capacity
        $stmt = $conn->prepare("
            SELECT wb.id, wb.bin_code
            FROM warehouse_bins wb
            JOIN warehouse_racks wr ON wb.rack_id = wr.id
            JOIN warehouse_aisles wa ON wr.aisle_id = wa.id
            JOIN warehouse_zones wz ON wa.zone_id = wz.id
            WHERE wz.warehouse_id = ? 
            AND wb.is_active = 1
            ORDER BY wb.id ASC
            LIMIT 1
        ");
        $stmt->execute([$warehouseId]);
        $bin = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if ($bin) {
        // Assign to location
        $stmt = $conn->prepare("
            INSERT INTO inventory_locations 
            (product_id, warehouse_id, bin_id, quantity, last_moved_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                quantity = quantity + ?,
                last_moved_at = NOW()
        ");
        $stmt->execute([$productId, $warehouseId, $bin['id'], $quantity, $quantity]);
        
        // Update bin capacity
        $stmt = $conn->prepare("UPDATE warehouse_bins SET current_units = current_units + ? WHERE id = ?");
        $stmt->execute([$quantity, $bin['id']]);
        
        return true;
    }
    
    return false;
}

function syncInventoryWithLocations(PDO $conn, $productId = null, $warehouseId = null) {
    $where = [];
    $params = [];
    
    if ($productId) {
        $where[] = 'i.product_id = ?';
        $params[] = $productId;
    }
    
    if ($warehouseId) {
        $where[] = 'i.warehouse_id = ?';
        $params[] = $warehouseId;
    }
    
    $whereClause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);
    
    // Get inventory that doesn't match location totals
    $stmt = $conn->prepare("
        SELECT 
            i.product_id, 
            i.warehouse_id, 
            i.quantity as inventory_qty,
            COALESCE(SUM(il.quantity), 0) as location_qty
        FROM inventory i
        LEFT JOIN inventory_locations il ON i.product_id = il.product_id AND i.warehouse_id = il.warehouse_id
        $whereClause
        GROUP BY i.product_id, i.warehouse_id, i.quantity
        HAVING inventory_qty != location_qty
    ");
    $stmt->execute($params);
    $mismatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($mismatches as $mismatch) {
        if ($mismatch['location_qty'] > 0) {
            // Update inventory to match locations
            $stmt = $conn->prepare("UPDATE inventory SET quantity = ? WHERE product_id = ? AND warehouse_id = ?");
            $stmt->execute([$mismatch['location_qty'], $mismatch['product_id'], $mismatch['warehouse_id']]);
        } else if ($mismatch['inventory_qty'] > 0) {
            // Auto-assign inventory to locations
            autoAssignToLocation($conn, $mismatch['product_id'], $mismatch['warehouse_id'], $mismatch['inventory_qty']);
        }
    }
}
?>

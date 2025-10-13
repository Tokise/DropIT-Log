<?php
/**
 * SWS Shipments API
 * Handles incoming shipments from PSM and outgoing shipments to PLT
 */

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../services/AIWarehouseService.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();
$auth = require_auth();

try {
    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'list';
        
        if ($action === 'list') {
            // Get incoming shipments from PSM
            $status = $_GET['status'] ?? null;
            $warehouse_id = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : null;
            
            $where = ['1=1'];
            $params = [];
            
            if ($status) {
                $where[] = 'po.status = :status';
                $params[':status'] = $status;
            }
            
            if ($warehouse_id) {
                $where[] = 'po.delivery_warehouse_id = :warehouse_id';
                $params[':warehouse_id'] = $warehouse_id;
            }
            
            $whereClause = implode(' AND ', $where);
            
            $stmt = $conn->prepare("
                SELECT 
                    po.id,
                    po.po_number,
                    po.status,
                    po.order_date,
                    po.expected_delivery_date,
                    po.total_amount,
                    s.name as supplier_name,
                    w.name as warehouse_name,
                    u.username as created_by_name,
                    COUNT(poi.id) as item_count,
                    SUM(poi.quantity) as total_items,
                    SUM(poi.received_quantity) as received_items,
                    CASE 
                        WHEN SUM(poi.quantity) = SUM(poi.received_quantity) THEN 'complete'
                        WHEN SUM(poi.received_quantity) > 0 THEN 'partial'
                        ELSE 'pending'
                    END as receiving_status
                FROM purchase_orders po
                LEFT JOIN suppliers s ON po.supplier_id = s.id
                LEFT JOIN warehouses w ON po.delivery_warehouse_id = w.id
                LEFT JOIN users u ON po.created_by = u.id
                LEFT JOIN purchase_order_items poi ON po.id = poi.purchase_order_id
                WHERE $whereClause
                GROUP BY po.id
                ORDER BY po.expected_delivery_date DESC, po.created_at DESC
                LIMIT 50
            ");
            
            $stmt->execute($params);
            $shipments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            json_ok(['items' => $shipments, 'total' => count($shipments)]);
        }
        
        if ($action === 'details') {
            // Get shipment details
            $po_id = isset($_GET['po_id']) ? (int)$_GET['po_id'] : 0;
            
            if ($po_id <= 0) {
                json_err('po_id is required', 422);
            }
            
            // Get PO header
            $stmt = $conn->prepare("
                SELECT 
                    po.*,
                    s.name as supplier_name,
                    s.contact_person,
                    s.phone,
                    s.email,
                    w.name as warehouse_name,
                    w.address as warehouse_address
                FROM purchase_orders po
                LEFT JOIN suppliers s ON po.supplier_id = s.id
                LEFT JOIN warehouses w ON po.delivery_warehouse_id = w.id
                WHERE po.id = :po_id
            ");
            $stmt->execute([':po_id' => $po_id]);
            $po = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$po) {
                json_err('Purchase order not found', 404);
            }
            
            // Get PO items
            $stmt = $conn->prepare("
                SELECT 
                    poi.*,
                    p.name as product_name,
                    p.sku,
                    p.barcode,
                    p.weight_kg,
                    p.dimensions_cm,
                    (poi.quantity - poi.received_quantity) as pending_quantity
                FROM purchase_order_items poi
                JOIN products p ON poi.product_id = p.id
                WHERE poi.purchase_order_id = :po_id
                ORDER BY p.name
            ");
            $stmt->execute([':po_id' => $po_id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $po['items'] = $items;
            
            json_ok($po);
        }
    }
    
    if ($method === 'POST') {
        $action = $_GET['action'] ?? 'receive';
        $data = read_json_body();
        
        if ($action === 'receive') {
            // Receive shipment items
            require_params($data, ['po_id', 'items']);
            
            $po_id = (int)$data['po_id'];
            $items = $data['items']; // Array of {product_id, quantity, bin_id (optional)}
            $use_ai = isset($data['use_ai']) ? (bool)$data['use_ai'] : true;
            
            $conn->beginTransaction();
            
            try {
                // Get PO details
                $stmt = $conn->prepare("
                    SELECT delivery_warehouse_id, status 
                    FROM purchase_orders 
                    WHERE id = :po_id
                ");
                $stmt->execute([':po_id' => $po_id]);
                $po = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$po) {
                    throw new Exception('Purchase order not found');
                }
                
                if (!in_array($po['status'], ['approved', 'in_transit'])) {
                    throw new Exception('Purchase order is not ready for receiving');
                }
                
                $warehouse_id = (int)$po['delivery_warehouse_id'];
                $aiService = new AIWarehouseService($conn);
                
                foreach ($items as $item) {
                    $product_id = (int)$item['product_id'];
                    $quantity = (int)$item['quantity'];
                    $bin_id = isset($item['bin_id']) ? (int)$item['bin_id'] : null;
                    $batch_number = $item['batch_number'] ?? null;
                    $expiry_date = $item['expiry_date'] ?? null;
                    
                    // If no bin_id and AI enabled, get AI suggestion
                    $location_id = null;
                    if (!$bin_id && $use_ai) {
                        try {
                            $aiResult = $aiService->suggestOptimalLocation($product_id, $quantity, $warehouse_id);
                            $location_id = $aiResult['location_id'] ?? null;
                        } catch (Exception $e) {
                            error_log("AI suggestion failed: " . $e->getMessage());
                        }
                    } else if ($bin_id) {
                        $location_id = $bin_id; // Use manual location
                    }
                    
                    // Update main inventory
                    $stmt = $conn->prepare("
                        INSERT INTO inventory (product_id, warehouse_id, quantity, updated_at)
                        VALUES (:product_id, :warehouse_id, :quantity, NOW())
                        ON DUPLICATE KEY UPDATE 
                            quantity = quantity + :quantity,
                            updated_at = NOW()
                    ");
                    $stmt->execute([
                        ':product_id' => $product_id,
                        ':warehouse_id' => $warehouse_id,
                        ':quantity' => $quantity
                    ]);
                    
                    // Assign to location if available
                    if ($location_id) {
                        $stmt = $conn->prepare("
                            INSERT INTO inventory_locations
                            (product_id, location_id, quantity, batch_number, expiry_date)
                            VALUES (:product_id, :location_id, :quantity, :batch_number, :expiry_date)
                            ON DUPLICATE KEY UPDATE
                                quantity = quantity + :quantity
                        ");
                        $stmt->execute([
                            ':product_id' => $product_id,
                            ':location_id' => $location_id,
                            ':quantity' => $quantity,
                            ':batch_number' => $batch_number,
                            ':expiry_date' => $expiry_date
                        ]);
                        
                        // Update location capacity
                        $stmt = $conn->prepare("
                            UPDATE warehouse_locations 
                            SET current_units = current_units + :quantity
                            WHERE id = :location_id
                        ");
                        $stmt->execute([
                            ':quantity' => $quantity,
                            ':location_id' => $location_id
                        ]);
                    }
                    
                    // Update PO item received quantity
                    $stmt = $conn->prepare("
                        UPDATE purchase_order_items
                        SET received_quantity = received_quantity + :quantity
                        WHERE purchase_order_id = :po_id AND product_id = :product_id
                    ");
                    $stmt->execute([
                        ':quantity' => $quantity,
                        ':po_id' => $po_id,
                        ':product_id' => $product_id
                    ]);
                    
                    // Log transaction
                    $stmt = $conn->prepare("
                        INSERT INTO inventory_transactions
                        (product_id, warehouse_id, transaction_type, quantity,
                         reference_type, reference_id, notes, performed_by)
                        VALUES (:product_id, :warehouse_id, 'receipt', :quantity,
                                'purchase_order', :po_id, :notes, :user_id)
                    ");
                    $notes = "Received from PO #$po_id";
                    if ($location_id) {
                        $notes .= " - Assigned to location ID: $location_id";
                    }
                    $stmt->execute([
                        ':product_id' => $product_id,
                        ':warehouse_id' => $warehouse_id,
                        ':quantity' => $quantity,
                        ':po_id' => $po_id,
                        ':notes' => $notes,
                        ':user_id' => $auth['id']
                    ]);
                }
                
                // Check if PO is fully received
                $stmt = $conn->prepare("
                    SELECT 
                        SUM(quantity) as total_qty,
                        SUM(received_quantity) as received_qty
                    FROM purchase_order_items
                    WHERE purchase_order_id = :po_id
                ");
                $stmt->execute([':po_id' => $po_id]);
                $totals = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($totals['total_qty'] == $totals['received_qty']) {
                    // Mark PO as completed
                    $stmt = $conn->prepare("
                        UPDATE purchase_orders
                        SET status = 'completed',
                            actual_delivery_date = NOW()
                        WHERE id = :po_id
                    ");
                    $stmt->execute([':po_id' => $po_id]);
                } else {
                    // Mark as partially received
                    $stmt = $conn->prepare("
                        UPDATE purchase_orders
                        SET status = 'partially_received'
                        WHERE id = :po_id
                    ");
                    $stmt->execute([':po_id' => $po_id]);
                }
                
                $conn->commit();
                
                log_audit('purchase_order', $po_id, 'receive', $auth['id'], json_encode($data));
                
                json_ok([
                    'success' => true,
                    'message' => 'Shipment received successfully',
                    'po_id' => $po_id,
                    'items_received' => count($items)
                ], 201);
                
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }
        }
        
        if ($action === 'prepare_outbound') {
            // Prepare outbound shipment for PLT
            require_params($data, ['sales_order_id']);
            
            $sales_order_id = (int)$data['sales_order_id'];
            
            // Get sales order items
            $stmt = $conn->prepare("
                SELECT 
                    soi.product_id,
                    soi.quantity,
                    p.name as product_name,
                    p.sku
                FROM sales_order_items soi
                JOIN products p ON soi.product_id = p.id
                WHERE soi.sales_order_id = :sales_order_id
            ");
            $stmt->execute([':sales_order_id' => $sales_order_id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($items)) {
                json_err('No items found for sales order', 404);
            }
            
            // Get AI-optimized picking route
            $aiService = new AIWarehouseService($conn);
            $routeResult = $aiService->generatePickingRoute($items);
            
            json_ok([
                'success' => true,
                'sales_order_id' => $sales_order_id,
                'items' => $items,
                'picking_route' => $routeResult['route'] ?? [],
                'estimated_time' => $routeResult['estimated_time'] ?? null,
                'reasoning' => $routeResult['reasoning'] ?? null
            ]);
        }
    }
    
    json_err('Method not allowed', 405);
    
} catch (Exception $e) {
    json_err($e->getMessage(), 500);
}

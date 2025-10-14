<?php
require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();

function po_recalc_total(PDO $conn, int $poId) {
    // First try the new po_items table
    $total = (float)$conn->query("SELECT COALESCE(SUM(total_price),0) FROM po_items WHERE po_id = " . (int)$poId)->fetchColumn();
    
    // If no items in new table, try legacy table
    if ($total == 0) {
        $total = (float)$conn->query("SELECT COALESCE(SUM(quantity*unit_price),0) FROM purchase_order_items WHERE po_id = " . (int)$poId)->fetchColumn();
    }
    
    // Update the PO with the calculated total
    $stmt = $conn->prepare("UPDATE purchase_orders SET total_amount = :t WHERE id = :id");
    $stmt->execute([':t' => $total, ':id' => $poId]);
    
    // Also update subtotal, tax_amount if needed
    $poStmt = $conn->prepare("SELECT supplier_id FROM purchase_orders WHERE id = :id");
    $poStmt->execute([':id' => $poId]);
    $supplierId = $poStmt->fetchColumn();
    
    if ($supplierId) {
        $supplierStmt = $conn->prepare("SELECT charges_tax, tax_rate FROM suppliers WHERE id = :id");
        $supplierStmt->execute([':id' => $supplierId]);
        $supplier = $supplierStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($supplier) {
            $subtotal = $total;
            $taxAmount = 0;
            $taxRate = 0;
            
            if ($supplier['charges_tax'] && $supplier['tax_rate'] > 0) {
                // If tax is inclusive, calculate subtotal
                if ($supplier['tax_rate'] > 0) {
                    $taxRate = $supplier['tax_rate'];
                    $subtotal = $total / (1 + $taxRate);
                    $taxAmount = $total - $subtotal;
                }
            }
            
            $updateStmt = $conn->prepare("UPDATE purchase_orders SET subtotal = :subtotal, tax_rate = :tax_rate, tax_amount = :tax_amount WHERE id = :id");
            $updateStmt->execute([
                ':subtotal' => $subtotal,
                ':tax_rate' => $taxRate,
                ':tax_amount' => $taxAmount,
                ':id' => $poId
            ]);
        }
    }
    
    return $total;
}

try {
    if ($method === 'GET') {
        // Disable caching
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id > 0) {
            $stmt = $conn->prepare("SELECT po.*, s.name AS supplier_name, w.name AS warehouse_name FROM purchase_orders po JOIN suppliers s ON po.supplier_id=s.id JOIN warehouses w ON po.warehouse_id=w.id WHERE po.id = :id");
            $stmt->execute([':id' => $id]);
            $po = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$po) json_err('PO not found', 404);
            
            // Get items - prioritize new po_items table with supplier products
            $items = $conn->prepare("
                SELECT 
                    poi.id,
                    poi.product_name as name,
                    poi.quantity,
                    poi.unit_price,
                    poi.total_price,
                    poi.received_quantity,
                    spc.product_code as sku,
                    spc.category,
                    spc.unit_of_measure
                FROM po_items poi
                LEFT JOIN supplier_products_catalog spc ON poi.supplier_product_id = spc.id
                WHERE poi.po_id = :id
            ");
            $items->execute([':id' => $id]);
            $po['items'] = $items->fetchAll(PDO::FETCH_ASSOC);
            
            // Guarantee 'name', 'product_name', 'sku' fields for all items (new table)
            foreach ($po['items'] as &$item) {
                if ((empty($item['name']) || $item['name'] === 'undefined') && !empty($item['product_name'])) {
                    $item['name'] = $item['product_name'];
                } elseif ((empty($item['product_name']) || $item['product_name'] === 'undefined') && !empty($item['name'])) {
                    $item['product_name'] = $item['name'];
                }
                if (empty($item['name']) || $item['name'] === 'undefined') {
                    $item['name'] = 'Unknown';
                }
                if (empty($item['product_name']) || $item['product_name'] === 'undefined') {
                    $item['product_name'] = 'Unknown';
                }
                if (empty($item['sku']) || $item['sku'] === 'undefined') {
                    $item['sku'] = 'N/A';
                }
            }
            unset($item);
            
            // Fallback to legacy table if no items found
            if (empty($po['items'])) {
                $items = $conn->prepare("SELECT poi.*, p.sku, p.name FROM purchase_order_items poi JOIN products p ON poi.product_id=p.id WHERE poi.po_id = :id");
                $items->execute([':id' => $id]);
                $po['items'] = $items->fetchAll(PDO::FETCH_ASSOC);
                // Guarantee fields for legacy table
                foreach ($po['items'] as &$item) {
                    if (empty($item['name']) && !empty($item['product_name'])) {
                        $item['name'] = $item['product_name'];
                    } elseif (empty($item['product_name']) && !empty($item['name'])) {
                        $item['product_name'] = $item['name'];
                    }
                    if (empty($item['sku'])) {
                        $item['sku'] = 'N/A';
                    }
                }
                unset($item);
            }
            
            json_ok($po);
        } else {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;
            $status = $_GET['status'] ?? null;
            $where = [];
            $params = [];

            if ($status === 'archived') {
                $where[] = 'po.is_archived = 1';
            } else {
                $where[] = 'po.is_archived = 0';
                if ($status !== null && $status !== '') {
                    $where[] = 'po.status = :status';
                    $params[':status'] = $status;
                }
            }
            $whereSql = empty($where)?'':'WHERE '.implode(' AND ',$where);
            $sql = "SELECT SQL_CALC_FOUND_ROWS po.*, s.name AS supplier_name, w.name AS warehouse_name
                    FROM purchase_orders po
                    JOIN suppliers s ON po.supplier_id=s.id
                    JOIN warehouses w ON po.warehouse_id=w.id
                    $whereSql
                    ORDER BY po.created_at DESC LIMIT :limit OFFSET :offset";
            $stmt = $conn->prepare($sql);
            foreach ($params as $k=>$v) { $stmt->bindValue($k,$v); }
            $stmt->bindValue(':limit',$limit,PDO::PARAM_INT);
            $stmt->bindValue(':offset',$offset,PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total = (int)$conn->query('SELECT FOUND_ROWS()')->fetchColumn();
            
            // Debug: log what we're returning
            error_log("PO API: Fetched " . count($rows) . " POs with status filter: " . ($status ?? 'none'));
            foreach ($rows as $row) {
                error_log("PO: " . $row['po_number'] . " - Status: " . $row['status']);
            }
            
            json_ok(['items'=>$rows,'page'=>$page,'limit'=>$limit,'total'=>$total]);
        }
    }

    if ($method === 'POST') {
        $data = read_json_body();
        $auth = require_auth();
        $authUserId = (int)$auth['id'];
        
        // New enhanced PO creation with items
        require_params($data, ['supplier_id']);
        $items = $data['items'] ?? [];
        
        // Generate PO number
        $poNumber = 'PO-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        
        // Get default warehouse
        $whStmt = $conn->prepare("SELECT id FROM warehouses WHERE is_active = 1 LIMIT 1");
        $whStmt->execute();
        $warehouseId = $whStmt->fetchColumn();
        if (!$warehouseId) json_err('No active warehouse found', 422);
        
        $conn->beginTransaction();
        try {
            // Create PO with explicit 'draft' status and tax information
            $stmt = $conn->prepare("INSERT INTO purchase_orders (po_number, supplier_id, warehouse_id, status, subtotal, tax_rate, tax_amount, total_amount, is_tax_inclusive, created_by, order_date, expected_delivery_date, ai_predicted_delivery, notes) VALUES (:po_number, :supplier_id, :warehouse_id, :status, :subtotal, :tax_rate, :tax_amount, :total_amount, :is_tax_inclusive, :created_by, :order_date, :expected_delivery_date, :ai_predicted_delivery, :notes)");
            $stmt->execute([
                ':po_number' => $poNumber,
                ':supplier_id' => (int)$data['supplier_id'],
                ':warehouse_id' => (int)$warehouseId,
                ':status' => 'draft',  // Explicit status parameter
                ':subtotal' => (float)($data['subtotal'] ?? 0),
                ':tax_rate' => (float)($data['tax_rate'] ?? 0),
                ':tax_amount' => (float)($data['tax_amount'] ?? 0),
                ':total_amount' => (float)($data['total_amount'] ?? 0),
                ':is_tax_inclusive' => (int)($data['is_tax_inclusive'] ?? 0),
                ':created_by' => $authUserId,
                ':order_date' => $data['order_date'] ?? date('Y-m-d'),
                ':expected_delivery_date' => $data['expected_delivery'] ?? null,
                ':ai_predicted_delivery' => $data['expected_delivery'] ?? null,
                ':notes' => $data['notes'] ?? null,
            ]);
            $poId = (int)$conn->lastInsertId();
            
            // Verify the PO was created with correct status
            $verifyStmt = $conn->prepare("SELECT status FROM purchase_orders WHERE id = :id");
            $verifyStmt->execute([':id' => $poId]);
            $createdStatus = $verifyStmt->fetchColumn();
            
            if ($createdStatus !== 'draft') {
                error_log("WARNING: PO created with status '$createdStatus' instead of 'draft'");
            }
            
            // Insert line items if provided
            if (!empty($items) && is_array($items)) {
                // Check if using supplier products (new way) or regular products (old way for compatibility)
                $useSupplierProducts = isset($items[0]['supplier_product_id']);
                
                if ($useSupplierProducts) {
                    // New way: Using supplier products
                    $ins = $conn->prepare("INSERT INTO po_items (po_id, supplier_product_id, product_name, quantity, unit_price, total_price) VALUES (:po_id, :supplier_product_id, :product_name, :quantity, :unit_price, :total_price)");
                    $legacyIns = $conn->prepare("INSERT INTO purchase_order_items (po_id, product_id, quantity, unit_price, received_quantity) VALUES (:po_id, :product_id, :quantity, :unit_price, 0)");
                    
                    foreach ($items as $it) {
                        if (!isset($it['supplier_product_id'], $it['quantity'], $it['unit_price'])) {
                            $conn->rollBack();
                            json_err('Each item requires supplier_product_id, quantity, unit_price', 422);
                        }
                        
                        // Get product info from supplier_products_catalog
                        $spStmt = $conn->prepare("
                            SELECT product_name, product_code, unit_price, weight_kg, dimensions_cm, barcode, category
                            FROM supplier_products_catalog 
                            WHERE id = :id
                        ");
                        $spStmt->execute([':id' => $it['supplier_product_id']]);
                        $supplierProduct = $spStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if (!$supplierProduct) {
                            $conn->rollBack();
                            json_err('Supplier product not found: ' . $it['supplier_product_id'], 404);
                        }
                        
                        $totalPrice = (float)$it['quantity'] * (float)$it['unit_price'];
                        
                        // Insert into new po_items table
                        $ins->execute([
                            ':po_id' => $poId,
                            ':supplier_product_id' => (int)$it['supplier_product_id'],
                            ':product_name' => $supplierProduct['product_name'],
                            ':quantity' => (float)$it['quantity'],
                            ':unit_price' => (float)$it['unit_price'],
                            ':total_price' => $totalPrice
                        ]);
                        $poItemId = (int)$conn->lastInsertId();
                        
                        // Create or find corresponding product in main products table
                        $sku = $supplierProduct['product_code'] ?: 'AUTO-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                        
                        // Check if product already exists by SKU
                        $existingStmt = $conn->prepare("SELECT id FROM products WHERE sku = :sku");
                        $existingStmt->execute([':sku' => $sku]);
                        $existingProductId = $existingStmt->fetchColumn();
                        
                        if (!$existingProductId) {
                            // Create new product
                            $productStmt = $conn->prepare("
                                INSERT INTO products 
                                (sku, name, description, unit_price, weight_kg, dimensions_cm, barcode, is_active, created_at, updated_at)
                                VALUES 
                                (:sku, :name, :description, :unit_price, :weight_kg, :dimensions_cm, :barcode, 1, NOW(), NOW())
                            ");
                            $productStmt->execute([
                                ':sku' => $sku,
                                ':name' => $supplierProduct['product_name'],
                                ':description' => "Created from PO #{$poNumber}" . ($supplierProduct['category'] ? " - Category: " . $supplierProduct['category'] : ''),
                                ':unit_price' => $supplierProduct['unit_price'] ?: $it['unit_price'],
                                ':weight_kg' => $supplierProduct['weight_kg'],
                                ':dimensions_cm' => $supplierProduct['dimensions_cm'],
                                ':barcode' => $supplierProduct['barcode']
                            ]);
                            $productId = (int)$conn->lastInsertId();
                        } else {
                            $productId = $existingProductId;
                        }
                        
                        // Insert into legacy purchase_order_items table for compatibility
                        $legacyIns->execute([
                            ':po_id' => $poId,
                            ':product_id' => $productId,
                            ':quantity' => (float)$it['quantity'],
                            ':unit_price' => (float)$it['unit_price']
                        ]);
                    }
                } else {
                    // Old way: Using regular products (for backward compatibility)
                    $ins = $conn->prepare("INSERT INTO purchase_order_items (po_id, product_id, quantity, unit_price, received_quantity) VALUES (:po_id, :product_id, :quantity, :unit_price, 0)");
                    foreach ($items as $it) {
                        if (!isset($it['product_id'], $it['quantity'], $it['unit_price'])) {
                            $conn->rollBack();
                            json_err('Each item requires product_id, quantity, unit_price', 422);
                        }
                        $ins->execute([
                            ':po_id' => $poId,
                            ':product_id' => (int)$it['product_id'],
                            ':quantity' => (int)$it['quantity'],
                            ':unit_price' => (float)$it['unit_price'],
                        ]);
                    }
                }
                // Recalculate total
                $total = po_recalc_total($conn, $poId);
            }
            
            // Log status history
            $histStmt = $conn->prepare("INSERT INTO po_status_history (po_id, from_status, to_status, changed_by, changed_by_type, notes) VALUES (:po_id, NULL, 'draft', :changed_by, 'user', 'PO created')");
            $histStmt->execute([':po_id' => $poId, ':changed_by' => $authUserId]);
            
            $conn->commit();
            json_ok(['id' => $poId, 'po_number' => $poNumber, 'total' => $total ?? 0], 201);
        } catch (Exception $e) {
            $conn->rollBack();
            json_err($e->getMessage(), 400);
        }
    }

    if ($method === 'PUT') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) json_err('id is required', 422);
        $data = read_json_body();
        
        // Handle recalculate totals request
        if (isset($data['action']) && $data['action'] === 'recalculate_totals') {
            $total = po_recalc_total($conn, $id);
            json_ok(['total_amount' => $total, 'message' => 'Totals recalculated successfully']);
        }
        
        // Update status or header fields; support receiving items
        if (isset($data['receive'])) {
            // Receive items: [{item_id, qty, performed_by}]
            $receipts = $data['receive'];
            if (!is_array($receipts) || empty($receipts)) json_err('receive array is empty',422);
            $conn->beginTransaction();
            try {
                foreach ($receipts as $r) {
                    require_params($r,['item_id','qty','performed_by']);
                    $qty = (int)$r['qty'];
                    $itemId = (int)$r['item_id'];
                    // Update received qty
                    $stmt = $conn->prepare("UPDATE purchase_order_items SET received_quantity = received_quantity + :q WHERE id = :id");
                    $stmt->execute([':q'=>$qty, ':id'=>$itemId]);
                    // Find product and warehouse
                    $row = $conn->query("SELECT poi.product_id, po.warehouse_id FROM purchase_order_items poi JOIN purchase_orders po ON poi.po_id=po.id WHERE poi.id = " . (int)$itemId)->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $product_id = $row['product_id'];
                        
                        // Ensure product exists in main products table
                        $stmt = $conn->prepare("SELECT id FROM products WHERE id = :product_id");
                        $stmt->execute([':product_id' => $product_id]);
                        $productExists = $stmt->fetchColumn();
                        
                        if (!$productExists) {
                            // Try to get product info from po_items to create the product
                            $stmt = $conn->prepare("
                                SELECT 
                                    poi.product_name,
                                    poi.unit_price,
                                    spc.product_code as sku,
                                    spc.category,
                                    spc.weight_kg,
                                    spc.dimensions_cm,
                                    spc.barcode
                                FROM po_items poi
                                LEFT JOIN supplier_products_catalog spc ON poi.supplier_product_id = spc.id
                                WHERE poi.po_id = :po_id
                                ORDER BY poi.id
                                LIMIT 1
                            ");
                            $stmt->execute([':po_id' => $id]);
                            $productInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($productInfo && !empty($productInfo['product_name'])) {
                                // Generate SKU if not available
                                $sku = $productInfo['sku'] ?: 'AUTO-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                                
                                // Create product in main products table
                                $stmt = $conn->prepare("
                                    INSERT INTO products 
                                    (id, sku, name, description, unit_price, weight_kg, dimensions_cm, 
                                     barcode, is_active, created_at, updated_at)
                                    VALUES 
                                    (:id, :sku, :name, :description, :unit_price, :weight_kg, :dimensions_cm,
                                     :barcode, 1, NOW(), NOW())
                                ");
                                $stmt->execute([
                                    ':id' => $product_id,
                                    ':sku' => $sku,
                                    ':name' => $productInfo['product_name'],
                                    ':description' => "Auto-created from PO #{$id}" . (isset($productInfo['category']) ? " - Category: " . $productInfo['category'] : ''),
                                    ':unit_price' => $productInfo['unit_price'] ?: 0,
                                    ':weight_kg' => $productInfo['weight_kg'] ?? null,
                                    ':dimensions_cm' => $productInfo['dimensions_cm'] ?? null,
                                    ':barcode' => $productInfo['barcode'] ?? null
                                ]);
                            } else {
                                // Fallback: create minimal product record
                                $stmt = $conn->prepare("
                                    INSERT INTO products 
                                    (id, sku, name, description, unit_price, is_active, created_at, updated_at)
                                    VALUES 
                                    (:id, :sku, :name, :description, 0, 1, NOW(), NOW())
                                ");
                                $stmt->execute([
                                    ':id' => $product_id,
                                    ':sku' => 'AUTO-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)),
                                    ':name' => 'Product #' . $product_id,
                                    ':description' => "Auto-created during receiving from PO #{$id}"
                                ]);
                            }
                        }
                        // Adjust inventory and log
                        $stmt = $conn->prepare("SELECT id, quantity FROM inventory WHERE product_id = :pid AND warehouse_id = :wid FOR UPDATE");
                        $stmt->execute([':pid'=>$row['product_id'],':wid'=>$row['warehouse_id']]);
                        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($inv) {
                            $stmt = $conn->prepare("UPDATE inventory SET quantity = quantity + :q, last_restocked_at = NOW(), updated_at = NOW() WHERE id = :id");
                            $stmt->execute([':q'=>$qty, ':id'=>$inv['id']]);
                        } else {
                            $stmt = $conn->prepare("INSERT INTO inventory (product_id, warehouse_id, quantity, reserved_quantity, last_restocked_at) VALUES (:pid,:wid,:q,0,NOW())");
                            $stmt->execute([':pid'=>$row['product_id'],':wid'=>$row['warehouse_id'],':q'=>$qty]);
                        }
                        $log = $conn->prepare("INSERT INTO inventory_transactions (product_id, warehouse_id, transaction_type, quantity, reference_type, reference_id, notes, performed_by) VALUES (:pid,:wid,'receipt',:q,'purchase_order',:po_id,'PO receipt',:uid)");
                        $log->execute([':pid'=>$row['product_id'],':wid'=>$row['warehouse_id'],':q'=>$qty, ':po_id'=>$id, ':uid'=>(int)$r['performed_by']]);
                    }
                }
                // Update PO status heuristically
                $counts = $conn->query("SELECT SUM(quantity) q, SUM(received_quantity) r FROM purchase_order_items WHERE po_id = " . (int)$id)->fetch(PDO::FETCH_ASSOC);
                if ($counts && (int)$counts['r'] >= (int)$counts['q']) {
                    $conn->prepare("UPDATE purchase_orders SET status='received', actual_delivery_date = CURDATE() WHERE id = :id")->execute([':id'=>$id]);
                } else {
                    $conn->prepare("UPDATE purchase_orders SET status='partially_received' WHERE id = :id")->execute([':id'=>$id]);
                }
                $conn->commit();
                json_ok(['received'=>true]);
            } catch (Exception $e) {
                $conn->rollBack();
                json_err($e->getMessage(),400);
            }
        } else {
            // Header update
            $conn->beginTransaction();
            try {
                // Get current PO data
                $stmt = $conn->prepare("SELECT status FROM purchase_orders WHERE id = :id");
                $stmt->execute([':id' => $id]);
                $currentPO = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$currentPO) {
                    json_err('PO not found', 404);
                }
                
                $oldStatus = $currentPO['status'];
                $newStatus = $data['status'] ?? null;
                
                $fields = ['status','approved_by','expected_delivery_date','notes'];
                $sets = [];$params = [':id'=>$id];
                foreach ($fields as $f) { 
                    if (array_key_exists($f,$data)) { 
                        $sets[] = "$f = :$f"; 
                        $params[":$f"] = $data[$f]; 
                    } 
                }
                
                if (empty($sets)) json_err('No fields to update',422);
                
                $sql = 'UPDATE purchase_orders SET '.implode(', ',$sets).' WHERE id = :id';
                $stmt = $conn->prepare($sql); 
                $stmt->execute($params);
                
                // Log status change if status was updated
                if ($newStatus && $newStatus !== $oldStatus) {
                    try {
                        $histStmt = $conn->prepare("INSERT INTO po_status_history (po_id, from_status, to_status, changed_by, changed_by_type, notes) VALUES (:po_id, :from_status, :to_status, :changed_by, 'user', :notes)");
                        $histStmt->execute([
                            ':po_id' => $id,
                            ':from_status' => $oldStatus,
                            ':to_status' => $newStatus,
                            ':changed_by' => $authUserId,
                            ':notes' => $data['notes'] ?? "Status changed from $oldStatus to $newStatus"
                        ]);
                    } catch (Exception $e) {
                        // Continue even if history logging fails
                        error_log("Failed to log status history: " . $e->getMessage());
                    }
                }
                
                $conn->commit();
                json_ok(['updated'=>true, 'old_status' => $oldStatus, 'new_status' => $newStatus]);
            } catch (Exception $e) {
                $conn->rollBack();
                json_err($e->getMessage(), 400);
            }
        }
    }

    json_err('Method not allowed',405);
} catch (Exception $e) {
    json_err($e->getMessage(),400);
}

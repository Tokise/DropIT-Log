<?php
require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();

function so_recalc_total(PDO $conn, int $orderId) {
    $total = (float)$conn->query("SELECT COALESCE(SUM(quantity*unit_price),0) FROM sales_order_items WHERE order_id = " . (int)$orderId)->fetchColumn();
    $stmt = $conn->prepare("UPDATE sales_orders SET total_amount = :t WHERE id = :id");
    $stmt->execute([':t' => $total, ':id' => $orderId]);
    return $total;
}

try {
    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id > 0) {
            $stmt = $conn->prepare("SELECT so.*, w.name as warehouse_name FROM sales_orders so JOIN warehouses w ON so.warehouse_id = w.id WHERE so.id = :id");
            $stmt->execute([':id' => $id]);
            $so = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$so) json_err('Sales Order not found', 404);
            $items = $conn->prepare("SELECT soi.*, p.sku, p.name FROM sales_order_items soi JOIN products p ON soi.product_id=p.id WHERE soi.order_id=:id");
            $items->execute([':id' => $id]);
            $so['items'] = $items->fetchAll(PDO::FETCH_ASSOC);
            json_ok($so);
        } else {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;
            $status = $_GET['status'] ?? null;
            $where = [];$params = [];
            if ($status) { $where[] = 'so.status = :status'; $params[':status'] = $status; }
            $whereSql = empty($where)?'':'WHERE '.implode(' AND ',$where);
            $sql = "SELECT SQL_CALC_FOUND_ROWS so.*, w.name as warehouse_name
                    FROM sales_orders so
                    JOIN warehouses w ON so.warehouse_id = w.id
                    $whereSql
                    ORDER BY so.created_at DESC LIMIT :limit OFFSET :offset";
            $stmt = $conn->prepare($sql);
            foreach ($params as $k=>$v) { $stmt->bindValue($k,$v); }
            $stmt->bindValue(':limit',$limit,PDO::PARAM_INT);
            $stmt->bindValue(':offset',$offset,PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total = (int)$conn->query('SELECT FOUND_ROWS()')->fetchColumn();
            json_ok(['items'=>$rows,'page'=>$page,'limit'=>$limit,'total'=>$total]);
        }
    }

    if ($method === 'POST') {
        // Create SO with items and reserve stock
        $data = read_json_body();
        require_params($data, ['order_number','customer_name','warehouse_id','created_by','items']);
        $items = $data['items'];
        if (!is_array($items) || empty($items)) json_err('items array is required', 422);

        $conn->beginTransaction();
        try {
            $stmt = $conn->prepare("INSERT INTO sales_orders (order_number, customer_name, customer_email, warehouse_id, status, total_amount, shipping_address, created_by) VALUES (:order_number,:customer_name,:customer_email,:warehouse_id,'pending',0,:shipping_address,:created_by)");
            $stmt->execute([
                ':order_number' => $data['order_number'],
                ':customer_name' => $data['customer_name'],
                ':customer_email' => $data['customer_email'] ?? null,
                ':warehouse_id' => (int)$data['warehouse_id'],
                ':shipping_address' => $data['shipping_address'] ?? null,
                ':created_by' => (int)$data['created_by'],
            ]);
            $orderId = (int)$conn->lastInsertId();

            $ins = $conn->prepare("INSERT INTO sales_order_items (order_id, product_id, quantity, unit_price, picked_quantity) VALUES (:order_id,:product_id,:quantity,:unit_price,0)");
            foreach ($items as $it) {
                require_params($it,['product_id','quantity','unit_price']);
                $ins->execute([
                    ':order_id' => $orderId,
                    ':product_id' => (int)$it['product_id'],
                    ':quantity' => (int)$it['quantity'],
                    ':unit_price' => (float)$it['unit_price'],
                ]);
                // Reserve inventory
                $stmt = $conn->prepare("SELECT id, quantity, reserved_quantity FROM inventory WHERE product_id = :pid AND warehouse_id = :wid FOR UPDATE");
                $stmt->execute([':pid' => (int)$it['product_id'], ':wid' => (int)$data['warehouse_id']]);
                $inv = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($inv) {
                    $available = (int)$inv['quantity'] - (int)$inv['reserved_quantity'];
                    if ($available < (int)$it['quantity']) { $conn->rollBack(); json_err('Insufficient stock to reserve', 409); }
                    $conn->prepare("UPDATE inventory SET reserved_quantity = reserved_quantity + :q WHERE id = :id")->execute([':q'=>(int)$it['quantity'], ':id'=>$inv['id']]);
                } else {
                    $conn->rollBack(); json_err('Inventory record missing for product/warehouse', 409);
                }
            }
            $total = so_recalc_total($conn, $orderId);
            $conn->commit();
            json_ok(['id'=>$orderId,'total'=>$total],201);
        } catch (Exception $e) {
            $conn->rollBack();
            json_err($e->getMessage(),400);
        }
    }

    if ($method === 'PUT') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) json_err('id is required', 422);
        $data = read_json_body();
        // Transitions: start picking, packed, ship
        if (isset($data['transition'])) {
            $t = $data['transition'];
            $conn->beginTransaction();
            try {
                if ($t === 'picking') {
                    $conn->prepare("UPDATE sales_orders SET status='picking', assigned_picker = :picker, picked_at = NOW() WHERE id = :id")
                         ->execute([':picker'=>(int)($data['assigned_picker'] ?? 0), ':id'=>$id]);
                } elseif ($t === 'packed') {
                    $conn->prepare("UPDATE sales_orders SET status='packed' WHERE id = :id")
                         ->execute([':id'=>$id]);
                } elseif ($t === 'shipped') {
                    // Deduct inventory, release reservations, log transactions
                    $items = $conn->prepare("SELECT soi.product_id, soi.quantity, so.warehouse_id FROM sales_order_items soi JOIN sales_orders so ON soi.order_id=so.id WHERE soi.order_id = :id");
                    $items->execute([':id'=>$id]);
                    $rows = $items->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rows as $r) {
                        $stmt = $conn->prepare("SELECT id FROM inventory WHERE product_id = :pid AND warehouse_id = :wid FOR UPDATE");
                        $stmt->execute([':pid'=>$r['product_id'], ':wid'=>$r['warehouse_id']]);
                        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($inv) {
                            $conn->prepare("UPDATE inventory SET quantity = quantity - :q, reserved_quantity = GREATEST(reserved_quantity - :q, 0), updated_at = NOW() WHERE id = :id")
                                 ->execute([':q'=>(int)$r['quantity'], ':id'=>$inv['id']]);
                            $log = $conn->prepare("INSERT INTO inventory_transactions (product_id, warehouse_id, transaction_type, quantity, reference_type, reference_id, notes, performed_by) VALUES (:pid,:wid,'shipment',:q,'sales_order',:so_id,'SO shipment',:uid)");
                            $log->execute([':pid'=>$r['product_id'], ':wid'=>$r['warehouse_id'], ':q'=>-(int)$r['quantity'], ':so_id'=>$id, ':uid'=>(int)($data['performed_by'] ?? 0)]);
                        }
                    }
                    $conn->prepare("UPDATE sales_orders SET status='shipped', shipped_at = NOW() WHERE id = :id")
                         ->execute([':id'=>$id]);
                } else {
                    json_err('Unsupported transition', 422);
                }
                $conn->commit();
                json_ok(['updated'=>true]);
            } catch (Exception $e) {
                $conn->rollBack();
                json_err($e->getMessage(),400);
            }
        } else {
            // Generic header update
            $fields = ['customer_name','customer_email','shipping_address','assigned_picker','delivery_date'];
            $sets = [];$params = [':id'=>$id];
            foreach ($fields as $f) { if (array_key_exists($f,$data)) { $sets[] = "$f = :$f"; $params[":$f"] = $data[$f]; } }
            if (empty($sets)) json_err('No fields to update',422);
            $sql = 'UPDATE sales_orders SET '.implode(', ',$sets).' WHERE id = :id';
            $stmt = $conn->prepare($sql); $stmt->execute($params);
            json_ok(['updated'=>true]);
        }
    }

    json_err('Method not allowed',405);
} catch (Exception $e) {
    json_err($e->getMessage(),400);
}

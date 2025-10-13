<?php
require_once 'common.php';

// Handle POST request for receiving shipment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = getRequestData();
    
    // Validate required fields
    if (!isset($data['po_id']) || !isset($data['items'])) {
        sendError('Missing required fields', 400);
    }

    try {
        $db->beginTransaction();

        // Get PO details
        $stmt = $db->prepare("SELECT * FROM purchase_orders WHERE id = ?");
        $stmt->execute([$data['po_id']]);
        $po = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$po) {
            sendError('PO not found', 404);
        }

        // Update PO status
        $stmt = $db->prepare("
            UPDATE purchase_orders 
            SET 
                status = 'received',
                received_at = CURRENT_TIMESTAMP,
                received_by = ?,
                updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        $stmt->execute([$_SESSION['user_id'], $data['po_id']]);

        // Record status change
        $stmt = $db->prepare("
            INSERT INTO po_status_history (
                po_id,
                from_status,
                to_status,
                changed_by,
                changed_by_type,
                notes
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['po_id'],
            $po['status'],
            'received',
            $_SESSION['user_id'],
            'user',
            'Shipment received at warehouse'
        ]);

        // Create goods receipt records
        foreach ($data['items'] as $item) {
            // Update receiving queue status
            $stmt = $db->prepare("
                UPDATE receiving_queue 
                SET 
                    status = 'received',
                    received_by = ?,
                    received_date = CURRENT_TIMESTAMP,
                    quantity = ?,
                    location_code = ?,
                    notes = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([
                $_SESSION['user_id'],
                $item['quantity'],
                $item['location_code'],
                $item['notes'] ?? null,
                $item['queue_id']
            ]);

            // Create goods receipt record in PSM
            $stmt = $db->prepare("
                INSERT INTO received_items (
                    po_id,
                    po_item_id,
                    supplier_product_id,
                    product_name,
                    product_code,
                    quantity_received,
                    unit_price,
                    total_amount,
                    received_by,
                    status,
                    notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
            ");
            $stmt->execute([
                $data['po_id'],
                $item['po_item_id'],
                $item['supplier_product_id'],
                $item['product_name'],
                $item['product_code'],
                $item['quantity'],
                $item['unit_price'],
                $item['unit_price'] * $item['quantity'],
                $_SESSION['user_id'],
                $item['notes'] ?? null
            ]);
        }

        // Create notifications
        $notifications = [
            // Notify PSM users
            [
                'recipient_type' => 'psm',
                'notification_type' => 'goods_receipt',
                'title' => "Goods Received - PO #{$po['po_number']}",
                'message' => "Goods have been received for PO #{$po['po_number']}. Please review and process the receipt.",
                'module' => 'sws',
                'priority' => 'high',
                'requires_action' => true
            ],
            // Notify supplier
            [
                'recipient_type' => 'supplier',
                'recipient_id' => $po['supplier_id'],
                'notification_type' => 'status_update',
                'title' => "Goods Received - PO #{$po['po_number']}",
                'message' => "Your shipment for PO #{$po['po_number']} has been received at the warehouse.",
                'module' => 'sws',
                'priority' => 'medium',
                'requires_action' => false
            ]
        ];

        foreach ($notifications as $notification) {
            $stmt = $db->prepare("
                INSERT INTO po_notifications (
                    po_id,
                    recipient_type,
                    recipient_id,
                    notification_type,
                    title,
                    message,
                    module,
                    priority,
                    requires_action
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['po_id'],
                $notification['recipient_type'],
                $notification['recipient_id'] ?? null,
                $notification['notification_type'],
                $notification['title'],
                $notification['message'],
                $notification['module'],
                $notification['priority'],
                $notification['requires_action']
            ]);
        }

        $db->commit();
        sendResponse(['message' => 'Shipment received successfully']);
    } catch (Exception $e) {
        $db->rollBack();
        sendError('Error receiving shipment: ' . $e->getMessage());
    }
} else {
    sendError('Method not allowed', 405);
}
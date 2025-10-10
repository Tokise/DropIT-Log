<?php
// Cron-friendly automation runner
// Usage (Windows Task Scheduler): php -f d:\xampp\htdocs\DropIT-Logistic\cron\automation.php

require_once __DIR__ . '/../services/AIService.php';
require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('UTC');

function logLine($msg) {
    echo '[' . date('Y-m-d H:i:s') . "] $msg\n";
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    $ai = new AIService();

    logLine('Starting automation run');

    // 1) Reorder recommendations for all active warehouses
    $warehouses = $conn->query("SELECT id, name FROM warehouses WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($warehouses as $w) {
        logLine("Generating reorder recommendations for warehouse #{$w['id']} ({$w['name']})...");
        try {
            $recs = $ai->generateReorderRecommendation((int)$w['id']);
            $count = $recs['total_items'] ?? 0;
            $cost = $recs['total_estimated_cost'] ?? 0;
            logLine(" -> Recommendations: $count items; Estimated cost: $cost");
        } catch (Exception $e) {
            logLine(' -> Error: ' . $e->getMessage());
        }
    }

    // 2) Anomaly detection (inventory)
    logLine('Running anomaly detection (inventory)...');
    try {
        $anom = $ai->detectAnomalies('inventory', null);
        $found = $anom['total_found'] ?? 0;
        $high = $anom['high_severity'] ?? 0;
        logLine(" -> Anomalies found: $found (high: $high)");
    } catch (Exception $e) {
        logLine(' -> Error: ' . $e->getMessage());
    }

    logLine('Automation run completed');
} catch (Exception $e) {
    logLine('Fatal: ' . $e->getMessage());
    http_response_code(500);
}

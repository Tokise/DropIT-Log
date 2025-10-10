<?php
require_once 'common.php';

header('Content-Type: application/json');
cors_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Only POST method allowed', 405);
}

try {
    $data = read_json_body();
    require_params($data, ['category_name']);
    
    $conn = db_conn();
    
    // Check if category already exists
    $stmt = $conn->prepare("SELECT id, name FROM categories WHERE LOWER(name) = LOWER(:name)");
    $stmt->execute([':name' => $data['category_name']]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        json_ok([
            'category_id' => $existing['id'],
            'category_name' => $existing['name'],
            'already_exists' => true,
            'message' => 'Category already exists'
        ]);
        return;
    }
    
    // Generate location data
    $locationPrefix = generateLocationPrefix($data['category_name']);
    $zoneLetterValue = getNextAvailableZone($conn);
    $aisleRangeValue = getNextAvailableAisleRange($conn);
    
    // Create new category
    $stmt = $conn->prepare("
        INSERT INTO categories (name, description, location_prefix, zone_letter, aisle_range) 
        VALUES (:name, :description, :location_prefix, :zone_letter, :aisle_range)
    ");
    
    $stmt->execute([
        ':name' => $data['category_name'],
        ':description' => $data['description'] ?? "AI-suggested category for {$data['category_name']} items",
        ':location_prefix' => $locationPrefix,
        ':zone_letter' => $zoneLetterValue,
        ':aisle_range' => $aisleRangeValue
    ]);
    
    $categoryId = (int)$conn->lastInsertId();
    
    json_ok([
        'category_id' => $categoryId,
        'category_name' => $data['category_name'],
        'location_prefix' => $locationPrefix,
        'zone_letter' => $zoneLetterValue,
        'aisle_range' => $aisleRangeValue,
        'already_exists' => false,
        'message' => 'New category created successfully'
    ], 201);
    
} catch (Exception $e) {
    json_err($e->getMessage(), 500);
}

function generateLocationPrefix($categoryName) {
    $words = explode(' ', $categoryName);
    if (count($words) >= 2) {
        return strtoupper(substr($words[0], 0, 2) . substr($words[1], 0, 1));
    } else {
        return strtoupper(substr($categoryName, 0, 3));
    }
}

function getNextAvailableZone($conn) {
    $stmt = $conn->prepare("SELECT DISTINCT zone_letter FROM categories WHERE zone_letter IS NOT NULL ORDER BY zone_letter");
    $stmt->execute();
    $usedZones = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    for ($i = ord('A'); $i <= ord('Z'); $i++) {
        $zone = chr($i);
        if (!in_array($zone, $usedZones)) {
            return $zone;
        }
    }
    return 'Z'; // Fallback
}

function getNextAvailableAisleRange($conn) {
    $stmt = $conn->prepare("SELECT aisle_range FROM categories WHERE aisle_range IS NOT NULL ORDER BY aisle_range DESC LIMIT 1");
    $stmt->execute();
    $lastRange = $stmt->fetchColumn();
    
    if (!$lastRange) {
        return '01-03';
    }
    
    $parts = explode('-', $lastRange);
    if (count($parts) === 2) {
        $newStart = intval($parts[1]) + 1;
        $newEnd = $newStart + 2;
        return str_pad($newStart, 2, '0', STR_PAD_LEFT) . '-' . str_pad($newEnd, 2, '0', STR_PAD_LEFT);
    }
    
    return '01-03'; // Fallback
}
?>

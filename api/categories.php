<?php
require_once 'common.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    $conn = db_conn();
    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id > 0) {
            $stmt = $conn->prepare("SELECT * FROM categories WHERE id = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) json_err('Category not found', 404);
            json_ok($row);
        } else {
            $stmt = $conn->prepare("SELECT * FROM categories ORDER BY name");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            json_ok(['items' => $rows]);
        }
    }

    if ($method === 'POST') {
        $data = read_json_body();
        require_params($data, ['name']);
        
        // Generate location_prefix if not provided
        if (!isset($data['location_prefix']) || empty($data['location_prefix'])) {
            $data['location_prefix'] = generateLocationPrefix($data['name']);
        }
        
        // Get next available zone and aisle range if not provided
        if (!isset($data['zone_letter']) || empty($data['zone_letter'])) {
            $data['zone_letter'] = getNextAvailableZone($conn);
        }
        
        if (!isset($data['aisle_range']) || empty($data['aisle_range'])) {
            $data['aisle_range'] = getNextAvailableAisleRange($conn);
        }
        
        $stmt = $conn->prepare("INSERT INTO categories (name, description, parent_id, location_prefix, zone_letter, aisle_range) VALUES (:name, :description, :parent_id, :location_prefix, :zone_letter, :aisle_range)");
        $stmt->execute([
            ':name' => $data['name'],
            ':description' => $data['description'] ?? null,
            ':parent_id' => $data['parent_id'] ?? null,
            ':location_prefix' => $data['location_prefix'],
            ':zone_letter' => $data['zone_letter'],
            ':aisle_range' => $data['aisle_range']
        ]);
        $id = (int)$conn->lastInsertId();
        json_ok(['id' => $id], 201);
    }

    if ($method === 'PUT') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) json_err('id is required', 422);
        $data = read_json_body();
        $fields = ['name', 'description', 'parent_id', 'location_prefix', 'zone_letter', 'aisle_range'];
        $sets = [];
        $params = [':id' => $id];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = "$f = :$f";
                $params[":$f"] = $data[$f];
            }
        }
        if (empty($sets)) json_err('No fields to update', 422);
        $sql = 'UPDATE categories SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        json_ok(['updated' => true]);
    }

    if ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) json_err('id is required', 422);
        $stmt = $conn->prepare("DELETE FROM categories WHERE id = :id");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        json_ok(['deleted' => true]);
    }

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

<?php
/**
 * Barcode Generator API
 * Generates EAN-13 barcodes for products
 */

require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();
$auth = require_auth();

if ($method !== 'POST') {
    json_err('POST required', 405);
}

try {
    $data = read_json_body();
    $product_id = isset($data['product_id']) ? (int)$data['product_id'] : null;
    $prefix = $data['prefix'] ?? '890'; // Default country code (Philippines)
    
    // Generate EAN-13 barcode
    $barcode = generateEAN13($prefix);
    
    // Check if barcode already exists
    $stmt = $conn->prepare("SELECT id FROM products WHERE barcode = :barcode");
    $stmt->execute([':barcode' => $barcode]);
    
    // If exists, generate a new one
    while ($stmt->fetch()) {
        $barcode = generateEAN13($prefix);
        $stmt->execute([':barcode' => $barcode]);
    }
    
    // If product_id provided, update the product
    if ($product_id) {
        $stmt = $conn->prepare("UPDATE products SET barcode = :barcode WHERE id = :id");
        $stmt->execute([':barcode' => $barcode, ':id' => $product_id]);
        
        log_audit('product', $product_id, 'generate_barcode', $auth['id'], "Generated barcode: $barcode");
    }
    
    json_ok([
        'barcode' => $barcode,
        'format' => 'EAN-13',
        'svg' => generateBarcodeSVG($barcode),
        'message' => 'Barcode generated successfully'
    ]);
    
} catch (Exception $e) {
    json_err($e->getMessage(), 500);
}

/**
 * Generate EAN-13 barcode
 */
function generateEAN13($prefix = '890') {
    // EAN-13 format: 3-digit prefix + 9 random digits + 1 check digit
    $prefix = str_pad($prefix, 3, '0', STR_PAD_LEFT);
    
    // Generate 9 random digits
    $randomDigits = '';
    for ($i = 0; $i < 9; $i++) {
        $randomDigits .= rand(0, 9);
    }
    
    $code = $prefix . $randomDigits;
    
    // Calculate check digit
    $checkDigit = calculateEAN13CheckDigit($code);
    
    return $code . $checkDigit;
}

/**
 * Calculate EAN-13 check digit
 */
function calculateEAN13CheckDigit($code) {
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $digit = (int)$code[$i];
        $sum += ($i % 2 === 0) ? $digit : $digit * 3;
    }
    $checkDigit = (10 - ($sum % 10)) % 10;
    return $checkDigit;
}

/**
 * Generate simple SVG barcode representation
 */
function generateBarcodeSVG($barcode) {
    $width = 300;
    $height = 100;
    $barWidth = 2;
    
    $svg = '<svg width="' . $width . '" height="' . $height . '" xmlns="http://www.w3.org/2000/svg">';
    $svg .= '<rect width="100%" height="100%" fill="white"/>';
    
    // Simple representation - alternating bars
    $x = 10;
    for ($i = 0; $i < strlen($barcode); $i++) {
        $digit = (int)$barcode[$i];
        $barHeight = 60 + ($digit * 2);
        
        if ($i % 2 === 0) {
            $svg .= '<rect x="' . $x . '" y="10" width="' . $barWidth . '" height="' . $barHeight . '" fill="black"/>';
        }
        $x += $barWidth + 1;
    }
    
    // Add barcode text
    $svg .= '<text x="' . ($width / 2) . '" y="' . ($height - 10) . '" text-anchor="middle" font-family="monospace" font-size="14">' . $barcode . '</text>';
    $svg .= '</svg>';
    
    return $svg;
}

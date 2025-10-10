<?php
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../services/BarcodeImageGenerator.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = db_conn();
$auth = require_auth();

try {
    if ($method === 'GET') {
        $barcode = trim($_GET['barcode'] ?? '');
        $width = (int)($_GET['width'] ?? 250);
        $height = (int)($_GET['height'] ?? 100);
        $format = $_GET['format'] ?? 'svg';
        
        if (empty($barcode)) {
            json_err('barcode parameter is required', 422);
        }
        
        if (strlen($barcode) !== 13) {
            json_err('Only EAN-13 barcodes are supported', 422);
        }
        
        // Try to get the barcode image from the database if column exists
        try {
            $stmt = $conn->prepare("SHOW COLUMNS FROM products LIKE 'barcode_image'");
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $stmt = $conn->prepare("SELECT barcode_image FROM products WHERE barcode = :barcode AND barcode_image IS NOT NULL LIMIT 1");
                $stmt->execute([':barcode' => $barcode]);
                $existingImage = $stmt->fetchColumn();
                
                if ($existingImage && $format === 'dataurl') {
                    // Return the stored image
                    json_ok([
                        'barcode' => $barcode,
                        'image' => $existingImage,
                        'width' => $width,
                        'height' => $height,
                        'source' => 'database'
                    ]);
                    return;
                }
            }
        } catch (Exception $e) {
            // Column doesn't exist or other error, continue to generate
        }
        
        // Generate new image if not found in database
        $generator = new BarcodeImageGenerator();
        
        if ($format === 'svg') {
            header('Content-Type: image/svg+xml');
            echo $generator->generateBarcodeSVG($barcode, $width, $height);
        } elseif ($format === 'dataurl') {
            json_ok([
                'barcode' => $barcode,
                'image' => $generator->generateBarcodeDataURL($barcode, $width, $height),
                'width' => $width,
                'height' => $height,
                'source' => 'generated'
            ]);
        } else {
            json_err('Unsupported format. Use svg or dataurl', 422);
        }
    } else {
        json_err('Method not allowed', 405);
    }
} catch (Exception $e) {
    json_err($e->getMessage(), 400);
}

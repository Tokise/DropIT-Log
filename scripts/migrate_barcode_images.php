<?php
/**
 * Migration script to add barcode_image field and populate existing products
 */

require_once __DIR__ . '/../api/common.php';
require_once __DIR__ . '/../services/BarcodeImageGenerator.php';

try {
    $conn = db_conn();
    
    echo "Starting barcode image migration...\n";
    
    // Step 1: Add the barcode_image column if it doesn't exist
    echo "1. Adding barcode_image column...\n";
    
    $stmt = $conn->prepare("SHOW COLUMNS FROM products LIKE 'barcode_image'");
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        $conn->exec("ALTER TABLE products ADD COLUMN barcode_image LONGTEXT NULL COMMENT 'Base64 encoded barcode image' AFTER barcode");
        echo "   ✓ barcode_image column added\n";
    } else {
        echo "   ✓ barcode_image column already exists\n";
    }
    
    // Step 2: Generate barcode images for existing products with barcodes
    echo "2. Generating barcode images for existing products...\n";
    
    $stmt = $conn->prepare("SELECT id, barcode FROM products WHERE barcode IS NOT NULL AND barcode != '' AND barcode_image IS NULL");
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($products)) {
        echo "   ✓ No products need barcode image generation\n";
    } else {
        $imageGenerator = new BarcodeImageGenerator();
        $updateStmt = $conn->prepare("UPDATE products SET barcode_image = :barcode_image WHERE id = :id");
        
        $count = 0;
        foreach ($products as $product) {
            try {
                // Only process EAN-13 barcodes
                if (strlen($product['barcode']) === 13) {
                    $barcodeImage = $imageGenerator->generateBarcodeDataURL($product['barcode']);
                    
                    $updateStmt->execute([
                        ':id' => $product['id'],
                        ':barcode_image' => $barcodeImage
                    ]);
                    
                    $count++;
                    echo "   ✓ Generated barcode image for product ID {$product['id']} (barcode: {$product['barcode']})\n";
                } else {
                    echo "   ⚠ Skipped product ID {$product['id']} - invalid barcode length: {$product['barcode']}\n";
                }
            } catch (Exception $e) {
                echo "   ✗ Failed to generate barcode image for product ID {$product['id']}: {$e->getMessage()}\n";
            }
        }
        
        echo "   ✓ Generated barcode images for {$count} products\n";
    }
    
    // Step 3: Add index for better performance (if not exists)
    echo "3. Adding database index...\n";
    
    try {
        $conn->exec("ALTER TABLE products ADD INDEX idx_barcode_image (barcode_image(100))");
        echo "   ✓ Index added for barcode_image column\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            echo "   ✓ Index already exists for barcode_image column\n";
        } else {
            echo "   ⚠ Could not add index: {$e->getMessage()}\n";
        }
    }
    
    echo "\n✅ Migration completed successfully!\n";
    echo "Barcode images are now stored in the database and will be used for faster loading.\n";
    
} catch (Exception $e) {
    echo "\n❌ Migration failed: {$e->getMessage()}\n";
    exit(1);
}

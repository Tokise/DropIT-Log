<?php
// Update categories table for AI-powered location assignment
header('Content-Type: text/html; charset=utf-8');

try {
    $host = 'localhost';
    $dbname = 'smart_warehouse';
    $username = 'root';
    $password = '';
    
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>Categories Schema Update</h2>";
    
    // Add location_prefix column
    try {
        $pdo->exec("ALTER TABLE categories ADD COLUMN location_prefix VARCHAR(10) NOT NULL DEFAULT 'GEN' COMMENT 'Prefix for location codes (e.g., ELC for Electronics)'");
        echo "<p style='color: green;'>✓ Added location_prefix column</p>";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "<p style='color: blue;'>✓ location_prefix column already exists</p>";
        } else {
            throw $e;
        }
    }
    
    // Add zone_letter column
    try {
        $pdo->exec("ALTER TABLE categories ADD COLUMN zone_letter VARCHAR(1) NOT NULL DEFAULT 'A' COMMENT 'Warehouse zone assignment (A-Z)'");
        echo "<p style='color: green;'>✓ Added zone_letter column</p>";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "<p style='color: blue;'>✓ zone_letter column already exists</p>";
        } else {
            throw $e;
        }
    }
    
    // Add aisle_range column
    try {
        $pdo->exec("ALTER TABLE categories ADD COLUMN aisle_range VARCHAR(10) DEFAULT '01-05' COMMENT 'Aisle range for this category'");
        echo "<p style='color: green;'>✓ Added aisle_range column</p>";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "<p style='color: blue;'>✓ aisle_range column already exists</p>";
        } else {
            throw $e;
        }
    }
    
    // Add product_image column to products table
    try {
        $pdo->exec("ALTER TABLE products ADD COLUMN product_image LONGTEXT NULL COMMENT 'Base64 encoded product image' AFTER image_url");
        echo "<p style='color: green;'>✓ Added product_image column to products table</p>";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "<p style='color: blue;'>✓ product_image column already exists</p>";
        } else {
            throw $e;
        }
    }
    
    // Insert default categories with location assignments
    $defaultCategories = [
        ['Electronics', 'Electronic devices and components', 'ELC', 'A', '01-03'],
        ['Clothing', 'Apparel and fashion items', 'CLT', 'B', '04-06'],
        ['Food & Beverages', 'Food items and drinks', 'FDB', 'C', '07-09'],
        ['Home & Garden', 'Household and garden items', 'HGD', 'D', '10-12'],
        ['Sports & Outdoors', 'Sports equipment and outdoor gear', 'SPT', 'E', '13-15'],
        ['Books & Media', 'Books, movies, music', 'BKM', 'F', '16-18'],
        ['Health & Beauty', 'Health and beauty products', 'HBT', 'G', '19-21'],
        ['Automotive', 'Car parts and accessories', 'AUT', 'H', '22-24'],
        ['Tools & Hardware', 'Tools and hardware supplies', 'TLS', 'I', '25-27'],
        ['Toys & Games', 'Toys and gaming products', 'TOY', 'J', '28-30']
    ];
    
    foreach ($defaultCategories as $cat) {
        try {
            $stmt = $pdo->prepare("INSERT IGNORE INTO categories (name, description, location_prefix, zone_letter, aisle_range) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute($cat);
            if ($stmt->rowCount() > 0) {
                echo "<p style='color: green;'>✓ Added category: {$cat[0]}</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color: orange;'>⚠ Category {$cat[0]} might already exist</p>";
        }
    }
    
    echo "<p><strong>Schema update completed!</strong></p>";
    echo "<p><a href='sws.php'>Go back to SWS</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>

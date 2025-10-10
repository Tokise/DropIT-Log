<?php
require_once __DIR__ . '/BarcodeImageGenerator.php';

class ProductCodeGenerator {
    private $conn;
    private $barcodeImageGenerator;
    
    public function __construct($connection) {
        $this->conn = $connection;
        $this->barcodeImageGenerator = new BarcodeImageGenerator();
    }
    
    /**
     * Generate a unique SKU
     */
    public function generateSKU($prefix = 'PRD') {
        $maxAttempts = 100;
        $attempt = 0;
        
        do {
            $timestamp = date('ymd'); // YYMMDD format
            $random = str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $sku = $prefix . '-' . $timestamp . '-' . $random;
            
            // Check if SKU already exists
            $stmt = $this->conn->prepare("SELECT id FROM products WHERE sku = :sku");
            $stmt->execute([':sku' => $sku]);
            $exists = $stmt->fetch();
            
            $attempt++;
        } while ($exists && $attempt < $maxAttempts);
        
        if ($attempt >= $maxAttempts) {
            throw new Exception('Unable to generate unique SKU after ' . $maxAttempts . ' attempts');
        }
        
        return $sku;
    }
    
    /**
     * Generate a unique EAN-13 barcode
     */
    public function generateBarcode($companyPrefix = '123') {
        $maxAttempts = 100;
        $attempt = 0;
        
        do {
            // Generate EAN-13 barcode
            // Format: Company Prefix (3) + Item Reference (9) + Check Digit (1)
            $companyPrefix = str_pad(substr($companyPrefix, 0, 3), 3, '0', STR_PAD_LEFT);
            $itemReference = str_pad(mt_rand(1, 999999999), 9, '0', STR_PAD_LEFT);
            
            // Calculate check digit
            $code = $companyPrefix . $itemReference;
            $checkDigit = $this->calculateEAN13CheckDigit($code);
            $barcode = $code . $checkDigit;
            
            // Check if barcode already exists
            $stmt = $this->conn->prepare("SELECT id FROM products WHERE barcode = :barcode");
            $stmt->execute([':barcode' => $barcode]);
            $exists = $stmt->fetch();
            
            $attempt++;
        } while ($exists && $attempt < $maxAttempts);
        
        if ($attempt >= $maxAttempts) {
            throw new Exception('Unable to generate unique barcode after ' . $maxAttempts . ' attempts');
        }
        
        return $barcode;
    }
    
    /**
     * Calculate EAN-13 check digit
     */
    private function calculateEAN13CheckDigit($code) {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $multiplier = ($i % 2 === 0) ? 1 : 3;
            $sum += (int)$code[$i] * $multiplier;
        }
        
        $checkDigit = (10 - ($sum % 10)) % 10;
        return $checkDigit;
    }
    
    /**
     * Generate both SKU and barcode
     */
    public function generateCodes($skuPrefix = 'PRD', $companyPrefix = '123') {
        return [
            'sku' => $this->generateSKU($skuPrefix),
            'barcode' => $this->generateBarcode($companyPrefix)
        ];
    }
    
    /**
     * Generate SKU, barcode, and barcode image
     */
    public function generateCodesWithImage($skuPrefix = 'PRD', $companyPrefix = '123') {
        $sku = $this->generateSKU($skuPrefix);
        $barcode = $this->generateBarcode($companyPrefix);
        $barcodeImage = $this->barcodeImageGenerator->generateBarcodeDataURL($barcode);
        
        return [
            'sku' => $sku,
            'barcode' => $barcode,
            'barcode_image' => $barcodeImage
        ];
    }
    
    /**
     * Validate and format product name for SKU generation
     */
    public function generateSKUFromName($productName, $maxLength = 3) {
        // Clean and format product name for SKU prefix
        $cleaned = preg_replace('/[^a-zA-Z0-9]/', '', strtoupper($productName));
        $prefix = substr($cleaned, 0, $maxLength);
        
        if (strlen($prefix) < 2) {
            $prefix = 'PRD'; // Default prefix if name is too short
        }
        
        return $this->generateSKU($prefix);
    }
}

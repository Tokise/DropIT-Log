<?php

class BarcodeService {
    private $config;
    
    public function __construct() {
        $this->config = require __DIR__ . '/../config/barcode_config.php';
    }
    
    /**
     * Lookup product information by barcode using external APIs
     */
    public function lookupBarcode($barcode) {
        // First try Cloudmersive API
        $result = $this->lookupCloudmersive($barcode);
        if ($result['success']) {
            return $result;
        }
        
        // If Cloudmersive fails, try other methods
        return $this->lookupFallback($barcode);
    }
    
    /**
     * Lookup using Cloudmersive Barcode API
     */
    private function lookupCloudmersive($barcode) {
        $apiKey = $this->config['cloudmersive']['api_key'];
        
        if (empty($apiKey) || $apiKey === 'your-cloudmersive-api-key-here') {
            return ['success' => false, 'error' => 'Cloudmersive API key not configured'];
        }
        
        $url = $this->config['cloudmersive']['base_url'];
        
        $postData = json_encode(['Value' => $barcode]);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Apikey: ' . $apiKey
            ],
            CURLOPT_TIMEOUT => $this->config['cloudmersive']['timeout'],
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'error' => 'CURL Error: ' . $error];
        }
        
        if ($httpCode !== 200) {
            return ['success' => false, 'error' => 'HTTP Error: ' . $httpCode];
        }
        
        $data = json_decode($response, true);
        
        if (!$data || !isset($data['Successful']) || !$data['Successful']) {
            return ['success' => false, 'error' => 'No product data found'];
        }
        
        // Transform Cloudmersive response to our format
        return [
            'success' => true,
            'product' => [
                'name' => $data['Title'] ?? 'Unknown Product',
                'description' => $data['Description'] ?? '',
                'brand' => $data['Brand'] ?? '',
                'category' => $data['Category'] ?? '',
                'ean' => $data['EAN'] ?? $barcode,
                'upc' => $data['UPC'] ?? '',
                'image_url' => $data['ImageURL'] ?? null,
                'suggested_price' => null // Cloudmersive doesn't provide pricing
            ],
            'source' => 'cloudmersive'
        ];
    }
    
    /**
     * Fallback lookup methods
     */
    private function lookupFallback($barcode) {
        // Try to extract basic info from barcode format
        $info = $this->analyzeBarcodeFormat($barcode);
        
        return [
            'success' => true,
            'product' => [
                'name' => 'Unknown Product',
                'description' => "Product with barcode: $barcode",
                'brand' => '',
                'category' => $info['type'] ?? 'General',
                'ean' => $barcode,
                'upc' => $barcode,
                'image_url' => null,
                'suggested_price' => null
            ],
            'source' => 'fallback'
        ];
    }
    
    /**
     * Analyze barcode format to extract basic information
     */
    private function analyzeBarcodeFormat($barcode) {
        $length = strlen($barcode);
        
        switch ($length) {
            case 8:
                return ['type' => 'EAN-8', 'format' => 'European Article Number'];
            case 12:
                return ['type' => 'UPC-A', 'format' => 'Universal Product Code'];
            case 13:
                return ['type' => 'EAN-13', 'format' => 'European Article Number'];
            case 14:
                return ['type' => 'GTIN-14', 'format' => 'Global Trade Item Number'];
            default:
                return ['type' => 'Unknown', 'format' => 'Unknown format'];
        }
    }
    
    /**
     * Validate barcode format
     */
    public function validateBarcode($barcode) {
        // Remove any non-numeric characters
        $barcode = preg_replace('/[^0-9]/', '', $barcode);
        
        // Check if it's a valid length
        $validLengths = [8, 12, 13, 14];
        $length = strlen($barcode);
        
        if (!in_array($length, $validLengths)) {
            return ['valid' => false, 'error' => 'Invalid barcode length'];
        }
        
        // Validate checksum for EAN/UPC codes
        if (in_array($length, [8, 12, 13])) {
            if (!$this->validateChecksum($barcode)) {
                return ['valid' => false, 'error' => 'Invalid checksum'];
            }
        }
        
        return ['valid' => true, 'barcode' => $barcode];
    }
    
    /**
     * Validate EAN/UPC checksum
     */
    private function validateChecksum($barcode) {
        $length = strlen($barcode);
        $checkDigit = (int)substr($barcode, -1);
        $digits = str_split(substr($barcode, 0, -1));
        
        $sum = 0;
        for ($i = 0; $i < count($digits); $i++) {
            $multiplier = ($length === 13) ? (($i % 2 === 0) ? 1 : 3) : (($i % 2 === 0) ? 3 : 1);
            $sum += (int)$digits[$i] * $multiplier;
        }
        
        $calculatedCheck = (10 - ($sum % 10)) % 10;
        return $calculatedCheck === $checkDigit;
    }
}

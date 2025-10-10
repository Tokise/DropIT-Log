<?php
// Load environment variables from .env file if it exists
if (file_exists(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue; // Skip comments
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}

// Barcode API Configuration
return [
    'cloudmersive' => [
        'api_key' => $_ENV['BARCODE_API_KEY'] ?? 'your-cloudmersive-api-key-here',
        'base_url' => 'https://api.cloudmersive.com/barcode/lookup/ean',
        'timeout' => 10
    ],
    'fallback_apis' => [
        // Add other barcode APIs as fallback
        'upc_database' => [
            'enabled' => false,
            'api_key' => $_ENV['UPC_API_KEY'] ?? null
        ]
    ]
];

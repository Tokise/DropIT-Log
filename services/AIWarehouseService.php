<?php
/**
 * AI Warehouse Service
 * Provides AI-powered automation for warehouse operations
 * - Smart location assignment
 * - Inventory predictions
 * - Optimal picking routes
 * - Anomaly detection
 */

require_once __DIR__ . '/../config/ai_config.php';

if (!class_exists('AIWarehouseService')) {
class AIWarehouseService {
    private $aiConfig;
    private $conn;
    
    public function __construct($dbConnection = null) {
        $this->aiConfig = new AIConfig();
        $this->conn = $dbConnection;
    }
    
    /**
     * Call AI API with a prompt (supports OpenRouter, Claude, Gemini, OpenAI)
     */
    private function callAIAPI($prompt, $systemInstruction = '') {
        $apiKey = $this->aiConfig->getApiKey();
        $provider = $this->aiConfig->getProvider();
        
        if (empty($apiKey)) {
            throw new Exception('AI API key not configured');
        }
        
        if ($provider === 'openrouter') {
            return $this->callOpenRouterAPI($prompt, $systemInstruction, $apiKey);
        } elseif ($provider === 'claude') {
            return $this->callClaudeAPI($prompt, $systemInstruction, $apiKey);
        } elseif ($provider === 'gemini') {
            return $this->callGeminiAPI($prompt, $systemInstruction, $apiKey);
        } else {
            throw new Exception("Unsupported AI provider: $provider");
        }
    }
    
    /**
     * Call OpenRouter API (supports multiple models including free Gemini)
     */
    private function callOpenRouterAPI($prompt, $systemInstruction, $apiKey) {
        $endpoint = $this->aiConfig->getEndpoint();
        $model = $this->aiConfig->getModel();
        
        $messages = [];
        if (!empty($systemInstruction)) {
            $messages[] = [
                'role' => 'system',
                'content' => $systemInstruction
            ];
        }
        $messages[] = [
            'role' => 'user',
            'content' => $prompt
        ];
        
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => 2048
        ];
        
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For local development
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // For local development
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'HTTP-Referer: http://localhost', // Optional: your site URL
            'X-Title: DropIT Logistics SWS' // Optional: your app name
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("OpenRouter API error: HTTP $httpCode - $response");
        }
        
        $data = json_decode($response, true);
        
        if (!isset($data['choices'][0]['message']['content'])) {
            throw new Exception('Invalid response from OpenRouter API');
        }
        
        return $data['choices'][0]['message']['content'];
    }
    
    /**
     * Call Claude (Anthropic) API
     */
    private function callClaudeAPI($prompt, $systemInstruction, $apiKey) {
        $endpoint = $this->aiConfig->getEndpoint();
        $model = $this->aiConfig->getModel();
        
        $payload = [
            'model' => $model,
            'max_tokens' => 2048,
            'temperature' => 0.7,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ]
        ];
        
        if (!empty($systemInstruction)) {
            $payload['system'] = $systemInstruction;
        }
        
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For local development
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // For local development
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("Claude API error: HTTP $httpCode - $response");
        }
        
        $data = json_decode($response, true);
        
        if (!isset($data['content'][0]['text'])) {
            throw new Exception('Invalid response from Claude API');
        }
        
        return $data['content'][0]['text'];
    }
    
    /**
     * Call Gemini API
     */
    private function callGeminiAPI($prompt, $systemInstruction, $apiKey) {
        $endpoint = $this->aiConfig->getEndpoint() . '?key=' . $apiKey;
        
        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'topK' => 40,
                'topP' => 0.95,
                'maxOutputTokens' => 2048,
            ]
        ];
        
        if (!empty($systemInstruction)) {
            $payload['systemInstruction'] = [
                'parts' => [
                    ['text' => $systemInstruction]
                ]
            ];
        }
        
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("Gemini API error: HTTP $httpCode - $response");
        }
        
        $data = json_decode($response, true);
        
        if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            throw new Exception('Invalid response from Gemini API');
        }
        
        return $data['candidates'][0]['content']['parts'][0]['text'];
    }
    
    /**
     * Smart Location Assignment
     * Uses AI to suggest optimal storage location for a product
     */
    public function suggestOptimalLocation($productId, $quantity, $warehouseId = 1) {
        try {
            // Get product details
            $stmt = $this->conn->prepare("
                SELECT p.*, c.name as category_name, c.zone_letter, c.aisle_range
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE p.id = :product_id
            ");
            $stmt->execute([':product_id' => $productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$product) {
                throw new Exception('Product not found');
            }
            
            // Get available bins with capacity
            $stmt = $this->conn->prepare("
                SELECT 
                    wb.id, wb.bin_code, wb.capacity_units, wb.current_units,
                    (wb.capacity_units - wb.current_units) as available_space,
                    wr.rack_code, wa.aisle_code, wz.zone_code, wz.zone_type
                FROM warehouse_bins wb
                JOIN warehouse_racks wr ON wb.rack_id = wr.id
                JOIN warehouse_aisles wa ON wr.aisle_id = wa.id
                JOIN warehouse_zones wz ON wa.zone_id = wz.id
                WHERE wz.warehouse_id = :warehouse_id
                    AND wb.is_available = 1
                    AND wb.is_reserved = 0
                    AND (wb.capacity_units - wb.current_units) >= :quantity
                ORDER BY (wb.capacity_units - wb.current_units) ASC
                LIMIT 20
            ");
            $stmt->execute([
                ':warehouse_id' => $warehouseId,
                ':quantity' => $quantity
            ]);
            $availableBins = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($availableBins)) {
                return [
                    'success' => false,
                    'message' => 'No available bins with sufficient capacity',
                    'suggestion' => null
                ];
            }
            
            // Prepare AI prompt
            $systemInstruction = "You are a warehouse optimization AI. Analyze the product and available storage locations to suggest the best bin for storage. Consider factors like product category, zone type, accessibility, and space efficiency. Respond ONLY with a valid JSON object.";
            
            $prompt = "Product Details:\n";
            $prompt .= "- Name: {$product['name']}\n";
            $prompt .= "- Category: {$product['category_name']}\n";
            $prompt .= "- SKU: {$product['sku']}\n";
            $prompt .= "- Quantity to store: {$quantity} units\n";
            $prompt .= "- Weight: {$product['weight_kg']} kg\n";
            $prompt .= "- Dimensions: {$product['dimensions_cm']}\n\n";
            
            $prompt .= "Available Storage Locations:\n";
            foreach ($availableBins as $idx => $bin) {
                $prompt .= ($idx + 1) . ". {$bin['bin_code']} - Zone: {$bin['zone_code']} ({$bin['zone_type']}), ";
                $prompt .= "Available: {$bin['available_space']} units, ";
                $prompt .= "Aisle: {$bin['aisle_code']}, Rack: {$bin['rack_code']}\n";
            }
            
            $prompt .= "\nTask: Select the BEST bin for this product and provide reasoning. ";
            $prompt .= "Respond with JSON format:\n";
            $prompt .= '{"bin_code": "selected_bin_code", "confidence": 0.95, "reasoning": "explanation"}';
            
            $aiResponse = $this->callAIAPI($prompt, $systemInstruction);
            
            // Extract JSON from response
            $jsonMatch = [];
            if (preg_match('/\{[^}]+\}/', $aiResponse, $jsonMatch)) {
                $aiSuggestion = json_decode($jsonMatch[0], true);
                
                // Find the suggested bin
                $suggestedBin = null;
                foreach ($availableBins as $bin) {
                    if ($bin['bin_code'] === $aiSuggestion['bin_code']) {
                        $suggestedBin = $bin;
                        break;
                    }
                }
                
                return [
                    'success' => true,
                    'suggestion' => [
                        'bin_id' => $suggestedBin['id'] ?? null,
                        'bin_code' => $aiSuggestion['bin_code'],
                        'confidence' => $aiSuggestion['confidence'] ?? 0.8,
                        'reasoning' => $aiSuggestion['reasoning'] ?? 'AI-selected optimal location',
                        'zone' => $suggestedBin['zone_code'] ?? null,
                        'available_space' => $suggestedBin['available_space'] ?? null
                    ],
                    'alternatives' => array_slice($availableBins, 0, 5)
                ];
            }
            
            // Fallback: return first available bin
            return [
                'success' => true,
                'suggestion' => [
                    'bin_id' => $availableBins[0]['id'],
                    'bin_code' => $availableBins[0]['bin_code'],
                    'confidence' => 0.6,
                    'reasoning' => 'Default selection (AI parsing failed)',
                    'zone' => $availableBins[0]['zone_code'],
                    'available_space' => $availableBins[0]['available_space']
                ],
                'alternatives' => array_slice($availableBins, 1, 5)
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'suggestion' => null
            ];
        }
    }
    
    /**
     * Predict inventory demand for next N days
     */
    public function predictDemand($productId, $days = 30) {
        try {
            // Get historical transaction data
            $stmt = $this->conn->prepare("
                SELECT 
                    DATE(created_at) as date,
                    SUM(CASE WHEN quantity < 0 THEN ABS(quantity) ELSE 0 END) as outbound,
                    SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END) as inbound
                FROM inventory_transactions
                WHERE product_id = :product_id
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                GROUP BY DATE(created_at)
                ORDER BY date DESC
            ");
            $stmt->execute([':product_id' => $productId]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($history)) {
                return [
                    'success' => false,
                    'message' => 'Insufficient historical data',
                    'prediction' => null
                ];
            }
            
            // Get current inventory
            $stmt = $this->conn->prepare("
                SELECT SUM(quantity) as total_qty
                FROM inventory
                WHERE product_id = :product_id
            ");
            $stmt->execute([':product_id' => $productId]);
            $currentStock = $stmt->fetchColumn() ?? 0;
            
            $systemInstruction = "You are an inventory demand forecasting AI. Analyze historical transaction data and predict future demand. Respond ONLY with valid JSON.";
            
            $prompt = "Historical Inventory Transactions (Last 90 days):\n";
            foreach (array_slice($history, 0, 30) as $record) {
                $prompt .= "Date: {$record['date']}, Outbound: {$record['outbound']}, Inbound: {$record['inbound']}\n";
            }
            
            $prompt .= "\nCurrent Stock: {$currentStock} units\n";
            $prompt .= "Forecast Period: {$days} days\n\n";
            $prompt .= "Task: Predict the demand for the next {$days} days. Provide:\n";
            $prompt .= '{"predicted_demand": number, "confidence": 0.0-1.0, "reorder_recommended": boolean, "reorder_quantity": number, "reasoning": "explanation"}';
            
            $aiResponse = $this->callAIAPI($prompt, $systemInstruction);
            
            // Extract JSON
            $jsonMatch = [];
            if (preg_match('/\{[^}]+\}/', $aiResponse, $jsonMatch)) {
                $prediction = json_decode($jsonMatch[0], true);
                
                return [
                    'success' => true,
                    'prediction' => $prediction,
                    'current_stock' => $currentStock,
                    'days_of_stock' => $prediction['predicted_demand'] > 0 
                        ? round($currentStock / ($prediction['predicted_demand'] / $days), 1)
                        : 999
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Failed to parse AI prediction',
                'prediction' => null
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'prediction' => null
            ];
        }
    }
    
    /**
     * Generate optimal picking route for multiple products
     */
    public function generatePickingRoute($orderItems) {
        try {
            // Get location details for each item
            $locations = [];
            foreach ($orderItems as $item) {
                $stmt = $this->conn->prepare("
                    SELECT 
                        il.bin_id, wb.bin_code, wb.level, wb.position,
                        wr.rack_code, wa.aisle_code, wz.zone_code,
                        il.quantity
                    FROM inventory_locations il
                    JOIN warehouse_bins wb ON il.bin_id = wb.id
                    JOIN warehouse_racks wr ON wb.rack_id = wr.id
                    JOIN warehouse_aisles wa ON wr.aisle_id = wa.id
                    JOIN warehouse_zones wz ON wa.zone_id = wz.id
                    WHERE il.product_id = :product_id
                        AND il.quantity >= :required_qty
                    ORDER BY il.quantity DESC
                    LIMIT 1
                ");
                $stmt->execute([
                    ':product_id' => $item['product_id'],
                    ':required_qty' => $item['quantity']
                ]);
                $location = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($location) {
                    $locations[] = array_merge($item, $location);
                }
            }
            
            if (empty($locations)) {
                return [
                    'success' => false,
                    'message' => 'No locations found for items',
                    'route' => []
                ];
            }
            
            $systemInstruction = "You are a warehouse picking route optimizer. Create the most efficient picking route considering zone proximity, aisle sequence, and rack/level accessibility. Respond ONLY with valid JSON.";
            
            $prompt = "Items to Pick:\n";
            foreach ($locations as $idx => $loc) {
                $prompt .= ($idx + 1) . ". Product: {$loc['product_name']}, ";
                $prompt .= "Location: {$loc['zone_code']}-{$loc['aisle_code']}-{$loc['rack_code']}-{$loc['bin_code']}, ";
                $prompt .= "Level: {$loc['level']}, Qty: {$loc['quantity']}\n";
            }
            
            $prompt .= "\nTask: Create optimal picking sequence (1-based index order). ";
            $prompt .= 'Respond with JSON: {"route": [3,1,4,2], "estimated_time_minutes": 15, "reasoning": "explanation"}';
            
            $aiResponse = $this->callAIAPI($prompt, $systemInstruction);
            
            // Extract JSON
            $jsonMatch = [];
            if (preg_match('/\{[^}]+\}/', $aiResponse, $jsonMatch)) {
                $routeData = json_decode($jsonMatch[0], true);
                
                $optimizedRoute = [];
                foreach ($routeData['route'] as $index) {
                    if (isset($locations[$index - 1])) {
                        $optimizedRoute[] = $locations[$index - 1];
                    }
                }
                
                return [
                    'success' => true,
                    'route' => $optimizedRoute,
                    'estimated_time' => $routeData['estimated_time_minutes'] ?? null,
                    'reasoning' => $routeData['reasoning'] ?? 'AI-optimized route'
                ];
            }
            
            // Fallback: return original order
            return [
                'success' => true,
                'route' => $locations,
                'estimated_time' => count($locations) * 2,
                'reasoning' => 'Default route (AI parsing failed)'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'route' => []
            ];
        }
    }
    
    /**
     * Detect inventory anomalies
     */
    public function detectAnomalies($warehouseId = null) {
        try {
            // Get inventory with potential issues
            $query = "
                SELECT 
                    p.id, p.name, p.sku,
                    SUM(i.quantity) as total_qty,
                    SUM(i.reserved_quantity) as reserved_qty,
                    p.reorder_point,
                    COUNT(DISTINCT i.warehouse_id) as warehouse_count,
                    GROUP_CONCAT(DISTINCT w.name) as warehouses
                FROM products p
                LEFT JOIN inventory i ON p.id = i.product_id
                LEFT JOIN warehouses w ON i.warehouse_id = w.id
            ";
            
            if ($warehouseId) {
                $query .= " WHERE i.warehouse_id = :warehouse_id";
            }
            
            $query .= " GROUP BY p.id HAVING total_qty IS NOT NULL";
            
            $stmt = $this->conn->prepare($query);
            if ($warehouseId) {
                $stmt->execute([':warehouse_id' => $warehouseId]);
            } else {
                $stmt->execute();
            }
            $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $systemInstruction = "You are an inventory anomaly detection AI. Identify unusual patterns, potential issues, and provide recommendations. Respond ONLY with valid JSON array.";
            
            $prompt = "Inventory Status:\n";
            foreach (array_slice($inventory, 0, 50) as $item) {
                $prompt .= "- {$item['name']} (SKU: {$item['sku']}): ";
                $prompt .= "Stock: {$item['total_qty']}, Reserved: {$item['reserved_qty']}, ";
                $prompt .= "Reorder Point: {$item['reorder_point']}, Warehouses: {$item['warehouse_count']}\n";
            }
            
            $prompt .= "\nTask: Identify anomalies (low stock, overstocking, unusual patterns). ";
            $prompt .= 'Respond with JSON array: [{"sku": "...", "issue": "...", "severity": "high/medium/low", "recommendation": "..."}]';
            
            $aiResponse = $this->callAIAPI($prompt, $systemInstruction);
            
            // Extract JSON array
            $jsonMatch = [];
            if (preg_match('/\[[^\]]+\]/', $aiResponse, $jsonMatch)) {
                $anomalies = json_decode($jsonMatch[0], true);
                
                return [
                    'success' => true,
                    'anomalies' => $anomalies ?? [],
                    'total_products_analyzed' => count($inventory)
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Failed to parse AI response',
                'anomalies' => []
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'anomalies' => []
            ];
        }
    }
    
    /**
     * Analyze barcode scan and provide context
     */
    public function analyzeBarcodeContext($barcode, $scanLocation = null, $scanPurpose = 'general') {
        try {
            // Look up product
            $stmt = $this->conn->prepare("
                SELECT p.*, c.name as category_name,
                    (SELECT SUM(quantity) FROM inventory WHERE product_id = p.id) as total_stock
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE p.barcode = :barcode
            ");
            $stmt->execute([':barcode' => $barcode]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$product) {
                return [
                    'success' => false,
                    'message' => 'Product not found',
                    'context' => null
                ];
            }
            
            // Get location info if provided
            $locationInfo = '';
            if ($scanLocation) {
                $locationInfo = "Scan Location: {$scanLocation}\n";
            }
            
            $systemInstruction = "You are a warehouse assistant AI. Provide helpful context and suggestions based on barcode scans. Respond ONLY with valid JSON.";
            
            $prompt = "Barcode Scanned: {$barcode}\n";
            $prompt .= "Product: {$product['name']} (SKU: {$product['sku']})\n";
            $prompt .= "Category: {$product['category_name']}\n";
            $prompt .= "Current Stock: {$product['total_stock']} units\n";
            $prompt .= "Reorder Point: {$product['reorder_point']}\n";
            $prompt .= $locationInfo;
            $prompt .= "Scan Purpose: {$scanPurpose}\n\n";
            $prompt .= "Task: Provide helpful context and action suggestions. ";
            $prompt .= 'Respond with JSON: {"status": "ok/warning/critical", "message": "...", "suggestions": ["action1", "action2"], "alerts": []}';
            
            $aiResponse = $this->callAIAPI($prompt, $systemInstruction);
            
            // Extract JSON
            $jsonMatch = [];
            if (preg_match('/\{[^}]+\}/', $aiResponse, $jsonMatch)) {
                $context = json_decode($jsonMatch[0], true);
                
                return [
                    'success' => true,
                    'product' => $product,
                    'context' => $context
                ];
            }
            
            return [
                'success' => true,
                'product' => $product,
                'context' => [
                    'status' => 'ok',
                    'message' => 'Product found',
                    'suggestions' => [],
                    'alerts' => []
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'context' => null
            ];
        }
    }
}
}

?>

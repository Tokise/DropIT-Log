<?php
/**
 * AI Service - Core AI Automation Engine
 * Handles all AI-powered automation features
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/ai_config.php';

class AIService {
    private $db;
    private $conn;
    private $aiConfig;
    
    public function __construct() {
        $this->db = new Database();
        $this->conn = $this->db->getConnection();
        $this->aiConfig = new AIConfig();
    }
    
    /**
     * Call AI API with retry logic
     */
    private function callAI($prompt, $systemPrompt = "", $temperature = 0.7) {
        $apiKey = $this->aiConfig->getApiKey();
        $endpoint = $this->aiConfig->getEndpoint();
        $model = $this->aiConfig->getModel();
        $provider = method_exists($this->aiConfig, 'getProvider') ? $this->aiConfig->getProvider() : 'openai';
        
        if (empty($apiKey)) {
            throw new Exception("AI API key not configured");
        }
        
        $startTime = microtime(true);
        $httpHeaders = ['Content-Type: application/json'];
        $url = $endpoint;
        $payload = [];
        
        if ($provider === 'gemini') {
            // Gemini: API key via query param, body uses contents/parts
            $url = $endpoint . (strpos($endpoint, '?') === false ? '?' : '&') . 'key=' . urlencode($apiKey);
            $payload = [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [ ['text' => $prompt] ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => $temperature,
                    'maxOutputTokens' => 2000
                ]
            ];
            if (!empty($systemPrompt)) {
                $payload['systemInstruction'] = [
                    'role' => 'system',
                    'parts' => [ ['text' => $systemPrompt] ]
                ];
            }
        } else {
            // OpenAI-compatible
            $messages = [];
            if (!empty($systemPrompt)) {
                $messages[] = ["role" => "system", "content" => $systemPrompt];
            }
            $messages[] = ["role" => "user", "content" => $prompt];
            $payload = [
                'model' => $model,
                'messages' => $messages,
                'temperature' => $temperature,
                'max_tokens' => 2000
            ];
            $httpHeaders[] = 'Authorization: Bearer ' . $apiKey;
        }
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        
        $executionTime = round((microtime(true) - $startTime) * 1000);
        
        if ($response === false) {
            $this->logAutomation('ai_api_call', 'cURL error', ['prompt' => $prompt], ['error' => $curlErr], false, $curlErr, $executionTime);
            throw new Exception('AI API cURL error: ' . $curlErr);
        }
        
        if ($httpCode < 200 || $httpCode >= 300) {
            $this->logAutomation('ai_api_call', 'API call failed', 
                ['prompt' => $prompt], 
                ['http_code' => $httpCode, 'response' => $response],
                false, "HTTP $httpCode error", $executionTime
            );
            throw new Exception("AI API call failed with HTTP $httpCode");
        }
        
        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Failed to decode AI response JSON: ' . json_last_error_msg());
        }
        
        // Extract text based on provider
        $content = '';
        if ($provider === 'gemini') {
            // Expect candidates[0].content.parts[*].text
            if (isset($result['candidates'][0]['content']['parts']) && is_array($result['candidates'][0]['content']['parts'])) {
                $parts = $result['candidates'][0]['content']['parts'];
                foreach ($parts as $part) {
                    if (isset($part['text'])) { $content .= $part['text']; }
                }
            } elseif (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                $content = $result['candidates'][0]['content']['parts'][0]['text'];
            } else {
                throw new Exception('Invalid Gemini response format');
            }
        } else {
            if (!isset($result['choices'][0]['message']['content'])) {
                throw new Exception("Invalid OpenAI response format");
            }
            $content = $result['choices'][0]['message']['content'];
        }
        
        $this->logAutomation('ai_api_call', 'Successful AI call',
            ['prompt' => substr($prompt, 0, 200)],
            ['response' => substr($content, 0, 500)],
            true, null, $executionTime
        );
        
        return $content;
    }
    
    /**
     * Demand Forecasting - Predict future demand for products
     */
    public function forecastDemand($productId, $daysAhead = 30) {
        try {
            // Get historical data
            $query = "SELECT DATE(created_at) as date, SUM(quantity) as total_sold
                     FROM sales_order_items soi
                     JOIN sales_orders so ON soi.order_id = so.id
                     WHERE soi.product_id = :product_id 
                     AND so.status IN ('shipped', 'delivered')
                     AND so.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                     GROUP BY DATE(created_at)
                     ORDER BY date ASC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':product_id', $productId);
            $stmt->execute();
            $historicalData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get product info
            $query = "SELECT p.*, c.name as category_name 
                     FROM products p 
                     LEFT JOIN categories c ON p.category_id = c.id 
                     WHERE p.id = :product_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':product_id', $productId);
            $stmt->execute();
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$product) {
                throw new Exception("Product not found");
            }
            
            // Prepare AI prompt
            $systemPrompt = "You are an expert supply chain analyst. Analyze sales data and provide demand forecasts in JSON format only.";
            
            $prompt = "Product: {$product['name']} (SKU: {$product['sku']})\n";
            $prompt .= "Category: {$product['category_name']}\n";
            $prompt .= "Historical Sales (Last 90 days):\n";
            $prompt .= json_encode($historicalData, JSON_PRETTY_PRINT);
            $prompt .= "\n\nAnalyze this data and forecast demand for the next $daysAhead days. ";
            $prompt .= "Consider trends, seasonality, and patterns. ";
            $prompt .= "Respond ONLY with valid JSON in this exact format:\n";
            $prompt .= '{"forecast_daily_avg": <number>, "forecast_total": <number>, "confidence": <0-1>, "trend": "increasing|stable|decreasing", "reasoning": "<brief explanation>"}';
            
            $aiResponse = $this->callAI($prompt, $systemPrompt, 0.3);
            
            // Parse AI response
            $forecast = $this->parseJSONResponse($aiResponse);
            
            if (!isset($forecast['forecast_total']) || !isset($forecast['confidence'])) {
                throw new Exception("Invalid forecast format from AI");
            }
            
            // Save prediction
            $predictionData = [
                'product_id' => $productId,
                'days_ahead' => $daysAhead,
                'forecast_daily_avg' => $forecast['forecast_daily_avg'],
                'forecast_total' => $forecast['forecast_total'],
                'trend' => $forecast['trend'],
                'reasoning' => $forecast['reasoning']
            ];
            
            $this->savePrediction('demand_forecast', $productId, null, 
                $predictionData, $forecast['confidence']);
            
            return $forecast;
            
        } catch (Exception $e) {
            $this->logAutomation('demand_forecasting', 'Forecast failed',
                ['product_id' => $productId],
                ['error' => $e->getMessage()],
                false, $e->getMessage(), 0
            );
            throw $e;
        }
    }
    
    /**
     * Auto Reorder Recommendation - Suggest purchase orders
     */
    public function generateReorderRecommendation($warehouseId = null) {
        try {
            // Get low stock items
            $query = "SELECT i.*, p.*, w.name as warehouse_name,
                     p.reorder_point, p.reorder_quantity, p.lead_time_days,
                     i.available_quantity
                     FROM inventory i
                     JOIN products p ON i.product_id = p.id
                     JOIN warehouses w ON i.warehouse_id = w.id
                     WHERE i.available_quantity <= p.reorder_point
                     AND p.is_active = 1";
            
            if ($warehouseId) {
                $query .= " AND i.warehouse_id = :warehouse_id";
            }
            
            $query .= " ORDER BY (p.reorder_point - i.available_quantity) DESC LIMIT 20";
            
            $stmt = $this->conn->prepare($query);
            if ($warehouseId) {
                $stmt->bindParam(':warehouse_id', $warehouseId);
            }
            $stmt->execute();
            $lowStockItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($lowStockItems)) {
                return ['recommendations' => [], 'message' => 'No items need reordering'];
            }
            
            $recommendations = [];
            
            foreach ($lowStockItems as $item) {
                // Get demand forecast
                try {
                    $forecast = $this->forecastDemand($item['product_id'], 30);
                    $forecastDemand = $forecast['forecast_total'];
                } catch (Exception $e) {
                    // Fallback to simple calculation
                    $forecastDemand = $item['reorder_quantity'];
                }
                
                // Get best supplier
                $supplierQuery = "SELECT sp.*, s.name as supplier_name, s.rating,
                                 s.payment_terms
                                 FROM supplier_products sp
                                 JOIN suppliers s ON sp.supplier_id = s.id
                                 WHERE sp.product_id = :product_id
                                 AND s.is_active = 1
                                 ORDER BY sp.is_preferred DESC, sp.unit_price ASC, s.rating DESC
                                 LIMIT 1";
                
                $stmt = $this->conn->prepare($supplierQuery);
                $stmt->bindParam(':product_id', $item['product_id']);
                $stmt->execute();
                $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$supplier) {
                    continue; // Skip if no supplier available
                }
                
                // Calculate optimal order quantity
                $safetyStockDays = $this->aiConfig->getSetting('reorder_safety_stock_days');
                $dailyDemand = $forecastDemand / 30;
                $safetyStock = ceil($dailyDemand * $safetyStockDays);
                $leadTimeStock = ceil($dailyDemand * $item['lead_time_days']);
                
                $optimalQuantity = max(
                    $item['reorder_quantity'],
                    $safetyStock + $leadTimeStock - $item['available_quantity'],
                    $supplier['minimum_order_quantity']
                );
                
                $recommendations[] = [
                    'product_id' => $item['product_id'],
                    'product_name' => $item['name'],
                    'sku' => $item['sku'],
                    'warehouse_id' => $item['warehouse_id'],
                    'warehouse_name' => $item['warehouse_name'],
                    'current_stock' => $item['available_quantity'],
                    'reorder_point' => $item['reorder_point'],
                    'recommended_quantity' => $optimalQuantity,
                    'supplier_id' => $supplier['supplier_id'],
                    'supplier_name' => $supplier['supplier_name'],
                    'unit_price' => $supplier['unit_price'],
                    'total_cost' => $optimalQuantity * $supplier['unit_price'],
                    'lead_time_days' => $supplier['lead_time_days'],
                    'urgency' => $this->calculateUrgency($item['available_quantity'], $item['reorder_point'], $dailyDemand)
                ];
            }
            
            // Use AI to prioritize and validate recommendations
            if (!empty($recommendations)) {
                $systemPrompt = "You are a procurement expert. Analyze reorder recommendations and provide insights.";
                $prompt = "Review these reorder recommendations and provide priority scores (0-1) for each:\n";
                $prompt .= json_encode($recommendations, JSON_PRETTY_PRINT);
                $prompt .= "\n\nRespond with JSON array with same items but add 'ai_priority_score' and 'ai_notes' fields.";
                
                try {
                    $aiResponse = $this->callAI($prompt, $systemPrompt, 0.3);
                    $aiRecommendations = $this->parseJSONResponse($aiResponse);
                    
                    if (is_array($aiRecommendations)) {
                        $recommendations = $aiRecommendations;
                    }
                } catch (Exception $e) {
                    // Continue with original recommendations if AI fails
                }
            }
            
            return [
                'recommendations' => $recommendations,
                'total_items' => count($recommendations),
                'total_estimated_cost' => array_sum(array_column($recommendations, 'total_cost'))
            ];
            
        } catch (Exception $e) {
            $this->logAutomation('reorder_recommendation', 'Failed to generate recommendations',
                ['warehouse_id' => $warehouseId],
                ['error' => $e->getMessage()],
                false, $e->getMessage(), 0
            );
            throw $e;
        }
    }
    
    /**
     * Optimize Picking Route - AI-powered warehouse routing
     */
    public function optimizePickingRoute($orderId) {
        try {
            // Get order items with locations
            $query = "SELECT soi.*, p.name, p.sku, i.location_code
                     FROM sales_order_items soi
                     JOIN products p ON soi.product_id = p.id
                     JOIN sales_orders so ON soi.order_id = so.id
                     JOIN inventory i ON i.product_id = p.id AND i.warehouse_id = so.warehouse_id
                     WHERE soi.order_id = :order_id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':order_id', $orderId);
            $stmt->execute();
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($items)) {
                throw new Exception("No items found for order");
            }
            
            // Use AI to optimize route
            $systemPrompt = "You are a warehouse logistics expert. Optimize picking routes to minimize travel time.";
            $prompt = "Warehouse layout uses format: Aisle-Rack-Shelf (e.g., A1-R3-S2)\n";
            $prompt .= "Items to pick:\n";
            $prompt .= json_encode($items, JSON_PRETTY_PRINT);
            $prompt .= "\n\nProvide optimal picking sequence to minimize walking distance. ";
            $prompt .= "Respond with JSON: {\"optimized_route\": [{\"item_id\": <id>, \"location\": \"<loc>\", \"sequence\": <num>, \"instructions\": \"<text>\"}], \"estimated_time_minutes\": <num>, \"distance_saved_percent\": <num>}";
            
            $aiResponse = $this->callAI($prompt, $systemPrompt, 0.3);
            $route = $this->parseJSONResponse($aiResponse);
            
            // Update order with optimized route
            $updateQuery = "UPDATE sales_orders 
                           SET ai_optimized_route = :route 
                           WHERE id = :order_id";
            $stmt = $this->conn->prepare($updateQuery);
            $routeJson = json_encode($route);
            $stmt->bindParam(':route', $routeJson);
            $stmt->bindParam(':order_id', $orderId);
            $stmt->execute();
            
            $this->logAutomation('route_optimization', 'Route optimized successfully',
                ['order_id' => $orderId],
                ['route' => $route],
                true, null, 0
            );
            
            return $route;
            
        } catch (Exception $e) {
            $this->logAutomation('route_optimization', 'Route optimization failed',
                ['order_id' => $orderId],
                ['error' => $e->getMessage()],
                false, $e->getMessage(), 0
            );
            throw $e;
        }
    }
    
    /**
     * Anomaly Detection - Detect unusual patterns
     */
    public function detectAnomalies($type = 'inventory', $warehouseId = null) {
        try {
            $anomalies = [];
            
            if ($type === 'inventory' || $type === 'all') {
                // Check for unusual inventory changes
                $query = "SELECT it.*, p.name, p.sku, w.name as warehouse_name
                         FROM inventory_transactions it
                         JOIN products p ON it.product_id = p.id
                         JOIN warehouses w ON it.warehouse_id = w.id
                         WHERE it.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                         AND ABS(it.quantity) > 100
                         ORDER BY it.created_at DESC
                         LIMIT 50";
                
                $stmt = $this->conn->prepare($query);
                $stmt->execute();
                $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($transactions)) {
                    $systemPrompt = "You are a fraud detection and inventory control expert. Identify suspicious patterns.";
                    $prompt = "Analyze these inventory transactions for anomalies (theft, errors, unusual patterns):\n";
                    $prompt .= json_encode($transactions, JSON_PRETTY_PRINT);
                    $prompt .= "\n\nRespond with JSON: {\"anomalies\": [{\"transaction_id\": <id>, \"severity\": \"low|medium|high\", \"reason\": \"<text>\", \"recommended_action\": \"<text>\"}]}";
                    
                    $aiResponse = $this->callAI($prompt, $systemPrompt, 0.2);
                    $result = $this->parseJSONResponse($aiResponse);
                    
                    if (isset($result['anomalies'])) {
                        $anomalies = array_merge($anomalies, $result['anomalies']);
                    }
                }
            }
            
            // Save anomalies as predictions
            foreach ($anomalies as $anomaly) {
                if ($anomaly['severity'] === 'high' || $anomaly['severity'] === 'medium') {
                    $this->savePrediction('anomaly_detection', null, $warehouseId,
                        $anomaly, 0.85);
                    
                    // Create notification for high severity
                    if ($anomaly['severity'] === 'high') {
                        $this->createNotification(
                            1, // Admin user
                            'alert',
                            'Anomaly Detected',
                            $anomaly['reason'] . ' - ' . $anomaly['recommended_action']
                        );
                    }
                }
            }
            
            return [
                'anomalies' => $anomalies,
                'total_found' => count($anomalies),
                'high_severity' => count(array_filter($anomalies, fn($a) => $a['severity'] === 'high'))
            ];
            
        } catch (Exception $e) {
            $this->logAutomation('anomaly_detection', 'Detection failed',
                ['type' => $type],
                ['error' => $e->getMessage()],
                false, $e->getMessage(), 0
            );
            throw $e;
        }
    }
    
    /**
     * Supplier Performance Analysis
     */
    public function analyzeSupplierPerformance($supplierId) {
        try {
            // Get supplier's purchase order history
            $query = "SELECT po.*, 
                     DATEDIFF(po.actual_delivery_date, po.expected_delivery_date) as delay_days,
                     (SELECT COUNT(*) FROM purchase_order_items WHERE po_id = po.id) as item_count
                     FROM purchase_orders po
                     WHERE po.supplier_id = :supplier_id
                     AND po.status IN ('received', 'partially_received')
                     AND po.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                     ORDER BY po.created_at DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':supplier_id', $supplierId);
            $stmt->execute();
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($orders)) {
                return ['rating' => 5.0, 'message' => 'No historical data available'];
            }
            
            $systemPrompt = "You are a supplier relationship management expert. Analyze supplier performance objectively.";
            $prompt = "Analyze this supplier's performance based on their order history:\n";
            $prompt .= json_encode($orders, JSON_PRETTY_PRINT);
            $prompt .= "\n\nProvide analysis in JSON: {\"rating\": <0-5>, \"on_time_delivery_rate\": <0-100>, \"strengths\": [\"<text>\"], \"weaknesses\": [\"<text>\"], \"recommendation\": \"continue|review|replace\", \"reasoning\": \"<text>\"}";
            
            $aiResponse = $this->callAI($prompt, $systemPrompt, 0.3);
            $analysis = $this->parseJSONResponse($aiResponse);
            
            // Update supplier rating
            if (isset($analysis['rating'])) {
                $updateQuery = "UPDATE suppliers SET rating = :rating WHERE id = :supplier_id";
                $stmt = $this->conn->prepare($updateQuery);
                $stmt->bindParam(':rating', $analysis['rating']);
                $stmt->bindParam(':supplier_id', $supplierId);
                $stmt->execute();
            }
            
            return $analysis;
            
        } catch (Exception $e) {
            $this->logAutomation('supplier_analysis', 'Analysis failed',
                ['supplier_id' => $supplierId],
                ['error' => $e->getMessage()],
                false, $e->getMessage(), 0
            );
            throw $e;
        }
    }
    
    /**
     * Helper: Parse JSON from AI response (handles markdown code blocks)
     */
    private function parseJSONResponse($response) {
        // Remove markdown code blocks if present
        $response = preg_replace('/```json\s*/', '', $response);
        $response = preg_replace('/```\s*/', '', $response);
        $response = trim($response);
        
        $decoded = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Failed to parse AI response as JSON: " . json_last_error_msg());
        }
        
        return $decoded;
    }
    
    /**
     * Helper: Calculate urgency level
     */
    private function calculateUrgency($currentStock, $reorderPoint, $dailyDemand) {
        $daysUntilStockout = $dailyDemand > 0 ? $currentStock / $dailyDemand : 999;
        
        if ($daysUntilStockout < 3) return 'critical';
        if ($daysUntilStockout < 7) return 'high';
        if ($daysUntilStockout < 14) return 'medium';
        return 'low';
    }
    
    /**
     * Helper: Save AI prediction to database
     */
    private function savePrediction($type, $productId, $warehouseId, $data, $confidence) {
        $query = "INSERT INTO ai_predictions 
                 (prediction_type, product_id, warehouse_id, prediction_data, confidence_score, valid_until)
                 VALUES (:type, :product_id, :warehouse_id, :data, :confidence, DATE_ADD(NOW(), INTERVAL 7 DAY))";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':product_id', $productId);
        $stmt->bindParam(':warehouse_id', $warehouseId);
        $dataJson = json_encode($data);
        $stmt->bindParam(':data', $dataJson);
        $stmt->bindParam(':confidence', $confidence);
        $stmt->execute();
        
        return $this->conn->lastInsertId();
    }
    
    /**
     * Helper: Log automation activity
     */
    private function logAutomation($type, $action, $input, $output, $success, $error = null, $execTime = 0) {
        $query = "INSERT INTO ai_automation_logs 
                 (automation_type, action_taken, input_data, output_data, success, error_message, execution_time_ms)
                 VALUES (:type, :action, :input, :output, :success, :error, :exec_time)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':action', $action);
        $inputJson = json_encode($input);
        $outputJson = json_encode($output);
        $stmt->bindParam(':input', $inputJson);
        $stmt->bindParam(':output', $outputJson);
        $stmt->bindParam(':success', $success, PDO::PARAM_BOOL);
        $stmt->bindParam(':error', $error);
        $stmt->bindParam(':exec_time', $execTime);
        $stmt->execute();
    }
    
    /**
     * Analyze PO status change
     */
    public function analyzeStatusChange($poId, $currentStatus, $proposedStatus) {
        try {
            // Get PO details
            $stmt = $this->conn->prepare("SELECT po.*, s.name as supplier_name, COUNT(poi.id) as item_count, 
                                          DATEDIFF(NOW(), po.order_date) as days_since_order
                                          FROM purchase_orders po 
                                          JOIN suppliers s ON po.supplier_id = s.id
                                          LEFT JOIN purchase_order_items poi ON po.id = poi.po_id
                                          WHERE po.id = :po_id
                                          GROUP BY po.id");
            $stmt->execute([':po_id' => $poId]);
            $po = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$po) {
                return ['approved' => false, 'confidence' => 0, 'notes' => 'PO not found'];
            }
            
            $prompt = "Analyze this purchase order status change:\n\n";
            $prompt .= "PO Number: {$po['po_number']}\n";
            $prompt .= "Supplier: {$po['supplier_name']}\n";
            $prompt .= "Current Status: $currentStatus\n";
            $prompt .= "Proposed Status: $proposedStatus\n";
            $prompt .= "Total Amount: ₱{$po['total_amount']}\n";
            $prompt .= "Item Count: {$po['item_count']}\n";
            $prompt .= "Days Since Order: {$po['days_since_order']}\n";
            $prompt .= "Expected Delivery: {$po['expected_delivery_date']}\n\n";
            $prompt .= "Should this status change be approved? Consider:\n";
            $prompt .= "1. Is the transition logical?\n";
            $prompt .= "2. Is the timing appropriate?\n";
            $prompt .= "3. Are there any red flags?\n\n";
            $prompt .= "Respond in JSON format: {\"approved\": true/false, \"confidence\": 0.0-1.0, \"notes\": \"explanation\"}";
            
            $systemPrompt = "You are an AI assistant analyzing purchase order status changes for a logistics system. Be cautious but practical.";
            
            $response = $this->callAI($prompt, $systemPrompt, 0.3);
            
            // Parse JSON response
            $result = json_decode($response, true);
            
            if (!$result) {
                // Fallback if JSON parsing fails
                return ['approved' => true, 'confidence' => 0.5, 'notes' => 'AI analysis completed'];
            }
            
            return $result;
            
        } catch (Exception $e) {
            // On error, approve but with low confidence
            return ['approved' => true, 'confidence' => 0.3, 'notes' => 'AI analysis unavailable: ' . $e->getMessage()];
        }
    }
    
    /**
     * Predict delivery date for PO
     */
    public function predictDeliveryDate($supplierId, $items) {
        try {
            // Get supplier historical data
            $stmt = $this->conn->prepare("SELECT AVG(DATEDIFF(received_at, order_date)) as avg_delivery_days
                                          FROM purchase_orders 
                                          WHERE supplier_id = :supplier_id 
                                          AND status = 'received' 
                                          AND received_at IS NOT NULL 
                                          AND order_date IS NOT NULL");
            $stmt->execute([':supplier_id' => $supplierId]);
            $history = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $avgDays = $history['avg_delivery_days'] ?? 7; // Default 7 days
            
            $itemCount = count($items);
            $totalQty = array_sum(array_column($items, 'quantity'));
            
            $prompt = "Predict delivery time for a purchase order:\n\n";
            $prompt .= "Supplier's average delivery time: $avgDays days\n";
            $prompt .= "Number of different items: $itemCount\n";
            $prompt .= "Total quantity: $totalQty units\n\n";
            $prompt .= "Consider complexity and volume. Provide estimated delivery days as a single number.";
            
            $response = $this->callAI($prompt, "You are a logistics AI predicting delivery times.", 0.5);
            
            // Extract number from response
            preg_match('/\d+/', $response, $matches);
            $predictedDays = isset($matches[0]) ? (int)$matches[0] : $avgDays;
            
            // Calculate delivery date
            $deliveryDate = date('Y-m-d', strtotime("+$predictedDays days"));
            
            return $deliveryDate;
            
        } catch (Exception $e) {
            // Fallback to 7 days
            return date('Y-m-d', strtotime('+7 days'));
        }
    }
    
    /**
     * Helper: Create notification
     */
    private function createNotification($userId, $type, $title, $message, $actionUrl = null) {
        $query = "INSERT INTO notifications (user_id, type, title, message, action_url)
                 VALUES (:user_id, :type, :title, :message, :action_url)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':title', $title);
        $stmt->bindParam(':message', $message);
        $stmt->bindParam(':action_url', $actionUrl);
        $stmt->execute();
    }
}
?>

<?php
require_once 'common.php';

header('Content-Type: application/json');

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    $auth = require_auth();
    
    switch ($method) {
        case 'GET':
            if ($action === 'sessions') {
                getChatSessions();
            } elseif ($action === 'messages') {
                getChatMessages($_GET['session_id'] ?? '');
            } elseif ($action === 'recommendations') {
                getRecommendations();
            } else {
                json_err('Invalid action', 400);
            }
            break;
            
        case 'POST':
            if ($action === 'chat') {
                processChat();
            } elseif ($action === 'analyze') {
                analyzeData();
            } elseif ($action === 'recommend') {
                generateRecommendations();
            } else {
                json_err('Invalid action', 400);
            }
            break;
            
        case 'PUT':
            if ($action === 'accept_recommendation') {
                acceptRecommendation();
            } elseif ($action === 'reject_recommendation') {
                rejectRecommendation();
            } else {
                json_err('Invalid action', 400);
            }
            break;
            
        default:
            json_err('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    json_err('Server error: ' . $e->getMessage(), 500);
}

function getChatSessions() {
    global $conn, $auth;
    
    $stmt = $conn->prepare("
        SELECT 
            acs.*,
            COUNT(acm.id) as message_count
        FROM ai_chat_sessions acs
        LEFT JOIN ai_chat_messages acm ON acs.id = acm.session_id
        WHERE acs.user_id = :user_id
        GROUP BY acs.id
        ORDER BY acs.last_activity DESC
        LIMIT 10
    ");
    $stmt->execute([':user_id' => $auth['user_id']]);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    json_ok(['sessions' => $sessions]);
}

function getChatMessages($sessionId) {
    global $conn, $auth;
    
    if (!$sessionId) {
        json_err('Session ID required', 400);
    }
    
    // Verify session belongs to user
    $stmt = $conn->prepare("
        SELECT id FROM ai_chat_sessions 
        WHERE id = :session_id AND user_id = :user_id
    ");
    $stmt->execute([
        ':session_id' => $sessionId,
        ':user_id' => $auth['user_id']
    ]);
    
    if (!$stmt->fetch()) {
        json_err('Session not found', 404);
    }
    
    $stmt = $conn->prepare("
        SELECT * FROM ai_chat_messages
        WHERE session_id = :session_id
        ORDER BY created_at ASC
    ");
    $stmt->execute([':session_id' => $sessionId]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    json_ok(['messages' => $messages]);
}

function getRecommendations() {
    global $conn, $auth;
    
    $module = $_GET['module'] ?? '';
    $status = $_GET['status'] ?? 'pending';
    
    $where = "WHERE user_id = :user_id AND status = :status";
    $params = [
        ':user_id' => $auth['user_id'],
        ':status' => $status
    ];
    
    if ($module) {
        $where .= " AND module = :module";
        $params[':module'] = $module;
    }
    
    $stmt = $conn->prepare("
        SELECT * FROM ai_recommendations
        $where
        ORDER BY confidence_score DESC, created_at DESC
        LIMIT 20
    ");
    $stmt->execute($params);
    $recommendations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    json_ok(['recommendations' => $recommendations]);
}

function processChat() {
    global $conn, $auth;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['message'])) {
        json_err('Message required', 400);
    }
    
    $sessionId = $data['session_id'] ?? null;
    $message = $data['message'];
    $context = $data['context'] ?? [];
    
    $conn->beginTransaction();
    
    try {
        // Create or get session
        if (!$sessionId) {
            $sessionToken = 'session_' . $auth['user_id'] . '_' . time();
            $stmt = $conn->prepare("
                INSERT INTO ai_chat_sessions (user_id, session_token, context_data)
                VALUES (:user_id, :session_token, :context_data)
            ");
            $stmt->execute([
                ':user_id' => $auth['user_id'],
                ':session_token' => $sessionToken,
                ':context_data' => json_encode($context)
            ]);
            $sessionId = $conn->lastInsertId();
        } else {
            // Update session activity
            $stmt = $conn->prepare("
                UPDATE ai_chat_sessions 
                SET last_activity = NOW(), context_data = :context_data
                WHERE id = :session_id AND user_id = :user_id
            ");
            $stmt->execute([
                ':context_data' => json_encode($context),
                ':session_id' => $sessionId,
                ':user_id' => $auth['user_id']
            ]);
        }
        
        // Save user message
        $stmt = $conn->prepare("
            INSERT INTO ai_chat_messages (session_id, message_type, message_content)
            VALUES (:session_id, 'user', :message_content)
        ");
        $stmt->execute([
            ':session_id' => $sessionId,
            ':message_content' => $message
        ]);
        
        // Get AI response
        $aiResponse = getAIResponse($message, $context, $auth);
        
        // Save AI response
        $stmt = $conn->prepare("
            INSERT INTO ai_chat_messages (session_id, message_type, message_content, metadata)
            VALUES (:session_id, 'assistant', :message_content, :metadata)
        ");
        $stmt->execute([
            ':session_id' => $sessionId,
            ':message_content' => $aiResponse['message'],
            ':metadata' => json_encode($aiResponse['metadata'] ?? [])
        ]);
        
        $conn->commit();
        
        json_ok([
            'session_id' => $sessionId,
            'response' => $aiResponse['message'],
            'metadata' => $aiResponse['metadata'] ?? []
        ]);
        
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}

function analyzeData() {
    global $conn, $auth;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['module']) || !isset($data['analysis_type'])) {
        json_err('Module and analysis type required', 400);
    }
    
    $module = $data['module'];
    $analysisType = $data['analysis_type'];
    $parameters = $data['parameters'] ?? [];
    
    // Get relevant data based on module and analysis type
    $analysisData = getAnalysisData($module, $analysisType, $parameters);
    
    // Send to AI for analysis
    $aiAnalysis = getAIAnalysis($analysisData, $analysisType, $auth);
    
    json_ok([
        'analysis' => $aiAnalysis,
        'data_points' => count($analysisData),
        'generated_at' => date('Y-m-d H:i:s')
    ]);
}

function generateRecommendations() {
    global $conn, $auth;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['module'])) {
        json_err('Module required', 400);
    }
    
    $module = $data['module'];
    $context = $data['context'] ?? [];
    
    // Get module-specific data for recommendations
    $moduleData = getModuleData($module, $auth);
    
    // Generate AI recommendations
    $recommendations = getAIRecommendations($module, $moduleData, $context, $auth);
    
    // Save recommendations to database
    foreach ($recommendations as $rec) {
        $stmt = $conn->prepare("
            INSERT INTO ai_recommendations (
                user_id, module, recommendation_type, title, description,
                action_data, confidence_score
            ) VALUES (
                :user_id, :module, :recommendation_type, :title, :description,
                :action_data, :confidence_score
            )
        ");
        
        $stmt->execute([
            ':user_id' => $auth['user_id'],
            ':module' => $module,
            ':recommendation_type' => $rec['type'],
            ':title' => $rec['title'],
            ':description' => $rec['description'],
            ':action_data' => json_encode($rec['action_data'] ?? []),
            ':confidence_score' => $rec['confidence_score']
        ]);
    }
    
    json_ok([
        'recommendations' => $recommendations,
        'count' => count($recommendations)
    ]);
}

function acceptRecommendation() {
    global $conn, $auth;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['recommendation_id'])) {
        json_err('Recommendation ID required', 400);
    }
    
    $stmt = $conn->prepare("
        UPDATE ai_recommendations 
        SET status = 'accepted', executed_at = NOW()
        WHERE id = :id AND user_id = :user_id AND status = 'pending'
    ");
    
    $stmt->execute([
        ':id' => $data['recommendation_id'],
        ':user_id' => $auth['user_id']
    ]);
    
    if ($stmt->rowCount() === 0) {
        json_err('Recommendation not found or already processed', 404);
    }
    
    json_ok(['message' => 'Recommendation accepted']);
}

function rejectRecommendation() {
    global $conn, $auth;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['recommendation_id'])) {
        json_err('Recommendation ID required', 400);
    }
    
    $stmt = $conn->prepare("
        UPDATE ai_recommendations 
        SET status = 'rejected'
        WHERE id = :id AND user_id = :user_id AND status = 'pending'
    ");
    
    $stmt->execute([
        ':id' => $data['recommendation_id'],
        ':user_id' => $auth['user_id']
    ]);
    
    if ($stmt->rowCount() === 0) {
        json_err('Recommendation not found or already processed', 404);
    }
    
    json_ok(['message' => 'Recommendation rejected']);
}

function getAIResponse($message, $context, $auth) {
    $geminiApiKey = $_ENV['GEMINI_API_KEY'] ?? '';
    
    if (empty($geminiApiKey)) {
        return [
            'message' => 'AI service is not configured. Please contact your administrator.',
            'metadata' => ['error' => 'missing_api_key']
        ];
    }
    
    // Prepare context for AI
    $systemPrompt = buildSystemPrompt($context, $auth);
    
    // Call Gemini API
    $response = callGeminiAPI($geminiApiKey, $systemPrompt, $message);
    
    return [
        'message' => $response['message'] ?? 'Sorry, I could not process your request.',
        'metadata' => $response['metadata'] ?? []
    ];
}

function getAIAnalysis($data, $analysisType, $auth) {
    $geminiApiKey = $_ENV['GEMINI_API_KEY'] ?? '';
    
    if (empty($geminiApiKey)) {
        return 'AI analysis service is not available.';
    }
    
    $prompt = buildAnalysisPrompt($data, $analysisType);
    $response = callGeminiAPI($geminiApiKey, $prompt, '');
    
    return $response['message'] ?? 'Analysis could not be completed.';
}

function getAIRecommendations($module, $moduleData, $context, $auth) {
    $geminiApiKey = $_ENV['GEMINI_API_KEY'] ?? '';
    
    if (empty($geminiApiKey)) {
        return [];
    }
    
    $prompt = buildRecommendationPrompt($module, $moduleData, $context);
    $response = callGeminiAPI($geminiApiKey, $prompt, '');
    
    // Parse AI response into structured recommendations
    return parseRecommendations($response['message'] ?? '');
}

function callGeminiAPI($apiKey, $systemPrompt, $userMessage) {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent?key=' . $apiKey;
    
    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $systemPrompt . "\n\nUser: " . $userMessage]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.7,
            'topK' => 40,
            'topP' => 0.95,
            'maxOutputTokens' => 1024
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return ['message' => 'AI service temporarily unavailable.'];
    }
    
    $decoded = json_decode($response, true);
    
    if (isset($decoded['candidates'][0]['content']['parts'][0]['text'])) {
        return ['message' => $decoded['candidates'][0]['content']['parts'][0]['text']];
    }
    
    return ['message' => 'Could not get AI response.'];
}

function buildSystemPrompt($context, $auth) {
    $module = $context['module'] ?? 'general';
    $role = $auth['role'] ?? 'user';
    
    $prompt = "You are an AI assistant for the DropIT Logistics Management System. ";
    $prompt .= "You help users with warehouse management, procurement, shipment tracking, asset management, and document management. ";
    $prompt .= "Current user role: $role. Current module: $module. ";
    $prompt .= "Provide helpful, accurate, and actionable responses. ";
    $prompt .= "If you need specific data, ask the user to provide it. ";
    $prompt .= "Keep responses concise and professional.";
    
    return $prompt;
}

function buildAnalysisPrompt($data, $analysisType) {
    $prompt = "Analyze the following logistics data for $analysisType analysis:\n\n";
    $prompt .= json_encode($data, JSON_PRETTY_PRINT);
    $prompt .= "\n\nProvide insights, trends, and actionable recommendations based on this data.";
    
    return $prompt;
}

function buildRecommendationPrompt($module, $moduleData, $context) {
    $prompt = "Based on the following $module module data, generate 3-5 actionable recommendations:\n\n";
    $prompt .= json_encode($moduleData, JSON_PRETTY_PRINT);
    $prompt .= "\n\nFormat each recommendation as: TITLE|DESCRIPTION|CONFIDENCE_SCORE|TYPE";
    $prompt .= "\nConfidence score should be between 0.5 and 1.0";
    
    return $prompt;
}

function parseRecommendations($aiResponse) {
    $recommendations = [];
    $lines = explode("\n", $aiResponse);
    
    foreach ($lines as $line) {
        if (strpos($line, '|') !== false) {
            $parts = explode('|', $line);
            if (count($parts) >= 4) {
                $recommendations[] = [
                    'title' => trim($parts[0]),
                    'description' => trim($parts[1]),
                    'confidence_score' => (float)trim($parts[2]),
                    'type' => trim($parts[3]),
                    'action_data' => []
                ];
            }
        }
    }
    
    return $recommendations;
}

function getAnalysisData($module, $analysisType, $parameters) {
    global $conn;
    
    switch ($module) {
        case 'sws':
            return getSWSAnalysisData($analysisType, $parameters);
        case 'psm':
            return getPSMAnalysisData($analysisType, $parameters);
        case 'plt':
            return getPLTAnalysisData($analysisType, $parameters);
        case 'alms':
            return getALMSAnalysisData($analysisType, $parameters);
        case 'dtlrs':
            return getDTLRSAnalysisData($analysisType, $parameters);
        default:
            return [];
    }
}

function getModuleData($module, $auth) {
    global $conn;
    
    switch ($module) {
        case 'sws':
            return getSWSModuleData();
        case 'psm':
            return getPSMModuleData();
        case 'plt':
            return getPLTModuleData();
        case 'alms':
            return getALMSModuleData();
        case 'dtlrs':
            return getDTLRSModuleData();
        default:
            return [];
    }
}

function getSWSAnalysisData($analysisType, $parameters) {
    global $conn;
    
    if ($analysisType === 'inventory_levels') {
        $stmt = $conn->prepare("
            SELECT p.name, p.sku, i.quantity, i.reserved_quantity, 
                   p.reorder_point, p.reorder_quantity
            FROM inventory i
            JOIN products p ON i.product_id = p.id
            WHERE i.quantity > 0
            ORDER BY i.quantity ASC
            LIMIT 50
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    return [];
}

function getPSMAnalysisData($analysisType, $parameters) {
    global $conn;
    
    if ($analysisType === 'supplier_performance') {
        $stmt = $conn->prepare("
            SELECT s.name, sp.on_time_rate, sp.quality_rate, sp.overall_rating
            FROM suppliers s
            JOIN supplier_performance sp ON s.id = sp.supplier_id
            ORDER BY sp.overall_rating DESC
            LIMIT 20
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    return [];
}

function getPLTAnalysisData($analysisType, $parameters) {
    global $conn;
    
    if ($analysisType === 'delivery_performance') {
        $stmt = $conn->prepare("
            SELECT status, COUNT(*) as count,
                   AVG(TIMESTAMPDIFF(HOUR, shipped_at, delivered_at)) as avg_delivery_hours
            FROM shipments
            WHERE shipped_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY status
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    return [];
}

function getALMSAnalysisData($analysisType, $parameters) {
    global $conn;
    
    if ($analysisType === 'maintenance_due') {
        $stmt = $conn->prepare("
            SELECT a.asset_name, a.asset_type, a.next_maintenance_date,
                   DATEDIFF(a.next_maintenance_date, CURDATE()) as days_until_due
            FROM assets a
            WHERE a.next_maintenance_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            AND a.status = 'active'
            ORDER BY a.next_maintenance_date ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    return [];
}

function getDTLRSAnalysisData($analysisType, $parameters) {
    global $conn;
    
    if ($analysisType === 'document_compliance') {
        $stmt = $conn->prepare("
            SELECT document_type, status, COUNT(*) as count
            FROM documents
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY document_type, status
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    return [];
}

function getSWSModuleData() {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT 
            (SELECT COUNT(*) FROM products WHERE is_active = 1) as active_products,
            (SELECT COUNT(*) FROM inventory WHERE quantity < 10) as low_stock_items,
            (SELECT SUM(quantity * unit_price) FROM inventory i JOIN products p ON i.product_id = p.id) as total_inventory_value
    ");
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getPSMModuleData() {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT 
            (SELECT COUNT(*) FROM purchase_orders WHERE status = 'pending_approval') as pending_pos,
            (SELECT COUNT(*) FROM suppliers WHERE is_active = 1) as active_suppliers,
            (SELECT AVG(overall_rating) FROM supplier_performance) as avg_supplier_rating
    ");
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getPLTModuleData() {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT 
            (SELECT COUNT(*) FROM shipments WHERE status = 'in_transit') as active_shipments,
            (SELECT COUNT(*) FROM shipments WHERE status = 'delivered' AND delivered_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as recent_deliveries,
            (SELECT COUNT(*) FROM shipments WHERE estimated_delivery < NOW() AND status != 'delivered') as overdue_shipments
    ");
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getALMSModuleData() {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT 
            (SELECT COUNT(*) FROM assets WHERE status = 'active') as active_assets,
            (SELECT COUNT(*) FROM assets WHERE next_maintenance_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)) as maintenance_due,
            (SELECT SUM(current_value) FROM assets WHERE status = 'active') as total_asset_value
    ");
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getDTLRSModuleData() {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT 
            (SELECT COUNT(*) FROM documents WHERE status = 'pending_approval') as pending_documents,
            (SELECT COUNT(*) FROM documents WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as recent_documents,
            (SELECT COUNT(DISTINCT document_type) FROM documents) as document_types
    ");
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

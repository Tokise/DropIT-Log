<?php
require_once 'common.php';

header('Content-Type: application/json');
cors_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Only POST method allowed', 405);
}

try {
    $data = read_json_body();
    require_params($data, ['image']);
    
    $imageData = $data['image'];
    $productName = $data['product_name'] ?? '';
    $description = $data['description'] ?? '';
    
    // Remove data URL prefix if present
    if (strpos($imageData, 'data:image') === 0) {
        $imageData = preg_replace('/^data:image\/[^;]+;base64,/', '', $imageData);
    }
    
    // Validate base64 image
    $decodedImage = base64_decode($imageData, true);
    if ($decodedImage === false) {
        json_err('Invalid image data', 422);
    }
    
    // Get image info
    $imageInfo = getimagesizefromstring($decodedImage);
    if ($imageInfo === false) {
        json_err('Invalid image format', 422);
    }
    $imageMime = isset($imageInfo['mime']) ? $imageInfo['mime'] : 'image/jpeg';
    
    // Get existing categories from database FIRST
    $conn = db_conn();
    $stmt = $conn->prepare("SELECT id, name, description, location_prefix, zone_letter, aisle_range FROM categories ORDER BY name");
    $stmt->execute();
    $existingCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // AI Analysis using Gemini Vision API with database categories
    $aiAnalysis = analyzeProductImage($imageData, $productName, $description, $imageMime, $existingCategories);
    
    // Find best matching category or suggest new one
    $categoryMatch = findBestCategoryMatch($aiAnalysis, $existingCategories);
    
    json_ok([
        'ai_analysis' => $aiAnalysis,
        'suggested_category' => $categoryMatch['category'],
        'confidence' => $categoryMatch['confidence'],
        'location_suggestion' => $categoryMatch['location'],
        'existing_categories' => $existingCategories,
        'create_new_category' => $categoryMatch['create_new']
    ]);
    
} catch (Exception $e) {
    json_err($e->getMessage(), 500);
}

function analyzeProductImage($imageData, $productName = '', $description = '', $imageMime = 'image/jpeg', $existingCategories = []) {
    // Load environment variables
    $envFile = __DIR__ . '/../.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                list($key, $value) = explode('=', $line, 2);
                $_ENV[trim($key)] = trim($value);
            }
        }
    }
    
    $geminiApiKey = $_ENV['GEMINI_API_KEY'] ?? null;
    
    if (!$geminiApiKey) {
        // Fallback to keyword-based analysis if no API key
        error_log("No Gemini API key found, using fallback analysis");
        return fallbackKeywordAnalysis($productName, $description);
    }
    
    try {
        // Use Gemini Vision API for image analysis
        error_log("Attempting Gemini API call for image analysis");
        $result = analyzeWithGemini($imageData, $productName, $description, $geminiApiKey, $imageMime, $existingCategories);
        error_log("Gemini API success: " . json_encode($result));
        return $result;
    } catch (Exception $e) {
        // Fallback to keyword analysis if API fails
        error_log("Gemini API failed: " . $e->getMessage());
        $fallbackResult = fallbackKeywordAnalysis($productName, $description);
        // Don't expose debug info to user, just return clean fallback result
        return $fallbackResult;
    }
}

function analyzeWithGemini($imageData, $productName, $description, $apiKey, $imageMime = 'image/jpeg', $existingCategories = []) {
    // Use the best available models from your API key - prioritize latest and fastest
    $models = [
        'models/gemini-2.5-flash',           // Latest stable multimodal model
        'models/gemini-2.0-flash',           // Fast and versatile multimodal 
        'models/gemini-2.5-pro',             // Most advanced reasoning
        'models/gemini-flash-latest',        // Latest flash version
        'models/gemini-1.5-flash',           // Stable fallback
        'models/gemini-pro-latest'           // Latest pro version
    ];
    
    $lastError = null;
    
    foreach ($models as $model) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $apiKey;
        
        try {
            return makeGeminiRequest($url, $imageData, $productName, $description, $imageMime, $existingCategories);
        } catch (Exception $e) {
            $lastError = $e;
            error_log("Model {$model} failed: " . $e->getMessage());
            continue;
        }
    }
    
    throw $lastError ?: new Exception("All Gemini models failed");
}

function buildCategoryListForPrompt($existingCategories) {
    if (empty($existingCategories)) {
        // Fallback to default categories if database is empty
        return "- Clothing\n    - Electronics\n    - Office Supplies\n    - Food Items\n    - Home & Garden\n    - Sports & Outdoors\n    - Books & Media\n    - Health & Beauty\n    - Automotive\n    - Tools & Hardware\n    - Toys & Games";
    }
    
    $categoryLines = [];
    foreach ($existingCategories as $cat) {
        $name = $cat['name'];
        $desc = !empty($cat['description']) ? " ({$cat['description']})" : "";
        $categoryLines[] = "- {$name}{$desc}";
    }
    
    return implode("\n    ", $categoryLines);
}

function makeGeminiRequest($url, $imageData, $productName, $description, $imageMime = 'image/jpeg', $existingCategories = []) {
    
    // Build category list from database
    $categoryList = buildCategoryListForPrompt($existingCategories);
    
    $prompt = "Analyze this product image carefully and categorize it based on what you actually see in the image. Consider the product name: '$productName' and description: '$description', but prioritize what you visually observe.
    
    Choose the most appropriate category from these available categories in our warehouse:
    $categoryList
    
    IMPORTANT CATEGORIZATION RULES:
    - Clothing items: t-shirts, shirts, pants, dresses, jackets, shoes, hats, etc.
    - Fashion accessories & Jewelry: necklaces, bracelets, rings, earrings, chains, bangles, watches (non-smart), belts, scarves - ALL categorize as 'Clothing'
    - Electronics: phones, computers, laptops, tablets, smart devices, electronic gadgets
    - Office Supplies: pens, paper, notebooks, staplers, folders, desk accessories
    - If the item doesn't fit any existing category, you can suggest a new category name

    FEW-SHOT EXAMPLES:
    - Gold necklace with pendant → Clothing
    - Set of bracelets and rings → Clothing
    - Black t-shirt → Clothing
    - Smartwatch with display → Electronics
    - Box of pens and paper → Office Supplies
    - Laptop computer → Electronics
    
    Respond in JSON format with:
    {
        \"detected_category\": \"exact category name from the list above OR suggest a new category name\",
        \"confidence_score\": number between 1-100,
        \"description\": \"detailed description of what you see in the image and why you categorized it this way\",
        \"key_features\": [\"list of key visual features you identified in the image\"],
        \"is_new_category\": true/false (true if suggesting a new category not in the list)
    }";
    
    // Prepare the request payload
    $parts = [
        [
            "text" => $prompt
        ]
    ];
    
    // Add image if provided
    if ($imageData) {
        $parts[] = [
            "inline_data" => [
                "mime_type" => $imageMime,
                "data" => $imageData
            ]
        ];
    }
    
    $payload = [
        "contents" => [
            [
                "parts" => $parts
            ]
        ],
        "generationConfig" => [
            "temperature" => 0.2,
            "topK" => 32,
            "topP" => 1,
            "maxOutputTokens" => 1024
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception("Gemini API returned HTTP $httpCode: $response");
    }
    
    $data = json_decode($response, true);
    
    if (!$data || !isset($data['candidates'][0]['content']['parts'][0]['text'])) {
        throw new Exception("Invalid response from Gemini API");
    }
    
    $aiResponse = $data['candidates'][0]['content']['parts'][0]['text'];
    
    // Extract JSON from the response
    $jsonStart = strpos($aiResponse, '{');
    $jsonEnd = strrpos($aiResponse, '}');
    
    if ($jsonStart !== false && $jsonEnd !== false) {
        $jsonStr = substr($aiResponse, $jsonStart, $jsonEnd - $jsonStart + 1);
        $result = json_decode($jsonStr, true);
        
        if ($result) {
            $det = $result['detected_category'] ?? 'General';
            $det = normalizeDetectedCategory($det, $productName, $description);
            return [
                'detected_category' => $det,
                'confidence_score' => $result['confidence_score'] ?? 50,
                'description' => $result['description'] ?? 'AI analysis completed',
                'keywords_found' => $result['key_features'] ?? [],
                'is_new_category' => $result['is_new_category'] ?? false
            ];
        }
    }
    
    throw new Exception("Could not parse AI response");
}

// Map synonyms and obvious items to allowed categories
function normalizeDetectedCategory($detected, $productName, $description) {
    $allowed = [
        'Electronics','Office Supplies','Raw Materials','Electronic Devices','Clothing','Food Items','Home & Garden','Sports & Outdoors','Books & Media','Health & Beauty','Automotive','Tools & Hardware','Toys & Games'
    ];
    $detTrim = trim($detected);
    // If already valid, return as-is
    foreach ($allowed as $a) {
        if (strcasecmp($a, $detTrim) === 0) return $a;
    }

    $text = strtolower($productName . ' ' . $description . ' ' . $detected);
    $jewelryKeywords = ['necklace','bracelet','ring','earring','earrings','jewelry','jewellery','pendant','chain','bangle','anklet','accessory','accessories'];
    foreach ($jewelryKeywords as $k) {
        if (strpos($text, $k) !== false) return 'Clothing';
    }

    // Common fallbacks
    if (strpos($text, 'shirt') !== false || strpos($text, 't-shirt') !== false || strpos($text, 'clothing') !== false || strpos($text, 'apparel') !== false) return 'Clothing';
    if (strpos($text, 'pen') !== false || strpos($text, 'paper') !== false || strpos($text, 'notebook') !== false) return 'Office Supplies';

    // Default to Clothing for wearable-looking misclassifications rather than Other/General
    if (preg_match('/wear|fashion|accessor/i', $text)) return 'Clothing';

    return $detTrim; // return original if no mapping applies
}

function fallbackKeywordAnalysis($productName, $description) {
    $text = strtolower($productName . ' ' . $description);
    
    // Map to match your database categories
    $categoryKeywords = [
        'Clothing' => ['shirt', 'pants', 'jacket', 'dress', 'clothing', 'apparel', 'fashion', 'wear', 'shoes', 'hat', 't-shirt', 'tshirt', 'polo', 'blouse', 'sweater', 'hoodie', 'jeans', 'shorts', 'necklace', 'bracelet', 'ring', 'earring', 'jewelry', 'pendant', 'chain', 'bangle', 'accessory'],
        'Electronics' => ['phone', 'computer', 'laptop', 'tablet', 'electronic', 'device', 'gadget', 'tech', 'smartphone', 'monitor', 'cpu', 'motherboard', 'ram'],
        'Electronic Devices' => ['circuit', 'component', 'resistor', 'capacitor', 'microchip', 'sensor', 'arduino', 'raspberry'],
        'Office Supplies' => ['pen', 'paper', 'notebook', 'stapler', 'folder', 'binder', 'office', 'stationery', 'pencil', 'marker'],
        'Raw Materials' => ['material', 'raw', 'component', 'part', 'supply', 'manufacturing', 'industrial', 'bulk'],
        'Food Items' => ['food', 'drink', 'beverage', 'snack', 'meal', 'nutrition', 'coffee', 'tea', 'juice', 'candy', 'chocolate'],
        'Home & Garden' => ['home', 'house', 'garden', 'furniture', 'decor', 'kitchen', 'chair', 'table', 'lamp', 'plant'],
        'Sports & Outdoors' => ['sport', 'outdoor', 'fitness', 'exercise', 'athletic', 'gym', 'ball', 'equipment', 'bike', 'run'],
        'Books & Media' => ['book', 'media', 'movie', 'music', 'cd', 'dvd', 'magazine', 'novel', 'textbook', 'guide'],
        'Health & Beauty' => ['health', 'beauty', 'cosmetic', 'skincare', 'makeup', 'wellness', 'cream', 'lotion', 'shampoo'],
        'Automotive' => ['car', 'auto', 'vehicle', 'automotive', 'engine', 'tire', 'brake', 'oil', 'motor'],
        'Tools & Hardware' => ['tool', 'hardware', 'screw', 'nail', 'hammer', 'wrench', 'drill', 'saw', 'bolt'],
        'Toys & Games' => ['toy', 'game', 'play', 'children', 'kid', 'puzzle', 'doll', 'action figure', 'board game']
    ];
    
    $scores = [];
    foreach ($categoryKeywords as $category => $keywords) {
        $score = 0;
        foreach ($keywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                $score += 1;
            }
        }
        $scores[$category] = $score;
    }
    
    arsort($scores);
    $topCategory = array_key_first($scores);
    $confidence = $scores[$topCategory] > 0 ? min(90, $scores[$topCategory] * 20) : 30;
    
    // If no keywords match, try to infer from filename or basic analysis
    if ($confidence < 40) {
        // Default fallback logic
        if (strpos($text, 'shirt') !== false || strpos($text, 'clothing') !== false) {
            $topCategory = 'Clothing';
            $confidence = 60;
        } elseif (strpos($text, 'electronic') !== false || strpos($text, 'device') !== false) {
            $topCategory = 'Electronics';
            $confidence = 60;
        } else {
            $topCategory = 'Office Supplies'; // Safe default
            $confidence = 30;
        }
    }
    
    return [
        'detected_category' => $topCategory,
        'confidence_score' => $confidence,
        'description' => "Based on keyword analysis of the product name and description, this appears to be a {$topCategory} item.",
        'keywords_found' => array_keys(array_filter($scores, function($score) { return $score > 0; }))
    ];
}

function findBestCategoryMatch($aiAnalysis, $existingCategories) {
    $detectedCategory = $aiAnalysis['detected_category'];
    $confidence = $aiAnalysis['confidence_score'];
    
    // Look for exact match in existing categories
    foreach ($existingCategories as $category) {
        if (strtolower($category['name']) === strtolower($detectedCategory)) {
            return [
                'category' => $category,
                'confidence' => $confidence,
                'location' => generateLocationCode($category),
                'create_new' => false
            ];
        }
    }
    
    // Look for partial match
    foreach ($existingCategories as $category) {
        if (strpos(strtolower($category['name']), strtolower($detectedCategory)) !== false ||
            strpos(strtolower($detectedCategory), strtolower($category['name'])) !== false) {
            return [
                'category' => $category,
                'confidence' => $confidence * 0.8, // Reduce confidence for partial match
                'location' => generateLocationCode($category),
                'create_new' => false
            ];
        }
    }
    
    // Suggest creating new category
    $newCategory = [
        'name' => $detectedCategory,
        'description' => "AI-suggested category for {$detectedCategory} items",
        'location_prefix' => generateLocationPrefix($detectedCategory),
        'zone_letter' => getNextAvailableZone($existingCategories),
        'aisle_range' => getNextAvailableAisleRange($existingCategories)
    ];
    
    return [
        'category' => $newCategory,
        'confidence' => $confidence,
        'location' => generateLocationCode($newCategory),
        'create_new' => true
    ];
}

function generateLocationPrefix($categoryName) {
    $words = explode(' ', $categoryName);
    if (count($words) >= 2) {
        return strtoupper(substr($words[0], 0, 2) . substr($words[1], 0, 1));
    } else {
        return strtoupper(substr($categoryName, 0, 3));
    }
}

function generateLocationCode($category) {
    $prefix = $category['location_prefix'];
    $zone = $category['zone_letter'];
    $aisleRange = explode('-', $category['aisle_range']);
    $aisle = str_pad($aisleRange[0], 2, '0', STR_PAD_LEFT);
    $rack = rand(1, 10);
    $shelf = rand(1, 5);
    
    return "{$prefix}-{$zone}{$aisle}-R{$rack}-S{$shelf}";
}

function getNextAvailableZone($existingCategories) {
    $usedZones = array_column($existingCategories, 'zone_letter');
    for ($i = ord('A'); $i <= ord('Z'); $i++) {
        $zone = chr($i);
        if (!in_array($zone, $usedZones)) {
            return $zone;
        }
    }
    return 'Z'; // Fallback
}

function getNextAvailableAisleRange($existingCategories) {
    $usedRanges = array_column($existingCategories, 'aisle_range');
    $maxEnd = 0;
    
    foreach ($usedRanges as $range) {
        if ($range) {
            $parts = explode('-', $range);
            if (count($parts) === 2) {
                $end = intval($parts[1]);
                if ($end > $maxEnd) {
                    $maxEnd = $end;
                }
            }
        }
    }
    
    $newStart = $maxEnd + 1;
    $newEnd = $newStart + 2;
    
    return str_pad($newStart, 2, '0', STR_PAD_LEFT) . '-' . str_pad($newEnd, 2, '0', STR_PAD_LEFT);
}
?>

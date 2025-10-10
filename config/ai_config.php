<?php
/**
 * AI Configuration for Automation
 * Supports multiple AI providers
 */

class AIConfig {
    // Default to Gemini (can switch to openai, claude, custom)
    private $ai_provider = "gemini"; // Options: openai, claude, gemini, custom
    private $api_key = ""; // Set your API key here or use environment variable
    // Gemini v1beta generateContent endpoint (model path is part of URL)
    private $api_endpoint = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-pro:generateContent";
    private $model = "gemini-1.5-pro";
    
    // AI Automation Settings
    private $settings = [
        'demand_forecasting_enabled' => true,
        'auto_reordering_enabled' => true,
        'inventory_optimization_enabled' => true,
        'supplier_recommendation_enabled' => true,
        'anomaly_detection_enabled' => true,
        'smart_routing_enabled' => true,
        'min_confidence_threshold' => 0.75, // Minimum AI confidence for automated actions
        'reorder_safety_stock_days' => 14, // Days of safety stock to maintain
    ];

    public function __construct() {
        // Load API key from environment or configuration
        // Prefer GEMINI_API_KEY or GOOGLE_GENAI_API_KEY when using Gemini, fallback to AI_API_KEY
        $this->api_key = getenv('GEMINI_API_KEY')
            ?: (getenv('GOOGLE_GENAI_API_KEY')
            ?: (getenv('AI_API_KEY') ?: $this->api_key));

        // If still empty, try to load from .env in project root
        if (empty($this->api_key)) {
            $envPath = __DIR__ . '/../.env';
            if (is_readable($envPath)) {
                $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($lines !== false) {
                    foreach ($lines as $line) {
                        if (strpos(ltrim($line), '#') === 0) continue; // skip comments
                        if (strpos($line, '=') !== false) {
                            [$k, $v] = array_map('trim', explode('=', $line, 2));
                            // strip surrounding quotes
                            $v = trim($v, "\"' ");
                            if (in_array($k, ['GEMINI_API_KEY','GOOGLE_GENAI_API_KEY','AI_API_KEY'], true) && !empty($v)) {
                                $this->api_key = $v;
                                break;
                            }
                        }
                    }
                }
            }
        }
    }

    public function getProvider() {
        return $this->ai_provider;
    }

    public function getApiKey() {
        return $this->api_key;
    }

    public function getEndpoint() {
        return $this->api_endpoint;
    }

    public function getModel() {
        return $this->model;
    }

    public function getSetting($key) {
        return $this->settings[$key] ?? null;
    }

    public function getAllSettings() {
        return $this->settings;
    }

    public function updateSetting($key, $value) {
        $this->settings[$key] = $value;
    }
}
?>

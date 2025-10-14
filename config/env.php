<?php
/**
 * Environment Configuration Loader
 * DropIT Logistic System
 */

class Env {
    private static $loaded = false;
    private static $vars = [];
    
    public static function load($file = null) {
        if (self::$loaded) return;
        
        if (!$file) {
            $file = __DIR__ . '/../.env';
        }
        
        if (!file_exists($file)) {
            error_log("Environment file not found: $file");
            return;
        }
        
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue; // Skip comments
            
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // Remove quotes if present
                if (preg_match('/^["\'](.*)["\']\s*$/', $value, $matches)) {
                    $value = $matches[1];
                }
                
                self::$vars[$key] = $value;
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
        
        self::$loaded = true;
    }
    
    public static function get($key, $default = null) {
        self::load();
        return self::$vars[$key] ?? $_ENV[$key] ?? getenv($key) ?: $default;
    }
}
?>

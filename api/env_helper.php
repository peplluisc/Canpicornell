<?php
/**
 * Simple .env helper for Can Picornell PHP Backend
 */

function get_env_var($key, $default = null) {
    static $env = null;
    
    if ($env === null) {
        $env = [];
        
        // 1. Read root .env file if available
        $env_file = __DIR__ . '/../.env';
        if (file_exists($env_file)) {
            $lines = @file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line) || strpos($line, '#') === 0) {
                        continue;
                    }
                    
                    $parts = explode('=', $line, 2);
                    if (count($parts) === 2) {
                        $name = trim($parts[0]);
                        $value = trim($parts[1]);
                        
                        // Remove wrapping quotes if present
                        if ((strpos($value, '"') === 0 && substr($value, -1) === '"') || 
                            (strpos($value, "'") === 0 && substr($value, -1) === "'")) {
                            $value = substr($value, 1, -1);
                        }
                        
                        $env[$name] = $value;
                    }
                }
            }
        }
        
        // 2. Read api/config.json as a fallback
        $config_file = __DIR__ . '/config.json';
        if (file_exists($config_file)) {
            $json_str = @file_get_contents($config_file);
            $json = json_decode($json_str, true);
            if (is_array($json)) {
                foreach ($json as $k => $v) {
                    $upper_key = strtoupper($k);
                    if (!isset($env[$upper_key]) && is_string($v)) {
                        $env[$upper_key] = $v;
                    }
                }
            }
        }
    }
    
    // Return from parsed env/config array
    if (isset($env[$key]) && $env[$key] !== '') {
        return $env[$key];
    }
    
    // Check PHP getenv, $_ENV, $_SERVER
    $val = getenv($key);
    if ($val !== false && $val !== '') {
        return $val;
    }
    
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return $_ENV[$key];
    }
    
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return $_SERVER[$key];
    }
    
    return $default;
}

?>

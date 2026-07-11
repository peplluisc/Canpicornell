<?php
/**
 * Simple .env helper for Can Picornell PHP Backend
 */

function get_env_var($key, $default = null) {
    static $env = null;
    
    if ($env === null) {
        $env = [];
        $env_file = __DIR__ . '/../.env';
        if (file_exists($env_file)) {
            $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
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
    
    return isset($env[$key]) ? $env[$key] : $default;
}
?>

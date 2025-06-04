<?php
/**
 * Simple environment file loader
 */
class Env {
    private static $loaded = false;
    private static $variables = [];

    public static function load($path = null) {
        if (self::$loaded) {
            return;
        }

        if ($path === null) {
            $path = __DIR__ . '/../.env';
        }

        if (!file_exists($path)) {
            throw new Exception("Environment file not found: {$path}");
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            // Skip comments
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            // Parse key=value pairs
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // Remove quotes if present
                if (preg_match('/^".*"$/', $value) || preg_match("/^'.*'$/", $value)) {
                    $value = substr($value, 1, -1);
                }
                
                self::$variables[$key] = $value;
                
                // Also set as environment variable
                if (!array_key_exists($key, $_ENV)) {
                    $_ENV[$key] = $value;
                    putenv("{$key}={$value}");
                }
            }
        }

        self::$loaded = true;
    }

    public static function get($key, $default = null) {
        self::load();
        
        // Check our loaded variables first
        if (array_key_exists($key, self::$variables)) {
            return self::$variables[$key];
        }
        
        // Check environment variables
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }
        
        // Check $_ENV superglobal
        if (array_key_exists($key, $_ENV)) {
            return $_ENV[$key];
        }
        
        return $default;
    }

    public static function required($key) {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new Exception("Required environment variable '{$key}' is not set");
        }
        return $value;
    }
}

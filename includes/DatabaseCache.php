<?php

class DatabaseCache {
    private static $cache = [];
    private static $cacheExpiry = 3600; // 1 hour cache expiry
    
    public static function getBarangays($pdo) {
        if (!isset(self::$cache['barangays']) || self::isCacheExpired('barangays')) {
            $stmt = $pdo->query("SELECT id, name FROM barangay ORDER BY name");
            self::$cache['barangays'] = [
                'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
                'timestamp' => time()
            ];
        }
        return self::$cache['barangays']['data'];
    }
    
    public static function getDocumentTypes($pdo) {
        if (!isset(self::$cache['document_types']) || self::isCacheExpired('document_types')) {
            $stmt = $pdo->query("SELECT id, name, code FROM document_types WHERE is_active = TRUE");
            self::$cache['document_types'] = [
                'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
                'timestamp' => time()
            ];
        }
        return self::$cache['document_types']['data'];
    }
    
    public static function getCaseCategories($pdo) {
        if (!isset(self::$cache['case_categories']) || self::isCacheExpired('case_categories')) {
            $stmt = $pdo->query("SELECT id, name FROM case_categories ORDER BY name");
            self::$cache['case_categories'] = [
                'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
                'timestamp' => time()
            ];
        }
        return self::$cache['case_categories']['data'];
    }
    
    public static function getCaseInterventions($pdo) {
        if (!isset(self::$cache['case_interventions']) || self::isCacheExpired('case_interventions')) {
            $stmt = $pdo->query("SELECT id, name FROM case_interventions ORDER BY name");
            self::$cache['case_interventions'] = [
                'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
                'timestamp' => time()
            ];
        }
        return self::$cache['case_interventions']['data'];
    }
    
    public static function clearCache($key = null) {
        if ($key) {
            unset(self::$cache[$key]);
        } else {
            self::$cache = [];
        }
    }
    
    private static function isCacheExpired($key) {
        return !isset(self::$cache[$key]['timestamp']) || 
               (time() - self::$cache[$key]['timestamp']) > self::$cacheExpiry;
    }
} 
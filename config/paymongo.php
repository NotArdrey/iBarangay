<?php
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/dbconn.php';

// Load environment variables
Env::load();

/**
 * PayMongo Integration Configuration
 * This file handles all PayMongo-related functionality
 */

/**
 * Check if PayMongo is available for a specific barangay
 * 
 * @param int $barangay_id The barangay ID to check
 * @return bool True if PayMongo is available, false otherwise
 */
function isPayMongoAvailable($barangay_id) {
    global $pdo;
    
    try {
        // Query the barangay_paymongo_settings table
        $stmt = $pdo->prepare("
            SELECT is_enabled, secret_key, public_key 
            FROM barangay_paymongo_settings
            WHERE barangay_id = ?
        ");
        $stmt->execute([$barangay_id]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // PayMongo is available if:
        // 1. Settings exist
        // 2. is_enabled is true/1
        // 3. Both secret_key and public_key are not empty
        return $settings && 
               (bool)$settings['is_enabled'] && 
               !empty($settings['secret_key']) && 
               !empty($settings['public_key']);
               
    } catch (Exception $e) {
        // Log error if needed
        error_log("PayMongo availability check error: " . $e->getMessage());
        return false;
    }
}

/**
 * Create a PayMongo checkout session
 * 
 * @param array $lineItems Array of line items for checkout
 * @param string $successUrl URL to redirect to on successful payment
 * @param string $cancelUrl URL to redirect to on cancelled payment
 * @param int $barangay_id The barangay ID
 * @return array|null Checkout session details or null on failure
 */
function createPayMongoCheckout($lineItems, $successUrl, $cancelUrl, $barangay_id) {
    global $pdo;
    
    try {
        // Get PayMongo settings for this barangay
        $stmt = $pdo->prepare("
            SELECT secret_key, public_key, test_mode
            FROM barangay_paymongo_settings
            WHERE barangay_id = ? AND is_enabled = 1
        ");
        $stmt->execute([$barangay_id]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$settings) {
            throw new Exception("PayMongo is not configured or disabled for this barangay");
        }
        
        // Initialize PayMongo API request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.paymongo.com/v1/checkout_sessions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        
        // Prepare checkout session payload
        $payload = [
            'data' => [
                'attributes' => [
                    'line_items' => $lineItems,
                    'payment_method_types' => ['card', 'gcash', 'paymaya', 'grab_pay'],
                    'success_url' => $successUrl,
                    'cancel_url' => $cancelUrl,
                    'description' => 'iBarangay Document Request Payment',
                    'send_email_receipt' => true
                ]
            ]
        ];
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($settings['secret_key'] . ':')
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $responseData = json_decode($response, true);
            $checkoutSession = $responseData['data'];
            
            // Return relevant checkout data
            return [
                'id' => $checkoutSession['id'],
                'checkout_url' => $checkoutSession['attributes']['checkout_url'],
                'reference_number' => $checkoutSession['attributes']['reference_number'],
                'status' => $checkoutSession['attributes']['status']
            ];
        } else {
            $errorData = json_decode($response, true);
            throw new Exception("PayMongo API Error: " . json_encode($errorData['errors'] ?? 'Unknown error'));
        }
        
    } catch (Exception $e) {
        error_log("PayMongo checkout error: " . $e->getMessage());
        return null;
    }
}
?>

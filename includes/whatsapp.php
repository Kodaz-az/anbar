<?php
/**
 * AlumPro.az - WhatsApp Business API Integration
 * Created: 2025-09-02
 * Updated: 2025-09-02 12:16:41
 */

/**
 * Send WhatsApp message using official WhatsApp Business API
 * @param string $phone Recipient phone number
 * @param string $message Message content
 * @return bool True on success, false on failure
 */
function sendWhatsApp($phone, $message) {
    if (!defined('WHATSAPP_ENABLED') || !WHATSAPP_ENABLED) {
        return false;
    }
    
    // Normalize phone number
    $phone = normalizePhoneNumber($phone);
    
    // API endpoint for sending messages
    $url = WHATSAPP_API_BASE_URL . '/messages';
    
    // Prepare request data for text message
    $data = [
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $phone,
        'type' => 'text',
        'text' => [
            'preview_url' => false,
            'body' => $message
        ]
    ];
    
    // Send API request
    $response = makeWhatsAppApiRequest($url, $data);
    
    // Log activity
    if (function_exists('logWhatsAppActivity')) {
        logWhatsAppActivity('text_message', $phone, $response);
    }
    
    return isset($response['success']) ? $response['success'] : false;
}

/**
 * Send WhatsApp template message using official WhatsApp Business API
 * @param string $phone Recipient phone number
 * @param string $template Template name
 * @param array $variables Template variables
 * @param string $language Language code (default: az)
 * @return bool True on success, false on failure
 */
function sendWhatsAppTemplate($phone, $template, $variables = [], $language = 'az') {
    if (!defined('WHATSAPP_ENABLED') || !WHATSAPP_ENABLED) {
        return false;
    }
    
    // Normalize phone number
    $phone = normalizePhoneNumber($phone);
    
    // API endpoint for sending messages
    $url = WHATSAPP_API_BASE_URL . '/messages';
    
    // Convert variables to template components format
    $components = [];
    
    // If we have variables, add them as body parameters
    if (!empty($variables)) {
        $parameters = [];
        foreach ($variables as $value) {
            $parameters[] = [
                'type' => 'text',
                'text' => (string)$value
            ];
        }
        
        $components[] = [
            'type' => 'body',
            'parameters' => $parameters
        ];
    }
    
    // Prepare request data for template message
    $data = [
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $phone,
        'type' => 'template',
        'template' => [
            'name' => $template,
            'language' => [
                'code' => $language
            ]
        ]
    ];
    
    // Add components only if they exist
    if (!empty($components)) {
        $data['template']['components'] = $components;
    }
    
    // Send API request
    $response = makeWhatsAppApiRequest($url, $data);
    
    // Log activity
    if (function_exists('logWhatsAppActivity')) {
        logWhatsAppActivity('template_message', $phone, $response, [
            'template' => $template,
            'variables' => $variables
        ]);
    }
    
    return isset($response['success']) ? $response['success'] : false;
}

/**
 * Make HTTP request to WhatsApp Business API
 * @param string $url API endpoint URL
 * @param array $data Request data
 * @param string $method HTTP method (POST, GET, etc.)
 * @return array Response with success status and data/error
 */
function makeWhatsAppApiRequest($url, $data, $method = 'POST') {
    // Initialize cURL
    $ch = curl_init($url);
    
    // Set common cURL options
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    
    // Check if constants are defined
    if (!defined('WHATSAPP_API_TOKEN')) {
        return ['success' => false, 'error' => 'WHATSAPP_API_TOKEN is not defined'];
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . WHATSAPP_API_TOKEN,
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    
    // Set method-specific options
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } else if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }
    
    // Execute request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    // Check for cURL errors
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        
        if (function_exists('logWhatsAppError')) {
            logWhatsAppError('API', "cURL Error: $error");
        }
        
        return [
            'success' => false,
            'error' => "cURL Error: $error"
        ];
    }
    
    // Close cURL
    curl_close($ch);
    
    // Parse JSON response
    $responseData = json_decode($response, true);
    
    // Check for JSON parsing error
    if (json_last_error() !== JSON_ERROR_NONE) {
        $error = "JSON parsing error: " . json_last_error_msg() . "\nResponse: $response";
        
        if (function_exists('logWhatsAppError')) {
            logWhatsAppError('API', $error);
        }
        
        return [
            'success' => false,
            'error' => $error,
            'raw_response' => $response
        ];
    }
    
    // Check for API errors
    if ($httpCode >= 400 || (isset($responseData['error']) && !empty($responseData['error']))) {
        $errorMessage = isset($responseData['error']['message']) 
            ? $responseData['error']['message'] 
            : (isset($responseData['error']) ? json_encode($responseData['error']) : "HTTP Error: $httpCode");
        
        if (function_exists('logWhatsAppError')) {
            logWhatsAppError('API', "API Error: $errorMessage");
        }
        
        return [
            'success' => false,
            'error' => $errorMessage,
            'http_code' => $httpCode,
            'response' => $responseData
        ];
    }
    
    // Return success response
    return [
        'success' => true,
        'http_code' => $httpCode,
        'data' => $responseData
    ];
}

/**
 * Normalize phone number to E.164 format
 * @param string $phone Phone number
 * @return string Normalized phone number
 */
function normalizePhoneNumber($phone) {
    // Remove all non-digit characters
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Ensure Azerbaijan country code if it appears to be an Azerbaijani number
    if (strlen($phone) == 10 && substr($phone, 0, 1) == '0') {
        // Format: 0501234567 -> 994501234567
        $phone = '994' . substr($phone, 1);
    } elseif (strlen($phone) == 9 && in_array(substr($phone, 0, 2), ['50', '51', '55', '70', '77', '99'])) {
        // Format: 501234567 -> 994501234567
        $phone = '994' . $phone;
    } elseif (strlen($phone) > 10 && substr($phone, 0, 1) == '0') {
        // Format: 0994501234567 -> 994501234567
        $phone = substr($phone, 1);
    }
    
    return $phone;
}

/**
 * Log WhatsApp error messages
 * @param string $recipient Recipient (phone number or 'API')
 * @param string $message Error message
 * @return void
 */
function logWhatsAppError($recipient, $message) {
    $logFile = __DIR__ . '/../logs/whatsapp_errors.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] Recipient: $recipient | Error: $message" . PHP_EOL;
    
    // Make sure the log directory exists
    $logDir = dirname($logFile);
    if (!file_exists($logDir)) {
        if (!@mkdir($logDir, 0755, true)) {
            // If we can't create the directory, log to the system log
            error_log("Failed to create log directory: $logDir");
            return;
        }
    }
    
    // Try to write to the log file
    if (!@error_log($logMessage, 3, $logFile)) {
        // If we can't write to the log file, log to the system log
        error_log("Failed to write to WhatsApp error log: $logFile");
        error_log($logMessage);
    }
}

/**
 * Log WhatsApp activity
 * @param string $type Activity type
 * @param string $recipient Recipient phone number
 * @param array $response API response
 * @param array $additionalInfo Additional information
 * @return void
 */
function logWhatsAppActivity($type, $recipient, $response, $additionalInfo = []) {
    $logFile = __DIR__ . '/../logs/whatsapp_activity.log';
    $timestamp = date('Y-m-d H:i:s');
    
    $status = isset($response['success']) && $response['success'] ? 'SUCCESS' : 'FAILED';
    $responseInfo = isset($response['success']) && $response['success'] 
        ? (isset($response['data']['messages'][0]['id']) ? 'Message ID: ' . $response['data']['messages'][0]['id'] : json_encode($response['data']))
        : 'Error: ' . (isset($response['error']) ? $response['error'] : 'Unknown error');
    
    $additionalInfoStr = !empty($additionalInfo) ? ' | Additional Info: ' . json_encode($additionalInfo) : '';
    
    $logMessage = "[$timestamp] Type: $type | Recipient: $recipient | Status: $status | $responseInfo$additionalInfoStr" . PHP_EOL;
    
    // Make sure the log directory exists
    $logDir = dirname($logFile);
    if (!file_exists($logDir)) {
        if (!@mkdir($logDir, 0755, true)) {
            // If we can't create the directory, log to the system log
            error_log("Failed to create log directory: $logDir");
            return;
        }
    }
    
    // Try to write to the log file
    if (!@error_log($logMessage, 3, $logFile)) {
        // If we can't write to the log file, log to the system log
        error_log("Failed to write to WhatsApp activity log: $logFile");
        error_log($logMessage);
    }
}
<?php
/**
 * AlumPro.az - WhatsApp Webhook Handler
 * Created: 2025-09-02
 * Updated: 2025-09-02 12:16:41
 */

// Make sure all required files are included
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/whatsapp.php';

// Check if WhatsApp integration is enabled
if (!defined('WHATSAPP_ENABLED') || !WHATSAPP_ENABLED) {
    http_response_code(503);
    echo 'WhatsApp integration is not enabled';
    exit;
}

// Verify webhook
if (isset($_GET['hub_mode']) && $_GET['hub_mode'] === 'subscribe' && 
    isset($_GET['hub_verify_token']) && isset($_GET['hub_challenge'])) {
    
    $verifyToken = $_GET['hub_verify_token'];
    
    if ($verifyToken === WHATSAPP_VERIFY_TOKEN) {
        echo $_GET['hub_challenge'];
        exit;
    } else {
        http_response_code(403);
        echo 'Verification token mismatch';
        exit;
    }
}

// Handle incoming webhook notifications
$input = file_get_contents('php://input');
$body = json_decode($input, true);

// Log all incoming webhooks
logWebhookRequest($input);

// Process webhook data
if (isset($body['object']) && $body['object'] === 'whatsapp_business_account') {
    if (isset($body['entry']) && is_array($body['entry'])) {
        foreach ($body['entry'] as $entry) {
            // Process each change
            if (isset($entry['changes']) && is_array($entry['changes'])) {
                foreach ($entry['changes'] as $change) {
                    if (isset($change['field']) && $change['field'] === 'messages' && isset($change['value'])) {
                        processMessages($change['value']);
                    }
                }
            }
        }
    }
    
    // Return 200 OK to acknowledge receipt
    http_response_code(200);
    echo 'EVENT_RECEIVED';
    exit;
} else {
    // Not a WhatsApp webhook event
    http_response_code(404);
    echo 'NOT_FOUND';
    exit;
}

/**
 * Log webhook requests
 * @param string $payload JSON payload
 * @return void
 */
function logWebhookRequest($payload) {
    $logFile = __DIR__ . '/../logs/whatsapp_webhooks.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] Webhook received: " . $payload . PHP_EOL;
    
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
        error_log("Failed to write to WhatsApp webhook log: $logFile");
        error_log($logMessage);
    }
}

/**
 * Process incoming WhatsApp messages
 * @param array $data Message data
 * @return void
 */
function processMessages($data) {
    // This is a simplified placeholder for message processing
    // In production, you would implement full message handling
    if (!isset($data['messages']) || !is_array($data['messages'])) {
        return;
    }
    
    foreach ($data['messages'] as $message) {
        // Get message details
        $messageId = $message['id'] ?? '';
        $from = $message['from'] ?? '';
        $timestamp = $message['timestamp'] ?? '';
        $type = $message['type'] ?? '';
        
        // Log the received message
        error_log("Received WhatsApp message: ID=$messageId, From=$from, Type=$type");
        
        // Here you would normally process the message based on its type
        // and interact with your database and business logic
        
        // For this simplified version, just acknowledge receipt
        if (!empty($from) && $from !== WHATSAPP_SENDER_PHONE_NUMBER) {
            // Send a simple acknowledgment
            sendWhatsApp($from, "Message received. Thank you!");
        }
    }
}
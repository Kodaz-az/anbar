<?php
// Notification handling system
// Created: 2025-09-01

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/whatsapp.php';

/**
 * Send a notification to a user through specified channel
 * @param int $userId User ID
 * @param string $templateCode Template code
 * @param array $variables Variables for template
 * @param string $channel Notification channel
 * @return bool Success or failure
 */
function sendNotification($userId, $templateCode, $variables = [], $channel = NOTIFY_SYSTEM) {
    // Get user contact information
    $user = getUserById($userId);
    if (!$user) {
        return false;
    }
    
    // Get notification template
    $template = getNotificationTemplate($templateCode, $channel);
    if (!$template) {
        return false;
    }
    
    // Process the template with variables
    $message = processTemplate($template['template_content'], $variables);
    $subject = processTemplate($template['template_subject'], $variables);
    
    // Send notification based on channel
    switch ($channel) {
        case NOTIFY_EMAIL:
            if (!empty($user['email'])) {
                return sendEmailNotification($user['email'], $subject, $message);
            }
            break;
            
        case NOTIFY_SMS:
            if (!empty($user['phone'])) {
                return sendSmsNotification($user['phone'], $message);
            }
            break;
            
        case NOTIFY_WHATSAPP:
            if (!empty($user['phone'])) {
                return sendWhatsAppMessage($user['phone'], $message);
            }
            break;
            
        case NOTIFY_SYSTEM:
            return saveSystemNotification($userId, $subject, $message, $templateCode);
            
        default:
            return false;
    }
    
    return false;
}

/**
 * Send an email notification
 * @param string $email Recipient email
 * @param string $subject Email subject
 * @param string $message Email message
 * @return bool Success or failure
 */
function sendEmailNotification($email, $subject, $message) {
    // Basic email headers
    $headers = [
        'From' => SITE_NAME . ' <' . ADMIN_EMAIL . '>',
        'Reply-To' => ADMIN_EMAIL,
        'X-Mailer' => 'PHP/' . phpversion(),
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/html; charset=UTF-8'
    ];
    
    // Convert headers array to string
    $headerString = '';
    foreach ($headers as $key => $value) {
        $headerString .= "$key: $value\r\n";
    }
    
    // Send email
    return mail($email, $subject, $message, $headerString);
}

/**
 * Send an SMS notification (placeholder - implement with SMS provider)
 * @param string $phone Recipient phone number
 * @param string $message SMS message
 * @return bool Success or failure
 */
function sendSmsNotification($phone, $message) {
    // This is a placeholder - implement with your SMS provider
    // For example, Twilio, Nexmo, etc.
    
    // Log the attempt
    logActivity(0, 'send_sms', "SMS to $phone: $message");
    
    // Return true for demonstration purposes
    return true;
}

/**
 * Save a system notification
 * @param int $userId User ID
 * @param string $title Notification title
 * @param string $message Notification message
 * @param string $type Notification type
 * @return bool Success or failure
 */
function saveSystemNotification($userId, $title, $message, $type = 'general') {
    return dbExecute(
        "INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (?, ?, ?, ?, NOW())",
        [$userId, $title, $message, $type],
        "isss"
    );
}

/**
 * Get unread notifications for a user
 * @param int $userId User ID
 * @param int $limit Maximum number of notifications to return
 * @return array Notifications
 */
function getUnreadNotifications($userId, $limit = 10) {
    return dbSelect(
        "SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT ?",
        [$userId, $limit],
        "ii"
    );
}

/**
 * Mark notification as read
 * @param int $notificationId Notification ID
 * @param int $userId User ID (for security)
 * @return bool Success or failure
 */
function markNotificationRead($notificationId, $userId) {
    return dbExecute(
        "UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?",
        [$notificationId, $userId],
        "ii"
    );
}

/**
 * Mark all notifications as read for a user
 * @param int $userId User ID
 * @return bool Success or failure
 */
function markAllNotificationsRead($userId) {
    return dbExecute(
        "UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0",
        [$userId],
        "i"
    );
}

/**
 * Get unread notification count for a user
 * @param int $userId User ID
 * @return int Number of unread notifications
 */
function getUnreadNotificationCount($userId) {
    $result = dbSelectOne(
        "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0",
        [$userId],
        "i"
    );
    
    return $result ? (int)$result['count'] : 0;
}

/**
 * Send order status update notification
 * @param int $orderId Order ID
 * @param string $status New status
 * @return bool Success or failure
 */
function sendOrderStatusNotification($orderId, $status) {
    // Get order and customer information
    $order = dbSelectOne(
        "SELECT o.*, c.user_id, c.fullname, c.phone FROM orders o 
         JOIN customers c ON o.customer_id = c.id 
         WHERE o.id = ?",
        [$orderId],
        "i"
    );
    
    if (!$order || empty($order['user_id'])) {
        return false;
    }
    
    // Prepare variables for template
    $variables = [
        'customer_name' => $order['fullname'],
        'order_number' => $order['order_number'],
        'order_date' => formatDate($order['order_date']),
        'order_amount' => formatMoney($order['total_amount']),
        'status' => getStatusText($status)
    ];
    
    // Send system notification
    $success = sendNotification(
        $order['user_id'], 
        'order_status_update', 
        $variables, 
        NOTIFY_SYSTEM
    );
    
    // If WhatsApp is enabled and customer has phone number, send WhatsApp
    if (WHATSAPP_ENABLED && !empty($order['phone'])) {
        $whatsappTemplate = 'order_status_' . $status;
        $template = getNotificationTemplate($whatsappTemplate, NOTIFY_WHATSAPP);
        
        if ($template) {
            $message = processTemplate($template['template_content'], $variables);
            sendWhatsAppMessage($order['phone'], $message);
        }
    }
    
    return $success;
}

/**
 * Get text representation of order status
 * @param string $status Status code
 * @return string Status text
 */
function getStatusText($status) {
    $statusMap = [
        STATUS_NEW => 'Yeni',
        STATUS_PROCESSING => 'Hazırlanır',
        STATUS_COMPLETED => 'Hazır',
        STATUS_DELIVERED => 'Təhvil verilib',
        STATUS_CANCELLED => 'Ləğv edilib'
    ];
    
    return $statusMap[$status] ?? 'Bilinmir';
}
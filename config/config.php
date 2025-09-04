<?php
/**
 * AlumPro.az - Configuration File
 * Created: 2025-09-02
 * Updated: 2025-09-02 09:42:19
 */
require_once __DIR__ . '/constants.php';
// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'ezizov04_anbar');
define('DB_PASS', 'ezizovs074');
define('DB_NAME', 'ezizov04_anbar');


// Site Configuration
define('SITE_URL', 'https://anbar.alumpro.az');
define('SITE_NAME', 'AlumPro.az');
define('SITE_DESCRIPTION', 'Alüminium və Şüşə Məhsulları İdarəetmə Sistemi');
define('DEFAULT_LANGUAGE', 'az');
define('TIMEZONE', 'Asia/Baku');

// Company Information
define('COMPANY_NAME', 'AlumPro MMC');
define('COMPANY_ADDRESS', 'Bakı şəhəri, Əhməd Rəcəbli 254');
define('COMPANY_PHONE', '+994 55 244 70 44');
define('COMPANY_EMAIL', 'info@alumpro.az');
define('COMPANY_VAT_ID', '1234567890');

define('SESSION_LIFETIME', 86400); // 24 hours in seconds
define('SESSION_INACTIVE_TIMEOUT', 3600); // 1 hour in seconds

// File Upload Configuration
define('UPLOAD_DIR', __DIR__ . '/../uploads/');

define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'pdf']);

// WhatsApp Business API Configuration
// Comment these out if you're not using WhatsApp integration
define('WHATSAPP_ENABLED', true); // Set to false until properly configured
define('WHATSAPP_API_BASE_URL', 'https://graph.facebook.com/v17.0/818172728035605');
define('WHATSAPP_API_TOKEN', 'EAAuSf74Qu5wBPdMllPaP6VFDZCfv0uSNUaD549f98QFZA2kdtnHjuQhDFXz08nGF5kWSG4k1Eu109rXJ8oHNIAMtQkPYAIKdhr1BHPAz2kpMEPThMdSMOZCHVotZAaoGGk1J1Fln6oPua6ZBWWd1lYrN8RcK3hH68Aym8MwR9Me37KJIHP1wZBENKfz43EZCRqeqEd8nsrXaknjDbP2ZA99eVkGUrAU7znT7ZBG2ZAOLYNTEQZBBwZDZD');
define('WHATSAPP_BUSINESS_ACCOUNT_ID', '1488605415645044');
define('WHATSAPP_SENDER_PHONE_NUMBER', '+12182311818');
define('WHATSAPP_VERIFY_TOKEN', 'alumproazwhatsapp'); // For webhook verification

// WhatsApp Message Templates
define('WHATSAPP_TEMPLATE_WELCOME', 'welcome_template');
define('WHATSAPP_TEMPLATE_ORDER_CONFIRMATION', 'order_confirmation');
define('WHATSAPP_TEMPLATE_ORDER_STATUS_UPDATE', 'order_status_update');
define('WHATSAPP_TEMPLATE_PAYMENT_REMINDER', 'payment_reminder');
define('WHATSAPP_TEMPLATE_ORDER_READY', 'order_ready');

// Email Configuration
define('SMTP_HOST', 'mail.alumpro.az');
define('SMTP_PORT', 465);
define('SMTP_USER', 'no-reply@alumpro.az');
define('SMTP_PASS', 'ezizovs074');
define('SMTP_FROM_NAME', 'AlumPro.az');
define('SMTP_FROM_EMAIL', 'no-reply@alumpro.az');

// Security Settings
define('PASSWORD_MIN_LENGTH', 8);
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 1800); // 30 minutes in seconds

// System Settings
define('DEBUG_MODE', true); // Set to true while debugging HTTP 500 errors
define('LOG_ERRORS', true);
define('ERROR_LOG_FILE', __DIR__ . '/../logs/error.log');

// Initialize settings
date_default_timezone_set(TIMEZONE);
ini_set('display_errors', DEBUG_MODE ? 1 : 0);
ini_set('error_log', ERROR_LOG_FILE);

// Enable error logging if configured
if (LOG_ERRORS) {
    error_reporting(E_ALL);
    ini_set('log_errors', 1);
    
    // Make sure the log directory exists
    $logDir = dirname(ERROR_LOG_FILE);
    if (!file_exists($logDir)) {
        @mkdir($logDir, 0755, true);
    }
}
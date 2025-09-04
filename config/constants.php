<?php
// Application constants
// Created: 2025-09-01

// User roles
define('ROLE_ADMIN', 'admin');
define('ROLE_SELLER', 'seller');
define('ROLE_CUSTOMER', 'customer');
define('ROLE_PRODUCTION', 'production');

// Order statuses
define('STATUS_NEW', 'new');
define('STATUS_PROCESSING', 'processing');
define('STATUS_COMPLETED', 'completed');
define('STATUS_DELIVERED', 'delivered');
define('STATUS_CANCELLED', 'cancelled');

// Message types
define('MESSAGE_TYPE_ORDER', 'order');
define('MESSAGE_TYPE_PAYMENT', 'payment');
define('MESSAGE_TYPE_SUPPORT', 'support');
define('MESSAGE_TYPE_NOTIFICATION', 'notification');

// Payment methods
define('PAYMENT_CASH', 'cash');
define('PAYMENT_CARD', 'card');
define('PAYMENT_TRANSFER', 'transfer');

// Date formats
define('DATE_FORMAT', 'd.m.Y');
define('DATETIME_FORMAT', 'd.m.Y H:i');

// Upload limits
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif']);

// Notification channels
define('NOTIFY_SMS', 'sms');
define('NOTIFY_EMAIL', 'email');
define('NOTIFY_WHATSAPP', 'whatsapp');
define('NOTIFY_SYSTEM', 'system');

// Warehouse categories
define('CATEGORY_PROFILE', 'profile');
define('CATEGORY_GLASS', 'glass');
define('CATEGORY_ACCESSORY', 'accessory');

// Inventory thresholds
define('INVENTORY_LOW', 'low');
define('INVENTORY_MEDIUM', 'medium');
define('INVENTORY_GOOD', 'good');

define('ACTIVITY_LOGIN', 'login');
define('ACTIVITY_LOGOUT', 'logout');
define('ACTIVITY_REGISTER', 'register');
define('ACTIVITY_PASSWORD_CHANGE', 'password_change');
define('ACTIVITY_PROFILE_UPDATE', 'profile_update');
define('ACTIVITY_ORDER_CREATE', 'order_create');
define('ACTIVITY_ORDER_UPDATE', 'order_update');
define('ACTIVITY_ORDER_STATUS_CHANGE', 'order_status_change');
define('ACTIVITY_INVENTORY_ADD', 'inventory_add');
define('ACTIVITY_INVENTORY_REMOVE', 'inventory_remove');
define('ACTIVITY_CUSTOMER_ADD', 'customer_add');
define('ACTIVITY_CUSTOMER_UPDATE', 'customer_update');
<?php
/**
 * AlumPro.az - Core Functions
 * Last Updated: 2025-09-03 08:10:00
 * Author: AlumproAz
 */

// Define constants if not already defined
if (!defined('SESSION_INACTIVE_TIMEOUT')) {
    define('SESSION_INACTIVE_TIMEOUT', 3600); // 1 hour in seconds
}

if (!defined('MAX_UPLOAD_SIZE')) {
    define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB
}

if (!defined('ALLOWED_EXTENSIONS')) {
    define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx']);
}

// Activity type constants
if (!defined('ACTIVITY_LOGIN')) {
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
}

// Password settings
if (!defined('PASSWORD_MIN_LENGTH')) {
    define('PASSWORD_MIN_LENGTH', 8);
}

/**
 * Get database connection
 * @return mysqli Database connection
 */


/**
 * Check if user is logged in
 * @return bool True if user is logged in, false otherwise
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Check if user session has expired due to inactivity
 * @return bool True if session has expired, false otherwise
 */
function isSessionExpired() {
    $timeout = SESSION_INACTIVE_TIMEOUT;
    
    if (!isset($_SESSION['last_activity'])) {
        return true;
    }
    
    $inactive = time() - $_SESSION['last_activity'];
    
    if ($inactive >= $timeout) {
        return true;
    }
    
    // Update last activity time
    $_SESSION['last_activity'] = time();
    
    return false;
}

/**
 * Update user's last activity time
 * @return void
 */
function updateLastActivity() {
    $_SESSION['last_activity'] = time();
}

/**
 * Check if user has specified role
 * @param string|array $roles Role(s) to check
 * @return bool True if user has role, false otherwise
 */
function hasRole($roles) {
    if (!isLoggedIn()) {
        return false;
    }
    
    if (!is_array($roles)) {
        $roles = [$roles];
    }
    
    return in_array($_SESSION['role'], $roles);
}

/**
 * Redirect to login page
 * @return void
 */
function redirectToLogin() {
    header('Location: ../auth/login.php');
    exit;
}

/**
 * Log user activity
 * @param int $userId User ID
 * @param string $activityType Type of activity
 * @param string $description Activity description
 * @return bool True on success, false on failure
 */
function logActivity($userId, $activityType, $description = '') {
    $conn = getDBConnection();
    
    // First check if activity_logs table exists
    $checkTableSql = "SHOW TABLES LIKE 'activity_logs'";
    $logsTableExists = $conn->query($checkTableSql)->num_rows > 0;
    
    // Then check for activity_log table (singular)
    $checkTableSql = "SHOW TABLES LIKE 'activity_log'";
    $logTableExists = $conn->query($checkTableSql)->num_rows > 0;
    
    if (!$logsTableExists && !$logTableExists) {
        // Fallback to regular logging if neither table exists
        error_log("User activity: User ID: $userId, Type: $activityType, Description: $description");
        return false;
    }
    
    try {
        if ($logsTableExists) {
            $sql = "INSERT INTO activity_logs (user_id, action_type, action_details, ip_address, created_at) 
                    VALUES (?, ?, ?, ?, NOW())";
            
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("isss", $userId, $activityType, $description, $ipAddress);
        } else {
            // Use activity_log table (singular)
            $sql = "INSERT INTO activity_log (user_id, activity_type, description, ip_address, user_agent, timestamp) 
                    VALUES (?, ?, ?, ?, ?, NOW())";
            
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("issss", $userId, $activityType, $description, $ipAddress, $userAgent);
        }
        
        return $stmt->execute();
    } catch (Exception $e) {
        error_log("Error logging activity: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if a table exists in the database
 * @param mysqli $conn Database connection
 * @param string $tableName Table name to check
 * @return bool True if table exists, false otherwise
 */
function tableExists($conn, $tableName) {
    $result = $conn->query("SHOW TABLES LIKE '$tableName'");
    return $result->num_rows > 0;
}

//
// INVENTORY MANAGEMENT FUNCTIONS
//

/**
 * Get products with low stock levels
 * @param int $limit Maximum number of products to return
 * @param int|null $branchId Branch ID (null for all branches)
 * @return array Array of products with low stock
 */
function getLowStockProducts($limit = 10, $branchId = null) {
    // Check glass inventory
    $lowStockProducts = [];
    $conn = getDBConnection();
    
    // Check glass inventory
    if (tableExists($conn, 'glass_inventory')) {
        $sql = "SELECT 
                id, 
                name, 
                type, 
                thickness, 
                remaining_volume as stock,
                purchase_volume as total_stock,
                'glass' as item_type, 
                'm²' as unit 
            FROM glass_inventory 
            WHERE remaining_volume < (purchase_volume * 0.2) 
            AND remaining_volume > 0";
            
        $result = $conn->query($sql);
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $row['stock_status'] = 'warning';
                if ($row['stock'] < ($row['total_stock'] * 0.1)) {
                    $row['stock_status'] = 'critical';
                }
                $lowStockProducts[] = $row;
            }
        }
    }
    
    // Check profile inventory
    if (tableExists($conn, 'profile_inventory')) {
        $sql = "SELECT 
                id, 
                name, 
                type, 
                color, 
                remaining_quantity as stock,
                purchase_quantity as total_stock,
                'profile' as item_type, 
                unit_of_measure as unit 
            FROM profile_inventory 
            WHERE remaining_quantity < (purchase_quantity * 0.2) 
            AND remaining_quantity > 0";
            
        $result = $conn->query($sql);
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $row['stock_status'] = 'warning';
                if ($row['stock'] < ($row['total_stock'] * 0.1)) {
                    $row['stock_status'] = 'critical';
                }
                $lowStockProducts[] = $row;
            }
        }
    }
    
    // Check accessories inventory
    if (tableExists($conn, 'accessories_inventory')) {
        $sql = "SELECT 
                id, 
                name, 
                remaining_quantity as stock,
                purchase_quantity as total_stock,
                'accessory' as item_type, 
                unit_of_measure as unit 
            FROM accessories_inventory 
            WHERE remaining_quantity < (purchase_quantity * 0.2) 
            AND remaining_quantity > 0";
            
        $result = $conn->query($sql);
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $row['stock_status'] = 'warning';
                if ($row['stock'] < ($row['total_stock'] * 0.1)) {
                    $row['stock_status'] = 'critical';
                }
                $lowStockProducts[] = $row;
            }
        }
    }
    
    // Sort by stock status (critical first) and limit results
    usort($lowStockProducts, function($a, $b) {
        if ($a['stock_status'] === 'critical' && $b['stock_status'] !== 'critical') {
            return -1;
        } elseif ($a['stock_status'] !== 'critical' && $b['stock_status'] === 'critical') {
            return 1;
        } else {
            return 0;
        }
    });
    
    return array_slice($lowStockProducts, 0, $limit);
}

//
// ORDER MANAGEMENT FUNCTIONS
//

/**
 * Get recent orders
 * @param int $limit Maximum number of orders to return
 * @param int|null $sellerId Seller ID (null for all sellers)
 * @param int|null $branchId Branch ID (null for all branches)
 * @return array Array of recent orders
 */
function getRecentOrders($limit = 10, $sellerId = null, $branchId = null) {
    $conn = getDBConnection();
    
    // Check if orders table exists
    if (!tableExists($conn, 'orders')) {
        return [];
    }
    
    $sql = "SELECT o.*, c.fullname as customer_name, c.phone as customer_phone, u.fullname as seller_name, b.name as branch_name
            FROM orders o
            LEFT JOIN customers c ON o.customer_id = c.id
            LEFT JOIN users u ON o.seller_id = u.id
            LEFT JOIN branches b ON o.branch_id = b.id
            WHERE 1=1";
    
    $params = [];
    $types = "";
    
    if ($sellerId !== null) {
        $sql .= " AND o.seller_id = ?";
        $params[] = $sellerId;
        $types .= "i";
    }
    
    if ($branchId !== null) {
        $sql .= " AND o.branch_id = ?";
        $params[] = $branchId;
        $types .= "i";
    }
    
    $sql .= " ORDER BY o.order_date DESC LIMIT ?";
    $params[] = $limit;
    $types .= "i";
    
    $stmt = $conn->prepare($sql);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $orders = [];
    while ($row = $result->fetch_assoc()) {
        $orders[] = $row;
    }
    
    return $orders;
}

/**
 * Get pending orders that need attention
 * @param int $limit Maximum number of orders to return
 * @param int|null $sellerId Seller ID (null for all sellers)
 * @return array Array of pending orders
 */
function getPendingOrders($limit = 10, $sellerId = null) {
    $conn = getDBConnection();
    
    // Check if orders table exists
    if (!tableExists($conn, 'orders')) {
        return [];
    }
    
    $sql = "SELECT o.*, c.fullname as customer_name, c.phone as customer_phone, u.fullname as seller_name
            FROM orders o
            LEFT JOIN customers c ON o.customer_id = c.id
            LEFT JOIN users u ON o.seller_id = u.id
            WHERE o.order_status IN ('new', 'processing')";
    
    $params = [];
    $types = "";
    
    if ($sellerId !== null) {
        $sql .= " AND o.seller_id = ?";
        $params[] = $sellerId;
        $types .= "i";
    }
    
    $sql .= " ORDER BY 
              CASE 
                WHEN o.order_status = 'new' THEN 1
                WHEN o.order_status = 'processing' THEN 2
                ELSE 3
              END,
              o.order_date ASC
              LIMIT ?";
    
    $params[] = $limit;
    $types .= "i";
    
    $stmt = $conn->prepare($sql);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $orders = [];
    while ($row = $result->fetch_assoc()) {
        $orders[] = $row;
    }
    
    return $orders;
}

/**
 * Generate unique order number
 * @param string $prefix Prefix for order number (default: 'ORD')
 * @return string Unique order number
 */
function generateOrderNumber($prefix = 'ORD') {
    $date = date('Ymd');
    $random = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 4));
    
    return $prefix . '-' . $date . '-' . $random;
}

/**
 * Generate unique barcode for order
 * @return string Unique barcode
 */
function generateBarcode() {
    $prefix = 'AP';
    $date = date('ymd');
    $random = mt_rand(1000, 9999);
    
    return $prefix . $date . $random;
}

/**
 * Check if barcode exists
 * @param string $barcode Barcode
 * @return bool True if exists, false otherwise
 */
function barcodeExists($barcode) {
    $conn = getDBConnection();
    $sql = "SELECT id FROM orders WHERE barcode = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $barcode);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->num_rows > 0;
}

/**
 * Get order by barcode
 * @param string $barcode Barcode
 * @return array|null Order information
 */
function getOrderByBarcode($barcode) {
    $conn = getDBConnection();
    $sql = "SELECT * FROM orders WHERE barcode = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $barcode);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

/**
 * Update order status
 * @param int $orderId Order ID
 * @param string $status New status
 * @param int $userId User ID who is updating the status
 * @param string $note Note for status update
 * @return bool True on success, false on failure
 */
function updateOrderStatus($orderId, $status, $userId, $note = '') {
    $conn = getDBConnection();
    
    // Get current status
    $sql = "SELECT order_status, order_number FROM orders WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return false;
    }
    
    $order = $result->fetch_assoc();
    $oldStatus = $order['order_status'];
    
    // If status is the same, return true
    if ($oldStatus === $status) {
        return true;
    }
    
    // Set appropriate datetime field based on new status
    $dateField = '';
    switch ($status) {
        case 'processing':
            $dateField = 'processing_date = NOW()';
            break;
        case 'completed':
            $dateField = 'completion_date = NOW()';
            break;
        case 'delivered':
            $dateField = 'delivery_date = NOW()';
            break;
    }
    
    // Update order status
    $sql = "UPDATE orders SET order_status = ?" . ($dateField ? ", $dateField" : "") . " WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $status, $orderId);
    
    $success = $stmt->execute();
    
    if ($success) {
        // Log activity
        $logMessage = "Sifariş statusu dəyişdirildi: {$order['order_number']} - $oldStatus → $status";
        if (!empty($note)) {
            $logMessage .= " | Qeyd: $note";
        }
        
        logActivity($userId, ACTIVITY_ORDER_STATUS_CHANGE, $logMessage);
    }
    
    return $success;
}

/**
 * Get order by ID
 * @param int $orderId Order ID
 * @return array|null Order data or null if not found
 */
function getOrderById($orderId) {
    $conn = getDBConnection();
    
    $sql = "SELECT o.*, c.fullname as customer_name, c.phone as customer_phone, 
                   u.fullname as seller_name, b.name as branch_name
            FROM orders o
            LEFT JOIN customers c ON o.customer_id = c.id
            LEFT JOIN users u ON o.seller_id = u.id
            LEFT JOIN branches b ON o.branch_id = b.id
            WHERE o.id = ?";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

/**
 * Get order profiles by order ID
 * @param int $orderId Order ID
 * @return array Array of order profiles
 */
function getOrderProfiles($orderId) {
    $conn = getDBConnection();
    
    $sql = "SELECT * FROM order_profiles WHERE order_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    
    $result = $stmt->get_result();
    
    $profiles = [];
    while ($row = $result->fetch_assoc()) {
        $profiles[] = $row;
    }
    
    return $profiles;
}

/**
 * Get order glass items by order ID
 * @param int $orderId Order ID
 * @return array Array of order glass items
 */
function getOrderGlass($orderId) {
    $conn = getDBConnection();
    
    $sql = "SELECT * FROM order_glass WHERE order_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    
    $result = $stmt->get_result();
    
    $glass = [];
    while ($row = $result->fetch_assoc()) {
        $glass[] = $row;
    }
    
    return $glass;
}

/**
 * Get order pricing details by order ID
 * @param int $orderId Order ID
 * @return array|null Order pricing details or null if not found
 */
function getOrderPricing($orderId) {
    $conn = getDBConnection();
    
    $sql = "SELECT * FROM order_pricing WHERE order_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

/**
 * Calculate profile lengths and areas for order
 * @param array $profiles Array of order profiles
 * @return array Calculated lengths and areas
 */
function calculateProfileLengths($profiles) {
    $totalLength = 0;
    $totalHandleLength = 0;
    
    foreach ($profiles as $profile) {
        $width = $profile['width'] / 100; // Convert cm to m
        $height = $profile['height'] / 100; // Convert cm to m
        $quantity = $profile['quantity'];
        
        // Calculate perimeter (2 * width + 2 * height)
        $perimeter = 2 * ($width + $height);
        
        // Calculate total length for this profile
        $profileLength = $perimeter * $quantity;
        
        // Add to total length
        $totalLength += $profileLength;
        
        // Add to handle length (typically just the width, but can be customized)
        $handleLength = $width * $quantity;
        $totalHandleLength += $handleLength;
    }
    
    return [
        'side_profiles_length' => $totalLength - $totalHandleLength,
        'handle_profiles_length' => $totalHandleLength
    ];
}

/**
 * Calculate glass area for order
 * @param array $glass Array of order glass items
 * @return float Total glass area in m²
 */
function calculateGlassArea($glass) {
    $totalArea = 0;
    
    foreach ($glass as $item) {
        $width = $item['width'] / 100; // Convert cm to m
        $height = $item['height'] / 100; // Convert cm to m
        $quantity = $item['quantity'];
        
        // Calculate area (width * height)
        $area = $width * $height * $quantity;
        
        // Add to total area
        $totalArea += $area;
    }
    
    return $totalArea;
}

/**
 * Calculate price with tax
 * @param float $price Price without tax
 * @param float $taxRate Tax rate (percentage)
 * @return float Price with tax
 */
function calculatePriceWithTax($price, $taxRate = 18.0) {
    return $price * (1 + ($taxRate / 100));
}

//
// SELLER STATISTICS FUNCTIONS
//

/**
 * Get seller sales statistics for dashboard
 * @param int $sellerId Seller user ID
 * @param string $period Period to get statistics for (today, week, month, year, all)
 * @return array Sales statistics
 */
function getSellerSales($sellerId, $period = 'month') {
    $conn = getDBConnection();
    
    // Check if orders table exists
    if (!tableExists($conn, 'orders')) {
        return [
            'stats' => [
                'total_orders' => 0,
                'total_sales' => 0,
                'average_order' => 0,
                'total_debt' => 0
            ],
            'by_status' => [],
            'recent_orders' => [],
            'top_products' => []
        ];
    }
    
    // Determine date range based on period
    $dateCondition = "";
    switch ($period) {
        case 'today':
            $dateCondition = "AND DATE(order_date) = CURDATE()";
            break;
        case 'week':
            $dateCondition = "AND order_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            break;
        case 'month':
            $dateCondition = "AND order_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            break;
        case 'year':
            $dateCondition = "AND order_date >= DATE_SUB(NOW(), INTERVAL 1 YEAR)";
            break;
        case 'all':
        default:
            $dateCondition = "";
            break;
    }
    
    // Get basic sales statistics
    $sql = "SELECT 
                COUNT(id) as total_orders,
                COALESCE(SUM(total_amount), 0) as total_sales,
                COALESCE(AVG(total_amount), 0) as average_order,
                COALESCE(SUM(remaining_amount), 0) as total_debt
            FROM orders 
            WHERE seller_id = ? 
            $dateCondition";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $sellerId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    // Get sales by status
    $statusSql = "SELECT 
                    order_status,
                    COUNT(id) as count,
                    COALESCE(SUM(total_amount), 0) as total
                  FROM orders 
                  WHERE seller_id = ? 
                  $dateCondition
                  GROUP BY order_status";
    
    $stmt = $conn->prepare($statusSql);
    $stmt->bind_param("i", $sellerId);
    $stmt->execute();
    $statusResults = $stmt->get_result();
    
    $salesByStatus = [];
    while ($row = $statusResults->fetch_assoc()) {
        $salesByStatus[$row['order_status']] = $row;
    }
    
    // Get recent orders
    $recentSql = "SELECT 
                    o.id, o.order_number, o.order_date, o.total_amount, 
                    o.order_status, c.fullname as customer_name
                  FROM orders o
                  LEFT JOIN customers c ON o.customer_id = c.id
                  WHERE o.seller_id = ? 
                  " . str_replace('order_date', 'o.order_date', $dateCondition) . "
                  ORDER BY o.order_date DESC
                  LIMIT 5";
    
    $stmt = $conn->prepare($recentSql);
    $stmt->bind_param("i", $sellerId);
    $stmt->execute();
    $recentOrders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Get top products
    $topProductsSql = "";
    
    // Check if order_profiles table exists
    if (tableExists($conn, 'order_profiles')) {
        $topProductsSql = "SELECT 
                            op.profile_type, 
                            COUNT(*) as count
                           FROM order_profiles op
                           JOIN orders o ON op.order_id = o.id
                           WHERE o.seller_id = ? 
                           " . str_replace('order_date', 'o.order_date', $dateCondition) . "
                           GROUP BY op.profile_type
                           ORDER BY count DESC
                           LIMIT 5";
    }
    
    $topProducts = [];
    
    if (!empty($topProductsSql)) {
        $stmt = $conn->prepare($topProductsSql);
        $stmt->bind_param("i", $sellerId);
        $stmt->execute();
        $topProducts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    // Combine all statistics
    return [
        'stats' => $result,
        'by_status' => $salesByStatus,
        'recent_orders' => $recentOrders,
        'top_products' => $topProducts
    ];
}

/**
 * Get sales statistics for specific date range
 * @param int $sellerId Seller user ID
 * @param string $startDate Start date (YYYY-MM-DD)
 * @param string $endDate End date (YYYY-MM-DD)
 * @return array Sales statistics
 */
function getSellerSalesForDateRange($sellerId, $startDate, $endDate) {
    $conn = getDBConnection();
    
    // Check if orders table exists
    if (!tableExists($conn, 'orders')) {
        return [
            'stats' => [
                'total_orders' => 0,
                'total_sales' => 0,
                'average_order' => 0,
                'total_debt' => 0
            ],
            'by_status' => [],
            'daily_sales' => []
        ];
    }
    
    // Get basic sales statistics
    $sql = "SELECT 
                COUNT(id) as total_orders,
                COALESCE(SUM(total_amount), 0) as total_sales,
                COALESCE(AVG(total_amount), 0) as average_order,
                COALESCE(SUM(remaining_amount), 0) as total_debt
            FROM orders 
            WHERE seller_id = ? 
            AND DATE(order_date) BETWEEN ? AND ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $sellerId, $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    // Get sales by status
    $statusSql = "SELECT 
                    order_status,
                    COUNT(id) as count,
                    COALESCE(SUM(total_amount), 0) as total
                  FROM orders 
                  WHERE seller_id = ? 
                  AND DATE(order_date) BETWEEN ? AND ?
                  GROUP BY order_status";
    
    $stmt = $conn->prepare($statusSql);
    $stmt->bind_param("iss", $sellerId, $startDate, $endDate);
    $stmt->execute();
    $statusResults = $stmt->get_result();
    
    $salesByStatus = [];
    while ($row = $statusResults->fetch_assoc()) {
        $salesByStatus[$row['order_status']] = $row;
    }
    
    // Get daily sales
    $dailySql = "SELECT 
                   DATE(order_date) as date,
                   COUNT(id) as order_count,
                   COALESCE(SUM(total_amount), 0) as daily_total
                 FROM orders 
                 WHERE seller_id = ? 
                 AND DATE(order_date) BETWEEN ? AND ?
                 GROUP BY DATE(order_date)
                 ORDER BY date";
    
    $stmt = $conn->prepare($dailySql);
    $stmt->bind_param("iss", $sellerId, $startDate, $endDate);
    $stmt->execute();
    $dailySales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Combine all statistics
    return [
        'stats' => $result,
        'by_status' => $salesByStatus,
        'daily_sales' => $dailySales
    ];
}

/**
 * Get customer statistics for seller
 * @param int $sellerId Seller user ID
 * @param string $period Period to get statistics for (month, year, all)
 * @return array Customer statistics
 */
function getSellerCustomerStats($sellerId, $period = 'month') {
    $conn = getDBConnection();
    
    // Check if required tables exist
    if (!tableExists($conn, 'orders') || !tableExists($conn, 'customers')) {
        return [
            'top_customers' => [],
            'new_customers' => 0
        ];
    }
    
    // Determine date range based on period
    $dateCondition = "";
    switch ($period) {
        case 'month':
            $dateCondition = "AND o.order_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            break;
        case 'year':
            $dateCondition = "AND o.order_date >= DATE_SUB(NOW(), INTERVAL 1 YEAR)";
            break;
        case 'all':
        default:
            $dateCondition = "";
            break;
    }
    
    // Get top customers by order count
    $sql = "SELECT 
                c.id, 
                c.fullname, 
                c.phone,
                COUNT(o.id) as order_count,
                COALESCE(SUM(o.total_amount), 0) as total_spent,
                MAX(o.order_date) as last_order_date
            FROM orders o
            JOIN customers c ON o.customer_id = c.id
            WHERE o.seller_id = ? 
            $dateCondition
            GROUP BY c.id
            ORDER BY order_count DESC, total_spent DESC
            LIMIT 10";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $sellerId);
    $stmt->execute();
    $topCustomers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Get new customers in the period
    $newCustomersSql = "SELECT 
                           COUNT(DISTINCT c.id) as new_customer_count
                         FROM customers c
                         JOIN orders o ON c.id = o.customer_id
                         WHERE o.seller_id = ?
                         $dateCondition
                         AND c.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    
    $stmt = $conn->prepare($newCustomersSql);
    $stmt->bind_param("i", $sellerId);
    $stmt->execute();
    $newCustomers = $stmt->get_result()->fetch_assoc();
    
    return [
        'top_customers' => $topCustomers,
        'new_customers' => $newCustomers['new_customer_count'] ?? 0
    ];
}

/**
 * Get system statistics for admin dashboard
 * @return array System statistics
 */
function getSystemStats() {
    $conn = getDBConnection();
    $stats = [];
    
    // Users stats
    if (tableExists($conn, 'users')) {
        $sql = "SELECT 
                    COUNT(*) as total_users,
                    SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admin_count,
                    SUM(CASE WHEN role = 'seller' THEN 1 ELSE 0 END) as seller_count,
                    SUM(CASE WHEN role = 'production' THEN 1 ELSE 0 END) as production_count,
                    SUM(CASE WHEN role = 'customer' THEN 1 ELSE 0 END) as customer_count
                FROM users";
        
        $result = $conn->query($sql);
        $stats['users'] = $result->fetch_assoc();
    } else {
        $stats['users'] = [
            'total_users' => 0,
            'admin_count' => 0,
            'seller_count' => 0,
            'production_count' => 0,
            'customer_count' => 0
        ];
    }
    
    // Orders stats
    if (tableExists($conn, 'orders')) {
        $sql = "SELECT 
                    COUNT(*) as total_orders,
                    SUM(CASE WHEN order_status = 'new' THEN 1 ELSE 0 END) as new_orders,
                    SUM(CASE WHEN order_status = 'processing' THEN 1 ELSE 0 END) as processing_orders,
                    SUM(CASE WHEN order_status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
                    SUM(CASE WHEN order_status = 'delivered' THEN 1 ELSE 0 END) as delivered_orders,
                    SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders,
                    COALESCE(SUM(total_amount), 0) as total_sales,
                    COALESCE(SUM(remaining_amount), 0) as total_debt
                FROM orders";
        
        $result = $conn->query($sql);
        $stats['orders'] = $result->fetch_assoc();
        
        // Monthly orders
        $sql = "SELECT 
                    YEAR(order_date) as year,
                    MONTH(order_date) as month,
                    COUNT(*) as order_count,
                    COALESCE(SUM(total_amount), 0) as total_amount
                FROM orders
                WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                GROUP BY YEAR(order_date), MONTH(order_date)
                ORDER BY YEAR(order_date), MONTH(order_date)";
        
        $result = $conn->query($sql);
        $stats['monthly_orders'] = [];
        
        while ($row = $result->fetch_assoc()) {
            $stats['monthly_orders'][] = $row;
        }
    } else {
        $stats['orders'] = [
            'total_orders' => 0,
            'new_orders' => 0,
            'processing_orders' => 0,
            'completed_orders' => 0,
            'delivered_orders' => 0,
            'cancelled_orders' => 0,
            'total_sales' => 0,
            'total_debt' => 0
        ];
        $stats['monthly_orders'] = [];
    }
    
    // Customers stats
    if (tableExists($conn, 'customers')) {
        $sql = "SELECT 
                    COUNT(*) as total_customers,
                    SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as new_customers,
                    COALESCE(SUM(total_payment), 0) as total_payments,
                    COALESCE(SUM(remaining_debt), 0) as total_debt
                FROM customers";
        
        $result = $conn->query($sql);
        $stats['customers'] = $result->fetch_assoc();
    } else {
        $stats['customers'] = [
            'total_customers' => 0,
            'new_customers' => 0,
            'total_payments' => 0,
            'total_debt' => 0
        ];
    }
    
    // Inventory stats
    $stats['inventory'] = [
        'low_stock_count' => 0,
        'out_of_stock_count' => 0
    ];
    
    // Glass inventory
    if (tableExists($conn, 'glass_inventory')) {
        $sql = "SELECT 
                    COUNT(*) as total_items,
                    SUM(CASE WHEN remaining_volume < (purchase_volume * 0.2) AND remaining_volume > 0 THEN 1 ELSE 0 END) as low_stock,
                    SUM(CASE WHEN remaining_volume <= 0 THEN 1 ELSE 0 END) as out_of_stock
                FROM glass_inventory";
        
        $result = $conn->query($sql);
        $glassStats = $result->fetch_assoc();
        
        $stats['inventory']['low_stock_count'] += $glassStats['low_stock'];
        $stats['inventory']['out_of_stock_count'] += $glassStats['out_of_stock'];
    }
    
    // Profile inventory
    if (tableExists($conn, 'profile_inventory')) {
        $sql = "SELECT 
                    COUNT(*) as total_items,
                    SUM(CASE WHEN remaining_quantity < (purchase_quantity * 0.2) AND remaining_quantity > 0 THEN 1 ELSE 0 END) as low_stock,
                    SUM(CASE WHEN remaining_quantity <= 0 THEN 1 ELSE 0 END) as out_of_stock
                FROM profile_inventory";
        
        $result = $conn->query($sql);
        $profileStats = $result->fetch_assoc();
        
        $stats['inventory']['low_stock_count'] += $profileStats['low_stock'];
        $stats['inventory']['out_of_stock_count'] += $profileStats['out_of_stock'];
    }
    
    // Accessories inventory
    if (tableExists($conn, 'accessories_inventory')) {
        $sql = "SELECT 
                    COUNT(*) as total_items,
                    SUM(CASE WHEN remaining_quantity < (purchase_quantity * 0.2) AND remaining_quantity > 0 THEN 1 ELSE 0 END) as low_stock,
                    SUM(CASE WHEN remaining_quantity <= 0 THEN 1 ELSE 0 END) as out_of_stock
                FROM accessories_inventory";
        
        $result = $conn->query($sql);
        $accessoryStats = $result->fetch_assoc();
        
        $stats['inventory']['low_stock_count'] += $accessoryStats['low_stock'];
        $stats['inventory']['out_of_stock_count'] += $accessoryStats['out_of_stock'];
    }
    
    return $stats;
}

//
// USER AND CUSTOMER FUNCTIONS
//

/**
 * Get user by ID
 * @param int $userId User ID
 * @return array|null User data or null if not found
 */
function getUserById($userId) {
    $conn = getDBConnection();
    
    $sql = "SELECT * FROM users WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

/**
 * Get customer by ID
 * @param int $customerId Customer ID
 * @return array|null Customer data or null if not found
 */
function getCustomerById($customerId) {
    $conn = getDBConnection();
    
    $sql = "SELECT * FROM customers WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $customerId);
    $stmt->execute();
    
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

/**
 * Get branch information by ID
 * @param int $branchId Branch ID
 * @return array|null Branch information
 */
function getBranchById($branchId) {
    $conn = getDBConnection();
    $sql = "SELECT * FROM branches WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $branchId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

/**
 * Get branches
 * @param bool $activeOnly Get only active branches
 * @return array Array of branches
 */
function getBranches($activeOnly = true) {
    $conn = getDBConnection();
    
    $sql = "SELECT * FROM branches";
    
    if ($activeOnly) {
        $sql .= " WHERE status = 'active'";
    }
    
    $sql .= " ORDER BY name";
    
    $result = $conn->query($sql);
    
    $branches = [];
    while ($row = $result->fetch_assoc()) {
        $branches[] = $row;
    }
    
    return $branches;
}

/**
 * Get users by role
 * @param string $role Role to filter by
 * @param bool $activeOnly Get only active users
 * @return array Array of users
 */
function getUsersByRole($role, $activeOnly = true) {
    $conn = getDBConnection();
    
    $sql = "SELECT * FROM users WHERE role = ?";
    
    if ($activeOnly) {
        $sql .= " AND status = 'active'";
    }
    
    $sql .= " ORDER BY fullname";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $role);
    $stmt->execute();
    
    $result = $stmt->get_result();
    
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    
    return $users;
}

/**
 * Check if user exists by username or email
 * @param string $username Username to check
 * @param string $email Email to check
 * @param int $excludeUserId User ID to exclude from check
 * @return bool True if user exists, false otherwise
 */
function userExists($username, $email, $excludeUserId = 0) {
    $conn = getDBConnection();
    
    $sql = "SELECT id FROM users WHERE (username = ? OR email = ?)";
    
    if ($excludeUserId > 0) {
        $sql .= " AND id != ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssi", $username, $email, $excludeUserId);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $username, $email);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->num_rows > 0;
}

/**
 * Check if customer exists by phone
 * @param string $phone Phone to check
 * @param int $excludeCustomerId Customer ID to exclude from check
 * @return bool True if customer exists, false otherwise
 */
function customerExists($phone, $excludeCustomerId = 0) {
    $conn = getDBConnection();
    
    $sql = "SELECT id FROM customers WHERE phone = ?";
    
    if ($excludeCustomerId > 0) {
        $sql .= " AND id != ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $phone, $excludeCustomerId);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $phone);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->num_rows > 0;
}

/**
 * Search customers by name or phone
 * @param string $searchTerm Search term
 * @param int $limit Maximum number of results to return
 * @return array Array of customers
 */
function searchCustomers($searchTerm, $limit = 10) {
    $conn = getDBConnection();
    
    $sql = "SELECT * FROM customers 
            WHERE fullname LIKE ? OR phone LIKE ? 
            ORDER BY fullname 
            LIMIT ?";
    
    $searchParam = "%$searchTerm%";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssi", $searchParam, $searchParam, $limit);
    $stmt->execute();
    
    $result = $stmt->get_result();
    
    $customers = [];
    while ($row = $result->fetch_assoc()) {
        $customers[] = $row;
    }
    
    return $customers;
}

/**
 * Send notification to user
 * @param int $userId User ID
 * @param string $title Notification title
 * @param string $message Notification message
 * @param string $type Notification type (info, success, warning, error)
 * @return bool True on success, false on failure
 */
function sendNotification($userId, $title, $message, $type = 'info') {
    $conn = getDBConnection();
    
    // Check if notifications table exists
    if (!tableExists($conn, 'notifications')) {
        return false;
    }
    
    $sql = "INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isss", $userId, $title, $message, $type);
    
    return $stmt->execute();
}

/**
 * Send message to user
 * @param int $senderId Sender user ID
 * @param int $receiverId Receiver user ID
 * @param string $message Message content
 * @return bool True on success, false on failure
 */
function sendMessage($senderId, $receiverId, $message) {
    $conn = getDBConnection();
    
    // Check if messages table exists
    if (!tableExists($conn, 'messages')) {
        return false;
    }
    
    $sql = "INSERT INTO messages (sender_id, receiver_id, message, created_at) VALUES (?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iis", $senderId, $receiverId, $message);
    
    return $stmt->execute();
}

//
// HELPER FUNCTIONS
//

/**
 * Format date in specified format
 * @param string $date Date string
 * @param string $format Format (default: d.m.Y H:i)
 * @return string Formatted date
 */
function formatDate($date, $format = 'd.m.Y H:i') {
    if (empty($date)) {
        return '-';
    }
    
    return date($format, strtotime($date));
}

/**
 * Format money amount with currency symbol
 * @param float $amount Amount to format
 * @param string $currency Currency symbol (default: ₼)
 * @return string Formatted money amount
 */
function formatMoney($amount, $currency = '₼') {
    return number_format((float)$amount, 2, '.', ' ') . ' ' . $currency;
}

/**
 * Format phone number
 * @param string $phone Phone number
 * @return string Formatted phone number
 */
function formatPhoneNumber($phone) {
    // Remove all non-numeric characters
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    if (strlen($phone) == 10) {
        // Assume format: 0501234567
        return '+994 ' . substr($phone, 0, 2) . ' ' . substr($phone, 2, 3) . ' ' . substr($phone, 5, 2) . ' ' . substr($phone, 7, 2);
    } elseif (strlen($phone) == 9) {
        // Assume format: 501234567
        return '+994 ' . substr($phone, 0, 2) . ' ' . substr($phone, 2, 3) . ' ' . substr($phone, 5, 2) . ' ' . substr($phone, 7, 2);
    } elseif (strlen($phone) == 12 && substr($phone, 0, 3) == '994') {
        // Assume format: 994501234567
        return '+994 ' . substr($phone, 3, 2) . ' ' . substr($phone, 5, 3) . ' ' . substr($phone, 8, 2) . ' ' . substr($phone, 10, 2);
    }
    
    // If no recognized format, return as is
    return $phone;
}

/**
 * Sanitize input data
 * @param string $data Data to sanitize
 * @return string Sanitized data
 */
function sanitizeInput($input) {
    $input = trim($input);
    $input = stripslashes($input);
    $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
    
    return $input;
}

/**
 * Validate email address
 * @param string $email Email address to validate
 * @return bool True if email is valid, false otherwise
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate phone number
 * @param string $phone Phone number to validate
 * @return bool True if phone is valid, false otherwise
 */
function validatePhone($phone) {
    // Remove all non-numeric characters
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Check if it has at least 9 digits
    return strlen($phone) >= 9;
}

/**
 * Generate a random strong password
 * @param int $length Password length
 * @return string Generated password
 */
function generatePassword($length = 10) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+';
    $password = '';
    
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[mt_rand(0, strlen($chars) - 1)];
    }
    
    return $password;
}

/**
 * Generate random string
 * @param int $length Length of string
 * @return string Random string
 */
function generateRandomString($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $randomString = '';
    
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, strlen($characters) - 1)];
    }
    
    return $randomString;
}

/**
 * Get file extension
 * @param string $filename Filename
 * @return string File extension
 */
function getFileExtension($filename) {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

/**
 * Check if file extension is allowed
 * @param string $extension File extension
 * @return bool True if allowed, false otherwise
 */
function isAllowedExtension($extension) {
    return in_array(strtolower($extension), ALLOWED_EXTENSIONS);
}

/**
 * Upload file
 * @param array $file File from $_FILES
 * @param string $targetDir Target directory
 * @param string $newFilename New filename (optional)
 * @return string|bool Path to uploaded file or false on failure
 */
function uploadFile($file, $targetDir, $newFilename = '') {
    // Check if file was uploaded without errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }
    
    // Check file size
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        return false;
    }
    
    // Check file extension
    $fileExtension = getFileExtension($file['name']);
    if (!isAllowedExtension($fileExtension)) {
        return false;
    }
    
    // Create target directory if it doesn't exist
    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0755, true);
    }
    
    // Generate new filename if not provided
    if (empty($newFilename)) {
        $newFilename = generateRandomString(10) . '.' . $fileExtension;
    } else {
        $newFilename = $newFilename . '.' . $fileExtension;
    }
    
    $targetPath = $targetDir . '/' . $newFilename;
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return $targetPath;
    }
    
    return false;
}

/**
 * Get current page name
 * @return string Current page name
 */
function getCurrentPage() {
    $currentPage = basename($_SERVER['PHP_SELF']);
    
    // Remove file extension
    $currentPage = pathinfo($currentPage, PATHINFO_FILENAME);
    
    return $currentPage;
}

/**
 * Check if string contains only ASCII characters
 * @param string $str String to check
 * @return bool True if ASCII only, false otherwise
 */
function isAscii($str) {
    return mb_check_encoding($str, 'ASCII');
}

/**
 * Transliterate non-ASCII characters to ASCII
 * @param string $str String to transliterate
 * @return string Transliterated string
 */
function transliterate($str) {
    if (isAscii($str)) {
        return $str;
    }
    
    $transliterationTable = [
        'ə' => 'e', 'Ə' => 'E',
        'ü' => 'u', 'Ü' => 'U',
        'ö' => 'o', 'Ö' => 'O',
        'ğ' => 'g', 'Ğ' => 'G',
        'ı' => 'i', 'I' => 'I',
        'ş' => 's', 'Ş' => 'S',
        'ç' => 'c', 'Ç' => 'C',
        'ä' => 'a', 'Ä' => 'A'
    ];
    
    return str_replace(array_keys($transliterationTable), array_values($transliterationTable), $str);
}

/**
 * Get top customers by order count or total spent
 * @param string $period Period to filter by (week, month, year, all)
 * @param int $limit Maximum number of customers to return
 * @param string $orderBy Field to order by (order_count or total_spent)
 * @param int|null $sellerId Seller ID to filter by (null for all sellers)
 * @return array Array of top customers
 */
function getTopCustomers($period = 'month', $limit = 5, $orderBy = 'total_spent', $sellerId = null) {
    $conn = getDBConnection();
    
    // Check if required tables exist
    if (!tableExists($conn, 'orders') || !tableExists($conn, 'customers')) {
        return [];
    }
    
    // Determine date range based on period
    $dateCondition = "";
    switch ($period) {
        case 'week':
            $dateCondition = "AND o.order_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            break;
        case 'month':
            $dateCondition = "AND o.order_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            break;
        case 'year':
            $dateCondition = "AND o.order_date >= DATE_SUB(NOW(), INTERVAL 1 YEAR)";
            break;
        case 'all':
        default:
            $dateCondition = "";
            break;
    }
    
    // Seller condition
    $sellerCondition = "";
    if ($sellerId !== null) {
        $sellerCondition = "AND o.seller_id = " . (int)$sellerId;
    }
    
    // Order by clause
    $orderByClause = "total_spent DESC, order_count DESC";
    if ($orderBy === 'order_count') {
        $orderByClause = "order_count DESC, total_spent DESC";
    }
    
    // Get top customers
    $sql = "SELECT 
                c.id, 
                c.fullname, 
                c.phone,
                COUNT(o.id) as order_count,
                COALESCE(SUM(o.total_amount), 0) as total_spent,
                MAX(o.order_date) as last_order_date
            FROM orders o
            JOIN customers c ON o.customer_id = c.id
            WHERE 1=1 
            $dateCondition
            $sellerCondition
            GROUP BY c.id
            ORDER BY $orderByClause
            LIMIT ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $customers = [];
    
    while ($row = $result->fetch_assoc()) {
        $customers[] = $row;
    }
    
    return $customers;
}


<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/notification.php';

// Check if user is logged in
if (!isLoggedIn()) {
    redirectToLogin();
}

// Check if session has expired
if (isSessionExpired()) {
    session_unset();
    session_destroy();
    redirectToLogin();
}

// Check if user has production or admin role
if (!hasRole(['production', 'admin'])) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

// Get parameters
$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$status = $_GET['status'] ?? '';
$returnUrl = $_GET['return'] ?? 'index.php';

// Validate order ID
if ($orderId <= 0) {
    $_SESSION['error_message'] = 'Yanlış sifariş identifikatoru';
    header('Location: ' . $returnUrl);
    exit;
}

// Validate status
$validStatuses = ['processing', 'completed', 'delivered', 'cancelled'];
if (!in_array($status, $validStatuses)) {
    $_SESSION['error_message'] = 'Yanlış status';
    header('Location: ' . $returnUrl);
    exit;
}

// Get order information
$conn = getDBConnection();
$sql = "SELECT * FROM orders WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    $_SESSION['error_message'] = 'Sifariş tapılmadı';
    header('Location: ' . $returnUrl);
    exit;
}

// Check if the current status can be changed to the new status
$currentStatus = $order['order_status'];
$validTransitions = [
    'new' => ['processing', 'cancelled'],
    'processing' => ['completed', 'cancelled'],
    'completed' => ['delivered', 'cancelled']
];

if (!isset($validTransitions[$currentStatus]) || !in_array($status, $validTransitions[$currentStatus])) {
    $_SESSION['error_message'] = 'Bu statusda dəyişiklik etmək mümkün deyil';
    header('Location: ' . $returnUrl);
    exit;
}

// Update order status
$userId = $_SESSION['user_id'];
$sql = "UPDATE orders SET 
        order_status = ?,
        updated_by = ?,
        updated_at = NOW()";

// If status is delivered, set delivery date
if ($status === 'delivered') {
    $sql .= ", delivery_date = NOW(), delivered_by = ?";
}

$sql .= " WHERE id = ?";

$stmt = $conn->prepare($sql);

if ($status === 'delivered') {
    $stmt->bind_param("siis", $status, $userId, $userId, $orderId);
} else {
    $stmt->bind_param("sii", $status, $userId, $orderId);
}

$result = $stmt->execute();

if ($result) {
    // Log activity
    $orderNumber = $order['order_number'];
    logActivity($userId, 'update_order_status', "Sifariş #$orderNumber statusu dəyişildi: $currentStatus -> $status");
    
    // Send notification
    sendOrderStatusNotification($orderId, $status);
    
    $_SESSION['success_message'] = "Sifariş #$orderNumber statusu uğurla yeniləndi";
} else {
    $_SESSION['error_message'] = 'Status yeniləmə zamanı xəta baş verdi';
}

// Redirect back
header('Location: ' . ($returnUrl === 'details' ? "order-details.php?id=$orderId" : $returnUrl));
exit;
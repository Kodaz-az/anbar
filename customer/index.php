<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

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

// Check if user has customer role
if (!hasRole('customer')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

// Get user information
$userId = $_SESSION['user_id'];
$userName = $_SESSION['fullname'];

// Get customer details from the database
$conn = getDBConnection();
$sql = "SELECT * FROM customers WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();

if (!$customer) {
    // If customer record not found
    $_SESSION['error_message'] = "Müştəri məlumatları tapılmadı";
    header('Location: ../auth/logout.php');
    exit;
}

// Get customer stats
$sql = "SELECT 
            COUNT(*) as total_orders,
            SUM(CASE WHEN order_status = 'new' THEN 1 ELSE 0 END) as new_orders,
            SUM(CASE WHEN order_status = 'processing' THEN 1 ELSE 0 END) as processing_orders,
            SUM(CASE WHEN order_status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
            SUM(CASE WHEN order_status = 'delivered' THEN 1 ELSE 0 END) as delivered_orders,
            SUM(total_amount) as total_spent,
            SUM(remaining_amount) as total_debt
        FROM orders
        WHERE customer_id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $customer['id']);
$stmt->execute();
$customerStats = $stmt->get_result()->fetch_assoc();

// Get recent orders
$sql = "SELECT o.*, b.name as branch_name, s.fullname as seller_name
        FROM orders o
        LEFT JOIN branches b ON o.branch_id = b.id
        LEFT JOIN users s ON o.seller_id = s.id
        WHERE o.customer_id = ?
        ORDER BY o.order_date DESC
        LIMIT 5";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $customer['id']);
$stmt->execute();
$recentOrders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get unread messages count
$sql = "SELECT COUNT(*) AS unread_count FROM messages WHERE receiver_id = ? AND is_read = 0";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$unreadMessages = $result['unread_count'] ?? 0;

// Get upcoming payments (orders with remaining debt)
$sql = "SELECT id, order_number, total_amount, advance_payment, remaining_amount, order_date
        FROM orders
        WHERE customer_id = ? AND remaining_amount > 0 AND order_status != 'cancelled'
        ORDER BY order_date ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $customer['id']);
$stmt->execute();
$upcomingPayments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Order status text and colors
$statusConfig = [
    'new' => ['text' => 'Yeni', 'color' => 'info'],
    'processing' => ['text' => 'Hazırlanır', 'color' => 'warning'],
    'completed' => ['text' => 'Hazır', 'color' => 'success'],
    'delivered' => ['text' => 'Təhvil verilib', 'color' => 'success'],
    'cancelled' => ['text' => 'Ləğv edilib', 'color' => 'danger']
];
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1eb15a">
    <title>Ana Səhifə | AlumPro</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #1eb15a;
            --secondary-color: #1e5eb1;
            --primary-gradient: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            --text-light: #ffffff;
            --text-dark: #333333;
            --background-light: #f8f9fa;
            --card-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            --border-radius: 12px;
            --spacing-sm: 8px;
            --spacing-md: 16px;
            --spacing-lg: 24px;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Roboto', sans-serif;
            background-color: var(--background-light);
            color: var(--text-dark);
            line-height: 1.5;
            padding-bottom: 70px; /* Space for bottom navigation */
        }
        
        /* Mobile app style header */
        .app-header {
            background: var(--primary-gradient);
            color: var(--text-light);
            padding: var(--spacing-md);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-title {
            font-size: 20px;
            font-weight: 700;
        }
        
        .header-actions a {
            color: var(--text-light);
            text-decoration: none;
            font-size: 18px;
            position: relative;
        }
        
        /* Container */
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: var(--spacing-md);
        }
        
        /* Welcome card */
        .welcome-card {
            background: var(--primary-gradient);
            color: white;
            border-radius: var(--border-radius);
            padding: var(--spacing-lg);
            margin-bottom: var(--spacing-md);
            box-shadow: var(--card-shadow);
            position: relative;
            overflow: hidden;
        }
        
        .welcome-card::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            z-index: 1;
        }
        
        .welcome-text {
            position: relative;
            z-index: 2;
        }
        
        .welcome-title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .welcome-message {
            font-size: 16px;
            opacity: 0.9;
            margin-bottom: 20px;
        }
        
        .welcome-action {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 10px 20px;
            border-radius: 30px;
            color: white;
            text-decoration: none;
            font-weight: 500;
            transition: background 0.2s;
        }
        
        .welcome-action:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        
        /* Stats cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: var(--spacing-md);
            margin-bottom: var(--spacing-md);
        }
        
        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: var(--spacing-md);
            display: flex;
            flex-direction: column;
            text-align: center;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 14px;
            color: #6b7280;
        }
        
        /* Section headers */
        .section-header {
            margin-bottom: var(--spacing-md);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .section-title i {
            color: var(--primary-color);
        }
        
        .section-action {
            color: var(--primary-color);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }
        
        /* Order cards */
        .order-list {
            display: flex;
            flex-direction: column;
            gap: var(--spacing-md);
            margin-bottom: var(--spacing-lg);
        }
        
        .order-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            overflow: hidden;
            transition: transform 0.2s;
            text-decoration: none;
            color: var(--text-dark);
        }
        
        .order-card:active {
            transform: scale(0.98);
        }
        
        .order-header {
            padding: var(--spacing-md);
            border-bottom: 1px solid #f3f4f6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .order-number {
            font-weight: 700;
            color: var(--secondary-color);
        }
        
        .order-date {
            font-size: 14px;
            color: #6b7280;
        }
        
        .order-body {
            padding: var(--spacing-md);
        }
        
        .order-detail {
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .order-detail i {
            color: var(--primary-color);
            width: 16px;
        }
        
        .order-footer {
            padding: var(--spacing-md);
            border-top: 1px solid #f3f4f6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f9fafb;
        }
        
        .order-amount {
            font-weight: 700;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .badge-info { background-color: #e0f2fe; color: #0369a1; }
        .badge-warning { background-color: #fef3c7; color: #92400e; }
        .badge-success { background-color: #d1fae5; color: #065f46; }
        .badge-danger { background-color: #fee2e2; color: #b91c1c; }
        
        /* Payment reminder */
        .payment-reminder {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            margin-bottom: var(--spacing-lg);
            overflow: hidden;
        }
        
        .payment-item {
            padding: var(--spacing-md);
            border-bottom: 1px solid #f3f4f6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .payment-item:last-child {
            border-bottom: none;
        }
        
        .payment-info {
            flex: 1;
        }
        
        .payment-order {
            font-weight: 500;
            margin-bottom: 4px;
        }
        
        .payment-date {
            font-size: 12px;
            color: #6b7280;
        }
        
        .payment-amount {
            font-weight: 700;
            color: #b91c1c;
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: var(--spacing-lg);
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            margin-bottom: var(--spacing-lg);
        }
        
        .empty-icon {
            font-size: 48px;
            color: #d1d5db;
            margin-bottom: var(--spacing-md);
        }
        
        /* Bottom navigation */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-around;
            padding: 12px 0;
            z-index: 100;
        }
        
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            color: #6b7280;
            font-size: 12px;
        }
        
        .nav-item.active {
            color: var(--primary-color);
        }
        
        .nav-icon {
            font-size: 20px;
            margin-bottom: 4px;
        }
        
        /* Badge for notifications */
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #ef4444;
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        @media (max-width: 576px) {
            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }
            
            .stat-value {
                font-size: 20px;
            }
            
            .stat-label {
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    <header class="app-header">
        <div class="header-title">Ana Səhifə</div>
        <div class="header-actions">
            <a href="notifications.php">
                <i class="fas fa-bell"></i>
                <?php if($unreadMessages > 0): ?>
                    <span class="notification-badge"><?= $unreadMessages ?></span>
                <?php endif; ?>
            </a>
        </div>
    </header>
    
    <div class="container">
        <!-- Welcome Card -->
        <div class="welcome-card">
            <div class="welcome-text">
                <div class="welcome-title">Salam, <?= explode(' ', htmlspecialchars($customer['fullname']))[0] ?>!</div>
                <div class="welcome-message">AlumPro.az müştəri panelinə xoş gəlmisiniz. Sifarişlərinizi izləyə və yenilikləri görə bilərsiniz.</div>
                <a href="orders.php" class="welcome-action">Bütün sifarişlərə bax <i class="fas fa-arrow-right"></i></a>
            </div>
        </div>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= $customerStats['total_orders'] ?? 0 ?></div>
                <div class="stat-label">Ümumi Sifariş</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-value"><?= formatMoney($customerStats['total_spent'] ?? 0, '') ?></div>
                <div class="stat-label">Ümumi Alış</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-value"><?= $customerStats['processing_orders'] ?? 0 ?></div>
                <div class="stat-label">Hazırlanan</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-value"><?= $customerStats['completed_orders'] ?? 0 ?></div>
                <div class="stat-label">Hazır</div>
            </div>
        </div>
        
        <!-- Recent Orders -->
        <div class="section-header">
            <h2 class="section-title"><i class="fas fa-clipboard-list"></i> Son Sifarişlər</h2>
            <a href="orders.php" class="section-action">Hamısı <i class="fas fa-angle-right"></i></a>
        </div>
        
        <?php if (empty($recentOrders)): ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-clipboard-list"></i></div>
                <h3>Heç bir sifariş tapılmadı</h3>
                <p>Hələ ki heç bir sifariş verməmisiniz.</p>
            </div>
        <?php else: ?>
            <div class="order-list">
                <?php foreach ($recentOrders as $order): ?>
                    <?php 
                        $statusInfo = $statusConfig[$order['order_status']] ?? ['text' => 'Bilinmir', 'color' => 'info'];
                    ?>
                    <a href="order-details.php?id=<?= $order['id'] ?>" class="order-card">
                        <div class="order-header">
                            <div class="order-number">#<?= htmlspecialchars($order['order_number']) ?></div>
                            <div class="order-date"><?= formatDate($order['order_date'], 'd.m.Y') ?></div>
                        </div>
                        
                        <div class="order-body">
                            <div class="order-detail">
                                <i class="fas fa-store"></i>
                                <span><?= htmlspecialchars($order['branch_name']) ?></span>
                            </div>
                            <div class="order-detail">
                                <i class="fas fa-user"></i>
                                <span><?= htmlspecialchars($order['seller_name']) ?></span>
                            </div>
                            <?php if ($order['remaining_amount'] > 0): ?>
                                <div class="order-detail">
                                    <i class="fas fa-exclamation-circle text-danger"></i>
                                    <span style="color: #b91c1c;">Qalıq borc: <?= formatMoney($order['remaining_amount']) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="order-footer">
                            <div class="order-amount"><?= formatMoney($order['total_amount']) ?></div>
                            <span class="status-badge badge-<?= $statusInfo['color'] ?>"><?= $statusInfo['text'] ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <!-- Payment Reminders -->
        <?php if (!empty($upcomingPayments)): ?>
            <div class="section-header">
                <h2 class="section-title"><i class="fas fa-money-bill-wave"></i> Ödəniş Xatırlatmaları</h2>
            </div>
            
            <div class="payment-reminder">
                <?php foreach ($upcomingPayments as $payment): ?>
                    <div class="payment-item">
                        <div class="payment-info">
                            <div class="payment-order">Sifariş #<?= htmlspecialchars($payment['order_number']) ?></div>
                            <div class="payment-date"><?= formatDate($payment['order_date'], 'd.m.Y') ?></div>
                        </div>
                        <div class="payment-amount"><?= formatMoney($payment['remaining_amount']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Bottom Navigation -->
    <nav class="bottom-nav">
        <a href="index.php" class="nav-item active">
            <div class="nav-icon"><i class="fas fa-home"></i></div>
            <span>Ana Səhifə</span>
        </a>
        <a href="orders.php" class="nav-item">
            <div class="nav-icon"><i class="fas fa-clipboard-list"></i></div>
            <span>Sifarişlər</span>
        </a>
        <a href="messages.php" class="nav-item">
            <div class="nav-icon"><i class="fas fa-envelope"></i></div>
            <span>Mesajlar</span>
            <?php if($unreadMessages > 0): ?>
                <span class="notification-badge"><?= $unreadMessages ?></span>
            <?php endif; ?>
        </a>
        <a href="profile.php" class="nav-item">
            <div class="nav-icon"><i class="fas fa-user"></i></div>
            <span>Profil</span>
        </a>
    </nav>
</body>
</html>
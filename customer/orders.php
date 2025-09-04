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

// Get customer information
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

// Get filter parameters
$status = $_GET['status'] ?? 'all';
$sort = $_GET['sort'] ?? 'date_desc';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

// Build query
$whereClause = "WHERE o.customer_id = ?";
$params = [$customer['id']];
$types = "i";

if ($status !== 'all') {
    $whereClause .= " AND o.order_status = ?";
    $params[] = $status;
    $types .= "s";
}

// Set order by clause based on sort parameter
$orderByClause = "ORDER BY o.order_date DESC"; // Default
switch ($sort) {
    case 'date_asc':
        $orderByClause = "ORDER BY o.order_date ASC";
        break;
    case 'amount_desc':
        $orderByClause = "ORDER BY o.total_amount DESC";
        break;
    case 'amount_asc':
        $orderByClause = "ORDER BY o.total_amount ASC";
        break;
}

// Get total orders count
$sqlCount = "SELECT COUNT(*) as total FROM orders o $whereClause";
$stmt = $conn->prepare($sqlCount);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$totalOrders = $result['total'];

// Calculate total pages
$totalPages = ceil($totalOrders / $perPage);

// Get orders
$sql = "SELECT o.*, s.fullname as seller_name, b.name as branch_name
        FROM orders o
        LEFT JOIN users s ON o.seller_id = s.id
        LEFT JOIN branches b ON o.branch_id = b.id
        $whereClause
        $orderByClause
        LIMIT ?, ?";

$params[] = $offset;
$params[] = $perPage;
$types .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get unread messages count
$sql = "SELECT COUNT(*) AS unread_count FROM messages WHERE receiver_id = ? AND is_read = 0";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$unreadMessages = $result['unread_count'] ?? 0;

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
    <title>Sifarişlərim | AlumPro</title>
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
        
        /* Filter bar */
        .filter-bar {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            margin-bottom: var(--spacing-md);
            overflow: hidden;
        }
        
        .status-tabs {
            display: flex;
            overflow-x: auto;
            white-space: nowrap;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none; /* Firefox */
        }
        
        .status-tabs::-webkit-scrollbar {
            display: none; /* Chrome, Safari, Opera */
        }
        
        .status-tab {
            padding: 12px 16px;
            color: #6b7280;
            font-weight: 500;
            transition: all 0.2s;
            border-bottom: 2px solid transparent;
            flex-shrink: 0;
            text-decoration: none;
        }
        
        .status-tab.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }
        
        .sort-bar {
            padding: 10px 16px;
            border-top: 1px solid #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .sort-label {
            font-size: 14px;
            color: #6b7280;
        }
        
        .sort-select {
            border: none;
            background: none;
            font-weight: 500;
            color: var(--primary-color);
            padding-right: 20px;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%231eb15a'%3E%3Cpath d='M7 10l5 5 5-5z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right center;
            background-size: 20px;
        }
        
        /* Order list */
        .order-list {
            display: flex;
            flex-direction: column;
            gap: var(--spacing-md);
            margin-bottom: var(--spacing-lg);
        }
        
        .order-item {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            overflow: hidden;
            transition: transform 0.2s;
            text-decoration: none;
            color: var(--text-dark);
            display: block;
        }
        
        .order-item:active {
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
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: var(--spacing-lg);
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
        }
        
        .empty-icon {
            font-size: 48px;
            color: #d1d5db;
            margin-bottom: var(--spacing-md);
        }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: var(--spacing-md);
        }
        
        .pagination-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: white;
            color: #374151;
            font-weight: 500;
            text-decoration: none;
            box-shadow: var(--card-shadow);
        }
        
        .pagination-link.active {
            background: var(--primary-gradient);
            color: white;
        }
        
        .pagination-link.disabled {
            opacity: 0.5;
            pointer-events: none;
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
    </style>
</head>
<body>
    <header class="app-header">
        <div class="header-title">Sifarişlərim</div>
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
        <!-- Filter bar -->
        <div class="filter-bar">
            <div class="status-tabs">
                <a href="?status=all<?= !empty($sort) ? '&sort=' . $sort : '' ?>" class="status-tab <?= $status === 'all' ? 'active' : '' ?>">
                    Hamısı
                </a>
                <a href="?status=new<?= !empty($sort) ? '&sort=' . $sort : '' ?>" class="status-tab <?= $status === 'new' ? 'active' : '' ?>">
                    Yeni
                </a>
                <a href="?status=processing<?= !empty($sort) ? '&sort=' . $sort : '' ?>" class="status-tab <?= $status === 'processing' ? 'active' : '' ?>">
                    Hazırlanır
                </a>
                <a href="?status=completed<?= !empty($sort) ? '&sort=' . $sort : '' ?>" class="status-tab <?= $status === 'completed' ? 'active' : '' ?>">
                    Hazır
                </a>
                <a href="?status=delivered<?= !empty($sort) ? '&sort=' . $sort : '' ?>" class="status-tab <?= $status === 'delivered' ? 'active' : '' ?>">
                    Təhvil verilib
                </a>
                <a href="?status=cancelled<?= !empty($sort) ? '&sort=' . $sort : '' ?>" class="status-tab <?= $status === 'cancelled' ? 'active' : '' ?>">
                    Ləğv edilib
                </a>
            </div>
            
            <div class="sort-bar">
                <div class="sort-label">Sıralama:</div>
                <select class="sort-select" onchange="window.location.href='?status=<?= $status ?>&sort='+this.value">
                    <option value="date_desc" <?= $sort === 'date_desc' ? 'selected' : '' ?>>Tarix (Yeni - Köhnə)</option>
                    <option value="date_asc" <?= $sort === 'date_asc' ? 'selected' : '' ?>>Tarix (Köhnə - Yeni)</option>
                    <option value="amount_desc" <?= $sort === 'amount_desc' ? 'selected' : '' ?>>Məbləğ (Böyük - Kiçik)</option>
                    <option value="amount_asc" <?= $sort === 'amount_asc' ? 'selected' : '' ?>>Məbləğ (Kiçik - Böyük)</option>
                </select>
            </div>
        </div>
        
        <!-- Orders List -->
        <?php if (empty($orders)): ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-clipboard-list"></i></div>
                <h3>Heç bir sifariş tapılmadı</h3>
                <p>Seçdiyiniz filter kriteriyalarına uyğun sifariş tapılmadı.</p>
            </div>
        <?php else: ?>
            <div class="order-list">
                <?php foreach ($orders as $order): ?>
                    <?php 
                        $statusInfo = $statusConfig[$order['order_status']] ?? ['text' => 'Bilinmir', 'color' => 'info'];
                    ?>
                    <a href="order-details.php?id=<?= $order['id'] ?>" class="order-item">
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
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?status=<?= $status ?>&sort=<?= $sort ?>&page=1" class="pagination-link">
                            <i class="fas fa-angle-double-left"></i>
                        </a>
                        <a href="?status=<?= $status ?>&sort=<?= $sort ?>&page=<?= $page - 1 ?>" class="pagination-link">
                            <i class="fas fa-angle-left"></i>
                        </a>
                    <?php else: ?>
                        <span class="pagination-link disabled"><i class="fas fa-angle-double-left"></i></span>
                        <span class="pagination-link disabled"><i class="fas fa-angle-left"></i></span>
                    <?php endif; ?>
                    
                    <?php
                    // Show limited page numbers with current page in the middle
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    // Always show at least 5 pages if available
                    if ($endPage - $startPage + 1 < 5) {
                        if ($startPage === 1) {
                            $endPage = min($totalPages, 5);
                        } elseif ($endPage === $totalPages) {
                            $startPage = max(1, $totalPages - 4);
                        }
                    }
                    
                    for ($i = $startPage; $i <= $endPage; $i++): 
                    ?>
                        <a href="?status=<?= $status ?>&sort=<?= $sort ?>&page=<?= $i ?>" class="pagination-link <?= $i === $page ? 'active' : '' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?status=<?= $status ?>&sort=<?= $sort ?>&page=<?= $page + 1 ?>" class="pagination-link">
                            <i class="fas fa-angle-right"></i>
                        </a>
                        <a href="?status=<?= $status ?>&sort=<?= $sort ?>&page=<?= $totalPages ?>" class="pagination-link">
                            <i class="fas fa-angle-double-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="pagination-link disabled"><i class="fas fa-angle-right"></i></span>
                        <span class="pagination-link disabled"><i class="fas fa-angle-double-right"></i></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <!-- Bottom Navigation -->
    <nav class="bottom-nav">
        <a href="index.php" class="nav-item">
            <div class="nav-icon"><i class="fas fa-home"></i></div>
            <span>Ana Səhifə</span>
        </a>
        <a href="orders.php" class="nav-item active">
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
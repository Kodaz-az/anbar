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

// Handle mark all as read action
if (isset($_GET['action']) && $_GET['action'] === 'mark_all_read') {
    markAllNotificationsRead($userId);
    header('Location: notifications.php');
    exit;
}

// Handle mark single notification as read
if (isset($_GET['action']) && $_GET['action'] === 'mark_read' && isset($_GET['id'])) {
    $notificationId = (int)$_GET['id'];
    markNotificationRead($notificationId, $userId);
    header('Location: notifications.php');
    exit;
}

// Get notifications with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Get total count
$sql = "SELECT COUNT(*) as total FROM notifications WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$totalNotifications = $result['total'];

// Calculate total pages
$totalPages = ceil($totalNotifications / $perPage);

// Get notifications
$sql = "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?, ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iii", $userId, $offset, $perPage);
$stmt->execute();
$notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get unread notification count
$sql = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$unreadCount = $result['count'];

// Get unread messages count for the nav badge
$sql = "SELECT COUNT(*) AS unread_count FROM messages WHERE receiver_id = ? AND is_read = 0";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$unreadMessages = $result['unread_count'] ?? 0;
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1eb15a">
    <title>Bildirişlər | AlumPro</title>
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
            font-size: 16px;
            position: relative;
            margin-left: var(--spacing-md);
        }
        
        /* Container */
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: var(--spacing-md);
        }
        
        /* Notification stats */
        .notification-stats {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: var(--spacing-md);
            margin-bottom: var(--spacing-md);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .stats-count {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .stats-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }
        
        .stats-text {
            font-size: 14px;
            color: #6b7280;
        }
        
        .stats-value {
            font-weight: 700;
            font-size: 16px;
            color: var(--text-dark);
        }
        
        .stats-action {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
        }
        
        /* Notification list */
        .notification-list {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            margin-bottom: var(--spacing-lg);
            overflow: hidden;
        }
        
        .notification-item {
            display: flex;
            padding: var(--spacing-md);
            border-bottom: 1px solid #f3f4f6;
            position: relative;
        }
        
        .notification-item:last-child {
            border-bottom: none;
        }
        
        .notification-item.unread {
            background-color: #f0fdf4;
        }
        
        .notification-item.unread::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: var(--primary-color);
        }
        
        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            margin-right: var(--spacing-md);
            flex-shrink: 0;
        }
        
        .notification-icon.order {
            background: #3b82f6;
        }
        
        .notification-icon.payment {
            background: #ef4444;
        }
        
        .notification-icon.system {
            background: #8b5cf6;
        }
        
        .notification-content {
            flex: 1;
        }
        
        .notification-title {
            font-weight: 500;
            margin-bottom: 4px;
        }
        
        .notification-message {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 8px;
        }
        
        .notification-time {
            font-size: 12px;
            color: #9ca3af;
        }
        
        .notification-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-left: var(--spacing-md);
        }
        
        .notification-action {
            color: #6b7280;
            transition: color 0.2s;
        }
        
        .notification-action:hover {
            color: var(--primary-color);
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
        <div class="header-title">Bildirişlər</div>
        <div class="header-actions">
            <a href="?action=mark_all_read">
                <i class="fas fa-check-double"></i> Hamısını oxunmuş et
            </a>
        </div>
    </header>
    
    <div class="container">
        <!-- Notification Stats -->
        <div class="notification-stats">
            <div class="stats-count">
                <div class="stats-icon">
                    <i class="fas fa-bell"></i>
                </div>
                <div>
                    <div class="stats-text">Ümumi bildirişlər</div>
                    <div class="stats-value"><?= $totalNotifications ?> (<?= $unreadCount ?> oxunmamış)</div>
                </div>
            </div>
        </div>
        
        <!-- Notifications List -->
        <?php if (empty($notifications)): ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-bell-slash"></i></div>
                <h3>Bildiriş yoxdur</h3>
                <p>Hal-hazırda heç bir bildirişiniz yoxdur.</p>
            </div>
        <?php else: ?>
            <div class="notification-list">
                <?php foreach ($notifications as $notification): ?>
                    <div class="notification-item <?= $notification['is_read'] ? '' : 'unread' ?>">
                        <?php
                            $iconClass = 'system';
                            $icon = 'fa-bell';
                            
                            if (strpos($notification['type'], 'order') !== false) {
                                $iconClass = 'order';
                                $icon = 'fa-clipboard-list';
                            } elseif (strpos($notification['type'], 'payment') !== false) {
                                $iconClass = 'payment';
                                $icon = 'fa-money-bill-wave';
                            }
                        ?>
                        <div class="notification-icon <?= $iconClass ?>">
                            <i class="fas <?= $icon ?>"></i>
                        </div>
                        
                        <div class="notification-content">
                            <div class="notification-title"><?= htmlspecialchars($notification['title']) ?></div>
                            <div class="notification-message"><?= htmlspecialchars($notification['message']) ?></div>
                            <div class="notification-time"><?= formatDate($notification['created_at'], 'd.m.Y H:i') ?></div>
                        </div>
                        
                        <?php if (!$notification['is_read']): ?>
                            <div class="notification-actions">
                                <a href="?action=mark_read&id=<?= $notification['id'] ?>" class="notification-action" title="Oxunmuş et">
                                    <i class="fas fa-check"></i>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=1" class="pagination-link">
                            <i class="fas fa-angle-double-left"></i>
                        </a>
                        <a href="?page=<?= $page - 1 ?>" class="pagination-link">
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
                        <a href="?page=<?= $i ?>" class="pagination-link <?= $i === $page ? 'active' : '' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?>" class="pagination-link">
                            <i class="fas fa-angle-right"></i>
                        </a>
                        <a href="?page=<?= $totalPages ?>" class="pagination-link">
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
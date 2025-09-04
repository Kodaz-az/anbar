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

// Check if user has production or admin role
if (!hasRole(['production', 'admin'])) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

// Get user's information
$userId = $_SESSION['user_id'];
$userName = $_SESSION['fullname'];

// Get today's orders
$conn = getDBConnection();
$sql = "SELECT o.*, c.fullname AS customer_name 
        FROM orders o
        JOIN customers c ON o.customer_id = c.id
        WHERE o.order_status IN ('new', 'processing', 'completed')
        ORDER BY 
            CASE 
                WHEN o.order_status = 'new' THEN 1
                WHEN o.order_status = 'processing' THEN 2
                WHEN o.order_status = 'completed' THEN 3
                ELSE 4
            END,
            o.order_date DESC
        LIMIT 30";

$result = $conn->query($sql);
$pendingOrders = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $pendingOrders[] = $row;
    }
}

// Get counts by status
$sql = "SELECT order_status, COUNT(*) as count FROM orders GROUP BY order_status";
$result = $conn->query($sql);
$ordersByStatus = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $ordersByStatus[$row['order_status']] = $row['count'];
    }
}

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
    <title>İstehsalat Panel | AlumPro</title>
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
        
        /* Header */
        .app-header {
            background: var(--primary-gradient);
            color: var(--text-light);
            padding: var(--spacing-md);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
        }
        
        .header-title {
            font-size: 20px;
            font-weight: 700;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
        }
        
        .user-name {
            font-weight: 500;
        }
        
        .logout-btn {
            color: white;
            text-decoration: none;
            padding: var(--spacing-sm);
        }
        
        /* Container */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: var(--spacing-md);
        }
        
        /* Stats cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: var(--spacing-md);
            margin-bottom: var(--spacing-lg);
        }
        
        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: var(--spacing-md);
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: var(--spacing-sm);
        }
        
        .stat-label {
            color: #6b7280;
        }
        
        /* Action buttons */
        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: var(--spacing-md);
            margin-bottom: var(--spacing-lg);
        }
        
        .action-btn {
            flex: 1;
            min-width: 200px;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: var(--spacing-md);
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            text-decoration: none;
            color: var(--text-dark);
            transition: transform 0.2s;
        }
        
        .action-btn:hover {
            transform: translateY(-5px);
        }
        
        .action-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            margin-bottom: var(--spacing-sm);
        }
        
        .action-label {
            font-weight: 500;
        }
        
        /* Order list */
        .order-list {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            margin-bottom: var(--spacing-lg);
            overflow: hidden;
        }
        
        .list-header {
            background: var(--primary-gradient);
            color: white;
            padding: var(--spacing-md);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .list-title {
            font-size: 18px;
            font-weight: 500;
            margin: 0;
        }
        
        .order-item {
            display: flex;
            padding: var(--spacing-md);
            border-bottom: 1px solid #f3f4f6;
            transition: background 0.2s;
        }
        
        .order-item:hover {
            background: #f9fafb;
        }
        
        .order-item:last-child {
            border-bottom: none;
        }
        
        .order-status {
            width: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .status-indicator {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
        }
        
        .status-new { background: #3b82f6; }
        .status-processing { background: #f59e0b; }
        .status-completed { background: #10b981; }
        
        .order-info {
            flex: 1;
            margin-left: var(--spacing-md);
        }
        
        .order-number {
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .order-customer, .order-date {
            font-size: 14px;
            color: #6b7280;
        }
        
        .order-actions {
            display: flex;
            gap: var(--spacing-sm);
            align-items: center;
        }
        
        .order-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #f3f4f6;
            color: #374151;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .order-btn:hover {
            background: var(--primary-color);
            color: white;
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
            font-size: 24px;
            margin-bottom: 4px;
        }
        
        /* Status badge */
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
            padding: var(--spacing-lg);
            text-align: center;
            color: #6b7280;
        }
        
        .empty-icon {
            font-size: 48px;
            margin-bottom: var(--spacing-md);
            opacity: 0.3;
        }
        
        /* Search bar */
        .search-bar {
            display: flex;
            margin-bottom: var(--spacing-md);
        }
        
        .search-input {
            flex: 1;
            padding: 12px;
            border: 1px solid #e5e7eb;
            border-radius: var(--border-radius) 0 0 var(--border-radius);
            font-size: 16px;
        }
        
        .search-btn {
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: 0 var(--border-radius) var(--border-radius) 0;
            padding: 0 20px;
            cursor: pointer;
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .action-buttons {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
            }
            
            .order-actions {
                flex-direction: column;
            }
        }
        
        @media (max-width: 576px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                grid-template-columns: 1fr;
            }
            
            .order-item {
                flex-direction: column;
            }
            
            .order-status {
                width: 100%;
                justify-content: flex-start;
                margin-bottom: var(--spacing-sm);
            }
            
            .order-info {
                margin-left: 0;
                margin-bottom: var(--spacing-sm);
            }
            
            .order-actions {
                flex-direction: row;
                justify-content: flex-end;
            }
        }
    </style>
</head>
<body>
    <header class="app-header">
        <div class="header-title">AlumPro İstehsalat</div>
        <div class="user-info">
            <span class="user-name"><?= htmlspecialchars($userName) ?></span>
            <a href="../auth/logout.php" class="logout-btn" title="Çıxış"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </header>
    
    <div class="container">
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= $ordersByStatus['new'] ?? 0 ?></div>
                <div class="stat-label">Yeni Sifarişlər</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-value"><?= $ordersByStatus['processing'] ?? 0 ?></div>
                <div class="stat-label">Hazırlanan Sifarişlər</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-value"><?= $ordersByStatus['completed'] ?? 0 ?></div>
                <div class="stat-label">Hazır Sifarişlər</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-value"><?= $ordersByStatus['delivered'] ?? 0 ?></div>
                <div class="stat-label">Təhvil Verilmiş</div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="action-buttons">
            <a href="barcode-scanner.php" class="action-btn">
                <div class="action-icon"><i class="fas fa-barcode"></i></div>
                <div class="action-label">Barkod Oxuyucu</div>
            </a>
            
            <a href="task-list.php" class="action-btn">
                <div class="action-icon"><i class="fas fa-tasks"></i></div>
                <div class="action-label">Tapşırıq Siyahısı</div>
            </a>
            
            <a href="update-status.php" class="action-btn">
                <div class="action-icon"><i class="fas fa-exchange-alt"></i></div>
                <div class="action-label">Status Yenilə</div>
            </a>
        </div>
        
        <!-- Search Bar -->
        <form action="search.php" method="get" class="search-bar">
            <input type="text" name="q" placeholder="Sifariş №, müştəri adı və ya barkod daxil edin..." class="search-input">
            <button type="submit" class="search-btn"><i class="fas fa-search"></i></button>
        </form>
        
        <!-- Order List -->
        <div class="order-list">
            <div class="list-header">
                <h2 class="list-title">Gözləyən Sifarişlər</h2>
            </div>
            
            <?php if (empty($pendingOrders)): ?>
                <div class="empty-state">
                    <div class="empty-icon"><i class="fas fa-clipboard-list"></i></div>
                    <h3>Heç bir gözləyən sifariş yoxdur</h3>
                </div>
            <?php else: ?>
                <?php foreach ($pendingOrders as $order): ?>
                    <?php 
                        $statusInfo = $statusConfig[$order['order_status']];
                        $statusIconClass = '';
                        $statusClass = '';
                        
                        switch ($order['order_status']) {
                            case 'new':
                                $statusIconClass = 'fa-plus';
                                $statusClass = 'status-new';
                                break;
                            case 'processing':
                                $statusIconClass = 'fa-cog';
                                $statusClass = 'status-processing';
                                break;
                            case 'completed':
                                $statusIconClass = 'fa-check';
                                $statusClass = 'status-completed';
                                break;
                        }
                    ?>
                    <div class="order-item">
                        <div class="order-status">
                            <div class="status-indicator <?= $statusClass ?>">
                                <i class="fas <?= $statusIconClass ?>"></i>
                            </div>
                        </div>
                        
                        <div class="order-info">
                            <div class="order-number">#<?= htmlspecialchars($order['order_number']) ?></div>
                            <div class="order-customer"><?= htmlspecialchars($order['customer_name']) ?></div>
                            <div class="order-date"><?= formatDate($order['order_date'], 'd.m.Y H:i') ?></div>
                        </div>
                        
                        <div class="order-actions">
                            <a href="order-details.php?id=<?= $order['id'] ?>" class="order-btn" title="Ətraflı bax">
                                <i class="fas fa-eye"></i>
                            </a>
                            
                            <a href="barcode-scanner.php?barcode=<?= urlencode($order['barcode']) ?>" class="order-btn" title="Barkod ilə aç">
                                <i class="fas fa-barcode"></i>
                            </a>
                            
                            <?php if ($order['order_status'] === 'new'): ?>
                                <a href="update-status.php?id=<?= $order['id'] ?>&status=processing" class="order-btn" title="Hazırlanır">
                                    <i class="fas fa-cog"></i>
                                </a>
                            <?php elseif ($order['order_status'] === 'processing'): ?>
                                <a href="update-status.php?id=<?= $order['id'] ?>&status=completed" class="order-btn" title="Hazırdır">
                                    <i class="fas fa-check"></i>
                                </a>
                            <?php elseif ($order['order_status'] === 'completed'): ?>
                                <a href="update-status.php?id=<?= $order['id'] ?>&status=delivered" class="order-btn" title="Təhvil ver">
                                    <i class="fas fa-truck"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Bottom Navigation -->
    <nav class="bottom-nav">
        <a href="index.php" class="nav-item active">
            <div class="nav-icon"><i class="fas fa-home"></i></div>
            <span>Ana Səhifə</span>
        </a>
        <a href="barcode-scanner.php" class="nav-item">
            <div class="nav-icon"><i class="fas fa-barcode"></i></div>
            <span>Barkod</span>
        </a>
        <a href="task-list.php" class="nav-item">
            <div class="nav-icon"><i class="fas fa-tasks"></i></div>
            <span>Tapşırıqlar</span>
        </a>
        <a href="profile.php" class="nav-item">
            <div class="nav-icon"><i class="fas fa-user"></i></div>
            <span>Profil</span>
        </a>
    </nav>
</body>
</html>
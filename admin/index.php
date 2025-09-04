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

// Check if user has admin role
if (!hasRole(ROLE_ADMIN)) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

// Get admin's information
$adminId = $_SESSION['user_id'];
$adminName = $_SESSION['fullname'];

// Get system stats
$conn = getDBConnection();

// Total users
$sql = "SELECT COUNT(*) as count FROM users";
$result = $conn->query($sql);
$totalUsers = $result->fetch_assoc()['count'];

// Users by role
$sql = "SELECT role, COUNT(*) as count FROM users GROUP BY role";
$result = $conn->query($sql);
$usersByRole = [];
while ($row = $result->fetch_assoc()) {
    $usersByRole[$row['role']] = $row['count'];
}

// Total orders
$sql = "SELECT COUNT(*) as count FROM orders";
$result = $conn->query($sql);
$totalOrders = $result->fetch_assoc()['count'];

// Orders by status
$sql = "SELECT order_status, COUNT(*) as count FROM orders GROUP BY order_status";
$result = $conn->query($sql);
$ordersByStatus = [];
while ($row = $result->fetch_assoc()) {
    $ordersByStatus[$row['order_status']] = $row['count'];
}

// Total customers
$sql = "SELECT COUNT(*) as count FROM customers";
$result = $conn->query($sql);
$totalCustomers = $result->fetch_assoc()['count'];

// Revenue stats
$sql = "SELECT 
            SUM(total_amount) as total_revenue, 
            SUM(advance_payment) as total_paid,
            SUM(remaining_amount) as total_debt
        FROM orders";
$result = $conn->query($sql);
$revenueStats = $result->fetch_assoc();

// Get recent orders
$sql = "SELECT o.*, c.fullname AS customer_name, s.fullname AS seller_name, b.name AS branch_name 
        FROM orders o 
        LEFT JOIN customers c ON o.customer_id = c.id
        LEFT JOIN users s ON o.seller_id = s.id
        LEFT JOIN branches b ON o.branch_id = b.id
        ORDER BY o.order_date DESC 
        LIMIT 10";
$result = $conn->query($sql);
$recentOrders = [];
while ($row = $result->fetch_assoc()) {
    $recentOrders[] = $row;
}

// Get recent users
$sql = "SELECT * FROM users ORDER BY created_at DESC LIMIT 5";
$result = $conn->query($sql);
$recentUsers = [];
while ($row = $result->fetch_assoc()) {
    $recentUsers[] = $row;
}

// Get low stock products
$lowStockProducts = getLowStockProducts(5);

// Get pending notifications (unread messages, system alerts, etc.)
$sql = "SELECT COUNT(*) as count FROM messages WHERE is_read = 0";
$result = $conn->query($sql);
$pendingNotifications = $result->fetch_assoc()['count'];

// Get system activity log
$sql = "SELECT al.*, u.fullname 
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.id
        ORDER BY al.created_at DESC 
        LIMIT 10";
$result = $conn->query($sql);
$activityLog = [];
while ($row = $result->fetch_assoc()) {
    $activityLog[] = $row;
}

// Order status text and colors
$statusConfig = [
    'new' => ['text' => 'Yeni', 'color' => 'info'],
    'processing' => ['text' => 'Hazırlanır', 'color' => 'warning'],
    'completed' => ['text' => 'Hazır', 'color' => 'success'],
    'delivered' => ['text' => 'Təhvil verilib', 'color' => 'success'],
    'cancelled' => ['text' => 'Ləğv edilib', 'color' => 'danger']
];

// Get branches
$sql = "SELECT * FROM branches";
$result = $conn->query($sql);
$branches = [];
while ($row = $result->fetch_assoc()) {
    $branches[] = $row;
}
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel | AlumPro</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .dashboard-stats {
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
            align-items: center;
            gap: var(--spacing-md);
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }
        
        .stat-info {
            flex: 1;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #6b7280;
            font-size: 14px;
        }
        
        .admin-content {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: var(--spacing-md);
        }
        
        .admin-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            margin-bottom: var(--spacing-md);
            overflow: hidden;
        }
        
        .admin-card-header {
            padding: var(--spacing-md);
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .admin-card-title {
            font-size: 18px;
            font-weight: 500;
            margin: 0;
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
        }
        
        .admin-card-actions a {
            color: var(--primary-color);
            font-size: 14px;
        }
        
        .admin-card-body {
            padding: var(--spacing-md);
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: var(--spacing-md);
        }
        
        .status-pill {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-pill-info { background-color: #e0f2fe; color: #0369a1; }
        .status-pill-success { background-color: #d1fae5; color: #065f46; }
        .status-pill-warning { background-color: #fef3c7; color: #92400e; }
        .status-pill-danger { background-color: #fee2e2; color: #b91c1c; }
        
        .recent-item {
            padding: var(--spacing-sm) 0;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .recent-item:last-child {
            border-bottom: none;
        }
        
        .recent-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        
        .recent-title {
            font-weight: 500;
        }
        
        .recent-date {
            font-size: 12px;
            color: #6b7280;
        }
        
        .activity-item {
            display: flex;
            gap: var(--spacing-md);
            padding: var(--spacing-sm) 0;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
            flex-shrink: 0;
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-user {
            font-weight: 500;
            margin-bottom: 2px;
        }
        
        .activity-action {
            font-size: 14px;
            color: #6b7280;
        }
        
        .activity-time {
            font-size: 12px;
            color: #6b7280;
        }
        
        .branch-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: var(--spacing-sm) 0;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .branch-item:last-child {
            border-bottom: none;
        }
        
        .branch-name {
            font-weight: 500;
        }
        
        .branch-address {
            font-size: 14px;
            color: #6b7280;
        }
        
        .user-role {
            font-size: 12px;
            padding: 2px 8px;
            border-radius: 12px;
            background: #f3f4f6;
        }
        
        .user-role-admin { background: #fee2e2; color: #b91c1c; }
        .user-role-seller { background: #e0f2fe; color: #0369a1; }
        .user-role-customer { background: #d1fae5; color: #065f46; }
        .user-role-production { background: #fef3c7; color: #92400e; }
        
        @media (max-width: 992px) {
            .admin-content {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- App Header -->
    <header class="app-header">
        <div class="header-left">
            <div class="logo">ALUMPRO.AZ</div>
            <div class="nav-links">
                <a href="index.php" class="active"><i class="fas fa-tachometer-alt"></i> Panel</a>
                <a href="users.php"><i class="fas fa-users"></i> İstifadəçilər</a>
                <a href="orders.php"><i class="fas fa-clipboard-list"></i> Sifarişlər</a>
                <a href="inventory.php"><i class="fas fa-boxes"></i> Anbar</a>
                <a href="branches.php"><i class="fas fa-building"></i> Filiallar</a>
                <a href="reports.php"><i class="fas fa-chart-bar"></i> Hesabatlar</a>
                <a href="settings.php"><i class="fas fa-cog"></i> Tənzimləmələr</a>
            </div>
        </div>
        <div class="header-right">
            <div class="user-info">
                <span><?= htmlspecialchars($adminName) ?> <i class="fas fa-angle-down"></i></span>
                <div class="user-menu">
                    <a href="profile.php"><i class="fas fa-user-cog"></i> Profil</a>
                    <a href="../auth/logout.php"><i class="fas fa-sign-out-alt"></i> Çıxış</a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="app-main">
        <div class="app-container">
            <div class="page-header">
                <h1><i class="fas fa-tachometer-alt"></i> Admin Panel</h1>
                <div>
                    <a href="settings.php" class="btn btn-outline">
                        <i class="fas fa-cog"></i> Tənzimləmələr
                    </a>
                </div>
            </div>

            <!-- Dashboard Stats -->
            <div class="dashboard-stats">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= $totalUsers ?></div>
                        <div class="stat-label">İstifadəçilər</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-clipboard-list"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= $totalOrders ?></div>
                        <div class="stat-label">Sifarişlər</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-user-tie"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= $totalCustomers ?></div>
                        <div class="stat-label">Müştərilər</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-money-bill-wave"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= formatMoney($revenueStats['total_revenue'] ?? 0, '') ?></div>
                        <div class="stat-label">Ümumi Gəlir</div>
                    </div>
                </div>
            </div>

            <!-- Main Dashboard Content -->
            <div class="admin-content">
                <!-- Left Column -->
                <div>
                    <!-- Orders Chart -->
                    <div class="admin-card">
                        <div class="admin-card-header">
                            <h2 class="admin-card-title"><i class="fas fa-chart-line"></i> Sifariş Statistikası</h2>
                            <div class="admin-card-actions">
                                <a href="reports.php">Ətraflı <i class="fas fa-arrow-right"></i></a>
                            </div>
                        </div>
                        <div class="admin-card-body">
                            <div class="chart-container">
                                <canvas id="orders-chart"></canvas>
                            </div>
                            
                            <div class="d-flex gap-2" style="flex-wrap: wrap;">
                                <div class="status-pill status-pill-info">Yeni: <?= $ordersByStatus['new'] ?? 0 ?></div>
                                <div class="status-pill status-pill-warning">Hazırlanır: <?= $ordersByStatus['processing'] ?? 0 ?></div>
                                <div class="status-pill status-pill-success">Hazır: <?= $ordersByStatus['completed'] ?? 0 ?></div>
                                <div class="status-pill status-pill-success">Təhvil verilib: <?= $ordersByStatus['delivered'] ?? 0 ?></div>
                                <div class="status-pill status-pill-danger">Ləğv edilib: <?= $ordersByStatus['cancelled'] ?? 0 ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Orders -->
                    <div class="admin-card">
                        <div class="admin-card-header">
                            <h2 class="admin-card-title"><i class="fas fa-clipboard-list"></i> Son Sifarişlər</h2>
                            <div class="admin-card-actions">
                                <a href="orders.php">Bütün sifarişlər <i class="fas fa-arrow-right"></i></a>
                            </div>
                        </div>
                        <div class="admin-card-body">
                            <?php if (empty($recentOrders)): ?>
                                <p class="text-center">Heç bir sifariş tapılmadı</p>
                            <?php else: ?>
                                <?php foreach ($recentOrders as $order): ?>
                                    <?php 
                                        $statusInfo = $statusConfig[$order['order_status']] ?? ['text' => 'Bilinmir', 'color' => 'info'];
                                    ?>
                                    <div class="recent-item">
                                        <div class="recent-header">
                                            <div class="recent-title">
                                                <a href="order-details.php?id=<?= $order['id'] ?>">#<?= htmlspecialchars($order['order_number']) ?></a>
                                            </div>
                                            <div class="recent-date"><?= formatDate($order['order_date'], 'd.m.Y H:i') ?></div>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <div><i class="fas fa-user text-primary"></i> <?= htmlspecialchars($order['customer_name']) ?></div>
                                                <div><i class="fas fa-user-tie text-primary"></i> <?= htmlspecialchars($order['seller_name']) ?></div>
                                            </div>
                                            <div>
                                                <div class="fw-bold"><?= formatMoney($order['total_amount']) ?></div>
                                                <div class="status-pill status-pill-<?= $statusInfo['color'] ?>"><?= $statusInfo['text'] ?></div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column -->
                <div>
                    <!-- Activity Log -->
                    <div class="admin-card">
                        <div class="admin-card-header">
                            <h2 class="admin-card-title"><i class="fas fa-history"></i> Son Fəaliyyətlər</h2>
                            <div class="admin-card-actions">
                                <a href="activity-log.php">Bütün fəaliyyətlər <i class="fas fa-arrow-right"></i></a>
                            </div>
                        </div>
                        <div class="admin-card-body">
                            <?php if (empty($activityLog)): ?>
                                <p class="text-center">Heç bir fəaliyyət tapılmadı</p>
                            <?php else: ?>
                                <?php foreach ($activityLog as $activity): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon">
                                            <?php
                                                $icon = 'fa-info';
                                                switch ($activity['action']) {
                                                    case 'login':
                                                        $icon = 'fa-sign-in-alt';
                                                        break;
                                                    case 'logout':
                                                        $icon = 'fa-sign-out-alt';
                                                        break;
                                                    case 'create_order':
                                                        $icon = 'fa-plus-circle';
                                                        break;
                                                    case 'update_order':
                                                        $icon = 'fa-edit';
                                                        break;
                                                    case 'delete_order':
                                                        $icon = 'fa-trash';
                                                        break;
                                                    case 'create_customer':
                                                        $icon = 'fa-user-plus';
                                                        break;
                                                    case 'update_customer':
                                                        $icon = 'fa-user-edit';
                                                        break;
                                                    case 'inventory_change':
                                                        $icon = 'fa-boxes';
                                                        break;
                                                }
                                            ?>
                                            <i class="fas <?= $icon ?>"></i>
                                        </div>
                                        <div class="activity-content">
                                            <div class="activity-user"><?= htmlspecialchars($activity['fullname'] ?? 'System') ?></div>
                                            <div class="activity-action"><?= htmlspecialchars($activity['details']) ?></div>
                                            <div class="activity-time"><?= formatDate($activity['created_at'], 'd.m.Y H:i') ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Low Stock Warning -->
                    <div class="admin-card">
                        <div class="admin-card-header">
                            <h2 class="admin-card-title"><i class="fas fa-exclamation-triangle"></i> Azalan Məhsullar</h2>
                            <div class="admin-card-actions">
                                <a href="inventory.php?filter=low-stock">Bütün azalan məhsullar <i class="fas fa-arrow-right"></i></a>
                            </div>
                        </div>
                        <div class="admin-card-body">
                            <?php if (empty($lowStockProducts)): ?>
                                <p class="text-center">Azalan məhsul yoxdur</p>
                            <?php else: ?>
                                <?php foreach ($lowStockProducts as $product): ?>
                                    <div class="recent-item">
                                        <div class="recent-title"><?= htmlspecialchars($product['name']) ?></div>
                                        <div class="d-flex justify-content-between align-items-center mt-1">
                                            <div>
                                                <?php if ($product['type'] === 'glass'): ?>
                                                    <div><?= $product['thickness'] ?>mm <?= htmlspecialchars($product['product_type']) ?></div>
                                                <?php else: ?>
                                                    <div><?= htmlspecialchars($product['product_type']) ?> <?= !empty($product['color']) ? '- ' . htmlspecialchars($product['color']) : '' ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="fw-bold status-pill status-pill-danger">
                                                <?= round($product['remaining']) ?> <?= htmlspecialchars($product['unit_of_measure']) ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Recent Users -->
                    <div class="admin-card">
                        <div class="admin-card-header">
                            <h2 class="admin-card-title"><i class="fas fa-users"></i> Son Qeydiyyatlı İstifadəçilər</h2>
                            <div class="admin-card-actions">
                                <a href="users.php">Bütün istifadəçilər <i class="fas fa-arrow-right"></i></a>
                            </div>
                        </div>
                        <div class="admin-card-body">
                            <?php if (empty($recentUsers)): ?>
                                <p class="text-center">Heç bir istifadəçi tapılmadı</p>
                            <?php else: ?>
                                <?php foreach ($recentUsers as $user): ?>
                                    <div class="recent-item">
                                        <div class="recent-header">
                                            <div class="recent-title">
                                                <a href="user-details.php?id=<?= $user['id'] ?>"><?= htmlspecialchars($user['fullname']) ?></a>
                                            </div>
                                            <div class="recent-date"><?= formatDate($user['created_at'], 'd.m.Y') ?></div>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <div><?= htmlspecialchars($user['email']) ?></div>
                                            </div>
                                            <div>
                                                <span class="user-role user-role-<?= $user['role'] ?>"><?= ucfirst($user['role']) ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Branches -->
                    <div class="admin-card">
                        <div class="admin-card-header">
                            <h2 class="admin-card-title"><i class="fas fa-building"></i> Filiallar</h2>
                            <div class="admin-card-actions">
                                <a href="branches.php">Bütün filiallar <i class="fas fa-arrow-right"></i></a>
                            </div>
                        </div>
                        <div class="admin-card-body">
                            <?php if (empty($branches)): ?>
                                <p class="text-center">Heç bir filial tapılmadı</p>
                            <?php else: ?>
                                <?php foreach ($branches as $branch): ?>
                                    <div class="branch-item">
                                        <div>
                                            <div class="branch-name"><?= htmlspecialchars($branch['name']) ?></div>
                                            <div class="branch-address"><?= htmlspecialchars($branch['address']) ?></div>
                                        </div>
                                        <div>
                                            <?php if ($branch['status'] === 'active'): ?>
                                                <span class="status-pill status-pill-success">Aktiv</span>
                                            <?php else: ?>
                                                <span class="status-pill status-pill-danger">Deaktiv</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="app-footer">
        <div>&copy; <?= date('Y') ?> AlumPro.az - Bütün hüquqlar qorunur</div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // User menu toggle
            const userInfo = document.querySelector('.user-info');
            userInfo.addEventListener('click', function() {
                this.classList.toggle('open');
            });
            
            // Orders chart
            const ordersCtx = document.getElementById('orders-chart').getContext('2d');
            const ordersChart = new Chart(ordersCtx, {
                type: 'bar',
                data: {
                    labels: ['Yanvar', 'Fevral', 'Mart', 'Aprel', 'May', 'İyun', 'İyul', 'Avqust', 'Sentyabr', 'Oktyabr', 'Noyabr', 'Dekabr'],
                    datasets: [
                        {
                            label: 'Sifarişlər',
                            data: [45, 52, 38, 60, 56, 65, 70, 65, 75, 80, 85, 90],
                            backgroundColor: 'rgba(30, 177, 90, 0.7)',
                            borderColor: 'rgba(30, 177, 90, 1)',
                            borderWidth: 1
                        },
                        {
                            label: 'Gəlir (min AZN)',
                            data: [25, 30, 22, 35, 30, 40, 42, 38, 45, 48, 52, 55],
                            backgroundColor: 'rgba(30, 94, 177, 0.7)',
                            borderColor: 'rgba(30, 94, 177, 1)',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>
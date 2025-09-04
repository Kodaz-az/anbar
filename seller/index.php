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

// Check if user has seller or admin role
if (!hasRole(['seller', 'admin'])) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

// Get seller's information
$sellerId = $_SESSION['user_id'];
$sellerName = $_SESSION['fullname'];
$branchId = $_SESSION['branch_id'] ?? null;

// Get branch information
$branch = getBranchById($branchId);
$branchName = $branch ? $branch['name'] : '';

// Get sales statistics
$todaySalesData = getSellerSales($sellerId, 'today');
$weekSalesData = getSellerSales($sellerId, 'week');
$monthSalesData = getSellerSales($sellerId, 'month');

// Extract stats for easy access
$todayStats = $todaySalesData['stats'] ?? [];
$weekStats = $weekSalesData['stats'] ?? [];
$monthStats = $monthSalesData['stats'] ?? [];

// Get recent orders
$recentOrders = isset($todaySalesData['recent_orders']) ? $todaySalesData['recent_orders'] : [];

// If there are not enough recent orders, get from database directly
if (count($recentOrders) < 5) {
    $conn = getDBConnection();
    $sql = "SELECT o.*, c.fullname AS customer_name 
            FROM orders o 
            JOIN customers c ON o.customer_id = c.id 
            WHERE o.seller_id = ? 
            ORDER BY o.order_date DESC 
            LIMIT 10";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $sellerId);
    $stmt->execute();
    $recentOrders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get low stock products
$lowStockProducts = getLowStockProducts(5);

// Get top customers
$topCustomers = getTopCustomers('month', 5, 'total_spent', $sellerId);

// Get unread message count
$unreadMessages = 0;
if (tableExists($conn, 'messages')) {
    $sql = "SELECT COUNT(*) AS unread_count FROM messages WHERE receiver_id = ? AND is_read = 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $sellerId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $unreadMessages = $result['unread_count'] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Satıcı Paneli | AlumPro</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        :root {
            --primary-color: #1eb15a;
            --secondary-color: #1e5eb1;
            --primary-gradient: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            --card-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --border-radius: 8px;
        }

        /* Dashboard-specific styles */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--card-shadow);
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            margin-bottom: 15px;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #333;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 14px;
            color: #666;
        }
        
        .action-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .action-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            background: white;
            color: #333;
            border: none;
            padding: 15px;
            border-radius: var(--border-radius);
            font-size: 16px;
            font-weight: 500;
            box-shadow: var(--card-shadow);
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
        }
        
        .action-btn:hover {
            background: var(--primary-gradient);
            color: white;
        }
        
        .action-btn i {
            font-size: 20px;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--card-shadow);
        }
        
        .data-table th {
            background: var(--primary-gradient);
            color: white;
            padding: 12px 15px;
            text-align: left;
        }
        
        .data-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .data-table tbody tr:last-child td {
            border-bottom: none;
        }
        
        .data-table tbody tr:hover {
            background-color: #f9f9f9;
        }
        
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .badge-success { background-color: #d1fae5; color: #065f46; }
        .badge-warning { background-color: #fef3c7; color: #92400e; }
        .badge-danger { background-color: #fee2e2; color: #b91c1c; }
        .badge-info { background-color: #e0f2fe; color: #0369a1; }
        
        .widget-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .widget-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
        }
        
        .view-all {
            font-size: 13px;
            color: var(--secondary-color);
            text-decoration: none;
        }
        
        .view-all:hover {
            text-decoration: underline;
        }
        
        .quick-stats {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .quick-stat {
            flex: 1;
            min-width: 200px;
            background: white;
            border-left: 4px solid var(--primary-color);
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 15px;
        }
        
        .quick-stat.warning { border-color: #f59e0b; }
        .quick-stat.success { border-color: #10b981; }
        .quick-stat.info { border-color: #3b82f6; }
        
        .quick-stat-value {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .quick-stat-label {
            font-size: 14px;
            color: #666;
        }
        
        /* Period tabs */
        .period-tabs {
            display: flex;
            background: #f3f4f6;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .period-tab {
            flex: 1;
            text-align: center;
            padding: 10px;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .period-tab.active {
            background: var(--primary-gradient);
            color: white;
            font-weight: 500;
        }
        
        /* Two column layout */
        .dashboard-layout {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
        }
        
        .main-content {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .side-widgets {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .widget {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--card-shadow);
        }
        
        /* Low stock indicator */
        .low-stock {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .low-stock:last-child {
            border-bottom: none;
        }
        
        .stock-indicator {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #fee2e2;
            color: #b91c1c;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }
        
        .stock-details {
            flex: 1;
        }
        
        .stock-name {
            font-weight: 600;
            margin-bottom: 3px;
        }
        
        .stock-info {
            font-size: 12px;
            color: #666;
        }
        
        /* Customer list */
        .customer-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .customer-item:last-child {
            border-bottom: none;
        }
        
        .customer-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-gradient);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 16px;
        }
        
        .customer-details {
            flex: 1;
        }
        
        .customer-name {
            font-weight: 600;
            margin-bottom: 3px;
        }
        
        .customer-info {
            font-size: 12px;
            color: #666;
        }
        
        .customer-value {
            font-weight: 600;
            color: var(--primary-color);
        }
        
        /* Button Action */
        .btn-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 4px;
            background: #f3f4f6;
            color: #374151;
            text-decoration: none;
            margin-right: 5px;
            transition: all 0.2s;
        }
        
        .btn-action:hover {
            background: var(--primary-gradient);
            color: white;
        }
        
        /* Responsive adjustments */
        @media (max-width: 1024px) {
            .dashboard-layout {
                grid-template-columns: 1fr;
            }
            
            .quick-stats {
                flex-direction: column;
            }
            
            .quick-stat {
                width: 100%;
            }
        }
        
        /* Ensure cards are displayed side-by-side even on smaller screens */
        @media (min-width: 640px) {
            .quick-stats {
                flex-direction: row;
                flex-wrap: wrap;
            }
            
            .quick-stat {
                min-width: calc(50% - 15px);
                flex: 0 0 calc(50% - 15px);
            }
        }
        
        @media (min-width: 1024px) {
            .quick-stat {
                min-width: 0;
                flex: 1;
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
                <a href="index.php" class="active"><i class="fas fa-home"></i> Ana Səhifə</a>
                <a href="orders.php"><i class="fas fa-clipboard-list"></i> Sifarişlər</a>
                <a href="customers.php"><i class="fas fa-users"></i> Müştərilər</a>
                <a href="warehouse.php"><i class="fas fa-warehouse"></i> Anbar</a>
                <a href="messages.php"><i class="fas fa-envelope"></i> 
                    Mesajlar
                    <?php if($unreadMessages > 0): ?>
                        <span class="notification-badge"><?= $unreadMessages ?></span>
                    <?php endif; ?>
                </a>
            </div>
        </div>
        <div class="header-right">
            <div class="user-info">
                <span><?= htmlspecialchars($sellerName) ?> <?= !empty($branchName) ? "(" . htmlspecialchars($branchName) . ")" : "" ?></span>
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
                <h1><i class="fas fa-tachometer-alt"></i> Satıcı Paneli</h1>
                <div class="breadcrumb">
                    <a href="index.php">Ana Səhifə</a> / <span>Panel</span>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="quick-stats">
                <div class="quick-stat">
                    <div class="quick-stat-value"><?= $todayStats['total_orders'] ?? 0 ?></div>
                    <div class="quick-stat-label">Bugünkü Sifarişlər</div>
                </div>
                <div class="quick-stat info">
                    <div class="quick-stat-value"><?= formatMoney($todayStats['total_sales'] ?? 0) ?></div>
                    <div class="quick-stat-label">Bugünkü Satış</div>
                </div>
                <div class="quick-stat success">
                    <div class="quick-stat-value"><?= formatMoney($weekStats['total_sales'] ?? 0) ?></div>
                    <div class="quick-stat-label">Həftəlik Satış</div>
                </div>
                <div class="quick-stat warning">
                    <div class="quick-stat-value"><?= formatMoney($monthStats['total_sales'] ?? 0) ?></div>
                    <div class="quick-stat-label">Aylıq Satış</div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons">
                <a href="hesabla.php" class="action-btn">
                    <i class="fas fa-plus-circle"></i> Yeni Sifariş
                </a>
                <a href="customers.php?action=new" class="action-btn">
                    <i class="fas fa-user-plus"></i> Yeni Müştəri
                </a>
                <a href="reports.php" class="action-btn">
                    <i class="fas fa-chart-bar"></i> Hesabatlar
                </a>
                <a href="messages.php?action=new" class="action-btn">
                    <i class="fas fa-envelope"></i> Yeni Mesaj
                </a>
            </div>

            <!-- Dashboard Layout -->
            <div class="dashboard-layout">
                <div class="main-content">
                    <!-- Recent Orders -->
                    <div class="widget">
                        <div class="widget-header">
                            <h2 class="widget-title">Son Sifarişlər</h2>
                            <a href="orders.php" class="view-all">Hamısına bax</a>
                        </div>
                        
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Sifariş №</th>
                                    <th>Müştəri</th>
                                    <th>Tarix</th>
                                    <th>Məbləğ</th>
                                    <th>Status</th>
                                    <th>Əməliyyatlar</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentOrders)): ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center;">Heç bir sifariş tapılmadı</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recentOrders as $order): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($order['order_number'] ?? '-') ?></td>
                                            <td><?= htmlspecialchars($order['customer_name'] ?? '-') ?></td>
                                            <td><?= formatDate($order['order_date'] ?? '') ?></td>
                                            <td><?= formatMoney($order['total_amount'] ?? 0) ?></td>
                                            <td>
                                                <?php 
                                                    $statusMap = [
                                                        'new' => ['class' => 'badge-info', 'text' => 'Yeni'],
                                                        'processing' => ['class' => 'badge-warning', 'text' => 'Hazırlanır'],
                                                        'completed' => ['class' => 'badge-success', 'text' => 'Hazır'],
                                                        'delivered' => ['class' => 'badge-success', 'text' => 'Təhvil verilib'],
                                                        'cancelled' => ['class' => 'badge-danger', 'text' => 'Ləğv edilib']
                                                    ];
                                                    $status = $statusMap[$order['order_status'] ?? ''] ?? ['class' => 'badge-info', 'text' => 'Bilinmir'];
                                                ?>
                                                <span class="badge <?= $status['class'] ?>"><?= $status['text'] ?></span>
                                            </td>
                                            <td>
                                                <a href="order-details.php?id=<?= $order['id'] ?? 0 ?>" class="btn-action" title="Ətraflı bax">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="order-edit.php?id=<?= $order['id'] ?? 0 ?>" class="btn-action" title="Düzəliş et">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="order-print.php?id=<?= $order['id'] ?? 0 ?>" class="btn-action" title="Çap et">
                                                    <i class="fas fa-print"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Sales Stats -->
                    <div class="widget">
                        <div class="widget-header">
                            <h2 class="widget-title">Satış Statistikası</h2>
                            <div class="period-tabs">
                                <div class="period-tab active" data-period="week">Həftəlik</div>
                                <div class="period-tab" data-period="month">Aylıq</div>
                                <div class="period-tab" data-period="year">İllik</div>
                            </div>
                        </div>
                        
                        <div id="sales-chart" style="height: 300px;"></div>
                    </div>
                </div>

                <div class="side-widgets">
                    <!-- Low Stock Items -->
                    <div class="widget">
                        <div class="widget-header">
                            <h2 class="widget-title">Anbarda Azalan Məhsullar</h2>
                            <a href="warehouse.php?filter=low-stock" class="view-all">Hamısına bax</a>
                        </div>
                        
                        <?php if (empty($lowStockProducts)): ?>
                            <p style="text-align: center; padding: 20px;">Azalan məhsul yoxdur</p>
                        <?php else: ?>
                            <?php foreach ($lowStockProducts as $product): ?>
                                <div class="low-stock">
                                    <div class="stock-indicator">
                                        <?php
                                            $stockValue = $product['stock'] ?? 0;
                                            echo is_numeric($stockValue) ? round($stockValue) : 0;
                                        ?>
                                    </div>
                                    <div class="stock-details">
                                        <div class="stock-name"><?= htmlspecialchars($product['name'] ?? '-') ?></div>
                                        <div class="stock-info">
                                            <?= htmlspecialchars($product['item_type'] ?? '-') ?> - 
                                            <?php if (($product['item_type'] ?? '') === 'glass'): ?>
                                                <?= $product['thickness'] ?? 0 ?>mm <?= htmlspecialchars($product['type'] ?? '-') ?>
                                            <?php else: ?>
                                                <?= htmlspecialchars($product['type'] ?? '-') ?> 
                                                <?= !empty($product['color']) ? '- ' . htmlspecialchars($product['color']) : '' ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Top Customers -->
                    <div class="widget">
                        <div class="widget-header">
                            <h2 class="widget-title">Ən Aktiv Müştərilər</h2>
                            <a href="customers.php?sort=top" class="view-all">Hamısına bax</a>
                        </div>
                        
                        <?php if (empty($topCustomers)): ?>
                            <p style="text-align: center; padding: 20px;">Müştəri məlumatı yoxdur</p>
                        <?php else: ?>
                            <?php foreach ($topCustomers as $customer): ?>
                                <div class="customer-item">
                                    <div class="customer-avatar">
                                        <?= strtoupper(substr($customer['fullname'] ?? 'M', 0, 1)) ?>
                                    </div>
                                    <div class="customer-details">
                                        <div class="customer-name"><?= htmlspecialchars($customer['fullname'] ?? '-') ?></div>
                                        <div class="customer-info">
                                            <?= $customer['order_count'] ?? 0 ?> sifariş
                                        </div>
                                    </div>
                                    <div class="customer-value">
                                        <?= formatMoney($customer['total_spent'] ?? 0) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="app-footer">
        <div>&copy; <?= date('Y') ?> AlumPro.az - Bütün hüquqlar qorunur</div>
    </footer>

    <!-- Chart.js for sales chart -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize chart
            const ctx = document.getElementById('sales-chart').getContext('2d');
            
            // Example data - in real app, this would come from an API
            const salesData = {
                week: {
                    labels: ['Bazar', 'B.ertəsi', 'Ç.axşamı', 'Çərşənbə', 'C.axşamı', 'Cümə', 'Şənbə'],
                    values: [12000, 19000, 13000, 15000, 20000, 26000, 18000]
                },
                month: {
                    labels: ['1 Həftə', '2 Həftə', '3 Həftə', '4 Həftə'],
                    values: [45000, 52000, 49000, 60000]
                },
                year: {
                    labels: ['Yan', 'Fev', 'Mar', 'Apr', 'May', 'İyun', 'İyul', 'Avq', 'Sen', 'Okt', 'Noy', 'Dek'],
                    values: [65000, 59000, 80000, 81000, 56000, 55000, 40000, 70000, 90000, 82000, 79000, 92000]
                }
            };
            
            // Create chart
            const salesChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: salesData.week.labels,
                    datasets: [{
                        label: 'Satış (AZN)',
                        data: salesData.week.values,
                        borderColor: 'rgb(30, 177, 90)',
                        backgroundColor: 'rgba(30, 177, 90, 0.1)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'top',
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return value.toLocaleString() + ' AZN';
                                }
                            }
                        }
                    }
                }
            });
            
            // Period tabs
            document.querySelectorAll('.period-tab').forEach(tab => {
                tab.addEventListener('click', function() {
                    const period = this.dataset.period;
                    
                    // Update active tab
                    document.querySelectorAll('.period-tab').forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Update chart data
                    salesChart.data.labels = salesData[period].labels;
                    salesChart.data.datasets[0].data = salesData[period].values;
                    salesChart.update();
                });
            });

            // Show user menu on click
            const userInfo = document.querySelector('.user-info');
            if (userInfo) {
                userInfo.addEventListener('click', function() {
                    this.classList.toggle('open');
                });
            }
        });
    </script>
</body>
</html>
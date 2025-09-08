<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}

// Check if session has expired
if (isSessionExpired()) {
    session_unset();
    session_destroy();
    header('Location: ../auth/login.php');
    exit;
}

// Check if user has proper access (admin or seller)
$userRole = $_SESSION['role'] ?? '';
$isAdmin = ($userRole === 'admin');
$isSeller = ($userRole === 'seller');

if (!$isAdmin && !$isSeller) {
    // If not admin or seller, redirect to unauthorized page
    header('Location: ../auth/unauthorized.php');
    exit;
}

$userId = $_SESSION['user_id'];
$userName = $_SESSION['fullname'];
$userBranchId = $_SESSION['branch_id'] ?? 0;
$conn = getDBConnection();

// For sellers, only show their own branch
$activeBranchId = 0;
if ($isSeller && $userBranchId > 0) {
    $activeBranchId = $userBranchId;
} else {
    // For admins, get the selected branch or default to the first one
    $activeBranchId = isset($_GET['branch_id']) && is_numeric($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
}

$dateRange = $_GET['date_range'] ?? 'today';

// Date range calculations
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd = date('Y-m-d', strtotime('sunday this week'));
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$yearStart = date('Y-01-01');
$yearEnd = date('Y-12-31');

$startDate = $today;
$endDate = $today;

switch ($dateRange) {
    case 'yesterday':
        $startDate = $yesterday;
        $endDate = $yesterday;
        break;
    case 'week':
        $startDate = $weekStart;
        $endDate = $weekEnd;
        break;
    case 'month':
        $startDate = $monthStart;
        $endDate = $monthEnd;
        break;
    case 'year':
        $startDate = $yearStart;
        $endDate = $yearEnd;
        break;
    case 'custom':
        $startDate = $_GET['start_date'] ?? $today;
        $endDate = $_GET['end_date'] ?? $today;
        break;
    default: // today
        $startDate = $today;
        $endDate = $today;
}

// Get branches based on user role
$branches = [];

if ($isAdmin) {
    // Admins can see all branches
    $sql = "SELECT * FROM branches ORDER BY name ASC";
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $branches[] = $row;
            
            // Set first branch as active if none selected
            if ($activeBranchId === 0 && count($branches) === 1) {
                $activeBranchId = $row['id'];
            }
        }
    }
} else {
    // Sellers can only see their assigned branch
    $sql = "SELECT * FROM branches WHERE id = ? ORDER BY name ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $userBranchId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $branches[] = $row;
            $activeBranchId = $row['id']; // Force the seller's branch to be active
        }
    }
}

// Branch statistics
$branchStats = [];
$activeBranch = null;

if ($activeBranchId > 0) {
    // Get active branch details
    foreach ($branches as $branch) {
        if ($branch['id'] == $activeBranchId) {
            $activeBranch = $branch;
            break;
        }
    }
    
    // General branch statistics
    $sql = "SELECT 
                COUNT(DISTINCT o.id) as total_orders,
                SUM(o.total_amount) as total_revenue,
                COUNT(DISTINCT o.customer_id) as customer_count,
                AVG(o.total_amount) as average_order_value
            FROM orders o
            WHERE o.branch_id = ? 
            AND DATE(o.order_date) BETWEEN ? AND ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $activeBranchId, $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if ($result) {
        $branchStats['total_orders'] = $result['total_orders'] ?? 0;
        $branchStats['total_revenue'] = $result['total_revenue'] ?? 0;
        $branchStats['customer_count'] = $result['customer_count'] ?? 0;
        $branchStats['average_order_value'] = $result['average_order_value'] ?? 0;
    }
    
    // Orders by status
    $sql = "SELECT 
                order_status,
                COUNT(*) as count,
                SUM(total_amount) as total
            FROM orders
            WHERE branch_id = ?
            AND DATE(order_date) BETWEEN ? AND ?
            GROUP BY order_status";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $activeBranchId, $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $branchStats['orders_by_status'] = [];
    
    while ($row = $result->fetch_assoc()) {
        $branchStats['orders_by_status'][$row['order_status']] = [
            'count' => $row['count'],
            'total' => $row['total']
        ];
    }
    
    // Top sellers
    $sql = "SELECT 
                u.id,
                u.fullname,
                COUNT(o.id) as order_count,
                SUM(o.total_amount) as total_sales
            FROM orders o
            JOIN users u ON o.seller_id = u.id
            WHERE o.branch_id = ?
            AND DATE(o.order_date) BETWEEN ? AND ?
            GROUP BY u.id
            ORDER BY total_sales DESC
            LIMIT 5";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $activeBranchId, $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $branchStats['top_sellers'] = [];
    
    while ($row = $result->fetch_assoc()) {
        $branchStats['top_sellers'][] = $row;
    }
    
    // Recent orders
    $sql = "SELECT 
                o.id,
                o.order_number,
                o.order_date,
                o.total_amount,
                o.order_status,
                c.fullname as customer_name,
                u.fullname as seller_name
            FROM orders o
            JOIN customers c ON o.customer_id = c.id
            JOIN users u ON o.seller_id = u.id
            WHERE o.branch_id = ?
            ORDER BY o.order_date DESC
            LIMIT 10";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $activeBranchId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $branchStats['recent_orders'] = [];
    
    while ($row = $result->fetch_assoc()) {
        $branchStats['recent_orders'][] = $row;
    }
    
    // Daily sales for last 7 days
    $sql = "SELECT 
                DATE(order_date) as sale_date,
                COUNT(*) as order_count,
                SUM(total_amount) as daily_total
            FROM orders
            WHERE branch_id = ?
            AND order_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 7 DAY)
            GROUP BY DATE(order_date)
            ORDER BY sale_date DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $activeBranchId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $branchStats['daily_sales'] = [];
    
    while ($row = $result->fetch_assoc()) {
        $branchStats['daily_sales'][] = $row;
    }
    
    // Active sellers
    $sql = "SELECT 
                u.id,
                u.fullname,
                u.email,
                u.last_login,
                COUNT(o.id) as today_orders,
                SUM(o.total_amount) as today_sales
            FROM users u
            LEFT JOIN orders o ON u.id = o.seller_id AND DATE(o.order_date) = CURRENT_DATE()
            WHERE u.branch_id = ? AND u.role = 'seller' AND u.status = 'active'
            GROUP BY u.id
            ORDER BY today_sales DESC, u.fullname ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $activeBranchId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $branchStats['active_sellers'] = [];
    
    while ($row = $result->fetch_assoc()) {
        $branchStats['active_sellers'][] = $row;
    }
}

// Process branch operations (only for admins)
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    // Add new branch
    if (isset($_POST['add_branch'])) {
        $name = trim($_POST['name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $manager_id = (int)($_POST['manager_id'] ?? 0);
        
        if (empty($name)) {
            $message = 'Filial adı boş ola bilməz';
            $messageType = 'error';
        } else {
            $sql = "INSERT INTO branches (name, address, phone, manager_id) VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssi", $name, $address, $phone, $manager_id);
            
            if ($stmt->execute()) {
                $message = 'Filial uğurla əlavə edildi';
                $messageType = 'success';
                logActivity($userId, 'add_branch', "Added new branch: $name");
            } else {
                $message = 'Filial əlavə edilərkən xəta baş verdi';
                $messageType = 'error';
            }
        }
    }
    
    // Edit branch
    if (isset($_POST['edit_branch'])) {
        $branch_id = (int)($_POST['branch_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $manager_id = (int)($_POST['manager_id'] ?? 0);
        $status = $_POST['status'] ?? 'active';
        
        if (empty($name)) {
            $message = 'Filial adı boş ola bilməz';
            $messageType = 'error';
        } elseif ($branch_id <= 0) {
            $message = 'Yanlış filial ID';
            $messageType = 'error';
        } else {
            $sql = "UPDATE branches SET name = ?, address = ?, phone = ?, manager_id = ?, status = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssisi", $name, $address, $phone, $manager_id, $status, $branch_id);
            
            if ($stmt->execute()) {
                $message = 'Filial məlumatları uğurla yeniləndi';
                $messageType = 'success';
                logActivity($userId, 'update_branch', "Updated branch ID: $branch_id");
            } else {
                $message = 'Filial məlumatları yenilənərkən xəta baş verdi';
                $messageType = 'error';
            }
        }
    }
}

// Get potential branch managers (sellers and admins) - for admin use only
$potentialManagers = [];
if ($isAdmin) {
    $sql = "SELECT id, fullname, role FROM users WHERE role IN ('admin', 'seller') AND status = 'active' ORDER BY fullname ASC";
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $potentialManagers[] = $row;
        }
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
    <title>Filiallar | AlumPro</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .branch-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .branch-item {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 15px;
            width: 250px;
            transition: all 0.3s;
            border: 2px solid transparent;
            cursor: pointer;
        }
        
        .branch-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.1);
        }
        
        .branch-item.active {
            border-color: var(--primary-color);
        }
        
        .branch-name {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .branch-address {
            font-size: 0.9rem;
            color: #6b7280;
            margin-bottom: 5px;
        }
        
        .branch-phone {
            font-size: 0.9rem;
            color: #6b7280;
        }
        
        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
            margin-left: 5px;
        }
        
        .status-active {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-inactive {
            background: #fee2e2;
            color: #b91c1c;
        }
        
        .date-filter {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .date-filter-item {
            background: white;
            border-radius: var(--border-radius);
            padding: 8px 15px;
            font-size: 0.9rem;
            border: 1px solid #e5e7eb;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .date-filter-item:hover {
            border-color: #d1d5db;
        }
        
        .date-filter-item.active {
            background: var(--primary-gradient);
            color: white;
            border-color: transparent;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 20px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
            color: var(--primary-color);
        }
        
        .stat-label {
            color: #6b7280;
            font-size: 0.9rem;
        }
        
        .status-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 15px;
        }
        
        .status-pill {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .status-pill-info { background-color: #e0f2fe; color: #0369a1; }
        .status-pill-success { background-color: #d1fae5; color: #065f46; }
        .status-pill-warning { background-color: #fef3c7; color: #92400e; }
        .status-pill-danger { background-color: #fee2e2; color: #b91c1c; }
        
        .chart-container {
            height: 300px;
            margin-bottom: 20px;
        }
        
        .dual-card {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .seller-list {
            margin-top: 15px;
        }
        
        .seller-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .seller-item:last-child {
            border-bottom: none;
        }
        
        .seller-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 16px;
        }
        
        .seller-info {
            flex: 1;
        }
        
        .seller-name {
            font-weight: 500;
            margin-bottom: 2px;
        }
        
        .seller-email {
            font-size: 0.8rem;
            color: #6b7280;
        }
        
        .seller-stats {
            text-align: right;
        }
        
        .seller-orders {
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .seller-revenue {
            font-size: 0.8rem;
            color: #6b7280;
        }
        
        .recent-order {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .recent-order:last-child {
            border-bottom: none;
        }
        
        .order-info {
            flex: 1;
        }
        
        .order-number {
            font-weight: 500;
            margin-bottom: 2px;
        }
        
        .order-meta {
            display: flex;
            gap: 10px;
            font-size: 0.8rem;
            color: #6b7280;
        }
        
        .order-amount {
            font-weight: 600;
            text-align: right;
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 1050;
        }
        
        .modal.show {
            display: block;
        }
        
        .modal-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1040;
        }
        
        .modal-dialog {
            position: relative;
            width: auto;
            margin: 1.75rem auto;
            max-width: 500px;
            z-index: 1050;
        }
        
        .modal-content {
            position: relative;
            display: flex;
            flex-direction: column;
            background-color: #fff;
            background-clip: padding-box;
            border: 1px solid rgba(0, 0, 0, 0.2);
            border-radius: 0.3rem;
            outline: 0;
        }
        
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem;
            border-bottom: 1px solid #e9ecef;
            border-top-left-radius: 0.3rem;
            border-top-right-radius: 0.3rem;
            background: linear-gradient(135deg, #1eb15a 0%, #1e5eb1 100%);
            color: white;
        }
        
        .modal-title {
            margin: 0;
            line-height: 1.5;
            font-size: 1.25rem;
            font-weight: 500;
        }
        
        .modal-header .close {
            padding: 1rem;
            margin: -1rem -1rem -1rem auto;
            background-color: transparent;
            border: 0;
            font-size: 1.5rem;
            color: white;
            cursor: pointer;
        }
        
        .modal-body {
            position: relative;
            flex: 1 1 auto;
            padding: 1rem;
        }
        
        .modal-footer {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding: 1rem;
            border-top: 1px solid #e9ecef;
        }
        
        .modal-footer .btn + .btn {
            margin-left: 0.5rem;
        }
        
        @media (max-width: 576px) {
            .modal-dialog {
                margin: 0.5rem;
                max-width: calc(100% - 1rem);
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
                <a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <?php if ($isAdmin): ?>
                <a href="users.php"><i class="fas fa-users"></i> İstifadəçilər</a>
                <?php endif; ?>
                <a href="customers.php"><i class="fas fa-user-tie"></i> Müştərilər</a>
                <a href="inventory.php"><i class="fas fa-warehouse"></i> Anbar</a>
                <a href="orders.php"><i class="fas fa-shopping-cart"></i> Sifarişlər</a>
                <a href="branches.php" class="active"><i class="fas fa-building"></i> Filiallar</a>
                <a href="reports.php"><i class="fas fa-chart-bar"></i> Hesabatlar</a>
                <?php if ($isAdmin): ?>
                <a href="settings.php"><i class="fas fa-cog"></i> Tənzimləmələr</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="header-right">
            <div class="user-info">
                <span><?= htmlspecialchars($userName) ?> <i class="fas fa-angle-down"></i></span>
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
                <h1><i class="fas fa-building"></i> Filiallar</h1>
                <div class="breadcrumb">
                    <a href="index.php">Dashboard</a> / 
                    <span>Filiallar</span>
                </div>
            </div>
            
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $messageType === 'error' ? 'danger' : 'success' ?>">
                    <i class="fas fa-<?= $messageType === 'error' ? 'exclamation-circle' : 'check-circle' ?>"></i>
                    <?= $message ?>
                </div>
            <?php endif; ?>
            
            <?php if ($isAdmin): ?>
            <div class="page-actions mb-4">
                <button type="button" class="btn btn-primary" onclick="openAddBranchModal()">
                    <i class="fas fa-plus"></i> Yeni Filial
                </button>
            </div>
            <?php endif; ?>
            
            <!-- Branch List - Only show to admins with multiple branches -->
            <?php if ($isAdmin && count($branches) > 1): ?>
                <div class="branch-list">
                    <?php foreach ($branches as $branch): ?>
                        <a href="?branch_id=<?= $branch['id'] ?>&date_range=<?= $dateRange ?>" class="branch-item <?= $branch['id'] == $activeBranchId ? 'active' : '' ?>">
                            <div class="branch-name">
                                <?= htmlspecialchars($branch['name']) ?>
                                <span class="status-badge status-<?= $branch['status'] ?>">
                                    <?= $branch['status'] === 'active' ? 'Aktiv' : 'Deaktiv' ?>
                                </span>
                            </div>
                            <div class="branch-address"><?= htmlspecialchars($branch['address']) ?></div>
                            <div class="branch-phone"><?= htmlspecialchars($branch['phone']) ?></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <?php if (empty($branches)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> 
                    <?php if ($isAdmin): ?>
                        Sistemdə qeydə alınmış filial yoxdur. Yeni filial əlavə edin.
                    <?php else: ?>
                        Sizə təyin edilmiş filial tapılmadı. Zəhmət olmasa, administratorla əlaqə saxlayın.
                    <?php endif; ?>
                </div>
            <?php elseif ($activeBranch): ?>
                <!-- Date Filter -->
                <form action="" method="get" id="dateFilterForm">
                    <input type="hidden" name="branch_id" value="<?= $activeBranchId ?>">
                    <input type="hidden" name="date_range" id="date_range_input" value="<?= $dateRange ?>">
                    <input type="hidden" name="start_date" id="start_date_input" value="<?= $startDate ?>">
                    <input type="hidden" name="end_date" id="end_date_input" value="<?= $endDate ?>">
                    
                    <div class="date-filter">
                        <div class="date-filter-item <?= $dateRange === 'today' ? 'active' : '' ?>" data-range="today">Bugün</div>
                        <div class="date-filter-item <?= $dateRange === 'yesterday' ? 'active' : '' ?>" data-range="yesterday">Dünən</div>
                        <div class="date-filter-item <?= $dateRange === 'week' ? 'active' : '' ?>" data-range="week">Bu həftə</div>
                        <div class="date-filter-item <?= $dateRange === 'month' ? 'active' : '' ?>" data-range="month">Bu ay</div>
                        <div class="date-filter-item <?= $dateRange === 'year' ? 'active' : '' ?>" data-range="year">Bu il</div>
                        <div class="date-filter-item <?= $dateRange === 'custom' ? 'active' : '' ?>" id="custom-date-range">
                            Tarix aralığı
                        </div>
                        
                        <?php if ($dateRange === 'custom'): ?>
                            <div class="date-range-inputs">
                                <input type="date" class="form-control" id="start_date" value="<?= $startDate ?>" onchange="updateCustomDateRange()">
                                <span class="mx-2">-</span>
                                <input type="date" class="form-control" id="end_date" value="<?= $endDate ?>" onchange="updateCustomDateRange()">
                            </div>
                        <?php endif; ?>
                    </div>
                </form>
                
                <!-- Branch Statistics -->
                <div class="card mb-4">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h2 class="card-title">
                                <i class="fas fa-chart-line"></i> <?= htmlspecialchars($activeBranch['name']) ?> Filialı Statistikası
                            </h2>
                            
                            <?php if ($isAdmin): ?>
                            <button type="button" class="btn btn-sm btn-outline" onclick="editBranch(<?= $activeBranch['id'] ?>)">
                                <i class="fas fa-edit"></i> Filiala düzəliş et
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Summary Statistics -->
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-value"><?= $branchStats['total_orders'] ?? 0 ?></div>
                                <div class="stat-label">Sifariş sayı</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value"><?= formatMoney($branchStats['total_revenue'] ?? 0, '') ?></div>
                                <div class="stat-label">Ümumi satış (₼)</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value"><?= $branchStats['customer_count'] ?? 0 ?></div>
                                <div class="stat-label">Müştəri sayı</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value"><?= formatMoney($branchStats['average_order_value'] ?? 0, '') ?></div>
                                <div class="stat-label">Orta sifariş məbləği (₼)</div>
                            </div>
                        </div>
                        
                        <!-- Orders by Status -->
                        <div class="status-pills">
                            <?php 
                            foreach ($statusConfig as $status => $config) {
                                $count = $branchStats['orders_by_status'][$status]['count'] ?? 0;
                                $total = $branchStats['orders_by_status'][$status]['total'] ?? 0;
                                
                                if ($count > 0):
                            ?>
                                <div class="status-pill status-pill-<?= $config['color'] ?>">
                                    <i class="fas fa-circle"></i>
                                    <span><?= $config['text'] ?>: <?= $count ?> (<?= formatMoney($total, '') ?> ₼)</span>
                                </div>
                            <?php 
                                endif;
                            } 
                            ?>
                        </div>
                        
                        <!-- Sales Chart -->
                        <div class="chart-container">
                            <canvas id="salesChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- Dual Card Layout -->
                <div class="dual-card">
                    <!-- Top Sellers -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-medal"></i> Ən Yaxşı Satıcılar</h2>
                        </div>
                        <div class="card-body">
                            <?php if (empty($branchStats['top_sellers'])): ?>
                                <div class="text-center p-4">
                                    <i class="fas fa-info-circle"></i> Seçilmiş dövr üçün satış məlumatları yoxdur.
                                </div>
                            <?php else: ?>
                                <div class="seller-list">
                                    <?php foreach ($branchStats['top_sellers'] as $seller): ?>
                                        <div class="seller-item">
                                            <div class="seller-avatar">
                                                <?= strtoupper(substr($seller['fullname'], 0, 1)) ?>
                                            </div>
                                            <div class="seller-info">
                                                <div class="seller-name"><?= htmlspecialchars($seller['fullname']) ?></div>
                                                <div class="seller-email"><?= $seller['order_count'] ?> sifariş</div>
                                            </div>
                                            <div class="seller-stats">
                                                <div class="seller-orders"><?= formatMoney($seller['total_sales'], '') ?> ₼</div>
                                                <div class="seller-revenue">Ümumi satış</div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Recent Orders -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-clipboard-list"></i> Son Sifarişlər</h2>
                        </div>
                        <div class="card-body">
                            <?php if (empty($branchStats['recent_orders'])): ?>
                                <div class="text-center p-4">
                                    <i class="fas fa-info-circle"></i> Bu filial üçün sifariş tapılmadı.
                                </div>
                            <?php else: ?>
                                <?php foreach ($branchStats['recent_orders'] as $order): 
                                    $statusInfo = $statusConfig[$order['order_status']] ?? ['text' => 'Bilinmir', 'color' => 'info'];
                                ?>
                                    <div class="recent-order">
                                        <div class="order-info">
                                            <div class="order-number">
                                                <a href="order-details.php?id=<?= $order['id'] ?>"><?= htmlspecialchars($order['order_number']) ?></a>
                                                <span class="status-badge status-pill-<?= $statusInfo['color'] ?>"><?= $statusInfo['text'] ?></span>
                                            </div>
                                            <div class="order-meta">
                                                <span><i class="fas fa-user"></i> <?= htmlspecialchars($order['customer_name']) ?></span>
                                                <span><i class="fas fa-calendar"></i> <?= formatDate($order['order_date']) ?></span>
                                            </div>
                                        </div>
                                        <div class="order-amount">
                                            <?= formatMoney($order['total_amount'], '') ?> ₼
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Active Sellers -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h2 class="card-title"><i class="fas fa-user-tie"></i> Aktiv Satıcılar</h2>
                    </div>
                    <div class="card-body">
                        <?php if (empty($branchStats['active_sellers'])): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Bu filialda aktiv satıcı yoxdur.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Satıcı</th>
                                            <th>Son aktivlik</th>
                                            <th>Bugünkü sifarişlər</th>
                                            <th>Bugünkü satış</th>
                                            <th>Əməliyyatlar</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($branchStats['active_sellers'] as $seller): ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="seller-avatar mr-2">
                                                            <?= strtoupper(substr($seller['fullname'], 0, 1)) ?>
                                                        </div>
                                                        <div>
                                                            <div class="font-weight-medium"><?= htmlspecialchars($seller['fullname']) ?></div>
                                                            <div class="text-muted small"><?= htmlspecialchars($seller['email']) ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td><?= $seller['last_login'] ? formatDate($seller['last_login'], 'd.m.Y H:i') : 'Heç vaxt' ?></td>
                                                <td><?= $seller['today_orders'] ?? 0 ?> sifariş</td>
                                                <td><?= formatMoney($seller['today_sales'] ?? 0, '') ?> ₼</td>
                                                <td>
                                                    <a href="user-details.php?id=<?= $seller['id'] ?>" class="btn btn-sm btn-outline">
                                                        <i class="fas fa-eye"></i> Ətraflı
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
    
    <?php if ($isAdmin): ?>
    <!-- Add Branch Modal - Admin Only -->
    <div class="modal" id="addBranchModal">
        <div class="modal-backdrop"></div>
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Yeni Filial Əlavə Et</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <form action="" method="post">
                        <input type="hidden" name="add_branch" value="1">
                        
                        <div class="form-group">
                            <label for="branch_name" class="form-label">Filial adı <span class="text-danger">*</span></label>
                            <input type="text" id="branch_name" name="name" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="branch_address" class="form-label">Ünvan</label>
                            <input type="text" id="branch_address" name="address" class="form-control">
                        </div>
                        
                        <div class="form-group">
                            <label for="branch_phone" class="form-label">Telefon</label>
                            <input type="text" id="branch_phone" name="phone" class="form-control" placeholder="+994 XX XXX XX XX">
                        </div>
                        
                        <div class="form-group">
                            <label for="branch_manager" class="form-label">Filial meneceri</label>
                            <select id="branch_manager" name="manager_id" class="form-control">
                                <option value="0">Seçin...</option>
                                <?php foreach ($potentialManagers as $manager): ?>
                                    <option value="<?= $manager['id'] ?>">
                                        <?= htmlspecialchars($manager['fullname']) ?> (<?= $manager['role'] === 'admin' ? 'Administrator' : 'Satıcı' ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">İmtina</button>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Əlavə et</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Edit Branch Modal - Admin Only -->
    <div class="modal" id="editBranchModal">
        <div class="modal-backdrop"></div>
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Filiala Düzəliş Et</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="edit-branch-content">
                        <!-- Content will be loaded dynamically -->
                        <div class="text-center p-5">
                            <i class="fas fa-spinner fa-spin fa-2x"></i>
                            <p class="mt-2">Yüklənir...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Footer -->
    <footer class="app-footer">
        <div>&copy; <?= date('Y') ?> AlumPro.az - Bütün hüquqlar qorunur</div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // User menu toggle
            const userInfo = document.querySelector('.user-info');
            if (userInfo) {
                userInfo.addEventListener('click', function() {
                    this.classList.toggle('open');
                });
            }
            
            // Modal functionality
            const modals = document.querySelectorAll('.modal');
            const modalBackdrops = document.querySelectorAll('.modal-backdrop');
            const modalCloseButtons = document.querySelectorAll('.modal-close, .close, [data-dismiss="modal"]');
            
            // Close modal with backdrop or close button
            modalBackdrops.forEach(backdrop => {
                backdrop.addEventListener('click', function() {
                    this.closest('.modal').classList.remove('show');
                });
            });
            
            modalCloseButtons.forEach(button => {
                button.addEventListener('click', function() {
                    this.closest('.modal').classList.remove('show');
                });
            });
            
            // Date filter functionality
            const dateFilterItems = document.querySelectorAll('.date-filter-item');
            const dateRangeInput = document.getElementById('date_range_input');
            const dateFilterForm = document.getElementById('dateFilterForm');
            
            if (dateFilterItems.length > 0 && dateRangeInput && dateFilterForm) {
                dateFilterItems.forEach(item => {
                    item.addEventListener('click', function() {
                        const range = this.getAttribute('data-range');
                        
                        if (range === 'custom') {
                            // Show custom date inputs if not already visible
                            if (!document.querySelector('.date-range-inputs')) {
                                const inputsDiv = document.createElement('div');
                                inputsDiv.className = 'date-range-inputs';
                                inputsDiv.innerHTML = `
                                    <input type="date" class="form-control" id="start_date" value="<?= $startDate ?>" onchange="updateCustomDateRange()">
                                    <span class="mx-2">-</span>
                                    <input type="date" class="form-control" id="end_date" value="<?= $endDate ?>" onchange="updateCustomDateRange()">
                                `;
                                
                                this.after(inputsDiv);
                            }
                        } else {
                            // Remove custom date inputs if visible
                            const inputsDiv = document.querySelector('.date-range-inputs');
                            if (inputsDiv) {
                                inputsDiv.remove();
                            }
                            
                            // Set the range and submit form
                            dateRangeInput.value = range;
                            dateFilterForm.submit();
                        }
                        
                        // Update active state
                        dateFilterItems.forEach(i => i.classList.remove('active'));
                        this.classList.add('active');
                    });
                });
            }
            
            // Initialize sales chart if data exists
            if (document.getElementById('salesChart')) {
                const salesData = <?= json_encode(array_reverse($branchStats['daily_sales'] ?? [])) ?>;
                
                if (salesData.length > 0) {
                    const dates = salesData.map(item => {
                        const date = new Date(item.sale_date);
                        return date.toLocaleDateString('az-AZ', { month: 'short', day: 'numeric' });
                    });
                    
                    const amounts = salesData.map(item => parseFloat(item.daily_total));
                    const counts = salesData.map(item => parseInt(item.order_count));
                    
                    const ctx = document.getElementById('salesChart').getContext('2d');
                    const chart = new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: dates,
                            datasets: [
                                {
                                    label: 'Satış məbləği (₼)',
                                    data: amounts,
                                    backgroundColor: 'rgba(46, 177, 90, 0.6)',
                                    borderColor: 'rgba(46, 177, 90, 1)',
                                    borderWidth: 1,
                                    yAxisID: 'y'
                                },
                                {
                                    label: 'Sifariş sayı',
                                    data: counts,
                                    type: 'line',
                                    backgroundColor: 'rgba(30, 94, 177, 0.1)',
                                    borderColor: 'rgba(30, 94, 177, 1)',
                                    borderWidth: 2,
                                    pointBackgroundColor: 'rgba(30, 94, 177, 1)',
                                    yAxisID: 'y1'
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    type: 'linear',
                                    position: 'left',
                                    title: {
                                        display: true,
                                        text: 'Satış məbləği (₼)'
                                    }
                                },
                                y1: {
                                    beginAtZero: true,
                                    type: 'linear',
                                    position: 'right',
                                    grid: {
                                        drawOnChartArea: false
                                    },
                                    title: {
                                        display: true,
                                        text: 'Sifariş sayı'
                                    }
                                }
                            }
                        }
                    });
                }
            }
        });
        
        function openAddBranchModal() {
            document.getElementById('addBranchModal').classList.add('show');
        }
        
        function editBranch(branchId) {
            // Set loading state
            document.getElementById('edit-branch-content').innerHTML = `
                <div class="text-center p-5">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                    <p class="mt-2">Yüklənir...</p>
                </div>
            `;
            
            // Show modal
            document.getElementById('editBranchModal').classList.add('show');
            
            // Fetch branch details and populate form
            // This would be an AJAX call in a real app
            // For now, let's simulate with setTimeout
            setTimeout(() => {
                // Get branch data from the page (this is just for the demo - in a real app, you'd fetch from the server)
                const branch = <?= json_encode($activeBranch ?? []) ?>;
                
                if (branch) {
                    const formHtml = `
                    <form action="" method="post">
                        <input type="hidden" name="edit_branch" value="1">
                        <input type="hidden" name="branch_id" value="${branch.id}">
                        
                        <div class="form-group">
                            <label for="edit_branch_name" class="form-label">Filial adı <span class="text-danger">*</span></label>
                            <input type="text" id="edit_branch_name" name="name" class="form-control" value="${escapeHtml(branch.name)}" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_branch_address" class="form-label">Ünvan</label>
                            <input type="text" id="edit_branch_address" name="address" class="form-control" value="${escapeHtml(branch.address || '')}">
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_branch_phone" class="form-label">Telefon</label>
                            <input type="text" id="edit_branch_phone" name="phone" class="form-control" value="${escapeHtml(branch.phone || '')}" placeholder="+994 XX XXX XX XX">
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_branch_manager" class="form-label">Filial meneceri</label>
                            <select id="edit_branch_manager" name="manager_id" class="form-control">
                                <option value="0">Seçin...</option>
                                <?php foreach ($potentialManagers as $manager): ?>
                                    <option value="<?= $manager['id'] ?>" ${branch.manager_id == <?= $manager['id'] ?> ? 'selected' : ''}>
                                        <?= htmlspecialchars($manager['fullname']) ?> (<?= $manager['role'] === 'admin' ? 'Administrator' : 'Satıcı' ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_branch_status" class="form-label">Status</label>
                            <select id="edit_branch_status" name="status" class="form-control">
                                <option value="active" ${branch.status === 'active' ? 'selected' : ''}>Aktiv</option>
                                <option value="inactive" ${branch.status === 'inactive' ? 'selected' : ''}>Deaktiv</option>
                            </select>
                        </div>
                        
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">İmtina</button>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Yadda saxla</button>
                        </div>
                    </form>
                    `;
                    
                    document.getElementById('edit-branch-content').innerHTML = formHtml;
                    
                    // Set up close button event handlers for the newly added content
                    document.querySelectorAll('#editBranchModal [data-dismiss="modal"]').forEach(button => {
                        button.addEventListener('click', function() {
                            document.getElementById('editBranchModal').classList.remove('show');
                        });
                    });
                } else {
                    document.getElementById('edit-branch-content').innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle"></i> Filial məlumatları tapılmadı.
                        </div>
                    `;
                }
            }, 500);
        }
        
        function updateCustomDateRange() {
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            
            if (startDate && endDate) {
                document.getElementById('date_range_input').value = 'custom';
                document.getElementById('start_date_input').value = startDate;
                document.getElementById('end_date_input').value = endDate;
                document.getElementById('dateFilterForm').submit();
            }
        }
        
        // Helper function to escape HTML
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>
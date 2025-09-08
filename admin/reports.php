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

// Get report parameters
$reportType = $_GET['type'] ?? 'sales';
$period = $_GET['period'] ?? 'monthly';
$dateStart = $_GET['date_start'] ?? date('Y-m-01'); // First day of current month
$dateEnd = $_GET['date_end'] ?? date('Y-m-d'); // Today
$branchId = $_GET['branch_id'] ?? 'all';
$sellerId = $_GET['seller_id'] ?? 'all';

// Set date ranges based on period
if ($period === 'daily') {
    $dateStart = date('Y-m-d');
    $dateEnd = date('Y-m-d');
} elseif ($period === 'weekly') {
    $dateStart = date('Y-m-d', strtotime('monday this week'));
    $dateEnd = date('Y-m-d', strtotime('sunday this week'));
} elseif ($period === 'monthly') {
    $dateStart = date('Y-m-01');
    $dateEnd = date('Y-m-t');
} elseif ($period === 'yearly') {
    $dateStart = date('Y-01-01');
    $dateEnd = date('Y-12-31');
} elseif ($period === 'last7') {
    $dateStart = date('Y-m-d', strtotime('-6 days'));
    $dateEnd = date('Y-m-d');
} elseif ($period === 'last30') {
    $dateStart = date('Y-m-d', strtotime('-29 days'));
    $dateEnd = date('Y-m-d');
} elseif ($period === 'last90') {
    $dateStart = date('Y-m-d', strtotime('-89 days'));
    $dateEnd = date('Y-m-d');
} elseif ($period === 'last365') {
    $dateStart = date('Y-m-d', strtotime('-364 days'));
    $dateEnd = date('Y-m-d');
}

// Get report data
$conn = getDBConnection();
$reportData = [];
$chartLabels = [];
$chartData = [];

// Get branches for filter
$sql = "SELECT id, name FROM branches WHERE status = 'active' ORDER BY name ASC";
$result = $conn->query($sql);
$branches = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $branches[] = $row;
    }
}

// Get sellers for filter
$sql = "SELECT u.id, u.fullname 
        FROM users u
        WHERE u.role = 'seller' AND u.status = 'active' 
        ORDER BY u.fullname ASC";
$result = $conn->query($sql);
$sellers = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $sellers[] = $row;
    }
}

// Build where clause based on filters
$whereClause = [];
$params = [];
$types = "";

$whereClause[] = "o.order_date BETWEEN ? AND ?";
$params[] = $dateStart . " 00:00:00";
$params[] = $dateEnd . " 23:59:59";
$types .= "ss";

if ($branchId !== 'all') {
    $whereClause[] = "o.branch_id = ?";
    $params[] = $branchId;
    $types .= "i";
}

if ($sellerId !== 'all') {
    $whereClause[] = "o.seller_id = ?";
    $params[] = $sellerId;
    $types .= "i";
}

$whereClauseStr = implode(" AND ", $whereClause);

// Get report data based on report type
if ($reportType === 'sales') {
    if ($period === 'daily') {
        // Daily sales by hour
        $sql = "SELECT 
                    HOUR(o.order_date) as time_period,
                    COUNT(*) as order_count,
                    SUM(o.total_amount) as total_amount,
                    SUM(o.advance_payment) as advance_payment,
                    SUM(o.remaining_amount) as remaining_amount
                FROM orders o
                WHERE $whereClauseStr
                GROUP BY HOUR(o.order_date)
                ORDER BY HOUR(o.order_date)";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $hour = sprintf("%02d:00", $row['time_period']);
            $reportData[] = [
                'period' => $hour,
                'order_count' => $row['order_count'],
                'total_amount' => $row['total_amount'],
                'advance_payment' => $row['advance_payment'],
                'remaining_amount' => $row['remaining_amount']
            ];
            
            $chartLabels[] = $hour;
            $chartData[] = $row['total_amount'];
        }
    } elseif ($period === 'weekly' || $period === 'last7') {
        // Weekly sales by day
        $sql = "SELECT 
                    DATE(o.order_date) as time_period,
                    DAYNAME(o.order_date) as day_name,
                    COUNT(*) as order_count,
                    SUM(o.total_amount) as total_amount,
                    SUM(o.advance_payment) as advance_payment,
                    SUM(o.remaining_amount) as remaining_amount
                FROM orders o
                WHERE $whereClauseStr
                GROUP BY DATE(o.order_date)
                ORDER BY DATE(o.order_date)";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $date = date('d.m.Y', strtotime($row['time_period']));
            $dayName = translateDayName($row['day_name']);
            
            $reportData[] = [
                'period' => $date . ' (' . $dayName . ')',
                'order_count' => $row['order_count'],
                'total_amount' => $row['total_amount'],
                'advance_payment' => $row['advance_payment'],
                'remaining_amount' => $row['remaining_amount']
            ];
            
            $chartLabels[] = $dayName;
            $chartData[] = $row['total_amount'];
        }
    } elseif ($period === 'monthly' || $period === 'last30') {
        // Monthly sales by day
        $sql = "SELECT 
                    DATE(o.order_date) as time_period,
                    COUNT(*) as order_count,
                    SUM(o.total_amount) as total_amount,
                    SUM(o.advance_payment) as advance_payment,
                    SUM(o.remaining_amount) as remaining_amount
                FROM orders o
                WHERE $whereClauseStr
                GROUP BY DATE(o.order_date)
                ORDER BY DATE(o.order_date)";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $date = date('d.m.Y', strtotime($row['time_period']));
            
            $reportData[] = [
                'period' => $date,
                'order_count' => $row['order_count'],
                'total_amount' => $row['total_amount'],
                'advance_payment' => $row['advance_payment'],
                'remaining_amount' => $row['remaining_amount']
            ];
            
            $chartLabels[] = date('d.m', strtotime($row['time_period']));
            $chartData[] = $row['total_amount'];
        }
    } elseif ($period === 'yearly' || $period === 'last365' || $period === 'last90') {
        // Yearly sales by month
        $sql = "SELECT 
                    YEAR(o.order_date) as year,
                    MONTH(o.order_date) as month,
                    COUNT(*) as order_count,
                    SUM(o.total_amount) as total_amount,
                    SUM(o.advance_payment) as advance_payment,
                    SUM(o.remaining_amount) as remaining_amount
                FROM orders o
                WHERE $whereClauseStr
                GROUP BY YEAR(o.order_date), MONTH(o.order_date)
                ORDER BY YEAR(o.order_date), MONTH(o.order_date)";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $monthName = translateMonthName(date('F', mktime(0, 0, 0, $row['month'], 10)));
            
            $reportData[] = [
                'period' => $monthName . ' ' . $row['year'],
                'order_count' => $row['order_count'],
                'total_amount' => $row['total_amount'],
                'advance_payment' => $row['advance_payment'],
                'remaining_amount' => $row['remaining_amount']
            ];
            
            $chartLabels[] = $monthName;
            $chartData[] = $row['total_amount'];
        }
    } else {
        // Custom date range - group by day
        $sql = "SELECT 
                    DATE(o.order_date) as time_period,
                    COUNT(*) as order_count,
                    SUM(o.total_amount) as total_amount,
                    SUM(o.advance_payment) as advance_payment,
                    SUM(o.remaining_amount) as remaining_amount
                FROM orders o
                WHERE $whereClauseStr
                GROUP BY DATE(o.order_date)
                ORDER BY DATE(o.order_date)";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $date = date('d.m.Y', strtotime($row['time_period']));
            
            $reportData[] = [
                'period' => $date,
                'order_count' => $row['order_count'],
                'total_amount' => $row['total_amount'],
                'advance_payment' => $row['advance_payment'],
                'remaining_amount' => $row['remaining_amount']
            ];
            
            $chartLabels[] = date('d.m', strtotime($row['time_period']));
            $chartData[] = $row['total_amount'];
        }
    }
} elseif ($reportType === 'sellers') {
    // Sales by seller
    $sql = "SELECT 
                u.id as seller_id,
                u.fullname as seller_name,
                COUNT(*) as order_count,
                SUM(o.total_amount) as total_amount,
                SUM(o.advance_payment) as advance_payment,
                SUM(o.remaining_amount) as remaining_amount
            FROM orders o
            JOIN users u ON o.seller_id = u.id
            WHERE $whereClauseStr
            GROUP BY o.seller_id
            ORDER BY SUM(o.total_amount) DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $reportData[] = [
            'period' => $row['seller_name'],
            'order_count' => $row['order_count'],
            'total_amount' => $row['total_amount'],
            'advance_payment' => $row['advance_payment'],
            'remaining_amount' => $row['remaining_amount']
        ];
        
        $chartLabels[] = $row['seller_name'];
        $chartData[] = $row['total_amount'];
    }
} elseif ($reportType === 'branches') {
    // Sales by branch
    $sql = "SELECT 
                b.id as branch_id,
                b.name as branch_name,
                COUNT(*) as order_count,
                SUM(o.total_amount) as total_amount,
                SUM(o.advance_payment) as advance_payment,
                SUM(o.remaining_amount) as remaining_amount
            FROM orders o
            JOIN branches b ON o.branch_id = b.id
            WHERE $whereClauseStr
            GROUP BY o.branch_id
            ORDER BY SUM(o.total_amount) DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $reportData[] = [
            'period' => $row['branch_name'],
            'order_count' => $row['order_count'],
            'total_amount' => $row['total_amount'],
            'advance_payment' => $row['advance_payment'],
            'remaining_amount' => $row['remaining_amount']
        ];
        
        $chartLabels[] = $row['branch_name'];
        $chartData[] = $row['total_amount'];
    }
} elseif ($reportType === 'products') {
    // Popular profile types - FIX: Removed total_length and total_weight columns that don't exist
    $sqlProfiles = "SELECT 
                profile_type,
                COUNT(*) as usage_count,
                SUM(quantity) as total_quantity
            FROM order_profiles op
            JOIN orders o ON op.order_id = o.id
            WHERE $whereClauseStr
            GROUP BY profile_type
            ORDER BY COUNT(*) DESC
            LIMIT 10";
    
    $stmt = $conn->prepare($sqlProfiles);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $profilesResult = $stmt->get_result();
    
    $profileData = [];
    $profileLabels = [];
    $profileValues = [];
    
    while ($row = $profilesResult->fetch_assoc()) {
        $profileData[] = [
            'name' => $row['profile_type'],
            'count' => $row['usage_count'],
            'quantity' => $row['total_quantity'],
            'length' => 0, // Set default value since column was removed
            'weight' => 0  // Set default value since column was removed
        ];
        
        $profileLabels[] = $row['profile_type'];
        $profileValues[] = $row['usage_count'];
    }
    
    // Popular glass types
    $sqlGlass = "SELECT 
                glass_type,
                COUNT(*) as usage_count,
                SUM(quantity) as total_quantity,
                SUM(area * quantity) as total_area
            FROM order_glass og
            JOIN orders o ON og.order_id = o.id
            WHERE $whereClauseStr
            GROUP BY glass_type
            ORDER BY COUNT(*) DESC
            LIMIT 10";
    
    $stmt = $conn->prepare($sqlGlass);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $glassResult = $stmt->get_result();
    
    $glassData = [];
    $glassLabels = [];
    $glassValues = [];
    
    while ($row = $glassResult->fetch_assoc()) {
        $glassData[] = [
            'name' => $row['glass_type'],
            'count' => $row['usage_count'],
            'quantity' => $row['total_quantity'],
            'area' => $row['total_area']
        ];
        
        $glassLabels[] = $row['glass_type'];
        $glassValues[] = $row['usage_count'];
    }
}

// Calculate report totals
$totalOrders = 0;
$totalAmount = 0;
$totalAdvance = 0;
$totalRemaining = 0;

foreach ($reportData as $row) {
    $totalOrders += $row['order_count'];
    $totalAmount += $row['total_amount'];
    $totalAdvance += $row['advance_payment'];
    $totalRemaining += $row['remaining_amount'];
}

/**
 * Translate English day name to Azerbaijani
 * @param string $dayName Day name in English
 * @return string Day name in Azerbaijani
 */
function translateDayName($dayName) {
    $days = [
        'Monday' => 'Bazar ertəsi',
        'Tuesday' => 'Çərşənbə axşamı',
        'Wednesday' => 'Çərşənbə',
        'Thursday' => 'Cümə axşamı',
        'Friday' => 'Cümə',
        'Saturday' => 'Şənbə',
        'Sunday' => 'Bazar'
    ];
    
    return $days[$dayName] ?? $dayName;
}

/**
 * Translate English month name to Azerbaijani
 * @param string $monthName Month name in English
 * @return string Month name in Azerbaijani
 */
function translateMonthName($monthName) {
    $months = [
        'January' => 'Yanvar',
        'February' => 'Fevral',
        'March' => 'Mart',
        'April' => 'Aprel',
        'May' => 'May',
        'June' => 'İyun',
        'July' => 'İyul',
        'August' => 'Avqust',
        'September' => 'Sentyabr',
        'October' => 'Oktyabr',
        'November' => 'Noyabr',
        'December' => 'Dekabr'
    ];
    
    return $months[$monthName] ?? $monthName;
}
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hesabatlar | AlumPro Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .report-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            min-width: 150px;
        }
        
        .filter-label {
            font-weight: 500;
            margin-bottom: 5px;
            color: #4b5563;
        }
        
        .filter-select, .filter-input {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: var(--border-radius);
            background-color: white;
        }
        
        .filter-btn {
            margin-top: auto;
            align-self: flex-end;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 15px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #6b7280;
            font-size: 14px;
        }
        
        .chart-container {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .chart-title {
            font-size: 16px;
            font-weight: 500;
        }
        
        .chart-actions {
            display: flex;
            gap: 10px;
        }
        
        .chart-body {
            position: relative;
            height: 300px;
        }
        
        .table-container {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .report-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .report-table th,
        .report-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .report-table th {
            background-color: #f9fafb;
            font-weight: 500;
        }
        
        .report-table tbody tr:last-child td {
            border-bottom: none;
        }
        
        .report-table tbody tr:hover {
            background-color: #f9fafb;
        }
        
        .report-table tfoot {
            font-weight: 700;
        }
        
        .report-table tfoot td {
            border-top: 2px solid #e5e7eb;
        }
        
        .report-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            overflow-x: auto;
            padding-bottom: 5px;
        }
        
        .report-tab {
            padding: 10px 15px;
            border-radius: var(--border-radius);
            background: white;
            box-shadow: var(--card-shadow);
            color: #4b5563;
            text-decoration: none;
            font-weight: 500;
            white-space: nowrap;
        }
        
        .report-tab.active {
            background: var(--primary-gradient);
            color: white;
        }
        
        .report-tab i {
            margin-right: 5px;
        }
        
        .period-tabs {
            display: flex;
            gap: 5px;
            margin-bottom: 20px;
            overflow-x: auto;
            padding-bottom: 5px;
        }
        
        .period-tab {
            padding: 5px 10px;
            border-radius: var(--border-radius);
            background: white;
            box-shadow: var(--card-shadow);
            color: #4b5563;
            text-decoration: none;
            font-size: 14px;
            white-space: nowrap;
        }
        
        .period-tab.active {
            background: var(--primary-color);
            color: white;
        }
        
        .two-column-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(450px, 1fr));
            gap: 20px;
        }
        
        .product-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .product-card-title {
            font-size: 16px;
            font-weight: 500;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .product-card-title i {
            color: var(--primary-color);
        }
        
        .product-list {
            max-height: 300px;
            overflow-y: auto;
        }
        
        .product-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .product-item:last-child {
            border-bottom: none;
        }
        
        .product-name {
            font-weight: 500;
        }
        
        .product-count {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        }
        
        .product-count-value {
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .product-count-details {
            font-size: 12px;
            color: #6b7280;
        }
        
        @media (max-width: 768px) {
            .report-filters {
                flex-direction: column;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .filter-btn {
                align-self: flex-start;
                width: 100%;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .two-column-grid {
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
                <a href="index.php"><i class="fas fa-tachometer-alt"></i> Panel</a>
                <a href="users.php"><i class="fas fa-users"></i> İstifadəçilər</a>
                <a href="orders.php"><i class="fas fa-clipboard-list"></i> Sifarişlər</a>
                <a href="inventory.php"><i class="fas fa-boxes"></i> Anbar</a>
                <a href="branches.php"><i class="fas fa-building"></i> Filiallar</a>
                <a href="reports.php" class="active"><i class="fas fa-chart-bar"></i> Hesabatlar</a>
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
                <h1><i class="fas fa-chart-bar"></i> Hesabatlar</h1>
                <div class="breadcrumb">
                    <a href="index.php">Panel</a> / 
                    <span>Hesabatlar</span>
                </div>
            </div>
            
            <!-- Report Tabs -->
            <div class="report-tabs">
                <a href="?type=sales&period=<?= $period ?>" class="report-tab <?= $reportType === 'sales' ? 'active' : '' ?>">
                    <i class="fas fa-chart-line"></i> Satış Dinamikası
                </a>
                <a href="?type=sellers&period=<?= $period ?>" class="report-tab <?= $reportType === 'sellers' ? 'active' : '' ?>">
                    <i class="fas fa-user-tie"></i> Satıcı Performansı
                </a>
                <a href="?type=branches&period=<?= $period ?>" class="report-tab <?= $reportType === 'branches' ? 'active' : '' ?>">
                    <i class="fas fa-building"></i> Filial Performansı
                </a>
                <a href="?type=products&period=<?= $period ?>" class="report-tab <?= $reportType === 'products' ? 'active' : '' ?>">
                    <i class="fas fa-box"></i> Məhsul Analizi
                </a>
            </div>
            
            <!-- Period Tabs (not shown for product analysis) -->
            <?php if ($reportType !== 'products'): ?>
                <div class="period-tabs">
                    <a href="?type=<?= $reportType ?>&period=daily" class="period-tab <?= $period === 'daily' ? 'active' : '' ?>">
                        Günlük
                    </a>
                    <a href="?type=<?= $reportType ?>&period=weekly" class="period-tab <?= $period === 'weekly' ? 'active' : '' ?>">
                        Həftəlik
                    </a>
                    <a href="?type=<?= $reportType ?>&period=monthly" class="period-tab <?= $period === 'monthly' ? 'active' : '' ?>">
                        Aylıq
                    </a>
                    <a href="?type=<?= $reportType ?>&period=yearly" class="period-tab <?= $period === 'yearly' ? 'active' : '' ?>">
                        İllik
                    </a>
                    <a href="?type=<?= $reportType ?>&period=last7" class="period-tab <?= $period === 'last7' ? 'active' : '' ?>">
                        Son 7 gün
                    </a>
                    <a href="?type=<?= $reportType ?>&period=last30" class="period-tab <?= $period === 'last30' ? 'active' : '' ?>">
                        Son 30 gün
                    </a>
                    <a href="?type=<?= $reportType ?>&period=last90" class="period-tab <?= $period === 'last90' ? 'active' : '' ?>">
                        Son 90 gün
                    </a>
                    <a href="?type=<?= $reportType ?>&period=custom" class="period-tab <?= $period === 'custom' ? 'active' : '' ?>">
                        Xüsusi tarix aralığı
                    </a>
                </div>
            <?php endif; ?>
            
            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-body">
                    <form action="" method="get" id="reportForm">
                        <input type="hidden" name="type" value="<?= $reportType ?>">
                        <?php if ($reportType !== 'products'): ?>
                            <input type="hidden" name="period" value="<?= $period ?>">
                        <?php endif; ?>
                        
                        <div class="report-filters">
                            <?php if ($period === 'custom'): ?>
                                <div class="filter-group">
                                    <label class="filter-label">Başlanğıc tarix:</label>
                                    <input type="date" name="date_start" class="filter-input" value="<?= $dateStart ?>">
                                </div>
                                
                                <div class="filter-group">
                                    <label class="filter-label">Son tarix:</label>
                                    <input type="date" name="date_end" class="filter-input" value="<?= $dateEnd ?>">
                                </div>
                            <?php endif; ?>
                            
                            <div class="filter-group">
                                <label class="filter-label">Filial:</label>
                                <select name="branch_id" class="filter-select">
                                    <option value="all">Bütün Filiallar</option>
                                    <?php foreach ($branches as $branch): ?>
                                        <option value="<?= $branch['id'] ?>" <?= $branchId == $branch['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($branch['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <?php if ($reportType !== 'sellers'): ?>
                                <div class="filter-group">
                                    <label class="filter-label">Satıcı:</label>
                                    <select name="seller_id" class="filter-select">
                                        <option value="all">Bütün Satıcılar</option>
                                        <?php foreach ($sellers as $seller): ?>
                                            <option value="<?= $seller['id'] ?>" <?= $sellerId == $seller['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($seller['fullname']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>
                            
                            <div class="filter-group">
                                <button type="submit" class="btn btn-primary filter-btn">
                                    <i class="fas fa-filter"></i> Tətbiq et
                                </button>
                            </div>
                            
                            <div class="filter-group">
                                <button type="button" class="btn btn-outline filter-btn" id="exportCsvBtn">
                                    <i class="fas fa-file-csv"></i> CSV Yüklə
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <?php if ($reportType !== 'products'): ?>
                <!-- Report Statistics -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value"><?= $totalOrders ?></div>
                        <div class="stat-label">Ümumi Sifariş</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-value"><?= formatMoney($totalAmount, '') ?></div>
                        <div class="stat-label">Ümumi Məbləğ</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-value"><?= formatMoney($totalAdvance, '') ?></div>
                        <div class="stat-label">Avans Ödənişlər</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-value"><?= formatMoney($totalRemaining, '') ?></div>
                        <div class="stat-label">Qalıq Borc</div>
                    </div>
                </div>
                
                <!-- Chart -->
                <div class="chart-container">
                    <div class="chart-header">
                        <div class="chart-title">
                            <?php
                                if ($reportType === 'sales') {
                                    echo 'Satış Dinamikası';
                                } elseif ($reportType === 'sellers') {
                                    echo 'Satıcı Performansı';
                                } elseif ($reportType === 'branches') {
                                    echo 'Filial Performansı';
                                }
                            ?>
                        </div>
                        <div class="chart-actions">
                            <button type="button" class="btn btn-sm btn-outline" id="toggleChartType">
                                <i class="fas fa-chart-bar"></i> Qrafik növünü dəyiş
                            </button>
                        </div>
                    </div>
                    <div class="chart-body">
                        <canvas id="mainChart"></canvas>
                    </div>
                </div>
                
                <!-- Data Table -->
                <div class="table-container">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>
                                    <?php
                                        if ($reportType === 'sales') {
                                            echo 'Dövr';
                                        } elseif ($reportType === 'sellers') {
                                            echo 'Satıcı';
                                        } elseif ($reportType === 'branches') {
                                            echo 'Filial';
                                        }
                                    ?>
                                </th>
                                <th>Sifariş Sayı</th>
                                <th>Ümumi Məbləğ</th>
                                <th>Avans Ödəniş</th>
                                <th>Qalıq Borc</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reportData)): ?>
                                <tr>
                                    <td colspan="5" class="text-center">Bu dövr üçün məlumat tapılmadı</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($reportData as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['period']) ?></td>
                                        <td><?= $row['order_count'] ?></td>
                                        <td><?= formatMoney($row['total_amount']) ?></td>
                                        <td><?= formatMoney($row['advance_payment']) ?></td>
                                        <td><?= formatMoney($row['remaining_amount']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>Cəmi:</td>
                                <td><?= $totalOrders ?></td>
                                <td><?= formatMoney($totalAmount) ?></td>
                                <td><?= formatMoney($totalAdvance) ?></td>
                                <td><?= formatMoney($totalRemaining) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php else: ?>
                <!-- Product Analysis -->
                <div class="two-column-grid">
                    <!-- Profile Products -->
                    <div class="product-card">
                        <div class="product-card-title">
                            <i class="fas fa-box"></i> Ən Çox İstifadə Olunan Profillər
                        </div>
                        
                        <div class="chart-body">
                            <canvas id="profileChart"></canvas>
                        </div>
                        
                        <div class="product-list">
                            <?php if (empty($profileData)): ?>
                                <div class="text-center p-4">
                                    Bu dövr üçün profil məlumatı tapılmadı
                                </div>
                            <?php else: ?>
                                <?php foreach ($profileData as $profile): ?>
                                    <div class="product-item">
                                        <div class="product-name"><?= htmlspecialchars($profile['name']) ?></div>
                                        <div class="product-count">
                                            <div class="product-count-value"><?= $profile['count'] ?> sifariş</div>
                                            <div class="product-count-details">
                                                <?= $profile['quantity'] ?> ədəd
                                                <!-- Removed length and weight data references -->
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Glass Products -->
                    <div class="product-card">
                        <div class="product-card-title">
                            <i class="fas fa-square"></i> Ən Çox İstifadə Olunan Şüşələr
                        </div>
                        
                        <div class="chart-body">
                            <canvas id="glassChart"></canvas>
                        </div>
                        
                        <div class="product-list">
                            <?php if (empty($glassData)): ?>
                                <div class="text-center p-4">
                                    Bu dövr üçün şüşə məlumatı tapılmadı
                                </div>
                            <?php else: ?>
                                <?php foreach ($glassData as $glass): ?>
                                    <div class="product-item">
                                        <div class="product-name"><?= htmlspecialchars($glass['name']) ?></div>
                                        <div class="product-count">
                                            <div class="product-count-value"><?= $glass['count'] ?> sifariş</div>
                                            <div class="product-count-details">
                                                <?= $glass['quantity'] ?> ədəd, <?= round($glass['area'], 2) ?> m²
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
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
            
            <?php if ($reportType !== 'products'): ?>
                // Chart initialization
                const ctx = document.getElementById('mainChart').getContext('2d');
                
                const chartLabels = <?= json_encode($chartLabels) ?>;
                const chartData = <?= json_encode($chartData) ?>;
                
                let chartType = 'bar';
                let chart = createChart(ctx, chartType, chartLabels, chartData);
                
                // Toggle chart type
                document.getElementById('toggleChartType').addEventListener('click', function() {
                    chartType = chartType === 'bar' ? 'line' : 'bar';
                    chart.destroy();
                    chart = createChart(ctx, chartType, chartLabels, chartData);
                });
                
                // Export to CSV
                document.getElementById('exportCsvBtn').addEventListener('click', function() {
                    exportToCSV();
                });
                
                function createChart(ctx, type, labels, data) {
                    return new Chart(ctx, {
                        type: type,
                        data: {
                            labels: labels,
                            datasets: [{
                                label: 'Satış məbləği (AZN)',
                                data: data,
                                backgroundColor: 'rgba(30, 177, 90, 0.5)',
                                borderColor: 'rgba(30, 177, 90, 1)',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: function(value) {
                                            return value + ' ₼';
                                        }
                                    }
                                }
                            },
                            plugins: {
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            return context.dataset.label + ': ' + context.raw + ' ₼';
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
                
                function exportToCSV() {
                    // Prepare data
                    const rows = [
                        ['Dövr', 'Sifariş Sayı', 'Ümumi Məbləğ', 'Avans Ödəniş', 'Qalıq Borc']
                    ];
                    
                    <?php foreach ($reportData as $row): ?>
                        rows.push([
                            '<?= addslashes($row['period']) ?>',
                            <?= $row['order_count'] ?>,
                            <?= $row['total_amount'] ?>,
                            <?= $row['advance_payment'] ?>,
                            <?= $row['remaining_amount'] ?>
                        ]);
                    <?php endforeach; ?>
                    
                    rows.push(['Cəmi:', <?= $totalOrders ?>, <?= $totalAmount ?>, <?= $totalAdvance ?>, <?= $totalRemaining ?>]);
                    
                    // Convert to CSV
                    let csvContent = "data:text/csv;charset=utf-8,";
                    
                    rows.forEach(function(rowArray) {
                        const row = rowArray.join(",");
                        csvContent += row + "\r\n";
                    });
                    
                    // Create download link
                    const encodedUri = encodeURI(csvContent);
                    const link = document.createElement("a");
                    link.setAttribute("href", encodedUri);
                    
                    // Get report type and period for filename
                    const reportTypeName = {
                        'sales': 'Satış_Dinamikası',
                        'sellers': 'Satıcı_Performansı',
                        'branches': 'Filial_Performansı'
                    }['<?= $reportType ?>'];
                    
                    const periodName = {
                        'daily': 'Günlük',
                        'weekly': 'Həftəlik',
                        'monthly': 'Aylıq',
                        'yearly': 'İllik',
                        'last7': 'Son_7_Gün',
                        'last30': 'Son_30_Gün',
                        'last90': 'Son_90_Gün',
                        'last365': 'Son_365_Gün',
                        'custom': '<?= $dateStart ?>_<?= $dateEnd ?>'
                    }['<?= $period ?>'];
                    
                    const filename = `AlumPro_${reportTypeName}_${periodName}_${new Date().toISOString().slice(0, 10)}.csv`;
                    link.setAttribute("download", filename);
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                }
            <?php else: ?>
                // Product analysis charts
                const profileCtx = document.getElementById('profileChart').getContext('2d');
                const glassCtx = document.getElementById('glassChart').getContext('2d');
                
                const profileLabels = <?= json_encode($profileLabels ?? []) ?>;
                const profileValues = <?= json_encode($profileValues ?? []) ?>;
                
                const glassLabels = <?= json_encode($glassLabels ?? []) ?>;
                const glassValues = <?= json_encode($glassValues ?? []) ?>;
                
                // Profile chart
                new Chart(profileCtx, {
                    type: 'pie',
                    data: {
                        labels: profileLabels,
                        datasets: [{
                            data: profileValues,
                            backgroundColor: [
                                'rgba(255, 99, 132, 0.7)',
                                'rgba(54, 162, 235, 0.7)',
                                'rgba(255, 206, 86, 0.7)',
                                'rgba(75, 192, 192, 0.7)',
                                'rgba(153, 102, 255, 0.7)',
                                'rgba(255, 159, 64, 0.7)',
                                'rgba(199, 199, 199, 0.7)',
                                'rgba(83, 102, 255, 0.7)',
                                'rgba(40, 159, 64, 0.7)',
                                'rgba(210, 199, 199, 0.7)'
                            ],
                            borderColor: [
                                'rgba(255, 99, 132, 1)',
                                'rgba(54, 162, 235, 1)',
                                'rgba(255, 206, 86, 1)',
                                'rgba(75, 192, 192, 1)',
                                'rgba(153, 102, 255, 1)',
                                'rgba(255, 159, 64, 1)',
                                'rgba(199, 199, 199, 1)',
                                'rgba(83, 102, 255, 1)',
                                'rgba(40, 159, 64, 1)',
                                'rgba(210, 199, 199, 1)'
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right',
                                labels: {
                                    boxWidth: 15,
                                    font: {
                                        size: 11
                                    }
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return context.label + ': ' + context.raw + ' sifariş';
                                    }
                                }
                            }
                        }
                    }
                });
                
                // Glass chart
                new Chart(glassCtx, {
                    type: 'pie',
                    data: {
                        labels: glassLabels,
                        datasets: [{
                            data: glassValues,
                            backgroundColor: [
                                'rgba(54, 162, 235, 0.7)',
                                'rgba(255, 99, 132, 0.7)',
                                'rgba(255, 206, 86, 0.7)',
                                'rgba(75, 192, 192, 0.7)',
                                'rgba(153, 102, 255, 0.7)',
                                'rgba(255, 159, 64, 0.7)',
                                'rgba(199, 199, 199, 0.7)',
                                'rgba(83, 102, 255, 0.7)',
                                'rgba(40, 159, 64, 0.7)',
                                'rgba(210, 199, 199, 0.7)'
                            ],
                            borderColor: [
                                'rgba(54, 162, 235, 1)',
                                'rgba(255, 99, 132, 1)',
                                'rgba(255, 206, 86, 1)',
                                'rgba(75, 192, 192, 1)',
                                'rgba(153, 102, 255, 1)',
                                'rgba(255, 159, 64, 1)',
                                'rgba(199, 199, 199, 1)',
                                'rgba(83, 102, 255, 1)',
                                'rgba(40, 159, 64, 1)',
                                'rgba(210, 199, 199, 1)'
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right',
                                labels: {
                                    boxWidth: 15,
                                    font: {
                                        size: 11
                                    }
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return context.label + ': ' + context.raw + ' sifariş';
                                    }
                                }
                            }
                        }
                    }
                });
            <?php endif; ?>
        });
    </script>
</body>
</html>
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
$branchId = $_SESSION['branch_id'];

// Get branch information
$branch = getBranchById($branchId);
$branchName = $branch ? $branch['name'] : '';

// Get filter parameters
$status = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$sort = $_GET['sort'] ?? 'date_desc';

// Pagination
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Build query
$conn = getDBConnection();

$sqlCount = "SELECT COUNT(*) as total FROM orders o 
             JOIN customers c ON o.customer_id = c.id
             LEFT JOIN users s ON o.seller_id = s.id";
             
$sql = "SELECT o.*, c.fullname as customer_name, s.fullname as seller_name 
        FROM orders o 
        JOIN customers c ON o.customer_id = c.id
        LEFT JOIN users s ON o.seller_id = s.id";

// Where conditions
$whereConditions = [];
$params = [];
$types = "";

// Filter by seller's orders if not admin
if (!hasRole('admin')) {
    $whereConditions[] = "o.seller_id = ?";
    $params[] = $sellerId;
    $types .= "i";
}

// Filter by status
if ($status !== 'all') {
    $whereConditions[] = "o.order_status = ?";
    $params[] = $status;
    $types .= "s";
}

// Search filter
if (!empty($search)) {
    $whereConditions[] = "(o.order_number LIKE ? OR c.fullname LIKE ? OR o.barcode LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= "sss";
}

// Date filter
if (!empty($dateFrom)) {
    $whereConditions[] = "DATE(o.order_date) >= ?";
    $params[] = $dateFrom;
    $types .= "s";
}

if (!empty($dateTo)) {
    $whereConditions[] = "DATE(o.order_date) <= ?";
    $params[] = $dateTo;
    $types .= "s";
}

// Add where clause to SQL
if (!empty($whereConditions)) {
    $sqlCount .= " WHERE " . implode(" AND ", $whereConditions);
    $sql .= " WHERE " . implode(" AND ", $whereConditions);
}

// Add sorting
switch ($sort) {
    case 'date_asc':
        $sql .= " ORDER BY o.order_date ASC";
        break;
    case 'date_desc':
        $sql .= " ORDER BY o.order_date DESC";
        break;
    case 'number_asc':
        $sql .= " ORDER BY o.order_number ASC";
        break;
    case 'number_desc':
        $sql .= " ORDER BY o.order_number DESC";
        break;
    case 'amount_asc':
        $sql .= " ORDER BY o.total_amount ASC";
        break;
    case 'amount_desc':
        $sql .= " ORDER BY o.total_amount DESC";
        break;
    default:
        $sql .= " ORDER BY o.order_date DESC";
}

// Count total orders - use a copy of params for the count query
$countParams = $params;
$countTypes = $types;

$stmt = $conn->prepare($sqlCount);
if (!empty($countParams)) {
    $stmt->bind_param($countTypes, ...$countParams);
}
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$totalOrders = $result['total'] ?? 0;

// Calculate total pages
$totalPages = ceil($totalOrders / $perPage);

// Add limit for pagination
$sql .= " LIMIT ?, ?";
$params[] = $offset;
$params[] = $perPage;
$types .= "ii";

// Get orders
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get order status counts for tabs
$statusCounts = [];
$sqlStatusCount = "SELECT order_status, COUNT(*) as count FROM orders";

// Add where condition for seller
if (!hasRole('admin')) {
    $sqlStatusCount .= " WHERE seller_id = ?";
}

$sqlStatusCount .= " GROUP BY order_status";

$stmt = $conn->prepare($sqlStatusCount);
if (!hasRole('admin')) {
    $stmt->bind_param("i", $sellerId);
}
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $statusCounts[$row['order_status']] = $row['count'];
}

// Get total count
$sqlTotalCount = "SELECT COUNT(*) as count FROM orders";
if (!hasRole('admin')) {
    $sqlTotalCount .= " WHERE seller_id = ?";
}

$stmt = $conn->prepare($sqlTotalCount);
if (!hasRole('admin')) {
    $stmt->bind_param("i", $sellerId);
}
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$statusCounts['all'] = $result['count'] ?? 0;

// Order status text and colors
$statusConfig = [
    'new' => ['text' => 'Yeni', 'color' => 'info'],
    'processing' => ['text' => 'Hazırlanır', 'color' => 'warning'],
    'completed' => ['text' => 'Hazır', 'color' => 'success'],
    'delivered' => ['text' => 'Təhvil verilib', 'color' => 'success'],
    'cancelled' => ['text' => 'Ləğv edilib', 'color' => 'danger']
];

// Get unread message count
$sql = "SELECT COUNT(*) AS unread_count FROM messages WHERE receiver_id = ? AND is_read = 0";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $sellerId);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$unreadMessages = $result['unread_count'] ?? 0;
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sifarişlər | AlumPro</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        :root {
            --primary-color: #1eb15a;
            --secondary-color: #1e5eb1;
            --primary-gradient: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            --card-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        /* Order specific styles */
        .status-tabs {
            display: flex;
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 20px;
            overflow-x: auto;
            white-space: nowrap;
            padding-bottom: 1px;
        }
        
        .status-tab {
            padding: 12px 20px;
            font-weight: 500;
            color: #6b7280;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.3s;
            position: relative;
        }
        
        .status-tab:hover {
            color: var(--primary-color);
        }
        
        .status-tab.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }
        
        .status-count {
            display: inline-block;
            padding: 2px 6px;
            background: #e5e7eb;
            color: #4b5563;
            border-radius: 10px;
            font-size: 12px;
            margin-left: 5px;
            min-width: 20px;
            text-align: center;
        }
        
        .status-tab.active .status-count {
            background: var(--primary-color);
            color: white;
        }
        
        .filter-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .search-form {
            display: flex;
            gap: 10px;
            flex: 1;
            max-width: 500px;
        }
        
        .search-input {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            min-width: 200px;
        }
        
        .search-btn {
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0 20px;
            cursor: pointer;
        }
        
        .filter-controls {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .filter-control {
            padding: 10px 15px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            min-width: 150px;
        }
        
        .date-input {
            min-width: 120px;
        }
        
        .new-order-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 10px 15px;
            font-weight: 500;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        
        .new-order-btn:hover {
            opacity: 0.9;
        }
        
        .orders-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
        }
        
        .orders-table th {
            background: var(--primary-gradient);
            color: white;
            padding: 12px 15px;
            text-align: left;
        }
        
        .orders-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .orders-table tbody tr:last-child td {
            border-bottom: none;
        }
        
        .orders-table tbody tr:hover {
            background-color: #f9f9f9;
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .badge-info { background-color: #e0f2fe; color: #0369a1; }
        .badge-warning { background-color: #fef3c7; color: #92400e; }
        .badge-success { background-color: #d1fae5; color: #065f46; }
        .badge-danger { background-color: #fee2e2; color: #b91c1c; }
        
        .btn-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 4px;
            background: #f3f4f6;
            color: #374151;
            transition: all 0.2s;
            margin-right: 5px;
        }
        
        .btn-action:hover {
            background: var(--primary-color);
            color: white;
        }
        
        .btn-status {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            margin-right: 5px;
            border: none;
        }
        
        .btn-processing {
            background: #fef3c7;
            color: #92400e;
        }
        
        .btn-processing:hover {
            background: #f59e0b;
            color: white;
        }
        
        .btn-complete {
            background: #d1fae5;
            color: #065f46;
        }
        
        .btn-complete:hover {
            background: #10b981;
            color: white;
        }
        
        .btn-deliver {
            background: #a7f3d0;
            color: #065f46;
        }
        
        .btn-deliver:hover {
            background: #059669;
            color: white;
        }
        
        .btn-cancel {
            background: #fee2e2;
            color: #b91c1c;
        }
        
        .btn-cancel:hover {
            background: #ef4444;
            color: white;
        }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
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
            transition: all 0.2s;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .pagination-link:hover {
            background: #f3f4f6;
        }
        
        .pagination-link.active {
            background: var(--primary-gradient);
            color: white;
        }
        
        .pagination-link.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 50px 0;
            background: white;
            border-radius: 8px;
            box-shadow: var(--card-shadow);
        }
        
        .empty-icon {
            font-size: 48px;
            color: #d1d5db;
            margin-bottom: 20px;
        }
        
        /* Status filter dropdown */
        .status-dropdown {
            position: relative;
            display: inline-block;
        }
        
        .status-dropdown-content {
            display: none;
            position: absolute;
            background-color: white;
            min-width: 160px;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
            z-index: 1;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .status-dropdown-content a {
            color: #374151;
            padding: 12px 16px;
            text-decoration: none;
            display: block;
            transition: background 0.2s;
        }
        
        .status-dropdown-content a:hover {
            background-color: #f3f4f6;
        }
        
        .status-dropdown:hover .status-dropdown-content {
            display: block;
        }
    </style>
</head>
<body>
    <!-- App Header -->
    <header class="app-header">
        <div class="header-left">
            <div class="logo">ALUMPRO.AZ</div>
            <div class="nav-links">
                <a href="index.php"><i class="fas fa-home"></i> Ana Səhifə</a>
                <a href="orders.php" class="active"><i class="fas fa-clipboard-list"></i> Sifarişlər</a>
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
                <span><?= htmlspecialchars($sellerName) ?> (<?= htmlspecialchars($branchName) ?>)</span>
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
                <h1><i class="fas fa-clipboard-list"></i> Sifarişlər</h1>
                <div class="breadcrumb">
                    <a href="index.php">Ana Səhifə</a> / <span>Sifarişlər</span>
                </div>
            </div>

            <!-- Status Tabs -->
            <div class="status-tabs">
                <a href="?status=all<?= !empty($search) ? '&search=' . urlencode($search) : '' ?><?= !empty($sort) ? '&sort=' . $sort : '' ?>" class="status-tab <?= $status === 'all' ? 'active' : '' ?>">
                    Bütün sifarişlər <span class="status-count"><?= $statusCounts['all'] ?? 0 ?></span>
                </a>
                <a href="?status=new<?= !empty($search) ? '&search=' . urlencode($search) : '' ?><?= !empty($sort) ? '&sort=' . $sort : '' ?>" class="status-tab <?= $status === 'new' ? 'active' : '' ?>">
                    Yeni <span class="status-count"><?= $statusCounts['new'] ?? 0 ?></span>
                </a>
                <a href="?status=processing<?= !empty($search) ? '&search=' . urlencode($search) : '' ?><?= !empty($sort) ? '&sort=' . $sort : '' ?>" class="status-tab <?= $status === 'processing' ? 'active' : '' ?>">
                    Hazırlanır <span class="status-count"><?= $statusCounts['processing'] ?? 0 ?></span>
                </a>
                <a href="?status=completed<?= !empty($search) ? '&search=' . urlencode($search) : '' ?><?= !empty($sort) ? '&sort=' . $sort : '' ?>" class="status-tab <?= $status === 'completed' ? 'active' : '' ?>">
                    Hazır <span class="status-count"><?= $statusCounts['completed'] ?? 0 ?></span>
                </a>
                <a href="?status=delivered<?= !empty($search) ? '&search=' . urlencode($search) : '' ?><?= !empty($sort) ? '&sort=' . $sort : '' ?>" class="status-tab <?= $status === 'delivered' ? 'active' : '' ?>">
                    Təhvil verilib <span class="status-count"><?= $statusCounts['delivered'] ?? 0 ?></span>
                </a>
                <a href="?status=cancelled<?= !empty($search) ? '&search=' . urlencode($search) : '' ?><?= !empty($sort) ? '&sort=' . $sort : '' ?>" class="status-tab <?= $status === 'cancelled' ? 'active' : '' ?>">
                    Ləğv edilib <span class="status-count"><?= $statusCounts['cancelled'] ?? 0 ?></span>
                </a>
            </div>

            <!-- Filters -->
            <div class="filter-bar">
                <form action="" method="get" class="search-form">
                    <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
                    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                    
                    <input type="text" name="search" placeholder="Sifariş nömrəsi, müştəri adı və ya barkod..." class="search-input" value="<?= htmlspecialchars($search) ?>">
                    <button type="submit" class="search-btn"><i class="fas fa-search"></i></button>
                </form>
                
                <div class="filter-controls">
                    <form action="" method="get" id="filter-form">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                        
                        <select name="sort" class="filter-control" onchange="document.getElementById('filter-form').submit()">
                            <option value="date_desc" <?= $sort === 'date_desc' ? 'selected' : '' ?>>Tarix (Yeni - Köhnə)</option>
                            <option value="date_asc" <?= $sort === 'date_asc' ? 'selected' : '' ?>>Tarix (Köhnə - Yeni)</option>
                            <option value="number_desc" <?= $sort === 'number_desc' ? 'selected' : '' ?>>Sifariş № (Böyük - Kiçik)</option>
                            <option value="number_asc" <?= $sort === 'number_asc' ? 'selected' : '' ?>>Sifariş № (Kiçik - Böyük)</option>
                            <option value="amount_desc" <?= $sort === 'amount_desc' ? 'selected' : '' ?>>Məbləğ (Böyük - Kiçik)</option>
                            <option value="amount_asc" <?= $sort === 'amount_asc' ? 'selected' : '' ?>>Məbləğ (Kiçik - Böyük)</option>
                        </select>
                        
                        <input type="date" name="date_from" class="filter-control date-input" value="<?= htmlspecialchars($dateFrom) ?>" placeholder="Tarixdən" onchange="document.getElementById('filter-form').submit()">
                        <input type="date" name="date_to" class="filter-control date-input" value="<?= htmlspecialchars($dateTo) ?>" placeholder="Tarixədək" onchange="document.getElementById('filter-form').submit()">
                    </form>
                    
                    <a href="hesabla.php" class="new-order-btn">
                        <i class="fas fa-plus"></i> Yeni Sifariş
                    </a>
                </div>
            </div>

            <?php if (empty($orders)): ?>
                <div class="empty-state">
                    <i class="fas fa-clipboard-list empty-icon"></i>
                    <h3>Heç bir sifariş tapılmadı</h3>
                    <p>Yeni sifariş yaratmaq üçün "Yeni Sifariş" düyməsini sıxın</p>
                </div>
            <?php else: ?>
                <!-- Orders Table -->
                <table class="orders-table">
                    <thead>
                        <tr>
                            <th>Sifariş №</th>
                            <th>Müştəri</th>
                            <th>Tarix</th>
                            <th>Məbləğ</th>
                            <th>Ödəniş</th>
                            <th>Status</th>
                            <th>Əməliyyatlar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <tr>
                                <td><?= htmlspecialchars($order['order_number']) ?></td>
                                <td><?= htmlspecialchars($order['customer_name']) ?></td>
                                <td><?= formatDate($order['order_date'], 'd.m.Y H:i') ?></td>
                                <td><?= formatMoney($order['total_amount']) ?></td>
                                <td>
                                    <?php if ($order['remaining_amount'] > 0): ?>
                                        <span class="status-badge badge-warning">
                                            <?= formatMoney($order['advance_payment']) ?> / <?= formatMoney($order['total_amount']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge badge-success">Tam</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                        $statusInfo = $statusConfig[$order['order_status']] ?? ['text' => 'Bilinmir', 'color' => 'info'];
                                    ?>
                                    <span class="status-badge badge-<?= $statusInfo['color'] ?>">
                                        <?= $statusInfo['text'] ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="actions-wrapper">
                                        <a href="order-details.php?id=<?= $order['id'] ?>" class="btn-action" title="Ətraflı bax">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        
                                        <?php if (in_array($order['order_status'], ['new', 'processing', 'completed'])): ?>
                                            <a href="order-edit.php?id=<?= $order['id'] ?>" class="btn-action" title="Düzəliş et">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <a href="order-print.php?id=<?= $order['id'] ?>" class="btn-action" title="Çap et">
                                            <i class="fas fa-print"></i>
                                        </a>
                                        
                                        <?php if (!empty($order['pdf_file'])): ?>
                                            <a href="<?= htmlspecialchars($order['pdf_file']) ?>" class="btn-action" title="PDF yüklə" target="_blank">
                                                <i class="fas fa-file-pdf"></i>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <!-- Status Change Dropdown -->
                                        <?php if ($order['order_status'] !== 'cancelled' && $order['order_status'] !== 'delivered'): ?>
                                            <div class="status-dropdown">
                                                <button class="btn-action" title="Status dəyiş">
                                                    <i class="fas fa-exchange-alt"></i>
                                                </button>
                                                <div class="status-dropdown-content">
                                                    <?php if ($order['order_status'] === 'new'): ?>
                                                        <a href="update-order-status.php?id=<?= $order['id'] ?>&status=processing">
                                                            <i class="fas fa-cog text-warning"></i> Hazırlanır
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (in_array($order['order_status'], ['new', 'processing'])): ?>
                                                        <a href="update-order-status.php?id=<?= $order['id'] ?>&status=completed">
                                                            <i class="fas fa-check text-success"></i> Hazır
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (in_array($order['order_status'], ['new', 'processing', 'completed'])): ?>
                                                        <a href="update-order-status.php?id=<?= $order['id'] ?>&status=delivered">
                                                            <i class="fas fa-truck text-success"></i> Təhvil verildi
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <a href="update-order-status.php?id=<?= $order['id'] ?>&status=cancelled" class="text-danger">
                                                        <i class="fas fa-times text-danger"></i> Ləğv et
                                                    </a>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php
                        // Build the base URL for pagination links
                        $params = [];
                        if (!empty($status)) $params[] = "status=" . urlencode($status);
                        if (!empty($search)) $params[] = "search=" . urlencode($search);
                        if (!empty($sort)) $params[] = "sort=" . urlencode($sort);
                        if (!empty($dateFrom)) $params[] = "date_from=" . urlencode($dateFrom);
                        if (!empty($dateTo)) $params[] = "date_to=" . urlencode($dateTo);
                        
                        $baseUrl = "?" . implode("&", $params);
                        ?>
                        
                        <?php if ($page > 1): ?>
                            <a href="<?= $baseUrl ?>&page=1" class="pagination-link">
                                <i class="fas fa-angle-double-left"></i>
                            </a>
                            <a href="<?= $baseUrl ?>&page=<?= $page - 1 ?>" class="pagination-link">
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
                            <a href="<?= $baseUrl ?>&page=<?= $i ?>" class="pagination-link <?= $i === $page ? 'active' : '' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="<?= $baseUrl ?>&page=<?= $page + 1 ?>" class="pagination-link">
                                <i class="fas fa-angle-right"></i>
                            </a>
                            <a href="<?= $baseUrl ?>&page=<?= $totalPages ?>" class="pagination-link">
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
    </main>

    <!-- Footer -->
    <footer class="app-footer">
        <div>&copy; <?= date('Y') ?> AlumPro.az - Bütün hüquqlar qorunur</div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
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
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

// Handle active tab and search
$activeTab = $_GET['tab'] ?? 'profile';
$search = $_GET['search'] ?? '';
$filter = $_GET['filter'] ?? '';

// Get warehouse categories
$conn = getDBConnection();
$categories = [];

$sql = "SELECT * FROM warehouse_categories WHERE status = 'active' ORDER BY name";
$result = $conn->query($sql);
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }
}

// Get inventory based on active tab
$inventory = [];
$paginate = true;
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;
$totalItems = 0;

// Build query based on active tab and search
switch ($activeTab) {
    case 'profile':
        $sqlCount = "SELECT COUNT(*) as total FROM profile_inventory";
        $sql = "SELECT * FROM profile_inventory";
        
        // Apply search if provided
        if (!empty($search)) {
            $searchTerm = '%' . $search . '%';
            $sqlCount .= " WHERE name LIKE ? OR color LIKE ? OR type LIKE ?";
            $sql .= " WHERE name LIKE ? OR color LIKE ? OR type LIKE ?";
        }
        
        // Apply filter if provided
        if ($filter === 'low-stock') {
            $whereClause = !empty($search) ? " AND" : " WHERE";
            $sqlCount .= "$whereClause remaining_quantity <= 10";
            $sql .= "$whereClause remaining_quantity <= 10";
        }
        
        $sql .= " ORDER BY name LIMIT ?, ?";
        
        // Get total items for pagination
        if (!empty($search)) {
            $stmt = $conn->prepare($sqlCount);
            $stmt->bind_param("sss", $searchTerm, $searchTerm, $searchTerm);
            $stmt->execute();
            $result = $stmt->get_result();
            $totalItems = $result->fetch_assoc()['total'];
            
            // Get inventory items
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssis", $searchTerm, $searchTerm, $searchTerm, $offset, $perPage);
            $stmt->execute();
            $inventory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        } else {
            $result = $conn->query($sqlCount);
            $totalItems = $result->fetch_assoc()['total'];
            
            // Get inventory items
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $offset, $perPage);
            $stmt->execute();
            $inventory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        break;
        
    case 'glass':
        $sqlCount = "SELECT COUNT(*) as total FROM glass_inventory";
        $sql = "SELECT * FROM glass_inventory";
        
        // Apply search if provided
        if (!empty($search)) {
            $searchTerm = '%' . $search . '%';
            $sqlCount .= " WHERE name LIKE ? OR type LIKE ?";
            $sql .= " WHERE name LIKE ? OR type LIKE ?";
        }
        
        // Apply filter if provided
        if ($filter === 'low-stock') {
            $whereClause = !empty($search) ? " AND" : " WHERE";
            $sqlCount .= "$whereClause remaining_volume <= 5";
            $sql .= "$whereClause remaining_volume <= 5";
        }
        
        $sql .= " ORDER BY name LIMIT ?, ?";
        
        // Get total items for pagination
        if (!empty($search)) {
            $stmt = $conn->prepare($sqlCount);
            $stmt->bind_param("ss", $searchTerm, $searchTerm);
            $stmt->execute();
            $result = $stmt->get_result();
            $totalItems = $result->fetch_assoc()['total'];
            
            // Get inventory items
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssii", $searchTerm, $searchTerm, $offset, $perPage);
            $stmt->execute();
            $inventory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        } else {
            $result = $conn->query($sqlCount);
            $totalItems = $result->fetch_assoc()['total'];
            
            // Get inventory items
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $offset, $perPage);
            $stmt->execute();
            $inventory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        break;
        
    case 'accessories':
        $sqlCount = "SELECT COUNT(*) as total FROM accessories_inventory";
        $sql = "SELECT * FROM accessories_inventory";
        
        // Apply search if provided
        if (!empty($search)) {
            $searchTerm = '%' . $search . '%';
            $sqlCount .= " WHERE name LIKE ?";
            $sql .= " WHERE name LIKE ?";
        }
        
        // Apply filter if provided
        if ($filter === 'low-stock') {
            $whereClause = !empty($search) ? " AND" : " WHERE";
            $sqlCount .= "$whereClause remaining_quantity <= 20";
            $sql .= "$whereClause remaining_quantity <= 20";
        }
        
        $sql .= " ORDER BY name LIMIT ?, ?";
        
        // Get total items for pagination
        if (!empty($search)) {
            $stmt = $conn->prepare($sqlCount);
            $stmt->bind_param("s", $searchTerm);
            $stmt->execute();
            $result = $stmt->get_result();
            $totalItems = $result->fetch_assoc()['total'];
            
            // Get inventory items
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sii", $searchTerm, $offset, $perPage);
            $stmt->execute();
            $inventory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        } else {
            $result = $conn->query($sqlCount);
            $totalItems = $result->fetch_assoc()['total'];
            
            // Get inventory items
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $offset, $perPage);
            $stmt->execute();
            $inventory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        break;
}

// Calculate total pages
$totalPages = ceil($totalItems / $perPage);

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
    <title>Anbar | AlumPro</title>
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

        /* Warehouse specific styles */
        .tab-navigation {
            display: flex;
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .tab-link {
            padding: 12px 20px;
            font-weight: 500;
            color: #6b7280;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.3s;
        }
        
        .tab-link:hover {
            color: var(--primary-color);
        }
        
        .tab-link.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }
        
        .search-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .search-input {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .search-btn {
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0 20px;
            cursor: pointer;
        }
        
        .filter-dropdown {
            padding: 10px 15px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            background: white;
        }
        
        .inventory-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
        }
        
        .inventory-table th {
            background: var(--primary-gradient);
            color: white;
            padding: 12px 15px;
            text-align: left;
        }
        
        .inventory-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .inventory-table tbody tr:last-child td {
            border-bottom: none;
        }
        
        .inventory-table tbody tr:hover {
            background-color: #f9f9f9;
        }
        
        .stock-status {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .stock-low { background-color: #fee2e2; color: #b91c1c; }
        .stock-medium { background-color: #fef3c7; color: #92400e; }
        .stock-good { background-color: #d1fae5; color: #065f46; }
        
        .btn-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
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
        
        .action-toolbar {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        
        .toolbar-btns {
            display: flex;
            gap: 10px;
        }
        
        .btn-new {
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
        
        .btn-new:hover {
            opacity: 0.9;
        }
        
        .btn-export {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: white;
            color: #374151;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 10px 15px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .btn-export:hover {
            background: #f9fafb;
        }
        
        /* Stock progress bar */
        .stock-bar {
            width: 100%;
            height: 8px;
            background: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 5px;
        }
        
        .stock-progress {
            height: 100%;
            background: var(--primary-gradient);
        }
        
        .low-progress { background: #ef4444; }
        .medium-progress { background: #f59e0b; }
        .high-progress { background: #10b981; }
    </style>
</head>
<body>
    <!-- App Header -->
    <header class="app-header">
        <div class="header-left">
            <div class="logo">ALUMPRO.AZ</div>
            <div class="nav-links">
                <a href="index.php"><i class="fas fa-home"></i> Ana Səhifə</a>
                <a href="orders.php"><i class="fas fa-clipboard-list"></i> Sifarişlər</a>
                <a href="customers.php"><i class="fas fa-users"></i> Müştərilər</a>
                <a href="warehouse.php" class="active"><i class="fas fa-warehouse"></i> Anbar</a>
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
                <h1><i class="fas fa-warehouse"></i> Anbar</h1>
                <div class="breadcrumb">
                    <a href="index.php">Ana Səhifə</a> / <span>Anbar</span>
                </div>
            </div>

            <!-- Tab Navigation -->
            <div class="tab-navigation">
                <a href="?tab=profile" class="tab-link <?= $activeTab === 'profile' ? 'active' : '' ?>">
                    <i class="fas fa-box"></i> Profillər
                </a>
                <a href="?tab=glass" class="tab-link <?= $activeTab === 'glass' ? 'active' : '' ?>">
                    <i class="fas fa-square"></i> Şüşələr
                </a>
                <a href="?tab=accessories" class="tab-link <?= $activeTab === 'accessories' ? 'active' : '' ?>">
                    <i class="fas fa-tools"></i> Aksesuarlar
                </a>
            </div>

            <!-- Action Toolbar -->
            <div class="action-toolbar">
                <div class="toolbar-btns">
                    <?php if (hasRole('admin')): ?>
                        <a href="warehouse-new.php?type=<?= $activeTab ?>" class="btn-new">
                            <i class="fas fa-plus"></i> Yeni <?= $activeTab === 'profile' ? 'Profil' : ($activeTab === 'glass' ? 'Şüşə' : 'Aksesuar') ?>
                        </a>
                    <?php endif; ?>
                    <a href="warehouse-export.php?tab=<?= $activeTab ?>" class="btn-export">
                        <i class="fas fa-file-export"></i> Export
                    </a>
                </div>
                
                <form action="" method="get" class="search-bar">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
                    
                    <select name="filter" class="filter-dropdown">
                        <option value="" <?= $filter === '' ? 'selected' : '' ?>>Bütün məhsullar</option>
                        <option value="low-stock" <?= $filter === 'low-stock' ? 'selected' : '' ?>>Azalan məhsullar</option>
                    </select>
                    
                    <input type="text" name="search" placeholder="Məhsul axtar..." class="search-input" value="<?= htmlspecialchars($search) ?>">
                    <button type="submit" class="search-btn"><i class="fas fa-search"></i></button>
                </form>
            </div>

            <!-- Inventory Table -->
            <?php if ($activeTab === 'profile'): ?>
                <table class="inventory-table">
                    <thead>
                        <tr>
                            <th>Profil Adı</th>
                            <th>Rəng</th>
                            <th>Növ</th>
                            <th>Ölçü Vahidi</th>
                            <th>Satış Qiyməti</th>
                            <th>Qalan Miqdar</th>
                            <th>Status</th>
                            <th>Əməliyyatlar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($inventory)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center;">Heç bir məhsul tapılmadı</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($inventory as $item): ?>
                                <?php 
                                    // Determine stock status
                                    $stockStatus = '';
                                    $stockClass = '';
                                    $progressClass = '';
                                    $percentRemaining = 0;
                                    
                                    if ($item['purchase_quantity'] > 0) {
                                        $percentRemaining = ($item['remaining_quantity'] / $item['purchase_quantity']) * 100;
                                    }
                                    
                                    if ($item['remaining_quantity'] <= 10) {
                                        $stockStatus = 'Azalır';
                                        $stockClass = 'stock-low';
                                        $progressClass = 'low-progress';
                                    } elseif ($item['remaining_quantity'] <= 30) {
                                        $stockStatus = 'Orta';
                                        $stockClass = 'stock-medium';
                                        $progressClass = 'medium-progress';
                                    } else {
                                        $stockStatus = 'Yaxşı';
                                        $stockClass = 'stock-good';
                                        $progressClass = 'high-progress';
                                    }
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($item['name']) ?></td>
                                    <td><?= htmlspecialchars($item['color']) ?></td>
                                    <td><?= htmlspecialchars($item['type']) ?></td>
                                    <td><?= htmlspecialchars($item['unit_of_measure']) ?></td>
                                    <td><?= formatMoney($item['sales_price']) ?></td>
                                    <td>
                                        <?= $item['remaining_quantity'] ?> <?= htmlspecialchars($item['unit_of_measure']) ?>
                                        <div class="stock-bar">
                                            <div class="stock-progress <?= $progressClass ?>" style="width: <?= min(100, $percentRemaining) ?>%"></div>
                                        </div>
                                    </td>
                                    <td><span class="stock-status <?= $stockClass ?>"><?= $stockStatus ?></span></td>
                                    <td>
                                        <a href="warehouse-view.php?type=profile&id=<?= $item['id'] ?>" class="btn-action" title="Ətraflı bax">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if (hasRole('admin')): ?>
                                            <a href="warehouse-edit.php?type=profile&id=<?= $item['id'] ?>" class="btn-action" title="Düzəliş et">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="warehouse-history.php?type=profile&id=<?= $item['id'] ?>" class="btn-action" title="Tarixçə">
                                            <i class="fas fa-history"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php elseif ($activeTab === 'glass'): ?>
                <table class="inventory-table">
                    <thead>
                        <tr>
                            <th>Şüşə Adı</th>
                            <th>Qalınlıq</th>
                            <th>Növ</th>
                            <th>Ölçü</th>
                            <th>Qiymət (m²)</th>
                            <th>Qalan Həcm</th>
                            <th>Status</th>
                            <th>Əməliyyatlar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($inventory)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center;">Heç bir məhsul tapılmadı</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($inventory as $item): ?>
                                <?php 
                                    // Determine stock status
                                    $stockStatus = '';
                                    $stockClass = '';
                                    $progressClass = '';
                                    $percentRemaining = 0;
                                    
                                    if ($item['purchase_volume'] > 0) {
                                        $percentRemaining = ($item['remaining_volume'] / $item['purchase_volume']) * 100;
                                    }
                                    
                                    if ($item['remaining_volume'] <= 5) {
                                        $stockStatus = 'Azalır';
                                        $stockClass = 'stock-low';
                                        $progressClass = 'low-progress';
                                    } elseif ($item['remaining_volume'] <= 15) {
                                        $stockStatus = 'Orta';
                                        $stockClass = 'stock-medium';
                                        $progressClass = 'medium-progress';
                                    } else {
                                        $stockStatus = 'Yaxşı';
                                        $stockClass = 'stock-good';
                                        $progressClass = 'high-progress';
                                    }
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($item['name']) ?></td>
                                    <td><?= $item['thickness'] ?> mm</td>
                                    <td><?= htmlspecialchars($item['type']) ?></td>
                                    <td><?= htmlspecialchars($item['dimensions'] ?? '-') ?></td>
                                    <td><?= formatMoney($item['purchase_price']) ?></td>
                                    <td>
                                        <?= $item['remaining_volume'] ?> m²
                                        <div class="stock-bar">
                                            <div class="stock-progress <?= $progressClass ?>" style="width: <?= min(100, $percentRemaining) ?>%"></div>
                                        </div>
                                    </td>
                                    <td><span class="stock-status <?= $stockClass ?>"><?= $stockStatus ?></span></td>
                                    <td>
                                        <a href="warehouse-view.php?type=glass&id=<?= $item['id'] ?>" class="btn-action" title="Ətraflı bax">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if (hasRole('admin')): ?>
                                            <a href="warehouse-edit.php?type=glass&id=<?= $item['id'] ?>" class="btn-action" title="Düzəliş et">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="warehouse-history.php?type=glass&id=<?= $item['id'] ?>" class="btn-action" title="Tarixçə">
                                            <i class="fas fa-history"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php elseif ($activeTab === 'accessories'): ?>
                <table class="inventory-table">
                    <thead>
                        <tr>
                            <th>Aksesuar Adı</th>
                            <th>Ölçü Vahidi</th>
                            <th>Alış Qiyməti</th>
                            <th>Qalan Miqdar</th>
                            <th>Status</th>
                            <th>Qeyd</th>
                            <th>Əməliyyatlar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($inventory)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center;">Heç bir məhsul tapılmadı</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($inventory as $item): ?>
                                <?php 
                                    // Determine stock status
                                    $stockStatus = '';
                                    $stockClass = '';
                                    $progressClass = '';
                                    $percentRemaining = 0;
                                    
                                    if ($item['purchase_quantity'] > 0) {
                                        $percentRemaining = ($item['remaining_quantity'] / $item['purchase_quantity']) * 100;
                                    }
                                    
                                    if ($item['remaining_quantity'] <= 20) {
                                        $stockStatus = 'Azalır';
                                        $stockClass = 'stock-low';
                                        $progressClass = 'low-progress';
                                    } elseif ($item['remaining_quantity'] <= 50) {
                                        $stockStatus = 'Orta';
                                        $stockClass = 'stock-medium';
                                        $progressClass = 'medium-progress';
                                    } else {
                                        $stockStatus = 'Yaxşı';
                                        $stockClass = 'stock-good';
                                        $progressClass = 'high-progress';
                                    }
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($item['name']) ?></td>
                                    <td><?= htmlspecialchars($item['unit_of_measure']) ?></td>
                                    <td><?= formatMoney($item['purchase_price']) ?></td>
                                    <td>
                                        <?= $item['remaining_quantity'] ?> <?= htmlspecialchars($item['unit_of_measure']) ?>
                                        <div class="stock-bar">
                                            <div class="stock-progress <?= $progressClass ?>" style="width: <?= min(100, $percentRemaining) ?>%"></div>
                                        </div>
                                    </td>
                                    <td><span class="stock-status <?= $stockClass ?>"><?= $stockStatus ?></span></td>
                                    <td><?= htmlspecialchars($item['notes'] ?? '-') ?></td>
                                    <td>
                                        <a href="warehouse-view.php?type=accessories&id=<?= $item['id'] ?>" class="btn-action" title="Ətraflı bax">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if (hasRole('admin')): ?>
                                            <a href="warehouse-edit.php?type=accessories&id=<?= $item['id'] ?>" class="btn-action" title="Düzəliş et">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="warehouse-history.php?type=accessories&id=<?= $item['id'] ?>" class="btn-action" title="Tarixçə">
                                            <i class="fas fa-history"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <!-- Pagination -->
            <?php if ($paginate && $totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?tab=<?= $activeTab ?>&page=1<?= !empty($search) ? '&search=' . htmlspecialchars($search) : '' ?><?= !empty($filter) ? '&filter=' . htmlspecialchars($filter) : '' ?>" class="pagination-link">
                            <i class="fas fa-angle-double-left"></i>
                        </a>
                        <a href="?tab=<?= $activeTab ?>&page=<?= $page - 1 ?><?= !empty($search) ? '&search=' . htmlspecialchars($search) : '' ?><?= !empty($filter) ? '&filter=' . htmlspecialchars($filter) : '' ?>" class="pagination-link">
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
                        <a href="?tab=<?= $activeTab ?>&page=<?= $i ?><?= !empty($search) ? '&search=' . htmlspecialchars($search) : '' ?><?= !empty($filter) ? '&filter=' . htmlspecialchars($filter) : '' ?>" class="pagination-link <?= $i === $page ? 'active' : '' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?tab=<?= $activeTab ?>&page=<?= $page + 1 ?><?= !empty($search) ? '&search=' . htmlspecialchars($search) : '' ?><?= !empty($filter) ? '&filter=' . htmlspecialchars($filter) : '' ?>" class="pagination-link">
                            <i class="fas fa-angle-right"></i>
                        </a>
                        <a href="?tab=<?= $activeTab ?>&page=<?= $totalPages ?><?= !empty($search) ? '&search=' . htmlspecialchars($search) : '' ?><?= !empty($filter) ? '&filter=' . htmlspecialchars($filter) : '' ?>" class="pagination-link">
                            <i class="fas fa-angle-double-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="pagination-link disabled"><i class="fas fa-angle-right"></i></span>
                        <span class="pagination-link disabled"><i class="fas fa-angle-double-right"></i></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Footer -->
    <footer class="app-footer">
        <div>&copy; <?= date('Y') ?> AlumPro.az - B端t端n h端quqlar qorunur</div>
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
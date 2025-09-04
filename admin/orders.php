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
if (!hasRole('admin')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

$userId = $_SESSION['user_id'];
$conn = getDBConnection();

// Get filter parameters
$status = isset($_GET['status']) ? $_GET['status'] : 'all';
$dateStart = isset($_GET['date_start']) ? $_GET['date_start'] : date('Y-m-d', strtotime('-30 days'));
$dateEnd = isset($_GET['date_end']) ? $_GET['date_end'] : date('Y-m-d');
$branchId = isset($_GET['branch_id']) && is_numeric($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
$sellerId = isset($_GET['seller_id']) && is_numeric($_GET['seller_id']) ? (int)$_GET['seller_id'] : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Get branches
$branchesSql = "SELECT id, name FROM branches WHERE status = 'active' ORDER BY name";
$branches = $conn->query($branchesSql)->fetch_all(MYSQLI_ASSOC);

// Get sellers
$sellersSql = "SELECT id, fullname FROM users WHERE role = 'seller' AND status = 'active' ORDER BY fullname";
$sellers = $conn->query($sellersSql)->fetch_all(MYSQLI_ASSOC);

// Build query
$whereClause = [];
$params = [];
$types = "";

if ($status !== 'all') {
    $whereClause[] = "o.order_status = ?";
    $params[] = $status;
    $types .= "s";
}

if (!empty($dateStart)) {
    $whereClause[] = "DATE(o.order_date) >= ?";
    $params[] = $dateStart;
    $types .= "s";
}

if (!empty($dateEnd)) {
    $whereClause[] = "DATE(o.order_date) <= ?";
    $params[] = $dateEnd;
    $types .= "s";
}

if ($branchId > 0) {
    $whereClause[] = "o.branch_id = ?";
    $params[] = $branchId;
    $types .= "i";
}

if ($sellerId > 0) {
    $whereClause[] = "o.seller_id = ?";
    $params[] = $sellerId;
    $types .= "i";
}

if (!empty($search)) {
    $whereClause[] = "(o.order_number LIKE ? OR o.barcode LIKE ? OR c.fullname LIKE ? OR c.phone LIKE ?)";
    $searchParam = "%{$search}%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "ssss";
}

$whereClauseStr = !empty($whereClause) ? "WHERE " . implode(" AND ", $whereClause) : "";

// Get total count for pagination
$countSql = "SELECT COUNT(*) as total FROM orders o 
             LEFT JOIN customers c ON o.customer_id = c.id 
             LEFT JOIN users s ON o.seller_id = s.id 
             LEFT JOIN branches b ON o.branch_id = b.id 
             $whereClauseStr";

$stmt = $conn->prepare($countSql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$totalOrders = $stmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalOrders / $perPage);

// Get orders
$sql = "SELECT o.*, 
               c.fullname AS customer_name, c.phone AS customer_phone, 
               s.fullname AS seller_name,
               b.name AS branch_name
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        LEFT JOIN users s ON o.seller_id = s.id
        LEFT JOIN branches b ON o.branch_id = b.id
        $whereClauseStr
        ORDER BY o.order_date DESC
        LIMIT ?, ?";

$params[] = $offset;
$params[] = $perPage;
$types .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Status configuration for UI
$statusConfig = [
    'new' => ['text' => 'Yeni', 'color' => 'info'],
    'processing' => ['text' => 'Hazırlanır', 'color' => 'warning'],
    'completed' => ['text' => 'Hazır', 'color' => 'success'],
    'delivered' => ['text' => 'Təhvil verilib', 'color' => 'primary'],
    'cancelled' => ['text' => 'Ləğv edilib', 'color' => 'danger']
];

// Process order status update
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
    $newStatus = isset($_POST['new_status']) ? $_POST['new_status'] : '';
    $statusNote = isset($_POST['status_note']) ? trim($_POST['status_note']) : '';
    
    if ($orderId <= 0) {
        $message = 'Yanlış sifariş ID-si';
        $messageType = 'error';
    } elseif (empty($newStatus)) {
        $message = 'Yeni status seçilməyib';
        $messageType = 'error';
    } else {
        // Get current order status
        $sql = "SELECT order_status, order_number FROM orders WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $currentOrder = $stmt->get_result()->fetch_assoc();
        
        if (!$currentOrder) {
            $message = 'Sifariş tapılmadı';
            $messageType = 'error';
        } else {
            $currentStatus = $currentOrder['order_status'];
            
            // Set appropriate datetime field based on new status
            $dateField = '';
            switch ($newStatus) {
                case 'processing':
                    $dateField = 'processing_date = NOW()';
                    break;
                case 'completed':
                    $dateField = 'completion_date = NOW()';
                    break;
                case 'delivered':
                    $dateField = 'delivery_date = NOW()';
                    break;
            }
            
            // Update order status
            $sql = "UPDATE orders SET order_status = ?" . ($dateField ? ", $dateField" : "") . " WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $newStatus, $orderId);
            
            if ($stmt->execute()) {
                $message = 'Sifariş statusu uğurla yeniləndi';
                $messageType = 'success';
                
                // Log status change
                $logMessage = "Sifariş statusu dəyişdirildi: {$currentOrder['order_number']} - {$statusConfig[$currentStatus]['text']} → {$statusConfig[$newStatus]['text']}";
                if (!empty($statusNote)) {
                    $logMessage .= " | Qeyd: $statusNote";
                }
                
                logActivity($userId, 'order_status_change', $logMessage);
                
                // If needed, add status change to order history table
                // This would be implemented in a real application
                
                // Send notification if configured
                // This would be implemented in a real application
            } else {
                $message = 'Status yeniləmə xətası';
                $messageType = 'error';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sifarişlər | AlumPro Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .filter-container {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
            align-items: center;
        }
        
        .filter-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .filter-label {
            font-weight: 500;
            color: #6b7280;
            white-space: nowrap;
        }
        
        .filter-select, .filter-input {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: var(--border-radius);
            background-color: white;
        }
        
        .filter-select {
            min-width: 150px;
        }
        
        .filter-input {
            min-width: 200px;
        }
        
        .order-table th {
            white-space: nowrap;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .badge-info {
            background-color: #e0f2fe;
            color: #0369a1;
        }
        
        .badge-warning {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .badge-success {
            background-color: #d1fae5;
            color: #065f46;
        }
        
        .badge-primary {
            background-color: #dbeafe;
            color: #1e40af;
        }
        
        .badge-danger {
            background-color: #fee2e2;
            color: #b91c1c;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 5px;
            margin-top: 20px;
        }
        
        .page-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: var(--border-radius);
            background: white;
            color: #374151;
            text-decoration: none;
            font-weight: 500;
            box-shadow: var(--card-shadow);
        }
        
        .page-link.active {
            background: var(--primary-gradient);
            color: white;
        }
        
        .page-link.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .btn-group {
            display: flex;
            gap: 5px;
        }
        
        @media (max-width: 992px) {
            .filter-container {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-item {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .filter-select, .filter-input {
                width: 100%;
            }
            
            .order-table {
                font-size: 0.8rem;
            }
            
            .btn-sm {
                padding: 0.25rem 0.5rem;
                font-size: 0.75rem;
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
                <a href="users.php"><i class="fas fa-users"></i> İstifadəçilər</a>
                <a href="inventory.php"><i class="fas fa-warehouse"></i> Anbar</a>
                <a href="orders.php" class="active"><i class="fas fa-shopping-cart"></i> Sifarişlər</a>
                <a href="reports.php"><i class="fas fa-chart-bar"></i> Hesabatlar</a>
                <a href="settings.php"><i class="fas fa-cog"></i> Tənzimləmələr</a>
            </div>
        </div>
        <div class="header-right">
            <div class="user-info">
                <span><?= $_SESSION['fullname'] ?> <i class="fas fa-angle-down"></i></span>
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
                <h1><i class="fas fa-shopping-cart"></i> Sifarişlər</h1>
                <div class="breadcrumb">
                    <a href="index.php">Dashboard</a> / 
                    <span>Sifarişlər</span>
                </div>
            </div>
            
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $messageType === 'error' ? 'danger' : 'success' ?>">
                    <i class="fas fa-<?= $messageType === 'error' ? 'exclamation-circle' : 'check-circle' ?>"></i>
                    <?= $message ?>
                </div>
            <?php endif; ?>
            
            <div class="card mb-4">
                <div class="card-body">
                    <h2 class="card-title"><i class="fas fa-filter"></i> Filtrlər</h2>
                    
                    <form action="" method="get" id="filterForm">
                        <div class="filter-container">
                            <div class="filter-item">
                                <label class="filter-label">Status:</label>
                                <select name="status" class="filter-select" onchange="document.getElementById('filterForm').submit()">
                                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Bütün Statuslar</option>
                                    <option value="new" <?= $status === 'new' ? 'selected' : '' ?>>Yeni</option>
                                    <option value="processing" <?= $status === 'processing' ? 'selected' : '' ?>>Hazırlanır</option>
                                    <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Hazır</option>
                                    <option value="delivered" <?= $status === 'delivered' ? 'selected' : '' ?>>Təhvil verilib</option>
                                    <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Ləğv edilib</option>
                                </select>
                            </div>
                            
                            <div class="filter-item">
                                <label class="filter-label">Başlanğıc tarix:</label>
                                <input type="date" name="date_start" class="filter-input" value="<?= $dateStart ?>">
                            </div>
                            
                            <div class="filter-item">
                                <label class="filter-label">Son tarix:</label>
                                <input type="date" name="date_end" class="filter-input" value="<?= $dateEnd ?>">
                            </div>
                            
                            <?php if (!empty($branches)): ?>
                                <div class="filter-item">
                                    <label class="filter-label">Filial:</label>
                                    <select name="branch_id" class="filter-select" onchange="document.getElementById('filterForm').submit()">
                                        <option value="0" <?= $branchId === 0 ? 'selected' : '' ?>>Bütün Filiallar</option>
                                        <?php foreach ($branches as $branch): ?>
                                            <option value="<?= $branch['id'] ?>" <?= $branchId === (int)$branch['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($branch['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($sellers)): ?>
                                <div class="filter-item">
                                    <label class="filter-label">Satıcı:</label>
                                    <select name="seller_id" class="filter-select" onchange="document.getElementById('filterForm').submit()">
                                        <option value="0" <?= $sellerId === 0 ? 'selected' : '' ?>>Bütün Satıcılar</option>
                                        <?php foreach ($sellers as $seller): ?>
                                            <option value="<?= $seller['id'] ?>" <?= $sellerId === (int)$seller['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($seller['fullname']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>
                            
                            <div class="filter-item">
                                <label class="filter-label">Axtar:</label>
                                <input type="text" name="search" class="filter-input" value="<?= htmlspecialchars($search) ?>" placeholder="Sifariş №, müştəri və ya barkod">
                            </div>
                            
                            <div class="filter-item">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter"></i> Tətbiq et
                                </button>
                                <a href="orders.php" class="btn btn-outline ml-2">
                                    <i class="fas fa-redo"></i> Sıfırla
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Sifarişlər (<?= $totalOrders ?>)</h2>
                    <div class="card-actions">
                        <a href="#" class="btn btn-sm btn-outline" onclick="window.print()">
                            <i class="fas fa-print"></i> Çap et
                        </a>
                        <a href="export-orders.php?<?= http_build_query($_GET) ?>" class="btn btn-sm btn-outline">
                            <i class="fas fa-file-excel"></i> Excel
                        </a>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover order-table">
                            <thead>
                                <tr>
                                    <th>Sifariş №</th>
                                    <th>Tarix</th>
                                    <th>Müştəri</th>
                                    <th>Telefon</th>
                                    <th>Satıcı</th>
                                    <th>Filial</th>
                                    <th>Məbləğ</th>
                                    <th>Qalıq Borc</th>
                                    <th>Status</th>
                                    <th>Əməliyyatlar</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($orders)): ?>
                                    <tr>
                                        <td colspan="10" class="text-center">Sifariş tapılmadı</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($orders as $order): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($order['order_number']) ?></td>
                                            <td><?= formatDate($order['order_date'], 'd.m.Y H:i') ?></td>
                                            <td><?= htmlspecialchars($order['customer_name']) ?></td>
                                            <td><?= htmlspecialchars($order['customer_phone']) ?></td>
                                            <td><?= htmlspecialchars($order['seller_name']) ?></td>
                                            <td><?= htmlspecialchars($order['branch_name']) ?></td>
                                            <td><?= formatMoney($order['total_amount']) ?></td>
                                            <td><?= formatMoney($order['remaining_amount']) ?></td>
                                            <td>
                                                <span class="status-badge badge-<?= $statusConfig[$order['order_status']]['color'] ?>">
                                                    <?= $statusConfig[$order['order_status']]['text'] ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group">
                                                    <a href="order-details.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-outline" title="Ətraflı">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <button type="button" class="btn btn-sm btn-outline" title="Statusu dəyiş" 
                                                            onclick="updateStatus(<?= $order['id'] ?>, '<?= $order['order_status'] ?>', '<?= htmlspecialchars($order['order_number']) ?>')">
                                                        <i class="fas fa-exchange-alt"></i>
                                                    </button>
                                                    <a href="../seller/order-print.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-outline" title="Çap et" target="_blank">
                                                        <i class="fas fa-print"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?status=<?= $status ?>&date_start=<?= $dateStart ?>&date_end=<?= $dateEnd ?>&branch_id=<?= $branchId ?>&seller_id=<?= $sellerId ?>&search=<?= urlencode($search) ?>&page=1" class="page-link">
                                    <i class="fas fa-angle-double-left"></i>
                                </a>
                                <a href="?status=<?= $status ?>&date_start=<?= $dateStart ?>&date_end=<?= $dateEnd ?>&branch_id=<?= $branchId ?>&seller_id=<?= $sellerId ?>&search=<?= urlencode($search) ?>&page=<?= $page - 1 ?>" class="page-link">
                                    <i class="fas fa-angle-left"></i>
                                </a>
                            <?php else: ?>
                                <span class="page-link disabled"><i class="fas fa-angle-double-left"></i></span>
                                <span class="page-link disabled"><i class="fas fa-angle-left"></i></span>
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
                                <a href="?status=<?= $status ?>&date_start=<?= $dateStart ?>&date_end=<?= $dateEnd ?>&branch_id=<?= $branchId ?>&seller_id=<?= $sellerId ?>&search=<?= urlencode($search) ?>&page=<?= $i ?>" class="page-link <?= $i === $page ? 'active' : '' ?>">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <a href="?status=<?= $status ?>&date_start=<?= $dateStart ?>&date_end=<?= $dateEnd ?>&branch_id=<?= $branchId ?>&seller_id=<?= $sellerId ?>&search=<?= urlencode($search) ?>&page=<?= $page + 1 ?>" class="page-link">
                                    <i class="fas fa-angle-right"></i>
                                </a>
                                <a href="?status=<?= $status ?>&date_start=<?= $dateStart ?>&date_end=<?= $dateEnd ?>&branch_id=<?= $branchId ?>&seller_id=<?= $sellerId ?>&search=<?= urlencode($search) ?>&page=<?= $totalPages ?>" class="page-link">
                                    <i class="fas fa-angle-double-right"></i>
                                </a>
                            <?php else: ?>
                                <span class="page-link disabled"><i class="fas fa-angle-right"></i></span>
                                <span class="page-link disabled"><i class="fas fa-angle-double-right"></i></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
    
    <!-- Update Status Modal -->
    <div class="modal fade" id="updateStatusModal" tabindex="-1" role="dialog" aria-labelledby="updateStatusModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="updateStatusModalLabel">Sifarişin Statusunu Dəyiş</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form action="" method="post">
                    <div class="modal-body">
                        <input type="hidden" name="update_status" value="1">
                        <input type="hidden" name="order_id" id="order_id">
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> <span id="order_number_display"></span> nömrəli sifariş üçün status dəyişikliyi
                        </div>
                        
                        <div class="form-group">
                            <label for="current_status" class="form-label">Cari Status</label>
                            <input type="text" id="current_status" class="form-control" readonly>
                        </div>
                        
                        <div class="form-group">
                            <label for="new_status" class="form-label">Yeni Status <span class="text-danger">*</span></label>
                            <select id="new_status" name="new_status" class="form-control" required>
                                <option value="">Seçin...</option>
                                <option value="new">Yeni</option>
                                <option value="processing">Hazırlanır</option>
                                <option value="completed">Hazır</option>
                                <option value="delivered">Təhvil verilib</option>
                                <option value="cancelled">Ləğv edilib</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="status_note" class="form-label">Qeyd</label>
                            <textarea id="status_note" name="status_note" class="form-control" rows="2"></textarea>
                            <div class="form-text">Bu qeyd daxili istifadə üçündür və müştəriyə göstərilmir</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Bağla</button>
                        <button type="submit" class="btn btn-primary">Yadda Saxla</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="app-footer">
        <div>&copy; <?= date('Y') ?> AlumPro.az - Bütün hüquqlar qorunur</div>
    </footer>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // User menu toggle
            const userInfo = document.querySelector('.user-info');
            userInfo.addEventListener('click', function() {
                this.classList.toggle('open');
            });
            
            // Apply filters automatically when date inputs change
            const dateInputs = document.querySelectorAll('input[type="date"]');
            dateInputs.forEach(input => {
                input.addEventListener('change', function() {
                    document.getElementById('filterForm').submit();
                });
            });
        });
        
        function updateStatus(orderId, currentStatus, orderNumber) {
            document.getElementById('order_id').value = orderId;
            document.getElementById('order_number_display').textContent = orderNumber;
            
            // Set current status with nice formatting
            let statusText = currentStatus;
            switch (currentStatus) {
                case 'new':
                    statusText = 'Yeni';
                    break;
                case 'processing':
                    statusText = 'Hazırlanır';
                    break;
                case 'completed':
                    statusText = 'Hazır';
                    break;
                case 'delivered':
                    statusText = 'Təhvil verilib';
                    break;
                case 'cancelled':
                    statusText = 'Ləğv edilib';
                    break;
            }
            document.getElementById('current_status').value = statusText;
            
            // Clear previous values
            document.getElementById('new_status').value = '';
            document.getElementById('status_note').value = '';
            
            $('#updateStatusModal').modal('show');
        }
    </script>
</body>
</html>
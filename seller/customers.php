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

// Check if we need to show the new customer form
$showNewForm = isset($_GET['action']) && $_GET['action'] === 'new';

// Process form submission for new customer
$formSuccess = false;
$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $fullname = trim($_POST['fullname'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    // Validate required fields
    if (empty($fullname) || empty($phone)) {
        $formError = 'Ad, soyad və telefon nömrəsi tələb olunur';
    } else {
        // Check if phone number already exists
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT id FROM customers WHERE phone = ?");
        $stmt->bind_param("s", $phone);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $formError = 'Bu telefon nömrəsi ilə müştəri artıq mövcuddur';
        } else {
            // Insert new customer
            $stmt = $conn->prepare("INSERT INTO customers (fullname, phone, email, address, notes, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssii", $fullname, $phone, $email, $address, $notes, $sellerId, $sellerId);
            
            if ($stmt->execute()) {
                $customerId = $conn->insert_id;
                $formSuccess = true;
                
                // Log activity
                logActivity($sellerId, 'create_customer', "Yeni müştəri yaradıldı: $fullname (ID: $customerId)");
                
                // Redirect to customer list or detail page
                header("Location: customer-view.php?id=$customerId&success=created");
                exit;
            } else {
                $formError = 'Müştəri yaradılarkən xəta baş verdi: ' . $conn->error;
            }
        }
    }
}

// Get customer list with pagination
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'name';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

$conn = getDBConnection();

// Build the query
$sqlCount = "SELECT COUNT(*) as total FROM customers";
$sql = "SELECT c.*, 
        COUNT(o.id) as orders_count, 
        SUM(o.total_amount) as total_spent 
        FROM customers c 
        LEFT JOIN orders o ON c.id = o.customer_id";

// Apply search filter
if (!empty($search)) {
    $searchTerm = '%' . $search . '%';
    $whereClause = " WHERE c.fullname LIKE ? OR c.phone LIKE ? OR c.email LIKE ?";
    $sqlCount .= $whereClause;
    $sql .= $whereClause;
}

// Group by clause
$sql .= " GROUP BY c.id";

// Apply sorting
switch ($sort) {
    case 'name':
        $sql .= " ORDER BY c.fullname ASC";
        break;
    case 'name_desc':
        $sql .= " ORDER BY c.fullname DESC";
        break;
    case 'date':
        $sql .= " ORDER BY c.created_at ASC";
        break;
    case 'date_desc':
        $sql .= " ORDER BY c.created_at DESC";
        break;
    case 'top':
        $sql .= " ORDER BY total_spent DESC";
        break;
    case 'debt':
        $sql .= " ORDER BY c.remaining_debt DESC";
        break;
    default:
        $sql .= " ORDER BY c.fullname ASC";
}

// Add limit for pagination
$sql .= " LIMIT ?, ?";

// Get total customers count
$totalCustomers = 0;
if (!empty($search)) {
    $stmt = $conn->prepare($sqlCount);
    $stmt->bind_param("sss", $searchTerm, $searchTerm, $searchTerm);
    $stmt->execute();
    $result = $stmt->get_result();
    $totalCustomers = $result->fetch_assoc()['total'];
} else {
    $result = $conn->query($sqlCount);
    $totalCustomers = $result->fetch_assoc()['total'];
}

// Get customers
$customers = [];
$stmt = $conn->prepare($sql);

if (!empty($search)) {
    $stmt->bind_param("sssii", $searchTerm, $searchTerm, $searchTerm, $offset, $perPage);
} else {
    $stmt->bind_param("ii", $offset, $perPage);
}

$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $customers[] = $row;
}

// Calculate total pages for pagination
$totalPages = ceil($totalCustomers / $perPage);

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
    <title>Müştərilər | AlumPro</title>
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

        /* Customer specific styles */
        .customers-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .customer-card {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
            transition: transform 0.3s;
        }
        
        .customer-card:hover {
            transform: translateY(-5px);
        }
        
        .customer-header {
            background: var(--primary-gradient);
            color: white;
            padding: 15px;
            position: relative;
        }
        
        .customer-name {
            font-size: 18px;
            font-weight: 500;
            margin-bottom: 5px;
        }
        
        .customer-id {
            font-size: 12px;
            opacity: 0.8;
        }
        
        .customer-actions {
            position: absolute;
            top: 15px;
            right: 15px;
            display: flex;
            gap: 8px;
        }
        
        .customer-action {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            transition: background 0.2s;
        }
        
        .customer-action:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        
        .customer-body {
            padding: 15px;
        }
        
        .customer-info {
            margin-bottom: 15px;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 8px;
        }
        
        .info-icon {
            width: 24px;
            margin-right: 10px;
            color: var(--primary-color);
        }
        
        .info-text {
            flex: 1;
            color: #4b5563;
        }
        
        .customer-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            padding-top: 15px;
            border-top: 1px solid #f3f4f6;
        }
        
        .stat-box {
            padding: 10px;
            background: #f9fafb;
            border-radius: 8px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 18px;
            font-weight: 700;
            color: var(--secondary-color);
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 12px;
            color: #6b7280;
        }
        
        .customer-footer {
            background: #f9fafb;
            padding: 15px;
            display: flex;
            gap: 10px;
        }
        
        .customer-btn {
            flex: 1;
            border: none;
            padding: 8px;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            transition: all 0.2s;
        }
        
        .btn-view {
            background: var(--primary-gradient);
            color: white;
        }
        
        .btn-view:hover {
            opacity: 0.9;
        }
        
        .btn-whatsapp {
            background: #25d366;
            color: white;
        }
        
        .btn-whatsapp:hover {
            opacity: 0.9;
        }
        
        .debt-indicator {
            position: absolute;
            top: -10px;
            right: -10px;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #ef4444;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 12px;
            border: 2px solid white;
        }
        
        /* Search and filter */
        .search-tools {
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
        
        .filter-tools {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .sort-select {
            padding: 10px 15px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            min-width: 180px;
        }
        
        .add-customer-btn {
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
        
        .add-customer-btn:hover {
            opacity: 0.9;
        }
        
        /* Form styles for new customer */
        .form-card {
            background: white;
            border-radius: 8px;
            box-shadow: var(--card-shadow);
            margin-bottom: 30px;
            overflow: hidden;
        }
        
        .form-header {
            background: var(--primary-gradient);
            color: white;
            padding: 20px;
        }
        
        .form-title {
            font-size: 20px;
            font-weight: 500;
            margin: 0;
        }
        
        .form-body {
            padding: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #374151;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 177, 90, 0.1);
        }
        
        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }
        
        .form-note {
            font-size: 12px;
            color: #6b7280;
            margin-top: 5px;
        }
        
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }
        
        .btn-cancel {
            padding: 10px 20px;
            border: 1px solid #e5e7eb;
            background: white;
            color: #374151;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .btn-cancel:hover {
            background: #f9fafb;
        }
        
        .btn-submit {
            padding: 10px 20px;
            border: none;
            background: var(--primary-gradient);
            color: white;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        
        .btn-submit:hover {
            opacity: 0.9;
        }
        
        .form-alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .alert-danger {
            background-color: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        
        .alert-success {
            background-color: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
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
        
        /* List view */
        .view-toggle {
            display: flex;
            gap: 10px;
        }
        
        .view-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: white;
            color: #374151;
            border: 1px solid #e5e7eb;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .view-btn:hover {
            background: #f3f4f6;
        }
        
        .view-btn.active {
            background: var(--primary-gradient);
            color: white;
            border-color: transparent;
        }
        
        .customers-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .customer-list-item {
            background: white;
            border-radius: 8px;
            box-shadow: var(--card-shadow);
            display: flex;
            align-items: center;
            padding: 15px;
            transition: transform 0.2s;
        }
        
        .customer-list-item:hover {
            transform: translateY(-3px);
        }
        
        .customer-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary-gradient);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 20px;
            margin-right: 15px;
        }
        
        .customer-list-info {
            flex: 1;
        }
        
        .customer-list-name {
            font-size: 16px;
            font-weight: 500;
            margin-bottom: 5px;
        }
        
        .customer-list-details {
            display: flex;
            gap: 15px;
            font-size: 14px;
            color: #6b7280;
        }
        
        .customer-list-actions {
            display: flex;
            gap: 10px;
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
                <a href="orders.php"><i class="fas fa-clipboard-list"></i> Sifarişlər</a>
                <a href="customers.php" class="active"><i class="fas fa-users"></i> Müştərilər</a>
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
                <h1><i class="fas fa-users"></i> Müştərilər</h1>
                <div class="breadcrumb">
                    <a href="index.php">Ana Səhifə</a> / <span>Müştərilər</span>
                </div>
            </div>

            <?php if ($showNewForm): ?>
                <!-- New Customer Form -->
                <div class="form-card">
                    <div class="form-header">
                        <h2 class="form-title">Yeni Müştəri</h2>
                    </div>
                    <div class="form-body">
                        <?php if (!empty($formError)): ?>
                            <div class="form-alert alert-danger">
                                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($formError) ?>
                            </div>
                        <?php endif; ?>

                        <form method="post" action="customers.php">
                            <input type="hidden" name="action" value="create">
                            
                            <div class="form-group">
                                <label for="fullname" class="form-label">Ad Soyad <span class="text-danger">*</span></label>
                                <input type="text" id="fullname" name="fullname" class="form-control" required value="<?= htmlspecialchars($_POST['fullname'] ?? '') ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="phone" class="form-label">Telefon <span class="text-danger">*</span></label>
                                <input type="text" id="phone" name="phone" class="form-control" required value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                                <div class="form-note">Format: +994 XX XXX XX XX</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="email" class="form-label">E-poçt</label>
                                <input type="email" id="email" name="email" class="form-control" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="address" class="form-label">Ünvan</label>
                                <input type="text" id="address" name="address" class="form-control" value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="notes" class="form-label">Əlavə qeydlər</label>
                                <textarea id="notes" name="notes" class="form-control"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                            </div>
                            
                            <div class="form-actions">
                                <a href="customers.php" class="btn-cancel">Ləğv et</a>
                                <button type="submit" class="btn-submit">Müştəri Yarat</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <!-- Search, Sort and Add Tools -->
                <div class="search-tools">
                    <form action="" method="get" class="search-form">
                        <input type="text" name="search" placeholder="Müştəri axtar..." class="search-input" value="<?= htmlspecialchars($search) ?>">
                        <button type="submit" class="search-btn"><i class="fas fa-search"></i></button>
                    </form>
                    
                    <div class="filter-tools">
                        <select name="sort" id="sort-select" class="sort-select" onchange="window.location.href='?sort='+this.value<?= !empty($search) ? '&search='.urlencode($search) : '' ?>">
                            <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Ad (A-Z)</option>
                            <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Ad (Z-A)</option>
                            <option value="date" <?= $sort === 'date' ? 'selected' : '' ?>>Köhnə müştərilər</option>
                            <option value="date_desc" <?= $sort === 'date_desc' ? 'selected' : '' ?>>Yeni müştərilər</option>
                            <option value="top" <?= $sort === 'top' ? 'selected' : '' ?>>Ən çox alış edənlər</option>
                            <option value="debt" <?= $sort === 'debt' ? 'selected' : '' ?>>Borcu olanlar</option>
                        </select>
                        
                        <div class="view-toggle">
                            <button id="grid-view-btn" class="view-btn active" title="Kart görünüşü">
                                <i class="fas fa-th"></i>
                            </button>
                            <button id="list-view-btn" class="view-btn" title="Siyahı görünüşü">
                                <i class="fas fa-list"></i>
                            </button>
                        </div>
                        
                        <a href="customers.php?action=new" class="add-customer-btn">
                            <i class="fas fa-plus"></i> Yeni Müştəri
                        </a>
                    </div>
                </div>

                <?php if (empty($customers)): ?>
                    <div style="text-align: center; padding: 50px 0; background: white; border-radius: 8px; box-shadow: var(--card-shadow);">
                        <i class="fas fa-search" style="font-size: 48px; color: #d1d5db; margin-bottom: 20px;"></i>
                        <h3>Heç bir müştəri tapılmadı</h3>
                        <p>Yeni müştəri əlavə etmək üçün "Yeni Müştəri" düyməsini sıxın</p>
                    </div>
                <?php else: ?>
                    <!-- Grid View (Default) -->
                    <div id="grid-view" class="customers-grid">
                        <?php foreach ($customers as $customer): ?>
                            <div class="customer-card">
                                <div class="customer-header">
                                    <div class="customer-name"><?= htmlspecialchars($customer['fullname']) ?></div>
                                    <div class="customer-id">ID: <?= $customer['id'] ?></div>
                                    
                                    <?php if ($customer['remaining_debt'] > 0): ?>
                                        <div class="debt-indicator" title="<?= formatMoney($customer['remaining_debt']) ?> borc">
                                            <i class="fas fa-exclamation"></i>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="customer-actions">
                                        <a href="customer-edit.php?id=<?= $customer['id'] ?>" class="customer-action" title="Düzəliş et">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="customer-orders.php?id=<?= $customer['id'] ?>" class="customer-action" title="Sifarişləri göstər">
                                            <i class="fas fa-clipboard-list"></i>
                                        </a>
                                    </div>
                                </div>
                                
                                <div class="customer-body">
                                    <div class="customer-info">
                                        <div class="info-row">
                                            <div class="info-icon"><i class="fas fa-phone"></i></div>
                                            <div class="info-text"><?= htmlspecialchars($customer['phone']) ?></div>
                                        </div>
                                        
                                        <?php if (!empty($customer['email'])): ?>
                                            <div class="info-row">
                                                <div class="info-icon"><i class="fas fa-envelope"></i></div>
                                                <div class="info-text"><?= htmlspecialchars($customer['email']) ?></div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($customer['address'])): ?>
                                            <div class="info-row">
                                                <div class="info-icon"><i class="fas fa-map-marker-alt"></i></div>
                                                <div class="info-text"><?= htmlspecialchars($customer['address']) ?></div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="info-row">
                                            <div class="info-icon"><i class="fas fa-calendar-alt"></i></div>
                                            <div class="info-text"><?= formatDate($customer['created_at']) ?></div>
                                        </div>
                                    </div>
                                    
                                    <div class="customer-stats">
                                        <div class="stat-box">
                                            <div class="stat-value"><?= $customer['orders_count'] ?? 0 ?></div>
                                            <div class="stat-label">Sifariş</div>
                                        </div>
                                        <div class="stat-box">
                                            <div class="stat-value"><?= formatMoney($customer['total_spent'] ?? 0, '') ?></div>
                                            <div class="stat-label">Ümumi alış</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="customer-footer">
                                    <a href="customer-view.php?id=<?= $customer['id'] ?>" class="customer-btn btn-view">
                                        <i class="fas fa-eye"></i> Ətraflı
                                    </a>
                                    
                                    <?php if (!empty($customer['phone']) && $customer['remaining_debt'] > 0): ?>
                                        <a href="send-debt-reminder.php?id=<?= $customer['id'] ?>" class="customer-btn btn-whatsapp">
                                            <i class="fab fa-whatsapp"></i> Borc Xatırlat
                                        </a>
                                    <?php else: ?>
                                        <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $customer['phone']) ?>" target="_blank" class="customer-btn btn-whatsapp">
                                            <i class="fab fa-whatsapp"></i> Yazın
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- List View (Hidden by Default) -->
                    <div id="list-view" class="customers-list" style="display: none;">
                        <?php foreach ($customers as $customer): ?>
                            <div class="customer-list-item">
                                <div class="customer-avatar">
                                    <?= strtoupper(substr($customer['fullname'], 0, 1)) ?>
                                </div>
                                
                                <div class="customer-list-info">
                                    <div class="customer-list-name">
                                        <?= htmlspecialchars($customer['fullname']) ?>
                                        <?php if ($customer['remaining_debt'] > 0): ?>
                                            <span class="stock-status stock-low" style="font-size: 11px; margin-left: 8px;">
                                                <?= formatMoney($customer['remaining_debt']) ?> borc
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="customer-list-details">
                                        <div><i class="fas fa-phone text-primary"></i> <?= htmlspecialchars($customer['phone']) ?></div>
                                        <div><i class="fas fa-clipboard-list text-primary"></i> <?= $customer['orders_count'] ?? 0 ?> sifariş</div>
                                        <div><i class="fas fa-money-bill-wave text-primary"></i> <?= formatMoney($customer['total_spent'] ?? 0) ?></div>
                                    </div>
                                </div>
                                
                                <div class="customer-list-actions">
                                    <a href="customer-view.php?id=<?= $customer['id'] ?>" class="btn-action" title="Ətraflı bax">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="customer-edit.php?id=<?= $customer['id'] ?>" class="btn-action" title="Düzəliş et">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="customer-orders.php?id=<?= $customer['id'] ?>" class="btn-action" title="Sifarişləri göstər">
                                        <i class="fas fa-clipboard-list"></i>
                                    </a>
                                    <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $customer['phone']) ?>" target="_blank" class="btn-action" title="WhatsApp">
                                        <i class="fab fa-whatsapp"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?page=1<?= !empty($search) ? '&search=' . htmlspecialchars($search) : '' ?><?= !empty($sort) ? '&sort=' . htmlspecialchars($sort) : '' ?>" class="pagination-link">
                                    <i class="fas fa-angle-double-left"></i>
                                </a>
                                <a href="?page=<?= $page - 1 ?><?= !empty($search) ? '&search=' . htmlspecialchars($search) : '' ?><?= !empty($sort) ? '&sort=' . htmlspecialchars($sort) : '' ?>" class="pagination-link">
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
                                <a href="?page=<?= $i ?><?= !empty($search) ? '&search=' . htmlspecialchars($search) : '' ?><?= !empty($sort) ? '&sort=' . htmlspecialchars($sort) : '' ?>" class="pagination-link <?= $i === $page ? 'active' : '' ?>">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <a href="?page=<?= $page + 1 ?><?= !empty($search) ? '&search=' . htmlspecialchars($search) : '' ?><?= !empty($sort) ? '&sort=' . htmlspecialchars($sort) : '' ?>" class="pagination-link">
                                    <i class="fas fa-angle-right"></i>
                                </a>
                                <a href="?page=<?= $totalPages ?><?= !empty($search) ? '&search=' . htmlspecialchars($search) : '' ?><?= !empty($sort) ? '&sort=' . htmlspecialchars($sort) : '' ?>" class="pagination-link">
                                    <i class="fas fa-angle-double-right"></i>
                                </a>
                            <?php else: ?>
                                <span class="pagination-link disabled"><i class="fas fa-angle-right"></i></span>
                                <span class="pagination-link disabled"><i class="fas fa-angle-double-right"></i></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
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
            // View toggling (grid/list)
            const gridViewBtn = document.getElementById('grid-view-btn');
            const listViewBtn = document.getElementById('list-view-btn');
            const gridView = document.getElementById('grid-view');
            const listView = document.getElementById('list-view');
            
            if (gridViewBtn && listViewBtn && gridView && listView) {
                gridViewBtn.addEventListener('click', function() {
                    gridViewBtn.classList.add('active');
                    listViewBtn.classList.remove('active');
                    gridView.style.display = 'grid';
                    listView.style.display = 'none';
                    localStorage.setItem('customerViewMode', 'grid');
                });
                
                listViewBtn.addEventListener('click', function() {
                    listViewBtn.classList.add('active');
                    gridViewBtn.classList.remove('active');
                    listView.style.display = 'flex';
                    gridView.style.display = 'none';
                    localStorage.setItem('customerViewMode', 'list');
                });
                
                // Load saved preference if available
                const savedViewMode = localStorage.getItem('customerViewMode');
                if (savedViewMode === 'list') {
                    listViewBtn.click();
                }
            }

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
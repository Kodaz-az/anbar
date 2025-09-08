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

// Check if order ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: orders.php');
    exit;
}

$orderId = (int)$_GET['id'];
$adminId = $_SESSION['user_id'];

// Get admin's information
$adminName = $_SESSION['fullname'];

// Get order details
$conn = getDBConnection();

// Check if company column exists in customers table
$hasCompanyColumn = false;
$checkCompanyColumn = "SHOW COLUMNS FROM customers LIKE 'company'";
$result = $conn->query($checkCompanyColumn);
if ($result && $result->num_rows > 0) {
    $hasCompanyColumn = true;
}

// Get order details
$sql = "SELECT o.*, b.name as branch_name, u.fullname as seller_name, c.fullname as customer_name, 
        c.phone as customer_phone, c.address as customer_address";

// Only include company column if it exists
if ($hasCompanyColumn) {
    $sql .= ", c.company as customer_company";
}

$sql .= " FROM orders o
        JOIN branches b ON o.branch_id = b.id
        JOIN users u ON o.seller_id = u.id
        JOIN customers c ON o.customer_id = c.id
        WHERE o.id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: orders.php');
    exit;
}

$order = $result->fetch_assoc();

// Check if order can be edited (only new and processing orders can be edited)
$canEdit = in_array($order['order_status'], ['new', 'processing']);

// Get all customers for dropdown
$sql = "SELECT c.id, c.fullname, c.phone";

// Add company column if it exists
if ($hasCompanyColumn) {
    $sql .= ", c.company";
}

$sql .= " FROM customers c ORDER BY c.fullname";

$stmt = $conn->prepare($sql);
$stmt->execute();
$customersResult = $stmt->get_result();
$customers = $customersResult->fetch_all(MYSQLI_ASSOC);

// Get all sellers for dropdown
$sql = "SELECT id, fullname FROM users WHERE role = 'seller' AND status = 'active' ORDER BY fullname";
$stmt = $conn->prepare($sql);
$stmt->execute();
$sellersResult = $stmt->get_result();
$sellers = $sellersResult->fetch_all(MYSQLI_ASSOC);

// Get all branches for dropdown
$sql = "SELECT id, name FROM branches WHERE status = 'active' ORDER BY name";
$stmt = $conn->prepare($sql);
$stmt->execute();
$branchesResult = $stmt->get_result();
$branches = $branchesResult->fetch_all(MYSQLI_ASSOC);

// Get order profiles
$sql = "SELECT * FROM order_profiles WHERE order_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$profilesResult = $stmt->get_result();
$profiles = $profilesResult->fetch_all(MYSQLI_ASSOC);

// Get order glass
$sql = "SELECT * FROM order_glass WHERE order_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$glassResult = $stmt->get_result();
$glass = $glassResult->fetch_all(MYSQLI_ASSOC);

// Get order pricing details
$sql = "SELECT * FROM order_pricing WHERE order_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$pricingResult = $stmt->get_result();
$pricing = $pricingResult->fetch_assoc();

// Process form submission
$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'update_order') {
        // Begin transaction
        $conn->begin_transaction();
        
        try {
            // Update order details
            $customerId = $_POST['customer_id'];
            $sellerId = $_POST['seller_id'];
            $branchId = $_POST['branch_id'];
            $orderStatus = $_POST['order_status'];
            $deliveryDate = !empty($_POST['delivery_date']) ? $_POST['delivery_date'] : null;
            $totalAmount = $_POST['total_amount'];
            $advancePayment = $_POST['advance_payment'];
            $remainingAmount = $totalAmount - $advancePayment;
            $assemblyFee = $_POST['assembly_fee'] ?? 0;
            $sellerNotes = $_POST['seller_notes'] ?? '';
            $adminNotes = $_POST['admin_notes'] ?? '';
            $drawingImage = $_POST['drawing_image'] ?? null;
            
            $sql = "UPDATE orders SET 
                    customer_id = ?,
                    seller_id = ?,
                    branch_id = ?,
                    order_status = ?,
                    delivery_date = ?,
                    total_amount = ?,
                    advance_payment = ?,
                    remaining_amount = ?,
                    assembly_fee = ?,
                    seller_notes = ?,
                    admin_notes = ?,
                    drawing_image = ?,
                    updated_at = NOW()
                    WHERE id = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iiissdddsssi", $customerId, $sellerId, $branchId, $orderStatus, $deliveryDate, $totalAmount, $advancePayment, 
                           $remainingAmount, $assemblyFee, $sellerNotes, $adminNotes, $drawingImage, $orderId);
            $stmt->execute();
            
            // Update customer total_orders, total_payment, advance_payment, and remaining_debt
            $sql = "UPDATE customers 
                    SET total_orders = (SELECT COUNT(*) FROM orders WHERE customer_id = ?),
                        total_payment = (SELECT SUM(total_amount) FROM orders WHERE customer_id = ?),
                        advance_payment = (SELECT SUM(advance_payment) FROM orders WHERE customer_id = ?),
                        remaining_debt = (SELECT SUM(remaining_amount) FROM orders WHERE customer_id = ?)
                    WHERE id = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iiiii", $customerId, $customerId, $customerId, $customerId, $customerId);
            $stmt->execute();
            
            // Delete existing profiles and re-insert updated ones
            $sql = "DELETE FROM order_profiles WHERE order_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $orderId);
            $stmt->execute();
            
            // Process profiles
            if (!empty($_POST['profiles'])) {
                $profileData = json_decode($_POST['profiles'], true);
                
                if ($profileData && is_array($profileData)) {
                    $sql = "INSERT INTO order_profiles (order_id, profile_type, width, height, quantity, hinge_count, notes) 
                            VALUES (?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    
                    foreach ($profileData as $profile) {
                        $stmt->bind_param("isddiss", $orderId, $profile['type'], $profile['width'], $profile['height'], 
                                      $profile['quantity'], $profile['hingeCount'], $profile['notes']);
                        $stmt->execute();
                    }
                }
            }
            
            // Delete existing glass and re-insert updated ones
            $sql = "DELETE FROM order_glass WHERE order_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $orderId);
            $stmt->execute();
            
            // Process glass
            if (!empty($_POST['glass'])) {
                $glassData = json_decode($_POST['glass'], true);
                
                if ($glassData && is_array($glassData)) {
                    $sql = "INSERT INTO order_glass (order_id, glass_type, width, height, quantity, offset_mm, area) 
                            VALUES (?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    
                    foreach ($glassData as $glassItem) {
                        // Calculate area in square meters
                        $area = ($glassItem['width'] * $glassItem['height']) / 10000; // Convert from cm² to m²
                        
                        $stmt->bind_param("isddid", $orderId, $glassItem['type'], $glassItem['width'], $glassItem['height'], 
                                      $glassItem['quantity'], $glassItem['offset'], $area);
                        $stmt->execute();
                    }
                }
            }
            
            // Update or insert pricing information
            if (!empty($_POST['pricing'])) {
                $pricingData = json_decode($_POST['pricing'], true);
                
                if ($pricingData && is_array($pricingData)) {
                    // Check if pricing exists for this order
                    $checkSql = "SELECT id FROM order_pricing WHERE order_id = ?";
                    $checkStmt = $conn->prepare($checkSql);
                    $checkStmt->bind_param("i", $orderId);
                    $checkStmt->execute();
                    $checkResult = $checkStmt->get_result();
                    
                    if ($checkResult->num_rows > 0) {
                        // Update existing pricing
                        $sql = "UPDATE order_pricing SET 
                                side_profiles_length = ?, side_profiles_price = ?,
                                handle_profiles_length = ?, handle_profiles_price = ?,
                                glass_area = ?, glass_price = ?,
                                hinge_count = ?, hinge_price = ?,
                                connection_count = ?, connection_price = ?,
                                mechanism_count = ?, mechanism_price = ?,
                                transport_fee = ?, assembly_fee = ?,
                                updated_at = NOW()
                                WHERE order_id = ?";
                        
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("ddddddiiiiiiddi", 
                            $pricingData['sideProfilesLength'], $pricingData['sideProfilesPrice'],
                            $pricingData['handleProfilesLength'], $pricingData['handleProfilesPrice'],
                            $pricingData['glassArea'], $pricingData['glassPrice'],
                            $pricingData['hingeCount'], $pricingData['hingePrice'],
                            $pricingData['connectionCount'], $pricingData['connectionPrice'],
                            $pricingData['mechanismCount'], $pricingData['mechanismPrice'],
                            $pricingData['transportFee'], $pricingData['assemblyFee'],
                            $orderId
                        );
                        $stmt->execute();
                    } else {
                        // Insert new pricing
                        $sql = "INSERT INTO order_pricing (
                                order_id, side_profiles_length, side_profiles_price,
                                handle_profiles_length, handle_profiles_price,
                                glass_area, glass_price,
                                hinge_count, hinge_price,
                                connection_count, connection_price,
                                mechanism_count, mechanism_price,
                                transport_fee, assembly_fee
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("iddddddiiiiiidd", 
                            $orderId,
                            $pricingData['sideProfilesLength'], $pricingData['sideProfilesPrice'],
                            $pricingData['handleProfilesLength'], $pricingData['handleProfilesPrice'],
                            $pricingData['glassArea'], $pricingData['glassPrice'],
                            $pricingData['hingeCount'], $pricingData['hingePrice'],
                            $pricingData['connectionCount'], $pricingData['connectionPrice'],
                            $pricingData['mechanismCount'], $pricingData['mechanismPrice'],
                            $pricingData['transportFee'], $pricingData['assemblyFee']
                        );
                        $stmt->execute();
                    }
                }
            }
            
            // Log activity
            logActivity($adminId, 'update_order', "Admin updated order #" . $order['order_number']);
            
            // Commit transaction
            $conn->commit();
            
            $successMessage = 'Sifariş uğurla yeniləndi';
            
            // Refresh order data
            $sql = "SELECT o.*, b.name as branch_name, u.fullname as seller_name, c.fullname as customer_name, 
                    c.phone as customer_phone, c.address as customer_address";
            
            // Only include company column if it exists
            if ($hasCompanyColumn) {
                $sql .= ", c.company as customer_company";
            }
            
            $sql .= " FROM orders o
                    JOIN branches b ON o.branch_id = b.id
                    JOIN users u ON o.seller_id = u.id
                    JOIN customers c ON o.customer_id = c.id
                    WHERE o.id = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $orderId);
            $stmt->execute();
            $result = $stmt->get_result();
            $order = $result->fetch_assoc();
            
            // Refresh profiles
            $sql = "SELECT * FROM order_profiles WHERE order_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $orderId);
            $stmt->execute();
            $profilesResult = $stmt->get_result();
            $profiles = $profilesResult->fetch_all(MYSQLI_ASSOC);
            
            // Refresh glass
            $sql = "SELECT * FROM order_glass WHERE order_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $orderId);
            $stmt->execute();
            $glassResult = $stmt->get_result();
            $glass = $glassResult->fetch_all(MYSQLI_ASSOC);
            
            // Refresh pricing
            $sql = "SELECT * FROM order_pricing WHERE order_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $orderId);
            $stmt->execute();
            $pricingResult = $stmt->get_result();
            $pricing = $pricingResult->fetch_assoc();
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $errorMessage = 'Xəta baş verdi: ' . $e->getMessage();
            
            // Log error
            error_log('Error updating order #' . $order['order_number'] . ': ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sifariş Düzəlişi #<?= $order['order_number'] ?> | AlumPro Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .order-number {
            font-size: 24px;
            font-weight: 500;
            color: var(--primary-color);
        }
        
        .order-actions {
            display: flex;
            gap: 10px;
        }
        
        .order-container {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .form-section {
            margin-bottom: 30px;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 500;
            margin-bottom: 15px;
            padding-bottom: 5px;
            border-bottom: 1px solid #e5e7eb;
            color: var(--primary-color);
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #4b5563;
        }
        
        .form-control {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 2px rgba(30, 177, 90, 0.2);
        }
        
        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }
        
        .form-row {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .form-col {
            flex: 1;
        }
        
        .action-buttons {
            text-align: right;
            margin-top: 20px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .table-container {
            margin-bottom: 20px;
            overflow-x: auto;
        }
        
        .order-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .order-table th {
            background-color: #f9fafb;
            padding: 10px;
            text-align: left;
            font-weight: 500;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .order-table td {
            padding: 10px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .order-table tr:last-child td {
            border-bottom: none;
        }
        
        .order-table .actions {
            display: flex;
            gap: 5px;
            justify-content: flex-end;
        }
        
        .order-table .action-btn {
            background: none;
            border: none;
            cursor: pointer;
            color: #6b7280;
            padding: 2px;
        }
        
        .order-table .action-btn:hover {
            color: var(--primary-color);
        }
        
        .order-table .delete-btn:hover {
            color: #ef4444;
        }
        
        .add-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background-color: #f3f4f6;
            border: 1px solid #d1d5db;
            padding: 6px 12px;
            border-radius: 4px;
            color: #4b5563;
            font-size: 14px;
            cursor: pointer;
        }
        
        .add-btn:hover {
            background-color: #e5e7eb;
        }
        
        .item-form {
            display: none;
            padding: 15px;
            background-color: #f9fafb;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        
        .item-form.active {
            display: block;
        }
        
        .input-group {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .input-group .form-control {
            flex: 1;
        }
        
        .summary-section {
            background-color: #f9fafb;
            padding: 15px;
            border-radius: 4px;
            margin-top: 20px;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        
        .summary-label {
            font-weight: 500;
        }
        
        .summary-value {
            font-weight: 700;
            text-align: right;
        }
        
        .summary-total {
            font-size: 18px;
            color: var(--primary-color);
            padding-top: 10px;
            margin-top: 10px;
            border-top: 1px solid #e5e7eb;
        }
        
        .drawing-canvas-container {
            margin-top: 15px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .drawing-tools {
            padding: 10px;
            background-color: #f9fafb;
            display: flex;
            gap: 10px;
            border-bottom: 1px solid #d1d5db;
        }
        
        .tool-btn {
            background: none;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            padding: 5px 10px;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .tool-btn:hover {
            background-color: #e5e7eb;
        }
        
        .tool-btn.active {
            background-color: #e5e7eb;
            font-weight: 500;
        }
        
        #drawingCanvas {
            background-color: white;
            cursor: crosshair;
            width: 100%;
            height: 400px;
        }
        
        .alert {
            padding: 12px 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .alert-success {
            background-color: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        
        .alert-error {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                flex-direction: column;
                gap: 15px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .order-actions {
                flex-direction: column;
            }
            
            .order-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .order-actions {
                width: 100%;
            }
            
            .order-actions .btn {
                width: 100%;
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
                <span><?= $adminName ?> <i class="fas fa-angle-down"></i></span>
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
                <h1><i class="fas fa-edit"></i> Sifariş Düzəlişi</h1>
                <div class="breadcrumb">
                    <a href="index.php">Dashboard</a> / 
                    <a href="orders.php">Sifarişlər</a> / 
                    <span>Sifariş Düzəlişi #<?= $order['order_number'] ?></span>
                </div>
            </div>
            
            <!-- Order Header -->
            <div class="order-header">
                <div class="order-number">Sifariş #<?= $order['order_number'] ?></div>
                
                <div class="order-actions">
                    <a href="order-print.php?id=<?= $orderId ?>" class="btn btn-outline" target="_blank">
                        <i class="fas fa-print"></i> Çap et
                    </a>
                    <a href="orders.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Geri qayıt
                    </a>
                </div>
            </div>
            
            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= $successMessage ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?= $errorMessage ?>
                </div>
            <?php endif; ?>
            
            <!-- Order Form -->
            <form id="orderForm" method="post" action="">
                <input type="hidden" name="action" value="update_order">
                <input type="hidden" id="drawingImage" name="drawing_image" value="<?= $order['drawing_image'] ?? '' ?>">
                <input type="hidden" id="profilesData" name="profiles" value="">
                <input type="hidden" id="glassData" name="glass" value="">
                <input type="hidden" id="pricingData" name="pricing" value="">
                
                <div class="order-container">
                    <div class="form-section">
                        <div class="section-title">Ümumi Məlumat</div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="customer_id">Müştəri</label>
                                <select class="form-control" id="customer_id" name="customer_id">
                                    <?php foreach ($customers as $customer): ?>
                                        <option value="<?= $customer['id'] ?>" <?= $customer['id'] == $order['customer_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($customer['fullname']) ?> 
                                            <?php if ($hasCompanyColumn && !empty($customer['company'])): ?> 
                                                (<?= htmlspecialchars($customer['company']) ?>)
                                            <?php endif; ?>
                                            - <?= htmlspecialchars($customer['phone']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="seller_id">Satıcı</label>
                                <select class="form-control" id="seller_id" name="seller_id">
                                    <?php foreach ($sellers as $seller): ?>
                                        <option value="<?= $seller['id'] ?>" <?= $seller['id'] == $order['seller_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($seller['fullname']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="branch_id">Filial</label>
                                <select class="form-control" id="branch_id" name="branch_id">
                                    <?php foreach ($branches as $branch): ?>
                                        <option value="<?= $branch['id'] ?>" <?= $branch['id'] == $order['branch_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($branch['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="order_status">Status</label>
                                <select class="form-control" id="order_status" name="order_status">
                                    <option value="new" <?= $order['order_status'] === 'new' ? 'selected' : '' ?>>Yeni</option>
                                    <option value="processing" <?= $order['order_status'] === 'processing' ? 'selected' : '' ?>>İşlənir</option>
                                    <option value="completed" <?= $order['order_status'] === 'completed' ? 'selected' : '' ?>>Hazırdır</option>
                                    <option value="delivered" <?= $order['order_status'] === 'delivered' ? 'selected' : '' ?>>Təhvil verilib</option>
                                    <option value="cancelled" <?= $order['order_status'] === 'cancelled' ? 'selected' : '' ?>>Ləğv edilib</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="delivery_date">Təhvil tarixi</label>
                                <input type="date" class="form-control" id="delivery_date" name="delivery_date" 
                                       value="<?= !empty($order['delivery_date']) ? date('Y-m-d', strtotime($order['delivery_date'])) : '' ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="order_date">Sifariş tarixi</label>
                                <input type="text" class="form-control" id="order_date" value="<?= date('d.m.Y H:i', strtotime($order['order_date'])) ?>" disabled>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <div class="section-title">Profillər</div>
                        
                        <div class="table-container">
                            <table class="order-table" id="profilesTable">
                                <thead>
                                    <tr>
                                        <th>Profil növü</th>
                                        <th>En (cm)</th>
                                        <th>Hündürlük (cm)</th>
                                        <th>Say</th>
                                        <th>Petlə sayı</th>
                                        <th>Qeyd</th>
                                        <th>Əməliyyatlar</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($profiles)): ?>
                                        <tr id="noProfilesRow">
                                            <td colspan="7" class="text-center">Profil məlumatı yoxdur</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($profiles as $index => $profile): ?>
                                            <tr data-index="<?= $index ?>" class="profile-row">
                                                <td><?= htmlspecialchars($profile['profile_type']) ?></td>
                                                <td><?= $profile['width'] ?></td>
                                                <td><?= $profile['height'] ?></td>
                                                <td><?= $profile['quantity'] ?></td>
                                                <td><?= $profile['hinge_count'] ?></td>
                                                <td><?= htmlspecialchars($profile['notes'] ?? '') ?></td>
                                                <td class="actions">
                                                    <button type="button" class="action-btn edit-profile" title="Düzəliş et">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button type="button" class="action-btn delete-btn delete-profile" title="Sil">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="item-form" id="profileForm">
                            <div class="form-row">
                                <div class="form-col">
                                    <label class="form-label" for="profileType">Profil növü</label>
                                    <input type="text" class="form-control" id="profileType">
                                </div>
                                <div class="form-col">
                                    <label class="form-label" for="profileWidth">En (cm)</label>
                                    <input type="number" class="form-control" id="profileWidth" step="0.1" min="0">
                                </div>
                                <div class="form-col">
                                    <label class="form-label" for="profileHeight">Hündürlük (cm)</label>
                                    <input type="number" class="form-control" id="profileHeight" step="0.1" min="0">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-col">
                                    <label class="form-label" for="profileQuantity">Say</label>
                                    <input type="number" class="form-control" id="profileQuantity" min="1" value="1">
                                </div>
                                <div class="form-col">
                                    <label class="form-label" for="profileHingeCount">Petlə sayı</label>
                                    <input type="number" class="form-control" id="profileHingeCount" min="0" value="0">
                                </div>
                                <div class="form-col">
                                    <label class="form-label" for="profileNotes">Qeyd</label>
                                    <input type="text" class="form-control" id="profileNotes">
                                </div>
                            </div>
                            <div class="form-row" style="justify-content: flex-end;">
                                <input type="hidden" id="profileIndex" value="-1">
                                <button type="button" class="btn btn-outline" id="cancelProfileBtn">İmtina</button>
                                <button type="button" class="btn btn-primary" id="saveProfileBtn">Əlavə et</button>
                            </div>
                        </div>
                        
                        <button type="button" class="add-btn" id="addProfileBtn">
                            <i class="fas fa-plus"></i> Profil əlavə et
                        </button>
                    </div>
                    
                    <div class="form-section">
                        <div class="section-title">Şüşələr</div>
                        
                        <div class="table-container">
                            <table class="order-table" id="glassTable">
                                <thead>
                                    <tr>
                                        <th>Şüşə növü</th>
                                        <th>En (cm)</th>
                                        <th>Hündürlük (cm)</th>
                                        <th>Say</th>
                                        <th>Offset (mm)</th>
                                        <th>Sahə (m²)</th>
                                        <th>Əməliyyatlar</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($glass)): ?>
                                        <tr id="noGlassRow">
                                            <td colspan="7" class="text-center">Şüşə məlumatı yoxdur</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($glass as $index => $glassItem): ?>
                                            <tr data-index="<?= $index ?>" class="glass-row">
                                                <td><?= htmlspecialchars($glassItem['glass_type']) ?></td>
                                                <td><?= $glassItem['width'] ?></td>
                                                <td><?= $glassItem['height'] ?></td>
                                                <td><?= $glassItem['quantity'] ?></td>
                                                <td><?= $glassItem['offset_mm'] ?></td>
                                                <td><?= $glassItem['area'] ?></td>
                                                <td class="actions">
                                                    <button type="button" class="action-btn edit-glass" title="Düzəliş et">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button type="button" class="action-btn delete-btn delete-glass" title="Sil">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="item-form" id="glassForm">
                            <div class="form-row">
                                <div class="form-col">
                                    <label class="form-label" for="glassType">Şüşə növü</label>
                                    <input type="text" class="form-control" id="glassType">
                                </div>
                                <div class="form-col">
                                    <label class="form-label" for="glassWidth">En (cm)</label>
                                    <input type="number" class="form-control" id="glassWidth" step="0.1" min="0">
                                </div>
                                <div class="form-col">
                                    <label class="form-label" for="glassHeight">Hündürlük (cm)</label>
                                    <input type="number" class="form-control" id="glassHeight" step="0.1" min="0">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-col">
                                    <label class="form-label" for="glassQuantity">Say</label>
                                    <input type="number" class="form-control" id="glassQuantity" min="1" value="1">
                                </div>
                                <div class="form-col">
                                    <label class="form-label" for="glassOffset">Offset (mm)</label>
                                    <input type="number" class="form-control" id="glassOffset" min="0" value="0">
                                </div>
                                <div class="form-col">
                                    <label class="form-label" for="glassArea">Sahə (m²)</label>
                                    <input type="text" class="form-control" id="glassArea" readonly>
                                </div>
                            </div>
                            <div class="form-row" style="justify-content: flex-end;">
                                <input type="hidden" id="glassIndex" value="-1">
                                <button type="button" class="btn btn-outline" id="cancelGlassBtn">İmtina</button>
                                <button type="button" class="btn btn-primary" id="saveGlassBtn">Əlavə et</button>
                            </div>
                        </div>
                        
                        <button type="button" class="add-btn" id="addGlassBtn">
                            <i class="fas fa-plus"></i> Şüşə əlavə et
                        </button>
                    </div>
                    
                    <div class="form-section">
                        <div class="section-title">Qiymətlər</div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="sideProfilesLength">Yan profillər (m)</label>
                                <input type="number" class="form-control" id="sideProfilesLength" step="0.01" min="0" 
                                       value="<?= $pricing['side_profiles_length'] ?? 0 ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="sideProfilesPrice">Yan profil qiyməti</label>
                                <input type="number" class="form-control" id="sideProfilesPrice" step="0.01" min="0" 
                                       value="<?= $pricing['side_profiles_price'] ?? 0 ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="handleProfilesLength">Tutacaq profillər (m)</label>
                                <input type="number" class="form-control" id="handleProfilesLength" step="0.01" min="0" 
                                       value="<?= $pricing['handle_profiles_length'] ?? 0 ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="handleProfilesPrice">Tutacaq profil qiyməti</label>
                                <input type="number" class="form-control" id="handleProfilesPrice" step="0.01" min="0" 
                                       value="<?= $pricing['handle_profiles_price'] ?? 0 ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="glassAreaTotal">Şüşə sahəsi (m²)</label>
                                <input type="number" class="form-control" id="glassAreaTotal" step="0.01" min="0" 
                                       value="<?= $pricing['glass_area'] ?? 0 ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="glassPrice">Şüşə qiyməti</label>
                                <input type="number" class="form-control" id="glassPrice" step="0.01" min="0" 
                                       value="<?= $pricing['glass_price'] ?? 0 ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="hingeCountTotal">Petlə sayı</label>
                                <input type="number" class="form-control" id="hingeCountTotal" min="0" 
                                       value="<?= $pricing['hinge_count'] ?? 0 ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="hingePrice">Petlə qiyməti</label>
                                <input type="number" class="form-control" id="hingePrice" step="0.01" min="0" 
                                       value="<?= $pricing['hinge_price'] ?? 0 ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="connectionCount">Birləşmə sayı</label>
                                <input type="number" class="form-control" id="connectionCount" min="0" 
                                       value="<?= $pricing['connection_count'] ?? 0 ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="connectionPrice">Birləşmə qiyməti</label>
                                <input type="number" class="form-control" id="connectionPrice" step="0.01" min="0" 
                                       value="<?= $pricing['connection_price'] ?? 0 ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="mechanismCount">Mexanizm sayı</label>
                                <input type="number" class="form-control" id="mechanismCount" min="0" 
                                       value="<?= $pricing['mechanism_count'] ?? 0 ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="mechanismPrice">Mexanizm qiyməti</label>
                                <input type="number" class="form-control" id="mechanismPrice" step="0.01" min="0" 
                                       value="<?= $pricing['mechanism_price'] ?? 0 ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="transportFee">Nəqliyyat xərci</label>
                                <input type="number" class="form-control" id="transportFee" step="0.01" min="0" 
                                       value="<?= $pricing['transport_fee'] ?? 0 ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="assembly_fee">Quraşdırma xərci</label>
                                <input type="number" class="form-control" id="assembly_fee" name="assembly_fee" step="0.01" min="0" 
                                       value="<?= $order['assembly_fee'] ?>">
                            </div>
                        </div>
                        
                        <div class="summary-section">
                            <div class="summary-row">
                                <div class="summary-label">Yan profillər:</div>
                                <div class="summary-value" id="sideProfilesTotal">
                                    <?= formatMoney(($pricing['side_profiles_length'] ?? 0) * ($pricing['side_profiles_price'] ?? 0), '') ?> AZN
                                </div>
                            </div>
                            <div class="summary-row">
                                <div class="summary-label">Tutacaq profillər:</div>
                                <div class="summary-value" id="handleProfilesTotal">
                                    <?= formatMoney(($pricing['handle_profiles_length'] ?? 0) * ($pricing['handle_profiles_price'] ?? 0), '') ?> AZN
                                </div>
                            </div>
                            <div class="summary-row">
                                <div class="summary-label">Şüşə:</div>
                                <div class="summary-value" id="glassTotalPrice">
                                    <?= formatMoney(($pricing['glass_area'] ?? 0) * ($pricing['glass_price'] ?? 0), '') ?> AZN
                                </div>
                            </div>
                            <div class="summary-row">
                                <div class="summary-label">Petlələr:</div>
                                <div class="summary-value" id="hingeTotalPrice">
                                    <?= formatMoney(($pricing['hinge_count'] ?? 0) * ($pricing['hinge_price'] ?? 0), '') ?> AZN
                                </div>
                            </div>
                            <div class="summary-row">
                                <div class="summary-label">Birləşmələr:</div>
                                <div class="summary-value" id="connectionTotalPrice">
                                    <?= formatMoney(($pricing['connection_count'] ?? 0) * ($pricing['connection_price'] ?? 0), '') ?> AZN
                                </div>
                            </div>
                            <div class="summary-row">
                                <div class="summary-label">Mexanizmlər:</div>
                                <div class="summary-value" id="mechanismTotalPrice">
                                    <?= formatMoney(($pricing['mechanism_count'] ?? 0) * ($pricing['mechanism_price'] ?? 0), '') ?> AZN
                                </div>
                            </div>
                            <div class="summary-row">
                                <div class="summary-label">Nəqliyyat:</div>
                                <div class="summary-value" id="transportTotal">
                                    <?= formatMoney($pricing['transport_fee'] ?? 0, '') ?> AZN
                                </div>
                            </div>
                            <div class="summary-row">
                                <div class="summary-label">Quraşdırma:</div>
                                <div class="summary-value" id="assemblyTotal">
                                    <?= formatMoney($order['assembly_fee'], '') ?> AZN
                                </div>
                            </div>
                            <div class="summary-row summary-total">
                                <div class="summary-label">Ümumi məbləğ:</div>
                                <div class="summary-value" id="totalAmount">
                                    <?= formatMoney($order['total_amount'], '') ?> AZN
                                </div>
                            </div>
                            <input type="hidden" id="total_amount" name="total_amount" value="<?= $order['total_amount'] ?>">
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <div class="section-title">Ödəniş məlumatları</div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="advance_payment">Avans ödəniş</label>
                                <input type="number" class="form-control" id="advance_payment" name="advance_payment" step="0.01" min="0" 
                                       value="<?= $order['advance_payment'] ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="remaining_amount">Qalıq məbləğ</label>
                                <input type="text" class="form-control" id="remaining_amount" value="<?= $order['remaining_amount'] ?>" readonly>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <div class="section-title">Qeydlər</div>
                        
                        <div class="form-row">
                            <div class="form-col">
                                <label class="form-label" for="initial_note">Müştəri qeydi</label>
                                <textarea class="form-control" id="initial_note" readonly><?= $order['initial_note'] ?? '' ?></textarea>
                            </div>
                            <div class="form-col">
                                <label class="form-label" for="seller_notes">Satıcı qeydi</label>
                                <textarea class="form-control" id="seller_notes" name="seller_notes"><?= $order['seller_notes'] ?? '' ?></textarea>
                            </div>
                            <div class="form-col">
                                <label class="form-label" for="admin_notes">Admin qeydi</label>
                                <textarea class="form-control" id="admin_notes" name="admin_notes"><?= $order['admin_notes'] ?? '' ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <div class="section-title">Eskiz</div>
                        
                        <div class="drawing-canvas-container">
                            <div class="drawing-tools">
                                <button type="button" class="tool-btn active" id="pencilTool">
                                    <i class="fas fa-pencil-alt"></i> Qələm
                                </button>
                                <button type="button" class="tool-btn" id="lineTool">
                                    <i class="fas fa-slash"></i> Xətt
                                </button>
                                <button type="button" class="tool-btn" id="rectTool">
                                    <i class="far fa-square"></i> Düzbucaqlı
                                </button>
                                <button type="button" class="tool-btn" id="circleTool">
                                    <i class="far fa-circle"></i> Dairə
                                </button>
                                <button type="button" class="tool-btn" id="textTool">
                                    <i class="fas fa-font"></i> Mətn
                                </button>
                                <button type="button" class="tool-btn" id="eraserTool">
                                    <i class="fas fa-eraser"></i> Silgi
                                </button>
                                <button type="button" class="tool-btn" id="clearCanvas">
                                    <i class="fas fa-trash-alt"></i> Təmizlə
                                </button>
                            </div>
                            <canvas id="drawingCanvas"></canvas>
                        </div>
                    </div>
                    
                    <div class="action-buttons">
                        <a href="orders.php" class="btn btn-outline">Ləğv et</a>
                        <button type="submit" class="btn btn-primary">Yadda saxla</button>
                    </div>
                </div>
            </form>
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
            
            // Profile management
            const profilesData = <?= json_encode($profiles) ?>;
            let profilesList = Array.isArray(profilesData) ? [...profilesData] : [];
            const profilesTable = document.getElementById('profilesTable');
            const profileForm = document.getElementById('profileForm');
            const addProfileBtn = document.getElementById('addProfileBtn');
            const saveProfileBtn = document.getElementById('saveProfileBtn');
            const cancelProfileBtn = document.getElementById('cancelProfileBtn');
            const profileType = document.getElementById('profileType');
            const profileWidth = document.getElementById('profileWidth');
            const profileHeight = document.getElementById('profileHeight');
            const profileQuantity = document.getElementById('profileQuantity');
            const profileHingeCount = document.getElementById('profileHingeCount');
            const profileNotes = document.getElementById('profileNotes');
            const profileIndex = document.getElementById('profileIndex');
            const noProfilesRow = document.getElementById('noProfilesRow');
            const profilesDataInput = document.getElementById('profilesData');
            
            // Glass management
            const glassData = <?= json_encode($glass) ?>;
            let glassList = Array.isArray(glassData) ? [...glassData] : [];
            const glassTable = document.getElementById('glassTable');
            const glassForm = document.getElementById('glassForm');
            const addGlassBtn = document.getElementById('addGlassBtn');
            const saveGlassBtn = document.getElementById('saveGlassBtn');
            const cancelGlassBtn = document.getElementById('cancelGlassBtn');
            const glassType = document.getElementById('glassType');
            const glassWidth = document.getElementById('glassWidth');
            const glassHeight = document.getElementById('glassHeight');
            const glassQuantity = document.getElementById('glassQuantity');
            const glassOffset = document.getElementById('glassOffset');
            const glassArea = document.getElementById('glassArea');
            const glassIndex = document.getElementById('glassIndex');
            const noGlassRow = document.getElementById('noGlassRow');
            const glassDataInput = document.getElementById('glassData');
            
            // Pricing elements
            const pricingDataInput = document.getElementById('pricingData');
            const sideProfilesLength = document.getElementById('sideProfilesLength');
            const sideProfilesPrice = document.getElementById('sideProfilesPrice');
            const handleProfilesLength = document.getElementById('handleProfilesLength');
            const handleProfilesPrice = document.getElementById('handleProfilesPrice');
            const glassAreaTotal = document.getElementById('glassAreaTotal');
            const glassPrice = document.getElementById('glassPrice');
            const hingeCountTotal = document.getElementById('hingeCountTotal');
            const hingePrice = document.getElementById('hingePrice');
            const connectionCount = document.getElementById('connectionCount');
            const connectionPrice = document.getElementById('connectionPrice');
            const mechanismCount = document.getElementById('mechanismCount');
            const mechanismPrice = document.getElementById('mechanismPrice');
            const transportFee = document.getElementById('transportFee');
            const assemblyFee = document.getElementById('assembly_fee');
            
            const sideProfilesTotal = document.getElementById('sideProfilesTotal');
            const handleProfilesTotal = document.getElementById('handleProfilesTotal');
            const glassTotalPrice = document.getElementById('glassTotalPrice');
            const hingeTotalPrice = document.getElementById('hingeTotalPrice');
            const connectionTotalPrice = document.getElementById('connectionTotalPrice');
            const mechanismTotalPrice = document.getElementById('mechanismTotalPrice');
            const transportTotal = document.getElementById('transportTotal');
            const assemblyTotal = document.getElementById('assemblyTotal');
            const totalAmount = document.getElementById('totalAmount');
            const totalAmountInput = document.getElementById('total_amount');
            
            // Payment elements
            const advancePayment = document.getElementById('advance_payment');
            const remainingAmount = document.getElementById('remaining_amount');
            
            // Initialize drawing canvas
            const canvas = document.getElementById('drawingCanvas');
            const ctx = canvas.getContext('2d');
            const drawingImage = document.getElementById('drawingImage');
            
            // Set canvas dimensions
            function resizeCanvas() {
                const container = canvas.parentElement;
                canvas.width = container.clientWidth;
                canvas.height = 400;
                
                // Redraw canvas if there's an existing image
                if (drawingImage.value) {
                    const img = new Image();
                    img.onload = function() {
                        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                    };
                    img.src = drawingImage.value;
                }
            }
            
            // Resize canvas on load
            resizeCanvas();
            window.addEventListener('resize', resizeCanvas);
            
            // Drawing variables
            let isDrawing = false;
            let lastX = 0;
            let lastY = 0;
            let currentTool = 'pencil';
            let startX, startY;
            
            // Tool buttons
            const pencilTool = document.getElementById('pencilTool');
            const lineTool = document.getElementById('lineTool');
            const rectTool = document.getElementById('rectTool');
            const circleTool = document.getElementById('circleTool');
            const textTool = document.getElementById('textTool');
            const eraserTool = document.getElementById('eraserTool');
            const clearCanvas = document.getElementById('clearCanvas');
            
            // Set active tool
            function setActiveTool(tool) {
                
                                currentTool = tool;
                
                // Remove active class from all tools
                document.querySelectorAll('.tool-btn').forEach(btn => {
                    btn.classList.remove('active');
                });
                
                // Add active class to current tool
                switch (tool) {
                    case 'pencil':
                        pencilTool.classList.add('active');
                        break;
                    case 'line':
                        lineTool.classList.add('active');
                        break;
                    case 'rect':
                        rectTool.classList.add('active');
                        break;
                    case 'circle':
                        circleTool.classList.add('active');
                        break;
                    case 'text':
                        textTool.classList.add('active');
                        break;
                    case 'eraser':
                        eraserTool.classList.add('active');
                        break;
                }
            }
            
            // Tool event listeners
            pencilTool.addEventListener('click', () => setActiveTool('pencil'));
            lineTool.addEventListener('click', () => setActiveTool('line'));
            rectTool.addEventListener('click', () => setActiveTool('rect'));
            circleTool.addEventListener('click', () => setActiveTool('circle'));
            textTool.addEventListener('click', () => setActiveTool('text'));
            eraserTool.addEventListener('click', () => setActiveTool('eraser'));
            
            clearCanvas.addEventListener('click', function() {
                if (confirm('Eskizi təmizləməyə əminsiniz?')) {
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    saveCanvas();
                }
            });
            
            // Drawing event listeners
            canvas.addEventListener('mousedown', startDrawing);
            canvas.addEventListener('touchstart', startDrawingTouch);
            canvas.addEventListener('mousemove', draw);
            canvas.addEventListener('touchmove', drawTouch);
            canvas.addEventListener('mouseup', stopDrawing);
            canvas.addEventListener('touchend', stopDrawing);
            canvas.addEventListener('mouseout', stopDrawing);
            
            function startDrawing(e) {
                isDrawing = true;
                [lastX, lastY] = [e.offsetX, e.offsetY];
                [startX, startY] = [e.offsetX, e.offsetY];
                
                if (currentTool === 'pencil' || currentTool === 'eraser') {
                    ctx.beginPath();
                    ctx.moveTo(lastX, lastY);
                }
                
                if (currentTool === 'text') {
                    const text = prompt('Mətn daxil edin:', '');
                    if (text) {
                        ctx.font = '16px Arial';
                        ctx.fillStyle = '#000';
                        ctx.fillText(text, lastX, lastY);
                        saveCanvas();
                    }
                }
            }
            
            function startDrawingTouch(e) {
                e.preventDefault();
                const touch = e.touches[0];
                const rect = canvas.getBoundingClientRect();
                const offsetX = touch.clientX - rect.left;
                const offsetY = touch.clientY - rect.top;
                
                isDrawing = true;
                [lastX, lastY] = [offsetX, offsetY];
                [startX, startY] = [offsetX, offsetY];
                
                if (currentTool === 'pencil' || currentTool === 'eraser') {
                    ctx.beginPath();
                    ctx.moveTo(lastX, lastY);
                }
                
                if (currentTool === 'text') {
                    const text = prompt('Mətn daxil edin:', '');
                    if (text) {
                        ctx.font = '16px Arial';
                        ctx.fillStyle = '#000';
                        ctx.fillText(text, lastX, lastY);
                        saveCanvas();
                    }
                }
            }
            
            function draw(e) {
                if (!isDrawing) return;
                
                if (currentTool === 'pencil') {
                    ctx.lineWidth = 2;
                    ctx.lineCap = 'round';
                    ctx.strokeStyle = '#000';
                    
                    ctx.lineTo(e.offsetX, e.offsetY);
                    ctx.stroke();
                    [lastX, lastY] = [e.offsetX, e.offsetY];
                } else if (currentTool === 'eraser') {
                    ctx.lineWidth = 20;
                    ctx.lineCap = 'round';
                    ctx.strokeStyle = '#fff';
                    
                    ctx.lineTo(e.offsetX, e.offsetY);
                    ctx.stroke();
                    [lastX, lastY] = [e.offsetX, e.offsetY];
                }
            }
            
            function drawTouch(e) {
                e.preventDefault();
                if (!isDrawing) return;
                
                const touch = e.touches[0];
                const rect = canvas.getBoundingClientRect();
                const offsetX = touch.clientX - rect.left;
                const offsetY = touch.clientY - rect.top;
                
                if (currentTool === 'pencil') {
                    ctx.lineWidth = 2;
                    ctx.lineCap = 'round';
                    ctx.strokeStyle = '#000';
                    
                    ctx.lineTo(offsetX, offsetY);
                    ctx.stroke();
                    [lastX, lastY] = [offsetX, offsetY];
                } else if (currentTool === 'eraser') {
                    ctx.lineWidth = 20;
                    ctx.lineCap = 'round';
                    ctx.strokeStyle = '#fff';
                    
                    ctx.lineTo(offsetX, offsetY);
                    ctx.stroke();
                    [lastX, lastY] = [offsetX, offsetY];
                }
            }
            
            function stopDrawing(e) {
                if (!isDrawing) return;
                
                if (currentTool === 'line') {
                    ctx.beginPath();
                    ctx.lineWidth = 2;
                    ctx.strokeStyle = '#000';
                    ctx.moveTo(startX, startY);
                    
                    const endX = e.offsetX || lastX;
                    const endY = e.offsetY || lastY;
                    
                    ctx.lineTo(endX, endY);
                    ctx.stroke();
                } else if (currentTool === 'rect') {
                    ctx.lineWidth = 2;
                    ctx.strokeStyle = '#000';
                    
                    const endX = e.offsetX || lastX;
                    const endY = e.offsetY || lastY;
                    
                    const width = endX - startX;
                    const height = endY - startY;
                    
                    ctx.strokeRect(startX, startY, width, height);
                } else if (currentTool === 'circle') {
                    ctx.beginPath();
                    ctx.lineWidth = 2;
                    ctx.strokeStyle = '#000';
                    
                    const endX = e.offsetX || lastX;
                    const endY = e.offsetY || lastY;
                    
                    const radius = Math.sqrt(Math.pow(endX - startX, 2) + Math.pow(endY - startY, 2));
                    
                    ctx.arc(startX, startY, radius, 0, 2 * Math.PI);
                    ctx.stroke();
                }
                
                isDrawing = false;
                saveCanvas();
            }
            
            function saveCanvas() {
                drawingImage.value = canvas.toDataURL('image/png');
            }
            
            // Profile form functions
            addProfileBtn.addEventListener('click', function() {
                profileForm.classList.add('active');
                addProfileBtn.style.display = 'none';
                profileIndex.value = -1;
                profileType.value = '';
                profileWidth.value = '';
                profileHeight.value = '';
                profileQuantity.value = '1';
                profileHingeCount.value = '0';
                profileNotes.value = '';
                saveProfileBtn.textContent = 'Əlavə et';
            });
            
            cancelProfileBtn.addEventListener('click', function() {
                profileForm.classList.remove('active');
                addProfileBtn.style.display = 'inline-flex';
            });
            
            saveProfileBtn.addEventListener('click', function() {
                if (!profileType.value || !profileWidth.value || !profileHeight.value || !profileQuantity.value) {
                    alert('Bütün məcburi xanaları doldurun!');
                    return;
                }
                
                const index = parseInt(profileIndex.value);
                const profile = {
                    profile_type: profileType.value,
                    width: parseFloat(profileWidth.value),
                    height: parseFloat(profileHeight.value),
                    quantity: parseInt(profileQuantity.value),
                    hinge_count: parseInt(profileHingeCount.value),
                    notes: profileNotes.value
                };
                
                if (index >= 0) {
                    // Update existing profile
                    profilesList[index] = profile;
                } else {
                    // Add new profile
                    profilesList.push(profile);
                }
                
                refreshProfilesTable();
                profileForm.classList.remove('active');
                addProfileBtn.style.display = 'inline-flex';
                updateProfilesData();
            });
            
            function refreshProfilesTable() {
                const tbody = profilesTable.querySelector('tbody');
                tbody.innerHTML = '';
                
                if (profilesList.length === 0) {
                    tbody.innerHTML = `
                        <tr id="noProfilesRow">
                            <td colspan="7" class="text-center">Profil məlumatı yoxdur</td>
                        </tr>
                    `;
                    return;
                }
                
                profilesList.forEach((profile, index) => {
                    const row = document.createElement('tr');
                    row.dataset.index = index;
                    row.className = 'profile-row';
                    
                    row.innerHTML = `
                        <td>${profile.profile_type}</td>
                        <td>${profile.width}</td>
                        <td>${profile.height}</td>
                        <td>${profile.quantity}</td>
                        <td>${profile.hinge_count}</td>
                        <td>${profile.notes || ''}</td>
                        <td class="actions">
                            <button type="button" class="action-btn edit-profile" title="Düzəliş et">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button type="button" class="action-btn delete-btn delete-profile" title="Sil">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    `;
                    
                    tbody.appendChild(row);
                });
                
                // Add event listeners to new buttons
                document.querySelectorAll('.edit-profile').forEach(btn => {
                    btn.addEventListener('click', editProfile);
                });
                
                document.querySelectorAll('.delete-profile').forEach(btn => {
                    btn.addEventListener('click', deleteProfile);
                });
            }
            
            function editProfile(e) {
                const row = e.target.closest('tr');
                const index = parseInt(row.dataset.index);
                const profile = profilesList[index];
                
                profileIndex.value = index;
                profileType.value = profile.profile_type;
                profileWidth.value = profile.width;
                profileHeight.value = profile.height;
                profileQuantity.value = profile.quantity;
                profileHingeCount.value = profile.hinge_count;
                profileNotes.value = profile.notes || '';
                
                profileForm.classList.add('active');
                addProfileBtn.style.display = 'none';
                saveProfileBtn.textContent = 'Yenilə';
            }
            
            function deleteProfile(e) {
                if (!confirm('Bu profili silmək istədiyinizə əminsiniz?')) return;
                
                const row = e.target.closest('tr');
                const index = parseInt(row.dataset.index);
                
                profilesList.splice(index, 1);
                refreshProfilesTable();
                updateProfilesData();
            }
            
            function updateProfilesData() {
                // Convert profilesList to simplified format for server
                const simplifiedData = profilesList.map(p => ({
                    type: p.profile_type,
                    width: p.width,
                    height: p.height,
                    quantity: p.quantity,
                    hingeCount: p.hinge_count,
                    notes: p.notes || ''
                }));
                
                profilesDataInput.value = JSON.stringify(simplifiedData);
            }
            
            // Glass form functions
            addGlassBtn.addEventListener('click', function() {
                glassForm.classList.add('active');
                addGlassBtn.style.display = 'none';
                glassIndex.value = -1;
                glassType.value = '';
                glassWidth.value = '';
                glassHeight.value = '';
                glassQuantity.value = '1';
                glassOffset.value = '0';
                glassArea.value = '';
                saveGlassBtn.textContent = 'Əlavə et';
            });
            
            cancelGlassBtn.addEventListener('click', function() {
                glassForm.classList.remove('active');
                addGlassBtn.style.display = 'inline-flex';
            });
            
            // Calculate glass area when dimensions change
            glassWidth.addEventListener('input', calculateGlassArea);
            glassHeight.addEventListener('input', calculateGlassArea);
            
            function calculateGlassArea() {
                if (glassWidth.value && glassHeight.value) {
                    const width = parseFloat(glassWidth.value);
                    const height = parseFloat(glassHeight.value);
                    if (width > 0 && height > 0) {
                        const area = (width * height) / 10000; // Convert from cm² to m²
                        glassArea.value = area.toFixed(4);
                    }
                }
            }
            
            saveGlassBtn.addEventListener('click', function() {
                if (!glassType.value || !glassWidth.value || !glassHeight.value || !glassQuantity.value) {
                    alert('Bütün məcburi xanaları doldurun!');
                    return;
                }
                
                const index = parseInt(glassIndex.value);
                const width = parseFloat(glassWidth.value);
                const height = parseFloat(glassHeight.value);
                const area = (width * height) / 10000; // Convert from cm² to m²
                
                const glass = {
                    glass_type: glassType.value,
                    width: width,
                    height: height,
                    quantity: parseInt(glassQuantity.value),
                    offset_mm: parseInt(glassOffset.value),
                    area: area
                };
                
                if (index >= 0) {
                    // Update existing glass
                    glassList[index] = glass;
                } else {
                    // Add new glass
                    glassList.push(glass);
                }
                
                refreshGlassTable();
                glassForm.classList.remove('active');
                addGlassBtn.style.display = 'inline-flex';
                updateGlassData();
            });
            
            function refreshGlassTable() {
                const tbody = glassTable.querySelector('tbody');
                tbody.innerHTML = '';
                
                if (glassList.length === 0) {
                    tbody.innerHTML = `
                        <tr id="noGlassRow">
                            <td colspan="7" class="text-center">Şüşə məlumatı yoxdur</td>
                        </tr>
                    `;
                    return;
                }
                
                glassList.forEach((glass, index) => {
                    const row = document.createElement('tr');
                    row.dataset.index = index;
                    row.className = 'glass-row';
                    
                    row.innerHTML = `
                        <td>${glass.glass_type}</td>
                        <td>${glass.width}</td>
                        <td>${glass.height}</td>
                        <td>${glass.quantity}</td>
                        <td>${glass.offset_mm}</td>
                        <td>${glass.area.toFixed(4)}</td>
                        <td class="actions">
                            <button type="button" class="action-btn edit-glass" title="Düzəliş et">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button type="button" class="action-btn delete-btn delete-glass" title="Sil">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    `;
                    
                    tbody.appendChild(row);
                });
                
                // Add event listeners to new buttons
                document.querySelectorAll('.edit-glass').forEach(btn => {
                    btn.addEventListener('click', editGlass);
                });
                
                document.querySelectorAll('.delete-glass').forEach(btn => {
                    btn.addEventListener('click', deleteGlass);
                });
            }
            
            function editGlass(e) {
                const row = e.target.closest('tr');
                const index = parseInt(row.dataset.index);
                const glass = glassList[index];
                
                glassIndex.value = index;
                glassType.value = glass.glass_type;
                glassWidth.value = glass.width;
                glassHeight.value = glass.height;
                glassQuantity.value = glass.quantity;
                glassOffset.value = glass.offset_mm;
                glassArea.value = glass.area.toFixed(4);
                
                glassForm.classList.add('active');
                addGlassBtn.style.display = 'none';
                saveGlassBtn.textContent = 'Yenilə';
            }
            
            function deleteGlass(e) {
                if (!confirm('Bu şüşəni silmək istədiyinizə əminsiniz?')) return;
                
                const row = e.target.closest('tr');
                const index = parseInt(row.dataset.index);
                
                glassList.splice(index, 1);
                refreshGlassTable();
                updateGlassData();
            }
            
            function updateGlassData() {
                // Convert glassList to simplified format for server
                const simplifiedData = glassList.map(g => ({
                    type: g.glass_type,
                    width: g.width,
                    height: g.height,
                    quantity: g.quantity,
                    offset: g.offset_mm,
                    area: g.area
                }));
                
                glassDataInput.value = JSON.stringify(simplifiedData);
            }
            
            // Pricing calculations
            sideProfilesLength.addEventListener('input', updatePricingData);
            sideProfilesPrice.addEventListener('input', updatePricingData);
            handleProfilesLength.addEventListener('input', updatePricingData);
            handleProfilesPrice.addEventListener('input', updatePricingData);
            glassAreaTotal.addEventListener('input', updatePricingData);
            glassPrice.addEventListener('input', updatePricingData);
            hingeCountTotal.addEventListener('input', updatePricingData);
            hingePrice.addEventListener('input', updatePricingData);
            connectionCount.addEventListener('input', updatePricingData);
            connectionPrice.addEventListener('input', updatePricingData);
            mechanismCount.addEventListener('input', updatePricingData);
            mechanismPrice.addEventListener('input', updatePricingData);
            transportFee.addEventListener('input', updatePricingData);
            assemblyFee.addEventListener('input', updatePricingData);
            advancePayment.addEventListener('input', calculateRemaining);
            
            function updatePricingData() {
                const pricingData = {
                    sideProfilesLength: parseFloat(sideProfilesLength.value || 0),
                    sideProfilesPrice: parseFloat(sideProfilesPrice.value || 0),
                    handleProfilesLength: parseFloat(handleProfilesLength.value || 0),
                    handleProfilesPrice: parseFloat(handleProfilesPrice.value || 0),
                    glassArea: parseFloat(glassAreaTotal.value || 0),
                    glassPrice: parseFloat(glassPrice.value || 0),
                    hingeCount: parseInt(hingeCountTotal.value || 0),
                    hingePrice: parseFloat(hingePrice.value || 0),
                    connectionCount: parseInt(connectionCount.value || 0),
                    connectionPrice: parseFloat(connectionPrice.value || 0),
                    mechanismCount: parseInt(mechanismCount.value || 0),
                    mechanismPrice: parseFloat(mechanismPrice.value || 0),
                    transportFee: parseFloat(transportFee.value || 0),
                    assemblyFee: parseFloat(assemblyFee.value || 0)
                };
                
                // Update display values
                const sideTotal = pricingData.sideProfilesPrice;
                const handleTotal = pricingData.handleProfilesPrice;
                const glassTotal = pricingData.glassPrice;
                const hingeTotal = pricingData.hingeCount * pricingData.hingePrice;
                const connTotal = pricingData.connectionCount * pricingData.connectionPrice;
                const mechTotal = pricingData.mechanismCount * pricingData.mechanismPrice;
                
                sideProfilesTotal.textContent = sideTotal.toFixed(2) + ' AZN';
                handleProfilesTotal.textContent = handleTotal.toFixed(2) + ' AZN';
                glassTotalPrice.textContent = glassTotal.toFixed(2) + ' AZN';
                hingeTotalPrice.textContent = hingeTotal.toFixed(2) + ' AZN';
                connectionTotalPrice.textContent = connTotal.toFixed(2) + ' AZN';
                mechanismTotalPrice.textContent = mechTotal.toFixed(2) + ' AZN';
                transportTotal.textContent = pricingData.transportFee.toFixed(2) + ' AZN';
                assemblyTotal.textContent = pricingData.assemblyFee.toFixed(2) + ' AZN';
                
                // Update total
                const total = sideTotal + handleTotal + glassTotal + hingeTotal + 
                            connTotal + mechTotal + pricingData.transportFee + pricingData.assemblyFee;
                
                totalAmount.textContent = total.toFixed(2) + ' AZN';
                totalAmountInput.value = total.toFixed(2);
                
                pricingDataInput.value = JSON.stringify(pricingData);
                
                // Update remaining amount
                calculateRemaining();
            }
            
            function calculateRemaining() {
                const total = parseFloat(totalAmountInput.value || 0);
                const advance = parseFloat(advancePayment.value || 0);
                const remaining = total - advance;
                
                remainingAmount.value = remaining.toFixed(2);
            }
            
            // Initialize data
            refreshProfilesTable();
            refreshGlassTable();
            updateProfilesData();
            updateGlassData();
            updatePricingData();
            
            // Form submission validation
            document.getElementById('orderForm').addEventListener('submit', function(e) {
                const total = parseFloat(totalAmountInput.value || 0);
                const advance = parseFloat(advancePayment.value || 0);
                
                if (advance > total) {
                    e.preventDefault();
                    alert('Avans ödənişi ümumi məbləğdən çox ola bilməz!');
                    return false;
                }
                
                return true;
            });
        });
    </script>
</body>
</html>
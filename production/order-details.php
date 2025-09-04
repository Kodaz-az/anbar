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

// Get order ID
$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($orderId <= 0) {
    header('Location: index.php');
    exit;
}

// Get order details
$conn = getDBConnection();
$sql = "SELECT o.*, 
               c.fullname AS customer_name, c.phone AS customer_phone,
               s.fullname AS seller_name, s.phone AS seller_phone,
               b.name AS branch_name, b.phone AS branch_phone
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        LEFT JOIN users s ON o.seller_id = s.id
        LEFT JOIN branches b ON o.branch_id = b.id
        WHERE o.id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    header('Location: index.php');
    exit;
}

// Get order profiles
$sql = "SELECT * FROM order_profiles WHERE order_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$profiles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get order glass
$sql = "SELECT * FROM order_glass WHERE order_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$glasses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get order accessories
$sql = "SELECT oa.*, a.name as accessory_name, a.unit as accessory_unit 
        FROM order_accessories oa
        LEFT JOIN accessories a ON oa.accessory_id = a.id
        WHERE oa.order_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$accessories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get material requirements
$sql = "SELECT 
            op.profile_type,
            SUM(op.total_length) as total_profile_length,
            SUM(op.total_weight) as total_profile_weight
        FROM order_profiles op
        WHERE op.order_id = ?
        GROUP BY op.profile_type";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$profileMaterials = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$sql = "SELECT 
            og.glass_type, 
            SUM(og.area * og.quantity) as total_area
        FROM order_glass og
        WHERE og.order_id = ?
        GROUP BY og.glass_type";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$glassMaterials = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get order's production note
$sql = "SELECT * FROM production_notes WHERE order_id = ? ORDER BY created_at DESC LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$productionNote = $stmt->get_result()->fetch_assoc();

// Process form submission for production note
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_note') {
    $note = trim($_POST['note'] ?? '');
    
    if (!empty($note)) {
        $sql = "INSERT INTO production_notes (order_id, user_id, note, created_at) VALUES (?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iis", $orderId, $userId, $note);
        
        if ($stmt->execute()) {
            // Redirect to avoid form resubmission
            header("Location: order-details.php?id={$orderId}&note_added=1");
            exit;
        }
    }
}

// Process status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $newStatus = $_POST['status'] ?? '';
    $validStatuses = ['processing', 'completed', 'delivered'];
    
    if (in_array($newStatus, $validStatuses)) {
        header("Location: update-status.php?id={$orderId}&status={$newStatus}&return=details");
        exit;
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

// Get status info
$statusInfo = $statusConfig[$order['order_status']] ?? ['text' => 'Bilinmir', 'color' => 'info'];
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sifariş Detalları | AlumPro İstehsalat</title>
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
        
        /* Mobile app style header */
        .app-header {
            background: var(--primary-gradient);
            color: var(--text-light);
            padding: var(--spacing-md);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-title {
            font-size: 20px;
            font-weight: 700;
        }
        
        .back-btn {
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        /* Container */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: var(--spacing-md);
        }
        
        /* Order header */
        .order-header {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: var(--spacing-md);
            margin-bottom: var(--spacing-md);
            position: relative;
            overflow: hidden;
        }
        
        .order-number {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .order-date {
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 15px;
        }
        
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
        
        .order-details {
            display: flex;
            gap: 30px;
            margin-top: 15px;
        }
        
        .detail-column {
            flex: 1;
        }
        
        .detail-label {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 5px;
        }
        
        .detail-value {
            font-weight: 500;
        }
        
        .order-actions {
            margin-top: 20px;
            display: flex;
            gap: 10px;
        }
        
        /* Content grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: var(--spacing-md);
        }
        
        /* Card styles */
        .card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            margin-bottom: var(--spacing-md);
            overflow: hidden;
        }
        
        .card-header {
            padding: var(--spacing-md);
            border-bottom: 1px solid #f3f4f6;
            font-size: 16px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .card-header i {
            color: var(--primary-color);
        }
        
        .card-body {
            padding: var(--spacing-md);
        }
        
        /* Tables */
        .table-responsive {
            overflow-x: auto;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th {
            text-align: left;
            padding: 10px;
            background-color: #f3f4f6;
            font-weight: 500;
            color: #4b5563;
        }
        
        .data-table td {
            padding: 10px;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .data-table tr:last-child td {
            border-bottom: none;
        }
        
        /* Materials list */
        .material-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .material-item:last-child {
            border-bottom: none;
        }
        
        .material-name {
            font-weight: 500;
        }
        
        .material-quantity {
            font-weight: 500;
            color: var(--primary-color);
        }
        
        /* Note box */
        .note-box {
            background: #f9fafb;
            border-left: 4px solid var(--primary-color);
            padding: var(--spacing-md);
            margin-top: var(--spacing-md);
        }
        
        .note-title {
            font-weight: 500;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 16px;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }
        
        .btn-primary {
            background: var(--primary-gradient);
            color: white;
        }
        
        .btn-primary:hover {
            opacity: 0.9;
        }
        
        .btn-secondary {
            background: #e5e7eb;
            color: #374151;
        }
        
        .btn-secondary:hover {
            background: #d1d5db;
        }
        
        .btn-success {
            background: #10b981;
            color: white;
        }
        
        .btn-success:hover {
            opacity: 0.9;
        }
        
        .btn-warning {
            background: #f59e0b;
            color: white;
        }
        
        .btn-warning:hover {
            opacity: 0.9;
        }
        
        .btn-outline {
            background: white;
            border: 1px solid #d1d5db;
            color: #374151;
        }
        
        .btn-outline:hover {
            background: #f9fafb;
        }
        
        .btn-block {
            display: block;
            width: 100%;
        }
        
        /* Forms */
        .form-group {
            margin-bottom: var(--spacing-md);
        }
        
        .form-label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 16px;
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
        
        /* Contact buttons */
        .contact-buttons {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-top: var(--spacing-md);
        }
        
        .contact-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
        }
        
        .contact-btn.whatsapp {
            background: #25d366;
            color: white;
        }
        
        .contact-btn.call {
            background: #3b82f6;
            color: white;
        }
        
        /* Timeline */
        .timeline {
            position: relative;
            margin-top: 20px;
            padding-left: 20px;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            top: 0;
            bottom: 0;
            left: 0;
            width: 2px;
            background-color: #e5e7eb;
        }
        
        .timeline-item {
            position: relative;
            padding-bottom: 20px;
        }
        
        .timeline-item:last-child {
            padding-bottom: 0;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -25px;
            top: 0;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background-color: var(--primary-color);
            border: 2px solid white;
        }
        
        .timeline-item.inactive::before {
            background-color: #d1d5db;
        }
        
        .timeline-content {
            padding: 10px 15px;
            background-color: #f9fafb;
            border-radius: 8px;
        }
        
        .timeline-title {
            font-weight: 500;
            margin-bottom: 4px;
        }
        
        .timeline-date {
            font-size: 12px;
            color: #6b7280;
        }
        
        /* Drawing image */
        .drawing-container {
            margin-top: var(--spacing-md);
            text-align: center;
        }
        
        .drawing-image {
            max-width: 100%;
            border-radius: 8px;
            box-shadow: var(--card-shadow);
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
            font-size: 20px;
            margin-bottom: 4px;
        }
        
        @media (max-width: 992px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .order-details {
                flex-direction: column;
                gap: 15px;
            }
            
            .contact-buttons {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <header class="app-header">
        <a href="index.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Geri
        </a>
        <div class="header-title">Sifariş Detalları</div>
    </header>
    
    <div class="container">
        <!-- Order Header -->
        <div class="order-header">
            <div class="order-number">
                #<?= htmlspecialchars($order['order_number']) ?>
                <span class="status-badge badge-<?= $statusInfo['color'] ?>"><?= $statusInfo['text'] ?></span>
            </div>
            <div class="order-date"><?= formatDate($order['order_date'], 'd.m.Y H:i') ?></div>
            
            <div class="order-details">
                <div class="detail-column">
                    <div class="detail-label">Müştəri</div>
                    <div class="detail-value"><?= htmlspecialchars($order['customer_name']) ?></div>
                </div>
                
                <div class="detail-column">
                    <div class="detail-label">Satıcı</div>
                    <div class="detail-value"><?= htmlspecialchars($order['seller_name']) ?></div>
                </div>
                
                <div class="detail-column">
                    <div class="detail-label">Filial</div>
                    <div class="detail-value"><?= htmlspecialchars($order['branch_name']) ?></div>
                </div>
                
                <div class="detail-column">
                    <div class="detail-label">Barkod</div>
                    <div class="detail-value"><?= htmlspecialchars($order['barcode']) ?></div>
                </div>
            </div>
            
            <!-- Order Actions -->
            <div class="order-actions">
                <?php if ($order['order_status'] === 'new'): ?>
                    <form method="post" action="">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="status" value="processing">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-cog"></i> İstehsalata Başla
                        </button>
                    </form>
                <?php elseif ($order['order_status'] === 'processing'): ?>
                    <form method="post" action="">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="status" value="completed">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-check"></i> Hazırdır
                        </button>
                    </form>
                <?php elseif ($order['order_status'] === 'completed'): ?>
                    <form method="post" action="">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="status" value="delivered">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-truck"></i> Təhvil Ver
                        </button>
                    </form>
                <?php endif; ?>
                
                <a href="barcode-scanner.php?barcode=<?= htmlspecialchars($order['barcode']) ?>" class="btn btn-secondary">
                    <i class="fas fa-barcode"></i> Barkod Görünüşü
                </a>
                
                <a href="#" onclick="window.print()" class="btn btn-outline">
                    <i class="fas fa-print"></i> Çap et
                </a>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="content-grid">
            <!-- Left Column -->
            <div>
                <!-- Profiles List -->
                <?php if (!empty($profiles)): ?>
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-box"></i> Profil Məhsulları
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Profil Növü</th>
                                            <th>Ölçülər</th>
                                            <th>Sayı</th>
                                            <th>Uzunluq</th>
                                            <th>Çəki</th>
                                            <th>Petlə Sayı</th>
                                            <th>Rəng</th>
                                            <th>Qeyd</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($profiles as $profile): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($profile['profile_type']) ?></td>
                                                <td><?= $profile['height'] ?> x <?= $profile['width'] ?> sm</td>
                                                <td><?= $profile['quantity'] ?> ədəd</td>
                                                <td><?= $profile['total_length'] ?? '-' ?> m</td>
                                                <td><?= $profile['total_weight'] ?? '-' ?> kg</td>
                                                <td><?= $profile['hinge_count'] ?> ədəd</td>
                                                <td><?= htmlspecialchars($profile['color'] ?? '-') ?></td>
                                                <td><?= htmlspecialchars($profile['notes'] ?? '-') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Glass List -->
                <?php if (!empty($glasses)): ?>
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-square"></i> Şüşə Məhsulları
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Şüşə Növü</th>
                                            <th>Ölçülər</th>
                                            <th>Sayı</th>
                                            <th>Ofset (mm)</th>
                                            <th>Sahə (m²)</th>
                                            <th>Ümumi Sahə</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($glasses as $glass): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($glass['glass_type']) ?></td>
                                                <td><?= $glass['height'] ?> x <?= $glass['width'] ?> sm</td>
                                                <td><?= $glass['quantity'] ?> ədəd</td>
                                                <td><?= $glass['offset_mm'] ?> mm</td>
                                                <td><?= $glass['area'] ?> m²</td>
                                                <td><?= $glass['area'] * $glass['quantity'] ?> m²</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Accessories List -->
                <?php if (!empty($accessories)): ?>
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-tools"></i> Aksesuarlar
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Aksesuar</th>
                                            <th>Miqdar</th>
                                            <th>Ölçü Vahidi</th>
                                            <th>Qeyd</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($accessories as $accessory): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($accessory['accessory_name']) ?></td>
                                                <td><?= $accessory['quantity'] ?></td>
                                                <td><?= htmlspecialchars($accessory['accessory_unit']) ?></td>
                                                <td><?= htmlspecialchars($accessory['notes'] ?? '-') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Order Notes -->
                <?php if (!empty($order['initial_note']) || !empty($order['seller_notes'])): ?>
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-sticky-note"></i> Sifarişlə Bağlı Qeydlər
                        </div>
                        <div class="card-body">
                            <?php if (!empty($order['initial_note'])): ?>
                                <div class="note-box">
                                    <div class="note-title">
                                        <i class="fas fa-info-circle"></i> Ümumi Qeyd:
                                    </div>
                                    <div><?= nl2br(htmlspecialchars($order['initial_note'])) ?></div>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($order['seller_notes'])): ?>
                                <div class="note-box">
                                    <div class="note-title">
                                        <i class="fas fa-user-tie"></i> Satıcı Qeydi:
                                    </div>
                                    <div><?= nl2br(htmlspecialchars($order['seller_notes'])) ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Production Notes -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-comment-alt"></i> İstehsalat Qeydləri
                    </div>
                    <div class="card-body">
                        <?php if (!empty($productionNote)): ?>
                            <div class="note-box">
                                <div class="note-title">
                                    Son qeyd:
                                </div>
                                <div><?= nl2br(htmlspecialchars($productionNote['note'])) ?></div>
                                <div style="font-size: 12px; color: #6b7280; margin-top: 8px;">
                                    <?= formatDate($productionNote['created_at'], 'd.m.Y H:i') ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <form method="post" action="" class="mt-4">
                            <input type="hidden" name="action" value="add_note">
                            <div class="form-group">
                                <label for="note" class="form-label">Yeni İstehsalat Qeydi Əlavə Et</label>
                                <textarea id="note" name="note" class="form-control" placeholder="İstehsalat prosesi ilə bağlı qeydlərinizi yazın..." required></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Qeyd Əlavə Et
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Drawing Image -->
                <?php if (!empty($order['drawing_image'])): ?>
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-pencil-alt"></i> Çəkilmiş Sxem
                        </div>
                        <div class="card-body">
                            <div class="drawing-container">
                                <img src="<?= htmlspecialchars($order['drawing_image']) ?>" alt="Çəkilmiş sxem" class="drawing-image">
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Right Column -->
            <div>
                <!-- Order Status Timeline -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-clock"></i> Sifariş Statusu
                    </div>
                    <div class="card-body">
                        <div class="timeline">
                            <div class="timeline-item">
                                <div class="timeline-content">
                                    <div class="timeline-title">Sifariş qəbul edildi</div>
                                    <div class="timeline-date"><?= formatDate($order['order_date'], 'd.m.Y H:i') ?></div>
                                </div>
                            </div>
                            
                            <div class="timeline-item <?= in_array($order['order_status'], ['new']) ? 'inactive' : '' ?>">
                                <div class="timeline-content">
                                    <div class="timeline-title">İstehsalata başlanıldı</div>
                                    <?php if (in_array($order['order_status'], ['processing', 'completed', 'delivered'])): ?>
                                        <div class="timeline-date"><?= formatDate($order['processing_date'] ?? $order['updated_at'], 'd.m.Y H:i') ?></div>
                                    <?php else: ?>
                                        <div class="timeline-date">Gözlənilir...</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="timeline-item <?= in_array($order['order_status'], ['new', 'processing']) ? 'inactive' : '' ?>">
                                <div class="timeline-content">
                                    <div class="timeline-title">Hazırdır</div>
                                    <?php if (in_array($order['order_status'], ['completed', 'delivered'])): ?>
                                        <div class="timeline-date"><?= formatDate($order['completion_date'] ?? $order['updated_at'], 'd.m.Y H:i') ?></div>
                                    <?php else: ?>
                                        <div class="timeline-date">Gözlənilir...</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="timeline-item <?= $order['order_status'] !== 'delivered' ? 'inactive' : '' ?>">
                                <div class="timeline-content">
                                    <div class="timeline-title">Təhvil verilib</div>
                                    <?php if ($order['order_status'] === 'delivered'): ?>
                                        <div class="timeline-date"><?= formatDate($order['delivery_date'] ?? $order['updated_at'], 'd.m.Y H:i') ?></div>
                                    <?php else: ?>
                                        <div class="timeline-date">Gözlənilir...</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Material Requirements -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-boxes"></i> Material Tələbləri
                    </div>
                    <div class="card-body">
                        <?php if (!empty($profileMaterials)): ?>
                            <h3 style="font-size: 16px; margin-bottom: 10px;">Profillər</h3>
                            <?php foreach ($profileMaterials as $material): ?>
                                <div class="material-item">
                                    <div class="material-name"><?= htmlspecialchars($material['profile_type']) ?></div>
                                    <div class="material-quantity">
                                        <?= $material['total_profile_length'] ?> m
                                        <?php if (!empty($material['total_profile_weight'])): ?>
                                            (<?= $material['total_profile_weight'] ?> kg)
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <?php if (!empty($glassMaterials)): ?>
                            <h3 style="font-size: 16px; margin-bottom: 10px; margin-top: 20px;">Şüşələr</h3>
                            <?php foreach ($glassMaterials as $material): ?>
                                <div class="material-item">
                                    <div class="material-name"><?= htmlspecialchars($material['glass_type']) ?></div>
                                    <div class="material-quantity"><?= $material['total_area'] ?> m²</div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <?php if (!empty($accessories)): ?>
                            <h3 style="font-size: 16px; margin-bottom: 10px; margin-top: 20px;">Aksesuarlar</h3>
                            <?php 
                            $accessorySummary = [];
                            foreach ($accessories as $accessory) {
                                $key = $accessory['accessory_name'] . '-' . $accessory['accessory_unit'];
                                if (!isset($accessorySummary[$key])) {
                                    $accessorySummary[$key] = [
                                        'name' => $accessory['accessory_name'],
                                        'unit' => $accessory['accessory_unit'],
                                        'quantity' => 0
                                    ];
                                }
                                $accessorySummary[$key]['quantity'] += $accessory['quantity'];
                            }
                            
                            foreach ($accessorySummary as $summary): 
                            ?>
                                <div class="material-item">
                                    <div class="material-name"><?= htmlspecialchars($summary['name']) ?></div>
                                    <div class="material-quantity"><?= $summary['quantity'] ?> <?= htmlspecialchars($summary['unit']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Contact Information -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-address-book"></i> Əlaqə Məlumatları
                    </div>
                    <div class="card-body">
                        <div style="margin-bottom: 15px;">
                            <div style="font-weight: 500; margin-bottom: 5px;">Müştəri</div>
                            <div><?= htmlspecialchars($order['customer_name']) ?></div>
                            <div><?= formatPhoneNumber($order['customer_phone']) ?></div>
                        </div>
                        
                        <div style="margin-bottom: 15px;">
                            <div style="font-weight: 500; margin-bottom: 5px;">Satıcı</div>
                            <div><?= htmlspecialchars($order['seller_name']) ?></div>
                            <div><?= formatPhoneNumber($order['seller_phone']) ?></div>
                        </div>
                        
                        <div>
                            <div style="font-weight: 500; margin-bottom: 5px;">Filial</div>
                            <div><?= htmlspecialchars($order['branch_name']) ?></div>
                            <div><?= formatPhoneNumber($order['branch_phone']) ?></div>
                        </div>
                        
                        <div class="contact-buttons">
                            <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $order['seller_phone']) ?>" class="contact-btn whatsapp">
                                <i class="fab fa-whatsapp"></i> Satıcı WhatsApp
                            </a>
                            <a href="tel:<?= preg_replace('/[^0-9]/', '', $order['seller_phone']) ?>" class="contact-btn call">
                                <i class="fas fa-phone"></i> Satıcı Zəng
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Financial Information -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-money-bill-wave"></i> Maliyyə Məlumatları
                    </div>
                    <div class="card-body">
                        <div class="material-item">
                            <div class="material-name">Ümumi Məbləğ</div>
                            <div class="material-quantity"><?= formatMoney($order['total_amount']) ?></div>
                        </div>
                        <div class="material-item">
                            <div class="material-name">Avans Ödəniş</div>
                            <div class="material-quantity"><?= formatMoney($order['advance_payment']) ?></div>
                        </div>
                        <div class="material-item">
                            <div class="material-name">Qalıq Borc</div>
                            <div class="material-quantity" style="<?= $order['remaining_amount'] > 0 ? 'color: #b91c1c;' : '' ?>">
                                <?= formatMoney($order['remaining_amount']) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bottom Navigation -->
    <nav class="bottom-nav">
        <a href="index.php" class="nav-item">
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
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

// Get order ID
$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($orderId <= 0) {
    header('Location: orders.php');
    exit;
}

// Get order details
$conn = getDBConnection();
$sql = "SELECT o.*, 
               c.fullname as customer_name, 
               c.phone as customer_phone,
               c.email as customer_email,
               c.address as customer_address,
               s.fullname as seller_name,
               b.name as branch_name
        FROM orders o 
        JOIN customers c ON o.customer_id = c.id
        LEFT JOIN users s ON o.seller_id = s.id
        LEFT JOIN branches b ON o.branch_id = b.id
        WHERE o.id = ?";

// If not admin, restrict to seller's own orders
if (!hasRole('admin')) {
    $sql .= " AND o.seller_id = ?";
}

$stmt = $conn->prepare($sql);

if (!hasRole('admin')) {
    $stmt->bind_param("ii", $orderId, $sellerId);
} else {
    $stmt->bind_param("i", $orderId);
}

$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    // Order not found or not authorized
    $_SESSION['error_message'] = "Sifariş tapılmadı və ya icazəniz yoxdur";
    header('Location: orders.php');
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

// Get order pricing details
$sql = "SELECT * FROM order_pricing WHERE order_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$pricing = $stmt->get_result()->fetch_assoc();

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
    <title>Sifariş Detalları | AlumPro</title>
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

        /* Order details specific styles */
        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .order-title {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .order-number {
            font-size: 24px;
            font-weight: 700;
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
        }
        
        .badge-info { background-color: #e0f2fe; color: #0369a1; }
        .badge-warning { background-color: #fef3c7; color: #92400e; }
        .badge-success { background-color: #d1fae5; color: #065f46; }
        .badge-danger { background-color: #fee2e2; color: #b91c1c; }
        
        .order-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: var(--primary-gradient);
            color: white;
            border: none;
        }
        
        .btn-primary:hover {
            opacity: 0.9;
        }
        
        .btn-outline {
            background: white;
            color: #374151;
            border: 1px solid #e5e7eb;
        }
        
        .btn-outline:hover {
            background: #f9fafb;
        }
        
        .btn-danger {
            background: #ef4444;
            color: white;
            border: none;
        }
        
        .btn-danger:hover {
            opacity: 0.9;
        }
        
        .detail-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .detail-card {
            background: white;
            border-radius: 8px;
            box-shadow: var(--card-shadow);
            overflow: hidden;
        }
        
        .card-header {
            background: var(--primary-gradient);
            color: white;
            padding: 15px;
        }
        
        .card-title {
            font-size: 16px;
            font-weight: 500;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .card-body {
            padding: 15px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .info-item {
            margin-bottom: 12px;
        }
        
        .info-label {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-weight: 500;
            color: #374151;
        }
        
        .summary-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .summary-table td {
            padding: 8px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .summary-table tr:last-child td {
            border-bottom: none;
        }
        
        .summary-label {
            color: #6b7280;
        }
        
        .summary-value {
            text-align: right;
            font-weight: 500;
            color: #374151;
        }
        
        .summary-total {
            color: var(--primary-color);
            font-weight: 700;
            font-size: 16px;
        }
        
        .products-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
        }
        
        .products-table th {
            background: var(--primary-gradient);
            color: white;
            padding: 12px 15px;
            text-align: left;
        }
        
        .products-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .products-table tbody tr:last-child td {
            border-bottom: none;
        }
        
        .products-table tbody tr:hover {
            background-color: #f9f9f9;
        }
        
        .pdf-embed {
            width: 100%;
            height: 600px;
            border-radius: 8px;
            box-shadow: var(--card-shadow);
            margin-bottom: 30px;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 500;
            margin: 30px 0 15px 0;
            padding-bottom: 10px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .barcode-display {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: var(--card-shadow);
            margin-bottom: 20px;
            text-align: center;
        }
        
        .note-box {
            background: #f9fafb;
            border-left: 4px solid var(--primary-color);
            padding: 15px;
            border-radius: 0 8px 8px 0;
            margin-bottom: 20px;
        }
        
        .note-title {
            font-weight: 500;
            margin-bottom: 10px;
            color: #374151;
        }
        
        /* Status change dropdown */
        .status-dropdown {
            position: relative;
            display: inline-block;
        }
        
        .status-dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            background-color: white;
            min-width: 200px;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
            z-index: 1;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .status-dropdown-content a {
            color: #374151;
            padding: 12px 16px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background 0.2s;
        }
        
        .status-dropdown-content a:hover {
            background-color: #f3f4f6;
        }
        
        .status-dropdown:hover .status-dropdown-content {
            display: block;
        }
        
        /* Timeline styles */
        .timeline {
            position: relative;
            margin-bottom: 30px;
            padding-left: 30px;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            top: 0;
            bottom: 0;
            left: 15px;
            width: 2px;
            background: #e5e7eb;
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 20px;
        }
        
        .timeline-icon {
            position: absolute;
            left: -30px;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: var(--primary-gradient);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }
        
        .timeline-content {
            background: white;
            border-radius: 8px;
            box-shadow: var(--card-shadow);
            padding: 15px;
        }
        
        .timeline-title {
            font-weight: 500;
            margin-bottom: 5px;
        }
        
        .timeline-date {
            font-size: 12px;
            color: #6b7280;
        }
        
        .no-print {
            display: block;
        }
        
        /* Print styles */
        @media print {
            .app-header, .app-footer, .no-print, .order-actions {
                display: none !important;
            }
            
            .app-main {
                padding: 0;
                margin: 0;
            }
            
            body, .app-container {
                margin: 0;
                padding: 0;
            }
            
            .detail-cards {
                display: block;
            }
            
            .detail-card {
                margin-bottom: 20px;
                box-shadow: none;
                border: 1px solid #e5e7eb;
            }
            
            .products-table {
                box-shadow: none;
                border: 1px solid #e5e7eb;
            }
            
            .card-header {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .products-table th {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
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
                <span><?= htmlspecialchars($sellerName) ?></span>
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
                <h1><i class="fas fa-clipboard-list"></i> Sifariş Detalları</h1>
                <div class="breadcrumb">
                    <a href="index.php">Ana Səhifə</a> / <a href="orders.php">Sifarişlər</a> / <span>Detal</span>
                </div>
            </div>

            <!-- Order Header -->
            <div class="order-header">
                <div class="order-title">
                    <div class="order-number">Sifariş #<?= htmlspecialchars($order['order_number']) ?></div>
                    <span class="status-badge badge-<?= $statusInfo['color'] ?>"><?= $statusInfo['text'] ?></span>
                </div>
                
                <div class="order-actions">
                    <?php if (in_array($order['order_status'], ['new', 'processing', 'completed']) && ($order['seller_id'] == $sellerId || hasRole('admin'))): ?>
                        <div class="status-dropdown">
                            <button class="btn btn-primary">
                                <i class="fas fa-exchange-alt"></i> Status Dəyiş
                            </button>
                            <div class="status-dropdown-content">
                                <?php if ($order['order_status'] === 'new'): ?>
                                    <a href="update-order-status.php?id=<?= $order['id'] ?>&status=processing&return=details">
                                        <i class="fas fa-cog text-warning"></i> Hazırlanır
                                    </a>
                                <?php endif; ?>
                                
                                <?php if (in_array($order['order_status'], ['new', 'processing'])): ?>
                                    <a href="update-order-status.php?id=<?= $order['id'] ?>&status=completed&return=details">
                                        <i class="fas fa-check text-success"></i> Hazır
                                    </a>
                                <?php endif; ?>
                                
                                <?php if (in_array($order['order_status'], ['new', 'processing', 'completed'])): ?>
                                    <a href="update-order-status.php?id=<?= $order['id'] ?>&status=delivered&return=details">
                                        <i class="fas fa-truck text-success"></i> Təhvil verildi
                                    </a>
                                <?php endif; ?>
                                
                                <a href="update-order-status.php?id=<?= $order['id'] ?>&status=cancelled&return=details" class="text-danger">
                                    <i class="fas fa-times text-danger"></i> Ləğv et
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (in_array($order['order_status'], ['new', 'processing', 'completed']) && ($order['seller_id'] == $sellerId || hasRole('admin'))): ?>
                        <a href="order-edit.php?id=<?= $order['id'] ?>" class="btn btn-outline">
                            <i class="fas fa-edit"></i> Düzəliş et
                        </a>
                    <?php endif; ?>
                    
                    <a href="order-print.php?id=<?= $order['id'] ?>" class="btn btn-outline no-print">
                        <i class="fas fa-print"></i> Çap et
                    </a>
                    
                    <?php if (!empty($order['pdf_file'])): ?>
                        <a href="<?= htmlspecialchars($order['pdf_file']) ?>" class="btn btn-outline no-print" target="_blank">
                            <i class="fas fa-file-pdf"></i> PDF Yüklə
                        </a>
                    <?php endif; ?>
                    
                    <button onclick="window.print()" class="btn btn-outline no-print">
                        <i class="fas fa-print"></i> Çap et
                    </button>
                </div>
            </div>

            <!-- Details Grid -->
            <div class="detail-cards">
                <!-- Customer Information -->
                <div class="detail-card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-user"></i> Müştəri Məlumatları</h3>
                    </div>
                    <div class="card-body">
                        <div class="info-item">
                            <div class="info-label">Ad Soyad</div>
                            <div class="info-value"><?= htmlspecialchars($order['customer_name']) ?></div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Telefon</div>
                            <div class="info-value"><?= htmlspecialchars($order['customer_phone']) ?></div>
                        </div>
                        
                        <?php if (!empty($order['customer_email'])): ?>
                            <div class="info-item">
                                <div class="info-label">E-poçt</div>
                                <div class="info-value"><?= htmlspecialchars($order['customer_email']) ?></div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($order['customer_address'])): ?>
                            <div class="info-item">
                                <div class="info-label">Ünvan</div>
                                <div class="info-value"><?= htmlspecialchars($order['customer_address']) ?></div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="no-print" style="margin-top: 10px;">
                            <a href="customer-view.php?id=<?= $order['customer_id'] ?>" class="btn btn-outline">
                                <i class="fas fa-eye"></i> Müştəri Profili
                            </a>
                            
                            <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $order['customer_phone']) ?>" class="btn btn-outline" target="_blank">
                                <i class="fab fa-whatsapp" style="color: #25D366;"></i> WhatsApp
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Order Information -->
                <div class="detail-card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-info-circle"></i> Sifariş Məlumatları</h3>
                    </div>
                    <div class="card-body">
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Sifariş №</div>
                                <div class="info-value"><?= htmlspecialchars($order['order_number']) ?></div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label">Tarix</div>
                                <div class="info-value"><?= formatDate($order['order_date']) ?></div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label">Satıcı</div>
                                <div class="info-value"><?= htmlspecialchars($order['seller_name']) ?></div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label">Filial</div>
                                <div class="info-value"><?= htmlspecialchars($order['branch_name']) ?></div>
                            </div>
                        </div>
                        
                        <?php if (!empty($order['initial_note'])): ?>
                            <div class="info-item">
                                <div class="info-label">Qeyd</div>
                                <div class="info-value"><?= nl2br(htmlspecialchars($order['initial_note'])) ?></div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($order['barcode'])): ?>
                            <div class="info-item">
                                <div class="info-label">Barkod</div>
                                <div class="info-value"><?= htmlspecialchars($order['barcode']) ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Financial Information -->
                <div class="detail-card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-money-bill-wave"></i> Maliyyə Məlumatları</h3>
                    </div>
                    <div class="card-body">
                        <table class="summary-table">
                            <tr>
                                <td class="summary-label">Ümumi Məbləğ</td>
                                <td class="summary-value"><?= formatMoney($order['total_amount']) ?></td>
                            </tr>
                            <tr>
                                <td class="summary-label">Avans Ödəniş</td>
                                <td class="summary-value"><?= formatMoney($order['advance_payment']) ?></td>
                            </tr>
                            <tr>
                                <td class="summary-label">Qalıq Borc</td>
                                <td class="summary-value"><?= formatMoney($order['remaining_amount']) ?></td>
                            </tr>
                            <?php if (!empty($order['assembly_fee'])): ?>
                                <tr>
                                    <td class="summary-label">Yığılma Haqqı</td>
                                    <td class="summary-value"><?= formatMoney($order['assembly_fee']) ?></td>
                                </tr>
                            <?php endif; ?>
                            <?php if ($order['remaining_amount'] <= 0): ?>
                                <tr>
                                    <td class="summary-label">Ödəniş Statusu</td>
                                    <td class="summary-value"><span class="status-badge badge-success">Tam Ödənilib</span></td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td class="summary-label">Ödəniş Statusu</td>
                                    <td class="summary-value"><span class="status-badge badge-warning">Qismən Ödənilib</span></td>
                                </tr>
                            <?php endif; ?>
                        </table>
                        
                        <?php if ($order['remaining_amount'] > 0): ?>
                            <div class="no-print" style="margin-top: 15px;">
                                <a href="send-debt-reminder.php?id=<?= $order['customer_id'] ?>&order=<?= $order['order_number'] ?>" class="btn btn-outline">
                                    <i class="fab fa-whatsapp" style="color: #25D366;"></i> Borc Xatırlat
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Profile Items -->
            <?php if (!empty($profiles)): ?>
                <div class="section-title">
                    <i class="fas fa-box"></i> Profil Məhsulları
                </div>
                
                <table class="products-table">
                    <thead>
                        <tr>
                            <th>Profil Növü</th>
                            <th>Hündürlük (sm)</th>
                            <th>En (sm)</th>
                            <th>Sayı</th>
                            <th>Petlə Sayı</th>
                            <th>Qeyd</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($profiles as $profile): ?>
                            <tr>
                                <td><?= htmlspecialchars($profile['profile_type']) ?></td>
                                <td><?= $profile['height'] ?></td>
                                <td><?= $profile['width'] ?></td>
                                <td><?= $profile['quantity'] ?></td>
                                <td><?= $profile['hinge_count'] ?></td>
                                <td><?= nl2br(htmlspecialchars($profile['notes'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <!-- Glass Items -->
            <?php if (!empty($glasses)): ?>
                <div class="section-title">
                    <i class="fas fa-square"></i> Şüşə Məhsulları
                </div>
                
                <table class="products-table">
                    <thead>
                        <tr>
                            <th>Şüşə Növü</th>
                            <th>Hündürlük (sm)</th>
                            <th>En (sm)</th>
                            <th>Sayı</th>
                            <th>Ofset (mm)</th>
                            <th>Sahə (m²)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($glasses as $glass): ?>
                            <tr>
                                <td><?= htmlspecialchars($glass['glass_type']) ?></td>
                                <td><?= $glass['height'] ?></td>
                                <td><?= $glass['width'] ?></td>
                                <td><?= $glass['quantity'] ?></td>
                                <td><?= $glass['offset_mm'] ?></td>
                                <td><?= $glass['area'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <!-- Order Pricing Details -->
            <?php if (!empty($pricing)): ?>
                <div class="section-title">
                    <i class="fas fa-calculator"></i> Qiymət Hesablaması
                </div>
                
                <div class="detail-cards">
                    <div class="detail-card">
                        <div class="card-header">
                            <h3 class="card-title">Profil Məlumatları</h3>
                        </div>
                        <div class="card-body">
                            <table class="summary-table">
                                <tr>
                                    <td class="summary-label">Yan Profillər</td>
                                    <td class="summary-value"><?= $pricing['side_profiles_length'] ?> m</td>
                                </tr>
                                <tr>
                                    <td class="summary-label">Yan Profil Xərci</td>
                                    <td class="summary-value"><?= formatMoney($pricing['side_profiles_price']) ?></td>
                                </tr>
                                <tr>
                                    <td class="summary-label">Qulp Profillər</td>
                                    <td class="summary-value"><?= $pricing['handle_profiles_length'] ?> m</td>
                                </tr>
                                <tr>
                                    <td class="summary-label">Qulp Profil Xərci</td>
                                    <td class="summary-value"><?= formatMoney($pricing['handle_profiles_price']) ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <div class="detail-card">
                        <div class="card-header">
                            <h3 class="card-title">Şüşə və Aksesuarlar</h3>
                        </div>
                        <div class="card-body">
                            <table class="summary-table">
                                <tr>
                                    <td class="summary-label">Şüşə Sahəsi</td>
                                    <td class="summary-value"><?= $pricing['glass_area'] ?> m²</td>
                                </tr>
                                <tr>
                                    <td class="summary-label">Şüşə Xərci</td>
                                    <td class="summary-value"><?= formatMoney($pricing['glass_price']) ?></td>
                                </tr>
                                <tr>
                                    <td class="summary-label">Petlə Sayı</td>
                                    <td class="summary-value"><?= $pricing['hinge_count'] ?> ədəd</td>
                                </tr>
                                <tr>
                                    <td class="summary-label">Petlə Xərci</td>
                                    <td class="summary-value"><?= formatMoney($pricing['hinge_price']) ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <div class="detail-card">
                        <div class="card-header">
                            <h3 class="card-title">Əlavə Xərclər</h3>
                        </div>
                        <div class="card-body">
                            <table class="summary-table">
                                <tr>
                                    <td class="summary-label">Bağlantı Sayı</td>
                                    <td class="summary-value"><?= $pricing['connection_count'] ?> ədəd</td>
                                </tr>
                                <tr>
                                    <td class="summary-label">Bağlantı Xərci</td>
                                    <td class="summary-value"><?= formatMoney($pricing['connection_price']) ?></td>
                                </tr>
                                <tr>
                                    <td class="summary-label">Mexanizm Sayı</td>
                                    <td class="summary-value"><?= $pricing['mechanism_count'] ?> ədəd</td>
                                </tr>
                                <tr>
                                    <td class="summary-label">Mexanizm Xərci</td>
                                    <td class="summary-value"><?= formatMoney($pricing['mechanism_price']) ?></td>
                                </tr>
                                <?php if ($pricing['transport_fee'] > 0): ?>
                                    <tr>
                                        <td class="summary-label">Nəqliyyat Xərci</td>
                                        <td class="summary-value"><?= formatMoney($pricing['transport_fee']) ?></td>
                                    </tr>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Seller Notes -->
            <?php if (!empty($order['seller_notes'])): ?>
                <div class="section-title">
                    <i class="fas fa-sticky-note"></i> Satıcı Qeydləri
                </div>
                
                <div class="note-box">
                    <div class="note-title">Qeydlər:</div>
                    <?= nl2br(htmlspecialchars($order['seller_notes'])) ?>
                </div>
            <?php endif; ?>

            <!-- Drawing Image -->
            <?php if (!empty($order['drawing_image'])): ?>
                <div class="section-title">
                    <i class="fas fa-pencil-alt"></i> Çəkilmiş Sxem
                </div>
                
                <div style="text-align: center; margin-bottom: 20px;">
                    <img src="<?= htmlspecialchars($order['drawing_image']) ?>" alt="Çəkilmiş sxem" style="max-width: 100%; border-radius: 8px; box-shadow: var(--card-shadow);">
                </div>
            <?php endif; ?>

            <!-- Barcode Display -->
            <?php if (!empty($order['barcode'])): ?>
                <div class="section-title no-print">
                    <i class="fas fa-barcode"></i> Barkod
                </div>
                
                <div class="barcode-display no-print">
                    <svg id="barcode"></svg>
                </div>
            <?php endif; ?>

            <!-- PDF Document Display -->
            <?php if (!empty($order['pdf_file'])): ?>
                <div class="section-title no-print">
                    <i class="fas fa-file-pdf"></i> PDF Sənəd
                </div>
                
                <iframe src="<?= htmlspecialchars($order['pdf_file']) ?>" class="pdf-embed no-print"></iframe>
            <?php endif; ?>
        </div>
    </main>

    <!-- Footer -->
    <footer class="app-footer">
        <div>&copy; <?= date('Y') ?> AlumPro.az - Bütün hüquqlar qorunur</div>
    </footer>

    <!-- JsBarcode for barcode display -->
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Show user menu on click
            const userInfo = document.querySelector('.user-info');
            if (userInfo) {
                userInfo.addEventListener('click', function() {
                    this.classList.toggle('open');
                });
            }
            
            // Generate barcode if element exists
            const barcodeElement = document.getElementById('barcode');
            if (barcodeElement) {
                JsBarcode("#barcode", "<?= htmlspecialchars($order['barcode']) ?>", {
                    format: "CODE39",
                    displayValue: true,
                    width: 1.5,
                    height: 60,
                    margin: 10,
                    fontSize: 14
                });
            }
        });
    </script>
</body>
</html>
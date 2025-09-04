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

// Process form submission for barcode scan
$orderData = null;
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['barcode'])) {
    $barcode = trim($_POST['barcode']);
    
    if (empty($barcode)) {
        $error = 'Barkod daxil edilməyib';
    } else {
        $conn = getDBConnection();
        
        // Search for order using barcode
        $sql = "SELECT o.*, c.fullname AS customer_name, b.name AS branch_name, s.fullname AS seller_name
                FROM orders o
                JOIN customers c ON o.customer_id = c.id
                LEFT JOIN branches b ON o.branch_id = b.id
                LEFT JOIN users s ON o.seller_id = s.id
                WHERE o.barcode = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $barcode);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $error = 'Bu barkoda uyğun sifariş tapılmadı';
        } else {
            $orderData = $result->fetch_assoc();
            
            // If action is to update status
            if (isset($_POST['action']) && $_POST['action'] === 'update_status') {
                $newStatus = $_POST['new_status'] ?? '';
                $validStatuses = ['processing', 'completed'];
                
                if (!in_array($newStatus, $validStatuses)) {
                    $error = 'Yanlış status seçimi';
                } else {
                    // Update order status
                    $updateSql = "UPDATE orders SET order_status = ? WHERE id = ?";
                    $updateStmt = $conn->prepare($updateSql);
                    $updateStmt->bind_param("si", $newStatus, $orderData['id']);
                    
                    if ($updateStmt->execute()) {
                        // Log the activity
                        logActivity($userId, 'update_order_status', "Sifariş #{$orderData['order_number']} statusu dəyişildi: {$newStatus}");
                        
                        $success = "Sifariş #{$orderData['order_number']} statusu uğurla yeniləndi: {$newStatus}";
                        
                        // Update order data with new status
                        $orderData['order_status'] = $newStatus;
                    } else {
                        $error = "Status yeniləmə zamanı xəta baş verdi: " . $conn->error;
                    }
                }
            }
            
            // If action is to update delivery info
            if (isset($_POST['action']) && $_POST['action'] === 'mark_delivered') {
                $signatureData = $_POST['signature_data'] ?? '';
                
                if (empty($signatureData)) {
                    $error = 'İmza məlumatları yoxdur';
                } else {
                    // Update order with delivery info and signature
                    $updateSql = "UPDATE orders SET 
                                 order_status = 'delivered', 
                                 delivery_date = NOW(), 
                                 delivered_by = ?,
                                 delivery_signature = ?
                                 WHERE id = ?";
                    
                    $updateStmt = $conn->prepare($updateSql);
                    $updateStmt->bind_param("isi", $userId, $signatureData, $orderData['id']);
                    
                    if ($updateStmt->execute()) {
                        // Save a copy of the signed delivery form
                        $orderNumber = $orderData['order_number'];
                        $customerName = sanitizeFilename($orderData['customer_name']);
                        $deliveryDate = date('Y-m-d');
                        
                        $filename = "{$customerName}_{$orderNumber}_delivery_{$deliveryDate}.jpg";
                        $directoryPath = ORDERS_DIR . "/{$customerName}";
                        
                        // Ensure directory exists
                        if (!file_exists($directoryPath)) {
                            mkdir($directoryPath, 0755, true);
                        }
                        
                        // Save signature image (convert base64 to image)
                        $signatureData = str_replace('data:image/png;base64,', '', $signatureData);
                        $signatureData = str_replace(' ', '+', $signatureData);
                        $signatureImage = base64_decode($signatureData);
                        
                        file_put_contents($directoryPath . '/' . $filename, $signatureImage);
                        
                        // Log the activity
                        logActivity($userId, 'mark_delivered', "Sifariş #{$orderNumber} təhvil verildi.");
                        
                        // Send notification to customer if enabled
                        if (WHATSAPP_ENABLED && !empty($orderData['customer_phone'])) {
                            $template = getNotificationTemplate('order_completed', 'whatsapp');
                            
                            if ($template) {
                                $variables = [
                                    'customer_name' => $orderData['customer_name'],
                                    'order_number' => $orderNumber,
                                    'phone' => COMPANY_PHONE
                                ];
                                
                                $message = processTemplate($template['template_content'], $variables);
                                sendWhatsAppMessage($orderData['customer_phone'], $message);
                            }
                        }
                        
                        $success = "Sifariş #{$orderNumber} uğurla təhvil verilmiş kimi qeyd edildi.";
                        $orderData['order_status'] = 'delivered';
                    } else {
                        $error = "Təhvil məlumatlarını yeniləmə zamanı xəta baş verdi: " . $conn->error;
                    }
                }
            }
            
            // If action is to upload photo
            if (isset($_POST['action']) && $_POST['action'] === 'upload_photo') {
                if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                    $error = 'Şəkil yükləmə zamanı xəta baş verdi';
                } else {
                    $orderNumber = $orderData['order_number'];
                    $customerName = sanitizeFilename($orderData['customer_name']);
                    $uploadDate = date('Y-m-d');
                    
                    $filename = "{$customerName}_{$orderNumber}_photo_{$uploadDate}.jpg";
                    $directoryPath = ORDERS_DIR . "/{$customerName}";
                    
                    // Ensure directory exists
                    if (!file_exists($directoryPath)) {
                        mkdir($directoryPath, 0755, true);
                    }
                    
                    // Upload the photo
                    if (move_uploaded_file($_FILES['photo']['tmp_name'], $directoryPath . '/' . $filename)) {
                        // Log the activity
                        logActivity($userId, 'upload_photo', "Sifariş #{$orderNumber} üçün şəkil yükləndi.");
                        
                        $success = "Sifariş #{$orderNumber} üçün şəkil uğurla yükləndi.";
                    } else {
                        $error = "Şəkli yadda saxlama zamanı xəta baş verdi";
                    }
                }
            }
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
    <title>Barkod Oxuma | AlumPro İstehsalat</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Include QuaggaJS for barcode scanning -->
    <script src="https://cdn.jsdelivr.net/npm/@ericblade/quagga2/dist/quagga.min.js"></script>
    <!-- Include Signature Pad for signatures -->
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.0.0/dist/signature_pad.umd.min.js"></script>
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
            padding-bottom: 20px;
        }
        
        /* Header */
        .app-header {
            background: var(--primary-gradient);
            color: var(--text-light);
            padding: var(--spacing-md);
            text-align: center;
            position: relative;
        }
        
        .header-title {
            font-size: 20px;
            font-weight: 700;
        }
        
        .logout-btn {
            position: absolute;
            right: var(--spacing-md);
            top: 50%;
            transform: translateY(-50%);
            color: white;
            text-decoration: none;
            font-size: 18px;
        }
        
        /* Container */
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: var(--spacing-md);
        }
        
        /* Card styles */
        .card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: var(--spacing-lg);
            margin-bottom: var(--spacing-md);
        }
        
        .card-title {
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
            font-size: 18px;
            font-weight: 500;
            margin-bottom: var(--spacing-md);
        }
        
        .card-title i {
            color: var(--primary-color);
        }
        
        /* Form styles */
        .form-group {
            margin-bottom: var(--spacing-md);
        }
        
        .form-label {
            display: block;
            font-weight: 500;
            margin-bottom: var(--spacing-sm);
        }
        
        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            font-size: 16px;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 177, 90, 0.1);
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 24px;
            border: none;
            border-radius: var(--border-radius);
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
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
        
        .btn-full {
            width: 100%;
        }
        
        .btn-group {
            display: flex;
            gap: var(--spacing-md);
            margin-top: var(--spacing-md);
        }
        
        .btn-icon {
            margin-right: var(--spacing-sm);
        }
        
        /* Alert messages */
        .alert {
            padding: 12px 15px;
            border-radius: var(--border-radius);
            margin-bottom: var(--spacing-md);
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
        
        /* Scanner area */
        .scanner-container {
            position: relative;
            margin-bottom: var(--spacing-md);
            overflow: hidden;
            border-radius: var(--border-radius);
        }
        
        #scanner {
            width: 100%;
            height: 300px;
            background: #000;
            position: relative;
        }
        
        #scanner canvas {
            max-width: 100%;
        }
        
        .scanner-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: white;
            z-index: 10;
        }
        
        .scanner-guides {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            border: 2px solid rgba(255,255,255,0.5);
            border-radius: 20px;
            box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.5);
            margin: 40px;
            z-index: 9;
            pointer-events: none;
        }
        
        /* Order details */
        .order-details {
            margin-top: var(--spacing-md);
            display: none;
        }
        
        .detail-group {
            display: flex;
            margin-bottom: var(--spacing-sm);
            border-bottom: 1px solid #f3f4f6;
            padding-bottom: var(--spacing-sm);
        }
        
        .detail-group:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            font-weight: 500;
            width: 140px;
            color: #6b7280;
        }
        
        .detail-value {
            flex: 1;
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
        
        /* Action buttons */
        .action-buttons {
            margin-top: var(--spacing-md);
            display: flex;
            flex-direction: column;
            gap: var(--spacing-md);
        }
        
        /* Signature pad */
        .signature-container {
            margin-top: var(--spacing-md);
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            overflow: hidden;
            display: none;
        }
        
        #signature-pad {
            width: 100%;
            height: 200px;
            background: white;
        }
        
        .signature-actions {
            display: flex;
            justify-content: space-between;
            padding: var(--spacing-sm);
            background: #f3f4f6;
        }
        
        /* Photo upload */
        .photo-upload {
            margin-top: var(--spacing-md);
            display: none;
        }
        
        .photo-preview {
            max-width: 100%;
            height: auto;
            margin-top: var(--spacing-md);
            border-radius: var(--border-radius);
            display: none;
        }
        
        /* Tabs */
        .tab-buttons {
            display: flex;
            margin-bottom: var(--spacing-md);
            border-bottom: 1px solid #ddd;
        }
        
        .tab-button {
            padding: 10px 20px;
            cursor: pointer;
            border: none;
            background: none;
            font-weight: 500;
            color: #6b7280;
            position: relative;
        }
        
        .tab-button.active {
            color: var(--primary-color);
            border-bottom: 2px solid var(--primary-color);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
    </style>
</head>
<body>
    <header class="app-header">
        <div class="header-title">AlumPro İstehsalat - Barkod Oxuma</div>
        <a href="../auth/logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i></a>
    </header>
    
    <div class="container">
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <h2 class="card-title"><i class="fas fa-barcode"></i> Barkod Oxuma</h2>
            
            <div class="tab-buttons">
                <button class="tab-button active" data-tab="scan">Kamera ilə</button>
                <button class="tab-button" data-tab="manual">Manual daxil etmə</button>
            </div>
            
            <div class="tab-content active" id="scan-tab">
                <div class="scanner-container">
                    <div id="scanner"></div>
                    <div class="scanner-overlay" id="scanner-overlay">
                        <i class="fas fa-camera" style="font-size: 32px; margin-bottom: 10px;"></i>
                        <div>Kameranı aktivləşdirmək üçün klikləyin</div>
                    </div>
                    <div class="scanner-guides"></div>
                </div>
                
                <button class="btn btn-primary btn-full" id="toggle-scanner">
                    <i class="fas fa-camera btn-icon"></i> Kameranı aktivləşdir
                </button>
            </div>
            
            <div class="tab-content" id="manual-tab">
                <form id="barcode-form" method="post" action="">
                    <div class="form-group">
                        <label for="barcode" class="form-label">Barkod</label>
                        <input type="text" id="barcode" name="barcode" class="form-control" placeholder="Barkodu daxil edin" value="<?= htmlspecialchars($_POST['barcode'] ?? '') ?>" autofocus>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-full">
                        <i class="fas fa-search btn-icon"></i> Barkodu axtar
                    </button>
                </form>
            </div>
        </div>
        
        <?php if ($orderData): ?>
            <div class="card order-details" id="order-details" style="display: block;">
                <h2 class="card-title"><i class="fas fa-clipboard-list"></i> Sifariş Məlumatları</h2>
                
                <div class="detail-group">
                    <div class="detail-label">Sifariş №:</div>
                    <div class="detail-value"><?= htmlspecialchars($orderData['order_number']) ?></div>
                </div>
                
                <div class="detail-group">
                    <div class="detail-label">Müştəri:</div>
                    <div class="detail-value"><?= htmlspecialchars($orderData['customer_name']) ?></div>
                </div>
                
                <div class="detail-group">
                    <div class="detail-label">Tarix:</div>
                    <div class="detail-value"><?= formatDate($orderData['order_date']) ?></div>
                </div>
                
                <div class="detail-group">
                    <div class="detail-label">Filial:</div>
                    <div class="detail-value"><?= htmlspecialchars($orderData['branch_name']) ?></div>
                </div>
                
                <div class="detail-group">
                    <div class="detail-label">Satıcı:</div>
                    <div class="detail-value"><?= htmlspecialchars($orderData['seller_name']) ?></div>
                </div>
                
                <div class="detail-group">
                    <div class="detail-label">Məbləğ:</div>
                    <div class="detail-value"><?= formatMoney($orderData['total_amount']) ?></div>
                </div>
                
                <div class="detail-group">
                    <div class="detail-label">Status:</div>
                    <div class="detail-value">
                        <?php 
                            $statusInfo = $statusConfig[$orderData['order_status']] ?? ['text' => 'Bilinmir', 'color' => 'info'];
                        ?>
                        <span class="status-badge badge-<?= $statusInfo['color'] ?>"><?= $statusInfo['text'] ?></span>
                    </div>
                </div>
                
                <?php if (!empty($orderData['initial_note'])): ?>
                    <div class="detail-group">
                        <div class="detail-label">Qeyd:</div>
                        <div class="detail-value"><?= nl2br(htmlspecialchars($orderData['initial_note'])) ?></div>
                    </div>
                <?php endif; ?>
                
                <!-- Status Update Actions -->
                <div class="action-buttons">
                    <?php if (in_array($orderData['order_status'], ['new', 'processing'])): ?>
                        <form method="post" action="">
                            <input type="hidden" name="barcode" value="<?= htmlspecialchars($orderData['barcode']) ?>">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="new_status" value="<?= $orderData['order_status'] === 'new' ? 'processing' : 'completed' ?>">
                            
                            <button type="submit" class="btn btn-primary btn-full">
                                <?php if ($orderData['order_status'] === 'new'): ?>
                                    <i class="fas fa-cog btn-icon"></i> Hazırlanır kimi işarələ
                                <?php else: ?>
                                    <i class="fas fa-check btn-icon"></i> Hazır kimi işarələ
                                <?php endif; ?>
                            </button>
                        </form>
                    <?php endif; ?>
                    
                    <?php if ($orderData['order_status'] === 'completed'): ?>
                        <button class="btn btn-primary btn-full" id="show-signature">
                            <i class="fas fa-signature btn-icon"></i> İmza ilə təhvil ver
                        </button>
                    <?php endif; ?>
                    
                    <button class="btn btn-secondary btn-full" id="show-photo-upload">
                        <i class="fas fa-camera btn-icon"></i> Şəkil çək/yüklə
                    </button>
                    
                    <a href="view-pdf.php?barcode=<?= htmlspecialchars($orderData['barcode']) ?>" class="btn btn-secondary btn-full" target="_blank">
                        <i class="fas fa-file-pdf btn-icon"></i> PDF Göstər
                    </a>
                </div>
                
                <!-- Signature Pad Container -->
                <div class="signature-container" id="signature-container">
                    <h3 style="padding: 10px; background: #f3f4f6;">Təhvil alanın imzası</h3>
                    <canvas id="signature-pad"></canvas>
                    <div class="signature-actions">
                        <button class="btn btn-secondary" id="clear-signature">Təmizlə</button>
                        <button class="btn btn-primary" id="save-signature">Təsdiq et və təhvil ver</button>
                    </div>
                </div>
                
                <!-- Hidden form for signature submission -->
                <form id="signature-form" method="post" action="" style="display:none;">
                    <input type="hidden" name="barcode" value="<?= htmlspecialchars($orderData['barcode']) ?>">
                    <input type="hidden" name="action" value="mark_delivered">
                    <input type="hidden" name="signature_data" id="signature_data">
                </form>
                
                <!-- Photo Upload Section -->
                <div class="photo-upload" id="photo-upload">
                    <h3 style="margin-bottom: 10px;">Şəkil yüklə</h3>
                    <form method="post" action="" enctype="multipart/form-data" id="photo-form">
                        <input type="hidden" name="barcode" value="<?= htmlspecialchars($orderData['barcode']) ?>">
                        <input type="hidden" name="action" value="upload_photo">
                        
                        <div class="form-group">
                            <label for="photo" class="form-label">Şəkil seçin və ya çəkin</label>
                            <input type="file" id="photo" name="photo" class="form-control" accept="image/*" capture>
                        </div>
                        
                        <img id="photo-preview" class="photo-preview" src="#" alt="Preview">
                        
                        <div class="btn-group">
                            <button type="button" class="btn btn-secondary" id="cancel-photo">Ləğv et</button>
                            <button type="submit" class="btn btn-primary">Yüklə</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Tab functionality
        const tabButtons = document.querySelectorAll('.tab-button');
        const tabContents = document.querySelectorAll('.tab-content');
        
        tabButtons.forEach(button => {
            button.addEventListener('click', function() {
                const tabId = this.getAttribute('data-tab');
                
                // Deactivate all tabs
                tabButtons.forEach(btn => btn.classList.remove('active'));
                tabContents.forEach(content => content.classList.remove('active'));
                
                // Activate current tab
                this.classList.add('active');
                document.getElementById(tabId + '-tab').classList.add('active');
            });
        });
        
        // Barcode scanner functionality
        const scannerElement = document.getElementById('scanner');
        const scannerOverlay = document.getElementById('scanner-overlay');
        const toggleScannerBtn = document.getElementById('toggle-scanner');
        let scanner = null;
        let scannerActive = false;
        
        function initScanner() {
            Quagga.init({
                inputStream: {
                    name: "Live",
                    type: "LiveStream",
                    target: scannerElement,
                    constraints: {
                        facingMode: "environment" // Use back camera
                    }
                },
                decoder: {
                    readers: ["code_39_reader", "code_128_reader", "ean_reader", "ean_8_reader", "code_39_vin_reader"]
                }
            }, function(err) {
                if (err) {
                    console.error("Error initializing scanner:", err);
                    return;
                }
                
                scannerActive = true;
                Quagga.start();
                scannerOverlay.style.display = 'none';
                toggleScannerBtn.innerHTML = '<i class="fas fa-stop btn-icon"></i> Kameranı söndür';
            });
            
            Quagga.onDetected(function(result) {
                const barcode = result.codeResult.code;
                console.log("Barcode detected:", barcode);
                
                // Stop scanner
                if (scannerActive) {
                    Quagga.stop();
                    scannerActive = false;
                }
                
                // Fill form and submit
                document.getElementById('barcode').value = barcode;
                document.getElementById('barcode-form').submit();
            });
        }
        
        toggleScannerBtn.addEventListener('click', function() {
            if (scannerActive) {
                Quagga.stop();
                scannerActive = false;
                scannerOverlay.style.display = 'flex';
                this.innerHTML = '<i class="fas fa-camera btn-icon"></i> Kameranı aktivləşdir';
            } else {
                initScanner();
            }
        });
        
        scannerOverlay.addEventListener('click', function() {
            if (!scannerActive) {
                initScanner();
            }
        });
        
        // Signature pad functionality
        const showSignatureBtn = document.getElementById('show-signature');
        const signatureContainer = document.getElementById('signature-container');
        let signaturePad = null;
        
        if (showSignatureBtn) {
            showSignatureBtn.addEventListener('click', function() {
                signatureContainer.style.display = 'block';
                
                const canvas = document.getElementById('signature-pad');
                signaturePad = new SignaturePad(canvas, {
                    backgroundColor: 'rgb(255, 255, 255)'
                });
                
                // Adjust canvas size
                function resizeCanvas() {
                    const ratio = Math.max(window.devicePixelRatio || 1, 1);
                    canvas.width = canvas.offsetWidth * ratio;
                    canvas.height = canvas.offsetHeight * ratio;
                    canvas.getContext("2d").scale(ratio, ratio);
                    signaturePad.clear(); // Clear the canvas
                }
                
                window.addEventListener("resize", resizeCanvas);
                resizeCanvas();
                
                // Scroll to signature pad
                signatureContainer.scrollIntoView({ behavior: 'smooth' });
            });
            
            // Clear signature
            document.getElementById('clear-signature').addEventListener('click', function() {
                if (signaturePad) {
                    signaturePad.clear();
                }
            });
            
            // Save signature and submit form
            document.getElementById('save-signature').addEventListener('click', function() {
                if (signaturePad && !signaturePad.isEmpty()) {
                    const signatureData = signaturePad.toDataURL();
                    document.getElementById('signature_data').value = signatureData;
                    document.getElementById('signature-form').submit();
                } else {
                    alert('Zəhmət olmasa imzalayın');
                }
            });
        }
        
        // Photo upload functionality
        const showPhotoBtn = document.getElementById('show-photo-upload');
        const photoUploadSection = document.getElementById('photo-upload');
        const photoInput = document.getElementById('photo');
        const photoPreview = document.getElementById('photo-preview');
        
        if (showPhotoBtn) {
            showPhotoBtn.addEventListener('click', function() {
                photoUploadSection.style.display = 'block';
                photoUploadSection.scrollIntoView({ behavior: 'smooth' });
            });
            
            document.getElementById('cancel-photo').addEventListener('click', function() {
                photoUploadSection.style.display = 'none';
                photoInput.value = '';
                photoPreview.style.display = 'none';
            });
            
            photoInput.addEventListener('change', function(e) {
                if (e.target.files && e.target.files[0]) {
                    const reader = new FileReader();
                    
                    reader.onload = function(e) {
                        photoPreview.src = e.target.result;
                        photoPreview.style.display = 'block';
                    };
                    
                    reader.readAsDataURL(e.target.files[0]);
                }
            });
        }
    </script>
</body>
</html>
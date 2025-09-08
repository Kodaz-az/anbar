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

// Get database connection
$conn = getDBConnection();

// Check if the company column exists
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
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sifariş Çapı #<?= $order['order_number'] ?> | AlumPro Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @page {
            size: A4;
            margin: 10mm;
        }
        
        body {
            font-family: 'Roboto', sans-serif;
            margin: 0;
            padding: 0;
            font-size: 12pt;
            line-height: 1.5;
            color: #333;
        }
        
        .print-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #ddd;
        }
        
        .company-info {
            flex: 1;
        }
        
        .company-logo {
            font-size: 24pt;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .company-details {
            font-size: 10pt;
            color: #666;
        }
        
        .order-info {
            text-align: right;
        }
        
        .order-number {
            font-size: 18pt;
            font-weight: bold;
            margin-bottom: 5px;
            color: #1eb15a;
        }
        
        .order-date {
            font-size: 10pt;
            color: #666;
        }
        
        .section {
            margin-bottom: 20px;
        }
        
        .section-title {
            font-size: 14pt;
            font-weight: bold;
            margin-bottom: 10px;
            color: #1eb15a;
        }
        
        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .customer-info, .order-details {
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 5px;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 8px;
        }
        
        .info-label {
            flex: 1;
            font-weight: 500;
        }
        
        .info-value {
            flex: 2;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        
        th {
            background-color: #f5f5f5;
            font-weight: 500;
        }
        
        .summary {
            margin-top: 20px;
            display: flex;
            justify-content: flex-end;
        }
        
        .summary-table {
            width: 300px;
            border-collapse: collapse;
        }
        
        .summary-table th, .summary-table td {
            padding: 8px;
            text-align: right;
        }
        
        .summary-table th {
            font-weight: normal;
        }
        
        .summary-table .total-row td {
            font-weight: bold;
            font-size: 14pt;
            border-top: 2px solid #1eb15a;
        }
        
        .notes {
            margin-top: 20px;
            padding: 15px;
            background-color: #f9f9f9;
            border-radius: 5px;
        }
        
        .signature-area {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
        }
        
        .signature-box {
            width: 45%;
        }
        
        .signature-line {
            margin-top: 70px;
            border-top: 1px solid #ddd;
            padding-top: 5px;
            text-align: center;
            font-size: 10pt;
            color: #666;
        }
        
        .barcode-area {
            text-align: center;
            margin: 30px 0;
        }
        
        .barcode {
            font-family: 'Libre Barcode 39', cursive;
            font-size: 40pt;
            letter-spacing: 5px;
        }
        
        .footer {
            margin-top: 40px;
            text-align: center;
            font-size: 9pt;
            color: #999;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
        
        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 10px 20px;
            background-color: #1eb15a;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .print-button i {
            font-size: 16px;
        }
        
        .back-button {
            position: fixed;
            top: 20px;
            left: 20px;
            padding: 10px 20px;
            background-color: #4b5563;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
        }
        
        .back-button i {
            font-size: 16px;
        }
        
        @media print {
            .print-button, .back-button {
                display: none;
            }
            
            body {
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <a href="orders.php" class="back-button">
        <i class="fas fa-arrow-left"></i> Geri qayıt
    </a>
    
    <button class="print-button" onclick="window.print()">
        <i class="fas fa-print"></i> Çap et
    </button>
    
    <div class="print-header">
        <div class="company-info">
            <div class="company-logo">ALUMPRO.AZ</div>
            <div class="company-details">
                <?= $order['branch_name'] ?> filialı<br>
                Tel: +994 50 123 45 67<br>
                Email: info@alumpro.az
            </div>
        </div>
        
        <div class="order-info">
            <div class="order-number">Sifariş #<?= $order['order_number'] ?></div>
            <div class="order-date">
                Tarix: <?= date('d.m.Y', strtotime($order['order_date'])) ?><br>
                <?php if (!empty($order['delivery_date'])): ?>
                    Təhvil tarixi: <?= date('d.m.Y', strtotime($order['delivery_date'])) ?><br>
                <?php endif; ?>
                Status: <?= translateOrderStatus($order['order_status']) ?>
            </div>
        </div>
    </div>
    
    <div class="grid">
        <div class="customer-info">
            <div class="section-title">Müştəri məlumatları</div>
            
            <div class="info-row">
                <div class="info-label">Ad, Soyad:</div>
                <div class="info-value"><?= htmlspecialchars($order['customer_name']) ?></div>
            </div>
            
            <?php if ($hasCompanyColumn && !empty($order['customer_company'])): ?>
            <div class="info-row">
                <div class="info-label">Şirkət:</div>
                <div class="info-value"><?= htmlspecialchars($order['customer_company']) ?></div>
            </div>
            <?php endif; ?>
            
            <div class="info-row">
                <div class="info-label">Telefon:</div>
                <div class="info-value"><?= htmlspecialchars($order['customer_phone']) ?></div>
            </div>
            
            <?php if (!empty($order['customer_address'])): ?>
            <div class="info-row">
                <div class="info-label">Ünvan:</div>
                <div class="info-value"><?= htmlspecialchars($order['customer_address']) ?></div>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="order-details">
            <div class="section-title">Sifariş detalları</div>
            
            <div class="info-row">
                <div class="info-label">Satıcı:</div>
                <div class="info-value"><?= htmlspecialchars($order['seller_name']) ?></div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Filial:</div>
                <div class="info-value"><?= htmlspecialchars($order['branch_name']) ?></div>
            </div>
            
            <?php if (!empty($order['barcode'])): ?>
            <div class="info-row">
                <div class="info-label">Barkod:</div>
                <div class="info-value"><?= htmlspecialchars($order['barcode']) ?></div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($order['delivery_date'])): ?>
            <div class="info-row">
                <div class="info-label">Təhvil tarixi:</div>
                <div class="info-value"><?= date('d.m.Y', strtotime($order['delivery_date'])) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if (!empty($profiles)): ?>
    <div class="section">
        <div class="section-title">Profillər</div>
        
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Profil növü</th>
                    <th>En (cm)</th>
                    <th>Hündürlük (cm)</th>
                    <th>Say</th>
                    <th>Petlə sayı</th>
                    <th>Qeyd</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($profiles as $index => $profile): ?>
                <tr>
                    <td><?= $index + 1 ?></td>
                    <td><?= htmlspecialchars($profile['profile_type']) ?></td>
                    <td><?= $profile['width'] ?></td>
                    <td><?= $profile['height'] ?></td>
                    <td><?= $profile['quantity'] ?></td>
                    <td><?= $profile['hinge_count'] ?></td>
                    <td><?= htmlspecialchars($profile['notes'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($glass)): ?>
    <div class="section">
        <div class="section-title">Şüşələr</div>
        
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Şüşə növü</th>
                    <th>En (cm)</th>
                    <th>Hündürlük (cm)</th>
                    <th>Say</th>
                    <th>Offset (mm)</th>
                    <th>Sahə (m²)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($glass as $index => $item): ?>
                <tr>
                    <td><?= $index + 1 ?></td>
                    <td><?= htmlspecialchars($item['glass_type']) ?></td>
                    <td><?= $item['width'] ?></td>
                    <td><?= $item['height'] ?></td>
                    <td><?= $item['quantity'] ?></td>
                    <td><?= $item['offset_mm'] ?></td>
                    <td><?= $item['area'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <div class="summary">
        <table class="summary-table">
            <tr>
                <th>Ümumi məbləğ:</th>
                <td><?= formatMoney($order['total_amount']) ?></td>
            </tr>
            <?php if ($order['assembly_fee'] > 0): ?>
            <tr>
                <th>Quraşdırma xidməti:</th>
                <td><?= formatMoney($order['assembly_fee']) ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <th>Avans ödəniş:</th>
                <td><?= formatMoney($order['advance_payment']) ?></td>
            </tr>
            <tr>
                <th>Qalıq məbləğ:</th>
                <td><?= formatMoney($order['remaining_amount']) ?></td>
            </tr>
            <tr class="total-row">
                <th>Ümumi:</th>
                <td><?= formatMoney($order['total_amount']) ?></td>
            </tr>
        </table>
    </div>
    
    <?php if (!empty($order['initial_note']) || !empty($order['seller_notes']) || !empty($order['admin_notes'])): ?>
    <div class="notes">
        <div class="section-title">Qeydlər</div>
        <?php if (!empty($order['initial_note'])): ?>
            <p><strong>Müştəri qeydi:</strong> <?= nl2br(htmlspecialchars($order['initial_note'])) ?></p>
        <?php endif; ?>
        
        <?php if (!empty($order['seller_notes'])): ?>
            <p><strong>Satıcı qeydi:</strong> <?= nl2br(htmlspecialchars($order['seller_notes'])) ?></p>
        <?php endif; ?>
        
        <?php if (!empty($order['admin_notes'])): ?>
            <p><strong>Admin qeydi:</strong> <?= nl2br(htmlspecialchars($order['admin_notes'])) ?></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($order['drawing_image'])): ?>
    <div class="section">
        <div class="section-title">Eskiz</div>
        <img src="<?= $order['drawing_image'] ?>" alt="Drawing" style="max-width: 100%; border: 1px solid #ddd;">
    </div>
    <?php endif; ?>
    
    <div class="signature-area">
        <div class="signature-box">
            <div class="signature-line">Satıcı imzası</div>
        </div>
        
        <div class="signature-box">
            <div class="signature-line">Müştəri imzası</div>
        </div>
    </div>
    
    <?php if (!empty($order['barcode'])): ?>
    <div class="barcode-area">
        <div class="barcode">*<?= $order['barcode'] ?>*</div>
        <div><?= $order['barcode'] ?></div>
    </div>
    <?php endif; ?>
    
    <div class="footer">
        <p>Bu çek sifarişin təsdiqlənməsi üçün rəsmi sənəddir.<br>
        AlumPro.az - Bütün hüquqlar qorunur &copy; <?= date('Y') ?></p>
    </div>

    <script>
        // Auto-print when the page loads (optional)
        window.onload = function() {
            // Uncomment the line below to automatically print when page loads
            // window.print();
        };
    </script>
</body>
</html>
<?php
/**
 * Translate order status to Azerbaijani
 */
function translateOrderStatus($status) {
    $translations = [
        'new' => 'Yeni',
        'processing' => 'İşlənir',
        'completed' => 'Hazırdır',
        'delivered' => 'Təhvil verilib',
        'cancelled' => 'Ləğv edilib'
    ];
    
    return $translations[$status] ?? $status;
}
?>
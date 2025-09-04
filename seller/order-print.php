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

// Get user information
$userId = $_SESSION['user_id'];
$userName = $_SESSION['fullname'];

// Get order ID
$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($orderId <= 0) {
    header('Location: orders.php');
    exit;
}

// Get order details
$conn = getDBConnection();
$sql = "SELECT o.*, 
               c.fullname AS customer_name, c.phone AS customer_phone, c.company AS customer_company, c.address AS customer_address,
               s.fullname AS seller_name, s.phone AS seller_phone,
               b.name AS branch_name, b.address AS branch_address, b.phone AS branch_phone
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

// Get order accessories
$sql = "SELECT oa.*, a.name as accessory_name, a.unit as accessory_unit 
        FROM order_accessories oa
        LEFT JOIN accessories a ON oa.accessory_id = a.id
        WHERE oa.order_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$accessories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Generate barcode image using a simple function
function generateBarcodeImageUrl($barcode) {
    return "https://barcode.tec-it.com/barcode.ashx?data=" . urlencode($barcode) . "&code=Code128&dpi=96";
}

// Order status text
$statusText = [
    'new' => 'Yeni',
    'processing' => 'Hazırlanır',
    'completed' => 'Hazır',
    'delivered' => 'Təhvil verilib',
    'cancelled' => 'Ləğv edilib'
];
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sifariş Çapı #<?= htmlspecialchars($order['order_number']) ?> | AlumPro</title>
    <style>
        @page {
            size: A4;
            margin: 10mm;
        }
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            font-size: 12pt;
            line-height: 1.4;
        }
        .page {
            padding: 20px;
            box-sizing: border-box;
        }
        .header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            border-bottom: 1px solid #ccc;
            padding-bottom: 10px;
        }
        .logo {
            font-size: 24pt;
            font-weight: bold;
            color: #1eb15a;
        }
        .company-info {
            text-align: right;
            font-size: 10pt;
        }
        .order-title {
            text-align: center;
            margin: 20px 0;
            font-size: 16pt;
            font-weight: bold;
        }
        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .detail-box {
            border: 1px solid #ccc;
            padding: 10px;
            border-radius: 5px;
        }
        .detail-title {
            font-weight: bold;
            margin-bottom: 5px;
            border-bottom: 1px solid #eee;
            padding-bottom: 5px;
        }
        .detail-item {
            margin-bottom: 5px;
        }
        .barcode-container {
            text-align: center;
            margin: 20px 0;
        }
        .barcode-number {
            margin-top: 5px;
            font-size: 10pt;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        table, th, td {
            border: 1px solid #ccc;
        }
        th, td {
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        .summary {
            margin-top: 30px;
            display: flex;
            justify-content: space-between;
        }
        .payment-info {
            width: 45%;
        }
        .signatures {
            width: 45%;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        .signature-line {
            display: flex;
            justify-content: space-between;
        }
        .signature-box {
            border-top: 1px solid #000;
            width: 150px;
            text-align: center;
            padding-top: 5px;
            font-size: 10pt;
        }
        .notes {
            margin-top: 20px;
            border-top: 1px solid #ccc;
            padding-top: 10px;
        }
        .notes-title {
            font-weight: bold;
            margin-bottom: 5px;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            font-size: 10pt;
            color: #666;
            border-top: 1px solid #ccc;
            padding-top: 10px;
        }
        @media print {
            .no-print {
                display: none;
            }
            a {
                text-decoration: none;
                color: black;
            }
            .page {
                page-break-after: always;
            }
        }
        .print-header {
            display: flex;
            justify-content: space-between;
            padding: 10px;
            background: #f5f5f5;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        .print-button {
            background: #1eb15a;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
        }
        .back-button {
            background: #6c757d;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <!-- Print Controls -->
    <div class="print-header no-print">
        <a href="order-details.php?id=<?= $orderId ?>" class="back-button">
            <i class="fas fa-arrow-left"></i> Geri Qayıt
        </a>
        <button onclick="window.print()" class="print-button">
            <i class="fas fa-print"></i> Çap Et
        </button>
    </div>
    
    <div class="page">
        <!-- Header -->
        <div class="header">
            <div class="logo">AlumPro.az</div>
            <div class="company-info">
                <div><?= COMPANY_NAME ?></div>
                <div><?= COMPANY_ADDRESS ?></div>
                <div>Tel: <?= COMPANY_PHONE ?></div>
                <div>Email: <?= COMPANY_EMAIL ?></div>
                <div>VÖEN: <?= COMPANY_VAT_ID ?></div>
            </div>
        </div>
        
        <!-- Order Title -->
        <div class="order-title">
            SİFARİŞ #<?= htmlspecialchars($order['order_number']) ?>
        </div>
        
        <!-- Barcode -->
        <div class="barcode-container">
            <img src="<?= generateBarcodeImageUrl($order['barcode']) ?>" alt="Barcode" height="60">
            <div class="barcode-number"><?= htmlspecialchars($order['barcode']) ?></div>
        </div>
        
        <!-- Order Details -->
        <div class="details-grid">
            <div class="detail-box">
                <div class="detail-title">Sifariş Məlumatları</div>
                <div class="detail-item"><strong>Sifariş №:</strong> <?= htmlspecialchars($order['order_number']) ?></div>
                <div class="detail-item"><strong>Tarix:</strong> <?= formatDate($order['order_date'], 'd.m.Y H:i') ?></div>
                <div class="detail-item"><strong>Status:</strong> <?= $statusText[$order['order_status']] ?? 'Bilinmir' ?></div>
                <div class="detail-item"><strong>Filial:</strong> <?= htmlspecialchars($order['branch_name']) ?></div>
                <div class="detail-item"><strong>Satıcı:</strong> <?= htmlspecialchars($order['seller_name']) ?></div>
            </div>
            
            <div class="detail-box">
                <div class="detail-title">Müştəri Məlumatları</div>
                <div class="detail-item"><strong>Ad Soyad:</strong> <?= htmlspecialchars($order['customer_name']) ?></div>
                <div class="detail-item"><strong>Telefon:</strong> <?= htmlspecialchars($order['customer_phone']) ?></div>
                <?php if (!empty($order['customer_company'])): ?>
                    <div class="detail-item"><strong>Şirkət:</strong> <?= htmlspecialchars($order['customer_company']) ?></div>
                <?php endif; ?>
                <?php if (!empty($order['customer_address'])): ?>
                    <div class="detail-item"><strong>Ünvan:</strong> <?= htmlspecialchars($order['customer_address']) ?></div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Profiles Table -->
        <?php if (!empty($profiles)): ?>
            <h3>Profil Məhsulları</h3>
            <table>
                <thead>
                    <tr>
                        <th>№</th>
                        <th>Profil Növü</th>
                        <th>Ölçülər</th>
                        <th>Sayı</th>
                        <th>Petlə Sayı</th>
                        <th>Rəng</th>
                        <th>Qeyd</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($profiles as $index => $profile): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td><?= htmlspecialchars($profile['profile_type']) ?></td>
                            <td><?= $profile['width'] ?> x <?= $profile['height'] ?> sm</td>
                            <td><?= $profile['quantity'] ?> ədəd</td>
                            <td><?= $profile['hinge_count'] ?> ədəd</td>
                            <td><?= htmlspecialchars($profile['color'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($profile['notes'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        
        <!-- Glass Table -->
        <?php if (!empty($glasses)): ?>
            <h3>Şüşə Məhsulları</h3>
            <table>
                <thead>
                    <tr>
                        <th>№</th>
                        <th>Şüşə Növü</th>
                        <th>Ölçülər</th>
                        <th>Sayı</th>
                        <th>Sahə (m²)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($glasses as $index => $glass): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td><?= htmlspecialchars($glass['glass_type']) ?></td>
                            <td><?= $glass['width'] ?> x <?= $glass['height'] ?> sm</td>
                            <td><?= $glass['quantity'] ?> ədəd</td>
                            <td><?= number_format($glass['area'], 2) ?> m²</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        
        <!-- Accessories Table -->
        <?php if (!empty($accessories)): ?>
            <h3>Aksesuarlar</h3>
            <table>
                <thead>
                    <tr>
                        <th>№</th>
                        <th>Aksesuar</th>
                        <th>Miqdar</th>
                        <th>Ölçü Vahidi</th>
                        <th>Qeyd</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accessories as $index => $accessory): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td><?= htmlspecialchars($accessory['accessory_name']) ?></td>
                            <td><?= $accessory['quantity'] ?></td>
                            <td><?= htmlspecialchars($accessory['accessory_unit']) ?></td>
                            <td><?= htmlspecialchars($accessory['notes'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        
        <!-- Summary -->
        <div class="summary">
            <div class="payment-info">
                <h3>Ödəniş Məlumatları</h3>
                <table>
                    <tbody>
                        <tr>
                            <th>Ümumi Məbləğ:</th>
                            <td><?= formatMoney($order['total_amount']) ?></td>
                        </tr>
                        <tr>
                            <th>Avans Ödəniş:</th>
                            <td><?= formatMoney($order['advance_payment']) ?></td>
                        </tr>
                        <tr>
                            <th>Qalıq Borc:</th>
                            <td><?= formatMoney($order['remaining_amount']) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <div class="signatures">
                <div class="signature-line">
                    <div class="signature-box">Satıcı</div>
                    <div class="signature-box">Müştəri</div>
                </div>
                
                <div class="signature-line">
                    <div>Tarix: <?= date('d.m.Y') ?></div>
                </div>
            </div>
        </div>
        
        <!-- Notes -->
        <?php if (!empty($order['initial_note']) || !empty($order['seller_notes'])): ?>
            <div class="notes">
                <div class="notes-title">Qeydlər:</div>
                <?php if (!empty($order['initial_note'])): ?>
                    <div><?= nl2br(htmlspecialchars($order['initial_note'])) ?></div>
                <?php endif; ?>
                <?php if (!empty($order['seller_notes'])): ?>
                    <div><strong>Satıcı qeydi:</strong> <?= nl2br(htmlspecialchars($order['seller_notes'])) ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <!-- Footer -->
        <div class="footer">
            <p>Bu sənəd AlumPro.az sistemi tərəfindən <?= date('d.m.Y H:i') ?> tarixində yaradılmışdır.</p>
            <p><?= COMPANY_NAME ?> © <?= date('Y') ?> | <?= COMPANY_PHONE ?> | <?= COMPANY_EMAIL ?></p>
        </div>
    </div>
</body>
</html>
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
if (!hasRole(ROLE_ADMIN)) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

// Get admin's information
$adminId = $_SESSION['user_id'];
$adminName = $_SESSION['fullname'];

// Get customer ID from query parameter
$customerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Initialize variables
$customer = null;
$outstandingOrders = [];
$totalDebt = 0;
$error = '';
$success = '';

// If no customer ID provided, redirect to customers page
if ($customerId <= 0) {
    header('Location: customers.php');
    exit;
}

// Get database connection
$conn = getDBConnection();

// Get customer information
$stmt = $conn->prepare("SELECT c.*, u.email FROM customers c LEFT JOIN users u ON c.user_id = u.id WHERE c.id = ?");
$stmt->bind_param("i", $customerId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Customer not found, redirect to customers page
    header('Location: customers.php?error=customer_not_found');
    exit;
}

$customer = $result->fetch_assoc();

// Get orders with outstanding balance
$stmt = $conn->prepare("SELECT id, order_number, order_date, total_amount, advance_payment, remaining_amount 
                        FROM orders 
                        WHERE customer_id = ? AND remaining_amount > 0
                        ORDER BY order_date DESC");
$stmt->bind_param("i", $customerId);
$stmt->execute();
$outstandingOrders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Calculate total debt
foreach ($outstandingOrders as $order) {
    $totalDebt += $order['remaining_amount'];
}

// Process form submission for sending debt reminder
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_reminder'])) {
    $messageType = sanitizeInput($_POST['message_type'] ?? 'default');
    $customMessage = sanitizeInput($_POST['custom_message'] ?? '');
    
    if (empty($customer['phone'])) {
        $error = 'Müştərinin telefon nömrəsi yoxdur';
    } else {
        // Clean phone number
        $phone = preg_replace('/[^0-9]/', '', $customer['phone']);
        
        try {
            // Check if WhatsApp integration is enabled
            if (defined('WHATSAPP_ENABLED') && WHATSAPP_ENABLED) {
                require_once '../includes/whatsapp.php';
                
                // Prepare message content
                $orderNumbers = [];
                foreach ($outstandingOrders as $order) {
                    $orderNumbers[] = $order['order_number'];
                }
                $orderNumbersStr = implode(', ', $orderNumbers);
                
                // Prepare variables for WhatsApp template
                $variables = [
                    'customer_name' => $customer['fullname'],
                    'total_debt' => formatMoney($totalDebt),
                    'order_numbers' => $orderNumbersStr,
                    'company_name' => defined('COMPANY_NAME') ? COMPANY_NAME : 'AlumPro',
                    'company_phone' => defined('COMPANY_PHONE') ? COMPANY_PHONE : '',
                    'payment_link' => defined('PAYMENT_LINK') ? PAYMENT_LINK : '',
                    'custom_message' => $customMessage
                ];
                
                // Choose template based on message type
                $templateName = 'debt_reminder_default';
                
                switch ($messageType) {
                    case 'first_reminder':
                        $templateName = 'debt_reminder_first';
                        break;
                    case 'second_reminder':
                        $templateName = 'debt_reminder_second';
                        break;
                    case 'urgent':
                        $templateName = 'debt_reminder_urgent';
                        break;
                    case 'custom':
                        $templateName = 'debt_reminder_custom';
                        break;
                    default:
                        $templateName = 'debt_reminder_default';
                }
                
                // Send WhatsApp message
                $result = sendWhatsAppTemplate($phone, $templateName, $variables);
                
                if ($result && isset($result['success']) && $result['success']) {
                    // Log activity
                    logActivity($adminId, 'send_debt_reminder', "Admin borc xatırlatması göndərdi: {$customer['fullname']} (ID: $customerId) - {$totalDebt}₼");
                    
                    // Update debt reminder sent time
                    $stmt = $conn->prepare("UPDATE customers SET last_debt_reminder = NOW() WHERE id = ?");
                    $stmt->bind_param("i", $customerId);
                    $stmt->execute();
                    
                    $success = 'Borc xatırlatması uğurla göndərildi';
                } else {
                    $error = 'WhatsApp mesajı göndərilmədi: ' . ($result['message'] ?? 'Bilinməyən xəta');
                }
            } else {
                // WhatsApp integration not enabled, redirect to WhatsApp web
                $message = "Salam " . $customer['fullname'] . "! Sizin AlumPro-da " . formatMoney($totalDebt) . " məbləğində borcunuz var.";
                if (!empty($orderNumbers)) {
                    $message .= " Sifariş nömrələri: " . $orderNumbersStr;
                }
                
                if (!empty($customMessage)) {
                    $message .= "\n\n" . $customMessage;
                }
                
                $whatsappUrl = "https://wa.me/" . $phone . "?text=" . urlencode($message);
                
                // Log activity
                logActivity($adminId, 'send_debt_reminder', "Admin borc xatırlatması göndərdi: {$customer['fullname']} (ID: $customerId) - {$totalDebt}₼");
                
                // Update debt reminder sent time
                $stmt = $conn->prepare("UPDATE customers SET last_debt_reminder = NOW() WHERE id = ?");
                $stmt->bind_param("i", $customerId);
                $stmt->execute();
                
                // Redirect to WhatsApp
                header("Location: $whatsappUrl");
                exit;
            }
        } catch (Exception $e) {
            $error = 'Borc xatırlatması göndərilərkən xəta: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Borc Xatırlatması Göndər | AlumPro Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        :root {
            --primary-color: #1eb15a;
            --secondary-color: #1e5eb1;
            --primary-gradient: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            --card-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --border-radius: 8px;
        }
        
        .customer-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 30px;
            padding: 20px;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
        }
        
        .customer-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
            font-weight: 700;
        }
        
        .customer-info {
            flex: 1;
        }
        
        .customer-name {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .customer-detail {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        
        .detail-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #6b7280;
        }
        
        .detail-item i {
            color: var(--primary-color);
        }
        
        .debt-summary {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .debt-total {
            font-size: 24px;
            font-weight: 700;
            color: #ef4444;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .reminder-form {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .form-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .message-types {
            margin-bottom: 20px;
        }
        
        .message-type {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 15px;
            border: 1px solid #e5e7eb;
            border-radius: var(--border-radius);
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .message-type:hover {
            background: #f9fafb;
        }
        
        .message-type.active {
            border-color: var(--primary-color);
            background: rgba(30, 177, 90, 0.05);
        }
        
        .message-type-radio {
            margin-right: 10px;
        }
        
        .message-type-info {
            flex: 1;
        }
        
        .message-type-title {
            font-weight: 500;
            margin-bottom: 5px;
        }
        
        .message-type-description {
            font-size: 14px;
            color: #6b7280;
        }
        
        .custom-message {
            margin-top: 20px;
            display: none;
        }
        
        .custom-message.show {
            display: block;
        }
        
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 30px;
        }
        
        .btn-whatsapp {
            background: #25d366;
            color: white;
            border: none;
            border-radius: var(--border-radius);
            padding: 10px 20px;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        
        .btn-whatsapp:hover {
            background: #1da851;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th, .table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .table th {
            font-weight: 500;
            color: #6b7280;
            background: #f9fafb;
        }
        
        .table tr:last-child td {
            border-bottom: none;
        }
        
        .has-debt {
            color: #ef4444;
            font-weight: 500;
        }
        
        .payment-details {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .payment-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .payment-info {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 10px;
        }
        
        .payment-label {
            font-weight: 500;
            color: #6b7280;
        }
        
        .payment-value {
            font-weight: 500;
        }
        
        .message-preview {
            margin-top: 20px;
            padding: 15px;
            background: #f9fafb;
            border-radius: var(--border-radius);
            border: 1px dashed #d1d5db;
        }
        
        .message-preview-title {
            font-weight: 500;
            margin-bottom: 10px;
            color: #6b7280;
        }
        
        .message-preview-content {
            white-space: pre-wrap;
            font-family: 'Roboto', sans-serif;
            padding: 10px;
            background: white;
            border-radius: 8px;
            border-top-left-radius: 0;
            position: relative;
            margin-left: 20px;
        }
        
        .message-preview-content::before {
            content: '';
            position: absolute;
            top: 0;
            left: -10px;
            width: 0;
            height: 0;
            border-top: 10px solid white;
            border-left: 10px solid transparent;
        }
        
        @media (max-width: 768px) {
            .customer-header {
                flex-direction: column;
                text-align: center;
            }
            
            .customer-detail {
                justify-content: center;
            }
            
            .payment-info {
                grid-template-columns: 1fr;
                gap: 5px;
            }
            
            .payment-label {
                margin-top: 10px;
                border-bottom: 1px solid #f3f4f6;
                padding-bottom: 5px;
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
                <a href="index.php"><i class="fas fa-tachometer-alt"></i> Panel</a>
                <a href="users.php"><i class="fas fa-users"></i> İstifadəçilər</a>
                <a href="orders.php"><i class="fas fa-clipboard-list"></i> Sifarişlər</a>
                <a href="customers.php" class="active"><i class="fas fa-user-tie"></i> Müştərilər</a>
                <a href="inventory.php"><i class="fas fa-boxes"></i> Anbar</a>
                <a href="branches.php"><i class="fas fa-building"></i> Filiallar</a>
                <a href="reports.php"><i class="fas fa-chart-bar"></i> Hesabatlar</a>
                <a href="settings.php"><i class="fas fa-cog"></i> Tənzimləmələr</a>
            </div>
        </div>
        <div class="header-right">
            <div class="user-info">
                <span><?= htmlspecialchars($adminName) ?> <i class="fas fa-angle-down"></i></span>
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
                <h1><i class="fab fa-whatsapp"></i> Borc Xatırlatması Göndər</h1>
                <div class="breadcrumb">
                    <a href="index.php">Panel</a> / 
                    <a href="customers.php">Müştərilər</a> / 
                    <a href="customers.php?id=<?= $customerId ?>"><?= htmlspecialchars($customer['fullname']) ?></a> / 
                    <span>Borc Xatırlatması</span>
                </div>
            </div>
            
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
            
            <!-- Customer Information -->
            <div class="customer-header">
                <div class="customer-avatar">
                    <?= strtoupper(substr($customer['fullname'], 0, 1)) ?>
                </div>
                <div class="customer-info">
                    <div class="customer-name"><?= htmlspecialchars($customer['fullname']) ?></div>
                    <div class="customer-detail">
                        <div class="detail-item">
                            <i class="fas fa-phone"></i>
                            <span><?= htmlspecialchars($customer['phone']) ?></span>
                        </div>
                        <?php if (!empty($customer['email'])): ?>
                            <div class="detail-item">
                                <i class="fas fa-envelope"></i>
                                <span><?= htmlspecialchars($customer['email']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($customer['address'])): ?>
                            <div class="detail-item">
                                <i class="fas fa-map-marker-alt"></i>
                                <span><?= htmlspecialchars($customer['address']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($customer['last_debt_reminder'])): ?>
                            <div class="detail-item">
                                <i class="fas fa-clock"></i>
                                <span>Son xatırlatma: <?= formatDate($customer['last_debt_reminder']) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
                        <!-- Debt Summary -->
            <div class="debt-summary">
                <div class="debt-total">
                    <i class="fas fa-exclamation-triangle"></i> Ümumi borc məbləği: <?= formatMoney($totalDebt) ?>
                </div>
                
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Sifariş №</th>
                                <th>Tarix</th>
                                <th>Ümumi məbləğ</th>
                                <th>Ödənilib</th>
                                <th>Qalıq borc</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($outstandingOrders as $order): ?>
                                <tr>
                                    <td><a href="order-details.php?id=<?= $order['id'] ?>"><?= htmlspecialchars($order['order_number']) ?></a></td>
                                    <td><?= formatDate($order['order_date']) ?></td>
                                    <td><?= formatMoney($order['total_amount']) ?></td>
                                    <td><?= formatMoney($order['advance_payment']) ?></td>
                                    <td class="has-debt"><?= formatMoney($order['remaining_amount']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <td colspan="4" style="text-align: right; font-weight: 700;">Ümumi borc:</td>
                                <td class="has-debt" style="font-weight: 700;"><?= formatMoney($totalDebt) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Payment Details -->
            <div class="payment-details">
                <div class="payment-title">
                    <i class="fas fa-money-bill-wave"></i> Ödəniş Məlumatları
                </div>
                <div class="payment-info">
                    <div class="payment-label">Bank adı:</div>
                    <div class="payment-value"><?= defined('COMPANY_BANK_NAME') ? COMPANY_BANK_NAME : 'Kapital Bank' ?></div>
                    
                    <div class="payment-label">Hesab sahibi:</div>
                    <div class="payment-value"><?= defined('COMPANY_ACCOUNT_NAME') ? COMPANY_ACCOUNT_NAME : 'AlumPro MMC' ?></div>
                    
                    <div class="payment-label">IBAN:</div>
                    <div class="payment-value"><?= defined('COMPANY_IBAN') ? COMPANY_IBAN : 'AZ12NABZ12345678901234567890' ?></div>
                    
                    <div class="payment-label">SWIFT/BIC:</div>
                    <div class="payment-value"><?= defined('COMPANY_SWIFT') ? COMPANY_SWIFT : 'KAPBAZ22' ?></div>
                    
                    <?php if (defined('COMPANY_PAYMENT_LINK') && !empty(COMPANY_PAYMENT_LINK)): ?>
                        <div class="payment-label">Onlayn ödəniş:</div>
                        <div class="payment-value">
                            <a href="<?= COMPANY_PAYMENT_LINK ?>" target="_blank">
                                <i class="fas fa-external-link-alt"></i> Onlayn ödəniş linki
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Send Reminder Form -->
            <form method="post" action="" class="reminder-form">
                <div class="form-title">
                    <i class="fab fa-whatsapp"></i> WhatsApp Borc Xatırlatması Göndər
                </div>
                
                <div class="message-types">
                    <div class="message-type active">
                        <input type="radio" name="message_type" value="default" id="message_type_default" class="message-type-radio" checked>
                        <div class="message-type-info">
                            <div class="message-type-title">Standart Xatırlatma</div>
                            <div class="message-type-description">Müştəriyə standart borc xatırlatması mesajı göndər</div>
                        </div>
                    </div>
                    
                    <div class="message-type">
                        <input type="radio" name="message_type" value="first_reminder" id="message_type_first" class="message-type-radio">
                        <div class="message-type-info">
                            <div class="message-type-title">İlk Xatırlatma</div>
                            <div class="message-type-description">Nəzakətli ilk xatırlatma mesajı</div>
                        </div>
                    </div>
                    
                    <div class="message-type">
                        <input type="radio" name="message_type" value="second_reminder" id="message_type_second" class="message-type-radio">
                        <div class="message-type-info">
                            <div class="message-type-title">İkinci Xatırlatma</div>
                            <div class="message-type-description">Daha ciddi tonda ikinci xatırlatma</div>
                        </div>
                    </div>
                    
                    <div class="message-type">
                        <input type="radio" name="message_type" value="urgent" id="message_type_urgent" class="message-type-radio">
                        <div class="message-type-info">
                            <div class="message-type-title">Təcili Xatırlatma</div>
                            <div class="message-type-description">Təcili ödəniş tələb edən ciddi xatırlatma</div>
                        </div>
                    </div>
                    
                    <div class="message-type">
                        <input type="radio" name="message_type" value="custom" id="message_type_custom" class="message-type-radio">
                        <div class="message-type-info">
                            <div class="message-type-title">Özəl Mesaj</div>
                            <div class="message-type-description">Öz mesajınızı yazın</div>
                        </div>
                    </div>
                </div>
                
                <div class="custom-message" id="custom_message_container">
                    <label for="custom_message" class="form-label">Özəl mesajınızı yazın:</label>
                    <textarea id="custom_message" name="custom_message" class="form-control" rows="5" placeholder="Hörmətli müştəri, borcunuzu ödəməyinizi xahiş edirik..."></textarea>
                    <div class="form-text mt-2">
                        <small>Mesajınızda istifadə edə biləcəyiniz dəyişənlər: {customer_name}, {total_debt}, {company_name}</small>
                    </div>
                </div>
                
                <div class="message-preview">
                    <div class="message-preview-title">Mesaj önizləməsi:</div>
                    <div class="message-preview-content" id="message_preview">
                        Salam <?= htmlspecialchars($customer['fullname']) ?>!
                        
AlumPro olaraq xatırladırıq ki, hesabınızda <?= formatMoney($totalDebt) ?> məbləğində ödənilməmiş borc var.

Ödənişi tezliklə etməyinizi xahiş edirik.

Ödəniş üçün bank məlumatları:
- Bank: <?= defined('COMPANY_BANK_NAME') ? COMPANY_BANK_NAME : 'Kapital Bank' ?>
- IBAN: <?= defined('COMPANY_IBAN') ? COMPANY_IBAN : 'AZ12NABZ12345678901234567890' ?>

Hər hansı sualınız olarsa, bizimlə əlaqə saxlaya bilərsiniz.

Hörmətlə,
AlumPro komandası
                    </div>
                </div>
                
                <div class="form-actions">
                    <a href="customers.php?id=<?= $customerId ?>" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Geri qayıt
                    </a>
                    <button type="submit" name="send_reminder" class="btn-whatsapp">
                        <i class="fab fa-whatsapp"></i> WhatsApp ilə Göndər
                    </button>
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
            if (userInfo) {
                userInfo.addEventListener('click', function() {
                    this.classList.toggle('open');
                });
            }
            
            // Message type selection
            const messageTypes = document.querySelectorAll('.message-type');
            const customMessageContainer = document.getElementById('custom_message_container');
            const messagePreview = document.getElementById('message_preview');
            
            // Default message templates
            const messageTemplates = {
                default: `Salam <?= htmlspecialchars($customer['fullname']) ?>!
                        
AlumPro olaraq xatırladırıq ki, hesabınızda <?= formatMoney($totalDebt) ?> məbləğində ödənilməmiş borc var.

Ödənişi tezliklə etməyinizi xahiş edirik.

Ödəniş üçün bank məlumatları:
- Bank: <?= defined('COMPANY_BANK_NAME') ? COMPANY_BANK_NAME : 'Kapital Bank' ?>
- IBAN: <?= defined('COMPANY_IBAN') ? COMPANY_IBAN : 'AZ12NABZ12345678901234567890' ?>

Hər hansı sualınız olarsa, bizimlə əlaqə saxlaya bilərsiniz.

Hörmətlə,
AlumPro komandası`,
                
                first_reminder: `Salam <?= htmlspecialchars($customer['fullname']) ?>!
                        
AlumPro olaraq sizə xatırlatmaq istəyirik ki, hesabınızda <?= formatMoney($totalDebt) ?> məbləğində ödənilməmiş borc var.

Bu, sadəcə xatırlatmadır. Əgər artıq ödəmisinizsə, zəhmət olmasa bizi məlumatlandırın.

Ödəniş üçün bank məlumatları:
- Bank: <?= defined('COMPANY_BANK_NAME') ? COMPANY_BANK_NAME : 'Kapital Bank' ?>
- IBAN: <?= defined('COMPANY_IBAN') ? COMPANY_IBAN : 'AZ12NABZ12345678901234567890' ?>

Əməkdaşlığınız üçün təşəkkür edirik.

Hörmətlə,
AlumPro komandası`,
                
                second_reminder: `Salam <?= htmlspecialchars($customer['fullname']) ?>!
                        
AlumPro olaraq sizə ikinci dəfə xatırlatmaq istəyirik ki, hesabınızda <?= formatMoney($totalDebt) ?> məbləğində ödənilməmiş borc var.

Borc ödənişinin vaxtı keçdiyindən, tezliklə ödəniş etməyiniz xahiş olunur.

Ödəniş üçün bank məlumatları:
- Bank: <?= defined('COMPANY_BANK_NAME') ? COMPANY_BANK_NAME : 'Kapital Bank' ?>
- IBAN: <?= defined('COMPANY_IBAN') ? COMPANY_IBAN : 'AZ12NABZ12345678901234567890' ?>

Ödəniş ilə bağlı hər hansı çətinliyiniz varsa, zəhmət olmasa bizimlə əlaqə saxlayın.

Hörmətlə,
AlumPro komandası`,
                
                urgent: `DİQQƏT! Təcili ödəniş xatırlatması

Hörmətli <?= htmlspecialchars($customer['fullname']) ?>,

AlumPro olaraq bildiririk ki, hesabınızda <?= formatMoney($totalDebt) ?> məbləğində VAXTİ KEÇMİŞ borc var. 

Bu borcun TƏCİLİ ödənilməsi tələb olunur!

Ödənişi 3 iş günü ərzində etməyiniz xahiş olunur, əks halda əlavə tədbirlər görülə bilər.

Ödəniş üçün bank məlumatları:
- Bank: <?= defined('COMPANY_BANK_NAME') ? COMPANY_BANK_NAME : 'Kapital Bank' ?>
- IBAN: <?= defined('COMPANY_IBAN') ? COMPANY_IBAN : 'AZ12NABZ12345678901234567890' ?>

Təcili olaraq bizimlə əlaqə saxlayın: <?= defined('COMPANY_PHONE') ? COMPANY_PHONE : '+994 XX XXX XX XX' ?>

AlumPro MMC`,
                
                custom: ''
            };
            
            // Update message preview when type changes
            function updateMessagePreview(type) {
                if (type === 'custom') {
                    customMessageContainer.classList.add('show');
                    const customMessage = document.getElementById('custom_message').value || 'Özəl mesajınızı buraya yazın...';
                    messagePreview.textContent = customMessage;
                } else {
                    customMessageContainer.classList.remove('show');
                    messagePreview.textContent = messageTemplates[type];
                }
            }
            
            // Set up message type selection
            messageTypes.forEach(type => {
                type.addEventListener('click', function() {
                    const radio = this.querySelector('input[type="radio"]');
                    radio.checked = true;
                    
                    // Remove active class from all types
                    messageTypes.forEach(t => t.classList.remove('active'));
                    
                    // Add active class to selected type
                    this.classList.add('active');
                    
                    // Update message preview
                    updateMessagePreview(radio.value);
                });
            });
            
            // Update preview when custom message changes
            const customMessageInput = document.getElementById('custom_message');
            if (customMessageInput) {
                customMessageInput.addEventListener('input', function() {
                    if (document.getElementById('message_type_custom').checked) {
                        messagePreview.textContent = this.value || 'Özəl mesajınızı buraya yazın...';
                    }
                });
            }
        });
    </script>
</body>
</html>
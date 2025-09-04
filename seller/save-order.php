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

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: hesabla.php');
    exit;
}

// Get seller's information and branch
$sellerId = $_SESSION['user_id'];
$branchId = $_SESSION['branch_id'];

// Get form data
$orderNumber = $_POST['order_number'] ?? '';
$customerId = $_POST['customer_id'] ?? '';
$initialNote = $_POST['initial_note'] ?? '';
$sellerNotes = $_POST['seller_notes'] ?? '';
$totalAmount = floatval($_POST['total_amount'] ?? 0);
$assemblyFee = floatval($_POST['assembly_fee'] ?? 0);
$advancePayment = floatval($_POST['advance_payment'] ?? 0);
$remainingAmount = floatval($_POST['remaining_amount'] ?? 0);
$profileData = $_POST['profile_data'] ?? '';
$glassData = $_POST['glass_data'] ?? '';
$drawingData = $_POST['drawing_data'] ?? '';

// Validate required fields
if (empty($orderNumber) || empty($customerId)) {
    $_SESSION['error_message'] = 'Məcburi sahələr doldurulmayıb';
    header('Location: hesabla.php');
    exit;
}

// Validate amount
if ($totalAmount <= 0) {
    $_SESSION['error_message'] = 'Sifariş məbləği düzgün deyil';
    header('Location: hesabla.php');
    exit;
}

// Generate barcode value
$barcodeValue = generateBarcodeValue($orderNumber);

// Connect to database
$conn = getDBConnection();

try {
    // Start transaction
    $conn->begin_transaction();
    
    // 1. Insert into orders table
    $stmt = $conn->prepare("INSERT INTO orders (
        order_number, customer_id, seller_id, branch_id, order_date, barcode,
        total_amount, assembly_fee, advance_payment, remaining_amount,
        initial_note, seller_notes, drawing_image
    ) VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $stmt->bind_param(
        "siissddddsss",
        $orderNumber, $customerId, $sellerId, $branchId, $barcodeValue,
        $totalAmount, $assemblyFee, $advancePayment, $remainingAmount,
        $initialNote, $sellerNotes, $drawingData
    );
    
    $stmt->execute();
    $orderId = $conn->insert_id;
    
    if (!$orderId) {
        throw new Exception("Sifariş yaradıla bilmədi");
    }
    
    // 2. Insert order pricing details
    $profileDataObj = json_decode($profileData, true);
    $glassDataObj = json_decode($glassData, true);
    
    // Calculate profile amounts
    $sideProfilesLength = $profileDataObj['side']['length'] ?? 0;
    $sideProfilesPrice = $profileDataObj['side']['cost'] ?? 0;
    
    $handleLength = 0;
    $handlePrice = 0;
    
    // Calculate handle profiles total
    foreach (['bq', 'qulp110', 'qulp20'] as $handleType) {
        if (isset($profileDataObj['handle'][$handleType])) {
            $handleLength += $profileDataObj['handle'][$handleType]['length'] ?? 0;
            $handlePrice += $profileDataObj['handle'][$handleType]['cost'] ?? 0;
        }
    }
    
    // Calculate glass total
    $glassArea = 0;
    $glassPrice = 0;
    
    foreach ($glassDataObj as $glass) {
        $glassArea += $glass['area'] ?? 0;
        $glassPrice += $glass['cost'] ?? 0;
    }
    
    // Get other pricing details
    $hingeCount = intval($_POST['hinge_count'] ?? 0);
    $hingePrice = floatval($_POST['hinge_price'] ?? 0) * $hingeCount;
    $connCount = intval($_POST['conn_count'] ?? 0);
    $connPrice = floatval($_POST['conn_price'] ?? 0) * $connCount;
    $mechCount = intval($_POST['mech_count'] ?? 0);
    $mechPrice = floatval($_POST['mech_price'] ?? 0) * $mechCount;
    $transportFee = floatval($_POST['transport_fee'] ?? 0);
    
    // Insert pricing details
    $stmt = $conn->prepare("INSERT INTO order_pricing (
        order_id, side_profiles_length, side_profiles_price, 
        handle_profiles_length, handle_profiles_price,
        glass_area, glass_price,
        hinge_count, hinge_price,
        connection_count, connection_price,
        mechanism_count, mechanism_price,
        transport_fee
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $stmt->bind_param(
        "iddddddidiiddd",
        $orderId, $sideProfilesLength, $sideProfilesPrice,
        $handleLength, $handlePrice,
        $glassArea, $glassPrice,
        $hingeCount, $hingePrice,
        $connCount, $connPrice,
        $mechCount, $mechPrice,
        $transportFee
    );
    
    $stmt->execute();
    
    // 3. Extract and insert order profiles
    $rows = json_decode($_POST['profile_rows'] ?? '[]', true);
    
    foreach ($rows as $row) {
        $profileType = $row['type'] ?? '';
        $height = floatval($row['height'] ?? 0);
        $width = floatval($row['width'] ?? 0);
        $quantity = intval($row['quantity'] ?? 0);
        $hingeCount = intval($row['hinge_count'] ?? 0);
        $notes = $row['notes'] ?? '';
        
        if ($height > 0 && $width > 0 && $quantity > 0) {
            $stmt = $conn->prepare("INSERT INTO order_profiles (
                order_id, profile_type, height, width, quantity, hinge_count, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->bind_param(
                "isddiss",
                $orderId, $profileType, $height, $width, $quantity, $hingeCount, $notes
            );
            
            $stmt->execute();
        }
    }
    
    // 4. Extract and insert order glass
    $glassRows = json_decode($_POST['glass_rows'] ?? '[]', true);
    
    foreach ($glassRows as $row) {
        $glassType = $row['type'] ?? '';
        $height = floatval($row['height'] ?? 0);
        $width = floatval($row['width'] ?? 0);
        $quantity = intval($row['quantity'] ?? 0);
        $offsetMm = intval($row['offset'] ?? 0);
        $area = floatval($row['area'] ?? 0);
        
        if ($height > 0 && $width > 0 && $quantity > 0 && $area > 0) {
            $stmt = $conn->prepare("INSERT INTO order_glass (
                order_id, glass_type, height, width, quantity, offset_mm, area
            ) VALUES (?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->bind_param(
                "isddiidd",
                $orderId, $glassType, $height, $width, $quantity, $offsetMm, $area
            );
            
            $stmt->execute();
        }
    }
    
    // 5. Update customer data
    $stmt = $conn->prepare("UPDATE customers SET 
        total_orders = total_orders + 1,
        total_payment = total_payment + ?,
        advance_payment = advance_payment + ?,
        remaining_debt = remaining_debt + ?,
        updated_by = ?,
        updated_at = NOW()
    WHERE id = ?");
    
    $stmt->bind_param(
        "dddii",
        $advancePayment, $advancePayment, $remainingAmount, $sellerId, $customerId
    );
    
    $stmt->execute();
    
    // 6. Adjust inventory for used materials
    // Note: This would require detailed implementation based on your inventory management logic
    
    // 7. Save PDF to customer directory
    $customerName = '';
    $customerPhone = '';
    
    $stmt = $conn->prepare("SELECT fullname, phone FROM customers WHERE id = ?");
    $stmt->bind_param("i", $customerId);
    $stmt->execute();
    $stmt->bind_result($customerName, $customerPhone);
    $stmt->fetch();
    $stmt->close();
    
    // Create customer directory
    $customerDir = createCustomerDirectory(sanitizeFilename($customerName));
    $pdfPath = $customerDir . '/' . sanitizeFilename($customerName) . '_' . $barcodeValue . '.pdf';
    
    // PDF path will be saved by JavaScript separately
    
    // 8. Send WhatsApp notification if enabled
    if (WHATSAPP_ENABLED && !empty($customerPhone)) {
        $template = getNotificationTemplate('new_order', 'whatsapp');
        
        if ($template) {
            $variables = [
                'customer_name' => $customerName,
                'order_date' => date('d.m.Y'),
                'order_number' => $orderNumber,
                'total_amount' => formatMoney($totalAmount)
            ];
            
            $message = processTemplate($template['template_content'], $variables);
            sendWhatsAppMessage($customerPhone, $message);
        }
    }
    
    // 9. Log activity
    logActivity($sellerId, 'create_order', "Yeni sifariş yaradıldı: #$orderNumber, Müştəri: $customerName, Məbləğ: $totalAmount AZN");
    
    // Commit transaction
    $conn->commit();
    
    // Clear current order number from session so a new one will be generated
    unset($_SESSION['current_order_number']);
    
    // Set success message
    $_SESSION['success_message'] = "Sifariş #$orderNumber uğurla yaradıldı";
    
    // Redirect to orders page
    header('Location: orders.php');
    exit;
    
} catch (Exception $e) {
    // Rollback transaction
    $conn->rollback();
    
    // Log error
    error_log("Order creation error: " . $e->getMessage());
    
    // Set error message
    $_SESSION['error_message'] = "Sifariş yaradıla bilmədi: " . $e->getMessage();
    
    // Redirect back to order form
    header('Location: hesabla.php');
    exit;
}
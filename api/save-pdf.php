<?php
// save-pdf.php - PDF faylları müştəri qovluqlarında saxlamaq üçün server tərəfli skript
session_start();
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

// Giriş məlumatlarını alın
$postData = json_decode(file_get_contents('php://input'), true);

if (!$postData || !isset($postData['pdf']) || !isset($postData['directory']) || !isset($postData['filename'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Tələb olunan məlumatlar yoxdur']);
    exit;
}

// Məlumatları alın
$pdf_data = $postData['pdf'];
$directory = $postData['directory'];
$filename = $postData['filename'];

// PDF data-nı base64-dən decode edin
// base64 prefix-i təmizləyin: "data:application/pdf;base64,"
$base64_data = substr($pdf_data, strpos($pdf_data, ',') + 1);
$pdf_content = base64_decode($base64_data);

if (!$pdf_content) {
    http_response_code(400);
    echo json_encode(['error' => 'Yanlış PDF məlumatları']);
    exit;
}

// Qovluğun varlığını yoxlayın və yaradın
$directory = $_SERVER['DOCUMENT_ROOT'] . $directory;

if (!file_exists($directory)) {
    if (!mkdir($directory, 0755, true)) {
        http_response_code(500);
        echo json_encode(['error' => 'Qovluğu yaratmaq mümkün olmadı: ' . $directory]);
        exit;
    }
}

// Faylı yadda saxlayın
$filepath = $directory . '/' . $filename;

if (file_put_contents($filepath, $pdf_content) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Faylı yadda saxlamaq mümkün olmadı']);
    exit;
}

// Save order PDF path to database if this is related to an order
if (strpos($filename, '_') !== false) {
    $parts = explode('_', $filename);
    if (count($parts) > 1) {
        $barcode = str_replace('.pdf', '', $parts[1]);
        
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE orders SET pdf_file = ? WHERE barcode = ?");
        $relativePath = '/sifarisler/' . basename($directory) . '/' . $filename;
        $stmt->bind_param("ss", $relativePath, $barcode);
        $stmt->execute();
    }
}

// Log activity
logActivity($_SESSION['user_id'] ?? 0, 'save_pdf', "PDF saved: $filepath");

// Müvəffəqiyyət cavabı qaytarın
echo json_encode(['success' => true, 'message' => 'PDF uğurla yadda saxlanıldı', 'path' => $filepath]);
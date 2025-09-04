<?php
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

// Check if user has appropriate role
if (!hasRole(['admin', 'seller'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// Get search query
$search = $_GET['search'] ?? '';

if (empty($search)) {
    echo json_encode([]);
    exit;
}

// Connect to database
$conn = getDBConnection();

// Search in customers
$searchTerm = '%' . $search . '%';
$sql = "SELECT id, fullname, phone, email FROM customers 
        WHERE fullname LIKE ? OR phone LIKE ? 
        ORDER BY fullname ASC LIMIT 10";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $searchTerm, $searchTerm);
$stmt->execute();
$result = $stmt->get_result();

$customers = [];
while ($row = $result->fetch_assoc()) {
    $customers[] = $row;
}

// Return results as JSON
header('Content-Type: application/json');
echo json_encode($customers);
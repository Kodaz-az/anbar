<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Log user logout activity
if (isset($_SESSION['user_id'])) {
    $userId = $_SESSION['user_id'];
    logActivity($userId, 'logout', 'İstifadəçi sistemdən çıxış etdi');
}

// Clear all session variables
$_SESSION = array();

// Destroy the session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 42000, '/');
}

// Destroy the session
session_destroy();

// Redirect to login page
header('Location: login.php');
exit;
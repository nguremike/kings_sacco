<?php
session_start();

// Application configuration
define('APP_NAME', 'SACCO Management System');
define('APP_URL', 'http://localhost/kings-sacco');
define('APP_VERSION', '1.0.0');

// Date and time settings
date_default_timezone_set('Africa/Nairobi');

// Include database
require_once __DIR__ . '/database.php';

// Check if user is logged in
function isLoggedIn()
{
    return isset($_SESSION['user_id']);
}

// Check if user has role
function hasRole($role)
{
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] == $role;
}

// Redirect if not logged in
function requireLogin()
{
    if (!isLoggedIn()) {
        header('Location: ' . APP_URL . '/login.php');
        exit();
    }
}

// Redirect if not authorized
function requireRole($role)
{
    requireLogin();
    if (!hasRole($role) && $_SESSION['user_role'] != 'admin') {
        header('Location: ' . APP_URL . '/dashboard.php');
        exit();
    }
}

// Get current user ID
function getCurrentUserId()
{
    return $_SESSION['user_id'] ?? 0;
}

// Get current user role
function getCurrentUserRole()
{
    return $_SESSION['user_role'] ?? '';
}

// Format currency
function formatCurrency($amount)
{
    return 'KES ' . number_format($amount, 2);
}

// Format date
function formatDate($date)
{
    return date('d M Y', strtotime($date));
}

// Generate member number
function generateMemberNumber()
{
    $year = date('Y');
    $month = date('m');
    $random = rand(1000, 9999);
    return "MEM{$year}{$month}{$random}";
}

// Generate loan number
function generateLoanNumber()
{
    $year = date('Y');
    $random = rand(10000, 99999);
    return "LN{$year}{$random}";
}

// Log audit trail
function logAudit($action, $table, $record_id, $old_data = null, $new_data = null)
{
    $user_id = getCurrentUserId();
    $ip = $_SERVER['REMOTE_ADDR'];
    $user_agent = $_SERVER['HTTP_USER_AGENT'];

    $sql = "INSERT INTO audit_logs (user_id, action, table_name, record_id, old_data, new_data, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

    executeQuery($sql, "isssssss", [
        $user_id,
        $action,
        $table,
        $record_id,
        json_encode($old_data),
        json_encode($new_data),
        $ip,
        $user_agent
    ]);
}

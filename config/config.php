<?php
session_start();

// Application configuration
define('APP_NAME', 'KINGS UNITED SACCO');
define('APP_URL', 'http://localhost/kings-sacco');
define('APP_VERSION', '1.0.0');

// Date and time settings
date_default_timezone_set('Africa/Nairobi');

// Include database
require_once __DIR__ . '/database.php';

require_once __DIR__ . '/../includes/functions.php';


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
    // Check if user is logged in and has a valid ID
    if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']) && $_SESSION['user_id'] > 0) {
        return (int)$_SESSION['user_id'];
    }

    // For debugging, you can log when this happens
    error_log("Warning: getCurrentUserId() called but no valid user ID in session");

    // Return null instead of 0 to help with validation
    return null;
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
    $conn = getConnection();
    $year = date('Y');
    $month = date('m');

    // Get the last member number for current year/month
    $sql = "SELECT member_no FROM members ORDER BY member_no DESC LIMIT 1";

    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        // Last member number exists for this month
        $last_member = $result->fetch_assoc();
        $last_number = $last_member['member_no'];

        // Extract the sequential part (last 4 digits)
        // $sequential = (int)substr($last_number, -4);
        // $new_sequential = str_pad($sequential + 1, 4, '0', STR_PAD_LEFT);
        $new_sequential = $last_number + 1;
    } else {
        // First member of the month
        $new_sequential = '1';
    }

    $conn->close();

    return $new_sequential;
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

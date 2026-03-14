<?php
// debug_users.php
require_once 'config/config.php';

echo "<h2>User Database Debug</h2>";

// Check if users table exists
$table_check = executeQuery("SHOW TABLES LIKE 'users'");
if ($table_check->num_rows == 0) {
    die("Users table does not exist!");
}

// Get all users
$users = executeQuery("SELECT id, username, full_name, role FROM users ORDER BY id");
echo "<h3>Users in Database:</h3>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Username</th><th>Full Name</th><th>Role</th></tr>";

$user_count = 0;
while ($user = $users->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . $user['id'] . "</td>";
    echo "<td>" . $user['username'] . "</td>";
    echo "<td>" . $user['full_name'] . "</td>";
    echo "<td>" . $user['role'] . "</td>";
    echo "</tr>";
    $user_count++;
}
echo "</table>";
echo "<p>Total users: $user_count</p>";

// Check current session
echo "<h3>Session Information:</h3>";
session_start();
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

echo "<h3>Current User ID from getCurrentUserId():</h3>";
$current_id = getCurrentUserId();
echo "User ID: " . ($current_id ?: 'NULL') . "<br>";

if ($current_id) {
    $user_check = executeQuery("SELECT * FROM users WHERE id = ?", "i", [$current_id]);
    if ($user_check && $user_check->num_rows > 0) {
        $user = $user_check->fetch_assoc();
        echo "<h4>Current User Details:</h4>";
        echo "<pre>";
        print_r($user);
        echo "</pre>";
    } else {
        echo "<p style='color:red'>Warning: Current user ID $current_id does not exist in database!</p>";
    }
}

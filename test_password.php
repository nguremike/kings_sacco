<?php
// test_password.php - Test the password functions

require_once 'password_functions.php';

echo "=== PASSWORD FUNCTIONS TEST ===\n\n";

// Test 1: Generate and verify password
echo "Test 1: Generate and verify password\n";
$password = "admin123";
$hash = generatePasswordHash($password);
echo "Password: " . $password . "\n";
echo "Hash: " . $hash . "\n";
echo "Verification: " . (verifyPassword($password, $hash) ? "✓ SUCCESS" : "✗ FAILED") . "\n\n";

// Test 2: Check if rehashing is needed
echo "Test 2: Check if rehashing is needed\n";
$oldHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]); // Older cost
echo "Needs rehash? " . (needsRehash($oldHash) ? "YES" : "NO") . "\n\n";

// Test 3: Password strength validation
echo "Test 3: Password strength validation\n";
$passwords = [
    "weak" => "pass123",
    "medium" => "Password123",
    "strong" => "P@ssw0rd123!",
    "admin123" => "admin123"
];

foreach ($passwords as $name => $pwd) {
    $result = validatePasswordStrength($pwd);
    echo $name . " (" . $pwd . "): " . $result['message'] . "\n";
}
echo "\n";

// Test 4: Login simulation
echo "Test 4: Login simulation\n";
$loginResult = loginUser('admin', 'admin123');
echo "Login attempt: " . ($loginResult['success'] ? "SUCCESS" : "FAILED") . "\n";
echo "Message: " . $loginResult['message'] . "\n";

if ($loginResult['success']) {
    echo "User data: " . print_r($loginResult['user'], true) . "\n";
}

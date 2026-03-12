<?php
// generate_password.php - Run this script once to generate the hashed password

// The password to hash
$password = 'admin123';

// Generate hash using password_hash() (recommended method)
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "Password: " . $password . "\n";
echo "Hash: " . $hash . "\n";
echo "Length: " . strlen($hash) . " characters\n\n";

// Verify the hash works
if (password_verify($password, $hash)) {
    echo "✓ Password verification successful!\n";
} else {
    echo "✗ Password verification failed!\n";
}

// Example of how to insert into database
echo "\n--- SQL Insert Statement ---\n";
echo "INSERT INTO users (username, password, full_name, role) VALUES \n";
echo "('admin', '" . $hash . "', 'System Administrator', 'admin');\n";

// Alternative using PASSWORD_BCRYPT algorithm specifically
$bcrypt_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
echo "\n--- BCRYPT Hash (cost 12) ---\n";
echo $bcrypt_hash . "\n";

// Show different hash formats
echo "\n--- Hash Information ---\n";
$info = password_get_info($hash);
echo "Algorithm: " . $info['algoName'] . "\n";
echo "Algorithm ID: " . $info['algo'] . "\n";
echo "Options: " . print_r($info['options'], true) . "\n";

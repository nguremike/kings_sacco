<?php
// quick_hash.php - Run this file directly in browser to get hash for admin123

if (isset($_GET['password'])) {
    $password = $_GET['password'];
    $hash = password_hash($password, PASSWORD_DEFAULT);

    echo "<h3>Password Hash Generator</h3>";
    echo "<strong>Password:</strong> " . htmlspecialchars($password) . "<br>";
    echo "<strong>Hash:</strong> " . $hash . "<br><br>";
    echo "<strong>SQL:</strong><br>";
    echo "INSERT INTO users (username, password, full_name, role) VALUES <br>";
    echo "('admin', '" . $hash . "', 'System Administrator', 'admin');<br><br>";

    // Verify
    echo "<strong>Verification:</strong> ";
    echo password_verify($password, $hash) ? "✓ Valid" : "✗ Invalid";
} else {
    echo "<form method='get'>";
    echo "<label>Enter Password:</label>";
    echo "<input type='text' name='password' value='admin123'>";
    echo "<button type='submit'>Generate Hash</button>";
    echo "</form>";
}

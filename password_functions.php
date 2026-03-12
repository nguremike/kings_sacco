<?php
// password_functions.php - Password handling functions

/**
 * Verify a password against a hash
 * 
 * @param string $password The plain text password to verify
 * @param string $hash The stored hash to verify against
 * @return bool Returns true if password matches hash, false otherwise
 */
function verifyPassword(string $password, string $hash): bool
{
    return password_verify($password, $hash);
}

/**
 * Enhanced password verification with logging
 * 
 * @param string $password The plain text password
 * @param string $hash The stored hash
 * @param int $userId Optional user ID for logging
 * @return bool Returns true if verification succeeds
 */
function verifyPasswordSecure(string $password, string $hash, int $userId = null): bool
{
    // Verify the password
    $verified = password_verify($password, $hash);

    // Log the verification attempt (optional)
    if ($userId) {
        $status = $verified ? 'SUCCESS' : 'FAILED';
        error_log("Password verification for user {$userId}: {$status}");
    }

    // If using legacy hashes, rehash if needed
    if ($verified && password_needs_rehash($hash, PASSWORD_DEFAULT, ['cost' => 12])) {
        // In a real application, you'd update the user's password hash here
        // $newHash = password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);
        // updateUserHash($userId, $newHash);
        error_log("Password needs rehashing for user {$userId}");
    }

    return $verified;
}

/**
 * Check if password needs rehashing
 * 
 * @param string $hash The current hash
 * @return bool Returns true if password should be rehashed
 */
function needsRehash(string $hash): bool
{
    return password_needs_rehash($hash, PASSWORD_DEFAULT, ['cost' => 12]);
}

/**
 * Generate a secure password hash
 * 
 * @param string $password The plain text password
 * @param int $cost The algorithmic cost (higher = more secure but slower)
 * @return string Returns the hashed password
 */
function generatePasswordHash(string $password, int $cost = 12): string
{
    return password_hash($password, PASSWORD_DEFAULT, ['cost' => $cost]);
}

/**
 * Validate password strength
 * 
 * @param string $password The password to validate
 * @return array Returns array with 'valid' bool and 'message' string
 */
function validatePasswordStrength(string $password): array
{
    $errors = [];

    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long";
    }

    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }

    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }

    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number";
    }

    if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
        $errors[] = "Password must contain at least one special character";
    }

    if (empty($errors)) {
        return ['valid' => true, 'message' => 'Password is strong'];
    } else {
        return ['valid' => false, 'message' => implode(', ', $errors)];
    }
}

/**
 * Example login function using password verification
 * 
 * @param string $username The username
 * @param string $password The password attempt
 * @return array Returns login result with status and user data if successful
 */
function loginUser(string $username, string $password): array
{
    // Get user from database
    $sql = "SELECT id, username, password, full_name, role, failed_attempts, locked_until 
            FROM users WHERE username = ? AND status = 1";

    $result = executeQuery($sql, "s", [$username]);

    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();

        // Check if account is locked
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            return [
                'success' => false,
                'message' => 'Account is locked. Try again later.'
            ];
        }

        // Verify password
        if (verifyPasswordSecure($password, $user['password'], $user['id'])) {
            // Reset failed attempts on successful login
            $resetSql = "UPDATE users SET failed_attempts = 0, last_login = NOW() WHERE id = ?";
            executeQuery($resetSql, "i", [$user['id']]);

            // Remove password from array before returning
            unset($user['password']);

            return [
                'success' => true,
                'message' => 'Login successful',
                'user' => $user
            ];
        } else {
            // Increment failed attempts
            $failed = $user['failed_attempts'] + 1;

            // Lock account after 5 failed attempts
            if ($failed >= 5) {
                $lockTime = date('Y-m-d H:i:s', strtotime('+30 minutes'));
                $lockSql = "UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?";
                executeQuery($lockSql, "isi", [$failed, $lockTime, $user['id']]);
                $message = "Account locked due to too many failed attempts. Try again after 30 minutes.";
            } else {
                $lockSql = "UPDATE users SET failed_attempts = ? WHERE id = ?";
                executeQuery($lockSql, "ii", [$failed, $user['id']]);
                $message = "Invalid password. Attempts remaining: " . (5 - $failed);
            }

            return [
                'success' => false,
                'message' => $message
            ];
        }
    }

    return [
        'success' => false,
        'message' => 'User not found'
    ];
}

/**
 * Update user password with new hash
 * 
 * @param int $userId The user ID
 * @param string $newPassword The new password
 * @return bool Returns true if password updated successfully
 */
function updateUserPassword(int $userId, string $newPassword): bool
{
    // Validate password strength
    $validation = validatePasswordStrength($newPassword);
    if (!$validation['valid']) {
        error_log("Password update failed: " . $validation['message']);
        return false;
    }

    // Generate new hash
    $newHash = generatePasswordHash($newPassword);

    // Update in database
    $sql = "UPDATE users SET password = ?, password_updated_at = NOW() WHERE id = ?";
    $result = executeQuery($sql, "si", [$newHash, $userId]);

    if ($result) {
        // Log password change
        logAudit('PASSWORD_CHANGE', 'users', $userId, null, ['action' => 'password_updated']);
        return true;
    }

    return false;
}

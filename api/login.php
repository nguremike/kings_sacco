<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    $username = $data['username'] ?? '';
    $password = $data['password'] ?? '';

    $sql = "SELECT * FROM users WHERE username = ? AND status = 1";
    $result = executeQuery($sql, "s", [$username]);

    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();

        if (password_verify($password, $user['password'])) {
            // Generate token (simple implementation - use JWT in production)
            $token = bin2hex(random_bytes(32));

            // Store token in database or cache
            $_SESSION['api_token'][$token] = $user['id'];

            $response = [
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'user_id' => $user['id'],
                    'username' => $user['username'],
                    'full_name' => $user['full_name'],
                    'role' => $user['role'],
                    'token' => $token
                ]
            ];
        } else {
            $response = [
                'success' => false,
                'message' => 'Invalid password'
            ];
        }
    } else {
        $response = [
            'success' => false,
            'message' => 'User not found'
        ];
    }

    echo json_encode($response);
}

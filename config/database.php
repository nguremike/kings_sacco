<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'pos');
define('DB_PASS', 'Alaska001');
define('DB_NAME', 'kings_sacco');

// Create connection
function getConnection()
{
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    return $conn;
}

// Execute query and return result
function executeQuery($sql, $types = "", $params = [])
{
    $conn = getConnection();

    // Prepare statement
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die("Error preparing statement: " . $conn->error);
    }

    // Bind parameters if provided
    if (!empty($types) && !empty($params)) {
        // Make sure params are passed by reference
        $bind_params = array();
        $bind_params[] = $types;

        foreach ($params as $key => $value) {
            $bind_params[] = &$params[$key];
        }

        call_user_func_array(array($stmt, 'bind_param'), $bind_params);
    }

    // Execute statement
    if (!$stmt->execute()) {
        die("Error executing query: " . $stmt->error);
    }

    // Get result
    $result = $stmt->get_result();

    // Close statement and connection
    $stmt->close();
    $conn->close();

    return $result;
}

// Insert and return ID
function insertAndGetId($sql, $types = "", $params = [])
{
    $conn = getConnection();
    $stmt = $conn->prepare($sql);

    if (!empty($types) && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $insert_id = $stmt->insert_id;

    $stmt->close();
    $conn->close();

    return $insert_id;
}

<?php
// db_check.php - Run this to check database structure
require_once 'config/config.php';

echo "<h2>Database Structure Check</h2>";

$tables_to_check = [
    'members' => ['id', 'member_no', 'full_name', 'national_id', 'phone', 'email', 'address', 'date_joined', 'membership_status', 'user_id', 'created_at'],
    'deposits' => ['id', 'member_id', 'deposit_date', 'amount', 'balance', 'transaction_type', 'reference_no', 'description', 'created_by'],
    'loans' => ['id', 'loan_no', 'member_id', 'product_id', 'principal_amount', 'interest_amount', 'total_amount', 'balance', 'duration_months', 'interest_rate', 'status'],
    'loan_repayments' => ['id', 'loan_id', 'payment_date', 'amount_paid', 'principal_paid', 'interest_paid', 'penalty_paid', 'balance', 'payment_method', 'reference_no'],
    'shares' => ['id', 'member_id', 'shares_count', 'share_value', 'total_value', 'transaction_type', 'reference_no', 'date_purchased']
];

foreach ($tables_to_check as $table => $expected_columns) {
    echo "<h3>Table: {$table}</h3>";

    $result = executeQuery("SHOW COLUMNS FROM {$table}");

    if (!$result) {
        echo "<p style='color: red'>Table {$table} does not exist!</p>";
        continue;
    }

    $existing_columns = [];
    while ($row = $result->fetch_assoc()) {
        $existing_columns[] = $row['Field'];
    }

    echo "<ul>";
    foreach ($expected_columns as $col) {
        if (in_array($col, $existing_columns)) {
            echo "<li style='color: green'>✓ {$col} exists</li>";
        } else {
            echo "<li style='color: red'>✗ {$col} MISSING</li>";
        }
    }
    echo "</ul>";

    // Show SQL to add missing columns
    $missing = array_diff($expected_columns, $existing_columns);
    if (!empty($missing)) {
        echo "<p><strong>SQL to add missing columns:</strong></p>";
        echo "<pre style='background: #f4f4f4; padding: 10px;'>";
        foreach ($missing as $col) {
            $type = 'VARCHAR(255)'; // Default type
            if (strpos($col, 'amount') !== false || strpos($col, 'balance') !== false || strpos($col, 'value') !== false) {
                $type = 'DECIMAL(10,2) DEFAULT 0';
            } elseif (strpos($col, 'count') !== false || strpos($col, 'shares') !== false) {
                $type = 'INT DEFAULT 0';
            } elseif (strpos($col, 'date') !== false) {
                $type = 'DATE NULL';
            } elseif (strpos($col, 'id') !== false && $col != 'id') {
                $type = 'INT NULL';
            }
            echo "ALTER TABLE {$table} ADD COLUMN {$col} {$type};\n";
        }
        echo "</pre>";
    }
}

<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Authorization');

require_once '../../config/config.php';

// Verify token
$headers = getallheaders();
$token = str_replace('Bearer ', '', $headers['Authorization'] ?? '');

if (!isset($_SESSION['api_token'][$token])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['api_token'][$token];

// Get member ID from user ID
$member_sql = "SELECT id FROM members WHERE user_id = ?";
$member_result = executeQuery($member_sql, "i", [$user_id]);
$member = $member_result->fetch_assoc();
$member_id = $member['id'] ?? 0;

if (!$member_id) {
    echo json_encode(['success' => false, 'message' => 'Member not found']);
    exit();
}

// Get dashboard data
$data = [];

// Member info
$info_sql = "SELECT * FROM members WHERE id = ?";
$info_result = executeQuery($info_sql, "i", [$member_id]);
$data['profile'] = $info_result->fetch_assoc();

// Account summary
$summary_sql = "
    SELECT 
        (SELECT COALESCE(SUM(balance), 0) FROM deposits WHERE member_id = ?) as total_savings,
        (SELECT COALESCE(SUM(shares_count), 0) FROM shares WHERE member_id = ?) as total_shares,
        (SELECT COALESCE(SUM(balance), 0) FROM loans WHERE member_id = ? AND status IN ('disbursed', 'active')) as loan_balance,
        (SELECT COALESCE(SUM(net_dividend), 0) FROM dividends WHERE member_id = ? AND status = 'calculated') as pending_dividend
";
$summary_result = executeQuery($summary_sql, "iiii", [$member_id, $member_id, $member_id, $member_id]);
$data['summary'] = $summary_result->fetch_assoc();

// Recent transactions
$trans_sql = "
    SELECT 'deposit' as type, deposit_date as date, amount, description 
    FROM deposits WHERE member_id = ? 
    UNION ALL 
    SELECT 'repayment' as type, payment_date as date, amount_paid as amount, 'Loan Repayment' as description 
    FROM loan_repayments WHERE loan_id IN (SELECT id FROM loans WHERE member_id = ?)
    ORDER BY date DESC LIMIT 10
";
$trans_result = executeQuery($trans_sql, "ii", [$member_id, $member_id]);
$data['transactions'] = [];
while ($row = $trans_result->fetch_assoc()) {
    $data['transactions'][] = $row;
}

// Active loans
$loans_sql = "
    SELECT l.*, lp.product_name 
    FROM loans l 
    JOIN loan_products lp ON l.product_id = lp.id 
    WHERE l.member_id = ? AND l.status IN ('disbursed', 'active')
    ORDER BY l.created_at DESC
";
$loans_result = executeQuery($loans_sql, "i", [$member_id]);
$data['loans'] = [];
while ($row = $loans_result->fetch_assoc()) {
    $data['loans'][] = $row;
}

echo json_encode([
    'success' => true,
    'data' => $data
]);

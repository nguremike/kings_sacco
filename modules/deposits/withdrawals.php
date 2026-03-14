<?php
// modules/deposits/withdrawals.php
require_once '../../config/config.php';
requireLogin();

$page_title = 'Withdrawal Management';

// Handle withdrawal transaction
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'process_withdrawal') {
        processWithdrawal();
    } elseif ($_POST['action'] == 'approve_withdrawal') {
        approveWithdrawal();
    } elseif ($_POST['action'] == 'reject_withdrawal') {
        rejectWithdrawal();
    }
}

function processWithdrawal()
{
    $conn = getConnection();
    $conn->begin_transaction();

    try {
        $member_id = $_POST['member_id'];
        $amount = floatval($_POST['amount']);
        $withdrawal_date = $_POST['withdrawal_date'] ?? date('Y-m-d');
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $reference_no = $_POST['reference_no'] ?? 'WDL' . time();
        $description = $_POST['description'] ?? 'Savings withdrawal';
        $requires_approval = isset($_POST['requires_approval']) ? true : false;
        $withdrawal_type = $_POST['withdrawal_type'] ?? 'regular';
        $notes = $_POST['notes'] ?? '';
        $created_by = getCurrentUserId();

        // Get member details
        $member_sql = "SELECT * FROM members WHERE id = ?";
        $member_stmt = $conn->prepare($member_sql);
        $member_stmt->bind_param("i", $member_id);
        $member_stmt->execute();
        $member_result = $member_stmt->get_result();
        $member = $member_result->fetch_assoc();

        if (!$member) {
            throw new Exception("Member not found");
        }

        // Get current balance
        $balance_sql = "SELECT COALESCE(SUM(CASE 
                            WHEN transaction_type = 'deposit' THEN amount 
                            WHEN transaction_type = 'withdrawal' THEN -amount 
                            ELSE 0 
                        END), 0) as current_balance 
                        FROM deposits 
                        WHERE member_id = ?";
        $balance_stmt = $conn->prepare($balance_sql);
        $balance_stmt->bind_param("i", $member_id);
        $balance_stmt->execute();
        $balance_result = $balance_stmt->get_result();
        $current_balance = $balance_result->fetch_assoc()['current_balance'];

        // Check if sufficient balance
        if ($current_balance < $amount) {
            throw new Exception("Insufficient balance. Current balance: " . formatCurrency($current_balance));
        }

        // Check minimum balance requirements if applicable
        $min_balance_after = 0; // Configure as needed
        if (($current_balance - $amount) < $min_balance_after) {
            throw new Exception("Withdrawal would reduce balance below minimum required (KES " . number_format($min_balance_after, 2) . ")");
        }

        // Calculate any withdrawal fees
        $fee_amount = calculateWithdrawalFee($amount, $withdrawal_type, $member);
        $net_amount = $amount - $fee_amount;

        if ($requires_approval || $amount > 50000) { // Auto-require approval for large withdrawals
            // Insert into withdrawal requests table
            $request_sql = "INSERT INTO withdrawal_requests 
                           (member_id, request_date, amount, fee_amount, net_amount, 
                            payment_method, reference_no, description, withdrawal_type, 
                            status, notes, created_by, created_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, NOW())";
            $request_stmt = $conn->prepare($request_sql);
            $request_stmt->bind_param(
                "isddssssssi",
                $member_id,
                $withdrawal_date,
                $amount,
                $fee_amount,
                $net_amount,
                $payment_method,
                $reference_no,
                $description,
                $withdrawal_type,
                $notes,
                $created_by
            );
            $request_stmt->execute();
            $request_id = $conn->insert_id;

            // Send notification to approvers
            notifyApprovers($member_id, $amount, $request_id);

            $conn->commit();
            $_SESSION['success'] = "Withdrawal request submitted for approval. Reference: " . $reference_no;
        } else {
            // Process withdrawal immediately
            $new_balance = $current_balance - $amount;

            // Record withdrawal
            $withdrawal_sql = "INSERT INTO deposits 
                              (member_id, deposit_date, amount, balance, transaction_type, 
                               reference_no, description, created_by, created_at)
                              VALUES (?, ?, ?, ?, 'withdrawal', ?, ?, ?, NOW())";
            $withdrawal_stmt = $conn->prepare($withdrawal_sql);
            $withdrawal_stmt->bind_param(
                "isddssi",
                $member_id,
                $withdrawal_date,
                $amount,
                $new_balance,
                $reference_no,
                $description,
                $created_by
            );
            $withdrawal_stmt->execute();
            $withdrawal_id = $conn->insert_id;

            // Record fee if applicable
            if ($fee_amount > 0) {
                $fee_sql = "INSERT INTO admin_charges 
                           (member_id, charge_type, amount, charge_date, description, 
                            reference_no, status, created_by, created_at)
                           VALUES (?, 'withdrawal_fee', ?, ?, 'Withdrawal fee', ?, 'paid', ?, NOW())";
                $fee_stmt = $conn->prepare($fee_sql);
                $fee_desc = "Withdrawal fee for transaction " . $reference_no;
                $fee_stmt->bind_param("idssi", $member_id, $fee_amount, $withdrawal_date, $fee_desc, $created_by);
                $fee_stmt->execute();
            }

            // Create transaction record
            $trans_no = 'TXN' . time() . rand(100, 999);
            $trans_sql = "INSERT INTO transactions 
                         (transaction_no, transaction_date, description, debit_account, 
                          credit_account, amount, reference_type, reference_id, created_by)
                         VALUES (?, ?, ?, 'MEMBER_DEPOSITS', 'CASH', ?, 'withdrawal', ?, ?)";
            $trans_stmt = $conn->prepare($trans_sql);
            $trans_desc = "Savings withdrawal - {$member['full_name']}";
            $trans_stmt->bind_param("sssiii", $trans_no, $withdrawal_date, $trans_desc, $amount, $withdrawal_id, $created_by);
            $trans_stmt->execute();

            // Send SMS notification
            $message = "Dear {$member['full_name']}, KES " . number_format($amount) . " has been withdrawn from your account. New balance: KES " . number_format($new_balance);
            sendNotification($member_id, 'Withdrawal Confirmation', $message, 'sms');

            $conn->commit();
            $_SESSION['success'] = "Withdrawal of " . formatCurrency($amount) . " processed successfully. New balance: " . formatCurrency($new_balance);
        }
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Withdrawal failed: ' . $e->getMessage();
        error_log("Withdrawal error: " . $e->getMessage());
    }

    $conn->close();
    header('Location: withdrawals.php');
    exit();
}

function approveWithdrawal()
{
    $conn = getConnection();
    $conn->begin_transaction();

    try {
        $request_id = $_POST['request_id'];
        $approval_notes = $_POST['approval_notes'] ?? '';
        $approved_by = getCurrentUserId();

        // Get withdrawal request
        $request_sql = "SELECT * FROM withdrawal_requests WHERE id = ? AND status = 'pending'";
        $request_stmt = $conn->prepare($request_sql);
        $request_stmt->bind_param("i", $request_id);
        $request_stmt->execute();
        $request_result = $request_stmt->get_result();

        if ($request_result->num_rows == 0) {
            throw new Exception("Withdrawal request not found or already processed");
        }

        $request = $request_result->fetch_assoc();

        // Get current balance
        $balance_sql = "SELECT COALESCE(SUM(CASE 
                            WHEN transaction_type = 'deposit' THEN amount 
                            WHEN transaction_type = 'withdrawal' THEN -amount 
                            ELSE 0 
                        END), 0) as current_balance 
                        FROM deposits 
                        WHERE member_id = ?";
        $balance_stmt = $conn->prepare($balance_sql);
        $balance_stmt->bind_param("i", $request['member_id']);
        $balance_stmt->execute();
        $balance_result = $balance_stmt->get_result();
        $current_balance = $balance_result->fetch_assoc()['current_balance'];

        // Double-check balance
        if ($current_balance < $request['amount']) {
            throw new Exception("Insufficient balance for approved withdrawal");
        }

        $new_balance = $current_balance - $request['amount'];

        // Process withdrawal
        $withdrawal_sql = "INSERT INTO deposits 
                          (member_id, deposit_date, amount, balance, transaction_type, 
                           reference_no, description, created_by, created_at)
                          VALUES (?, ?, ?, ?, 'withdrawal', ?, ?, ?, NOW())";
        $withdrawal_stmt = $conn->prepare($withdrawal_sql);
        $description = "Approved withdrawal - " . $request['description'];
        $withdrawal_stmt->bind_param(
            "isddssi",
            $request['member_id'],
            $request['request_date'],
            $request['amount'],
            $new_balance,
            $request['reference_no'],
            $description,
            $approved_by
        );
        $withdrawal_stmt->execute();
        $withdrawal_id = $conn->insert_id;

        // Record fee if applicable
        if ($request['fee_amount'] > 0) {
            $fee_sql = "INSERT INTO admin_charges 
                       (member_id, charge_type, amount, charge_date, description, 
                        reference_no, status, created_by, created_at)
                       VALUES (?, 'withdrawal_fee', ?, ?, 'Withdrawal fee', ?, 'paid', ?, NOW())";
            $fee_stmt = $conn->prepare($fee_sql);
            $fee_desc = "Withdrawal fee for approved request " . $request['reference_no'];
            $fee_stmt->bind_param("idssi", $request['member_id'], $request['fee_amount'], $request['request_date'], $fee_desc, $approved_by);
            $fee_stmt->execute();
        }

        // Update request status
        $update_sql = "UPDATE withdrawal_requests 
                       SET status = 'approved', approved_by = ?, approved_at = NOW(), approval_notes = ?
                       WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("isi", $approved_by, $approval_notes, $request_id);
        $update_stmt->execute();

        // Get member details for notification
        $member_sql = "SELECT full_name, phone FROM members WHERE id = ?";
        $member_stmt = $conn->prepare($member_sql);
        $member_stmt->bind_param("i", $request['member_id']);
        $member_stmt->execute();
        $member = $member_stmt->get_result()->fetch_assoc();

        // Send SMS notification
        $message = "Dear {$member['full_name']}, your withdrawal request of KES " . number_format($request['amount']) . " has been APPROVED.";
        sendNotification($request['member_id'], 'Withdrawal Approved', $message, 'sms');

        $conn->commit();
        $_SESSION['success'] = "Withdrawal request approved and processed successfully.";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Approval failed: ' . $e->getMessage();
    }

    $conn->close();
    header('Location: withdrawals.php?tab=pending');
    exit();
}

function rejectWithdrawal()
{
    $conn = getConnection();
    $conn->begin_transaction();

    try {
        $request_id = $_POST['request_id'];
        $rejection_reason = $_POST['rejection_reason'];
        $rejected_by = getCurrentUserId();

        // Update request status
        $update_sql = "UPDATE withdrawal_requests 
                       SET status = 'rejected', rejected_by = ?, rejected_at = NOW(), rejection_reason = ?
                       WHERE id = ? AND status = 'pending'";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("isi", $rejected_by, $rejection_reason, $request_id);
        $update_stmt->execute();

        if ($update_stmt->affected_rows == 0) {
            throw new Exception("Withdrawal request not found or already processed");
        }

        // Get request details for notification
        $request_sql = "SELECT * FROM withdrawal_requests WHERE id = ?";
        $request_stmt = $conn->prepare($request_sql);
        $request_stmt->bind_param("i", $request_id);
        $request_stmt->execute();
        $request = $request_stmt->get_result()->fetch_assoc();

        // Get member details
        $member_sql = "SELECT full_name, phone FROM members WHERE id = ?";
        $member_stmt = $conn->prepare($member_sql);
        $member_stmt->bind_param("i", $request['member_id']);
        $member_stmt->execute();
        $member = $member_stmt->get_result()->fetch_assoc();

        // Send SMS notification
        $message = "Dear {$member['full_name']}, your withdrawal request of KES " . number_format($request['amount']) . " has been REJECTED. Reason: {$rejection_reason}";
        sendNotification($request['member_id'], 'Withdrawal Rejected', $message, 'sms');

        $conn->commit();
        $_SESSION['success'] = "Withdrawal request rejected.";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Rejection failed: ' . $e->getMessage();
    }

    $conn->close();
    header('Location: withdrawals.php?tab=pending');
    exit();
}

function calculateWithdrawalFee($amount, $withdrawal_type, $member)
{
    // Configure withdrawal fees based on SACCO policy
    $fee = 0;

    switch ($withdrawal_type) {
        case 'regular':
            // Regular withdrawals: 0.5% of amount, min KES 50, max KES 500
            $fee = max(50, min(500, $amount * 0.005));
            break;
        case 'emergency':
            // Emergency withdrawals: 1% of amount, min KES 100, max KES 1000
            $fee = max(100, min(1000, $amount * 0.01));
            break;
        case 'full':
            // Full account closure: KES 500 flat fee
            $fee = 500;
            break;
        case 'partial':
            // Partial withdrawal: 0.25% of amount, min KES 25, max KES 250
            $fee = max(25, min(250, $amount * 0.0025));
            break;
        default:
            $fee = 0;
    }

    // Check for fee exemptions (e.g., senior citizens, special members)
    // This would be based on member data

    return $fee;
}

function notifyApprovers($member_id, $amount, $request_id)
{
    // Get all users with approval rights
    $approvers_sql = "SELECT id, phone, email FROM users WHERE role IN ('admin', 'manager') AND status = 1";
    $approvers = executeQuery($approvers_sql);

    while ($approver = $approvers->fetch_assoc()) {
        // Send notification (SMS, email, or in-app)
        $message = "New withdrawal request for KES " . number_format($amount) . " requires your approval.";
        // sendNotification($approver['id'], 'Withdrawal Approval', $message, 'sms');
    }
}

// Get filter parameters
$tab = $_GET['tab'] ?? 'all';
$member_id = $_GET['member_id'] ?? '';
$status = $_GET['status'] ?? 'all';
$date_from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['to'] ?? date('Y-m-d');

// Build query based on filters
$where_conditions = ["1=1"];
$params = [];
$types = "";

if (!empty($member_id)) {
    $where_conditions[] = "wr.member_id = ?";
    $params[] = $member_id;
    $types .= "i";
}

if ($status != 'all') {
    $where_conditions[] = "wr.status = ?";
    $params[] = $status;
    $types .= "s";
}

if (!empty($date_from)) {
    $where_conditions[] = "wr.request_date >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $where_conditions[] = "wr.request_date <= ?";
    $params[] = $date_to;
    $types .= "s";
}

$where_clause = implode(" AND ", $where_conditions);

// Check if withdrawal_requests table exists, create if not
$table_check = executeQuery("SHOW TABLES LIKE 'withdrawal_requests'");
if ($table_check->num_rows == 0) {
    $create_sql = "CREATE TABLE IF NOT EXISTS withdrawal_requests (
        id INT PRIMARY KEY AUTO_INCREMENT,
        member_id INT NOT NULL,
        request_date DATE NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        fee_amount DECIMAL(10,2) DEFAULT 0,
        net_amount DECIMAL(10,2) NOT NULL,
        payment_method VARCHAR(50) NOT NULL,
        reference_no VARCHAR(50) UNIQUE,
        description TEXT,
        withdrawal_type VARCHAR(50) DEFAULT 'regular',
        status ENUM('pending', 'approved', 'rejected', 'cancelled') DEFAULT 'pending',
        approved_by INT NULL,
        approved_at DATETIME NULL,
        approval_notes TEXT,
        rejected_by INT NULL,
        rejected_at DATETIME NULL,
        rejection_reason TEXT,
        notes TEXT,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (member_id) REFERENCES members(id),
        FOREIGN KEY (approved_by) REFERENCES users(id),
        FOREIGN KEY (rejected_by) REFERENCES users(id),
        FOREIGN KEY (created_by) REFERENCES users(id)
    )";
    executeQuery($create_sql);
}

// Get processed withdrawals (actual transactions)
$processed_sql = "SELECT d.*, 
                  m.member_no, m.full_name as member_name,
                  u.full_name as created_by_name,
                  'processed' as request_status,
                  d.reference_no as original_ref
                  FROM deposits d
                  JOIN members m ON d.member_id = m.id
                  LEFT JOIN users u ON d.created_by = u.id
                  WHERE d.transaction_type = 'withdrawal'
                  AND d.deposit_date BETWEEN ? AND ?
                  ORDER BY d.deposit_date DESC";
$processed_stmt = executeQuery($processed_sql, "ss", [$date_from, $date_to]);

// Get pending withdrawal requests
$pending_sql = "SELECT wr.*, 
                m.member_no, m.full_name as member_name, m.phone,
                c.full_name as created_by_name,
                a.full_name as approved_by_name,
                r.full_name as rejected_by_name
                FROM withdrawal_requests wr
                JOIN members m ON wr.member_id = m.id
                LEFT JOIN users c ON wr.created_by = c.id
                LEFT JOIN users a ON wr.approved_by = a.id
                LEFT JOIN users r ON wr.rejected_by = r.id
                WHERE $where_clause
                ORDER BY wr.created_at DESC";
$pending_requests = !empty($params) ? executeQuery($pending_sql, $types, $params) : executeQuery($pending_sql);

// Get summary statistics
$stats_sql = "SELECT 
              (SELECT COUNT(*) FROM deposits WHERE transaction_type = 'withdrawal' AND deposit_date BETWEEN ? AND ?) as total_processed,
              (SELECT COALESCE(SUM(amount), 0) FROM deposits WHERE transaction_type = 'withdrawal' AND deposit_date BETWEEN ? AND ?) as total_amount,
              (SELECT COUNT(*) FROM withdrawal_requests WHERE status = 'pending') as pending_count,
              (SELECT COALESCE(SUM(amount), 0) FROM withdrawal_requests WHERE status = 'pending') as pending_amount,
              (SELECT COUNT(*) FROM withdrawal_requests WHERE status = 'approved') as approved_count,
              (SELECT COUNT(*) FROM withdrawal_requests WHERE status = 'rejected') as rejected_count
              FROM dual";
$stats_result = executeQuery($stats_sql, "ssss", [$date_from, $date_to, $date_from, $date_to]);
$stats = $stats_result->fetch_assoc();

// Get members for dropdown
$members = executeQuery("SELECT id, member_no, full_name FROM members WHERE membership_status = 'active' ORDER BY member_no");

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Withdrawal Management</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Deposits</a></li>
                <li class="breadcrumb-item active">Withdrawals</li>
            </ul>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#withdrawalModal">
                <i class="fas fa-hand-holding-usd me-2"></i>New Withdrawal
            </button>
            <button class="btn btn-success" onclick="exportWithdrawals()">
                <i class="fas fa-file-excel me-2"></i>Export
            </button>
        </div>
    </div>
</div>

<!-- Alert Messages -->
<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?php echo nl2br($_SESSION['success']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>
        <?php echo $_SESSION['error']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-2">
        <div class="stats-card primary">
            <div class="stats-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo $stats['total_processed'] ?? 0; ?></h3>
                <p>Processed</p>
            </div>
        </div>
    </div>

    <div class="col-md-2">
        <div class="stats-card success">
            <div class="stats-icon">
                <i class="fas fa-money-bill-wave"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($stats['total_amount'] ?? 0); ?></h3>
                <p>Total Amount</p>
            </div>
        </div>
    </div>

    <div class="col-md-2">
        <div class="stats-card warning">
            <div class="stats-icon">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo $stats['pending_count'] ?? 0; ?></h3>
                <p>Pending</p>
                <small><?php echo formatCurrency($stats['pending_amount'] ?? 0); ?></small>
            </div>
        </div>
    </div>

    <div class="col-md-2">
        <div class="stats-card info">
            <div class="stats-icon">
                <i class="fas fa-thumbs-up"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo $stats['approved_count'] ?? 0; ?></h3>
                <p>Approved</p>
            </div>
        </div>
    </div>

    <div class="col-md-2">
        <div class="stats-card danger">
            <div class="stats-icon">
                <i class="fas fa-times-circle"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo $stats['rejected_count'] ?? 0; ?></h3>
                <p>Rejected</p>
            </div>
        </div>
    </div>

    <div class="col-md-2">
        <div class="stats-card secondary">
            <div class="stats-icon">
                <i class="fas fa-users"></i>
            </div>
            <div class="stats-content">
                <?php
                $member_count = executeQuery("SELECT COUNT(DISTINCT member_id) as count FROM deposits WHERE transaction_type = 'withdrawal'")->fetch_assoc()['count'];
                ?>
                <h3><?php echo $member_count; ?></h3>
                <p>Members</p>
            </div>
        </div>
    </div>
</div>

<!-- Filter Card -->
<div class="card mb-4">
    <div class="card-header bg-info text-white">
        <h5 class="card-title mb-0"><i class="fas fa-filter"></i> Filter Withdrawals</h5>
    </div>
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-2">
                <label for="member_id" class="form-label">Member</label>
                <select class="form-control" id="member_id" name="member_id">
                    <option value="">All Members</option>
                    <?php while ($m = $members->fetch_assoc()): ?>
                        <option value="<?php echo $m['id']; ?>" <?php echo $member_id == $m['id'] ? 'selected' : ''; ?>>
                            <?php echo $m['member_no']; ?> - <?php echo $m['full_name']; ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label for="status" class="form-label">Status</label>
                <select class="form-control" id="status" name="status">
                    <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>All Status</option>
                    <option value="pending" <?php echo $status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="approved" <?php echo $status == 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="rejected" <?php echo $status == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                </select>
            </div>

            <div class="col-md-2">
                <label for="from" class="form-label">From Date</label>
                <input type="date" class="form-control" id="from" name="from" value="<?php echo $date_from; ?>">
            </div>

            <div class="col-md-2">
                <label for="to" class="form-label">To Date</label>
                <input type="date" class="form-control" id="to" name="to" value="<?php echo $date_to; ?>">
            </div>

            <div class="col-md-2">
                <label for="tab" class="form-label">View</label>
                <select class="form-control" id="tab" name="tab" onchange="this.form.submit()">
                    <option value="all" <?php echo $tab == 'all' ? 'selected' : ''; ?>>All Transactions</option>
                    <option value="processed" <?php echo $tab == 'processed' ? 'selected' : ''; ?>>Processed</option>
                    <option value="pending" <?php echo $tab == 'pending' ? 'selected' : ''; ?>>Pending Requests</option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary d-block w-100">
                    <i class="fas fa-search"></i> Filter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Tab Navigation -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?php echo $tab == 'all' || $tab == 'processed' ? 'active' : ''; ?>" href="?tab=all">All Transactions</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab == 'pending' ? 'active' : ''; ?>" href="?tab=pending">
            Pending Requests
            <?php if ($stats['pending_count'] > 0): ?>
                <span class="badge bg-danger ms-1"><?php echo $stats['pending_count']; ?></span>
            <?php endif; ?>
        </a>
    </li>
</ul>

<!-- Processed Withdrawals Table -->
<?php if ($tab == 'all' || $tab == 'processed'): ?>
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title">Processed Withdrawals</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-striped datatable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Member</th>
                            <th>Reference</th>
                            <th>Amount</th>
                            <th>Balance After</th>
                            <th>Description</th>
                            <th>Processed By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $processed_total = 0;
                        while ($row = $processed_stmt->fetch_assoc()):
                            $processed_total += $row['amount'];
                        ?>
                            <tr>
                                <td><?php echo formatDate($row['deposit_date']); ?></td>
                                <td>
                                    <a href="../members/view.php?id=<?php echo $row['member_id']; ?>">
                                        <strong><?php echo $row['member_name']; ?></strong>
                                        <br>
                                        <small><?php echo $row['member_no']; ?></small>
                                    </a>
                                </td>
                                <td><?php echo $row['reference_no']; ?></td>
                                <td class="text-danger fw-bold">- <?php echo formatCurrency($row['amount']); ?></td>
                                <td><?php echo formatCurrency($row['balance']); ?></td>
                                <td><?php echo $row['description']; ?></td>
                                <td><?php echo $row['created_by_name'] ?? 'System'; ?></td>
                                <td>
                                    <a href="receipt.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-info" target="_blank">
                                        <i class="fas fa-receipt"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-info">
                            <th colspan="3" class="text-end">Total:</th>
                            <th class="text-danger"><?php echo formatCurrency($processed_total); ?></th>
                            <th colspan="4"></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Pending Withdrawal Requests -->
<?php if ($tab == 'pending'): ?>
    <div class="card mb-4">
        <div class="card-header bg-warning">
            <h5 class="card-title mb-0">Pending Withdrawal Requests</h5>
        </div>
        <div class="card-body">
            <?php if ($pending_requests->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>Request Date</th>
                                <th>Member</th>
                                <th>Amount</th>
                                <th>Fee</th>
                                <th>Net Amount</th>
                                <th>Type</th>
                                <th>Payment Method</th>
                                <th>Description</th>
                                <th>Requested By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($req = $pending_requests->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo formatDate($req['request_date']); ?></td>
                                    <td>
                                        <a href="../members/view.php?id=<?php echo $req['member_id']; ?>">
                                            <strong><?php echo $req['member_name']; ?></strong>
                                            <br>
                                            <small><?php echo $req['member_no']; ?></small>
                                        </a>
                                    </td>
                                    <td class="fw-bold"><?php echo formatCurrency($req['amount']); ?></td>
                                    <td class="text-warning"><?php echo formatCurrency($req['fee_amount']); ?></td>
                                    <td class="text-success"><?php echo formatCurrency($req['net_amount']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php
                                                                echo $req['withdrawal_type'] == 'regular' ? 'info' : ($req['withdrawal_type'] == 'emergency' ? 'danger' : ($req['withdrawal_type'] == 'full' ? 'secondary' : 'primary'));
                                                                ?>">
                                            <?php echo ucfirst($req['withdrawal_type']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo ucfirst($req['payment_method']); ?></td>
                                    <td><?php echo $req['description']; ?></td>
                                    <td><?php echo $req['created_by_name'] ?? 'Member'; ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-success" onclick="approveRequest(<?php echo $req['id']; ?>, <?php echo $req['amount']; ?>)">
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                        <button type="button" class="btn btn-sm btn-danger" onclick="rejectRequest(<?php echo $req['id']; ?>)">
                                            <i class="fas fa-times"></i> Reject
                                        </button>
                                        <a href="#" class="btn btn-sm btn-info" onclick="viewRequest(<?php echo $req['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-4">
                    <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                    <h5>No Pending Withdrawal Requests</h5>
                    <p class="text-muted">All withdrawal requests have been processed.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- New Withdrawal Modal -->
<div class="modal fade" id="withdrawalModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-hand-holding-usd"></i> Process Withdrawal</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="" class="needs-validation" novalidate>
                <div class="modal-body">
                    <input type="hidden" name="action" value="process_withdrawal">

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="member_id" class="form-label">Select Member <span class="text-danger">*</span></label>
                            <select class="form-control" id="member_id" name="member_id" required onchange="loadMemberBalance()">
                                <option value="">-- Select Member --</option>
                                <?php
                                $all_members = executeQuery("SELECT m.id, m.member_no, m.full_name,
                                                            (SELECT COALESCE(SUM(CASE 
                                                                 WHEN transaction_type = 'deposit' THEN amount 
                                                                 WHEN transaction_type = 'withdrawal' THEN -amount 
                                                                 ELSE 0 
                                                             END), 0) 
                                                             FROM deposits WHERE member_id = m.id) as balance
                                                            FROM members m
                                                            WHERE m.membership_status = 'active'
                                                            ORDER BY m.member_no");
                                while ($m = $all_members->fetch_assoc()):
                                ?>
                                    <option value="<?php echo $m['id']; ?>" data-balance="<?php echo $m['balance']; ?>">
                                        <?php echo $m['member_no']; ?> - <?php echo $m['full_name']; ?> (Bal: <?php echo formatCurrency($m['balance']); ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="withdrawal_type" class="form-label">Withdrawal Type</label>
                            <select class="form-control" id="withdrawal_type" name="withdrawal_type" required onchange="calculateFee()">
                                <option value="regular">Regular Withdrawal (0.5% fee)</option>
                                <option value="partial">Partial Withdrawal (0.25% fee)</option>
                                <option value="emergency">Emergency Withdrawal (1% fee)</option>
                                <option value="full">Full Account Closure (KES 500)</option>
                            </select>
                        </div>
                    </div>

                    <div class="alert alert-info" id="balanceInfo" style="display: none;">
                        <strong>Current Balance:</strong> <span id="currentBalance"></span><br>
                        <strong>Balance After Withdrawal:</strong> <span id="balanceAfter"></span>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="amount" class="form-label">Withdrawal Amount <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="amount" name="amount"
                                min="100" step="100" required onchange="calculateFee()">
                            <div class="invalid-feedback">Please enter withdrawal amount</div>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="fee_amount" class="form-label">Fee Amount</label>
                            <input type="text" class="form-control" id="fee_amount" readonly>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="net_amount" class="form-label">Net Amount</label>
                            <input type="text" class="form-control" id="net_amount" readonly>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="withdrawal_date" class="form-label">Withdrawal Date</label>
                            <input type="date" class="form-control" id="withdrawal_date" name="withdrawal_date"
                                value="<?php echo date('Y-m-d'); ?>" required>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="payment_method" class="form-label">Payment Method</label>
                            <select class="form-control" id="payment_method" name="payment_method" required>
                                <option value="cash">Cash</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="mpesa">M-Pesa</option>
                                <option value="cheque">Cheque</option>
                            </select>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="reference_no" class="form-label">Reference Number</label>
                            <input type="text" class="form-control" id="reference_no" name="reference_no"
                                value="WDL<?php echo time(); ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <input type="text" class="form-control" id="description" name="description"
                            value="Savings withdrawal" required>
                    </div>

                    <div class="mb-3">
                        <label for="notes" class="form-label">Additional Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="requires_approval" name="requires_approval" value="1">
                        <label class="form-check-label" for="requires_approval">
                            Requires Approval (for large withdrawals)
                        </label>
                        <small class="text-muted d-block">Withdrawals above KES 50,000 automatically require approval</small>
                    </div>

                    <div class="alert alert-warning" id="approvalWarning" style="display: none;">
                        <i class="fas fa-exclamation-triangle"></i>
                        This withdrawal exceeds KES 50,000 and will require approval.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Process Withdrawal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Approve Modal -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">Approve Withdrawal Request</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="approve_withdrawal">
                    <input type="hidden" name="request_id" id="approve_request_id">

                    <p>Are you sure you want to approve this withdrawal request?</p>

                    <div class="card bg-light mb-3">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-6">
                                    <p class="mb-1">Amount:</p>
                                    <p class="mb-1">Fee:</p>
                                    <p class="mb-1">Net Amount:</p>
                                </div>
                                <div class="col-6 text-end">
                                    <p class="mb-1"><strong id="approve_amount"></strong></p>
                                    <p class="mb-1"><strong id="approve_fee"></strong></p>
                                    <p class="mb-1"><strong id="approve_net"></strong></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="approval_notes" class="form-label">Approval Notes</label>
                        <textarea class="form-control" id="approval_notes" name="approval_notes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Approve & Process</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Reject Withdrawal Request</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="reject_withdrawal">
                    <input type="hidden" name="request_id" id="reject_request_id">

                    <div class="mb-3">
                        <label for="rejection_reason" class="form-label">Reason for Rejection <span class="text-danger">*</span></label>
                        <select class="form-control mb-2" id="rejection_reason_select" onchange="updateRejectReason()">
                            <option value="">-- Select Reason --</option>
                            <option value="Insufficient balance">Insufficient balance</option>
                            <option value="Below minimum balance requirement">Below minimum balance requirement</option>
                            <option value="Suspicious activity">Suspicious activity</option>
                            <option value="Incomplete documentation">Incomplete documentation</option>
                            <option value="Member not verified">Member not verified</option>
                            <option value="Other">Other</option>
                        </select>
                        <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function loadMemberBalance() {
        var select = document.getElementById('member_id');
        var selected = select.options[select.selectedIndex];
        var balance = selected.dataset.balance || 0;

        if (select.value) {
            document.getElementById('balanceInfo').style.display = 'block';
            document.getElementById('currentBalance').innerHTML = formatCurrency(balance);
            calculateFee();
        } else {
            document.getElementById('balanceInfo').style.display = 'none';
        }
    }

    function calculateFee() {
        var amount = parseFloat(document.getElementById('amount').value) || 0;
        var type = document.getElementById('withdrawal_type').value;
        var balance = parseFloat(document.querySelector('#member_id option:checked')?.dataset.balance || 0);

        var fee = 0;

        switch (type) {
            case 'regular':
                fee = Math.max(50, Math.min(500, amount * 0.005));
                break;
            case 'partial':
                fee = Math.max(25, Math.min(250, amount * 0.0025));
                break;
            case 'emergency':
                fee = Math.max(100, Math.min(1000, amount * 0.01));
                break;
            case 'full':
                fee = 500;
                break;
        }

        var netAmount = amount - fee;
        var newBalance = balance - amount;

        document.getElementById('fee_amount').value = formatCurrency(fee);
        document.getElementById('net_amount').value = formatCurrency(netAmount);
        document.getElementById('balanceAfter').innerHTML = formatCurrency(newBalance);

        // Check if approval needed
        if (amount > 50000) {
            document.getElementById('approvalWarning').style.display = 'block';
            document.getElementById('requires_approval').checked = true;
        } else {
            document.getElementById('approvalWarning').style.display = 'none';
        }
    }

    function approveRequest(id, amount) {
        document.getElementById('approve_request_id').value = id;
        document.getElementById('approve_amount').innerHTML = formatCurrency(amount);

        // Calculate fee and net (would need to get from request)
        var modal = new bootstrap.Modal(document.getElementById('approveModal'));
        modal.show();
    }

    function rejectRequest(id) {
        document.getElementById('reject_request_id').value = id;
        var modal = new bootstrap.Modal(document.getElementById('rejectModal'));
        modal.show();
    }

    function updateRejectReason() {
        var select = document.getElementById('rejection_reason_select');
        var textarea = document.getElementById('rejection_reason');

        if (select.value && select.value != 'Other') {
            textarea.value = select.value;
        } else {
            textarea.value = '';
        }
    }

    function formatCurrency(amount) {
        return 'KES ' + parseFloat(amount).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function exportWithdrawals() {
        var member = document.getElementById('member_id').value;
        var status = document.getElementById('status').value;
        var from = document.getElementById('from').value;
        var to = document.getElementById('to').value;
        var tab = document.getElementById('tab').value;

        window.location.href = 'export-withdrawals.php?member=' + member + '&status=' + status + '&from=' + from + '&to=' + to + '&tab=' + tab;
    }

    // Form validation
    (function() {
        'use strict';
        var forms = document.querySelectorAll('.needs-validation');
        Array.prototype.slice.call(forms).forEach(function(form) {
            form.addEventListener('submit', function(event) {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                form.classList.add('was-validated');
            }, false);
        });
    })();
</script>

<style>
    .stats-card.secondary {
        background: linear-gradient(135deg, #6c757d, #5a6268);
    }

    .stats-card.secondary .stats-icon {
        background: rgba(255, 255, 255, 0.2);
        color: white;
    }

    .stats-card.secondary .stats-content h3,
    .stats-card.secondary .stats-content p {
        color: white;
    }

    .table td {
        vertical-align: middle;
    }

    .nav-tabs .nav-link {
        font-weight: 500;
    }

    .nav-tabs .nav-link.active {
        border-bottom: 3px solid #007bff;
    }

    .badge.bg-warning {
        color: #333;
    }

    @media (max-width: 768px) {
        .stats-card {
            margin-bottom: 10px;
        }

        .btn-group {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
    }
</style>

<?php include '../../includes/footer.php'; ?>
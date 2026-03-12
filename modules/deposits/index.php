<?php
require_once '../../config/config.php';
requireLogin();

$page_title = 'Deposits Management';

// Handle deposit transaction
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'add_deposit') {
        $member_id = $_POST['member_id'];
        $amount = $_POST['amount'];
        $deposit_date = $_POST['deposit_date'] ?? date('Y-m-d');
        $transaction_type = $_POST['transaction_type'] ?? 'deposit';
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $reference_no = $_POST['reference_no'] ?? 'DEP' . time();
        $description = $_POST['description'] ?? '';
        $created_by = getCurrentUserId();

        $conn = getConnection();
        $conn->begin_transaction();

        try {
            // Get current balance
            $balance_result = $conn->query("SELECT COALESCE(SUM(
                CASE WHEN transaction_type = 'deposit' THEN amount 
                     WHEN transaction_type = 'withdrawal' THEN -amount 
                     ELSE 0 END
            ), 0) as current_balance FROM deposits WHERE member_id = $member_id");
            $current_balance = $balance_result->fetch_assoc()['current_balance'];

            // Calculate new balance
            if ($transaction_type == 'deposit') {
                $new_balance = $current_balance + $amount;
            } else {
                // Check if sufficient balance for withdrawal
                if ($current_balance < $amount) {
                    throw new Exception('Insufficient balance for withdrawal');
                }
                $new_balance = $current_balance - $amount;
            }

            // Insert deposit record
            $sql = "INSERT INTO deposits (member_id, deposit_date, amount, balance, transaction_type, reference_no, description, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("isddsssi", $member_id, $deposit_date, $amount, $new_balance, $transaction_type, $reference_no, $description, $created_by);
            $stmt->execute();
            $deposit_id = $stmt->insert_id;

            // Create transaction record
            $trans_no = 'TXN' . time() . rand(100, 999);
            $member_result = $conn->query("SELECT full_name FROM members WHERE id = $member_id");
            $member_data = $member_result->fetch_assoc();

            if ($transaction_type == 'deposit') {
                $desc = "Deposit - {$member_data['full_name']}";
                $debit_account = 'CASH';
                $credit_account = 'MEMBER_DEPOSITS';
            } else {
                $desc = "Withdrawal - {$member_data['full_name']}";
                $debit_account = 'MEMBER_DEPOSITS';
                $credit_account = 'CASH';
            }

            $trans_sql = "INSERT INTO transactions (transaction_no, transaction_date, description, debit_account, credit_account, amount, reference_type, reference_id, created_by)
                          VALUES (?, ?, ?, ?, ?, ?, 'deposit', ?, ?)";
            $stmt2 = $conn->prepare($trans_sql);
            $stmt2->bind_param("sssssiii", $trans_no, $deposit_date, $desc, $debit_account, $credit_account, $amount, $deposit_id, $created_by);
            $stmt2->execute();

            $conn->commit();

            logAudit('INSERT', 'deposits', $deposit_id, null, $_POST);

            $action_text = ($transaction_type == 'deposit') ? 'deposited' : 'withdrawn';
            $_SESSION['success'] = "Amount KES " . number_format($amount, 2) . " $action_text successfully. New balance: KES " . number_format($new_balance, 2);
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = 'Transaction failed: ' . $e->getMessage();
        }

        $conn->close();
        header('Location: index.php');
        exit();
    }
}

// Handle deletion (admin only)
if (isset($_GET['delete']) && hasRole('admin')) {
    $id = $_GET['delete'];

    $sql = "DELETE FROM deposits WHERE id = ?";
    executeQuery($sql, "i", [$id]);

    logAudit('DELETE', 'deposits', $id, null, null);
    $_SESSION['success'] = 'Deposit record deleted successfully';

    header('Location: index.php');
    exit();
}

// Get summary statistics
$summary_sql = "SELECT 
                COUNT(DISTINCT member_id) as total_depositors,
                COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE 0 END), 0) as total_deposits,
                COALESCE(SUM(CASE WHEN transaction_type = 'withdrawal' THEN amount ELSE 0 END), 0) as total_withdrawals,
                COUNT(CASE WHEN transaction_type = 'deposit' THEN 1 END) as deposit_count,
                COUNT(CASE WHEN transaction_type = 'withdrawal' THEN 1 END) as withdrawal_count,
                COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE -amount END), 0) as net_balance
                FROM deposits";
$summary_result = executeQuery($summary_sql);
$summary = $summary_result->fetch_assoc();

// Get monthly deposits for chart
$monthly_sql = "SELECT 
                DATE_FORMAT(deposit_date, '%Y-%m') as month,
                SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE 0 END) as deposits,
                SUM(CASE WHEN transaction_type = 'withdrawal' THEN amount ELSE 0 END) as withdrawals
                FROM deposits 
                WHERE deposit_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                GROUP BY DATE_FORMAT(deposit_date, '%Y-%m')
                ORDER BY month ASC";
$monthly_result = executeQuery($monthly_sql);

// Get top depositors
$top_depositors_sql = "SELECT 
                        m.id, m.member_no, m.full_name,
                        COALESCE(SUM(CASE WHEN d.transaction_type = 'deposit' THEN d.amount ELSE 0 END), 0) as total_deposits,
                        COALESCE(SUM(CASE WHEN d.transaction_type = 'withdrawal' THEN d.amount ELSE 0 END), 0) as total_withdrawals,
                        COALESCE(SUM(CASE WHEN d.transaction_type = 'deposit' THEN d.amount ELSE -d.amount END), 0) as current_balance,
                        COUNT(CASE WHEN d.transaction_type = 'deposit' THEN 1 END) as deposit_count
                        FROM members m
                        LEFT JOIN deposits d ON m.id = d.member_id
                        WHERE m.membership_status = 'active'
                        GROUP BY m.id
                        HAVING total_deposits > 0
                        ORDER BY current_balance DESC
                        LIMIT 10";
$top_depositors = executeQuery($top_depositors_sql);

// Get recent transactions
$recent_sql = "SELECT d.*, 
               m.member_no, m.full_name as member_name
               FROM deposits d
               JOIN members m ON d.member_id = m.id
               ORDER BY d.deposit_date DESC, d.created_at DESC
               LIMIT 100";
$recent_deposits = executeQuery($recent_sql);

// Get members for dropdown
$members = executeQuery("SELECT id, member_no, full_name, 
                        (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE -amount END), 0) 
                         FROM deposits WHERE member_id = members.id) as current_balance
                        FROM members 
                        WHERE membership_status = 'active' 
                        ORDER BY full_name");

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Deposits Management</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item active">Deposits</li>
            </ul>
        </div>
        <div class="col-auto">
            <div class="btn-group">
                <button type="button" class="btn btn-success dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="fas fa-plus me-2"></i>New Transaction
                </button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#depositModal" onclick="setTransactionType('deposit')">
                            <i class="fas fa-arrow-down text-success me-2"></i>Record Deposit
                        </a></li>
                    <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#depositModal" onclick="setTransactionType('withdrawal')">
                            <i class="fas fa-arrow-up text-danger me-2"></i>Process Withdrawal
                        </a></li>
                    <li>
                        <hr class="dropdown-divider">
                    </li>
                    <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#bulkDepositModal">
                            <i class="fas fa-upload me-2"></i>Bulk Deposit
                        </a></li>
                </ul>
            </div>
            <button class="btn btn-primary" onclick="exportDeposits()">
                <i class="fas fa-file-excel me-2"></i>Export
            </button>
            <button class="btn btn-info" onclick="printStatement()">
                <i class="fas fa-print me-2"></i>Print
            </button>
        </div>
    </div>
</div>

<!-- Alert Messages -->
<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?php
        echo $_SESSION['success'];
        unset($_SESSION['success']);
        ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>
        <?php
        echo $_SESSION['error'];
        unset($_SESSION['error']);
        ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-xl-2 col-md-4">
        <div class="stats-card primary">
            <div class="stats-icon">
                <i class="fas fa-users"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo number_format($summary['total_depositors'] ?? 0); ?></h3>
                <p>Active Depositors</p>
            </div>
        </div>
    </div>

    <div class="col-xl-2 col-md-4">
        <div class="stats-card success">
            <div class="stats-icon">
                <i class="fas fa-arrow-down"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($summary['total_deposits'] ?? 0); ?></h3>
                <p>Total Deposits</p>
                <small><?php echo number_format($summary['deposit_count'] ?? 0); ?> transactions</small>
            </div>
        </div>
    </div>

    <div class="col-xl-2 col-md-4">
        <div class="stats-card danger">
            <div class="stats-icon">
                <i class="fas fa-arrow-up"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($summary['total_withdrawals'] ?? 0); ?></h3>
                <p>Total Withdrawals</p>
                <small><?php echo number_format($summary['withdrawal_count'] ?? 0); ?> transactions</small>
            </div>
        </div>
    </div>

    <div class="col-xl-2 col-md-4">
        <div class="stats-card warning">
            <div class="stats-icon">
                <i class="fas fa-piggy-bank"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($summary['net_balance'] ?? 0); ?></h3>
                <p>Net Balance</p>
            </div>
        </div>
    </div>

    <div class="col-xl-2 col-md-4">
        <div class="stats-card info">
            <div class="stats-icon">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="stats-content">
                <h3><?php
                    $avg_deposit = $summary['deposit_count'] > 0 ? $summary['total_deposits'] / $summary['deposit_count'] : 0;
                    echo formatCurrency($avg_deposit);
                    ?></h3>
                <p>Avg Deposit</p>
            </div>
        </div>
    </div>

    <div class="col-xl-2 col-md-4">
        <div class="stats-card secondary">
            <div class="stats-icon">
                <i class="fas fa-calendar"></i>
            </div>
            <div class="stats-content">
                <h3><?php
                    $today_deposits = executeQuery("SELECT COALESCE(SUM(amount), 0) as total FROM deposits WHERE deposit_date = CURDATE() AND transaction_type = 'deposit'")->fetch_assoc()['total'];
                    echo formatCurrency($today_deposits);
                    ?></h3>
                <p>Today's Deposits</p>
            </div>
        </div>
    </div>
</div>

<!-- Charts Row -->
<div class="row mb-4">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Monthly Deposit/Withdrawal Trend</h5>
                <div class="card-tools">
                    <select class="form-select form-select-sm" id="chartPeriod" onchange="updateChart()">
                        <option value="12">Last 12 Months</option>
                        <option value="6">Last 6 Months</option>
                        <option value="3">Last 3 Months</option>
                    </select>
                </div>
            </div>
            <div class="card-body">
                <canvas id="depositChart" height="100"></canvas>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Deposit Distribution</h5>
            </div>
            <div class="card-body">
                <canvas id="distributionChart" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Top Depositors -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title">Top Depositors</h5>
        <div class="card-tools">
            <a href="statements.php" class="btn btn-sm btn-outline-primary">View All Statements</a>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Member</th>
                        <th>Member No</th>
                        <th>Total Deposits</th>
                        <th>Withdrawals</th>
                        <th>Current Balance</th>
                        <th>Transactions</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $rank = 1;
                    while ($depositor = $top_depositors->fetch_assoc()):
                    ?>
                        <tr>
                            <td><strong>#<?php echo $rank++; ?></strong></td>
                            <td>
                                <a href="../members/view.php?id=<?php echo $depositor['id']; ?>">
                                    <?php echo $depositor['full_name']; ?>
                                </a>
                            </td>
                            <td><?php echo $depositor['member_no']; ?></td>
                            <td class="text-success"><?php echo formatCurrency($depositor['total_deposits']); ?></td>
                            <td class="text-danger"><?php echo formatCurrency($depositor['total_withdrawals']); ?></td>
                            <td class="text-primary fw-bold"><?php echo formatCurrency($depositor['current_balance']); ?></td>
                            <td>
                                <span class="badge bg-info"><?php echo $depositor['deposit_count']; ?> deposits</span>
                            </td>
                            <td>
                                <a href="statement.php?member_id=<?php echo $depositor['id']; ?>" class="btn btn-sm btn-outline-info" title="Statement">
                                    <i class="fas fa-file-invoice"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Recent Transactions -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title">Recent Transactions</h5>
        <ul class="nav nav-tabs card-header-tabs">
            <li class="nav-item">
                <a class="nav-link active" data-bs-toggle="tab" href="#all">All</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#deposits">Deposits</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#withdrawals">Withdrawals</a>
            </li>
        </ul>
    </div>
    <div class="card-body">
        <div class="tab-content">
            <div class="tab-pane fade show active" id="all">
                <div class="table-responsive">
                    <table class="table table-hover datatable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Member</th>
                                <th>Member No</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Balance</th>
                                <th>Reference</th>
                                <th>Description</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($deposit = $recent_deposits->fetch_assoc()): ?>
                                <tr class="<?php echo $deposit['transaction_type'] == 'deposit' ? 'table-success' : 'table-danger'; ?>">
                                    <td><?php echo formatDate($deposit['deposit_date']); ?></td>
                                    <td>
                                        <a href="../members/view.php?id=<?php echo $deposit['member_id']; ?>">
                                            <?php echo $deposit['member_name']; ?>
                                        </a>
                                    </td>
                                    <td><?php echo $deposit['member_no']; ?></td>
                                    <td>
                                        <?php if ($deposit['transaction_type'] == 'deposit'): ?>
                                            <span class="badge bg-success">Deposit</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Withdrawal</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="<?php echo $deposit['transaction_type'] == 'deposit' ? 'text-success' : 'text-danger'; ?> fw-bold">
                                        <?php echo $deposit['transaction_type'] == 'deposit' ? '+' : '-'; ?>
                                        <?php echo formatCurrency($deposit['amount']); ?>
                                    </td>
                                    <td><?php echo formatCurrency($deposit['balance']); ?></td>
                                    <td>
                                        <?php if ($deposit['reference_no']): ?>
                                            <small><?php echo $deposit['reference_no']; ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $deposit['description'] ?: '-'; ?></td>
                                    <td>
                                        <div class="btn-group">
                                            <a href="receipt.php?id=<?php echo $deposit['id']; ?>" class="btn btn-sm btn-outline-info" title="Receipt">
                                                <i class="fas fa-receipt"></i>
                                            </a>
                                            <?php if (hasRole('admin')): ?>
                                                <a href="javascript:void(0)" onclick="confirmDelete(<?php echo $deposit['id']; ?>)"
                                                    class="btn btn-sm btn-outline-danger" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="tab-pane fade" id="deposits">
                <!-- Deposits only view -->
            </div>

            <div class="tab-pane fade" id="withdrawals">
                <!-- Withdrawals only view -->
            </div>
        </div>
    </div>
</div>

<!-- Deposit/Withdrawal Modal -->
<div class="modal fade" id="depositModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="" class="needs-validation" novalidate>
                <div class="modal-header" id="modalHeader">
                    <h5 class="modal-title" id="modalTitle">Record Deposit</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_deposit">
                    <input type="hidden" name="transaction_type" id="transaction_type" value="deposit">

                    <div class="mb-3">
                        <label for="member_id" class="form-label">Select Member <span class="text-danger">*</span></label>
                        <select class="form-control select2" id="member_id" name="member_id" required onchange="loadMemberBalance()">
                            <option value="">-- Select Member --</option>
                            <?php
                            $members->data_seek(0);
                            while ($member = $members->fetch_assoc()):
                            ?>
                                <option value="<?php echo $member['id']; ?>" data-balance="<?php echo $member['current_balance']; ?>">
                                    <?php echo $member['full_name']; ?> (<?php echo $member['member_no']; ?>) -
                                    Balance: <?php echo formatCurrency($member['current_balance']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="alert alert-info" id="balanceInfo" style="display: none;">
                        <strong>Current Balance:</strong> <span id="currentBalance"></span>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="amount" class="form-label">Amount (KES) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="amount" name="amount"
                                min="1" required onchange="validateAmount()">
                            <div class="invalid-feedback">Please enter valid amount</div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="payment_method" class="form-label">Payment Method</label>
                            <select class="form-control" id="payment_method" name="payment_method">
                                <option value="cash">Cash</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="mpesa">M-Pesa</option>
                                <option value="cheque">Cheque</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="deposit_date" class="form-label">Transaction Date</label>
                            <input type="date" class="form-control" id="deposit_date" name="deposit_date"
                                value="<?php echo date('Y-m-d'); ?>">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="reference_no" class="form-label">Reference Number</label>
                            <input type="text" class="form-control" id="reference_no" name="reference_no"
                                value="TXN<?php echo time(); ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description/Notes</label>
                        <textarea class="form-control" id="description" name="description" rows="2"></textarea>
                    </div>

                    <div class="alert alert-warning" id="withdrawalWarning" style="display: none;">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Warning:</strong> Please ensure member has sufficient balance for withdrawal.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" id="submitBtn">
                        <i class="fas fa-save me-2"></i>Process Transaction
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Deposit Modal -->
<div class="modal fade" id="bulkDepositModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Bulk Deposit Upload</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    Upload an Excel/CSV file with columns: Member No, Amount, Reference, Date (optional)
                </div>

                <form action="bulk-upload.php" method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label for="bulk_file" class="form-label">Select File</label>
                        <input type="file" class="form-control" id="bulk_file" name="bulk_file"
                            accept=".csv,.xlsx,.xls" required>
                        <small class="text-muted">Supported formats: CSV, Excel (.xlsx, .xls)</small>
                    </div>

                    <div class="mb-3">
                        <a href="sample.csv" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-download me-2"></i>Download Sample
                        </a>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-upload me-2"></i>Upload & Process
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    // Set transaction type for modal
    function setTransactionType(type) {
        document.getElementById('transaction_type').value = type;
        var modal = document.getElementById('depositModal');
        var modalHeader = modal.querySelector('.modal-header');
        var modalTitle = modal.querySelector('#modalTitle');
        var submitBtn = modal.querySelector('#submitBtn');
        var withdrawalWarning = document.getElementById('withdrawalWarning');

        if (type == 'deposit') {
            modalHeader.className = 'modal-header bg-success text-white';
            modalTitle.innerHTML = 'Record Deposit';
            submitBtn.className = 'btn btn-success';
            submitBtn.innerHTML = '<i class="fas fa-arrow-down me-2"></i>Process Deposit';
            withdrawalWarning.style.display = 'none';
        } else {
            modalHeader.className = 'modal-header bg-danger text-white';
            modalTitle.innerHTML = 'Process Withdrawal';
            submitBtn.className = 'btn btn-danger';
            submitBtn.innerHTML = '<i class="fas fa-arrow-up me-2"></i>Process Withdrawal';
            withdrawalWarning.style.display = 'block';
        }
    }

    // Load member balance
    function loadMemberBalance() {
        var select = document.getElementById('member_id');
        var selected = select.options[select.selectedIndex];
        var balance = selected.dataset.balance || 0;

        if (select.value) {
            document.getElementById('balanceInfo').style.display = 'block';
            document.getElementById('currentBalance').innerHTML = formatCurrency(balance);
        } else {
            document.getElementById('balanceInfo').style.display = 'none';
        }
    }

    // Validate amount for withdrawal
    function validateAmount() {
        var type = document.getElementById('transaction_type').value;
        if (type == 'withdrawal') {
            var amount = parseFloat(document.getElementById('amount').value) || 0;
            var select = document.getElementById('member_id');
            var selected = select.options[select.selectedIndex];
            var balance = parseFloat(selected.dataset.balance) || 0;

            if (amount > balance) {
                Swal.fire({
                    icon: 'error',
                    title: 'Insufficient Balance',
                    text: 'Withdrawal amount exceeds available balance of ' + formatCurrency(balance)
                });
                document.getElementById('amount').value = '';
            }
        }
    }

    // Format currency
    function formatCurrency(amount) {
        return 'KES ' + parseFloat(amount).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    // Export deposits
    function exportDeposits() {
        window.location.href = 'export.php';
    }

    // Print statement
    function printStatement() {
        window.location.href = 'print.php';
    }

    // Confirm delete
    function confirmDelete(id) {
        Swal.fire({
            title: 'Are you sure?',
            text: "This action cannot be undone!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'index.php?delete=' + id;
            }
        });
    }

    // Initialize charts
    document.addEventListener('DOMContentLoaded', function() {
        // Deposit/Withdrawal Chart
        var months = [];
        var depositsData = [];
        var withdrawalsData = [];

        <?php
        $monthly_result->data_seek(0);
        while ($row = $monthly_result->fetch_assoc()):
        ?>
            months.push('<?php echo $row['month']; ?>');
            depositsData.push(<?php echo $row['deposits'] ?? 0; ?>);
            withdrawalsData.push(<?php echo $row['withdrawals'] ?? 0; ?>);
        <?php endwhile; ?>

        if (months.length > 0) {
            var ctx = document.getElementById('depositChart').getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: months,
                    datasets: [{
                        label: 'Deposits',
                        data: depositsData,
                        backgroundColor: 'rgba(40, 167, 69, 0.5)',
                        borderColor: 'rgb(40, 167, 69)',
                        borderWidth: 1
                    }, {
                        label: 'Withdrawals',
                        data: withdrawalsData,
                        backgroundColor: 'rgba(220, 53, 69, 0.5)',
                        borderColor: 'rgb(220, 53, 69)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return 'KES ' + value.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
        }

        // Distribution Chart - Balance ranges
        <?php
        $range1 = executeQuery("SELECT COUNT(*) as count FROM members WHERE 
        (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE -amount END), 0) 
         FROM deposits WHERE member_id = members.id) BETWEEN 0 AND 10000")->fetch_assoc();
        $range2 = executeQuery("SELECT COUNT(*) as count FROM members WHERE 
        (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE -amount END), 0) 
         FROM deposits WHERE member_id = members.id) BETWEEN 10001 AND 50000")->fetch_assoc();
        $range3 = executeQuery("SELECT COUNT(*) as count FROM members WHERE 
        (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE -amount END), 0) 
         FROM deposits WHERE member_id = members.id) BETWEEN 50001 AND 100000")->fetch_assoc();
        $range4 = executeQuery("SELECT COUNT(*) as count FROM members WHERE 
        (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE -amount END), 0) 
         FROM deposits WHERE member_id = members.id) > 100000")->fetch_assoc();
        ?>

        var distCtx = document.getElementById('distributionChart').getContext('2d');
        new Chart(distCtx, {
            type: 'doughnut',
            data: {
                labels: ['0-10K', '10K-50K', '50K-100K', '100K+'],
                datasets: [{
                    data: [
                        <?php echo $range1['count'] ?? 0; ?>,
                        <?php echo $range2['count'] ?? 0; ?>,
                        <?php echo $range3['count'] ?? 0; ?>,
                        <?php echo $range4['count'] ?? 0; ?>
                    ],
                    backgroundColor: [
                        'rgba(255, 99, 132, 0.5)',
                        'rgba(54, 162, 235, 0.5)',
                        'rgba(255, 206, 86, 0.5)',
                        'rgba(75, 192, 192, 0.5)'
                    ],
                    borderColor: [
                        'rgb(255, 99, 132)',
                        'rgb(54, 162, 235)',
                        'rgb(255, 206, 86)',
                        'rgb(75, 192, 192)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    });

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

    // Initialize Select2
    $(document).ready(function() {
        $('.select2').select2({
            theme: 'bootstrap-5',
            width: '100%',
            dropdownParent: $('#depositModal')
        });
    });

    // Update chart based on period
    function updateChart() {
        var period = document.getElementById('chartPeriod').value;
        // In production, you'd reload chart data via AJAX
        location.reload();
    }
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

    .table-success {
        background-color: rgba(40, 167, 69, 0.05);
    }

    .table-danger {
        background-color: rgba(220, 53, 69, 0.05);
    }

    .modal-header.bg-success,
    .modal-header.bg-danger {
        color: white;
    }

    .modal-header.bg-success .btn-close,
    .modal-header.bg-danger .btn-close {
        filter: brightness(0) invert(1);
    }

    /* Responsive */
    @media (max-width: 768px) {
        .btn-group {
            margin-bottom: 10px;
            width: 100%;
        }

        .btn-group .btn {
            width: 100%;
        }
    }
</style>

<?php include '../../includes/footer.php'; ?>
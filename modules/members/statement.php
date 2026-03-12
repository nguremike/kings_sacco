<?php
require_once '../../config/config.php';
requireLogin();

$member_id = $_GET['id'] ?? 0;
$date_from = $_GET['from'] ?? date('Y-m-d', strtotime('-1 year'));
$date_to = $_GET['to'] ?? date('Y-m-d');

// Get member details
$member_sql = "SELECT m.*, 
               u.username as user_account,
               (SELECT COUNT(*) FROM loans WHERE member_id = m.id) as total_loans,
               (SELECT COUNT(*) FROM shares WHERE member_id = m.id) as total_share_transactions
               FROM members m
               LEFT JOIN users u ON m.user_id = u.id
               WHERE m.id = ?";
$member_result = executeQuery($member_sql, "i", [$member_id]);

if ($member_result->num_rows == 0) {
    $_SESSION['error'] = 'Member not found';
    header('Location: index.php');
    exit();
}

$member = $member_result->fetch_assoc();
$page_title = 'Member Statement - ' . $member['full_name'];

// Get share summary
$share_summary_sql = "SELECT 
                      COALESCE(SUM(shares_count), 0) as total_shares,
                      COALESCE(SUM(total_value), 0) as total_share_value,
                      COUNT(*) as share_transactions
                      FROM shares 
                      WHERE member_id = ?";
$share_summary = executeQuery($share_summary_sql, "i", [$member_id])->fetch_assoc();

// Get share contributions (partial payments)
$contributions_sql = "SELECT 
                      COALESCE(SUM(amount), 0) as total_contributions,
                      COUNT(*) as contribution_count
                      FROM share_contributions 
                      WHERE member_id = ?";
$contributions = executeQuery($contributions_sql, "i", [$member_id])->fetch_assoc();

// Get shares issued
$shares_issued_sql = "SELECT * FROM shares_issued 
                      WHERE member_id = ? 
                      ORDER BY issue_date DESC";
$shares_issued = executeQuery($shares_issued_sql, "i", [$member_id]);

// Get deposit summary
$deposit_summary_sql = "SELECT 
                        COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE 0 END), 0) as total_deposits,
                        COALESCE(SUM(CASE WHEN transaction_type = 'withdrawal' THEN amount ELSE 0 END), 0) as total_withdrawals,
                        COUNT(CASE WHEN transaction_type = 'deposit' THEN 1 END) as deposit_count,
                        COUNT(CASE WHEN transaction_type = 'withdrawal' THEN 1 END) as withdrawal_count,
                        COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE -amount END), 0) as current_balance
                        FROM deposits 
                        WHERE member_id = ?";
$deposit_summary = executeQuery($deposit_summary_sql, "i", [$member_id])->fetch_assoc();

// Get loan summary
$loan_summary_sql = "SELECT 
                     COUNT(*) as total_loans,
                     COALESCE(SUM(principal_amount), 0) as total_principal,
                     COALESCE(SUM(interest_amount), 0) as total_interest,
                     COALESCE(SUM(total_amount), 0) as total_amount,
                     COALESCE(SUM(CASE WHEN status IN ('disbursed', 'active') THEN 
                         (total_amount - COALESCE((SELECT SUM(amount_paid) FROM loan_repayments WHERE loan_id = loans.id), 0))
                         ELSE 0 END), 0) as outstanding_balance
                     FROM loans 
                     WHERE member_id = ?";
$loan_summary = executeQuery($loan_summary_sql, "i", [$member_id])->fetch_assoc();

// Get active loans details
$active_loans_sql = "SELECT l.*, lp.product_name,
                     COALESCE((SELECT SUM(amount_paid) FROM loan_repayments WHERE loan_id = l.id), 0) as amount_paid,
                     (l.total_amount - COALESCE((SELECT SUM(amount_paid) FROM loan_repayments WHERE loan_id = l.id), 0)) as remaining_balance
                     FROM loans l
                     LEFT JOIN loan_products lp ON l.product_id = lp.id
                     WHERE l.member_id = ? AND l.status IN ('disbursed', 'active')
                     ORDER BY l.disbursement_date DESC";
$active_loans = executeQuery($active_loans_sql, "i", [$member_id]);

// Get all transactions (combined view)
$transactions_sql = "SELECT 
                     'deposit' as source,
                     d.id as transaction_id,
                     d.deposit_date as transaction_date,
                     d.transaction_type as type,
                     d.amount,
                     d.balance as running_balance,
                     d.reference_no,
                     d.description,
                     NULL as loan_no,
                     NULL as product_name,
                     NULL as shares_count,
                     NULL as share_value
                     FROM deposits d
                     WHERE d.member_id = ? AND d.deposit_date BETWEEN ? AND ?
                     
                     UNION ALL
                     
                     SELECT 
                     'loan_repayment' as source,
                     lr.id as transaction_id,
                     lr.payment_date as transaction_date,
                     'loan_repayment' as type,
                     lr.amount_paid as amount,
                     NULL as running_balance,
                     lr.reference_no,
                     CONCAT('Loan Repayment - ', l.loan_no) as description,
                     l.loan_no,
                     lp.product_name,
                     NULL,
                     NULL
                     FROM loan_repayments lr
                     JOIN loans l ON lr.loan_id = l.id
                     LEFT JOIN loan_products lp ON l.product_id = lp.id
                     WHERE l.member_id = ? AND lr.payment_date BETWEEN ? AND ?
                     
                     UNION ALL
                     
                     SELECT 
                     'share_purchase' as source,
                     s.id as transaction_id,
                     s.date_purchased as transaction_date,
                     'share_purchase' as type,
                     s.total_value as amount,
                     NULL as running_balance,
                     s.reference_no,
                     CONCAT('Share Purchase - ', s.shares_count, ' shares') as description,
                     NULL,
                     NULL,
                     s.shares_count,
                     s.share_value
                     FROM shares s
                     WHERE s.member_id = ? AND s.date_purchased BETWEEN ? AND ?
                     
                     UNION ALL
                     
                     SELECT 
                     'share_contribution' as source,
                     sc.id as transaction_id,
                     sc.contribution_date as transaction_date,
                     'share_contribution' as type,
                     sc.amount,
                     NULL as running_balance,
                     sc.reference_no,
                     CONCAT('Share Contribution - ', sc.notes) as description,
                     NULL,
                     NULL,
                     NULL,
                     NULL
                     FROM share_contributions sc
                     WHERE sc.member_id = ? AND sc.contribution_date BETWEEN ? AND ?
                     
                     ORDER BY transaction_date DESC";
$transactions = executeQuery(
    $transactions_sql,
    "ssssssssssss",
    [
        $member_id,
        $date_from,
        $date_to,
        $member_id,
        $date_from,
        $date_to,
        $member_id,
        $date_from,
        $date_to,
        $member_id,
        $date_from,
        $date_to
    ]
);

// Get running balance for deposits over time for chart
$balance_history_sql = "SELECT 
                        deposit_date,
                        balance
                        FROM deposits 
                        WHERE member_id = ? 
                        ORDER BY deposit_date ASC";
$balance_history = executeQuery($balance_history_sql, "i", [$member_id]);

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Member Statement</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Members</a></li>
                <li class="breadcrumb-item"><a href="view.php?id=<?php echo $member_id; ?>"><?php echo $member['full_name']; ?></a></li>
                <li class="breadcrumb-item active">Statement</li>
            </ul>
        </div>
        <div class="col-auto">
            <button class="btn btn-success" onclick="window.print()">
                <i class="fas fa-print me-2"></i>Print Statement
            </button>
            <button class="btn btn-primary" onclick="exportStatement()">
                <i class="fas fa-file-pdf me-2"></i>Export PDF
            </button>
            <a href="view.php?id=<?php echo $member_id; ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Profile
            </a>
        </div>
    </div>
</div>

<!-- Date Range Filter -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <input type="hidden" name="id" value="<?php echo $member_id; ?>">

            <div class="col-md-4">
                <label for="from" class="form-label">From Date</label>
                <input type="date" class="form-control" id="from" name="from" value="<?php echo $date_from; ?>">
            </div>

            <div class="col-md-4">
                <label for="to" class="form-label">To Date</label>
                <input type="date" class="form-control" id="to" name="to" value="<?php echo $date_to; ?>">
            </div>

            <div class="col-md-4">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary d-block">
                    <i class="fas fa-filter me-2"></i>Apply Filter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Member Information Card -->
<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="card-title mb-0">Member Information</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3">
                <p><strong>Member No:</strong><br> <?php echo $member['member_no']; ?></p>
            </div>
            <div class="col-md-3">
                <p><strong>Full Name:</strong><br> <?php echo $member['full_name']; ?></p>
            </div>
            <div class="col-md-3">
                <p><strong>National ID:</strong><br> <?php echo $member['national_id']; ?></p>
            </div>
            <div class="col-md-3">
                <p><strong>Phone:</strong><br> <?php echo $member['phone']; ?></p>
            </div>
            <div class="col-md-3">
                <p><strong>Date Joined:</strong><br> <?php echo formatDate($member['date_joined']); ?></p>
            </div>
            <div class="col-md-3">
                <p><strong>Membership Status:</strong><br>
                    <span class="badge bg-<?php echo $member['membership_status'] == 'active' ? 'success' : 'warning'; ?>">
                        <?php echo ucfirst($member['membership_status']); ?>
                    </span>
                </p>
            </div>
            <div class="col-md-3">
                <p><strong>Statement Period:</strong><br> <?php echo formatDate($date_from); ?> - <?php echo formatDate($date_to); ?></p>
            </div>
            <div class="col-md-3">
                <p><strong>Generated On:</strong><br> <?php echo date('d M Y H:i:s'); ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <!-- Shares Summary -->
    <div class="col-md-4">
        <div class="card h-100 border-primary">
            <div class="card-header bg-primary text-white">
                <h6 class="card-title mb-0"><i class="fas fa-chart-pie me-2"></i>Shares Summary</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-6">
                        <p class="text-muted mb-1">Full Shares</p>
                        <h4><?php echo number_format($member['full_shares_issued'] ?? 0); ?></h4>
                    </div>
                    <div class="col-6">
                        <p class="text-muted mb-1">Share Value</p>
                        <h4><?php echo formatCurrency($share_summary['total_share_value'] ?? 0); ?></h4>
                    </div>
                    <div class="col-6">
                        <p class="text-muted mb-1">Contributions</p>
                        <h4><?php echo formatCurrency($contributions['total_contributions'] ?? 0); ?></h4>
                    </div>
                    <div class="col-6">
                        <p class="text-muted mb-1">Partial Balance</p>
                        <h4><?php echo formatCurrency($member['partial_share_balance'] ?? 0); ?></h4>
                    </div>
                </div>
                <div class="progress mt-2" style="height: 10px;">
                    <?php
                    $progress = (($member['partial_share_balance'] ?? 0) / 10000) * 100;
                    ?>
                    <div class="progress-bar bg-success" role="progressbar"
                        style="width: <?php echo $progress; ?>%;"
                        aria-valuenow="<?php echo $progress; ?>"
                        aria-valuemin="0"
                        aria-valuemax="100">
                    </div>
                </div>
                <small class="text-muted">Progress to next share: <?php echo number_format($progress, 1); ?>%</small>
            </div>
        </div>
    </div>

    <!-- Deposits Summary -->
    <div class="col-md-4">
        <div class="card h-100 border-success">
            <div class="card-header bg-success text-white">
                <h6 class="card-title mb-0"><i class="fas fa-piggy-bank me-2"></i>Savings Summary</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-6">
                        <p class="text-muted mb-1">Total Deposits</p>
                        <h4 class="text-success"><?php echo formatCurrency($deposit_summary['total_deposits']); ?></h4>
                    </div>
                    <div class="col-6">
                        <p class="text-muted mb-1">Total Withdrawals</p>
                        <h4 class="text-danger"><?php echo formatCurrency($deposit_summary['total_withdrawals']); ?></h4>
                    </div>
                    <div class="col-6">
                        <p class="text-muted mb-1">Current Balance</p>
                        <h4 class="text-primary"><?php echo formatCurrency($deposit_summary['current_balance']); ?></h4>
                    </div>
                    <div class="col-6">
                        <p class="text-muted mb-1">Transactions</p>
                        <h4><?php echo $deposit_summary['deposit_count'] + $deposit_summary['withdrawal_count']; ?></h4>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Loans Summary -->
    <div class="col-md-4">
        <div class="card h-100 border-warning">
            <div class="card-header bg-warning text-dark">
                <h6 class="card-title mb-0"><i class="fas fa-hand-holding-usd me-2"></i>Loans Summary</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-6">
                        <p class="text-muted mb-1">Total Loans</p>
                        <h4><?php echo $loan_summary['total_loans']; ?></h4>
                    </div>
                    <div class="col-6">
                        <p class="text-muted mb-1">Total Principal</p>
                        <h4><?php echo formatCurrency($loan_summary['total_principal']); ?></h4>
                    </div>
                    <div class="col-6">
                        <p class="text-muted mb-1">Total Interest</p>
                        <h4><?php echo formatCurrency($loan_summary['total_interest']); ?></h4>
                    </div>
                    <div class="col-6">
                        <p class="text-muted mb-1">Outstanding</p>
                        <h4 class="text-danger"><?php echo formatCurrency($loan_summary['outstanding_balance']); ?></h4>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Balance History Chart -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title">Savings Balance History</h5>
    </div>
    <div class="card-body">
        <canvas id="balanceChart" height="100"></canvas>
    </div>
</div>

<!-- Active Loans Section -->
<?php if ($active_loans->num_rows > 0): ?>
    <div class="card mb-4">
        <div class="card-header bg-warning">
            <h5 class="card-title mb-0 text-dark">Active Loans</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Loan No</th>
                            <th>Product</th>
                            <th>Disbursement Date</th>
                            <th>Principal</th>
                            <th>Interest</th>
                            <th>Total</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($loan = $active_loans->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?php echo $loan['loan_no']; ?></strong></td>
                                <td><?php echo $loan['product_name']; ?></td>
                                <td><?php echo formatDate($loan['disbursement_date']); ?></td>
                                <td><?php echo formatCurrency($loan['principal_amount']); ?></td>
                                <td><?php echo formatCurrency($loan['interest_amount']); ?></td>
                                <td><?php echo formatCurrency($loan['total_amount']); ?></td>
                                <td class="text-success"><?php echo formatCurrency($loan['amount_paid']); ?></td>
                                <td class="text-danger fw-bold"><?php echo formatCurrency($loan['remaining_balance']); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $loan['status'] == 'active' ? 'success' : 'info'; ?>">
                                        <?php echo ucfirst($loan['status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Shares Issued Section -->
<?php if ($shares_issued->num_rows > 0): ?>
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="card-title mb-0">Share Certificates Issued</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Certificate No</th>
                            <th>Share Number</th>
                            <th>Issue Date</th>
                            <th>Amount Paid</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($cert = $shares_issued->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $cert['certificate_number']; ?></td>
                                <td><?php echo $cert['share_number']; ?></td>
                                <td><?php echo formatDate($cert['issue_date']); ?></td>
                                <td><?php echo formatCurrency($cert['amount_paid']); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Detailed Transactions -->
<div class="card">
    <div class="card-header bg-secondary text-white">
        <h5 class="card-title mb-0">Transaction History</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-striped" id="transactionsTable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th>Reference</th>
                        <th>Debit</th>
                        <th>Credit</th>
                        <th>Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $running_balance = 0;
                    $transactions_array = [];
                    while ($trans = $transactions->fetch_assoc()) {
                        $transactions_array[] = $trans;
                    }

                    // Reverse for chronological order
                    $transactions_array = array_reverse($transactions_array);

                    foreach ($transactions_array as $trans):
                        // Calculate running balance for deposits only
                        if ($trans['source'] == 'deposit') {
                            $running_balance = $trans['running_balance'];
                        }

                        // Determine if it's debit or credit
                        $is_debit = false;
                        $is_credit = false;
                        $amount = $trans['amount'];

                        if ($trans['source'] == 'deposit') {
                            if ($trans['type'] == 'deposit') {
                                $is_credit = true;
                            } else {
                                $is_debit = true;
                            }
                        } elseif ($trans['source'] == 'loan_repayment') {
                            $is_credit = true;
                        } elseif ($trans['source'] == 'share_purchase') {
                            $is_debit = true;
                        } elseif ($trans['source'] == 'share_contribution') {
                            $is_credit = true;
                        }
                    ?>
                        <tr>
                            <td><?php echo formatDate($trans['transaction_date']); ?></td>
                            <td>
                                <?php
                                $badge_class = 'secondary';
                                $icon = 'circle';
                                switch ($trans['source']) {
                                    case 'deposit':
                                        if ($trans['type'] == 'deposit') {
                                            $badge_class = 'success';
                                            $icon = 'arrow-down';
                                        } else {
                                            $badge_class = 'danger';
                                            $icon = 'arrow-up';
                                        }
                                        break;
                                    case 'loan_repayment':
                                        $badge_class = 'info';
                                        $icon = 'credit-card';
                                        break;
                                    case 'share_purchase':
                                        $badge_class = 'primary';
                                        $icon = 'chart-pie';
                                        break;
                                    case 'share_contribution':
                                        $badge_class = 'warning';
                                        $icon = 'coins';
                                        break;
                                }
                                ?>
                                <span class="badge bg-<?php echo $badge_class; ?>">
                                    <i class="fas fa-<?php echo $icon; ?> me-1"></i>
                                    <?php
                                    if ($trans['source'] == 'deposit') {
                                        echo ucfirst($trans['type']);
                                    } elseif ($trans['source'] == 'loan_repayment') {
                                        echo 'Loan Repayment';
                                    } elseif ($trans['source'] == 'share_purchase') {
                                        echo 'Share Purchase';
                                    } elseif ($trans['source'] == 'share_contribution') {
                                        echo 'Share Contribution';
                                    }
                                    ?>
                                </span>
                            </td>
                            <td>
                                <?php echo $trans['description']; ?>
                                <?php if ($trans['loan_no']): ?>
                                    <br><small class="text-muted">Loan: <?php echo $trans['loan_no']; ?></small>
                                <?php endif; ?>
                                <?php if ($trans['shares_count']): ?>
                                    <br><small class="text-muted"><?php echo $trans['shares_count']; ?> shares @ <?php echo formatCurrency($trans['share_value']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $trans['reference_no'] ?: '-'; ?></td>
                            <td class="text-danger"><?php echo $is_debit ? formatCurrency($amount) : '-'; ?></td>
                            <td class="text-success"><?php echo $is_credit ? formatCurrency($amount) : '-'; ?></td>
                            <td class="text-primary fw-bold">
                                <?php echo $trans['source'] == 'deposit' ? formatCurrency($running_balance) : '-'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (empty($transactions_array)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4">
                                <i class="fas fa-info-circle fa-2x text-muted mb-3"></i>
                                <p class="text-muted">No transactions found for the selected period.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr class="table-info">
                        <th colspan="4" class="text-end">Totals:</th>
                        <th class="text-danger">
                            <?php
                            $total_debits = 0;
                            foreach ($transactions_array as $trans) {
                                if (($trans['source'] == 'deposit' && $trans['type'] == 'withdrawal') || $trans['source'] == 'share_purchase') {
                                    $total_debits += $trans['amount'];
                                }
                            }
                            echo formatCurrency($total_debits);
                            ?>
                        </th>
                        <th class="text-success">
                            <?php
                            $total_credits = 0;
                            foreach ($transactions_array as $trans) {
                                if (($trans['source'] == 'deposit' && $trans['type'] == 'deposit') || $trans['source'] == 'loan_repayment' || $trans['source'] == 'share_contribution') {
                                    $total_credits += $trans['amount'];
                                }
                            }
                            echo formatCurrency($total_credits);
                            ?>
                        </th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <div class="card-footer text-muted">
        <small>
            <i class="fas fa-info-circle me-1"></i>
            Statement generated on <?php echo date('d M Y H:i:s'); ?> for period <?php echo formatDate($date_from); ?> to <?php echo formatDate($date_to); ?>
        </small>
    </div>
</div>

<script>
    // Balance History Chart
    document.addEventListener('DOMContentLoaded', function() {
        var dates = [];
        var balances = [];

        <?php
        $balance_history->data_seek(0);
        while ($row = $balance_history->fetch_assoc()):
        ?>
            dates.push('<?php echo formatDate($row['deposit_date']); ?>');
            balances.push(<?php echo $row['balance']; ?>);
        <?php endwhile; ?>

        if (dates.length > 0) {
            var ctx = document.getElementById('balanceChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: dates,
                    datasets: [{
                        label: 'Savings Balance',
                        data: balances,
                        borderColor: 'rgb(40, 167, 69)',
                        backgroundColor: 'rgba(40, 167, 69, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
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
    });

    // Export statement as PDF
    function exportStatement() {
        var memberId = <?php echo $member_id; ?>;
        var from = '<?php echo $date_from; ?>';
        var to = '<?php echo $date_to; ?>';

        window.location.href = 'export-statement.php?id=' + memberId + '&from=' + from + '&to=' + to;
    }

    // Print friendly styles
    window.onbeforeprint = function() {
        var tables = document.querySelectorAll('.table');
        tables.forEach(function(table) {
            table.classList.add('table-print');
        });
    };

    window.onafterprint = function() {
        var tables = document.querySelectorAll('.table');
        tables.forEach(function(table) {
            table.classList.remove('table-print');
        });
    };
</script>

<style>
    @media print {

        .sidebar,
        .navbar,
        .breadcrumb,
        .page-header .col-auto,
        .card-header .btn,
        .footer,
        .btn {
            display: none !important;
        }

        .main-content {
            margin-left: 0 !important;
            padding: 10px !important;
        }

        .card {
            box-shadow: none !important;
            border: 1px solid #ddd !important;
            margin-bottom: 15px !important;
            break-inside: avoid;
        }

        .table-print {
            font-size: 10pt;
        }

        .badge {
            border: 1px solid #000 !important;
            color: #000 !important;
            background: transparent !important;
        }
    }

    .table td,
    .table th {
        vertical-align: middle;
    }

    .progress {
        background-color: #e9ecef;
        border-radius: 5px;
    }

    .card-header.bg-warning {
        color: #000;
    }

    .border-primary {
        border-color: #007bff !important;
    }

    .border-success {
        border-color: #28a745 !important;
    }

    .border-warning {
        border-color: #ffc107 !important;
    }
</style>

<?php include '../../includes/footer.php'; ?>
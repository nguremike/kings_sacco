<?php
require_once '../../config/config.php';
requireLogin();

$member_id = $_GET['id'] ?? 0;
$year = $_GET['year'] ?? date('Y');
$month = $_GET['month'] ?? '';
$print = isset($_GET['print']) ? true : false;

// Get member details
$member_sql = "SELECT m.*, 
               u.username as user_account
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

// Get opening balance (balance brought forward from previous year)
$opening_balance_sql = "SELECT COALESCE(SUM(CASE 
                            WHEN transaction_type = 'deposit' THEN amount 
                            WHEN transaction_type = 'withdrawal' THEN -amount 
                            ELSE 0 
                        END), 0) as opening_balance
                        FROM deposits 
                        WHERE member_id = ? AND deposit_date < ?";
$year_start = $year . '-01-01';
$opening_result = executeQuery($opening_balance_sql, "is", [$member_id, $year_start]);
$opening_balance = $opening_result->fetch_assoc()['opening_balance'];

// Get share summary
$share_summary_sql = "SELECT 
                      COALESCE(SUM(shares_count), 0) as total_shares,
                      COALESCE(SUM(total_value), 0) as total_share_value,
                      COUNT(*) as share_transactions,
                      COALESCE(SUM(CASE WHEN YEAR(date_purchased) = ? THEN shares_count ELSE 0 END), 0) as shares_this_year,
                      COALESCE(SUM(CASE WHEN YEAR(date_purchased) = ? THEN total_value ELSE 0 END), 0) as share_value_this_year
                      FROM shares 
                      WHERE member_id = ?";
$share_summary_result = executeQuery($share_summary_sql, "iii", [$year, $year, $member_id]);
$share_summary = $share_summary_result->fetch_assoc();

// Get share contributions (partial payments)
$contributions_sql = "SELECT 
                      COALESCE(SUM(amount), 0) as total_contributions,
                      COUNT(*) as contribution_count,
                      COALESCE(SUM(CASE WHEN YEAR(contribution_date) = ? THEN amount ELSE 0 END), 0) as contributions_this_year,
                      COUNT(CASE WHEN YEAR(contribution_date) = ? THEN 1 END) as contributions_count_this_year
                      FROM share_contributions 
                      WHERE member_id = ?";
$contributions_result = executeQuery($contributions_sql, "iii", [$year, $year, $member_id]);
$contributions = $contributions_result->fetch_assoc();

// Get shares issued
$shares_issued_sql = "SELECT * FROM shares_issued 
                      WHERE member_id = ? 
                      ORDER BY issue_date DESC";
$shares_issued = executeQuery($shares_issued_sql, "i", [$member_id]);

// Get shares issued in current year
$shares_issued_year_sql = "SELECT COUNT(*) as count, COALESCE(SUM(amount_paid), 0) as total 
                           FROM shares_issued 
                           WHERE member_id = ? AND YEAR(issue_date) = ?";
$shares_issued_year_result = executeQuery($shares_issued_year_sql, "ii", [$member_id, $year]);
$shares_issued_year = $shares_issued_year_result->fetch_assoc();

// Calculate total share capital
$total_share_capital = $share_summary['total_share_value'] + $contributions['total_contributions'];
$share_progress = ($member['partial_share_balance'] ?? 0) / 10000 * 100;

// Get deposit transactions for the year
$deposits_sql = "SELECT d.*,
                 CASE 
                     WHEN d.transaction_type = 'deposit' THEN 'Credit'
                     WHEN d.transaction_type = 'withdrawal' THEN 'Debit'
                 END as entry_type
                 FROM deposits d
                 WHERE d.member_id = ? AND YEAR(d.deposit_date) = ?
                 ORDER BY d.deposit_date ASC";
$deposits = executeQuery($deposits_sql, "ii", [$member_id, $year]);

// Get loan payments for the year
$loan_payments_sql = "SELECT lr.*, l.loan_no, l.principal_amount as loan_principal
                      FROM loan_repayments lr
                      JOIN loans l ON lr.loan_id = l.id
                      WHERE l.member_id = ? AND YEAR(lr.payment_date) = ?
                      ORDER BY lr.payment_date DESC";
$loan_payments = executeQuery($loan_payments_sql, "ii", [$member_id, $year]);

// Get loan summary
$loan_summary_sql = "SELECT 
                     COUNT(DISTINCT l.id) as loans_active,
                     COUNT(lr.id) as payment_count,
                     COALESCE(SUM(lr.amount_paid), 0) as total_payments,
                     COALESCE(SUM(lr.principal_paid), 0) as total_principal_paid,
                     COALESCE(SUM(lr.interest_paid), 0) as total_interest_paid
                     FROM loans l
                     LEFT JOIN loan_repayments lr ON l.id = lr.loan_id AND YEAR(lr.payment_date) = ?
                     WHERE l.member_id = ?";
$loan_summary_result = executeQuery($loan_summary_sql, "ii", [$year, $member_id]);
$loan_summary = $loan_summary_result->fetch_assoc();

// Get current year totals
$year_totals_sql = "SELECT 
                    COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE 0 END), 0) as total_deposits,
                    COALESCE(SUM(CASE WHEN transaction_type = 'withdrawal' THEN amount ELSE 0 END), 0) as total_withdrawals
                    FROM deposits 
                    WHERE member_id = ? AND YEAR(deposit_date) = ?";
$year_totals_result = executeQuery($year_totals_sql, "ii", [$member_id, $year]);
$year_totals = $year_totals_result->fetch_assoc();

// Calculate closing balance
$closing_balance = $opening_balance + $year_totals['total_deposits'] - $year_totals['total_withdrawals'];

// Get monthly breakdown
$monthly_sql = "SELECT 
                MONTH(deposit_date) as month,
                SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE 0 END) as monthly_deposits,
                SUM(CASE WHEN transaction_type = 'withdrawal' THEN amount ELSE 0 END) as monthly_withdrawals
                FROM deposits 
                WHERE member_id = ? AND YEAR(deposit_date) = ?
                GROUP BY MONTH(deposit_date)
                ORDER BY month ASC";
$monthly_result = executeQuery($monthly_sql, "ii", [$member_id, $year]);

$monthly_data = [];
while ($row = $monthly_result->fetch_assoc()) {
    $monthly_data[$row['month']] = $row;
}

// Get available years for dropdown
$years_sql = "SELECT DISTINCT YEAR(deposit_date) as year FROM deposits WHERE member_id = ? 
              UNION 
              SELECT DISTINCT YEAR(contribution_date) FROM share_contributions WHERE member_id = ?
              UNION
              SELECT DISTINCT YEAR(payment_date) FROM loan_repayments lr JOIN loans l ON lr.loan_id = l.id WHERE l.member_id = ?
              ORDER BY year DESC";
$years_result = executeQuery($years_sql, "iii", [$member_id, $member_id, $member_id]);

// If print mode, use print template
if ($print) {
    include 'statement_print.php';
    exit();
}

include '../../includes/header.php';
?>

<!-- Page Header -->
<!-- <div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Member Statement</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Members</a></li>
                <li class="breadcrumb-item"><a href="view.php?id=<? //php echo $member_id; 
                                                                    ?>"><? //php echo $member['full_name']; 
                                                                        ?></a></li>
                <li class="breadcrumb-item active">Statement</li>
            </ul>
        </div>
        <div class="col-auto">
            <button class="btn btn-success" onclick="window.open('print-statement.php?id=<? //php echo $member_id; 
                                                                                            ?>&year=<? //php echo $year; 
                                                                                                    ?>&print=1', '_blank')">
                <i class="fas fa-print me-2"></i>Print Statement
            </button>
            <button class="btn btn-primary" onclick="exportStatement()">
                <i class="fas fa-file-pdf me-2"></i>Export PDF
            </button>
            <a href="view.php?id=<? //php echo $member_id; 
                                    ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Profile
            </a>
        </div>
    </div>
</div> -->
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
            <!-- Print Statement Button - Opens in new window -->
            <a href="statement_print.php?id=<?php echo $member_id; ?>&year=<?php echo $year; ?>"
                class="btn btn-success"
                target="_blank">
                <i class="fas fa-print me-2"></i>Print Statement
            </a>

            <!-- Export PDF Button -->
            <button class="btn btn-primary" onclick="exportStatement()">
                <i class="fas fa-file-pdf me-2"></i>Export PDF
            </button>

            <!-- Back Button -->
            <a href="view.php?id=<?php echo $member_id; ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Profile
            </a>
        </div>
    </div>
</div>

<!-- Year and Period Selection -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <input type="hidden" name="id" value="<?php echo $member_id; ?>">

            <div class="col-md-4">
                <label for="year" class="form-label">Select Year</label>
                <select class="form-control" id="year" name="year" onchange="this.form.submit()">
                    <?php
                    $years_result->data_seek(0);
                    while ($y = $years_result->fetch_assoc()):
                    ?>
                        <option value="<?php echo $y['year']; ?>" <?php echo $y['year'] == $year ? 'selected' : ''; ?>>
                            <?php echo $y['year']; ?>
                        </option>
                    <?php endwhile; ?>
                    <option value="<?php echo date('Y'); ?>" <?php echo date('Y') == $year ? 'selected' : ''; ?>>
                        <?php echo date('Y'); ?> (Current)
                    </option>
                </select>
            </div>

            <div class="col-md-4">
                <label for="month" class="form-label">Filter by Month (Optional)</label>
                <select class="form-control" id="month" name="month" onchange="this.form.submit()">
                    <option value="">All Months</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $m == $month ? 'selected' : ''; ?>>
                            <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                        </option>
                    <?php endfor; ?>
                </select>
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
                <p><strong>Statement Period:</strong><br> Year <?php echo $year; ?></p>
            </div>
            <div class="col-md-3">
                <p><strong>Generated On:</strong><br> <?php echo date('d M Y H:i:s'); ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Balance Brought Forward Card -->
<div class="card mb-4 border-info">
    <div class="card-header bg-info text-white">
        <h5 class="card-title mb-0"><i class="fas fa-forward me-2"></i>Balance Brought Forward (as at 31/12/<?php echo $year - 1; ?>)</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <h3 class="text-primary"><?php echo formatCurrency($opening_balance); ?></h3>
                <p class="text-muted">Opening savings balance for the year <?php echo $year; ?></p>
            </div>
            <div class="col-md-6 text-end">
                <span class="badge bg-info fs-6">Balance B/F</span>
            </div>
        </div>
    </div>
</div>

<!-- Share Capital Summary Card -->
<div class="card mb-4 border-primary">
    <div class="card-header bg-primary text-white">
        <h5 class="card-title mb-0"><i class="fas fa-chart-pie me-2"></i>Share Capital Summary</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3">
                <div class="border p-3 text-center">
                    <small class="text-muted">Total Shares</small>
                    <h5><?php echo number_format($share_summary['total_shares']); ?></h5>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border p-3 text-center">
                    <small class="text-muted">Share Value</small>
                    <h5 class="text-primary"><?php echo formatCurrency($share_summary['total_share_value']); ?></h5>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border p-3 text-center">
                    <small class="text-muted">Contributions</small>
                    <h5 class="text-success"><?php echo formatCurrency($contributions['total_contributions']); ?></h5>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border p-3 text-center bg-light">
                    <small class="text-muted">Total Share Capital</small>
                    <h5 class="fw-bold"><?php echo formatCurrency($total_share_capital); ?></h5>
                </div>
            </div>
        </div>

        <div class="row mt-3">
            <div class="col-md-6">
                <table class="table table-sm">
                    <tr>
                        <th>Full Shares Issued:</th>
                        <td><?php echo number_format($member['full_shares_issued'] ?? 0); ?></td>
                    </tr>
                    <tr>
                        <th>Partial Share Balance:</th>
                        <td><?php echo formatCurrency($member['partial_share_balance'] ?? 0); ?></td>
                    </tr>
                    <tr>
                        <th>Progress to Next Share:</th>
                        <td>
                            <div class="progress" style="height: 20px;">
                                <div class="progress-bar bg-success" role="progressbar"
                                    style="width: <?php echo $share_progress; ?>%;"
                                    aria-valuenow="<?php echo $share_progress; ?>"
                                    aria-valuemin="0"
                                    aria-valuemax="100">
                                    <?php echo number_format($share_progress, 1); ?>%
                                </div>
                            </div>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="col-md-6">
                <table class="table table-sm">
                    <tr>
                        <th>Shares Issued in <?php echo $year; ?>:</th>
                        <td><?php echo number_format($shares_issued_year['count']); ?></td>
                    </tr>
                    <tr>
                        <th>Share Value Issued in <?php echo $year; ?>:</th>
                        <td><?php echo formatCurrency($shares_issued_year['total']); ?></td>
                    </tr>
                    <tr>
                        <th>Contributions in <?php echo $year; ?>:</th>
                        <td><?php echo formatCurrency($contributions['contributions_this_year']); ?> (<?php echo $contributions['contributions_count_this_year']; ?> payments)</td>
                    </tr>
                </table>
            </div>
        </div>

        <?php if ($shares_issued->num_rows > 0): ?>
            <div class="mt-3">
                <h6>Share Certificates</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead>
                            <tr>
                                <th>Certificate No</th>
                                <th>Share Number</th>
                                <th>Issue Date</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $shares_issued->data_seek(0);
                            while ($cert = $shares_issued->fetch_assoc()):
                            ?>
                                <tr>
                                    <td><?php echo $cert['certificate_number']; ?></td>
                                    <td><?php echo $cert['share_number']; ?></td>
                                    <td><?php echo formatDate($cert['issue_date']); ?></td>
                                    <td class="text-end"><?php echo formatCurrency($cert['amount_paid']); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="stats-card success">
            <div class="stats-icon">
                <i class="fas fa-arrow-down"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($year_totals['total_deposits']); ?></h3>
                <p>Total Deposits <?php echo $year; ?></p>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card danger">
            <div class="stats-icon">
                <i class="fas fa-arrow-up"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($year_totals['total_withdrawals']); ?></h3>
                <p>Total Withdrawals <?php echo $year; ?></p>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card warning">
            <div class="stats-icon">
                <i class="fas fa-credit-card"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($loan_summary['total_payments']); ?></h3>
                <p>Loan Payments</p>
                <small><?php echo $loan_summary['payment_count']; ?> payments</small>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card info">
            <div class="stats-icon">
                <i class="fas fa-coins"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($contributions['contributions_this_year']); ?></h3>
                <p>Share Contributions</p>
                <small><?php echo $contributions['contributions_count_this_year']; ?> transactions</small>
            </div>
        </div>
    </div>
</div>

<!-- Monthly Breakdown Chart -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title">Monthly Activity - <?php echo $year; ?></h5>
    </div>
    <div class="card-body">
        <canvas id="monthlyChart" height="100"></canvas>
    </div>
</div>

<!-- Sacco Deposits Section -->
<div class="card mb-4">
    <div class="card-header bg-success text-white">
        <h5 class="card-title mb-0"><i class="fas fa-piggy-bank me-2"></i>Savings Account - <?php echo $year; ?></h5>
    </div>
    <div class="card-body">
        <div class="row mb-3">
            <div class="col-md-3">
                <div class="border p-3 text-center">
                    <small class="text-muted">Opening Balance</small>
                    <h5 class="text-primary"><?php echo formatCurrency($opening_balance); ?></h5>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border p-3 text-center">
                    <small class="text-muted">Total Deposits</small>
                    <h5 class="text-success">+ <?php echo formatCurrency($year_totals['total_deposits']); ?></h5>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border p-3 text-center">
                    <small class="text-muted">Total Withdrawals</small>
                    <h5 class="text-danger">- <?php echo formatCurrency($year_totals['total_withdrawals']); ?></h5>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border p-3 text-center bg-light">
                    <small class="text-muted">Closing Balance</small>
                    <h5 class="fw-bold"><?php echo formatCurrency($closing_balance); ?></h5>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-striped datatable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Description</th>
                        <th>Reference</th>
                        <th>Debit (Withdrawals)</th>
                        <th>Credit (Deposits)</th>
                        <th>Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $running_balance = $opening_balance;
                    $deposits->data_seek(0);
                    while ($trans = $deposits->fetch_assoc()):
                        if ($trans['transaction_type'] == 'deposit') {
                            $running_balance += $trans['amount'];
                            $credit = $trans['amount'];
                            $debit = 0;
                        } else {
                            $running_balance -= $trans['amount'];
                            $credit = 0;
                            $debit = $trans['amount'];
                        }
                    ?>
                        <tr>
                            <td><?php echo formatDate($trans['deposit_date']); ?></td>
                            <td><?php echo $trans['description'] ?: 'Savings transaction'; ?></td>
                            <td><?php echo $trans['reference_no'] ?: '-'; ?></td>
                            <td class="text-danger"><?php echo $debit > 0 ? formatCurrency($debit) : '-'; ?></td>
                            <td class="text-success"><?php echo $credit > 0 ? formatCurrency($credit) : '-'; ?></td>
                            <td class="text-primary fw-bold"><?php echo formatCurrency($running_balance); ?></td>
                        </tr>
                    <?php endwhile; ?>

                    <?php if ($deposits->num_rows == 0): ?>
                        <tr>
                            <td colspan="6" class="text-center py-3">
                                No savings transactions for <?php echo $year; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr class="table-info">
                        <th colspan="3" class="text-end">Totals:</th>
                        <th class="text-danger"><?php echo formatCurrency($year_totals['total_withdrawals']); ?></th>
                        <th class="text-success"><?php echo formatCurrency($year_totals['total_deposits']); ?></th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- Loan Payments Section -->
<?php if ($loan_payments->num_rows > 0): ?>
    <div class="card mb-4">
        <div class="card-header bg-warning">
            <h5 class="card-title mb-0"><i class="fas fa-hand-holding-usd me-2"></i>Loan Payments - <?php echo $year; ?></h5>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-4">
                    <div class="border p-3 text-center">
                        <small class="text-muted">Total Payments</small>
                        <h5 class="text-success"><?php echo formatCurrency($loan_summary['total_payments']); ?></h5>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border p-3 text-center">
                        <small class="text-muted">Principal Paid</small>
                        <h5><?php echo formatCurrency($loan_summary['total_principal_paid']); ?></h5>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border p-3 text-center">
                        <small class="text-muted">Interest Paid</small>
                        <h5><?php echo formatCurrency($loan_summary['total_interest_paid']); ?></h5>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Loan No</th>
                            <th>Description</th>
                            <th>Principal</th>
                            <th>Interest</th>
                            <th>Total</th>
                            <th>Reference</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($payment = $loan_payments->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo formatDate($payment['payment_date']); ?></td>
                                <td><span class="badge bg-info"><?php echo $payment['loan_no']; ?></span></td>
                                <td>Loan Repayment</td>
                                <td class="text-end"><?php echo formatCurrency($payment['principal_paid']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($payment['interest_paid']); ?></td>
                                <td class="text-end fw-bold"><?php echo formatCurrency($payment['amount_paid']); ?></td>
                                <td><?php echo $payment['reference_no'] ?: '-'; ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-info">
                            <th colspan="3" class="text-end">Totals:</th>
                            <th class="text-end"><?php echo formatCurrency($loan_summary['total_principal_paid']); ?></th>
                            <th class="text-end"><?php echo formatCurrency($loan_summary['total_interest_paid']); ?></th>
                            <th class="text-end"><?php echo formatCurrency($loan_summary['total_payments']); ?></th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Year End Summary -->
<div class="card">
    <div class="card-header bg-secondary text-white">
        <h5 class="card-title mb-0"><i class="fas fa-chart-line me-2"></i>Year End Summary - <?php echo $year; ?></h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <h6>Savings Account</h6>
                <table class="table table-sm">
                    <tr>
                        <th>Balance Brought Forward (01/01/<?php echo $year; ?>):</th>
                        <td class="text-end"><?php echo formatCurrency($opening_balance); ?></td>
                    </tr>
                    <tr>
                        <th>Total Deposits:</th>
                        <td class="text-end text-success">+ <?php echo formatCurrency($year_totals['total_deposits']); ?></td>
                    </tr>
                    <tr>
                        <th>Total Withdrawals:</th>
                        <td class="text-end text-danger">- <?php echo formatCurrency($year_totals['total_withdrawals']); ?></td>
                    </tr>
                    <tr class="table-primary">
                        <th>Closing Balance (31/12/<?php echo $year; ?>):</th>
                        <td class="text-end fw-bold"><?php echo formatCurrency($closing_balance); ?></td>
                    </tr>
                </table>
            </div>

            <div class="col-md-6">
                <h6>Share Capital</h6>
                <table class="table table-sm">
                    <tr>
                        <th>Total Shares (All Time):</th>
                        <td class="text-end"><?php echo number_format($share_summary['total_shares']); ?></td>
                    </tr>
                    <tr>
                        <th>Total Share Value:</th>
                        <td class="text-end"><?php echo formatCurrency($share_summary['total_share_value']); ?></td>
                    </tr>
                    <tr>
                        <th>Total Contributions:</th>
                        <td class="text-end"><?php echo formatCurrency($contributions['total_contributions']); ?></td>
                    </tr>
                    <tr>
                        <th>Shares Issued in <?php echo $year; ?>:</th>
                        <td class="text-end"><?php echo number_format($shares_issued_year['count']); ?> (<?php echo formatCurrency($shares_issued_year['total']); ?>)</td>
                    </tr>
                    <tr>
                        <th>Contributions in <?php echo $year; ?>:</th>
                        <td class="text-end"><?php echo formatCurrency($contributions['contributions_this_year']); ?></td>
                    </tr>
                    <tr class="table-success">
                        <th>Total Share Capital:</th>
                        <td class="text-end fw-bold"><?php echo formatCurrency($total_share_capital); ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="row mt-3">
            <div class="col-md-12">
                <h6>Loan Summary</h6>
                <table class="table table-sm">
                    <tr>
                        <th>Total Loan Payments in <?php echo $year; ?>:</th>
                        <td class="text-end"><?php echo formatCurrency($loan_summary['total_payments']); ?></td>
                    </tr>
                    <tr>
                        <th>Principal Paid:</th>
                        <td class="text-end"><?php echo formatCurrency($loan_summary['total_principal_paid']); ?></td>
                    </tr>
                    <tr>
                        <th>Interest Paid:</th>
                        <td class="text-end"><?php echo formatCurrency($loan_summary['total_interest_paid']); ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    <div class="card-footer text-muted">
        <small>
            <i class="fas fa-info-circle me-1"></i>
            Statement for year <?php echo $year; ?>. Balance brought forward from <?php echo $year - 1; ?>.
        </small>
    </div>
</div>

<script>
    // Monthly Chart
    document.addEventListener('DOMContentLoaded', function() {
        var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        var depositsData = [];
        var withdrawalsData = [];

        <?php for ($m = 1; $m <= 12; $m++): ?>
            <?php if (isset($monthly_data[$m])): ?>
                depositsData.push(<?php echo $monthly_data[$m]['monthly_deposits'] / 1000; ?>);
                withdrawalsData.push(<?php echo $monthly_data[$m]['monthly_withdrawals'] / 1000; ?>);
            <?php else: ?>
                depositsData.push(0);
                withdrawalsData.push(0);
            <?php endif; ?>
        <?php endfor; ?>

        var ctx = document.getElementById('monthlyChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: months,
                datasets: [{
                    label: 'Deposits (KES Thousands)',
                    data: depositsData,
                    backgroundColor: 'rgba(40, 167, 69, 0.5)',
                    borderColor: 'rgb(40, 167, 69)',
                    borderWidth: 1
                }, {
                    label: 'Withdrawals (KES Thousands)',
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
                        title: {
                            display: true,
                            text: 'Amount (KES Thousands)'
                        }
                    }
                }
            }
        });
    });

    function exportStatement() {
        var memberId = <?php echo $member_id; ?>;
        var year = '<?php echo $year; ?>';
        window.location.href = 'export-statement.php?id=' + memberId + '&year=' + year;
    }

    // Add this to your existing JavaScript section
    function printStatement() {
        var memberId = <?php echo $member_id; ?>;
        var year = '<?php echo $year; ?>';
        var month = '<?php echo $month; ?>';

        // Open print-friendly version in new window
        var printWindow = window.open('statement_print.php?id=' + memberId + '&year=' + year + '&month=' + month, '_blank');

        // Optional: Auto-trigger print dialog when page loads
        printWindow.onload = function() {
            printWindow.print();
        };
    }
</script>

<style>
    .stats-card {
        margin-bottom: 15px;
    }

    .border {
        border: 1px solid #dee2e6 !important;
        border-radius: 5px;
    }

    .table td,
    .table th {
        vertical-align: middle;
    }

    .card-header {
        font-weight: 600;
    }

    .progress {
        height: 20px;
        border-radius: 10px;
    }

    .progress-bar {
        border-radius: 10px;
        font-size: 11px;
        line-height: 20px;
    }

    @media print {

        .sidebar,
        .navbar,
        .breadcrumb,
        .page-header .col-auto,
        .card-header .btn,
        .footer,
        .btn,
        .no-print {
            display: none !important;
        }

        .main-content {
            margin-left: 0 !important;
            padding: 10px !important;
        }

        .card {
            box-shadow: none !important;
            border: 1px solid #ddd !important;
            break-inside: avoid;
        }
    }
</style>

<?php include '../../includes/footer.php'; ?>
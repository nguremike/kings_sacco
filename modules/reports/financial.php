<?php
require_once '../../config/config.php';
requireLogin();

$page_title = 'Financial Reports';

// Get date range
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

// Get summary data
$summary = [];

// Total deposits
$deposits = executeQuery("
    SELECT 
        SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE 0 END) as total_deposits,
        SUM(CASE WHEN transaction_type = 'withdrawal' THEN amount ELSE 0 END) as total_withdrawals,
        COUNT(DISTINCT member_id) as depositors
    FROM deposits
    WHERE deposit_date BETWEEN ? AND ?
", "ss", [$start_date, $end_date])->fetch_assoc();

// Loan disbursements
$disbursements = executeQuery("
    SELECT 
        SUM(principal_amount) as total_disbursed,
        COUNT(*) as loan_count
    FROM loans
    WHERE disbursement_date BETWEEN ? AND ?
", "ss", [$start_date, $end_date])->fetch_assoc();

// Loan repayments
$repayments = executeQuery("
    SELECT 
        SUM(amount_paid) as total_repaid,
        COUNT(*) as payment_count
    FROM loan_repayments
    WHERE payment_date BETWEEN ? AND ?
", "ss", [$start_date, $end_date])->fetch_assoc();

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Financial Reports</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="../reports/index.php">Reports</a></li>
                <li class="breadcrumb-item active">Financial</li>
            </ul>
        </div>
        <div class="col-auto">
            <button class="btn btn-success" onclick="window.print()">
                <i class="fas fa-print me-2"></i>Print
            </button>
            <button class="btn btn-primary" onclick="exportReport()">
                <i class="fas fa-file-excel me-2"></i>Export
            </button>
        </div>
    </div>
</div>

<!-- Date Filter -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-4">
                <label for="start_date" class="form-label">Start Date</label>
                <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $start_date; ?>">
            </div>
            <div class="col-md-4">
                <label for="end_date" class="form-label">End Date</label>
                <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $end_date; ?>">
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

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="stats-card success">
            <div class="stats-icon">
                <i class="fas fa-arrow-down"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($deposits['total_deposits'] ?? 0); ?></h3>
                <p>Total Deposits</p>
                <small><?php echo number_format($deposits['depositors'] ?? 0); ?> depositors</small>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card danger">
            <div class="stats-icon">
                <i class="fas fa-arrow-up"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($deposits['total_withdrawals'] ?? 0); ?></h3>
                <p>Total Withdrawals</p>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card primary">
            <div class="stats-icon">
                <i class="fas fa-hand-holding-usd"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($disbursements['total_disbursed'] ?? 0); ?></h3>
                <p>Loan Disbursements</p>
                <small><?php echo number_format($disbursements['loan_count'] ?? 0); ?> loans</small>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card warning">
            <div class="stats-icon">
                <i class="fas fa-credit-card"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($repayments['total_repaid'] ?? 0); ?></h3>
                <p>Loan Repayments</p>
                <small><?php echo number_format($repayments['payment_count'] ?? 0); ?> payments</small>
            </div>
        </div>
    </div>
</div>

<!-- Income Statement -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title">Income Statement</h5>
        <p class="text-muted">For the period <?php echo formatDate($start_date); ?> to <?php echo formatDate($end_date); ?></p>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <h6>Income</h6>
                <table class="table table-sm">
                    <?php
                    // Interest income
                    $interest_income = executeQuery("
                        SELECT SUM(interest_paid) as total FROM loan_repayments 
                        WHERE payment_date BETWEEN ? AND ?
                    ", "ss", [$start_date, $end_date])->fetch_assoc();
                    ?>
                    <tr>
                        <td>Interest on Loans</td>
                        <td class="text-end"><?php echo formatCurrency($interest_income['total'] ?? 0); ?></td>
                    </tr>

                    <?php
                    // Fees income
                    $fees_income = 0; // Calculate fees
                    ?>
                    <tr>
                        <td>Application Fees</td>
                        <td class="text-end"><?php echo formatCurrency($fees_income); ?></td>
                    </tr>

                    <tr class="table-info">
                        <td><strong>Total Income</strong></td>
                        <td class="text-end"><strong><?php echo formatCurrency($interest_income['total'] ?? 0); ?></strong></td>
                    </tr>
                </table>
            </div>

            <div class="col-md-6">
                <h6>Expenses</h6>
                <table class="table table-sm">
                    <?php
                    // Operating expenses
                    $expenses = 0; // Calculate from transactions
                    ?>
                    <tr>
                        <td>Operating Expenses</td>
                        <td class="text-end"><?php echo formatCurrency($expenses); ?></td>
                    </tr>

                    <tr>
                        <td>Dividends Paid</td>
                        <td class="text-end">
                            <?php
                            $dividends_paid = executeQuery("
                                SELECT SUM(net_dividend) as total FROM dividends 
                                WHERE payment_date BETWEEN ? AND ?
                            ", "ss", [$start_date, $end_date])->fetch_assoc();
                            echo formatCurrency($dividends_paid['total'] ?? 0);
                            ?>
                        </td>
                    </tr>

                    <tr class="table-info">
                        <td><strong>Total Expenses</strong></td>
                        <td class="text-end"><strong><?php echo formatCurrency($expenses + ($dividends_paid['total'] ?? 0)); ?></strong></td>
                    </tr>
                </table>
            </div>
        </div>

        <hr>

        <div class="row">
            <div class="col-md-12">
                <h4 class="text-end">
                    Net Income:
                    <?php
                    $net_income = ($interest_income['total'] ?? 0) - ($expenses + ($dividends_paid['total'] ?? 0));
                    ?>
                    <span class="<?php echo $net_income >= 0 ? 'text-success' : 'text-danger'; ?>">
                        <?php echo formatCurrency($net_income); ?>
                    </span>
                </h4>
            </div>
        </div>
    </div>
</div>

<!-- Balance Sheet -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title">Balance Sheet</h5>
        <p class="text-muted">As at <?php echo formatDate($end_date); ?></p>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <h6>Assets</h6>
                <table class="table table-sm">
                    <?php
                    // Cash and bank balances
                    $cash = executeQuery("
                        SELECT SUM(amount) as total FROM deposits 
                        WHERE transaction_type = 'deposit'
                    ")->fetch_assoc();

                    // Loans receivable
                    $loans_receivable = executeQuery("
                        SELECT SUM(balance) as total FROM loans 
                        WHERE status IN ('disbursed', 'active')
                    ")->fetch_assoc();
                    ?>
                    <tr>
                        <td>Cash and Bank Balances</td>
                        <td class="text-end"><?php echo formatCurrency($cash['total'] ?? 0); ?></td>
                    </tr>
                    <tr>
                        <td>Loans Receivable</td>
                        <td class="text-end"><?php echo formatCurrency($loans_receivable['total'] ?? 0); ?></td>
                    </tr>
                    <tr>
                        <td>Accounts Receivable</td>
                        <td class="text-end"><?php echo formatCurrency(0); ?></td>
                    </tr>
                    <tr class="table-info">
                        <td><strong>Total Assets</strong></td>
                        <td class="text-end"><strong><?php echo formatCurrency(($cash['total'] ?? 0) + ($loans_receivable['total'] ?? 0)); ?></strong></td>
                    </tr>
                </table>
            </div>

            <div class="col-md-6">
                <h6>Liabilities and Equity</h6>
                <table class="table table-sm">
                    <?php
                    // Member deposits (liability)
                    $member_deposits = executeQuery("
                        SELECT SUM(balance) as total FROM deposits 
                        WHERE transaction_type = 'deposit'
                    ")->fetch_assoc();

                    // Share capital
                    $share_capital = executeQuery("
                        SELECT SUM(total_value) as total FROM shares
                    ")->fetch_assoc();

                    // Retained earnings
                    $retained_earnings = 0; // Calculate
                    ?>
                    <tr>
                        <td>Member Deposits</td>
                        <td class="text-end"><?php echo formatCurrency($member_deposits['total'] ?? 0); ?></td>
                    </tr>
                    <tr>
                        <td>Share Capital</td>
                        <td class="text-end"><?php echo formatCurrency($share_capital['total'] ?? 0); ?></td>
                    </tr>
                    <tr>
                        <td>Retained Earnings</td>
                        <td class="text-end"><?php echo formatCurrency($retained_earnings); ?></td>
                    </tr>
                    <tr class="table-info">
                        <td><strong>Total Liabilities & Equity</strong></td>
                        <td class="text-end"><strong><?php echo formatCurrency(($member_deposits['total'] ?? 0) + ($share_capital['total'] ?? 0) + $retained_earnings); ?></strong></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    function exportReport() {
        window.location.href = 'export-financial.php?start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>';
    }
</script>

<?php include '../../includes/footer.php'; ?>
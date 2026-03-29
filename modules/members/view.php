<?php
require_once '../../config/config.php';
requireLogin();

$id = $_GET['id'] ?? 0;

// Get member details
$sql = "SELECT * FROM members WHERE id = ?";
$result = executeQuery($sql, "i", [$id]);

if ($result->num_rows == 0) {
    $_SESSION['error'] = 'Member not found';
    header('Location: index.php');
    exit();
}

$member = $result->fetch_assoc();
$page_title = 'Member Profile - ' . $member['full_name'];

// Get member statistics
$stats = [];

// Total deposits
$depositResult = executeQuery("SELECT COALESCE(SUM(amount), 0) as total FROM deposits WHERE member_id = ? AND transaction_type = 'deposit'", "i", [$id]);
$stats['total_deposits'] = $depositResult->fetch_assoc()['total'] ?? 0;

// Total withdrawals
$withdrawResult = executeQuery("SELECT COALESCE(SUM(amount), 0) as total FROM deposits WHERE member_id = ? AND transaction_type = 'withdrawal'", "i", [$id]);
$stats['total_withdrawals'] = $withdrawResult->fetch_assoc()['total'] ?? 0;

// Current balance (deposits minus withdrawals)
$stats['current_balance'] = $stats['total_deposits'] - $stats['total_withdrawals'];

// Total shares
$sharesResult = executeQuery("SELECT COALESCE(SUM(shares_count), 0) as total FROM shares WHERE member_id = ?", "i", [$id]);
$stats['total_shares'] = $sharesResult->fetch_assoc()['total'] ?? 0;

// Total share value
$shareValueResult = executeQuery("SELECT COALESCE(SUM(total_value), 0) as total FROM shares WHERE member_id = ?", "i", [$id]);
$stats['total_share_value'] = $shareValueResult->fetch_assoc()['total'] ?? 0;

// Active loans count
$loansResult = executeQuery("SELECT COUNT(*) as total FROM loans WHERE member_id = ? AND status IN ('active', 'disbursed')", "i", [$id]);
$stats['active_loans'] = $loansResult->fetch_assoc()['total'] ?? 0;

// Total loan amount (principal)
$loanAmountResult = executeQuery("SELECT COALESCE(SUM(principal_amount), 0) as total FROM loans WHERE member_id = ? AND status IN ('active', 'disbursed')", "i", [$id]);
$stats['total_loan_amount'] = $loanAmountResult->fetch_assoc()['total'] ?? 0;

// Calculate loan balance (total loan minus repayments)
$repaymentsResult = executeQuery("SELECT COALESCE(SUM(amount_paid), 0) as total FROM loan_repayments WHERE loan_id IN (SELECT id FROM loans WHERE member_id = ?)", "i", [$id]);
$total_repayments = $repaymentsResult->fetch_assoc()['total'] ?? 0;
$stats['loan_balance'] = $stats['total_loan_amount'] - $total_repayments;

// Get detailed withdrawal data for share progress
$withdrawals_detail = executeQuery("SELECT amount, deposit_date, description, reference_no 
                                   FROM deposits 
                                   WHERE member_id = ? AND transaction_type = 'withdrawal'
                                   ORDER BY deposit_date DESC 
                                   LIMIT 10", "i", [$id]);

// Get share contributions with withdrawal impact
$share_contributions = executeQuery("SELECT amount, contribution_date, reference_no, notes 
                                    FROM share_contributions 
                                    WHERE member_id = ? 
                                    ORDER BY contribution_date DESC 
                                    LIMIT 10", "i", [$id]);

// Get all loans for this member
$loans_sql = "SELECT l.*, lp.product_name 
              FROM loans l 
              LEFT JOIN loan_products lp ON l.product_id = lp.id 
              WHERE l.member_id = ? 
              ORDER BY l.created_at DESC";
$loans = executeQuery($loans_sql, "i", [$id]);

// Get recent transactions (deposits and loan repayments)
$transactions = executeQuery("
    (SELECT 
        'deposit' as type, 
        deposit_date as trans_date, 
        amount, 
        description,
        reference_no
    FROM deposits 
    WHERE member_id = ?)
    UNION ALL
    (SELECT 
        'repayment' as type, 
        payment_date as trans_date, 
        amount_paid as amount, 
        'Loan Repayment' as description,
        reference_no
    FROM loan_repayments 
    WHERE loan_id IN (SELECT id FROM loans WHERE member_id = ?))
    ORDER BY trans_date DESC 
    LIMIT 20
", "ii", [$id, $id]);

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Member Profile</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Members</a></li>
                <li class="breadcrumb-item active">Profile</li>
            </ul>
        </div>
        <div class="col-auto">
            <div class="btn-group">
                <a href="edit.php?id=<?php echo $id; ?>" class="btn btn-primary">
                    <i class="fas fa-edit me-2"></i>Edit Member
                </a>
                <a href="statement.php?id=<?php echo $id; ?>" class="btn btn-success">
                    <i class="fas fa-file-pdf me-2"></i>Statement
                </a>
                <a href="complete-statement.php?id=<?php echo $id; ?>" class="btn btn-info">
                    <i class="fas fa-file-invoice-dollar me-2"></i>Complete Statement
                </a>
                <button type="button" class="btn btn-secondary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown">
                    <span class="visually-hidden">Toggle Dropdown</span>
                </button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="../deposits/add.php?member_id=<?php echo $id; ?>"><i class="fas fa-plus me-2"></i>Add Deposit</a></li>
                    <li><a class="dropdown-item" href="../deposits/withdrawals.php?member_id=<?php echo $id; ?>"><i class="fas fa-hand-holding-usd me-2"></i>Process Withdrawal</a></li>
                    <li><a class="dropdown-item" href="../shares/contributions.php?member_id=<?php echo $id; ?>"><i class="fas fa-chart-pie me-2"></i>Add Share Contribution</a></li>
                    <li><a class="dropdown-item" href="../loans/apply.php?member_id=<?php echo $id; ?>"><i class="fas fa-hand-holding-usd me-2"></i>Apply Loan</a></li>
                    <li>
                        <hr class="dropdown-divider">
                    </li>
                    <li><a class="dropdown-item text-danger" href="#" onclick="confirmDelete(<?php echo $id; ?>)"><i class="fas fa-trash me-2"></i>Delete Member</a></li>
                </ul>
            </div>
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

<!-- Member Profile -->
<div class="row">
    <!-- Profile Card -->
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <div class="profile-image mb-3">
                    <?php if ($member['photo'] ?? false): ?>
                        <img src="../../uploads/members/<?php echo $member['photo']; ?>" class="rounded-circle img-fluid" style="width: 150px; height: 150px; object-fit: cover;" alt="Profile">
                    <?php else: ?>
                        <i class="fas fa-user-circle fa-6x text-primary"></i>
                    <?php endif; ?>
                </div>
                <h4><?php echo $member['full_name']; ?></h4>
                <p class="text-muted">
                    <span class="badge bg-primary"><?php echo $member['member_no']; ?></span>
                </p>
                <p>
                    <?php
                    $status_colors = [
                        'active' => 'success',
                        'pending' => 'warning',
                        'suspended' => 'danger',
                        'closed' => 'secondary'
                    ];
                    $status_color = $status_colors[$member['membership_status']] ?? 'secondary';
                    ?>
                    <span class="badge bg-<?php echo $status_color; ?> fs-6">
                        <?php echo ucfirst($member['membership_status']); ?>
                    </span>
                </p>

                <hr>

                <div class="text-start">
                    <p><i class="fas fa-id-card me-2 text-primary"></i> <?php echo $member['national_id']; ?></p>
                    <p><i class="fas fa-phone me-2 text-primary"></i> <?php echo $member['phone']; ?></p>
                    <p><i class="fas fa-envelope me-2 text-primary"></i> <?php echo $member['email'] ?: 'N/A'; ?></p>
                    <p><i class="fas fa-map-marker me-2 text-primary"></i> <?php echo $member['address'] ?: 'N/A'; ?></p>
                    <p><i class="fas fa-calendar me-2 text-primary"></i> Joined: <?php echo formatDate($member['date_joined']); ?></p>
                </div>
            </div>
        </div>

        <!-- Quick Actions Card -->
        <div class="card mt-4">
            <div class="card-header bg-secondary text-white">
                <h5 class="card-title mb-0"><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="../deposits/add.php?member_id=<?php echo $id; ?>" class="btn btn-outline-success">
                        <i class="fas fa-plus-circle me-2"></i>Add Deposit
                    </a>
                    <a href="../deposits/withdrawals.php?member_id=<?php echo $id; ?>" class="btn btn-outline-danger">
                        <i class="fas fa-hand-holding-usd me-2"></i>Process Withdrawal
                    </a>
                    <a href="../shares/contributions.php?member_id=<?php echo $id; ?>" class="btn btn-outline-primary">
                        <i class="fas fa-coins me-2"></i>Add Share Contribution
                    </a>
                    <a href="../loans/apply.php?member_id=<?php echo $id; ?>" class="btn btn-outline-warning">
                        <i class="fas fa-file-signature me-2"></i>Apply for Loan
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics and Details -->
    <div class="col-md-8">
        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-6 mb-3">
                <div class="stats-card success">
                    <div class="stats-icon">
                        <i class="fas fa-piggy-bank"></i>
                    </div>
                    <div class="stats-content">
                        <h3><?php echo formatCurrency($stats['current_balance']); ?></h3>
                        <p>Current Balance</p>
                        <small>Deposits: <?php echo formatCurrency($stats['total_deposits']); ?></small><br>
                        <small class="text-danger">Withdrawals: <?php echo formatCurrency($stats['total_withdrawals']); ?></small>
                    </div>
                </div>
            </div>

            <div class="col-md-6 mb-3">
                <div class="stats-card primary">
                    <div class="stats-icon">
                        <i class="fas fa-chart-pie"></i>
                    </div>
                    <div class="stats-content">
                        <h3><?php echo number_format($stats['total_shares']); ?></h3>
                        <p>Total Shares</p>
                        <small>Value: <?php echo formatCurrency($stats['total_share_value']); ?></small>
                    </div>
                </div>
            </div>

            <div class="col-md-6 mb-3">
                <div class="stats-card warning">
                    <div class="stats-icon">
                        <i class="fas fa-hand-holding-usd"></i>
                    </div>
                    <div class="stats-content">
                        <h3><?php echo number_format($stats['active_loans']); ?></h3>
                        <p>Active Loans</p>
                        <small>Total: <?php echo formatCurrency($stats['total_loan_amount']); ?></small>
                    </div>
                </div>
            </div>

            <div class="col-md-6 mb-3">
                <div class="stats-card danger">
                    <div class="stats-icon">
                        <i class="fas fa-credit-card"></i>
                    </div>
                    <div class="stats-content">
                        <h3><?php echo formatCurrency($stats['loan_balance']); ?></h3>
                        <p>Outstanding Loan Balance</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Enhanced Share Progress Card with Withdrawals -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="card-title mb-0"><i class="fas fa-chart-pie me-2"></i>Share Capital Progress</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Share Value: KES 10,000 per share</h6>
                        <div class="mb-3">
                            <label class="form-label text-muted">Total Share Contributions</label>
                            <h4 class="text-primary"><?php echo formatCurrency($member['total_share_contributions'] ?? 0); ?></h4>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-muted">Full Shares Issued</label>
                            <h4><?php echo number_format($member['full_shares_issued'] ?? 0); ?></h4>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-muted">Partial Share Balance</label>
                            <h4 class="text-warning"><?php echo formatCurrency($member['partial_share_balance'] ?? 0); ?></h4>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <h6>Progress to Next Share</h6>
                        <?php
                        $partial = $member['partial_share_balance'] ?? 0;
                        $percentage = ($partial / 10000) * 100;
                        $needed = 10000 - $partial;
                        ?>
                        <div class="progress mb-3" style="height: 30px;">
                            <div class="progress-bar progress-bar-striped bg-success"
                                role="progressbar"
                                style="width: <?php echo $percentage; ?>%;"
                                aria-valuenow="<?php echo $percentage; ?>"
                                aria-valuemin="0"
                                aria-valuemax="100">
                                <?php echo number_format($percentage, 1); ?>%
                            </div>
                        </div>

                        <p class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            Need KES <?php echo number_format($needed, 2); ?> more for next share
                        </p>

                        <div class="alert alert-info mt-2">
                            <i class="fas fa-chart-line me-2"></i>
                            <strong>Share Capital Status:</strong>
                            <?php if ($partial >= 10000): ?>
                                <span class="text-success">✅ Ready for new share!</span>
                            <?php elseif ($partial > 0): ?>
                                <span class="text-warning">⏳ In progress</span>
                            <?php else: ?>
                                <span class="text-secondary">📝 Not started</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- NEW: Withdrawal History Card -->
        <div class="card mb-4">
            <div class="card-header bg-danger text-white">
                <h5 class="card-title mb-0"><i class="fas fa-arrow-up me-2"></i>Withdrawal History (Affects Share Capital)</h5>
            </div>
            <div class="card-body">
                <?php if ($withdrawals_detail->num_rows > 0): ?>
                    <div class="alert alert-warning mb-3">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Note:</strong> Withdrawals reduce your available funds for share contributions.
                        Each withdrawal decreases your ability to build share capital.
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Description</th>
                                    <th>Reference</th>
                                    <th>Impact on Share Capital</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $total_withdrawn_for_shares = 0;
                                while ($wd = $withdrawals_detail->fetch_assoc()):
                                    $impact = "Reduces available funds by " . formatCurrency($wd['amount']);
                                    $total_withdrawn_for_shares += $wd['amount'];
                                ?>
                                    <tr class="table-danger">
                                        <td><?php echo formatDate($wd['deposit_date']); ?></td>
                                        <td class="text-danger fw-bold">- <?php echo formatCurrency($wd['amount']); ?></td>
                                        <td><?php echo $wd['description'] ?: 'Savings withdrawal'; ?></td>
                                        <td><?php echo $wd['reference_no'] ?: '-'; ?></td>
                                        <td><small class="text-warning"><?php echo $impact; ?></small></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-warning">
                                    <th colspan="4" class="text-end">Total Withdrawals Affecting Share Capital:</th>
                                    <th class="text-danger"><?php echo formatCurrency($total_withdrawn_for_shares); ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-3">
                        <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                        <p class="text-muted">No withdrawal records found. This member's share capital is intact.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Share Contributions History -->
        <div class="card mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="card-title mb-0"><i class="fas fa-coins me-2"></i>Share Contribution History</h5>
            </div>
            <div class="card-body">
                <?php if ($share_contributions->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Reference</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $total_contributions = 0;
                                while ($sc = $share_contributions->fetch_assoc()):
                                    $total_contributions += $sc['amount'];
                                ?>
                                    <tr class="table-success">
                                        <td><?php echo formatDate($sc['contribution_date']); ?></td>
                                        <td class="text-success fw-bold">+ <?php echo formatCurrency($sc['amount']); ?></td>
                                        <td><?php echo $sc['reference_no'] ?: '-'; ?></td>
                                        <td><?php echo $sc['notes'] ?: 'Share contribution'; ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-success">
                                    <th colspan="3" class="text-end">Total Share Contributions:</th>
                                    <th class="text-success"><?php echo formatCurrency($total_contributions); ?></th>
                                </tr>
                                <tr class="table-info">
                                    <th colspan="3" class="text-end">Net Share Capital (Contributions - Withdrawals):</th>
                                    <th class="<?php echo ($total_contributions - $total_withdrawn_for_shares) >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo formatCurrency($total_contributions - $total_withdrawn_for_shares); ?>
                                    </th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-3">
                        <i class="fas fa-info-circle fa-2x text-muted mb-2"></i>
                        <p class="text-muted">No share contributions recorded yet.</p>
                        <a href="../shares/contributions.php?member_id=<?php echo $id; ?>" class="btn btn-sm btn-primary">
                            <i class="fas fa-plus me-2"></i>Add First Contribution
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Share Progress Impact Analysis -->
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="card-title mb-0"><i class="fas fa-chart-line me-2"></i>Share Capital Impact Analysis</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="border p-3 text-center">
                            <small class="text-muted">Total Share Contributions</small>
                            <h4 class="text-success"><?php echo formatCurrency($total_contributions ?? 0); ?></h4>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border p-3 text-center">
                            <small class="text-muted">Total Withdrawals</small>
                            <h4 class="text-danger"><?php echo formatCurrency($total_withdrawn_for_shares ?? 0); ?></h4>
                        </div>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-md-12">
                        <div class="alert alert-<?php echo ($total_contributions ?? 0) > ($total_withdrawn_for_shares ?? 0) ? 'success' : 'danger'; ?>">
                            <i class="fas fa-<?php echo ($total_contributions ?? 0) > ($total_withdrawn_for_shares ?? 0) ? 'arrow-up' : 'arrow-down'; ?> me-2"></i>
                            <strong>Net Share Capital Change:</strong>
                            <?php
                            $net_change = ($total_contributions ?? 0) - ($total_withdrawn_for_shares ?? 0);
                            $change_class = $net_change >= 0 ? 'text-success' : 'text-danger';
                            ?>
                            <span class="<?php echo $change_class; ?> fw-bold">
                                <?php echo $net_change >= 0 ? '+' : ''; ?><?php echo formatCurrency($net_change); ?>
                            </span>
                            <br>
                            <small class="text-muted">
                                <?php if ($net_change > 0): ?>
                                    Member is actively building share capital.
                                <?php elseif ($net_change < 0): ?>
                                    Withdrawals exceed contributions. Consider reducing withdrawals to build share capital.
                                <?php else: ?>
                                    No net change in share capital.
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Loans Section -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title">Loans</h5>
                <div class="card-tools">
                    <a href="../loans/apply.php?member_id=<?php echo $id; ?>" class="btn btn-sm btn-primary">
                        <i class="fas fa-plus"></i> New Loan
                    </a>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Loan No</th>
                                <th>Product</th>
                                <th>Principal</th>
                                <th>Interest</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($loans->num_rows > 0): ?>
                                <?php while ($loan = $loans->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-info"><?php echo $loan['loan_no']; ?></span>
                                        </td>
                                        <td><?php echo $loan['product_name'] ?? 'N/A'; ?></td>
                                        <td><?php echo formatCurrency($loan['principal_amount']); ?></td>
                                        <td><?php echo formatCurrency($loan['interest_amount']); ?></td>
                                        <td><?php echo formatCurrency($loan['total_amount']); ?></td>
                                        <td>
                                            <?php
                                            $status_class = [
                                                'pending' => 'warning',
                                                'approved' => 'primary',
                                                'disbursed' => 'info',
                                                'active' => 'success',
                                                'completed' => 'secondary',
                                                'defaulted' => 'danger',
                                                'rejected' => 'dark'
                                            ][$loan['status']] ?? 'secondary';
                                            ?>
                                            <span class="badge bg-<?php echo $status_class; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $loan['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="../loans/view.php?id=<?php echo $loan['id']; ?>" class="btn btn-sm btn-outline-info">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center">No loans found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Recent Transactions -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Recent Transactions</h5>
                <div class="card-tools">
                    <a href="statement.php?id=<?php echo $id; ?>" class="btn btn-sm btn-success">
                        <i class="fas fa-file-pdf"></i> Full Statement
                    </a>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Description</th>
                                <th>Reference</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($transactions->num_rows > 0): ?>
                                <?php while ($trans = $transactions->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo formatDate($trans['trans_date']); ?></td>
                                        <td>
                                            <?php if ($trans['type'] == 'deposit'): ?>
                                                <span class="badge bg-success">Deposit</span>
                                            <?php else: ?>
                                                <span class="badge bg-info">Repayment</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $trans['description'] ?: 'N/A'; ?></td>
                                        <td><?php echo $trans['reference_no'] ?: '-'; ?></td>
                                        <td class="<?php echo $trans['type'] == 'deposit' ? 'text-success' : 'text-info'; ?>">
                                            <strong>
                                                <?php echo $trans['type'] == 'deposit' ? '+' : '-'; ?>
                                                <?php echo formatCurrency($trans['amount']); ?>
                                            </strong>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center">No transactions found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function confirmDelete(id) {
        Swal.fire({
            title: 'Are you sure?',
            text: "You are about to delete this member. This action cannot be undone!",
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

    // Tooltip initialization
    document.addEventListener('DOMContentLoaded', function() {
        var tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        tooltips.forEach(function(tooltip) {
            new bootstrap.Tooltip(tooltip);
        });
    });
</script>

<style>
    .stats-card {
        margin-bottom: 15px;
        cursor: default;
        transition: transform 0.2s;
    }

    .stats-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
    }

    .table td,
    .table th {
        vertical-align: middle;
    }

    .progress {
        background-color: #e9ecef;
        border-radius: 10px;
    }

    .progress-bar {
        border-radius: 10px;
        font-size: 12px;
        line-height: 30px;
    }

    .border {
        border: 1px solid #dee2e6 !important;
        border-radius: 8px;
    }

    .btn-group .btn {
        margin-right: 2px;
    }

    @media (max-width: 768px) {
        .btn-group {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .btn-group .btn {
            margin-right: 0;
            border-radius: 4px !important;
        }

        .stats-card {
            margin-bottom: 10px;
        }
    }
</style>

<?php include '../../includes/footer.php'; ?>
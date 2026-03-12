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
                <button type="button" class="btn btn-info dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown">
                    <span class="visually-hidden">Toggle Dropdown</span>
                </button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="../deposits/add.php?member_id=<?php echo $id; ?>"><i class="fas fa-plus me-2"></i>Add Deposit</a></li>
                    <li><a class="dropdown-item" href="../shares/add.php?member_id=<?php echo $id; ?>"><i class="fas fa-chart-pie me-2"></i>Purchase Shares</a></li>
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
                        <small>Deposits: <?php echo formatCurrency($stats['total_deposits']); ?></small>
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

        <!-- Share Progress Card -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title">Share Progress</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Share Value: KES 10,000 per share</h6>
                        <div class="mb-3">
                            <label class="form-label">Total Contributions</label>
                            <h4><?php echo formatCurrency($member['total_share_contributions'] ?? 0); ?></h4>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Full Shares Issued</label>
                            <h4><?php echo $member['full_shares_issued'] ?? 0; ?></h4>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Partial Balance</label>
                            <h4><?php echo formatCurrency($member['partial_share_balance'] ?? 0); ?></h4>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <h6>Progress to Next Share</h6>
                        <?php
                        $partial = $member['partial_share_balance'] ?? 0;
                        $percentage = ($partial / 10000) * 100;
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
                            Need KES <?php echo number_format(10000 - $partial, 2); ?> more for next share
                        </p>

                        <a href="../shares/contributions.php?member_id=<?php echo $id; ?>" class="btn btn-sm btn-primary">
                            <i class="fas fa-plus me-2"></i>Add Contribution
                        </a>
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
</script>

<?php include '../../includes/footer.php'; ?>
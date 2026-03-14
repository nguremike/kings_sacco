<?php
require_once '../../config/config.php';
requireLogin();

$loan_id = $_GET['id'] ?? 0;

// Get loan details with related information
$loan_sql = "SELECT l.*, 
             m.id as member_id, m.member_no, m.full_name as member_name, 
             m.national_id, m.phone, m.email, m.date_joined,
             lp.product_name, lp.interest_rate as product_rate, 
             lp.max_amount, lp.min_amount,
             u.full_name as created_by_name,
             a.full_name as approved_by_name
             FROM loans l
             JOIN members m ON l.member_id = m.id
             JOIN loan_products lp ON l.product_id = lp.id
             LEFT JOIN users u ON l.created_by = u.id
             LEFT JOIN users a ON l.approved_by = a.id
             WHERE l.id = ?";
$loan_result = executeQuery($loan_sql, "i", [$loan_id]);

if ($loan_result->num_rows == 0) {
    $_SESSION['error'] = 'Loan not found';
    header('Location: index.php');
    exit();
}

$loan = $loan_result->fetch_assoc();
$page_title = 'Loan Details - ' . $loan['loan_no'];

// Get guarantors for this loan
$guarantors_sql = "SELECT lg.*, 
                   m.member_no, m.full_name as guarantor_name, 
                   m.phone, m.email
                   FROM loan_guarantors lg
                   JOIN members m ON lg.guarantor_member_id = m.id
                   WHERE lg.loan_id = ?
                   ORDER BY lg.status, lg.created_at";
$guarantors = executeQuery($guarantors_sql, "i", [$loan_id]);

// Get guarantor statistics
$guarantor_stats_sql = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                        COALESCE(SUM(CASE WHEN status = 'approved' THEN guaranteed_amount ELSE 0 END), 0) as total_approved_amount
                        FROM loan_guarantors
                        WHERE loan_id = ?";
$guarantor_stats_result = executeQuery($guarantor_stats_sql, "i", [$loan_id]);
$guarantor_stats = $guarantor_stats_result->fetch_assoc();

// Get repayment schedule
$schedule_sql = "SELECT * FROM amortization_schedule 
                 WHERE loan_id = ? 
                 ORDER BY installment_no ASC";
$schedule = executeQuery($schedule_sql, "i", [$loan_id]);

// Get repayment history
$repayments_sql = "SELECT lr.*, u.full_name as recorded_by_name
                   FROM loan_repayments lr
                   LEFT JOIN users u ON lr.created_by = u.id
                   WHERE lr.loan_id = ?
                   ORDER BY lr.payment_date DESC";
$repayments = executeQuery($repayments_sql, "i", [$loan_id]);

// Calculate loan statistics
$total_paid = 0;
$repayment_count = 0;
$last_payment_date = null;
$next_due_date = null;
$next_installment = null;

// Check if amortization_schedule table exists and has data
$schedule_exists = false;
if ($schedule && $schedule->num_rows > 0) {
    $schedule_exists = true;
    // Get next due payment
    $next_sql = "SELECT * FROM amortization_schedule 
                 WHERE loan_id = ? AND status = 'pending' 
                 ORDER BY due_date ASC LIMIT 1";
    $next_result = executeQuery($next_sql, "i", [$loan_id]);
    if ($next_result && $next_result->num_rows > 0) {
        $next = $next_result->fetch_assoc();
        $next_due_date = $next['due_date'];
        $next_installment = $next['total_payment'];
    }
}

// Calculate total paid from repayments
$paid_sql = "SELECT COALESCE(SUM(amount_paid), 0) as total FROM loan_repayments WHERE loan_id = ?";
$paid_result = executeQuery($paid_sql, "i", [$loan_id]);
$total_paid = $paid_result->fetch_assoc()['total'];
$repayment_count = $repayments->num_rows;

// Get last payment date
if ($repayments && $repayments->num_rows > 0) {
    $repayments->data_seek(0);
    $last = $repayments->fetch_assoc();
    $last_payment_date = $last['payment_date'];
    $repayments->data_seek(0); // Reset pointer
}

// Calculate remaining balance
$remaining_balance = $loan['total_amount'] - $total_paid;
$progress_percentage = $loan['total_amount'] > 0 ? ($total_paid / $loan['total_amount']) * 100 : 0;

// Check if loan is overdue
$is_overdue = false;
if ($next_due_date && $next_due_date < date('Y-m-d') && $loan['status'] == 'active') {
    $is_overdue = true;
}

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Loan Details</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Loans</a></li>
                <li class="breadcrumb-item active">View Loan</li>
            </ul>
        </div>
        <div class="col-auto">
            <?php if ($loan['status'] == 'approved'): ?>
                <a href="disburse.php?id=<?php echo $loan_id; ?>" class="btn btn-success">
                    <i class="fas fa-money-bill-wave me-2"></i>Disburse Loan
                </a>
            <?php elseif ($loan['status'] == 'disbursed' || $loan['status'] == 'active'): ?>
                <a href="repayment.php?loan_id=<?php echo $loan_id; ?>" class="btn btn-primary">
                    <i class="fas fa-credit-card me-2"></i>Record Payment
                </a>
            <?php endif; ?>

            <?php if ($loan['status'] == 'pending' || $loan['status'] == 'guarantor_pending'): ?>
                <a href="approve.php?id=<?php echo $loan_id; ?>" class="btn btn-warning">
                    <i class="fas fa-check-circle me-2"></i>Review Loan
                </a>
            <?php endif; ?>

            <!-- Guarantor Management Link - Visible for pending and guarantor_pending status -->
            <?php if ($loan['status'] == 'guarantor_pending' || $loan['status'] == 'pending'): ?>
                <a href="process-guarantors.php?loan_id=<?php echo $loan_id; ?>" class="btn btn-info">
                    <i class="fas fa-handshake me-2"></i>Manage Guarantors
                    <?php if (($guarantor_stats['pending'] ?? 0) > 0): ?>
                        <span class="badge bg-warning ms-1"><?php echo $guarantor_stats['pending']; ?> Pending</span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>

            <button class="btn btn-secondary" onclick="printLoan()">
                <i class="fas fa-print me-2"></i>Print
            </button>
            <a href="index.php" class="btn btn-dark">
                <i class="fas fa-arrow-left me-2"></i>Back
            </a>
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

<!-- Loan Status Banner -->
<div class="alert alert-<?php
                        echo $loan['status'] == 'active' ? 'success' : ($loan['status'] == 'defaulted' ? 'danger' : ($loan['status'] == 'completed' ? 'info' : ($loan['status'] == 'pending' ? 'warning' : ($loan['status'] == 'guarantor_pending' ? 'secondary' : 'secondary'))));
                        ?> mb-4">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <i class="fas fa-<?php
                                echo $loan['status'] == 'active' ? 'check-circle' : ($loan['status'] == 'defaulted' ? 'exclamation-triangle' : ($loan['status'] == 'completed' ? 'flag' : ($loan['status'] == 'pending' ? 'clock' : ($loan['status'] == 'guarantor_pending' ? 'handshake' : 'info-circle'))));
                                ?> fa-2x me-3"></i>
            <strong>Loan Status: <?php echo strtoupper(str_replace('_', ' ', $loan['status'])); ?></strong>
            <?php if ($is_overdue): ?>
                <span class="badge bg-danger ms-3">OVERDUE</span>
            <?php endif; ?>

            <!-- Guarantor Status Indicator -->
            <?php if ($loan['status'] == 'guarantor_pending'): ?>
                <span class="badge bg-info ms-3">
                    <i class="fas fa-handshake me-1"></i>
                    Need <?php echo max(0, 3 - ($guarantor_stats['approved'] ?? 0)); ?> more guarantor(s)
                </span>
            <?php endif; ?>
        </div>
        <div>
            <span class="badge bg-primary fs-6"><?php echo $loan['loan_no']; ?></span>
        </div>
    </div>
</div>

<!-- Loan Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="stats-card primary">
            <div class="stats-icon">
                <i class="fas fa-money-bill-wave"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($loan['principal_amount']); ?></h3>
                <p>Principal Amount</p>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card info">
            <div class="stats-icon">
                <i class="fas fa-percentage"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo $loan['interest_rate']; ?>%</h3>
                <p>Interest Rate</p>
                <small>Amount: <?php echo formatCurrency($loan['interest_amount']); ?></small>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card success">
            <div class="stats-icon">
                <i class="fas fa-credit-card"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($total_paid); ?></h3>
                <p>Total Paid</p>
                <small><?php echo $repayment_count; ?> payments</small>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card <?php echo $remaining_balance > 0 ? 'warning' : 'success'; ?>">
            <div class="stats-icon">
                <i class="fas fa-hand-holding-usd"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($remaining_balance); ?></h3>
                <p>Remaining Balance</p>
                <small><?php echo number_format($progress_percentage, 1); ?>% paid</small>
            </div>
        </div>
    </div>
</div>

<!-- Progress Bar -->
<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between mb-2">
            <span>Repayment Progress</span>
            <span class="fw-bold"><?php echo number_format($progress_percentage, 1); ?>% Complete</span>
        </div>
        <div class="progress" style="height: 25px;">
            <div class="progress-bar bg-success progress-bar-striped"
                role="progressbar"
                style="width: <?php echo $progress_percentage; ?>%;"
                aria-valuenow="<?php echo $progress_percentage; ?>"
                aria-valuemin="0"
                aria-valuemax="100">
                <?php echo formatCurrency($total_paid); ?> / <?php echo formatCurrency($loan['total_amount']); ?>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Left Column - Member & Loan Details -->
    <div class="col-md-6">
        <!-- Member Information -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="card-title mb-0"><i class="fas fa-user me-2"></i>Member Information</h5>
                <div class="card-tools">
                    <a href="../members/view.php?id=<?php echo $loan['member_id']; ?>" class="btn btn-sm btn-light">
                        <i class="fas fa-external-link-alt"></i> View Profile
                    </a>
                </div>
            </div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr>
                        <th style="width: 150px;">Member No:</th>
                        <td>
                            <a href="../members/view.php?id=<?php echo $loan['member_id']; ?>">
                                <strong><?php echo $loan['member_no']; ?></strong>
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <th>Full Name:</th>
                        <td><?php echo $loan['member_name']; ?></td>
                    </tr>
                    <tr>
                        <th>National ID:</th>
                        <td><?php echo $loan['national_id']; ?></td>
                    </tr>
                    <tr>
                        <th>Phone:</th>
                        <td><?php echo $loan['phone']; ?></td>
                    </tr>
                    <tr>
                        <th>Email:</th>
                        <td><?php echo $loan['email'] ?: 'N/A'; ?></td>
                    </tr>
                    <tr>
                        <th>Date Joined:</th>
                        <td><?php echo formatDate($loan['date_joined']); ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Loan Details -->
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="card-title mb-0"><i class="fas fa-file-invoice me-2"></i>Loan Details</h5>
            </div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr>
                        <th style="width: 150px;">Product:</th>
                        <td><?php echo $loan['product_name']; ?></td>
                    </tr>
                    <tr>
                        <th>Application Date:</th>
                        <td><?php echo formatDate($loan['application_date']); ?></td>
                    </tr>
                    <tr>
                        <th>Approval Date:</th>
                        <td><?php echo $loan['approval_date'] ? formatDate($loan['approval_date']) : 'Pending'; ?></td>
                    </tr>
                    <tr>
                        <th>Disbursement Date:</th>
                        <td><?php echo $loan['disbursement_date'] ? formatDate($loan['disbursement_date']) : 'Not disbursed'; ?></td>
                    </tr>
                    <tr>
                        <th>Duration:</th>
                        <td><?php echo $loan['duration_months']; ?> months</td>
                    </tr>
                    <tr>
                        <th>Principal Amount:</th>
                        <td class="fw-bold"><?php echo formatCurrency($loan['principal_amount']); ?></td>
                    </tr>
                    <tr>
                        <th>Interest Amount:</th>
                        <td><?php echo formatCurrency($loan['interest_amount']); ?> (<?php echo $loan['interest_rate']; ?>% p.a.)</td>
                    </tr>
                    <tr>
                        <th>Total Amount:</th>
                        <td class="fw-bold text-primary"><?php echo formatCurrency($loan['total_amount']); ?></td>
                    </tr>
                    <?php if ($next_installment): ?>
                        <tr>
                            <th>Monthly Installment:</th>
                            <td class="fw-bold"><?php echo formatCurrency($next_installment); ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th>Created By:</th>
                        <td><?php echo $loan['created_by_name'] ?: 'System'; ?> on <?php echo formatDate($loan['created_at']); ?></td>
                    </tr>
                    <?php if ($loan['approved_by_name']): ?>
                        <tr>
                            <th>Approved By:</th>
                            <td><?php echo $loan['approved_by_name']; ?></td>
                        </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- Right Column - Guarantors & Payment Info -->
    <div class="col-md-6">
        <!-- Guarantors Card with Management Link -->
        <div class="card mb-4">
            <div class="card-header bg-warning">
                <h5 class="card-title mb-0"><i class="fas fa-handshake me-2"></i>Guarantors</h5>
                <div class="card-tools">
                    <?php if ($loan['status'] == 'guarantor_pending' || $loan['status'] == 'pending'): ?>
                        <a href="process-guarantors.php?loan_id=<?php echo $loan_id; ?>" class="btn btn-sm btn-primary">
                            <i class="fas fa-edit me-1"></i> Manage Guarantors
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <!-- Guarantor Status Summary -->
                <div class="row mb-3">
                    <div class="col-4 text-center">
                        <div class="small text-muted">Total</div>
                        <h5><?php echo $guarantor_stats['total'] ?? 0; ?></h5>
                    </div>
                    <div class="col-4 text-center">
                        <div class="small text-muted">Approved</div>
                        <h5 class="text-success"><?php echo $guarantor_stats['approved'] ?? 0; ?></h5>
                    </div>
                    <div class="col-4 text-center">
                        <div class="small text-muted">Pending</div>
                        <h5 class="text-warning"><?php echo $guarantor_stats['pending'] ?? 0; ?></h5>
                    </div>
                </div>

                <!-- Progress to 3 Guarantors -->
                <?php if ($loan['status'] == 'guarantor_pending'): ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span>Required Guarantors Progress</span>
                            <span class="fw-bold"><?php echo min(($guarantor_stats['approved'] ?? 0), 3); ?>/3</span>
                        </div>
                        <div class="progress" style="height: 10px;">
                            <?php $guarantor_progress = (($guarantor_stats['approved'] ?? 0) / 3) * 100; ?>
                            <div class="progress-bar bg-success"
                                role="progressbar"
                                style="width: <?php echo min($guarantor_progress, 100); ?>%;"
                                aria-valuenow="<?php echo min($guarantor_progress, 100); ?>"
                                aria-valuemin="0"
                                aria-valuemax="100">
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Guarantors List -->
                <?php if ($guarantors && $guarantors->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $guarantors->data_seek(0);
                                $display_count = 0;
                                while ($g = $guarantors->fetch_assoc()):
                                    $display_count++;
                                    if ($display_count <= 3): // Show only first 3
                                ?>
                                        <tr>
                                            <td>
                                                <a href="../members/view.php?id=<?php echo $g['guarantor_member_id']; ?>">
                                                    <?php echo $g['guarantor_name']; ?>
                                                </a>
                                            </td>
                                            <td><?php echo formatCurrency($g['guaranteed_amount']); ?></td>
                                            <td>
                                                <?php if ($g['status'] == 'approved'): ?>
                                                    <span class="badge bg-success">Approved</span>
                                                <?php elseif ($g['status'] == 'pending'): ?>
                                                    <span class="badge bg-warning">Pending</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Rejected</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="../members/view.php?id=<?php echo $g['guarantor_member_id']; ?>" class="btn btn-sm btn-outline-info" title="View Guarantor">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                <?php
                                    endif;
                                endwhile;
                                ?>
                            </tbody>
                            <?php if ($guarantors->num_rows > 3): ?>
                                <tfoot>
                                    <tr>
                                        <td colspan="4" class="text-center">
                                            <a href="process-guarantors.php?loan_id=<?php echo $loan_id; ?>" class="btn btn-sm btn-link">
                                                View all <?php echo $guarantors->num_rows; ?> guarantors
                                            </a>
                                        </td>
                                    </tr>
                                </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-3">
                        <i class="fas fa-user-friends fa-2x text-muted mb-2"></i>
                        <p class="text-muted">No guarantors added yet</p>
                        <?php if ($loan['status'] == 'guarantor_pending' || $loan['status'] == 'pending'): ?>
                            <a href="process-guarantors.php?loan_id=<?php echo $loan_id; ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-plus me-1"></i> Add Guarantors
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Coverage Summary -->
                <?php if (($guarantor_stats['total_approved_amount'] ?? 0) > 0): ?>
                    <div class="mt-3 p-2 bg-light rounded">
                        <div class="d-flex justify-content-between">
                            <span>Total Guaranteed:</span>
                            <span class="fw-bold"><?php echo formatCurrency($guarantor_stats['total_approved_amount'] ?? 0); ?></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span>Required Coverage:</span>
                            <span class="fw-bold"><?php echo formatCurrency($loan['principal_amount']); ?></span>
                        </div>
                        <div class="d-flex justify-content-between mt-1">
                            <span>Status:</span>
                            <span>
                                <?php if (($guarantor_stats['total_approved_amount'] ?? 0) >= $loan['principal_amount']): ?>
                                    <span class="badge bg-success">Fully Covered</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">
                                        Short by <?php echo formatCurrency($loan['principal_amount'] - ($guarantor_stats['total_approved_amount'] ?? 0)); ?>
                                    </span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payment Schedule Summary -->
        <div class="card mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="card-title mb-0"><i class="fas fa-calendar-alt me-2"></i>Payment Schedule</h5>
            </div>
            <div class="card-body">
                <?php if ($next_due_date): ?>
                    <div class="alert <?php echo $is_overdue ? 'alert-danger' : 'alert-info'; ?>">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong>Next Payment Due:</strong> <?php echo formatDate($next_due_date); ?>
                                <?php if ($is_overdue): ?>
                                    <span class="badge bg-danger ms-2">OVERDUE</span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <strong>Amount:</strong> <?php echo formatCurrency($next_installment); ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($last_payment_date): ?>
                    <div class="alert alert-success">
                        <div class="d-flex justify-content-between">
                            <div>
                                <strong>Last Payment:</strong> <?php echo formatDate($last_payment_date); ?>
                            </div>
                            <div>
                                <strong>Total Paid:</strong> <?php echo formatCurrency($total_paid); ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($schedule_exists): ?>
                    <div class="table-responsive mt-3">
                        <table class="table table-sm table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Due Date</th>
                                    <th>Principal</th>
                                    <th>Interest</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $schedule_count = 0;
                                $schedule->data_seek(0);
                                while ($row = $schedule->fetch_assoc()):
                                    $schedule_count++;
                                    if ($schedule_count <= 5): // Show only first 5
                                ?>
                                        <tr class="<?php
                                                    echo $row['status'] == 'paid' ? 'table-success' : ($row['due_date'] < date('Y-m-d') && $row['status'] != 'paid' ? 'table-danger' : '');
                                                    ?>">
                                            <td><?php echo $row['installment_no']; ?></td>
                                            <td><?php echo formatDate($row['due_date']); ?></td>
                                            <td><?php echo formatCurrency($row['principal']); ?></td>
                                            <td><?php echo formatCurrency($row['interest']); ?></td>
                                            <td><?php echo formatCurrency($row['total_payment']); ?></td>
                                            <td>
                                                <?php if ($row['status'] == 'paid'): ?>
                                                    <span class="badge bg-success">Paid</span>
                                                <?php elseif ($row['due_date'] < date('Y-m-d')): ?>
                                                    <span class="badge bg-danger">Overdue</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                <?php
                                    endif;
                                endwhile;
                                ?>
                            </tbody>
                        </table>
                        <?php if ($schedule_count > 5): ?>
                            <div class="text-center mt-2">
                                <a href="#fullSchedule" data-bs-toggle="collapse" class="btn btn-sm btn-link">
                                    Show all <?php echo $schedule_count; ?> installments
                                </a>
                            </div>
                            <div class="collapse" id="fullSchedule">
                                <table class="table table-sm table-bordered mt-2">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Due Date</th>
                                            <th>Principal</th>
                                            <th>Interest</th>
                                            <th>Total</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $schedule->data_seek(0);
                                        while ($row = $schedule->fetch_assoc()):
                                        ?>
                                            <tr class="<?php
                                                        echo $row['status'] == 'paid' ? 'table-success' : ($row['due_date'] < date('Y-m-d') && $row['status'] != 'paid' ? 'table-danger' : '');
                                                        ?>">
                                                <td><?php echo $row['installment_no']; ?></td>
                                                <td><?php echo formatDate($row['due_date']); ?></td>
                                                <td><?php echo formatCurrency($row['principal']); ?></td>
                                                <td><?php echo formatCurrency($row['interest']); ?></td>
                                                <td><?php echo formatCurrency($row['total_payment']); ?></td>
                                                <td>
                                                    <?php if ($row['status'] == 'paid'): ?>
                                                        <span class="badge bg-success">Paid</span>
                                                    <?php elseif ($row['due_date'] < date('Y-m-d')): ?>
                                                        <span class="badge bg-danger">Overdue</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning">Pending</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted text-center py-3">No payment schedule generated yet</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Repayment History -->
<div class="card mt-2">
    <div class="card-header bg-secondary text-white">
        <h5 class="card-title mb-0"><i class="fas fa-history me-2"></i>Repayment History</h5>
        <?php if ($loan['status'] == 'disbursed' || $loan['status'] == 'active'): ?>
            <div class="card-tools">
                <a href="repayment.php?loan_id=<?php echo $loan_id; ?>" class="btn btn-sm btn-light">
                    <i class="fas fa-plus me-1"></i> Record Payment
                </a>
            </div>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ($repayments && $repayments->num_rows > 0): ?>
            <div class="table-responsive">
                <table class="table table-bordered datatable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Amount Paid</th>
                            <th>Principal</th>
                            <th>Interest</th>
                            <th>Penalty</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th>Recorded By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($r = $repayments->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo formatDate($r['payment_date']); ?></td>
                                <td class="fw-bold text-success"><?php echo formatCurrency($r['amount_paid']); ?></td>
                                <td><?php echo formatCurrency($r['principal_paid']); ?></td>
                                <td><?php echo formatCurrency($r['interest_paid']); ?></td>
                                <td><?php echo $r['penalty_paid'] ? formatCurrency($r['penalty_paid']) : '-'; ?></td>
                                <td>
                                    <span class="badge bg-info"><?php echo ucfirst($r['payment_method']); ?></span>
                                </td>
                                <td><?php echo $r['reference_no'] ?: '-'; ?></td>
                                <td><?php echo $r['recorded_by_name'] ?: 'System'; ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-info">
                            <th colspan="1" class="text-end">Totals:</th>
                            <th><?php echo formatCurrency($total_paid); ?></th>
                            <th colspan="6"></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php else: ?>
            <p class="text-muted text-center py-3">No repayments recorded yet</p>
        <?php endif; ?>
    </div>
</div>

<!-- Loan Documents Section -->
<div class="card mt-4">
    <div class="card-header">
        <h5 class="card-title"><i class="fas fa-file-pdf me-2"></i>Loan Documents</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-file-pdf fa-3x text-danger mb-2"></i>
                        <h6>Loan Application</h6>
                        <button class="btn btn-sm btn-outline-primary" onclick="generateDocument('application')">
                            <i class="fas fa-download"></i> Download
                        </button>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-file-signature fa-3x text-primary mb-2"></i>
                        <h6>Loan Agreement</h6>
                        <button class="btn btn-sm btn-outline-primary" onclick="generateDocument('agreement')">
                            <i class="fas fa-download"></i> Download
                        </button>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-handshake fa-3x text-success mb-2"></i>
                        <h6>Guarantor Forms</h6>
                        <button class="btn btn-sm btn-outline-primary" onclick="generateDocument('guarantor')">
                            <i class="fas fa-download"></i> Download
                        </button>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-receipt fa-3x text-warning mb-2"></i>
                        <h6>Payment Schedule</h6>
                        <button class="btn btn-sm btn-outline-primary" onclick="generateDocument('schedule')">
                            <i class="fas fa-download"></i> Download
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Print loan details
    function printLoan() {
        var printWindow = window.open('print-loan.php?id=<?php echo $loan_id; ?>', '_blank');
        if (printWindow) {
            printWindow.onload = function() {
                printWindow.print();
            };
        } else {
            alert('Please allow pop-ups to print the loan details');
        }
    }

    // Generate document
    function generateDocument(type) {
        window.open('generate-document.php?loan_id=<?php echo $loan_id; ?>&type=' + type, '_blank');
    }

    // Confirm action
    function confirmAction(message, url) {
        Swal.fire({
            title: 'Are you sure?',
            text: message,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, proceed!'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = url;
            }
        });
    }

    // Initialize tooltips
    document.addEventListener('DOMContentLoaded', function() {
        var tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        tooltips.forEach(function(tooltip) {
            new bootstrap.Tooltip(tooltip);
        });
    });
</script>

<style>
    .stats-card .stats-content small {
        font-size: 11px;
        opacity: 0.9;
    }

    .progress {
        border-radius: 15px;
        background-color: #e9ecef;
    }

    .progress-bar {
        border-radius: 15px;
        font-size: 12px;
        font-weight: bold;
    }

    .table td,
    .table th {
        vertical-align: middle;
    }

    .card-header {
        font-weight: 600;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .card-header .card-tools {
        margin-left: auto;
    }

    .badge {
        font-size: 11px;
        padding: 5px 8px;
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
<?php
// modules/loans/approve.php
require_once '../../config/config.php';
requireRole('admin');

$id = $_GET['id'] ?? 0;

// Get system settings
$settings = getLoanSettings();

// Get loan details
$sql = "SELECT l.*, m.full_name, m.member_no, m.date_joined, m.id as member_id,
        m.national_id, m.phone, m.email,
        lp.product_name, lp.interest_rate as product_rate,
        lp.processing_fee, lp.insurance_fee, lp.min_savings_balance, lp.max_loans_active
        FROM loans l 
        JOIN members m ON l.member_id = m.id 
        JOIN loan_products lp ON l.product_id = lp.id 
        WHERE l.id = ?";
$result = executeQuery($sql, "i", [$id]);

if ($result->num_rows == 0) {
    $_SESSION['error'] = 'Loan not found';
    header('Location: index.php');
    exit();
}

$loan = $result->fetch_assoc();

// Get member financial status
$member_stats = getMemberFinancialStatus($loan['member_id']);

// Get guarantor information
$guarantors = getGuarantorInfo($id);

// Check eligibility with system settings
$eligibility = checkApprovalEligibility($loan, $member_stats, $guarantors, $settings);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'];

    if ($action == 'approve') {
        approveLoan($id, $loan, $settings);
    } elseif ($action == 'reject') {
        rejectLoan($id, $loan);
    }
}

function getLoanSettings()
{
    $settings = [];
    $result = executeQuery("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'loan_%' OR setting_key LIKE '%guarantor%'");
    while ($row = $result->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    return $settings;
}

function getMemberFinancialStatus($member_id)
{
    $stats = [];

    // Get savings balance
    $savings = executeQuery("SELECT COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE -amount END), 0) as balance 
                            FROM deposits WHERE member_id = ?", "i", [$member_id])->fetch_assoc()['balance'];
    $stats['savings_balance'] = $savings;

    // Get shares value
    $shares = executeQuery("SELECT COALESCE(SUM(total_value), 0) as total FROM shares WHERE member_id = ?", "i", [$member_id])->fetch_assoc()['total'];
    $stats['shares_value'] = $shares;

    // Get active loans count
    $active_loans = executeQuery("SELECT COUNT(*) as count FROM loans WHERE member_id = ? AND status IN ('active', 'disbursed')", "i", [$member_id])->fetch_assoc()['count'];
    $stats['active_loans'] = $active_loans;

    // Get previous loans performance
    $previous_loans = executeQuery("SELECT COUNT(*) as count, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed 
                                   FROM loans WHERE member_id = ?", "i", [$member_id])->fetch_assoc();
    $stats['previous_loans'] = $previous_loans['count'];
    $stats['completed_loans'] = $previous_loans['completed'];

    return $stats;
}

function getGuarantorInfo($loan_id)
{
    $guarantors = executeQuery("SELECT lg.*, m.full_name, m.member_no, m.phone,
                               (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE -amount END), 0) 
                                FROM deposits WHERE member_id = m.id) as savings,
                               (SELECT COALESCE(SUM(total_value), 0) FROM shares WHERE member_id = m.id) as shares
                               FROM loan_guarantors lg
                               JOIN members m ON lg.guarantor_member_id = m.id
                               WHERE lg.loan_id = ?", "i", [$loan_id]);

    $data = [];
    while ($g = $guarantors->fetch_assoc()) {
        $data[] = $g;
    }

    // Calculate totals
    $total_approved = 0;
    $approved_count = 0;
    foreach ($data as $g) {
        if ($g['status'] == 'approved') {
            $total_approved += $g['guaranteed_amount'];
            $approved_count++;
        }
    }

    return [
        'list' => $data,
        'total_approved' => $total_approved,
        'approved_count' => $approved_count
    ];
}

function checkApprovalEligibility($loan, $member_stats, $guarantors, $settings)
{
    $eligible = true;
    $reasons = [];
    $warnings = [];

    // Check savings balance requirement
    $min_savings = $loan['min_savings_balance'] ?? ($settings['min_savings_balance'] ?? 0);
    if ($member_stats['savings_balance'] < $min_savings) {
        $eligible = false;
        $reasons[] = "Insufficient savings: " . formatCurrency($member_stats['savings_balance']) .
            " (required: " . formatCurrency($min_savings) . ")";
    }

    // Check maximum active loans
    $max_active = $loan['max_loans_active'] ?? ($settings['max_loans_active'] ?? 1);
    if ($member_stats['active_loans'] >= $max_active) {
        $eligible = false;
        $reasons[] = "Member already has {$member_stats['active_loans']} active loan(s) (maximum: $max_active)";
    }

    // Check guarantor requirements
    $guarantor_required = $loan['guarantor_required'] ?? ($settings['guarantor_required'] ?? 1);
    $min_guarantors = $loan['min_guarantors'] ?? ($settings['min_guarantors'] ?? 1);

    if ($guarantor_required && $guarantors['approved_count'] < $min_guarantors) {
        // Check if self-guarantee is enabled
        $self_guarantee_enabled = $settings['enable_self_guarantee'] ?? 1;
        $self_multiplier = $settings['self_guarantee_multiplier'] ?? 3;

        $self_guarantee_limit = $member_stats['savings_balance'] * $self_multiplier;

        if ($self_guarantee_enabled && $self_guarantee_limit >= $loan['principal_amount']) {
            $warnings[] = "Using self-guarantee (savings × $self_multiplier = " . formatCurrency($self_guarantee_limit) . ")";
        } else {
            $eligible = false;
            $reasons[] = "Insufficient guarantors: {$guarantors['approved_count']} approved (need $min_guarantors)";
        }
    }

    // Check guarantor coverage
    if ($guarantors['total_approved'] < $loan['principal_amount'] && !($self_guarantee_enabled ?? false)) {
        $eligible = false;
        $reasons[] = "Insufficient guarantor coverage: " . formatCurrency($guarantors['total_approved']) .
            " (need " . formatCurrency($loan['principal_amount']) . ")";
    }

    // Check if loan exceeds auto-approve threshold
    $auto_approve_threshold = $settings['auto_approve_threshold'] ?? 0;
    if ($auto_approve_threshold > 0 && $loan['principal_amount'] > $auto_approve_threshold) {
        $warnings[] = "Loan exceeds auto-approve threshold of " . formatCurrency($auto_approve_threshold);
    }

    return [
        'eligible' => $eligible,
        'reasons' => $reasons,
        'warnings' => $warnings
    ];
}

function approveLoan($loan_id, $loan, $settings)
{
    $conn = getConnection();
    $conn->begin_transaction();

    try {
        // Record processing and insurance fees if not already recorded
        if ($loan['processing_fee'] > 0) {
            recordLoanFee($conn, $loan_id, $loan['member_id'], 'processing_fee', $loan['processing_fee'], $loan['principal_amount']);
        }

        if ($loan['insurance_fee'] > 0) {
            recordLoanFee($conn, $loan_id, $loan['member_id'], 'insurance_fee', $loan['insurance_fee'], $loan['principal_amount']);
        }

        // Update loan status
        $updateSql = "UPDATE loans SET status = 'approved', approval_date = CURDATE(), approved_by = ? WHERE id = ?";
        executeQuery($updateSql, "ii", [getCurrentUserId(), $loan_id]);

        // Create amortization schedule
        createAmortizationSchedule($conn, $loan_id, $loan['principal_amount'], $loan['interest_rate'], $loan['duration_months']);

        $conn->commit();

        logAudit('APPROVE', 'loans', $loan_id, null, $loan);
        $_SESSION['success'] = 'Loan approved successfully';
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Approval failed: ' . $e->getMessage();
    }

    $conn->close();
    header('Location: index.php');
    exit();
}

function rejectLoan($loan_id, $loan)
{
    $updateSql = "UPDATE loans SET status = 'rejected' WHERE id = ?";
    executeQuery($updateSql, "i", [$loan_id]);

    logAudit('REJECT', 'loans', $loan_id, null, $loan);
    $_SESSION['success'] = 'Loan rejected';

    header('Location: index.php');
    exit();
}

function recordLoanFee($conn, $loan_id, $member_id, $fee_type, $amount, $principal)
{
    $reference_no = strtoupper(substr($fee_type, 0, 3)) . $loan_id . time();
    $description = ucfirst(str_replace('_', ' ', $fee_type)) . " for loan #$loan_id";

    $sql = "INSERT INTO admin_charges (member_id, charge_type, amount, charge_date, description, reference_no, loan_id, status, created_by)
            VALUES (?, ?, ?, CURDATE(), ?, ?, ?, 'pending', ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isdssii", $member_id, $fee_type, $amount, $description, $reference_no, $loan_id, getCurrentUserId());
    $stmt->execute();
}

function createAmortizationSchedule($conn, $loan_id, $principal, $rate, $months)
{
    $monthly_rate = $rate / 100 / 12;
    $monthly_payment = ($principal * $monthly_rate * pow(1 + $monthly_rate, $months)) / (pow(1 + $monthly_rate, $months) - 1);

    $balance = $principal;
    $due_date = new DateTime();
    $due_date->modify('+1 month');

    for ($i = 1; $i <= $months; $i++) {
        $interest = $balance * $monthly_rate;
        $principal_paid = $monthly_payment - $interest;
        $balance -= $principal_paid;

        if ($balance < 0) $balance = 0;

        $sql = "INSERT INTO amortization_schedule (loan_id, installment_no, due_date, principal, interest, total_payment, balance, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iisdddd", $loan_id, $i, $due_date->format('Y-m-d'), $principal_paid, $interest, $monthly_payment, $balance);
        $stmt->execute();

        $due_date->modify('+1 month');
    }
}

$page_title = 'Approve Loan - ' . $loan['loan_no'];

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Loan Approval</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Loans</a></li>
                <li class="breadcrumb-item"><a href="approvals.php">Approvals</a></li>
                <li class="breadcrumb-item active">Approve Loan</li>
            </ul>
        </div>
    </div>
</div>

<!-- Loan Details Card -->
<div class="row">
    <div class="col-md-8">
        <!-- Loan Application Details -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="card-title mb-0">Loan Application Details</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">Loan Number</label>
                        <p class="fw-bold fs-5"><?php echo $loan['loan_no']; ?></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">Product</label>
                        <p class="fw-bold"><?php echo $loan['product_name']; ?> (<?php echo $loan['interest_rate']; ?>% p.a.)</p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">Member</label>
                        <p class="fw-bold"><?php echo $loan['full_name']; ?></p>
                        <small class="text-muted"><?php echo $loan['member_no']; ?> | Joined: <?php echo formatDate($loan['date_joined']); ?></small>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">Contact</label>
                        <p class="mb-0"><?php echo $loan['phone']; ?></p>
                        <small><?php echo $loan['email'] ?: 'No email'; ?></small>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label text-muted">Application Date</label>
                        <p class="fw-bold"><?php echo formatDate($loan['application_date']); ?></p>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label text-muted">Principal Amount</label>
                        <p class="fw-bold text-primary fs-5"><?php echo formatCurrency($loan['principal_amount']); ?></p>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label text-muted">Duration</label>
                        <p class="fw-bold"><?php echo $loan['duration_months']; ?> months</p>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label text-muted">Interest Amount</label>
                        <p class="fw-bold"><?php echo formatCurrency($loan['interest_amount']); ?></p>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label text-muted">Total Amount</label>
                        <p class="fw-bold"><?php echo formatCurrency($loan['total_amount']); ?></p>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label text-muted">Monthly Installment</label>
                        <p class="fw-bold"><?php echo formatCurrency($loan['total_amount'] / $loan['duration_months']); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Member Financial Status -->
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="card-title mb-0">Member Financial Status</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="text-center p-3 border rounded">
                            <h6>Total Deposits</h6>
                            <h3 class="text-success"><?php echo formatCurrency($loan['member_deposits']); ?></h3>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-center p-3 border rounded">
                            <h6>Total Shares</h6>
                            <h3 class="text-primary"><?php echo formatCurrency($loan['member_shares']); ?></h3>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-center p-3 border rounded">
                            <h6>Total Assets</h6>
                            <h3 class="text-info"><?php echo formatCurrency($loan['member_deposits'] + $loan['member_shares']); ?></h3>
                        </div>
                    </div>
                </div>

                <!-- Self-Guarantee Calculator -->
                <div class="mt-4 p-3 bg-light rounded">
                    <h6 class="mb-3">Self-Guarantee Calculation</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <td>Member Deposits:</td>
                                    <td class="fw-bold"><?php echo formatCurrency($loan['member_deposits']); ?></td>
                                </tr>
                                <tr>
                                    <td>Multiplier:</td>
                                    <td class="fw-bold">× 3</td>
                                </tr>
                                <tr>
                                    <td>Self-Guarantee Limit:</td>
                                    <td class="fw-bold <?php echo $self_guarantee_eligible ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo formatCurrency($self_guarantee_limit); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Loan Amount:</td>
                                    <td class="fw-bold"><?php echo formatCurrency($loan['principal_amount']); ?></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <div class="text-center">
                                <div class="progress mb-2" style="height: 20px;">
                                    <?php
                                    $self_progress = $loan['member_deposits'] > 0 ?
                                        min(($loan['member_deposits'] / ($loan['principal_amount'] / 3)) * 100, 100) : 0;
                                    ?>
                                    <div class="progress-bar <?php echo $self_guarantee_eligible ? 'bg-success' : 'bg-warning'; ?>"
                                        role="progressbar"
                                        style="width: <?php echo $self_progress; ?>%;"
                                        aria-valuenow="<?php echo $self_progress; ?>"
                                        aria-valuemin="0"
                                        aria-valuemax="100">
                                        <?php echo number_format($self_progress, 1); ?>%
                                    </div>
                                </div>
                                <p class="mb-0">
                                    Required: <?php echo formatCurrency($loan['principal_amount'] / 3); ?>
                                    <?php if ($self_guarantee_eligible): ?>
                                        <br><span class="badge bg-success">Eligible for Self-Guarantee</span>
                                    <?php else: ?>
                                        <br><span class="badge bg-danger">Not Eligible for Self-Guarantee</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Guarantors Card -->
        <div class="card mb-4">
            <div class="card-header bg-warning">
                <h5 class="card-title mb-0">Guarantors (<?php echo $guarantor_data['approved_count'] ?? 0; ?> approved)</h5>
                <div class="card-tools">
                    <a href="process-guarantors.php?loan_id=<?php echo $id; ?>" class="btn btn-sm btn-primary">
                        <i class="fas fa-edit me-1"></i> Manage Guarantors
                    </a>
                </div>
            </div>
            <div class="card-body">
                <?php if ($guarantor_list && $guarantor_list->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Member No</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Assets</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $total_guaranteed = 0;
                                while ($g = $guarantor_list->fetch_assoc()):
                                    $total_guaranteed += $g['guaranteed_amount'];
                                    $guarantor_assets = $g['guarantor_shares'] + $g['guarantor_savings'];
                                ?>
                                    <tr class="<?php echo $g['status'] == 'approved' ? 'table-success' : ($g['status'] == 'pending' ? '' : 'table-danger'); ?>">
                                        <td><?php echo $g['full_name']; ?></td>
                                        <td><?php echo $g['member_no']; ?></td>
                                        <td class="fw-bold"><?php echo formatCurrency($g['guaranteed_amount']); ?></td>
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
                                            <small>Shares: <?php echo formatCurrency($g['guarantor_shares']); ?><br>
                                                Savings: <?php echo formatCurrency($g['guarantor_savings']); ?></small>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-info">
                                    <th colspan="2" class="text-end">Total Guaranteed:</th>
                                    <th><?php echo formatCurrency($total_guaranteed); ?></th>
                                    <th colspan="2"></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted text-center py-3">No guarantors added yet</p>
                <?php endif; ?>

                <!-- Guarantor Coverage Progress -->
                <div class="mt-3">
                    <div class="d-flex justify-content-between mb-1">
                        <span>Guarantor Coverage Progress</span>
                        <span class="fw-bold"><?php echo formatCurrency($guarantor_coverage); ?> / <?php echo formatCurrency($loan['principal_amount']); ?></span>
                    </div>
                    <div class="progress" style="height: 10px;">
                        <?php $guarantor_progress = ($guarantor_coverage / $loan['principal_amount']) * 100; ?>
                        <div class="progress-bar <?php echo $guarantor_coverage >= $loan['principal_amount'] ? 'bg-success' : 'bg-warning'; ?>"
                            role="progressbar"
                            style="width: <?php echo min($guarantor_progress, 100); ?>%;"
                            aria-valuenow="<?php echo min($guarantor_progress, 100); ?>"
                            aria-valuemin="0"
                            aria-valuemax="100">
                            <?php echo number_format(min($guarantor_progress, 100), 1); ?>%
                        </div>
                    </div>
                    <?php if ($guarantor_coverage < $loan['principal_amount']): ?>
                        <small class="text-danger">Short by <?php echo formatCurrency($loan['principal_amount'] - $guarantor_coverage); ?></small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Eligibility Check Card -->
    <div class="col-md-4">
        <div class="card mb-4 sticky-top" style="top: 80px;">
            <div class="card-header <?php echo $eligible ? 'bg-success' : 'bg-danger'; ?> text-white">
                <h5 class="card-title mb-0">
                    <i class="fas fa-<?php echo $eligible ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
                    Eligibility Check
                </h5>
            </div>
            <div class="card-body">
                <!-- Membership Duration -->
                <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                    <span>
                        <i class="fas fa-clock me-2"></i>Membership Duration
                    </span>
                    <span>
                        <?php echo $loan['membership_months']; ?> months
                        <?php if ($membership_eligible): ?>
                            <i class="fas fa-check-circle text-success ms-2"></i>
                        <?php else: ?>
                            <i class="fas fa-times-circle text-danger ms-2"></i>
                        <?php endif; ?>
                    </span>
                </div>

                <!-- Guarantor Coverage -->
                <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                    <span>
                        <i class="fas fa-handshake me-2"></i>Guarantor Coverage
                    </span>
                    <span class="text-end">
                        <?php echo formatCurrency($guarantor_coverage); ?>
                        <?php if ($guarantor_coverage >= $loan['principal_amount']): ?>
                            <i class="fas fa-check-circle text-success ms-2"></i>
                        <?php else: ?>
                            <i class="fas fa-times-circle text-danger ms-2"></i>
                        <?php endif; ?>
                        <br>
                        <small><?php echo $guarantor_count; ?> guarantor(s)</small>
                    </span>
                </div>

                <!-- Self-Guarantee -->
                <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                    <span>
                        <i class="fas fa-user-shield me-2"></i>Self-Guarantee
                    </span>
                    <span class="text-end">
                        <?php echo formatCurrency($self_guarantee_limit); ?>
                        <?php if ($self_guarantee_eligible): ?>
                            <i class="fas fa-check-circle text-success ms-2"></i>
                        <?php else: ?>
                            <i class="fas fa-times-circle text-danger ms-2"></i>
                        <?php endif; ?>
                        <br>
                        <small>3× deposits</small>
                    </span>
                </div>

                <!-- Eligibility Details -->
                <div class="mt-4">
                    <h6>Eligibility Details:</h6>
                    <ul class="list-unstyled">
                        <?php foreach ($approval_details as $detail): ?>
                            <li class="mb-2 small"><?php echo $detail; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Final Status -->
                <div class="alert <?php echo $eligible ? 'alert-success' : 'alert-danger'; ?> mt-3">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-<?php echo $eligible ? 'check-circle' : 'exclamation-triangle'; ?> fa-2x me-3"></i>
                        <div>
                            <strong><?php echo $eligible ? 'ELIGIBLE FOR APPROVAL' : 'NOT ELIGIBLE'; ?></strong>
                            <?php if ($eligible): ?>
                                <br>Can be approved via <strong><?php echo $approval_method == 'self' ? 'Self-Guarantee' : 'Guarantor Coverage'; ?></strong>
                            <?php else: ?>
                                <br>Does not meet approval criteria
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Approval/Rejection Buttons -->
                <?php if ($eligible): ?>
                    <form method="POST" action="" onsubmit="return confirmAction('Are you sure you want to approve this loan?')">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="btn btn-success w-100 mb-2">
                            <i class="fas fa-check-circle me-2"></i>Approve Loan
                        </button>
                    </form>

                    <button type="button" class="btn btn-danger w-100" onclick="showRejectForm()">
                        <i class="fas fa-times-circle me-2"></i>Reject Loan
                    </button>

                    <div id="rejectForm" style="display: none;" class="mt-3">
                        <form method="POST" action="" onsubmit="return confirmAction('Are you sure you want to reject this loan?')">
                            <input type="hidden" name="action" value="reject">
                            <div class="mb-2">
                                <label for="rejection_reason" class="form-label">Rejection Reason</label>
                                <select class="form-control mb-2" id="rejection_reason" name="rejection_reason" required>
                                    <option value="">-- Select Reason --</option>
                                    <option value="Insufficient guarantor coverage">Insufficient guarantor coverage</option>
                                    <option value="Cannot self-guarantee (low savings)">Cannot self-guarantee (low savings)</option>
                                    <option value="Membership too recent">Membership too recent</option>
                                    <option value="Poor credit history">Poor credit history</option>
                                    <option value="Incomplete documentation">Incomplete documentation</option>
                                    <option value="Other">Other</option>
                                </select>
                                <textarea class="form-control" name="rejection_reason_custom" placeholder="Specify other reason..."></textarea>
                            </div>
                            <button type="submit" class="btn btn-danger w-100">
                                <i class="fas fa-times-circle me-2"></i>Confirm Rejection
                            </button>
                            <button type="button" class="btn btn-secondary w-100 mt-2" onclick="hideRejectForm()">
                                Cancel
                            </button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        This loan does not meet the approval criteria.
                    </div>
                    <button type="button" class="btn btn-danger w-100" onclick="showRejectForm()">
                        <i class="fas fa-times-circle me-2"></i>Reject Loan
                    </button>

                    <div id="rejectForm" style="display: none;" class="mt-3">
                        <form method="POST" action="" onsubmit="return confirmAction('Are you sure you want to reject this loan?')">
                            <input type="hidden" name="action" value="reject">
                            <div class="mb-2">
                                <label for="rejection_reason" class="form-label">Rejection Reason</label>
                                <select class="form-control mb-2" id="rejection_reason" name="rejection_reason" required>
                                    <option value="">-- Select Reason --</option>
                                    <option value="Insufficient guarantor coverage">Insufficient guarantor coverage</option>
                                    <option value="Cannot self-guarantee (low savings)">Cannot self-guarantee (low savings)</option>
                                    <option value="Membership too recent">Membership too recent</option>
                                    <option value="Poor credit history">Poor credit history</option>
                                    <option value="Incomplete documentation">Incomplete documentation</option>
                                    <option value="Other">Other</option>
                                </select>
                                <textarea class="form-control" name="rejection_reason_custom" placeholder="Specify other reason..."></textarea>
                            </div>
                            <button type="submit" class="btn btn-danger w-100">
                                <i class="fas fa-times-circle me-2"></i>Confirm Rejection
                            </button>
                            <button type="button" class="btn btn-secondary w-100 mt-2" onclick="hideRejectForm()">
                                Cancel
                            </button>
                        </form>
                    </div>

                    <a href="approvals.php" class="btn btn-secondary w-100 mt-2">
                        <i class="fas fa-arrow-left me-2"></i>Back to Approvals
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    function showRejectForm() {
        document.getElementById('rejectForm').style.display = 'block';
    }

    function hideRejectForm() {
        document.getElementById('rejectForm').style.display = 'none';
    }

    function confirmAction(message) {
        return confirm(message);
    }

    // Handle rejection reason
    document.getElementById('rejection_reason')?.addEventListener('change', function() {
        var customField = document.querySelector('textarea[name="rejection_reason_custom"]');
        if (this.value == 'Other') {
            customField.style.display = 'block';
            customField.required = true;
        } else {
            customField.style.display = 'none';
            customField.required = false;
            customField.value = '';
        }
    });

    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
        var customField = document.querySelector('textarea[name="rejection_reason_custom"]');
        if (customField) {
            customField.style.display = 'none';
        }
    });
</script>

<?php
// Function to create amortization schedule
// function createAmortizationSchedule($loan_id, $principal, $rate, $months)
// {
//     $monthly_rate = $rate / 100 / 12;
//     $monthly_payment = ($principal * $monthly_rate * pow(1 + $monthly_rate, $months)) / (pow(1 + $monthly_rate, $months) - 1);

//     $balance = $principal;
//     $due_date = new DateTime();
//     $due_date->modify('+1 month');

//     for ($i = 1; $i <= $months; $i++) {
//         $interest = $balance * $monthly_rate;
//         $principal_paid = $monthly_payment - $interest;
//         $balance -= $principal_paid;

//         if ($balance < 0) $balance = 0;

//         $sql = "INSERT INTO amortization_schedule (loan_id, installment_no, due_date, principal, interest, total_payment, balance, status) 
//                 VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')";

//         executeQuery($sql, "iisdddd", [
//             $loan_id,
//             $i,
//             $due_date->format('Y-m-d'),
//             $principal_paid,
//             $interest,
//             $monthly_payment,
//             $balance
//         ]);

//         $due_date->modify('+1 month');
//     }
// }
?>

<style>
    .sticky-top {
        z-index: 1;
    }

    .progress {
        background-color: #e9ecef;
        border-radius: 10px;
    }

    .progress-bar {
        border-radius: 10px;
    }

    .list-unstyled li {
        padding: 5px 0;
        border-bottom: 1px dashed #dee2e6;
    }

    .list-unstyled li:last-child {
        border-bottom: none;
    }

    @media (max-width: 768px) {
        .sticky-top {
            position: relative;
            top: 0;
        }
    }
</style>

<?php include '../../includes/footer.php'; ?>
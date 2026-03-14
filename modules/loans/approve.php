<?php
require_once '../../config/config.php';
requireRole('admin');

$id = $_GET['id'] ?? 0;

// Get loan details with enhanced information
$sql = "SELECT l.*, 
        m.full_name, m.member_no, m.national_id, m.phone, m.email, m.date_joined,
        lp.product_name, lp.interest_rate as product_rate,
        (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE -amount END), 0) 
         FROM deposits WHERE member_id = m.id) as member_deposits,
        (SELECT COALESCE(SUM(total_value), 0) FROM shares WHERE member_id = m.id) as member_shares,
        TIMESTAMPDIFF(MONTH, m.date_joined, CURDATE()) as membership_months
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

// Get guarantor information
$guarantors_sql = "SELECT 
                   COUNT(*) as total_count,
                   SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_count,
                   COALESCE(SUM(CASE WHEN status = 'approved' THEN guaranteed_amount ELSE 0 END), 0) as total_guaranteed
                   FROM loan_guarantors 
                   WHERE loan_id = ?";
$guarantors_result = executeQuery($guarantors_sql, "i", [$id]);
$guarantor_data = $guarantors_result->fetch_assoc();

// Get detailed guarantor list
$guarantor_list_sql = "SELECT lg.*, m.full_name, m.member_no, m.phone,
                       (SELECT COALESCE(SUM(total_value), 0) FROM shares WHERE member_id = m.id) as guarantor_shares,
                       (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE -amount END), 0) 
                        FROM deposits WHERE member_id = m.id) as guarantor_savings
                       FROM loan_guarantors lg
                       JOIN members m ON lg.guarantor_member_id = m.id
                       WHERE lg.loan_id = ?
                       ORDER BY lg.status, lg.created_at";
$guarantor_list = executeQuery($guarantor_list_sql, "i", [$id]);

// Calculate eligibility with new rules
$membership_eligible = $loan['membership_months'] >= 6;
$guarantor_coverage = $guarantor_data['total_guaranteed'] ?? 0;
$guarantor_count = $guarantor_data['approved_count'] ?? 0;
$self_guarantee_limit = $loan['member_deposits'] * 3;
$self_guarantee_eligible = $self_guarantee_limit >= $loan['principal_amount'];

// Determine if loan can be approved
$can_approve = false;
$approval_method = '';
$approval_details = [];

if (!$membership_eligible) {
    $approval_details[] = "❌ Membership duration: {$loan['membership_months']} months (need 6+)";
} else {
    $approval_details[] = "✅ Membership duration: {$loan['membership_months']} months";
}

// Check guarantor coverage
if ($guarantor_coverage >= $loan['principal_amount']) {
    $can_approve = true;
    $approval_method = 'guarantor';
    $approval_details[] = "✅ Guarantor coverage: " . formatCurrency($guarantor_coverage) . " (Fully covered)";
} else {
    $approval_details[] = "❌ Guarantor coverage: " . formatCurrency($guarantor_coverage) . " (Need " . formatCurrency($loan['principal_amount'] - $guarantor_coverage) . " more)";
}

// Check self-guarantee
if ($self_guarantee_eligible) {
    $can_approve = true;
    $approval_method = 'self';
    $approval_details[] = "✅ Self-guarantee: Deposits " . formatCurrency($loan['member_deposits']) . " × 3 = " . formatCurrency($self_guarantee_limit) . " (Sufficient)";
} else {
    $approval_details[] = "❌ Self-guarantee: Need deposits of " . formatCurrency($loan['principal_amount'] / 3) . " (Current: " . formatCurrency($loan['member_deposits']) . ")";
}

// Final eligibility
$eligible = $membership_eligible && $can_approve;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'];

    if ($action == 'approve') {
        // Update loan status
        $updateSql = "UPDATE loans SET status = 'approved', approval_date = CURDATE(), approved_by = ? WHERE id = ?";
        executeQuery($updateSql, "ii", [getCurrentUserId(), $id]);

        // Create amortization schedule
        createAmortizationSchedule($id, $loan['principal_amount'], $loan['interest_rate'], $loan['duration_months']);

        // Send notification to member
        $approval_message = "Dear {$loan['full_name']}, your loan of " . formatCurrency($loan['principal_amount']) . " has been APPROVED. ";
        if ($approval_method == 'self') {
            $approval_message .= "This loan was approved based on your savings balance (self-guarantee). ";
        } else {
            $approval_message .= "The loan is guaranteed by " . $guarantor_count . " guarantor(s). ";
        }
        $approval_message .= "You will be contacted for disbursement.";

        sendNotification($loan['member_id'], 'Loan Approved', $approval_message, 'sms');

        logAudit('APPROVE', 'loans', $id, null, $loan);
        $_SESSION['success'] = 'Loan approved successfully via ' . ($approval_method == 'self' ? 'self-guarantee' : 'guarantor coverage');
    } elseif ($action == 'reject') {
        $rejection_reason = $_POST['rejection_reason'] ?? '';
        $updateSql = "UPDATE loans SET status = 'rejected' WHERE id = ?";
        executeQuery($updateSql, "i", [$id]);

        // Send notification to member
        $rejection_message = "Dear {$loan['full_name']}, your loan application has been reviewed and was not approved. ";
        if (!empty($rejection_reason)) {
            $rejection_message .= "Reason: " . $rejection_reason;
        }
        sendNotification($loan['member_id'], 'Loan Update', $rejection_message, 'sms');

        logAudit('REJECT', 'loans', $id, null, $loan);
        $_SESSION['success'] = 'Loan rejected';
    }

    header('Location: index.php');
    exit();
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
function createAmortizationSchedule($loan_id, $principal, $rate, $months)
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

        executeQuery($sql, "iisdddd", [
            $loan_id,
            $i,
            $due_date->format('Y-m-d'),
            $principal_paid,
            $interest,
            $monthly_payment,
            $balance
        ]);

        $due_date->modify('+1 month');
    }
}
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
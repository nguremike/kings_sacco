<?php
// modules/loans/approvals.php
require_once '../../config/config.php';
requireRole('admin');

$page_title = 'Loan Approvals';

// Get system settings
$settings = getLoanSettings();

// Handle loan approval/rejection
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $loan_id = $_POST['loan_id'];
    $action = $_POST['action'];
    $remarks = $_POST['remarks'] ?? '';

    processLoanAction($loan_id, $action, $remarks, $settings);
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

function processLoanAction($loan_id, $action, $remarks, $settings)
{
    $conn = getConnection();
    $conn->begin_transaction();

    try {
        if ($action == 'approve') {
            // Get loan details
            $loan = executeQuery("SELECT * FROM loans WHERE id = ?", "i", [$loan_id])->fetch_assoc();

            // Record fees
            if ($loan['processing_fee'] > 0) {
                recordLoanFee($conn, $loan_id, $loan['member_id'], 'processing_fee', $loan['processing_fee']);
            }

            if ($loan['insurance_fee'] > 0) {
                recordLoanFee($conn, $loan_id, $loan['member_id'], 'insurance_fee', $loan['insurance_fee']);
            }

            // Update loan status
            $sql = "UPDATE loans SET status = 'approved', approval_date = CURDATE(), approved_by = ?, remarks = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("isi", getCurrentUserId(), $remarks, $loan_id);
            $stmt->execute();

            // Create amortization schedule
            createAmortizationSchedule($conn, $loan_id, $loan['principal_amount'], $loan['interest_rate'], $loan['duration_months']);

            logAudit('APPROVE', 'loans', $loan_id, ['status' => 'pending'], ['status' => 'approved']);
            $_SESSION['success'] = 'Loan approved successfully';
        } elseif ($action == 'reject') {
            $sql = "UPDATE loans SET status = 'rejected', approved_by = ?, remarks = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("isi", getCurrentUserId(), $remarks, $loan_id);
            $stmt->execute();

            logAudit('REJECT', 'loans', $loan_id, ['status' => 'pending'], ['status' => 'rejected']);
            $_SESSION['success'] = 'Loan rejected';
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Action failed: ' . $e->getMessage();
    }

    $conn->close();
    header('Location: approvals.php');
    exit();
}

function recordLoanFee($conn, $loan_id, $member_id, $fee_type, $amount)
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

// Get pending loans with enhanced eligibility
$pending_sql = "SELECT l.*, 
                m.full_name, m.member_no, m.date_joined,
                lp.product_name, lp.interest_rate as product_rate,
                lp.min_savings_balance, lp.max_loans_active,
                lp.guarantor_required, lp.min_guarantors,
                (SELECT COUNT(*) FROM loan_guarantors WHERE loan_id = l.id) as guarantor_count,
                (SELECT COUNT(*) FROM loan_guarantors WHERE loan_id = l.id AND status = 'approved') as approved_guarantors,
                (SELECT COALESCE(SUM(guaranteed_amount), 0) FROM loan_guarantors WHERE loan_id = l.id AND status = 'approved') as total_guaranteed,
                (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE -amount END), 0) 
                 FROM deposits WHERE member_id = l.member_id) as member_savings,
                TIMESTAMPDIFF(MONTH, m.date_joined, CURDATE()) as membership_months
                FROM loans l
                JOIN members m ON l.member_id = m.id
                JOIN loan_products lp ON l.product_id = lp.id
                WHERE l.status IN ('pending', 'guarantor_pending')
                ORDER BY l.created_at ASC";
$pending_loans = executeQuery($pending_sql);

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Loan Approvals</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Loans</a></li>
                <li class="breadcrumb-item active">Approvals</li>
            </ul>
        </div>
        <div class="col-auto">
            <button class="btn btn-primary" onclick="window.location.reload()">
                <i class="fas fa-sync-alt me-2"></i>Refresh
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

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="stats-card warning">
            <div class="stats-icon">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo number_format($stats['pending_count'] ?? 0); ?></h3>
                <p>Pending Review</p>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stats-card info">
            <div class="stats-icon">
                <i class="fas fa-handshake"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo number_format($stats['guarantor_pending_count'] ?? 0); ?></h3>
                <p>Guarantor Pending</p>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stats-card success">
            <div class="stats-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo number_format($stats['approved_this_month'] ?? 0); ?></h3>
                <p>Approved This Month</p>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stats-card danger">
            <div class="stats-icon">
                <i class="fas fa-times-circle"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo number_format($stats['rejected_this_month'] ?? 0); ?></h3>
                <p>Rejected This Month</p>
            </div>
        </div>
    </div>
</div>

<!-- Pending Loans Section -->
<div class="card mb-4">
    <div class="card-header bg-warning">
        <h5 class="card-title mb-0">Loans Pending Approval</h5>
        <div class="card-tools">
            <span class="badge bg-danger">Total Amount: <?php echo formatCurrency($stats['total_pending_amount'] ?? 0); ?></span>
        </div>
    </div>
    <div class="card-body">
        <?php if ($pending_loans->num_rows > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover datatable">
                    <thead>
                        <tr>
                            <th>Loan No</th>
                            <th>Member</th>
                            <th>Product</th>
                            <th>Principal</th>
                            <th>Duration</th>
                            <th>Application Date</th>
                            <th>Status</th>
                            <th>Guarantors</th>
                            <th>Self-Guarantee</th>
                            <th>Eligibility</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($loan = $pending_loans->fetch_assoc()):
                            // Calculate eligibility with new rules
                            $eligible = false;
                            $eligibility_reason = '';
                            $eligibility_class = 'danger';

                            // Rule 1: Check membership duration (6 months minimum)
                            $membership_eligible = $loan['membership_months'] >= 6;

                            // Rule 2: Check guarantor coverage
                            $guarantor_coverage = $loan['total_guaranteed'];
                            $guarantor_count = $loan['approved_guarantors'];

                            // Rule 3: Check self-guarantee (deposits * 3 >= loan amount)
                            $self_guarantee_limit = $loan['member_deposits'] * 3;
                            $self_guarantee_eligible = $self_guarantee_limit >= $loan['principal_amount'];

                            // New approval logic:
                            // Can approve if:
                            // 1. Membership duration >= 6 months AND
                            // 2. (Guarantor coverage >= loan amount) OR (Self-guarantee eligible)
                            if ($membership_eligible) {
                                if ($guarantor_coverage >= $loan['principal_amount']) {
                                    $eligible = true;
                                    $eligibility_reason = 'Fully guaranteed by ' . $guarantor_count . ' guarantor(s)';
                                    $eligibility_class = 'success';
                                } elseif ($self_guarantee_eligible) {
                                    $eligible = true;
                                    $eligibility_reason = 'Self-guaranteed (Deposits: ' . formatCurrency($loan['member_deposits']) . ' x 3 = ' . formatCurrency($self_guarantee_limit) . ')';
                                    $eligibility_class = 'info';
                                } else {
                                    $eligibility_reason = 'Insufficient guarantee. Need either:';
                                    $eligibility_reason .= '<br>- Guarantors: Need ' . formatCurrency($loan['principal_amount'] - $guarantor_coverage) . ' more';
                                    $eligibility_reason .= '<br>- Self-guarantee: Need deposits of ' . formatCurrency($loan['principal_amount'] / 3) . ' (currently ' . formatCurrency($loan['member_deposits']) . ')';
                                }
                            } else {
                                $eligibility_reason = 'Member since ' . $loan['membership_months'] . ' months (need 6+)';
                            }

                            // Calculate self-guarantee progress
                            $self_guarantee_progress = $loan['member_deposits'] > 0 ? min(($loan['member_deposits'] / ($loan['principal_amount'] / 3)) * 100, 100) : 0;
                        ?>
                            <tr>
                                <td>
                                    <span class="badge bg-primary"><?php echo $loan['loan_no']; ?></span>
                                </td>
                                <td>
                                    <a href="../members/view.php?id=<?php echo $loan['member_id']; ?>" class="text-decoration-none">
                                        <strong><?php echo $loan['full_name']; ?></strong>
                                        <br>
                                        <small class="text-muted"><?php echo $loan['member_no']; ?></small>
                                    </a>
                                </td>
                                <td>
                                    <?php echo $loan['product_name']; ?>
                                    <br>
                                    <small class="text-muted"><?php echo $loan['interest_rate']; ?>% p.a.</small>
                                </td>
                                <td>
                                    <strong><?php echo formatCurrency($loan['principal_amount']); ?></strong>
                                    <br>
                                    <small>Total: <?php echo formatCurrency($loan['total_amount']); ?></small>
                                </td>
                                <td><?php echo $loan['duration_months']; ?> months</td>
                                <td><?php echo formatDate($loan['application_date']); ?></td>
                                <td>
                                    <?php if ($loan['status'] == 'guarantor_pending'): ?>
                                        <span class="badge bg-info">Guarantor Pending</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning">Pending Review</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="text-center">
                                        <span class="badge bg-<?php echo $guarantor_coverage >= $loan['principal_amount'] ? 'success' : 'warning'; ?>">
                                            <?php echo $loan['approved_guarantors']; ?>/3
                                        </span>
                                        <br>
                                        <small><?php echo formatCurrency($guarantor_coverage); ?></small>
                                        <?php if ($guarantor_coverage < $loan['principal_amount']): ?>
                                            <br>
                                            <small class="text-danger">Short: <?php echo formatCurrency($loan['principal_amount'] - $guarantor_coverage); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="text-center">
                                        <?php if ($self_guarantee_eligible): ?>
                                            <span class="badge bg-success">Eligible</span>
                                            <br>
                                            <small><?php echo formatCurrency($loan['member_deposits']); ?> deposits</small>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Not Eligible</span>
                                            <br>
                                            <div class="progress mt-1" style="height: 5px; width: 80px; margin: 0 auto;">
                                                <div class="progress-bar bg-info"
                                                    role="progressbar"
                                                    style="width: <?php echo $self_guarantee_progress; ?>%;"
                                                    aria-valuenow="<?php echo $self_guarantee_progress; ?>"
                                                    aria-valuemin="0"
                                                    aria-valuemax="100">
                                                </div>
                                            </div>
                                            <small><?php echo formatCurrency($loan['member_deposits']); ?> / <?php echo formatCurrency($loan['principal_amount'] / 3); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($eligible): ?>
                                        <span class="badge bg-<?php echo $eligibility_class; ?>">
                                            <i class="fas fa-check"></i> Eligible
                                        </span>
                                        <button type="button" class="btn btn-sm btn-link p-0" onclick="showEligibilityDetails('<?php echo htmlspecialchars($eligibility_reason); ?>')">
                                            <i class="fas fa-info-circle"></i>
                                        </button>
                                    <?php else: ?>
                                        <span class="badge bg-danger">
                                            <i class="fas fa-exclamation-triangle"></i> Not Eligible
                                        </span>
                                        <button type="button" class="btn btn-sm btn-link p-0" onclick="showEligibilityDetails('<?php echo htmlspecialchars($eligibility_reason); ?>')">
                                            <i class="fas fa-info-circle"></i>
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <button type="button" class="btn btn-sm btn-<?php echo $eligible ? 'success' : 'secondary'; ?>"
                                            onclick="showApproveModal(<?php echo $loan['id']; ?>, '<?php echo $loan['full_name']; ?>', <?php echo $loan['principal_amount']; ?>, <?php echo $loan['interest_amount']; ?>, <?php echo $loan['duration_months']; ?>)"
                                            <?php echo !$eligible ? 'disabled' : ''; ?>>
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                        <button type="button" class="btn btn-sm btn-info" onclick="showModifyModal(<?php echo $loan['id']; ?>, '<?php echo $loan['full_name']; ?>', <?php echo $loan['principal_amount']; ?>, <?php echo $loan['interest_amount']; ?>, <?php echo $loan['duration_months']; ?>, <?php echo $loan['interest_rate']; ?>)">
                                            <i class="fas fa-edit"></i> Modify
                                        </button>
                                        <button type="button" class="btn btn-sm btn-danger" onclick="showRejectModal(<?php echo $loan['id']; ?>, '<?php echo $loan['full_name']; ?>')">
                                            <i class="fas fa-times"></i> Reject
                                        </button>
                                        <a href="view.php?id=<?php echo $loan['id']; ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-center py-4">
                <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                <h5>No Pending Loan Approvals</h5>
                <p class="text-muted">All loan applications have been processed.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Recently Processed -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title">Recently Processed (Last 30 Days)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Loan No</th>
                        <th>Member</th>
                        <th>Principal</th>
                        <th>Status</th>
                        <th>Processed Date</th>
                        <th>Processed By</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($loan = $recent_loans->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo $loan['loan_no']; ?></td>
                            <td>
                                <?php echo $loan['full_name']; ?>
                                <br>
                                <small><?php echo $loan['member_no']; ?></small>
                            </td>
                            <td><?php echo formatCurrency($loan['principal_amount']); ?></td>
                            <td>
                                <?php if ($loan['status'] == 'approved'): ?>
                                    <span class="badge bg-success">Approved</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Rejected</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo formatDate($loan['created_at']); ?></td>
                            <td><?php echo $loan['approved_by_name'] ?? 'System'; ?></td>
                            <td>
                                <a href="view.php?id=<?php echo $loan['id']; ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-eye"></i> View
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Approve Modal -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Approve Loan</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="loan_id" id="approve_loan_id">
                    <input type="hidden" name="action" value="approve">

                    <div class="text-center mb-3">
                        <i class="fas fa-check-circle fa-3x text-success"></i>
                    </div>

                    <p>Are you sure you want to approve the loan for <strong id="approve_member_name"></strong>?</p>

                    <div class="card bg-light mb-3">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-6">
                                    <p class="mb-1">Principal:</p>
                                    <p class="mb-1">Interest:</p>
                                    <p class="mb-1">Total:</p>
                                    <p class="mb-1">Duration:</p>
                                </div>
                                <div class="col-6 text-end">
                                    <p class="mb-1"><strong id="approve_principal"></strong></p>
                                    <p class="mb-1"><strong id="approve_interest"></strong></p>
                                    <p class="mb-1"><strong id="approve_total"></strong></p>
                                    <p class="mb-1"><strong id="approve_duration"></strong> months</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Next Steps:</strong>
                        <ul class="mb-0 mt-2">
                            <li>Loan will be marked as approved</li>
                            <li>Member will be notified via SMS</li>
                            <li>Loan will be ready for disbursement</li>
                        </ul>
                    </div>

                    <div class="mb-3">
                        <label for="approve_remarks" class="form-label">Approval Remarks (Optional)</label>
                        <textarea class="form-control" id="approve_remarks" name="remarks" rows="2"
                            placeholder="Any additional notes..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check me-2"></i>Approve Loan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modify Modal -->
<div class="modal fade" id="modifyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="" class="needs-validation" novalidate>
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">Modify & Approve Loan</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="loan_id" id="modify_loan_id">
                    <input type="hidden" name="action" value="modify">

                    <div class="text-center mb-3">
                        <i class="fas fa-edit fa-3x text-info"></i>
                    </div>

                    <p>Modify loan terms for <strong id="modify_member_name"></strong></p>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="approved_amount" class="form-label">Principal Amount (KES)</label>
                            <input type="number" class="form-control" id="approved_amount" name="approved_amount"
                                min="1000" step="1000" required onchange="calculateModifiedTotal()">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="approved_interest" class="form-label">Interest Amount (KES)</label>
                            <input type="number" class="form-control" id="approved_interest" name="approved_interest"
                                min="0" step="100" required onchange="calculateModifiedTotal()">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="approved_duration" class="form-label">Duration (Months)</label>
                            <input type="number" class="form-control" id="approved_duration" name="approved_duration"
                                min="1" max="36" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="approved_rate" class="form-label">Interest Rate (%)</label>
                            <input type="number" class="form-control" id="approved_rate" name="approved_rate"
                                step="0.1" readonly>
                        </div>
                    </div>

                    <div class="card bg-light mb-3">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-6">
                                    <p class="mb-1">Total Repayable:</p>
                                    <p class="mb-1">Monthly Installment:</p>
                                </div>
                                <div class="col-6 text-end">
                                    <p class="mb-1"><strong id="modify_total"></strong></p>
                                    <p class="mb-1"><strong id="modify_monthly"></strong></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="modify_remarks" class="form-label">Modification Remarks</label>
                        <textarea class="form-control" id="modify_remarks" name="remarks" rows="2"
                            placeholder="Reason for modification..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info text-white">
                        <i class="fas fa-save me-2"></i>Save & Approve
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Reject Loan Application</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="loan_id" id="reject_loan_id">
                    <input type="hidden" name="action" value="reject">

                    <div class="text-center mb-3">
                        <i class="fas fa-times-circle fa-3x text-danger"></i>
                    </div>

                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        This action cannot be undone. The member will be notified.
                    </div>

                    <p>Please provide a reason for rejecting <strong id="reject_member_name"></strong>'s loan application:</p>

                    <div class="mb-3">
                        <label for="reject_reason" class="form-label">Rejection Reason <span class="text-danger">*</span></label>
                        <select class="form-control mb-2" id="reject_reason_select" onchange="updateRejectReason()">
                            <option value="">-- Select Reason --</option>
                            <option value="Insufficient guarantors">Insufficient guarantors</option>
                            <option value="Low savings balance - cannot self-guarantee">Low savings balance - cannot self-guarantee</option>
                            <option value="Poor credit history">Poor credit history</option>
                            <option value="Membership too recent">Membership too recent (need 6+ months)</option>
                            <option value="Existing loan default">Existing loan default</option>
                            <option value="Incomplete documentation">Incomplete documentation</option>
                            <option value="Exceeds borrowing limit">Exceeds borrowing limit</option>
                            <option value="Other">Other (specify below)</option>
                        </select>
                        <textarea class="form-control" id="reject_remarks" name="remarks" rows="3" required
                            placeholder="Provide detailed reason for rejection..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-times me-2"></i>Reject Application
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Show approve modal
    function showApproveModal(loanId, memberName, principal, interest, duration) {
        document.getElementById('approve_loan_id').value = loanId;
        document.getElementById('approve_member_name').textContent = memberName;
        document.getElementById('approve_principal').innerHTML = formatCurrency(principal);
        document.getElementById('approve_interest').innerHTML = formatCurrency(interest);
        document.getElementById('approve_total').innerHTML = formatCurrency(principal + interest);
        document.getElementById('approve_duration').innerHTML = duration;

        var modal = new bootstrap.Modal(document.getElementById('approveModal'));
        modal.show();
    }

    // Show modify modal
    function showModifyModal(loanId, memberName, principal, interest, duration, rate) {
        document.getElementById('modify_loan_id').value = loanId;
        document.getElementById('modify_member_name').textContent = memberName;
        document.getElementById('approved_amount').value = principal;
        document.getElementById('approved_interest').value = interest;
        document.getElementById('approved_duration').value = duration;
        document.getElementById('approved_rate').value = rate;

        calculateModifiedTotal();

        var modal = new bootstrap.Modal(document.getElementById('modifyModal'));
        modal.show();
    }

    // Calculate modified total
    function calculateModifiedTotal() {
        var principal = parseFloat(document.getElementById('approved_amount').value) || 0;
        var interest = parseFloat(document.getElementById('approved_interest').value) || 0;
        var duration = parseFloat(document.getElementById('approved_duration').value) || 1;

        var total = principal + interest;
        var monthly = total / duration;

        document.getElementById('modify_total').innerHTML = formatCurrency(total);
        document.getElementById('modify_monthly').innerHTML = formatCurrency(monthly);
    }

    // Show reject modal
    function showRejectModal(loanId, memberName) {
        document.getElementById('reject_loan_id').value = loanId;
        document.getElementById('reject_member_name').textContent = memberName;

        var modal = new bootstrap.Modal(document.getElementById('rejectModal'));
        modal.show();
    }

    // Update reject reason textarea
    function updateRejectReason() {
        var select = document.getElementById('reject_reason_select');
        var textarea = document.getElementById('reject_remarks');

        if (select.value && select.value != 'Other') {
            textarea.value = select.value;
        } else {
            textarea.value = '';
        }
    }

    // Show eligibility details
    function showEligibilityDetails(details) {
        Swal.fire({
            title: 'Eligibility Details',
            html: '<div class="text-start">' + details.replace(/\n/g, '<br>') + '</div>',
            icon: 'info',
            confirmButtonColor: '#3085d6'
        });
    }

    // Format currency
    function formatCurrency(amount) {
        return 'KES ' + parseFloat(amount).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
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

    // Initialize tooltips
    document.addEventListener('DOMContentLoaded', function() {
        var tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        tooltips.forEach(function(tooltip) {
            new bootstrap.Tooltip(tooltip);
        });
    });

    // Auto-refresh pending count (optional)
    setTimeout(function() {
        location.reload();
    }, 300000); // Refresh every 5 minutes
</script>

<style>
    .stats-card.info {
        background: linear-gradient(135deg, #17a2b8, #138496);
    }

    .stats-card.info .stats-icon {
        background: rgba(255, 255, 255, 0.2);
        color: white;
    }

    .stats-card.info .stats-content h3,
    .stats-card.info .stats-content p {
        color: white;
    }

    .btn-group .btn {
        margin-right: 2px;
    }

    .btn-group .btn:last-child {
        margin-right: 0;
    }

    .table td {
        vertical-align: middle;
    }

    .modal-header.bg-success,
    .modal-header.bg-danger,
    .modal-header.bg-info {
        color: white;
    }

    .modal-header.bg-success .btn-close,
    .modal-header.bg-danger .btn-close,
    .modal-header.bg-info .btn-close {
        filter: brightness(0) invert(1);
    }

    .progress {
        background-color: #e9ecef;
        border-radius: 10px;
    }

    .progress-bar {
        border-radius: 10px;
    }

    /* Responsive */
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
    }

    .eligibility-badge {
        cursor: help;
    }
</style>

<?php include '../../includes/footer.php'; ?>
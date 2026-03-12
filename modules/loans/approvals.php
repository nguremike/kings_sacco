<?php
//show php errors
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);




require_once '../../config/config.php';
requireRole('admin'); // Only admin and loan officers can approve loans

$page_title = 'Loan Approvals';

// Handle loan approval/rejection
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $loan_id = $_POST['loan_id'];
    $action = $_POST['action'];
    $remarks = $_POST['remarks'] ?? '';
    $approved_amount = $_POST['approved_amount'] ?? null;
    $approved_interest = $_POST['approved_interest'] ?? null;
    $approved_duration = $_POST['approved_duration'] ?? null;

    $conn = getConnection();
    $conn->begin_transaction();

    try {
        if ($action == 'approve') {
            // Update loan status to approved
            $sql = "UPDATE loans SET status = 'approved', approval_date = CURDATE(), approved_by = ?, remarks = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("isi", getCurrentUserId(), $remarks, $loan_id);
            $stmt->execute();

            // Get loan details for notification
            $loan_sql = "SELECT l.*, m.full_name, m.phone, m.member_no, lp.product_name 
                        FROM loans l 
                        JOIN members m ON l.member_id = m.id 
                        JOIN loan_products lp ON l.product_id = lp.id 
                        WHERE l.id = ?";
            $loan_result = $conn->query($loan_sql);
            $loan = $loan_result->fetch_assoc();

            // Send notification to member
            $message = "Dear {$loan['full_name']}, your loan application of KES " . number_format($loan['principal_amount']) . " has been APPROVED. You will be contacted for disbursement.";
            sendNotification($loan['member_id'], 'Loan Approved', $message, 'sms');

            logAudit('APPROVE', 'loans', $loan_id, ['status' => 'pending'], ['status' => 'approved']);
            $_SESSION['success'] = 'Loan approved successfully';
        } elseif ($action == 'reject') {
            // Update loan status to rejected
            $sql = "UPDATE loans SET status = 'rejected', approved_by = ?, remarks = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("isi", getCurrentUserId(), $remarks, $loan_id);
            $stmt->execute();

            // Get loan details for notification
            $loan_sql = "SELECT l.*, m.full_name, m.phone, m.member_no 
                        FROM loans l 
                        JOIN members m ON l.member_id = m.id 
                        WHERE l.id = ?";
            $loan_result = $conn->query($loan_sql);
            $loan = $loan_result->fetch_assoc();

            // Send notification to member
            $message = "Dear {$loan['full_name']}, your loan application has been reviewed and was not approved at this time. Remarks: {$remarks}";
            sendNotification($loan['member_id'], 'Loan Application Update', $message, 'sms');

            logAudit('REJECT', 'loans', $loan_id, ['status' => 'pending'], ['status' => 'rejected']);
            $_SESSION['success'] = 'Loan rejected';
        } elseif ($action == 'modify') {
            // Update loan with modified terms
            $sql = "UPDATE loans SET principal_amount = ?, interest_amount = ?, total_amount = ?, duration_months = ?, 
                    status = 'approved', approval_date = CURDATE(), approved_by = ?, remarks = ? WHERE id = ?";
            $total_amount = $approved_amount + $approved_interest;
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("dddiisi", $approved_amount, $approved_interest, $total_amount, $approved_duration, getCurrentUserId(), $remarks, $loan_id);
            $stmt->execute();

            logAudit('MODIFY', 'loans', $loan_id, null, $_POST);
            $_SESSION['success'] = 'Loan modified and approved successfully';
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

// Get pending loans with guarantor status
$pending_sql = "SELECT l.*, 
                m.full_name, m.member_no, m.phone, m.email, m.date_joined,
                lp.product_name, lp.interest_rate as product_rate, lp.max_amount,
                (SELECT COUNT(*) FROM loan_guarantors WHERE loan_id = l.id) as guarantor_count,
                (SELECT COUNT(*) FROM loan_guarantors WHERE loan_id = l.id AND status = 'approved') as approved_guarantors,
                (SELECT COALESCE(SUM(guaranteed_amount), 0) FROM loan_guarantors WHERE loan_id = l.id AND status = 'approved') as total_guaranteed,
                (SELECT COALESCE(SUM(amount), 0) FROM deposits WHERE member_id = l.member_id AND transaction_type = 'deposit') as member_deposits,
                TIMESTAMPDIFF(MONTH, m.date_joined, CURDATE()) as membership_months
                FROM loans l
                JOIN members m ON l.member_id = m.id
                JOIN loan_products lp ON l.product_id = lp.id
                WHERE l.status IN ('pending', 'guarantor_pending')
                ORDER BY 
                    CASE l.status 
                        WHEN 'guarantor_pending' THEN 1 
                        WHEN 'pending' THEN 2 
                        ELSE 3 
                    END,
                    l.created_at ASC";
$pending_loans = executeQuery($pending_sql);

// Get recently approved/rejected loans (last 30 days)
$recent_sql = "SELECT l.*, m.full_name, m.member_no, u.full_name as approved_by_name,
               (SELECT COUNT(*) FROM loan_guarantors WHERE loan_id = l.id) as guarantor_count
               FROM loans l
               JOIN members m ON l.member_id = m.id
               LEFT JOIN users u ON l.approved_by = u.id
               WHERE l.status IN ('approved', 'rejected')
               AND l.updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
               ORDER BY l.updated_at DESC
               LIMIT 20";
$recent_loans = executeQuery($recent_sql);

// Get statistics
$stats_sql = "SELECT 
              SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
              SUM(CASE WHEN status = 'guarantor_pending' THEN 1 ELSE 0 END) as guarantor_pending_count,
              SUM(CASE WHEN status = 'approved' AND MONTH(approval_date) = MONTH(NOW()) THEN 1 ELSE 0 END) as approved_this_month,
              SUM(CASE WHEN status = 'rejected' AND MONTH(updated_at) = MONTH(NOW()) THEN 1 ELSE 0 END) as rejected_this_month,
              COALESCE(SUM(CASE WHEN status IN ('pending', 'guarantor_pending') THEN principal_amount ELSE 0 END), 0) as total_pending_amount
              FROM loans";
$stats_result = executeQuery($stats_sql);
$stats = $stats_result->fetch_assoc();

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
                            <th>Eligibility</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($loan = $pending_loans->fetch_assoc()):
                            // Calculate eligibility
                            $eligible = true;
                            $eligibility_messages = [];

                            // Check membership duration (6 months minimum)
                            if ($loan['membership_months'] < 6) {
                                $eligible = false;
                                $eligibility_messages[] = "Member since {$loan['membership_months']} months (need 6+)";
                            }

                            // Check guarantors (minimum 3 approved)
                            if ($loan['approved_guarantors'] < 3) {
                                $eligible = false;
                                $eligibility_messages[] = "Need " . (3 - $loan['approved_guarantors']) . " more guarantors";
                            }

                            // Check guarantor coverage (should cover loan amount)
                            if ($loan['total_guaranteed'] < $loan['principal_amount']) {
                                $eligible = false;
                                $shortfall = $loan['principal_amount'] - $loan['total_guaranteed'];
                                $eligibility_messages[] = "Guarantor shortfall: " . formatCurrency($shortfall);
                            }

                            // Check deposit/shares ratio (optional)
                            $required_deposit = $loan['principal_amount'] * 0.1; // 10% of loan amount
                            if ($loan['member_deposits'] < $required_deposit) {
                                $eligible = false;
                                $eligibility_messages[] = "Low savings (need min " . formatCurrency($required_deposit) . ")";
                            }
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
                                        <span class="badge bg-<?php echo $loan['approved_guarantors'] >= 3 ? 'success' : 'danger'; ?>">
                                            <?php echo $loan['approved_guarantors']; ?>/3
                                        </span>
                                        <br>
                                        <small><?php echo formatCurrency($loan['total_guaranteed']); ?></small>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($eligible): ?>
                                        <span class="badge bg-success"><i class="fas fa-check"></i> Eligible</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger" title="<?php echo implode('\n', $eligibility_messages); ?>">
                                            <i class="fas fa-exclamation-triangle"></i> Issues
                                        </span>
                                        <button type="button" class="btn btn-sm btn-link" onclick="showEligibilityIssues(<?php echo htmlspecialchars(json_encode($eligibility_messages)); ?>)">
                                            <i class="fas fa-info-circle"></i>
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <button type="button" class="btn btn-sm btn-success" onclick="showApproveModal(<?php echo $loan['id']; ?>, '<?php echo $loan['full_name']; ?>', <?php echo $loan['principal_amount']; ?>, <?php echo $loan['interest_amount']; ?>, <?php echo $loan['duration_months']; ?>)"
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
                            <td><?php echo formatDate($loan['updated_at']); ?></td>
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
                            <option value="Low savings balance">Low savings balance</option>
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

    // Show eligibility issues
    function showEligibilityIssues(issues) {
        var issuesList = '';
        issues.forEach(function(issue) {
            issuesList += '<li>' + issue + '</li>';
        });

        Swal.fire({
            title: 'Eligibility Issues',
            html: '<ul class="text-start">' + issuesList + '</ul>',
            icon: 'warning'
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
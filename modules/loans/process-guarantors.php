<?php
require_once '../../config/config.php';
requireRole('admin'); // Only admin can process guarantors

$loan_id = $_GET['loan_id'] ?? 0;

// Get loan details
$loan_sql = "SELECT l.*, m.full_name, m.member_no, m.phone, m.email,
             lp.product_name, lp.interest_rate
             FROM loans l
             JOIN members m ON l.member_id = m.id
             JOIN loan_products lp ON l.product_id = lp.id
             WHERE l.id = ? AND l.status = 'guarantor_pending'";
$loan_result = executeQuery($loan_sql, "i", [$loan_id]);

if ($loan_result->num_rows == 0) {
    $_SESSION['error'] = 'Loan not found or not in guarantor pending status';
    header('Location: index.php');
    exit();
}

$loan = $loan_result->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    $guarantor_ids = $_POST['guarantor_ids'] ?? [];
    $remarks = $_POST['remarks'] ?? '';

    $conn = getConnection();
    $conn->begin_transaction();

    try {
        if ($action == 'approve_all') {
            // Approve all selected guarantors
            foreach ($guarantor_ids as $guarantor_id) {
                $update_sql = "UPDATE loan_guarantors SET status = 'approved', approval_date = CURDATE() WHERE id = ? AND loan_id = ?";
                $stmt = $conn->prepare($update_sql);
                $stmt->bind_param("ii", $guarantor_id, $loan_id);
                $stmt->execute();

                // Get guarantor details for notification
                $guarantor_sql = "SELECT lg.*, m.full_name, m.phone 
                                  FROM loan_guarantors lg
                                  JOIN members m ON lg.guarantor_member_id = m.id
                                  WHERE lg.id = ?";
                $g_stmt = $conn->prepare($guarantor_sql);
                $g_stmt->bind_param("i", $guarantor_id);
                $g_stmt->execute();
                $g_result = $g_stmt->get_result();
                $guarantor = $g_result->fetch_assoc();

                // Send notification to guarantor
                if (!empty($guarantor['phone'])) {
                    $message = "Dear {$guarantor['full_name']}, you have been approved as a guarantor for loan {$loan['loan_no']} of KES " . number_format($loan['principal_amount']) . ". Thank you.";
                    sendNotification($guarantor['guarantor_member_id'], 'Guarantor Approved', $message, 'sms');
                }
            }

            // Check if we have at least 3 approved guarantors - FIXED QUERY
            $check_sql = "SELECT COUNT(*) as count FROM loan_guarantors WHERE loan_id = ? AND status = 'approved'";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("i", $loan_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $approved_count = $check_result->fetch_assoc()['count'];

            if ($approved_count >= 3) {
                // Update loan status to pending approval
                $update_loan_sql = "UPDATE loans SET status = 'pending' WHERE id = ?";
                $stmt = $conn->prepare($update_loan_sql);
                $stmt->bind_param("i", $loan_id);
                $stmt->execute();

                // Send notification to member
                $message = "Dear {$loan['full_name']}, your loan application has met the guarantor requirements and is now ready for final approval.";
                sendNotification($loan['member_id'], 'Loan Update', $message, 'sms');
            }

            $_SESSION['success'] = 'Guarantors approved successfully';
        } elseif ($action == 'reject_all') {
            // Reject all selected guarantors
            foreach ($guarantor_ids as $guarantor_id) {
                $update_sql = "UPDATE loan_guarantors SET status = 'rejected' WHERE id = ? AND loan_id = ?";
                $stmt = $conn->prepare($update_sql);
                $stmt->bind_param("ii", $guarantor_id, $loan_id);
                $stmt->execute();

                // Get guarantor details for notification
                $guarantor_sql = "SELECT lg.*, m.full_name, m.phone 
                                  FROM loan_guarantors lg
                                  JOIN members m ON lg.guarantor_member_id = m.id
                                  WHERE lg.id = ?";
                $g_stmt = $conn->prepare($guarantor_sql);
                $g_stmt->bind_param("i", $guarantor_id);
                $g_stmt->execute();
                $g_result = $g_stmt->get_result();
                $guarantor = $g_result->fetch_assoc();

                // Send notification to guarantor
                if (!empty($guarantor['phone'])) {
                    $message = "Dear {$guarantor['full_name']}, your guarantor application for loan {$loan['loan_no']} has been reviewed and was not approved. Remarks: {$remarks}";
                    sendNotification($guarantor['guarantor_member_id'], 'Guarantor Update', $message, 'sms');
                }
            }

            $_SESSION['success'] = 'Guarantors rejected';
        } elseif ($action == 'approve_selected') {
            // Approve only selected guarantors
            foreach ($guarantor_ids as $guarantor_id) {
                $update_sql = "UPDATE loan_guarantors SET status = 'approved', approval_date = CURDATE() WHERE id = ? AND loan_id = ?";
                $stmt = $conn->prepare($update_sql);
                $stmt->bind_param("ii", $guarantor_id, $loan_id);
                $stmt->execute();
            }

            // Check if we now have at least 3 approved guarantors - FIXED QUERY
            $check_sql = "SELECT COUNT(*) as count FROM loan_guarantors WHERE loan_id = ? AND status = 'approved'";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("i", $loan_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $approved_count = $check_result->fetch_assoc()['count'];

            if ($approved_count >= 3) {
                // Update loan status to pending approval
                $update_loan_sql = "UPDATE loans SET status = 'pending' WHERE id = ?";
                $stmt = $conn->prepare($update_loan_sql);
                $stmt->bind_param("i", $loan_id);
                $stmt->execute();

                // Send notification to member
                $message = "Dear {$loan['full_name']}, your loan application has met the guarantor requirements and is now ready for final approval.";
                sendNotification($loan['member_id'], 'Loan Update', $message, 'sms');
            }

            $_SESSION['success'] = 'Selected guarantors approved successfully';
        } elseif ($action == 'reject_selected') {
            // Reject selected guarantors
            foreach ($guarantor_ids as $guarantor_id) {
                $update_sql = "UPDATE loan_guarantors SET status = 'rejected' WHERE id = ? AND loan_id = ?";
                $stmt = $conn->prepare($update_sql);
                $stmt->bind_param("ii", $guarantor_id, $loan_id);
                $stmt->execute();
            }

            $_SESSION['success'] = 'Selected guarantors rejected';
        } elseif ($action == 'add_guarantor') {
            // Add a new guarantor
            $guarantor_member_id = $_POST['guarantor_member_id'];
            $guaranteed_amount = $_POST['guaranteed_amount'];

            // Check if member is already a guarantor for this loan
            $check_sql = "SELECT id FROM loan_guarantors WHERE loan_id = ? AND guarantor_member_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("ii", $loan_id, $guarantor_member_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();

            if ($check_result->num_rows > 0) {
                $_SESSION['error'] = 'This member is already a guarantor for this loan';
                header('Location: process-guarantors.php?loan_id=' . $loan_id);
                exit();
            }

            // Check if guarantor has enough shares/deposits (optional)
            $check_eligibility = checkGuarantorEligibility($conn, $guarantor_member_id, $guaranteed_amount);
            if (!$check_eligibility['eligible']) {
                $_SESSION['error'] = 'Guarantor not eligible: ' . $check_eligibility['reason'];
                header('Location: process-guarantors.php?loan_id=' . $loan_id);
                exit();
            }

            $insert_sql = "INSERT INTO loan_guarantors (loan_id, guarantor_member_id, guaranteed_amount, status, created_at) 
                          VALUES (?, ?, ?, 'pending', NOW())";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("iid", $loan_id, $guarantor_member_id, $guaranteed_amount);
            $insert_stmt->execute();

            // Get member details for notification
            $member_sql = "SELECT full_name, phone FROM members WHERE id = ?";
            $member_stmt = $conn->prepare($member_sql);
            $member_stmt->bind_param("i", $guarantor_member_id);
            $member_stmt->execute();
            $member_result = $member_stmt->get_result();
            $guarantor_member = $member_result->fetch_assoc();

            // Send notification to potential guarantor
            if (!empty($guarantor_member['phone'])) {
                $message = "Dear {$guarantor_member['full_name']}, you have been added as a guarantor for loan {$loan['loan_no']} of KES " . number_format($loan['principal_amount']) . ". Please visit the office to confirm.";
                sendNotification($guarantor_member_id, 'Guarantor Request', $message, 'sms');
            }

            $_SESSION['success'] = 'New guarantor added successfully';
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Action failed: ' . $e->getMessage();
    }

    $conn->close();
    header('Location: process-guarantors.php?loan_id=' . $loan_id);
    exit();
}

// Function to check guarantor eligibility
function checkGuarantorEligibility($conn, $member_id, $guaranteed_amount)
{
    // Check member's total shares/deposits
    $shares_sql = "SELECT COALESCE(SUM(total_value), 0) as total_shares FROM shares WHERE member_id = ?";
    $shares_stmt = $conn->prepare($shares_sql);
    $shares_stmt->bind_param("i", $member_id);
    $shares_stmt->execute();
    $shares_result = $shares_stmt->get_result();
    $shares = $shares_result->fetch_assoc()['total_shares'];

    $deposits_sql = "SELECT COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE -amount END), 0) as balance 
                     FROM deposits WHERE member_id = ?";
    $deposits_stmt = $conn->prepare($deposits_sql);
    $deposits_stmt->bind_param("i", $member_id);
    $deposits_stmt->execute();
    $deposits_result = $deposits_stmt->get_result();
    $balance = $deposits_result->fetch_assoc()['balance'];

    $total_assets = $shares + $balance;

    // Check existing guarantor commitments
    $existing_sql = "SELECT COALESCE(SUM(guaranteed_amount), 0) as total FROM loan_guarantors 
                     WHERE guarantor_member_id = ? AND status IN ('pending', 'approved')";
    $existing_stmt = $conn->prepare($existing_sql);
    $existing_stmt->bind_param("i", $member_id);
    $existing_stmt->execute();
    $existing_result = $existing_stmt->get_result();
    $existing_commitments = $existing_result->fetch_assoc()['total'];

    $available_capacity = $total_assets - $existing_commitments;

    if ($total_assets < $guaranteed_amount) {
        return [
            'eligible' => false,
            'reason' => "Insufficient assets (Shares: " . formatCurrency($shares) . ", Savings: " . formatCurrency($balance) . ")"
        ];
    }

    if ($available_capacity < $guaranteed_amount) {
        return [
            'eligible' => false,
            'reason' => "Existing guarantor commitments of " . formatCurrency($existing_commitments) . " reduce available capacity"
        ];
    }

    return ['eligible' => true, 'reason' => ''];
}

// Get all guarantors for this loan
$guarantors_sql = "SELECT lg.*, 
                   m.member_no, m.full_name as guarantor_name, 
                   m.phone, m.email,
                   (SELECT COALESCE(SUM(total_value), 0) FROM shares WHERE member_id = m.id) as total_shares,
                   (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE -amount END), 0) 
                    FROM deposits WHERE member_id = m.id) as savings_balance
                   FROM loan_guarantors lg
                   JOIN members m ON lg.guarantor_member_id = m.id
                   WHERE lg.loan_id = ?
                   ORDER BY 
                       CASE lg.status 
                           WHEN 'pending' THEN 1 
                           WHEN 'approved' THEN 2 
                           ELSE 3 
                       END, 
                       lg.created_at ASC";
$guarantors = executeQuery($guarantors_sql, "i", [$loan_id]);

// Get statistics - FIXED QUERIES
$stats_sql = "SELECT 
              COUNT(*) as total,
              SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
              SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
              SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
              COALESCE(SUM(CASE WHEN status = 'approved' THEN guaranteed_amount ELSE 0 END), 0) as total_approved_amount
              FROM loan_guarantors
              WHERE loan_id = ?";
$stats_result = executeQuery($stats_sql, "i", [$loan_id]);
$stats = $stats_result->fetch_assoc();

// Get eligible members for new guarantor dropdown
$eligible_members_sql = "SELECT m.id, m.member_no, m.full_name,
                         (SELECT COALESCE(SUM(total_value), 0) FROM shares WHERE member_id = m.id) as total_shares,
                         (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE -amount END), 0) 
                          FROM deposits WHERE member_id = m.id) as savings_balance
                         FROM members m
                         WHERE m.membership_status = 'active' 
                         AND m.id NOT IN (
                             SELECT guarantor_member_id FROM loan_guarantors WHERE loan_id = ?
                         )
                         AND m.id != ?
                         ORDER BY m.full_name";
$eligible_members = executeQuery($eligible_members_sql, "ii", [$loan_id, $loan['member_id']]);

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Process Guarantors</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Loans</a></li>
                <li class="breadcrumb-item"><a href="approvals.php">Approvals</a></li>
                <li class="breadcrumb-item active">Process Guarantors</li>
            </ul>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addGuarantorModal">
                <i class="fas fa-user-plus me-2"></i>Add Guarantor
            </button>
            <a href="view.php?id=<?php echo $loan_id; ?>" class="btn btn-info">
                <i class="fas fa-eye me-2"></i>View Loan
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

<!-- Loan Summary Card -->
<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="card-title mb-0">Loan Information</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3">
                <p><strong>Loan No:</strong><br> <?php echo $loan['loan_no']; ?></p>
            </div>
            <div class="col-md-3">
                <p><strong>Member:</strong><br> <?php echo $loan['full_name']; ?> (<?php echo $loan['member_no']; ?>)</p>
            </div>
            <div class="col-md-2">
                <p><strong>Product:</strong><br> <?php echo $loan['product_name']; ?></p>
            </div>
            <div class="col-md-2">
                <p><strong>Principal:</strong><br> <?php echo formatCurrency($loan['principal_amount']); ?></p>
            </div>
            <div class="col-md-2">
                <p><strong>Required Guarantors:</strong><br>
                    <span class="badge bg-<?php echo ($stats['approved'] ?? 0) >= 3 ? 'success' : 'warning'; ?> fs-6">
                        <?php echo $stats['approved'] ?? 0; ?>/3
                    </span>
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Guarantor Statistics -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="stats-card info">
            <div class="stats-icon">
                <i class="fas fa-users"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo $stats['total'] ?? 0; ?></h3>
                <p>Total Guarantors</p>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card warning">
            <div class="stats-icon">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo $stats['pending'] ?? 0; ?></h3>
                <p>Pending Approval</p>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card success">
            <div class="stats-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo $stats['approved'] ?? 0; ?></h3>
                <p>Approved</p>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card primary">
            <div class="stats-icon">
                <i class="fas fa-hand-holding-usd"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($stats['total_approved_amount'] ?? 0); ?></h3>
                <p>Total Guaranteed</p>
            </div>
        </div>
    </div>
</div>

<!-- Guarantors Table -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title">Guarantors List</h5>
        <?php if ($guarantors && $guarantors->num_rows > 0): ?>
            <div class="card-tools">
                <button type="button" class="btn btn-sm btn-success" onclick="approveAll()">
                    <i class="fas fa-check-double me-2"></i>Approve All
                </button>
                <button type="button" class="btn btn-sm btn-danger" onclick="rejectAll()">
                    <i class="fas fa-times-double me-2"></i>Reject All
                </button>
            </div>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <form method="POST" action="" id="guarantorForm">
            <input type="hidden" name="action" id="formAction" value="">
            <input type="hidden" name="remarks" id="formRemarks" value="">

            <?php if ($guarantors && $guarantors->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th width="40">
                                    <input type="checkbox" id="selectAll" onclick="toggleAll()">
                                </th>
                                <th>Member No</th>
                                <th>Guarantor Name</th>
                                <th>Phone</th>
                                <th>Shares</th>
                                <th>Savings</th>
                                <th>Guaranteed Amount</th>
                                <th>Status</th>
                                <th>Date Added</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $guarantors->data_seek(0);
                            while ($g = $guarantors->fetch_assoc()):
                                $total_assets = ($g['total_shares'] ?? 0) + ($g['savings_balance'] ?? 0);
                                $capacity_percentage = $total_assets > 0 ? ($g['guaranteed_amount'] / $total_assets) * 100 : 0;
                            ?>
                                <tr class="<?php
                                            echo $g['status'] == 'approved' ? 'table-success' : ($g['status'] == 'rejected' ? 'table-danger' : '');
                                            ?>">
                                    <td>
                                        <?php if ($g['status'] == 'pending'): ?>
                                            <input type="checkbox" name="guarantor_ids[]" value="<?php echo $g['id']; ?>" class="guarantor-checkbox">
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $g['member_no']; ?></td>
                                    <td>
                                        <a href="../members/view.php?id=<?php echo $g['guarantor_member_id']; ?>">
                                            <strong><?php echo $g['guarantor_name']; ?></strong>
                                        </a>
                                    </td>
                                    <td><?php echo $g['phone']; ?></td>
                                    <td><?php echo formatCurrency($g['total_shares'] ?? 0); ?></td>
                                    <td><?php echo formatCurrency($g['savings_balance'] ?? 0); ?></td>
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
                                    <td><?php echo formatDate($g['created_at']); ?></td>
                                    <td>
                                        <?php if ($g['status'] == 'pending'): ?>
                                            <div class="btn-group">
                                                <button type="button" class="btn btn-sm btn-success" onclick="approveSingle(<?php echo $g['id']; ?>)">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-danger" onclick="rejectSingle(<?php echo $g['id']; ?>)">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                        <?php endif; ?>

                                        <!-- Asset Capacity Progress -->
                                        <?php if ($total_assets > 0): ?>
                                            <div class="mt-1" style="width: 100px;">
                                                <div class="progress" style="height: 5px;">
                                                    <div class="progress-bar bg-<?php echo $capacity_percentage > 100 ? 'danger' : 'success'; ?>"
                                                        style="width: <?php echo min($capacity_percentage, 100); ?>%;"
                                                        title="Guarantee uses <?php echo number_format($capacity_percentage, 1); ?>% of assets">
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-info">
                                <th colspan="6" class="text-end">Total Guaranteed:</th>
                                <th><?php echo formatCurrency($stats['total_approved_amount'] ?? 0); ?></th>
                                <th colspan="3"></th>
                            </tr>
                            <tr>
                                <th colspan="6" class="text-end">Required Coverage:</th>
                                <th><?php echo formatCurrency($loan['principal_amount']); ?></th>
                                <th colspan="3">
                                    <?php if (($stats['total_approved_amount'] ?? 0) >= $loan['principal_amount']): ?>
                                        <span class="badge bg-success">Fully Covered</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">
                                            Short by <?php echo formatCurrency($loan['principal_amount'] - ($stats['total_approved_amount'] ?? 0)); ?>
                                        </span>
                                    <?php endif; ?>
                                </th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Bulk Action Buttons -->
                <div class="row mt-3" id="bulkActions" style="display: none;">
                    <div class="col-md-12">
                        <div class="card bg-light">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-md-4">
                                        <strong><span id="selectedCount">0</span> guarantor(s) selected</strong>
                                    </div>
                                    <div class="col-md-5">
                                        <input type="text" class="form-control" id="bulkRemarks" placeholder="Remarks (optional)">
                                    </div>
                                    <div class="col-md-3">
                                        <button type="button" class="btn btn-success me-2" onclick="submitBulk('approve_selected')">
                                            <i class="fas fa-check"></i> Approve Selected
                                        </button>
                                        <button type="button" class="btn btn-danger" onclick="submitBulk('reject_selected')">
                                            <i class="fas fa-times"></i> Reject Selected
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <div class="text-center py-4">
                    <i class="fas fa-user-friends fa-3x text-muted mb-3"></i>
                    <h5>No Guarantors Added Yet</h5>
                    <p class="text-muted">Click the "Add Guarantor" button to add guarantors for this loan.</p>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Add Guarantor Modal -->
<div class="modal fade" id="addGuarantorModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Add New Guarantor</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_guarantor">

                    <div class="mb-3">
                        <label for="guarantor_member_id" class="form-label">Select Guarantor <span class="text-danger">*</span></label>
                        <select class="form-control select2" id="guarantor_member_id" name="guarantor_member_id" required onchange="loadGuarantorAssets()">
                            <option value="">-- Select Member --</option>
                            <?php
                            if ($eligible_members) {
                                $eligible_members->data_seek(0);
                                while ($member = $eligible_members->fetch_assoc()):
                                    $total_assets = ($member['total_shares'] ?? 0) + ($member['savings_balance'] ?? 0);
                            ?>
                                    <option value="<?php echo $member['id']; ?>"
                                        data-shares="<?php echo $member['total_shares'] ?? 0; ?>"
                                        data-savings="<?php echo $member['savings_balance'] ?? 0; ?>">
                                        <?php echo $member['full_name']; ?> (<?php echo $member['member_no']; ?>) -
                                        Assets: <?php echo formatCurrency($total_assets); ?>
                                    </option>
                            <?php
                                endwhile;
                            }
                            ?>
                        </select>
                    </div>

                    <div class="alert alert-info" id="assetsInfo" style="display: none;">
                        <strong>Member Assets:</strong><br>
                        <span id="sharesAmount"></span><br>
                        <span id="savingsAmount"></span>
                    </div>

                    <div class="mb-3">
                        <label for="guaranteed_amount" class="form-label">Guaranteed Amount (KES) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="guaranteed_amount" name="guaranteed_amount"
                            min="1000" max="<?php echo $loan['principal_amount']; ?>" step="1000" required>
                        <small class="text-muted">Maximum: <?php echo formatCurrency($loan['principal_amount']); ?></small>
                    </div>

                    <div class="alert alert-warning" id="eligibilityWarning" style="display: none;">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <span id="warningMessage"></span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-user-plus me-2"></i>Add Guarantor
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Toggle select all checkboxes
    function toggleAll() {
        var checkboxes = document.getElementsByClassName('guarantor-checkbox');
        var selectAll = document.getElementById('selectAll');

        for (var i = 0; i < checkboxes.length; i++) {
            checkboxes[i].checked = selectAll.checked;
        }

        updateBulkActions();
    }

    // Update bulk actions visibility
    function updateBulkActions() {
        var checkboxes = document.getElementsByClassName('guarantor-checkbox');
        var selectedCount = 0;

        for (var i = 0; i < checkboxes.length; i++) {
            if (checkboxes[i].checked) {
                selectedCount++;
            }
        }

        document.getElementById('selectedCount').textContent = selectedCount;
        document.getElementById('bulkActions').style.display = selectedCount > 0 ? 'block' : 'none';
    }

    // Attach change event to checkboxes
    document.addEventListener('DOMContentLoaded', function() {
        var checkboxes = document.getElementsByClassName('guarantor-checkbox');
        for (var i = 0; i < checkboxes.length; i++) {
            checkboxes[i].addEventListener('change', updateBulkActions);
        }
    });

    // Submit bulk action
    function submitBulk(action) {
        var checkboxes = document.getElementsByClassName('guarantor-checkbox');
        var anyChecked = false;

        for (var i = 0; i < checkboxes.length; i++) {
            if (checkboxes[i].checked) {
                anyChecked = true;
                break;
            }
        }

        if (!anyChecked) {
            Swal.fire('No Selection', 'Please select at least one guarantor', 'warning');
            return;
        }

        var remarks = document.getElementById('bulkRemarks').value;
        document.getElementById('formAction').value = action;
        document.getElementById('formRemarks').value = remarks;

        var message = action == 'approve_selected' ? 'approve' : 'reject';
        Swal.fire({
            title: 'Confirm Action',
            text: 'Are you sure you want to ' + message + ' the selected guarantors?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: action == 'approve_selected' ? '#28a745' : '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, ' + message + ' them!'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('guarantorForm').submit();
            }
        });
    }

    // Approve all
    function approveAll() {
        // Get all pending guarantor IDs
        var checkboxes = document.getElementsByClassName('guarantor-checkbox');
        var ids = [];
        for (var i = 0; i < checkboxes.length; i++) {
            ids.push(checkboxes[i].value);
        }

        if (ids.length === 0) {
            Swal.fire('No Pending', 'No pending guarantors to approve', 'info');
            return;
        }

        Swal.fire({
            title: 'Approve All Guarantors',
            text: 'Are you sure you want to approve all ' + ids.length + ' pending guarantors?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, approve all!'
        }).then((result) => {
            if (result.isConfirmed) {
                // Create hidden inputs for each ID
                for (var i = 0; i < ids.length; i++) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'guarantor_ids[]';
                    input.value = ids[i];
                    document.getElementById('guarantorForm').appendChild(input);
                }

                document.getElementById('formAction').value = 'approve_all';
                document.getElementById('guarantorForm').submit();
            }
        });
    }

    // Reject all
    function rejectAll() {
        // Get all pending guarantor IDs
        var checkboxes = document.getElementsByClassName('guarantor-checkbox');
        var ids = [];
        for (var i = 0; i < checkboxes.length; i++) {
            ids.push(checkboxes[i].value);
        }

        if (ids.length === 0) {
            Swal.fire('No Pending', 'No pending guarantors to reject', 'info');
            return;
        }

        Swal.fire({
            title: 'Reject All Guarantors',
            text: 'Are you sure you want to reject all ' + ids.length + ' pending guarantors?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, reject all!'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Provide Reason',
                    input: 'textarea',
                    inputLabel: 'Rejection Reason',
                    inputPlaceholder: 'Enter reason for rejection...',
                    inputAttributes: {
                        'aria-label': 'Enter reason for rejection'
                    },
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    confirmButtonText: 'Reject All'
                }).then((inputResult) => {
                    if (inputResult.isConfirmed) {
                        // Create hidden inputs for each ID
                        for (var i = 0; i < ids.length; i++) {
                            var input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = 'guarantor_ids[]';
                            input.value = ids[i];
                            document.getElementById('guarantorForm').appendChild(input);
                        }

                        document.getElementById('formAction').value = 'reject_all';
                        document.getElementById('formRemarks').value = inputResult.value;
                        document.getElementById('guarantorForm').submit();
                    }
                });
            }
        });
    }

    // Approve single guarantor
    function approveSingle(guarantorId) {
        Swal.fire({
            title: 'Approve Guarantor',
            text: 'Are you sure you want to approve this guarantor?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, approve!'
        }).then((result) => {
            if (result.isConfirmed) {
                // Create hidden input for the ID
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'guarantor_ids[]';
                input.value = guarantorId;
                document.getElementById('guarantorForm').appendChild(input);

                document.getElementById('formAction').value = 'approve_selected';
                document.getElementById('guarantorForm').submit();
            }
        });
    }

    // Reject single guarantor
    function rejectSingle(guarantorId) {
        Swal.fire({
            title: 'Reject Guarantor',
            text: 'Are you sure you want to reject this guarantor?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, reject!'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Provide Reason',
                    input: 'textarea',
                    inputLabel: 'Rejection Reason',
                    inputPlaceholder: 'Enter reason for rejection...',
                    inputAttributes: {
                        'aria-label': 'Enter reason for rejection'
                    },
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    confirmButtonText: 'Reject'
                }).then((inputResult) => {
                    if (inputResult.isConfirmed) {
                        // Create hidden input for the ID
                        var input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'guarantor_ids[]';
                        input.value = guarantorId;
                        document.getElementById('guarantorForm').appendChild(input);

                        document.getElementById('formAction').value = 'reject_selected';
                        document.getElementById('formRemarks').value = inputResult.value;
                        document.getElementById('guarantorForm').submit();
                    }
                });
            }
        });
    }

    // Load guarantor assets
    function loadGuarantorAssets() {
        var select = document.getElementById('guarantor_member_id');
        var selected = select.options[select.selectedIndex];

        if (select.value) {
            var shares = parseFloat(selected.dataset.shares) || 0;
            var savings = parseFloat(selected.dataset.savings) || 0;
            var total = shares + savings;

            document.getElementById('assetsInfo').style.display = 'block';
            document.getElementById('sharesAmount').innerHTML = 'Shares: ' + formatCurrency(shares);
            document.getElementById('savingsAmount').innerHTML = 'Savings: ' + formatCurrency(savings);
            document.getElementById('savingsAmount').innerHTML += '<br><strong>Total Assets: ' + formatCurrency(total) + '</strong>';

            // Check eligibility
            var amount = parseFloat(document.getElementById('guaranteed_amount').value) || 0;
            if (amount > total) {
                document.getElementById('eligibilityWarning').style.display = 'block';
                document.getElementById('warningMessage').innerHTML = 'Warning: Guaranteed amount exceeds member\'s total assets!';
            } else {
                document.getElementById('eligibilityWarning').style.display = 'none';
            }
        } else {
            document.getElementById('assetsInfo').style.display = 'none';
        }
    }

    // Format currency
    function formatCurrency(amount) {
        return 'KES ' + parseFloat(amount).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    // Initialize Select2
    $(document).ready(function() {
        $('.select2').select2({
            theme: 'bootstrap-5',
            width: '100%',
            dropdownParent: $('#addGuarantorModal')
        });
    });

    // Validate guaranteed amount
    document.getElementById('guaranteed_amount').addEventListener('input', function() {
        var amount = parseFloat(this.value) || 0;
        var select = document.getElementById('guarantor_member_id');

        if (select.value) {
            var selected = select.options[select.selectedIndex];
            var shares = parseFloat(selected.dataset.shares) || 0;
            var savings = parseFloat(selected.dataset.savings) || 0;
            var total = shares + savings;

            if (amount > total) {
                document.getElementById('eligibilityWarning').style.display = 'block';
                document.getElementById('warningMessage').innerHTML = 'Warning: Guaranteed amount exceeds member\'s total assets!';
            } else {
                document.getElementById('eligibilityWarning').style.display = 'none';
            }
        }
    });
</script>

<style>
    .stats-card .stats-content small {
        font-size: 11px;
        opacity: 0.9;
    }

    .table td {
        vertical-align: middle;
    }

    .progress {
        background-color: #e9ecef;
        border-radius: 3px;
    }

    .card-header .card-tools {
        margin-left: auto;
    }

    #bulkActions {
        position: sticky;
        bottom: 20px;
        z-index: 100;
    }

    .table-success {
        background-color: rgba(40, 167, 69, 0.05) !important;
    }

    .table-danger {
        background-color: rgba(220, 53, 69, 0.05) !important;
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
    }
</style>

<?php include '../../includes/footer.php'; ?>
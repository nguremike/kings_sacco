<?php
// modules/loans/process-guarantors.php
require_once '../../config/config.php';
requireRole('admin');

$loan_id = $_GET['loan_id'] ?? 0;

// Get system settings
$settings = getLoanSettings();

// Get loan details
$loan_sql = "SELECT l.*, m.full_name, m.member_no, lp.product_name,
             lp.guarantor_required, lp.min_guarantors, lp.max_guarantors, lp.guarantor_coverage
             FROM loans l 
             JOIN members m ON l.member_id = m.id 
             JOIN loan_products lp ON l.product_id = lp.id 
             WHERE l.id = ?";
$loan_result = executeQuery($loan_sql, "i", [$loan_id]);

if ($loan_result->num_rows == 0) {
    $_SESSION['error'] = 'Loan not found';
    header('Location: index.php');
    exit();
}

$loan = $loan_result->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    processGuarantorAction($loan_id, $action, $loan, $settings);
}

function getLoanSettings()
{
    $settings = [];
    $result = executeQuery("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE '%guarantor%'");
    while ($row = $result->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    return $settings;
}

function processGuarantorAction($loan_id, $action, $loan, $settings)
{
    $conn = getConnection();
    $conn->begin_transaction();

    try {
        if ($action == 'add_guarantor') {
            addGuarantor($conn, $loan_id, $loan, $settings);
        } elseif ($action == 'approve_guarantor') {
            approveGuarantor($conn, $loan_id);
        } elseif ($action == 'reject_guarantor') {
            rejectGuarantor($conn, $loan_id);
        } elseif ($action == 'approve_all') {
            approveAllGuarantors($conn, $loan_id, $loan, $settings);
        } elseif ($action == 'reject_all') {
            rejectAllGuarantors($conn, $loan_id);
        } elseif ($action == 'approve_selected') {
            approveSelectedGuarantors($conn, $loan_id);
        } elseif ($action == 'reject_selected') {
            rejectSelectedGuarantors($conn, $loan_id);
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Action failed: ' . $e->getMessage();
        error_log("Guarantor action error: " . $e->getMessage());
    }

    $conn->close();
    header('Location: process-guarantors.php?loan_id=' . $loan_id);
    exit();
}

function addGuarantor($conn, $loan_id, $loan, $settings)
{
    $guarantor_member_id = $_POST['guarantor_member_id'];
    $guaranteed_amount = $_POST['guaranteed_amount'];

    // Check if already a guarantor
    $check = $conn->prepare("SELECT id FROM loan_guarantors WHERE loan_id = ? AND guarantor_member_id = ?");
    $check->bind_param("ii", $loan_id, $guarantor_member_id);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        throw new Exception("Member is already a guarantor for this loan");
    }

    // Check guarantor eligibility
    $eligibility = checkGuarantorEligibility($conn, $guarantor_member_id, $guaranteed_amount, $loan, $settings);
    if (!$eligibility['eligible']) {
        throw new Exception($eligibility['reason']);
    }

    // Add guarantor
    $sql = "INSERT INTO loan_guarantors (loan_id, guarantor_member_id, guaranteed_amount, status) 
            VALUES (?, ?, ?, 'pending')";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iid", $loan_id, $guarantor_member_id, $guaranteed_amount);
    $stmt->execute();

    // Get member details for notification
    $member = $conn->prepare("SELECT full_name, phone FROM members WHERE id = ?");
    $member->bind_param("i", $guarantor_member_id);
    $member->execute();
    $member_data = $member->get_result()->fetch_assoc();

    // Send notification to guarantor
    if (!empty($member_data['phone'])) {
        $message = "You have been added as a guarantor for loan " . $loan['loan_no'] . ". Please visit the office to confirm.";
        sendNotification($guarantor_member_id, 'Guarantor Added', $message, 'sms');
    }

    $_SESSION['success'] = 'Guarantor added successfully';
}

function checkGuarantorEligibility($conn, $member_id, $amount, $loan, $settings)
{
    // Get guarantor's financial status
    $savings = $conn->query("SELECT COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE -amount END), 0) as balance 
                            FROM deposits WHERE member_id = $member_id")->fetch_assoc()['balance'];

    $shares = $conn->query("SELECT COALESCE(SUM(total_value), 0) as total FROM shares WHERE member_id = $member_id")->fetch_assoc()['total'];

    $total_assets = $savings + $shares;

    // Check existing guarantor commitments
    $existing = $conn->query("SELECT COALESCE(SUM(guaranteed_amount), 0) as total 
                             FROM loan_guarantors WHERE guarantor_member_id = $member_id AND status IN ('pending', 'approved')")->fetch_assoc()['total'];

    $available = $total_assets - $existing;

    if ($available < $amount) {
        return [
            'eligible' => false,
            'reason' => "Insufficient assets. Available: " . formatCurrency($available) . ", Required: " . formatCurrency($amount)
        ];
    }

    return ['eligible' => true, 'reason' => ''];
}

function approveGuarantor($conn, $loan_id)
{
    $guarantor_id = $_POST['guarantor_id'] ?? $_GET['id'] ?? 0;

    if (!$guarantor_id) {
        throw new Exception("Guarantor ID not provided");
    }

    $sql = "UPDATE loan_guarantors SET status = 'approved', approval_date = CURDATE() WHERE id = ? AND loan_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $guarantor_id, $loan_id);
    $stmt->execute();

    if ($stmt->affected_rows == 0) {
        throw new Exception("Guarantor not found or already processed");
    }

    // Get guarantor details for notification
    $guarantor = $conn->prepare("SELECT lg.*, m.full_name, m.phone, l.loan_no 
                                 FROM loan_guarantors lg
                                 JOIN members m ON lg.guarantor_member_id = m.id
                                 JOIN loans l ON lg.loan_id = l.id
                                 WHERE lg.id = ?");
    $guarantor->bind_param("i", $guarantor_id);
    $guarantor->execute();
    $g_data = $guarantor->get_result()->fetch_assoc();

    // Send notification
    if (!empty($g_data['phone'])) {
        $message = "Your guarantor application for loan " . $g_data['loan_no'] . " has been APPROVED. Thank you.";
        sendNotification($g_data['guarantor_member_id'], 'Guarantor Approved', $message, 'sms');
    }

    // Check if loan now has required guarantors
    checkGuarantorRequirements($conn, $loan_id);

    $_SESSION['success'] = 'Guarantor approved successfully';
}

function rejectGuarantor($conn, $loan_id)
{
    $guarantor_id = $_POST['guarantor_id'] ?? $_GET['id'] ?? 0;
    $reason = $_POST['reason'] ?? $_POST['remarks'] ?? 'No reason provided';

    if (!$guarantor_id) {
        throw new Exception("Guarantor ID not provided");
    }

    $sql = "UPDATE loan_guarantors SET status = 'rejected', rejection_reason = ? WHERE id = ? AND loan_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sii", $reason, $guarantor_id, $loan_id);
    $stmt->execute();

    if ($stmt->affected_rows == 0) {
        throw new Exception("Guarantor not found or already processed");
    }

    // Get guarantor details for notification
    $guarantor = $conn->prepare("SELECT lg.*, m.full_name, m.phone, l.loan_no 
                                 FROM loan_guarantors lg
                                 JOIN members m ON lg.guarantor_member_id = m.id
                                 JOIN loans l ON lg.loan_id = l.id
                                 WHERE lg.id = ?");
    $guarantor->bind_param("i", $guarantor_id);
    $guarantor->execute();
    $g_data = $guarantor->get_result()->fetch_assoc();

    // Send notification
    if (!empty($g_data['phone'])) {
        $message = "Your guarantor application for loan " . $g_data['loan_no'] . " has been REJECTED. Reason: $reason";
        sendNotification($g_data['guarantor_member_id'], 'Guarantor Rejected', $message, 'sms');
    }

    $_SESSION['success'] = 'Guarantor rejected successfully';
}

function approveAllGuarantors($conn, $loan_id, $loan, $settings)
{
    // Get all pending guarantors
    $pending = $conn->query("SELECT id, guarantor_member_id FROM loan_guarantors WHERE loan_id = $loan_id AND status = 'pending'");

    while ($g = $pending->fetch_assoc()) {
        $update = $conn->prepare("UPDATE loan_guarantors SET status = 'approved', approval_date = CURDATE() WHERE id = ?");
        $update->bind_param("i", $g['id']);
        $update->execute();

        // Send notification
        $member = $conn->prepare("SELECT phone, full_name FROM members WHERE id = ?");
        $member->bind_param("i", $g['guarantor_member_id']);
        $member->execute();
        $m = $member->get_result()->fetch_assoc();

        if (!empty($m['phone'])) {
            $message = "Your guarantor application for loan " . $loan['loan_no'] . " has been APPROVED.";
            sendNotification($g['guarantor_member_id'], 'Guarantor Approved', $message, 'sms');
        }
    }

    // Check if loan now has required guarantors
    checkGuarantorRequirements($conn, $loan_id, $loan, $settings);

    $_SESSION['success'] = 'All pending guarantors approved';
}

function rejectAllGuarantors($conn, $loan_id)
{
    $reason = $_POST['reason'] ?? $_POST['remarks'] ?? 'Rejected by admin';

    // Get all pending guarantors
    $pending = $conn->query("SELECT id, guarantor_member_id FROM loan_guarantors WHERE loan_id = $loan_id AND status = 'pending'");

    while ($g = $pending->fetch_assoc()) {
        $update = $conn->prepare("UPDATE loan_guarantors SET status = 'rejected', rejection_reason = ? WHERE id = ?");
        $update->bind_param("si", $reason, $g['id']);
        $update->execute();

        // Send notification
        $member = $conn->prepare("SELECT phone, full_name FROM members WHERE id = ?");
        $member->bind_param("i", $g['guarantor_member_id']);
        $member->execute();
        $m = $member->get_result()->fetch_assoc();

        if (!empty($m['phone'])) {
            $message = "Your guarantor application for loan has been REJECTED. Reason: $reason";
            sendNotification($g['guarantor_member_id'], 'Guarantor Rejected', $message, 'sms');
        }
    }

    $_SESSION['success'] = 'All pending guarantors rejected';
}

function approveSelectedGuarantors($conn, $loan_id)
{
    $selected_ids = $_POST['selected_ids'] ?? [];

    if (empty($selected_ids)) {
        throw new Exception("No guarantors selected");
    }

    $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
    $types = str_repeat('i', count($selected_ids));

    $sql = "UPDATE loan_guarantors SET status = 'approved', approval_date = CURDATE() WHERE id IN ($placeholders) AND loan_id = ?";
    $params = array_merge($selected_ids, [$loan_id]);
    $types .= "i";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    // Check if loan now has required guarantors
    checkGuarantorRequirements($conn, $loan_id);

    $_SESSION['success'] = 'Selected guarantors approved';
}

function rejectSelectedGuarantors($conn, $loan_id)
{
    $selected_ids = $_POST['selected_ids'] ?? [];
    $reason = $_POST['reason'] ?? $_POST['remarks'] ?? 'Rejected by admin';

    if (empty($selected_ids)) {
        throw new Exception("No guarantors selected");
    }

    $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
    $types = str_repeat('i', count($selected_ids));

    $sql = "UPDATE loan_guarantors SET status = 'rejected', rejection_reason = ? WHERE id IN ($placeholders) AND loan_id = ?";
    $params = array_merge([$reason], $selected_ids, [$loan_id]);
    $types = "s" . $types . "i";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    $_SESSION['success'] = 'Selected guarantors rejected';
}

function checkGuarantorRequirements($conn, $loan_id, $loan = null, $settings = null)
{
    if (!$loan) {
        $loan_result = $conn->query("SELECT l.*, lp.min_guarantors, lp.guarantor_coverage 
                                     FROM loans l 
                                     JOIN loan_products lp ON l.product_id = lp.id 
                                     WHERE l.id = $loan_id");
        $loan = $loan_result->fetch_assoc();
    }

    // Get approved guarantors
    $approved = $conn->query("SELECT COUNT(*) as count, COALESCE(SUM(guaranteed_amount), 0) as total 
                             FROM loan_guarantors WHERE loan_id = $loan_id AND status = 'approved'")->fetch_assoc();

    $min_guarantors = $loan['min_guarantors'] ?? ($settings['min_guarantors'] ?? 1);
    $required_coverage = $loan['principal_amount'] * ($loan['guarantor_coverage'] / 100);

    if ($approved['count'] >= $min_guarantors && $approved['total'] >= $required_coverage) {
        $conn->query("UPDATE loans SET status = 'pending' WHERE id = $loan_id");

        // Notify member
        $loan_details = $conn->query("SELECT member_id, loan_no FROM loans WHERE id = $loan_id")->fetch_assoc();
        $message = "Your loan " . $loan_details['loan_no'] . " has met all guarantor requirements and is ready for review.";
        sendNotification($loan_details['member_id'], 'Loan Update', $message, 'sms');
    }
}

// Get guarantors with eligibility info
function getGuarantorsWithEligibility($conn, $loan_id, $loan, $settings)
{
    $sql = "SELECT lg.*, m.full_name, m.member_no, m.phone,
            (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE -amount END), 0) 
             FROM deposits WHERE member_id = m.id) as savings,
            (SELECT COALESCE(SUM(total_value), 0) FROM shares WHERE member_id = m.id) as shares,
            (SELECT COALESCE(SUM(guaranteed_amount), 0) 
             FROM loan_guarantors lg2 
             WHERE lg2.guarantor_member_id = m.id AND lg2.status IN ('pending', 'approved') AND lg2.loan_id != ?) as other_commitments
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

    $result = $conn->prepare($sql);
    $result->bind_param("ii", $loan_id, $loan_id);
    $result->execute();
    return $result->get_result();
}

// Get eligible members for new guarantor dropdown
function getEligibleMembers($conn, $loan_id, $member_id)
{
    $sql = "SELECT m.id, m.member_no, m.full_name,
            (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE -amount END), 0) 
             FROM deposits WHERE member_id = m.id) as savings,
            (SELECT COALESCE(SUM(total_value), 0) FROM shares WHERE member_id = m.id) as shares
            FROM members m
            WHERE m.membership_status = 'active' 
            AND m.id != ?
            AND m.id NOT IN (
                SELECT guarantor_member_id FROM loan_guarantors WHERE loan_id = ?
            )
            ORDER BY m.full_name";

    $result = $conn->prepare($sql);
    $result->bind_param("ii", $member_id, $loan_id);
    $result->execute();
    return $result->get_result();
}

$conn = getConnection();
$guarantors = getGuarantorsWithEligibility($conn, $loan_id, $loan, $settings);
$eligible_members = getEligibleMembers($conn, $loan_id, $loan['member_id']);

// Get statistics
$stats_sql = "SELECT 
              COUNT(*) as total,
              SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
              SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
              SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
              COALESCE(SUM(CASE WHEN status = 'approved' THEN guaranteed_amount ELSE 0 END), 0) as total_approved_amount
              FROM loan_guarantors
              WHERE loan_id = ?";
$stats_result = $conn->prepare($stats_sql);
$stats_result->bind_param("i", $loan_id);
$stats_result->execute();
$stats = $stats_result->get_result()->fetch_assoc();

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
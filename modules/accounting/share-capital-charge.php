<?php
// modules/accounting/share-capital-charge.php
require_once '../../config/config.php';
requireRole('admin');

$page_title = 'Share Capital Charge - Single Member';

// Get member for selection
$members = executeQuery("SELECT id, member_no, full_name, total_share_contributions, full_shares_issued, partial_share_balance 
                        FROM members WHERE membership_status = 'active' ORDER BY member_no");

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action == 'process_charge') {
        processSingleMemberCharge();
    } elseif ($action == 'waive_charge') {
        waiveMemberCharge();
    } elseif ($action == 'adjust_charge') {
        adjustMemberCharge();
    } elseif ($action == 'preview_charge') {
        previewMemberCharge();
    }
}

function processSingleMemberCharge()
{
    $conn = getConnection();
    $conn->begin_transaction();

    try {
        $member_id = $_POST['member_id'];
        $charge_amount = floatval($_POST['charge_amount']);
        $charge_date = $_POST['charge_date'] ?? date('Y-m-d');
        $year = date('Y', strtotime($charge_date));
        $description = $_POST['description'] ?? 'Year-end share capital charge';
        $reference_no = $_POST['reference_no'] ?? 'SCC' . time() . rand(100, 999);
        $current_user_id = getCurrentUserId();

        if (!$current_user_id) {
            $current_user_id = 1;
        }

        // Get member details
        $member = getMemberDetails($conn, $member_id);

        if (!$member) {
            throw new Exception("Member not found");
        }

        // Check if member already has a share charge for this year
        $check_sql = "SELECT id FROM admin_charges 
                      WHERE member_id = ? AND charge_type = 'share_shortfall' 
                      AND YEAR(charge_date) = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $member_id, $year);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            throw new Exception("Member already has a share capital charge for year $year");
        }

        // Check if member has sufficient balance
        if ($member['current_balance'] < $charge_amount) {
            throw new Exception("Insufficient balance. Available: " . formatCurrency($member['current_balance']) .
                ", Required: " . formatCurrency($charge_amount));
        }

        // Create admin charge record
        $charge_sql = "INSERT INTO admin_charges 
                      (member_id, charge_type, amount, charge_date, due_date, description, 
                       reference_no, status, created_by, created_at)
                      VALUES (?, 'share_shortfall', ?, ?, ?, ?, ?, 'pending', ?, NOW())";
        $charge_stmt = $conn->prepare($charge_sql);
        $due_date = date('Y-m-d', strtotime('+30 days', strtotime($charge_date)));
        $charge_stmt->bind_param(
            "idssssi",
            $member_id,
            $charge_amount,
            $charge_date,
            $due_date,
            $description,
            $reference_no,
            $current_user_id
        );
        $charge_stmt->execute();
        $charge_id = $conn->insert_id;

        // Deduct from deposits
        $new_balance = $member['current_balance'] - $charge_amount;

        $withdrawal_sql = "INSERT INTO deposits 
                          (member_id, deposit_date, amount, balance, transaction_type, 
                           reference_no, description, created_by, created_at)
                          VALUES (?, ?, ?, ?, 'withdrawal', ?, ?, ?, NOW())";
        $withdrawal_stmt = $conn->prepare($withdrawal_sql);
        $withdrawal_desc = "Share capital charge - $description";
        $withdrawal_stmt->bind_param(
            "isddssi",
            $member_id,
            $charge_date,
            $charge_amount,
            $new_balance,
            $reference_no,
            $withdrawal_desc,
            $current_user_id
        );
        $withdrawal_stmt->execute();

        // Add to share contributions
        $contrib_sql = "INSERT INTO share_contributions 
                       (member_id, amount, contribution_date, reference_no, notes, created_by, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $contrib_stmt = $conn->prepare($contrib_sql);
        $notes = "Share capital charge - $description";
        $contrib_stmt->bind_param(
            "idsssi",
            $member_id,
            $charge_amount,
            $charge_date,
            $reference_no,
            $notes,
            $current_user_id
        );
        $contrib_stmt->execute();

        // Update member share capital
        $new_total = $member['total_share_contributions'] + $charge_amount;
        $new_full_shares = floor($new_total / 10000);
        $new_partial = $new_total - ($new_full_shares * 10000);
        $new_shares_issued = $new_full_shares - $member['full_shares_issued'];

        $update_sql = "UPDATE members SET 
                      total_share_contributions = ?,
                      full_shares_issued = ?,
                      partial_share_balance = ?,
                      last_share_charge_date = ?
                      WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param(
            "didsi",
            $new_total,
            $new_full_shares,
            $new_partial,
            $charge_date,
            $member_id
        );
        $update_stmt->execute();

        // Issue new shares if any
        if ($new_shares_issued > 0) {
            issueNewShares($conn, $member_id, $charge_date, $current_user_id, $member['full_shares_issued'], $new_shares_issued);
        }

        // Create journal entry
        createJournalEntry($conn, $charge_date, $member_id, $charge_amount, $reference_no, $current_user_id);

        // Log the transaction
        logAudit('SHARE_CHARGE', 'admin_charges', $charge_id, null, [
            'member_id' => $member_id,
            'amount' => $charge_amount,
            'year' => $year
        ]);

        $conn->commit();

        $_SESSION['success'] = "Share capital charge of " . formatCurrency($charge_amount) . " processed successfully for {$member['full_name']}.\n";
        if ($new_shares_issued > 0) {
            $_SESSION['success'] .= "New shares issued: $new_shares_issued";
        }

        header('Location: share-capital-charge.php');
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Failed to process charge: ' . $e->getMessage();
        header('Location: share-capital-charge.php');
        exit();
    }
}

function previewMemberCharge()
{
    $conn = getConnection();

    try {
        $member_id = $_POST['member_id'];
        $charge_amount = floatval($_POST['charge_amount'] ?? 1000);
        $year = $_POST['year'] ?? date('Y');

        $member = getMemberDetails($conn, $member_id);

        if (!$member) {
            throw new Exception("Member not found");
        }

        $total_share_value = $member['total_shares_value'] + $member['partial_share_balance'];
        $has_full_share = $total_share_value >= 10000;

        $preview = [
            'member' => $member,
            'charge_amount' => $charge_amount,
            'year' => $year,
            'has_full_share' => $has_full_share,
            'new_total_contributions' => $member['total_share_contributions'] + $charge_amount,
            'new_full_shares' => floor(($member['total_share_contributions'] + $charge_amount) / 10000),
            'new_partial_balance' => ($member['total_share_contributions'] + $charge_amount) - (floor(($member['total_share_contributions'] + $charge_amount) / 10000) * 10000),
            'new_shares_to_issue' => floor(($member['total_share_contributions'] + $charge_amount) / 10000) - $member['full_shares_issued']
        ];

        $_SESSION['charge_preview'] = $preview;
    } catch (Exception $e) {
        $_SESSION['error'] = 'Preview failed: ' . $e->getMessage();
    }

    $conn->close();
    header('Location: share-capital-charge.php');
    exit();
}

function waiveMemberCharge()
{
    $conn = getConnection();
    $conn->begin_transaction();

    try {
        $member_id = $_POST['member_id'];
        $year = $_POST['year'];
        $reason = $_POST['waiver_reason'];
        $current_user_id = getCurrentUserId();

        // Check if charge exists
        $check_sql = "SELECT id FROM admin_charges 
                      WHERE member_id = ? AND charge_type = 'share_shortfall' 
                      AND YEAR(charge_date) = ? AND status = 'pending'";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $member_id, $year);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows == 0) {
            throw new Exception("No pending share capital charge found for this member and year");
        }

        $charge_id = $check_result->fetch_assoc()['id'];

        // Update charge status to waived
        $update_sql = "UPDATE admin_charges 
                       SET status = 'waived', waived_by = ?, waived_at = NOW(), waiver_reason = ?
                       WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("isi", $current_user_id, $reason, $charge_id);
        $update_stmt->execute();

        // Add to share_charge_exceptions
        $exception_sql = "INSERT INTO share_charge_exceptions 
                         (member_id, year, waived, reason, approved_by, approved_at)
                         VALUES (?, ?, 1, ?, ?, NOW())";
        $exception_stmt = $conn->prepare($exception_sql);
        $exception_stmt->bind_param("iisi", $member_id, $year, $reason, $current_user_id);
        $exception_stmt->execute();

        $conn->commit();

        $_SESSION['success'] = "Share capital charge waived successfully for year $year";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Failed to waive charge: ' . $e->getMessage();
    }

    $conn->close();
    header('Location: share-capital-charge.php');
    exit();
}

function adjustMemberCharge()
{
    $conn = getConnection();
    $conn->begin_transaction();

    try {
        $member_id = $_POST['member_id'];
        $year = $_POST['year'];
        $adjustment_amount = floatval($_POST['adjustment_amount']);
        $reason = $_POST['adjustment_reason'];
        $current_user_id = getCurrentUserId();

        // Get existing charge
        $charge_sql = "SELECT id, amount FROM admin_charges 
                      WHERE member_id = ? AND charge_type = 'share_shortfall' 
                      AND YEAR(charge_date) = ? AND status = 'pending'";
        $charge_stmt = $conn->prepare($charge_sql);
        $charge_stmt->bind_param("ii", $member_id, $year);
        $charge_stmt->execute();
        $charge_result = $charge_stmt->get_result();

        if ($charge_result->num_rows == 0) {
            throw new Exception("No pending share capital charge found for this member and year");
        }

        $charge = $charge_result->fetch_assoc();

        // Update charge amount
        $update_sql = "UPDATE admin_charges 
                       SET amount = ?, description = CONCAT(description, ' (Adjusted: ', ? , ')')
                       WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("dsi", $adjustment_amount, $reason, $charge['id']);
        $update_stmt->execute();

        $conn->commit();

        $_SESSION['success'] = "Share capital charge adjusted from " . formatCurrency($charge['amount']) .
            " to " . formatCurrency($adjustment_amount) . " for year $year";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Failed to adjust charge: ' . $e->getMessage();
    }

    $conn->close();
    header('Location: share-capital-charge.php');
    exit();
}

function getMemberDetails($conn, $member_id)
{
    $sql = "SELECT m.*,
            (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE -amount END), 0) 
             FROM deposits WHERE member_id = m.id) as current_balance,
            (SELECT COALESCE(SUM(total_value), 0) FROM shares WHERE member_id = m.id) as total_shares_value,
            (SELECT COALESCE(SUM(amount), 0) FROM share_contributions WHERE member_id = m.id) as total_share_contributions,
            COALESCE(m.full_shares_issued, 0) as full_shares_issued,
            COALESCE(m.partial_share_balance, 0) as partial_share_balance
            FROM members m
            WHERE m.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 0) {
        return null;
    }

    return $result->fetch_assoc();
}

function issueNewShares($conn, $member_id, $issue_date, $user_id, $current_shares, $new_shares_issued)
{
    for ($i = 0; $i < $new_shares_issued; $i++) {
        $share_number = 'SH' . date('Y') . str_pad($member_id, 4, '0', STR_PAD_LEFT) .
            str_pad($current_shares + $i + 1, 3, '0', STR_PAD_LEFT);
        $certificate_number = 'CERT' . time() . rand(1000, 9999) . $i;

        $issue_sql = "INSERT INTO shares_issued 
                      (member_id, share_number, share_count, amount_paid, issue_date, certificate_number, issued_by)
                      VALUES (?, ?, 1, 10000, ?, ?, ?)";
        $issue_stmt = $conn->prepare($issue_sql);
        $issue_stmt->bind_param("isdsi", $member_id, $share_number, $issue_date, $certificate_number, $user_id);
        $issue_stmt->execute();

        // Also add to shares table
        $shares_sql = "INSERT INTO shares 
                      (member_id, shares_count, share_value, total_value, transaction_type, 
                       reference_no, date_purchased, description, created_by)
                      VALUES (?, 1, 10000, 10000, 'share_charge', ?, ?, ?, ?)";
        $shares_stmt = $conn->prepare($shares_sql);
        $ref_no = 'SHARE' . $share_number;
        $desc = "Share issued from manual charge";
        $shares_stmt->bind_param("isssi", $member_id, $ref_no, $issue_date, $desc, $user_id);
        $shares_stmt->execute();
    }
}

function createJournalEntry($conn, $charge_date, $member_id, $amount, $reference_no, $user_id)
{
    $journal_no = 'JNL' . time() . rand(100, 999);

    $journal_sql = "INSERT INTO journal_entries 
                   (entry_date, journal_no, reference_type, reference_id, description, 
                    total_debit, total_credit, status, created_by)
                   VALUES (?, ?, 'share_charge', ?, ?, ?, ?, 'posted', ?)";
    $journal_stmt = $conn->prepare($journal_sql);
    $desc = "Share capital charge for member ID: $member_id";
    $journal_stmt->bind_param("ssidii", $charge_date, $journal_no, $member_id, $desc, $amount, $amount, $user_id);
    $journal_stmt->execute();
    $journal_id = $conn->insert_id;

    // Debit: Share Capital Account
    $detail_sql = "INSERT INTO journal_details 
                  (journal_id, account_code, debit_amount, credit_amount, description)
                  VALUES (?, 'SHARE_CAPITAL', ?, 0, ?)";
    $detail_stmt = $conn->prepare($detail_sql);
    $detail_stmt->bind_param("ids", $journal_id, $amount, $desc);
    $detail_stmt->execute();

    // Credit: Cash/Bank Account
    $detail2_sql = "INSERT INTO journal_details 
                   (journal_id, account_code, debit_amount, credit_amount, description)
                   VALUES (?, 'CASH', 0, ?, ?)";
    $detail2_stmt = $conn->prepare($detail2_sql);
    $detail2_stmt->bind_param("ids", $journal_id, $amount, $desc);
    $detail2_stmt->execute();
}

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Share Capital Charge - Single Member</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="year-end-processing.php">Year-End Processing</a></li>
                <li class="breadcrumb-item active">Single Member Charge</li>
            </ul>
        </div>
        <div class="col-auto">
            <a href="year-end-processing.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Processing
            </a>
        </div>
    </div>
</div>

<!-- Alert Messages -->
<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?php echo nl2br($_SESSION['success']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>
        <?php echo $_SESSION['error']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<!-- Preview Section -->
<?php if (isset($_SESSION['charge_preview'])):
    $preview = $_SESSION['charge_preview'];
    $member = $preview['member'];
    unset($_SESSION['charge_preview']);
?>
    <div class="card mb-4 border-info">
        <div class="card-header bg-info text-white">
            <h5 class="card-title mb-0"><i class="fas fa-eye me-2"></i>Charge Preview</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h6>Member Information</h6>
                    <table class="table table-sm">
                        <tr>
                            <td><strong>Member No:</strong> <?php echo $member['member_no']; ?>
                        </tr>
                        </tr>
                        <tr>
                            <td><strong>Full Name:</strong> <?php echo $member['full_name']; ?> </td>
                        </tr>
                        <tr>
                            <td><strong>Current Balance:</strong> <?php echo formatCurrency($member['current_balance']); ?> </td>
                        </tr>
                        <tr>
                            <td><strong>Total Share Capital:</strong> <?php echo formatCurrency($member['total_shares_value'] + $member['partial_share_balance']); ?> </td>
                        </tr>
                        <tr>
                            <td><strong>Full Shares Owned:</strong> <?php echo number_format($member['full_shares_issued']); ?> </td>
                        </tr>
                        <tr>
                            <td><strong>Partial Balance:</strong> <?php echo formatCurrency($member['partial_share_balance']); ?> </td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <h6>Charge Impact</h6>
                    <table class="table table-sm">
                        <tr>
                            <td><strong>Charge Amount:</strong> <?php echo formatCurrency($preview['charge_amount']); ?> </td>
                        </tr>
                        <tr>
                            <td><strong>Year:</strong> <?php echo $preview['year']; ?> </td>
                        </tr>
                        <tr>
                            <td><strong>Has Full Share:</strong> <?php echo $preview['has_full_share'] ? 'Yes' : 'No'; ?> </td>
                        </tr>
                        <tr>
                            <td><strong>New Total Contributions:</strong> <?php echo formatCurrency($preview['new_total_contributions']); ?> </td>
                        </tr>
                        <tr>
                            <td><strong>New Full Shares:</strong> <?php echo number_format($preview['new_full_shares']); ?> </td>
                        </tr>
                        <tr>
                            <td><strong>New Partial Balance:</strong> <?php echo formatCurrency($preview['new_partial_balance']); ?> </td>
                        </tr>
                        <tr class="table-success">
                            <td><strong>New Shares to Issue:</strong> <?php echo number_format($preview['new_shares_to_issue']); ?> </td>
                        </tr>
                    </table>
                </div>
            </div>
            <div class="text-center mt-3">
                <form method="POST" action="" style="display: inline-block;">
                    <input type="hidden" name="action" value="process_charge">
                    <input type="hidden" name="member_id" value="<?php echo $member['id']; ?>">
                    <input type="hidden" name="charge_amount" value="<?php echo $preview['charge_amount']; ?>">
                    <input type="hidden" name="charge_date" value="<?php echo date('Y-m-d'); ?>">
                    <input type="hidden" name="year" value="<?php echo $preview['year']; ?>">
                    <button type="submit" class="btn btn-success btn-lg" onclick="return confirm('Confirm charge of <?php echo formatCurrency($preview['charge_amount']); ?> for <?php echo $member['full_name']; ?>?')">
                        <i class="fas fa-check-circle me-2"></i>Confirm Charge
                    </button>
                </form>
                <button type="button" class="btn btn-secondary btn-lg" onclick="location.reload()">
                    <i class="fas fa-times me-2"></i>Cancel
                </button>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Main Form -->
<div class="card">
    <div class="card-header bg-primary text-white">
        <h5 class="card-title mb-0"><i class="fas fa-user-plus me-2"></i>Single Member Share Capital Charge</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-12">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Purpose:</strong> Use this module to add or correct share capital charges for individual members that were not captured in the year-end batch processing.
                </div>
            </div>
        </div>

        <form method="POST" action="" id="chargeForm">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="member_id" class="form-label">Select Member <span class="text-danger">*</span></label>
                    <select class="form-control select2" id="member_id" name="member_id" required onchange="loadMemberDetails()">
                        <option value="">-- Select Member --</option>
                        <?php while ($member = $members->fetch_assoc()):
                            $progress = (($member['partial_share_balance'] ?? 0) / 10000) * 100;
                        ?>
                            <option value="<?php echo $member['id']; ?>"
                                data-balance="<?php echo $member['partial_share_balance']; ?>"
                                data-shares="<?php echo $member['full_shares_issued']; ?>"
                                data-contributions="<?php echo $member['total_share_contributions']; ?>">
                                <?php echo $member['member_no']; ?> - <?php echo $member['full_name']; ?>
                                (Shares: <?php echo $member['full_shares_issued']; ?>,
                                Progress: <?php echo number_format($progress, 1); ?>%)
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="col-md-3 mb-3">
                    <label for="charge_amount" class="form-label">Charge Amount (KES) <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" id="charge_amount" name="charge_amount"
                        min="100" step="100" value="1000" required>
                </div>

                <div class="col-md-3 mb-3">
                    <label for="year" class="form-label">Year <span class="text-danger">*</span></label>
                    <select class="form-control" id="year" name="year" required>
                        <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == date('Y') - 1 ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="charge_date" class="form-label">Charge Date</label>
                    <input type="date" class="form-control" id="charge_date" name="charge_date"
                        value="<?php echo date('Y-m-d'); ?>">
                </div>

                <div class="col-md-6 mb-3">
                    <label for="reference_no" class="form-label">Reference Number</label>
                    <input type="text" class="form-control" id="reference_no" name="reference_no"
                        value="SCC<?php echo time(); ?>">
                </div>
            </div>

            <div class="mb-3">
                <label for="description" class="form-label">Description</label>
                <textarea class="form-control" id="description" name="description" rows="2"
                    placeholder="Reason for this charge...">Year-end share capital charge</textarea>
            </div>

            <div id="memberInfo" class="alert alert-info" style="display: none;">
                <strong>Member Share Status:</strong><br>
                <span id="memberSharesInfo"></span>
            </div>

            <hr>

            <div class="row">
                <div class="col-md-12">
                    <button type="submit" name="action" value="preview_charge" class="btn btn-primary btn-lg">
                        <i class="fas fa-eye me-2"></i>Preview Charge
                    </button>
                    <button type="button" class="btn btn-secondary btn-lg" onclick="resetForm()">
                        <i class="fas fa-undo me-2"></i>Reset
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Waive/Adjust Existing Charges -->
<div class="card mt-4">
    <div class="card-header bg-warning">
        <h5 class="card-title mb-0"><i class="fas fa-edit me-2"></i>Manage Existing Charges</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <h6>Waive Existing Charge</h6>
                <form method="POST" action="" class="row g-3">
                    <input type="hidden" name="action" value="waive_charge">

                    <div class="col-md-6">
                        <label for="waive_member_id" class="form-label">Member</label>
                        <select class="form-control" id="waive_member_id" name="member_id" required>
                            <option value="">Select Member</option>
                            <?php
                            $charge_members = executeQuery("SELECT DISTINCT m.id, m.member_no, m.full_name 
                                                            FROM admin_charges ac 
                                                            JOIN members m ON ac.member_id = m.id 
                                                            WHERE ac.charge_type = 'share_shortfall' 
                                                            AND ac.status = 'pending' 
                                                            ORDER BY m.member_no");
                            while ($cm = $charge_members->fetch_assoc()):
                            ?>
                                <option value="<?php echo $cm['id']; ?>">
                                    <?php echo $cm['member_no']; ?> - <?php echo $cm['full_name']; ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label for="waive_year" class="form-label">Year</label>
                        <select class="form-control" id="waive_year" name="year" required>
                            <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                                <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-info w-100" onclick="return confirm('Waive this charge?')">
                            <i class="fas fa-hand-peace"></i> Waive
                        </button>
                    </div>

                    <div class="col-12">
                        <label for="waiver_reason" class="form-label">Waiver Reason</label>
                        <textarea class="form-control" id="waiver_reason" name="waiver_reason" rows="2" required></textarea>
                    </div>
                </form>
            </div>

            <div class="col-md-6">
                <h6>Adjust Existing Charge</h6>
                <form method="POST" action="" class="row g-3">
                    <input type="hidden" name="action" value="adjust_charge">

                    <div class="col-md-6">
                        <label for="adjust_member_id" class="form-label">Member</label>
                        <select class="form-control" id="adjust_member_id" name="member_id" required>
                            <option value="">Select Member</option>
                            <?php
                            $charge_members->data_seek(0);
                            while ($cm = $charge_members->fetch_assoc()):
                            ?>
                                <option value="<?php echo $cm['id']; ?>">
                                    <?php echo $cm['member_no']; ?> - <?php echo $cm['full_name']; ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label for="adjust_year" class="form-label">Year</label>
                        <select class="form-control" id="adjust_year" name="year" required>
                            <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                                <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label for="adjustment_amount" class="form-label">New Amount</label>
                        <input type="number" class="form-control" id="adjustment_amount" name="adjustment_amount"
                            min="0" step="100" required>
                    </div>

                    <div class="col-12">
                        <label for="adjustment_reason" class="form-label">Adjustment Reason</label>
                        <textarea class="form-control" id="adjustment_reason" name="adjustment_reason" rows="2" required></textarea>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-warning" onclick="return confirm('Adjust this charge?')">
                            <i class="fas fa-edit"></i> Adjust Charge
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    function loadMemberDetails() {
        var select = document.getElementById('member_id');
        var selected = select.options[select.selectedIndex];
        var balance = selected.dataset.balance || 0;
        var shares = selected.dataset.shares || 0;
        var contributions = selected.dataset.contributions || 0;
        var totalCapital = (shares * 10000) + parseFloat(balance);

        if (select.value) {
            document.getElementById('memberInfo').style.display = 'block';
            document.getElementById('memberSharesInfo').innerHTML = `
            Full Shares Owned: ${shares}<br>
            Partial Balance: ${formatCurrency(balance)}<br>
            Total Share Capital: ${formatCurrency(totalCapital)}<br>
            Total Contributions: ${formatCurrency(contributions)}<br>
            <strong>Progress to Next Share: ${((balance / 10000) * 100).toFixed(1)}%</strong>
        `;
        } else {
            document.getElementById('memberInfo').style.display = 'none';
        }
    }

    function formatCurrency(amount) {
        return 'KES ' + parseFloat(amount).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function resetForm() {
        document.getElementById('chargeForm').reset();
        document.getElementById('memberInfo').style.display = 'none';
        document.getElementById('member_id').value = '';
    }

    // Initialize Select2
    $(document).ready(function() {
        $('.select2').select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: '-- Select Member --'
        });
    });
</script>

<style>
    .card-header {
        font-weight: 600;
    }

    #memberInfo {
        transition: all 0.3s ease;
    }

    @media (max-width: 768px) {
        .btn {
            width: 100%;
            margin-bottom: 10px;
        }
    }
</style>

<?php include '../../includes/footer.php'; ?>
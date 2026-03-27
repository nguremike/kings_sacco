<?php
// modules/accounting/share-capital-deduction.php
//show php errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);



require_once '../../config/config.php';
requireRole('admin');

$page_title = 'Share Capital Deduction - Individual Member';

// Get member for selection
$members = executeQuery("SELECT m.id, m.member_no, m.full_name, 
                        m.total_share_contributions, m.full_shares_issued, m.partial_share_balance,
                        (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE -amount END), 0) 
                         FROM deposits WHERE member_id = m.id) as current_balance,
                        (SELECT COALESCE(SUM(total_value), 0) FROM shares WHERE member_id = m.id) as total_shares_value
                        FROM members m 
                        WHERE m.membership_status = 'active' 
                        ORDER BY m.member_no");

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action == 'process_deduction') {
        processShareDeduction();
    } elseif ($action == 'preview_deduction') {
        previewShareDeduction();
    } elseif ($action == 'reverse_deduction') {
        reverseShareDeduction();
    } elseif ($action == 'exempt_member') {
        exemptMemberFromDeduction();
    }
}

function processShareDeduction()
{
    $conn = getConnection();
    $conn->begin_transaction();

    try {
        $member_id = $_POST['member_id'];
        $deduction_amount = floatval($_POST['deduction_amount']);
        $deduction_date = $_POST['deduction_date'] ?? date('Y-m-d');
        $year = date('Y', strtotime($deduction_date));
        $description = $_POST['description'] ?? 'Year-end share capital deduction';
        $reference_no = $_POST['reference_no'] ?? 'SCD' . time() . rand(100, 999);
        $current_user_id = getCurrentUserId();

        if (!$current_user_id) {
            $current_user_id = 1;
        }

        // Get member details
        $member = getMemberDetails($conn, $member_id);

        if (!$member) {
            throw new Exception("Member not found");
        }

        // Check if member already has a full share (10,000 or more)
        $total_share_value = $member['total_shares_value'] + $member['partial_share_balance'];

        if ($total_share_value >= 10000) {
            throw new Exception("Member already has a full share (KES " . formatCurrency($total_share_value) . "). No deduction needed.");
        }

        // Check if member has sufficient balance
        if ($member['current_balance'] < $deduction_amount) {
            throw new Exception("Insufficient balance. Available: " . formatCurrency($member['current_balance']) .
                ", Required: " . formatCurrency($deduction_amount));
        }

        // Check if deduction already processed for this year
        $check_sql = "SELECT id FROM admin_charges 
                      WHERE member_id = ? AND charge_type = 'share_shortfall' 
                      AND YEAR(charge_date) = ? AND status = 'processed'";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $member_id, $year);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            throw new Exception("Share capital deduction already processed for member in year $year");
        }

        // Calculate new share totals
        $new_total_contributions = $member['total_share_contributions'] + $deduction_amount;
        $new_full_shares = floor($new_total_contributions / 10000);
        $new_partial_balance = $new_total_contributions - ($new_full_shares * 10000);
        $new_shares_to_issue = $new_full_shares - $member['full_shares_issued'];

        // Create admin charge record
        $charge_sql = "INSERT INTO admin_charges 
                      (member_id, charge_type, amount, charge_date, due_date, description, 
                       reference_no, status, created_by, created_at)
                      VALUES (?, 'share_shortfall', ?, ?, ?, ?, ?, 'processed', ?, NOW())";
        $charge_stmt = $conn->prepare($charge_sql);
        $due_date = date('Y-m-d', strtotime('+30 days', strtotime($deduction_date)));
        $charge_stmt->bind_param(
            "idssssi",
            $member_id,
            $deduction_amount,
            $deduction_date,
            $due_date,
            $description,
            $reference_no,
            $current_user_id
        );
        $charge_stmt->execute();
        $charge_id = $conn->insert_id;

        // Deduct from deposits
        $new_balance = $member['current_balance'] - $deduction_amount;

        $withdrawal_sql = "INSERT INTO deposits 
                          (member_id, deposit_date, amount, balance, transaction_type, 
                           reference_no, description, created_by, created_at)
                          VALUES (?, ?, ?, ?, 'withdrawal', ?, ?, ?, NOW())";
        $withdrawal_stmt = $conn->prepare($withdrawal_sql);
        $withdrawal_desc = "Share capital deduction - $description";
        $withdrawal_stmt->bind_param(
            "isddssi",
            $member_id,
            $deduction_date,
            $deduction_amount,
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
        $notes = "Year-end share capital deduction - $description";
        $contrib_stmt->bind_param(
            "idsssi",
            $member_id,
            $deduction_amount,
            $deduction_date,
            $reference_no,
            $notes,
            $current_user_id
        );
        $contrib_stmt->execute();

        // Update member share capital
        $update_sql = "UPDATE members SET 
                      total_share_contributions = ?,
                      full_shares_issued = ?,
                      partial_share_balance = ?,
                      last_share_charge_date = ?
                      WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param(
            "didsi",
            $new_total_contributions,
            $new_full_shares,
            $new_partial_balance,
            $deduction_date,
            $member_id
        );
        $update_stmt->execute();

        // Issue new shares if any
        if ($new_shares_to_issue > 0) {
            issueNewShares($conn, $member_id, $deduction_date, $current_user_id, $member['full_shares_issued'], $new_shares_to_issue);
        }

        // Create journal entry
        createJournalEntry($conn, $deduction_date, $member_id, $deduction_amount, $reference_no, $current_user_id);

        // Log the transaction
        logAudit('SHARE_DEDUCTION', 'admin_charges', $charge_id, null, [
            'member_id' => $member_id,
            'amount' => $deduction_amount,
            'year' => $year,
            'new_shares' => $new_shares_to_issue
        ]);

        $conn->commit();

        $success_message = "Share capital deduction of " . formatCurrency($deduction_amount) . " processed successfully for {$member['full_name']}.\n";
        $success_message .= "New total contributions: " . formatCurrency($new_total_contributions) . "\n";

        if ($new_shares_to_issue > 0) {
            $success_message .= "✅ New shares issued: $new_shares_to_issue share(s)\n";
        } else {
            $success_message .= "Progress to next share: " . number_format(($new_partial_balance / 10000) * 100, 1) . "%\n";
        }

        $_SESSION['success'] = $success_message;

        header('Location: share-capital-deduction.php');
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Failed to process deduction: ' . $e->getMessage();
        header('Location: share-capital-deduction.php');
        exit();
    }
}

function previewShareDeduction()
{
    $conn = getConnection();

    try {
        $member_id = $_POST['member_id'];
        $deduction_amount = floatval($_POST['deduction_amount'] ?? 1000);
        $year = $_POST['year'] ?? date('Y');

        $member = getMemberDetails($conn, $member_id);

        if (!$member) {
            throw new Exception("Member not found");
        }

        $total_share_value = $member['total_shares_value'] + $member['partial_share_balance'];
        $has_full_share = $total_share_value >= 10000;

        $preview = [
            'member' => $member,
            'deduction_amount' => $deduction_amount,
            'year' => $year,
            'has_full_share' => $has_full_share,
            'current_share_percentage' => ($total_share_value / 10000) * 100,
            'current_shortfall' => max(0, 10000 - $total_share_value),
            'new_total_contributions' => $member['total_share_contributions'] + $deduction_amount,
            'new_full_shares' => floor(($member['total_share_contributions'] + $deduction_amount) / 10000),
            'new_partial_balance' => ($member['total_share_contributions'] + $deduction_amount) -
                (floor(($member['total_share_contributions'] + $deduction_amount) / 10000) * 10000),
            'new_shares_to_issue' => floor(($member['total_share_contributions'] + $deduction_amount) / 10000) - $member['full_shares_issued'],
            'sufficient_balance' => $member['current_balance'] >= $deduction_amount,
            'balance_after' => $member['current_balance'] - $deduction_amount,
            'new_share_percentage' => (($total_share_value + $deduction_amount) / 10000) * 100
        ];

        $_SESSION['deduction_preview'] = $preview;
    } catch (Exception $e) {
        $_SESSION['error'] = 'Preview failed: ' . $e->getMessage();
    }

    $conn->close();
    header('Location: share-capital-deduction.php');
    exit();
}

function reverseShareDeduction()
{
    $conn = getConnection();
    $conn->begin_transaction();

    try {
        $charge_id = $_POST['charge_id'];
        $reason = $_POST['reversal_reason'];
        $current_user_id = getCurrentUserId();

        // Get charge details
        $charge_sql = "SELECT * FROM admin_charges WHERE id = ? AND charge_type = 'share_shortfall'";
        $charge_stmt = $conn->prepare($charge_sql);
        $charge_stmt->bind_param("i", $charge_id);
        $charge_stmt->execute();
        $charge_result = $charge_stmt->get_result();

        if ($charge_result->num_rows == 0) {
            throw new Exception("Charge record not found");
        }

        $charge = $charge_result->fetch_assoc();
        $member_id = $charge['member_id'];
        $amount = $charge['amount'];
        $charge_date = $charge['charge_date'];

        // Get member current share status
        $member = getMemberDetails($conn, $member_id);

        if (!$member) {
            throw new Exception("Member not found");
        }

        // Reverse the share contribution
        $reverse_contrib_sql = "INSERT INTO share_contributions 
                               (member_id, amount, contribution_date, reference_no, notes, created_by, created_at)
                               VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $reverse_contrib_stmt = $conn->prepare($reverse_contrib_sql);
        $reverse_amount = -$amount;
        $reference_no = 'REV' . time() . rand(100, 999);
        $notes = "Reversal of share capital deduction - $reason";
        $reverse_contrib_stmt->bind_param(
            "idsssi",
            $member_id,
            $reverse_amount,
            $charge_date,
            $reference_no,
            $notes,
            $current_user_id
        );
        $reverse_contrib_stmt->execute();

        // Update member share totals
        $new_total_contributions = $member['total_share_contributions'] - $amount;
        $new_full_shares = floor($new_total_contributions / 10000);
        $new_partial_balance = $new_total_contributions - ($new_full_shares * 10000);

        $update_sql = "UPDATE members SET 
                      total_share_contributions = ?,
                      full_shares_issued = ?,
                      partial_share_balance = ?
                      WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param(
            "didi",
            $new_total_contributions,
            $new_full_shares,
            $new_partial_balance,
            $member_id
        );
        $update_stmt->execute();

        // Add money back to deposits
        $new_balance = $member['current_balance'] + $amount;

        $deposit_sql = "INSERT INTO deposits 
                       (member_id, deposit_date, amount, balance, transaction_type, 
                        reference_no, description, created_by, created_at)
                       VALUES (?, ?, ?, ?, 'deposit', ?, ?, ?, NOW())";
        $deposit_stmt = $conn->prepare($deposit_sql);
        $deposit_desc = "Reversal of share capital deduction - $reason";
        $deposit_stmt->bind_param(
            "isddssi",
            $member_id,
            $charge_date,
            $amount,
            $new_balance,
            $reference_no,
            $deposit_desc,
            $current_user_id
        );
        $deposit_stmt->execute();

        // Mark charge as reversed
        $update_charge_sql = "UPDATE admin_charges 
                             SET status = 'reversed', reversed_by = ?, reversed_at = NOW(), reversal_reason = ?
                             WHERE id = ?";
        $update_charge_stmt = $conn->prepare($update_charge_sql);
        $update_charge_stmt->bind_param("isi", $current_user_id, $reason, $charge_id);
        $update_charge_stmt->execute();

        // Delete any shares issued from this deduction
        $delete_shares_sql = "DELETE FROM shares_issued 
                             WHERE member_id = ? AND issue_date = ? 
                             AND certificate_number LIKE 'CERT%' 
                             ORDER BY issue_date DESC LIMIT ?";
        $delete_shares_stmt = $conn->prepare($delete_shares_sql);
        $shares_issued = floor($amount / 10000);
        $delete_shares_stmt->bind_param("isi", $member_id, $charge_date, $shares_issued);
        $delete_shares_stmt->execute();

        $conn->commit();

        $_SESSION['success'] = "Share capital deduction of " . formatCurrency($amount) . " reversed successfully for member.";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Failed to reverse deduction: ' . $e->getMessage();
    }

    $conn->close();
    header('Location: share-capital-deduction.php');
    exit();
}

function exemptMemberFromDeduction()
{
    $conn = getConnection();
    $conn->begin_transaction();

    try {
        $member_id = $_POST['member_id'];
        $year = $_POST['year'];
        $reason = $_POST['exemption_reason'];
        $current_user_id = getCurrentUserId();

        // Check if exemption already exists
        $check_sql = "SELECT id FROM share_charge_exceptions 
                      WHERE member_id = ? AND year = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $member_id, $year);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            throw new Exception("Member already has an exemption for year $year");
        }

        // Add exemption
        $exception_sql = "INSERT INTO share_charge_exceptions 
                         (member_id, year, waived, reason, approved_by, approved_at)
                         VALUES (?, ?, 1, ?, ?, NOW())";
        $exception_stmt = $conn->prepare($exception_sql);
        $exception_stmt->bind_param("iisi", $member_id, $year, $reason, $current_user_id);
        $exception_stmt->execute();

        // Also create an admin note
        $note_sql = "INSERT INTO admin_notes 
                    (member_id, note_date, note_type, subject, content, created_by)
                    VALUES (?, NOW(), 'exemption', 'Share Capital Exemption', ?, ?)";
        $note_stmt = $conn->prepare($note_sql);
        $content = "Exempted from share capital deduction for year $year. Reason: $reason";
        $note_stmt->bind_param("isi", $member_id, $content, $current_user_id);
        $note_stmt->execute();

        $conn->commit();

        $_SESSION['success'] = "Member exempted from share capital deduction for year $year";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Failed to exempt member: ' . $e->getMessage();
    }

    $conn->close();
    header('Location: share-capital-deduction.php');
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
        $certificate_number = 'CERT' . time() . rand(1000, 9999) . $i . rand(10, 99);

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
        $desc = "Share issued from year-end deduction";
        $shares_stmt->bind_param("isssi", $member_id, $ref_no, $issue_date, $desc, $user_id);
        $shares_stmt->execute();
    }
}

function createJournalEntry($conn, $entry_date, $member_id, $amount, $reference_no, $user_id)
{
    // First, check if journal tables exist
    $table_check = $conn->query("SHOW TABLES LIKE 'journal_entries'");
    if ($table_check->num_rows == 0) {
        return true;
    }

    // Get valid account codes from chart_of_accounts
    $account_codes = [];
    $account_result = $conn->query("SELECT account_code FROM chart_of_accounts WHERE account_type IN ('equity', 'liability', 'asset')");
    while ($row = $account_result->fetch_assoc()) {
        $account_codes[] = $row['account_code'];
    }

    // Find appropriate account codes
    $share_capital_account = findAccountCode($conn, 'SHARE_CAPITAL', 'equity');
    $member_deposit_account = findAccountCode($conn, 'MEMBER_DEPOSITS', 'liability');
    $cash_account = findAccountCode($conn, 'CASH', 'asset');

    // If share capital account not found, use a generic equity account
    if (!$share_capital_account) {
        $share_capital_account = getDefaultEquityAccount($conn);
    }

    // If member deposit account not found, use a generic liability account
    if (!$member_deposit_account) {
        $member_deposit_account = getDefaultLiabilityAccount($conn);
    }

    $journal_no = 'JNL' . time() . rand(100, 999);
    $desc = "Share capital deduction for member ID: $member_id - Reference: $reference_no";

    // Insert into journal_entries
    $journal_sql = "INSERT INTO journal_entries 
                   (entry_date, journal_no, reference_type, reference_id, description, 
                    total_debit, total_credit, status, created_by)
                   VALUES (?, ?, 'share_deduction', ?, ?, ?, ?, 'posted', ?)";

    $journal_stmt = $conn->prepare($journal_sql);
    if (!$journal_stmt) {
        error_log("Journal entry prepare failed: " . $conn->error);
        return false;
    }

    $journal_stmt->bind_param(
        "ssisddi",
        $entry_date,
        $journal_no,
        $member_id,
        $desc,
        $amount,
        $amount,
        $user_id
    );

    if (!$journal_stmt->execute()) {
        error_log("Journal entry execute failed: " . $journal_stmt->error);
        return false;
    }

    $journal_id = $conn->insert_id;

    // Create journal details with valid account codes
    $detail_sql = "INSERT INTO journal_details 
                  (journal_id, account_code, debit_amount, credit_amount, description)
                  VALUES (?, ?, ?, 0, ?)";
    $detail_stmt = $conn->prepare($detail_sql);
    if ($detail_stmt) {
        $detail_stmt->bind_param("isds", $journal_id, $share_capital_account, $amount, $desc);
        $detail_stmt->execute();
    }

    $detail2_sql = "INSERT INTO journal_details 
                   (journal_id, account_code, debit_amount, credit_amount, description)
                   VALUES (?, ?, 0, ?, ?)";
    $detail2_stmt = $conn->prepare($detail2_sql);
    if ($detail2_stmt) {
        $detail2_stmt->bind_param("isds", $journal_id, $member_deposit_account, $amount, $desc);
        $detail2_stmt->execute();
    }

    return $journal_id;
}

function findAccountCode($conn, $search_code, $account_type)
{
    $sql = "SELECT account_code FROM chart_of_accounts WHERE account_code = ? OR account_name LIKE ?";
    $like = '%' . $search_code . '%';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $search_code, $like);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        return $result->fetch_assoc()['account_code'];
    }

    return null;
}

function getDefaultEquityAccount($conn)
{
    $sql = "SELECT account_code FROM chart_of_accounts WHERE account_type = 'equity' AND is_active = 1 LIMIT 1";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc()['account_code'];
    }
    return '3000'; // Default equity account code
}

function getDefaultLiabilityAccount($conn)
{
    $sql = "SELECT account_code FROM chart_of_accounts WHERE account_type = 'liability' AND is_active = 1 LIMIT 1";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc()['account_code'];
    }
    return '2000'; // Default liability account code
}
include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Share Capital Deduction - Individual Member</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="year-end-processing.php">Year-End Processing</a></li>
                <li class="breadcrumb-item active">Share Capital Deduction</li>
            </ul>
        </div>
        <div class="col-auto">
            <a href="share-capital-charge.php" class="btn btn-info">
                <i class="fas fa-hand-holding-usd me-2"></i>Manual Charge
            </a>
            <a href="year-end-processing.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back
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
<?php if (isset($_SESSION['deduction_preview'])):
    $preview = $_SESSION['deduction_preview'];
    $member = $preview['member'];
    unset($_SESSION['deduction_preview']);
?>
    <div class="card mb-4 border-info">
        <div class="card-header bg-info text-white">
            <h5 class="card-title mb-0"><i class="fas fa-eye me-2"></i>Deduction Preview</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h6>Member Information</h6>
                    <table class="table table-sm">
                        <tr>
                            <td><strong>Member No:</strong></td>
                            <td><?php echo $member['member_no']; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Full Name:</strong></td>
                            <td><?php echo $member['full_name']; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Current Balance:</strong></td>
                            <td class="<?php echo $preview['sufficient_balance'] ? 'text-success' : 'text-danger'; ?>"><?php echo formatCurrency($member['current_balance']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Total Share Capital:</strong></td>
                            <td><?php echo formatCurrency($member['total_shares_value'] + $member['partial_share_balance']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Full Shares Owned:</strong></td>
                            <td><?php echo number_format($member['full_shares_issued']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Partial Balance:</strong></td>
                            <td><?php echo formatCurrency($member['partial_share_balance']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Progress to Full Share:</strong></td>
                            <td><?php echo number_format($preview['current_share_percentage'], 1); ?>%</td>
                        </tr>
                        <tr>
                            <td><strong>Shortfall to KES 10,000:</strong></td>
                            <td class="text-danger"><?php echo formatCurrency($preview['current_shortfall']); ?></td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <h6>Deduction Impact</h6>
                    <table class="table table-sm">
                        <tr>
                            <td><strong>Deduction Amount:</strong></td>
                            <td><?php echo formatCurrency($preview['deduction_amount']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Year:</strong></td>
                            <td><?php echo $preview['year']; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Balance After Deduction:</strong></td>
                            <td class="<?php echo $preview['balance_after'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo formatCurrency($preview['balance_after']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>New Total Contributions:</strong></td>
                            <td><?php echo formatCurrency($preview['new_total_contributions']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>New Full Shares:</strong></td>
                            <td><?php echo number_format($preview['new_full_shares']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>New Partial Balance:</strong></td>
                            <td><?php echo formatCurrency($preview['new_partial_balance']); ?></td>
                        </tr>
                        <tr class="table-success">
                            <td><strong>New Shares to Issue:</strong></td>
                            <td><?php echo number_format($preview['new_shares_to_issue']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>New Progress:</strong></td>
                            <td><?php echo number_format($preview['new_share_percentage'], 1); ?>%</td>
                        </tr>
                    </table>
                </div>
            </div>

            <?php if (!$preview['sufficient_balance']): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Insufficient Balance!</strong> Member does not have enough funds for this deduction.
                </div>
            <?php endif; ?>

            <div class="text-center mt-3">
                <form method="POST" action="" style="display: inline-block;">
                    <input type="hidden" name="action" value="process_deduction">
                    <input type="hidden" name="member_id" value="<?php echo $member['id']; ?>">
                    <input type="hidden" name="deduction_amount" value="<?php echo $preview['deduction_amount']; ?>">
                    <input type="hidden" name="deduction_date" value="<?php echo date('Y-m-d'); ?>">
                    <input type="hidden" name="year" value="<?php echo $preview['year']; ?>">
                    <button type="submit" class="btn btn-success btn-lg" <?php echo !$preview['sufficient_balance'] ? 'disabled' : ''; ?>
                        onclick="return confirm('Confirm deduction of <?php echo formatCurrency($preview['deduction_amount']); ?> from <?php echo $member['full_name']; ?>?')">
                        <i class="fas fa-check-circle me-2"></i>Confirm Deduction
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
        <h5 class="card-title mb-0"><i class="fas fa-exchange-alt me-2"></i>Share Capital Deduction</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-12">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Purpose:</strong> Deduct funds from member's savings account and transfer to share capital for members who have not reached the full share value of KES 10,000 by year-end.
                </div>
            </div>
        </div>

        <form method="POST" action="" id="deductionForm">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="member_id" class="form-label">Select Member <span class="text-danger">*</span></label>
                    <select class="form-control select2" id="member_id" name="member_id" required onchange="loadMemberDetails()">
                        <option value="">-- Select Member --</option>
                        <?php
                        $members->data_seek(0);
                        while ($member = $members->fetch_assoc()):
                            $total_share = $member['total_shares_value'] + $member['partial_share_balance'];
                            $needs_deduction = $total_share < 10000;
                        ?>
                            <option value="<?php echo $member['id']; ?>"
                                data-balance="<?php echo $member['current_balance']; ?>"
                                data-shares="<?php echo $member['full_shares_issued']; ?>"
                                data-partial="<?php echo $member['partial_share_balance']; ?>"
                                data-total-share="<?php echo $total_share; ?>"
                                data-needs-deduction="<?php echo $needs_deduction ? 'yes' : 'no'; ?>">
                                <?php echo $member['member_no']; ?> - <?php echo $member['full_name']; ?>
                                (Shares: <?php echo $member['full_shares_issued']; ?>,
                                Capital: <?php echo formatCurrency($total_share); ?>)
                                <?php if ($needs_deduction): ?>
                                    - <span class="text-warning">Needs Deduction</span>
                                <?php else: ?>
                                    - <span class="text-success">Complete</span>
                                <?php endif; ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="col-md-3 mb-3">
                    <label for="deduction_amount" class="form-label">Deduction Amount (KES) <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" id="deduction_amount" name="deduction_amount"
                        min="100" step="100" value="1000" required>
                    <small class="text-muted">Recommended: KES 1,000 (or up to shortfall amount)</small>
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
                    <label for="deduction_date" class="form-label">Deduction Date</label>
                    <input type="date" class="form-control" id="deduction_date" name="deduction_date"
                        value="<?php echo date('Y-m-d'); ?>">
                </div>

                <div class="col-md-6 mb-3">
                    <label for="reference_no" class="form-label">Reference Number</label>
                    <input type="text" class="form-control" id="reference_no" name="reference_no"
                        value="SCD<?php echo time(); ?>">
                </div>
            </div>

            <div class="mb-3">
                <label for="description" class="form-label">Description</label>
                <textarea class="form-control" id="description" name="description" rows="2"
                    placeholder="Reason for this deduction...">Year-end share capital deduction</textarea>
            </div>

            <div id="memberInfo" class="alert alert-info" style="display: none;">
                <strong>Member Share Status:</strong><br>
                <span id="memberSharesInfo"></span>
                <div id="deductionWarning" class="mt-2" style="display: none;">
                    <i class="fas fa-exclamation-triangle text-warning"></i>
                    <span class="text-warning">This member already has a full share (KES 10,000+). No deduction needed.</span>
                </div>
                <div id="balanceWarning" class="mt-2" style="display: none;">
                    <i class="fas fa-exclamation-triangle text-danger"></i>
                    <span class="text-danger">Insufficient balance for this deduction amount!</span>
                </div>
            </div>

            <hr>

            <div class="row">
                <div class="col-md-12">
                    <button type="submit" name="action" value="preview_deduction" class="btn btn-primary btn-lg">
                        <i class="fas fa-eye me-2"></i>Preview Deduction
                    </button>
                    <button type="button" class="btn btn-secondary btn-lg" onclick="resetForm()">
                        <i class="fas fa-undo me-2"></i>Reset
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Reversal Section -->
<div class="card mt-4">
    <div class="card-header bg-danger text-white">
        <h5 class="card-title mb-0"><i class="fas fa-undo-alt me-2"></i>Reverse Deduction</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-12">
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Warning:</strong> Reversing a deduction will undo the transaction and may affect share certificates issued.
                </div>
            </div>
        </div>

        <form method="POST" action="" class="row g-3">
            <input type="hidden" name="action" value="reverse_deduction">

            <div class="col-md-5">
                <label for="charge_id" class="form-label">Select Deduction to Reverse</label>
                <select class="form-control" id="charge_id" name="charge_id" required>
                    <option value="">-- Select Transaction --</option>
                    <?php
                    $charges = executeQuery("SELECT ac.id, ac.amount, ac.charge_date, ac.reference_no, 
                                            m.member_no, m.full_name
                                            FROM admin_charges ac
                                            JOIN members m ON ac.member_id = m.id
                                            WHERE ac.charge_type = 'share_shortfall' 
                                            AND ac.status = 'processed'
                                            ORDER BY ac.charge_date DESC
                                            LIMIT 50");
                    while ($charge = $charges->fetch_assoc()):
                    ?>
                        <option value="<?php echo $charge['id']; ?>">
                            <?php echo $charge['member_no']; ?> - <?php echo $charge['full_name']; ?>
                            (<?php echo formatCurrency($charge['amount']); ?> on <?php echo formatDate($charge['charge_date']); ?>)
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="col-md-5">
                <label for="reversal_reason" class="form-label">Reversal Reason</label>
                <textarea class="form-control" id="reversal_reason" name="reversal_reason" rows="2" required></textarea>
            </div>

            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-danger w-100" onclick="return confirm('Are you sure you want to reverse this deduction? This action cannot be undone.')">
                    <i class="fas fa-undo-alt me-2"></i>Reverse Deduction
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Exemption Section -->
<div class="card mt-4">
    <div class="card-header bg-success text-white">
        <h5 class="card-title mb-0"><i class="fas fa-hand-peace me-2"></i>Exempt Member from Deduction</h5>
    </div>
    <div class="card-body">
        <form method="POST" action="" class="row g-3">
            <input type="hidden" name="action" value="exempt_member">

            <div class="col-md-4">
                <label for="exempt_member_id" class="form-label">Select Member</label>
                <select class="form-control" id="exempt_member_id" name="member_id" required>
                    <option value="">-- Select Member --</option>
                    <?php
                    $members->data_seek(0);
                    while ($member = $members->fetch_assoc()):
                    ?>
                        <option value="<?php echo $member['id']; ?>">
                            <?php echo $member['member_no']; ?> - <?php echo $member['full_name']; ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label for="exempt_year" class="form-label">Year</label>
                <select class="form-control" id="exempt_year" name="year" required>
                    <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                        <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="col-md-4">
                <label for="exemption_reason" class="form-label">Exemption Reason</label>
                <textarea class="form-control" id="exemption_reason" name="exemption_reason" rows="2" required></textarea>
            </div>

            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-success w-100">
                    <i class="fas fa-hand-peace me-2"></i>Exempt Member
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function loadMemberDetails() {
        var select = document.getElementById('member_id');
        var selected = select.options[select.selectedIndex];
        var balance = parseFloat(selected.dataset.balance) || 0;
        var shares = parseInt(selected.dataset.shares) || 0;
        var partial = parseFloat(selected.dataset.partial) || 0;
        var totalShare = parseFloat(selected.dataset.totalShare) || 0;
        var needsDeduction = selected.dataset.needsDeduction;
        var deductionAmount = parseFloat(document.getElementById('deduction_amount').value) || 0;

        if (select.value) {
            document.getElementById('memberInfo').style.display = 'block';
            document.getElementById('memberSharesInfo').innerHTML = `
            Full Shares Owned: ${shares}<br>
            Partial Balance: ${formatCurrency(partial)}<br>
            Total Share Capital: ${formatCurrency(totalShare)}<br>
            Progress to Full Share: ${((totalShare / 10000) * 100).toFixed(1)}%<br>
            Shortfall to KES 10,000: ${formatCurrency(Math.max(0, 10000 - totalShare))}
        `;

            // Check if member already has full share
            if (totalShare >= 10000) {
                document.getElementById('deductionWarning').style.display = 'block';
            } else {
                document.getElementById('deductionWarning').style.display = 'none';
            }

            // Check balance sufficiency
            if (deductionAmount > balance) {
                document.getElementById('balanceWarning').style.display = 'block';
            } else {
                document.getElementById('balanceWarning').style.display = 'none';
            }

            // Set max deduction amount to shortfall or balance
            var maxDeduction = Math.min(balance, Math.max(0, 10000 - totalShare));
            document.getElementById('deduction_amount').max = maxDeduction;
            document.getElementById('deduction_amount').placeholder = `Max: ${formatCurrency(maxDeduction)}`;
        } else {
            document.getElementById('memberInfo').style.display = 'none';
        }
    }

    // Update balance warning when amount changes
    document.getElementById('deduction_amount').addEventListener('input', function() {
        var select = document.getElementById('member_id');
        var selected = select.options[select.selectedIndex];
        var balance = parseFloat(selected.dataset.balance) || 0;
        var amount = parseFloat(this.value) || 0;

        if (select.value) {
            if (amount > balance) {
                document.getElementById('balanceWarning').style.display = 'block';
            } else {
                document.getElementById('balanceWarning').style.display = 'none';
            }
        }
    });

    function formatCurrency(amount) {
        return 'KES ' + parseFloat(amount).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function resetForm() {
        document.getElementById('deductionForm').reset();
        document.getElementById('memberInfo').style.display = 'none';
        document.getElementById('member_id').value = '';
        document.getElementById('deduction_amount').value = 1000;
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
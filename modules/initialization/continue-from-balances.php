<?php
// modules/initialization/continue-from-balances.php
require_once '../../config/config.php';
requireRole('admin');

$page_title = 'Continue from Balances';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action == 'continue') {
        $start_date = $_POST['start_date'];
        $verify_balances = $_POST['verify_balances'] ?? false;

        $conn = getConnection();
        $conn->begin_transaction();

        try {
            // Verify all opening balances
            if ($verify_balances) {
                $check_sql = "SELECT 
                             (SELECT COALESCE(SUM(amount), 0) FROM opening_balances WHERE balance_type = 'share') as total_shares,
                             (SELECT COALESCE(SUM(amount), 0) FROM opening_balances WHERE balance_type = 'deposit') as total_deposits,
                             (SELECT COALESCE(SUM(amount), 0) FROM opening_balances WHERE balance_type = 'loan') as total_loans";
                $totals = $conn->query($check_sql)->fetch_assoc();

                // Log verification
                logAudit('VERIFY_OPENING', 'system', 0, null, $totals);
            }

            // Mark members as initialized
            $update_sql = "UPDATE members SET opening_balance_initialized = 1, opening_balance_date = ? 
                          WHERE id IN (SELECT DISTINCT member_id FROM opening_balances)";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("s", $start_date);
            $update_stmt->execute();

            // Create system journal entry for opening balances
            $journal_no = 'OPEN' . date('Ymd') . rand(1000, 9999);
            $journal_sql = "INSERT INTO journal_entries 
                           (entry_date, journal_no, reference_type, description, total_debit, total_credit, 
                            status, created_by, created_at)
                           VALUES (?, ?, 'opening_balance', 'Opening Balances Initialization', 
                           (SELECT COALESCE(SUM(amount), 0) FROM opening_balances WHERE balance_type IN ('deposit', 'share')),
                           (SELECT COALESCE(SUM(amount), 0) FROM opening_balances WHERE balance_type = 'loan'),
                           'posted', ?, NOW())";
            $journal_stmt = $conn->prepare($journal_sql);
            $journal_stmt->bind_param("ssi", $start_date, $journal_no, getCurrentUserId());
            $journal_stmt->execute();

            $conn->commit();
            $_SESSION['success'] = "System initialized successfully. You can now continue with normal transactions from $start_date.";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = 'Failed to initialize: ' . $e->getMessage();
        }

        $conn->close();
        header('Location: ../../dashboard.php');
        exit();
    }
}

// Get summary of opening balances
$summary_sql = "SELECT 
                COUNT(DISTINCT member_id) as total_members,
                (SELECT COALESCE(SUM(amount), 0) FROM opening_balances WHERE balance_type = 'share') as total_shares,
                (SELECT COUNT(*) FROM opening_balances WHERE balance_type = 'share') as share_count,
                (SELECT COALESCE(SUM(amount), 0) FROM opening_balances WHERE balance_type = 'deposit') as total_deposits,
                (SELECT COUNT(*) FROM opening_balances WHERE balance_type = 'deposit') as deposit_count,
                (SELECT COALESCE(SUM(amount), 0) FROM opening_balances WHERE balance_type = 'loan') as total_loans,
                (SELECT COUNT(*) FROM opening_balances WHERE balance_type = 'loan') as loan_count
                FROM opening_balances";
$summary = executeQuery($summary_sql)->fetch_assoc();

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Continue from Balances</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="opening-balances.php">Opening Balances</a></li>
                <li class="breadcrumb-item active">Continue</li>
            </ul>
        </div>
    </div>
</div>

<!-- Summary Card -->
<div class="card mb-4">
    <div class="card-header bg-success text-white">
        <h5 class="card-title mb-0">Opening Balances Summary</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3">
                <div class="text-center p-3 border rounded">
                    <h6>Total Members</h6>
                    <h3><?php echo $summary['total_members'] ?? 0; ?></h3>
                </div>
            </div>
            <div class="col-md-3">
                <div class="text-center p-3 border rounded">
                    <h6>Share Balances</h6>
                    <h3 class="text-primary"><?php echo formatCurrency($summary['total_shares'] ?? 0); ?></h3>
                    <small><?php echo $summary['share_count'] ?? 0; ?> entries</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="text-center p-3 border rounded">
                    <h6>Deposit Balances</h6>
                    <h3 class="text-success"><?php echo formatCurrency($summary['total_deposits'] ?? 0); ?></h3>
                    <small><?php echo $summary['deposit_count'] ?? 0; ?> entries</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="text-center p-3 border rounded">
                    <h6>Loan Balances</h6>
                    <h3 class="text-warning"><?php echo formatCurrency($summary['total_loans'] ?? 0); ?></h3>
                    <small><?php echo $summary['loan_count'] ?? 0; ?> loans</small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Continue Form -->
<div class="card">
    <div class="card-header bg-primary text-white">
        <h5 class="card-title mb-0">Continue to Normal Operations</h5>
    </div>
    <div class="card-body">
        <form method="POST" action="" onsubmit="return confirmContinue()">
            <input type="hidden" name="action" value="continue">

            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                <strong>Ready to continue?</strong> This will mark the opening balances as verified and allow the system to start normal operations.
                All future transactions will be processed from the start date you specify.
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="start_date" class="form-label">System Start Date</label>
                    <input type="date" class="form-control" id="start_date" name="start_date"
                        value="<?php echo date('Y-m-d'); ?>" required>
                    <small class="text-muted">This is the date from which normal transactions will begin</small>
                </div>

                <div class="col-md-6 mb-3">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" id="verify_balances" name="verify_balances" value="1">
                        <label class="form-check-label" for="verify_balances">
                            I have verified that all opening balances are correct
                        </label>
                    </div>
                </div>
            </div>

            <hr>

            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Important:</strong> This action cannot be undone. Ensure all opening balances are correct before proceeding.
            </div>

            <button type="submit" class="btn btn-success btn-lg">
                <i class="fas fa-play-circle me-2"></i>Continue to Normal Operations
            </button>
            <a href="opening-balances.php" class="btn btn-secondary btn-lg">
                <i class="fas fa-arrow-left me-2"></i>Back to Opening Balances
            </a>
        </form>
    </div>
</div>

<script>
    function confirmContinue() {
        return Swal.fire({
            title: 'Continue to Normal Operations?',
            html: 'This will finalize all opening balances and start normal transaction processing.<br><br>' +
                '<strong>Make sure:</strong><br>' +
                '✓ All share balances are correct<br>' +
                '✓ All deposit balances are correct<br>' +
                '✓ All loan balances are correct<br>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, continue!'
        }).then((result) => {
            return result.isConfirmed;
        });
    }
</script>

<?php include '../../includes/footer.php'; ?>
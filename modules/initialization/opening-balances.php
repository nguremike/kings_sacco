<?php
//show php errors
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);




// modules/initialization/opening-balances.php
require_once '../../config/config.php';
requireRole('admin');

$page_title = 'Opening Balances Initialization';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action == 'initialize_shares') {
        initializeSharesBalances();
    } elseif ($action == 'initialize_deposits') {
        initializeDepositsBalances();
    } elseif ($action == 'initialize_loans') {
        initializeLoansBalances();
    } elseif ($action == 'process_batch') {
        processOpeningBalanceBatch();
    }
}

function initializeSharesBalances()
{
    $conn = getConnection();
    $conn->begin_transaction();

    try {
        $effective_date = $_POST['effective_date'];
        $batch_no = 'SHARE' . date('Ymd') . rand(1000, 9999);
        $total_shares = 0;
        $member_count = 0;

        // Get members with share balances from CSV or manual entry
        if (isset($_FILES['shares_file']) && $_FILES['shares_file']['error'] == 0) {
            // Process CSV file
            $file = fopen($_FILES['shares_file']['tmp_name'], 'r');
            $headers = fgetcsv($file);

            while ($row = fgetcsv($file)) {
                $data = array_combine($headers, $row);
                $member = getMemberByNumber($data['member_no']);

                if ($member) {
                    $amount = $data['shares'] * 10000; // 1 share = 10,000
                    $total_shares += $amount;
                    $member_count++;

                    // Insert opening balance record
                    $sql = "INSERT INTO opening_balances 
                            (member_id, balance_type, amount, shares_count, share_value, effective_date, 
                             description, reference_no, created_by) 
                            VALUES (?, 'share', ?, ?, 10000, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $desc = "Opening share balance - {$data['shares']} shares";
                    $stmt->bind_param(
                        "idiissi",
                        $member['id'],
                        $amount,
                        $data['shares'],
                        $effective_date,
                        $desc,
                        $batch_no,
                        getCurrentUserId()
                    );
                    $stmt->execute();
                    $balance_id = $conn->insert_id;

                    // Insert into shares table as opening balance
                    $share_sql = "INSERT INTO shares 
                                  (member_id, shares_count, share_value, total_value, transaction_type, 
                                   reference_no, date_purchased, description, is_opening_balance, 
                                   opening_balance_id, created_by, created_at)
                                  VALUES (?, ?, 10000, ?, 'opening_balance', ?, ?, ?, 1, ?, ?, NOW())";
                    $share_stmt = $conn->prepare($share_sql);
                    $share_stmt->bind_param(
                        "iidsssii",
                        $member['id'],
                        $data['shares'],
                        $amount,
                        $batch_no,
                        $effective_date,
                        $desc,
                        $balance_id,
                        getCurrentUserId()
                    );
                    $share_stmt->execute();
                }
            }
            fclose($file);
        }

        // Create batch record
        $batch_sql = "INSERT INTO opening_balance_batches 
                      (batch_no, batch_date, total_members, total_shares, total_deposits, total_loans, 
                       status, created_by, created_at)
                      VALUES (?, ?, ?, ?, 0, 0, 'processed', ?, NOW())";
        $batch_stmt = $conn->prepare($batch_sql);
        $batch_stmt->bind_param("ssiii", $batch_no, $effective_date, $member_count, $total_shares, getCurrentUserId());
        $batch_stmt->execute();

        $conn->commit();
        $_SESSION['success'] = "Share balances initialized successfully. $member_count members processed, Total: " . formatCurrency($total_shares);
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Failed to initialize share balances: ' . $e->getMessage();
    }

    $conn->close();
    header('Location: opening-balances.php');
    exit();
}

function initializeDepositsBalances()
{
    $conn = getConnection();
    $conn->begin_transaction();

    try {
        $effective_date = $_POST['effective_date'];
        $batch_no = 'DEP' . date('Ymd') . rand(1000, 9999);
        $total_deposits = 0;
        $member_count = 0;

        if (isset($_FILES['deposits_file']) && $_FILES['deposits_file']['error'] == 0) {
            $file = fopen($_FILES['deposits_file']['tmp_name'], 'r');
            $headers = fgetcsv($file);

            while ($row = fgetcsv($file)) {
                $data = array_combine($headers, $row);
                $member = getMemberByNumber($data['member_no']);

                if ($member) {
                    $amount = floatval($data['amount']);
                    $total_deposits += $amount;
                    $member_count++;

                    // Insert opening balance record
                    $sql = "INSERT INTO opening_balances 
                            (member_id, balance_type, amount, effective_date, description, reference_no, created_by) 
                            VALUES (?, 'deposit', ?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $desc = "Opening deposit balance - {$data['description']}";
                    $stmt->bind_param(
                        "idissi",
                        $member['id'],
                        $amount,
                        $effective_date,
                        $desc,
                        $batch_no,
                        getCurrentUserId()
                    );
                    $stmt->execute();
                    $balance_id = $conn->insert_id;

                    // Insert into deposits table as opening balance
                    $deposit_sql = "INSERT INTO deposits 
                                    (member_id, deposit_date, amount, balance, transaction_type, 
                                     reference_no, description, is_opening_balance, opening_balance_id, 
                                     created_by, created_at)
                                    VALUES (?, ?, ?, ?, 'opening_balance', ?, ?, 1, ?, ?, NOW())";
                    $deposit_stmt = $conn->prepare($deposit_sql);
                    $deposit_stmt->bind_param(
                        "isddssii",
                        $member['id'],
                        $effective_date,
                        $amount,
                        $amount,
                        $batch_no,
                        $desc,
                        $balance_id,
                        getCurrentUserId()
                    );
                    $deposit_stmt->execute();
                }
            }
            fclose($file);
        }

        // Update batch
        $batch_sql = "INSERT INTO opening_balance_batches 
                      (batch_no, batch_date, total_members, total_shares, total_deposits, total_loans, 
                       status, created_by, created_at)
                      VALUES (?, ?, ?, 0, ?, 0, 'processed', ?, NOW())";
        $batch_stmt = $conn->prepare($batch_sql);
        $batch_stmt->bind_param("ssiii", $batch_no, $effective_date, $member_count, $total_deposits, getCurrentUserId());
        $batch_stmt->execute();

        $conn->commit();
        $_SESSION['success'] = "Deposit balances initialized successfully. $member_count members processed, Total: " . formatCurrency($total_deposits);
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Failed to initialize deposit balances: ' . $e->getMessage();
    }

    $conn->close();
    header('Location: opening-balances.php');
    exit();
}

function initializeLoansBalances()
{
    $conn = getConnection();
    $conn->begin_transaction();

    try {
        $effective_date = $_POST['effective_date'];
        $batch_no = 'LOAN' . date('Ymd') . rand(1000, 9999);
        $total_loans = 0;
        $loan_count = 0;

        if (isset($_FILES['loans_file']) && $_FILES['loans_file']['error'] == 0) {
            $file = fopen($_FILES['loans_file']['tmp_name'], 'r');
            $headers = fgetcsv($file);

            while ($row = fgetcsv($file)) {
                $data = array_combine($headers, $row);
                $member = getMemberByNumber($data['member_no']);

                if ($member) {
                    $principal = floatval($data['principal']);
                    $interest = floatval($data['interest']) ?? 0;
                    $total = $principal + $interest;
                    $total_loans += $principal;
                    $loan_count++;

                    // Generate loan number
                    $loan_no = 'OLD' . date('Y') . str_pad($loan_count, 4, '0', STR_PAD_LEFT);

                    // Insert opening balance record
                    $sql = "INSERT INTO opening_balances 
                            (member_id, balance_type, amount, loan_id, effective_date, description, reference_no, created_by) 
                            VALUES (?, 'loan', ?, NULL, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $desc = "Opening loan balance - {$data['description']}";
                    $stmt->bind_param(
                        "idissi",
                        $member['id'],
                        $principal,
                        $effective_date,
                        $desc,
                        $batch_no,
                        getCurrentUserId()
                    );
                    $stmt->execute();
                    $balance_id = $conn->insert_id;

                    // Insert into loans table as opening balance
                    $loan_sql = "INSERT INTO loans 
                                (loan_no, member_id, product_id, principal_amount, interest_amount, 
                                 total_amount, duration_months, interest_rate, application_date, 
                                 disbursement_date, status, is_opening_balance, opening_balance_id, 
                                 created_by, created_at)
                                VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, 'active', 1, ?, ?, NOW())";
                    $loan_stmt = $conn->prepare($loan_sql);
                    $loan_stmt->bind_param(
                        "siiddiisssii",
                        $loan_no,
                        $member['id'],
                        $principal,
                        $interest,
                        $total,
                        $data['duration'] ?? 12,
                        $data['interest_rate'] ?? 12,
                        $effective_date,
                        $effective_date,
                        $balance_id,
                        getCurrentUserId()
                    );
                    $loan_stmt->execute();
                    $loan_id = $conn->insert_id;

                    // Update opening balance with loan_id
                    $update_sql = "UPDATE opening_balances SET loan_id = ? WHERE id = ?";
                    $update_stmt = $conn->prepare($update_sql);
                    $update_stmt->bind_param("ii", $loan_id, $balance_id);
                    $update_stmt->execute();
                }
            }
            fclose($file);
        }

        // Update batch
        $batch_sql = "INSERT INTO opening_balance_batches 
                      (batch_no, batch_date, total_members, total_shares, total_deposits, total_loans, 
                       status, created_by, created_at)
                      VALUES (?, ?, ?, 0, 0, ?, 'processed', ?, NOW())";
        $batch_stmt = $conn->prepare($batch_sql);
        $batch_stmt->bind_param("ssiii", $batch_no, $effective_date, $loan_count, $total_loans, getCurrentUserId());
        $batch_stmt->execute();

        $conn->commit();
        $_SESSION['success'] = "Loan balances initialized successfully. $loan_count loans processed, Total: " . formatCurrency($total_loans);
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Failed to initialize loan balances: ' . $e->getMessage();
    }

    $conn->close();
    header('Location: opening-balances.php');
    exit();
}

function getMemberByNumber($member_no)
{
    $sql = "SELECT id, member_no, full_name FROM members WHERE member_no = ?";
    $result = executeQuery($sql, "s", [$member_no]);
    return $result->fetch_assoc();
}

// Get existing batches
$batches_sql = "SELECT * FROM opening_balance_batches ORDER BY created_at DESC LIMIT 20";
$batches = executeQuery($batches_sql);

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Opening Balances Initialization</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="../settings/index.php">Settings</a></li>
                <li class="breadcrumb-item active">Opening Balances</li>
            </ul>
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

<!-- Instructions Card -->
<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="card-title mb-0"><i class="fas fa-info-circle me-2"></i>Opening Balances Initialization Guide</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-8">
                <p>Use this module to initialize opening balances for:</p>
                <ul>
                    <li><strong>Share Balances</strong> - Existing share capital before system start</li>
                    <li><strong>Deposit Balances</strong> - Member savings/deposit balances</li>
                    <li><strong>Loan Balances</strong> - Outstanding loan balances</li>
                </ul>
                <p class="text-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Important:</strong> This should only be done once during system setup.
                    Subsequent balances will be maintained through normal transactions.
                </p>
            </div>
            <div class="col-md-4">
                <div class="card bg-light">
                    <div class="card-body">
                        <h6>Sample CSV Format:</h6>
                        <pre class="small">member_no,shares,description<br>MEM001,50,Opening shares<br>MEM002,25,Opening shares</pre>
                        <a href="sample-shares.csv" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-download"></i> Download Sample
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Share Balances Initialization -->
<div class="card mb-4">
    <div class="card-header bg-success text-white">
        <h5 class="card-title mb-0"><i class="fas fa-chart-pie me-2"></i>1. Initialize Share Balances</h5>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" class="row">
            <input type="hidden" name="action" value="initialize_shares">

            <div class="col-md-4 mb-3">
                <label for="effective_date_shares" class="form-label">Effective Date</label>
                <input type="date" class="form-control" id="effective_date_shares" name="effective_date"
                    value="<?php echo date('Y-m-d'); ?>" required>
            </div>

            <div class="col-md-6 mb-3">
                <label for="shares_file" class="form-label">Upload CSV File</label>
                <input type="file" class="form-control" id="shares_file" name="shares_file"
                    accept=".csv" required>
                <small class="text-muted">CSV format: member_no, shares, description (optional)</small>
            </div>

            <div class="col-md-2 mb-3">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-success w-100">
                    <i class="fas fa-upload"></i> Upload Shares
                </button>
            </div>
        </form>

        <!-- Manual Entry Form -->
        <div class="mt-3">
            <button class="btn btn-sm btn-outline-success" type="button" data-bs-toggle="collapse" data-bs-target="#manualShares">
                <i class="fas fa-plus-circle"></i> Manual Entry
            </button>

            <div class="collapse mt-3" id="manualShares">
                <div class="card card-body">
                    <h6>Manual Share Balance Entry</h6>
                    <form method="POST" class="row">
                        <input type="hidden" name="action" value="manual_shares">

                        <div class="col-md-3 mb-2">
                            <select class="form-control" name="member_id" required>
                                <option value="">Select Member</option>
                                <?php
                                $members = executeQuery("SELECT id, member_no, full_name FROM members ORDER BY member_no");
                                while ($m = $members->fetch_assoc()) {
                                    echo "<option value='{$m['id']}'>{$m['member_no']} - {$m['full_name']}</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-2 mb-2">
                            <input type="number" class="form-control" name="shares" placeholder="Shares" required>
                        </div>
                        <div class="col-md-3 mb-2">
                            <input type="text" class="form-control" name="description" placeholder="Description">
                        </div>
                        <div class="col-md-2 mb-2">
                            <button type="submit" class="btn btn-sm btn-success">Add</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Deposit Balances Initialization -->
<div class="card mb-4">
    <div class="card-header bg-info text-white">
        <h5 class="card-title mb-0"><i class="fas fa-piggy-bank me-2"></i>2. Initialize Deposit Balances</h5>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" class="row">
            <input type="hidden" name="action" value="initialize_deposits">

            <div class="col-md-4 mb-3">
                <label for="effective_date_deposits" class="form-label">Effective Date</label>
                <input type="date" class="form-control" id="effective_date_deposits" name="effective_date"
                    value="<?php echo date('Y-m-d'); ?>" required>
            </div>

            <div class="col-md-6 mb-3">
                <label for="deposits_file" class="form-label">Upload CSV File</label>
                <input type="file" class="form-control" id="deposits_file" name="deposits_file"
                    accept=".csv" required>
                <small class="text-muted">CSV format: member_no, amount, description (optional)</small>
            </div>

            <div class="col-md-2 mb-3">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-info w-100">
                    <i class="fas fa-upload"></i> Upload Deposits
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Loan Balances Initialization -->
<div class="card mb-4">
    <div class="card-header bg-warning">
        <h5 class="card-title mb-0"><i class="fas fa-hand-holding-usd me-2"></i>3. Initialize Loan Balances</h5>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" class="row">
            <input type="hidden" name="action" value="initialize_loans">

            <div class="col-md-4 mb-3">
                <label for="effective_date_loans" class="form-label">Effective Date</label>
                <input type="date" class="form-control" id="effective_date_loans" name="effective_date"
                    value="<?php echo date('Y-m-d'); ?>" required>
            </div>

            <div class="col-md-6 mb-3">
                <label for="loans_file" class="form-label">Upload CSV File</label>
                <input type="file" class="form-control" id="loans_file" name="loans_file"
                    accept=".csv" required>
                <small class="text-muted">CSV format: member_no, principal, interest, duration, interest_rate, description</small>
            </div>

            <div class="col-md-2 mb-3">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-warning w-100">
                    <i class="fas fa-upload"></i> Upload Loans
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Recent Batches -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title">Recent Opening Balance Batches</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>Batch No</th>
                        <th>Date</th>
                        <th>Members</th>
                        <th>Shares</th>
                        <th>Deposits</th>
                        <th>Loans</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($batch = $batches->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?php echo $batch['batch_no']; ?></strong></td>
                            <td><?php echo formatDate($batch['batch_date']); ?></td>
                            <td><?php echo $batch['total_members']; ?></td>
                            <td><?php echo formatCurrency($batch['total_shares']); ?></td>
                            <td><?php echo formatCurrency($batch['total_deposits']); ?></td>
                            <td><?php echo formatCurrency($batch['total_loans']); ?></td>
                            <td>
                                <span class="badge bg-<?php
                                                        echo $batch['status'] == 'posted' ? 'success' : ($batch['status'] == 'verified' ? 'info' : ($batch['status'] == 'processed' ? 'primary' : 'warning'));
                                                        ?>">
                                    <?php echo ucfirst($batch['status']); ?>
                                </span>
                            </td>
                            <td><?php echo formatDate($batch['created_at']); ?></td>
                            <td>
                                <a href="view-batch.php?id=<?php echo $batch['id']; ?>" class="btn btn-sm btn-info">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if ($batch['status'] == 'processed'): ?>
                                    <button class="btn btn-sm btn-success" onclick="verifyBatch(<?php echo $batch['id']; ?>)">
                                        <i class="fas fa-check"></i>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mt-4">
    <div class="col-md-4">
        <div class="stats-card primary">
            <div class="stats-icon">
                <i class="fas fa-chart-pie"></i>
            </div>
            <div class="stats-content">
                <?php
                $total_shares = executeQuery("SELECT COALESCE(SUM(amount), 0) as total FROM opening_balances WHERE balance_type = 'share'")->fetch_assoc()['total'];
                ?>
                <h3><?php echo formatCurrency($total_shares); ?></h3>
                <p>Total Share Balances</p>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="stats-card success">
            <div class="stats-icon">
                <i class="fas fa-piggy-bank"></i>
            </div>
            <div class="stats-content">
                <?php
                $total_deposits = executeQuery("SELECT COALESCE(SUM(amount), 0) as total FROM opening_balances WHERE balance_type = 'deposit'")->fetch_assoc()['total'];
                ?>
                <h3><?php echo formatCurrency($total_deposits); ?></h3>
                <p>Total Deposit Balances</p>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="stats-card warning">
            <div class="stats-icon">
                <i class="fas fa-hand-holding-usd"></i>
            </div>
            <div class="stats-content">
                <?php
                $total_loans = executeQuery("SELECT COALESCE(SUM(amount), 0) as total FROM opening_balances WHERE balance_type = 'loan'")->fetch_assoc()['total'];
                ?>
                <h3><?php echo formatCurrency($total_loans); ?></h3>
                <p>Total Loan Balances</p>
            </div>
        </div>
    </div>
</div>

<script>
    function verifyBatch(id) {
        Swal.fire({
            title: 'Verify Batch',
            text: 'Are you sure you want to verify this batch? This confirms the balances are correct.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, verify'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'verify-batch.php?id=' + id;
            }
        });
    }

    // File upload preview
    document.getElementById('shares_file')?.addEventListener('change', function(e) {
        var fileName = e.target.files[0]?.name;
        if (fileName) {
            // Could show preview
        }
    });
</script>

<style>
    .stats-card {
        margin-bottom: 15px;
    }

    .card-header {
        font-weight: 600;
    }

    .pre {
        background: #f8f9fa;
        padding: 10px;
        border-radius: 5px;
        font-size: 11px;
    }

    @media (max-width: 768px) {
        .col-md-2 .btn {
            margin-top: 10px;
        }
    }
</style>

<?php include '../../includes/footer.php'; ?>
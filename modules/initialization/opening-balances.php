<?php
// modules/initialization/opening-balances.php
//show php errors
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);



require_once '../../config/config.php';
requireRole('admin');

$page_title = 'Opening Balances Initialization';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action == 'initialize_loans') {
        initializeLoansBalances();
    }
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

            // Clean headers
            $headers = array_map(function ($h) {
                $h = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $h);
                return trim(strtolower($h));
            }, $headers);

            error_log("CSV Headers: " . print_r($headers, true));

            $line_number = 1;
            $errors = [];

            while (($row = fgetcsv($file)) !== false) {
                $line_number++;

                // Skip empty rows
                if (empty(array_filter($row))) {
                    continue;
                }

                // Map data to headers
                $data = array_combine($headers, $row);
                error_log("Line $line_number data: " . print_r($data, true));

                // Get member by number - try multiple possible column names
                $member_no = '';
                if (isset($data['member_no']) && !empty($data['member_no'])) {
                    $member_no = trim($data['member_no']);
                } elseif (isset($data['memberno']) && !empty($data['memberno'])) {
                    $member_no = trim($data['memberno']);
                } elseif (isset($data['member number']) && !empty($data['member number'])) {
                    $member_no = trim($data['member number']);
                } elseif (isset($data['m/no']) && !empty($data['m/no'])) {
                    $member_no = trim($data['m/no']);
                } elseif (isset($data['mno']) && !empty($data['mno'])) {
                    $member_no = trim($data['mno']);
                }

                if (empty($member_no)) {
                    $errors[] = "Line $line_number: Member number missing. Available fields: " . implode(', ', array_keys($data));
                    continue;
                }

                error_log("Looking for member: '$member_no'");

                // Try to find member with multiple methods
                $member = getMemberByNumberAdvanced($conn, $member_no);

                if (!$member) {
                    // Try with the member number from the database to see what's available
                    $all_members = $conn->query("SELECT member_no, full_name FROM members LIMIT 10");
                    $available_members = [];
                    while ($m = $all_members->fetch_assoc()) {
                        $available_members[] = $m['member_no'] . ' - ' . $m['full_name'];
                    }
                    $errors[] = "Line $line_number: Member not found - No: '$member_no'. Available members: " . implode(', ', array_slice($available_members, 0, 5)) . "...";
                    continue;
                }

                error_log("Found member: " . $member['member_no'] . " - " . $member['full_name']);

                $principal = floatval($data['principal'] ?? 0);
                $interest = floatval($data['interest'] ?? 0);
                $total = $principal + $interest;
                $duration = intval($data['duration'] ?? 12);
                $interest_rate = floatval($data['interest_rate'] ?? 12);
                $description = $data['description'] ?? 'Opening loan balance';
                $disbursement_date = $data['disbursement_date'] ?? $effective_date;

                if ($principal <= 0) {
                    $errors[] = "Line $line_number: Invalid principal amount: $principal";
                    continue;
                }

                // Generate loan number
                $loan_no = 'OLD' . date('Y') . str_pad($loan_count + 1, 4, '0', STR_PAD_LEFT);

                // Insert opening balance record
                $current_user_id = getCurrentUserId();
                if (!$current_user_id) {
                    $current_user_id = 1; // Default admin
                }

                $balance_sql = "INSERT INTO opening_balances 
                               (member_id, balance_type, amount, loan_id, effective_date, description, reference_no, created_by)
                               VALUES (?, 'loan', ?, NULL, ?, ?, ?, ?)";
                $balance_stmt = $conn->prepare($balance_sql);
                $balance_desc = $description;
                $balance_ref = $batch_no;
                $balance_amount = $principal;
                $balance_effective = $effective_date;
                $balance_created_by = $current_user_id;
                $balance_stmt->bind_param(
                    "idsssi",
                    $member['id'],
                    $balance_amount,
                    $balance_effective,
                    $balance_desc,
                    $balance_ref,
                    $balance_created_by
                );
                $balance_stmt->execute();
                $balance_id = $conn->insert_id;

                // Insert into loans table as opening balance
                $loan_sql = "INSERT INTO loans 
                            (loan_no, member_id, product_id, principal_amount, interest_amount, 
                             total_amount, duration_months, interest_rate, application_date, 
                             disbursement_date, status, is_opening_balance, opening_balance_id, 
                             created_by, created_at)
                            VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, 'active', 1, ?, ?, NOW())";
                $loan_stmt = $conn->prepare($loan_sql);
                $loan_loan_no = $loan_no;
                $loan_member_id = $member['id'];
                $loan_principal = $principal;
                $loan_interest = $interest;
                $loan_total = $total;
                $loan_duration = $duration;
                $loan_interest_rate = $interest_rate;
                $loan_application_date = $disbursement_date;
                $loan_disbursement_date = $disbursement_date;
                $loan_balance_id = $balance_id;
                $loan_created_by = $current_user_id;

                $loan_stmt->bind_param(
                    "sidddiissii",
                    $loan_loan_no,
                    $loan_member_id,
                    $loan_principal,
                    $loan_interest,
                    $loan_total,
                    $loan_duration,
                    $loan_interest_rate,
                    $loan_application_date,
                    $loan_disbursement_date,
                    $loan_balance_id,
                    $loan_created_by
                );
                $loan_stmt->execute();
                $loan_id = $conn->insert_id;

                // Update opening balance with loan_id
                $update_sql = "UPDATE opening_balances SET loan_id = ? WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_loan_id = $loan_id;
                $update_balance_id = $balance_id;
                $update_stmt->bind_param("ii", $update_loan_id, $update_balance_id);
                $update_stmt->execute();

                $total_loans += $principal;
                $loan_count++;
            }

            fclose($file);

            // Create batch record if any loans were imported
            if ($loan_count > 0) {
                $batch_sql = "INSERT INTO opening_balance_batches 
                             (batch_no, batch_date, total_members, total_shares, total_deposits, total_loans, 
                              status, notes, created_by, created_at)
                             VALUES (?, ?, ?, 0, 0, ?, 'processed', ?, ?, NOW())";
                $batch_stmt = $conn->prepare($batch_sql);
                $batch_no_val = $batch_no;
                $batch_date_val = $effective_date;
                $batch_members_val = $loan_count;
                $batch_loans_val = $total_loans;
                $batch_notes = "Imported $loan_count loan balances totaling " . formatCurrency($total_loans);
                $batch_created_by = $current_user_id ?? 1;

                $batch_stmt->bind_param(
                    "ssiisi",
                    $batch_no_val,
                    $batch_date_val,
                    $batch_members_val,
                    $batch_loans_val,
                    $batch_notes,
                    $batch_created_by
                );
                $batch_stmt->execute();
            }

            $conn->commit();

            $_SESSION['success'] = "Loan balances initialized successfully.\n";
            $_SESSION['success'] .= "Loans: $loan_count\n";
            $_SESSION['success'] .= "Total Amount: " . formatCurrency($total_loans);

            if (!empty($errors)) {
                $_SESSION['import_errors'] = $errors;
            }
        } else {
            throw new Exception("No file uploaded or file error");
        }
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Failed to initialize loan balances: ' . $e->getMessage();
        error_log("Loan initialization error: " . $e->getMessage());
    }

    $conn->close();

    // FIXED: Ensure no output before redirect
    if (ob_get_length()) ob_clean();
    header('Location: opening-balances.php');
    exit();
}

function getMemberByNumberAdvanced($conn, $member_no)
{
    // Strategy 1: Exact match
    $sql = "SELECT id, member_no, full_name FROM members WHERE member_no = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $member_no);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }

    // Strategy 2: Case-insensitive match
    $sql = "SELECT id, member_no, full_name FROM members WHERE LOWER(member_no) = LOWER(?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $member_no);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }

    // Strategy 3: Remove leading zeros and try
    $cleaned = ltrim($member_no, '0');
    if ($cleaned != $member_no) {
        $sql = "SELECT id, member_no, full_name FROM members WHERE member_no = ? OR member_no LIKE ?";
        $like = '%' . $cleaned . '%';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $cleaned, $like);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            return $result->fetch_assoc();
        }
    }

    // Strategy 4: Try to match by phone number if member_no looks like phone
    if (strlen($member_no) == 10 && substr($member_no, 0, 1) == '0') {
        $sql = "SELECT id, member_no, full_name FROM members WHERE phone = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $member_no);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            return $result->fetch_assoc();
        }
    }

    // Strategy 5: Try to match by ID if member_no is numeric and looks like ID
    if (is_numeric($member_no) && strlen($member_no) >= 6 && strlen($member_no) <= 8) {
        $sql = "SELECT id, member_no, full_name FROM members WHERE national_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $member_no);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            return $result->fetch_assoc();
        }
    }

    return null;
}

function getMemberByNumber($member_no)
{
    error_log("Searching for member: " . $member_no);

    // Try exact match first
    $sql = "SELECT id, member_no, full_name FROM members WHERE member_no = ?";
    $result = executeQuery($sql, "s", [$member_no]);

    if ($result->num_rows > 0) {
        error_log("Found exact match: " . $member_no);
        return $result->fetch_assoc();
    }

    // Try with LIKE for partial matches
    $like_no = '%' . $member_no . '%';
    $sql2 = "SELECT id, member_no, full_name FROM members WHERE member_no LIKE ?";
    $result2 = executeQuery($sql2, "s", [$like_no]);

    if ($result2->num_rows > 0) {
        error_log("Found LIKE match: " . $member_no);
        return $result2->fetch_assoc();
    }

    // Try without leading zeros
    $clean_no = ltrim($member_no, '0');
    if ($clean_no != $member_no) {
        $sql3 = "SELECT id, member_no, full_name FROM members WHERE member_no = ? OR member_no LIKE ?";
        $like_clean = '%' . $clean_no . '%';
        $result3 = executeQuery($sql3, "ss", [$clean_no, $like_clean]);

        if ($result3->num_rows > 0) {
            error_log("Found cleaned match: " . $clean_no);
            return $result3->fetch_assoc();
        }
    }

    error_log("No member found for: " . $member_no);
    return null;
}

include '../../includes/header.php';
?>

<!-- Rest of the HTML remains the same -->

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
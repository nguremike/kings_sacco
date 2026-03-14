<?php
// modules/initialization/import-share-contributions.php
//show php errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);




require_once '../../config/config.php';
requireRole('admin');

$page_title = 'Import Share Contributions';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action == 'import_contributions') {
        importShareContributions();
    } elseif ($action == 'process_contributions') {
        processShareContributions();
    } elseif ($action == 'manual_contribution') {
        addManualContribution();
    }
}

function importShareContributions()
{
    $conn = getConnection();
    $conn->begin_transaction();

    try {
        $effective_date = $_POST['effective_date'];
        $batch_no = 'SHARECONTRIB' . date('Ymd') . rand(1000, 9999);
        $total_amount = 0;
        $record_count = 0;

        if (isset($_FILES['contributions_file']) && $_FILES['contributions_file']['error'] == 0) {
            $file = fopen($_FILES['contributions_file']['tmp_name'], 'r');
            $headers = fgetcsv($file);

            // Clean headers
            $headers = array_map(function ($h) {
                $h = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $h);
                return trim(strtolower($h));
            }, $headers);

            while ($row = fgetcsv($file)) {
                // Skip empty rows
                if (empty(array_filter($row))) {
                    continue;
                }

                // Map data to headers
                $data = array_combine($headers, $row);
                $member = getMemberByNumber($data['member_no']);

                if ($member) {
                    $amount = floatval($data['amount']);
                    $contrib_date = $data['date'] ?? $effective_date;
                    $reference = $data['reference'] ?? 'IMPORT' . time();
                    $description = $data['description'] ?? 'Imported share contribution';

                    $total_amount += $amount;
                    $record_count++;

                    // Insert into share_contribution_imports
                    $sql = "INSERT INTO share_contribution_imports 
                            (batch_no, member_id, contribution_amount, contribution_date, reference_no, description)
                            VALUES (?, ?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("sidsss", $batch_no, $member['id'], $amount, $contrib_date, $reference, $description);
                    $stmt->execute();

                    // Also record in opening_balances
                    $ob_sql = "INSERT INTO opening_balances 
                              (member_id, balance_type, amount, effective_date, description, reference_no, created_by)
                              VALUES (?, 'share_contribution', ?, ?, ?, ?, ?)";
                    $ob_stmt = $conn->prepare($ob_sql);
                    $ob_desc = "Imported share contribution: KES " . number_format($amount);
                    $created_by = getCurrentUserId();
                    $ob_stmt->bind_param("issssi", $member['id'], $amount, $effective_date, $ob_desc, $batch_no, $created_by);
                    $ob_stmt->execute();
                }
            }
            fclose($file);

            // Create batch record
            $batch_sql = "INSERT INTO opening_balance_batches 
                          (batch_no, batch_date, total_members, total_shares, total_deposits, total_loans, 
                           status, notes, created_by, created_at)
                          VALUES (?, ?, ?, 0, 0, 0, 'processed', ?, ?, NOW())";
            $batch_stmt = $conn->prepare($batch_sql);
            $notes = "Imported $record_count share contributions totaling " . formatCurrency($total_amount);
            $created_by = getCurrentUserId();
            $batch_stmt->bind_param("ssisi", $batch_no, $effective_date, $record_count, $notes, $created_by);
            $batch_stmt->execute();

            $conn->commit();
            $_SESSION['success'] = "Share contributions imported successfully.\n";
            $_SESSION['success'] .= "Records: $record_count\n";
            $_SESSION['success'] .= "Total Amount: " . formatCurrency($total_amount);
        } else {
            throw new Exception("No file uploaded or file error");
        }
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Failed to import contributions: ' . $e->getMessage();
    }

    $conn->close();
    header('Location: import-share-contributions.php');
    exit();
}

function processShareContributions()
{
    $conn = getConnection();
    $conn->begin_transaction();

    try {
        $batch_no = $_POST['batch_no'];
        $share_value = 10000; // 1 share = KES 10,000
        $created_by = getCurrentUserId();

        // Get all unprocessed contributions for this batch
        $contributions_sql = "SELECT * FROM share_contribution_imports 
                              WHERE batch_no = ? AND processed = 0";
        $contributions = $conn->prepare($contributions_sql);
        $contributions->bind_param("s", $batch_no);
        $contributions->execute();
        $result = $contributions->get_result();

        $total_processed = 0;
        $total_full_shares = 0;
        $total_partial = 0;

        while ($row = $result->fetch_assoc()) {
            $member_id = $row['member_id'];
            $amount = $row['contribution_amount'];

            // Get member's current share status
            $member_sql = "SELECT total_share_contributions, full_shares_issued, partial_share_balance 
                          FROM members WHERE id = ?";
            $member_stmt = $conn->prepare($member_sql);
            $member_stmt->bind_param("i", $member_id);
            $member_stmt->execute();
            $member_result = $member_stmt->get_result();
            $member = $member_result->fetch_assoc();

            // Calculate new totals
            $current_total = $member['total_share_contributions'] ?? 0;
            $current_shares = $member['full_shares_issued'] ?? 0;
            $current_partial = $member['partial_share_balance'] ?? 0;

            $new_total = $current_total + $amount;
            $new_full_shares = floor($new_total / $share_value);
            $new_partial = $new_total - ($new_full_shares * $share_value);

            // Calculate new shares issued
            $new_shares_issued = $new_full_shares - $current_shares;

            if ($new_shares_issued > 0) {
                // Issue new full shares
                for ($i = 0; $i < $new_shares_issued; $i++) {
                    $share_number = 'SH' . date('Y') . str_pad($member_id, 4, '0', STR_PAD_LEFT) .
                        str_pad($current_shares + $i + 1, 3, '0', STR_PAD_LEFT);

                    // FIXED: Generate unique certificate number using multiple unique components
                    $certificate_number = 'CERT' .
                        date('Ymd') .          // Current date
                        str_pad($member_id, 4, '0', STR_PAD_LEFT) .  // Member ID
                        str_pad($current_shares + $i + 1, 3, '0', STR_PAD_LEFT) .  // Share sequence
                        rand(100, 999);        // Random suffix

                    // Alternative: Use uniqid() for guaranteed uniqueness
                    // $certificate_number = 'CERT' . uniqid() . $i;

                    $issue_sql = "INSERT INTO shares_issued 
                      (member_id, share_number, share_count, amount_paid, issue_date, certificate_number, issued_by)
                      VALUES (?, ?, 1, ?, ?, ?, ?)";
                    $issue_stmt = $conn->prepare($issue_sql);
                    $issue_stmt->bind_param(
                        "isdssi",
                        $member_id,
                        $share_number,
                        $share_value,
                        $row['contribution_date'],
                        $certificate_number,
                        $created_by
                    );

                    if (!$issue_stmt->execute()) {
                        throw new Exception("Failed to insert share certificate: " . $issue_stmt->error);
                    }

                    // Also add to shares table
                    $shares_sql = "INSERT INTO shares 
                      (member_id, shares_count, share_value, total_value, transaction_type, 
                       reference_no, date_purchased, description, created_by, is_opening_balance)
                      VALUES (?, 1, ?, ?, 'opening_balance', ?, ?, ?, ?, 1)";
                    $shares_stmt = $conn->prepare($shares_sql);
                    $desc = "Share issued from imported contribution";
                    $reference_no = 'IMP' . time() . rand(1000, 9999) . $i;
                    $shares_stmt->bind_param(
                        "iddsssi",
                        $member_id,
                        $share_value,
                        $share_value,
                        $reference_no,
                        $row['contribution_date'],
                        $desc,
                        $created_by
                    );
                    $shares_stmt->execute();
                }

                $total_full_shares += $new_shares_issued;
            }

            // Record as share contribution
            $contrib_sql = "INSERT INTO share_contributions 
                           (member_id, amount, contribution_date, reference_no, notes, created_by, created_at)
                           VALUES (?, ?, ?, ?, ?, ?, NOW())";
            $contrib_stmt = $conn->prepare($contrib_sql);
            $notes = "Imported contribution - " . $row['description'];
            $contrib_stmt->bind_param(
                "idsssi",
                $member_id,           // i - integer
                $amount,              // d - double
                $row['contribution_date'], // s - string
                $row['reference_no'], // s - string
                $notes,               // s - string
                $created_by           // i - integer
            );
            $contrib_stmt->execute();

            // Update member totals
            $update_sql = "UPDATE members SET 
                          total_share_contributions = ?,
                          full_shares_issued = ?,
                          partial_share_balance = ?,
                          imported_contributions = imported_contributions + ?,
                          imported_shares_issued = imported_shares_issued + ?
                          WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param(
                "dididi",
                $new_total,           // d - double
                $new_full_shares,     // i - integer
                $new_partial,         // d - double
                $amount,              // d - double
                $new_shares_issued,   // i - integer
                $member_id            // i - integer
            );
            $update_stmt->execute();

            // Mark as processed
            $process_sql = "UPDATE share_contribution_imports SET processed = 1 WHERE id = ?";
            $process_stmt = $conn->prepare($process_sql);
            $process_stmt->bind_param("i", $row['id']);
            $process_stmt->execute();

            $total_processed++;
            $total_partial += $new_partial;
        }

        $conn->commit();
        $_SESSION['success'] = "Share contributions processed successfully.\n";
        $_SESSION['success'] .= "Processed: $total_processed contributions\n";
        $_SESSION['success'] .= "New full shares issued: $total_full_shares\n";
        $_SESSION['success'] .= "Total partial balances: " . formatCurrency($total_partial);
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Failed to process contributions: ' . $e->getMessage();
        error_log("Process contributions error: " . $e->getMessage());
    }

    $conn->close();
    header('Location: import-share-contributions.php');
    exit();
}

function addManualContribution()
{
    $conn = getConnection();
    $conn->begin_transaction();

    try {
        $member_id = $_POST['member_id'];
        $amount = $_POST['amount'];
        $contribution_date = $_POST['contribution_date'];
        $reference_no = $_POST['reference_no'] ?? 'MANUAL' . time();
        $description = $_POST['description'] ?? 'Manual share contribution';
        $created_by = getCurrentUserId();
        $share_value = 10000;

        // Get member's current share status
        $member_sql = "SELECT total_share_contributions, full_shares_issued, partial_share_balance 
                      FROM members WHERE id = ?";
        $member_stmt = $conn->prepare($member_sql);
        $member_stmt->bind_param("i", $member_id);
        $member_stmt->execute();
        $member_result = $member_stmt->get_result();
        $member = $member_result->fetch_assoc();

        // Calculate new totals
        $current_total = $member['total_share_contributions'] ?? 0;
        $current_shares = $member['full_shares_issued'] ?? 0;

        $new_total = $current_total + $amount;
        $new_full_shares = floor($new_total / $share_value);
        $new_partial = $new_total - ($new_full_shares * $share_value);
        $new_shares_issued = $new_full_shares - $current_shares;

        if ($new_shares_issued > 0) {
            // Issue new full shares
            for ($i = 0; $i < $new_shares_issued; $i++) {
                $share_number = 'SH' . date('Y') . str_pad($member_id, 4, '0', STR_PAD_LEFT) .
                    str_pad($current_shares + $i + 1, 3, '0', STR_PAD_LEFT);
                $certificate_number = 'CERT' . time() . rand(1000, 9999) . $i;

                $issue_sql = "INSERT INTO shares_issued 
              (member_id, share_number, share_count, amount_paid, issue_date, certificate_number, issued_by)
              VALUES (?, ?, 1, ?, ?, ?, ?)";
                $issue_stmt = $conn->prepare($issue_sql);
                $issue_stmt->bind_param(
                    "isdsii",
                    $member_id,           // i - integer
                    $share_number,        // s - string
                    $share_value,         // d - double
                    $contribution_date,   // s - string
                    $certificate_number,  // s - string
                    $created_by           // i - integer
                );
                $issue_stmt->execute();
            }
        }

        // Add share contribution
        $contrib_sql = "INSERT INTO share_contributions 
                       (member_id, amount, contribution_date, reference_no, notes, created_by, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $contrib_stmt = $conn->prepare($contrib_sql);
        $contrib_stmt->bind_param("idsssi", $member_id, $amount, $contribution_date, $reference_no, $description, $created_by);
        $contrib_stmt->execute();

        // Update member
        $update_sql = "UPDATE members SET 
                      total_share_contributions = ?,
                      full_shares_issued = ?,
                      partial_share_balance = ?,
                      imported_contributions = imported_contributions + ?,
                      imported_shares_issued = imported_shares_issued + ?
                      WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("dididi", $new_total, $new_full_shares, $new_partial, $amount, $new_shares_issued, $member_id);
        $update_stmt->execute();

        // Record in opening_balances
        $ob_sql = "INSERT INTO opening_balances 
                  (member_id, balance_type, amount, effective_date, description, reference_no, created_by,
                   contribution_progress, full_shares_from_contrib, remaining_balance)
                  VALUES (?, 'share_contribution', ?, ?, ?, ?, ?, ?, ?, ?)";
        $ob_stmt = $conn->prepare($ob_sql);
        $progress = ($new_partial / $share_value) * 100;
        $ob_stmt->bind_param("isissiiid", $member_id, $amount, $contribution_date, $description, $reference_no, $created_by, $progress, $new_full_shares, $new_partial);
        $ob_stmt->execute();

        $conn->commit();
        $_SESSION['success'] = "Manual contribution added successfully.\n";
        $_SESSION['success'] .= "Amount: " . formatCurrency($amount) . "\n";
        if ($new_shares_issued > 0) {
            $_SESSION['success'] .= "New shares issued: $new_shares_issued\n";
        }
        $_SESSION['success'] .= "Current partial balance: " . formatCurrency($new_partial);
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Failed to add manual contribution: ' . $e->getMessage();
        error_log("Manual contribution error: " . $e->getMessage());
    }

    $conn->close();
    header('Location: import-share-contributions.php');
    exit();
}

function getMemberByNumber($member_no)
{
    $sql = "SELECT id, member_no, full_name FROM members WHERE member_no = ?";
    $result = executeQuery($sql, "s", [$member_no]);
    return $result->fetch_assoc();
}

// Get unprocessed batches
$batches_sql = "SELECT DISTINCT batch_no, MIN(created_at) as created_at, 
                COUNT(*) as record_count, SUM(contribution_amount) as total_amount
                FROM share_contribution_imports 
                WHERE processed = 0
                GROUP BY batch_no
                ORDER BY created_at DESC";
$batches = executeQuery($batches_sql);

// Get processed batches
$processed_sql = "SELECT DISTINCT batch_no, MIN(created_at) as created_at, 
                  COUNT(*) as record_count, SUM(contribution_amount) as total_amount
                  FROM share_contribution_imports 
                  WHERE processed = 1
                  GROUP BY batch_no
                  ORDER BY created_at DESC
                  LIMIT 10";
$processed = executeQuery($processed_sql);

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Import Share Contributions</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="opening-balances.php">Opening Balances</a></li>
                <li class="breadcrumb-item active">Import Share Contributions</li>
            </ul>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#manualModal">
                <i class="fas fa-plus-circle"></i> Manual Entry
            </button>
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

<!-- Instructions Card -->
<div class="card mb-4">
    <div class="card-header bg-info text-white">
        <h5 class="card-title mb-0"><i class="fas fa-info-circle me-2"></i>Import Share Contributions Guide</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-8">
                <p>Use this module to import existing share contributions (partial payments) from your legacy system.</p>
                <ul>
                    <li><strong>1 Share = KES 10,000</strong> - Members can contribute any amount</li>
                    <li>Contributions accumulate until they reach KES 10,000</li>
                    <li>When a member reaches KES 10,000, a full share is automatically issued</li>
                    <li>Import contributions will be processed to issue any full shares</li>
                </ul>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Example:</strong> If a member has contributed KES 24,000 in total:
                    <ul class="mb-0 mt-1">
                        <li>2 full shares will be issued (KES 20,000)</li>
                        <li>KES 4,000 remains as partial balance</li>
                        <li>Progress to next share: 40%</li>
                    </ul>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-light">
                    <div class="card-body">
                        <h6>Sample CSV Format:</h6>
                        <pre class="small">member_no,amount,date,reference,description
MEM001,2400,2024-01-15,CONTRIB001,January contribution
MEM001,2600,2024-02-15,CONTRIB002,February contribution
MEM002,5000,2024-01-20,CONTRIB003,Initial contribution</pre>
                        <a href="sample-contributions.csv" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-download"></i> Download Sample
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Import Form -->
<div class="card mb-4">
    <div class="card-header bg-success text-white">
        <h5 class="card-title mb-0"><i class="fas fa-file-import"></i> Import Contributions CSV</h5>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" class="row">
            <input type="hidden" name="action" value="import_contributions">

            <div class="col-md-3 mb-3">
                <label for="effective_date" class="form-label">Effective Date</label>
                <input type="date" class="form-control" id="effective_date" name="effective_date"
                    value="<?php echo date('Y-m-d'); ?>" required>
            </div>

            <div class="col-md-6 mb-3">
                <label for="contributions_file" class="form-label">CSV File</label>
                <input type="file" class="form-control" id="contributions_file" name="contributions_file"
                    accept=".csv" required>
                <small class="text-muted">CSV with headers: member_no, amount, date, reference, description</small>
            </div>

            <div class="col-md-3 mb-3">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-success w-100">
                    <i class="fas fa-upload"></i> Upload & Import
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Unprocessed Batches -->
<?php if ($batches->num_rows > 0): ?>
    <div class="card mb-4">
        <div class="card-header bg-warning">
            <h5 class="card-title mb-0"><i class="fas fa-clock"></i> Pending Processing</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Batch No</th>
                            <th>Upload Date</th>
                            <th>Records</th>
                            <th>Total Amount</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($batch = $batches->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?php echo $batch['batch_no']; ?></strong></td>
                                <td><?php echo formatDate($batch['created_at']); ?></td>
                                <td><?php echo $batch['record_count']; ?></td>
                                <td><?php echo formatCurrency($batch['total_amount']); ?></td>
                                <td>
                                    <form method="POST" style="display: inline;" onsubmit="return confirmProcess()">
                                        <input type="hidden" name="action" value="process_contributions">
                                        <input type="hidden" name="batch_no" value="<?php echo $batch['batch_no']; ?>">
                                        <button type="submit" class="btn btn-sm btn-success">
                                            <i class="fas fa-cogs"></i> Process Batch
                                        </button>
                                    </form>
                                    <a href="view-batch-details.php?batch=<?php echo $batch['batch_no']; ?>" class="btn btn-sm btn-info">
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
<?php endif; ?>

<!-- Processed Batches -->
<?php if ($processed->num_rows > 0): ?>
    <div class="card mb-4">
        <div class="card-header bg-secondary text-white">
            <h5 class="card-title mb-0"><i class="fas fa-history"></i> Recently Processed</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Batch No</th>
                            <th>Processed Date</th>
                            <th>Records</th>
                            <th>Total Amount</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($batch = $processed->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $batch['batch_no']; ?></td>
                                <td><?php echo formatDate($batch['created_at']); ?></td>
                                <td><?php echo $batch['record_count']; ?></td>
                                <td><?php echo formatCurrency($batch['total_amount']); ?></td>
                                <td>
                                    <a href="view-batch-details.php?batch=<?php echo $batch['batch_no']; ?>" class="btn btn-sm btn-info">
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
<?php endif; ?>

<!-- Summary Statistics -->
<div class="row">
    <div class="col-md-4">
        <div class="stats-card primary">
            <div class="stats-icon">
                <i class="fas fa-coins"></i>
            </div>
            <div class="stats-content">
                <?php
                $total_imported = executeQuery("SELECT COALESCE(SUM(amount), 0) as total FROM share_contributions WHERE created_by IS NOT NULL")->fetch_assoc()['total'];
                ?>
                <h3><?php echo formatCurrency($total_imported); ?></h3>
                <p>Total Imported Contributions</p>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="stats-card success">
            <div class="stats-icon">
                <i class="fas fa-chart-pie"></i>
            </div>
            <div class="stats-content">
                <?php
                $total_shares = executeQuery("SELECT COALESCE(SUM(shares_count), 0) as total FROM shares WHERE transaction_type = 'opening_balance'")->fetch_assoc()['total'];
                ?>
                <h3><?php echo number_format($total_shares); ?></h3>
                <p>Shares Issued from Imports</p>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="stats-card warning">
            <div class="stats-icon">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="stats-content">
                <?php
                $avg_contribution = executeQuery("SELECT COALESCE(AVG(amount), 0) as avg FROM share_contributions")->fetch_assoc()['avg'];
                ?>
                <h3><?php echo formatCurrency($avg_contribution); ?></h3>
                <p>Average Contribution</p>
            </div>
        </div>
    </div>
</div>

<!-- Manual Entry Modal -->
<div class="modal fade" id="manualModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Manual Share Contribution Entry</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="manual_contribution">

                    <div class="mb-3">
                        <label for="member_id" class="form-label">Member</label>
                        <select class="form-control" id="member_id" name="member_id" required>
                            <option value="">Select Member</option>
                            <?php
                            $members = executeQuery("SELECT id, member_no, full_name, total_share_contributions, 
                                                    full_shares_issued, partial_share_balance 
                                                    FROM members ORDER BY member_no");
                            while ($m = $members->fetch_assoc()):
                                $progress = ($m['partial_share_balance'] / 10000) * 100;
                            ?>
                                <option value="<?php echo $m['id']; ?>"
                                    data-shares="<?php echo $m['full_shares_issued']; ?>"
                                    data-partial="<?php echo $m['partial_share_balance']; ?>">
                                    <?php echo $m['member_no']; ?> - <?php echo $m['full_name']; ?>
                                    (Shares: <?php echo $m['full_shares_issued']; ?>,
                                    Partial: <?php echo formatCurrency($m['partial_share_balance']); ?>)
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="amount" class="form-label">Contribution Amount (KES)</label>
                        <input type="number" class="form-control" id="amount" name="amount" min="1" step="100" required>
                    </div>

                    <div class="mb-3">
                        <label for="contribution_date" class="form-label">Contribution Date</label>
                        <input type="date" class="form-control" id="contribution_date" name="contribution_date"
                            value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="reference_no" class="form-label">Reference Number</label>
                        <input type="text" class="form-control" id="reference_no" name="reference_no"
                            value="MANUAL<?php echo time(); ?>">
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <input type="text" class="form-control" id="description" name="description"
                            value="Manual share contribution entry">
                    </div>

                    <div class="alert alert-info" id="sharePreview" style="display: none;">
                        <strong>Preview:</strong><br>
                        <span id="previewText"></span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Contribution</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function confirmProcess() {
        return confirm('Are you sure you want to process this batch? This will issue shares for any contributions that have reached KES 10,000.');
    }

    // Preview share calculation
    document.getElementById('amount')?.addEventListener('input', function() {
        var select = document.getElementById('member_id');
        var selected = select.options[select.selectedIndex];

        if (selected.value) {
            var existingShares = parseFloat(selected.dataset.shares) || 0;
            var existingPartial = parseFloat(selected.dataset.partial) || 0;
            var amount = parseFloat(this.value) || 0;

            var total = existingPartial + amount;
            var newShares = Math.floor(total / 10000);
            var remaining = total % 10000;

            var preview = document.getElementById('sharePreview');
            var previewText = document.getElementById('previewText');

            if (newShares > 0) {
                preview.style.display = 'block';
                previewText.innerHTML = 'This contribution will issue <strong>' + newShares + '</strong> new share(s).<br>' +
                    'Remaining partial balance: <strong>KES ' + remaining.toLocaleString() + '</strong>';
            } else {
                preview.style.display = 'block';
                previewText.innerHTML = 'No full shares will be issued yet.<br>' +
                    'New partial balance: <strong>KES ' + remaining.toLocaleString() + '</strong>';
            }
        }
    });

    // Update preview when member changes
    document.getElementById('member_id')?.addEventListener('change', function() {
        if (document.getElementById('amount').value) {
            document.getElementById('amount').dispatchEvent(new Event('input'));
        }
    });
</script>

<style>
    .stats-card {
        margin-bottom: 15px;
    }

    .pre {
        background: #f8f9fa;
        padding: 10px;
        border-radius: 5px;
        font-size: 11px;
    }

    #sharePreview {
        transition: all 0.3s ease;
    }

    @media (max-width: 768px) {
        .col-md-2 .btn {
            margin-top: 10px;
        }
    }
</style>

<?php include '../../includes/footer.php'; ?>
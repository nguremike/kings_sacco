<?php
// modules/deposits/monthly-import.php
require_once '../../config/config.php';
requireRole('admin');

$page_title = 'Import Monthly Contributions';

// Handle process batch request
if (isset($_GET['process_batch'])) {
    $batch_no = $_GET['process_batch'];
    processPendingBatch($batch_no);
}

// Handle file upload and import
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action == 'upload_file') {
        uploadContributionFile();
    } elseif ($action == 'process_import') {
        processMonthlyContributions();
    } elseif ($action == 'preview_file') {
        previewContributionFile();
    } elseif ($action == 'process_pending') {
        $batch_no = $_POST['batch_no'];
        processPendingBatch($batch_no);
    }
}

function uploadContributionFile()
{
    $conn = getConnection();
    $conn->begin_transaction();

    try {
        $year = intval($_POST['year'] ?? date('Y'));
        $import_date = $_POST['import_date'] ?? date('Y-m-d');
        $default_description = $_POST['default_description'] ?? 'Monthly contribution - ';
        $batch_no = 'MONTHLY' . date('Ymd') . rand(1000, 9999);

        $uploaded = 0;
        $skipped = 0;
        $errors = [];
        $total_amount = 0;

        if (isset($_FILES['contribution_file']) && $_FILES['contribution_file']['error'] == 0) {
            $file = fopen($_FILES['contribution_file']['tmp_name'], 'r');

            // Read headers
            $headers = fgetcsv($file);

            // Clean headers
            $headers = array_map(function ($h) {
                $h = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $h);
                $h = trim($h);
                $h = str_replace(['"', "'"], '', $h);
                return $h;
            }, $headers);

            // Expected format: M/NO, Names, Jan, Feb, Mar, Apr, May, Jun, Jul, Aug, Sep, Oct, Nov, Dec
            $line_number = 1;

            while (($row = fgetcsv($file)) !== false) {
                $line_number++;

                // Skip empty rows
                if (empty(array_filter($row))) {
                    continue;
                }

                // Ensure row has enough columns
                if (count($row) < 13) {
                    $errors[] = "Line $line_number: Insufficient columns. Expected 13, got " . count($row);
                    $skipped++;
                    continue;
                }

                $member_no = trim($row[0]);
                $member_name = trim($row[1]);

                // Find member by member number
                $member = getMemberByNumber($member_no);

                if (!$member) {
                    $errors[] = "Line $line_number: Member not found - No: $member_no, Name: $member_name";
                    $skipped++;
                    continue;
                }

                // Process each month (Jan = index 2, Feb = 3, etc.)
                $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                $month_index = 2; // Start from column 2 (Jan)

                foreach ($months as $month_name) {
                    $amount = isset($row[$month_index]) ? floatval(str_replace(',', '', $row[$month_index])) : 0;

                    if ($amount > 0) {
                        // Create month date (first day of the month)
                        $month_num = array_search($month_name, $months) + 1;
                        $month_date = sprintf("%04d-%02d-01", $year, $month_num);

                        // Check if contribution already exists for this month
                        $check_sql = "SELECT id FROM deposits 
                                     WHERE member_id = ? 
                                     AND MONTH(deposit_date) = ? 
                                     AND YEAR(deposit_date) = ?
                                     AND transaction_type = 'deposit'
                                     AND description LIKE '%Monthly contribution%'";
                        $check_stmt = $conn->prepare($check_sql);
                        $check_stmt->bind_param("iii", $member['id'], $month_num, $year);
                        $check_stmt->execute();
                        $check_result = $check_stmt->get_result();

                        if ($check_result->num_rows > 0) {
                            $errors[] = "Line $line_number: Contribution already exists for {$member_name} - {$month_name} {$year}";
                            $skipped++;
                            continue;
                        }

                        // Insert into monthly_contribution_imports
                        $insert_sql = "INSERT INTO monthly_contribution_imports 
                                      (batch_no, member_id, member_no, member_name, 
                                       contribution_month, amount, month_name, year, 
                                       reference_no, status, created_at)
                                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
                        $insert_stmt = $conn->prepare($insert_sql);
                        $reference_no = 'IMP' . $batch_no . $month_num;
                        $insert_stmt->bind_param(
                            "sisssdiss",
                            $batch_no,
                            $member['id'],
                            $member_no,
                            $member_name,
                            $month_date,
                            $amount,
                            $month_name,
                            $year,
                            $reference_no
                        );
                        $insert_stmt->execute();

                        $uploaded++;
                        $total_amount += $amount;
                    }

                    $month_index++;
                }
            }

            fclose($file);

            // Create batch record
            $batch_sql = "INSERT INTO contribution_import_batches 
                         (batch_no, import_date, year, total_records, total_amount, 
                          status, filename, created_by, created_at)
                         VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, NOW())";
            $batch_stmt = $conn->prepare($batch_sql);
            $filename = $_FILES['contribution_file']['name'];
            $current_user = getCurrentUserId();
            $batch_stmt->bind_param("ssiidss", $batch_no, $import_date, $year, $uploaded, $total_amount, $filename, $current_user);
            $batch_stmt->execute();

            $conn->commit();

            $_SESSION['import_batch'] = $batch_no;
            $_SESSION['success'] = "File uploaded successfully!\n";
            $_SESSION['success'] .= "Records found: $uploaded contributions\n";
            $_SESSION['success'] .= "Total amount: " . formatCurrency($total_amount) . "\n";
            if (!empty($errors)) {
                $_SESSION['import_errors'] = $errors;
            }
        } else {
            throw new Exception("No file uploaded or file error");
        }
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Upload failed: ' . $e->getMessage();
        error_log("Monthly import upload error: " . $e->getMessage());
    }

    $conn->close();
    header('Location: monthly-import.php');
    exit();
}

function processMonthlyContributions()
{
    $conn = getConnection();
    $conn->begin_transaction();

    try {
        $batch_no = $_POST['batch_no'];
        $deposit_type = $_POST['deposit_type'] ?? 'Monthly contribution';
        $create_recurring = isset($_POST['create_recurring']) ? true : false;
        $current_user = getCurrentUserId();

        // Get all pending imports for this batch
        $imports_sql = "SELECT * FROM monthly_contribution_imports 
                        WHERE batch_no = ? AND status = 'pending'";
        $imports_stmt = $conn->prepare($imports_sql);
        $imports_stmt->bind_param("s", $batch_no);
        $imports_stmt->execute();
        $imports_result = $imports_stmt->get_result();

        $processed = 0;
        $total_amount = 0;
        $errors = [];

        while ($row = $imports_result->fetch_assoc()) {
            try {
                // Get current balance
                $balance_sql = "SELECT COALESCE(SUM(CASE 
                                    WHEN transaction_type = 'deposit' THEN amount 
                                    WHEN transaction_type = 'withdrawal' THEN -amount 
                                    ELSE 0 
                                END), 0) as current_balance 
                                FROM deposits 
                                WHERE member_id = ?";
                $balance_stmt = $conn->prepare($balance_sql);
                $balance_stmt->bind_param("i", $row['member_id']);
                $balance_stmt->execute();
                $balance_result = $balance_stmt->get_result();
                $current_balance = $balance_result->fetch_assoc()['current_balance'];

                // Calculate new balance
                $new_balance = $current_balance + $row['amount'];

                // Insert deposit
                $deposit_sql = "INSERT INTO deposits 
                               (member_id, deposit_date, amount, balance, transaction_type, 
                                reference_no, description, created_by, created_at)
                               VALUES (?, ?, ?, ?, 'deposit', ?, ?, ?, NOW())";
                $deposit_stmt = $conn->prepare($deposit_sql);
                $description = $deposit_type . ' - ' . $row['month_name'] . ' ' . $row['year'];
                $reference_no = 'MONTHLY-' . $row['year'] . '-' . $row['month_name'] . '-' . $row['member_no'];

                $deposit_stmt->bind_param(
                    "isddssi",
                    $row['member_id'],
                    $row['contribution_month'],
                    $row['amount'],
                    $new_balance,
                    $reference_no,
                    $description,
                    $current_user
                );
                $deposit_stmt->execute();
                $deposit_id = $conn->insert_id;

                // Create transaction record
                $trans_no = 'TXN' . time() . rand(100, 999);
                $trans_sql = "INSERT INTO transactions 
                             (transaction_no, transaction_date, description, debit_account, 
                              credit_account, amount, reference_type, reference_id, created_by)
                             VALUES (?, ?, ?, 'CASH', 'MEMBER_DEPOSITS', ?, 'deposit', ?, ?)";
                $trans_stmt = $conn->prepare($trans_sql);
                $trans_desc = "Monthly contribution - {$row['member_name']} - {$row['month_name']} {$row['year']}";
                $trans_stmt->bind_param(
                    "sssiii",
                    $trans_no,
                    $row['contribution_month'],
                    $trans_desc,
                    $row['amount'],
                    $deposit_id,
                    $current_user
                );
                $trans_stmt->execute();

                // Mark as processed
                $update_sql = "UPDATE monthly_contribution_imports 
                               SET status = 'processed', processed_at = NOW() 
                               WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("i", $row['id']);
                $update_stmt->execute();

                $processed++;
                $total_amount += $row['amount'];
            } catch (Exception $e) {
                $errors[] = "Failed to process {$row['member_name']} - {$row['month_name']}: " . $e->getMessage();

                // Mark as skipped
                $skip_sql = "UPDATE monthly_contribution_imports 
                             SET status = 'skipped', notes = ? 
                             WHERE id = ?";
                $skip_stmt = $conn->prepare($skip_sql);
                $error_msg = $e->getMessage();
                $skip_stmt->bind_param("si", $error_msg, $row['id']);
                $skip_stmt->execute();
            }
        }

        // Update batch status
        $batch_sql = "UPDATE contribution_import_batches 
                      SET processed_records = ?, 
                          skipped_records = ?, 
                          status = 'completed', 
                          completed_at = NOW() 
                      WHERE batch_no = ?";
        $batch_stmt = $conn->prepare($batch_sql);
        $skipped_count = count($errors);
        $batch_stmt->bind_param("iis", $processed, $skipped_count, $batch_no);
        $batch_stmt->execute();

        $conn->commit();

        $_SESSION['success'] = "Monthly contributions processed successfully!\n";
        $_SESSION['success'] .= "Processed: $processed contributions\n";
        $_SESSION['success'] .= "Total amount: " . formatCurrency($total_amount) . "\n";
        if (!empty($errors)) {
            $_SESSION['import_errors'] = $errors;
        }
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Processing failed: ' . $e->getMessage();
        error_log("Monthly import processing error: " . $e->getMessage());
    }

    $conn->close();
    header('Location: monthly-import.php');
    exit();
}

function processPendingBatch($batch_no)
{
    $conn = getConnection();
    $conn->begin_transaction();

    try {
        // Get batch information
        $batch_sql = "SELECT * FROM contribution_import_batches WHERE batch_no = ?";
        $batch_stmt = $conn->prepare($batch_sql);
        $batch_stmt->bind_param("s", $batch_no);
        $batch_stmt->execute();
        $batch_result = $batch_stmt->get_result();

        if ($batch_result->num_rows == 0) {
            throw new Exception("Batch not found");
        }

        $batch = $batch_result->fetch_assoc();

        // Get all pending imports for this batch
        $imports_sql = "SELECT * FROM monthly_contribution_imports 
                        WHERE batch_no = ? AND status = 'pending'";
        $imports_stmt = $conn->prepare($imports_sql);
        $imports_stmt->bind_param("s", $batch_no);
        $imports_stmt->execute();
        $imports_result = $imports_stmt->get_result();

        if ($imports_result->num_rows == 0) {
            $_SESSION['warning'] = "No pending imports found in this batch.";
            header('Location: view-batch.php?batch=' . $batch_no);
            exit();
        }

        $current_user = getCurrentUserId();
        $processed = 0;
        $total_amount = 0;
        $errors = [];

        while ($row = $imports_result->fetch_assoc()) {
            try {
                // Check if deposit already exists (double-check)
                $check_sql = "SELECT id FROM deposits 
                             WHERE member_id = ? 
                             AND DATE(deposit_date) = ? 
                             AND amount = ? 
                             AND transaction_type = 'deposit'";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("isd", $row['member_id'], $row['contribution_month'], $row['amount']);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();

                if ($check_result->num_rows > 0) {
                    // Already exists, just mark as processed
                    $update_sql = "UPDATE monthly_contribution_imports 
                                   SET status = 'processed', processed_at = NOW(), notes = 'Already existed'
                                   WHERE id = ?";
                    $update_stmt = $conn->prepare($update_sql);
                    $update_stmt->bind_param("i", $row['id']);
                    $update_stmt->execute();
                    $processed++;
                    continue;
                }

                // Get current balance
                $balance_sql = "SELECT COALESCE(SUM(CASE 
                                    WHEN transaction_type = 'deposit' THEN amount 
                                    WHEN transaction_type = 'withdrawal' THEN -amount 
                                    ELSE 0 
                                END), 0) as current_balance 
                                FROM deposits 
                                WHERE member_id = ?";
                $balance_stmt = $conn->prepare($balance_sql);
                $balance_stmt->bind_param("i", $row['member_id']);
                $balance_stmt->execute();
                $balance_result = $balance_stmt->get_result();
                $current_balance = $balance_result->fetch_assoc()['current_balance'];

                // Calculate new balance
                $new_balance = $current_balance + $row['amount'];

                // Insert deposit
                $deposit_sql = "INSERT INTO deposits 
                               (member_id, deposit_date, amount, balance, transaction_type, 
                                reference_no, description, created_by, created_at)
                               VALUES (?, ?, ?, ?, 'deposit', ?, ?, ?, NOW())";
                $deposit_stmt = $conn->prepare($deposit_sql);
                $description = 'Monthly contribution (batch process) - ' . $row['month_name'] . ' ' . $row['year'];
                $reference_no = 'BATCH-' . $row['batch_no'] . '-' . $row['id'];

                $deposit_stmt->bind_param(
                    "isddssi",
                    $row['member_id'],
                    $row['contribution_month'],
                    $row['amount'],
                    $new_balance,
                    $reference_no,
                    $description,
                    $current_user
                );
                $deposit_stmt->execute();
                $deposit_id = $conn->insert_id;

                // Create transaction record
                $trans_no = 'TXN' . time() . rand(100, 999);
                $trans_sql = "INSERT INTO transactions 
                             (transaction_no, transaction_date, description, debit_account, 
                              credit_account, amount, reference_type, reference_id, created_by)
                             VALUES (?, ?, ?, 'CASH', 'MEMBER_DEPOSITS', ?, 'deposit', ?, ?)";
                $trans_stmt = $conn->prepare($trans_sql);
                $trans_desc = "Monthly contribution - {$row['member_name']} - {$row['month_name']} {$row['year']}";
                $trans_stmt->bind_param(
                    "sssiii",
                    $trans_no,
                    $row['contribution_month'],
                    $trans_desc,
                    $row['amount'],
                    $deposit_id,
                    $current_user
                );
                $trans_stmt->execute();

                // Mark as processed
                $update_sql = "UPDATE monthly_contribution_imports 
                               SET status = 'processed', processed_at = NOW() 
                               WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("i", $row['id']);
                $update_stmt->execute();

                $processed++;
                $total_amount += $row['amount'];
            } catch (Exception $e) {
                $errors[] = "Failed to process ID {$row['id']}: " . $e->getMessage();

                // Mark as skipped
                $skip_sql = "UPDATE monthly_contribution_imports 
                             SET status = 'skipped', notes = ? 
                             WHERE id = ?";
                $skip_stmt = $conn->prepare($skip_sql);
                $error_msg = $e->getMessage();
                $skip_stmt->bind_param("si", $error_msg, $row['id']);
                $skip_stmt->execute();
            }
        }

        // Update batch statistics
        $update_batch_sql = "UPDATE contribution_import_batches 
                              SET processed_records = processed_records + ?,
                                  skipped_records = skipped_records + ?,
                                  status = CASE 
                                      WHEN (processed_records + ? + skipped_records) >= total_records 
                                      THEN 'completed' 
                                      ELSE 'processing' 
                                  END
                              WHERE batch_no = ?";
        $update_batch_stmt = $conn->prepare($update_batch_sql);
        $skipped_count = count($errors);
        $update_batch_stmt->bind_param("iiis", $processed, $skipped_count, $processed, $batch_no);
        $update_batch_stmt->execute();

        $conn->commit();

        $_SESSION['success'] = "Batch processed successfully!\n";
        $_SESSION['success'] .= "Processed: $processed contributions\n";
        $_SESSION['success'] .= "Total amount: " . formatCurrency($total_amount) . "\n";
        if (!empty($errors)) {
            $_SESSION['import_errors'] = $errors;
        }
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Batch processing failed: ' . $e->getMessage();
        error_log("Batch processing error: " . $e->getMessage());
    }

    $conn->close();
    header('Location: view-batch.php?batch=' . $batch_no);
    exit();
}

function previewContributionFile()
{
    $response = ['success' => true, 'headers' => [], 'preview' => [], 'errors' => []];

    if (isset($_FILES['contribution_file']) && $_FILES['contribution_file']['error'] == 0) {
        $file = fopen($_FILES['contribution_file']['tmp_name'], 'r');
        $headers = fgetcsv($file);

        // Clean headers
        $headers = array_map(function ($h) {
            $h = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $h);
            return trim($h);
        }, $headers);

        $response['headers'] = $headers;

        // Check expected format
        $expected = ['M/NO', 'Names', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        if (count($headers) < 13) {
            $response['success'] = false;
            $response['errors'][] = "Invalid format. Expected at least 13 columns (M/NO, Names, Jan-Dec)";
        }

        // Preview first 5 rows
        $preview = [];
        $count = 0;
        while (($row = fgetcsv($file)) !== false && $count < 5) {
            $preview[] = $row;
            $count++;
        }
        $response['preview'] = $preview;

        fclose($file);
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

function getMemberByNumber($member_no)
{
    $sql = "SELECT id, member_no, full_name FROM members WHERE member_no = ? OR member_no LIKE ?";
    $like_no = '%' . $member_no . '%';
    $result = executeQuery($sql, "ss", [$member_no, $like_no]);

    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }

    // Try without leading zeros
    $clean_no = ltrim($member_no, '0');
    if ($clean_no != $member_no) {
        $result = executeQuery($sql, "ss", [$clean_no, '%' . $clean_no . '%']);
        if ($result->num_rows > 0) {
            return $result->fetch_assoc();
        }
    }

    return null;
}

// Get recent import batches
$batches_sql = "SELECT * FROM contribution_import_batches 
                ORDER BY created_at DESC LIMIT 10";
$batches = executeQuery($batches_sql);

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Import Monthly Contributions</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Deposits</a></li>
                <li class="breadcrumb-item active">Monthly Import</li>
            </ul>
        </div>
        <div class="col-auto">
            <a href="templates/monthly-contributions-template.xls" class="btn btn-success">
                <i class="fas fa-download"></i> Download Template
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

<?php if (isset($_SESSION['warning'])): ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <?php echo $_SESSION['warning']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['warning']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>
        <?php echo $_SESSION['error']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['import_errors'])): ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <strong>Import completed with warnings/errors:</strong>
        <ul class="mb-0 mt-2" style="max-height: 200px; overflow-y: auto;">
            <?php foreach ($_SESSION['import_errors'] as $error): ?>
                <li><?php echo $error; ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['import_errors']); ?>
<?php endif; ?>

<!-- Instructions Card -->
<div class="card mb-4">
    <div class="card-header bg-info text-white">
        <h5 class="card-title mb-0"><i class="fas fa-info-circle"></i> Import Instructions</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-8">
                <p>Upload an Excel/CSV file with monthly contributions in the following format:</p>
                <table class="table table-bordered table-sm">
                    <thead>
                        <tr>
                            <th>M/NO</th>
                            <th>Names</th>
                            <th>Jan</th>
                            <th>Feb</th>
                            <th>Mar</th>
                            <th>...</th>
                            <th>Dec</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>1</td>
                            <td>CHARLES G. NDERITU</td>
                            <td>0</td>
                            <td></td>
                            <td>8655</td>
                            <td>...</td>
                            <td></td>
                        </tr>
                        <tr>
                            <td>2</td>
                            <td>MARAGARA GACHIE</td>
                            <td>1000</td>
                            <td>1000</td>
                            <td>1000</td>
                            <td>...</td>
                            <td>1000</td>
                        </tr>
                    </tbody>
                </table>
                <p class="text-muted small">Empty cells will be ignored. Only positive amounts will be imported.</p>
            </div>
            <div class="col-md-4">
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Note:</strong>
                    <ul class="mb-0 mt-2">
                        <li>Member numbers are matched with database</li>
                        <li>Duplicate monthly contributions are skipped</li>
                        <li>All imports are recorded in batches</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Upload Form -->
<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="card-title mb-0"><i class="fas fa-upload"></i> Upload Monthly Contributions</h5>
    </div>
    <div class="card-body">
        <form id="uploadForm" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_file">

            <div class="row">
                <div class="col-md-3 mb-3">
                    <label for="year" class="form-label">Year</label>
                    <select class="form-control" id="year" name="year" required>
                        <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                            <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="col-md-3 mb-3">
                    <label for="import_date" class="form-label">Import Date</label>
                    <input type="date" class="form-control" id="import_date" name="import_date"
                        value="<?php echo date('Y-m-d'); ?>" required>
                </div>

                <div class="col-md-6 mb-3">
                    <label for="contribution_file" class="form-label">Excel/CSV File</label>
                    <input type="file" class="form-control" id="contribution_file" name="contribution_file"
                        accept=".csv,.xlsx,.xls" required onchange="previewFile()">
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="default_description" class="form-label">Description Prefix</label>
                    <input type="text" class="form-control" id="default_description" name="default_description"
                        value="Monthly contribution - ">
                </div>
            </div>

            <!-- Preview Section -->
            <div id="previewSection" style="display: none;" class="mb-4">
                <h6>File Preview:</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered" id="previewTable">
                        <thead id="previewHeader">
                            <tr></tr>
                        </thead>
                        <tbody id="previewBody">
                        </tbody>
                    </table>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-upload"></i> Upload File
            </button>
            <button type="button" class="btn btn-secondary" onclick="resetForm()">
                <i class="fas fa-undo"></i> Reset
            </button>
        </form>
    </div>
</div>

<!-- Pending Batches -->
<?php if (isset($_SESSION['import_batch'])):
    $batch_no = $_SESSION['import_batch'];
    $pending_sql = "SELECT * FROM monthly_contribution_imports 
                    WHERE batch_no = ? AND status = 'pending'";
    $pending = executeQuery($pending_sql, "s", [$batch_no]);
?>
    <div class="card mb-4 border-warning">
        <div class="card-header bg-warning">
            <h5 class="card-title mb-0">Pending Import Batch: <?php echo $batch_no; ?></h5>
        </div>
        <div class="card-body">
            <p>Found <?php echo $pending->num_rows; ?> pending contributions ready for processing.</p>

            <form method="POST" action="" onsubmit="return confirm('Process these contributions? This will create deposit records.')">
                <input type="hidden" name="action" value="process_import">
                <input type="hidden" name="batch_no" value="<?php echo $batch_no; ?>">

                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="deposit_type" class="form-label">Deposit Type</label>
                        <select class="form-control" id="deposit_type" name="deposit_type">
                            <option value="Monthly contribution">Monthly contribution</option>
                            <option value="Share contribution">Share contribution</option>
                            <option value="Regular deposit">Regular deposit</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <div class="form-check mt-4">
                            <input class="form-check-input" type="checkbox" id="create_recurring" name="create_recurring" value="1">
                            <label class="form-check-label" for="create_recurring">
                                Create recurring schedule for next year
                            </label>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-success">
                    <i class="fas fa-play"></i> Process Import
                </button>
                <a href="clear-batch.php?batch=<?php echo $batch_no; ?>" class="btn btn-danger" onclick="return confirm('Clear this batch?')">
                    <i class="fas fa-trash"></i> Clear Batch
                </a>
            </form>

            <!-- Preview Pending -->
            <div class="table-responsive mt-3">
                <table class="table table-sm table-bordered">
                    <thead>
                        <tr>
                            <th>Member No</th>
                            <th>Member Name</th>
                            <th>Month</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($p = $pending->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $p['member_no']; ?></td>
                                <td><?php echo $p['member_name']; ?></td>
                                <td><?php echo $p['month_name'] . ' ' . $p['year']; ?></td>
                                <td class="text-end"><?php echo formatCurrency($p['amount']); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php unset($_SESSION['import_batch']); ?>
<?php endif; ?>

<!-- Recent Import Batches -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title">Recent Import Batches</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>Batch No</th>
                        <th>Date</th>
                        <th>Year</th>
                        <th>Filename</th>
                        <th>Records</th>
                        <th>Amount</th>
                        <th>Processed</th>
                        <th>Skipped</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($batch = $batches->fetch_assoc()):
                        // Get pending count for this batch
                        $pending_count_sql = "SELECT COUNT(*) as pending_count 
                                              FROM monthly_contribution_imports 
                                              WHERE batch_no = ? AND status = 'pending'";
                        $pending_count_result = executeQuery($pending_count_sql, "s", [$batch['batch_no']]);
                        $pending_count = $pending_count_result->fetch_assoc()['pending_count'];
                    ?>
                        <tr>
                            <td><strong><?php echo $batch['batch_no']; ?></strong></td>
                            <td><?php echo formatDate($batch['import_date']); ?></td>
                            <td><?php echo $batch['year']; ?></td>
                            <td><?php echo $batch['filename']; ?></td>
                            <td><?php echo $batch['total_records']; ?></td>
                            <td><?php echo formatCurrency($batch['total_amount']); ?></td>
                            <td><?php echo $batch['processed_records']; ?></td>
                            <td><?php echo $batch['skipped_records']; ?></td>
                            <td>
                                <span class="badge bg-<?php
                                                        echo $batch['status'] == 'completed' ? 'success' : ($batch['status'] == 'processing' ? 'warning' : ($batch['status'] == 'failed' ? 'danger' : 'secondary'));
                                                        ?>">
                                    <?php echo ucfirst($batch['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <a href="view-batch.php?batch=<?php echo $batch['batch_no']; ?>" class="btn btn-sm btn-info" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if ($pending_count > 0): ?>
                                        <a href="monthly-import.php?process_batch=<?php echo $batch['batch_no']; ?>"
                                            class="btn btn-sm btn-success"
                                            title="Process Pending (<?php echo $pending_count; ?>)"
                                            onclick="return confirm('Process <?php echo $pending_count; ?> pending contributions in this batch?')">
                                            <i class="fas fa-play"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    function previewFile() {
        var file = document.getElementById('contribution_file').files[0];
        if (!file) return;

        var formData = new FormData();
        formData.append('contribution_file', file);
        formData.append('action', 'preview_file');

        fetch('monthly-import.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('previewSection').style.display = 'block';

                    // Build preview table
                    var headerRow = document.querySelector('#previewHeader tr');
                    headerRow.innerHTML = '';
                    data.headers.forEach(header => {
                        headerRow.innerHTML += '<th>' + header + '</th>';
                    });

                    var body = document.getElementById('previewBody');
                    body.innerHTML = '';
                    data.preview.forEach(row => {
                        var tr = document.createElement('tr');
                        row.forEach(cell => {
                            var td = document.createElement('td');
                            td.textContent = cell || '-';
                            tr.appendChild(td);
                        });
                        body.appendChild(tr);
                    });
                } else {
                    Swal.fire('Preview Error', data.errors.join('\n'), 'error');
                }
            })
            .catch(error => {
                console.error('Preview error:', error);
            });
    }

    function resetForm() {
        document.getElementById('uploadForm').reset();
        document.getElementById('previewSection').style.display = 'none';
    }

    // Format currency helper
    function formatCurrency(amount) {
        return 'KES ' + parseFloat(amount).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
</script>

<style>
    #previewSection {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 5px;
        max-height: 300px;
        overflow-y: auto;
    }

    #previewTable {
        font-size: 12px;
    }

    #previewTable th {
        background: #e9ecef;
        position: sticky;
        top: 0;
        z-index: 10;
    }

    .btn-group .btn {
        margin-right: 2px;
    }

    .btn-group .btn:last-child {
        margin-right: 0;
    }
</style>

<?php include '../../includes/footer.php'; ?>
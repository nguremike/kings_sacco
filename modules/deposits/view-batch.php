<?php
// modules/deposits/view-batch.php
require_once '../../config/config.php';
requireLogin();

$batch_no = $_GET['batch'] ?? '';

if (empty($batch_no)) {
    $_SESSION['error'] = 'Batch number not provided';
    header('Location: monthly-import.php');
    exit();
}

// Get batch information
$batch_sql = "SELECT * FROM contribution_import_batches WHERE batch_no = ?";
$batch_result = executeQuery($batch_sql, "s", [$batch_no]);

if ($batch_result->num_rows == 0) {
    $_SESSION['error'] = 'Batch not found';
    header('Location: monthly-import.php');
    exit();
}

$batch = $batch_result->fetch_assoc();

// Get all imports in this batch
$imports_sql = "SELECT * FROM monthly_contribution_imports 
                WHERE batch_no = ? 
                ORDER BY 
                    CASE status 
                        WHEN 'pending' THEN 1 
                        WHEN 'processed' THEN 2 
                        WHEN 'skipped' THEN 3 
                    END,
                    member_no, 
                    contribution_month ASC";
$imports = executeQuery($imports_sql, "s", [$batch_no]);

// Get statistics
$stats_sql = "SELECT 
              COUNT(*) as total,
              SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
              SUM(CASE WHEN status = 'processed' THEN 1 ELSE 0 END) as processed,
              SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) as skipped,
              SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) as pending_amount,
              SUM(CASE WHEN status = 'processed' THEN amount ELSE 0 END) as processed_amount
              FROM monthly_contribution_imports 
              WHERE batch_no = ?";
$stats_result = executeQuery($stats_sql, "s", [$batch_no]);
$stats = $stats_result->fetch_assoc();

// Get member summary
$member_summary_sql = "SELECT 
                       member_no, 
                       member_name,
                       COUNT(*) as contribution_count,
                       SUM(amount) as total_amount,
                       SUM(CASE WHEN status = 'processed' THEN 1 ELSE 0 END) as processed_count,
                       SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count
                       FROM monthly_contribution_imports 
                       WHERE batch_no = ?
                       GROUP BY member_no, member_name
                       ORDER BY member_no";
$member_summary = executeQuery($member_summary_sql, "s", [$batch_no]);

// Handle batch reprocessing if requested
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'reprocess_failed') {
        reprocessFailedImports($batch_no);
    } elseif ($_POST['action'] == 'delete_batch') {
        deleteBatch($batch_no);
    }
}

function reprocessFailedImports($batch_no)
{
    $conn = getConnection();
    $conn->begin_transaction();

    try {
        $current_user = getCurrentUserId();
        $reprocessed = 0;
        $errors = [];

        // Get all skipped imports
        $skipped_sql = "SELECT * FROM monthly_contribution_imports 
                        WHERE batch_no = ? AND status = 'skipped'";
        $skipped_stmt = $conn->prepare($skipped_sql);
        $skipped_stmt->bind_param("s", $batch_no);
        $skipped_stmt->execute();
        $skipped_result = $skipped_stmt->get_result();

        while ($row = $skipped_result->fetch_assoc()) {
            try {
                // Check if member exists
                $member = getMemberById($row['member_id']);
                if (!$member) {
                    throw new Exception("Member ID {$row['member_id']} not found");
                }

                // Check for duplicate
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
                    // Already exists, mark as processed
                    $update_sql = "UPDATE monthly_contribution_imports 
                                   SET status = 'processed', processed_at = NOW(), notes = 'Already existed in system'
                                   WHERE id = ?";
                    $update_stmt = $conn->prepare($update_sql);
                    $update_stmt->bind_param("i", $row['id']);
                    $update_stmt->execute();
                    $reprocessed++;
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
                $description = 'Monthly contribution (reprocessed) - ' . $row['month_name'] . ' ' . $row['year'];
                $reference_no = 'REP-' . $row['batch_no'] . '-' . $row['id'];

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

                // Update import status
                $update_sql = "UPDATE monthly_contribution_imports 
                               SET status = 'processed', processed_at = NOW(), notes = 'Reprocessed successfully'
                               WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("i", $row['id']);
                $update_stmt->execute();

                $reprocessed++;
            } catch (Exception $e) {
                $errors[] = "Failed to reprocess ID {$row['id']}: " . $e->getMessage();

                // Update with error note
                $note_sql = "UPDATE monthly_contribution_imports 
                             SET notes = CONCAT(IFNULL(notes, ''), ' | Reprocess failed: ', ?)
                             WHERE id = ?";
                $note_stmt = $conn->prepare($note_sql);
                $error_msg = $e->getMessage();
                $note_stmt->bind_param("si", $error_msg, $row['id']);
                $note_stmt->execute();
            }
        }

        // Update batch stats
        $update_batch_sql = "UPDATE contribution_import_batches 
                             SET processed_records = processed_records + ?,
                                 skipped_records = skipped_records - ?
                             WHERE batch_no = ?";
        $update_batch_stmt = $conn->prepare($update_batch_sql);
        $update_batch_stmt->bind_param("iis", $reprocessed, $reprocessed, $batch_no);
        $update_batch_stmt->execute();

        $conn->commit();

        $_SESSION['success'] = "Reprocessed $reprocessed failed imports successfully.";
        if (!empty($errors)) {
            $_SESSION['import_errors'] = $errors;
        }
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Reprocessing failed: ' . $e->getMessage();
    }

    $conn->close();
    header('Location: view-batch.php?batch=' . $batch_no);
    exit();
}

function deleteBatch($batch_no)
{
    $conn = getConnection();
    $conn->begin_transaction();

    try {
        // Check if any imports are already processed
        $check_sql = "SELECT COUNT(*) as processed_count 
                      FROM monthly_contribution_imports 
                      WHERE batch_no = ? AND status = 'processed'";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $batch_no);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $processed = $check_result->fetch_assoc()['processed_count'];

        if ($processed > 0) {
            throw new Exception("Cannot delete batch with $processed already processed records. Please reprocess or handle individually.");
        }

        // Delete imports
        $delete_imports_sql = "DELETE FROM monthly_contribution_imports WHERE batch_no = ?";
        $delete_imports_stmt = $conn->prepare($delete_imports_sql);
        $delete_imports_stmt->bind_param("s", $batch_no);
        $delete_imports_stmt->execute();

        // Delete batch
        $delete_batch_sql = "DELETE FROM contribution_import_batches WHERE batch_no = ?";
        $delete_batch_stmt = $conn->prepare($delete_batch_sql);
        $delete_batch_stmt->bind_param("s", $batch_no);
        $delete_batch_stmt->execute();

        $conn->commit();

        $_SESSION['success'] = "Batch deleted successfully.";
        header('Location: monthly-import.php');
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Delete failed: ' . $e->getMessage();
        header('Location: view-batch.php?batch=' . $batch_no);
        exit();
    }

    $conn->close();
}

function getMemberById($member_id)
{
    $sql = "SELECT id, member_no, full_name FROM members WHERE id = ?";
    $result = executeQuery($sql, "i", [$member_id]);
    return $result->fetch_assoc();
}

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Batch Details: <?php echo htmlspecialchars($batch_no); ?></h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Deposits</a></li>
                <li class="breadcrumb-item"><a href="monthly-import.php">Monthly Import</a></li>
                <li class="breadcrumb-item active">Batch Details</li>
            </ul>
        </div>
        <div class="col-auto">
            <a href="monthly-import.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Imports
            </a>
        </div>
    </div>
</div>

<!-- Alert Messages -->
<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?php echo $_SESSION['success']; ?>
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

<?php if (isset($_SESSION['import_errors'])): ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <strong>Processing Errors:</strong>
        <ul class="mb-0 mt-2" style="max-height: 200px; overflow-y: auto;">
            <?php foreach ($_SESSION['import_errors'] as $error): ?>
                <li><?php echo $error; ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['import_errors']); ?>
<?php endif; ?>

<!-- Batch Information Card -->
<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="card-title mb-0"><i class="fas fa-info-circle"></i> Batch Information</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3">
                <p><strong>Batch Number:</strong><br> <?php echo $batch['batch_no']; ?></p>
            </div>
            <div class="col-md-2">
                <p><strong>Import Date:</strong><br> <?php echo formatDate($batch['import_date']); ?></p>
            </div>
            <div class="col-md-2">
                <p><strong>Year:</strong><br> <?php echo $batch['year']; ?></p>
            </div>
            <div class="col-md-3">
                <p><strong>Filename:</strong><br> <?php echo $batch['filename']; ?></p>
            </div>
            <div class="col-md-2">
                <p><strong>Status:</strong><br>
                    <span class="badge bg-<?php
                                            echo $batch['status'] == 'completed' ? 'success' : ($batch['status'] == 'processing' ? 'warning' : ($batch['status'] == 'failed' ? 'danger' : 'secondary'));
                                            ?> fs-6">
                        <?php echo ucfirst($batch['status']); ?>
                    </span>
                </p>
            </div>
        </div>
        <div class="row mt-2">
            <div class="col-md-12">
                <p><strong>Import Summary:</strong><br>
                    Total Records: <?php echo $batch['total_records']; ?> |
                    Total Amount: <?php echo formatCurrency($batch['total_amount']); ?> |
                    Processed: <?php echo $batch['processed_records']; ?> |
                    Skipped: <?php echo $batch['skipped_records']; ?>
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="stats-card primary">
            <div class="stats-icon">
                <i class="fas fa-file-import"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo $stats['total'] ?? 0; ?></h3>
                <p>Total Records</p>
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
                <p>Pending</p>
                <small><?php echo formatCurrency($stats['pending_amount'] ?? 0); ?></small>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card success">
            <div class="stats-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo $stats['processed'] ?? 0; ?></h3>
                <p>Processed</p>
                <small><?php echo formatCurrency($stats['processed_amount'] ?? 0); ?></small>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card danger">
            <div class="stats-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo $stats['skipped'] ?? 0; ?></h3>
                <p>Skipped/Failed</p>
            </div>
        </div>
    </div>
</div>

<!-- Action Buttons -->
<?php if ($stats['pending'] > 0 || $stats['skipped'] > 0): ?>
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Reprocess all failed/skipped imports?')">
                        <input type="hidden" name="action" value="reprocess_failed">
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-sync-alt"></i> Reprocess Failed Imports (<?php echo $stats['skipped']; ?>)
                        </button>
                    </form>

                    <?php if ($stats['processed'] == 0): ?>
                        <form method="POST" style="display: inline; margin-left: 10px;" onsubmit="return confirm('Delete this entire batch? This cannot be undone.')">
                            <input type="hidden" name="action" value="delete_batch">
                            <button type="submit" class="btn btn-danger">
                                <i class="fas fa-trash"></i> Delete Batch
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if ($stats['pending'] > 0): ?>
                        <a href="monthly-import.php?process_batch=<?php echo $batch_no; ?>" class="btn btn-success">
                            <i class="fas fa-play"></i> Process Pending (<?php echo $stats['pending']; ?>)
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Member Summary -->
<div class="card mb-4">
    <div class="card-header bg-info text-white">
        <h5 class="card-title mb-0"><i class="fas fa-users"></i> Member Summary</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Member No</th>
                        <th>Member Name</th>
                        <th>Contributions</th>
                        <th>Total Amount</th>
                        <th>Processed</th>
                        <th>Pending</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $member_total_amount = 0;
                    while ($member = $member_summary->fetch_assoc()):
                        $member_total_amount += $member['total_amount'];
                    ?>
                        <tr>
                            <td><?php echo $member['member_no']; ?></td>
                            <td><?php echo $member['member_name']; ?></td>
                            <td><?php echo $member['contribution_count']; ?></td>
                            <td class="text-end"><?php echo formatCurrency($member['total_amount']); ?></td>
                            <td class="text-center">
                                <?php if ($member['processed_count'] > 0): ?>
                                    <span class="badge bg-success"><?php echo $member['processed_count']; ?></span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($member['pending_count'] > 0): ?>
                                    <span class="badge bg-warning"><?php echo $member['pending_count']; ?></span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
                <tfoot>
                    <tr class="table-info">
                        <th colspan="3" class="text-end">Total:</th>
                        <th class="text-end"><?php echo formatCurrency($member_total_amount); ?></th>
                        <th colspan="2"></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- Detailed Imports Table -->
<div class="card">
    <div class="card-header bg-secondary text-white">
        <h5 class="card-title mb-0"><i class="fas fa-list"></i> Detailed Import Records</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-striped datatable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Member No</th>
                        <th>Member Name</th>
                        <th>Month</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Processed At</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $imports->fetch_assoc()): ?>
                        <tr class="<?php
                                    echo $row['status'] == 'processed' ? 'table-success' : ($row['status'] == 'skipped' ? 'table-danger' : ($row['status'] == 'pending' ? 'table-warning' : ''));
                                    ?>">
                            <td><?php echo $row['id']; ?></td>
                            <td><?php echo $row['member_no']; ?></td>
                            <td><?php echo $row['member_name']; ?></td>
                            <td><?php echo $row['month_name'] . ' ' . $row['year']; ?></td>
                            <td class="text-end"><?php echo formatCurrency($row['amount']); ?></td>
                            <td>
                                <?php if ($row['status'] == 'processed'): ?>
                                    <span class="badge bg-success">Processed</span>
                                <?php elseif ($row['status'] == 'pending'): ?>
                                    <span class="badge bg-warning">Pending</span>
                                <?php elseif ($row['status'] == 'skipped'): ?>
                                    <span class="badge bg-danger">Skipped</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $row['processed_at'] ? formatDate($row['processed_at']) : '-'; ?></td>
                            <td>
                                <?php if ($row['notes']): ?>
                                    <span class="text-muted small"><?php echo htmlspecialchars($row['notes']); ?></span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer">
        <div class="row">
            <div class="col-md-6">
                <small class="text-muted">
                    <i class="fas fa-info-circle"></i>
                    Total Records: <?php echo $stats['total']; ?> |
                    Pending: <?php echo $stats['pending']; ?> |
                    Processed: <?php echo $stats['processed']; ?> |
                    Skipped: <?php echo $stats['skipped']; ?>
                </small>
            </div>
            <div class="col-md-6 text-end">
                <small class="text-muted">
                    Imported on: <?php echo formatDate($batch['created_at']); ?>
                </small>
            </div>
        </div>
    </div>
</div>

<style>
    .stats-card .stats-content small {
        font-size: 11px;
        opacity: 0.9;
    }

    .table td {
        vertical-align: middle;
    }

    .table-success {
        background-color: rgba(40, 167, 69, 0.05) !important;
    }

    .table-danger {
        background-color: rgba(220, 53, 69, 0.05) !important;
    }

    .table-warning {
        background-color: rgba(255, 193, 7, 0.05) !important;
    }

    .card-header {
        font-weight: 600;
    }

    @media (max-width: 768px) {
        .stats-card {
            margin-bottom: 10px;
        }

        .btn {
            margin-bottom: 5px;
            width: 100%;
        }
    }
</style>

<?php include '../../includes/footer.php'; ?>
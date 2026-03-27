<?php
// modules/accounting/view-processing-log.php
//show php errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);



require_once '../../config/config.php';
requireRole('admin');

$log_id = $_GET['id'] ?? 0;

if (!$log_id) {
    $_SESSION['error'] = 'Processing log ID not provided';
    header('Location: year-end-processing.php');
    exit();
}

// Get processing log details
$log_sql = "SELECT l.*, u.full_name as processed_by_name,
            a.full_name as approved_by_name
            FROM year_end_processing_log l
            LEFT JOIN users u ON l.processed_by = u.id
            LEFT JOIN users a ON l.approved_by = a.id
            WHERE l.id = ?";
$log_result = executeQuery($log_sql, "i", [$log_id]);

if ($log_result->num_rows == 0) {
    $_SESSION['error'] = 'Processing log not found';
    header('Location: year-end-processing.php');
    exit();
}

$log = $log_result->fetch_assoc();

// Get affected members (if any) - this would require a separate table to track
// For now, we'll show summary from the log

$page_title = 'Processing Log Details - ' . $log['process_year'];

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Processing Log Details</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="year-end-processing.php">Year-End Processing</a></li>
                <li class="breadcrumb-item active">Log Details</li>
            </ul>
        </div>
        <div class="col-auto">
            <a href="year-end-processing.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Processing
            </a>
        </div>
    </div>
</div>

<!-- Log Information Card -->
<div class="row">
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="card-title mb-0"><i class="fas fa-info-circle me-2"></i>Processing Information</h5>
            </div>
            <div class="card-body">
                <table class="table table-bordered">
                    <tr>
                        <th style="width: 200px;">Log ID:</th>
                        <td><strong>#<?php echo $log['id']; ?></strong></td>
                    </tr>
                    <tr>
                        <th>Processing Year:</th>
                        <td><?php echo $log['process_year']; ?></td>
                    </tr>
                    <tr>
                        <th>Process Type:</th>
                        <td>
                            <?php
                            $type_labels = [
                                'admin_charges' => 'Admin Charges Only',
                                'share_capital_charge' => 'Share Capital Charges Only',
                                'both' => 'Both Admin and Share Charges'
                            ];
                            echo $type_labels[$log['process_type']] ?? ucfirst(str_replace('_', ' ', $log['process_type']));
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Status:</th>
                        <td>
                            <?php
                            $status_class = [
                                'pending' => 'secondary',
                                'processing' => 'warning',
                                'completed' => 'success',
                                'failed' => 'danger'
                            ][$log['status']] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?php echo $status_class; ?> fs-6">
                                <?php echo ucfirst($log['status']); ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th>Processed At:</th>
                        <td><?php echo formatDate($log['processed_at']) . ' ' . date('H:i:s', strtotime($log['processed_at'])); ?></td>
                    </tr>
                    <tr>
                        <th>Completed At:</th>
                        <td>
                            <?php echo $log['completed_at'] ? formatDate($log['completed_at']) . ' ' . date('H:i:s', strtotime($log['completed_at'])) : '-'; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Processed By:</th>
                        <td><?php echo $log['processed_by_name'] ?? 'System'; ?></td>
                    </tr>
                    <tr>
                        <th>Approved By:</th>
                        <td><?php echo $log['approved_by_name'] ?? 'Not approved'; ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="card-title mb-0"><i class="fas fa-chart-pie me-2"></i>Summary Statistics</h5>
            </div>
            <div class="card-body">
                <div class="text-center mb-3">
                    <div class="display-4 fw-bold text-primary"><?php echo number_format($log['members_processed']); ?></div>
                    <p class="text-muted">Members Processed</p>
                </div>

                <table class="table table-sm">
                    <tr>
                        <td>Total Admin Charges:</td>
                        <td class="text-end fw-bold"><?php echo formatCurrency($log['total_admin_charges']); ?></td>
                    </tr>
                    <tr>
                        <td>Total Share Charges:</td>
                        <td class="text-end fw-bold"><?php echo formatCurrency($log['total_share_charges']); ?></td>
                    </tr>
                    <tr class="table-primary">
                        <td><strong>Total Collected:</strong></td>
                        <td class="text-end fw-bold">
                            <?php echo formatCurrency($log['total_admin_charges'] + $log['total_share_charges']); ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Error Log Section -->
<?php if (!empty($log['error_log'])): ?>
    <div class="card mb-4 border-danger">
        <div class="card-header bg-danger text-white">
            <h5 class="card-title mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Error Log</h5>
        </div>
        <div class="card-body">
            <div class="bg-light p-3 rounded" style="max-height: 300px; overflow-y: auto;">
                <pre class="mb-0" style="font-size: 11px; font-family: monospace;"><?php echo htmlspecialchars($log['error_log']); ?></pre>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Timeline Section -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title mb-0"><i class="fas fa-clock me-2"></i>Processing Timeline</h5>
    </div>
    <div class="card-body">
        <div class="timeline">
            <!-- Started -->
            <div class="timeline-item">
                <div class="timeline-badge bg-primary">
                    <i class="fas fa-play"></i>
                </div>
                <div class="timeline-content">
                    <h6>Processing Started</h6>
                    <p class="text-muted"><?php echo formatDate($log['processed_at']) . ' at ' . date('H:i:s', strtotime($log['processed_at'])); ?></p>
                </div>
            </div>

            <!-- Processing steps (if we had more detailed logs) -->
            <div class="timeline-item">
                <div class="timeline-badge bg-info">
                    <i class="fas fa-cogs"></i>
                </div>
                <div class="timeline-content">
                    <h6>Processing Members</h6>
                    <p class="text-muted">Processed <?php echo number_format($log['members_processed']); ?> members</p>
                </div>
            </div>

            <!-- Completed/Failed -->
            <div class="timeline-item">
                <div class="timeline-badge bg-<?php echo $log['status'] == 'completed' ? 'success' : ($log['status'] == 'failed' ? 'danger' : 'warning'); ?>">
                    <i class="fas fa-<?php echo $log['status'] == 'completed' ? 'check' : ($log['status'] == 'failed' ? 'times' : 'clock'); ?>"></i>
                </div>
                <div class="timeline-content">
                    <h6>Processing <?php echo ucfirst($log['status']); ?></h6>
                    <?php if ($log['completed_at']): ?>
                        <p class="text-muted"><?php echo formatDate($log['completed_at']) . ' at ' . date('H:i:s', strtotime($log['completed_at'])); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Actions -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0"><i class="fas fa-tools me-2"></i>Actions</h5>
    </div>
    <div class="card-body">
        <?php if ($log['status'] == 'failed'): ?>
            <button type="button" class="btn btn-warning" onclick="retryProcessing(<?php echo $log['id']; ?>)">
                <i class="fas fa-redo-alt me-2"></i>Retry Processing
            </button>
        <?php endif; ?>

        <?php if ($log['status'] == 'completed'): ?>
            <button type="button" class="btn btn-success" onclick="downloadReport(<?php echo $log['id']; ?>)">
                <i class="fas fa-download me-2"></i>Download Report
            </button>
            <button type="button" class="btn btn-info" onclick="viewAffectedMembers(<?php echo $log['id']; ?>)">
                <i class="fas fa-users me-2"></i>View Affected Members
            </button>
        <?php endif; ?>

        <button type="button" class="btn btn-danger" onclick="confirmDelete(<?php echo $log['id']; ?>)">
            <i class="fas fa-trash me-2"></i>Delete Log
        </button>
    </div>
</div>

<style>
    .timeline {
        position: relative;
        padding: 20px 0;
    }

    .timeline-item {
        position: relative;
        padding-left: 50px;
        margin-bottom: 30px;
    }

    .timeline-badge {
        position: absolute;
        left: 0;
        top: 0;
        width: 35px;
        height: 35px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
    }

    .timeline-content {
        padding-bottom: 20px;
        border-bottom: 1px solid #dee2e6;
    }

    .timeline-content h6 {
        margin-bottom: 5px;
        font-weight: 600;
    }

    .timeline-content p {
        margin-bottom: 0;
        font-size: 12px;
    }
</style>

<script>
    function retryProcessing(id) {
        Swal.fire({
            title: 'Retry Processing',
            text: 'Are you sure you want to retry the year-end processing? This will attempt to process again.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#ffc107',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, retry'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'year-end-processing.php?retry=' + id;
            }
        });
    }

    function downloadReport(id) {
        window.location.href = 'download-processing-report.php?id=' + id;
    }

    function viewAffectedMembers(id) {
        window.location.href = 'affected-members.php?log_id=' + id;
    }

    function confirmDelete(id) {
        Swal.fire({
            title: 'Delete Log',
            text: 'Are you sure you want to delete this processing log? This action cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'delete-processing-log.php?id=' + id;
            }
        });
    }
</script>

<?php include '../../includes/footer.php'; ?>
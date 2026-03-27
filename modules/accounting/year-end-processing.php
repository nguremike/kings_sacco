<?php
// modules/accounting/year-end-processing.php
//show php errors
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);



require_once '../../config/config.php';
require_once '../../config/year_end_processing.php';
requireRole('admin');

$page_title = 'Year-End Processing';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action == 'preview') {
        $year = $_POST['year'] ?? date('Y') - 1;
        $admin_amount = floatval($_POST['admin_amount'] ?? 1000);
        $share_amount = floatval($_POST['share_amount'] ?? 1000);

        $preview_data = previewYearEndCharges($year, $admin_amount, $share_amount);
        $_SESSION['preview_data'] = $preview_data;
        $_SESSION['preview_year'] = $year;
        $_SESSION['preview_admin_amount'] = $admin_amount;
        $_SESSION['preview_share_amount'] = $share_amount;
    } elseif ($action == 'process') {
        $year = $_POST['year'];
        $process_admin = isset($_POST['process_admin']) ? true : false;
        $process_share = isset($_POST['process_share']) ? true : false;
        $admin_amount = floatval($_POST['admin_amount'] ?? 1000);
        $share_amount = floatval($_POST['share_amount'] ?? 1000);

        $result = runYearEndProcessing($year, $process_admin, $process_share, $admin_amount, $share_amount);

        if ($result['success']) {
            $_SESSION['success'] = $result['message'];
            if (isset($result['results'])) {
                $_SESSION['processing_results'] = $result['results'];
            }
        } else {
            $_SESSION['error'] = $result['message'];
        }

        header('Location: year-end-processing.php');
        exit();
    }
}

// Get processing history
$history = getYearEndProcessingHistory(20);

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Year-End Processing</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Accounting</a></li>
                <li class="breadcrumb-item active">Year-End Processing</li>
            </ul>
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

<!-- Processing Results -->
<?php if (isset($_SESSION['processing_results'])):
    $results = $_SESSION['processing_results'];
    unset($_SESSION['processing_results']);
?>
    <div class="alert alert-info alert-dismissible fade show" role="alert">
        <h5><i class="fas fa-chart-bar me-2"></i>Processing Results</h5>
        <div class="row">
            <div class="col-md-6">
                <strong>Admin Charges:</strong><br>
                Members charged: <?php echo $results['admin_charges']['count']; ?><br>
                Total amount: <?php echo formatCurrency($results['admin_charges']['total']); ?>
            </div>
            <div class="col-md-6">
                <strong>Share Capital Charges:</strong><br>
                Members charged: <?php echo $results['share_charges']['count']; ?><br>
                Total amount: <?php echo formatCurrency($results['share_charges']['total']); ?>
            </div>
        </div>
        <?php if (!empty($results['errors'])): ?>
            <div class="mt-3">
                <strong>Errors/Warnings:</strong>
                <ul class="mb-0">
                    <?php foreach (array_slice($results['errors'], 0, 5) as $error): ?>
                        <li><?php echo $error; ?></li>
                    <?php endforeach; ?>
                    <?php if (count($results['errors']) > 5): ?>
                        <li>... and <?php echo count($results['errors']) - 5; ?> more errors</li>
                    <?php endif; ?>
                </ul>
            </div>
        <?php endif; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Processing Form -->
<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="card-title mb-0"><i class="fas fa-calendar-alt me-2"></i>Year-End Processing Configuration</h5>
    </div>
    <div class="card-body">
        <form method="POST" action="" class="needs-validation" novalidate>
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label for="year" class="form-label">Processing Year</label>
                    <select class="form-control" id="year" name="year" required>
                        <?php for ($y = date('Y') - 1; $y >= date('Y') - 3; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo ($y == date('Y') - 1) ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="col-md-3 mb-3">
                    <label for="admin_amount" class="form-label">Admin Charge Amount (KES)</label>
                    <input type="number" class="form-control" id="admin_amount" name="admin_amount"
                        value="1000" min="0">
                </div>

                <div class="col-md-3 mb-3">
                    <label for="share_amount" class="form-label">Share Charge Amount (KES)</label>
                    <input type="number" class="form-control" id="share_amount" name="share_amount"
                        value="1000" min="0" step="100">
                </div>

                <div class="col-md-3 mb-3">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" name="action" value="preview" class="btn btn-info w-100">
                        <i class="fas fa-eye"></i> Preview
                    </button>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="process_admin" name="process_admin" value="1" checked>
                        <label class="form-check-label" for="process_admin">
                            Process Admin Charges (KES <span id="display_admin_amount">1000</span> per member)
                        </label>
                    </div>

                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="process_share" name="process_share" value="1" checked>
                        <label class="form-check-label" for="process_share">
                            Process Share Capital Charges (KES <span id="display_share_amount">1000</span> for members without a full share)
                        </label>
                    </div>
                </div>

                <div class="col-md-6 text-end">
                    <button type="submit" name="action" value="process" class="btn btn-success btn-lg"
                        onclick="return confirm('This will deduct charges from member accounts. This action cannot be undone. Proceed?')">
                        <i class="fas fa-play"></i> Run Year-End Processing
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Preview Results -->
<?php if (isset($_SESSION['preview_data'])):
    $preview = $_SESSION['preview_data'];
    $preview_year = $_SESSION['preview_year'];
    $admin_amount = $_SESSION['preview_admin_amount'];
    $share_amount = $_SESSION['preview_share_amount'];
    unset($_SESSION['preview_data']);
    unset($_SESSION['preview_year']);
    unset($_SESSION['preview_admin_amount']);
    unset($_SESSION['preview_share_amount']);
?>
    <div class="card mb-4">
        <div class="card-header bg-info text-white">
            <h5 class="card-title mb-0"><i class="fas fa-chart-pie me-2"></i>Preview for Year <?php echo $preview_year; ?></h5>
        </div>
        <div class="card-body">
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="stats-card primary">
                        <div class="stats-icon">
                            <i class="fas fa-file-invoice"></i>
                        </div>
                        <div class="stats-content">
                            <h3><?php echo $preview['admin_charges']['count']; ?></h3>
                            <p>Admin Charges</p>
                            <small>Total: <?php echo formatCurrency($preview['admin_charges']['total']); ?></small>
                            <br><small class="text-warning">Insufficient: <?php echo $preview['admin_charges']['insufficient']; ?></small>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="stats-card warning">
                        <div class="stats-icon">
                            <i class="fas fa-coins"></i>
                        </div>
                        <div class="stats-content">
                            <h3><?php echo $preview['share_charges']['count']; ?></h3>
                            <p>Share Charges</p>
                            <small>Total: <?php echo formatCurrency($preview['share_charges']['total']); ?></small>
                            <br><small class="text-warning">Insufficient: <?php echo $preview['share_charges']['insufficient']; ?></small>
                            <br><small class="text-info">Exceptions: <?php echo $preview['share_charges']['exceptions']; ?></small>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="stats-card success">
                        <div class="stats-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stats-content">
                            <h3><?php echo count($preview['members']); ?></h3>
                            <p>Total Members</p>
                            <small>Active members considered</small>
                        </div>
                    </div>
                </div>
            </div>

            <h6>Member Breakdown</h6>
            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead>
                        <tr>
                            <th>Member No</th>
                            <th>Member Name</th>
                            <th>Current Balance</th>
                            <th>Share Status</th>
                            <th>Admin Charge</th>
                            <th>Share Charge</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($preview['members'], 0, 20) as $member): ?>
                            <tr>
                                <td><?php echo $member['member_no']; ?></td>
                                <td><?php echo $member['full_name']; ?></td>
                                <td class="text-end"><?php echo formatCurrency($member['current_balance']); ?></td>
                                <td>
                                    <?php if ($member['share_status'] == 'eligible'): ?>
                                        <span class="badge bg-success">Has Share</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning">No Full Share</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if (isset($member['admin_charge']) && $member['admin_charge'] > 0): ?>
                                        <?php echo formatCurrency($member['admin_charge']); ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if (isset($member['share_charge']) && $member['share_charge'] > 0): ?>
                                        <?php echo formatCurrency($member['share_charge']); ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (isset($member['admin_charge_note'])): ?>
                                        <span class="text-warning"><?php echo $member['admin_charge_note']; ?></span>
                                    <?php elseif (isset($member['share_charge_note'])): ?>
                                        <span class="text-info"><?php echo $member['share_charge_note']; ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (count($preview['members']) > 20): ?>
                    <p class="text-muted">Showing first 20 of <?php echo count($preview['members']); ?> members</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Processing History -->
<!-- Processing History -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title">Processing History</h5>
    </div>
    <div class="card-body">
        <?php
        $history = getYearEndProcessingHistory(20);

        // Check if history is valid and has rows
        if ($history && $history->num_rows > 0):
        ?>
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Year</th>
                            <th>Type</th>
                            <th>Processed At</th>
                            <th>Processed By</th>
                            <th>Members</th>
                            <th>Admin Charges</th>
                            <th>Share Charges</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $history->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?php echo $row['process_year']; ?></strong></td>
                                <td><?php echo ucfirst(str_replace('_', ' ', $row['process_type'])); ?></td>
                                <td><?php echo isset($row['processed_at']) ? formatDate($row['processed_at']) : '-'; ?></td>
                                <td><?php echo $row['processed_by_name'] ?? 'System'; ?></td>
                                <td class="text-end"><?php echo $row['members_processed'] ?? 0; ?></td>
                                <td class="text-end"><?php echo isset($row['total_admin_charges']) ? formatCurrency($row['total_admin_charges']) : '-'; ?></td>
                                <td class="text-end"><?php echo isset($row['total_share_charges']) ? formatCurrency($row['total_share_charges']) : '-'; ?></td>
                                <td>
                                    <?php if (($row['status'] ?? '') == 'completed'): ?>
                                        <span class="badge bg-success">Completed</span>
                                    <?php elseif (($row['status'] ?? '') == 'processing'): ?>
                                        <span class="badge bg-warning">Processing</span>
                                    <?php elseif (($row['status'] ?? '') == 'failed'): ?>
                                        <span class="badge bg-danger">Failed</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-info" onclick="viewLog(<?php echo $row['id'] ?? 0; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-center py-4">
                <i class="fas fa-history fa-3x text-muted mb-3"></i>
                <h5>No Processing History</h5>
                <p class="text-muted">No year-end processing records found. Run your first year-end process to see history here.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    // Update displayed amounts when inputs change
    document.getElementById('admin_amount').addEventListener('input', function() {
        document.getElementById('display_admin_amount').textContent = this.value;
    });

    document.getElementById('share_amount').addEventListener('input', function() {
        document.getElementById('display_share_amount').textContent = this.value;
    });

    function viewLog(id) {
        // Implement view log details
        window.location.href = 'view-processing-log.php?id=' + id;
    }
</script>

<?php include '../../includes/footer.php'; ?>
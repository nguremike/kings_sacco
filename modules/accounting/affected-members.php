<?php
// modules/accounting/affected-members.php
require_once '../../config/config.php';
requireRole('admin');

$log_id = $_GET['log_id'] ?? 0;

// Get log details
$log_sql = "SELECT * FROM year_end_processing_log WHERE id = ?";
$log_result = executeQuery($log_sql, "i", [$log_id]);
$log = $log_result->fetch_assoc();

// Get affected members
$members_sql = "SELECT m.*, pm.admin_charge, pm.share_charge, pm.status, pm.error_message
                FROM year_end_processing_members pm
                JOIN members m ON pm.member_id = m.id
                WHERE pm.log_id = ?
                ORDER BY m.member_no";
$members = executeQuery($members_sql, "i", [$log_id]);

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Affected Members - <?php echo $log['process_year']; ?></h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="year-end-processing.php">Year-End Processing</a></li>
                <li class="breadcrumb-item"><a href="view-processing-log.php?id=<?php echo $log_id; ?>">Log Details</a></li>
                <li class="breadcrumb-item active">Affected Members</li>
            </ul>
        </div>
        <div class="col-auto">
            <a href="view-processing-log.php?id=<?php echo $log_id; ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Log
            </a>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="card-title">Members Processed</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered datatable">
                <thead>
                    <tr>
                        <th>Member No</th>
                        <th>Member Name</th>
                        <th>Admin Charge</th>
                        <th>Share Charge</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Error</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $total_admin = 0;
                    $total_share = 0;
                    while ($member = $members->fetch_assoc()):
                        $total_admin += $member['admin_charge'];
                        $total_share += $member['share_charge'];
                    ?>
                        <tr>
                            <td><?php echo $member['member_no']; ?></td>
                            <td><?php echo $member['full_name']; ?></td>
                            <td class="text-end"><?php echo formatCurrency($member['admin_charge']); ?></td>
                            <td class="text-end"><?php echo formatCurrency($member['share_charge']); ?></td>
                            <td class="text-end fw-bold"><?php echo formatCurrency($member['admin_charge'] + $member['share_charge']); ?></td>
                            <td>
                                <?php if ($member['status'] == 'processed'): ?>
                                    <span class="badge bg-success">Processed</span>
                                <?php elseif ($member['status'] == 'skipped'): ?>
                                    <span class="badge bg-warning">Skipped</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Failed</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $member['error_message'] ?: '-'; ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
                <tfoot>
                    <tr class="table-info">
                        <th colspan="2" class="text-end">Totals:</th>
                        <th class="text-end"><?php echo formatCurrency($total_admin); ?></th>
                        <th class="text-end"><?php echo formatCurrency($total_share); ?></th>
                        <th class="text-end"><?php echo formatCurrency($total_admin + $total_share); ?></th>
                        <th colspan="2"></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
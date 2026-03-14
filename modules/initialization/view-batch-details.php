<?php
// modules/initialization/view-batch-details.php
require_once '../../config/config.php';
requireLogin();

$batch_no = $_GET['batch'] ?? '';

$details_sql = "SELECT sci.*, m.member_no, m.full_name 
                FROM share_contribution_imports sci
                JOIN members m ON sci.member_id = m.id
                WHERE sci.batch_no = ?
                ORDER BY sci.id";
$details = executeQuery($details_sql, "s", [$batch_no]);

$summary_sql = "SELECT 
                COUNT(*) as total_records,
                SUM(contribution_amount) as total_amount,
                SUM(CASE WHEN processed = 1 THEN 1 ELSE 0 END) as processed_count,
                SUM(CASE WHEN processed = 0 THEN 1 ELSE 0 END) as pending_count
                FROM share_contribution_imports
                WHERE batch_no = ?";
$summary = executeQuery($summary_sql, "s", [$batch_no])->fetch_assoc();

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Batch Details: <?php echo $batch_no; ?></h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="import-share-contributions.php">Import Contributions</a></li>
                <li class="breadcrumb-item active">Batch Details</li>
            </ul>
        </div>
        <div class="col-auto">
            <a href="import-share-contributions.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="stats-card primary">
            <div class="stats-content">
                <h3><?php echo $summary['total_records']; ?></h3>
                <p>Total Records</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stats-card success">
            <div class="stats-content">
                <h3><?php echo formatCurrency($summary['total_amount']); ?></h3>
                <p>Total Amount</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stats-card info">
            <div class="stats-content">
                <h3><?php echo $summary['processed_count']; ?></h3>
                <p>Processed</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stats-card warning">
            <div class="stats-content">
                <h3><?php echo $summary['pending_count']; ?></h3>
                <p>Pending</p>
            </div>
        </div>
    </div>
</div>

<!-- Details Table -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title">Batch Records</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>Member No</th>
                        <th>Member Name</th>
                        <th>Amount</th>
                        <th>Date</th>
                        <th>Reference</th>
                        <th>Description</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $details->fetch_assoc()): ?>
                        <tr class="<?php echo $row['processed'] ? 'table-success' : ''; ?>">
                            <td><?php echo $row['member_no']; ?></td>
                            <td><?php echo $row['full_name']; ?></td>
                            <td class="text-end"><?php echo formatCurrency($row['contribution_amount']); ?></td>
                            <td><?php echo formatDate($row['contribution_date']); ?></td>
                            <td><?php echo $row['reference_no']; ?></td>
                            <td><?php echo $row['description']; ?></td>
                            <td>
                                <?php if ($row['processed']): ?>
                                    <span class="badge bg-success">Processed</span>
                                <?php else: ?>
                                    <span class="badge bg-warning">Pending</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
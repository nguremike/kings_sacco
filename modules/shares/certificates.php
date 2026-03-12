<?php
require_once '../../config/config.php';
requireLogin();

$page_title = 'Share Certificates';

// Get all issued shares
$certificates_sql = "SELECT si.*, m.full_name, m.member_no, m.national_id, u.full_name as issued_by_name
                     FROM shares_issued si
                     JOIN members m ON si.member_id = m.id
                     LEFT JOIN users u ON si.issued_by = u.id
                     ORDER BY si.issue_date DESC";
$certificates = executeQuery($certificates_sql);

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Share Certificates</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Shares</a></li>
                <li class="breadcrumb-item active">Certificates</li>
            </ul>
        </div>
        <div class="col-auto">
            <a href="contributions.php" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i>Add Contribution
            </a>
        </div>
    </div>
</div>

<!-- Certificates Table -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title">Issued Share Certificates</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable">
                <thead>
                    <tr>
                        <th>Certificate No</th>
                        <th>Share Number</th>
                        <th>Member</th>
                        <th>Member No</th>
                        <th>Issue Date</th>
                        <th>Amount Paid</th>
                        <th>Issued By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($cert = $certificates->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <span class="badge bg-success"><?php echo $cert['certificate_number']; ?></span>
                            </td>
                            <td><?php echo $cert['share_number']; ?></td>
                            <td><?php echo $cert['full_name']; ?></td>
                            <td><?php echo $cert['member_no']; ?></td>
                            <td><?php echo formatDate($cert['issue_date']); ?></td>
                            <td><?php echo formatCurrency($cert['amount_paid']); ?></td>
                            <td><?php echo $cert['issued_by_name'] ?: 'System'; ?></td>
                            <td>
                                <a href="certificate_print.php?id=<?php echo $cert['id']; ?>" class="btn btn-sm btn-outline-success" target="_blank">
                                    <i class="fas fa-print"></i> Print
                                </a>
                                <a href="certificate_download.php?id=<?php echo $cert['id']; ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-download"></i> PDF
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
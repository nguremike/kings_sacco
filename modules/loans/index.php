<?php
require_once '../../config/config.php';
requireLogin();

$page_title = 'Loans Management';

// Get all loans
$sql = "SELECT l.*, m.full_name, m.member_no, lp.product_name 
        FROM loans l 
        JOIN members m ON l.member_id = m.id 
        JOIN loan_products lp ON l.product_id = lp.id 
        ORDER BY l.created_at DESC";
$loans = executeQuery($sql);

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Loans Management</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item active">Loans</li>
            </ul>
        </div>
        <div class="col-auto">
            <a href="apply.php" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i>New Loan Application
            </a>
        </div>
    </div>
</div>

<!-- Loans Table -->
<div class="card">
    <div class="card-body">
        <ul class="nav nav-tabs mb-3" id="loanTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="all-tab" data-bs-toggle="tab" data-bs-target="#all" type="button">
                    All Loans
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending" type="button">
                    Pending
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="approved-tab" data-bs-toggle="tab" data-bs-target="#approved" type="button">
                    Approved
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="active-tab" data-bs-toggle="tab" data-bs-target="#active" type="button">
                    Active
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="completed-tab" data-bs-toggle="tab" data-bs-target="#completed" type="button">
                    Completed
                </button>
            </li>
        </ul>

        <div class="tab-content" id="loanTabsContent">
            <div class="tab-pane fade show active" id="all" role="tabpanel">
                <div class="table-responsive">
                    <table class="table table-hover datatable">
                        <thead>
                            <tr>
                                <th>Loan No</th>
                                <th>Member</th>
                                <th>Product</th>
                                <th>Principal</th>
                                <th>Interest</th>
                                <th>Total</th>
                                <th>Duration</th>
                                <th>Application Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($loan = $loans->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-info"><?php echo $loan['loan_no']; ?></span>
                                    </td>
                                    <td>
                                        <a href="../members/view.php?id=<?php echo $loan['member_id']; ?>" class="text-decoration-none">
                                            <?php echo $loan['full_name']; ?><br>
                                            <small class="text-muted"><?php echo $loan['member_no']; ?></small>
                                        </a>
                                    </td>
                                    <td><?php echo $loan['product_name']; ?></td>
                                    <td><?php echo formatCurrency($loan['principal_amount']); ?></td>
                                    <td><?php echo formatCurrency($loan['interest_amount']); ?></td>
                                    <td><?php echo formatCurrency($loan['total_amount']); ?></td>
                                    <td><?php echo $loan['duration_months']; ?> months</td>
                                    <td><?php echo formatDate($loan['application_date']); ?></td>
                                    <td>
                                        <?php
                                        $statusClass = [
                                            'pending' => 'warning',
                                            'guarantor_pending' => 'info',
                                            'approved' => 'primary',
                                            'disbursed' => 'success',
                                            'active' => 'success',
                                            'completed' => 'secondary',
                                            'defaulted' => 'danger',
                                            'rejected' => 'dark'
                                        ][$loan['status']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $statusClass; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $loan['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <a href="view.php?id=<?php echo $loan['id']; ?>" class="btn btn-sm btn-outline-info" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if ($loan['status'] == 'pending' && hasRole('admin')): ?>
                                                <a href="approve.php?id=<?php echo $loan['id']; ?>" class="btn btn-sm btn-outline-success" title="Approve">
                                                    <i class="fas fa-check"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($loan['status'] == 'approved' && hasRole('admin')): ?>
                                                <a href="disburse.php?id=<?php echo $loan['id']; ?>" class="btn btn-sm btn-outline-primary" title="Disburse">
                                                    <i class="fas fa-money-bill"></i>
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

            <!-- Other tabs similar structure but filtered by status -->
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
<?php
require_once '../../config/config.php';
requireLogin();

$page_title = 'Loans Management';

// Get filter parameters
$tab = $_GET['tab'] ?? 'all';
$status_filter = $_GET['status'] ?? '';
$member_id = $_GET['member_id'] ?? '';
$product_id = $_GET['product_id'] ?? '';
$date_from = $_GET['from'] ?? '';
$date_to = $_GET['to'] ?? '';

// Get all loans based on tab
$base_sql = "SELECT l.*, 
             m.full_name, m.member_no, m.phone,
             lp.product_name, lp.interest_rate as product_rate,
             (SELECT COUNT(*) FROM loan_repayments WHERE loan_id = l.id) as payment_count,
             (SELECT COALESCE(SUM(amount_paid), 0) FROM loan_repayments WHERE loan_id = l.id) as total_paid
             FROM loans l
             JOIN members m ON l.member_id = m.id
             JOIN loan_products lp ON l.product_id = lp.id";

$where_conditions = ["1=1"];
$params = [];
$types = "";

// Apply tab filter
switch ($tab) {
    case 'pending':
        $where_conditions[] = "l.status IN ('pending', 'guarantor_pending')";
        break;
    case 'approved':
        $where_conditions[] = "l.status = 'approved'";
        break;
    case 'active':
        $where_conditions[] = "l.status IN ('disbursed', 'active')";
        break;
    case 'completed':
        $where_conditions[] = "l.status = 'completed'";
        break;
    case 'defaulted':
        $where_conditions[] = "l.status = 'defaulted'";
        break;
    case 'rejected':
        $where_conditions[] = "l.status = 'rejected'";
        break;
    default:
        // All loans - no additional filter
        break;
}

// Apply additional filters
if (!empty($status_filter) && $status_filter != 'all') {
    $where_conditions[] = "l.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($member_id)) {
    $where_conditions[] = "l.member_id = ?";
    $params[] = $member_id;
    $types .= "i";
}

if (!empty($product_id)) {
    $where_conditions[] = "l.product_id = ?";
    $params[] = $product_id;
    $types .= "i";
}

if (!empty($date_from)) {
    $where_conditions[] = "l.application_date >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $where_conditions[] = "l.application_date <= ?";
    $params[] = $date_to;
    $types .= "s";
}

$where_clause = implode(" AND ", $where_conditions);
$order_by = "ORDER BY 
    CASE 
        WHEN l.status = 'pending' THEN 1
        WHEN l.status = 'guarantor_pending' THEN 2
        WHEN l.status = 'approved' THEN 3
        WHEN l.status = 'disbursed' THEN 4
        WHEN l.status = 'active' THEN 5
        WHEN l.status = 'completed' THEN 6
        WHEN l.status = 'defaulted' THEN 7
        WHEN l.status = 'rejected' THEN 8
        ELSE 9
    END,
    l.application_date DESC";

$sql = "$base_sql WHERE $where_clause $order_by";

if (!empty($params)) {
    $loans = executeQuery($sql, $types, $params);
} else {
    $loans = executeQuery($sql);
}

// Get statistics for each tab
$stats_sql = "SELECT 
              SUM(CASE WHEN status IN ('pending', 'guarantor_pending') THEN 1 ELSE 0 END) as pending_count,
              SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_count,
              SUM(CASE WHEN status IN ('disbursed', 'active') THEN 1 ELSE 0 END) as active_count,
              SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
              SUM(CASE WHEN status = 'defaulted' THEN 1 ELSE 0 END) as defaulted_count,
              SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
              SUM(CASE WHEN status IN ('disbursed', 'active') THEN principal_amount ELSE 0 END) as active_principal,
              SUM(CASE WHEN status = 'completed' THEN principal_amount ELSE 0 END) as completed_principal
              FROM loans";
$stats_result = executeQuery($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get members for filter dropdown
$members = executeQuery("SELECT id, member_no, full_name FROM members WHERE membership_status = 'active' ORDER BY member_no");

// Get loan products for filter dropdown
$products = executeQuery("SELECT id, product_name FROM loan_products WHERE status = 1 ORDER BY product_name");

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
            <button class="btn btn-success" onclick="exportLoans()">
                <i class="fas fa-file-excel me-2"></i>Export
            </button>
        </div>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-2">
        <a href="?tab=pending" class="text-decoration-none">
            <div class="stats-card warning">
                <div class="stats-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stats-content">
                    <h3><?php echo number_format($stats['pending_count'] ?? 0); ?></h3>
                    <p>Pending</p>
                    <small>Awaiting review</small>
                </div>
            </div>
        </a>
    </div>

    <div class="col-md-2">
        <a href="?tab=approved" class="text-decoration-none">
            <div class="stats-card info">
                <div class="stats-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stats-content">
                    <h3><?php echo number_format($stats['approved_count'] ?? 0); ?></h3>
                    <p>Approved</p>
                    <small>Ready for disbursement</small>
                </div>
            </div>
        </a>
    </div>

    <div class="col-md-2">
        <a href="?tab=active" class="text-decoration-none">
            <div class="stats-card success">
                <div class="stats-icon">
                    <i class="fas fa-play-circle"></i>
                </div>
                <div class="stats-content">
                    <h3><?php echo number_format($stats['active_count'] ?? 0); ?></h3>
                    <p>Active</p>
                    <small><?php echo formatCurrency($stats['active_principal'] ?? 0); ?></small>
                </div>
            </div>
        </a>
    </div>

    <div class="col-md-2">
        <a href="?tab=completed" class="text-decoration-none">
            <div class="stats-card secondary">
                <div class="stats-icon">
                    <i class="fas fa-flag-checkered"></i>
                </div>
                <div class="stats-content">
                    <h3><?php echo number_format($stats['completed_count'] ?? 0); ?></h3>
                    <p>Completed</p>
                    <small><?php echo formatCurrency($stats['completed_principal'] ?? 0); ?></small>
                </div>
            </div>
        </a>
    </div>

    <div class="col-md-2">
        <a href="?tab=defaulted" class="text-decoration-none">
            <div class="stats-card danger">
                <div class="stats-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stats-content">
                    <h3><?php echo number_format($stats['defaulted_count'] ?? 0); ?></h3>
                    <p>Defaulted</p>
                    <small>Urgent attention</small>
                </div>
            </div>
        </a>
    </div>

    <div class="col-md-2">
        <a href="?tab=rejected" class="text-decoration-none">
            <div class="stats-card dark">
                <div class="stats-icon">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="stats-content">
                    <h3><?php echo number_format($stats['rejected_count'] ?? 0); ?></h3>
                    <p>Rejected</p>
                    <small>Declined applications</small>
                </div>
            </div>
        </a>
    </div>
</div>

<!-- Filter Card -->
<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="card-title mb-0"><i class="fas fa-filter me-2"></i>Filter Loans</h5>
    </div>
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <input type="hidden" name="tab" value="<?php echo $tab; ?>">

            <div class="col-md-2">
                <label for="status" class="form-label">Status</label>
                <select class="form-control" id="status" name="status">
                    <option value="">All Status</option>
                    <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="guarantor_pending" <?php echo $status_filter == 'guarantor_pending' ? 'selected' : ''; ?>>Guarantor Pending</option>
                    <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="disbursed" <?php echo $status_filter == 'disbursed' ? 'selected' : ''; ?>>Disbursed</option>
                    <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="defaulted" <?php echo $status_filter == 'defaulted' ? 'selected' : ''; ?>>Defaulted</option>
                    <option value="rejected" <?php echo $status_filter == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                </select>
            </div>

            <div class="col-md-3">
                <label for="member_id" class="form-label">Member</label>
                <select class="form-control" id="member_id" name="member_id">
                    <option value="">All Members</option>
                    <?php while ($member = $members->fetch_assoc()): ?>
                        <option value="<?php echo $member['id']; ?>" <?php echo $member_id == $member['id'] ? 'selected' : ''; ?>>
                            <?php echo $member['member_no']; ?> - <?php echo $member['full_name']; ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label for="product_id" class="form-label">Product</label>
                <select class="form-control" id="product_id" name="product_id">
                    <option value="">All Products</option>
                    <?php while ($product = $products->fetch_assoc()): ?>
                        <option value="<?php echo $product['id']; ?>" <?php echo $product_id == $product['id'] ? 'selected' : ''; ?>>
                            <?php echo $product['product_name']; ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label for="from" class="form-label">From Date</label>
                <input type="date" class="form-control" id="from" name="from" value="<?php echo $date_from; ?>">
            </div>

            <div class="col-md-2">
                <label for="to" class="form-label">To Date</label>
                <input type="date" class="form-control" id="to" name="to" value="<?php echo $date_to; ?>">
            </div>

            <div class="col-md-1">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Tab Navigation -->
<div class="card">
    <div class="card-header p-0">
        <ul class="nav nav-tabs" id="loanTabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link <?php echo $tab == 'all' ? 'active' : ''; ?>" href="?tab=all">
                    All Loans
                    <?php
                    $total = array_sum([
                        $stats['pending_count'] ?? 0,
                        $stats['approved_count'] ?? 0,
                        $stats['active_count'] ?? 0,
                        $stats['completed_count'] ?? 0,
                        $stats['defaulted_count'] ?? 0,
                        $stats['rejected_count'] ?? 0
                    ]);
                    ?>
                    <span class="badge bg-secondary ms-1"><?php echo $total; ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $tab == 'pending' ? 'active' : ''; ?>" href="?tab=pending">
                    <i class="fas fa-clock text-warning me-1"></i>Pending
                    <?php if (($stats['pending_count'] ?? 0) > 0): ?>
                        <span class="badge bg-warning ms-1"><?php echo $stats['pending_count']; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $tab == 'approved' ? 'active' : ''; ?>" href="?tab=approved">
                    <i class="fas fa-check-circle text-info me-1"></i>Approved
                    <?php if (($stats['approved_count'] ?? 0) > 0): ?>
                        <span class="badge bg-info ms-1"><?php echo $stats['approved_count']; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $tab == 'active' ? 'active' : ''; ?>" href="?tab=active">
                    <i class="fas fa-play-circle text-success me-1"></i>Active
                    <?php if (($stats['active_count'] ?? 0) > 0): ?>
                        <span class="badge bg-success ms-1"><?php echo $stats['active_count']; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $tab == 'completed' ? 'active' : ''; ?>" href="?tab=completed">
                    <i class="fas fa-flag-checkered text-secondary me-1"></i>Completed
                    <?php if (($stats['completed_count'] ?? 0) > 0): ?>
                        <span class="badge bg-secondary ms-1"><?php echo $stats['completed_count']; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $tab == 'defaulted' ? 'active' : ''; ?>" href="?tab=defaulted">
                    <i class="fas fa-exclamation-triangle text-danger me-1"></i>Defaulted
                    <?php if (($stats['defaulted_count'] ?? 0) > 0): ?>
                        <span class="badge bg-danger ms-1"><?php echo $stats['defaulted_count']; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $tab == 'rejected' ? 'active' : ''; ?>" href="?tab=rejected">
                    <i class="fas fa-times-circle text-dark me-1"></i>Rejected
                    <?php if (($stats['rejected_count'] ?? 0) > 0): ?>
                        <span class="badge bg-dark ms-1"><?php echo $stats['rejected_count']; ?></span>
                    <?php endif; ?>
                </a>
            </li>
        </ul>
    </div>

    <div class="card-body">
        <!-- Tab Content -->
        <div class="tab-content">
            <!-- All Loans Tab -->
            <div class="tab-pane fade show active">
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
                                <th>Paid</th>
                                <th>Balance</th>
                                <th>Duration</th>
                                <th>Application Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $loans->data_seek(0);
                            while ($loan = $loans->fetch_assoc()):
                                $balance = $loan['total_amount'] - $loan['total_paid'];
                                $progress = $loan['total_amount'] > 0 ? ($loan['total_paid'] / $loan['total_amount']) * 100 : 0;
                            ?>
                                <tr class="<?php
                                            echo $loan['status'] == 'defaulted' ? 'table-danger' : ($loan['status'] == 'completed' ? 'table-success' : ($loan['status'] == 'pending' ? 'table-warning' : ''));
                                            ?>">
                                    <td>
                                        <span class="badge bg-primary"><?php echo $loan['loan_no']; ?></span>
                                    </td>
                                    <td>
                                        <a href="../members/view.php?id=<?php echo $loan['member_id']; ?>" class="text-decoration-none">
                                            <strong><?php echo $loan['full_name']; ?></strong>
                                            <br>
                                            <small class="text-muted"><?php echo $loan['member_no']; ?></small>
                                        </a>
                                    </td>
                                    <td>
                                        <?php echo $loan['product_name']; ?>
                                        <br>
                                        <small class="text-muted"><?php echo $loan['interest_rate']; ?>% p.a.</small>
                                    </td>
                                    <td><?php echo formatCurrency($loan['principal_amount']); ?></td>
                                    <td><?php echo formatCurrency($loan['interest_amount']); ?></td>
                                    <td><strong><?php echo formatCurrency($loan['total_amount']); ?></strong></td>
                                    <td class="text-success"><?php echo formatCurrency($loan['total_paid']); ?></td>
                                    <td class="<?php echo $balance > 0 ? 'text-danger' : 'text-success'; ?> fw-bold">
                                        <?php echo formatCurrency($balance); ?>
                                        <br>
                                        <small><?php echo number_format($progress, 1); ?>%</small>
                                    </td>
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

                                            <?php if ($loan['status'] == 'pending' || $loan['status'] == 'guarantor_pending'): ?>
                                                <a href="approve.php?id=<?php echo $loan['id']; ?>" class="btn btn-sm btn-outline-success" title="Review">
                                                    <i class="fas fa-check-circle"></i>
                                                </a>
                                                <a href="process-guarantors.php?loan_id=<?php echo $loan['id']; ?>" class="btn btn-sm btn-outline-warning" title="Guarantors">
                                                    <i class="fas fa-handshake"></i>
                                                </a>
                                            <?php endif; ?>

                                            <?php if ($loan['status'] == 'approved'): ?>
                                                <a href="disburse.php?id=<?php echo $loan['id']; ?>" class="btn btn-sm btn-outline-success" title="Disburse">
                                                    <i class="fas fa-money-bill"></i>
                                                </a>
                                            <?php endif; ?>

                                            <?php if ($loan['status'] == 'disbursed' || $loan['status'] == 'active'): ?>
                                                <a href="repayment.php?loan_id=<?php echo $loan['id']; ?>" class="btn btn-sm btn-outline-primary" title="Record Payment">
                                                    <i class="fas fa-credit-card"></i>
                                                </a>
                                            <?php endif; ?>

                                            <a href="generate-document.php?loan_id=<?php echo $loan['id']; ?>&type=schedule" class="btn btn-sm btn-outline-secondary" title="Schedule" target="_blank">
                                                <i class="fas fa-calendar-alt"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>

                            <?php if ($loans->num_rows == 0): ?>
                                <tr>
                                    <td colspan="12" class="text-center py-4">
                                        <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                                        <h5>No loans found</h5>
                                        <p class="text-muted">No loans match your current filter criteria.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer with summary -->
    <div class="card-footer">
        <div class="row">
            <div class="col-md-6">
                <small class="text-muted">
                    <i class="fas fa-info-circle me-1"></i>
                    Showing loans for <strong><?php echo ucfirst($tab); ?></strong> tab
                    <?php if (!empty($member_id) || !empty($product_id) || !empty($date_from) || !empty($date_to)): ?>
                        with applied filters
                    <?php endif; ?>
                </small>
            </div>
            <div class="col-md-6 text-end">
                <small class="text-muted">
                    Total Principal:
                    <?php
                    $total_principal = 0;
                    $loans->data_seek(0);
                    while ($loan = $loans->fetch_assoc()) {
                        $total_principal += $loan['principal_amount'];
                    }
                    echo formatCurrency($total_principal);
                    ?>
                </small>
            </div>
        </div>
    </div>
</div>

<script>
    function exportLoans() {
        var tab = '<?php echo $tab; ?>';
        var status = document.getElementById('status').value;
        var member = document.getElementById('member_id').value;
        var product = document.getElementById('product_id').value;
        var from = document.getElementById('from').value;
        var to = document.getElementById('to').value;

        window.location.href = 'export.php?tab=' + tab + '&status=' + status + '&member=' + member +
            '&product=' + product + '&from=' + from + '&to=' + to;
    }

    // Initialize tooltips
    document.addEventListener('DOMContentLoaded', function() {
        var tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        tooltips.forEach(function(tooltip) {
            new bootstrap.Tooltip(tooltip);
        });
    });
</script>

<style>
    .stats-card {
        transition: transform 0.2s;
        cursor: pointer;
    }

    .stats-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
    }

    .stats-card.info {
        background: linear-gradient(135deg, #17a2b8, #138496);
    }

    .stats-card.info .stats-icon {
        background: rgba(255, 255, 255, 0.2);
        color: white;
    }

    .stats-card.info .stats-content h3,
    .stats-card.info .stats-content p {
        color: white;
    }

    .stats-card.secondary {
        background: linear-gradient(135deg, #6c757d, #5a6268);
    }

    .stats-card.secondary .stats-icon {
        background: rgba(255, 255, 255, 0.2);
        color: white;
    }

    .stats-card.secondary .stats-content h3,
    .stats-card.secondary .stats-content p {
        color: white;
    }

    .stats-card.dark {
        background: linear-gradient(135deg, #343a40, #23272b);
    }

    .stats-card.dark .stats-icon {
        background: rgba(255, 255, 255, 0.2);
        color: white;
    }

    .stats-card.dark .stats-content h3,
    .stats-card.dark .stats-content p {
        color: white;
    }

    .nav-tabs .nav-link {
        font-weight: 500;
        color: #495057;
        border: none;
        padding: 12px 20px;
    }

    .nav-tabs .nav-link:hover {
        border: none;
        color: #007bff;
    }

    .nav-tabs .nav-link.active {
        color: #007bff;
        background: transparent;
        border-bottom: 3px solid #007bff;
    }

    .nav-tabs .nav-link .badge {
        font-size: 11px;
    }

    .table td {
        vertical-align: middle;
    }

    .btn-group .btn {
        margin-right: 2px;
    }

    @media (max-width: 768px) {
        .stats-card {
            margin-bottom: 10px;
        }

        .nav-tabs {
            flex-wrap: nowrap;
            overflow-x: auto;
            white-space: nowrap;
        }

        .nav-tabs .nav-link {
            padding: 8px 12px;
            font-size: 13px;
        }

        .btn-group {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .btn-group .btn {
            margin-right: 0;
            border-radius: 4px !important;
        }
    }
</style>

<?php include '../../includes/footer.php'; ?>
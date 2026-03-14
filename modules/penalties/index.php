<?php
// modules/penalties/index.php
require_once '../../config/config.php';
requireLogin();

$page_title = 'Penalties Management';

// Get filter parameters
$member_id = $_GET['member_id'] ?? '';
$status = $_GET['status'] ?? 'all';
$type = $_GET['type'] ?? 'all';
$date_from = $_GET['from'] ?? date('Y-m-01');
$date_to = $_GET['to'] ?? date('Y-m-d');

// Build query based on filters
$where_conditions = ["1=1"];
$params = [];
$types = "";

if (!empty($member_id)) {
    $where_conditions[] = "p.member_id = ?";
    $params[] = $member_id;
    $types .= "i";
}

if ($status != 'all') {
    $where_conditions[] = "p.status = ?";
    $params[] = $status;
    $types .= "s";
}

if ($type != 'all') {
    $where_conditions[] = "p.penalty_type = ?";
    $params[] = $type;
    $types .= "s";
}

if (!empty($date_from)) {
    $where_conditions[] = "p.penalty_date >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $where_conditions[] = "p.penalty_date <= ?";
    $params[] = $date_to;
    $types .= "s";
}

$where_clause = implode(" AND ", $where_conditions);

// Get penalties with details
$penalties_sql = "SELECT p.*, 
                  m.member_no, m.full_name as member_name, m.phone,
                  l.loan_no,
                  u.full_name as created_by_name,
                  w.full_name as waived_by_name
                  FROM penalties p
                  JOIN members m ON p.member_id = m.id
                  LEFT JOIN loans l ON p.loan_id = l.id
                  LEFT JOIN users u ON p.created_by = u.id
                  LEFT JOIN users w ON p.waived_by = w.id
                  WHERE $where_clause
                  ORDER BY p.penalty_date DESC, p.created_at DESC";

$penalties = !empty($params) ? executeQuery($penalties_sql, $types, $params) : executeQuery($penalties_sql);

// Get summary statistics
$summary_sql = "SELECT 
                COUNT(*) as total_penalties,
                SUM(CASE WHEN p.status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN p.status = 'paid' THEN 1 ELSE 0 END) as paid_count,
                SUM(CASE WHEN p.status = 'waived' THEN 1 ELSE 0 END) as waived_count,
                SUM(CASE WHEN p.status = 'overdue' THEN 1 ELSE 0 END) as overdue_count,
                COALESCE(SUM(CASE WHEN p.status IN ('pending', 'overdue') THEN p.amount ELSE 0 END), 0) as outstanding_amount,
                COALESCE(SUM(p.amount), 0) as total_amount
                FROM penalties p
                WHERE $where_clause";

$summary_result = !empty($params) ? executeQuery($summary_sql, $types, $params) : executeQuery($summary_sql);
$summary = $summary_result->fetch_assoc();

// Get members for dropdown
$members = executeQuery("SELECT id, member_no, full_name FROM members WHERE membership_status = 'active' ORDER BY member_no");

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Penalties Management</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item active">Penalties</li>
            </ul>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPenaltyModal">
                <i class="fas fa-plus-circle me-2"></i>Add Penalty
            </button>
            <button class="btn btn-success" onclick="exportPenalties()">
                <i class="fas fa-file-excel me-2"></i>Export
            </button>
        </div>
    </div>
</div>

<!-- Filter Card -->
<div class="card mb-4">
    <div class="card-header bg-info text-white">
        <h5 class="card-title mb-0"><i class="fas fa-filter me-2"></i>Filter Penalties</h5>
    </div>
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-2">
                <label for="member_id" class="form-label">Member</label>
                <select class="form-control" id="member_id" name="member_id">
                    <option value="">All Members</option>
                    <?php while ($m = $members->fetch_assoc()): ?>
                        <option value="<?php echo $m['id']; ?>" <?php echo $member_id == $m['id'] ? 'selected' : ''; ?>>
                            <?php echo $m['member_no']; ?> - <?php echo $m['full_name']; ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label for="status" class="form-label">Status</label>
                <select class="form-control" id="status" name="status">
                    <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>All Status</option>
                    <option value="pending" <?php echo $status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="paid" <?php echo $status == 'paid' ? 'selected' : ''; ?>>Paid</option>
                    <option value="waived" <?php echo $status == 'waived' ? 'selected' : ''; ?>>Waived</option>
                    <option value="overdue" <?php echo $status == 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                </select>
            </div>

            <div class="col-md-2">
                <label for="type" class="form-label">Penalty Type</label>
                <select class="form-control" id="type" name="type">
                    <option value="all" <?php echo $type == 'all' ? 'selected' : ''; ?>>All Types</option>
                    <option value="loan_penalty" <?php echo $type == 'loan_penalty' ? 'selected' : ''; ?>>Loan Penalty</option>
                    <option value="withdrawal_penalty" <?php echo $type == 'withdrawal_penalty' ? 'selected' : ''; ?>>Withdrawal Penalty</option>
                    <option value="late_fee" <?php echo $type == 'late_fee' ? 'selected' : ''; ?>>Late Fee</option>
                    <option value="administration_fee" <?php echo $type == 'administration_fee' ? 'selected' : ''; ?>>Admin Fee</option>
                    <option value="other" <?php echo $type == 'other' ? 'selected' : ''; ?>>Other</option>
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

            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary d-block w-100">
                    <i class="fas fa-search"></i> Filter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="stats-card warning">
            <div class="stats-icon">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo $summary['pending_count'] ?? 0; ?></h3>
                <p>Pending</p>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card success">
            <div class="stats-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo $summary['paid_count'] ?? 0; ?></h3>
                <p>Paid</p>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card info">
            <div class="stats-icon">
                <i class="fas fa-hand-peace"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo $summary['waived_count'] ?? 0; ?></h3>
                <p>Waived</p>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card danger">
            <div class="stats-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($summary['outstanding_amount'] ?? 0); ?></h3>
                <p>Outstanding</p>
            </div>
        </div>
    </div>
</div>

<!-- Penalties Table -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title">Penalties List</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-striped datatable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Member</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Due Date</th>
                        <th>Loan</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($penalty = $penalties->fetch_assoc()): ?>
                        <tr class="<?php
                                    echo $penalty['status'] == 'paid' ? 'table-success' : ($penalty['status'] == 'waived' ? 'table-info' : ($penalty['status'] == 'overdue' ? 'table-danger' : ''));
                                    ?>">
                            <td><?php echo formatDate($penalty['penalty_date']); ?></td>
                            <td>
                                <a href="../members/view.php?id=<?php echo $penalty['member_id']; ?>">
                                    <strong><?php echo $penalty['member_name']; ?></strong>
                                    <br>
                                    <small><?php echo $penalty['member_no']; ?></small>
                                </a>
                            </td>
                            <td>
                                <span class="badge bg-<?php
                                                        echo $penalty['penalty_type'] == 'loan_penalty' ? 'danger' : ($penalty['penalty_type'] == 'withdrawal_penalty' ? 'warning' : ($penalty['penalty_type'] == 'late_fee' ? 'info' : ($penalty['penalty_type'] == 'administration_fee' ? 'secondary' : 'primary')));
                                                        ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $penalty['penalty_type'])); ?>
                                </span>
                            </td>
                            <td><?php echo $penalty['description']; ?></td>
                            <td class="fw-bold text-danger"><?php echo formatCurrency($penalty['amount']); ?></td>
                            <td>
                                <?php if ($penalty['status'] == 'paid'): ?>
                                    <span class="badge bg-success">Paid</span>
                                <?php elseif ($penalty['status'] == 'pending'): ?>
                                    <span class="badge bg-warning">Pending</span>
                                <?php elseif ($penalty['status'] == 'waived'): ?>
                                    <span class="badge bg-info">Waived</span>
                                <?php elseif ($penalty['status'] == 'overdue'): ?>
                                    <span class="badge bg-danger">Overdue</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $penalty['due_date'] ? formatDate($penalty['due_date']) : '-'; ?></td>
                            <td>
                                <?php if ($penalty['loan_id']): ?>
                                    <a href="../loans/view.php?id=<?php echo $penalty['loan_id']; ?>">
                                        <?php echo $penalty['loan_no']; ?>
                                    </a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-sm btn-success" onclick="recordPayment(<?php echo $penalty['id']; ?>, <?php echo $penalty['amount']; ?>)" <?php echo $penalty['status'] == 'paid' ? 'disabled' : ''; ?>>
                                        <i class="fas fa-money-bill"></i> Pay
                                    </button>
                                    <button type="button" class="btn btn-sm btn-info" onclick="waivePenalty(<?php echo $penalty['id']; ?>, <?php echo $penalty['amount']; ?>)" <?php echo $penalty['status'] == 'paid' || $penalty['status'] == 'waived' ? 'disabled' : ''; ?>>
                                        <i class="fas fa-hand-peace"></i> Waive
                                    </button>
                                    <a href="details.php?id=<?php echo $penalty['id']; ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
                <tfoot>
                    <tr class="table-info">
                        <th colspan="4" class="text-end">Totals:</th>
                        <th><?php echo formatCurrency($summary['total_amount'] ?? 0); ?></th>
                        <th colspan="4"></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- Add Penalty Modal -->
<div class="modal fade" id="addPenaltyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Add New Penalty</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="process-penalty.php">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Member</label>
                        <select class="form-control" name="member_id" required>
                            <option value="">Select Member</option>
                            <?php
                            $all_members = executeQuery("SELECT id, member_no, full_name FROM members WHERE membership_status = 'active' ORDER BY member_no");
                            while ($m = $all_members->fetch_assoc()):
                            ?>
                                <option value="<?php echo $m['id']; ?>">
                                    <?php echo $m['member_no']; ?> - <?php echo $m['full_name']; ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Penalty Type</label>
                        <select class="form-control" name="penalty_type" required>
                            <option value="">Select Type</option>
                            <option value="loan_penalty">Loan Penalty</option>
                            <option value="withdrawal_penalty">Withdrawal Penalty</option>
                            <option value="late_fee">Late Fee</option>
                            <option value="administration_fee">Administration Fee</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Amount (KES)</label>
                        <input type="number" class="form-control" name="amount" min="1" step="100" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Penalty Date</label>
                        <input type="date" class="form-control" name="penalty_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Due Date (Optional)</label>
                        <input type="date" class="form-control" name="due_date">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Related Loan (Optional)</label>
                        <select class="form-control" name="loan_id">
                            <option value="">No related loan</option>
                            <?php
                            $loans = executeQuery("SELECT id, loan_no FROM loans WHERE status IN ('active', 'disbursed') LIMIT 50");
                            while ($l = $loans->fetch_assoc()):
                            ?>
                                <option value="<?php echo $l['id']; ?>"><?php echo $l['loan_no']; ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="2" required></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Reference Number</label>
                        <input type="text" class="form-control" name="reference_no" value="PEN<?php echo time(); ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Penalty</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">Record Penalty Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="process-payment.php">
                <div class="modal-body">
                    <input type="hidden" name="penalty_id" id="payment_penalty_id">

                    <div class="mb-3">
                        <label class="form-label">Amount to Pay</label>
                        <input type="text" class="form-control" id="payment_amount" readonly>
                        <input type="hidden" name="amount" id="payment_amount_hidden">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Payment Date</label>
                        <input type="date" class="form-control" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Payment Method</label>
                        <select class="form-control" name="payment_method" required>
                            <option value="cash">Cash</option>
                            <option value="bank">Bank Transfer</option>
                            <option value="mpesa">M-Pesa</option>
                            <option value="cheque">Cheque</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Reference Number</label>
                        <input type="text" class="form-control" name="reference_no" value="PAY<?php echo time(); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Record Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Waiver Modal -->
<div class="modal fade" id="waiverModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">Waive Penalty</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="process-waiver.php">
                <div class="modal-body">
                    <input type="hidden" name="penalty_id" id="waiver_penalty_id">

                    <div class="mb-3">
                        <label class="form-label">Amount to Waive</label>
                        <input type="text" class="form-control" id="waiver_amount" readonly>
                        <input type="hidden" name="amount" id="waiver_amount_hidden">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Waiver Date</label>
                        <input type="date" class="form-control" name="waiver_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Reason for Waiver</label>
                        <textarea class="form-control" name="reason" rows="3" required></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Additional Notes</label>
                        <textarea class="form-control" name="notes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info text-white">Waive Penalty</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function recordPayment(id, amount) {
        document.getElementById('payment_penalty_id').value = id;
        document.getElementById('payment_amount').value = formatCurrency(amount);
        document.getElementById('payment_amount_hidden').value = amount;

        var modal = new bootstrap.Modal(document.getElementById('paymentModal'));
        modal.show();
    }

    function waivePenalty(id, amount) {
        document.getElementById('waiver_penalty_id').value = id;
        document.getElementById('waiver_amount').value = formatCurrency(amount);
        document.getElementById('waiver_amount_hidden').value = amount;

        var modal = new bootstrap.Modal(document.getElementById('waiverModal'));
        modal.show();
    }

    function formatCurrency(amount) {
        return 'KES ' + parseFloat(amount).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function exportPenalties() {
        var member = document.getElementById('member_id').value;
        var status = document.getElementById('status').value;
        var type = document.getElementById('type').value;
        var from = document.getElementById('from').value;
        var to = document.getElementById('to').value;

        window.location.href = 'export.php?member=' + member + '&status=' + status + '&type=' + type + '&from=' + from + '&to=' + to;
    }
</script>

<style>
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

    .table-success {
        background-color: rgba(40, 167, 69, 0.05) !important;
    }

    .table-danger {
        background-color: rgba(220, 53, 69, 0.05) !important;
    }

    .table-info {
        background-color: rgba(23, 162, 184, 0.05) !important;
    }

    .btn-group .btn {
        margin-right: 2px;
    }

    @media (max-width: 768px) {
        .btn-group {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .btn-group .btn {
            margin-right: 0;
        }
    }
</style>

<?php include '../../includes/footer.php'; ?>
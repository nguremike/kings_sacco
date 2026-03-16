<?php
// modules/admin_charges/index.php
require_once '../../config/config.php';
requireLogin();

$page_title = 'Admin Charges Management';

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
    $where_conditions[] = "ac.member_id = ?";
    $params[] = $member_id;
    $types .= "i";
}

if ($status != 'all') {
    $where_conditions[] = "ac.status = ?";
    $params[] = $status;
    $types .= "s";
}

if ($type != 'all') {
    $where_conditions[] = "ac.charge_type = ?";
    $params[] = $type;
    $types .= "s";
}

if (!empty($date_from)) {
    $where_conditions[] = "ac.charge_date >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $where_conditions[] = "ac.charge_date <= ?";
    $params[] = $date_to;
    $types .= "s";
}

$where_clause = implode(" AND ", $where_conditions);

// Get admin charges with details
$charges_sql = "SELECT ac.*, 
                m.member_no, m.full_name as member_name, m.phone,
                l.loan_no,
                u.full_name as created_by_name,
                w.full_name as waived_by_name
                FROM admin_charges ac
                JOIN members m ON ac.member_id = m.id
                LEFT JOIN loans l ON ac.loan_id = l.id
                LEFT JOIN users u ON ac.created_by = u.id
                LEFT JOIN users w ON ac.waived_by = w.id
                WHERE $where_clause
                ORDER BY ac.charge_date DESC, ac.created_at DESC";

$charges = !empty($params) ? executeQuery($charges_sql, $types, $params) : executeQuery($charges_sql);

// Get summary statistics
$summary_sql = "SELECT 
                COUNT(*) as total_charges,
                SUM(CASE WHEN ac.status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN ac.status = 'paid' THEN 1 ELSE 0 END) as paid_count,
                SUM(CASE WHEN ac.status = 'waived' THEN 1 ELSE 0 END) as waived_count,
                SUM(CASE WHEN ac.status = 'overdue' THEN 1 ELSE 0 END) as overdue_count,
                COALESCE(SUM(CASE WHEN ac.status IN ('pending', 'overdue') THEN ac.amount ELSE 0 END), 0) as outstanding_amount,
                COALESCE(SUM(ac.amount), 0) as total_amount,
                COUNT(DISTINCT ac.member_id) as members_affected
                FROM admin_charges ac
                WHERE $where_clause";

$summary_result = !empty($params) ? executeQuery($summary_sql, $types, $params) : executeQuery($summary_sql);
$summary = $summary_result->fetch_assoc();

// Get charge rates for reference
$rates_sql = "SELECT * FROM admin_charge_rates WHERE is_active = 1 ORDER BY charge_type";
$rates = executeQuery($rates_sql);

// Get members for dropdown
$members = executeQuery("SELECT id, member_no, full_name FROM members WHERE membership_status = 'active' ORDER BY member_no");

// Get monthly totals for chart
$monthly_sql = "SELECT 
                DATE_FORMAT(charge_date, '%Y-%m') as month,
                SUM(amount) as total,
                SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as paid
                FROM admin_charges
                WHERE charge_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                GROUP BY DATE_FORMAT(charge_date, '%Y-%m')
                ORDER BY month ASC";
$monthly_result = executeQuery($monthly_sql);

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Admin Charges Management</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item active">Admin Charges</li>
            </ul>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addChargeModal">
                <i class="fas fa-plus-circle me-2"></i>Add Charge
            </button>
            <button type="button" class="btn btn-success" onclick="generateMonthlyCharges()">
                <i class="fas fa-calendar-alt me-2"></i>Generate Monthly
            </button>
            <button class="btn btn-info" onclick="exportCharges()">
                <i class="fas fa-file-excel me-2"></i>Export
            </button>
        </div>
    </div>
</div>

<!-- Filter Card -->
<div class="card mb-4">
    <div class="card-header bg-info text-white">
        <h5 class="card-title mb-0"><i class="fas fa-filter me-2"></i>Filter Admin Charges</h5>
    </div>
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-2">
                <label for="member_id" class="form-label">Member</label>
                <select class="form-control select2" name="member_id" id="member_select" required style="width: 100%;">
                    <option value="">-- Select Member --</option>
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
                <label for="type" class="form-label">Charge Type</label>
                <select class="form-control" id="type" name="type">
                    <option value="all" <?php echo $type == 'all' ? 'selected' : ''; ?>>All Types</option>
                    <option value="registration" <?php echo $type == 'registration' ? 'selected' : ''; ?>>Registration</option>
                    <option value="monthly_fee" <?php echo $type == 'monthly_fee' ? 'selected' : ''; ?>>Monthly Fee</option>
                    <option value="annual_fee" <?php echo $type == 'annual_fee' ? 'selected' : ''; ?>>Annual Fee</option>
                    <option value="statement_fee" <?php echo $type == 'statement_fee' ? 'selected' : ''; ?>>Statement Fee</option>
                    <option value="sms_charge" <?php echo $type == 'sms_charge' ? 'selected' : ''; ?>>SMS Charge</option>
                    <option value="ledger_fee" <?php echo $type == 'ledger_fee' ? 'selected' : ''; ?>>Ledger Fee</option>
                    <option value="loan_processing" <?php echo $type == 'loan_processing' ? 'selected' : ''; ?>>Loan Processing</option>
                    <option value="loan_insurance" <?php echo $type == 'loan_insurance' ? 'selected' : ''; ?>>Loan Insurance</option>
                    <option value="guarantor_fee" <?php echo $type == 'guarantor_fee' ? 'selected' : ''; ?>>Guarantor Fee</option>
                    <option value="dividend_processing" <?php echo $type == 'dividend_processing' ? 'selected' : ''; ?>>Dividend Processing</option>
                    <option value="withdrawal_fee" <?php echo $type == 'withdrawal_fee' ? 'selected' : ''; ?>>Withdrawal Fee</option>
                    <option value="transfer_fee" <?php echo $type == 'transfer_fee' ? 'selected' : ''; ?>>Transfer Fee</option>
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
    <div class="col-md-2">
        <div class="stats-card primary">
            <div class="stats-icon">
                <i class="fas fa-receipt"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo $summary['total_charges'] ?? 0; ?></h3>
                <p>Total Charges</p>
            </div>
        </div>
    </div>

    <div class="col-md-2">
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

    <div class="col-md-2">
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

    <div class="col-md-2">
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

    <div class="col-md-2">
        <div class="stats-card danger">
            <div class="stats-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo $summary['overdue_count'] ?? 0; ?></h3>
                <p>Overdue</p>
            </div>
        </div>
    </div>

    <div class="col-md-2">
        <div class="stats-card secondary">
            <div class="stats-icon">
                <i class="fas fa-users"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo $summary['members_affected'] ?? 0; ?></h3>
                <p>Members</p>
            </div>
        </div>
    </div>
</div>

<!-- Monthly Chart -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Monthly Admin Charges Trend</h5>
            </div>
            <div class="card-body">
                <canvas id="monthlyChart" height="100"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Admin Charges Table -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title">Admin Charges List</h5>
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
                    <?php while ($charge = $charges->fetch_assoc()): ?>
                        <tr class="<?php
                                    echo $charge['status'] == 'paid' ? 'table-success' : ($charge['status'] == 'waived' ? 'table-info' : ($charge['status'] == 'overdue' ? 'table-danger' : ''));
                                    ?>">
                            <td><?php echo formatDate($charge['charge_date']); ?></td>
                            <td>
                                <a href="../members/view.php?id=<?php echo $charge['member_id']; ?>">
                                    <strong><?php echo $charge['member_name']; ?></strong>
                                    <br>
                                    <small><?php echo $charge['member_no']; ?></small>
                                </a>
                            </td>
                            <td>
                                <span class="badge bg-<?php
                                                        echo $charge['charge_type'] == 'registration' ? 'primary' : ($charge['charge_type'] == 'monthly_fee' ? 'info' : ($charge['charge_type'] == 'loan_processing' ? 'warning' : ($charge['charge_type'] == 'withdrawal_fee' ? 'danger' : ($charge['charge_type'] == 'annual_fee' ? 'success' : 'secondary'))));
                                                        ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $charge['charge_type'])); ?>
                                </span>
                            </td>
                            <td><?php echo $charge['description']; ?></td>
                            <td class="fw-bold text-danger"><?php echo formatCurrency($charge['amount']); ?></td>
                            <td>
                                <?php if ($charge['status'] == 'paid'): ?>
                                    <span class="badge bg-success">Paid</span>
                                <?php elseif ($charge['status'] == 'pending'): ?>
                                    <span class="badge bg-warning">Pending</span>
                                <?php elseif ($charge['status'] == 'waived'): ?>
                                    <span class="badge bg-info">Waived</span>
                                <?php elseif ($charge['status'] == 'overdue'): ?>
                                    <span class="badge bg-danger">Overdue</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $charge['due_date'] ? formatDate($charge['due_date']) : '-'; ?></td>
                            <td>
                                <?php if ($charge['loan_id']): ?>
                                    <a href="../loans/view.php?id=<?php echo $charge['loan_id']; ?>">
                                        <?php echo $charge['loan_no']; ?>
                                    </a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-sm btn-success" onclick="recordPayment(<?php echo $charge['id']; ?>, <?php echo $charge['amount']; ?>)" <?php echo $charge['status'] == 'paid' ? 'disabled' : ''; ?>>
                                        <i class="fas fa-money-bill"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-info" onclick="waiveCharge(<?php echo $charge['id']; ?>, <?php echo $charge['amount']; ?>)" <?php echo $charge['status'] == 'paid' || $charge['status'] == 'waived' ? 'disabled' : ''; ?>>
                                        <i class="fas fa-hand-peace"></i>
                                    </button>
                                    <a href="details.php?id=<?php echo $charge['id']; ?>" class="btn btn-sm btn-outline-primary">
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

<!-- Add Charge Modal -->
<div class="modal fade" id="addChargeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Add Admin Charge</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="process-charge.php">
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
                        <label class="form-label">Charge Type</label>
                        <select class="form-control" name="charge_type" id="charge_type" required onchange="updateAmountFromRate()">
                            <option value="">Select Type</option>
                            <?php
                            $rates->data_seek(0);
                            while ($rate = $rates->fetch_assoc()):
                            ?>
                                <option value="<?php echo $rate['charge_type']; ?>"
                                    data-method="<?php echo $rate['calculation_method']; ?>"
                                    data-rate="<?php echo $rate['rate_value']; ?>"
                                    data-min="<?php echo $rate['min_amount']; ?>">
                                    <?php echo $rate['charge_name']; ?> (<?php echo $rate['calculation_method']; ?>)
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Amount (KES)</label>
                        <input type="number" class="form-control" name="amount" id="amount" min="1" step="100" required>
                        <small class="text-muted" id="amount_hint"></small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Charge Date</label>
                        <input type="date" class="form-control" name="charge_date" value="<?php echo date('Y-m-d'); ?>" required>
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
                            $loans = executeQuery("SELECT id, loan_no FROM loans WHERE status IN ('active', 'disbursed', 'pending') LIMIT 50");
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
                        <input type="text" class="form-control" name="reference_no" value="CHG<?php echo time(); ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Charge</button>
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
                <h5 class="modal-title">Record Charge Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="process-payment.php">
                <div class="modal-body">
                    <input type="hidden" name="charge_id" id="payment_charge_id">

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
                        <label class="form-label">Receipt Number</label>
                        <input type="text" class="form-control" name="receipt_no" value="RCT<?php echo time(); ?>" readonly>
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
                <h5 class="modal-title">Waive Admin Charge</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="process-waiver.php">
                <div class="modal-body">
                    <input type="hidden" name="charge_id" id="waiver_charge_id">

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
                    <button type="submit" class="btn btn-info text-white">Waive Charge</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Generate Monthly Charges Modal -->
<div class="modal fade" id="monthlyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">Generate Monthly Charges</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="generate-monthly.php">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Month</label>
                        <select class="form-control" name="month" required>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $m == date('m') ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Year</label>
                        <select class="form-control" name="year" required>
                            <?php for ($y = date('Y'); $y >= date('Y') - 1; $y--): ?>
                                <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="include_monthly_fee" name="include_monthly_fee" value="1" checked>
                            <label class="form-check-label" for="include_monthly_fee">
                                Monthly Maintenance Fee (KES 100)
                            </label>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="include_ledger_fee" name="include_ledger_fee" value="1" checked>
                            <label class="form-check-label" for="include_ledger_fee">
                                Ledger Fee (KES 20)
                            </label>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="include_sms_charges" name="include_sms_charges" value="1">
                            <label class="form-check-label" for="include_sms_charges">
                                SMS Charges (Based on usage)
                            </label>
                        </div>
                    </div>

                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        This will generate monthly charges for all active members.
                        Previous charges for the same period will be skipped.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Generate Charges</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Update amount based on selected rate
    document.getElementById('charge_type').addEventListener('change', function() {
        var selected = this.options[this.selectedIndex];
        var method = selected.dataset.method;
        var rate = selected.dataset.rate;
        var min = selected.dataset.min;

        var amountField = document.getElementById('amount');
        var hintField = document.getElementById('amount_hint');

        if (method == 'fixed') {
            amountField.value = rate;
            amountField.readOnly = true;
            hintField.innerHTML = 'Fixed amount: KES ' + parseFloat(rate).toLocaleString();
        } else if (method == 'percentage') {
            amountField.value = '';
            amountField.readOnly = false;
            hintField.innerHTML = 'Percentage rate: ' + rate + '% (min KES ' + parseFloat(min).toLocaleString() + ')';
        } else {
            amountField.value = '';
            amountField.readOnly = false;
            hintField.innerHTML = '';
        }
    });

    // Record payment
    function recordPayment(id, amount) {
        document.getElementById('payment_charge_id').value = id;
        document.getElementById('payment_amount').value = formatCurrency(amount);
        document.getElementById('payment_amount_hidden').value = amount;

        var modal = new bootstrap.Modal(document.getElementById('paymentModal'));
        modal.show();
    }

    // Waive charge
    function waiveCharge(id, amount) {
        document.getElementById('waiver_charge_id').value = id;
        document.getElementById('waiver_amount').value = formatCurrency(amount);
        document.getElementById('waiver_amount_hidden').value = amount;

        var modal = new bootstrap.Modal(document.getElementById('waiverModal'));
        modal.show();
    }

    // Generate monthly charges
    function generateMonthlyCharges() {
        var modal = new bootstrap.Modal(document.getElementById('monthlyModal'));
        modal.show();
    }

    // Format currency
    function formatCurrency(amount) {
        return 'KES ' + parseFloat(amount).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    // Export charges
    function exportCharges() {
        var member = document.getElementById('member_id').value;
        var status = document.getElementById('status').value;
        var type = document.getElementById('type').value;
        var from = document.getElementById('from').value;
        var to = document.getElementById('to').value;

        window.location.href = 'export.php?member=' + member + '&status=' + status + '&type=' + type + '&from=' + from + '&to=' + to;
    }

    // Initialize chart
    document.addEventListener('DOMContentLoaded', function() {
        var months = [];
        var totals = [];
        var paid = [];

        <?php
        $monthly_result->data_seek(0);
        while ($row = $monthly_result->fetch_assoc()):
        ?>
            months.push('<?php echo $row['month']; ?>');
            totals.push(<?php echo $row['total'] / 1000; ?>);
            paid.push(<?php echo $row['paid'] / 1000; ?>);
        <?php endwhile; ?>

        if (months.length > 0) {
            var ctx = document.getElementById('monthlyChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: months,
                    datasets: [{
                        label: 'Total Charges (KES Thousands)',
                        data: totals,
                        borderColor: '#dc3545',
                        backgroundColor: 'rgba(220, 53, 69, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }, {
                        label: 'Paid Amount (KES Thousands)',
                        data: paid,
                        borderColor: '#28a745',
                        backgroundColor: 'rgba(40, 167, 69, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Amount (KES Thousands)'
                            }
                        }
                    }
                }
            });
        }
    });

    // Basic initialization
</script>

<style>
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

<script>
    $(document).ready(function() {
        $('#member_select').select2({
            placeholder: '-- Select Member --',
            allowClear: true,
            width: '100%'
        });
    });
</script>
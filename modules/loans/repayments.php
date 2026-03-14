<?php
require_once '../../config/config.php';
requireLogin();

$page_title = 'Loan Repayments';

// Get filter parameters
$loan_id = $_GET['loan_id'] ?? '';
$member_id = $_GET['member_id'] ?? '';
$date_from = $_GET['from'] ?? date('Y-m-d', strtotime('-3 months'));
$date_to = $_GET['to'] ?? date('Y-m-d');
$payment_method = $_GET['method'] ?? 'all';

// Get members for dropdown
$members = executeQuery("SELECT id, member_no, full_name FROM members WHERE membership_status = 'active' ORDER BY full_name");

// Get loans for dropdown (if member selected)
$loans = [];
if (!empty($member_id)) {
    $loans_sql = "SELECT l.id, l.loan_no, l.principal_amount, l.total_amount, l.status,
                  COALESCE((SELECT SUM(amount_paid) FROM loan_repayments WHERE loan_id = l.id), 0) as amount_paid,
                  (l.total_amount - COALESCE((SELECT SUM(amount_paid) FROM loan_repayments WHERE loan_id = l.id), 0)) as balance
                  FROM loans l 
                  WHERE l.member_id = ? AND l.status IN ('disbursed', 'active')
                  ORDER BY l.created_at DESC";
    $loans_result = executeQuery($loans_sql, "i", [$member_id]);
    while ($row = $loans_result->fetch_assoc()) {
        $loans[] = $row;
    }
}

// Build query based on filters
$where_conditions = ["1=1"];
$params = [];
$types = "";

if (!empty($loan_id)) {
    $where_conditions[] = "lr.loan_id = ?";
    $params[] = $loan_id;
    $types .= "i";
}

if (!empty($member_id)) {
    $where_conditions[] = "l.member_id = ?";
    $params[] = $member_id;
    $types .= "i";
}

if (!empty($date_from)) {
    $where_conditions[] = "lr.payment_date >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $where_conditions[] = "lr.payment_date <= ?";
    $params[] = $date_to;
    $types .= "s";
}

if ($payment_method != 'all') {
    $where_conditions[] = "lr.payment_method = ?";
    $params[] = $payment_method;
    $types .= "s";
}

$where_clause = implode(" AND ", $where_conditions);

// Get repayments with details
$repayments_sql = "SELECT lr.*, 
                   l.loan_no, l.principal_amount as loan_principal, l.total_amount as loan_total,
                   m.id as member_id, m.member_no, m.full_name as member_name, m.phone,
                   u.full_name as recorded_by_name,
                   lp.product_name
                   FROM loan_repayments lr
                   JOIN loans l ON lr.loan_id = l.id
                   JOIN members m ON l.member_id = m.id
                   LEFT JOIN loan_products lp ON l.product_id = lp.id
                   LEFT JOIN users u ON lr.created_by = u.id
                   WHERE $where_clause
                   ORDER BY lr.payment_date DESC, lr.created_at DESC";

$repayments = !empty($params) ? executeQuery($repayments_sql, $types, $params) : executeQuery($repayments_sql);

// Get summary statistics
$summary_sql = "SELECT 
                COUNT(*) as total_payments,
                COALESCE(SUM(lr.amount_paid), 0) as total_amount,
                COALESCE(SUM(lr.principal_paid), 0) as total_principal,
                COALESCE(SUM(lr.interest_paid), 0) as total_interest,
                COALESCE(SUM(lr.penalty_paid), 0) as total_penalty,
                COUNT(DISTINCT lr.loan_id) as loans_serviced,
                COUNT(DISTINCT l.member_id) as members_count
                FROM loan_repayments lr
                JOIN loans l ON lr.loan_id = l.id
                WHERE $where_clause";

$summary_result = !empty($params) ? executeQuery($summary_sql, $types, $params) : executeQuery($summary_sql);
$summary = $summary_result->fetch_assoc();

// Get payment method breakdown
$method_sql = "SELECT 
               lr.payment_method,
               COUNT(*) as count,
               COALESCE(SUM(lr.amount_paid), 0) as total
               FROM loan_repayments lr
               JOIN loans l ON lr.loan_id = l.id
               WHERE $where_clause
               GROUP BY lr.payment_method
               ORDER BY total DESC";

$method_result = !empty($params) ? executeQuery($method_sql, $types, $params) : executeQuery($method_sql);

// Get monthly trend
$monthly_sql = "SELECT 
                DATE_FORMAT(lr.payment_date, '%Y-%m') as month,
                COUNT(*) as payment_count,
                COALESCE(SUM(lr.amount_paid), 0) as total_amount
                FROM loan_repayments lr
                JOIN loans l ON lr.loan_id = l.id
                WHERE lr.payment_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                GROUP BY DATE_FORMAT(lr.payment_date, '%Y-%m')
                ORDER BY month ASC";
$monthly_result = executeQuery($monthly_sql);

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Loan Repayments</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Loans</a></li>
                <li class="breadcrumb-item active">Repayments</li>
            </ul>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#recordPaymentModal">
                <i class="fas fa-plus-circle me-2"></i>Record Payment
            </button>
            <button class="btn btn-success" onclick="exportToExcel()">
                <i class="fas fa-file-excel me-2"></i>Export
            </button>
            <button class="btn btn-danger" onclick="exportToPDF()">
                <i class="fas fa-file-pdf me-2"></i>PDF
            </button>
        </div>
    </div>
</div>

<!-- Alert Messages -->
<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?php
        echo $_SESSION['success'];
        unset($_SESSION['success']);
        ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>
        <?php
        echo $_SESSION['error'];
        unset($_SESSION['error']);
        ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Filter Card -->
<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="card-title mb-0"><i class="fas fa-filter me-2"></i>Filter Repayments</h5>
    </div>
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-2">
                <label for="member_id" class="form-label">Member</label>
                <select class="form-control select2" id="member_id" name="member_id" onchange="this.form.submit()">
                    <option value="">-- All Members --</option>
                    <?php
                    $members->data_seek(0);
                    while ($member = $members->fetch_assoc()):
                    ?>
                        <option value="<?php echo $member['id']; ?>" <?php echo $member_id == $member['id'] ? 'selected' : ''; ?>>
                            <?php echo $member['full_name']; ?> (<?php echo $member['member_no']; ?>)
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label for="loan_id" class="form-label">Loan</label>
                <select class="form-control" id="loan_id" name="loan_id">
                    <option value="">-- All Loans --</option>
                    <?php foreach ($loans as $loan): ?>
                        <option value="<?php echo $loan['id']; ?>" <?php echo $loan_id == $loan['id'] ? 'selected' : ''; ?>>
                            <?php echo $loan['loan_no']; ?> (<?php echo formatCurrency($loan['principal_amount']); ?>)
                        </option>
                    <?php endforeach; ?>
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
                <label for="method" class="form-label">Payment Method</label>
                <select class="form-control" id="method" name="method">
                    <option value="all" <?php echo $payment_method == 'all' ? 'selected' : ''; ?>>All Methods</option>
                    <option value="cash" <?php echo $payment_method == 'cash' ? 'selected' : ''; ?>>Cash</option>
                    <option value="bank" <?php echo $payment_method == 'bank' ? 'selected' : ''; ?>>Bank Transfer</option>
                    <option value="mpesa" <?php echo $payment_method == 'mpesa' ? 'selected' : ''; ?>>M-Pesa</option>
                    <option value="cheque" <?php echo $payment_method == 'cheque' ? 'selected' : ''; ?>>Cheque</option>
                    <option value="mobile" <?php echo $payment_method == 'mobile' ? 'selected' : ''; ?>>Mobile Money</option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1">
                        <i class="fas fa-search me-2"></i>Filter
                    </button>
                    <a href="repayments.php" class="btn btn-secondary">
                        <i class="fas fa-undo"></i>
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="stats-card primary">
            <div class="stats-icon">
                <i class="fas fa-credit-card"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo number_format($summary['total_payments'] ?? 0); ?></h3>
                <p>Total Payments</p>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card success">
            <div class="stats-icon">
                <i class="fas fa-money-bill-wave"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($summary['total_amount'] ?? 0); ?></h3>
                <p>Total Amount</p>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card info">
            <div class="stats-icon">
                <i class="fas fa-chart-pie"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo $summary['loans_serviced'] ?? 0; ?></h3>
                <p>Loans Serviced</p>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card warning">
            <div class="stats-icon">
                <i class="fas fa-users"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo $summary['members_count'] ?? 0; ?></h3>
                <p>Active Members</p>
            </div>
        </div>
    </div>
</div>

<!-- Payment Method Breakdown -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Payment Method Breakdown</h5>
            </div>
            <div class="card-body">
                <canvas id="methodChart" height="100"></canvas>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Monthly Payment Trend</h5>
            </div>
            <div class="card-body">
                <canvas id="trendChart" height="100"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Repayments Table -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title">Loan Repayment History</h5>
        <div class="card-tools">
            <span class="badge bg-info">
                Total: <?php echo formatCurrency($summary['total_amount'] ?? 0); ?>
            </span>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-striped datatable" id="repaymentsTable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Member</th>
                        <th>Loan No</th>
                        <th>Product</th>
                        <th>Amount Paid</th>
                        <th>Principal</th>
                        <th>Interest</th>
                        <th>Penalty</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th>Recorded By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $total_amount = 0;
                    $total_principal = 0;
                    $total_interest = 0;
                    $total_penalty = 0;

                    if ($repayments->num_rows > 0):
                        while ($repayment = $repayments->fetch_assoc()):
                            $total_amount += $repayment['amount_paid'];
                            $total_principal += $repayment['principal_paid'];
                            $total_interest += $repayment['interest_paid'];
                            $total_penalty += $repayment['penalty_paid'] ?? 0;
                    ?>
                            <tr>
                                <td><?php echo formatDate($repayment['payment_date']); ?></td>
                                <td>
                                    <a href="../members/view.php?id=<?php echo $repayment['member_id']; ?>">
                                        <strong><?php echo $repayment['member_name']; ?></strong>
                                        <br>
                                        <small><?php echo $repayment['member_no']; ?></small>
                                    </a>
                                </td>
                                <td>
                                    <a href="view.php?id=<?php echo $repayment['loan_id']; ?>">
                                        <?php echo $repayment['loan_no']; ?>
                                    </a>
                                </td>
                                <td><?php echo $repayment['product_name'] ?? 'N/A'; ?></td>
                                <td class="fw-bold text-success"><?php echo formatCurrency($repayment['amount_paid']); ?></td>
                                <td><?php echo formatCurrency($repayment['principal_paid']); ?></td>
                                <td><?php echo formatCurrency($repayment['interest_paid']); ?></td>
                                <td><?php echo $repayment['penalty_paid'] ? formatCurrency($repayment['penalty_paid']) : '-'; ?></td>
                                <td>
                                    <span class="badge bg-<?php
                                                            echo $repayment['payment_method'] == 'cash' ? 'success' : ($repayment['payment_method'] == 'bank' ? 'primary' : ($repayment['payment_method'] == 'mpesa' ? 'info' : ($repayment['payment_method'] == 'cheque' ? 'warning' : 'secondary')));
                                                            ?>">
                                        <?php echo ucfirst($repayment['payment_method']); ?>
                                    </span>
                                </td>
                                <td><?php echo $repayment['reference_no'] ?: '-'; ?></td>
                                <td><?php echo $repayment['recorded_by_name'] ?: 'System'; ?></td>
                                <td>
                                    <div class="btn-group">
                                        <a href="receipt.php?id=<?php echo $repayment['id']; ?>" class="btn btn-sm btn-outline-info" title="Receipt" target="_blank">
                                            <i class="fas fa-receipt"></i>
                                        </a>
                                        <a href="view.php?id=<?php echo $repayment['loan_id']; ?>" class="btn btn-sm btn-outline-primary" title="View Loan">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if (hasRole('admin')): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="confirmDelete(<?php echo $repayment['id']; ?>)" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php
                        endwhile;
                    else:
                        ?>
                        <tr>
                            <td colspan="12" class="text-center py-4">
                                <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No repayments found for the selected criteria.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr class="table-info fw-bold">
                        <td colspan="4" class="text-end">Totals:</td>
                        <td><?php echo formatCurrency($total_amount); ?></td>
                        <td><?php echo formatCurrency($total_principal); ?></td>
                        <td><?php echo formatCurrency($total_interest); ?></td>
                        <td><?php echo formatCurrency($total_penalty); ?></td>
                        <td colspan="4"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <div class="card-footer text-muted">
        <div class="row">
            <div class="col-md-6">
                <small>
                    <i class="fas fa-info-circle me-1"></i>
                    Showing <?php echo $repayments->num_rows; ?> repayments
                </small>
            </div>
            <div class="col-md-6 text-end">
                <small>
                    Generated on: <?php echo date('d M Y H:i:s'); ?>
                </small>
            </div>
        </div>
    </div>
</div>

<!-- Record Payment Modal -->
<div class="modal fade" id="recordPaymentModal" tabindex="-1" aria-labelledby="recordPaymentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="recordPaymentModalLabel">
                    <i class="fas fa-credit-card me-2"></i>Record Loan Payment
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="process-repayment.php" class="needs-validation" novalidate>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="modal_member_id" class="form-label">Select Member <span class="text-danger">*</span></label>
                            <select class="form-control select2-modal" id="modal_member_id" name="member_id" required>
                                <option value="">-- Select Member --</option>
                                <?php
                                $all_members = executeQuery("SELECT id, member_no, full_name FROM members WHERE membership_status = 'active' ORDER BY full_name");
                                while ($member = $all_members->fetch_assoc()):
                                ?>
                                    <option value="<?php echo $member['id']; ?>">
                                        <?php echo $member['full_name']; ?> (<?php echo $member['member_no']; ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="modal_loan_id" class="form-label">Select Loan <span class="text-danger">*</span></label>
                            <select class="form-control" id="modal_loan_id" name="loan_id" required>
                                <option value="">-- First select a member --</option>
                            </select>
                        </div>
                    </div>

                    <!-- Loan Details Summary -->
                    <div class="card bg-light mb-3" id="loanDetailsCard" style="display: none;">
                        <div class="card-body">
                            <h6 class="card-title">Loan Summary</h6>
                            <div class="row">
                                <div class="col-md-3 col-6">
                                    <small class="text-muted d-block">Principal</small>
                                    <strong id="loanPrincipal">-</strong>
                                </div>
                                <div class="col-md-3 col-6">
                                    <small class="text-muted d-block">Total Amount</small>
                                    <strong id="loanTotal">-</strong>
                                </div>
                                <div class="col-md-3 col-6">
                                    <small class="text-muted d-block">Paid to Date</small>
                                    <strong id="loanPaid">-</strong>
                                </div>
                                <div class="col-md-3 col-6">
                                    <small class="text-muted d-block">Balance</small>
                                    <strong id="loanBalance" class="text-danger">-</strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="amount_paid" class="form-label">Amount Paid (KES) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="amount_paid" name="amount_paid"
                                min="1" step="100" required onchange="calculateAllocation()">
                            <div class="invalid-feedback">Please enter the amount paid.</div>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="payment_date" class="form-label">Payment Date</label>
                            <input type="date" class="form-control" id="payment_date" name="payment_date"
                                value="<?php echo date('Y-m-d'); ?>">
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="payment_method" class="form-label">Payment Method</label>
                            <select class="form-control" id="payment_method" name="payment_method">
                                <option value="cash">Cash</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="mpesa">M-Pesa</option>
                                <option value="cheque">Cheque</option>
                                <option value="mobile">Mobile Money</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="reference_no" class="form-label">Reference Number</label>
                            <input type="text" class="form-control" id="reference_no" name="reference_no"
                                placeholder="Transaction ID, Cheque number, etc.">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="receipt_no" class="form-label">Receipt Number</label>
                            <input type="text" class="form-control" id="receipt_no" name="receipt_no"
                                value="RCT<?php echo time(); ?>" readonly>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="2" placeholder="Optional notes about this payment"></textarea>
                    </div>

                    <!-- Payment Allocation -->
                    <div class="card mt-3 border-info">
                        <div class="card-header bg-info text-white">
                            <h6 class="mb-0">Payment Allocation</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <label for="principal_paid" class="form-label">Principal Portion</label>
                                    <input type="number" class="form-control" id="principal_paid" name="principal_paid"
                                        value="0" min="0" step="100" readonly>
                                </div>
                                <div class="col-md-4">
                                    <label for="interest_paid" class="form-label">Interest Portion</label>
                                    <input type="number" class="form-control" id="interest_paid" name="interest_paid"
                                        value="0" min="0" step="100" readonly>
                                </div>
                                <div class="col-md-4">
                                    <label for="penalty_paid" class="form-label">Penalty Portion</label>
                                    <input type="number" class="form-control" id="penalty_paid" name="penalty_paid"
                                        value="0" min="0" step="100">
                                </div>
                            </div>
                            <small class="text-muted d-block mt-2">
                                <i class="fas fa-info-circle me-1"></i>
                                Allocation will be automatically calculated based on the repayment schedule. Penalty can be adjusted manually.
                            </small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save me-2"></i>Record Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- AJAX Loan Loader Script -->
<script>
    $(document).ready(function() {
        // Initialize Select2 for modal
        $('#modal_member_id').select2({
            theme: 'bootstrap-5',
            width: '100%',
            dropdownParent: $('#recordPaymentModal')
        });

        // Member selection change handler - Load loans via AJAX
        $('#modal_member_id').on('change', function() {
            var memberId = $(this).val();
            var loanSelect = $('#modal_loan_id');

            // Clear current options
            loanSelect.empty();
            loanSelect.append('<option value="">Loading loans...</option>');

            // Hide loan details card
            $('#loanDetailsCard').hide();

            if (memberId) {
                // Make AJAX call to get member's loans
                $.ajax({
                    url: 'ajax/get-member-loans.php',
                    type: 'POST',
                    data: {
                        member_id: memberId
                    },
                    dataType: 'json',
                    success: function(response) {
                        loanSelect.empty();

                        if (response.success && response.loans.length > 0) {
                            loanSelect.append('<option value="">-- Select Loan --</option>');

                            $.each(response.loans, function(index, loan) {
                                var status = parseFloat(loan.balance) > 0 ?
                                    'Balance: ' + formatCurrency(loan.balance) :
                                    'Fully Paid';

                                var option = '<option value="' + loan.id + '" ' +
                                    'data-principal="' + loan.principal + '" ' +
                                    'data-total="' + loan.total + '" ' +
                                    'data-paid="' + loan.paid + '" ' +
                                    'data-balance="' + loan.balance + '">' +
                                    loan.loan_no + ' - ' + formatCurrency(loan.principal) +
                                    ' (' + status + ')</option>';
                                loanSelect.append(option);
                            });
                        } else {
                            loanSelect.append('<option value="" disabled>No active loans found for this member</option>');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', error);
                        loanSelect.empty();
                        loanSelect.append('<option value="" disabled>Error loading loans. Please try again.</option>');
                    }
                });
            } else {
                loanSelect.empty();
                loanSelect.append('<option value="">-- First select a member --</option>');
            }
        });

        // Loan selection change handler
        $('#modal_loan_id').on('change', function() {
            var selected = $(this).find('option:selected');

            if (selected.val()) {
                var principal = selected.data('principal');
                var total = selected.data('total');
                var paid = selected.data('paid');
                var balance = selected.data('balance');

                $('#loanPrincipal').html(formatCurrency(principal));
                $('#loanTotal').html(formatCurrency(total));
                $('#loanPaid').html(formatCurrency(paid));
                $('#loanBalance').html(formatCurrency(balance));

                $('#loanDetailsCard').show();

                // Set max amount to balance
                $('#amount_paid').attr('max', balance);

                // Clear previous amount
                $('#amount_paid').val('');
                $('#principal_paid').val(0);
                $('#interest_paid').val(0);
            } else {
                $('#loanDetailsCard').hide();
            }
        });

        // Amount paid change handler
        $('#amount_paid').on('input', function() {
            calculateAllocation();
        });
    });

    // Calculate payment allocation
    function calculateAllocation() {
        var amount = parseFloat($('#amount_paid').val()) || 0;
        var selected = $('#modal_loan_id').find('option:selected');

        if (selected.val() && amount > 0) {
            var balance = selected.data('balance');

            // In production, you would make an AJAX call to get the correct allocation
            // For now, we'll do a simple allocation based on typical loan structure
            var interestRate = 0.1; // Assume 10% of payment goes to interest
            var interestPortion = Math.min(amount * interestRate, balance * 0.1);
            var principalPortion = amount - interestPortion;

            if (principalPortion > balance) {
                principalPortion = balance;
                interestPortion = amount - principalPortion;
            }

            $('#principal_paid').val(principalPortion.toFixed(2));
            $('#interest_paid').val(interestPortion.toFixed(2));
        } else {
            $('#principal_paid').val(0);
            $('#interest_paid').val(0);
        }
    }

    // Format currency helper
    function formatCurrency(amount) {
        return 'KES ' + parseFloat(amount).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    // Export functions
    function exportToExcel() {
        var memberId = '<?php echo $member_id; ?>';
        var loanId = '<?php echo $loan_id; ?>';
        var from = '<?php echo $date_from; ?>';
        var to = '<?php echo $date_to; ?>';
        var method = '<?php echo $payment_method; ?>';

        window.location.href = 'export-repayments.php?member_id=' + memberId + '&loan_id=' + loanId + '&from=' + from + '&to=' + to + '&method=' + method + '&format=excel';
    }

    function exportToPDF() {
        var memberId = '<?php echo $member_id; ?>';
        var loanId = '<?php echo $loan_id; ?>';
        var from = '<?php echo $date_from; ?>';
        var to = '<?php echo $date_to; ?>';
        var method = '<?php echo $payment_method; ?>';

        window.location.href = 'export-repayments.php?member_id=' + memberId + '&loan_id=' + loanId + '&from=' + from + '&to=' + to + '&method=' + method + '&format=pdf';
    }

    // Confirm delete
    function confirmDelete(id) {
        Swal.fire({
            title: 'Delete Repayment Record',
            text: 'Are you sure you want to delete this repayment record? This action cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'delete-repayment.php?id=' + id;
            }
        });
    }

    // Form validation
    (function() {
        'use strict';
        var forms = document.querySelectorAll('.needs-validation');
        Array.prototype.slice.call(forms).forEach(function(form) {
            form.addEventListener('submit', function(event) {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                form.classList.add('was-validated');
            }, false);
        });
    })();

    // Initialize charts
    document.addEventListener('DOMContentLoaded', function() {
        // Payment Method Chart
        var methodLabels = [];
        var methodData = [];
        var methodColors = [];

        <?php
        $method_result->data_seek(0);
        while ($method = $method_result->fetch_assoc()):
        ?>
            methodLabels.push('<?php echo ucfirst($method['payment_method']); ?>');
            methodData.push(<?php echo $method['total']; ?>);
            methodColors.push('<?php
                                echo $method['payment_method'] == 'cash' ? '#28a745' : ($method['payment_method'] == 'bank' ? '#007bff' : ($method['payment_method'] == 'mpesa' ? '#17a2b8' : ($method['payment_method'] == 'cheque' ? '#ffc107' : '#6c757d')));
                                ?>');
        <?php endwhile; ?>

        if (methodLabels.length > 0) {
            var methodCtx = document.getElementById('methodChart').getContext('2d');
            new Chart(methodCtx, {
                type: 'doughnut',
                data: {
                    labels: methodLabels,
                    datasets: [{
                        data: methodData,
                        backgroundColor: methodColors,
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 12,
                                padding: 15
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    var label = context.label || '';
                                    var value = context.raw || 0;
                                    var total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    var percentage = Math.round((value / total) * 100);
                                    return label + ': ' + formatCurrency(value) + ' (' + percentage + '%)';
                                }
                            }
                        }
                    }
                }
            });
        }

        // Monthly Trend Chart
        var months = [];
        var paymentCounts = [];
        var amountData = [];

        <?php
        $monthly_result->data_seek(0);
        while ($row = $monthly_result->fetch_assoc()):
        ?>
            months.push('<?php echo $row['month']; ?>');
            paymentCounts.push(<?php echo $row['payment_count']; ?>);
            amountData.push(<?php echo $row['total_amount'] / 1000; ?>); // In thousands
        <?php endwhile; ?>

        if (months.length > 0) {
            var trendCtx = document.getElementById('trendChart').getContext('2d');
            new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: months,
                    datasets: [{
                        label: 'Amount (KES Thousands)',
                        data: amountData,
                        borderColor: '#28a745',
                        backgroundColor: 'rgba(40, 167, 69, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4,
                        yAxisID: 'y-amount'
                    }, {
                        label: 'Number of Payments',
                        data: paymentCounts,
                        borderColor: '#007bff',
                        backgroundColor: 'rgba(0, 123, 255, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4,
                        yAxisID: 'y-count'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    scales: {
                        'y-amount': {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            title: {
                                display: true,
                                text: 'Amount (KES Thousands)'
                            },
                            beginAtZero: true
                        },
                        'y-count': {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            title: {
                                display: true,
                                text: 'Number of Payments'
                            },
                            grid: {
                                drawOnChartArea: false
                            },
                            beginAtZero: true
                        }
                    }
                }
            });
        }
    });
</script>

<style>
    .stats-card .stats-content small {
        font-size: 11px;
        opacity: 0.9;
    }

    .table td {
        vertical-align: middle;
    }

    .card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        font-weight: 600;
    }

    .card-header .card-tools {
        margin-left: auto;
    }

    .badge {
        font-size: 11px;
        padding: 5px 8px;
    }

    #loanDetailsCard {
        transition: all 0.3s ease;
        border-left: 4px solid #17a2b8;
    }

    #loanDetailsCard .card-body {
        padding: 1rem;
    }

    #loanDetailsCard .col-md-3,
    #loanDetailsCard .col-6 {
        margin-bottom: 10px;
    }

    #loanDetailsCard small {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .modal-lg {
        max-width: 800px;
    }

    .select2-container--bootstrap-5 .select2-selection {
        min-height: 38px;
    }

    /* Fix for modal z-index */
    .modal {
        z-index: 1050;
    }

    .modal-backdrop {
        z-index: 1040;
    }

    .select2-container {
        z-index: 1060 !important;
    }

    @media (max-width: 768px) {
        .btn-group {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .btn-group .btn {
            margin: 0;
            border-radius: 4px !important;
        }

        #loanDetailsCard .col-6 {
            border-right: none;
            border-bottom: 1px solid #dee2e6;
            padding-bottom: 8px;
        }

        #loanDetailsCard .col-6:last-child {
            border-bottom: none;
        }
    }
</style>

<?php include '../../includes/footer.php'; ?>
<?php
require_once '../../config/config.php';
requireRole('admin');

$page_title = 'Dividend Payments';

// Handle payment recording
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'record_payment') {
        $dividend_id = $_POST['dividend_id'];
        $payment_date = $_POST['payment_date'];
        $amount_paid = $_POST['amount_paid'];
        $payment_method = $_POST['payment_method'];
        $reference_no = $_POST['reference_no'] ?? 'DIVPMT' . time();
        $notes = $_POST['notes'] ?? '';

        $conn = getConnection();
        $conn->begin_transaction();

        try {
            // Insert payment record
            $sql = "INSERT INTO dividend_payments (dividend_id, payment_date, amount_paid, payment_method, reference_no, notes, paid_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("isdsssi", $dividend_id, $payment_date, $amount_paid, $payment_method, $reference_no, $notes, getCurrentUserId());
            $stmt->execute();

            // Update dividend status
            $update_sql = "UPDATE dividends SET status = 'paid', payment_date = ?, payment_method = ?, payment_reference = ?, paid_by = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("sssii", $payment_date, $payment_method, $reference_no, getCurrentUserId(), $dividend_id);
            $update_stmt->execute();

            $conn->commit();
            $_SESSION['success'] = 'Dividend payment recorded successfully';
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = 'Failed to record payment: ' . $e->getMessage();
        }

        $conn->close();
        header('Location: payments.php');
        exit();
    }
}

// Get all dividends with payment status
$dividends_sql = "SELECT d.*, m.member_no, m.full_name,
                 dp.id as payment_id, dp.payment_date as paid_date, dp.reference_no as payment_ref
                 FROM dividends d
                 JOIN members m ON d.member_id = m.id
                 LEFT JOIN dividend_payments dp ON d.id = dp.dividend_id
                 ORDER BY d.financial_year DESC, m.full_name ASC";
$dividends = executeQuery($dividends_sql);

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Dividend Payments</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Dividends</a></li>
                <li class="breadcrumb-item active">Payments</li>
            </ul>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<?php
$total_paid = executeQuery("SELECT COALESCE(SUM(amount_paid), 0) as total FROM dividend_payments")->fetch_assoc()['total'];
$pending_count = executeQuery("SELECT COUNT(*) as count FROM dividends WHERE status != 'paid'")->fetch_assoc()['count'];
$paid_count = executeQuery("SELECT COUNT(*) as count FROM dividends WHERE status = 'paid'")->fetch_assoc()['count'];
?>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="stats-card success">
            <div class="stats-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo $paid_count; ?></h3>
                <p>Paid Dividends</p>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="stats-card warning">
            <div class="stats-icon">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo $pending_count; ?></h3>
                <p>Pending Payments</p>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="stats-card primary">
            <div class="stats-icon">
                <i class="fas fa-money-bill-wave"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($total_paid); ?></h3>
                <p>Total Paid Out</p>
            </div>
        </div>
    </div>
</div>

<!-- Dividends Table -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title">Dividend Payments</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered datatable">
                <thead>
                    <tr>
                        <th>Year</th>
                        <th>Member</th>
                        <th>Member No</th>
                        <th>Net Dividend</th>
                        <th>Status</th>
                        <th>Payment Date</th>
                        <th>Reference</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($div = $dividends->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo $div['financial_year']; ?></td>
                            <td><?php echo $div['full_name']; ?></td>
                            <td><?php echo $div['member_no']; ?></td>
                            <td class="fw-bold"><?php echo formatCurrency($div['net_dividend']); ?></td>
                            <td>
                                <?php if ($div['status'] == 'paid'): ?>
                                    <span class="badge bg-success">Paid</span>
                                <?php elseif ($div['status'] == 'approved'): ?>
                                    <span class="badge bg-primary">Approved</span>
                                <?php else: ?>
                                    <span class="badge bg-warning">Calculated</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $div['paid_date'] ? formatDate($div['paid_date']) : '-'; ?></td>
                            <td><?php echo $div['payment_ref'] ?: '-'; ?></td>
                            <td>
                                <a href="voucher.php?id=<?php echo $div['id']; ?>" class="btn btn-sm btn-info" title="View Voucher">
                                    <i class="fas fa-file-invoice"></i>
                                </a>
                                <?php if ($div['status'] != 'paid'): ?>
                                    <button type="button" class="btn btn-sm btn-success" onclick="recordPayment(<?php echo $div['id']; ?>, <?php echo $div['net_dividend']; ?>)" title="Record Payment">
                                        <i class="fas fa-money-bill-wave"></i>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Record Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">Record Dividend Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="record_payment">
                    <input type="hidden" name="dividend_id" id="dividend_id">

                    <div class="mb-3">
                        <label>Amount to Pay</label>
                        <input type="text" class="form-control" id="display_amount" readonly>
                        <input type="hidden" name="amount_paid" id="amount_paid">
                    </div>

                    <div class="mb-3">
                        <label>Payment Date</label>
                        <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label>Payment Method</label>
                        <select name="payment_method" class="form-control" required>
                            <option value="bank">Bank Transfer</option>
                            <option value="cash">Cash</option>
                            <option value="mpesa">M-Pesa</option>
                            <option value="cheque">Cheque</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label>Reference Number</label>
                        <input type="text" name="reference_no" class="form-control" value="DIVPMT<?php echo time(); ?>">
                    </div>

                    <div class="mb-3">
                        <label>Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
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

<script>
    function recordPayment(id, amount) {
        document.getElementById('dividend_id').value = id;
        document.getElementById('display_amount').value = formatCurrency(amount);
        document.getElementById('amount_paid').value = amount;

        var modal = new bootstrap.Modal(document.getElementById('paymentModal'));
        modal.show();
    }

    function formatCurrency(amount) {
        return 'KES ' + parseFloat(amount).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
</script>

<?php include '../../includes/footer.php'; ?>
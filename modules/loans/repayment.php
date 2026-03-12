<?php
require_once '../../config/config.php';
requireLogin();

$loan_id = $_GET['loan_id'] ?? 0;

// Get loan details
$sql = "SELECT l.*, m.full_name, m.member_no, m.phone
        FROM loans l 
        JOIN members m ON l.member_id = m.id 
        WHERE l.id = ? AND l.status IN ('disbursed', 'active')";
$result = executeQuery($sql, "i", [$loan_id]);

if ($result->num_rows == 0) {
    $_SESSION['error'] = 'Loan not found or not active';
    header('Location: index.php');
    exit();
}

$loan = $result->fetch_assoc();

// Get amortization schedule
$schedule = executeQuery("
    SELECT * FROM amortization_schedule 
    WHERE loan_id = ? AND status != 'paid'
    ORDER BY installment_no
", "i", [$loan_id]);

// Get next pending installment
$next = executeQuery("
    SELECT * FROM amortization_schedule 
    WHERE loan_id = ? AND status = 'pending'
    ORDER BY installment_no
    LIMIT 1
", "i", [$loan_id]);
$next_payment = $next->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $amount_paid = $_POST['amount_paid'];
    $payment_date = $_POST['payment_date'];
    $payment_method = $_POST['payment_method'];
    $reference_no = $_POST['reference_no'];

    // Calculate distribution
    $remaining = $amount_paid;
    $total_principal = 0;
    $total_interest = 0;

    // Get all pending installments
    $installments = executeQuery("
        SELECT * FROM amortization_schedule 
        WHERE loan_id = ? AND status = 'pending'
        ORDER BY installment_no
    ", "i", [$loan_id]);

    while ($row = $installments->fetch_assoc() && $remaining > 0) {
        $amount_due = $row['total_payment'];

        if ($remaining >= $amount_due) {
            // Pay full installment
            $total_principal += $row['principal'];
            $total_interest += $row['interest'];
            $remaining -= $amount_due;

            // Mark as paid
            executeQuery("
                UPDATE amortization_schedule 
                SET status = 'paid', paid_date = ? 
                WHERE id = ?
            ", "si", [$payment_date, $row['id']]);
        } else {
            // Partial payment - handle according to rules
            // For simplicity, we'll apply to principal first
            $total_principal += $remaining;
            $remaining = 0;

            // Update remaining principal in schedule
            $new_principal = $row['principal'] - $remaining;
            // Recalculate schedule... (complex)
        }
    }

    // Record repayment
    $repayment_sql = "INSERT INTO loan_repayments (loan_id, payment_date, amount_paid, principal_paid, interest_paid, balance, payment_method, reference_no, created_by)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

    executeQuery($repayment_sql, "isdddsssi", [
        $loan_id,
        $payment_date,
        $amount_paid,
        $total_principal,
        $total_interest,
        $loan['total_amount'] - ($amount_paid - $total_interest), // Simplified balance
        $payment_method,
        $reference_no,
        getCurrentUserId()
    ]);

    // Check if loan is fully paid
    $remaining_check = executeQuery("
        SELECT COUNT(*) as pending FROM amortization_schedule 
        WHERE loan_id = ? AND status != 'paid'
    ", "i", [$loan_id]);
    $pending = $remaining_check->fetch_assoc();

    if ($pending['pending'] == 0) {
        executeQuery("UPDATE loans SET status = 'completed' WHERE id = ?", "i", [$loan_id]);
    }

    logAudit('PAYMENT', 'loan_repayments', $loan_id, null, $_POST);

    // Send receipt
    $message = "Dear {$loan['full_name']}, we have received your loan payment of " . formatCurrency($amount_paid) . ". Thank you.";
    sendNotification($loan['member_id'], 'Payment Received', $message, 'sms');

    $_SESSION['success'] = 'Payment recorded successfully';
    header('Location: view.php?id=' . $loan_id);
    exit();
}

$page_title = 'Loan Repayment - ' . $loan['loan_no'];

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Loan Repayment</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Loans</a></li>
                <li class="breadcrumb-item active">Repayment</li>
            </ul>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <!-- Repayment Form -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title">Record Payment</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" class="needs-validation" novalidate>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="amount_paid" class="form-label">Amount Paid <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="amount_paid" name="amount_paid"
                                min="0" step="100" required
                                value="<?php echo $next_payment['total_payment'] ?? ''; ?>">
                            <div class="invalid-feedback">Please enter amount paid</div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="payment_date" class="form-label">Payment Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="payment_date" name="payment_date"
                                value="<?php echo date('Y-m-d'); ?>" required>
                            <div class="invalid-feedback">Please select payment date</div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="payment_method" class="form-label">Payment Method <span class="text-danger">*</span></label>
                            <select class="form-control" id="payment_method" name="payment_method" required>
                                <option value="">-- Select Method --</option>
                                <option value="cash">Cash</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="mpesa">M-Pesa</option>
                                <option value="cheque">Cheque</option>
                            </select>
                            <div class="invalid-feedback">Please select payment method</div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="reference_no" class="form-label">Reference Number</label>
                            <input type="text" class="form-control" id="reference_no" name="reference_no">
                            <small class="text-muted">Transaction ID, Cheque number, etc.</small>
                        </div>
                    </div>

                    <hr>

                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save me-2"></i>Record Payment
                    </button>
                    <a href="view.php?id=<?php echo $loan_id; ?>" class="btn btn-secondary">
                        <i class="fas fa-times me-2"></i>Cancel
                    </a>
                </form>
            </div>
        </div>

        <!-- Amortization Schedule -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Repayment Schedule</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Due Date</th>
                                <th>Principal</th>
                                <th>Interest</th>
                                <th>Total</th>
                                <th>Balance</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $schedule->data_seek(0);
                            while ($row = $schedule->fetch_assoc()):
                            ?>
                                <tr class="<?php echo $row['status'] == 'paid' ? 'table-success' : ($row['due_date'] < date('Y-m-d') ? 'table-danger' : ''); ?>">
                                    <td><?php echo $row['installment_no']; ?></td>
                                    <td><?php echo formatDate($row['due_date']); ?></td>
                                    <td><?php echo formatCurrency($row['principal']); ?></td>
                                    <td><?php echo formatCurrency($row['interest']); ?></td>
                                    <td><?php echo formatCurrency($row['total_payment']); ?></td>
                                    <td><?php echo formatCurrency($row['balance']); ?></td>
                                    <td>
                                        <?php if ($row['status'] == 'paid'): ?>
                                            <span class="badge bg-success">Paid</span>
                                        <?php elseif ($row['due_date'] < date('Y-m-d')): ?>
                                            <span class="badge bg-danger">Overdue</span>
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
    </div>

    <div class="col-md-4">
        <!-- Loan Summary -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title">Loan Summary</h5>
            </div>
            <div class="card-body">
                <p><strong>Loan No:</strong> <?php echo $loan['loan_no']; ?></p>
                <p><strong>Member:</strong> <?php echo $loan['full_name']; ?></p>
                <p><strong>Principal:</strong> <?php echo formatCurrency($loan['principal_amount']); ?></p>
                <p><strong>Total Repayable:</strong> <?php echo formatCurrency($loan['total_amount']); ?></p>
                <p><strong>Paid to Date:</strong> <?php echo formatCurrency($loan['total_amount'] - $loan['balance']); ?></p>
                <p><strong>Balance:</strong> <?php echo formatCurrency($loan['balance']); ?></p>
            </div>
        </div>

        <!-- Next Payment -->
        <?php if ($next_payment): ?>
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">Next Payment Due</h5>
                </div>
                <div class="card-body">
                    <h3 class="text-primary"><?php echo formatCurrency($next_payment['total_payment']); ?></h3>
                    <p>Due Date: <?php echo formatDate($next_payment['due_date']); ?></p>
                    <?php if ($next_payment['due_date'] < date('Y-m-d')): ?>
                        <span class="badge bg-danger">Overdue</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
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
</script>

<?php include '../../includes/footer.php'; ?>
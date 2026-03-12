<?php
require_once '../../config/config.php';
requireRole('admin');

$id = $_GET['id'] ?? 0;

// Get loan details
$sql = "SELECT l.*, m.full_name, m.member_no, m.phone, m.email
        FROM loans l 
        JOIN members m ON l.member_id = m.id 
        WHERE l.id = ? AND l.status = 'approved'";
$result = executeQuery($sql, "i", [$id]);

if ($result->num_rows == 0) {
    $_SESSION['error'] = 'Loan not found or not approved for disbursement';
    header('Location: index.php');
    exit();
}

$loan = $result->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $disbursement_method = $_POST['disbursement_method'];
    $reference_no = $_POST['reference_no'];
    $disbursement_date = $_POST['disbursement_date'];

    // Update loan status
    $updateSql = "UPDATE loans SET status = 'disbursed', disbursement_date = ? WHERE id = ?";
    executeQuery($updateSql, "si", [$disbursement_date, $id]);

    // Create transaction record
    $transaction_no = 'TXN' . time() . rand(100, 999);
    $sql = "INSERT INTO transactions (transaction_no, transaction_date, description, debit_account, credit_account, amount, reference_type, reference_id, created_by)
            VALUES (?, ?, ?, ?, ?, ?, 'loan', ?, ?)";

    executeQuery($sql, "sssssiii", [
        $transaction_no,
        $disbursement_date,
        'Loan disbursement - ' . $loan['loan_no'],
        'LOANS_RECEIVABLE',
        'CASH',
        $loan['principal_amount'],
        $id,
        getCurrentUserId()
    ]);

    logAudit('DISBURSE', 'loans', $id, null, $_POST);

    // Send notification (SMS/Email)
    $message = "Dear {$loan['full_name']}, your loan of " . formatCurrency($loan['principal_amount']) . " has been disbursed via {$disbursement_method}. Reference: {$reference_no}";
    sendNotification($loan['member_id'], 'Loan Disbursement', $message, 'sms');

    $_SESSION['success'] = 'Loan disbursed successfully';
    header('Location: index.php');
    exit();
}

$page_title = 'Disburse Loan - ' . $loan['loan_no'];

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Loan Disbursement</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Loans</a></li>
                <li class="breadcrumb-item active">Disburse Loan</li>
            </ul>
        </div>
    </div>
</div>

<!-- Disbursement Form -->
<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Disbursement Details</h5>
            </div>
            <div class="card-body">
                <div class="row mb-4">
                    <div class="col-md-6">
                        <p><strong>Loan Number:</strong> <?php echo $loan['loan_no']; ?></p>
                        <p><strong>Member:</strong> <?php echo $loan['full_name']; ?></p>
                        <p><strong>Member No:</strong> <?php echo $loan['member_no']; ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Principal Amount:</strong> <?php echo formatCurrency($loan['principal_amount']); ?></p>
                        <p><strong>Approval Date:</strong> <?php echo formatDate($loan['approval_date']); ?></p>
                        <p><strong>First Payment Date:</strong> <?php echo date('d M Y', strtotime('+1 month')); ?></p>
                    </div>
                </div>

                <form method="POST" action="" class="needs-validation" novalidate>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="disbursement_method" class="form-label">Disbursement Method <span class="text-danger">*</span></label>
                            <select class="form-control" id="disbursement_method" name="disbursement_method" required>
                                <option value="">-- Select Method --</option>
                                <option value="cash">Cash</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="mpesa">M-Pesa</option>
                                <option value="cheque">Cheque</option>
                            </select>
                            <div class="invalid-feedback">Please select disbursement method</div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="reference_no" class="form-label">Reference Number</label>
                            <input type="text" class="form-control" id="reference_no" name="reference_no">
                            <small class="text-muted">Transaction ID, Cheque number, etc.</small>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="disbursement_date" class="form-label">Disbursement Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="disbursement_date" name="disbursement_date"
                                value="<?php echo date('Y-m-d'); ?>" required>
                            <div class="invalid-feedback">Please select disbursement date</div>
                        </div>
                    </div>

                    <hr>

                    <div class="row">
                        <div class="col-12">
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-money-bill-wave me-2"></i>Confirm Disbursement
                            </button>
                            <a href="index.php" class="btn btn-secondary">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Disbursement Summary</h5>
            </div>
            <div class="card-body">
                <div class="text-center mb-3">
                    <i class="fas fa-check-circle text-success fa-4x"></i>
                </div>
                <h6 class="text-center mb-3">Ready for Disbursement</h6>

                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between">
                        <span>Amount:</span>
                        <strong><?php echo formatCurrency($loan['principal_amount']); ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span>Interest Rate:</span>
                        <strong><?php echo $loan['interest_rate']; ?>%</strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span>Duration:</span>
                        <strong><?php echo $loan['duration_months']; ?> months</strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span>Monthly Payment:</span>
                        <strong><?php echo formatCurrency($loan['total_amount'] / $loan['duration_months']); ?></strong>
                    </li>
                </ul>

                <div class="alert alert-info mt-3">
                    <i class="fas fa-info-circle me-2"></i>
                    First payment is due on <?php echo date('d M Y', strtotime('+1 month')); ?>
                </div>
            </div>
        </div>
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
<?php
require_once '../../config/config.php';
requireRole('admin');

$id = $_GET['id'] ?? 0;

// Get loan details
$sql = "SELECT l.*, m.full_name, m.member_no, m.date_joined, 
        lp.product_name, lp.interest_rate
        FROM loans l 
        JOIN members m ON l.member_id = m.id 
        JOIN loan_products lp ON l.product_id = lp.id 
        WHERE l.id = ?";
$result = executeQuery($sql, "i", [$id]);

if ($result->num_rows == 0) {
    $_SESSION['error'] = 'Loan not found';
    header('Location: index.php');
    exit();
}

$loan = $result->fetch_assoc();

// Check eligibility
$join_date = new DateTime($loan['date_joined']);
$now = new DateTime();
$membership_months = $join_date->diff($now)->m + ($join_date->diff($now)->y * 12);

// Check guarantors
$guarantors = executeQuery("
    SELECT COUNT(*) as count, SUM(guaranteed_amount) as total 
    FROM loan_guarantors 
    WHERE loan_id = ? AND status = 'approved'
", "i", [$id]);
$guarantor_data = $guarantors->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'];

    if ($action == 'approve') {
        $updateSql = "UPDATE loans SET status = 'approved', approval_date = CURDATE(), approved_by = ? WHERE id = ?";
        executeQuery($updateSql, "ii", [getCurrentUserId(), $id]);

        // Create amortization schedule
        createAmortizationSchedule($id, $loan['principal_amount'], $loan['interest_rate'], $loan['duration_months']);

        logAudit('APPROVE', 'loans', $id, null, $loan);
        $_SESSION['success'] = 'Loan approved successfully';
    } else {
        $updateSql = "UPDATE loans SET status = 'rejected' WHERE id = ?";
        executeQuery($updateSql, "i", [$id]);

        logAudit('REJECT', 'loans', $id, null, $loan);
        $_SESSION['success'] = 'Loan rejected';
    }

    header('Location: index.php');
    exit();
}

$page_title = 'Approve Loan - ' . $loan['loan_no'];

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Loan Approval</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Loans</a></li>
                <li class="breadcrumb-item active">Approve Loan</li>
            </ul>
        </div>
    </div>
</div>

<!-- Loan Details Card -->
<div class="row">
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title">Loan Application Details</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">Loan Number</label>
                        <p class="fw-bold"><?php echo $loan['loan_no']; ?></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">Product</label>
                        <p class="fw-bold"><?php echo $loan['product_name']; ?></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">Member</label>
                        <p class="fw-bold"><?php echo $loan['full_name']; ?></p>
                        <small class="text-muted"><?php echo $loan['member_no']; ?></small>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">Application Date</label>
                        <p class="fw-bold"><?php echo formatDate($loan['application_date']); ?></p>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label text-muted">Principal Amount</label>
                        <p class="fw-bold text-primary"><?php echo formatCurrency($loan['principal_amount']); ?></p>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label text-muted">Interest Rate</label>
                        <p class="fw-bold"><?php echo $loan['interest_rate']; ?>% p.a.</p>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label text-muted">Duration</label>
                        <p class="fw-bold"><?php echo $loan['duration_months']; ?> months</p>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label text-muted">Interest Amount</label>
                        <p class="fw-bold"><?php echo formatCurrency($loan['interest_amount']); ?></p>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label text-muted">Total Amount</label>
                        <p class="fw-bold"><?php echo formatCurrency($loan['total_amount']); ?></p>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label text-muted">Monthly Installment</label>
                        <p class="fw-bold"><?php echo formatCurrency($loan['total_amount'] / $loan['duration_months']); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Guarantors Card -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title">Guarantors</h5>
            </div>
            <div class="card-body">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Member No</th>
                            <th>Guaranteed Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $guarantor_list = executeQuery("
                            SELECT lg.*, m.full_name, m.member_no 
                            FROM loan_guarantors lg 
                            JOIN members m ON lg.guarantor_member_id = m.id 
                            WHERE lg.loan_id = ?
                        ", "i", [$id]);

                        while ($g = $guarantor_list->fetch_assoc()):
                        ?>
                            <tr>
                                <td><?php echo $g['full_name']; ?></td>
                                <td><?php echo $g['member_no']; ?></td>
                                <td><?php echo formatCurrency($g['guaranteed_amount']); ?></td>
                                <td>
                                    <?php if ($g['status'] == 'approved'): ?>
                                        <span class="badge bg-success">Approved</span>
                                    <?php elseif ($g['status'] == 'pending'): ?>
                                        <span class="badge bg-warning">Pending</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Rejected</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Eligibility Check Card -->
    <div class="col-md-4">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title">Eligibility Check</h5>
            </div>
            <div class="card-body">
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        Membership Duration
                        <span>
                            <?php echo $membership_months; ?> months
                            <?php if ($membership_months >= 6): ?>
                                <i class="fas fa-check-circle text-success ms-2"></i>
                            <?php else: ?>
                                <i class="fas fa-times-circle text-danger ms-2"></i>
                            <?php endif; ?>
                        </span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        Number of Guarantors
                        <span>
                            <?php echo $guarantor_data['count']; ?>/3
                            <?php if ($guarantor_data['count'] >= 3): ?>
                                <i class="fas fa-check-circle text-success ms-2"></i>
                            <?php else: ?>
                                <i class="fas fa-times-circle text-danger ms-2"></i>
                            <?php endif; ?>
                        </span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        Guarantor Coverage
                        <span>
                            <?php echo formatCurrency($guarantor_data['total']); ?>
                            <?php if ($guarantor_data['total'] >= $loan['principal_amount']): ?>
                                <i class="fas fa-check-circle text-success ms-2"></i>
                            <?php else: ?>
                                <i class="fas fa-times-circle text-danger ms-2"></i>
                            <?php endif; ?>
                        </span>
                    </li>
                </ul>

                <hr>

                <?php if ($membership_months >= 6 && $guarantor_data['count'] >= 3 && $guarantor_data['total'] >= $loan['principal_amount']): ?>
                    <form method="POST" action="" onsubmit="return confirmAction('Are you sure you want to approve this loan?')">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="btn btn-success w-100 mb-2">
                            <i class="fas fa-check-circle me-2"></i>Approve Loan
                        </button>
                    </form>

                    <form method="POST" action="" onsubmit="return confirmAction('Are you sure you want to reject this loan?')">
                        <input type="hidden" name="action" value="reject">
                        <button type="submit" class="btn btn-danger w-100">
                            <i class="fas fa-times-circle me-2"></i>Reject Loan
                        </button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        This loan does not meet all eligibility criteria.
                    </div>
                    <a href="index.php" class="btn btn-secondary w-100">
                        <i class="fas fa-arrow-left me-2"></i>Back to Loans
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
// Function to create amortization schedule
function createAmortizationSchedule($loan_id, $principal, $rate, $months)
{
    $monthly_rate = $rate / 100 / 12;
    $monthly_payment = ($principal * $monthly_rate * pow(1 + $monthly_rate, $months)) / (pow(1 + $monthly_rate, $months) - 1);

    $balance = $principal;
    $due_date = new DateTime();
    $due_date->modify('+1 month');

    for ($i = 1; $i <= $months; $i++) {
        $interest = $balance * $monthly_rate;
        $principal_paid = $monthly_payment - $interest;
        $balance -= $principal_paid;

        if ($balance < 0) $balance = 0;

        $sql = "INSERT INTO amortization_schedule (loan_id, installment_no, due_date, principal, interest, total_payment, balance, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')";

        executeQuery($sql, "iisdddd", [
            $loan_id,
            $i,
            $due_date->format('Y-m-d'),
            $principal_paid,
            $interest,
            $monthly_payment,
            $balance
        ]);

        $due_date->modify('+1 month');
    }
}
?>

<?php include '../../includes/footer.php'; ?>
<?php
require_once '../../config/config.php';
requireLogin();

$page_title = 'Loan Application';

// Get loan products
$products = executeQuery("SELECT * FROM loan_products WHERE status = 1");

// Get members for select2
$members = executeQuery("SELECT id, member_no, full_name FROM members WHERE membership_status = 'active' ORDER BY full_name");

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $member_id = $_POST['member_id'];
    $product_id = $_POST['product_id'];
    $principal = $_POST['principal'];
    $duration = $_POST['duration'];

    // Get product details
    $productResult = executeQuery("SELECT * FROM loan_products WHERE id = ?", "i", [$product_id]);
    $product = $productResult->fetch_assoc();

    // Calculate interest
    $interest = $principal * ($product['interest_rate'] / 100) * ($duration / 12);
    $total = $principal + $interest;

    // Generate loan number
    $loan_no = generateLoanNumber();

    // Insert loan
    $sql = "INSERT INTO loans (loan_no, member_id, product_id, principal_amount, interest_amount, total_amount, 
            duration_months, interest_rate, application_date, status, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 'guarantor_pending', ?)";

    $loan_id = insertAndGetId($sql, "siidddiii", [
        $loan_no,
        $member_id,
        $product_id,
        $principal,
        $interest,
        $total,
        $duration,
        $product['interest_rate'],
        getCurrentUserId()
    ]);

    if ($loan_id) {
        logAudit('INSERT', 'loans', $loan_id, null, $_POST);
        $_SESSION['success'] = 'Loan application submitted successfully. Please add guarantors.';
        header('Location: guarantors.php?loan_id=' . $loan_id);
        exit();
    } else {
        $_SESSION['error'] = 'Failed to submit loan application';
    }
}

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Loan Application</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Loans</a></li>
                <li class="breadcrumb-item active">Apply</li>
            </ul>
        </div>
    </div>
</div>

<!-- Alert Messages -->
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

<!-- Loan Application Form -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title">Loan Application Form</h5>
    </div>
    <div class="card-body">
        <form method="POST" action="" class="needs-validation" novalidate id="loanForm">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="member_id" class="form-label">Select Member <span class="text-danger">*</span></label>
                    <select class="form-control select2" id="member_id" name="member_id" required>
                        <option value="">-- Select Member --</option>
                        <?php while ($member = $members->fetch_assoc()): ?>
                            <option value="<?php echo $member['id']; ?>">
                                <?php echo $member['full_name']; ?> (<?php echo $member['member_no']; ?>)
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <div class="invalid-feedback">Please select a member</div>
                </div>

                <div class="col-md-6 mb-3">
                    <label for="product_id" class="form-label">Loan Product <span class="text-danger">*</span></label>
                    <select class="form-control" id="product_id" name="product_id" required onchange="updateProductDetails()">
                        <option value="">-- Select Product --</option>
                        <?php while ($product = $products->fetch_assoc()): ?>
                            <option value="<?php echo $product['id']; ?>"
                                data-rate="<?php echo $product['interest_rate']; ?>"
                                data-max="<?php echo $product['max_amount']; ?>"
                                data-min="<?php echo $product['min_amount']; ?>"
                                data-duration="<?php echo $product['max_duration_months']; ?>">
                                <?php echo $product['product_name']; ?> (<?php echo $product['interest_rate']; ?>% p.a.)
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <div class="invalid-feedback">Please select a loan product</div>
                </div>

                <div class="col-md-6 mb-3">
                    <label for="principal" class="form-label">Loan Amount (KES) <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" id="principal" name="principal"
                        min="0" step="1000" required onkeyup="calculateLoan()" onchange="calculateLoan()">
                    <div class="invalid-feedback">Please enter loan amount</div>
                    <small class="text-muted" id="amountRange"></small>
                </div>

                <div class="col-md-6 mb-3">
                    <label for="duration" class="form-label">Duration (Months) <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" id="duration" name="duration"
                        min="1" max="36" required onkeyup="calculateLoan()" onchange="calculateLoan()">
                    <div class="invalid-feedback">Please enter loan duration</div>
                    <small class="text-muted" id="durationRange"></small>
                </div>
            </div>

            <!-- Loan Calculation Summary -->
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6 class="card-title">Loan Summary</h6>
                            <div class="row">
                                <div class="col-md-3">
                                    <p class="mb-1">Principal Amount:</p>
                                    <h5 id="displayPrincipal">KES 0.00</h5>
                                </div>
                                <div class="col-md-3">
                                    <p class="mb-1">Interest (Estimate):</p>
                                    <h5 id="displayInterest">KES 0.00</h5>
                                </div>
                                <div class="col-md-3">
                                    <p class="mb-1">Total Repayable:</p>
                                    <h5 id="displayTotal">KES 0.00</h5>
                                </div>
                                <div class="col-md-3">
                                    <p class="mb-1">Monthly Installment:</p>
                                    <h5 id="displayMonthly">KES 0.00</h5>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <hr>

            <div class="row">
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane me-2"></i>Submit Application
                    </button>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-times me-2"></i>Cancel
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    // Update product details when product is selected
    function updateProductDetails() {
        const select = document.getElementById('product_id');
        const option = select.options[select.selectedIndex];

        if (option.value) {
            const minAmount = option.dataset.min;
            const maxAmount = option.dataset.max;
            const maxDuration = option.dataset.duration;

            document.getElementById('amountRange').innerHTML =
                `Range: KES ${parseFloat(minAmount).toLocaleString()} - ${parseFloat(maxAmount).toLocaleString()}`;
            document.getElementById('durationRange').innerHTML =
                `Maximum duration: ${maxDuration} months`;

            document.getElementById('principal').min = minAmount;
            document.getElementById('principal').max = maxAmount;
            document.getElementById('duration').max = maxDuration;
        }

        calculateLoan();
    }

    // Calculate loan amounts
    function calculateLoan() {
        const principal = parseFloat(document.getElementById('principal').value) || 0;
        const duration = parseFloat(document.getElementById('duration').value) || 0;
        const select = document.getElementById('product_id');
        const option = select.options[select.selectedIndex];

        if (principal > 0 && duration > 0 && option.value) {
            const rate = parseFloat(option.dataset.rate) || 0;
            const interest = principal * (rate / 100) * (duration / 12);
            const total = principal + interest;
            const monthly = total / duration;

            document.getElementById('displayPrincipal').innerHTML = `KES ${principal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
            document.getElementById('displayInterest').innerHTML = `KES ${interest.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
            document.getElementById('displayTotal').innerHTML = `KES ${total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
            document.getElementById('displayMonthly').innerHTML = `KES ${monthly.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
        }
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

    // Initialize Select2
    $(document).ready(function() {
        $('.select2').select2({
            theme: 'bootstrap-5',
            width: '100%'
        });
    });
</script>

<?php include '../../includes/footer.php'; ?>
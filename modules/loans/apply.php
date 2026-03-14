<?php
// modules/loans/apply.php
require_once '../../config/config.php';
requireLogin();

$page_title = 'Loan Application';

// Get system settings
$settings = getLoanSettings();

// Get loan products
$products = executeQuery("SELECT * FROM loan_products WHERE status = 1 ORDER BY product_name");

// Get members for select2
$members = executeQuery("SELECT id, member_no, full_name, date_joined,
                        (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE -amount END), 0) 
                         FROM deposits WHERE member_id = members.id) as current_balance
                        FROM members WHERE membership_status = 'active' ORDER BY full_name");

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $member_id = $_POST['member_id'];
    $product_id = $_POST['product_id'];
    $principal = $_POST['principal'];
    $duration = $_POST['duration'];

    // Get product details
    $productResult = executeQuery("SELECT * FROM loan_products WHERE id = ?", "i", [$product_id]);
    $product = $productResult->fetch_assoc();

    // Check eligibility
    $member = executeQuery("SELECT * FROM members WHERE id = ?", "i", [$member_id])->fetch_assoc();
    $eligibility = checkLoanEligibility($member_id, $principal, $product, $settings, $duration);

    if (!$eligibility['eligible']) {
        $_SESSION['error'] = 'Loan application failed: ' . implode(', ', $eligibility['reasons']);
        header('Location: apply.php');
        exit();
    }

    // Calculate interest based on product settings
    $interest = calculateLoanInterest($principal, $product['interest_rate'], $duration, $product);
    $total = $principal + $interest;

    // Calculate fees
    $processing_fee = calculateProcessingFee($principal, $product, $settings);
    $insurance_fee = calculateInsuranceFee($principal, $product, $settings);
    $total_fees = $processing_fee + $insurance_fee;

    // Generate loan number
    $loan_no = generateLoanNumber();

    // Determine if loan requires approval
    $requires_approval = ($principal > ($settings['auto_approve_threshold'] ?? 0)) ? 1 : 0;
    $initial_status = $requires_approval ? 'pending' : 'approved';

    $conn = getConnection();
    $conn->begin_transaction();

    try {
        // Insert loan
        $sql = "INSERT INTO loans (loan_no, member_id, product_id, principal_amount, interest_amount, total_amount, 
                duration_months, interest_rate, application_date, status, created_by, processing_fee, insurance_fee) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "siidddiisidd",
            $loan_no,
            $member_id,
            $product_id,
            $principal,
            $interest,
            $total,
            $duration,
            $product['interest_rate'],
            $initial_status,
            getCurrentUserId(),
            $processing_fee,
            $insurance_fee
        );
        $stmt->execute();
        $loan_id = $conn->insert_id;

        // Create fee transactions if applicable
        if ($processing_fee > 0) {
            recordLoanFee($conn, $loan_id, $member_id, 'processing_fee', $processing_fee, $principal);
        }

        if ($insurance_fee > 0) {
            recordLoanFee($conn, $loan_id, $member_id, 'insurance_fee', $insurance_fee, $principal);
        }

        // Auto-approve if below threshold
        if (!$requires_approval && ($settings['auto_approve_threshold'] ?? 0) > 0) {
            // Auto-create amortization schedule
            createAmortizationSchedule($conn, $loan_id, $principal, $product['interest_rate'], $duration);

            // Notify member
            $message = "Dear {$member['full_name']}, your loan of " . formatCurrency($principal) . " has been automatically approved.";
            sendNotification($member_id, 'Loan Auto-Approved', $message, 'sms');
        }

        $conn->commit();

        logAudit('INSERT', 'loans', $loan_id, null, $_POST);

        $status_message = $requires_approval ?
            "Loan application submitted successfully. It will be reviewed by an officer." :
            "Loan approved automatically. You can proceed with disbursement.";

        $_SESSION['success'] = $status_message;

        if ($requires_approval) {
            header('Location: index.php');
        } else {
            header('Location: view.php?id=' . $loan_id);
        }
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Failed to submit loan application: ' . $e->getMessage();
        header('Location: apply.php');
        exit();
    }
}

function getLoanSettings()
{
    $settings = [];
    $result = executeQuery("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'loan_%' OR setting_key LIKE '%guarantor%' OR setting_key LIKE '%interest%'");
    while ($row = $result->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    return $settings;
}

function checkLoanEligibility($member_id, $principal, $product, $settings, $duration)
{
    $eligible = true;
    $reasons = [];

    // Get member details
    $member = executeQuery("SELECT * FROM members WHERE id = ?", "i", [$member_id])->fetch_assoc();

    // Check membership duration
    $join_date = new DateTime($member['date_joined']);
    $now = new DateTime();
    $membership_months = $join_date->diff($now)->m + ($join_date->diff($now)->y * 12);

    $min_months = $product['membership_min_months'] ?? ($settings['membership_min_months'] ?? 6);
    if ($membership_months < $min_months) {
        $eligible = false;
        $reasons[] = "Membership duration of $membership_months months is less than required $min_months months";
    }

    // Check savings balance
    $savings = executeQuery("SELECT COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE -amount END), 0) as balance 
                            FROM deposits WHERE member_id = ?", "i", [$member_id])->fetch_assoc()['balance'];

    $min_savings = $product['min_savings_balance'] ?? ($settings['min_savings_balance'] ?? 0);
    if ($savings < $min_savings) {
        $eligible = false;
        $reasons[] = "Savings balance of " . formatCurrency($savings) . " is less than required " . formatCurrency($min_savings);
    }

    // Check active loans
    $active_loans = executeQuery("SELECT COUNT(*) as count FROM loans WHERE member_id = ? AND status IN ('active', 'disbursed')", "i", [$member_id])->fetch_assoc()['count'];
    $max_active = $product['max_loans_active'] ?? ($settings['max_loans_active'] ?? 1);
    if ($active_loans >= $max_active) {
        $eligible = false;
        $reasons[] = "Member already has $active_loans active loan(s) (maximum $max_active)";
    }

    // Check loan amount limits
    if ($principal < ($product['min_amount'] ?? 0)) {
        $eligible = false;
        $reasons[] = "Loan amount below minimum of " . formatCurrency($product['min_amount'] ?? 0);
    }

    if ($principal > ($product['max_amount'] ?? 999999999)) {
        $eligible = false;
        $reasons[] = "Loan amount exceeds maximum of " . formatCurrency($product['max_amount'] ?? 0);
    }

    // Check duration limits
    if ($duration < ($product['min_duration'] ?? 1)) {
        $eligible = false;
        $reasons[] = "Duration below minimum of {$product['min_duration']} months";
    }

    if ($duration > ($product['max_duration'] ?? 36)) {
        $eligible = false;
        $reasons[] = "Duration exceeds maximum of {$product['max_duration']} months";
    }

    return ['eligible' => $eligible, 'reasons' => $reasons];
}

function calculateLoanInterest($principal, $rate, $duration, $product)
{
    $interest_type = $product['interest_type'] ?? 'fixed';
    $calculation = $product['interest_calculation'] ?? 'flat';

    if ($calculation == 'flat') {
        return $principal * ($rate / 100) * ($duration / 12);
    } elseif ($calculation == 'monthly') {
        // Monthly reducing balance
        $monthly_rate = $rate / 100 / 12;
        return $principal * $monthly_rate * $duration;
    } else {
        // Default to flat rate
        return $principal * ($rate / 100) * ($duration / 12);
    }
}

function calculateProcessingFee($principal, $product, $settings)
{
    $fee = 0;
    $fee_rate = $product['processing_fee'] ?? ($settings['default_processing_fee'] ?? 0);
    $fee_type = $product['processing_fee_type'] ?? 'percentage';

    if ($fee_type == 'percentage') {
        $fee = $principal * ($fee_rate / 100);
    } else {
        $fee = $fee_rate;
    }

    return round($fee, 2);
}

function calculateInsuranceFee($principal, $product, $settings)
{
    $fee = 0;
    $fee_rate = $product['insurance_fee'] ?? ($settings['default_insurance_fee'] ?? 0);
    $fee_type = $product['insurance_fee_type'] ?? 'percentage';

    if ($fee_type == 'percentage') {
        $fee = $principal * ($fee_rate / 100);
    } else {
        $fee = $fee_rate;
    }

    return round($fee, 2);
}

function recordLoanFee($conn, $loan_id, $member_id, $fee_type, $amount, $principal)
{
    $reference_no = strtoupper(substr($fee_type, 0, 3)) . $loan_id . time();
    $description = ucfirst(str_replace('_', ' ', $fee_type)) . " for loan #$loan_id";

    $sql = "INSERT INTO admin_charges (member_id, charge_type, amount, charge_date, description, reference_no, loan_id, status, created_by)
            VALUES (?, ?, ?, CURDATE(), ?, ?, ?, 'pending', ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isdssii", $member_id, $fee_type, $amount, $description, $reference_no, $loan_id, getCurrentUserId());
    $stmt->execute();
}

function createAmortizationSchedule($conn, $loan_id, $principal, $rate, $months)
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
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iisdddd", $loan_id, $i, $due_date->format('Y-m-d'), $principal_paid, $interest, $monthly_payment, $balance);
        $stmt->execute();

        $due_date->modify('+1 month');
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
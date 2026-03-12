<?php
require_once '../../config/config.php';
requireLogin();

$page_title = 'Register New Member';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $member_no = generateMemberNumber();
    $full_name = $_POST['full_name'];
    $national_id = $_POST['national_id'];
    $phone = $_POST['phone'];
    $email = $_POST['email'];
    $address = $_POST['address'];
    $date_joined = $_POST['date_joined'];

    // Registration fees
    $registration_fee = 2000; // Fixed amount
    $bylaws_fee = 400; // Fixed amount
    $total_fees = $registration_fee + $bylaws_fee;

    // Payment details
    $payment_method = $_POST['payment_method'];
    $receipt_no = $_POST['receipt_no'] ?? 'REG' . time();
    $amount_paid = $_POST['amount_paid'];

    // Validate payment
    if ($amount_paid < $total_fees) {
        $_SESSION['error'] = "Total fees required: KES " . number_format($total_fees) . ". You paid KES " . number_format($amount_paid);
        header('Location: register.php');
        exit();
    }

    // Check if national ID already exists
    $checkSql = "SELECT id FROM members WHERE national_id = ?";
    $checkResult = executeQuery($checkSql, "s", [$national_id]);

    if ($checkResult->num_rows > 0) {
        $_SESSION['error'] = 'National ID already exists';
    } else {
        // Start transaction
        $conn = getConnection();
        $conn->begin_transaction();

        try {
            // Insert member
            $sql = "INSERT INTO members (member_no, full_name, national_id, phone, email, address, date_joined, 
                    membership_status, registration_fee_paid, bylaws_fee_paid, registration_date, registration_receipt_no, 
                    total_share_contributions, full_shares_issued, partial_share_balance, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, 0, 0, 0, ?)";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                "sssssssddssi",
                $member_no,
                $full_name,
                $national_id,
                $phone,
                $email,
                $address,
                $date_joined,
                $registration_fee,
                $bylaws_fee,
                $date_joined,
                $receipt_no,
                getCurrentUserId()
            );
            $stmt->execute();
            $member_id = $stmt->insert_id;

            // Create transaction for registration fee
            $trans_no1 = 'REG' . time() . rand(100, 999);
            $trans_sql1 = "INSERT INTO transactions (transaction_no, transaction_date, description, debit_account, credit_account, amount, reference_type, reference_id, created_by)
                          VALUES (?, ?, ?, 'REGISTRATION_INCOME', 'CASH', ?, 'registration', ?, ?)";
            $stmt1 = $conn->prepare($trans_sql1);
            $desc1 = "Membership registration fee - {$full_name}";
            $stmt1->bind_param("sssiii", $trans_no1, $date_joined, $desc1, $registration_fee, $member_id, getCurrentUserId());
            $stmt1->execute();

            // Create transaction for bylaws fee
            $trans_no2 = 'BYL' . time() . rand(100, 999);
            $trans_sql2 = "INSERT INTO transactions (transaction_no, transaction_date, description, debit_account, credit_account, amount, reference_type, reference_id, created_by)
                          VALUES (?, ?, ?, 'BYLAWS_INCOME', 'CASH', ?, 'bylaws', ?, ?)";
            $stmt2 = $conn->prepare($trans_sql2);
            $desc2 = "Bylaws purchase - {$full_name}";
            $stmt2->bind_param("sssiii", $trans_no2, $date_joined, $desc2, $bylaws_fee, $member_id, getCurrentUserId());
            $stmt2->execute();

            // If there's excess payment (share contribution)
            $excess = $amount_paid - $total_fees;
            if ($excess > 0) {
                // Add to share contributions
                $contribution_sql = "INSERT INTO share_contributions (member_id, amount, contribution_date, reference_no, payment_method, created_by)
                                    VALUES (?, ?, ?, ?, ?, ?)";
                $stmt3 = $conn->prepare($contribution_sql);
                $stmt3->bind_param("idsssi", $member_id, $excess, $date_joined, $receipt_no, $payment_method, getCurrentUserId());
                $stmt3->execute();

                // Update member share contributions
                $update_sql = "UPDATE members SET total_share_contributions = total_share_contributions + ? WHERE id = ?";
                $stmt4 = $conn->prepare($update_sql);
                $stmt4->bind_param("di", $excess, $member_id);
                $stmt4->execute();

                // Check if this completes a full share
                checkAndIssueShares($conn, $member_id);
            }

            $conn->commit();

            logAudit('INSERT', 'members', $member_id, null, $_POST);
            $_SESSION['success'] = "Member registered successfully! Registration Fee: KES 2,000, Bylaws: KES 400";
            if ($excess > 0) {
                $_SESSION['success'] .= " Share contribution: KES " . number_format($excess);
            }
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Registration failed: " . $e->getMessage();
        }

        $conn->close();
        header('Location: index.php');
        exit();
    }
}

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Register New Member</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Members</a></li>
                <li class="breadcrumb-item active">Register</li>
            </ul>
        </div>
    </div>
</div>

<!-- Registration Form -->
<div class="card">
    <div class="card-header bg-primary text-white">
        <h5 class="card-title mb-0">Member Registration & Fees Payment</h5>
    </div>
    <div class="card-body">
        <form method="POST" action="" class="needs-validation" novalidate id="registrationForm">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="full_name" name="full_name" required>
                    <div class="invalid-feedback">Please enter full name</div>
                </div>

                <div class="col-md-6 mb-3">
                    <label for="national_id" class="form-label">National ID <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="national_id" name="national_id" required>
                    <div class="invalid-feedback">Please enter national ID</div>
                </div>

                <div class="col-md-6 mb-3">
                    <label for="phone" class="form-label">Phone Number <span class="text-danger">*</span></label>
                    <input type="tel" class="form-control" id="phone" name="phone" required>
                    <div class="invalid-feedback">Please enter phone number</div>
                </div>

                <div class="col-md-6 mb-3">
                    <label for="email" class="form-label">Email Address</label>
                    <input type="email" class="form-control" id="email" name="email">
                </div>

                <div class="col-md-6 mb-3">
                    <label for="date_joined" class="form-label">Registration Date <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" id="date_joined" name="date_joined"
                        value="<?php echo date('Y-m-d'); ?>" required>
                    <div class="invalid-feedback">Please select registration date</div>
                </div>

                <div class="col-md-12 mb-3">
                    <label for="address" class="form-label">Address</label>
                    <textarea class="form-control" id="address" name="address" rows="2"></textarea>
                </div>
            </div>

            <hr class="my-4">

            <h5 class="mb-3">Registration Fees</h5>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6>Registration Fee</h6>
                            <h4 class="text-primary">KES 2,000</h4>
                            <small>One-time membership fee</small>
                        </div>
                    </div>
                </div>

                <div class="col-md-4 mb-3">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6>Bylaws Fee</h6>
                            <h4 class="text-info">KES 400</h4>
                            <small>SACCO bylaws document</small>
                        </div>
                    </div>
                </div>

                <div class="col-md-4 mb-3">
                    <div class="card bg-success text-white">
                        <div class="card-body">
                            <h6>Total Required</h6>
                            <h4 id="totalRequired">KES 2,400</h4>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mt-3">
                <div class="col-md-6 mb-3">
                    <label for="payment_method" class="form-label">Payment Method <span class="text-danger">*</span></label>
                    <select class="form-control" id="payment_method" name="payment_method" required>
                        <option value="">-- Select Payment Method --</option>
                        <option value="cash">Cash</option>
                        <option value="bank">Bank Transfer</option>
                        <option value="mpesa">M-Pesa</option>
                        <option value="mobile">Mobile Money</option>
                    </select>
                    <div class="invalid-feedback">Please select payment method</div>
                </div>

                <div class="col-md-6 mb-3">
                    <label for="receipt_no" class="form-label">Receipt/Reference Number</label>
                    <input type="text" class="form-control" id="receipt_no" name="receipt_no"
                        value="REG<?php echo time(); ?>" readonly>
                    <small class="text-muted">Auto-generated, can be changed</small>
                </div>

                <div class="col-md-6 mb-3">
                    <label for="amount_paid" class="form-label">Amount Paid (KES) <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" id="amount_paid" name="amount_paid"
                        min="2400" step="100" value="2400" required onchange="calculateExcess()">
                    <div class="invalid-feedback">Please enter amount paid (minimum KES 2,400)</div>
                </div>

                <div class="col-md-6 mb-3">
                    <div class="card" id="excessCard" style="display: none;">
                        <div class="card-body bg-info text-white">
                            <h6>Excess Payment (Share Contribution)</h6>
                            <h4 id="excessAmount">KES 0</h4>
                            <small>This will be recorded as share contribution</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                <strong>Share Information:</strong> 1 share = KES 10,000. You can contribute any amount towards shares.
                When you reach KES 10,000, a full share will be issued with a certificate.
            </div>

            <hr>

            <div class="row">
                <div class="col-12">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-save me-2"></i>Register Member & Process Payment
                    </button>
                    <a href="index.php" class="btn btn-secondary btn-lg">
                        <i class="fas fa-times me-2"></i>Cancel
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    function calculateExcess() {
        var totalRequired = 2400;
        var amountPaid = parseFloat(document.getElementById('amount_paid').value) || 0;
        var excess = amountPaid - totalRequired;

        if (excess > 0) {
            document.getElementById('excessCard').style.display = 'block';
            document.getElementById('excessAmount').innerHTML = 'KES ' + excess.toLocaleString();
        } else {
            document.getElementById('excessCard').style.display = 'none';
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

    // Auto-calculate on page load
    document.addEventListener('DOMContentLoaded', function() {
        calculateExcess();
    });
</script>

<?php include '../../includes/footer.php'; ?>
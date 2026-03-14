<?php
// modules/settings/loan-products.php
require_once '../../config/config.php';
requireRole('admin');

$page_title = 'Loan Products Management';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action == 'add_product') {
        addLoanProduct();
    } elseif ($action == 'edit_product') {
        editLoanProduct();
    } elseif ($action == 'delete_product') {
        deleteLoanProduct();
    } elseif ($action == 'toggle_status') {
        toggleProductStatus();
    }
}

function addLoanProduct()
{
    $conn = getConnection();
    $conn->begin_transaction();

    try {
        $product_name = $_POST['product_name'];
        $product_code = $_POST['product_code'];
        $description = $_POST['description'] ?? '';
        $interest_rate = floatval($_POST['interest_rate']);
        $interest_type = $_POST['interest_type'] ?? 'fixed';
        $interest_calculation = $_POST['interest_calculation'] ?? 'flat';
        $min_amount = floatval($_POST['min_amount'] ?? 0);
        $max_amount = floatval($_POST['max_amount'] ?? 0);
        $min_duration = intval($_POST['min_duration'] ?? 1);
        $max_duration = intval($_POST['max_duration'] ?? 12);
        $duration_unit = $_POST['duration_unit'] ?? 'months';
        $repayment_frequency = $_POST['repayment_frequency'] ?? 'monthly';
        $grace_period = intval($_POST['grace_period'] ?? 0);
        $late_payment_penalty = floatval($_POST['late_payment_penalty'] ?? 0);
        $penalty_type = $_POST['penalty_type'] ?? 'percentage';
        $processing_fee = floatval($_POST['processing_fee'] ?? 0);
        $processing_fee_type = $_POST['processing_fee_type'] ?? 'percentage';
        $insurance_fee = floatval($_POST['insurance_fee'] ?? 0);
        $insurance_fee_type = $_POST['insurance_fee_type'] ?? 'percentage';
        $guarantor_required = intval($_POST['guarantor_required'] ?? 1);
        $min_guarantors = intval($_POST['min_guarantors'] ?? 1);
        $max_guarantors = intval($_POST['max_guarantors'] ?? 3);
        $guarantor_coverage = floatval($_POST['guarantor_coverage'] ?? 100);
        $membership_min_months = intval($_POST['membership_min_months'] ?? 6);
        $min_savings_balance = floatval($_POST['min_savings_balance'] ?? 0);
        $min_shares_value = floatval($_POST['min_shares_value'] ?? 0);
        $max_loans_active = intval($_POST['max_loans_active'] ?? 1);
        $allow_topup = isset($_POST['allow_topup']) ? 1 : 0;
        $allow_restructuring = isset($_POST['allow_restructuring']) ? 1 : 0;
        $allow_early_repayment = isset($_POST['allow_early_repayment']) ? 1 : 0;
        $early_repayment_penalty = floatval($_POST['early_repayment_penalty'] ?? 0);
        $collateral_required = isset($_POST['collateral_required']) ? 1 : 0;
        $collateral_type = $_POST['collateral_type'] ?? '';
        $status = isset($_POST['status']) ? 1 : 0;
        $created_by = getCurrentUserId();

        // Check if product code already exists
        $check_sql = "SELECT id FROM loan_products WHERE product_code = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $product_code);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            throw new Exception("Product code already exists");
        }

        // Insert loan product - NO COMMENTS IN SQL
        $insert_sql = "INSERT INTO loan_products 
                      (product_name, product_code, description, interest_rate, interest_type, 
                       interest_calculation, min_amount, max_amount, min_duration, max_duration, 
                       duration_unit, repayment_frequency, grace_period, late_payment_penalty, 
                       penalty_type, processing_fee, processing_fee_type, insurance_fee, 
                       insurance_fee_type, guarantor_required, min_guarantors, max_guarantors, 
                       guarantor_coverage, membership_min_months, min_savings_balance, 
                       min_shares_value, max_loans_active, allow_topup, allow_restructuring, 
                       allow_early_repayment, early_repayment_penalty, collateral_required, 
                       collateral_type, status, created_by, created_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 
                              ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $insert_stmt = $conn->prepare($insert_sql);

        if (!$insert_stmt) {
            throw new Exception("Failed to prepare insert statement: " . $conn->error);
        }

        // Count parameters to verify
        $param_count = substr_count($insert_sql, '?');

        // Build type string for parameters
        $type_string = "sssddsdddsssiddsiddiiiddiiiiiiiiisii";

        $insert_stmt->bind_param(
            $type_string,
            $product_name,
            $product_code,
            $description,
            $interest_rate,
            $interest_type,
            $interest_calculation,
            $min_amount,
            $max_amount,
            $min_duration,
            $max_duration,
            $duration_unit,
            $repayment_frequency,
            $grace_period,
            $late_payment_penalty,
            $penalty_type,
            $processing_fee,
            $processing_fee_type,
            $insurance_fee,
            $insurance_fee_type,
            $guarantor_required,
            $min_guarantors,
            $max_guarantors,
            $guarantor_coverage,
            $membership_min_months,
            $min_savings_balance,
            $min_shares_value,
            $max_loans_active,
            $allow_topup,
            $allow_restructuring,
            $allow_early_repayment,
            $early_repayment_penalty,
            $collateral_required,
            $collateral_type,
            $status,
            $created_by
        );

        $insert_stmt->execute();

        $conn->commit();
        $_SESSION['success'] = "Loan product '{$product_name}' added successfully.";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Failed to add loan product: ' . $e->getMessage();
        error_log("Add loan product error: " . $e->getMessage());
    }

    $conn->close();
    header('Location: loan-products.php');
    exit();
}
function editLoanProduct()
{
    $conn = getConnection();
    $conn->begin_transaction();

    try {
        // Get all POST data with defaults
        $product_id = intval($_POST['product_id'] ?? 0);
        $product_name = $_POST['product_name'] ?? '';
        $product_code = $_POST['product_code'] ?? '';
        $description = $_POST['description'] ?? '';
        $interest_rate = floatval($_POST['interest_rate'] ?? 0);
        $interest_type = $_POST['interest_type'] ?? 'fixed';
        $interest_calculation = $_POST['interest_calculation'] ?? 'flat';
        $min_amount = floatval($_POST['min_amount'] ?? 0);
        $max_amount = floatval($_POST['max_amount'] ?? 0);
        $min_duration = intval($_POST['min_duration'] ?? 1);
        $max_duration = intval($_POST['max_duration'] ?? 12);
        $duration_unit = $_POST['duration_unit'] ?? 'months';
        $repayment_frequency = $_POST['repayment_frequency'] ?? 'monthly';
        $grace_period = intval($_POST['grace_period'] ?? 0);
        $late_payment_penalty = floatval($_POST['late_payment_penalty'] ?? 0);
        $penalty_type = $_POST['penalty_type'] ?? 'percentage';
        $processing_fee = floatval($_POST['processing_fee'] ?? 0);
        $processing_fee_type = $_POST['processing_fee_type'] ?? 'percentage';
        $insurance_fee = floatval($_POST['insurance_fee'] ?? 0);
        $insurance_fee_type = $_POST['insurance_fee_type'] ?? 'percentage';
        $guarantor_required = isset($_POST['guarantor_required']) ? 1 : 0;
        $min_guarantors = intval($_POST['min_guarantors'] ?? 1);
        $max_guarantors = intval($_POST['max_guarantors'] ?? 3);
        $guarantor_coverage = floatval($_POST['guarantor_coverage'] ?? 100);
        $membership_min_months = intval($_POST['membership_min_months'] ?? 6);
        $min_savings_balance = floatval($_POST['min_savings_balance'] ?? 0);
        $min_shares_value = floatval($_POST['min_shares_value'] ?? 0);
        $max_loans_active = intval($_POST['max_loans_active'] ?? 1);
        $allow_topup = isset($_POST['allow_topup']) ? 1 : 0;
        $allow_restructuring = isset($_POST['allow_restructuring']) ? 1 : 0;
        $allow_early_repayment = isset($_POST['allow_early_repayment']) ? 1 : 0;
        $early_repayment_penalty = floatval($_POST['early_repayment_penalty'] ?? 0);
        $collateral_required = isset($_POST['collateral_required']) ? 1 : 0;
        $collateral_type = $_POST['collateral_type'] ?? '';
        $status = isset($_POST['status']) ? 1 : 0;

        // Validate required fields
        if (empty($product_name) || empty($product_code)) {
            throw new Exception("Product name and code are required");
        }

        // Check if product code already exists for another product
        $check_sql = "SELECT id FROM loan_products WHERE product_code = ? AND id != ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("si", $product_code, $product_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            throw new Exception("Product code already exists for another product");
        }

        // Update loan product
        $update_sql = "UPDATE loan_products SET 
                      product_name = ?, 
                      product_code = ?, 
                      description = ?, 
                      interest_rate = ?, 
                      interest_type = ?, 
                      interest_calculation = ?,
                      min_amount = ?, 
                      max_amount = ?, 
                      min_duration = ?, 
                      max_duration = ?,
                      duration_unit = ?, 
                      repayment_frequency = ?, 
                      grace_period = ?,
                      late_payment_penalty = ?, 
                      penalty_type = ?, 
                      processing_fee = ?,
                      processing_fee_type = ?, 
                      insurance_fee = ?, 
                      insurance_fee_type = ?,
                      guarantor_required = ?, 
                      min_guarantors = ?, 
                      max_guarantors = ?,
                      guarantor_coverage = ?, 
                      membership_min_months = ?, 
                      min_savings_balance = ?, 
                      min_shares_value = ?, 
                      max_loans_active = ?,
                      allow_topup = ?, 
                      allow_restructuring = ?, 
                      allow_early_repayment = ?,
                      early_repayment_penalty = ?, 
                      collateral_required = ?, 
                      collateral_type = ?,
                      status = ?, 
                      updated_at = NOW()
                      WHERE id = ?";

        $update_stmt = $conn->prepare($update_sql);

        if (!$update_stmt) {
            throw new Exception("Failed to prepare statement: " . $conn->error);
        }

        // Count parameters to verify
        $param_count = substr_count($update_sql, '?');

        // Build type string character by character
        $types = "";
        $types .= "s"; // 1: product_name
        $types .= "s"; // 2: product_code
        $types .= "s"; // 3: description
        $types .= "d"; // 4: interest_rate
        $types .= "s"; // 5: interest_type
        $types .= "s"; // 6: interest_calculation
        $types .= "d"; // 7: min_amount
        $types .= "d"; // 8: max_amount
        $types .= "i"; // 9: min_duration
        $types .= "i"; // 10: max_duration
        $types .= "s"; // 11: duration_unit
        $types .= "s"; // 12: repayment_frequency
        $types .= "i"; // 13: grace_period
        $types .= "d"; // 14: late_payment_penalty
        $types .= "s"; // 15: penalty_type
        $types .= "d"; // 16: processing_fee
        $types .= "s"; // 17: processing_fee_type
        $types .= "d"; // 18: insurance_fee
        $types .= "s"; // 19: insurance_fee_type
        $types .= "i"; // 20: guarantor_required
        $types .= "i"; // 21: min_guarantors
        $types .= "i"; // 22: max_guarantors
        $types .= "d"; // 23: guarantor_coverage
        $types .= "i"; // 24: membership_min_months
        $types .= "d"; // 25: min_savings_balance
        $types .= "d"; // 26: min_shares_value
        $types .= "i"; // 27: max_loans_active
        $types .= "i"; // 28: allow_topup
        $types .= "i"; // 29: allow_restructuring
        $types .= "i"; // 30: allow_early_repayment
        $types .= "d"; // 31: early_repayment_penalty
        $types .= "i"; // 32: collateral_required
        $types .= "s"; // 33: collateral_type
        $types .= "i"; // 34: status
        $types .= "i"; // 35: id (WHERE clause)

        // Verify count
        if (strlen($types) != $param_count) {
            throw new Exception("Type string length mismatch. Expected: $param_count, Got: " . strlen($types));
        }

        // Bind parameters
        $bind_result = $update_stmt->bind_param(
            $types,
            $product_name,
            $product_code,
            $description,
            $interest_rate,
            $interest_type,
            $interest_calculation,
            $min_amount,
            $max_amount,
            $min_duration,
            $max_duration,
            $duration_unit,
            $repayment_frequency,
            $grace_period,
            $late_payment_penalty,
            $penalty_type,
            $processing_fee,
            $processing_fee_type,
            $insurance_fee,
            $insurance_fee_type,
            $guarantor_required,
            $min_guarantors,
            $max_guarantors,
            $guarantor_coverage,
            $membership_min_months,
            $min_savings_balance,
            $min_shares_value,
            $max_loans_active,
            $allow_topup,
            $allow_restructuring,
            $allow_early_repayment,
            $early_repayment_penalty,
            $collateral_required,
            $collateral_type,
            $status,
            $product_id
        );

        if (!$bind_result) {
            throw new Exception("Bind param failed: " . $update_stmt->error);
        }

        $execute_result = $update_stmt->execute();

        if (!$execute_result) {
            throw new Exception("Execute failed: " . $update_stmt->error);
        }

        $conn->commit();
        $_SESSION['success'] = "Loan product '{$product_name}' updated successfully.";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Failed to update loan product: ' . $e->getMessage();
        error_log("Edit loan product error: " . $e->getMessage());
    }

    $conn->close();
    header('Location: loan-products.php');
    exit();
}

function deleteLoanProduct()
{
    $conn = getConnection();
    $conn->begin_transaction();

    try {
        $product_id = intval($_POST['product_id']);

        // Check if product is used in any loans
        $check_sql = "SELECT COUNT(*) as count FROM loans WHERE product_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $product_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $count = $check_result->fetch_assoc()['count'];

        if ($count > 0) {
            throw new Exception("Cannot delete product that has {$count} loan(s) associated. Deactivate instead.");
        }

        // Delete product
        $delete_sql = "DELETE FROM loan_products WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $product_id);
        $delete_stmt->execute();

        $conn->commit();
        $_SESSION['success'] = "Loan product deleted successfully.";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Failed to delete loan product: ' . $e->getMessage();
        error_log("Delete loan product error: " . $e->getMessage());
    }

    $conn->close();
    header('Location: loan-products.php');
    exit();
}

function toggleProductStatus()
{
    $conn = getConnection();

    try {
        $product_id = intval($_POST['product_id']);
        $current_status = intval($_POST['current_status']);
        $new_status = $current_status ? 0 : 1;

        $update_sql = "UPDATE loan_products SET status = ? WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("ii", $new_status, $product_id);
        $update_stmt->execute();

        $status_text = $new_status ? 'activated' : 'deactivated';
        $_SESSION['success'] = "Loan product {$status_text} successfully.";
    } catch (Exception $e) {
        $_SESSION['error'] = 'Failed to toggle product status: ' . $e->getMessage();
        error_log("Toggle product status error: " . $e->getMessage());
    }

    $conn->close();
    header('Location: loan-products.php');
    exit();
}

function updateLoanSettings()
{
    $conn = getConnection();

    try {
        $default_interest_rate = floatval($_POST['default_interest_rate']);
        $max_loan_amount = floatval($_POST['max_loan_amount']);
        $min_loan_amount = floatval($_POST['min_loan_amount']);
        $max_duration_months = intval($_POST['max_duration_months']);
        $min_duration_months = intval($_POST['min_duration_months']);
        $default_processing_fee = floatval($_POST['default_processing_fee']);
        $default_insurance_fee = floatval($_POST['default_insurance_fee']);
        $default_late_penalty = floatval($_POST['default_late_penalty']);
        $default_guarantor_required = isset($_POST['default_guarantor_required']) ? 1 : 0;
        $default_min_guarantors = intval($_POST['default_min_guarantors'] ?? 1);
        $auto_approve_threshold = floatval($_POST['auto_approve_threshold'] ?? 0);
        $require_approval_above = floatval($_POST['require_approval_above'] ?? 0);
        $enable_self_guarantee = isset($_POST['enable_self_guarantee']) ? 1 : 0;
        $self_guarantee_multiplier = floatval($_POST['self_guarantee_multiplier'] ?? 3);
        $allow_partial_guarantee = isset($_POST['allow_partial_guarantee']) ? 1 : 0;
        $enable_credit_scoring = isset($_POST['enable_credit_scoring']) ? 1 : 0;
        $min_credit_score = intval($_POST['min_credit_score'] ?? 0);

        // Update settings in database (assuming a settings table)
        $settings = [
            'default_interest_rate' => $default_interest_rate,
            'max_loan_amount' => $max_loan_amount,
            'min_loan_amount' => $min_loan_amount,
            'max_duration_months' => $max_duration_months,
            'min_duration_months' => $min_duration_months,
            'default_processing_fee' => $default_processing_fee,
            'default_insurance_fee' => $default_insurance_fee,
            'default_late_penalty' => $default_late_penalty,
            'default_guarantor_required' => $default_guarantor_required,
            'default_min_guarantors' => $default_min_guarantors,
            'auto_approve_threshold' => $auto_approve_threshold,
            'require_approval_above' => $require_approval_above,
            'enable_self_guarantee' => $enable_self_guarantee,
            'self_guarantee_multiplier' => $self_guarantee_multiplier,
            'allow_partial_guarantee' => $allow_partial_guarantee,
            'enable_credit_scoring' => $enable_credit_scoring,
            'min_credit_score' => $min_credit_score
        ];

        // Update each setting (implement based on your settings storage)
        foreach ($settings as $key => $value) {
            $update_sql = "UPDATE system_settings SET setting_value = ? WHERE setting_key = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("ss", $value, $key);
            $update_stmt->execute();

            if ($update_stmt->affected_rows == 0) {
                $insert_sql = "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)";
                $insert_stmt = $conn->prepare($insert_sql);
                $insert_stmt->bind_param("ss", $key, $value);
                $insert_stmt->execute();
            }
        }

        $_SESSION['success'] = "Loan settings updated successfully.";
    } catch (Exception $e) {
        $_SESSION['error'] = 'Failed to update loan settings: ' . $e->getMessage();
        error_log("Update loan settings error: " . $e->getMessage());
    }

    $conn->close();
    header('Location: loan-products.php?tab=settings');
    exit();
}

// Get all loan products
$products_sql = "SELECT * FROM loan_products ORDER BY 
                 CASE status WHEN 1 THEN 0 ELSE 1 END, 
                 product_name ASC";
$products = executeQuery($products_sql);

// Get product statistics
$stats_sql = "SELECT 
              COUNT(*) as total_products,
              SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as active_products,
              SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as inactive_products,
              MIN(min_amount) as min_loan_amount,
              MAX(max_amount) as max_loan_amount,
              AVG(interest_rate) as avg_interest_rate,
              COUNT(DISTINCT interest_type) as interest_types
              FROM loan_products";
$stats_result = executeQuery($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get usage statistics
$usage_sql = "SELECT 
              lp.id, lp.product_name,
              COUNT(l.id) as loan_count,
              SUM(CASE WHEN l.status = 'active' THEN 1 ELSE 0 END) as active_loans,
              SUM(CASE WHEN l.status = 'pending' THEN 1 ELSE 0 END) as pending_loans,
              COALESCE(SUM(l.principal_amount), 0) as total_disbursed
              FROM loan_products lp
              LEFT JOIN loans l ON lp.id = l.product_id
              GROUP BY lp.id
              ORDER BY loan_count DESC";
$usage = executeQuery($usage_sql);

// Get tab parameter
$tab = $_GET['tab'] ?? 'products';

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Loan Products Management</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Settings</a></li>
                <li class="breadcrumb-item active">Loan Products</li>
            </ul>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProductModal">
                <i class="fas fa-plus-circle me-2"></i>New Loan Product
            </button>
        </div>
    </div>
</div>

<!-- Alert Messages -->
<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?php echo $_SESSION['success']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>
        <?php echo $_SESSION['error']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-2">
        <div class="stats-card primary">
            <div class="stats-icon">
                <i class="fas fa-box"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo $stats['total_products'] ?? 0; ?></h3>
                <p>Total Products</p>
            </div>
        </div>
    </div>

    <div class="col-md-2">
        <div class="stats-card success">
            <div class="stats-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo $stats['active_products'] ?? 0; ?></h3>
                <p>Active</p>
            </div>
        </div>
    </div>

    <div class="col-md-2">
        <div class="stats-card warning">
            <div class="stats-icon">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo $stats['inactive_products'] ?? 0; ?></h3>
                <p>Inactive</p>
            </div>
        </div>
    </div>

    <div class="col-md-2">
        <div class="stats-card info">
            <div class="stats-icon">
                <i class="fas fa-percentage"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo number_format($stats['avg_interest_rate'] ?? 0, 2); ?>%</h3>
                <p>Avg Interest</p>
            </div>
        </div>
    </div>

    <div class="col-md-2">
        <div class="stats-card secondary">
            <div class="stats-icon">
                <i class="fas fa-coins"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($stats['min_loan_amount'] ?? 0); ?></h3>
                <p>Min Amount</p>
            </div>
        </div>
    </div>

    <div class="col-md-2">
        <div class="stats-card dark">
            <div class="stats-icon">
                <i class="fas fa-money-bill-wave"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($stats['max_loan_amount'] ?? 0); ?></h3>
                <p>Max Amount</p>
            </div>
        </div>
    </div>
</div>

<!-- Tab Navigation -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?php echo $tab == 'products' ? 'active' : ''; ?>" href="?tab=products">
            <i class="fas fa-list"></i> Loan Products
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab == 'usage' ? 'active' : ''; ?>" href="?tab=usage">
            <i class="fas fa-chart-bar"></i> Product Usage
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab == 'settings' ? 'active' : ''; ?>" href="?tab=settings">
            <i class="fas fa-cog"></i> General Settings
        </a>
    </li>
</ul>

<!-- Products Tab -->
<?php if ($tab == 'products'): ?>
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">Loan Products</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-striped datatable">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Product Name</th>
                            <th>Interest Rate</th>
                            <th>Amount Range</th>
                            <th>Duration</th>
                            <th>Fees</th>
                            <th>Guarantors</th>
                            <th>Requirements</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($product = $products->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-secondary"><?php echo $product['product_code']; ?></span>
                                </td>
                                <td>
                                    <strong><?php echo $product['product_name']; ?></strong>
                                    <br>
                                    <small class="text-muted"><?php echo substr($product['description'], 0, 50); ?>...</small>
                                </td>
                                <td>
                                    <span class="badge bg-info"><?php echo $product['interest_rate']; ?>%</span>
                                    <br>
                                    <small><?php echo ucfirst($product['interest_type']); ?></small>
                                </td>
                                <td>
                                    <?php echo formatCurrency($product['min_amount']); ?> -
                                    <?php echo formatCurrency($product['max_amount']); ?>
                                </td>
                                <td>
                                    <?php echo $product['min_duration']; ?> - <?php echo $product['max_duration']; ?>
                                    <?php echo $product['duration_unit']; ?>
                                </td>
                                <td>
                                    <?php if ($product['processing_fee'] > 0): ?>
                                        <span class="badge bg-warning">Processing: <?php echo $product['processing_fee']; ?><?php echo $product['processing_fee_type'] == 'percentage' ? '%' : ''; ?></span>
                                    <?php endif; ?>
                                    <?php if ($product['insurance_fee'] > 0): ?>
                                        <br><span class="badge bg-info">Insurance: <?php echo $product['insurance_fee']; ?><?php echo $product['insurance_fee_type'] == 'percentage' ? '%' : ''; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($product['guarantor_required']): ?>
                                        <span class="badge bg-success">Required</span>
                                        <br><small><?php echo $product['min_guarantors']; ?>-<?php echo $product['max_guarantors']; ?> persons</small>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Not Required</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small>
                                        Member: <?php echo $product['membership_min_months']; ?>+ months<br>
                                        Savings: <?php echo formatCurrency($product['min_savings_balance']); ?><br>
                                        Shares: <?php echo formatCurrency($product['min_shares_value']); ?>
                                    </small>
                                </td>
                                <td>
                                    <?php if ($product['status']): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <button type="button" class="btn btn-sm btn-info" onclick="viewProduct(<?php echo $product['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-primary" onclick="editProduct(<?php echo htmlspecialchars(json_encode($product)); ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if ($product['status']): ?>
                                            <button type="button" class="btn btn-sm btn-warning" onclick="toggleStatus(<?php echo $product['id']; ?>, <?php echo $product['status']; ?>)">
                                                <i class="fas fa-ban"></i>
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-success" onclick="toggleStatus(<?php echo $product['id']; ?>, <?php echo $product['status']; ?>)">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-sm btn-danger" onclick="confirmDelete(<?php echo $product['id']; ?>, '<?php echo $product['product_name']; ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Usage Tab -->
<?php if ($tab == 'usage'): ?>
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">Product Usage Statistics</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Product Name</th>
                            <th>Total Loans</th>
                            <th>Active Loans</th>
                            <th>Pending Loans</th>
                            <th>Total Disbursed</th>
                            <th>Average Loan</th>
                            <th>Utilization</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $total_loans_all = 0;
                        $total_disbursed_all = 0;
                        while ($stat = $usage->fetch_assoc()):
                            $total_loans_all += $stat['loan_count'];
                            $total_disbursed_all += $stat['total_disbursed'];
                            $avg_loan = $stat['loan_count'] > 0 ? $stat['total_disbursed'] / $stat['loan_count'] : 0;
                        ?>
                            <tr>
                                <td><strong><?php echo $stat['product_name']; ?></strong></td>
                                <td class="text-end"><?php echo number_format($stat['loan_count']); ?></td>
                                <td class="text-end"><?php echo number_format($stat['active_loans']); ?></td>
                                <td class="text-end"><?php echo number_format($stat['pending_loans']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($stat['total_disbursed']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($avg_loan); ?></td>
                                <td>
                                    <?php
                                    $utilization = $total_loans_all > 0 ? ($stat['loan_count'] / $total_loans_all) * 100 : 0;
                                    ?>
                                    <div class="progress" style="height: 20px;">
                                        <div class="progress-bar bg-success" role="progressbar"
                                            style="width: <?php echo $utilization; ?>%;"
                                            aria-valuenow="<?php echo $utilization; ?>"
                                            aria-valuemin="0"
                                            aria-valuemax="100">
                                            <?php echo number_format($utilization, 1); ?>%
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-info">
                            <th>Total</th>
                            <th class="text-end"><?php echo number_format($total_loans_all); ?></th>
                            <th colspan="3"></th>
                            <th class="text-end"><?php echo formatCurrency($total_disbursed_all); ?></th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Settings Tab -->
<?php if ($tab == 'settings'):
    // Get current settings
    $settings_sql = "SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'loan_%'";
    $settings_result = executeQuery($settings_sql);
    $settings = [];
    while ($row = $settings_result->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
?>
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">General Loan Settings</h5>
        </div>
        <div class="card-body">
            <form method="POST" action="" class="needs-validation" novalidate>
                <input type="hidden" name="action" value="update_settings">

                <div class="row">
                    <div class="col-md-6">
                        <h6 class="border-bottom pb-2">Interest & Amounts</h6>

                        <div class="mb-3">
                            <label for="default_interest_rate" class="form-label">Default Interest Rate (%)</label>
                            <input type="number" class="form-control" id="default_interest_rate" name="default_interest_rate"
                                value="<?php echo $settings['default_interest_rate'] ?? 12; ?>" step="0.1" min="0">
                        </div>

                        <div class="mb-3">
                            <label for="min_loan_amount" class="form-label">Minimum Loan Amount (KES)</label>
                            <input type="number" class="form-control" id="min_loan_amount" name="min_loan_amount"
                                value="<?php echo $settings['min_loan_amount'] ?? 1000; ?>" min="0">
                        </div>

                        <div class="mb-3">
                            <label for="max_loan_amount" class="form-label">Maximum Loan Amount (KES)</label>
                            <input type="number" class="form-control" id="max_loan_amount" name="max_loan_amount"
                                value="<?php echo $settings['max_loan_amount'] ?? 500000; ?>" min="0">
                        </div>

                        <div class="mb-3">
                            <label for="min_duration_months" class="form-label">Minimum Duration (Months)</label>
                            <input type="number" class="form-control" id="min_duration_months" name="min_duration_months"
                                value="<?php echo $settings['min_duration_months'] ?? 1; ?>" min="1">
                        </div>

                        <div class="mb-3">
                            <label for="max_duration_months" class="form-label">Maximum Duration (Months)</label>
                            <input type="number" class="form-control" id="max_duration_months" name="max_duration_months"
                                value="<?php echo $settings['max_duration_months'] ?? 36; ?>" min="1">
                        </div>
                    </div>

                    <div class="col-md-6">
                        <h6 class="border-bottom pb-2">Fees & Penalties</h6>

                        <div class="mb-3">
                            <label for="default_processing_fee" class="form-label">Default Processing Fee (%)</label>
                            <input type="number" class="form-control" id="default_processing_fee" name="default_processing_fee"
                                value="<?php echo $settings['default_processing_fee'] ?? 1; ?>" step="0.1" min="0">
                        </div>

                        <div class="mb-3">
                            <label for="default_insurance_fee" class="form-label">Default Insurance Fee (%)</label>
                            <input type="number" class="form-control" id="default_insurance_fee" name="default_insurance_fee"
                                value="<?php echo $settings['default_insurance_fee'] ?? 0.5; ?>" step="0.1" min="0">
                        </div>

                        <div class="mb-3">
                            <label for="default_late_penalty" class="form-label">Default Late Payment Penalty (KES/day)</label>
                            <input type="number" class="form-control" id="default_late_penalty" name="default_late_penalty"
                                value="<?php echo $settings['default_late_penalty'] ?? 50; ?>" min="0">
                        </div>
                    </div>
                </div>

                <div class="row mt-3">
                    <div class="col-md-6">
                        <h6 class="border-bottom pb-2">Guarantor Settings</h6>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="default_guarantor_required" name="default_guarantor_required" value="1"
                                <?php echo ($settings['default_guarantor_required'] ?? 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="default_guarantor_required">
                                Require Guarantors by Default
                            </label>
                        </div>

                        <div class="mb-3">
                            <label for="default_min_guarantors" class="form-label">Minimum Guarantors Required</label>
                            <input type="number" class="form-control" id="default_min_guarantors" name="default_min_guarantors"
                                value="<?php echo $settings['default_min_guarantors'] ?? 1; ?>" min="0" max="5">
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="enable_self_guarantee" name="enable_self_guarantee" value="1"
                                <?php echo ($settings['enable_self_guarantee'] ?? 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="enable_self_guarantee">
                                Enable Self-Guarantee (based on savings)
                            </label>
                        </div>

                        <div class="mb-3">
                            <label for="self_guarantee_multiplier" class="form-label">Self-Guarantee Multiplier</label>
                            <input type="number" class="form-control" id="self_guarantee_multiplier" name="self_guarantee_multiplier"
                                value="<?php echo $settings['self_guarantee_multiplier'] ?? 3; ?>" step="0.5" min="1">
                            <small class="text-muted">Loan amount allowed = Savings × multiplier</small>
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="allow_partial_guarantee" name="allow_partial_guarantee" value="1"
                                <?php echo ($settings['allow_partial_guarantee'] ?? 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="allow_partial_guarantee">
                                Allow Partial Guarantee (multiple guarantors)
                            </label>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <h6 class="border-bottom pb-2">Approval & Credit</h6>

                        <div class="mb-3">
                            <label for="auto_approve_threshold" class="form-label">Auto-Approve Threshold (KES)</label>
                            <input type="number" class="form-control" id="auto_approve_threshold" name="auto_approve_threshold"
                                value="<?php echo $settings['auto_approve_threshold'] ?? 0; ?>" min="0">
                            <small class="text-muted">Loans below this amount are auto-approved (0 = disabled)</small>
                        </div>

                        <div class="mb-3">
                            <label for="require_approval_above" class="form-label">Require Approval Above (KES)</label>
                            <input type="number" class="form-control" id="require_approval_above" name="require_approval_above"
                                value="<?php echo $settings['require_approval_above'] ?? 50000; ?>" min="0">
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="enable_credit_scoring" name="enable_credit_scoring" value="1"
                                <?php echo ($settings['enable_credit_scoring'] ?? 0) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="enable_credit_scoring">
                                Enable Credit Scoring
                            </label>
                        </div>

                        <div class="mb-3">
                            <label for="min_credit_score" class="form-label">Minimum Credit Score</label>
                            <input type="number" class="form-control" id="min_credit_score" name="min_credit_score"
                                value="<?php echo $settings['min_credit_score'] ?? 600; ?>" min="0" max="1000">
                        </div>
                    </div>
                </div>

                <hr>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Settings
                </button>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- Add Product Modal -->
<div class="modal fade" id="addProductModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Add Loan Product</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="" class="needs-validation" novalidate>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_product">

                    <ul class="nav nav-tabs mb-3" id="productTabs">
                        <li class="nav-item">
                            <a class="nav-link active" data-bs-toggle="tab" href="#basic">Basic Info</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-bs-toggle="tab" href="#financial">Financial</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-bs-toggle="tab" href="#fees">Fees & Penalties</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-bs-toggle="tab" href="#guarantor">Guarantor Rules</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-bs-toggle="tab" href="#eligibility">Eligibility</a>
                        </li>
                    </ul>

                    <div class="tab-content">
                        <!-- Basic Info Tab -->
                        <div class="tab-pane fade show active" id="basic">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="product_name" class="form-label">Product Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="product_name" name="product_name" required>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="product_code" class="form-label">Product Code <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="product_code" name="product_code" required>
                                </div>

                                <div class="col-12 mb-3">
                                    <label for="description" class="form-label">Description</label>
                                    <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="status" name="status" value="1" checked>
                                        <label class="form-check-label" for="status">
                                            Active (available for applications)
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Financial Tab -->
                        <div class="tab-pane fade" id="financial">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="interest_rate" class="form-label">Interest Rate (%) <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" id="interest_rate" name="interest_rate" step="0.1" min="0" required>
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label for="interest_type" class="form-label">Interest Type</label>
                                    <select class="form-control" id="interest_type" name="interest_type">
                                        <option value="fixed">Fixed</option>
                                        <option value="reducing">Reducing Balance</option>
                                        <option value="compound">Compound</option>
                                    </select>
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label for="interest_calculation" class="form-label">Calculation Method</label>
                                    <select class="form-control" id="interest_calculation" name="interest_calculation">
                                        <option value="flat">Flat Rate</option>
                                        <option value="monthly">Monthly Rest</option>
                                        <option value="annual">Annual Rest</option>
                                    </select>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="min_amount" class="form-label">Minimum Amount (KES)</label>
                                    <input type="number" class="form-control" id="min_amount" name="min_amount" min="0" value="0">
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label for="max_amount" class="form-label">Maximum Amount (KES)</label>
                                    <input type="number" class="form-control" id="max_amount" name="max_amount" min="0" value="0">
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label for="min_duration" class="form-label">Min Duration</label>
                                    <input type="number" class="form-control" id="min_duration" name="min_duration" min="1" value="1">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label for="max_duration" class="form-label">Max Duration</label>
                                    <input type="number" class="form-control" id="max_duration" name="max_duration" min="1" value="12">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label for="duration_unit" class="form-label">Duration Unit</label>
                                    <select class="form-control" id="duration_unit" name="duration_unit">
                                        <option value="months">Months</option>
                                        <option value="weeks">Weeks</option>
                                        <option value="years">Years</option>
                                    </select>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label for="repayment_frequency" class="form-label">Repayment Frequency</label>
                                    <select class="form-control" id="repayment_frequency" name="repayment_frequency">
                                        <option value="monthly">Monthly</option>
                                        <option value="weekly">Weekly</option>
                                        <option value="biweekly">Bi-Weekly</option>
                                        <option value="lump_sum">Lump Sum</option>
                                    </select>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="grace_period" class="form-label">Grace Period (days)</label>
                                    <input type="number" class="form-control" id="grace_period" name="grace_period" min="0" value="0">
                                </div>
                            </div>
                        </div>

                        <!-- Fees Tab -->
                        <div class="tab-pane fade" id="fees">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="processing_fee" class="form-label">Processing Fee</label>
                                    <input type="number" class="form-control" id="processing_fee" name="processing_fee" step="0.1" min="0" value="0">
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label for="processing_fee_type" class="form-label">Processing Fee Type</label>
                                    <select class="form-control" id="processing_fee_type" name="processing_fee_type">
                                        <option value="percentage">Percentage (%)</option>
                                        <option value="fixed">Fixed (KES)</option>
                                    </select>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="insurance_fee" class="form-label">Insurance Fee</label>
                                    <input type="number" class="form-control" id="insurance_fee" name="insurance_fee" step="0.1" min="0" value="0">
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label for="insurance_fee_type" class="form-label">Insurance Fee Type</label>
                                    <select class="form-control" id="insurance_fee_type" name="insurance_fee_type">
                                        <option value="percentage">Percentage (%)</option>
                                        <option value="fixed">Fixed (KES)</option>
                                    </select>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="late_payment_penalty" class="form-label">Late Payment Penalty</label>
                                    <input type="number" class="form-control" id="late_payment_penalty" name="late_payment_penalty" step="0.1" min="0" value="0">
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label for="penalty_type" class="form-label">Penalty Type</label>
                                    <select class="form-control" id="penalty_type" name="penalty_type">
                                        <option value="percentage">Percentage (%)</option>
                                        <option value="fixed">Fixed (KES)</option>
                                        <option value="per_day">Per Day (KES)</option>
                                    </select>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="allow_early_repayment" name="allow_early_repayment" value="1">
                                        <label class="form-check-label" for="allow_early_repayment">
                                            Allow Early Repayment
                                        </label>
                                    </div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="early_repayment_penalty" class="form-label">Early Repayment Penalty (%)</label>
                                    <input type="number" class="form-control" id="early_repayment_penalty" name="early_repayment_penalty" step="0.1" min="0" value="0">
                                </div>
                            </div>
                        </div>

                        <!-- Guarantor Tab -->
                        <div class="tab-pane fade" id="guarantor">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="guarantor_required" name="guarantor_required" value="1" checked>
                                        <label class="form-check-label" for="guarantor_required">
                                            Require Guarantors
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="min_guarantors" class="form-label">Minimum Guarantors</label>
                                    <input type="number" class="form-control" id="min_guarantors" name="min_guarantors" min="0" value="1">
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label for="max_guarantors" class="form-label">Maximum Guarantors</label>
                                    <input type="number" class="form-control" id="max_guarantors" name="max_guarantors" min="0" value="3">
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label for="guarantor_coverage" class="form-label">Guarantor Coverage (%)</label>
                                    <input type="number" class="form-control" id="guarantor_coverage" name="guarantor_coverage" min="0" max="100" value="100">
                                </div>
                            </div>
                        </div>

                        <!-- Eligibility Tab -->
                        <div class="tab-pane fade" id="eligibility">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="membership_min_months" class="form-label">Minimum Membership (months)</label>
                                    <input type="number" class="form-control" id="membership_min_months" name="membership_min_months" min="0" value="6">
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label for="min_savings_balance" class="form-label">Minimum Savings Balance (KES)</label>
                                    <input type="number" class="form-control" id="min_savings_balance" name="min_savings_balance" min="0" value="0">
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label for="min_shares_value" class="form-label">Minimum Shares Value (KES)</label>
                                    <input type="number" class="form-control" id="min_shares_value" name="min_shares_value" min="0" value="0">
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="max_loans_active" class="form-label">Maximum Active Loans</label>
                                    <input type="number" class="form-control" id="max_loans_active" name="max_loans_active" min="1" value="1">
                                </div>

                                <div class="col-md-4 mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="allow_topup" name="allow_topup" value="1">
                                        <label class="form-check-label" for="allow_topup">
                                            Allow Top-up
                                        </label>
                                    </div>
                                </div>

                                <div class="col-md-4 mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="allow_restructuring" name="allow_restructuring" value="1">
                                        <label class="form-check-label" for="allow_restructuring">
                                            Allow Restructuring
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="collateral_required" name="collateral_required" value="1">
                                        <label class="form-check-label" for="collateral_required">
                                            Require Collateral
                                        </label>
                                    </div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="collateral_type" class="form-label">Collateral Type</label>
                                    <input type="text" class="form-control" id="collateral_type" name="collateral_type" placeholder="e.g., Land, Vehicle, Shares">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Product</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Product Modal -->
<div class="modal fade" id="editProductModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Loan Product</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="" class="needs-validation" novalidate>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_product">
                    <input type="hidden" name="product_id" id="edit_product_id">

                    <!-- Same tabs as add modal, pre-filled with JavaScript -->
                    <div id="editFormContent"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Product</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Confirm Delete</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete <strong id="delete_product_name"></strong>?</p>
                <p class="text-danger"><i class="fas fa-exclamation-triangle"></i> This action cannot be undone if the product has no associated loans.</p>
            </div>
            <div class="modal-footer">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="delete_product">
                    <input type="hidden" name="product_id" id="delete_product_id">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Product</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Toggle Status Form (hidden) -->
<form id="toggleForm" method="POST" action="" style="display: none;">
    <input type="hidden" name="action" value="toggle_status">
    <input type="hidden" name="product_id" id="toggle_product_id">
    <input type="hidden" name="current_status" id="toggle_current_status">
</form>

<script>
    function editProduct(product) {
        // Populate edit modal with product data
        document.getElementById('edit_product_id').value = product.id;

        // Build edit form HTML (simplified - in production, you'd load this via AJAX or rebuild the tabs)
        var editHtml = `
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Product Name</label>
                <input type="text" class="form-control" name="product_name" value="${product.product_name}" required>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Product Code</label>
                <input type="text" class="form-control" name="product_code" value="${product.product_code}" required>
            </div>
            <div class="col-12 mb-3">
                <label class="form-label">Description</label>
                <textarea class="form-control" name="description" rows="3">${product.description || ''}</textarea>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Interest Rate (%)</label>
                <input type="number" class="form-control" name="interest_rate" step="0.1" value="${product.interest_rate}" required>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Min Amount</label>
                <input type="number" class="form-control" name="min_amount" value="${product.min_amount}">
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Max Amount</label>
                <input type="number" class="form-control" name="max_amount" value="${product.max_amount}">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Min Duration</label>
                <input type="number" class="form-control" name="min_duration" value="${product.min_duration}">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Max Duration</label>
                <input type="number" class="form-control" name="max_duration" value="${product.max_duration}">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Processing Fee</label>
                <input type="number" class="form-control" name="processing_fee" step="0.1" value="${product.processing_fee}">
            </div>
            <div class="col-md-3 mb-3">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="status" value="1" ${product.status ? 'checked' : ''}>
                    <label class="form-check-label">Active</label>
                </div>
            </div>
        </div>
        <input type="hidden" name="interest_type" value="${product.interest_type || 'fixed'}">
        <input type="hidden" name="interest_calculation" value="${product.interest_calculation || 'flat'}">
        <input type="hidden" name="duration_unit" value="${product.duration_unit || 'months'}">
        <input type="hidden" name="repayment_frequency" value="${product.repayment_frequency || 'monthly'}">
        <input type="hidden" name="grace_period" value="${product.grace_period || 0}">
        <input type="hidden" name="late_payment_penalty" value="${product.late_payment_penalty || 0}">
        <input type="hidden" name="penalty_type" value="${product.penalty_type || 'percentage'}">
        <input type="hidden" name="processing_fee_type" value="${product.processing_fee_type || 'percentage'}">
        <input type="hidden" name="insurance_fee" value="${product.insurance_fee || 0}">
        <input type="hidden" name="insurance_fee_type" value="${product.insurance_fee_type || 'percentage'}">
        <input type="hidden" name="guarantor_required" value="${product.guarantor_required || 1}">
        <input type="hidden" name="min_guarantors" value="${product.min_guarantors || 1}">
        <input type="hidden" name="max_guarantors" value="${product.max_guarantors || 3}">
        <input type="hidden" name="guarantor_coverage" value="${product.guarantor_coverage || 100}">
        <input type="hidden" name="membership_min_months" value="${product.membership_min_months || 6}">
        <input type="hidden" name="min_savings_balance" value="${product.min_savings_balance || 0}">
        <input type="hidden" name="min_shares_value" value="${product.min_shares_value || 0}">
        <input type="hidden" name="max_loans_active" value="${product.max_loans_active || 1}">
        <input type="hidden" name="allow_topup" value="${product.allow_topup || 0}">
        <input type="hidden" name="allow_restructuring" value="${product.allow_restructuring || 0}">
        <input type="hidden" name="allow_early_repayment" value="${product.allow_early_repayment || 0}">
        <input type="hidden" name="early_repayment_penalty" value="${product.early_repayment_penalty || 0}">
        <input type="hidden" name="collateral_required" value="${product.collateral_required || 0}">
        <input type="hidden" name="collateral_type" value="${product.collateral_type || ''}">
    `;

        document.getElementById('editFormContent').innerHTML = editHtml;

        var modal = new bootstrap.Modal(document.getElementById('editProductModal'));
        modal.show();
    }

    function viewProduct(id) {
        window.location.href = 'view-product.php?id=' + id;
    }

    function toggleStatus(id, currentStatus) {
        document.getElementById('toggle_product_id').value = id;
        document.getElementById('toggle_current_status').value = currentStatus;
        document.getElementById('toggleForm').submit();
    }

    function confirmDelete(id, name) {
        document.getElementById('delete_product_id').value = id;
        document.getElementById('delete_product_name').textContent = name;

        var modal = new bootstrap.Modal(document.getElementById('deleteModal'));
        modal.show();
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
</script>

<style>
    .stats-card.dark {
        background: linear-gradient(135deg, #343a40, #23272b);
    }

    .stats-card.dark .stats-icon {
        background: rgba(255, 255, 255, 0.2);
        color: white;
    }

    .stats-card.dark .stats-content h3,
    .stats-card.dark .stats-content p {
        color: white;
    }

    .nav-tabs .nav-link {
        font-weight: 500;
    }

    .nav-tabs .nav-link.active {
        border-bottom: 3px solid #007bff;
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
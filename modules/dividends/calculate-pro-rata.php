<?php
// modules/dividends/calculate-pro-rata.php
require_once '../../config/config.php';
requireRole('admin');

$page_title = 'Calculate Dividends (Pro-rata)';

// Get financial year
$current_year = date('Y');
$selected_year = $_GET['year'] ?? $current_year;

// Kenya SACCO standard interest rate (can be configured)
$default_interest_rate = 11.00; // 11% as per Kenya SACCO norms

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $financial_year = $_POST['financial_year'];
    $interest_rate = $_POST['interest_rate'];
    $withholding_tax_rate = 5; // 5% fixed as per Kenya law
    $calculation_date = $_POST['calculation_date'] ?? date('Y-m-d');
    $apply_penalty_deductions = isset($_POST['apply_penalty_deductions']) ? true : false;
    $minimum_balance_required = floatval($_POST['minimum_balance_required'] ?? 0);
    $qualifying_months = intval($_POST['qualifying_months'] ?? 6); // Minimum months to qualify

    // Check if dividends already calculated for this year
    $checkSql = "SELECT COUNT(*) as count FROM dividends WHERE financial_year = ?";
    $checkResult = executeQuery($checkSql, "s", [$financial_year]);
    $check = $checkResult->fetch_assoc();

    if ($check['count'] > 0) {
        $_SESSION['error'] = 'Dividends already calculated for this financial year';
        header('Location: index.php');
        exit();
    }

    $conn = getConnection();
    $conn->begin_transaction();

    try {
        // Set the financial year dates
        $year_start = $financial_year . '-01-01';
        $year_end = $financial_year . '-12-31';

        // Get all active members with their financial data
        $members_sql = "SELECT m.id, m.member_no, m.full_name, m.date_joined,
                       (SELECT COALESCE(SUM(CASE 
                            WHEN transaction_type = 'deposit' THEN amount 
                            WHEN transaction_type = 'withdrawal' THEN -amount 
                            ELSE 0 
                       END), 0) 
                        FROM deposits 
                        WHERE member_id = m.id AND deposit_date < ?) as opening_balance,
                       (SELECT COALESCE(SUM(CASE 
                            WHEN transaction_type = 'withdrawal' THEN amount 
                            ELSE 0 
                       END), 0) 
                        FROM deposits 
                        WHERE member_id = m.id AND deposit_date BETWEEN ? AND ?) as total_withdrawals,
                       (SELECT COALESCE(SUM(amount), 0)
                        FROM penalties 
                        WHERE member_id = m.id AND penalty_date BETWEEN ? AND ?) as total_penalties,
                       (SELECT COALESCE(SUM(amount), 0) 
                        FROM admin_charges 
                        WHERE member_id = m.id AND charge_date BETWEEN ? AND ?) as total_charges
                       FROM members m
                       WHERE m.membership_status = 'active' 
                       AND YEAR(m.date_joined) <= ?";

        $members = $conn->prepare($members_sql);

        if (!$members) {
            throw new Exception("Failed to prepare statement: " . $conn->error);
        }

        // Bind parameters - 8 placeholders total
        $members->bind_param(
            "ssssssss",
            $year_start,           // for opening_balance
            $year_start,
            $year_end, // for total_withdrawals
            $year_start,
            $year_end, // for total_penalties
            $year_start,
            $year_end, // for total_charges
            $financial_year        // for YEAR(date_joined) <= ?
        );

        $members->execute();
        $members_result = $members->get_result();

        if (!$members_result) {
            throw new Exception("Failed to execute query: " . $conn->error);
        }

        $total_dividend = 0;
        $eligible_count = 0;
        $excluded_count = 0;
        $dividend_details = [];

        while ($member = $members_result->fetch_assoc()) {
            // Calculate membership duration in months for the financial year
            $join_date = new DateTime($member['date_joined']);
            $year_start_date = new DateTime($year_start);
            $year_end_date = new DateTime($year_end);

            // Determine qualifying months
            if ($join_date > $year_end_date) {
                // Joined after year end - not eligible
                $excluded_count++;
                continue;
            }

            $membership_start = max($join_date, $year_start_date);
            $membership_end = $year_end_date;
            $membership_interval = $membership_start->diff($membership_end);
            $active_months = $membership_interval->m + ($membership_interval->y * 12) + 1;

            // Ensure we don't exceed 12 months
            $active_months = min($active_months, 12);

            // Check minimum qualifying period
            if ($active_months < $qualifying_months) {
                $excluded_count++;
                continue;
            }

            // Calculate opening balance after deductions
            $opening_balance = floatval($member['opening_balance']);
            $total_withdrawals = floatval($member['total_withdrawals']);
            $total_penalties = floatval($member['total_penalties']);
            $total_charges = floatval($member['total_charges']);

            // Adjusted opening balance (less withdrawals, penalties, charges)
            $adjusted_opening = $opening_balance - $total_withdrawals - $total_penalties - $total_charges;

            // Skip if below minimum balance requirement
            if ($adjusted_opening < $minimum_balance_required) {
                $excluded_count++;
                continue;
            }

            // Calculate interest on adjusted opening balance (full year)
            $opening_interest = $adjusted_opening * ($interest_rate / 100);

            // Get monthly contributions with weights
            $contributions_sql = "SELECT 
                                  MONTH(deposit_date) as month,
                                  SUM(amount) as total
                                  FROM deposits 
                                  WHERE member_id = ? 
                                  AND transaction_type = 'deposit'
                                  AND deposit_date BETWEEN ? AND ?
                                  GROUP BY MONTH(deposit_date)
                                  ORDER BY month ASC";

            $contributions = $conn->prepare($contributions_sql);
            $contributions->bind_param("iss", $member['id'], $year_start, $year_end);
            $contributions->execute();
            $contributions_result = $contributions->get_result();

            $weighted_sum = 0;
            $total_weight = 0;
            $monthly_breakdown = [];

            while ($contrib = $contributions_result->fetch_assoc()) {
                $month = $contrib['month'];
                $amount = $contrib['total'];

                // Calculate months remaining in the year after this month
                $months_remaining = 13 - $month; // Jan=12, Feb=11, ..., Dec=1
                $weight = $months_remaining / 12;

                $weighted_amount = $amount * $weight;
                $weighted_sum += $weighted_amount;
                $total_weight += $weight;

                $monthly_breakdown[] = [
                    'month' => $month,
                    'amount' => $amount,
                    'months_remaining' => $months_remaining,
                    'weight' => $weight,
                    'weighted_amount' => $weighted_amount
                ];
            }

            // Calculate average weighted balance
            $average_weighted_balance = $total_weight > 0 ? $weighted_sum / $total_weight : 0;

            // Calculate interest on weighted contributions
            $contribution_interest = $average_weighted_balance * ($interest_rate / 100);

            // Total gross dividend
            $gross_dividend = $opening_interest + $contribution_interest;

            // Apply withholding tax (5% as per Kenya law)
            $withholding_tax = $gross_dividend * ($withholding_tax_rate / 100);
            $net_dividend = $gross_dividend - $withholding_tax;

            // Calculate total deposits for the year
            $total_deposits_sql = "SELECT COALESCE(SUM(amount), 0) as total 
                                   FROM deposits 
                                   WHERE member_id = ? AND transaction_type = 'deposit'
                                   AND deposit_date BETWEEN ? AND ?";
            $total_deposits_stmt = $conn->prepare($total_deposits_sql);
            $total_deposits_stmt->bind_param("iss", $member['id'], $year_start, $year_end);
            $total_deposits_stmt->execute();
            $total_deposits_result = $total_deposits_stmt->get_result();
            $total_deposits = $total_deposits_result->fetch_assoc()['total'];

            // Store dividend details
            $dividend_details[] = [
                'member_id' => $member['id'],
                'member_no' => $member['member_no'],
                'full_name' => $member['full_name'],
                'opening_balance' => $opening_balance,
                'adjusted_opening' => $adjusted_opening,
                'withdrawals' => $total_withdrawals,
                'penalties' => $total_penalties,
                'charges' => $total_charges,
                'opening_interest' => $opening_interest,
                'weighted_sum' => $weighted_sum,
                'avg_weighted_balance' => $average_weighted_balance,
                'contribution_interest' => $contribution_interest,
                'gross_dividend' => $gross_dividend,
                'withholding_tax' => $withholding_tax,
                'net_dividend' => $net_dividend,
                'active_months' => $active_months,
                'breakdown' => $monthly_breakdown
            ];

            // Check if the adjusted_opening column exists
            $column_check = $conn->query("SHOW COLUMNS FROM dividends LIKE 'adjusted_opening'");
            $has_columns = $column_check && $column_check->num_rows > 0;

            // In calculate-pro-rata.php, find the section where dividends are inserted
            // Replace the existing dividend insertion code with this:

            // Get current user ID with validation
            $current_user_id = getCurrentUserId();

            // Validate user ID exists
            if (!$current_user_id || $current_user_id <= 0) {
                // Try to get first admin user as fallback
                $admin_query = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
                if ($admin_query && $admin_query->num_rows > 0) {
                    $admin = $admin_query->fetch_assoc();
                    $current_user_id = $admin['id'];
                    error_log("Dividend calculation: Using fallback admin user ID: $current_user_id");
                } else {
                    // If no admin found, try any user
                    $any_user = $conn->query("SELECT id FROM users LIMIT 1");
                    if ($any_user && $any_user->num_rows > 0) {
                        $user = $any_user->fetch_assoc();
                        $current_user_id = $user['id'];
                        error_log("Dividend calculation: Using fallback any user ID: $current_user_id");
                    } else {
                        throw new Exception("No users found in the system to associate with dividend calculation");
                    }
                }
            }

            // Double-check the user exists
            $user_check = $conn->prepare("SELECT id FROM users WHERE id = ?");
            $user_check->bind_param("i", $current_user_id);
            $user_check->execute();
            $user_result = $user_check->get_result();
            if ($user_result->num_rows == 0) {
                throw new Exception("User ID $current_user_id does not exist in database");
            }

            $calculation_method = 'kenya_sacco_pro_rata';

            // Check if the adjusted_opening column exists
            $column_check = $conn->query("SHOW COLUMNS FROM dividends LIKE 'adjusted_opening'");
            $has_columns = $column_check && $column_check->num_rows > 0;

            if ($has_columns) {
                // Full insert with all Kenya SACCO columns
                $insert_sql = "INSERT INTO dividends 
                  (member_id, financial_year, opening_balance, adjusted_opening,
                   total_withdrawals, total_penalties, total_charges, total_deposits, 
                   interest_rate, gross_dividend, withholding_tax, net_dividend, 
                   status, eligibility_months, calculation_method, calculated_by, calculated_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'calculated', ?, ?, ?, NOW())";

                $insert_stmt = $conn->prepare($insert_sql);

                if (!$insert_stmt) {
                    throw new Exception("Failed to prepare insert statement: " . $conn->error);
                }

                $insert_stmt->bind_param(
                    "isddddddddddiis",
                    $member['id'],              // i - integer
                    $financial_year,             // s - string
                    $opening_balance,             // d - double
                    $adjusted_opening,            // d - double
                    $total_withdrawals,           // d - double
                    $total_penalties,             // d - double
                    $total_charges,               // d - double
                    $total_deposits,              // d - double
                    $interest_rate,               // d - double
                    $gross_dividend,              // d - double
                    $withholding_tax,             // d - double
                    $net_dividend,                 // d - double
                    $active_months,                // i - integer
                    $current_user_id,              // i - integer (VALIDATED)
                    $calculation_method            // s - string
                );
            } else {
                // Minimal insert without Kenya-specific columns
                $insert_sql = "INSERT INTO dividends 
                  (member_id, financial_year, opening_balance, total_deposits, 
                   interest_rate, gross_dividend, withholding_tax, net_dividend, 
                   status, eligibility_months, calculation_method, calculated_by, calculated_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'calculated', ?, ?, ?, NOW())";

                $insert_stmt = $conn->prepare($insert_sql);

                if (!$insert_stmt) {
                    throw new Exception("Failed to prepare insert statement: " . $conn->error);
                }

                $insert_stmt->bind_param(
                    "isddddddiis",
                    $member['id'],              // i - integer
                    $financial_year,             // s - string
                    $opening_balance,             // d - double
                    $total_deposits,              // d - double
                    $interest_rate,               // d - double
                    $gross_dividend,              // d - double
                    $withholding_tax,             // d - double
                    $net_dividend,                 // d - double
                    $active_months,                // i - integer
                    $current_user_id,              // i - integer (VALIDATED)
                    $calculation_method            // s - string
                );
            }


            if (!$insert_stmt->execute()) {
                throw new Exception("Failed to insert dividend: " . $insert_stmt->error);
            }

            $dividend_id = $conn->insert_id;

            // Insert monthly breakdown if table exists
            if (!empty($monthly_breakdown) && tableExists($conn, 'dividend_contributions')) {
                foreach ($monthly_breakdown as $breakdown) {
                    $breakdown_sql = "INSERT INTO dividend_contributions 
                                     (dividend_id, contribution_month, amount, weight, weighted_amount, interest_earned)
                                     VALUES (?, ?, ?, ?, ?, ?)";
                    $breakdown_stmt = $conn->prepare($breakdown_sql);
                    $month_date = $financial_year . '-' . str_pad($breakdown['month'], 2, '0', STR_PAD_LEFT) . '-01';
                    $interest_earned = $breakdown['weighted_amount'] * ($interest_rate / 100);

                    // FIXED: Use variables for all parameters
                    $breakdown_stmt->bind_param(
                        "isdddd",
                        $dividend_id,
                        $month_date,
                        $breakdown['amount'],
                        $breakdown['weight'],
                        $breakdown['weighted_amount'],
                        $interest_earned
                    );
                    $breakdown_stmt->execute();
                }
            }

            $total_dividend += $net_dividend;
            $eligible_count++;
        }

        $conn->commit();

        // Store calculation summary in session for display
        $_SESSION['dividend_summary'] = [
            'total_dividend' => $total_dividend,
            'eligible_count' => $eligible_count,
            'excluded_count' => $excluded_count,
            'interest_rate' => $interest_rate,
            'financial_year' => $financial_year,
            'details' => $dividend_details
        ];

        logAudit('CALCULATE', 'dividends', 0, null, [
            'year' => $financial_year,
            'rate' => $interest_rate,
            'method' => 'kenya_sacco_pro_rata',
            'eligible' => $eligible_count,
            'excluded' => $excluded_count,
            'total' => $total_dividend
        ]);

        $_SESSION['success'] = "Dividends calculated successfully using PRO-RATA method.\n";
        $_SESSION['success'] .= "Eligible members: $eligible_count, Excluded: $excluded_count\n";
        $_SESSION['success'] .= "Total payout: " . formatCurrency($total_dividend);
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Calculation failed: ' . $e->getMessage();
        error_log("Dividend calculation error: " . $e->getMessage());
    }

    $conn->close();
    header('Location: index.php');
    exit();
}

// Helper function to check if table exists
function tableExists($conn, $table_name)
{
    $result = $conn->query("SHOW TABLES LIKE '$table_name'");
    return $result->num_rows > 0;
}

include '../../includes/header.php';
?>



<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Calculate Dividends (Pro-rata)</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Dividends</a></li>
                <li class="breadcrumb-item active">Pro-rata</li>
            </ul>
        </div>
    </div>
</div>

<!-- Calculation Form -->
<div class="card">
    <div class="card-header bg-primary text-white">
        <h5 class="card-title mb-0">Dividend Calculation Parameters</h5>
    </div>
    <div class="card-body">
        <form method="POST" action="" class="needs-validation" novalidate>
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label for="financial_year" class="form-label">Financial Year <span class="text-danger">*</span></label>
                    <select class="form-control" id="financial_year" name="financial_year" required>
                        <option value="">-- Select Year --</option>
                        <?php for ($y = $current_year; $y >= $current_year - 5; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $selected_year ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="col-md-3 mb-3">
                    <label for="interest_rate" class="form-label">Dividend Rate (%) <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" id="interest_rate" name="interest_rate"
                        min="0" max="100" step="0.01" required value="<?php echo $default_interest_rate; ?>">
                    <small class="text-muted">Current rate: <?php echo $default_interest_rate; ?>%</small>
                </div>

                <div class="col-md-3 mb-3">
                    <label for="qualifying_months" class="form-label">Minimum Qualifying Months</label>
                    <input type="number" class="form-control" id="qualifying_months" name="qualifying_months"
                        min="0" max="12" value="6">
                    <small class="text-muted">Minimum months to qualify for dividends</small>
                </div>

                <div class="col-md-3 mb-3">
                    <label for="minimum_balance_required" class="form-label">Minimum Balance Required (KES)</label>
                    <input type="number" class="form-control" id="minimum_balance_required" name="minimum_balance_required"
                        min="0" step="100" value="0">
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="apply_penalty_deductions" name="apply_penalty_deductions" value="1" checked>
                        <label class="form-check-label" for="apply_penalty_deductions">
                            Apply penalty and charge deductions to opening balance
                        </label>
                    </div>
                </div>

                <div class="col-md-6 mb-3">
                    <label for="calculation_date" class="form-label">Calculation Date</label>
                    <input type="date" class="form-control" id="calculation_date" name="calculation_date"
                        value="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>

            <div class="alert alert-info">
                <i class="fas fa-calculator me-2"></i>
                <strong>Pro-rata Calculation Method:</strong>
                <div class="row mt-3">
                    <div class="col-md-6">
                        <h6>Step 1: Opening Balance Adjustment</h6>
                        <p class="small">Opening Balance - (Withdrawals + Penalties + Admin Charges) = Adjusted Opening Balance</p>
                        <p class="small">Interest on Opening = Adjusted Opening × Rate%</p>

                        <h6 class="mt-3">Step 2: Monthly Contribution Weighting</h6>
                        <p class="small">Each month's contribution is weighted by months remaining:</p>
                        <ul class="small">
                            <li>January: 12/12 weight (full year)</li>
                            <li>February: 11/12 weight</li>
                            <li>March: 10/12 weight</li>
                            <li>April: 9/12 weight</li>
                            <li>May: 8/12 weight</li>
                            <li>June: 7/12 weight</li>
                            <li>July: 6/12 weight</li>
                            <li>August: 5/12 weight</li>
                            <li>September: 4/12 weight</li>
                            <li>October: 3/12 weight</li>
                            <li>November: 2/12 weight</li>
                            <li>December: 1/12 weight</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6>Step 3: Average Weighted Balance</h6>
                        <p class="small">Weighted Sum = Σ(Monthly Contribution × Weight)</p>
                        <p class="small">Average Weighted Balance = Weighted Sum ÷ Number of Months</p>

                        <h6 class="mt-3">Step 4: Total Dividend Calculation</h6>
                        <p class="small">Gross Dividend = Opening Interest + (Average Weighted Balance × Rate%)</p>
                        <p class="small">Withholding Tax = Gross Dividend × 5%</p>
                        <p class="small fw-bold">Net Dividend = Gross Dividend - Tax</p>

                        <h6 class="mt-3">Example:</h6>
                        <p class="small">KES 1,000 contributed in:<br>
                            January: 1,000 × 12/12 = 1,000<br>
                            April: 1,000 × 9/12 = 750<br>
                            September: 1,000 × 4/12 = 333<br>
                            <strong>Weighted Sum = 2,083</strong>
                        </p>
                    </div>
                </div>
            </div>

            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Important Notes:</strong>
                <ul class="mb-0 mt-2">
                    <li>Members who joined in December are typically excluded (or get minimal weight)</li>
                    <li>Withdrawals, penalties, and charges are deducted from opening balance</li>
                    <li>5% withholding tax is deducted as per Kenya Revenue Authority requirements</li>
                    <li>Minimum qualifying period of 6 months is recommended</li>
                </ul>
            </div>

            <hr>

            <button type="submit" class="btn btn-primary btn-lg" onclick="return confirm('This will calculate pro-rata dividends for all eligible members using Kenya SACCO standards. Proceed?')">
                <i class="fas fa-calculator me-2"></i>Calculate Pro-rata Dividends
            </button>
            <a href="index.php" class="btn btn-secondary btn-lg">
                <i class="fas fa-times me-2"></i>Cancel
            </a>
        </form>
    </div>
</div>

<!-- Previous Year Statistics -->
<div class="card mt-4">
    <div class="card-header">
        <h5 class="card-title">Previous Year Statistics</h5>
    </div>
    <div class="card-body">
        <?php
        $stats_sql = "SELECT 
                      financial_year,
                      COUNT(*) as member_count,
                      SUM(net_dividend) as total_dividend,
                      AVG(net_dividend) as avg_dividend,
                      MIN(net_dividend) as min_dividend,
                      MAX(net_dividend) as max_dividend
                      FROM dividends
                      WHERE financial_year < ?
                      GROUP BY financial_year
                      ORDER BY financial_year DESC
                      LIMIT 5";
        $stats = executeQuery($stats_sql, "s", [$selected_year]);
        ?>

        <?php if ($stats->num_rows > 0): ?>
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Year</th>
                            <th>Members</th>
                            <th>Total Payout</th>
                            <th>Average</th>
                            <th>Minimum</th>
                            <th>Maximum</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $stats->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $row['financial_year']; ?></td>
                                <td><?php echo number_format($row['member_count']); ?></td>
                                <td><?php echo formatCurrency($row['total_dividend']); ?></td>
                                <td><?php echo formatCurrency($row['avg_dividend']); ?></td>
                                <td><?php echo formatCurrency($row['min_dividend']); ?></td>
                                <td><?php echo formatCurrency($row['max_dividend']); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-muted text-center">No previous dividend calculations found.</p>
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
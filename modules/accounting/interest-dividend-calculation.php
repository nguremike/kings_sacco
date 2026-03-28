<?php
// modules/accounting/interest-dividend-calculation.php
require_once '../../config/config.php';
requireRole('admin');

$page_title = 'Interest & Dividend Calculation';

// Get current year
$current_year = date('Y');
$selected_year = $_GET['year'] ?? $current_year;
$calculation_type = $_GET['type'] ?? 'interest'; // interest or dividend

// Handle calculation
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action == 'calculate_interest') {
        calculateInterest();
    } elseif ($action == 'calculate_dividend') {
        calculateDividend();
    }
}

function calculateInterest()
{
    $conn = getConnection();
    $conn->begin_transaction();

    try {
        $year = $_POST['financial_year'];
        $interest_rate = floatval($_POST['interest_rate']);
        $total_interest_pool = floatval($_POST['total_interest_pool']);
        $year_start = $year . '-01-01';
        $year_end = $year . '-12-31';

        // Check if already calculated
        $check_sql = "SELECT id FROM interest_calculations WHERE financial_year = ? AND calculation_type = 'interest'";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $year);
        $check_stmt->execute();
        if ($check_stmt->get_result()->num_rows > 0) {
            throw new Exception("Interest already calculated for year $year");
        }

        // Get all eligible members (exclude December joiners)
        $members_sql = "SELECT m.id, m.member_no, m.full_name, m.date_joined,
                        (SELECT COALESCE(SUM(CASE 
                            WHEN transaction_type = 'deposit' THEN amount 
                            WHEN transaction_type = 'withdrawal' THEN -amount 
                            ELSE 0 
                        END), 0) 
                         FROM deposits 
                         WHERE member_id = m.id AND deposit_date < ?) as opening_balance
                        FROM members m
                        WHERE m.membership_status = 'active' 
                        AND MONTH(m.date_joined) < 12
                        AND YEAR(m.date_joined) <= ?";

        $members = $conn->prepare($members_sql);
        $members->bind_param("si", $year_start, $year);
        $members->execute();
        $members_result = $members->get_result();

        $calculations = [];
        $total_opening_interest = 0;
        $total_weighted_value = 0;

        // Step 1: Calculate opening balance interest for all members
        while ($member = $members_result->fetch_assoc()) {
            $opening_balance = floatval($member['opening_balance']);
            $opening_interest = $opening_balance * ($interest_rate / 100);

            $calculations[$member['id']] = [
                'member_id' => $member['id'],
                'member_no' => $member['member_no'],
                'full_name' => $member['full_name'],
                'opening_balance' => $opening_balance,
                'opening_interest' => $opening_interest,
                'active_months' => calculateActiveMonths($member['date_joined'], $year_start, $year_end),
                'weighted_contributions' => 0,
                'weighted_value' => 0
            ];

            $total_opening_interest += $opening_interest;
        }

        // Step 2: Compare with declared pool
        $remaining_pool = $total_interest_pool - $total_opening_interest;

        if ($remaining_pool > 0) {
            // Step 3: Calculate weighted contributions for pro-rata distribution
            foreach ($calculations as $member_id => &$calc) {
                // Get monthly contributions for the year
                $contributions_sql = "SELECT MONTH(deposit_date) as month, SUM(amount) as total
                                     FROM deposits 
                                     WHERE member_id = ? 
                                     AND transaction_type = 'deposit'
                                     AND YEAR(deposit_date) = ?
                                     GROUP BY MONTH(deposit_date)";
                $contrib_stmt = $conn->prepare($contributions_sql);
                $contrib_stmt->bind_param("ii", $member_id, $year);
                $contrib_stmt->execute();
                $contrib_result = $contrib_stmt->get_result();

                $weighted_sum = 0;
                while ($contrib = $contrib_result->fetch_assoc()) {
                    $month = $contrib['month'];
                    $amount = $contrib['total'];
                    $months_remaining = 13 - $month; // Jan=12, Feb=11, ..., Dec=1
                    $weighted_sum += $amount * $months_remaining;
                }

                $weighted_value = $weighted_sum / 12;
                $calc['weighted_contributions'] = $weighted_sum;
                $calc['weighted_value'] = $weighted_value;
                $total_weighted_value += $weighted_value;
            }

            // Step 4: Distribute remaining pool pro-rata
            foreach ($calculations as &$calc) {
                if ($total_weighted_value > 0) {
                    $pro_rata_interest = ($calc['weighted_value'] / $total_weighted_value) * $remaining_pool;
                    $calc['pro_rata_interest'] = $pro_rata_interest;
                } else {
                    $calc['pro_rata_interest'] = 0;
                }
                $calc['gross_interest'] = $calc['opening_interest'] + $calc['pro_rata_interest'];
            }
        } else {
            // Scale down opening interest proportionally
            $scale_factor = $total_interest_pool / $total_opening_interest;
            foreach ($calculations as &$calc) {
                $calc['opening_interest'] = $calc['opening_interest'] * $scale_factor;
                $calc['gross_interest'] = $calc['opening_interest'];
                $calc['pro_rata_interest'] = 0;
            }
        }

        // Step 5: Calculate tax and net interest with rounding
        foreach ($calculations as &$calc) {
            $calc['withholding_tax'] = $calc['gross_interest'] * 0.05;
            $calc['net_interest'] = $calc['gross_interest'] - $calc['withholding_tax'];
            $calc['net_interest'] = roundToNearestDenomination($calc['net_interest']);

            // Insert into database
            $insert_sql = "INSERT INTO interest_calculations 
                          (member_id, financial_year, opening_balance, opening_interest, 
                           weighted_contributions, weighted_value, pro_rata_interest, 
                           gross_interest, withholding_tax, net_interest, interest_rate,
                           calculation_type, created_by, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'interest', ?, NOW())";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param(
                "iidddddddddi",
                $calc['member_id'],
                $year,
                $calc['opening_balance'],
                $calc['opening_interest'],
                $calc['weighted_contributions'],
                $calc['weighted_value'],
                $calc['pro_rata_interest'],
                $calc['gross_interest'],
                $calc['withholding_tax'],
                $calc['net_interest'],
                $interest_rate,
                getCurrentUserId()
            );
            $insert_stmt->execute();
        }

        // Create calculation summary
        $summary_sql = "INSERT INTO interest_calculation_summary 
                       (financial_year, calculation_type, total_members, total_gross_interest, 
                        total_tax, total_net_interest, interest_rate, total_pool, created_by)
                       VALUES (?, 'interest', ?, ?, ?, ?, ?, ?, ?)";
        $summary_stmt = $conn->prepare($summary_sql);
        $total_gross = array_sum(array_column($calculations, 'gross_interest'));
        $total_tax = array_sum(array_column($calculations, 'withholding_tax'));
        $total_net = array_sum(array_column($calculations, 'net_interest'));
        $summary_stmt->bind_param(
            "iidddddi",
            $year,
            count($calculations),
            $total_gross,
            $total_tax,
            $total_net,
            $interest_rate,
            $total_interest_pool,
            getCurrentUserId()
        );
        $summary_stmt->execute();

        $conn->commit();

        $_SESSION['interest_calculations'] = $calculations;
        $_SESSION['interest_summary'] = [
            'total_members' => count($calculations),
            'total_opening_interest' => $total_opening_interest,
            'total_weighted_value' => $total_weighted_value,
            'remaining_pool' => $remaining_pool,
            'total_gross' => $total_gross,
            'total_tax' => $total_tax,
            'total_net' => $total_net
        ];
        $_SESSION['success'] = "Interest calculation completed successfully!";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Calculation failed: ' . $e->getMessage();
        error_log("Interest calculation error: " . $e->getMessage());
    }

    $conn->close();
    header('Location: interest-dividend-calculation.php?type=interest');
    exit();
}

function calculateDividend()
{
    $conn = getConnection();
    $conn->begin_transaction();

    try {
        $year = $_POST['financial_year'];
        $dividend_pool = floatval($_POST['dividend_pool']);
        $share_value = 10000;
        $year_start = $year . '-01-01';
        $year_end = $year . '-12-31';

        // Check if already calculated
        $check_sql = "SELECT id FROM interest_calculations WHERE financial_year = ? AND calculation_type = 'dividend'";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $year);
        $check_stmt->execute();
        if ($check_stmt->get_result()->num_rows > 0) {
            throw new Exception("Dividend already calculated for year $year");
        }

        // Get all members with shares
        $members_sql = "SELECT m.id, m.member_no, m.full_name,
                        COALESCE(m.full_shares_issued, 0) as full_shares,
                        COALESCE(m.partial_share_balance, 0) as partial_balance
                        FROM members m
                        WHERE m.membership_status = 'active'";
        $members = $conn->query($members_sql);

        $calculations = [];
        $total_weighted_shares = 0;

        // Calculate weighted shares for each member
        while ($member = $members->fetch_assoc()) {
            $total_shares_value = ($member['full_shares'] * $share_value) + $member['partial_balance'];

            // Determine eligible months
            $eligible_months = 0;

            if ($total_shares_value >= $share_value) {
                // Check if member already had full share at start of year
                $has_full_at_start = checkFullShareAtStart($conn, $member['id'], $year);

                if ($has_full_at_start) {
                    $eligible_months = 12;
                } else {
                    // Find when member reached full share
                    $completion_month = findShareCompletionMonth($conn, $member['id'], $year, $share_value);
                    if ($completion_month > 0) {
                        $eligible_months = 13 - $completion_month;
                    }
                }
            }

            if ($eligible_months > 0) {
                $weighted_shares = ($share_value * $eligible_months) / 12;
                $calculations[] = [
                    'member_id' => $member['id'],
                    'member_no' => $member['member_no'],
                    'full_name' => $member['full_name'],
                    'full_shares' => $member['full_shares'],
                    'partial_balance' => $member['partial_balance'],
                    'total_share_value' => $total_shares_value,
                    'eligible_months' => $eligible_months,
                    'weighted_shares' => $weighted_shares
                ];
                $total_weighted_shares += $weighted_shares;
            }
        }

        // Distribute dividends pro-rata
        foreach ($calculations as &$calc) {
            if ($total_weighted_shares > 0) {
                $calc['gross_dividend'] = ($calc['weighted_shares'] / $total_weighted_shares) * $dividend_pool;
            } else {
                $calc['gross_dividend'] = 0;
            }
            $calc['withholding_tax'] = $calc['gross_dividend'] * 0.05;
            $calc['net_dividend'] = $calc['gross_dividend'] - $calc['withholding_tax'];
            $calc['net_dividend'] = roundToNearestDenomination($calc['net_dividend']);

            // Insert into database
            $insert_sql = "INSERT INTO interest_calculations 
                          (member_id, financial_year, total_share_value, eligible_months,
                           weighted_shares, gross_dividend, withholding_tax, net_dividend,
                           calculation_type, created_by, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'dividend', ?, NOW())";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param(
                "iiddddddi",
                $calc['member_id'],
                $year,
                $calc['total_share_value'],
                $calc['eligible_months'],
                $calc['weighted_shares'],
                $calc['gross_dividend'],
                $calc['withholding_tax'],
                $calc['net_dividend'],
                getCurrentUserId()
            );
            $insert_stmt->execute();
        }

        // Create summary
        $summary_sql = "INSERT INTO interest_calculation_summary 
                       (financial_year, calculation_type, total_members, total_gross, 
                        total_tax, total_net, total_pool, created_by)
                       VALUES (?, 'dividend', ?, ?, ?, ?, ?, ?)";
        $summary_stmt = $conn->prepare($summary_sql);
        $total_gross = array_sum(array_column($calculations, 'gross_dividend'));
        $total_tax = array_sum(array_column($calculations, 'withholding_tax'));
        $total_net = array_sum(array_column($calculations, 'net_dividend'));
        $summary_stmt->bind_param(
            "iiddddi",
            $year,
            count($calculations),
            $total_gross,
            $total_tax,
            $total_net,
            $dividend_pool,
            getCurrentUserId()
        );
        $summary_stmt->execute();

        $conn->commit();

        $_SESSION['dividend_calculations'] = $calculations;
        $_SESSION['dividend_summary'] = [
            'total_members' => count($calculations),
            'total_weighted_shares' => $total_weighted_shares,
            'total_gross' => $total_gross,
            'total_tax' => $total_tax,
            'total_net' => $total_net
        ];
        $_SESSION['success'] = "Dividend calculation completed successfully!";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Calculation failed: ' . $e->getMessage();
        error_log("Dividend calculation error: " . $e->getMessage());
    }

    $conn->close();
    header('Location: interest-dividend-calculation.php?type=dividend');
    exit();
}

function calculateActiveMonths($date_joined, $year_start, $year_end)
{
    $join_date = new DateTime($date_joined);
    $start_date = new DateTime($year_start);
    $end_date = new DateTime($year_end);

    if ($join_date > $end_date) return 0;

    $effective_start = max($join_date, $start_date);
    $interval = $effective_start->diff($end_date);
    return $interval->m + ($interval->y * 12) + 1;
}

function checkFullShareAtStart($conn, $member_id, $year)
{
    $year_start = $year . '-01-01';
    $sql = "SELECT COALESCE(SUM(total_value), 0) as total_shares 
            FROM shares 
            WHERE member_id = ? AND date_purchased < ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $member_id, $year_start);
    $stmt->execute();
    $result = $stmt->get_result();
    $total = $result->fetch_assoc()['total_shares'];
    return $total >= 10000;
}

function findShareCompletionMonth($conn, $member_id, $year, $share_value)
{
    $year_start = $year . '-01-01';
    $year_end = $year . '-12-31';

    $sql = "SELECT date_purchased, total_value FROM shares 
            WHERE member_id = ? AND date_purchased BETWEEN ? AND ?
            ORDER BY date_purchased ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $member_id, $year_start, $year_end);
    $stmt->execute();
    $result = $stmt->get_result();

    $running_total = 0;
    while ($row = $result->fetch_assoc()) {
        $running_total += $row['total_value'];
        if ($running_total >= $share_value) {
            return (int)date('m', strtotime($row['date_purchased']));
        }
    }

    return 0;
}

function roundToNearestDenomination($amount)
{
    if ($amount < 100) {
        return round($amount / 50) * 50;
    } elseif ($amount < 500) {
        return round($amount / 100) * 100;
    } elseif ($amount < 1000) {
        return round($amount / 500) * 500;
    } else {
        return round($amount / 1000) * 1000;
    }
}

// Get previous years for dropdown
$years = [];
$result = executeQuery("SELECT DISTINCT financial_year FROM interest_calculations ORDER BY financial_year DESC");
while ($row = $result->fetch_assoc()) {
    $years[] = $row['financial_year'];
}

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Interest & Dividend Calculation</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Accounting</a></li>
                <li class="breadcrumb-item active">Interest & Dividend</li>
            </ul>
        </div>
        <div class="col-auto">
            <div class="btn-group">
                <a href="?type=interest" class="btn btn-primary <?php echo $calculation_type == 'interest' ? 'active' : ''; ?>">
                    <i class="fas fa-percentage"></i> Interest
                </a>
                <a href="?type=dividend" class="btn btn-success <?php echo $calculation_type == 'dividend' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-pie"></i> Dividend
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Alert Messages -->
<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?php echo nl2br($_SESSION['success']); ?>
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

<?php if ($calculation_type == 'interest'): ?>
    <!-- Interest Calculation Form -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="card-title mb-0"><i class="fas fa-percentage me-2"></i>Interest on Member Deposits</h5>
        </div>
        <div class="card-body">
            <form method="POST" action="" class="needs-validation" novalidate>
                <input type="hidden" name="action" value="calculate_interest">

                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label for="financial_year" class="form-label">Financial Year</label>
                        <select class="form-control" id="financial_year" name="financial_year" required>
                            <option value="">Select Year</option>
                            <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                                <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label for="interest_rate" class="form-label">Interest Rate (%)</label>
                        <input type="number" class="form-control" id="interest_rate" name="interest_rate"
                            step="0.01" min="0" max="100" value="11" required>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label for="total_interest_pool" class="form-label">Total Interest Payable (KES)</label>
                        <input type="number" class="form-control" id="total_interest_pool" name="total_interest_pool"
                            step="1000" min="0" required>
                        <small class="text-muted">Declared after AGM</small>
                    </div>
                </div>

                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Calculation Method:</strong>
                    <ul class="mb-0 mt-2">
                        <li>Opening balance interest: Balance × Rate</li>
                        <li>If opening interest &lt; declared pool → Pro-rata distribution of remaining pool</li>
                        <li>If opening interest ≥ declared pool → Scale down proportionally</li>
                        <li>Monthly contributions weighted by months remaining (Jan=12, Feb=11, ..., Dec=1)</li>
                        <li>5% withholding tax deducted</li>
                        <li>Amounts rounded to nearest 50/100/500/1000 (no coins)</li>
                    </ul>
                </div>

                <button type="submit" class="btn btn-primary btn-lg" onclick="return confirm('Calculate interest for selected year?')">
                    <i class="fas fa-calculator me-2"></i>Calculate Interest
                </button>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if ($calculation_type == 'dividend'): ?>
    <!-- Dividend Calculation Form -->
    <div class="card mb-4">
        <div class="card-header bg-success text-white">
            <h5 class="card-title mb-0"><i class="fas fa-chart-pie me-2"></i>Dividend on Shares</h5>
        </div>
        <div class="card-body">
            <form method="POST" action="" class="needs-validation" novalidate>
                <input type="hidden" name="action" value="calculate_dividend">

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="financial_year" class="form-label">Financial Year</label>
                        <select class="form-control" id="financial_year" name="financial_year" required>
                            <option value="">Select Year</option>
                            <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                                <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label for="dividend_pool" class="form-label">Total Dividend Pool (KES)</label>
                        <input type="number" class="form-control" id="dividend_pool" name="dividend_pool"
                            step="1000" min="0" required>
                        <small class="text-muted">Declared after audit</small>
                    </div>
                </div>

                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Dividend Calculation Method:</strong>
                    <ul class="mb-0 mt-2">
                        <li>1 Share = KES 10,000</li>
                        <li>Only FULL shares qualify for dividends</li>
                        <li>Members who had full share from Jan: eligible for full year</li>
                        <li>Members who completed share during year: eligible from completion month</li>
                        <li>Weighted shares = (Share Value × Eligible Months) / 12</li>
                        <li>Pro-rata distribution based on weighted shares</li>
                        <li>5% withholding tax deducted</li>
                        <li>Amounts rounded to nearest 50/100/500/1000 (no coins)</li>
                    </ul>
                </div>

                <button type="submit" class="btn btn-success btn-lg" onclick="return confirm('Calculate dividend for selected year?')">
                    <i class="fas fa-calculator me-2"></i>Calculate Dividend
                </button>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- Results Section -->
<?php if ($calculation_type == 'interest' && isset($_SESSION['interest_calculations'])):
    $calculations = $_SESSION['interest_calculations'];
    $summary = $_SESSION['interest_summary'];
    unset($_SESSION['interest_calculations']);
    unset($_SESSION['interest_summary']);
?>
    <div class="card mb-4">
        <div class="card-header bg-success text-white">
            <h5 class="card-title mb-0">Interest Calculation Results</h5>
        </div>
        <div class="card-body">
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stats-card primary">
                        <div class="stats-content">
                            <h3><?php echo number_format($summary['total_members']); ?></h3>
                            <p>Eligible Members</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card info">
                        <div class="stats-content">
                            <h3><?php echo formatCurrency($summary['total_opening_interest']); ?></h3>
                            <p>Opening Interest</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card warning">
                        <div class="stats-content">
                            <h3><?php echo formatCurrency($summary['remaining_pool']); ?></h3>
                            <p>Remaining Pool</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card success">
                        <div class="stats-content">
                            <h3><?php echo formatCurrency($summary['total_net']); ?></h3>
                            <p>Total Net Payout</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered datatable">
                    <thead>
                        <tr>
                            <th>Member No</th>
                            <th>Member Name</th>
                            <th>Opening Balance</th>
                            <th>Opening Interest</th>
                            <th>Pro-rata Interest</th>
                            <th>Gross Interest</th>
                            <th>Tax (5%)</th>
                            <th>Net Interest</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($calculations as $calc): ?>
                            <tr>
                                <td><?php echo $calc['member_no']; ?></td>
                                <td><?php echo $calc['full_name']; ?></td>
                                <td class="text-end"><?php echo formatCurrency($calc['opening_balance']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($calc['opening_interest']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($calc['pro_rata_interest']); ?></td>
                                <td class="text-end fw-bold"><?php echo formatCurrency($calc['gross_interest']); ?></td>
                                <td class="text-end text-danger"><?php echo formatCurrency($calc['withholding_tax']); ?></td>
                                <td class="text-end text-success fw-bold"><?php echo formatCurrency($calc['net_interest']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-info">
                            <th colspan="2" class="text-end">Totals:</th>
                            <th class="text-end"><?php echo formatCurrency(array_sum(array_column($calculations, 'opening_balance'))); ?></th>
                            <th class="text-end"><?php echo formatCurrency(array_sum(array_column($calculations, 'opening_interest'))); ?></th>
                            <th class="text-end"><?php echo formatCurrency(array_sum(array_column($calculations, 'pro_rata_interest'))); ?></th>
                            <th class="text-end"><?php echo formatCurrency(array_sum(array_column($calculations, 'gross_interest'))); ?></th>
                            <th class="text-end"><?php echo formatCurrency(array_sum(array_column($calculations, 'withholding_tax'))); ?></th>
                            <th class="text-end"><?php echo formatCurrency(array_sum(array_column($calculations, 'net_interest'))); ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($calculation_type == 'dividend' && isset($_SESSION['dividend_calculations'])):
    $calculations = $_SESSION['dividend_calculations'];
    $summary = $_SESSION['dividend_summary'];
    unset($_SESSION['dividend_calculations']);
    unset($_SESSION['dividend_summary']);
?>
    <div class="card mb-4">
        <div class="card-header bg-success text-white">
            <h5 class="card-title mb-0">Dividend Calculation Results</h5>
        </div>
        <div class="card-body">
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="stats-card primary">
                        <div class="stats-content">
                            <h3><?php echo number_format($summary['total_members']); ?></h3>
                            <p>Eligible Members</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card info">
                        <div class="stats-content">
                            <h3><?php echo number_format($summary['total_weighted_shares'], 2); ?></h3>
                            <p>Total Weighted Shares</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card success">
                        <div class="stats-content">
                            <h3><?php echo formatCurrency($summary['total_net']); ?></h3>
                            <p>Total Net Payout</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered datatable">
                    <thead>
                        <tr>
                            <th>Member No</th>
                            <th>Member Name</th>
                            <th>Full Shares</th>
                            <th>Share Value</th>
                            <th>Eligible Months</th>
                            <th>Weighted Shares</th>
                            <th>Gross Dividend</th>
                            <th>Tax (5%)</th>
                            <th>Net Dividend</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($calculations as $calc): ?>
                            <tr>
                                <td><?php echo $calc['member_no']; ?></td>
                                <td><?php echo $calc['full_name']; ?></td>
                                <td class="text-end"><?php echo number_format($calc['full_shares']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($calc['total_share_value']); ?></td>
                                <td class="text-end"><?php echo $calc['eligible_months']; ?></td>
                                <td class="text-end"><?php echo number_format($calc['weighted_shares'], 2); ?></td>
                                <td class="text-end fw-bold"><?php echo formatCurrency($calc['gross_dividend']); ?></td>
                                <td class="text-end text-danger"><?php echo formatCurrency($calc['withholding_tax']); ?></td>
                                <td class="text-end text-success fw-bold"><?php echo formatCurrency($calc['net_dividend']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-info">
                            <th colspan="5" class="text-end">Totals:</th>
                            <th class="text-end"><?php echo number_format($summary['total_weighted_shares'], 2); ?></th>
                            <th class="text-end"><?php echo formatCurrency($summary['total_gross']); ?></th>
                            <th class="text-end"><?php echo formatCurrency($summary['total_tax']); ?></th>
                            <th class="text-end"><?php echo formatCurrency($summary['total_net']); ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

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
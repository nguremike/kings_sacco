<?php
// modules/accounting/interest-dividend-calculation.php
require_once '../../config/config.php';
requireRole('admin');

$page_title = 'Interest & Dividend Calculation';

// ==================== HELPER FUNCTIONS ====================

/**
 * Round amount to nearest denomination to eliminate coins
 * Rules:
 * - Amount < 100: round to nearest 50
 * - Amount < 500: round to nearest 100
 * - Amount < 1000: round to nearest 500
 * - Amount >= 1000: round to nearest 1000
 */
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

/**
 * Calculate active months for a member in a given year
 */
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

/**
 * Check if member already had full share at start of year
 */
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

/**
 * Find when member reached full share during the year
 */
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

// Get current year
$current_year = date('Y');
$selected_year = $_GET['year'] ?? $current_year;
$calculation_type = $_GET['type'] ?? 'interest';
$action = $_GET['action'] ?? 'form'; // form, preview, calculate, reports

// Handle actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action == 'preview_interest') {
        previewInterest();
    } elseif ($action == 'preview_dividend') {
        previewDividend();
    } elseif ($action == 'calculate_interest') {
        calculateInterest();
    } elseif ($action == 'calculate_dividend') {
        calculateDividend();
    }
}

// [Rest of the code continues...]

// Function to preview interest calculation
function previewInterest()
{
    $conn = getConnection();

    try {
        $year = $_POST['financial_year'];
        $interest_rate = floatval($_POST['interest_rate']);
        $total_interest_pool = floatval($_POST['total_interest_pool']);
        $year_start = $year . '-01-01';

        // Get all eligible members
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

        $preview_data = [];
        $total_opening_interest = 0;
        $total_weighted_value = 0;

        while ($member = $members_result->fetch_assoc()) {
            $opening_balance = floatval($member['opening_balance']);
            $opening_interest = $opening_balance * ($interest_rate / 100);

            $preview_data[$member['id']] = [
                'member_id' => $member['id'],
                'member_no' => $member['member_no'],
                'full_name' => $member['full_name'],
                'opening_balance' => $opening_balance,
                'opening_interest' => $opening_interest,
                'weighted_value' => 0,
                'pro_rata_interest' => 0
            ];

            $total_opening_interest += $opening_interest;
        }

        $remaining_pool = max(0, $total_interest_pool - $total_opening_interest);

        if ($remaining_pool > 0) {
            // Calculate weighted contributions
            foreach ($preview_data as $member_id => &$calc) {
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
                    $months_remaining = 13 - $month;
                    $weighted_sum += $amount * $months_remaining;
                }

                $calc['weighted_value'] = $weighted_sum / 12;
                $total_weighted_value += $calc['weighted_value'];
            }

            // Calculate pro-rata interest
            foreach ($preview_data as &$calc) {
                if ($total_weighted_value > 0) {
                    $calc['pro_rata_interest'] = ($calc['weighted_value'] / $total_weighted_value) * $remaining_pool;
                }
            }
        }

        // Calculate final amounts
        foreach ($preview_data as &$calc) {
            $calc['gross_interest'] = $calc['opening_interest'] + $calc['pro_rata_interest'];
            $calc['withholding_tax'] = $calc['gross_interest'] * 0.05;
            $calc['net_interest'] = $calc['gross_interest'] - $calc['withholding_tax'];
            $calc['net_interest'] = roundToNearestDenomination($calc['net_interest']);
        }

        $_SESSION['interest_preview'] = [
            'data' => $preview_data,
            'year' => $year,
            'interest_rate' => $interest_rate,
            'total_interest_pool' => $total_interest_pool,
            'total_opening_interest' => $total_opening_interest,
            'remaining_pool' => $remaining_pool,
            'total_weighted_value' => $total_weighted_value,
            'total_gross' => array_sum(array_column($preview_data, 'gross_interest')),
            'total_tax' => array_sum(array_column($preview_data, 'withholding_tax')),
            'total_net' => array_sum(array_column($preview_data, 'net_interest'))
        ];

        $_SESSION['success_preview'] = "Preview generated successfully!";
    } catch (Exception $e) {
        $_SESSION['error'] = 'Preview failed: ' . $e->getMessage();
    }

    $conn->close();
    header('Location: interest-dividend-calculation.php?type=interest&action=preview');
    exit();
}

// Function to preview dividend calculation
function previewDividend()
{
    $conn = getConnection();

    try {
        $year = $_POST['financial_year'];
        $dividend_pool = floatval($_POST['dividend_pool']);
        $share_value = 10000;

        $members_sql = "SELECT m.id, m.member_no, m.full_name,
                        COALESCE(m.full_shares_issued, 0) as full_shares,
                        COALESCE(m.partial_share_balance, 0) as partial_balance
                        FROM members m
                        WHERE m.membership_status = 'active'";
        $members = $conn->query($members_sql);

        $preview_data = [];
        $total_weighted_shares = 0;

        while ($member = $members->fetch_assoc()) {
            $total_shares_value = ($member['full_shares'] * $share_value) + $member['partial_balance'];
            $eligible_months = 0;

            if ($total_shares_value >= $share_value) {
                $has_full_at_start = checkFullShareAtStart($conn, $member['id'], $year);

                if ($has_full_at_start) {
                    $eligible_months = 12;
                } else {
                    $completion_month = findShareCompletionMonth($conn, $member['id'], $year, $share_value);
                    if ($completion_month > 0) {
                        $eligible_months = 13 - $completion_month;
                    }
                }
            }

            if ($eligible_months > 0) {
                $weighted_shares = ($share_value * $eligible_months) / 12;
                $preview_data[] = [
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

        foreach ($preview_data as &$calc) {
            if ($total_weighted_shares > 0) {
                $calc['gross_dividend'] = ($calc['weighted_shares'] / $total_weighted_shares) * $dividend_pool;
            } else {
                $calc['gross_dividend'] = 0;
            }
            $calc['withholding_tax'] = $calc['gross_dividend'] * 0.05;
            $calc['net_dividend'] = $calc['gross_dividend'] - $calc['withholding_tax'];
            $calc['net_dividend'] = roundToNearestDenomination($calc['net_dividend']);
        }

        $_SESSION['dividend_preview'] = [
            'data' => $preview_data,
            'year' => $year,
            'dividend_pool' => $dividend_pool,
            'total_weighted_shares' => $total_weighted_shares,
            'total_gross' => array_sum(array_column($preview_data, 'gross_dividend')),
            'total_tax' => array_sum(array_column($preview_data, 'withholding_tax')),
            'total_net' => array_sum(array_column($preview_data, 'net_dividend'))
        ];

        $_SESSION['success_preview'] = "Preview generated successfully!";
    } catch (Exception $e) {
        $_SESSION['error'] = 'Preview failed: ' . $e->getMessage();
    }

    $conn->close();
    header('Location: interest-dividend-calculation.php?type=dividend&action=preview');
    exit();
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

        // ... [rest of the calculation code as shown above] ...

        // When inserting, store all values in variables
        $current_user = getCurrentUserId();
        if (!$current_user) $current_user = 1;

        foreach ($calculations as &$calc) {
            // Store all values in variables
            $member_id_val = $calc['member_id'];
            $year_val = $year;
            $opening_balance_val = $calc['opening_balance'];
            $opening_interest_val = $calc['opening_interest'];
            $weighted_contributions_val = $calc['weighted_contributions'];
            $weighted_value_val = $calc['weighted_value'];
            $pro_rata_interest_val = $calc['pro_rata_interest'];
            $gross_interest_val = $calc['gross_interest'];
            $withholding_tax_val = $calc['withholding_tax'];
            $net_interest_val = $calc['net_interest'];
            $interest_rate_val = $interest_rate;

            $insert_sql = "INSERT INTO interest_calculations 
                          (member_id, financial_year, opening_balance, opening_interest, 
                           weighted_contributions, weighted_value, pro_rata_interest, 
                           gross_interest, withholding_tax, net_interest, interest_rate,
                           calculation_type, created_by, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'interest', ?, NOW())";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param(
                "iidddddddddi",
                $member_id_val,
                $year_val,
                $opening_balance_val,
                $opening_interest_val,
                $weighted_contributions_val,
                $weighted_value_val,
                $pro_rata_interest_val,
                $gross_interest_val,
                $withholding_tax_val,
                $net_interest_val,
                $interest_rate_val,
                $current_user
            );
            $insert_stmt->execute();
        }

        // ... [rest of the function] ...

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

        // ... [rest of the calculation code as shown above] ...

        // When inserting, store all values in variables
        $current_user = getCurrentUserId();
        if (!$current_user) $current_user = 1;

        foreach ($calculations as &$calc) {
            // Store all values in variables
            $member_id_val = $calc['member_id'];
            $year_val = $year;
            $total_share_value_val = $calc['total_share_value'];
            $eligible_months_val = $calc['eligible_months'];
            $weighted_shares_val = $calc['weighted_shares'];
            $gross_dividend_val = $calc['gross_dividend'];
            $withholding_tax_val = $calc['withholding_tax'];
            $net_dividend_val = $calc['net_dividend'];

            $insert_sql = "INSERT INTO interest_calculations 
                          (member_id, financial_year, total_share_value, eligible_months,
                           weighted_shares, gross_dividend, withholding_tax, net_dividend,
                           calculation_type, created_by, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'dividend', ?, NOW())";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param(
                "iiddddddi",
                $member_id_val,
                $year_val,
                $total_share_value_val,
                $eligible_months_val,
                $weighted_shares_val,
                $gross_dividend_val,
                $withholding_tax_val,
                $net_dividend_val,
                $current_user
            );
            $insert_stmt->execute();
        }

        // Create summary - store values in variables
        $total_gross = array_sum(array_column($calculations, 'gross_dividend'));
        $total_tax = array_sum(array_column($calculations, 'withholding_tax'));
        $total_net = array_sum(array_column($calculations, 'net_dividend'));
        $total_members = count($calculations);

        $summary_sql = "INSERT INTO interest_calculation_summary 
                       (financial_year, calculation_type, total_members, total_gross, 
                        total_tax, total_net, total_pool, created_by)
                       VALUES (?, 'dividend', ?, ?, ?, ?, ?, ?)";
        $summary_stmt = $conn->prepare($summary_sql);
        $summary_stmt->bind_param(
            "iiddddi",
            $year,
            $total_members,
            $total_gross,
            $total_tax,
            $total_net,
            $dividend_pool,
            $current_user
        );
        $summary_stmt->execute();

        $conn->commit();

        $_SESSION['dividend_calculations'] = $calculations;
        $_SESSION['dividend_summary'] = [
            'total_members' => $total_members,
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

// [Include the calculateInterest and calculateDividend functions from previous response]
// ... (keep the existing calculate functions) ...

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
                <a href="?type=interest&action=form" class="btn btn-primary <?php echo $calculation_type == 'interest' ? 'active' : ''; ?>">
                    <i class="fas fa-percentage"></i> Interest
                </a>
                <a href="?type=dividend&action=form" class="btn btn-success <?php echo $calculation_type == 'dividend' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-pie"></i> Dividend
                </a>
                <a href="?type=<?php echo $calculation_type; ?>&action=reports" class="btn btn-info">
                    <i class="fas fa-history"></i> Reports
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

<?php if (isset($_SESSION['success_preview'])): ?>
    <div class="alert alert-info alert-dismissible fade show" role="alert">
        <i class="fas fa-eye me-2"></i>
        <?php echo $_SESSION['success_preview']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['success_preview']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>
        <?php echo $_SESSION['error']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<?php if ($action == 'reports'): ?>
    <!-- Reports Section -->
    <div class="card mb-4">
        <div class="card-header bg-info text-white">
            <h5 class="card-title mb-0"><i class="fas fa-history me-2"></i>Calculation History</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered datatable">
                    <thead>
                        <tr>
                            <th>Year</th>
                            <th>Type</th>
                            <th>Members</th>
                            <th>Total Gross</th>
                            <th>Tax (5%)</th>
                            <th>Total Net</th>
                            <th>Rate/Pool</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $history_sql = "SELECT * FROM interest_calculation_summary ORDER BY financial_year DESC, created_at DESC";
                        $history = executeQuery($history_sql);
                        while ($row = $history->fetch_assoc()):
                        ?>
                            <tr>
                                <td><?php echo $row['financial_year']; ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $row['calculation_type'] == 'interest' ? 'primary' : 'success'; ?>">
                                        <?php echo ucfirst($row['calculation_type']); ?>
                                    </span>
                                </td>
                                <td class="text-end"><?php echo number_format($row['total_members']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['total_gross']); ?></td>
                                <td class="text-end text-danger"><?php echo formatCurrency($row['total_tax']); ?></td>
                                <td class="text-end text-success"><?php echo formatCurrency($row['total_net']); ?></td>
                                <td class="text-end">
                                    <?php if ($row['calculation_type'] == 'interest'): ?>
                                        <?php echo $row['interest_rate']; ?>% / <?php echo formatCurrency($row['total_pool']); ?>
                                    <?php else: ?>
                                        Pool: <?php echo formatCurrency($row['total_pool']); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo formatDate($row['created_at']); ?></td>
                                <td>
                                    <a href="view-calculation.php?id=<?php echo $row['id']; ?>&type=<?php echo $row['calculation_type']; ?>" class="btn btn-sm btn-info">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <a href="export-calculation.php?id=<?php echo $row['id']; ?>&type=<?php echo $row['calculation_type']; ?>" class="btn btn-sm btn-success">
                                        <i class="fas fa-download"></i> Export
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php elseif ($action == 'preview' && isset($_SESSION['interest_preview'])):
    $preview = $_SESSION['interest_preview'];
    unset($_SESSION['interest_preview']);
?>
    <!-- Interest Preview Section -->
    <div class="card mb-4 border-info">
        <div class="card-header bg-info text-white">
            <h5 class="card-title mb-0"><i class="fas fa-eye me-2"></i>Interest Preview - <?php echo $preview['year']; ?></h5>
        </div>
        <div class="card-body">
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stats-card primary">
                        <div class="stats-content">
                            <h3><?php echo number_format(count($preview['data'])); ?></h3>
                            <p>Eligible Members</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card info">
                        <div class="stats-content">
                            <h3><?php echo formatCurrency($preview['total_opening_interest']); ?></h3>
                            <p>Opening Interest</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card warning">
                        <div class="stats-content">
                            <h3><?php echo formatCurrency($preview['remaining_pool']); ?></h3>
                            <p>Remaining Pool</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card success">
                        <div class="stats-content">
                            <h3><?php echo formatCurrency($preview['total_net']); ?></h3>
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
                            <th>Weighted Value</th>
                            <th>Pro-rata Interest</th>
                            <th>Gross Interest</th>
                            <th>Tax (5%)</th>
                            <th>Net Interest</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($preview['data'] as $calc): ?>
                            <tr>
                                <td><?php echo $calc['member_no']; ?></td>
                                <td><?php echo $calc['full_name']; ?></td>
                                <td class="text-end"><?php echo formatCurrency($calc['opening_balance']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($calc['opening_interest']); ?></td>
                                <td class="text-end"><?php echo number_format($calc['weighted_value'], 2); ?></td>
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
                            <th class="text-end"><?php echo formatCurrency(array_sum(array_column($preview['data'], 'opening_balance'))); ?></th>
                            <th class="text-end"><?php echo formatCurrency($preview['total_opening_interest']); ?></th>
                            <th class="text-end"><?php echo number_format($preview['total_weighted_value'], 2); ?></th>
                            <th class="text-end"><?php echo formatCurrency($preview['remaining_pool']); ?></th>
                            <th class="text-end"><?php echo formatCurrency($preview['total_gross']); ?></th>
                            <th class="text-end"><?php echo formatCurrency($preview['total_tax']); ?></th>
                            <th class="text-end"><?php echo formatCurrency($preview['total_net']); ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="text-center mt-4">
                <form method="POST" action="" style="display: inline-block;">
                    <input type="hidden" name="action" value="calculate_interest">
                    <input type="hidden" name="financial_year" value="<?php echo $preview['year']; ?>">
                    <input type="hidden" name="interest_rate" value="<?php echo $preview['interest_rate']; ?>">
                    <input type="hidden" name="total_interest_pool" value="<?php echo $preview['total_interest_pool']; ?>">
                    <button type="submit" class="btn btn-success btn-lg" onclick="return confirm('Confirm and process interest calculation?')">
                        <i class="fas fa-check-circle me-2"></i>Confirm & Process
                    </button>
                </form>
                <a href="?type=interest&action=form" class="btn btn-secondary btn-lg">
                    <i class="fas fa-edit me-2"></i>Modify Parameters
                </a>
            </div>
        </div>
    </div>

<?php elseif ($action == 'preview' && isset($_SESSION['dividend_preview'])):
    $preview = $_SESSION['dividend_preview'];
    unset($_SESSION['dividend_preview']);
?>
    <!-- Dividend Preview Section -->
    <div class="card mb-4 border-info">
        <div class="card-header bg-info text-white">
            <h5 class="card-title mb-0"><i class="fas fa-eye me-2"></i>Dividend Preview - <?php echo $preview['year']; ?></h5>
        </div>
        <div class="card-body">
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="stats-card primary">
                        <div class="stats-content">
                            <h3><?php echo number_format(count($preview['data'])); ?></h3>
                            <p>Eligible Members</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card info">
                        <div class="stats-content">
                            <h3><?php echo number_format($preview['total_weighted_shares'], 2); ?></h3>
                            <p>Total Weighted Shares</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card success">
                        <div class="stats-content">
                            <h3><?php echo formatCurrency($preview['total_net']); ?></h3>
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
                        <?php foreach ($preview['data'] as $calc): ?>
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
                            <th class="text-end"><?php echo number_format($preview['total_weighted_shares'], 2); ?></th>
                            <th class="text-end"><?php echo formatCurrency($preview['total_gross']); ?></th>
                            <th class="text-end"><?php echo formatCurrency($preview['total_tax']); ?></th>
                            <th class="text-end"><?php echo formatCurrency($preview['total_net']); ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="text-center mt-4">
                <form method="POST" action="" style="display: inline-block;">
                    <input type="hidden" name="action" value="calculate_dividend">
                    <input type="hidden" name="financial_year" value="<?php echo $preview['year']; ?>">
                    <input type="hidden" name="dividend_pool" value="<?php echo $preview['dividend_pool']; ?>">
                    <button type="submit" class="btn btn-success btn-lg" onclick="return confirm('Confirm and process dividend calculation?')">
                        <i class="fas fa-check-circle me-2"></i>Confirm & Process
                    </button>
                </form>
                <a href="?type=dividend&action=form" class="btn btn-secondary btn-lg">
                    <i class="fas fa-edit me-2"></i>Modify Parameters
                </a>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- Calculation Form -->
    <?php if ($calculation_type == 'interest'): ?>
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="card-title mb-0"><i class="fas fa-percentage me-2"></i>Interest on Member Deposits</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" class="needs-validation" novalidate>
                    <input type="hidden" name="action" value="preview_interest">

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="financial_year" class="form-label">Financial Year <span class="text-danger">*</span></label>
                            <select class="form-control" id="financial_year" name="financial_year" required>
                                <option value="">Select Year</option>
                                <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                                    <option value="<?php echo $y; ?>" <?php echo $y == $current_year - 1 ? 'selected' : ''; ?>>
                                        <?php echo $y; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="interest_rate" class="form-label">Interest Rate (%) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="interest_rate" name="interest_rate"
                                step="0.01" min="0" max="100" value="11" required>
                            <small class="text-muted">Declared rate after AGM</small>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="total_interest_pool" class="form-label">Total Interest Payable (KES) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="total_interest_pool" name="total_interest_pool"
                                step="1000" min="0" required>
                            <small class="text-muted">Declared amount after AGM</small>
                        </div>
                    </div>

                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Calculation Method:</strong>
                        <ul class="mb-0 mt-2">
                            <li>Opening balance interest: Balance × Rate</li>
                            <li>If opening interest &lt; declared pool → Pro-rata distribution of remaining pool based on monthly contributions weighted by months remaining</li>
                            <li>If opening interest ≥ declared pool → Scale down proportionally</li>
                            <li>Monthly contributions weighted: Jan=12, Feb=11, ..., Dec=1</li>
                            <li>5% withholding tax deducted</li>
                            <li>Amounts rounded to nearest 50/100/500/1000 (no coins)</li>
                        </ul>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-eye me-2"></i>Preview Interest Calculation
                    </button>
                </form>
            </div>
        </div>

    <?php else: ?>
        <div class="card mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="card-title mb-0"><i class="fas fa-chart-pie me-2"></i>Dividend on Shares</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" class="needs-validation" novalidate>
                    <input type="hidden" name="action" value="preview_dividend">

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="financial_year" class="form-label">Financial Year <span class="text-danger">*</span></label>
                            <select class="form-control" id="financial_year" name="financial_year" required>
                                <option value="">Select Year</option>
                                <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                                    <option value="<?php echo $y; ?>" <?php echo $y == $current_year - 1 ? 'selected' : ''; ?>>
                                        <?php echo $y; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="dividend_pool" class="form-label">Total Dividend Pool (KES) <span class="text-danger">*</span></label>
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
                            <li>Members who had full share from Jan: eligible for full year (12 months)</li>
                            <li>Members who completed share during year: eligible from completion month</li>
                            <li>Weighted shares = (Share Value × Eligible Months) / 12</li>
                            <li>Pro-rata distribution based on weighted shares</li>
                            <li>5% withholding tax deducted</li>
                            <li>Amounts rounded to nearest 50/100/500/1000 (no coins)</li>
                        </ul>
                    </div>

                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="fas fa-eye me-2"></i>Preview Dividend Calculation
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>
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

<style>
    .stats-card {
        cursor: default;
        transition: transform 0.2s;
    }

    .stats-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
    }

    .table td,
    .table th {
        vertical-align: middle;
    }

    .btn-group .btn.active {
        box-shadow: inset 0 0 5px rgba(0, 0, 0, 0.2);
    }

    @media (max-width: 768px) {
        .btn-group {
            flex-wrap: wrap;
        }

        .stats-card {
            margin-bottom: 10px;
        }
    }
</style>

<?php include '../../includes/footer.php'; ?>
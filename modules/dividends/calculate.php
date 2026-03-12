<?php
require_once '../../config/config.php';
requireRole('admin');

$page_title = 'Calculate Dividends';

// Get financial year
$current_year = date('Y');
$selected_year = $_GET['year'] ?? $current_year;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $financial_year = $_POST['financial_year'];
    $interest_rate = $_POST['interest_rate'];
    $withholding_tax_rate = 5; // 5% fixed

    // Check if dividends already calculated for this year
    $checkSql = "SELECT COUNT(*) as count FROM dividends WHERE financial_year = ?";
    $checkResult = executeQuery($checkSql, "s", [$financial_year]);
    $check = $checkResult->fetch_assoc();

    if ($check['count'] > 0) {
        $_SESSION['error'] = 'Dividends already calculated for this financial year';
        header('Location: index.php');
        exit();
    }

    // Get all active members
    $members = executeQuery("
        SELECT m.id, m.full_name,
               (SELECT SUM(amount) FROM deposits WHERE member_id = m.id AND transaction_type = 'deposit' AND YEAR(deposit_date) = ?) as total_deposits,
               (SELECT SUM(balance) FROM deposits WHERE member_id = m.id AND transaction_type = 'deposit' AND YEAR(deposit_date) <= ? ORDER BY deposit_date DESC LIMIT 1) as closing_balance
        FROM members m
        WHERE m.membership_status = 'active'
    ", "ss", [$financial_year, $financial_year]);

    $total_dividend = 0;

    while ($member = $members->fetch_assoc()) {
        // Calculate dividend based on average daily balance or minimum balance
        // For simplicity, using closing balance method
        $opening_balance = getOpeningBalance($member['id'], $financial_year);
        $closing_balance = $member['closing_balance'] ?? 0;
        $average_balance = ($opening_balance + $closing_balance) / 2;

        // Calculate gross dividend
        $gross_dividend = $average_balance * ($interest_rate / 100);

        // Calculate tax
        $withholding_tax = $gross_dividend * ($withholding_tax_rate / 100);
        $net_dividend = $gross_dividend - $withholding_tax;

        // Insert dividend record
        $sql = "INSERT INTO dividends (member_id, financial_year, opening_balance, total_deposits, interest_rate, 
                gross_dividend, withholding_tax, net_dividend, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'calculated', ?)";

        executeQuery($sql, "isidddddi", [
            $member['id'],
            $financial_year,
            $opening_balance,
            $member['total_deposits'] ?? 0,
            $interest_rate,
            $gross_dividend,
            $withholding_tax,
            $net_dividend,
            getCurrentUserId()
        ]);

        $total_dividend += $net_dividend;
    }

    logAudit('CALCULATE', 'dividends', 0, null, ['year' => $financial_year, 'rate' => $interest_rate]);

    $_SESSION['success'] = 'Dividends calculated successfully. Total payout: ' . formatCurrency($total_dividend);
    header('Location: index.php');
    exit();
}

function getOpeningBalance($member_id, $year)
{
    // Get balance at start of year
    $result = executeQuery("
        SELECT balance FROM deposits 
        WHERE member_id = ? AND deposit_date < ?
        ORDER BY deposit_date DESC 
        LIMIT 1
    ", "is", [$member_id, $year . '-01-01']);

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['balance'];
    }

    return 0;
}

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Calculate Dividends</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Dividends</a></li>
                <li class="breadcrumb-item active">Calculate</li>
            </ul>
        </div>
    </div>
</div>

<!-- Calculation Form -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title">Dividend Calculation Parameters</h5>
    </div>
    <div class="card-body">
        <form method="POST" action="" class="needs-validation" novalidate>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="financial_year" class="form-label">Financial Year <span class="text-danger">*</span></label>
                    <select class="form-control" id="financial_year" name="financial_year" required>
                        <option value="">-- Select Year --</option>
                        <?php for ($y = $current_year; $y >= $current_year - 5; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $selected_year ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                    <div class="invalid-feedback">Please select financial year</div>
                </div>

                <div class="col-md-6 mb-3">
                    <label for="interest_rate" class="form-label">Dividend Rate (%) <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" id="interest_rate" name="interest_rate"
                        min="0" max="100" step="0.01" required
                        value="8.68">
                    <div class="invalid-feedback">Please enter dividend rate</div>
                    <small class="text-muted">Enter the rate declared during AGM</small>
                </div>
            </div>

            <div class="alert alert-info">
                <i class="fas fa-calculator me-2"></i>
                <strong>Calculation Method:</strong> Average Daily Balance Method
                <br>
                <small>Dividend = Average Daily Balance × (Rate/100)</small>
                <br>
                <small>Withholding Tax: 5% will be automatically applied</small>
            </div>

            <hr>

            <button type="submit" class="btn btn-primary" onclick="return confirmAction('This will calculate dividends for all members. Proceed?')">
                <i class="fas fa-calculator me-2"></i>Calculate Dividends
            </button>
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-times me-2"></i>Cancel
            </a>
        </form>
    </div>
</div>

<!-- Sample Calculation Preview -->
<div class="card mt-4">
    <div class="card-header">
        <h5 class="card-title">Sample Calculation</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <h6>Opening Balance Method:</h6>
                <table class="table table-sm">
                    <tr>
                        <td>Opening Balance (OB)</td>
                        <td>= 100,000</td>
                    </tr>
                    <tr>
                        <td>Interest (8.68%)</td>
                        <td>= 8,680</td>
                    </tr>
                </table>

                <h6 class="mt-3">Monthly Contribution Dividend:</h6>
                <table class="table table-sm">
                    <tr>
                        <td>Monthly Contribution</td>
                        <td>= 2,000</td>
                    </tr>
                    <tr>
                        <td>Total from MC</td>
                        <td>= 954.80</td>
                    </tr>
                </table>
            </div>

            <div class="col-md-6">
                <h6>Total Calculation:</h6>
                <table class="table table-sm">
                    <tr>
                        <td>Dividend from OB</td>
                        <td>8,680.00</td>
                    </tr>
                    <tr>
                        <td>Dividend from MC</td>
                        <td>954.80</td>
                    </tr>
                    <tr>
                        <td><strong>Gross Dividend</strong></td>
                        <td><strong>9,634.80</strong></td>
                    </tr>
                    <tr>
                        <td>Withholding Tax (5%)</td>
                        <td>481.74</td>
                    </tr>
                    <tr>
                        <td><strong>Net Dividend</strong></td>
                        <td><strong>9,153.06</strong></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
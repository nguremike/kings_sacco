<?php
// modules/accounting/view-calculation.php
require_once '../../config/config.php';
requireLogin();

$calculation_id = $_GET['id'] ?? 0;
$calculation_type = $_GET['type'] ?? 'interest'; // interest or dividend

if (!$calculation_id) {
    $_SESSION['error'] = 'Calculation ID not provided';
    header('Location: interest-dividend-calculation.php?type=' . $calculation_type . '&action=reports');
    exit();
}

// Get calculation summary
$summary_sql = "SELECT * FROM interest_calculation_summary WHERE id = ? AND calculation_type = ?";
$summary_result = executeQuery($summary_sql, "is", [$calculation_id, $calculation_type]);

if ($summary_result->num_rows == 0) {
    $_SESSION['error'] = 'Calculation record not found';
    header('Location: interest-dividend-calculation.php?type=' . $calculation_type . '&action=reports');
    exit();
}

$summary = $summary_result->fetch_assoc();

// Get detailed calculations
$details_sql = "SELECT ic.*, m.member_no, m.full_name, m.phone, m.email
                FROM interest_calculations ic
                JOIN members m ON ic.member_id = m.id
                WHERE ic.financial_year = ? AND ic.calculation_type = ?
                ORDER BY ic.net_interest DESC";
$details = executeQuery($details_sql, "is", [$summary['financial_year'], $calculation_type]);

$page_title = ucfirst($calculation_type) . ' Calculation Details - ' . $summary['financial_year'];

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">
                <?php echo ucfirst($calculation_type); ?> Calculation Details
                <small class="text-muted"><?php echo $summary['financial_year']; ?></small>
            </h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="interest-dividend-calculation.php">Interest & Dividend</a></li>
                <li class="breadcrumb-item"><a href="interest-dividend-calculation.php?type=<?php echo $calculation_type; ?>&action=reports">Reports</a></li>
                <li class="breadcrumb-item active">View Calculation</li>
            </ul>
        </div>
        <div class="col-auto">
            <button class="btn btn-primary" onclick="window.print()">
                <i class="fas fa-print me-2"></i>Print
            </button>
            <a href="export-calculation.php?id=<?php echo $calculation_id; ?>&type=<?php echo $calculation_type; ?>" class="btn btn-success">
                <i class="fas fa-file-excel me-2"></i>Export to Excel
            </a>
            <a href="interest-dividend-calculation.php?type=<?php echo $calculation_type; ?>&action=reports" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Reports
            </a>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="stats-card primary">
            <div class="stats-icon">
                <i class="fas fa-users"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo number_format($summary['total_members']); ?></h3>
                <p>Total Members</p>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card success">
            <div class="stats-icon">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($summary['total_gross']); ?></h3>
                <p>Gross <?php echo ucfirst($calculation_type); ?></p>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card danger">
            <div class="stats-icon">
                <i class="fas fa-percentage"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($summary['total_tax']); ?></h3>
                <p>Withholding Tax (5%)</p>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card info">
            <div class="stats-icon">
                <i class="fas fa-money-bill-wave"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($summary['total_net']); ?></h3>
                <p>Net Payout</p>
            </div>
        </div>
    </div>
</div>

<!-- Calculation Parameters Card -->
<div class="card mb-4">
    <div class="card-header bg-info text-white">
        <h5 class="card-title mb-0"><i class="fas fa-sliders-h me-2"></i>Calculation Parameters</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3">
                <p><strong>Financial Year:</strong><br> <?php echo $summary['financial_year']; ?></p>
            </div>
            <div class="col-md-3">
                <p><strong>Calculation Type:</strong><br>
                    <span class="badge bg-<?php echo $calculation_type == 'interest' ? 'primary' : 'success'; ?>">
                        <?php echo ucfirst($calculation_type); ?>
                    </span>
                </p>
            </div>
            <div class="col-md-3">
                <?php if ($calculation_type == 'interest'): ?>
                    <p><strong>Interest Rate:</strong><br> <?php echo $summary['interest_rate']; ?>% p.a.</p>
                    <p><strong>Total Pool:</strong><br> <?php echo formatCurrency($summary['total_pool']); ?></p>
                <?php else: ?>
                    <p><strong>Dividend Pool:</strong><br> <?php echo formatCurrency($summary['total_pool']); ?></p>
                <?php endif; ?>
            </div>
            <div class="col-md-3">
                <p><strong>Calculated On:</strong><br> <?php echo formatDate($summary['created_at']); ?></p>
                <p><strong>Calculated By:</strong><br>
                    <?php
                    $user_sql = "SELECT full_name FROM users WHERE id = ?";
                    $user_result = executeQuery($user_sql, "i", [$summary['created_by']]);
                    $user = $user_result->fetch_assoc();
                    echo $user['full_name'] ?? 'System';
                    ?>
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Summary Statistics Card -->
<div class="card mb-4">
    <div class="card-header bg-secondary text-white">
        <h5 class="card-title mb-0"><i class="fas fa-chart-bar me-2"></i>Summary Statistics</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
                <div class="border p-3 text-center">
                    <small class="text-muted">Average <?php echo ucfirst($calculation_type); ?></small>
                    <h4><?php echo formatCurrency($summary['total_net'] / $summary['total_members']); ?></h4>
                </div>
            </div>
            <div class="col-md-4">
                <div class="border p-3 text-center">
                    <small class="text-muted">Minimum <?php echo ucfirst($calculation_type); ?></small>
                    <h4 class="text-danger">
                        <?php
                        $min_sql = "SELECT net_interest FROM interest_calculations WHERE financial_year = ? AND calculation_type = ? ORDER BY net_interest ASC LIMIT 1";
                        $min_result = executeQuery($min_sql, "is", [$summary['financial_year'], $calculation_type]);
                        $min = $min_result->fetch_assoc();
                        echo formatCurrency($min['net_interest'] ?? 0);
                        ?>
                    </h4>
                </div>
            </div>
            <div class="col-md-4">
                <div class="border p-3 text-center">
                    <small class="text-muted">Maximum <?php echo ucfirst($calculation_type); ?></small>
                    <h4 class="text-success">
                        <?php
                        $max_sql = "SELECT net_interest FROM interest_calculations WHERE financial_year = ? AND calculation_type = ? ORDER BY net_interest DESC LIMIT 1";
                        $max_result = executeQuery($max_sql, "is", [$summary['financial_year'], $calculation_type]);
                        $max = $max_result->fetch_assoc();
                        echo formatCurrency($max['net_interest'] ?? 0);
                        ?>
                    </h4>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Interest Specific Details -->
<?php if ($calculation_type == 'interest'): ?>
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="card-title mb-0"><i class="fas fa-calculator me-2"></i>Interest Calculation Breakdown</h5>
        </div>
        <div class="card-body">
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
                        <?php
                        $total_opening = 0;
                        $total_opening_interest = 0;
                        $total_weighted = 0;
                        $total_pro_rata = 0;
                        $total_gross = 0;
                        $total_tax = 0;
                        $total_net = 0;

                        while ($row = $details->fetch_assoc()):
                            $total_opening += $row['opening_balance'];
                            $total_opening_interest += $row['opening_interest'];
                            $total_weighted += $row['weighted_value'];
                            $total_pro_rata += $row['pro_rata_interest'];
                            $total_gross += $row['gross_interest'];
                            $total_tax += $row['withholding_tax'];
                            $total_net += $row['net_interest'];
                        ?>
                            <tr>
                                <td><?php echo $row['member_no']; ?></td>
                                <td>
                                    <a href="../members/view.php?id=<?php echo $row['member_id']; ?>">
                                        <?php echo $row['full_name']; ?>
                                    </a>
                                </td>
                                <td class="text-end"><?php echo formatCurrency($row['opening_balance']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['opening_interest']); ?></td>
                                <td class="text-end"><?php echo number_format($row['weighted_value'], 2); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['pro_rata_interest']); ?></td>
                                <td class="text-end fw-bold"><?php echo formatCurrency($row['gross_interest']); ?></td>
                                <td class="text-end text-danger"><?php echo formatCurrency($row['withholding_tax']); ?></td>
                                <td class="text-end text-success fw-bold"><?php echo formatCurrency($row['net_interest']); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-info">
                            <th colspan="2" class="text-end">Totals:</th>
                            <th class="text-end"><?php echo formatCurrency($total_opening); ?></th>
                            <th class="text-end"><?php echo formatCurrency($total_opening_interest); ?></th>
                            <th class="text-end"><?php echo number_format($total_weighted, 2); ?></th>
                            <th class="text-end"><?php echo formatCurrency($total_pro_rata); ?></th>
                            <th class="text-end"><?php echo formatCurrency($total_gross); ?></th>
                            <th class="text-end"><?php echo formatCurrency($total_tax); ?></th>
                            <th class="text-end"><?php echo formatCurrency($total_net); ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Dividend Specific Details -->
<?php if ($calculation_type == 'dividend'): ?>
    <div class="card mb-4">
        <div class="card-header bg-success text-white">
            <h5 class="card-title mb-0"><i class="fas fa-chart-pie me-2"></i>Dividend Calculation Breakdown</h5>
        </div>
        <div class="card-body">
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
                        <?php
                        $total_shares = 0;
                        $total_value = 0;
                        $total_weighted = 0;
                        $total_gross = 0;
                        $total_tax = 0;
                        $total_net = 0;

                        while ($row = $details->fetch_assoc()):
                            $total_shares += $row['full_shares'];
                            $total_value += $row['total_share_value'];
                            $total_weighted += $row['weighted_shares'];
                            $total_gross += $row['gross_dividend'];
                            $total_tax += $row['withholding_tax'];
                            $total_net += $row['net_dividend'];
                        ?>
                            <tr>
                                <td><?php echo $row['member_no']; ?></td>
                                <td>
                                    <a href="../members/view.php?id=<?php echo $row['member_id']; ?>">
                                        <?php echo $row['full_name']; ?>
                                    </a>
                                </td>
                                <td class="text-end"><?php echo number_format($row['full_shares']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['total_share_value']); ?></td>
                                <td class="text-end"><?php echo $row['eligible_months']; ?> months</td>
                                <td class="text-end"><?php echo number_format($row['weighted_shares'], 2); ?></td>
                                <td class="text-end fw-bold"><?php echo formatCurrency($row['gross_dividend']); ?></td>
                                <td class="text-end text-danger"><?php echo formatCurrency($row['withholding_tax']); ?></td>
                                <td class="text-end text-success fw-bold"><?php echo formatCurrency($row['net_dividend']); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-info">
                            <th colspan="2" class="text-end">Totals:</th>
                            <th class="text-end"><?php echo number_format($total_shares); ?></th>
                            <th class="text-end"><?php echo formatCurrency($total_value); ?></th>
                            <th class="text-end"></th>
                            <th class="text-end"><?php echo number_format($total_weighted, 2); ?></th>
                            <th class="text-end"><?php echo formatCurrency($total_gross); ?></th>
                            <th class="text-end"><?php echo formatCurrency($total_tax); ?></th>
                            <th class="text-end"><?php echo formatCurrency($total_net); ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Top 10 Members Section -->
<div class="card mb-4">
    <div class="card-header bg-warning">
        <h5 class="card-title mb-0"><i class="fas fa-trophy me-2"></i>Top 10 Members by <?php echo ucfirst($calculation_type); ?></h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Member No</th>
                        <th>Member Name</th>
                        <th>Phone</th>
                        <th>Amount</th>
                        <th>Percentage</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $top_sql = "SELECT ic.*, m.member_no, m.full_name, m.phone
                                FROM interest_calculations ic
                                JOIN members m ON ic.member_id = m.id
                                WHERE ic.financial_year = ? AND ic.calculation_type = ?
                                ORDER BY ic.net_interest DESC
                                LIMIT 10";
                    $top_result = executeQuery($top_sql, "is", [$summary['financial_year'], $calculation_type]);
                    $rank = 1;
                    while ($top = $top_result->fetch_assoc()):
                        $percentage = ($summary['total_net'] > 0) ? ($top['net_interest'] / $summary['total_net']) * 100 : 0;
                    ?>
                        <tr>
                            <td><strong>#<?php echo $rank++; ?></strong></td>
                            <td><?php echo $top['member_no']; ?></td>
                            <td><?php echo $top['full_name']; ?></td>
                            <td><?php echo $top['phone']; ?></td>
                            <td class="text-end fw-bold text-success"><?php echo formatCurrency($top['net_interest']); ?></td>
                            <td class="text-end">
                                <div class="progress" style="height: 20px;">
                                    <div class="progress-bar bg-success" role="progressbar"
                                        style="width: <?php echo $percentage; ?>%;"
                                        aria-valuenow="<?php echo $percentage; ?>"
                                        aria-valuemin="0"
                                        aria-valuemax="100">
                                        <?php echo number_format($percentage, 1); ?>%
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Distribution Chart -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title">Distribution Analysis</h5>
    </div>
    <div class="card-body">
        <canvas id="distributionChart" height="100"></canvas>
    </div>
</div>

<script>
    // Distribution Chart
    document.addEventListener('DOMContentLoaded', function() {
        var ranges = ['0-1,000', '1,000-5,000', '5,000-10,000', '10,000-20,000', '20,000+'];
        var counts = [];

        <?php
        $range1 = executeQuery("SELECT COUNT(*) as count FROM interest_calculations WHERE financial_year = ? AND calculation_type = ? AND net_interest BETWEEN 0 AND 1000", "is", [$summary['financial_year'], $calculation_type])->fetch_assoc()['count'];
        $range2 = executeQuery("SELECT COUNT(*) as count FROM interest_calculations WHERE financial_year = ? AND calculation_type = ? AND net_interest BETWEEN 1001 AND 5000", "is", [$summary['financial_year'], $calculation_type])->fetch_assoc()['count'];
        $range3 = executeQuery("SELECT COUNT(*) as count FROM interest_calculations WHERE financial_year = ? AND calculation_type = ? AND net_interest BETWEEN 5001 AND 10000", "is", [$summary['financial_year'], $calculation_type])->fetch_assoc()['count'];
        $range4 = executeQuery("SELECT COUNT(*) as count FROM interest_calculations WHERE financial_year = ? AND calculation_type = ? AND net_interest BETWEEN 10001 AND 20000", "is", [$summary['financial_year'], $calculation_type])->fetch_assoc()['count'];
        $range5 = executeQuery("SELECT COUNT(*) as count FROM interest_calculations WHERE financial_year = ? AND calculation_type = ? AND net_interest > 20000", "is", [$summary['financial_year'], $calculation_type])->fetch_assoc()['count'];
        ?>

        counts.push(<?php echo $range1; ?>);
        counts.push(<?php echo $range2; ?>);
        counts.push(<?php echo $range3; ?>);
        counts.push(<?php echo $range4; ?>);
        counts.push(<?php echo $range5; ?>);

        var ctx = document.getElementById('distributionChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ranges,
                datasets: [{
                    label: 'Number of Members',
                    data: counts,
                    backgroundColor: 'rgba(40, 167, 69, 0.5)',
                    borderColor: 'rgb(40, 167, 69)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Number of Members'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Amount Range (KES)'
                        }
                    }
                }
            }
        });
    });
</script>

<style>
    .stats-card {
        margin-bottom: 15px;
    }

    .border {
        border: 1px solid #dee2e6 !important;
        border-radius: 5px;
        background: #f8f9fa;
    }

    .table td,
    .table th {
        vertical-align: middle;
    }

    .progress {
        background-color: #e9ecef;
        border-radius: 10px;
    }

    .progress-bar {
        border-radius: 10px;
        font-size: 11px;
        line-height: 20px;
    }

    @media print {

        .sidebar,
        .navbar,
        .breadcrumb,
        .page-header .col-auto,
        .card-header .btn,
        .footer,
        .btn,
        .no-print {
            display: none !important;
        }

        .main-content {
            margin-left: 0 !important;
            padding: 10px !important;
        }

        .card {
            box-shadow: none !important;
            border: 1px solid #ddd !important;
            break-inside: avoid;
        }
    }
</style>

<?php include '../../includes/footer.php'; ?>
<?php
// modules/dividends/index.php
require_once '../../config/config.php';
requireLogin();

$page_title = 'Dividend Management';

// Get filter year
$year = $_GET['year'] ?? date('Y');

// Get dividend summary by year and method
$dividends_sql = "SELECT d.*, m.full_name, m.member_no,
                 CASE 
                     WHEN d.calculation_method = 'kenya_sacco_pro_rata' THEN 'Kenya SACCO Pro-rata'
                     WHEN d.calculation_method = 'pro_rata' THEN 'Standard Pro-rata'
                     ELSE 'Standard'
                 END as method_name
                 FROM dividends d
                 JOIN members m ON d.member_id = m.id
                 WHERE d.financial_year = ?
                 ORDER BY d.net_dividend DESC";
$dividends = executeQuery($dividends_sql, "s", [$year]);

// Get summary statistics by method
$summary_sql = "SELECT 
                calculation_method,
                COUNT(*) as member_count,
                SUM(gross_dividend) as total_gross,
                SUM(withholding_tax) as total_tax,
                SUM(net_dividend) as total_net,
                AVG(net_dividend) as avg_dividend,
                MIN(net_dividend) as min_dividend,
                MAX(net_dividend) as max_dividend
                FROM dividends
                WHERE financial_year = ?
                GROUP BY calculation_method";
$summary_result = executeQuery($summary_sql, "s", [$year]);

$summaries = [];
while ($row = $summary_result->fetch_assoc()) {
    $summaries[$row['calculation_method']] = $row;
}

// Get yearly totals for chart
$yearly_sql = "SELECT 
               financial_year,
               SUM(net_dividend) as total_payout,
               COUNT(*) as member_count
               FROM dividends
               GROUP BY financial_year
               ORDER BY financial_year DESC
               LIMIT 5";
$yearly = executeQuery($yearly_sql);

// Get top 10 dividend earners for the year
$top_sql = "SELECT d.*, m.full_name, m.member_no
            FROM dividends d
            JOIN members m ON d.member_id = m.id
            WHERE d.financial_year = ?
            ORDER BY d.net_dividend DESC
            LIMIT 10";
$top_earners = executeQuery($top_sql, "s", [$year]);

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Dividend Management</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item active">Dividends</li>
            </ul>
        </div>
        <div class="col-auto">
            <div class="btn-group">
                <button type="button" class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="fas fa-calculator me-2"></i>Calculate Dividends
                </button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="calculate.php">
                            <i class="fas fa-calculator"></i> Standard Method
                        </a></li>
                    <li><a class="dropdown-item" href="calculate-pro-rata.php">
                            <i class="fas fa-chart-line"></i> Kenya SACCO Pro-rata
                            <span class="badge bg-success ms-2">Recommended</span>
                        </a></li>
                </ul>
            </div>
            <a href="payments.php" class="btn btn-success">
                <i class="fas fa-money-bill-wave me-2"></i>Dividend Payments
            </a>
        </div>
    </div>
</div>

<!-- Alert Messages -->
<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?php
        echo nl2br($_SESSION['success']);
        unset($_SESSION['success']);
        ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

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

<!-- Year Selector and Summary -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="year" class="form-label">Select Financial Year</label>
                <select class="form-control" id="year" name="year" onchange="this.form.submit()">
                    <?php
                    $years = executeQuery("SELECT DISTINCT financial_year FROM dividends ORDER BY financial_year DESC");
                    if ($years->num_rows > 0):
                        while ($y = $years->fetch_assoc()):
                    ?>
                            <option value="<?php echo $y['financial_year']; ?>" <?php echo $y['financial_year'] == $year ? 'selected' : ''; ?>>
                                <?php echo $y['financial_year']; ?>
                            </option>
                        <?php
                        endwhile;
                    else:
                        ?>
                        <option value="<?php echo date('Y'); ?>"><?php echo date('Y'); ?></option>
                    <?php endif; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<!-- Method Summary Cards -->
<div class="row mb-4">
    <?php foreach ($summaries as $method => $summary): ?>
        <div class="col-md-6">
            <div class="card <?php echo $method == 'kenya_sacco_pro_rata' ? 'border-success' : 'border-info'; ?>">
                <div class="card-header <?php echo $method == 'kenya_sacco_pro_rata' ? 'bg-success text-white' : 'bg-info text-white'; ?>">
                    <h5 class="card-title mb-0">
                        <?php echo $method == 'kenya_sacco_pro_rata' ? 'Kenya SACCO Pro-rata' : 'Standard Method'; ?>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-6">
                            <p class="text-muted mb-1">Members</p>
                            <h4><?php echo number_format($summary['member_count']); ?></h4>
                        </div>
                        <div class="col-6">
                            <p class="text-muted mb-1">Total Gross</p>
                            <h4><?php echo formatCurrency($summary['total_gross']); ?></h4>
                        </div>
                        <div class="col-6">
                            <p class="text-muted mb-1">Withholding Tax (5%)</p>
                            <h4 class="text-danger"><?php echo formatCurrency($summary['total_tax']); ?></h4>
                        </div>
                        <div class="col-6">
                            <p class="text-muted mb-1">Net Payout</p>
                            <h4 class="text-success"><?php echo formatCurrency($summary['total_net']); ?></h4>
                        </div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-4">
                            <small class="text-muted">Average</small>
                            <h6><?php echo formatCurrency($summary['avg_dividend']); ?></h6>
                        </div>
                        <div class="col-4">
                            <small class="text-muted">Minimum</small>
                            <h6><?php echo formatCurrency($summary['min_dividend']); ?></h6>
                        </div>
                        <div class="col-4">
                            <small class="text-muted">Maximum</small>
                            <h6><?php echo formatCurrency($summary['max_dividend']); ?></h6>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Yearly Trend Chart -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title">Yearly Dividend Payout Trend</h5>
    </div>
    <div class="card-body">
        <canvas id="yearlyChart" height="100"></canvas>
    </div>
</div>

<!-- Top 10 Earners -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title">Top 10 Dividend Earners - <?php echo $year; ?></h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Member No</th>
                        <th>Member Name</th>
                        <th>Opening Balance</th>
                        <th>Total Deposits</th>
                        <th>Gross Dividend</th>
                        <th>Tax (5%)</th>
                        <th>Net Dividend</th>
                        <th>Method</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $rank = 1;
                    while ($div = $top_earners->fetch_assoc()):
                    ?>
                        <tr>
                            <td><strong>#<?php echo $rank++; ?></strong></td>
                            <td><?php echo $div['member_no']; ?></td>
                            <td><?php echo $div['full_name']; ?></td>
                            <td><?php echo formatCurrency($div['opening_balance']); ?></td>
                            <td><?php echo formatCurrency($div['total_deposits']); ?></td>
                            <td><?php echo formatCurrency($div['gross_dividend']); ?></td>
                            <td class="text-danger"><?php echo formatCurrency($div['withholding_tax']); ?></td>
                            <td class="text-success fw-bold"><?php echo formatCurrency($div['net_dividend']); ?></td>
                            <td>
                                <?php if ($div['calculation_method'] == 'kenya_sacco_pro_rata'): ?>
                                    <span class="badge bg-success">Kenya Pro-rata</span>
                                <?php elseif ($div['calculation_method'] == 'pro_rata'): ?>
                                    <span class="badge bg-info">Pro-rata</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Standard</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="voucher.php?id=<?php echo $div['id']; ?>" class="btn btn-sm btn-outline-info">
                                    <i class="fas fa-file-invoice"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- All Dividends Table -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title">Dividend Details - <?php echo $year; ?></h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-striped datatable">
                <thead>
                    <tr>
                        <th>Member No</th>
                        <th>Member Name</th>
                        <th>Opening Balance</th>
                        <th>Total Deposits</th>
                        <th>Gross Dividend</th>
                        <th>Tax (5%)</th>
                        <th>Net Dividend</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($div = $dividends->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo $div['member_no']; ?></td>
                            <td><?php echo $div['full_name']; ?></td>
                            <td><?php echo formatCurrency($div['opening_balance']); ?></td>
                            <td><?php echo formatCurrency($div['total_deposits']); ?></td>
                            <td><?php echo formatCurrency($div['gross_dividend']); ?></td>
                            <td><?php echo formatCurrency($div['withholding_tax']); ?></td>
                            <td class="fw-bold"><?php echo formatCurrency($div['net_dividend']); ?></td>
                            <td>
                                <?php if ($div['calculation_method'] == 'kenya_sacco_pro_rata'): ?>
                                    <span class="badge bg-success">Kenya Pro-rata</span>
                                <?php elseif ($div['calculation_method'] == 'pro_rata'): ?>
                                    <span class="badge bg-info">Pro-rata</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Standard</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($div['status'] == 'paid'): ?>
                                    <span class="badge bg-success">Paid</span>
                                <?php elseif ($div['status'] == 'approved'): ?>
                                    <span class="badge bg-primary">Approved</span>
                                <?php else: ?>
                                    <span class="badge bg-warning">Calculated</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <a href="voucher.php?id=<?php echo $div['id']; ?>" class="btn btn-sm btn-outline-info" title="View Voucher">
                                        <i class="fas fa-file-invoice"></i>
                                    </a>
                                    <?php if ($div['status'] == 'calculated' && hasRole('admin')): ?>
                                        <a href="approve.php?id=<?php echo $div['id']; ?>" class="btn btn-sm btn-outline-success" title="Approve">
                                            <i class="fas fa-check"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    // Yearly Chart
    document.addEventListener('DOMContentLoaded', function() {
        var years = [];
        var payouts = [];
        var memberCounts = [];

        <?php
        $yearly->data_seek(0);
        while ($row = $yearly->fetch_assoc()):
        ?>
            years.push('<?php echo $row['financial_year']; ?>');
            payouts.push(<?php echo $row['total_payout'] / 1000; ?>); // In thousands
            memberCounts.push(<?php echo $row['member_count']; ?>);
        <?php endwhile; ?>

        if (years.length > 0) {
            var ctx = document.getElementById('yearlyChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: years,
                    datasets: [{
                        label: 'Total Payout (KES Thousands)',
                        data: payouts,
                        borderColor: '#28a745',
                        backgroundColor: 'rgba(40, 167, 69, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4,
                        yAxisID: 'y-payout'
                    }, {
                        label: 'Number of Members',
                        data: memberCounts,
                        borderColor: '#17a2b8',
                        backgroundColor: 'rgba(23, 162, 184, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4,
                        yAxisID: 'y-members'
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        'y-payout': {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            title: {
                                display: true,
                                text: 'Payout (KES Thousands)'
                            }
                        },
                        'y-members': {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            title: {
                                display: true,
                                text: 'Number of Members'
                            },
                            grid: {
                                drawOnChartArea: false
                            }
                        }
                    }
                }
            });
        }
    });
</script>

<style>
    .card-header {
        font-weight: 600;
    }

    .badge.bg-success {
        background-color: #28a745 !important;
    }

    .table td {
        vertical-align: middle;
    }

    .btn-group .btn {
        margin-right: 2px;
    }

    @media (max-width: 768px) {
        .btn-group {
            display: flex;
            flex-direction: column;
        }

        .btn-group .btn {
            margin-right: 0;
            margin-bottom: 2px;
        }
    }
</style>

<?php include '../../includes/footer.php'; ?>
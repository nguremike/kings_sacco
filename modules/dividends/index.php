<?php
require_once '../../config/config.php';
requireLogin();

$page_title = 'Dividend Management';

// Get dividends by year
$year = $_GET['year'] ?? date('Y');

$dividends = executeQuery("
    SELECT d.*, m.full_name, m.member_no
    FROM dividends d
    JOIN members m ON d.member_id = m.id
    WHERE d.financial_year = ?
    ORDER BY d.net_dividend DESC
", "s", [$year]);

// Get summary
$summary = executeQuery("
    SELECT 
        COUNT(*) as total_members,
        SUM(gross_dividend) as total_gross,
        SUM(withholding_tax) as total_tax,
        SUM(net_dividend) as total_net,
        AVG(net_dividend) as avg_dividend
    FROM dividends
    WHERE financial_year = ?
", "s", [$year])->fetch_assoc();

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
            <a href="calculate.php" class="btn btn-primary">
                <i class="fas fa-calculator me-2"></i>Calculate Dividends
            </a>
            <button class="btn btn-success" onclick="exportDividends()">
                <i class="fas fa-file-excel me-2"></i>Export
            </button>
        </div>
    </div>
</div>

<!-- Year Selector -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="year" class="form-label">Select Financial Year</label>
                <select class="form-control" id="year" name="year" onchange="this.form.submit()">
                    <?php
                    $years = executeQuery("SELECT DISTINCT financial_year FROM dividends ORDER BY financial_year DESC");
                    while ($y = $years->fetch_assoc()):
                    ?>
                        <option value="<?php echo $y['financial_year']; ?>" <?php echo $y['financial_year'] == $year ? 'selected' : ''; ?>>
                            <?php echo $y['financial_year']; ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
        </form>
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
                <h3><?php echo number_format($summary['total_members'] ?? 0); ?></h3>
                <p>Members Qualified</p>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card success">
            <div class="stats-icon">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($summary['total_gross'] ?? 0); ?></h3>
                <p>Gross Dividend</p>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card warning">
            <div class="stats-icon">
                <i class="fas fa-percentage"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($summary['total_tax'] ?? 0); ?></h3>
                <p>Withholding Tax</p>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card danger">
            <div class="stats-icon">
                <i class="fas fa-hand-holding-usd"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($summary['total_net'] ?? 0); ?></h3>
                <p>Net Payout</p>
            </div>
        </div>
    </div>
</div>

<!-- Dividends Table -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title">Dividend Details - <?php echo $year; ?></h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable">
                <thead>
                    <tr>
                        <th>Member No</th>
                        <th>Member Name</th>
                        <th>Opening Balance</th>
                        <th>Total Deposits</th>
                        <th>Gross Dividend</th>
                        <th>Tax (5%)</th>
                        <th>Net Dividend</th>
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
                            <td><strong><?php echo formatCurrency($div['net_dividend']); ?></strong></td>
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
                                        <i class="fas fa-file-pdf"></i>
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
                <tfoot>
                    <tr class="table-info">
                        <th colspan="4" class="text-end">Totals:</th>
                        <th><?php echo formatCurrency($summary['total_gross'] ?? 0); ?></th>
                        <th><?php echo formatCurrency($summary['total_tax'] ?? 0); ?></th>
                        <th><?php echo formatCurrency($summary['total_net'] ?? 0); ?></th>
                        <th colspan="2"></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<script>
    function exportDividends() {
        window.location.href = 'export.php?year=<?php echo $year; ?>';
    }
</script>

<?php include '../../includes/footer.php'; ?>
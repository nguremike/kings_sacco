<?php
// modules/accounting/trial-balance.php
require_once '../../config/config.php';
require_once '../../includes/accounting_functions.php';
requireLogin();

$page_title = 'Trial Balance';

$as_at_date = $_GET['as_at'] ?? date('Y-m-d');
$conn = getConnection();
$trial_balance = getTrialBalance($conn, $as_at_date);

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Trial Balance</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Accounting</a></li>
                <li class="breadcrumb-item active">Trial Balance</li>
            </ul>
        </div>
        <div class="col-auto">
            <button class="btn btn-primary" onclick="window.print()">
                <i class="fas fa-print me-2"></i>Print
            </button>
        </div>
    </div>
</div>

<!-- Date Filter -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row">
            <div class="col-md-4">
                <label>As at Date</label>
                <input type="date" name="as_at" class="form-control" value="<?php echo $as_at_date; ?>">
            </div>
            <div class="col-md-2">
                <label>&nbsp;</label>
                <button type="submit" class="btn btn-primary d-block w-100">Generate</button>
            </div>
        </form>
    </div>
</div>

<!-- Trial Balance Table -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title">Trial Balance as at <?php echo formatDate($as_at_date); ?></h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>Account Code</th>
                        <th>Account Name</th>
                        <th>Account Type</th>
                        <th class="text-end">Debit (KES)</th>
                        <th class="text-end">Credit (KES)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $total_debit = 0;
                    $total_credit = 0;
                    while ($row = $trial_balance->fetch_assoc()):
                        $debit = $row['balance'] > 0 && $row['normal_balance'] == 'debit' ? $row['balance'] : 0;
                        $credit = $row['balance'] > 0 && $row['normal_balance'] == 'credit' ? $row['balance'] : 0;
                        $total_debit += $debit;
                        $total_credit += $credit;
                    ?>
                        <tr>
                            <td><?php echo $row['account_code']; ?></td>
                            <td><?php echo $row['account_name']; ?></td>
                            <td><?php echo ucfirst($row['account_type']); ?></td>
                            <td class="text-end"><?php echo $debit > 0 ? number_format($debit, 2) : '-'; ?></td>
                            <td class="text-end"><?php echo $credit > 0 ? number_format($credit, 2) : '-'; ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
                <tfoot>
                    <tr class="table-info fw-bold">
                        <td colspan="3" class="text-end">Totals:</td>
                        <td class="text-end"><?php echo number_format($total_debit, 2); ?></td>
                        <td class="text-end"><?php echo number_format($total_credit, 2); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
<?php
require_once '../../config/config.php';
requireLogin();

$page_title = 'Deposit Statements';

// Get filter parameters
$member_id = $_GET['member_id'] ?? '';
$date_from = $_GET['from'] ?? date('Y-m-d', strtotime('-3 months'));
$date_to = $_GET['to'] ?? date('Y-m-d');
$transaction_type = $_GET['type'] ?? 'all';

// First, let's detect the actual column names in deposits table
$columns_result = executeQuery("SHOW COLUMNS FROM deposits");
$columns = [];
while ($col = $columns_result->fetch_assoc()) {
    $columns[] = $col['Field'];
}

// Determine column names based on common variations
$member_id_col = in_array('member_id', $columns) ? 'member_id' : (in_array('memberid', $columns) ? 'memberid' : (in_array('memberId', $columns) ? 'memberId' : 'member_id'));

$date_col = in_array('deposit_date', $columns) ? 'deposit_date' : (in_array('transaction_date', $columns) ? 'transaction_date' : (in_array('date', $columns) ? 'date' : 'deposit_date'));

$type_col = in_array('transaction_type', $columns) ? 'transaction_type' : (in_array('type', $columns) ? 'type' : (in_array('trans_type', $columns) ? 'trans_type' : 'transaction_type'));

$amount_col = in_array('amount', $columns) ? 'amount' : (in_array('transaction_amount', $columns) ? 'transaction_amount' : 'amount');

$desc_col = in_array('description', $columns) ? 'description' : (in_array('notes', $columns) ? 'notes' : (in_array('particulars', $columns) ? 'particulars' : 'description'));

$ref_col = in_array('reference_no', $columns) ? 'reference_no' : (in_array('reference', $columns) ? 'reference' : (in_array('ref_no', $columns) ? 'ref_no' : 'reference_no'));

// Get members for dropdown
$members = executeQuery("SELECT id, member_no, full_name FROM members WHERE membership_status = 'active' ORDER BY full_name");

// Build query based on filters
$where_conditions = ["1=1"];
$params = [];
$types = "";

if (!empty($member_id)) {
    $where_conditions[] = "d.$member_id_col = ?";
    $params[] = $member_id;
    $types .= "i";
}

if (!empty($date_from)) {
    $where_conditions[] = "d.$date_col >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $where_conditions[] = "d.$date_col <= ?";
    $params[] = $date_to;
    $types .= "s";
}

if ($transaction_type != 'all') {
    $where_conditions[] = "d.$type_col = ?";
    $params[] = $transaction_type;
    $types .= "s";
}

$where_clause = implode(" AND ", $where_conditions);

// For single member view with running balance
if (!empty($member_id)) {
    // Get all transactions for the member ordered by date
    $transactions_sql = "SELECT d.*, 
                         m.member_no, 
                         m.full_name as member_name
                         FROM deposits d
                         JOIN members m ON d.$member_id_col = m.id
                         WHERE $where_clause
                         ORDER BY d.$date_col ASC";

    if (!empty($params)) {
        $transactions = executeQuery($transactions_sql, $types, $params);
    } else {
        $transactions = executeQuery($transactions_sql);
    }

    // Calculate running balance manually
    $transactions_array = [];
    $running_balance = 0;

    // First, get opening balance before the date range
    $opening_sql = "SELECT COALESCE(SUM(
                        CASE 
                            WHEN $type_col = 'deposit' THEN $amount_col 
                            WHEN $type_col = 'withdrawal' THEN -$amount_col 
                            ELSE 0 
                        END), 0) as opening_balance
                    FROM deposits 
                    WHERE $member_id_col = ? AND $date_col < ?";
    $opening_result = executeQuery($opening_sql, "is", [$member_id, $date_from]);
    $opening_balance = $opening_result->fetch_assoc()['opening_balance'];
    $running_balance = $opening_balance;
} else {
    // For all members view (no running balance)
    $transactions_sql = "SELECT d.*, 
                         m.member_no, 
                         m.full_name as member_name
                         FROM deposits d
                         JOIN members m ON d.$member_id_col = m.id
                         WHERE $where_clause
                         ORDER BY d.$member_id_col, d.$date_col DESC";

    if (!empty($params)) {
        $transactions = executeQuery($transactions_sql, $types, $params);
    } else {
        $transactions = executeQuery($transactions_sql);
    }
}

// Get summary statistics
$summary_sql = "SELECT 
                COUNT(DISTINCT d.$member_id_col) as total_members,
                COUNT(CASE WHEN d.$type_col = 'deposit' THEN 1 END) as deposit_count,
                COUNT(CASE WHEN d.$type_col = 'withdrawal' THEN 1 END) as withdrawal_count,
                COALESCE(SUM(CASE WHEN d.$type_col = 'deposit' THEN d.$amount_col ELSE 0 END), 0) as total_deposits,
                COALESCE(SUM(CASE WHEN d.$type_col = 'withdrawal' THEN d.$amount_col ELSE 0 END), 0) as total_withdrawals
                FROM deposits d
                WHERE $where_clause";

if (!empty($params)) {
    $summary_result = executeQuery($summary_sql, $types, $params);
} else {
    $summary_result = executeQuery($summary_sql);
}
$summary = $summary_result->fetch_assoc();

// Get member balance if single member selected
$current_balance = 0;
if (!empty($member_id)) {
    $balance_sql = "SELECT COALESCE(SUM(
                        CASE 
                            WHEN $type_col = 'deposit' THEN $amount_col 
                            WHEN $type_col = 'withdrawal' THEN -$amount_col 
                            ELSE 0 
                        END), 0) as balance
                    FROM deposits 
                    WHERE $member_id_col = ?";
    $balance_result = executeQuery($balance_sql, "i", [$member_id]);
    $current_balance = $balance_result->fetch_assoc()['balance'];
}

// Get member details if single member selected
$selected_member = null;
if (!empty($member_id)) {
    $member_sql = "SELECT * FROM members WHERE id = ?";
    $member_result = executeQuery($member_sql, "i", [$member_id]);
    $selected_member = $member_result->fetch_assoc();
}

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Deposit Statements</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Deposits</a></li>
                <li class="breadcrumb-item active">Statements</li>
            </ul>
        </div>
        <div class="col-auto">
            <button class="btn btn-success" onclick="exportToExcel()">
                <i class="fas fa-file-excel me-2"></i>Export to Excel
            </button>
            <button class="btn btn-danger" onclick="exportToPDF()">
                <i class="fas fa-file-pdf me-2"></i>Export to PDF
            </button>
            <button class="btn btn-primary" onclick="window.print()">
                <i class="fas fa-print me-2"></i>Print
            </button>
        </div>
    </div>
</div>

<!-- Filter Card -->
<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="card-title mb-0">Filter Statements</h5>
    </div>
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-3">
                <label for="member_id" class="form-label">Member</label>
                <select class="form-control select2" id="member_id" name="member_id">
                    <option value="">-- All Members --</option>
                    <?php
                    $members->data_seek(0);
                    while ($member = $members->fetch_assoc()):
                    ?>
                        <option value="<?php echo $member['id']; ?>" <?php echo $member_id == $member['id'] ? 'selected' : ''; ?>>
                            <?php echo $member['full_name']; ?> (<?php echo $member['member_no']; ?>)
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label for="from" class="form-label">From Date</label>
                <input type="date" class="form-control" id="from" name="from" value="<?php echo $date_from; ?>">
            </div>

            <div class="col-md-2">
                <label for="to" class="form-label">To Date</label>
                <input type="date" class="form-control" id="to" name="to" value="<?php echo $date_to; ?>">
            </div>

            <div class="col-md-2">
                <label for="type" class="form-label">Transaction Type</label>
                <select class="form-control" id="type" name="type">
                    <option value="all" <?php echo $transaction_type == 'all' ? 'selected' : ''; ?>>All Transactions</option>
                    <option value="deposit" <?php echo $transaction_type == 'deposit' ? 'selected' : ''; ?>>Deposits Only</option>
                    <option value="withdrawal" <?php echo $transaction_type == 'withdrawal' ? 'selected' : ''; ?>>Withdrawals Only</option>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label">&nbsp;</label>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1">
                        <i class="fas fa-filter me-2"></i>Apply Filter
                    </button>
                    <a href="statements.php" class="btn btn-secondary">
                        <i class="fas fa-undo"></i>
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Member Info Card (if single member selected) -->
<?php if ($selected_member): ?>
    <div class="card mb-4 border-primary">
        <div class="card-header bg-primary text-white">
            <h5 class="card-title mb-0">Member Information</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <p><strong>Member No:</strong><br> <?php echo $selected_member['member_no']; ?></p>
                </div>
                <div class="col-md-3">
                    <p><strong>Full Name:</strong><br> <?php echo $selected_member['full_name']; ?></p>
                </div>
                <div class="col-md-3">
                    <p><strong>Phone:</strong><br> <?php echo $selected_member['phone']; ?></p>
                </div>
                <div class="col-md-3">
                    <p><strong>Current Balance:</strong><br>
                        <span class="text-primary fw-bold fs-5"><?php echo formatCurrency($current_balance); ?></span>
                    </p>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="stats-card info">
            <div class="stats-icon">
                <i class="fas fa-users"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo number_format($summary['total_members'] ?? 0); ?></h3>
                <p>Members</p>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card success">
            <div class="stats-icon">
                <i class="fas fa-arrow-down"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($summary['total_deposits'] ?? 0); ?></h3>
                <p>Total Deposits</p>
                <small><?php echo number_format($summary['deposit_count'] ?? 0); ?> transactions</small>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card danger">
            <div class="stats-icon">
                <i class="fas fa-arrow-up"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($summary['total_withdrawals'] ?? 0); ?></h3>
                <p>Total Withdrawals</p>
                <small><?php echo number_format($summary['withdrawal_count'] ?? 0); ?> transactions</small>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card primary">
            <div class="stats-icon">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency(($summary['total_deposits'] ?? 0) - ($summary['total_withdrawals'] ?? 0)); ?></h3>
                <p>Net Flow</p>
            </div>
        </div>
    </div>
</div>

<!-- Statements Table -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title">Transaction Statements</h5>
        <div class="card-tools">
            <span class="badge bg-info">
                Period: <?php echo formatDate($date_from); ?> - <?php echo formatDate($date_to); ?>
            </span>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-striped datatable" id="statementsTable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Member No</th>
                        <th>Member Name</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th>Reference</th>
                        <th>Amount</th>
                        <?php if (!empty($member_id)): ?>
                            <th>Running Balance</th>
                        <?php endif; ?>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $row_count = 0;

                    if (!empty($member_id)) {
                        // For single member with running balance
                        $running_balance = $opening_balance;

                        while ($trans = $transactions->fetch_assoc()):
                            $row_count++;

                            // Update running balance
                            if ($trans[$type_col] == 'deposit') {
                                $running_balance += $trans[$amount_col];
                            } else {
                                $running_balance -= $trans[$amount_col];
                            }
                    ?>
                            <tr class="<?php echo $trans[$type_col] == 'deposit' ? 'table-success' : 'table-danger'; ?>">
                                <td><?php echo formatDate($trans[$date_col]); ?></td>
                                <td><?php echo $trans['member_no']; ?></td>
                                <td>
                                    <a href="../members/view.php?id=<?php echo $trans[$member_id_col]; ?>">
                                        <?php echo $trans['member_name']; ?>
                                    </a>
                                </td>
                                <td>
                                    <?php if ($trans[$type_col] == 'deposit'): ?>
                                        <span class="badge bg-success">Deposit</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Withdrawal</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $trans[$desc_col] ?: '-'; ?></td>
                                <td><?php echo $trans[$ref_col] ?: '-'; ?></td>
                                <td class="<?php echo $trans[$type_col] == 'deposit' ? 'text-success' : 'text-danger'; ?> fw-bold">
                                    <?php echo $trans[$type_col] == 'deposit' ? '+' : '-'; ?>
                                    <?php echo formatCurrency($trans[$amount_col]); ?>
                                </td>
                                <td class="text-primary fw-bold">
                                    <?php echo formatCurrency($running_balance); ?>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <a href="receipt.php?id=<?php echo $trans['id']; ?>" class="btn btn-sm btn-outline-info" title="Receipt" target="_blank">
                                            <i class="fas fa-receipt"></i>
                                        </a>
                                        <?php if (hasRole('admin')): ?>
                                            <a href="javascript:void(0)" onclick="confirmDelete(<?php echo $trans['id']; ?>)"
                                                class="btn btn-sm btn-outline-danger" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php
                        endwhile;
                    } else {
                        // For all members (no running balance)
                        while ($trans = $transactions->fetch_assoc()):
                            $row_count++;
                        ?>
                            <tr class="<?php echo $trans[$type_col] == 'deposit' ? 'table-success' : 'table-danger'; ?>">
                                <td><?php echo formatDate($trans[$date_col]); ?></td>
                                <td><?php echo $trans['member_no']; ?></td>
                                <td>
                                    <a href="../members/view.php?id=<?php echo $trans[$member_id_col]; ?>">
                                        <?php echo $trans['member_name']; ?>
                                    </a>
                                </td>
                                <td>
                                    <?php if ($trans[$type_col] == 'deposit'): ?>
                                        <span class="badge bg-success">Deposit</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Withdrawal</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $trans[$desc_col] ?: '-'; ?></td>
                                <td><?php echo $trans[$ref_col] ?: '-'; ?></td>
                                <td class="<?php echo $trans[$type_col] == 'deposit' ? 'text-success' : 'text-danger'; ?> fw-bold">
                                    <?php echo $trans[$type_col] == 'deposit' ? '+' : '-'; ?>
                                    <?php echo formatCurrency($trans[$amount_col]); ?>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <a href="receipt.php?id=<?php echo $trans['id']; ?>" class="btn btn-sm btn-outline-info" title="Receipt" target="_blank">
                                            <i class="fas fa-receipt"></i>
                                        </a>
                                        <?php if (hasRole('admin')): ?>
                                            <a href="javascript:void(0)" onclick="confirmDelete(<?php echo $trans['id']; ?>)"
                                                class="btn btn-sm btn-outline-danger" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                    <?php
                        endwhile;
                    }
                    ?>

                    <?php if ($row_count == 0): ?>
                        <tr>
                            <td colspan="<?php echo !empty($member_id) ? '9' : '8'; ?>" class="text-center py-4">
                                <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No transactions found for the selected criteria.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr class="table-info fw-bold">
                        <td colspan="6" class="text-end">Totals:</td>
                        <td class="text-success">
                            Deposits: <?php echo formatCurrency($summary['total_deposits'] ?? 0); ?>
                            <?php if (!empty($member_id)): ?>
                                <br>Withdrawals: <?php echo formatCurrency($summary['total_withdrawals'] ?? 0); ?>
                            <?php endif; ?>
                        </td>
                        <?php if (!empty($member_id)): ?>
                            <td></td>
                        <?php endif; ?>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <div class="card-footer text-muted">
        <div class="row">
            <div class="col-md-6">
                <small>
                    <i class="fas fa-info-circle me-1"></i>
                    Showing <?php echo $row_count; ?> transactions
                    <?php if (!empty($member_id)): ?>
                        for selected member
                    <?php else: ?>
                        across all members
                    <?php endif; ?>
                </small>
            </div>
            <div class="col-md-6 text-end">
                <small>
                    Generated on: <?php echo date('d M Y H:i:s'); ?>
                </small>
            </div>
        </div>
    </div>
</div>

<!-- Monthly Summary Chart (only for single member) -->
<?php if (!empty($member_id)):
    // Get monthly summary for chart
    $monthly_sql = "SELECT 
                    DATE_FORMAT($date_col, '%Y-%m') as month,
                    SUM(CASE WHEN $type_col = 'deposit' THEN $amount_col ELSE 0 END) as deposits,
                    SUM(CASE WHEN $type_col = 'withdrawal' THEN $amount_col ELSE 0 END) as withdrawals
                    FROM deposits 
                    WHERE $member_id_col = ? AND $date_col BETWEEN ? AND ?
                    GROUP BY DATE_FORMAT($date_col, '%Y-%m')
                    ORDER BY month ASC";
    $monthly_result = executeQuery($monthly_sql, "iss", [$member_id, $date_from, $date_to]);
?>
    <div class="card mt-4">
        <div class="card-header">
            <h5 class="card-title">Monthly Activity</h5>
        </div>
        <div class="card-body">
            <canvas id="monthlyChart" height="100"></canvas>
        </div>
    </div>

    <script>
        // Monthly Chart
        document.addEventListener('DOMContentLoaded', function() {
            var months = [];
            var depositsData = [];
            var withdrawalsData = [];

            <?php while ($row = $monthly_result->fetch_assoc()): ?>
                months.push('<?php echo $row['month']; ?>');
                depositsData.push(<?php echo $row['deposits'] ?? 0; ?>);
                withdrawalsData.push(<?php echo $row['withdrawals'] ?? 0; ?>);
            <?php endwhile; ?>

            if (months.length > 0) {
                var ctx = document.getElementById('monthlyChart').getContext('2d');
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: months,
                        datasets: [{
                            label: 'Deposits',
                            data: depositsData,
                            backgroundColor: 'rgba(40, 167, 69, 0.5)',
                            borderColor: 'rgb(40, 167, 69)',
                            borderWidth: 1
                        }, {
                            label: 'Withdrawals',
                            data: withdrawalsData,
                            backgroundColor: 'rgba(220, 53, 69, 0.5)',
                            borderColor: 'rgb(220, 53, 69)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return 'KES ' + value.toLocaleString();
                                    }
                                }
                            }
                        }
                    }
                });
            }
        });
    </script>
<?php endif; ?>

<script>
    // Export to Excel
    function exportToExcel() {
        var memberId = '<?php echo $member_id; ?>';
        var from = '<?php echo $date_from; ?>';
        var to = '<?php echo $date_to; ?>';
        var type = '<?php echo $transaction_type; ?>';

        window.location.href = 'export-statements.php?member_id=' + memberId + '&from=' + from + '&to=' + to + '&type=' + type + '&format=excel';
    }

    // Export to PDF
    function exportToPDF() {
        var memberId = '<?php echo $member_id; ?>';
        var from = '<?php echo $date_from; ?>';
        var to = '<?php echo $date_to; ?>';
        var type = '<?php echo $transaction_type; ?>';

        window.location.href = 'export-statements.php?member_id=' + memberId + '&from=' + from + '&to=' + to + '&type=' + type + '&format=pdf';
    }

    // Confirm delete
    function confirmDelete(id) {
        Swal.fire({
            title: 'Are you sure?',
            text: "This action cannot be undone!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'index.php?delete=' + id;
            }
        });
    }

    // Initialize Select2
    $(document).ready(function() {
        $('.select2').select2({
            theme: 'bootstrap-5',
            width: '100%'
        });
    });

    // Print functionality
    window.onbeforeprint = function() {
        var table = document.getElementById('statementsTable');
        if (table) {
            table.classList.add('table-print');
        }
    };

    window.onafterprint = function() {
        var table = document.getElementById('statementsTable');
        if (table) {
            table.classList.remove('table-print');
        }
    };
</script>

<style>
    .stats-card.info {
        background: linear-gradient(135deg, #17a2b8, #138496);
    }

    .stats-card.info .stats-icon {
        background: rgba(255, 255, 255, 0.2);
        color: white;
    }

    .stats-card.info .stats-content h3,
    .stats-card.info .stats-content p {
        color: white;
    }

    .table-success {
        background-color: rgba(40, 167, 69, 0.05) !important;
    }

    .table-danger {
        background-color: rgba(220, 53, 69, 0.05) !important;
    }

    @media print {

        .sidebar,
        .navbar,
        .breadcrumb,
        .page-header .col-auto,
        .card-header .btn,
        .footer,
        .btn,
        .select2,
        .filter-card {
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

        .table-print {
            font-size: 10pt;
        }

        .badge {
            border: 1px solid #000 !important;
            color: #000 !important;
            background: transparent !important;
        }
    }
</style>

<?php include '../../includes/footer.php'; ?>
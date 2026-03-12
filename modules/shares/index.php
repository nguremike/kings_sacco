<?php
require_once '../../config/config.php';
requireLogin();

$page_title = 'Shares Management';

// Handle share purchase (full share purchase)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'purchase_full') {
        $member_id = $_POST['member_id'];
        $shares_count = $_POST['shares_count'];
        $share_value = 10000; // Fixed share value
        $reference_no = $_POST['reference_no'] ?? '';
        $date_purchased = $_POST['date_purchased'] ?? date('Y-m-d');
        $payment_method = $_POST['payment_method'] ?? 'cash';

        // Calculate total value
        $total_value = $shares_count * $share_value;

        $conn = getConnection();
        $conn->begin_transaction();

        try {
            // Insert into shares table
            $sql = "INSERT INTO shares (member_id, shares_count, share_value, total_value, transaction_type, reference_no, date_purchased, created_by) 
                    VALUES (?, ?, ?, ?, 'purchase', ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $created_by = getCurrentUserId();
            $stmt->bind_param("iiddssi", $member_id, $shares_count, $share_value, $total_value, $reference_no, $date_purchased, $created_by);
            $stmt->execute();
            $share_id = $stmt->insert_id;

            // Also add to share_contributions
            $contrib_sql = "INSERT INTO share_contributions (member_id, amount, contribution_date, reference_no, payment_method, notes, created_by)
                           VALUES (?, ?, ?, ?, ?, 'Full share purchase', ?)";
            $stmt2 = $conn->prepare($contrib_sql);
            $stmt2->bind_param("idsssi", $member_id, $total_value, $date_purchased, $reference_no, $payment_method, $created_by);
            $stmt2->execute();

            // Update member total contributions
            $update_sql = "UPDATE members SET total_share_contributions = total_share_contributions + ? WHERE id = ?";
            $stmt3 = $conn->prepare($update_sql);
            $stmt3->bind_param("di", $total_value, $member_id);
            $stmt3->execute();

            // Issue share certificates
            for ($i = 0; $i < $shares_count; $i++) {
                $share_number = 'SH' . date('Y') . str_pad($member_id, 4, '0', STR_PAD_LEFT) . str_pad(getNextShareNumber($member_id) + $i, 3, '0', STR_PAD_LEFT);
                $certificate_number = 'CERT' . time() . rand(1000, 9999) . $i;

                $issue_sql = "INSERT INTO shares_issued (member_id, share_number, share_count, amount_paid, issue_date, certificate_number, issued_by)
                              VALUES (?, ?, 1, ?, ?, ?, ?)";
                $stmt4 = $conn->prepare($issue_sql);
                $stmt4->bind_param("isds si", $member_id, $share_number, $share_value, $date_purchased, $certificate_number, $created_by);
                $stmt4->execute();
            }

            // Create transaction record
            $trans_no = 'SHR' . time() . rand(100, 999);
            $member_result = executeQuery("SELECT full_name FROM members WHERE id = ?", "i", [$member_id]);
            $member_data = $member_result->fetch_assoc();
            $desc = "Share purchase - {$member_data['full_name']} ({$shares_count} shares)";

            $trans_sql = "INSERT INTO transactions (transaction_no, transaction_date, description, debit_account, credit_account, amount, reference_type, reference_id, created_by)
                          VALUES (?, ?, ?, 'SHARES', 'CASH', ?, 'share', ?, ?)";
            $stmt5 = $conn->prepare($trans_sql);
            $stmt5->bind_param("sssiii", $trans_no, $date_purchased, $desc, $total_value, $share_id, $created_by);
            $stmt5->execute();

            $conn->commit();

            logAudit('INSERT', 'shares', $share_id, null, $_POST);
            $_SESSION['success'] = $shares_count . ' share(s) purchased successfully. Certificates issued.';
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = 'Failed to purchase shares: ' . $e->getMessage();
        }

        $conn->close();
        header('Location: index.php');
        exit();
    }
}

// Handle share deletion (admin only)
if (isset($_GET['delete']) && hasRole('admin')) {
    $id = $_GET['delete'];

    $sql = "DELETE FROM shares WHERE id = ?";
    executeQuery($sql, "i", [$id]);

    logAudit('DELETE', 'shares', $id, null, null);
    $_SESSION['success'] = 'Share record deleted successfully';

    header('Location: index.php');
    exit();
}

// Get share summary statistics
$summary_sql = "SELECT 
                COUNT(DISTINCT s.member_id) as total_shareholders,
                COALESCE(SUM(s.shares_count), 0) as total_shares,
                COALESCE(SUM(s.total_value), 0) as total_value,
                COALESCE(AVG(s.shares_count), 0) as avg_shares_per_member,
                (SELECT COUNT(*) FROM shares_issued) as total_certificates,
                (SELECT COALESCE(SUM(amount), 0) FROM share_contributions) as total_contributions
                FROM shares s";
$summary_result = executeQuery($summary_sql);
$summary = $summary_result->fetch_assoc();

// Get monthly share purchases for chart
$monthly_sql = "SELECT 
                DATE_FORMAT(date_purchased, '%Y-%m') as month,
                SUM(shares_count) as shares_count,
                SUM(total_value) as total_value
                FROM shares 
                WHERE date_purchased >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                GROUP BY DATE_FORMAT(date_purchased, '%Y-%m')
                ORDER BY month ASC";
$monthly_result = executeQuery($monthly_sql);

// Get top shareholders
$top_shareholders_sql = "SELECT 
                         m.id, m.member_no, m.full_name,
                         COALESCE(SUM(s.shares_count), 0) as total_shares,
                         COALESCE(SUM(s.total_value), 0) as total_value,
                         m.partial_share_balance,
                         m.full_shares_issued
                         FROM members m
                         LEFT JOIN shares s ON m.id = s.member_id
                         WHERE m.membership_status = 'active'
                         GROUP BY m.id
                         HAVING total_shares > 0 OR m.partial_share_balance > 0
                         ORDER BY total_value DESC
                         LIMIT 10";
$top_shareholders = executeQuery($top_shareholders_sql);

// Get recent share transactions
$recent_sql = "SELECT s.*, 
               m.member_no, m.full_name as member_name,
               m.partial_share_balance, m.full_shares_issued
               FROM shares s
               JOIN members m ON s.member_id = m.id
               ORDER BY s.date_purchased DESC, s.created_at DESC
               LIMIT 50";
$recent_shares = executeQuery($recent_sql);

// Get members for dropdown
$members = executeQuery("SELECT id, member_no, full_name, total_share_contributions, full_shares_issued, partial_share_balance 
                        FROM members WHERE membership_status = 'active' ORDER BY full_name");

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Shares Management</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item active">Shares</li>
            </ul>
        </div>
        <div class="col-auto">
            <div class="btn-group">
                <button type="button" class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="fas fa-plus me-2"></i>New Transaction
                </button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#purchaseModal">
                            <i class="fas fa-shopping-cart me-2"></i>Purchase Full Shares
                        </a></li>
                    <li><a class="dropdown-item" href="contributions.php">
                            <i class="fas fa-coins me-2"></i>Add Contribution
                        </a></li>
                    <li>
                        <hr class="dropdown-divider">
                    </li>
                    <li><a class="dropdown-item" href="certificates.php">
                            <i class="fas fa-certificate me-2"></i>View Certificates
                        </a></li>
                </ul>
            </div>
            <button class="btn btn-success" onclick="exportShares()">
                <i class="fas fa-file-excel me-2"></i>Export
            </button>
        </div>
    </div>
</div>

<!-- Alert Messages -->
<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?php
        echo $_SESSION['success'];
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

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-xl-2 col-md-4">
        <div class="stats-card primary">
            <div class="stats-icon">
                <i class="fas fa-users"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo number_format($summary['total_shareholders'] ?? 0); ?></h3>
                <p>Shareholders</p>
            </div>
        </div>
    </div>

    <div class="col-xl-2 col-md-4">
        <div class="stats-card success">
            <div class="stats-icon">
                <i class="fas fa-chart-pie"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo number_format($summary['total_shares'] ?? 0); ?></h3>
                <p>Total Shares</p>
            </div>
        </div>
    </div>

    <div class="col-xl-2 col-md-4">
        <div class="stats-card warning">
            <div class="stats-icon">
                <i class="fas fa-coins"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($summary['total_value'] ?? 0); ?></h3>
                <p>Share Value</p>
            </div>
        </div>
    </div>

    <div class="col-xl-2 col-md-4">
        <div class="stats-card info">
            <div class="stats-icon">
                <i class="fas fa-trophy"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo number_format($summary['total_certificates'] ?? 0); ?></h3>
                <p>Certificates</p>
            </div>
        </div>
    </div>

    <div class="col-xl-2 col-md-4">
        <div class="stats-card secondary">
            <div class="stats-icon">
                <i class="fas fa-piggy-bank"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($summary['total_contributions'] ?? 0); ?></h3>
                <p>Contributions</p>
            </div>
        </div>
    </div>

    <div class="col-xl-2 col-md-4">
        <div class="stats-card dark">
            <div class="stats-icon">
                <i class="fas fa-calculator"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency(10000); ?></h3>
                <p>Per Share</p>
            </div>
        </div>
    </div>
</div>

<!-- Share Progress Overview -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Share Contribution Progress</h5>
            </div>
            <div class="card-body">
                <canvas id="progressChart" height="100"></canvas>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Monthly Share Purchases</h5>
            </div>
            <div class="card-body">
                <canvas id="sharesChart" height="100"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Top Shareholders -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title">Top Shareholders</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Member</th>
                        <th>Member No</th>
                        <th>Full Shares</th>
                        <th>Partial Balance</th>
                        <th>Total Value</th>
                        <th>Progress</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $rank = 1;
                    while ($holder = $top_shareholders->fetch_assoc()):
                        $total_contributions = ($holder['total_shares'] * 10000) + ($holder['partial_share_balance'] ?? 0);
                        $progress_percent = (($holder['partial_share_balance'] ?? 0) / 10000) * 100;
                    ?>
                        <tr>
                            <td><strong>#<?php echo $rank++; ?></strong></td>
                            <td>
                                <a href="../members/view.php?id=<?php echo $holder['id']; ?>">
                                    <?php echo $holder['full_name']; ?>
                                </a>
                            </td>
                            <td><?php echo $holder['member_no']; ?></td>
                            <td><?php echo number_format($holder['total_shares']); ?></td>
                            <td><?php echo formatCurrency($holder['partial_share_balance'] ?? 0); ?></td>
                            <td><?php echo formatCurrency($holder['total_value'] + ($holder['partial_share_balance'] ?? 0)); ?></td>
                            <td style="width: 150px;">
                                <div class="progress" style="height: 20px;">
                                    <div class="progress-bar bg-success"
                                        role="progressbar"
                                        style="width: <?php echo $progress_percent; ?>%;"
                                        aria-valuenow="<?php echo $progress_percent; ?>"
                                        aria-valuemin="0"
                                        aria-valuemax="100">
                                        <?php echo number_format($progress_percent, 1); ?>%
                                    </div>
                                </div>
                            </td>
                            <td>
                                <a href="contributions.php?member_id=<?php echo $holder['id']; ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-plus"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Recent Share Transactions -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title">Recent Share Transactions</h5>
        <ul class="nav nav-tabs card-header-tabs">
            <li class="nav-item">
                <a class="nav-link active" data-bs-toggle="tab" href="#all">All</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#full">Full Shares</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#partial">Contributions</a>
            </li>
        </ul>
    </div>
    <div class="card-body">
        <div class="tab-content">
            <div class="tab-pane fade show active" id="all">
                <div class="table-responsive">
                    <table class="table table-hover datatable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Member</th>
                                <th>Member No</th>
                                <th>Type</th>
                                <th>Shares</th>
                                <th>Amount</th>
                                <th>Share Value</th>
                                <th>Certificate</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($share = $recent_shares->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo formatDate($share['date_purchased']); ?></td>
                                    <td>
                                        <a href="../members/view.php?id=<?php echo $share['member_id']; ?>">
                                            <?php echo $share['member_name']; ?>
                                        </a>
                                    </td>
                                    <td><?php echo $share['member_no']; ?></td>
                                    <td>
                                        <?php if ($share['shares_count'] > 0): ?>
                                            <span class="badge bg-success">Full Share</span>
                                        <?php else: ?>
                                            <span class="badge bg-info">Contribution</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="<?php echo $share['shares_count'] > 0 ? 'text-success fw-bold' : ''; ?>">
                                        <?php echo $share['shares_count'] > 0 ? '+' . number_format($share['shares_count']) : '-'; ?>
                                    </td>
                                    <td class="text-success fw-bold"><?php echo formatCurrency($share['total_value']); ?></td>
                                    <td><?php echo formatCurrency($share['share_value']); ?></td>
                                    <td>
                                        <?php if ($share['shares_count'] > 0): ?>
                                            <span class="badge bg-primary">Issued</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <?php if ($share['shares_count'] > 0): ?>
                                                <a href="certificate_print.php?share_id=<?php echo $share['id']; ?>" class="btn btn-sm btn-outline-success" title="Certificate">
                                                    <i class="fas fa-certificate"></i>
                                                </a>
                                            <?php endif; ?>
                                            <a href="receipt.php?id=<?php echo $share['id']; ?>" class="btn btn-sm btn-outline-info" title="Receipt">
                                                <i class="fas fa-receipt"></i>
                                            </a>
                                            <?php if (hasRole('admin')): ?>
                                                <a href="javascript:void(0)" onclick="confirmDelete(<?php echo $share['id']; ?>)"
                                                    class="btn btn-sm btn-outline-danger" title="Delete">
                                                    <i class="fas fa-trash"></i>
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

            <div class="tab-pane fade" id="full">
                <!-- Full shares only -->
            </div>

            <div class="tab-pane fade" id="partial">
                <!-- Partial contributions - link to contributions.php -->
                <div class="text-center py-4">
                    <i class="fas fa-coins fa-3x text-info mb-3"></i>
                    <h5>View Partial Contributions</h5>
                    <p class="text-muted">Track member contributions towards full shares</p>
                    <a href="contributions.php" class="btn btn-info">
                        <i class="fas fa-arrow-right me-2"></i>Go to Contributions
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Purchase Full Shares Modal -->
<div class="modal fade" id="purchaseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="" class="needs-validation" novalidate>
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Purchase Full Shares</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="purchase_full">

                    <div class="mb-3">
                        <label for="member_id" class="form-label">Select Member <span class="text-danger">*</span></label>
                        <select class="form-control select2" id="member_id" name="member_id" required>
                            <option value="">-- Select Member --</option>
                            <?php
                            $members->data_seek(0);
                            while ($member = $members->fetch_assoc()):
                                $progress = (($member['partial_share_balance'] ?? 0) / 10000) * 100;
                            ?>
                                <option value="<?php echo $member['id']; ?>"
                                    data-balance="<?php echo $member['partial_share_balance']; ?>"
                                    data-shares="<?php echo $member['full_shares_issued']; ?>">
                                    <?php echo $member['full_name']; ?> (<?php echo $member['member_no']; ?>) -
                                    Shares: <?php echo $member['full_shares_issued']; ?>,
                                    Progress: <?php echo number_format($progress, 1); ?>%
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="alert alert-info" id="memberShareInfo" style="display: none;">
                        <strong>Current Share Status:</strong><br>
                        <span id="shareStatus"></span>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="shares_count" class="form-label">Number of Shares <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="shares_count" name="shares_count"
                                min="1" step="1" required onchange="calculateTotal()">
                            <small class="text-muted">1 share = KES 10,000</small>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="total_value" class="form-label">Total Amount (KES)</label>
                            <input type="text" class="form-control" id="total_value" readonly>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="payment_method" class="form-label">Payment Method</label>
                            <select class="form-control" id="payment_method" name="payment_method">
                                <option value="cash">Cash</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="mpesa">M-Pesa</option>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="reference_no" class="form-label">Reference Number</label>
                            <input type="text" class="form-control" id="reference_no" name="reference_no"
                                value="SHARE<?php echo time(); ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="date_purchased" class="form-label">Purchase Date</label>
                        <input type="date" class="form-control" id="date_purchased" name="date_purchased"
                            value="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Note:</strong> This will immediately issue share certificates for the purchased shares.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-shopping-cart me-2"></i>Purchase Shares
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Calculate total for purchase
    function calculateTotal() {
        var shares = parseFloat(document.getElementById('shares_count').value) || 0;
        var value = 10000; // Fixed share value
        var total = shares * value;

        document.getElementById('total_value').value = 'KES ' + total.toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    // Update member share info when selected
    $('#member_id').on('change', function() {
        var selected = $(this).find('option:selected');
        var balance = selected.data('balance') || 0;
        var shares = selected.data('shares') || 0;
        var progress = (balance / 10000 * 100).toFixed(1);

        if (selected.val()) {
            $('#memberShareInfo').show();
            $('#shareStatus').html(`
            Full Shares Owned: ${shares}<br>
            Partial Balance: KES ${balance.toLocaleString()}<br>
            Progress to Next Share: ${progress}%
        `);
        } else {
            $('#memberShareInfo').hide();
        }
    });

    // Export shares to Excel
    function exportShares() {
        window.location.href = 'export.php';
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

    // Initialize charts
    document.addEventListener('DOMContentLoaded', function() {
        // Shares Chart
        var months = [];
        var sharesData = [];
        var valueData = [];

        <?php
        $monthly_result->data_seek(0);
        while ($row = $monthly_result->fetch_assoc()):
        ?>
            months.push('<?php echo $row['month']; ?>');
            sharesData.push(<?php echo $row['shares_count'] ?? 0; ?>);
            valueData.push(<?php echo ($row['total_value'] ?? 0) / 1000; ?>);
        <?php endwhile; ?>

        if (months.length > 0) {
            var ctx = document.getElementById('sharesChart').getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: months,
                    datasets: [{
                        label: 'Shares Purchased',
                        data: sharesData,
                        backgroundColor: 'rgba(40, 167, 69, 0.5)',
                        borderColor: 'rgb(40, 167, 69)',
                        borderWidth: 1,
                        yAxisID: 'y-shares'
                    }, {
                        label: 'Value (KES Thousands)',
                        data: valueData,
                        type: 'line',
                        borderColor: 'rgb(255, 193, 7)',
                        backgroundColor: 'rgba(255, 193, 7, 0.1)',
                        yAxisID: 'y-value',
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        'y-shares': {
                            beginAtZero: true,
                            position: 'left',
                            title: {
                                display: true,
                                text: 'Number of Shares'
                            }
                        },
                        'y-value': {
                            beginAtZero: true,
                            position: 'right',
                            title: {
                                display: true,
                                text: 'Value (KES Thousands)'
                            },
                            grid: {
                                drawOnChartArea: false
                            }
                        }
                    }
                }
            });
        }

        // Progress Chart - Distribution of shareholders by contribution levels
        <?php
        $level1 = executeQuery("SELECT COUNT(*) as count FROM members WHERE total_share_contributions >= 10000 AND total_share_contributions < 50000")->fetch_assoc();
        $level2 = executeQuery("SELECT COUNT(*) as count FROM members WHERE total_share_contributions >= 50000 AND total_share_contributions < 100000")->fetch_assoc();
        $level3 = executeQuery("SELECT COUNT(*) as count FROM members WHERE total_share_contributions >= 100000 AND total_share_contributions < 500000")->fetch_assoc();
        $level4 = executeQuery("SELECT COUNT(*) as count FROM members WHERE total_share_contributions >= 500000")->fetch_assoc();
        ?>

        var progressCtx = document.getElementById('progressChart').getContext('2d');
        new Chart(progressCtx, {
            type: 'doughnut',
            data: {
                labels: ['1-5 Shares', '5-10 Shares', '10-50 Shares', '50+ Shares'],
                datasets: [{
                    data: [
                        <?php echo $level1['count'] ?? 0; ?>,
                        <?php echo $level2['count'] ?? 0; ?>,
                        <?php echo $level3['count'] ?? 0; ?>,
                        <?php echo $level4['count'] ?? 0; ?>
                    ],
                    backgroundColor: [
                        'rgba(255, 99, 132, 0.5)',
                        'rgba(54, 162, 235, 0.5)',
                        'rgba(255, 206, 86, 0.5)',
                        'rgba(75, 192, 192, 0.5)'
                    ],
                    borderColor: [
                        'rgb(255, 99, 132)',
                        'rgb(54, 162, 235)',
                        'rgb(255, 206, 86)',
                        'rgb(75, 192, 192)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    });

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

    // Initialize Select2
    $(document).ready(function() {
        $('.select2').select2({
            theme: 'bootstrap-5',
            width: '100%',
            dropdownParent: $('#purchaseModal')
        });
    });
</script>

<!-- Helper function to get next share number -->
<?php
function getNextShareNumber($member_id)
{
    $result = executeQuery("SELECT COUNT(*) as count FROM shares_issued WHERE member_id = ?", "i", [$member_id]);
    $row = $result->fetch_assoc();
    return $row['count'];
}
?>

<style>
    .stats-card.secondary {
        background: linear-gradient(135deg, #6c757d, #5a6268);
    }

    .stats-card.secondary .stats-icon {
        background: rgba(255, 255, 255, 0.2);
        color: white;
    }

    .stats-card.secondary .stats-content h3,
    .stats-card.secondary .stats-content p {
        color: white;
    }

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

    .table td {
        vertical-align: middle;
    }

    .progress {
        background-color: #e9ecef;
        border-radius: 10px;
    }

    .progress-bar {
        border-radius: 10px;
        font-size: 12px;
        line-height: 20px;
    }
</style>

<?php include '../../includes/footer.php'; ?>
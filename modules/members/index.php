<?php
require_once '../../config/config.php';
requireLogin();

$page_title = 'Members Management';

// Handle member deletion
if (isset($_GET['delete']) && hasRole('admin')) {
    $id = $_GET['delete'];

    // Check if member has any transactions
    $checkSql = "SELECT id FROM deposits WHERE member_id = ? UNION SELECT id FROM loans WHERE member_id = ?";
    $checkResult = executeQuery($checkSql, "ii", [$id, $id]);

    if ($checkResult->num_rows > 0) {
        $_SESSION['error'] = 'Cannot delete member with existing transactions';
    } else {
        $sql = "DELETE FROM members WHERE id = ?";
        executeQuery($sql, "i", [$id]);
        logAudit('DELETE', 'members', $id, null, null);
        $_SESSION['success'] = 'Member deleted successfully';
    }

    header('Location: index.php');
    exit();
}

// Get all members with enhanced financial data
$sql = "SELECT m.*, 
        (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE 0 END), 0) 
         FROM deposits WHERE member_id = m.id) as total_deposits,
        (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'withdrawal' THEN amount ELSE 0 END), 0) 
         FROM deposits WHERE member_id = m.id) as total_withdrawals,
        (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE -amount END), 0) 
         FROM deposits WHERE member_id = m.id) as current_balance,
        (SELECT COUNT(*) FROM loans WHERE member_id = m.id) as total_loans,
        (SELECT COUNT(*) FROM loans WHERE member_id = m.id AND status IN ('active', 'disbursed')) as active_loans,
        (SELECT COALESCE(SUM(total_value), 0) FROM shares WHERE member_id = m.id) as total_shares,
        (SELECT COALESCE(SUM(amount), 0) FROM share_contributions WHERE member_id = m.id) as share_contributions
        FROM members m 
        ORDER BY m.created_at DESC";
$members = executeQuery($sql);

// Get summary statistics
$summary_sql = "SELECT 
                COUNT(*) as total_members,
                SUM(CASE WHEN membership_status = 'active' THEN 1 ELSE 0 END) as active_members,
                SUM(CASE WHEN membership_status = 'pending' THEN 1 ELSE 0 END) as pending_members,
                SUM(CASE WHEN membership_status = 'suspended' THEN 1 ELSE 0 END) as suspended_members,
                (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE -amount END), 0) 
                 FROM deposits) as total_savings,
                (SELECT COALESCE(SUM(total_value), 0) FROM shares) as total_share_value,
                (SELECT COALESCE(SUM(amount), 0) FROM share_contributions) as total_share_contributions,
                (SELECT COALESCE(SUM(total_amount), 0) FROM loans WHERE status IN ('active', 'disbursed')) as total_outstanding_loans
                FROM members";
$summary_result = executeQuery($summary_sql);
$summary = $summary_result->fetch_assoc();

// Get recent members for dashboard
$recent_sql = "SELECT m.*, 
               (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE -amount END), 0) 
                FROM deposits WHERE member_id = m.id) as current_balance
               FROM members m 
               WHERE m.membership_status = 'active'
               ORDER BY m.created_at DESC 
               LIMIT 5";
$recent_members = executeQuery($recent_sql);

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Members Management</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item active">Members</li>
            </ul>
        </div>
        <div class="col-auto">
            <div class="btn-group">
                <a href="add.php" class="btn btn-primary">
                    <i class="fas fa-plus me-2"></i>Add New Member
                </a>
                <a href="import.php" class="btn btn-info">
                    <i class="fas fa-file-import me-2"></i>Import
                </a>
                <button class="btn btn-success" onclick="exportMembers()">
                    <i class="fas fa-file-excel me-2"></i>Export
                </button>
            </div>
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

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="stats-card primary">
            <div class="stats-icon">
                <i class="fas fa-users"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo number_format($summary['total_members'] ?? 0); ?></h3>
                <p>Total Members</p>
                <small>Active: <?php echo number_format($summary['active_members'] ?? 0); ?></small>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card success">
            <div class="stats-icon">
                <i class="fas fa-piggy-bank"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($summary['total_savings'] ?? 0); ?></h3>
                <p>Total Savings</p>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card info">
            <div class="stats-icon">
                <i class="fas fa-chart-pie"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency(($summary['total_share_value'] ?? 0) + ($summary['total_share_contributions'] ?? 0)); ?></h3>
                <p>Total Share Capital</p>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card warning">
            <div class="stats-icon">
                <i class="fas fa-hand-holding-usd"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($summary['total_outstanding_loans'] ?? 0); ?></h3>
                <p>Outstanding Loans</p>
            </div>
        </div>
    </div>
</div>

<!-- Filter and Search Card -->
<div class="card mb-4">
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
                <label for="status_filter" class="form-label">Filter by Status</label>
                <select class="form-control" id="status_filter" onchange="filterTable()">
                    <option value="all">All Members</option>
                    <option value="active">Active</option>
                    <option value="pending">Pending</option>
                    <option value="suspended">Suspended</option>
                    <option value="closed">Closed</option>
                </select>
            </div>
            <div class="col-md-4">
                <label for="balance_filter" class="form-label">Filter by Balance</label>
                <select class="form-control" id="balance_filter" onchange="filterTable()">
                    <option value="all">All Balances</option>
                    <option value="positive">Positive Balance (>0)</option>
                    <option value="zero">Zero Balance</option>
                    <option value="negative">Negative Balance</option>
                    <option value="above_10k">Above KES 10,000</option>
                    <option value="above_50k">Above KES 50,000</option>
                    <option value="above_100k">Above KES 100,000</option>
                </select>
            </div>
            <div class="col-md-4">
                <label for="search_input" class="form-label">Search</label>
                <input type="text" class="form-control" id="search_input" placeholder="Search by name, member no, phone..." onkeyup="filterTable()">
            </div>
        </div>
    </div>
</div>

<!-- Recent Members Card -->
<div class="card mb-4">
    <div class="card-header bg-info text-white">
        <h5 class="card-title mb-0"><i class="fas fa-clock me-2"></i>Recently Added Members</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Member No</th>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Date Joined</th>
                        <th>Current Balance</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($recent = $recent_members->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo $recent['member_no']; ?></td>
                            <td>
                                <a href="view.php?id=<?php echo $recent['id']; ?>">
                                    <?php echo $recent['full_name']; ?>
                                </a>
                            </td>
                            <td><?php echo $recent['phone']; ?></td>
                            <td><?php echo formatDate($recent['date_joined']); ?></td>
                            <td class="fw-bold <?php echo ($recent['current_balance'] ?? 0) >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo formatCurrency($recent['current_balance'] ?? 0); ?>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo $recent['membership_status'] == 'active' ? 'success' : ($recent['membership_status'] == 'pending' ? 'warning' : 'secondary'); ?>">
                                    <?php echo ucfirst($recent['membership_status']); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Members Table -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title">Member List</h5>
        <div class="card-tools">
            <span class="badge bg-primary">Total: <span id="totalCount">0</span></span>
            <span class="badge bg-success">Active: <span id="activeCount">0</span></span>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable" id="membersTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Member No</th>
                        <th>Full Name</th>
                        <!-- <th>National ID</th>
                        <th>Phone</th>
                        <th>Email</th> -->
                        <!-- <th>Date Joined</th> -->
                        <th>Deposits</th>
                        <th>Withdrawals</th>
                        <th>Current Balance</th>
                        <th>Loans</th>
                        <th>Shares</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($member = $members->fetch_assoc()):
                        $net_balance = ($member['total_deposits'] ?? 0) - ($member['total_withdrawals'] ?? 0);
                        $balance_class = $net_balance > 0 ? 'text-success' : ($net_balance < 0 ? 'text-danger' : 'text-secondary');
                    ?>
                        <tr data-status="<?php echo $member['membership_status']; ?>"
                            data-balance="<?php echo $net_balance; ?>"
                            data-name="<?php echo strtolower($member['full_name']); ?>"
                            data-member-no="<?php echo $member['member_no']; ?>"
                            data-phone="<?php echo $member['phone']; ?>">
                            <td><?php echo $member['id']; ?></td>
                            <td>
                                <span class="badge bg-primary"><?php echo $member['member_no']; ?></span>
                            </td>
                            <td>
                                <a href="view.php?id=<?php echo $member['id']; ?>" class="text-decoration-none">
                                    <strong><?php echo $member['full_name']; ?></strong>
                                </a>
                            </td>
                            <!-- <td><?php // echo $member['national_id']; 
                                        ?></td>
                            <td><?php //echo $member['phone']; 
                                ?></td>
                            <td><?php //echo $member['email'] ?: '-'; 
                                ?></td> -->
                            <!-- <td><?php // echo formatDate($member['date_joined']); 
                                        ?></td> -->
                            <td class="text-success"><?php echo formatCurrency($member['total_deposits'] ?? 0); ?></td>
                            <td class="text-danger"><?php echo formatCurrency($member['total_withdrawals'] ?? 0); ?></td>
                            <td class="fw-bold <?php echo $balance_class; ?>">
                                <?php echo formatCurrency($net_balance); ?>
                                <?php if ($net_balance > 0): ?>
                                    <i class="fas fa-arrow-up text-success ms-1"></i>
                                <?php elseif ($net_balance < 0): ?>
                                    <i class="fas fa-arrow-down text-danger ms-1"></i>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-info"><?php echo $member['total_loans'] ?? 0; ?></span>
                                <?php if (($member['active_loans'] ?? 0) > 0): ?>
                                    <br><small class="text-warning">Active: <?php echo $member['active_loans']; ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $total_shares = ($member['total_shares'] ?? 0) + ($member['share_contributions'] ?? 0);
                                ?>
                                <span class="badge bg-primary"><?php echo formatCurrency($total_shares); ?></span>
                                <!-- <br><small><?php //echo number_format($member['total_shares'] ?? 0); 
                                                ?> shares</small> -->
                            </td>
                            <td>
                                <?php if ($member['membership_status'] == 'active'): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php elseif ($member['membership_status'] == 'pending'): ?>
                                    <span class="badge bg-warning">Pending</span>
                                <?php elseif ($member['membership_status'] == 'suspended'): ?>
                                    <span class="badge bg-danger">Suspended</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Closed</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group" role="group">
                                    <a href="view.php?id=<?php echo $member['id']; ?>" class="btn btn-sm btn-outline-info" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="edit.php?id=<?php echo $member['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="statement.php?id=<?php echo $member['id']; ?>" class="btn btn-sm btn-outline-success" title="Statement">
                                        <i class="fas fa-file-invoice"></i>
                                    </a>
                                    <?php if (hasRole('admin')): ?>
                                        <a href="javascript:void(0)" onclick="confirmDelete(<?php echo $member['id']; ?>)"
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
    <div class="card-footer">
        <div class="row">
            <div class="col-md-6">
                <small class="text-muted">
                    <i class="fas fa-info-circle me-1"></i>
                    Showing members with current balance calculations
                </small>
            </div>
            <div class="col-md-6 text-end">
                <small class="text-muted">
                    Total Deposits: <span id="totalDeposits">0</span> |
                    Total Withdrawals: <span id="totalWithdrawals">0</span> |
                    Net Balance: <span id="netBalance">0</span>
                </small>
            </div>
        </div>
    </div>
</div>

<script>
    function confirmDelete(id) {
        confirmAction('This action cannot be undone. Are you sure you want to delete this member?', function() {
            window.location.href = 'index.php?delete=' + id;
        });
    }

    function exportMembers() {
        window.location.href = 'export.php';
    }

    function filterTable() {
        var status = document.getElementById('status_filter').value;
        var balance = document.getElementById('balance_filter').value;
        var search = document.getElementById('search_input').value.toLowerCase();
        var rows = document.querySelectorAll('#membersTable tbody tr');
        var visibleCount = 0;
        var activeCount = 0;
        var totalDeposits = 0;
        var totalWithdrawals = 0;

        rows.forEach(function(row) {
            var rowStatus = row.getAttribute('data-status');
            var rowBalance = parseFloat(row.getAttribute('data-balance'));
            var rowName = row.getAttribute('data-name');
            var rowMemberNo = row.getAttribute('data-member-no');
            var rowPhone = row.getAttribute('data-phone');

            var show = true;

            // Status filter
            if (status !== 'all' && rowStatus !== status) {
                show = false;
            }

            // Balance filter
            if (balance !== 'all' && show) {
                if (balance === 'positive' && rowBalance <= 0) show = false;
                else if (balance === 'zero' && rowBalance !== 0) show = false;
                else if (balance === 'negative' && rowBalance >= 0) show = false;
                else if (balance === 'above_10k' && rowBalance <= 10000) show = false;
                else if (balance === 'above_50k' && rowBalance <= 50000) show = false;
                else if (balance === 'above_100k' && rowBalance <= 100000) show = false;
            }

            // Search filter
            if (search !== '' && show) {
                var searchable = rowName + ' ' + rowMemberNo + ' ' + rowPhone;
                if (searchable.indexOf(search) === -1) {
                    show = false;
                }
            }

            if (show) {
                row.style.display = '';
                visibleCount++;
                if (rowStatus === 'active') activeCount++;

                // Extract totals from visible rows
                var depositCell = row.cells[7]?.innerText.replace('KES', '').replace(/,/g, '').trim();
                var withdrawalCell = row.cells[8]?.innerText.replace('KES', '').replace(/,/g, '').trim();
                if (depositCell && !isNaN(parseFloat(depositCell))) {
                    totalDeposits += parseFloat(depositCell);
                }
                if (withdrawalCell && !isNaN(parseFloat(withdrawalCell))) {
                    totalWithdrawals += parseFloat(withdrawalCell);
                }
            } else {
                row.style.display = 'none';
            }
        });

        // Update counters
        document.getElementById('totalCount').innerText = visibleCount;
        document.getElementById('activeCount').innerText = activeCount;
        document.getElementById('totalDeposits').innerHTML = formatCurrency(totalDeposits);
        document.getElementById('totalWithdrawals').innerHTML = formatCurrency(totalWithdrawals);
        document.getElementById('netBalance').innerHTML = formatCurrency(totalDeposits - totalWithdrawals);
    }

    function formatCurrency(amount) {
        return 'KES ' + amount.toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    // Initialize DataTable with custom options
    $(document).ready(function() {
        var table = $('.datatable').DataTable({
            pageLength: 25,
            order: [
                [6, 'desc']
            ], // Sort by date joined
            language: {
                emptyTable: "No members found",
                info: "Showing _START_ to _END_ of _TOTAL_ members",
                infoEmpty: "Showing 0 to 0 of 0 members",
                search: "Search:",
                lengthMenu: "Show _MENU_ members per page"
            },
            columnDefs: [{
                    targets: [7, 8, 9],
                    className: 'text-end'
                }, // Align currency columns
                {
                    targets: [12],
                    orderable: false
                }, // Status column
                {
                    targets: [13],
                    orderable: false
                } // Actions column
            ],
            drawCallback: function() {
                // Update summary after table redraw
                updateTableSummary();
            }
        });

        function updateTableSummary() {
            var visibleRows = table.rows({
                filter: 'applied'
            }).nodes();
            var totalDeposits = 0;
            var totalWithdrawals = 0;

            $(visibleRows).each(function() {
                var deposit = $(this).find('td:eq(7)').text().replace('KES', '').replace(/,/g, '').trim();
                var withdrawal = $(this).find('td:eq(8)').text().replace('KES', '').replace(/,/g, '').trim();
                if (deposit && !isNaN(parseFloat(deposit))) {
                    totalDeposits += parseFloat(deposit);
                }
                if (withdrawal && !isNaN(parseFloat(withdrawal))) {
                    totalWithdrawals += parseFloat(withdrawal);
                }
            });

            document.getElementById('totalDeposits').innerHTML = formatCurrency(totalDeposits);
            document.getElementById('totalWithdrawals').innerHTML = formatCurrency(totalWithdrawals);
            document.getElementById('netBalance').innerHTML = formatCurrency(totalDeposits - totalWithdrawals);
        }

        // Initial filter update
        filterTable();
    });
</script>

<style>
    .stats-card {
        cursor: pointer;
        transition: transform 0.2s;
    }

    .stats-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
    }

    .table td {
        vertical-align: middle;
    }

    .btn-group .btn {
        margin-right: 2px;
    }

    .btn-group .btn:last-child {
        margin-right: 0;
    }

    .badge {
        font-size: 11px;
        padding: 5px 8px;
    }

    #status_filter,
    #balance_filter,
    #search_input {
        cursor: pointer;
    }

    .card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .card-header .card-tools {
        margin-left: auto;
    }

    .card-header .card-tools .badge {
        margin-left: 8px;
        font-size: 12px;
    }

    @media (max-width: 768px) {
        .btn-group {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .btn-group .btn {
            margin-right: 0;
            border-radius: 4px !important;
        }

        .stats-card {
            margin-bottom: 10px;
        }
    }
</style>

<?php include '../../includes/footer.php'; ?>
<?php
require_once 'config/config.php';
requireLogin();

$page_title = 'Dashboard';

// Get statistics
$stats = [];

// Total members
$result = executeQuery("SELECT COUNT(*) as total FROM members WHERE membership_status = 'active'");
$stats['total_members'] = $result->fetch_assoc()['total'];

// Total deposits
$result = executeQuery("SELECT SUM(amount) as total FROM deposits WHERE transaction_type = 'deposit'");
$stats['total_deposits'] = $result->fetch_assoc()['total'] ?? 0;

// Active loans
$result = executeQuery("SELECT COUNT(*) as total FROM loans WHERE status IN ('active', 'disbursed')");
$stats['active_loans'] = $result->fetch_assoc()['total'];

// Total loan portfolio
$result = executeQuery("SELECT SUM(total_amount) as total FROM loans WHERE status IN ('active', 'disbursed')");
$stats['loan_portfolio'] = $result->fetch_assoc()['total'] ?? 0;

// Recent members
$recent_members = executeQuery("SELECT * FROM members ORDER BY created_at DESC LIMIT 5");

// Recent loans
$recent_loans = executeQuery("
    SELECT l.*, m.full_name, m.member_no 
    FROM loans l 
    JOIN members m ON l.member_id = m.id 
    ORDER BY l.created_at DESC 
    LIMIT 5
");

include 'includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Dashboard</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                <li class="breadcrumb-item active">Dashboard</li>
            </ul>
        </div>
        <div class="col-auto">
            <button class="btn btn-primary" onclick="window.location.reload()">
                <i class="fas fa-sync-alt me-2"></i>Refresh
            </button>
        </div>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row">
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="stats-card primary">
            <div class="stats-icon">
                <i class="fas fa-users"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo number_format($stats['total_members']); ?></h3>
                <p>Total Members</p>
                <span class="stats-change positive">
                    <i class="fas fa-arrow-up"></i> 12% increase
                </span>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-4">
        <div class="stats-card success">
            <div class="stats-icon">
                <i class="fas fa-piggy-bank"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($stats['total_deposits']); ?></h3>
                <p>Total Deposits</p>
                <span class="stats-change positive">
                    <i class="fas fa-arrow-up"></i> 8% increase
                </span>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-4">
        <div class="stats-card warning">
            <div class="stats-icon">
                <i class="fas fa-hand-holding-usd"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo number_format($stats['active_loans']); ?></h3>
                <p>Active Loans</p>
                <span class="stats-change positive">
                    <i class="fas fa-arrow-up"></i> 5% increase
                </span>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-4">
        <div class="stats-card danger">
            <div class="stats-icon">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($stats['loan_portfolio']); ?></h3>
                <p>Loan Portfolio</p>
                <span class="stats-change positive">
                    <i class="fas fa-arrow-up"></i> 15% increase
                </span>
            </div>
        </div>
    </div>
</div>

<!-- Charts Row -->
<div class="row mb-4">
    <div class="col-xl-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Loan Disbursement Trend</h5>
            </div>
            <div class="card-body">
                <canvas id="loanChart"></canvas>
            </div>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Deposit Collection Trend</h5>
            </div>
            <div class="card-body">
                <canvas id="depositChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Recent Members and Loans -->
<div class="row">
    <div class="col-xl-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Recent Members</h5>
                <div class="card-tools">
                    <a href="modules/members/index.php" class="btn btn-sm btn-outline-primary">
                        View All <i class="fas fa-arrow-right ms-2"></i>
                    </a>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Member No</th>
                                <th>Name</th>
                                <th>Phone</th>
                                <th>Date Joined</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($member = $recent_members->fetch_assoc()): ?>
                                <tr>
                                    <td><span class="badge bg-primary"><?php echo $member['member_no']; ?></span></td>
                                    <td><?php echo $member['full_name']; ?></td>
                                    <td><?php echo $member['phone']; ?></td>
                                    <td><?php echo formatDate($member['date_joined']); ?></td>
                                    <td>
                                        <?php if ($member['membership_status'] == 'active'): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php elseif ($member['membership_status'] == 'pending'): ?>
                                            <span class="badge bg-warning">Pending</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Recent Loans</h5>
                <div class="card-tools">
                    <a href="modules/loans/index.php" class="btn btn-sm btn-outline-primary">
                        View All <i class="fas fa-arrow-right ms-2"></i>
                    </a>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Loan No</th>
                                <th>Member</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($loan = $recent_loans->fetch_assoc()): ?>
                                <tr>
                                    <td><span class="badge bg-info"><?php echo $loan['loan_no']; ?></span></td>
                                    <td><?php echo $loan['full_name']; ?></td>
                                    <td><?php echo formatCurrency($loan['principal_amount']); ?></td>
                                    <td>
                                        <?php
                                        $statusClass = [
                                            'pending' => 'warning',
                                            'approved' => 'info',
                                            'disbursed' => 'primary',
                                            'active' => 'success',
                                            'completed' => 'secondary',
                                            'defaulted' => 'danger'
                                        ][$loan['status']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $statusClass; ?>">
                                            <?php echo ucfirst($loan['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="modules/loans/view.php?id=<?php echo $loan['id']; ?>" class="btn btn-sm btn-outline-info">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Loan Chart
    const loanCtx = document.getElementById('loanChart').getContext('2d');
    new Chart(loanCtx, {
        type: 'line',
        data: {
            labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            datasets: [{
                label: 'Loan Disbursements',
                data: [1200000, 1500000, 1800000, 1600000, 2000000, 2200000, 2100000, 2500000, 2300000, 2800000, 3000000, 3200000],
                borderColor: 'rgb(75, 192, 192)',
                backgroundColor: 'rgba(75, 192, 192, 0.2)',
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    display: false
                }
            },
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

    // Deposit Chart
    const depositCtx = document.getElementById('depositChart').getContext('2d');
    new Chart(depositCtx, {
        type: 'bar',
        data: {
            labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            datasets: [{
                label: 'Deposits',
                data: [800000, 950000, 1100000, 1050000, 1300000, 1450000, 1400000, 1650000, 1550000, 1800000, 1950000, 2100000],
                backgroundColor: 'rgba(54, 162, 235, 0.5)',
                borderColor: 'rgb(54, 162, 235)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    display: false
                }
            },
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
</script>

<?php include 'includes/footer.php'; ?>
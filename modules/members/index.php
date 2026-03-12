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

// Get all members
$sql = "SELECT m.*, 
        (SELECT SUM(amount) FROM deposits WHERE member_id = m.id AND transaction_type = 'deposit') as total_deposits,
        (SELECT COUNT(*) FROM loans WHERE member_id = m.id) as total_loans
        FROM members m 
        ORDER BY m.created_at DESC";
$members = executeQuery($sql);

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
            <a href="add.php" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i>Add New Member
            </a>
            <button class="btn btn-success" onclick="exportMembers()">
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

<!-- Members Table -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Member No</th>
                        <th>Full Name</th>
                        <th>National ID</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Date Joined</th>
                        <th>Total Deposits</th>
                        <th>Loans</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($member = $members->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo $member['id']; ?></td>
                            <td>
                                <span class="badge bg-primary"><?php echo $member['member_no']; ?></span>
                            </td>
                            <td>
                                <a href="view.php?id=<?php echo $member['id']; ?>" class="text-decoration-none">
                                    <?php echo $member['full_name']; ?>
                                </a>
                            </td>
                            <td><?php echo $member['national_id']; ?></td>
                            <td><?php echo $member['phone']; ?></td>
                            <td><?php echo $member['email']; ?></td>
                            <td><?php echo formatDate($member['date_joined']); ?></td>
                            <td><?php echo formatCurrency($member['total_deposits'] ?? 0); ?></td>
                            <td>
                                <span class="badge bg-info"><?php echo $member['total_loans'] ?? 0; ?></span>
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
</script>

<?php include '../../includes/footer.php'; ?>
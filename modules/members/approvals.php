<?php
//show php errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../../config/config.php';
requireRole('admin'); // Only admin and officers can approve members

$page_title = 'Member Approvals';

// Handle approval action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $member_id = $_POST['member_id'];
    $action = $_POST['action'];
    $remarks = $_POST['remarks'] ?? '';

    if ($action == 'approve') {
        // Update member status to active
        $sql = "UPDATE members SET membership_status = 'active' WHERE id = ?";
        executeQuery($sql, "i", [$member_id]);

        // Create user account for member if not exists
        $member_sql = "SELECT * FROM members WHERE id = ?";
        $member_result = executeQuery($member_sql, "i", [$member_id]);
        $member = $member_result->fetch_assoc();

        // Check if user account already exists
        $user_check = executeQuery("SELECT id FROM users WHERE username = ?", "s", [$member['member_no']]);

        if ($user_check->num_rows == 0) {
            // Create username from member number
            $username = $member['member_no'];
            // Generate random password (member will reset on first login)
            $temp_password = bin2hex(random_bytes(4)); // 8 character temporary password
            $hashed_password = password_hash($temp_password, PASSWORD_DEFAULT);

            $user_sql = "INSERT INTO users (username, password, full_name, email, role, status, created_at) 
                         VALUES (?, ?, ?, ?, 'member', 1, NOW())";
            executeQuery($user_sql, "ssss", [$username, $hashed_password, $member['full_name'], $member['email']]);

            $user_id = executeQuery("SELECT LAST_INSERT_ID() as id")->fetch_assoc()['id'];

            // Link user to member
            executeQuery("UPDATE members SET user_id = ? WHERE id = ?", "ii", [$user_id, $member_id]);

            // Send SMS with login credentials (implement your SMS logic)
            $message = "Dear {$member['full_name']}, your membership has been approved. Login with Username: {$username} and Password: {$temp_password}. Please change your password on first login.";
            // sendSMS($member['phone'], $message);
        }

        logAudit('APPROVE', 'members', $member_id, ['status' => 'pending'], ['status' => 'active']);
        $_SESSION['success'] = 'Member approved successfully';
    } elseif ($action == 'reject') {
        // Update member status to rejected
        $sql = "UPDATE members SET membership_status = 'rejected' WHERE id = ?";
        executeQuery($sql, "i", [$member_id]);

        // Store rejection reason in session or another table
        // Since we don't have rejected_reason column, we'll log it
        logAudit('REJECT', 'members', $member_id, ['status' => 'pending'], ['status' => 'rejected', 'reason' => $remarks]);

        // Send rejection notification
        $member_sql = "SELECT * FROM members WHERE id = ?";
        $member_result = executeQuery($member_sql, "i", [$member_id]);
        $member = $member_result->fetch_assoc();

        $message = "Dear {$member['full_name']}, your membership application has been reviewed and was not approved at this time. Remarks: {$remarks}";
        // sendSMS($member['phone'], $message);

        $_SESSION['success'] = 'Member rejected';
    } elseif ($action == 'request_info') {
        // Request additional information from member
        $sql = "UPDATE members SET membership_status = 'pending' WHERE id = ?"; // Keep as pending, just add note
        executeQuery($sql, "i", [$member_id]);

        $member_sql = "SELECT * FROM members WHERE id = ?";
        $member_result = executeQuery($member_sql, "i", [$member_id]);
        $member = $member_result->fetch_assoc();

        // Log the info request
        logAudit('INFO_REQUEST', 'members', $member_id, null, ['remarks' => $remarks]);

        $message = "Dear {$member['full_name']}, we need additional information to process your membership. Remarks: {$remarks}. Please visit our office or upload documents online.";
        // sendSMS($member['phone'], $message);

        $_SESSION['success'] = 'Information requested from member';
    }

    header('Location: approvals.php');
    exit();
}

// Get pending members - using created_at instead of updated_at
$pending_sql = "SELECT m.*, 
                (SELECT COUNT(*) FROM deposits WHERE member_id = m.id) as deposit_count,
                (SELECT COUNT(*) FROM shares WHERE member_id = m.id) as share_count,
                u.username as user_account,
                u.id as user_id
                FROM members m
                LEFT JOIN users u ON m.user_id = u.id
                WHERE m.membership_status IN ('pending')
                ORDER BY m.created_at ASC";
$pending_members = executeQuery($pending_sql);

// Get recently approved/rejected members - using created_at with date filter
$recent_sql = "SELECT m.*, u.full_name as approved_by_name
               FROM members m
               LEFT JOIN users u ON m.user_id = u.id
               WHERE m.membership_status IN ('active', 'rejected')
               AND m.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
               ORDER BY m.created_at DESC
               LIMIT 20";
$recent_members = executeQuery($recent_sql);

// Get statistics
$stats_sql = "SELECT 
               SUM(CASE WHEN membership_status = 'pending' THEN 1 ELSE 0 END) as pending_count,
               SUM(CASE WHEN membership_status = 'active' AND MONTH(created_at) = MONTH(NOW()) THEN 1 ELSE 0 END) as approved_this_month,
               SUM(CASE WHEN membership_status = 'rejected' AND MONTH(created_at) = MONTH(NOW()) THEN 1 ELSE 0 END) as rejected_this_month
               FROM members";
$stats_result = executeQuery($stats_sql);
$stats = $stats_result->fetch_assoc();

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Member Approvals</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Members</a></li>
                <li class="breadcrumb-item active">Approvals</li>
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
<div class="row mb-4">
    <div class="col-xl-4 col-md-6">
        <div class="stats-card warning">
            <div class="stats-icon">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo number_format($stats['pending_count'] ?? 0); ?></h3>
                <p>Pending Approval</p>
            </div>
        </div>
    </div>

    <div class="col-xl-4 col-md-6">
        <div class="stats-card success">
            <div class="stats-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo number_format($stats['approved_this_month'] ?? 0); ?></h3>
                <p>Approved This Month</p>
            </div>
        </div>
    </div>

    <div class="col-xl-4 col-md-6">
        <div class="stats-card danger">
            <div class="stats-icon">
                <i class="fas fa-times-circle"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo number_format($stats['rejected_this_month'] ?? 0); ?></h3>
                <p>Rejected This Month</p>
            </div>
        </div>
    </div>
</div>

<!-- Pending Approvals Section -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title">Pending Member Approvals</h5>
        <div class="card-tools">
            <span class="badge bg-warning"><?php echo $stats['pending_count'] ?? 0; ?> Pending</span>
        </div>
    </div>
    <div class="card-body">
        <?php if ($pending_members->num_rows > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover datatable">
                    <thead>
                        <tr>
                            <th>Member No</th>
                            <th>Full Name</th>
                            <th>National ID</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Date Joined</th>
                            <th>Status</th>
                            <th>Initial Deposit</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($member = $pending_members->fetch_assoc()):
                            // Check if member has made initial deposit
                            $initial_deposit = executeQuery("
                            SELECT amount, deposit_date, reference_no 
                            FROM deposits 
                            WHERE member_id = ? AND transaction_type = 'deposit' 
                            ORDER BY deposit_date ASC LIMIT 1
                        ", "i", [$member['id']])->fetch_assoc();
                        ?>
                            <tr>
                                <td>
                                    <span class="badge bg-secondary"><?php echo $member['member_no']; ?></span>
                                </td>
                                <td>
                                    <strong><?php echo $member['full_name']; ?></strong>
                                </td>
                                <td><?php echo $member['national_id']; ?></td>
                                <td><?php echo $member['phone']; ?></td>
                                <td><?php echo $member['email'] ?: 'N/A'; ?></td>
                                <td><?php echo formatDate($member['date_joined']); ?></td>
                                <td>
                                    <span class="badge bg-warning">Pending</span>
                                </td>
                                <td>
                                    <?php if ($initial_deposit): ?>
                                        <span class="text-success">
                                            <i class="fas fa-check-circle"></i>
                                            <?php echo formatCurrency($initial_deposit['amount']); ?>
                                            <br><small><?php echo formatDate($initial_deposit['deposit_date']); ?></small>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-warning">No Deposit</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group" role="group">
                                        <button type="button" class="btn btn-sm btn-success" onclick="showApproveModal(<?php echo $member['id']; ?>, '<?php echo addslashes($member['full_name']); ?>')">
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                        <button type="button" class="btn btn-sm btn-info" onclick="showInfoModal(<?php echo $member['id']; ?>, '<?php echo addslashes($member['full_name']); ?>')">
                                            <i class="fas fa-question"></i> Request Info
                                        </button>
                                        <button type="button" class="btn btn-sm btn-danger" onclick="showRejectModal(<?php echo $member['id']; ?>, '<?php echo addslashes($member['full_name']); ?>')">
                                            <i class="fas fa-times"></i> Reject
                                        </button>
                                        <a href="view.php?id=<?php echo $member['id']; ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </div>

                                    <?php if (!$member['user_id']): ?>
                                        <div class="mt-1">
                                            <small class="text-warning">
                                                <i class="fas fa-exclamation-triangle"></i> No user account
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-center py-4">
                <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                <h5>No Pending Approvals</h5>
                <p class="text-muted">All member applications have been processed.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Recently Processed -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title">Recently Processed (Last 30 Days)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Member No</th>
                        <th>Full Name</th>
                        <th>Phone</th>
                        <th>Date Joined</th>
                        <th>Status</th>
                        <th>Processed Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($member = $recent_members->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo $member['member_no']; ?></td>
                            <td><?php echo $member['full_name']; ?></td>
                            <td><?php echo $member['phone']; ?></td>
                            <td><?php echo formatDate($member['date_joined']); ?></td>
                            <td>
                                <?php if ($member['membership_status'] == 'active'): ?>
                                    <span class="badge bg-success">Approved</span>
                                <?php elseif ($member['membership_status'] == 'rejected'): ?>
                                    <span class="badge bg-danger">Rejected</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo formatDate($member['created_at']); ?></td>
                            <td>
                                <a href="view.php?id=<?php echo $member['id']; ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-eye"></i> View
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Approval Modal -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Approve Member</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="member_id" id="approve_member_id">
                    <input type="hidden" name="action" value="approve">

                    <div class="text-center mb-3">
                        <i class="fas fa-user-check fa-3x text-success"></i>
                    </div>

                    <p>Are you sure you want to approve <strong id="approve_member_name"></strong>?</p>

                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>What happens next:</strong>
                        <ul class="mb-0 mt-2">
                            <li>Member status will be set to Active</li>
                            <li>A user account will be created for the member</li>
                            <li>Login credentials will be generated</li>
                            <li>Member can now apply for loans</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check me-2"></i>Approve Member
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Request Info Modal -->
<div class="modal fade" id="infoModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">Request Additional Information</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="member_id" id="info_member_id">
                    <input type="hidden" name="action" value="request_info">

                    <div class="text-center mb-3">
                        <i class="fas fa-question-circle fa-3x text-info"></i>
                    </div>

                    <p>Request more information from <strong id="info_member_name"></strong></p>

                    <div class="mb-3">
                        <label for="info_remarks" class="form-label">Information Required <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="info_remarks" name="remarks" rows="4" required
                            placeholder="Specify what documents or information are needed..."></textarea>
                        <small class="text-muted">This message will be logged for reference</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info text-white">
                        <i class="fas fa-paper-plane me-2"></i>Send Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Reject Member Application</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="member_id" id="reject_member_id">
                    <input type="hidden" name="action" value="reject">

                    <div class="text-center mb-3">
                        <i class="fas fa-times-circle fa-3x text-danger"></i>
                    </div>

                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        This action cannot be undone. The member will be notified.
                    </div>

                    <p>Please provide a reason for rejecting <strong id="reject_member_name"></strong>'s application:</p>

                    <div class="mb-3">
                        <label for="reject_reason" class="form-label">Rejection Reason <span class="text-danger">*</span></label>
                        <select class="form-control mb-2" id="reject_reason_select" onchange="updateRejectReason()">
                            <option value="">-- Select Reason --</option>
                            <option value="Incomplete documentation">Incomplete documentation</option>
                            <option value="Invalid identification">Invalid identification</option>
                            <option value="Does not meet membership criteria">Does not meet membership criteria</option>
                            <option value="Failed background check">Failed background check</option>
                            <option value="Duplicate application">Duplicate application</option>
                            <option value="Other">Other (specify below)</option>
                        </select>
                        <textarea class="form-control" id="reject_remarks" name="remarks" rows="3" required
                            placeholder="Provide detailed reason for rejection..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-times me-2"></i>Reject Application
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Show approve modal
    function showApproveModal(memberId, memberName) {
        document.getElementById('approve_member_id').value = memberId;
        document.getElementById('approve_member_name').textContent = memberName;

        var modal = new bootstrap.Modal(document.getElementById('approveModal'));
        modal.show();
    }

    // Show info request modal
    function showInfoModal(memberId, memberName) {
        document.getElementById('info_member_id').value = memberId;
        document.getElementById('info_member_name').textContent = memberName;

        var modal = new bootstrap.Modal(document.getElementById('infoModal'));
        modal.show();
    }

    // Show reject modal
    function showRejectModal(memberId, memberName) {
        document.getElementById('reject_member_id').value = memberId;
        document.getElementById('reject_member_name').textContent = memberName;

        var modal = new bootstrap.Modal(document.getElementById('rejectModal'));
        modal.show();
    }

    // Update reject reason textarea
    function updateRejectReason() {
        var select = document.getElementById('reject_reason_select');
        var textarea = document.getElementById('reject_remarks');

        if (select.value && select.value != 'Other') {
            textarea.value = select.value;
        } else {
            textarea.value = '';
        }
    }

    // Initialize DataTable
    $(document).ready(function() {
        $('.datatable').DataTable({
            pageLength: 25,
            order: [
                [5, 'asc']
            ], // Sort by date joined
            language: {
                emptyTable: "No pending approvals found"
            }
        });
    });
</script>

<style>
    .btn-group .btn {
        margin-right: 2px;
    }

    .btn-group .btn:last-child {
        margin-right: 0;
    }

    .table td {
        vertical-align: middle;
    }

    .modal-header.bg-success,
    .modal-header.bg-danger,
    .modal-header.bg-info {
        color: white;
    }

    .modal-header.bg-success .btn-close,
    .modal-header.bg-danger .btn-close,
    .modal-header.bg-info .btn-close {
        filter: brightness(0) invert(1);
    }

    @media (max-width: 768px) {
        .btn-group {
            display: flex;
            flex-direction: column;
        }

        .btn-group .btn {
            margin-right: 0;
            margin-bottom: 2px;
            border-radius: 4px !important;
        }
    }
</style>

<?php include '../../includes/footer.php'; ?>
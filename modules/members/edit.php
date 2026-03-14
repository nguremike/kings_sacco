<?php
require_once '../../config/config.php';
requireLogin();

$member_id = $_GET['id'] ?? 0;

// Get member details
$member_sql = "SELECT m.*, 
               u.username as user_account,
               (SELECT COUNT(*) FROM deposits WHERE member_id = m.id) as deposit_count,
               (SELECT COUNT(*) FROM loans WHERE member_id = m.id) as loan_count,
               (SELECT COUNT(*) FROM shares WHERE member_id = m.id) as share_count
               FROM members m
               LEFT JOIN users u ON m.user_id = u.id
               WHERE m.id = ?";
$member_result = executeQuery($member_sql, "i", [$member_id]);

if ($member_result->num_rows == 0) {
    $_SESSION['error'] = 'Member not found';
    header('Location: index.php');
    exit();
}

$member = $member_result->fetch_assoc();
$page_title = 'Edit Member - ' . $member['full_name'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $full_name = $_POST['full_name'];
    $national_id = $_POST['national_id'];
    $phone = $_POST['phone'];
    $email = $_POST['email'];
    $address = $_POST['address'];
    $date_joined = $_POST['date_joined'];
    $membership_status = $_POST['membership_status'];

    $conn = getConnection();
    $conn->begin_transaction();

    try {
        // Check if national ID already exists for another member
        $check_sql = "SELECT id FROM members WHERE national_id = ? AND id != ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("si", $national_id, $member_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $_SESSION['error'] = 'National ID already exists for another member';
            header('Location: edit.php?id=' . $member_id);
            exit();
        }

        // Check if phone already exists for another member
        $check_phone_sql = "SELECT id FROM members WHERE phone = ? AND id != ?";
        $check_phone_stmt = $conn->prepare($check_phone_sql);
        $check_phone_stmt->bind_param("si", $phone, $member_id);
        $check_phone_stmt->execute();
        $check_phone_result = $check_phone_stmt->get_result();

        if ($check_phone_result->num_rows > 0) {
            $_SESSION['error'] = 'Phone number already exists for another member';
            header('Location: edit.php?id=' . $member_id);
            exit();
        }

        // Update member
        $update_sql = "UPDATE members SET 
                       full_name = ?,
                       national_id = ?,
                       phone = ?,
                       email = ?,
                       address = ?,
                       date_joined = ?,
                       membership_status = ?,
                       updated_at = NOW()
                       WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("sssssssi", $full_name, $national_id, $phone, $email, $address, $date_joined, $membership_status, $member_id);
        $update_stmt->execute();

        // Update user account if exists and email changed
        if (!empty($member['user_id']) && !empty($email)) {
            $user_update_sql = "UPDATE users SET email = ?, full_name = ? WHERE id = ?";
            $user_update_stmt = $conn->prepare($user_update_sql);
            $user_update_stmt->bind_param("ssi", $email, $full_name, $member['user_id']);
            $user_update_stmt->execute();
        }

        $conn->commit();

        logAudit('UPDATE', 'members', $member_id, $member, $_POST);
        $_SESSION['success'] = 'Member updated successfully';

        header('Location: view.php?id=' . $member_id);
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Update failed: ' . $e->getMessage();
    }

    $conn->close();
}

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Edit Member</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Members</a></li>
                <li class="breadcrumb-item"><a href="view.php?id=<?php echo $member_id; ?>"><?php echo $member['full_name']; ?></a></li>
                <li class="breadcrumb-item active">Edit</li>
            </ul>
        </div>
        <div class="col-auto">
            <a href="view.php?id=<?php echo $member_id; ?>" class="btn btn-info">
                <i class="fas fa-eye me-2"></i>View Member
            </a>
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to List
            </a>
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

<div class="row">
    <div class="col-md-8">
        <!-- Edit Member Form -->
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="card-title mb-0"><i class="fas fa-user-edit me-2"></i>Edit Member Information</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" class="needs-validation" novalidate>
                    <div class="row">
                        <!-- Member Number (Read Only) -->
                        <div class="col-md-6 mb-3">
                            <label for="member_no" class="form-label">Member Number</label>
                            <input type="text" class="form-control" id="member_no" value="<?php echo $member['member_no']; ?>" readonly>
                            <small class="text-muted">Member number cannot be changed</small>
                        </div>

                        <!-- Date Joined -->
                        <div class="col-md-6 mb-3">
                            <label for="date_joined" class="form-label">Date Joined <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="date_joined" name="date_joined"
                                value="<?php echo $member['date_joined']; ?>" required>
                            <div class="invalid-feedback">Please select date joined</div>
                        </div>

                        <!-- Full Name -->
                        <div class="col-md-6 mb-3">
                            <label for="full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="full_name" name="full_name"
                                value="<?php echo htmlspecialchars($member['full_name']); ?>" required>
                            <div class="invalid-feedback">Please enter full name</div>
                        </div>

                        <!-- National ID -->
                        <div class="col-md-6 mb-3">
                            <label for="national_id" class="form-label">National ID <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="national_id" name="national_id"
                                value="<?php echo $member['national_id']; ?>" required>
                            <div class="invalid-feedback">Please enter national ID</div>
                        </div>

                        <!-- Phone -->
                        <div class="col-md-6 mb-3">
                            <label for="phone" class="form-label">Phone Number <span class="text-danger">*</span></label>
                            <input type="tel" class="form-control" id="phone" name="phone"
                                value="<?php echo $member['phone']; ?>" required>
                            <div class="invalid-feedback">Please enter phone number</div>
                        </div>

                        <!-- Email -->
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="email" name="email"
                                value="<?php echo $member['email']; ?>">
                            <small class="text-muted">Used for user account and notifications</small>
                        </div>

                        <!-- Address -->
                        <div class="col-md-12 mb-3">
                            <label for="address" class="form-label">Address</label>
                            <textarea class="form-control" id="address" name="address" rows="2"><?php echo htmlspecialchars($member['address']); ?></textarea>
                        </div>

                        <!-- Membership Status -->
                        <div class="col-md-6 mb-3">
                            <label for="membership_status" class="form-label">Membership Status <span class="text-danger">*</span></label>
                            <select class="form-control" id="membership_status" name="membership_status" required>
                                <option value="active" <?php echo $member['membership_status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="pending" <?php echo $member['membership_status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="suspended" <?php echo $member['membership_status'] == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                <option value="closed" <?php echo $member['membership_status'] == 'closed' ? 'selected' : ''; ?>>Closed</option>
                                <option value="rejected" <?php echo $member['membership_status'] == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                            <small class="text-muted">Changing status will affect member's access</small>
                        </div>

                        <!-- User Account Info (Read Only) -->
                        <div class="col-md-6 mb-3">
                            <label for="user_account" class="form-label">User Account</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="user_account"
                                    value="<?php echo $member['user_account'] ?: 'No user account'; ?>" readonly>
                                <?php if ($member['user_account']): ?>
                                    <button class="btn btn-outline-danger" type="button" onclick="resetPassword()">
                                        <i class="fas fa-key"></i> Reset Password
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-outline-success" type="button" onclick="createUserAccount()">
                                        <i class="fas fa-user-plus"></i> Create Account
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Statistics Summary -->
                    <div class="row mt-3">
                        <div class="col-12">
                            <div class="alert alert-info">
                                <div class="row">
                                    <div class="col-md-3">
                                        <strong>Deposits:</strong> <?php echo $member['deposit_count']; ?> transactions
                                    </div>
                                    <div class="col-md-3">
                                        <strong>Loans:</strong> <?php echo $member['loan_count']; ?> loans
                                    </div>
                                    <div class="col-md-3">
                                        <strong>Shares:</strong> <?php echo $member['share_count']; ?> transactions
                                    </div>
                                    <div class="col-md-3">
                                        <strong>Last Updated:</strong> <?php echo $member['updated_at'] ? formatDate($member['updated_at']) : 'Never'; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr>

                    <div class="row">
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Update Member
                            </button>
                            <a href="view.php?id=<?php echo $member_id; ?>" class="btn btn-secondary">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <!-- Member Photo -->
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="card-title mb-0"><i class="fas fa-camera me-2"></i>Member Photo</h5>
            </div>
            <div class="card-body text-center">
                <div class="mb-3">
                    <?php if (isset($member['photo']) && file_exists('../../uploads/members/' . $member['photo'])): ?>
                        <img src="../../uploads/members/<?php echo $member['photo']; ?>" class="img-fluid rounded-circle" style="width: 150px; height: 150px; object-fit: cover;" alt="Profile">
                    <?php else: ?>
                        <div class="bg-light d-inline-block p-4 rounded-circle">
                            <i class="fas fa-user-circle fa-5x text-secondary"></i>
                        </div>
                    <?php endif; ?>
                </div>

                <form action="upload-photo.php" method="POST" enctype="multipart/form-data" id="photoForm">
                    <input type="hidden" name="member_id" value="<?php echo $member_id; ?>">
                    <div class="mb-3">
                        <input type="file" class="form-control" name="photo" accept="image/*" id="photoInput">
                        <small class="text-muted">Max size: 2MB. Allowed: JPG, PNG, GIF</small>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary" id="uploadBtn" disabled>
                        <i class="fas fa-upload me-2"></i>Upload Photo
                    </button>
                </form>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="card">
            <div class="card-header bg-warning">
                <h5 class="card-title mb-0"><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="../deposits/add.php?member_id=<?php echo $member_id; ?>" class="btn btn-outline-success">
                        <i class="fas fa-plus-circle me-2"></i>Add Deposit
                    </a>
                    <a href="../shares/contributions.php?member_id=<?php echo $member_id; ?>" class="btn btn-outline-primary">
                        <i class="fas fa-coins me-2"></i>Add Share Contribution
                    </a>
                    <a href="../loans/apply.php?member_id=<?php echo $member_id; ?>" class="btn btn-outline-warning">
                        <i class="fas fa-hand-holding-usd me-2"></i>Apply for Loan
                    </a>
                    <a href="statement.php?id=<?php echo $member_id; ?>" class="btn btn-outline-info">
                        <i class="fas fa-file-invoice me-2"></i>View Statement
                    </a>
                    <?php if (hasRole('admin')): ?>
                        <button type="button" class="btn btn-outline-danger" onclick="confirmDelete(<?php echo $member_id; ?>)">
                            <i class="fas fa-trash me-2"></i>Delete Member
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Audit Info -->
        <div class="card mt-4">
            <div class="card-header bg-secondary text-white">
                <h5 class="card-title mb-0"><i class="fas fa-history me-2"></i>Audit Information</h5>
            </div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr>
                        <th>Created:</th>
                        <td><?php echo formatDate($member['created_at']); ?></td>
                    </tr>
                    <tr>
                        <th>Last Updated:</th>
                        <td><?php echo $member['updated_at'] ? formatDate($member['updated_at']) : 'Never'; ?></td>
                    </tr>
                    <tr>
                        <th>User Account:</th>
                        <td>
                            <?php if ($member['user_account']): ?>
                                <span class="badge bg-success">Created</span>
                            <?php else: ?>
                                <span class="badge bg-warning">Not Created</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title">Reset User Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to reset the password for <strong><?php echo $member['full_name']; ?></strong>?</p>
                <p>A new temporary password will be generated and sent via SMS.</p>

                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    The member will be required to change password on next login.
                </div>

                <form action="reset-password.php" method="POST" id="resetPasswordForm">
                    <input type="hidden" name="member_id" value="<?php echo $member_id; ?>">
                    <input type="hidden" name="user_id" value="<?php echo $member['user_id']; ?>">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="resetPasswordForm" class="btn btn-warning">
                    <i class="fas fa-key me-2"></i>Reset Password
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Create User Modal -->
<div class="modal fade" id="createUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">Create User Account</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Create a user account for <strong><?php echo $member['full_name']; ?></strong>?</p>
                <p>A username will be created using the member number: <strong><?php echo $member['member_no']; ?></strong></p>
                <p>A temporary password will be generated and sent via SMS.</p>

                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i>
                    The member will be able to login to the system.
                </div>

                <form action="create-user.php" method="POST" id="createUserForm">
                    <input type="hidden" name="member_id" value="<?php echo $member_id; ?>">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="createUserForm" class="btn btn-success">
                    <i class="fas fa-user-plus me-2"></i>Create Account
                </button>
            </div>
        </div>
    </div>
</div>

<script>
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

    // Photo upload handling
    document.getElementById('photoInput').addEventListener('change', function(e) {
        var uploadBtn = document.getElementById('uploadBtn');
        var file = e.target.files[0];

        if (file) {
            // Check file size (2MB limit)
            if (file.size > 2 * 1024 * 1024) {
                alert('File size must be less than 2MB');
                this.value = '';
                uploadBtn.disabled = true;
                return;
            }

            // Check file type
            var fileType = file.type;
            if (!fileType.match(/image\/(jpeg|jpg|png|gif)/)) {
                alert('Only JPG, PNG, and GIF images are allowed');
                this.value = '';
                uploadBtn.disabled = true;
                return;
            }

            uploadBtn.disabled = false;
        } else {
            uploadBtn.disabled = true;
        }
    });

    // Reset password
    function resetPassword() {
        var modal = new bootstrap.Modal(document.getElementById('resetPasswordModal'));
        modal.show();
    }

    // Create user account
    function createUserAccount() {
        var modal = new bootstrap.Modal(document.getElementById('createUserModal'));
        modal.show();
    }

    // Confirm delete
    function confirmDelete(id) {
        Swal.fire({
            title: 'Delete Member',
            text: 'Are you sure you want to delete this member? This action cannot be undone and may affect related records.',
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

    // Phone number validation
    document.getElementById('phone').addEventListener('input', function(e) {
        this.value = this.value.replace(/[^0-9+]/g, '');
    });

    // National ID validation
    document.getElementById('national_id').addEventListener('input', function(e) {
        this.value = this.value.replace(/[^0-9]/g, '');
    });

    // Warn before leaving if form is dirty
    var formChanged = false;
    var form = document.querySelector('form.needs-validation');

    form.addEventListener('input', function() {
        formChanged = true;
    });

    window.addEventListener('beforeunload', function(e) {
        if (formChanged) {
            e.preventDefault();
            e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
        }
    });

    form.addEventListener('submit', function() {
        formChanged = false;
    });
</script>

<style>
    /* Custom styles for edit page */
    .card {
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    }

    .card-header {
        font-weight: 600;
    }

    .form-label {
        font-weight: 500;
        color: #495057;
    }

    .alert-info {
        background-color: #e3f2fd;
        border-color: #b8e1ff;
    }

    @media (max-width: 768px) {
        .btn-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .btn-group .btn {
            margin: 0;
            border-radius: 4px !important;
        }
    }

    /* Readonly field styling */
    input[readonly] {
        background-color: #f8f9fa;
        cursor: not-allowed;
    }

    /* Photo upload styling */
    #photoInput {
        font-size: 0.9rem;
    }

    #uploadBtn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    /* Quick actions grid */
    .d-grid.gap-2 .btn {
        text-align: left;
        padding: 10px 15px;
    }

    .d-grid.gap-2 .btn i {
        width: 20px;
        text-align: center;
    }
</style>

<?php include '../../includes/footer.php'; ?>
<?php
require_once '../../config/config.php';
requireLogin();

$page_title = 'Add New Member';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $member_no = generateMemberNumber();
    $full_name = $_POST['full_name'];
    $national_id = $_POST['national_id'];
    $phone = $_POST['phone'];
    $email = $_POST['email'];
    $address = $_POST['address'];
    $date_joined = $_POST['date_joined'];

    // Check if national ID already exists
    $checkSql = "SELECT id FROM members WHERE national_id = ?";
    $checkResult = executeQuery($checkSql, "s", [$national_id]);

    if ($checkResult->num_rows > 0) {
        $_SESSION['error'] = 'National ID already exists';
    } else {
        $sql = "INSERT INTO members (member_no, full_name, national_id, phone, email, address, date_joined, membership_status, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?)";

        $insert_id = insertAndGetId($sql, "sssssssi", [
            $member_no,
            $full_name,
            $national_id,
            $phone,
            $email,
            $address,
            $date_joined,
            getCurrentUserId()
        ]);

        if ($insert_id) {
            logAudit('INSERT', 'members', $insert_id, null, $_POST);
            $_SESSION['success'] = 'Member added successfully';
            header('Location: index.php');
            exit();
        } else {
            $_SESSION['error'] = 'Failed to add member';
        }
    }
}

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Add New Member</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Members</a></li>
                <li class="breadcrumb-item active">Add Member</li>
            </ul>
        </div>
    </div>
</div>

<!-- Alert Messages -->
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

<!-- Add Member Form -->
<div class="card">
    <div class="card-body">
        <form method="POST" action="" class="needs-validation" novalidate>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="full_name" name="full_name" required>
                    <div class="invalid-feedback">Please enter full name</div>
                </div>

                <div class="col-md-6 mb-3">
                    <label for="national_id" class="form-label">National ID <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="national_id" name="national_id" required>
                    <div class="invalid-feedback">Please enter national ID</div>
                </div>

                <div class="col-md-6 mb-3">
                    <label for="phone" class="form-label">Phone Number <span class="text-danger">*</span></label>
                    <input type="tel" class="form-control" id="phone" name="phone" required>
                    <div class="invalid-feedback">Please enter phone number</div>
                </div>

                <div class="col-md-6 mb-3">
                    <label for="email" class="form-label">Email Address</label>
                    <input type="email" class="form-control" id="email" name="email">
                </div>

                <div class="col-md-6 mb-3">
                    <label for="date_joined" class="form-label">Date Joined <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" id="date_joined" name="date_joined"
                        value="<?php echo date('Y-m-d'); ?>" required>
                    <div class="invalid-feedback">Please select date joined</div>
                </div>

                <div class="col-md-12 mb-3">
                    <label for="address" class="form-label">Address</label>
                    <textarea class="form-control" id="address" name="address" rows="3"></textarea>
                </div>
            </div>

            <hr>

            <div class="row">
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Save Member
                    </button>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-times me-2"></i>Cancel
                    </a>
                </div>
            </div>
        </form>
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
</script>

<?php include '../../includes/footer.php'; ?>
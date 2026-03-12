<?php
require_once '../../config/config.php';
requireLogin();

$loan_id = $_GET['loan_id'] ?? 0;

// Get loan details
$loanSql = "SELECT l.*, m.full_name, m.member_no, lp.product_name 
            FROM loans l 
            JOIN members m ON l.member_id = m.id 
            JOIN loan_products lp ON l.product_id = lp.id 
            WHERE l.id = ?";
$loanResult = executeQuery($loanSql, "i", [$loan_id]);

if ($loanResult->num_rows == 0) {
    $_SESSION['error'] = 'Loan not found';
    header('Location: index.php');
    exit();
}

$loan = $loanResult->fetch_assoc();
$page_title = 'Add Guarantors - ' . $loan['loan_no'];

// Get existing guarantors
$guarantors = executeQuery("
    SELECT lg.*, m.full_name, m.member_no 
    FROM loan_guarantors lg 
    JOIN members m ON lg.guarantor_member_id = m.id 
    WHERE lg.loan_id = ?
", "i", [$loan_id]);

$guarantor_count = $guarantors->num_rows;

// Get eligible guarantors (active members excluding loan applicant)
$eligible = executeQuery("
    SELECT id, member_no, full_name 
    FROM members 
    WHERE id != ? AND membership_status = 'active'
    ORDER BY full_name
", "i", [$loan['member_id']]);

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_guarantor'])) {
    $guarantor_id = $_POST['guarantor_id'];
    $guaranteed_amount = $_POST['guaranteed_amount'];

    // Check if guarantor already added
    $checkSql = "SELECT id FROM loan_guarantors WHERE loan_id = ? AND guarantor_member_id = ?";
    $checkResult = executeQuery($checkSql, "ii", [$loan_id, $guarantor_id]);

    if ($checkResult->num_rows > 0) {
        $_SESSION['error'] = 'This member is already a guarantor for this loan';
    } else {
        $sql = "INSERT INTO loan_guarantors (loan_id, guarantor_member_id, guaranteed_amount, status) 
                VALUES (?, ?, ?, 'pending')";

        if (insertAndGetId($sql, "iid", [$loan_id, $guarantor_id, $guaranteed_amount])) {
            logAudit('INSERT', 'loan_guarantors', $loan_id, null, $_POST);
            $_SESSION['success'] = 'Guarantor added successfully';
        } else {
            $_SESSION['error'] = 'Failed to add guarantor';
        }
    }

    header('Location: guarantors.php?loan_id=' . $loan_id);
    exit();
}

if (isset($_GET['remove']) && hasRole('admin')) {
    $guarantor_id = $_GET['remove'];

    $sql = "DELETE FROM loan_guarantors WHERE id = ? AND loan_id = ?";
    executeQuery($sql, "ii", [$guarantor_id, $loan_id]);

    logAudit('DELETE', 'loan_guarantors', $guarantor_id, null, null);
    $_SESSION['success'] = 'Guarantor removed successfully';

    header('Location: guarantors.php?loan_id=' . $loan_id);
    exit();
}

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Loan Guarantors</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Loans</a></li>
                <li class="breadcrumb-item active">Guarantors</li>
            </ul>
        </div>
        <div class="col-auto">
            <?php if ($guarantor_count < 3 && $loan['status'] == 'guarantor_pending'): ?>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addGuarantorModal">
                    <i class="fas fa-plus me-2"></i>Add Guarantor
                </button>
            <?php endif; ?>

            <?php if ($guarantor_count >= 3 && $loan['status'] == 'guarantor_pending' && hasRole('admin')): ?>
                <a href="process-guarantors.php?loan_id=<?php echo $loan_id; ?>" class="btn btn-success">
                    <i class="fas fa-check-circle me-2"></i>Submit for Approval
                </a>
            <?php endif; ?>
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

<!-- Loan Summary Card -->
<div class="card mb-4">
    <div class="card-body">
        <div class="row">
            <div class="col-md-3">
                <p class="text-muted mb-1">Loan Number</p>
                <h6 class="fw-bold"><?php echo $loan['loan_no']; ?></h6>
            </div>
            <div class="col-md-3">
                <p class="text-muted mb-1">Member</p>
                <h6 class="fw-bold"><?php echo $loan['full_name']; ?></h6>
                <small class="text-muted"><?php echo $loan['member_no']; ?></small>
            </div>
            <div class="col-md-2">
                <p class="text-muted mb-1">Loan Amount</p>
                <h6 class="fw-bold"><?php echo formatCurrency($loan['principal_amount']); ?></h6>
            </div>
            <div class="col-md-2">
                <p class="text-muted mb-1">Guarantors Required</p>
                <h6 class="fw-bold"><?php echo $guarantor_count; ?>/3</h6>
            </div>
            <div class="col-md-2">
                <p class="text-muted mb-1">Status</p>
                <span class="badge bg-warning"><?php echo ucfirst(str_replace('_', ' ', $loan['status'])); ?></span>
            </div>
        </div>
    </div>
</div>

<!-- Guarantors List -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title">Guarantors List</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Guarantor Name</th>
                        <th>Member No</th>
                        <th>Guaranteed Amount</th>
                        <th>Status</th>
                        <th>Date Added</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $counter = 1;
                    $total_guaranteed = 0;
                    while ($guarantor = $guarantors->fetch_assoc()):
                        $total_guaranteed += $guarantor['guaranteed_amount'];
                    ?>
                        <tr>
                            <td><?php echo $counter++; ?></td>
                            <td><?php echo $guarantor['full_name']; ?></td>
                            <td><?php echo $guarantor['member_no']; ?></td>
                            <td><?php echo formatCurrency($guarantor['guaranteed_amount']); ?></td>
                            <td>
                                <?php if ($guarantor['status'] == 'approved'): ?>
                                    <span class="badge bg-success">Approved</span>
                                <?php elseif ($guarantor['status'] == 'pending'): ?>
                                    <span class="badge bg-warning">Pending</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Rejected</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo formatDate($guarantor['created_at']); ?></td>
                            <td>
                                <?php if ($guarantor['status'] == 'pending' && hasRole('admin')): ?>
                                    <a href="approve-guarantor.php?id=<?php echo $guarantor['id']; ?>"
                                        class="btn btn-sm btn-outline-success" title="Approve">
                                        <i class="fas fa-check"></i>
                                    </a>
                                    <a href="reject-guarantor.php?id=<?php echo $guarantor['id']; ?>"
                                        class="btn btn-sm btn-outline-danger" title="Reject">
                                        <i class="fas fa-times"></i>
                                    </a>
                                <?php endif; ?>

                                <?php if ($loan['status'] == 'guarantor_pending'): ?>
                                    <a href="guarantors.php?loan_id=<?php echo $loan_id; ?>&remove=<?php echo $guarantor['id']; ?>"
                                        class="btn btn-sm btn-outline-danger" title="Remove"
                                        onclick="return confirm('Are you sure you want to remove this guarantor?')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>

                    <?php if ($guarantor_count == 0): ?>
                        <tr>
                            <td colspan="7" class="text-center">No guarantors added yet</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr class="table-info">
                        <th colspan="3" class="text-end">Total Guaranteed Amount:</th>
                        <th><?php echo formatCurrency($total_guaranteed); ?></th>
                        <th colspan="3"></th>
                    </tr>
                    <tr>
                        <th colspan="3" class="text-end">Required Coverage:</th>
                        <th><?php echo formatCurrency($loan['principal_amount']); ?></th>
                        <th colspan="3"></th>
                    </tr>
                    <tr>
                        <th colspan="3" class="text-end">Coverage Status:</th>
                        <th>
                            <?php if ($total_guaranteed >= $loan['principal_amount']): ?>
                                <span class="badge bg-success">Fully Covered</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Under Covered (<?php echo formatCurrency($loan['principal_amount'] - $total_guaranteed); ?> short)</span>
                            <?php endif; ?>
                        </th>
                        <th colspan="3"></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- Add Guarantor Modal -->
<div class="modal fade" id="addGuarantorModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title">Add Guarantor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="guarantor_id" class="form-label">Select Guarantor <span class="text-danger">*</span></label>
                        <select class="form-control select2-modal" id="guarantor_id" name="guarantor_id" required style="width: 100%;">
                            <option value="">-- Select Member --</option>
                            <?php
                            $eligible->data_seek(0);
                            while ($member = $eligible->fetch_assoc()):
                            ?>
                                <option value="<?php echo $member['id']; ?>">
                                    <?php echo $member['full_name']; ?> (<?php echo $member['member_no']; ?>)
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="guaranteed_amount" class="form-label">Guaranteed Amount <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="guaranteed_amount" name="guaranteed_amount"
                            min="1000" max="<?php echo $loan['principal_amount']; ?>" step="1000" required>
                        <small class="text-muted">Maximum: <?php echo formatCurrency($loan['principal_amount']); ?></small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_guarantor" class="btn btn-primary">Add Guarantor</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    $(document).ready(function() {
        // Initialize Select2 in modal
        $('.select2-modal').select2({
            theme: 'bootstrap-5',
            width: '100%',
            dropdownParent: $('#addGuarantorModal')
        });

        // Set max guaranteed amount
        $('#guaranteed_amount').attr('max', <?php echo $loan['principal_amount']; ?>);
    });
</script>

<?php include '../../includes/footer.php'; ?>
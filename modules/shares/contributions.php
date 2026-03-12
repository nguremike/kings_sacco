<?php
require_once '../../config/config.php';
requireLogin();

// Include the functions file that contains checkAndIssueShares
require_once '../../includes/functions.php';

$page_title = 'Share Contributions';

// Handle contribution submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'add_contribution') {
        $member_id = $_POST['member_id'];
        $amount = $_POST['amount'];
        $contribution_date = $_POST['contribution_date'] ?? date('Y-m-d');
        $payment_method = $_POST['payment_method'];
        $reference_no = $_POST['reference_no'] ?? 'CONTRIB' . time();
        $notes = $_POST['notes'] ?? '';
        $created_by = getCurrentUserId();

        $conn = getConnection();
        $conn->begin_transaction();

        try {
            // Add contribution record
            $sql = "INSERT INTO share_contributions (member_id, amount, contribution_date, reference_no, payment_method, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            // Fix: Use variables instead of direct values
            $stmt->bind_param("idssssi", $member_id, $amount, $contribution_date, $reference_no, $payment_method, $notes, $created_by);
            $stmt->execute();

            // Update member total contributions
            $update_sql = "UPDATE members SET total_share_contributions = total_share_contributions + ? WHERE id = ?";
            $stmt2 = $conn->prepare($update_sql);
            // Fix: Use variables instead of direct values
            $stmt2->bind_param("di", $amount, $member_id);
            $stmt2->execute();

            // Get member name for transaction description
            $member_result = executeQuery("SELECT full_name FROM members WHERE id = ?", "i", [$member_id]);
            $member_data = $member_result->fetch_assoc();
            $member_name = $member_data['full_name'];

            // Create transaction record
            $trans_no = 'SHR' . time() . rand(100, 999);
            $trans_sql = "INSERT INTO transactions (transaction_no, transaction_date, description, debit_account, credit_account, amount, reference_type, reference_id, created_by)
                         VALUES (?, ?, ?, 'SHARE_CONTRIBUTIONS', 'CASH', ?, 'share_contribution', ?, ?)";
            $stmt3 = $conn->prepare($trans_sql);
            $desc = "Share contribution - {$member_name}";
            // Fix: Use variables instead of direct values
            $stmt3->bind_param("sssiii", $trans_no, $contribution_date, $desc, $amount, $member_id, $created_by);
            $stmt3->execute();

            // Check if this completes any full shares
            checkAndIssueShares($conn, $member_id);

            $conn->commit();
            $_SESSION['success'] = 'Share contribution added successfully';
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = 'Failed to add contribution: ' . $e->getMessage();
        }

        $conn->close();
        header('Location: contributions.php');
        exit();
    }
}

// Get all contributions
$contributions_sql = "SELECT sc.*, m.full_name, m.member_no, u.full_name as added_by_name
                      FROM share_contributions sc
                      JOIN members m ON sc.member_id = m.id
                      LEFT JOIN users u ON sc.created_by = u.id
                      ORDER BY sc.contribution_date DESC, sc.created_at DESC";
$contributions = executeQuery($contributions_sql);

// Get members for dropdown
$members = executeQuery("SELECT id, member_no, full_name, total_share_contributions, full_shares_issued, partial_share_balance 
                        FROM members WHERE membership_status = 'active' ORDER BY full_name");

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Share Contributions</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Shares</a></li>
                <li class="breadcrumb-item active">Contributions</li>
            </ul>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#contributionModal">
                <i class="fas fa-plus me-2"></i>Add Contribution
            </button>
            <a href="index.php" class="btn btn-info">
                <i class="fas fa-chart-pie me-2"></i>Share Certificates
            </a>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <?php
    $total_contributions = executeQuery("SELECT COALESCE(SUM(amount), 0) as total FROM share_contributions")->fetch_assoc();
    $total_members_contributing = executeQuery("SELECT COUNT(DISTINCT member_id) as total FROM share_contributions")->fetch_assoc();
    $avg_contribution = executeQuery("SELECT COALESCE(AVG(amount), 0) as avg FROM share_contributions")->fetch_assoc();
    ?>

    <div class="col-xl-3 col-md-6">
        <div class="stats-card primary">
            <div class="stats-icon">
                <i class="fas fa-coins"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($total_contributions['total'] ?? 0); ?></h3>
                <p>Total Contributions</p>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stats-card success">
            <div class="stats-icon">
                <i class="fas fa-users"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo $total_members_contributing['total'] ?? 0; ?></h3>
                <p>Contributing Members</p>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stats-card info">
            <div class="stats-icon">
                <i class="fas fa-calculator"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($avg_contribution['avg'] ?? 0); ?></h3>
                <p>Average Contribution</p>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="stats-card warning">
            <div class="stats-icon">
                <i class="fas fa-trophy"></i>
            </div>
            <div class="stats-content">
                <h3><?php
                    $full_shares = executeQuery("SELECT COALESCE(SUM(share_count), 0) as total FROM shares_issued")->fetch_assoc();
                    echo $full_shares['total'] ?? 0;
                    ?></h3>
                <p>Full Shares Issued</p>
            </div>
        </div>
    </div>
</div>

<!-- Contributions Table -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title">Share Contribution History</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Member</th>
                        <th>Member No</th>
                        <th>Amount</th>
                        <th>Payment Method</th>
                        <th>Reference</th>
                        <th>Added By</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($contrib = $contributions->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo formatDate($contrib['contribution_date']); ?></td>
                            <td>
                                <a href="../members/view.php?id=<?php echo $contrib['member_id']; ?>">
                                    <?php echo $contrib['full_name']; ?>
                                </a>
                            </td>
                            <td><?php echo $contrib['member_no']; ?></td>
                            <td class="text-success fw-bold"><?php echo formatCurrency($contrib['amount']); ?></td>
                            <td>
                                <span class="badge bg-info"><?php echo ucfirst($contrib['payment_method']); ?></span>
                            </td>
                            <td><?php echo $contrib['reference_no'] ?: '-'; ?></td>
                            <td><?php echo $contrib['added_by_name'] ?: 'System'; ?></td>
                            <td><?php echo $contrib['notes'] ?: '-'; ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Contribution Modal -->
<div class="modal fade" id="contributionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Add Share Contribution</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_contribution">

                    <div class="mb-3">
                        <label for="member_id" class="form-label">Select Member <span class="text-danger">*</span></label>
                        <select class="form-control select2" id="member_id" name="member_id" required>
                            <option value="">-- Select Member --</option>
                            <?php
                            $members->data_seek(0);
                            while ($member = $members->fetch_assoc()):
                            ?>
                                <option value="<?php echo $member['id']; ?>"
                                    data-total="<?php echo $member['total_share_contributions']; ?>"
                                    data-shares="<?php echo $member['full_shares_issued']; ?>"
                                    data-balance="<?php echo $member['partial_share_balance']; ?>">
                                    <?php echo $member['full_name']; ?> (<?php echo $member['member_no']; ?>) -
                                    Shares: <?php echo $member['full_shares_issued']; ?>,
                                    Balance: <?php echo formatCurrency($member['partial_share_balance']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="amount" class="form-label">Amount (KES) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="amount" name="amount"
                                min="100" step="100" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="payment_method" class="form-label">Payment Method <span class="text-danger">*</span></label>
                            <select class="form-control" id="payment_method" name="payment_method" required>
                                <option value="cash">Cash</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="mpesa">M-Pesa</option>
                                <option value="mobile">Mobile Money</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="contribution_date" class="form-label">Contribution Date</label>
                            <input type="date" class="form-control" id="contribution_date" name="contribution_date"
                                value="<?php echo date('Y-m-d'); ?>">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="reference_no" class="form-label">Reference Number</label>
                            <input type="text" class="form-control" id="reference_no" name="reference_no"
                                value="CONTRIB<?php echo time(); ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
                    </div>

                    <div class="alert alert-info" id="shareProgress">
                        <strong>Share Progress:</strong><br>
                        <span id="memberShareInfo"></span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save me-2"></i>Add Contribution
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    $(document).ready(function() {
        $('.select2').select2({
            theme: 'bootstrap-5',
            width: '100%',
            dropdownParent: $('#contributionModal')
        });

        // Update share progress when member is selected
        $('#member_id').on('change', function() {
            var selected = $(this).find('option:selected');
            var total = selected.data('total') || 0;
            var shares = selected.data('shares') || 0;
            var balance = selected.data('balance') || 0;

            var progress = ((balance / 10000) * 100).toFixed(1);
            var nextShare = 10000 - balance;

            $('#memberShareInfo').html(`
            Total Contributions: ${formatCurrency(total)}<br>
            Full Shares Issued: ${shares}<br>
            Current Balance: ${formatCurrency(balance)}<br>
            Progress to Next Share: ${progress}%<br>
            <strong>Needed for next share: ${formatCurrency(nextShare)}</strong>
        `);
        });
    });

    function formatCurrency(amount) {
        return 'KES ' + parseFloat(amount).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
</script>

<?php include '../../includes/footer.php'; ?>
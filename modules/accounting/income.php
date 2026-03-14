<?php
// modules/accounting/income.php
require_once '../../config/config.php';
require_once '../../includes/accounting_functions.php';
requireRole('admin');

$page_title = 'Income/Revenue Management';

// Handle income recording
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'record_income') {
        $income_date = $_POST['income_date'] ?? date('Y-m-d');
        $income_type = $_POST['income_type'];
        $amount = $_POST['amount'];
        $payment_method = $_POST['payment_method'];
        $reference_no = $_POST['reference_no'] ?? 'INC' . time();
        $description = $_POST['description'];
        $received_from = $_POST['received_from'];
        $category_id = $_POST['category_id'];
        $notes = $_POST['notes'] ?? '';

        $conn = getConnection();
        $conn->begin_transaction();

        try {
            // Insert income record
            $sql = "INSERT INTO income (income_date, income_type, amount, payment_method, reference_no, 
                    description, received_from, category_id, notes, created_by, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                "ssdsssssis",
                $income_date,
                $income_type,
                $amount,
                $payment_method,
                $reference_no,
                $description,
                $received_from,
                $category_id,
                $notes,
                getCurrentUserId()
            );
            $stmt->execute();
            $income_id = $conn->insert_id;

            // Determine income account based on type
            $income_account_map = [
                'interest' => '4000',
                'fees' => '4030',
                'penalties' => '4020',
                'dividend_income' => '4100',
                'other' => '4200'
            ];
            $income_account = $income_account_map[$income_type] ?? '4200';
            $cash_account = getCashAccountCode($payment_method);

            // Create journal entry
            $lines = [
                [
                    'account' => $cash_account,
                    'debit' => $amount,
                    'credit' => 0,
                    'description' => "Income received: $description"
                ],
                [
                    'account' => $income_account,
                    'debit' => 0,
                    'credit' => $amount,
                    'description' => "Income: $income_type"
                ]
            ];

            createJournalEntry(
                $conn,
                $income_date,
                'income',
                $income_id,
                "Income - $income_type: $description",
                $lines
            );

            $conn->commit();
            $_SESSION['success'] = 'Income recorded successfully';
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = 'Failed to record income: ' . $e->getMessage();
        }

        $conn->close();
        header('Location: income.php');
        exit();
    }
}

// Get income with filters
$month = $_GET['month'] ?? date('m');
$year = $_GET['year'] ?? date('Y');

$income_sql = "SELECT i.*, ec.category_name,
               u.full_name as created_by_name
               FROM income i
               LEFT JOIN expense_categories ec ON i.category_id = ec.id
               LEFT JOIN users u ON i.created_by = u.id
               WHERE YEAR(i.income_date) = ? AND MONTH(i.income_date) = ?
               ORDER BY i.income_date DESC";

$income = executeQuery($income_sql, "ss", [$year, $month]);

// Get summary
$summary_sql = "SELECT 
                COUNT(*) as count,
                SUM(amount) as total,
                AVG(amount) as average,
                COUNT(DISTINCT income_type) as types
                FROM income
                WHERE YEAR(income_date) = ? AND MONTH(income_date) = ?";
$summary = executeQuery($summary_sql, "ss", [$year, $month])->fetch_assoc();

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Income/Revenue Management</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Accounting</a></li>
                <li class="breadcrumb-item active">Income</li>
            </ul>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#incomeModal">
                <i class="fas fa-plus-circle me-2"></i>Record Income
            </button>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="stats-card success">
            <div class="stats-icon">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo $summary['count'] ?? 0; ?></h3>
                <p>Transactions</p>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card success">
            <div class="stats-icon">
                <i class="fas fa-money-bill-wave"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($summary['total'] ?? 0); ?></h3>
                <p>Total Income</p>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card success">
            <div class="stats-icon">
                <i class="fas fa-calculator"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($summary['average'] ?? 0); ?></h3>
                <p>Average</p>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card success">
            <div class="stats-icon">
                <i class="fas fa-tags"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo $summary['types'] ?? 0; ?></h3>
                <p>Income Types</p>
            </div>
        </div>
    </div>
</div>

<!-- Income Table -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title">Income Records</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered datatable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Reference</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th>Received From</th>
                        <th>Category</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $income->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo formatDate($row['income_date']); ?></td>
                            <td><?php echo $row['reference_no']; ?></td>
                            <td><?php echo ucfirst($row['income_type']); ?></td>
                            <td><?php echo $row['description']; ?></td>
                            <td><?php echo $row['received_from'] ?: 'N/A'; ?></td>
                            <td><?php echo $row['category_name'] ?? 'Uncategorized'; ?></td>
                            <td class="text-success fw-bold"><?php echo formatCurrency($row['amount']); ?></td>
                            <td><?php echo ucfirst($row['payment_method']); ?></td>
                            <td>
                                <a href="view-journal.php?type=income&id=<?php echo $row['id']; ?>" class="btn btn-sm btn-info">
                                    <i class="fas fa-book"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Record Income Modal -->
<div class="modal fade" id="incomeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">Record Income</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="record_income">

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label>Income Date</label>
                            <input type="date" name="income_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Income Type</label>
                            <select name="income_type" class="form-control" required>
                                <option value="">Select Type</option>
                                <option value="interest">Interest Income</option>
                                <option value="fees">Administrative Fees</option>
                                <option value="penalties">Penalty Fees</option>
                                <option value="dividend_income">Dividend Income</option>
                                <option value="other">Other Income</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label>Amount (KES)</label>
                            <input type="number" name="amount" class="form-control" min="1" step="100" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Payment Method</label>
                            <select name="payment_method" class="form-control" required>
                                <option value="cash">Cash</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="mpesa">M-Pesa</option>
                                <option value="cheque">Cheque</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label>Reference Number</label>
                            <input type="text" name="reference_no" class="form-control" value="INC<?php echo time(); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Received From</label>
                            <input type="text" name="received_from" class="form-control" placeholder="Payer name">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label>Description</label>
                        <input type="text" name="description" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label>Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Record Income</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
<?php
require_once '../../config/config.php';
requireRole('admin'); // Only admin and accountants can record expenses

$page_title = 'Expense Management';

// Handle expense recording
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'record_expense') {
        $expense_date = $_POST['expense_date'] ?? date('Y-m-d');
        $expense_type = $_POST['expense_type'];
        $amount = $_POST['amount'];
        $payment_method = $_POST['payment_method'];
        $reference_no = $_POST['reference_no'] ?? 'EXP' . time();
        $description = $_POST['description'];
        $paid_to = $_POST['paid_to'];
        $category_id = $_POST['category_id'];
        $notes = $_POST['notes'] ?? '';

        $conn = getConnection();
        $conn->begin_transaction();

        try {
            // Insert expense record
            $sql = "INSERT INTO expenses (expense_date, expense_type, amount, payment_method, reference_no, 
                    description, paid_to, category_id, notes, created_by, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                "ssdsssssis",
                $expense_date,
                $expense_type,
                $amount,
                $payment_method,
                $reference_no,
                $description,
                $paid_to,
                $category_id,
                $notes,
                getCurrentUserId()
            );
            $stmt->execute();
            $expense_id = $conn->insert_id;

            // Create journal entry (double-entry accounting)
            // Debit: Expense account
            // Credit: Cash/Bank account

            // Get account codes based on expense type and payment method
            $expense_account = getExpenseAccountCode($expense_type, $category_id);
            $cash_account = getCashAccountCode($payment_method);

            // Journal entry for expense
            $journal_sql = "INSERT INTO journal_entries (entry_date, reference_type, reference_id, description, 
                           created_by, created_at) VALUES (?, 'expense', ?, ?, ?, NOW())";
            $journal_stmt = $conn->prepare($journal_sql);
            $journal_desc = "Expense: $expense_type - $description";
            $journal_stmt->bind_param("sisi", $expense_date, $expense_id, $journal_desc, getCurrentUserId());
            $journal_stmt->execute();
            $journal_id = $conn->insert_id;

            // Debit entry (Expense increases with debit)
            $debit_sql = "INSERT INTO journal_details (journal_id, account_code, debit_amount, credit_amount, 
                          created_at) VALUES (?, ?, ?, 0, NOW())";
            $debit_stmt = $conn->prepare($debit_sql);
            $debit_stmt->bind_param("isd", $journal_id, $expense_account, $amount);
            $debit_stmt->execute();

            // Credit entry (Cash decreases with credit)
            $credit_sql = "INSERT INTO journal_details (journal_id, account_code, debit_amount, credit_amount, 
                           created_at) VALUES (?, ?, 0, ?, NOW())";
            $credit_stmt = $conn->prepare($credit_sql);
            $credit_stmt->bind_param("isd", $journal_id, $cash_account, $amount);
            $credit_stmt->execute();

            $conn->commit();

            logAudit('INSERT', 'expenses', $expense_id, null, $_POST);
            $_SESSION['success'] = 'Expense recorded successfully';
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = 'Failed to record expense: ' . $e->getMessage();
        }

        $conn->close();
        header('Location: expenses.php');
        exit();
    }
}

// Handle expense deletion (admin only)
if (isset($_GET['delete']) && hasRole('admin')) {
    $id = $_GET['delete'];

    $conn = getConnection();
    $conn->begin_transaction();

    try {
        // Delete journal details first
        $journal_details_sql = "DELETE jd FROM journal_details jd 
                               INNER JOIN journal_entries je ON jd.journal_id = je.id 
                               WHERE je.reference_type = 'expense' AND je.reference_id = ?";
        $stmt1 = $conn->prepare($journal_details_sql);
        $stmt1->bind_param("i", $id);
        $stmt1->execute();

        // Delete journal entries
        $journal_sql = "DELETE FROM journal_entries WHERE reference_type = 'expense' AND reference_id = ?";
        $stmt2 = $conn->prepare($journal_sql);
        $stmt2->bind_param("i", $id);
        $stmt2->execute();

        // Delete expense
        $expense_sql = "DELETE FROM expenses WHERE id = ?";
        $stmt3 = $conn->prepare($expense_sql);
        $stmt3->bind_param("i", $id);
        $stmt3->execute();

        $conn->commit();

        logAudit('DELETE', 'expenses', $id, null, null);
        $_SESSION['success'] = 'Expense deleted successfully';
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Failed to delete expense: ' . $e->getMessage();
    }

    $conn->close();
    header('Location: expenses.php');
    exit();
}

// Get expense categories
$categories_sql = "SELECT * FROM expense_categories WHERE status = 1 ORDER BY category_name";
$categories = executeQuery($categories_sql);

// Get expenses with filters
$month = $_GET['month'] ?? date('m');
$year = $_GET['year'] ?? date('Y');
$category_filter = $_GET['category'] ?? '';

$where_conditions = ["YEAR(expense_date) = ?", "MONTH(expense_date) = ?"];
$params = [$year, $month];
$types = "ss";

if (!empty($category_filter)) {
    $where_conditions[] = "category_id = ?";
    $params[] = $category_filter;
    $types .= "i";
}

$where_clause = implode(" AND ", $where_conditions);

$expenses_sql = "SELECT e.*, ec.category_name, ec.category_code,
                 u.full_name as created_by_name
                 FROM expenses e
                 LEFT JOIN expense_categories ec ON e.category_id = ec.id
                 LEFT JOIN users u ON e.created_by = u.id
                 WHERE $where_clause
                 ORDER BY e.expense_date DESC, e.created_at DESC";

$expenses = executeQuery($expenses_sql, $types, $params);

// Get expense summary
$summary_sql = "SELECT 
                COUNT(*) as total_count,
                COALESCE(SUM(amount), 0) as total_amount,
                COALESCE(AVG(amount), 0) as average_amount,
                COUNT(DISTINCT expense_type) as type_count
                FROM expenses
                WHERE $where_clause";
$summary_result = executeQuery($summary_sql, $types, $params);
$summary = $summary_result->fetch_assoc();

// Get category breakdown
$category_breakdown_sql = "SELECT 
                           ec.category_name,
                           COUNT(*) as count,
                           COALESCE(SUM(e.amount), 0) as total
                           FROM expenses e
                           JOIN expense_categories ec ON e.category_id = ec.id
                           WHERE YEAR(e.expense_date) = ? AND MONTH(e.expense_date) = ?
                           GROUP BY ec.id, ec.category_name
                           ORDER BY total DESC";
$category_breakdown = executeQuery($category_breakdown_sql, "ss", [$year, $month]);

// Get daily totals for chart
$daily_sql = "SELECT 
              DAY(expense_date) as day,
              COALESCE(SUM(amount), 0) as total
              FROM expenses
              WHERE YEAR(expense_date) = ? AND MONTH(expense_date) = ?
              GROUP BY DAY(expense_date)
              ORDER BY day ASC";
$daily_result = executeQuery($daily_sql, "ss", [$year, $month]);

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Expense Management</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Accounting</a></li>
                <li class="breadcrumb-item active">Expenses</li>
            </ul>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#expenseModal">
                <i class="fas fa-plus-circle me-2"></i>Record Expense
            </button>
            <button class="btn btn-success" onclick="exportToExcel()">
                <i class="fas fa-file-excel me-2"></i>Export
            </button>
            <a href="journal.php" class="btn btn-info">
                <i class="fas fa-book me-2"></i>Journal Entries
            </a>
        </div>
    </div>
</div>

<!-- Month/Year Filter -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-3">
                <label for="month" class="form-label">Month</label>
                <select class="form-control" id="month" name="month">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo str_pad($m, 2, '0', STR_PAD_LEFT); ?>"
                            <?php echo $month == str_pad($m, 2, '0', STR_PAD_LEFT) ? 'selected' : ''; ?>>
                            <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label for="year" class="form-label">Year</label>
                <select class="form-control" id="year" name="year">
                    <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>>
                            <?php echo $y; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label for="category" class="form-label">Category</label>
                <select class="form-control" id="category" name="category">
                    <option value="">All Categories</option>
                    <?php
                    $categories->data_seek(0);
                    while ($cat = $categories->fetch_assoc()):
                    ?>
                        <option value="<?php echo $cat['id']; ?>" <?php echo $category_filter == $cat['id'] ? 'selected' : ''; ?>>
                            <?php echo $cat['category_name']; ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary d-block w-100">
                    <i class="fas fa-filter me-2"></i>Apply Filter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="stats-card danger">
            <div class="stats-icon">
                <i class="fas fa-receipt"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo $summary['total_count'] ?? 0; ?></h3>
                <p>Total Expenses</p>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card warning">
            <div class="stats-icon">
                <i class="fas fa-money-bill-wave"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($summary['total_amount'] ?? 0); ?></h3>
                <p>Total Amount</p>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card info">
            <div class="stats-icon">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo formatCurrency($summary['average_amount'] ?? 0); ?></h3>
                <p>Average Expense</p>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stats-card primary">
            <div class="stats-icon">
                <i class="fas fa-tags"></i>
            </div>
            <div class="stats-content">
                <h3><?php echo $summary['type_count'] ?? 0; ?></h3>
                <p>Categories Used</p>
            </div>
        </div>
    </div>
</div>

<!-- Charts Row -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Expense by Category</h5>
            </div>
            <div class="card-body">
                <canvas id="categoryChart" height="200"></canvas>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Daily Expenses - <?php echo date('F Y', strtotime("$year-$month-01")); ?></h5>
            </div>
            <div class="card-body">
                <canvas id="dailyChart" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Category Breakdown Table -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title">Category Breakdown</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Number of Expenses</th>
                        <th>Total Amount</th>
                        <th>Percentage</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $grand_total = $summary['total_amount'] ?? 0;
                    while ($cat = $category_breakdown->fetch_assoc()):
                        $percentage = $grand_total > 0 ? ($cat['total'] / $grand_total) * 100 : 0;
                    ?>
                        <tr>
                            <td><strong><?php echo $cat['category_name']; ?></strong></td>
                            <td><?php echo $cat['count']; ?></td>
                            <td><?php echo formatCurrency($cat['total']); ?></td>
                            <td>
                                <div class="progress" style="height: 20px;">
                                    <div class="progress-bar bg-info"
                                        role="progressbar"
                                        style="width: <?php echo $percentage; ?>%;"
                                        aria-valuenow="<?php echo $percentage; ?>"
                                        aria-valuemin="0"
                                        aria-valuemax="100">
                                        <?php echo number_format($percentage, 1); ?>%
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
                <tfoot>
                    <tr class="table-info">
                        <th>Total</th>
                        <th><?php echo $summary['total_count'] ?? 0; ?></th>
                        <th><?php echo formatCurrency($grand_total); ?></th>
                        <th>100%</th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- Expenses Table -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title">Expense List</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-striped datatable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Reference</th>
                        <th>Category</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th>Paid To</th>
                        <th>Amount</th>
                        <th>Payment Method</th>
                        <th>Recorded By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($expense = $expenses->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo formatDate($expense['expense_date']); ?></td>
                            <td>
                                <span class="badge bg-secondary"><?php echo $expense['reference_no']; ?></span>
                            </td>
                            <td>
                                <span class="badge bg-info"><?php echo $expense['category_name'] ?? 'Uncategorized'; ?></span>
                                <br>
                                <small><?php echo $expense['category_code'] ?? ''; ?></small>
                            </td>
                            <td><?php echo ucfirst(str_replace('_', ' ', $expense['expense_type'])); ?></td>
                            <td><?php echo $expense['description']; ?></td>
                            <td><?php echo $expense['paid_to'] ?: 'N/A'; ?></td>
                            <td class="fw-bold text-danger"><?php echo formatCurrency($expense['amount']); ?></td>
                            <td>
                                <span class="badge bg-<?php
                                                        echo $expense['payment_method'] == 'cash' ? 'success' : ($expense['payment_method'] == 'bank' ? 'primary' : ($expense['payment_method'] == 'mpesa' ? 'info' : 'secondary'));
                                                        ?>">
                                    <?php echo ucfirst($expense['payment_method']); ?>
                                </span>
                            </td>
                            <td><?php echo $expense['created_by_name'] ?: 'System'; ?></td>
                            <td>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-sm btn-outline-info" onclick="viewJournal(<?php echo $expense['id']; ?>)" title="View Journal">
                                        <i class="fas fa-book"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="printReceipt(<?php echo $expense['id']; ?>)" title="Print Receipt">
                                        <i class="fas fa-print"></i>
                                    </button>
                                    <?php if (hasRole('admin')): ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="confirmDelete(<?php echo $expense['id']; ?>)" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>

                    <?php if ($expenses->num_rows == 0): ?>
                        <tr>
                            <td colspan="10" class="text-center py-4">
                                <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No expenses found for the selected period.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr class="table-danger fw-bold">
                        <td colspan="6" class="text-end">Total:</td>
                        <td><?php echo formatCurrency($summary['total_amount'] ?? 0); ?></td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- Record Expense Modal -->
<div class="modal fade" id="expenseModal" tabindex="-1" aria-labelledby="expenseModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="expenseModalLabel">
                    <i class="fas fa-receipt me-2"></i>Record Expense
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="" class="needs-validation" novalidate>
                <div class="modal-body">
                    <input type="hidden" name="action" value="record_expense">

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="expense_date" class="form-label">Expense Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="expense_date" name="expense_date"
                                value="<?php echo date('Y-m-d'); ?>" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="expense_type" class="form-label">Expense Type <span class="text-danger">*</span></label>
                            <select class="form-control" id="expense_type" name="expense_type" required>
                                <option value="">-- Select Type --</option>
                                <option value="operational">Operational</option>
                                <option value="administrative">Administrative</option>
                                <option value="salary">Salary/Wages</option>
                                <option value="rent">Rent</option>
                                <option value="utilities">Utilities</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="travel">Travel</option>
                                <option value="training">Training</option>
                                <option value="marketing">Marketing</option>
                                <option value="legal">Legal</option>
                                <option value="consultancy">Consultancy</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="category_id" class="form-label">Category <span class="text-danger">*</span></label>
                            <select class="form-control" id="category_id" name="category_id" required>
                                <option value="">-- Select Category --</option>
                                <?php
                                $categories->data_seek(0);
                                while ($cat = $categories->fetch_assoc()):
                                ?>
                                    <option value="<?php echo $cat['id']; ?>">
                                        <?php echo $cat['category_name']; ?> (<?php echo $cat['category_code']; ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="amount" class="form-label">Amount (KES) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="amount" name="amount"
                                min="1" step="100" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="payment_method" class="form-label">Payment Method <span class="text-danger">*</span></label>
                            <select class="form-control" id="payment_method" name="payment_method" required>
                                <option value="">-- Select Method --</option>
                                <option value="cash">Cash</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="mpesa">M-Pesa</option>
                                <option value="cheque">Cheque</option>
                                <option value="credit_card">Credit Card</option>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="paid_to" class="form-label">Paid To <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="paid_to" name="paid_to"
                                placeholder="Recipient name" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="reference_no" class="form-label">Reference Number</label>
                            <input type="text" class="form-control" id="reference_no" name="reference_no"
                                value="EXP<?php echo time(); ?>">
                            <small class="text-muted">Invoice/Receipt number</small>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="description" class="form-label">Description <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="description" name="description"
                                placeholder="Brief description" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="notes" class="form-label">Additional Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="2"
                            placeholder="Any additional information"></textarea>
                    </div>

                    <!-- Journal Entry Preview -->
                    <div class="card mt-3 border-info">
                        <div class="card-header bg-info text-white">
                            <h6 class="mb-0">Journal Entry Preview (Double-Entry)</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <table class="table table-sm">
                                        <tr>
                                            <th>Account</th>
                                            <th>Debit</th>
                                            <th>Credit</th>
                                        </tr>
                                        <tr>
                                            <td id="previewDebitAccount">Expense Account</td>
                                            <td id="previewDebitAmount">0.00</td>
                                            <td>-</td>
                                        </tr>
                                        <tr>
                                            <td id="previewCreditAccount">Cash/Bank Account</td>
                                            <td>-</td>
                                            <td id="previewCreditAmount">0.00</td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <div class="alert alert-info mb-0">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <strong>Double-Entry Principle:</strong><br>
                                        Debit increases expenses, Credit decreases cash.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-save me-2"></i>Record Expense
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Update journal preview when amount changes
    document.getElementById('amount').addEventListener('input', function() {
        var amount = parseFloat(this.value) || 0;
        document.getElementById('previewDebitAmount').textContent = formatCurrency(amount);
        document.getElementById('previewCreditAmount').textContent = formatCurrency(amount);
    });

    // Update account names based on selections
    document.getElementById('expense_type').addEventListener('change', updateAccountNames);
    document.getElementById('payment_method').addEventListener('change', updateAccountNames);
    document.getElementById('category_id').addEventListener('change', updateAccountNames);

    function updateAccountNames() {
        var expenseType = document.getElementById('expense_type').value;
        var paymentMethod = document.getElementById('payment_method').value;
        var categorySelect = document.getElementById('category_id');
        var categoryName = categorySelect.options[categorySelect.selectedIndex]?.text.split(' (')[0] || 'Expense';

        if (expenseType) {
            document.getElementById('previewDebitAccount').textContent =
                categoryName + ' (' + expenseType.charAt(0).toUpperCase() + expenseType.slice(1) + ')';
        }

        if (paymentMethod) {
            document.getElementById('previewCreditAccount').textContent =
                paymentMethod.charAt(0).toUpperCase() + paymentMethod.slice(1) + ' Account';
        }
    }

    // Format currency
    function formatCurrency(amount) {
        return 'KES ' + parseFloat(amount).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    // Export functions
    function exportToExcel() {
        var month = document.getElementById('month').value;
        var year = document.getElementById('year').value;
        var category = document.getElementById('category').value;
        window.location.href = 'export-expenses.php?month=' + month + '&year=' + year + '&category=' + category;
    }

    // View journal entry
    function viewJournal(expenseId) {
        window.location.href = 'view-journal.php?reference_type=expense&reference_id=' + expenseId;
    }

    // Print receipt
    function printReceipt(expenseId) {
        window.open('print-expense.php?id=' + expenseId, '_blank');
    }

    // Confirm delete
    function confirmDelete(id) {
        Swal.fire({
            title: 'Delete Expense',
            text: 'Are you sure you want to delete this expense? This will also remove the associated journal entries.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'expenses.php?delete=' + id;
            }
        });
    }

    // Initialize charts
    document.addEventListener('DOMContentLoaded', function() {
        // Category Chart
        var categoryLabels = [];
        var categoryData = [];
        var categoryColors = [];

        <?php
        $category_breakdown->data_seek(0);
        $colors = ['#dc3545', '#fd7e14', '#ffc107', '#28a745', '#17a2b8', '#007bff', '#6610f2', '#e83e8c'];
        $i = 0;
        while ($cat = $category_breakdown->fetch_assoc()):
        ?>
            categoryLabels.push('<?php echo $cat['category_name']; ?>');
            categoryData.push(<?php echo $cat['total']; ?>);
            categoryColors.push('<?php echo $colors[$i % count($colors)]; ?>');
        <?php
            $i++;
        endwhile;
        ?>

        if (categoryLabels.length > 0) {
            var categoryCtx = document.getElementById('categoryChart').getContext('2d');
            new Chart(categoryCtx, {
                type: 'pie',
                data: {
                    labels: categoryLabels,
                    datasets: [{
                        data: categoryData,
                        backgroundColor: categoryColors,
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 12,
                                padding: 15
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    var label = context.label || '';
                                    var value = context.raw || 0;
                                    var total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    var percentage = Math.round((value / total) * 100);
                                    return label + ': ' + formatCurrency(value) + ' (' + percentage + '%)';
                                }
                            }
                        }
                    }
                }
            });
        }

        // Daily Chart
        var days = [];
        var dailyTotals = [];

        <?php
        $daily_result->data_seek(0);
        for ($d = 1; $d <= 31; $d++) {
            $found = false;
            $daily_result->data_seek(0);
            while ($day = $daily_result->fetch_assoc()) {
                if ($day['day'] == $d) {
                    echo "days.push('Day $d');\n";
                    echo "dailyTotals.push(" . ($day['total'] / 1000) . ");\n";
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                echo "days.push('Day $d');\n";
                echo "dailyTotals.push(0);\n";
            }
        }
        ?>

        var dailyCtx = document.getElementById('dailyChart').getContext('2d');
        new Chart(dailyCtx, {
            type: 'bar',
            data: {
                labels: days,
                datasets: [{
                    label: 'Amount (KES Thousands)',
                    data: dailyTotals,
                    backgroundColor: 'rgba(220, 53, 69, 0.5)',
                    borderColor: '#dc3545',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Amount (KES Thousands)'
                        }
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
</script>

<style>
    .stats-card.danger {
        background: linear-gradient(135deg, #dc3545, #c82333);
    }

    .stats-card.danger .stats-icon {
        background: rgba(255, 255, 255, 0.2);
        color: white;
    }

    .stats-card.danger .stats-content h3,
    .stats-card.danger .stats-content p {
        color: white;
    }

    .stats-card.warning {
        background: linear-gradient(135deg, #fd7e14, #e06b0d);
    }

    .stats-card.warning .stats-icon {
        background: rgba(255, 255, 255, 0.2);
        color: white;
    }

    .stats-card.warning .stats-content h3,
    .stats-card.warning .stats-content p {
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
        font-size: 11px;
        line-height: 20px;
    }

    .modal-header.bg-danger {
        color: white;
    }

    .modal-header.bg-danger .btn-close {
        filter: brightness(0) invert(1);
    }

    @media (max-width: 768px) {
        .btn-group {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .btn-group .btn {
            margin: 0;
            border-radius: 4px !important;
        }
    }
</style>

<?php include '../../includes/footer.php'; ?>
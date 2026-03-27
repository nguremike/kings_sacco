<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


require_once '../../config/config.php';
requireLogin();

$loan_id = $_GET['id'] ?? 0;

// Get loan details with related information
$loan_sql = "SELECT l.*, 
             m.id as member_id, m.member_no, m.full_name as member_name, 
             m.national_id, m.phone, m.email, m.address, m.date_joined,
             lp.product_name, lp.interest_rate as product_rate, 
             lp.min_amount, lp.max_amount,
             u.full_name as created_by_name,
             a.full_name as approved_by_name,
             d.full_name as disbursed_by_name
             FROM loans l
             JOIN members m ON l.member_id = m.id
             JOIN loan_products lp ON l.product_id = lp.id
             LEFT JOIN users u ON l.created_by = u.id
             LEFT JOIN users a ON l.approved_by = a.id
             LEFT JOIN users d ON l.disbursed_by = d.id
             WHERE l.id = ?";
$loan_result = executeQuery($loan_sql, "i", [$loan_id]);

if ($loan_result->num_rows == 0) {
    $_SESSION['error'] = 'Loan not found';
    header('Location: index.php');
    exit();
}

$loan = $loan_result->fetch_assoc();

// Get guarantors for this loan
$guarantors_sql = "SELECT lg.*, 
                   m.member_no, m.full_name as guarantor_name, 
                   m.national_id, m.phone, m.email
                   FROM loan_guarantors lg
                   JOIN members m ON lg.guarantor_member_id = m.id
                   WHERE lg.loan_id = ? AND lg.status = 'approved'
                   ORDER BY lg.created_at";
$guarantors = executeQuery($guarantors_sql, "i", [$loan_id]);

// Get repayment schedule
$schedule_sql = "SELECT * FROM amortization_schedule 
                 WHERE loan_id = ? 
                 ORDER BY installment_no ASC";
$schedule = executeQuery($schedule_sql, "i", [$loan_id]);

// Get repayment history
$repayments_sql = "SELECT lr.*, u.full_name as recorded_by_name
                   FROM loan_repayments lr
                   LEFT JOIN users u ON lr.created_by = u.id
                   WHERE lr.loan_id = ?
                   ORDER BY lr.payment_date DESC";
$repayments = executeQuery($repayments_sql, "i", [$loan_id]);

// Calculate loan statistics
$total_paid = 0;
$repayment_count = 0;

if ($repayments->num_rows > 0) {
    $repayments->data_seek(0);
    while ($r = $repayments->fetch_assoc()) {
        $total_paid += $r['amount_paid'];
        $repayment_count++;
    }
    $repayments->data_seek(0);
}

$remaining_balance = $loan['total_amount'] - $total_paid;
$progress_percentage = $loan['total_amount'] > 0 ? ($total_paid / $loan['total_amount']) * 100 : 0;

// Get next due date
$next_due_date = null;
$next_installment = null;
if ($schedule->num_rows > 0) {
    $schedule->data_seek(0);
    while ($row = $schedule->fetch_assoc()) {
        if ($row['status'] != 'paid') {
            $next_due_date = $row['due_date'];
            $next_installment = $row['total_payment'];
            break;
        }
    }
    $schedule->data_seek(0);
}

// Get SACCO information
$sacco_info = [
    'name' => APP_NAME,
    'address' => '123 SACCO Plaza, Nairobi, Kenya',
    'phone' => '+254 700 000 000',
    'email' => 'info@sacco.co.ke',
    'website' => 'www.sacco.co.ke',
    'registration_no' => 'SACCO/REG/001/2020'
];

// Format dates for print
function formatDatePrint($date)
{
    if (empty($date) || $date == '0000-00-00') {
        return '-';
    }
    return date('d M Y', strtotime($date));
}

function formatCurrencyPrint($amount)
{
    return 'KES ' . number_format($amount, 2);
}

include '../../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Details - <?php echo $loan['loan_no']; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Arial', sans-serif;
            font-size: 11px;
            line-height: 1.3;
            background: white;
            padding: 15mm;
        }

        .print-container {
            max-width: 100%;
            margin: 0 auto;
        }

        .header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #007bff;
        }

        .header h1 {
            font-size: 24px;
            color: #007bff;
            margin-bottom: 5px;
        }

        .header h2 {
            font-size: 18px;
            font-weight: normal;
            margin-bottom: 5px;
        }

        .header p {
            font-size: 11px;
            color: #666;
        }

        .loan-status {
            text-align: center;
            margin: 15px 0;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background: #f8f9fa;
        }

        .loan-status .status-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-badge.pending {
            background: #ffc107;
            color: #333;
        }

        .status-badge.approved {
            background: #17a2b8;
            color: white;
        }

        .status-badge.disbursed {
            background: #007bff;
            color: white;
        }

        .status-badge.active {
            background: #28a745;
            color: white;
        }

        .status-badge.completed {
            background: #6c757d;
            color: white;
        }

        .status-badge.defaulted {
            background: #dc3545;
            color: white;
        }

        .section {
            margin: 20px 0;
        }

        .section-title {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid #333;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin: 10px 0;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px dotted #eee;
        }

        .info-label {
            font-weight: bold;
            color: #555;
        }

        .info-value {
            text-align: right;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }

        th {
            background: #f0f0f0;
            font-weight: bold;
            padding: 8px;
            text-align: left;
            border: 1px solid #333;
        }

        td {
            padding: 6px;
            border: 1px solid #333;
        }

        .text-right {
            text-align: right;
        }

        .text-success {
            color: #28a745;
        }

        .text-danger {
            color: #dc3545;
        }

        .summary-box {
            display: flex;
            justify-content: space-between;
            margin: 15px 0;
        }

        .summary-item {
            flex: 1;
            padding: 10px;
            border: 1px solid #333;
            text-align: center;
            margin-right: 10px;
        }

        .summary-item:last-child {
            margin-right: 0;
        }

        .summary-item h4 {
            font-size: 16px;
            margin-bottom: 5px;
        }

        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 9px;
            color: #666;
            border-top: 1px solid #ccc;
            padding-top: 10px;
        }

        .signature-section {
            display: flex;
            justify-content: space-between;
            margin-top: 40px;
        }

        .signature-item {
            text-align: center;
            width: 30%;
        }

        .signature-line {
            margin-top: 30px;
            border-top: 1px solid #333;
        }

        .progress {
            height: 20px;
            background: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
            margin: 10px 0;
        }

        .progress-bar {
            height: 100%;
            background: #28a745;
            text-align: center;
            line-height: 20px;
            color: white;
            font-size: 10px;
        }

        @media print {
            body {
                padding: 0;
            }

            .no-print {
                display: none;
            }
        }
    </style>
</head>

<body>
    <div class="print-container">
        <!-- Header -->
        <div class="header">
            <h1><?php echo $sacco_info['name']; ?></h1>
            <h2>LOAN DETAILS STATEMENT</h2>
            <p>Generated on: <?php echo date('d M Y H:i:s'); ?></p>
        </div>

        <!-- Loan Status -->
        <div class="loan-status">
            <?php
            $status_class = [
                'pending' => 'pending',
                'guarantor_pending' => 'pending',
                'approved' => 'approved',
                'disbursed' => 'disbursed',
                'active' => 'active',
                'completed' => 'completed',
                'defaulted' => 'defaulted',
                'rejected' => 'defaulted'
            ][$loan['status']] ?? 'pending';
            ?>
            <span class="status-badge <?php echo $status_class; ?>">
                <?php echo strtoupper(str_replace('_', ' ', $loan['status'])); ?>
            </span>
            <span style="margin-left: 15px;"><strong>Loan No:</strong> <?php echo $loan['loan_no']; ?></span>
        </div>

        <!-- Loan Summary Cards -->
        <div class="summary-box">
            <div class="summary-item">
                <h4>Principal</h4>
                <p><?php echo formatCurrencyPrint($loan['principal_amount']); ?></p>
            </div>
            <div class="summary-item">
                <h4>Interest</h4>
                <p><?php echo formatCurrencyPrint($loan['interest_amount']); ?></p>
            </div>
            <div class="summary-item">
                <h4>Total</h4>
                <p><?php echo formatCurrencyPrint($loan['total_amount']); ?></p>
            </div>
            <div class="summary-item">
                <h4>Paid</h4>
                <p class="text-success"><?php echo formatCurrencyPrint($total_paid); ?></p>
            </div>
            <div class="summary-item">
                <h4>Balance</h4>
                <p class="text-danger"><?php echo formatCurrencyPrint($remaining_balance); ?></p>
            </div>
        </div>

        <!-- Progress Bar -->
        <div class="progress">
            <div class="progress-bar" style="width: <?php echo $progress_percentage; ?>%;">
                <?php echo number_format($progress_percentage, 1); ?>% Complete
            </div>
        </div>

        <div class="row">
            <!-- Member Information -->
            <div class="section">
                <div class="section-title">MEMBER INFORMATION</div>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Member Number:</span>
                        <span class="info-value"><?php echo $loan['member_no']; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Full Name:</span>
                        <span class="info-value"><?php echo $loan['member_name']; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">National ID:</span>
                        <span class="info-value"><?php echo $loan['national_id']; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Phone:</span>
                        <span class="info-value"><?php echo $loan['phone']; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Email:</span>
                        <span class="info-value"><?php echo $loan['email'] ?: 'N/A'; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Date Joined:</span>
                        <span class="info-value"><?php echo formatDatePrint($loan['date_joined']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Address:</span>
                        <span class="info-value"><?php echo $loan['address'] ?: 'N/A'; ?></span>
                    </div>
                </div>
            </div>

            <!-- Loan Details -->
            <div class="section">
                <div class="section-title">LOAN DETAILS</div>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Product:</span>
                        <span class="info-value"><?php echo $loan['product_name']; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Interest Rate:</span>
                        <span class="info-value"><?php echo $loan['interest_rate']; ?>% p.a.</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Duration:</span>
                        <span class="info-value"><?php echo $loan['duration_months']; ?> months</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Monthly Installment:</span>
                        <span class="info-value"><?php echo formatCurrencyPrint($loan['total_amount'] / $loan['duration_months']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Application Date:</span>
                        <span class="info-value"><?php echo formatDatePrint($loan['application_date']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Approval Date:</span>
                        <span class="info-value"><?php echo formatDatePrint($loan['approval_date']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Disbursement Date:</span>
                        <span class="info-value"><?php echo formatDatePrint($loan['disbursement_date']); ?></span>
                    </div>
                    <?php if ($next_due_date): ?>
                        <div class="info-item">
                            <span class="info-label">Next Due Date:</span>
                            <span class="info-value"><?php echo formatDatePrint($next_due_date); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Guarantors -->
            <?php if ($guarantors->num_rows > 0): ?>
                <div class="section">
                    <div class="section-title">GUARANTORS</div>
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Member No</th>
                                <th>National ID</th>
                                <th>Phone</th>
                                <th>Amount Guaranteed</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $total_guaranteed = 0;
                            while ($g = $guarantors->fetch_assoc()):
                                $total_guaranteed += $g['guaranteed_amount'];
                            ?>
                                <tr>
                                    <td><?php echo $g['guarantor_name']; ?></td>
                                    <td><?php echo $g['member_no']; ?></td>
                                    <td><?php echo $g['national_id']; ?></td>
                                    <td><?php echo $g['phone']; ?></td>
                                    <td class="text-right"><?php echo formatCurrencyPrint($g['guaranteed_amount']); ?></td>
                                </tr>
                            <?php endwhile; ?>
                            <tr style="font-weight: bold; background: #f0f0f0;">
                                <td colspan="4" class="text-right">Total Guaranteed:</td>
                                <td class="text-right"><?php echo formatCurrencyPrint($total_guaranteed); ?></td>
                            </tr>
                            <tr>
                                <td colspan="4" class="text-right">Required Coverage:</td>
                                <td class="text-right"><?php echo formatCurrencyPrint($loan['principal_amount']); ?></td>
                            </tr>
                            <tr>
                                <td colspan="4" class="text-right">Coverage Status:</td>
                                <td class="text-right">
                                    <?php if ($total_guaranteed >= $loan['principal_amount']): ?>
                                        <span class="text-success">Fully Covered</span>
                                    <?php else: ?>
                                        <span class="text-danger">Short by <?php echo formatCurrencyPrint($loan['principal_amount'] - $total_guaranteed); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- Repayment Schedule -->
            <?php if ($schedule->num_rows > 0): ?>
                <div class="section">
                    <div class="section-title">REPAYMENT SCHEDULE</div>
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Due Date</th>
                                <th>Principal</th>
                                <th>Interest</th>
                                <th>Total</th>
                                <th>Balance</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $schedule_count = 0;
                            $schedule->data_seek(0);
                            while ($row = $schedule->fetch_assoc()):
                                $schedule_count++;
                                if ($schedule_count <= 20): // Show first 20 installments
                            ?>
                                    <tr class="<?php echo $row['status'] == 'paid' ? '' : ($row['due_date'] < date('Y-m-d') && $row['status'] != 'paid' ? 'table-danger' : ''); ?>">
                                        <td class="text-right"><?php echo $row['installment_no']; ?></td>
                                        <td><?php echo formatDatePrint($row['due_date']); ?></td>
                                        <td class="text-right"><?php echo formatCurrencyPrint($row['principal']); ?></td>
                                        <td class="text-right"><?php echo formatCurrencyPrint($row['interest']); ?></td>
                                        <td class="text-right"><?php echo formatCurrencyPrint($row['total_payment']); ?></td>
                                        <td class="text-right"><?php echo formatCurrencyPrint($row['balance']); ?></td>
                                        <td class="text-center">
                                            <?php if ($row['status'] == 'paid'): ?>
                                                <span class="text-success">Paid</span>
                                            <?php elseif ($row['due_date'] < date('Y-m-d')): ?>
                                                <span class="text-danger">Overdue</span>
                                            <?php else: ?>
                                                <span class="text-warning">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                            <?php
                                endif;
                            endwhile;
                            ?>
                        </tbody>
                    </table>
                    <?php if ($schedule_count > 20): ?>
                        <p class="text-muted" style="margin-top: 10px;"><em>Showing first 20 of <?php echo $schedule_count; ?> installments. Full schedule available in the system.</em></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Repayment History -->
            <?php if ($repayments->num_rows > 0): ?>
                <div class="section">
                    <div class="section-title">PAYMENT HISTORY</div>
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Principal</th>
                                <th>Interest</th>
                                <th>Penalty</th>
                                <th>Method</th>
                                <th>Reference</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $repayments->data_seek(0);
                            while ($r = $repayments->fetch_assoc()):
                            ?>
                                <tr>
                                    <td><?php echo formatDatePrint($r['payment_date']); ?></td>
                                    <td class="text-right"><?php echo formatCurrencyPrint($r['amount_paid']); ?></td>
                                    <td class="text-right"><?php echo formatCurrencyPrint($r['principal_paid']); ?></td>
                                    <td class="text-right"><?php echo formatCurrencyPrint($r['interest_paid']); ?></td>
                                    <td class="text-right"><?php echo $r['penalty_paid'] ? formatCurrencyPrint($r['penalty_paid']) : '-'; ?></td>
                                    <td><?php echo ucfirst($r['payment_method']); ?></td>
                                    <td><?php echo $r['reference_no'] ?: '-'; ?></td>
                                </tr>
                            <?php endwhile; ?>
                            <tr style="font-weight: bold; background: #f0f0f0;">
                                <td class="text-right">TOTALS:</td>
                                <td class="text-right"><?php echo formatCurrencyPrint($total_paid); ?></td>
                                <td colspan="5"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Signature Section -->
        <div class="signature-section">
            <div class="signature-item">
                <div class="signature-line"></div>
                <p><strong>Borrower Signature</strong></p>
                <p><?php echo $loan['member_name']; ?></p>
                <p>Date: ______________</p>
            </div>
            <div class="signature-item">
                <div class="signature-line"></div>
                <p><strong>Guarantor Signature</strong></p>
                <p>Date: ______________</p>
            </div>
            <div class="signature-item">
                <div class="signature-line"></div>
                <p><strong>Loan Officer</strong></p>
                <p><?php echo $loan['approved_by_name'] ?? '_________________'; ?></p>
                <p>Date: <?php echo date('d M Y'); ?></p>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p><?php echo $sacco_info['name']; ?> | <?php echo $sacco_info['address']; ?> | Tel: <?php echo $sacco_info['phone']; ?></p>
            <p>Email: <?php echo $sacco_info['email']; ?> | Website: <?php echo $sacco_info['website']; ?></p>
            <p>This is a computer generated statement. No signature required for electronic copy.</p>
        </div>

        <!-- Print Button -->
        <div class="no-print" style="text-align: center; margin-top: 20px;">
            <button class="btn btn-primary" onclick="window.print()">
                <i class="fas fa-print"></i> Print Loan Details
            </button>
            <a href="view.php?id=<?php echo $loan_id; ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <script>
        // Auto-print when page loads? Uncomment if needed
        // window.onload = function() {
        //     window.print();
        // }
    </script>
</body>

</html>

<?php include '../../includes/footer.php'; ?>
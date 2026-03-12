<?php
require_once '../../config/config.php';
requireLogin();

$member_id = $_GET['id'] ?? 0;
$date_from = $_GET['from'] ?? date('Y-m-d', strtotime('-1 year'));
$date_to = $_GET['to'] ?? date('Y-m-d');

// Get member details
$member_sql = "SELECT m.*, 
               u.username as user_account
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

// Get share summary
$share_summary_sql = "SELECT 
                      COALESCE(SUM(shares_count), 0) as total_shares,
                      COALESCE(SUM(total_value), 0) as total_share_value,
                      COUNT(*) as share_transactions
                      FROM shares 
                      WHERE member_id = ?";
$share_summary = executeQuery($share_summary_sql, "i", [$member_id])->fetch_assoc();

// Get share contributions
$contributions_sql = "SELECT 
                      COALESCE(SUM(amount), 0) as total_contributions,
                      COUNT(*) as contribution_count
                      FROM share_contributions 
                      WHERE member_id = ?";
$contributions = executeQuery($contributions_sql, "i", [$member_id])->fetch_assoc();

// Get shares issued
$shares_issued_sql = "SELECT * FROM shares_issued 
                      WHERE member_id = ? 
                      ORDER BY issue_date DESC";
$shares_issued = executeQuery($shares_issued_sql, "i", [$member_id]);

// Get deposit summary
$deposit_summary_sql = "SELECT 
                        COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE 0 END), 0) as total_deposits,
                        COALESCE(SUM(CASE WHEN transaction_type = 'withdrawal' THEN amount ELSE 0 END), 0) as total_withdrawals,
                        COUNT(CASE WHEN transaction_type = 'deposit' THEN 1 END) as deposit_count,
                        COUNT(CASE WHEN transaction_type = 'withdrawal' THEN 1 END) as withdrawal_count,
                        COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE -amount END), 0) as current_balance
                        FROM deposits 
                        WHERE member_id = ?";
$deposit_summary = executeQuery($deposit_summary_sql, "i", [$member_id])->fetch_assoc();

// Get loan summary
$loan_summary_sql = "SELECT 
                     COUNT(*) as total_loans,
                     COALESCE(SUM(principal_amount), 0) as total_principal,
                     COALESCE(SUM(interest_amount), 0) as total_interest,
                     COALESCE(SUM(total_amount), 0) as total_amount,
                     COALESCE(SUM(CASE WHEN status IN ('disbursed', 'active') THEN 
                         (total_amount - COALESCE((SELECT SUM(amount_paid) FROM loan_repayments WHERE loan_id = loans.id), 0))
                         ELSE 0 END), 0) as outstanding_balance
                     FROM loans 
                     WHERE member_id = ?";
$loan_summary = executeQuery($loan_summary_sql, "i", [$member_id])->fetch_assoc();

// Get active loans
$active_loans_sql = "SELECT l.*, lp.product_name,
                     COALESCE((SELECT SUM(amount_paid) FROM loan_repayments WHERE loan_id = l.id), 0) as amount_paid
                     FROM loans l
                     LEFT JOIN loan_products lp ON l.product_id = lp.id
                     WHERE l.member_id = ? AND l.status IN ('disbursed', 'active')
                     ORDER BY l.disbursement_date DESC";
$active_loans = executeQuery($active_loans_sql, "i", [$member_id]);

// Get all transactions
$transactions_sql = "SELECT 
                     'deposit' as source,
                     d.deposit_date as transaction_date,
                     d.transaction_type as type,
                     d.amount,
                     d.balance as running_balance,
                     d.reference_no,
                     d.description,
                     NULL as loan_no
                     FROM deposits d
                     WHERE d.member_id = ? AND d.deposit_date BETWEEN ? AND ?
                     
                     UNION ALL
                     
                     SELECT 
                     'loan_repayment' as source,
                     lr.payment_date as transaction_date,
                     'loan_repayment' as type,
                     lr.amount_paid as amount,
                     NULL as running_balance,
                     lr.reference_no,
                     CONCAT('Loan Repayment - ', l.loan_no) as description,
                     l.loan_no
                     FROM loan_repayments lr
                     JOIN loans l ON lr.loan_id = l.id
                     WHERE l.member_id = ? AND lr.payment_date BETWEEN ? AND ?
                     
                     UNION ALL
                     
                     SELECT 
                     'share_purchase' as source,
                     s.date_purchased as transaction_date,
                     'share_purchase' as type,
                     s.total_value as amount,
                     NULL as running_balance,
                     s.reference_no,
                     CONCAT('Share Purchase - ', s.shares_count, ' shares') as description,
                     NULL
                     FROM shares s
                     WHERE s.member_id = ? AND s.date_purchased BETWEEN ? AND ?
                     
                     UNION ALL
                     
                     SELECT 
                     'share_contribution' as source,
                     sc.contribution_date as transaction_date,
                     'share_contribution' as type,
                     sc.amount,
                     NULL as running_balance,
                     sc.reference_no,
                     CONCAT('Share Contribution - ', sc.notes) as description,
                     NULL
                     FROM share_contributions sc
                     WHERE sc.member_id = ? AND sc.contribution_date BETWEEN ? AND ?
                     
                     ORDER BY transaction_date DESC";
$transactions = executeQuery(
    $transactions_sql,
    "ssssssssssss",
    [
        $member_id,
        $date_from,
        $date_to,
        $member_id,
        $date_from,
        $date_to,
        $member_id,
        $date_from,
        $date_to,
        $member_id,
        $date_from,
        $date_to
    ]
);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Statement - <?php echo $member['full_name']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            font-family: 'Arial', sans-serif;
            background: white;
            padding: 20px;
        }

        .statement-container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #007bff;
        }

        .header h1 {
            color: #007bff;
            margin-bottom: 5px;
        }

        .header h3 {
            color: #333;
            margin-bottom: 10px;
        }

        .info-table {
            width: 100%;
            margin-bottom: 30px;
            border-collapse: collapse;
        }

        .info-table td {
            padding: 8px;
            border: 1px solid #ddd;
        }

        .info-table td:first-child {
            font-weight: bold;
            background: #f8f9fa;
            width: 150px;
        }

        .summary-card {
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
            background: #f8f9fa;
        }

        .summary-card h4 {
            margin-top: 0;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #007bff;
            color: #007bff;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            padding: 5px 0;
            border-bottom: 1px dotted #ddd;
        }

        .summary-label {
            font-weight: bold;
            color: #555;
        }

        .summary-value {
            font-weight: bold;
        }

        .text-success {
            color: #28a745;
        }

        .text-danger {
            color: #dc3545;
        }

        .text-primary {
            color: #007bff;
        }

        table.transactions {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 12px;
        }

        table.transactions th {
            background: #007bff;
            color: white;
            padding: 10px;
            text-align: left;
            font-size: 12px;
        }

        table.transactions td {
            padding: 8px;
            border: 1px solid #ddd;
        }

        table.transactions tr:nth-child(even) {
            background: #f8f9fa;
        }

        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            text-align: center;
            font-size: 11px;
            color: #666;
        }

        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }

        @media print {
            .print-button {
                display: none;
            }

            body {
                padding: 0;
            }

            .statement-container {
                box-shadow: none;
                padding: 15px;
            }

            .header {
                margin-bottom: 15px;
            }

            table.transactions th {
                background: #333 !important;
                color: white !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .summary-card {
                background: #f8f9fa !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }

        .badge-success {
            background: #28a745;
            color: white;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
        }

        .badge-danger {
            background: #dc3545;
            color: white;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
        }

        .badge-info {
            background: #17a2b8;
            color: white;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
        }

        .badge-primary {
            background: #007bff;
            color: white;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
        }

        .badge-warning {
            background: #ffc107;
            color: #333;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
        }
    </style>
</head>

<body>
    <div class="print-button">
        <button class="btn btn-primary" onclick="window.print()">
            <i class="fas fa-print me-2"></i>Print Statement
        </button>
        <a href="export-statement.php?id=<?php echo $member_id; ?>&from=<?php echo $date_from; ?>&to=<?php echo $date_to; ?>&format=excel" class="btn btn-success">
            <i class="fas fa-file-excel me-2"></i>Export Excel
        </a>
        <a href="export-statement.php?id=<?php echo $member_id; ?>&from=<?php echo $date_from; ?>&to=<?php echo $date_to; ?>&format=pdf" class="btn btn-danger">
            <i class="fas fa-file-pdf me-2"></i>Export PDF
        </a>
        <a href="view.php?id=<?php echo $member_id; ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back
        </a>
    </div>

    <div class="statement-container">
        <!-- Header -->
        <div class="header">
            <h1><?php echo APP_NAME; ?></h1>
            <h3>MEMBER STATEMENT</h3>
            <p>Period: <?php echo formatDate($date_from); ?> to <?php echo formatDate($date_to); ?></p>
            <p>Generated: <?php echo date('d M Y H:i:s'); ?></p>
        </div>

        <!-- Member Information -->
        <table class="info-table">
            <tr>
                <td>Member No:</td>
                <td><strong><?php echo $member['member_no']; ?></strong></td>
                <td>Full Name:</td>
                <td><strong><?php echo $member['full_name']; ?></strong></td>
            </tr>
            <tr>
                <td>National ID:</td>
                <td><?php echo $member['national_id']; ?></td>
                <td>Phone:</td>
                <td><?php echo $member['phone']; ?></td>
            </tr>
            <tr>
                <td>Date Joined:</td>
                <td><?php echo formatDate($member['date_joined']); ?></td>
                <td>Status:</td>
                <td>
                    <span class="badge-<?php echo $member['membership_status'] == 'active' ? 'success' : 'warning'; ?>">
                        <?php echo ucfirst($member['membership_status']); ?>
                    </span>
                </td>
            </tr>
        </table>

        <!-- Summary Cards Row -->
        <div style="display: flex; gap: 20px; margin-bottom: 30px;">
            <!-- Shares Summary -->
            <div style="flex: 1;" class="summary-card">
                <h4><i class="fas fa-chart-pie"></i> Shares Summary</h4>
                <div class="summary-row">
                    <span class="summary-label">Full Shares:</span>
                    <span class="summary-value"><?php echo number_format($member['full_shares_issued'] ?? 0); ?></span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Share Value:</span>
                    <span class="summary-value text-primary">KES <?php echo number_format($share_summary['total_share_value'], 2); ?></span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Contributions:</span>
                    <span class="summary-value text-success">KES <?php echo number_format($contributions['total_contributions'], 2); ?></span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Partial Balance:</span>
                    <span class="summary-value text-info">KES <?php echo number_format($member['partial_share_balance'] ?? 0, 2); ?></span>
                </div>
                <div class="progress mt-2" style="height: 10px;">
                    <?php $progress = (($member['partial_share_balance'] ?? 0) / 10000) * 100; ?>
                    <div class="progress-bar bg-success" role="progressbar"
                        style="width: <?php echo $progress; ?>%;"
                        aria-valuenow="<?php echo $progress; ?>"
                        aria-valuemin="0"
                        aria-valuemax="100">
                    </div>
                </div>
                <small>Progress to next share: <?php echo number_format($progress, 1); ?>%</small>
            </div>

            <!-- Savings Summary -->
            <div style="flex: 1;" class="summary-card">
                <h4><i class="fas fa-piggy-bank"></i> Savings Summary</h4>
                <div class="summary-row">
                    <span class="summary-label">Total Deposits:</span>
                    <span class="summary-value text-success">KES <?php echo number_format($deposit_summary['total_deposits'], 2); ?></span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Total Withdrawals:</span>
                    <span class="summary-value text-danger">KES <?php echo number_format($deposit_summary['total_withdrawals'], 2); ?></span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Current Balance:</span>
                    <span class="summary-value text-primary" style="font-size: 18px;">KES <?php echo number_format($deposit_summary['current_balance'], 2); ?></span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Transactions:</span>
                    <span class="summary-value"><?php echo $deposit_summary['deposit_count'] + $deposit_summary['withdrawal_count']; ?></span>
                </div>
            </div>

            <!-- Loans Summary -->
            <div style="flex: 1;" class="summary-card">
                <h4><i class="fas fa-hand-holding-usd"></i> Loans Summary</h4>
                <div class="summary-row">
                    <span class="summary-label">Total Loans:</span>
                    <span class="summary-value"><?php echo $loan_summary['total_loans']; ?></span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Total Principal:</span>
                    <span class="summary-value">KES <?php echo number_format($loan_summary['total_principal'], 2); ?></span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Total Interest:</span>
                    <span class="summary-value">KES <?php echo number_format($loan_summary['total_interest'], 2); ?></span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Outstanding:</span>
                    <span class="summary-value text-danger">KES <?php echo number_format($loan_summary['outstanding_balance'], 2); ?></span>
                </div>
            </div>
        </div>

        <!-- Active Loans Section -->
        <?php if ($active_loans->num_rows > 0): ?>
            <div class="summary-card">
                <h4><i class="fas fa-credit-card"></i> Active Loans</h4>
                <table class="table table-bordered table-sm">
                    <thead>
                        <tr>
                            <th>Loan No</th>
                            <th>Product</th>
                            <th>Disbursement Date</th>
                            <th>Principal</th>
                            <th>Interest</th>
                            <th>Total</th>
                            <th>Paid</th>
                            <th>Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($loan = $active_loans->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?php echo $loan['loan_no']; ?></strong></td>
                                <td><?php echo $loan['product_name']; ?></td>
                                <td><?php echo formatDate($loan['disbursement_date']); ?></td>
                                <td class="text-end">KES <?php echo number_format($loan['principal_amount'], 2); ?></td>
                                <td class="text-end">KES <?php echo number_format($loan['interest_amount'], 2); ?></td>
                                <td class="text-end">KES <?php echo number_format($loan['total_amount'], 2); ?></td>
                                <td class="text-end text-success">KES <?php echo number_format($loan['amount_paid'], 2); ?></td>
                                <td class="text-end text-danger fw-bold">KES <?php echo number_format($loan['total_amount'] - $loan['amount_paid'], 2); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- Share Certificates -->
        <?php if ($shares_issued->num_rows > 0): ?>
            <div class="summary-card">
                <h4><i class="fas fa-certificate"></i> Share Certificates Issued</h4>
                <table class="table table-bordered table-sm">
                    <thead>
                        <tr>
                            <th>Certificate No</th>
                            <th>Share Number</th>
                            <th>Issue Date</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($cert = $shares_issued->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $cert['certificate_number']; ?></td>
                                <td><?php echo $cert['share_number']; ?></td>
                                <td><?php echo formatDate($cert['issue_date']); ?></td>
                                <td class="text-end">KES <?php echo number_format($cert['amount_paid'], 2); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- Transaction History -->
        <div class="summary-card">
            <h4><i class="fas fa-history"></i> Transaction History</h4>
            <table class="transactions">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th>Reference</th>
                        <th>Debit (KES)</th>
                        <th>Credit (KES)</th>
                        <th>Balance (KES)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $running_balance = 0;
                    $transactions_array = [];
                    while ($trans = $transactions->fetch_assoc()) {
                        $transactions_array[] = $trans;
                    }

                    // Reverse for chronological order
                    $transactions_array = array_reverse($transactions_array);

                    foreach ($transactions_array as $trans):
                        // Determine if it's debit or credit
                        $is_debit = false;
                        $is_credit = false;

                        if ($trans['source'] == 'deposit') {
                            if ($trans['type'] == 'deposit') {
                                $is_credit = true;
                            } else {
                                $is_debit = true;
                            }
                            $running_balance = $trans['running_balance'];
                        } elseif ($trans['source'] == 'loan_repayment') {
                            $is_credit = true;
                        } elseif ($trans['source'] == 'share_purchase') {
                            $is_debit = true;
                        } elseif ($trans['source'] == 'share_contribution') {
                            $is_credit = true;
                        }

                        // Get badge class
                        $badge_class = 'secondary';
                        $icon = 'circle';
                        switch ($trans['source']) {
                            case 'deposit':
                                $badge_class = $trans['type'] == 'deposit' ? 'success' : 'danger';
                                break;
                            case 'loan_repayment':
                                $badge_class = 'info';
                                break;
                            case 'share_purchase':
                                $badge_class = 'primary';
                                break;
                            case 'share_contribution':
                                $badge_class = 'warning';
                                break;
                        }
                    ?>
                        <tr>
                            <td><?php echo formatDate($trans['transaction_date']); ?></td>
                            <td>
                                <span class="badge-<?php echo $badge_class; ?>">
                                    <?php
                                    if ($trans['source'] == 'deposit') {
                                        echo ucfirst($trans['type']);
                                    } elseif ($trans['source'] == 'loan_repayment') {
                                        echo 'Loan Repayment';
                                    } elseif ($trans['source'] == 'share_purchase') {
                                        echo 'Share Purchase';
                                    } elseif ($trans['source'] == 'share_contribution') {
                                        echo 'Share Contribution';
                                    }
                                    ?>
                                </span>
                            </td>
                            <td>
                                <?php echo $trans['description']; ?>
                                <?php if ($trans['loan_no']): ?>
                                    <br><small>Loan: <?php echo $trans['loan_no']; ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $trans['reference_no'] ?: '-'; ?></td>
                            <td class="text-end text-danger"><?php echo $is_debit ? number_format($trans['amount'], 2) : '-'; ?></td>
                            <td class="text-end text-success"><?php echo $is_credit ? number_format($trans['amount'], 2) : '-'; ?></td>
                            <td class="text-end text-primary fw-bold">
                                <?php echo $trans['source'] == 'deposit' ? number_format($running_balance, 2) : '-'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (empty($transactions_array)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4">
                                No transactions found for the selected period.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr style="background: #e9ecef; font-weight: bold;">
                        <td colspan="4" class="text-end">Totals:</td>
                        <td class="text-end text-danger">
                            <?php
                            $total_debits = 0;
                            foreach ($transactions_array as $trans) {
                                if (($trans['source'] == 'deposit' && $trans['type'] == 'withdrawal') || $trans['source'] == 'share_purchase') {
                                    $total_debits += $trans['amount'];
                                }
                            }
                            echo number_format($total_debits, 2);
                            ?>
                        </td>
                        <td class="text-end text-success">
                            <?php
                            $total_credits = 0;
                            foreach ($transactions_array as $trans) {
                                if (($trans['source'] == 'deposit' && $trans['type'] == 'deposit') || $trans['source'] == 'loan_repayment' || $trans['source'] == 'share_contribution') {
                                    $total_credits += $trans['amount'];
                                }
                            }
                            echo number_format($total_credits, 2);
                            ?>
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>This is a computer generated statement. No signature required.</p>
            <p>Generated by <?php echo APP_NAME; ?> on <?php echo date('d M Y H:i:s'); ?></p>
            <p>For any queries, please contact the SACCO office.</p>
        </div>
    </div>

    <script>
        window.onload = function() {
            // Auto print if print parameter is set
            <?php if (isset($_GET['print']) && $_GET['print'] == 'true'): ?>
                window.print();
            <?php endif; ?>
        };
    </script>
</body>

</html>
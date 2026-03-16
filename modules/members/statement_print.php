<?php
// modules/members/statement_print.php
require_once '../../config/config.php';
requireLogin();

$member_id = $_GET['id'] ?? 0;
$year = $_GET['year'] ?? date('Y');
$month = $_GET['month'] ?? '';

// Get member details
$member_sql = "SELECT m.*, 
               u.username as user_account
               FROM members m
               LEFT JOIN users u ON m.user_id = u.id
               WHERE m.id = ?";
$member_result = executeQuery($member_sql, "i", [$member_id]);

if ($member_result->num_rows == 0) {
    echo "Member not found";
    exit();
}

$member = $member_result->fetch_assoc();

// Get opening balance (balance brought forward from previous year)
$opening_balance_sql = "SELECT COALESCE(SUM(CASE 
                            WHEN transaction_type = 'deposit' THEN amount 
                            WHEN transaction_type = 'withdrawal' THEN -amount 
                            ELSE 0 
                        END), 0) as opening_balance
                        FROM deposits 
                        WHERE member_id = ? AND deposit_date < ?";
$year_start = $year . '-01-01';
$opening_result = executeQuery($opening_balance_sql, "is", [$member_id, $year_start]);
$opening_balance = $opening_result->fetch_assoc()['opening_balance'];

// Get share summary
$share_summary_sql = "SELECT 
                      COALESCE(SUM(shares_count), 0) as total_shares,
                      COALESCE(SUM(total_value), 0) as total_share_value,
                      COUNT(*) as share_transactions,
                      COALESCE(SUM(CASE WHEN YEAR(date_purchased) = ? THEN shares_count ELSE 0 END), 0) as shares_this_year,
                      COALESCE(SUM(CASE WHEN YEAR(date_purchased) = ? THEN total_value ELSE 0 END), 0) as share_value_this_year
                      FROM shares 
                      WHERE member_id = ?";
$share_summary_result = executeQuery($share_summary_sql, "iii", [$year, $year, $member_id]);
$share_summary = $share_summary_result->fetch_assoc();

// Get share contributions (partial payments)
$contributions_sql = "SELECT 
                      COALESCE(SUM(amount), 0) as total_contributions,
                      COUNT(*) as contribution_count,
                      COALESCE(SUM(CASE WHEN YEAR(contribution_date) = ? THEN amount ELSE 0 END), 0) as contributions_this_year,
                      COUNT(CASE WHEN YEAR(contribution_date) = ? THEN 1 END) as contributions_count_this_year
                      FROM share_contributions 
                      WHERE member_id = ?";
$contributions_result = executeQuery($contributions_sql, "iii", [$year, $year, $member_id]);
$contributions = $contributions_result->fetch_assoc();

// Get shares issued
$shares_issued_sql = "SELECT * FROM shares_issued 
                      WHERE member_id = ? 
                      ORDER BY issue_date DESC";
$shares_issued = executeQuery($shares_issued_sql, "i", [$member_id]);

// Get shares issued in current year
$shares_issued_year_sql = "SELECT COUNT(*) as count, COALESCE(SUM(amount_paid), 0) as total 
                           FROM shares_issued 
                           WHERE member_id = ? AND YEAR(issue_date) = ?";
$shares_issued_year_result = executeQuery($shares_issued_year_sql, "ii", [$member_id, $year]);
$shares_issued_year = $shares_issued_year_result->fetch_assoc();

// Calculate total share capital
$total_share_capital = $share_summary['total_share_value'] + $contributions['total_contributions'];

// Get deposit transactions for the year
$deposits_sql = "SELECT d.*,
                 CASE 
                     WHEN d.transaction_type = 'deposit' THEN 'Credit'
                     WHEN d.transaction_type = 'withdrawal' THEN 'Debit'
                 END as entry_type
                 FROM deposits d
                 WHERE d.member_id = ? AND YEAR(d.deposit_date) = ?";
if (!empty($month)) {
    $deposits_sql .= " AND MONTH(d.deposit_date) = ?";
    $deposits = executeQuery($deposits_sql, "iii", [$member_id, $year, $month]);
} else {
    $deposits = executeQuery($deposits_sql, "ii", [$member_id, $year]);
}

// Get loan payments for the year
$loan_payments_sql = "SELECT lr.*, l.loan_no, l.principal_amount as loan_principal
                      FROM loan_repayments lr
                      JOIN loans l ON lr.loan_id = l.id
                      WHERE l.member_id = ? AND YEAR(lr.payment_date) = ?";
if (!empty($month)) {
    $loan_payments_sql .= " AND MONTH(lr.payment_date) = ?";
    $loan_payments = executeQuery($loan_payments_sql, "iii", [$member_id, $year, $month]);
} else {
    $loan_payments = executeQuery($loan_payments_sql, "ii", [$member_id, $year]);
}

// Get loan summary
$loan_summary_sql = "SELECT 
                     COUNT(DISTINCT l.id) as loans_active,
                     COUNT(lr.id) as payment_count,
                     COALESCE(SUM(lr.amount_paid), 0) as total_payments,
                     COALESCE(SUM(lr.principal_paid), 0) as total_principal_paid,
                     COALESCE(SUM(lr.interest_paid), 0) as total_interest_paid
                     FROM loans l
                     LEFT JOIN loan_repayments lr ON l.id = lr.loan_id AND YEAR(lr.payment_date) = ?
                     WHERE l.member_id = ?";
$loan_summary_result = executeQuery($loan_summary_sql, "ii", [$year, $member_id]);
$loan_summary = $loan_summary_result->fetch_assoc();

// Get current year totals
$year_totals_sql = "SELECT 
                    COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE 0 END), 0) as total_deposits,
                    COALESCE(SUM(CASE WHEN transaction_type = 'withdrawal' THEN amount ELSE 0 END), 0) as total_withdrawals
                    FROM deposits 
                    WHERE member_id = ? AND YEAR(deposit_date) = ?";
$year_totals_result = executeQuery($year_totals_sql, "ii", [$member_id, $year]);
$year_totals = $year_totals_result->fetch_assoc();

// Calculate closing balance
$closing_balance = $opening_balance + $year_totals['total_deposits'] - $year_totals['total_withdrawals'];

// Format date function for print
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
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Statement - <?php echo $member['full_name']; ?> (<?php echo $year; ?>)</title>
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

        .header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #333;
        }

        .header h1 {
            font-size: 24px;
            margin-bottom: 5px;
        }

        .header h2 {
            font-size: 18px;
            font-weight: normal;
            margin-bottom: 5px;
        }

        .member-info {
            margin-bottom: 20px;
            padding: 10px;
            border: 1px solid #333;
            background: #f9f9f9;
        }

        .member-info table {
            width: 100%;
            border-collapse: collapse;
        }

        .member-info td {
            padding: 5px;
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

        .page-break {
            page-break-after: always;
        }

        @media print {
            body {
                padding: 0;
            }
        }
    </style>
</head>

<body>
    <div class="header">
        <h1><?php echo APP_NAME; ?></h1>
        <h2>MEMBER STATEMENT</h2>
        <p>For the Year Ended 31st December <?php echo $year; ?></p>
        <?php if (!empty($month)): ?>
            <p><strong>Month:</strong> <?php echo date('F', mktime(0, 0, 0, $month, 1)); ?></p>
        <?php endif; ?>
    </div>

    <div class="member-info">
        <table>
            <tr>
                <td><strong>Member No:</strong> <?php echo $member['member_no']; ?></td>
                <td><strong>Full Name:</strong> <?php echo $member['full_name']; ?></td>
            </tr>
            <tr>
                <td><strong>National ID:</strong> <?php echo $member['national_id']; ?></td>
                <td><strong>Phone:</strong> <?php echo $member['phone']; ?></td>
            </tr>
            <tr>
                <td><strong>Date Joined:</strong> <?php echo formatDatePrint($member['date_joined']); ?></td>
                <td><strong>Generated On:</strong> <?php echo date('d M Y H:i:s'); ?></td>
            </tr>
        </table>
    </div>

    <!-- Balance Brought Forward -->
    <div class="section">
        <div class="section-title">BALANCE BROUGHT FORWARD</div>
        <table>
            <tr>
                <td><strong>Opening Balance as at 01/01/<?php echo $year; ?>:</strong></td>
                <td class="text-right"><strong><?php echo formatCurrencyPrint($opening_balance); ?></strong></td>
            </tr>
        </table>
    </div>

    <!-- Share Capital Summary -->
    <div class="section">
        <div class="section-title">SHARE CAPITAL SUMMARY</div>
        <table>
            <tr>
                <th>Description</th>
                <th class="text-right">Amount/Count</th>
            </tr>
            <tr>
                <td>Total Shares (All Time)</td>
                <td class="text-right"><?php echo number_format($share_summary['total_shares']); ?></td>
            </tr>
            <tr>
                <td>Total Share Value</td>
                <td class="text-right"><?php echo formatCurrencyPrint($share_summary['total_share_value']); ?></td>
            </tr>
            <tr>
                <td>Total Share Contributions</td>
                <td class="text-right"><?php echo formatCurrencyPrint($contributions['total_contributions']); ?></td>
            </tr>
            <tr>
                <td><strong>Total Share Capital</strong></td>
                <td class="text-right"><strong><?php echo formatCurrencyPrint($total_share_capital); ?></strong></td>
            </tr>
            <tr>
                <td>Full Shares Issued</td>
                <td class="text-right"><?php echo number_format($member['full_shares_issued'] ?? 0); ?></td>
            </tr>
            <tr>
                <td>Partial Share Balance</td>
                <td class="text-right"><?php echo formatCurrencyPrint($member['partial_share_balance'] ?? 0); ?></td>
            </tr>
            <tr>
                <td>Shares Issued in <?php echo $year; ?></td>
                <td class="text-right"><?php echo number_format($shares_issued_year['count']); ?> (<?php echo formatCurrencyPrint($shares_issued_year['total']); ?>)</td>
            </tr>
            <tr>
                <td>Contributions in <?php echo $year; ?></td>
                <td class="text-right"><?php echo formatCurrencyPrint($contributions['contributions_this_year']); ?></td>
            </tr>
        </table>

        <?php if ($shares_issued->num_rows < 0): ?>
            <div style="margin-top: 15px;">
                <strong>Share Certificates Issued</strong>
                <table>
                    <thead>
                        <tr>
                            <th>Certificate No</th>
                            <th>Share Number</th>
                            <th>Issue Date</th>
                            <th class="text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $shares_issued->data_seek(0);
                        while ($cert = $shares_issued->fetch_assoc()):
                        ?>
                            <tr>
                                <td><?php echo $cert['certificate_number']; ?></td>
                                <td><?php echo $cert['share_number']; ?></td>
                                <td><?php echo formatDatePrint($cert['issue_date']); ?></td>
                                <td class="text-right"><?php echo formatCurrencyPrint($cert['amount_paid']); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Savings Account Transactions -->
    <div class="section">
        <div class="section-title">SAVINGS ACCOUNT - <?php echo $year; ?></div>

        <div class="summary-box">
            <div class="summary-item">
                <h4>Opening Balance</h4>
                <p><?php echo formatCurrencyPrint($opening_balance); ?></p>
            </div>
            <div class="summary-item">
                <h4>Total Deposits</h4>
                <p class="text-success">+ <?php echo formatCurrencyPrint($year_totals['total_deposits']); ?></p>
            </div>
            <div class="summary-item">
                <h4>Total Withdrawals</h4>
                <p class="text-danger">- <?php echo formatCurrencyPrint($year_totals['total_withdrawals']); ?></p>
            </div>
            <div class="summary-item">
                <h4>Closing Balance</h4>
                <p><strong><?php echo formatCurrencyPrint($closing_balance); ?></strong></p>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Description</th>
                    <th>Reference</th>
                    <th class="text-right">Debit (Withdrawals)</th>
                    <th class="text-right">Credit (Deposits)</th>
                    <th class="text-right">Balance</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $running_balance = $opening_balance;
                $deposits->data_seek(0);
                while ($trans = $deposits->fetch_assoc()):
                    if ($trans['transaction_type'] == 'deposit') {
                        $running_balance += $trans['amount'];
                        $credit = $trans['amount'];
                        $debit = 0;
                    } else {
                        $running_balance -= $trans['amount'];
                        $credit = 0;
                        $debit = $trans['amount'];
                    }
                ?>
                    <tr>
                        <td><?php echo formatDatePrint($trans['deposit_date']); ?></td>
                        <td><?php echo $trans['description'] ?: 'Savings transaction'; ?></td>
                        <td><?php echo $trans['reference_no'] ?: '-'; ?></td>
                        <td class="text-right"><?php echo $debit > 0 ? formatCurrencyPrint($debit) : '-'; ?></td>
                        <td class="text-right"><?php echo $credit > 0 ? formatCurrencyPrint($credit) : '-'; ?></td>
                        <td class="text-right"><?php echo formatCurrencyPrint($running_balance); ?></td>
                    </tr>
                <?php endwhile; ?>

                <?php if ($deposits->num_rows == 0): ?>
                    <tr>
                        <td colspan="6" class="text-center">No savings transactions for <?php echo $year; ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="font-weight: bold; background: #f0f0f0;">
                    <td colspan="3" class="text-right">TOTALS:</td>
                    <td class="text-right"><?php echo formatCurrencyPrint($year_totals['total_withdrawals']); ?></td>
                    <td class="text-right"><?php echo formatCurrencyPrint($year_totals['total_deposits']); ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Loan Payments -->
    <?php if ($loan_payments->num_rows > 0): ?>
        <div class="section">
            <div class="section-title">LOAN PAYMENTS - <?php echo $year; ?></div>

            <div class="summary-box">
                <div class="summary-item">
                    <h4>Total Payments</h4>
                    <p><?php echo formatCurrencyPrint($loan_summary['total_payments']); ?></p>
                </div>
                <div class="summary-item">
                    <h4>Principal Paid</h4>
                    <p><?php echo formatCurrencyPrint($loan_summary['total_principal_paid']); ?></p>
                </div>
                <div class="summary-item">
                    <h4>Interest Paid</h4>
                    <p><?php echo formatCurrencyPrint($loan_summary['total_interest_paid']); ?></p>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Loan No</th>
                        <th class="text-right">Principal</th>
                        <th class="text-right">Interest</th>
                        <th class="text-right">Total</th>
                        <th>Reference</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $loan_payments->data_seek(0);
                    while ($payment = $loan_payments->fetch_assoc()):
                    ?>
                        <tr>
                            <td><?php echo formatDatePrint($payment['payment_date']); ?></td>
                            <td><?php echo $payment['loan_no']; ?></td>
                            <td class="text-right"><?php echo formatCurrencyPrint($payment['principal_paid']); ?></td>
                            <td class="text-right"><?php echo formatCurrencyPrint($payment['interest_paid']); ?></td>
                            <td class="text-right"><?php echo formatCurrencyPrint($payment['amount_paid']); ?></td>
                            <td><?php echo $payment['reference_no'] ?: '-'; ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- Year End Summary -->
    <div class="section">
        <div class="section-title">YEAR END SUMMARY - 31ST DECEMBER <?php echo $year; ?></div>

        <table>
            <tr>
                <td colspan="2"><strong>SAVINGS ACCOUNT</strong></td>
            </tr>
            <tr>
                <td>Balance Brought Forward (01/01/<?php echo $year; ?>)</td>
                <td class="text-right"><?php echo formatCurrencyPrint($opening_balance); ?></td>
            </tr>
            <tr>
                <td>Total Deposits</td>
                <td class="text-right text-success">+ <?php echo formatCurrencyPrint($year_totals['total_deposits']); ?></td>
            </tr>
            <tr>
                <td>Total Withdrawals</td>
                <td class="text-right text-danger">- <?php echo formatCurrencyPrint($year_totals['total_withdrawals']); ?></td>
            </tr>
            <tr style="font-weight: bold;">
                <td>Closing Balance (31/12/<?php echo $year; ?>)</td>
                <td class="text-right"><?php echo formatCurrencyPrint($closing_balance); ?></td>
            </tr>

            <tr>
                <td colspan="2"><strong>SHARE CAPITAL</strong></td>
            </tr>
            <tr>
                <td>Total Share Value</td>
                <td class="text-right"><?php echo formatCurrencyPrint($share_summary['total_share_value']); ?></td>
            </tr>
            <tr>
                <td>Total Share Contributions</td>
                <td class="text-right"><?php echo formatCurrencyPrint($contributions['total_contributions']); ?></td>
            </tr>
            <tr style="font-weight: bold;">
                <td>Total Share Capital</td>
                <td class="text-right"><?php echo formatCurrencyPrint($total_share_capital); ?></td>
            </tr>

            <?php if ($loan_payments->num_rows > 0): ?>
                <tr>
                    <td colspan="2"><strong>LOAN ACTIVITY</strong></td>
                </tr>
                <tr>
                    <td>Total Loan Payments</td>
                    <td class="text-right"><?php echo formatCurrencyPrint($loan_summary['total_payments']); ?></td>
                </tr>
                <tr>
                    <td>- Principal Paid</td>
                    <td class="text-right"><?php echo formatCurrencyPrint($loan_summary['total_principal_paid']); ?></td>
                </tr>
                <tr>
                    <td>- Interest Paid</td>
                    <td class="text-right"><?php echo formatCurrencyPrint($loan_summary['total_interest_paid']); ?></td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="footer">
        <p>This is a computer generated statement. No signature required.</p>
        <p><?php echo APP_NAME; ?> | Generated on: <?php echo date('d M Y H:i:s'); ?></p>
    </div>

    <script>
        window.onload = function() {
            window.print();
        }
    </script>
</body>

</html>
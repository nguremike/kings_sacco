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

// Get ALL loans for the member (for lifetime loan summary)
$all_loans_sql = "SELECT l.*, lp.product_name,
                  (SELECT COALESCE(SUM(amount_paid), 0) FROM loan_repayments WHERE loan_id = l.id) as amount_paid,
                  (l.total_amount - (SELECT COALESCE(SUM(amount_paid), 0) FROM loan_repayments WHERE loan_id = l.id)) as remaining_balance
                  FROM loans l
                  LEFT JOIN loan_products lp ON l.product_id = lp.id
                  WHERE l.member_id = ?
                  ORDER BY l.application_date DESC";
$all_loans = executeQuery($all_loans_sql, "i", [$member_id]);

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
                     COUNT(*) as total_loans,
                     COALESCE(SUM(principal_amount), 0) as total_principal,
                     COALESCE(SUM(interest_amount), 0) as total_interest,
                     COALESCE(SUM(total_amount), 0) as total_amount,
                     SUM(CASE WHEN status IN ('active', 'disbursed') THEN 
                         (total_amount - COALESCE((SELECT SUM(amount_paid) FROM loan_repayments WHERE loan_id = loans.id), 0))
                         ELSE 0 END) as outstanding_balance
                     FROM loans 
                     WHERE member_id = ?";
$loan_summary_result = executeQuery($loan_summary_sql, "i", [$member_id]);
$loan_summary = $loan_summary_result->fetch_assoc();

// Get active loans
$active_loans_sql = "SELECT l.*, lp.product_name,
                     (SELECT COALESCE(SUM(amount_paid), 0) FROM loan_repayments WHERE loan_id = l.id) as amount_paid,
                     (l.total_amount - (SELECT COALESCE(SUM(amount_paid), 0) FROM loan_repayments WHERE loan_id = l.id)) as remaining_balance
                     FROM loans l
                     LEFT JOIN loan_products lp ON l.product_id = lp.id
                     WHERE l.member_id = ? AND l.status IN ('disbursed', 'active')
                     ORDER BY l.disbursement_date DESC";
$active_loans = executeQuery($active_loans_sql, "i", [$member_id]);

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

// Format functions
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

$page_title = 'Member Statement - ' . $member['full_name'];
include '../../includes/header.php';
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

        .loan-status {
            font-size: 10px;
        }

        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 9px;
            font-weight: bold;
        }

        .badge-success {
            background: #28a745;
            color: white;
        }

        .badge-warning {
            background: #ffc107;
            color: #333;
        }

        .badge-danger {
            background: #dc3545;
            color: white;
        }

        .badge-info {
            background: #17a2b8;
            color: white;
        }

        .badge-secondary {
            background: #6c757d;
            color: white;
        }
    </style>
</head>

<body>
    <div class="header d-flex justify-content-center align-items-center flex-wrap py-3 border-bottom">
        <div class="d-flex align-items-center gap-3">
            <img src="/kings-sacco/assets/images/logo.jpeg" alt="Logo" style="height: 80px; width: auto;">

        </div>
        <div class="text-center">

            <h2 class="h4 mb-0"><?php echo APP_NAME; ?></h2>

            <h2 class="h5 mb-0">MEMBER STATEMENT</h2>
            <p class="mb-0">For the Year Ended 31st December <?php echo $year - 1; ?></p>
            <?php if (!empty($month)): ?>
                <p class="mb-0"><strong>Month:</strong> <?php echo date('F', mktime(0, 0, 0, $month, 1)); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="member-info">
        <table>
            <tr>
                <td width="25%"><strong>Member No:</strong> <?php echo $member['member_no']; ?></td>
                <td width="25%" colspan="3"><strong>Full Name:</strong> <?php echo $member['full_name']; ?></td>
                <!-- <td width="25%"><strong>National ID:</strong> <? php // echo $member['national_id']; 
                                                                    ?></td>
                <td width="25%"><strong>Phone:</strong> <?php //echo $member['phone']; 
                                                        ?></td> -->
            </tr>
            <tr>
                <!-- <td><strong>Email:</strong> <? php // echo $member['email'] ?: 'N/A'; 
                                                    ?></td>
                <td><strong>Date Joined:</strong> <? php // echo formatDatePrint($member['date_joined']); 
                                                    ?></td>
                <td><strong>Address:</strong> <? php // echo $member['address'] ?: 'N/A'; 
                                                ?></td> -->
                <td colspan="4"><strong>Generated On:</strong> <?php echo date('d M Y H:i:s'); ?></td>
            </tr>
        </table>
    </div>

    <!-- Balance Brought Forward -->
    <div class="section">
        <div class="section-title">BALANCE BROUGHT FORWARD</div>
        <table>
            <tr>
                <td width="80%"><strong>Opening Balance as at 01/01/<?php echo $year; ?>:</strong></td>
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
                <td>Total Share Contributions</td>
                <td class="text-right"><?php echo formatCurrencyPrint($contributions['total_contributions']); ?></td>
            </tr>
            <tr>
                <td>Total Share Capital</td>
                <td class="text-right"><?php echo formatCurrencyPrint($total_share_capital); ?></td>
            </tr>
            <tr>
                <td>Full Shares Issued</td>
                <td class="text-right"><?php echo number_format($member['full_shares_issued'] ?? 0); ?></td>
            </tr>

        </table>

        <?php if ($shares_issued->num_rows > 0): ?>
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
        <div class="section-title">SAVINGS ACCOUNT - AS at 31st December 2025</div>

        <div class="summary-box">
            <!-- <div class="summary-item">
                <h4>Opening Balance</h4>
                <p><?php // echo formatCurrencyPrint($opening_balance); 
                    ?></p>
            </div>
            <div class="summary-item">
                <h4>Total Deposits</h4>
                <p class="text-success">+ <?php // echo formatCurrencyPrint($year_totals['total_deposits']); 
                                            ?></p>
            </div>
            <div class="summary-item">
                <h4>Total Withdrawals</h4>
                <p class="text-danger">- <?php //echo formatCurrencyPrint($year_totals['total_withdrawals']); 
                                            ?></p>
            </div> -->



            <table>
                <tr>
                    <td width="60%">Closing Balance 31/12/<?php echo $year - 1; ?>:</td>
                    <td class="text-right"><?php echo formatCurrencyPrint($closing_balance); ?></td>
                </tr>
            </table>

        </div>


    </div>

    <?php if ($active_loans->num_rows > 0): ?>
        <!-- LOANS SUMMARY SECTION - NEW -->



        <!-- ACTIVE LOANS DETAILS -->
        <!-- <?php //if ($active_loans->num_rows > 0): 
                ?> -->
        <div class="section">
            <div class="section-title">ACTIVE LOANS</div>
            <table>
                <thead>
                    <tr>
                        <th>Date </th>
                        <!-- <th>Loan No</th> -->
                        <th>Product</th>
                        <!-- <th>Principal</th>
                        <th>Interest</th>
                        <th>Total</th>
                        <th>Paid</th> -->
                        <th>Balance</th>


                    </tr>
                </thead>
                <tbody>
                    <?php
                    $active_loans->data_seek(0);
                    while ($loan = $active_loans->fetch_assoc()):
                        $balance = $loan['total_amount'] - $loan['amount_paid'];
                    ?>
                        <tr>
                            <td><?php echo formatDatePrint($loan['disbursement_date']); ?></td>
                            <!-- <td><?php // echo $loan['loan_no']; 
                                        ?></td> -->
                            <td><?php echo $loan['product_name']; ?></td>
                            <!-- <td class="text-right"><?php // echo formatCurrencyPrint($loan['principal_amount']); 
                                                        ?></td>
                            <td class="text-right"><?php //echo formatCurrencyPrint($loan['interest_amount']); 
                                                    ?></td>
                            <td class="text-right"><?php //echo formatCurrencyPrint($loan['total_amount']); 
                                                    ?></td>
                            <td class="text-right text-success"><?php // echo formatCurrencyPrint($loan['amount_paid']); 
                                                                ?></td> -->
                            <td class="text-right text-danger"><?php echo formatCurrencyPrint($loan['principal_amount']);
                                                                $principal_amount = formatCurrencyPrint($loan['principal_amount']);
                                                                ?></td>


                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- ALL LOANS DETAILS -->


    <!-- Loan Payments for the Year -->
    <?php if ($loan_payments->num_rows > 0): ?>
        <div class="section">
            <div class="section-title">LOAN PAYMENTS - <?php echo $year; ?></div>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Loan No</th>
                        <th class="text-right">Principal</th>
                        <th class="text-right">Interest</th>
                        <th class="text-right">Total</th>
                        <th>Method</th>
                        <th>Reference</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $total_payments_year = 0;
                    $loan_payments->data_seek(0);
                    while ($payment = $loan_payments->fetch_assoc()):
                        $total_payments_year += $payment['amount_paid'];
                    ?>
                        <tr>
                            <td><?php echo formatDatePrint($payment['payment_date']); ?></td>
                            <td><?php echo $payment['loan_no']; ?></td>
                            <td class="text-right"><?php echo formatCurrencyPrint($payment['principal_paid']); ?></td>
                            <td class="text-right"><?php echo formatCurrencyPrint($payment['interest_paid']); ?></td>
                            <td class="text-right"><?php echo formatCurrencyPrint($payment['amount_paid']); ?></td>
                            <td><?php echo ucfirst($payment['payment_method']); ?></td>
                            <td><?php echo $payment['reference_no'] ?: '-'; ?></td>
                        </tr>
                    <?php endwhile; ?>
                    <tr style="font-weight: bold; background: #f0f0f0;">
                        <td colspan="4" class="text-right">Total Payments in <?php echo $year; ?>:</td>
                        <td class="text-right"><?php echo formatCurrencyPrint($total_payments_year); ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- Year End Summary -->
    <div class="section">
        <div class="section-title">YEAR END SUMMARY - 31ST DECEMBER <?php echo $year - 1; ?></div>

        <table>
            <tr>
                <td colspan="2"><strong>SAVINGS ACCOUNT</strong></td>
            </tr>

            <tr>
                <td>Closing Balance (31/12/<?php echo $year - 1; ?>)</td>
                <td class="text-right"><?php echo formatCurrencyPrint($closing_balance); ?></td>
            </tr>

            <tr>
                <td colspan="2"><strong>SHARE CAPITAL</strong></td>
            </tr>
            <tr>
                <td>Total Share Capital</td>
                <td class="text-right"><?php echo formatCurrencyPrint($total_share_capital); ?></td>
            </tr>

            <tr>
                <td colspan="2"><strong>LOANS SUMMARY</strong></td>
            </tr>

            <tr>
                <td>Outstanding Loan Balance</td>
                <td class="text-right <?php echo $principal_amount > 0 ? 'text-danger' : 'text-success'; ?>"><?php echo $principal_amount > 0 ? $principal_amount : "0.00"; ?></td>
            </tr>

            <?php if ($loan_payments->num_rows > 0): ?>
                <tr>
                    <td colspan="2"><strong>LOAN ACTIVITY - <?php echo $year; ?></strong></td>
                </tr>
                <tr>
                    <td>Loan Payments Made in <?php echo $year; ?></td>
                    <td class="text-right"><?php echo formatCurrencyPrint($total_payments_year ?? 0); ?></td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="footer">
        <p>This is a computer generated statement.</p>
        <p><?php echo APP_NAME; ?> | Generated on: <?php echo date('d M Y H:i:s'); ?></p>
    </div>

    <script>
        window.onload = function() {
            window.print();
        }
    </script>
</body>

</html>

<?php include '../../includes/footer.php'; ?>
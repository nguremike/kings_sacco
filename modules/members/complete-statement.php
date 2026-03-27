<?php
// modules/members/complete-statement.php
require_once '../../config/config.php';
requireLogin();

$member_id = $_GET['id'] ?? 0;
$year = $_GET['year'] ?? date('Y');
$print = isset($_GET['print']) ? true : false;

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

// Get ALL share contributions (lifetime)
$share_contributions_sql = "SELECT 
                            COUNT(*) as total_count,
                            COALESCE(SUM(amount), 0) as total_amount,
                            MIN(contribution_date) as first_contribution,
                            MAX(contribution_date) as last_contribution
                            FROM share_contributions 
                            WHERE member_id = ?";
$share_contributions_result = executeQuery($share_contributions_sql, "i", [$member_id]);
$share_contributions = $share_contributions_result->fetch_assoc();

// Get shares issued (all time)
$shares_issued_sql = "SELECT * FROM shares_issued 
                      WHERE member_id = ? 
                      ORDER BY issue_date DESC";
$shares_issued = executeQuery($shares_issued_sql, "i", [$member_id]);

// Get share summary
$share_summary_sql = "SELECT 
                      COALESCE(SUM(shares_count), 0) as total_shares,
                      COALESCE(SUM(total_value), 0) as total_share_value
                      FROM shares 
                      WHERE member_id = ?";
$share_summary_result = executeQuery($share_summary_sql, "i", [$member_id]);
$share_summary = $share_summary_result->fetch_assoc();

// Get all deposits (lifetime)
$all_deposits_sql = "SELECT d.*,
                     CASE 
                         WHEN d.transaction_type = 'deposit' THEN 'Credit'
                         WHEN d.transaction_type = 'withdrawal' THEN 'Debit'
                     END as entry_type
                     FROM deposits d
                     WHERE d.member_id = ?
                     ORDER BY d.deposit_date ASC";
$all_deposits = executeQuery($all_deposits_sql, "i", [$member_id]);

// Get deposit summary
$deposit_summary_sql = "SELECT 
                        COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE 0 END), 0) as total_deposits,
                        COALESCE(SUM(CASE WHEN transaction_type = 'withdrawal' THEN amount ELSE 0 END), 0) as total_withdrawals,
                        COUNT(CASE WHEN transaction_type = 'deposit' THEN 1 END) as deposit_count,
                        COUNT(CASE WHEN transaction_type = 'withdrawal' THEN 1 END) as withdrawal_count
                        FROM deposits 
                        WHERE member_id = ?";
$deposit_summary_result = executeQuery($deposit_summary_sql, "i", [$member_id]);
$deposit_summary = $deposit_summary_result->fetch_assoc();

// Get current balance
$current_balance = $deposit_summary['total_deposits'] - $deposit_summary['total_withdrawals'];

// Get all loans
$loans_sql = "SELECT l.*, lp.product_name,
              (SELECT COALESCE(SUM(amount_paid), 0) FROM loan_repayments WHERE loan_id = l.id) as amount_paid,
              (l.total_amount - (SELECT COALESCE(SUM(amount_paid), 0) FROM loan_repayments WHERE loan_id = l.id)) as remaining_balance
              FROM loans l
              LEFT JOIN loan_products lp ON l.product_id = lp.id
              WHERE l.member_id = ?
              ORDER BY l.application_date DESC";
$loans = executeQuery($loans_sql, "i", [$member_id]);

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

// Get all loan repayments
$all_repayments_sql = "SELECT lr.*, l.loan_no, l.principal_amount as loan_principal
                       FROM loan_repayments lr
                       JOIN loans l ON lr.loan_id = l.id
                       WHERE l.member_id = ?
                       ORDER BY lr.payment_date DESC";
$all_repayments = executeQuery($all_repayments_sql, "i", [$member_id]);

// Get yearly breakdown for deposits
$yearly_deposits_sql = "SELECT 
                        YEAR(deposit_date) as year,
                        COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE 0 END), 0) as total_deposits,
                        COALESCE(SUM(CASE WHEN transaction_type = 'withdrawal' THEN amount ELSE 0 END), 0) as total_withdrawals,
                        COUNT(CASE WHEN transaction_type = 'deposit' THEN 1 END) as deposit_count,
                        COUNT(CASE WHEN transaction_type = 'withdrawal' THEN 1 END) as withdrawal_count
                        FROM deposits 
                        WHERE member_id = ?
                        GROUP BY YEAR(deposit_date)
                        ORDER BY year DESC";
$yearly_deposits = executeQuery($yearly_deposits_sql, "i", [$member_id]);

// Get yearly breakdown for shares
$yearly_shares_sql = "SELECT 
                      YEAR(contribution_date) as year,
                      COALESCE(SUM(amount), 0) as total_contributions,
                      COUNT(*) as contribution_count
                      FROM share_contributions 
                      WHERE member_id = ?
                      GROUP BY YEAR(contribution_date)
                      ORDER BY year DESC";
$yearly_shares = executeQuery($yearly_shares_sql, "i", [$member_id]);

// Get monthly breakdown for current year
$monthly_sql = "SELECT 
                MONTH(deposit_date) as month,
                SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE 0 END) as monthly_deposits,
                SUM(CASE WHEN transaction_type = 'withdrawal' THEN amount ELSE 0 END) as monthly_withdrawals
                FROM deposits 
                WHERE member_id = ? AND YEAR(deposit_date) = ?
                GROUP BY MONTH(deposit_date)
                ORDER BY month ASC";
$monthly_result = executeQuery($monthly_sql, "ii", [$member_id, $year]);

$monthly_data = [];
while ($row = $monthly_result->fetch_assoc()) {
    $monthly_data[$row['month']] = $row;
}

// Get available years
$years_sql = "SELECT DISTINCT YEAR(deposit_date) as year FROM deposits WHERE member_id = ? 
              UNION 
              SELECT DISTINCT YEAR(contribution_date) FROM share_contributions WHERE member_id = ?
              UNION
              SELECT DISTINCT YEAR(payment_date) FROM loan_repayments lr JOIN loans l ON lr.loan_id = l.id WHERE l.member_id = ?
              ORDER BY year DESC";
$years_result = executeQuery($years_sql, "iii", [$member_id, $member_id, $member_id]);

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

// If print mode, use simplified layout
if ($print) {
    $page_title = 'Complete Statement - ' . $member['full_name'];
    include '../../includes/header.php';
?>

    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Complete Statement - <?php echo $member['full_name']; ?></title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: 'Arial', sans-serif;
                font-size: 10px;
                line-height: 1.3;
                background: white;
                padding: 10mm;
            }

            .statement-container {
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
                font-size: 22px;
                color: #007bff;
                margin-bottom: 5px;
            }

            .header h2 {
                font-size: 16px;
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
                padding: 4px;
            }

            .section {
                margin: 15px 0;
                page-break-inside: avoid;
            }

            .section-title {
                font-size: 14px;
                font-weight: bold;
                margin-bottom: 8px;
                padding-bottom: 3px;
                border-bottom: 1px solid #333;
                background: #e9ecef;
                padding: 5px;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                margin: 8px 0;
                font-size: 9px;
            }

            th {
                background: #f0f0f0;
                font-weight: bold;
                padding: 5px;
                text-align: left;
                border: 1px solid #333;
            }

            td {
                padding: 4px;
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
                flex-wrap: wrap;
                justify-content: space-between;
                margin: 10px 0;
            }

            .summary-item {
                flex: 1;
                padding: 8px;
                border: 1px solid #333;
                text-align: center;
                margin-right: 5px;
                min-width: 100px;
            }

            .summary-item:last-child {
                margin-right: 0;
            }

            .summary-item h4 {
                font-size: 12px;
                margin-bottom: 3px;
            }

            .footer {
                margin-top: 20px;
                text-align: center;
                font-size: 8px;
                color: #666;
                border-top: 1px solid #ccc;
                padding-top: 8px;
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
        <div class="statement-container">
            <!-- Header -->
            <div class="header">
                <h1><?php echo APP_NAME; ?></h1>
                <h2>COMPLETE MEMBER STATEMENT</h2>
                <p>Generated on: <?php echo date('d M Y H:i:s'); ?></p>
            </div>

            <!-- Member Information -->
            <div class="member-info">
                <table>
                    <tr>
                        <td width="25%"><strong>Member No:</strong> <?php echo $member['member_no']; ?> </td>
                        <td width="25%"><strong>Full Name:</strong> <?php echo $member['full_name']; ?> </td>
                        <td width="25%"><strong>National ID:</strong> <?php echo $member['national_id']; ?> </td>
                        <td width="25%"><strong>Phone:</strong> <?php echo $member['phone']; ?> </td>
                    </tr>
                    <tr>
                        <td><strong>Email:</strong> <?php echo $member['email'] ?: 'N/A'; ?> </td>
                        <td><strong>Date Joined:</strong> <?php echo formatDatePrint($member['date_joined']); ?> </td>
                        <td><strong>Address:</strong> <?php echo $member['address'] ?: 'N/A'; ?> </td>
                        <td><strong>Status:</strong> <?php echo ucfirst($member['membership_status']); ?> </td>
                    </tr>
                </table>
            </div>

            <!-- Summary Cards -->
            <div class="summary-box">
                <div class="summary-item">
                    <h4>Savings Balance</h4>
                    <p><?php echo formatCurrencyPrint($current_balance); ?></p>
                </div>
                <div class="summary-item">
                    <h4>Share Capital</h4>
                    <p><?php echo formatCurrencyPrint($share_summary['total_share_value'] + $share_contributions['total_amount']); ?></p>
                </div>
                <div class="summary-item">
                    <h4>Total Loans</h4>
                    <p><?php echo formatCurrencyPrint($loan_summary['total_amount']); ?></p>
                </div>
                <div class="summary-item">
                    <h4>Outstanding Loans</h4>
                    <p class="text-danger"><?php echo formatCurrencyPrint($loan_summary['outstanding_balance']); ?></p>
                </div>
            </div>

            <!-- Share Capital Section -->
            <div class="section">
                <div class="section-title">SHARE CAPITAL SUMMARY</div>
                <table>
                    <tr>
                        <th>Description</th>
                        <th class="text-right">Amount/Count</th>
                    </tr>
                    <tr>
                        <td>Total Shares (Full)</td>
                        <td class="text-right"><?php echo number_format($share_summary['total_shares']); ?></td>
                    </tr>
                    <tr>
                        <td>Total Share Value</td>
                        <td class="text-right"><?php echo formatCurrencyPrint($share_summary['total_share_value']); ?></td>
                    </tr>
                    <tr>
                        <td>Total Share Contributions</td>
                        <td class="text-right"><?php echo formatCurrencyPrint($share_contributions['total_amount']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Total Share Capital</strong></td>
                        <td class="text-right"><strong><?php echo formatCurrencyPrint($share_summary['total_share_value'] + $share_contributions['total_amount']); ?></strong></td>
                    </tr>
                    <tr>
                        <td>Number of Contributions</td>
                        <td class="text-right"><?php echo number_format($share_contributions['total_count']); ?></td>
                    </tr>
                    <tr>
                        <td>First Contribution</td>
                        <td class="text-right"><?php echo $share_contributions['first_contribution'] ? formatDatePrint($share_contributions['first_contribution']) : '-'; ?></td>
                    </tr>
                    <tr>
                        <td>Last Contribution</td>
                        <td class="text-right"><?php echo $share_contributions['last_contribution'] ? formatDatePrint($share_contributions['last_contribution']) : '-'; ?></td>
                    </tr>
                    <tr>
                        <td>Full Shares Issued</td>
                        <td class="text-right"><?php echo number_format($member['full_shares_issued'] ?? 0); ?></td>
                    </tr>
                    <tr>
                        <td>Partial Share Balance</td>
                        <td class="text-right"><?php echo formatCurrencyPrint($member['partial_share_balance'] ?? 0); ?></td>
                    </tr>
                </table>
            </div>

            <!-- Share Certificates -->
            <?php if ($shares_issued->num_rows > 0): ?>
                <div class="section">
                    <div class="section-title">SHARE CERTIFICATES</div>
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

            <!-- Savings Account Section -->
            <div class="section">
                <div class="section-title">SAVINGS ACCOUNT SUMMARY</div>
                <table>
                    <tr>
                        <th>Description</th>
                        <th class="text-right">Amount</th>
                    </tr>
                    <tr>
                        <td>Total Deposits (Lifetime)</td>
                        <td class="text-right text-success">+ <?php echo formatCurrencyPrint($deposit_summary['total_deposits']); ?></td>
                    </tr>
                    <tr>
                        <td>Total Withdrawals (Lifetime)</td>
                        <td class="text-right text-danger">- <?php echo formatCurrencyPrint($deposit_summary['total_withdrawals']); ?></td>
                    </tr>
                    <tr style="font-weight: bold; background: #f0f0f0;">
                        <td>Current Balance</td>
                        <td class="text-right"><?php echo formatCurrencyPrint($current_balance); ?></td>
                    </tr>
                    <tr>
                        <td>Number of Deposits</td>
                        <td class="text-right"><?php echo number_format($deposit_summary['deposit_count']); ?></td>
                    </tr>
                    <tr>
                        <td>Number of Withdrawals</td>
                        <td class="text-right"><?php echo number_format($deposit_summary['withdrawal_count']); ?></td>
                    </tr>
                </table>
            </div>

            <!-- Deposit Transaction History -->
            <?php if ($all_deposits->num_rows > 0): ?>
                <div class="section">
                    <div class="section-title">DEPOSIT TRANSACTION HISTORY</div>
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
                            $running_balance = 0;
                            $all_deposits->data_seek(0);
                            while ($trans = $all_deposits->fetch_assoc()):
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
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- Yearly Breakdown -->
            <div class="section">
                <div class="section-title">YEARLY BREAKDOWN</div>
                <table>
                    <thead>
                        <tr>
                            <th>Year</th>
                            <th>Deposits</th>
                            <th>Withdrawals</th>
                            <th>Net Change</th>
                            <th>Share Contributions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $yearly_deposits->data_seek(0);
                        $yearly_shares_data = [];
                        while ($ys = $yearly_shares->fetch_assoc()) {
                            $yearly_shares_data[$ys['year']] = $ys;
                        }

                        while ($yd = $yearly_deposits->fetch_assoc()):
                            $net_change = $yd['total_deposits'] - $yd['total_withdrawals'];
                            $share_contrib = $yearly_shares_data[$yd['year']]['total_contributions'] ?? 0;
                        ?>
                            <tr>
                                <td><?php echo $yd['year']; ?></td>
                                <td class="text-right"><?php echo formatCurrencyPrint($yd['total_deposits']); ?></td>
                                <td class="text-right"><?php echo formatCurrencyPrint($yd['total_withdrawals']); ?></td>
                                <td class="text-right <?php echo $net_change >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo $net_change >= 0 ? '+' : ''; ?><?php echo formatCurrencyPrint($net_change); ?>
                                </td>
                                <td class="text-right"><?php echo formatCurrencyPrint($share_contrib); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- Loans Section -->
            <div class="section">
                <div class="section-title">LOANS SUMMARY</div>
                <table>
                    <tr>
                        <th>Description</th>
                        <th class="text-right">Amount</th>
                    </tr>
                    <tr>
                        <td>Total Loans Taken</td>
                        <td class="text-right"><?php echo formatCurrencyPrint($loan_summary['total_amount']); ?></td>
                    </tr>
                    <tr>
                        <td>Total Principal</td>
                        <td class="text-right"><?php echo formatCurrencyPrint($loan_summary['total_principal']); ?></td>
                    </tr>
                    <tr>
                        <td>Total Interest</td>
                        <td class="text-right"><?php echo formatCurrencyPrint($loan_summary['total_interest']); ?></td>
                    </tr>
                    <tr style="font-weight: bold;">
                        <td>Outstanding Balance</td>
                        <td class="text-right text-danger"><?php echo formatCurrencyPrint($loan_summary['outstanding_balance']); ?></td>
                    </tr>
                    <tr>
                        <td>Number of Loans</td>
                        <td class="text-right"><?php echo $loan_summary['total_loans']; ?></td>
                    </tr>
                </table>
            </div>

            <!-- Loan Details -->
            <?php if ($loans->num_rows > 0): ?>
                <div class="section">
                    <div class="section-title">LOAN DETAILS</div>
                    <?php while ($loan = $loans->fetch_assoc()): ?>
                        <table style="margin-bottom: 10px;">
                            <tr style="background: #e9ecef;">
                                <th colspan="2">Loan: <?php echo $loan['loan_no']; ?> (<?php echo $loan['product_name']; ?>)</th>
                            </tr>
                            <tr>
                                <td width="50%">Principal Amount</td>
                                <td class="text-right"><?php echo formatCurrencyPrint($loan['principal_amount']); ?></td>
                            </tr>
                            <tr>
                                <td>Interest Amount</td>
                                <td class="text-right"><?php echo formatCurrencyPrint($loan['interest_amount']); ?></td>
                            </tr>
                            <tr>
                                <td>Total Amount</td>
                                <td class="text-right"><?php echo formatCurrencyPrint($loan['total_amount']); ?></td>
                            </tr>
                            <tr>
                                <td>Amount Paid</td>
                                <td class="text-right text-success"><?php echo formatCurrencyPrint($loan['amount_paid']); ?></td>
                            </tr>
                            <tr style="font-weight: bold;">
                                <td>Remaining Balance</td>
                                <td class="text-right text-danger"><?php echo formatCurrencyPrint($loan['remaining_balance']); ?></td>
                            </tr>
                            <tr>
                                <td>Duration</td>
                                <td class="text-right"><?php echo $loan['duration_months']; ?> months</td>
                            </tr>
                            <tr>
                                <td>Interest Rate</td>
                                <td class="text-right"><?php echo $loan['interest_rate']; ?>%</td>
                            </tr>
                            <tr>
                                <td>Status</td>
                                <td class="text-right"><?php echo ucfirst(str_replace('_', ' ', $loan['status'])); ?></td>
                            </tr>
                            <tr>
                                <td>Application Date</td>
                                <td class="text-right"><?php echo formatDatePrint($loan['application_date']); ?></td>
                            </tr>
                            <tr>
                                <td>Disbursement Date</td>
                                <td class="text-right"><?php echo formatDatePrint($loan['disbursement_date']); ?></td>
                            </tr>
                        </table>
                    <?php endwhile; ?>
                </div>
            <?php endif; ?>

            <!-- Loan Repayment History -->
            <?php if ($all_repayments->num_rows > 0): ?>
                <div class="section">
                    <div class="section-title">LOAN REPAYMENT HISTORY</div>
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Loan No</th>
                                <th>Amount</th>
                                <th>Principal</th>
                                <th>Interest</th>
                                <th>Method</th>
                                <th>Reference</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $all_repayments->data_seek(0);
                            $total_repayments = 0;
                            while ($r = $all_repayments->fetch_assoc()):
                                $total_repayments += $r['amount_paid'];
                            ?>
                                <tr>
                                    <td><?php echo formatDatePrint($r['payment_date']); ?></td>
                                    <td><?php echo $r['loan_no']; ?></td>
                                    <td class="text-right"><?php echo formatCurrencyPrint($r['amount_paid']); ?></td>
                                    <td class="text-right"><?php echo formatCurrencyPrint($r['principal_paid']); ?></td>
                                    <td class="text-right"><?php echo formatCurrencyPrint($r['interest_paid']); ?></td>
                                    <td><?php echo ucfirst($r['payment_method']); ?></td>
                                    <td><?php echo $r['reference_no'] ?: '-'; ?></td>
                                </tr>
                            <?php endwhile; ?>
                            <tr style="font-weight: bold; background: #f0f0f0;">
                                <td colspan="2" class="text-right">Total Repayments:</td>
                                <td class="text-right"><?php echo formatCurrencyPrint($total_repayments); ?></td>
                                <td colspan="4"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- Footer -->
            <div class="footer">
                <p><?php echo APP_NAME; ?> | <?php echo $sacco_info['address'] ?? 'Nairobi, Kenya'; ?> | Tel: <?php echo $sacco_info['phone'] ?? '+254 700 000 000'; ?></p>
                <p>This is a computer generated complete statement. For any queries, please contact the SACCO office.</p>
            </div>

            <!-- Print Button -->
            <div class="no-print" style="text-align: center; margin-top: 20px;">
                <button class="btn btn-primary" onclick="window.print()">
                    <i class="fas fa-print"></i> Print Statement
                </button>
                <a href="view.php?id=<?php echo $member_id; ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <script>
            // Auto-print when page loads
            <?php if ($print): ?>
                window.onload = function() {
                    window.print();
                }
            <?php endif; ?>
        </script>
    </body>

    </html>

<?php
} else {
    // View mode - include normal header
    $page_title = 'Complete Member Statement - ' . $member['full_name'];
    include '../../includes/header.php';
?>

    <!-- Regular view mode with navigation -->
    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h3 class="page-title">Complete Member Statement</h3>
                <ul class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                    <li class="breadcrumb-item"><a href="index.php">Members</a></li>
                    <li class="breadcrumb-item"><a href="view.php?id=<?php echo $member_id; ?>"><?php echo $member['full_name']; ?></a></li>
                    <li class="breadcrumb-item active">Complete Statement</li>
                </ul>
            </div>
            <div class="col-auto">
                <button class="btn btn-success" onclick="window.open('complete-statement.php?id=<?php echo $member_id; ?>&print=1', '_blank')">
                    <i class="fas fa-print me-2"></i>Print Statement
                </button>
                <a href="view.php?id=<?php echo $member_id; ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Profile
                </a>
            </div>
        </div>
    </div>

    <!-- Year Selection -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <input type="hidden" name="id" value="<?php echo $member_id; ?>">
                <div class="col-md-4">
                    <label for="year" class="form-label">Year (for chart)</label>
                    <select class="form-control" id="year" name="year" onchange="this.form.submit()">
                        <?php
                        $years_result->data_seek(0);
                        while ($y = $years_result->fetch_assoc()):
                        ?>
                            <option value="<?php echo $y['year']; ?>" <?php echo $y['year'] == $year ? 'selected' : ''; ?>>
                                <?php echo $y['year']; ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </form>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stats-card success">
                <div class="stats-icon">
                    <i class="fas fa-piggy-bank"></i>
                </div>
                <div class="stats-content">
                    <h3><?php echo formatCurrency($current_balance); ?></h3>
                    <p>Savings Balance</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card primary">
                <div class="stats-icon">
                    <i class="fas fa-chart-pie"></i>
                </div>
                <div class="stats-content">
                    <h3><?php echo formatCurrency($share_summary['total_share_value'] + $share_contributions['total_amount']); ?></h3>
                    <p>Share Capital</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card info">
                <div class="stats-icon">
                    <i class="fas fa-hand-holding-usd"></i>
                </div>
                <div class="stats-content">
                    <h3><?php echo $loan_summary['total_loans']; ?></h3>
                    <p>Total Loans</p>
                    <small><?php echo formatCurrency($loan_summary['total_amount']); ?></small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card warning">
                <div class="stats-icon">
                    <i class="fas fa-credit-card"></i>
                </div>
                <div class="stats-content">
                    <h3><?php echo formatCurrency($loan_summary['outstanding_balance']); ?></h3>
                    <p>Outstanding Loans</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Monthly Chart -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title">Monthly Activity - <?php echo $year; ?></h5>
        </div>
        <div class="card-body">
            <canvas id="monthlyChart" height="100"></canvas>
        </div>
    </div>

    <!-- Summary Tables (Collapsible) -->
    <div class="row">
        <div class="col-md-6">
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0"><i class="fas fa-chart-pie"></i> Share Capital Summary</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <th>Total Shares:</th>
                            <td class="text-end"><?php echo number_format($share_summary['total_shares']); ?></td>
                        </tr>
                        <tr>
                            <th>Total Share Value:</th>
                            <td class="text-end"><?php echo formatCurrency($share_summary['total_share_value']); ?></td>
                        </tr>
                        <tr>
                            <th>Total Contributions:</th>
                            <td class="text-end"><?php echo formatCurrency($share_contributions['total_amount']); ?></td>
                        </tr>
                        <tr class="table-info">
                            <th>Total Share Capital:</th>
                            <td class="text-end"><strong><?php echo formatCurrency($share_summary['total_share_value'] + $share_contributions['total_amount']); ?></strong></td>
                        </tr>
                        <tr>
                            <th>Full Shares Issued:</th>
                            <td class="text-end"><?php echo number_format($member['full_shares_issued'] ?? 0); ?></td>
                        </tr>
                        <tr>
                            <th>Partial Balance:</th>
                            <td class="text-end"><?php echo formatCurrency($member['partial_share_balance'] ?? 0); ?> </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="card-title mb-0"><i class="fas fa-piggy-bank"></i> Savings Summary</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <th>Total Deposits:</th>
                            <td class="text-end text-success"><?php echo formatCurrency($deposit_summary['total_deposits']); ?></td>
                        </tr>
                        <tr>
                            <th>Total Withdrawals:</th>
                            <td class="text-end text-danger"><?php echo formatCurrency($deposit_summary['total_withdrawals']); ?></td>
                        </tr>
                        <tr class="table-info">
                            <th>Current Balance:</th>
                            <td class="text-end"><strong><?php echo formatCurrency($current_balance); ?></strong></td>
                        </tr>
                        <tr>
                            <th>Number of Deposits:</th>
                            <td class="text-end"><?php echo number_format($deposit_summary['deposit_count']); ?></td>
                        </tr>
                        <tr>
                            <th>Number of Withdrawals:</th>
                            <td class="text-end"><?php echo number_format($deposit_summary['withdrawal_count']); ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="card mb-4">
                <div class="card-header bg-warning">
                    <h5 class="card-title mb-0"><i class="fas fa-hand-holding-usd"></i> Loans Summary</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <th>Total Loans Taken:</th>
                            <td class="text-end"><?php echo formatCurrency($loan_summary['total_amount']); ?></td>
                        </tr>
                        <tr>
                            <th>Total Principal:</th>
                            <td class="text-end"><?php echo formatCurrency($loan_summary['total_principal']); ?></td>
                        </tr>
                        <tr>
                            <th>Total Interest:</th>
                            <td class="text-end"><?php echo formatCurrency($loan_summary['total_interest']); ?></td>
                        </tr>
                        <tr class="table-danger">
                            <th>Outstanding Balance:</th>
                            <td class="text-end"><strong><?php echo formatCurrency($loan_summary['outstanding_balance']); ?></strong></td>
                        </tr>
                        <tr>
                            <th>Number of Loans:</th>
                            <td class="text-end"><?php echo $loan_summary['total_loans']; ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- All Loans Table -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title">All Loans</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered datatable">
                    <thead>
                        <tr>
                            <th>Loan No</th>
                            <th>Product</th>
                            <th>Principal</th>
                            <th>Interest</th>
                            <th>Total</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th>Disbursement Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $loans->data_seek(0);
                        while ($loan = $loans->fetch_assoc()):
                            $balance = $loan['total_amount'] - $loan['amount_paid'];
                        ?>
                            <tr>
                                <td><span class="badge bg-info"><?php echo $loan['loan_no']; ?></span></td>
                                <td><?php echo $loan['product_name']; ?></td>
                                <td class="text-end"><?php echo formatCurrency($loan['principal_amount']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($loan['interest_amount']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($loan['total_amount']); ?></td>
                                <td class="text-end text-success"><?php echo formatCurrency($loan['amount_paid']); ?></td>
                                <td class="text-end <?php echo $balance > 0 ? 'text-danger' : 'text-success'; ?>"><?php echo formatCurrency($balance); ?></td>
                                <td><span class="badge bg-<?php echo $loan['status'] == 'active' ? 'success' : ($loan['status'] == 'pending' ? 'warning' : 'secondary'); ?>"><?php echo ucfirst($loan['status']); ?></span></td>
                                <td><?php echo formatDate($loan['disbursement_date']); ?></td>
                                <td><a href="../loans/view.php?id=<?php echo $loan['id']; ?>" class="btn btn-sm btn-info"><i class="fas fa-eye"></i></a></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Deposit Transaction History -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title">Deposit Transaction History</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered datatable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Reference</th>
                            <th class="text-end">Debit (Withdrawals)</th>
                            <th class="text-end">Credit (Deposits)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $all_deposits->data_seek(0);
                        while ($trans = $all_deposits->fetch_assoc()):
                        ?>
                            <tr class="<?php echo $trans['transaction_type'] == 'deposit' ? 'table-success' : 'table-danger'; ?>">
                                <td><?php echo formatDate($trans['deposit_date']); ?></td>
                                <td><?php echo $trans['description'] ?: 'Savings transaction'; ?></td>
                                <td><?php echo $trans['reference_no'] ?: '-'; ?></td>
                                <td class="text-end"><?php echo $trans['transaction_type'] == 'withdrawal' ? formatCurrency($trans['amount']) : '-'; ?></td>
                                <td class="text-end"><?php echo $trans['transaction_type'] == 'deposit' ? formatCurrency($trans['amount']) : '-'; ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // Monthly Chart
        document.addEventListener('DOMContentLoaded', function() {
            var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            var depositsData = [];
            var withdrawalsData = [];

            <?php for ($m = 1; $m <= 12; $m++): ?>
                <?php if (isset($monthly_data[$m])): ?>
                    depositsData.push(<?php echo $monthly_data[$m]['monthly_deposits'] / 1000; ?>);
                    withdrawalsData.push(<?php echo $monthly_data[$m]['monthly_withdrawals'] / 1000; ?>);
                <?php else: ?>
                    depositsData.push(0);
                    withdrawalsData.push(0);
                <?php endif; ?>
            <?php endfor; ?>

            var ctx = document.getElementById('monthlyChart').getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: months,
                    datasets: [{
                        label: 'Deposits (KES Thousands)',
                        data: depositsData,
                        backgroundColor: 'rgba(40, 167, 69, 0.5)',
                        borderColor: 'rgb(40, 167, 69)',
                        borderWidth: 1
                    }, {
                        label: 'Withdrawals (KES Thousands)',
                        data: withdrawalsData,
                        backgroundColor: 'rgba(220, 53, 69, 0.5)',
                        borderColor: 'rgb(220, 53, 69)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
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
    </script>

<?php
    include '../../includes/footer.php';
}
?>
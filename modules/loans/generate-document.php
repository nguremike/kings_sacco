<?php
// modules/loans/generate-document.php
require_once '../../config/config.php';
requireLogin();

$loan_id = $_GET['loan_id'] ?? 0;
$document_type = $_GET['type'] ?? 'schedule';

if (!$loan_id) {
    $_SESSION['error'] = 'Loan ID not provided';
    header('Location: index.php');
    exit();
}

// Get loan details
$loan_sql = "SELECT l.*, 
             m.member_no, m.full_name, m.national_id, m.phone, m.email, m.address,
             lp.product_name, lp.interest_rate as product_rate,
             u.full_name as created_by_name,
             a.full_name as approved_by_name
             FROM loans l
             JOIN members m ON l.member_id = m.id
             JOIN loan_products lp ON l.product_id = lp.id
             LEFT JOIN users u ON l.created_by = u.id
             LEFT JOIN users a ON l.approved_by = a.id
             WHERE l.id = ?";
$loan_result = executeQuery($loan_sql, "i", [$loan_id]);

if ($loan_result->num_rows == 0) {
    $_SESSION['error'] = 'Loan not found';
    header('Location: index.php');
    exit();
}

$loan = $loan_result->fetch_assoc();

// Get guarantors
$guarantors_sql = "SELECT lg.*, m.full_name, m.member_no, m.national_id, m.phone, m.email
                   FROM loan_guarantors lg
                   JOIN members m ON lg.guarantor_member_id = m.id
                   WHERE lg.loan_id = ? AND lg.status = 'approved'";
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

// Calculate totals
$total_paid = 0;
$repayments_array = [];
while ($r = $repayments->fetch_assoc()) {
    $total_paid += $r['amount_paid'];
    $repayments_array[] = $r;
}

$outstanding_balance = $loan['total_amount'] - $total_paid;
$progress_percentage = $loan['total_amount'] > 0 ? ($total_paid / $loan['total_amount']) * 100 : 0;

// Get SACCO information
$sacco_info = [
    'name' => APP_NAME,
    'address' => '123 SACCO Plaza, Nairobi, Kenya',
    'phone' => '+254 700 000 000',
    'email' => 'info@sacco.co.ke',
    'website' => 'www.sacco.co.ke',
    'logo' => '../../assets/images/logo.png',
    'registration_no' => 'SACCO/REG/001/2020',
    'chairperson' => 'John Doe',
    'secretary' => 'Jane Smith'
];

// Generate document based on type
switch ($document_type) {
    case 'application':
        generateLoanApplication($loan, $guarantors, $sacco_info);
        break;
    case 'agreement':
        generateLoanAgreement($loan, $guarantors, $sacco_info);
        break;
    case 'guarantor':
        generateGuarantorForm($loan, $guarantors, $sacco_info);
        break;
    case 'schedule':
        generateRepaymentSchedule($loan, $schedule, $repayments_array, $total_paid, $outstanding_balance, $sacco_info);
        break;
    default:
        $_SESSION['error'] = 'Invalid document type';
        header('Location: view.php?id=' . $loan_id);
        exit();
}

function generateLoanApplication($loan, $guarantors, $sacco_info)
{
    $filename = "Loan_Application_" . $loan['loan_no'] . "_" . date('Ymd') . ".pdf";

    $html = '<html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; font-size: 12px; line-height: 1.4; }
            .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
            .header h1 { color: #007bff; margin: 0; font-size: 24px; }
            .header h2 { margin: 5px 0; font-size: 18px; }
            .header p { margin: 0; color: #666; }
            .section { margin: 20px 0; }
            .section h3 { background: #007bff; color: white; padding: 8px; margin: 0 0 10px 0; font-size: 14px; }
            table { width: 100%; border-collapse: collapse; margin: 10px 0; }
            td, th { padding: 8px; border: 1px solid #ddd; text-align: left; }
            th { background: #f0f0f0; }
            .signature { margin-top: 40px; }
            .signature-line { border-top: 1px solid #333; width: 200px; margin-top: 40px; }
            .footer { margin-top: 30px; text-align: center; font-size: 10px; color: #666; border-top: 1px solid #ddd; padding-top: 10px; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>' . $sacco_info['name'] . '</h1>
            <h2>LOAN APPLICATION FORM</h2>
            <p>Application Date: ' . date('d M Y', strtotime($loan['application_date'])) . '</p>
        </div>
        
        <div class="section">
            <h3>LOAN DETAILS</h3>
            <table>
                <tr><td width="30%">Loan Number:</td><td><strong>' . $loan['loan_no'] . '</strong></td></tr>
                <tr><td>Product:</td><td>' . $loan['product_name'] . '</td></tr>
                <tr><td>Principal Amount:</td><td>KES ' . number_format($loan['principal_amount'], 2) . '</td></tr>
                <tr><td>Interest Rate:</td><td>' . $loan['interest_rate'] . '% p.a.</td></tr>
                <tr><td>Interest Amount:</td><td>KES ' . number_format($loan['interest_amount'], 2) . '</td></tr>
                <tr><td>Total Amount:</td><td>KES ' . number_format($loan['total_amount'], 2) . '</td></tr>
                <tr><td>Duration:</td><td>' . $loan['duration_months'] . ' months</td></tr>
                <tr><td>Monthly Installment:</td><td>KES ' . number_format($loan['total_amount'] / $loan['duration_months'], 2) . '</td></tr>
            </table>
        </div>
        
        <div class="section">
            <h3>MEMBER INFORMATION</h3>
            <table>
                <tr><td width="30%">Member Number:</td><td>' . $loan['member_no'] . '</td></tr>
                <tr><td>Full Name:</td><td>' . $loan['full_name'] . '</td></tr>
                <tr><td>National ID:</td><td>' . $loan['national_id'] . '</td></tr>
                <tr><td>Phone:</td><td>' . $loan['phone'] . '</td></tr>
                <tr><td>Email:</td><td>' . ($loan['email'] ?: 'N/A') . '</td></tr>
                <tr><td>Address:</td><td>' . ($loan['address'] ?: 'N/A') . '</td></tr>
            </table>
        </div>';

    if ($guarantors->num_rows > 0) {
        $html .= '<div class="section">
            <h3>GUARANTORS</h3>
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
                <tbody>';

        while ($g = $guarantors->fetch_assoc()) {
            $html .= '<tr>
                <td>' . $g['full_name'] . '</td>
                <td>' . $g['member_no'] . '</td>
                <td>' . $g['national_id'] . '</td>
                <td>' . $g['phone'] . '</td>
                <td>KES ' . number_format($g['guaranteed_amount'], 2) . '</td>
            </tr>';
        }

        $html .= '</tbody></table></div>';
    }

    $html .= '<div class="section">
            <h3>DECLARATION</h3>
            <p>I, <strong>' . $loan['full_name'] . '</strong>, hereby apply for the above loan and declare that all information provided is true and correct. I agree to be bound by the terms and conditions of the SACCO.</p>
        </div>
        
        <div class="signature">
            <table width="100%">
                <tr>
                    <td width="50%">
                        <div class="signature-line"></div>
                        <p><strong>Applicant Signature</strong><br>Date: ______________</p>
                    </td>
                    <td width="50%">
                        <div class="signature-line"></div>
                        <p><strong>Loan Officer Signature</strong><br>Date: ______________</p>
                    </td>
                </tr>
            </table>
        </div>
        
        <div class="footer">
            <p>' . $sacco_info['name'] . ' | Reg: ' . $sacco_info['registration_no'] . ' | Tel: ' . $sacco_info['phone'] . '</p>
            <p>Generated on: ' . date('d M Y H:i:s') . '</p>
        </div>
    </body>
    </html>';

    generatePDF($html, $filename);
}

function generateLoanAgreement($loan, $guarantors, $sacco_info)
{
    $filename = "Loan_Agreement_" . $loan['loan_no'] . "_" . date('Ymd') . ".pdf";

    $html = '<html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; font-size: 11px; line-height: 1.4; }
            .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #28a745; padding-bottom: 10px; }
            .header h1 { color: #28a745; margin: 0; font-size: 24px; }
            .header h2 { margin: 5px 0; font-size: 18px; }
            .agreement-title { text-align: center; font-size: 16px; font-weight: bold; margin: 20px 0; text-transform: uppercase; }
            .section { margin: 20px 0; }
            .section h3 { background: #28a745; color: white; padding: 5px; margin: 0 0 10px 0; font-size: 13px; }
            table { width: 100%; border-collapse: collapse; margin: 10px 0; }
            td, th { padding: 6px; border: 1px solid #ddd; }
            th { background: #f0f0f0; }
            .terms { margin: 15px 0; }
            .terms p { margin: 5px 0; }
            .signature { margin-top: 40px; }
            .signature-line { border-top: 1px solid #333; width: 200px; margin-top: 40px; }
            .footer { margin-top: 30px; text-align: center; font-size: 9px; color: #666; border-top: 1px solid #ddd; padding-top: 10px; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>' . $sacco_info['name'] . '</h1>
            <h2>LOAN AGREEMENT</h2>
            <p>Date: ' . date('d M Y', strtotime($loan['disbursement_date'] ?? $loan['application_date'])) . '</p>
        </div>
        
        <div class="agreement-title">
            THIS LOAN AGREEMENT is made between ' . $sacco_info['name'] . ' (hereinafter referred to as "The Lender") and ' . strtoupper($loan['full_name']) . ' (hereinafter referred to as "The Borrower")
        </div>
        
        <div class="section">
            <h3>1. PARTIES TO THE AGREEMENT</h3>
            <table>
                <tr><td width="30%"><strong>Lender:</strong></td><td>' . $sacco_info['name'] . '</td></tr>
                <tr><td><strong>Borrower:</strong></td><td>' . $loan['full_name'] . ' (ID: ' . $loan['national_id'] . ')</td></tr>
                <tr><td><strong>Borrower Number:</strong></td><td>' . $loan['member_no'] . '</td></tr>
                <tr><td><strong>Contact:</strong></td><td>Phone: ' . $loan['phone'] . ', Email: ' . ($loan['email'] ?: 'N/A') . '</td></tr>
            </table>
        </div>
        
        <div class="section">
            <h3>2. LOAN DETAILS</h3>
            <table>
                <tr><td width="40%">Loan Number:</td><td><strong>' . $loan['loan_no'] . '</strong></td></tr>
                <tr><td>Principal Amount:</td><td>KES ' . number_format($loan['principal_amount'], 2) . '</td></tr>
                <tr><td>Interest Rate:</td><td>' . $loan['interest_rate'] . '% per annum</td></tr>
                <tr><td>Interest Amount:</td><td>KES ' . number_format($loan['interest_amount'], 2) . '</td></tr>
                <tr><td>Total Repayable:</td><td>KES ' . number_format($loan['total_amount'], 2) . '</td></tr>
                <tr><td>Duration:</td><td>' . $loan['duration_months'] . ' months</td></tr>
                <tr><td>Repayment Frequency:</td><td>Monthly</td></tr>
                <tr><td>Monthly Installment:</td><td>KES ' . number_format($loan['total_amount'] / $loan['duration_months'], 2) . '</td></tr>
                <tr><td>Disbursement Date:</td><td>' . ($loan['disbursement_date'] ? date('d M Y', strtotime($loan['disbursement_date'])) : 'Not disbursed') . '</td></tr>
                <tr><td>First Payment Due:</td><td>' . date('d M Y', strtotime('+1 month', strtotime($loan['disbursement_date'] ?? $loan['application_date']))) . '</td></tr>
            </table>
        </div>';

    if ($guarantors->num_rows > 0) {
        $html .= '<div class="section">
            <h3>3. GUARANTORS</h3>
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>ID Number</th>
                        <th>Member No</th>
                        <th>Guaranteed Amount</th>
                    </tr>
                </thead>
                <tbody>';

        while ($g = $guarantors->fetch_assoc()) {
            $html .= '<tr>
                <td>' . $g['full_name'] . '</td>
                <td>' . $g['national_id'] . '</td>
                <td>' . $g['member_no'] . '</td>
                <td>KES ' . number_format($g['guaranteed_amount'], 2) . '</td>
            </tr>';
        }

        $html .= '</tbody></table>
            <p><em>The guarantors hereby guarantee repayment of the above loan and agree to be jointly and severally liable for any default.</em></p>
        </div>';
    }

    $html .= '<div class="section">
            <h3>4. TERMS AND CONDITIONS</h3>
            <div class="terms">
                <p><strong>4.1 Repayment:</strong> The borrower agrees to repay the loan in ' . $loan['duration_months'] . ' equal monthly installments of KES ' . number_format($loan['total_amount'] / $loan['duration_months'], 2) . '.</p>
                <p><strong>4.2 Default:</strong> In case of default, the borrower will be charged a penalty of KES 50 per day until full repayment.</p>
                <p><strong>4.3 Early Repayment:</strong> The borrower may repay the loan early without penalty.</p>
                <p><strong>4.4 Default Consequences:</strong> Upon default, the entire outstanding balance becomes due immediately and the guarantors shall be liable.</p>
                <p><strong>4.5 Governing Law:</strong> This agreement is governed by the laws of Kenya.</p>
            </div>
        </div>
        
        <div class="signature">
            <table width="100%">
                <tr>
                    <td width="33%">
                        <div class="signature-line"></div>
                        <p><strong>Borrower</strong><br>Name: ' . $loan['full_name'] . '<br>Date: ______________</p>
                    </td>
                    <td width="33%">
                        <div class="signature-line"></div>
                        <p><strong>Guarantor (if any)</strong><br>Date: ______________</p>
                    </td>
                    <td width="33%">
                        <div class="signature-line"></div>
                        <p><strong>For SACCO</strong><br>Name: ' . ($loan['approved_by_name'] ?? '_________________') . '<br>Date: ______________</p>
                    </td>
                </tr>
            </table>
        </div>
        
        <div class="footer">
            <p>' . $sacco_info['name'] . ' | Reg: ' . $sacco_info['registration_no'] . ' | Tel: ' . $sacco_info['phone'] . ' | Email: ' . $sacco_info['email'] . '</p>
            <p>This agreement was generated on ' . date('d M Y H:i:s') . '</p>
        </div>
    </body>
    </html>';

    generatePDF($html, $filename);
}

function generateGuarantorForm($loan, $guarantors, $sacco_info)
{
    $filename = "Guarantor_Form_" . $loan['loan_no'] . "_" . date('Ymd') . ".pdf";

    $html = '<html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; font-size: 12px; line-height: 1.4; }
            .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #ffc107; padding-bottom: 10px; }
            .header h1 { color: #ffc107; margin: 0; font-size: 24px; }
            .header h2 { margin: 5px 0; font-size: 18px; }
            .section { margin: 20px 0; border: 1px solid #ddd; padding: 15px; border-radius: 5px; }
            .section h3 { background: #ffc107; color: #333; padding: 5px; margin: -15px -15px 15px -15px; border-radius: 5px 5px 0 0; }
            table { width: 100%; border-collapse: collapse; }
            td { padding: 8px; }
            .declaration { margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 4px solid #ffc107; }
            .signature { margin-top: 40px; }
            .signature-line { border-top: 1px solid #333; width: 250px; margin-top: 40px; }
            .footer { margin-top: 30px; text-align: center; font-size: 10px; color: #666; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>' . $sacco_info['name'] . '</h1>
            <h2>GUARANTOR FORM</h2>
            <p>Loan Number: ' . $loan['loan_no'] . ' | Date: ' . date('d M Y') . '</p>
        </div>
        
        <div class="section">
            <h3>BORROWER INFORMATION</h3>
            <table>
                <tr><td width="30%"><strong>Name:</strong></td><td>' . $loan['full_name'] . '</td></tr>
                <tr><td><strong>Member No:</strong></td><td>' . $loan['member_no'] . '</td></tr>
                <tr><td><strong>National ID:</strong></td><td>' . $loan['national_id'] . '</td></tr>
                <tr><td><strong>Loan Amount:</strong></td><td>KES ' . number_format($loan['principal_amount'], 2) . '</td></tr>
            </table>
        </div>';

    if ($guarantors->num_rows > 0) {
        while ($g = $guarantors->fetch_assoc()) {
            $html .= '<div class="section">
                <h3>GUARANTOR DETAILS</h3>
                <table>
                    <tr><td width="30%"><strong>Full Name:</strong></td><td>' . $g['full_name'] . '</td></tr>
                    <tr><td><strong>Member No:</strong></td><td>' . $g['member_no'] . '</td></tr>
                    <tr><td><strong>National ID:</strong></td><td>' . $g['national_id'] . '</td></tr>
                    <tr><td><strong>Phone:</strong></td><td>' . $g['phone'] . '</td></tr>
                    <tr><td><strong>Email:</strong></td><td>' . ($g['email'] ?: 'N/A') . '</td></tr>
                    <tr><td><strong>Amount Guaranteed:</strong></td><td>KES ' . number_format($g['guaranteed_amount'], 2) . '</td></tr>
                </table>
                
                <div class="declaration">
                    <p><strong>GUARANTOR DECLARATION</strong></p>
                    <p>I, <strong>' . $g['full_name'] . '</strong>, hereby guarantee the repayment of the above loan. I understand that if the borrower defaults, I will be liable for the guaranteed amount.</p>
                </div>
                
                <div class="signature">
                    <div class="signature-line"></div>
                    <p><strong>Guarantor Signature</strong><br>Name: ' . $g['full_name'] . '<br>Date: ______________</p>
                </div>
            </div>';
        }
    } else {
        $html .= '<div class="section">
            <p><em>No guarantors have been added for this loan yet.</em></p>
        </div>';
    }

    $html .= '<div class="footer">
            <p>' . $sacco_info['name'] . ' | ' . $sacco_info['address'] . ' | Tel: ' . $sacco_info['phone'] . '</p>
            <p>Generated on: ' . date('d M Y H:i:s') . '</p>
        </div>
    </body>
    </html>';

    generatePDF($html, $filename);
}

function generateRepaymentSchedule($loan, $schedule, $repayments, $total_paid, $outstanding_balance, $sacco_info)
{
    $filename = "Repayment_Schedule_" . $loan['loan_no'] . "_" . date('Ymd') . ".pdf";

    $schedule_data = [];
    while ($row = $schedule->fetch_assoc()) {
        $schedule_data[] = $row;
    }

    $html = '<html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; font-size: 10px; line-height: 1.3; }
            .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #17a2b8; padding-bottom: 10px; }
            .header h1 { color: #17a2b8; margin: 0; font-size: 22px; }
            .header h2 { margin: 5px 0; font-size: 16px; }
            .summary { background: #f8f9fa; padding: 10px; margin: 15px 0; border-radius: 5px; }
            .summary table { width: 100%; }
            .summary td { padding: 3px; }
            table { width: 100%; border-collapse: collapse; margin: 10px 0; }
            th { background: #17a2b8; color: white; padding: 8px; text-align: left; }
            td { padding: 6px; border: 1px solid #ddd; }
            .paid { background-color: #d4edda; }
            .overdue { background-color: #f8d7da; }
            .pending { background-color: #fff3cd; }
            .footer { margin-top: 30px; text-align: center; font-size: 9px; color: #666; border-top: 1px solid #ddd; padding-top: 10px; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>' . $sacco_info['name'] . '</h1>
            <h2>LOAN REPAYMENT SCHEDULE</h2>
            <p>Loan Number: ' . $loan['loan_no'] . ' | Generated: ' . date('d M Y H:i') . '</p>
        </div>
        
        <div class="summary">
            <table>
                <tr>
                    <td><strong>Borrower:</strong> ' . $loan['full_name'] . ' (' . $loan['member_no'] . ')</td>
                    <td><strong>Principal:</strong> KES ' . number_format($loan['principal_amount'], 2) . '</td>
                </tr>
                <tr>
                    <td><strong>Product:</strong> ' . $loan['product_name'] . '</td>
                    <td><strong>Interest Rate:</strong> ' . $loan['interest_rate'] . '% p.a.</td>
                </tr>
                <tr>
                    <td><strong>Duration:</strong> ' . $loan['duration_months'] . ' months</td>
                    <td><strong>Total Repayable:</strong> KES ' . number_format($loan['total_amount'], 2) . '</td>
                </tr>
                <tr>
                    <td><strong>Total Paid:</strong> KES ' . number_format($total_paid, 2) . '</td>
                    <td><strong>Outstanding Balance:</strong> KES ' . number_format($outstanding_balance, 2) . '</td>
                </tr>
                <tr>
                    <td><strong>Progress:</strong> ' . number_format($progress_percentage, 1) . '%</td>
                    <td><strong>Status:</strong> ' . ($outstanding_balance == 0 ? 'PAID' : ($outstanding_balance < 0 ? 'OVERPAID' : 'ACTIVE')) . '</td>
                </tr>
            </table>
        </div>
        
        <h3>REPAYMENT SCHEDULE</h3>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Due Date</th>
                    <th>Principal</th>
                    <th>Interest</th>
                    <th>Total Due</th>
                    <th>Balance</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>';

    foreach ($schedule_data as $row) {
        $status_class = $row['status'] == 'paid' ? 'paid' : ($row['due_date'] < date('Y-m-d') && $row['status'] != 'paid' ? 'overdue' : 'pending');
        $html .= '<tr class="' . $status_class . '">
            <td>' . $row['installment_no'] . '</td>
            <td>' . date('d M Y', strtotime($row['due_date'])) . '</td>
            <td>KES ' . number_format($row['principal'], 2) . '</td>
            <td>KES ' . number_format($row['interest'], 2) . '</td>
            <td>KES ' . number_format($row['total_payment'], 2) . '</td>
            <td>KES ' . number_format($row['balance'], 2) . '</td>
            <td>' . ucfirst($row['status']) . '</td>
        </tr>';
    }

    $html .= '</tbody>
        </table>';

    if (!empty($repayments)) {
        $html .= '<h3>PAYMENT HISTORY</h3>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Amount</th>
                    <th>Principal</th>
                    <th>Interest</th>
                    <th>Method</th>
                    <th>Reference</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($repayments as $r) {
            $html .= '<tr>
                <td>' . date('d M Y', strtotime($r['payment_date'])) . '</td>
                <td>KES ' . number_format($r['amount_paid'], 2) . '</td>
                <td>KES ' . number_format($r['principal_paid'], 2) . '</td>
                <td>KES ' . number_format($r['interest_paid'], 2) . '</td>
                <td>' . ucfirst($r['payment_method']) . '</td>
                <td>' . $r['reference_no'] . '</td>
            </tr>';
        }

        $html .= '</tbody></table>';
    }

    $html .= '<div class="footer">
            <p>' . $sacco_info['name'] . ' | ' . $sacco_info['address'] . ' | Tel: ' . $sacco_info['phone'] . '</p>
            <p>This is a computer generated schedule. For any queries, please contact the SACCO office.</p>
        </div>
    </body>
    </html>';

    generatePDF($html, $filename);
}

function generatePDF($html, $filename)
{
    require_once '../../vendor/autoload.php';

    $dompdf = new Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream($filename, ['Attachment' => true]);
    exit();
}

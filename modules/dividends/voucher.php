<?php
require_once '../../config/config.php';
requireLogin();

$dividend_id = $_GET['id'] ?? 0;
$type = $_GET['type'] ?? 'view'; // view, print, download

// Get dividend details
$dividend_sql = "SELECT d.*, 
                 m.member_no, m.full_name, m.national_id, m.phone, m.email, m.address,
                 u.full_name as approved_by_name,
                 a.full_name as paid_by_name
                 FROM dividends d
                 JOIN members m ON d.member_id = m.id
                 LEFT JOIN users u ON d.approved_by = u.id
                 LEFT JOIN users a ON d.paid_by = a.id
                 WHERE d.id = ?";
$dividend_result = executeQuery($dividend_sql, "i", [$dividend_id]);

if ($dividend_result->num_rows == 0) {
    $_SESSION['error'] = 'Dividend record not found';
    header('Location: index.php');
    exit();
}

$dividend = $dividend_result->fetch_assoc();

// Get SACCO information (from settings table)
$sacco_info = [
    'name' => APP_NAME,
    'address' => '123 SACCO Plaza, Nairobi, Kenya',
    'phone' => '+254 700 000 000',
    'email' => 'info@sacco.co.ke',
    'website' => 'www.sacco.co.ke',
    'logo' => '../../assets/images/logo.png'
];

// Format numbers for display
$opening_balance = number_format($dividend['opening_balance'], 2);
$total_deposits = number_format($dividend['total_deposits'], 2);
$gross_dividend = number_format($dividend['gross_dividend'], 2);
$withholding_tax = number_format($dividend['withholding_tax'], 2);
$net_dividend = number_format($dividend['net_dividend'], 2);

// Amount in words function
function numberToWords($number)
{
    $words = array(
        0 => 'Zero',
        1 => 'One',
        2 => 'Two',
        3 => 'Three',
        4 => 'Four',
        5 => 'Five',
        6 => 'Six',
        7 => 'Seven',
        8 => 'Eight',
        9 => 'Nine',
        10 => 'Ten',
        11 => 'Eleven',
        12 => 'Twelve',
        13 => 'Thirteen',
        14 => 'Fourteen',
        15 => 'Fifteen',
        16 => 'Sixteen',
        17 => 'Seventeen',
        18 => 'Eighteen',
        19 => 'Nineteen',
        20 => 'Twenty',
        30 => 'Thirty',
        40 => 'Forty',
        50 => 'Fifty',
        60 => 'Sixty',
        70 => 'Seventy',
        80 => 'Eighty',
        90 => 'Ninety'
    );

    $number = floor($number);

    if ($number < 21) {
        return $words[$number];
    } elseif ($number < 100) {
        $tens = floor($number / 10) * 10;
        $units = $number % 10;
        return $words[$tens] . ($units ? ' ' . $words[$units] : '');
    } elseif ($number < 1000) {
        $hundreds = floor($number / 100);
        $remainder = $number % 100;
        return $words[$hundreds] . ' Hundred' . ($remainder ? ' and ' . numberToWords($remainder) : '');
    } elseif ($number < 1000000) {
        $thousands = floor($number / 1000);
        $remainder = $number % 1000;
        return numberToWords($thousands) . ' Thousand' . ($remainder ? ' ' . numberToWords($remainder) : '');
    } elseif ($number < 1000000000) {
        $millions = floor($number / 1000000);
        $remainder = $number % 1000000;
        return numberToWords($millions) . ' Million' . ($remainder ? ' ' . numberToWords($remainder) : '');
    }

    return 'Number too large';
}

$net_dividend_words = numberToWords($dividend['net_dividend']) . ' Shillings Only';

// Handle different view types
if ($type == 'download' || $type == 'print') {
    // For PDF download or print, we'll use the same HTML but with different headers
    if ($type == 'download') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="Dividend_Voucher_' . $dividend['member_no'] . '_' . $dividend['financial_year'] . '.pdf"');
    } else {
        header('Content-Type: text/html');
    }
}

// For view mode, include header
if ($type == 'view') {
    $page_title = 'Dividend Voucher - ' . $dividend['full_name'];
    include '../../includes/header.php';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dividend Voucher - <?php echo $dividend['full_name']; ?></title>

    <?php if ($type == 'print' || $type == 'download'): ?>
        <!-- Print/Download specific styles -->
        <style>
            body {
                font-family: 'Arial', sans-serif;
                margin: 0;
                padding: 20px;
                background: white;
            }

            .voucher-container {
                max-width: 800px;
                margin: 0 auto;
                background: white;
                border: 2px solid #28a745;
                border-radius: 15px;
                padding: 30px;
                box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
            }
        </style>
    <?php else: ?>
        <!-- View mode styles -->
        <style>
            .voucher-container {
                max-width: 800px;
                margin: 20px auto;
                background: white;
                border: 2px solid #28a745;
                border-radius: 15px;
                padding: 30px;
                box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
            }
        </style>
    <?php endif; ?>

    <style>
        .voucher-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px dashed #28a745;
        }

        .voucher-header h1 {
            color: #28a745;
            margin: 10px 0 5px;
            font-size: 28px;
            font-weight: bold;
        }

        .voucher-header h2 {
            color: #333;
            margin: 0;
            font-size: 20px;
            text-transform: uppercase;
        }

        .voucher-header h3 {
            color: #666;
            margin: 5px 0 0;
            font-size: 16px;
            font-weight: normal;
        }

        .voucher-number {
            position: absolute;
            top: 30px;
            right: 30px;
            background: #28a745;
            color: white;
            padding: 10px 20px;
            border-radius: 50px;
            font-weight: bold;
            font-size: 14px;
        }

        .member-info {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            border-left: 4px solid #28a745;
        }

        .member-info h4 {
            margin-top: 0;
            margin-bottom: 15px;
            color: #28a745;
            font-weight: bold;
        }

        .member-info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }

        .member-info-item {
            display: flex;
            flex-direction: column;
        }

        .member-info-item .label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .member-info-item .value {
            font-size: 16px;
            font-weight: bold;
            color: #333;
        }

        .dividend-details {
            margin-bottom: 30px;
        }

        .dividend-details h4 {
            color: #28a745;
            font-weight: bold;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #dee2e6;
        }

        .details-table {
            width: 100%;
            border-collapse: collapse;
        }

        .details-table tr {
            border-bottom: 1px solid #e9ecef;
        }

        .details-table td {
            padding: 12px 10px;
        }

        .details-table td:first-child {
            font-weight: 600;
            color: #495057;
            width: 50%;
        }

        .details-table td:last-child {
            text-align: right;
            font-weight: 500;
        }

        .details-table .total-row {
            background: #e8f5e9;
            font-weight: bold;
            font-size: 16px;
        }

        .details-table .total-row td {
            padding: 15px 10px;
        }

        .amount-in-words {
            background: #fff3cd;
            border: 1px solid #ffeeba;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            font-style: italic;
            color: #856404;
        }

        .amount-in-words .label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #856404;
            margin-bottom: 5px;
        }

        .amount-in-words .words {
            font-size: 18px;
            font-weight: 500;
            line-height: 1.4;
        }

        .signature-section {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 30px;
            margin-top: 40px;
            padding-top: 30px;
            border-top: 2px dashed #dee2e6;
        }

        .signature-item {
            text-align: center;
        }

        .signature-line {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #333;
            width: 100%;
        }

        .signature-label {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 5px;
        }

        .signature-name {
            font-weight: bold;
            margin-bottom: 2px;
        }

        .signature-date {
            font-size: 11px;
            color: #6c757d;
        }

        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 11px;
            color: #6c757d;
        }

        .footer p {
            margin: 3px 0;
        }

        .status-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-paid {
            background: #28a745;
            color: white;
        }

        .status-pending {
            background: #ffc107;
            color: #333;
        }

        .status-calculated {
            background: #17a2b8;
            color: white;
        }

        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 80px;
            color: rgba(40, 167, 69, 0.1);
            white-space: nowrap;
            pointer-events: none;
            z-index: -1;
        }

        .action-buttons {
            text-align: center;
            margin: 20px 0;
        }

        .action-buttons .btn {
            margin: 0 5px;
            padding: 10px 25px;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: #007bff;
            color: white;
            border: none;
        }

        .btn-success {
            background: #28a745;
            color: white;
            border: none;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
            border: none;
        }

        @media print {

            .action-buttons,
            .no-print {
                display: none;
            }

            body {
                padding: 0;
            }

            .voucher-container {
                box-shadow: none;
                border: 2px solid #000;
            }
        }
    </style>
</head>

<body>
    <div class="voucher-container">
        <!-- Watermark -->
        <?php if ($dividend['status'] == 'paid'): ?>
            <div class="watermark">PAID</div>
        <?php elseif ($dividend['status'] == 'approved'): ?>
            <div class="watermark">APPROVED</div>
        <?php else: ?>
            <div class="watermark">CALCULATED</div>
        <?php endif; ?>

        <!-- Voucher Number -->
        <div class="voucher-number">
            Voucher #: DIV-<?php echo str_pad($dividend['id'], 6, '0', STR_PAD_LEFT); ?>-<?php echo $dividend['financial_year']; ?>
        </div>

        <!-- Header -->
        <div class="voucher-header">
            <?php if (file_exists($sacco_info['logo'])): ?>
                <img src="<?php echo $sacco_info['logo']; ?>" alt="SACCO Logo" height="60">
            <?php endif; ?>
            <h1><?php echo $sacco_info['name']; ?></h1>
            <h2>DIVIDEND VOUCHER</h2>
            <h3>For the Financial Year <?php echo $dividend['financial_year']; ?></h3>
        </div>

        <!-- Member Information -->
        <div class="member-info">
            <h4><i class="fas fa-user"></i> Member Information</h4>
            <div class="member-info-grid">
                <div class="member-info-item">
                    <span class="label">Member Number</span>
                    <span class="value"><?php echo $dividend['member_no']; ?></span>
                </div>
                <div class="member-info-item">
                    <span class="label">Full Name</span>
                    <span class="value"><?php echo $dividend['full_name']; ?></span>
                </div>
                <div class="member-info-item">
                    <span class="label">National ID</span>
                    <span class="value"><?php echo $dividend['national_id']; ?></span>
                </div>
                <div class="member-info-item">
                    <span class="label">Phone Number</span>
                    <span class="value"><?php echo $dividend['phone']; ?></span>
                </div>
                <div class="member-info-item">
                    <span class="label">Email</span>
                    <span class="value"><?php echo $dividend['email'] ?: 'N/A'; ?></span>
                </div>
                <div class="member-info-item">
                    <span class="label">Status</span>
                    <span class="value">
                        <span class="status-badge status-<?php echo $dividend['status']; ?>">
                            <?php echo ucfirst($dividend['status']); ?>
                        </span>
                    </span>
                </div>
            </div>
        </div>

        <!-- Dividend Calculation Details -->
        <div class="dividend-details">
            <h4>Dividend Calculation Details</h4>
            <table class="details-table">
                <tr>
                    <td>Opening Balance (as at 01/01/<?php echo $dividend['financial_year']; ?>)</td>
                    <td>KES <?php echo number_format($dividend['opening_balance'], 2); ?></td>
                </tr>
                <tr>
                    <td>Total Deposits for the Year</td>
                    <td>KES <?php echo number_format($dividend['total_deposits'], 2); ?></td>
                </tr>
                <tr>
                    <td>Closing Balance (as at 31/12/<?php echo $dividend['financial_year']; ?>)</td>
                    <td>KES <?php echo number_format($dividend['opening_balance'] + $dividend['total_deposits'], 2); ?></td>
                </tr>
                <tr>
                    <td>Dividend Rate Declared</td>
                    <td><?php echo number_format($dividend['interest_rate'], 2); ?>%</td>
                </tr>
                <tr style="border-top: 2px solid #28a745;">
                    <td><strong>Gross Dividend</strong></td>
                    <td><strong>KES <?php echo number_format($dividend['gross_dividend'], 2); ?></strong></td>
                </tr>
                <tr>
                    <td>Less: Withholding Tax (5%)</td>
                    <td>KES <?php echo number_format($dividend['withholding_tax'], 2); ?></td>
                </tr>
                <tr class="total-row">
                    <td><strong>NET DIVIDEND PAYABLE</strong></td>
                    <td><strong>KES <?php echo number_format($dividend['net_dividend'], 2); ?></strong></td>
                </tr>
            </table>
        </div>

        <!-- Amount in Words -->
        <div class="amount-in-words">
            <div class="label">Amount in Words</div>
            <div class="words"><?php echo $net_dividend_words; ?></div>
        </div>

        <!-- Payment Information (if paid) -->
        <?php if ($dividend['status'] == 'paid'): ?>
            <div class="payment-info" style="background: #d4edda; padding: 15px; border-radius: 10px; margin-bottom: 30px;">
                <h4 style="color: #155724; margin-top: 0; margin-bottom: 10px;">Payment Information</h4>
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
                    <div>
                        <div style="font-size: 12px; color: #155724;">Payment Date</div>
                        <div style="font-weight: bold;"><?php echo $dividend['payment_date'] ? date('d M Y', strtotime($dividend['payment_date'])) : 'N/A'; ?></div>
                    </div>
                    <div>
                        <div style="font-size: 12px; color: #155724;">Payment Method</div>
                        <div style="font-weight: bold;"><?php echo $dividend['payment_method'] ?? 'Bank Transfer'; ?></div>
                    </div>
                    <div>
                        <div style="font-size: 12px; color: #155724;">Reference</div>
                        <div style="font-weight: bold;"><?php echo $dividend['payment_reference'] ?? 'DIV' . str_pad($dividend['id'], 6, '0', STR_PAD_LEFT); ?></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Signature Section -->
        <div class="signature-section">
            <div class="signature-item">
                <div class="signature-label">PREPARED BY</div>
                <div class="signature-name"><?php echo $dividend['approved_by_name'] ?? '_________________'; ?></div>
                <div class="signature-date">Date: <?php echo $dividend['approved_at'] ? date('d M Y', strtotime($dividend['approved_at'])) : '_____________'; ?></div>
            </div>

            <div class="signature-item">
                <div class="signature-label">CHECKED BY</div>
                <div class="signature-name">_________________</div>
                <div class="signature-date">Date: _____________</div>
            </div>

            <div class="signature-item">
                <div class="signature-label">AUTHORIZED BY</div>
                <div class="signature-name">_________________</div>
                <div class="signature-date">Date: _____________</div>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>This is a computer generated voucher. No signature required.</p>
            <p><?php echo $sacco_info['name']; ?> | <?php echo $sacco_info['address']; ?> | Tel: <?php echo $sacco_info['phone']; ?></p>
            <p>Email: <?php echo $sacco_info['email']; ?> | Website: <?php echo $sacco_info['website']; ?></p>
            <p>Generated on: <?php echo date('d M Y H:i:s'); ?></p>
        </div>
    </div>

    <!-- Action Buttons (only in view mode) -->
    <?php if ($type == 'view'): ?>
        <div class="action-buttons no-print">
            <button class="btn btn-primary" onclick="window.print()">
                <i class="fas fa-print"></i> Print Voucher
            </button>
            <a href="voucher.php?id=<?php echo $dividend_id; ?>&type=download" class="btn btn-success">
                <i class="fas fa-download"></i> Download PDF
            </a>
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Dividends
            </a>
        </div>
    <?php endif; ?>

    <!-- Auto-print for print type -->
    <?php if ($type == 'print'): ?>
        <script>
            window.onload = function() {
                window.print();
                setTimeout(function() {
                    window.close();
                }, 1000);
            };
        </script>
    <?php endif; ?>
</body>

</html>

<?php
// For view mode, include footer
if ($type == 'view') {
    include '../../includes/footer.php';
}
?>
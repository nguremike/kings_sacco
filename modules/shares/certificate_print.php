<?php
require_once '../../config/config.php';
requireLogin();

$share_id = $_GET['share_id'] ?? 0;
$certificate_id = $_GET['certificate_id'] ?? 0;
$type = $_GET['type'] ?? 'print'; // print, download, view

// Get share certificate details
if ($certificate_id > 0) {
    // Get by certificate ID
    $cert_sql = "SELECT si.*, 
                 m.member_no, m.full_name, m.national_id, m.phone, m.email, m.address, m.date_joined,
                 u.full_name as issued_by_name
                 FROM shares_issued si
                 JOIN members m ON si.member_id = m.id
                 LEFT JOIN users u ON si.issued_by = u.id
                 WHERE si.id = ?";
    $cert_result = executeQuery($cert_sql, "i", [$certificate_id]);
} else {
    // Get by share ID (from shares table)
    $cert_sql = "SELECT s.*, 
                 m.member_no, m.full_name, m.national_id, m.phone, m.email, m.address, m.date_joined,
                 u.full_name as issued_by_name,
                 s.date_purchased as issue_date,
                 s.shares_count as share_count,
                 s.total_value as amount_paid,
                 s.reference_no as certificate_number
                 FROM shares s
                 JOIN members m ON s.member_id = m.id
                 LEFT JOIN users u ON s.created_by = u.id
                 WHERE s.id = ?";
    $cert_result = executeQuery($cert_sql, "i", [$share_id]);
}

if ($cert_result->num_rows == 0) {
    $_SESSION['error'] = 'Share certificate not found';
    header('Location: index.php');
    exit();
}

$certificate = $cert_result->fetch_assoc();

// If we got from shares table and no certificate number exists, generate one
if (!isset($certificate['certificate_number']) || empty($certificate['certificate_number'])) {
    $certificate['certificate_number'] = 'CERT' .
        date('Ymd', strtotime($certificate['date_purchased'])) .
        str_pad($certificate['member_id'], 4, '0', STR_PAD_LEFT) .
        str_pad($certificate['id'], 4, '0', STR_PAD_LEFT);
}

// If no share number exists, generate one
if (!isset($certificate['share_number']) || empty($certificate['share_number'])) {
    $certificate['share_number'] = 'SH' .
        date('Y', strtotime($certificate['date_purchased'])) .
        str_pad($certificate['member_id'], 4, '0', STR_PAD_LEFT) .
        str_pad($certificate['id'], 4, '0', STR_PAD_LEFT);
}

// Get SACCO information
$sacco_info = [
    'name' => APP_NAME,
    'address' => 'Government Rd, Tropical House, Nakuru, Kenya',
    'phone' => '+254 722 772473',
    'email' => 'kingsunitedsacco@gmail.com',
    // 'website' => 'www.sacco.co.ke',
    'logo' => '../../assets/images/logo.png',
    'registration_no' => 'SACCO/REG/001/2020',
    'chairperson' => 'Charles G. Nderitu',
    // 'secretary' => 'Maragara Gachie'
];

// Handle different view types
if ($type == 'download') {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="Share_Certificate_' . $certificate['member_no'] . '_' . $certificate['share_number'] . '.pdf"');
} elseif ($type == 'print') {
    header('Content-Type: text/html');
}

// For view mode, include header
if ($type == 'view') {
    $page_title = 'Share Certificate - ' . $certificate['full_name'];
    include '../../includes/header.php';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Share Certificate - <?php echo $certificate['full_name']; ?></title>

    <style>
        /* Minimal A4-friendly styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Arial', sans-serif;
            background: <?php echo ($type == 'view') ? '#f4f4f4' : 'white'; ?>;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }

        .certificate {
            width: 210mm;
            /* A4 width */
            min-height: 297mm;
            /* A4 height */
            background: white;
            margin: 0 auto;
            padding: 15mm 15mm 10mm 15mm;
            box-shadow: <?php echo ($type == 'view') ? '0 0 10px rgba(0,0,0,0.1)' : 'none'; ?>;
            position: relative;
            page-break-after: avoid;
            page-break-inside: avoid;
        }

        /* Header */
        .header {
            text-align: center;
            margin-bottom: 15px;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
        }

        .header h1 {
            font-size: 24px;
            font-weight: bold;
            margin: 5px 0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .header h2 {
            font-size: 18px;
            font-weight: normal;
            margin: 0;
            color: #444;
        }

        /* Certificate Number */
        .cert-no {
            text-align: right;
            font-size: 12px;
            margin-bottom: 20px;
            color: #666;
        }

        /* Title */
        .title {
            text-align: center;
            font-size: 28px;
            font-weight: bold;
            margin: 20px 0;
            text-transform: uppercase;
            letter-spacing: 3px;
            color: #000;
        }

        /* To Whom */
        .to-whom {
            text-align: center;
            font-size: 14px;
            margin: 10px 0 20px;
            font-style: italic;
        }

        /* Member Name */
        .member-name {
            text-align: center;
            font-size: 26px;
            font-weight: bold;
            margin: 20px 0;
            padding: 10px 0;
            border-top: 2px solid #333;
            border-bottom: 2px solid #333;
            text-transform: uppercase;
        }

        /* Info Grid */
        .info-grid {
            display: flex;
            flex-wrap: wrap;
            margin: 20px 0;
            border: 1px solid #ddd;
        }

        .info-item {
            flex: 1 1 50%;
            padding: 8px 12px;
            border-bottom: 1px solid #eee;
            font-size: 14px;
        }

        .info-item strong {
            display: inline-block;
            width: 100px;
            color: #333;
        }

        /* Share Details Table */
        .share-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            border: 1px solid #333;
        }

        .share-table th {
            background: #f0f0f0;
            padding: 10px;
            text-align: left;
            font-size: 14px;
            border: 1px solid #333;
        }

        .share-table td {
            padding: 8px 10px;
            border: 1px solid #333;
            font-size: 14px;
        }

        .share-table .total-row {
            font-weight: bold;
            background: #f9f9f9;
        }

        .text-right {
            text-align: right;
        }

        /* Amount in words */
        .amount-words {
            margin: 20px 0;
            padding: 10px;
            background: #f9f9f9;
            border: 1px solid #ddd;
            font-style: italic;
            font-size: 14px;
        }

        .amount-words .label {
            font-weight: bold;
            margin-right: 10px;
            font-style: normal;
        }

        /* Signatures */
        .signatures {
            display: flex;
            justify-content: space-between;
            margin: 40px 0 20px;
        }

        .signature {
            text-align: center;
            width: 45%;
        }

        .signature-line {
            margin: 30px 0 5px;
            border-top: 1px solid #333;
            width: 100%;
        }

        .signature-name {
            font-weight: bold;
            font-size: 14px;
        }

        .signature-title {
            font-size: 12px;
            color: #666;
            margin-top: 3px;
        }

        /* Footer */
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 10px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }

        .footer p {
            margin: 2px 0;
        }

        /* Action buttons (only for view mode) */
        .actions {
            text-align: center;
            margin: 20px 0;
        }

        .actions .btn {
            display: inline-block;
            padding: 10px 20px;
            margin: 0 5px;
            text-decoration: none;
            color: white;
            border-radius: 4px;
            font-size: 14px;
            border: none;
            cursor: pointer;
        }

        .btn-primary {
            background: #007bff;
        }

        .btn-success {
            background: #28a745;
        }

        .btn-secondary {
            background: #6c757d;
        }

        .btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        /* Print styles */
        @media print {
            body {
                background: white;
                padding: 0;
            }

            .certificate {
                box-shadow: none;
                padding: 10mm;
                width: 100%;
                height: auto;
            }

            .actions,
            .no-print {
                display: none;
            }

            .share-table th {
                background: #f0f0f0 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }

        /* Ensure A4 sizing for print */
        @page {
            size: A4;
            margin: 10mm;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .certificate {
                width: 100%;
                padding: 10px;
            }

            .info-item {
                flex: 1 1 100%;
            }

            .signatures {
                flex-direction: column;
                gap: 20px;
            }

            .signature {
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <div class="certificate">
        <!-- Header -->
        <div class="header">
            <h1><?php echo $sacco_info['name']; ?></h1>
            <h2>SHARE CERTIFICATE</h2>
        </div>

        <!-- Certificate Number -->
        <div class="cert-no">
            Certificate No: <strong><?php echo $certificate['certificate_number']; ?></strong>
        </div>

        <!-- Title -->
        <div class="title">CERTIFICATE OF SHARE OWNERSHIP</div>

        <!-- To Whom -->
        <div class="to-whom">THIS IS TO CERTIFY THAT</div>

        <!-- Member Name -->
        <div class="member-name"><?php echo strtoupper($certificate['full_name']); ?></div>

        <!-- Member Information -->
        <div class="info-grid">
            <div class="info-item">
                <strong>Member No:</strong> <?php echo $certificate['member_no']; ?>
            </div>
            <div class="info-item">
                <strong>National ID:</strong> <?php echo $certificate['national_id']; ?>
            </div>
            <div class="info-item">
                <strong>Date Joined:</strong> <?php echo date('d/m/Y', strtotime($certificate['date_joined'])); ?>
            </div>
            <div class="info-item">
                <strong>Share Number:</strong> <?php echo $certificate['share_number']; ?>
            </div>
        </div>

        <!-- Share Details Table -->
        <table class="share-table">
            <tr>
                <th>Description</th>
                <th class="text-right">Details</th>
            </tr>
            <tr>
                <td>Number of Shares</td>
                <td class="text-right"><?php echo number_format($certificate['share_count']); ?></td>
            </tr>
            <tr>
                <td>Value per Share</td>
                <td class="text-right">KES <?php echo number_format(10000, 2); ?></td>
            </tr>
            <tr class="total-row">
                <td>Total Value</td>
                <td class="text-right">KES <?php echo number_format($certificate['amount_paid'], 2); ?></td>
            </tr>
            <tr>
                <td>Issue Date</td>
                <td class="text-right"><?php echo date('d/m/Y', strtotime($certificate['issue_date'])); ?></td>
            </tr>
        </table>

        <!-- Amount in Words (short version) -->
        <div class="amount-words">
            <span class="label">Amount in Words:</span>
            <?php
            // Simple word conversion for the minimal version
            $amount_words = number_format($certificate['share_count']) . ' share' . ($certificate['share_count'] > 1 ? 's' : '');
            echo $amount_words . ' at KES 10,000 each';
            ?>
        </div>

        <!-- Signatures -->
        <div class="signatures">
            <div class="signature">
                <div class="signature-line"></div>
                <div class="signature-name"><?php echo $sacco_info['chairperson']; ?></div>
                <div class="signature-title">Chairperson</div>
                <div style="font-size: 11px; margin-top: 5px;">Date: <?php echo date('d/m/Y'); ?></div>
            </div>

            <!-- <div class="signature">
                <div class="signature-line"></div>
                <div class="signature-name"><? //php echo $sacco_info['secretary']; 
                                            ?></div>
                <div class="signature-title">Secretary</div>
                <div style="font-size: 11px; margin-top: 5px;">Date: <? //php echo date('d/m/Y'); 
                                                                        ?></div>
            </div> -->
        </div>

        <!-- Footer -->
        <div class="footer">
            <p><?php echo $sacco_info['name']; ?> | Reg: <?php echo $sacco_info['registration_no']; ?></p>
            <p><?php echo $sacco_info['address']; ?> | Tel: <?php echo $sacco_info['phone']; ?></p>
            <p>This certificate is the property of the Society</p>
        </div>
    </div>

    <!-- Action Buttons (only in view mode) -->
    <?php if ($type == 'view'): ?>
        <div class="actions no-print">
            <button class="btn btn-primary" onclick="window.print()">
                <i class="fas fa-print"></i> Print
            </button>
            <a href="certificate_print.php?<?php echo $certificate_id > 0 ? 'certificate_id=' . $certificate_id : 'share_id=' . $share_id; ?>&type=download" class="btn btn-success">
                <i class="fas fa-download"></i> Download PDF
            </a>
            <a href="certificates.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back
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
<?php
// modules/dividends/reports.php
require_once '../../config/config.php';
requireLogin();

$page_title = 'Dividend Reports';

// Get filter parameters
$report_type = $_GET['report_type'] ?? 'summary';
$year = $_GET['year'] ?? date('Y');
$member_id = $_GET['member_id'] ?? '';
$export = $_GET['export'] ?? '';

// Handle export
if ($export == 'excel' || $export == 'pdf') {
    exportDividendReport($report_type, $year, $member_id, $export);
}

function exportDividendReport($type, $year, $member_id, $format)
{
    // Get data based on report type
    $data = getDividendReportData($type, $year, $member_id);

    if ($format == 'excel') {
        exportToExcel($data, $type, $year);
    } elseif ($format == 'pdf') {
        exportToPDF($data, $type, $year);
    }
}

function getDividendReportData($type, $year, $member_id)
{
    $conn = getConnection();

    switch ($type) {
        case 'summary':
            $sql = "SELECT 
                    d.financial_year,
                    COUNT(DISTINCT d.member_id) as total_members,
                    COUNT(*) as total_records,
                    SUM(d.gross_dividend) as total_gross,
                    SUM(d.withholding_tax) as total_tax,
                    SUM(d.net_dividend) as total_net,
                    AVG(d.net_dividend) as avg_dividend,
                    MIN(d.net_dividend) as min_dividend,
                    MAX(d.net_dividend) as max_dividend,
                    SUM(CASE WHEN d.status = 'paid' THEN 1 ELSE 0 END) as paid_count,
                    SUM(CASE WHEN d.status = 'paid' THEN d.net_dividend ELSE 0 END) as paid_amount,
                    SUM(CASE WHEN d.status = 'calculated' THEN 1 ELSE 0 END) as pending_count,
                    SUM(CASE WHEN d.calculation_method = 'kenya_sacco_pro_rata' THEN 1 ELSE 0 END) as pro_rata_count,
                    SUM(CASE WHEN d.calculation_method = 'standard' THEN 1 ELSE 0 END) as standard_count
                    FROM dividends d
                    WHERE d.financial_year = ?
                    GROUP BY d.financial_year";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $year);
            break;

        case 'member':
            $sql = "SELECT 
                    d.*,
                    m.member_no,
                    m.full_name,
                    m.national_id,
                    m.phone,
                    m.date_joined,
                    u.full_name as calculated_by_name,
                    p.full_name as paid_by_name
                    FROM dividends d
                    JOIN members m ON d.member_id = m.id
                    LEFT JOIN users u ON d.calculated_by = u.id
                    LEFT JOIN users p ON d.paid_by = p.id
                    WHERE d.financial_year = ?
                    ORDER BY d.net_dividend DESC";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $year);
            break;

        case 'single':
            $sql = "SELECT 
                    d.*,
                    m.member_no,
                    m.full_name,
                    m.national_id,
                    m.phone,
                    m.email,
                    m.address,
                    m.date_joined,
                    u.full_name as calculated_by_name,
                    p.full_name as paid_by_name,
                    (SELECT SUM(amount) FROM dividend_contributions WHERE dividend_id = d.id) as total_weighted,
                    (SELECT COUNT(*) FROM dividend_contributions WHERE dividend_id = d.id) as contribution_months
                    FROM dividends d
                    JOIN members m ON d.member_id = m.id
                    LEFT JOIN users u ON d.calculated_by = u.id
                    LEFT JOIN users p ON d.paid_by = p.id
                    WHERE d.financial_year = ? AND d.member_id = ?
                    ORDER BY d.net_dividend DESC";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $year, $member_id);
            break;

        case 'yearly':
            $sql = "SELECT 
                    d.financial_year,
                    COUNT(DISTINCT d.member_id) as members,
                    COUNT(*) as records,
                    SUM(d.gross_dividend) as gross,
                    SUM(d.withholding_tax) as tax,
                    SUM(d.net_dividend) as net,
                    AVG(d.net_dividend) as average,
                    SUM(CASE WHEN d.calculation_method = 'kenya_sacco_pro_rata' THEN d.net_dividend ELSE 0 END) as pro_rata_total,
                    SUM(CASE WHEN d.calculation_method = 'standard' THEN d.net_dividend ELSE 0 END) as standard_total
                    FROM dividends d
                    GROUP BY d.financial_year
                    ORDER BY d.financial_year DESC";
            $stmt = $conn->prepare($sql);
            break;

        case 'comparison':
            $sql = "SELECT 
                    d.financial_year,
                    d.calculation_method,
                    COUNT(*) as member_count,
                    SUM(d.gross_dividend) as total_gross,
                    SUM(d.net_dividend) as total_net,
                    AVG(d.net_dividend) as avg_net,
                    MIN(d.net_dividend) as min_net,
                    MAX(d.net_dividend) as max_net
                    FROM dividends d
                    WHERE d.financial_year = ?
                    GROUP BY d.financial_year, d.calculation_method
                    ORDER BY d.calculation_method";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $year);
            break;

        case 'payment':
            $sql = "SELECT 
                    dp.*,
                    d.financial_year,
                    d.net_dividend,
                    m.member_no,
                    m.full_name,
                    u.full_name as processed_by_name
                    FROM dividend_payments dp
                    JOIN dividends d ON dp.dividend_id = d.id
                    JOIN members m ON d.member_id = m.id
                    LEFT JOIN users u ON dp.paid_by = u.id
                    WHERE YEAR(dp.payment_date) = ?
                    ORDER BY dp.payment_date DESC";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $year);
            break;

        default:
            return [];
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    $conn->close();
    return $data;
}

function exportToExcel($data, $report_type, $year)
{
    require_once '../../vendor/autoload.php';

    $spreadsheet = new PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Set headers based on report type
    switch ($report_type) {
        case 'summary':
            $headers = [
                'Financial Year',
                'Total Members',
                'Total Records',
                'Gross Dividend',
                'Withholding Tax',
                'Net Dividend',
                'Average',
                'Minimum',
                'Maximum',
                'Paid Count',
                'Paid Amount',
                'Pending',
                'Pro-rata Count',
                'Standard Count'
            ];
            break;
        case 'member':
            $headers = [
                'Member No',
                'Member Name',
                'National ID',
                'Phone',
                'Date Joined',
                'Opening Balance',
                'Total Deposits',
                'Interest Rate',
                'Gross Dividend',
                'Withholding Tax',
                'Net Dividend',
                'Status',
                'Calculation Method',
                'Eligibility Months',
                'Calculated By'
            ];
            break;
        case 'single':
            $headers = [
                'Member No',
                'Member Name',
                'National ID',
                'Phone',
                'Email',
                'Date Joined',
                'Opening Balance',
                'Adjusted Opening',
                'Total Withdrawals',
                'Total Penalties',
                'Total Charges',
                'Total Deposits',
                'Interest Rate',
                'Gross Dividend',
                'Withholding Tax',
                'Net Dividend',
                'Status',
                'Calculation Method',
                'Eligibility Months',
                'Calculated By',
                'Paid By',
                'Payment Date'
            ];
            break;
        case 'yearly':
            $headers = [
                'Year',
                'Members',
                'Records',
                'Gross (KES)',
                'Tax (KES)',
                'Net (KES)',
                'Average (KES)',
                'Pro-rata Total',
                'Standard Total'
            ];
            break;
        case 'comparison':
            $headers = [
                'Year',
                'Method',
                'Members',
                'Gross (KES)',
                'Net (KES)',
                'Average (KES)',
                'Minimum (KES)',
                'Maximum (KES)'
            ];
            break;
        case 'payment':
            $headers = [
                'Payment Date',
                'Member No',
                'Member Name',
                'Financial Year',
                'Dividend Amount',
                'Amount Paid',
                'Payment Method',
                'Reference No',
                'Receipt No',
                'Processed By'
            ];
            break;
        default:
            $headers = array_keys($data[0] ?? []);
    }

    // Write headers
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . '1', $header);
        $sheet->getStyle($col . '1')->getFont()->setBold(true);
        $sheet->getStyle($col . '1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF28A745');
        $sheet->getStyle($col . '1')->getFont()->getColor()->setARGB('FFFFFFFF');
        $col++;
    }

    // Write data
    $row = 2;
    foreach ($data as $item) {
        $col = 'A';
        foreach ($item as $key => $value) {
            // Format currency values
            if (
                strpos($key, 'amount') !== false || strpos($key, 'dividend') !== false ||
                strpos($key, 'balance') !== false || strpos($key, 'gross') !== false ||
                strpos($key, 'net') !== false || strpos($key, 'tax') !== false ||
                strpos($key, 'paid') !== false
            ) {
                if (is_numeric($value)) {
                    $sheet->setCellValue($col . $row, $value);
                    $sheet->getStyle($col . $row)->getNumberFormat()
                        ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
                } else {
                    $sheet->setCellValue($col . $row, $value);
                }
            } else {
                $sheet->setCellValue($col . $row, $value);
            }
            $col++;
        }
        $row++;
    }

    // Auto-size columns
    foreach (range('A', $col) as $columnID) {
        $sheet->getColumnDimension($columnID)->setAutoSize(true);
    }

    // Set filename
    $filename = 'dividend_report_' . $report_type . '_' . $year . '_' . date('Ymd') . '.xlsx';

    // Output
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit();
}

function exportToPDF($data, $report_type, $year)
{
    require_once '../../vendor/autoload.php';

    $pdf = new \Dompdf\Dompdf();

    // Build HTML
    $html = '<html><head><style>
        body { font-family: Arial, sans-serif; font-size: 10pt; }
        h2 { color: #28a745; text-align: center; margin-bottom: 5px; }
        h4 { text-align: center; margin-top: 0; color: #666; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 9pt; }
        th { background: #28a745; color: white; padding: 8px; text-align: left; }
        td { padding: 6px; border: 1px solid #ddd; }
        tr:nth-child(even) { background: #f9f9f9; }
        .total-row { background: #e8f5e9; font-weight: bold; }
        .footer { text-align: center; margin-top: 20px; font-size: 8pt; color: #666; }
        .summary-box { border: 1px solid #28a745; padding: 10px; margin-bottom: 15px; border-radius: 5px; }
        .summary-item { display: inline-block; margin-right: 20px; }
        .text-right { text-align: right; }
        .text-success { color: #28a745; }
        .text-danger { color: #dc3545; }
    </style></head><body>';

    $html .= '<h2>' . APP_NAME . '</h2>';
    $html .= '<h4>Dividend Report - ' . ucfirst(str_replace('_', ' ', $report_type)) . ' - ' . $year . '</h4>';
    $html .= '<p>Generated on: ' . date('d M Y H:i:s') . '</p>';

    // Summary box for numeric totals
    if (!empty($data) && $report_type == 'summary') {
        $total_gross = array_sum(array_column($data, 'total_gross'));
        $total_tax = array_sum(array_column($data, 'total_tax'));
        $total_net = array_sum(array_column($data, 'total_net'));

        $html .= '<div class="summary-box">';
        $html .= '<div class="summary-item"><strong>Total Gross:</strong> KES ' . number_format($total_gross, 2) . '</div>';
        $html .= '<div class="summary-item"><strong>Total Tax:</strong> KES ' . number_format($total_tax, 2) . '</div>';
        $html .= '<div class="summary-item"><strong>Total Net:</strong> KES ' . number_format($total_net, 2) . '</div>';
        $html .= '</div>';
    }

    $html .= '<table>';

    // Headers
    $html .= '<tr>';
    switch ($report_type) {
        case 'summary':
            $html .= '<th>Year</th><th>Members</th><th>Records</th><th>Gross (KES)</th><th>Tax (KES)</th>';
            $html .= '<th>Net (KES)</th><th>Average</th><th>Min</th><th>Max</th><th>Paid</th><th>Pending</th>';
            break;
        case 'member':
            $html .= '<th>Member No</th><th>Name</th><th>Opening</th><th>Deposits</th><th>Rate</th>';
            $html .= '<th>Gross</th><th>Tax</th><th>Net</th><th>Status</th><th>Method</th>';
            break;
        case 'yearly':
            $html .= '<th>Year</th><th>Members</th><th>Records</th><th>Gross (KES)</th><th>Tax (KES)</th>';
            $html .= '<th>Net (KES)</th><th>Average</th><th>Pro-rata</th><th>Standard</th>';
            break;
        case 'comparison':
            $html .= '<th>Method</th><th>Members</th><th>Gross (KES)</th><th>Net (KES)</th>';
            $html .= '<th>Average</th><th>Minimum</th><th>Maximum</th>';
            break;
        case 'payment':
            $html .= '<th>Date</th><th>Member</th><th>Year</th><th>Dividend</th><th>Paid</th>';
            $html .= '<th>Method</th><th>Reference</th><th>Processed By</th>';
            break;
        default:
            $html .= '<th>Member</th><th>Amount</th><th>Status</th>';
    }
    $html .= '</tr>';

    // Data rows
    foreach ($data as $row) {
        $html .= '<tr>';
        switch ($report_type) {
            case 'summary':
                $html .= '<td>' . $row['financial_year'] . '</td>';
                $html .= '<td class="text-right">' . number_format($row['total_members']) . '</td>';
                $html .= '<td class="text-right">' . number_format($row['total_records']) . '</td>';
                $html .= '<td class="text-right">' . number_format($row['total_gross'], 2) . '</td>';
                $html .= '<td class="text-right">' . number_format($row['total_tax'], 2) . '</td>';
                $html .= '<td class="text-right text-success">' . number_format($row['total_net'], 2) . '</td>';
                $html .= '<td class="text-right">' . number_format($row['avg_dividend'], 2) . '</td>';
                $html .= '<td class="text-right">' . number_format($row['min_dividend'], 2) . '</td>';
                $html .= '<td class="text-right">' . number_format($row['max_dividend'], 2) . '</td>';
                $html .= '<td class="text-right">' . $row['paid_count'] . '</td>';
                $html .= '<td class="text-right">' . $row['pending_count'] . '</td>';
                break;

            case 'member':
                $html .= '<td>' . $row['member_no'] . '</td>';
                $html .= '<td>' . $row['full_name'] . '</td>';
                $html .= '<td class="text-right">' . number_format($row['opening_balance'], 2) . '</td>';
                $html .= '<td class="text-right">' . number_format($row['total_deposits'], 2) . '</td>';
                $html .= '<td class="text-right">' . $row['interest_rate'] . '%</td>';
                $html .= '<td class="text-right">' . number_format($row['gross_dividend'], 2) . '</td>';
                $html .= '<td class="text-right">' . number_format($row['withholding_tax'], 2) . '</td>';
                $html .= '<td class="text-right text-success">' . number_format($row['net_dividend'], 2) . '</td>';
                $html .= '<td>' . ucfirst($row['status']) . '</td>';
                $html .= '<td>' . ($row['calculation_method'] == 'kenya_sacco_pro_rata' ? 'Pro-rata' : 'Standard') . '</td>';
                break;

            case 'yearly':
                $html .= '<td>' . $row['financial_year'] . '</td>';
                $html .= '<td class="text-right">' . number_format($row['members']) . '</td>';
                $html .= '<td class="text-right">' . number_format($row['records']) . '</td>';
                $html .= '<td class="text-right">' . number_format($row['gross'], 2) . '</td>';
                $html .= '<td class="text-right">' . number_format($row['tax'], 2) . '</td>';
                $html .= '<td class="text-right text-success">' . number_format($row['net'], 2) . '</td>';
                $html .= '<td class="text-right">' . number_format($row['average'], 2) . '</td>';
                $html .= '<td class="text-right">' . number_format($row['pro_rata_total'], 2) . '</td>';
                $html .= '<td class="text-right">' . number_format($row['standard_total'], 2) . '</td>';
                break;

            case 'comparison':
                $method = $row['calculation_method'] == 'kenya_sacco_pro_rata' ? 'Pro-rata' : 'Standard';
                $html .= '<td>' . $method . '</td>';
                $html .= '<td class="text-right">' . number_format($row['member_count']) . '</td>';
                $html .= '<td class="text-right">' . number_format($row['total_gross'], 2) . '</td>';
                $html .= '<td class="text-right text-success">' . number_format($row['total_net'], 2) . '</td>';
                $html .= '<td class="text-right">' . number_format($row['avg_net'], 2) . '</td>';
                $html .= '<td class="text-right">' . number_format($row['min_net'], 2) . '</td>';
                $html .= '<td class="text-right">' . number_format($row['max_net'], 2) . '</td>';
                break;

            case 'payment':
                $html .= '<td>' . date('d/m/Y', strtotime($row['payment_date'])) . '</td>';
                $html .= '<td>' . $row['full_name'] . '<br><small>' . $row['member_no'] . '</small></td>';
                $html .= '<td>' . $row['financial_year'] . '</td>';
                $html .= '<td class="text-right">' . number_format($row['net_dividend'], 2) . '</td>';
                $html .= '<td class="text-right text-success">' . number_format($row['amount_paid'], 2) . '</td>';
                $html .= '<td>' . ucfirst($row['payment_method']) . '</td>';
                $html .= '<td>' . $row['reference_no'] . '</td>';
                $html .= '<td>' . ($row['processed_by_name'] ?? 'System') . '</td>';
                break;
        }
        $html .= '</tr>';
    }

    // Add totals row for member report
    if ($report_type == 'member' && !empty($data)) {
        $total_gross = array_sum(array_column($data, 'gross_dividend'));
        $total_tax = array_sum(array_column($data, 'withholding_tax'));
        $total_net = array_sum(array_column($data, 'net_dividend'));

        $html .= '<tr class="total-row">';
        $html .= '<td colspan="5" class="text-right"><strong>TOTAL:</strong></td>';
        $html .= '<td class="text-right"><strong>' . number_format($total_gross, 2) . '</strong></td>';
        $html .= '<td class="text-right"><strong>' . number_format($total_tax, 2) . '</strong></td>';
        $html .= '<td class="text-right text-success"><strong>' . number_format($total_net, 2) . '</strong></td>';
        $html .= '<td colspan="2"></td>';
        $html .= '</tr>';
    }

    $html .= '</table>';

    // Footer
    $html .= '<div class="footer">';
    $html .= '<p>Generated by ' . APP_NAME . ' | Total Records: ' . count($data) . '</p>';
    $html .= '<p>' . date('Y') . ' &copy; All Rights Reserved</p>';
    $html .= '</div>';

    $html .= '</body></html>';

    $pdf->loadHtml($html);
    $pdf->setPaper('A4', 'landscape');
    $pdf->render();

    $filename = 'dividend_report_' . $report_type . '_' . $year . '_' . date('Ymd') . '.pdf';
    $pdf->stream($filename, ['Attachment' => true]);
    exit();
}

// Get report data for display
$report_data = getDividendReportData($report_type, $year, $member_id);

// Get years for dropdown
$years_sql = "SELECT DISTINCT financial_year FROM dividends ORDER BY financial_year DESC";
$years_result = executeQuery($years_sql);
$years = [];
while ($y = $years_result->fetch_assoc()) {
    $years[] = $y['financial_year'];
}

// Get members for dropdown
$members_sql = "SELECT id, member_no, full_name FROM members WHERE membership_status = 'active' ORDER BY member_no";
$members = executeQuery($members_sql);

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Dividend Reports</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Dividends</a></li>
                <li class="breadcrumb-item active">Reports</li>
            </ul>
        </div>
        <div class="col-auto">
            <button class="btn btn-success" onclick="exportReport('excel')">
                <i class="fas fa-file-excel"></i> Export to Excel
            </button>
            <button class="btn btn-danger" onclick="exportReport('pdf')">
                <i class="fas fa-file-pdf"></i> Export to PDF
            </button>
            <button class="btn btn-primary" onclick="window.print()">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>
</div>

<!-- Report Filter Card -->
<div class="card mb-4">
    <div class="card-header bg-success text-white">
        <h5 class="card-title mb-0"><i class="fas fa-filter"></i> Report Filters</h5>
    </div>
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-3">
                <label for="report_type" class="form-label">Report Type</label>
                <select class="form-control" id="report_type" name="report_type" onchange="toggleMemberField()">
                    <option value="summary" <?php echo $report_type == 'summary' ? 'selected' : ''; ?>>Annual Summary</option>
                    <option value="member" <?php echo $report_type == 'member' ? 'selected' : ''; ?>>Member List</option>
                    <option value="single" <?php echo $report_type == 'single' ? 'selected' : ''; ?>>Single Member</option>
                    <option value="yearly" <?php echo $report_type == 'yearly' ? 'selected' : ''; ?>>Yearly Comparison</option>
                    <option value="comparison" <?php echo $report_type == 'comparison' ? 'selected' : ''; ?>>Method Comparison</option>
                    <option value="payment" <?php echo $report_type == 'payment' ? 'selected' : ''; ?>>Payment History</option>
                </select>
            </div>

            <div class="col-md-2">
                <label for="year" class="form-label">Year</label>
                <select class="form-control" id="year" name="year">
                    <?php if (!empty($years)): ?>
                        <?php foreach ($years as $y): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="<?php echo date('Y'); ?>"><?php echo date('Y'); ?></option>
                    <?php endif; ?>
                </select>
            </div>

            <div class="col-md-3" id="member_field" style="<?php echo $report_type == 'single' ? 'display:block' : 'display:none'; ?>">
                <label for="member_id" class="form-label">Select Member</label>
                <select class="form-control" id="member_id" name="member_id">
                    <option value="">-- Select Member --</option>
                    <?php while ($m = $members->fetch_assoc()): ?>
                        <option value="<?php echo $m['id']; ?>" <?php echo $member_id == $m['id'] ? 'selected' : ''; ?>>
                            <?php echo $m['member_no']; ?> - <?php echo $m['full_name']; ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary d-block w-100">
                    <i class="fas fa-search"></i> Generate
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Report Results -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title">
            <?php
            $titles = [
                'summary' => 'Annual Dividend Summary - ' . $year,
                'member' => 'Member Dividend List - ' . $year,
                'single' => 'Individual Member Dividend',
                'yearly' => 'Yearly Dividend Comparison',
                'comparison' => 'Method Comparison - ' . $year,
                'payment' => 'Dividend Payment History - ' . $year
            ];
            echo $titles[$report_type] ?? 'Dividend Report';
            ?>
        </h5>
        <div class="card-tools">
            <span class="badge bg-info">Records: <?php echo count($report_data); ?></span>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($report_data)): ?>
            <div class="alert alert-info text-center py-4">
                <i class="fas fa-info-circle fa-3x mb-3"></i>
                <h5>No data found</h5>
                <p class="text-muted">No dividend records match your filter criteria.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered table-striped datatable">
                    <thead>
                        <tr>
                            <?php if ($report_type == 'summary'): ?>
                                <th>Year</th>
                                <th>Members</th>
                                <th>Records</th>
                                <th>Gross (KES)</th>
                                <th>Tax (KES)</th>
                                <th>Net (KES)</th>
                                <th>Average</th>
                                <th>Minimum</th>
                                <th>Maximum</th>
                                <th>Paid</th>
                                <th>Pending</th>
                                <th>Pro-rata</th>
                                <th>Standard</th>
                            <?php elseif ($report_type == 'member'): ?>
                                <th>Member No</th>
                                <th>Member Name</th>
                                <th>Opening</th>
                                <th>Deposits</th>
                                <th>Rate</th>
                                <th>Gross</th>
                                <th>Tax</th>
                                <th>Net</th>
                                <th>Status</th>
                                <th>Method</th>
                                <th>Action</th>
                            <?php elseif ($report_type == 'single'): ?>
                                <th>Detail</th>
                                <th>Value</th>
                            <?php elseif ($report_type == 'yearly'): ?>
                                <th>Year</th>
                                <th>Members</th>
                                <th>Records</th>
                                <th>Gross (KES)</th>
                                <th>Tax (KES)</th>
                                <th>Net (KES)</th>
                                <th>Average</th>
                                <th>Pro-rata Total</th>
                                <th>Standard Total</th>
                            <?php elseif ($report_type == 'comparison'): ?>
                                <th>Method</th>
                                <th>Members</th>
                                <th>Gross (KES)</th>
                                <th>Net (KES)</th>
                                <th>Average</th>
                                <th>Minimum</th>
                                <th>Maximum</th>
                            <?php elseif ($report_type == 'payment'): ?>
                                <th>Date</th>
                                <th>Member</th>
                                <th>Year</th>
                                <th>Dividend</th>
                                <th>Paid</th>
                                <th>Method</th>
                                <th>Reference</th>
                                <th>Receipt</th>
                                <th>Processed By</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($report_type == 'single' && !empty($report_data)):
                            $d = $report_data[0];
                        ?>
                            <tr>
                                <td><strong>Member No</strong></td>
                                <td><?php echo $d['member_no']; ?></td>
                            </tr>
                            <tr>
                                <td><strong>Member Name</strong></td>
                                <td><?php echo $d['full_name']; ?></td>
                            </tr>
                            <tr>
                                <td><strong>National ID</strong></td>
                                <td><?php echo $d['national_id']; ?></td>
                            </tr>
                            <tr>
                                <td><strong>Phone</strong></td>
                                <td><?php echo $d['phone']; ?></td>
                            </tr>
                            <tr>
                                <td><strong>Date Joined</strong></td>
                                <td><?php echo formatDate($d['date_joined']); ?></td>
                            </tr>
                            <tr class="table-primary">
                                <td><strong>Opening Balance</strong></td>
                                <td><?php echo formatCurrency($d['opening_balance']); ?></td>
                            </tr>
                            <?php if (isset($d['adjusted_opening'])): ?>
                                <tr>
                                    <td><strong>Adjusted Opening</strong></td>
                                    <td><?php echo formatCurrency($d['adjusted_opening']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Withdrawals</strong></td>
                                    <td><?php echo formatCurrency($d['total_withdrawals'] ?? 0); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Penalties</strong></td>
                                    <td><?php echo formatCurrency($d['total_penalties'] ?? 0); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Charges</strong></td>
                                    <td><?php echo formatCurrency($d['total_charges'] ?? 0); ?></td>
                                </tr>
                            <?php endif; ?>
                            <tr>
                                <td><strong>Total Deposits</strong></td>
                                <td><?php echo formatCurrency($d['total_deposits']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Interest Rate</strong></td>
                                <td><?php echo $d['interest_rate']; ?>%</td>
                            </tr>
                            <tr>
                                <td><strong>Gross Dividend</strong></td>
                                <td><?php echo formatCurrency($d['gross_dividend']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Withholding Tax (5%)</strong></td>
                                <td class="text-danger"><?php echo formatCurrency($d['withholding_tax']); ?></td>
                            </tr>
                            <tr class="table-success">
                                <td><strong>Net Dividend</strong></td>
                                <td><strong><?php echo formatCurrency($d['net_dividend']); ?></strong></td>
                            </tr>
                            <tr>
                                <td><strong>Calculation Method</strong></td>
                                <td>
                                    <?php if ($d['calculation_method'] == 'kenya_sacco_pro_rata'): ?>
                                        <span class="badge bg-success">Kenya Pro-rata</span>
                                    <?php else: ?>
                                        <span class="badge bg-info">Standard</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Eligibility Months</strong></td>
                                <td><?php echo $d['eligibility_months'] ?? 12; ?> months</td>
                            </tr>
                            <tr>
                                <td><strong>Status</strong></td>
                                <td>
                                    <?php if ($d['status'] == 'paid'): ?>
                                        <span class="badge bg-success">Paid</span>
                                    <?php elseif ($d['status'] == 'approved'): ?>
                                        <span class="badge bg-primary">Approved</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning">Calculated</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if ($d['status'] == 'paid'): ?>
                                <tr>
                                    <td><strong>Payment Date</strong></td>
                                    <td><?php echo formatDate($d['payment_date']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Payment Method</strong></td>
                                    <td><?php echo ucfirst($d['payment_method']); ?></td>
                                </tr>
                            <?php endif; ?>
                            <tr>
                                <td><strong>Calculated By</strong></td>
                                <td><?php echo $d['calculated_by_name'] ?? 'System'; ?> on <?php echo formatDate($d['calculated_at']); ?></td>
                            </tr>
                            <?php if ($d['contribution_months'] > 0): ?>
                                <tr>
                                    <td><strong>Contribution Months</strong></td>
                                    <td><?php echo $d['contribution_months']; ?> months (Weighted total: <?php echo formatCurrency($d['total_weighted']); ?>)</td>
                                </tr>
                            <?php endif; ?>

                        <?php else: ?>
                            <?php foreach ($report_data as $row): ?>
                                <tr>
                                    <?php if ($report_type == 'summary'): ?>
                                        <td><?php echo $row['financial_year']; ?></td>
                                        <td class="text-end"><?php echo number_format($row['total_members']); ?></td>
                                        <td class="text-end"><?php echo number_format($row['total_records']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($row['total_gross']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($row['total_tax']); ?></td>
                                        <td class="text-end text-success"><?php echo formatCurrency($row['total_net']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($row['avg_dividend']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($row['min_dividend']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($row['max_dividend']); ?></td>
                                        <td class="text-end"><?php echo $row['paid_count']; ?></td>
                                        <td class="text-end"><?php echo $row['pending_count']; ?></td>
                                        <td class="text-end"><?php echo $row['pro_rata_count']; ?></td>
                                        <td class="text-end"><?php echo $row['standard_count']; ?></td>

                                    <?php elseif ($report_type == 'member'): ?>
                                        <td><?php echo $row['member_no']; ?></td>
                                        <td>
                                            <a href="../members/view.php?id=<?php echo $row['member_id']; ?>">
                                                <?php echo $row['full_name']; ?>
                                            </a>
                                        </td>
                                        <td class="text-end"><?php echo formatCurrency($row['opening_balance']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($row['total_deposits']); ?></td>
                                        <td class="text-end"><?php echo $row['interest_rate']; ?>%</td>
                                        <td class="text-end"><?php echo formatCurrency($row['gross_dividend']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($row['withholding_tax']); ?></td>
                                        <td class="text-end text-success"><?php echo formatCurrency($row['net_dividend']); ?></td>
                                        <td>
                                            <?php if ($row['status'] == 'paid'): ?>
                                                <span class="badge bg-success">Paid</span>
                                            <?php elseif ($row['status'] == 'approved'): ?>
                                                <span class="badge bg-primary">Approved</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">Calculated</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($row['calculation_method'] == 'kenya_sacco_pro_rata'): ?>
                                                <span class="badge bg-success">Pro-rata</span>
                                            <?php else: ?>
                                                <span class="badge bg-info">Standard</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="voucher.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-info">
                                                <i class="fas fa-file-invoice"></i>
                                            </a>
                                        </td>

                                    <?php elseif ($report_type == 'yearly'): ?>
                                        <td><?php echo $row['financial_year']; ?></td>
                                        <td class="text-end"><?php echo number_format($row['members']); ?></td>
                                        <td class="text-end"><?php echo number_format($row['records']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($row['gross']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($row['tax']); ?></td>
                                        <td class="text-end text-success"><?php echo formatCurrency($row['net']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($row['average']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($row['pro_rata_total']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($row['standard_total']); ?></td>

                                    <?php elseif ($report_type == 'comparison'): ?>
                                        <td>
                                            <?php if ($row['calculation_method'] == 'kenya_sacco_pro_rata'): ?>
                                                <span class="badge bg-success">Pro-rata</span>
                                            <?php else: ?>
                                                <span class="badge bg-info">Standard</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end"><?php echo number_format($row['member_count']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($row['total_gross']); ?></td>
                                        <td class="text-end text-success"><?php echo formatCurrency($row['total_net']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($row['avg_net']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($row['min_net']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($row['max_net']); ?></td>

                                    <?php elseif ($report_type == 'payment'): ?>
                                        <td><?php echo formatDate($row['payment_date']); ?></td>
                                        <td>
                                            <a href="../members/view.php?id=<?php echo $row['member_id']; ?>">
                                                <?php echo $row['full_name']; ?><br>
                                                <small><?php echo $row['member_no']; ?></small>
                                            </a>
                                        </td>
                                        <td><?php echo $row['financial_year']; ?></td>
                                        <td class="text-end"><?php echo formatCurrency($row['net_dividend']); ?></td>
                                        <td class="text-end text-success"><?php echo formatCurrency($row['amount_paid']); ?></td>
                                        <td><?php echo ucfirst($row['payment_method']); ?></td>
                                        <td><?php echo $row['reference_no']; ?></td>
                                        <td><?php echo $row['receipt_no']; ?></td>
                                        <td><?php echo $row['processed_by_name'] ?? 'System'; ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>

                    <!-- Totals Row -->
                    <?php if ($report_type == 'member' && !empty($report_data)):
                        $total_gross = array_sum(array_column($report_data, 'gross_dividend'));
                        $total_tax = array_sum(array_column($report_data, 'withholding_tax'));
                        $total_net = array_sum(array_column($report_data, 'net_dividend'));
                    ?>
                        <tfoot>
                            <tr class="table-info fw-bold">
                                <td colspan="5" class="text-end">TOTALS:</td>
                                <td class="text-end"><?php echo formatCurrency($total_gross); ?></td>
                                <td class="text-end"><?php echo formatCurrency($total_tax); ?></td>
                                <td class="text-end text-success"><?php echo formatCurrency($total_net); ?></td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    function toggleMemberField() {
        var reportType = document.getElementById('report_type').value;
        var memberField = document.getElementById('member_field');

        if (reportType == 'single') {
            memberField.style.display = 'block';
        } else {
            memberField.style.display = 'none';
        }
    }

    function exportReport(format) {
        var report_type = document.getElementById('report_type').value;
        var year = document.getElementById('year').value;
        var member_id = document.getElementById('member_id')?.value || '';

        window.location.href = 'reports.php?export=' + format +
            '&report_type=' + report_type +
            '&year=' + year +
            '&member_id=' + member_id;
    }

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        toggleMemberField();
    });
</script>

<style>
    .table td {
        vertical-align: middle;
    }

    .table .text-end {
        font-family: 'Courier New', monospace;
    }

    .card-header {
        font-weight: 600;
    }

    .badge.bg-success {
        background-color: #28a745 !important;
    }

    .badge.bg-info {
        background-color: #17a2b8 !important;
    }

    .badge.bg-warning {
        background-color: #ffc107 !important;
        color: #333;
    }

    @media (max-width: 768px) {
        .table-responsive {
            font-size: 12px;
        }
    }
</style>

<?php include '../../includes/footer.php'; ?>
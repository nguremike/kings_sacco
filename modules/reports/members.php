<?php
// modules/reports/members.php
require_once '../../config/config.php';
requireLogin();

$page_title = 'Member Reports';

// Get filter parameters
$report_type = $_GET['report_type'] ?? 'summary';
$status = $_GET['status'] ?? 'all';
$date_from = $_GET['from'] ?? date('Y-m-d', strtotime('-1 year'));
$date_to = $_GET['to'] ?? date('Y-m-d');
$export = $_GET['export'] ?? '';

// Handle export
if ($export == 'excel' || $export == 'pdf') {
    exportMemberReport($report_type, $status, $date_from, $date_to, $export);
}

function exportMemberReport($type, $status, $from, $to, $format)
{
    // Get data based on report type
    $data = getMemberReportData($type, $status, $from, $to);

    if ($format == 'excel') {
        exportToExcel($data, $type);
    } elseif ($format == 'pdf') {
        exportToPDF($data, $type);
    }
}

function getMemberReportData($type, $status, $from, $to)
{
    $conn = getConnection();

    $status_condition = ($status != 'all') ? "AND m.membership_status = '$status'" : "";

    switch ($type) {
        case 'summary':
            $sql = "SELECT 
                    m.membership_status,
                    COUNT(*) as member_count,
                    SUM(CASE WHEN m.user_id IS NOT NULL THEN 1 ELSE 0 END) as with_account,
                    MIN(m.date_joined) as earliest_join,
                    MAX(m.date_joined) as latest_join,
                    (SELECT COUNT(*) FROM deposits WHERE member_id = m.id) as total_deposits,
                    (SELECT COUNT(*) FROM loans WHERE member_id = m.id) as total_loans,
                    (SELECT COUNT(*) FROM shares WHERE member_id = m.id) as total_shares
                    FROM members m
                    WHERE 1=1 $status_condition
                    GROUP BY m.membership_status
                    ORDER BY m.membership_status";
            break;

        case 'detailed':
            $sql = "SELECT 
                    m.member_no, m.full_name, m.national_id, m.phone, m.email,
                    m.membership_status, m.date_joined,
                    COALESCE((SELECT SUM(amount) FROM deposits WHERE member_id = m.id AND transaction_type = 'deposit'), 0) as total_deposits,
                    COALESCE((SELECT SUM(amount) FROM deposits WHERE member_id = m.id AND transaction_type = 'withdrawal'), 0) as total_withdrawals,
                    COALESCE((SELECT COUNT(*) FROM loans WHERE member_id = m.id), 0) as loan_count,
                    COALESCE((SELECT SUM(principal_amount) FROM loans WHERE member_id = m.id AND status IN ('active', 'disbursed')), 0) as active_loans,
                    COALESCE((SELECT SUM(shares_count) FROM shares WHERE member_id = m.id), 0) as total_shares,
                    COALESCE((SELECT SUM(total_value) FROM shares WHERE member_id = m.id), 0) as share_value,
                    CASE WHEN m.user_id IS NOT NULL THEN 'Yes' ELSE 'No' END as has_account
                    FROM members m
                    WHERE m.date_joined BETWEEN ? AND ? $status_condition
                    ORDER BY m.date_joined DESC";
            break;

        case 'demographics':
            $sql = "SELECT 
                    YEAR(date_joined) as join_year,
                    MONTH(date_joined) as join_month,
                    COUNT(*) as new_members,
                    SUM(CASE WHEN membership_status = 'active' THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN membership_status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN membership_status = 'suspended' THEN 1 ELSE 0 END) as suspended,
                    SUM(CASE WHEN membership_status = 'closed' THEN 1 ELSE 0 END) as closed
                    FROM members
                    WHERE date_joined BETWEEN ? AND ?
                    GROUP BY YEAR(date_joined), MONTH(date_joined)
                    ORDER BY join_year DESC, join_month DESC";
            break;

        case 'inactive':
            $sql = "SELECT 
                    m.member_no, m.full_name, m.phone, m.email, m.date_joined,
                    COALESCE(MAX(d.deposit_date), 'Never') as last_deposit,
                    COALESCE(MAX(l.created_at), 'Never') as last_loan,
                    DATEDIFF(NOW(), COALESCE(MAX(d.deposit_date), m.date_joined)) as days_inactive
                    FROM members m
                    LEFT JOIN deposits d ON m.id = d.member_id
                    LEFT JOIN loans l ON m.id = l.member_id
                    WHERE m.membership_status = 'active'
                    GROUP BY m.id
                    HAVING last_deposit = 'Never' OR last_deposit < DATE_SUB(NOW(), INTERVAL 6 MONTH)
                    ORDER BY days_inactive DESC";
            break;

        case 'growth':
            $sql = "SELECT 
                    DATE_FORMAT(date_joined, '%Y-%m') as month,
                    COUNT(*) as new_members,
                    SUM(CASE WHEN membership_status = 'active' THEN 1 ELSE 0 END) as active_members,
                    SUM(CASE WHEN membership_status = 'pending' THEN 1 ELSE 0 END) as pending_members
                    FROM members
                    WHERE date_joined BETWEEN ? AND ?
                    GROUP BY DATE_FORMAT(date_joined, '%Y-%m')
                    ORDER BY month ASC";
            break;

        default:
            $sql = "SELECT * FROM members WHERE 1=1 $status_condition LIMIT 100";
    }

    if (in_array($type, ['detailed', 'demographics', 'growth'])) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $from, $to);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($sql);
    }

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    $conn->close();
    return $data;
}

function exportToExcel($data, $report_type)
{
    require_once '../../vendor/autoload.php';

    $spreadsheet = new PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Set headers based on report type
    switch ($report_type) {
        case 'summary':
            $headers = ['Status', 'Count', 'With Account', 'Earliest Join', 'Latest Join', 'Total Deposits', 'Total Loans', 'Total Shares'];
            break;
        case 'detailed':
            $headers = [
                'Member No',
                'Full Name',
                'National ID',
                'Phone',
                'Email',
                'Status',
                'Date Joined',
                'Total Deposits',
                'Total Withdrawals',
                'Loan Count',
                'Active Loans',
                'Total Shares',
                'Share Value',
                'Has Account'
            ];
            break;
        case 'demographics':
            $headers = ['Year', 'Month', 'New Members', 'Active', 'Pending', 'Suspended', 'Closed'];
            break;
        case 'inactive':
            $headers = ['Member No', 'Full Name', 'Phone', 'Email', 'Date Joined', 'Last Deposit', 'Last Loan', 'Days Inactive'];
            break;
        case 'growth':
            $headers = ['Month', 'New Members', 'Active Members', 'Pending Members'];
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
            ->getStartColor()->setARGB('FF007BFF');
        $sheet->getStyle($col . '1')->getFont()->getColor()->setARGB('FFFFFFFF');
        $col++;
    }

    // Write data
    $row = 2;
    foreach ($data as $item) {
        $col = 'A';
        foreach ($item as $value) {
            $sheet->setCellValue($col . $row, $value);
            $col++;
        }
        $row++;
    }

    // Auto-size columns
    foreach (range('A', $col) as $columnID) {
        $sheet->getColumnDimension($columnID)->setAutoSize(true);
    }

    // Set filename
    $filename = 'member_report_' . $report_type . '_' . date('Ymd') . '.xlsx';

    // Output
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit();
}

function exportToPDF($data, $report_type)
{
    require_once '../../vendor/autoload.php';

    $pdf = new \Dompdf\Dompdf();

    // Build HTML table
    $html = '<html><head><style>
        body { font-family: Arial, sans-serif; }
        h2 { color: #007bff; text-align: center; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { background: #007bff; color: white; padding: 10px; text-align: left; }
        td { padding: 8px; border: 1px solid #ddd; }
        tr:nth-child(even) { background: #f9f9f9; }
        .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #666; }
    </style></head><body>';

    $html .= '<h2>Member Report - ' . ucfirst($report_type) . '</h2>';
    $html .= '<p>Generated on: ' . date('d M Y H:i:s') . '</p>';

    $html .= '<table>';

    // Headers
    $html .= '<tr>';
    switch ($report_type) {
        case 'summary':
            $html .= '<th>Status</th><th>Count</th><th>With Account</th><th>Earliest Join</th><th>Latest Join</th><th>Total Deposits</th><th>Total Loans</th><th>Total Shares</th>';
            break;
        case 'detailed':
            $html .= '<th>Member No</th><th>Name</th><th>Phone</th><th>Status</th><th>Date Joined</th><th>Total Deposits</th><th>Loan Count</th><th>Total Shares</th>';
            break;
        case 'demographics':
            $html .= '<th>Year</th><th>Month</th><th>New Members</th><th>Active</th><th>Pending</th><th>Suspended</th><th>Closed</th>';
            break;
        case 'inactive':
            $html .= '<th>Member No</th><th>Name</th><th>Phone</th><th>Date Joined</th><th>Last Deposit</th><th>Days Inactive</th>';
            break;
        case 'growth':
            $html .= '<th>Month</th><th>New Members</th><th>Active</th><th>Pending</th>';
            break;
    }
    $html .= '</tr>';

    // Data rows
    foreach ($data as $row) {
        $html .= '<tr>';
        foreach ($row as $value) {
            $html .= '<td>' . $value . '</td>';
        }
        $html .= '</tr>';
    }

    $html .= '</table>';
    $html .= '<div class="footer">Generated by ' . APP_NAME . ' | ' . date('Y') . '</div>';
    $html .= '</body></html>';

    $pdf->loadHtml($html);
    $pdf->setPaper('A4', 'landscape');
    $pdf->render();

    $filename = 'member_report_' . $report_type . '_' . date('Ymd') . '.pdf';
    $pdf->stream($filename, ['Attachment' => true]);
    exit();
}

// Get report data for display
$report_data = getMemberReportData($report_type, $status, $date_from, $date_to);

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Member Reports</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="../reports/index.php">Reports</a></li>
                <li class="breadcrumb-item active">Member Reports</li>
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
    <div class="card-header bg-primary text-white">
        <h5 class="card-title mb-0"><i class="fas fa-filter"></i> Report Filters</h5>
    </div>
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-3">
                <label for="report_type" class="form-label">Report Type</label>
                <select class="form-control" id="report_type" name="report_type" onchange="this.form.submit()">
                    <option value="summary" <?php echo $report_type == 'summary' ? 'selected' : ''; ?>>Summary by Status</option>
                    <option value="detailed" <?php echo $report_type == 'detailed' ? 'selected' : ''; ?>>Detailed Member List</option>
                    <option value="demographics" <?php echo $report_type == 'demographics' ? 'selected' : ''; ?>>Demographics</option>
                    <option value="inactive" <?php echo $report_type == 'inactive' ? 'selected' : ''; ?>>Inactive Members</option>
                    <option value="growth" <?php echo $report_type == 'growth' ? 'selected' : ''; ?>>Membership Growth</option>
                </select>
            </div>

            <div class="col-md-2">
                <label for="status" class="form-label">Member Status</label>
                <select class="form-control" id="status" name="status">
                    <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>All Status</option>
                    <option value="active" <?php echo $status == 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="pending" <?php echo $status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="suspended" <?php echo $status == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                    <option value="closed" <?php echo $status == 'closed' ? 'selected' : ''; ?>>Closed</option>
                </select>
            </div>

            <?php if (in_array($report_type, ['detailed', 'demographics', 'growth'])): ?>
                <div class="col-md-2">
                    <label for="from" class="form-label">From Date</label>
                    <input type="date" class="form-control" id="from" name="from" value="<?php echo $date_from; ?>">
                </div>

                <div class="col-md-2">
                    <label for="to" class="form-label">To Date</label>
                    <input type="date" class="form-control" id="to" name="to" value="<?php echo $date_to; ?>">
                </div>
            <?php endif; ?>

            <div class="col-md-<?php echo in_array($report_type, ['detailed', 'demographics', 'growth']) ? '3' : '7'; ?>">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary d-block w-100">
                    <i class="fas fa-search"></i> Generate Report
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
                'summary' => 'Member Summary by Status',
                'detailed' => 'Detailed Member List',
                'demographics' => 'Member Demographics',
                'inactive' => 'Inactive Members (6+ months)',
                'growth' => 'Membership Growth Trend'
            ];
            echo $titles[$report_type] ?? 'Member Report';
            ?>
        </h5>
        <div class="card-tools">
            <span class="badge bg-info">Total Records: <?php echo count($report_data); ?></span>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-striped datatable">
                <thead>
                    <tr>
                        <?php if ($report_type == 'summary'): ?>
                            <th>Membership Status</th>
                            <th>Total Members</th>
                            <th>With User Account</th>
                            <th>Earliest Join Date</th>
                            <th>Latest Join Date</th>
                            <th>Total Deposits</th>
                            <th>Total Loans</th>
                            <th>Total Shares</th>
                        <?php elseif ($report_type == 'detailed'): ?>
                            <th>Member No</th>
                            <th>Full Name</th>
                            <th>National ID</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Date Joined</th>
                            <th>Total Deposits</th>
                            <th>Total Withdrawals</th>
                            <th>Net Savings</th>
                            <th>Loan Count</th>
                            <th>Active Loans</th>
                            <th>Total Shares</th>
                            <th>Share Value</th>
                            <th>Has Account</th>
                        <?php elseif ($report_type == 'demographics'): ?>
                            <th>Year</th>
                            <th>Month</th>
                            <th>New Members</th>
                            <th>Active</th>
                            <th>Pending</th>
                            <th>Suspended</th>
                            <th>Closed</th>
                            <th>Retention Rate</th>
                        <?php elseif ($report_type == 'inactive'): ?>
                            <th>Member No</th>
                            <th>Full Name</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Date Joined</th>
                            <th>Last Deposit</th>
                            <th>Last Loan</th>
                            <th>Days Inactive</th>
                            <th>Status</th>
                            <th>Action</th>
                        <?php elseif ($report_type == 'growth'): ?>
                            <th>Month</th>
                            <th>New Members</th>
                            <th>Active Members</th>
                            <th>Pending Members</th>
                            <th>Growth Rate</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($report_type == 'summary'): ?>
                        <?php
                        $total_members = 0;
                        $total_with_account = 0;
                        foreach ($report_data as $row):
                            $total_members += $row['member_count'];
                            $total_with_account += $row['with_account'];
                        ?>
                            <tr>
                                <td>
                                    <span class="badge bg-<?php
                                                            echo $row['membership_status'] == 'active' ? 'success' : ($row['membership_status'] == 'pending' ? 'warning' : ($row['membership_status'] == 'suspended' ? 'danger' : 'secondary'));
                                                            ?>">
                                        <?php echo ucfirst($row['membership_status']); ?>
                                    </span>
                                </td>
                                <td class="text-end"><?php echo number_format($row['member_count']); ?></td>
                                <td class="text-end"><?php echo number_format($row['with_account']); ?></td>
                                <td><?php echo formatDate($row['earliest_join']); ?></td>
                                <td><?php echo formatDate($row['latest_join']); ?></td>
                                <td class="text-end"><?php echo number_format($row['total_deposits']); ?></td>
                                <td class="text-end"><?php echo number_format($row['total_loans']); ?></td>
                                <td class="text-end"><?php echo number_format($row['total_shares']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="table-info fw-bold">
                            <td>TOTAL</td>
                            <td class="text-end"><?php echo number_format($total_members); ?></td>
                            <td class="text-end"><?php echo number_format($total_with_account); ?></td>
                            <td colspan="5"></td>
                        </tr>

                    <?php elseif ($report_type == 'detailed'): ?>
                        <?php foreach ($report_data as $row):
                            $net_savings = $row['total_deposits'] - $row['total_withdrawals'];
                        ?>
                            <tr>
                                <td><?php echo $row['member_no']; ?></td>
                                <td>
                                    <a href="../members/view.php?id=<?php echo $row['id'] ?? 0; ?>">
                                        <?php echo $row['full_name']; ?>
                                    </a>
                                </td>
                                <td><?php echo $row['national_id']; ?></td>
                                <td><?php echo $row['phone']; ?></td>
                                <td><?php echo $row['email'] ?: '-'; ?></td>
                                <td>
                                    <span class="badge bg-<?php
                                                            echo $row['membership_status'] == 'active' ? 'success' : ($row['membership_status'] == 'pending' ? 'warning' : ($row['membership_status'] == 'suspended' ? 'danger' : 'secondary'));
                                                            ?>">
                                        <?php echo ucfirst($row['membership_status']); ?>
                                    </span>
                                </td>
                                <td><?php echo formatDate($row['date_joined']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['total_deposits']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['total_withdrawals']); ?></td>
                                <td class="text-end <?php echo $net_savings >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo formatCurrency($net_savings); ?>
                                </td>
                                <td class="text-end"><?php echo $row['loan_count']; ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['active_loans']); ?></td>
                                <td class="text-end"><?php echo $row['total_shares']; ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['share_value']); ?></td>
                                <td class="text-center">
                                    <?php if ($row['has_account'] == 'Yes'): ?>
                                        <span class="badge bg-success">Yes</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">No</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                    <?php elseif ($report_type == 'demographics'): ?>
                        <?php foreach ($report_data as $row):
                            $retention_rate = $row['new_members'] > 0 ?
                                ($row['active'] / $row['new_members']) * 100 : 0;
                        ?>
                            <tr>
                                <td><?php echo $row['join_year']; ?></td>
                                <td><?php echo date('F', mktime(0, 0, 0, $row['join_month'], 1)); ?></td>
                                <td class="text-end"><?php echo $row['new_members']; ?></td>
                                <td class="text-end"><?php echo $row['active']; ?></td>
                                <td class="text-end"><?php echo $row['pending']; ?></td>
                                <td class="text-end"><?php echo $row['suspended']; ?></td>
                                <td class="text-end"><?php echo $row['closed']; ?></td>
                                <td class="text-end">
                                    <div class="progress" style="height: 20px;">
                                        <div class="progress-bar bg-success" role="progressbar"
                                            style="width: <?php echo $retention_rate; ?>%;"
                                            aria-valuenow="<?php echo $retention_rate; ?>"
                                            aria-valuemin="0"
                                            aria-valuemax="100">
                                            <?php echo number_format($retention_rate, 1); ?>%
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                    <?php elseif ($report_type == 'inactive'): ?>
                        <?php foreach ($report_data as $row):
                            $inactive_class = $row['days_inactive'] > 365 ? 'danger' : ($row['days_inactive'] > 180 ? 'warning' : 'info');
                        ?>
                            <tr class="table-<?php echo $inactive_class; ?>">
                                <td><?php echo $row['member_no']; ?></td>
                                <td>
                                    <a href="../members/view.php?id=<?php echo $row['id'] ?? 0; ?>">
                                        <?php echo $row['full_name']; ?>
                                    </a>
                                </td>
                                <td><?php echo $row['phone']; ?></td>
                                <td><?php echo $row['email'] ?: '-'; ?></td>
                                <td><?php echo formatDate($row['date_joined']); ?></td>
                                <td><?php echo $row['last_deposit'] != 'Never' ? formatDate($row['last_deposit']) : 'Never'; ?></td>
                                <td><?php echo $row['last_loan'] != 'Never' ? formatDate($row['last_loan']) : 'Never'; ?></td>
                                <td class="text-end">
                                    <span class="badge bg-<?php echo $inactive_class; ?>">
                                        <?php echo number_format($row['days_inactive']); ?> days
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $row['membership_status'] ?? 'secondary'; ?>">
                                        <?php echo ucfirst($row['membership_status'] ?? 'active'); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="../members/view.php?id=<?php echo $row['id'] ?? 0; ?>" class="btn btn-sm btn-info">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="../members/edit.php?id=<?php echo $row['id'] ?? 0; ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                    <?php elseif ($report_type == 'growth'): ?>
                        <?php
                        $previous_count = 0;
                        foreach ($report_data as $row):
                            $growth_rate = $previous_count > 0 ?
                                (($row['new_members'] - $previous_count) / $previous_count) * 100 : 0;
                        ?>
                            <tr>
                                <td><?php echo $row['month']; ?></td>
                                <td class="text-end"><?php echo $row['new_members']; ?></td>
                                <td class="text-end"><?php echo $row['active_members']; ?></td>
                                <td class="text-end"><?php echo $row['pending_members']; ?></td>
                                <td class="text-end">
                                    <span class="badge bg-<?php echo $growth_rate >= 0 ? 'success' : 'danger'; ?>">
                                        <?php echo number_format($growth_rate, 1); ?>%
                                    </span>
                                </td>
                            </tr>
                        <?php
                            $previous_count = $row['new_members'];
                        endforeach; ?>

                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Summary Cards for Dashboard View -->
<?php if ($report_type == 'summary'): ?>
    <div class="row mt-4">
        <div class="col-md-3">
            <div class="stats-card success">
                <div class="stats-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stats-content">
                    <?php
                    $active_count = executeQuery("SELECT COUNT(*) as count FROM members WHERE membership_status = 'active'")->fetch_assoc()['count'];
                    ?>
                    <h3><?php echo number_format($active_count); ?></h3>
                    <p>Active Members</p>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stats-card warning">
                <div class="stats-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stats-content">
                    <?php
                    $pending_count = executeQuery("SELECT COUNT(*) as count FROM members WHERE membership_status = 'pending'")->fetch_assoc()['count'];
                    ?>
                    <h3><?php echo number_format($pending_count); ?></h3>
                    <p>Pending Approval</p>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stats-card primary">
                <div class="stats-icon">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stats-content">
                    <?php
                    $with_account = executeQuery("SELECT COUNT(*) as count FROM members WHERE user_id IS NOT NULL")->fetch_assoc()['count'];
                    ?>
                    <h3><?php echo number_format($with_account); ?></h3>
                    <p>With User Account</p>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stats-card info">
                <div class="stats-icon">
                    <i class="fas fa-calendar"></i>
                </div>
                <div class="stats-content">
                    <?php
                    $this_month = executeQuery("SELECT COUNT(*) as count FROM members WHERE MONTH(date_joined) = MONTH(NOW()) AND YEAR(date_joined) = YEAR(NOW())")->fetch_assoc()['count'];
                    ?>
                    <h3><?php echo number_format($this_month); ?></h3>
                    <p>Joined This Month</p>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
    function exportReport(format) {
        var report_type = document.getElementById('report_type').value;
        var status = document.getElementById('status').value;
        var from = document.getElementById('from')?.value || '';
        var to = document.getElementById('to')?.value || '';

        window.location.href = 'members.php?export=' + format +
            '&report_type=' + report_type +
            '&status=' + status +
            '&from=' + from +
            '&to=' + to;
    }
</script>

<style>
    .stats-card {
        margin-bottom: 15px;
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

    .table-info {
        background-color: #e3f2fd !important;
    }

    @media (max-width: 768px) {
        .table-responsive {
            font-size: 12px;
        }

        .btn-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .btn-group .btn {
            margin: 0;
        }
    }
</style>

<?php include '../../includes/footer.php'; ?>
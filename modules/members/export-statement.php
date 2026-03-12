<?php
require_once '../../config/config.php';
require_once '../../config/pdf_excel_config.php';
requireLogin();

$member_id = $_GET['id'] ?? 0;
$date_from = $_GET['from'] ?? date('Y-m-d', strtotime('-1 year'));
$date_to = $_GET['to'] ?? date('Y-m-d');
$format = $_GET['format'] ?? 'excel'; // excel, csv, or pdf

// Get member details (same as before)
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

// Get all the data summaries (same as before)
// ... [keep all the SQL queries from the original export-statement.php] ...

if ($format == 'pdf') {
    // Generate PDF
    $pdf = new PDFGenerator();

    // Start output buffering for PDF view
    ob_start();
    include 'print-statement.php';
    $html = ob_get_clean();

    $filename = "statement_{$member['member_no']}_" . date('Ymd');
    $pdf->generateFromHtml($html, $filename . '.pdf', 'A4', 'portrait', true);
} else {
    // Generate Excel
    $excel = new ExcelGenerator();

    // Set properties
    $excel->setProperties(
        "Member Statement - {$member['full_name']}",
        "Statement from $date_from to $date_to",
        APP_NAME,
        "Member statement for {$member['full_name']}"
    );

    // Add title
    $excel->addTitle(APP_NAME . " - Member Statement", 1, 'A', 'G');
    $excel->addSubtitle("Period: " . formatDate($date_from) . " to " . formatDate($date_to), 2, 'A', 'G');

    $row = 4;

    // Member Information
    $excel->mergeCells('A' . $row . ':G' . $row);
    $excel->getSheet()->setCellValue('A' . $row, 'MEMBER INFORMATION');
    $excel->getSheet()->getStyle('A' . $row)->getFont()->setBold(true);
    $row++;

    $memberData = [
        ['Member No:', $member['member_no'], 'Full Name:', $member['full_name']],
        ['National ID:', $member['national_id'], 'Phone:', $member['phone']],
        ['Date Joined:', formatDate($member['date_joined']), 'Status:', ucfirst($member['membership_status'])]
    ];

    foreach ($memberData as $dataRow) {
        $col = 'A';
        foreach ($dataRow as $cell) {
            $excel->getSheet()->setCellValue($col . $row, $cell);
            if (strpos($cell, ':') !== false) {
                $excel->getSheet()->getStyle($col . $row)->getFont()->setBold(true);
            }
            $col++;
        }
        $row++;
    }

    $row += 2;

    // Continue with the rest of the Excel generation...
    // [Add all the summary sections and transaction table]

    // Auto-size columns
    $excel->autoSizeColumns('A', 'G');

    // Export based on format
    $filename = "statement_{$member['member_no']}_" . date('Ymd');
    if ($format == 'csv') {
        $excel->exportCsv($filename);
    } else {
        $excel->exportExcel($filename);
    }
}

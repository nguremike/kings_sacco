<?php

/**
 * PDF and Excel Configuration File
 * This file handles the setup and configuration of Dompdf and PhpSpreadsheet
 */

// Autoload Composer dependencies
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * PDF Generator Class
 * Handles PDF generation using Dompdf
 */
class PDFGenerator
{

    private $dompdf;
    private $options;

    public function __construct()
    {
        // Configure Dompdf options
        $this->options = new Options();
        $this->options->set('isRemoteEnabled', true);
        $this->options->set('isHtml5ParserEnabled', true);
        $this->options->set('isPhpEnabled', false);
        $this->options->set('defaultFont', 'Helvetica');
        $this->options->set('defaultPaperSize', 'A4');
        $this->options->set('defaultPaperOrientation', 'portrait');
        $this->options->set('dpi', 150);
        $this->options->set('fontHeightRatio', 1.1);
        $this->options->set('isJavascriptEnabled', false);

        // Set temp directory (make sure this folder exists and is writable)
        $tempDir = __DIR__ . '/../temp';
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0777, true);
        }
        $this->options->set('tempDir', $tempDir);

        // Set font directory
        $fontDir = __DIR__ . '/../fonts';
        if (!file_exists($fontDir)) {
            mkdir($fontDir, 0777, true);
        }
        $this->options->set('fontDir', $fontDir);
        $this->options->set('fontCache', $fontDir);

        // Initialize Dompdf
        $this->dompdf = new Dompdf($this->options);
    }

    /**
     * Generate PDF from HTML string
     */
    public function generateFromHtml($html, $filename = 'document.pdf', $paperSize = 'A4', $orientation = 'portrait', $download = true)
    {
        try {
            // Add UTF-8 meta tag if not present
            if (strpos($html, '<meta charset=') === false) {
                $html = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html;
            }

            // Load HTML
            $this->dompdf->loadHtml($html);

            // Set paper size and orientation
            $this->dompdf->setPaper($paperSize, $orientation);

            // Render PDF
            $this->dompdf->render();

            // Output PDF
            if ($download) {
                $this->dompdf->stream($filename, ['Attachment' => 1]);
            } else {
                $this->dompdf->stream($filename, ['Attachment' => 0]);
            }

            return true;
        } catch (Exception $e) {
            error_log("PDF Generation Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate PDF from a view file
     */
    public function generateFromView($viewPath, $data = [], $filename = 'document.pdf', $paperSize = 'A4', $orientation = 'portrait', $download = true)
    {
        try {
            // Extract data variables
            extract($data);

            // Start output buffering
            ob_start();

            // Include the view file
            include $viewPath;

            // Get the HTML content
            $html = ob_get_clean();

            return $this->generateFromHtml($html, $filename, $paperSize, $orientation, $download);
        } catch (Exception $e) {
            error_log("PDF View Generation Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Save PDF to server
     */
    public function saveToFile($html, $filepath)
    {
        try {
            $this->dompdf->loadHtml($html);
            $this->dompdf->render();
            file_put_contents($filepath, $this->dompdf->output());
            return true;
        } catch (Exception $e) {
            error_log("PDF Save Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get PDF as string
     */
    public function getPdfString($html)
    {
        try {
            $this->dompdf->loadHtml($html);
            $this->dompdf->render();
            return $this->dompdf->output();
        } catch (Exception $e) {
            error_log("PDF String Generation Error: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Excel Generator Class
 * Handles Excel file generation using PhpSpreadsheet
 */
class ExcelGenerator
{

    private $spreadsheet;
    private $sheet;

    public function __construct()
    {
        $this->spreadsheet = new Spreadsheet();
        $this->sheet = $this->spreadsheet->getActiveSheet();
    }

    /**
     * Set document properties
     */
    public function setProperties($title, $subject = '', $creator = 'SACCO System', $description = '')
    {
        $this->spreadsheet->getProperties()
            ->setCreator($creator)
            ->setLastModifiedBy($creator)
            ->setTitle($title)
            ->setSubject($subject)
            ->setDescription($description)
            ->setKeywords("sacco excel export")
            ->setCategory("Reports");
    }

    /**
     * Set headers for table
     */
    public function setHeaders($headers, $startRow = 1, $startCol = 'A')
    {
        $col = $startCol;
        foreach ($headers as $header) {
            $this->sheet->setCellValue($col . $startRow, $header);

            // Style the header
            $this->sheet->getStyle($col . $startRow)->applyFromArray([
                'font' => [
                    'bold' => true,
                    'size' => 12,
                    'color' => ['rgb' => 'FFFFFF']
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '007BFF']
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => '000000']
                    ]
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER
                ]
            ]);

            $col++;
        }
    }

    /**
     * Add data rows
     */
    public function addData($data, $startRow = 2, $startCol = 'A')
    {
        $row = $startRow;

        foreach ($data as $rowData) {
            $col = $startCol;
            foreach ($rowData as $value) {
                $this->sheet->setCellValue($col . $row, $value);
                $col++;
            }
            $row++;
        }

        // Apply borders to all data cells
        $lastRow = $row - 1;
        $lastCol = chr(ord($startCol) + count($data[0] ?? []) - 1);
        $this->sheet->getStyle($startCol . $startRow . ':' . $lastCol . $lastRow)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'CCCCCC']
                ]
            ]
        ]);
    }

    /**
     * Add summary row with totals
     */
    public function addSummaryRow($labels, $values, $row, $startCol = 'A')
    {
        $col = $startCol;
        foreach ($labels as $label) {
            $this->sheet->setCellValue($col . $row, $label);
            $this->sheet->getStyle($col . $row)->getFont()->setBold(true);
            $col++;
        }

        foreach ($values as $value) {
            $this->sheet->setCellValue($col . $row, $value);
            $this->sheet->getStyle($col . $row)->getFont()->setBold(true);
            $col++;
        }

        // Style summary row
        $this->sheet->getStyle($startCol . $row . ':' . ($col - 1) . $row)->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E0E0E0']
            ],
            'borders' => [
                'top' => [
                    'borderStyle' => Border::BORDER_MEDIUM,
                    'color' => ['rgb' => '000000']
                ]
            ]
        ]);
    }

    /**
     * Auto-size columns
     */
    public function autoSizeColumns($startCol = 'A', $endCol = 'Z')
    {
        foreach (range($startCol, $endCol) as $col) {
            $this->sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /**
     * Set column widths manually
     */
    public function setColumnWidths($widths)
    {
        foreach ($widths as $col => $width) {
            $this->sheet->getColumnDimension($col)->setWidth($width);
        }
    }

    /**
     * Set number format for a range
     */
    public function setNumberFormat($range, $format = NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1)
    {
        $this->sheet->getStyle($range)->getNumberFormat()->setFormatCode($format);
    }

    /**
     * Set currency format for a range
     */
    public function setCurrencyFormat($range)
    {
        $this->sheet->getStyle($range)->getNumberFormat()->setFormatCode('KES #,##0.00');
    }

    /**
     * Set date format for a range
     */
    public function setDateFormat($range, $format = 'dd/mm/yyyy')
    {
        $this->sheet->getStyle($range)->getNumberFormat()->setFormatCode($format);
    }

    /**
     * Merge cells
     */
    public function mergeCells($range)
    {
        $this->sheet->mergeCells($range);
    }

    /**
     * Add title to worksheet
     */
    public function addTitle($title, $row = 1, $col = 'A', $mergeThrough = 'Z')
    {
        $this->mergeCells($col . $row . ':' . $mergeThrough . $row);
        $this->sheet->setCellValue($col . $row, $title);
        $this->sheet->getStyle($col . $row)->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 16,
                'color' => ['rgb' => '007BFF']
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER
            ]
        ]);
        $this->sheet->getRowDimension($row)->setRowHeight(30);
    }

    /**
     * Add subtitle/date range
     */
    public function addSubtitle($subtitle, $row = 2, $col = 'A', $mergeThrough = 'Z')
    {
        $this->mergeCells($col . $row . ':' . $mergeThrough . $row);
        $this->sheet->setCellValue($col . $row, $subtitle);
        $this->sheet->getStyle($col . $row)->getFont()->setItalic(true);
        $this->sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    /**
     * Export as Excel file
     */
    public function exportExcel($filename)
    {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '.xlsx"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($this->spreadsheet);
        $writer->save('php://output');
        exit;
    }

    /**
     * Export as CSV file
     */
    public function exportCsv($filename)
    {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment;filename="' . $filename . '.csv"');
        header('Cache-Control: max-age=0');

        $writer = new Csv($this->spreadsheet);
        $writer->save('php://output');
        exit;
    }

    /**
     * Save to server
     */
    public function saveToFile($filepath)
    {
        $writer = new Xlsx($this->spreadsheet);
        $writer->save($filepath);
    }

    /**
     * Get spreadsheet object for custom operations
     */
    public function getSpreadsheet()
    {
        return $this->spreadsheet;
    }

    /**
     * Get active sheet
     */
    public function getSheet()
    {
        return $this->sheet;
    }
}

/**
 * Helper function for formatting currency in PDFs
 */
function formatCurrencyPDF($amount)
{
    return 'KES ' . number_format($amount, 2);
}

/**
 * Helper function for formatting dates in PDFs
 */
function formatDatePDF($date)
{
    return date('d M Y', strtotime($date));
}

/**
 * Helper function for creating page breaks in PDFs
 */
function pageBreak()
{
    return '<div style="page-break-after: always;"></div>';
}

/**
 * Helper function for PDF styles
 */
function getPDFStyles()
{
    return '
        <style>
            body {
                font-family: Helvetica, Arial, sans-serif;
                font-size: 12px;
                line-height: 1.4;
                color: #333;
            }
            .header {
                text-align: center;
                margin-bottom: 20px;
                padding-bottom: 10px;
                border-bottom: 2px solid #007bff;
            }
            .header h1 {
                color: #007bff;
                margin: 0 0 5px;
                font-size: 24px;
            }
            .header h3 {
                margin: 0 0 5px;
                font-size: 18px;
            }
            .header p {
                margin: 0;
                color: #666;
                font-size: 11px;
            }
            .info-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 20px;
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
                margin: 0 0 10px;
                padding-bottom: 8px;
                border-bottom: 2px solid #007bff;
                color: #007bff;
            }
            .summary-row {
                display: flex;
                justify-content: space-between;
                margin-bottom: 8px;
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
            table.data-table {
                width: 100%;
                border-collapse: collapse;
                margin: 20px 0;
                font-size: 11px;
            }
            table.data-table th {
                background: #007bff;
                color: white;
                padding: 8px;
                text-align: left;
                font-weight: bold;
            }
            table.data-table td {
                padding: 6px 8px;
                border: 1px solid #ddd;
            }
            table.data-table tr:nth-child(even) {
                background: #f8f9fa;
            }
            .footer {
                margin-top: 30px;
                padding-top: 15px;
                border-top: 1px solid #ddd;
                text-align: center;
                font-size: 10px;
                color: #666;
            }
            .text-success { color: #28a745; }
            .text-danger { color: #dc3545; }
            .text-primary { color: #007bff; }
            .text-right { text-align: right; }
            .text-center { text-align: center; }
            .font-bold { font-weight: bold; }
            .badge-success {
                background: #28a745;
                color: white;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 10px;
            }
            .badge-danger {
                background: #dc3545;
                color: white;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 10px;
            }
            .badge-info {
                background: #17a2b8;
                color: white;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 10px;
            }
            .badge-warning {
                background: #ffc107;
                color: #333;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 10px;
            }
            .progress {
                background: #e9ecef;
                height: 10px;
                border-radius: 5px;
                margin: 10px 0;
            }
            .progress-bar {
                background: #28a745;
                height: 10px;
                border-radius: 5px;
            }
        </style>
    ';
}

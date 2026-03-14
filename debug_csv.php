<?php
// debug_csv.php
require_once '../../config/config.php';
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['test_file'])) {
    echo "<h2>CSV Debug Results</h2>";

    $file = fopen($_FILES['test_file']['tmp_name'], 'r');

    echo "<h3>File Information:</h3>";
    echo "Filename: " . $_FILES['test_file']['name'] . "<br>";
    echo "File size: " . $_FILES['test_file']['size'] . " bytes<br>";
    echo "File type: " . $_FILES['test_file']['type'] . "<br>";

    echo "<h3>Headers (Row 1):</h3>";
    $headers = fgetcsv($file);
    echo "<pre>";
    print_r($headers);
    echo "</pre>";

    echo "<h3>Cleaned Headers:</h3>";
    $cleaned = array_map(function ($h) {
        $h = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $h);
        $h = str_replace('"', '', $h);
        $h = trim($h);
        $h = strtolower($h);
        return $h;
    }, $headers);
    echo "<pre>";
    print_r($cleaned);
    echo "</pre>";

    echo "<h3>Data Rows:</h3>";
    $row_num = 2;
    while (($row = fgetcsv($file)) !== false) {
        echo "<strong>Row $row_num:</strong><br>";
        echo "<pre>";
        print_r($row);
        echo "</pre>";

        // Show cleaned row
        $row_cleaned = array_map('trim', $row);
        echo "<strong>Cleaned Row $row_num:</strong><br>";
        echo "<pre>";
        print_r($row_cleaned);
        echo "</pre>";

        // Show mapped data if column count matches
        if (count($row_cleaned) == count($cleaned)) {
            $data = array_combine($cleaned, $row_cleaned);
            echo "<strong>Mapped Data $row_num:</strong><br>";
            echo "<pre>";
            print_r($data);
            echo "</pre>";

            // Check required fields
            echo "<strong>Validation:</strong><br>";
            $missing = [];
            if (empty($data['full_name'])) $missing[] = 'full_name';
            if (empty($data['national_id'])) $missing[] = 'national_id';
            if (empty($data['phone'])) $missing[] = 'phone';

            if (empty($missing)) {
                echo "✅ All required fields present<br>";
            } else {
                echo "❌ Missing: " . implode(', ', $missing) . "<br>";
            }
        } else {
            echo "❌ Column count mismatch! Headers: " . count($cleaned) . ", Row: " . count($row_cleaned) . "<br>";
        }

        echo "<hr>";
        $row_num++;
    }

    fclose($file);
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>CSV Debug Tool</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>
    <div class="container mt-5">
        <h1>CSV Debug Tool</h1>
        <p>Upload your CSV file to debug parsing issues</p>

        <form method="POST" enctype="multipart/form-data">
            <div class="mb-3">
                <label for="test_file" class="form-label">Select CSV File</label>
                <input type="file" class="form-control" id="test_file" name="test_file" accept=".csv" required>
            </div>
            <button type="submit" class="btn btn-primary">Debug CSV</button>
        </form>

        <div class="mt-4">
            <h4>Common Issues:</h4>
            <ul>
                <li><strong>BOM (Byte Order Mark)</strong> - UTF-8 files may have hidden characters at the beginning</li>
                <li><strong>Quotes in headers</strong> - Headers might be quoted like "full_name"</li>
                <li><strong>Extra spaces</strong> - Headers might have trailing spaces</li>
                <li><strong>Column count mismatch</strong> - Some rows may have different number of columns</li>
                <li><strong>Empty rows</strong> - Blank lines in the CSV</li>
            </ul>
        </div>
    </div>
</body>

</html>
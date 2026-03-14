<?php
// modules/deposits/templates/monthly-contributions-template.php
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="monthly-contributions-template.xls"');

$year = date('Y');
?>

<html>

<head>
    <title>Monthly Contributions Template</title>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
</head>

<body>
    <table border="1">
        <thead>
            <tr>
                <th>M/NO</th>
                <th>Names</th>
                <th>Jan</th>
                <th>Feb</th>
                <th>Mar</th>
                <th>Apr</th>
                <th>May</th>
                <th>Jun</th>
                <th>Jul</th>
                <th>Aug</th>
                <th>Sep</th>
                <th>Oct</th>
                <th>Nov</th>
                <th>Dec</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>1</td>
                <td>CHARLES G. NDERITU</td>
                <td>0</td>
                <td></td>
                <td>8655</td>
                <td></td>
                <td></td>
                <td>55000</td>
                <td></td>
                <td></td>
                <td>17000</td>
                <td>2000</td>
                <td></td>
                <td></td>
            </tr>
            <tr>
                <td>2</td>
                <td>MARAGARA GACHIE</td>
                <td>1000</td>
                <td>1000</td>
                <td>1000</td>
                <td>1000</td>
                <td>1000</td>
                <td>1000</td>
                <td>1000</td>
                <td>1000</td>
                <td>1000</td>
                <td>1000</td>
                <td>1000</td>
                <td>1000</td>
            </tr>
            <tr>
                <td colspan="14" style="background-color: #f0f0f0;">
                    <strong>Instructions:</strong>
                    - Fill in member numbers as they appear in the system
                    - Leave empty cells for months with no contribution
                    - Use numbers only (no currency symbols)
                    - Decimals are allowed (e.g., 1000.50)
                </td>
            </tr>
        </tbody>
    </table>
</body>

</html>
<?php
require_once '../../config/config.php';
requireLogin();

$id = $_GET['id'] ?? 0;

// Get share details
$sql = "SELECT s.*, m.full_name, m.member_no, m.national_id, u.full_name as issued_by_name
        FROM shares s
        JOIN members m ON s.member_id = m.id
        LEFT JOIN users u ON s.created_by = u.id
        WHERE s.id = ?";
$result = executeQuery($sql, "i", [$id]);

if ($result->num_rows == 0) {
    $_SESSION['error'] = 'Share record not found';
    header('Location: index.php');
    exit();
}

$share = $result->fetch_assoc();

// Page for printing certificate
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Share Certificate - <?php echo $share['member_no']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #fff;
            font-family: 'Times New Roman', serif;
        }

        .certificate {
            max-width: 800px;
            margin: 50px auto;
            padding: 40px;
            border: 2px solid #gold;
            background: #fff;
            position: relative;
        }

        .certificate:before {
            content: '';
            position: absolute;
            top: 10px;
            left: 10px;
            right: 10px;
            bottom: 10px;
            border: 1px solid #gold;
            pointer-events: none;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .header h1 {
            font-size: 36px;
            font-weight: bold;
            color: #b8860b;
            text-transform: uppercase;
        }

        .header h3 {
            font-size: 24px;
            color: #333;
        }

        .content {
            margin: 40px 0;
            font-size: 18px;
            line-height: 2;
        }

        .signature {
            margin-top: 50px;
        }

        .signature-line {
            border-top: 1px solid #000;
            width: 200px;
            margin-top: 5px;
        }

        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 12px;
            color: #666;
        }

        .stamp {
            position: absolute;
            bottom: 100px;
            right: 100px;
            width: 150px;
            height: 150px;
            border: 2px solid #f00;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transform: rotate(-15deg);
            color: #f00;
            font-size: 24px;
            font-weight: bold;
            opacity: 0.3;
        }
    </style>
</head>

<body>
    <div class="certificate">
        <div class="stamp">APPROVED</div>

        <div class="header">
            <h1><?php echo APP_NAME; ?></h1>
            <h3>SHARE CERTIFICATE</h3>
        </div>

        <div class="content">
            <p>This is to certify that</p>
            <h2 class="text-center"><?php echo strtoupper($share['full_name']); ?></h2>
            <p>Member Number: <strong><?php echo $share['member_no']; ?></strong></p>
            <p>National ID: <strong><?php echo $share['national_id']; ?></strong></p>

            <p>is the registered owner of</p>
            <h1 class="text-center"><?php echo number_format($share['shares_count']); ?></h1>
            <p class="text-center">SHARES</p>

            <p>in <?php echo APP_NAME; ?>, with a total value of</p>
            <h3 class="text-center"><?php echo formatCurrency($share['total_value']); ?></h3>

            <p>Issued on this day, <?php echo date('d F Y', strtotime($share['date_purchased'])); ?></p>
        </div>

        <div class="row signature">
            <div class="col-6">
                <p>_________________________</p>
                <p>Chairperson</p>
            </div>
            <div class="col-6 text-end">
                <p>_________________________</p>
                <p>Secretary</p>
            </div>
        </div>

        <div class="footer">
            <p>This certificate is the property of <?php echo APP_NAME; ?> and must be returned upon request.</p>
            <p>Issued by: <?php echo $share['issued_by_name'] ?? 'System'; ?> | Certificate No: <?php echo str_pad($share['id'], 6, '0', STR_PAD_LEFT); ?></p>
        </div>

        <div class="text-center mt-4">
            <button class="btn btn-primary" onclick="window.print()">Print Certificate</button>
            <a href="index.php" class="btn btn-secondary">Back</a>
        </div>
    </div>
</body>

</html>
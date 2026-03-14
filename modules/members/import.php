<?php
// modules/members/import.php
//show php errors
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);



require_once '../../config/config.php';
requireRole('admin');

$page_title = 'Import Members';

// Handle file upload and import
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'import_members') {
        importMembers();
    } elseif ($_POST['action'] == 'validate_csv') {
        validateCSV();
    } elseif ($_POST['action'] == 'download_template') {
        downloadTemplate();
    }
}

function importMembers()
{
    $conn = getConnection();
    $conn->begin_transaction();

    try {
        $import_type = $_POST['import_type'] ?? 'full';
        $default_status = $_POST['default_status'] ?? 'pending';
        $charge_fees = isset($_POST['charge_fees']) ? true : false;
        $registration_fee = floatval($_POST['registration_fee'] ?? 2000);
        $bylaws_fee = floatval($_POST['bylaws_fee'] ?? 400);
        $create_user_accounts = isset($_POST['create_user_accounts']) ? true : false;

        $imported = 0;
        $skipped = 0;
        $errors = [];

        if (isset($_FILES['members_file']) && $_FILES['members_file']['error'] == 0) {
            $file = fopen($_FILES['members_file']['tmp_name'], 'r');

            // Debug: Check if file is readable
            if (!$file) {
                throw new Exception("Could not open file");
            }

            // Get headers
            $headers = fgetcsv($file);

            // Debug: Log raw headers
            error_log("Raw headers: " . print_r($headers, true));

            if ($headers === false) {
                throw new Exception("Could not read headers from CSV");
            }

            // Clean headers - remove BOM and extra characters
            $headers = array_map(function ($h) {
                // Remove BOM (Byte Order Mark) and other non-printable characters
                $h = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $h);
                // Remove any quotes
                $h = str_replace('"', '', $h);
                $h = str_replace("'", '', $h);
                // Trim whitespace
                $h = trim($h);
                // Convert to lowercase
                $h = strtolower($h);
                return $h;
            }, $headers);

            // Debug: Log cleaned headers
            error_log("Cleaned headers: " . print_r($headers, true));

            $line_number = 1;

            while (($row = fgetcsv($file)) !== false) {
                $line_number++;

                // Debug: Log raw row
                error_log("Raw row $line_number: " . print_r($row, true));

                // Skip empty rows (all fields empty)
                $row = array_map('trim', $row);
                if (count(array_filter($row)) == 0) {
                    continue;
                }

                // Ensure row has same number of columns as headers
                if (count($row) != count($headers)) {
                    $errors[] = "Line $line_number: Column count mismatch. Expected " . count($headers) . ", got " . count($row);
                    $skipped++;
                    continue;
                }

                // Map data to headers
                $data = array_combine($headers, $row);

                // Debug: Log mapped data
                error_log("Mapped data $line_number: " . print_r($data, true));

                // Validate required fields with more detailed checking
                $missing_fields = [];

                if (!isset($data['full_name']) || trim($data['full_name']) === '') {
                    $missing_fields[] = 'full_name';
                }
                if (!isset($data['national_id']) || trim($data['national_id']) === '') {
                    $missing_fields[] = 'national_id';
                }
                if (!isset($data['phone']) || trim($data['phone']) === '') {
                    $missing_fields[] = 'phone';
                }

                if (!empty($missing_fields)) {
                    $errors[] = "Line $line_number: Missing required fields: " . implode(', ', $missing_fields);
                    $errors[] = "Line $line_number data: " . print_r($data, true);
                    $skipped++;
                    continue;
                }

                // Clean the data
                $full_name = trim($data['full_name']);
                $national_id = trim($data['national_id']);
                $phone = trim($data['phone']);
                $email = isset($data['email']) ? trim($data['email']) : null;
                $address = isset($data['address']) ? trim($data['address']) : null;
                $date_joined = isset($data['date_joined']) && !empty(trim($data['date_joined'])) ? trim($data['date_joined']) : date('Y-m-d');
                $membership_status = isset($data['membership_status']) && !empty(trim($data['membership_status'])) ? trim($data['membership_status']) : $default_status;

                // Check if member already exists
                $check_sql = "SELECT id FROM members WHERE national_id = ? OR phone = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("ss", $national_id, $phone);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();

                if ($check_result->num_rows > 0) {
                    $errors[] = "Line $line_number: Member with National ID $national_id or Phone $phone already exists";
                    $skipped++;
                    continue;
                }

                // Generate member number if not provided
                $member_no = (isset($data['member_no']) && !empty(trim($data['member_no']))) ? trim($data['member_no']) : generateMemberNumber();

                // Insert member
                $insert_sql = "INSERT INTO members 
                              (member_no, full_name, national_id, phone, email, address, date_joined, membership_status, created_by, created_at)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                $insert_stmt = $conn->prepare($insert_sql);
                $insert_stmt->bind_param("ssssssssi", $member_no, $full_name, $national_id, $phone, $email, $address, $date_joined, $membership_status, getCurrentUserId());

                if (!$insert_stmt->execute()) {
                    throw new Exception("Failed to insert member: " . $insert_stmt->error);
                }

                $member_id = $conn->insert_id;

                // Process registration fees if enabled
                if ($charge_fees) {
                    $total_fees = $registration_fee + $bylaws_fee;

                    // Update member with fee payment
                    $fee_sql = "UPDATE members SET 
                               registration_fee_paid = ?,
                               bylaws_fee_paid = ?,
                               registration_receipt_no = ?,
                               registration_date = ?
                               WHERE id = ?";
                    $fee_stmt = $conn->prepare($fee_sql);
                    $receipt_no = 'IMP' . time() . rand(100, 999);
                    $fee_stmt->bind_param("ddssi", $registration_fee, $bylaws_fee, $receipt_no, $date_joined, $member_id);
                    $fee_stmt->execute();

                    // Create transaction for registration fee
                    $trans_no1 = 'REG' . time() . rand(100, 999);
                    $trans_sql1 = "INSERT INTO transactions 
                                  (transaction_no, transaction_date, description, debit_account, credit_account, amount, reference_type, reference_id, created_by)
                                  VALUES (?, ?, ?, 'REGISTRATION_INCOME', 'CASH', ?, 'registration', ?, ?)";
                    $trans_stmt1 = $conn->prepare($trans_sql1);
                    $desc1 = "Registration fee - $full_name";
                    $trans_stmt1->bind_param("sssiii", $trans_no1, $date_joined, $desc1, $registration_fee, $member_id, getCurrentUserId());
                    $trans_stmt1->execute();

                    // Create transaction for bylaws fee
                    $trans_no2 = 'BYL' . time() . rand(100, 999);
                    $trans_sql2 = "INSERT INTO transactions 
                                  (transaction_no, transaction_date, description, debit_account, credit_account, amount, reference_type, reference_id, created_by)
                                  VALUES (?, ?, ?, 'BYLAWS_INCOME', 'CASH', ?, 'bylaws', ?, ?)";
                    $trans_stmt2 = $conn->prepare($trans_sql2);
                    $desc2 = "Bylaws purchase - $full_name";
                    $trans_stmt2->bind_param("sssiii", $trans_no2, $date_joined, $desc2, $bylaws_fee, $member_id, getCurrentUserId());
                    $trans_stmt2->execute();
                }

                // Process initial deposit if provided
                if (isset($data['initial_deposit']) && !empty(trim($data['initial_deposit'])) && floatval($data['initial_deposit']) > 0) {
                    $deposit_amount = floatval($data['initial_deposit']);

                    $deposit_sql = "INSERT INTO deposits 
                                   (member_id, deposit_date, amount, balance, transaction_type, reference_no, description, created_by, created_at)
                                   VALUES (?, ?, ?, ?, 'deposit', ?, ?, ?, NOW())";
                    $deposit_stmt = $conn->prepare($deposit_sql);
                    $deposit_ref = 'DEP' . time() . rand(100, 999);
                    $deposit_desc = "Initial deposit - Import";
                    $deposit_stmt->bind_param("isddssi", $member_id, $date_joined, $deposit_amount, $deposit_amount, $deposit_ref, $deposit_desc, getCurrentUserId());
                    $deposit_stmt->execute();
                }

                // Create user account if enabled
                if ($create_user_accounts) {
                    createUserAccount($conn, $member_id, $full_name, $email, $phone, $member_no);
                }

                $imported++;
            }

            fclose($file);
        } else {
            throw new Exception("No file uploaded or file upload error");
        }

        $conn->commit();

        $message = "Import completed successfully!\n";
        $message .= "Imported: $imported members\n";
        $message .= "Skipped: $skipped members\n";
        if (!empty($errors)) {
            $message .= "Errors: " . count($errors) . " issues found\n";
            $_SESSION['import_errors'] = $errors;
        }

        $_SESSION['success'] = nl2br($message);
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Import failed: ' . $e->getMessage();
        error_log("Import error: " . $e->getMessage());
    }

    $conn->close();
    header('Location: import.php');
    exit();
}

function validateCSV()
{
    $response = ['valid' => true, 'headers' => [], 'preview' => [], 'errors' => []];

    if (isset($_FILES['members_file']) && $_FILES['members_file']['error'] == 0) {
        $file = fopen($_FILES['members_file']['tmp_name'], 'r');
        $headers = fgetcsv($file);

        // Clean headers
        $headers = array_map(function ($h) {
            $h = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $h);
            return trim($h);
        }, $headers);

        $response['headers'] = $headers;

        // Check required headers
        $required = ['full_name', 'national_id', 'phone'];
        $missing = array_diff($required, array_map('strtolower', $headers));

        if (!empty($missing)) {
            $response['valid'] = false;
            $response['errors'][] = "Missing required columns: " . implode(', ', $missing);
        }

        // Preview first 5 rows
        $preview = [];
        $count = 0;
        while (($row = fgetcsv($file)) !== false && $count < 5) {
            $preview[] = $row;
            $count++;
        }
        $response['preview'] = $preview;

        fclose($file);
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

function downloadTemplate()
{
    $type = $_GET['type'] ?? 'full';

    if ($type == 'minimal') {
        $filename = 'member_import_template_minimal.csv';
        $headers = ['full_name', 'national_id', 'phone', 'date_joined'];
        $data = [
            ['John Doe', '12345678', '0712345678', date('Y-m-d')],
            ['Jane Smith', '87654321', '0723456789', date('Y-m-d')]
        ];
    } else {
        $filename = 'member_import_template_full.csv';
        $headers = ['member_no', 'full_name', 'national_id', 'phone', 'email', 'address', 'date_joined', 'membership_status', 'registration_fee', 'bylaws_fee', 'initial_deposit'];
        $data = [
            ['MEM001', 'John Doe', '12345678', '0712345678', 'john@email.com', 'Nairobi', date('Y-m-d'), 'active', '2000', '400', '5000'],
            ['', 'Jane Smith', '87654321', '0723456789', 'jane@email.com', 'Mombasa', date('Y-m-d'), 'pending', '2000', '400', '0']
        ];
    }

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');

    // Add BOM for Excel UTF-8 compatibility
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // Write headers
    fputcsv($output, $headers);

    // Write sample data
    foreach ($data as $row) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit();
}

function createUserAccount($conn, $member_id, $full_name, $email, $phone, $member_no)
{
    // Check if user already exists
    $check_sql = "SELECT id FROM users WHERE username = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("s", $member_no);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows == 0) {
        // Generate random password
        $temp_password = bin2hex(random_bytes(4));
        $hashed_password = password_hash($temp_password, PASSWORD_DEFAULT);

        $user_sql = "INSERT INTO users (username, password, full_name, email, role, status, created_at) 
                     VALUES (?, ?, ?, ?, 'member', 1, NOW())";
        $user_stmt = $conn->prepare($user_sql);
        $user_stmt->bind_param("ssss", $member_no, $hashed_password, $full_name, $email);
        $user_stmt->execute();
        $user_id = $conn->insert_id;

        // Link user to member
        $link_sql = "UPDATE members SET user_id = ? WHERE id = ?";
        $link_stmt = $conn->prepare($link_sql);
        $link_stmt->bind_param("ii", $user_id, $member_id);
        $link_stmt->execute();

        // Send SMS with credentials
        if (!empty($phone)) {
            $message = "Welcome $full_name! Your account has been created. Username: $member_no, Password: $temp_password";
            sendNotification($member_id, 'Account Created', $message, 'sms');
        }
    }
}

// Get recent imports
$recent_sql = "SELECT m.*, u.full_name as created_by_name 
               FROM members m
               LEFT JOIN users u ON m.created_by = u.id
               WHERE m.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
               ORDER BY m.created_at DESC
               LIMIT 20";
$recent_members = executeQuery($recent_sql);

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h3 class="page-title">Import Members</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Members</a></li>
                <li class="breadcrumb-item active">Import</li>
            </ul>
        </div>
        <div class="col-auto">
            <div class="btn-group">
                <button type="button" class="btn btn-success dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="fas fa-download"></i> Download Template
                </button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="?action=download_template&type=full">Full Template (All Fields)</a></li>
                    <li><a class="dropdown-item" href="?action=download_template&type=minimal">Minimal Template</a></li>
                </ul>
            </div>
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Members
            </a>
        </div>
    </div>
</div>

<!-- Alert Messages -->
<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?php echo $_SESSION['success']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>
        <?php echo $_SESSION['error']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['import_errors'])): ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <strong>Import completed with warnings:</strong>
        <ul class="mb-0 mt-2">
            <?php foreach ($_SESSION['import_errors'] as $error): ?>
                <li><?php echo $error; ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['import_errors']); ?>
<?php endif; ?>

<!-- Import Form -->
<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="card-title mb-0"><i class="fas fa-upload"></i> Import Members from CSV</h5>
    </div>
    <div class="card-body">
        <form id="importForm" method="POST" enctype="multipart/form-data" class="needs-validation" novalidate>
            <input type="hidden" name="action" value="import_members">

            <div class="row">
                <div class="col-md-8 mb-3">
                    <label for="members_file" class="form-label">CSV File <span class="text-danger">*</span></label>
                    <input type="file" class="form-control" id="members_file" name="members_file"
                        accept=".csv" required onchange="validateFile()">
                    <div class="invalid-feedback">Please select a CSV file to import.</div>
                    <small class="text-muted">Supported format: CSV (Comma separated values)</small>
                </div>

                <div class="col-md-4 mb-3">
                    <label for="import_type" class="form-label">Import Type</label>
                    <select class="form-control" id="import_type" name="import_type">
                        <option value="full">Full Import (All Fields)</option>
                        <option value="minimal">Minimal Import (Required Only)</option>
                    </select>
                </div>
            </div>

            <!-- Preview Section -->
            <div id="previewSection" style="display: none;" class="mb-4">
                <h6>File Preview:</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered" id="previewTable">
                        <thead id="previewHeader">
                            <tr></tr>
                        </thead>
                        <tbody id="previewBody">
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Import Options -->
            <div class="card mb-3">
                <div class="card-header bg-info text-white">
                    <h6 class="mb-0">Import Options</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="default_status" class="form-label">Default Member Status</label>
                            <select class="form-control" id="default_status" name="default_status">
                                <option value="pending">Pending</option>
                                <option value="active">Active</option>
                                <option value="suspended">Suspended</option>
                            </select>
                        </div>

                        <div class="col-md-8 mb-3">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" id="charge_fees" name="charge_fees" value="1" checked>
                                <label class="form-check-label" for="charge_fees">
                                    Charge registration fees (KES 2,000 + KES 400 bylaws)
                                </label>
                            </div>

                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="create_user_accounts" name="create_user_accounts" value="1" checked>
                                <label class="form-check-label" for="create_user_accounts">
                                    Create user accounts for members
                                </label>
                            </div>
                        </div>
                    </div>

                    <div id="feeOptions" class="row">
                        <div class="col-md-3">
                            <label for="registration_fee" class="form-label">Registration Fee (KES)</label>
                            <input type="number" class="form-control" id="registration_fee" name="registration_fee" value="2000" min="0">
                        </div>
                        <div class="col-md-3">
                            <label for="bylaws_fee" class="form-label">Bylaws Fee (KES)</label>
                            <input type="number" class="form-control" id="bylaws_fee" name="bylaws_fee" value="400" min="0">
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-lg" onclick="return confirmImport()">
                <i class="fas fa-upload"></i> Import Members
            </button>
            <button type="button" class="btn btn-secondary btn-lg" onclick="resetForm()">
                <i class="fas fa-undo"></i> Reset
            </button>
        </form>
    </div>
</div>

<!-- Recent Imports -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title">Recently Imported Members (Last 7 Days)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>Member No</th>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Date Joined</th>
                        <th>Status</th>
                        <th>Imported On</th>
                        <th>Imported By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($member = $recent_members->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo $member['member_no']; ?></td>
                            <td><?php echo $member['full_name']; ?></td>
                            <td><?php echo $member['phone']; ?></td>
                            <td><?php echo formatDate($member['date_joined']); ?></td>
                            <td>
                                <span class="badge bg-<?php echo $member['membership_status'] == 'active' ? 'success' : 'warning'; ?>">
                                    <?php echo ucfirst($member['membership_status']); ?>
                                </span>
                            </td>
                            <td><?php echo formatDate($member['created_at']); ?></td>
                            <td><?php echo $member['created_by_name'] ?? 'System'; ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    function validateFile() {
        var file = document.getElementById('members_file').files[0];
        if (!file) return;

        // Check file extension
        var ext = file.name.split('.').pop().toLowerCase();
        if (ext != 'csv') {
            Swal.fire('Invalid File', 'Please upload a CSV file', 'error');
            document.getElementById('members_file').value = '';
            return;
        }

        // Preview file
        previewCSV(file);
    }

    function previewCSV(file) {
        var formData = new FormData();
        formData.append('members_file', file);
        formData.append('action', 'validate_csv');

        fetch('import.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.valid) {
                    document.getElementById('previewSection').style.display = 'block';

                    // Build preview table
                    var headerRow = document.querySelector('#previewHeader tr');
                    headerRow.innerHTML = '';
                    data.headers.forEach(header => {
                        headerRow.innerHTML += '<th>' + header + '</th>';
                    });

                    var body = document.getElementById('previewBody');
                    body.innerHTML = '';
                    data.preview.forEach(row => {
                        var tr = document.createElement('tr');
                        row.forEach(cell => {
                            var td = document.createElement('td');
                            td.textContent = cell;
                            tr.appendChild(td);
                        });
                        body.appendChild(tr);
                    });
                } else {
                    Swal.fire('Validation Error', data.errors.join('\n'), 'error');
                }
            });
    }

    function confirmImport() {
        var file = document.getElementById('members_file').files[0];
        if (!file) {
            Swal.fire('No File', 'Please select a file to import', 'warning');
            return false;
        }

        return Swal.fire({
            title: 'Confirm Import',
            text: 'Are you sure you want to import members from ' + file.name + '?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, import!'
        }).then((result) => {
            return result.isConfirmed;
        });
    }

    function resetForm() {
        document.getElementById('importForm').reset();
        document.getElementById('previewSection').style.display = 'none';
    }

    // Toggle fee options
    document.getElementById('charge_fees').addEventListener('change', function() {
        document.getElementById('feeOptions').style.display = this.checked ? 'flex' : 'none';
    });

    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('feeOptions').style.display = 'flex';
    });
</script>

<style>
    #importForm .card {
        margin-bottom: 15px;
    }

    #previewSection {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 5px;
        margin-top: 15px;
    }

    #previewTable {
        font-size: 12px;
    }

    #previewTable th {
        background: #e9ecef;
    }

    @media (max-width: 768px) {
        .btn-group {
            width: 100%;
            margin-bottom: 10px;
        }

        .btn-group .btn {
            width: 100%;
        }
    }
</style>

<?php include '../../includes/footer.php'; ?>
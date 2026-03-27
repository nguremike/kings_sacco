<?php
// functions/year_end_processing.php
require_once 'config.php';

/**
 * Run year-end processing for admin charges and share capital charges
 * 
 * @param int $year The year to process (defaults to previous year)
 * @param bool $process_admin_charges Whether to process admin charges
 * @param bool $process_share_charges Whether to process share capital charges
 * @param float $admin_charge_amount Amount to charge for admin fees
 * @param float $share_charge_amount Amount to charge for share capital shortfall
 * @return array Result of processing
 */
function runYearEndProcessing(
    $year = null,
    $process_admin_charges = true,
    $process_share_charges = true,
    $admin_charge_amount = 1000,
    $share_charge_amount = 1000
) {

    if (!$year) {
        $year = date('Y') - 1; // Default to previous year
    }

    $conn = getConnection();
    $conn->begin_transaction();

    try {
        // Check if already processed for this year
        $check_sql = "SELECT id FROM year_end_processing_log 
                      WHERE process_year = ? AND status = 'completed'";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $year);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            return [
                'success' => false,
                'message' => "Year $year has already been processed"
            ];
        }

        // Create processing log
        $process_type = 'both';
        if (!$process_admin_charges && $process_share_charges) {
            $process_type = 'share_capital_charge';
        } elseif ($process_admin_charges && !$process_share_charges) {
            $process_type = 'admin_charges';
        }

        $current_user_id = getCurrentUserId();
        if (!$current_user_id) {
            $current_user_id = 1; // Default to admin ID 1 if not logged in
        }

        $log_sql = "INSERT INTO year_end_processing_log 
                    (process_year, process_type, processed_at, processed_by, status)
                    VALUES (?, ?, NOW(), ?, 'processing')";
        $log_stmt = $conn->prepare($log_sql);
        $log_stmt->bind_param("isi", $year, $process_type, $current_user_id);
        $log_stmt->execute();
        $log_id = $conn->insert_id;

        $results = [
            'admin_charges' => ['count' => 0, 'total' => 0],
            'share_charges' => ['count' => 0, 'total' => 0],
            'errors' => []
        ];

        // Get all active members
        $members_sql = "SELECT m.id, m.member_no, m.full_name,
                        (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE -amount END), 0) 
                         FROM deposits WHERE member_id = m.id) as current_balance,
                        (SELECT COALESCE(SUM(total_value), 0) FROM shares WHERE member_id = m.id) as total_shares_value,
                        (SELECT COALESCE(SUM(shares_count), 0) FROM shares WHERE member_id = m.id) as total_shares,
                        COALESCE(m.partial_share_balance, 0) as partial_share_balance,
                        COALESCE(m.total_share_contributions, 0) as total_share_contributions,
                        COALESCE(m.full_shares_issued, 0) as full_shares_issued
                        FROM members m
                        WHERE m.membership_status = 'active'";
        $members_result = $conn->query($members_sql);

        if (!$members_result) {
            throw new Exception("Failed to get members: " . $conn->error);
        }

        while ($member = $members_result->fetch_assoc()) {
            try {
                // Ensure numeric values
                $member['current_balance'] = floatval($member['current_balance'] ?? 0);
                $member['total_shares_value'] = floatval($member['total_shares_value'] ?? 0);
                $member['partial_share_balance'] = floatval($member['partial_share_balance'] ?? 0);
                $member['total_share_contributions'] = floatval($member['total_share_contributions'] ?? 0);
                $member['full_shares_issued'] = intval($member['full_shares_issued'] ?? 0);

                // Process admin charges
                if ($process_admin_charges) {
                    processAdminCharge($conn, $member, $year, $admin_charge_amount, $results, $current_user_id);
                }

                // Process share capital charge
                if ($process_share_charges) {
                    processShareCapitalCharge($conn, $member, $year, $share_charge_amount, $results, $current_user_id);
                }
            } catch (Exception $e) {
                $results['errors'][] = "Member {$member['member_no']}: " . $e->getMessage();
            }
        }

        // Update processing log - FIXED: 6 parameters total
        $update_log_sql = "UPDATE year_end_processing_log 
                          SET members_processed = ?, 
                              total_admin_charges = ?,
                              total_share_charges = ?,
                              status = 'completed',
                              completed_at = NOW(),
                              error_log = ?
                          WHERE id = ?";
        $update_log_stmt = $conn->prepare($update_log_sql);

        if (!$update_log_stmt) {
            throw new Exception("Failed to prepare update statement: " . $conn->error);
        }

        $total_members = $results['admin_charges']['count'] + $results['share_charges']['count'];
        $error_log = implode("\n", $results['errors']);
        $admin_total = $results['admin_charges']['total'];
        $share_total = $results['share_charges']['total'];

        // FIXED: 6 parameters with correct types: i, d, d, s, i
        // i = integer, d = double, s = string, i = integer
        $update_log_stmt->bind_param(
            "iddsi",  // i for members_processed, d for admin_total, d for share_total, s for error_log, i for log_id
            $total_members,
            $admin_total,
            $share_total,
            $error_log,
            $log_id
        );
        $update_log_stmt->execute();

        $conn->commit();

        return [
            'success' => true,
            'message' => "Year-end processing for $year completed successfully",
            'results' => $results
        ];
    } catch (Exception $e) {
        $conn->rollback();

        // Update log with error if it was created
        if (isset($log_id)) {
            $error_log_sql = "UPDATE year_end_processing_log 
                              SET status = 'failed', error_log = ? 
                              WHERE id = ?";
            $error_log_stmt = $conn->prepare($error_log_sql);
            if ($error_log_stmt) {
                $error_msg = $e->getMessage();
                // FIXED: 2 parameters: s, i
                $error_log_stmt->bind_param("si", $error_msg, $log_id);
                $error_log_stmt->execute();
            }
        }

        return [
            'success' => false,
            'message' => 'Processing failed: ' . $e->getMessage()
        ];
    } finally {
        if (isset($conn)) {
            $conn->close();
        }
    }
}

/**
 * Process admin charge for a single member
 */
function processAdminCharge($conn, $member, $year, $amount, &$results, $user_id)
{
    // Check if member has sufficient balance
    if ($member['current_balance'] < $amount) {
        // Insufficient balance - log as error but continue
        $results['errors'][] = "Member {$member['member_no']}: Insufficient balance for admin charge (KES " . number_format($amount) . ")";
        return;
    }

    // Create admin charge
    $charge_date = $year . '-12-31'; // Last day of the year
    $reference_no = 'ADM' . $year . $member['id'] . rand(100, 999);
    $description = "Annual administrative charge for year $year";

    // Insert into admin_charges table
    $charge_sql = "INSERT INTO admin_charges 
                  (member_id, charge_type, amount, charge_date, due_date, 
                   description, reference_no, status, created_by, created_at)
                  VALUES (?, 'annual_fee', ?, ?, ?, ?, ?, 'pending', ?, NOW())";
    $charge_stmt = $conn->prepare($charge_sql);
    $due_date = date('Y-m-d', strtotime('+30 days', strtotime($charge_date)));
    $charge_stmt->bind_param(
        "idssssi",
        $member['id'],
        $amount,
        $charge_date,
        $due_date,
        $description,
        $reference_no,
        $user_id
    );
    $charge_stmt->execute();

    // Deduct from deposits (create withdrawal)
    $new_balance = $member['current_balance'] - $amount;

    $withdrawal_sql = "INSERT INTO deposits 
                      (member_id, deposit_date, amount, balance, transaction_type, 
                       reference_no, description, created_by, created_at)
                      VALUES (?, ?, ?, ?, 'withdrawal', ?, ?, ?, NOW())";
    $withdrawal_stmt = $conn->prepare($withdrawal_sql);
    $withdrawal_desc = "Annual admin charge deduction - $year";
    $withdrawal_stmt->bind_param(
        "isddssi",
        $member['id'],
        $charge_date,
        $amount,
        $new_balance,
        $reference_no,
        $withdrawal_desc,
        $user_id
    );
    $withdrawal_stmt->execute();

    // Update member record
    $update_sql = "UPDATE members SET 
                   last_admin_charge_date = ?,
                   total_admin_charges_year = COALESCE(total_admin_charges_year, 0) + ?
                   WHERE id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("sdi", $charge_date, $amount, $member['id']);
    $update_stmt->execute();

    // Update results
    $results['admin_charges']['count']++;
    $results['admin_charges']['total'] += $amount;
}

/**
 * Process share capital charge for a single member
 */
function processShareCapitalCharge($conn, $member, $year, $amount, &$results, $user_id)
{
    // Check if member has already completed a share (KES 10,000)
    $total_share_value = $member['total_shares_value'] + $member['partial_share_balance'];

    if ($total_share_value >= 10000) {
        // Member has at least one full share - no charge
        return;
    }

    // Check if member has an exception for this year
    $exception_sql = "SELECT id FROM share_charge_exceptions 
                      WHERE member_id = ? AND year = ? AND waived = 1";
    $exception_stmt = $conn->prepare($exception_sql);
    $exception_stmt->bind_param("ii", $member['id'], $year);
    $exception_stmt->execute();
    $exception_result = $exception_stmt->get_result();

    if ($exception_result->num_rows > 0) {
        // Member has an exception - skip charge
        $results['errors'][] = "Member {$member['member_no']}: Share charge waived for $year";
        return;
    }

    // Check if member has sufficient balance
    if ($member['current_balance'] < $amount) {
        // Insufficient balance - log as error
        $results['errors'][] = "Member {$member['member_no']}: Insufficient balance for share capital charge (KES " . number_format($amount) . ")";
        return;
    }

    // Create share capital charge (as admin charge)
    $charge_date = $year . '-12-31';
    $reference_no = 'SHR' . $year . $member['id'] . rand(100, 999);
    $description = "Share capital shortfall charge for year $year (no full share)";

    // Insert into admin_charges
    $charge_sql = "INSERT INTO admin_charges 
                  (member_id, charge_type, amount, charge_date, due_date, 
                   description, reference_no, status, created_by, created_at)
                  VALUES (?, 'share_shortfall', ?, ?, ?, ?, ?, 'pending', ?, NOW())";
    $charge_stmt = $conn->prepare($charge_sql);
    $due_date = date('Y-m-d', strtotime('+30 days', strtotime($charge_date)));
    $charge_stmt->bind_param(
        "idssssi",
        $member['id'],
        $amount,
        $charge_date,
        $due_date,
        $description,
        $reference_no,
        $user_id
    );
    $charge_stmt->execute();

    // Deduct from deposits
    $new_balance = $member['current_balance'] - $amount;

    $withdrawal_sql = "INSERT INTO deposits 
                      (member_id, deposit_date, amount, balance, transaction_type, 
                       reference_no, description, created_by, created_at)
                      VALUES (?, ?, ?, ?, 'withdrawal', ?, ?, ?, NOW())";
    $withdrawal_stmt = $conn->prepare($withdrawal_sql);
    $withdrawal_desc = "Share capital charge deduction - $year";
    $withdrawal_stmt->bind_param(
        "isddssi",
        $member['id'],
        $charge_date,
        $amount,
        $new_balance,
        $reference_no,
        $withdrawal_desc,
        $user_id
    );
    $withdrawal_stmt->execute();

    // Add to share contributions (as part of share capital)
    $contrib_sql = "INSERT INTO share_contributions 
                   (member_id, amount, contribution_date, reference_no, notes, created_by, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, NOW())";
    $contrib_stmt = $conn->prepare($contrib_sql);
    $notes = "Forced share contribution from year-end charge $year";
    $contrib_stmt->bind_param(
        "idsssi",
        $member['id'],
        $amount,
        $charge_date,
        $reference_no,
        $notes,
        $user_id
    );
    $contrib_stmt->execute();

    // Update member share capital
    $new_total = $member['total_share_contributions'] + $amount;
    $new_full_shares = floor($new_total / 10000);
    $new_partial = $new_total - ($new_full_shares * 10000);

    $update_sql = "UPDATE members SET 
                   total_share_contributions = COALESCE(total_share_contributions, 0) + ?,
                   full_shares_issued = ?,
                   partial_share_balance = ?,
                   last_share_charge_date = ?
                   WHERE id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param(
        "diids",
        $amount,
        $new_full_shares,
        $new_partial,
        $charge_date,
        $member['id']
    );
    $update_stmt->execute();

    // Check if this completes a full share
    if (floor(($member['total_share_contributions'] + $amount) / 10000) > $member['full_shares_issued']) {
        issueNewShares($conn, $member['id'], $charge_date, $user_id);
    }

    // Update results
    $results['share_charges']['count']++;
    $results['share_charges']['total'] += $amount;
}

/**
 * Issue new shares when a member reaches KES 10,000
 */
function issueNewShares($conn, $member_id, $issue_date, $user_id)
{
    // Get member's current share status
    $member_sql = "SELECT full_name, member_no, 
                   COALESCE(total_share_contributions, 0) as total_share_contributions, 
                   COALESCE(full_shares_issued, 0) as full_shares_issued,
                   COALESCE(partial_share_balance, 0) as partial_share_balance
                   FROM members WHERE id = ?";
    $member_stmt = $conn->prepare($member_sql);
    $member_stmt->bind_param("i", $member_id);
    $member_stmt->execute();
    $member_result = $member_stmt->get_result();

    if (!$member_result || $member_result->num_rows == 0) {
        error_log("Member not found for share issuance: $member_id");
        return;
    }

    $member = $member_result->fetch_assoc();

    $share_value = 10000;
    $new_full_shares = floor($member['total_share_contributions'] / $share_value);
    $new_shares_issued = $new_full_shares - $member['full_shares_issued'];

    for ($i = 0; $i < $new_shares_issued; $i++) {
        $share_number = 'SH' . date('Y') . str_pad($member_id, 4, '0', STR_PAD_LEFT) .
            str_pad($member['full_shares_issued'] + $i + 1, 3, '0', STR_PAD_LEFT);
        $certificate_number = 'CERT' . time() . rand(1000, 9999) . $i;

        $issue_sql = "INSERT INTO shares_issued 
                      (member_id, share_number, share_count, amount_paid, issue_date, certificate_number, issued_by)
                      VALUES (?, ?, 1, ?, ?, ?, ?)";
        $issue_stmt = $conn->prepare($issue_sql);
        $issue_stmt->bind_param(
            "isdsi",
            $member_id,
            $share_number,
            $share_value,
            $issue_date,
            $certificate_number,
            $user_id
        );
        $issue_stmt->execute();

        // Also add to shares table
        $shares_sql = "INSERT INTO shares 
                      (member_id, shares_count, share_value, total_value, transaction_type, 
                       reference_no, date_purchased, description, created_by)
                      VALUES (?, 1, ?, ?, 'share_charge', ?, ?, ?, ?)";
        $shares_stmt = $conn->prepare($shares_sql);
        $desc = "Share issued from year-end charge";
        $shares_stmt->bind_param(
            "iddssi",
            $member_id,
            $share_value,
            $share_value,
            $certificate_number,
            $issue_date,
            $desc,
            $user_id
        );
        $shares_stmt->execute();
    }
}

/**
 * Get year-end processing history
 */
function getYearEndProcessingHistory($limit = 10)
{
    $conn = getConnection();

    // First, check if the table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'year_end_processing_log'");
    if ($table_check->num_rows == 0) {
        // Table doesn't exist - create it
        $create_table_sql = "CREATE TABLE IF NOT EXISTS year_end_processing_log (
            id INT PRIMARY KEY AUTO_INCREMENT,
            process_year YEAR NOT NULL,
            process_type ENUM('admin_charges', 'share_capital_charge', 'both') NOT NULL,
            processed_at DATETIME NOT NULL,
            processed_by INT NOT NULL,
            members_processed INT DEFAULT 0,
            total_admin_charges DECIMAL(10,2) DEFAULT 0,
            total_share_charges DECIMAL(10,2) DEFAULT 0,
            status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
            error_log TEXT,
            completed_at DATETIME NULL,
            FOREIGN KEY (processed_by) REFERENCES users(id)
        )";
        $conn->query($create_table_sql);
    }

    $sql = "SELECT l.*, u.full_name as processed_by_name
            FROM year_end_processing_log l
            LEFT JOIN users u ON l.processed_by = u.id
            ORDER BY l.processed_at DESC
            LIMIT ?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Failed to prepare year-end processing history query: " . $conn->error);
        return new stdClass(); // Return empty object
    }

    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result) {
        error_log("Failed to get year-end processing history result: " . $conn->error);
        return new stdClass(); // Return empty object
    }

    $conn->close();
    return $result;
}

/**
 * Check if year has been processed
 */
function isYearProcessed($year)
{
    $conn = getConnection();
    $sql = "SELECT id FROM year_end_processing_log 
            WHERE process_year = ? AND status = 'completed'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $year);
    $stmt->execute();
    $result = $stmt->get_result();
    $processed = $result->num_rows > 0;
    $conn->close();
    return $processed;
}

/**
 * Add exception for share capital charge
 */
function addShareChargeException($member_id, $year, $reason)
{
    $conn = getConnection();

    $user_id = getCurrentUserId();
    if (!$user_id) {
        $user_id = 1;
    }

    $sql = "INSERT INTO share_charge_exceptions 
            (member_id, year, waived, reason, approved_by, approved_at)
            VALUES (?, ?, 1, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iisi", $member_id, $year, $reason, $user_id);

    $result = $stmt->execute();
    $conn->close();
    return $result;
}

/**
 * Preview year-end charges without processing
 */
function previewYearEndCharges($year, $admin_charge_amount = 1000, $share_charge_amount = 1000)
{
    $conn = getConnection();

    $sql = "SELECT m.id, m.member_no, m.full_name,
            (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE -amount END), 0) 
             FROM deposits WHERE member_id = m.id) as current_balance,
            (SELECT COALESCE(SUM(total_value), 0) FROM shares WHERE member_id = m.id) as total_shares_value,
            COALESCE(m.partial_share_balance, 0) as partial_share_balance,
            COALESCE(m.total_share_contributions, 0) as total_share_contributions,
            CASE 
                WHEN (SELECT COALESCE(SUM(total_value), 0) FROM shares WHERE member_id = m.id) + 
                     COALESCE(m.partial_share_balance, 0) >= 10000 
                THEN 'eligible' 
                ELSE 'shortfall' 
            END as share_status
            FROM members m
            WHERE m.membership_status = 'active'
            ORDER BY m.member_no";

    $result = $conn->query($sql);

    if (!$result) {
        $conn->close();
        return [
            'admin_charges' => ['count' => 0, 'total' => 0, 'insufficient' => 0],
            'share_charges' => ['count' => 0, 'total' => 0, 'insufficient' => 0, 'exceptions' => 0],
            'members' => []
        ];
    }

    $preview = [
        'admin_charges' => ['count' => 0, 'total' => 0, 'insufficient' => 0],
        'share_charges' => ['count' => 0, 'total' => 0, 'insufficient' => 0, 'exceptions' => 0],
        'members' => []
    ];

    while ($row = $result->fetch_assoc()) {
        // Ensure numeric values
        $row['current_balance'] = floatval($row['current_balance'] ?? 0);
        $row['total_shares_value'] = floatval($row['total_shares_value'] ?? 0);
        $row['partial_share_balance'] = floatval($row['partial_share_balance'] ?? 0);

        $member_preview = [
            'id' => $row['id'],
            'member_no' => $row['member_no'],
            'full_name' => $row['full_name'],
            'current_balance' => $row['current_balance'],
            'share_status' => $row['share_status'],
            'admin_charge_applicable' => true,
            'share_charge_applicable' => false
        ];

        // Admin charge preview
        if ($row['current_balance'] >= $admin_charge_amount) {
            $preview['admin_charges']['count']++;
            $preview['admin_charges']['total'] += $admin_charge_amount;
            $member_preview['admin_charge'] = $admin_charge_amount;
        } else {
            $preview['admin_charges']['insufficient']++;
            $member_preview['admin_charge'] = 0;
            $member_preview['admin_charge_note'] = 'Insufficient balance';
        }

        // Share charge preview
        $total_share_value = $row['total_shares_value'] + $row['partial_share_balance'];
        if ($total_share_value < 10000) {
            // Check for exception
            $exception_sql = "SELECT id FROM share_charge_exceptions 
                              WHERE member_id = ? AND year = ? AND waived = 1";
            $exception_stmt = $conn->prepare($exception_sql);
            $exception_stmt->bind_param("ii", $row['id'], $year);
            $exception_stmt->execute();
            $exception_result = $exception_stmt->get_result();
            $has_exception = $exception_result->num_rows > 0;

            if ($has_exception) {
                $preview['share_charges']['exceptions']++;
                $member_preview['share_charge_note'] = 'Exception applied';
            } elseif ($row['current_balance'] >= $share_charge_amount) {
                $preview['share_charges']['count']++;
                $preview['share_charges']['total'] += $share_charge_amount;
                $member_preview['share_charge'] = $share_charge_amount;
                $member_preview['share_charge_applicable'] = true;
            } else {
                $preview['share_charges']['insufficient']++;
                $member_preview['share_charge'] = 0;
                $member_preview['share_charge_note'] = 'Insufficient balance';
            }
        }

        $preview['members'][] = $member_preview;
    }

    $conn->close();
    return $preview;
}

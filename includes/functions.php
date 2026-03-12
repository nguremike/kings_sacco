<?php
// Add to includes/functions.php

/**
 * Send notification to member
 * 
 * @param int $member_id The member ID
 * @param string $title Notification title
 * @param string $message Notification message
 * @param string $channel Notification channel (sms, email, app)
 * @return bool Returns true if notification sent successfully
 */



// includes/functions.php

/**
 * Check and issue full shares based on contributions
 * 
 * @param mysqli $conn Database connection
 * @param int $member_id Member ID
 * @return void
 */
function checkAndIssueShares($conn, $member_id)
{
    // Get member current contributions
    $result = $conn->query("SELECT total_share_contributions, full_shares_issued, partial_share_balance 
                           FROM members WHERE id = $member_id");

    if (!$result || $result->num_rows == 0) {
        return;
    }

    $member = $result->fetch_assoc();

    $total_contributions = $member['total_share_contributions'];
    $issued_shares = $member['full_shares_issued'];
    $share_value = 10000; // 1 share = KES 10,000

    // Calculate how many full shares should be issued
    $expected_shares = floor($total_contributions / $share_value);
    $new_shares = $expected_shares - $issued_shares;

    if ($new_shares > 0) {
        // Issue new shares
        for ($i = 0; $i < $new_shares; $i++) {
            $share_number = 'SH' . date('Y') . str_pad($member_id, 4, '0', STR_PAD_LEFT) . str_pad($issued_shares + $i + 1, 3, '0', STR_PAD_LEFT);
            $certificate_number = 'CERT' . time() . rand(1000, 9999);
            $current_user = getCurrentUserId();

            $issue_sql = "INSERT INTO shares_issued (member_id, share_number, share_count, amount_paid, issue_date, certificate_number, issued_by)
                          VALUES (?, ?, 1, ?, CURDATE(), ?, ?)";
            $stmt = $conn->prepare($issue_sql);
            $stmt->bind_param("isdsi", $member_id, $share_number, $share_value, $certificate_number, $current_user);
            $stmt->execute();

            // Also add to shares table for backward compatibility
            $shares_sql = "INSERT INTO shares (member_id, shares_count, share_value, total_value, transaction_type, reference_no, date_purchased, created_by)
                          VALUES (?, 1, ?, ?, 'purchase', ?, CURDATE(), ?)";
            $stmt2 = $conn->prepare($shares_sql);
            $ref_no = 'SHARE' . $share_number;
            $stmt2->bind_param("iddssi", $member_id, $share_value, $share_value, $ref_no, $current_user);
            $stmt2->execute();
        }

        // Calculate new partial balance
        $partial_balance = $total_contributions - ($expected_shares * $share_value);

        // Update member record
        $update_sql = "UPDATE members SET full_shares_issued = ?, partial_share_balance = ? WHERE id = ?";
        $stmt3 = $conn->prepare($update_sql);
        $stmt3->bind_param("idi", $expected_shares, $partial_balance, $member_id);
        $stmt3->execute();

        // Log the share issuance
        logAudit('ISSUE_SHARES', 'members', $member_id, null, [
            'new_shares' => $new_shares,
            'total_shares' => $expected_shares,
            'partial_balance' => $partial_balance
        ]);
    }
}

/**
 * Send notification to member
 * 
 * @param int $member_id The member ID
 * @param string $title Notification title
 * @param string $message Notification message
 * @param string $channel Notification channel (sms, email, app)
 * @return bool Returns true if notification sent successfully
 */
function sendNotification($member_id, $title, $message, $channel = 'sms')
{
    // Get member details
    $sql = "SELECT phone, email FROM members WHERE id = ?";
    $result = executeQuery($sql, "i", [$member_id]);

    if ($result->num_rows == 0) {
        return false;
    }

    $member = $result->fetch_assoc();

    // Insert notification record
    $insert_sql = "INSERT INTO notifications (member_id, title, message, type, status) 
                   VALUES (?, ?, ?, ?, 'pending')";
    executeQuery($insert_sql, "isss", [$member_id, $title, $message, $channel]);

    // In production, implement actual SMS/Email sending here
    // For now, just log it
    error_log("Notification for member {$member_id}: {$title} - {$message} via {$channel}");

    return true;
}

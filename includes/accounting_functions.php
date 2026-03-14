<?php
// includes/accounting_functions.php
// Helper functions for accounting module

/**
 * Get account code for expense type
 */
function getExpenseAccountCode($expense_type, $category_id = null)
{
    $account_map = [
        'salary' => '5000',
        'rent' => '5010',
        'utilities' => '5020',
        'office_supplies' => '5030',
        'communication' => '5040',
        'travel' => '5050',
        'training' => '5060',
        'marketing' => '5070',
        'legal' => '5080',
        'bank_charges' => '5090',
        'maintenance' => '5110',
        'insurance' => '5120',
        'other' => '5200'
    ];

    return $account_map[$expense_type] ?? '5200';
}

/**
 * Get cash/bank account code based on payment method
 */
function getCashAccountCode($payment_method)
{
    $account_map = [
        'cash' => '1000',
        'bank' => '1010',
        'mpesa' => '1030',
        'cheque' => '1010',
        'credit_card' => '1010'
    ];

    return $account_map[$payment_method] ?? '1000';
}

/**
 * Create journal entry
 */
function createJournalEntry($conn, $entry_date, $reference_type, $reference_id, $description, $lines)
{
    // Generate journal number
    $year = date('Y');
    $month = date('m');
    $result = $conn->query("SELECT COUNT(*) as count FROM journal_entries WHERE YEAR(created_at) = $year");
    $count = $result->fetch_assoc()['count'] + 1;
    $journal_no = "JNL-$year-$month-" . str_pad($count, 5, '0', STR_PAD_LEFT);

    // Calculate totals
    $total_debit = 0;
    $total_credit = 0;
    foreach ($lines as $line) {
        $total_debit += $line['debit'] ?? 0;
        $total_credit += $line['credit'] ?? 0;
    }

    if (abs($total_debit - $total_credit) > 0.01) {
        throw new Exception("Journal entry must balance. Debit: $total_debit, Credit: $total_credit");
    }

    // Insert journal header
    $sql = "INSERT INTO journal_entries (entry_date, journal_no, reference_type, reference_id, description, 
            total_debit, total_credit, status, created_by, posted_by, posted_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'posted', ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "sssisddii",
        $entry_date,
        $journal_no,
        $reference_type,
        $reference_id,
        $description,
        $total_debit,
        $total_credit,
        getCurrentUserId(),
        getCurrentUserId()
    );
    $stmt->execute();
    $journal_id = $conn->insert_id;

    // Insert journal details
    foreach ($lines as $line) {
        $detail_sql = "INSERT INTO journal_details (journal_id, account_code, account_name, debit_amount, credit_amount, description)
                      VALUES (?, ?, (SELECT account_name FROM chart_of_accounts WHERE account_code = ?), ?, ?, ?)";
        $detail_stmt = $conn->prepare($detail_sql);
        $detail_stmt->bind_param(
            "issdds",
            $journal_id,
            $line['account'],
            $line['account'],
            $line['debit'] ?? 0,
            $line['credit'] ?? 0,
            $line['description'] ?? ''
        );
        $detail_stmt->execute();
    }

    return $journal_id;
}

/**
 * Get trial balance
 */
function getTrialBalance($conn, $as_at_date = null)
{
    if (!$as_at_date) {
        $as_at_date = date('Y-m-d');
    }

    $sql = "SELECT 
            coa.account_code,
            coa.account_name,
            coa.account_type,
            coa.normal_balance,
            COALESCE(SUM(jd.debit_amount), 0) as total_debit,
            COALESCE(SUM(jd.credit_amount), 0) as total_credit,
            CASE 
                WHEN coa.normal_balance = 'debit' 
                THEN COALESCE(SUM(jd.debit_amount), 0) - COALESCE(SUM(jd.credit_amount), 0)
                ELSE COALESCE(SUM(jd.credit_amount), 0) - COALESCE(SUM(jd.debit_amount), 0)
            END as balance
            FROM chart_of_accounts coa
            LEFT JOIN journal_details jd ON coa.account_code = jd.account_code
            LEFT JOIN journal_entries je ON jd.journal_id = je.id 
                AND je.status = 'posted' AND je.entry_date <= ?
            WHERE coa.is_active = 1
            GROUP BY coa.id
            HAVING balance != 0
            ORDER BY coa.account_code";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $as_at_date);
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Get income statement for period
 */
function getIncomeStatement($conn, $start_date, $end_date)
{
    // Income accounts
    $income_sql = "SELECT 
                   coa.account_code,
                   coa.account_name,
                   COALESCE(SUM(jd.credit_amount - jd.debit_amount), 0) as amount
                   FROM chart_of_accounts coa
                   LEFT JOIN journal_details jd ON coa.account_code = jd.account_code
                   LEFT JOIN journal_entries je ON jd.journal_id = je.id 
                       AND je.status = 'posted' 
                       AND je.entry_date BETWEEN ? AND ?
                   WHERE coa.account_type = 'income' AND coa.is_active = 1
                   GROUP BY coa.id
                   HAVING amount != 0
                   ORDER BY coa.account_code";

    $stmt = $conn->prepare($income_sql);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $income = $stmt->get_result();

    // Expense accounts
    $expense_sql = "SELECT 
                    coa.account_code,
                    coa.account_name,
                    COALESCE(SUM(jd.debit_amount - jd.credit_amount), 0) as amount
                    FROM chart_of_accounts coa
                    LEFT JOIN journal_details jd ON coa.account_code = jd.account_code
                    LEFT JOIN journal_entries je ON jd.journal_id = je.id 
                        AND je.status = 'posted' 
                        AND je.entry_date BETWEEN ? AND ?
                    WHERE coa.account_type = 'expense' AND coa.is_active = 1
                    GROUP BY coa.id
                    HAVING amount != 0
                    ORDER BY coa.account_code";

    $stmt = $conn->prepare($expense_sql);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $expenses = $stmt->get_result();

    return ['income' => $income, 'expenses' => $expenses];
}

/**
 * Get balance sheet as at date
 */
function getBalanceSheet($conn, $as_at_date)
{
    // Assets
    $asset_sql = "SELECT 
                  coa.account_code,
                  coa.account_name,
                  coa.category,
                  CASE 
                      WHEN coa.normal_balance = 'debit' 
                      THEN COALESCE(SUM(jd.debit_amount - jd.credit_amount), 0)
                      ELSE COALESCE(SUM(jd.credit_amount - jd.debit_amount), 0)
                  END as balance
                  FROM chart_of_accounts coa
                  LEFT JOIN journal_details jd ON coa.account_code = jd.account_code
                  LEFT JOIN journal_entries je ON jd.journal_id = je.id 
                      AND je.status = 'posted' AND je.entry_date <= ?
                  WHERE coa.account_type = 'asset' AND coa.is_active = 1
                  GROUP BY coa.id
                  HAVING balance != 0
                  ORDER BY coa.category, coa.account_code";

    $stmt = $conn->prepare($asset_sql);
    $stmt->bind_param("s", $as_at_date);
    $stmt->execute();
    $assets = $stmt->get_result();

    // Liabilities
    $liability_sql = "SELECT 
                      coa.account_code,
                      coa.account_name,
                      coa.category,
                      CASE 
                          WHEN coa.normal_balance = 'credit' 
                          THEN COALESCE(SUM(jd.credit_amount - jd.debit_amount), 0)
                          ELSE COALESCE(SUM(jd.debit_amount - jd.credit_amount), 0)
                      END as balance
                      FROM chart_of_accounts coa
                      LEFT JOIN journal_details jd ON coa.account_code = jd.account_code
                      LEFT JOIN journal_entries je ON jd.journal_id = je.id 
                          AND je.status = 'posted' AND je.entry_date <= ?
                      WHERE coa.account_type = 'liability' AND coa.is_active = 1
                      GROUP BY coa.id
                      HAVING balance != 0
                      ORDER BY coa.category, coa.account_code";

    $stmt = $conn->prepare($liability_sql);
    $stmt->bind_param("s", $as_at_date);
    $stmt->execute();
    $liabilities = $stmt->get_result();

    // Equity
    $equity_sql = "SELECT 
                   coa.account_code,
                   coa.account_name,
                   coa.category,
                   CASE 
                       WHEN coa.normal_balance = 'credit' 
                       THEN COALESCE(SUM(jd.credit_amount - jd.debit_amount), 0)
                       ELSE COALESCE(SUM(jd.debit_amount - jd.credit_amount), 0)
                   END as balance
                   FROM chart_of_accounts coa
                   LEFT JOIN journal_details jd ON coa.account_code = jd.account_code
                   LEFT JOIN journal_entries je ON jd.journal_id = je.id 
                       AND je.status = 'posted' AND je.entry_date <= ?
                   WHERE coa.account_type = 'equity' AND coa.is_active = 1
                   GROUP BY coa.id
                   HAVING balance != 0
                   ORDER BY coa.category, coa.account_code";

    $stmt = $conn->prepare($equity_sql);
    $stmt->bind_param("s", $as_at_date);
    $stmt->execute();
    $equity = $stmt->get_result();

    return ['assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity];
}

/**
 * Calculate financial ratios
 */
function calculateFinancialRatios($conn, $period_date)
{
    $ratios = [];

    // Get totals
    $bs = getBalanceSheet($conn, $period_date);
    $is = getIncomeStatement($conn, date('Y-m-01', strtotime($period_date)), $period_date);

    // Calculate totals
    $total_assets = 0;
    while ($row = $bs['assets']->fetch_assoc()) {
        $total_assets += $row['balance'];
    }

    $total_liabilities = 0;
    while ($row = $bs['liabilities']->fetch_assoc()) {
        $total_liabilities += $row['balance'];
    }

    $total_equity = 0;
    while ($row = $bs['equity']->fetch_assoc()) {
        $total_equity += $row['balance'];
    }

    $total_income = 0;
    while ($row = $is['income']->fetch_assoc()) {
        $total_income += $row['amount'];
    }

    $total_expenses = 0;
    while ($row = $is['expenses']->fetch_assoc()) {
        $total_expenses += $row['amount'];
    }

    // Calculate ratios
    if ($total_liabilities > 0) {
        $ratios['debt_to_equity'] = $total_liabilities / $total_equity;
    }

    if ($total_assets > 0) {
        $ratios['return_on_assets'] = ($total_income - $total_expenses) / $total_assets;
    }

    if ($total_equity > 0) {
        $ratios['return_on_equity'] = ($total_income - $total_expenses) / $total_equity;
    }

    $ratios['profit_margin'] = $total_income > 0 ? ($total_income - $total_expenses) / $total_income : 0;

    // Save ratios
    foreach ($ratios as $name => $value) {
        $sql = "INSERT INTO financial_ratios (ratio_date, ratio_type, ratio_name, value) 
                VALUES (?, 'profitability', ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssd", $period_date, $name, $value);
        $stmt->execute();
    }

    return $ratios;
}

/**
 * Post automatic depreciation
 */
function postDepreciation($conn, $period_date)
{
    $assets_sql = "SELECT * FROM assets WHERE status = 'active' AND depreciation_method != 'none'";
    $assets = $conn->query($assets_sql);

    while ($asset = $assets->fetch_assoc()) {
        $depreciation_amount = 0;

        if ($asset['depreciation_method'] == 'straight_line' && $asset['useful_life_years'] > 0) {
            $depreciation_amount = ($asset['purchase_cost'] - $asset['salvage_value']) / $asset['useful_life_years'] / 12;
        } elseif ($asset['depreciation_method'] == 'reducing_balance' && $asset['depreciation_rate'] > 0) {
            $depreciation_amount = $asset['current_value'] * ($asset['depreciation_rate'] / 100) / 12;
        }

        if ($depreciation_amount > 0) {
            // Create journal entry for depreciation
            $lines = [
                [
                    'account' => '5100',
                    'debit' => $depreciation_amount,
                    'credit' => 0,
                    'description' => "Depreciation for {$asset['asset_name']}"
                ],
                [
                    'account' => '1310',
                    'debit' => 0,
                    'credit' => $depreciation_amount,
                    'description' => "Accumulated depreciation for {$asset['asset_name']}"
                ]
            ];

            $journal_id = createJournalEntry(
                $conn,
                $period_date,
                'adjustment',
                $asset['id'],
                "Monthly depreciation - {$asset['asset_name']}",
                $lines
            );

            // Update asset value
            $new_value = $asset['current_value'] - $depreciation_amount;
            $update_sql = "UPDATE assets SET current_value = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("di", $new_value, $asset['id']);
            $update_stmt->execute();

            // Record in depreciation schedule
            $schedule_sql = "INSERT INTO depreciation_schedule 
                            (asset_id, period_date, depreciation_amount, accumulated_depreciation, book_value, journal_id)
                            VALUES (?, ?, ?, 
                            (SELECT COALESCE(SUM(depreciation_amount), 0) FROM depreciation_schedule WHERE asset_id = ?) + ?,
                            ?, ?)";
            $schedule_stmt = $conn->prepare($schedule_sql);
            $accumulated = $depreciation_amount; // This needs proper calculation
            $schedule_stmt->bind_param(
                "isddddi",
                $asset['id'],
                $period_date,
                $depreciation_amount,
                $asset['id'],
                $depreciation_amount,
                $new_value,
                $journal_id
            );
            $schedule_stmt->execute();
        }
    }
}

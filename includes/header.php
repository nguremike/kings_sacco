<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - <?php echo $page_title ?? 'Dashboard'; ?></title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.2.2/css/buttons.bootstrap5.min.css">

    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />

    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/dashboard.css">
</head>

<body>

    <?php if (isLoggedIn()): ?>
        <!-- Top Navigation Bar -->
        <nav class="navbar navbar-expand-lg navbar-dark bg-primary fixed-top">
            <div class="container-fluid">
                <a class="navbar-brand" href="<?php echo APP_URL; ?>/dashboard.php">
                    <i class="fas fa-hand-holding-usd me-2"></i>
                    <?php echo APP_NAME; ?>
                </a>

                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                    <span class="navbar-toggler-icon"></span>
                </button>

                <div class="collapse navbar-collapse" id="navbarNav">
                    <ul class="navbar-nav ms-auto">
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                                <i class="fas fa-bell me-1"></i>
                                <span class="badge bg-danger">3</span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="#">New loan application</a></li>
                                <li><a class="dropdown-item" href="#">Payment received</a></li>
                                <li>
                                    <hr class="dropdown-divider">
                                </li>
                                <li><a class="dropdown-item" href="#">View all notifications</a></li>
                            </ul>
                        </li>

                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                                <i class="fas fa-user-circle me-1"></i>
                                <?php echo $_SESSION['user_name'] ?? 'User'; ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="#"><i class="fas fa-user me-2"></i>Profile</a></li>
                                <li><a class="dropdown-item" href="#"><i class="fas fa-cog me-2"></i>Settings</a></li>
                                <li>
                                    <hr class="dropdown-divider">
                                </li>
                                <li><a class="dropdown-item" href="<?php echo APP_URL; ?>/logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                            </ul>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>

        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-brand">
                <i class="fas fa-hand-holding-usd fa-2x"></i>
                <span>SACCO MS</span>
            </div>

            <div class="sidebar-menu">
                <!-- Main Dashboard -->
                <div class="menu-section">
                    <div class="menu-section-title">Main</div>
                    <a href="<?php echo APP_URL; ?>/dashboard.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </div>

                <!-- Membership Management -->
                <div class="menu-section">
                    <div class="menu-section-title">Membership</div>
                    <a href="<?php echo APP_URL; ?>/modules/members/index.php" class="menu-item <?php echo strpos($_SERVER['PHP_SELF'], '/members/index.php') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-users"></i>
                        <span>All Members</span>
                    </a>
                    <a href="<?php echo APP_URL; ?>/modules/members/approvals.php" class="menu-item <?php echo strpos($_SERVER['PHP_SELF'], '/members/approvals.php') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-user-check"></i>
                        <span>Member Approvals</span>
                        <?php
                        // Get pending approvals count
                        $pending_count = 0;
                        $count_result = executeQuery("SELECT COUNT(*) as count FROM members WHERE membership_status = 'pending'");
                        if ($count_result) {
                            $pending_count = $count_result->fetch_assoc()['count'];
                        }
                        ?>
                        <?php if ($pending_count > 0): ?>
                            <span class="badge bg-danger float-end"><?php echo $pending_count; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="<?php echo APP_URL; ?>/modules/members/register.php" class="menu-item <?php echo strpos($_SERVER['PHP_SELF'], '/members/register.php') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-user-plus"></i>
                        <span>Register Member</span>
                    </a>
                </div>

                <!-- Shares Management - Updated with new structure -->
                <div class="menu-section">
                    <div class="menu-section-title">Share Capital</div>
                    <a href="<?php echo APP_URL; ?>/modules/shares/index.php" class="menu-item <?php echo strpos($_SERVER['PHP_SELF'], '/shares/index.php') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-chart-pie"></i>
                        <span>Share Dashboard</span>
                    </a>
                    <a href="<?php echo APP_URL; ?>/modules/shares/contributions.php" class="menu-item <?php echo strpos($_SERVER['PHP_SELF'], '/shares/contributions.php') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-coins"></i>
                        <span>Contributions</span>
                    </a>
                    <a href="<?php echo APP_URL; ?>/modules/shares/certificates.php" class="menu-item <?php echo strpos($_SERVER['PHP_SELF'], '/shares/certificates.php') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-certificate"></i>
                        <span>Certificates</span>
                    </a>
                    <div class="menu-sub-item">
                        <span class="badge bg-info">1 Share = KES 10,000</span>
                    </div>
                </div>

                <!-- Deposits/Savings -->
                <div class="menu-section">
                    <div class="menu-section-title">Savings</div>
                    <a href="<?php echo APP_URL; ?>/modules/deposits/index.php" class="menu-item <?php echo strpos($_SERVER['PHP_SELF'], '/deposits/index.php') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-piggy-bank"></i>
                        <span>Deposits</span>
                    </a>
                    <a href="<?php echo APP_URL; ?>/modules/deposits/withdrawals.php" class="menu-item <?php echo strpos($_SERVER['PHP_SELF'], '/deposits/withdrawals.php') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span>Withdrawals</span>
                    </a>
                    <a href="<?php echo APP_URL; ?>/modules/deposits/statements.php" class="menu-item <?php echo strpos($_SERVER['PHP_SELF'], '/deposits/statements.php') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-file-invoice"></i>
                        <span>Statements</span>
                    </a>
                </div>

                <!-- Loans Management -->
                <div class="menu-section">
                    <div class="menu-section-title">Loans</div>
                    <a href="<?php echo APP_URL; ?>/modules/loans/index.php" class="menu-item <?php echo strpos($_SERVER['PHP_SELF'], '/loans/index.php') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span>All Loans</span>
                        <?php
                        // Get pending loans count
                        $pending_loans = 0;
                        $loan_count_result = executeQuery("SELECT COUNT(*) as count FROM loans WHERE status = 'pending'");
                        if ($loan_count_result) {
                            $pending_loans = $loan_count_result->fetch_assoc()['count'];
                        }
                        ?>
                        <?php if ($pending_loans > 0): ?>
                            <span class="badge bg-warning float-end"><?php echo $pending_loans; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="<?php echo APP_URL; ?>/modules/loans/apply.php" class="menu-item <?php echo strpos($_SERVER['PHP_SELF'], '/loans/apply.php') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-plus-circle"></i>
                        <span>Apply Loan</span>
                    </a>
                    <a href="<?php echo APP_URL; ?>/modules/loans/approvals.php" class="menu-item <?php echo strpos($_SERVER['PHP_SELF'], '/loans/approvals.php') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-check-circle"></i>
                        <span>Loan Approvals</span>
                    </a>
                    <a href="<?php echo APP_URL; ?>/modules/loans/repayments.php" class="menu-item <?php echo strpos($_SERVER['PHP_SELF'], '/loans/repayments.php') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-credit-card"></i>
                        <span>Repayments</span>
                    </a>
                    <a href="<?php echo APP_URL; ?>/modules/loans/guarantors.php" class="menu-item <?php echo strpos($_SERVER['PHP_SELF'], '/loans/guarantors.php') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-handshake"></i>
                        <span>Guarantors</span>
                    </a>
                </div>

                <!-- Dividends -->
                <div class="menu-section">
                    <div class="menu-section-title">Dividends</div>
                    <a href="<?php echo APP_URL; ?>/modules/dividends/index.php" class="menu-item <?php echo strpos($_SERVER['PHP_SELF'], '/dividends/index.php') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-percentage"></i>
                        <span>Dividend Management</span>
                    </a>
                    <a href="<?php echo APP_URL; ?>/modules/dividends/calculate.php" class="menu-item <?php echo strpos($_SERVER['PHP_SELF'], '/dividends/calculate.php') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-calculator"></i>
                        <span>Calculate Dividends</span>
                    </a>
                    <a href="<?php echo APP_URL; ?>/modules/dividends/payments.php" class="menu-item <?php echo strpos($_SERVER['PHP_SELF'], '/dividends/payments.php') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Dividend Payments</span>
                    </a>
                </div>

                <!-- Reports -->
                <div class="menu-section">
                    <div class="menu-section-title">Reports</div>
                    <a href="<?php echo APP_URL; ?>/modules/reports/financial.php" class="menu-item <?php echo strpos($_SERVER['PHP_SELF'], '/reports/financial.php') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-chart-line"></i>
                        <span>Financial Reports</span>
                    </a>
                    <a href="<?php echo APP_URL; ?>/modules/reports/loans.php" class="menu-item <?php echo strpos($_SERVER['PHP_SELF'], '/reports/loans.php') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-file-invoice"></i>
                        <span>Loan Reports</span>
                    </a>
                    <a href="<?php echo APP_URL; ?>/modules/reports/members.php" class="menu-item <?php echo strpos($_SERVER['PHP_SELF'], '/reports/members.php') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-address-book"></i>
                        <span>Member Reports</span>
                    </a>
                    <a href="<?php echo APP_URL; ?>/modules/reports/shares.php" class="menu-item <?php echo strpos($_SERVER['PHP_SELF'], '/reports/shares.php') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-chart-pie"></i>
                        <span>Share Reports</span>
                    </a>
                    <a href="<?php echo APP_URL; ?>/modules/reports/dividends.php" class="menu-item <?php echo strpos($_SERVER['PHP_SELF'], '/reports/dividends.php') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-percentage"></i>
                        <span>Dividend Reports</span>
                    </a>
                </div>

                <!-- Administration (Admin Only) -->
                <?php if (hasRole('admin')): ?>
                    <div class="menu-section">
                        <div class="menu-section-title">Administration</div>
                        <a href="<?php echo APP_URL; ?>/modules/settings/users.php" class="menu-item <?php echo strpos($_SERVER['PHP_SELF'], '/settings/users.php') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-user-cog"></i>
                            <span>User Management</span>
                        </a>
                        <a href="<?php echo APP_URL; ?>/modules/settings/loan-products.php" class="menu-item <?php echo strpos($_SERVER['PHP_SELF'], '/settings/loan-products.php') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-box"></i>
                            <span>Loan Products</span>
                        </a>
                        <a href="<?php echo APP_URL; ?>/modules/settings/fees.php" class="menu-item <?php echo strpos($_SERVER['PHP_SELF'], '/settings/fees.php') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-coins"></i>
                            <span>Registration Fees</span>
                        </a>
                        <a href="<?php echo APP_URL; ?>/modules/settings/audit-logs.php" class="menu-item <?php echo strpos($_SERVER['PHP_SELF'], '/settings/audit-logs.php') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-history"></i>
                            <span>Audit Logs</span>
                        </a>
                        <a href="<?php echo APP_URL; ?>/modules/settings/backup.php" class="menu-item <?php echo strpos($_SERVER['PHP_SELF'], '/settings/backup.php') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-database"></i>
                            <span>Backup & Restore</span>
                        </a>
                        <a href="<?php echo APP_URL; ?>/modules/settings/system.php" class="menu-item <?php echo strpos($_SERVER['PHP_SELF'], '/settings/system.php') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-cog"></i>
                            <span>System Settings</span>
                        </a>
                    </div>
                <?php endif; ?>

                <!-- Member Portal (For logged in members) -->
                <?php if (hasRole('member')): ?>
                    <div class="menu-section">
                        <div class="menu-section-title">My Account</div>
                        <a href="<?php echo APP_URL; ?>/member/dashboard.php" class="menu-item">
                            <i class="fas fa-tachometer-alt"></i>
                            <span>My Dashboard</span>
                        </a>
                        <a href="<?php echo APP_URL; ?>/member/profile.php" class="menu-item">
                            <i class="fas fa-user"></i>
                            <span>My Profile</span>
                        </a>
                        <a href="<?php echo APP_URL; ?>/member/shares.php" class="menu-item">
                            <i class="fas fa-chart-pie"></i>
                            <span>My Shares</span>
                        </a>
                        <a href="<?php echo APP_URL; ?>/member/deposits.php" class="menu-item">
                            <i class="fas fa-piggy-bank"></i>
                            <span>My Savings</span>
                        </a>
                        <a href="<?php echo APP_URL; ?>/member/loans.php" class="menu-item">
                            <i class="fas fa-hand-holding-usd"></i>
                            <span>My Loans</span>
                        </a>
                        <a href="<?php echo APP_URL; ?>/member/dividends.php" class="menu-item">
                            <i class="fas fa-percentage"></i>
                            <span>My Dividends</span>
                        </a>
                    </div>
                <?php endif; ?>

                <!-- Quick Actions -->
                <div class="menu-section">
                    <div class="menu-section-title">Quick Actions</div>
                    <a href="<?php echo APP_URL; ?>/modules/members/register.php" class="menu-item">
                        <i class="fas fa-user-plus"></i>
                        <span>Register Member</span>
                    </a>
                    <a href="<?php echo APP_URL; ?>/modules/shares/contributions.php" class="menu-item">
                        <i class="fas fa-coins"></i>
                        <span>Add Contribution</span>
                    </a>
                    <a href="<?php echo APP_URL; ?>/modules/deposits/add.php" class="menu-item">
                        <i class="fas fa-plus-circle"></i>
                        <span>Record Deposit</span>
                    </a>
                    <a href="<?php echo APP_URL; ?>/modules/loans/apply.php" class="menu-item">
                        <i class="fas fa-file-signature"></i>
                        <span>New Loan</span>
                    </a>
                </div>

                <!-- Help & Support -->
                <div class="menu-section">
                    <div class="menu-section-title">Support</div>
                    <a href="#" class="menu-item" onclick="showHelp()">
                        <i class="fas fa-question-circle"></i>
                        <span>Help Guide</span>
                    </a>
                    <a href="#" class="menu-item" onclick="showSupport()">
                        <i class="fas fa-headset"></i>
                        <span>Contact Support</span>
                    </a>
                    <a href="#" class="menu-item" onclick="showAbout()">
                        <i class="fas fa-info-circle"></i>
                        <span>About</span>
                    </a>
                </div>
            </div>

            <!-- Sidebar Footer -->
            <div class="sidebar-footer">
                <div class="version">
                    <small>Version <?php echo APP_VERSION; ?></small>
                </div>
            </div>
        </div>

        <!-- Main Content Wrapper -->
        <div class="main-content">
        <?php endif; ?>

        <script>
            // Helper functions for sidebar actions
            function showHelp() {
                Swal.fire({
                    title: 'Help Guide',
                    html: `
            <div class="text-start">
                <h6>Quick Tips:</h6>
                <ul>
                    <li>Use the sidebar to navigate between modules</li>
                    <li>Registration requires KES 2,000 + KES 400 bylaws</li>
                    <li>1 share = KES 10,000 (can be paid in installments)</li>
                    <li>Loans require minimum 3 guarantors</li>
                </ul>
                <p class="mt-2">For detailed help, contact system administrator.</p>
            </div>
        `,
                    icon: 'info'
                });
            }

            function showSupport() {
                Swal.fire({
                    title: 'Contact Support',
                    html: `
            <div class="text-start">
                <p><i class="fas fa-phone me-2"></i> +254 700 000 000</p>
                <p><i class="fas fa-envelope me-2"></i> support@sacco.co.ke</p>
                <p><i class="fas fa-clock me-2"></i> Mon-Fri: 8:00 AM - 5:00 PM</p>
            </div>
        `,
                    icon: 'info'
                });
            }

            function showAbout() {
                Swal.fire({
                    title: 'About <?php echo APP_NAME; ?>',
                    html: `
            <div class="text-center">
                <i class="fas fa-hand-holding-usd fa-4x text-primary mb-3"></i>
                <h5><?php echo APP_NAME; ?></h5>
                <p>Version <?php echo APP_VERSION; ?></p>
                <p>A comprehensive SACCO Management System</p>
                <hr>
                <p class="text-muted">© <?php echo date('Y'); ?> All rights reserved</p>
            </div>
        `,
                    icon: 'info'
                });
            }
        </script>

        <style>
            /* Additional sidebar styles */
            .sidebar-footer {
                position: absolute;
                bottom: 0;
                width: 100%;
                padding: 15px 20px;
                border-top: 1px solid #e9ecef;
                background: white;
                font-size: 12px;
                color: #6c757d;
                text-align: center;
            }

            .menu-sub-item {
                padding: 8px 20px 8px 54px;
                font-size: 12px;
                color: #6c757d;
            }

            .sidebar-menu {
                padding-bottom: 70px;
                /* Space for footer */
            }

            /* Badge styles in sidebar */
            .menu-item .badge {
                margin-top: 3px;
                font-size: 10px;
                padding: 3px 6px;
            }

            /* Active state */
            .menu-item.active {
                background: rgba(67, 97, 238, 0.1);
                color: var(--primary-color);
                border-left-color: var(--primary-color);
                font-weight: 600;
            }

            .menu-item.active i {
                color: var(--primary-color);
            }

            /* Hover effects */
            .menu-item:hover {
                background: rgba(67, 97, 238, 0.05);
                padding-left: 23px;
                transition: all 0.3s;
            }

            /* Scrollbar styling */
            .sidebar::-webkit-scrollbar {
                width: 5px;
            }

            .sidebar::-webkit-scrollbar-track {
                background: #f1f1f1;
            }

            .sidebar::-webkit-scrollbar-thumb {
                background: #c1c1c1;
                border-radius: 5px;
            }

            .sidebar::-webkit-scrollbar-thumb:hover {
                background: #a8a8a8;
            }
        </style>
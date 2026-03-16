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
                    <!-- <i class="fas fa-hand-holding-usd me-2"></i> -->
                    <i class="fa-solid fa-chess-king"></i>
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
        <div class="sidebar  mt-2" id="sidebar">

            <div class="sidebar-brand align-items-center px-3 py-2 ">
                <!-- <i class="fas fa-hand-holding-usd"></i> -->
                <i class="fa-solid fa-chess-king"></i>
                <span> KINGS SACCO</span>
            </div>

            <div class="sidebar-menu">

                <!-- Dashboard -->
                <a href="<?php echo APP_URL; ?>/dashboard.php" class="menu-item">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>

                <!-- MEMBERSHIP -->
                <div class="menu-group">

                    <a class="menu-item menu-toggle">
                        <i class="fas fa-users"></i>
                        <span>Membership</span>
                        <i class="fas fa-chevron-down arrow"></i>
                    </a>

                    <div class="submenu">

                        <a href="<?php echo APP_URL; ?>/modules/members/index.php" class="submenu-item">
                            <i class="fas fa-list"></i> All Members
                        </a>

                        <a href="<?php echo APP_URL; ?>/modules/members/approvals.php" class="submenu-item">
                            <i class="fas fa-user-check"></i> Member Approvals
                        </a>

                        <a href="<?php echo APP_URL; ?>/modules/members/register.php" class="submenu-item">
                            <i class="fas fa-user-plus"></i> Register Member
                        </a>

                        <a href="<?php echo APP_URL; ?>/modules/members/import.php" class="submenu-item">
                            <i class="fas fa-file-import"></i> Import Members
                        </a>

                    </div>
                </div>


                <!-- SHARES -->
                <div class="menu-group">

                    <a class="menu-item menu-toggle">
                        <i class="fas fa-chart-pie"></i>
                        <span>Share Capital</span>
                        <i class="fas fa-chevron-down arrow"></i>
                    </a>

                    <div class="submenu">

                        <a href="<?php echo APP_URL; ?>/modules/shares/index.php" class="submenu-item">
                            <i class="fas fa-chart-line"></i> Share Dashboard
                        </a>

                        <a href="<?php echo APP_URL; ?>/modules/shares/contributions.php" class="submenu-item">
                            <i class="fas fa-coins"></i> Contributions
                        </a>

                        <a href="<?php echo APP_URL; ?>/modules/shares/certificates.php" class="submenu-item">
                            <i class="fas fa-certificate"></i> Certificates
                        </a>

                    </div>
                </div>


                <!-- SAVINGS -->
                <div class="menu-group">

                    <a class="menu-item menu-toggle">
                        <i class="fas fa-piggy-bank"></i>
                        <span>Savings</span>
                        <i class="fas fa-chevron-down arrow"></i>
                    </a>

                    <div class="submenu">

                        <a href="<?php echo APP_URL; ?>/modules/deposits/index.php" class="submenu-item">
                            <i class="fas fa-wallet"></i> Deposits
                        </a>

                        <a href="<?php echo APP_URL; ?>/modules/deposits/withdrawals.php" class="submenu-item">
                            <i class="fas fa-hand-holding-usd"></i> Withdrawals
                        </a>

                        <a href="<?php echo APP_URL; ?>/modules/deposits/statements.php" class="submenu-item">
                            <i class="fas fa-file-invoice"></i> Statements
                        </a>

                    </div>
                </div>


                <!-- LOANS -->
                <div class="menu-group">

                    <a class="menu-item menu-toggle">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span>Loans</span>
                        <i class="fas fa-chevron-down arrow"></i>
                    </a>

                    <div class="submenu">

                        <a href="<?php echo APP_URL; ?>/modules/loans/index.php" class="submenu-item">
                            <i class="fas fa-list"></i> All Loans
                        </a>

                        <a href="<?php echo APP_URL; ?>/modules/loans/apply.php" class="submenu-item">
                            <i class="fas fa-plus-circle"></i> Apply Loan
                        </a>

                        <a href="<?php echo APP_URL; ?>/modules/loans/approvals.php" class="submenu-item">
                            <i class="fas fa-check-circle"></i> Loan Approvals
                        </a>

                        <a href="<?php echo APP_URL; ?>/modules/loans/repayments.php" class="submenu-item">
                            <i class="fas fa-credit-card"></i> Repayments
                        </a>

                        <a href="<?php echo APP_URL; ?>/modules/loans/guarantors.php" class="submenu-item">
                            <i class="fas fa-handshake"></i> Guarantors
                        </a>

                    </div>
                </div>


                <!-- DIVIDENDS -->
                <div class="menu-group">

                    <a class="menu-item menu-toggle">
                        <i class="fas fa-percentage"></i>
                        <span>Dividends</span>
                        <i class="fas fa-chevron-down arrow"></i>
                    </a>

                    <div class="submenu">

                        <a href="<?php echo APP_URL; ?>/modules/dividends/index.php" class="submenu-item">
                            <i class="fas fa-chart-line"></i> Dashboard
                        </a>

                        <a href="<?php echo APP_URL; ?>/modules/dividends/calculate.php" class="submenu-item">
                            <i class="fas fa-calculator"></i> Standard Method
                        </a>

                        <a href="<?php echo APP_URL; ?>/modules/dividends/calculate-pro-rata.php" class="submenu-item">
                            <i class="fas fa-chart-area"></i> Pro-rata Method
                        </a>

                        <a href="<?php echo APP_URL; ?>/modules/dividends/payments.php" class="submenu-item">
                            <i class="fas fa-money-bill-wave"></i> Payments
                        </a>

                        <a href="<?php echo APP_URL; ?>/modules/dividends/reports.php" class="submenu-item">
                            <i class="fas fa-file-alt"></i> Reports
                        </a>

                    </div>
                </div>


                <!-- ACCOUNTING -->
                <div class="menu-group">

                    <a class="menu-item menu-toggle">
                        <i class="fas fa-book"></i>
                        <span>Accounting</span>
                        <i class="fas fa-chevron-down arrow"></i>
                    </a>

                    <div class="submenu">

                        <a href="<?php echo APP_URL; ?>/modules/accounting/index.php" class="submenu-item">
                            <i class="fas fa-chart-line"></i> Dashboard
                        </a>

                        <a href="<?php echo APP_URL; ?>/modules/admin_charges/index.php" class="submenu-item">
                            <i class="fa-solid fa-file-invoice-dollar"></i> Admin Charges
                        </a>

                        <a href="<?php echo APP_URL; ?>/modules/accounting/year-end-processing.php" class="submenu-item">
                            <i class="fas fa-chart-area"></i> Year End Processing
                        </a>



                        <a href="<?php echo APP_URL; ?>/modules/penalties/index.php" class="submenu-item">
                            <i class="fas fa-book-open"></i> Penalties
                        </a>

                        <a href="<?php echo APP_URL; ?>/modules/accounting/expenses.php" class="submenu-item">
                            <i class="fas fa-receipt"></i> Expenses
                        </a>

                        <a href="<?php echo APP_URL; ?>/modules/accounting/income.php" class="submenu-item">
                            <i class="fas fa-money-bill-wave"></i> Income
                        </a>



                    </div>
                </div>


                <!-- REPORTS -->
                <div class="menu-group">

                    <a class="menu-item menu-toggle">
                        <i class="fas fa-chart-bar"></i>
                        <span>Reports</span>
                        <i class="fas fa-chevron-down arrow"></i>
                    </a>

                    <div class="submenu">

                        <a href="<?php echo APP_URL; ?>/modules/reports/financial.php" class="submenu-item">
                            <i class="fas fa-chart-line"></i> Financial Reports
                        </a>

                        <a href="<?php echo APP_URL; ?>/modules/reports/loans.php" class="submenu-item">
                            <i class="fas fa-file-invoice"></i> Loan Reports
                        </a>

                        <a href="<?php echo APP_URL; ?>/modules/reports/members.php" class="submenu-item">
                            <i class="fas fa-users"></i> Member Reports
                        </a>

                        <a href="<?php echo APP_URL; ?>/modules/reports/dividends.php" class="submenu-item">
                            <i class="fas fa-percentage"></i> Dividend Reports
                        </a>

                    </div>
                </div>


                <!-- SETTINGS -->
                <div class="menu-group">

                    <a class="menu-item menu-toggle">
                        <i class="fas fa-cog"></i>
                        <span>Administration</span>
                        <i class="fas fa-chevron-down arrow"></i>
                    </a>

                    <div class="submenu">

                        <a href="<?php echo APP_URL; ?>/modules/settings/users.php" class="submenu-item">
                            <i class="fas fa-user-cog"></i> Users
                        </a>

                        <a href="<?php echo APP_URL; ?>/modules/settings/loan-products.php" class="submenu-item">
                            <i class="fas fa-box"></i> Loan Products
                        </a>

                        <a href="<?php echo APP_URL; ?>/modules/settings/system.php" class="submenu-item">
                            <i class="fas fa-sliders-h"></i> System Settings
                        </a>

                    </div>
                </div>


                <!-- INITIALIZATION -->
                <div class="menu-group">

                    <a class="menu-item menu-toggle">
                        <i class="fas fa-database"></i>
                        <span>Initialization</span>
                        <i class="fas fa-chevron-down arrow"></i>
                    </a>

                    <div class="submenu">

                        <a href="<?php echo APP_URL; ?>/modules/initialization/opening-balances.php" class="submenu-item">
                            <i class="fas fa-table"></i> Opening Balances
                        </a>

                        <a href="<?php echo APP_URL; ?>/modules/initialization/import-share-contributions.php" class="submenu-item">
                            <i class="fas fa-coins"></i> Import Shares
                        </a>

                    </div>
                </div>


            </div>
        </div>

        <!-- Main Content Wrapper -->
        <div class="main-content">
        <?php endif; ?>

        <style>
            /* Additional sidebar styles for accounting and dividends sections */
            .menu-sub-section {
                margin-left: 15px;
                margin-top: 5px;
                margin-bottom: 5px;
                border-left: 1px dashed #dee2e6;
            }

            .menu-sub-title {
                padding: 5px 15px;
                font-size: 11px;
                text-transform: uppercase;
                color: #6c757d;
                font-weight: 600;
                letter-spacing: 0.5px;
            }

            .menu-item.sub-item {
                padding-left: 45px;
                font-size: 13px;
            }

            .menu-item.sub-item i {
                width: 20px;
                font-size: 12px;
            }

            .menu-item.sub-item small {
                font-size: 10px;
                color: #6c757d;
            }

            .sidebar-footer {
                position: absolute;
                bottom: 0;
                width: 100%;
                padding: 10px 20px;
                border-top: 1px solid #e9ecef;
                background: white;
                font-size: 11px;
                color: #6c757d;
                text-align: center;
            }

            .sidebar-footer .financial-period {
                margin-top: 3px;
                color: #28a745;
                font-weight: 500;
            }

            .sidebar-footer .dividend-method {
                border-top: 1px dotted #dee2e6;
                padding-top: 5px;
                margin-top: 5px;
            }

            .menu-sub-item {
                padding: 8px 20px 8px 54px;
                font-size: 12px;
                color: #6c757d;
            }

            .sidebar-menu {
                padding-bottom: 100px;
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

            /* Dividend section specific colors */
            .menu-item [class*="fa-percentage"] {
                color: #6f42c1;
            }

            .menu-item [class*="fa-calculator"] {
                color: #fd7e14;
            }

            .menu-item [class*="fa-chart-line"] {
                color: #20c997;
            }

            .menu-item [class*="fa-balance-scale"] {
                color: #17a2b8;
            }

            /* NEW badge animation */
            .badge.bg-success {
                animation: pulse 2s infinite;
            }

            @keyframes pulse {
                0% {
                    opacity: 1;
                }

                50% {
                    opacity: 0.7;
                }

                100% {
                    opacity: 1;
                }
            }

            /* Tooltip for method descriptions */
            .menu-item.sub-item:hover small {
                color: var(--primary-color);
            }
        </style>

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
                    <li>Loans require minimum 3 guarantors or self-guarantee</li>
                    <li><strong>Dividend Methods:</strong> Two calculation methods available</li>
                    <ul>
                        <li><strong>Standard:</strong> Full year basis</li>
                        <li><strong>Pro-rata:</strong> Monthly weighted (Dec joiners excluded)</li>
                    </ul>
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
                <p>A comprehensive SACCO Management System with full accounting</p>
                <p><strong>Dividend Methods:</strong> Standard & Pro-rata</p>
                <hr>
                <p class="text-muted">© <?php echo date('Y'); ?> All rights reserved</p>
            </div>
        `,
                    icon: 'info'
                });
            }

            // Tooltip initialization for method descriptions
            document.addEventListener('DOMContentLoaded', function() {
                var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
                var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
                    return new bootstrap.Tooltip(tooltipTriggerEl);
                });
            });
        </script>
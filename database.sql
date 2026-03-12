-- database.sql
CREATE DATABASE IF NOT EXISTS kings_sacco;
USE kings_sacco;

-- Users table
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    full_name VARCHAR(100),
    role ENUM('admin', 'officer', 'accountant', 'member') DEFAULT 'member',
    status TINYINT(1) DEFAULT 1,
    last_login DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Members table
CREATE TABLE members (
    id INT PRIMARY KEY AUTO_INCREMENT,
    member_no VARCHAR(20) UNIQUE NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    national_id VARCHAR(20) UNIQUE NOT NULL,
    phone VARCHAR(15) NOT NULL,
    email VARCHAR(100),
    address TEXT,
    date_joined DATE NOT NULL,
    membership_status ENUM('pending', 'active', 'suspended', 'closed') DEFAULT 'pending',
    kyc_documents TEXT,
    user_id INT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Shares table
CREATE TABLE shares (
    id INT PRIMARY KEY AUTO_INCREMENT,
    member_id INT NOT NULL,
    shares_count INT NOT NULL,
    share_value DECIMAL(10,2) NOT NULL,
    total_value DECIMAL(10,2) GENERATED ALWAYS AS (shares_count * share_value) STORED,
    transaction_type ENUM('purchase', 'transfer', 'refund') NOT NULL,
    reference_no VARCHAR(50),
    date_purchased DATE NOT NULL,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Deposits table
CREATE TABLE deposits (
    id INT PRIMARY KEY AUTO_INCREMENT,
    member_id INT NOT NULL,
    deposit_date DATE NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    balance DECIMAL(10,2) NOT NULL,
    transaction_type ENUM('deposit', 'withdrawal', 'interest') NOT NULL,
    reference_no VARCHAR(50),
    description TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Loan products table
CREATE TABLE loan_products (
    id INT PRIMARY KEY AUTO_INCREMENT,
    product_name VARCHAR(50) NOT NULL,
    interest_rate DECIMAL(5,2) NOT NULL,
    max_duration_months INT NOT NULL,
    min_amount DECIMAL(10,2),
    max_amount DECIMAL(10,2),
    description TEXT,
    status TINYINT(1) DEFAULT 1
);

-- Loans table
CREATE TABLE loans (
    id INT PRIMARY KEY AUTO_INCREMENT,
    loan_no VARCHAR(20) UNIQUE NOT NULL,
    member_id INT NOT NULL,
    product_id INT NOT NULL,
    principal_amount DECIMAL(10,2) NOT NULL,
    interest_amount DECIMAL(10,2) NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    duration_months INT NOT NULL,
    interest_rate DECIMAL(5,2) NOT NULL,
    application_date DATE NOT NULL,
    approval_date DATE,
    disbursement_date DATE,
    first_payment_date DATE,
    status ENUM('pending', 'guarantor_pending', 'approved', 'disbursed', 'active', 'completed', 'defaulted', 'rejected') DEFAULT 'pending',
    created_by INT,
    approved_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id),
    FOREIGN KEY (product_id) REFERENCES loan_products(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
);

-- Loan guarantors table
CREATE TABLE loan_guarantors (
    id INT PRIMARY KEY AUTO_INCREMENT,
    loan_id INT NOT NULL,
    guarantor_member_id INT NOT NULL,
    guaranteed_amount DECIMAL(10,2) NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    approval_date DATE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (guarantor_member_id) REFERENCES members(id)
);

-- Loan repayments table
CREATE TABLE loan_repayments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    loan_id INT NOT NULL,
    payment_date DATE NOT NULL,
    amount_paid DECIMAL(10,2) NOT NULL,
    principal_paid DECIMAL(10,2) NOT NULL,
    interest_paid DECIMAL(10,2) NOT NULL,
    penalty_paid DECIMAL(10,2) DEFAULT 0,
    balance DECIMAL(10,2) NOT NULL,
    payment_method ENUM('cash', 'bank', 'mpesa', 'mobile') NOT NULL,
    reference_no VARCHAR(50),
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Amortization schedule table
CREATE TABLE amortization_schedule (
    id INT PRIMARY KEY AUTO_INCREMENT,
    loan_id INT NOT NULL,
    installment_no INT NOT NULL,
    due_date DATE NOT NULL,
    principal DECIMAL(10,2) NOT NULL,
    interest DECIMAL(10,2) NOT NULL,
    total_payment DECIMAL(10,2) NOT NULL,
    balance DECIMAL(10,2) NOT NULL,
    status ENUM('pending', 'paid', 'overdue') DEFAULT 'pending',
    paid_date DATE,
    FOREIGN KEY (loan_id) REFERENCES loans(id)
);

-- Dividends table
CREATE TABLE dividends (
    id INT PRIMARY KEY AUTO_INCREMENT,
    member_id INT NOT NULL,
    financial_year YEAR NOT NULL,
    opening_balance DECIMAL(10,2) NOT NULL,
    total_deposits DECIMAL(10,2) NOT NULL,
    interest_rate DECIMAL(5,2) NOT NULL,
    gross_dividend DECIMAL(10,2) NOT NULL,
    withholding_tax DECIMAL(10,2) NOT NULL,
    net_dividend DECIMAL(10,2) NOT NULL,
    status ENUM('calculated', 'approved', 'paid') DEFAULT 'calculated',
    payment_date DATE,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Transactions table (for double-entry accounting)
CREATE TABLE transactions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    transaction_no VARCHAR(50) UNIQUE NOT NULL,
    transaction_date DATE NOT NULL,
    description TEXT,
    debit_account VARCHAR(50) NOT NULL,
    credit_account VARCHAR(50) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    reference_type ENUM('deposit', 'withdrawal', 'loan', 'repayment', 'dividend', 'share') NOT NULL,
    reference_id INT NOT NULL,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Notifications table
CREATE TABLE notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    member_id INT,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('sms', 'email', 'app') NOT NULL,
    status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
    sent_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (member_id) REFERENCES members(id)
);

-- Audit logs table
CREATE TABLE audit_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action VARCHAR(50) NOT NULL,
    table_name VARCHAR(50),
    record_id INT,
    old_data TEXT,
    new_data TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Insert default data
INSERT INTO loan_products (product_name, interest_rate, max_duration_months, min_amount, max_amount, description) VALUES
('Normal Loan', 12.00, 24, 10000, 500000, 'Standard loan product for general purposes'),
('Emergency Loan', 10.00, 12, 5000, 100000, 'Quick loan for emergencies'),
('Development Loan', 14.00, 36, 50000, 1000000, 'Long-term loan for development projects');

INSERT INTO users (username, password, full_name, role) VALUES
('admin', '$2y$10$YourHashedPasswordHere', 'System Administrator', 'admin');
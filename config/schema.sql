-- ============================================================
--  ZOHOBOOKS REPLICA - Full Database Schema
-- ============================================================

CREATE DATABASE IF NOT EXISTS zohobooks CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE zohobooks;

-- ─── ACCESS CONTROL ─────────────────────────────────────────
CREATE TABLE roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL UNIQUE,
  description TEXT,
  permissions JSON,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  phone VARCHAR(30),
  department VARCHAR(100),
  employee_id VARCHAR(50),
  avatar VARCHAR(255),
  status ENUM('active','inactive','suspended') DEFAULT 'active',
  last_login TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (role_id) REFERENCES roles(id)
);

-- ─── CONTACTS ───────────────────────────────────────────────
CREATE TABLE contacts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type ENUM('customer','vendor','both') NOT NULL DEFAULT 'customer',
  company_name VARCHAR(200),
  first_name VARCHAR(100),
  last_name VARCHAR(100),
  email VARCHAR(150),
  phone VARCHAR(30),
  mobile VARCHAR(30),
  website VARCHAR(255),
  tax_id VARCHAR(100),
  currency VARCHAR(10) DEFAULT 'NGN',
  payment_terms INT DEFAULT 30,
  billing_address TEXT,
  shipping_address TEXT,
  city VARCHAR(100),
  state VARCHAR(100),
  country VARCHAR(100) DEFAULT 'Nigeria',
  zip VARCHAR(20),
  notes TEXT,
  status ENUM('active','inactive') DEFAULT 'active',
  balance DECIMAL(15,2) DEFAULT 0.00,
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id)
);

-- ─── CHART OF ACCOUNTS ──────────────────────────────────────
CREATE TABLE account_groups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  type ENUM('asset','liability','equity','income','expense') NOT NULL,
  description TEXT
);

CREATE TABLE accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  group_id INT,
  account_code VARCHAR(20) NOT NULL UNIQUE,
  account_name VARCHAR(200) NOT NULL,
  account_type ENUM('asset','liability','equity','income','expense') NOT NULL,
  sub_type VARCHAR(100),
  description TEXT,
  currency VARCHAR(10) DEFAULT 'NGN',
  balance DECIMAL(15,2) DEFAULT 0.00,
  is_system TINYINT(1) DEFAULT 0,
  status ENUM('active','inactive') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (group_id) REFERENCES account_groups(id)
);

-- ─── INVOICES ───────────────────────────────────────────────
CREATE TABLE invoices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_number VARCHAR(50) NOT NULL UNIQUE,
  contact_id INT NOT NULL,
  issue_date DATE NOT NULL,
  due_date DATE NOT NULL,
  status ENUM('draft','sent','partially_paid','paid','overdue','cancelled','void') DEFAULT 'draft',
  currency VARCHAR(10) DEFAULT 'NGN',
  exchange_rate DECIMAL(15,6) DEFAULT 1.000000,
  subtotal DECIMAL(15,2) DEFAULT 0.00,
  discount_type ENUM('percentage','fixed') DEFAULT 'percentage',
  discount_value DECIMAL(10,2) DEFAULT 0.00,
  discount_amount DECIMAL(15,2) DEFAULT 0.00,
  tax_amount DECIMAL(15,2) DEFAULT 0.00,
  shipping_charge DECIMAL(15,2) DEFAULT 0.00,
  total DECIMAL(15,2) DEFAULT 0.00,
  amount_paid DECIMAL(15,2) DEFAULT 0.00,
  balance_due DECIMAL(15,2) DEFAULT 0.00,
  notes TEXT,
  terms TEXT,
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (contact_id) REFERENCES contacts(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE invoice_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT NOT NULL,
  item_id INT,
  description VARCHAR(500) NOT NULL,
  quantity DECIMAL(15,3) NOT NULL DEFAULT 1.000,
  unit VARCHAR(50),
  unit_price DECIMAL(15,2) NOT NULL,
  discount_percent DECIMAL(5,2) DEFAULT 0.00,
  tax_percent DECIMAL(5,2) DEFAULT 0.00,
  tax_amount DECIMAL(15,2) DEFAULT 0.00,
  amount DECIMAL(15,2) NOT NULL,
  FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
);

CREATE TABLE invoice_payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT NOT NULL,
  payment_date DATE NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  payment_mode ENUM('cash','bank_transfer','cheque','card','online') DEFAULT 'bank_transfer',
  reference VARCHAR(200),
  account_id INT,
  notes TEXT,
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (invoice_id) REFERENCES invoices(id),
  FOREIGN KEY (account_id) REFERENCES accounts(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
);

-- ─── EXPENSES & BILLS ───────────────────────────────────────
CREATE TABLE expense_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  account_id INT,
  description TEXT,
  FOREIGN KEY (account_id) REFERENCES accounts(id)
);

CREATE TABLE expenses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  expense_number VARCHAR(50) NOT NULL UNIQUE,
  contact_id INT,
  category_id INT,
  account_id INT,
  expense_date DATE NOT NULL,
  due_date DATE,
  type ENUM('expense','bill') DEFAULT 'expense',
  status ENUM('draft','pending','approved','paid','overdue','cancelled') DEFAULT 'draft',
  reference VARCHAR(200),
  currency VARCHAR(10) DEFAULT 'NGN',
  subtotal DECIMAL(15,2) DEFAULT 0.00,
  tax_amount DECIMAL(15,2) DEFAULT 0.00,
  total DECIMAL(15,2) NOT NULL,
  amount_paid DECIMAL(15,2) DEFAULT 0.00,
  balance_due DECIMAL(15,2) DEFAULT 0.00,
  description TEXT,
  notes TEXT,
  receipt_path VARCHAR(500),
  is_recurring TINYINT(1) DEFAULT 0,
  recurring_frequency VARCHAR(50),
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (contact_id) REFERENCES contacts(id),
  FOREIGN KEY (category_id) REFERENCES expense_categories(id),
  FOREIGN KEY (account_id) REFERENCES accounts(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE expense_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  expense_id INT NOT NULL,
  description VARCHAR(500) NOT NULL,
  quantity DECIMAL(15,3) DEFAULT 1.000,
  unit_price DECIMAL(15,2) NOT NULL,
  tax_percent DECIMAL(5,2) DEFAULT 0.00,
  tax_amount DECIMAL(15,2) DEFAULT 0.00,
  amount DECIMAL(15,2) NOT NULL,
  FOREIGN KEY (expense_id) REFERENCES expenses(id) ON DELETE CASCADE
);

-- ─── INVENTORY / ITEMS ──────────────────────────────────────
CREATE TABLE item_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  description TEXT
);

CREATE TABLE items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT,
  sku VARCHAR(100) UNIQUE,
  name VARCHAR(200) NOT NULL,
  description TEXT,
  type ENUM('product','service') DEFAULT 'product',
  unit VARCHAR(50),
  selling_price DECIMAL(15,2) DEFAULT 0.00,
  cost_price DECIMAL(15,2) DEFAULT 0.00,
  tax_percent DECIMAL(5,2) DEFAULT 0.00,
  track_inventory TINYINT(1) DEFAULT 0,
  opening_stock DECIMAL(15,3) DEFAULT 0.000,
  current_stock DECIMAL(15,3) DEFAULT 0.000,
  reorder_point DECIMAL(15,3) DEFAULT 0.000,
  account_id INT,
  status ENUM('active','inactive') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES item_categories(id),
  FOREIGN KEY (account_id) REFERENCES accounts(id)
);

CREATE TABLE stock_adjustments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  adjustment_date DATE NOT NULL,
  type ENUM('increase','decrease','damage','return') NOT NULL,
  quantity DECIMAL(15,3) NOT NULL,
  reason TEXT,
  reference VARCHAR(200),
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (item_id) REFERENCES items(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
);

-- ─── JOURNAL ENTRIES ────────────────────────────────────────
CREATE TABLE journal_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  journal_number VARCHAR(50) NOT NULL UNIQUE,
  entry_date DATE NOT NULL,
  reference VARCHAR(200),
  notes TEXT,
  status ENUM('draft','published') DEFAULT 'draft',
  total_debit DECIMAL(15,2) DEFAULT 0.00,
  total_credit DECIMAL(15,2) DEFAULT 0.00,
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE journal_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  journal_id INT NOT NULL,
  account_id INT NOT NULL,
  description VARCHAR(500),
  debit DECIMAL(15,2) DEFAULT 0.00,
  credit DECIMAL(15,2) DEFAULT 0.00,
  FOREIGN KEY (journal_id) REFERENCES journal_entries(id) ON DELETE CASCADE,
  FOREIGN KEY (account_id) REFERENCES accounts(id)
);

-- ─── BANK ACCOUNTS & RECONCILIATION ────────────────────────
CREATE TABLE bank_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_id INT NOT NULL,
  bank_name VARCHAR(200) NOT NULL,
  account_number VARCHAR(100),
  account_holder VARCHAR(200),
  branch VARCHAR(200),
  ifsc_swift VARCHAR(100),
  currency VARCHAR(10) DEFAULT 'NGN',
  opening_balance DECIMAL(15,2) DEFAULT 0.00,
  current_balance DECIMAL(15,2) DEFAULT 0.00,
  last_reconciled_date DATE,
  status ENUM('active','inactive') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (account_id) REFERENCES accounts(id)
);

CREATE TABLE bank_transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bank_account_id INT NOT NULL,
  transaction_date DATE NOT NULL,
  description VARCHAR(500),
  reference VARCHAR(200),
  type ENUM('debit','credit') NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  balance DECIMAL(15,2),
  is_reconciled TINYINT(1) DEFAULT 0,
  reconciled_date DATE,
  matched_type VARCHAR(50),
  matched_id INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id)
);

-- ─── STAFF (HR MODULE) ──────────────────────────────────────
CREATE TABLE departments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  manager_id INT,
  description TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE staff (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT,
  employee_id VARCHAR(50) UNIQUE,
  department_id INT,
  photo VARCHAR(255),
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  email VARCHAR(150) UNIQUE,
  phone VARCHAR(30),
  gender ENUM('male','female','other'),
  date_of_birth DATE,
  hire_date DATE,
  job_title VARCHAR(150),
  employment_type ENUM('full_time','part_time','contract','intern') DEFAULT 'full_time',
  salary DECIMAL(15,2) DEFAULT 0.00,
  salary_type ENUM('monthly','weekly','daily','hourly') DEFAULT 'monthly',
  bank_name VARCHAR(200),
  account_number VARCHAR(100),
  address TEXT,
  city VARCHAR(100),
  state VARCHAR(100),
  country VARCHAR(100) DEFAULT 'Nigeria',
  emergency_contact_name VARCHAR(200),
  emergency_contact_phone VARCHAR(30),
  status ENUM('active','inactive','terminated','on_leave') DEFAULT 'active',
  notes TEXT,
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (department_id) REFERENCES departments(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE app_settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(100) NOT NULL UNIQUE,
  setting_value TEXT,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE staff_leaves (
  id INT AUTO_INCREMENT PRIMARY KEY,
  staff_id INT NOT NULL,
  leave_start DATE NOT NULL,
  leave_end DATE NOT NULL,
  leave_reason TEXT,
  status ENUM('active','cancelled','expired') DEFAULT 'active',
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (staff_id) REFERENCES staff(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
);

-- ─── AUDIT LOG ──────────────────────────────────────────────
CREATE TABLE audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT,
  action VARCHAR(100) NOT NULL,
  module VARCHAR(100),
  record_id INT,
  old_data JSON,
  new_data JSON,
  ip_address VARCHAR(50),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ─── SEED DATA ──────────────────────────────────────────────
INSERT INTO roles (name, description, permissions) VALUES
('admin', 'Full system access', '{"all": true}'),
('hr', 'HR and staff management', '{"staff": true, "contacts": true, "dashboard": true}'),
('accountant', 'Accounting and finance', '{"invoices": true, "expenses": true, "accounts": true, "reports": true, "bank": true, "dashboard": true}'),
('sales', 'Sales and invoicing', '{"invoices": true, "contacts": true, "inventory": true, "dashboard": true}'),
('viewer', 'Read-only access', '{"dashboard": true, "reports": true}');

-- Default admin user (password: Admin@123)
INSERT INTO users (role_id, name, email, password, status) VALUES
(1, 'System Admin', 'admin@zohobooks.local', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'active');

INSERT INTO account_groups (name, type) VALUES
('Current Assets', 'asset'), ('Fixed Assets', 'asset'), ('Other Assets', 'asset'),
('Current Liabilities', 'liability'), ('Long-term Liabilities', 'liability'),
('Equity', 'equity'), ('Operating Income', 'income'), ('Other Income', 'income'),
('Cost of Goods Sold', 'expense'), ('Operating Expenses', 'expense'), ('Other Expenses', 'expense');

INSERT INTO accounts (group_id, account_code, account_name, account_type, sub_type, is_system) VALUES
(1, '1000', 'Cash', 'asset', 'cash', 1),
(1, '1010', 'Bank Account', 'asset', 'bank', 1),
(1, '1100', 'Accounts Receivable', 'asset', 'receivable', 1),
(1, '1200', 'Inventory Asset', 'asset', 'inventory', 1),
(1, '1300', 'Prepaid Expenses', 'asset', 'other_current', 0),
(2, '1500', 'Office Equipment', 'asset', 'fixed', 0),
(2, '1510', 'Furniture & Fixtures', 'asset', 'fixed', 0),
(2, '1520', 'Vehicles', 'asset', 'fixed', 0),
(4, '2000', 'Accounts Payable', 'liability', 'payable', 1),
(4, '2100', 'VAT Payable', 'liability', 'tax', 1),
(4, '2200', 'Salaries Payable', 'liability', 'other_current', 0),
(5, '2500', 'Long-term Loans', 'liability', 'long_term', 0),
(6, '3000', 'Owner Equity', 'equity', 'equity', 1),
(6, '3100', 'Retained Earnings', 'equity', 'retained', 1),
(7, '4000', 'Sales Revenue', 'income', 'sales', 1),
(7, '4100', 'Service Revenue', 'income', 'service', 0),
(8, '4500', 'Interest Income', 'income', 'other', 0),
(9, '5000', 'Cost of Goods Sold', 'expense', 'cogs', 1),
(10, '6000', 'Salaries & Wages', 'expense', 'payroll', 0),
(10, '6100', 'Rent Expense', 'expense', 'operating', 0),
(10, '6200', 'Utilities Expense', 'expense', 'operating', 0),
(10, '6300', 'Office Supplies', 'expense', 'operating', 0),
(10, '6400', 'Marketing & Advertising', 'expense', 'operating', 0),
(10, '6500', 'Travel & Entertainment', 'expense', 'operating', 0),
(11, '7000', 'Depreciation Expense', 'expense', 'other', 0),
(11, '7100', 'Interest Expense', 'expense', 'other', 0);

INSERT INTO expense_categories (name, account_id) VALUES
('Office Supplies', 22), ('Rent', 20), ('Utilities', 21),
('Travel', 24), ('Marketing', 23), ('Salaries', 19),
('Equipment', 6), ('Miscellaneous', 22);

INSERT INTO item_categories (name) VALUES
('General'), ('Electronics'), ('Furniture'), ('Stationery'), ('Services');

INSERT INTO departments (name) VALUES
('Administration'), ('Finance'), ('Sales'), ('Human Resources'), ('Operations');

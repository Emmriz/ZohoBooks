# ZohoBooks Replica — Installation Guide

## Requirements
- PHP 8.0+ with PDO & PDO_MySQL extensions
- MySQL 5.7+ or MariaDB 10.3+
- Apache or Nginx web server
- mod_rewrite enabled (Apache)

---

## Step 1 — Copy Files to Web Root

Place the `zohobooks/` folder inside your web root:
- **XAMPP / WAMP:** `C:/xampp/htdocs/zohobooks/`
- **Linux Apache:** `/var/www/html/zohobooks/`
- **MAMP:** `/Applications/MAMP/htdocs/zohobooks/`

---

## Step 2 — Create the Database

Open phpMyAdmin or MySQL CLI and run:

```sql
SOURCE /path/to/zohobooks/config/schema.sql;
```

Or paste the contents of `config/schema.sql` into phpMyAdmin's SQL tab.

---

## Step 3 — Configure Database Connection

Edit `config/database.php` and update:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'zohobooks');
define('DB_USER', 'root');        // Your MySQL username
define('DB_PASS', '');            // Your MySQL password
define('APP_URL', 'http://localhost/zohobooks');  // Your app URL
```

---

## Step 4 — Set File Permissions (Linux)

```bash
chmod -R 755 /var/www/html/zohobooks
chmod -R 777 /var/www/html/zohobooks/uploads   # if you add an uploads folder
```

---

## Step 5 — Access the App

Open your browser and go to:
```
http://localhost/zohobooks
```

**Default Login Credentials:**
- Email: `admin@zohobooks.local`
- Password: `password`

> ⚠️ **Change the default password immediately after first login!**

---

## Project Structure

```
zohobooks/
├── config/
│   ├── database.php          # DB connection settings
│   └── schema.sql            # Full database schema + seed data
├── includes/
│   ├── functions.php         # Core functions, auth, helpers
│   ├── header.php            # Sidebar + top nav layout
│   └── footer.php            # JS init + footer
├── modules/
│   ├── dashboard/index.php   # Main dashboard
│   ├── invoices/
│   │   ├── index.php         # Invoice list + create/edit
│   │   ├── view.php          # Invoice detail/print
│   │   └── record_payment.php
│   ├── expenses/index.php    # Expenses & Bills
│   ├── contacts/index.php    # Customers & Vendors
│   ├── inventory/index.php   # Items & Stock
│   ├── accounts/
│   │   ├── index.php         # Chart of Accounts
│   │   └── journal.php       # Journal Entries
│   ├── bank/index.php        # Banking & Reconciliation
│   ├── reports/index.php     # P&L, Balance Sheet, etc.
│   ├── staff/index.php       # HR / Staff Management
│   └── access/index.php      # Users, Roles & Permissions
├── login.php
├── logout.php
└── index.php
```

---

## Roles Included

| Role       | Access                                          |
|------------|-------------------------------------------------|
| admin      | Full access to everything                       |
| hr         | Staff, Contacts, Dashboard                      |
| accountant | Invoices, Expenses, Accounts, Reports, Banking  |
| sales      | Invoices, Contacts, Inventory, Dashboard        |
| viewer     | Dashboard and Reports (read-only)               |

---

## Currency

Default currency is **NGN (₦)**. To change:
- Edit `config/database.php` constants: `APP_CURRENCY` and `APP_CURRENCY_SYMBOL`

---

## Features

- ✅ Dashboard with live charts
- ✅ Invoice creation with line items, tax, discount, shipping
- ✅ Payment recording on invoices
- ✅ Printable invoice view
- ✅ Expenses & Bills with categories
- ✅ Customer & Vendor contacts
- ✅ Inventory with stock adjustments and low-stock alerts
- ✅ Chart of Accounts (5 types: asset, liability, equity, income, expense)
- ✅ Double-entry Journal Entries (balanced validation)
- ✅ Bank accounts & transaction ledger with reconciliation
- ✅ 7 Report types (P&L, Balance Sheet, AR Aging, AP Aging, Expenses, Sales by Customer, Tax Summary)
- ✅ Staff/HR management with departments, payroll info
- ✅ Access Control: Users, Roles & Permission Matrix
- ✅ Audit Log

---

## Support
Built with PHP 8, MySQL, Tailwind CSS, Lucide Icons, and Chart.js.

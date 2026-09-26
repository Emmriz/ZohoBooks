<?php
// ============================================================
//  ZOHOBOOKS - Mail (SMTP) Configuration — EXAMPLE
//  Copy this file to "mail.php" in the same folder and fill in
//  your real values. "mail.php" is gitignored so your mailbox
//  password never gets committed.
//
//  Hosting provider setup:
//  1. In your hosting control panel (e.g. cPanel > Email Accounts),
//     create a mailbox to send from, e.g. noreply@yourdomain.com.
//  2. Find its SMTP settings — usually under "Connect Devices" or
//     "Set Up Mail Client" next to the mailbox. Typical values:
//       Host:       mail.yourdomain.com
//       Port:       465 (SSL)  or  587 (TLS)
//       Username:   the full mailbox address
//       Password:   the mailbox password
//  3. Fill in the values below to match exactly what your host shows.
// ============================================================

define('MAIL_ENABLED',    true);              // set to false to disable all outgoing email
define('MAIL_HOST',       'mail.yourdomain.com');
define('MAIL_PORT',       465);               // 465 = SSL, 587 = TLS — match your host
define('MAIL_ENCRYPTION', 'ssl');             // 'ssl' for port 465, 'tls' for port 587
define('MAIL_USERNAME',   'noreply@yourdomain.com');
define('MAIL_PASSWORD',   'your-mailbox-password');
define('MAIL_FROM_EMAIL', MAIL_USERNAME);
define('MAIL_FROM_NAME',  APP_NAME);

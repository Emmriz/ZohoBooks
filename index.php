<?php
require_once __DIR__ . '/includes/functions.php';
if (isLoggedIn()) {
    header('Location: ' . APP_URL . '/modules/dashboard/index.php');
} else {
    header('Location: ' . APP_URL . '/login.php');
}
exit;

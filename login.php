<?php
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    header('Location: ' . APP_URL . '/modules/dashboard/index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = login(post('email'), post('password'));
    if ($result['success']) {
        header('Location: ' . APP_URL . '/modules/dashboard/index.php');
        exit;
    }
    $error = $result['message'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — ZohoBooks</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<style>
  * { font-family: 'Inter', sans-serif; }
  .form-input { width: 100%; border: 1px solid #d1d5db; border-radius: 8px; padding: 11px 14px; font-size: 0.875rem; outline: none; transition: border-color 0.15s; }
  .form-input:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
  .bg-pattern { background-color: #4C5135; background-image: radial-gradient(circle at 25% 25%, rgba(37,99,235,0.15) 0%, transparent 50%), radial-gradient(circle at 75% 75%, rgba(139,92,246,0.1) 0%, transparent 50%); }
</style>
</head>
<body class="min-h-screen bg-pattern flex items-center justify-center p-4">

<div class="w-full max-w-md">
  <!-- Logo -->
  <div class="text-center mb-8">
    <div class="w-14 h-14 rounded-2xl bg-[#F4EDE3] flex items-center justify-center mx-auto mb-4">
      <i data-lucide="book-open" class="w-7 h-7 text-[#4C5135]"></i>
    </div>
    <h1 class="text-2xl font-bold text-[#F4EDE3]">MY LAB AFRICA</h1>
    <p class="text-[#F4EDE3] text-sm mt-1">Accounting & Finance Management</p>
  </div>

  <div class="bg-[#F4EDE3] rounded-2xl p-8 shadow-xl">
    <h2 class="text-lg font-semibold text-gray-800 mb-1  text-center">Welcome back</h2>
    <p class="text-sm text-gray-500 mb-6 text-center">Sign in to your account</p>

    <?php if ($error): ?>
    <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm flex items-center gap-2">
      <i data-lucide="alert-circle" class="w-4 h-4 flex-shrink-0"></i>
      <?= clean($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST">
      <div class="mb-4">
        <label class="block text-sm font-medium text-gray-700 mb-1.5">Email Address</label>
        <input type="email" name="email" required value="<?= clean(post('email')) ?>"
               placeholder="admin@zohobooks.local"
               class="form-input">
      </div>
      <div class="mb-6">
        <label class="block text-sm font-medium text-gray-700 mb-1.5">Password</label>
        <input type="password" name="password" required placeholder="••••••••" class="form-input">
      </div>
      <button type="submit"
              class="w-full bg-[#4C5135] hover:bg-[#3A3F2A] text-white font-medium py-3 rounded-xl transition-colors text-sm">
        Sign In
      </button>
    </form>

    <div class="mt-6 pt-5 border-t border-gray-100">
      <p class="text-xs text-center text-gray-400">
        Default: <strong>admin@zohobooks.local</strong> / <strong>password</strong>
      </p>
    </div>
  </div>

  <p class="text-center text-slate-500 text-xs mt-6">
    <?= APP_NAME ?> v<?= APP_VERSION ?> &copy; <?= date('Y') ?>
  </p>
</div>

<script>lucide.createIcons();</script>
</body>
</html>

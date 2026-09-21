<?php
// ============================================================
//  ZOHOBOOKS - Layout Header
// ============================================================
requireLogin();
$user = currentUser();
$currentModule = $currentModule ?? 'dashboard';

$navItems = [
    'dashboard'  => ['icon' => 'grid-2x2', 'label' => 'Dashboard', 'url' => 'dashboard'],
    'invoices'   => ['icon' => 'file-text', 'label' => 'Invoices', 'url' => 'invoices', 'perm' => 'invoices'],
    'expenses'   => ['icon' => 'receipt', 'label' => 'Expenses & Bills', 'url' => 'expenses', 'perm' => 'expenses'],
    'contacts'   => ['icon' => 'users', 'label' => 'Contacts', 'url' => 'contacts', 'perm' => 'contacts'],
    'inventory'  => ['icon' => 'package', 'label' => 'Inventory', 'url' => 'inventory', 'perm' => 'inventory'],
    'accounts'   => ['icon' => 'landmark', 'label' => 'Chart of Accounts', 'url' => 'accounts', 'perm' => 'accounts'],
    'bank'       => ['icon' => 'building-2', 'label' => 'Banking', 'url' => 'bank', 'perm' => 'bank'],
    'reports'    => ['icon' => 'bar-chart-2', 'label' => 'Reports', 'url' => 'reports', 'perm' => 'reports'],
    'staff'      => ['icon' => 'user-check', 'label' => 'Staff / HR', 'url' => 'staff', 'perm' => 'staff'],
    'access'     => ['icon' => 'shield', 'label' => 'Access Control', 'url' => 'access', 'perm' => 'all'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= clean($pageTitle ?? 'Dashboard') ?> — <?= APP_NAME ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script>
  tailwind.config = {
    theme: {
      extend: {
        colors: {
          brand: { 50:'#eff6ff', 100:'#dbeafe', 500:'#3b82f6', 600:'#2563eb', 700:'#1d4ed8', 800:'#1e40af', 900:'#1e3a8a' },
          sidebar: '#0f172a'
        }
      }
    }
  }
</script>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
  * { font-family: 'Inter', sans-serif; }
  ::-webkit-scrollbar { width: 5px; height: 5px; }
  ::-webkit-scrollbar-track { background: #f1f5f9; }
  ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 99px; }
  .nav-item { transition: all 0.15s ease; }
  .nav-item:hover { background: rgba(255,255,255,0.08); }
  .nav-item.active { background: rgba(59,130,246,0.25); border-left: 3px solid #3b82f6; }
  .nav-item.active .nav-label { color: #93c5fd; }
  .nav-item .nav-label { color: #94a3b8; font-size: 0.8rem; font-weight: 500; }
  .card { background: white; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.07), 0 1px 2px rgba(0,0,0,0.04); }
  .btn-primary { background: #2563eb; color: white; border-radius: 8px; padding: 8px 18px; font-size: 0.875rem; font-weight: 500; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: background 0.15s; }
  .btn-primary:hover { background: #1d4ed8; }
  .btn-secondary { background: white; color: #374151; border: 1px solid #d1d5db; border-radius: 8px; padding: 8px 18px; font-size: 0.875rem; font-weight: 500; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all 0.15s; }
  .btn-secondary:hover { background: #f9fafb; }
  .btn-danger { background: #dc2626; color: white; border-radius: 8px; padding: 8px 18px; font-size: 0.875rem; font-weight: 500; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
  .form-input { width: 100%; border: 1px solid #d1d5db; border-radius: 8px; padding: 9px 12px; font-size: 0.875rem; outline: none; transition: border-color 0.15s; }
  .form-input:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
  .form-label { display: block; font-size: 0.8rem; font-weight: 500; color: #374151; margin-bottom: 4px; }
  .table-row:hover { background: #f8fafc; }
  .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 50; display: flex; align-items: center; justify-content: center; padding: 1rem; }
  .modal-box { background: white; border-radius: 16px; width: 100%; max-height: 90vh; overflow-y: auto; }
  .sidebar-logo { font-size: 1.1rem; font-weight: 700; color: white; letter-spacing: -0.3px; }
  .stat-card { background: white; border-radius: 12px; padding: 1.25rem; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
  .alert-success { background: #f0fdf4; border: 1px solid #86efac; color: #166534; border-radius: 8px; padding: 12px 16px; font-size: 0.875rem; }
  .alert-error { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; border-radius: 8px; padding: 12px 16px; font-size: 0.875rem; }
</style>
</head>
<body class="bg-slate-50 text-gray-800">

<!-- ── Sidebar ── -->
<aside id="sidebar" class="fixed inset-y-0 left-0 w-60 z-30 flex flex-col" style="background:#0f172a;">
  <div class="flex items-center gap-3 px-5 py-4 border-b border-slate-700">
    <div class="w-8 h-8 rounded-lg bg-blue-600 flex items-center justify-center">
      <i data-lucide="book-open" class="w-4 h-4 text-white"></i>
    </div>
    <span class="sidebar-logo"><?= APP_NAME ?></span>
  </div>

  <nav class="flex-1 py-3 overflow-y-auto">
    <?php foreach ($navItems as $key => $item):
      $perm = $item['perm'] ?? $key;
      if (!hasPermission($perm)) continue;
      $isActive = ($currentModule === $key);
    ?>
    <a href="<?= APP_URL ?>/modules/<?= $item['url'] ?>/index.php"
       class="nav-item <?= $isActive ? 'active' : '' ?> flex items-center gap-3 px-5 py-3 mx-2 rounded-lg mb-0.5">
      <i data-lucide="<?= $item['icon'] ?>" class="w-4 h-4 <?= $isActive ? 'text-blue-400' : 'text-slate-500' ?>"></i>
      <span class="nav-label <?= $isActive ? '!text-blue-200' : '' ?>"><?= $item['label'] ?></span>
    </a>
    <?php endforeach; ?>
  </nav>

  <div class="border-t border-slate-700 p-4">
    <div class="flex items-center gap-3">
      <div class="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center text-white text-xs font-bold">
        <?= strtoupper(substr($user['name'], 0, 2)) ?>
      </div>
      <div class="flex-1 min-w-0">
        <p class="text-xs font-medium text-slate-200 truncate"><?= clean($user['name']) ?></p>
        <p class="text-xs text-slate-500 truncate"><?= clean($user['role_name']) ?></p>
      </div>
      <a href="<?= APP_URL ?>/logout.php" title="Logout">
        <i data-lucide="log-out" class="w-4 h-4 text-slate-500 hover:text-red-400 transition-colors"></i>
      </a>
    </div>
  </div>
</aside>

<!-- ── Main Content ── -->
<div class="ml-60 min-h-screen flex flex-col">
  <!-- Top bar -->
  <header class="bg-white border-b border-gray-100 px-6 py-3 flex items-center justify-between sticky top-0 z-20">
    <div>
      <h1 class="text-base font-semibold text-gray-800"><?= clean($pageTitle ?? 'Dashboard') ?></h1>
      <p class="text-xs text-gray-400"><?= date('l, d F Y') ?></p>
    </div>
    <div class="flex items-center gap-3">
      <div class="relative">
        <i data-lucide="bell" class="w-5 h-5 text-gray-400 cursor-pointer hover:text-gray-600"></i>
      </div>
      <div class="w-px h-6 bg-gray-200"></div>
      <span class="text-xs text-gray-500"><?= clean($user['name']) ?></span>
    </div>
  </header>

  <!-- Flash messages -->
  <?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="mx-6 mt-4 alert-success flex items-center gap-2">
    <i data-lucide="check-circle" class="w-4 h-4"></i>
    <?= clean($_SESSION['flash_success']) ?>
  </div>
  <?php unset($_SESSION['flash_success']); endif; ?>

  <?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="mx-6 mt-4 alert-error flex items-center gap-2">
    <i data-lucide="alert-circle" class="w-4 h-4"></i>
    <?= clean($_SESSION['flash_error']) ?>
  </div>
  <?php unset($_SESSION['flash_error']); endif; ?>

  <main class="flex-1 p-6">

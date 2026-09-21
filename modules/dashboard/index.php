<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$db = getDB();
$currentModule = 'dashboard';
$pageTitle = 'Dashboard';

// ── Stats ────────────────────────────────────────────────────
$totalRevenue   = $db->query("SELECT COALESCE(SUM(total),0) FROM invoices WHERE status='paid'")->fetchColumn();
$totalReceivable= $db->query("SELECT COALESCE(SUM(balance_due),0) FROM invoices WHERE status IN('sent','partially_paid','overdue')")->fetchColumn();
$totalExpenses  = $db->query("SELECT COALESCE(SUM(total),0) FROM expenses WHERE status='paid' AND MONTH(expense_date)=MONTH(NOW()) AND YEAR(expense_date)=YEAR(NOW())")->fetchColumn();
$totalPayable   = $db->query("SELECT COALESCE(SUM(balance_due),0) FROM expenses WHERE type='bill' AND status IN('pending','approved')")->fetchColumn();
$totalContacts  = $db->query("SELECT COUNT(*) FROM contacts WHERE status='active'")->fetchColumn();
$totalItems     = $db->query("SELECT COUNT(*) FROM items WHERE status='active'")->fetchColumn();
$overdueInvoices= $db->query("SELECT COUNT(*) FROM invoices WHERE status='overdue'")->fetchColumn();
$draftInvoices  = $db->query("SELECT COUNT(*) FROM invoices WHERE status='draft'")->fetchColumn();

// ── Recent Invoices ──────────────────────────────────────────
$recentInvoices = $db->query("
    SELECT i.*, CONCAT(COALESCE(c.company_name, CONCAT(c.first_name,' ',c.last_name))) as contact_name
    FROM invoices i LEFT JOIN contacts c ON i.contact_id = c.id
    ORDER BY i.created_at DESC LIMIT 7
")->fetchAll();

// ── Recent Expenses ──────────────────────────────────────────
$recentExpenses = $db->query("
    SELECT e.*, COALESCE(c.company_name, CONCAT(c.first_name,' ',c.last_name)) as contact_name,
           ec.name as category_name
    FROM expenses e
    LEFT JOIN contacts c ON e.contact_id = c.id
    LEFT JOIN expense_categories ec ON e.category_id = ec.id
    ORDER BY e.created_at DESC LIMIT 5
")->fetchAll();

// ── Monthly Revenue (12 months) ──────────────────────────────
$monthlyData = $db->query("
    SELECT DATE_FORMAT(issue_date,'%b %Y') as month_label,
           DATE_FORMAT(issue_date,'%Y-%m') as month_key,
           COALESCE(SUM(total),0) as revenue,
           COALESCE(SUM(amount_paid),0) as collected
    FROM invoices
    WHERE issue_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY month_key, month_label ORDER BY month_key
")->fetchAll();

// ── Top Customers ────────────────────────────────────────────
$topCustomers = $db->query("
    SELECT COALESCE(c.company_name, CONCAT(c.first_name,' ',c.last_name)) as name,
           SUM(i.total) as total_billed, COUNT(i.id) as invoice_count
    FROM invoices i JOIN contacts c ON i.contact_id = c.id
    WHERE i.status != 'cancelled'
    GROUP BY c.id ORDER BY total_billed DESC LIMIT 5
")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<!-- Stats Grid -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
  <?php
  $stats = [
    ['label'=>'Total Revenue', 'value'=>formatCurrency($totalRevenue), 'icon'=>'trending-up', 'color'=>'bg-blue-50 text-blue-600', 'sub'=>'All time paid'],
    ['label'=>'Receivables', 'value'=>formatCurrency($totalReceivable), 'icon'=>'clock', 'color'=>'bg-yellow-50 text-yellow-600', 'sub'=>'Outstanding'],
    ['label'=>'Expenses (This Month)', 'value'=>formatCurrency($totalExpenses), 'icon'=>'trending-down', 'color'=>'bg-red-50 text-red-600', 'sub'=>date('F Y')],
    ['label'=>'Payables', 'value'=>formatCurrency($totalPayable), 'icon'=>'alert-circle', 'color'=>'bg-purple-50 text-purple-600', 'sub'=>'Bills due'],
  ];
  foreach ($stats as $s): ?>
  <div class="stat-card">
    <div class="flex items-center justify-between mb-3">
      <p class="text-xs text-gray-500 font-medium"><?= $s['label'] ?></p>
      <div class="w-8 h-8 rounded-lg <?= $s['color'] ?> flex items-center justify-center">
        <i data-lucide="<?= $s['icon'] ?>" class="w-4 h-4"></i>
      </div>
    </div>
    <p class="text-xl font-bold text-gray-800"><?= $s['value'] ?></p>
    <p class="text-xs text-gray-400 mt-1"><?= $s['sub'] ?></p>
  </div>
  <?php endforeach; ?>
</div>

<!-- Secondary Stats -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
  <?php
  $stats2 = [
    ['label'=>'Active Contacts','value'=>$totalContacts,'icon'=>'users','color'=>'text-blue-500'],
    ['label'=>'Active Items','value'=>$totalItems,'icon'=>'package','color'=>'text-green-500'],
    ['label'=>'Overdue Invoices','value'=>$overdueInvoices,'icon'=>'alert-triangle','color'=>'text-red-500'],
    ['label'=>'Draft Invoices','value'=>$draftInvoices,'icon'=>'file','color'=>'text-gray-500'],
  ];
  foreach ($stats2 as $s): ?>
  <div class="stat-card flex items-center gap-4">
    <div class="w-10 h-10 rounded-xl bg-gray-50 flex items-center justify-center">
      <i data-lucide="<?= $s['icon'] ?>" class="w-5 h-5 <?= $s['color'] ?>"></i>
    </div>
    <div>
      <p class="text-xs text-gray-500"><?= $s['label'] ?></p>
      <p class="text-lg font-bold text-gray-800"><?= $s['value'] ?></p>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Charts + Tables Row -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">

  <!-- Revenue Chart -->
  <div class="lg:col-span-2 card p-5">
    <div class="flex items-center justify-between mb-4">
      <div>
        <h3 class="text-sm font-semibold text-gray-800">Revenue Overview</h3>
        <p class="text-xs text-gray-400">Last 12 months</p>
      </div>
    </div>
    <div style="position:relative;height:220px;">
      <canvas id="revenueChart"></canvas>
    </div>
  </div>

  <!-- Top Customers -->
  <div class="card p-5">
    <h3 class="text-sm font-semibold text-gray-800 mb-4">Top Customers</h3>
    <?php if ($topCustomers): ?>
    <div class="space-y-3">
      <?php foreach ($topCustomers as $c): ?>
      <div class="flex items-center gap-3">
        <div class="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center text-xs font-bold text-blue-600 flex-shrink-0">
          <?= strtoupper(substr($c['name'], 0, 2)) ?>
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-xs font-medium text-gray-800 truncate"><?= clean($c['name']) ?></p>
          <p class="text-xs text-gray-400"><?= $c['invoice_count'] ?> invoices</p>
        </div>
        <span class="text-xs font-semibold text-gray-700"><?= formatCurrency($c['total_billed']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="text-center py-8 text-gray-400">
      <i data-lucide="users" class="w-8 h-8 mx-auto mb-2 opacity-40"></i>
      <p class="text-xs">No customer data yet</p>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Bottom Row: Recent Invoices + Recent Expenses -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

  <!-- Recent Invoices -->
  <div class="card p-5">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-sm font-semibold text-gray-800">Recent Invoices</h3>
      <a href="<?= APP_URL ?>/modules/invoices/index.php" class="text-xs text-blue-600 hover:underline">View all</a>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full">
        <thead><tr class="text-xs text-gray-400 border-b border-gray-100">
          <th class="pb-2 text-left font-medium">Invoice</th>
          <th class="pb-2 text-left font-medium">Customer</th>
          <th class="pb-2 text-right font-medium">Amount</th>
          <th class="pb-2 text-center font-medium">Status</th>
        </tr></thead>
        <tbody>
          <?php foreach ($recentInvoices as $inv): ?>
          <tr class="table-row border-b border-gray-50">
            <td class="py-2.5 text-xs font-medium text-blue-600">
              <a href="<?= APP_URL ?>/modules/invoices/view.php?id=<?= $inv['id'] ?>"><?= clean($inv['invoice_number']) ?></a>
            </td>
            <td class="py-2.5 text-xs text-gray-600 max-w-[120px] truncate"><?= clean($inv['contact_name']) ?></td>
            <td class="py-2.5 text-xs text-right font-medium"><?= formatCurrency($inv['total']) ?></td>
            <td class="py-2.5 text-center"><?= statusBadge($inv['status']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$recentInvoices): ?>
          <tr><td colspan="4" class="py-8 text-center text-xs text-gray-400">No invoices yet</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Recent Expenses -->
  <div class="card p-5">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-sm font-semibold text-gray-800">Recent Expenses</h3>
      <a href="<?= APP_URL ?>/modules/expenses/index.php" class="text-xs text-blue-600 hover:underline">View all</a>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full">
        <thead><tr class="text-xs text-gray-400 border-b border-gray-100">
          <th class="pb-2 text-left font-medium">Ref</th>
          <th class="pb-2 text-left font-medium">Category</th>
          <th class="pb-2 text-right font-medium">Amount</th>
          <th class="pb-2 text-center font-medium">Status</th>
        </tr></thead>
        <tbody>
          <?php foreach ($recentExpenses as $exp): ?>
          <tr class="table-row border-b border-gray-50">
            <td class="py-2.5 text-xs font-medium text-blue-600">
              <a href="<?= APP_URL ?>/modules/expenses/view.php?id=<?= $exp['id'] ?>"><?= clean($exp['expense_number']) ?></a>
            </td>
            <td class="py-2.5 text-xs text-gray-600"><?= clean($exp['category_name'] ?? '-') ?></td>
            <td class="py-2.5 text-xs text-right font-medium"><?= formatCurrency($exp['total']) ?></td>
            <td class="py-2.5 text-center"><?= statusBadge($exp['status']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$recentExpenses): ?>
          <tr><td colspan="4" class="py-8 text-center text-xs text-gray-400">No expenses yet</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const labels = <?= json_encode(array_column($monthlyData, 'month_label')) ?>;
const revenue = <?= json_encode(array_map(fn($r) => (float)$r['revenue'], $monthlyData)) ?>;
const collected = <?= json_encode(array_map(fn($r) => (float)$r['collected'], $monthlyData)) ?>;

new Chart(document.getElementById('revenueChart'), {
  type: 'bar',
  data: {
    labels,
    datasets: [
      { label: 'Billed', data: revenue, backgroundColor: 'rgba(59,130,246,0.15)', borderColor: '#3b82f6', borderWidth: 2, borderRadius: 4 },
      { label: 'Collected', data: collected, backgroundColor: 'rgba(16,185,129,0.15)', borderColor: '#10b981', borderWidth: 2, borderRadius: 4 }
    ]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { position: 'top', labels: { font: { size: 11 }, usePointStyle: true } } },
    scales: {
      x: { grid: { display: false }, ticks: { font: { size: 10 } } },
      y: { grid: { color: '#f1f5f9' }, ticks: { font: { size: 10 }, callback: v => '₦' + v.toLocaleString() } }
    }
  }
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

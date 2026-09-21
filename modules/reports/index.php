<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
if (!hasPermission('reports')) { $_SESSION['flash_error']='Access denied'; header('Location: '.APP_URL.'/modules/dashboard/index.php'); exit; }

$db = getDB();
$currentModule = 'reports';
$pageTitle = 'Reports';

$report   = get('report', 'pl');
$dateFrom = get('date_from', date('Y-01-01'));
$dateTo   = get('date_to',   date('Y-m-d'));

include __DIR__ . '/../../includes/header.php';
?>

<!-- Report Nav -->
<div class="flex gap-2 mb-6 flex-wrap">
  <?php $reports = [
    'pl'       => ['Profit & Loss',    'trending-up'],
    'bs'       => ['Balance Sheet',    'landmark'],
    'ar_aging' => ['AR Aging',         'clock'],
    'ap_aging' => ['AP Aging',         'alert-circle'],
    'expenses' => ['Expense Summary',  'receipt'],
    'sales'    => ['Sales by Customer','users'],
    'tax'      => ['Tax Summary',      'file-text'],
  ];
  foreach ($reports as $key => [$label, $icon]): ?>
  <a href="?report=<?=$key?>&date_from=<?=$dateFrom?>&date_to=<?=$dateTo?>"
     class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-all
            <?= $report===$key ? 'bg-blue-600 text-white shadow' : 'bg-white text-gray-600 hover:bg-gray-100 border border-gray-200' ?>">
    <i data-lucide="<?=$icon?>" class="w-4 h-4"></i><?= $label ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- Date Filter -->
<form method="GET" class="card p-4 mb-6 flex items-center gap-4">
  <input type="hidden" name="report" value="<?= clean($report) ?>">
  <div class="flex items-center gap-2">
    <label class="text-sm text-gray-500 whitespace-nowrap">From</label>
    <input type="date" name="date_from" class="form-input py-2 text-sm" value="<?= $dateFrom ?>">
  </div>
  <div class="flex items-center gap-2">
    <label class="text-sm text-gray-500 whitespace-nowrap">To</label>
    <input type="date" name="date_to" class="form-input py-2 text-sm" value="<?= $dateTo ?>">
  </div>
  <button type="submit" class="btn-primary text-sm">Apply</button>
  <button type="button" onclick="window.print()" class="btn-secondary text-sm"><i data-lucide="printer" class="w-4 h-4"></i> Print</button>
</form>

<?php
// ── PROFIT & LOSS ────────────────────────────────────────────
if ($report === 'pl'):
  $income = $db->prepare("SELECT a.account_name, COALESCE(SUM(jl.credit - jl.debit),0) as balance FROM journal_lines jl JOIN accounts a ON jl.account_id=a.id JOIN journal_entries je ON jl.journal_id=je.id WHERE a.account_type='income' AND je.status='published' AND je.entry_date BETWEEN ? AND ? GROUP BY a.id ORDER BY a.account_code");
  $income->execute([$dateFrom, $dateTo]); $incomeRows = $income->fetchAll();

  // Also count from invoices
  $invIncome = $db->prepare("SELECT COALESCE(SUM(total),0) as total FROM invoices WHERE status IN('paid','partially_paid') AND issue_date BETWEEN ? AND ?");
  $invIncome->execute([$dateFrom, $dateTo]); $invTotal = (float)$invIncome->fetchColumn();

  $expenses = $db->prepare("SELECT a.account_name, COALESCE(SUM(jl.debit - jl.credit),0) as balance FROM journal_lines jl JOIN accounts a ON jl.account_id=a.id JOIN journal_entries je ON jl.journal_id=je.id WHERE a.account_type='expense' AND je.status='published' AND je.entry_date BETWEEN ? AND ? GROUP BY a.id ORDER BY a.account_code");
  $expenses->execute([$dateFrom, $dateTo]); $expenseRows = $expenses->fetchAll();

  $expFromExp = $db->prepare("SELECT ec.name as account_name, COALESCE(SUM(e.total),0) as balance FROM expenses e LEFT JOIN expense_categories ec ON e.category_id=ec.id WHERE e.status='paid' AND e.expense_date BETWEEN ? AND ? GROUP BY ec.id ORDER BY ec.name");
  $expFromExp->execute([$dateFrom, $dateTo]); $expFromExpRows = $expFromExp->fetchAll();

  $totalIncome   = $invTotal + array_sum(array_column($incomeRows,'balance'));
  $totalExpenses = array_sum(array_column($expenseRows,'balance')) + array_sum(array_column($expFromExpRows,'balance'));
  $netProfit = $totalIncome - $totalExpenses;
?>
<div class="card overflow-hidden print:shadow-none">
  <div class="px-6 py-4 border-b bg-gray-50">
    <h2 class="text-base font-semibold">Profit & Loss Statement</h2>
    <p class="text-xs text-gray-400"><?= formatDate($dateFrom) ?> — <?= formatDate($dateTo) ?></p>
  </div>
  <div class="p-6 max-w-2xl">
    <!-- Income -->
    <h3 class="text-sm font-semibold text-green-700 mb-2 flex items-center gap-2"><i data-lucide="trending-up" class="w-4 h-4"></i> Income</h3>
    <table class="w-full mb-4 text-sm">
      <?php if ($invTotal > 0): ?>
      <tr class="border-b border-gray-50"><td class="py-2 text-gray-700 pl-4">Sales Revenue (Invoices)</td><td class="py-2 text-right font-medium"><?= formatCurrency($invTotal) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($incomeRows as $row): ?>
      <tr class="border-b border-gray-50"><td class="py-2 text-gray-700 pl-4"><?= clean($row['account_name']) ?></td><td class="py-2 text-right font-medium"><?= formatCurrency($row['balance']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$incomeRows && $invTotal == 0): ?>
      <tr><td class="py-2 text-gray-400 pl-4 italic text-xs">No income recorded</td><td class="py-2 text-right text-gray-400">₦0.00</td></tr>
      <?php endif; ?>
      <tr class="bg-green-50 font-semibold"><td class="py-2 pl-4 text-green-700">Total Income</td><td class="py-2 text-right text-green-700"><?= formatCurrency($totalIncome) ?></td></tr>
    </table>

    <!-- Expenses -->
    <h3 class="text-sm font-semibold text-red-700 mb-2 flex items-center gap-2"><i data-lucide="trending-down" class="w-4 h-4"></i> Expenses</h3>
    <table class="w-full mb-4 text-sm">
      <?php foreach ($expFromExpRows as $row): ?>
      <tr class="border-b border-gray-50"><td class="py-2 text-gray-700 pl-4"><?= clean($row['account_name']??'Uncategorized') ?></td><td class="py-2 text-right font-medium"><?= formatCurrency($row['balance']) ?></td></tr>
      <?php endforeach; ?>
      <?php foreach ($expenseRows as $row): ?>
      <tr class="border-b border-gray-50"><td class="py-2 text-gray-700 pl-4"><?= clean($row['account_name']) ?></td><td class="py-2 text-right font-medium"><?= formatCurrency($row['balance']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$expenseRows && !$expFromExpRows): ?>
      <tr><td class="py-2 text-gray-400 pl-4 italic text-xs">No expenses recorded</td><td class="py-2 text-right text-gray-400">₦0.00</td></tr>
      <?php endif; ?>
      <tr class="bg-red-50 font-semibold"><td class="py-2 pl-4 text-red-700">Total Expenses</td><td class="py-2 text-right text-red-700"><?= formatCurrency($totalExpenses) ?></td></tr>
    </table>

    <!-- Net -->
    <div class="border-t-2 border-gray-300 pt-4">
      <div class="flex justify-between items-center text-lg font-bold <?= $netProfit>=0?'text-green-700':'text-red-700' ?>">
        <span><?= $netProfit>=0?'Net Profit':'Net Loss' ?></span>
        <span><?= formatCurrency(abs($netProfit)) ?></span>
      </div>
    </div>
  </div>
</div>

<?php elseif ($report === 'bs'):
// ── BALANCE SHEET ────────────────────────────────────────────
  $bsData = $db->query("SELECT a.account_type, a.account_name, a.account_code, a.balance FROM accounts a WHERE a.status='active' ORDER BY a.account_type, a.account_code")->fetchAll();
  $bs = ['asset'=>[],'liability'=>[],'equity'=>[]];
  foreach ($bsData as $row) { if (isset($bs[$row['account_type']])) $bs[$row['account_type']][] = $row; }
  $totalAssets = array_sum(array_column($bs['asset'],'balance'));
  $totalLiab   = array_sum(array_column($bs['liability'],'balance'));
  $totalEquity = array_sum(array_column($bs['equity'],'balance'));
?>
<div class="card overflow-hidden">
  <div class="px-6 py-4 border-b bg-gray-50">
    <h2 class="text-base font-semibold">Balance Sheet</h2>
    <p class="text-xs text-gray-400">As of <?= formatDate($dateTo) ?></p>
  </div>
  <div class="p-6 grid grid-cols-2 gap-8 max-w-4xl">
    <?php foreach ([['asset','Assets','blue'],['liability','Liabilities','red'],['equity','Equity','purple']] as [$type,$label,$col]): ?>
    <div <?= $type==='equity'?'class="col-span-2"':'' ?>>
      <h3 class="text-sm font-semibold text-<?=$col?>-700 mb-3"><?= $label ?></h3>
      <table class="w-full text-sm">
        <?php foreach ($bs[$type] as $row): ?>
        <tr class="border-b border-gray-50">
          <td class="py-2 pl-4 font-mono text-xs text-gray-400 w-16"><?= $row['account_code'] ?></td>
          <td class="py-2 text-gray-700"><?= clean($row['account_name']) ?></td>
          <td class="py-2 text-right"><?= formatCurrency($row['balance']) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="font-semibold bg-<?=$col?>-50"><td></td><td class="py-2 pl-4 text-<?=$col?>-700">Total <?= $label ?></td>
       <td class="py-2 text-right text-<?=$col?>-700"> <?= formatCurrency( $type === 'asset' ? $totalAssets :
        ($type === 'liability' ? $totalLiab : $totalEquity)
    ) ?>
</td>
      </table>
    </div>
    <?php endforeach; ?>
    <div class="col-span-2 border-t-2 pt-4 flex justify-between text-base font-bold">
      <span>Assets = Liabilities + Equity</span>
      <span class="<?= abs($totalAssets - $totalLiab - $totalEquity)<0.01?'text-green-700':'text-red-700' ?>">
        <?= abs($totalAssets - $totalLiab - $totalEquity)<0.01 ? 'Balanced ✓' : 'Unbalanced — difference: '.formatCurrency(abs($totalAssets-$totalLiab-$totalEquity)) ?>
      </span>
    </div>
  </div>
</div>

<?php elseif ($report === 'ar_aging'):
// ── AR AGING ─────────────────────────────────────────────────
  $arRows = $db->prepare("
    SELECT COALESCE(c.company_name, CONCAT(c.first_name,' ',c.last_name)) as name,
           i.invoice_number, i.due_date, i.balance_due, i.status,
           DATEDIFF(NOW(), i.due_date) as days_overdue
    FROM invoices i JOIN contacts c ON i.contact_id=c.id
    WHERE i.status IN('sent','partially_paid','overdue') AND i.balance_due > 0
    ORDER BY days_overdue DESC
  "); $arRows->execute(); $arRows = $arRows->fetchAll();
  $buckets = ['current'=>0,'1_30'=>0,'31_60'=>0,'61_90'=>0,'over_90'=>0];
  foreach ($arRows as $r) {
    $d = (int)$r['days_overdue'];
    if ($d<=0) $buckets['current']+=$r['balance_due'];
    elseif ($d<=30) $buckets['1_30']+=$r['balance_due'];
    elseif ($d<=60) $buckets['31_60']+=$r['balance_due'];
    elseif ($d<=90) $buckets['61_90']+=$r['balance_due'];
    else $buckets['over_90']+=$r['balance_due'];
  }
?>
<div class="grid grid-cols-5 gap-3 mb-6">
  <?php foreach (['current'=>['Current','green'],'1_30'=>['1-30 Days','yellow'],'31_60'=>['31-60 Days','orange'],'61_90'=>['61-90 Days','red'],'over_90'=>['90+ Days','red']] as $k=>[$lbl,$col]): ?>
  <div class="card p-4 text-center">
    <p class="text-xs text-<?=$col?>-600 font-medium mb-1"><?=$lbl?></p>
    <p class="text-lg font-bold"><?= formatCurrency($buckets[$k]) ?></p>
  </div>
  <?php endforeach; ?>
</div>
<div class="card overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-gray-50 border-b"><tr class="text-xs text-gray-500 font-medium">
      <th class="px-4 py-3 text-left">Customer</th>
      <th class="px-4 py-3 text-left">Invoice #</th>
      <th class="px-4 py-3 text-left">Due Date</th>
      <th class="px-4 py-3 text-center">Days Overdue</th>
      <th class="px-4 py-3 text-right">Balance Due</th>
      <th class="px-4 py-3 text-center">Status</th>
    </tr></thead>
    <tbody class="divide-y divide-gray-50">
      <?php foreach ($arRows as $row): ?>
      <tr class="table-row">
        <td class="px-4 py-3 text-xs"><?= clean($row['name']) ?></td>
        <td class="px-4 py-3 text-xs font-medium text-blue-600"><a href="<?= APP_URL ?>/modules/invoices/view.php?id="><?= clean($row['invoice_number']) ?></a></td>
        <td class="px-4 py-3 text-xs"><?= formatDate($row['due_date']) ?></td>
        <td class="px-4 py-3 text-center text-xs <?= $row['days_overdue']>0?'text-red-600 font-bold':'' ?>"><?= max(0,$row['days_overdue']) ?></td>
        <td class="px-4 py-3 text-right text-xs font-medium text-red-600"><?= formatCurrency($row['balance_due']) ?></td>
        <td class="px-4 py-3 text-center"><?= statusBadge($row['status']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$arRows): ?><tr><td colspan="6" class="px-4 py-8 text-center text-gray-400 text-xs">No outstanding receivables.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php elseif ($report === 'expenses'):
// ── EXPENSE SUMMARY ──────────────────────────────────────────
  $expSummary = $db->prepare("SELECT ec.name as category, COUNT(*) as count, SUM(e.total) as total FROM expenses e LEFT JOIN expense_categories ec ON e.category_id=ec.id WHERE e.status != 'cancelled' AND e.expense_date BETWEEN ? AND ? GROUP BY ec.id ORDER BY total DESC");
  $expSummary->execute([$dateFrom, $dateTo]); $expSummary = $expSummary->fetchAll();
  $grandTotal = array_sum(array_column($expSummary,'total'));
?>
<div class="card overflow-hidden">
  <div class="px-6 py-4 border-b bg-gray-50">
    <h2 class="text-base font-semibold">Expense Summary</h2>
    <p class="text-xs text-gray-400"><?= formatDate($dateFrom) ?> — <?= formatDate($dateTo) ?></p>
  </div>
  <table class="w-full">
    <thead class="bg-gray-50 border-b"><tr class="text-xs text-gray-500 font-medium">
      <th class="px-6 py-3 text-left">Category</th>
      <th class="px-6 py-3 text-center">Transactions</th>
      <th class="px-6 py-3 text-right">Amount</th>
      <th class="px-6 py-3 text-right">% of Total</th>
    </tr></thead>
    <tbody class="divide-y divide-gray-50">
      <?php foreach ($expSummary as $row): $pct = $grandTotal>0 ? round($row['total']/$grandTotal*100,1) : 0; ?>
      <tr class="table-row">
        <td class="px-6 py-3 text-sm"><?= clean($row['category']??'Uncategorized') ?></td>
        <td class="px-6 py-3 text-center text-sm"><?= $row['count'] ?></td>
        <td class="px-6 py-3 text-right text-sm font-medium"><?= formatCurrency($row['total']) ?></td>
        <td class="px-6 py-3 text-right">
          <div class="flex items-center justify-end gap-2">
            <div class="w-20 bg-gray-100 rounded-full h-1.5"><div class="bg-blue-500 h-1.5 rounded-full" style="width:<?=$pct?>%"></div></div>
            <span class="text-xs text-gray-500"><?=$pct?>%</span>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <tr class="bg-gray-50 font-bold"><td class="px-6 py-3">Total</td><td class="px-6 py-3 text-center"><?= array_sum(array_column($expSummary,'count')) ?></td><td class="px-6 py-3 text-right"><?= formatCurrency($grandTotal) ?></td><td></td></tr>
    </tbody>
  </table>
</div>

<?php elseif ($report === 'sales'):
// ── SALES BY CUSTOMER ────────────────────────────────────────
  $salesRows = $db->prepare("SELECT COALESCE(c.company_name, CONCAT(c.first_name,' ',c.last_name)) as customer, COUNT(i.id) as invoices, SUM(i.total) as total_billed, SUM(i.amount_paid) as total_paid, SUM(i.balance_due) as balance FROM invoices i JOIN contacts c ON i.contact_id=c.id WHERE i.status != 'cancelled' AND i.issue_date BETWEEN ? AND ? GROUP BY c.id ORDER BY total_billed DESC");
  $salesRows->execute([$dateFrom, $dateTo]); $salesRows = $salesRows->fetchAll();
?>
<div class="card overflow-hidden">
  <div class="px-6 py-4 border-b bg-gray-50">
    <h2 class="text-base font-semibold">Sales by Customer</h2>
    <p class="text-xs text-gray-400"><?= formatDate($dateFrom) ?> — <?= formatDate($dateTo) ?></p>
  </div>
  <table class="w-full">
    <thead class="bg-gray-50 border-b"><tr class="text-xs text-gray-500 font-medium">
      <th class="px-6 py-3 text-left">Customer</th>
      <th class="px-6 py-3 text-center">Invoices</th>
      <th class="px-6 py-3 text-right">Total Billed</th>
      <th class="px-6 py-3 text-right">Collected</th>
      <th class="px-6 py-3 text-right">Outstanding</th>
    </tr></thead>
    <tbody class="divide-y divide-gray-50">
      <?php foreach ($salesRows as $row): ?>
      <tr class="table-row">
        <td class="px-6 py-3 text-sm font-medium"><?= clean($row['customer']) ?></td>
        <td class="px-6 py-3 text-center text-sm"><?= $row['invoices'] ?></td>
        <td class="px-6 py-3 text-right text-sm"><?= formatCurrency($row['total_billed']) ?></td>
        <td class="px-6 py-3 text-right text-sm text-green-600"><?= formatCurrency($row['total_paid']) ?></td>
        <td class="px-6 py-3 text-right text-sm <?= $row['balance']>0?'text-red-600 font-medium':'' ?>"><?= formatCurrency($row['balance']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$salesRows): ?><tr><td colspan="5" class="px-6 py-8 text-center text-gray-400 text-xs">No sales data for this period.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php elseif ($report === 'tax'):
// ── TAX SUMMARY ──────────────────────────────────────────────
  $taxCollected = $db->prepare("SELECT COALESCE(SUM(tax_amount),0) FROM invoices WHERE status IN('paid','partially_paid','sent') AND issue_date BETWEEN ? AND ?");
  $taxCollected->execute([$dateFrom,$dateTo]); $taxCollected = (float)$taxCollected->fetchColumn();
  $taxPaid = $db->prepare("SELECT COALESCE(SUM(tax_amount),0) FROM expenses WHERE status != 'cancelled' AND expense_date BETWEEN ? AND ?");
  $taxPaid->execute([$dateFrom,$dateTo]); $taxPaid = (float)$taxPaid->fetchColumn();
?>
<div class="grid grid-cols-3 gap-4 mb-6">
  <div class="stat-card"><p class="text-xs text-gray-400">Tax Collected (Sales)</p><p class="text-2xl font-bold text-blue-600"><?= formatCurrency($taxCollected) ?></p></div>
  <div class="stat-card"><p class="text-xs text-gray-400">Tax Paid (Purchases)</p><p class="text-2xl font-bold text-red-600"><?= formatCurrency($taxPaid) ?></p></div>
  <div class="stat-card"><p class="text-xs text-gray-400">Net Tax Payable</p><p class="text-2xl font-bold <?= ($taxCollected-$taxPaid)>=0?'text-orange-600':'text-green-600' ?>"><?= formatCurrency(abs($taxCollected-$taxPaid)) ?> <?= ($taxCollected-$taxPaid)<0?'(refund)':'' ?></p></div>
</div>
<div class="card p-6 text-sm text-gray-600">
  <p>This summary covers VAT/Tax from <strong><?= formatDate($dateFrom) ?></strong> to <strong><?= formatDate($dateTo) ?></strong>.</p>
  <p class="mt-2">Ensure all invoices and expenses are correctly tagged with tax percentages for accurate reporting.</p>
</div>
<?php elseif ($report === 'ap_aging'):
  $apRows = $db->prepare("SELECT COALESCE(c.company_name, CONCAT(c.first_name,' ',c.last_name)) as name, e.expense_number, e.due_date, e.balance_due, e.status, DATEDIFF(NOW(), e.due_date) as days_overdue FROM expenses e JOIN contacts c ON e.contact_id=c.id WHERE e.type='bill' AND e.status IN('pending','approved') AND e.balance_due>0 ORDER BY days_overdue DESC");
  $apRows->execute(); $apRows = $apRows->fetchAll();
?>
<div class="card overflow-hidden">
  <div class="px-6 py-4 border-b bg-gray-50"><h2 class="text-base font-semibold">AP Aging — Bills Outstanding</h2></div>
  <table class="w-full text-sm">
    <thead class="bg-gray-50 border-b"><tr class="text-xs text-gray-500 font-medium">
      <th class="px-4 py-3 text-left">Vendor</th><th class="px-4 py-3 text-left">Bill #</th>
      <th class="px-4 py-3 text-left">Due Date</th><th class="px-4 py-3 text-center">Days Overdue</th>
      <th class="px-4 py-3 text-right">Amount Due</th><th class="px-4 py-3 text-center">Status</th>
    </tr></thead>
    <tbody class="divide-y divide-gray-50">
      <?php foreach ($apRows as $row): ?>
      <tr class="table-row">
        <td class="px-4 py-3 text-xs"><?= clean($row['name']) ?></td>
        <td class="px-4 py-3 text-xs font-medium"><?= clean($row['expense_number']) ?></td>
        <td class="px-4 py-3 text-xs"><?= formatDate($row['due_date']) ?></td>
        <td class="px-4 py-3 text-center text-xs <?= $row['days_overdue']>0?'text-red-600 font-bold':'' ?>"><?= max(0,$row['days_overdue']) ?></td>
        <td class="px-4 py-3 text-right text-xs font-medium"><?= formatCurrency($row['balance_due']) ?></td>
        <td class="px-4 py-3 text-center"><?= statusBadge($row['status']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$apRows): ?><tr><td colspan="6" class="px-4 py-8 text-center text-gray-400 text-xs">No outstanding bills.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<style>@media print { aside, header, form, .btn-primary, .btn-secondary { display:none!important; } .ml-60 { margin-left:0!important; } }</style>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

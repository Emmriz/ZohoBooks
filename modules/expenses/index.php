<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
if (!hasPermission('expenses')) { $_SESSION['flash_error']='Access denied'; header('Location: '.APP_URL.'/modules/dashboard/index.php'); exit; }

$db = getDB();
$currentModule = 'expenses';
$pageTitle = 'Expenses & Bills';

if (get('action') === 'delete' && get('id')) {
    $db->prepare("UPDATE expenses SET status='cancelled' WHERE id=?")->execute([(int)get('id')]);
    $_SESSION['flash_success'] = 'Expense cancelled.';
    header('Location: ' . APP_URL . '/modules/expenses/index.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $editId = (int)post('edit_id');
    $descriptions = post('item_desc', []);
    $quantities   = post('item_qty', []);
    $prices       = post('item_price', []);
    $taxPcts      = post('item_tax', []);
    $subtotal = 0; $taxTotal = 0; $lineItems = [];

    if (!is_array($descriptions)) $descriptions = [$descriptions];
    foreach ($descriptions as $i => $desc) {
        if (empty(trim($desc))) continue;
        $qty   = (float)($quantities[$i] ?? 1);
        $price = (float)($prices[$i] ?? 0);
        $taxP  = (float)($taxPcts[$i] ?? 0);
        $lineAmt = $qty * $price;
        $taxAmt  = $lineAmt * $taxP / 100;
        $subtotal += $lineAmt; $taxTotal += $taxAmt;
        $lineItems[] = compact('desc','qty','price','taxP','lineAmt','taxAmt');
    }
    $total = $subtotal + $taxTotal;
    $data = [
        'contact_id'   => (int)post('contact_id') ?: null,
        'category_id'  => (int)post('category_id') ?: null,
        'expense_date' => post('expense_date'),
        'due_date'     => post('due_date') ?: null,
        'type'         => post('type','expense'),
        'status'       => post('status','draft'),
        'reference'    => post('reference'),
        'currency'     => 'NGN',
        'subtotal'     => $subtotal,
        'tax_amount'   => $taxTotal,
        'total'        => $total,
        'balance_due'  => $total,
        'description'  => post('description'),
        'notes'        => post('notes'),
        'created_by'   => $_SESSION['user_id'],
    ];
    if ($editId) {
        $set = implode(', ', array_map(fn($k)=>"$k=?", array_keys($data)));
        $db->prepare("UPDATE expenses SET $set WHERE id=?")->execute([...array_values($data), $editId]);
        $db->prepare("DELETE FROM expense_items WHERE expense_id=?")->execute([$editId]);
        $expId = $editId; $_SESSION['flash_success'] = 'Expense updated.';
    } else {
        $data['expense_number'] = generateNumber('EXP', 'expenses', 'expense_number');
        $cols = implode(',', array_keys($data)); $ph = implode(',', array_fill(0,count($data),'?'));
        $db->prepare("INSERT INTO expenses ($cols) VALUES ($ph)")->execute(array_values($data));
        $expId = $db->lastInsertId(); $_SESSION['flash_success'] = 'Expense created.';
    }
    foreach ($lineItems as $li) {
        $db->prepare("INSERT INTO expense_items (expense_id, description, quantity, unit_price, tax_percent, tax_amount, amount) VALUES (?,?,?,?,?,?,?)")
           ->execute([$expId, $li['desc'], $li['qty'], $li['price'], $li['taxP'], $li['taxAmt'], $li['lineAmt']]);
    }
    header('Location: ' . APP_URL . '/modules/expenses/index.php'); exit;
}

$search = get('search'); $filterType = get('ftype'); $status = get('status'); $page = max(1,(int)get('page',1));
$where=['1=1']; $params=[];
if ($search) { $where[]="(e.expense_number LIKE ? OR e.description LIKE ?)"; $s="%$search%"; $params=array_merge($params,[$s,$s]); }
if ($filterType) { $where[]="e.type=?"; $params[]=$filterType; }
if ($status) { $where[]="e.status=?"; $params[]=$status; }
$whereSQL = implode(' AND ',$where);
$total = $db->prepare("SELECT COUNT(*) FROM expenses e WHERE $whereSQL"); $total->execute($params);
$pg = paginate((int)$total->fetchColumn(),$page);
$stmt = $db->prepare("SELECT e.*, COALESCE(c.company_name, CONCAT(c.first_name,' ',c.last_name)) as contact_name, ec.name as category_name FROM expenses e LEFT JOIN contacts c ON e.contact_id=c.id LEFT JOIN expense_categories ec ON e.category_id=ec.id WHERE $whereSQL ORDER BY e.created_at DESC LIMIT {$pg['perPage']} OFFSET {$pg['offset']}");
$stmt->execute($params); $expenses = $stmt->fetchAll();

$contacts   = $db->query("SELECT id, COALESCE(company_name, CONCAT(first_name,' ',last_name)) as name FROM contacts WHERE status='active' ORDER BY name")->fetchAll();
$categories = $db->query("SELECT * FROM expense_categories ORDER BY name")->fetchAll();
$editExpense = null; $editItems = [];
if (get('edit')) { $s2=$db->prepare("SELECT * FROM expenses WHERE id=?"); $s2->execute([(int)get('edit')]); $editExpense=$s2->fetch(); $s3=$db->prepare("SELECT * FROM expense_items WHERE expense_id=?"); $s3->execute([(int)get('edit')]); $editItems=$s3->fetchAll(); }

include __DIR__ . '/../../includes/header.php';
?>

<div class="flex items-center justify-between mb-6">
  <div class="flex gap-2">
    <div class="relative">
      <input type="text" placeholder="Search..." value="<?= clean($search) ?>" class="form-input pl-9 py-2 text-sm w-56" onchange="location='?search='+encodeURIComponent(this.value)+'&ftype=<?= clean($filterType) ?>&status=<?= clean($status) ?>'">
      <i data-lucide="search" class="w-4 h-4 text-gray-400 absolute left-2.5 top-2.5"></i>
    </div>
    <select onchange="location='?ftype='+this.value+'&search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>'" class="form-input py-2 text-sm">
      <option value="">All Types</option>
      <option value="expense" <?= $filterType==='expense'?'selected':'' ?>>Expenses</option>
      <option value="bill" <?= $filterType==='bill'?'selected':'' ?>>Bills</option>
    </select>
    <select onchange="location='?status='+this.value+'&ftype=<?= urlencode($filterType) ?>&search=<?= urlencode($search) ?>'" class="form-input py-2 text-sm">
      <option value="">All Status</option>
      <?php foreach (['draft','pending','approved','paid','overdue','cancelled'] as $s): ?>
      <option value="<?=$s?>" <?= $status===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button onclick="openModal('expenseModal')" class="btn-primary">
    <i data-lucide="plus" class="w-4 h-4"></i> New Expense
  </button>
</div>

<!-- Summary Cards -->
<?php
$totals = $db->query("SELECT SUM(CASE WHEN type='expense' THEN total ELSE 0 END) as exp_total, SUM(CASE WHEN type='bill' THEN total ELSE 0 END) as bill_total, SUM(CASE WHEN type='bill' AND status IN('pending','approved') THEN balance_due ELSE 0 END) as payable FROM expenses WHERE status != 'cancelled'")->fetch();
?>
<div class="grid grid-cols-3 gap-4 mb-6">
  <div class="stat-card"><p class="text-xs text-gray-400 mb-1">Total Expenses</p><p class="text-xl font-bold text-gray-800"><?= formatCurrency($totals['exp_total']??0) ?></p></div>
  <div class="stat-card"><p class="text-xs text-gray-400 mb-1">Total Bills</p><p class="text-xl font-bold text-gray-800"><?= formatCurrency($totals['bill_total']??0) ?></p></div>
  <div class="stat-card"><p class="text-xs text-gray-400 mb-1">Bills Payable</p><p class="text-xl font-bold text-red-600"><?= formatCurrency($totals['payable']??0) ?></p></div>
</div>

<div class="card overflow-hidden">
  <table class="w-full">
    <thead class="bg-gray-50 border-b border-gray-100">
      <tr class="text-xs text-gray-500 font-medium">
        <th class="px-4 py-3 text-left">Ref #</th>
        <th class="px-4 py-3 text-left">Type</th>
        <th class="px-4 py-3 text-left">Category</th>
        <th class="px-4 py-3 text-left">Date</th>
        <th class="px-4 py-3 text-left">Vendor</th>
        <th class="px-4 py-3 text-right">Amount</th>
        <th class="px-4 py-3 text-center">Status</th>
        <th class="px-4 py-3 text-center">Actions</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-50">
      <?php foreach ($expenses as $e): ?>
      <tr class="table-row">
        <td class="px-4 py-3 text-xs font-semibold text-blue-600"><?= clean($e['expense_number']) ?></td>
        <td class="px-4 py-3"><span class="text-xs px-2 py-0.5 rounded-full <?= $e['type']==='bill'?'bg-purple-100 text-purple-700':'bg-orange-100 text-orange-700' ?>"><?= ucfirst($e['type']) ?></span></td>
        <td class="px-4 py-3 text-xs text-gray-600"><?= clean($e['category_name']??'-') ?></td>
        <td class="px-4 py-3 text-xs text-gray-500"><?= formatDate($e['expense_date']) ?></td>
        <td class="px-4 py-3 text-xs text-gray-600"><?= clean($e['contact_name']??'-') ?></td>
        <td class="px-4 py-3 text-xs text-right font-medium"><?= formatCurrency($e['total']) ?></td>
        <td class="px-4 py-3 text-center"><?= statusBadge($e['status']) ?></td>
        <td class="px-4 py-3 text-center">
          <div class="flex items-center justify-center gap-1">
            <a href="?edit=<?= $e['id'] ?>" class="p-1 rounded hover:bg-yellow-50 text-yellow-500"><i data-lucide="pencil" class="w-3.5 h-3.5"></i></a>
            <button onclick="confirmDelete('?action=delete&id=<?= $e['id'] ?>','Cancel this expense?')" class="p-1 rounded hover:bg-red-50 text-red-400"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$expenses): ?>
      <tr><td colspan="8" class="px-4 py-12 text-center text-sm text-gray-400">
        <i data-lucide="receipt" class="w-10 h-10 mx-auto mb-2 opacity-30"></i><p>No expenses yet.</p>
      </td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Expense Modal -->
<div id="expenseModal" class="modal-overlay <?= $editExpense?'':'hidden' ?>">
<div class="modal-box max-w-3xl">
  <div class="flex items-center justify-between px-6 py-4 border-b">
    <h2 class="text-base font-semibold"><?= $editExpense?'Edit Expense':'New Expense' ?></h2>
    <button onclick="closeModal('expenseModal')" class="text-gray-400"><i data-lucide="x" class="w-5 h-5"></i></button>
  </div>
  <form method="POST" class="p-6">
    <?php if ($editExpense): ?><input type="hidden" name="edit_id" value="<?= $editExpense['id'] ?>"><?php endif; ?>
    <div class="grid grid-cols-2 gap-4 mb-4">
      <div><label class="form-label">Type</label>
        <select name="type" class="form-input">
          <option value="expense" <?= ($editExpense['type']??'')==='expense'?'selected':'' ?>>Expense</option>
          <option value="bill" <?= ($editExpense['type']??'')==='bill'?'selected':'' ?>>Bill (Vendor Invoice)</option>
        </select>
      </div>
      <div><label class="form-label">Status</label>
        <select name="status" class="form-input">
          <?php foreach (['draft','pending','approved','paid'] as $s): ?>
          <option value="<?=$s?>" <?= ($editExpense['status']??'draft')===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label class="form-label">Expense Date *</label><input type="date" name="expense_date" required class="form-input" value="<?= $editExpense['expense_date']??date('Y-m-d') ?>"></div>
      <div><label class="form-label">Due Date</label><input type="date" name="due_date" class="form-input" value="<?= $editExpense['due_date']??'' ?>"></div>
      <div><label class="form-label">Category</label>
        <select name="category_id" class="form-input">
          <option value="">Select...</option>
          <?php foreach ($categories as $cat): ?>
          <option value="<?=$cat['id']?>" <?= ($editExpense['category_id']??0)==$cat['id']?'selected':'' ?>><?= clean($cat['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label class="form-label">Vendor</label>
        <select name="contact_id" class="form-input">
          <option value="">Select...</option>
          <?php foreach ($contacts as $c): ?>
          <option value="<?=$c['id']?>" <?= ($editExpense['contact_id']??0)==$c['id']?'selected':'' ?>><?= clean($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label class="form-label">Reference #</label><input type="text" name="reference" class="form-input" value="<?= clean($editExpense['reference']??'') ?>"></div>
      <div><label class="form-label">Description</label><input type="text" name="description" class="form-input" value="<?= clean($editExpense['description']??'') ?>"></div>
    </div>

    <!-- Line Items -->
    <div class="border border-gray-200 rounded-xl overflow-hidden mb-4">
      <table class="w-full text-xs">
        <thead class="bg-gray-50 border-b"><tr class="text-gray-500">
          <th class="px-3 py-2 text-left">Description</th>
          <th class="px-3 py-2 text-center w-16">Qty</th>
          <th class="px-3 py-2 text-right w-28">Unit Price</th>
          <th class="px-3 py-2 text-center w-16">Tax%</th>
          <th class="px-3 py-2 text-right w-24">Amount</th>
          <th class="w-8"></th>
        </tr></thead>
        <tbody id="expLineBody">
          <?php if ($editItems): foreach ($editItems as $li): ?>
          <tr class="exp-line border-b border-gray-50">
            <td class="px-2 py-1"><input type="text" name="item_desc[]" class="form-input text-xs py-1.5" value="<?= clean($li['description']) ?>" required></td>
            <td class="px-2 py-1"><input type="number" name="item_qty[]" class="form-input text-xs py-1.5 text-center" value="<?= $li['quantity'] ?>" min="0.001" step="0.001" oninput="calcExpLine(this)"></td>
            <td class="px-2 py-1"><input type="number" name="item_price[]" class="form-input text-xs py-1.5 text-right" value="<?= $li['unit_price'] ?>" min="0" step="0.01" oninput="calcExpLine(this)"></td>
            <td class="px-2 py-1"><input type="number" name="item_tax[]" class="form-input text-xs py-1.5 text-center" value="<?= $li['tax_percent'] ?>" min="0" max="100" oninput="calcExpLine(this)"></td>
            <td class="px-2 py-1"><input type="text" class="form-input text-xs py-1.5 text-right bg-gray-50" readonly value="<?= number_format($li['amount'],2) ?>"></td>
            <td class="px-2 py-1"><button type="button" onclick="this.closest('tr').remove();calcExpTotals()" class="text-red-400"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button></td>
          </tr>
          <?php endforeach; else: ?>
          <tr class="exp-line border-b border-gray-50">
            <td class="px-2 py-1"><input type="text" name="item_desc[]" class="form-input text-xs py-1.5" required placeholder="Description"></td>
            <td class="px-2 py-1"><input type="number" name="item_qty[]" class="form-input text-xs py-1.5 text-center" value="1" min="0.001" step="0.001" oninput="calcExpLine(this)"></td>
            <td class="px-2 py-1"><input type="number" name="item_price[]" class="form-input text-xs py-1.5 text-right" value="0.00" min="0" step="0.01" oninput="calcExpLine(this)"></td>
            <td class="px-2 py-1"><input type="number" name="item_tax[]" class="form-input text-xs py-1.5 text-center" value="0" min="0" max="100" oninput="calcExpLine(this)"></td>
            <td class="px-2 py-1"><input type="text" class="form-input text-xs py-1.5 text-right bg-gray-50" readonly value="0.00"></td>
            <td></td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
      <div class="px-4 py-2 border-t">
        <button type="button" onclick="addExpLine()" class="text-xs text-blue-600 hover:underline flex items-center gap-1"><i data-lucide="plus" class="w-3.5 h-3.5"></i> Add Line</button>
      </div>
    </div>
    <div class="flex justify-end text-sm font-bold gap-4 mb-4">
      <span class="text-gray-500">Total:</span><span id="expTotal">₦0.00</span>
    </div>
    <div><label class="form-label">Notes</label><textarea name="notes" rows="2" class="form-input"><?= clean($editExpense['notes']??'') ?></textarea></div>
    <div class="flex justify-end gap-3 mt-5 pt-4 border-t">
      <button type="button" onclick="closeModal('expenseModal')" class="btn-secondary">Cancel</button>
      <button type="submit" class="btn-primary"><i data-lucide="save" class="w-4 h-4"></i> Save</button>
    </div>
  </form>
</div>
</div>

<script>
function addExpLine() {
  document.getElementById('expLineBody').insertAdjacentHTML('beforeend', `<tr class="exp-line border-b border-gray-50">
    <td class="px-2 py-1"><input type="text" name="item_desc[]" class="form-input text-xs py-1.5" required placeholder="Description"></td>
    <td class="px-2 py-1"><input type="number" name="item_qty[]" class="form-input text-xs py-1.5 text-center" value="1" min="0.001" step="0.001" oninput="calcExpLine(this)"></td>
    <td class="px-2 py-1"><input type="number" name="item_price[]" class="form-input text-xs py-1.5 text-right" value="0.00" min="0" step="0.01" oninput="calcExpLine(this)"></td>
    <td class="px-2 py-1"><input type="number" name="item_tax[]" class="form-input text-xs py-1.5 text-center" value="0" min="0" max="100" oninput="calcExpLine(this)"></td>
    <td class="px-2 py-1"><input type="text" class="form-input text-xs py-1.5 text-right bg-gray-50" readonly value="0.00"></td>
    <td class="px-2 py-1"><button type="button" onclick="this.closest('tr').remove();calcExpTotals()" class="text-red-400"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button></td>
  </tr>`);
  lucide.createIcons();
}
function calcExpLine(el) {
  const row=el.closest('tr');
  const qty=parseFloat(row.querySelector('[name="item_qty[]"]').value)||0;
  const price=parseFloat(row.querySelector('[name="item_price[]"]').value)||0;
  const tax=parseFloat(row.querySelector('[name="item_tax[]"]').value)||0;
  const amt=qty*price*(1+tax/100);
  row.querySelector('input[readonly]').value=amt.toFixed(2);
  calcExpTotals();
}
function calcExpTotals() {
  let total=0;
  document.querySelectorAll('.exp-line input[readonly]').forEach(i=>total+=parseFloat(i.value)||0);
  document.getElementById('expTotal').textContent='₦'+total.toFixed(2);
}
calcExpTotals();
<?php if ($editExpense): ?>window.addEventListener('load',()=>openModal('expenseModal'));<?php endif; ?>
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
if (!hasPermission('invoices')) { $_SESSION['flash_error']='Access denied'; header('Location: '.APP_URL.'/modules/dashboard/index.php'); exit; }

$db = getDB();
$currentModule = 'invoices';
$pageTitle = 'Invoices';

// ── Handle Actions ────────────────────────────────────────────
$action = get('action');

if ($action === 'delete' && get('id')) {
    $id = (int)get('id');
    $db->prepare("UPDATE invoices SET status='void' WHERE id=?")->execute([$id]);
    $_SESSION['flash_success'] = 'Invoice voided successfully.';
    header('Location: ' . APP_URL . '/modules/invoices/index.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post = $_POST;
    $editId = (int)($post['edit_id'] ?? 0);

    // Build line items
    $descriptions = $post['item_desc'] ?? [];
    $quantities   = $post['item_qty'] ?? [];
    $prices       = $post['item_price'] ?? [];
    $taxPcts      = $post['item_tax'] ?? [];
    $discPcts     = $post['item_disc'] ?? [];

    $subtotal = 0; $taxTotal = 0;
    $lineItems = [];
    foreach ($descriptions as $i => $desc) {
        if (empty(trim($desc))) continue;
        $qty   = (float)($quantities[$i] ?? 1);
        $price = (float)($prices[$i] ?? 0);
        $taxP  = (float)($taxPcts[$i] ?? 0);
        $discP = (float)($discPcts[$i] ?? 0);
        $lineAmt = $qty * $price * (1 - $discP/100);
        $taxAmt  = $lineAmt * $taxP / 100;
        $subtotal += $lineAmt;
        $taxTotal += $taxAmt;
        $lineItems[] = compact('desc','qty','price','taxP','discP','lineAmt','taxAmt');
    }

    $discType  = $post['discount_type'] ?? 'percentage';
    $discVal   = (float)($post['discount_value'] ?? 0);
    $discAmt   = $discType === 'percentage' ? $subtotal * $discVal / 100 : $discVal;
    $shipping  = (float)($post['shipping_charge'] ?? 0);
    $total     = $subtotal - $discAmt + $taxTotal + $shipping;

    $data = [
        'contact_id'     => (int)$post['contact_id'],
        'issue_date'     => $post['issue_date'],
        'due_date'       => $post['due_date'],
        'status'         => $post['status'] ?? 'draft',
        'currency'       => $post['currency'] ?? 'NGN',
        'subtotal'       => $subtotal,
        'discount_type'  => $discType,
        'discount_value' => $discVal,
        'discount_amount'=> $discAmt,
        'tax_amount'     => $taxTotal,
        'shipping_charge'=> $shipping,
        'total'          => $total,
        'balance_due'    => $total,
        'notes'          => $post['notes'] ?? '',
        'terms'          => $post['terms'] ?? '',
        'created_by'     => $_SESSION['user_id'],
    ];

    if ($editId) {
        $inv = $db->prepare("SELECT amount_paid FROM invoices WHERE id=?")->execute([$editId]) ? $db->prepare("SELECT amount_paid FROM invoices WHERE id=?")->execute([$editId]) : null;
        $existing = $db->prepare("SELECT amount_paid FROM invoices WHERE id=?")->execute([$editId]);
        $paidRow = $db->prepare("SELECT amount_paid FROM invoices WHERE id=?");
        $paidRow->execute([$editId]);
        $paid = (float)($paidRow->fetchColumn() ?? 0);
        $data['balance_due'] = $total - $paid;
        $set = implode(', ', array_map(fn($k) => "$k=?", array_keys($data)));
        $db->prepare("UPDATE invoices SET $set WHERE id=?")->execute([...array_values($data), $editId]);
        $db->prepare("DELETE FROM invoice_items WHERE invoice_id=?")->execute([$editId]);
        $invoiceId = $editId;
        $_SESSION['flash_success'] = 'Invoice updated.';
    } else {
        $data['invoice_number'] = generateNumber('INV', 'invoices', 'invoice_number');
        $cols = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $db->prepare("INSERT INTO invoices ($cols) VALUES ($placeholders)")->execute(array_values($data));
        $invoiceId = $db->lastInsertId();
        $_SESSION['flash_success'] = 'Invoice created successfully.';
        auditLog('create', 'invoices', $invoiceId, [], $data);
    }

    foreach ($lineItems as $li) {
        $db->prepare("INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, discount_percent, tax_percent, tax_amount, amount) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$invoiceId, $li['desc'], $li['qty'], $li['price'], $li['discP'], $li['taxP'], $li['taxAmt'], $li['lineAmt']]);
    }

    header('Location: ' . APP_URL . '/modules/invoices/index.php'); exit;
}

// ── Filters ───────────────────────────────────────────────────
$search = get('search');
$status = get('status');
$page   = max(1, (int)get('page', 1));

$where = ['1=1']; $params = [];
if ($search) { $where[] = "(i.invoice_number LIKE ? OR c.company_name LIKE ? OR c.first_name LIKE ?)"; $s="%$search%"; $params = array_merge($params, [$s,$s,$s]); }
if ($status) { $where[] = "i.status=?"; $params[] = $status; }
$whereSQL = implode(' AND ', $where);

$totalCount = $db->prepare("SELECT COUNT(*) FROM invoices i LEFT JOIN contacts c ON i.contact_id=c.id WHERE $whereSQL");
$totalCount->execute($params);
$pg = paginate((int)$totalCount->fetchColumn(), $page);

$stmt = $db->prepare("
    SELECT i.*, COALESCE(c.company_name, CONCAT(c.first_name,' ',c.last_name)) as contact_name
    FROM invoices i LEFT JOIN contacts c ON i.contact_id=c.id
    WHERE $whereSQL ORDER BY i.created_at DESC LIMIT {$pg['perPage']} OFFSET {$pg['offset']}
");
$stmt->execute($params);
$invoices = $stmt->fetchAll();

// For form
$contacts = $db->query("SELECT id, COALESCE(company_name, CONCAT(first_name,' ',last_name)) as name FROM contacts WHERE status='active' ORDER BY name")->fetchAll();
$editInvoice = null; $editItems = [];
if (get('edit')) {
    $editInvoice = $db->prepare("SELECT * FROM invoices WHERE id=?")->execute([(int)get('edit')]) ? null : null;
    $s2 = $db->prepare("SELECT * FROM invoices WHERE id=?"); $s2->execute([(int)get('edit')]); $editInvoice = $s2->fetch();
    $s3 = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id=?"); $s3->execute([(int)get('edit')]); $editItems = $s3->fetchAll();
}

include __DIR__ . '/../../includes/header.php';
?>

<!-- Header -->
<div class="flex items-center justify-between mb-6">
  <div class="flex items-center gap-3">
    <div class="relative">
      <input type="text" placeholder="Search invoices..." value="<?= clean($search) ?>"
             class="form-input pl-9 py-2 text-sm w-64"
             onchange="location='?search='+encodeURIComponent(this.value)+'&status=<?= clean($status) ?>'">
      <i data-lucide="search" class="w-4 h-4 text-gray-400 absolute left-2.5 top-2.5"></i>
    </div>
    <select onchange="location='?status='+this.value+'&search=<?= urlencode($search) ?>'" class="form-input py-2 text-sm w-40">
      <option value="">All Status</option>
      <?php foreach (['draft','sent','paid','partially_paid','overdue','cancelled','void'] as $s): ?>
      <option value="<?=$s?>" <?= $status===$s?'selected':'' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button onclick="openModal('invoiceModal')" class="btn-primary">
    <i data-lucide="plus" class="w-4 h-4"></i> New Invoice
  </button>
</div>

<!-- Table -->
<div class="card overflow-hidden">
  <table class="w-full">
    <thead class="bg-gray-50 border-b border-gray-100">
      <tr class="text-xs text-gray-500 font-medium">
        <th class="px-4 py-3 text-left">Invoice #</th>
        <th class="px-4 py-3 text-left">Customer</th>
        <th class="px-4 py-3 text-left">Issue Date</th>
        <th class="px-4 py-3 text-left">Due Date</th>
        <th class="px-4 py-3 text-right">Amount</th>
        <th class="px-4 py-3 text-right">Balance Due</th>
        <th class="px-4 py-3 text-center">Status</th>
        <th class="px-4 py-3 text-center">Actions</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-50">
      <?php foreach ($invoices as $inv): ?>
      <tr class="table-row">
        <td class="px-4 py-3 text-xs font-semibold text-blue-600">
          <a href="view.php?id=<?= $inv['id'] ?>"><?= clean($inv['invoice_number']) ?></a>
        </td>
        <td class="px-4 py-3 text-xs text-gray-700"><?= clean($inv['contact_name']) ?></td>
        <td class="px-4 py-3 text-xs text-gray-500"><?= formatDate($inv['issue_date']) ?></td>
        <td class="px-4 py-3 text-xs text-gray-500"><?= formatDate($inv['due_date']) ?></td>
        <td class="px-4 py-3 text-xs text-right font-medium"><?= formatCurrency($inv['total']) ?></td>
        <td class="px-4 py-3 text-xs text-right font-medium <?= $inv['balance_due']>0?'text-red-600':'' ?>"><?= formatCurrency($inv['balance_due']) ?></td>
        <td class="px-4 py-3 text-center"><?= statusBadge($inv['status']) ?></td>
        <td class="px-4 py-3 text-center">
          <div class="flex items-center justify-center gap-1">
            <a href="view.php?id=<?= $inv['id'] ?>" class="p-1 rounded hover:bg-blue-50 text-blue-500" title="View"><i data-lucide="eye" class="w-3.5 h-3.5"></i></a>
            <a href="?edit=<?= $inv['id'] ?>" class="p-1 rounded hover:bg-yellow-50 text-yellow-500" title="Edit"><i data-lucide="pencil" class="w-3.5 h-3.5"></i></a>
            <a href="record_payment.php?id=<?= $inv['id'] ?>" class="p-1 rounded hover:bg-green-50 text-green-500" title="Record Payment"><i data-lucide="credit-card" class="w-3.5 h-3.5"></i></a>
            <button onclick="confirmDelete('?action=delete&id=<?= $inv['id'] ?>','Void this invoice?')" class="p-1 rounded hover:bg-red-50 text-red-400" title="Void"><i data-lucide="ban" class="w-3.5 h-3.5"></i></button>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$invoices): ?>
      <tr><td colspan="8" class="px-4 py-12 text-center text-sm text-gray-400">
        <i data-lucide="file-text" class="w-10 h-10 mx-auto mb-2 opacity-30"></i>
        <p>No invoices found. Create your first invoice!</p>
      </td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <!-- Pagination -->
  <?php if ($pg['totalPages'] > 1): ?>
  <div class="px-4 py-3 border-t border-gray-100 flex items-center justify-between">
    <p class="text-xs text-gray-500">Showing <?= count($invoices) ?> of <?= $pg['total'] ?> invoices</p>
    <div class="flex gap-1">
      <?php for ($p=1;$p<=$pg['totalPages'];$p++): ?>
      <a href="?page=<?=$p?>&search=<?=urlencode($search)?>&status=<?=urlencode($status)?>"
         class="px-3 py-1 text-xs rounded <?= $p===$pg['page']?'bg-blue-600 text-white':'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>"><?=$p?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Invoice Modal -->
<div id="invoiceModal" class="modal-overlay <?= ($editInvoice || !empty($_POST)) ? '' : 'hidden' ?>">
<div class="modal-box max-w-4xl">
  <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
    <h2 class="text-base font-semibold"><?= $editInvoice ? 'Edit Invoice' : 'New Invoice' ?></h2>
    <button onclick="closeModal('invoiceModal')" class="text-gray-400 hover:text-gray-600"><i data-lucide="x" class="w-5 h-5"></i></button>
  </div>
  <form method="POST" class="p-6">
    <?php if ($editInvoice): ?>
    <input type="hidden" name="edit_id" value="<?= $editInvoice['id'] ?>">
    <?php endif; ?>

    <div class="grid grid-cols-2 gap-4 mb-4">
      <div>
        <label class="form-label">Customer *</label>
        <select name="contact_id" required class="form-input">
          <option value="">Select customer...</option>
          <?php foreach ($contacts as $c): ?>
          <option value="<?=$c['id']?>" <?= ($editInvoice && $editInvoice['contact_id']==$c['id'])?'selected':'' ?>><?= clean($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Status</label>
        <select name="status" class="form-input">
          <?php foreach (['draft','sent','paid','partially_paid','overdue'] as $s): ?>
          <option value="<?=$s?>" <?= ($editInvoice && $editInvoice['status']===$s)?'selected':($s==='draft'?'selected':'') ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Issue Date *</label>
        <input type="date" name="issue_date" required class="form-input" value="<?= $editInvoice['issue_date'] ?? date('Y-m-d') ?>">
      </div>
      <div>
        <label class="form-label">Due Date *</label>
        <input type="date" name="due_date" required class="form-input" value="<?= $editInvoice['due_date'] ?? date('Y-m-d', strtotime('+30 days')) ?>">
      </div>
    </div>

    <!-- Line Items -->
    <div class="border border-gray-200 rounded-xl overflow-hidden mb-4">
      <div class="bg-gray-50 px-4 py-2.5 border-b border-gray-200">
        <p class="text-xs font-medium text-gray-600">Line Items</p>
      </div>
      <table class="w-full text-xs">
        <thead class="bg-gray-50 border-b border-gray-100">
          <tr class="text-gray-500">
            <th class="px-3 py-2 text-left">Description</th>
            <th class="px-3 py-2 text-center w-16">Qty</th>
            <th class="px-3 py-2 text-right w-28">Unit Price</th>
            <th class="px-3 py-2 text-center w-16">Disc%</th>
            <th class="px-3 py-2 text-center w-16">Tax%</th>
            <th class="px-3 py-2 text-right w-28">Amount</th>
            <th class="px-3 py-2 w-8"></th>
          </tr>
        </thead>
        <tbody id="lineItemsBody">
          <?php if ($editItems): foreach ($editItems as $li): ?>
          <tr class="line-item border-b border-gray-50">
            <td class="px-2 py-1"><input type="text" name="item_desc[]" class="form-input text-xs py-1.5" value="<?= clean($li['description']) ?>" placeholder="Item description" required></td>
            <td class="px-2 py-1"><input type="number" name="item_qty[]" class="form-input text-xs py-1.5 text-center" value="<?= $li['quantity'] ?>" min="0.001" step="0.001" oninput="calcLine(this)"></td>
            <td class="px-2 py-1"><input type="number" name="item_price[]" class="form-input text-xs py-1.5 text-right" value="<?= $li['unit_price'] ?>" min="0" step="0.01" oninput="calcLine(this)"></td>
            <td class="px-2 py-1"><input type="number" name="item_disc[]" class="form-input text-xs py-1.5 text-center" value="<?= $li['discount_percent'] ?>" min="0" max="100" oninput="calcLine(this)"></td>
            <td class="px-2 py-1"><input type="number" name="item_tax[]" class="form-input text-xs py-1.5 text-center" value="<?= $li['tax_percent'] ?>" min="0" max="100" oninput="calcLine(this)"></td>
            <td class="px-2 py-1"><input type="text" class="form-input text-xs py-1.5 text-right bg-gray-50" readonly value="<?= number_format($li['amount'],2) ?>"></td>
            <td class="px-2 py-1"><button type="button" onclick="removeLine(this)" class="text-red-400 hover:text-red-600"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button></td>
          </tr>
          <?php endforeach; else: ?>
          <tr class="line-item border-b border-gray-50">
            <td class="px-2 py-1"><input type="text" name="item_desc[]" class="form-input text-xs py-1.5" placeholder="Item description" required></td>
            <td class="px-2 py-1"><input type="number" name="item_qty[]" class="form-input text-xs py-1.5 text-center" value="1" min="0.001" step="0.001" oninput="calcLine(this)"></td>
            <td class="px-2 py-1"><input type="number" name="item_price[]" class="form-input text-xs py-1.5 text-right" value="0.00" min="0" step="0.01" oninput="calcLine(this)"></td>
            <td class="px-2 py-1"><input type="number" name="item_disc[]" class="form-input text-xs py-1.5 text-center" value="0" min="0" max="100" oninput="calcLine(this)"></td>
            <td class="px-2 py-1"><input type="number" name="item_tax[]" class="form-input text-xs py-1.5 text-center" value="0" min="0" max="100" oninput="calcLine(this)"></td>
            <td class="px-2 py-1"><input type="text" class="form-input text-xs py-1.5 text-right bg-gray-50" readonly value="0.00"></td>
            <td class="px-2 py-1"></td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
      <div class="px-4 py-2 border-t border-gray-100">
        <button type="button" onclick="addLine()" class="text-xs text-blue-600 hover:underline flex items-center gap-1">
          <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add Line Item
        </button>
      </div>
    </div>

    <!-- Totals + Notes -->
    <div class="grid grid-cols-2 gap-6">
      <div class="space-y-3">
        <div>
          <label class="form-label">Notes</label>
          <textarea name="notes" rows="3" class="form-input" placeholder="Notes for the customer..."><?= clean($editInvoice['notes'] ?? '') ?></textarea>
        </div>
        <div>
          <label class="form-label">Terms & Conditions</label>
          <textarea name="terms" rows="2" class="form-input" placeholder="Payment terms..."><?= clean($editInvoice['terms'] ?? '') ?></textarea>
        </div>
      </div>
      <div class="space-y-2 text-sm">
        <div class="flex justify-between text-gray-500 text-xs"><span>Subtotal</span><span id="subtotalDisplay">₦0.00</span></div>
        <div class="flex items-center gap-2 text-xs">
          <span class="text-gray-500">Discount</span>
          <select name="discount_type" class="form-input py-1 text-xs w-28">
            <option value="percentage" <?= ($editInvoice['discount_type']??'')==='percentage'?'selected':'' ?>>Percentage</option>
            <option value="fixed" <?= ($editInvoice['discount_type']??'')==='fixed'?'selected':'' ?>>Fixed</option>
          </select>
          <input type="number" name="discount_value" value="<?= $editInvoice['discount_value'] ?? 0 ?>" min="0" step="0.01" class="form-input py-1 text-xs w-24 text-right" oninput="calcTotals()">
        </div>
        <div class="flex justify-between text-gray-500 text-xs"><span>Tax</span><span id="taxDisplay">₦0.00</span></div>
        <div class="flex items-center gap-2 text-xs">
          <span class="text-gray-500">Shipping</span>
          <input type="number" name="shipping_charge" value="<?= $editInvoice['shipping_charge'] ?? 0 ?>" min="0" step="0.01" class="form-input py-1 text-xs w-28 text-right ml-auto" oninput="calcTotals()">
        </div>
        <div class="flex justify-between font-bold text-gray-800 pt-2 border-t border-gray-200"><span>Total</span><span id="totalDisplay">₦0.00</span></div>
      </div>
    </div>

    <div class="flex justify-end gap-3 mt-5 pt-5 border-t border-gray-100">
      <button type="button" onclick="closeModal('invoiceModal')" class="btn-secondary">Cancel</button>
      <button type="submit" class="btn-primary"><i data-lucide="save" class="w-4 h-4"></i> Save Invoice</button>
    </div>
  </form>
</div>
</div>

<script>
function addLine() {
  const body = document.getElementById('lineItemsBody');
  const row = `<tr class="line-item border-b border-gray-50">
    <td class="px-2 py-1"><input type="text" name="item_desc[]" class="form-input text-xs py-1.5" placeholder="Item description" required></td>
    <td class="px-2 py-1"><input type="number" name="item_qty[]" class="form-input text-xs py-1.5 text-center" value="1" min="0.001" step="0.001" oninput="calcLine(this)"></td>
    <td class="px-2 py-1"><input type="number" name="item_price[]" class="form-input text-xs py-1.5 text-right" value="0.00" min="0" step="0.01" oninput="calcLine(this)"></td>
    <td class="px-2 py-1"><input type="number" name="item_disc[]" class="form-input text-xs py-1.5 text-center" value="0" min="0" max="100" oninput="calcLine(this)"></td>
    <td class="px-2 py-1"><input type="number" name="item_tax[]" class="form-input text-xs py-1.5 text-center" value="0" min="0" max="100" oninput="calcLine(this)"></td>
    <td class="px-2 py-1"><input type="text" class="form-input text-xs py-1.5 text-right bg-gray-50" readonly value="0.00"></td>
    <td class="px-2 py-1"><button type="button" onclick="removeLine(this)" class="text-red-400 hover:text-red-600"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button></td>
  </tr>`;
  body.insertAdjacentHTML('beforeend', row);
  lucide.createIcons();
}
function removeLine(btn) {
  const rows = document.querySelectorAll('.line-item');
  if (rows.length <= 1) return;
  btn.closest('tr').remove();
  calcTotals();
}
function calcLine(el) {
  const row = el.closest('tr');
  const qty   = parseFloat(row.querySelector('[name="item_qty[]"]').value) || 0;
  const price = parseFloat(row.querySelector('[name="item_price[]"]').value) || 0;
  const disc  = parseFloat(row.querySelector('[name="item_disc[]"]').value) || 0;
  const tax   = parseFloat(row.querySelector('[name="item_tax[]"]').value) || 0;
  const lineAmt = qty * price * (1 - disc/100);
  const taxAmt  = lineAmt * tax / 100;
  row.querySelectorAll('input[readonly]')[0].value = (lineAmt + taxAmt).toFixed(2);
  calcTotals();
}
function calcTotals() {
  let subtotal=0, taxTotal=0;
  document.querySelectorAll('.line-item').forEach(row => {
    const qty   = parseFloat(row.querySelector('[name="item_qty[]"]').value) || 0;
    const price = parseFloat(row.querySelector('[name="item_price[]"]').value) || 0;
    const disc  = parseFloat(row.querySelector('[name="item_disc[]"]').value) || 0;
    const tax   = parseFloat(row.querySelector('[name="item_tax[]"]').value) || 0;
    const lineAmt = qty * price * (1 - disc/100);
    subtotal  += lineAmt;
    taxTotal  += lineAmt * tax / 100;
  });
  const discType = document.querySelector('[name="discount_type"]').value;
  const discVal  = parseFloat(document.querySelector('[name="discount_value"]').value) || 0;
  const discAmt  = discType==='percentage' ? subtotal*discVal/100 : discVal;
  const shipping = parseFloat(document.querySelector('[name="shipping_charge"]').value) || 0;
  const total = subtotal - discAmt + taxTotal + shipping;
  document.getElementById('subtotalDisplay').textContent = '₦' + subtotal.toFixed(2);
  document.getElementById('taxDisplay').textContent = '₦' + taxTotal.toFixed(2);
  document.getElementById('totalDisplay').textContent = '₦' + total.toFixed(2);
}
calcTotals();
<?php if ($editInvoice): ?>window.addEventListener('load',()=>openModal('invoiceModal'));<?php endif; ?>
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

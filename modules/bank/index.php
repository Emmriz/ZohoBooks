<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
if (!hasPermission('bank')) { $_SESSION['flash_error']='Access denied'; header('Location: '.APP_URL.'/modules/dashboard/index.php'); exit; }

$db = getDB();
$currentModule = 'bank';
$pageTitle = 'Banking';

// Handle bank account creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'bank_account') {
    $editId = (int)post('edit_id');
    $data = [
        'account_id'     => (int)post('account_id'),
        'bank_name'      => post('bank_name'),
        'account_number' => post('account_number'),
        'account_holder' => post('account_holder'),
        'branch'         => post('branch'),
        'currency'       => post('currency','NGN'),
        'opening_balance'=> (float)post('opening_balance'),
        'current_balance'=> (float)post('opening_balance'),
        'status'         => 'active',
    ];
    if ($editId) {
        $set = implode(', ', array_map(fn($k)=>"$k=?", array_keys($data)));
        $db->prepare("UPDATE bank_accounts SET $set WHERE id=?")->execute([...array_values($data), $editId]);
        $_SESSION['flash_success'] = 'Bank account updated.';
    } else {
        $cols = implode(',', array_keys($data)); $ph = implode(',', array_fill(0,count($data),'?'));
        $db->prepare("INSERT INTO bank_accounts ($cols) VALUES ($ph)")->execute(array_values($data));
        $_SESSION['flash_success'] = 'Bank account added.';
    }
    header('Location: ' . APP_URL . '/modules/bank/index.php'); exit;
}

// Handle transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'transaction') {
    $bankId = (int)post('bank_account_id');
    $amount = (float)post('amount');
    $type   = post('type');
    $db->prepare("INSERT INTO bank_transactions (bank_account_id, transaction_date, description, reference, type, amount) VALUES (?,?,?,?,?,?)")
       ->execute([$bankId, post('transaction_date'), post('description'), post('reference'), $type, $amount]);
    $op = $type === 'credit' ? '+' : '-';
    $db->prepare("UPDATE bank_accounts SET current_balance = current_balance $op ? WHERE id=?")->execute([$amount, $bankId]);
    $_SESSION['flash_success'] = 'Transaction recorded.';
    header('Location: ' . APP_URL . '/modules/bank/index.php?view=' . $bankId); exit;
}

// Handle reconcile
if (get('action') === 'reconcile' && get('txn_id')) {
    $txnId = (int)get('txn_id');
    $db->prepare("UPDATE bank_transactions SET is_reconciled=1, reconciled_date=NOW() WHERE id=?")->execute([$txnId]);
    $_SESSION['flash_success'] = 'Transaction reconciled.';
    header('Location: ' . APP_URL . '/modules/bank/index.php?view=' . get('bank_id')); exit;
}

$bankAccounts = $db->query("SELECT ba.*, a.account_name FROM bank_accounts ba LEFT JOIN accounts a ON ba.account_id=a.id WHERE ba.status='active' ORDER BY ba.bank_name")->fetchAll();
$accounts = $db->query("SELECT id, account_name FROM accounts WHERE account_type='asset' AND sub_type IN('bank','cash') AND status='active'")->fetchAll();

$viewId = (int)get('view');
$viewAccount = null;
$transactions = [];
if ($viewId) {
    $s = $db->prepare("SELECT ba.*, a.account_name FROM bank_accounts ba LEFT JOIN accounts a ON ba.account_id=a.id WHERE ba.id=?");
    $s->execute([$viewId]); $viewAccount = $s->fetch();
    $page = max(1,(int)get('page',1));
    $pg = paginate((int)$db->prepare("SELECT COUNT(*) FROM bank_transactions WHERE bank_account_id=?")->execute([$viewId]) ?: 0, $page);
    $cnt = $db->prepare("SELECT COUNT(*) FROM bank_transactions WHERE bank_account_id=?"); $cnt->execute([$viewId]);
    $pg = paginate((int)$cnt->fetchColumn(), $page);
    $txnStmt = $db->prepare("SELECT * FROM bank_transactions WHERE bank_account_id=? ORDER BY transaction_date DESC, id DESC LIMIT {$pg['perPage']} OFFSET {$pg['offset']}");
    $txnStmt->execute([$viewId]); $transactions = $txnStmt->fetchAll();
}

$editBankAcc = null;
if (get('edit')) { $s=$db->prepare("SELECT * FROM bank_accounts WHERE id=?"); $s->execute([(int)get('edit')]); $editBankAcc=$s->fetch(); }

include __DIR__ . '/../../includes/header.php';
?>

<div class="flex items-center justify-between mb-6">
  <?php if ($viewAccount): ?>
  <a href="<?= APP_URL ?>/modules/bank/index.php" class="btn-secondary text-xs"><i data-lucide="arrow-left" class="w-4 h-4"></i> All Accounts</a>
  <div class="flex gap-2">
    <button onclick="openModal('txnModal')" class="btn-primary"><i data-lucide="plus" class="w-4 h-4"></i> Add Transaction</button>
  </div>
  <?php else: ?>
  <div></div>
  <button onclick="openModal('bankModal')" class="btn-primary"><i data-lucide="plus" class="w-4 h-4"></i> Add Bank Account</button>
  <?php endif; ?>
</div>

<?php if (!$viewAccount): ?>
<!-- Bank Accounts Grid -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
  <?php foreach ($bankAccounts as $ba): ?>
  <div class="card p-5 cursor-pointer hover:shadow-md transition-shadow" onclick="location='?view=<?= $ba['id'] ?>'">
    <div class="flex items-start justify-between mb-4">
      <div class="w-10 h-10 rounded-xl bg-blue-100 flex items-center justify-center">
        <i data-lucide="building-2" class="w-5 h-5 text-blue-600"></i>
      </div>
      <div class="flex gap-1">
        <a href="?edit=<?= $ba['id'] ?>" onclick="event.stopPropagation()" class="p-1 rounded hover:bg-yellow-50 text-yellow-500"><i data-lucide="pencil" class="w-3.5 h-3.5"></i></a>
      </div>
    </div>
    <h3 class="font-semibold text-gray-800"><?= clean($ba['bank_name']) ?></h3>
    <p class="text-sm text-gray-400 mb-1"><?= clean($ba['account_number']) ?></p>
    <p class="text-xs text-gray-400 mb-4"><?= clean($ba['account_holder']) ?></p>
    <div class="border-t pt-3">
      <p class="text-xs text-gray-400">Current Balance</p>
      <p class="text-xl font-bold <?= $ba['current_balance']>=0?'text-gray-800':'text-red-600' ?>"><?= formatCurrency($ba['current_balance']) ?></p>
    </div>
    <p class="text-xs text-gray-400 mt-2"><?= clean($ba['account_name']) ?></p>
  </div>
  <?php endforeach; ?>
  <?php if (!$bankAccounts): ?>
  <div class="col-span-3 card p-12 text-center text-gray-400">
    <i data-lucide="building-2" class="w-10 h-10 mx-auto mb-2 opacity-30"></i>
    <p>No bank accounts added yet.</p>
  </div>
  <?php endif; ?>
</div>

<?php else: ?>
<!-- Single Account View -->
<div class="card p-5 mb-6">
  <div class="flex items-center justify-between">
    <div>
      <h2 class="text-lg font-bold text-gray-800"><?= clean($viewAccount['bank_name']) ?></h2>
      <p class="text-sm text-gray-400"><?= clean($viewAccount['account_number']) ?> &bull; <?= clean($viewAccount['account_holder']) ?></p>
    </div>
    <div class="text-right">
      <p class="text-xs text-gray-400">Current Balance</p>
      <p class="text-2xl font-bold text-blue-600"><?= formatCurrency($viewAccount['current_balance']) ?></p>
    </div>
  </div>
</div>

<div class="card overflow-hidden">
  <div class="px-5 py-3 border-b bg-gray-50 flex items-center justify-between">
    <h3 class="text-sm font-semibold">Transactions</h3>
    <span class="text-xs text-gray-400"><?= $pg['total'] ?> total</span>
  </div>
  <table class="w-full">
    <thead class="bg-gray-50 border-b border-gray-100">
      <tr class="text-xs text-gray-500 font-medium">
        <th class="px-4 py-3 text-left">Date</th>
        <th class="px-4 py-3 text-left">Description</th>
        <th class="px-4 py-3 text-left">Reference</th>
        <th class="px-4 py-3 text-center">Type</th>
        <th class="px-4 py-3 text-right">Amount</th>
        <th class="px-4 py-3 text-center">Reconciled</th>
        <th class="px-4 py-3 text-center">Action</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-50">
      <?php foreach ($transactions as $txn): ?>
      <tr class="table-row <?= $txn['is_reconciled']?'opacity-60':'' ?>">
        <td class="px-4 py-3 text-xs"><?= formatDate($txn['transaction_date']) ?></td>
        <td class="px-4 py-3 text-xs text-gray-700"><?= clean($txn['description']) ?></td>
        <td class="px-4 py-3 text-xs text-gray-400"><?= clean($txn['reference']) ?></td>
        <td class="px-4 py-3 text-center">
          <span class="text-xs px-2 py-0.5 rounded-full <?= $txn['type']==='credit'?'bg-green-100 text-green-700':'bg-red-100 text-red-700' ?>"><?= ucfirst($txn['type']) ?></span>
        </td>
        <td class="px-4 py-3 text-xs text-right font-medium <?= $txn['type']==='credit'?'text-green-600':'text-red-600' ?>">
          <?= $txn['type']==='credit'?'+':'-' ?><?= formatCurrency($txn['amount']) ?>
        </td>
        <td class="px-4 py-3 text-center">
          <?php if ($txn['is_reconciled']): ?>
          <span class="text-xs text-green-600 flex items-center justify-center gap-1"><i data-lucide="check-circle" class="w-3.5 h-3.5"></i> Yes</span>
          <?php else: ?>
          <span class="text-xs text-gray-400">No</span>
          <?php endif; ?>
        </td>
        <td class="px-4 py-3 text-center">
          <?php if (!$txn['is_reconciled']): ?>
          <a href="?action=reconcile&txn_id=<?= $txn['id'] ?>&bank_id=<?= $viewId ?>" class="text-xs text-blue-600 hover:underline">Reconcile</a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$transactions): ?>
      <tr><td colspan="7" class="px-4 py-12 text-center text-sm text-gray-400">No transactions yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
  <!-- Pagination -->
  <?php if ($pg['totalPages']>1): ?>
  <div class="px-4 py-3 border-t flex justify-end gap-1">
    <?php for($p=1;$p<=$pg['totalPages'];$p++): ?>
    <a href="?view=<?=$viewId?>&page=<?=$p?>" class="px-3 py-1 text-xs rounded <?= $p===$pg['page']?'bg-blue-600 text-white':'bg-gray-100 text-gray-600' ?>"><?=$p?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Add Transaction Modal -->
<div id="txnModal" class="modal-overlay hidden">
<div class="modal-box max-w-md">
  <div class="flex items-center justify-between px-6 py-4 border-b">
    <h2 class="text-base font-semibold">Add Transaction</h2>
    <button onclick="closeModal('txnModal')" class="text-gray-400"><i data-lucide="x" class="w-5 h-5"></i></button>
  </div>
  <form method="POST" class="p-6 space-y-4">
    <input type="hidden" name="form" value="transaction">
    <input type="hidden" name="bank_account_id" value="<?= $viewId ?>">
    <div class="grid grid-cols-2 gap-4">
      <div><label class="form-label">Date *</label><input type="date" name="transaction_date" required class="form-input" value="<?= date('Y-m-d') ?>"></div>
      <div><label class="form-label">Type *</label>
        <select name="type" required class="form-input">
          <option value="credit">Credit (Money In)</option>
          <option value="debit">Debit (Money Out)</option>
        </select>
      </div>
    </div>
    <div><label class="form-label">Amount *</label><input type="number" name="amount" required class="form-input" min="0.01" step="0.01" placeholder="0.00"></div>
    <div><label class="form-label">Description *</label><input type="text" name="description" required class="form-input" placeholder="Transaction description"></div>
    <div><label class="form-label">Reference</label><input type="text" name="reference" class="form-input" placeholder="Cheque no., transfer ref..."></div>
    <div class="flex justify-end gap-3 pt-2">
      <button type="button" onclick="closeModal('txnModal')" class="btn-secondary">Cancel</button>
      <button type="submit" class="btn-primary"><i data-lucide="save" class="w-4 h-4"></i> Save</button>
    </div>
  </form>
</div>
</div>
<?php endif; ?>

<!-- Bank Account Modal -->
<div id="bankModal" class="modal-overlay <?= $editBankAcc?'':'hidden' ?>">
<div class="modal-box max-w-lg">
  <div class="flex items-center justify-between px-6 py-4 border-b">
    <h2 class="text-base font-semibold"><?= $editBankAcc?'Edit Bank Account':'Add Bank Account' ?></h2>
    <button onclick="closeModal('bankModal')" class="text-gray-400"><i data-lucide="x" class="w-5 h-5"></i></button>
  </div>
  <form method="POST" class="p-6">
    <input type="hidden" name="form" value="bank_account">
    <?php if ($editBankAcc): ?><input type="hidden" name="edit_id" value="<?= $editBankAcc['id'] ?>"><?php endif; ?>
    <div class="grid grid-cols-2 gap-4">
      <div class="col-span-2"><label class="form-label">Bank Name *</label><input type="text" name="bank_name" required class="form-input" value="<?= clean($editBankAcc['bank_name']??'') ?>" placeholder="First Bank, GTBank..."></div>
      <div><label class="form-label">Account Number</label><input type="text" name="account_number" class="form-input" value="<?= clean($editBankAcc['account_number']??'') ?>"></div>
      <div><label class="form-label">Account Holder</label><input type="text" name="account_holder" class="form-input" value="<?= clean($editBankAcc['account_holder']??'') ?>"></div>
      <div><label class="form-label">Branch</label><input type="text" name="branch" class="form-input" value="<?= clean($editBankAcc['branch']??'') ?>"></div>
      <div><label class="form-label">Currency</label>
        <select name="currency" class="form-input">
          <?php foreach (['NGN','USD','EUR','GBP','GHS'] as $cur): ?>
          <option value="<?=$cur?>" <?= ($editBankAcc['currency']??'NGN')===$cur?'selected':'' ?>><?=$cur?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label class="form-label">Opening Balance</label><input type="number" name="opening_balance" class="form-input" value="<?= $editBankAcc['opening_balance']??0 ?>" min="0" step="0.01"></div>
      <div><label class="form-label">Linked Account *</label>
        <select name="account_id" required class="form-input">
          <option value="">Select...</option>
          <?php foreach ($accounts as $acc): ?>
          <option value="<?=$acc['id']?>" <?= ($editBankAcc['account_id']??0)==$acc['id']?'selected':'' ?>><?= clean($acc['account_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="flex justify-end gap-3 mt-5 pt-4 border-t">
      <button type="button" onclick="closeModal('bankModal')" class="btn-secondary">Cancel</button>
      <button type="submit" class="btn-primary"><i data-lucide="save" class="w-4 h-4"></i> Save</button>
    </div>
  </form>
</div>
</div>
<?php if ($editBankAcc): ?><script>window.addEventListener('load',()=>openModal('bankModal'));</script><?php endif; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

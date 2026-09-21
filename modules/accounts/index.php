<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
if (!hasPermission('accounts')) { $_SESSION['flash_error']='Access denied'; header('Location: '.APP_URL.'/modules/dashboard/index.php'); exit; }

$db = getDB();
$currentModule = 'accounts';
$pageTitle = 'Chart of Accounts';

if (get('action') === 'delete' && get('id')) {
    $chk = $db->prepare("SELECT is_system FROM accounts WHERE id=?"); $chk->execute([(int)get('id')]);
    $acc = $chk->fetch();
    if ($acc && $acc['is_system']) { $_SESSION['flash_error']='Cannot delete system accounts.'; }
    else { $db->prepare("UPDATE accounts SET status='inactive' WHERE id=?")->execute([(int)get('id')]); $_SESSION['flash_success']='Account deactivated.'; }
    header('Location: ' . APP_URL . '/modules/accounts/index.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $editId = (int)post('edit_id');
    $data = [
        'group_id'     => (int)post('group_id') ?: null,
        'account_code' => post('account_code'),
        'account_name' => post('account_name'),
        'account_type' => post('account_type'),
        'sub_type'     => post('sub_type'),
        'description'  => post('description'),
        'currency'     => post('currency','NGN'),
        'status'       => 'active',
    ];
    if ($editId) {
        $set = implode(', ', array_map(fn($k)=>"$k=?", array_keys($data)));
        $db->prepare("UPDATE accounts SET $set WHERE id=?")->execute([...array_values($data), $editId]);
        $_SESSION['flash_success'] = 'Account updated.';
    } else {
        $cols = implode(',', array_keys($data)); $ph = implode(',', array_fill(0,count($data),'?'));
        $db->prepare("INSERT INTO accounts ($cols) VALUES ($ph)")->execute(array_values($data));
        $_SESSION['flash_success'] = 'Account created.';
    }
    header('Location: ' . APP_URL . '/modules/accounts/index.php'); exit;
}

// Group by type
$accounts = $db->query("SELECT a.*, ag.name as group_name FROM accounts a LEFT JOIN account_groups ag ON a.group_id=ag.id ORDER BY a.account_type, a.account_code")->fetchAll();
$groups = $db->query("SELECT * FROM account_groups ORDER BY type")->fetchAll();
$grouped = [];
foreach ($accounts as $acc) { $grouped[$acc['account_type']][] = $acc; }

$editAccount = null;
if (get('edit')) { $s=$db->prepare("SELECT * FROM accounts WHERE id=?"); $s->execute([(int)get('edit')]); $editAccount=$s->fetch(); }

include __DIR__ . '/../../includes/header.php';
?>

<div class="flex justify-end mb-6">
  <button onclick="openModal('accountModal')" class="btn-primary"><i data-lucide="plus" class="w-4 h-4"></i> New Account</button>
</div>

<?php
$typeColors = ['asset'=>'blue','liability'=>'red','equity'=>'purple','income'=>'green','expense'=>'orange'];
$typeLabels = ['asset'=>'Assets','liability'=>'Liabilities','equity'=>'Equity','income'=>'Income','expense'=>'Expenses'];
foreach ($grouped as $type => $accs):
  $col = $typeColors[$type] ?? 'gray';
  $totalBal = array_sum(array_column($accs,'balance'));
?>
<div class="card overflow-hidden mb-4">
  <div class="px-4 py-3 flex items-center justify-between border-b bg-<?= $col ?>-50">
    <h3 class="text-sm font-semibold text-<?= $col ?>-700"><?= $typeLabels[$type] ?? ucfirst($type) ?></h3>
    <span class="text-sm font-bold text-<?= $col ?>-700"><?= formatCurrency($totalBal) ?></span>
  </div>
  <table class="w-full">
    <thead class="bg-gray-50 border-b"><tr class="text-xs text-gray-400 font-medium">
      <th class="px-4 py-2 text-left">Code</th>
      <th class="px-4 py-2 text-left">Account Name</th>
      <th class="px-4 py-2 text-left">Sub Type</th>
      <th class="px-4 py-2 text-right">Balance</th>
      <th class="px-4 py-2 text-center">Status</th>
      <th class="px-4 py-2 text-center">Actions</th>
    </tr></thead>
    <tbody class="divide-y divide-gray-50">
      <?php foreach ($accs as $acc): ?>
      <tr class="table-row">
        <td class="px-4 py-2.5 text-xs font-mono text-gray-500"><?= clean($acc['account_code']) ?></td>
        <td class="px-4 py-2.5 text-xs font-medium text-gray-800"><?= clean($acc['account_name']) ?> <?= $acc['is_system']?'<span class="text-xs text-gray-400">(system)</span>':'' ?></td>
        <td class="px-4 py-2.5 text-xs text-gray-500"><?= clean(str_replace('_',' ',$acc['sub_type']??'')) ?></td>
        <td class="px-4 py-2.5 text-xs text-right font-medium"><?= formatCurrency($acc['balance']) ?></td>
        <td class="px-4 py-2.5 text-center"><?= statusBadge($acc['status']) ?></td>
        <td class="px-4 py-2.5 text-center">
          <?php if (!$acc['is_system']): ?>
          <div class="flex items-center justify-center gap-1">
            <a href="?edit=<?= $acc['id'] ?>" class="p-1 rounded hover:bg-yellow-50 text-yellow-500"><i data-lucide="pencil" class="w-3.5 h-3.5"></i></a>
            <button onclick="confirmDelete('?action=delete&id=<?= $acc['id'] ?>','Deactivate account?')" class="p-1 rounded hover:bg-red-50 text-red-400"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button>
          </div>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endforeach; ?>

<!-- Account Modal -->
<div id="accountModal" class="modal-overlay <?= $editAccount?'':'hidden' ?>">
<div class="modal-box max-w-lg">
  <div class="flex items-center justify-between px-6 py-4 border-b">
    <h2 class="text-base font-semibold"><?= $editAccount?'Edit Account':'New Account' ?></h2>
    <button onclick="closeModal('accountModal')" class="text-gray-400"><i data-lucide="x" class="w-5 h-5"></i></button>
  </div>
  <form method="POST" class="p-6 space-y-4">
    <?php if ($editAccount): ?><input type="hidden" name="edit_id" value="<?= $editAccount['id'] ?>"><?php endif; ?>
    <div class="grid grid-cols-2 gap-4">
      <div><label class="form-label">Account Code *</label><input type="text" name="account_code" required class="form-input" value="<?= clean($editAccount['account_code']??'') ?>" placeholder="1000"></div>
      <div><label class="form-label">Account Type *</label>
        <select name="account_type" required class="form-input" id="accType">
          <?php foreach (['asset','liability','equity','income','expense'] as $t): ?>
          <option value="<?=$t?>" <?= ($editAccount['account_type']??'')===$t?'selected':'' ?>><?= ucfirst($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-span-2"><label class="form-label">Account Name *</label><input type="text" name="account_name" required class="form-input" value="<?= clean($editAccount['account_name']??'') ?>" placeholder="e.g. Cash in Hand"></div>
      <div><label class="form-label">Group</label>
        <select name="group_id" class="form-input">
          <option value="">None</option>
          <?php foreach ($groups as $g): ?>
          <option value="<?=$g['id']?>" <?= ($editAccount['group_id']??0)==$g['id']?'selected':'' ?>><?= clean($g['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label class="form-label">Sub Type</label><input type="text" name="sub_type" class="form-input" value="<?= clean($editAccount['sub_type']??'') ?>" placeholder="e.g. bank, payable"></div>
      <div class="col-span-2"><label class="form-label">Description</label><textarea name="description" rows="2" class="form-input"><?= clean($editAccount['description']??'') ?></textarea></div>
    </div>
    <div class="flex justify-end gap-3 pt-2">
      <button type="button" onclick="closeModal('accountModal')" class="btn-secondary">Cancel</button>
      <button type="submit" class="btn-primary"><i data-lucide="save" class="w-4 h-4"></i> Save Account</button>
    </div>
  </form>
</div>
</div>
<?php if ($editAccount): ?><script>window.addEventListener('load',()=>openModal('accountModal'));</script><?php endif; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

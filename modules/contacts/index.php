<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
if (!hasPermission('contacts')) { $_SESSION['flash_error']='Access denied'; header('Location: '.APP_URL.'/modules/dashboard/index.php'); exit; }

$db = getDB();
$currentModule = 'contacts';
$pageTitle = 'Contacts';
$action = get('action');

if ($action === 'delete' && get('id')) {
    $db->prepare("UPDATE contacts SET status='inactive' WHERE id=?")->execute([(int)get('id')]);
    $_SESSION['flash_success'] = 'Contact deactivated.';
    header('Location: ' . APP_URL . '/modules/contacts/index.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $editId = (int)post('edit_id');
    $data = [
        'type'           => post('type','customer'),
        'company_name'   => post('company_name'),
        'first_name'     => post('first_name'),
        'last_name'      => post('last_name'),
        'email'          => post('email'),
        'phone'          => post('phone'),
        'mobile'         => post('mobile'),
        'website'        => post('website'),
        'tax_id'         => post('tax_id'),
        'currency'       => post('currency','NGN'),
        'payment_terms'  => (int)post('payment_terms',30),
        'billing_address'=> post('billing_address'),
        'city'           => post('city'),
        'state'          => post('state'),
        'country'        => post('country','Nigeria'),
        'notes'          => post('notes'),
        'status'         => 'active',
        'created_by'     => $_SESSION['user_id'],
    ];
    if ($editId) {
        $set = implode(', ', array_map(fn($k)=>"$k=?", array_keys($data)));
        $db->prepare("UPDATE contacts SET $set WHERE id=?")->execute([...array_values($data), $editId]);
        $_SESSION['flash_success'] = 'Contact updated.';
    } else {
        $cols = implode(',', array_keys($data));
        $ph   = implode(',', array_fill(0, count($data), '?'));
        $db->prepare("INSERT INTO contacts ($cols) VALUES ($ph)")->execute(array_values($data));
        $_SESSION['flash_success'] = 'Contact created.';
    }
    header('Location: ' . APP_URL . '/modules/contacts/index.php'); exit;
}

$search = get('search'); $type = get('type'); $page = max(1,(int)get('page',1));
$where=['1=1']; $params=[];
if ($search) { $where[]="(company_name LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)"; $s="%$search%"; $params=array_merge($params,[$s,$s,$s,$s]); }
if ($type) { $where[]="type=?"; $params[]=$type; }
$whereSQL = implode(' AND ',$where);
$total = $db->prepare("SELECT COUNT(*) FROM contacts WHERE $whereSQL AND status='active'");
$total->execute($params); $pg = paginate((int)$total->fetchColumn(),$page);
$stmt = $db->prepare("SELECT * FROM contacts WHERE $whereSQL AND status='active' ORDER BY company_name, first_name LIMIT {$pg['perPage']} OFFSET {$pg['offset']}");
$stmt->execute($params); $contacts = $stmt->fetchAll();

$editContact = null;
if (get('edit')) { $s2=$db->prepare("SELECT * FROM contacts WHERE id=?"); $s2->execute([(int)get('edit')]); $editContact=$s2->fetch(); }

include __DIR__ . '/../../includes/header.php';
?>

<div class="flex items-center justify-between mb-6">
  <div class="flex gap-2">
    <div class="relative">
      <input type="text" placeholder="Search contacts..." value="<?= clean($search) ?>" class="form-input pl-9 py-2 text-sm w-64" onchange="location='?search='+encodeURIComponent(this.value)+'&type=<?= clean($type) ?>'">
      <i data-lucide="search" class="w-4 h-4 text-gray-400 absolute left-2.5 top-2.5"></i>
    </div>
    <select onchange="location='?type='+this.value+'&search=<?= urlencode($search) ?>'" class="form-input py-2 text-sm">
      <option value="">All Types</option>
      <option value="customer" <?= $type==='customer'?'selected':'' ?>>Customers</option>
      <option value="vendor" <?= $type==='vendor'?'selected':'' ?>>Vendors</option>
      <option value="both" <?= $type==='both'?'selected':'' ?>>Both</option>
    </select>
  </div>
  <button onclick="openModal('contactModal')" class="btn-primary">
    <i data-lucide="user-plus" class="w-4 h-4"></i> New Contact
  </button>
</div>

<div class="card overflow-hidden">
  <table class="w-full">
    <thead class="bg-gray-50 border-b border-gray-100">
      <tr class="text-xs text-gray-500 font-medium">
        <th class="px-4 py-3 text-left">Name / Company</th>
        <th class="px-4 py-3 text-left">Type</th>
        <th class="px-4 py-3 text-left">Email</th>
        <th class="px-4 py-3 text-left">Phone</th>
        <th class="px-4 py-3 text-left">City</th>
        <th class="px-4 py-3 text-right">Balance</th>
        <th class="px-4 py-3 text-center">Actions</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-50">
      <?php foreach ($contacts as $c):
        $displayName = $c['company_name'] ?: ($c['first_name'].' '.$c['last_name']);
      ?>
      <tr class="table-row">
        <td class="px-4 py-3">
          <div class="flex items-center gap-2">
            <div class="w-7 h-7 rounded-full bg-blue-100 flex items-center justify-center text-xs font-bold text-blue-600">
              <?= strtoupper(substr(trim($displayName),0,2)) ?>
            </div>
            <div>
              <p class="text-xs font-medium text-gray-800"><?= clean($displayName) ?></p>
              <?php if ($c['company_name'] && ($c['first_name']||$c['last_name'])): ?>
              <p class="text-xs text-gray-400"><?= clean($c['first_name'].' '.$c['last_name']) ?></p>
              <?php endif; ?>
            </div>
          </div>
        </td>
        <td class="px-4 py-3"><span class="text-xs capitalize px-2 py-0.5 rounded-full <?= $c['type']==='customer'?'bg-blue-100 text-blue-700':($c['type']==='vendor'?'bg-purple-100 text-purple-700':'bg-green-100 text-green-700') ?>"><?= $c['type'] ?></span></td>
        <td class="px-4 py-3 text-xs text-gray-600"><?= clean($c['email']) ?></td>
        <td class="px-4 py-3 text-xs text-gray-600"><?= clean($c['phone']) ?></td>
        <td class="px-4 py-3 text-xs text-gray-500"><?= clean($c['city']) ?></td>
        <td class="px-4 py-3 text-xs text-right <?= $c['balance']<0?'text-red-600':'' ?>"><?= formatCurrency($c['balance']) ?></td>
        <td class="px-4 py-3 text-center">
          <div class="flex items-center justify-center gap-1">
            <a href="?edit=<?= $c['id'] ?>" class="p-1 rounded hover:bg-yellow-50 text-yellow-500"><i data-lucide="pencil" class="w-3.5 h-3.5"></i></a>
            <button onclick="confirmDelete('?action=delete&id=<?= $c['id'] ?>','Deactivate this contact?')" class="p-1 rounded hover:bg-red-50 text-red-400"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$contacts): ?>
      <tr><td colspan="7" class="px-4 py-12 text-center text-sm text-gray-400">
        <i data-lucide="users" class="w-10 h-10 mx-auto mb-2 opacity-30"></i><p>No contacts yet.</p>
      </td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Contact Modal -->
<div id="contactModal" class="modal-overlay <?= $editContact?'':'hidden' ?>">
<div class="modal-box max-w-2xl">
  <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
    <h2 class="text-base font-semibold"><?= $editContact?'Edit Contact':'New Contact' ?></h2>
    <button onclick="closeModal('contactModal')" class="text-gray-400 hover:text-gray-600"><i data-lucide="x" class="w-5 h-5"></i></button>
  </div>
  <form method="POST" class="p-6">
    <?php if ($editContact): ?><input type="hidden" name="edit_id" value="<?= $editContact['id'] ?>"><?php endif; ?>
    <div class="grid grid-cols-2 gap-4">
      <div class="col-span-2"><label class="form-label">Contact Type</label>
        <select name="type" class="form-input">
          <?php foreach (['customer','vendor','both'] as $t): ?>
          <option value="<?=$t?>" <?= ($editContact['type']??'customer')===$t?'selected':'' ?>><?= ucfirst($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-span-2"><label class="form-label">Company Name</label><input type="text" name="company_name" class="form-input" value="<?= clean($editContact['company_name']??'') ?>" placeholder="ABC Ltd."></div>
      <div><label class="form-label">First Name</label><input type="text" name="first_name" class="form-input" value="<?= clean($editContact['first_name']??'') ?>"></div>
      <div><label class="form-label">Last Name</label><input type="text" name="last_name" class="form-input" value="<?= clean($editContact['last_name']??'') ?>"></div>
      <div><label class="form-label">Email</label><input type="email" name="email" class="form-input" value="<?= clean($editContact['email']??'') ?>"></div>
      <div><label class="form-label">Phone</label><input type="text" name="phone" class="form-input" value="<?= clean($editContact['phone']??'') ?>"></div>
      <div><label class="form-label">Mobile</label><input type="text" name="mobile" class="form-input" value="<?= clean($editContact['mobile']??'') ?>"></div>
      <div><label class="form-label">Tax ID / RC Number</label><input type="text" name="tax_id" class="form-input" value="<?= clean($editContact['tax_id']??'') ?>"></div>
      <div><label class="form-label">City</label><input type="text" name="city" class="form-input" value="<?= clean($editContact['city']??'') ?>"></div>
      <div><label class="form-label">State</label><input type="text" name="state" class="form-input" value="<?= clean($editContact['state']??'') ?>"></div>
      <div><label class="form-label">Country</label><input type="text" name="country" class="form-input" value="<?= clean($editContact['country']??'Nigeria') ?>"></div>
      <div><label class="form-label">Payment Terms (days)</label><input type="number" name="payment_terms" class="form-input" value="<?= $editContact['payment_terms']??30 ?>" min="0"></div>
      <div class="col-span-2"><label class="form-label">Billing Address</label><textarea name="billing_address" rows="2" class="form-input"><?= clean($editContact['billing_address']??'') ?></textarea></div>
      <div class="col-span-2"><label class="form-label">Notes</label><textarea name="notes" rows="2" class="form-input"><?= clean($editContact['notes']??'') ?></textarea></div>
    </div>
    <div class="flex justify-end gap-3 mt-5 pt-5 border-t border-gray-100">
      <button type="button" onclick="closeModal('contactModal')" class="btn-secondary">Cancel</button>
      <button type="submit" class="btn-primary"><i data-lucide="save" class="w-4 h-4"></i> Save Contact</button>
    </div>
  </form>
</div>
</div>
<?php if ($editContact): ?><script>window.addEventListener('load',()=>openModal('contactModal'));</script><?php endif; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
// Only admin
$user = currentUser();
$perms = is_string($user['permissions']) ? json_decode($user['permissions'],true) : $user['permissions'];
if (empty($perms['all'])) { $_SESSION['flash_error']='Admin access required.'; header('Location: '.APP_URL.'/modules/dashboard/index.php'); exit; }

$db = getDB();
$currentModule = 'access';
$pageTitle = 'Access Control';

// Delete user
if (get('action') === 'delete_user' && get('id')) {
    $uid = (int)get('id');
    if ($uid === (int)$_SESSION['user_id']) { $_SESSION['flash_error']='Cannot delete yourself.'; }
    else { $db->prepare("UPDATE users SET status='suspended' WHERE id=?")->execute([$uid]); $_SESSION['flash_success']='User suspended.'; }
    header('Location: ' . APP_URL . '/modules/access/index.php'); exit;
}

// Create/Update User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'user') {
    $editId = (int)post('edit_id');
    $data = [
        'role_id'     => (int)post('role_id'),
        'name'        => post('name'),
        'email'       => post('email'),
        'phone'       => post('phone'),
        'department'  => post('department'),
        'status'      => post('status','active'),
    ];
    if (!$editId || post('password')) {
        $pass = post('password');
        if (!$editId && !$pass) { $_SESSION['flash_error']='Password required for new user.'; header('Location: '.APP_URL.'/modules/access/index.php'); exit; }
        if ($pass) $data['password'] = password_hash($pass, PASSWORD_BCRYPT);
    }
    if ($editId) {
        $set = implode(', ', array_map(fn($k)=>"$k=?", array_keys($data)));
        $db->prepare("UPDATE users SET $set WHERE id=?")->execute([...array_values($data), $editId]);
        $_SESSION['flash_success'] = 'User updated.';
    } else {
        if (!isset($data['password'])) { $_SESSION['flash_error']='Password is required.'; header('Location: '.APP_URL.'/modules/access/index.php'); exit; }
        $cols = implode(',', array_keys($data)); $ph = implode(',', array_fill(0,count($data),'?'));
        $db->prepare("INSERT INTO users ($cols) VALUES ($ph)")->execute(array_values($data));
        $_SESSION['flash_success'] = 'User created.';
        auditLog('create_user','access', $db->lastInsertId());
    }
    header('Location: ' . APP_URL . '/modules/access/index.php'); exit;
}

// Update Role Permissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'role') {
    $roleId = (int)post('role_id');
    $modules = post('modules',[]);
    if (!is_array($modules)) $modules=[];
    $perm = in_array('all',$modules) ? ['all'=>true] : array_fill_keys($modules,true);
    $db->prepare("UPDATE roles SET permissions=? WHERE id=?")->execute([json_encode($perm), $roleId]);
    $_SESSION['flash_success'] = 'Role permissions updated.';
    header('Location: ' . APP_URL . '/modules/access/index.php?tab=roles'); exit;
}

$tab = get('tab','users');
$users = $db->query("SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id=r.id ORDER BY u.created_at DESC")->fetchAll();
$roles = $db->query("SELECT * FROM roles ORDER BY id")->fetchAll();
$editUser = null;
if (get('edit')) { $s=$db->prepare("SELECT * FROM users WHERE id=?"); $s->execute([(int)get('edit')]); $editUser=$s->fetch(); }

$allModules = ['dashboard','invoices','expenses','contacts','inventory','accounts','bank','reports','staff'];

include __DIR__ . '/../../includes/header.php';
?>

<!-- Tabs -->
<div class="flex gap-1 mb-6 border-b border-gray-200">
  <a href="?tab=users" class="px-5 py-3 text-sm font-medium border-b-2 transition-colors <?= $tab==='users'?'border-blue-600 text-blue-600':'border-transparent text-gray-500 hover:text-gray-700' ?>">
    <i data-lucide="users" class="w-4 h-4 inline mr-1.5"></i>Users
  </a>
  <a href="?tab=roles" class="px-5 py-3 text-sm font-medium border-b-2 transition-colors <?= $tab==='roles'?'border-blue-600 text-blue-600':'border-transparent text-gray-500 hover:text-gray-700' ?>">
    <i data-lucide="shield" class="w-4 h-4 inline mr-1.5"></i>Roles & Permissions
  </a>
  <a href="?tab=audit" class="px-5 py-3 text-sm font-medium border-b-2 transition-colors <?= $tab==='audit'?'border-blue-600 text-blue-600':'border-transparent text-gray-500 hover:text-gray-700' ?>">
    <i data-lucide="clock" class="w-4 h-4 inline mr-1.5"></i>Audit Log
  </a>
</div>

<?php if ($tab === 'users'): ?>
<!-- Users Tab -->
<div class="flex justify-end mb-4">
  <button onclick="openModal('userModal')" class="btn-primary"><i data-lucide="user-plus" class="w-4 h-4"></i> Add User</button>
</div>

<div class="card overflow-hidden">
  <table class="w-full">
    <thead class="bg-gray-50 border-b border-gray-100">
      <tr class="text-xs text-gray-500 font-medium">
        <th class="px-4 py-3 text-left">User</th>
        <th class="px-4 py-3 text-left">Email</th>
        <th class="px-4 py-3 text-left">Role</th>
        <th class="px-4 py-3 text-left">Department</th>
        <th class="px-4 py-3 text-left">Last Login</th>
        <th class="px-4 py-3 text-center">Status</th>
        <th class="px-4 py-3 text-center">Actions</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-50">
      <?php foreach ($users as $u): ?>
      <tr class="table-row">
        <td class="px-4 py-3">
          <div class="flex items-center gap-2">
            <div class="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center text-xs font-bold text-blue-700">
              <?= strtoupper(substr($u['name'],0,2)) ?>
            </div>
            <span class="text-sm font-medium text-gray-800"><?= clean($u['name']) ?></span>
          </div>
        </td>
        <td class="px-4 py-3 text-xs text-gray-600"><?= clean($u['email']) ?></td>
        <td class="px-4 py-3"><span class="text-xs px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700"><?= clean($u['role_name']) ?></span></td>
        <td class="px-4 py-3 text-xs text-gray-500"><?= clean($u['department']??'-') ?></td>
        <td class="px-4 py-3 text-xs text-gray-400"><?= $u['last_login'] ? formatDate($u['last_login']) : 'Never' ?></td>
        <td class="px-4 py-3 text-center"><?= statusBadge($u['status']) ?></td>
        <td class="px-4 py-3 text-center">
          <div class="flex items-center justify-center gap-1">
            <a href="?edit=<?= $u['id'] ?>" class="p-1 rounded hover:bg-yellow-50 text-yellow-500"><i data-lucide="pencil" class="w-3.5 h-3.5"></i></a>
            <?php if ($u['id'] != $_SESSION['user_id']): ?>
            <button onclick="confirmDelete('?action=delete_user&id=<?= $u['id'] ?>','Suspend this user?')" class="p-1 rounded hover:bg-red-50 text-red-400"><i data-lucide="user-x" class="w-3.5 h-3.5"></i></button>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php elseif ($tab === 'roles'): ?>
<!-- Roles Tab -->
<div class="grid grid-cols-1 gap-4">
  <?php foreach ($roles as $role):
    $rolePerm = is_string($role['permissions']) ? json_decode($role['permissions'],true) : [];
    $rolePerm = $rolePerm ?: [];
  ?>
  <div class="card p-5">
    <form method="POST">
      <input type="hidden" name="form" value="role">
      <input type="hidden" name="role_id" value="<?= $role['id'] ?>">
      <div class="flex items-start justify-between mb-4">
        <div>
          <h3 class="font-semibold text-gray-800 capitalize"><?= clean($role['name']) ?></h3>
          <p class="text-xs text-gray-400"><?= clean($role['description']) ?></p>
        </div>
        <button type="submit" class="btn-primary text-xs"><i data-lucide="save" class="w-3.5 h-3.5"></i> Save</button>
      </div>
      <div class="flex flex-wrap gap-2">
        <label class="flex items-center gap-2 text-sm cursor-pointer">
          <input type="checkbox" name="modules[]" value="all" class="rounded" <?= !empty($rolePerm['all'])?'checked':'' ?> onchange="toggleAll(this)">
          <span class="font-medium text-purple-700">All Access</span>
        </label>
        <?php foreach ($allModules as $mod): ?>
        <label class="flex items-center gap-2 text-sm cursor-pointer px-3 py-1.5 border border-gray-200 rounded-lg hover:bg-gray-50 module-check">
          <input type="checkbox" name="modules[]" value="<?=$mod?>" class="rounded" <?= (!empty($rolePerm['all'])||!empty($rolePerm[$mod]))?'checked':'' ?>>
          <?= ucwords(str_replace('_',' ',$mod)) ?>
        </label>
        <?php endforeach; ?>
      </div>
    </form>
  </div>
  <?php endforeach; ?>
</div>

<?php elseif ($tab === 'audit'): ?>
<!-- Audit Log -->
<?php $auditLog = $db->query("SELECT al.*, u.name as user_name FROM audit_log al LEFT JOIN users u ON al.user_id=u.id ORDER BY al.created_at DESC LIMIT 100")->fetchAll(); ?>
<div class="card overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-gray-50 border-b"><tr class="text-xs text-gray-500 font-medium">
      <th class="px-4 py-3 text-left">Time</th>
      <th class="px-4 py-3 text-left">User</th>
      <th class="px-4 py-3 text-left">Action</th>
      <th class="px-4 py-3 text-left">Module</th>
      <th class="px-4 py-3 text-left">IP</th>
    </tr></thead>
    <tbody class="divide-y divide-gray-50">
      <?php foreach ($auditLog as $log): ?>
      <tr class="table-row">
        <td class="px-4 py-2.5 text-xs text-gray-400"><?= date('d M Y H:i', strtotime($log['created_at'])) ?></td>
        <td class="px-4 py-2.5 text-xs font-medium"><?= clean($log['user_name']??'System') ?></td>
        <td class="px-4 py-2.5 text-xs"><span class="px-2 py-0.5 rounded bg-blue-50 text-blue-700"><?= clean($log['action']) ?></span></td>
        <td class="px-4 py-2.5 text-xs text-gray-500"><?= clean($log['module']) ?></td>
        <td class="px-4 py-2.5 text-xs text-gray-400"><?= clean($log['ip_address']??'') ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$auditLog): ?><tr><td colspan="5" class="px-4 py-8 text-center text-gray-400 text-xs">No audit logs yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- User Modal -->
<div id="userModal" class="modal-overlay <?= $editUser?'':'hidden' ?>">
<div class="modal-box max-w-lg">
  <div class="flex items-center justify-between px-6 py-4 border-b">
    <h2 class="text-base font-semibold"><?= $editUser?'Edit User':'Add User' ?></h2>
    <button onclick="closeModal('userModal')" class="text-gray-400"><i data-lucide="x" class="w-5 h-5"></i></button>
  </div>
  <form method="POST" class="p-6">
    <input type="hidden" name="form" value="user">
    <?php if ($editUser): ?><input type="hidden" name="edit_id" value="<?= $editUser['id'] ?>"><?php endif; ?>
    <div class="grid grid-cols-2 gap-4">
      <div class="col-span-2"><label class="form-label">Full Name *</label><input type="text" name="name" required class="form-input" value="<?= clean($editUser['name']??'') ?>"></div>
      <div class="col-span-2"><label class="form-label">Email *</label><input type="email" name="email" required class="form-input" value="<?= clean($editUser['email']??'') ?>"></div>
      <div><label class="form-label">Phone</label><input type="text" name="phone" class="form-input" value="<?= clean($editUser['phone']??'') ?>"></div>
      <div><label class="form-label">Department</label><input type="text" name="department" class="form-input" value="<?= clean($editUser['department']??'') ?>"></div>
      <div><label class="form-label">Role *</label>
        <select name="role_id" required class="form-input">
          <?php foreach ($roles as $r): ?>
          <option value="<?=$r['id']?>" <?= ($editUser['role_id']??1)==$r['id']?'selected':'' ?>><?= clean($r['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label class="form-label">Status</label>
        <select name="status" class="form-input">
          <?php foreach (['active'=>'Active','inactive'=>'Inactive','suspended'=>'Suspended'] as $k=>$v): ?>
          <option value="<?=$k?>" <?= ($editUser['status']??'active')===$k?'selected':'' ?>><?=$v?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-span-2">
        <label class="form-label"><?= $editUser?'New Password (leave blank to keep)':'Password *' ?></label>
        <input type="password" name="password" class="form-input" <?= !$editUser?'required':'' ?> placeholder="Min. 8 characters">
      </div>
    </div>
    <div class="flex justify-end gap-3 mt-5 pt-4 border-t">
      <button type="button" onclick="closeModal('userModal')" class="btn-secondary">Cancel</button>
      <button type="submit" class="btn-primary"><i data-lucide="save" class="w-4 h-4"></i> Save User</button>
    </div>
  </form>
</div>
</div>

<script>
function toggleAll(cb) {
  document.querySelectorAll('.module-check input').forEach(i => i.checked = cb.checked);
}
<?php if ($editUser): ?>window.addEventListener('load',()=>openModal('userModal'));<?php endif; ?>
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

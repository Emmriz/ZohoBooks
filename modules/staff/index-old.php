<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
if (!hasPermission('staff')) {
    $_SESSION['flash_error'] = 'Access denied';
    header('Location: ' . APP_URL . '/modules/dashboard/index.php');
    exit;
}

$db            = getDB();
$currentModule = 'staff';
$pageTitle     = 'Staff / HR';

// Auto-expire leaves: restore to active if leave end date has passed
try {
    $db->query("
        UPDATE staff s
        INNER JOIN staff_leaves sl ON sl.staff_id = s.id
        SET s.status = 'active', sl.status = 'expired'
        WHERE s.status = 'on_leave'
          AND sl.status = 'active'
          AND sl.leave_end < CURDATE()
    ");
} catch (Exception $e) {}

// DELETE STAFF
if (get('action') === 'delete_staff' && get('id')) {
    $db->prepare("UPDATE staff SET status='terminated' WHERE id=?")->execute([(int)get('id')]);
    $_SESSION['flash_success'] = 'Staff record terminated.';
    header('Location: ' . APP_URL . '/modules/staff/index.php'); exit;
}

// DELETE DEPARTMENT
if (get('action') === 'delete_dept' && get('id')) {
    $deptId = (int)get('id');
    $used   = $db->prepare("SELECT COUNT(*) FROM staff WHERE department_id=? AND status != 'terminated'");
    $used->execute([$deptId]);
    if ($used->fetchColumn() > 0) {
        $_SESSION['flash_error'] = 'Cannot delete — department has active staff. Reassign them first.';
    } else {
        $db->prepare("DELETE FROM departments WHERE id=?")->execute([$deptId]);
        $_SESSION['flash_success'] = 'Department deleted.';
    }
    header('Location: ' . APP_URL . '/modules/staff/index.php?tab=departments'); exit;
}

// SAVE DEPARTMENT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'department') {
    $editDeptId  = (int)post('edit_dept_id');
    $name        = trim(post('dept_name'));
    $description = trim(post('dept_description'));
    $manager     = trim(post('dept_manager'));
    if (empty($name)) {
        $_SESSION['flash_error'] = 'Department name is required.';
        header('Location: ' . APP_URL . '/modules/staff/index.php?tab=departments'); exit;
    }
    if ($editDeptId) {
        $db->prepare("UPDATE departments SET name=?, description=?, manager_name=? WHERE id=?")
           ->execute([$name, $description, $manager, $editDeptId]);
        $_SESSION['flash_success'] = 'Department updated.';
    } else {
        $db->prepare("INSERT INTO departments (name, description, manager_name) VALUES (?,?,?)")
           ->execute([$name, $description, $manager]);
        $_SESSION['flash_success'] = 'Department created.';
    }
    header('Location: ' . APP_URL . '/modules/staff/index.php?tab=departments'); exit;
}

// SAVE STAFF
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'staff') {
    $editId     = (int)post('edit_id');
    $newStatus  = post('status', 'active');
    $leaveStart = post('leave_start');
    $leaveEnd   = post('leave_end');
    $leaveReason = post('leave_reason');

    $data = [
        'department_id'           => (int)post('department_id') ?: null,
        'first_name'              => post('first_name'),
        'last_name'               => post('last_name'),
        'email'                   => post('email'),
        'phone'                   => post('phone'),
        'gender'                  => post('gender'),
        'date_of_birth'           => post('date_of_birth') ?: null,
        'hire_date'               => post('hire_date') ?: null,
        'job_title'               => post('job_title'),
        'employment_type'         => post('employment_type', 'full_time'),
        'salary'                  => (float)post('salary'),
        'salary_type'             => post('salary_type', 'monthly'),
        'bank_name'               => post('bank_name'),
        'account_number'          => post('account_number'),
        'address'                 => post('address'),
        'city'                    => post('city'),
        'state'                   => post('state'),
        'country'                 => post('country', 'Nigeria'),
        'emergency_contact_name'  => post('emergency_contact_name'),
        'emergency_contact_phone' => post('emergency_contact_phone'),
        'status'                  => $newStatus,
        'notes'                   => post('notes'),
        'created_by'              => $_SESSION['user_id'],
    ];

    if ($editId) {
        $set = implode(', ', array_map(fn($k) => "$k=?", array_keys($data)));
        $db->prepare("UPDATE staff SET $set WHERE id=?")->execute([...array_values($data), $editId]);
        if ($newStatus === 'on_leave' && $leaveStart && $leaveEnd) {
            $db->prepare("UPDATE staff_leaves SET status='cancelled' WHERE staff_id=? AND status='active'")->execute([$editId]);
            $db->prepare("INSERT INTO staff_leaves (staff_id,leave_start,leave_end,leave_reason,status,created_by) VALUES (?,?,?,?,'active',?)")
               ->execute([$editId, $leaveStart, $leaveEnd, $leaveReason, $_SESSION['user_id']]);
        }
        $_SESSION['flash_success'] = 'Staff record updated.';
    } else {
        $data['employee_id'] = 'EMP-' . str_pad((int)$db->query("SELECT COUNT(*)+1 FROM staff")->fetchColumn(), 4, '0', STR_PAD_LEFT);
        $cols = implode(',', array_keys($data));
        $ph   = implode(',', array_fill(0, count($data), '?'));
        $db->prepare("INSERT INTO staff ($cols) VALUES ($ph)")->execute(array_values($data));
        $newId = $db->lastInsertId();
        if ($newStatus === 'on_leave' && $leaveStart && $leaveEnd) {
            $db->prepare("INSERT INTO staff_leaves (staff_id,leave_start,leave_end,leave_reason,status,created_by) VALUES (?,?,?,?,'active',?)")
               ->execute([$newId, $leaveStart, $leaveEnd, $leaveReason, $_SESSION['user_id']]);
        }
        $_SESSION['flash_success'] = 'Staff member added.';
    }
    header('Location: ' . APP_URL . '/modules/staff/index.php'); exit;
}

// FETCH
$tab          = get('tab', 'staff');
$search       = get('search');
$filterDept   = get('dept');
$filterStatus = get('status', 'active');
$page         = max(1, (int)get('page', 1));

$where = ['1=1']; $params = [];
if ($search) {
    $where[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.email LIKE ? OR s.employee_id LIKE ? OR s.job_title LIKE ?)";
    $sv = "%$search%"; $params = array_merge($params, [$sv,$sv,$sv,$sv,$sv]);
}
if ($filterDept)   { $where[] = "s.department_id=?"; $params[] = $filterDept; }
if ($filterStatus) { $where[] = "s.status=?";        $params[] = $filterStatus; }
$whereSQL = implode(' AND ', $where);

$cnt = $db->prepare("SELECT COUNT(*) FROM staff s WHERE $whereSQL");
$cnt->execute($params);
$pg   = paginate((int)$cnt->fetchColumn(), $page);
$stmt = $db->prepare("
    SELECT s.*, d.name as dept_name,
           sl.leave_start, sl.leave_end, sl.leave_reason
    FROM staff s
    LEFT JOIN departments d ON s.department_id = d.id
    LEFT JOIN staff_leaves sl ON sl.staff_id = s.id AND sl.status = 'active'
    WHERE $whereSQL
    ORDER BY s.first_name
    LIMIT {$pg['perPage']} OFFSET {$pg['offset']}
");
$stmt->execute($params);
$staffList = $stmt->fetchAll();

$departments = $db->query("
    SELECT d.*, COUNT(s.id) as staff_count
    FROM departments d
    LEFT JOIN staff s ON s.department_id = d.id AND s.status != 'terminated'
    GROUP BY d.id ORDER BY d.name
")->fetchAll();

$editStaff   = null; $activeLeave = null;
if (get('edit')) {
    $s = $db->prepare("SELECT * FROM staff WHERE id=?"); $s->execute([(int)get('edit')]); $editStaff = $s->fetch();
    if ($editStaff) {
        $sl = $db->prepare("SELECT * FROM staff_leaves WHERE staff_id=? AND status='active' ORDER BY id DESC LIMIT 1");
        $sl->execute([$editStaff['id']]); $activeLeave = $sl->fetch();
    }
}
$editDept = null;
if (get('edit_dept')) {
    $sd = $db->prepare("SELECT * FROM departments WHERE id=?"); $sd->execute([(int)get('edit_dept')]); $editDept = $sd->fetch();
}

$summary = $db->query("SELECT COUNT(*) as total, SUM(salary) as total_salary, SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active, SUM(CASE WHEN status='on_leave' THEN 1 ELSE 0 END) as on_leave FROM staff")->fetch();

include __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="flex items-center justify-between gap-3 mb-6 flex-wrap">
  <!-- Left: Tabs -->
  <div class="flex gap-1 border-b border-gray-200 self-end">
    <a href="?tab=staff" class="px-5 py-2.5 text-sm font-medium border-b-2 transition-colors <?= $tab==='staff'?'border-brand text-brand':'border-transparent text-gray-500 hover:text-gray-700' ?>">
      <i data-lucide="users" class="w-4 h-4 inline mr-1"></i> Staff
    </a>
    <a href="?tab=departments" class="px-5 py-2.5 text-sm font-medium border-b-2 transition-colors <?= $tab==='departments'?'border-brand text-brand':'border-transparent text-gray-500 hover:text-gray-700' ?>">
      <i data-lucide="building" class="w-4 h-4 inline mr-1"></i> Departments
      <span class="ml-1 px-1.5 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600"><?= count($departments) ?></span>
    </a>
  </div>

  <!-- Right: Filters + Action Button (staff tab only) -->
  <div class="flex items-center gap-2 flex-wrap">
    <?php if ($tab === 'staff'): ?>
    <div class="relative">
      <input type="text" placeholder="Search staff..." value="<?= clean($search) ?>"
             class="form-input pl-9 py-2 text-sm w-48"
             onchange="location='?tab=staff&search='+encodeURIComponent(this.value)+'&dept=<?= urlencode($filterDept) ?>&status=<?= urlencode($filterStatus) ?>'">
      <i data-lucide="search" class="w-4 h-4 text-gray-400 absolute left-2.5 top-2.5"></i>
    </div>
    <select onchange="location='?tab=staff&dept='+this.value+'&search=<?= urlencode($search) ?>&status=<?= urlencode($filterStatus) ?>'"
            class="form-input py-2 text-sm w-40">
      <option value="">All Departments</option>
      <?php foreach($departments as $d): ?>
      <option value="<?= $d['id'] ?>" <?= $filterDept==$d['id']?'selected':'' ?>><?= clean($d['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select onchange="location='?tab=staff&status='+this.value+'&search=<?= urlencode($search) ?>&dept=<?= urlencode($filterDept) ?>'"
            class="form-input py-2 text-sm w-36">
      <?php foreach(['active'=>'Active','on_leave'=>'On Leave','inactive'=>'Inactive','terminated'=>'Terminated'] as $k=>$v): ?>
      <option value="<?= $k ?>" <?= $filterStatus===$k?'selected':'' ?>><?= $v ?></option>
      <?php endforeach; ?>
    </select>
    <button onclick="openModal('staffModal')" class="btn-primary whitespace-nowrap">
      <i data-lucide="user-plus" class="w-4 h-4"></i> Add Staff
    </button>
    <?php else: ?>
    <button onclick="openModal('deptModal')" class="btn-primary">
      <i data-lucide="folder-plus" class="w-4 h-4"></i> New Department
    </button>
    <?php endif; ?>
  </div>
</div>

<!-- Summary Cards -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
  <?php foreach([
    ['Total Staff',    $summary['total'],    'users',        'blue'],
    ['Active',         $summary['active'],   'user-check',   'green'],
    ['On Leave',       $summary['on_leave'], 'calendar-off', 'yellow'],
    ['Monthly Payroll',formatCurrency($summary['total_salary']??0),'banknote','purple'],
  ] as [$label,$val,$icon,$col]): ?>
  <div class="stat-card">
    <div class="flex items-center justify-between mb-2">
      <p class="text-xs text-gray-400"><?= $label ?></p>
      <div class="w-7 h-7 rounded-lg bg-<?= $col ?>-50 flex items-center justify-center">
        <i data-lucide="<?= $icon ?>" class="w-3.5 h-3.5 text-<?= $col ?>-600"></i>
      </div>
    </div>
    <p class="text-xl font-bold text-gray-800"><?= $val ?></p>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'staff'): ?>
<!-- Staff Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
  <?php foreach($staffList as $staff): ?>
  <div class="card p-5">
    <div class="flex items-start gap-3 mb-3">
      <div class="w-12 h-12 rounded-full flex items-center justify-center text-white font-bold text-lg flex-shrink-0" style="background:var(--brand)">
        <?= strtoupper(substr($staff['first_name'],0,1).substr($staff['last_name'],0,1)) ?>
      </div>
      <div class="flex-1 min-w-0">
        <h3 class="font-semibold text-gray-800 truncate"><?= clean($staff['first_name'].' '.$staff['last_name']) ?></h3>
        <p class="text-xs text-gray-500 truncate"><?= clean($staff['job_title']??'') ?></p>
        <p class="text-xs text-brand"><?= clean($staff['employee_id']) ?></p>
      </div>
      <?= statusBadge($staff['status']) ?>
    </div>
    <div class="space-y-1.5 text-xs text-gray-500 mb-3">
      <?php if($staff['dept_name']): ?><div class="flex items-center gap-2"><i data-lucide="building" class="w-3.5 h-3.5"></i><?= clean($staff['dept_name']) ?></div><?php endif; ?>
      <?php if($staff['email']): ?><div class="flex items-center gap-2 truncate"><i data-lucide="mail" class="w-3.5 h-3.5 flex-shrink-0"></i><?= clean($staff['email']) ?></div><?php endif; ?>
      <?php if($staff['phone']): ?><div class="flex items-center gap-2"><i data-lucide="phone" class="w-3.5 h-3.5"></i><?= clean($staff['phone']) ?></div><?php endif; ?>
      <?php if($staff['hire_date']): ?><div class="flex items-center gap-2"><i data-lucide="calendar" class="w-3.5 h-3.5"></i>Hired <?= formatDate($staff['hire_date']) ?></div><?php endif; ?>
      <?php if($staff['status']==='on_leave' && $staff['leave_start'] && $staff['leave_end']): ?>
      <div class="flex items-center gap-2 mt-1 bg-yellow-50 border border-yellow-200 rounded-lg px-2 py-1.5">
        <i data-lucide="calendar-off" class="w-3.5 h-3.5 text-yellow-600 flex-shrink-0"></i>
        <span class="text-yellow-700 font-medium">Leave: <?= formatDate($staff['leave_start']) ?> — <?= formatDate($staff['leave_end']) ?></span>
      </div>
      <?php endif; ?>
    </div>
    <div class="flex items-center justify-between border-t pt-3">
      <span class="text-xs font-semibold text-gray-800"><?= formatCurrency($staff['salary']) ?><span class="text-gray-400 font-normal">/<?= $staff['salary_type'] ?></span></span>
      <div class="flex gap-1">
        <a href="?edit=<?= $staff['id'] ?>&tab=staff" class="p-1.5 rounded hover:bg-yellow-50 text-yellow-500" title="Edit"><i data-lucide="pencil" class="w-3.5 h-3.5"></i></a>
        <button onclick="confirmDelete('?action=delete_staff&id=<?= $staff['id'] ?>','Terminate this staff member?')" class="p-1.5 rounded hover:bg-red-50 text-red-400" title="Terminate"><i data-lucide="user-x" class="w-3.5 h-3.5"></i></button>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if(!$staffList): ?>
  <div class="col-span-3 card p-12 text-center text-gray-400">
    <i data-lucide="users" class="w-12 h-12 mx-auto mb-3 opacity-30"></i>
    <p class="font-medium">No staff members found.</p><p class="text-sm mt-1">Click "Add Staff" to get started.</p>
  </div>
  <?php endif; ?>
</div>

<?php else: ?>
<!-- DEPARTMENTS TAB -->
<?php if(!$departments): ?>
<div class="card p-12 text-center text-gray-400">
  <i data-lucide="building" class="w-12 h-12 mx-auto mb-3 opacity-30"></i>
  <p class="font-medium text-gray-600 mb-1">No departments yet</p>
  <p class="text-sm mb-4">Create your first department to organise your staff.</p>
  <button onclick="openModal('deptModal')" class="btn-primary mx-auto"><i data-lucide="folder-plus" class="w-4 h-4"></i> Create Department</button>
</div>
<?php else: ?>
<div class="card overflow-hidden">
  <table class="w-full">
    <thead class="bg-gray-50 border-b border-gray-100">
      <tr class="text-xs text-gray-500 font-medium">
        <th class="px-4 py-3 text-left">#</th>
        <th class="px-4 py-3 text-left">Department Name</th>
        <th class="px-4 py-3 text-left">Manager</th>
        <th class="px-4 py-3 text-left">Description</th>
        <th class="px-4 py-3 text-center">Staff</th>
        <th class="px-4 py-3 text-center">Actions</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-50">
      <?php foreach($departments as $i=>$dept): ?>
      <tr class="table-row">
        <td class="px-4 py-3 text-xs text-gray-400"><?= $i+1 ?></td>
        <td class="px-4 py-3">
          <div class="flex items-center gap-2">
            <div class="w-8 h-8 rounded-lg bg-indigo-50 flex items-center justify-center"><i data-lucide="building" class="w-4 h-4 text-indigo-500"></i></div>
            <span class="text-sm font-semibold text-gray-800"><?= clean($dept['name']) ?></span>
          </div>
        </td>
        <td class="px-4 py-3 text-xs text-gray-600"><?= clean($dept['manager_name']??'—') ?></td>
        <td class="px-4 py-3 text-xs text-gray-500 max-w-xs truncate"><?= clean($dept['description']??'—') ?></td>
        <td class="px-4 py-3 text-center">
          <a href="?tab=staff&dept=<?= $dept['id'] ?>" class="inline-flex items-center gap-1 text-xs font-semibold text-brand hover:underline">
            <i data-lucide="users" class="w-3.5 h-3.5"></i> <?= $dept['staff_count'] ?> staff
          </a>
        </td>
        <td class="px-4 py-3 text-center">
          <div class="flex items-center justify-center gap-1">
            <a href="?tab=staff&dept=<?= $dept['id'] ?>" class="p-1.5 rounded hover:bg-blue-50 text-blue-500" title="View Staff"><i data-lucide="eye" class="w-3.5 h-3.5"></i></a>
            <a href="?tab=departments&edit_dept=<?= $dept['id'] ?>" class="p-1.5 rounded hover:bg-yellow-50 text-yellow-500" title="Edit"><i data-lucide="pencil" class="w-3.5 h-3.5"></i></a>
            <button onclick="confirmDelete('?action=delete_dept&id=<?= $dept['id'] ?>','Delete this department? All staff must be reassigned first.')" class="p-1.5 rounded hover:bg-red-50 text-red-400" title="Delete"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php endif; ?>


<!-- DEPARTMENT MODAL -->
<div id="deptModal" class="modal-overlay <?= $editDept?'':'hidden' ?>"><div class="modal-box max-w-md">
  <div class="flex items-center justify-between px-6 py-4 border-b">
    <h2 class="text-base font-semibold"><?= $editDept?'Edit Department':'New Department' ?></h2>
    <button onclick="closeModal('deptModal')" class="text-gray-400 hover:text-gray-600"><i data-lucide="x" class="w-5 h-5"></i></button>
  </div>
  <form method="POST" class="p-6 space-y-4">
    <input type="hidden" name="form" value="department">
    <?php if($editDept): ?><input type="hidden" name="edit_dept_id" value="<?= $editDept['id'] ?>"><?php endif; ?>
    <div>
      <label class="form-label">Department Name *</label>
      <input type="text" name="dept_name" required class="form-input" value="<?= clean($editDept['name']??'') ?>" placeholder="e.g. Finance, Operations, Sales">
    </div>
    <div>
      <label class="form-label">Department Manager</label>
      <input type="text" name="dept_manager" class="form-input" value="<?= clean($editDept['manager_name']??'') ?>" placeholder="Manager's name">
    </div>
    <div>
      <label class="form-label">Description</label>
      <textarea name="dept_description" rows="3" class="form-input" placeholder="Brief description..."><?= clean($editDept['description']??'') ?></textarea>
    </div>
    <div class="flex justify-end gap-3 pt-2">
      <button type="button" onclick="closeModal('deptModal')" class="btn-secondary">Cancel</button>
      <button type="submit" class="btn-primary"><i data-lucide="save" class="w-4 h-4"></i> <?= $editDept?'Update':'Create' ?> Department</button>
    </div>
  </form>
</div></div>


<!-- STAFF MODAL -->
<div id="staffModal" class="modal-overlay <?= $editStaff?'':'hidden' ?>"><div class="modal-box max-w-3xl">
  <div class="flex items-center justify-between px-6 py-4 border-b">
    <h2 class="text-base font-semibold"><?= $editStaff?'Edit Staff Member':'Add Staff Member' ?></h2>
    <button onclick="closeModal('staffModal')" class="text-gray-400"><i data-lucide="x" class="w-5 h-5"></i></button>
  </div>
  <form method="POST" class="p-6">
    <input type="hidden" name="form" value="staff">
    <?php if($editStaff): ?><input type="hidden" name="edit_id" value="<?= $editStaff['id'] ?>"><?php endif; ?>

    <!-- Tabs -->
    <div class="flex gap-1 mb-5 border-b border-gray-100">
      <?php foreach(['personal'=>'Personal','employment'=>'Employment','payroll'=>'Payroll','emergency'=>'Emergency'] as $t=>$tl): ?>
      <button type="button" onclick="switchTab('<?= $t ?>')" id="tab_<?= $t ?>" class="tab-btn px-4 py-2 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-brand transition-colors"><?= $tl ?></button>
      <?php endforeach; ?>
    </div>

    <!-- Personal -->
    <div id="pane_personal" class="tab-pane">
      <div class="grid grid-cols-2 gap-4">
        <div><label class="form-label">First Name *</label><input type="text" name="first_name" required class="form-input" value="<?= clean($editStaff['first_name']??'') ?>"></div>
        <div><label class="form-label">Last Name *</label><input type="text" name="last_name" required class="form-input" value="<?= clean($editStaff['last_name']??'') ?>"></div>
        <div><label class="form-label">Email</label><input type="email" name="email" class="form-input" value="<?= clean($editStaff['email']??'') ?>"></div>
        <div><label class="form-label">Phone</label><input type="text" name="phone" class="form-input" value="<?= clean($editStaff['phone']??'') ?>"></div>
        <div><label class="form-label">Gender</label>
          <select name="gender" class="form-input">
            <option value="">Select...</option>
            <option value="male" <?= ($editStaff['gender']??'')==='male'?'selected':'' ?>>Male</option>
            <option value="female" <?= ($editStaff['gender']??'')==='female'?'selected':'' ?>>Female</option>
            <option value="other" <?= ($editStaff['gender']??'')==='other'?'selected':'' ?>>Other</option>
          </select>
        </div>
        <div><label class="form-label">Date of Birth</label><input type="date" name="date_of_birth" class="form-input" value="<?= $editStaff['date_of_birth']??'' ?>"></div>
        <div class="col-span-2"><label class="form-label">Address</label><textarea name="address" rows="2" class="form-input"><?= clean($editStaff['address']??'') ?></textarea></div>
        <div><label class="form-label">City</label><input type="text" name="city" class="form-input" value="<?= clean($editStaff['city']??'') ?>"></div>
        <div><label class="form-label">State</label><input type="text" name="state" class="form-input" value="<?= clean($editStaff['state']??'') ?>"></div>
      </div>
    </div>

    <!-- Employment -->
    <div id="pane_employment" class="tab-pane hidden">
      <div class="grid grid-cols-2 gap-4">
        <div><label class="form-label">Department</label>
          <select name="department_id" class="form-input">
            <option value="">None</option>
            <?php foreach($departments as $d): ?><option value="<?= $d['id'] ?>" <?= ($editStaff['department_id']??0)==$d['id']?'selected':'' ?>><?= clean($d['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div><label class="form-label">Job Title</label><input type="text" name="job_title" class="form-input" value="<?= clean($editStaff['job_title']??'') ?>"></div>
        <div><label class="form-label">Employment Type</label>
          <select name="employment_type" class="form-input">
            <?php foreach(['full_time'=>'Full Time','part_time'=>'Part Time','contract'=>'Contract','intern'=>'Intern'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($editStaff['employment_type']??'full_time')===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Status</label>
          <select name="status" id="staffStatus" class="form-input" onchange="handleStatusChange(this.value)">
            <?php foreach(['active'=>'Active','on_leave'=>'On Leave','inactive'=>'Inactive','terminated'=>'Terminated'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($editStaff['status']??'active')===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div><label class="form-label">Hire Date</label><input type="date" name="hire_date" class="form-input" value="<?= $editStaff['hire_date']??'' ?>"></div>
        <div></div>

        <!-- Leave Section — shown only when status = on_leave -->
        <div id="leaveSection" class="col-span-2 <?= ($editStaff['status']??'')==='on_leave'?'':'hidden' ?>">
          <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4 space-y-3">
            <div class="flex items-center gap-2 text-yellow-700 text-sm font-semibold">
              <i data-lucide="calendar-off" class="w-4 h-4"></i> Leave Duration
              <span class="text-xs font-normal text-yellow-600 ml-1">Staff auto-returns to Active when leave ends</span>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="form-label text-yellow-800">Leave Start Date *</label>
                <input type="date" name="leave_start" id="leaveStart" class="form-input"
                       value="<?= $activeLeave['leave_start']??date('Y-m-d') ?>"
                       min="<?= date('Y-m-d') ?>">
              </div>
              <div>
                <label class="form-label text-yellow-800">Leave End Date *</label>
                <input type="date" name="leave_end" id="leaveEnd" class="form-input"
                       value="<?= $activeLeave['leave_end']??'' ?>"
                       min="<?= date('Y-m-d',strtotime('+1 day')) ?>">
              </div>
            </div>
            <div>
              <label class="form-label text-yellow-800">Reason / Notes</label>
              <textarea name="leave_reason" rows="2" class="form-input" placeholder="Annual leave, medical, maternity..."><?= clean($activeLeave['leave_reason']??'') ?></textarea>
            </div>
            <div id="leaveDuration" class="text-xs text-yellow-700 font-medium"></div>
          </div>
        </div>

        <div class="col-span-2"><label class="form-label">Notes</label><textarea name="notes" rows="2" class="form-input"><?= clean($editStaff['notes']??'') ?></textarea></div>
      </div>
    </div>

    <!-- Payroll -->
    <div id="pane_payroll" class="tab-pane hidden">
      <div class="grid grid-cols-2 gap-4">
        <div><label class="form-label">Salary Amount</label><input type="number" name="salary" class="form-input" value="<?= $editStaff['salary']??0 ?>" min="0" step="0.01"></div>
        <div><label class="form-label">Salary Type</label>
          <select name="salary_type" class="form-input">
            <?php foreach(['monthly','weekly','daily','hourly'] as $t): ?>
            <option value="<?= $t ?>" <?= ($editStaff['salary_type']??'monthly')===$t?'selected':'' ?>><?= ucfirst($t) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div><label class="form-label">Bank Name</label><input type="text" name="bank_name" class="form-input" value="<?= clean($editStaff['bank_name']??'') ?>"></div>
        <div><label class="form-label">Account Number</label><input type="text" name="account_number" class="form-input" value="<?= clean($editStaff['account_number']??'') ?>"></div>
      </div>
    </div>

    <!-- Emergency -->
    <div id="pane_emergency" class="tab-pane hidden">
      <div class="grid grid-cols-2 gap-4">
        <div><label class="form-label">Emergency Contact Name</label><input type="text" name="emergency_contact_name" class="form-input" value="<?= clean($editStaff['emergency_contact_name']??'') ?>"></div>
        <div><label class="form-label">Emergency Contact Phone</label><input type="text" name="emergency_contact_phone" class="form-input" value="<?= clean($editStaff['emergency_contact_phone']??'') ?>"></div>
      </div>
    </div>

    <div class="flex justify-end gap-3 mt-6 pt-4 border-t">
      <button type="button" onclick="closeModal('staffModal')" class="btn-secondary">Cancel</button>
      <button type="submit" class="btn-primary"><i data-lucide="save" class="w-4 h-4"></i> Save Staff</button>
    </div>
  </form>
</div></div>


<script>
// Tab switching
function switchTab(tab) {
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.add('hidden'));
  document.querySelectorAll('.tab-btn').forEach(b => { b.classList.remove('border-brand','text-brand'); b.classList.add('border-transparent','text-gray-500'); });
  document.getElementById('pane_' + tab).classList.remove('hidden');
  document.getElementById('tab_' + tab).classList.add('border-brand','text-brand');
  document.getElementById('tab_' + tab).classList.remove('border-transparent','text-gray-500');
}
switchTab('personal');

// Leave calendar
function handleStatusChange(val) {
  const section = document.getElementById('leaveSection');
  const startEl = document.getElementById('leaveStart');
  const endEl   = document.getElementById('leaveEnd');
  if (val === 'on_leave') {
    section.classList.remove('hidden');
    startEl.required = true;
    endEl.required   = true;
    switchTab('employment');
    updateLeaveDuration();
  } else {
    section.classList.add('hidden');
    startEl.required = false;
    endEl.required   = false;
  }
}

function updateLeaveDuration() {
  const start = document.getElementById('leaveStart').value;
  const end   = document.getElementById('leaveEnd').value;
  const div   = document.getElementById('leaveDuration');
  if (start && end) {
    const diff = Math.round((new Date(end) - new Date(start)) / 86400000);
    if (diff > 0)      div.textContent = '📅 Duration: ' + diff + ' day' + (diff !== 1 ? 's' : '');
    else if (diff === 0) div.textContent = '📅 Duration: 1 day';
    else               div.textContent = '⚠️ End date must be after start date.';
  } else {
    div.textContent = '';
  }
}

document.getElementById('leaveStart').addEventListener('change', function () {
  const next = new Date(this.value); next.setDate(next.getDate() + 1);
  document.getElementById('leaveEnd').min = next.toISOString().split('T')[0];
  updateLeaveDuration();
});
document.getElementById('leaveEnd').addEventListener('change', updateLeaveDuration);

<?php if($editStaff && $editStaff['status']==='on_leave'): ?>
window.addEventListener('load', () => { handleStatusChange('on_leave'); updateLeaveDuration(); });
<?php endif; ?>
<?php if($editStaff): ?>window.addEventListener('load', () => openModal('staffModal'));<?php endif; ?>
<?php if($editDept):  ?>window.addEventListener('load', () => openModal('deptModal'));<?php endif; ?>
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

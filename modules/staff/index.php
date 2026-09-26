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

// Auto-expire leaves
try {
    $expiring = $db->query("
        SELECT s.id as staff_id, s.first_name, s.last_name, s.email,
               sl.leave_start, sl.leave_end,
               cu.name as creator_name, cu.email as creator_email
        FROM staff s
        INNER JOIN staff_leaves sl ON sl.staff_id = s.id
        LEFT JOIN users cu ON sl.created_by = cu.id
        WHERE s.status = 'on_leave'
          AND sl.status = 'active'
          AND sl.leave_end < CURDATE()
    ")->fetchAll();

    if ($expiring) {
        $db->query("
            UPDATE staff s
            INNER JOIN staff_leaves sl ON sl.staff_id = s.id
            SET s.status = 'active', sl.status = 'expired'
            WHERE s.status = 'on_leave'
              AND sl.status = 'active'
              AND sl.leave_end < CURDATE()
        ");
        foreach ($expiring as $exp) {
            $creator = $exp['creator_email'] ? ['name' => $exp['creator_name'], 'email' => $exp['creator_email']] : null;
            notifyLeaveCompleted($exp, $exp['leave_start'], $exp['leave_end'], $creator);
        }
    }
} catch (Exception $e) {}

// ── ACTIVATE LEAVE (from card action) ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'activate_leave') {
    $staffId     = (int)post('staff_id');
    $leaveStart  = post('leave_start');
    $leaveEnd    = post('leave_end');
    $leaveReason = post('leave_reason');

    if ($staffId && $leaveStart && $leaveEnd) {
        activateStaffLeave($staffId, $leaveStart, $leaveEnd, $leaveReason, (int)$_SESSION['user_id']);
        $_SESSION['flash_success'] = 'Leave activated successfully.';
    } else {
        $_SESSION['flash_error'] = 'Please fill in both leave start and end dates.';
    }
    header('Location: ' . APP_URL . '/modules/staff/index.php?tab=staff&status=on_leave'); exit;
}

// ── REVOKE LEAVE ─────────────────────────────────────────────
if (get('action') === 'revoke_leave' && get('id')) {
    $staffId = (int)get('id');
    revokeStaffLeave($staffId);
    $_SESSION['flash_success'] = 'Leave revoked. Staff is now active.';
    header('Location: ' . APP_URL . '/modules/staff/index.php?tab=staff'); exit;
}

// ── DELETE STAFF ──────────────────────────────────────────────
if (get('action') === 'delete_staff' && get('id')) {
    $db->prepare("UPDATE staff SET status='terminated' WHERE id=?")->execute([(int)get('id')]);
    $_SESSION['flash_success'] = 'Staff record terminated.';
    header('Location: ' . APP_URL . '/modules/staff/index.php'); exit;
}

// ── DELETE DEPARTMENT ─────────────────────────────────────────
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

// ── SAVE DEPARTMENT ───────────────────────────────────────────
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

// ── SAVE STAFF ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'staff') {
    $editId      = (int)post('edit_id');
    $newStatus   = post('status', 'active');
    $leaveStart  = post('leave_start');
    $leaveEnd    = post('leave_end');
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
            activateStaffLeave($editId, $leaveStart, $leaveEnd, $leaveReason, (int)$_SESSION['user_id']);
        }
        $_SESSION['flash_success'] = 'Staff record updated.';
    } else {
        $data['employee_id'] = 'EMP-' . str_pad((int)$db->query("SELECT COUNT(*)+1 FROM staff")->fetchColumn(), 4, '0', STR_PAD_LEFT);
        $cols = implode(',', array_keys($data));
        $ph   = implode(',', array_fill(0, count($data), '?'));
        $db->prepare("INSERT INTO staff ($cols) VALUES ($ph)")->execute(array_values($data));
        $newId = $db->lastInsertId();
        if ($newStatus === 'on_leave' && $leaveStart && $leaveEnd) {
            activateStaffLeave($newId, $leaveStart, $leaveEnd, $leaveReason, (int)$_SESSION['user_id']);
        }
        $_SESSION['flash_success'] = 'Staff member added.';
    }
    header('Location: ' . APP_URL . '/modules/staff/index.php'); exit;
}

// ── FETCH DATA ────────────────────────────────────────────────
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
$pg = paginate((int)$cnt->fetchColumn(), $page);

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

$editStaff = null; $activeLeave = null;
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

$summary = $db->query("
    SELECT COUNT(*) as total,
           SUM(salary) as total_salary,
           SUM(CASE WHEN status='active'   THEN 1 ELSE 0 END) as active,
           SUM(CASE WHEN status='on_leave' THEN 1 ELSE 0 END) as on_leave
    FROM staff
")->fetch();

$allLeaves = $db->query("
    SELECT sl.*, u.name as created_by_name
    FROM staff_leaves sl
    LEFT JOIN users u ON sl.created_by = u.id
    ORDER BY sl.leave_start DESC
")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="flex items-center justify-between gap-3 mb-6 flex-wrap">
  <div class="flex gap-1 border-b border-gray-200 self-end">
    <a href="?tab=staff" class="px-5 py-2.5 text-sm font-medium border-b-2 transition-colors <?= $tab==='staff'?'border-brand text-brand':'border-transparent text-gray-500 hover:text-gray-700' ?>">
      <i data-lucide="users" class="w-4 h-4 inline mr-1"></i> Staff
    </a>
    <a href="?tab=departments" class="px-5 py-2.5 text-sm font-medium border-b-2 transition-colors <?= $tab==='departments'?'border-brand text-brand':'border-transparent text-gray-500 hover:text-gray-700' ?>">
      <i data-lucide="building" class="w-4 h-4 inline mr-1"></i> Departments
      <span class="ml-1 px-1.5 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600"><?= count($departments) ?></span>
    </a>
  </div>
  <div class="flex items-center gap-2 flex-wrap">
    <?php if ($tab === 'staff'): ?>
    <div class="relative">
      <input type="text" placeholder="Search staff..." value="<?= clean($search) ?>"
             class="form-input pl-9 py-2 text-sm w-48"
             onchange="location='?tab=staff&search='+encodeURIComponent(this.value)+'&dept=<?= urlencode($filterDept) ?>&status=<?= urlencode($filterStatus) ?>'">
      <i data-lucide="search" class="w-4 h-4 text-gray-400 absolute left-2.5 top-2.5"></i>
    </div>
    <select onchange="location='?tab=staff&dept='+this.value+'&search=<?= urlencode($search) ?>&status=<?= urlencode($filterStatus) ?>'" class="form-input py-2 text-sm w-40">
      <option value="">All Departments</option>
      <?php foreach($departments as $d): ?>
      <option value="<?= $d['id'] ?>" <?= $filterDept==$d['id']?'selected':'' ?>><?= clean($d['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select onchange="location='?tab=staff&status='+this.value+'&search=<?= urlencode($search) ?>&dept=<?= urlencode($filterDept) ?>'" class="form-input py-2 text-sm w-36">
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
      <div class="w-12 h-12 rounded-full flex items-center justify-center text-white font-bold text-lg flex-shrink-0"
           style="background:var(--brand)">
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
      <?php if($staff['dept_name']): ?>
      <div class="flex items-center gap-2"><i data-lucide="building" class="w-3.5 h-3.5"></i><?= clean($staff['dept_name']) ?></div>
      <?php endif; ?>
      <?php if($staff['email']): ?>
      <div class="flex items-center gap-2 truncate"><i data-lucide="mail" class="w-3.5 h-3.5 flex-shrink-0"></i><?= clean($staff['email']) ?></div>
      <?php endif; ?>
      <?php if($staff['phone']): ?>
      <div class="flex items-center gap-2"><i data-lucide="phone" class="w-3.5 h-3.5"></i><?= clean($staff['phone']) ?></div>
      <?php endif; ?>
      <?php if($staff['hire_date']): ?>
      <div class="flex items-center gap-2"><i data-lucide="calendar" class="w-3.5 h-3.5"></i>Hired <?= formatDate($staff['hire_date']) ?></div>
      <?php endif; ?>

      <!-- Leave duration badge -->
      <?php if($staff['status']==='on_leave' && $staff['leave_start'] && $staff['leave_end']): ?>
      <div class="flex items-center gap-2 mt-1 bg-yellow-50 border border-yellow-200 rounded-lg px-2 py-1.5">
        <i data-lucide="calendar-off" class="w-3.5 h-3.5 text-yellow-600 flex-shrink-0"></i>
        <span class="text-yellow-700 font-medium">
          Leave: <?= formatDate($staff['leave_start']) ?> — <?= formatDate($staff['leave_end']) ?>
        </span>
      </div>
      <?php endif; ?>
    </div>

    <!-- Card Footer: salary + action buttons -->
    <div class="flex items-center justify-between border-t pt-3">
      <span class="text-xs font-semibold text-gray-800">
        <?= formatCurrency($staff['salary']) ?><span class="text-gray-400 font-normal">/<?= $staff['salary_type'] ?></span>
      </span>

      <!-- Action buttons -->
      <div class="flex items-center gap-1">

        <!-- View -->
        <button onclick="viewStaff(<?= $staff['id'] ?>)"
                class="p-1.5 rounded hover:bg-blue-50 text-blue-500" title="View Details">
          <i data-lucide="eye" class="w-3.5 h-3.5"></i>
        </button>

        <!-- Edit -->
        <a href="?edit=<?= $staff['id'] ?>&tab=staff"
           class="p-1.5 rounded hover:bg-yellow-50 text-yellow-500" title="Edit Staff">
          <i data-lucide="pencil" class="w-3.5 h-3.5"></i>
        </a>

        <?php if ($staff['status'] === 'on_leave'): ?>
        <!-- Revoke Leave — only shown when staff is on leave -->
        <button onclick="revokeLeave(<?= $staff['id'] ?>, '<?= clean($staff['first_name'].' '.$staff['last_name']) ?>')"
                class="p-1.5 rounded hover:bg-green-50 text-green-600" title="Revoke Leave">
          <i data-lucide="user-check" class="w-3.5 h-3.5"></i>
        </button>
        <?php else: ?>
        <!-- Activate Leave — shown for active / inactive staff -->
        <button onclick="openLeaveModal(<?= $staff['id'] ?>, '<?= clean($staff['first_name'].' '.$staff['last_name']) ?>')"
                class="p-1.5 rounded hover:bg-yellow-50 text-yellow-600" title="Activate Leave">
          <i data-lucide="calendar-off" class="w-3.5 h-3.5"></i>
        </button>
        <?php endif; ?>

        <!-- Terminate -->
        <button onclick="confirmDelete('?action=delete_staff&id=<?= $staff['id'] ?>','Terminate this staff member?')"
                class="p-1.5 rounded hover:bg-red-50 text-red-400" title="Terminate">
          <i data-lucide="user-x" class="w-3.5 h-3.5"></i>
        </button>

      </div>
    </div>

    <!-- Tooltip labels under buttons (visible on hover via title attr above, but add text labels for clarity) -->
    <?php if ($staff['status'] !== 'on_leave'): ?>
    <div class="flex justify-end mt-1 gap-1 text-gray-300" style="font-size:0.6rem">
      <span style="width:1.75rem;text-align:center">View</span>
      <span style="width:1.75rem;text-align:center">Edit</span>
      <span style="width:1.75rem;text-align:center">Leave</span>
      <span style="width:1.75rem;text-align:center">End</span>
    </div>
    <?php else: ?>
    <div class="flex justify-end mt-1 gap-1 text-gray-300" style="font-size:0.6rem">
      <span style="width:1.75rem;text-align:center">View</span>
      <span style="width:1.75rem;text-align:center">Edit</span>
      <span style="width:1.75rem;text-align:center">Revoke</span>
      <span style="width:1.75rem;text-align:center">End</span>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>

  <?php if(!$staffList): ?>
  <div class="col-span-3 card p-12 text-center text-gray-400">
    <i data-lucide="users" class="w-12 h-12 mx-auto mb-3 opacity-30"></i>
    <p class="font-medium">No staff members found.</p>
    <p class="text-sm mt-1">Click "Add Staff" to get started.</p>
  </div>
  <?php endif; ?>
</div>

<?php else: ?>
<!-- ── DEPARTMENTS TAB ────────────────────────────────────── -->
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
            <div class="w-8 h-8 rounded-lg bg-indigo-50 flex items-center justify-center">
              <i data-lucide="building" class="w-4 h-4 text-indigo-500"></i>
            </div>
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
            <button onclick="confirmDelete('?action=delete_dept&id=<?= $dept['id'] ?>','Delete department? All staff must be reassigned first.')" class="p-1.5 rounded hover:bg-red-50 text-red-400" title="Delete"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php endif; ?>


<!-- ══════════════════════════════════════════════════════════
     VIEW STAFF MODAL
════════════════════════════════════════════════════════════ -->
<div id="viewStaffModal" class="modal-overlay hidden">
<div class="modal-box max-w-2xl">

  <!-- Print-only header (hidden on screen, shown when printing) -->
  <div class="hidden print:block px-6 pt-6">
    <h1 class="text-xl font-bold text-gray-800"><?= clean(getSetting('app_name', APP_NAME)) ?></h1>
    <p class="text-sm text-gray-500">Staff Profile — Printed <?= date('d M Y') ?></p>
  </div>

  <!-- Header -->
  <div class="flex items-center justify-between gap-4 px-6 py-5 border-b border-gray-100" style="background:var(--brand-light)">
    <div class="flex items-center gap-4 min-w-0">
      <div id="vsAvatar" class="w-14 h-14 rounded-full flex items-center justify-center text-white font-bold text-xl flex-shrink-0" style="background:var(--brand)"></div>
      <div class="min-w-0">
        <h2 id="vsName" class="text-lg font-semibold text-gray-800 truncate"></h2>
        <p id="vsTitle" class="text-sm text-gray-500 truncate"></p>
        <p id="vsEmpId" class="text-xs font-medium mt-0.5" style="color:var(--brand)"></p>
      </div>
    </div>
    <div class="flex items-center gap-3 flex-shrink-0">
      <span id="vsStatus"></span>
      <button onclick="closeModal('viewStaffModal')" class="text-gray-400 hover:text-gray-600 no-print">
        <i data-lucide="x" class="w-5 h-5"></i>
      </button>
    </div>
  </div>

  <div class="p-6 space-y-6 max-h-[65vh] overflow-y-auto">

    <!-- Leave banner -->
    <div id="vsLeaveBanner" class="hidden bg-yellow-50 border border-yellow-200 rounded-lg px-4 py-3 flex items-center gap-2 text-sm text-yellow-800 font-medium">
      <i data-lucide="calendar-off" class="w-4 h-4 flex-shrink-0"></i>
      <span id="vsLeaveText"></span>
    </div>

    <!-- Personal Information -->
    <div>
      <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-3">Personal Information</h3>
      <div class="grid grid-cols-2 gap-x-6 gap-y-4">
        <div><p class="text-xs text-gray-400 mb-0.5">Email</p><p id="vsEmail" class="text-sm text-gray-800 font-medium break-all"></p></div>
        <div><p class="text-xs text-gray-400 mb-0.5">Phone</p><p id="vsPhone" class="text-sm text-gray-800 font-medium"></p></div>
        <div><p class="text-xs text-gray-400 mb-0.5">Gender</p><p id="vsGender" class="text-sm text-gray-800 font-medium capitalize"></p></div>
        <div><p class="text-xs text-gray-400 mb-0.5">Date of Birth</p><p id="vsDob" class="text-sm text-gray-800 font-medium"></p></div>
        <div class="col-span-2"><p class="text-xs text-gray-400 mb-0.5">Address</p><p id="vsAddress" class="text-sm text-gray-800 font-medium"></p></div>
      </div>
    </div>

    <!-- Employment -->
    <div class="border-t border-gray-100 pt-5">
      <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-3">Employment</h3>
      <div class="grid grid-cols-2 gap-x-6 gap-y-4">
        <div><p class="text-xs text-gray-400 mb-0.5">Department</p><p id="vsDept" class="text-sm text-gray-800 font-medium"></p></div>
        <div><p class="text-xs text-gray-400 mb-0.5">Employment Type</p><p id="vsEmpType" class="text-sm text-gray-800 font-medium capitalize"></p></div>
        <div><p class="text-xs text-gray-400 mb-0.5">Hire Date</p><p id="vsHireDate" class="text-sm text-gray-800 font-medium"></p></div>
      </div>
    </div>

    <!-- Payroll -->
    <div class="border-t border-gray-100 pt-5">
      <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-3">Payroll</h3>
      <div class="grid grid-cols-2 gap-x-6 gap-y-4">
        <div><p class="text-xs text-gray-400 mb-0.5">Salary</p><p id="vsSalary" class="text-sm text-gray-800 font-medium"></p></div>
        <div><p class="text-xs text-gray-400 mb-0.5">Bank Name</p><p id="vsBank" class="text-sm text-gray-800 font-medium"></p></div>
        <div><p class="text-xs text-gray-400 mb-0.5">Account Number</p><p id="vsAccount" class="text-sm text-gray-800 font-medium"></p></div>
      </div>
    </div>

    <!-- Emergency Contact -->
    <div class="border-t border-gray-100 pt-5">
      <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-3">Emergency Contact</h3>
      <div class="grid grid-cols-2 gap-x-6 gap-y-4">
        <div><p class="text-xs text-gray-400 mb-0.5">Name</p><p id="vsEmergName" class="text-sm text-gray-800 font-medium"></p></div>
        <div><p class="text-xs text-gray-400 mb-0.5">Phone</p><p id="vsEmergPhone" class="text-sm text-gray-800 font-medium"></p></div>
      </div>
    </div>

    <!-- Notes -->
    <div id="vsNotesWrap" class="border-t border-gray-100 pt-5 hidden">
      <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Notes</h3>
      <p id="vsNotes" class="text-sm text-gray-600 whitespace-pre-line"></p>
    </div>
  </div>

  <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-gray-100 bg-gray-50 rounded-b-2xl no-print">
    <button onclick="closeModal('viewStaffModal')" class="btn-secondary">Close</button>
    <button id="vsLeaveHistoryBtn" class="btn-secondary"><i data-lucide="history" class="w-4 h-4"></i> Leave History</button>
    <button onclick="printStaffProfile()" class="btn-secondary"><i data-lucide="printer" class="w-4 h-4"></i> Print</button>
    <a id="vsEditBtn" href="#" class="btn-primary"><i data-lucide="pencil" class="w-4 h-4"></i> Edit Staff</a>
  </div>
</div>
</div>


<!-- ══════════════════════════════════════════════════════════
     LEAVE HISTORY MODAL
════════════════════════════════════════════════════════════ -->
<div id="leaveHistoryModal" class="modal-overlay hidden">
<div class="modal-box max-w-xl">
  <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
    <div>
      <h2 class="text-base font-semibold text-gray-800 flex items-center gap-2">
        <i data-lucide="history" class="w-4 h-4" style="color:var(--brand)"></i> Leave History
      </h2>
      <p id="lhStaffName" class="text-xs text-gray-400 mt-0.5"></p>
    </div>
    <button onclick="closeModal('leaveHistoryModal')" class="text-gray-400 hover:text-gray-600">
      <i data-lucide="x" class="w-5 h-5"></i>
    </button>
  </div>

  <div id="lhBody" class="p-6 max-h-[65vh] overflow-y-auto"></div>

  <div class="flex justify-end px-6 py-4 border-t border-gray-100 bg-gray-50 rounded-b-2xl">
    <button onclick="closeModal('leaveHistoryModal')" class="btn-secondary">Close</button>
  </div>
</div>
</div>


<!-- ══════════════════════════════════════════════════════════
     ACTIVATE LEAVE MODAL
════════════════════════════════════════════════════════════ -->
<div id="leaveModal" class="modal-overlay hidden">
<div class="modal-box max-w-md">
  <div class="flex items-center justify-between px-6 py-4 border-b">
    <div>
      <h2 class="text-base font-semibold">Activate Leave</h2>
      <p id="leaveStaffName" class="text-xs text-gray-400 mt-0.5"></p>
    </div>
    <button onclick="closeModal('leaveModal')" class="text-gray-400 hover:text-gray-600">
      <i data-lucide="x" class="w-5 h-5"></i>
    </button>
  </div>
  <form method="POST" class="p-6 space-y-4" onsubmit="return validateLeaveForm()">
    <input type="hidden" name="form" value="activate_leave">
    <input type="hidden" name="staff_id" id="leaveStaffId">

    <div class="grid grid-cols-2 gap-4">
      <div>
        <label class="form-label">Leave Start Date *</label>
        <input type="date" name="leave_start" id="modalLeaveStart" required class="form-input"
               min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
      </div>
      <div>
        <label class="form-label">Leave End Date *</label>
        <input type="date" name="leave_end" id="modalLeaveEnd" required class="form-input"
               min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
      </div>
    </div>

    <!-- Live duration display -->
    <div id="modalLeaveDuration" class="hidden bg-yellow-50 border border-yellow-200 rounded-lg px-4 py-2.5 text-sm text-yellow-800 font-medium flex items-center gap-2">
      <i data-lucide="clock" class="w-4 h-4 flex-shrink-0"></i>
      <span id="modalDurationText"></span>
    </div>

    <div>
      <label class="form-label">Reason / Notes</label>
      <textarea name="leave_reason" rows="3" class="form-input"
                placeholder="Annual leave, medical leave, maternity leave..."></textarea>
    </div>

    <div class="bg-blue-50 border border-blue-100 rounded-lg px-4 py-2.5 text-xs text-blue-700">
      <i data-lucide="info" class="w-3.5 h-3.5 inline mr-1"></i>
      Staff status will automatically return to <strong>Active</strong> when the leave end date is reached.
    </div>

    <div class="flex justify-end gap-3 pt-2">
      <button type="button" onclick="closeModal('leaveModal')" class="btn-secondary">Cancel</button>
      <button type="submit" class="btn-primary">
        <i data-lucide="calendar-off" class="w-4 h-4"></i> Activate Leave
      </button>
    </div>
  </form>
</div>
</div>


<!-- ══════════════════════════════════════════════════════════
     REVOKE LEAVE CONFIRM MODAL
════════════════════════════════════════════════════════════ -->
<div id="revokeModal" class="modal-overlay hidden">
<div class="modal-box max-w-sm">
  <div class="flex items-center justify-between px-6 py-4 border-b">
    <h2 class="text-base font-semibold">Revoke Leave</h2>
    <button onclick="closeModal('revokeModal')" class="text-gray-400 hover:text-gray-600">
      <i data-lucide="x" class="w-5 h-5"></i>
    </button>
  </div>
  <div class="p-6">
    <div class="flex items-start gap-3 mb-5">
      <div class="w-10 h-10 rounded-full bg-green-100 flex items-center justify-center flex-shrink-0">
        <i data-lucide="user-check" class="w-5 h-5 text-green-600"></i>
      </div>
      <div>
        <p class="text-sm font-medium text-gray-800">Revoke leave for <span id="revokeStaffName" class="text-brand"></span>?</p>
        <p class="text-xs text-gray-500 mt-1">This will immediately set the staff status back to <strong>Active</strong> and cancel the current leave record.</p>
      </div>
    </div>
    <div class="flex justify-end gap-3">
      <button onclick="closeModal('revokeModal')" class="btn-secondary">Cancel</button>
      <a id="revokeConfirmLink" href="#" class="btn-primary" style="background:#16a34a">
        <i data-lucide="user-check" class="w-4 h-4"></i> Yes, Revoke Leave
      </a>
    </div>
  </div>
</div>
</div>


<!-- ══════════════════════════════════════════════════════════
     DEPARTMENT MODAL
════════════════════════════════════════════════════════════ -->
<div id="deptModal" class="modal-overlay <?= $editDept?'':'hidden' ?>">
<div class="modal-box max-w-md">
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
</div>
</div>


<!-- ══════════════════════════════════════════════════════════
     STAFF MODAL (Add / Edit)
════════════════════════════════════════════════════════════ -->
<div id="staffModal" class="modal-overlay <?= $editStaff?'':'hidden' ?>">
<div class="modal-box max-w-3xl">
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
      <button type="button" onclick="switchTab('<?= $t ?>')" id="tab_<?= $t ?>"
              class="tab-btn px-4 py-2 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-brand transition-colors"><?= $tl ?></button>
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
            <option value="male"   <?= ($editStaff['gender']??'')==='male'  ?'selected':'' ?>>Male</option>
            <option value="female" <?= ($editStaff['gender']??'')==='female'?'selected':'' ?>>Female</option>
            <option value="other"  <?= ($editStaff['gender']??'')==='other' ?'selected':'' ?>>Other</option>
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
            <?php foreach($departments as $d): ?>
            <option value="<?= $d['id'] ?>" <?= ($editStaff['department_id']??0)==$d['id']?'selected':'' ?>><?= clean($d['name']) ?></option>
            <?php endforeach; ?>
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
        <div><label class="form-label">Status</label>
          <select name="status" id="staffStatus" class="form-input" onchange="handleStatusChange(this.value)">
            <?php foreach(['active'=>'Active','on_leave'=>'On Leave','inactive'=>'Inactive','terminated'=>'Terminated'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($editStaff['status']??'active')===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div><label class="form-label">Hire Date</label><input type="date" name="hire_date" class="form-input" value="<?= $editStaff['hire_date']??'' ?>"></div>
        <div></div>

        <!-- Leave section inside staff modal -->
        <div id="leaveSection" class="col-span-2 <?= ($editStaff['status']??'')==='on_leave'?'':'hidden' ?>">
          <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4 space-y-3">
            <div class="flex items-center gap-2 text-yellow-700 text-sm font-semibold">
              <i data-lucide="calendar-off" class="w-4 h-4"></i> Leave Duration
              <span class="text-xs font-normal text-yellow-600 ml-1">— Auto-returns to Active when leave ends</span>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="form-label text-yellow-800">Leave Start *</label>
                <input type="date" name="leave_start" id="leaveStart" class="form-input"
                       value="<?= $activeLeave['leave_start']??date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>">
              </div>
              <div>
                <label class="form-label text-yellow-800">Leave End *</label>
                <input type="date" name="leave_end" id="leaveEnd" class="form-input"
                       value="<?= $activeLeave['leave_end']??'' ?>" min="<?= date('Y-m-d',strtotime('+1 day')) ?>">
              </div>
            </div>
            <div>
              <label class="form-label text-yellow-800">Reason</label>
              <textarea name="leave_reason" rows="2" class="form-input" placeholder="Annual, medical, maternity..."><?= clean($activeLeave['leave_reason']??'') ?></textarea>
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
</div>
</div>


<script>
// ── Tab switching ─────────────────────────────────────────────
function switchTab(tab) {
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.add('hidden'));
  document.querySelectorAll('.tab-btn').forEach(b => {
    b.classList.remove('border-brand','text-brand');
    b.classList.add('border-transparent','text-gray-500');
  });
  document.getElementById('pane_' + tab).classList.remove('hidden');
  document.getElementById('tab_' + tab).classList.add('border-brand','text-brand');
  document.getElementById('tab_' + tab).classList.remove('border-transparent','text-gray-500');
}
switchTab('personal');

// ── Status change inside staff edit modal ─────────────────────
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
    div.textContent = diff > 0 ? '📅 Duration: ' + diff + ' day' + (diff!==1?'s':'') : '⚠️ End date must be after start.';
  } else { div.textContent = ''; }
}
document.getElementById('leaveStart').addEventListener('change', function() {
  const next = new Date(this.value); next.setDate(next.getDate()+1);
  document.getElementById('leaveEnd').min = next.toISOString().split('T')[0];
  updateLeaveDuration();
});
document.getElementById('leaveEnd').addEventListener('change', updateLeaveDuration);

<?php if($editStaff && $editStaff['status']==='on_leave'): ?>
window.addEventListener('load', () => { handleStatusChange('on_leave'); updateLeaveDuration(); });
<?php endif; ?>
<?php if($editStaff): ?>window.addEventListener('load', () => openModal('staffModal'));<?php endif; ?>
<?php if($editDept):  ?>window.addEventListener('load', () => openModal('deptModal'));<?php endif; ?>

// ── Activate Leave modal (from card button) ───────────────────
function openLeaveModal(staffId, staffName) {
  document.getElementById('leaveStaffId').value  = staffId;
  document.getElementById('leaveStaffName').textContent = staffName;
  // Reset fields
  document.getElementById('modalLeaveStart').value = '<?= date('Y-m-d') ?>';
  document.getElementById('modalLeaveEnd').value   = '';
  document.getElementById('modalLeaveDuration').classList.add('hidden');
  openModal('leaveModal');
}

function validateLeaveForm() {
  const start = document.getElementById('modalLeaveStart').value;
  const end   = document.getElementById('modalLeaveEnd').value;
  if (!start || !end) { alert('Please select both start and end dates.'); return false; }
  if (new Date(end) <= new Date(start)) { alert('End date must be after start date.'); return false; }
  return true;
}

// Live duration inside leave modal
document.getElementById('modalLeaveStart').addEventListener('change', updateModalDuration);
document.getElementById('modalLeaveEnd').addEventListener('change', updateModalDuration);

function updateModalDuration() {
  const start = document.getElementById('modalLeaveStart').value;
  const end   = document.getElementById('modalLeaveEnd').value;
  const box   = document.getElementById('modalLeaveDuration');
  const txt   = document.getElementById('modalDurationText');
  if (start && end) {
    const diff = Math.round((new Date(end) - new Date(start)) / 86400000);
    if (diff > 0) {
      txt.textContent = 'Duration: ' + diff + ' day' + (diff!==1?'s':'');
      box.classList.remove('hidden');
    } else {
      txt.textContent = '⚠️ End date must be after start date.';
      box.classList.remove('hidden');
    }
  } else {
    box.classList.add('hidden');
  }
  // Also update end date min
  if (start) {
    const next = new Date(start); next.setDate(next.getDate()+1);
    document.getElementById('modalLeaveEnd').min = next.toISOString().split('T')[0];
  }
}

// ── Revoke Leave confirmation modal ──────────────────────────
function revokeLeave(staffId, staffName) {
  document.getElementById('revokeStaffName').textContent = staffName;
  document.getElementById('revokeConfirmLink').href = '?action=revoke_leave&id=' + staffId;
  openModal('revokeModal');
}

// ── View Staff modal ──────────────────────────────────────────
const staffData      = <?= json_encode($staffList, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const leaveHistoryData = <?= json_encode($allLeaves, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const currencySymbol = '<?= addslashes(APP_CURRENCY_SYMBOL) ?>';

function vsVal(v, fallback) {
  fallback = fallback || '—';
  return (v === null || v === undefined || v === '') ? fallback : v;
}
function vsDate(d) {
  if (!d) return '—';
  const dt = new Date(d + 'T00:00:00');
  if (isNaN(dt)) return '—';
  const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  return String(dt.getDate()).padStart(2,'0') + ' ' + months[dt.getMonth()] + ' ' + dt.getFullYear();
}
function vsMoney(v) {
  return currencySymbol + Number(v || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
}
function vsStatusBadge(status) {
  const map = {
    active: 'bg-green-100 text-green-700', inactive: 'bg-gray-100 text-gray-500',
    terminated: 'bg-red-100 text-red-700', on_leave: 'bg-orange-100 text-orange-700'
  };
  const cls   = map[status] || 'bg-gray-100 text-gray-600';
  const label = (status || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
  return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ' + cls + '">' + label + '</span>';
}

function viewStaff(id) {
  const s = staffData.find(x => String(x.id) === String(id));
  if (!s) return;

  document.getElementById('vsAvatar').textContent = (s.first_name?.[0] || '') + (s.last_name?.[0] || '');
  document.getElementById('vsName').textContent    = s.first_name + ' ' + s.last_name;
  document.getElementById('vsTitle').textContent   = s.job_title || 'No job title set';
  document.getElementById('vsEmpId').textContent   = s.employee_id || '';
  document.getElementById('vsStatus').innerHTML    = vsStatusBadge(s.status);

  document.getElementById('vsEmail').textContent  = vsVal(s.email);
  document.getElementById('vsPhone').textContent  = vsVal(s.phone);
  document.getElementById('vsGender').textContent = vsVal(s.gender);
  document.getElementById('vsDob').textContent    = vsDate(s.date_of_birth);
  document.getElementById('vsAddress').textContent = [s.address, s.city, s.state, s.country].filter(Boolean).join(', ') || '—';

  document.getElementById('vsDept').textContent     = vsVal(s.dept_name);
  document.getElementById('vsEmpType').textContent  = vsVal((s.employment_type || '').replace(/_/g, ' '));
  document.getElementById('vsHireDate').textContent = vsDate(s.hire_date);

  document.getElementById('vsSalary').textContent  = vsMoney(s.salary) + ' / ' + (s.salary_type || 'monthly');
  document.getElementById('vsBank').textContent    = vsVal(s.bank_name);
  document.getElementById('vsAccount').textContent = vsVal(s.account_number);

  document.getElementById('vsEmergName').textContent  = vsVal(s.emergency_contact_name);
  document.getElementById('vsEmergPhone').textContent = vsVal(s.emergency_contact_phone);

  const notesWrap = document.getElementById('vsNotesWrap');
  if (s.notes) {
    notesWrap.classList.remove('hidden');
    document.getElementById('vsNotes').textContent = s.notes;
  } else {
    notesWrap.classList.add('hidden');
  }

  const leaveBanner = document.getElementById('vsLeaveBanner');
  if (s.status === 'on_leave' && s.leave_start && s.leave_end) {
    leaveBanner.classList.remove('hidden');
    document.getElementById('vsLeaveText').textContent =
      'On leave: ' + vsDate(s.leave_start) + ' — ' + vsDate(s.leave_end) + (s.leave_reason ? ' (' + s.leave_reason + ')' : '');
  } else {
    leaveBanner.classList.add('hidden');
  }

  document.getElementById('vsEditBtn').href = '?edit=' + s.id + '&tab=staff';
  document.getElementById('vsLeaveHistoryBtn').onclick = () => viewLeaveHistory(s.id, s.first_name + ' ' + s.last_name);

  openModal('viewStaffModal');
  lucide.createIcons();
}

function printStaffProfile() {
  window.print();
}

// ── Leave History modal ────────────────────────────────────────
function lhStatusBadge(status) {
  const map   = { active: 'bg-orange-100 text-orange-700', cancelled: 'bg-gray-100 text-gray-500', expired: 'bg-blue-100 text-blue-700' };
  const cls   = map[status] || 'bg-gray-100 text-gray-600';
  const label = (status || '').replace(/\b\w/g, c => c.toUpperCase());
  return '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium ' + cls + '">' + label + '</span>';
}

function viewLeaveHistory(staffId, staffName) {
  document.getElementById('lhStaffName').textContent = staffName;

  const records = leaveHistoryData
    .filter(l => String(l.staff_id) === String(staffId))
    .sort((a, b) => new Date(b.leave_start) - new Date(a.leave_start));

  const body = document.getElementById('lhBody');

  if (!records.length) {
    body.innerHTML = `
      <div class="text-center text-gray-400 py-10">
        <i data-lucide="calendar-x" class="w-10 h-10 mx-auto mb-3 opacity-30"></i>
        <p class="text-sm font-medium">No leave records found.</p>
      </div>`;
  } else {
    body.innerHTML = records.map(r => {
      const days = Math.round((new Date(r.leave_end) - new Date(r.leave_start)) / 86400000) + 1;
      const recordedDate = r.created_at ? vsDate(r.created_at.split(' ')[0]) : '—';
      return `
        <div class="flex gap-4 pb-5 mb-5 border-b border-gray-100 last:border-0 last:mb-0 last:pb-0">
          <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0" style="background:var(--brand-light)">
            <i data-lucide="calendar-off" class="w-4 h-4" style="color:var(--brand)"></i>
          </div>
          <div class="flex-1 min-w-0">
            <div class="flex items-start justify-between gap-2 mb-1">
              <p class="text-sm font-semibold text-gray-800">${vsDate(r.leave_start)} — ${vsDate(r.leave_end)}</p>
              ${lhStatusBadge(r.status)}
            </div>
            <p class="text-xs text-gray-500 mb-1">${days} day${days !== 1 ? 's' : ''} &middot; ${r.leave_reason ? r.leave_reason : 'No reason specified'}</p>
            <p class="text-[11px] text-gray-400">Recorded by ${r.created_by_name || 'System'} on ${recordedDate}</p>
          </div>
        </div>`;
    }).join('');
  }

  openModal('leaveHistoryModal');
  lucide.createIcons();
}
</script>

<style>
@media print {
  body * { visibility: hidden; }
  #viewStaffModal, #viewStaffModal * { visibility: visible; }
  #viewStaffModal { position: absolute; inset: 0; background: white; padding: 2rem; }
  #viewStaffModal .modal-box { box-shadow: none; max-height: none; overflow: visible; width: 100%; border-radius: 0; }
  .no-print { display: none !important; }
}
</style>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

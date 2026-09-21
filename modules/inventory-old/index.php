<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
if (!hasPermission('inventory')) { $_SESSION['flash_error']='Access denied'; header('Location: '.APP_URL.'/modules/dashboard/index.php'); exit; }

$db = getDB();
$currentModule = 'inventory';
$pageTitle = 'Inventory';

if (get('action') === 'delete' && get('id')) {
    $db->prepare("UPDATE items SET status='inactive' WHERE id=?")->execute([(int)get('id')]);
    $_SESSION['flash_success'] = 'Item deactivated.';
    header('Location: ' . APP_URL . '/modules/inventory/index.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'item') {
    $editId = (int)post('edit_id');
    $data = [
        'category_id'     => (int)post('category_id') ?: null,
        'sku'             => post('sku'),
        'name'            => post('name'),
        'description'     => post('description'),
        'type'            => post('type','product'),
        'unit'            => post('unit'),
        'selling_price'   => (float)post('selling_price'),
        'cost_price'      => (float)post('cost_price'),
        'tax_percent'     => (float)post('tax_percent'),
        'track_inventory' => (int)post('track_inventory'),
        'reorder_point'   => (float)post('reorder_point'),
        'status'          => 'active',
    ];
    if ($editId) {
        $set = implode(', ', array_map(fn($k)=>"$k=?", array_keys($data)));
        $db->prepare("UPDATE items SET $set WHERE id=?")->execute([...array_values($data), $editId]);
        $_SESSION['flash_success'] = 'Item updated.';
    } else {
        $data['opening_stock'] = (float)post('opening_stock');
        $data['current_stock'] = $data['opening_stock'];
        $cols = implode(',', array_keys($data)); $ph = implode(',', array_fill(0,count($data),'?'));
        $db->prepare("INSERT INTO items ($cols) VALUES ($ph)")->execute(array_values($data));
        $_SESSION['flash_success'] = 'Item created.';
    }
    header('Location: ' . APP_URL . '/modules/inventory/index.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'adjustment') {
    $itemId = (int)post('item_id');
    $type   = post('adj_type');
    $qty    = (float)post('adj_qty');
    $db->prepare("INSERT INTO stock_adjustments (item_id, adjustment_date, type, quantity, reason, created_by) VALUES (?,NOW(),?,?,?,?)")
       ->execute([$itemId, $type, $qty, post('reason'), $_SESSION['user_id']]);
    $op = in_array($type, ['increase','return']) ? '+' : '-';
    $db->prepare("UPDATE items SET current_stock = current_stock $op ? WHERE id=?")->execute([$qty, $itemId]);
    $_SESSION['flash_success'] = 'Stock adjusted.';
    header('Location: ' . APP_URL . '/modules/inventory/index.php'); exit;
}

$search = get('search'); $filterType = get('ftype'); $page = max(1,(int)get('page',1));
$where=['i.status=\'active\'']; $params=[];
if ($search) { $where[]="(i.name LIKE ? OR i.sku LIKE ?)"; $s="%$search%"; $params=array_merge($params,[$s,$s]); }
if ($filterType) { $where[]="i.type=?"; $params[]=$filterType; }
$whereSQL = implode(' AND ',$where);
$total = $db->prepare("SELECT COUNT(*) FROM items i WHERE $whereSQL"); $total->execute($params);
$pg = paginate((int)$total->fetchColumn(),$page);
$stmt = $db->prepare("SELECT i.*, ic.name as category_name FROM items i LEFT JOIN item_categories ic ON i.category_id=ic.id WHERE $whereSQL ORDER BY i.name LIMIT {$pg['perPage']} OFFSET {$pg['offset']}");
$stmt->execute($params); $items = $stmt->fetchAll();

$categories = $db->query("SELECT * FROM item_categories ORDER BY name")->fetchAll();
$editItem = null;
if (get('edit')) { $s=$db->prepare("SELECT * FROM items WHERE id=?"); $s->execute([(int)get('edit')]); $editItem=$s->fetch(); }

// Summary
$summary = $db->query("SELECT COUNT(*) as total_items, SUM(current_stock * cost_price) as stock_value, SUM(CASE WHEN current_stock <= reorder_point AND track_inventory=1 THEN 1 ELSE 0 END) as low_stock FROM items WHERE status='active'")->fetch();

include __DIR__ . '/../../includes/header.php';
?>

<div class="flex items-center justify-between mb-6">
  <div class="flex gap-2">
    <div class="relative">
      <input type="text" placeholder="Search items..." value="<?= clean($search) ?>" class="form-input pl-9 py-2 text-sm w-56" onchange="location='?search='+encodeURIComponent(this.value)+'&ftype=<?= clean($filterType) ?>'">
      <i data-lucide="search" class="w-4 h-4 text-gray-400 absolute left-2.5 top-2.5"></i>
    </div>
    <select onchange="location='?ftype='+this.value+'&search=<?= urlencode($search) ?>'" class="form-input py-2 text-sm">
      <option value="">All Types</option>
      <option value="product" <?= $filterType==='product'?'selected':'' ?>>Products</option>
      <option value="service" <?= $filterType==='service'?'selected':'' ?>>Services</option>
    </select>
  </div>
  <button onclick="openModal('itemModal')" class="btn-primary"><i data-lucide="plus" class="w-4 h-4"></i> New Item</button>
</div>

<!-- Summary -->
<div class="grid grid-cols-3 gap-4 mb-6">
  <div class="stat-card"><p class="text-xs text-gray-400 mb-1">Total Items</p><p class="text-2xl font-bold"><?= $summary['total_items'] ?></p></div>
  <div class="stat-card"><p class="text-xs text-gray-400 mb-1">Stock Value</p><p class="text-2xl font-bold"><?= formatCurrency($summary['stock_value']??0) ?></p></div>
  <div class="stat-card"><p class="text-xs text-gray-400 mb-1">Low Stock Items</p><p class="text-2xl font-bold text-red-600"><?= $summary['low_stock'] ?></p></div>
</div>

<div class="card overflow-hidden">
  <table class="w-full">
    <thead class="bg-gray-50 border-b border-gray-100">
      <tr class="text-xs text-gray-500 font-medium">
        <th class="px-4 py-3 text-left">SKU</th>
        <th class="px-4 py-3 text-left">Name</th>
        <th class="px-4 py-3 text-left">Category</th>
        <th class="px-4 py-3 text-left">Type</th>
        <th class="px-4 py-3 text-right">Cost Price</th>
        <th class="px-4 py-3 text-right">Selling Price</th>
        <th class="px-4 py-3 text-right">Stock</th>
        <th class="px-4 py-3 text-center">Actions</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-50">
      <?php foreach ($items as $item): 
        $isLow = $item['track_inventory'] && $item['current_stock'] <= $item['reorder_point'];
      ?>
      <tr class="table-row <?= $isLow ? 'bg-red-50' : '' ?>">
        <td class="px-4 py-3 text-xs font-mono text-gray-500"><?= clean($item['sku']??'-') ?></td>
        <td class="px-4 py-3">
          <p class="text-xs font-medium text-gray-800"><?= clean($item['name']) ?></p>
          <?php if ($isLow): ?><p class="text-xs text-red-500 flex items-center gap-1"><i data-lucide="alert-triangle" class="w-3 h-3"></i> Low stock</p><?php endif; ?>
        </td>
        <td class="px-4 py-3 text-xs text-gray-500"><?= clean($item['category_name']??'-') ?></td>
        <td class="px-4 py-3"><span class="text-xs px-2 py-0.5 rounded-full <?= $item['type']==='product'?'bg-blue-100 text-blue-700':'bg-green-100 text-green-700' ?>"><?= ucfirst($item['type']) ?></span></td>
        <td class="px-4 py-3 text-xs text-right"><?= formatCurrency($item['cost_price']) ?></td>
        <td class="px-4 py-3 text-xs text-right font-medium"><?= formatCurrency($item['selling_price']) ?></td>
        <td class="px-4 py-3 text-xs text-right <?= $isLow?'text-red-600 font-bold':'' ?>">
          <?= $item['track_inventory'] ? number_format($item['current_stock'],2) . ' ' . clean($item['unit']??'') : '<span class="text-gray-400">N/A</span>' ?>
        </td>
        <td class="px-4 py-3 text-center">
          <div class="flex items-center justify-center gap-1">
            <a href="?edit=<?= $item['id'] ?>" class="p-1 rounded hover:bg-yellow-50 text-yellow-500" title="Edit"><i data-lucide="pencil" class="w-3.5 h-3.5"></i></a>
            <?php if ($item['track_inventory']): ?>
            <button onclick="openAdjModal(<?= $item['id'] ?>, '<?= clean($item['name']) ?>')" class="p-1 rounded hover:bg-blue-50 text-blue-500" title="Adjust Stock"><i data-lucide="layers" class="w-3.5 h-3.5"></i></button>
            <?php endif; ?>
            <button onclick="confirmDelete('?action=delete&id=<?= $item['id'] ?>','Deactivate item?')" class="p-1 rounded hover:bg-red-50 text-red-400" title="Delete"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$items): ?>
      <tr><td colspan="8" class="px-4 py-12 text-center text-sm text-gray-400">
        <i data-lucide="package" class="w-10 h-10 mx-auto mb-2 opacity-30"></i><p>No items yet.</p>
      </td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Item Modal -->
<div id="itemModal" class="modal-overlay <?= $editItem?'':'hidden' ?>">
<div class="modal-box max-w-2xl">
  <div class="flex items-center justify-between px-6 py-4 border-b">
    <h2 class="text-base font-semibold"><?= $editItem?'Edit Item':'New Item' ?></h2>
    <button onclick="closeModal('itemModal')" class="text-gray-400"><i data-lucide="x" class="w-5 h-5"></i></button>
  </div>
  <form method="POST" class="p-6">
    <input type="hidden" name="form" value="item">
    <?php if ($editItem): ?><input type="hidden" name="edit_id" value="<?= $editItem['id'] ?>"><?php endif; ?>
    <div class="grid grid-cols-2 gap-4">
      <div><label class="form-label">Type</label>
        <select name="type" class="form-input">
          <option value="product" <?= ($editItem['type']??'product')==='product'?'selected':'' ?>>Product</option>
          <option value="service" <?= ($editItem['type']??'')==='service'?'selected':'' ?>>Service</option>
        </select>
      </div>
      <div><label class="form-label">Category</label>
        <select name="category_id" class="form-input">
          <option value="">None</option>
          <?php foreach ($categories as $cat): ?>
          <option value="<?=$cat['id']?>" <?= ($editItem['category_id']??0)==$cat['id']?'selected':'' ?>><?= clean($cat['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-span-2"><label class="form-label">Item Name *</label><input type="text" name="name" required class="form-input" value="<?= clean($editItem['name']??'') ?>"></div>
      <div><label class="form-label">SKU</label><input type="text" name="sku" class="form-input" value="<?= clean($editItem['sku']??'') ?>" placeholder="Auto if blank"></div>
      <div><label class="form-label">Unit</label><input type="text" name="unit" class="form-input" value="<?= clean($editItem['unit']??'') ?>" placeholder="pcs, kg, hrs..."></div>
      <div><label class="form-label">Cost Price</label><input type="number" name="cost_price" class="form-input" value="<?= $editItem['cost_price']??0 ?>" min="0" step="0.01"></div>
      <div><label class="form-label">Selling Price</label><input type="number" name="selling_price" class="form-input" value="<?= $editItem['selling_price']??0 ?>" min="0" step="0.01"></div>
      <div><label class="form-label">Tax %</label><input type="number" name="tax_percent" class="form-input" value="<?= $editItem['tax_percent']??0 ?>" min="0" max="100" step="0.01"></div>
      <div class="flex items-center gap-3 pt-5">
        <input type="checkbox" name="track_inventory" value="1" id="trackInv" <?= ($editItem['track_inventory']??0)?'checked':'' ?> class="rounded">
        <label for="trackInv" class="form-label mb-0">Track Inventory</label>
      </div>
      <?php if (!$editItem): ?>
      <div><label class="form-label">Opening Stock</label><input type="number" name="opening_stock" class="form-input" value="0" min="0" step="0.001"></div>
      <?php endif; ?>
      <div><label class="form-label">Reorder Point</label><input type="number" name="reorder_point" class="form-input" value="<?= $editItem['reorder_point']??0 ?>" min="0" step="0.001"></div>
      <div class="col-span-2"><label class="form-label">Description</label><textarea name="description" rows="2" class="form-input"><?= clean($editItem['description']??'') ?></textarea></div>
    </div>
    <div class="flex justify-end gap-3 mt-5 pt-4 border-t">
      <button type="button" onclick="closeModal('itemModal')" class="btn-secondary">Cancel</button>
      <button type="submit" class="btn-primary"><i data-lucide="save" class="w-4 h-4"></i> Save Item</button>
    </div>
  </form>
</div>
</div>

<!-- Stock Adjustment Modal -->
<div id="adjModal" class="modal-overlay hidden">
<div class="modal-box max-w-md">
  <div class="flex items-center justify-between px-6 py-4 border-b">
    <h2 class="text-base font-semibold">Adjust Stock — <span id="adjItemName"></span></h2>
    <button onclick="closeModal('adjModal')" class="text-gray-400"><i data-lucide="x" class="w-5 h-5"></i></button>
  </div>
  <form method="POST" class="p-6 space-y-4">
    <input type="hidden" name="form" value="adjustment">
    <input type="hidden" name="item_id" id="adjItemId">
    <div><label class="form-label">Adjustment Type</label>
      <select name="adj_type" class="form-input">
        <option value="increase">Increase (Stock In)</option>
        <option value="decrease">Decrease (Stock Out)</option>
        <option value="damage">Damage / Write-off</option>
        <option value="return">Return</option>
      </select>
    </div>
    <div><label class="form-label">Quantity *</label><input type="number" name="adj_qty" required class="form-input" min="0.001" step="0.001" placeholder="0.00"></div>
    <div><label class="form-label">Reason</label><textarea name="reason" rows="2" class="form-input" placeholder="Reason for adjustment..."></textarea></div>
    <div class="flex justify-end gap-3 pt-2">
      <button type="button" onclick="closeModal('adjModal')" class="btn-secondary">Cancel</button>
      <button type="submit" class="btn-primary"><i data-lucide="check" class="w-4 h-4"></i> Adjust</button>
    </div>
  </form>
</div>
</div>

<script>
function openAdjModal(id, name) {
  document.getElementById('adjItemId').value = id;
  document.getElementById('adjItemName').textContent = name;
  openModal('adjModal');
}
<?php if ($editItem): ?>window.addEventListener('load',()=>openModal('itemModal'));<?php endif; ?>
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

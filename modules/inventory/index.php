<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
if (!hasPermission('inventory')) { $_SESSION['flash_error']='Access denied'; header('Location: '.APP_URL.'/modules/dashboard/index.php'); exit; }

$db = getDB();
$currentModule = 'inventory';
$pageTitle     = 'Inventory';

// ── Delete Item ───────────────────────────────────────────────
if (get('action') === 'delete' && get('id')) {
    $db->prepare("UPDATE items SET status='inactive' WHERE id=?")->execute([(int)get('id')]);
    $_SESSION['flash_success'] = 'Item deactivated.';
    header('Location: ' . APP_URL . '/modules/inventory/index.php'); exit;
}

// ── Delete Category ───────────────────────────────────────────
if (get('action') === 'delete_cat' && get('id')) {
    $used = $db->prepare("SELECT COUNT(*) FROM items WHERE category_id=? AND status='active'");
    $used->execute([(int)get('id')]);
    if ($used->fetchColumn() > 0) {
        $_SESSION['flash_error'] = 'Cannot delete — category has active items. Reassign them first.';
    } else {
        $db->prepare("DELETE FROM item_categories WHERE id=?")->execute([(int)get('id')]);
        $_SESSION['flash_success'] = 'Category deleted.';
    }
    header('Location: ' . APP_URL . '/modules/inventory/index.php?tab=categories'); exit;
}

// ── Create / Edit Category ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'category') {
    $editCatId = (int)post('edit_cat_id');
    $name      = trim(post('cat_name'));
    $desc      = trim(post('cat_description'));

    if (empty($name)) {
        $_SESSION['flash_error'] = 'Category name is required.';
        header('Location: ' . APP_URL . '/modules/inventory/index.php?tab=categories'); exit;
    }
    if ($editCatId) {
        $db->prepare("UPDATE item_categories SET name=?, description=? WHERE id=?")
           ->execute([$name, $desc, $editCatId]);
        $_SESSION['flash_success'] = 'Category updated.';
    } else {
        $db->prepare("INSERT INTO item_categories (name, description) VALUES (?,?)")
           ->execute([$name, $desc]);
        $_SESSION['flash_success'] = 'Category "' . htmlspecialchars($name) . '" created successfully.';
    }
    header('Location: ' . APP_URL . '/modules/inventory/index.php?tab=categories'); exit;
}

// ── Create / Edit Item ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'item') {
    $editId = (int)post('edit_id');
    $data   = [
        'category_id'     => (int)post('category_id') ?: null,
        'sku'             => post('sku') ?: null,
        'name'            => post('name'),
        'description'     => post('description'),
        'type'            => post('type', 'product'),
        'unit'            => post('unit'),
        'selling_price'   => (float)post('selling_price'),
        'cost_price'      => (float)post('cost_price'),
        'tax_percent'     => (float)post('tax_percent'),
        'track_inventory' => (int)post('track_inventory'),
        'reorder_point'   => (float)post('reorder_point'),
        'status'          => 'active',
    ];
    if ($editId) {
        $set = implode(', ', array_map(fn($k) => "$k=?", array_keys($data)));
        $db->prepare("UPDATE items SET $set WHERE id=?")->execute([...array_values($data), $editId]);
        $_SESSION['flash_success'] = 'Item updated.';
    } else {
        $data['opening_stock'] = (float)post('opening_stock');
        $data['current_stock'] = $data['opening_stock'];
        $cols = implode(',', array_keys($data));
        $ph   = implode(',', array_fill(0, count($data), '?'));
        $db->prepare("INSERT INTO items ($cols) VALUES ($ph)")->execute(array_values($data));
        $_SESSION['flash_success'] = 'Item created.';
    }
    header('Location: ' . APP_URL . '/modules/inventory/index.php'); exit;
}

// ── Stock Adjustment ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'adjustment') {
    $itemId = (int)post('item_id');
    $type   = post('adj_type');
    $qty    = (float)post('adj_qty');
    $db->prepare("INSERT INTO stock_adjustments (item_id, adjustment_date, type, quantity, reason, created_by) VALUES (?,NOW(),?,?,?,?)")
       ->execute([$itemId, $type, $qty, post('reason'), $_SESSION['user_id']]);
    $op = in_array($type, ['increase', 'return']) ? '+' : '-';
    $db->prepare("UPDATE items SET current_stock = current_stock $op ? WHERE id=?")->execute([$qty, $itemId]);
    $_SESSION['flash_success'] = 'Stock adjusted.';
    header('Location: ' . APP_URL . '/modules/inventory/index.php'); exit;
}

// ── Fetch Data ────────────────────────────────────────────────
$tab        = get('tab', 'items');
$search     = get('search');
$filterType = get('ftype');
$filterCat  = get('cat');
$page       = max(1, (int)get('page', 1));

$where  = ["i.status='active'"]; $params = [];
if ($search)     { $where[] = "(i.name LIKE ? OR i.sku LIKE ?)"; $s = "%$search%"; $params = array_merge($params, [$s, $s]); }
if ($filterType) { $where[] = "i.type=?"; $params[] = $filterType; }
if ($filterCat)  { $where[] = "i.category_id=?"; $params[] = $filterCat; }
$whereSQL = implode(' AND ', $where);

$totalItems = $db->prepare("SELECT COUNT(*) FROM items i WHERE $whereSQL");
$totalItems->execute($params);
$pg = paginate((int)$totalItems->fetchColumn(), $page);

$stmt = $db->prepare("
    SELECT i.*, ic.name as category_name
    FROM items i LEFT JOIN item_categories ic ON i.category_id = ic.id
    WHERE $whereSQL ORDER BY i.name
    LIMIT {$pg['perPage']} OFFSET {$pg['offset']}
");
$stmt->execute($params);
$items = $stmt->fetchAll();

$categories = $db->query("SELECT ic.*, COUNT(i.id) as item_count FROM item_categories ic LEFT JOIN items i ON ic.id=i.category_id AND i.status='active' GROUP BY ic.id ORDER BY ic.name")->fetchAll();

$editItem = null;
if (get('edit')) {
    $s = $db->prepare("SELECT * FROM items WHERE id=?");
    $s->execute([(int)get('edit')]);
    $editItem = $s->fetch();
}

$editCat = null;
if (get('edit_cat')) {
    $sc = $db->prepare("SELECT * FROM item_categories WHERE id=?");
    $sc->execute([(int)get('edit_cat')]);
    $editCat = $sc->fetch();
}

// Summary stats
$summary = $db->query("
    SELECT
      COUNT(*) as total_items,
      SUM(current_stock * cost_price) as stock_value,
      SUM(CASE WHEN current_stock <= reorder_point AND track_inventory=1 THEN 1 ELSE 0 END) as low_stock,
      COUNT(DISTINCT category_id) as categories
    FROM items WHERE status='active'
")->fetch();

include __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="flex items-center justify-between mb-6">
  <div class="flex gap-1 border-b border-gray-200">
    <a href="?tab=items"
       class="px-5 py-2.5 text-sm font-medium border-b-2 transition-colors
              <?= $tab==='items' ? 'border-brand text-brand' : 'border-transparent text-gray-500 hover:text-gray-700' ?>">
      <i data-lucide="package" class="w-4 h-4 inline mr-1"></i> Items
    </a>
    <a href="?tab=categories"
       class="px-5 py-2.5 text-sm font-medium border-b-2 transition-colors
              <?= $tab==='categories' ? 'border-brand text-brand' : 'border-transparent text-gray-500 hover:text-gray-700' ?>">
      <i data-lucide="folder" class="w-4 h-4 inline mr-1"></i> Categories
      <span class="ml-1 px-1.5 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600"><?= count($categories) ?></span>
    </a>
  </div>
  <div class="flex gap-2">
    <?php if ($tab === 'categories'): ?>
      <button onclick="openModal('catModal')" class="btn-primary">
        <i data-lucide="folder-plus" class="w-4 h-4"></i> New Category
      </button>
    <?php else: ?>
      <button onclick="openModal('itemModal')" class="btn-primary">
        <i data-lucide="plus" class="w-4 h-4"></i> New Item
      </button>
    <?php endif; ?>
  </div>
</div>

<!-- Summary Cards -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
  <?php $stats = [
    ['Total Items',    $summary['total_items'],               'package',         'blue'],
    ['Categories',     count($categories),                    'folder',          'purple'],
    ['Stock Value',    formatCurrency($summary['stock_value']??0), 'trending-up','green'],
    ['Low Stock',      $summary['low_stock'],                 'alert-triangle',  'red'],
  ]; foreach ($stats as [$label, $val, $icon, $col]): ?>
  <div class="stat-card">
    <div class="flex items-center justify-between mb-2">
      <p class="text-xs text-gray-400 font-medium"><?= $label ?></p>
      <div class="w-7 h-7 rounded-lg bg-<?= $col ?>-50 flex items-center justify-center">
        <i data-lucide="<?= $icon ?>" class="w-3.5 h-3.5 text-<?= $col ?>-600"></i>
      </div>
    </div>
    <p class="text-xl font-bold text-gray-800"><?= $val ?></p>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'items'): ?>
<!-- ── ITEMS TAB ──────────────────────────────────────────── -->

<!-- Filters -->
<div class="flex flex-wrap gap-2 mb-4">
  <div class="relative">
    <input type="text" placeholder="Search items..." value="<?= clean($search) ?>"
           class="form-input pl-9 py-2 text-sm w-56"
           onchange="location='?tab=items&search='+encodeURIComponent(this.value)+'&ftype=<?= urlencode($filterType) ?>&cat=<?= urlencode($filterCat) ?>'">
    <i data-lucide="search" class="w-4 h-4 text-gray-400 absolute left-2.5 top-2.5"></i>
  </div>
  <select onchange="location='?tab=items&ftype='+this.value+'&search=<?= urlencode($search) ?>&cat=<?= urlencode($filterCat) ?>'" class="form-input py-2 text-sm w-36">
    <option value="">All Types</option>
    <option value="product" <?= $filterType==='product'?'selected':'' ?>>Products</option>
    <option value="service" <?= $filterType==='service'?'selected':'' ?>>Services</option>
  </select>
  <select onchange="location='?tab=items&cat='+this.value+'&search=<?= urlencode($search) ?>&ftype=<?= urlencode($filterType) ?>'" class="form-input py-2 text-sm w-44">
    <option value="">All Categories</option>
    <?php foreach ($categories as $cat): ?>
    <option value="<?= $cat['id'] ?>" <?= $filterCat==$cat['id']?'selected':'' ?>><?= clean($cat['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <?php if ($search||$filterType||$filterCat): ?>
  <a href="?tab=items" class="btn-secondary text-xs py-2"><i data-lucide="x" class="w-3.5 h-3.5"></i> Clear</a>
  <?php endif; ?>
</div>

<div class="card overflow-hidden">
  <table class="w-full">
    <thead class="bg-gray-50 border-b border-gray-100">
      <tr class="text-xs text-gray-500 font-medium">
        <th class="px-4 py-3 text-left">SKU</th>
        <th class="px-4 py-3 text-left">Name</th>
        <th class="px-4 py-3 text-left">Category</th>
        <th class="px-4 py-3 text-left">Type</th>
        <th class="px-4 py-3 text-right">Cost</th>
        <th class="px-4 py-3 text-right">Price</th>
        <th class="px-4 py-3 text-right">Stock</th>
        <th class="px-4 py-3 text-center">Actions</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-50">
      <?php foreach ($items as $item):
        $isLow = $item['track_inventory'] && $item['current_stock'] <= $item['reorder_point'];
      ?>
      <tr class="table-row <?= $isLow ? 'bg-red-50' : '' ?>">
        <td class="px-4 py-3 text-xs font-mono text-gray-400"><?= clean($item['sku'] ?? '-') ?></td>
        <td class="px-4 py-3">
          <p class="text-xs font-medium text-gray-800"><?= clean($item['name']) ?></p>
          <?php if ($isLow): ?><p class="text-xs text-red-500 flex items-center gap-1 mt-0.5"><i data-lucide="alert-triangle" class="w-3 h-3"></i> Low stock</p><?php endif; ?>
        </td>
        <td class="px-4 py-3">
          <?php if ($item['category_name']): ?>
          <span class="text-xs px-2 py-0.5 rounded-full bg-purple-50 text-purple-700"><?= clean($item['category_name']) ?></span>
          <?php else: ?><span class="text-xs text-gray-300">—</span><?php endif; ?>
        </td>
        <td class="px-4 py-3">
          <span class="text-xs px-2 py-0.5 rounded-full <?= $item['type']==='product'?'bg-blue-50 text-blue-700':'bg-green-50 text-green-700' ?>">
            <?= ucfirst($item['type']) ?>
          </span>
        </td>
        <td class="px-4 py-3 text-xs text-right"><?= formatCurrency($item['cost_price']) ?></td>
        <td class="px-4 py-3 text-xs text-right font-medium"><?= formatCurrency($item['selling_price']) ?></td>
        <td class="px-4 py-3 text-xs text-right <?= $isLow?'text-red-600 font-bold':'' ?>">
          <?= $item['track_inventory']
              ? number_format($item['current_stock'], 2) . ' <span class="text-gray-400">' . clean($item['unit'] ?? '') . '</span>'
              : '<span class="text-gray-300">N/A</span>' ?>
        </td>
        <td class="px-4 py-3 text-center">
          <div class="flex items-center justify-center gap-1">
            <a href="?edit=<?= $item['id'] ?>" class="p-1 rounded hover:bg-yellow-50 text-yellow-500" title="Edit"><i data-lucide="pencil" class="w-3.5 h-3.5"></i></a>
            <?php if ($item['track_inventory']): ?>
            <button onclick="openAdjModal(<?= $item['id'] ?>, '<?= clean($item['name']) ?>')"
                    class="p-1 rounded hover:bg-blue-50 text-blue-500" title="Adjust Stock">
              <i data-lucide="layers" class="w-3.5 h-3.5"></i>
            </button>
            <?php endif; ?>
            <button onclick="confirmDelete('?action=delete&id=<?= $item['id'] ?>','Deactivate this item?')"
                    class="p-1 rounded hover:bg-red-50 text-red-400" title="Deactivate">
              <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
            </button>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$items): ?>
      <tr><td colspan="8" class="px-4 py-12 text-center text-sm text-gray-400">
        <i data-lucide="package" class="w-10 h-10 mx-auto mb-2 opacity-30"></i>
        <p>No items found.</p>
        <?php if (!$categories): ?>
        <p class="text-xs mt-1">Start by <a href="?tab=categories" class="text-brand underline">creating a category</a> first.</p>
        <?php endif; ?>
      </td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <?php if ($pg['totalPages'] > 1): ?>
  <div class="px-4 py-3 border-t border-gray-100 flex items-center justify-between">
    <p class="text-xs text-gray-500"><?= count($items) ?> of <?= $pg['total'] ?> items</p>
    <div class="flex gap-1">
      <?php for ($p = 1; $p <= $pg['totalPages']; $p++): ?>
      <a href="?tab=items&page=<?= $p ?>&search=<?= urlencode($search) ?>&ftype=<?= urlencode($filterType) ?>&cat=<?= urlencode($filterCat) ?>"
         class="px-3 py-1 text-xs rounded <?= $p===$pg['page']?'bg-brand text-white':'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php else: ?>
<!-- ── CATEGORIES TAB ─────────────────────────────────────── -->

<?php if (!$categories): ?>
<div class="card p-12 text-center text-gray-400">
  <i data-lucide="folder-open" class="w-12 h-12 mx-auto mb-3 opacity-30"></i>
  <p class="font-medium text-gray-600">No categories yet</p>
  <p class="text-sm mt-1 mb-4">Create your first item category to organize your inventory.</p>
  <button onclick="openModal('catModal')" class="btn-primary mx-auto">
    <i data-lucide="folder-plus" class="w-4 h-4"></i> Create First Category
  </button>
</div>
<?php else: ?>
<div class="card overflow-hidden">
  <table class="w-full">
    <thead class="bg-gray-50 border-b border-gray-100">
      <tr class="text-xs text-gray-500 font-medium">
        <th class="px-4 py-3 text-left">#</th>
        <th class="px-4 py-3 text-left">Category Name</th>
        <th class="px-4 py-3 text-left">Description</th>
        <th class="px-4 py-3 text-center">Items</th>
        <th class="px-4 py-3 text-center">Actions</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-50">
      <?php foreach ($categories as $i => $cat): ?>
      <tr class="table-row">
        <td class="px-4 py-3 text-xs text-gray-400"><?= $i + 1 ?></td>
        <td class="px-4 py-3">
          <div class="flex items-center gap-2">
            <div class="w-7 h-7 rounded-lg bg-purple-50 flex items-center justify-center">
              <i data-lucide="folder" class="w-3.5 h-3.5 text-purple-500"></i>
            </div>
            <span class="text-sm font-medium text-gray-800"><?= clean($cat['name']) ?></span>
          </div>
        </td>
        <td class="px-4 py-3 text-xs text-gray-500"><?= clean($cat['description'] ?? '—') ?></td>
        <td class="px-4 py-3 text-center">
          <a href="?tab=items&cat=<?= $cat['id'] ?>"
             class="inline-flex items-center gap-1 text-xs font-semibold text-brand hover:underline">
            <?= $cat['item_count'] ?> item<?= $cat['item_count'] != 1 ? 's' : '' ?>
          </a>
        </td>
        <td class="px-4 py-3 text-center">
          <div class="flex items-center justify-center gap-1">
            <a href="?tab=categories&edit_cat=<?= $cat['id'] ?>"
               class="p-1 rounded hover:bg-yellow-50 text-yellow-500" title="Edit">
              <i data-lucide="pencil" class="w-3.5 h-3.5"></i>
            </a>
            <button onclick="confirmDelete('?action=delete_cat&id=<?= $cat['id'] ?>','Delete this category?')"
                    class="p-1 rounded hover:bg-red-50 text-red-400" title="Delete">
              <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
            </button>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php endif; // end tab ?>


<!-- ── Category Modal ────────────────────────────────────── -->
<div id="catModal" class="modal-overlay <?= ($editCat || (get('tab')==='categories' && isset($_SESSION['_open_cat']))) ? '' : 'hidden' ?>">
<div class="modal-box max-w-md">
  <div class="flex items-center justify-between px-6 py-4 border-b">
    <h2 class="text-base font-semibold"><?= $editCat ? 'Edit Category' : 'New Category' ?></h2>
    <button onclick="closeModal('catModal')" class="text-gray-400 hover:text-gray-600">
      <i data-lucide="x" class="w-5 h-5"></i>
    </button>
  </div>
  <form method="POST" class="p-6 space-y-4">
    <input type="hidden" name="form" value="category">
    <?php if ($editCat): ?><input type="hidden" name="edit_cat_id" value="<?= $editCat['id'] ?>"><?php endif; ?>

    <div>
      <label class="form-label">Category Name *</label>
      <input type="text" name="cat_name" required class="form-input"
             value="<?= clean($editCat['name'] ?? '') ?>"
             placeholder="e.g. Electronics, Raw Materials, Office Supplies">
    </div>
    <div>
      <label class="form-label">Description</label>
      <textarea name="cat_description" rows="3" class="form-input"
                placeholder="Optional description..."><?= clean($editCat['description'] ?? '') ?></textarea>
    </div>

    <div class="flex justify-end gap-3 pt-2">
      <button type="button" onclick="closeModal('catModal')" class="btn-secondary">Cancel</button>
      <button type="submit" class="btn-primary">
        <i data-lucide="save" class="w-4 h-4"></i>
        <?= $editCat ? 'Update Category' : 'Create Category' ?>
      </button>
    </div>
  </form>
</div>
</div>


<!-- ── Item Modal ────────────────────────────────────────── -->
<div id="itemModal" class="modal-overlay <?= $editItem ? '' : 'hidden' ?>">
<div class="modal-box max-w-2xl">
  <div class="flex items-center justify-between px-6 py-4 border-b">
    <h2 class="text-base font-semibold"><?= $editItem ? 'Edit Item' : 'New Item' ?></h2>
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
          <option value="">— No Category —</option>
          <?php foreach ($categories as $cat): ?>
          <option value="<?= $cat['id'] ?>" <?= ($editItem['category_id']??0)==$cat['id']?'selected':'' ?>><?= clean($cat['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (!$categories): ?>
        <p class="text-xs text-orange-500 mt-1">
          <a href="?tab=categories" class="underline">Create a category</a> first to organize items.
        </p>
        <?php endif; ?>
      </div>
      <div class="col-span-2"><label class="form-label">Item Name *</label>
        <input type="text" name="name" required class="form-input" value="<?= clean($editItem['name']??'') ?>" placeholder="Item name">
      </div>
      <div><label class="form-label">SKU / Code</label>
        <input type="text" name="sku" class="form-input" value="<?= clean($editItem['sku']??'') ?>" placeholder="Leave blank to skip">
      </div>
      <div><label class="form-label">Unit</label>
        <input type="text" name="unit" class="form-input" value="<?= clean($editItem['unit']??'') ?>" placeholder="pcs, kg, hrs, litres…">
      </div>
      <div><label class="form-label">Cost Price (<?= APP_CURRENCY_SYMBOL ?>)</label>
        <input type="number" name="cost_price" class="form-input" value="<?= $editItem['cost_price']??0 ?>" min="0" step="0.01">
      </div>
      <div><label class="form-label">Selling Price (<?= APP_CURRENCY_SYMBOL ?>)</label>
        <input type="number" name="selling_price" class="form-input" value="<?= $editItem['selling_price']??0 ?>" min="0" step="0.01">
      </div>
      <div><label class="form-label">Tax %</label>
        <input type="number" name="tax_percent" class="form-input" value="<?= $editItem['tax_percent']??0 ?>" min="0" max="100" step="0.01">
      </div>
      <div><label class="form-label">Reorder Point</label>
        <input type="number" name="reorder_point" class="form-input" value="<?= $editItem['reorder_point']??0 ?>" min="0" step="0.001">
      </div>
      <div class="col-span-2 flex items-center gap-3 p-3 bg-gray-50 rounded-xl">
        <input type="checkbox" name="track_inventory" value="1" id="trackInv"
               <?= ($editItem['track_inventory']??0)?'checked':'' ?> class="rounded w-4 h-4">
        <div>
          <label for="trackInv" class="text-sm font-medium text-gray-700 cursor-pointer">Track Inventory</label>
          <p class="text-xs text-gray-400">Enable to monitor stock levels and get low-stock alerts.</p>
        </div>
      </div>
      <?php if (!$editItem): ?>
      <div class="col-span-2"><label class="form-label">Opening Stock</label>
        <input type="number" name="opening_stock" class="form-input" value="0" min="0" step="0.001">
      </div>
      <?php endif; ?>
      <div class="col-span-2"><label class="form-label">Description</label>
        <textarea name="description" rows="2" class="form-input" placeholder="Optional item description..."><?= clean($editItem['description']??'') ?></textarea>
      </div>
    </div>
    <div class="flex justify-end gap-3 mt-5 pt-4 border-t">
      <button type="button" onclick="closeModal('itemModal')" class="btn-secondary">Cancel</button>
      <button type="submit" class="btn-primary"><i data-lucide="save" class="w-4 h-4"></i> Save Item</button>
    </div>
  </form>
</div>
</div>


<!-- ── Stock Adjustment Modal ────────────────────────────── -->
<div id="adjModal" class="modal-overlay hidden">
<div class="modal-box max-w-md">
  <div class="flex items-center justify-between px-6 py-4 border-b">
    <h2 class="text-base font-semibold">Adjust Stock — <span id="adjItemName" class="text-brand"></span></h2>
    <button onclick="closeModal('adjModal')" class="text-gray-400"><i data-lucide="x" class="w-5 h-5"></i></button>
  </div>
  <form method="POST" class="p-6 space-y-4">
    <input type="hidden" name="form" value="adjustment">
    <input type="hidden" name="item_id" id="adjItemId">
    <div><label class="form-label">Adjustment Type</label>
      <select name="adj_type" class="form-input">
        <option value="increase">⬆ Increase (Stock In)</option>
        <option value="decrease">⬇ Decrease (Stock Out)</option>
        <option value="damage">⚠ Damage / Write-off</option>
        <option value="return">↩ Customer Return</option>
      </select>
    </div>
    <div><label class="form-label">Quantity *</label>
      <input type="number" name="adj_qty" required class="form-input" min="0.001" step="0.001" placeholder="0.00">
    </div>
    <div><label class="form-label">Reason / Notes</label>
      <textarea name="reason" rows="2" class="form-input" placeholder="Brief reason for the adjustment…"></textarea>
    </div>
    <div class="flex justify-end gap-3 pt-2">
      <button type="button" onclick="closeModal('adjModal')" class="btn-secondary">Cancel</button>
      <button type="submit" class="btn-primary"><i data-lucide="check" class="w-4 h-4"></i> Apply Adjustment</button>
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
<?php if ($editItem): ?>window.addEventListener('load', () => openModal('itemModal'));<?php endif; ?>
<?php if ($editCat):  ?>window.addEventListener('load', () => openModal('catModal'));<?php endif; ?>
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

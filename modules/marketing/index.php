<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
if (!hasPermission('marketing')) { $_SESSION['flash_error'] = 'Access denied'; header('Location: ' . APP_URL . '/modules/dashboard/index.php'); exit; }

$db            = getDB();
$currentModule = 'marketing';
$pageTitle     = 'Marketing';

// ── DELETE LEAD ────────────────────────────────────────────────
if (get('action') === 'delete_lead' && get('id')) {
    $db->prepare("DELETE FROM leads WHERE id=?")->execute([(int)get('id')]);
    $_SESSION['flash_success'] = 'Lead deleted.';
    header('Location: ' . APP_URL . '/modules/marketing/index.php?tab=leads'); exit;
}

// ── CONVERT LEAD TO CUSTOMER ─────────────────────────────────
if (get('action') === 'convert_lead' && get('id')) {
    $leadId = (int)get('id');
    $lead = $db->prepare("SELECT * FROM leads WHERE id=?"); $lead->execute([$leadId]); $lead = $lead->fetch();
    if ($lead && $lead['status'] !== 'converted') {
        $nameParts = explode(' ', trim($lead['name']), 2);
        $first = $nameParts[0] ?? $lead['name'];
        $last  = $nameParts[1] ?? '';
        $db->prepare("INSERT INTO contacts (type, first_name, last_name, email, phone, status, created_by) VALUES ('customer',?,?,?,?,'active',?)")
           ->execute([$first, $last, $lead['email'], $lead['phone'], $_SESSION['user_id']]);
        $newContactId = $db->lastInsertId();
        $db->prepare("UPDATE leads SET status='converted', converted_contact_id=? WHERE id=?")->execute([$newContactId, $leadId]);
        $_SESSION['flash_success'] = 'Lead converted to customer.';
    } else {
        $_SESSION['flash_error'] = 'Lead not found or already converted.';
    }
    header('Location: ' . APP_URL . '/modules/marketing/index.php?tab=leads'); exit;
}

// ── DELETE CAMPAIGN (guarded) ────────────────────────────────
if (get('action') === 'delete_campaign' && get('id')) {
    $cid = (int)get('id');
    $used = $db->prepare("SELECT
        (SELECT COUNT(*) FROM leads WHERE campaign_id=?) +
        (SELECT COUNT(*) FROM promo_codes WHERE campaign_id=?) +
        (SELECT COUNT(*) FROM content_calendar WHERE campaign_id=?) as cnt");
    $used->execute([$cid, $cid, $cid]);
    if ($used->fetchColumn() > 0) {
        $_SESSION['flash_error'] = 'Cannot delete — this campaign is still linked to leads, promo codes, or content. Remove those links first.';
    } else {
        $db->prepare("DELETE FROM campaigns WHERE id=?")->execute([$cid]);
        $_SESSION['flash_success'] = 'Campaign deleted.';
    }
    header('Location: ' . APP_URL . '/modules/marketing/index.php?tab=campaigns'); exit;
}

// ── DELETE PROMO CODE ────────────────────────────────────────
if (get('action') === 'delete_promo' && get('id')) {
    $db->prepare("DELETE FROM promo_codes WHERE id=?")->execute([(int)get('id')]);
    $_SESSION['flash_success'] = 'Promo code deleted.';
    header('Location: ' . APP_URL . '/modules/marketing/index.php?tab=promos'); exit;
}

// ── RECORD PROMO REDEMPTION ───────────────────────────────────
if (get('action') === 'redeem_promo' && get('id')) {
    $pid = (int)get('id');
    $promo = $db->prepare("SELECT * FROM promo_codes WHERE id=?"); $promo->execute([$pid]); $promo = $promo->fetch();
    if ($promo && ($promo['max_uses'] === null || $promo['used_count'] < $promo['max_uses'])) {
        $db->prepare("UPDATE promo_codes SET used_count = used_count + 1 WHERE id=?")->execute([$pid]);
        $_SESSION['flash_success'] = 'Redemption recorded.';
    } else {
        $_SESSION['flash_error'] = 'Promo code has reached its usage limit.';
    }
    header('Location: ' . APP_URL . '/modules/marketing/index.php?tab=promos'); exit;
}

// ── DELETE CONTENT ────────────────────────────────────────────
if (get('action') === 'delete_content' && get('id')) {
    $db->prepare("DELETE FROM content_calendar WHERE id=?")->execute([(int)get('id')]);
    $_SESSION['flash_success'] = 'Content item deleted.';
    header('Location: ' . APP_URL . '/modules/marketing/index.php?tab=content'); exit;
}

// ── SAVE LEAD ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'lead') {
    $editId = (int)post('edit_id');
    $data = [
        'name'        => trim(post('name')),
        'email'       => post('email'),
        'phone'       => post('phone'),
        'source'      => post('source', 'other'),
        'status'      => post('status', 'new'),
        'campaign_id' => (int)post('campaign_id') ?: null,
        'notes'       => post('notes'),
    ];
    if ($data['name'] === '') {
        $_SESSION['flash_error'] = 'Lead name is required.';
        header('Location: ' . APP_URL . '/modules/marketing/index.php?tab=leads'); exit;
    }
    if ($editId) {
        $set = implode(', ', array_map(fn($k) => "$k=?", array_keys($data)));
        $db->prepare("UPDATE leads SET $set WHERE id=?")->execute([...array_values($data), $editId]);
        $_SESSION['flash_success'] = 'Lead updated.';
    } else {
        $data['created_by'] = $_SESSION['user_id'];
        $cols = implode(',', array_keys($data)); $ph = implode(',', array_fill(0, count($data), '?'));
        $db->prepare("INSERT INTO leads ($cols) VALUES ($ph)")->execute(array_values($data));
        $_SESSION['flash_success'] = 'Lead added.';
    }
    header('Location: ' . APP_URL . '/modules/marketing/index.php?tab=leads'); exit;
}

// ── SAVE CAMPAIGN ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'campaign') {
    $editId = (int)post('edit_id');
    $data = [
        'name'        => trim(post('name')),
        'channel'     => post('channel', 'social_media'),
        'budget'      => (float)post('budget'),
        'start_date'  => post('start_date') ?: null,
        'end_date'    => post('end_date') ?: null,
        'status'      => post('status', 'planned'),
        'description' => post('description'),
    ];
    if ($data['name'] === '') {
        $_SESSION['flash_error'] = 'Campaign name is required.';
        header('Location: ' . APP_URL . '/modules/marketing/index.php?tab=campaigns'); exit;
    }
    if ($editId) {
        $set = implode(', ', array_map(fn($k) => "$k=?", array_keys($data)));
        $db->prepare("UPDATE campaigns SET $set WHERE id=?")->execute([...array_values($data), $editId]);
        $_SESSION['flash_success'] = 'Campaign updated.';
    } else {
        $data['created_by'] = $_SESSION['user_id'];
        $cols = implode(',', array_keys($data)); $ph = implode(',', array_fill(0, count($data), '?'));
        $db->prepare("INSERT INTO campaigns ($cols) VALUES ($ph)")->execute(array_values($data));
        $_SESSION['flash_success'] = 'Campaign created.';
    }
    header('Location: ' . APP_URL . '/modules/marketing/index.php?tab=campaigns'); exit;
}

// ── SAVE PROMO CODE ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'promo') {
    $editId = (int)post('edit_id');
    $data = [
        'code'           => strtoupper(trim(post('code'))),
        'campaign_id'    => (int)post('campaign_id') ?: null,
        'item_id'        => (int)post('item_id') ?: null,
        'discount_type'  => post('discount_type', 'percentage'),
        'discount_value' => (float)post('discount_value'),
        'max_uses'       => post('max_uses') !== '' ? (int)post('max_uses') : null,
        'start_date'     => post('start_date') ?: null,
        'end_date'       => post('end_date') ?: null,
        'status'         => post('status', 'active'),
    ];
    if ($data['code'] === '') {
        $_SESSION['flash_error'] = 'Promo code is required.';
        header('Location: ' . APP_URL . '/modules/marketing/index.php?tab=promos'); exit;
    }
    try {
        if ($editId) {
            $set = implode(', ', array_map(fn($k) => "$k=?", array_keys($data)));
            $db->prepare("UPDATE promo_codes SET $set WHERE id=?")->execute([...array_values($data), $editId]);
            $_SESSION['flash_success'] = 'Promo code updated.';
        } else {
            $data['created_by'] = $_SESSION['user_id'];
            $cols = implode(',', array_keys($data)); $ph = implode(',', array_fill(0, count($data), '?'));
            $db->prepare("INSERT INTO promo_codes ($cols) VALUES ($ph)")->execute(array_values($data));
            $_SESSION['flash_success'] = 'Promo code created.';
        }
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = str_contains($e->getMessage(), 'Duplicate') ? 'That promo code already exists.' : 'Could not save promo code.';
    }
    header('Location: ' . APP_URL . '/modules/marketing/index.php?tab=promos'); exit;
}

// ── SAVE CONTENT ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'content') {
    $editId = (int)post('edit_id');
    $data = [
        'title'          => trim(post('title')),
        'channel'        => post('channel', 'social_media'),
        'scheduled_date' => post('scheduled_date'),
        'status'         => post('status', 'planned'),
        'campaign_id'    => (int)post('campaign_id') ?: null,
        'notes'          => post('notes'),
    ];
    if ($data['title'] === '' || !$data['scheduled_date']) {
        $_SESSION['flash_error'] = 'Title and scheduled date are required.';
        header('Location: ' . APP_URL . '/modules/marketing/index.php?tab=content'); exit;
    }
    if ($editId) {
        $set = implode(', ', array_map(fn($k) => "$k=?", array_keys($data)));
        $db->prepare("UPDATE content_calendar SET $set WHERE id=?")->execute([...array_values($data), $editId]);
        $_SESSION['flash_success'] = 'Content item updated.';
    } else {
        $data['created_by'] = $_SESSION['user_id'];
        $cols = implode(',', array_keys($data)); $ph = implode(',', array_fill(0, count($data), '?'));
        $db->prepare("INSERT INTO content_calendar ($cols) VALUES ($ph)")->execute(array_values($data));
        $_SESSION['flash_success'] = 'Content item added.';
    }
    header('Location: ' . APP_URL . '/modules/marketing/index.php?tab=content'); exit;
}

// ── EMAIL BLAST (reuses the SMTP mailer built for staff leave notifications) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'email_blast') {
    $leadIds = post('lead_ids', []);
    if (!is_array($leadIds)) $leadIds = [];
    $subject = trim(post('blast_subject'));
    $message = trim(post('blast_message'));
    $sent = 0; $skipped = 0;
    if ($leadIds && $subject && $message) {
        $ph = implode(',', array_fill(0, count($leadIds), '?'));
        $recipients = $db->prepare("SELECT name, email FROM leads WHERE id IN ($ph)");
        $recipients->execute($leadIds);
        $bodyHtml = '<p>' . nl2br(htmlspecialchars($message)) . '</p>';
        foreach ($recipients->fetchAll() as $r) {
            if ($r['email'] && filter_var($r['email'], FILTER_VALIDATE_EMAIL)) {
                if (sendMail($r['email'], $r['name'], $subject, emailWrapper($subject, $bodyHtml))) $sent++;
                else $skipped++;
            } else $skipped++;
        }
        $_SESSION['flash_success'] = "Email sent to $sent recipient(s)." . ($skipped ? " $skipped skipped (no/invalid email)." : '');
    } else {
        $_SESSION['flash_error'] = 'Select at least one recipient and fill in subject & message.';
    }
    header('Location: ' . APP_URL . '/modules/marketing/index.php?tab=leads'); exit;
}

// ── FETCH DATA ─────────────────────────────────────────────────
$tab          = get('tab', 'overview');
$allCampaigns = $db->query("SELECT id, name FROM campaigns ORDER BY name")->fetchAll();
$allItems     = $db->query("SELECT id, name FROM items WHERE status='active' ORDER BY name")->fetchAll();

$editLead = null;
if (get('edit')) { $s = $db->prepare("SELECT * FROM leads WHERE id=?"); $s->execute([(int)get('edit')]); $editLead = $s->fetch(); }
$editCampaign = null;
if (get('edit_campaign')) { $s = $db->prepare("SELECT * FROM campaigns WHERE id=?"); $s->execute([(int)get('edit_campaign')]); $editCampaign = $s->fetch(); }
$editPromo = null;
if (get('edit_promo')) { $s = $db->prepare("SELECT * FROM promo_codes WHERE id=?"); $s->execute([(int)get('edit_promo')]); $editPromo = $s->fetch(); }
$editContent = null;
if (get('edit_content')) { $s = $db->prepare("SELECT * FROM content_calendar WHERE id=?"); $s->execute([(int)get('edit_content')]); $editContent = $s->fetch(); }

if ($tab === 'overview') {
    $newLeadsThisMonth = $db->query("SELECT COUNT(*) FROM leads WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();
    $totalLeads        = $db->query("SELECT COUNT(*) FROM leads")->fetchColumn();
    $convertedLeads    = $db->query("SELECT COUNT(*) FROM leads WHERE status='converted'")->fetchColumn();
    $conversionRate    = $totalLeads > 0 ? round($convertedLeads / $totalLeads * 100, 1) : 0;
    $activeCampaigns   = $db->query("SELECT COUNT(*) FROM campaigns WHERE status='active'")->fetchColumn();

    $leadsByStatus = $db->query("SELECT status, COUNT(*) as cnt FROM leads GROUP BY status")->fetchAll();
    $statusCounts  = array_column($leadsByStatus, 'cnt', 'status');

    $topCampaigns = $db->query("
        SELECT c.name,
               COUNT(l.id) as leads_count,
               SUM(CASE WHEN l.status='converted' THEN 1 ELSE 0 END) as converted_count
        FROM campaigns c LEFT JOIN leads l ON l.campaign_id = c.id
        GROUP BY c.id ORDER BY leads_count DESC LIMIT 5
    ")->fetchAll();

    $bestSellingItems = $db->query("
        SELECT it.name, SUM(ii.quantity) as qty_sold, SUM(ii.amount) as revenue
        FROM invoice_items ii
        JOIN invoices i ON ii.invoice_id = i.id
        JOIN items it ON ii.item_id = it.id
        WHERE i.status != 'cancelled'
        GROUP BY it.id ORDER BY qty_sold DESC LIMIT 5
    ")->fetchAll();

    $repeatStats = $db->query("
        SELECT COUNT(*) as total_customers,
               SUM(CASE WHEN invoice_count > 1 THEN 1 ELSE 0 END) as repeat_customers
        FROM (SELECT contact_id, COUNT(*) as invoice_count FROM invoices WHERE status != 'cancelled' GROUP BY contact_id) t
    ")->fetch();
    $repeatRate = ($repeatStats['total_customers'] ?? 0) > 0 ? round($repeatStats['repeat_customers'] / $repeatStats['total_customers'] * 100, 1) : 0;
}

if ($tab === 'leads') {
    $search         = get('search');
    $filterStatus   = get('status');
    $filterSource   = get('source');
    $filterCampaign = get('campaign');
    $where = ['1=1']; $params = [];
    if ($search)         { $where[] = "(l.name LIKE ? OR l.email LIKE ? OR l.phone LIKE ?)"; $s = "%$search%"; $params = array_merge($params, [$s, $s, $s]); }
    if ($filterStatus)   { $where[] = "l.status=?"; $params[] = $filterStatus; }
    if ($filterSource)   { $where[] = "l.source=?"; $params[] = $filterSource; }
    if ($filterCampaign) { $where[] = "l.campaign_id=?"; $params[] = $filterCampaign; }
    $whereSQL = implode(' AND ', $where);
    $page = max(1, (int)get('page', 1));
    $total = $db->prepare("SELECT COUNT(*) FROM leads l WHERE $whereSQL"); $total->execute($params);
    $pg = paginate((int)$total->fetchColumn(), $page);
    $stmt = $db->prepare("
        SELECT l.*, c.name as campaign_name, u.name as created_by_name
        FROM leads l
        LEFT JOIN campaigns c ON l.campaign_id = c.id
        LEFT JOIN users u ON l.created_by = u.id
        WHERE $whereSQL ORDER BY l.created_at DESC
        LIMIT {$pg['perPage']} OFFSET {$pg['offset']}
    ");
    $stmt->execute($params);
    $leads = $stmt->fetchAll();
}

if ($tab === 'campaigns') {
    $campaigns = $db->query("
        SELECT c.*, u.name as created_by_name,
          COUNT(DISTINCT l.id) as leads_count,
          SUM(CASE WHEN l.status='converted' THEN 1 ELSE 0 END) as converted_count,
          COALESCE(SUM(inv.total), 0) as revenue
        FROM campaigns c
        LEFT JOIN users u ON c.created_by = u.id
        LEFT JOIN leads l ON l.campaign_id = c.id
        LEFT JOIN (SELECT contact_id, SUM(total) as total FROM invoices WHERE status != 'cancelled' GROUP BY contact_id) inv
          ON inv.contact_id = l.converted_contact_id
        GROUP BY c.id ORDER BY c.created_at DESC
    ")->fetchAll();
}

if ($tab === 'promos') {
    $promos = $db->query("
        SELECT p.*, c.name as campaign_name, it.name as item_name, u.name as created_by_name
        FROM promo_codes p
        LEFT JOIN campaigns c ON p.campaign_id = c.id
        LEFT JOIN items it ON p.item_id = it.id
        LEFT JOIN users u ON p.created_by = u.id
        ORDER BY p.created_at DESC
    ")->fetchAll();
}

if ($tab === 'content') {
    $contentItems = $db->query("
        SELECT cc.*, c.name as campaign_name, u.name as created_by_name
        FROM content_calendar cc
        LEFT JOIN campaigns c ON cc.campaign_id = c.id
        LEFT JOIN users u ON cc.created_by = u.id
        ORDER BY cc.scheduled_date ASC
    ")->fetchAll();
}

include __DIR__ . '/../../includes/header.php';
?>

<!-- Tabs -->
<div class="flex gap-1 mb-6 border-b border-gray-200 flex-wrap">
  <a href="?tab=overview" class="px-5 py-2.5 text-sm font-medium border-b-2 transition-colors <?= $tab==='overview'?'border-brand text-brand':'border-transparent text-gray-500 hover:text-gray-700' ?>">
    <i data-lucide="layout-dashboard" class="w-4 h-4 inline mr-1.5"></i>Overview
  </a>
  <a href="?tab=leads" class="px-5 py-2.5 text-sm font-medium border-b-2 transition-colors <?= $tab==='leads'?'border-brand text-brand':'border-transparent text-gray-500 hover:text-gray-700' ?>">
    <i data-lucide="user-plus" class="w-4 h-4 inline mr-1.5"></i>Leads
  </a>
  <a href="?tab=campaigns" class="px-5 py-2.5 text-sm font-medium border-b-2 transition-colors <?= $tab==='campaigns'?'border-brand text-brand':'border-transparent text-gray-500 hover:text-gray-700' ?>">
    <i data-lucide="megaphone" class="w-4 h-4 inline mr-1.5"></i>Campaigns
  </a>
  <a href="?tab=promos" class="px-5 py-2.5 text-sm font-medium border-b-2 transition-colors <?= $tab==='promos'?'border-brand text-brand':'border-transparent text-gray-500 hover:text-gray-700' ?>">
    <i data-lucide="ticket" class="w-4 h-4 inline mr-1.5"></i>Promo Codes
  </a>
  <a href="?tab=content" class="px-5 py-2.5 text-sm font-medium border-b-2 transition-colors <?= $tab==='content'?'border-brand text-brand':'border-transparent text-gray-500 hover:text-gray-700' ?>">
    <i data-lucide="calendar-days" class="w-4 h-4 inline mr-1.5"></i>Content Calendar
  </a>
  <a href="?tab=reports" class="px-5 py-2.5 text-sm font-medium border-b-2 transition-colors <?= $tab==='reports'?'border-brand text-brand':'border-transparent text-gray-500 hover:text-gray-700' ?>">
    <i data-lucide="bar-chart-2" class="w-4 h-4 inline mr-1.5"></i>Reports
  </a>
</div>

<?php if ($tab === 'overview'): ?>
<!-- ══════════════════════════════════════════════════════════
     OVERVIEW TAB
════════════════════════════════════════════════════════════ -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
  <?php $ovStats = [
    ['New Leads (This Month)', $newLeadsThisMonth, 'user-plus',   'blue'],
    ['Total Leads',            $totalLeads,        'users',       'purple'],
    ['Conversion Rate',        $conversionRate.'%','trending-up', 'green'],
    ['Active Campaigns',       $activeCampaigns,   'megaphone',   'orange'],
  ]; foreach ($ovStats as [$label, $val, $icon, $col]): ?>
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

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
  <!-- Leads Pipeline -->
  <div class="card p-5">
    <h3 class="text-sm font-semibold text-gray-800 mb-4">Leads Pipeline</h3>
    <div class="space-y-3">
      <?php foreach (['new'=>'New','contacted'=>'Contacted','qualified'=>'Qualified','converted'=>'Converted','lost'=>'Lost'] as $k => $lbl):
        $cnt = $statusCounts[$k] ?? 0;
        $pct = $totalLeads > 0 ? round($cnt / $totalLeads * 100) : 0;
      ?>
      <div>
        <div class="flex items-center justify-between text-xs mb-1">
          <span class="text-gray-600 font-medium"><?= $lbl ?></span>
          <span class="text-gray-400"><?= $cnt ?> (<?= $pct ?>%)</span>
        </div>
        <div class="w-full bg-gray-100 rounded-full h-2"><div class="h-2 rounded-full" style="width:<?= $pct ?>%;background:var(--brand)"></div></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Top Campaigns -->
  <div class="card p-5">
    <h3 class="text-sm font-semibold text-gray-800 mb-4">Top Campaigns by Leads</h3>
    <?php $hasCampaignData = array_filter(array_column($topCampaigns, 'leads_count')); ?>
    <?php if ($hasCampaignData): ?>
    <div class="space-y-3">
      <?php foreach ($topCampaigns as $c): if (!$c['leads_count']) continue; ?>
      <div class="flex items-center justify-between gap-3">
        <div class="min-w-0">
          <p class="text-xs font-medium text-gray-800 truncate"><?= clean($c['name']) ?></p>
          <p class="text-xs text-gray-400"><?= $c['converted_count'] ?> converted</p>
        </div>
        <span class="text-xs font-semibold text-brand whitespace-nowrap"><?= $c['leads_count'] ?> leads</span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="text-center py-8 text-gray-400"><i data-lucide="megaphone" class="w-8 h-8 mx-auto mb-2 opacity-40"></i><p class="text-xs">No campaign data yet</p></div>
    <?php endif; ?>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
  <!-- Best Selling Items -->
  <div class="card p-5">
    <h3 class="text-sm font-semibold text-gray-800 mb-4">Best-Selling Items</h3>
    <?php if ($bestSellingItems): ?>
    <table class="w-full text-sm">
      <thead><tr class="text-xs text-gray-400 border-b"><th class="pb-2 text-left">Item</th><th class="pb-2 text-right">Qty Sold</th><th class="pb-2 text-right">Revenue</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php foreach ($bestSellingItems as $item): ?>
        <tr>
          <td class="py-2 text-xs font-medium text-gray-700"><?= clean($item['name']) ?></td>
          <td class="py-2 text-xs text-right"><?= number_format($item['qty_sold'], 0) ?></td>
          <td class="py-2 text-xs text-right font-medium"><?= formatCurrency($item['revenue']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="text-center py-8 text-gray-400"><i data-lucide="package" class="w-8 h-8 mx-auto mb-2 opacity-40"></i><p class="text-xs">No sales data yet</p></div>
    <?php endif; ?>
  </div>

  <!-- Repeat Customer Rate -->
  <div class="card p-5 flex flex-col items-center justify-center text-center">
    <h3 class="text-sm font-semibold text-gray-800 mb-4 self-start">Repeat Customer Rate</h3>
    <div class="w-28 h-28 rounded-full flex items-center justify-center mb-3" style="background:var(--brand-light)">
      <span class="text-2xl font-bold" style="color:var(--brand)"><?= $repeatRate ?>%</span>
    </div>
    <p class="text-xs text-gray-400"><?= $repeatStats['repeat_customers'] ?? 0 ?> of <?= $repeatStats['total_customers'] ?? 0 ?> customers have ordered more than once</p>
  </div>
</div>

<?php elseif ($tab === 'leads'): ?>
<!-- ══════════════════════════════════════════════════════════
     LEADS TAB
════════════════════════════════════════════════════════════ -->
<div class="flex items-center justify-between gap-3 mb-4 flex-wrap">
  <div class="flex gap-2 flex-wrap">
    <div class="relative">
      <input type="text" placeholder="Search leads..." value="<?= clean($search) ?>" class="form-input pl-9 py-2 text-sm w-52" onchange="location='?tab=leads&search='+encodeURIComponent(this.value)">
      <i data-lucide="search" class="w-4 h-4 text-gray-400 absolute left-2.5 top-2.5"></i>
    </div>
    <select onchange="location='?tab=leads&status='+this.value" class="form-input py-2 text-sm">
      <option value="">All Status</option>
      <?php foreach (['new'=>'New','contacted'=>'Contacted','qualified'=>'Qualified','converted'=>'Converted','lost'=>'Lost'] as $k=>$v): ?>
      <option value="<?=$k?>" <?= $filterStatus===$k?'selected':'' ?>><?=$v?></option>
      <?php endforeach; ?>
    </select>
    <select onchange="location='?tab=leads&source='+this.value" class="form-input py-2 text-sm">
      <option value="">All Sources</option>
      <?php foreach (['walk_in'=>'Walk-in','referral'=>'Referral','social_media'=>'Social Media','website'=>'Website','ad'=>'Ad','cold_call'=>'Cold Call','other'=>'Other'] as $k=>$v): ?>
      <option value="<?=$k?>" <?= $filterSource===$k?'selected':'' ?>><?=$v?></option>
      <?php endforeach; ?>
    </select>
    <select onchange="location='?tab=leads&campaign='+this.value" class="form-input py-2 text-sm">
      <option value="">All Campaigns</option>
      <?php foreach ($allCampaigns as $c): ?>
      <option value="<?=$c['id']?>" <?= $filterCampaign==$c['id']?'selected':'' ?>><?= clean($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="flex gap-2">
    <button type="button" id="sendEmailBtn" onclick="openEmailBlastModal()" class="btn-secondary" disabled>
      <i data-lucide="mail" class="w-4 h-4"></i> Send Email (<span id="selectedCount">0</span>)
    </button>
    <button onclick="openModal('leadModal')" class="btn-primary"><i data-lucide="user-plus" class="w-4 h-4"></i> New Lead</button>
  </div>
</div>

<div class="card overflow-hidden">
  <table class="w-full">
    <thead class="bg-gray-50 border-b border-gray-100">
      <tr class="text-xs text-gray-500 font-medium">
        <th class="px-4 py-3 text-left"><input type="checkbox" id="selectAllLeads" onchange="toggleAllLeads(this)"></th>
        <th class="px-4 py-3 text-left">Name</th>
        <th class="px-4 py-3 text-left">Contact</th>
        <th class="px-4 py-3 text-left">Source</th>
        <th class="px-4 py-3 text-left">Campaign</th>
        <th class="px-4 py-3 text-center">Status</th>
        <th class="px-4 py-3 text-left">Created</th>
        <th class="px-4 py-3 text-left">Logged By</th>
        <th class="px-4 py-3 text-center">Actions</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-50">
      <?php foreach ($leads as $lead): ?>
      <tr class="table-row">
        <td class="px-4 py-3"><input type="checkbox" class="lead-check" value="<?= $lead['id'] ?>" data-email="<?= clean($lead['email']) ?>" onchange="updateEmailBtn()"></td>
        <td class="px-4 py-3 text-sm font-medium text-gray-800"><?= clean($lead['name']) ?></td>
        <td class="px-4 py-3 text-xs text-gray-500"><?= clean($lead['email'] ?: '-') ?><br><span class="text-gray-400"><?= clean($lead['phone'] ?: '') ?></span></td>
        <td class="px-4 py-3 text-xs text-gray-500 capitalize"><?= str_replace('_',' ',$lead['source']) ?></td>
        <td class="px-4 py-3 text-xs text-gray-500"><?= clean($lead['campaign_name'] ?? '—') ?></td>
        <td class="px-4 py-3 text-center"><?= statusBadge($lead['status']) ?></td>
        <td class="px-4 py-3 text-xs text-gray-400"><?= formatDate($lead['created_at']) ?></td>
        <td class="px-4 py-3 text-xs text-gray-500"><?= clean($lead['created_by_name'] ?? 'System') ?></td>
        <td class="px-4 py-3 text-center">
          <div class="flex items-center justify-center gap-1">
            <a href="?tab=leads&edit=<?= $lead['id'] ?>" class="p-1 rounded hover:bg-yellow-50 text-yellow-500" title="Edit"><i data-lucide="pencil" class="w-3.5 h-3.5"></i></a>
            <?php if ($lead['status'] !== 'converted'): ?>
            <button onclick="confirmDelete('?action=convert_lead&id=<?= $lead['id'] ?>','Convert this lead to a customer contact?')" class="p-1 rounded hover:bg-green-50 text-green-600" title="Convert to Customer"><i data-lucide="user-check" class="w-3.5 h-3.5"></i></button>
            <?php endif; ?>
            <button onclick="confirmDelete('?action=delete_lead&id=<?= $lead['id'] ?>','Delete this lead? This cannot be undone.')" class="p-1 rounded hover:bg-red-50 text-red-400" title="Delete"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$leads): ?>
      <tr><td colspan="9" class="px-4 py-12 text-center text-sm text-gray-400"><i data-lucide="users" class="w-10 h-10 mx-auto mb-2 opacity-30"></i><p>No leads found.</p></td></tr>
      <?php endif; ?>
    </tbody>
  </table>
  <?php if ($pg['totalPages'] > 1): ?>
  <div class="px-4 py-3 border-t border-gray-100 flex items-center justify-between">
    <p class="text-xs text-gray-500"><?= count($leads) ?> of <?= $pg['total'] ?> leads</p>
    <div class="flex gap-1">
      <?php for ($p = 1; $p <= $pg['totalPages']; $p++): ?>
      <a href="?tab=leads&page=<?= $p ?>" class="px-3 py-1 text-xs rounded <?= $p===$pg['page']?'bg-brand text-white':'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'campaigns'): ?>
<!-- ══════════════════════════════════════════════════════════
     CAMPAIGNS TAB
════════════════════════════════════════════════════════════ -->
<div class="flex justify-end mb-4">
  <button onclick="openModal('campaignModal')" class="btn-primary"><i data-lucide="megaphone" class="w-4 h-4"></i> New Campaign</button>
</div>
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
  <?php foreach ($campaigns as $camp): ?>
  <div class="card p-5">
    <div class="flex items-start justify-between mb-3 gap-2">
      <div class="min-w-0">
        <h3 class="font-semibold text-gray-800 truncate"><?= clean($camp['name']) ?></h3>
        <p class="text-xs text-gray-400 capitalize"><?= str_replace('_',' ',$camp['channel']) ?></p>
      </div>
      <?= statusBadge($camp['status']) ?>
    </div>
    <div class="grid grid-cols-2 gap-2 text-xs text-gray-500 mb-3">
      <div>Budget<br><span class="font-semibold text-gray-800"><?= formatCurrency($camp['budget']) ?></span></div>
      <div>Duration<br><span class="font-semibold text-gray-800"><?= $camp['start_date'] ? formatDate($camp['start_date']) : '—' ?> – <?= $camp['end_date'] ? formatDate($camp['end_date']) : '—' ?></span></div>
      <div>Leads<br><span class="font-semibold text-gray-800"><?= $camp['leads_count'] ?></span></div>
      <div>Converted<br><span class="font-semibold text-green-600"><?= $camp['converted_count'] ?></span></div>
    </div>
    <div class="text-xs text-gray-500 mb-3">Revenue Attributed<br><span class="font-semibold text-sm text-brand"><?= formatCurrency($camp['revenue']) ?></span></div>
    <?php if ($camp['description']): ?><p class="text-xs text-gray-400 mb-3 line-clamp-2"><?= clean($camp['description']) ?></p><?php endif; ?>
    <p class="text-xs text-gray-400 mb-3 flex items-center gap-1"><i data-lucide="user" class="w-3 h-3"></i>Logged by <span class="font-medium text-gray-500"><?= clean($camp['created_by_name'] ?? 'System') ?></span></p>
    <div class="flex items-center justify-end gap-1 border-t pt-3">
      <a href="?tab=campaigns&edit_campaign=<?= $camp['id'] ?>" class="p-1.5 rounded hover:bg-yellow-50 text-yellow-500" title="Edit"><i data-lucide="pencil" class="w-3.5 h-3.5"></i></a>
      <button onclick="confirmDelete('?action=delete_campaign&id=<?= $camp['id'] ?>','Delete this campaign?')" class="p-1.5 rounded hover:bg-red-50 text-red-400" title="Delete"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (!$campaigns): ?>
  <div class="col-span-3 card p-12 text-center text-gray-400"><i data-lucide="megaphone" class="w-10 h-10 mx-auto mb-2 opacity-30"></i><p>No campaigns yet.</p></div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'promos'): ?>
<!-- ══════════════════════════════════════════════════════════
     PROMO CODES TAB
════════════════════════════════════════════════════════════ -->
<div class="flex justify-end mb-4">
  <button onclick="openModal('promoModal')" class="btn-primary"><i data-lucide="ticket" class="w-4 h-4"></i> New Promo Code</button>
</div>
<div class="card overflow-hidden">
  <table class="w-full">
    <thead class="bg-gray-50 border-b border-gray-100">
      <tr class="text-xs text-gray-500 font-medium">
        <th class="px-4 py-3 text-left">Code</th>
        <th class="px-4 py-3 text-left">Campaign</th>
        <th class="px-4 py-3 text-left">Applies To</th>
        <th class="px-4 py-3 text-left">Discount</th>
        <th class="px-4 py-3 text-center">Usage</th>
        <th class="px-4 py-3 text-left">Valid</th>
        <th class="px-4 py-3 text-center">Status</th>
        <th class="px-4 py-3 text-left">Logged By</th>
        <th class="px-4 py-3 text-center">Actions</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-50">
      <?php foreach ($promos as $promo): ?>
      <tr class="table-row">
        <td class="px-4 py-3 text-sm font-mono font-semibold text-brand"><?= clean($promo['code']) ?></td>
        <td class="px-4 py-3 text-xs text-gray-500"><?= clean($promo['campaign_name'] ?? '—') ?></td>
        <td class="px-4 py-3 text-xs text-gray-500"><?= clean($promo['item_name'] ?? 'All Items') ?></td>
        <td class="px-4 py-3 text-xs text-gray-700"><?= $promo['discount_type']==='percentage' ? $promo['discount_value'].'%' : formatCurrency($promo['discount_value']) ?></td>
        <td class="px-4 py-3 text-xs text-center"><?= $promo['used_count'] ?> / <?= $promo['max_uses'] ?? '∞' ?></td>
        <td class="px-4 py-3 text-xs text-gray-400"><?= $promo['start_date'] ? formatDate($promo['start_date']) : '—' ?> – <?= $promo['end_date'] ? formatDate($promo['end_date']) : '—' ?></td>
        <td class="px-4 py-3 text-center"><?= statusBadge($promo['status']) ?></td>
        <td class="px-4 py-3 text-xs text-gray-500"><?= clean($promo['created_by_name'] ?? 'System') ?></td>
        <td class="px-4 py-3 text-center">
          <div class="flex items-center justify-center gap-1">
            <button onclick="confirmDelete('?action=redeem_promo&id=<?= $promo['id'] ?>','Record one redemption for &quot;<?= clean($promo['code']) ?>&quot;?')" class="p-1 rounded hover:bg-green-50 text-green-600" title="Record Redemption"><i data-lucide="check-circle" class="w-3.5 h-3.5"></i></button>
            <a href="?tab=promos&edit_promo=<?= $promo['id'] ?>" class="p-1 rounded hover:bg-yellow-50 text-yellow-500" title="Edit"><i data-lucide="pencil" class="w-3.5 h-3.5"></i></a>
            <button onclick="confirmDelete('?action=delete_promo&id=<?= $promo['id'] ?>','Delete this promo code?')" class="p-1 rounded hover:bg-red-50 text-red-400" title="Delete"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$promos): ?><tr><td colspan="9" class="px-4 py-12 text-center text-sm text-gray-400"><i data-lucide="ticket" class="w-10 h-10 mx-auto mb-2 opacity-30"></i><p>No promo codes yet.</p></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php elseif ($tab === 'content'): ?>
<!-- ══════════════════════════════════════════════════════════
     CONTENT CALENDAR TAB
════════════════════════════════════════════════════════════ -->
<div class="flex justify-end mb-4">
  <button onclick="openModal('contentModal')" class="btn-primary"><i data-lucide="calendar-plus" class="w-4 h-4"></i> New Content</button>
</div>
<div class="card overflow-hidden">
  <table class="w-full">
    <thead class="bg-gray-50 border-b border-gray-100">
      <tr class="text-xs text-gray-500 font-medium">
        <th class="px-4 py-3 text-left">Date</th>
        <th class="px-4 py-3 text-left">Title</th>
        <th class="px-4 py-3 text-left">Channel</th>
        <th class="px-4 py-3 text-left">Campaign</th>
        <th class="px-4 py-3 text-center">Status</th>
        <th class="px-4 py-3 text-left">Logged By</th>
        <th class="px-4 py-3 text-center">Actions</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-50">
      <?php foreach ($contentItems as $item):
        $isOverdue = strtotime($item['scheduled_date']) < strtotime(date('Y-m-d')) && $item['status'] === 'planned';
      ?>
      <tr class="table-row <?= $isOverdue ? 'bg-red-50' : '' ?>">
        <td class="px-4 py-3 text-xs font-medium <?= $isOverdue ? 'text-red-600' : 'text-gray-700' ?>"><?= formatDate($item['scheduled_date']) ?></td>
        <td class="px-4 py-3 text-sm text-gray-800"><?= clean($item['title']) ?></td>
        <td class="px-4 py-3 text-xs text-gray-500 capitalize"><?= str_replace('_',' ',$item['channel']) ?></td>
        <td class="px-4 py-3 text-xs text-gray-500"><?= clean($item['campaign_name'] ?? '—') ?></td>
        <td class="px-4 py-3 text-center"><?= statusBadge($item['status']) ?></td>
        <td class="px-4 py-3 text-xs text-gray-500"><?= clean($item['created_by_name'] ?? 'System') ?></td>
        <td class="px-4 py-3 text-center">
          <div class="flex items-center justify-center gap-1">
            <a href="?tab=content&edit_content=<?= $item['id'] ?>" class="p-1 rounded hover:bg-yellow-50 text-yellow-500" title="Edit"><i data-lucide="pencil" class="w-3.5 h-3.5"></i></a>
            <button onclick="confirmDelete('?action=delete_content&id=<?= $item['id'] ?>','Delete this content item?')" class="p-1 rounded hover:bg-red-50 text-red-400" title="Delete"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$contentItems): ?><tr><td colspan="7" class="px-4 py-12 text-center text-sm text-gray-400"><i data-lucide="calendar" class="w-10 h-10 mx-auto mb-2 opacity-30"></i><p>No content scheduled yet.</p></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php elseif ($tab === 'reports'):
$mktReport = get('report', 'sources');
$rDateFrom = get('date_from', date('Y-01-01'));
$rDateTo   = get('date_to', date('Y-m-d'));
?>
<!-- ══════════════════════════════════════════════════════════
     MARKETING REPORTS TAB
════════════════════════════════════════════════════════════ -->
<div class="flex gap-2 mb-6 flex-wrap">
  <?php $mktReports = [
    'sources'   => ['Lead Sources',        'compass'],
    'campaigns' => ['Campaign Performance','megaphone'],
    'funnel'    => ['Conversion Funnel',   'filter'],
    'promos'    => ['Promo Performance',   'ticket'],
  ];
  foreach ($mktReports as $key => [$label, $icon]): ?>
  <a href="?tab=reports&report=<?=$key?>&date_from=<?=$rDateFrom?>&date_to=<?=$rDateTo?>"
     class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-all
            <?= $mktReport===$key ? 'bg-blue-600 text-white shadow' : 'bg-white text-gray-600 hover:bg-gray-100 border border-gray-200' ?>">
    <i data-lucide="<?=$icon?>" class="w-4 h-4"></i><?= $label ?>
  </a>
  <?php endforeach; ?>
</div>

<?php if ($mktReport !== 'promos'): ?>
<form method="GET" class="card p-4 mb-6 flex items-center gap-4 flex-wrap">
  <input type="hidden" name="tab" value="reports">
  <input type="hidden" name="report" value="<?= clean($mktReport) ?>">
  <div class="flex items-center gap-2">
    <label class="text-sm text-gray-500 whitespace-nowrap">From</label>
    <input type="date" name="date_from" class="form-input py-2 text-sm" value="<?= $rDateFrom ?>">
  </div>
  <div class="flex items-center gap-2">
    <label class="text-sm text-gray-500 whitespace-nowrap">To</label>
    <input type="date" name="date_to" class="form-input py-2 text-sm" value="<?= $rDateTo ?>">
  </div>
  <button type="submit" class="btn-primary text-sm">Apply</button>
  <button type="button" onclick="window.print()" class="btn-secondary text-sm"><i data-lucide="printer" class="w-4 h-4"></i> Print</button>
</form>
<?php else: ?>
<div class="flex justify-end mb-6"><button type="button" onclick="window.print()" class="btn-secondary text-sm"><i data-lucide="printer" class="w-4 h-4"></i> Print</button></div>
<?php endif; ?>

<?php if ($mktReport === 'sources'):
  $sourceRows = $db->prepare("
    SELECT l.source,
           COUNT(*) as total_leads,
           SUM(CASE WHEN l.status='converted' THEN 1 ELSE 0 END) as converted,
           COALESCE(SUM(inv.total), 0) as revenue
    FROM leads l
    LEFT JOIN (SELECT contact_id, SUM(total) as total FROM invoices WHERE status != 'cancelled' GROUP BY contact_id) inv
      ON inv.contact_id = l.converted_contact_id
    WHERE l.created_at BETWEEN ? AND ?
    GROUP BY l.source ORDER BY total_leads DESC
  ");
  $sourceRows->execute([$rDateFrom, $rDateTo . ' 23:59:59']);
  $sourceRows = $sourceRows->fetchAll();
  $sourceLabels = ['walk_in'=>'Walk-in','referral'=>'Referral','social_media'=>'Social Media','website'=>'Website','ad'=>'Ad','cold_call'=>'Cold Call','other'=>'Other'];
?>
<div class="card overflow-hidden print:shadow-none">
  <div class="px-6 py-4 border-b bg-gray-50">
    <h2 class="text-base font-semibold">Lead Source Performance</h2>
    <p class="text-xs text-gray-400"><?= formatDate($rDateFrom) ?> — <?= formatDate($rDateTo) ?></p>
  </div>
  <table class="w-full">
    <thead class="bg-gray-50 border-b"><tr class="text-xs text-gray-500 font-medium">
      <th class="px-6 py-3 text-left">Source</th>
      <th class="px-6 py-3 text-center">Total Leads</th>
      <th class="px-6 py-3 text-center">Converted</th>
      <th class="px-6 py-3 text-center">Conversion Rate</th>
      <th class="px-6 py-3 text-right">Revenue Generated</th>
    </tr></thead>
    <tbody class="divide-y divide-gray-50">
      <?php foreach ($sourceRows as $row): $rate = $row['total_leads']>0 ? round($row['converted']/$row['total_leads']*100,1) : 0; ?>
      <tr class="table-row">
        <td class="px-6 py-3 text-sm font-medium capitalize"><?= clean($sourceLabels[$row['source']] ?? $row['source']) ?></td>
        <td class="px-6 py-3 text-center text-sm"><?= $row['total_leads'] ?></td>
        <td class="px-6 py-3 text-center text-sm text-green-600 font-medium"><?= $row['converted'] ?></td>
        <td class="px-6 py-3 text-center">
          <div class="flex items-center justify-center gap-2">
            <div class="w-16 bg-gray-100 rounded-full h-1.5"><div class="h-1.5 rounded-full" style="width:<?=$rate?>%;background:var(--brand)"></div></div>
            <span class="text-xs text-gray-500"><?=$rate?>%</span>
          </div>
        </td>
        <td class="px-6 py-3 text-right text-sm font-medium"><?= formatCurrency($row['revenue']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$sourceRows): ?><tr><td colspan="5" class="px-6 py-8 text-center text-gray-400 text-xs">No leads in this period.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php elseif ($mktReport === 'campaigns'):
  $campRows = $db->query("
    SELECT c.name, c.channel, c.budget, c.status,
           COUNT(DISTINCT l.id) as leads_count,
           SUM(CASE WHEN l.status='converted' THEN 1 ELSE 0 END) as converted_count,
           COALESCE(SUM(inv.total), 0) as revenue
    FROM campaigns c
    LEFT JOIN leads l ON l.campaign_id = c.id
    LEFT JOIN (SELECT contact_id, SUM(total) as total FROM invoices WHERE status != 'cancelled' GROUP BY contact_id) inv
      ON inv.contact_id = l.converted_contact_id
    GROUP BY c.id ORDER BY revenue DESC
  ")->fetchAll();
?>
<div class="card overflow-hidden print:shadow-none">
  <div class="px-6 py-4 border-b bg-gray-50">
    <h2 class="text-base font-semibold">Campaign Performance</h2>
    <p class="text-xs text-gray-400">Budget vs. revenue attributed, all campaigns</p>
  </div>
  <table class="w-full">
    <thead class="bg-gray-50 border-b"><tr class="text-xs text-gray-500 font-medium">
      <th class="px-6 py-3 text-left">Campaign</th>
      <th class="px-6 py-3 text-left">Channel</th>
      <th class="px-6 py-3 text-right">Budget</th>
      <th class="px-6 py-3 text-center">Leads</th>
      <th class="px-6 py-3 text-center">Converted</th>
      <th class="px-6 py-3 text-right">Cost / Lead</th>
      <th class="px-6 py-3 text-right">Revenue</th>
      <th class="px-6 py-3 text-right">ROI</th>
    </tr></thead>
    <tbody class="divide-y divide-gray-50">
      <?php foreach ($campRows as $row):
        $costPerLead = $row['leads_count']>0 ? $row['budget']/$row['leads_count'] : 0;
        $roi = $row['budget']>0 ? round((($row['revenue']-$row['budget'])/$row['budget'])*100,1) : null;
      ?>
      <tr class="table-row">
        <td class="px-6 py-3 text-sm font-medium"><?= clean($row['name']) ?></td>
        <td class="px-6 py-3 text-xs text-gray-500 capitalize"><?= str_replace('_',' ',$row['channel']) ?></td>
        <td class="px-6 py-3 text-right text-sm"><?= formatCurrency($row['budget']) ?></td>
        <td class="px-6 py-3 text-center text-sm"><?= $row['leads_count'] ?></td>
        <td class="px-6 py-3 text-center text-sm text-green-600 font-medium"><?= $row['converted_count'] ?></td>
        <td class="px-6 py-3 text-right text-sm"><?= formatCurrency($costPerLead) ?></td>
        <td class="px-6 py-3 text-right text-sm font-medium"><?= formatCurrency($row['revenue']) ?></td>
        <td class="px-6 py-3 text-right text-sm font-semibold <?= $roi===null?'text-gray-300':($roi>=0?'text-green-600':'text-red-600') ?>">
          <?= $roi===null ? 'n/a' : $roi.'%' ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$campRows): ?><tr><td colspan="8" class="px-6 py-8 text-center text-gray-400 text-xs">No campaigns yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php elseif ($mktReport === 'funnel'):
  $funnelRows = $db->prepare("SELECT status, COUNT(*) as cnt FROM leads WHERE created_at BETWEEN ? AND ? GROUP BY status");
  $funnelRows->execute([$rDateFrom, $rDateTo . ' 23:59:59']);
  $funnelCounts = array_column($funnelRows->fetchAll(), 'cnt', 'status');
  $funnelTotal  = array_sum($funnelCounts);
?>
<div class="card p-6 print:shadow-none max-w-2xl">
  <h2 class="text-base font-semibold mb-1">Lead Conversion Funnel</h2>
  <p class="text-xs text-gray-400 mb-6"><?= formatDate($rDateFrom) ?> — <?= formatDate($rDateTo) ?></p>
  <div class="space-y-4">
    <?php foreach (['new'=>'New','contacted'=>'Contacted','qualified'=>'Qualified','converted'=>'Converted','lost'=>'Lost'] as $k => $lbl):
      $cnt = $funnelCounts[$k] ?? 0;
      $pct = $funnelTotal > 0 ? round($cnt / $funnelTotal * 100) : 0;
    ?>
    <div>
      <div class="flex items-center justify-between text-sm mb-1.5">
        <span class="font-medium text-gray-700"><?= $lbl ?></span>
        <span class="text-gray-500"><?= $cnt ?> lead<?= $cnt!==1?'s':'' ?> (<?= $pct ?>%)</span>
      </div>
      <div class="w-full bg-gray-100 rounded-full h-3"><div class="h-3 rounded-full" style="width:<?= $pct ?>%;background:var(--brand)"></div></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php if (!$funnelTotal): ?><p class="text-center text-gray-400 text-xs mt-6">No leads in this period.</p><?php endif; ?>
</div>

<?php elseif ($mktReport === 'promos'):
  $promoRows = $db->query("
    SELECT p.*, c.name as campaign_name
    FROM promo_codes p LEFT JOIN campaigns c ON p.campaign_id = c.id
    ORDER BY p.used_count DESC
  ")->fetchAll();
?>
<div class="card overflow-hidden print:shadow-none">
  <div class="px-6 py-4 border-b bg-gray-50">
    <h2 class="text-base font-semibold">Promo Code Performance</h2>
    <p class="text-xs text-gray-400">All promo codes, ranked by redemptions</p>
  </div>
  <table class="w-full">
    <thead class="bg-gray-50 border-b"><tr class="text-xs text-gray-500 font-medium">
      <th class="px-6 py-3 text-left">Code</th>
      <th class="px-6 py-3 text-left">Campaign</th>
      <th class="px-6 py-3 text-left">Discount</th>
      <th class="px-6 py-3 text-center">Redemptions</th>
      <th class="px-6 py-3 text-center">Usage Rate</th>
      <th class="px-6 py-3 text-center">Status</th>
    </tr></thead>
    <tbody class="divide-y divide-gray-50">
      <?php foreach ($promoRows as $row):
        $usageRate = $row['max_uses'] ? round($row['used_count']/$row['max_uses']*100,1) : null;
      ?>
      <tr class="table-row">
        <td class="px-6 py-3 text-sm font-mono font-semibold text-brand"><?= clean($row['code']) ?></td>
        <td class="px-6 py-3 text-xs text-gray-500"><?= clean($row['campaign_name'] ?? '—') ?></td>
        <td class="px-6 py-3 text-xs text-gray-700"><?= $row['discount_type']==='percentage' ? $row['discount_value'].'%' : formatCurrency($row['discount_value']) ?></td>
        <td class="px-6 py-3 text-center text-sm font-medium"><?= $row['used_count'] ?> <?= $row['max_uses']?'/ '.$row['max_uses']:'' ?></td>
        <td class="px-6 py-3 text-center text-xs text-gray-500"><?= $usageRate===null ? '—' : $usageRate.'%' ?></td>
        <td class="px-6 py-3 text-center"><?= statusBadge($row['status']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$promoRows): ?><tr><td colspan="6" class="px-6 py-8 text-center text-gray-400 text-xs">No promo codes yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php endif; ?>


<!-- ══════════════════════════════════════════════════════════
     LEAD MODAL
════════════════════════════════════════════════════════════ -->
<div id="leadModal" class="modal-overlay <?= $editLead?'':'hidden' ?>">
<div class="modal-box max-w-lg">
  <div class="flex items-center justify-between px-6 py-4 border-b">
    <h2 class="text-base font-semibold"><?= $editLead?'Edit Lead':'New Lead' ?></h2>
    <button onclick="closeModal('leadModal')" class="text-gray-400"><i data-lucide="x" class="w-5 h-5"></i></button>
  </div>
  <form method="POST" class="p-6">
    <input type="hidden" name="form" value="lead">
    <?php if ($editLead): ?><input type="hidden" name="edit_id" value="<?= $editLead['id'] ?>"><?php endif; ?>
    <div class="grid grid-cols-2 gap-4">
      <div class="col-span-2"><label class="form-label">Name *</label><input type="text" name="name" required class="form-input" value="<?= clean($editLead['name']??'') ?>"></div>
      <div><label class="form-label">Email</label><input type="email" name="email" class="form-input" value="<?= clean($editLead['email']??'') ?>"></div>
      <div><label class="form-label">Phone</label><input type="text" name="phone" class="form-input" value="<?= clean($editLead['phone']??'') ?>"></div>
      <div><label class="form-label">Source</label>
        <select name="source" class="form-input">
          <?php foreach (['walk_in'=>'Walk-in','referral'=>'Referral','social_media'=>'Social Media','website'=>'Website','ad'=>'Ad','cold_call'=>'Cold Call','other'=>'Other'] as $k=>$v): ?>
          <option value="<?=$k?>" <?= ($editLead['source']??'other')===$k?'selected':'' ?>><?=$v?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label class="form-label">Status</label>
        <select name="status" class="form-input">
          <?php foreach (['new'=>'New','contacted'=>'Contacted','qualified'=>'Qualified','converted'=>'Converted','lost'=>'Lost'] as $k=>$v): ?>
          <option value="<?=$k?>" <?= ($editLead['status']??'new')===$k?'selected':'' ?>><?=$v?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-span-2"><label class="form-label">Campaign</label>
        <select name="campaign_id" class="form-input">
          <option value="">None</option>
          <?php foreach ($allCampaigns as $c): ?>
          <option value="<?=$c['id']?>" <?= ($editLead['campaign_id']??0)==$c['id']?'selected':'' ?>><?= clean($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-span-2"><label class="form-label">Notes</label><textarea name="notes" rows="3" class="form-input"><?= clean($editLead['notes']??'') ?></textarea></div>
    </div>
    <div class="flex justify-end gap-3 mt-5 pt-4 border-t">
      <button type="button" onclick="closeModal('leadModal')" class="btn-secondary">Cancel</button>
      <button type="submit" class="btn-primary"><i data-lucide="save" class="w-4 h-4"></i> Save Lead</button>
    </div>
  </form>
</div>
</div>


<!-- ══════════════════════════════════════════════════════════
     EMAIL BLAST MODAL
════════════════════════════════════════════════════════════ -->
<div id="emailBlastModal" class="modal-overlay hidden">
<div class="modal-box max-w-lg">
  <div class="flex items-center justify-between px-6 py-4 border-b">
    <h2 class="text-base font-semibold">Send Campaign Email</h2>
    <button onclick="closeModal('emailBlastModal')" class="text-gray-400"><i data-lucide="x" class="w-5 h-5"></i></button>
  </div>
  <form method="POST" class="p-6">
    <input type="hidden" name="form" value="email_blast">
    <div id="blastLeadIdsContainer"></div>
    <p class="text-xs text-gray-500 mb-4">Sending to <strong id="blastRecipientCount">0</strong> selected lead(s) with a valid email address.</p>
    <div class="mb-4"><label class="form-label">Subject *</label><input type="text" name="blast_subject" required class="form-input" placeholder="e.g. New arrivals this week!"></div>
    <div><label class="form-label">Message *</label><textarea name="blast_message" rows="6" required class="form-input" placeholder="Write your message..."></textarea></div>
    <div class="flex justify-end gap-3 mt-5 pt-4 border-t">
      <button type="button" onclick="closeModal('emailBlastModal')" class="btn-secondary">Cancel</button>
      <button type="submit" class="btn-primary"><i data-lucide="send" class="w-4 h-4"></i> Send Email</button>
    </div>
  </form>
</div>
</div>


<!-- ══════════════════════════════════════════════════════════
     CAMPAIGN MODAL
════════════════════════════════════════════════════════════ -->
<div id="campaignModal" class="modal-overlay <?= $editCampaign?'':'hidden' ?>">
<div class="modal-box max-w-lg">
  <div class="flex items-center justify-between px-6 py-4 border-b">
    <h2 class="text-base font-semibold"><?= $editCampaign?'Edit Campaign':'New Campaign' ?></h2>
    <button onclick="closeModal('campaignModal')" class="text-gray-400"><i data-lucide="x" class="w-5 h-5"></i></button>
  </div>
  <form method="POST" class="p-6">
    <input type="hidden" name="form" value="campaign">
    <?php if ($editCampaign): ?><input type="hidden" name="edit_id" value="<?= $editCampaign['id'] ?>"><?php endif; ?>
    <div class="grid grid-cols-2 gap-4">
      <div class="col-span-2"><label class="form-label">Campaign Name *</label><input type="text" name="name" required class="form-input" value="<?= clean($editCampaign['name']??'') ?>"></div>
      <div><label class="form-label">Channel</label>
        <select name="channel" class="form-input">
          <?php foreach (['social_media'=>'Social Media','email'=>'Email','sms'=>'SMS','radio'=>'Radio','print'=>'Print','influencer'=>'Influencer','referral'=>'Referral Program','other'=>'Other'] as $k=>$v): ?>
          <option value="<?=$k?>" <?= ($editCampaign['channel']??'social_media')===$k?'selected':'' ?>><?=$v?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label class="form-label">Status</label>
        <select name="status" class="form-input">
          <?php foreach (['planned'=>'Planned','active'=>'Active','completed'=>'Completed','cancelled'=>'Cancelled'] as $k=>$v): ?>
          <option value="<?=$k?>" <?= ($editCampaign['status']??'planned')===$k?'selected':'' ?>><?=$v?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label class="form-label">Budget (<?= APP_CURRENCY_SYMBOL ?>)</label><input type="number" name="budget" class="form-input" value="<?= $editCampaign['budget']??0 ?>" min="0" step="0.01"></div>
      <div></div>
      <div><label class="form-label">Start Date</label><input type="date" name="start_date" class="form-input" value="<?= $editCampaign['start_date']??'' ?>"></div>
      <div><label class="form-label">End Date</label><input type="date" name="end_date" class="form-input" value="<?= $editCampaign['end_date']??'' ?>"></div>
      <div class="col-span-2"><label class="form-label">Description</label><textarea name="description" rows="3" class="form-input"><?= clean($editCampaign['description']??'') ?></textarea></div>
    </div>
    <div class="flex justify-end gap-3 mt-5 pt-4 border-t">
      <button type="button" onclick="closeModal('campaignModal')" class="btn-secondary">Cancel</button>
      <button type="submit" class="btn-primary"><i data-lucide="save" class="w-4 h-4"></i> Save Campaign</button>
    </div>
  </form>
</div>
</div>


<!-- ══════════════════════════════════════════════════════════
     PROMO CODE MODAL
════════════════════════════════════════════════════════════ -->
<div id="promoModal" class="modal-overlay <?= $editPromo?'':'hidden' ?>">
<div class="modal-box max-w-lg">
  <div class="flex items-center justify-between px-6 py-4 border-b">
    <h2 class="text-base font-semibold"><?= $editPromo?'Edit Promo Code':'New Promo Code' ?></h2>
    <button onclick="closeModal('promoModal')" class="text-gray-400"><i data-lucide="x" class="w-5 h-5"></i></button>
  </div>
  <form method="POST" class="p-6">
    <input type="hidden" name="form" value="promo">
    <?php if ($editPromo): ?><input type="hidden" name="edit_id" value="<?= $editPromo['id'] ?>"><?php endif; ?>
    <div class="grid grid-cols-2 gap-4">
      <div class="col-span-2"><label class="form-label">Code *</label><input type="text" name="code" required class="form-input uppercase" value="<?= clean($editPromo['code']??'') ?>" placeholder="e.g. LAUNCH10"></div>
      <div><label class="form-label">Campaign</label>
        <select name="campaign_id" class="form-input">
          <option value="">None</option>
          <?php foreach ($allCampaigns as $c): ?>
          <option value="<?=$c['id']?>" <?= ($editPromo['campaign_id']??0)==$c['id']?'selected':'' ?>><?= clean($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label class="form-label">Applies To Item</label>
        <select name="item_id" class="form-input">
          <option value="">All Items</option>
          <?php foreach ($allItems as $it): ?>
          <option value="<?=$it['id']?>" <?= ($editPromo['item_id']??0)==$it['id']?'selected':'' ?>><?= clean($it['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label class="form-label">Discount Type</label>
        <select name="discount_type" class="form-input">
          <option value="percentage" <?= ($editPromo['discount_type']??'percentage')==='percentage'?'selected':'' ?>>Percentage</option>
          <option value="fixed" <?= ($editPromo['discount_type']??'')==='fixed'?'selected':'' ?>>Fixed Amount</option>
        </select>
      </div>
      <div><label class="form-label">Discount Value</label><input type="number" name="discount_value" class="form-input" value="<?= $editPromo['discount_value']??0 ?>" min="0" step="0.01"></div>
      <div><label class="form-label">Max Uses</label><input type="number" name="max_uses" class="form-input" value="<?= $editPromo['max_uses']??'' ?>" min="1" placeholder="Unlimited"></div>
      <div><label class="form-label">Status</label>
        <select name="status" class="form-input">
          <option value="active" <?= ($editPromo['status']??'active')==='active'?'selected':'' ?>>Active</option>
          <option value="inactive" <?= ($editPromo['status']??'')==='inactive'?'selected':'' ?>>Inactive</option>
        </select>
      </div>
      <div><label class="form-label">Start Date</label><input type="date" name="start_date" class="form-input" value="<?= $editPromo['start_date']??'' ?>"></div>
      <div><label class="form-label">End Date</label><input type="date" name="end_date" class="form-input" value="<?= $editPromo['end_date']??'' ?>"></div>
    </div>
    <div class="flex justify-end gap-3 mt-5 pt-4 border-t">
      <button type="button" onclick="closeModal('promoModal')" class="btn-secondary">Cancel</button>
      <button type="submit" class="btn-primary"><i data-lucide="save" class="w-4 h-4"></i> Save Promo Code</button>
    </div>
  </form>
</div>
</div>


<!-- ══════════════════════════════════════════════════════════
     CONTENT CALENDAR MODAL
════════════════════════════════════════════════════════════ -->
<div id="contentModal" class="modal-overlay <?= $editContent?'':'hidden' ?>">
<div class="modal-box max-w-lg">
  <div class="flex items-center justify-between px-6 py-4 border-b">
    <h2 class="text-base font-semibold"><?= $editContent?'Edit Content':'New Content' ?></h2>
    <button onclick="closeModal('contentModal')" class="text-gray-400"><i data-lucide="x" class="w-5 h-5"></i></button>
  </div>
  <form method="POST" class="p-6">
    <input type="hidden" name="form" value="content">
    <?php if ($editContent): ?><input type="hidden" name="edit_id" value="<?= $editContent['id'] ?>"><?php endif; ?>
    <div class="grid grid-cols-2 gap-4">
      <div class="col-span-2"><label class="form-label">Title *</label><input type="text" name="title" required class="form-input" value="<?= clean($editContent['title']??'') ?>"></div>
      <div><label class="form-label">Channel</label>
        <select name="channel" class="form-input">
          <?php foreach (['social_media'=>'Social Media','email'=>'Email','sms'=>'SMS','print'=>'Print','other'=>'Other'] as $k=>$v): ?>
          <option value="<?=$k?>" <?= ($editContent['channel']??'social_media')===$k?'selected':'' ?>><?=$v?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label class="form-label">Scheduled Date *</label><input type="date" name="scheduled_date" required class="form-input" value="<?= $editContent['scheduled_date']??'' ?>"></div>
      <div><label class="form-label">Status</label>
        <select name="status" class="form-input">
          <?php foreach (['planned'=>'Planned','posted'=>'Posted','cancelled'=>'Cancelled'] as $k=>$v): ?>
          <option value="<?=$k?>" <?= ($editContent['status']??'planned')===$k?'selected':'' ?>><?=$v?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label class="form-label">Campaign</label>
        <select name="campaign_id" class="form-input">
          <option value="">None</option>
          <?php foreach ($allCampaigns as $c): ?>
          <option value="<?=$c['id']?>" <?= ($editContent['campaign_id']??0)==$c['id']?'selected':'' ?>><?= clean($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-span-2"><label class="form-label">Notes</label><textarea name="notes" rows="3" class="form-input"><?= clean($editContent['notes']??'') ?></textarea></div>
    </div>
    <div class="flex justify-end gap-3 mt-5 pt-4 border-t">
      <button type="button" onclick="closeModal('contentModal')" class="btn-secondary">Cancel</button>
      <button type="submit" class="btn-primary"><i data-lucide="save" class="w-4 h-4"></i> Save Content</button>
    </div>
  </form>
</div>
</div>


<script>
// ── Leads: bulk selection + email blast ─────────────────────────
function toggleAllLeads(cb) {
  document.querySelectorAll('.lead-check').forEach(c => c.checked = cb.checked);
  updateEmailBtn();
}
function updateEmailBtn() {
  const checked = document.querySelectorAll('.lead-check:checked');
  document.getElementById('selectedCount').textContent = checked.length;
  document.getElementById('sendEmailBtn').disabled = checked.length === 0;
}
function openEmailBlastModal() {
  const checked = Array.from(document.querySelectorAll('.lead-check:checked'));
  const withEmail = checked.filter(c => c.dataset.email && c.dataset.email.trim() !== '');
  document.getElementById('blastRecipientCount').textContent = withEmail.length;
  document.getElementById('blastLeadIdsContainer').innerHTML =
    checked.map(c => `<input type="hidden" name="lead_ids[]" value="${c.value}">`).join('');
  openModal('emailBlastModal');
}

<?php if ($editLead): ?>window.addEventListener('load', () => openModal('leadModal'));<?php endif; ?>
<?php if ($editCampaign): ?>window.addEventListener('load', () => openModal('campaignModal'));<?php endif; ?>
<?php if ($editPromo): ?>window.addEventListener('load', () => openModal('promoModal'));<?php endif; ?>
<?php if ($editContent): ?>window.addEventListener('load', () => openModal('contentModal'));<?php endif; ?>
</script>

<style>@media print { aside, header, form, .btn-primary, .btn-secondary, .no-print { display:none!important; } .ml-60 { margin-left:0!important; } }</style>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

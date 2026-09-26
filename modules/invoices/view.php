<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
$db = getDB();
$currentModule = 'invoices';
$id = (int)get('id');
if (!$id) { header('Location: index.php'); exit; }

$stmt = $db->prepare("SELECT i.*, COALESCE(c.company_name, CONCAT(c.first_name,' ',c.last_name)) as contact_name, c.email as contact_email, c.phone as contact_phone, c.billing_address as contact_address FROM invoices i LEFT JOIN contacts c ON i.contact_id=c.id WHERE i.id=?");
$stmt->execute([$id]); $invoice = $stmt->fetch();
if (!$invoice) { $_SESSION['flash_error']='Invoice not found'; header('Location: index.php'); exit; }

$items = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id=?"); $items->execute([$id]); $items = $items->fetchAll();
$payments = $db->prepare("SELECT p.*, u.name as recorded_by_name FROM invoice_payments p LEFT JOIN users u ON p.created_by=u.id WHERE p.invoice_id=? ORDER BY p.payment_date DESC"); $payments->execute([$id]); $payments = $payments->fetchAll();

$pageTitle = $invoice['invoice_number'];
include __DIR__ . '/../../includes/header.php';
?>

<div class="flex items-center justify-between mb-6">
  <a href="index.php" class="btn-secondary text-xs"><i data-lucide="arrow-left" class="w-4 h-4"></i> Back</a>
  <div class="flex gap-2">
    <a href="index.php?edit=<?= $id ?>" class="btn-secondary text-xs"><i data-lucide="pencil" class="w-4 h-4"></i> Edit</a>
    <a href="record_payment.php?id=<?= $id ?>" class="btn-primary text-xs"><i data-lucide="credit-card" class="w-4 h-4"></i> Record Payment</a>
    <button onclick="window.print()" class="btn-secondary text-xs"><i data-lucide="printer" class="w-4 h-4"></i> Print</button>
  </div>
</div>

<div class="max-w-4xl mx-auto">
  <div class="card p-8 print:shadow-none">
    <!-- Invoice Header -->
    <?php
      $invLogoFull = $logoPath ? __DIR__ . '/../../' . $logoPath : '';
      $invHasLogo  = $logoPath && file_exists($invLogoFull);
    ?>
    <div class="flex justify-between mb-8">
      <div>
        <?php if ($invHasLogo): ?>
        <img src="<?= APP_URL ?>/<?= clean($logoPath) ?>?v=<?= filemtime($invLogoFull) ?>" alt="<?= clean($appName) ?>"
             class="h-24 w-auto max-w-[280px] object-contain">
        <?php else: ?>
        <h1 class="text-2xl font-bold text-gray-800"><?= clean($appName) ?></h1>
        <?php endif; ?>
        <p class="text-gray-500 text-sm mt-1">Tax Invoice</p>
      </div>
      <div class="text-right">
        <h2 class="text-xl font-bold text-blue-600"><?= clean($invoice['invoice_number']) ?></h2>
        <?= statusBadge($invoice['status']) ?>
      </div>
    </div>

    <!-- Billing Info -->
    <div class="grid grid-cols-2 gap-8 mb-8">
      <div>
        <p class="text-xs font-semibold text-gray-400 uppercase mb-2">Bill To</p>
        <p class="font-semibold text-gray-800"><?= clean($invoice['contact_name']) ?></p>
        <p class="text-sm text-gray-500"><?= clean($invoice['contact_email']) ?></p>
        <p class="text-sm text-gray-500"><?= clean($invoice['contact_phone']) ?></p>
        <p class="text-sm text-gray-500 mt-1"><?= nl2br(clean($invoice['contact_address'])) ?></p>
      </div>
      <div class="text-right">
        <table class="ml-auto text-sm">
          <tr><td class="text-gray-400 pr-4 py-0.5">Issue Date:</td><td class="font-medium"><?= formatDate($invoice['issue_date']) ?></td></tr>
          <tr><td class="text-gray-400 pr-4 py-0.5">Due Date:</td><td class="font-medium <?= strtotime($invoice['due_date'])<time()&&$invoice['status']!=='paid'?'text-red-600':'' ?>"><?= formatDate($invoice['due_date']) ?></td></tr>
          <tr><td class="text-gray-400 pr-4 py-0.5">Currency:</td><td class="font-medium"><?= clean($invoice['currency']) ?></td></tr>
        </table>
      </div>
    </div>

    <!-- Items Table -->
    <table class="w-full mb-6 text-sm">
      <thead><tr class="bg-gray-50 border-y border-gray-200">
        <th class="px-3 py-3 text-left font-medium text-gray-600">#</th>
        <th class="px-3 py-3 text-left font-medium text-gray-600">Description</th>
        <th class="px-3 py-3 text-center font-medium text-gray-600">Qty</th>
        <th class="px-3 py-3 text-right font-medium text-gray-600">Unit Price</th>
        <th class="px-3 py-3 text-center font-medium text-gray-600">Disc%</th>
        <th class="px-3 py-3 text-center font-medium text-gray-600">Tax%</th>
        <th class="px-3 py-3 text-right font-medium text-gray-600">Amount</th>
      </tr></thead>
      <tbody class="divide-y divide-gray-100">
        <?php foreach ($items as $i => $item): ?>
        <tr>
          <td class="px-3 py-3 text-gray-400"><?= $i+1 ?></td>
          <td class="px-3 py-3 text-gray-700"><?= clean($item['description']) ?></td>
          <td class="px-3 py-3 text-center"><?= $item['quantity'] ?></td>
          <td class="px-3 py-3 text-right"><?= formatCurrency($item['unit_price']) ?></td>
          <td class="px-3 py-3 text-center"><?= $item['discount_percent'] ?>%</td>
          <td class="px-3 py-3 text-center"><?= $item['tax_percent'] ?>%</td>
          <td class="px-3 py-3 text-right font-medium"><?= formatCurrency($item['amount']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Totals -->
    <div class="flex justify-end mb-6">
      <div class="w-64 space-y-1.5 text-sm">
        <div class="flex justify-between text-gray-500"><span>Subtotal</span><span><?= formatCurrency($invoice['subtotal']) ?></span></div>
        <?php if ($invoice['discount_amount'] > 0): ?>
        <div class="flex justify-between text-red-500"><span>Discount</span><span>-<?= formatCurrency($invoice['discount_amount']) ?></span></div>
        <?php endif; ?>
        <?php if ($invoice['tax_amount'] > 0): ?>
        <div class="flex justify-between text-gray-500"><span>Tax</span><span><?= formatCurrency($invoice['tax_amount']) ?></span></div>
        <?php endif; ?>
        <?php if ($invoice['shipping_charge'] > 0): ?>
        <div class="flex justify-between text-gray-500"><span>Shipping</span><span><?= formatCurrency($invoice['shipping_charge']) ?></span></div>
        <?php endif; ?>
        <div class="flex justify-between font-bold text-gray-800 border-t pt-2 text-base"><span>Total</span><span><?= formatCurrency($invoice['total']) ?></span></div>
        <?php if ($invoice['amount_paid'] > 0): ?>
        <div class="flex justify-between text-green-600"><span>Amount Paid</span><span>-<?= formatCurrency($invoice['amount_paid']) ?></span></div>
        <div class="flex justify-between font-bold text-red-600 border-t pt-1"><span>Balance Due</span><span><?= formatCurrency($invoice['balance_due']) ?></span></div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($invoice['notes']): ?>
    <div class="border-t pt-4 mb-4"><p class="text-xs text-gray-400 mb-1">Notes</p><p class="text-sm text-gray-600"><?= nl2br(clean($invoice['notes'])) ?></p></div>
    <?php endif; ?>
    <?php if ($invoice['terms']): ?>
    <div class="border-t pt-4"><p class="text-xs text-gray-400 mb-1">Terms & Conditions</p><p class="text-sm text-gray-600"><?= nl2br(clean($invoice['terms'])) ?></p></div>
    <?php endif; ?>
  </div>

  <!-- Payment History -->
  <?php if ($payments): ?>
  <div class="card p-5 mt-6">
    <h3 class="text-sm font-semibold text-gray-800 mb-4">Payment History</h3>
    <table class="w-full text-sm">
      <thead><tr class="text-xs text-gray-400 border-b">
        <th class="pb-2 text-left">Date</th><th class="pb-2 text-left">Mode</th><th class="pb-2 text-left">Reference</th><th class="pb-2 text-left">Recorded By</th><th class="pb-2 text-right">Amount</th>
      </tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php foreach ($payments as $p): ?>
        <tr>
          <td class="py-2"><?= formatDate($p['payment_date']) ?></td>
          <td class="py-2 capitalize"><?= str_replace('_',' ',$p['payment_mode']) ?></td>
          <td class="py-2 text-gray-400"><?= clean($p['reference']) ?></td>
          <td class="py-2 text-gray-400"><?= clean($p['recorded_by_name'] ?? '-') ?></td>
          <td class="py-2 text-right font-medium text-green-600"><?= formatCurrency($p['amount']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<style>@media print { aside, header, .btn-primary, .btn-secondary, a[href="index.php"] { display:none!important; } .ml-60 { margin-left:0!important; } }</style>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
$db = getDB();
$currentModule = 'invoices';
$id = (int)get('id');
$stmt = $db->prepare("SELECT i.*, COALESCE(c.company_name, CONCAT(c.first_name,' ',c.last_name)) as contact_name FROM invoices i LEFT JOIN contacts c ON i.contact_id=c.id WHERE i.id=?");
$stmt->execute([$id]); $invoice = $stmt->fetch();
if (!$invoice) { header('Location: index.php'); exit; }
$pageTitle = 'Record Payment';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = (float)post('amount');
    $db->prepare("INSERT INTO invoice_payments (invoice_id, payment_date, amount, payment_mode, reference, notes, created_by) VALUES (?,?,?,?,?,?,?)")
       ->execute([$id, post('payment_date'), $amount, post('payment_mode'), post('reference'), post('notes'), $_SESSION['user_id']]);

    $newPaid = $invoice['amount_paid'] + $amount;
    $balance = max(0, $invoice['total'] - $newPaid);
    $newStatus = $balance <= 0 ? 'paid' : ($newPaid > 0 ? 'partially_paid' : $invoice['status']);
    $db->prepare("UPDATE invoices SET amount_paid=?, balance_due=?, status=? WHERE id=?")->execute([$newPaid, $balance, $newStatus, $id]);

    $_SESSION['flash_success'] = 'Payment of ' . formatCurrency($amount) . ' recorded successfully.';
    header('Location: view.php?id=' . $id); exit;
}

$accounts = $db->query("SELECT id, account_name FROM accounts WHERE account_type='asset' AND sub_type IN('cash','bank') AND status='active'")->fetchAll();
include __DIR__ . '/../../includes/header.php';
?>

<div class="max-w-lg mx-auto">
  <div class="flex items-center gap-3 mb-6">
    <a href="view.php?id=<?= $id ?>" class="btn-secondary text-xs"><i data-lucide="arrow-left" class="w-4 h-4"></i> Back</a>
    <h2 class="text-base font-semibold">Record Payment</h2>
  </div>

  <div class="card p-6 mb-4">
    <div class="flex justify-between text-sm mb-2">
      <span class="text-gray-500">Invoice</span><span class="font-semibold"><?= clean($invoice['invoice_number']) ?></span>
    </div>
    <div class="flex justify-between text-sm mb-2">
      <span class="text-gray-500">Customer</span><span><?= clean($invoice['contact_name']) ?></span>
    </div>
    <div class="flex justify-between text-sm mb-2">
      <span class="text-gray-500">Invoice Total</span><span class="font-semibold"><?= formatCurrency($invoice['total']) ?></span>
    </div>
    <div class="flex justify-between text-sm mb-2">
      <span class="text-gray-500">Amount Paid</span><span class="text-green-600"><?= formatCurrency($invoice['amount_paid']) ?></span>
    </div>
    <div class="flex justify-between font-bold border-t pt-2 mt-2">
      <span>Balance Due</span><span class="text-red-600"><?= formatCurrency($invoice['balance_due']) ?></span>
    </div>
  </div>

  <div class="card p-6">
    <form method="POST">
      <div class="space-y-4">
        <div>
          <label class="form-label">Payment Date *</label>
          <input type="date" name="payment_date" required class="form-input" value="<?= date('Y-m-d') ?>">
        </div>
        <div>
          <label class="form-label">Amount *</label>
          <input type="number" name="amount" required class="form-input" value="<?= $invoice['balance_due'] ?>" min="0.01" step="0.01" max="<?= $invoice['balance_due'] ?>">
        </div>
        <div>
          <label class="form-label">Payment Mode *</label>
          <select name="payment_mode" required class="form-input">
            <option value="bank_transfer">Bank Transfer</option>
            <option value="cash">Cash</option>
            <option value="cheque">Cheque</option>
            <option value="card">Card</option>
            <option value="online">Online Payment</option>
          </select>
        </div>
        <div>
          <label class="form-label">Reference / Transaction ID</label>
          <input type="text" name="reference" class="form-input" placeholder="Bank teller ref, cheque number...">
        </div>
        <div>
          <label class="form-label">Notes</label>
          <textarea name="notes" rows="2" class="form-input" placeholder="Optional notes..."></textarea>
        </div>
        <div class="flex justify-end gap-3 pt-2">
          <a href="view.php?id=<?= $id ?>" class="btn-secondary">Cancel</a>
          <button type="submit" class="btn-primary"><i data-lucide="check" class="w-4 h-4"></i> Record Payment</button>
        </div>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

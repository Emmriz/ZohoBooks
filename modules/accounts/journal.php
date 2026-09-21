<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
if (!hasPermission('accounts')) { $_SESSION['flash_error']='Access denied'; header('Location: '.APP_URL.'/modules/dashboard/index.php'); exit; }

$db = getDB();
$currentModule = 'accounts';
$pageTitle = 'Journal Entries';

if (get('action') === 'delete' && get('id')) {
    $db->prepare("DELETE FROM journal_entries WHERE id=? AND status='draft'")->execute([(int)get('id')]);
    $_SESSION['flash_success'] = 'Draft journal entry deleted.';
    header('Location: ' . APP_URL . '/modules/accounts/journal.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $editId = (int)post('edit_id');
    $accs  = post('line_account', []);
    $descs = post('line_desc', []);
    $debits= post('line_debit', []);
    $credits=post('line_credit', []);

    $totalDebit = $totalCredit = 0;
    $lines = [];
    foreach ($accs as $i => $accId) {
        if (!$accId) continue;
        $d = (float)($debits[$i] ?? 0);
        $c = (float)($credits[$i] ?? 0);
        $totalDebit += $d; $totalCredit += $c;
        $lines[] = ['account_id'=>$accId,'description'=>$descs[$i]??'','debit'=>$d,'credit'=>$c];
    }
    if (abs($totalDebit - $totalCredit) > 0.01) {
        $_SESSION['flash_error'] = 'Journal entry is not balanced. Debits must equal Credits.';
        header('Location: ' . APP_URL . '/modules/accounts/journal.php'); exit;
    }

    $data = [
        'entry_date'   => post('entry_date'),
        'reference'    => post('reference'),
        'notes'        => post('notes'),
        'status'       => post('status','draft'),
        'total_debit'  => $totalDebit,
        'total_credit' => $totalCredit,
        'created_by'   => $_SESSION['user_id'],
    ];

    if ($editId) {
        $set = implode(', ', array_map(fn($k)=>"$k=?", array_keys($data)));
        $db->prepare("UPDATE journal_entries SET $set WHERE id=?")->execute([...array_values($data), $editId]);
        $db->prepare("DELETE FROM journal_lines WHERE journal_id=?")->execute([$editId]);
        $jid = $editId; $_SESSION['flash_success'] = 'Journal entry updated.';
    } else {
        $data['journal_number'] = generateNumber('JE', 'journal_entries', 'journal_number');
        $cols = implode(',', array_keys($data)); $ph = implode(',', array_fill(0,count($data),'?'));
        $db->prepare("INSERT INTO journal_entries ($cols) VALUES ($ph)")->execute(array_values($data));
        $jid = $db->lastInsertId(); $_SESSION['flash_success'] = 'Journal entry created.';
    }
    foreach ($lines as $line) {
        $db->prepare("INSERT INTO journal_lines (journal_id, account_id, description, debit, credit) VALUES (?,?,?,?,?)")
           ->execute([$jid, $line['account_id'], $line['description'], $line['debit'], $line['credit']]);
        // Update account balances
        $net = $line['debit'] - $line['credit'];
        // For assets/expenses: debit increases, credit decreases; for liability/equity/income: reverse
        $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id=?")->execute([$net, $line['account_id']]);
    }
    header('Location: ' . APP_URL . '/modules/accounts/journal.php'); exit;
}

$page = max(1,(int)get('page',1));
$total = $db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
$pg = paginate((int)$total, $page);
$journals = $db->query("SELECT je.*, u.name as created_by_name FROM journal_entries je LEFT JOIN users u ON je.created_by=u.id ORDER BY je.entry_date DESC, je.id DESC LIMIT {$pg['perPage']} OFFSET {$pg['offset']}")->fetchAll();
$accounts = $db->query("SELECT id, account_code, account_name FROM accounts WHERE status='active' ORDER BY account_code")->fetchAll();

$editJournal = null; $editLines = [];
if (get('edit')) {
    $s=$db->prepare("SELECT * FROM journal_entries WHERE id=?"); $s->execute([(int)get('edit')]); $editJournal=$s->fetch();
    $sl=$db->prepare("SELECT * FROM journal_lines WHERE journal_id=?"); $sl->execute([(int)get('edit')]); $editLines=$sl->fetchAll();
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="flex items-center justify-between mb-6">
  <div class="flex gap-2">
    <a href="index.php" class="btn-secondary text-xs"><i data-lucide="arrow-left" class="w-4 h-4"></i> Chart of Accounts</a>
  </div>
  <button onclick="openModal('journalModal')" class="btn-primary"><i data-lucide="plus" class="w-4 h-4"></i> New Entry</button>
</div>

<div class="card overflow-hidden">
  <table class="w-full">
    <thead class="bg-gray-50 border-b"><tr class="text-xs text-gray-500 font-medium">
      <th class="px-4 py-3 text-left">Journal #</th>
      <th class="px-4 py-3 text-left">Date</th>
      <th class="px-4 py-3 text-left">Reference</th>
      <th class="px-4 py-3 text-left">Notes</th>
      <th class="px-4 py-3 text-right">Debit</th>
      <th class="px-4 py-3 text-right">Credit</th>
      <th class="px-4 py-3 text-center">Status</th>
      <th class="px-4 py-3 text-center">Actions</th>
    </tr></thead>
    <tbody class="divide-y divide-gray-50">
      <?php foreach ($journals as $je): ?>
      <tr class="table-row">
        <td class="px-4 py-3 text-xs font-semibold text-blue-600"><?= clean($je['journal_number']) ?></td>
        <td class="px-4 py-3 text-xs"><?= formatDate($je['entry_date']) ?></td>
        <td class="px-4 py-3 text-xs text-gray-500"><?= clean($je['reference']) ?></td>
        <td class="px-4 py-3 text-xs text-gray-500 max-w-[200px] truncate"><?= clean($je['notes']) ?></td>
        <td class="px-4 py-3 text-xs text-right font-medium"><?= formatCurrency($je['total_debit']) ?></td>
        <td class="px-4 py-3 text-xs text-right font-medium"><?= formatCurrency($je['total_credit']) ?></td>
        <td class="px-4 py-3 text-center"><?= statusBadge($je['status']) ?></td>
        <td class="px-4 py-3 text-center">
          <div class="flex items-center justify-center gap-1">
            <a href="?edit=<?= $je['id'] ?>" class="p-1 rounded hover:bg-yellow-50 text-yellow-500"><i data-lucide="pencil" class="w-3.5 h-3.5"></i></a>
            <?php if ($je['status']==='draft'): ?>
            <button onclick="confirmDelete('?action=delete&id=<?= $je['id'] ?>','Delete this draft entry?')" class="p-1 rounded hover:bg-red-50 text-red-400"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$journals): ?><tr><td colspan="8" class="px-4 py-12 text-center text-gray-400 text-xs">No journal entries yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Journal Modal -->
<div id="journalModal" class="modal-overlay <?= $editJournal?'':'hidden' ?>">
<div class="modal-box max-w-4xl">
  <div class="flex items-center justify-between px-6 py-4 border-b">
    <h2 class="text-base font-semibold"><?= $editJournal?'Edit Journal Entry':'New Journal Entry' ?></h2>
    <button onclick="closeModal('journalModal')" class="text-gray-400"><i data-lucide="x" class="w-5 h-5"></i></button>
  </div>
  <form method="POST" class="p-6">
    <?php if ($editJournal): ?><input type="hidden" name="edit_id" value="<?= $editJournal['id'] ?>"><?php endif; ?>
    <div class="grid grid-cols-3 gap-4 mb-5">
      <div><label class="form-label">Date *</label><input type="date" name="entry_date" required class="form-input" value="<?= $editJournal['entry_date']??date('Y-m-d') ?>"></div>
      <div><label class="form-label">Reference</label><input type="text" name="reference" class="form-input" value="<?= clean($editJournal['reference']??'') ?>"></div>
      <div><label class="form-label">Status</label>
        <select name="status" class="form-input">
          <option value="draft" <?= ($editJournal['status']??'draft')==='draft'?'selected':'' ?>>Draft</option>
          <option value="published" <?= ($editJournal['status']??'')==='published'?'selected':'' ?>>Published</option>
        </select>
      </div>
      <div class="col-span-3"><label class="form-label">Notes</label><input type="text" name="notes" class="form-input" value="<?= clean($editJournal['notes']??'') ?>"></div>
    </div>

    <!-- Lines -->
    <div class="border border-gray-200 rounded-xl overflow-hidden mb-4">
      <table class="w-full text-xs">
        <thead class="bg-gray-50 border-b"><tr class="text-gray-500">
          <th class="px-3 py-2 text-left">Account</th>
          <th class="px-3 py-2 text-left w-48">Description</th>
          <th class="px-3 py-2 text-right w-36">Debit</th>
          <th class="px-3 py-2 text-right w-36">Credit</th>
          <th class="w-8"></th>
        </tr></thead>
        <tbody id="journalLines">
          <?php
          $lineData = $editLines ?: [null, null];
          foreach ($lineData as $line):
          ?>
          <tr class="jl-row border-b border-gray-50">
            <td class="px-2 py-1">
              <select name="line_account[]" class="form-input text-xs py-1.5" required>
                <option value="">Select account...</option>
                <?php foreach ($accounts as $acc): ?>
                <option value="<?=$acc['id']?>" <?= ($line['account_id']??0)==$acc['id']?'selected':'' ?>><?= $acc['account_code'].' - '.clean($acc['account_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td class="px-2 py-1"><input type="text" name="line_desc[]" class="form-input text-xs py-1.5" value="<?= clean($line['description']??'') ?>"></td>
            <td class="px-2 py-1"><input type="number" name="line_debit[]" class="form-input text-xs py-1.5 text-right" value="<?= number_format($line['debit']??0,2,'.','') ?>" min="0" step="0.01" oninput="calcJournalTotals()"></td>
            <td class="px-2 py-1"><input type="number" name="line_credit[]" class="form-input text-xs py-1.5 text-right" value="<?= number_format($line['credit']??0,2,'.','') ?>" min="0" step="0.01" oninput="calcJournalTotals()"></td>
            <td class="px-2 py-1"><button type="button" onclick="removeJLine(this)" class="text-red-400"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot class="bg-gray-50 border-t font-semibold text-xs">
          <tr>
            <td class="px-3 py-2 text-gray-700" colspan="2">Totals</td>
            <td class="px-3 py-2 text-right" id="jTotalDebit">₦0.00</td>
            <td class="px-3 py-2 text-right" id="jTotalCredit">₦0.00</td>
            <td></td>
          </tr>
          <tr>
            <td colspan="3" class="px-3 py-1 text-xs">
              <span id="jBalanceMsg" class="text-green-600"></span>
            </td>
            <td colspan="2"></td>
          </tr>
        </tfoot>
      </table>
      <div class="px-4 py-2 border-t">
        <button type="button" onclick="addJLine()" class="text-xs text-blue-600 hover:underline flex items-center gap-1"><i data-lucide="plus" class="w-3.5 h-3.5"></i> Add Line</button>
      </div>
    </div>

    <div class="flex justify-end gap-3 pt-2">
      <button type="button" onclick="closeModal('journalModal')" class="btn-secondary">Cancel</button>
      <button type="submit" class="btn-primary"><i data-lucide="save" class="w-4 h-4"></i> Save Entry</button>
    </div>
  </form>
</div>
</div>

<script>
const accountOptions = `<?php foreach($accounts as $acc): ?><option value="<?=$acc['id']?>"><?= $acc['account_code'].' - '.htmlspecialchars($acc['account_name'],ENT_QUOTES) ?></option><?php endforeach; ?>`;

function addJLine() {
  document.getElementById('journalLines').insertAdjacentHTML('beforeend', `<tr class="jl-row border-b border-gray-50">
    <td class="px-2 py-1"><select name="line_account[]" class="form-input text-xs py-1.5" required><option value="">Select account...</option>${accountOptions}</select></td>
    <td class="px-2 py-1"><input type="text" name="line_desc[]" class="form-input text-xs py-1.5"></td>
    <td class="px-2 py-1"><input type="number" name="line_debit[]" class="form-input text-xs py-1.5 text-right" value="0.00" min="0" step="0.01" oninput="calcJournalTotals()"></td>
    <td class="px-2 py-1"><input type="number" name="line_credit[]" class="form-input text-xs py-1.5 text-right" value="0.00" min="0" step="0.01" oninput="calcJournalTotals()"></td>
    <td class="px-2 py-1"><button type="button" onclick="removeJLine(this)" class="text-red-400"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button></td>
  </tr>`);
  lucide.createIcons();
}
function removeJLine(btn) {
  if (document.querySelectorAll('.jl-row').length <= 2) return;
  btn.closest('tr').remove(); calcJournalTotals();
}
function calcJournalTotals() {
  let td=0, tc=0;
  document.querySelectorAll('.jl-row').forEach(row => {
    td += parseFloat(row.querySelector('[name="line_debit[]"]').value)||0;
    tc += parseFloat(row.querySelector('[name="line_credit[]"]').value)||0;
  });
  document.getElementById('jTotalDebit').textContent = '₦'+td.toFixed(2);
  document.getElementById('jTotalCredit').textContent = '₦'+tc.toFixed(2);
  const diff = Math.abs(td-tc);
  const msg = document.getElementById('jBalanceMsg');
  if (diff < 0.01) { msg.textContent='✓ Balanced'; msg.className='text-green-600'; }
  else { msg.textContent='⚠ Difference: ₦'+diff.toFixed(2); msg.className='text-red-600'; }
}
calcJournalTotals();
<?php if ($editJournal): ?>window.addEventListener('load',()=>openModal('journalModal'));<?php endif; ?>
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

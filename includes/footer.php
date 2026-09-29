  </main><!-- /main -->
</div><!-- /ml-60 -->

<!-- ══════════════════════════════════════════════════════════
     GLOBAL CONFIRM DIALOG (replaces native confirm())
════════════════════════════════════════════════════════════ -->
<div id="confirmDialog" class="modal-overlay hidden" style="z-index:100">
<div class="modal-box max-w-sm">
  <div class="p-6">
    <div class="flex items-start gap-3 mb-5">
      <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center flex-shrink-0">
        <i data-lucide="alert-triangle" class="w-5 h-5 text-red-500"></i>
      </div>
      <p id="confirmDialogMsg" class="flex-1 pt-1.5 text-sm font-medium text-gray-800"></p>
    </div>
    <div class="flex justify-end gap-3">
      <button type="button" onclick="closeModal('confirmDialog')" class="btn-secondary">Cancel</button>
      <button type="button" id="confirmDialogConfirmBtn" class="btn-danger">Confirm</button>
    </div>
  </div>
</div>
</div>

<!-- ══════════════════════════════════════════════════════════
     GLOBAL ALERT DIALOG (replaces native alert())
════════════════════════════════════════════════════════════ -->
<div id="alertDialog" class="modal-overlay hidden" style="z-index:100">
<div class="modal-box max-w-sm">
  <div class="p-6">
    <div class="flex items-start gap-3 mb-5">
      <div class="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0" style="background:var(--brand-light)">
        <i data-lucide="info" class="w-5 h-5" style="color:var(--brand)"></i>
      </div>
      <p id="alertDialogMsg" class="flex-1 pt-1.5 text-sm font-medium text-gray-800"></p>
    </div>
    <div class="flex justify-end">
      <button type="button" onclick="closeModal('alertDialog')" class="btn-primary">OK</button>
    </div>
  </div>
</div>
</div>

<script>
  // Init Lucide icons
  lucide.createIcons();

  const __csrfToken = <?= json_encode(csrfToken()) ?>;

  // Submits a GET-style "?action=x&id=y" URL as a real POST carrying the
  // CSRF token, so every state-changing link (not just <form> submissions)
  // is covered by the central CSRF check in requireLogin().
  function postAction(url) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = url;
    form.style.display = 'none';
    const csrf = document.createElement('input');
    csrf.type  = 'hidden';
    csrf.name  = 'csrf_token';
    csrf.value = __csrfToken;
    form.appendChild(csrf);
    document.body.appendChild(form);
    form.submit();
  }

  // Modal helpers
  function openModal(id) {
    document.getElementById(id).classList.remove('hidden');
    document.body.style.overflow = 'hidden';
  }
  function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
    document.body.style.overflow = '';
  }

  // Auto-hide flash messages
  setTimeout(() => {
    document.querySelectorAll('.alert-success, .alert-error').forEach(el => {
      el.style.transition = 'opacity 0.5s';
      el.style.opacity = '0';
      setTimeout(() => el.remove(), 500);
    });
  }, 4000);

  // Confirm delete helper — shows a branded dialog instead of the native confirm(),
  // then submits the action as a CSRF-protected POST instead of a bare GET navigation.
  function confirmDelete(url, msg) {
    document.getElementById('confirmDialogMsg').textContent = msg || 'Are you sure you want to delete this record?';
    document.getElementById('confirmDialogConfirmBtn').onclick = function() {
      closeModal('confirmDialog');
      postAction(url);
    };
    openModal('confirmDialog');
  }

  // Alert helper — shows a branded dialog instead of the native alert()
  function customAlert(msg) {
    document.getElementById('alertDialogMsg').textContent = msg;
    openModal('alertDialog');
  }
</script>
</body>
</html>

  </main><!-- /main -->
</div><!-- /ml-60 -->

<script>
  // Init Lucide icons
  lucide.createIcons();

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

  // Confirm delete helper
  function confirmDelete(url, msg) {
    if (confirm(msg || 'Are you sure you want to delete this record?')) {
      window.location.href = url;
    }
  }
</script>
</body>
</html>

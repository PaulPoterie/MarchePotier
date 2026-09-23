/* Populate the native WordPress quick-edit form with the saved selection. */
document.addEventListener('click', function (event) {
  const button = event.target.closest('.editinline');
  if (!button) return;
  const row = button.closest('tr');
  const id = row ? row.id.replace('post-', '') : '';
  if (!/^\d+$/.test(id)) return;
  setTimeout(function () {
    const value = document.querySelector('#post-' + id + ' [data-marcpo-decision]');
    const select = document.querySelector('#edit-' + id + ' select[name="marcpo_inline_decision"]');
    if (value && select) select.value = value.dataset.marcpoDecision;
  }, 0);
});

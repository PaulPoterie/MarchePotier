document.addEventListener('click', (event) => {
    const row = event.target.closest('tr[data-mp-dossier]');
    if (!row || event.target.closest('a,button,input,select,textarea,.mp-review-person') || window.getSelection().toString() || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    window.location.assign(row.dataset.mpDossier);
});

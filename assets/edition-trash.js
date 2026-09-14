/* Confirmation native ; les droits et nonces restent contrôlés par WordPress. */
(() => {
    const linked = new Set(mpEditionTrash.linked.map(String));
    document.addEventListener('click', event => {
        const link = event.target.closest('a[href]');
        if (!link) return;
        const url = new URL(link.href, window.location.href);
        if (url.searchParams.get('action') !== 'trash' || !linked.has(url.searchParams.get('post'))) return;
        if (!window.confirm(mpEditionTrash.message)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);
    document.addEventListener('submit', event => {
        const form = event.target;
        if (form.id !== 'posts-filter') return;
        const bottom = event.submitter && event.submitter.id === 'doaction2';
        const action = form.querySelector(bottom ? '[name="action2"]' : '[name="action"]');
        if (!action || action.value !== 'trash') return;
        const selected = Array.from(form.querySelectorAll('input[name="post[]"]:checked'));
        if (!selected.some(input => linked.has(input.value))) return;
        if (!window.confirm(selected.length === 1 ? mpEditionTrash.message : mpEditionTrash.bulkMessage)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);
})();

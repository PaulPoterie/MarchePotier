/* Visionneuse native réservée à Examiner : liens ordinaires si dialog indisponible. */
(() => {
    const links = Array.from(document.querySelectorAll('a[data-marcpo-viewer]'));
    if (!links.length || !window.HTMLDialogElement || !HTMLDialogElement.prototype.showModal) return;
    const dialog = document.createElement('dialog');
    dialog.className = 'marcpo-viewer';
    dialog.setAttribute('aria-label', 'Photos et justificatifs du dossier');
    dialog.innerHTML = `<header class="marcpo-viewer-bar"><p class="marcpo-viewer-title" aria-live="polite"></p><a class="marcpo-viewer-open" target="_blank" rel="noopener noreferrer">Ouvrir le fichier</a><a class="marcpo-viewer-download" target="_blank" rel="noopener noreferrer">Télécharger</a><button type="button" class="marcpo-viewer-close" aria-label="Fermer la visionneuse">Fermer ×</button></header><div class="marcpo-viewer-stage"><button type="button" class="marcpo-viewer-prev" aria-label="Fichier précédent">‹</button><div class="marcpo-viewer-content"></div><button type="button" class="marcpo-viewer-next" aria-label="Fichier suivant">›</button></div><p class="marcpo-viewer-help">Flèches pour parcourir les fichiers · Échap pour fermer. Si un PDF ne s’affiche pas, utilisez « Ouvrir le fichier ».</p>`;
    document.body.append(dialog);
    const content = dialog.querySelector('.marcpo-viewer-content');
    const close = dialog.querySelector('.marcpo-viewer-close');
    let index = 0, opener = null, overflow = '';
    function render() {
        const link = links[index];
        const label = link.dataset.label || 'Fichier';
        dialog.querySelector('.marcpo-viewer-title').textContent = label + ' — ' + (index + 1) + ' / ' + links.length;
        dialog.querySelector('.marcpo-viewer-open').href = link.dataset.preview || link.href;
        const download = dialog.querySelector('.marcpo-viewer-download');
        download.href = link.href; download.hidden = link.dataset.marcpoViewer !== 'pdf';
        content.replaceChildren();
        if (link.dataset.marcpoViewer === 'pdf') {
            const frame = document.createElement('iframe');
            frame.title = label;
            frame.src = link.dataset.preview;
            content.append(frame);
        } else {
            const image = document.createElement('img');
            image.alt = label;
            image.addEventListener('error', () => { content.textContent = 'Image indisponible. Essayez « Ouvrir le fichier » ou rechargez le dossier.'; });
            image.src = link.href;
            content.append(image);
        }
    }
    function move(step) { index = (index + step + links.length) % links.length; render(); }
    links.forEach((link, position) => link.addEventListener('click', event => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
        event.preventDefault();
        index = position; opener = link; overflow = document.documentElement.style.overflow;
        render(); dialog.showModal(); document.documentElement.style.overflow = 'hidden'; close.focus();
    }));
    close.addEventListener('click', () => dialog.close());
    dialog.querySelector('.marcpo-viewer-prev').addEventListener('click', () => move(-1));
    dialog.querySelector('.marcpo-viewer-next').addEventListener('click', () => move(1));
    dialog.addEventListener('keydown', event => {
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') { event.preventDefault(); move(event.key === 'ArrowLeft' ? -1 : 1); }
    });
    dialog.addEventListener('close', () => { content.replaceChildren(); document.documentElement.style.overflow = overflow; opener?.focus(); });
})();

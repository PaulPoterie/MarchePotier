/* Visionneuse native réservée à Examiner : liens ordinaires si dialog indisponible. */
(() => {
    const links = Array.from(document.querySelectorAll('a[data-mp-viewer]'));
    if (!links.length || !window.HTMLDialogElement || !HTMLDialogElement.prototype.showModal) return;
    const dialog = document.createElement('dialog');
    dialog.className = 'mp-viewer';
    dialog.setAttribute('aria-label', 'Photos et justificatifs du dossier');
    dialog.innerHTML = `<header class="mp-viewer-bar"><p class="mp-viewer-title" aria-live="polite"></p><a class="mp-viewer-open" target="_blank" rel="noopener noreferrer">Ouvrir le fichier</a><a class="mp-viewer-download" target="_blank" rel="noopener noreferrer">Télécharger</a><button type="button" class="mp-viewer-close" aria-label="Fermer la visionneuse">Fermer ×</button></header><div class="mp-viewer-stage"><button type="button" class="mp-viewer-prev" aria-label="Fichier précédent">‹</button><div class="mp-viewer-content"></div><button type="button" class="mp-viewer-next" aria-label="Fichier suivant">›</button></div><p class="mp-viewer-help">Flèches pour parcourir les fichiers · Échap pour fermer. Si un PDF ne s’affiche pas, utilisez « Ouvrir le fichier ».</p>`;
    document.body.append(dialog);
    const content = dialog.querySelector('.mp-viewer-content');
    const close = dialog.querySelector('.mp-viewer-close');
    let index = 0, opener = null, overflow = '';
    function render() {
        const link = links[index];
        const label = link.dataset.label || 'Fichier';
        dialog.querySelector('.mp-viewer-title').textContent = label + ' — ' + (index + 1) + ' / ' + links.length;
        dialog.querySelector('.mp-viewer-open').href = link.dataset.preview || link.href;
        const download = dialog.querySelector('.mp-viewer-download');
        download.href = link.href; download.hidden = link.dataset.mpViewer !== 'pdf';
        content.replaceChildren();
        if (link.dataset.mpViewer === 'pdf') {
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
    dialog.querySelector('.mp-viewer-prev').addEventListener('click', () => move(-1));
    dialog.querySelector('.mp-viewer-next').addEventListener('click', () => move(1));
    dialog.addEventListener('keydown', event => {
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') { event.preventDefault(); move(event.key === 'ArrowLeft' ? -1 : 1); }
    });
    dialog.addEventListener('close', () => { content.replaceChildren(); document.documentElement.style.overflow = overflow; opener?.focus(); });
})();

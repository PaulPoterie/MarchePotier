/* Réponses uniquement : aucun fichier ni jeton de session dans localStorage. */
window.mpCandidateDraft = function (form) {
  const key = 'mp-candidate-draft:' + form.elements.namedItem('mp_edition').value;
  const duration = 24 * 60 * 60 * 1000;
  const fields = Array.from(form.elements).filter(input =>
    /^(INPUT|TEXTAREA|SELECT)$/.test(input.tagName) &&
    !['hidden', 'file', 'submit', 'button'].includes(input.type) && !input.readOnly &&
    (/^mp_record\[(identity|activity)\]\[/.test(input.name) || input.name === 'mp_photo_consent'));
  const notice = document.createElement('p');
  notice.setAttribute('role', 'status');
  notice.className = 'mp-draft-status';
  form.prepend(notice);
  let expires = 0, stopped = false, timer;
  const normal = 'Vos textes et choix sont sauvegardés dans ce navigateur pendant 24 heures. Utilisez le même appareil pour reprendre votre candidature.';
  function unavailable() { notice.textContent = 'La sauvegarde des réponses est indisponible dans ce navigateur. Gardez cette page ouverte pour conserver votre saisie.'; }
  function clear() {
    stopped = true; clearTimeout(timer);
    try { localStorage.removeItem(key); } catch (_) { /* La saisie reste utilisable. */ }
  }
  function arm() {
    clearTimeout(timer);
    timer = setTimeout(() => { clear(); notice.textContent = 'La sauvegarde de vos réponses a expiré. Vos réponses restent sur cette page ; une nouvelle modification créera un nouveau brouillon.'; }, Math.max(0, expires - Date.now()));
  }
  function save() {
    if (stopped) { stopped = false; expires = 0; }
    if (!expires || expires <= Date.now()) expires = Date.now() + duration;
    const values = fields.map(input => ({ name: input.name, value: input.value, checked: ['checkbox', 'radio'].includes(input.type) ? input.checked : null }));
    try { localStorage.setItem(key, JSON.stringify({ version: 1, expires, values })); notice.textContent = normal; arm(); }
    catch (_) { unavailable(); }
  }
  try {
    // Supprime aussi les brouillons expirés des autres éditions de ce site.
    for (let i = localStorage.length - 1; i >= 0; i--) {
      const itemKey = localStorage.key(i);
      if (!itemKey || !itemKey.startsWith('mp-candidate-draft:')) continue;
      try { const item = JSON.parse(localStorage.getItem(itemKey)); if (!item || !Number.isFinite(item.expires) || item.expires <= Date.now()) localStorage.removeItem(itemKey); }
      catch (_) { localStorage.removeItem(itemKey); }
    }
    const stored = JSON.parse(localStorage.getItem(key));
    notice.textContent = normal;
    if (stored && stored.version === 1 && Array.isArray(stored.values) && stored.expires > Date.now() && stored.expires <= Date.now() + duration) {
      expires = stored.expires;
      fields.forEach(input => {
        const choice = ['checkbox', 'radio'].includes(input.type);
        const item = stored.values.find(v => v && v.name === input.name && (!choice || v.value === input.value));
        if (!item || typeof item.value !== 'string') return;
        if (choice) input.checked = item.checked === true;
        else if (input.tagName !== 'SELECT' || Array.from(input.options).some(o => o.value === item.value)) input.value = item.value;
      });
      notice.textContent = 'Votre brouillon a été restauré. ' + normal;
      arm();
    }
  } catch (_) { unavailable(); }
  form.addEventListener('input', event => { if (fields.includes(event.target)) save(); });
  form.addEventListener('change', event => { if (fields.includes(event.target)) save(); });
  return {
    clear,
    syncExpiry(seconds) {
      if (stopped || !Number.isFinite(seconds) || seconds * 1000 <= Date.now()) return;
      const deadline = seconds * 1000;
      if (!expires || deadline < expires) { expires = deadline; save(); }
    }
  };
};
// Confirmation rendue par le serveur : couvre aussi un retour après interruption.
document.querySelectorAll('.mp-success[data-mp-edition]').forEach(element => {
  try { localStorage.removeItem('mp-candidate-draft:' + element.dataset.mpEdition); } catch (_) {}
});

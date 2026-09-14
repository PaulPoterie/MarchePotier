document.querySelectorAll('.mp-public form').forEach(form => {
  const draft = window.mpCandidateDraft(form);
  const presentation = form.querySelector('[name="mp_record[activity][presentation]"]');
  const wordCounter = form.querySelector('#mp-presentation-count');
  if (presentation && wordCounter) {
    const countWords = () => {
      const count = presentation.value.split(/[\s\u0085]+/u).filter(Boolean).length;
      const exceeded = count > 300;
      wordCounter.hidden = false;
      wordCounter.textContent = `${count} / 300 mots` + (exceeded ? ` — retirez ${count - 300} mot(s) pour envoyer votre candidature.` : '');
      presentation.setCustomValidity(exceeded ? 'Présentation : limitez votre texte à 300 mots maximum.' : '');
      presentation.setAttribute('aria-invalid', String(exceeded));
    };
    presentation.addEventListener('input', countWords);
    countWords();
  }

  const update = () => {
    const professionalStatus = form.querySelector('[name="mp_record[activity][professional_status]"]')?.value || '';
    form.querySelectorAll('[data-status-help]').forEach(help => {
      help.hidden = !help.dataset.statusHelp.split(' ').includes(professionalStatus);
    });
    form.querySelectorAll('[data-parent]').forEach(row => {
      const name = `mp_record[activity][${row.dataset.parent}]`;
      const selected = Array.from(form.elements).filter(input => input.name === name || input.name === `${name}[]`).some(input => input.value === row.dataset.choice && (input.type !== 'checkbox' || input.checked));
      row.hidden = !selected;
      row.querySelectorAll('input,textarea').forEach(input => { input.required = selected; input.disabled = !selected; });
    });
    form.querySelectorAll('[data-multiple]').forEach(group => {
      const inputs = Array.from(group.querySelectorAll('input[type=checkbox]'));
      inputs[0]?.setCustomValidity(inputs.some(input => input.checked) ? '' : 'Choisissez au moins une réponse.');
    });
    form.querySelectorAll('input[type=file]').forEach(input => {
      input.setCustomValidity(input.files[0]?.size > Number(input.dataset.maxSize) ? 'Le fichier dépasse ' + input.dataset.maxLabel + '.' : '');
    });
  };
  form.addEventListener('change', update);
  update();

  if (!window.FormData || !window.XMLHttpRequest || !window.Promise || !window.URL) return;
  const inputs = Array.from(form.querySelectorAll('input[type=file]'));
  const submit = form.querySelector('button[type=submit]');
  const endpoint = new URL(form.action);
  endpoint.hash = '';
  endpoint.searchParams.set('mp_async', '1');
  const notice = document.createElement('p');
  notice.setAttribute('role', 'status');
  notice.className = 'mp-upload-summary';
  notice.textContent = 'Vérification des pièces déjà reçues…';
  const restore = document.createElement('button');
  restore.type = 'button'; restore.textContent = 'Réessayer la connexion'; restore.hidden = true;
  submit.before(notice, restore);
  const help = document.createElement('p');
  help.textContent = 'Les fichiers sont envoyés un par un dès leur sélection. Les pièces reçues sont conservées temporairement pendant 24 heures dans ce navigateur. Cliquez ensuite sur « Envoyer ma candidature » pour valider votre dossier. Gardez la page ouverte pendant les transferts.';
  inputs[0].closest('fieldset').querySelector('p').after(help);
  let ready = false, busy = false, submitting = false, revision = '';
  const states = inputs.map(input => {
    const status = document.createElement('small');
    status.id = input.id + '-status'; status.setAttribute('role', 'status');
    input.setAttribute('aria-describedby', [input.getAttribute('aria-describedby'), status.id].filter(Boolean).join(' '));
    const progress = document.createElement('progress');
    progress.max = 100; progress.value = 0; progress.hidden = true;
    progress.setAttribute('aria-label', 'Progression : ' + input.closest('.mp-field').querySelector('label').textContent);
    const retry = document.createElement('button');
    retry.type = 'button'; retry.className = 'mp-upload-retry'; retry.textContent = 'Réessayer cette pièce'; retry.hidden = true;
    const preview = document.createElement('img');
    preview.className = 'mp-upload-preview'; preview.alt = 'Aperçu du fichier choisi'; preview.hidden = true;
    input.after(preview, status, progress, retry);
    const state = {input, status, progress, retry, preview, phase: 'empty', file: null, url: ''};
    retry.addEventListener('click', () => { state.phase = 'queued'; retry.hidden = true; pump(); });
    input.addEventListener('change', () => {
      const file = input.files[0];
      if (!file) return;
      state.file = file; state.phase = 'failed'; input.required = true;
      if (state.url) URL.revokeObjectURL(state.url);
      preview.hidden = true; retry.hidden = true; progress.hidden = true;
      const documentSlot = input.name === 'status' || input.name === 'insurance';
      const pdf = /\.pdf$/i.test(file.name);
      if (file.size === 0 || file.size > Number(input.dataset.maxSize)) {
        input.setCustomValidity('Choisissez un fichier non vide de ' + input.dataset.maxLabel + ' maximum.');
        status.textContent = input.validationMessage; refresh(); return;
      }
      if (!(documentSlot ? /\.(pdf|jpe?g|png|webp)$/i : /\.(jpe?g|png|webp)$/i).test(file.name)) {
        input.setCustomValidity(documentSlot ? 'Choisissez un PDF ou une image JPEG, PNG ou WebP. Pour une photo HEIC, exportez-la en JPEG.' : 'Choisissez une photo JPEG, PNG ou WebP. Pour une photo HEIC, exportez-la en JPEG.');
        status.textContent = input.validationMessage; refresh(); return;
      }
      input.setCustomValidity('');
      if (!pdf) { state.url = URL.createObjectURL(file); preview.src = state.url; preview.hidden = false; }
      state.phase = 'queued'; status.textContent = 'En attente…'; pump();
    });
    return state;
  });
  function tokens(operation) {
    const data = new FormData();
    ['mp_edition', 'mp_issued', 'mp_random', 'mp_signature', 'mp_nonce', 'mp_fax'].forEach(name => data.append(name, form.elements.namedItem(name).value));
    data.append('mp_upload_operation', operation);
    return data;
  }
  function request(data, onProgress) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', endpoint.href);
      xhr.timeout = 10 * 60 * 1000;
      if (onProgress) xhr.upload.onprogress = event => onProgress(event.lengthComputable ? Math.min(100, Math.round(event.loaded / event.total * 100)) : null);
      xhr.onload = () => {
        let result;
        try { result = JSON.parse(xhr.responseText); } catch (_) {
          reject(new Error(xhr.status === 413 ? 'Le serveur refuse cette taille de fichier. Réduisez le fichier et réessayez.' : 'Réponse du serveur interrompue ou illisible. Réessayez ; les pièces reçues sont conservées.')); return;
        }
        if (xhr.status < 200 || xhr.status >= 300 || !result.success) { reject(new Error(result.data?.message || 'Envoi impossible. Réessayez.')); return; }
        resolve(result.data);
      };
      xhr.onerror = xhr.ontimeout = () => reject(new Error('Connexion interrompue ou trop lente. Vérifiez votre connexion puis réessayez.'));
      xhr.send(data);
    });
  }
  function refresh() {
    submit.disabled = !ready || busy || submitting || states.some(state => state.phase === 'queued');
    states.forEach(state => { state.input.disabled = !ready || submitting || state.phase === 'sending'; });
    if (ready && !submitting) notice.textContent = states.filter(state => state.phase === 'done').length + ' / ' + states.length + ' pièces reçues. ' + (busy ? 'Transfert en cours…' : 'Validez ensuite votre candidature avec le bouton ci-dessous.');
  }
  async function pump() {
    refresh();
    if (!ready || busy || submitting) return;
    const state = states.find(item => item.phase === 'queued');
    if (!state) return;
    busy = true; state.phase = 'sending'; state.progress.hidden = false; state.progress.value = 0;
    state.status.textContent = 'Envoi en cours…'; refresh();
    const data = tokens('upload'); data.append('mp_slot', state.input.name); data.append(state.input.name, state.file);
    try {
      const result = await request(data, percent => {
        if (percent === null) state.progress.removeAttribute('value'); else state.progress.value = percent;
        state.status.textContent = percent === 100 ? 'Transfert terminé — vérification du fichier…' : 'Envoi en cours' + (percent === null ? '…' : ' : ' + percent + ' %');
      });
      draft.syncExpiry(result.expires);
      revision = result.revision;
      state.phase = 'done'; state.input.required = false; state.input.setCustomValidity('');
      state.status.textContent = 'Reçu : ' + result.files[state.input.name];
      state.progress.value = 100;
    } catch (error) {
      state.phase = 'failed'; state.status.textContent = error.message; state.retry.hidden = false;
    } finally { busy = false; state.progress.hidden = true; refresh(); pump(); }
  }
  async function recover() {
    restore.hidden = true; refresh();
    try {
      const result = await request(tokens('status'));
      if (result.redirect) { draft.clear(); window.location.assign(result.redirect); return; }
      draft.syncExpiry(result.expires);
      revision = result.revision;
      states.forEach(state => {
        if (result.files[state.input.name]) {
          state.phase = 'done'; state.input.required = false;
          state.status.textContent = 'Déjà reçu : ' + result.files[state.input.name];
        }
      });
      ready = true; refresh();
    } catch (error) { notice.textContent = error.message; restore.hidden = false; }
  }
  restore.addEventListener('click', recover);
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (!ready || busy || submitting) return;
    if (states.some(state => state.phase !== 'done')) {
      notice.textContent = 'Ajoutez les pièces manquantes ou réessayez les pièces en échec avant de valider.';
      states.find(state => state.phase !== 'done').input.focus(); return;
    }
    // Retirer tous les fichiers : la validation finale ne renvoie que les réponses.
    const data = new FormData(form);
    inputs.forEach(input => data.delete(input.name));
    data.append('mp_revision', revision);
    submitting = true; refresh(); notice.textContent = 'Enregistrement de votre candidature…';
    try {
      const result = await request(data);
      submitting = false;
      if (!result.redirect) throw new Error('Confirmation du serveur incomplète. Réessayez.');
      draft.clear();
      window.location.assign(result.redirect);
    } catch (error) {
      submitting = false; refresh(); notice.textContent = error.message + ' Vos réponses restent dans ce formulaire.';
    }
  });
  window.addEventListener('beforeunload', event => {
    if (busy || submitting || states.some(state => state.phase === 'queued')) { event.preventDefault(); event.returnValue = ''; }
  });
  recover();
});

document.addEventListener('click', (event) => {
    // Le raccourci de ligne laisse les liens, champs et sélections de texte fonctionner normalement.
    const row = event.target.closest('tr[data-mp-dossier]');
    if (!row || event.target.closest('a,button,input,select,textarea,.mp-review-person') || window.getSelection().toString() || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    window.location.assign(row.dataset.mpDossier);
});

const applicationSort = document.querySelector('#mp-sort');
if (applicationSort) {
    // Le formulaire ne contient pas paged : changer le tri revient à la première page.
    applicationSort.addEventListener('change', () => applicationSort.form.requestSubmit());
}

const myScore = document.querySelector('.mp-examiner #mp-my-score');
const voteState = document.querySelector('.mp-examiner #mp-vote-state');
if (myScore && voteState) {
    const savedText = voteState.textContent;
    myScore.addEventListener('change', () => {
        const changed = myScore.value !== myScore.dataset.savedScore;
        voteState.textContent = changed ? 'Modification non enregistrée — cliquez sur « Valider ma note ».' : savedText;
        voteState.classList.toggle('mp-vote-state-pending', changed || myScore.dataset.savedScore === '');
    });
}

document.addEventListener('click', (event) => {
    // Le raccourci de ligne laisse les liens, champs et sélections de texte fonctionner normalement.
    const row = event.target.closest('tr[data-marcpo-dossier]');
    if (!row || event.target.closest('a,button,input,select,textarea,.marcpo-review-person') || window.getSelection().toString() || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    window.location.assign(row.dataset.marcpoDossier);
});

const applicationSort = document.querySelector('#marcpo-sort');
if (applicationSort) {
    // Le formulaire ne contient pas paged : changer le tri revient à la première page.
    applicationSort.addEventListener('change', () => applicationSort.form.requestSubmit());
}

const myScore = document.querySelector('.marcpo-examiner #marcpo-my-score');
const voteState = document.querySelector('.marcpo-examiner #marcpo-vote-state');
if (myScore && voteState) {
    const savedText = voteState.textContent;
    myScore.addEventListener('change', () => {
        const changed = myScore.value !== myScore.dataset.savedScore;
        voteState.textContent = changed ? 'Modification non enregistrée — cliquez sur « Valider ma note ».' : savedText;
        voteState.classList.toggle('marcpo-vote-state-pending', changed || myScore.dataset.savedScore === '');
    });
}

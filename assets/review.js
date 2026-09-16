document.addEventListener('click', (event) => {
    const row = event.target.closest('tr[data-mp-dossier]');
    if (!row || event.target.closest('a,button,input,select,textarea,.mp-review-person') || window.getSelection().toString() || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    window.location.assign(row.dataset.mpDossier);
});

// Sur téléphone, garder l’action personnelle avant les photos sans dérouler tout le jury.
if (window.matchMedia('(max-width: 782px)').matches) {
    document.querySelectorAll('.mp-examiner .mp-jury-details').forEach((details) => { details.open = false; });
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

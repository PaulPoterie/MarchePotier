/* Sélecteur natif WordPress. */
document.addEventListener('click', (event) => {
    const button = event.target.closest('.mp-media-select, .mp-media-remove');
    if (!button) return;
    const field = button.closest('.mp-media-field');
    const input = field.querySelector('input');
    const label = field.querySelector('.mp-media-name');
    if (button.classList.contains('mp-media-remove')) {
        input.value = '0';
        label.textContent = 'Aucun fichier sélectionné';
        return;
    }
    const picker = wp.media({ title: 'Choisir un fichier public pour cette édition', button: { text: 'Utiliser ce fichier' }, library: { type: button.dataset.type }, multiple: false });
    picker.on('select', () => {
        const file = picker.state().get('selection').first().toJSON();
        input.value = String(file.id);
        label.textContent = file.title || file.filename;
    });
    picker.open();
});

// Le code suit l’année saisie, y compris avant le premier enregistrement.
const editionYear = document.getElementById('mp-year');
const formShortcode = document.getElementById('mp-form-shortcode');
if (editionYear && formShortcode) {
    const updateShortcode = () => {
        formShortcode.value = /^[2-9][0-9]{3}$/.test(editionYear.value)
            ? '[inscription_potier edition="' + editionYear.value + '"]' : '';
    };
    editionYear.addEventListener('input', updateShortcode);
    formShortcode.addEventListener('focus', () => formShortcode.select());
    updateShortcode();
}

// L’explication est nécessaire uniquement lorsqu’un tarif réduit est proposé.
const reducedPrice = document.getElementById('mp-reduced_price');
const reducedDescription = document.getElementById('mp-reduced_description');
if (reducedPrice && reducedDescription) {
    const updateReduced = () => {
        reducedDescription.required = reducedPrice.value !== '';
        const label = document.querySelector('label[for="mp-reduced_description"]');
        if (label) label.textContent = 'Conditions du tarif réduit' + (reducedDescription.required ? ' *' : ' (facultatif)');
    };
    reducedPrice.addEventListener('input', updateReduced);
    updateReduced();
}

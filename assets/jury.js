document.addEventListener('DOMContentLoaded', function () {
	const mode = document.getElementById('marcpo-jury-mode');
	const voters = document.getElementById('marcpo-jury-voters');
	if (mode && voters) {
		const updateVoters = function () {
			voters.hidden = mode.value !== 'multiple';
			voters.disabled = voters.hidden;
		};
		mode.addEventListener('change', updateVoters);
		updateVoters();
	}
	const invite = document.getElementById('marcpo-administrator-invite');
	if (invite) invite.addEventListener('click', function (event) {
		const form = invite.form;
		if (!form) return;
		// Le bouton natif gère l'autosauvegarde, le verrou et l'avertissement de départ.
		// En création, « Enregistrer le brouillon » évite de publier en invitant.
		const save = form.querySelector('#save-post') || form.querySelector('#publish');
		if (!save) return;
		event.preventDefault();
		if (save.disabled || save.classList.contains('disabled') || !form.reportValidity()) return;
		const intent = document.createElement('input');
		intent.type = 'hidden'; intent.name = invite.name; intent.value = invite.value;
		form.appendChild(intent);
		try { save.click(); } finally { intent.remove(); }
		// Retirer l'intention même si un autre contrôle bloque l'envoi : une sauvegarde
		// ordinaire ultérieure ne doit pas envoyer une invitation à notre insu.
	});
	let index = 0;
	document.querySelectorAll('.marcpo-jury-group').forEach(group => {
		const table = group.querySelector('tbody');
		const template = group.querySelector('template');
		const add = group.querySelector('.marcpo-jury-add');
		add.addEventListener('click', function () {
			const row = template.content.cloneNode(true);
			// Clé provisoire distincte des IDs de comptes déjà enregistrés par WordPress.
			row.querySelectorAll('[name]').forEach(input => { input.name = input.name.replace('__INDEX__', 'new_' + index); });
			index++;
			table.appendChild(row);
			table.lastElementChild.querySelector('input[type="text"]').focus();
		});
		table.addEventListener('click', function (event) {
			if (event.target.closest('.marcpo-jury-remove')) event.target.closest('tr').remove();
		});
	});
});

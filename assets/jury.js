document.addEventListener('DOMContentLoaded', function () {
	let index = 0;
	document.querySelectorAll('.mp-jury-group').forEach(group => {
		const table = group.querySelector('tbody');
		const template = group.querySelector('template');
		const add = group.querySelector('.mp-jury-add');
		add.addEventListener('click', function () {
			const row = template.content.cloneNode(true);
			// Clé provisoire distincte des IDs de comptes déjà enregistrés par WordPress.
			row.querySelectorAll('[name]').forEach(input => { input.name = input.name.replace('__INDEX__', 'new_' + index); });
			index++;
			table.appendChild(row);
			table.lastElementChild.querySelector('input[type="text"]').focus();
		});
		table.addEventListener('click', function (event) {
			if (event.target.closest('.mp-jury-remove')) event.target.closest('tr').remove();
		});
	});
});

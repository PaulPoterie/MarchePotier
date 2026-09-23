document.addEventListener('DOMContentLoaded', function () {
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

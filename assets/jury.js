document.addEventListener('DOMContentLoaded', function () {
	let index = 0;
	document.querySelectorAll('.mp-jury-group').forEach(group => {
	const table = group.querySelector('tbody');
	const template = group.querySelector('template');
	const add = group.querySelector('.mp-jury-add');
	add.addEventListener('click', function () {
		const row = template.content.cloneNode(true);
		row.querySelectorAll('[name]').forEach(input => { input.name = input.name.replace('__INDEX__', 'new_' + index); });
		index++;
		table.appendChild(row);
		table.lastElementChild.querySelector('input[type="text"]').focus();
	});
	table.addEventListener('click', function (event) {
		if (event.target.closest('.mp-jury-remove')) event.target.closest('tr').remove();
		const move = event.target.closest('.mp-jury-move');
		if (!move) return;
		const row = move.closest('tr');
		const target = group.dataset.group === 'members' ? 'administrators' : 'members';
		row.querySelectorAll('[name]').forEach(input => {
			input.name = input.name.replace(/^mp_jury\[[^\]]+\]\[[^\]]+\]/, 'mp_jury[' + target + '][moved_' + index + ']');
		});
		index++;
		move.textContent = target === 'members' ? 'Passer dans le tableau administrateur' : 'Passer dans le tableau votant';
		document.querySelector('#mp-jury-' + target + ' tbody').appendChild(row);
		row.querySelector('input[type="text"]').focus();
	});
	});
});

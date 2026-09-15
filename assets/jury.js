document.addEventListener('DOMContentLoaded', function () {
	const table = document.querySelector('#mp-jury-members tbody');
	const template = document.getElementById('mp-jury-template');
	const add = document.getElementById('mp-jury-add');
	if (!table || !template || !add) return;
	let index = 0;
	add.addEventListener('click', function () {
		const row = template.content.cloneNode(true);
		row.querySelectorAll('[name]').forEach(input => { input.name = input.name.replace('__INDEX__', 'new_' + index); });
		index++;
		table.appendChild(row);
		table.lastElementChild.querySelector('input[type="text"]').focus();
	});
	table.addEventListener('click', function (event) {
		if (event.target.closest('.mp-jury-remove')) event.target.closest('tr').remove();
	});
});

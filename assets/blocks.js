(function (wp) {
	'use strict';
	const el = wp.element.createElement;
	const {useEffect, useState} = wp.element;
	const {InspectorControls, useBlockProps} = wp.blockEditor;
	const {PanelBody, Placeholder, SelectControl, Notice, Spinner, Button} = wp.components;
	let request;
	function editions(refresh) {
		if (!request || refresh) request = wp.apiFetch({path: '/marche-potier/v1/editions'});
		return request;
	}
	function EditionBlock(props) {
		const [rows, setRows] = useState(null);
		const [error, setError] = useState('');
		const [revision, setRevision] = useState(0);
		const form = props.name === 'marche-potier/formulaire-candidature';
		const title = form ? 'Formulaire de candidature' : 'Présentation de la sélection';
		const selected = rows && rows.find(row => row.id === props.attributes.editionId);
		useEffect(() => {
			let active = true;
			setError('');
			setRows(null);
			editions(revision > 0).then(data => {
				if (active) setRows(data);
			}).catch(() => {
				if (active) setError('Impossible de charger les éditions. Vérifiez votre connexion et votre accès à Marché Potier.');
			});
			return () => { active = false; };
		}, [revision]);
		const options = [{label: 'Choisir une édition…', value: 0}];
		if (rows) rows.forEach(row => options.push({label: row.label + (row.published ? '' : ' — non publiée'), value: row.id}));
		if (rows && props.attributes.editionId && !selected) options.push({label: 'Édition supprimée ou indisponible — choisissez-en une autre', value: props.attributes.editionId});
		function control() {
			return el(SelectControl, {
				label: 'Édition à afficher', value: props.attributes.editionId, options,
				disabled: !rows,
				onChange: value => props.setAttributes({editionId: Number(value)}),
				help: 'Choisissez le titre et l’année du marché concerné.',
				__nextHasNoMarginBottom: true, __next40pxDefaultSize: true
			});
		}
		let notice = '';
		if (rows && !rows.length) notice = 'Créez et enregistrez une édition dans Marché Potier → Éditions, puis actualisez la liste.';
		else if (rows && props.attributes.editionId && !selected) notice = 'L’édition choisie n’est plus disponible. Sélectionnez une autre édition.';
		else if (selected && !selected.published) notice = 'Publiez cette édition pour que son contenu soit accessible sur le site.';
		else if (selected && !form && !selected.selectionPublic) notice = 'La sélection est masquée. Dans l’édition, rubrique « Affichage sur le site », autorisez son affichage lorsque vous êtes prêt.';
		return el('div', useBlockProps({className: 'mp-edition-block'}),
			el(InspectorControls, null, el(PanelBody, {title: 'Édition du marché'}, control())),
			el(Placeholder, {icon: form ? 'feedback' : 'groups', label: title},
				el('div', {className: 'mp-edition-block-content'},
					!rows && !error && el(Spinner),
					error && el(Notice, {status: 'error', isDismissible: false}, error),
					control(),
					notice && el(Notice, {status: 'warning', isDismissible: false}, notice),
					selected && el('p', {className: 'mp-edition-block-summary'}, el('strong', null, selected.label)),
					el('p', null, form ? 'Le formulaire complet apparaît sur la page publiée, pendant les dates d’inscription définies dans l’édition.' : 'La page publiée affiche les potiers sélectionnés ayant autorisé leur présentation, dès que la sélection publique est activée.'),
					el(Button, {variant: 'secondary', onClick: () => setRevision(value => value + 1)}, 'Actualiser les éditions')
				)
			)
		);
	}
	[
		['marche-potier/formulaire-candidature', 'Formulaire de candidature', 'feedback'],
		['marche-potier/presentation-selection', 'Présentation de la sélection', 'groups']
	].forEach(([name, title, icon]) => wp.blocks.registerBlockType(name, {
		apiVersion: 3, title, icon, category: 'widgets',
		description: 'Affiche le contenu de l’édition choisie du Marché Potier.',
		keywords: ['marché', 'potier', 'édition'],
		attributes: {editionId: {type: 'integer', default: 0}},
		supports: {html: false, multiple: false, reusable: false},
		edit: EditionBlock, save: () => null
	}));
})(window.wp);

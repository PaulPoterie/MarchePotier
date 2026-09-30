<?php
/** Admin UX regressions, using isolated fixtures and intercepted emails from votes-local.php. */
use MarchePotier\{Jury,Votes,Records,Editions,Plugin};
if ( ! isset( $state, $manager, $checks ) || PHP_SAPI !== 'cli' ) { exit; }
wp_set_current_user( $manager );
$ui_juror = marcpo_user( 'Historique', 'marcpo_juror' );
$ui_edition = marcpo_post( 'marcpo_edition', 'Édition Interface' );
$ui_hidden = marcpo_post( 'marcpo_edition', 'Édition réservée UI' );
$ui_raw = array( 'mode' => 'multiple', 'revision' => '', 'administrator' => array( 'name' => 'Responsable', 'email' => get_userdata( $manager )->user_email ), 'members' => array( array( 'name' => 'Jury historique', 'email' => get_userdata( $ui_juror )->user_email, 'active' => '1' ), array( 'name' => 'Inactif', 'email' => get_userdata( $a )->user_email, 'active' => '0' ) ) );
marcpo_check( true === marcpo_config( $ui_edition, $ui_raw ), 'Équipe de test active et inactive préparée' );
update_post_meta( $ui_edition, '_marcpo_edition_settings', array( 'exhibitors' => '30' ) );
$ui_apps = array();
foreach ( array(
	array( $ui_edition, 'ÉrableHistorique', 'Anaïs', 'anais.historique@example.test', 'selected' ),
	array( $ui_edition, 'AutreHistorique', 'Malo', 'atelier+test@example.test', 'pending' ),
	array( $ui_hidden, 'ConfidentielHistorique', 'Secret', 'secret.historique@example.test', 'selected' ),
) as [$ed, $last, $first, $email, $decision] ) {
	$person = marcpo_post( 'marcpo_potier', 'Identité historique UI' );
	$identity = array( 'last_name' => $last, 'first_name' => $first, 'email' => $email, 'city' => 'VilleNonRecherchable' );
	update_post_meta( $person, Records::META, array( 'identity' => $identity ) );
	$application = marcpo_post( 'marcpo_candidature', 'TitreNonRecherchable' );
	update_post_meta( $application, Records::META, array( 'edition_id' => $ed, 'potier_id' => $person, 'identity' => $identity, 'activity' => array( 'presentation' => 'TexteNonRecherchable' ), 'decision' => $decision ) );
	$ui_apps[] = $application;
}
wp_set_current_user( $ui_juror );
marcpo_check( true === Votes::record( $ui_apps[0], '4' ), 'Note de test enregistrée avant changement de mode' );
$history_render = static function ( mixed $search ): array {
	$_GET = array( 's' => wp_slash( $search ) );
	ob_start(); Records::history(); $html = ob_get_clean(); $_GET = array();
	preg_match( '/<tbody>(.*?)<\/tbody>/s', $html, $body );
	return array( $html, $body[1] ?? '' );
};
foreach ( array( 'ERABLEHISTORIQUE ANAIS', 'ANAIS.HISTORIQUE@EXAMPLE.TEST', 'Anaïs ÉrableHistorique' ) as $term ) {
	[$html, $body] = $history_render( $term );
	marcpo_check( str_contains( $body, 'ÉrableHistorique' ) && ! str_contains( $body, 'AutreHistorique' ) && ! str_contains( $body, 'ConfidentielHistorique' ), 'Historique : mots combinés, nom/prénom/email, casse et accents — ' . $term );
	marcpo_check( str_contains( $html, '1 / 30' ) && str_contains( $html, '2 candidatures' ) && ! str_contains( $html, 'Édition réservée UI' ), 'Totaux complets de l’édition autorisée, aucune colonne confidentielle' );
}
foreach ( array( 'VilleNonRecherchable', 'TexteNonRecherchable', 'TitreNonRecherchable', 'Édition Interface', 'ConfidentielHistorique', '.*' ) as $term ) {
	[$html, $body] = $history_render( $term );
	marcpo_check( str_contains( $body, 'Aucun potier' ) && ! str_contains( $body, 'ÉrableHistorique' ), 'La recherche ne parcourt pas les autres champs, ni les dossiers cachés, ni des expressions régulières — ' . $term );
}
[$html, $body] = $history_render( 'atelier+test' );
marcpo_check( str_contains( $body, 'AutreHistorique' ) && ! str_contains( $body, 'ÉrableHistorique' ), 'Le signe plus dans un email est recherché littéralement' );
[$html, $body] = $history_render( array( 'malformed' ) );
marcpo_check( str_contains( $body, 'ÉrableHistorique' ) && str_contains( $body, 'AutreHistorique' ), 'Paramètre de recherche tableau ignoré sans erreur de type' );
[$html] = $history_render( '" autofocus onfocus="alert(1)' );
marcpo_check( ! str_contains( $html, 'value="" autofocus' ) && str_contains( $html, '&quot; autofocus' ), 'Recherche échappée dans l’attribut de saisie' );
[$html] = $history_render( str_repeat( 'a', 400 ) );
marcpo_check( str_contains( $html, 'value="' . str_repeat( 'a', 200 ) . '"' ) && ! str_contains( $html, str_repeat( 'a', 201 ) ), 'Recherche limitée à 200 caractères côté serveur' );

ob_start(); Plugin::render_dashboard(); $ui_home = ob_get_clean();
marcpo_check( ! str_contains( $ui_home, 'edit.php?post_type=marcpo_edition' ) && str_contains( $ui_home, 'page=marcpo-gestion' ), 'Accueil votant : candidatures accessibles, gestion des éditions absente' );
wp_set_current_user( $manager );
ob_start(); Plugin::render_dashboard(); $ui_home = ob_get_clean();
$ui_header = get_file_data( dirname( __DIR__ ) . '/marche-potier.php', array( 'Version' => 'Version' ) );
marcpo_check( str_contains( $ui_home, 'Gestion de Marché Potier' ) && str_contains( $ui_home, 'fait par Poterie Navarraise' ) && str_contains( $ui_home, 'edit.php?post_type=marcpo_edition' ) && str_contains( $ui_home, rawurlencode( 'Gestion de Marché Potier — version ' . $ui_header['Version'] ) ), 'Accueil organisateur : accès autorisés, signature discrète et email avec version courante' );
Plugin::dashboard_assets( 'dashboard' );
marcpo_check( ! wp_style_is( 'marcpo-dashboard', 'enqueued' ), 'CSS de l’accueil absent du tableau de bord WordPress' );
Plugin::dashboard_assets( 'toplevel_page_marche-potier' );
marcpo_check( wp_style_is( 'marcpo-dashboard', 'enqueued' ), 'CSS de l’accueil chargé uniquement sur sa page' );
ob_start(); Editions::title_help( get_post( $ui_edition ) ); $ui_help = ob_get_clean();
marcpo_check( str_contains( $ui_help, 'Marché potier 20XX' ), 'Conseil de titre affiché pour une édition' );
ob_start(); Editions::title_help( get_post( $ui_apps[0] ) ); $ui_help = ob_get_clean();
marcpo_check( '' === $ui_help, 'Conseil de titre absent des autres contenus' );

$ui_members = Jury::settings( $ui_edition )['members']; $ui_notes = Votes::all( array( $ui_apps[0] ) ); $ui_mail_count = count( $mails );
$ui_raw = marcpo_raw( $ui_edition ); unset( $ui_raw['members'] ); $ui_raw['mode'] = 'simple';
marcpo_check( true === marcpo_config( $ui_edition, $ui_raw ) && Jury::settings( $ui_edition )['members'] === $ui_members && Votes::all( array( $ui_apps[0] ) ) === $ui_notes && count( $mails ) === $ui_mail_count, 'Mode simple, fieldset non soumis : affectations actives/inactives et notes conservées, aucune invitation' );
ob_start(); Jury::box( get_post( $ui_edition ) ); $ui_box = ob_get_clean();
marcpo_check( str_contains( $ui_box, 'id="marcpo-jury-voters" hidden disabled' ), 'Mode simple : groupe des votants masqué dès le rendu serveur' );
$ui_raw = marcpo_raw( $ui_edition ); unset( $ui_raw['members'] ); $ui_raw['mode'] = 'multiple';
marcpo_check( true === marcpo_config( $ui_edition, $ui_raw ) && Jury::settings( $ui_edition )['members'] === $ui_members && Votes::all( array( $ui_apps[0] ) ) === $ui_notes, 'Retour aux votes multiples sans JS : aucun membre ni vote perdu' );
ob_start(); Jury::box( get_post( $ui_edition ) ); $ui_box = ob_get_clean();
marcpo_check( str_contains( $ui_box, 'id="marcpo-jury-voters"><legend>' ), 'Mode multiple : groupe des votants affiché et actif' );
$ui_raw = marcpo_raw( $ui_edition ); unset( $ui_raw['members'] ); $ui_raw['mode'] = 'simple'; $ui_raw['administrator'] = array( 'name' => 'Jury promu', 'email' => get_userdata( $ui_juror )->user_email );
marcpo_check( true === marcpo_config( $ui_edition, $ui_raw ) && Jury::administrator( $ui_edition ) === $ui_juror && Votes::all( array( $ui_apps[0] ) ) === $ui_notes, 'Administrateur remplacé par un votant masqué : promotion sans doublon et note conservée' );
wp_set_current_user( 0 );
marcpo_denied( array( Records::class, 'history' ), 'Historique et recherche refusés au visiteur anonyme' );
marcpo_denied( array( Plugin::class, 'render_dashboard' ), 'Accueil refusé au visiteur anonyme' );
wp_set_current_user( 1 ); $_GET = array(); $_POST = array();

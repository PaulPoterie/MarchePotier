<?php
/** Intégration CLI sur le site Local nommé, emails interceptés, fixtures propres. */
if ( PHP_SAPI !== 'cli' || empty( $argv[1] ) ) { exit( "Usage: votes-local.php <racine WordPress locale> [--keep|--cleanup]\n" ); }
$root = realpath( $argv[1] );
if ( ! $root || basename( dirname( dirname( $root ) ) ) !== 'marche-potier-test' ) { exit( "Site de test attendu.\n" ); }
define( 'DISABLE_WP_CRON', true );
// Charge exclusivement le code de cette branche, sans changer les extensions du site.
define( 'WP_PLUGIN_DIR', dirname( __DIR__ ) );
$_SERVER['HTTP_HOST'] = 'marche-potier-test.local'; $_SERVER['REQUEST_METHOD'] = 'GET';
require $root . '/wp-load.php';
if ( wp_parse_url( home_url(), PHP_URL_HOST ) !== 'marche-potier-test.local' ) { exit( "Site inattendu.\n" ); }
require dirname( __DIR__ ) . '/marche-potier.php';
use MarchePotier\{Plugin,Jury,Votes,Records,Editions,Review,Notifications,PrivateFiles,CsvExport,SubmissionLock,Blocks};
Plugin::boot(); Editions::register(); Records::register(); Blocks::register();
require_once ABSPATH . 'wp-admin/includes/user.php';
$manifest = dirname( __DIR__, 2 ) . '/votes-fixtures.json';
$mails = array();
add_filter( 'pre_wp_mail', static function ( $pre, $mail ) use ( &$mails ) { $mails[] = $mail; return true; }, 100, 2 );
if ( '--worker' === ( $argv[2] ?? '' ) ) {
	wp_set_current_user( (int) $argv[3] );
	$result = Votes::record( (int) $argv[4], $argv[5] );
	exit( true === $result ? 0 : 1 );
}
wp_set_current_user( 1 );
if ( ! current_user_can( 'manage_options' ) ) { exit( "Administrateur local manquant.\n" ); }
Editions::install_permissions(); Records::permissions(); Jury::install(); Votes::install();
wp_set_current_user( 0 ); wp_set_current_user( 1 );
function mp_cleanup( array $state ): void {
	wp_set_current_user( 1 ); $_POST = array();
	// Inclut les contenus créés dans le navigateur avec les comptes fictifs de ce test.
	if ( ! empty( $state['users'] ) ) {
		$browser_posts = get_posts( array( 'post_type' => array( 'post', 'page', 'mp_edition', 'mp_candidature' ), 'post_status' => array_values( get_post_stati() ), 'author__in' => $state['users'], 'posts_per_page' => -1 ) );
		foreach ( $browser_posts as $post ) {
			if ( 'auto-draft' === $post->post_status || str_starts_with( $post->post_title, '[TEST VOTES]' ) ) { wp_delete_post( $post->ID, true ); }
		}
	}
	foreach ( array_reverse( $state['posts'] ?? array() ) as $id ) { if ( get_post_meta( $id, '_mp_vote_test', true ) === $state['run'] ) { wp_delete_post( $id, true ); } }
	foreach ( $state['users'] ?? array() as $uid ) { if ( get_user_meta( $uid, '_mp_vote_test', true ) === $state['run'] ) { wp_delete_user( $uid ); } }
}
if ( '--cleanup' === ( $argv[2] ?? '' ) ) {
	if ( is_file( $manifest ) ) { mp_cleanup( json_decode( file_get_contents( $manifest ), true ) ); unlink( $manifest ); }
	exit( "Fixtures retirées.\n" );
}
if ( is_file( $manifest ) ) { exit( "Fixtures précédentes présentes : utiliser --cleanup.\n" ); }
$state = array( 'run' => wp_generate_uuid4(), 'posts' => array(), 'users' => array() ); $checks = 0;
function mp_state(): void { global $state, $manifest; file_put_contents( $manifest, json_encode( $state, JSON_PRETTY_PRINT ) ); }
function mp_check( bool $ok, string $label ): void { global $checks; if ( ! $ok ) { throw new RuntimeException( $label ); } echo 'OK ' . ++$checks . ' : ' . $label . "\n"; }
function mp_post( string $type, string $title ): int {
	global $state; $_POST = array();
	$id = wp_insert_post( array( 'post_type' => $type, 'post_title' => '[TEST VOTES] ' . $title, 'post_status' => 'publish' ), true );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
	update_post_meta( $id, '_mp_vote_test', $state['run'] ); $state['posts'][] = $id; mp_state(); return $id;
}
function mp_user( string $name, string $role ): int {
	global $state;
	$login = 'mpvote_' . substr( $state['run'], 0, 8 ) . '_' . strtolower( $name ); $password = wp_generate_password( 28, false );
	$uid = wp_insert_user( array( 'user_login' => $login, 'user_email' => $login . '@example.test', 'display_name' => $name, 'user_pass' => $password, 'role' => $role ) );
	if ( is_wp_error( $uid ) ) { throw new RuntimeException( $uid->get_error_message() ); }
	update_user_meta( $uid, '_mp_vote_test', $state['run'] ); $state['users'][] = $uid;
	$state['logins'][ $name ] = array( 'login' => $login, 'password' => $password ); mp_state(); return $uid;
}
function mp_config( int $edition, array $raw ): true|WP_Error {
	if ( ! SubmissionLock::acquire() ) { throw new RuntimeException( 'Verrou indisponible' ); }
	try { return Jury::configure( $edition, $raw ); } finally { SubmissionLock::release(); }
}
function mp_raw( int $edition ): array {
	$data = Jury::settings( $edition ); $rows = array(); $administrator = array();
	foreach ( $data['members'] as $uid => $member ) {
		$row = array( 'user_id' => (string) $uid, 'name' => $member['name'], 'email' => get_userdata( $uid )->user_email, 'active' => $member['active'] ? '1' : '0' );
		if ( 'administrator' === $member['kind'] ) { $administrator = array( 'name' => $row['name'], 'email' => $row['email'] ); } else { $rows[] = $row; }
	}
	return array( 'revision' => $data['revision'], 'mode' => $data['mode'], 'members' => $rows, 'administrator' => $administrator );
}
class MPTestDenied extends RuntimeException {}
class MPTestRedirect extends RuntimeException {}
add_filter( 'wp_die_handler', static fn() => static function ( $message ) { throw new MPTestDenied( strip_tags( (string) $message ) ); } );
function mp_denied( callable $fn, string $label ): void {
	$denied = false; ob_start(); try { $fn(); } catch ( MPTestDenied $e ) { $denied = true; } finally { ob_end_clean(); } mp_check( $denied, $label );
}
$keep = '--keep' === ( $argv[2] ?? '' ); $passed = false;
try {
	mp_check( get_option( 'mp_votes_schema_version' ) === '1', 'Table des votes installée' );
	$a = mp_user( 'Alice', 'subscriber' ); $b = mp_user( 'Bruno', 'mp_juror' ); $outsider = mp_user( 'Externe', 'subscriber' );
	$manager = mp_user( 'Responsable', 'mp_organizer' );
	$edition = mp_post( 'mp_edition', 'Jury 2098' ); $other = mp_post( 'mp_edition', 'Édition confidentielle 2099' );
	$settings = array( 'year' => '2098', 'opens' => '2020-01-01T00:00', 'closes' => '2098-12-31T23:00', 'exhibitors' => '30' );
	update_post_meta( $edition, '_mp_edition_settings', $settings );
	update_post_meta( $other, '_mp_edition_settings', array_merge( $settings, array( 'year' => '2099' ) ) );
	mp_check( Jury::settings( $edition )['mode'] === 'simple', 'Anciennes éditions en mode simple' );
	$raw = array( 'revision' => '', 'mode' => 'multiple', 'members' => array(
		array( 'user_id' => '0', 'name' => 'Alice', 'email' => get_userdata( $a )->user_email, 'active' => '1' ),
		array( 'user_id' => '0', 'name' => 'Bruno', 'email' => get_userdata( $b )->user_email, 'active' => '1' ),
		array( 'user_id' => '0', 'name' => 'Camille', 'email' => 'mpvote_' . substr( $state['run'], 0, 8 ) . '_camille@example.test', 'active' => '1' ),
	) );
	$raw['administrator'] = array( 'name' => 'Responsable', 'email' => get_userdata( $manager )->user_email );
	$config_result = mp_config( $edition, $raw );
	mp_check( true === $config_result, 'Organisateurs existants rattachés et nouveau compte créé' . ( is_wp_error( $config_result ) ? ' : ' . $config_result->get_error_message() : '' ) );
	$c = (int) email_exists( $raw['members'][2]['email'] ); update_user_meta( $c, '_mp_vote_test', $state['run'] ); $state['users'][] = $c; mp_state();
	mp_check( count( $mails ) === 1 && str_contains( $mails[0]['message'], 'action=rp' ), 'Invitation avec lien de mot de passe, email intercepté' );
	$invitation = $mails[0]; $new_user = get_userdata( $c );
	mp_check( $invitation['to'] === $new_user->user_email && str_contains( $invitation['subject'], 'Jury 2098' ) && str_contains( $invitation['message'], 'Bonjour Camille,' ), 'Invitation adressée au votant avec son nom et son édition' );
	mp_check( in_array( 'Content-Type: text/html; charset=UTF-8', $invitation['headers'], true ) && str_contains( $invitation['message'], '>Choisir mon mot de passe</a>' ), 'Invitation HTML avec bouton de choix du mot de passe' );
	$visible = wp_strip_all_tags( $invitation['message'] );
	mp_check( str_contains( $visible, 'Votre identifiant de connexion est votre adresse email' ) && str_contains( $visible, $new_user->user_email ) && ! str_contains( $visible, $new_user->user_login ), 'Email indiqué comme identifiant, identifiant technique absent du texte visible' );
	preg_match( '/href="([^"]*action=rp[^"]*)"/', $invitation['message'], $reset_match );
	parse_str( wp_parse_url( html_entity_decode( $reset_match[1] ?? '', ENT_QUOTES, 'UTF-8' ), PHP_URL_QUERY ) ?? '', $reset_params );
	$reset_user = check_password_reset_key( $reset_params['key'] ?? '', $reset_params['login'] ?? '' );
	mp_check( $reset_user instanceof WP_User && $reset_user->ID === $c, 'Bouton contenant un lien de définition du mot de passe reconnu par WordPress' );
	$chosen_password = wp_generate_password( 28, true ); reset_password( $new_user, $chosen_password );
	$authenticated = wp_authenticate_email_password( null, $new_user->user_email, $chosen_password );
	mp_check( $authenticated instanceof WP_User && $authenticated->ID === $c && is_wp_error( check_password_reset_key( $reset_params['key'], $reset_params['login'] ) ), 'Connexion par email après choix du mot de passe ; lien consommé inutilisable' );
	// WordPress envoie aussi sa notification de changement de mot de passe, interceptée ici.
	$before_invitations = count( $mails );
	mp_check( get_userdata( $a )->roles === array( 'subscriber' ) && get_userdata( $c )->roles === array( 'mp_juror' ), 'Rôle existant conservé, nouveau rôle limité' );
	mp_check( is_wp_error( mp_config( $edition, $raw ) ), 'Édition ouverte dans un onglet ancien : conflit détecté' );
	$raw = mp_raw( $edition ); mp_check( true === mp_config( $edition, $raw ) && count( $mails ) === $before_invitations, 'Enregistrement sans nouvelle invitation' );
	$existing_before = get_userdata( $c ); $raw = mp_raw( $edition ); $raw['members'][2]['invite'] = '1';
	mp_check( true === mp_config( $edition, $raw ) && count( $mails ) === $before_invitations + 1, 'Réinvitation explicite du compte existant' );
	$reinvitation = end( $mails ); $existing_after = get_userdata( $c );
	mp_check( $existing_before->user_pass === $existing_after->user_pass && $existing_before->user_activation_key === $existing_after->user_activation_key && str_contains( $reinvitation['message'], '>Accéder aux candidatures</a>' ) && ! str_contains( $reinvitation['message'], 'action=rp' ), 'Réinvitation sans changement du mot de passe ni création de clé de réinitialisation' );
	$expected_login = esc_url( wp_login_url( add_query_arg( array( 'page' => 'mp-gestion', 'mp_edition' => $edition ), admin_url( 'admin.php' ) ) ) );
	mp_check( str_contains( $reinvitation['message'], 'href="' . $expected_login . '"' ) && str_contains( $reinvitation['message'], 'action=lostpassword' ), 'Liens vers les candidatures de la bonne édition et vers la récupération du mot de passe' );
	$raw = mp_raw( $edition ); mp_config( $edition, $raw ); mp_check( count( $mails ) === $before_invitations + 1, 'Enregistrer après réinvitation ne renvoie aucun email' );
	$before_jury = Jury::settings( $edition ); $_POST = array( 'mp_jury' => array( 'mode' => 'simple' ) ); Jury::save( $edition ); $_POST = array();
	mp_check( Jury::settings( $edition ) === $before_jury, 'Réglages envoyés sans nonce ignorés' );
	$raw = mp_raw( $edition ); $raw['members'][1]['email'] = $raw['members'][0]['email'];
	mp_check( is_wp_error( mp_config( $edition, $raw ) ), 'Doublon d’email refusé avant toute écriture' );
	$potier = mp_post( 'mp_potier', 'Identité fictive' );
	$identity = array( 'last_name' => 'Céramiste test', 'first_name' => 'Lou', 'email' => 'candidat-votes@example.test', 'city' => 'Ville de test' );
	update_post_meta( $potier, Records::META, array( 'identity' => $identity ) );
	$apps = array();
	foreach ( array( $edition, $edition, $other ) as $index => $ed ) {
		$id = mp_post( 'mp_candidature', 'Dossier ' . ( $index + 1 ) ); $apps[] = $id;
		update_post_meta( $id, Records::META, array( 'edition_id' => $ed, 'potier_id' => $potier, 'identity' => $identity, 'activity' => array( 'presentation' => 'Dossier fictif pour vérifier les votes.' ), 'internal' => array(), 'files' => array(), 'decision' => 'pending' ) );
		update_post_meta( $id, '_mp_edition_id', $ed ); update_post_meta( $id, '_mp_potier_id', $potier ); update_post_meta( $id, '_mp_decision', 'pending' );
	}
	[$app, $second, $hidden] = $apps;
	$state += array( 'edition' => $edition, 'app' => $app, 'second' => $second, 'hidden' => $hidden, 'alice' => $a, 'bruno' => $b, 'manager' => $manager ); mp_state();
	wp_set_current_user( $a );
	mp_check( Jury::can_view_application( $app ) && ! Jury::can_view_application( $hidden ), 'Accès limité à l’édition affectée' );
	mp_check( ! current_user_can( 'mp_manage_applications' ) && ! current_user_can( 'mp_manage_editions' ) && ! current_user_can( 'mp_select_applications' ), 'Votant sans droits de gestion ni sélection' );
	mp_check( ! current_user_can( 'edit_post', $app ) && ! current_user_can( 'delete_post', $app ), 'Modification native et suppression refusées' );
	mp_check( is_wp_error( mp_config( $edition, mp_raw( $edition ) ) ), 'Votant incapable de modifier les affectations ou le mode' );
	$_POST = array( 'candidature' => (string) $app, 'decision' => 'selected' ); $_REQUEST = array( '_wpnonce' => wp_create_nonce( 'mp_review_decision_' . $app ) );
	mp_denied( array( Review::class, 'save_decision' ), 'Décision finale refusée au votant même avec nonce valide' ); $_POST = array(); $_REQUEST = array();
	mp_check( Votes::summary( $app )['count'] === 0, 'Absence de vote distincte de zéro' );
	mp_check( true === Votes::record( $app, '0' ) && Votes::summary( $app )['count'] === 1 && Votes::summary( $app )['total'] === 0, 'La note zéro est comptabilisée' );
	require __DIR__ . '/personal-votes.php';
	foreach ( array( '-1', '6', '2.5', '05', '', array( '5' ), 5 ) as $invalid ) { mp_check( is_wp_error( Votes::record( $app, $invalid ) ), 'Note invalide refusée : ' . json_encode( $invalid ) ); }
	mp_check( true === Votes::record( $app, '5' ) && Votes::summary( $app )['count'] === 1, 'Correction remplace la note sans double vote' );
	wp_set_current_user( $b ); mp_check( true === Votes::record( $app, '3' ) && Votes::summary( $app )['total'] === 8, 'Deux comptes gardent des notes indépendantes' );
	mp_check( Records::data( $app )['decision'] === 'pending', 'Les notes ne modifient pas la sélection finale' );
	$_GET = array( 'candidature' => (string) $app, 'mp_edition' => (string) $edition, 'mp_from' => 'gestion' );
	ob_start(); Records::view(); $html = ob_get_clean();
	mp_check( substr_count( $html, 'name="score"' ) === 1 && str_contains( $html, 'Bruno — vous' ) && str_contains( $html, '5 / 5' ), 'Un seul sélecteur personnel, autres notes visibles' );
	mp_check( ! str_contains( $html, 'Modifier ce dossier' ) && ! str_contains( $html, 'Enregistrer la sélection' ) && ! str_contains( $html, 'candidature=' . $hidden ), 'Actions réservées et historique interdit absents' );
	ob_start(); Review::table(); $html = ob_get_clean();
	mp_check( str_contains( $html, '8 points' ) && str_contains( $html, '2 vote(s) sur 4' ) && ! str_contains( $html, 'Exporter CSV' ), 'Total et participation des administrateurs et votants, accès export dans la gestion' );
	$_GET = array(); ob_start(); Records::history(); $html = ob_get_clean();
	mp_check( ! str_contains( $html, 'confidentielle' ), 'Historique filtré par édition autorisée' );
	$_GET = array( 'candidature' => (string) $hidden ); mp_denied( array( Records::class, 'view' ), 'Accès direct à un autre dossier refusé' );
	$_GET = array( 'application' => (string) $hidden, 'slot' => 'insurance' ); mp_denied( array( PrivateFiles::class, 'download' ), 'Pièces jointes d’une autre édition refusées' );
	mp_denied( array( CsvExport::class, 'download' ), 'Export CSV réservé aux responsables' );
	mp_check( Records::navigation_ids( array( 'mp_edition' => $other ) ) === array(), 'Filtre d’édition forgé sans fuite de dossiers' );
	$_POST = array( 'candidature' => (string) $app, 'score' => '4' ); $_REQUEST = $_POST;
	mp_denied( array( Votes::class, 'save' ), 'Vote HTTP sans nonce refusé' );
	$_POST['user_id'] = (string) $a; $_REQUEST = $_POST + array( '_wpnonce' => wp_create_nonce( 'mp_vote_' . $app ) );
	$redirect = static function () { throw new MPTestRedirect(); }; add_filter( 'wp_redirect', $redirect );
	try { Votes::save(); } catch ( MPTestRedirect $e ) {} finally { remove_filter( 'wp_redirect', $redirect ); }
	$stored = Votes::all( array( $app ) )[ $app ];
	mp_check( (int) $stored[ $a ]['score'] === 5 && (int) $stored[ $b ]['score'] === 4, 'Identifiant de votant forgé ignoré, seule la session compte' );
	$_POST = array(); $_GET = array(); $_REQUEST = array();
	wp_set_current_user( $outsider ); mp_check( is_wp_error( Votes::record( $app, '5' ) ), 'Compte non affecté refusé' );
	wp_set_current_user( 0 ); mp_denied( array( Review::class, 'table' ), 'Visiteur anonyme refusé' );
	wp_set_current_user( 1 ); mp_check( is_wp_error( Votes::record( $app, '5' ) ), 'Administrateur non inscrit au jury ne vote pas' );
	$before_dates = Editions::settings( $edition );
	update_post_meta( $edition, '_mp_edition_settings', array_merge( $before_dates, array( 'opens' => '2020-01-01T00:00', 'closes' => '2020-02-01T00:00' ) ) );
	wp_set_current_user( $a ); mp_check( ! Editions::is_open( $edition ) && true === Votes::record( $app, '1' ), 'Vote possible après la fermeture des inscriptions' );
	update_post_meta( $edition, '_mp_edition_settings', array_merge( $before_dates, array( 'opens' => '2098-01-01T00:00', 'closes' => '2098-02-01T00:00' ) ) );
	mp_check( ! Editions::is_open( $edition ) && true === Votes::record( $app, '5' ), 'Vote possible avant l’ouverture des inscriptions' );
	ob_start(); Votes::render( $app ); $html = ob_get_clean(); mp_check( str_contains( $html, 'name="score"' ) && ! str_contains( $html, 'Votes clôturés' ), 'Sélecteur de note disponible indépendamment des dates' );
	update_post_meta( $edition, '_mp_edition_settings', $before_dates );
	wp_set_current_user( 1 ); $raw = mp_raw( $edition ); $raw['members'][0]['active'] = '0'; mp_config( $edition, $raw );
	mp_check( Votes::summary( $app )['total'] === 4 && Votes::summary( $app )['expected'] === 3, 'Retrait : ancienne note conservée mais exclue du total' );
	wp_set_current_user( $a ); mp_check( ! Jury::can_view_application( $app ) && is_wp_error( Votes::record( $app, '5' ) ), 'Retrait coupe accès et vote immédiatement' );
	wp_set_current_user( 1 ); $raw = mp_raw( $edition ); $raw['members'][0]['active'] = '1'; $raw['mode'] = 'simple'; mp_config( $edition, $raw );
	wp_set_current_user( $a ); mp_check( is_wp_error( Votes::record( $app, '2' ) ), 'Mode simple refuse les notes' );
	ob_start(); Votes::render( $app ); $html = ob_get_clean(); mp_check( '' === $html && count( Votes::all( array( $app ) )[ $app ] ) === 2, 'Mode simple masque les votes sans les effacer' );
	wp_set_current_user( 1 ); $raw = mp_raw( $edition ); $raw['mode'] = 'multiple'; mp_config( $edition, $raw );
	$before_mails = count( $mails ); Notifications::send( $app );
	mp_check( count( $mails ) - $before_mails === 5, 'Notification candidat, administrateur et trois votants sans doublon' );
	Notifications::send( $app ); mp_check( count( $mails ) - $before_mails === 5, 'Notifications non répétées après un second appel' );
	$failure = static fn( $pre, $mail ) => $mail['to'] === get_userdata( $b )->user_email ? false : $pre;
	add_filter( 'pre_wp_mail', $failure, 200, 2 );
	Notifications::send( $second );
	mp_check( get_post_meta( $second, '_mp_mail_jury_' . $b, true ) === 'failed' && get_post_meta( $second, '_mp_mail_jury_' . $c, true ) === 'accepted', 'Échec d’un email signalé, autres destinataires traités' );
	$raw = mp_raw( $edition ); $raw['members'][1]['invite'] = '1'; mp_config( $edition, $raw );
	mp_check( str_contains( Jury::settings( $edition )['members'][ $b ]['invitation'], 'Échec' ), 'Échec d’invitation visible dans l’édition' );
	remove_filter( 'pre_wp_mail', $failure, 200 );
	$before_mails = count( $mails ); Notifications::send( $hidden );
	mp_check( count( $mails ) === $before_mails + 1 && '' === Jury::contact_email( $other ), 'Aucun destinataire de secours ni ancien contact sans administrateur affecté' );
	ob_start(); Review::decision_form( $hidden ); $html = ob_get_clean();
	mp_check( str_contains( $html, 'Enregistrer la sélection' ), 'Sélection directe disponible au responsable en mode simple' );
	$jobs = array();
	foreach ( array( $a => '2', $b => '5' ) as $uid => $score ) {
		$pipes = array(); $process = proc_open( array( PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, $root, '--worker', (string) $uid, (string) $second, $score ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		fclose( $pipes[0] ); $jobs[] = array( $process, $pipes );
	}
	foreach ( $jobs as [$process, $pipes] ) { $out = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); mp_check( proc_close( $process ) === 0, 'Vote simultané enregistré : ' . $err . $out ); }
	mp_check( Votes::summary( $second )['total'] === 7 && Votes::summary( $second )['count'] === 2, 'Votes simultanés sans perte ni doublon' );
	mp_check( Records::navigation_ids( array( 'mp_edition' => $edition, 'orderby' => 'points', 'order' => 'DESC' ) ) === array( $app, $second ), 'Tri numérique décroissant sur toutes les candidatures' );
	wp_trash_post( $second ); wp_set_current_user( $b ); mp_check( is_wp_error( Votes::record( $second, '2' ) ), 'Vote refusé sur dossier à la corbeille' );
	wp_set_current_user( 1 ); wp_untrash_post( $second );
	require __DIR__ . '/market-administrators.php';
	require __DIR__ . '/blocks-local.php';
	$passed = true; echo "SUCCÈS : $checks vérifications. Emails interceptés.\n";
} finally {
	if ( ! $keep || ! $passed ) { mp_cleanup( $state ); if ( is_file( $manifest ) ) { unlink( $manifest ); } }
}

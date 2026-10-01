<?php
/** Internal market cities, access-scoped history filters and public-form separation. */
use MarchePotier\{Editions,Records,Jury,PublicForm};
if ( ! isset( $state, $manager, $checks ) || PHP_SAPI !== 'cli' ) { exit; }
wp_set_current_user( $manager );
$city_settings = array( 'year' => '2098', 'city' => 'Bayonne', 'opens' => '2020-01-01T00:00', 'closes' => '2098-12-31T23:00', 'market_start' => '2099-01-15', 'market_end' => '2099-01-16', 'venue' => 'Place du marché', 'exhibitors' => '30', 'price' => '50' );
foreach ( array( null, '', " \t\n\u{00a0} ", array( 'Bayonne' ), 12, false, '<b></b>', str_repeat( 'é', 121 ) ) as $bad_city ) {
	marcpo_check( null === Editions::validate( array_merge( $city_settings, array( 'city' => $bad_city ) ) ), 'Ville obligatoire : type, contenu vide et longueur invalides refusés' );
}
$without_city = $city_settings; unset( $without_city['city'] );
marcpo_check( null === Editions::validate( $without_city ), 'Une sauvegarde sans le nouveau champ ville est refusée côté serveur' );
$clean_city = Editions::validate( array_merge( $city_settings, array( 'city' => "  <b>L'Haÿ</b>\u{00a0}  les Roses  " ) ) );
marcpo_check( "L'Haÿ les Roses" === $clean_city['city'], 'Ville nettoyée : accents et apostrophe conservés, espaces normalisés' );
marcpo_check( null !== Editions::validate( array_merge( $city_settings, array( 'city' => str_repeat( 'é', 120 ) ) ) ), 'Limite de 120 caractères Unicode, pas 120 octets' );

$city_voter = marcpo_user( 'Ville', 'marcpo_juror' );
$city_editions = array();
foreach ( array( 'Bayonne 2098' => 'Bayonne', 'Bayonne 2099' => 'BAYONNE', 'Pau 2098' => 'Pau', 'Ancienne édition' => '', 'Édition privée' => 'VilleSecrète' ) as $title => $city ) {
	$id = marcpo_post( 'marcpo_edition', $title ); $city_editions[ $title ] = $id;
	$settings = array_merge( $city_settings, array( 'city' => $city ) );
	if ( '' === $city ) { unset( $settings['city'] ); }
	update_post_meta( $id, '_marcpo_edition_settings', $settings );
	$team = array( 'mode' => 'multiple', 'revision' => '', 'administrator' => array( 'name' => 'Responsable', 'email' => get_userdata( $manager )->user_email ), 'members' => array() );
	if ( 'VilleSecrète' !== $city ) { $team['members'][] = array( 'name' => 'Votant ville', 'email' => get_userdata( $city_voter )->user_email, 'active' => '1' ); }
	if ( true !== marcpo_config( $id, $team ) ) { throw new RuntimeException( 'Configuration des fixtures ville impossible.' ); }
}
$city_bayonne = $city_editions['Bayonne 2098'];
$state['city_editions'] = $city_editions; marcpo_state();
$city_people = array();
foreach ( array( 'Commun' => 'Pau', 'SeulementPau' => 'Bayonne', 'Ancien' => 'Bayonne', 'SecretVille' => 'Bayonne' ) as $name => $residence ) {
	$id = marcpo_post( 'marcpo_potier', 'Potier ' . $name ); $city_people[ $name ] = $id;
	update_post_meta( $id, Records::META, array( 'identity' => array( 'last_name' => $name, 'first_name' => 'Lou', 'email' => strtolower( $name ) . '@example.test', 'city' => $residence ) ) );
}
foreach ( array( array( 'Bayonne 2098', 'Commun', 'selected' ), array( 'Bayonne 2099', 'Commun', 'pending' ), array( 'Pau 2098', 'Commun', 'rejected' ), array( 'Pau 2098', 'SeulementPau', 'selected' ), array( 'Ancienne édition', 'Ancien', 'selected' ), array( 'Édition privée', 'SecretVille', 'selected' ) ) as [$title, $name, $decision] ) {
	$id = marcpo_post( 'marcpo_candidature', 'Dossier ville ' . $name );
	update_post_meta( $id, Records::META, array( 'edition_id' => $city_editions[ $title ], 'potier_id' => $city_people[ $name ], 'identity' => Records::data( $city_people[ $name ] )['identity'], 'decision' => $decision ) );
}

$city_before = Editions::settings( $city_bayonne ); $team_before = Jury::settings( $city_bayonne ); $mail_before = count( $mails );
$city_post = array( 'marcpo_edition_nonce' => wp_create_nonce( 'marcpo_save_edition_' . $city_bayonne ), 'marcpo_jury_nonce' => wp_create_nonce( 'marcpo_jury_' . $city_bayonne ), 'marcpo_jury' => marcpo_raw( $city_bayonne ) );
$city_post['marcpo_jury']['administrator']['invite'] = '1';
foreach ( array( $without_city, array_merge( $city_settings, array( 'city' => array( 'Pau' ) ) ) ) as $invalid_settings ) {
	$_POST = wp_slash( $city_post + array( 'marcpo_edition' => $invalid_settings ) ); Editions::save( $city_bayonne );
	marcpo_check( Editions::settings( $city_bayonne ) === $city_before && Jury::settings( $city_bayonne ) === $team_before && count( $mails ) === $mail_before, 'Ville invalide : anciens réglages et équipe conservés, aucune invitation' );
}
unset( $city_post['marcpo_jury']['administrator']['invite'] );
$city_post['marcpo_edition'] = array_merge( $city_settings, array( 'city' => "L'Haÿ les Roses" ) );
$_POST = wp_slash( $city_post ); Editions::save( $city_bayonne ); $_POST = array();
marcpo_check( "L'Haÿ les Roses" === Editions::city( $city_bayonne ), 'Sauvegarde HTTP de la ville avec un seul unslash et stockage sans perte' );
wp_set_current_user( $city_voter );
$_POST = wp_slash( $city_post ); $_POST['marcpo_edition']['city'] = 'Interdit'; Editions::save( $city_bayonne ); $_POST = array();
marcpo_check( "L'Haÿ les Roses" === Editions::city( $city_bayonne ), 'Un votant ne peut pas modifier la ville du marché' );
wp_set_current_user( $manager );
$escaped_settings = Editions::settings( $city_bayonne ); $escaped_settings['city'] = '" autofocus onfocus="alert(1)';
ob_start(); Editions::market_fields( $escaped_settings ); $city_editor = ob_get_clean();
marcpo_check( str_contains( $city_editor, 'value="&quot; autofocus' ) && ! str_contains( $city_editor, 'value="" autofocus' ), 'Ville échappée dans le champ de modification' );
$private_city = 'VilleInterneAbsenteDuFormulaire';
update_post_meta( $city_bayonne, '_marcpo_edition_settings', array_merge( $city_settings, array( 'city' => $private_city ) ) );
wp_set_current_user( 0 ); $public_html = PublicForm::render( $city_bayonne );
marcpo_check( str_contains( $public_html, '<form ' ) && ! str_contains( $public_html, $private_city ) && ! str_contains( $public_html, 'marcpo_edition[city]' ) && str_contains( $public_html, 'Place du marché' ), 'Formulaire public : ville interne absente, lieu d’exposition toujours présent' );
marcpo_check( Editions::is_open( $city_editions['Ancienne édition'] ) && '' === Editions::city( $city_editions['Ancienne édition'] ), 'Ancienne édition sans ville : candidatures toujours ouvertes, aucune ville inventée' );
update_post_meta( $city_bayonne, '_marcpo_edition_settings', $city_settings );

$city_render = static function ( mixed $city, string $search = '' ): array {
	$_GET = wp_slash( array( 'marcpo_city' => $city, 's' => $search ) );
	ob_start(); Records::history(); $html = ob_get_clean(); $_GET = array();
	preg_match( '/<thead>(.*?)<\/thead>/s', $html, $head ); preg_match( '/<tbody>(.*?)<\/tbody>/s', $html, $body );
	return array( $html, $head[1] ?? '', $body[1] ?? '' );
};
wp_set_current_user( $city_voter );
$bayonne_key = hash( 'sha256', 'bayonne' ); $pau_key = hash( 'sha256', 'pau' );
[$html, $head, $body] = $city_render( '' );
marcpo_check( str_contains( $head, 'Bayonne 2098' ) && str_contains( $head, 'Pau 2098' ) && str_contains( $head, 'Ancienne édition' ) && ! str_contains( $html, 'VilleSecrète' ) && ! str_contains( $html, 'SecretVille' ), 'Toutes les villes : éditions accessibles seulement, anciens dossiers inclus' );
marcpo_check( 1 === substr_count( $html, 'value="' . $bayonne_key . '"' ), 'Une seule option pour Bayonne et BAYONNE' );
[$html, $head, $body] = $city_render( $bayonne_key );
marcpo_check( str_contains( $head, 'Bayonne 2098' ) && str_contains( $head, 'Bayonne 2099' ) && ! str_contains( $head, 'Pau 2098' ) && ! str_contains( $head, 'Ancienne édition' ), 'Filtre ville : colonnes limitées aux éditions de la ville choisie' );
marcpo_check( str_contains( $body, 'Commun' ) && ! str_contains( $body, 'SeulementPau' ) && str_contains( $body, 'Sélectionné' ) && str_contains( $body, 'À examiner' ) && ! str_contains( $body, 'Non sélectionné' ), 'Sélections par ville du marché, sans filtrer la ville de résidence du candidat' );
[$html, $head, $body] = $city_render( $pau_key, 'SEULEMENTPAU Lou' );
marcpo_check( str_contains( $body, 'SeulementPau' ) && ! str_contains( $body, '>Commun<' ) && str_contains( $head, '2 candidatures' ), 'Ville et recherche d’identité combinées, totaux complets de cette édition' );
marcpo_check( str_contains( $html, esc_url( add_query_arg( 'marcpo_city', $pau_key, admin_url( 'admin.php?page=marcpo-historique' ) ) ) ) && str_contains( $html, 'Réinitialiser les filtres' ), 'Effacer la recherche conserve la ville ; réinitialisation complète disponible' );
[$html, $head, $body] = $city_render( 'missing' );
marcpo_check( str_contains( $head, 'Ancienne édition' ) && ! str_contains( $head, 'Bayonne 2098' ) && str_contains( $body, 'Ancien' ), 'Option Ville non renseignée pour compléter les anciennes éditions' );
foreach ( array( array( $bayonne_key ), 'inconnue', hash( 'sha256', 'villesecrete' ), '<b>' . $bayonne_key . '</b>' ) as $invalid_filter ) {
	[$html, $head, $body] = $city_render( $invalid_filter );
	marcpo_check( '' === $head && '' === $body && str_contains( $html, 'Aucune édition accessible' ) && ! str_contains( $html, 'VilleSecrète' ), 'Filtre invalide ou hors accès : aucun dossier ni ville cachée exposés' );
}
wp_set_current_user( $manager ); $_GET = array(); $_POST = array();
delete_transient( 'marcpo_jury_error_' . $manager ); wp_set_current_user( 1 );

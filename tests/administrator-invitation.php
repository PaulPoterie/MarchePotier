<?php
/** Native edition saves used by the administrator's invitation button; emails intercepted by votes-local.php. */
use MarchePotier\{Jury,Editions};
if ( ! isset( $state, $manager, $checks ) || PHP_SAPI !== 'cli' ) { exit; }
require_once ABSPATH . 'wp-admin/includes/post.php';

wp_set_current_user( $manager );
$invite_edition = marcpo_post( 'marcpo_edition', 'Invitation administrateur' );
wp_update_post( array( 'ID' => $invite_edition, 'post_status' => 'auto-draft' ) );
$invite_request = static function () use ( $invite_edition, $manager ): array {
	return array(
		'post_ID' => $invite_edition, 'post_type' => 'marcpo_edition', 'action' => 'editpost', 'user_ID' => $manager,
		'post_title' => '[TEST VOTES] Invitation administrateur', 'post_status' => get_post_status( $invite_edition ),
		'original_post_status' => get_post_status( $invite_edition ),
		'marcpo_edition_nonce' => wp_create_nonce( 'marcpo_save_edition_' . $invite_edition ),
		'marcpo_jury_nonce' => wp_create_nonce( 'marcpo_jury_' . $invite_edition ),
		'marcpo_edition' => array( 'year' => '2098', 'opens' => '2020-01-01T00:00', 'closes' => '2098-12-31T23:00', 'market_start' => '2099-01-15', 'market_end' => '2099-01-16', 'venue' => "Place de l'église", 'exhibitors' => '30', 'price' => '50' ),
		'marcpo_jury' => array( 'revision' => Jury::settings( $invite_edition )['revision'], 'mode' => 'simple', 'administrator' => array( 'name' => 'Responsable', 'email' => get_userdata( $manager )->user_email, 'invite' => '1' ) ),
	);
};

$before_mails = count( $mails );
foreach ( array( 'marcpo_edition_nonce', 'marcpo_jury_nonce' ) as $nonce_key ) {
	foreach ( array( 'invalid', array( 'invalid' ) ) as $nonce_value ) {
		$_POST = wp_slash( $invite_request() ); $_POST[ $nonce_key ] = $nonce_value;
		Editions::save( $invite_edition );
		marcpo_check( ! Jury::administrator( $invite_edition ) && count( $mails ) === $before_mails, 'Invitation administrateur refusée avec nonce invalide ou tableau : ' . $nonce_key );
	}
}
$_POST = wp_slash( $invite_request() ); $_POST['marcpo_edition']['closes'] = 'invalide';
Editions::save( $invite_edition );
marcpo_check( ! Jury::administrator( $invite_edition ) && count( $mails ) === $before_mails, 'Bouton invitation : dates invalides sans affectation ni email' );
wp_set_current_user( $a ); $_POST = wp_slash( $invite_request() );
Editions::save( $invite_edition );
marcpo_check( ! Jury::administrator( $invite_edition ) && count( $mails ) === $before_mails, 'Un votant ne peut pas enregistrer et inviter un administrateur' );
wp_set_current_user( $manager );
$password_before = get_userdata( $manager )->user_pass;
$_POST = wp_slash( $invite_request() );
$first_request = $_POST;
$saved_id = edit_post();
marcpo_check( $saved_id === $invite_edition && 'draft' === get_post_status( $invite_edition ) && $manager === Jury::administrator( $invite_edition ), 'Soumission native du bouton : nouvelle édition enregistrée en brouillon avec administrateur — ' . wp_json_encode( array( 'saved' => $saved_id, 'edition' => $invite_edition, 'status' => get_post_status( $invite_edition ), 'administrator' => Jury::administrator( $invite_edition ), 'manager' => $manager, 'error' => get_transient( 'marcpo_jury_error_' . $manager ) ) ) );
marcpo_check( count( $mails ) === $before_mails + 1 && end( $mails )['to'] === get_userdata( $manager )->user_email && $password_before === get_userdata( $manager )->user_pass, 'Un seul email au compte existant, mot de passe conservé' );
marcpo_check( "Place de l'église" === Editions::settings( $invite_edition )['venue'], 'Le bouton sauvegarde aussi les paramètres, sans perte des apostrophes' );
$_POST = $first_request; Editions::save( $invite_edition );
marcpo_check( count( $mails ) === $before_mails + 1, 'Rejeu du même formulaire périmé : aucune invitation supplémentaire' );
$_POST = wp_slash( $invite_request() ); unset( $_POST['marcpo_jury']['administrator']['invite'] );
$_POST['marcpo_edition']['year'] = '2097'; edit_post();
marcpo_check( '2097' === Editions::settings( $invite_edition )['year'] && count( $mails ) === $before_mails + 1, 'Enregistrement ordinaire ultérieur : paramètres modifiés, aucune réinvitation' );
$_POST = array(); wp_update_post( array( 'ID' => $invite_edition, 'post_status' => 'publish' ) );
$_POST = wp_slash( $invite_request() ); edit_post();
marcpo_check( 'publish' === get_post_status( $invite_edition ) && count( $mails ) === $before_mails + 2, 'Réinvitation explicite d’une édition publiée sans changement de statut' );
$invite_failure = static fn() => false;
add_filter( 'pre_wp_mail', $invite_failure, 200 );
try { $_POST = wp_slash( $invite_request() ); edit_post(); }
finally { remove_filter( 'pre_wp_mail', $invite_failure, 200 ); }
marcpo_check( str_contains( Jury::settings( $invite_edition )['members'][ $manager ]['invitation'], 'Échec' ) && $manager === Jury::administrator( $invite_edition ), 'Échec d’envoi affiché sans perdre l’administrateur enregistré' );
$_POST = wp_slash( $invite_request() ); edit_post();
marcpo_check( 'Invitation confiée au service d’envoi' === Jury::settings( $invite_edition )['members'][ $manager ]['invitation'], 'Le bouton permet de réessayer après un échec' );
$_POST = array(); delete_transient( 'marcpo_jury_error_' . $manager );
wp_set_current_user( 1 );

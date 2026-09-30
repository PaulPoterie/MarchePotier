<?php
/** Targeted regression tests for the WordPress.org review, on the named local site only. */
if ( PHP_SAPI !== 'cli' || empty( $argv[1] ) || basename( dirname( dirname( $argv[1] ) ) ) !== 'marche-potier-test' ) { exit( "Local test site required.\n" ); }
define( 'WP_PLUGIN_DIR', dirname( __DIR__ ) );
define( 'DISABLE_WP_CRON', true );
require $argv[1] . '/wp-load.php';
if ( wp_parse_url( home_url(), PHP_URL_HOST ) !== 'marche-potier-test.local' ) { exit( "Unexpected site.\n" ); }
require dirname( __DIR__ ) . '/marche-potier.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
use MarchePotier\{Records,Editions,Jury,PrivateFiles};
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
Records::register(); Editions::register(); wp_set_current_user( 1 );
if ( ! current_user_can( 'manage_options' ) ) { exit( "Local administrator required.\n" ); }
Editions::install_permissions(); Records::permissions(); Jury::install();
wp_set_current_user( 0 ); wp_set_current_user( 1 );
$checks = 0;
function marcpo_review_check( bool $value, string $label ): void {
	global $checks; if ( ! $value ) { throw new RuntimeException( $label ); }
	echo 'OK ' . ++$checks . ' : ' . $label . "\n";
}
$id = wp_insert_post( array( 'post_type'=>'marcpo_candidature', 'post_status'=>'publish', 'post_title'=>'[TEST REVIEW] Modification rapide' ) );
try {
	update_post_meta( $id, Records::META, array( 'decision'=>'pending', 'potier_id'=>1, 'edition_id'=>1 ) );
	set_current_screen( 'dashboard' ); Records::quick_edit_script();
	marcpo_review_check( ! wp_script_is( 'marcpo-quick-edit', 'enqueued' ), 'Aucun script de modification rapide sur le tableau de bord' );
	set_current_screen( 'edit-marcpo_candidature' ); Records::quick_edit_script();
	marcpo_review_check( wp_script_is( 'marcpo-quick-edit', 'enqueued' ), 'Script de modification rapide enregistré sur la liste des candidatures' );
	marcpo_review_check( in_array( 'inline-edit-post', wp_scripts()->registered['marcpo-quick-edit']->deps, true ), 'Ordre de chargement du script natif WordPress respecté' );
	wp_dequeue_script( 'marcpo-quick-edit' ); wp_set_current_user( 0 ); Records::quick_edit_script();
	marcpo_review_check( ! wp_script_is( 'marcpo-quick-edit', 'enqueued' ), 'Script non chargé sans permission' );
	$_POST = array( 'marcpo_inline_decision'=>'selected', '_inline_edit'=>'invalid' );
	Records::save_inline_decision( $id );
	marcpo_review_check( 'pending' === Records::data( $id )['decision'], 'Modification rapide refusée au visiteur' );
	wp_set_current_user( 1 ); Records::save_inline_decision( $id );
	marcpo_review_check( 'pending' === Records::data( $id )['decision'], 'Modification rapide refusée avec un nonce invalide' );
	$_POST['_inline_edit'] = wp_create_nonce( 'inlineeditnonce' ); Records::save_inline_decision( $id );
	marcpo_review_check( 'selected' === Records::data( $id )['decision'], 'Modification rapide valide enregistrée' );
	set_transient( 'marcpo_jury_error_1', 'Test de notification', 120 );
	set_current_screen( 'dashboard' ); ob_start(); Jury::notice(); PrivateFiles::migration_notice( array( 'remaining'=>1 ) ); $output = ob_get_clean();
	marcpo_review_check( '' === $output && get_transient( 'marcpo_jury_error_1' ), 'Notifications absentes du tableau de bord et erreur conservée pour son écran' );
	set_current_screen( 'marcpo_edition' ); ob_start(); Jury::notice(); PrivateFiles::migration_notice( array( 'remaining'=>1 ) ); $output = ob_get_clean();
	marcpo_review_check( str_contains( $output, 'Test de notification' ) && str_contains( $output, 'is-dismissible' ) && ! get_transient( 'marcpo_jury_error_1' ), 'Notifications contextuelles, erreur consommée et import refermable' );
	echo "SUCCÈS : $checks vérifications de révision.\n";
} finally {
	$_POST = array(); wp_set_current_user( 1 ); wp_delete_post( $id, true ); delete_transient( 'marcpo_jury_error_1' );
}

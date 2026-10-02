<?php
/** CLI uniquement, sur une base jetable préfixée marcpo_db261002_ ; voir docs/DEVELOPPEMENT.md. */
if ( PHP_SAPI !== 'cli' || empty( $argv[1] ) || ! defined( 'MARCPO_DB_REVIEW' ) ) { exit( "Isolated database review bootstrap required.\n" ); }
define( 'DISABLE_WP_CRON', true );
define( 'WP_PLUGIN_DIR', dirname( __DIR__ ) );
$_SERVER['HTTP_HOST'] = 'marche-potier-test.local'; $_SERVER['REQUEST_METHOD'] = 'GET';
require $argv[1] . '/wp-load.php';
require dirname( __DIR__ ) . '/marche-potier.php';
use MarchePotier\{SubmissionLock,MediaLibrary,PrivateFiles,Plugin,Editions,Records};
global $wpdb;
if ( $wpdb->prefix !== 'marcpo_db261002_' ) { throw new RuntimeException( 'Disposable database required.' ); }
Plugin::boot(); Editions::register(); Records::register(); wp_set_current_user( 1 );
$key = '_marcpo_submission_lock'; $counter = '_marcpo_lock_test_counter';
if ( isset( $argv[2] ) ) {
	if ( 'try' === $argv[2] ) {
		$acquired = SubmissionLock::acquire(); echo $acquired ? 'acquired' : SubmissionLock::error()->get_error_code();
		SubmissionLock::release(); exit;
	}
	if ( 'exit' === $argv[2] ) { echo SubmissionLock::acquire() ? 'acquired' : 'failed'; exit; }
	if ( 'count' === $argv[2] ) {
		for ( $i = 0; $i < 12; ++$i ) {
			if ( ! SubmissionLock::acquire() ) { throw new RuntimeException( 'Contended lock failed.' ); }
			try {
				$value = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $counter ) );
				usleep( 10000 );
				$wpdb->update( $wpdb->options, array( 'option_value' => $value + 1 ), array( 'option_name' => $counter ) );
			} finally { SubmissionLock::release(); }
		}
		echo 'counted'; exit;
	}
	throw new RuntimeException( 'Unknown worker operation.' );
}
$checks = 0;
function marcpo_lock_check( bool $ok, string $label ): void { global $checks; if ( ! $ok ) { throw new RuntimeException( $label ); } echo 'OK LOCK ' . ++$checks . ': ' . $label . "\n"; }
function marcpo_lock_worker( string $mode ): array {
	global $argv;
	$process = proc_open( array( PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, $argv[1], $mode ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Worker failed to start.' ); }
	fclose( $pipes[0] ); return array( $process, $pipes );
}
function marcpo_lock_join( array $worker ): string {
	[$process, $pipes] = $worker;
	$output = stream_get_contents( $pipes[1] ); $errors = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] );
	if ( proc_close( $process ) !== 0 || '' !== $errors ) { throw new RuntimeException( 'Worker: ' . $errors . $output ); }
	return $output;
}
$read = static fn() => $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $key ) );
if ( null !== $read() || null !== get_option( $counter, null ) ) { throw new RuntimeException( 'Preserve previous lock/fixtures.' ); }
$foreign = 'foreign-test-owner:' . ( time() - DAY_IN_SECONDS );
try {
	wp_cache_set( $key, 'stale-cache-entry', 'options' );
	marcpo_lock_check( SubmissionLock::acquire(), 'Acquisition indépendante du cache d’options' );
	marcpo_lock_check( ! SubmissionLock::acquire(), 'Verrou non réentrant' );
	$owner = $read();
	marcpo_lock_check( is_string( $owner ) && strlen( $owner ) > 64, 'Propriétaire enregistré dans la base' );
	$started = microtime( true );
	marcpo_lock_check( 'marcpo_lock_busy' === marcpo_lock_join( marcpo_lock_worker( 'try' ) ), 'Une seconde connexion est refusée pendant la détention' );
	marcpo_lock_check( microtime( true ) - $started >= 3 && $owner === $read(), 'Attente bornée, propriétaire initial préservé' );
	$busy = MediaLibrary::migrate();
	marcpo_lock_check( is_wp_error( $busy ) && 'marcpo_lock_busy' === $busy->get_error_code(), 'Migration occupée distinguée d’une erreur de fichiers' );
	SubmissionLock::release();
	marcpo_lock_check( null === $read() && SubmissionLock::acquire(), 'Libération puis nouvelle acquisition' );
	$wpdb->update( $wpdb->options, array( 'option_value' => $foreign ), array( 'option_name' => $key ) );
	SubmissionLock::release();
	marcpo_lock_check( $foreign === $read(), 'Un ancien propriétaire ne supprime pas le verrou de son successeur' );
	marcpo_lock_check( 'marcpo_lock_busy' === marcpo_lock_join( marcpo_lock_worker( 'try' ) ) && $foreign === $read(), 'Un verrou ancien n’est pas volé sur la seule base de son âge' );
	$wpdb->delete( $wpdb->options, array( 'option_name' => $key, 'option_value' => $foreign ) );
	wp_cache_delete( $key, 'options' );
	marcpo_lock_check( 'acquired' === marcpo_lock_join( marcpo_lock_worker( 'exit' ) ) && null === $read(), 'La fin de requête libère un verrou oublié' );
	marcpo_lock_check( SubmissionLock::acquire(), 'Verrou disponible après arrêt du processus précédent' );
	$table = $wpdb->options;
	try { $wpdb->options = 'marcpo_test_other_blog_options'; SubmissionLock::release(); }
	finally { $wpdb->options = $table; }
	marcpo_lock_check( null === $read(), 'Libération dans la table d’origine après changement de contexte' );
	$reject = static fn( $query ) => str_contains( $query, 'INSERT IGNORE INTO' ) && str_contains( $query, '_marcpo_submission_lock' ) ? '' : $query;
	add_filter( 'query', $reject );
	try { marcpo_lock_check( ! SubmissionLock::acquire() && 'marcpo_lock_storage' === SubmissionLock::error()->get_error_code(), 'Échec de stockage refusé et distingué d’une contention' ); }
	finally { remove_filter( 'query', $reject ); }
	$wpdb->insert( $wpdb->options, array( 'option_name' => $counter, 'option_value' => '0', 'autoload' => 'no' ) );
	$workers = array();
	for ( $i = 0; $i < 4; ++$i ) { $workers[] = marcpo_lock_worker( 'count' ); }
	foreach ( $workers as $worker ) { marcpo_lock_check( 'counted' === marcpo_lock_join( $worker ), 'Travail concurrent terminé sans erreur' ); }
	$value = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $counter ) );
	marcpo_lock_check( 48 === $value && null === $read(), '48 écritures concurrentes sans perte, verrou libéré' );
	$result = MediaLibrary::migrate();
	marcpo_lock_check( ! is_wp_error( $result ) && 0 === $result['moved'] && 0 === $result['remaining'], 'Installation neuve : migration vide réussie' );
	require_once ABSPATH . 'wp-admin/includes/screen.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
	set_current_screen( 'toplevel_page_marche-potier' );
	foreach ( array( $busy, new WP_Error( 'marcpo_lock_storage', 'Storage unavailable' ) ) as $error ) {
		ob_start(); PrivateFiles::migration_notice( $error ); $notice = ob_get_clean();
		marcpo_lock_check( ! str_contains( $notice, 'uploads' ) && str_contains( $notice, esc_html( $error->get_error_message() ) ), 'Notice de verrou sans conseil trompeur sur uploads' );
	}
	echo "SUCCÈS LOCK : $checks vérifications.\n";
} finally {
	SubmissionLock::release();
	$wpdb->delete( $wpdb->options, array( 'option_name' => $key, 'option_value' => $foreign ) );
	$wpdb->delete( $wpdb->options, array( 'option_name' => $counter ) );
	wp_cache_delete( $key, 'options' );
}

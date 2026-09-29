<?php
/** Consent and provider integration checks; local fixtures only, all HTTP calls intercepted. */
if ( PHP_SAPI !== 'cli' || empty( $argv[1] ) || basename( dirname( dirname( $argv[1] ) ) ) !== 'marche-potier-test' ) { exit( "Local test site required.\n" ); }
define( 'WP_PLUGIN_DIR', dirname( __DIR__ ) );
define( 'DISABLE_WP_CRON', true );
require $argv[1] . '/wp-load.php';
if ( wp_parse_url( home_url(), PHP_URL_HOST ) !== 'marche-potier-test.local' ) { exit( "Unexpected site.\n" ); }
require dirname( __DIR__ ) . '/marche-potier.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
use MarchePotier\{ExternalServices,GalleryMap,Gallery,Records,Editions,PublicForm,Fields,CsvExport,Notifications};

Records::register(); Editions::register(); ExternalServices::hooks(); ExternalServices::register(); GalleryMap::hooks();
wp_set_current_user( 1 );
if ( ! current_user_can( 'manage_options' ) ) { exit( "Local administrator required.\n" ); }
$checks = 0; $ids = array(); $calls = array(); $settings = array(); $run = wp_generate_uuid4(); $cache_key = '';
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
// Settings are overridden only in this PHP process; no real site authorization is changed.
$options = static function () use ( &$settings ) { return $settings; };
add_filter( 'pre_option_' . ExternalServices::OPTION, $options );
$provider_features = null;
$network = static function ( $pre, $args, $url ) use ( &$calls, &$provider_features ) {
	$calls[] = array( 'url' => $url, 'args' => $args );
	return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'features' => $provider_features ?? array( array( 'properties' => array( 'score' => .97, 'postcode' => '64100', 'city' => 'Bayonne', 'type' => 'housenumber', 'label' => '<b>1 rue fictive</b>, 64100 Bayonne', 'unexpected' => '<script>unused</script>' ), 'geometry' => array( 'coordinates' => array( -1.47, 43.49 ) ) ) ) ) ) );
};
add_filter( 'pre_http_request', $network, PHP_INT_MAX, 3 );
function marcpo_map_check( bool $ok, string $message ): void {
	global $checks;
	if ( ! $ok ) { throw new RuntimeException( $message ); }
	echo 'OK MAP ' . ++$checks . ' : ' . $message . "\n";
}
try {
	marcpo_map_check( ! ExternalServices::enabled( 'ign' ) && ! ExternalServices::enabled( 'osm' ), 'Les deux services sont désactivés sans accord administrateur' );
	marcpo_map_check( array( 'ign' => false, 'osm' => false ) === ExternalServices::sanitize( array( 'ign' => array( '1' ), 'osm' => 'yes' ) ), 'Paramètres malformés refusés, sans conversion implicite en autorisation' );
	marcpo_map_check( array( 'ign' => true, 'osm' => false ) === ExternalServices::sanitize( array( 'ign' => '1' ) ), 'Cases indépendantes et absences désactivées' );
	$once = ExternalServices::sanitize( array( 'ign' => '1', 'osm' => '1' ) );
	marcpo_map_check( $once === ExternalServices::sanitize( $once ), 'Double nettoyage de la Settings API idempotent' );
	ob_start(); ExternalServices::render(); $settings_html = ob_get_clean();
	marcpo_map_check( str_contains( $settings_html, 'name="_wpnonce"' ) && preg_match( '/name=[\'"]option_page[\'"] value=[\'"]marcpo_services[\'"]/', $settings_html ) && ! str_contains( $settings_html, 'checked="checked"' ), 'Formulaire administrateur avec nonce, sans case précochée' );
	$registered = get_registered_settings();
	marcpo_map_check( 'marcpo_services' === $registered[ExternalServices::OPTION]['group'] && array(ExternalServices::class, 'sanitize') === $registered[ExternalServices::OPTION]['sanitize_callback'], 'Réglages enregistrés auprès de la Settings API' );
	wp_set_current_user( 0 );
	$deny = static fn() => static function () { throw new RuntimeException( 'denied' ); };
	add_filter( 'wp_die_handler', $deny ); $denied = false;
	try { ExternalServices::render(); } catch ( RuntimeException $error ) { $denied = 'denied' === $error->getMessage(); }
	remove_filter( 'wp_die_handler', $deny ); wp_set_current_user( 1 );
	marcpo_map_check( $denied, 'Réglages refusés sans droit administrateur' );
	$edition = wp_insert_post( array( 'post_type' => 'marcpo_edition', 'post_status' => 'publish', 'post_title' => '[TEST MAP] ' . $run ) ); $ids[] = $edition;
	update_post_meta( $edition, '_marcpo_edition_settings', array( 'selection_public' => true, 'opens' => wp_date( 'Y-m-d\TH:i', time() - HOUR_IN_SECONDS ), 'closes' => wp_date( 'Y-m-d\TH:i', time() + DAY_IN_SECONDS ) ) );
	$app = wp_insert_post( array( 'post_type' => 'marcpo_candidature', 'post_status' => 'publish', 'post_title' => '[TEST MAP] Candidate ' . $run ) ); $ids[] = $app;
	$data = array( 'edition_id' => $edition, 'decision' => 'selected', 'publication_consent' => true, 'identity' => array( 'last_name' => 'Synthetic', 'first_name' => 'Candidate', 'address' => '1 rue fictive ' . $run, 'postcode' => '64100', 'city' => 'Bayonne', 'country' => 'France', 'phone' => '0100000000', 'email' => 'private@example.test' ), 'activity' => array(), 'files' => array() );
	update_post_meta( $app, Records::META, $data ); update_post_meta( $app, '_marcpo_edition_id', $edition );
	$cache_key = 'marcpo_address_' . GalleryMap::signature( $data['identity'] );
	$form = PublicForm::render( $edition );
	preg_match( '/<input\b[^>]*name="marcpo_map_consent"[^>]*>/', $form, $checkbox );
	marcpo_map_check( isset( $checkbox[0] ) && ! str_contains( $checkbox[0], 'required' ) && ! str_contains( $checkbox[0], 'checked' ), 'Accord cartographique public facultatif et décoché par défaut' );
	marcpo_map_check( str_contains( $form, 'name="marcpo_record[identity][phone]" required' ) && true === Fields::identity()['phone'][2], 'Téléphone obligatoire inchangé' );
	marcpo_map_check( Gallery::eligible( $app ) && ! GalleryMap::eligible( $app ), 'Un ancien accord de publication ne vaut pas consentement cartographique' );
	$settings = array( 'ign' => true, 'osm' => true );
	GalleryMap::locate( $app );
	marcpo_map_check( 0 === count( $calls ), 'Aucun appel IGN sans accord du candidat, même services activés' );
	$data['map_consent'] = false; update_post_meta( $app, Records::META, $data );
	marcpo_map_check( ! wp_next_scheduled( 'marcpo_locate_city', array( $app ) ), 'Aucune tâche de localisation après refus' );
	$html = Gallery::render_edition( $edition );
	marcpo_map_check( str_contains( $html, 'Synthetic Candidate' ) && ! str_contains( $html, 'data-tiles=' ), 'Galerie conservée sans carte pour un candidat ayant refusé' );
	$data['map_consent'] = true; $data['map_consent_at'] = gmdate('c'); update_post_meta( $app, Records::META, $data );
	marcpo_map_check( (bool) wp_next_scheduled( 'marcpo_locate_city', array( $app ) ), 'Localisation planifiée uniquement avec les deux accords et une sélection publique' );
	$settings['ign'] = false;
	do_action( 'marcpo_locate_city', $app );
	marcpo_map_check( 0 === count( $calls ) && ! get_post_meta( $app, '_marcpo_map_location', true ), 'Une tâche déjà planifiée ne contacte pas IGN après désactivation' );
	$settings['ign'] = true; GalleryMap::locate( $app );
	marcpo_map_check( 1 === count( $calls ) && is_array( get_post_meta( $app, '_marcpo_map_location', true ) ), 'Géocodage autorisé avec réponse IGN simulée, sans réseau réel' );
	$query = array(); parse_str( wp_parse_url( $calls[0]['url'], PHP_URL_QUERY ), $query );
	marcpo_map_check( array( 'q', 'index', 'limit' ) === array_keys( $query ) && $query['q'] === $data['identity']['address'] . ' 64100 Bayonne' && ! str_contains( $calls[0]['url'], '0100000000' ) && ! str_contains( $calls[0]['url'], 'private' ), 'Seule l’adresse est envoyée : ni téléphone, ni email, ni nom de candidat' );
	GalleryMap::locate( $app );
	marcpo_map_check( 1 === count( $calls ), 'Coordonnées existantes réutilisées sans nouvel appel' );
	$settings['osm'] = false;
	$html = Gallery::render_edition( $edition );
	marcpo_map_check( str_contains( $html, 'Synthetic Candidate' ) && ! str_contains( $html, 'data-tiles=' ) && ! str_contains( $html, '1 rue fictive' ), 'OSM désactivé : pas de carte ni d’adresse précise dans la galerie' );
	$settings['osm'] = true; $settings['ign'] = false;
	$html = Gallery::render_edition( $edition );
	marcpo_map_check( str_contains( $html, 'data-tiles=' ) && str_contains( $html, '1 rue fictive' ), 'Accords indépendants : fond de carte et coordonnées déjà enregistrées' );
	$headers = CsvExport::headers(); $row = CsvExport::row( $app );
	marcpo_map_check( count($headers) === count($row) && 'Oui' === $row[array_search('Autorisation de localisation sur la carte', $headers, true)], 'Accord distinct présent dans l’export CSV' );
	marcpo_map_check( str_contains( Notifications::summary( $data ), 'Autorisation de localisation sur la carte : Oui' ), 'Confirmation de candidature indiquant le choix cartographique' );
	$data['map_consent'] = false; update_post_meta( $app, Records::META, $data );
	marcpo_map_check( ! get_post_meta( $app, '_marcpo_map_location', true ) && ! wp_next_scheduled( 'marcpo_locate_city', array( $app ) ), 'Retrait du consentement : coordonnées et tâche de ce candidat supprimées' );
	$settings['ign'] = true; GalleryMap::locate( $app );
	marcpo_map_check( 1 === count( $calls ) && ! str_contains( Gallery::render_edition( $edition ), 'data-tiles=' ), 'Retrait respecté même si le résultat IGN reste dans le cache partagé' );
	$raw = array( 'identity' => $data['identity'], 'activity' => array(), 'internal' => array(), 'map_consent_present' => '1', 'map_consent' => '1' );
	$prepared = Records::prepare( $raw, $data, true );
	marcpo_map_check( ! is_wp_error($prepared) && true === $prepared['map_consent'] && !empty($prepared['map_consent_at']), 'Édition organisateur : accord explicitement confirmé et daté' );
	unset($raw['map_consent_present'], $raw['map_consent']);
	$preserved = Records::prepare($raw, $prepared, true);
	marcpo_map_check( !is_wp_error($preserved) && $preserved['map_consent_at'] === $prepared['map_consent_at'] && true === $preserved['map_consent'], 'Modification partielle : accord et date existants conservés' );
	$raw['map_consent_present'] = '1';
	$revoked = Records::prepare($raw, $prepared, true);
	marcpo_map_check( !is_wp_error($revoked) && false === $revoked['map_consent'] && '' === $revoked['map_consent_at'], 'Case décochée dans le dossier : retrait transmis au stockage' );
	// Exercise the untrusted provider response cached for this synthetic address.
	$data['map_consent'] = true; update_post_meta( $app, Records::META, $data );
	$valid_features = get_transient( $cache_key );
	marcpo_map_check( '1 rue fictive, 64100 Bayonne' === $valid_features[0]['properties']['label'] && ! isset( $valid_features[0]['properties']['unexpected'] ), 'Réponse IGN nettoyée et limitée aux propriétés utiles avant mise en cache' );
	$bad_features = array( 'invalid', array( 'invalid' ) );
	foreach ( array( array( 'properties', 'city', array() ), array( 'properties', 'score', 'dog' ), array( 'properties', 'score', 2 ), array( 'properties', 'label', array() ), array( 'geometry', 'coordinates', 'invalid' ), array( 'geometry', 'coordinates', array( INF, 43 ) ), array( 'geometry', 'coordinates', array( 181, 43 ) ) ) as [$group, $field, $bad] ) {
		$variant = $valid_features; $variant[0][$group][$field] = $bad; $bad_features[] = $variant;
	}
	$variant = $valid_features; $variant[1] = array( 'properties' => array( 'score' => 'dog' ) ); $bad_features[] = $variant;
	foreach ( $bad_features as $bad ) {
		set_transient( $cache_key, $bad, 60 ); GalleryMap::locate( $app );
		marcpo_map_check( ! get_post_meta( $app, '_marcpo_map_location', true ) && 1 === count( $calls ), 'Réponse IGN malformée refusée sans erreur de type, point publié ni appel réseau' );
	}
	set_transient( $cache_key, $valid_features, 60 ); GalleryMap::locate( $app );
	marcpo_map_check( is_array( get_post_meta( $app, '_marcpo_map_location', true ) ), 'Réponse IGN valide toujours acceptée après les refus' );
	foreach ( array( array( 'invalid' ), array( array( 'properties' => array( 'score' => 'dog' ) ) ) ) as $provider_features ) {
		delete_post_meta( $app, '_marcpo_map_location' ); delete_transient( $cache_key ); GalleryMap::locate( $app );
		marcpo_map_check( ! get_post_meta( $app, '_marcpo_map_location', true ) && false === get_transient( $cache_key ), 'Réponse HTTP IGN malformée rejetée avant mise en cache' );
	}
	echo "SUCCÈS : $checks vérifications cartographiques, aucun appel réseau externe.\n";
} finally {
	$settings = array();
	foreach ( array_reverse( $ids ) as $id ) { wp_clear_scheduled_hook('marcpo_locate_city', array($id)); wp_delete_post( $id, true ); }
	if ( $cache_key ) { delete_transient( $cache_key ); }
	remove_filter( 'pre_option_' . ExternalServices::OPTION, $options );
	remove_filter( 'pre_http_request', $network, PHP_INT_MAX );
}

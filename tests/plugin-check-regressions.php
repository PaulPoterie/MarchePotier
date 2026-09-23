<?php
/** Runs inside votes-local.php, with its isolated fixtures and intercepted emails. */
use MarchePotier\{Request,PublicForm,Fields,Gallery,VoteTracking,Records,Votes};

$saved_get = $_GET; $saved_post = $_POST; $saved_server = $_SERVER;
try {
	$search = "L'atelier d’Élodie \\ grès";
	$_GET = wp_slash( array( 's' => $search, 'marcpo_sort' => 'name_asc', 'marcpo_edition' => (string) $edition ) );
	marcpo_check( Records::list_context()['s'] === $search, 'Recherche HTTP : apostrophes, accents et antislash conservés après un seul unslash' );
	$_GET = array( 'marcpo_edition' => array( '1' ), 'marcpo_sort' => '<b>points_desc</b>' );
	$invalid_context = Records::list_context();
	marcpo_check( ! isset( $invalid_context['marcpo_edition'] ) && ! isset( $invalid_context['orderby'] ), 'Paramètres de navigation malformés refusés' );
	wp_set_current_user( $a );
	marcpo_denied( array( VoteTracking::class, 'render' ), 'Tableau au lieu d’un ID édition refusé dans le suivi' );
	foreach ( array( '<b>5</b>', '5.0', ' 5', '6', array( '5' ), "5\\" ) as $bad ) {
		$_POST = wp_slash( array( 'score' => $bad ) );
		marcpo_check( is_wp_error( Votes::record( $app, Request::post( 'score' ) ) ), 'Note HTTP malformée refusée sans conversion' );
	}
	marcpo_check( Votes::all( array() ) === array(), 'Lecture SQL de votes vide sans requête IN invalide' );
	$_SERVER['REMOTE_ADDR'] = '2001:db8::1';
	marcpo_check( Request::remote_address() === '2001:db8::1', 'IPv6 acceptée pour la limitation des tentatives' );
	$_SERVER['REMOTE_ADDR'] = array( '127.0.0.1' ); $_SERVER['CONTENT_LENGTH'] = array( '12' ); $_SERVER['REQUEST_METHOD'] = 'DELETE';
	marcpo_check( Request::remote_address() === 'unknown' && Request::content_length() === PHP_INT_MAX && Request::method() === 'INVALID', 'Métadonnées HTTP malformées gérées sans erreur de type' );
	$values = new ReflectionProperty( PublicForm::class, 'values' ); $before_values = $values->getValue();
	$malicious = '\"><script>alert(1)</script>&';
	$values->setValue( null, array( 'identity' => array( 'last_name' => $malicious ), 'activity' => array( 'presentation' => '</textarea><script>alert(2)</script>', 'professional_status' => 'artisan', 'production' => array( 'bijoux' ) ) ) );
	try {
		$render = new ReflectionMethod( PublicForm::class, 'render_group' );
		ob_start(); $render->invoke( null, Fields::identity(), 'identity', 'Coordonnées' ); $render->invoke( null, Fields::activity(), 'activity', 'Activité' ); $markup = ob_get_clean();
		marcpo_check( ! str_contains( $markup, '<script>' ) && str_contains( $markup, esc_attr( $malicious ) ), 'Rendu du formulaire échappe les valeurs et les fermetures de balise' );
		marcpo_check( str_contains( $markup, 'name="marcpo_record[activity][presentation]" required' ) && str_contains( $markup, 'data-parent="production" data-choice="autre"' ) && str_contains( $markup, 'aria-describedby="marcpo-presentation-help marcpo-presentation-count"' ), 'Attributs du formulaire, conditions et aides conservés' );
		marcpo_check( 1 === preg_match( '/value="bijoux"\s+checked=/', $markup ) && 1 === preg_match( '/value="artisan"\s+selected=/', $markup ), 'Cases multiples et choix sélectionnés conservés' );
	} finally { $values->setValue( null, $before_values ); }
	$icon = new ReflectionMethod( Gallery::class, 'render_icon' );
	ob_start(); $icon->invoke( null, 'website' ); $svg = ob_get_clean();
	marcpo_check( str_contains( $svg, '<svg viewBox=' ) && str_contains( $svg, '<circle' ), 'Icône SVG locale conservée' );

	// Representative bounded dataset: more than one page, multiple editions already exist.
	wp_set_current_user( 1 ); $_POST = array();
	$perf_edition = marcpo_post( 'marcpo_edition', 'Profilage métadonnées' ); $perf_ids = array();
	for ( $n = 0; $n < 60; ++$n ) {
		$id = marcpo_post( 'marcpo_candidature', 'Profilage ' . $n ); $perf_ids[] = $id;
		update_post_meta( $id, '_marcpo_edition_id', $perf_edition );
		update_post_meta( $id, Records::META, array( 'edition_id' => $perf_edition, 'identity' => array( 'email' => 'candidate' . $n . '@example.test' ) ) );
		wp_cache_delete( $id, 'post_meta' );
	}
	$meta_reads = 0;
	$observe = static function ( $sql ) use ( &$meta_reads ) { if ( str_contains( $sql, 'SELECT post_id, meta_key, meta_value FROM' ) ) { ++$meta_reads; } return $sql; };
	add_filter( 'query', $observe ); $started = microtime( true );
	try { $duplicate = PublicForm::duplicate( $perf_edition, 'candidate59@example.test' ); } finally { remove_filter( 'query', $observe ); }
	marcpo_check( $duplicate && $meta_reads === 1, 'Doublons : 60 dossiers examinés avec une seule lecture groupée des métadonnées' );
	echo sprintf( "PROFIL : 60 dossiers, %d requête de métadonnées, %.1f ms.\n", $meta_reads, 1000 * ( microtime( true ) - $started ) );
	marcpo_check( ! PublicForm::duplicate( $perf_edition, 'candidate59@example.test', $perf_ids[59] ), 'Le contrôle de doublon exclut exactement le dossier en cours' );
} finally { $_GET = $saved_get; $_POST = $saved_post; $_SERVER = $saved_server; wp_set_current_user( 1 ); }

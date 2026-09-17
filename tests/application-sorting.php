<?php
/** Tri global des dossiers : dates réelles, noms accentués, zéro et absence de note. */
use MarchePotier\{Jury,Records,Review,Votes};

wp_set_current_user( 1 );
$sort_edition = mp_post( 'mp_edition', 'Tri des candidatures' );
update_post_meta( $sort_edition, Jury::META, Jury::settings( $edition ) );
$sort_ids = array();
$sort_fixtures = array(
	array( 'Zèbre', 'Luc', '2026-09-01T12:00:00Z', '0' ),
	array( 'Éclair', 'Zoé', '2026-09-03T12:00:00Z', '2' ),
	array( 'eclair', 'Anne', '2026-09-02T14:00:00+02:00', '5' ),
	array( 'Albert', 'Nina', '2026-09-03T14:00:00+02:00', null ),
	array( '', 'Imane', '', null ),
);
foreach ( $sort_fixtures as $index => [ $last, $first, $submitted, $score ] ) {
	$id = mp_post( 'mp_candidature', 'Titre indépendant ' . ( 5 - $index ) ); $sort_ids[] = $id;
	wp_update_post( array( 'ID' => $id, 'post_date' => '2026-09-' . ( 20 - $index ) . ' 12:00:00' ) );
	update_post_meta( $id, Records::META, array( 'edition_id' => $sort_edition, 'potier_id' => $potier, 'identity' => array( 'last_name' => $last, 'first_name' => $first ), 'submitted_at' => $submitted, 'decision' => 'pending' ) );
	update_post_meta( $id, '_mp_edition_id', $sort_edition ); update_post_meta( $id, '_mp_decision', 'pending' );
	wp_set_current_user( $a );
	if ( null !== $score ) { mp_check( true === Votes::record( $id, $score ), 'Préparation d’une note pour le tri : ' . $score ); }
	wp_set_current_user( 1 );
}
[ $sort_a, $sort_b, $sort_c, $sort_d, $sort_e ] = $sort_ids;
$sort_expected = array(
	'points_desc' => array( $sort_c, $sort_b, $sort_a, $sort_d, $sort_e ),
	'points_asc' => array( $sort_a, $sort_b, $sort_c, $sort_d, $sort_e ),
	'submitted_desc' => array( $sort_d, $sort_b, $sort_c, $sort_a, $sort_e ),
	'submitted_asc' => array( $sort_a, $sort_c, $sort_b, $sort_d, $sort_e ),
	'name_asc' => array( $sort_d, $sort_c, $sort_b, $sort_a, $sort_e ),
	'name_desc' => array( $sort_a, $sort_b, $sort_c, $sort_d, $sort_e ),
);
wp_set_current_user( $a );
foreach ( $sort_expected as $sort_value => $expected ) {
	$_GET = array( 'mp_edition' => (string) $sort_edition, 'mp_sort' => $sort_value );
	$sort_context = Records::list_context();
	mp_check( Records::navigation_ids( $sort_context ) === $expected, 'Ordre global : ' . $sort_value );
	mp_check( Records::navigation_ids( array_merge( $sort_context, array( 'mp_edition' => $other ) ) ) === array(), 'Tri sans accès à une autre édition : ' . $sort_value );
}
mp_check( Records::navigation_ids( array( 'mp_edition' => $sort_edition ) ) === $sort_expected['submitted_desc'], 'Par défaut : soumissions récentes, dates absentes en dernier, égalités stables' );
$_GET = array( 'mp_edition' => (string) $sort_edition, 'mp_sort' => 'name_asc', 'mp_my_vote' => 'rated', 's' => 'eclair' );
mp_check( Records::navigation_ids( Records::list_context() ) === array( $sort_c, $sort_b ), 'Tri combiné à la recherche sans accents et au filtre de note personnelle' );
foreach ( array( 'unknown', array( 'name_asc' ) ) as $invalid_sort ) {
	$_GET = array( 'mp_sort' => $invalid_sort );
	mp_check( Records::list_context() === array(), 'Tri invalide ou non scalaire ignoré' );
}

// La pagination, les liens Examiner et les dossiers voisins gardent le même tri.
update_user_option( $a, 'mp_applications_per_page', 2 );
$_GET = array( 'mp_edition' => (string) $sort_edition, 'mp_sort' => 'name_asc', 'paged' => '2', 'mp_decision' => 'pending' );
ob_start(); Review::table(); $sort_html = ob_get_clean();
preg_match_all( '/data-mp-dossier="([^"]+)"/', $sort_html, $sort_rows );
$sort_row_ids = array();
foreach ( $sort_rows[1] as $url ) {
	parse_str( wp_parse_url( html_entity_decode( $url ), PHP_URL_QUERY ), $sort_query ); $sort_row_ids[] = (int) $sort_query['candidature'];
	mp_check( $sort_query['orderby'] === 'name' && $sort_query['order'] === 'ASC' && $sort_query['paged'] === '2' && $sort_query['mp_decision'] === 'pending', 'Lien Examiner conserve le tri, la page et le filtre' );
}
mp_check( $sort_row_ids === array( $sort_b, $sort_a ), 'La deuxième page correspond au tri de toute la liste' );
mp_check( str_contains( $sort_html, 'name="mp_sort" form="mp-application-filters"' ) && ! str_contains( $sort_html, 'name="paged"' ) && str_contains( $sort_html, '<noscript>' ), 'Tri lié au formulaire des filtres, retour à la première page et bouton sans JavaScript' );
mp_check( 1 === preg_match( '/value="name_asc"\s+selected=/', $sort_html ) && str_contains( $sort_html, '5 candidature(s)' ), 'Tri actif et compteur visibles' );
$_GET = array( 'candidature' => (string) $sort_b, 'mp_edition' => (string) $sort_edition, 'orderby' => 'name', 'order' => 'ASC', 'mp_from' => 'gestion' );
ob_start(); Records::view(); $sort_view = ob_get_clean();
mp_check( str_contains( $sort_view, esc_url( Records::view_url( $sort_c, Records::list_context() ) ) ) && str_contains( $sort_view, esc_url( Records::view_url( $sort_a, Records::list_context() ) ) ) && str_contains( $sort_view, 'Dossier 3 sur 5' ), 'Dossiers précédent et suivant suivent le nom, y compris entre deux pages' );
delete_user_option( $a, 'mp_applications_per_page' ); $_GET = array();
wp_set_current_user( 1 );

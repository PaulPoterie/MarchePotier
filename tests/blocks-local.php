<?php
/** Scénarios des blocs, exécutés avec les fixtures de votes-local.php. */
use MarchePotier\{Blocks,Editions,Jury,Records,PublicForm,Gallery,ExternalServices};
if ( ! isset( $state, $manager, $checks ) || PHP_SAPI !== 'cli' ) { exit; }

$registry = WP_Block_Type_Registry::get_instance();
marcpo_check( $registry->is_registered( Blocks::FORM ) && $registry->is_registered( Blocks::SELECTION ), 'Deux blocs WordPress enregistrés côté serveur' );
marcpo_check( $registry->get_registered( Blocks::FORM )->attributes['editionId']['type'] === 'integer' && $registry->get_registered( Blocks::FORM )->api_version === 3, 'Le bloc conserve un identifiant d’édition entier avec l’API des blocs actuelle' );
$block_edition_a = marcpo_post( 'marcpo_edition', 'Marché Alpha' ); $block_edition_b = marcpo_post( 'marcpo_edition', 'Marché Bêta' );
$block_settings = array( 'year' => '2030', 'opens' => '2020-01-01T00:00', 'closes' => '2099-12-31T23:00', 'selection_public' => false );
foreach ( array( $block_edition_a, $block_edition_b ) as $id ) { update_post_meta( $id, '_marcpo_edition_settings', $block_settings ); }
$form_a = '<!-- wp:' . Blocks::FORM . ' {"editionId":' . $block_edition_a . '} /-->';
$form_b = '<!-- wp:' . Blocks::FORM . ' {"editionId":' . $block_edition_b . '} /-->';
$selection_a = '<!-- wp:' . Blocks::SELECTION . ' {"editionId":' . $block_edition_a . '} /-->';
$selection_b = '<!-- wp:' . Blocks::SELECTION . ' {"editionId":' . $block_edition_b . '} /-->';
$page_form = marcpo_post( 'page', 'Formulaire bloc Alpha' ); wp_update_post( array( 'ID' => $page_form, 'post_content' => wp_slash( $form_a ) ) );
$page_selection = marcpo_post( 'page', 'Sélection bloc Alpha' ); wp_update_post( array( 'ID' => $page_selection, 'post_content' => wp_slash( $selection_a ) ) );
$state['block_edition_a'] = $block_edition_a; $state['block_edition_b'] = $block_edition_b; $state['block_form_page'] = $page_form; $state['block_selection_page'] = $page_selection; marcpo_state();

wp_set_current_user( $manager );
$response = rest_do_request( new WP_REST_Request( 'GET', '/marche-potier/v1/editions' ) ); $options = $response->get_data();
$options_by_id = array_column( $options, null, 'id' );
marcpo_check( $response->get_status() === 200 && isset( $options_by_id[ $block_edition_a ], $options_by_id[ $block_edition_b ] ) && $options_by_id[ $block_edition_a ]['label'] === '[TEST VOTES] Marché Alpha (2030)', 'Liste authentifiée : titre et année, deux éditions de la même année distinctes' );
wp_set_current_user( 0 ); $response = rest_do_request( new WP_REST_Request( 'GET', '/marche-potier/v1/editions' ) );
marcpo_check( $response->get_status() >= 400, 'La liste des éditions privées n’est pas exposée aux visiteurs' );
wp_set_current_user( $a ); $response = rest_do_request( new WP_REST_Request( 'GET', '/marche-potier/v1/editions' ) );
marcpo_check( $response->get_status() === 403, 'Le votant limité ne peut pas lister les éditions dans l’éditeur' );
wp_set_current_user( 0 );
$html_a = do_blocks( $form_a ); $html_b = do_blocks( $form_b );
marcpo_check( str_contains( $html_a, 'name="marcpo_edition" value="' . $block_edition_a . '"' ) && str_contains( $html_b, 'name="marcpo_edition" value="' . $block_edition_b . '"' ), 'Deux formulaires de la même année ciblent chacun leur édition par ID' );
marcpo_check( str_contains( $html_a, 'Marché Alpha (2030)' ) && ! str_contains( $html_a, 'Marché Bêta' ), 'Le formulaire affiche le titre de la bonne édition' );
update_post_meta( $block_edition_a, '_marcpo_edition_settings', array_merge( $block_settings, array( 'year' => '2031' ) ) );
marcpo_check( str_contains( do_blocks( $form_a ), 'Marché Alpha (2031)' ) && str_contains( do_blocks( $form_a ), 'name="marcpo_edition" value="' . $block_edition_a . '"' ), 'Changer l’année conserve l’édition ciblée par le bloc' );
marcpo_check( ! str_contains( do_blocks( '<!-- wp:' . Blocks::FORM . ' /-->' ), '<form ' ), 'Un bloc sans édition ne publie aucun formulaire' );
marcpo_check( ! str_contains( PublicForm::render( $page_form ), '<form ' ), 'Un identifiant d’un autre type de contenu ne peut pas devenir une édition' );
update_post_meta( $block_edition_a, '_marcpo_edition_settings', array_merge( $block_settings, array( 'opens' => '2020-01-01T00:00', 'closes' => '2020-02-01T00:00' ) ) );
marcpo_check( str_contains( do_blocks( $form_a ), 'Les candidatures sont actuellement fermées' ) && ! str_contains( do_blocks( $form_a ), '<form ' ), 'Le bloc formulaire respecte toujours les dates d’inscription' );
update_post_meta( $block_edition_a, '_marcpo_edition_settings', $block_settings );

$block_apps = array();
foreach ( array( $block_edition_a => 'Alpha', $block_edition_b => 'Bêta' ) as $id => $name ) {
	$block_app = marcpo_post( 'marcpo_candidature', 'Sélection ' . $name ); $block_apps[] = $block_app;
	$data = array( 'edition_id' => $id, 'potier_id' => $potier, 'decision' => 'selected', 'publication_consent' => true, 'identity' => array( 'last_name' => 'Potière ' . $name, 'first_name' => 'Test' ), 'activity' => array(), 'files' => array() );
	update_post_meta( $block_app, Records::META, $data ); update_post_meta( $block_app, '_marcpo_edition_id', $id );
}
marcpo_check( ! str_contains( do_blocks( $selection_a ), 'Potière Alpha' ), 'La sélection reste masquée sans autorisation publique' );
foreach ( array( $block_edition_a, $block_edition_b ) as $id ) { update_post_meta( $id, '_marcpo_edition_settings', array_merge( $block_settings, array( 'selection_public' => true ) ) ); }
$html_a = do_blocks( $selection_a ); $html_b = do_blocks( $selection_b );
marcpo_check( str_contains( $html_a, 'Potière Alpha' ) && ! str_contains( $html_a, 'Potière Bêta' ) && str_contains( $html_b, 'Potière Bêta' ) && ! str_contains( $html_b, 'Potière Alpha' ), 'Deux sélections de la même année ne mélangent jamais leurs candidatures' );
$data = Records::data( $block_apps[0] ); $data['publication_consent'] = false; update_post_meta( $block_apps[0], Records::META, $data );
marcpo_check( ! str_contains( do_blocks( $selection_a ), 'Potière Alpha' ), 'Le bloc sélection respecte l’autorisation de présentation du candidat' );
$data['publication_consent'] = true; update_post_meta( $block_apps[0], Records::META, $data );
wp_set_current_user( 1 ); wp_trash_post( $block_edition_a );
marcpo_check( ! str_contains( do_blocks( $form_a ), '<form ' ) && ! str_contains( do_blocks( $selection_a ), 'Potière Alpha' ) && str_contains( do_blocks( $form_b ), '<form ' ), 'Édition supprimée : aucun remplacement implicite par une édition de la même année' );
wp_untrash_post( $block_edition_a ); wp_update_post( array( 'ID' => $block_edition_a, 'post_status' => 'draft' ) );
marcpo_check( ! str_contains( do_blocks( $form_a ), '<form ' ) && ! str_contains( do_blocks( $selection_a ), 'Potière Alpha' ), 'Une édition non publiée ne rend pas ses formulaires et sélections publics' );
wp_update_post( array( 'ID' => $block_edition_a, 'post_status' => 'publish' ) );

$nested = '<!-- wp:group --><div class="wp-block-group">' . $form_a . '</div><!-- /wp:group -->';
marcpo_check( Blocks::contains( $nested, Blocks::FORM ) && Blocks::edition_ids( $nested, Blocks::FORM ) === array( $block_edition_a ), 'Détection du formulaire dans un groupe de blocs' );
$pattern = marcpo_post( 'wp_block', 'Composition avec formulaire' );
wp_update_post( array( 'ID' => $pattern, 'post_content' => wp_slash( $nested . '<!-- wp:block {"ref":' . $pattern . '} /-->' ) ) );
$reference = '<!-- wp:block {"ref":' . $pattern . '} /-->';
marcpo_check( Blocks::contains( $reference, Blocks::FORM ) && Blocks::edition_ids( $reference, Blocks::FORM ) === array( $block_edition_a ), 'Détection dans une composition synchronisée, même en présence d’une référence circulaire' );

$saved_query = $GLOBALS['wp_query']; $saved_post = $GLOBALS['post'] ?? null;
$GLOBALS['wp_query'] = new WP_Query( array( 'page_id' => $page_form ) ); $GLOBALS['post'] = get_post( $page_form );
do_action( 'wp_enqueue_scripts' );
marcpo_check( wp_script_is( 'marcpo-form', 'enqueued' ) && wp_script_is( 'marcpo-draft', 'enqueued' ) && wp_style_is( 'marcpo-form', 'enqueued' ), 'Le bloc charge les styles, la sauvegarde des réponses et les transferts de pièces' );
$GLOBALS['wp_query'] = new WP_Query( array( 'page_id' => $page_selection ) ); $GLOBALS['post'] = get_post( $page_selection );
$map_options = static fn() => array( 'ign' => false, 'osm' => false );
add_filter( 'pre_option_' . ExternalServices::OPTION, $map_options );
do_action( 'wp_enqueue_scripts' );
marcpo_check( wp_script_is( 'marcpo-gallery', 'enqueued' ) && wp_style_is( 'marcpo-gallery', 'enqueued' ) && ! wp_script_is( 'marcpo-leaflet', 'enqueued' ), 'Le bloc sélection charge la galerie sans Leaflet lorsque le service est désactivé' );
remove_filter( 'pre_option_' . ExternalServices::OPTION, $map_options );
$map_options = static fn() => array( 'ign' => false, 'osm' => true );
add_filter( 'pre_option_' . ExternalServices::OPTION, $map_options );
do_action( 'wp_enqueue_scripts' );
marcpo_check( wp_script_is( 'marcpo-leaflet', 'enqueued' ) && wp_style_is( 'marcpo-leaflet', 'enqueued' ), 'Leaflet chargé lorsque le service cartographique est autorisé' );
remove_filter( 'pre_option_' . ExternalServices::OPTION, $map_options );
$GLOBALS['wp_query'] = $saved_query; $GLOBALS['post'] = $saved_post;
wp_set_current_user( $manager );
ob_start(); Editions::render_box( get_post( $block_edition_a ) ); $html = ob_get_clean();
marcpo_check( strpos( $html, 'Édition de l’année' ) < strpos( $html, 'Date de début du marché' ) && ! str_contains( $html, 'shortcode' ) && ! str_contains( $html, 'selection_public' ), 'Année avant la date de début, aucune proposition de shortcode ni sélection publique parmi les paramètres' );
ob_start(); Editions::publication_box( get_post( $block_edition_a ) ); $html = ob_get_clean();
marcpo_check( str_contains( $html, 'Formulaire de candidature' ) && str_contains( $html, 'Présentation de la sélection' ) && str_contains( $html, 'name="marcpo_edition[selection_public]"' ), 'Nouvelle rubrique Affichage sur le site : les deux blocs et l’autorisation de publication' );
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
Editions::add_box(); global $wp_meta_boxes;
marcpo_check( isset( $wp_meta_boxes['marcpo_edition']['normal']['low']['marcpo-edition-publication'] ), 'La rubrique d’affichage se place après les rôles en bas de l’édition' );
ob_start(); Jury::box( get_post( $block_edition_a ) ); $html = ob_get_clean();
marcpo_check( ! str_contains( $html, 'Ancien contact' ) && ! str_contains( $html, 'marcpo_jury[closed]' ), 'Ancien contact et clôture des votes absents de l’écran d’édition' );
wp_set_current_user( 1 );

marcpo_check( ! shortcode_exists( 'inscription_potier' ) && ! shortcode_exists( 'afficher_selection' ) && ! str_contains( do_shortcode( '[inscription_potier edition="2030"][afficher_selection edition="2030"]' ), '<form ' ), 'Anciens shortcodes inactifs : aucun formulaire ni sélection rendu' );

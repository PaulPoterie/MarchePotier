<?php
/** Blocs publics reliés à une édition par son identifiant WordPress. */
namespace MarchePotier;
defined( 'ABSPATH' ) || exit;

final class Blocks {
	public const FORM = 'marche-potier/formulaire-candidature';
	public const SELECTION = 'marche-potier/presentation-selection';
	public static function hooks(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_action( 'rest_api_init', static function () {
			register_rest_route( 'marche-potier/v1', '/editions', array(
				'methods' => 'GET',
				'permission_callback' => static fn() => current_user_can( 'mp_manage_editions' ),
				'callback' => array( self::class, 'edition_options' ),
			) );
		} );
	}
	public static function register(): void {
		wp_register_script( 'mp-blocks', plugins_url( '../assets/blocks.js', __FILE__ ), array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-api-fetch' ), '0.19.0', true );
		wp_register_style( 'mp-blocks-editor', plugins_url( '../assets/blocks-editor.css', __FILE__ ), array(), '0.19.0' );
		foreach ( array( self::FORM => 'Formulaire de candidature', self::SELECTION => 'Présentation de la sélection' ) as $name => $title ) {
			register_block_type( $name, array(
				'api_version' => 3,
				'title' => $title,
				'category' => 'widgets',
				'icon' => self::FORM === $name ? 'feedback' : 'groups',
				'attributes' => array( 'editionId' => array( 'type' => 'integer', 'default' => 0 ) ),
				'supports' => array( 'html' => false, 'multiple' => false, 'reusable' => false ),
				'editor_script_handles' => array( 'mp-blocks' ),
				'editor_style_handles' => array( 'mp-blocks-editor' ),
				'render_callback' => static function ( array $attributes ) use ( $name ): string {
					$id = (int) ( $attributes['editionId'] ?? 0 );
					return self::FORM === $name ? PublicForm::render( $id ) : Gallery::render_edition( $id );
				},
			) );
		}
	}
	public static function edition_label( int $id ): string {
		$title = sanitize_text_field( wp_specialchars_decode( get_the_title( $id ), ENT_QUOTES ) ) ?: 'Édition sans titre';
		$year = Editions::settings( $id )['year'];
		return $title . ' (' . ( $year ?: 'année à renseigner' ) . ')';
	}
	/** Seuls les responsables authentifiés obtiennent la liste des éditions privées. */
	public static function edition_options(): array {
		$rows = array();
		foreach ( get_posts( array( 'post_type' => 'mp_edition', 'post_status' => array( 'publish', 'private', 'draft', 'pending', 'future' ), 'posts_per_page' => -1, 'orderby' => array( 'title' => 'ASC', 'ID' => 'ASC' ) ) ) as $edition ) {
			$rows[] = array( 'id' => $edition->ID, 'label' => self::edition_label( $edition->ID ), 'published' => 'publish' === $edition->post_status, 'selectionPublic' => Editions::selection_is_public( $edition->ID ) );
		}
		return $rows;
	}
	/** Recherche aussi dans les groupes et les compositions synchronisées, sans boucle. */
	private static function attributes_in( string $content, string $name, array $visited = array() ): array {
		$found = array();
		$walk = static function ( array $blocks ) use ( &$walk, &$found, $name, $visited ): void {
			foreach ( $blocks as $block ) {
				if ( $name === $block['blockName'] ) { $found[] = $block['attrs']; }
				if ( 'core/block' === $block['blockName'] && ! empty( $block['attrs']['ref'] ) ) {
					$ref = (int) $block['attrs']['ref'];
					if ( ! in_array( $ref, $visited, true ) ) {
						$pattern = get_post( $ref );
						if ( $pattern && 'wp_block' === $pattern->post_type && 'publish' === $pattern->post_status ) { $found = array_merge( $found, self::attributes_in( $pattern->post_content, $name, array_merge( $visited, array( $ref ) ) ) ); }
					}
				}
				if ( ! empty( $block['innerBlocks'] ) ) { $walk( $block['innerBlocks'] ); }
			}
		};
		$walk( parse_blocks( $content ) );
		return $found;
	}
	public static function contains( string $content, string $name ): bool { return (bool) self::attributes_in( $content, $name ); }
	public static function edition_ids( string $content, string $name ): array {
		return array_values( array_unique( array_filter( array_map( static fn( $attrs ) => is_int( $attrs['editionId'] ?? null ) && $attrs['editionId'] > 0 ? $attrs['editionId'] : 0, self::attributes_in( $content, $name ) ) ) ) );
	}
}

<?php
namespace MarchePotier;
defined( 'ABSPATH' ) || exit;

final class Gallery {
	public static function hooks(): void {
		add_action( 'wp_enqueue_scripts', static function () {
			$post = get_queried_object();
			if ( $post instanceof \WP_Post && Blocks::contains( $post->post_content, Blocks::SELECTION ) ) {
				wp_enqueue_style( 'mp-leaflet', plugins_url( '../assets/vendor/leaflet/leaflet.css', __FILE__ ), array(), '1.9.4' );
				wp_enqueue_script( 'mp-leaflet', plugins_url( '../assets/vendor/leaflet/leaflet.js', __FILE__ ), array(), '1.9.4', true );
				wp_enqueue_style( 'mp-gallery', plugins_url( '../assets/gallery.css', __FILE__ ), array( 'mp-leaflet' ), '0.8.0' );
				wp_enqueue_script( 'mp-gallery', plugins_url( '../assets/gallery.js', __FILE__ ), array( 'mp-leaflet' ), '0.8.0', true );
			}
		} );
		add_action( 'admin_post_mp_gallery_photo', array( self::class, 'photo' ) );
		add_action( 'admin_post_nopriv_mp_gallery_photo', array( self::class, 'photo' ) );
	}

	private static function render_icon( string $key ): void {
		$paths = array(
			'website' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c5 5 5 13 0 18-5-5-5-13 0-18Z"/>',
			'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r=".7" fill="currentColor" stroke="none"/>',
			'facebook' => '<path d="M14 21v-8h3l.5-4H14V7c0-1 .4-2 2-2h2V1.5c-.7-.2-1.8-.3-3-.3-3 0-5 1.8-5 5V9H7v4h3v8"/>',
		);
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG paths are fixed literals in the local allowlist above; no user content.
		echo '<svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ( $paths[ $key ] ?? '' ) . '</svg>';
	}

	public static function eligible( int $id ): bool {
		$data = Records::data( $id );
		return 'mp_candidature' === get_post_type( $id ) && in_array( get_post_status( $id ), array( 'publish', 'private', 'draft', 'pending', 'future' ), true ) && 'selected' === ( $data['decision'] ?? '' ) && ! empty( $data['publication_consent'] ) && Editions::selection_is_public( (int) ( $data['edition_id'] ?? 0 ) );
	}
	/** Diffusion publique des photos de créations après contrôle de sélection et consentement. Jamais de PDF. */
	public static function photo(): void {
		$id = (int) ( Request::query( 'application' ) ?? 0 );
		$slot = Request::query( 'slot' ) ?? '';
		if ( ! in_array( $slot, array( 'product1', 'product2', 'product3' ), true ) || ! self::eligible( $id ) ) { wp_die( 'Photo indisponible.', '', array( 'response' => 404 ) ); }
		PrivateFiles::download();
	}
	public static function render_edition( int $edition ): string {
		if ( ! $edition || ! Editions::selection_is_public( $edition ) ) { return '<p>La sélection de cette édition n’est pas encore publiée.</p>'; }
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Edition-scoped complete gallery; metadata is primed in one batch below before permission filtering.
		$ids = get_posts( array( 'post_type' => 'mp_candidature', 'post_status' => array( 'publish', 'private', 'draft', 'pending', 'future' ), 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => '_mp_edition_id', 'meta_value' => $edition, 'orderby' => array( 'title' => 'ASC', 'ID' => 'ASC' ) ) );
		update_meta_cache( 'post', $ids );
		$ids = array_values( array_filter( $ids, array( self::class, 'eligible' ) ) );
		if ( ! $ids ) { return '<p>Aucun potier à présenter pour cette édition.</p>'; }
		$prefix = wp_unique_id( 'mp-card-' );
		ob_start();
		echo '<section class="mp-gallery" aria-label="Potiers sélectionnés — ' . esc_attr( Blocks::edition_label( $edition ) ) . '"><div class="mp-gallery-grid">';
		foreach ( $ids as $id ) {
			$data = Records::data( $id ); $identity = $data['identity'];
			$name = trim( ( $identity['last_name'] ?? '' ) . ' ' . ( $identity['first_name'] ?? '' ) );
			echo '<article class="mp-gallery-card" id="' . esc_attr( $prefix . $id ) . '"><div class="mp-gallery-slider" role="group" aria-label="Photos de ' . esc_attr( $name ) . '"><div class="mp-gallery-slides">';
			$count = 0;
			foreach ( array( 'product1', 'product2', 'product3' ) as $slot ) {
				$file = $data['files'][ $slot ] ?? array(); if ( ! $file ) { continue; }
				$url = ! empty( $file['attachment_id'] ) ? wp_get_attachment_image_url( $file['attachment_id'], 'large' ) : PrivateFiles::file_url( $file );
				if ( ! $url ) { continue; }
				++$count;
				echo '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $name . ' — pièce ' . $count ) . '" width="800" height="800" loading="lazy"' . ( $count > 1 ? ' hidden' : '' ) . '>';
			}
			if ( ! $count ) { echo '<div class="mp-gallery-placeholder">Photos à venir</div>'; }
			echo '</div>';
			if ( $count > 1 ) {
				echo '<div class="mp-gallery-slide-controls" hidden><button class="mp-gallery-arrow" type="button" data-slide="-1" aria-label="Photo précédente">‹</button><div class="mp-gallery-dots">';
				for ( $n = 0; $n < $count; ++$n ) { echo '<button type="button" data-photo="' . esc_attr( $n ) . '" aria-label="Afficher la photo ' . esc_attr( $n + 1 ) . '" aria-pressed="' . ( 0 === $n ? 'true' : 'false' ) . '"><span></span></button>'; }
				echo '</div><button class="mp-gallery-arrow" type="button" data-slide="1" aria-label="Photo suivante">›</button><span class="mp-gallery-sr" aria-live="polite">Photo 1 sur ' . esc_html( $count ) . '</span></div>';
			}
			echo '</div><div class="mp-gallery-info"><h3>' . esc_html( $name ) . '</h3><p>' . esc_html( trim( ( $identity['postcode'] ?? '' ) . ' ' . ( $identity['city'] ?? '' ) ) ) . '</p>';
			$techniques = array();
			foreach ( $data['activity']['technique'] ?? array() as $key ) { $techniques[] = 'autre' === $key ? ( $data['activity']['technique_other'] ?? 'Autre' ) : ( Fields::activity()['technique'][3][ $key ] ?? '' ); }
			echo '<p class="mp-gallery-techniques">' . esc_html( implode( ' · ', array_filter( $techniques ) ) ) . '</p><div class="mp-gallery-links">';
			foreach ( array( 'website' => 'Site web', 'facebook' => 'Facebook', 'instagram' => 'Instagram' ) as $key => $label ) {
				$url = $identity[ $key ] ?? ''; if ( ! is_string( $url ) || ! preg_match( '~^https?://~i', $url ) ) { continue; }
				echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr( $label . ' — ' . $name ) . '" title="' . esc_attr( $label ) . '">';
				self::render_icon( $key );
				echo '</a>';
			}
			echo '</div></div></article>';
		}
		echo '</div>';
		GalleryMap::render( $ids, $prefix );
		echo '</section>';
		return ob_get_clean();
	}
}

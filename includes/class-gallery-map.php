<?php
namespace MarchePotier;
defined( 'ABSPATH' ) || exit;

final class GalleryMap {
	public static function hooks(): void {
		add_action( 'admin_init', static function () {
			if ( ! current_user_can( 'mp_manage_applications' ) ) { return; }
			foreach ( get_posts( array( 'post_type' => 'mp_candidature', 'post_status' => array( 'publish', 'draft', 'private', 'pending', 'future' ), 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $id ) {
				if ( ! Gallery::eligible( $id ) || get_post_meta( $id, '_mp_map_location', true ) || get_post_meta( $id, '_mp_map_error', true ) ) { continue; }
				if ( ! wp_next_scheduled( 'mp_locate_city', array( $id ) ) ) { wp_schedule_single_event( time() + 10, 'mp_locate_city', array( $id ) ); }
			}
		} );
		foreach ( array( 'added_post_meta', 'updated_post_meta' ) as $hook ) {
			add_action( $hook, static function( $meta_id, $id, $key ) {
				if ( Records::META === $key && 'mp_candidature' === get_post_type( $id ) && ! wp_next_scheduled( 'mp_locate_city', array( $id ) ) ) { wp_schedule_single_event( time() + 10, 'mp_locate_city', array( $id ) ); }
			}, 10, 3 );
		}
		add_action( 'mp_locate_city', array( self::class, 'locate' ) );
	}
	public static function signature( array $identity ): string {
		return hash( 'sha256', wp_json_encode( array_intersect_key( $identity, array_flip( array( 'address', 'city', 'postcode', 'country' ) ) ) ) );
	}
	private static function normalize( string $value ): string { return preg_replace( '/[^a-z0-9]/', '', strtolower( remove_accents( $value ) ) ); }
	/** Géocodage IGN des adresses françaises ; seuls les éléments de l'adresse sont transmis. */
	public static function locate( int $id ): void {
		if ( ! Gallery::eligible( $id ) ) { return; }
		$identity = Records::data( $id )['identity'] ?? array();
		$signature = self::signature( $identity );
		$old = get_post_meta( $id, '_mp_map_location', true );
		if ( is_array( $old ) && ( $old['signature'] ?? '' ) === $signature ) { return; }
		$city = $identity['city'] ?? ''; $postcode = $identity['postcode'] ?? ''; $address = $identity['address'] ?? '';
		$country = self::normalize( $identity['country'] ?? 'France' );
		update_post_meta( $id, '_mp_map_error', 'Adresse non localisée : vérifiez la rue, le code postal et la ville. Localisation automatique disponible pour la France.' );
		if ( ! $city || ! $address || ! preg_match( '/^\d{5}$/D', $postcode ) || ! in_array( $country, array( '', 'france', 'fr', 'francemetropolitaine' ), true ) ) { return; }
		$key = 'mp_address_' . $signature;
		$features = get_transient( $key );
		if ( false === $features ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Existing documented mp_ provider filter API; preserve site customizations.
			$response = wp_remote_get( add_query_arg( array( 'q' => trim( $address . ' ' . $postcode . ' ' . $city ), 'index' => 'address', 'limit' => 2 ), apply_filters( 'mp_address_geocoder_url', 'https://data.geopf.fr/geocodage/search' ) ), array( 'timeout' => 8, 'user-agent' => 'MarchePotier/0.8.0 (' . home_url() . ')' ) );
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return; }
			$json = json_decode( wp_remote_retrieve_body( $response ), true );
			$features = $json['features'] ?? null;
			if ( ! is_array( $features ) ) { return; }
			set_transient( $key, $features, 180 * DAY_IN_SECONDS );
		}
		$match = $features[0] ?? array(); $props = $match['properties'] ?? array();
		if ( ( $props['score'] ?? 0 ) < .75 || ( $props['postcode'] ?? '' ) !== $postcode || self::normalize( $props['city'] ?? '' ) !== self::normalize( $city ) || ! in_array( $props['type'] ?? '', array( 'housenumber', 'street' ), true ) ) { return; }
		// Une adresse avec numéro ne doit pas devenir un simple centre de rue.
		if ( preg_match( '/^\s*\d+/', $address ) && 'housenumber' !== $props['type'] ) { return; }
		if ( isset( $features[1]['properties']['score'] ) && abs( $props['score'] - $features[1]['properties']['score'] ) < .02 ) { return; }
		$coords = $match['geometry']['coordinates'] ?? array();
		if ( count( $coords ) !== 2 || ! is_numeric( $coords[0] ) || ! is_numeric( $coords[1] ) || abs( (float) $coords[0] ) > 180 || abs( (float) $coords[1] ) > 90 ) { return; }
		update_post_meta( $id, '_mp_map_location', array( 'signature' => $signature, 'lat' => (float) $coords[1], 'lon' => (float) $coords[0], 'label' => sanitize_text_field( $props['label'] ?? '' ) ) );
		delete_post_meta( $id, '_mp_map_error' );
	}
	public static function render( array $ids, string $prefix ): void {
		$points = array();
		foreach ( $ids as $id ) {
			if ( ! Gallery::eligible( $id ) ) { continue; }
			$identity = Records::data( $id )['identity'] ?? array(); $point = get_post_meta( $id, '_mp_map_location', true );
			if ( ! is_array( $point ) || ( $point['signature'] ?? '' ) !== self::signature( $identity ) ) { continue; }
			$points[] = array( 'lat' => $point['lat'], 'lon' => $point['lon'], 'name' => trim( ( $identity['last_name'] ?? '' ) . ' ' . ( $identity['first_name'] ?? '' ) ), 'city' => $identity['city'] ?? '', 'address' => trim( ( $identity['address'] ?? '' ) . ', ' . ( $identity['postcode'] ?? '' ) . ' ' . ( $identity['city'] ?? '' ) ), 'target' => $prefix . $id );
		}
		if ( ! $points ) { return; }
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Existing documented mp_ provider filter API; preserve site customizations.
		echo '<section class="mp-map-section" aria-label="Carte des potiers"><div class="mp-map-heading"><div><p class="mp-map-eyebrow">À travers les ateliers</p><h2>Les potiers sur la carte</h2></div><p>' . esc_html( count( $points ) ) . ' potiers localisés · adresses des candidats</p></div><div class="mp-gallery-map" aria-label="Carte OpenStreetMap des adresses des potiers" data-points="' . esc_attr( wp_json_encode( $points ) ) . '" data-tiles="' . esc_attr( apply_filters( 'mp_map_tile_url', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png' ) ) . '"></div><p class="mp-map-caption">Cliquez sur un point pour retrouver les potiers à cette adresse. Adresses déclarées par les candidats. Fond de carte : <a href="https://www.openstreetmap.org/copyright">© OpenStreetMap</a>.</p></section>';
	}
}

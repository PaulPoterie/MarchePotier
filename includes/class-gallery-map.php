<?php
namespace MarchePotier;
defined( 'ABSPATH' ) || exit;

final class GalleryMap {
	public static function hooks(): void {
		add_action( 'admin_init', static function () {
			if ( ! ExternalServices::enabled( 'ign' ) || ! current_user_can( 'marcpo_manage_applications' ) ) { return; }
			foreach ( get_posts( array( 'post_type' => 'marcpo_candidature', 'post_status' => array( 'publish', 'draft', 'private', 'pending', 'future' ), 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $id ) {
				if ( ! self::eligible( $id ) || get_post_meta( $id, '_marcpo_map_location', true ) || get_post_meta( $id, '_marcpo_map_error', true ) ) { continue; }
				if ( ! wp_next_scheduled( 'marcpo_locate_city', array( $id ) ) ) { wp_schedule_single_event( time() + 10, 'marcpo_locate_city', array( $id ) ); }
			}
		} );
		foreach ( array( 'added_post_meta', 'updated_post_meta' ) as $hook ) {
			add_action( $hook, static function( $meta_id, $id, $key ) {
				if ( Records::META !== $key || 'marcpo_candidature' !== get_post_type( $id ) ) { return; }
				if ( true !== ( Records::data( $id )['map_consent'] ?? false ) ) {
					wp_clear_scheduled_hook( 'marcpo_locate_city', array( $id ) );
					delete_post_meta( $id, '_marcpo_map_location' );
					delete_post_meta( $id, '_marcpo_map_error' );
					return;
				}
				if ( ExternalServices::enabled( 'ign' ) && self::eligible( $id ) && ! wp_next_scheduled( 'marcpo_locate_city', array( $id ) ) ) { wp_schedule_single_event( time() + 10, 'marcpo_locate_city', array( $id ) ); }
			}, 10, 3 );
		}
		add_action( 'marcpo_locate_city', array( self::class, 'locate' ) );
	}
	/** Gallery publication never implies permission to send or publish the address. */
	public static function eligible( int $id ): bool {
		return Gallery::eligible( $id ) && true === ( Records::data( $id )['map_consent'] ?? false );
	}
	public static function signature( array $identity ): string {
		return hash( 'sha256', wp_json_encode( array_intersect_key( $identity, array_flip( array( 'address', 'city', 'postcode', 'country' ) ) ) ) );
	}
	private static function normalize( string $value ): string { return preg_replace( '/[^a-z0-9]/', '', strtolower( remove_accents( $value ) ) ); }
	private static function expand_saint( string $value ): string { return preg_replace( '/(?<![\p{L}\p{N}])st\b\.?/iu', 'saint', $value ); }
	/** Validate the external JSON (and cached copies) before indexing, arithmetic or string operations. */
	private static function valid_feature( mixed $feature ): bool {
		if ( ! is_array( $feature ) || ! is_array( $feature['properties'] ?? null ) || ! is_array( $feature['geometry'] ?? null ) ) { return false; }
		$props = $feature['properties']; $coords = $feature['geometry']['coordinates'] ?? null;
		foreach ( array( 'postcode', 'city', 'type', 'label' ) as $key ) { if ( ! is_string( $props[ $key ] ?? null ) ) { return false; } }
		$score = $props['score'] ?? null;
		if ( ! is_numeric( $score ) || ! is_finite( (float) $score ) || $score < 0 || $score > 1 || ! is_array( $coords ) || count( $coords ) !== 2 ) { return false; }
		foreach ( array( 0 => 180, 1 => 90 ) as $axis => $limit ) {
			if ( ! isset( $coords[ $axis ] ) || ! is_numeric( $coords[ $axis ] ) || ! is_finite( (float) $coords[ $axis ] ) || abs( (float) $coords[ $axis ] ) > $limit ) { return false; }
		}
		return true;
	}
	/** Géocodage IGN des adresses françaises ; seuls les éléments de l'adresse sont transmis. */
	public static function locate( int $id ): void {
		if ( ! ExternalServices::enabled( 'ign' ) || ! self::eligible( $id ) ) { return; }
		$identity = Records::data( $id )['identity'] ?? array();
		$signature = self::signature( $identity );
		$old = get_post_meta( $id, '_marcpo_map_location', true );
		if ( is_array( $old ) && ( $old['signature'] ?? '' ) === $signature ) { return; }
		update_post_meta( $id, '_marcpo_map_error', 'Adresse non localisée : vérifiez la rue, le code postal et la ville. Localisation automatique disponible pour la France.' );
		foreach ( array( 'city', 'postcode', 'address', 'country' ) as $field ) { if ( isset( $identity[ $field ] ) && ! is_string( $identity[ $field ] ) ) { return; } }
		$city = trim( self::expand_saint( $identity['city'] ?? '' ) );
		$postcode = preg_replace( '/[\s\p{Z}]+/u', '', $identity['postcode'] ?? '' );
		$address = trim( self::expand_saint( $identity['address'] ?? '' ) );
		$country = self::normalize( $identity['country'] ?? 'France' );
		if ( in_array( $country, array( 'paysbasque', 'euskalherri', 'euskalherria' ), true ) ) { $country = 'france'; }
		if ( ! $city || ! preg_match( '/^\d{5}$/D', $postcode ) || ! in_array( $country, array( '', 'france', 'fr', 'francemetropolitaine' ), true ) ) { return; }
		$features = $address ? self::features( 'marcpo_address_v2_' . $signature, array( 'q' => trim( $address . ' ' . $postcode . ' ' . $city ), 'index' => 'address', 'limit' => 2 ) ) : array();
		if ( null === $features ) { return; }
		$match = $features[0] ?? null;
		$props = $match['properties'] ?? array();
		$precise = $match && $props['score'] >= .75 && $props['postcode'] === $postcode && self::normalize( self::expand_saint( $props['city'] ) ) === self::normalize( $city ) && in_array( $props['type'], array( 'housenumber', 'street' ), true );
		if ( preg_match( '/^\s*\d+/', $address ) && ( $props['type'] ?? '' ) !== 'housenumber' ) { $precise = false; }
		if ( isset( $features[1] ) && abs( $props['score'] - $features[1]['properties']['score'] ) < .02 ) { $precise = false; }
		if ( ! $precise ) {
			// A municipality result is an approximate position, never a guessed street.
			$features = self::features( 'marcpo_commune_v2_' . $signature, array( 'q' => $city, 'index' => 'address', 'type' => 'municipality', 'postcode' => $postcode, 'limit' => 2 ) );
			if ( ! $features ) { return; }
			$match = $features[0]; $props = $match['properties'];
			if ( 'municipality' !== $props['type'] || $props['postcode'] !== $postcode || $props['score'] < .5 ) { return; }
			if ( isset( $features[1] ) && abs( $props['score'] - $features[1]['properties']['score'] ) < .1 ) { return; }
		}
		$coords = $match['geometry']['coordinates'];
		update_post_meta( $id, '_marcpo_map_location', array( 'signature' => $signature, 'lat' => (float) $coords[1], 'lon' => (float) $coords[0], 'label' => sanitize_text_field( $props['label'] ), 'precision' => $precise ? 'address' : 'municipality' ) );
		delete_post_meta( $id, '_marcpo_map_error' );
	}
	/** Null means an invalid response or transport failure; an empty list is a valid miss. */
	private static function features( string $key, array $query ): ?array {
		$features = get_transient( $key );
		if ( false === $features ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Existing documented marcpo_ provider filter API; preserve site customizations.
			$response = wp_remote_get( add_query_arg( $query, apply_filters( 'marcpo_address_geocoder_url', 'https://data.geopf.fr/geocodage/search' ) ), array( 'timeout' => 8, 'user-agent' => 'PoterieNavarraiseMarketManager/0.19.1 (' . home_url() . ')' ) );
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return null; }
			$json = json_decode( wp_remote_retrieve_body( $response ), true );
			$features = is_array( $json ) ? ( $json['features'] ?? null ) : null;
			if ( ! is_array( $features ) || ! array_is_list( $features ) ) { return null; }
			$features = array_slice( $features, 0, 2 );
			foreach ( $features as &$feature ) {
				if ( ! self::valid_feature( $feature ) ) { return null; }
				$props = $feature['properties'];
				$feature = array( 'properties' => array( 'score' => (float) $props['score'], 'postcode' => sanitize_text_field( $props['postcode'] ), 'city' => sanitize_text_field( $props['city'] ), 'type' => sanitize_key( $props['type'] ), 'label' => sanitize_text_field( $props['label'] ) ), 'geometry' => array( 'coordinates' => array_map( 'floatval', $feature['geometry']['coordinates'] ) ) );
			}
			unset( $feature );
			set_transient( $key, $features, 180 * DAY_IN_SECONDS );
		}
		if ( ! is_array( $features ) || ! array_is_list( $features ) || count( $features ) > 2 ) { return null; }
		foreach ( $features as $feature ) { if ( ! self::valid_feature( $feature ) ) { return null; } }
		return $features;
	}
	public static function render( array $ids, string $prefix ): void {
		if ( ! ExternalServices::enabled( 'osm' ) ) { return; }
		$points = array();
		foreach ( $ids as $id ) {
			if ( ! self::eligible( $id ) ) { continue; }
			$identity = Records::data( $id )['identity'] ?? array(); $point = get_post_meta( $id, '_marcpo_map_location', true );
			if ( ! is_array( $point ) || ( $point['signature'] ?? '' ) !== self::signature( $identity ) ) { continue; }
			$address = ( $point['precision'] ?? '' ) === 'municipality' ? 'Centre de la commune : ' . ( $point['label'] ?? $identity['city'] ?? '' ) . ' (position approximative)' : trim( ( $identity['address'] ?? '' ) . ', ' . ( $identity['postcode'] ?? '' ) . ' ' . ( $identity['city'] ?? '' ) );
			$points[] = array( 'lat' => $point['lat'], 'lon' => $point['lon'], 'name' => trim( ( $identity['last_name'] ?? '' ) . ' ' . ( $identity['first_name'] ?? '' ) ), 'city' => $identity['city'] ?? '', 'address' => $address, 'target' => $prefix . $id );
		}
		if ( ! $points ) { return; }
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Existing documented marcpo_ provider filter API; preserve site customizations.
		echo '<section class="marcpo-map-section" aria-label="Carte des potiers"><div class="marcpo-map-heading"><div><p class="marcpo-map-eyebrow">À travers les ateliers</p><h2>Les potiers sur la carte</h2></div><p>' . esc_html( count( $points ) ) . ' potiers localisés · adresses ou communes</p></div><div class="marcpo-gallery-map" aria-label="Carte OpenStreetMap des adresses des potiers" data-points="' . esc_attr( wp_json_encode( $points ) ) . '" data-tiles="' . esc_attr( apply_filters( 'marcpo_map_tile_url', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png' ) ) . '"></div><p class="marcpo-map-caption">Cliquez sur un point pour retrouver les potiers. Si leur adresse précise ne peut être localisée, le centre de leur commune est affiché avec la mention « position approximative ». Fond de carte : <a href="https://www.openstreetmap.org/copyright">© OpenStreetMap</a>.</p></section>';
	}
}

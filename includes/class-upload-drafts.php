<?php
namespace MarchePotier;
defined( 'ABSPATH' ) || exit;

/** Pièces temporaires liées au cookie signé du formulaire, jamais à un chemin client. */
final class UploadDrafts {
	private const PREFIX = 'mp_upload_draft_';
	public static function hooks(): void {
		add_action( 'mp_cleanup_upload_drafts', array( self::class, 'cleanup' ) );
		add_action( 'init', static function () {
			if ( ! wp_next_scheduled( 'mp_cleanup_upload_drafts' ) ) { wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'mp_cleanup_upload_drafts' ); }
		} );
	}
	public static function key( string $session, int $edition ): string {
		return self::PREFIX . hash_hmac( 'sha256', $session . '|' . $edition, wp_salt( 'nonce' ) );
	}
	/** Tous les appels de lecture/modification sont effectués sous SubmissionLock. */
	public static function read( string $key ): array {
		$data = get_option( $key, array() );
		if ( ! is_array( $data ) ) { return array(); }
		if ( $data && ( $data['expires'] ?? 0 ) <= time() ) {
			PrivateFiles::remove( $data['files'] ?? array() );
			delete_option( $key );
			return array();
		}
		return $data;
	}
	public static function receipt( string $key ): int {
		$ids = get_posts( array( 'post_type' => 'mp_candidature', 'post_status' => array( 'publish', 'private', 'draft', 'pending', 'future', 'trash' ), 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => '_mp_upload_key', 'meta_value' => $key ) );
		return $ids ? (int) $ids[0] : 0;
	}
	public static function state( array $data ): array {
		$files = array();
		foreach ( $data['files'] ?? array() as $slot => $file ) {
			if ( PrivateFiles::path( $file ) ) { $files[ $slot ] = $file['label'] ?? PrivateFiles::slots()[ $slot ]; }
		}
		return array( 'files' => $files, 'revision' => $data['revision'] ?? '', 'expires' => $data['expires'] ?? 0 );
	}
	public static function upload( string $key, string $slot, array $uploads ): array|\WP_Error {
		$data = self::read( $key );
		if ( self::receipt( $key ) ) { return new \WP_Error( 'submitted', 'Cette candidature est déjà enregistrée. Rechargez la page pour afficher la confirmation.' ); }
		if ( ! isset( PrivateFiles::slots()[ $slot ], $uploads[ $slot ] ) ) { return new \WP_Error( 'upload', 'Sélectionnez un fichier pour cette pièce.' ); }
		$stored = PrivateFiles::store( array( $slot => $uploads[ $slot ] ), true );
		if ( is_wp_error( $stored ) ) { return $stored; }
		if ( empty( $stored[ $slot ] ) ) { return new \WP_Error( 'upload', 'Le fichier n’a pas été reçu.' ); }
		$stored[ $slot ]['label'] = sanitize_file_name( $uploads[ $slot ]['name'] );
		$old = $data['files'][ $slot ] ?? null;
		$data['files'][ $slot ] = $stored[ $slot ];
		$data['expires'] = $data['expires'] ?? time() + DAY_IN_SECONDS;
		$data['revision'] = bin2hex( random_bytes( 16 ) );
		if ( ! update_option( $key, $data, false ) ) { PrivateFiles::remove( $stored ); return new \WP_Error( 'storage', 'La pièce n’a pas pu être conservée. Réessayez.' ); }
		if ( $old ) { PrivateFiles::remove( array( $old ) ); }
		return self::state( $data );
	}
	/** Copier avant validation : le rollback d'une candidature ne détruit jamais le brouillon. */
	public static function copies( string $key, string $revision ): array|\WP_Error {
		$data = self::read( $key );
		if ( ! $revision || ! hash_equals( $data['revision'] ?? '', $revision ) ) { return new \WP_Error( 'draft', 'Les pièces ont changé ou expiré. Rechargez la page pour retrouver les pièces disponibles.' ); }
		$root = PrivateFiles::root();
		if ( is_wp_error( $root ) ) { return $root; }
		$copies = array();
		try {
			foreach ( PrivateFiles::slots() as $slot => $label ) {
				$file = $data['files'][ $slot ] ?? array();
				$source = PrivateFiles::path( $file );
				if ( ! $source ) { throw new \RuntimeException( $label . ' : pièce manquante. Ajoutez-la avant de valider.' ); }
				$name = bin2hex( random_bytes( 24 ) ) . '.' . pathinfo( $source, PATHINFO_EXTENSION );
				$copies[ $slot ] = array( 'name' => $name, 'mime' => $file['mime'], 'original_name' => $file['original_name'] ?? $file['label'] ?? '' );
				if ( ! copy( $source, $root . '/' . $name ) ) { throw new \RuntimeException( 'Copie impossible. Vos pièces reçues sont conservées ; réessayez.' ); }
				@chmod( $root . '/' . $name, 0644 );
				if ( ! empty( $file['social_error'] ) ) { $copies[ $slot ]['social_error'] = true; }
				if ( ! empty( $file['social'] ) ) {
					$social_source = PrivateFiles::path( $file['social'] );
					$social_name = bin2hex( random_bytes( 24 ) ) . '.jpg';
					$copies[ $slot ]['social'] = array_merge( $file['social'], array( 'name' => $social_name ) );
					if ( ! $social_source || ! copy( $social_source, $root . '/' . $social_name ) ) { throw new \RuntimeException( 'Copie impossible. Vos pièces reçues sont conservées ; réessayez.' ); }
					@chmod( $root . '/' . $social_name, 0644 );
				}
			}
			return $copies;
		} catch ( \Throwable $error ) { PrivateFiles::remove( $copies ); return new \WP_Error( 'upload', $error->getMessage() ); }
	}
	public static function finish( string $key ): void {
		$data = self::read( $key );
		PrivateFiles::remove( $data['files'] ?? array() );
		delete_option( $key );
	}
	public static function cleanup(): void {
		global $wpdb;
		if ( ! SubmissionLock::acquire() ) { return; }
		try {
			$keys = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::PREFIX ) . '%' ) );
			foreach ( $keys as $key ) { self::read( $key ); }
		} finally { SubmissionLock::release(); }
	}
}

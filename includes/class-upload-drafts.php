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
			MediaLibrary::discard_draft( $data['files'] ?? array() );
			delete_option( $key );
			return array();
		}
		return $data;
	}
	public static function receipt( string $key ): int {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Idempotency receipt lookup limited to one ID by the exact draft key, including trashed submissions.
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
		$stored = PrivateFiles::store( array( $slot => $uploads[ $slot ] ), true, $key );
		if ( is_wp_error( $stored ) ) { return $stored; }
		if ( empty( $stored[ $slot ] ) ) { return new \WP_Error( 'upload', 'Le fichier n’a pas été reçu.' ); }
		$stored[ $slot ]['label'] = sanitize_file_name( $uploads[ $slot ]['name'] );
		$old = $data['files'][ $slot ] ?? null;
		$data['files'][ $slot ] = $stored[ $slot ];
		$data['expires'] = $data['expires'] ?? time() + DAY_IN_SECONDS;
		$data['revision'] = bin2hex( random_bytes( 16 ) );
		if ( ! update_option( $key, $data, false ) ) { PrivateFiles::remove( $stored ); return new \WP_Error( 'storage', 'La pièce n’a pas pu être conservée. Réessayez.' ); }
		if ( $old ) { MediaLibrary::discard_draft( array( $old ) ); }
		return self::state( $data );
	}
	/** Reuse the exact provisional attachments. The caller must not delete them on submission failure. */
	public static function files( string $key, string $revision ): array|\WP_Error {
		$data = self::read( $key );
		if ( ! $revision || ! hash_equals( $data['revision'] ?? '', $revision ) ) { return new \WP_Error( 'draft', 'Les pièces ont changé ou expiré. Rechargez la page pour retrouver les pièces disponibles.' ); }
		foreach ( PrivateFiles::slots() as $slot => $label ) {
			$file = $data['files'][ $slot ] ?? array();
			if ( ! PrivateFiles::path( $file ) ) { return new \WP_Error( 'upload', $label . ' : pièce manquante. Ajoutez-la avant de valider.' ); }
			// Upgrade pre-migration drafts lazily, without copying files or changing the revision.
			if ( empty( $file['attachment_id'] ) ) {
				$file = MediaLibrary::import( $file );
				if ( is_wp_error( $file ) ) { return $file; }
				if ( ! empty( $file['social'] ) && empty( $file['social']['attachment_id'] ) ) {
					$file['social'] = MediaLibrary::import( $file['social'] );
					if ( is_wp_error( $file['social'] ) ) { return $file['social']; }
					update_post_meta( $file['social']['attachment_id'], '_mp_temporary_until', $data['expires'] );
					update_post_meta( $file['social']['attachment_id'], '_mp_draft_owner', $key );
				}
				update_post_meta( $file['attachment_id'], '_mp_temporary_until', $data['expires'] );
				update_post_meta( $file['attachment_id'], '_mp_draft_owner', $key );
				$data['files'][ $slot ] = $file;
				if ( ! update_option( $key, $data, false ) ) { return new \WP_Error( 'draft', 'Brouillon non enregistré ; réessayez.' ); }
			}
			if ( get_post_meta( $file['attachment_id'], '_mp_draft_owner', true ) !== $key ) { return new \WP_Error( 'draft', 'Cette pièce n’appartient pas au formulaire.' ); }
		}
		return $data['files'];
	}
	public static function finish( string $key ): void { delete_option( $key ); }
	public static function cleanup(): void {
		global $wpdb;
		if ( ! SubmissionLock::acquire() ) { return; }
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Hourly cleanup needs the current prefixed option keys under lock; values are read through the options API.
			$keys = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::PREFIX ) . '%' ) );
			foreach ( $keys as $key ) { self::read( $key ); }
			MediaLibrary::cleanup();
		} finally { SubmissionLock::release(); }
	}
}

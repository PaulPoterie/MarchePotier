<?php
namespace MarchePotier;
defined( 'ABSPATH' ) || exit;

/** WordPress attachments. Only explicitly temporary, unreferenced media may be collected. */
final class MediaLibrary {
	public static function hooks(): void {
		add_filter( 'manage_media_columns', static function ( $columns ) { $columns['mp_media_state'] = 'Marché Potier'; return $columns; } );
		add_action( 'manage_media_custom_column', static function ( $column, $id ) {
			if ( 'mp_media_state' !== $column ) { return; }
			if ( get_post_meta( $id, '_mp_temporary_until', true ) ) { echo 'Envoi provisoire'; }
			elseif ( 'mp_candidature' === get_post_type( wp_get_post_parent_id( $id ) ) ) { echo 'Candidature enregistrée'; }
		}, 10, 2 );
	}
	public static function load(): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	public static function register( string $path, string $mime, string $title, string $owner = '' ): int|\WP_Error {
		self::load(); $path = wp_normalize_path( $path );
		$id = wp_insert_attachment( wp_slash( array(
			'post_mime_type' => $mime, 'post_title' => sanitize_text_field( $title ), 'post_status' => 'inherit',
			'meta_input' => array( '_mp_temporary_until' => time() + DAY_IN_SECONDS, '_mp_draft_owner' => $owner ),
		) ), $path, 0, true );
		if ( is_wp_error( $id ) ) { return $id; }
		// The marker is saved with the attachment, before potentially expensive image processing.
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
		return $id;
	}

	public static function store( array $uploads, bool $partial = false, string $owner = '' ): array|\WP_Error {
		self::load(); $stored = array();
		try {
			foreach ( PrivateFiles::slots() as $slot => $label ) {
				$file = $uploads[ $slot ] ?? null;
				if ( $partial && ( null === $file || ( is_array( $file ) && UPLOAD_ERR_NO_FILE === ( $file['error'] ?? null ) ) ) ) { continue; }
				if ( ! is_array( $file ) || UPLOAD_ERR_OK !== ( $file['error'] ?? null ) || ! is_string( $file['tmp_name'] ?? null ) || ! is_string( $file['name'] ?? null ) || ! is_uploaded_file( $file['tmp_name'] ) ) { throw new \RuntimeException( $label . ' : fichier manquant ou transfert interrompu.' ); }
				$size = filesize( $file['tmp_name'] );
				if ( ! $size || $size > PrivateFiles::max_size() ) { throw new \RuntimeException( $label . ' : taille maximale ' . PrivateFiles::size_label() . '.' ); }
				$mimes = array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' );
				if ( in_array( $slot, array( 'status', 'insurance' ), true ) ) { $mimes['pdf'] = 'application/pdf'; }
				$checked = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $mimes );
				$mime = ( new \finfo( FILEINFO_MIME_TYPE ) )->file( $file['tmp_name'] );
				if ( ! $checked['ext'] || ! $checked['type'] || $mime !== $checked['type'] || ! in_array( $mime, $mimes, true ) ) { throw new \RuntimeException( $label . ' : format non autorisé.' ); }
				if ( 'application/pdf' === $mime ) {
					if ( '%PDF-' !== file_get_contents( $file['tmp_name'], false, null, 0, 5 ) ) { throw new \RuntimeException( $label . ' : PDF invalide.' ); }
				} else {
					$info = @getimagesize( $file['tmp_name'] ); $memory = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
					if ( ! $info || $info[0] * $info[1] > 60000000 || max( $info[0], $info[1] ) > 12000 || ( $memory > 0 && memory_get_usage( true ) + $info[0] * $info[1] * 8 + 33554432 > $memory ) ) { throw new \RuntimeException( $label . ' : image trop grande pour le traitement.' ); }
				}
				// The caller validates the signed public form or the administrator nonce before this method.
				$result = wp_handle_upload( $file, array( 'test_form' => false, 'mimes' => $mimes ) );
				if ( isset( $result['error'] ) ) { throw new \RuntimeException( $label . ' : ' . $result['error'] ); }
				$id = self::register( $result['file'], $result['type'], $label . ' — ' . $file['name'], $owner );
				if ( is_wp_error( $id ) ) { wp_delete_file( $result['file'] ); throw new \RuntimeException( $id->get_error_message() ); }
				$stored[ $slot ] = array( 'attachment_id' => $id, 'mime' => $result['type'], 'original_name' => sanitize_file_name( $file['name'] ) );
			}
			return $stored;
		} catch ( \Throwable $error ) { self::remove_temporary( $stored ); return new \WP_Error( 'upload', $error->getMessage() ); }
	}

	/** References are checked even when finalization was interrupted before clearing the temporary marker. */
	public static function references(): array {
		$references = array();
		$ids = get_posts( array( 'post_type' => 'mp_candidature', 'post_status' => array_values( get_post_stati() ), 'posts_per_page' => -1, 'fields' => 'ids' ) );
		update_meta_cache( 'post', $ids );
		foreach ( $ids as $id ) {
			foreach ( Records::data( $id )['files'] ?? array() as $file ) {
				foreach ( array( $file, $file['social'] ?? array() ) as $item ) {
					if ( ! empty( $item['attachment_id'] ) ) { $references[ (int) $item['attachment_id'] ] = (int) $id; }
					if ( ! empty( $item['name'] ) ) { $references[ 'legacy:' . $item['name'] ] = (int) $id; }
				}
			}
		}
		return $references;
	}

	/** Legacy draft files were never library media. Only draft lifecycle code calls this method. */
	public static function discard_draft( array $files ): void {
		$references = self::references();
		self::remove_temporary( $files, $references );
		foreach ( $files as $file ) {
			foreach ( array( $file, $file['social'] ?? array() ) as $item ) {
				if ( ! empty( $item['attachment_id'] ) || empty( $item['name'] ) || isset( $references[ 'legacy:' . $item['name'] ] ) ) { continue; }
				$path = PrivateFiles::path( $item );
				if ( $path ) { wp_delete_file( $path ); if ( file_exists( $path ) ) { update_option( '_mp_media_cleanup_error', 'Une ancienne pièce provisoire n’a pas pu être supprimée.', false ); } }
			}
		}
	}

	public static function promote( int $attachment, int $application ): bool {
		if ( 'attachment' !== get_post_type( $attachment ) ) { return false; }
		$result = wp_update_post( array( 'ID' => $attachment, 'post_parent' => $application ), true );
		if ( is_wp_error( $result ) || ! $result ) { return false; }
		delete_post_meta( $attachment, '_mp_temporary_until' );
		delete_post_meta( $attachment, '_mp_draft_owner' );
		return true;
	}

	/** Called after the application metadata has been durably saved, under the submission lock. */
	public static function finalize( int $application ): void {
		$data = Records::data( $application ); $changed = false;
		foreach ( $data['files'] ?? array() as $slot => $file ) {
			if ( ! empty( $file['attachment_id'] ) ) { self::promote( (int) $file['attachment_id'], $application ); }
			if ( ! empty( $file['social']['attachment_id'] ) ) { self::promote( (int) $file['social']['attachment_id'], $application ); }
			if ( ! in_array( $slot, array( 'product1', 'product2', 'product3' ), true ) || ! empty( $file['social'] ) ) { continue; }
			$path = PrivateFiles::path( $file ); $source = null;
			try {
				if ( ! $path ) { throw new \RuntimeException( 'Photo indisponible.' ); }
				$info = @getimagesize( $path ); $memory = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
				if ( ! $info || ( $memory > 0 && memory_get_usage( true ) + $info[0] * $info[1] * 8 + 33554432 > $memory ) ) { throw new \RuntimeException( 'Mémoire insuffisante pour la copie sociale.' ); }
				$source = @imagecreatefromstring( file_get_contents( $path ) );
				if ( ! $source ) { throw new \RuntimeException( 'Photo illisible.' ); }
				$social = SocialImages::create( $source, $path );
				$data['files'][ $slot ]['social'] = $social;
				unset( $data['files'][ $slot ]['social_error'] );
				// Persist the reference before clearing the temporary marker.
				if ( ! update_post_meta( $application, Records::META, wp_slash( $data ) ) ) { self::remove_temporary( array( $social ) ); unset( $data['files'][ $slot ]['social'] ); throw new \RuntimeException( 'Copie non enregistrée.' ); }
				self::promote( (int) $social['attachment_id'], $application );
			} catch ( \Throwable $error ) { $data['files'][ $slot ]['social_error'] = true; $changed = true; }
			finally { if ( $source ) { imagedestroy( $source ); } }
		}
		if ( $changed ) { update_post_meta( $application, Records::META, wp_slash( $data ) ); }
	}

	/** Never delete permanent media, even after an application is deleted or its file replaced. */
	public static function remove_temporary( array $files, ?array $references = null ): void {
		$references = $references ?? self::references();
		foreach ( $files as $file ) {
			if ( ! is_array( $file ) ) { continue; }
			if ( ! empty( $file['social'] ) ) { self::remove_temporary( array( $file['social'] ), $references ); }
			$id = (int) ( $file['attachment_id'] ?? 0 );
			if ( ! $id || ! get_post_meta( $id, '_mp_temporary_until', true ) || isset( $references[ $id ] ) || wp_get_post_parent_id( $id ) ) { continue; }
			$path = get_attached_file( $id );
			wp_delete_attachment( $id, true );
			if ( get_post( $id ) || ( $path && file_exists( $path ) ) ) { update_option( '_mp_media_cleanup_error', 'Un média provisoire n’a pas pu être supprimé.', false ); }
		}
	}

	/** Called while UploadDrafts holds the same lock as submission and replacement. */
	public static function cleanup(): void {
		$references = self::references();
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded scheduled cleanup of explicitly marked provisional attachments, never all unattached media.
		$ids = get_posts( array( 'post_type' => 'attachment', 'post_status' => array_values( get_post_stati() ), 'posts_per_page' => 100, 'fields' => 'ids', 'meta_query' => array( array( 'key' => '_mp_temporary_until', 'value' => time(), 'compare' => '<=', 'type' => 'NUMERIC' ) ) ) );
		foreach ( $ids as $id ) {
			if ( isset( $references[ $id ] ) ) { self::promote( $id, $references[ $id ] ); }
			else { self::remove_temporary( array( array( 'attachment_id' => $id ) ), $references ); }
		}
	}

	/** Existing uploads stay at their original URL; importing is idempotent across interrupted batches. */
	public static function import( array $file ): array|\WP_Error {
		if ( ! empty( $file['attachment_id'] ) ) { return 'attachment' === get_post_type( $file['attachment_id'] ) ? $file : new \WP_Error( 'media', 'Média supprimé de la médiathèque ; référence conservée.' ); }
		$path = PrivateFiles::path( $file );
		if ( ! $path ) { return new \WP_Error( 'media', 'Un ancien fichier est introuvable ; sa référence est conservée.' ); }
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) { return new \WP_Error( 'media', $uploads['error'] ); }
		$base = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );
		if ( ! str_starts_with( wp_normalize_path( $path ), $base ) ) {
			$target = $base . 'marche-potier/' . wp_basename( $path );
			if ( ! wp_mkdir_p( dirname( $target ) ) ) { return new \WP_Error( 'media', 'Dossier de migration indisponible.' ); }
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			$filesystem = new \WP_Filesystem_Direct( null );
			if ( ( ! file_exists( $target ) && ! $filesystem->copy( $path, $target, false ) ) || hash_file( 'sha256', $path ) !== hash_file( 'sha256', $target ) ) { return new \WP_Error( 'media', 'Copie non vérifiée ; fichier original conservé.' ); }
			// Keep the verified legacy original until a separate, explicit maintenance operation.
			$path = $target;
		}
		$path = wp_normalize_path( $path );
		$relative = substr( $path, strlen( $base ) );
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Migration-only deduplication by exact source path, including pre-existing media attachments.
		$ids = get_posts( array( 'post_type' => 'attachment', 'post_status' => array_values( get_post_stati() ), 'posts_per_page' => 1, 'fields' => 'ids', 'meta_query' => array( 'relation' => 'OR', array( 'key' => '_wp_attached_file', 'value' => $relative ), array( 'key' => '_mp_import_source', 'value' => $relative ) ) ) );
		if ( $ids ) { $id = (int) $ids[0]; }
		else {
			self::load();
			$id = wp_insert_attachment( wp_slash( array( 'post_mime_type' => $file['mime'], 'post_title' => $file['original_name'] ?? $file['label'] ?? wp_basename( $path ), 'post_status' => 'inherit', 'meta_input' => array( '_mp_import_source' => $relative ) ) ), $path, 0, true );
			if ( is_wp_error( $id ) ) { return $id; }
			wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
		}
		$file['attachment_id'] = $id;
		return $file;
	}

	public static function migrate(): array|\WP_Error {
		if ( ! SubmissionLock::acquire() ) { return new \WP_Error( 'media', 'Migration occupée ; réessayez.' ); }
		try {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Resumable migration processes only a small batch of unconverted applications.
			$ids = get_posts( array( 'post_type' => 'mp_candidature', 'post_status' => array_values( get_post_stati() ), 'posts_per_page' => 10, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'meta_query' => array( array( 'key' => '_mp_media_migrated', 'compare' => 'NOT EXISTS' ) ) ) );
			foreach ( $ids as $id ) {
				$data = Records::data( $id );
				foreach ( $data['files'] ?? array() as $slot => $file ) {
					$converted = self::import( $file );
					if ( is_wp_error( $converted ) ) { return $converted; }
					if ( ! empty( $file['social'] ) ) {
						$converted['social'] = self::import( $file['social'] );
						if ( is_wp_error( $converted['social'] ) ) { return $converted['social']; }
					}
					$data['files'][ $slot ] = $converted;
				}
				if ( Records::data( $id ) !== $data && ! update_post_meta( $id, Records::META, wp_slash( $data ) ) ) { return new \WP_Error( 'media', 'Références non enregistrées ; migration à reprendre.' ); }
				foreach ( $data['files'] ?? array() as $file ) {
					self::promote( (int) $file['attachment_id'], $id );
					if ( ! empty( $file['social']['attachment_id'] ) ) { self::promote( (int) $file['social']['attachment_id'], $id ); }
				}
				update_post_meta( $id, '_mp_media_migrated', 1 );
			}
			return array( 'moved' => count( $ids ), 'remaining' => count( $ids ) === 10 ? 1 : 0 );
		} finally { SubmissionLock::release(); }
	}
}

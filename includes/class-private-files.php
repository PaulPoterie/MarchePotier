<?php
namespace MarchePotier;
defined( 'ABSPATH' ) || exit;

final class PrivateFiles {
	public const MAX_SIZE = 20971520;
	/** Plafond plugin, limites PHP et éventuelle restriction WordPress. */
	public static function max_size(): int {
		$upload = wp_convert_hr_to_bytes( ini_get( 'upload_max_filesize' ) );
		$post = wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) );
		// PHP accepte post_max_size=0 (sans plafond), contrairement au min() de WordPress.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Existing WordPress core filter, not a plugin-defined hook.
		$wp_limit = $post > 0 ? wp_max_upload_size() : apply_filters( 'upload_size_limit', $upload, $upload, $post );
		$limit = min( self::MAX_SIZE, max( 0, (int) $wp_limit ), max( 0, $upload ) );
		// Le corps multipart contient aussi les champs, en-têtes et délimiteurs.
		if ( $post > 0 ) { $limit = min( $limit, max( 0, $post - 65536 ) ); }
		return $limit;
	}
	public static function size_label(): string {
		$bytes = self::max_size();
		// Arrondir vers le bas pour ne jamais annoncer plus que la limite réelle.
		return $bytes >= 1048576 ? number_format_i18n( floor( $bytes / 1048576 * 100 ) / 100, $bytes % 1048576 === 0 ? 0 : 2 ) . ' Mo' : number_format_i18n( floor( $bytes / 1024 ) ) . ' Ko';
	}
	public static function slots(): array {
		return array( 'product1' => 'Photo de produit 1', 'product2' => 'Photo de produit 2', 'product3' => 'Photo de produit 3', 'stand' => 'Photo du stand', 'status' => 'Justificatif de statut', 'insurance' => 'Assurance RC' );
	}
	public static function hooks(): void {
		MediaLibrary::hooks();
		add_action( 'admin_init', static function () {
			if ( ! current_user_can( 'manage_options' ) || get_option( '_mp_media_library_migrated' ) ) { return; }
			$result = self::migrate();
			if ( ! is_wp_error( $result ) && ! $result['remaining'] ) { update_option( '_mp_media_library_migrated', 1, false ); }
			else { add_action( 'admin_notices', static function () use ( $result ) { echo '<div class="notice notice-warning"><p>' . esc_html( is_wp_error( $result ) ? $result->get_error_message() : 'Import des fichiers dans la médiathèque en cours ; elle continuera à la prochaine page d’administration.' ) . '</p></div>'; } ); }
		} );
		add_action( 'post_edit_form_tag', static function () {
			if ( 'mp_candidature' === get_post_type() ) { echo ' enctype="multipart/form-data"'; }
		} );
		add_action( 'admin_post_mp_private_file', array( self::class, 'download' ) );
		add_action( 'admin_post_nopriv_mp_private_file', array( self::class, 'download' ) );
	}
	/** Suit le répertoire uploads configuré par WordPress, y compris en multisite. */
	public static function root(): string|\WP_Error {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) { return new \WP_Error( 'storage', 'Le dossier uploads de WordPress est indisponible.' ); }
		$path = trailingslashit( $uploads['basedir'] ) . 'marche-potier';
		if ( ! wp_mkdir_p( $path ) || ! wp_is_writable( $path ) ) { return new \WP_Error( 'storage', 'Le dossier uploads/marche-potier n’est pas accessible en écriture.' ); }
		return wp_normalize_path( realpath( $path ) );
	}
	/** Ancien emplacement : utilisé uniquement pour la migration et son repli de lecture. */
	public static function legacy_root(): string|false {
		$path = defined( 'MP_PRIVATE_DIR' ) ? MP_PRIVATE_DIR : dirname( untrailingslashit( ABSPATH ) ) . '/marche-potier-private';
		if ( ! is_string( $path ) || ! path_is_absolute( $path ) ) { return false; }
		$resolved = realpath( $path );
		return $resolved ? wp_normalize_path( $resolved ) : false;
	}
	/** Compatibility entry points; all new files are WordPress attachments. */
	public static function migrate(): array|\WP_Error { return MediaLibrary::migrate(); }
	public static function store( array $uploads, bool $partial = false, string $owner = '' ): array|\WP_Error { return MediaLibrary::store( $uploads, $partial, $owner ); }
	public static function path( array $file ): string|false {
		if ( ! empty( $file['attachment_id'] ) ) { $path = get_attached_file( (int) $file['attachment_id'] ); return $path && is_file( $path ) ? $path : false; }
		$name = $file['name'] ?? '';
		if ( ! is_string( $name ) || ! preg_match( '/^[a-f0-9]{48}\.(jpg|jpeg|png|webp|pdf)$/D', $name ) ) { return false; }
		$root = self::root();
		if ( is_wp_error( $root ) ) { return false; }
		foreach ( array_unique( array_filter( array( $root, self::legacy_root() ) ) ) as $directory ) {
			$path = realpath( $directory . '/' . $name );
			if ( $path && wp_normalize_path( dirname( $path ) ) === $directory && ! is_link( $directory . '/' . $name ) ) { return $path; }
		}
		return false;
	}
	public static function remove( array $files ): void { MediaLibrary::remove_temporary( $files ); }
	public static function file_url( array $file ): string {
		if ( ! empty( $file['attachment_id'] ) ) { return wp_get_attachment_url( (int) $file['attachment_id'] ) ?: ''; }
		$path = self::path( $file ); $uploads = wp_upload_dir();
		$base = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );
		return $path && str_starts_with( wp_normalize_path( $path ), $base ) ? trailingslashit( $uploads['baseurl'] ) . substr( wp_normalize_path( $path ), strlen( $base ) ) : '';
	}
	public static function url( int $id, string $slot ): string { return self::file_url( Records::data( $id )['files'][ $slot ] ?? array() ); }
	public static function render( int $id, bool $documents_only = false ): void {
		if ( ! Jury::can_view_application( $id ) ) { return; }
		echo $documents_only ? '<h3>Justificatifs</h3>' : '<h3>Photos et justificatifs</h3>';
		$files = Records::data( $id )['files'] ?? array();
		if ( ! $files ) { echo '<p>Aucun fichier joint à ce dossier.</p>'; return; }
		echo '<div class="mp-files' . ( $documents_only ? ' mp-review-documents' : '' ) . '">';
		foreach ( self::slots() as $slot => $label ) {
			if ( ! isset( $files[ $slot ] ) ) { continue; }
			if ( $documents_only && ! in_array( $slot, array( 'status', 'insurance' ), true ) ) { continue; }
			$url = self::url( $id, $slot );
			$pdf = 'application/pdf' === ( $files[ $slot ]['mime'] ?? '' );
			echo '<p><a' . ( $documents_only ? ' data-mp-viewer="' . ( $pdf ? 'pdf' : 'image' ) . '" data-label="' . esc_attr( $label ) . '" data-preview="' . esc_url( add_query_arg( 'mp_inline', '1', $url ) ) . '"' : '' ) . ' href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">';
			if ( ! $pdf ) { echo '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $label ) . '" style="max-width:220px;max-height:180px;object-fit:contain" loading="lazy"><br>'; }
			elseif ( $documents_only ) { echo '<span class="mp-document-cover" aria-hidden="true"><svg viewBox="0 0 48 56" width="48" height="56" fill="none"><path d="M9 2h20l10 10v42H9zM29 2v12h10M16 24h16M16 31h16" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg><strong>PDF</strong></span>'; }
			echo esc_html( $label ) . '</a></p>';
		}
		echo '</div>';
	}

	public static function edit( int $id ): void {
		if ( ! current_user_can( 'mp_manage_applications' ) ) { return; }
		self::render( $id );
		echo '<p>Ajouter ou remplacer les fichiers ci-dessous, puis cliquer sur « Mettre à jour » (ou « Publier »). Un champ laissé vide conserve le fichier actuel. Les fichiers sont enregistrés dans la médiathèque WordPress. Un remplacement conserve l’ancien média. Maximum ' . esc_html( self::size_label() ) . ' par fichier.</p>';
		foreach ( self::slots() as $slot => $label ) {
			$pdf = in_array( $slot, array( 'status', 'insurance' ), true );
			echo '<p><label for="mp-upload-' . esc_attr( $slot ) . '"><strong>' . esc_html( $label ) . '</strong> — ' . ( $pdf ? 'PDF, JPEG, PNG ou WebP' : 'JPEG, PNG ou WebP' ) . '</label><br><input type="file" id="mp-upload-' . esc_attr( $slot ) . '" name="mp_admin_' . esc_attr( $slot ) . '" accept="' . ( $pdf ? '.pdf,.jpg,.jpeg,.png,.webp' : '.jpg,.jpeg,.png,.webp' ) . '"></p>';
		}
	}
	/** Preserve old links without requiring a session or an expiring nonce. */
	public static function download(): void {
		$id = (int) ( Request::query( 'application' ) ?? 0 ); $slot = Request::query( 'slot' ) ?? '';
		if ( ! isset( self::slots()[ $slot ] ) || 'mp_candidature' !== get_post_type( $id ) ) { wp_die( 'Fichier introuvable.', '', array( 'response' => 404 ) ); }
		$file = Records::data( $id )['files'][ $slot ] ?? array();
		if ( '1' === Request::query( 'mp_social' ) ) { $file = $file['social'] ?? array(); }
		$url = self::file_url( $file );
		if ( ! $url ) { wp_die( 'Fichier introuvable.', '', array( 'response' => 404 ) ); }
		nocache_headers();
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- URL comes exclusively from the WordPress attachment API, which may use a media CDN.
		wp_redirect( $url, 302 ); exit;
	}
}

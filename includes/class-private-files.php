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
		add_action( 'admin_init', static function () {
			if ( ! current_user_can( 'manage_options' ) || get_option( '_mp_uploads_storage_migrated' ) ) { return; }
			$result = self::migrate();
			if ( ! is_wp_error( $result ) && ! $result['remaining'] ) { update_option( '_mp_uploads_storage_migrated', 1, false ); }
			else { add_action( 'admin_notices', static function () use ( $result ) { echo '<div class="notice notice-warning"><p>' . esc_html( is_wp_error( $result ) ? $result->get_error_message() : 'Migration des fichiers vers uploads en cours ; elle continuera à la prochaine page d’administration.' ) . '</p></div>'; } ); }
		} );
		add_action( 'post_edit_form_tag', static function () {
			if ( 'mp_candidature' === get_post_type() ) { echo ' enctype="multipart/form-data"'; }
		} );
		add_action( 'admin_post_mp_private_file', array( self::class, 'download' ) );
		add_action( 'admin_post_nopriv_mp_private_file', static function () { wp_die( 'Connexion organisateur requise.', '', array( 'response' => 403 ) ); } );
		add_action( 'before_delete_post', static function ( $id, $post ) { if ( 'mp_candidature' === $post->post_type ) { self::remove( Records::data( $id )['files'] ?? array() ); } }, 10, 2 );
	}
	/** Suit le répertoire uploads configuré par WordPress, y compris en multisite. */
	public static function root(): string|\WP_Error {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) { return new \WP_Error( 'storage', 'Le dossier uploads de WordPress est indisponible.' ); }
		$path = trailingslashit( $uploads['basedir'] ) . 'marche-potier';
		if ( ! wp_mkdir_p( $path ) || ! is_writable( $path ) ) { return new \WP_Error( 'storage', 'Le dossier uploads/marche-potier n’est pas accessible en écriture.' ); }
		return wp_normalize_path( realpath( $path ) );
	}
	/** Ancien emplacement : utilisé uniquement pour la migration et son repli de lecture. */
	public static function legacy_root(): string|false {
		$path = defined( 'MP_PRIVATE_DIR' ) ? MP_PRIVATE_DIR : dirname( untrailingslashit( ABSPATH ) ) . '/marche-potier-private';
		if ( ! is_string( $path ) || ! path_is_absolute( $path ) ) { return false; }
		$resolved = realpath( $path );
		return $resolved ? wp_normalize_path( $resolved ) : false;
	}
	/** Migration reprenable : vérifier les octets avant de supprimer chaque ancienne copie. */
	public static function migrate(): array|\WP_Error {
		$root = self::root(); $legacy = self::legacy_root();
		if ( is_wp_error( $root ) ) { return $root; }
		if ( ! $legacy || $root === $legacy ) { return array( 'moved' => 0, 'remaining' => 0 ); }
		if ( ! SubmissionLock::acquire() ) { return new \WP_Error( 'storage', 'Migration occupée ; réessayez.' ); }
		$moved = 0; $remaining = 0;
		try {
			$names = @scandir( $legacy );
			if ( false === $names ) { throw new \RuntimeException( 'L’ancien dossier de fichiers est inaccessible.' ); }
			foreach ( $names as $name ) {
				if ( ! preg_match( '/^[a-f0-9]{48}\.(jpg|jpeg|png|webp|pdf)$/D', $name ) ) { continue; }
				$source = $legacy . '/' . $name; $target = $root . '/' . $name;
				if ( is_link( $source ) || ! is_file( $source ) || wp_normalize_path( dirname( realpath( $source ) ) ) !== $legacy ) { continue; }
				if ( $moved >= 50 ) { ++$remaining; continue; }
				if ( is_link( $target ) ) { throw new \RuntimeException( 'Destination de migration invalide.' ); }
				if ( ! file_exists( $target ) ) {
					$temp = $target . '.migration-' . bin2hex( random_bytes( 8 ) );
					try {
						if ( ! copy( $source, $temp ) || hash_file( 'sha256', $source ) !== hash_file( 'sha256', $temp ) || ! rename( $temp, $target ) ) { throw new \RuntimeException( 'Copie de migration impossible ; les fichiers originaux sont conservés.' ); }
					} finally { if ( is_file( $temp ) ) { unlink( $temp ); } }
				}
				if ( hash_file( 'sha256', $source ) !== hash_file( 'sha256', $target ) ) { throw new \RuntimeException( 'Conflit de fichiers : original conservé.' ); }
				@chmod( $target, 0644 );
				if ( ! unlink( $source ) ) { throw new \RuntimeException( 'Copie vérifiée dans uploads, mais suppression de l’ancienne copie impossible.' ); }
				++$moved;
			}
			return array( 'moved' => $moved, 'remaining' => $remaining );
		} catch ( \Throwable $error ) { return new \WP_Error( 'storage', $error->getMessage() ); }
		finally { SubmissionLock::release(); }
	}
	/** Appelé uniquement pour de vrais uploads HTTP ; aucun chemin fourni par le candidat n'est repris. */
	public static function store( array $uploads, bool $partial = false ): array|\WP_Error {
		$root = self::root();
		if ( is_wp_error( $root ) ) { return $root; }
		$stored = array();
		try {
			foreach ( self::slots() as $slot => $label ) {
				$file = $uploads[ $slot ] ?? null;
				if ( $partial && ( null === $file || ( is_array( $file ) && UPLOAD_ERR_NO_FILE === ( $file['error'] ?? null ) ) ) ) { continue; }
				if ( is_array( $file ) && in_array( $file['error'] ?? null, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) { throw new \RuntimeException( $label . ' : taille maximale ' . self::size_label() . '.' ); }
				if ( ! is_array( $file ) || ! isset( $file['error'], $file['tmp_name'], $file['name'] ) || UPLOAD_ERR_OK !== $file['error'] || ! is_string( $file['tmp_name'] ) || ! is_string( $file['name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) { throw new \RuntimeException( $label . ' : fichier manquant ou envoi interrompu.' ); }
				$size = filesize( $file['tmp_name'] );
				if ( ! $size || $size > self::max_size() ) { throw new \RuntimeException( $label . ' : fichier non vide, taille maximale ' . self::size_label() . '.' ); }
				$pdf = in_array( $slot, array( 'status', 'insurance' ), true );
				$mimes = array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' );
				if ( $pdf ) { $mimes['pdf'] = 'application/pdf'; }
				$checked = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $mimes );
				$mime = ( new \finfo( FILEINFO_MIME_TYPE ) )->file( $file['tmp_name'] );
				if ( ! $checked['ext'] || ! $checked['type'] || $checked['type'] !== $mime || ! in_array( $mime, $mimes, true ) ) { throw new \RuntimeException( $label . ' : format non autorisé.' ); }
				$pdf = 'application/pdf' === $mime;
				$name = bin2hex( random_bytes( 24 ) ) . '.' . ( $pdf ? 'pdf' : $checked['ext'] );
				$target = $root . '/' . $name;
				// Enregistrer le nom avant l'écriture pour nettoyer même un fichier partiel.
				$stored[ $slot ] = array( 'name' => $name, 'mime' => $mime, 'original_name' => sanitize_text_field( wp_basename( str_replace( '\\', '/', $file['name'] ) ) ) );
				if ( $pdf ) {
					if ( '%PDF-' !== file_get_contents( $file['tmp_name'], false, null, 0, 5 ) || ! move_uploaded_file( $file['tmp_name'], $target ) ) { throw new \RuntimeException( $label . ' : PDF invalide ou stockage impossible.' ); }
				} else {
					$info = @getimagesize( $file['tmp_name'] );
					$memory = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
					if ( ! $info || $info[0] * $info[1] > 60000000 || max( $info[0], $info[1] ) > 12000 || ( $memory > 0 && memory_get_usage( true ) + $info[0] * $info[1] * 8 + 33554432 > $memory ) || ! function_exists( 'imagecreatefromstring' ) ) { throw new \RuntimeException( $label . ' : image trop grande pour le traitement. Réduisez sa résolution et réessayez.' ); }
					$image = @imagecreatefromstring( file_get_contents( $file['tmp_name'] ) );
					if ( ! $image ) { throw new \RuntimeException( $label . ' : image illisible.' ); }
					try {
						imagesavealpha( $image, true );
						$ok = match ( $mime ) { 'image/jpeg' => imagejpeg( $image, $target, 90 ), 'image/png' => imagepng( $image, $target ), 'image/webp' => function_exists( 'imagewebp' ) && imagewebp( $image, $target, 90 ) };
						if ( $ok && in_array( $slot, array( 'product1', 'product2', 'product3' ), true ) ) {
							// Une copie facultative ne doit pas empêcher le dépôt du dossier.
							try { $stored[ $slot ]['social'] = SocialImages::create( $image, $file['tmp_name'] ); }
							catch ( \Throwable $error ) { $stored[ $slot ]['social_error'] = true; }
						}
					} finally { imagedestroy( $image ); }
					if ( ! $ok ) { throw new \RuntimeException( $label . ' : traitement impossible.' ); }
				}
				@chmod( $target, 0644 );
			}
			return $stored;
		} catch ( \Throwable $error ) {
			self::remove( $stored );
			return new \WP_Error( 'upload', $error->getMessage() );
		}
	}
	public static function path( array $file ): string|false {
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
	public static function remove( array $files ): void { foreach ( $files as $file ) { if ( is_array( $file ) && ! empty( $file['social'] ) ) { self::remove( array( $file['social'] ) ); } $path = is_array( $file ) ? self::path( $file ) : false; if ( $path ) { wp_delete_file( $path ); } } }
	public static function url( int $id, string $slot ): string {
		$file = Records::data( $id )['files'][ $slot ] ?? array();
		if ( ! in_array( $slot, array( 'status', 'insurance' ), true ) && ! empty( $file['attachment_id'] ) ) { return wp_get_attachment_url( $file['attachment_id'] ) ?: ''; }
		return wp_nonce_url( add_query_arg( array( 'action' => 'mp_private_file', 'application' => $id, 'slot' => $slot ), admin_url( 'admin-post.php' ) ), 'mp_file_' . $id . '_' . $slot );
	}
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
		echo '<p>Ajouter ou remplacer les fichiers ci-dessous, puis cliquer sur « Mettre à jour » (ou « Publier »). Un champ laissé vide conserve le fichier actuel. Les fichiers sont stockés dans le dossier uploads de WordPress. Maximum ' . esc_html( self::size_label() ) . ' par fichier.</p>';
		foreach ( self::slots() as $slot => $label ) {
			$pdf = in_array( $slot, array( 'status', 'insurance' ), true );
			echo '<p><label for="mp-upload-' . esc_attr( $slot ) . '"><strong>' . esc_html( $label ) . '</strong> — ' . ( $pdf ? 'PDF, JPEG, PNG ou WebP' : 'JPEG, PNG ou WebP' ) . '</label><br><input type="file" id="mp-upload-' . esc_attr( $slot ) . '" name="mp_admin_' . esc_attr( $slot ) . '" accept="' . ( $pdf ? '.pdf,.jpg,.jpeg,.png,.webp' : '.jpg,.jpeg,.png,.webp' ) . '"></p>';
		}
	}
	public static function download(): void {
		$id = isset( $_GET['application'] ) && is_string( $_GET['application'] ) ? absint( $_GET['application'] ) : 0;
		if ( ! Jury::can_view_application( $id ) ) { wp_die( 'Accès refusé.', '', array( 'response' => 403 ) ); }
		$slot = isset( $_GET['slot'] ) && is_string( $_GET['slot'] ) ? $_GET['slot'] : '';
		if ( ! isset( self::slots()[ $slot ] ) || get_post_type( $id ) !== 'mp_candidature' || 'trash' === get_post_status( $id ) ) { wp_die( 'Fichier introuvable.', '', array( 'response' => 404 ) ); }
		check_admin_referer( 'mp_file_' . $id . '_' . $slot );
		$file = Records::data( $id )['files'][ $slot ] ?? array();
		$social = '1' === ( $_GET['mp_social'] ?? '' );
		if ( $social ) { $file = $file['social'] ?? array(); }
		$path = self::path( $file );
		if ( ! $path ) { wp_die( 'Fichier introuvable.', '', array( 'response' => 404 ) ); }
		$mime = $file['mime'] ?? '';
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'application/pdf' ), true ) ) { wp_die( 'Format inconnu.', '', array( 'response' => 404 ) ); }
		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		$inline_pdf = 'application/pdf' === $mime && '1' === ( $_GET['mp_inline'] ?? '' );
		header( $inline_pdf ? "Content-Security-Policy: default-src 'none'; frame-ancestors 'self'" : "Content-Security-Policy: default-src 'none'; sandbox" );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: ' . ( ( $social || 'application/pdf' === $mime && ! $inline_pdf ) ? 'attachment' : 'inline' ) . '; filename="' . $slot . ( $social ? '-instagram-facebook-1080x1350' : '' ) . '.' . pathinfo( $path, PATHINFO_EXTENSION ) . '"' );
		readfile( $path );
		exit;
	}
}

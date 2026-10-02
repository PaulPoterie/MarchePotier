<?php
namespace MarchePotier;
defined( 'ABSPATH' ) || exit;

/** Copie de diffusion ; ne remplace jamais la photo du dossier. */
final class SocialImages {
	public const WIDTH = 1080;
	public const HEIGHT = 1350;
	public static function render( int $application ): void {
		$files = Records::data( $application )['files'] ?? array();
		foreach ( array( 'product1', 'product2', 'product3' ) as $slot ) {
			$url = PrivateFiles::file_url( $files[ $slot ]['social'] ?? array() );
			if ( $url ) { echo '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( PrivateFiles::slots()[ $slot ] . ' — copie réseaux sociaux 1 080 × 1 350' ) . '</a></p>'; }
		}
	}
	/** Only our export gets the padding adapters; WordPress loads their parent classes first. */
	public static function editors( array $editors ): array {
		foreach ( $editors as &$editor ) {
			if ( 'WP_Image_Editor_Imagick' === $editor ) {
				require_once __DIR__ . '/class-social-image-editor-imagick.php';
				$editor = SocialImageEditorImagick::class;
			} elseif ( 'WP_Image_Editor_GD' === $editor ) {
				require_once __DIR__ . '/class-social-image-editor-gd.php';
				$editor = SocialImageEditorGD::class;
			}
		}
		return $editors;
	}
	private static function check( mixed $result ): void {
		if ( is_wp_error( $result ) ) { throw new \RuntimeException( 'Traitement de l’image impossible avec l’éditeur WordPress.' ); }
	}
	public static function create( string $original ): array {
		$memory = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		if ( $memory > 0 && memory_get_usage( true ) + 25165824 > $memory ) { throw new \RuntimeException( 'Mémoire insuffisante pour la copie sociale.' ); }
		$uploads = wp_upload_dir(); $root = $uploads['path'];
		if ( ! empty( $uploads['error'] ) ) { throw new \RuntimeException( 'Stockage indisponible.' ); }
		// Scope the native editor filter to this load, including when WordPress returns an error.
		add_filter( 'wp_image_editors', array( self::class, 'editors' ), PHP_INT_MAX );
		try { $editor = wp_get_image_editor( $original, array( 'methods' => array( 'marcpo_frame' ) ) ); }
		finally { remove_filter( 'wp_image_editors', array( self::class, 'editors' ), PHP_INT_MAX ); }
		self::check( $editor );
		self::check( $editor->maybe_exif_rotate() );
		$size = $editor->get_size();
		// Do not enlarge small photographs or crop any part of the pottery.
		if ( $size['width'] > self::WIDTH || $size['height'] > self::HEIGHT ) { self::check( $editor->resize( self::WIDTH, self::HEIGHT, false ) ); }
		self::check( $editor->marcpo_frame() );
		self::check( $editor->set_quality( 90 ) );
		$name = bin2hex( random_bytes( 24 ) ) . '.jpg';
		$path = $root . '/' . $name;
		// These downloadable copies promise JPEG, even when the site converts ordinary thumbnails.
		$jpeg = static function ( $formats, $filename ) use ( $path ) {
			if ( $filename && wp_normalize_path( $filename ) === wp_normalize_path( $path ) ) { unset( $formats['image/jpeg'] ); }
			return $formats;
		};
		add_filter( 'image_editor_output_format', $jpeg, PHP_INT_MAX, 2 );
		try {
			$saved = $editor->save( $path, 'image/jpeg' );
			self::check( $saved );
			if ( 'image/jpeg' !== $saved['mime-type'] || self::WIDTH !== $saved['width'] || self::HEIGHT !== $saved['height'] ) { throw new \RuntimeException( 'Format de copie inattendu.' ); }
			unset( $editor ); // Release decoded pixels before WordPress generates attachment metadata.
			// Metadata generation can also convert the full image; keep its JPEG protection until then.
			$id = MediaLibrary::register( $path, 'image/jpeg', 'Copie réseaux sociaux — 1080 × 1350' );
			if ( is_wp_error( $id ) ) { throw new \RuntimeException( $id->get_error_message() ); }
			return array( 'attachment_id' => $id, 'mime' => 'image/jpeg', 'width' => self::WIDTH, 'height' => self::HEIGHT );
		} catch ( \Throwable $error ) {
			if ( is_file( $path ) ) { wp_delete_file( $path ); }
			throw $error;
		} finally {
			remove_filter( 'image_editor_output_format', $jpeg, PHP_INT_MAX );
		}
	}
}

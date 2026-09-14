<?php
namespace MarchePotier;
defined( 'ABSPATH' ) || exit;

/** Copie de diffusion ; ne remplace jamais la photo du dossier. */
final class SocialImages {
	public static function create( \GdImage $source, string $original ): array {
		$memory = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		if ( $memory > 0 && memory_get_usage( true ) + 25165824 > $memory ) { throw new \RuntimeException( 'Mémoire insuffisante pour la copie sociale.' ); }
		$root = PrivateFiles::root();
		if ( is_wp_error( $root ) ) { throw new \RuntimeException( 'Stockage indisponible.' ); }
		$orientation = 1;
		if ( function_exists( 'exif_read_data' ) && IMAGETYPE_JPEG === @exif_imagetype( $original ) ) {
			$exif = @exif_read_data( $original );
			$orientation = (int) ( $exif['Orientation'] ?? 1 );
		}
		$swapped = in_array( $orientation, array( 5, 6, 7, 8 ), true );
		$width = imagesx( $source ); $height = imagesy( $source );
		$scale = min( 1, ( $swapped ? 1350 : 1080 ) / $width, ( $swapped ? 1080 : 1350 ) / $height );
		$w = max( 1, (int) round( $width * $scale ) ); $h = max( 1, (int) round( $height * $scale ) );
		$photo = null; $canvas = null;
		$name = bin2hex( random_bytes( 24 ) ) . '.jpg';
		$path = $root . '/' . $name;
		try {
			$photo = imagecreatetruecolor( $w, $h );
			imagefill( $photo, 0, 0, imagecolorallocate( $photo, 255, 255, 255 ) );
			if ( ! imagecopyresampled( $photo, $source, 0, 0, 0, 0, $w, $h, $width, $height ) ) { throw new \RuntimeException( 'Redimensionnement impossible.' ); }
			if ( in_array( $orientation, array( 2, 5, 7 ), true ) ) { imageflip( $photo, IMG_FLIP_HORIZONTAL ); }
			if ( 4 === $orientation ) { imageflip( $photo, IMG_FLIP_VERTICAL ); }
			$angle = match ( $orientation ) { 3 => 180, 5, 8 => 90, 6, 7 => -90, default => 0 };
			if ( $angle ) {
				$rotated = imagerotate( $photo, $angle, 0 );
				if ( ! $rotated ) { throw new \RuntimeException( 'Rotation impossible.' ); }
				imagedestroy( $photo ); $photo = $rotated;
			}
			$canvas = imagecreatetruecolor( 1080, 1350 );
			imagefill( $canvas, 0, 0, imagecolorallocate( $canvas, 255, 255, 255 ) );
			imagecopy( $canvas, $photo, (int) floor( ( 1080 - imagesx( $photo ) ) / 2 ), (int) floor( ( 1350 - imagesy( $photo ) ) / 2 ), 0, 0, imagesx( $photo ), imagesy( $photo ) );
			if ( ! imagejpeg( $canvas, $path, 90 ) ) { throw new \RuntimeException( 'Écriture impossible.' ); }
			@chmod( $path, 0644 );
			return array( 'name' => $name, 'mime' => 'image/jpeg', 'width' => 1080, 'height' => 1350 );
		} catch ( \Throwable $error ) {
			if ( is_file( $path ) ) { wp_delete_file( $path ); }
			throw $error;
		} finally {
			if ( $photo ) { imagedestroy( $photo ); }
			if ( $canvas ) { imagedestroy( $canvas ); }
		}
	}

	public static function render( int $id ): void {
		if ( ! current_user_can( 'mp_manage_applications' ) ) { return; }
		$files = Records::data( $id )['files'] ?? array();
		$links = array();
		foreach ( array( 'product1', 'product2', 'product3' ) as $slot ) {
			if ( ! empty( $files[ $slot ]['social'] ) ) {
				$url = wp_nonce_url( add_query_arg( array( 'action' => 'mp_private_file', 'application' => $id, 'slot' => $slot, 'mp_social' => '1' ), admin_url( 'admin-post.php' ) ), 'mp_file_' . $id . '_' . $slot );
				$links[] = '<a class="button" href="' . esc_url( $url ) . '">Télécharger — ' . esc_html( PrivateFiles::slots()[ $slot ] ) . '</a>';
			} elseif ( ! empty( $files[ $slot ]['social_error'] ) ) {
				$links[] = '<span>' . esc_html( PrivateFiles::slots()[ $slot ] ) . ' : copie sociale indisponible. Remplacez la photo pour réessayer.</span>';
			}
		}
		if ( $links ) { echo '<h3>Images pour Instagram et Facebook</h3><p>JPEG 1 080 × 1 350 px · photo entière sur fond blanc.</p><p>' . implode( ' ', $links ) . '</p>'; }
	}
}

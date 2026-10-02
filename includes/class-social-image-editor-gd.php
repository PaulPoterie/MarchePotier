<?php
namespace MarchePotier;
defined( 'ABSPATH' ) || exit;

/** Native GD editor plus the one operation absent from the common API: white padding. */
final class SocialImageEditorGD extends \WP_Image_Editor_GD {
	public function marcpo_frame(): bool|\WP_Error {
		$width = SocialImages::WIDTH; $height = SocialImages::HEIGHT;
		if ( $this->size['width'] > $width || $this->size['height'] > $height ) { return new \WP_Error( 'marcpo_image_size', 'Photo trop grande pour le cadre.' ); }
		$canvas = imagecreatetruecolor( $width, $height );
		if ( ! $canvas ) { return new \WP_Error( 'marcpo_image_canvas', 'Création du fond blanc impossible.' ); }
		imagefill( $canvas, 0, 0, imagecolorallocate( $canvas, 255, 255, 255 ) );
		imagealphablending( $canvas, true );
		if ( ! imagecopy( $canvas, $this->image, (int) floor( ( $width - $this->size['width'] ) / 2 ), (int) floor( ( $height - $this->size['height'] ) / 2 ), 0, 0, $this->size['width'], $this->size['height'] ) ) {
			return new \WP_Error( 'marcpo_image_canvas', 'Centrage de la photo impossible.' );
		}
		$this->image = $canvas;
		$this->mime_type = 'image/jpeg';
		$this->output_mime_type = null;
		$this->update_size( $width, $height );
		return true;
	}
}

<?php
namespace MarchePotier;
defined( 'ABSPATH' ) || exit;

/** Native Imagick editor with the same white frame, without loading or calling GD. */
final class SocialImageEditorImagick extends \WP_Image_Editor_Imagick {
	public function marcpo_frame(): bool|\WP_Error {
		$width = SocialImages::WIDTH; $height = SocialImages::HEIGHT;
		if ( $this->size['width'] > $width || $this->size['height'] > $height ) { return new \WP_Error( 'marcpo_image_size', 'Photo trop grande pour le cadre.' ); }
		$canvas = new \Imagick();
		try {
			$canvas->newImage( $width, $height, new \ImagickPixel( 'white' ), 'jpeg' );
			$canvas->setImageColorspace( \Imagick::COLORSPACE_SRGB );
			$canvas->compositeImage( $this->image, \Imagick::COMPOSITE_OVER, (int) floor( ( $width - $this->size['width'] ) / 2 ), (int) floor( ( $height - $this->size['height'] ) / 2 ) );
			$canvas->setImagePage( 0, 0, 0, 0 );
			$this->image->clear();
			$this->image = $canvas;
			$this->mime_type = 'image/jpeg';
			$this->output_mime_type = null;
			$this->update_size( $width, $height );
			return true;
		} catch ( \Exception $error ) {
			$canvas->clear();
			return new \WP_Error( 'marcpo_image_canvas', 'Centrage de la photo impossible.' );
		}
	}
}

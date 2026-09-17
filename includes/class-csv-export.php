<?php
namespace MarchePotier;
defined( 'ABSPATH' ) || exit;

final class CsvExport {
	public static function hooks(): void {
		add_action( 'admin_post_mp_export_csv', array( self::class, 'download' ) );
		add_action( 'admin_post_mp_export_file', array( self::class, 'file' ) );
		add_action( 'admin_post_nopriv_mp_export_file', array( self::class, 'file' ) );
	}
	public static function file_url( int $id, string $slot ): string {
		return PrivateFiles::url( $id, $slot );
	}
	/** Compatibility with links exported before migration to the media library. */
	public static function file(): void { PrivateFiles::download(); }
	public static function headers(): array {
		$row = array( 'Numéro de candidature', 'Édition', 'Sélection' );
		foreach ( array( Fields::identity(), Fields::activity(), Fields::internal() ) as $schema ) { foreach ( $schema as $field ) { $row[] = $field[0]; } }
		$row[] = 'Date de dépôt'; $row[] = 'Autorisation de présentation publique';
		foreach ( PrivateFiles::slots() as $label ) { $row[] = $label . ' — nom du fichier'; $row[] = $label . ' — lien'; }
		return $row;
	}
	public static function row( int $id ): array {
		$data = Records::data( $id );
		$row = array( (string) $id, get_the_title( $data['edition_id'] ?? 0 ), Records::decisions()[ $data['decision'] ?? 'pending' ] ?? '' );
		foreach ( array( 'identity' => Fields::identity(), 'activity' => Fields::activity(), 'internal' => Fields::internal() ) as $group => $schema ) {
			foreach ( $schema as $key => $field ) {
				$value = $data[ $group ][ $key ] ?? '';
				$row[] = is_array( $value ) ? implode( ', ', array_map( static fn( $v ) => $field[3][ $v ] ?? $v, $value ) ) : (string) ( $field[3][ $value ] ?? $value );
			}
		}
		$row[] = $data['submitted_at'] ?? ''; $row[] = empty( $data['publication_consent'] ) ? 'Non' : 'Oui';
		foreach ( PrivateFiles::slots() as $slot => $label ) {
			$file = $data['files'][ $slot ] ?? array();
			$row[] = $file['original_name'] ?? $file['label'] ?? '';
			$row[] = $file ? self::file_url( $id, $slot ) : '';
		}
		return $row;
	}
	/** Empêche Excel/Calc d'interpréter les valeurs saisies comme des formules. */
	public static function cell( mixed $value ): string {
		$value = str_replace( "\0", '', (string) $value );
		return preg_match( '/^[\s\x{FEFF}]*[=+@-]/u', $value ) || preg_match( '/^[\t\r\n]/', $value ) ? "'" . $value : $value;
	}
	public static function write( $stream, array $ids ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write the UTF-8 BOM to the caller's output stream; buffering the complete CSV would grow memory with the export size.
		fwrite( $stream, "\xEF\xBB\xBF" );
		fputcsv( $stream, self::headers(), ';', '"', '', "\r\n" );
		foreach ( $ids as $id ) { fputcsv( $stream, array_map( array( self::class, 'cell' ), self::row( $id ) ), ';', '"', '', "\r\n" ); }
	}
	public static function download(): void {
		if ( ! current_user_can( 'mp_manage_applications' ) ) { wp_die( 'Accès refusé.', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'mp_export_csv' );
		$ids = Records::navigation_ids( Records::list_context() );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="candidatures-' . gmdate( 'Y-m-d-His' ) . '.csv"' );
		$stream = fopen( 'php://output', 'wb' );
		self::write( $stream, $ids );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the php://output stream after sending CSV rows; no filesystem file is being modified.
		fclose( $stream ); exit;
	}
}

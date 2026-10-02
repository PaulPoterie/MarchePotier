<?php
namespace MarchePotier;
defined( 'ABSPATH' ) || exit;

/**
 * Verrou par table d'options pour dépôts, fichiers, affectations, notes et réservations d’emails.
 * Il n’est pas réentrant : ne pas acquérir un second verrou dans une fonction appelée sous verrou.
 * Toujours libérer dans finally. Ce verrou ne remplace ni une transaction ni un numéro de révision.
 */
final class SubmissionLock {
	private const OPTION = '_marcpo_submission_lock';
	private static string $owner = '';
	private static string $table = '';
	private static bool $shutdown_registered = false;
	private static bool $storage_error = false;
	/**
	 * L'index unique d'option_name arbitre les requêtes concurrentes, même avec un cache persistant.
	 * INSERT IGNORE est aussi utilisé par le verrou de WP_Upgrader et traduit par l'intégration SQLite.
	 * Pas d'expiration automatique : une opération lente ne doit jamais perdre son verrou au profit d'une autre.
	 */
	public static function acquire(): bool {
		global $wpdb;
		self::$storage_error = false;
		if ( '' !== self::$owner ) { return false; }
		$owner = bin2hex( random_bytes( 32 ) ) . ':' . time();
		$deadline = microtime( true ) + 3;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic insert-only mutex in a private, non-autoloaded option; never read or modify through an option cache.
			$result = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, self::OPTION, $owner, 'no' ) );
			if ( false === $result ) { self::$storage_error = true; return false; }
			if ( 1 === $result ) {
				self::$owner = $owner;
				self::$table = $wpdb->options;
				if ( ! self::$shutdown_registered ) { register_shutdown_function( array( self::class, 'release' ) ); self::$shutdown_registered = true; }
				return true;
			}
			if ( microtime( true ) >= $deadline ) { return false; }
			usleep( 50000 );
		} while ( true );
	}
	/** Distingue une écriture impossible d'un verrou déjà détenu, sans exposer de détail SQL. */
	public static function error(): \WP_Error {
		return self::$storage_error
			? new \WP_Error( 'marcpo_lock_storage', __( 'Le verrou d’enregistrement est indisponible. Contactez l’administrateur du site.', 'poterie-navarraise-market-manager' ) )
			: new \WP_Error( 'marcpo_lock_busy', __( 'Un autre enregistrement est en cours. Réessayez dans quelques instants. Si le message persiste, contactez l’administrateur du site.', 'poterie-navarraise-market-manager' ) );
	}
	public static function release(): void {
		global $wpdb;
		if ( '' === self::$owner ) { return; }
		// Table mémorisée pour switch_to_blog ; seule la requête propriétaire peut libérer cette ligne.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional owner release bypasses caches and must not delete another request's lock.
		$result = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', self::$table, self::OPTION, self::$owner ) );
		if ( false !== $result ) { self::$owner = ''; self::$table = ''; }
	}
}

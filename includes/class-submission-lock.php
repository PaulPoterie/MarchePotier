<?php
namespace MarchePotier;
defined( 'ABSPATH' ) || exit;

/** Verrou MySQL de connexion : commun aux dépôts et sauvegardes internes. */
final class SubmissionLock {
	private static bool $held = false;
	private static function name(): string { global $wpdb; return 'mp_' . hash( 'sha256', DB_NAME . $wpdb->prefix ); }
	public static function acquire(): bool {
		global $wpdb;
		if ( self::$held ) { return false; }
		self::$held = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', substr( self::name(), 0, 64 ) ) );
		return self::$held;
	}
	public static function release(): void {
		global $wpdb;
		if ( self::$held ) { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', substr( self::name(), 0, 64 ) ) ); self::$held = false; }
	}
}
